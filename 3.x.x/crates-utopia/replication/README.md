# utopia-replication

MySQL binlog replication (CDC) for Utopia. Rust port of [utopia-php/replication](https://github.com/utopia-php/replication) (monorepo `packages/replication`, PHP SHA `078611738ae3`).

Streams row-level INSERT/UPDATE/DELETE events from a MySQL GTID binlog over the replication protocol, or from an archived binlog file. Internals are blocking TCP (no Swoole); the public surface stays Utopia-fluent.

## Install

```toml
utopia-replication = { path = "../utopia-replication" }
```

## Source server

```ini
binlog_format       = ROW
gtid_mode           = ON
enforce_gtid_consistency = ON
binlog_row_metadata = FULL   # optional: ships column names in the stream
```

When metadata is `MINIMAL`, column names are resolved from `INFORMATION_SCHEMA` over a second connection.

## Usage

```rust
use utopia_replication::{Change, MySQL, Source};

let mut replication = MySQL::new(
    "127.0.0.1",
    3306,
    "replicator",
    "secret",
    1001,                       // unique among replicas
    Some("appwrite".into()),    // only emit this schema; None = all
    false,                      // ssl
    true,                       // ssl_verify
    "",                         // ssl_ca
    15.0,                       // heartbeat seconds
);

replication.start(checkpoint.as_deref())?; // resume GTID set, or None for "now"

loop {
    for change in replication.get_changes()? {
        // change.action  - Change::INSERT | UPDATE | DELETE
        // change.table   - physical table name
        // change.rows    - column => value maps (after-image for updates)
        // change.gtid    - executed-GTID-set checkpoint (advances on commit)
        checkpoint = Some(change.gtid.clone());
    }
}
```

### Reading from a binlog file

```rust
use utopia_replication::{Decoder, EventParser, File, GtidSet, Transport};

let mut source = File::new(bytes);
source.open(None)?;
let mut decoder = Decoder::new(
    EventParser::new(),
    GtidSet::new(""),
    Some("appwrite".into()),
    source.checksum(),
);
for event in source.events()? {
    if let Some(change) = decoder.decode(&event)? {
        // ...
    }
}
```

## API Reference

### `Source` / `MySQL`

| Method | Signature | Description |
|--------|-----------|-------------|
| `MySQL::new` | `(host, port, username, password, server_id, schema, ssl, ssl_verify, ssl_ca, heartbeat)` | PHP `Source\MySQL::__construct`. |
| `start` | `fn start(&mut self, position: Option<&str>) -> Result<(), ReplicationError>` | Connect and begin a GTID dump. |
| `get_changes` | `fn get_changes(&mut self) -> Result<Vec<Change>, ReplicationError>` | Next decoded row change(s). PHP yields a generator; Rust returns a batch of currently available events (one per call for the live dump). |
| `stop` | `fn stop(&mut self)` | Close dump and schema connections. |
| `next_change` | `fn next_change(&mut self) -> Result<Option<Change>, ReplicationError>` | Blocking next change from the live dump. |

### `Change`

| Field | Description |
|-------|-------------|
| `action` | `Change::INSERT` / `UPDATE` / `DELETE` (`"insert"` / `"update"` / `"delete"`). |
| `database` | Source schema. |
| `table` | Physical table name. |
| `rows` | Column → [`RowValue`] maps (after-image for UPDATE). |
| `gtid` | Executed-GTID-set of transactions committed *before* this event. |

`RowValue` is `Int(i64)`, `Float(f64)`, `Bytes(Vec<u8>)`, or `Null`.

### `File` / `Transport`

| Method | Description |
|--------|-------------|
| `File::new` / `from_bytes` / `from_chunks` | PHP `File::__construct(string\|iterable)`. |
| `open` | Validate magic, learn checksum from FORMAT_DESCRIPTION. |
| `events` | Framed raw event records. |
| `checksum` / `position` / `close` | PHP `Transport` methods. File `position()` is always `""`. |

### `Decoder` / `EventParser` / `GtidSet` / `BinaryReader`

| Type | PHP | Notes |
|------|-----|-------|
| `Decoder` | `Source\MySQL\Decoder` | `decode(&[u8]) -> Option<Change>`. Checkpoint advances on XID / autocommit QUERY. |
| `EventParser` | `Source\MySQL\EventParser` | `parse_table_map`, `parse_rows`. Optional column-name resolver for MINIMAL metadata. |
| `GtidSet` | `Source\MySQL\GtidSet` | Text form `uuid:1-5:7-9`. `encode()` is COM_BINLOG_DUMP_GTID wire form (half-open). |
| `BinaryReader` | `Source\MySQL\BinaryReader` | Little-endian cursor over a buffer. |
| `Connection` / `Client` | live dump + query protocol | Blocking TCP; optional native-tls. |
| `Constants` | protocol / MYSQL_TYPE_* constants | Same numeric values as PHP. |

### Errors

[`ReplicationError::Message`] matches PHP `Utopia\Replication\Exception` (free-form message).

## Intentional deviations

- Live `get_changes()` returns a `Vec` of currently available changes (one event per call on the socket dump) instead of a Swoole generator.
- `RowValue` is a typed enum instead of PHP `mixed`.
- TLS uses `native-tls` rather than PHP OpenSSL streams.

## Tests

```bash
cargo test -p utopia-replication
```

Ports PHP `tests/Unit/Source/MySQL/{Decoder,EventParser,File,GtidSet}Test.php` using the same in-memory binlog fixtures. Live MySQL E2E always hits the compose MySQL container (`REPLICATION_TEST_HOST`, default `127.0.0.1:8706`).

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/replication/Cargo.toml
```

## License

MIT - see [LICENSE](LICENSE).
