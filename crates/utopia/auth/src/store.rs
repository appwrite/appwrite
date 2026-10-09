//! Session store encoding: `base64(json_encode({...}))`.

use base64::engine::DecodePaddingMode;
use base64::engine::general_purpose::{GeneralPurpose, GeneralPurposeConfig};
use base64::{Engine, alphabet};
use serde_json::{Map, Value};

const LENIENT: GeneralPurpose = GeneralPurpose::new(
    &alphabet::STANDARD,
    GeneralPurposeConfig::new().with_decode_padding_mode(DecodePaddingMode::Indifferent),
);

/// Encodes `{"id": id, "secret": secret}` like `Store::encode()`.
pub fn encode(id: &str, secret: &str) -> String {
    let mut map = Map::new();
    map.insert("id".into(), Value::String(id.to_owned()));
    map.insert("secret".into(), Value::String(secret.to_owned()));
    let json = php_json(&Value::Object(map));
    base64::engine::general_purpose::STANDARD.encode(json)
}

/// Decodes a store; returns `(id, secret)` with empty strings for missing parts.
pub fn decode(data: &str) -> (String, String) {
    let Ok(raw) = LENIENT.decode(data.trim()) else {
        return (String::new(), String::new());
    };
    let Ok(Value::Object(map)) = serde_json::from_slice::<Value>(&raw) else {
        return (String::new(), String::new());
    };
    let field = |k: &str| match map.get(k) {
        Some(Value::String(s)) => s.clone(),
        Some(Value::Number(n)) => n.to_string(),
        _ => String::new(),
    };
    (field("id"), field("secret"))
}

fn php_json(value: &Value) -> String {
    serde_json::to_string(value).unwrap_or_default().replace('/', "\\/")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn round_trip() {
        let e = encode("user1", "abc");
        assert_eq!(e, "eyJpZCI6InVzZXIxIiwic2VjcmV0IjoiYWJjIn0=");
        assert_eq!(decode(&e), ("user1".into(), "abc".into()));
        assert_eq!(decode("!!!"), (String::new(), String::new()));
    }
}
