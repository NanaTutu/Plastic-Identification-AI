from contextlib import asynccontextmanager
from fastapi import FastAPI, File, UploadFile, Form, Header, Request, Body, Response, HTTPException
from fastapi.responses import JSONResponse
from api.schema import PredictionResponse
from api.database import (
    get_db, get_api_key_record, increment_usage, log_prediction,
    create_api_key_record, deactivate_api_key, list_api_keys,
    get_prediction_logs, get_stats, init_db, get_or_create_portal_key,
)
from api.auth import (
    check_rate_limit, check_admin_rate_limit, master_key_ok, api_error,
    record_prediction,
)
from api.inference import (
    validate_file, validate_image_content, sanitize_job_id,
    run_inference, run_simple_inference, MAX_FILE_SIZE, MODEL_NAME, warmup,
)
from api.inference_cache import CACHE_ENABLED, result_cache
import uuid
import time
import os
import logging
import asyncio
import hashlib
import httpx
from prometheus_fastapi_instrumentator import Instrumentator


@asynccontextmanager
async def lifespan(_app: FastAPI):
    try:
        init_db()
        logger.info("Database schema initialized")
    except Exception as e:
        logger.error(f"Failed to initialize database schema: {e}")

    try:
        await asyncio.to_thread(warmup)
        logger.info("Inference models warmed up")
    except Exception as e:
        logger.error(f"Failed to warm up inference models: {e}")

    portal_key = os.getenv("PORTAL_API_KEY") or os.getenv("PLASTICID_PORTAL_API_KEY")
    if portal_key:
        try:
            get_or_create_portal_key(portal_key)
            logger.info("Portal API key registered")
        except Exception as e:
            logger.error(f"Failed to register portal key: {e}")
    else:
        logger.warning("PORTAL_API_KEY not set; portal playground predictions are disabled")

    yield


app = FastAPI(title="Plastic Identification API", lifespan=lifespan)
Instrumentator().instrument(app).expose(app)

API_KEY = os.getenv("API_KEY")
CI_ENDPOINT = "http://plasticid-backend/api/predictions"

if not API_KEY:
    raise RuntimeError("API_KEY environment variable is required")

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s | %(message)s",
)

logger = logging.getLogger("plasticid-api")


@app.middleware("http")
async def log_requests(request: Request, call_next):
    start_time = time.time()
    api_key = "redacted" if request.query_params.get("api_key") else "none"
    response = await call_next(request)
    process_time = (time.time() - start_time) * 1000
    logger.info(
        f"{request.method} {request.url.path} | "
        f"api_key={api_key} | "
        f"Status: {response.status_code} | "
        f"Time: {process_time:.2f}ms"
    )
    return response


def _cache_key(mode: str, data: bytes) -> str:
    return f"{mode}:{hashlib.sha256(data).hexdigest()}"


def _require_master(request: Request, x_api_key: str | None):
    if not master_key_ok(x_api_key, API_KEY):
        return api_error(401, "INVALID_API_KEY", "Invalid master API key")
    client_ip = request.client.host if request.client else "unknown"
    if not check_admin_rate_limit(client_ip):
        return api_error(
            429, "rate_limit_exceeded",
            "Too many admin requests. Please retry later."
        )
    return None


@app.get("/")
def health_check():
    return {
        "status": "ok",
        "model_loaded": True,
        "service": "Plastic Identification API",
        "model": MODEL_NAME
    }


@app.get("/health/db")
def db_health():
    try:
        db = get_db()
        cursor = db.cursor()
        cursor.execute("SELECT 1")
        db.close()
        return {"db_status": "ok"}
    except Exception as e:
        return {"db_status": "error", "details": str(e)}


@app.get("/v1/stats")
def get_stats_endpoint(
    request: Request,
    x_api_key: str | None = Header(default=None)
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
    window_seconds: int = Body(default=60)
):
    error = _require_master(request, x_api_key)
    if error:
        return error

    if not owner.strip() or len(owner) > 255:
        return api_error(400, "INVALID_OWNER", "Owner must be 1-255 characters")

    api_key = create_api_key_record(owner, rate_limit, window_seconds)
    return {
        "api_key": api_key,
        "owner": owner,
        "rate_limit": rate_limit,
        "window_seconds": window_seconds
    }


@app.get("/v1/list_keys")
def get_list_keys(
    request: Request,
    x_api_key: str | None = Header(default=None)
):
    error = _require_master(request, x_api_key)
    if error:
        return error
    return {"api_keys": list_api_keys()}


@app.delete("/v1/keys/{key}")
def remove_key(
    key: str,
    request: Request,
    x_api_key: str | None = Header(default=None)
):
    error = _require_master(request, x_api_key)
    if error:
        return error

    if not deactivate_api_key(key):
        return api_error(404, "KEY_NOT_FOUND", "API key not found")
    return {"status": "deactivated", "api_key": key}


@app.get("/v1/usage")
def usage(x_api_key: str | None = Header(default=None)):
    key = x_api_key
    if not key:
        return api_error(401, "API_KEY_REQUIRED", "An API key must be provided")

    record = get_api_key_record(key)
    if not record:
        return api_error(401, "INVALID_API_KEY", "Invalid or inactive API key")

    return {
        "api_key": key,
        "is_active": bool(record["is_active"]),
        "rate_limit": record["rate_limit"],
        "window_seconds": record["window_seconds"],
        "total_requests": record["total_requests"]
    }


