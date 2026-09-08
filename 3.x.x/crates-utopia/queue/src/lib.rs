//! Task queue server, brokers, and adapters for Utopia.
//!
//! Rust port of [`utopia-php/queue`](https://github.com/utopia-php/queue)
//! (PHP SHA `c3ae00025014`).
//!
//! Layout matches PHP `Utopia\Queue\`:
//! - [`Adapter`], [`Connection`], [`Consumer`], [`Job`], [`Message`],
//!   [`Publisher`], [`Queue`], and [`Server`] at the crate root
//! - runtimes under [`adapter`] (`Adapter\Swoole`, `Adapter\Workerman`, …)
//! - brokers under [`broker`] (`Broker\Redis`, `Broker\Nats`, `Broker\Pool`)
//! - connections under [`connection`] (`Connection\Redis`, `Connection\RedisCluster`)
//!
//! ```
//! use serde_json::json;
//! use utopia_queue::adapter::KubernetesJob;
//! use utopia_queue::broker::Redis;
//! use utopia_queue::connection::InMemoryConnection;
//! use utopia_queue::prelude::*;
//!
//! let connection = InMemoryConnection::new();
//! let broker = Redis::new(connection.clone(), connection);
//! broker
//!     .enqueue(&Queue::new("demo").unwrap(), json!({"n": 1}), false)
//!     .unwrap();
//!
//! let adapter = KubernetesJob::new(broker, 1, "demo").unwrap();
//! let mut server = Server::new(adapter);
//!
//! server
//!     .job()
//!     .inject("message")
//!     .unwrap()
//!     .action(|args| {
//!         let _message = args.message()?;
//!         Ok(())
//!     });
//!
//! server.start().unwrap();
//! ```

mod action;
pub mod adapter;
pub mod broker;
pub mod connection;
mod consumer;
mod error;
mod job;
pub mod lock;
mod message;
pub mod pool;
mod publisher;
mod queue;
mod server;

pub use action::ActionArgs;
pub use adapter::{Adapter, RECEIVE_BACKOFF, RECEIVE_TIMEOUT};
pub use connection::Connection;
pub use consumer::Consumer;
pub use error::QueueError;
pub use job::{ActionFn, Job};
pub use message::Message;
pub use publisher::Publisher;
pub use queue::Queue;
pub use server::{HookEntry, Server};

pub mod prelude {
    pub use crate::adapter::{Adapter, KubernetesJob, Swoole, Workerman};
    pub use crate::broker::Nats;
    pub use crate::connection::{Connection, InMemoryConnection, Locking};
    pub use crate::consumer::Consumer;
    pub use crate::lock::{Lock, MutexLock};
    pub use crate::pool::ResourcePool;
    pub use crate::publisher::Publisher;
    pub use crate::server::Server;
    pub use crate::{ActionArgs, Job, Message, Queue, QueueError};
}
