//! [`Codec`]: how a cache value becomes the bytes an adapter stores.

use bytes::Bytes;
use php_std::json::{self, Flags};
use php_std::types::{Array, ArrayKey, Value};

use crate::{Error, Result};

/// Turns a cache value into bytes and back.
///
/// Both directions fail on bad input: a value that cannot be encoded, or
/// bytes that are not a payload this codec wrote. Adapters treat a decode
/// failure as a miss. Implement it to store values in another format.
pub trait Codec: Send + Sync + 'static {
    /// The bytes stored for `value`.
    fn encode(&self, value: &Value) -> Result<Vec<u8>>;

    /// The value `bytes` hold.
    fn decode(&self, bytes: &[u8]) -> Result<Value>;
}

/// JSON, the format every adapter has always written.
///
/// Objects decode to associative arrays, except empty ones, which stay
/// objects so `{}` survives a round trip instead of becoming `[]`.
///
/// ```
/// use php_std::types::{Array, Value};
/// use utopia_cache::{Codec, Json};
///
/// let value = Value::Array(Array::from_entries([("time".into(), Value::Int(1)), ("data".into(), "x".into())], None));
/// assert_eq!(Json.encode(&value)?, br#"{"time":1,"data":"x"}"#);
/// assert_eq!(Json.decode(br#"{"time":1,"data":"x"}"#)?, value);
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Codec\Json;
///
/// $codec = new Json();
/// echo $codec->encode(['time' => 1, 'data' => 'x']), "\n"; // {"time":1,"data":"x"}
/// var_dump($codec->decode('{"time":1,"data":"x"}') === ['time' => 1, 'data' => 'x']); // bool(true)
/// ```
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Json;

impl Codec for Json {
    fn encode(&self, value: &Value) -> Result<Vec<u8>> {
        json::encode(value, Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH)
            .map(String::into_bytes)
            .map_err(|e| Error::Json(e.message().to_owned()))
    }

    fn decode(&self, bytes: &[u8]) -> Result<Value> {
        let flags = Flags::THROW_ON_ERROR;
        let map = |e: json::Error| Error::Json(e.message().to_owned());
        // Only text holding an empty object (`{`, blanks, `}`) needs the
        // object-preserving decode; everything else decodes as arrays at once.
        if !has_empty_object(bytes) {
            return json::decode(bytes, Some(true), json::DEFAULT_DEPTH, flags).map_err(map);
        }
        json::decode(bytes, Some(false), json::DEFAULT_DEPTH, flags).map(associative).map_err(map)
    }
}

/// No empty object (`{`, blanks, `}`) anywhere in the text, so the plain array
/// decode keeps every value.
///
/// PHP: `preg_match('/\{\s*\}/', $text) === 0`.
fn has_empty_object(text: &[u8]) -> bool {
    !matches!(php_std::pcre::preg_match_bare(br"/\{\s*\}/", text).map(|p| p.value), Ok(php_std::pcre::Value::Int(0)))
}

/// Objects with properties become arrays (their names normalised to array
/// keys, as `get_object_vars` does); empty objects stay objects.
fn associative(value: Value) -> Value {
    match value {
        Value::Object(properties) if properties.is_empty() => Value::Object(properties),
        Value::Object(properties) => Value::Array(
            properties
                .into_iter()
                .map(|(k, v)| {
                    let key = match k {
                        ArrayKey::Str(s) => ArrayKey::normalize(s),
                        int => int,
                    };
                    (key, associative(v))
                })
                .collect::<Array>(),
        ),
        Value::Array(items) => {
            let next = items.next_index();
            Value::Array(Array::from_entries(items.into_iter().map(|(k, v)| (k, associative(v))), next))
        }
        other => other,
    }
}

/// Binary igbinary payloads: smaller and faster than JSON, and a lossless
/// round trip for every value, including empty objects.
///
/// Payloads are not JSON, so switching an existing cache to this codec turns
/// its stored entries into misses until they are rewritten.
///
/// ```
/// use php_std::types::Value;
/// use utopia_cache::{Codec, Igbinary};
///
/// let bytes = Igbinary.encode(&Value::from("hello"))?;
/// assert_eq!(Igbinary.decode(&bytes)?, Value::from("hello"));
/// assert!(Igbinary.decode(b"").is_err());
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Codec\Igbinary;
///
/// $codec = new Igbinary();
/// echo $codec->decode($codec->encode('hello')), "\n"; // hello
/// ```
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Igbinary;

impl Codec for Igbinary {
    fn encode(&self, value: &Value) -> Result<Vec<u8>> {
        Ok(php_std::igbinary::serialize(value))
    }

    fn decode(&self, bytes: &[u8]) -> Result<Value> {
        if bytes.is_empty() {
            return Err(Error::Runtime("Value is not an igbinary payload.".into()));
        }
        php_std::igbinary::unserialize(&Bytes::copy_from_slice(bytes)).map_err(|e| Error::Runtime(e.to_string()))
    }
}

/// A codec chosen at run time, for configuration-driven setups.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub enum AnyCodec {
    /// [`Json`].
    #[default]
    Json,
    /// [`Igbinary`].
    Igbinary,
}

impl Codec for AnyCodec {
    fn encode(&self, value: &Value) -> Result<Vec<u8>> {
        match self {
            AnyCodec::Json => Json.encode(value),
            AnyCodec::Igbinary => Igbinary.encode(value),
        }
    }

    fn decode(&self, bytes: &[u8]) -> Result<Value> {
        match self {
            AnyCodec::Json => Json.decode(bytes),
            AnyCodec::Igbinary => Igbinary.decode(bytes),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn detects_empty_objects() {
        assert!(has_empty_object(b"{}"));
        assert!(has_empty_object(b"[1,{ \n}]"));
        assert!(!has_empty_object(b"{\"a\":1}"));
        assert!(!has_empty_object(b"{"));
    }
}
