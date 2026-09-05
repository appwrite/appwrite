use crate::message::Message;
use crate::protocol::Protocol;

/// A DNS query together with its source. PHP `Utopia\DNS\Query`.
#[derive(Debug, Clone)]
pub struct Query {
    pub message: Message,
    pub ip: String,
    pub port: u16,
    pub protocol: Protocol,
}

impl Query {
    #[must_use]
    pub fn new(message: Message, ip: impl Into<String>, port: u16, protocol: Protocol) -> Self {
        Self {
            message,
            ip: ip.into(),
            port,
            protocol,
        }
    }
}
