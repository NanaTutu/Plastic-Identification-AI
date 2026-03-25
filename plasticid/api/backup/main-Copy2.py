from fastapi import FastAPI, File, UploadFile, Form
from ultralytics import YOLO
from PIL import Image
import io
import time
import requests   # ✅ FIXED

app = FastAPI(title="Plastic Identification API")

MODEL_NAME = "best.pt"

# Load model once
model = YOLO("models/best.pt")

# Warm-up
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

        # ==============================
        # ✅ SEND RESULT TO CODEIGNITER
        # ==============================
        payload = {
            "job_id": job_id,
            "model": MODEL_NAME,
            "detections": detections,
            "count": len(detections),
            "inference_ms": inference_ms,
            "source": source
        }

        try:
            requestsforeach ($payload['detections'] as $d) {
            $predictionModel->insert([
                'image_id'  => $imageId,
                'label'     => $d['class'],
                'confidence'=> $d['confidence'],
                'x1' => $d['bbox'][0],
                'y1' => $d['bbox'][1],
                'x2' => $d['bbox'][2],
                'y2' => $d['bbox'][3],
            ]);
        }.post(
                "http://host.docker.internal:8080/api/predictions",
                json=payload,
                # headers={"X-API-KEY": "supersecretkey123"},
                timeout=5
            )
        except Exception as e:
            print("Failed to store prediction:", e)

        # ==============================
        # ✅ API RESPONSE
        # ==============================
        # return {
        #     "job_id": job_id,
        #     "model": MODEL_NAME,
        #     "detections": detections,
        #     "count": len(detections),
        #     "status": "completed",
        #     "inference_ms": inference_ms
        # }

    except Exception as e:
        return {
            "job_id": job_id,
            "status": "failed",
            "error": str(e)
        }
