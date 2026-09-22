from fastapi.testclient import TestClient
from fastapi.responses import JSONResponse
from PIL import Image
import io
import os
import sys
from unittest import mock

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

os.environ["MYSQL_PASSWORD"] = "test_password"
os.environ["API_KEY"] = "test_api_key"
os.environ["DB_HOST"] = "localhost"
os.environ["ONNX_INFERENCE"] = "0"
os.environ.setdefault("REDIS_URL", "redis://127.0.0.1:6399/0")

from api.inference import sanitize_job_id
from api import main
from api.preprocess import MAX_IMAGE_EDGE, prepare_image, rescale_bbox
from api.inference_cache import TTLCache
from api.rate_limiter import SlidingWindowRateLimiter
import api.auth as auth


def make_image() -> bytes:
    buf = io.BytesIO()
    Image.new("RGB", (640, 640), color="red").save(buf, format="JPEG")
    return buf.getvalue()


def key_record():
    return {
        "api_key": "pk_hash",
        "owner": "tester",
        "is_active": 1,
        "rate_limit": 10,
        "window_seconds": 60,
        "total_requests": 0
    }


@pytest.fixture
def api_client():
    with TestClient(main.app) as client:
        yield client


def test_health_check(api_client):
    response = api_client.get("/")
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "ok"
    assert data["service"] == "Plastic Identification API"


def test_predict_no_api_key(api_client):
    response = api_client.post(
        "/v1/predict",
        files={"image": ("test.jpg", make_image(), "image/jpeg")},
    )
    assert response.status_code == 401
    assert response.json()["error"]["code"] == "API_KEY_REQUIRED"


def test_predict_invalid_api_key(api_client):
    with mock.patch.object(main, "get_api_key_record", return_value=None):
        response = api_client.post(
            "/v1/predict",
            headers={"X-API-KEY": "pk_unknown"},
            files={"image": ("test.jpg", make_image(), "image/jpeg")},
        )
    assert response.status_code == 401
    assert response.json()["error"]["code"] == "INVALID_API_KEY"


def test_predict_success(api_client):
    with mock.patch.object(main, "get_api_key_record", return_value=key_record()), \
         mock.patch.object(main, "check_rate_limit", return_value=0), \
         mock.patch.object(main, "run_inference",
                           return_value=([], "unknown")), \
         mock.patch.object(main, "increment_usage"), \
         mock.patch.object(main, "log_prediction"), \
         mock.patch.object(main, "send_to_ci") as send_mock:
        response = api_client.post(
            "/v1/predict",
            headers={"X-API-KEY": "pk_test"},
            files={"image": ("test.jpg", make_image(), "image/jpeg")},
        )
    assert response.status_code == 200
    data = response.json()
    assert data["job_id"]
    assert data["count"] == 0
    assert data["detections"] == []
    assert "X-RateLimit-Remaining" in response.headers
    assert send_mock.call_count == 1


def test_rate_limit_exceeded(api_client):
    limited = JSONResponse(
        status_code=429,
        content={"error": {"code": "rate_limit_exceeded", "message": "slow down"}},
    )
    with mock.patch.object(main, "get_api_key_record", return_value=key_record()), \
         mock.patch.object(main, "check_rate_limit", return_value=limited):
        response = api_client.post(
            "/v1/predict",
            headers={"X-API-KEY": "pk_test"},
            files={"image": ("test.jpg", make_image(), "image/jpeg")},
        )
    assert response.status_code == 429


def test_validate_file_type_rejected(api_client):
    with mock.patch.object(main, "get_api_key_record", return_value=key_record()), \
         mock.patch.object(main, "check_rate_limit", return_value=0):
        response = api_client.post(
            "/v1/predict",
            headers={"X-API-KEY": "pk_test"},
            files={"image": ("test.txt", b"not an image", "text/plain")},
        )
    assert response.status_code == 400


