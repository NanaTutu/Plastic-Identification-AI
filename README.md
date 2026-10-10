# PlasticID - Plastic Classification System

AI-powered plastic waste classification using a two-stage YOLOv8 pipeline: a COCO
detector (`yolov8n`) finds the object of interest, then a classifier (`best_v8m`,
YOLOv8m) predicts one of 6 plastic types: HDPE, LDPE, PVC, PET, PP, and PS.

## Architecture

```
  browser
    │  portal pages, playground, dashboards
    ▼
  CodeIgniter 4 (:8080) ──── proxy (master / portal key) ────▶ FastAPI (:8000)
    │                                                             │  │
    │◀──── outbox ingest: POST /api/predictions ────────────────│  │
    │                                                             │  │
    └────────────────▶ MySQL 8.0 ◀───────────────────────────────│  │
                       (one shared database)                      │  │
                                                                  │  ▼
                                                                Redis 7
                                                              (rate limits)

  FastAPI ── GET /metrics ──▶ Prometheus (:9090) ── datasource ──▶ Grafana (:3000)
```

- **CodeIgniter migrations are the single schema authority**: one production
  migration creates all six application tables — foreign keys, CHECKs,
  table-level grants and retention events included — on every backend start
  (`php spark migrate --force`) using a dedicated `plasticid_migrate` user.
- **FastAPI** owns inference and the API-key/rate-limit tables (`api_keys`,
  `prediction_logs`, `prediction_outbox`) at the application level, then
  reliably delivers each prediction to the PHP backend through a background
  outbox worker. Its `api/database.py` is verify-only: it checks the schema
  contract at startup and refuses to start on mismatch, but never issues DDL.
- **CodeIgniter** owns reporting/portal tables (`images`, `predictions`,
  `api_key_requests`) and serves the public portal, playground and dashboards.
- Both apps share one MySQL database, each with its own least-privilege
  account; Redis backs per-key rate limiting.

## Tech Stack

- **ML Model**: YOLOv8n detector (Ultralytics) + YOLOv8m classifier (`best_v8m`)
- **API**: FastAPI (Python 3.12)
- **Backend**: CodeIgniter 4.6 (PHP 8.3 in Docker/CI; `composer.json` requires `^8.1`)
- **Database**: MySQL 8.0
- **Rate limiting**: Redis 7
- **Monitoring**: Prometheus + Grafana
- **Container**: Docker & Docker Compose

## Quick Start

### Prerequisites

- Docker & Docker Compose
- Python 3.12 (for local development)
- An existing classifier checkpoint at `plasticid/models/best_v8m.pt`
  (git- and docker-ignored: the image gets it from the `./plasticid/models` bind mount)

### Setup

1. Clone the repository
2. Copy environment template:
   ```bash
   cp .env.example .env
   ```
3. Fill in `.env` (start from `.env.example`): generate unique values for
   `MYSQL_ROOT_PASSWORD`, `API_DB_PASSWORD`, `CI_DB_PASSWORD`,
   `MIGRATE_DB_PASSWORD`, `REDIS_PASSWORD`, `API_KEY` and `PORTAL_API_KEY`,
   and place the existing classifier checkpoint at
   `plasticid/models/best_v8m.pt`.
4. Start services:
   ```bash
   docker compose up -d --build
   ```

On the first boot of an **empty** MySQL data directory the container runs
`mysql/bootstrap.sh`, which creates the three least-privilege database users
and drops legacy accounts; on an existing volume the same statements must be
applied manually (see “Database users”). The API readiness endpoint is
`http://localhost:8000/health/ready` and returns `503` until MySQL, Redis,
the schema and the model are ready. The backend runs its migrations on every
container start (before Apache), and Composer dependencies are installed at
image build time.

### Services

