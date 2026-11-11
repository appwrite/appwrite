# Utopia Registry

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/registry`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/registry) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Named resources created on first use by registered factories, cached per context.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-registry = { git = "https://github.com/utopia-rs/registry" }
```

A registry holds the resources an application shares, such as database connections and API clients, and creates each one only when it is first needed. Register a factory per resource at startup; code that needs a resource asks the registry for it by name.

## Create a resource on first use

The factory runs on the first read; later reads return the same instance.

```rust
use utopia_registry::Registry;

let mut registry = Registry::new();
registry.set("db", || String::from("postgresql://db.internal"), false);

assert!(registry.has("db"));
assert_eq!(registry.get::<String>("db", false)?, "postgresql://db.internal");
```

## Get a new instance every time

A fresh factory runs on every read, for resources that must not be shared. A single read can also ask for a new instance.

```rust
use std::sync::atomic::{AtomicU32, Ordering};
use utopia_registry::Registry;

let mut registry = Registry::new();
let requests = AtomicU32::new(0);
registry.set("request", move || requests.fetch_add(1, Ordering::Relaxed), true);

assert_eq!(*registry.get::<u32>("request", false)?, 0);
assert_eq!(*registry.get::<u32>("request", false)?, 1);
```

## Keep one set of resources per context

Each context has its own instances. Switch context per worker or tenant to give each its own connections.

```rust
use std::sync::atomic::{AtomicU32, Ordering};
use utopia_registry::Registry;

let mut registry = Registry::new();
let connections = AtomicU32::new(0);
registry.set("redis", move || connections.fetch_add(1, Ordering::Relaxed), false);

let first = *registry.get::<u32>("redis", false)?;
registry.context("tenant-b");
let second = *registry.get::<u32>("redis", false)?;
assert_ne!(first, second);
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/registry`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/registry). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-registry
cargo clippy -p utopia-registry --all-targets -- -D warnings
cargo fmt --all --check
```

### Compare with PHP

Replay the results PHP recorded for every case against Rust, without PHP:

```bash
COMPAT_LIB=registry cargo test -p compat
```

Run every case on both runtimes, fuzz both with generated inputs, then run the whole conversion check. These need the dev stack (`docker compose up -d`):

```bash
bin/compat run registry
bin/compat fuzz registry
bin/compat check registry
```

### Benchmark

```bash
bin/compat bench registry
```

This times every benchmark on PHP and on a release build of Rust, both in containers on the dev stack. It fails if Rust is slower. Add `--write` to record the run in [`tests/compat/registry/bench.json`](https://github.com/appwrite/appwrite/blob/main/tests/compat/registry/bench.json). Run it on a quiet machine, one library at a time.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
