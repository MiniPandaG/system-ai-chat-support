#!/bin/bash
# Aprovisiona MySQL en producción (idempotente): la base, el usuario de la aplicación (solo SELECT, INSERT, UPDATE y DELETE) y el de
# migraciones (todos los permisos, solo sobre esta base). Ambos exigen conexión cifrada. A diferencia del script de desarrollo,
# no crea ninguna base de pruebas. Se ejecuta al crear el volumen; para aplicarlo a uno existente:
#   docker compose -f compose.production.yaml exec mysql bash /docker-entrypoint-initdb.d/01-provision-users.sh
(
set -euo pipefail

for name in DB_DATABASE DB_USERNAME DB_MIGRATOR_USERNAME; do
    if [[ ! "${!name}" =~ ^[A-Za-z0-9_]+$ ]]; then
        echo "$name solo admite letras, números y guion bajo." >&2
        exit 1
    fi
done
for name in DB_PASSWORD DB_MIGRATOR_PASSWORD; do
    if [[ ! "${!name}" =~ ^[A-Za-z0-9]{24,}$ ]]; then
        echo "$name debe tener 24 o más caracteres alfanuméricos." >&2
        exit 1
    fi
done
if [[ "$DB_USERNAME" == "$DB_MIGRATOR_USERNAME" ]]; then
    echo "El usuario de la aplicación y el de migraciones deben ser distintos." >&2
    exit 1
fi

export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"

mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'%' IDENTIFIED BY '${DB_PASSWORD}' REQUIRE SSL;
ALTER USER '${DB_USERNAME}'@'%' IDENTIFIED BY '${DB_PASSWORD}' REQUIRE SSL;
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${DB_USERNAME}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'%';

CREATE USER IF NOT EXISTS '${DB_MIGRATOR_USERNAME}'@'%' IDENTIFIED BY '${DB_MIGRATOR_PASSWORD}' REQUIRE SSL;
ALTER USER '${DB_MIGRATOR_USERNAME}'@'%' IDENTIFIED BY '${DB_MIGRATOR_PASSWORD}' REQUIRE SSL;
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${DB_MIGRATOR_USERNAME}'@'%';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_MIGRATOR_USERNAME}'@'%';

FLUSH PRIVILEGES;
SQL
)