| Compose service | URL | Description |
|-----------------|-----|-------------|
| `fastapi` | http://localhost:8000 | ML inference API |
| `ci` (container `plasticid-backend`) | http://localhost:8080 | Portal, dashboards, ingest API |
| `grafana` | http://127.0.0.1:3000 | Metrics dashboard (`PlasticID Overview`) |
| `prometheus` | http://127.0.0.1:9090 | Metrics collection (scrapes `fastapi:/metrics`) |
| `plasticid-db` | internal only | MySQL 8.0 |
| `redis` | internal only | Redis 7 (rate limiting) |

Prometheus and Grafana are bound to localhost only; MySQL and Redis are not
published to the host at all.

## API Usage

All API-key operations use the `X-API-KEY` header (never query strings).
`<master_key>` is the `API_KEY` value from `.env`.

### Predict plastic type

```bash
curl -X POST "http://localhost:8000/v1/predict" \
  -H "X-API-KEY: pk_xxx" \
  -F "image=@plastic.jpg"
```

Response: `{job_id, model, count, detections, inference_ms, detected_object}` plus
`X-RateLimit-Limit/Remaining/Reset` headers. Uploads are limited to 10 MB JPEG/PNG.

### Create an API key (master key required)

```bash
curl -X POST "http://localhost:8000/v1/keys" \
  -H "X-API-KEY: <master_key>" \
  -H "Content-Type: application/json" \
  -d '{"owner": "my_app", "rate_limit": 100, "window_seconds": 3600}'
```

`rate_limit` defaults to 10, `window_seconds` to 60; the owner name `portal` is reserved.

### FastAPI endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/` | — | Liveness; reports `model_loaded` and model name |
| GET | `/health/db` | — | DB health + schema diagnostics |
| GET | `/health/ready` | — | Readiness: database, schema, model, rate limiter |
| GET | `/metrics` | — | Prometheus metrics |
| GET | `/docs`, `/redoc`, `/openapi.json` | — | Interactive API docs |
| POST | `/v1/predict` | any valid key | Classify an uploaded image |
| GET | `/v1/predictions?limit=` | any valid key | Prediction history for the key (`limit` 1–100, default 20) |
| GET | `/v1/usage` | any valid key | Quota/usage for the presented key |
| POST | `/v1/keys` | master key | Create a key |
| GET | `/v1/list_keys` | master key | List keys (masked) |
| DELETE | `/v1/keys/{key}` | master key | Deactivate a key |
| GET | `/v1/stats` | master key | Platform stats (10 req/60 s per IP) |

### Backend (CodeIgniter) endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/` | — | Stock welcome page (used as container healthcheck) |
| POST | `/api/predictions` | master key | Prediction ingest from the FastAPI outbox worker |
| GET | `/api/get_keys` | master key | Proxies FastAPI `/v1/list_keys` (JSON) |
| GET | `/dashboard/api-keys`, `/dashboard/api-stats` | master key (`X-API-KEY`) | HTML dashboards proxying FastAPI |
| GET | `/portal`, `/portal/methodology`, `/portal/docs`, `/portal/playground`, `/portal/request` | — | Public portal pages |
| POST | `/portal/request` | CSRF | Request an API key (5 requests/hour/IP; new keys get 100 req/h) |
| POST | `/portal/predict` | CSRF | Playground image upload, proxied to FastAPI |

### Health check

```bash
curl http://localhost:8000/
```

## Configuration

`.env.example` is the canonical template. Variables marked *required* fail
`docker compose up` if unset.

### Environment variables

