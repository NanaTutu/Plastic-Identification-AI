#!/bin/sh
# Least-privilege database users for PlasticID.
#
# This script runs exactly once, on the first boot of an *empty* MySQL data
# directory (docker-compose mounts it at /docker-entrypoint-initdb.d/).
# On an existing volume it never runs again; apply the same statements
# manually as root (see README, "Database users").
#
# Privilege model:
#   plasticid_api     - FastAPI runtime.  SELECT on the schema (reads are
#                       verified/diagnostic) + table-level DML issued later by
#                       the CodeIgniter migration, once the tables exist
#                       (MySQL rejects table-level GRANTs on missing tables).
#   plasticid_ci      - CodeIgniter runtime (Apache).  SELECT on the schema +
#                       table-level DML granted by the migration.
#   plasticid_migrate - only used by `php spark migrate` (Config\Services).
#                       ALL PRIVILEGES + GRANT OPTION so the migration can
#                       issue the table-level grants above.
#   root              - localhost only; the remote root account is dropped.
set -eu

: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD must be set}"
: "${MYSQL_DATABASE:?MYSQL_DATABASE must be set}"
: "${API_DB_PASSWORD:?API_DB_PASSWORD must be set}"
: "${CI_DB_PASSWORD:?CI_DB_PASSWORD must be set}"
: "${MIGRATE_DB_PASSWORD:?MIGRATE_DB_PASSWORD must be set}"

MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot <<SQL
CREATE USER IF NOT EXISTS 'plasticid_api'@'%' IDENTIFIED BY '${API_DB_PASSWORD}';
CREATE USER IF NOT EXISTS 'plasticid_ci'@'%' IDENTIFIED BY '${CI_DB_PASSWORD}';
CREATE USER IF NOT EXISTS 'plasticid_migrate'@'%' IDENTIFIED BY '${MIGRATE_DB_PASSWORD}';

GRANT SELECT ON ${MYSQL_DATABASE}.* TO 'plasticid_api'@'%';
GRANT SELECT ON ${MYSQL_DATABASE}.* TO 'plasticid_ci'@'%';
GRANT ALL PRIVILEGES ON ${MYSQL_DATABASE}.* TO 'plasticid_migrate'@'%' WITH GRANT OPTION;

-- Legacy/over-privileged accounts from earlier setups.
DROP USER IF EXISTS 'tutu'@'%';
DROP USER IF EXISTS 'root'@'%';
SQL
