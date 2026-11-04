#!/usr/bin/env bash
# Prueba de restauración (la mensual de RNF-13): restaura una copia en una base y una carpeta de prueba, compara con lo vivo y
# lo borra todo al terminar. Imprime una línea para anotar en el registro de restauraciones.
#
#   BACKUP_PASSPHRASE='...' bash scripts/restore-check.sh --fresh     # hace una copia nueva y la restaura (comparación exacta)
#   BACKUP_PASSPHRASE='...' bash scripts/restore-check.sh             # restaura la última copia que haya (los conteos vivos pueden ser mayores)
#
# Qué compara: el número de filas de cada tabla y la lista de archivos con sus huellas. No toca la base ni los archivos vivos.
set -euo pipefail

cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1

: "${BACKUP_PASSPHRASE:?Define BACKUP_PASSPHRASE}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
EXACT=false

if [ "${1:-}" = "--fresh" ]; then
    bash scripts/backup.sh >/dev/null
    EXACT=true
fi

SOURCE="$(find "$BACKUP_DIR" -maxdepth 1 -type d -name 'gecko-*' | sort | tail -n1)"
[ -n "$SOURCE" ] || { echo "No hay copias en $BACKUP_DIR." >&2; exit 1; }

STAMP="$(date -u +%Y%m%d%H%M%S)"
SCRATCH_DB="restorecheck_$STAMP"
SCRATCH_FILES="$BACKUP_DIR/restorecheck-$STAMP"

cleanup() {
    docker compose exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e 'DROP DATABASE IF EXISTS \`$SCRATCH_DB\`'" 2>/dev/null || true
    rm -rf "$SCRATCH_FILES"
}
trap cleanup EXIT

START="$(date +%s)"
bash scripts/restore.sh "$SOURCE" "$SCRATCH_DB" "$SCRATCH_FILES" >/dev/null
SECONDS_TAKEN=$(( $(date +%s) - START ))

counts() {
    docker compose exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -N -e \"SELECT table_name FROM information_schema.tables WHERE table_schema='$1' AND table_type='BASE TABLE' ORDER BY 1\"" 2>/dev/null | tr -d '\r' \
        | while read -r table; do
            rows="$(docker compose exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -N -e 'SELECT COUNT(*) FROM \`$1\`.\`$table\`'" 2>/dev/null </dev/null | tr -d '\r ')"
            echo "$table $rows"
        done
}

LIVE_DB="$(docker compose exec -T mysql sh -c 'printf %s "$MYSQL_DATABASE"' | tr -d '\r')"
LIVE_COUNTS="$(counts "$LIVE_DB")"
RESTORED_COUNTS="$(counts "$SCRATCH_DB")"

DIFFS=0
TABLES=0
ROWS=0
while read -r table live; do
    restored="$(printf '%s\n' "$RESTORED_COUNTS" | awk -v t="$table" '$1 == t { print $2 }')"
    TABLES=$((TABLES + 1))
    ROWS=$((ROWS + ${restored:-0}))
    if [ "$EXACT" = true ] && [ "$live" != "$restored" ]; then
        echo "DIFERENCIA en $table: vivo $live, restaurado ${restored:-falta}"
        DIFFS=$((DIFFS + 1))
    elif [ "$EXACT" = false ] && [ "${restored:-0}" -gt "$live" ]; then
        echo "DIFERENCIA en $table: la copia tiene más filas ($restored) que lo vivo ($live)"
        DIFFS=$((DIFFS + 1))
    fi
done <<< "$LIVE_COUNTS"

# Huella y ruta de cada archivo, en el mismo formato en este equipo y dentro del contenedor (sha256sum de Windows añade «*»).
LISTING='find . -type f ! -path "./exports/*" -print0 | sort -z | xargs -0 -r sha256sum | sed -E "s#^([0-9a-f]{64}) [ *](\./)?#\1  #"'

LIVE_FILES="$(docker compose exec -T app sh -c "cd storage/app/private && $LISTING" </dev/null | tr -d '\r')"
RESTORED_FILES="$(cd "$SCRATCH_FILES" && sh -c "$LISTING")"
FILES="$(printf '%s' "$RESTORED_FILES" | grep -c . || true)"

if [ "$EXACT" = true ] && [ "$LIVE_FILES" != "$RESTORED_FILES" ]; then
    echo "DIFERENCIA en los archivos: la lista o las huellas no coinciden"
    DIFFS=$((DIFFS + 1))
fi
RESULT="CORRECTA"
[ "$DIFFS" -eq 0 ] || RESULT="CON DIFERENCIAS ($DIFFS)"
echo
echo "Restauración $RESULT | copia: $(basename "$SOURCE") | tablas: $TABLES | filas restauradas: $ROWS | archivos: $FILES | duración: ${SECONDS_TAKEN}s | comparación: $([ "$EXACT" = true ] && echo exacta || echo 'con la copia más reciente') | $(date -u +%Y-%m-%d)"
[ "$DIFFS" -eq 0 ]