| Variable | Description | Default |
|----------|-------------|---------|
| `MYSQL_ROOT_PASSWORD` | MySQL root password (root@localhost only) | *required* |
| `MYSQL_DATABASE` | Database name | `plasticid_db` |
| `API_DB_PASSWORD` | Password for `plasticid_api` (FastAPI) | *required* |
| `CI_DB_PASSWORD` | Password for `plasticid_ci` (backend) | *required* |
| `MIGRATE_DB_PASSWORD` | Password for `plasticid_migrate` (migrations only) | *required* |
| `REDIS_PASSWORD` | Redis AUTH password (embedded in `REDIS_URL`) | *required* |
| `API_KEY` | Superuser master key; env-only (never stored in DB), required for admin endpoints | *required* |
| `PORTAL_API_KEY` | Portal playground demo key, auto-registered at API startup (owner `portal`, 100 req/h) | empty |
| `CI_API_KEY` | Key FastAPI uses to call the backend | falls back to `API_KEY` |
| `CI_ENDPOINT` | Backend ingest URL | `http://ci/api/predictions` |
| `GRAFANA_ADMIN_USER` | Grafana admin user | `admin` |
| `GRAFANA_ADMIN_PASSWORD` | Grafana admin password | *required* |
| `MODEL_PATH` | Classifier weights | `/app/models/best_v8m.pt` |
| `DETECTOR_PATH` | Detector weights | `/app/yolov8n.pt` |
| `DATA_PATH` | Training data path (training only) | `/app/data/images` |
| `RATE_LIMIT_REDIS_REQUIRED` | Fail closed when Redis is unavailable | `1` (code default `0`) |
| `REDIS_URL` | Redis address (compose builds it from `REDIS_PASSWORD`) | `redis://:REDIS_PASSWORD@redis:6379/0` |
| `DB_STARTUP_REQUIRED` / `DB_STARTUP_ATTEMPTS` / `DB_STARTUP_DELAY` | Schema verification retries at API startup | `1` / `10` / `2` |
| `DB_MAX_CONNECTIONS` / `DB_CONNECT_TIMEOUT` | MySQL pool tuning | `10` / `5` |
| `OUTBOX_WORKER_ENABLED` / `OUTBOX_POLL_INTERVAL` / `OUTBOX_BATCH_SIZE` | Background delivery worker | `1` / `5` / `10` |
| `OUTBOX_MAX_ATTEMPTS` / `OUTBOX_CLAIM_TIMEOUT_SECONDS` | Outbox retry policy | `12` / `300` |
| `INFERENCE_LOCK_TIMEOUT` | Seconds to wait for an inference slot before shedding load (`503 INFERENCE_BUSY`) | `30` |
| `READ_RATE_LIMIT` / `READ_RATE_WINDOW` | Quota for read endpoints (`/v1/usage`, `/v1/predictions`) | `120` / `60` |
| `RATE_LIMIT_MEMORY_MAX_KEYS` | Max keys kept in the in-process limiter fallback | `10000` |
| `APP_BASE_URL` | Public portal URL | `http://localhost:8080/` |
| `APP_ALLOWED_HOSTNAMES` | Extra allowed hostnames | empty |
| `CORS_ALLOWED_ORIGINS` | Comma-separated portal origins | `http://localhost:8080` |
| `FASTAPI_BASE_URL` | Internal API URL used by the backend | `http://plasticid-fastapi:8000` |
| `FASTAPI_PUBLIC_URL` | Public API URL used by portal links | `http://localhost:8000` |
| `PORTAL_RATE_LIMIT` / `PORTAL_RATE_WINDOW` | API-side quota for the portal key | `100` / `60` |
| `PORTAL_REQUESTS_PER_MINUTE` | Playground throttle per session (forwarded to the backend) | `20` |
| `MAX_IMAGE_EDGE` / `MAX_IMAGE_PIXELS` | Upload dimension limits | `1280` / `40000000` |
| `CACHE_MAX_IMAGE_SIZE` | Max upload size eligible for result caching | `2097152` |
| `CI_ENVIRONMENT` / `DB_DEBUG` | CodeIgniter runtime | `production` / `0` |

Local-only toggles used outside Docker (read by the Python code, not forwarded by
compose): `ONNX_INFERENCE` (export/run ONNX), `NORMALIZE_WB` (auto white balance
in preprocessing), `CACHE_ENABLED`, `CACHE_TTL`, `CACHE_MAX_ITEMS`.

### Model classes

| ID | Plastic Type |
|----|--------------|
| 0 | HDPE |
| 1 | LDPE |
| 2 | PVC |
| 3 | PET |
| 4 | PP |
| 5 | PS |

## Training

Edit `plasticid/configs/train.yaml` and run from the `plasticid/` directory:

