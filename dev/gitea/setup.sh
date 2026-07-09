#!/usr/bin/env sh
set -eu

GITEA_URL="${GITEA_URL:-http://gitea:3000}"
ADMIN_USER="${GITEA_ADMIN_USER:-appwrite}"
ADMIN_PASSWORD="${GITEA_ADMIN_PASSWORD:-password}"
ADMIN_EMAIL="${GITEA_ADMIN_EMAIL:-appwrite@localhost.test}"
# Must match the callback Appwrite actually builds (protocol + consoleHostname +
# /v1/vcs/gitea/callback) -- adjust if your local console hostname differs.
OAUTH_REDIRECT_URI="${GITEA_OAUTH_REDIRECT_URI:-http://localhost:9501/v1/vcs/gitea/callback}"
OAUTH_APP_FILE="/data/gitea-oauth-app.env"

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

# Gitea generates the client_id/client_secret itself -- they can't be pinned to
# a fixed value ahead of time. Create the app once and persist the real
# generated credentials so a developer can copy them into .env manually.
if [ ! -f "${OAUTH_APP_FILE}" ]; then
  RESPONSE=$(wget -q -O - \
    --http-user="${ADMIN_USER}" --http-password="${ADMIN_PASSWORD}" \
    --header="Content-Type: application/json" \
    --post-data="{\"name\":\"appwrite-dev\",\"redirect_uris\":[\"${OAUTH_REDIRECT_URI}\"],\"confidential_client\":true}" \
    "${GITEA_URL}/api/v1/user/applications/oauth2" 2>/dev/null || echo '{}')

  CLIENT_ID=$(echo "${RESPONSE}" | sed -n 's/.*"client_id":"\([^"]*\)".*/\1/p')
  CLIENT_SECRET=$(echo "${RESPONSE}" | sed -n 's/.*"client_secret":"\([^"]*\)".*/\1/p')

  if [ -n "${CLIENT_ID}" ] && [ -n "${CLIENT_SECRET}" ]; then
    {
      echo "_APP_VCS_GITEA_CLIENT_ID=${CLIENT_ID}"
      echo "_APP_VCS_GITEA_CLIENT_SECRET=${CLIENT_SECRET}"
    } > "${OAUTH_APP_FILE}"

    echo "Created a Gitea OAuth2 app for local testing. Copy these into your .env and restart appwrite:"
    cat "${OAUTH_APP_FILE}"
    echo "(redirect_uri registered: ${OAUTH_REDIRECT_URI} -- must match your actual console hostname)"
  else
    echo "Could not create Gitea OAuth2 app automatically; register one via the Gitea UI instead."
  fi
else
  echo "Gitea OAuth2 app already created, reusing:"
  cat "${OAUTH_APP_FILE}"
fi
