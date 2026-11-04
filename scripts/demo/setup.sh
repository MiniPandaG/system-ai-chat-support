#!/usr/bin/env bash
# Prepara la empresa de ejemplo para las pruebas de usabilidad (docs/09): Zapatería Ejemplo, con datos inventados, un
# administrador (con 2FA ya activo), un agente y el widget permitido en http://localhost:9090. Solo para local o pruebas.
# Imprime una vez las credenciales: quien conduce la sesión las entrega a los participantes.
set -euo pipefail

cd "$(dirname "$0")/../.."
export MSYS_NO_PATHCONV=1

echo "== Servicios necesarios (cola, conocimiento, antivirus y correo de prueba) =="
docker compose --profile knowledge --profile scan up -d queue reverb qdrant embeddings clamav mailpit

echo "== Creando la empresa de ejemplo =="
STATE="$(docker compose exec -T app php scripts/demo/seed.php crear)"

field() {
    printf '%s' "$STATE" | php -r '$j = json_decode(stream_get_contents(STDIN), true); echo $j[$argv[1]];' "$1"
}

cat <<EOF

Listo. Guarda esto ahora (no se vuelve a mostrar):

  Panel (administrador): http://127.0.0.1:8080/login
    correo:     $(field admin_email)
    contraseña: $(field admin_password)
    2FA:        el código sale de: bash scripts/demo/otp.sh

  Panel (agente, sin 2FA):
    correo:     $(field agent_email)
    contraseña: $(field agent_password)

  Widget del cliente final: node scripts/widget-demo/serve.mjs  y abrir http://localhost:9090
    llave pública de la empresa: $(field widget_key)

  Correos (la encuesta de la tarea T8 llega aquí): http://127.0.0.1:8025

Al terminar todas las sesiones: bash scripts/demo/teardown.sh
EOF
