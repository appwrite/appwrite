#!/bin/sh
# Builds target/release/libutopia.so inside appwrite/base (Alpine), from the directory that holds
# Cargo.toml. The one recipe for the image: this repository's Dockerfile runs it, and so does
# appwrite-labs/cloud's, over its vendored copy. A new system dependency is added here only.
# Alpine's own Rust is older than the workspace needs, so rustup installs rust-toolchain.toml's.
set -eu

apk add --no-cache rustup clang-dev libheif-dev build-base
rustup-init -y --default-toolchain none
. "$HOME/.cargo/env"
rustup toolchain install
cargo build --release --locked -p utopia-extension
