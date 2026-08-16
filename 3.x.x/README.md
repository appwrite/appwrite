# Appwrite 3.x (Rust)

Rust workspace for the Appwrite 3.x server rewrite. Utopia domain crates live under `crates/utopia-*`. Appwrite product crates live under `crates/appwrite-*`. The runnable HTTP binary is `apps/server`.

## Layout

| Path | Role |
|------|------|
| `crates/utopia-*` | Domain building blocks ported from Utopia PHP (DI, HTTP, database, auth, …). Treat as libraries; do not fold Appwrite product logic into them. |
| `crates/appwrite-*` | Appwrite-specific layers (exceptions, hooks, locale, auth, events, response models, database helpers, platform composition). Currently stubs. |
| `apps/server` | Binary crate (`appwrite-server`). Minimal Hyper health endpoint for now. |

### Appwrite crates

| Crate | Purpose |
|-------|---------|
| `appwrite-exception` | Error types |
| `appwrite-hooks` | Lifecycle hooks |
| `appwrite-locale` | Locale helpers |
| `appwrite-auth` | Auth on `utopia-auth` / `utopia-validators` |
| `appwrite-event` | Event envelopes |
| `appwrite-response` | API response models |
| `appwrite-database` | Database helpers on `utopia-database` |
| `appwrite-platform` | Platform composition on Utopia + other `appwrite-*` crates |

## Build and test

From this directory (`3.x.x/`):

```bash
# Default member is apps/server
cargo build

# Individual stubs
cargo check -p appwrite-exception -p appwrite-hooks -p appwrite-locale
cargo check -p appwrite-auth -p appwrite-event -p appwrite-response
cargo check -p appwrite-database -p appwrite-platform -p appwrite-server

# Tests / benches (stubs)
cargo test -p appwrite-exception
cargo bench -p appwrite-exception --bench smoke

# Run the health stub
APPWRITE_BIND=127.0.0.1:8080 cargo run -p appwrite-server
```

Docker (from this directory):

```bash
docker build -t appwrite-server -f apps/server/Dockerfile .
```

## Notes

- Workspace metadata (`version`, `edition`, lints) is inherited via `*.workspace = true`.
- Do not modify `utopia-*` sources when working on Appwrite stubs unless you are intentionally syncing Utopia.
- Toolchain: see `rust-toolchain.toml` (Rust 1.97.1).
