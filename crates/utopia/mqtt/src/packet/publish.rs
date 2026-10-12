use super::Packet;
use crate::wire::{self, Length, at};
use crate::{Error, Properties};

/// A PUBLISH: a message on a topic.
///
/// ```
/// use utopia_mqtt::packet::{Packet, Publish};
/// use utopia_mqtt::spec::v5;
/// use utopia_mqtt::{Properties, Property};
///
/// let properties = Properties::new().with(Property::user("unit", "C"));
/// let bytes = v5::publish(b"sensors/room1/temp", b"21.5", 1, 100, Some(&properties), false)?;
/// let publish = Publish::decode(&Packet::parse(&bytes)?, 5)?;
/// assert_eq!((publish.topic.as_slice(), publish.payload.as_slice()), (&b"sensors/room1/temp"[..], &b"21.5"[..]));
/// assert_eq!((publish.qos, publish.packet_id), (1, 100));
/// assert_eq!(publish.user_properties(), [(b"unit".to_vec(), b"C".to_vec())]);
/// # Ok::<(), utopia_mqtt::Error>(())
/// ```
///
/// ```php
/// use Utopia\Mqtt\Packet;
/// use Utopia\Mqtt\Packet\Publish;
/// use Utopia\Mqtt\Packet\Specs\V5;
/// use Utopia\Mqtt\Properties;
/// use Utopia\Mqtt\Property;
///
/// $properties = (new Properties())->add(new Property(Property::USER, ['unit' => 'C']));
/// $publish = Publish::decode(Packet::parse(V5::publish('sensors/room1/temp', '21.5', 1, 100, $properties)), 5);
/// echo $publish->topic, ' ', $publish->payload, ' ', $publish->packetId, "\n"; // sensors/room1/temp 21.5 100
/// ```
///
/// PHP: `Utopia\Mqtt\Packet\Publish`.
#[derive(Debug, Clone, PartialEq)]
pub struct Publish {
    /// The topic.
    pub topic: Vec<u8>,
    /// The payload.
    pub payload: Vec<u8>,
    /// The QoS.
    pub qos: u8,
    /// The DUP flag.
    pub dup: bool,
    /// The RETAIN flag.
    pub retain: bool,
    /// The packet id (0 at QoS 0).
    pub packet_id: u16,
    /// The property block (5.0 only).
    pub properties: Option<Properties>,
}

impl Publish {
    /// Decodes a PUBLISH sent by a client speaking `protocol` (4 or 5).
    ///
    /// PHP: `Publish::decode(Packet $packet, int $protocol)`.
    pub fn decode(packet: &Packet<'_>, protocol: u8) -> Result<Self, Error> {
        let body = packet.body;
        let (topic, offset) = wire::read_string(body, 0);
        let mut offset = offset;
        let mut packet_id = 0;
        if packet.qos() > 0 {
            (packet_id, offset) = wire::read_int16(body, offset);
        }
        let mut properties = None;
        if protocol >= 5 {
            let (parsed, next) = Properties::parse(body, offset)?;
            properties = Some(parsed);
            offset = next;
        }
        Ok(Self {
            topic: topic.to_vec(),
            payload: at::substr(body, Length::from(offset), None)?.to_vec(),
            qos: packet.qos(),
            dup: packet.dup(),
            retain: packet.retain(),
            packet_id,
            properties,
        })
    }

    /// The merged User Properties (see [`Properties::user`]).
    ///
    /// PHP: `userProperties()`.
    pub fn user_properties(&self) -> Vec<(Vec<u8>, Vec<u8>)> {
        self.properties.as_ref().map(Properties::user).unwrap_or_default()
    }
}

/// A PUBACK: a client acknowledging a QoS 1 delivery.
///
/// PHP: `Utopia\Mqtt\Packet\Puback`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Puback {
    /// The packet id acknowledged.
    pub packet_id: u16,
    /// The reason code (0 when the client omits it).
    pub reason_code: u8,
}

impl Puback {
    /// Decodes a PUBACK body.
    ///
    /// PHP: `Puback::decode(string $body)`.
    pub fn decode(body: &[u8]) -> Self {
        let (packet_id, offset) = wire::read_int16(body, 0);
        Self { packet_id, reason_code: body.get(offset).copied().unwrap_or(0) }
    }
}
