//! HS256 symmetric JWT verifier (`jsonwebtoken`).

use std::collections::HashMap;

use jsonwebtoken::{decode, Algorithm, DecodingKey, Validation};
use serde_json::Value;

use crate::error::AuthError;
use crate::jwt::verifier::{Verifier, VerifierConfig};

/// Verifies tokens signed with HS256.
#[derive(Debug, Clone)]
pub struct SymmetricVerifier {
    secret: String,
    config: VerifierConfig,
}

impl SymmetricVerifier {
    /// Create an HS256 verifier.
    pub fn new(secret: impl Into<String>, config: VerifierConfig) -> Result<Self, AuthError> {
        let secret = secret.into();
        if secret.is_empty() || secret == "0" {
            return Err(AuthError::InvalidInput(
                "a signing secret is required".into(),
            ));
        }
        Ok(Self { secret, config })
    }

    /// Convenience constructor with default configuration.
    pub fn with_secret(secret: impl Into<String>) -> Result<Self, AuthError> {
        Self::new(secret, VerifierConfig::default())
    }

    /// Verify a compact JWS and return its claims using `jsonwebtoken`.
    pub fn verify(&self, token: &str) -> Result<HashMap<String, Value>, AuthError> {
        let mut validation = Validation::new(Algorithm::HS256);
        validation.validate_exp = !self.config.allow_expired;
        validation.leeway = self.config.leeway;

        if let Some(issuer) = &self.config.issuer {
            validation.set_issuer(&[issuer.as_str()]);
        }

        if let Some(audience) = &self.config.audience {
            let refs: Vec<&str> = audience.iter().map(String::as_str).collect();
            validation.set_audience(&refs);
        }

        let token_data = decode::<HashMap<String, Value>>(
            token,
            &DecodingKey::from_secret(self.secret.as_bytes()),
            &validation,
        )
        .map_err(|e| AuthError::Verification(e.to_string()))?;

        if let Some(expected_type) = &self.config.token_type {
            if token_data.header.typ.as_deref() != Some(expected_type.as_str()) {
                return Err(AuthError::Verification("unexpected token type".into()));
            }
        }

        Ok(token_data.claims)
    }
}

impl Verifier for SymmetricVerifier {
    fn algorithm(&self) -> &'static str {
        "HS256"
    }

    fn config(&self) -> &VerifierConfig {
        &self.config
    }

    fn verify_signature(&self, signing_input: &str, signature: &[u8]) -> Result<(), AuthError> {
        use hmac::{Hmac, Mac};
        use sha2::Sha256;

        type HmacSha256 = Hmac<Sha256>;

        let mut mac = HmacSha256::new_from_slice(self.secret.as_bytes())
            .map_err(|e| AuthError::Verification(e.to_string()))?;
        mac.update(signing_input.as_bytes());

        mac.verify_slice(signature)
            .map_err(|_| AuthError::Verification("signature verification failed".into()))
    }
}
