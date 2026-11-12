#!/usr/bin/env bash
set -euo pipefail

# Extract, never execute or rebuild, the two images that the manifest references.
# docker create supports the non-native platform without QEMU.
container=""
trap 'if [[ -n "$container" ]]; then docker rm "$container" >/dev/null; fi' EXIT
for arch in amd64 arm64; do
  if [[ "$arch" == amd64 ]]; then digest="$DIGEST_AMD64"; else digest="$DIGEST_ARM64"; fi
  if [[ ! "$digest" =~ ^sha256:[a-f0-9]{64}$ ]]; then
    echo "Missing or invalid $arch image digest" >&2
    exit 1
  fi
  image="$REGISTRY_GITHUB/$IMAGE_NAME@$digest"
  docker pull --platform "linux/$arch" "$image"
  container=$(docker create --platform "linux/$arch" "$image")
  mkdir -p "image-assets/$arch"
  docker cp "$container:/app/dist/client/." "image-assets/$arch/"
  docker rm "$container" >/dev/null
  container=""
done
