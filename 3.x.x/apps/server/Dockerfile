# Build from the 3.x.x workspace root:
#   docker build -t appwrite-rust:local -f apps/server/Dockerfile .
FROM rust:1.97-bookworm AS builder
WORKDIR /src
RUN apt-get update \
    && apt-get install -y --no-install-recommends pkg-config libssl-dev \
    && rm -rf /var/lib/apt/lists/*
COPY . .
RUN cargo build --release -p appwrite-server

FROM debian:bookworm-slim
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl libssl3 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=builder /src/target/release/appwrite-server /usr/local/bin/appwrite-server
ENV APPWRITE_BIND=0.0.0.0:80
EXPOSE 80
CMD ["appwrite-server"]
