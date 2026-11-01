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

    /// The bytes stored for the [`Envelope`](crate::Envelope) record
    /// `['time' => $time, 'data' => $data]`.
    ///
    /// The default builds the record, copying `data`, and encodes it;
    /// override it to write the record around the borrowed data ([`Json`]
    /// does).
    fn encode_envelope(&self, time: i64, data: &Value) -> Result<Vec<u8>> {
        let mut record = Array::with_capacity(2);
        record.set(ArrayKey::from("time"), Value::Int(time));
        record.set(ArrayKey::from("data"), data.clone());
        self.encode(&Value::Array(record))
    }
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

    /// `{"time":<time>,"data":<data>}`: `json_encode()` of the record,
    /// written around the encoded data. The data sits one level down, so it
    /// is encoded with one level less of depth, failing exactly where the
    /// record would.
    fn encode_envelope(&self, time: i64, data: &Value) -> Result<Vec<u8>> {
        let data = json::encode(data, Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH - 1)
            .map_err(|e| Error::Json(e.message().to_owned()))?;
        let mut out = Vec::with_capacity(data.len() + 40);
        out.extend_from_slice(b"{\"time\":");
        out.extend_from_slice(time.to_string().as_bytes());
        out.extend_from_slice(b",\"data\":");
        out.extend_from_slice(data.as_bytes());
        out.push(b'}');
        Ok(out)
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
/// PHP: `preg_match('/\{\s*\}/', $text) === 0`, scanned natively: `\s`
/// without `/u` is space, `\t`, `\n`, `\v`, `\f` and `\r`.
fn has_empty_object(text: &[u8]) -> bool {
    text.iter().enumerate().any(|(i, &b)| {
        b == b'{'
            && text[i + 1..].iter().find(|c| !matches!(c, b' ' | b'\t' | b'\n' | 0x0b | 0x0c | b'\r')) == Some(&b'}')
    })
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

    fn encode_envelope(&self, time: i64, data: &Value) -> Result<Vec<u8>> {
        match self {
            AnyCodec::Json => Json.encode_envelope(time, data),
            AnyCodec::Igbinary => Igbinary.encode_envelope(time, data),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The record `encode_envelope` writes is the one `encode` writes.
    #[test]
    fn json_envelopes_match_the_record() {
        let nested =
            |depth: usize| (1..depth).fold(Value::Array(Array::new()), |v, _| Value::Array(Array::from_list(vec![v])));
        let values = [
            Value::from("x"),
            Value::Str(b"\xff".as_slice().into()),
            Value::Array(Array::from_entries(
                [("a/b".into(), Value::Float(0.1)), (ArrayKey::Int(3), Value::Null)],
                None,
            )),
            Value::Array(Array::from_list(vec![Value::Object(Array::new()), Value::Float(f64::NAN)])),
            nested(510),
            nested(511),
            nested(512),
        ];
        for value in values {
            for time in [0, -5, 1_700_000_000, i64::MAX] {
                let mut record = Array::with_capacity(2);
                record.set(ArrayKey::from("time"), Value::Int(time));
                record.set(ArrayKey::from("data"), value.clone());
                let expected = Json.encode(&Value::Array(record)).map_err(|e| e.to_string());
                assert_eq!(Json.encode_envelope(time, &value).map_err(|e| e.to_string()), expected);
            }
        }
    }

    #[test]
    fn detects_empty_objects() {
        assert!(has_empty_object(b"{}"));
        assert!(has_empty_object(b"[1,{ \n}]"));
        assert!(!has_empty_object(b"{\"a\":1}"));
        assert!(!has_empty_object(b"{"));
    }
}
