//! MIME: header fields, body encodings and the parts of a message.

pub mod encoding;
pub mod header;
pub(crate) mod part;

pub use part::{Chunks, Part};
