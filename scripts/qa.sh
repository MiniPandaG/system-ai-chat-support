#!/usr/bin/env bash
# Corre todas las comprobaciones de calidad y seguridad. Falla en cuanto una falla.
set -euo pipefail

cd "$(dirname "$0")/.."

docker compose up -d --wait app mysql redis

echo "== JS: compilación (las pruebas de PHP necesitan el manifiesto de Vite) =="
docker compose run --rm node sh -c "corepack enable && pnpm install --frozen-lockfile && pnpm build"

echo "== PHP: formato, análisis estático, pruebas y auditoría =="
docker compose exec -T app composer qa

echo "== JS: tipos, lint y auditoría =="
docker compose run --rm node sh -c "corepack enable && pnpm qa"

bash scripts/secrets.sh
