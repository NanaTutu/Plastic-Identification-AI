from ultralytics import YOLO
from pathlib import Path

# -------------------------
# Paths
# -------------------------
MODEL_PATH = "/home/tutu/Developement/plasticid/experiments/plasticid_v1/weights/best.pt"
IMAGE_PATH = "/home/tutu/Developement/plasticid/data/images/test/hdpe (16).png"   # change this
OUTPUT_DIR = "/home/tutu/Developement/plasticid/inference_output"

Path(OUTPUT_DIR).mkdir(parents=True, exist_ok=True)

# -------------------------
# Load model (CPU)
# -------------------------
model = YOLO(MODEL_PATH)

# -------------------------
# Run inference
# -------------------------
results = model.predict(
    source=IMAGE_PATH,
    device="cpu",
    conf=0.25,
    save=True,
    project=OUTPUT_DIR,
    name="run1"
)

# -------------------------
# Print detections
# -------------------------
for r in results:
    if r.boxes is None:
        print("No objects detected.")
        continue

    for box in r.boxes:
        cls_id = int(box.cls.item())
        conf = float(box.conf.item())
        class_name = model.names[cls_id]

        print(f"Detected: {class_name} | Confidence: {conf:.2f}")

print("✅ Inference complete")
