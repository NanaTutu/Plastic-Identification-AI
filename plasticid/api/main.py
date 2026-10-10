import asyncio
import hashlib
import json
import logging
import os
import time
import uuid
from contextlib import asynccontextmanager
from pathlib import Path

import httpx
import pymysql
import pymysql.err
from fastapi import BackgroundTasks, Body, FastAPI, File, Header, HTTPException, Request, Response, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from prometheus_fastapi_instrumentator import Instrumentator

from api.auth import (
    api_error,
    check_admin_rate_limit,
    master_key_ok,
    reserve_rate_limit,
)
from api.database import (
    DatabaseBusy,
    claim_outbox_entries,
    claim_outbox_entry,
    close_db_pool,
    create_api_key_record_with_id,
    deactivate_api_key,
    get_api_key_record,
    get_or_create_portal_key,
    get_prediction_logs,
    get_stats,
    init_db,
    list_api_keys,
    mark_outbox_delivered,
    mark_outbox_failed,
    mask_key,
    record_prediction_event,
    schema_diagnostics,
)
from api.inference import (
    MAX_FILE_SIZE,
    MODEL_NAME,
    InferenceBusyError,
    is_model_loaded,
    run_inference,
    validate_file,
    validate_image_content,
    warmup,
)
from api.inference_cache import CACHE_ENABLED, result_cache
from api.preprocess import open_image
from api.rate_limiter import limiter
from api.schema import PredictionResponse

logger = logging.getLogger("plasticid-api")
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s | %(message)s",
)

API_KEY = os.getenv("API_KEY")
if not API_KEY:
    raise RuntimeError("API_KEY environment variable is required")

CI_API_KEY = os.getenv("CI_API_KEY") or API_KEY
CI_ENDPOINT = os.getenv("CI_ENDPOINT", "http://ci/api/predictions")
DB_STARTUP_REQUIRED = os.getenv("DB_STARTUP_REQUIRED", "0") == "1"
DB_STARTUP_ATTEMPTS = max(1, int(os.getenv("DB_STARTUP_ATTEMPTS", "5")))
DB_STARTUP_DELAY = max(0.0, float(os.getenv("DB_STARTUP_DELAY", "1")))
CACHE_MAX_IMAGE_SIZE = max(0, int(os.getenv("CACHE_MAX_IMAGE_SIZE", str(2 * 1024 * 1024))))
OUTBOX_WORKER_ENABLED = os.getenv("OUTBOX_WORKER_ENABLED", "1") == "1"
OUTBOX_POLL_INTERVAL = max(0.5, float(os.getenv("OUTBOX_POLL_INTERVAL", "5")))
OUTBOX_BATCH_SIZE = max(1, min(int(os.getenv("OUTBOX_BATCH_SIZE", "10")), 100))
# Cheap read endpoints (history / usage) get their own fixed quota so a valid
# key cannot use them to flood the database.
READ_RATE_LIMIT = max(1, int(os.getenv("READ_RATE_LIMIT", "120")))
READ_RATE_WINDOW = max(1, int(os.getenv("READ_RATE_WINDOW", "60")))
CORS_ALLOWED_ORIGINS = [
    origin.strip()
    for origin in os.getenv("CORS_ALLOWED_ORIGINS", "http://localhost:8080").split(",")
    if origin.strip()
]
_outbox_task: asyncio.Task | None = None


async def _read_limited_upload(image: UploadFile, max_bytes: int) -> bytes:
    data = bytearray()
    while len(data) <= max_bytes:
        chunk = await image.read(min(1024 * 1024, max_bytes + 1 - len(data)))
        if not chunk:
            break
        data.extend(chunk)
    return bytes(data)


def _is_retryable_db_error(exc: Exception) -> bool:
    """Transient faults are worth retrying; SQL defects are not.

    A malformed statement will fail identically on every attempt, so retrying it
    only multiplies the cost of an already-invalid change. The one RuntimeError
    worth waiting out is a missing schema contract: the ci service applies the
    CodeIgniter migrations at startup and we may have raced it.
    """
    if isinstance(exc, RuntimeError):
        return str(exc).startswith("Database schema verification failed")
    driver_errors = tuple(
        error
        for error in (
            getattr(pymysql, "OperationalError", None),
            getattr(pymysql, "InterfaceError", None),
            getattr(pymysql.err, "InternalError", None),
        )
        if error is not None
    )
    if driver_errors and isinstance(exc, driver_errors):
        codes = {2002, 2003, 2006, 2013, 1040, 1205, 1213, 3572}
        return exc.args and exc.args[0] in codes
    return False


