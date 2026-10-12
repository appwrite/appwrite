//! MQTT 5.0 properties: identifier/value pairs carried in a block (a
//! variable byte length, then each property) by every 5.0 packet that has
//! one. MQTT 3.1.1 packets have none.

use crate::Error;
use crate::wire::{self, Length};

/// The wire encoding of a property's value, fixed by its identifier.
///
/// PHP: the `Property::TYPE_*` string constants.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum WireType {
    /// One byte.
    Byte,
    /// A two-byte big-endian integer.
    Int16,
    /// A four-byte big-endian integer.
    Int32,
    /// A variable byte integer.
    Varint,
    /// A UTF-8 string or binary data, length-prefixed.
    String,
    /// A User Property: a key string and a value string.
    Pair,
}

impl WireType {
    /// The name PHP gives the type (`byte`, `int16`, `int32`, `varint`,
    /// `string`, `pair`).
    ///
    /// PHP: the value of `Property::TYPE_BYTE` and the other `TYPE_*`
    /// constants.
    pub fn as_str(self) -> &'static str {
        match self {
            WireType::Byte => Property::TYPE_BYTE,
            WireType::Int16 => Property::TYPE_INT16,
            WireType::Int32 => Property::TYPE_INT32,
            WireType::Varint => Property::TYPE_VARINT,
            WireType::String => Property::TYPE_STRING,
            WireType::Pair => Property::TYPE_PAIR,
        }
    }
}

/// The value of a [`Property`].
///
/// Parsing gives each identifier the variant of its wire type: an
/// [`Int`](Value::Int) for bytes and integers, a [`Bytes`](Value::Bytes) for
/// strings and binary data, a [`Pair`](Value::Pair) for a User Property, and a
/// [`Float`](Value::Float) for a variable byte integer only past nine bytes
/// (see [`Length`]). Encoding converts a value of another variant the way
/// PHP's `(int)` and `(string)` casts do.
#[derive(Debug, Clone, PartialEq)]
pub enum Value {
    /// An integer.
    Int(i64),
    /// A variable byte integer PHP decoded past the 64-bit range.
    Float(f64),
    /// A string or binary data.
    Bytes(Vec<u8>),
    /// A User Property's key and value.
    Pair(Vec<u8>, Vec<u8>),
}

impl Value {
    /// PHP's `(int) $value`.
    fn to_int(&self) -> i64 {
        match self {
            Value::Int(i) => *i,
            Value::Float(f) => php_std::format::dval_to_lval(*f),
            Value::Bytes(b) => php_std::format::str_to_int(b),
            Value::Pair(..) => 1,
        }
    }

    /// PHP's `(string) $value`.
    fn to_bytes(&self) -> std::borrow::Cow<'_, [u8]> {
        match self {
            Value::Int(i) => i.to_string().into_bytes().into(),
            Value::Float(f) => php_std::number::to_string(*f).into_bytes().into(),
            Value::Bytes(b) => b.as_slice().into(),
            Value::Pair(..) => b"Array".as_slice().into(),
        }
    }

    /// The bytes of a string value.
    pub fn as_bytes(&self) -> Option<&[u8]> {
        match self {
            Value::Bytes(b) => Some(b),
            _ => None,
        }
    }

    /// The integer of an integer value.
    pub fn as_int(&self) -> Option<i64> {
        match self {
            Value::Int(i) => Some(*i),
            _ => None,
        }
    }
}

impl From<Length> for Value {
    fn from(length: Length) -> Self {
        match length {
            Length::Int(i) => Value::Int(i),
            Length::Float(f) => Value::Float(f),
        }
    }
}