@app.post("/v1/predict", response_model=PredictionResponse)
async def predict_v1(
    response: Response,
    image: UploadFile = File(...),
    x_api_key: str | None = Header(default=None)
):
    key = x_api_key
    job_id = str(uuid.uuid4())

    if not key:
        return api_error(401, "API_KEY_REQUIRED", "An API key must be provided")

    key_record = await asyncio.to_thread(get_api_key_record, key)
    if not key_record:
        return api_error(401, "INVALID_API_KEY", "Invalid or inactive API key")

    result = await asyncio.to_thread(
        check_rate_limit, key, key_record["rate_limit"], key_record["window_seconds"]
    )
    if isinstance(result, JSONResponse):
        return result

    response.headers["X-RateLimit-Limit"] = str(key_record["rate_limit"])
    response.headers["X-RateLimit-Remaining"] = str(
        max(0, key_record["rate_limit"] - result)
    )
    response.headers["X-RateLimit-Reset"] = str(int(time.time() + key_record["window_seconds"]))

    start = time.time()

    validate_file(image)

    img_bytes = await image.read()
    if len(img_bytes) > MAX_FILE_SIZE:
        return api_error(413, "FILE_TOO_LARGE", f"File size exceeds {MAX_FILE_SIZE // (1024*1024)}MB limit")

    if not validate_image_content(img_bytes):
        return api_error(400, "INVALID_IMAGE", "Uploaded file is not a valid image")

    cache_key = _cache_key("v1", img_bytes)
    cached = result_cache.get(cache_key) if CACHE_ENABLED else None
    if cached is None:
        detections, detected_object = await asyncio.to_thread(run_inference, img_bytes)
        result_cache.set(cache_key, (detections, detected_object))
    else:
        detections, detected_object = cached

    await asyncio.to_thread(increment_usage, key)

    inference_time = int((time.time() - start) * 1000)

    await asyncio.to_thread(log_prediction, key, job_id, "plasticid_v1", len(detections), inference_time)
    await asyncio.to_thread(record_prediction, key, key_record["window_seconds"])

    return {
        "job_id": job_id,
        "model": "plasticid_v1",
        "count": len(detections),
        "detections": detections,
        "inference_ms": inference_time,
        "detected_object": detected_object
    }


@app.get("/v1/predictions")
def get_predictions(
    x_api_key: str | None = Header(default=None),
    limit: int = 20
):
    key = x_api_key
    if not key:
        return api_error(401, "API_KEY_REQUIRED", "An API key must be provided")

    key_record = get_api_key_record(key)

    if not key_record:
        return api_error(401, "INVALID_API_KEY", "Invalid or inactive API key")

    try:
        limit = max(1, min(int(limit), 100))
    except ValueError:
        limit = 20

    rows = get_prediction_logs(key, limit)

    return {
        "api_key": key,
        "results": rows
    }


async def send_to_ci(payload: dict):
    for attempt in range(3):
        try:
            async with httpx.AsyncClient(timeout=5) as client:
                await client.post(
                    CI_ENDPOINT,
                    json=payload,
                    headers={"X-API-KEY": API_KEY}
                )
            return
        except Exception as e:
            logger.error(
                f"Failed to send prediction to CI backend (attempt {attempt + 1}/3): {e}"
            )
            if attempt < 2:
                await asyncio.sleep(0.5 * (attempt + 1))


@app.post("/predict")
async def predict(
    job_id: str = Form(...),
    file: UploadFile = File(...),
    source: str = Form(default="api"),
    x_api_key: str | None = Header(default=None)
):
    key = x_api_key
    if not key:
        return api_error(401, "API_KEY_REQUIRED", "An API key must be provided")

    key_record = await asyncio.to_thread(get_api_key_record, key)
    if not key_record:
        return api_error(401, "INVALID_API_KEY", "Invalid or inactive API key")

    job_id = sanitize_job_id(job_id)

    result = await asyncio.to_thread(
        check_rate_limit, key, key_record["rate_limit"], key_record["window_seconds"]
    )
    if isinstance(result, JSONResponse):
        return result

    start_time = time.time()

    try:
        validate_file(file)

        image_bytes = await file.read()
        if len(image_bytes) > MAX_FILE_SIZE:
            return api_error(413, "FILE_TOO_LARGE", f"File size exceeds {MAX_FILE_SIZE // (1024*1024)}MB limit")

        if not validate_image_content(image_bytes):
            return api_error(400, "INVALID_IMAGE", "Uploaded file is not a valid image")

        cache_key = _cache_key("simple", image_bytes)
        cached = result_cache.get(cache_key) if CACHE_ENABLED else None
        if cached is None:
            detections = await asyncio.to_thread(run_simple_inference, image_bytes)
            result_cache.set(cache_key, detections)
        else:
            detections = cached

        await asyncio.to_thread(increment_usage, key)

        inference_ms = int((time.time() - start_time) * 1000)

        await asyncio.to_thread(log_prediction, key, job_id, MODEL_NAME, len(detections), inference_ms)
        await asyncio.to_thread(record_prediction, key, key_record["window_seconds"])

        payload = {
            "job_id": job_id,
            "model": MODEL_NAME,
            "detections": detections,
            "count": len(detections),
            "inference_ms": inference_ms,
            "source": source
        }

        asyncio.ensure_future(send_to_ci(payload))

        return {
            "job_id": job_id,
            "status": "completed",
            "count": len(detections),
            "inference_ms": inference_ms,
            "detections": detections,
        }

    except HTTPException:
        raise
    except Exception as e:
        return JSONResponse(
            status_code=500,
            content={
                "job_id": job_id,
                "status": "failed",
                "error": str(e)
            }
        )