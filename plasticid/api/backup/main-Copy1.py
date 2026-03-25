from fastapi import FastAPI, File, UploadFile, Form
from ultralytics import YOLO
from PIL import Image
import io
import time

app = FastAPI(title="Plastic Identification API")

MODEL_NAME = "best.pt"

# Load model once
model = YOLO("models/best.pt")

# Warm-up (important for first-request latency)
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
        image_bytes = await file.read()
        image = Image.open(io.BytesIO(image_bytes)).convert("RGB")

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

        return {
            "job_id": job_id,
            "model": MODEL_NAME,
            "detections": detections,
            "count": len(detections),
            "status": "completed",
            "inference_ms": inference_ms
        }

    except Exception as e:
        return {
            "job_id": job_id,
            "status": "failed",
            "error": str(e)
        }
