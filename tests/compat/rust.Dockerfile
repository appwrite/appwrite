# Toolchain for the Rust compat driver in a Linux container (bin/compat-rust),
# so `compat bench` times Rust on the same kernel and Docker network as the
# PHP driver. Same base as Dockerfile.rust's builder; bin/compat-rust passes
# its RUST_VERSION. The image builds and runs the driver: sources, the cargo
# registry and the target dir are mounted, not copied.
ARG RUST_VERSION=1.88
FROM rust:${RUST_VERSION}-slim-bookworm

# OpenSSL headers for utopia-auth's RSA keys, and libssl3 to run against.
RUN apt-get update \
    && apt-get install -y --no-install-recommends pkg-config libssl-dev \
    && rm -rf /var/lib/apt/lists/*
