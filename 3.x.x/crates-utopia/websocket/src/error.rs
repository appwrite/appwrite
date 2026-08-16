use thiserror::Error;

/// Errors raised by the WebSocket client and adapters.
#[derive(Debug, Error, Clone)]
pub enum WebsocketError {
    /// PHP `InvalidArgumentException`: `Invalid WebSocket URL`
    #[error("Invalid WebSocket URL")]
    InvalidUrl,
    /// PHP `InvalidArgumentException`: `WebSocket URL must contain a host`
    #[error("WebSocket URL must contain a host")]
    MissingHost,
    /// PHP `RuntimeException`: `Not connected to WebSocket server`
    #[error("Not connected to WebSocket server")]
    NotConnected,
    /// PHP `RuntimeException`: `WebSocket connection failed: {code} - {message}`
    #[error("WebSocket connection failed: {code} - {message}")]
    ConnectFailed { code: i32, message: String },
    /// PHP `RuntimeException`: `Failed to send data: {code} - {message}`
    #[error("Failed to send data: {code} - {message}")]
    SendFailed { code: i32, message: String },
    /// PHP `RuntimeException`: `Failed to receive data: {code} - {message}`
    #[error("Failed to receive data: {code} - {message}")]
    ReceiveFailed { code: i32, message: String },
    /// Adapter / I/O failure.
    #[error("{0}")]
    Io(String),
}

impl From<std::io::Error> for WebsocketError {
    fn from(error: std::io::Error) -> Self {
        Self::Io(error.to_string())
    }
}
