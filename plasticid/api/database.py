import hashlib
import json
import os
import secrets
import uuid
from typing import Any

import pymysql
import pymysql.cursors
from dbutils.pooled_db import PooledDB, TooManyConnections

DB_HOST = os.getenv("DB_HOST", "plasticid-db")
DB_USER = os.getenv("MYSQL_USER", "plasticid_api")
DB_PASSWORD = os.getenv("MYSQL_PASSWORD")
DB_NAME = os.getenv("MYSQL_DATABASE", "plasticid_db")
DB_PORT = int(os.getenv("DB_PORT", "3306"))
DB_MAX_CONNECTIONS = max(1, int(os.getenv("DB_MAX_CONNECTIONS", "10")))
DB_CONNECT_TIMEOUT = max(1, int(os.getenv("DB_CONNECT_TIMEOUT", "5")))
OUTBOX_MAX_ATTEMPTS = max(1, int(os.getenv("OUTBOX_MAX_ATTEMPTS", "12")))
OUTBOX_CLAIM_TIMEOUT_SECONDS = max(60, int(os.getenv("OUTBOX_CLAIM_TIMEOUT_SECONDS", "300")))

# The schema is owned by the CodeIgniter migrations (`php spark migrate`,
# run by the ci service at startup). FastAPI never creates or alters tables;
# init_db() only verifies that this migration has been applied.
SCHEMA_CONTRACT_VERSION = "2026-10-06-000001"

if not DB_PASSWORD:
    raise RuntimeError("MYSQL_PASSWORD environment variable is required")


class DatabaseBusy(RuntimeError):
    """Raised when the connection pool has no free connection to hand out."""


# blocking=False means the pool raises instead of waiting forever when it is
# exhausted; callers surface this as a bounded 503 rather than hanging a worker
# thread indefinitely under load.
db_pool = PooledDB(
    creator=pymysql,
    maxconnections=DB_MAX_CONNECTIONS,
    host=DB_HOST,
    user=DB_USER,
    password=DB_PASSWORD,
    database=DB_NAME,
    port=DB_PORT,
    connect_timeout=DB_CONNECT_TIMEOUT,
    cursorclass=pymysql.cursors.DictCursor,
    autocommit=True,
    blocking=False,
)


def hash_key(api_key: str) -> str:
    return hashlib.sha256(api_key.encode("utf-8")).hexdigest()


def mask_key(api_key: str) -> str:
    if len(api_key) <= 8:
        return "***"
    return api_key[:3] + "..." + api_key[-4:]


def get_db():
    try:
        return db_pool.connection()
    except TooManyConnections as exc:
        raise DatabaseBusy("Database connection pool is exhausted") from exc


# Expected schema contract. Tables are created by
# plasticid-backend/app/Database/Migrations/2026-10-06-000001_ProductionSchema.php;
# `migrations` is CodeIgniter's own bookkeeping table.
_REQUIRED_TABLES: dict[str, set[str]] = {
    "api_keys": {
        "id",
        "api_key",
        "owner",
        "key_type",
        "is_active",
        "rate_limit",
        "window_seconds",
        "total_requests",
        "last_used_at",
        "deactivated_at",
        "created_at",
        "updated_at",
    },
    "prediction_logs": {
        "id",
        "job_id",
        "api_key",
        "api_key_id",
        "model",
        "detections",
        "inference_ms",
        "source",
        "detected_object",
        "image_sha256",
        "created_at",
    },
    "prediction_outbox": {
        "id",
        "job_id",
        "api_key_id",
        "payload",
        "status",
        "attempts",
        "next_attempt_at",
        "locked_at",
        "claim_token",
        "delivered_at",
        "last_error",
        "created_at",
        "updated_at",
    },
    "images": {
        "id",
        "filename",
        "job_id",
        "source",
        "api_key_id",
        "model",
        "inference_ms",
        "detection_count",
        "detected_object",
        "image_width",
        "image_height",
        "image_sha256",
        "content_type",
        "created_at",
    },
    "predictions": {
        "id",
        "image_id",
        "label",
        "confidence",
        "x1",
        "y1",
        "x2",
        "y2",
        "created_at",
    },
    "api_key_requests": {
        "id",
        "name",
        "email",
        "status",
        "api_key_id",
        "error_message",
        "created_at",
        "updated_at",
    },
    "migrations": {
        "id",
        "version",
        "class",
        "group",
        "namespace",
        "time",
        "batch",
    },
}

