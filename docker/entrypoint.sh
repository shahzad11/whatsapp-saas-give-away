#!/bin/sh
set -e

# Fail fast and loudly on missing secrets rather than booting a misconfigured,
# silently insecure app.
: "${BACKEND_API_KEY:?BACKEND_API_KEY must be set}"
: "${MYSQL_USER:?MYSQL_USER must be set}"
: "${MYSQL_PASSWORD:?MYSQL_PASSWORD must be set}"

if [ "${#BACKEND_API_KEY}" -lt 32 ]; then
  echo "FATAL: BACKEND_API_KEY must be at least 32 characters" >&2
  exit 1
fi

chown -R www-data:www-data /var/lib/php/sessions "${APP_ROOT}/logs" 2>/dev/null || true

php /usr/local/bin/bootstrap.php

exec "$@"
