#!/usr/bin/env sh
set -eu

GITEA_URL="${GITEA_URL:-http://gitea:3000}"
ADMIN_USER="${GITEA_ADMIN_USER:-appwrite}"
ADMIN_PASSWORD="${GITEA_ADMIN_PASSWORD:-password}"
ADMIN_EMAIL="${GITEA_ADMIN_EMAIL:-appwrite@localhost.test}"

until wget -q -O /dev/null "${GITEA_URL}/api/healthz"; do
  echo "Waiting for Gitea at ${GITEA_URL}"
  sleep 1
done

su-exec git gitea admin user create \
  --admin \
  --username "${ADMIN_USER}" \
  --password "${ADMIN_PASSWORD}" \
  --email "${ADMIN_EMAIL}" \
  --must-change-password=false \
  --config /data/gitea/conf/app.ini || true

echo "Gitea test fixture is ready"