_REQUIRED_INDEXES = {
    ("api_keys", ("api_key",), True),
    ("api_keys", ("owner", "is_active"), False),
    ("prediction_logs", ("job_id",), True),
    ("prediction_logs", ("api_key", "created_at"), False),
    ("prediction_outbox", ("job_id",), True),
    ("prediction_outbox", ("status", "next_attempt_at"), False),
    ("images", ("job_id",), True),
    ("predictions", ("image_id",), False),
    ("api_key_requests", ("status", "created_at"), False),
}

# (table, column, referenced_table, referenced_column)
_REQUIRED_FOREIGN_KEYS = {
    ("predictions", "image_id", "images", "id"),
    ("images", "api_key_id", "api_keys", "id"),
    ("prediction_logs", "api_key_id", "api_keys", "id"),
    ("prediction_outbox", "api_key_id", "api_keys", "id"),
    ("api_key_requests", "api_key_id", "api_keys", "id"),
}


def _schema_indexes(cur) -> set[tuple[str, tuple[str, ...], bool]]:
    cur.execute(
        """
        SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
        """
    )
    grouped: dict[tuple[str, str], dict[str, Any]] = {}
    for row in cur.fetchall():
        key = (row["TABLE_NAME"], row["INDEX_NAME"])
        entry = grouped.setdefault(key, {"unique": True, "columns": []})
        entry["unique"] = entry["unique"] and row["NON_UNIQUE"] == 0
        entry["columns"].append(row["COLUMN_NAME"])
    return {
        (table, tuple(entry["columns"]), bool(entry["unique"]))
        for (table, _), entry in grouped.items()
    }


def _foreign_keys(cur) -> set[tuple[str, str, str, str]]:
    cur.execute(
        """
        SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND REFERENCED_TABLE_NAME IS NOT NULL
          AND TABLE_NAME <> 'migrations'
        """
    )
    return {
        (row["TABLE_NAME"], row["COLUMN_NAME"], row["REFERENCED_TABLE_NAME"], row["REFERENCED_COLUMN_NAME"])
        for row in cur.fetchall()
    }


