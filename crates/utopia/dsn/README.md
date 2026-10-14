# Utopia DSN

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/dsn`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/dsn) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Data source names (connection strings), parsed into scheme, credentials, host, port, path and options.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-dsn = { git = "https://github.com/utopia-rs/dsn" }
```

A DSN (data source name) puts everything needed to reach a service in one string: `scheme://user:password@host:port/path?query`. Applications read them from configuration so a database, cache or provider can change without code changes. This guide covers the common tasks.

## Read a connection string

Parse the string once, then read each part. A missing part is `None` (or empty for the path).

```rust
use utopia_dsn::Dsn;

let dsn = Dsn::parse("postgresql://appwrite:secret@db.internal:5432/appwrite")?;

assert_eq!(dsn.scheme(), "postgresql");
assert_eq!(dsn.host(), "db.internal");
assert_eq!(dsn.port(), Some(5432));
assert_eq!(dsn.path(), "appwrite");
assert_eq!(dsn.user(), Some(&b"appwrite"[..]));
```

## Use credentials with special characters

Passwords often contain `@`, `/` or `:`, which have a meaning in a DSN. Percent-encode them in the string; the parser decodes them. Credentials are bytes in Rust, because a decoded password can be any bytes.

```rust
use utopia_dsn::Dsn;

let dsn = Dsn::parse("redis://default:p%40ss%2Fw%3Ard@cache:6379")?;

assert_eq!(dsn.password(), Some(&b"p@ss/w:rd"[..]));
let password = String::from_utf8_lossy(dsn.password().unwrap_or_default());
assert_eq!(password, "p@ss/w:rd");
```

## Keep secrets out of logs

Printing a DSN for debugging never shows its password, and errors never quote the string they refused.

```rust
use utopia_dsn::Dsn;

let dsn = Dsn::parse("mariadb://root:hunter2@db:3306/appwrite")?;
let logged = format!("{dsn:?}");

assert!(logged.contains("db"));
assert!(!logged.contains("hunter2"));
```

## Read options from the query string

Settings that are not part of the address go in the query. Read one with `param`, or with `param_or` to fall back to a default.

```rust
use utopia_dsn::Dsn;

let dsn = Dsn::parse("s3://key:secret@s3.amazonaws.com/uploads?region=eu-central-1&acl=private")?;

assert_eq!(dsn.param("region")?, Some(&b"eu-central-1"[..]));
assert_eq!(dsn.param_or("acl", b"public-read")?, b"private");
assert_eq!(dsn.param_or("endpoint", b"https://s3.amazonaws.com")?, b"https://s3.amazonaws.com");
```

## Configure a service from the environment

Read the DSN from an environment variable, with a default for local development, and pick an adapter by its scheme.

```rust
use utopia_dsn::Dsn;

let value = std::env::var("APP_CACHE").unwrap_or_else(|_| "redis://localhost:6379".to_owned());
let dsn = Dsn::parse(&value)?;

let adapter = match dsn.scheme() {
    "redis" => format!("Redis at {}:{}", dsn.host(), dsn.port().unwrap_or(6379)),
    "memcached" => format!("Memcached at {}", dsn.host()),
    other => format!("unsupported cache: {other}"),
};
assert_eq!(adapter, "Redis at localhost:6379");
```

## Reject invalid configuration

A DSN needs a scheme and a host. Anything else fails with an error you can report without exposing the value.

```rust
use utopia_dsn::{Dsn, Error};

for value in ["localhost:6379", "redis://", "not a dsn"] {
    match Dsn::parse(value) {
        Err(Error::InvalidArgument(reason)) => println!("invalid cache configuration: {reason}"),
        other => panic!("expected an error, got {other:?}"),
    }
}
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
