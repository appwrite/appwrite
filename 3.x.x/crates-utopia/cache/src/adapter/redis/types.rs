/// PHP `Utopia\Cache\Adapter\Redis\RedisError`.
#[derive(Debug, Clone, PartialEq)]
pub struct RedisError {
    pub message: String,
}

impl RedisError {
    #[must_use]
    pub fn new(message: impl Into<String>) -> Self {
        Self {
            message: message.into(),
        }
    }

    #[must_use]
    pub fn exception_message(&self) -> &str {
        &self.message
    }
}

/// PHP `Utopia\Cache\Adapter\Redis\ConnectionException`.
#[derive(Debug, Clone)]
pub struct ConnectionException {
    pub message: String,
}

impl ConnectionException {
    #[must_use]
    pub fn new(message: impl Into<String>) -> Self {
        Self {
            message: message.into(),
        }
    }
}

impl std::fmt::Display for ConnectionException {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.message)
    }
}

impl std::error::Error for ConnectionException {}

/// PHP `Utopia\Cache\Adapter\Redis\ConnectionError`.
#[derive(Debug, Clone)]
pub struct ConnectionError {
    pub exception: ConnectionException,
}

impl ConnectionError {
    #[must_use]
    pub fn new(exception: ConnectionException) -> Self {
        Self { exception }
    }
}

/// PHP `Utopia\Cache\Adapter\Redis\ConnectionContext`.
#[derive(Debug)]
pub struct ConnectionContext {
    pub client: super::Client,
    pub pending: std::collections::VecDeque<std::sync::mpsc::Sender<ParseOutcome>>,
}

impl ConnectionContext {
    #[must_use]
    pub fn new(client: super::Client) -> Self {
        Self {
            client,
            pending: std::collections::VecDeque::new(),
        }
    }
}

/// Outcome of [`super::Client::parse`].
#[derive(Debug, Clone, PartialEq)]
pub enum ParseOutcome {
    Incomplete,
    Value(RespValue),
}

/// A decoded RESP value. Redis error frames are wrapped, not thrown.
#[derive(Debug, Clone, PartialEq)]
pub enum RespValue {
    Nil,
    Simple(String),
    Integer(i64),
    Bulk(String),
    Array(Vec<RespValue>),
    RedisError(String),
    ConnectionError(String),
}

impl PartialEq<&str> for RespValue {
    fn eq(&self, other: &&str) -> bool {
        match self {
            Self::Simple(s) | Self::Bulk(s) => s == other,
            _ => false,
        }
    }
}
