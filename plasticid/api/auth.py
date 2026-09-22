import hmac
import secrets
import time

from fastapi.responses import JSONResponse

from api.rate_limiter import limiter

_ADMIN_LIMIT = 10
_ADMIN_WINDOW = 60


def generate_api_key():
    return "pk_" + secrets.token_hex(24)


def check_rate_limit(api_key: str, rate_limit: int, window_seconds: int):
    count = limiter.count(api_key, window_seconds)
    if count >= rate_limit:
        retry_after = window_seconds
        return api_error(
            status_code=429,
            code="rate_limit_exceeded",
            message=f"Rate limit exceeded. Retry in {retry_after}s",
            extra={"retry_after": retry_after}
        )
    return count


def record_prediction(api_key: str, window_seconds: int) -> None:
    limiter.record(api_key, window_seconds)


def master_key_ok(provided: str | None, expected: str) -> bool:
    if not provided or not expected:
        return False
    return hmac.compare_digest(provided, expected)


def check_admin_rate_limit(client_ip: str) -> bool:
    if not client_ip:
        return False
    if limiter.count(f"admin:{client_ip}", _ADMIN_WINDOW) >= _ADMIN_LIMIT:
        return False
    limiter.record(f"admin:{client_ip}", _ADMIN_WINDOW)
    return True


def rate_limit_backend() -> str:
    return "redis" if not limiter._degraded and limiter._redis else "memory"


def api_error(
    status_code: int,
    code: str,
    message: str,
    extra: dict | None = None
):
    payload = {
        "error": {
            "code": code,
            "message": message
        }
    }
    if extra:
        payload["error"].update(extra)

    return JSONResponse(status_code=status_code, content=payload)