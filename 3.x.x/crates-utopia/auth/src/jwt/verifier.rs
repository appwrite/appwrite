//! JWT verifier base types.

use std::collections::HashMap;

use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine;
use serde_json::Value;

use crate::error::AuthError;
use crate::jwt::enums::{Claim, Header};

/// Configuration for JWT verification.
#[derive(Debug, Clone, Default)]
pub struct VerifierConfig {
    /// Required `iss` claim; not checked when `None`.
    pub issuer: Option<String>,
    /// Acceptable `aud` values; not checked when `None`.
    pub audience: Option<Vec<String>>,
    /// Required `typ` header; not checked when `None`.
    pub token_type: Option<String>,
    /// Skip the `exp` check when true.
    pub allow_expired: bool,
    /// Clock-skew tolerance in seconds.
    pub leeway: u64,
}

impl VerifierConfig {
    /// Create a verifier configuration.
    pub fn new() -> Self {
        Self::default()
    }

    /// Set required issuer.
    pub fn issuer(mut self, issuer: impl Into<String>) -> Self {
        self.issuer = Some(issuer.into());
        self
    }

    /// Set acceptable audience(s).
    pub fn audience(mut self, audience: impl Into<Audience>) -> Self {
        self.audience = Some(audience.into().0);
        self
    }

    /// Set required token type header.
    pub fn token_type(mut self, token_type: impl Into<String>) -> Self {
        self.token_type = Some(token_type.into());
        self
    }

    /// Allow expired tokens.
    pub fn allow_expired(mut self, allow: bool) -> Self {
        self.allow_expired = allow;
        self
    }

    /// Set clock-skew leeway in seconds.
    pub fn leeway(mut self, leeway: u64) -> Self {
        self.leeway = leeway;
        self
    }
}

/// Audience value(s) for verification.
#[derive(Debug, Clone)]
pub struct Audience(pub Vec<String>);

impl From<&str> for Audience {
    fn from(value: &str) -> Self {
        Self(vec![value.to_owned()])
    }
}

impl From<String> for Audience {
    fn from(value: String) -> Self {
        Self(vec![value])
    }
}

impl From<Vec<String>> for Audience {
    fn from(value: Vec<String>) -> Self {
        Self(value)
    }
}

/// Base functionality for compact JWS token verifiers.
pub trait Verifier {
    /// Expected JWS `alg` header value.
    fn algorithm(&self) -> &'static str;

    /// Verifier configuration.
    fn config(&self) -> &VerifierConfig;

    /// Verify the raw signature against the signing input.
    fn verify_signature(&self, signing_input: &str, signature: &[u8]) -> Result<(), AuthError>;

    /// Verify a compact JWS and return its claims.
    fn verify(&self, token: &str) -> Result<HashMap<String, Value>, AuthError> {
        let config = self.config();
        if config.leeway > i64::MAX as u64 {
            return Err(AuthError::InvalidInput("leeway cannot be negative".into()));
        }

        let segments: Vec<&str> = token.split('.').collect();
        if segments.len() != 3 {
            return Err(AuthError::Verification(
                "token must have three segments".into(),
            ));
        }

        let encoded_header = segments[0];
        let encoded_claims = segments[1];
        let encoded_signature = segments[2];

        let header = decode_segment(encoded_header, "header")?;
        let claims = decode_segment(encoded_claims, "claims")?;

        let signature = base64_url_decode(encoded_signature)
            .ok_or_else(|| AuthError::Verification("signature is not valid base64url".into()))?;

        if header.get(Header::Algorithm.as_str()) != Some(&Value::String(self.algorithm().into())) {
            return Err(AuthError::Verification("unexpected token algorithm".into()));
        }

        if let Some(expected_type) = &config.token_type {
            if header.get(Header::Type.as_str()) != Some(&Value::String(expected_type.clone())) {
                return Err(AuthError::Verification("unexpected token type".into()));
            }
        }

        self.verify_signature(&format!("{encoded_header}.{encoded_claims}"), &signature)?;

        validate_claims(&claims, config)?;

        Ok(claims)
    }
}

fn validate_claims(
    claims: &HashMap<String, Value>,
    config: &VerifierConfig,
) -> Result<(), AuthError> {
    let now = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map_err(|e| AuthError::Verification(e.to_string()))?
        .as_secs() as i64;
    let leeway = i64::try_from(config.leeway).unwrap_or(i64::MAX);

    if let Some(nbf) = claims.get(Claim::NotBefore.as_str()) {
        let nbf = numeric_claim(nbf, "nbf")?;
        if now + leeway < nbf {
            return Err(AuthError::Verification("token is not yet valid".into()));
        }
    }

    if let Some(iat) = claims.get(Claim::IssuedAt.as_str()) {
        let iat = numeric_claim(iat, "iat")?;
        if now + leeway < iat {
            return Err(AuthError::Verification(
                "token was issued in the future".into(),
            ));
        }
    }

    if !config.allow_expired {
        let exp = claims
            .get(Claim::Expiration.as_str())
            .ok_or_else(|| AuthError::Verification("token is missing the \"exp\" claim".into()))?;
        let exp = numeric_claim(exp, "exp")?;
        if now >= exp + leeway {
            return Err(AuthError::Verification("token has expired".into()));
        }
    }

    if let Some(expected_issuer) = &config.issuer {
        if claims.get(Claim::Issuer.as_str()) != Some(&Value::String(expected_issuer.clone())) {
            return Err(AuthError::Verification("unexpected token issuer".into()));
        }
    }

    if let Some(expected_audiences) = &config.audience {
        let token_aud = claims.get(Claim::Audience.as_str());
        if !audience_matches(token_aud, expected_audiences) {
            return Err(AuthError::Verification("unexpected token audience".into()));
        }
    }

    Ok(())
}

fn audience_matches(token_aud: Option<&Value>, expected: &[String]) -> bool {
    let token_audiences: Vec<&str> = match token_aud {
        Some(Value::String(s)) => vec![s.as_str()],
        Some(Value::Array(items)) => items.iter().filter_map(Value::as_str).collect(),
        Some(_) | None => return false,
    };

    expected
        .iter()
        .any(|expected| token_audiences.contains(&expected.as_str()))
}

fn numeric_claim(value: &Value, name: &str) -> Result<i64, AuthError> {
    match value {
        Value::Number(number) => number
            .as_i64()
            .ok_or_else(|| AuthError::Verification(format!("invalid \"{name}\" claim"))),
        _ => Err(AuthError::Verification(format!("invalid \"{name}\" claim"))),
    }
}

fn decode_segment(segment: &str, name: &str) -> Result<HashMap<String, Value>, AuthError> {
    let label = capitalize(name);
    let decoded = base64_url_decode(segment)
        .ok_or_else(|| AuthError::Verification(format!("{label} is not valid base64url")))?;

    let value: Value = serde_json::from_slice(&decoded)
        .map_err(|_| AuthError::Verification(format!("{label} is not valid JSON")))?;

    let Value::Object(map) = value else {
        return Err(AuthError::Verification(format!(
            "{label} must be a JSON object"
        )));
    };

    Ok(map.into_iter().collect())
}

fn base64_url_decode(value: &str) -> Option<Vec<u8>> {
    URL_SAFE_NO_PAD.decode(value).ok()
}

fn capitalize(value: &str) -> String {
    let mut chars = value.chars();
    match chars.next() {
        None => String::new(),
        Some(first) => first.to_uppercase().collect::<String>() + chars.as_str(),
    }
}
