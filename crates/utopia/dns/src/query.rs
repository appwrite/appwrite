//! A query as a server receives it: the message and where it came from.

use crate::message::{MAX_SIZE, MAX_UDP_SIZE, Message};

/// The transport a query arrived over.
///
/// PHP: `Utopia\DNS\Protocol`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Protocol {
    /// UDP (the source address can be forged).
    Udp,
    /// TCP.
    Tcp,
    /// DNS over HTTPS (RFC 8484).
    Https,
}

impl Protocol {
    /// The largest response it carries: 512 bytes over plain UDP
    /// (RFC 1035 §4.2.1), 65535 over streams.
    ///
    /// PHP: `maxResponseSize()`.
    pub fn max_response_size(self) -> usize {
        match self {
            Protocol::Udp => MAX_UDP_SIZE,
            Protocol::Tcp | Protocol::Https => MAX_SIZE,
        }
    }

    /// `udp`, `tcp` or `https`.
    ///
    /// PHP: the enum's value.
    pub fn as_str(self) -> &'static str {
        match self {
            Protocol::Udp => "udp",
            Protocol::Tcp => "tcp",
            Protocol::Https => "https",
        }
    }
}

/// A decoded query with its source: the client's address and port and the
/// transport. Resolver decorators read the source for tracing or rate
/// limiting; stream transports carry verified addresses, UDP does not.
///
/// PHP: `Utopia\DNS\Query`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Query {
    /// The query message.
    pub message: Message,
    /// The client's IP address.
    pub ip: String,
    /// The client's port.
    pub port: u16,
    /// The transport.
    pub protocol: Protocol,
}
