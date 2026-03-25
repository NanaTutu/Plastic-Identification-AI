from fastapi import FastAPI, File, UploadFile, Form
from ultralytics import YOLO
from PIL import Image
import io
import time
import requests

app = FastAPI(title="Plastic Identification API")

MODEL_NAME = "best.pt"

# Load model once
model = YOLO("models/best.pt")

# Warm-up (reduces first-request latency)
model.predict(source=Image.new("RGB", (640, 640)), device="cpu")


@app.get("/")
def health_check():
    return {
        "status": "ok",
        "model": MODEL_NAME
    }


@app.post("/predict")
async def predict(
    job_id: str = Form(...),
    file: UploadFile = File(...),
    source: str = Form(default="api")
):
    start_time = time.time()

    try:
        # Read image
        image_bytes = await file.read()
        image = Image.open(io.BytesIO(image_bytes)).convert("RGB")

        # Run inference
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
                    "bbox": [round(v, 1) for v in box.xyxy[0].tolist()]
                })

        inference_ms = int((time.time() - start_time) * 1000)

        # ==============================
        # SEND RESULT TO CODEIGNITER
        # ==============================
        payload = {
            "job_id": job_id,
            "source": source,
            "model": MODEL_NAME,
            "detections": detections,
            "count": len(detections),
            "inference_ms": inference_ms
        }

        try:
            response = requests.post(
                "http://host.docker.internal:8080/api/predictions",
                json=payload,
                timeout=5
            )

            if response.status_code != 200:
                print("CI error:", response.status_code, response.text)

        except Exception as e:
            print("Failed to store prediction:", e)

        # ==============================
        # API RESPONSE
        # ==============================
        return {
            "job_id": job_id,
            "model": MODEL_NAME,
            "detections": detections,
            "count": len(detections),
            "status": "completed",
            "inference_ms": inference_ms
            "payload": payload
        }

    except Exception as e:
        return {
            "job_id": job_id,
            "status": "failed",
            "error": str(e)
        }
