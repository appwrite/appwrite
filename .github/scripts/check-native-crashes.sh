#!/usr/bin/env bash
set -euo pipefail

image="${1:-${IMAGE:-}}"
pattern='zend_mm_heap|Segmentation fault|core dumped|signal[ =](6|7|11)\b|exit code 139\b|exited abnormally: signal=[1-9]'

if [ -z "$image" ]; then
    echo '::error::No appwrite image given; pass it as the first argument or set IMAGE'
    exit 1
fi

configuration=$(docker compose config --format json)
names=$(jq -r --arg image "$image" '.services | to_entries[] | select(.value.image == $image) | .key' <<< "$configuration")

if [ -z "$names" ]; then
    echo "::error::No appwrite services matched image ${image}"
    exit 1
fi

services=()
while IFS= read -r name; do
    services+=("$name")
done <<< "$names"
echo "Scanning: ${services[*]}"

logs=$(docker compose logs --no-color "${services[@]}" 2>&1)

status=0
matches=$(grep -E -- "$pattern" <<< "$logs") || status=$?

case "$status" in
    0)
        echo "$matches"
        echo '::error::Native crash in appwrite container logs'
        exit 1
        ;;
    1)
        echo 'No native crashes found'
        ;;
    *)
        echo "::error::Scanning appwrite container logs failed with status ${status}"
        exit "$status"
        ;;
esac
