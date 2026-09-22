# PlasticID - Plastic Classification System

AI-powered plastic waste classification using YOLOv8 to identify 6 types of plastic: HDPE, LDPE, PVC, PET, PP, and PS.

## Architecture

```
┌─────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   FastAPI   │────▶│   MySQL DB      │     │    Grafana      │
│  (Python)   │     │   (predictions) │     │   (monitoring)  │
└─────────────┘     └─────────────────┘     └─────────────────┘
       │
       ▼
┌─────────────────┐
│ CodeIgniter PHP │
│   (Backend)     │
└─────────────────┘
```

## Tech Stack

- **ML Model**: YOLOv8n (Ultralytics)
- **API**: FastAPI (Python 3.12)
- **Backend**: CodeIgniter 4 (PHP 8.1)
- **Database**: MySQL 8.0
- **Monitoring**: Prometheus + Grafana
- **Container**: Docker & Docker Compose

## Quick Start

### Prerequisites

- Docker & Docker Compose
- Python 3.12 (for local development)

### Setup

1. Clone the repository
2. Copy environment template:
   ```bash
   cp .env.example .env
   ```
3. Edit `.env` with your credentials
4. Start services:
   ```bash
   docker-compose up -d
   ```

### Services

| Service | URL | Description |
|---------|-----|-------------|
| FastAPI | http://localhost:8000 | ML inference API |
| PHP Backend | http://localhost:8080 | Web backend |
| Grafana | http://localhost:3000 | Metrics dashboard |
| Prometheus | http://localhost:9090 | Metrics collection |

## API Usage

### Predict Plastic Type

With API key (header-based):
```bash
curl -X POST "http://localhost:8000/v1/predict" \
  -H "X-API-KEY: pk_xxx" \
  -F "image=@plastic.jpg"
```

Legacy endpoint (also requires API key):
```bash
curl -X POST "http://localhost:8000/predict" \
  -H "X-API-KEY: pk_xxx" \
  -F "job_id=123" \
  -F "file=@plastic.jpg"
```

Create an API key (master key required):
```bash
curl -X POST "http://localhost:8000/v1/keys" \
  -H "X-API-KEY: <master_key>" \
  -H "Content-Type: application/json" \
  -d '{"owner": "my_app", "rate_limit": 100, "window_seconds": 3600}'
```

### Health Check

```bash
curl http://localhost:8000/
```

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `MYSQL_USER` | Database user | `tutu` |
| `MYSQL_PASSWORD` | Database password | - |
| `MYSQL_DATABASE` | Database name | `plasticid_db` |
| `API_KEY` | Backend/master API key | - |
| `PORTAL_API_KEY` | Playground demo key | - |
| `DATA_PATH` | Training data path | `/app/data/images` |
| `MODEL_PATH` | Model weights path | `/app/models/best.pt` |

### Model Classes

| ID | Plastic Type |
|----|--------------|
| 0 | HDPE |
| 1 | LDPE |
| 2 | PVC |
| 3 | PET |
| 4 | PP |
| 5 | PS |

## Training

Edit `plasticid/configs/train.yaml` and run:

```bash
python -m src.training.train
```

## Development

### Run locally (without Docker)

```bash
# Python
cd plasticid
pip install -r requirements.txt
uvicorn api.main:app --reload

# PHP
cd plasticid-backend
composer install
php spark serve
```

## Security

- API keys are required via the `X-API-KEY` header (never in query strings)
- API keys are stored as SHA-256 hashes in the database
- Rate limiting per API key (enforced on both endpoints), and rate limiting for master-key management endpoints
- File type validation (JPEG, PNG only)
- 10MB max file size
- Credentials stored in `.env` (never commit this file)

## Database

Tables are created automatically on FastAPI startup (`api_keys`, `prediction_logs`).
The CodeIgniter migration creates the reporting tables (`images`, `predictions`) — run it once:

```bash
cd plasticid-backend && php spark migrate
```

`created_at` columns default to `CURRENT_TIMESTAMP`, which also powers rate limiting.

## Project Structure

```
.
├── docker-compose.yml       # Container orchestration
├── .env                     # Environment variables (secret)
├── .env.example             # Environment template
├── .gitignore
├── README.md
├── yolov8n.pt              # Base model weights
├── plasticid/
│   ├── Dockerfile
│   ├── api/
│   │   ├── main.py          # FastAPI endpoints
│   │   └── schema.py
│   ├── configs/
│   │   ├── data.yaml        # Dataset config
│   │   └── train.yaml      # Training config
│   ├── src/
│   │   └── training/        # Training scripts
│   └── data/               # Dataset images
└── plasticid-backend/       # CodeIgniter PHP backend
```
