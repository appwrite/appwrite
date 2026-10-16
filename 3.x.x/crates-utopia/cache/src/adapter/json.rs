use serde_json::Value;

use crate::error::CacheError;

/// PHP `Utopia\Cache\Adapter\Json`.
#[derive(Debug, Clone, Copy)]
pub struct Json;

impl Json {
    /// PHP `Json::decode`. `serde_json::Value` preserves `{}` vs `[]`.
    #[must_use]
    pub fn decode(value: &str) -> Option<Value> {
        serde_json::from_str(value).ok()
    }

    /// Decode throwing on malformed JSON (PHP `JSON_THROW_ON_ERROR`).
    pub fn decode_strict(value: &str) -> Result<Value, CacheError> {
        serde_json::from_str(value).map_err(|e| CacheError::message(e.to_string()))
    }

    pub fn encode(value: &Value) -> Result<String, CacheError> {
        serde_json::to_string(value).map_err(|e| CacheError::message(e.to_string()))
    }

    /// PHP `preg_match('/\{\s*\}/', $value) !== 0`.
    #[must_use]
    pub fn contains_empty_object(value: &str) -> bool {
        let bytes = value.as_bytes();
        let mut i = 0;
        while i < bytes.len() {
            if bytes[i] == b'{' {
                let mut j = i + 1;
                while j < bytes.len() && bytes[j].is_ascii_whitespace() {
                    j += 1;
                }
                if j < bytes.len() && bytes[j] == b'}' {
                    return true;
                }
            }
            i += 1;
        }
        false
    }
}
