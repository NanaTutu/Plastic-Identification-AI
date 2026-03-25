from fastapi import FastAPI, File, UploadFile, Form, HTTPException, Header, Query, Request, Body, Response
from fastapi.responses import JSONResponse
from ultralytics import YOLO
from PIL import Image
import io
import time
import requests
import uuid
import os
from collections import defaultdict
import pymysql
import pymysql.cursors
import secrets
from api.schema import PredictionResponse
import logging
from prometheus_fastapi_instrumentator import Instrumentator

ALLOWED_TYPES = {"image/jpeg", "image/png", "image/jpg"}
MAX_FILE_SIZE = 10 * 1024 * 1024  # 10MB

app = FastAPI(title="Plastic Identification API",)
Instrumentator().instrument(app).expose(app)

DB_HOST = os.getenv("DB_HOST", "plasticid-db")
DB_USER = os.getenv("MYSQL_USER", "tutu")
DB_PASSWORD = os.getenv("MYSQL_PASSWORD", "password")
DB_NAME = os.getenv("MYSQL_DATABASE", "plasticid_db")
API_KEY = os.getenv("API_KEY", "change_me")


def get_db():
    return pymysql.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME,
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=True
    )

def get_api_key_record(api_key: str):
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                "SELECT * FROM api_keys WHERE api_key=%s AND is_active=1",
                (api_key,)
            )
            return cur.fetchone()
    finally:
        db.close()

def increment_usage(api_key: str):
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                "UPDATE api_keys SET total_requests = total_requests + 1 WHERE api_key=%s",
                (api_key,)
            )
    finally:
        db.close()

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

def generate_api_key():
    return "pk_" + secrets.token_hex(24)

def log_prediction(api_key, job_id, model, detections, inference_ms):

    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute("""
                INSERT INTO prediction_logs
                (job_id, api_key, model, detections, inference_ms)
                VALUES (%s,%s,%s,%s,%s)
            """, (job_id, api_key, model, detections, inference_ms))

        db.commit()
    finally:
        db.close()

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s | %(message)s",
)

logger = logging.getLogger("plasticid-api")

MODEL_NAME = "best.pt"
CI_ENDPOINT = "http://plasticid-backend/api/predictions"
# inet 192.168.1.52/24 brd 192.168.1.255 scope global dynamic noprefixroute wlp58s0
# net 172.20.10.6/28 brd 172.20.10.15 scope global dynamic noprefixroute wlp58s0

# Rate limit settings
# RATE_LIMIT = 10            # requests
# RATE_WINDOW = 60           # seconds

# API_KEY = "supersecretkey123"  # must match CI .env
# VALID_API_KEYS = {"demo-key-123"}  # In production, use a secure store

# Load model once
model = YOLO("models/best.pt")

# Warm-up
model.predict(source=Image.new("RGB", (640, 640)), device="cpu")

rate_limit_store = defaultdict(lambda: {
    "count": 0,
    "reset_at": 0
})  # type: ignore

def check_rate_limit(api_key: str, rate_limit: int, window_seconds: int):
    now = time.time()
    record = rate_limit_store[api_key]

    # first request or expired window
    if record["reset_at"] == 0 or now > record["reset_at"]:
        record["count"] = 0
        record["reset_at"] = now + window_seconds

    if record["count"] >= rate_limit:
        retry_after = int(record["reset_at"] - now)
        return api_error(
            status_code=429,
            code="rate_limit_exceeded",
            message=f"Rate limit exceeded. Retry in {retry_after}s",
            extra={"retry_after": retry_after}
        )
        # raise HTTPException(
        #     status_code=429,
        #     detail=f"Rate limit exceeded. Retry in {retry_after}s"
        # )

    record["count"] += 1

def validate_file(file: UploadFile):
    if file.content_type not in ALLOWED_TYPES:
        raise HTTPException(
            status_code=400,
            detail=f"Invalid file type. Allowed: {', '.join(ALLOWED_TYPES)}"
        )
    return True


@app.middleware("http")
async def log_requests(request: Request, call_next):
    start_time = time.time()
    api_key = request.query_params.get("api_key") #or request.headers.get("X-API-KEY") or "no-key"
    response = await call_next(request)
    process_time = (time.time() - start_time) * 1000
    logger.info(
        f"{request.method} {request.url.path} | "
        f"api_key={api_key} | "
        f"Status: {response.status_code} | "
        f"Time: {process_time:.2f}ms"
    )
    return response

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
        return {"db_status": "ok"}
    except Exception as e:
        return {"db_status": "error", "details": str(e)}

