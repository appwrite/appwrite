//! MQTT control packets: the fixed header ([`Packet`], [`Kind`]), splitting
//! a byte stream into packets ([`frames`]), and the decoded form of each
//! packet a broker reads or answers with.
//!
//! PHP: `Utopia\Mqtt\Packet` and the `Utopia\Mqtt\Packet\*` classes.

mod auth;
mod connack;
mod connect;
mod disconnect;
mod publish;
mod subscribe;
mod unsubscribe;

pub use auth::Auth;
pub use connack::Connack;
pub use connect::{Connect, Will};
pub use disconnect::Disconnect;
pub use publish::{Puback, Publish};
pub use subscribe::{Filter, Suback, Subscribe};
pub use unsubscribe::{Unsuback, Unsubscribe};

use crate::Error;
use crate::wire::{self, Length};

/// The type of a control packet (the high nibble of its first byte).
///
/// PHP: the `Packet::CONNECT` ... `Packet::AUTH` constants.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Kind {
    /// CONNECT (1).
    Connect,
    /// CONNACK (2).
    Connack,
    /// PUBLISH (3).
    Publish,
    /// PUBACK (4).
    Puback,
    /// SUBSCRIBE (8).
    Subscribe,
    /// SUBACK (9).
    Suback,
    /// UNSUBSCRIBE (10).
    Unsubscribe,
    /// UNSUBACK (11).
    Unsuback,
    /// PINGREQ (12).
    Pingreq,
    /// PINGRESP (13).
    Pingresp,
    /// DISCONNECT (14).
    Disconnect,
    /// AUTH (15).
    Auth,
    /// Any other type: 0 (reserved), and PUBREC, PUBREL and PUBCOMP (5 to 7),
    /// which this library does not speak (it delivers at most QoS 1).
    Other(u8),
}

impl Kind {
    /// The type for a fixed header's high nibble.
    pub fn from_code(code: u8) -> Self {
        match code {
            Packet::CONNECT => Kind::Connect,
            Packet::CONNACK => Kind::Connack,
            Packet::PUBLISH => Kind::Publish,
            Packet::PUBACK => Kind::Puback,
            Packet::SUBSCRIBE => Kind::Subscribe,
            Packet::SUBACK => Kind::Suback,
            Packet::UNSUBSCRIBE => Kind::Unsubscribe,
            Packet::UNSUBACK => Kind::Unsuback,
            Packet::PINGREQ => Kind::Pingreq,
            Packet::PINGRESP => Kind::Pingresp,
            Packet::DISCONNECT => Kind::Disconnect,
            Packet::AUTH => Kind::Auth,
            other => Kind::Other(other),
        }
    }

    /// The fixed header's high nibble.
    pub fn code(self) -> u8 {
        match self {
            Kind::Connect => Packet::CONNECT,
            Kind::Connack => Packet::CONNACK,
            Kind::Publish => Packet::PUBLISH,
            Kind::Puback => Packet::PUBACK,
            Kind::Subscribe => Packet::SUBSCRIBE,
            Kind::Suback => Packet::SUBACK,
            Kind::Unsubscribe => Packet::UNSUBSCRIBE,
            Kind::Unsuback => Packet::UNSUBACK,
            Kind::Pingreq => Packet::PINGREQ,
            Kind::Pingresp => Packet::PINGRESP,
            Kind::Disconnect => Packet::DISCONNECT,
            Kind::Auth => Packet::AUTH,
            Kind::Other(code) => code,
        }
    }

    /// The lowercase name (`connect`, `publish`, ...; `unknown` for
    /// [`Kind::Other`]), as telemetry labels packets.
    ///
    /// PHP: `Packet::name()`.
    pub fn name(self) -> &'static str {
        match self {
            Kind::Connect => "connect",
            Kind::Connack => "connack",
            Kind::Publish => "publish",
            Kind::Puback => "puback",
            Kind::Subscribe => "subscribe",
            Kind::Suback => "suback",
            Kind::Unsubscribe => "unsubscribe",
            Kind::Unsuback => "unsuback",
            Kind::Pingreq => "pingreq",
            Kind::Pingresp => "pingresp",
            Kind::Disconnect => "disconnect",
            Kind::Auth => "auth",
            Kind::Other(_) => "unknown",
        }
    }
}

