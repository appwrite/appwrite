//! Control packet encoders for each protocol version: [`v3`] (MQTT 3.1.1)
//! and [`v5`] (MQTT 5.0).
//!
//! PHP: `Utopia\Mqtt\Packet\Specs\V3` and `V5`.

use crate::packet::Packet;
use crate::wire;
use crate::{Error, Properties};

/// The packet with this first byte and body.
fn frame(first: u8, body: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(body.len() + 5);
    out.push(first);
    wire::encode_length(body.len() as u64, &mut out);
    out.extend_from_slice(body);
    out
}

/// A property block, empty when there are no properties.
fn block(properties: Option<&Properties>, out: &mut Vec<u8>) -> Result<(), Error> {
    match properties {
        Some(properties) => properties.encode_into(out),
        None => {
            out.push(0);
            Ok(())
        }
    }
}

/// The first byte of a PUBLISH: QoS in bits 1-2, DUP (bit 3) only when the
/// QoS is above 0.
fn publish_header(qos: u8, dup: bool) -> u8 {
    let flags = (u32::from(qos) << 1) | if dup && qos > 0 { 0x08 } else { 0 };
    ((u32::from(Packet::PUBLISH) << 4) | flags) as u8
}

/// MQTT 3.1.1 encoders. There are no property blocks and no reason codes:
/// CONNACK carries a return code, SUBACK a granted QoS (or failure) byte per
/// filter, and there is no AUTH.
///
/// ```
/// use utopia_mqtt::spec::v3;
///
/// assert_eq!(v3::connack(v3::RETURN_ACCEPTED), b"\x20\x02\x00\x00");
/// assert_eq!(v3::suback(b"\x00\x02", &[v3::SUBSCRIBE_FAILURE]), b"\x90\x03\x00\x02\x80");
/// ```
///
/// ```php
/// use Utopia\Mqtt\Packet\Specs\V3;
///
/// echo bin2hex(V3::connack(V3::RETURN_ACCEPTED)), "\n"; // 20020000
/// echo bin2hex(V3::suback("\x00\x02", chr(V3::SUBSCRIBE_FAILURE))), "\n"; // 9003000280
/// ```
///
/// PHP: `Utopia\Mqtt\Packet\Specs\V3`.
pub mod v3 {
    use super::{frame, publish_header};
    use crate::packet::Packet;
    use crate::wire;

    /// The protocol level of MQTT 3.1.1.
    pub const PROTOCOL_LEVEL: u8 = 4;
    /// CONNACK: accepted.
    pub const RETURN_ACCEPTED: u8 = 0x00;
    /// CONNACK: unacceptable protocol version.
    pub const RETURN_UNACCEPTABLE_PROTOCOL: u8 = 0x01;
    /// CONNACK: identifier rejected.
    pub const RETURN_IDENTIFIER_REJECTED: u8 = 0x02;
    /// CONNACK: server unavailable.
    pub const RETURN_SERVER_UNAVAILABLE: u8 = 0x03;
    /// CONNACK: bad user name or password.
    pub const RETURN_BAD_CREDENTIALS: u8 = 0x04;
    /// CONNACK: not authorized.
    pub const RETURN_NOT_AUTHORIZED: u8 = 0x05;
    /// SUBACK: the failure marker (a granted QoS otherwise).
    pub const SUBSCRIBE_FAILURE: u8 = 0x80;