async def _initialize_database() -> bool:
    last_error: Exception | None = None
    for attempt in range(1, DB_STARTUP_ATTEMPTS + 1):
        try:
            await asyncio.to_thread(init_db)
            logger.info("Database schema verified")
            return True
        except Exception as exc:
            last_error = exc
            logger.error(
                "Database initialization attempt %s/%s failed: %s",
                attempt,
                DB_STARTUP_ATTEMPTS,
                exc,
            )
            if not _is_retryable_db_error(exc):
                logger.error("Database initialization error is not retryable")
                break
            if attempt < DB_STARTUP_ATTEMPTS:
                await asyncio.sleep(DB_STARTUP_DELAY)

    if DB_STARTUP_REQUIRED and last_error is not None:
        raise RuntimeError("Database schema initialization failed") from last_error
    return False


async def _run_outbox_worker() -> None:
    while True:
        try:
            entries = await asyncio.to_thread(claim_outbox_entries, OUTBOX_BATCH_SIZE)
            for entry in entries:
                await _deliver_claimed_outbox(entry)
        except asyncio.CancelledError:
            raise
        except Exception:
            logger.exception("Prediction outbox worker failed")
        await asyncio.sleep(OUTBOX_POLL_INTERVAL)


@asynccontextmanager
async def lifespan(_app: FastAPI):
    global _outbox_task
    database_ready = await _initialize_database()

    try:
        await asyncio.to_thread(warmup)
        logger.info("Inference models warmed up")
    except Exception as exc:
        logger.error("Failed to warm up inference models: %s", exc)

    portal_key = os.getenv("PORTAL_API_KEY") or os.getenv("PLASTICID_PORTAL_API_KEY")
    if portal_key:
        try:
            await asyncio.to_thread(get_or_create_portal_key, portal_key)
            logger.info("Portal API key registered")
        except Exception as exc:
            logger.error("Failed to register portal key: %s", exc)
    else:
        logger.warning("PORTAL_API_KEY not set; portal playground predictions are disabled")

    if database_ready and OUTBOX_WORKER_ENABLED:
        _outbox_task = asyncio.create_task(_run_outbox_worker())

    try:
        yield
    finally:
        if _outbox_task is not None:
            _outbox_task.cancel()
            await asyncio.gather(_outbox_task, return_exceptions=True)
            _outbox_task = None
        try:
            close_db_pool()
        except Exception as exc:
            logger.error("Failed to close database pool: %s", exc)


app = FastAPI(title="Plastic Identification API", lifespan=lifespan)
Instrumentator().instrument(app).expose(app)

if CORS_ALLOWED_ORIGINS:
    app.add_middleware(
        CORSMiddleware,
        allow_origins=CORS_ALLOWED_ORIGINS,
        allow_credentials=False,
        allow_methods=["GET", "POST", "DELETE", "OPTIONS"],
        allow_headers=["X-API-KEY", "Content-Type"],
    )


@app.exception_handler(DatabaseBusy)
async def _database_busy_handler(_request: Request, _exc: DatabaseBusy):
    return api_error(
        503,
        "DATABASE_BUSY",
        "Database is temporarily busy",
        {"retry_after": 5},
    )


@app.exception_handler(InferenceBusyError)
async def _inference_busy_handler(_request: Request, _exc: InferenceBusyError):
    return api_error(
        503,
        "INFERENCE_BUSY",
        "Inference capacity is saturated",
        {"retry_after": 2},
    )


@app.middleware("http")
async def log_requests(request: Request, call_next):
    start_time = time.time()
    response = await call_next(request)
    process_time = (time.time() - start_time) * 1000
    logger.info(
        "%s %s | api_key=%s | Status: %s | Time: %.2fms",
        request.method,
        request.url.path,
        "redacted" if request.query_params.get("api_key") else "none",
        response.status_code,
        process_time,
    )
    return response


def _cache_key(mode: str, data: bytes) -> str:
    return f"{mode}:{hashlib.sha256(data).hexdigest()}"