def test_image_content_validation(api_client):
    with mock.patch.object(main, "get_api_key_record", return_value=key_record()), \
         mock.patch.object(main, "check_rate_limit", return_value=0):
        response = api_client.post(
            "/v1/predict",
            headers={"X-API-KEY": "pk_test"},
            files={"image": ("test.jpg", b"not actually a jpeg", "image/jpeg")},
        )
    assert response.status_code == 400
    assert response.json()["error"]["code"] == "INVALID_IMAGE"


def test_master_key_required(api_client):
    response = api_client.get("/v1/stats")
    assert response.status_code == 401


def test_invalid_master_key(api_client):
    response = api_client.get("/v1/stats", headers={"X-API-KEY": "wrong"})
    assert response.status_code == 401


def test_admin_rate_limit_exceeded(api_client):
    with mock.patch.object(main, "check_admin_rate_limit", return_value=False):
        response = api_client.get("/v1/stats", headers={"X-API-KEY": "test_api_key"})
    assert response.status_code == 429


def test_predictions_limit_clamped(api_client):
    with mock.patch.object(main, "get_api_key_record", return_value=key_record()), \
         mock.patch.object(main, "get_prediction_logs", return_value=[]) as mocked:
        api_client.get("/v1/predictions", headers={"X-API-KEY": "pk_test"}, params={"limit": 9999})
        assert mocked.call_args[0][1] == 100


def test_sanitize_job_id_removes_path_traversal():
    result = sanitize_job_id("../../etc/passwd")
    assert result == "etcpasswd"
    assert "/" not in result
    assert "." not in result


def test_sanitize_job_id_fallback_on_empty():
    result = sanitize_job_id("!!!")
    assert result.startswith("job_")


def test_ttl_cache_basic():
    cache = TTLCache(max_items=2, ttl_seconds=60)
    cache.set("a", 1)
    assert cache.get("a") == 1
    assert cache.get("missing") is None


def test_ttl_cache_lru_eviction():
    cache = TTLCache(max_items=2, ttl_seconds=60)
    cache.set("a", 1)
    cache.set("b", 2)
    cache.set("c", 3)
    assert cache.get("a") is None
    assert cache.get("b") == 2
    assert cache.get("c") == 3


def test_ttl_cache_disabled_when_zero():
    cache = TTLCache(max_items=2, ttl_seconds=0)
    cache.set("a", 1)
    assert cache.get("a") is None


def test_prepare_image_downscales_large():
    buf = io.BytesIO()
    Image.new("RGB", (2000, 1000), color="white").save(buf, format="JPEG")
    img, scale_x, scale_y = prepare_image(buf.getvalue())
    assert max(img.size) <= MAX_IMAGE_EDGE
    assert scale_x == 2000 / img.size[0]
    assert scale_y == 1000 / img.size[1]


def test_prepare_image_keeps_small_dimensions():
    buf = io.BytesIO()
    Image.new("RGB", (320, 240), color="green").save(buf, format="JPEG")
    img, scale_x, scale_y = prepare_image(buf.getvalue())
    assert img.size == (320, 240)
    assert scale_x == 1.0
    assert scale_y == 1.0


def test_rescale_bbox():
    assert rescale_bbox([10, 20, 30, 40], 2.0, 3.0) == [20.0, 60.0, 60.0, 120.0]


def test_rate_limiter_memory_enforces_limit():
    mem = SlidingWindowRateLimiter(use_memory_only=True)
    key = f"mem-{id(mem)}"
    counts = []
    for _ in range(3):
        counts.append(mem.count(key, 60))
        mem.record(key, 60)
    assert counts == [0, 1, 2]


def test_check_rate_limit_enforces_429():
    mem = SlidingWindowRateLimiter(use_memory_only=True)
    key = f"rl-{id(mem)}"
    assert isinstance(auth.check_rate_limit(key, 2, 60), int)
    auth.record_prediction(key, 60)
    auth.record_prediction(key, 60)
    result = auth.check_rate_limit(key, 2, 60)
    assert result.status_code == 429