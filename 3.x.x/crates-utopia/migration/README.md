# utopia-migration

Project migration sources and destinations for Utopia. Rust port of [utopia-php/migration](https://github.com/utopia-php/migration) (PHP SHA [`7e371c8f59bf`](https://github.com/utopia-php/migration/commit/7e371c8f59bf)).

Transfer resources (databases, auth, storage, functions, sites, messaging, …) from a [`Source`](https://github.com/utopia-php/migration/blob/7e371c8f59bf/src/Migration/Source.php) to a [`Destination`](https://github.com/utopia-php/migration/blob/7e371c8f59bf/src/Migration/Destination.php). Storage uses `utopia-storage`; DSNs use `utopia-dsn`; documents/collections use `utopia-database`. Default tests do not require live HTTP.

## Install

```toml
utopia-migration = { path = "../utopia-migration" }
```

## Usage

```rust
use utopia_migration::prelude::*;
use utopia_migration::resource::TYPE_DATABASE;

let mut source = MockSource::new();
source.push_mock_resource(Database::new("db", "Main"));
let mut transfer = Transfer::new(source, MockDestination::new());
transfer.run(&[TYPE_DATABASE], &mut |_| {}, None, None).unwrap();
```

## API Reference

### `Transfer<S, D>`

| Method | Description |
|--------|-------------|
| `new` | PHP `__construct(Source, Destination)` - registers a shared [`Cache`] |
| `run` | Transfer resource types. Errors if a root resource ID is set without a type (`Resource type must be set when resource ID is set.`) |
| `run_with_resource_selector` | Canonical Appwrite parent/child selector (colon-containing IDs stay opaque) |
| `get_cache` / `get_status_counters` / `get_report` | Transfer progress |
| `extract_services` | Expand service group names (`auth`, `databases`, …) to resource types |

Constants match PHP: `GROUP_*`, `ROOT_RESOURCES`, `STORAGE_MAX_CHUNK_SIZE`.

### `Cache`

PHP-compatible keyed store. Rows/documents are stored as **status counters** (not individual documents) to avoid memory blow-ups. `resolve_resource_cache_key` concatenates parent sequences.

### `OnDuplicate` / `SchemaAction`

| Case | Value | Existing resource |
|------|-------|-------------------|
| `Fail` | `fail` | `Create` (caller surfaces duplicate) |
| `Skip` | `skip` | `Skip` |
| `Overwrite` | `overwrite` | `Overwrite` only when source `updatedAt` is strictly newer |

### Sources

| Type | PHP | Notes |
|------|-----|-------|
| `AppwriteSource` | `Sources\Appwrite` | PHP constructor + column mapping. Live Appwrite not required for default tests |
| `Firebase` | `Sources\Firebase` | Service-account map constructor |
| `NHost` / `Supabase` | `Sources\NHost` / `Supabase` | Lazy DB connect (PHP Supabase connects in ctor; Rust delays so CI needs no Postgres) |
| `JsonSource` / `CsvSource` | `Sources\JSON` / `CSV` | `from_resource_ids` keeps colon-containing IDs separate. Uses `utopia_storage::Local` |
| `MockSource` | test adapter | In-memory, colon-split legacy child selector |

### Destinations

| Type | PHP | Notes |
|------|-----|-------|
| `AppwriteDestination` | `Destinations\Appwrite` | Schema import uses `utopia-database` (Memory in tests) via `with_database`. `resolve_destination_dsn` is public. Without a resolver it returns `""` (never copies the source DSN). Live Appwrite HTTP is feature-gated (`appwrite-http`) |
| `JsonDestination` / `CsvDestination` | `Destinations\JSON` / `CSV` | Stream rows to a temp `Local` device then copy |
| `LocalDestination` | `Destinations\Local` | Filesystem export |
| `MockDestination` | test adapter | In-memory import |

### Resources

All PHP resource types are represented (`Database`, `Table`/`Collection`, `Row`/`Document`, `Column`/`Attribute`, `Index`, auth, storage, functions, sites, messaging, integrations, settings, templates, domains, backups). `Column::resolve` matches Appwrite type/format/size mapping (`FORMAT_SIZES`, `SIZES`). `AppwriteSource::get_column` / `get_attribute` map raw payloads onto the PHP column subclass kinds (`Email`, `RegularText`, `Varchar`, …) via [`ColumnKind`].

### Intentional deviations

- Snake_case method names.
- `MockSource` / `MockDestination` are public (PHP keeps them in tests).
- `resolve_destination_dsn` is public (PHP tests use reflection).
- NHost/Supabase do not open Postgres in the constructor.
- `AppwriteDestination::new` is DSN-only; `with_database` is the PHP constructor that takes `dbForProject` / `collectionStructure`. Default tests use the Memory adapter. Subquery attribute/index filters are not registered - table meta `attributes` is updated in place so `IndexValidator` sees the same columns.
- Live Appwrite HTTP import (auth, storage, functions, …) requires the `appwrite-http` feature; default CI does not call paid or credentialed APIs.
- PHP `Utopia\Fetch` / Appwrite PHP SDK are PHP-runtime-only and are not Composer requires of this library. Default tests cover `AppwriteSource::get_column` / `get_attribute` / `list_columns_from_sdk_list` without an HTTP client. Optional live HTTP is feature-gated (`appwrite-http`); a parity test uses reqwest as a **dev-dependency** only.

## Tests

```bash
cargo test -p utopia-migration
```

Default tests do not need live Appwrite, ClickHouse, or Postgres. NHost/Supabase constructors are covered without connecting.

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/migration/Cargo.toml
```
