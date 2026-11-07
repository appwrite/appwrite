//! Whether an error means the connection to the database was lost.
//!
//! PHP: `Utopia\Database\Connection`.

/// Messages of errors caused by a lost connection, as Swoole lists them
/// (`Swoole\Database\DetectsLostConnections::ERROR_MESSAGES`, in the Swoole
/// library bundled with the dev image's extension).
pub const SWOOLE_LOST_CONNECTION_MESSAGES: &[&str] = &[
    "server has gone away",
    "Server has gone away",
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
    "ORA-03113",
    "ORA-03114",
    "Packets out of order. Expected",
    "Adaptive Server connection failed",
    "Communication link failure",
    "connection is no longer usable",
    "Login timeout expired",
    "SQLSTATE[HY000] [2002] Connection refused",
    "running with the --read-only option so it cannot execute this statement",
    "The connection is broken and recovery is not possible. The connection is marked by the client driver as unrecoverable. No attempt was made to restore the connection.",
    "SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo failed: Try again",
    "SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo failed: Name or service not known",
    "SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for",
    "SQLSTATE[HY000]: General error: 7 SSL SYSCALL error: EOF detected",
    "SSL error: unexpected eof",
    "SQLSTATE[HY000] [2002] Connection timed out",
    "SSL: Connection timed out",
    "SQLSTATE[HY000]: General error: 1105 The last transaction was aborted due to Seamless Scaling. Please retry.",
    "Temporary failure in name resolution",
    "SQLSTATE[08S01]: Communication link failure",
    "SQLSTATE[08006] [7] could not connect to server: Connection refused Is the server running on host",
    "SQLSTATE[HY000]: General error: 7 SSL SYSCALL error: No route to host",
    "The client was disconnected by the server because of inactivity. See wait_timeout and interactive_timeout for configuring this behavior.",
    "SQLSTATE[08006] [7] could not translate host name",
    "TCP Provider: Error code 0x274C",
    "SQLSTATE[HY000] [2002] No such file or directory",
    "SSL: Operation timed out",
    "Reason: Server is in script upgrade mode. Only administrator can connect at this time.",
    "Unknown $curl_error_code: 77",
    "SSL: Handshake timed out",
    "SQLSTATE[08006] [7] SSL error: sslv3 alert unexpected message",
    "SQLSTATE[08006] [7] unrecognized SSL error code:",
    "SQLSTATE[HY000] [1045] Access denied for user",
    "SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it",
    "SQLSTATE[HY000] [2002] A connection attempt failed because the connected party did not properly respond after a period of time, or established connection failed because connected host has failed to respond",
    "SQLSTATE[HY000] [2002] Network is unreachable",
    "SQLSTATE[HY000] [2002] The requested address is not valid in its context",
    "SQLSTATE[HY000] [2002] A socket operation was attempted to an unreachable network",
    "SQLSTATE[HY000] [2002] Operation now in progress",
    "SQLSTATE[HY000] [2002] Operation in progress",
    "SQLSTATE[HY000]: General error: 3989",
    "went away",
    "No such file or directory",
    "server is shutting down",
    "failed to connect to",
    "Channel connection is closed",
    "Connection lost",
    "Broken pipe",
    "SQLSTATE[25006]: Read only sql transaction: 7",
    "vtgate connection error: no healthy endpoints",
    "primary is not serving, there may be a reparent operation in progress",
    "current keyspace is being resharded",
    "no healthy tablet available",
    "transaction pool connection limit exceeded",
    "SSL operation failed with code 5",
    "timed out",
    "Error reading result",
];

/// The library's own lost-connection messages, checked after Swoole's.
///
/// PHP: `Connection::$errors`.
pub const LOST_CONNECTION_MESSAGES: &[&str] = &["Max connect timeout reached"];

/// Whether an error with `message` was caused by a lost connection: the
/// message contains one of [`SWOOLE_LOST_CONNECTION_MESSAGES`] or
/// [`LOST_CONNECTION_MESSAGES`] (case-sensitively, bytes as they are).
///
/// ```
/// use utopia_database::connection::has_error;
///
/// assert!(has_error("SQLSTATE[08006] [7] server closed the connection unexpectedly"));
/// assert!(has_error("Max connect timeout reached"));
/// assert!(!has_error("SQLSTATE[23505]: Unique violation"));
/// ```
///
/// PHP: `Connection::hasError($e)` on `$e->getMessage()`
/// (`DetectsLostConnections::causedByLostConnection($e)`, then `mb_strpos`
/// over `Connection::$errors`; every needle is ASCII, so a byte search
/// finds what `mb_strpos` finds, invalid UTF-8 around it included).
pub fn has_error(message: impl AsRef<[u8]>) -> bool {
    let message = message.as_ref();
    SWOOLE_LOST_CONNECTION_MESSAGES
        .iter()
        .chain(LOST_CONNECTION_MESSAGES)
        .any(|needle| php_std::string::str_contains(message, needle.as_bytes()))
}