    /// CONNECT: protocol header and client id, plus the user name and
    /// password when they are not empty.
    ///
    /// PHP: `V3::connect(string $clientId, int $keepAlive = 60, bool $cleanSession = true, string $username = '', string $password = '')`.
    pub fn connect(
        client_id: &[u8],
        keep_alive: u16,
        clean_session: bool,
        username: &[u8],
        password: &[u8],
    ) -> Vec<u8> {
        let mut flags = if clean_session { 0x02 } else { 0x00 };
        if !username.is_empty() {
            flags |= 0x80;
        }
        if !password.is_empty() {
            flags |= 0x40;
        }
        let mut body = Vec::with_capacity(12 + client_id.len() + username.len() + password.len());
        wire::encode_string(b"MQTT", &mut body);
        body.push(PROTOCOL_LEVEL);
        body.push(flags);
        body.extend_from_slice(&keep_alive.to_be_bytes());
        wire::encode_string(client_id, &mut body);
        if !username.is_empty() {
            wire::encode_string(username, &mut body);
        }
        if !password.is_empty() {
            wire::encode_string(password, &mut body);
        }
        frame(Packet::CONNECT << 4, &body)
    }

    /// SUBSCRIBE: packet id, then each topic filter with `qos` as its options
    /// byte.
    ///
    /// PHP: `V3::subscribe(int $packetId, array $filters, int $qos = 0)`.
    pub fn subscribe<T: AsRef<[u8]>>(packet_id: u16, filters: &[T], qos: u8) -> Vec<u8> {
        let mut body = packet_id.to_be_bytes().to_vec();
        for filter in filters {
            wire::encode_string(filter.as_ref(), &mut body);
            body.push(qos);
        }
        frame((Packet::SUBSCRIBE << 4) | 0x02, &body)
    }

    /// UNSUBSCRIBE: packet id, then each topic filter.
    ///
    /// PHP: `V3::unsubscribe(int $packetId, array $filters)`.
    pub fn unsubscribe<T: AsRef<[u8]>>(packet_id: u16, filters: &[T]) -> Vec<u8> {
        let mut body = packet_id.to_be_bytes().to_vec();
        for filter in filters {
            wire::encode_string(filter.as_ref(), &mut body);
        }
        frame((Packet::UNSUBSCRIBE << 4) | 0x02, &body)
    }

    /// CONNACK: no acknowledge flags, then the return code.
    ///
    /// PHP: `V3::connack(int $returnCode)`.
    pub fn connack(return_code: u8) -> Vec<u8> {
        frame(Packet::CONNACK << 4, &[0x00, return_code])
    }

    /// PUBLISH: topic, packet id (above QoS 0), then payload. `dup` marks a
    /// re-delivery of a QoS 1 message and is ignored at QoS 0.
    ///
    /// PHP: `V3::publish(string $topic, string $payload, int $qos, int $packetId, bool $dup = false)`.
    pub fn publish(topic: &[u8], payload: &[u8], qos: u8, packet_id: u16, dup: bool) -> Vec<u8> {
        let mut body = Vec::with_capacity(4 + topic.len() + payload.len());
        wire::encode_string(topic, &mut body);
        if qos > 0 {
            body.extend_from_slice(&packet_id.to_be_bytes());
        }
        body.extend_from_slice(payload);
        frame(publish_header(qos, dup), &body)
    }

    /// PUBACK: the two-byte packet id, as given.
    ///
    /// PHP: `V3::puback(string $packetId)`.
    pub fn puback(packet_id: &[u8]) -> Vec<u8> {
        frame(Packet::PUBACK << 4, packet_id)
    }

    /// SUBACK: the packet id, then one granted QoS (or failure) byte per
    /// filter.
    ///
    /// PHP: `V3::suback(string $packetId, string $returnCodes)`.
    pub fn suback(packet_id: &[u8], return_codes: &[u8]) -> Vec<u8> {
        frame(Packet::SUBACK << 4, &[packet_id, return_codes].concat())
    }

    /// UNSUBACK: the two-byte packet id (3.1.1 has no reason codes).
    ///
    /// PHP: `V3::unsuback(string $packetId)`.
    pub fn unsuback(packet_id: &[u8]) -> Vec<u8> {
        frame(Packet::UNSUBACK << 4, packet_id)
    }

    /// DISCONNECT: no reason code, no properties.
    ///
    /// PHP: `V3::disconnect()`.
    pub fn disconnect() -> Vec<u8> {
        frame(Packet::DISCONNECT << 4, &[])
    }
}

