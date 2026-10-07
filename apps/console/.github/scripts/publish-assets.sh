#!/bin/sh
set -eu

: "${R2_BUCKET:?Set R2_BUCKET}"
: "${RCLONE_CONFIG_R2_ACCESS_KEY_ID:?Set the R2 access key}"
: "${RCLONE_CONFIG_R2_SECRET_ACCESS_KEY:?Set the R2 secret key}"

# Combine the exact image outputs locally before uploading. rclone rejects
# different bytes at the same path instead of silently choosing an architecture.
merged=$(mktemp -d)
trap 'rm -rf "$merged"' EXIT
for arch in amd64 arm64; do
  test -d "image-assets/$arch/assets"
  rclone copy "image-assets/$arch" "$merged" \
    --filter-from .github/scripts/static-assets.filter --checksum --immutable
done

# Only content-hashed build assets are shared between environments.
# copy retains old chunks for open tabs and rollback.
# --no-traverse: the bucket keeps every chunk ever deployed, so building the
# destination listing takes minutes and grows with each deploy; HEADing the
# few thousand source paths instead is fast and stays constant.
rclone copy "$merged" "r2:$R2_BUCKET" --checksum --immutable --metadata \
  --no-traverse --checkers 32 \
  --transfers 32 --stats 30s --stats-one-line --stats-log-level NOTICE \
  --metadata-set 'cache-control=public, max-age=31536000, immutable'
