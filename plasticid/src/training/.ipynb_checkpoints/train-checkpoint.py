# src/training/train.py
from ultralytics import YOLO

# Paths
train_yaml = "../../configs/train.yaml"
data_yaml  = "../../configs/data.yaml"

# Load model and train
model = YOLO(train_yaml)
model.train(data=data_yaml)

# Evaluate and save metrics
metrics = model.val()

# Save metrics
import csv
from datetime import datetime
metrics_file = "../../experiments/metrics.csv"

with open(metrics_file, "a", newline="") as f:
    writer = csv.writer(f)
    writer.writerow([
        "run_001",
        metrics.box.map50,
        metrics.box.map50_95,
        metrics.box.prc[0].f1,
        metrics.box.prc[1].f1,
        metrics.box.prc[2].f1,
        metrics.box.prc[3].f1,
        metrics.box.prc[4].f1,
        metrics.box.prc[5].f1,
        0,  # inference time placeholder
        datetime.today().strftime("%Y-%m-%d")
    ])

