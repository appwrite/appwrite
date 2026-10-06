#!/bin/sh
set -eu

su git -c "gitea admin user create --username $GITEA_ADMIN_USERNAME --password $GITEA_ADMIN_PASSWORD --email $GITEA_ADMIN_EMAIL --admin --must-change-password=false" || true

if [ ! -f /data/gitea/oauth.json ]; then
    curl -sf -X POST -u "$GITEA_ADMIN_USERNAME:$GITEA_ADMIN_PASSWORD" \
        -H 'Content-Type: application/json' \
        -d '{"name":"Appwrite","redirect_uris":["http://localhost/v1/vcs/gitea/callback"],"confidential_client":true}' \
        http://gitea:3000/api/v1/user/applications/oauth2 > /tmp/oauth.json
    mv /tmp/oauth.json /data/gitea/oauth.json
fi
