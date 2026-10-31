#!/usr/bin/env bash
# Crea .env a partir de .env.example con contraseñas aleatorias para el entorno local.
# No sobrescribe un .env existente y nunca imprime los secretos.
set -euo pipefail

cd "$(dirname "$0")/.."

if [[ -e .env ]]; then
    echo ".env ya existe; no se toca."
    exit 0
fi

random_secret() {
    # 48 caracteres hexadecimales: cumplen la validación de provision-users.sh
    od -An -N24 -tx1 /dev/urandom | tr -d ' \n'
}

content="$(cat .env.example)"
while [[ "$content" == *CHANGE_ME* ]]; do
    secret="$(random_secret)"
    content="${content/CHANGE_ME/$secret}"
done

# Valores propios del entorno local (en producción se usa .env.example tal cual).
content="${content/APP_ENV=production/APP_ENV=local}"
content="${content/APP_DEBUG=false/APP_DEBUG=true}"
content="${content/APP_URL=https:\/\/tikarochat.example/APP_URL=http://localhost:8080}"
content="${content/LOG_LEVEL=warning/LOG_LEVEL=debug}"
content="${content/SESSION_SECURE_COOKIE=true/SESSION_SECURE_COOKIE=false}"

umask 077
printf '%s\n' "$content" > .env
echo ".env creado con contraseñas nuevas."
