from ultralytics import YOLO
from pathlib import Path
import csv

PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
MODEL_PATH = str(PROJECT_ROOT / "experiments" / "plasticid_v1" / "weights" / "best.pt")
IMAGE_DIR = str(PROJECT_ROOT / "data" / "images" / "test")
OUTPUT_DIR = str(PROJECT_ROOT / "batch_inference_output")
CSV_PATH = f"{OUTPUT_DIR}/results.csv"

Path(OUTPUT_DIR).mkdir(parents=True, exist_ok=True)

model = YOLO(MODEL_PATH)

results = model.predict(
    source=IMAGE_DIR,
    device="cpu",
    conf=0.25,
    save=True,
    project=OUTPUT_DIR,
    name="images"
)

with open(CSV_PATH, "w", newline="") as f:
    writer = csv.writer(f)
    writer.writerow([
        "image",
        "class",
        "confidence",
        "x1", "y1", "x2", "y2"
    ])

    for r in results:
        image_name = Path(r.path).name

        if r.boxes is None:
            continue

        for box in r.boxes:
            cls_id = int(box.cls.item())
            conf = float(box.conf.item())
            x1, y1, x2, y2 = box.xyxy[0].tolist()

            writer.writerow([
                image_name,
                model.names[cls_id],
                round(conf, 4),
                round(x1, 1),
                round(y1, 1),
                round(x2, 1),
                round(y2, 1),
            ])

print(f"Batch inference complete")
print(f"Results saved to: {CSV_PATH}")
