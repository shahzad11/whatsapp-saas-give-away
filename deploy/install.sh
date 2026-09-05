#!/usr/bin/env bash
#
# One-command install of the WhatsApp SaaS stack onto any Docker host.
#
#   ./deploy/install.sh                          # prompts for the domain
#   ./deploy/install.sh example.com              # app served at app.example.com
#   ./deploy/install.sh example.com admin@example.com
#
# The app is published at <subdomain>.<domain> — `app` by default, so a domain
# of example.com yields https://app.example.com. Override with APP_SUBDOMAIN,
# or set APP_SUBDOMAIN="" to serve the apex domain itself. A host that already
# carries the subdomain (app.example.com, srv123.hstgr.cloud with
# APP_SUBDOMAIN=srv123) is used verbatim rather than prefixed twice.
#
# Nothing about the host is baked into an image: the domain only ever reaches
# the stack through .env, which is why the same repo deploys to any number of
# servers and domains.
#
# Generates every secret, writes .env (0600), and brings the stack up. Safe to
# re-run: an existing .env is reused, never regenerated, so restarting the
# stack cannot silently orphan the database volume behind a new password.

set -euo pipefail

DOMAIN_INPUT="${1:-}"
ADMIN_EMAIL="${2:-}"
APP_SUBDOMAIN="${APP_SUBDOMAIN-app}"
BUILD_FROM_SOURCE="${BUILD_FROM_SOURCE:-false}"
# Parent of the per-deployment directory. Each deployment lives in its own
# directory named after the host it serves, so several stacks on one box stay
# legible and independent.
DEPLOY_ROOT="${DEPLOY_ROOT:-/opt}"
RELOCATE="${RELOCATE:-true}"

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

need() { command -v "$1" >/dev/null 2>&1 || { echo "FATAL: $1 is required" >&2; exit 1; }; }
need docker
need openssl
need tar

if ! docker compose version >/dev/null 2>&1; then
  echo "FATAL: docker compose v2 is required" >&2
  exit 1
fi

# --- Domain -----------------------------------------------------------------

# An existing deployment already knows its host; don't re-ask for it.
EXISTING_HOST=""
if [[ -f .env ]]; then
  EXISTING_HOST="$(sed -n 's/^APP_HOST=["'"'"']\{0,1\}\([^"'"'"']*\).*/\1/p' .env | head -n1)"
fi

HOST_IS_VERBATIM=false
if [[ -z "$DOMAIN_INPUT" && -n "$EXISTING_HOST" ]]; then
  # An existing .env already holds a fully-qualified host. Take it as-is: it may
  # predate the app.<domain> convention (e.g. srv1931558.hstgr.cloud), and
  # prefixing it would repoint a live deployment at a name with no certificate.
  DOMAIN_INPUT="$EXISTING_HOST"
  HOST_IS_VERBATIM=true
fi

if [[ -z "$DOMAIN_INPUT" ]]; then
  if [[ ! -t 0 ]]; then
    echo "Usage: $0 <domain> [admin-email]" >&2
    echo "Example: $0 example.com admin@example.com  ->  https://app.example.com" >&2
    exit 1
  fi
  echo "The app will be published at ${APP_SUBDOMAIN:+${APP_SUBDOMAIN}.}<domain> over HTTPS."
  echo "That name must already resolve to this server, or Let's Encrypt cannot issue."
  read -r -p "Domain (e.g. example.com): " DOMAIN_INPUT
fi

# Hostnames are case-insensitive; normalise so the Traefik rule, the directory
# name and the certificate all agree.
DOMAIN_INPUT="$(printf '%s' "$DOMAIN_INPUT" | tr '[:upper:]' '[:lower:]' | tr -d '[:space:]')"
DOMAIN_INPUT="${DOMAIN_INPUT#http://}"
DOMAIN_INPUT="${DOMAIN_INPUT#https://}"
DOMAIN_INPUT="${DOMAIN_INPUT%%/*}"

if [[ ! "$DOMAIN_INPUT" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]]; then
  echo "FATAL: '$DOMAIN_INPUT' is not a valid domain name" >&2
  exit 1
fi

if [[ "$HOST_IS_VERBATIM" == "true" || -z "$APP_SUBDOMAIN" || "$DOMAIN_INPUT" == "${APP_SUBDOMAIN}."* ]]; then
  APP_HOST="$DOMAIN_INPUT"
else
  APP_HOST="${APP_SUBDOMAIN}.${DOMAIN_INPUT}"
fi

