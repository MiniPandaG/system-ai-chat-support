#!/usr/bin/env bash
# Restaura una copia hecha con backup.sh en una base de datos NUEVA y en una carpeta NUEVA (RNF-13, RS-20).
#
#   BACKUP_PASSPHRASE='...' bash scripts/restore.sh backups/gecko-AAAAMMDD-HHMMSS geckochat_restore ./restaurado
#
# No pisa nada: se niega si la base de destino ya existe o si la carpeta de destino ya tiene algo. Así una restauración de
# prueba (la mensual de RNF-13) nunca toca producción, y una restauración real se hace a propósito: restaurar a una base
# nueva, comprobarla y solo entonces apuntar la aplicación a ella (ver la guía de operación).
#
# Antes de descifrar comprueba la firma del manifiesto y la huella de cada archivo: una copia alterada se rechaza.
# Variables: BACKUP_PASSPHRASE (la misma de la copia) y COMPOSE_FILE (por defecto compose.yaml).
set -euo pipefail

cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1

SOURCE="${1:?Uso: restore.sh <carpeta de la copia> <base de datos nueva> <carpeta nueva para los archivos>}"
TARGET_DB="${2:?Falta el nombre de la base de datos nueva}"
TARGET_FILES="${3:?Falta la carpeta nueva para los archivos}"
: "${BACKUP_PASSPHRASE:?Define BACKUP_PASSPHRASE (la frase con la que se cifró la copia)}"
ITER=600000

if ! [[ "$TARGET_DB" =~ ^[A-Za-z0-9_]{1,60}$ ]]; then
    echo "El nombre de la base solo admite letras, números y guion bajo." >&2
    exit 1
fi

for file in MANIFEST.txt MANIFEST.hmac db.sql.gz.enc files.tar.gz.enc; do
    [ -f "$SOURCE/$file" ] || { echo "Falta $SOURCE/$file: la copia está incompleta." >&2; exit 1; }
done

echo "== Comprobando la firma y las huellas =="
EXPECTED="$(cat "$SOURCE/MANIFEST.hmac")"
ACTUAL="$(openssl dgst -sha256 -hmac "$BACKUP_PASSPHRASE" "$SOURCE/MANIFEST.txt" | sed 's/^.*= //')"
if [ "$EXPECTED" != "$ACTUAL" ]; then
    echo "La firma del manifiesto no coincide: la frase es otra o la copia fue alterada. No se restaura nada." >&2
    exit 1
fi
for file in db.sql.gz.enc files.tar.gz.enc; do
    WANT="$(grep " $file\$" "$SOURCE/MANIFEST.txt" | cut -d' ' -f1)"
    HAVE="$(sha256sum "$SOURCE/$file" | cut -d' ' -f1)"
    if [ "$WANT" != "$HAVE" ]; then
        echo "La huella de $file no coincide: el archivo fue alterado o se dañó. No se restaura nada." >&2
        exit 1
    fi
done

if [ -e "$TARGET_FILES" ] && [ -n "$(ls -A "$TARGET_FILES" 2>/dev/null)" ]; then
    echo "La carpeta $TARGET_FILES ya tiene contenido. Usa una carpeta nueva." >&2
    exit 1
fi

EXISTS="$(docker compose exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -N -e \"SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$TARGET_DB'\"" 2>/dev/null | tr -d '\r ')"
if [ "$EXISTS" != "0" ]; then
    echo "La base $TARGET_DB ya existe. Usa un nombre nuevo." >&2
    exit 1
fi

decrypt() {
    openssl enc -d -aes-256-cbc -pbkdf2 -iter "$ITER" -pass env:BACKUP_PASSPHRASE
}

echo "== Restaurando la base de datos en $TARGET_DB =="
docker compose exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e 'CREATE DATABASE \`$TARGET_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'" 2>/dev/null
decrypt < "$SOURCE/db.sql.gz.enc" | gunzip | docker compose exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" $TARGET_DB" 2>/dev/null

echo "== Restaurando los archivos en $TARGET_FILES =="
mkdir -p "$TARGET_FILES"
decrypt < "$SOURCE/files.tar.gz.enc" | gunzip | tar -xf - -C "$TARGET_FILES"

echo "Listo: base $TARGET_DB y archivos en $TARGET_FILES. El índice de conocimiento no se restaura: se rehace con «php artisan knowledge:reindex»."
