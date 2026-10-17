use crate::{Properties, Property};

/// A DISCONNECT: a client leaving, or the broker closing a 5.0 client with a
/// reason.
///
/// ```
/// use utopia_mqtt::Property;
/// use utopia_mqtt::packet::Disconnect;
///
/// let refused = Disconnect::refuse(Disconnect::NOT_AUTHORIZED, Some(b"Session expired"));
/// let reason = refused.properties.as_ref().and_then(|p| p.bytes(Property::REASON_STRING));
/// assert_eq!(reason, Some(&b"Session expired"[..]));
/// assert_eq!(Disconnect::normal(Some(b"")).properties, None);
/// ```
///
/// ```php
/// use Utopia\Mqtt\Packet\Disconnect;
/// use Utopia\Mqtt\Property;
///
/// $refused = Disconnect::refuse(Disconnect::NOT_AUTHORIZED, 'Session expired');
/// echo $refused->properties->get(Property::REASON_STRING), "\n"; // Session expired
/// var_dump(Disconnect::normal('')->properties); // NULL
/// ```
///
/// PHP: `Utopia\Mqtt\Packet\Disconnect`.
#[derive(Debug, Clone, PartialEq)]
pub struct Disconnect {
    /// The reason code.
    pub reason_code: u8,
    /// The property block (a Reason String) sent to 5.0 clients.
    pub properties: Option<Properties>,
}

impl Disconnect {
    /// Normal disconnection.
    pub const NORMAL: u8 = 0x00;
    /// Not authorized.
    pub const NOT_AUTHORIZED: u8 = 0x87;

    /// Decodes a DISCONNECT body: its reason code (normal when empty). The
    /// properties are not read.
    ///
    /// PHP: `Disconnect::decode(string $body)`.
    pub fn decode(body: &[u8]) -> Self {
        Self { reason_code: body.first().copied().unwrap_or(Self::NORMAL), properties: None }
    }

    /// A broker-initiated DISCONNECT with a reason code and, when given and
    /// not empty, a Reason String. The Reason String is diagnostic: 3.1.1
    /// has no properties and never sees it.
    ///
    /// PHP: `Disconnect::refuse(int $reasonCode = Disconnect::NOT_AUTHORIZED, ?string $reason = null)`.
    pub fn refuse(reason_code: u8, reason: Option<&[u8]>) -> Self {
        Self { reason_code, properties: reason_properties(reason) }
    }

    /// A normal DISCONNECT, with an optional Reason String.
    ///
    /// PHP: `Disconnect::normal(?string $reason = null)`.
    pub fn normal(reason: Option<&[u8]>) -> Self {
        Self::refuse(Self::NORMAL, reason)
    }
}

/// A block holding the Reason String, or none for a missing or empty one.
fn reason_properties(reason: Option<&[u8]>) -> Option<Properties> {
    reason.filter(|r| !r.is_empty()).map(|r| Properties::new().with(Property::bytes(Property::REASON_STRING, r)))
}