/// One MQTT 5.0 property: an identifier and a value whose wire encoding the
/// identifier fixes (see [`Property::wire_type`]).
///
/// A User Property ([`Property::USER`]) carries one key and value; a block
/// may hold any number of them.
///
/// ```
/// use utopia_mqtt::{Property, Value};
///
/// let expiry = Property::new(Property::SESSION_EXPIRY_INTERVAL, Value::Int(3600));
/// assert_eq!(expiry.encode()?, [0x11, 0x00, 0x00, 0x0E, 0x10]);
/// assert_eq!(Property::user("projectId", "p1").encode()?, b"\x26\x00\x09projectId\x00\x02p1");
/// # Ok::<(), utopia_mqtt::Error>(())
/// ```
///
/// ```php
/// use Utopia\Mqtt\Property;
///
/// echo bin2hex((new Property(Property::SESSION_EXPIRY_INTERVAL, 3600))->encode()), "\n"; // 1100000e10
/// echo bin2hex((new Property(Property::USER, ['projectId' => 'p1']))->encode()), "\n";
/// ```
///
/// PHP: `Utopia\Mqtt\Property`; a PHP User Property holding several keys is
/// one [`Property::user`] per key.
#[derive(Debug, Clone, PartialEq)]
pub struct Property {
    /// The identifier.
    pub id: u8,
    /// The value.
    pub value: Value,
}

impl Property {
    /// Payload Format Indicator (byte).
    pub const PAYLOAD_FORMAT_INDICATOR: u8 = 0x01;
    /// Message Expiry Interval (four-byte integer).
    pub const MESSAGE_EXPIRY_INTERVAL: u8 = 0x02;
    /// Content Type (string).
    pub const CONTENT_TYPE: u8 = 0x03;
    /// Response Topic (string).
    pub const RESPONSE_TOPIC: u8 = 0x08;
    /// Correlation Data (binary).
    pub const CORRELATION_DATA: u8 = 0x09;
    /// Subscription Identifier (variable byte integer).
    pub const SUBSCRIPTION_IDENTIFIER: u8 = 0x0B;
    /// Session Expiry Interval (four-byte integer).
    pub const SESSION_EXPIRY_INTERVAL: u8 = 0x11;
    /// Assigned Client Identifier (string).
    pub const ASSIGNED_CLIENT_IDENTIFIER: u8 = 0x12;
    /// Server Keep Alive (two-byte integer).
    pub const SERVER_KEEP_ALIVE: u8 = 0x13;
    /// Authentication Method (string).
    pub const AUTHENTICATION_METHOD: u8 = 0x15;
    /// Authentication Data (binary).
    pub const AUTHENTICATION_DATA: u8 = 0x16;
    /// Request Problem Information (byte).
    pub const REQUEST_PROBLEM_INFORMATION: u8 = 0x17;
    /// Will Delay Interval (four-byte integer).
    pub const WILL_DELAY_INTERVAL: u8 = 0x18;
    /// Request Response Information (byte).
    pub const REQUEST_RESPONSE_INFORMATION: u8 = 0x19;
    /// Response Information (string).
    pub const RESPONSE_INFORMATION: u8 = 0x1A;
    /// Server Reference (string).
    pub const SERVER_REFERENCE: u8 = 0x1C;
    /// Reason String (string).
    pub const REASON_STRING: u8 = 0x1F;
    /// Receive Maximum (two-byte integer).
    pub const RECEIVE_MAXIMUM: u8 = 0x21;
    /// Topic Alias Maximum (two-byte integer).
    pub const TOPIC_ALIAS_MAXIMUM: u8 = 0x22;
    /// Topic Alias (two-byte integer).
    pub const TOPIC_ALIAS: u8 = 0x23;
    /// Maximum QoS (byte).
    pub const MAXIMUM_QOS: u8 = 0x24;
    /// Retain Available (byte).
    pub const RETAIN_AVAILABLE: u8 = 0x25;
    /// User Property (string pair).
    pub const USER: u8 = 0x26;
    /// Maximum Packet Size (four-byte integer).
    pub const MAXIMUM_PACKET_SIZE: u8 = 0x27;
    /// Wildcard Subscription Available (byte).
    pub const WILDCARD_SUBSCRIPTION_AVAILABLE: u8 = 0x28;
    /// Subscription Identifier Available (byte).
    pub const SUBSCRIPTION_IDENTIFIER_AVAILABLE: u8 = 0x29;
    /// Shared Subscription Available (byte).
    pub const SHARED_SUBSCRIPTION_AVAILABLE: u8 = 0x2A;

