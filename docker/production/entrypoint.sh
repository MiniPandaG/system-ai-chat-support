#!/bin/sh
# Prepara las cachés de Laravel con el entorno de ESTE contenedor y arranca el comando. Si una caché falla, el contenedor no
# arranca: es mejor un despliegue rojo que una aplicación a medias.
set -eu

php artisan config:cache --no-ansi --quiet
php artisan route:cache --no-ansi --quiet
php artisan event:cache --no-ansi --quiet
php artisan view:cache --no-ansi --quiet

exec "$@"
