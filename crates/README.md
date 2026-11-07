# Rust crates

The Rust side of the Appwrite monorepo. See [rfc/rust.md](../rfc/rust.md) for the migration plan and architecture.

| Directory | Holds | Ships as |
|-----------|-------|----------|
| `utopia/<name>` | Generic Utopia libraries (Rust counterparts of `packages/<name>`) | Workspace crates |
| `appwrite/core` | Appwrite domain core: lifecycle, auth, models, events | Workspace crate |
| `appwrite/<service>` | One crate per migrated service (`users`, ...) | Workspace crate |
| `appwrite/server` | The `appwrite-rust` binary | `Dockerfile.rust` image |
| `support/php-std` | PHP engine semantics (type juggling, formatting, JSON, `filter_var`, `parse_url`) shared by Utopia crates | Workspace crate |
| `tools/compat` | `bin/compat`: differential PHP ↔ Rust compatibility tester | Development tool |

Rules:

- **Utopia crates are generic.** They never depend on `appwrite-*` crates and contain no Appwrite domain logic.
- **Create a Utopia crate when a migrated service first needs it, and convert the library in full.** A crate is done when [CONVERSION.md](CONVERSION.md) §2 holds, measured by `bin/compat check <lib>` ([tests/compat](../tests/compat/README.md)).
- **Service crates depend on `appwrite-core` and Utopia crates only**, never on each other.
- **Anything observable through the API matches PHP** (messages, codes, field order, side effects). The PHP E2E suite decides.
- **Rust writes keep PHP coherent:** the same tables, the same cache purges, the same queue messages.

Commands:

```bash
cargo fmt --all
cargo clippy --workspace --all-targets -- -D warnings
cargo test --workspace
cargo build --release -p appwrite-server         # target/release/appwrite-rust
COMPOSE_PROFILES=postgresql,rust docker compose up -d --wait
```
