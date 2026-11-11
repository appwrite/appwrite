use crate::wire::{self, Length, at};
use crate::{Error, Properties};

/// An UNSUBSCRIBE: topic filters a client no longer wants.
///
/// ```
/// use utopia_mqtt::packet::{Packet, Unsubscribe};
/// use utopia_mqtt::spec::v3;
///
/// let bytes = v3::unsubscribe(2, &[b"news/tech"]);
/// let unsubscribe = Unsubscribe::decode(Packet::parse(&bytes)?.body, 4)?;
/// assert_eq!((unsubscribe.packet_id, unsubscribe.filters), (2, vec![b"news/tech".to_vec()]));
/// # Ok::<(), utopia_mqtt::Error>(())
/// ```
///
/// ```php
/// use Utopia\Mqtt\Packet;
/// use Utopia\Mqtt\Packet\Specs\V3;
/// use Utopia\Mqtt\Packet\Unsubscribe;
///
/// $unsubscribe = Unsubscribe::decode(Packet::parse(V3::unsubscribe(2, ['news/tech']))->body, 4);
/// echo $unsubscribe->packetId, ' ', $unsubscribe->filters()[0], "\n"; // 2 news/tech
/// ```
///
/// PHP: `Utopia\Mqtt\Packet\Unsubscribe`.
#[derive(Debug, Clone, PartialEq)]
pub struct Unsubscribe {
    /// The packet id the UNSUBACK answers.
    pub packet_id: u16,
    /// The topic filters, in order.
    ///
    /// PHP: `filters()`.
    pub filters: Vec<Vec<u8>>,
    /// The property block (5.0 only).
    pub properties: Option<Properties>,
}

impl Unsubscribe {
    /// Decodes an UNSUBSCRIBE body sent by a client speaking `protocol`.
    ///
    /// PHP: `Unsubscribe::decode(string $body, int $protocol)`.
    pub fn decode(body: &[u8], protocol: u8) -> Result<Self, Error> {
        let (packet_id, offset) = wire::read_int16(body, 0);
        let mut properties = None;
        let mut offset = offset;
        if protocol >= 5 {
            let (parsed, next) = Properties::parse(body, offset)?;
            properties = Some(parsed);
            offset = next;
        }
        let mut offset = Length::from(offset);
        let mut filters = Vec::new();
        while at::before(offset, body.len()) {
            let (filter, next) = at::string(body, offset)?;
            offset = next;
            filters.push(filter.to_vec());
        }
        Ok(Self { packet_id, filters, properties })
    }
}

/// An UNSUBACK being built: one code per filter (5.0 sends them; 3.1.1 has
/// none).
///
/// PHP: `Utopia\Mqtt\Packet\Unsuback`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Unsuback {
    codes: Vec<u8>,
}

impl Unsuback {
    /// Success.
    pub const SUCCESS: u8 = 0x00;
    /// No subscription existed.
    pub const NO_SUBSCRIPTION_EXISTED: u8 = 0x11;
    /// Not authorized.
    pub const NOT_AUTHORIZED: u8 = 0x87;

    /// No codes yet.
    pub fn new() -> Self {
        Self::default()
    }

    /// The next filter was unsubscribed.
    ///
    /// PHP: `success()`.
    pub fn success(&mut self) -> &mut Self {
        self.codes.push(Self::SUCCESS);
        self
    }

    /// The next filter failed with a reason code
    /// ([`Unsuback::NOT_AUTHORIZED`] in PHP's default).
    ///
    /// PHP: `fail(int $reasonCode = Unsuback::NOT_AUTHORIZED)`.
    pub fn fail(&mut self, reason_code: u8) -> &mut Self {
        self.codes.push(reason_code);
        self
    }

    /// The codes, one per filter answered.
    ///
    /// PHP: `codes()`.
    pub fn codes(&self) -> &[u8] {
        &self.codes
    }
}
