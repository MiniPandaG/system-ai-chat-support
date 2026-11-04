#!/usr/bin/env bash
# Copia de seguridad cifrada de la base de datos y de los archivos privados (RNF-13, RS-20).
#
#   BACKUP_PASSPHRASE='...' bash scripts/backup.sh
#
# Qué guarda: un volcado consistente de MySQL (una sola transacción, sin bloquear la aplicación) y los archivos privados
# (adjuntos, logos y documentos de conocimiento). No guarda Redis (colas y límites, se rehacen solos) ni Qdrant (el
# índice se reconstruye desde la base con «php artisan knowledge:reindex»). Las exportaciones de empresas tampoco entran.
#
# Cómo lo protege: cada archivo se cifra con AES-256 (clave derivada de la frase con PBKDF2) y un manifiesto con la huella
# de cada archivo lleva una firma HMAC-SHA256: restore.sh la comprueba antes de descifrar nada, así que una copia alterada
# se rechaza. La frase vive solo en el entorno de quien ejecuta el script (nunca en el repositorio ni en la copia).
#
# Variables: BACKUP_PASSPHRASE (obligatoria, 20 caracteres o más), BACKUP_DIR (por defecto ./backups),
# BACKUP_KEEP_DAYS (por defecto 14), COMPOSE_FILE (por defecto compose.yaml; en producción, compose.production.yaml).
# La copia se queda en este servidor: llevarla a otro lugar (otro servidor o almacenamiento externo) es parte de la operación.
set -euo pipefail

cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1

: "${BACKUP_PASSPHRASE:?Define BACKUP_PASSPHRASE (frase larga y secreta para cifrar la copia)}"
if [ "${#BACKUP_PASSPHRASE}" -lt 20 ]; then
    echo "BACKUP_PASSPHRASE debe tener al menos 20 caracteres." >&2
    exit 1
fi

BACKUP_DIR="${BACKUP_DIR:-./backups}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
DEST="$BACKUP_DIR/gecko-$STAMP"
ITER=600000

umask 077
mkdir -p "$DEST"

encrypt() {
    openssl enc -aes-256-cbc -pbkdf2 -iter "$ITER" -salt -pass env:BACKUP_PASSPHRASE
}

echo "== Base de datos =="
docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --routines --triggers --hex-blob --no-tablespaces "$MYSQL_DATABASE"' \
    | gzip -9 | encrypt > "$DEST/db.sql.gz.enc"

echo "== Archivos privados =="
docker compose exec -T app sh -c 'cd storage/app/private && tar -cf - --exclude=./exports . 2>/dev/null || true' \
    | gzip -9 | encrypt > "$DEST/files.tar.gz.enc"

echo "== Manifiesto firmado =="
{
    echo "geckochat-backup 1"
    echo "created_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "kdf=pbkdf2-sha256 iter=$ITER cipher=aes-256-cbc"
    for file in db.sql.gz.enc files.tar.gz.enc; do
        echo "$(sha256sum "$DEST/$file" | cut -d' ' -f1)  $(wc -c < "$DEST/$file" | tr -d ' ')  $file"
    done
} > "$DEST/MANIFEST.txt"
openssl dgst -sha256 -hmac "$BACKUP_PASSPHRASE" "$DEST/MANIFEST.txt" | sed 's/^.*= //' > "$DEST/MANIFEST.hmac"

if [ ! -s "$DEST/db.sql.gz.enc" ]; then
    echo "El volcado de la base quedó vacío: la copia no sirve." >&2
    rm -rf "$DEST"
    exit 1
fi

echo "== Copias antiguas (más de $KEEP_DAYS días) =="
find "$BACKUP_DIR" -maxdepth 1 -type d -name 'gecko-*' -mtime "+$KEEP_DAYS" -exec rm -rf {} + 2>/dev/null || true

echo "Listo: $DEST"
