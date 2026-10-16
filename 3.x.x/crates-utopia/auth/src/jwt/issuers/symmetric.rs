//! HS256 symmetric JWT issuer (`jsonwebtoken`).

use std::collections::HashMap;

use jsonwebtoken::{encode, Algorithm, EncodingKey, Header};
use serde_json::Value;

use crate::error::AuthError;
use crate::jwt::enums::Claim;
use crate::jwt::issuer::{require_issuer, Issuer};

/// Base issuer for tokens signed with HS256.
#[derive(Debug, Clone)]
pub struct SymmetricIssuer {
    secret: String,
    issuer: String,
    key_id: Option<String>,
    token_type: String,
}

impl SymmetricIssuer {
    /// Create an HS256 issuer.
    pub fn new(
        secret: impl Into<String>,
        issuer: impl Into<String>,
        token_type: impl Into<String>,
        key_id: Option<String>,
    ) -> Result<Self, AuthError> {
        let secret = secret.into();
        if secret.is_empty() || secret == "0" {
            return Err(AuthError::InvalidInput(
                "a signing secret is required".into(),
            ));
        }
        let issuer = issuer.into();
        require_issuer(&issuer)?;
        Ok(Self {
            secret,
            issuer,
            key_id,
            token_type: token_type.into(),
        })
    }

    /// Generate a cryptographically strong HS256 secret (hex-encoded).
    pub fn generate_secret(bytes: usize) -> String {
        use rand::RngCore;
        let mut buf = vec![0u8; bytes];
        rand::thread_rng().fill_bytes(&mut buf);
        hex::encode(buf)
    }

    /// Optional key identifier header.
    #[must_use]
    pub fn key_id(&self) -> Option<&str> {
        self.key_id.as_deref()
    }

    /// Issue a signed JWT with the given claims.
    pub fn issue_claims(&self, mut claims: HashMap<String, Value>) -> Result<String, AuthError> {
        claims.insert(
            Claim::Issuer.as_str().into(),
            Value::String(self.issuer.clone()),
        );
        self.sign_jwt(&claims)
    }

    /// Sign claims into a compact JWS using `jsonwebtoken` (HS256).
    pub fn sign_jwt(&self, claims: &HashMap<String, Value>) -> Result<String, AuthError> {
        let mut header = Header::new(Algorithm::HS256);
        header.typ = Some(self.token_type.clone());
        header.kid.clone_from(&self.key_id);

        let payload = Value::Object(claims.clone().into_iter().collect());
        encode(
            &header,
            &payload,
            &EncodingKey::from_secret(self.secret.as_bytes()),
        )
        .map_err(|e| AuthError::SigningFailed(e.to_string()))
    }
}

impl Issuer for SymmetricIssuer {
    fn issuer(&self) -> &str {
        &self.issuer
    }

    fn token_type(&self) -> &str {
        &self.token_type
    }

    fn algorithm(&self) -> &'static str {
        "HS256"
    }

    fn sign_input(&self, signing_input: &str) -> Result<Vec<u8>, AuthError> {
        use hmac::{Hmac, Mac};
        use sha2::Sha256;

        type HmacSha256 = Hmac<Sha256>;

        let mut mac = HmacSha256::new_from_slice(self.secret.as_bytes())
            .map_err(|e| AuthError::SigningFailed(e.to_string()))?;
        mac.update(signing_input.as_bytes());
        Ok(mac.finalize().into_bytes().to_vec())
    }

    fn sign(&self, claims: &HashMap<String, Value>) -> Result<String, AuthError> {
        self.sign_jwt(claims)
    }
}

/// `OAuth2` refresh token issuer (HS256, `typ = JWT`).
#[derive(Debug, Clone)]
pub struct RefreshToken {
    inner: SymmetricIssuer,
}

impl RefreshToken {
    /// Create a refresh token issuer.
    pub fn new(secret: impl Into<String>, issuer: impl Into<String>) -> Result<Self, AuthError> {
        Self::with_key_id(secret, issuer, None)
    }

    /// Create a refresh token issuer with an explicit key id.
    pub fn with_key_id(
        secret: impl Into<String>,
        issuer: impl Into<String>,
        key_id: Option<String>,
    ) -> Result<Self, AuthError> {
        Ok(Self {
            inner: SymmetricIssuer::new(secret, issuer, "JWT", key_id)?,
        })
    }

    /// Optional key identifier header.
    #[must_use]
    pub fn key_id(&self) -> Option<&str> {
        self.inner.key_id()
    }

    /// Generate a signing secret.
    pub fn generate_secret(bytes: usize) -> String {
        SymmetricIssuer::generate_secret(bytes)
    }

    /// Issue an `OAuth2` refresh token.
    #[allow(clippy::too_many_arguments)]
    pub fn issue(
        &self,
        subject: &str,
        audience: &str,
        client_id: &str,
        duration_secs: i64,
        scopes: &[&str],
        jti: Option<&str>,
        extra_claims: HashMap<String, Value>,
    ) -> Result<String, AuthError> {
        let now = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map_err(|e| AuthError::SigningFailed(e.to_string()))?
            .as_secs() as i64;

        let mut claims = extra_claims;
        claims.remove(Claim::Scope.as_str());

        claims.insert(
            Claim::Issuer.as_str().into(),
            Value::String(self.inner.issuer.clone()),
        );
        claims.insert(
            Claim::Audience.as_str().into(),
            Value::String(audience.into()),
        );
        claims.insert(
            Claim::Subject.as_str().into(),
            Value::String(subject.into()),
        );
        claims.insert(
            Claim::ClientId.as_str().into(),
            Value::String(client_id.into()),
        );
        claims.insert(
            Claim::Expiration.as_str().into(),
            Value::Number((now + duration_secs).into()),
        );
        claims.insert(Claim::IssuedAt.as_str().into(), Value::Number(now.into()));
        claims.insert(
            Claim::JwtId.as_str().into(),
            Value::String(jti.map_or_else(|| self.inner.generate_jti(16), str::to_owned)),
        );

        if !scopes.is_empty() {
            claims.insert(
                Claim::Scope.as_str().into(),
                Value::String(scopes.join(" ")),
            );
        }

        self.inner.sign_jwt(&claims)
    }
}