# Used only for default addresses; strip the leading app label back off.
APP_DOMAIN="$DOMAIN_INPUT"
if [[ -n "$APP_SUBDOMAIN" && "$APP_DOMAIN" == "${APP_SUBDOMAIN}."* ]]; then
  APP_DOMAIN="${APP_DOMAIN#"${APP_SUBDOMAIN}."}"
fi

if [[ -n "$EXISTING_HOST" && "$EXISTING_HOST" != "$APP_HOST" ]]; then
  echo "FATAL: .env already targets ${EXISTING_HOST}, refusing to repoint it to ${APP_HOST}." >&2
  echo "       Edit APP_HOST in .env by hand, or deploy ${APP_HOST} from its own directory." >&2
  exit 1
fi

# Compose project names and Docker resource names allow neither dots nor
# uppercase, so derive a slug. Existing deployments have no STACK_NAME in .env
# and fall back to the historical `whatsapp-saas` names, which must not change
# or they would orphan their volumes.
STACK_NAME="$(printf '%s' "$APP_HOST" | tr -c 'a-z0-9' '-' | sed 's/-\{2,\}/-/g; s/^-//; s/-$//')"

# --- Per-domain deployment directory ----------------------------------------

DEPLOY_DIR="${DEPLOY_DIR:-${DEPLOY_ROOT}/${APP_HOST}}"

# Move a fresh checkout into its per-domain home and continue from there. Only
# ever on a fresh install: once .env exists this *is* the deployment, and
# copying it elsewhere would fork the stack away from its volumes.
if [[ "$RELOCATE" == "true" && "$REPO_ROOT" != "$DEPLOY_DIR" && ! -f .env && -z "${WA_RELOCATED:-}" ]]; then
  if mkdir -p "$DEPLOY_DIR" 2>/dev/null; then
    echo "==> Installing into ${DEPLOY_DIR}"
    # tar rather than cp -a: the working tree may carry a multi-gigabyte
    # Baileys data/ directory and node_modules, neither of which belongs in a
    # deployment.
    tar -cf - \
      --exclude='./.git' \
      --exclude='./node_modules' \
      --exclude='./backend-node/node_modules' \
      --exclude='./data' \
      --exclude='./backend-node/data' \
      --exclude='./logs' \
      --exclude='./*.md' \
      . | (cd "$DEPLOY_DIR" && tar -xf -)
    export WA_RELOCATED=1
    exec "$DEPLOY_DIR/deploy/install.sh" "$DOMAIN_INPUT" "$ADMIN_EMAIL"
  fi
  echo "==> ${DEPLOY_DIR} is not creatable — deploying from ${REPO_ROOT} instead"
fi

# --- Environment ------------------------------------------------------------

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
APP_DOMAIN="${APP_DOMAIN}"
APP_NAME="WhatsApp SaaS"

# Names every Docker resource and the Traefik router, so two deployments on one
# host cannot collide. Changing it after the first boot orphans the volumes.
STACK_NAME="${STACK_NAME}"
COMPOSE_PROJECT_NAME="${STACK_NAME}"

# Internal shared secret between the frontend and backend containers — not an
# external API key. The backend refuses to boot without it.
BACKEND_API_KEY="$(openssl rand -hex 32)"

# Encrypts secrets at rest (SMTP password, LLM API keys). Generated here so the
# .env is complete: without it the crypto layer falls back to BACKEND_API_KEY,
# and rotating that would silently make every stored secret undecryptable.
# Rotating APP_SECRET_KEY itself has the same effect, so treat it as permanent.
APP_SECRET_KEY="$(openssl rand -hex 32)"

MYSQL_ROOT_PASSWORD="$(openssl rand -hex 24)"
MYSQL_PASSWORD="$(openssl rand -hex 24)"
MYSQL_DATABASE="whatsapp_saas"
MYSQL_USER="wa_app"

ADMIN_EMAIL="${ADMIN_EMAIL:-admin@${APP_DOMAIN}}"
ADMIN_PASSWORD="${ADMIN_PASSWORD}"
ADMIN_NAME="Administrator"

ALLOW_REGISTRATION="false"
SESSION_SECURE="true"
DEV_MODE="false"
DEFAULT_PLAN_CODE="free"
LOG_LEVEL="info"
MAIL_FROM="noreply@${APP_DOMAIN}"
EOF
  chmod 600 .env

  echo
  echo "    App URL:        https://${APP_HOST}/"
  echo "    Admin login:    ${ADMIN_EMAIL:-admin@${APP_DOMAIN}}"
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
echo "    Deployment directory: $(pwd)"
docker compose "${COMPOSE_ARGS[@]}" ps