    /// The name of [`WireType::Byte`].
    pub const TYPE_BYTE: &'static str = "byte";
    /// The name of [`WireType::Int16`].
    pub const TYPE_INT16: &'static str = "int16";
    /// The name of [`WireType::Int32`].
    pub const TYPE_INT32: &'static str = "int32";
    /// The name of [`WireType::Varint`].
    pub const TYPE_VARINT: &'static str = "varint";
    /// The name of [`WireType::String`].
    pub const TYPE_STRING: &'static str = "string";
    /// The name of [`WireType::Pair`].
    pub const TYPE_PAIR: &'static str = "pair";

    /// A property.
    ///
    /// PHP: `new Property(int $id, mixed $value)`.
    pub fn new(id: u8, value: Value) -> Self {
        Self { id, value }
    }

    /// A User Property.
    ///
    /// PHP: `new Property(Property::USER, [$key => $value])`.
    pub fn user(key: impl Into<Vec<u8>>, value: impl Into<Vec<u8>>) -> Self {
        Self { id: Self::USER, value: Value::Pair(key.into(), value.into()) }
    }

    /// A string or binary property (a Reason String, an Authentication
    /// Method).
    pub fn bytes(id: u8, value: impl Into<Vec<u8>>) -> Self {
        Self { id, value: Value::Bytes(value.into()) }
    }

    /// The wire type of an identifier; an unknown one is an
    /// [`Error::Runtime`] (`Unknown MQTT property 0x7f`).
    ///
    /// PHP: `Property::wireType(int $id)`.
    pub fn wire_type(id: u8) -> Result<WireType, Error> {
        Ok(match id {
            Self::PAYLOAD_FORMAT_INDICATOR
            | Self::REQUEST_PROBLEM_INFORMATION
            | Self::REQUEST_RESPONSE_INFORMATION
            | Self::MAXIMUM_QOS
            | Self::RETAIN_AVAILABLE
            | Self::WILDCARD_SUBSCRIPTION_AVAILABLE
            | Self::SUBSCRIPTION_IDENTIFIER_AVAILABLE
            | Self::SHARED_SUBSCRIPTION_AVAILABLE => WireType::Byte,
            Self::SERVER_KEEP_ALIVE | Self::RECEIVE_MAXIMUM | Self::TOPIC_ALIAS_MAXIMUM | Self::TOPIC_ALIAS => {
                WireType::Int16
            }
            Self::MESSAGE_EXPIRY_INTERVAL
            | Self::SESSION_EXPIRY_INTERVAL
            | Self::WILL_DELAY_INTERVAL
            | Self::MAXIMUM_PACKET_SIZE => WireType::Int32,
            Self::SUBSCRIPTION_IDENTIFIER => WireType::Varint,
            Self::CONTENT_TYPE
            | Self::RESPONSE_TOPIC
            | Self::CORRELATION_DATA
            | Self::ASSIGNED_CLIENT_IDENTIFIER
            | Self::AUTHENTICATION_METHOD
            | Self::AUTHENTICATION_DATA
            | Self::RESPONSE_INFORMATION
            | Self::SERVER_REFERENCE
            | Self::REASON_STRING => WireType::String,
            Self::USER => WireType::Pair,
            _ => return Err(Error::unknown_property(id)),
        })
    }

    /// The property on the wire: its identifier, then its value. A User
    /// Property whose value is not a [`Value::Pair`] encodes to nothing.
    ///
    /// PHP: `encode()`.
    pub fn encode(&self) -> Result<Vec<u8>, Error> {
        let mut out = Vec::new();
        self.encode_into(&mut out)?;
        Ok(out)
    }

    /// Appends [`encode`](Property::encode)'s bytes to `out`.
    pub fn encode_into(&self, out: &mut Vec<u8>) -> Result<(), Error> {
        let kind = Self::wire_type(self.id)?;
        if kind == WireType::Pair {
            if let Value::Pair(key, value) = &self.value {
                out.push(self.id);
                wire::encode_string(key, out);
                wire::encode_string(value, out);
            }
            return Ok(());
        }
        out.push(self.id);
        match kind {
            WireType::Byte => out.push(self.value.to_int() as u8),
            WireType::Int16 => out.extend_from_slice(&(self.value.to_int() as u16).to_be_bytes()),
            WireType::Int32 => out.extend_from_slice(&(self.value.to_int() as u32).to_be_bytes()),
            WireType::Varint => wire::encode_php_length(self.value.to_int(), out),
            _ => wire::encode_string(&self.value.to_bytes(), out),
        }
        Ok(())
    }
}

