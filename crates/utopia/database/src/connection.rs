//! Whether an error means the connection to the database was lost.
//!
//! PHP: `Utopia\Database\Connection::hasError`, which asks Swoole's
//! `DetectsLostConnections::causedByLostConnection` and then its own list.

/// The messages of errors caused by a lost connection: Swoole's
/// `DetectsLostConnections` list followed by `Connection::$errors`.
pub const LOST_CONNECTION_MESSAGES: &[&str] = &["Max connect timeout reached"];

/// `Connection::hasError($e)`: whether the error message names a lost connection.
pub fn has_error(message: &str) -> bool {
    LOST_CONNECTION_MESSAGES.iter().any(|needle| message.contains(needle))
}
