# appwrite-server

Appwrite Rust HTTP server binary.

Currently a **minimal Hyper health stub** (`GET /v1/health`) wired through `utopia-http` and `appwrite-platform`.

## Run

```bash
cargo run -p appwrite-server
# or
APPWRITE_BIND=127.0.0.1:8080 cargo run -p appwrite-server
```

## Docker

```bash
docker build -t appwrite-server -f apps/server/Dockerfile .
```

## Status

Stub only. Full API surface is not implemented.
