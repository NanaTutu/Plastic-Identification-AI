from ultralytics import YOLO
import yaml
import csv
import os
import re
import shutil
from datetime import datetime
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent.parent
DATA_YAML = str(BASE_DIR / "configs" / "data.yaml")
CONFIG_YAML = BASE_DIR / "configs" / "train.yaml"
EXPERIMENTS_DIR = BASE_DIR / "experiments"
MODELS_DIR = BASE_DIR / "models"

with open(CONFIG_YAML) as f:
    cfg = yaml.safe_load(f)

PRETRAINED_MODEL = str(BASE_DIR / "models" / cfg["model"])
EPOCHS = cfg["epochs"]
IMGSZ = cfg["imgsz"]
BATCH = cfg["batch"]
OPTIMIZER = cfg.get("optimizer", "Adam")
LR0 = cfg.get("lr0", 0.001)
PATIENCE = cfg.get("patience", 10)

MODEL_OUTPUT = os.getenv("MODEL_PATH", str(MODELS_DIR / "best_v8m.pt"))


def _expand_env(value: str) -> str:
    def repl(match):
        var = match.group(1)
        default = match.group(3) or ""
        return os.getenv(var, default)

    pattern = re.compile(r"\$\{([^}:]+)(?::-(.*?))?\}")
    return pattern.sub(repl, str(value))


with open(DATA_YAML) as f:
    data_cfg = yaml.safe_load(f)
data_cfg["path"] = _expand_env(data_cfg.get("path", ""))

run_name = f"{cfg.get('name', 'plasticid')}_{datetime.today().strftime('%Y%m%d_%H%M%S')}"

EXPERIMENTS_DIR.mkdir(parents=True, exist_ok=True)
metrics_file = EXPERIMENTS_DIR / "metrics.csv"

if not metrics_file.exists():
    with open(metrics_file, "w", newline="") as f:
        writer = csv.writer(f)
        writer.writerow([
            "run_id", "mAP50", "mAP50_95",
            "HDPE_AP50", "LDPE_AP50", "PVC_AP50",
            "PET_AP50", "PP_AP50", "PS_AP50",
            "inference_ms", "date"
        ])

model = YOLO(PRETRAINED_MODEL)

model.train(
    data=data_cfg,
    epochs=EPOCHS,
    imgsz=IMGSZ,
    batch=BATCH,
    optimizer=OPTIMIZER,
    lr0=LR0,
    patience=PATIENCE,
    project=str(EXPERIMENTS_DIR),
    name=run_name,
)

metrics = model.val()

hdpe = metrics.box.class_result(0)
ldpe = metrics.box.class_result(1)
pvc  = metrics.box.class_result(2)
pet  = metrics.box.class_result(3)
pp   = metrics.box.class_result(4)
ps   = metrics.box.class_result(5)

with open(metrics_file, "a", newline="") as f:
    writer = csv.writer(f)
    writer.writerow([
        run_name,
        round(metrics.box.map50, 4),
        round(metrics.box.map, 4),
        round(hdpe[2], 4),
        round(ldpe[2], 4),
        round(pvc[2], 4),
        round(pet[2], 4),
        round(pp[2], 4),
        round(ps[2], 4),
        round(metrics.speed.get("inference", 0), 2),
        datetime.today().strftime("%Y-%m-%d"),
    ])

experiment_best = EXPERIMENTS_DIR / run_name / "weights" / "best.pt"
if experiment_best.exists():
    shutil.copy(experiment_best, MODEL_OUTPUT)
    print(f"Copied best model to {MODEL_OUTPUT}")

print(f"Training completed successfully")
print(f"Run: {run_name}")
print(f"Metrics saved to: {metrics_file}")
print(f"Model saved in: {EXPERIMENTS_DIR / run_name}")
