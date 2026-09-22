import os
import threading
import time
from collections import deque

try:
    import redis as _redis_lib
    _REDIS_AVAILABLE = True
except ImportError:
    _redis_lib = None
    _REDIS_AVAILABLE = False

REDIS_URL = os.getenv("REDIS_URL", "redis://redis:6379/0")
_RECONNECT_INTERVAL = 30.0
_KEY_PREFIX = "rate:"


class SlidingWindowRateLimiter:
    def __init__(self, use_memory_only: bool = False):
        self._lock = threading.Lock()
        self._buckets: dict[str, deque] = {}
        self._redis = None
        self._degraded = True
        self._last_attempt = 0.0

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

    def _check_redis(self, key: str, window_seconds: int) -> int:
        now = time.time()
        window_key = _KEY_PREFIX + key
        pipe = self._redis.pipeline(transaction=False)
        pipe.zremrangebyscore(window_key, 0, now - window_seconds)
        pipe.zcard(window_key)
        _, count = pipe.execute()
        return int(count)

    def _check_memory(self, key: str, window_seconds: int) -> int:
        now = time.monotonic()
        with self._lock:
            dq = self._buckets.setdefault(key, deque())
            cutoff = now - window_seconds
            while dq and dq[0] < cutoff:
                dq.popleft()
            return len(dq)

    def _try_reconnect(self) -> None:
        if self._redis is None:
            return
        now = time.monotonic()
        if now - self._last_attempt < _RECONNECT_INTERVAL:
            return
        self._last_attempt = now
        try:
            self._redis.ping()
            self._degraded = False
        except Exception:
            self._degraded = True

    def count(self, key: str, window_seconds: int) -> int:
        if not self._degraded and self._redis is not None:
            try:
                return self._check_redis(key, window_seconds)
            except Exception:
                self._degraded = True
                self._last_attempt = time.monotonic()
        if self._degraded and self._redis is not None:
            self._try_reconnect()
            if not self._degraded:
                try:
                    return self._check_redis(key, window_seconds)
                except Exception:
                    self._degraded = True
        return self._check_memory(key, window_seconds)

    def record(self, key: str, window_seconds: int) -> None:
        if not self._degraded and self._redis is not None:
            try:
                now = time.time()
                window_key = _KEY_PREFIX + key
                pipe = self._redis.pipeline(transaction=False)
                pipe.zadd(window_key, {f"{now}": now})
                pipe.expire(window_key, window_seconds + 1)
                pipe.execute()
                return
            except Exception:
                self._degraded = True
                self._last_attempt = time.monotonic()
        with self._lock:
            self._buckets.setdefault(key, deque()).append(time.monotonic())


limiter = SlidingWindowRateLimiter()