/// MQTT 5.0 encoders. Every acknowledgement carries a reason code and a
/// property block (empty when `None`); PUBLISH carries the block before its
/// payload. Encoding fails only for a property with an unknown identifier.
///
/// ```
/// use utopia_mqtt::spec::v5;
///
/// assert_eq!(v5::connack(v5::REASON_SUCCESS, None)?, b"\x20\x03\x00\x00\x00");
/// assert_eq!(v5::disconnect(v5::REASON_NOT_AUTHORIZED, None)?, b"\xE0\x02\x87\x00");
/// # Ok::<(), utopia_mqtt::Error>(())
/// ```
///
/// ```php
/// use Utopia\Mqtt\Packet\Specs\V5;
///
/// echo bin2hex(V5::connack(V5::REASON_SUCCESS)), "\n"; // 2003000000
/// echo bin2hex(V5::disconnect(V5::REASON_NOT_AUTHORIZED)), "\n"; // e0028700
/// ```
///
/// PHP: `Utopia\Mqtt\Packet\Specs\V5`.
pub mod v5 {
    use super::{block, frame, publish_header};
    use crate::packet::Packet;
    use crate::wire;
    use crate::{Error, Properties};

    /// The protocol level of MQTT 5.0.
    pub const PROTOCOL_LEVEL: u8 = 5;
    /// Success, in every acknowledgement.
    pub const REASON_SUCCESS: u8 = 0x00;
    /// Not authorized.
    pub const REASON_NOT_AUTHORIZED: u8 = 0x87;
    /// AUTH: success.
    pub const AUTH_SUCCESS: u8 = 0x00;
    /// AUTH: continue authentication.
    pub const AUTH_CONTINUE: u8 = 0x18;
    /// AUTH: re-authenticate.
    pub const AUTH_REAUTH: u8 = 0x19;

    /// CONNECT: protocol header, properties (authentication, metadata), then
    /// the client id.
    ///
    /// PHP: `V5::connect(string $clientId, int $keepAlive = 60, bool $cleanStart = true, ?Properties $properties = null)`.
    pub fn connect(
        client_id: &[u8],
        keep_alive: u16,
        clean_start: bool,
        properties: Option<&Properties>,
    ) -> Result<Vec<u8>, Error> {
        let mut body = Vec::with_capacity(16 + client_id.len());
        wire::encode_string(b"MQTT", &mut body);
        body.push(PROTOCOL_LEVEL);
        body.push(if clean_start { 0x02 } else { 0x00 });
        body.extend_from_slice(&keep_alive.to_be_bytes());
        block(properties, &mut body)?;
        wire::encode_string(client_id, &mut body);
        Ok(frame(Packet::CONNECT << 4, &body))
    }

    /// SUBSCRIBE: packet id, properties, then each topic filter with `qos`
    /// as its options byte.
    ///
    /// PHP: `V5::subscribe(int $packetId, array $filters, int $qos = 0, ?Properties $properties = null)`.
    pub fn subscribe<T: AsRef<[u8]>>(
        packet_id: u16,
        filters: &[T],
        qos: u8,
        properties: Option<&Properties>,
    ) -> Result<Vec<u8>, Error> {
        let mut body = packet_id.to_be_bytes().to_vec();
        block(properties, &mut body)?;
        for filter in filters {
            wire::encode_string(filter.as_ref(), &mut body);
            body.push(qos);
        }
        Ok(frame((Packet::SUBSCRIBE << 4) | 0x02, &body))
    }

    /// UNSUBSCRIBE: packet id, properties, then each topic filter.
    ///
    /// PHP: `V5::unsubscribe(int $packetId, array $filters, ?Properties $properties = null)`.
    pub fn unsubscribe<T: AsRef<[u8]>>(
        packet_id: u16,
        filters: &[T],
        properties: Option<&Properties>,
    ) -> Result<Vec<u8>, Error> {
        let mut body = packet_id.to_be_bytes().to_vec();
        block(properties, &mut body)?;
        for filter in filters {
            wire::encode_string(filter.as_ref(), &mut body);
        }
        Ok(frame((Packet::UNSUBSCRIBE << 4) | 0x02, &body))
    }