def schema_diagnostics() -> dict[str, Any]:
    missing_tables: list[str] = []
    missing_columns: dict[str, list[str]] = {}
    missing_indexes: list[str] = []
    missing_fks: list[str] = []
    version: str | None = None
    try:
        db = get_db()
        try:
            with db.cursor() as cur:
                cur.execute(
                    """
                    SELECT TABLE_NAME
                    FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE()
                    """
                )
                existing_tables = {row["TABLE_NAME"] for row in cur.fetchall()}
                for table in _REQUIRED_TABLES:
                    if table not in existing_tables:
                        missing_tables.append(table)
                for table in sorted(_REQUIRED_TABLES):
                    if table in missing_tables:
                        missing_columns[table] = sorted(_REQUIRED_TABLES[table])
                        continue
                    cur.execute(
                        """
                        SELECT COLUMN_NAME
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                        """,
                        (table,),
                    )
                    columns = {row["COLUMN_NAME"] for row in cur.fetchall()}
                    missing = sorted(_REQUIRED_TABLES[table] - columns)
                    if missing:
                        missing_columns[table] = missing
                existing_indexes = _schema_indexes(cur)
                for table, columns, unique in _REQUIRED_INDEXES:
                    if (table, columns, unique) not in existing_indexes:
                        missing_indexes.append(f"{table}({','.join(columns)})")
                existing_fks = _foreign_keys(cur)
                for table, column, ref_table, ref_column in sorted(_REQUIRED_FOREIGN_KEYS):
                    if (table, column, ref_table, ref_column) not in existing_fks:
                        missing_fks.append(f"{table}.{column}->{ref_table}.{ref_column}")
                if "migrations" not in missing_tables:
                    cur.execute(
                        "SELECT version FROM migrations WHERE version = %s",
                        (SCHEMA_CONTRACT_VERSION,),
                    )
                    row = cur.fetchone()
                    version = row["version"] if row else None
        finally:
            db.close()
    except Exception as exc:
        return {
            "ok": False,
            "missing_tables": sorted(_REQUIRED_TABLES),
            "missing_columns": {table: sorted(columns) for table, columns in _REQUIRED_TABLES.items()},
            "missing_indexes": sorted(
                f"{table}({','.join(columns)})" for table, columns, _ in _REQUIRED_INDEXES
            ),
            "missing_fks": sorted(
                f"{table}.{column}->{ref_table}.{ref_column}"
                for table, column, ref_table, ref_column in _REQUIRED_FOREIGN_KEYS
            ),
            "version": None,
            "error": type(exc).__name__,
        }

    return {
        "ok": (
            not missing_tables
            and not missing_columns
            and not missing_indexes
            and not missing_fks
            and version is not None
        ),
        "missing_tables": sorted(missing_tables),
        "missing_columns": missing_columns,
        "missing_indexes": sorted(missing_indexes),
        "missing_fks": sorted(missing_fks),
        "version": version,
        "error": None,
    }


def db_is_ready() -> bool:
    return bool(schema_diagnostics()["ok"])


def init_db():
    """Verify (never mutate) the schema owned by the CodeIgniter migrations."""
    diagnostics = schema_diagnostics()
    if not diagnostics["ok"]:
        raise RuntimeError(
            "Database schema verification failed: " + json.dumps(diagnostics, sort_keys=True)
        )


def close_db_pool() -> None:
    db_pool.close()


def get_api_key_record(api_key: str):
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                "SELECT * FROM api_keys WHERE api_key=%s AND is_active=1",
                (hash_key(api_key),),
            )
            return cur.fetchone()
    finally:
        db.close()


def record_prediction_event(
    api_key: str,
    api_key_id: int | None,
    job_id: str,
    model: str,
    detection_count: int,
    inference_ms: int,
    payload: dict[str, Any],
    detected_object: str,
    image_sha256: str,
    source: str,
) -> int:
    db = get_db()
    try:
        db.begin()
        with db.cursor() as cur:
            cur.execute(
                """
                UPDATE api_keys
                SET total_requests = total_requests + 1, last_used_at = NOW(6)
                WHERE api_key=%s
                """,
                (hash_key(api_key),),
            )
            if cur.rowcount != 1:
                raise RuntimeError("API key is not active")
            cur.execute(
                """
                INSERT INTO prediction_logs
                (job_id, api_key, api_key_id, model, detections, inference_ms,
                 source, detected_object, image_sha256, created_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(6))
                """,
                (
                    job_id,
                    hash_key(api_key),
                    api_key_id,
                    model,
                    int(detection_count),
                    int(inference_ms),
                    source,
                    detected_object,
                    image_sha256,
                ),
            )
            cur.execute(
                """
                INSERT INTO prediction_outbox
                (job_id, api_key_id, payload, status, next_attempt_at)
                VALUES (%s,%s,%s,'pending',NOW(6))
                """,
                (job_id, api_key_id, json.dumps(payload, separators=(",", ":"))),
            )
            outbox_id = int(cur.lastrowid)
        db.commit()
        return outbox_id
    except Exception:
        try:
            db.rollback()
        except Exception:
            pass
        raise
    finally:
        db.close()


