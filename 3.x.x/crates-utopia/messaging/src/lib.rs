//! Multi-adapter messaging for Utopia.
//!
//! Rust port of [`utopia-php/messaging`](https://github.com/utopia-php/messaging).
//!
//! Layout matches PHP `Utopia\Messaging\`:
//! - [`Adapter`], [`Message`], [`Priority`], and [`Response`] at the crate root
//! - providers under [`adapter`] (`Adapter\SMS\Twilio`, `Adapter\Email\SMTP`, …)
//! - payloads under [`messages`] (`Messages\SMS`, `Messages\Email`, …)
//! - [`helpers`] (`Helpers\JWT`)

#![allow(clippy::upper_case_acronyms)]

pub mod adapter;
mod error;
pub mod helpers;
pub mod http;
mod message;
pub mod messages;
mod php;
mod priority;
mod response;

pub use adapter::{Adapter, AdapterBase, GroupedSend, SendResult};
pub use error::MessagingError;
pub use message::{Message, MessageKind};
pub use priority::Priority;
pub use response::{Response, ResponseData, ResultRow};

/// Prelude for PHP crate-root types. Provider adapters stay under [`adapter`].
pub mod prelude {
    pub use crate::adapter::{Adapter, SendResult};
    pub use crate::messages::{Email, Push, SMS};
    pub use crate::{Message, MessagingError, Priority, Response};
}
