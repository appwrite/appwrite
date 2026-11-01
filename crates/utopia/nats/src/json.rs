//! JSON as PHP's `json_encode` writes it and `json_decode(..., true)` reads
//! it: what the server and the PHP client see on the wire.

use php_std::json::{self, Flags};
use serde_json::Value;

use crate::error::{Error, Result};

/// `json_encode($value, JSON_THROW_ON_ERROR)`: slashes and non-ASCII
/// characters escaped, empty objects as `{}`, empty lists as `[]`.
pub(crate) fn encode(value: &Value) -> Result<String> {
    let php = php_std::types::Value::<php_std::types::Never>::from_json(value);
    json::encode(&php, Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH).map_err(Error::json)
}

/// `json_decode($text, true, 512, JSON_THROW_ON_ERROR)`.
pub(crate) fn decode(text: &[u8]) -> Result<Value> {
    json::decode_value(text, json::DEFAULT_DEPTH, Flags::THROW_ON_ERROR).map_err(Error::json)
}
