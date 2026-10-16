# appwrite-platform

Appwrite platform composition layer on `utopia-platform`, `utopia-http`, and `utopia-di`.

Wires together the Users-API foundation crates (`appwrite-exception`,
`appwrite-hooks`, `appwrite-locale`, `appwrite-auth`, `appwrite-event`,
`appwrite-response`, `appwrite-database`) into a single `AppwritePlatform`
facade, mirroring how `app/init.php` wires `Appwrite\*` services (`$hooks`,
`$publisherForDeletes`, `$publisherForAudits`, ...) into the Utopia
`App`/`Platform` at boot.

## Install

```toml
appwrite-platform = { workspace = true }
```

## API

| Item | Purpose |
|---|---|
| `AppwritePlatform::new()` | Builds an empty `utopia_platform::Platform`, DI `Container`, hook registry (with the default `passwordValidator` hook registered), and in-memory delete/audit publishers. |
| `platform()` | The composed `utopia_platform::Platform`. |
| `di()` | The composed `utopia_di::Container`. |
| `hooks()` / `hooks_mut()` | The `appwrite_hooks::Hooks` registry; override `PASSWORD_VALIDATOR` here to layer project-specific strength/dictionary/history policy on top of the default length check. |
| `deletes()` | The `v1-deletes` queue publisher (`appwrite_event::MemoryDeletePublisher` for now). |
| `audits()` | The `v1-audits` queue publisher (`appwrite_event::MemoryAuditPublisher` for now). |
| `ensure_ready()` | Verifies the hook registry, publishers, response model catalog, database sentinel, and HTTP mode constant are all reachable. |

```rust
use appwrite_platform::AppwritePlatform;
use serde_json::json;

let platform = AppwritePlatform::new();
assert!(platform.ensure_ready().is_ok());
assert_eq!(
    platform.hooks().trigger(appwrite_hooks::PASSWORD_VALIDATOR, &[json!("short")]),
    Some(json!(false)),
);
```

## Deviations from PHP

- `app/init.php` wires dozens of services (`dbForProject`, `queueForEvents`,
  `queueForDeletes`, `queueForAudits`, locale, ...) into a single Utopia
  `App`/DI registration pass. `AppwritePlatform` currently composes only the
  subset the Users API foundation needs (hooks, delete/audit publishers);
  the rest lands alongside the corresponding HTTP module ports.
- The delete/audit publishers are the in-memory implementations from
  `appwrite-event`, not the Redis-backed `Utopia\Queue\Publisher` PHP uses.
  Swapping in a real publisher is an `apps/server` wiring change, not a
  change to this crate's composition surface.
- Live SQL uses Rust engine crates via Utopia adapters (`Postgres`, `Mysql`,
  `MariaDb`). Product code should not construct a low-level SQL client or
  mirror PHP PDO; see `3.x.x/AGENTS.md` and `3.x.x/README.md`.
