//! A MongoDB client: commands, documents, indexes, sessions and
//! transactions, on the official driver (`mongodb`, BSON from `bson`).
//!
//! [`Client`] connects to one server with SCRAM-SHA-256 and runs
//! commands on it: the generic [`Client::query`], and the shapes PHP's
//! client offers over it (insert, find, update, upsert, delete, count,
//! aggregate, indexes, collections). Results are the server's replies as
//! BSON documents. Writes and reads join a [`Session`]'s transaction when
//! [`Options::session`] names one; outside transactions, reads are causally
//! consistent with the client's last reply, as in PHP.
//!
//! [`Auth`] is the SCRAM-SHA-256 conversation as a value; the client
//! itself authenticates through the driver.
//!
//! PHP: ports `utopia-php/mongo`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Mongo\Client` | [`Client`] ([`Config`] holds the constructor's parameters) |
//! | `Client::query()` | [`Client::query`], [`Client::query_in`] (the `session` command field), returning [`Reply`] |
//! | `$options` arrays (`session`, `writeConcern`, `limit`, ...) | [`Options`] (`session` and the other fields) |
//! | `startSession()`'s array | [`Session`] |
//! | `getSessionState()`, `getConnectionInfo()` | [`SessionState`], [`ConnectionInfo`] |
//! | `Client::TRANSACTION_*`, `READ_CONCERN_*`, `COMMAND_*` | [`TransactionState`], [`client::READ_CONCERNS`], [`client::command`] |
//! | `upsert()`'s operations | [`Upsert`] |
//! | `Utopia\Mongo\Auth` | [`Auth`] ([`Credentials`] are its options) |
//! | `Utopia\Mongo\Exception` and subclasses | [`Error`] (one variant per class), [`Failure`] (the details) |
//!
//! The driver keeps its own connection pool and session pool: there is no
//! socket to manage and no Swoole coroutine switch (`$useCoroutine`) to
//! choose, and server sessions are pooled client side (`startSession` and
//! `endSessions` are not round trips). PHP's `toObject()` and `toArray()`
//! convert between PHP's two shapes of one BSON document (array and
//! `stdClass`); a [`bson::Document`] is both.

#![warn(missing_docs)]

mod auth;
pub mod client;
mod error;
mod uuid;

pub use auth::{Auth, Credentials};
pub use bson;
pub use client::{
    Client, Config, ConnectionInfo, Options, ReadConcern, Reply, Session, SessionState, TransactionState, Upsert,
    WriteConcern,
};
pub use error::{Error, Failure, Result};
pub use mongodb;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
