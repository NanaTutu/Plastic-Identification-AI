from ultralytics import YOLO
from pathlib import Path
import os

PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
MODEL_PATH = os.getenv("MODEL_PATH", str(PROJECT_ROOT / "models" / "best_v8m.pt"))
IMAGE_PATH = str(PROJECT_ROOT / "data" / "images" / "test" / "hdpe (16).png")
OUTPUT_DIR = str(PROJECT_ROOT / "inference_output")

Path(OUTPUT_DIR).mkdir(parents=True, exist_ok=True)

model = YOLO(MODEL_PATH)

results = model.predict(
    source=IMAGE_PATH,
    device="cpu",
    conf=0.25,
    save=True,
    project=OUTPUT_DIR,
    name="run1"
)

for r in results:
    if r.boxes is None:
        print("No objects detected.")
        continue

    for box in r.boxes:
        cls_id = int(box.cls.item())
        conf = float(box.conf.item())
        class_name = model.names[cls_id]

        print(f"Detected: {class_name} | Confidence: {conf:.2f}")

print("Inference complete")