def _safe_filename(filename: str | None, fallback: str) -> str:
    if not filename:
        return fallback
    value = Path(filename.replace("\\", "/")).name
    value = "".join(char for char in value if ord(char) >= 32 and ord(char) != 127)
    return value[:255] or fallback


def _image_dimensions(data: bytes) -> tuple[int | None, int | None]:
    try:
        image = open_image(data)
        try:
            return image.size
        finally:
            image.close()
    except Exception:
        return None, None


def _require_master(request: Request, x_api_key: str | None):
    if not master_key_ok(x_api_key, API_KEY):
        return api_error(401, "INVALID_API_KEY", "Invalid master API key")

    client_ip = request.client.host if request.client else "unknown"
    allowed = check_admin_rate_limit(client_ip)
    if allowed is None:
        return api_error(
            503,
            "RATE_LIMIT_UNAVAILABLE",
            "Rate limiting is temporarily unavailable",
        )
    if not allowed:
        return api_error(
            429,
            "rate_limit_exceeded",
            "Too many admin requests. Please retry later.",
        )
    return None


def _read_rate_limit(api_key: str):
    """Apply a fixed quota to cheap read endpoints, independent of the
    prediction quota, so a valid key cannot use them to flood the database."""
    reservation = reserve_rate_limit(
        f"read:{api_key}", READ_RATE_LIMIT, READ_RATE_WINDOW
    )
    if isinstance(reservation, JSONResponse):
        return reservation
    return None


@app.get("/")
def health_check():
    return {
        "status": "ok",
        "model_loaded": is_model_loaded(),
        "service": "Plastic Identification API",
        "model": MODEL_NAME,
    }


@app.get("/health/db")
def db_health():
    diagnostics = schema_diagnostics()
    if diagnostics["ok"]:
        return {"db_status": "ok", "schema_version": diagnostics["version"]}
    return JSONResponse(
        status_code=503,
        content={
            "db_status": "unavailable",
            "schema": {
                "version": diagnostics.get("version"),
                "missing_tables": diagnostics.get("missing_tables", []),
                "missing_indexes": diagnostics.get("missing_indexes", []),
            },
        },
    )


@app.get("/health/ready")
def readiness():
    diagnostics = schema_diagnostics()
    checks = {
        "database": bool(diagnostics["ok"]),
        "schema": bool(diagnostics["ok"]),
        "model": is_model_loaded(),
        "rate_limiter": limiter.available(),
    }
    ready = all(checks.values())
    return JSONResponse(
        status_code=200 if ready else 503,
        content={"status": "ready" if ready else "not_ready", "checks": checks},
    )


@app.get("/v1/stats")
def get_stats_endpoint(
    request: Request,
    x_api_key: str | None = Header(default=None),
):
    error = _require_master(request, x_api_key)
    if error:
        return error
    return get_stats()


@app.post("/v1/keys")
def create_key(
    request: Request,
    x_api_key: str | None = Header(default=None),
    owner: str = Body(...),
    rate_limit: int = Body(default=10),
    window_seconds: int = Body(default=60),
):
    error = _require_master(request, x_api_key)
    if error:
        return error

    owner = owner.strip()
    if not owner or len(owner) > 255:
        return api_error(400, "INVALID_OWNER", "Owner must be 1-255 characters")
    if owner.casefold() == "portal":
        return api_error(400, "INVALID_OWNER", "The owner name 'portal' is reserved")
    if isinstance(rate_limit, bool) or not 1 <= rate_limit <= 100_000:
        return api_error(400, "INVALID_RATE_LIMIT", "Rate limit must be between 1 and 100000")
    if isinstance(window_seconds, bool) or not 1 <= window_seconds <= 86_400:
        return api_error(400, "INVALID_WINDOW", "Window must be between 1 and 86400 seconds")

    api_key, api_key_id = create_api_key_record_with_id(owner, rate_limit, window_seconds)
    return {
        "id": api_key_id,
        "api_key": api_key,
        "owner": owner,
        "key_type": "user",
        "rate_limit": rate_limit,
        "window_seconds": window_seconds,
    }


@app.get("/v1/list_keys")
def get_list_keys(
    request: Request,
    x_api_key: str | None = Header(default=None),
):
    error = _require_master(request, x_api_key)
    if error:
        return error
    return {"api_keys": list_api_keys()}


