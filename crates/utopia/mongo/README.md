# Utopia Mongo

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/mongo`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/mongo) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

MongoDB client: commands, CRUD, indexes, sessions and transactions with causal consistency, on the official driver.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-mongo = { git = "https://github.com/utopia-rs/mongo" }
```

A client for one MongoDB server: run commands, insert, find, update and delete documents, manage collections and indexes, and group writes in transactions. It is the Rust port of `utopia-php/mongo`, on MongoDB's official driver: the commands, the replies and the errors are the ones PHP's client sends and reports, so a PHP service and a Rust service can share a deployment. This guide covers the common tasks, then how the crate is built.

## Connect and write a document

`Client::new` checks the settings; `connect` dials the server and authenticates (SCRAM-SHA-256). A document inserted without an `_id` gets a UUIDv7, and `insert` returns it as inserted.

```rust
use utopia_mongo::bson::doc;
use utopia_mongo::{Client, Config, Options};

tokio::runtime::Runtime::new()?.block_on(async {
    let client = Client::new(Config::new("app", "localhost", 27017, "root", "password"))?;
    client.connect().await?;

    let movie = client.insert("movies", doc! { "name": "Inception", "year": 2010 }, Options::new()).await?;
    assert_eq!(movie.get_str("_id")?.len(), 36);

    let found = client.find("movies", doc! { "name": "Inception" }, Options::new()).await?;
    let batch = found.get_document("cursor")?.get_array("firstBatch")?;
    assert_eq!(batch.len(), 1);
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
```

## Tell failures apart

Every failure is an `Error` whose variant is PHP's exception class. A server's refusal carries its code (`11000` for a duplicate key), and an `Error::Unsent` never reached the server, so it is safe to replay.

```rust
use utopia_mongo::bson::doc;
use utopia_mongo::{Error, Failure};

let error = Error::from_response(&doc! { "errmsg": "E11000 duplicate key", "code": 11000 }, Some("insert"));
assert!(error.is_duplicate_key_error());
assert_eq!(error.error_category(), "Duplicate Key Error");
assert!(!error.is_unsent_error());

let unsent = Error::Unsent(Box::new(Failure::new("Client is not connected to MongoDB", 0)));
assert!(unsent.is_unsent_error());
assert_eq!(unsent.php_class(), "Utopia\\Mongo\\UnsentException");
```

## Write in a transaction

Transactions need a replica set. Start a session, then either drive the transaction yourself (`start_transaction`, commands with `Options::in_session`, `commit_transaction`) or let `with_transaction` commit it, retrying while the failure is transient.

```rust
use utopia_mongo::bson::doc;
use utopia_mongo::{Client, Config, Options};

tokio::runtime::Runtime::new()?.block_on(async {
    let client = Client::new(Config::new("app", "localhost", 27017, "root", "password"))?;
    client.connect().await?;
    let session = client.start_session(doc! {}).await?;

    client.start_transaction(&session, doc! {}).await?;
    client.insert("orders", doc! { "item": "book" }, Options::in_session(Some(session.clone()))).await?;
    client.commit_transaction(&session, doc! {}).await?;

    let state = client.session_state(&session).expect("tracked");
    assert_eq!(state.state.as_str(), "committed");
    client.end_sessions(&[session], doc! {}).await?;
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
```

## Build concerns

`create_write_concern` and `create_read_concern` turn the shapes PHP's options accept into the documents the commands carry, and refuse the invalid ones.

```rust
use utopia_mongo::bson::{Bson, doc};
use utopia_mongo::{Client, Config};

let client = Client::new(Config::new("app", "localhost", 27017, "root", "password"))?;
assert_eq!(client.create_write_concern(&Bson::String("majority".into()))?.to_document(), doc! { "w": "majority" });
assert_eq!(
    client.create_read_concern(&Bson::String("dirty".into())).unwrap_err().message(),
    "Invalid read concern level: dirty"
);
```

## How the crate is built

- **`Client`** (`client.rs`) holds one `mongodb::Client` (direct connection, SCRAM-SHA-256, no automatic retries, as PHP's single socket) and the state PHP's client keeps per connection: whether it is connected, the cluster and operation times of the last reply (commands outside a transaction read at or after them, as PHP's do), and the sessions it started with their transaction state. Every command goes through `query_in`, which runs it with `run_command`, in the session's driver session when there is one, and reads the reply as PHP does: write errors and error replies become `Error::Exception` with the server's code, a reply with `n` becomes `Reply::Count`.
- **Sessions and transactions** are the driver's: it numbers transactions and their commands and starts a transaction with its first command. The client keeps PHP's bookkeeping beside them (`txnNumber`, `state`, `lastUse`) and its rules: a transaction started twice, committed or aborted when none is in progress fails as PHP's does.
- **`Auth`** (`auth.rs`) is the SCRAM-SHA-256 conversation PHP's client runs by hand, as a value; the driver authenticates `Client`.
- **`Error`** (`error.rs`) has one variant per PHP exception class; `Failure` holds what `Utopia\Mongo\Exception` carries and its classification (network, timeout, duplicate key, transient, ...).
- **Ids**: `create_uuid` makes UUIDv7 ids that grow monotonically within a millisecond, as `ramsey/uuid` makes them for PHP.

Where the driver and PHP's hand-made client differ (a transaction that ran no command, abort errors, the transport itself), the conversion records a deviation in `tests/compat/mongo/spec.json`.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