/// A framed control packet: its type and flags (the fixed header) and the
/// rest of it, borrowed from the bytes it was parsed from.
///
/// ```
/// use utopia_mqtt::packet::{Kind, Packet};
/// use utopia_mqtt::spec::v3;
///
/// let bytes = v3::publish(b"sport/tennis", b"hi", 1, 7, false);
/// let packet = Packet::parse(&bytes)?;
/// assert_eq!(packet.kind, Kind::Publish);
/// assert_eq!(packet.qos(), 1);
/// assert_eq!(packet.name(), "publish");
/// # Ok::<(), utopia_mqtt::Error>(())
/// ```
///
/// ```php
/// use Utopia\Mqtt\Packet;
/// use Utopia\Mqtt\Packet\Specs\V3;
///
/// $packet = Packet::parse(V3::publish('sport/tennis', 'hi', 1, 7));
/// echo $packet->name(), ' ', $packet->qos(), "\n"; // publish 1
/// ```
///
/// PHP: `Utopia\Mqtt\Packet` (`$type`, `$flags`, `$body`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Packet<'a> {
    /// The packet type.
    pub kind: Kind,
    /// The fixed header's low nibble.
    pub flags: u8,
    /// Everything after the fixed header, up to its remaining length.
    pub body: &'a [u8],
}

impl<'a> Packet<'a> {
    /// CONNECT's type code.
    pub const CONNECT: u8 = 1;
    /// CONNACK's type code.
    pub const CONNACK: u8 = 2;
    /// PUBLISH's type code.
    pub const PUBLISH: u8 = 3;
    /// PUBACK's type code.
    pub const PUBACK: u8 = 4;
    /// SUBSCRIBE's type code.
    pub const SUBSCRIBE: u8 = 8;
    /// SUBACK's type code.
    pub const SUBACK: u8 = 9;
    /// UNSUBSCRIBE's type code.
    pub const UNSUBSCRIBE: u8 = 10;
    /// UNSUBACK's type code.
    pub const UNSUBACK: u8 = 11;
    /// PINGREQ's type code.
    pub const PINGREQ: u8 = 12;
    /// PINGRESP's type code.
    pub const PINGRESP: u8 = 13;
    /// DISCONNECT's type code.
    pub const DISCONNECT: u8 = 14;
    /// AUTH's type code.
    pub const AUTH: u8 = 15;
    /// QoS 1 (at least once), the highest QoS delivered.
    pub const QOS_1: u8 = 1;

    /// Splits one framed packet into its type, flags and body. A stream
    /// framed with MQTT's rules (a TCP listener, [`frames`]) delivers exactly
    /// one packet per read. Short input reads as zeros; a remaining length
    /// PHP's arithmetic carries past the 64-bit range is an [`Error::Type`].
    ///
    /// PHP: `Packet::parse(string $data)`.
    pub fn parse(data: &'a [u8]) -> Result<Self, Error> {
        let first = wire::byte(data, 0);
        let (remaining, bytes) = wire::decode_length(data, 1);
        let remaining = remaining
            .as_int()
            .ok_or_else(|| Error::Type("substr(): Argument #3 ($length) must be of type ?int, float given".into()))?;
        let body = wire::at::substr(data, Length::from(1 + bytes), Some(remaining))?;
        Ok(Packet { kind: Kind::from_code(first >> 4), flags: first & 0x0F, body })
    }

