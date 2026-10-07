#!/usr/bin/env bash
set -euo pipefail

# Writes server=<bool> and console=<bool> to $GITHUB_OUTPUT from the files a run
# changed. Manual runs and release branches always run everything.

if [ "$EVENT" = "workflow_dispatch" ] || [[ "$REF" == refs/heads/*.x ]]; then
  echo 'server=true' >> "$GITHUB_OUTPUT"
  echo 'console=true' >> "$GITHUB_OUTPUT"
  exit 0
fi

if [ "$EVENT" = "pull_request" ]; then
  base=$(git merge-base "origin/$BASE_REF" HEAD)
elif git rev-parse -q --verify "$BEFORE^{commit}" > /dev/null; then
  base="$BEFORE"
else
  base=$(git rev-parse HEAD~1)
fi

server=false
console=false
while read -r file; do
  case "$file" in
    '') ;;
    .github/scripts/changes.sh)
      server=true
      console=true
      ;;
    apps/console/* | .github/workflows/console*.yml | .github/scripts/console/*)
      console=true
      ;;
    apps/*) ;;
    *)
      server=true
      ;;
  esac
done <<< "$(git diff --name-only "$base" HEAD)"

echo "server=$server" >> "$GITHUB_OUTPUT"
echo "console=$console" >> "$GITHUB_OUTPUT"
echo "server=$server console=$console (base $base)"
