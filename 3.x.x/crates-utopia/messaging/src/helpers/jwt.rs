//! PHP `Utopia\Messaging\Helpers\JWT`.

use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine;
use hmac::{digest::KeyInit, Hmac, Mac};
use jsonwebtoken::{encode, Algorithm, EncodingKey, Header};
use serde_json::{json, Value};
use sha2::{Sha256, Sha384, Sha512};

use crate::error::MessagingError;

type HmacSha256 = Hmac<Sha256>;
type HmacSha384 = Hmac<Sha384>;
type HmacSha512 = Hmac<Sha512>;

/// PHP `Utopia\Messaging\Helpers\JWT`.
#[derive(Debug, Clone, Copy)]
pub struct JWT;

impl JWT {
    /// PHP `JWT::encode($payload, $key, $algorithm, $keyId = null)`.
    ///
    /// Header/payload JSON matches PHP `json_encode(..., JSON_UNESCAPED_SLASHES)`
    /// (`serde_json` does not escape slashes). HMAC algorithms use the same
    /// base64url segments as PHP `safeBase64Encode`.
    pub fn encode(
        payload: &Value,
        key: &str,
        algorithm: &str,
        key_id: Option<&str>,
    ) -> Result<String, MessagingError> {
        match algorithm {
            "HS256" | "HS384" | "HS512" => encode_hmac(payload, key, algorithm, key_id),
            "RS256" | "RS384" | "RS512" | "ES256" | "ES384" => {
                encode_asymmetric(payload, key, algorithm, key_id)
            }
            _ => Err(MessagingError::AlgorithmNotSupported),
        }
    }
}

fn encode_hmac(
    payload: &Value,
    key: &str,
    algorithm: &str,
    key_id: Option<&str>,
) -> Result<String, MessagingError> {
    let header = header_json(algorithm, key_id);
    let header_b64 = safe_base64_encode(header.as_bytes());
    let payload_json =
        serde_json::to_string(payload).map_err(|e| MessagingError::message(e.to_string()))?;
    let payload_b64 = safe_base64_encode(payload_json.as_bytes());
    let signing_input = format!("{header_b64}.{payload_b64}");
    let signature = match algorithm {
        "HS256" => hmac_sign::<HmacSha256>(key.as_bytes(), signing_input.as_bytes())?,
        "HS384" => hmac_sign::<HmacSha384>(key.as_bytes(), signing_input.as_bytes())?,
        "HS512" => hmac_sign::<HmacSha512>(key.as_bytes(), signing_input.as_bytes())?,
        _ => return Err(MessagingError::AlgorithmNotSupported),
    };
    let sig_b64 = safe_base64_encode(&signature);
    Ok(format!("{signing_input}.{sig_b64}"))
}

fn encode_asymmetric(
    payload: &Value,
    key: &str,
    algorithm: &str,
    key_id: Option<&str>,
) -> Result<String, MessagingError> {
    let alg = match algorithm {
        "RS256" => Algorithm::RS256,
        "RS384" => Algorithm::RS384,
        "RS512" => Algorithm::RS512,
        "ES256" => Algorithm::ES256,
        "ES384" => Algorithm::ES384,
        _ => return Err(MessagingError::AlgorithmNotSupported),
    };
    let mut header = Header::new(alg);
    header.typ = Some("JWT".into());
    header.kid = key_id.map(str::to_owned);
    let encoding_key = if algorithm.starts_with("RS") {
        EncodingKey::from_rsa_pem(key.as_bytes())
            .or_else(|_| EncodingKey::from_rsa_pem(ensure_pem(key, "RSA PRIVATE KEY").as_bytes()))
            .map_err(|_| MessagingError::JwtSignFailed)?
    } else {
        EncodingKey::from_ec_pem(key.as_bytes())
            .or_else(|_| EncodingKey::from_ec_pem(ensure_pem(key, "EC PRIVATE KEY").as_bytes()))
            .or_else(|_| EncodingKey::from_ec_pem(ensure_pem(key, "PRIVATE KEY").as_bytes()))
            .map_err(|_| MessagingError::JwtSignFailed)?
    };
    encode(&header, payload, &encoding_key).map_err(|_| MessagingError::JwtSignFailed)
}

fn header_json(algorithm: &str, key_id: Option<&str>) -> String {
    let value = if let Some(kid) = key_id {
        json!({"typ": "JWT", "alg": algorithm, "kid": kid})
    } else {
        json!({"typ": "JWT", "alg": algorithm})
    };
    serde_json::to_string(&value).expect("jwt header")
}

fn hmac_sign<M: Mac + KeyInit>(key: &[u8], data: &[u8]) -> Result<Vec<u8>, MessagingError> {
    let mut mac =
        <M as KeyInit>::new_from_slice(key).map_err(|e| MessagingError::message(e.to_string()))?;
    mac.update(data);
    Ok(mac.finalize().into_bytes().to_vec())
}

fn safe_base64_encode(input: &[u8]) -> String {
    URL_SAFE_NO_PAD.encode(input)
}

fn ensure_pem(key: &str, label: &str) -> String {
    if key.contains("BEGIN") {
        return key.to_string();
    }
    let mut wrapped = String::new();
    for (i, chunk) in key.as_bytes().chunks(64).enumerate() {
        if i > 0 {
            wrapped.push('\n');
        }
        wrapped.push_str(std::str::from_utf8(chunk).unwrap_or(""));
    }
    format!("-----BEGIN {label}-----\n{wrapped}\n-----END {label}-----\n")
}