```bash
cd plasticid
python -m src.training.train
```

Training reads `configs/data.yaml` (resolved against `DATA_PATH`), appends metrics
to `experiments/metrics.csv`, saves runs under `experiments/<name>/`, and copies the
best weights to `models/best_v8m.pt`. Note: `src/evaluation/` and `src/inference/`
are currently empty placeholders, and `train.py` has no `__main__` guard — importing
it starts a training run.

## Development

### Run locally (without Docker)

```bash
# Python (API_KEY and API_DB_PASSWORD are required; DB is optional with
# DB_STARTUP_REQUIRED=0 RATE_LIMIT_REDIS_REQUIRED=0)
cd plasticid
pip install -r requirements-dev.txt
uvicorn api.main:app --reload

# PHP
cd plasticid-backend
composer install
php spark migrate
php spark serve
```

## Validation

No host Python/PHP toolchain is needed — the test suites run inside the images:

```bash
# 26 pytest tests (mocked DB; installs pytest on the fly)
docker exec plasticid-fastapi sh -c 'pip install -q pytest && python -m pytest tests/'

# 10 PHPUnit tests (one-off build with dev dependencies, SQLite in-memory)
docker build --build-arg COMPOSER_FLAGS= -t plasticid-backend:test ./plasticid-backend
docker run --rm plasticid-backend:test vendor/bin/phpunit

# compose file sanity
docker compose config --quiet
```

CI (`.github/workflows/ci.yml`) runs the same three checks on every push/PR:
Python 3.12 (`compileall` + pytest), PHP 8.3 (`composer validate --strict`,
`composer install`, `composer test`), and compose config validation.
There are no lint/format jobs.

## Security

- API keys are required via the `X-API-KEY` header (never in query strings;
  the request logger redacts `api_key` if present)
- API keys are stored as SHA-256 hashes in the database and are masked in API responses;
  the master key exists only in the environment, never in the database
- Rate limiting is atomic per API key and uses hashed Redis keys; production fails
  closed if Redis is unavailable (`RATE_LIMIT_REDIS_REQUIRED=1`); Redis itself
  requires AUTH (`REDIS_PASSWORD`, wired into `REDIS_URL` and the healthcheck)
- File type and decoded image format validation (JPEG, PNG only), 10 MB cap
- Bounded upload reads and image pixel/edge limits
- Portal POSTs are CSRF-protected (cookie-based); key requests are throttled to
  5 per hour per IP and playground predictions to `PORTAL_REQUESTS_PER_MINUTE` per session
- Backend ingest, key, and dashboard routes require the master key
- Each service connects to MySQL with its own least-privilege account
  (`plasticid_api` / `plasticid_ci`); only the migration runner holds DDL
  privileges, and `root@'%'` / `tutu` have been removed
- Retention events purge prediction logs, outbox rows and key requests
  automatically (data minimization, see “Retention”)
- FastAPI runs as a non-root user (`appuser`, uid 10001); Apache sets
  `ServerTokens Prod`, `LimitRequestBody`, and `TraceEnable Off`
- Credentials are stored in `.env` (never commit this file)

## Operations

| Service | Healthcheck | Interval / start period |
|---------|-------------|-------------------------|
| `fastapi` | `GET /health/ready` | 30 s / 90 s |
| `ci` | `GET /` | 15 s / 30 s |
| `plasticid-db` | `mysqladmin ping` | 10 s / 30 s |
| `redis` | `redis-cli ping` (with `REDISCLI_AUTH`) | 10 s / 10 s |

Docker Compose waits for MySQL, Redis, and the backend to be healthy before
starting FastAPI; Prometheus waits for FastAPI. Liveness is `GET /`, database
health `GET /health/db`, dependency-aware readiness `GET /health/ready`.

Monitoring: Prometheus scrapes only the FastAPI `/metrics` endpoint (15 s). Grafana
provisions one Prometheus datasource and one dashboard, **PlasticID Overview**
(request rate and p95 latency). MySQL, Redis, and PHP are not scraped.