@app.post("/send-test")
def send_test():
    payload = {
        "job_id": "job-test-001",
        "model": "best.pt",
        "detections": [
            {
                "class": "HDPE",
                "confidence": 0.97,
                "bbox": [10, 20, 100, 200]
            }
        ],
        "count": 1,
        "inference_ms": 123,
        "source": "api"
    }

    try:
        r = requests.post(
            CI_ENDPOINT,
            json=payload,
            timeout=5
        )
        return {
            "sent": True,
            "ci_status": r.status_code,
            "ci_response": r.json()
        }

    except Exception as e:
        return {
            "sent": False,
            "error": str(e)
        }


# -------------------------
# API
# -------------------------

@app.get("/v1/stats")
def get_stats():

    db = get_db()
    try:
        with db.cursor() as cur:

            # total API keys
            cur.execute("SELECT COUNT(*) AS total FROM api_keys")
            total_keys = cur.fetchone()["total"]

            # active API keys
            cur.execute("SELECT COUNT(*) AS total FROM api_keys WHERE is_active=1")
            active_keys = cur.fetchone()["total"]

            # total requests
            cur.execute("SELECT SUM(total_requests) AS total FROM api_keys")
            result = cur.fetchone()
            total_requests = result["total"] or 0

            # top API users
            cur.execute("""
                SELECT api_key, total_requests
                FROM api_keys
                ORDER BY total_requests DESC
                LIMIT 5
            """)
            top_users = cur.fetchall()

        return {
            "total_api_keys": total_keys,
            "active_keys": active_keys,
            "total_requests": total_requests,
            "top_users": top_users
        }
    finally:
        db.close()

@app.post("/v1/keys")
def create_api_key(
    owner: str = Body(...),
    rate_limit: int = Body(default=10),
    window_seconds: int = Body(default=60)
):
    api_key = generate_api_key()
    db = get_db()
    with db.cursor() as cur:
        cur.execute("""
            INSERT INTO api_keys (api_key, owner, rate_limit, window_seconds)
            VALUES (%s, %s, %s, %s)
        """, (api_key, owner, rate_limit, window_seconds)
    )
    return {
        "api_key": api_key,
        "owner": owner,
        "rate_limit": rate_limit,
        "window_seconds": window_seconds
    }

@app.get("/v1/list_keys")
def list_keys():
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute("SELECT id, api_key, owner, is_active, rate_limit, window_seconds, total_requests FROM api_keys")
            keys = cur.fetchall()
        return {"api_keys": keys}
    finally:
        db.close()

@app.delete("/v1/keys/{key}")
def deactivate_key(key: str):
    db = get_db()
    with db.cursor() as cur:
        cur.execute("UPDATE api_keys SET is_active=0 WHERE api_key=%s", (key,))
        if cur.rowcount == 0:
            return api_error(404, "KEY_NOT_FOUND", "API key not found")
    return {"status": "deactivated", "api_key": key}

@app.get("/v1/usage")
def usage(
    api_key: str | None = Query(default=None),
    x_api_key: str | None = Header(default=None)
):
    key = x_api_key or api_key
    if not key:
        return api_error(401, "API_KEY_REQUIRED", "An API key must be provided")

    record = get_api_key_record(key)
    if not record:
        return api_error(401, "INVALID_API_KEY", "Invalid or inactive API key")

    return {
        "api_key": record["api_key"],
        "is_active": bool(record["is_active"]),
        "rate_limit": record["rate_limit"],
        "window_seconds": record["window_seconds"],
        "total_requests": record["total_requests"]
    }