def _insert_api_key(owner: str, rate_limit: int, window_seconds: int, key_type: str) -> tuple[str, int]:
    api_key = "pk_" + secrets.token_hex(24)
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                """
                INSERT INTO api_keys (api_key, owner, key_type, rate_limit, window_seconds)
                VALUES (%s, %s, %s, %s, %s)
                """,
                (hash_key(api_key), owner, key_type, int(rate_limit), int(window_seconds)),
            )
            return api_key, int(cur.lastrowid)
    finally:
        db.close()


def create_api_key_record_with_id(
    owner: str, rate_limit: int, window_seconds: int
) -> tuple[str, int]:
    return _insert_api_key(owner, rate_limit, window_seconds, "user")


def deactivate_api_key(api_key: str) -> bool:
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                """
                UPDATE api_keys
                SET is_active=0, deactivated_at=NOW(6)
                WHERE api_key=%s AND is_active=1
                """,
                (hash_key(api_key),),
            )
            return cur.rowcount > 0
    finally:
        db.close()


def list_api_keys():
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                """
                SELECT id, api_key, owner, key_type, is_active, rate_limit,
                       window_seconds, total_requests, created_at, last_used_at
                FROM api_keys
                ORDER BY id
                """
            )
            keys = cur.fetchall()
        return [
            {
                **key,
                "api_key": mask_key(key["api_key"]),
                "is_active": bool(key["is_active"]),
            }
            for key in keys
        ]
    finally:
        db.close()


def get_stats():
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute("SELECT COUNT(*) AS total FROM api_keys")
            total_keys = cur.fetchone()["total"]

            cur.execute("SELECT COUNT(*) AS total FROM api_keys WHERE is_active=1")
            active_keys = cur.fetchone()["total"]

            cur.execute("SELECT COALESCE(SUM(total_requests),0) AS total FROM api_keys")
            total_requests = cur.fetchone()["total"] or 0

            cur.execute(
                """
                SELECT api_key, total_requests
                FROM api_keys
                ORDER BY total_requests DESC
                LIMIT 5
                """
            )
            top_users = [
                {"api_key": mask_key(r["api_key"]), "total_requests": r["total_requests"]}
                for r in cur.fetchall()
            ]

        return {
            "total_api_keys": total_keys,
            "active_keys": active_keys,
            "total_requests": total_requests,
            "top_users": top_users,
        }
    finally:
        db.close()


def get_prediction_logs(api_key: str, limit: int):
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                """
                SELECT
                    job_id,
                    model,
                    detections,
                    inference_ms,
                    source,
                    detected_object,
                    image_sha256,
                    created_at
                FROM prediction_logs
                WHERE api_key=%s
                ORDER BY created_at DESC
                LIMIT %s
                """,
                (hash_key(api_key), limit),
            )
            return cur.fetchall()
    finally:
        db.close()


def get_or_create_portal_key(portal_key: str):
    rate_limit = max(1, int(os.getenv("PORTAL_RATE_LIMIT", "100")))
    window_seconds = max(1, int(os.getenv("PORTAL_RATE_WINDOW", "60")))
    key_hash = hash_key(portal_key)
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                "SELECT id FROM api_keys WHERE key_type='service' AND api_key=%s",
                (key_hash,),
            )
            if cur.fetchone():
                cur.execute(
                    "UPDATE api_keys SET is_active=0, deactivated_at=NOW(6) WHERE key_type='service' AND api_key<>%s",
                    (key_hash,),
                )
                cur.execute(
                    """
                    UPDATE api_keys
                    SET is_active=1, deactivated_at=NULL, rate_limit=%s, window_seconds=%s
                    WHERE key_type='service' AND api_key=%s
                    """,
                    (rate_limit, window_seconds, key_hash),
                )
                return
            cur.execute(
                "UPDATE api_keys SET is_active=0, deactivated_at=NOW(6) WHERE key_type='service'"
            )
            cur.execute(
                """
                INSERT INTO api_keys (api_key, owner, key_type, rate_limit, window_seconds)
                VALUES (%s, 'portal', 'service', %s, %s)
                """,
                (key_hash, rate_limit, window_seconds),
            )
    finally:
        db.close()