## Database

The CodeIgniter migration layer is the **single source of truth for the schema**.
The production migration
`plasticid-backend/app/Database/Migrations/2026-10-06-000001_ProductionSchema.php`
runs on every backend start (`php spark migrate --force`, before Apache) and:

- drops all legacy tables from earlier iterations (`jobs`,
  `schema_migrations`, the original `images`/`predictions`, …) so every
  environment starts from a known state,
- creates the six application tables with real foreign keys, CHECK
  constraints, composite/unique indexes and `utf8mb4_0900_ai_ci` collation,
- issues the table-level DML grants for the runtime users (MySQL rejects
  table-level grants on nonexistent tables, so this happens after CREATE),
- creates the three retention events (below).

FastAPI never issues DDL. `plasticid/api/database.py` only *verifies* the
schema at startup: required tables, columns, indexes and foreign keys, plus
the migration bookkeeping row (`SELECT version FROM migrations WHERE version
= SCHEMA_CONTRACT_VERSION`). Any mismatch raises and the API refuses to start.
`GET /health/db` exposes the same diagnostics; `GET /health/ready` gates
readiness on them.

The migration runs through a dedicated database group:
`app/Config/Services.php` returns `db_connect('migrations')` for
`service('migrations')`, and `app/Config/Database.php` defines that group from
`MIGRATE_DB_USER`/`MIGRATE_DB_PASSWORD`. Bookkeeping and DDL therefore run as
`plasticid_migrate`, while the Apache runtime uses `plasticid_ci` (documented
in the file header of `mysql/bootstrap.sh`).

### Tables

| Table | Owner (app) | Purpose |
|-------|-------------|---------|
| `api_keys` | FastAPI | SHA-256-hashed keys, quotas, status |
| `prediction_logs` | FastAPI | One row per prediction request |
| `prediction_outbox` | FastAPI | Pending / delivered deliveries to the backend |
| `images` | CodeIgniter | Ingested image metadata (sha256, source) |
| `predictions` | CodeIgniter | Model results, linked to `images` |
| `api_key_requests` | CodeIgniter | Portal key-request audit trail |
| `migrations` | CodeIgniter | CI4 migration bookkeeping |

Foreign keys: `images.api_key_id`, `prediction_logs.api_key_id`,
`prediction_outbox.api_key_id` and `api_key_requests.api_key_id` →
`api_keys(id)` (`ON DELETE SET NULL`); `predictions.image_id` →
`images(id)` (`ON DELETE CASCADE`).

### Database users

Usernames are fixed (compose + `mysql/bootstrap.sh`); only passwords live in
`.env`.

| User | Grants | Used by |
|------|--------|---------|
| `plasticid_api` | SELECT on the schema; INSERT/UPDATE/DELETE on `api_keys`, `prediction_logs`, `prediction_outbox` (table-level, granted by the migration) | FastAPI |
| `plasticid_ci` | SELECT on the schema; SELECT/INSERT/UPDATE on `images`, `predictions`, `api_key_requests` | Apache / CodeIgniter |
| `plasticid_migrate` | ALL PRIVILEGES + GRANT OPTION (migration only) | `php spark migrate` |
| `root` | localhost only (`root@'%'` and legacy `tutu` are dropped) | manual admin |

`mysql/bootstrap.sh` (mounted at `/docker-entrypoint-initdb.d/01-bootstrap.sh`)
creates this state on the **first boot of an empty data directory**. On an
existing volume it never runs again — apply the same statements manually as
root (they are reproduced in the script header / “Database users” table).

### Retention

The db container runs with `--event-scheduler=ON`. The migration creates
three events (definer `plasticid_migrate`, all purge with `LIMIT 5000`):

| Event | Schedule | Deletes |
|-------|----------|---------|
| `ev_purge_prediction_logs` | hourly | `prediction_logs` older than 90 days |
| `ev_purge_prediction_outbox` | hourly | delivered / dead-letter `prediction_outbox` older than 7 days |
| `ev_purge_api_key_requests` | daily | `api_key_requests` older than 90 days |

