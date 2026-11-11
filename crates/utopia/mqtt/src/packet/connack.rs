use crate::Properties;

/// A CONNACK: the broker's answer to a CONNECT.
///
/// ```
/// use utopia_mqtt::packet::Connack;
///
/// assert!(Connack::accept(false, None).accepted());
/// assert_eq!(Connack::refuse(Connack::BAD_CREDENTIALS, None).reason_code, 0x86);
/// ```
///
/// ```php
/// use Utopia\Mqtt\Packet\Connack;
///
/// var_dump(Connack::accept()->accepted()); // true
/// echo Connack::refuse(Connack::BAD_CREDENTIALS)->reasonCode, "\n"; // 134
/// ```
///
/// PHP: `Utopia\Mqtt\Packet\Connack`.
#[derive(Debug, Clone, PartialEq)]
pub struct Connack {
    /// The reason code (5.0); 3.1.1 clients get the matching return code.
    pub reason_code: u8,
    /// Whether the broker resumed a stored session.
    pub session_present: bool,
    /// The property block sent to 5.0 clients.
    pub properties: Option<Properties>,
}

impl Connack {
    /// Success.
    pub const SUCCESS: u8 = 0x00;
    /// Unspecified error.
    pub const UNSPECIFIED_ERROR: u8 = 0x80;
    /// Bad user name or password.
    pub const BAD_CREDENTIALS: u8 = 0x86;
    /// Not authorized.
    pub const NOT_AUTHORIZED: u8 = 0x87;
    /// Server unavailable.
    pub const SERVER_UNAVAILABLE: u8 = 0x88;
    /// Server busy.
    pub const SERVER_BUSY: u8 = 0x89;
    /// Quota exceeded.
    pub const QUOTA_EXCEEDED: u8 = 0x97;

    /// Accepts the connection.
    ///
    /// PHP: `Connack::accept(bool $sessionPresent = false, ?Properties $properties = null)`.
    pub fn accept(session_present: bool, properties: Option<Properties>) -> Self {
        Self { reason_code: Self::SUCCESS, session_present, properties }
    }

    /// Refuses the connection with a reason code
    /// ([`Connack::NOT_AUTHORIZED`] in PHP's default).
    ///
    /// PHP: `Connack::refuse(int $reasonCode = Connack::NOT_AUTHORIZED, ?Properties $properties = null)`.
    pub fn refuse(reason_code: u8, properties: Option<Properties>) -> Self {
        Self { reason_code, session_present: false, properties }
    }

    /// Whether the connection was accepted.
    ///
    /// PHP: `accepted()`.
    pub fn accepted(&self) -> bool {
        self.reason_code == Self::SUCCESS
    }
}
