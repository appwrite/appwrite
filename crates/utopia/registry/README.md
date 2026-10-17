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
registry.set("db", || String::from("postgresql://db.internal"));

assert!(registry.has("db"));
assert_eq!(registry.get::<String>("db")?, "postgresql://db.internal");
```

## Get a new instance every time

A fresh factory runs on every read, for resources that must not be shared. A single read can also ask for a new instance.

```rust
use std::sync::atomic::{AtomicU32, Ordering};
use utopia_registry::Registry;

let mut registry = Registry::new();
let requests = AtomicU32::new(0);
registry.set_fresh("request", move || requests.fetch_add(1, Ordering::Relaxed));

assert_eq!(*registry.get::<u32>("request")?, 0);
assert_eq!(*registry.get::<u32>("request")?, 1);
```

## Keep one set of resources per context

Each context has its own instances. Switch context per worker or tenant to give each its own connections.

```rust
use std::sync::atomic::{AtomicU32, Ordering};
use utopia_registry::Registry;

let mut registry = Registry::new();
let connections = AtomicU32::new(0);
registry.set("redis", move || connections.fetch_add(1, Ordering::Relaxed));

let first = *registry.get::<u32>("redis")?;
registry.context("tenant-b");
let second = *registry.get::<u32>("redis")?;
assert_ne!(first, second);
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
