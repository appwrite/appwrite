use crate::wire::{self, Length, at};
use crate::{Error, Properties, Property};

/// A CONNECT: what a client asks for when it opens a session.
///
/// ```
/// use utopia_mqtt::packet::{Connect, Packet};
/// use utopia_mqtt::spec::v3;
///
/// let bytes = v3::connect(b"device-tv", 60, true, b"user", b"secret");
/// let connect = Connect::decode(Packet::parse(&bytes)?.body)?;
/// assert_eq!(connect.client_id, b"device-tv");
/// assert_eq!(connect.password.as_deref(), Some(&b"secret"[..]));
/// # Ok::<(), utopia_mqtt::Error>(())
/// ```
///
/// ```php
/// use Utopia\Mqtt\Packet;
/// use Utopia\Mqtt\Packet\Connect;
/// use Utopia\Mqtt\Packet\Specs\V3;
///
/// $connect = Connect::decode(Packet::parse(V3::connect('device-tv', 60, true, 'user', 'secret'))->body);
/// echo $connect->clientId, ' ', $connect->password, "\n"; // device-tv secret
/// ```
///
/// PHP: `Utopia\Mqtt\Packet\Connect`.
#[derive(Debug, Clone, PartialEq)]
pub struct Connect {
    /// The protocol level: 4 for MQTT 3.1.1, 5 for MQTT 5.0.
    pub protocol_level: u8,
    /// The Client Identifier (empty when the client leaves it to the
    /// server).
    pub client_id: Vec<u8>,
    /// Clean Start (5.0) / Clean Session (3.1.1).
    pub clean_start: bool,
    /// The keep-alive interval in seconds (0 disables it).
    pub keep_alive: u16,
    /// The user name, when the username flag is set.
    pub username: Option<Vec<u8>>,
    /// The password, when the password flag is set.
    pub password: Option<Vec<u8>>,
    /// The Will, when the will flag is set.
    pub will: Option<Will>,
    /// The Authentication Method property (5.0), when it is a string.
    pub auth_method: Option<Vec<u8>>,
    /// The Authentication Data property (5.0), when it is a string.
    pub auth_data: Option<Vec<u8>>,
    /// The property block (5.0 only).
    pub properties: Option<Properties>,
}

/// The Will of a CONNECT: the message the broker publishes for a client that
/// goes away without a DISCONNECT. Its 5.0 properties are skipped.
///
/// PHP: `Utopia\Mqtt\Packet\Will`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Will {
    /// The topic.
    pub topic: Vec<u8>,
    /// The payload.
    pub payload: Vec<u8>,
    /// The QoS.
    pub qos: u8,
    /// The RETAIN flag.
    pub retain: bool,
}

impl Connect {
    /// Decodes a CONNECT body.
    ///
    /// PHP: `Connect::decode(string $body)`.
    pub fn decode(body: &[u8]) -> Result<Self, Error> {
        let (_, offset) = wire::read_string(body, 0);
        let (level, offset) = wire::read_byte(body, offset);
        let (flags, offset) = wire::read_byte(body, offset);
        let (keep_alive, offset) = wire::read_int16(body, offset);

        let mut properties = None;
        let mut auth_method = None;
        let mut auth_data = None;
        let mut offset = offset;
        if level >= 5 {
            let (parsed, next) = Properties::parse(body, offset)?;
            auth_method = parsed.bytes(Property::AUTHENTICATION_METHOD).map(<[u8]>::to_vec);
            auth_data = parsed.bytes(Property::AUTHENTICATION_DATA).map(<[u8]>::to_vec);
            properties = Some(parsed);
            offset = next;
        }

        let (client_id, mut offset) = at::string(body, Length::from(offset))?;

        let mut will = None;
        if flags & 0x04 == 0x04 {
            if level >= 5 {
                let start = offset.as_int().ok_or_else(|| {
                    Error::Type(
                        "Utopia\\Mqtt\\Properties::skip(): Argument #2 ($offset) must be of type int, float given"
                            .into(),
                    )
                })?;
                offset = Length::from(Properties::skip(body, start as usize)?);
            }
            let (topic, next) = at::string(body, offset)?;
            let (payload, next) = at::string(body, next)?;
            offset = next;
            will = Some(Will {
                topic: topic.to_vec(),
                payload: payload.to_vec(),
                qos: (flags >> 3) & 0x03,
                retain: flags & 0x20 == 0x20,
            });
        }

        let mut username = None;
        if flags & 0x80 == 0x80 {
            let (value, next) = at::string(body, offset)?;
            username = Some(value.to_vec());
            offset = next;
        }

        let mut password = None;
        if flags & 0x40 == 0x40 {
            password = Some(at::string(body, offset)?.0.to_vec());
        }

        Ok(Connect {
            protocol_level: level,
            client_id: client_id.to_vec(),
            clean_start: flags & 0x02 == 0x02,
            keep_alive,
            username,
            password,
            will,
            auth_method,
            auth_data,
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
