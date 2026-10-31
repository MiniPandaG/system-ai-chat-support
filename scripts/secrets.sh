#!/usr/bin/env bash
# Busca secretos con gitleaks (imagen fijada por versión, sin instalar nada global).
# Escanea el árbol de trabajo y, si ya hay commits, también todo el historial.
set -euo pipefail

cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1

GITLEAKS_IMAGE="ghcr.io/gitleaks/gitleaks:v8.30.1"

echo "== gitleaks: árbol de trabajo =="
docker run --rm -v "$PWD:/repo:ro" "$GITLEAKS_IMAGE" dir /repo \
    --config /repo/.gitleaks.dir.toml --redact --no-banner

if git rev-parse --verify HEAD >/dev/null 2>&1; then
    echo "== gitleaks: historial de Git =="
    # safe.directory: dentro del contenedor el repositorio pertenece a otro usuario (p. ej. en CI).
    docker run --rm -v "$PWD:/repo:ro" \
        -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/repo \
        "$GITLEAKS_IMAGE" git /repo \
        --config /repo/.gitleaks.toml --redact --no-banner
else
    echo "== gitleaks: sin commits todavía, se omite el historial =="
fi
