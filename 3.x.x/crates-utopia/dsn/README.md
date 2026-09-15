# utopia-dsn

DSN parsing for Utopia. Rust port of [utopia-php/dsn](https://github.com/utopia-php/dsn).

Parses Data Source Names (`scheme://user:password@host:port/path?query`) with PHP `parse_url` / `parse_str` semantics, including custom schemes such as `mariadb://` and `s3://`.

## Install

```toml
utopia-dsn = { path = "../utopia-dsn" }
```

## Usage

```rust
use utopia_dsn::Dsn;

let dsn = Dsn::new(
    "mariadb://user:password@localhost:3306/database?charset=utf8&timezone=UTC",
)
.unwrap();

assert_eq!(dsn.get_scheme(), "mariadb");
assert_eq!(dsn.get_user(), Some("user"));
assert_eq!(dsn.get_password(), Some("password"));
assert_eq!(dsn.get_host(), "localhost");
assert_eq!(dsn.get_port(), Some("3306"));
assert_eq!(dsn.get_path(), "database");
assert_eq!(dsn.get_query(), Some("charset=utf8&timezone=UTC"));
assert_eq!(dsn.get_param("charset", ""), "utf8");
assert_eq!(dsn.get_param("timezone", ""), "UTC");
```

## API Reference

### `Dsn`

PHP `Utopia\DSN\DSN`. Also exported as the type alias `DSN`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(dsn: impl AsRef<str>) -> Result<Dsn, DsnError>` | Parse a DSN. Fails when `parse_url` returns false, scheme is missing, or host is missing. |
| `get_scheme` | `fn get_scheme(&self) -> &str` | Scheme (`mariadb`, `mysql`, `s3`, `sms`, …). |
| `get_user` | `fn get_user(&self) -> Option<&str>` | URL-decoded user. `None` when omitted. |
| `get_password` | `fn get_password(&self) -> Option<&str>` | URL-decoded password. `None` when omitted (`user@host`); `Some("")` when empty (`user:@host`). |
| `get_host` | `fn get_host(&self) -> &str` | Host (required). |
| `get_port` | `fn get_port(&self) -> Option<&str>` | Port as a decimal **string** (`"3306"`), matching PHP's coerced `?string`. |
| `get_path` | `fn get_path(&self) -> &str` | Path with leading `/` stripped. Missing path is `""`, not `None`. |
| `get_query` | `fn get_query(&self) -> Option<&str>` | Raw query string (values still encoded). `None` when omitted. |
| `get_param` | `fn get_param(&self, key: &str, default: &str) -> String` | Query parameter. Lazy PHP `parse_str` (URL-decodes values). PHP default for `default` is `''`. |

### `DsnError`

| Variant | PHP message |
|---------|-------------|
| `InvalidArgument(String)` | `Unable to parse DSN: {dsn}` when `parse_url` returns false |
| | `Unable to parse DSN: scheme is required` |
| | `Unable to parse DSN: host is required` |

PHP `empty()` is applied to scheme and host (`null` / `""` / `"0"` fail).

### PHP `parse_url` quirks this crate matches

- Custom schemes are accepted (`mariadb://`, `s3://user:secret@host:3306/bucket?region=us-east-1`).
- `mariadb://` (empty host after `://`) is **unparseable**, not “host is required”.
- User and password are URL-decoded (`+` → space, `%XX` sequences).
- `user:@localhost` → password empty string (`isset` pass); `user@localhost` → password `None`.
- Query stays encoded in `get_query()`; `get_param()` returns decoded values.

## Tests

```bash
cargo test --manifest-path crates-utopia/dsn/Cargo.toml
```

Ports `tests/DSN/DSNTest.php` (`testSuccess`, `testGetParam`, `testFail`) and adds extra error-path coverage (missing scheme, missing host, invalid port).

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/dsn/Cargo.toml
```

Prints `dsn_parse` and `dsn_get_param` ops/s for a typical MariaDB DSN. PHP twin: [`benchmarks/dsn/`](../../benchmarks/dsn/).

## Code quality

- **rustfmt** - `cargo fmt --manifest-path crates-utopia/dsn/Cargo.toml`
- **Clippy** - `cargo clippy --manifest-path crates-utopia/dsn/Cargo.toml --all-targets -- -D warnings`
- Inherits workspace lint policy (`[lints] workspace = true`).

## License

MIT - see [LICENSE](LICENSE).