@app.post("/v1/predict", response_model=PredictionResponse)
async def predict_v1(
    response: Response,
    image: UploadFile = File(...),
    api_key: str | None = Query(default=None),
    x_api_key: str | None = Header(default=None)
):

    key = x_api_key or api_key
    job_id = str(uuid.uuid4())

    if not key:
        return api_error(401, "API_KEY_REQUIRED", "An API key must be provided")

    key_record = get_api_key_record(key)
    if not key_record:
        return api_error(401, "INVALID_API_KEY", "Invalid or inactive API key")

    rate_error = check_rate_limit(
        key,
        key_record["rate_limit"],
        key_record["window_seconds"]
    )
    if rate_error:
        return rate_error

    record = rate_limit_store[key]

    response.headers["X-RateLimit-Limit"] = str(key_record["rate_limit"])
    response.headers["X-RateLimit-Remaining"] = str(
        max(0, key_record["rate_limit"] - record["count"])
    )
    response.headers["X-RateLimit-Reset"] = str(int(record["reset_at"]))

    start = time.time()

    validate_file(image)
    
    img_bytes = await image.read()
    if len(img_bytes) > MAX_FILE_SIZE:
        return api_error(413, "FILE_TOO_LARGE", f"File size exceeds {MAX_FILE_SIZE // (1024*1024)}MB limit")
    
    img = Image.open(io.BytesIO(img_bytes)).convert("RGB")

    results = model.predict(img, conf=0.25, device="cpu")

    detections = []

    for r in results:
        if r.boxes:
            for b in r.boxes:
                cls = int(b.cls.item())
                detections.append({
                    "class_name": model.names[cls],
                    "confidence": round(float(b.conf.item()), 4),
                    "bbox": [round(v, 1) for v in b.xyxy[0].tolist()]
                })

    increment_usage(key)

    inference_time = int((time.time() - start) * 1000)

    log_prediction(
        key,
        job_id,
        "plasticid_v1",
        len(detections),
        inference_time
    )

    return {
        "job_id": job_id,
        "model": "plasticid_v1",
        "count": len(detections),
        "detections": detections,
        "inference_ms": int((time.time() - start) * 1000)
    }

@app.get("/v1/predictions")
def get_predictions(
    api_key: str,
    limit: int = 20
):

    key_record = get_api_key_record(api_key)

    if not key_record:
        return api_error(
            401,
            "INVALID_API_KEY",
            "Invalid or inactive API key"
        )

    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute("""
                SELECT
                    job_id,
                    model,
                    detections,
                    inference_ms,
                    created_at
                FROM prediction_logs
                WHERE api_key=%s
                ORDER BY created_at DESC
                LIMIT %s
            """, (api_key, limit))

            rows = cur.fetchall()

        return {
            "api_key": api_key,
            "results": rows
        }
    finally:
        db.close()


@app.post("/predict")
async def predict(
    job_id: str = Form(...),
    file: UploadFile = File(...),
    source: str = Form(default="api")
):
    start_time = time.time()

    try:
        validate_file(file)
        
        image_bytes = await file.read()
        if len(image_bytes) > MAX_FILE_SIZE:
            return {
                "job_id": job_id,
                "status": "failed",
                "error": f"File size exceeds {MAX_FILE_SIZE // (1024*1024)}MB limit"
            }
        
        image = Image.open(io.BytesIO(image_bytes)).convert("RGB")

        # ============================
        # Run inference
        # ============================
        results = model.predict(
            source=image,
            conf=0.25,
            device="cpu"
        )

        detections = []

        for r in results:
            if r.boxes is None:
                continue

            for box in r.boxes:
                cls_id = int(box.cls.item())

                detections.append({
                    "class": model.names[cls_id],
                    "confidence": round(float(box.conf.item()), 4),
                    "bbox": [
                        round(v, 1) for v in box.xyxy[0].tolist()
                    ]
                })

        inference_ms = int((time.time() - start_time) * 1000)

        # ============================
        # Build payload for CI
        # ============================
        payload = {
            "job_id": job_id,
            "model": MODEL_NAME,
            "detections": detections,
            "count": len(detections),
            "inference_ms": inference_ms,
            "source": source
        }

        # ============================
        # Send to CodeIgniter (async-safe)
        # ============================
        try:
            requests.post(
                CI_ENDPOINT,
                json=payload,
                headers={"X-API-KEY": API_KEY},
                timeout=5
            )
        except Exception as e:
            # CI failure should NOT break inference
            print("⚠ Failed to send to CI:", e)

        # ============================
        # API response
        # ============================
        return {
            "job_id": job_id,
            "status": "completed",
            "count": len(detections),
            "inference_ms": inference_ms,
            "detections": detections
        }

    except Exception as e:
        return {
            "job_id": job_id,
            "status": "failed",
            "error": str(e)
        }