def _claim_outbox(cur, token: str, limit: int, outbox_id: int | None = None) -> None:
    # The timeout is a validated integer from configuration, so interpolating it
    # avoids a placeholder-count mismatch across the two call shapes below.
    claimable = f"""
        (status IN ('pending', 'failed') AND next_attempt_at <= NOW(6))
        OR (status = 'processing' AND locked_at < DATE_SUB(NOW(6), INTERVAL {OUTBOX_CLAIM_TIMEOUT_SECONDS} SECOND))
    """
    if outbox_id is None:
        cur.execute(
            f"""
            UPDATE prediction_outbox
            SET status='processing', attempts=attempts+1, locked_at=NOW(6),
                claim_token=%s, updated_at=NOW(6)
            WHERE {claimable}
            ORDER BY id
            LIMIT %s
            """,
            (token, limit),
        )
    else:
        cur.execute(
            f"""
            UPDATE prediction_outbox
            SET status='processing', attempts=attempts+1, locked_at=NOW(6),
                claim_token=%s, updated_at=NOW(6)
            WHERE id=%s AND {claimable}
            """,
            (token, outbox_id),
        )


def _claimed_rows(cur, token: str) -> list[dict[str, Any]]:
    cur.execute(
        """
        SELECT id, job_id, api_key_id, payload, attempts, claim_token
        FROM prediction_outbox
        WHERE claim_token=%s AND status='processing'
        ORDER BY id
        """,
        (token,),
    )
    return list(cur.fetchall())


def claim_outbox_entries(limit: int = 10) -> list[dict[str, Any]]:
    token = uuid.uuid4().hex
    db = get_db()
    try:
        with db.cursor() as cur:
            _claim_outbox(cur, token, max(1, min(int(limit), 100)))
            return _claimed_rows(cur, token)
    finally:
        db.close()


def claim_outbox_entry(outbox_id: int) -> dict[str, Any] | None:
    token = uuid.uuid4().hex
    db = get_db()
    try:
        with db.cursor() as cur:
            _claim_outbox(cur, token, 1, outbox_id)
            rows = _claimed_rows(cur, token)
            return rows[0] if rows else None
    finally:
        db.close()


def mark_outbox_delivered(outbox_id: int, claim_token: str) -> None:
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                """
                UPDATE prediction_outbox
                SET status='delivered', delivered_at=NOW(6), locked_at=NULL,
                    claim_token=NULL, last_error=NULL, updated_at=NOW(6)
                WHERE id=%s AND claim_token=%s
                """,
                (outbox_id, claim_token),
            )
    finally:
        db.close()


def mark_outbox_failed(
    outbox_id: int,
    claim_token: str,
    error: str,
    permanent: bool = False,
    attempts: int = 0,
) -> None:
    dead_letter = permanent or attempts >= OUTBOX_MAX_ATTEMPTS
    # Schedule relative to the database clock (NOW(6)) rather than a Python
    # UTC timestamp, so the backoff stays consistent with the claim query's
    # `next_attempt_at <= NOW(6)` comparison regardless of server timezone.
    backoff_seconds = min(300, 2 ** min(attempts, 8))
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                """
                UPDATE prediction_outbox
                SET status=%s,
                    next_attempt_at=DATE_ADD(NOW(6), INTERVAL %s SECOND),
                    locked_at=NULL, claim_token=NULL,
                    last_error=%s, updated_at=NOW(6)
                WHERE id=%s AND claim_token=%s
                """,
                (
                    "dead_letter" if dead_letter else "failed",
                    backoff_seconds,
                    error[:255],
                    outbox_id,
                    claim_token,
                ),
            )
    finally:
        db.close()
