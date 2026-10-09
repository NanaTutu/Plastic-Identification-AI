# Case Study: PlasticID — AI-Powered Plastic Classification Platform

> **Turning a YOLOv8 notebook experiment into a hardened, observable, two-service
> production platform.**

| | |
|---|---|
| **Project** | PlasticID (Plastic Classification System) |
| **Repository** | `github.com/NanaTutu/Plastic-Identification-AI` |
| **Branch / revision** | `main` @ `94e6be4` |
| **Timeline** | 25 Mar 2026 → 7 Oct 2026 (7 commits) |
| **Stack** | Python 3.12 / FastAPI · PHP 8.3 / CodeIgniter 4.6 · MySQL 8.0 · Redis 7 · Docker Compose · Prometheus + Grafana |
| **ML** | Ultralytics YOLOv8 — `yolov8n` detector + `best_v8m` (YOLOv8m) 6-class classifier |

---

## Table of contents

1. [Executive summary](#1-executive-summary)
2. [The problem](#2-the-problem)
3. [Goals and scope](#3-goals-and-scope)
4. [Solution architecture](#4-solution-architecture)
5. [The machine-learning pipeline](#5-the-machine-learning-pipeline)
6. [The inference API (FastAPI)](#6-the-inference-api-fastapi)
7. [Reliable delivery: the transactional outbox](#7-reliable-delivery-the-transactional-outbox)
8. [The public portal and backend (CodeIgniter)](#8-the-public-portal-and-backend-codeigniter)
9. [Data architecture: one schema authority](#9-data-architecture-one-schema-authority)
10. [Security engineering](#10-security-engineering)
11. [Operations, observability and backup](#11-operations-observability-and-backup)
12. [Testing and CI](#12-testing-and-ci)
13. [Challenges and how they were solved](#13-challenges-and-how-they-were-solved)
14. [Results](#14-results)
15. [Lessons learned](#15-lessons-learned)
16. [Limitations and future work](#16-limitations-and-future-work)
17. [Appendix](#17-appendix)

---

## 1. Executive summary

Recycling facilities and consumers face a recurring problem: the seven common
plastic types look and feel similar, but they must be separated because they
melt, degrade and reprocess differently. PlasticID is an end-to-end system that
classifies a photographed plastic item into **six resin types — HDPE, LDPE, PVC,
PET, PP and PS — using a two-stage YOLOv8 pipeline**, and exposes that
capability to developers through a public, API-keyed service with a portal,
playground, dashboards and monitoring.

The project moved through two distinct phases. The first phase (March–June 2026)
was a prototype: a model file, a notebook, an early FastAPI endpoint and a single
`images`/`predictions` schema. The second phase (September–October 2026) was a
deliberate **productionization effort**: authentication and rate limiting,
a public portal, a single authoritative database migration, a written schema
contract, least-privilege database users, automatic data retention, a
transactional outbox for cross-service delivery, health/readiness probing,
Prometheus/Grafana observability, database backup tooling and CI.

The result is a system whose most interesting engineering is not the model, but
everything around it: **how two independent web applications safely share one
database, how a prediction survives a backend outage, and how the platform fails
closed when its dependencies degrade.**

---

## 2. The problem

### 2.1 Domain problem

Plastic waste sorting depends on accurately identifying the resin type:

| ID | Resin | Common examples |
|----|-------|-----------------|
| 0 | HDPE | milk jugs, detergent bottles |
| 1 | LDPE | plastic bags, films, squeezy bottles |
| 2 | PVC | pipes, window frames, blister packs |
| 3 | PET | beverage bottles, food containers |
| 4 | PP | bottle caps, yoghurt tubs, straws |
| 5 | PS | foam cups, trays, CD cases |

Manual sorting is slow and error-prone, and misclassification contaminates
recycling streams. A low-cost, camera-based classifier that can run on a CPU
(no GPU) lowers the barrier for small facilities and educational users.

### 2.2 Engineering problem

The prototype worked but was not production-safe:

- API keys and rate limits did not exist — anyone could call prediction.
- Two applications (a Python API and a PHP backend) both wanted to own the
  database schema, creating drift and race conditions.
- A prediction was lost if the PHP backend was down when the Python API tried to
  record it.
- Credentials were broadly scoped (`root@'%'`, a legacy `tutu` user).
- Prediction metadata accumulated forever, with no retention policy.
- There was no way to tell whether the service was actually healthy.
- No CI, no backups, no monitoring.

The second phase set out to fix all of the above without changing the core
product promise: *upload an image, get the plastic type*.

---

## 3. Goals and scope

**Functional goals**

- Classify an image into one of six plastic types via an authenticated HTTP API.
- Offer a public portal with methodology, documentation, a live playground and a
  self-service API-key request form.
- Provide admin dashboards for key management and platform statistics.

**Non-functional goals**

- **Reliability** — predictions must not be lost when a downstream service fails.
- **Security** — least privilege everywhere; no secret in the database or logs.
- **Observability** — health/readiness endpoints, metrics, dashboards.
- **Operability** — one command to start, automatic schema management, backups.
- **CPU-only** — must run without a GPU.

**Out of scope** — training orchestration, a user account system, horizontal
model scaling, and a mobile app.

---

## 4. Solution architecture

PlasticID is composed of **two cooperating applications sharing one database**,
plus rate limiting, monitoring and orchestration:

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

| Component | Technology | Responsibility |
|-----------|------------|----------------|
| `fastapi` | Python 3.12 / FastAPI / uvicorn | Inference, API keys, rate limits, prediction logs, outbox worker |
| `ci` | PHP 8.3 / CodeIgniter 4.6 / Apache | Public portal, playground, dashboards, prediction ingest |
| `plasticid-db` | MySQL 8.0 | Single shared schema |
| `redis` | Redis 7 | Atomic per-key sliding-window rate limiting |
| `prometheus` | Prometheus | Scrapes `fastapi:/metrics` every 15 s |
| `grafana` | Grafana | Provisioned "PlasticID Overview" dashboard |

**Key architectural decision — separation of ownership.** Rather than merging the
two applications, PlasticID keeps them independent but gives each a clearly
owned slice of the database and a contract between them:

- **CodeIgniter owns the schema.** A single production migration creates every
  table, foreign key, CHECK constraint and retention event.
- **FastAPI owns inference and the writes for keys/logs/outbox**, and *verifies*
  the schema at startup rather than creating it.

This "one writer of DDL, many verifiers" rule is the backbone of the whole
design and is discussed in [§9](#9-data-architecture-one-schema-authority).

---

## 5. The machine-learning pipeline

### 5.1 Two-stage detection → classification

A common failure mode of single-shot plastic classifiers is that a photo
contains a lot of non-plastic context (a table, a hand, a background). PlasticID
handles this with a two-stage pipeline that first **localizes** the object and
then **classifies** it:

1. **Detector — `yolov8n.pt` (COCO).** Finds candidate objects and keeps only
   those whose COCO class is one of `TARGET_OBJECTS =
   ["bottle", "cup", "bowl", "wine glass", "vase"]`. The highest-confidence
   target becomes the crop region.
2. **Classifier — `best_v8m.pt` (YOLOv8m).** Runs on the cropped region and
   outputs one of the six resin classes with a bounding box and confidence.

If the detector finds no target object, the pipeline degrades gracefully and
classifies the full image. Inference is serialized behind a `threading.Lock`
(`_predict`) so concurrent requests cannot corrupt shared model state.

### 5.2 Preprocessing

Preprocessing is defensive and deterministic (`plasticid/api/preprocess.py`):

- Decode with Pillow and **reject anything that is not JPEG or PNG**.
- Enforce a configurable pixel budget (`MAX_IMAGE_PIXELS`, default 40 MP) to
  defuse decompression-bomb inputs.
- Apply **EXIF orientation transpose** so portrait photos are not classified
  sideways.
- Downscale so the longest edge is at most `MAX_IMAGE_EDGE` (default 1280),
  tracking `scale_x`/`scale_y` so bounding boxes can be mapped back to original
  coordinates (`rescale_bbox`).
- Optional **auto white balance** (`NORMALIZE_WB`) to make lighting across
  devices more consistent.

### 5.3 Training

Training is config-driven (`plasticid/configs/train.yaml`) and dataset-driven
(`data.yaml` with the six class names). Running `python -m src.training.train`:

- trains a YOLO model for 50 epochs at 640 px with the Adam optimizer,
- appends per-class `AP50` plus `mAP50`/`mAP50-95`, inference speed and date to
  `experiments/metrics.csv`,
- copies the best weights to `MODEL_PATH` (`models/best_v8m.pt`).

The training code is honest about its rough edges today: `train.py` has no
`__main__` guard (importing it starts a run), and `src/evaluation/` and
`src/inference/` remain placeholders. These are documented rather than hidden.

### 5.4 Inference backends

The inference layer supports two engines behind one interface:

- **PyTorch (default)** — `yolo.predict(..., device="cpu")`.
- **ONNX (optional)** — enabled with `ONNX_INFERENCE=1`; weights are exported on
  first use and served through ONNX Runtime. If export or load fails, the code
  **falls back to PyTorch** and logs a warning rather than failing the service.

---

## 6. The inference API (FastAPI)

### 6.1 Endpoint surface

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/` | — | Liveness; reports `model_loaded` and model name |
| GET | `/health/db` | — | DB health + schema diagnostics |
| GET | `/health/ready` | — | Readiness: database, schema, model, rate limiter |
| GET | `/metrics` | — | Prometheus metrics |
| GET | `/docs`, `/redoc`, `/openapi.json` | — | Interactive API docs |
| POST | `/v1/predict` | any valid key | Classify an uploaded image |
| GET | `/v1/predictions?limit=` | any valid key | Prediction history for the key |
| GET | `/v1/usage` | any valid key | Quota/usage for the presented key |
| POST | `/v1/keys` | master key | Create a key |
| GET | `/v1/list_keys` | master key | List keys (masked) |
| DELETE | `/v1/keys/{key}` | master key | Deactivate a key |
| GET | `/v1/stats` | master key | Platform stats (rate-limited per IP) |

### 6.2 Request lifecycle for `POST /v1/predict`

1. Read `X-API-KEY`; reject with `401 API_KEY_REQUIRED` if absent.
2. Reject early on a declared `Content-Length` above the 10 MB cap (`413`).
3. Look up the hashed key; reject `401 INVALID_API_KEY` if missing/inactive.
4. Reject `503 MODEL_UNAVAILABLE` if the model is not loaded.
5. **Reserve** a rate-limit slot atomically; on rejection return `429` with a
   `retry_after`, otherwise set `X-RateLimit-Limit/Remaining/Reset`.
6. Validate MIME type, stream the body with a hard byte cap, then verify the
   decoded image is genuinely JPEG/PNG.
7. Serve from the TTL result cache if the bytes were seen recently; otherwise
   run inference.
8. Persist usage + log + outbox entry in a **single transaction**, then hand the
   outbox row to a background delivery task.

### 6.3 API-key model

- Keys look like `pk_<48 hex>`.
- Only a **SHA-256 hash** of each key is stored (`hash_key`); the plaintext key
  is shown once at creation.
- Keys are always returned **masked** (`pk_...2345`); list/stats endpoints mask
  every key.
- A **master key** (`API_KEY`) is the only superuser credential. It lives only
  in the environment, is compared with `hmac.compare_digest`, and is never
  written to the database.
- The `portal` owner name is reserved and cannot be created through the public
  key endpoint.

### 6.4 Rate limiting

Rate limiting is a **sliding window** keyed by a hash of the key/identity and
implemented with a Redis sorted set plus a Lua script:

```lua
redis.call('ZREMRANGEBYSCORE', key, '-inf', now - window)
local count = redis.call('ZCARD', key)
if count >= limit then return {0, count} end
redis.call('ZADD', key, now, member)
redis.call('EXPIRE', key, window + 1)
return {1, count + 1}
```

Three properties matter:

- **Atomic reservation** — the Lua script checks and records in one round trip,
  so concurrent requests cannot oversubscribe a key.
- **Hashed Redis keys** — Redis never sees a raw API key.
- **Degradation policy** — if Redis is unavailable, the limiter falls back to an
  in-process deque **unless** `RATE_LIMIT_REDIS_REQUIRED=1` (the production
  default), in which case it **fails closed** and returns `503`. Admin
  operations are limited to 10 requests / 60 s per IP.

### 6.5 Result caching

A small thread-safe `TTLCache` (LRU + TTL, default 256 items / 900 s) caches
inference results **keyed by a hash of the image bytes**, and only for uploads
under `CACHE_MAX_IMAGE_SIZE` (default 2 MB). This keeps repeated playground
uploads cheap without letting large payloads occupy memory.

---

## 7. Reliable delivery: the transactional outbox

This is the most consequential piece of engineering in the project.

**The problem.** After computing a prediction, the FastAPI service must persist
metadata to MySQL (for history/usage) *and* notify the CodeIgniter backend
(which owns `images`/`predictions`). Naively doing both over the network means a
crash or outage between the two steps either loses the prediction or double-
counts it. Network calls inside a request also add user-visible latency.

**The solution — a transactional outbox.** FastAPI writes the prediction *and* a
row describing the pending delivery **in one local transaction**
(`record_prediction_event`):

```sql
BEGIN;
UPDATE api_keys SET total_requests = total_requests + 1, last_used_at = NOW(6);
INSERT INTO prediction_logs (...);
INSERT INTO prediction_outbox (job_id, api_key_id, payload, status, next_attempt_at)
     VALUES (..., 'pending', NOW(6));
COMMIT;   -- atomic: usage, log and delivery intent succeed or fail together
```

Delivery is then handled by two paths:

- **Fast path** — a background task attempts delivery immediately after the
  response is produced.
- **Safety net** — a lifespan-managed worker polls every 5 s, claims a batch of
  due rows, and delivers them.

**Claiming with tokens.** Rows are claimed with an `UPDATE ... SET
status='processing', claim_token=<uuid>` guarded by a `WHERE` clause that
matches only `pending`/`failed` rows whose `next_attempt_at` has passed, or
`processing` rows whose `locked_at` is older than `OUTBOX_CLAIM_TIMEOUT_SECONDS`
(300 s). This makes the worker safe against duplicate delivery and reclaims
rows from crashed workers. Completion updates are guarded by the same
`claim_token`, so a stale worker cannot overwrite a newer claim.

**Retry policy.** Failures are classified:

- **Permanent** (HTTP `4xx` from the backend, or malformed JSON) → immediately
  `dead_letter`.
- **Transient** (network error, HTTP `5xx`) → `failed`, with exponential backoff
  `min(300, 2^attempts)` seconds, up to `OUTBOX_MAX_ATTEMPTS` (12).

**Idempotency on the receiving side.** The CodeIgniter ingest endpoint
(`Api\Predictions::store`) enforces a unique `job_id` on `images` and, inside a
transaction, returns a `duplicate` response with the existing `image_id` if the
job was already stored — so at-least-once delivery cannot create duplicate rows.

**Net effect:** a prediction is recorded and eventually delivered even if the PHP
backend is down for hours, without blocking the user's response and without
duplicates.

---

## 8. The public portal and backend (CodeIgniter)

The PHP application is not just an ingest sink — it is the human-facing surface.

### 8.1 Routes and controllers

| Route | Controller | Notes |
|-------|------------|-------|
| `/` | `Home::index` | Container healthcheck page |
| `POST /api/predictions` | `Api\Predictions::store` | Master-key ingest, idempotent by `job_id` |
| `GET /api/get_keys` | `Api\Predictions::index` | Proxies FastAPI `/v1/list_keys` |
| `GET /dashboard/api-keys` | `Api\ApiDashboard::keys` | HTML dashboard (master key) |
| `GET /dashboard/api-stats` | `Api\ApiDashboard::stats` | HTML dashboard (master key) |
| `GET /portal`, `/portal/methodology`, `/portal/docs`, `/portal/playground`, `/portal/request` | `Portal` | Public pages |
| `POST /portal/request` | `Portal::submit_request` | CSRF-protected, 5 requests/hour/IP |
| `POST /portal/predict` | `Portal::predict` | CSRF-protected playground proxy |

### 8.2 Portal features

- **Landing / methodology / docs** — explain the system, the six resin types and
  the API contract.
- **Playground** — upload an image and see classifications; the portal forwards
  the upload to FastAPI using the dedicated **portal key**, which is
  auto-registered as a `service` key at API startup.
- **Request-a-key** — validates name/email, records an audit row in
  `api_key_requests`, then calls FastAPI `POST /v1/keys` and shows the new key.
  The audit row is updated to `approved` or `failed` depending on the outcome.
- **Admin dashboards** — proxy key listing and platform stats from FastAPI and
  render them server-side.

### 8.3 Abuse controls

- Portal POSTs are **CSRF-protected** via CodeIgniter's cookie-based CSRF filter
  (`['filter' => 'csrf']`).
- Key requests are throttled to **5 per hour per IP**, and playground predictions
  to `PORTAL_REQUESTS_PER_MINUTE` (default 20) per session, using a session-
  backed sliding counter.
- Uploaded files are validated (size, MIME), proxied, and the temp file is
  always deleted in a `finally` block.
- All proxying to FastAPI uses the internal base URL and attaches the master key
  server-side, so the browser never sees privileged credentials.

### 8.4 Design system

The October commit rebuilt the portal's presentation layer: a redesigned CSS
system (`public/assets/css/portal.css`, ~1,600 lines), self-hosted variable
fonts (Space Grotesk, Outfit, JetBrains Mono), hero/bento imagery and a favicon.
The portal is intentionally self-contained — no external CDN dependency.

---

## 9. Data architecture: one schema authority

### 9.1 The principle

Two applications writing the same schema is a classic source of drift. PlasticID
resolves it with an explicit rule:

> **CodeIgniter migrations are the single source of truth for the schema.
> FastAPI never issues DDL — it only verifies a contract at startup.**

- `2026-10-06-000001_ProductionSchema.php` runs on every backend start
  (`php spark migrate --force`) as `plasticid_migrate`. Its `up()` **drops and
  recreates** every application table for a known clean state, then grants
  table-level DML and creates retention events.
- FastAPI's `database.py` declares an expected contract — required tables,
  columns, indexes, foreign keys and a `SCHEMA_CONTRACT_VERSION`
  (`2026-10-06-000001`) bookkeeping row — and `init_db()` raises if anything is
  missing. The `/health/db` and `/health/ready` endpoints expose the same
  diagnostics.

If the API starts before the migration has run, it retries the *whole*
verification (a known race), but a genuine mismatch is treated as non-retryable
and fails fast (`_is_retryable_db_error`).

### 9.2 Tables

| Table | Owner | Purpose |
|-------|-------|---------|
| `api_keys` | FastAPI | Hashed keys, quotas, status |
| `prediction_logs` | FastAPI | One row per prediction request |
| `prediction_outbox` | FastAPI | Pending/delivered deliveries |
| `images` | CodeIgniter | Ingested image metadata |
| `predictions` | CodeIgniter | Model results, linked to `images` |
| `api_key_requests` | CodeIgniter | Portal key-request audit trail |
| `migrations` | CodeIgniter | CI4 migration bookkeeping |

The migration uses real **foreign keys** (`ON DELETE SET NULL` for `api_key_id`
references, `ON DELETE CASCADE` for `predictions.image_id`), **CHECK
constraints** (e.g. `confidence BETWEEN 0 AND 1`, enumerated statuses),
composite/unique **indexes**, `utf8mb4_0900_ai_ci` collation and microsecond
timestamps (`DATETIME(6)`).

### 9.3 Least-privilege users

| User | Grants | Used by |
|------|--------|---------|
| `plasticid_api` | Schema SELECT + table DML on `api_keys`, `prediction_logs`, `prediction_outbox` | FastAPI |
| `plasticid_ci` | Schema SELECT + DML on `images`, `predictions`, `api_key_requests` | Apache / CI |
| `plasticid_migrate` | ALL + GRANT OPTION (DDL) | `php spark migrate` |
| `root` | `localhost` only | manual admin |

The runtime web and API accounts cannot alter the schema. `mysql/bootstrap.sh`
creates this state on the first boot of an empty volume and **drops the legacy
`tutu` and remote `root@'%'` accounts**. Table-level grants live in the migration
because MySQL refuses to grant on a table that does not exist yet.

### 9.4 Retention (data minimization)

The db container runs with `--event-scheduler=ON`, and the migration creates
three events (each batched with `LIMIT 5000`):

| Event | Schedule | Deletes |
|-------|----------|---------|
| `ev_purge_prediction_logs` | hourly | `prediction_logs` older than 90 days |
| `ev_purge_prediction_outbox` | hourly | delivered/dead-letter outbox rows older than 7 days |
| `ev_purge_api_key_requests` | daily | `api_key_requests` older than 90 days |

This is a concrete data-minimization posture: prediction metadata does not
accumulate indefinitely.

---

## 10. Security engineering

Security was treated as a first-class requirement rather than an afterthought.
The controls, mapped to the risk they address:

| Risk | Control |
|------|---------|
| Key leakage in responses | Keys stored as SHA-256 hashes; all responses mask keys |
| Key leakage in logs | `X-API-KEY` header never logged; query `api_key` redacted if present |
| Master-key compromise | Env-only, never persisted; compared with `hmac.compare_digest` / `hash_equals` |
| Brute force / abuse | Atomic per-key sliding-window limits; admin endpoints per-IP limited |
| Rate-limiter outage | Fails closed (`RATE_LIMIT_REDIS_REQUIRED=1`) |
| Redis exposure | `requirepass` + password embedded in `REDIS_URL`; not published to host |
| Oversized / malicious uploads | 10 MB cap, bounded streaming reads, MIME + decoded-format checks, pixel/edge caps |
| Upload-based resource exhaustion | `MAX_IMAGE_PIXELS` (40 MP) with decompression-bomb handling |
| Path traversal in filenames | Filename sanitization on both services (`Path.name`, control-char stripping) |
| CSRF on portal forms | Cookie-based CSRF filter on all portal POSTs |
| Credential sprawl | Four least-privilege DB accounts; no `root@'%'` |
| Over-privileged runtime | FastAPI runs as non-root `appuser` (uid 10001); Apache sets `ServerTokens Prod`, `LimitRequestBody`, `TraceEnable Off` |
| Data retention / privacy | Automated purge events; backups git-ignored |
| Cross-origin confusion | Explicit CORS allow-list |

---

## 11. Operations, observability and backup

### 11.1 Orchestration and startup ordering

Docker Compose encodes the dependency graph with **health-gated** `depends_on`:

- FastAPI waits for MySQL, Redis **and** the backend to be healthy.
- Prometheus waits for FastAPI.
- Grafana waits for Prometheus.

| Service | Healthcheck | Interval / start |
|---------|-------------|------------------|
| `fastapi` | `GET /health/ready` | 30 s / 90 s |
| `ci` | `GET /` | 15 s / 30 s |
| `plasticid-db` | `mysqladmin ping` | 10 s / 30 s |
| `redis` | `redis-cli ping` (with `REDISCLI_AUTH`) | 10 s / 10 s |

Readiness is dependency-aware: `/health/ready` returns `503` until MySQL, the
schema, the model and the rate limiter are all available, so an orchestrator will
not route traffic to a half-initialized API.

MySQL and Redis are **not published to the host**; Prometheus and Grafana bind
to `127.0.0.1` only.

### 11.2 Observability

- `prometheus-fastapi-instrumentator` exposes `/metrics`.
- Prometheus scrapes `fastapi:8000/metrics` every 15 s.
- Grafana provisions one datasource and one **PlasticID Overview** dashboard
  with request rate and p95 latency panels.
- Structured request logging records method, path, status and latency, with
  key material redacted.

### 11.3 Backups

`scripts/backup-db.sh` performs a repeatable, verifiable dump:

- `mysqldump --single-transaction --quick --routines --triggers --events
  --add-drop-table` inside the db container (events included so the retention
  jobs survive a restore).
- gzip validation (`gzip -t`) *before* promoting the temp file.
- SHA-256 manifest + `LATEST.txt` pointer, written alongside per-file `.sha256`.
- 14-day prune (configurable via `KEEP_DAYS`).

The README records a latest verified dump (`plasticid_db_20261006_141159.sql.gz`)
restored into a throwaway `mysql:8.0` container as a correctness check.

### 11.4 Configuration

`.env.example` is the canonical template with ~40 documented variables. Required
secrets are enforced by Compose (`${VAR:?must be set}`), so a misconfigured
deploy fails at `docker compose up` rather than at runtime. `.env` is git-ignored.

---

## 12. Testing and CI

**Python — 26 pytest tests** (`plasticid/tests/test_api.py`). The suite mocks the
database and model so it needs no external services, and covers:

- health/liveness;
- auth failures (missing/invalid key, master key required, admin rate limit);
- successful prediction and the delivery hand-off (`deliver_outbox_entry`);
- rate-limit `429` behavior and atomic reservation;
- file-type and image-content rejection;
- model-unavailable `503`;
- quota validation on key creation;
- `job_id` sanitization (path traversal) and TTL/LRU cache semantics;
- image preprocessing (downscale vs. passthrough) and bbox rescaling;
- that rate-limiter window keys are hashed.

**PHP — 10 PHPUnit tests** (unit + stock examples), run against SQLite
in-memory, including `PredictionsTest` for the ingest endpoint.

**Compose validation** — `docker compose config --quiet`.

**CI** (`.github/workflows/ci.yml`) runs the same three checks on every push and
pull request: Python 3.12 (`compileall` + `pytest`), PHP 8.3
(`composer validate --strict`, `composer install`, `composer test`) and compose
config validation. Tests also run inside the images for verification
(`docker exec ... pytest`, one-off `phpunit` build).

---

## 13. Challenges and how they were solved

### 13.1 Two apps, one database

**Challenge.** The prototype had both the Python and PHP sides creating and
expecting tables, which caused "works on my volume" drift and startup races.

**Solution.** A single production migration became the only DDL authority, and
FastAPI gained a **verify-only schema contract** (tables, columns, indexes, FKs,
version). The API refuses to start on mismatch, turning silent drift into a loud
failure. Table-level grants were intentionally placed in the migration because
MySQL cannot grant on a table that does not exist yet.

### 13.2 Losing predictions across services

**Challenge.** Persisting locally and notifying PHP are two operations across a
network boundary; either can fail independently.

**Solution.** The transactional outbox described in [§7](#7-reliable-delivery-the-transactional-outbox):
one atomic local write, asynchronous delivery, claim tokens, bounded exponential
backoff, dead-lettering, and idempotent ingest by `job_id`.

### 13.3 Consistent rate limiting under concurrency

**Challenge.** A naive read-then-write counter races and can over-admit requests;
an in-memory limiter cannot be shared across workers.

**Solution.** A Redis sorted-set sliding window executed as a single Lua script
(atomic), with hashed keys and a deliberate degradation policy: fall back to
memory in development, but **fail closed** in production.

### 13.4 Startup ordering

**Challenge.** FastAPI needs the schema to exist, but the backend that creates it
may not have finished migrating.

**Solution.** Health-gated Compose dependencies, API startup retries classified
as retryable vs. non-retryable, and a readiness endpoint that gates on every
dependency.

### 13.5 Model artifacts and datasets

**Challenge.** Model weights and datasets are large and may contain sensitive
data; committing them bloats the repo.

**Solution.** The dataset, notebooks checkpoints and output folders were
untracked from git; `best_v8m.pt` is bind-mounted into the container; only the
base `yolov8n.pt` weights are tracked.

### 13.6 The repository was renamed

**Challenge.** The GitHub remote moved from `pythondev` to
`Plastic-Identification-AI`, and the historical commits touched a CI workflow
file.

**Solution.** Updated the git remote to the new URL and pushed with a token
scoped for `repo` **and** `workflow`. (Operational note recorded here because it
is the kind of friction that recurs whenever a token lacks the workflow scope.)

---

## 14. Results

**What the platform delivers today:**

- An authenticated, rate-limited classification API with six resin classes,
  CPU-only inference and optional ONNX acceleration.
- A public portal with methodology, documentation, a live playground and
  self-service key requests, plus admin key/stat dashboards.
- **At-least-once, exactly-effectively-once** delivery of predictions to the
  backend via the outbox, with retries and dead-lettering.
- A single authoritative schema with real referential integrity, CHECK
  constraints and indexes, verified at API startup.
- Least-privilege database access across four accounts, with legacy
  over-privileged accounts removed.
- Automatic data retention (90-day logs, 7-day outbox, 90-day key requests).
- Dependency-aware health/readiness, Prometheus metrics and a Grafana dashboard.
- Verified, prunable database backups.
- CI covering both languages plus compose validation.

**Honest note on model metrics.** Training writes per-class AP and mAP figures to
`experiments/metrics.csv`, but that directory is git-ignored, so the repository
does not assert specific accuracy numbers. Any accuracy claim should be read from
a training run rather than from this document. The engineering guarantees above
are verifiable in the code and tests.

---

## 15. Lessons learned

1. **Ownership beats integration.** Giving each service an explicit slice of the
   schema and a contract, instead of merging codebases, kept both apps
   independently deployable while eliminating drift.
2. **Verify, don't recreate.** A startup check that refuses to run on schema
   mismatch is worth more than a hundred lines of defensive DDL.
3. **Design for the failure, not the happy path.** The outbox and the
   fail-closed rate limiter exist because the interesting cases are the ones
   where something is already broken.
4. **Least privilege must be enforced by the platform, not convention.** Table-
   level grants and a dedicated migration account make over-privilege impossible
   rather than discouraged.
5. **Observability is a feature.** A readiness endpoint that reflects real
   dependencies is what lets Compose (and a future orchestrator) behave.
6. **Document the rough edges.** The README calls out the `train.py` import side
   effect and the empty placeholder packages — honesty keeps the next
   contributor from stepping on a rake.

---

## 16. Limitations and future work

- **Single shared database** couples the two services operationally; a future
  iteration could split into separate schemas with an explicit API/event
  boundary.
- **No integration test against a real MySQL/Redis** in CI — tests are heavily
  mocked, so the schema contract is verified at runtime rather than in the
  pipeline.
- **No lint/format jobs**; CI checks compile, tests and config only.
- **Training pipeline ergonomics** — `train.py` lacks an `__main__` guard, and
  `src/evaluation/` and `src/inference/` are empty placeholders.
- **Inference is CPU-bound and serialized** behind a global lock; horizontal
  scaling would need a worker pool or a dedicated inference service.
- **No pagination on prediction history** beyond a clamped `limit` (1–100).
- **Retention events are batched** (`LIMIT 5000` per run); a very large backlog
  drains over multiple executions.
- **Portal throttling is session/IP based**, which is coarse behind shared NATs.

---

## 17. Appendix

### 17.1 Repository layout

```
.
├── docker-compose.yml         # 6 services: fastapi, ci, db, redis, prometheus, grafana
├── .env.example               # canonical configuration template (~40 vars)
├── mysql/bootstrap.sh         # fresh-volume least-privilege users
├── scripts/backup-db.sh       # dump + sha256 manifest + prune
├── prometheus.yml             # scrapes fastapi:/metrics
├── grafana/                   # provisioned datasource + PlasticID Overview
├── .github/workflows/ci.yml   # pytest + composer test + compose validation
├── yolov8n.pt                 # tracked base detector weights
├── plasticid/                 # FastAPI inference API
│   ├── api/                   # main, auth, database, inference, preprocess,
│   │                          #   inference_cache, rate_limiter, schema
│   ├── configs/               # data.yaml, train.yaml
│   ├── src/training/          # train.py, single/batch inference
│   ├── tests/test_api.py      # 26 pytest tests
│   └── Dockerfile             # python:3.12-slim, non-root, uvicorn :8000
└── plasticid-backend/         # CodeIgniter 4 (PHP 8.3, Apache)
    ├── app/Controllers/       # Portal, Api\Predictions, Api\ApiDashboard
    ├── app/Database/Migrations/  # single production schema migration
    ├── app/Config/            # Database.php, Services.php, Routes.php
    ├── app/Views/             # portal/, dashboard/
    └── Dockerfile             # php:8.3-apache, migrate on start
```

### 17.2 Commit history

| Commit | Date | Summary |
|--------|------|---------|
| `70e7e71` | 2026-03-25 | first commit |
| `c220b3f` | 2026-03-25 | added readme.md |
| `46bc940` | 2026-06-02 | 02-06-2026 |
| `bd3b5e6` | 2026-09-22 | chore: untrack dataset and notebook checkpoints |
| `5afb47b` | 2026-09-22 | feat: add API key management, rate limiting, portal, and tests |
| `973f980` | 2026-09-22 | refactor: unify model path, reconcile schema owners, drop legacy endpoint |
| `94e6be4` | 2026-10-07 | feat: production schema migration, hardened API security, redesigned portal |

### 17.3 Selected environment variables

| Variable | Purpose | Default |
|----------|---------|---------|
| `API_KEY` | Master key (env-only) | *required* |
| `PORTAL_API_KEY` | Playground service key | empty |
| `API_DB_PASSWORD` / `CI_DB_PASSWORD` / `MIGRATE_DB_PASSWORD` | Per-role DB passwords | *required* |
| `REDIS_PASSWORD` | Redis AUTH | *required* |
| `RATE_LIMIT_REDIS_REQUIRED` | Fail closed if Redis is down | `1` |
| `OUTBOX_WORKER_ENABLED` / `OUTBOX_POLL_INTERVAL` / `OUTBOX_BATCH_SIZE` | Outbox worker | `1` / `5` / `10` |
| `OUTBOX_MAX_ATTEMPTS` / `OUTBOX_CLAIM_TIMEOUT_SECONDS` | Retry policy | `12` / `300` |
| `MODEL_PATH` / `DETECTOR_PATH` | Classifier / detector weights | `/app/models/best_v8m.pt` / `/app/yolov8n.pt` |
| `MAX_IMAGE_EDGE` / `MAX_IMAGE_PIXELS` | Upload dimension limits | `1280` / `40000000` |
| `PORTAL_REQUESTS_PER_MINUTE` | Playground throttle | `20` |

### 17.4 Glossary

- **Outbox** — a table holding delivery intents written in the same transaction
  as the business data, drained asynchronously for reliable delivery.
- **Claim token** — a per-attempt UUID that makes outbox completion updates safe
  against duplicate or stale workers.
- **Master key** — the single superuser API credential, stored only in the
  environment.
- **Verify-only** — FastAPI validates the schema at startup but never mutates it.
- **Fail closed** — on dependency loss, deny the request rather than allow it.
