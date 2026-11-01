use crate::wire::{self, Length, at};
use crate::{Error, Properties};

/// A SUBSCRIBE: topic filters a client asks to receive.
///
/// ```
/// use utopia_mqtt::packet::{Filter, Packet, Subscribe};
/// use utopia_mqtt::spec::v5;
///
/// let bytes = v5::subscribe(1, &[b"sensors/+/temp"], 1, None)?;
/// let subscribe = Subscribe::decode(Packet::parse(&bytes)?.body, 5)?;
/// assert_eq!(subscribe.packet_id, 1);
/// assert_eq!(subscribe.filters, [Filter { topic: b"sensors/+/temp".to_vec(), qos: 1 }]);
/// # Ok::<(), utopia_mqtt::Error>(())
/// ```
///
/// ```php
/// use Utopia\Mqtt\Packet;
/// use Utopia\Mqtt\Packet\Specs\V5;
/// use Utopia\Mqtt\Packet\Subscribe;
///
/// $subscribe = Subscribe::decode(Packet::parse(V5::subscribe(1, ['sensors/+/temp'], 1))->body, 5);
/// echo $subscribe->filters()[0]->topic, ' ', $subscribe->filters()[0]->qos, "\n"; // sensors/+/temp 1
/// ```
///
/// PHP: `Utopia\Mqtt\Packet\Subscribe`.
#[derive(Debug, Clone, PartialEq)]
pub struct Subscribe {
    /// The packet id the SUBACK answers.
    pub packet_id: u16,
    /// The topic filters with their requested QoS, in order.
    ///
    /// PHP: `filters()`.
    pub filters: Vec<Filter>,
    /// The property block (5.0 only).
    pub properties: Option<Properties>,
}

/// One topic filter of a SUBSCRIBE and the QoS asked for it.
///
/// PHP: `Utopia\Mqtt\Packet\Filter`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Filter {
    /// The topic filter.
    pub topic: Vec<u8>,
    /// The QoS (the low two bits of the options byte).
    pub qos: u8,
}

impl Subscribe {
    /// Decodes a SUBSCRIBE body sent by a client speaking `protocol`.
    ///
    /// PHP: `Subscribe::decode(string $body, int $protocol)`.
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
            let (topic, next) = at::string(body, offset)?;
            let qos = if at::isset(body, next) { at::byte(body, next)?.0 & 0x03 } else { 0 };
            offset = next.plus(Length::Int(1));
            filters.push(Filter { topic: topic.to_vec(), qos });
        }
        Ok(Self { packet_id, filters, properties })
    }
}

/// A SUBACK being built: one code per filter, in the SUBSCRIBE's order.
///
/// ```
/// use utopia_mqtt::packet::Suback;
///
/// let mut suback = Suback::new();
/// suback.grant(1).deny(Suback::DENIED);
/// assert_eq!(suback.codes(), [1, 0x80]);
/// ```
///
/// ```php
/// use Utopia\Mqtt\Packet\Suback;
///
/// print_r((new Suback())->grant(1)->deny()->codes()); // [1, 128]
/// ```
///
/// PHP: `Utopia\Mqtt\Packet\Suback`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Suback {
    codes: Vec<u8>,
}

impl Suback {
    /// The failure code PHP's `deny()` defaults to.
    pub const DENIED: u8 = 0x80;

    /// No codes yet.
    pub fn new() -> Self {
        Self::default()
    }

    /// Grants the next filter at `qos`.
    ///
    /// PHP: `grant(int $qos)`.
    pub fn grant(&mut self, qos: u8) -> &mut Self {
        self.codes.push(qos);
        self
    }

    /// Denies the next filter with a reason code ([`Suback::DENIED`] in
    /// PHP's default).
    ///
    /// PHP: `deny(int $reasonCode = Suback::DENIED)`.
    pub fn deny(&mut self, reason_code: u8) -> &mut Self {
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
