//! PHP `Utopia\Database\Connection`.

/// PHP `Utopia\Database\Connection`.
#[derive(Debug, Clone, Copy)]
pub struct Connection;

impl Connection {
    const ERRORS: &'static [&'static str] = &["Max connect timeout reached"];

    /// PHP `Connection::hasError`.
    #[must_use]
    pub fn has_error(message: &str) -> bool {
        const LOST: &[&str] = &[
            "server has gone away",
            "no connection to the server",
            "Lost connection",
            "is dead or not enabled",
            "Error while sending",
            "decryption failed or bad record mac",
            "server closed the connection unexpectedly",
            "SSL connection has been closed unexpectedly",
            "Error writing data to the connection",
            "Resource deadlock avoided",
            "Transaction() on null",
            "child connection forced to terminate due to client_idle_limit",
            "query_wait_timeout",
            "reset by peer",
            "Physical connection is not usable",
            "TCP Provider: Error code 0x68",
            "ORA-03114",
            "Packets out of order",
            "Adaptive Server connection failed",
            "Communication link failure",
            "connection is no longer usable",
            "Login timeout expired",
            "Connection refused",
            "running with the --read-only option",
            "The connection is broken and recovery is not possible",
            "SSL: Handshake timed out",
            "Reason: Socket is not connected",
            "Broken pipe",
        ];
        if LOST.iter().any(|n| message.contains(n)) {
            return true;
        }
        Self::ERRORS.iter().any(|n| message.contains(n))
    }
}
