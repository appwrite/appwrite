use crate::message::Message;

/// Transport protocol a DNS query arrived over. PHP `Utopia\DNS\Protocol`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Protocol {
    Udp,
    Tcp,
    /// DNS-over-HTTPS (RFC 8484). PHP case name is `Https`.
    Https,
}

impl Protocol {
    /// Maximum response size the protocol can carry.
    ///
    /// 512 bytes for plain UDP per RFC 1035 Section 4.2.1, 65535 for streams.
    #[must_use]
    pub fn max_response_size(self) -> usize {
        match self {
            Self::Udp => Message::MAX_UDP_SIZE,
            Self::Tcp | Self::Https => Message::MAX_SIZE,
        }
    }

    /// PHP enum string value (`udp` / `tcp` / `https`).
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Udp => "udp",
            Self::Tcp => "tcp",
            Self::Https => "https",
        }
    }
}
