#!/usr/bin/env bash
# El código de verificación en dos pasos actual del administrador de la empresa de ejemplo (solo local o pruebas).
set -euo pipefail

cd "$(dirname "$0")/../.."
docker compose exec -T app php scripts/demo/seed.php otp
