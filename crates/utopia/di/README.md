# Utopia DI

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/di`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/di) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

A dependency injection container: factories with declared dependencies, built once, resolved through parent containers.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-di = { git = "https://github.com/utopia-rs/di" }
```

A container builds the objects an application is made of, such as connections, clients and services, from factories that declare what they depend on. Ask the container for an id and it resolves the dependencies, builds the object once, and shares it with every later caller.

## Register and resolve a dependency

A factory receives its dependencies, resolved, in the order it declared them.

```rust
use utopia_di::Container;

let container = Container::new();
container.set("host", &[], |_| Ok(String::from("db.internal")));
container.set("dsn", &["host"], |deps| Ok(format!("postgresql://{}:5432", deps.get::<String>(0)?)));

assert_eq!(*container.get::<String>("dsn")?, "postgresql://db.internal:5432");
```

## Share one instance

The factory runs on the first `get`; every later `get` returns the same instance.

```rust
use std::sync::Arc;
use utopia_di::Container;

let container = Container::new();
container.set("pool", &[], |_| Ok(vec![String::from("connection")]));

let first = container.get::<Vec<String>>("pool")?;
let second = container.get::<Vec<String>>("pool")?;
assert!(Arc::ptr_eq(&first, &second));
```

## Scope dependencies with a child container

A child container adds or overrides dependencies for one request or job, and resolves everything else through its parent, which keeps sharing what it built.

```rust
use std::sync::Arc;
use utopia_di::Container;

let app = Arc::new(Container::new());
app.set("config", &[], |_| Ok("production"));

let request = Container::with_parent(app.clone());
request.set("user", &["config"], |deps| Ok(format!("user@{}", deps.get::<&str>(0)?)));

assert_eq!(*request.get::<String>("user")?, "user@production");
assert!(request.has("config"));
assert!(!app.has("user"));
```

## Handle a missing dependency

Resolving an id that no container defines fails with `Error::NotFound`.

```rust
use utopia_di::{Container, Error};

let container = Container::new();
let error = container.get_any("cache").unwrap_err();
assert_eq!(error, Error::NotFound("cache".into()));
assert_eq!(error.to_string(), "Dependency cache not found");
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