    /// The type's lowercase name.
    ///
    /// PHP: `name()`.
    pub fn name(&self) -> &'static str {
        self.kind.name()
    }

    /// The QoS of a PUBLISH, read from its flags.
    ///
    /// PHP: `qos()`.
    pub fn qos(&self) -> u8 {
        (self.flags >> 1) & 0x03
    }

    /// The DUP flag of a PUBLISH: a possible re-delivery of an earlier QoS 1
    /// attempt.
    ///
    /// PHP: `dup()`.
    pub fn dup(&self) -> bool {
        self.flags & 0x08 == 0x08
    }

    /// The RETAIN flag of a PUBLISH.
    pub fn retain(&self) -> bool {
        self.flags & 0x01 == 0x01
    }

    /// Clean Start (5.0) / Clean Session (3.1.1) of a CONNECT body: bit 1 of
    /// the connect flags, at the same place in both versions.
    ///
    /// PHP: `Packet::isCleanStart(string $body)`.
    pub fn is_clean_start(body: &[u8]) -> bool {
        let (_, offset) = wire::read_string(body, 0);
        wire::byte(body, offset + 1) & 0x02 == 0x02
    }

    /// The Client Identifier of a CONNECT body, the first payload field. On
    /// 5.0 it follows the property block, which is skipped.
    ///
    /// PHP: `Packet::getClientId(string $body)`.
    pub fn client_id(body: &[u8]) -> Result<Vec<u8>, Error> {
        let (_, offset) = wire::read_string(body, 0);
        let level = wire::byte(body, offset);
        let offset = offset + 4;
        let at = if level >= 5 {
            let (length, bytes) = wire::decode_length(body, offset);
            Length::from(offset).plus(Length::from(bytes).plus(length))
        } else {
            Length::from(offset)
        };
        Ok(wire::at::string(body, at)?.0.to_vec())
    }
}

/// Splits a byte stream into whole packets, and returns them with the
/// trailing bytes that do not make a whole packet yet. Used where the
/// carrier's framing does not follow MQTT's (a WebSocket message may hold
/// several packets, or part of one): keep the remainder and prepend it to
/// what arrives next. A remaining length longer than four bytes never
/// completes.
///
/// ```
/// use utopia_mqtt::packet::{frames, pingreq};
///
/// let mut stream = [pingreq(), pingreq()].concat();
/// stream.push(0xC0);
/// let (packets, rest) = frames(&stream);
/// assert_eq!(packets, [&pingreq()[..], &pingreq()[..]]);
/// assert_eq!(rest, [0xC0]);
/// ```
///
/// ```php
/// use Utopia\Mqtt\Packet;
///
/// [$packets, $rest] = Packet::frames(Packet::pingreq() . Packet::pingreq() . "\xC0");
/// echo count($packets), ' ', bin2hex($rest), "\n"; // 2 c0
/// ```
///
/// PHP: `Packet::frames(string $buffer)`.
pub fn frames(buffer: &[u8]) -> (Vec<&[u8]>, &[u8]) {
    let mut packets = Vec::new();
    let length = buffer.len();
    let mut offset = 0;
    while length - offset >= 2 {
        let mut multiplier = 1usize;
        let mut remaining = 0usize;
        let mut cursor = offset + 1;
        let mut complete = false;
        loop {
            if cursor >= length {
                break;
            }
            let b = buffer[cursor];
            remaining += usize::from(b & 0x7F) * multiplier;
            multiplier *= 128;
            cursor += 1;
            if b & 0x80 == 0 {
                complete = true;
                break;
            }
            if cursor - offset > 4 {
                break;
            }
        }
        let total = (cursor - offset) + remaining;
        if !complete || length - offset < total {
            break;
        }
        packets.push(&buffer[offset..offset + total]);
        offset += total;
    }
    (packets, &buffer[offset..])
}

/// A PINGREQ.
///
/// PHP: `Packet::pingreq()`.
pub fn pingreq() -> [u8; 2] {
    [Packet::PINGREQ << 4, 0]
}

/// A PINGRESP.
///
/// PHP: `Packet::pingresp()`.
pub fn pingresp() -> [u8; 2] {
    [Packet::PINGRESP << 4, 0]
}
