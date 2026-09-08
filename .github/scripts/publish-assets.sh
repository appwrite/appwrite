#!/bin/sh
set -eu

: "${R2_BUCKET:?Set R2_BUCKET}"
: "${RCLONE_CONFIG_R2_ACCESS_KEY_ID:?Set the R2 access key}"
: "${RCLONE_CONFIG_R2_SECRET_ACCESS_KEY:?Set the R2 secret key}"

# Combine the exact image outputs locally before uploading. rclone rejects
# different bytes at the same path instead of silently choosing an architecture.
merged=$(mktemp -d)
trap 'rm -rf "$merged"' EXIT
build_id=$(cat image-assets/amd64/asset-build-id.txt)
case "$build_id" in ''|local|*[!A-Za-z0-9_-]*) echo "Invalid asset build ID" >&2; exit 1 ;; esac
[ "$build_id" = "$(cat image-assets/arm64/asset-build-id.txt)" ]
for arch in amd64 arm64; do
  test -d "image-assets/$arch/assets"
  rclone copy "image-assets/$arch" "$merged" \
    --filter-from .github/scripts/static-assets.filter --checksum --immutable
done

# Every file is immutable within this build, including public images and fonts.
# copy retains previous builds for open tabs and rollback.
rclone copy "$merged" "r2:$R2_BUCKET/builds/$build_id" \
  --checksum --immutable --metadata \
  --metadata-set 'cache-control=public, max-age=31536000, immutable'
