#!/usr/bin/env bash
# Comprobaciones posteriores a un despliegue (RS-10, RS-04, RNF-10): lo que las pruebas automáticas no pueden ver porque
# depende del entorno real. Imprime APROBADO o FALLO por cada una y termina con error si alguna falla.
#
#   bash scripts/verify-deploy.sh https://soporte.miempresa.com            # contra el sitio real, por HTTPS
#   COMPOSE_FILE=compose.production.yaml bash scripts/verify-deploy.sh ...  # además, comprueba por dentro (base, cola, planificador)
#
# Con una dirección http:// (ensayo local sin proxy) las comprobaciones de HSTS y de cookies seguras se saltan, y lo dice.
set -uo pipefail

cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1

# En Windows, MSYS_NO_PATHCONV (necesario para docker) hace que curl reciba «/dev/null» tal cual y falle al escribir; curl no lo necesita.
curl() { env -u MSYS_NO_PATHCONV curl "$@"; }

BASE="${1:?Uso: verify-deploy.sh <dirección pública del sitio>}"
BASE="${BASE%/}"
FAILS=0
HTTPS=false
[[ "$BASE" == https://* ]] && HTTPS=true

ok() { echo "APROBADO  $1"; }
bad() { echo "FALLO     $1"; FAILS=$((FAILS + 1)); }
skip() { echo "OMITIDO   $1"; }

headers="$(curl -sS -D - -o /dev/null --max-time 20 "$BASE/login" 2>&1)" || { bad "no se pudo abrir $BASE/login: $headers"; exit 1; }
body="$(curl -sS --max-time 20 "$BASE/login")"

status="$(printf '%s' "$headers" | head -n1 | tr -d '\r')"
[[ "$status" == *" 200"* ]] && ok "el sitio responde: $status" || bad "el sitio no responde 200: $status"

if $HTTPS; then
    printf '%s' "$headers" | grep -qi '^strict-transport-security: .*max-age=' && ok "HSTS presente" || bad "falta Strict-Transport-Security"
    printf '%s' "$headers" | grep -i '^set-cookie:' | grep -qi 'secure' && ok "las cookies llevan Secure" || bad "alguna cookie no lleva Secure"
    curl -sS -o /dev/null --max-time 10 "http://${BASE#https://}/login" -w '%{http_code}' 2>/dev/null | grep -qE '^(301|302|307|308)$' && ok "http redirige a https" || bad "http no redirige a https (¿lo hace el proxy?)"
else
    skip "HSTS, cookies Secure y redirección a https (la dirección es http)"
fi

printf '%s' "$headers" | grep -qi '^content-security-policy: ' && ok "CSP presente" || bad "falta Content-Security-Policy"
csp="$(printf '%s' "$headers" | grep -i '^content-security-policy: ' | tr -d '\r')"
[[ "$csp" == *"unsafe-inline"* || "$csp" == *"unsafe-eval"* ]] && bad "la CSP admite unsafe-inline o unsafe-eval" || ok "la CSP no admite unsafe-inline ni unsafe-eval"
printf '%s' "$headers" | grep -qi '^x-content-type-options: nosniff' && ok "X-Content-Type-Options: nosniff" || bad "falta X-Content-Type-Options"
printf '%s' "$headers" | grep -qi '^referrer-policy: ' && ok "Referrer-Policy presente" || bad "falta Referrer-Policy"
printf '%s' "$headers" | grep -qi '^x-frame-options: ' && ok "X-Frame-Options presente" || bad "falta X-Frame-Options"
printf '%s' "$headers" | grep -qi '^server: .*[0-9]\.[0-9]' && bad "el servidor muestra su versión" || ok "el servidor no muestra su versión"
printf '%s' "$headers" | grep -qi '^x-powered-by: ' && bad "se muestra X-Powered-By" || ok "sin X-Powered-By"

curl -sS -o /dev/null --max-time 10 -w '%{http_code}' "$BASE/up" | grep -q '^200$' && ok "/up responde 200" || bad "/up no responde 200"
[[ "$(curl -sS -o /dev/null --max-time 10 -w '%{http_code}' "$BASE/apps/x/events")" == "404" ]] && ok "la API interna de Reverb (/apps) no se expone" || bad "/apps responde algo distinto de 404"
[[ "$(curl -sS -o /dev/null --max-time 10 -w '%{http_code}' "$BASE/storage/x")" == "404" ]] && ok "/storage no sirve archivos" || bad "/storage responde algo distinto de 404"
[[ "$(curl -sS -o /dev/null --max-time 10 -w '%{http_code}' "$BASE/.env")" == "404" || "$(curl -sS -o /dev/null --max-time 10 -w '%{http_code}' "$BASE/.env")" == "403" ]] && ok "/.env no se sirve" || bad "/.env responde algo inesperado"
[[ "$(curl -sS -o /dev/null --max-time 10 -w '%{http_code}' "$BASE/tickets")" == "302" ]] && ok "el panel exige iniciar sesión" || bad "/tickets no redirige al inicio de sesión"
printf '%s' "$body" | grep -qiE 'stack trace|Whoops|APP_DEBUG' && bad "la página muestra rastros de depuración" || ok "sin rastros de depuración en la página"

if [ -n "${COMPOSE_FILE:-}" ]; then
    echo "-- por dentro ($COMPOSE_FILE) --"
    tls="$(docker compose exec -T app php -r '
        $pdo = new PDO("mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::MYSQL_ATTR_SSL_CA => getenv("MYSQL_ATTR_SSL_CA"), PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true]);
        echo $pdo->query("SHOW SESSION STATUS LIKE \"Ssl_cipher\"")->fetch(PDO::FETCH_NUM)[1] ?? "";' 2>/dev/null | tr -d '\r')"
    [ -n "$tls" ] && ok "la aplicación habla con MySQL cifrado ($tls) y verifica el certificado" || bad "la conexión a MySQL no va cifrada o no verifica el certificado"

    plain="$(docker compose exec -T app php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); echo "conecta"; } catch (Throwable $e) { echo "rechazada"; }' 2>/dev/null | tr -d '\r')"
    [ "$plain" = "rechazada" ] && ok "MySQL rechaza una conexión sin cifrar" || bad "MySQL acepta una conexión sin cifrar"

    ddl="$(docker compose exec -T app php -r 'try { $p = new PDO("mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::MYSQL_ATTR_SSL_CA => getenv("MYSQL_ATTR_SSL_CA")]); $p->exec("CREATE TABLE zz_prueba (id INT)"); echo "creó"; } catch (Throwable $e) { echo "negado"; }' 2>/dev/null | tr -d '\r')"
    [ "$ddl" = "negado" ] && ok "el usuario de la aplicación no puede crear tablas (sin DDL)" || bad "el usuario de la aplicación puede ejecutar DDL"

    docker compose exec -T app php artisan schedule:list 2>/dev/null | grep -q 'attachments:rescan' && ok "el planificador conoce las tareas" || bad "schedule:list no muestra las tareas"
    for service in queue scheduler reverb; do
        [ -n "$(docker compose ps --status running -q "$service" 2>/dev/null)" ] && ok "el servicio $service está corriendo" || bad "el servicio $service no está corriendo"
    done
    docker compose exec -T app php artisan about --only=environment 2>/dev/null | grep -qi 'Debug.*OFF' && ok "APP_DEBUG está apagado" || bad "APP_DEBUG no está apagado"
fi

echo
if [ "$FAILS" -eq 0 ]; then echo "Todo aprobado."; else echo "$FAILS comprobación(es) fallaron."; exit 1; fi
