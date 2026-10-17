//! HS256 JWTs compatible with `adhocore/jwt` as used by Appwrite.

use base64::Engine;
use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use hmac::{Hmac, Mac};
use serde_json::{Map, Value};
use sha2::Sha256;

type HmacSha256 = Hmac<Sha256>;

fn now() -> i64 {
    std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.as_secs() as i64).unwrap_or(0)
}

/// Encodes `claims` (insertion order kept) adding `exp = now + max_age`.
pub fn encode(key: &str, mut claims: Map<String, Value>, max_age: i64) -> String {
    if !claims.contains_key("iat") && !claims.contains_key("exp") {
        claims.insert("exp".to_owned(), Value::from(now() + max_age));
    }
    let header = URL_SAFE_NO_PAD.encode(br#"{"typ":"JWT","alg":"HS256"}"#);
    // JSON_UNESCAPED_SLASHES: plain serde_json output.
    let payload = URL_SAFE_NO_PAD.encode(serde_json::to_vec(&Value::Object(claims)).unwrap_or_default());
    let signing_input = format!("{header}.{payload}");
    let mut mac = HmacSha256::new_from_slice(key.as_bytes()).expect("HMAC accepts any key length");
    mac.update(signing_input.as_bytes());
    let signature = URL_SAFE_NO_PAD.encode(mac.finalize().into_bytes());
    format!("{signing_input}.{signature}")
}

/// Decodes and verifies a token; errors carry adhocore's messages.
pub fn decode(key: &str, token: &str, max_age: i64) -> Result<Map<String, Value>, String> {
    let parts: Vec<&str> = token.split('.').collect();
    if parts.len() != 3 {
        return Err("Invalid token: Incomplete segments".to_owned());
    }
    let header: Value = URL_SAFE_NO_PAD
        .decode(parts[0])
        .ok()
        .and_then(|b| serde_json::from_slice(&b).ok())
        .ok_or_else(|| "JSON failed: Syntax error".to_owned())?;
    let alg =
        header.get("alg").and_then(Value::as_str).ok_or_else(|| "Invalid token: Missing header algo".to_owned())?;
    if !matches!(alg, "HS256" | "HS384" | "HS512" | "RS256" | "RS384" | "RS512") {
        return Err("Invalid token: Unsupported header algo".to_owned());
    }
    let mut mac = HmacSha256::new_from_slice(key.as_bytes()).expect("HMAC accepts any key length");
    mac.update(format!("{}.{}", parts[0], parts[1]).as_bytes());
    let signature = URL_SAFE_NO_PAD.decode(parts[2]).map_err(|_| "Invalid token: Signature failed".to_owned())?;
    mac.verify_slice(&signature).map_err(|_| "Invalid token: Signature failed".to_owned())?;
    let payload: Value = URL_SAFE_NO_PAD
        .decode(parts[1])
        .ok()
        .and_then(|b| serde_json::from_slice(&b).ok())
        .ok_or_else(|| "JSON failed: Syntax error".to_owned())?;
    let Value::Object(claims) = payload else {
        return Err("JSON failed: Syntax error".to_owned());
    };
    let t = now();
    if let Some(exp) = claims.get("exp").and_then(Value::as_i64)
        && t >= exp
    {
        return Err("Invalid token: Expired".to_owned());
    }
    if let Some(iat) = claims.get("iat").and_then(Value::as_i64)
        && t >= iat + max_age
    {
        return Err("Invalid token: Expired".to_owned());
    }
    if let Some(nbf) = claims.get("nbf").and_then(Value::as_i64)
        && t <= nbf
    {
        return Err("Invalid token: Not now".to_owned());
    }
    Ok(claims)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn round_trip() {
        let mut claims = Map::new();
        claims.insert("projectId".into(), "p1".into());
        claims.insert("userId".into(), "u1".into());
        claims.insert("sessionId".into(), "".into());
        let token = encode("secret", claims, 900);
        let decoded = decode("secret", &token, 3600).unwrap();
        assert_eq!(decoded.get("userId").and_then(Value::as_str), Some("u1"));
        assert!(decoded.get("exp").is_some());
        assert_eq!(decode("other", &token, 3600).unwrap_err(), "Invalid token: Signature failed");
        assert_eq!(decode("secret", "a.b", 3600).unwrap_err(), "Invalid token: Incomplete segments");
    }
}
