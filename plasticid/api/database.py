import os
import hashlib
import secrets
import pymysql
import pymysql.cursors
from dbutils.pooled_db import PooledDB

DB_HOST = os.getenv("DB_HOST", "plasticid-db")
DB_USER = os.getenv("MYSQL_USER", "tutu")
DB_PASSWORD = os.getenv("MYSQL_PASSWORD")
DB_NAME = os.getenv("MYSQL_DATABASE", "plasticid_db")

if not DB_PASSWORD:
    raise RuntimeError("MYSQL_PASSWORD environment variable is required")

db_pool = PooledDB(
    creator=pymysql,
    maxconnections=10,
    host=DB_HOST,
    user=DB_USER,
    password=DB_PASSWORD,
    database=DB_NAME,
    cursorclass=pymysql.cursors.DictCursor,
    autocommit=True,
    blocking=True,
)


def hash_key(api_key: str) -> str:
    return hashlib.sha256(api_key.encode("utf-8")).hexdigest()


def mask_key(api_key: str) -> str:
    if len(api_key) <= 8:
        return "***"
    return api_key[:5] + "..." + api_key[-4:]


def get_db():
    return db_pool.connection()


def get_api_key_record(api_key: str):
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                "SELECT * FROM api_keys WHERE api_key=%s AND is_active=1",
                (hash_key(api_key),)
            )
            return cur.fetchone()
    finally:
        db.close()


def increment_usage(api_key: str):
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                "UPDATE api_keys SET total_requests = total_requests + 1 WHERE api_key=%s",
                (hash_key(api_key),)
            )
    finally:
        db.close()


def log_prediction(api_key, job_id, model, detection_count, inference_ms):
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute("""
                INSERT INTO prediction_logs
                (job_id, api_key, model, detections, inference_ms, created_at)
                VALUES (%s,%s,%s,%s,%s,NOW())
            """, (job_id, hash_key(api_key), model, detection_count, inference_ms))
    finally:
        db.close()


def create_api_key_record(owner: str, rate_limit: int, window_seconds: int) -> str:
    api_key = "pk_" + secrets.token_hex(24)
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute("""
                INSERT INTO api_keys (api_key, owner, rate_limit, window_seconds)
                VALUES (%s, %s, %s, %s)
            """, (hash_key(api_key), owner, int(rate_limit), int(window_seconds)))
    finally:
        db.close()
    return api_key


def deactivate_api_key(api_key: str) -> bool:
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                "UPDATE api_keys SET is_active=0 WHERE api_key=%s AND is_active=1",
                (hash_key(api_key),)
            )
            return cur.rowcount > 0
    finally:
        db.close()


def list_api_keys():
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute("""
                SELECT id, api_key, owner, is_active, rate_limit,
                       window_seconds, total_requests
                FROM api_keys
            """)
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

            cur.execute("""
                SELECT api_key, total_requests
                FROM api_keys
                ORDER BY total_requests DESC
                LIMIT 5
            """)
            top_users = [
                {"api_key": mask_key(r["api_key"]), "total_requests": r["total_requests"]}
                for r in cur.fetchall()
            ]

        return {
            "total_api_keys": total_keys,
            "active_keys": active_keys,
            "total_requests": total_requests,
            "top_users": top_users
        }
    finally:
        db.close()


def get_prediction_logs(api_key: str, limit: int):
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute("""
                SELECT
                    job_id,
                    model,
                    detections,
                    inference_ms,
                    created_at
                FROM prediction_logs
                WHERE api_key=%s
                ORDER BY created_at DESC
                LIMIT %s
            """, (hash_key(api_key), limit))
            return cur.fetchall()
    finally:
        db.close()


def get_or_create_portal_key(portal_key: str):
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute(
                "SELECT api_key FROM api_keys WHERE owner='portal' AND api_key=%s",
                (hash_key(portal_key),)
            )
            if cur.fetchone():
                cur.execute(
                    "UPDATE api_keys SET is_active=1 WHERE owner='portal' AND api_key=%s",
                    (hash_key(portal_key),)
                )
                return
            cur.execute("""
                INSERT INTO api_keys (api_key, owner, rate_limit, window_seconds)
                VALUES (%s, 'portal', 20, 60)
            """, (hash_key(portal_key),))
    finally:
        db.close()


def init_db():
    db = get_db()
    try:
        with db.cursor() as cur:
            cur.execute("""
                CREATE TABLE IF NOT EXISTS api_keys (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    api_key VARCHAR(64) NOT NULL,
                    owner VARCHAR(255) NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    rate_limit INT NOT NULL DEFAULT 10,
                    window_seconds INT NOT NULL DEFAULT 60,
                    total_requests INT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_api_keys_api_key (api_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """)
            cur.execute("""
                CREATE TABLE IF NOT EXISTS prediction_logs (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    job_id VARCHAR(64) NOT NULL,
                    api_key VARCHAR(64) NOT NULL,
                    model VARCHAR(50) NOT NULL DEFAULT '',
                    detections INT NOT NULL DEFAULT 0,
                    inference_ms INT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_prediction_logs_api_key (api_key),
                    KEY idx_prediction_logs_job_id (job_id),
                    KEY idx_prediction_logs_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """)
            cur.execute("""
                ALTER TABLE api_keys
                MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            """)
            cur.execute("""
                ALTER TABLE prediction_logs
                MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            """)
            cur.execute("""
                UPDATE api_keys
                SET api_key = SHA2(api_key, 256)
                WHERE api_key NOT REGEXP '^[a-f0-9]{64}$'
            """)
            cur.execute("""
                UPDATE prediction_logs
                SET api_key = SHA2(api_key, 256)
                WHERE api_key NOT REGEXP '^[a-f0-9]{64}$'
            """)
    finally:
        db.close()