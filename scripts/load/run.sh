#!/usr/bin/env bash
# Prueba de carga local con k6 (imagen fijada). Crea una empresa de ejemplo con 10 000 tickets, lanza los escenarios y la
# borra con tenant:purge. Solo contra el entorno local o de pruebas: nunca apuntes esto a producción.
# Uso: bash scripts/load/run.sh [panel|api|todo]
set -euo pipefail

cd "$(dirname "$0")/../.."
export MSYS_NO_PATHCONV=1

K6_IMAGE="grafana/k6:2.2.0"
WHAT="${1:-todo}"
NETWORK="$(docker inspect "$(docker compose ps -q web)" --format '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{end}}')"

echo "== Creando la empresa de carga (10 000 tickets) =="
STATE="$(docker compose exec -T app php scripts/load/seed.php 10000)"

field() {
    printf '%s' "$STATE" | php -r '$j = json_decode(stream_get_contents(STDIN), true); $v = $j[$argv[1]]; echo is_array($v) ? json_encode($v) : $v;' "$1"
}

TENANT="$(field tenant)"
trap 'echo "== Borrando la empresa de carga =="; docker compose exec -T app php artisan tenant:purge "$TENANT" --confirm="$TENANT" --no-interaction >/dev/null || true' EXIT

status=0

run_k6() {
    docker run --rm --network "$NETWORK" -v "$PWD/scripts/load:/scripts:ro" \
        -e BASE_URL="http://web" -e AGENTS="$(field agents)" \
        -e API_KEY="$(field api_key)" -e FIRST_TICKET="$(field first_ticket)" \
        "$K6_IMAGE" run "/scripts/$1.js"
}

# Los dos escenarios corren aunque uno no pase sus umbrales; el resultado final es el peor de los dos.
if [ "$WHAT" = "panel" ] || [ "$WHAT" = "todo" ]; then echo "== Panel =="; run_k6 panel || status=1; fi
if [ "$WHAT" = "api" ] || [ "$WHAT" = "todo" ]; then echo "== API =="; run_k6 api || status=1; fi
exit $status
