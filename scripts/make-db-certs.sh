#!/usr/bin/env bash
# Genera la autoridad y el certificado del servidor MySQL para la conexión cifrada de producción (RS-10).
#
#   bash scripts/make-db-certs.sh        # crea ./certs con ca.pem, server-cert.pem y server-key.pem
#
# El certificado del servidor vale para el nombre «mysql» (el servicio de Docker) y dura 825 días: hay que renovarlo antes.
# La aplicación recibe solo ca.pem (MYSQL_ATTR_SSL_CA) y comprueba que el servidor es el que firmó esa autoridad; MySQL recibe
# el certificado y la llave del servidor. La llave de la autoridad se borra al terminar: si hay que renovar, se genera todo de nuevo.
# No sobrescribe: si ./certs ya existe, se niega.
set -euo pipefail

cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1

DIR="${1:-certs}"

if [ -e "$DIR" ]; then
    echo "$DIR ya existe: bórralo a propósito si quieres renovar los certificados." >&2
    exit 1
fi

umask 077
mkdir -p "$DIR"
cd "$DIR"

openssl genrsa -out ca-key.pem 4096 2>/dev/null
openssl req -new -x509 -nodes -days 3650 -key ca-key.pem -subj "/CN=GeckoChat DB CA" -out ca.pem

openssl genrsa -out server-key.pem 2048 2>/dev/null
openssl req -new -key server-key.pem -subj "/CN=mysql" -out server.csr
printf 'subjectAltName=DNS:mysql,DNS:localhost\nextendedKeyUsage=serverAuth\n' > server.ext
openssl x509 -req -in server.csr -CA ca.pem -CAkey ca-key.pem -CAcreateserial -days 825 -extfile server.ext -out server-cert.pem 2>/dev/null

rm -f ca-key.pem ca.srl server.csr server.ext

# MySQL corre con otro usuario dentro del contenedor y debe poder leer su llave; los certificados quedan fuera del repositorio
# (./certs está en .gitignore) y la carpeta solo la lee quien administra el servidor.
chmod 0644 ca.pem server-cert.pem server-key.pem

echo "Listo: $DIR/ca.pem, $DIR/server-cert.pem y $DIR/server-key.pem."
