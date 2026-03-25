# src/training/train.py

from ultralytics import YOLO
import csv
from datetime import datetime
from pathlib import Path

# ======================================================
# CONFIGURATION
# ======================================================
DATA_YAML = "/home/tutu/Developement/plasticid/configs/data.yaml"
EXPERIMENTS_DIR = "/home/tutu/Developement/plasticid/experiments"
MODEL_NAME = "plasticid_v1"
PRETRAINED_MODEL = "yolov8n.pt"

EPOCHS = 1        # CPU dry run (increase later)
IMGSZ = 416       # CPU-friendly image size
BATCH = 1         # CPU-safe batch size

# ======================================================
# SETUP
# ======================================================
Path(EXPERIMENTS_DIR).mkdir(parents=True, exist_ok=True)
metrics_file = Path(EXPERIMENTS_DIR) / "metrics.csv"

# Create metrics CSV if missing
if not metrics_file.exists():
    with open(metrics_file, "w", newline="") as f:
        writer = csv.writer(f)
        writer.writerow([
            "run_id",
            "mAP50",
            "mAP50_95",
            "HDPE_AP50",
            "LDPE_AP50",
            "PVC_AP50",
            "PET_AP50",
            "PP_AP50",
            "PS_AP50",
            "inference_ms",
            "date"
        ])

# ======================================================
# TRAINING
# ======================================================
model = YOLO(PRETRAINED_MODEL)

model.train(
    data=DATA_YAML,
    epochs=EPOCHS,
    imgsz=IMGSZ,
    batch=BATCH,
    project=EXPERIMENTS_DIR,
    name=MODEL_NAME
)

# ======================================================
# VALIDATION
# ======================================================
metrics = model.val()

# Per-class metrics
hdpe = metrics.box.class_result(0)  # (p, r, ap50, ap)
ldpe = metrics.box.class_result(1)
pvc  = metrics.box.class_result(2)
pet  = metrics.box.class_result(3)
pp   = metrics.box.class_result(4)
ps   = metrics.box.class_result(5)

# ======================================================
# METRICS LOGGING
# ======================================================
with open(metrics_file, "a", newline="") as f:
    writer = csv.writer(f)
    writer.writerow([
        MODEL_NAME,
        metrics.box.map50,   # Overall mAP@0.5
        metrics.box.map,     # Overall mAP@0.5:0.95
        hdpe[2],             # HDPE AP@0.5
        ldpe[2],             # LDPE AP@0.5
        pvc[2],              # PVC AP@0.5
        pet[2],              # PET AP@0.5
        pp[2],               # PP AP@0.5
        ps[2],               # PS AP@0.5
        metrics.speed.get("inference", 0),
        datetime.today().strftime("%Y-%m-%d")
    ])

print("✅ Training completed successfully")
print(f"📊 Metrics saved to: {metrics_file}")
print(f"📦 Model saved in: {EXPERIMENTS_DIR}/{MODEL_NAME}")