@app.delete("/v1/keys/{key}")
def remove_key(
    key: str,
    request: Request,
    x_api_key: str | None = Header(default=None),
):
    error = _require_master(request, x_api_key)
    if error:
        return error

    if not deactivate_api_key(key):
        return api_error(404, "KEY_NOT_FOUND", "API key not found")
    return {"status": "deactivated", "api_key": mask_key(key)}


@app.get("/v1/usage")
def usage(x_api_key: str | None = Header(default=None)):
    key = x_api_key
    if not key:
        return api_error(401, "API_KEY_REQUIRED", "An API key must be provided")

    record = get_api_key_record(key)
    if not record:
        return api_error(401, "INVALID_API_KEY", "Invalid or inactive API key")

    error = _read_rate_limit(key)
    if error is not None:
        return error

    return {
        "api_key": mask_key(key),
        "is_active": bool(record["is_active"]),
        "rate_limit": record["rate_limit"],
        "window_seconds": record["window_seconds"],
        "total_requests": record["total_requests"],
    }


@app.post("/v1/predict", response_model=PredictionResponse)
async def predict_v1(
    request: Request,
    response: Response,
    background_tasks: BackgroundTasks,
    image: UploadFile = File(...),
    x_api_key: str | None = Header(default=None),
):
    key = x_api_key
    job_id = str(uuid.uuid4())

    if not key:
        return api_error(401, "API_KEY_REQUIRED", "An API key must be provided")

    content_length = request.headers.get("content-length")
    if content_length:
        try:
            if int(content_length) > MAX_FILE_SIZE + 2 * 1024 * 1024:
                return api_error(413, "FILE_TOO_LARGE", "File size exceeds the 10MB limit")
        except ValueError:
            return api_error(400, "INVALID_REQUEST", "Invalid Content-Length header")

    key_record = await asyncio.to_thread(get_api_key_record, key)
    if not key_record:
        return api_error(401, "INVALID_API_KEY", "Invalid or inactive API key")

    if not is_model_loaded():
        return api_error(503, "MODEL_UNAVAILABLE", "Inference model is not ready")

    reservation = await asyncio.to_thread(
        reserve_rate_limit,
        key,
        key_record["rate_limit"],
        key_record["window_seconds"],
    )
    if isinstance(reservation, JSONResponse):
        return reservation

    response.headers["X-RateLimit-Limit"] = str(key_record["rate_limit"])
    response.headers["X-RateLimit-Remaining"] = str(
        max(0, key_record["rate_limit"] - reservation.count)
    )
    response.headers["X-RateLimit-Reset"] = str(
        int(time.time() + key_record["window_seconds"])
    )

    def _release_reservation() -> None:
        limiter.release(key, key_record["window_seconds"], reservation.token)

    # Reserve-then-release: any path that does not return a prediction hands the
    # slot back, so invalid uploads and service errors do not consume quota.
    delivered = False
    try:
        try:
            validate_file(image)
        except HTTPException as exc:
            return api_error(exc.status_code, "INVALID_FILE_TYPE", str(exc.detail))

        img_bytes = await _read_limited_upload(image, MAX_FILE_SIZE)
        if len(img_bytes) > MAX_FILE_SIZE:
            return api_error(413, "FILE_TOO_LARGE", "File size exceeds the 10MB limit")

        if not validate_image_content(img_bytes):
            return api_error(400, "INVALID_IMAGE", "Uploaded file is not a valid JPEG or PNG image")

        cache_key = _cache_key("v1", img_bytes)
        cache_allowed = CACHE_ENABLED and len(img_bytes) <= CACHE_MAX_IMAGE_SIZE
        cached = result_cache.get(cache_key) if cache_allowed else None
        start = time.time()
        try:
            if cached is None:
                detections, detected_object = await asyncio.to_thread(run_inference, img_bytes)
                if cache_allowed:
                    result_cache.set(cache_key, (detections, detected_object))
            else:
                detections, detected_object = cached
        except InferenceBusyError:
            logger.warning("Inference capacity exhausted; shedding load")
            return api_error(
                503,
                "INFERENCE_BUSY",
                "Inference capacity is saturated. Please retry.",
                {"retry_after": 2},
            )
        except Exception as exc:
            logger.exception("Inference failed: %s", exc)
            return api_error(503, "INFERENCE_UNAVAILABLE", "Inference service is temporarily unavailable")

        inference_time = int((time.time() - start) * 1000)

        try:
            image_width, image_height = _image_dimensions(img_bytes)
            image_sha256 = hashlib.sha256(img_bytes).hexdigest()
            source = "portal" if key_record.get("key_type") == "service" or key_record.get("owner") == "portal" else "api"
            ci_detections = [
                {
                    "class": detection["class_name"],
                    "confidence": detection["confidence"],
                    "bbox": detection["bbox"],
                }
                for detection in detections
            ]
            ci_payload = {
                "job_id": job_id,
                "filename": _safe_filename(image.filename, job_id),
                "content_type": (image.content_type or "application/octet-stream").split(";", 1)[0].lower(),
                "model": "plasticid_v1",
                "detections": ci_detections,
                "count": len(detections),
                "inference_ms": inference_time,
                "source": source,
                "api_key_id": key_record.get("id"),
                "detected_object": detected_object,
                "image_width": image_width,
                "image_height": image_height,
                "image_sha256": image_sha256,
            }
            outbox_id = await asyncio.to_thread(
                record_prediction_event,
                key,
                key_record.get("id"),
                job_id,
                "plasticid_v1",
                len(detections),
                inference_time,
                ci_payload,
                detected_object,
                image_sha256,
                source,
            )
        except Exception as exc:
            logger.exception("Failed to persist prediction metadata: %s", exc)
            return api_error(503, "USAGE_RECORD_FAILED", "Prediction could not be recorded")

        background_tasks.add_task(deliver_outbox_entry, outbox_id)
        delivered = True
        return {
            "job_id": job_id,
            "model": "plasticid_v1",
            "count": len(detections),
            "detections": detections,
            "inference_ms": inference_time,
            "detected_object": detected_object,
        }
    finally:
        if not delivered:
            await asyncio.to_thread(_release_reservation)


