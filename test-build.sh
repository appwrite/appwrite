#!/usr/bin/env bash
#
# End-to-end test of the jobs-service build slice, driven entirely through the
# Appwrite CLI's `push function` — which streams the deployment build logs live,
# exactly as an end user sees them.
#
# Self-contained: bootstraps a throwaway console account + project, logs the CLI
# in, then pushes a tiny Node function twice — so the second build reuses the
# package-manager build cache the first one saved ("Build cache hit").
#
# Optional env:
#   ENDPOINT  Appwrite endpoint (default: http://localhost/v1)
#   FUNC_ID   function id       (default: testbuild)
#   COMMANDS  build command     (default: npm install)
#
set -euo pipefail

ENDPOINT="${ENDPOINT:-http://localhost/v1}"
FUNC_ID="${FUNC_ID:-testbuild}"
COMMANDS="${COMMANDS:-npm install}"
EMAIL="tb-$(date +%s)@example.com"
PASSWORD="password123"

id() { python3 -c 'import sys,json;print(json.load(sys.stdin).get("$id",""))'; }
console() { curl -s -c "$JAR" -b "$JAR" -H "X-Appwrite-Project: console" -H "Content-Type: application/json" "$@"; }

# --- Bootstrap a project owned by a fresh console account (REST) -------------
JAR="$(mktemp)"
console -X POST "$ENDPOINT/account"               -d "{\"userId\":\"unique()\",\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"name\":\"Test\"}" >/dev/null
console -X POST "$ENDPOINT/account/sessions/email" -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}" >/dev/null
TEAM="$(console -X POST "$ENDPOINT/teams" -d '{"teamId":"unique()","name":"t"}' | id)"
PROJECT_ID="$(console -X POST "$ENDPOINT/projects" -d "{\"projectId\":\"unique()\",\"name\":\"p\",\"teamId\":\"$TEAM\",\"region\":\"default\"}" | id)"

# --- Log the CLI in (push uses a session, not an API key) --------------------
appwrite login --email "$EMAIL" --password "$PASSWORD" --endpoint "$ENDPOINT" >/dev/null

# --- Generate a minimal Appwrite project to push -----------------------------
WORK="$(mktemp -d)"
mkdir -p "$WORK/functions/$FUNC_ID"
printf 'module.exports = async ({ res }) => res.json({ ok: true });\n' > "$WORK/functions/$FUNC_ID/main.js"
# A real dependency so `npm install` populates a cache worth reusing.
printf '{ "name": "fn", "version": "1.0.0", "main": "main.js", "dependencies": { "ms": "2.1.3" } }\n' > "$WORK/functions/$FUNC_ID/package.json"
cat > "$WORK/appwrite.json" <<EOF
{
  "projectId": "$PROJECT_ID",
  "functions": [
    {
      "\$id": "$FUNC_ID",
      "name": "$FUNC_ID",
      "runtime": "node-22",
      "execute": ["any"],
      "entrypoint": "main.js",
      "commands": "$COMMANDS",
      "path": "functions/$FUNC_ID"
    }
  ]
}
EOF

# --- Push + stream the build (real user experience) --------------------------
# Push twice against the same function: the first build seeds the build cache,
# the second reuses it (watch for "Build cache hit" in the second run's logs).
cd "$WORK"
echo "Project $PROJECT_ID — first build (cold cache), logs stream below:"
echo
appwrite push function --function-id "$FUNC_ID" --activate --force

echo
echo "Second build (should hit the build cache seeded above):"
echo
appwrite push function --function-id "$FUNC_ID" --activate --force
