import hmac
import secrets

from fastapi.responses import JSONResponse

from api.rate_limiter import RateLimiterUnavailable, limiter

_ADMIN_LIMIT = 10
_ADMIN_WINDOW = 60


def generate_api_key():
    return "pk_" + secrets.token_hex(24)


def check_rate_limit(api_key: str, rate_limit: int, window_seconds: int):
    try:
        reservation = limiter.reserve(api_key, window_seconds, rate_limit)
    except RateLimiterUnavailable:
        return api_error(
            503,
            "RATE_LIMIT_UNAVAILABLE",
            "Rate limiting is temporarily unavailable",
        )
    if not reservation.allowed:
        return api_error(
            429,
            "rate_limit_exceeded",
            f"Rate limit exceeded. Retry in {window_seconds}s",
            {"retry_after": window_seconds},
        )
    return reservation.count


def record_prediction(api_key: str, window_seconds: int) -> None:
    limiter.record(api_key, window_seconds)


def master_key_ok(provided: str | None, expected: str) -> bool:
    if not provided or not expected:
        return False
    return hmac.compare_digest(provided, expected)


def check_admin_rate_limit(client_ip: str) -> bool | None:
    if not client_ip:
        return False
    try:
        return limiter.reserve(f"admin:{client_ip}", _ADMIN_WINDOW, _ADMIN_LIMIT).allowed
    except RateLimiterUnavailable:
        return None


def rate_limit_backend() -> str:
    return "redis" if not limiter._degraded and limiter._redis else "memory"


def api_error(
    status_code: int,
    code: str,
    message: str,
    extra: dict | None = None,
):
    payload = {
        "error": {
            "code": code,
            "message": message,
        }
    }
    if extra:
        payload["error"].update(extra)
    return JSONResponse(status_code=status_code, content=payload)
