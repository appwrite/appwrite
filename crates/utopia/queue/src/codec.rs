//! How an envelope becomes the bytes a broker carries, and back.

use std::sync::Arc;

use bytes::Bytes;
use php_std::Value;
use php_std::json::{self, Flags};

use crate::error::{Error, Result};

/// Turns a message envelope into bytes and back.
///
/// Both directions fail on bad input: a value that cannot be encoded, or
/// bytes this codec did not write. A broker treats a decode failure as a
/// poison message and parks it: unlike a cache it cannot shrug one off as a
/// miss, because the bytes are the only copy of somebody's work.
///
/// Implement it to carry envelopes in another format.
pub trait Codec: Send + Sync {
    /// Encodes a value.
    fn encode(&self, value: &Value) -> Result<Vec<u8>>;

    /// Decodes bytes this codec wrote.
    fn decode(&self, bytes: &[u8]) -> Result<Value>;

    /// The media type of what [`Codec::encode`] writes, published beside the
    /// payload by brokers that carry metadata.
    fn content_type(&self) -> &'static str;
}

impl<C: Codec + ?Sized> Codec for Arc<C> {
    fn encode(&self, value: &Value) -> Result<Vec<u8>> {
        (**self).encode(value)
    }

    fn decode(&self, bytes: &[u8]) -> Result<Value> {
        (**self).decode(bytes)
    }

    fn content_type(&self) -> &'static str {
        (**self).content_type()
    }
}

/// The wire format every release so far has written: `json_encode` with
/// PHP's escaping (`\/`, `\uXXXX`), objects decoded to arrays.
///
/// ```
/// use php_std::{Array, Value};
/// use utopia_queue::codec::{Codec, Json};
///
/// let mut envelope = Array::new();
/// envelope.set("path".into(), Value::from("a/b é"));
/// let bytes = Json.encode(&Value::Array(envelope))?;
/// assert_eq!(bytes, r#"{"path":"a\/b \u00e9"}"#.as_bytes());
/// assert_eq!(Json.decode(&bytes)?, Json.decode("{\"path\":\"a/b é\"}".as_bytes())?);
/// # Ok::<(), utopia_queue::Error>(())
/// ```
///
/// ```php
/// use Utopia\Queue\Codec\Json;
///
/// echo new Json()->encode(['path' => 'a/b é']), "\n"; // {"path":"a\/b é"}
/// ```
#[derive(Debug, Clone, Copy, Default)]
pub struct Json;

impl Codec for Json {
    fn encode(&self, value: &Value) -> Result<Vec<u8>> {
        json::encode(value, Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH)
            .map(String::into_bytes)
            .map_err(|e| Error::Json(e.message().to_owned()))
    }

    fn decode(&self, bytes: &[u8]) -> Result<Value> {
        json::decode(bytes, Some(true), json::DEFAULT_DEPTH, Flags::THROW_ON_ERROR).map_err(|e| match e {
            json::Error::Value(m) => Error::Value(m),
            other => Error::Json(other.message().to_owned()),
        })
    }

    fn content_type(&self) -> &'static str {
        "application/json"
    }
}

/// Binary envelopes in igbinary's format: smaller and faster to decode
/// than JSON. A broker configured with this codec alone cannot read what an
/// earlier release wrote; [`Compat`] makes the switch survivable.
#[derive(Debug, Clone, Copy, Default)]
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

    fn content_type(&self) -> &'static str {
        "application/vnd.php.igbinary"
    }
}

/// igbinary's header: a NUL byte and the format version, currently 2.
const IGBINARY_MAGIC: &[u8] = b"\x00\x00\x00\x02";

/// Reads either encoding, writes one of them: the codec for a queue that
/// changes formats. The reader is picked from the leading bytes (igbinary
/// opens with a NUL, which no JSON document may contain).
///
/// ```
/// use php_std::{Array, Value};
/// use utopia_queue::codec::{Codec, Compat, Igbinary, Json};
///
/// let value = Value::Array(Array::from_list(vec![Value::Int(1)]));
/// let reader = Compat::new(Json);
/// assert_eq!(reader.decode(&Igbinary.encode(&value)?)?, value);
/// assert_eq!(reader.decode(&Json.encode(&value)?)?, value);
/// assert_eq!(reader.content_type(), "application/json");
/// # Ok::<(), utopia_queue::Error>(())
/// ```
///
/// ```php
/// use Utopia\Queue\Codec\Compat;
/// use Utopia\Queue\Codec\Igbinary;
///
/// $reader = new Compat();
/// var_dump($reader->decode(new Igbinary()->encode([1])) === [1]); // bool(true)
/// ```
#[derive(Debug, Clone, Copy, Default)]
pub struct Compat<W: Codec = Json> {
    writer: W,
}

impl<W: Codec> Compat<W> {
    /// Writes with `writer`, reads JSON and igbinary.
    pub fn new(writer: W) -> Self {
        Self { writer }
    }
}

impl<W: Codec> Codec for Compat<W> {
    fn encode(&self, value: &Value) -> Result<Vec<u8>> {
        self.writer.encode(value)
    }

    fn decode(&self, bytes: &[u8]) -> Result<Value> {
        if bytes.starts_with(IGBINARY_MAGIC) {
            return Igbinary.decode(bytes);
        }
        if bytes.first() == Some(&0) {
            return Err(Error::Runtime("Value carries an unsupported igbinary format version.".into()));
        }
        Json.decode(bytes)
    }

    fn content_type(&self) -> &'static str {
        self.writer.content_type()
    }
}
