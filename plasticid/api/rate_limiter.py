import hashlib
import os
import threading
import time
import uuid
from collections import deque
from dataclasses import dataclass

try:
    import redis as _redis_lib
    _REDIS_AVAILABLE = True
except ImportError:
    _redis_lib = None
    _REDIS_AVAILABLE = False

REDIS_URL = os.getenv("REDIS_URL", "redis://redis:6379/0")
_RECONNECT_INTERVAL = 30.0
_KEY_PREFIX = "rate:"
# Upper bound on distinct in-memory buckets. Only used when Redis is
# unavailable; without a cap a key spray (many distinct keys) would grow the
# dict unbounded and exhaust memory.
_MEMORY_MAX_KEYS = max(1, int(os.getenv("RATE_LIMIT_MEMORY_MAX_KEYS", "10000")))
_RESERVE_SCRIPT = """
local key = KEYS[1]
local now = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local limit = tonumber(ARGV[3])
local member = ARGV[4]
redis.call('ZREMRANGEBYSCORE', key, '-inf', now - window)
local count = redis.call('ZCARD', key)
if count >= limit then
    return {0, count}
end
redis.call('ZADD', key, now, member)
redis.call('EXPIRE', key, window + 1)
return {1, count + 1}
"""


class RateLimiterUnavailable(RuntimeError):
    pass


@dataclass(frozen=True)
class Reservation:
    allowed: bool
    count: int
    token: str | None = None


def _env_bool(name: str, default: bool = False) -> bool:
    value = os.getenv(name)
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


class SlidingWindowRateLimiter:
    def __init__(self, use_memory_only: bool = False, require_redis: bool | None = None):
        self._lock = threading.Lock()
        self._buckets: dict[str, deque[tuple[float, str]]] = {}
        self._redis = None
        self._degraded = True
        self._last_attempt = 0.0
        self._require_redis = (
            _env_bool("RATE_LIMIT_REDIS_REQUIRED")
            if require_redis is None
            else require_redis
        )

        if use_memory_only or not _REDIS_AVAILABLE:
            return

        try:
            client = _redis_lib.Redis.from_url(
                REDIS_URL,
                decode_responses=True,
                socket_connect_timeout=1.0,
                socket_timeout=1.0,
            )
            client.ping()
            self._redis = client
            self._degraded = False
        except Exception:
            self._redis = None
            self._degraded = True

    @staticmethod
    def _window_key(key: str) -> str:
        digest = hashlib.sha256(key.encode("utf-8")).hexdigest()
        return _KEY_PREFIX + digest

    def _mark_degraded(self) -> None:
        self._degraded = True
        self._last_attempt = time.monotonic()

    def _try_reconnect(self) -> None:
        if not _REDIS_AVAILABLE:
            return
        now = time.monotonic()
        if now - self._last_attempt < _RECONNECT_INTERVAL:
            return
        self._last_attempt = now
        try:
            client = _redis_lib.Redis.from_url(
                REDIS_URL,
                decode_responses=True,
                socket_connect_timeout=1.0,
                socket_timeout=1.0,
            )
            client.ping()
            self._redis = client
            self._degraded = False
        except Exception:
            self._redis = None
            self._degraded = True

    def _check_redis(self, key: str, window_seconds: int) -> int:
        now = time.time()
        window_key = self._window_key(key)
        pipe = self._redis.pipeline(transaction=False)
        pipe.zremrangebyscore(window_key, "-inf", now - window_seconds)
        pipe.zcard(window_key)
        _, count = pipe.execute()
        return int(count)

    def _prune_memory(self, bucket: deque[tuple[float, str]], window_seconds: int) -> None:
        cutoff = time.monotonic() - window_seconds
        while bucket and bucket[0][0] <= cutoff:
            bucket.popleft()

    def _memory_bucket(self, key: str, window_seconds: int) -> deque[tuple[float, str]]:
        window_key = self._window_key(key)
        bucket = self._buckets.get(window_key)
        if bucket is None:
            if len(self._buckets) >= _MEMORY_MAX_KEYS:
                # Evict the oldest bucket so a key spray cannot grow the map
                # without bound while the Redis limiter is degraded.
                self._buckets.pop(next(iter(self._buckets)), None)
            bucket = deque()
            self._buckets[window_key] = bucket
        self._prune_memory(bucket, window_seconds)
        return bucket

    def _check_memory(self, key: str, window_seconds: int) -> int:
        with self._lock:
            return len(self._memory_bucket(key, window_seconds))

    def _reserve_memory(self, key: str, window_seconds: int, limit: int) -> Reservation:
        with self._lock:
            bucket = self._memory_bucket(key, window_seconds)
            if len(bucket) >= limit:
                return Reservation(False, len(bucket))
            token = uuid.uuid4().hex
            bucket.append((time.monotonic(), token))
            return Reservation(True, len(bucket), token)

    def _reserve_redis(self, key: str, window_seconds: int, limit: int) -> Reservation:
        now = time.time()
        token = f"{now:.6f}:{uuid.uuid4().hex}"
        result = self._redis.eval(
            _RESERVE_SCRIPT,
            1,
            self._window_key(key),
            now,
            window_seconds,
            limit,
            token,
        )
        allowed, count = result
        return Reservation(bool(int(allowed)), int(count), token if allowed else None)

    def reserve(self, key: str, window_seconds: int, limit: int) -> Reservation:
        window_seconds = max(1, int(window_seconds))
        limit = max(1, int(limit))

        if not self._degraded and self._redis is not None:
            try:
                return self._reserve_redis(key, window_seconds, limit)
            except Exception:
                self._mark_degraded()

        if self._degraded:
            self._try_reconnect()
            if not self._degraded and self._redis is not None:
                try:
                    return self._reserve_redis(key, window_seconds, limit)
                except Exception:
                    self._mark_degraded()

        if self._require_redis:
            raise RateLimiterUnavailable("Redis rate limiter is unavailable")
        return self._reserve_memory(key, window_seconds, limit)

    def release(self, key: str, window_seconds: int, token: str | None) -> None:
        if not token:
            return
        if not self._degraded and self._redis is not None:
            try:
                self._redis.zrem(self._window_key(key), token)
                return
            except Exception:
                self._mark_degraded()
        with self._lock:
            bucket = self._buckets.get(self._window_key(key))
            if bucket:
                self._prune_memory(bucket, max(1, int(window_seconds)))
                for index, (_, bucket_token) in enumerate(bucket):
                    if bucket_token == token:
                        del bucket[index]
                        break

    def count(self, key: str, window_seconds: int) -> int:
        if not self._degraded and self._redis is not None:
            try:
                return self._check_redis(key, max(1, int(window_seconds)))
            except Exception:
                self._mark_degraded()
        return self._check_memory(key, max(1, int(window_seconds)))

    def record(self, key: str, window_seconds: int) -> None:
        window_seconds = max(1, int(window_seconds))
        if not self._degraded and self._redis is not None:
            try:
                now = time.time()
                window_key = self._window_key(key)
                pipe = self._redis.pipeline(transaction=False)
                pipe.zadd(window_key, {f"{now:.6f}:{uuid.uuid4().hex}": now})
                pipe.expire(window_key, window_seconds + 1)
                pipe.execute()
                return
            except Exception:
                self._mark_degraded()
        with self._lock:
            bucket = self._memory_bucket(key, window_seconds)
            bucket.append((time.monotonic(), uuid.uuid4().hex))

    def available(self) -> bool:
        return not self._require_redis or (self._redis is not None and not self._degraded)


limiter = SlidingWindowRateLimiter()
