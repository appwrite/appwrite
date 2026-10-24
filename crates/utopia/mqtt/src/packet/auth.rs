use crate::wire;
use crate::{Error, Properties, Property};

/// An AUTH (5.0 only): one step of enhanced authentication, or a
/// re-authentication on an open connection.
///
/// ```
/// use utopia_mqtt::packet::{Auth, Packet};
/// use utopia_mqtt::spec::v5;
/// use utopia_mqtt::{Properties, Property};
///
/// let properties = Properties::new().with(Property::bytes(Property::AUTHENTICATION_METHOD, "token"));
/// let bytes = v5::auth(Auth::REAUTH, Some(&properties))?;
/// let auth = Auth::decode(Packet::parse(&bytes)?.body)?;
/// assert_eq!((auth.reason_code, auth.method.as_slice()), (Auth::REAUTH, &b"token"[..]));
/// # Ok::<(), utopia_mqtt::Error>(())
/// ```
///
/// ```php
/// use Utopia\Mqtt\Packet;
/// use Utopia\Mqtt\Packet\Auth;
/// use Utopia\Mqtt\Packet\Specs\V5;
/// use Utopia\Mqtt\Properties;
/// use Utopia\Mqtt\Property;
///
/// $properties = (new Properties())->add(new Property(Property::AUTHENTICATION_METHOD, 'token'));
/// $auth = Auth::decode(Packet::parse(V5::auth(Auth::REAUTH, $properties))->body);
/// echo $auth->reasonCode, ' ', $auth->method, "\n"; // 25 token
/// ```
///
/// PHP: `Utopia\Mqtt\Packet\Auth`.
#[derive(Debug, Clone, PartialEq)]
pub struct Auth {
    /// The reason code: [`Auth::SUCCESS`], [`Auth::CONTINUE`] or
    /// [`Auth::REAUTH`].
    pub reason_code: u8,
    /// The Authentication Method (empty when absent or not a string).
    pub method: Vec<u8>,
    /// The Authentication Data (empty when absent or not a string).
    pub data: Vec<u8>,
    /// The property block, for a decoded AUTH that had one.
    pub properties: Option<Properties>,
}

impl Auth {
    /// Success.
    pub const SUCCESS: u8 = 0x00;
    /// Continue authentication.
    pub const CONTINUE: u8 = 0x18;
    /// Re-authenticate.
    pub const REAUTH: u8 = 0x19;

    /// Decodes an AUTH body; an empty body is a success.
    ///
    /// PHP: `Auth::decode(string $body)`.
    pub fn decode(body: &[u8]) -> Result<Self, Error> {
        if body.is_empty() {
            return Ok(Self::success(b""));
        }
        let (reason_code, offset) = wire::read_byte(body, 0);
        let (properties, _) = Properties::parse(body, offset)?;
        Ok(Self {
            reason_code,
            method: properties.bytes(Property::AUTHENTICATION_METHOD).unwrap_or_default().to_vec(),
            data: properties.bytes(Property::AUTHENTICATION_DATA).unwrap_or_default().to_vec(),
            properties: Some(properties),
        })
    }

    /// A success, naming the method.
    ///
    /// PHP: `Auth::success(string $method = '')`.
    pub fn success(method: &[u8]) -> Self {
        Self { reason_code: Self::SUCCESS, method: method.to_vec(), data: Vec::new(), properties: None }
    }

    /// A challenge: continue authentication with this method and data.
    ///
    /// PHP: `Auth::challenge(string $method, string $data)`.
    pub fn challenge(method: &[u8], data: &[u8]) -> Self {
        Self { reason_code: Self::CONTINUE, method: method.to_vec(), data: data.to_vec(), properties: None }
    }

    /// The merged User Properties (see [`Properties::user`]).
    ///
    /// PHP: `userProperties()`.
    pub fn user_properties(&self) -> Vec<(Vec<u8>, Vec<u8>)> {
        self.properties.as_ref().map(Properties::user).unwrap_or_default()
    }
}
