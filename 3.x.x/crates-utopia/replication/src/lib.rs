//! `MySQL` binlog replication for Utopia.
//!
//! Rust port of [`utopia-php/replication`](https://github.com/utopia-php/replication).

pub mod source;

mod change;
mod error;

pub use change::{Change, RowValue};
pub use error::ReplicationError;
pub use source::mysql::{
    BinaryReader, Client, Connection, Constants, Decoder, EventParser, File, GtidSet, ParsedRows,
    Transport,
};
pub use source::MySQL;

/// PHP `Utopia\Replication\Source`.
pub trait Source {
    /// PHP `start(?string $position = null)`.
    fn start(&mut self, position: Option<&str>) -> Result<(), ReplicationError>;
    /// PHP `getChanges()` - collect currently available changes (blocking on live sockets).
    fn get_changes(&mut self) -> Result<Vec<Change>, ReplicationError>;
    /// PHP `stop()`.
    fn stop(&mut self);
}

/// Prelude for the PHP-shaped surface.
pub mod prelude {
    pub use crate::source::mysql::{
        BinaryReader, Client, Connection, Constants, Decoder, EventParser, File, GtidSet, Transport,
    };
    pub use crate::{Change, MySQL, ReplicationError, RowValue, Source};
}
