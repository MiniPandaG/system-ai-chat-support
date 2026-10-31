#!/usr/bin/env bash
# Prepara el entorno local desde cero: .env, contenedores, dependencias, clave, migraciones y frontend.
# Es repetible: no pisa un .env existente ni una clave ya generada.
set -euo pipefail

cd "$(dirname "$0")/.."

bash scripts/make-env.sh

docker compose up -d --build --wait app mysql redis

docker compose exec -T app composer install --no-interaction

if ! grep -q '^APP_KEY=.\+' .env; then
    docker compose exec -T app php artisan key:generate --force
fi

docker compose exec -T app php artisan migrate --database=mysql_migrator --force

docker compose run --rm node sh -c "corepack enable && pnpm install --frozen-lockfile && pnpm build"

docker compose up -d web
echo "Listo: http://127.0.0.1:8080"
