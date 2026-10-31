#!/bin/bash
# Crea (o corrige, es idempotente) los dos usuarios de MySQL y las bases de datos.
#   - aplicación: solo SELECT, INSERT, UPDATE y DELETE
#   - migraciones: todos los permisos, solo sobre las bases del proyecto
# Se ejecuta solo al crear el volumen. Para aplicarlo a un volumen existente:
#   docker compose exec mysql bash /docker-entrypoint-initdb.d/01-provision-users.sh
# Si MySQL lo carga con source (sin permiso de ejecución), las opciones no deben filtrarse al entrypoint.
(
set -euo pipefail

for name in DB_DATABASE DB_USERNAME DB_MIGRATOR_USERNAME; do
    if [[ ! "${!name}" =~ ^[A-Za-z0-9_]+$ ]]; then
        echo "$name solo admite letras, números y guion bajo." >&2
        exit 1
    fi
done
for name in DB_PASSWORD DB_MIGRATOR_PASSWORD; do
    if [[ ! "${!name}" =~ ^[A-Za-z0-9]{16,}$ ]]; then
        echo "$name debe tener 16 o más caracteres alfanuméricos." >&2
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
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USERNAME}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${DB_USERNAME}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_DATABASE}_test\`.* TO '${DB_USERNAME}'@'%';

CREATE USER IF NOT EXISTS '${DB_MIGRATOR_USERNAME}'@'%' IDENTIFIED BY '${DB_MIGRATOR_PASSWORD}';
ALTER USER '${DB_MIGRATOR_USERNAME}'@'%' IDENTIFIED BY '${DB_MIGRATOR_PASSWORD}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${DB_MIGRATOR_USERNAME}'@'%';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_MIGRATOR_USERNAME}'@'%';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}_test\`.* TO '${DB_MIGRATOR_USERNAME}'@'%';

FLUSH PRIVILEGES;
SQL
)
