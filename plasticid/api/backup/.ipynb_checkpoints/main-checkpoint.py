from fastapi import FastAPI, File, UploadFile
from ultralytics import YOLO
from PIL import Image
import io

app = FastAPI(title="Plastic Identification API")

# Load model once at startup
model = YOLO("models/best.pt")
# /home/tutu/pythondev/plasticid/experiments/plasticid_v1/weights

@app.get("/")
def health_check():
    return {"status": "ok", "model": "plasticid_v1"}

@app.post("/predict")
async def predict(file: UploadFile = File(...)):
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

    return {
        "detections": detections,
        "count": len(detections)
    }
