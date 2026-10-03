//! JWT issuer base types.

use std::collections::HashMap;

use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine;
use rand::RngCore;
use serde_json::Value;

use crate::error::AuthError;
use crate::jwt::enums::Header;

/// Base functionality for compact JWS token issuers.
pub trait Issuer {
    /// Token issuer (`iss` claim value).
    fn issuer(&self) -> &str;

    /// JWS `typ` header value.
    fn token_type(&self) -> &str;

    /// JWS `alg` header value.
    fn algorithm(&self) -> &'static str;

    /// Extra header fields (e.g. `kid`).
    fn extra_headers(&self) -> HashMap<String, Value> {
        HashMap::new()
    }

    /// Produce the raw (binary) signature for the signing input.
    fn sign_input(&self, signing_input: &str) -> Result<Vec<u8>, AuthError>;

    /// Encode claims into a signed compact JWS.
    fn sign(&self, claims: &HashMap<String, Value>) -> Result<String, AuthError> {
        let mut header = HashMap::from([
            (
                Header::Type.as_str().into(),
                Value::String(self.token_type().into()),
            ),
            (
                Header::Algorithm.as_str().into(),
                Value::String(self.algorithm().into()),
            ),
        ]);
        header.extend(self.extra_headers());

        let header_json =
            serde_json::to_string(&header).map_err(|e| AuthError::SigningFailed(e.to_string()))?;
        let claims_json =
            serde_json::to_string(claims).map_err(|e| AuthError::SigningFailed(e.to_string()))?;

        let signing_input = format!(
            "{}.{}",
            base64_url_encode(header_json.as_bytes()),
            base64_url_encode(claims_json.as_bytes())
        );

        let signature = self.sign_input(&signing_input)?;
        Ok(format!(
            "{}.{}",
            signing_input,
            base64_url_encode(&signature)
        ))
    }

    /// Generate a random hex `jti` claim.
    fn generate_jti(&self, bytes: usize) -> String {
        let mut buf = vec![0u8; bytes];
        rand::thread_rng().fill_bytes(&mut buf);
        hex::encode(buf)
    }
}

/// Validate issuer string.
pub fn require_issuer(issuer: &str) -> Result<(), AuthError> {
    if issuer.is_empty() || issuer == "0" {
        return Err(AuthError::InvalidInput("an issuer is required".into()));
    }
    Ok(())
}

fn base64_url_encode(bytes: &[u8]) -> String {
    URL_SAFE_NO_PAD.encode(bytes)
}