    /// CONNACK: no acknowledge flags, the reason code, then properties.
    ///
    /// PHP: `V5::connack(int $reasonCode, ?Properties $properties = null)`.
    pub fn connack(reason_code: u8, properties: Option<&Properties>) -> Result<Vec<u8>, Error> {
        let mut body = vec![0x00, reason_code];
        block(properties, &mut body)?;
        Ok(frame(Packet::CONNACK << 4, &body))
    }

    /// PUBLISH: topic, packet id (above QoS 0), properties, then payload.
    /// `dup` marks a re-delivery of a QoS 1 message and is ignored at QoS 0.
    ///
    /// PHP: `V5::publish(string $topic, string $payload, int $qos, int $packetId, ?Properties $properties = null, bool $dup = false)`.
    pub fn publish(
        topic: &[u8],
        payload: &[u8],
        qos: u8,
        packet_id: u16,
        properties: Option<&Properties>,
        dup: bool,
    ) -> Result<Vec<u8>, Error> {
        let mut body = Vec::with_capacity(5 + topic.len() + payload.len());
        wire::encode_string(topic, &mut body);
        if qos > 0 {
            body.extend_from_slice(&packet_id.to_be_bytes());
        }
        block(properties, &mut body)?;
        body.extend_from_slice(payload);
        Ok(frame(publish_header(qos, dup), &body))
    }

    /// PUBACK: the two-byte packet id (reason and properties are omitted for
    /// a success).
    ///
    /// PHP: `V5::puback(string $packetId)`.
    pub fn puback(packet_id: &[u8]) -> Vec<u8> {
        frame(Packet::PUBACK << 4, packet_id)
    }

    /// SUBACK: packet id, properties, then one reason code per filter.
    ///
    /// PHP: `V5::suback(string $packetId, string $reasonCodes, ?Properties $properties = null)`.
    pub fn suback(packet_id: &[u8], reason_codes: &[u8], properties: Option<&Properties>) -> Result<Vec<u8>, Error> {
        let mut body = packet_id.to_vec();
        block(properties, &mut body)?;
        body.extend_from_slice(reason_codes);
        Ok(frame(Packet::SUBACK << 4, &body))
    }

    /// UNSUBACK: packet id, properties, then a success code for each of
    /// `count` filters.
    ///
    /// PHP: `V5::unsuback(string $packetId, int $count, ?Properties $properties = null)`.
    pub fn unsuback(packet_id: &[u8], count: usize, properties: Option<&Properties>) -> Result<Vec<u8>, Error> {
        let mut body = packet_id.to_vec();
        block(properties, &mut body)?;
        body.resize(body.len() + count, REASON_SUCCESS);
        Ok(frame(Packet::UNSUBACK << 4, &body))
    }

    /// DISCONNECT: the reason code, then properties.
    ///
    /// PHP: `V5::disconnect(int $reasonCode, ?Properties $properties = null)`.
    pub fn disconnect(reason_code: u8, properties: Option<&Properties>) -> Result<Vec<u8>, Error> {
        let mut body = vec![reason_code];
        block(properties, &mut body)?;
        Ok(frame(Packet::DISCONNECT << 4, &body))
    }

    /// AUTH: the reason code, then properties (the Authentication Method and
    /// Data live in the block).
    ///
    /// PHP: `V5::auth(int $reasonCode, ?Properties $properties = null)`.
    pub fn auth(reason_code: u8, properties: Option<&Properties>) -> Result<Vec<u8>, Error> {
        let mut body = vec![reason_code];
        block(properties, &mut body)?;
        Ok(frame(Packet::AUTH << 4, &body))
    }
}
