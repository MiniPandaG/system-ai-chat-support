#!/usr/bin/env bash
# Crea .env a partir de .env.example con contraseñas aleatorias. No sobrescribe un .env existente y nunca imprime los secretos.
#
#   bash scripts/make-env.sh                  # entorno local
#   APP_URL=https://soporte.miempresa.com TRUSTED_PROXIES=172.16.0.0/12 bash scripts/make-env.sh production
#
# En producción deja APP_ENV=production y APP_DEBUG=false, genera la llave de la aplicación, apunta la base de datos a la
# autoridad certificadora de scripts/make-db-certs.sh y exige APP_URL con https y TRUSTED_PROXIES (la red del proxy).
set -euo pipefail

cd "$(dirname "$0")/.."

MODE="${1:-local}"

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

if [[ "$MODE" == production ]]; then
    : "${APP_URL:?Define APP_URL con la dirección pública (https://...)}"
    : "${TRUSTED_PROXIES:?Define TRUSTED_PROXIES con la red del proxy (por ejemplo la red de Docker)}"
    if [[ "$APP_URL" != https://* ]]; then
        echo "APP_URL debe empezar con https:// en producción." >&2
        exit 1
    fi
    app_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    content="${content/APP_URL=https:\/\/geckochat.net/APP_URL=$APP_URL}"
    content="${content/TRUSTED_PROXIES=/TRUSTED_PROXIES=$TRUSTED_PROXIES}"
    content="${content/MYSQL_ATTR_SSL_CA=/MYSQL_ATTR_SSL_CA=\/etc\/ssl\/gecko\/ca.pem}"
    content="${content/APP_KEY=/APP_KEY=$app_key}"
else
    # Valores propios del entorno local (en producción se usa .env.example tal cual).
    content="${content/APP_ENV=production/APP_ENV=local}"
    content="${content/APP_DEBUG=false/APP_DEBUG=true}"
    content="${content/APP_URL=https:\/\/geckochat.net/APP_URL=http://127.0.0.1:8080}"
    content="${content/LOG_LEVEL=warning/LOG_LEVEL=debug}"
    content="${content/SESSION_SECURE_COOKIE=true/SESSION_SECURE_COOKIE=false}"
    content="${content/MAIL_MAILER=log/MAIL_MAILER=smtp}"
    content="${content/MAIL_HOST=127.0.0.1/MAIL_HOST=mailpit}"
    content="${content/MAIL_PORT=2525/MAIL_PORT=1025}"
fi

umask 077
printf '%s\n' "$content" > .env
echo ".env creado con contraseñas nuevas ($MODE)."
