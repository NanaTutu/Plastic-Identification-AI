from pydantic import BaseModel
from typing import List


class Detection(BaseModel):
    class_name: str
    confidence: float
    bbox: List[float]


class PredictionResponse(BaseModel):
    job_id: str
    model: str
    count: int
    detections: List[Detection]
    inference_ms: int