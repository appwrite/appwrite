#!/bin/sh
# Prints the appwrite/utopia-extension tag that holds the extension built from this tree: a hash
# of everything the build reads. The Extension image workflow publishes under it, and a consumer
# that has the source but should not compile it (appwrite-labs/cloud, over its vendored copy) runs
# this to learn which image to copy utopia.so from. Two trees with the same inputs share a tag, so
# a commit that leaves them alone needs no new image.
#
# The inputs are the Cargo workspace and the base image the Dockerfile builds it on. The rest of
# the Dockerfile does not reach the library, so editing it does not move the tag.
set -eu

cd "$(dirname "$0")/../.."

if command -v sha256sum > /dev/null; then
    sum=sha256sum
else
    sum="shasum -a 256" # macOS
fi

{
    grep -m 1 ' AS extension-build$' Dockerfile
    find Cargo.toml Cargo.lock rust-toolchain.toml .cargo crates -type f | LC_ALL=C sort | while read -r file; do
        $sum "$file"
    done
} | $sum | cut -c 1-16 | sed 's/^/build-/'