/// An ordered list of [`Property`]: an MQTT 5.0 property block. It encodes
/// to its length as a variable byte integer followed by each property, and
/// parses the same shape back.
///
/// ```
/// use utopia_mqtt::{Properties, Property};
///
/// let block = Properties::new()
///     .with(Property::bytes(Property::REASON_STRING, "ok"))
///     .with(Property::user("projectId", "p1"))
///     .encode()?;
/// let (parsed, end) = Properties::parse(&block, 0)?;
/// assert_eq!(end, block.len());
/// assert_eq!(parsed.bytes(Property::REASON_STRING), Some(&b"ok"[..]));
/// assert_eq!(parsed.user(), [(b"projectId".to_vec(), b"p1".to_vec())]);
/// # Ok::<(), utopia_mqtt::Error>(())
/// ```
///
/// ```php
/// use Utopia\Mqtt\Properties;
/// use Utopia\Mqtt\Property;
///
/// $block = (new Properties())
///     ->add(new Property(Property::REASON_STRING, 'ok'))
///     ->add(new Property(Property::USER, ['projectId' => 'p1']))
///     ->encode();
/// [$parsed, $end] = Properties::parse($block, 0);
/// echo $parsed->get(Property::REASON_STRING), "\n"; // ok
/// print_r($parsed->user()); // ['projectId' => 'p1']
/// ```
///
/// PHP: `Utopia\Mqtt\Properties`.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Properties {
    properties: Vec<Property>,
}

impl Properties {
    /// An empty block.
    pub fn new() -> Self {
        Self::default()
    }

    /// Appends a property.
    ///
    /// PHP: `add(Property $property)`.
    pub fn add(&mut self, property: Property) -> &mut Self {
        self.properties.push(property);
        self
    }

    /// The block with `property` appended, for building one in an
    /// expression.
    pub fn with(mut self, property: Property) -> Self {
        self.properties.push(property);
        self
    }

    /// Every property, in order.
    ///
    /// PHP: `all()`.
    pub fn all(&self) -> &[Property] {
        &self.properties
    }

    /// The value of the first property with this identifier.
    ///
    /// PHP: `get(int $id)`.
    pub fn get(&self, id: u8) -> Option<&Value> {
        self.properties.iter().find(|p| p.id == id).map(|p| &p.value)
    }

    /// The first property with this identifier when it is a string, such as
    /// the Authentication Method.
    pub fn bytes(&self, id: u8) -> Option<&[u8]> {
        self.get(id).and_then(Value::as_bytes)
    }

    /// The User Properties merged into one list of keys and values: a key
    /// seen again replaces the earlier value in place.
    ///
    /// PHP merges them with `array_merge()`, which renumbers integer keys: a
    /// key that is a decimal integer (`"7"`, `"-1"`, not `"07"`) is replaced
    /// by its position among those keys (`"0"`, `"1"`, ...) and never
    /// replaces another. The same happens here (a quirk the compat spec
    /// records).
    ///
    /// PHP: `user()`.
    pub fn user(&self) -> Vec<(Vec<u8>, Vec<u8>)> {
        let mut merged: Vec<(Vec<u8>, Vec<u8>)> = Vec::new();
        let mut index = 0u64;
        for property in &self.properties {
            let (Property::USER, Value::Pair(key, value)) = (property.id, &property.value) else {
                continue;
            };
            if integer_key(key) {
                merged.push((index.to_string().into_bytes(), value.clone()));
                index += 1;
            } else if let Some(entry) = merged.iter_mut().find(|(k, _)| k == key) {
                entry.1 = value.clone();
            } else {
                merged.push((key.clone(), value.clone()));
            }
        }
        merged
    }

