import os
import threading
import time
from collections import OrderedDict

CACHE_ENABLED = os.getenv("CACHE_ENABLED", "1") == "1"


class TTLCache:
    def __init__(self, max_items: int = 256, ttl_seconds: int = 900):
        self.max_items = max(1, max_items)
        self.ttl_seconds = max(0, ttl_seconds)
        self._store: "OrderedDict[str, tuple[object, float]]" = OrderedDict()
        self._lock = threading.Lock()

    def get(self, key: str):
        if self.ttl_seconds == 0:
            return None
        now = time.monotonic()
        with self._lock:
            item = self._store.get(key)
            if item is None:
                return None
            value, expires_at = item
            if now >= expires_at:
                del self._store[key]
                return None
            self._store.move_to_end(key)
            return value

    def set(self, key: str, value) -> None:
        if self.ttl_seconds == 0:
            return
        now = time.monotonic()
        with self._lock:
            self._store[key] = (value, now + self.ttl_seconds)
            self._store.move_to_end(key)
            while len(self._store) > self.max_items:
                self._store.popitem(last=False)

    def clear(self) -> None:
        with self._lock:
            self._store.clear()

    def __len__(self) -> int:
        with self._lock:
            return len(self._store)


result_cache = TTLCache(
    max_items=int(os.getenv("CACHE_MAX_ITEMS", "256")),
    ttl_seconds=int(os.getenv("CACHE_TTL", "900")),
)