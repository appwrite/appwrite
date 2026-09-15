//! Exceptions matching `Utopia\NATS\Exception`.

use thiserror::Error;

#[derive(Debug, Error)]
#[error("{0}")]
pub struct NatsException(pub String);

#[derive(Debug, Error)]
#[error("{0}")]
pub struct ConnectionException(pub String);

#[derive(Debug, Error)]
#[error("{0}")]
pub struct TimeoutException(pub String);

#[derive(Debug, Error)]
#[error("{0}")]
pub struct ProtocolException(pub String);

#[derive(Debug, Error)]
#[error("{0}")]
pub struct AuthenticationException(pub String);

#[derive(Debug, Error)]
#[error("{0}")]
pub struct PermissionException(pub String);

#[derive(Debug, Error)]
#[error("{0}")]
pub struct MaxPayloadException(pub String);

#[derive(Debug)]
pub struct JetStreamException {
    pub message: String,
    pub api_error: Option<crate::jetstream::ApiError>,
}

impl std::fmt::Display for JetStreamException {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.message)
    }
}

impl std::error::Error for JetStreamException {}

impl JetStreamException {
    pub fn new(message: impl Into<String>) -> Self {
        Self {
            message: message.into(),
            api_error: None,
        }
    }
}

#[derive(Debug, Error)]
#[error("{0}")]
pub struct KeyValueException(pub String);

#[derive(Debug, Error)]
#[error("{0}")]
pub struct ObjectStoreException(pub String);

#[derive(Debug, Error)]
pub enum NatsError {
    #[error("{0}")]
    Nats(#[from] NatsException),
    #[error("{0}")]
    Connection(#[from] ConnectionException),
    #[error("{0}")]
    Timeout(#[from] TimeoutException),
    #[error("{0}")]
    Protocol(#[from] ProtocolException),
    #[error("{0}")]
    Authentication(#[from] AuthenticationException),
    #[error("{0}")]
    Permission(#[from] PermissionException),
    #[error("{0}")]
    MaxPayload(#[from] MaxPayloadException),
    #[error("{0}")]
    JetStream(#[from] JetStreamException),
    #[error("{0}")]
    KeyValue(#[from] KeyValueException),
    #[error("{0}")]
    ObjectStore(#[from] ObjectStoreException),
}

impl NatsError {
    pub fn message(&self) -> String {
        self.to_string()
    }

    pub fn is_timeout(&self) -> bool {
        matches!(self, Self::Timeout(_))
    }

    pub fn is_connection(&self) -> bool {
        matches!(self, Self::Connection(_) | Self::Timeout(_))
    }
}
