//! Per-client SMTP connection state. Mirrors `src/Server/SMTP/Connection.php`.

use std::sync::Arc;

use tokio::net::TcpStream;
use tokio::sync::Mutex;

/// SMTP protocol state. Starts in `Command` mode; transitions to `Data` after the
/// backend accepts `DATA` with a 354 response, and back to `Command` after the
/// client sends the dot terminator.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SmtpState {
    Command,
    Data,
}

/// Per-client SMTP connection: state machine + EHLO domain + lazily-opened backend socket.
#[derive(Debug)]
pub struct Connection {
    pub state: SmtpState,
    pub domain: Option<String>,
    pub backend: Option<Arc<Mutex<TcpStream>>>,
}

impl Connection {
    pub fn new() -> Self {
        Self {
            state: SmtpState::Command,
            domain: None,
            backend: None,
        }
    }

    /// True if the connection is currently streaming message body data.
    pub fn is_data(&self) -> bool {
        self.state == SmtpState::Data
    }
}

impl Default for Connection {
    fn default() -> Self {
        Self::new()
    }
}
