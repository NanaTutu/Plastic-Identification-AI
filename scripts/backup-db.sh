#!/usr/bin/env bash
# Repeatable MySQL backup for PlasticID.
#
# Usage:
#   scripts/backup-db.sh                 # dump plasticid_db -> backups/
#   KEEP_DAYS=7 scripts/backup-db.sh     # prune dumps older than 7 days (default 14)
#
# Restore (destructive; stops nothing on its own):
#   gunzip -c backups/<file>.sql.gz | \
#     docker exec -i plasticid-db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot plasticid_db'
#
# The dump includes --routines, --triggers and --events so the retention
# events created by the production migration are preserved.
set -euo pipefail
cd "$(dirname "$0")/.."

DB_CONTAINER="${DB_CONTAINER:-plasticid-db}"
DB_NAME="${MYSQL_DATABASE:-plasticid_db}"
BACKUP_DIR="${BACKUP_DIR:-backups}"
KEEP_DAYS="${KEEP_DAYS:-14}"

mkdir -p "$BACKUP_DIR"

stamp="$(date +%Y%m%d_%H%M%S)"
file="${BACKUP_DIR}/${DB_NAME}_${stamp}.sql.gz"
tmp="${file}.tmp"

docker exec -e DB_NAME="$DB_NAME" "$DB_CONTAINER" sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump \
     --single-transaction --quick \
     --routines --triggers --events \
     --add-drop-table \
     "$DB_NAME" | gzip -c' > "$tmp"

# Reject a truncated/failed archive before promoting it.
gzip -t "$tmp"
mv "$tmp" "$file"

sha="$(sha256sum "$file" | cut -d' ' -f1)"
printf '%s  %s\n' "$sha" "$(basename "$file")" >> "${BACKUP_DIR}/MANIFEST.txt"
printf '%s\n' "$(basename "$file")" > "${BACKUP_DIR}/LATEST.txt"
printf '%s  %s\n' "$sha" "$(basename "$file")" > "${file}.sha256"

find "$BACKUP_DIR" -name "${DB_NAME}_*.sql.gz" -mtime +"$KEEP_DAYS" -delete
find "$BACKUP_DIR" -name "${DB_NAME}_*.sql.gz.sha256" -mtime +"$KEEP_DAYS" -delete

echo "Backup written: $file"
echo "SHA256: $sha"