@app.get("/v1/predictions")
def get_predictions(
    x_api_key: str | None = Header(default=None),
    limit: int = 20,
):
    key = x_api_key
    if not key:
        return api_error(401, "API_KEY_REQUIRED", "An API key must be provided")

    key_record = get_api_key_record(key)
    if not key_record:
        return api_error(401, "INVALID_API_KEY", "Invalid or inactive API key")

    error = _read_rate_limit(key)
    if error is not None:
        return error

    try:
        limit = max(1, min(int(limit), 100))
    except (TypeError, ValueError):
        limit = 20

    rows = get_prediction_logs(key, limit)
    return {"api_key": mask_key(key), "results": rows}


async def _post_to_ci(payload: dict) -> None:
    async with httpx.AsyncClient(timeout=5) as client:
        response = await client.post(
            CI_ENDPOINT,
            json=payload,
            headers={"X-API-KEY": CI_API_KEY},
        )
    if 200 <= response.status_code < 300:
        return
    if 400 <= response.status_code < 500:
        raise PermanentDeliveryError(f"CI backend rejected payload with HTTP {response.status_code}")
    raise RuntimeError(f"CI backend returned HTTP {response.status_code}")


class PermanentDeliveryError(RuntimeError):
    pass


async def _deliver_claimed_outbox(entry: dict) -> None:
    outbox_id = int(entry["id"])
    claim_token = entry["claim_token"]
    payload = entry["payload"]
    if isinstance(payload, str):
        try:
            payload = json.loads(payload)
        except json.JSONDecodeError:
            await asyncio.to_thread(
                mark_outbox_failed,
                outbox_id,
                claim_token,
                "Invalid JSON payload",
                True,
                int(entry.get("attempts") or 0),
            )
            return

    try:
        await _post_to_ci(payload)
    except PermanentDeliveryError as exc:
        await asyncio.to_thread(
            mark_outbox_failed,
            outbox_id,
            claim_token,
            str(exc),
            True,
            int(entry.get("attempts") or 0),
        )
    except Exception as exc:
        logger.error("Outbox delivery %s failed: %s", outbox_id, exc)
        await asyncio.to_thread(
            mark_outbox_failed,
            outbox_id,
            claim_token,
            str(exc),
            False,
            int(entry.get("attempts") or 0),
        )
    else:
        await asyncio.to_thread(mark_outbox_delivered, outbox_id, claim_token)


async def deliver_outbox_entry(outbox_id: int) -> None:
    entry = await asyncio.to_thread(claim_outbox_entry, outbox_id)
    if entry is not None:
        await _deliver_claimed_outbox(entry)

