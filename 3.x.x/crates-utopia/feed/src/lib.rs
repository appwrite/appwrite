//! Event feeds for Utopia.
//!
//! Rust port of [`utopia-php/feed`](https://github.com/utopia-php/feed)
//! (PHP SHA `ff6c011b0a8a`).
//!
//! Layout matches PHP `Utopia\Feed\`:
//! - [`Producer`], [`Consumer`], [`Server`], [`Remote`], [`Batch`], [`Id`], [`Key`]
//! - stores under [`store`] (`Store\Memory`, `Store\Cache`, `Store\Redis`, …)
//! - cursors under [`cursor`] (`Cursor\Memory`, `Cursor\Cache`, `Cursor\Redis`, …)

mod batch;
mod consumer;
pub mod cursor;
mod error;
mod extensions;
mod http;
mod id;
mod key;
mod producer;
mod readable;
mod remote;
mod server;
pub mod store;

pub use batch::Batch;
pub use consumer::{ConsumeDecision, Consumer, IntoConsumeResult};
pub use cursor::{Cache as CacheCursor, Cursor, Memory as MemoryCursor, None as NoneCursor};
#[cfg(feature = "redis")]
pub use cursor::{Pool as PoolCursor, Redis as RedisCursor};
pub use error::{FeedError, Invalid, Transport, Unsupported};
pub use extensions::Extensions;
pub use http::{FeedHttpResponse, RecordedRequest, RecordingTransport};
pub use id::Id;
pub use key::Key;
pub use producer::Producer;
pub use readable::{Appendable, Readable, Store, MAX_BATCH, MAX_TIMEOUT, MEDIA_TYPE, TIP};
pub use remote::Remote;
pub use server::Server;
pub use store::{Cache as CacheStore, Memory as MemoryStore, None as NoneStore};
#[cfg(feature = "redis")]
pub use store::{Pool as PoolStore, Redis as RedisStore, RedisConn};

pub mod prelude {
    pub use crate::{
        Batch, CacheCursor, CacheStore, Consumer, Cursor, FeedError, Id, Key, MemoryCursor,
        MemoryStore, NoneCursor, NoneStore, Producer, Readable, Remote, Server, TIP,
    };
}