`created_at` columns default to `CURRENT_TIMESTAMP(6)`.

For local development against a local MySQL:

```bash
cd plasticid-backend && php spark migrate
```

### Backups

Database dumps live in **`backups/`** (git-ignored — dumps contain PII and
their own event routines). `backups/LATEST.txt` points at the newest dump and
`backups/MANIFEST.txt` records its sha256 and verification status.

```bash
./scripts/backup-db.sh        # dump + gzip + sha256 manifest + 14-day prune
```

The script runs `mysqldump --single-transaction --quick --routines --triggers
--events --add-drop-table` inside the db container, validates the gzip,
appends to `MANIFEST.txt` and prunes dumps older than 14 days. Restore:

```bash
gunzip -c backups/<file>.sql.gz | docker exec -i plasticid-db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot plasticid_db'
```

Restore instructions also live in the script header. Latest verified dump:
`backups/plasticid_db_20261006_141159.sql.gz` (sha256 `aa3a1221…03ec8da79`,
7 tables — row counts and content fingerprints rechecked after restoring into
a throwaway `mysql:8.0` container).

## Project Structure

```
.
├── docker-compose.yml       # 6 services: fastapi, ci, plasticid-db, redis, prometheus, grafana
├── .env                     # Environment variables (secret)
├── .env.example             # Environment template
├── .gitignore
├── README.md
├── mysql/bootstrap.sh       # Fresh-volume users/grants (docker-entrypoint-initdb.d)
├── scripts/backup-db.sh     # Repeatable dump + sha256 manifest + prune
├── backups/                 # gzip dumps + MANIFEST.txt + LATEST.txt (git-ignored)
├── prometheus.yml           # Scrapes fastapi:/metrics
├── grafana/                 # Provisioned datasource + PlasticID Overview dashboard
├── .github/workflows/ci.yml # pytest, composer test, compose validation
├── yolov8n.pt               # Base detector weights (tracked)
├── plasticid/
│   ├── Dockerfile           # python:3.12-slim, non-root, uvicorn :8000
│   ├── api/
│   │   ├── main.py          # FastAPI endpoints + lifespan/outbox worker
│   │   ├── auth.py          # Key validation + rate-limit checks
│   │   ├── database.py      # Verify-only schema diagnostics + keys/logs/outbox DML
│   │   ├── inference.py     # Detector -> classifier pipeline
│   │   ├── inference_cache.py
│   │   ├── preprocess.py
│   │   ├── rate_limiter.py  # Redis-backed limiter
│   │   └── schema.py
│   ├── configs/
│   │   ├── data.yaml        # Dataset config (6 classes)
│   │   └── train.yaml       # Training config
│   ├── src/training/        # train.py + inference helpers
│   ├── tests/test_api.py    # 26 pytest tests
│   ├── models/              # Checkpoints (git-ignored, bind-mounted)
│   ├── data/                # Dataset images/labels (git-ignored)
│   ├── experiments/         # Training runs + metrics.csv (git-ignored)
│   ├── requirements.txt / requirements-dev.txt / pyproject.toml (pytest config)
│   └── Dockerfile / .dockerignore
└── plasticid-backend/       # CodeIgniter 4 (PHP 8.3, Apache)
    ├── app/Controllers/     # Portal, Api\Predictions, Api\ApiDashboard
    ├── app/Database/Migrations/  # single production schema migration
    ├── app/Config/          # Database.php ($default + $migrations groups), Services.php
    ├── app/Views/           # portal/, dashboard/
    ├── tests/               # PHPUnit (unit + stock examples)
    ├── composer.json        # `composer test` -> phpunit
    └── Dockerfile           # php:8.3-apache, migrate on start, COMPOSER_FLAGS build arg
```

Git-ignored local artifacts not listed above: `notebookenv/` (a local Python
virtualenv), `plasticid/uploads/`, `plasticid/inference_output*/`,
`plasticid/batch_inference_output/`, and `plasticid-backend/vendor/`.
