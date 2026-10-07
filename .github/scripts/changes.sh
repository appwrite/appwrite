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

# Assigned first so a failing diff stops the script instead of reporting no
# changes; without renames, a move out of the server still counts its source.
files=$(git diff --name-only --no-renames "$base" HEAD)

server=false
console=false
while read -r file; do
  case "$file" in
    '') ;;
    .github/scripts/changes.sh | .github/gcrunner.yml)
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
done <<< "$files"

echo "server=$server" >> "$GITHUB_OUTPUT"
echo "console=$console" >> "$GITHUB_OUTPUT"
echo "server=$server console=$console (base $base)"
