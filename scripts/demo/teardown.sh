#!/usr/bin/env bash
# Borra la empresa de ejemplo de las pruebas de usabilidad con todo lo suyo (tenant:purge).
set -euo pipefail

cd "$(dirname "$0")/../.."
export MSYS_NO_PATHCONV=1

TENANT="$(docker compose exec -T app php scripts/demo/seed.php empresa | tr -d '\r\n')"

if [[ ! "$TENANT" =~ ^[0-9]+$ ]]; then
    echo "No hay una empresa de demostración que borrar." >&2
    exit 1
fi

docker compose exec -T app php artisan tenant:purge "$TENANT" --confirm="$TENANT" --no-interaction
docker compose exec -T app php scripts/demo/seed.php olvidar
echo "La empresa de demostración se borró."