    /// The encoded block: its length, then each property.
    ///
    /// PHP: `encode()`.
    pub fn encode(&self) -> Result<Vec<u8>, Error> {
        let mut out = Vec::new();
        self.encode_into(&mut out)?;
        Ok(out)
    }

    /// Appends [`encode`](Properties::encode)'s bytes to `out`.
    pub fn encode_into(&self, out: &mut Vec<u8>) -> Result<(), Error> {
        if self.properties.is_empty() {
            out.push(0);
            return Ok(());
        }
        let mut body = Vec::new();
        for property in &self.properties {
            property.encode_into(&mut body)?;
        }
        wire::encode_length(body.len() as u64, out);
        out.extend_from_slice(&body);
        Ok(())
    }

    /// Parses the block at `offset`: the properties and the offset past the
    /// block. An identifier that is not a property (including the zero a
    /// block read past the end of `data` gives) is an [`Error::Runtime`].
    ///
    /// PHP: `Properties::parse(string $data, int $offset)`.
    pub fn parse(data: &[u8], offset: usize) -> Result<(Properties, usize), Error> {
        let mut properties = Properties::new();
        let (length, bytes) = wire::decode_length(data, offset);
        let mut offset = offset.saturating_add(bytes);
        let end = Length::from(offset).plus(length);
        while before(offset, end) {
            let id = wire::byte(data, offset);
            offset = offset.saturating_add(1);
            let value;
            (value, offset) = read_value(id, data, offset)?;
            properties.properties.push(Property { id, value });
        }
        Ok((properties, offset))
    }

    /// The offset past the block at `offset`, without decoding it. A length
    /// PHP's arithmetic carries past the 64-bit range is an [`Error::Type`].
    ///
    /// PHP: `Properties::skip(string $data, int $offset)`.
    pub fn skip(data: &[u8], offset: usize) -> Result<usize, Error> {
        let (length, bytes) = wire::decode_length(data, offset);
        Length::from(offset)
            .plus(Length::from(bytes))
            .plus(length)
            .as_int()
            .and_then(|end| usize::try_from(end).ok())
            .ok_or_else(|| {
                Error::Type("Utopia\\Mqtt\\Properties::skip(): Return value must be of type int, float returned".into())
            })
    }
}

/// `$offset < $end`.
fn before(offset: usize, end: Length) -> bool {
    match end {
        Length::Int(end) => (offset as i64) < end,
        Length::Float(end) => (offset as f64) < end,
    }
}

/// Whether PHP turns this array key into an integer: a decimal integer in the
/// 64-bit range without leading zeros or a plus sign.
pub(crate) fn integer_key(key: &[u8]) -> bool {
    std::str::from_utf8(key).is_ok_and(php_std::encoding::is_integer_key)
}

fn read_value(id: u8, data: &[u8], offset: usize) -> Result<(Value, usize), Error> {
    Ok(match Property::wire_type(id)? {
        WireType::Byte => (Value::Int(i64::from(wire::byte(data, offset))), offset.saturating_add(1)),
        WireType::Int16 => {
            let (value, next) = wire::read_int16(data, offset);
            (Value::Int(i64::from(value)), next)
        }
        WireType::Int32 => {
            let value = (u32::from(wire::byte(data, offset)) << 24)
                | (u32::from(wire::byte(data, offset.saturating_add(1))) << 16)
                | (u32::from(wire::byte(data, offset.saturating_add(2))) << 8)
                | u32::from(wire::byte(data, offset.saturating_add(3)));
            (Value::Int(i64::from(value)), offset.saturating_add(4))
        }
        WireType::Varint => {
            let (value, bytes) = wire::decode_length(data, offset);
            (value.into(), offset.saturating_add(bytes))
        }
        WireType::Pair => {
            let (key, next) = wire::read_string(data, offset);
            let (value, next) = wire::read_string(data, next);
            (Value::Pair(key.to_vec(), value.to_vec()), next)
        }
        WireType::String => {
            let (value, next) = wire::read_string(data, offset);
            (Value::Bytes(value.to_vec()), next)
        }
    })
}
