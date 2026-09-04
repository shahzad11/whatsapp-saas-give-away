#!/usr/bin/env bash
#
# One-command install of the WhatsApp SaaS stack onto any Docker host.
#
#   ./deploy/install.sh srv1931558.hstgr.cloud admin@example.com
#
# Generates every secret, writes .env (0600), and brings the stack up. Safe to
# re-run: an existing .env is reused, never regenerated, so restarting the
# stack cannot silently orphan the database volume behind a new password.

set -euo pipefail

APP_HOST="${1:-}"
ADMIN_EMAIL="${2:-}"
BUILD_FROM_SOURCE="${BUILD_FROM_SOURCE:-false}"

if [[ -z "$APP_HOST" ]]; then
  echo "Usage: $0 <app-host> [admin-email]" >&2
  echo "Example: $0 srv1931558.hstgr.cloud admin@example.com" >&2
  exit 1
fi

cd "$(dirname "$0")/.."

need() { command -v "$1" >/dev/null 2>&1 || { echo "FATAL: $1 is required" >&2; exit 1; }; }
need docker
need openssl

if ! docker compose version >/dev/null 2>&1; then
  echo "FATAL: docker compose v2 is required" >&2
  exit 1
fi

if [[ -f .env ]]; then
  echo "==> .env already exists — reusing it (delete it to start fresh)"
else
  echo "==> Generating secrets"
  ADMIN_PASSWORD="$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)"

  # Every value is quoted. Compose tolerates bare spaces, but operators do
  # `set -a; . ./.env` all the time, and an unquoted `APP_NAME=WhatsApp SaaS`
  # makes that blow up with "SaaS: command not found".
  umask 077
  cat > .env <<EOF
APP_HOST="${APP_HOST}"
APP_NAME="WhatsApp SaaS"

BACKEND_API_KEY="$(openssl rand -hex 32)"
MYSQL_ROOT_PASSWORD="$(openssl rand -hex 24)"
MYSQL_PASSWORD="$(openssl rand -hex 24)"
MYSQL_DATABASE="whatsapp_saas"
MYSQL_USER="wa_app"

ADMIN_EMAIL="${ADMIN_EMAIL:-admin@${APP_HOST}}"
ADMIN_PASSWORD="${ADMIN_PASSWORD}"
ADMIN_NAME="Administrator"

ALLOW_REGISTRATION="false"
SESSION_SECURE="true"
DEV_MODE="false"
DEFAULT_PLAN_CODE="free"
LOG_LEVEL="info"
MAIL_FROM="noreply@${APP_HOST}"
EOF
  chmod 600 .env

  echo
  echo "    Admin login:    ${ADMIN_EMAIL:-admin@${APP_HOST}}"
  echo "    Admin password: ${ADMIN_PASSWORD}"
  echo "    (also stored in .env — save it now, it is not shown again)"
  echo
fi

COMPOSE_ARGS=(-f docker-compose.yml)
if [[ "$BUILD_FROM_SOURCE" == "true" ]]; then
  echo "==> Building images from source"
  COMPOSE_ARGS+=(-f docker-compose.build.yml)
  docker compose "${COMPOSE_ARGS[@]}" build
else
  echo "==> Pulling images"
  docker compose "${COMPOSE_ARGS[@]}" pull
fi

echo "==> Starting stack"
docker compose "${COMPOSE_ARGS[@]}" up -d

echo "==> Waiting for the frontend to become healthy"
for i in $(seq 1 60); do
  if docker compose "${COMPOSE_ARGS[@]}" ps --format json 2>/dev/null | grep -q '"Health":"healthy".*frontend\|frontend.*healthy'; then
    break
  fi
  if curl -fsS -o /dev/null "http://127.0.0.1/health.php" 2>/dev/null; then
    break
  fi
  sleep 2
done

echo
echo "==> Done. https://${APP_HOST}/"
docker compose "${COMPOSE_ARGS[@]}" ps
