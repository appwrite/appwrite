//! RS256 asymmetric JWT issuer.

use std::collections::HashMap;

use rsa::pkcs8::{
    DecodePrivateKey, DecodePublicKey, EncodePrivateKey, EncodePublicKey, LineEnding,
};
use rsa::traits::PublicKeyParts;
use rsa::{RsaPrivateKey, RsaPublicKey};
use serde_json::Value;
use sha2::{Digest, Sha256};

use crate::error::AuthError;
use crate::jwt::enums::{Claim, Header};
use crate::jwt::issuer::{require_issuer, Issuer};

/// Base issuer for tokens signed with RS256.
#[derive(Debug, Clone)]
pub struct AsymmetricIssuer {
    private_key_pem: String,
    public_key_pem: String,
    issuer: String,
    key_id: Option<String>,
    token_type: String,
}

impl AsymmetricIssuer {
    /// Create an RS256 issuer from PEM-encoded RSA keys.
    pub fn new(
        private_key_pem: impl Into<String>,
        public_key_pem: impl Into<String>,
        issuer: impl Into<String>,
        token_type: impl Into<String>,
        key_id: Option<String>,
    ) -> Result<Self, AuthError> {
        let private_key_pem = private_key_pem.into();
        let public_key_pem = public_key_pem.into();
        if private_key_pem.is_empty()
            || private_key_pem == "0"
            || public_key_pem.is_empty()
            || public_key_pem == "0"
        {
            return Err(AuthError::InvalidInput(
                "both a private and a public key are required".into(),
            ));
        }
        let issuer = issuer.into();
        require_issuer(&issuer)?;
        Ok(Self {
            private_key_pem,
            public_key_pem,
            issuer,
            key_id,
            token_type: token_type.into(),
        })
    }

    /// Generate a fresh RSA keypair suitable for RS256 signing.
    pub fn generate_key_pair(bits: usize) -> Result<(String, String), AuthError> {
        let mut rng = rand::thread_rng();
        let private_key = RsaPrivateKey::new(&mut rng, bits)
            .map_err(|e| AuthError::SigningFailed(e.to_string()))?;
        let public_key = RsaPublicKey::from(&private_key);

        let private_pem = private_key
            .to_pkcs8_pem(LineEnding::LF)
            .map_err(|e| AuthError::SigningFailed(e.to_string()))?
            .to_string();
        let public_pem = public_key
            .to_public_key_pem(LineEnding::LF)
            .map_err(|e| AuthError::SigningFailed(e.to_string()))?;

        Ok((private_pem, public_pem))
    }

    /// Deterministic key identifier derived from the RSA modulus.
    pub fn key_id(&mut self) -> Result<String, AuthError> {
        if let Some(kid) = &self.key_id {
            return Ok(kid.clone());
        }
        let modulus = self.modulus()?;
        let kid = derive_key_id(&modulus);
        self.key_id = Some(kid.clone());
        Ok(kid)
    }

    /// Public key as a JWK suitable for JWKS endpoints.
    pub fn public_jwk(&mut self) -> Result<HashMap<String, String>, AuthError> {
        let public_key = RsaPublicKey::from_public_key_pem(&self.public_key_pem)
            .map_err(|e| AuthError::SigningFailed(e.to_string()))?;
        let n = public_key.n().to_bytes_be();
        let e = public_key.e().to_bytes_be();
        let kid = self.key_id()?;

        Ok(HashMap::from([
            ("kty".into(), "RSA".into()),
            ("use".into(), "sig".into()),
            ("alg".into(), "RS256".into()),
            ("kid".into(), kid),
            ("n".into(), base64_url_encode(&n)),
            ("e".into(), base64_url_encode(&e)),
        ]))
    }

    /// Issue a signed JWT with the given claims.
    pub fn issue_claims(&self, mut claims: HashMap<String, Value>) -> Result<String, AuthError> {
        claims.insert(
            Claim::Issuer.as_str().into(),
            Value::String(self.issuer.clone()),
        );
        self.sign(&claims)
    }

    fn modulus(&self) -> Result<Vec<u8>, AuthError> {
        let public_key = RsaPublicKey::from_public_key_pem(&self.public_key_pem)
            .map_err(|e| AuthError::SigningFailed(e.to_string()))?;
        Ok(public_key.n().to_bytes_be())
    }
}

impl Issuer for AsymmetricIssuer {
    fn issuer(&self) -> &str {
        &self.issuer
    }

    fn token_type(&self) -> &str {
        &self.token_type
    }

    fn algorithm(&self) -> &'static str {
        "RS256"
    }

    fn extra_headers(&self) -> HashMap<String, Value> {
        let kid = self
            .key_id
            .clone()
            .or_else(|| self.modulus().ok().map(|m| derive_key_id(&m)))
            .unwrap_or_default();
        HashMap::from([(Header::KeyId.as_str().into(), Value::String(kid))])
    }

    fn sign_input(&self, signing_input: &str) -> Result<Vec<u8>, AuthError> {
        use rsa::pkcs1v15::SigningKey;
        use rsa::signature::{RandomizedSigner, SignatureEncoding};

        let private_key = RsaPrivateKey::from_pkcs8_pem(&self.private_key_pem)
            .map_err(|e| AuthError::SigningFailed(e.to_string()))?;
        let signing_key = SigningKey::<Sha256>::new(private_key);
        let mut rng = rand::thread_rng();
        let signature = signing_key.sign_with_rng(&mut rng, signing_input.as_bytes());
        Ok(signature.to_vec())
    }
}

/// `OAuth2` access token issuer (`RS256`, `typ = at+jwt`).
#[derive(Debug, Clone)]
pub struct AccessToken {
    inner: AsymmetricIssuer,
}

impl AccessToken {
    /// Create an access token issuer.
    pub fn new(
        private_key_pem: impl Into<String>,
        public_key_pem: impl Into<String>,
        issuer: impl Into<String>,
    ) -> Result<Self, AuthError> {
        Self::with_key_id(private_key_pem, public_key_pem, issuer, None)
    }

    /// Create an access token issuer with an explicit key id.
    pub fn with_key_id(
        private_key_pem: impl Into<String>,
        public_key_pem: impl Into<String>,
        issuer: impl Into<String>,
        key_id: Option<String>,
    ) -> Result<Self, AuthError> {
        Ok(Self {
            inner: AsymmetricIssuer::new(
                private_key_pem,
                public_key_pem,
                issuer,
                "at+jwt",
                key_id,
            )?,
        })
    }

    /// Generate a fresh RSA keypair suitable for RS256 signing.
    pub fn generate_key_pair(bits: usize) -> Result<(String, String), AuthError> {
        AsymmetricIssuer::generate_key_pair(bits)
    }

    /// Deterministic key identifier derived from the RSA modulus.
    pub fn key_id(&mut self) -> Result<String, AuthError> {
        self.inner.key_id()
    }

    /// Public key as a JWK suitable for JWKS endpoints.
    pub fn public_jwk(&mut self) -> Result<HashMap<String, String>, AuthError> {
        self.inner.public_jwk()
    }

    /// Issue an RFC 9068 access token.
    #[allow(clippy::too_many_arguments)]
    pub fn issue(
        &self,
        subject: &str,
        audience: &[&str],
        client_id: &str,
        auth_time: i64,
        duration_secs: i64,
        scopes: &[&str],
        jti: Option<&str>,
        extra_claims: HashMap<String, Value>,
    ) -> Result<String, AuthError> {
        if audience.is_empty() {
            return Err(AuthError::InvalidInput(
                "audience must contain at least one resource server identifier.".into(),
            ));
        }
        if audience.iter().any(|identifier| identifier.is_empty()) {
            return Err(AuthError::InvalidInput(
                "audience must contain non-empty resource server identifiers.".into(),
            ));
        }

        let now = now_timestamp()?;
        let mut claims = extra_claims;
        claims.remove(Claim::Scope.as_str());

        claims.insert(
            Claim::Issuer.as_str().into(),
            Value::String(self.inner.issuer.clone()),
        );
        claims.insert(
            Claim::Audience.as_str().into(),
            Value::Array(
                audience
                    .iter()
                    .map(|identifier| Value::String((*identifier).to_owned()))
                    .collect(),
            ),
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
        claims.insert(
            Claim::AuthTime.as_str().into(),
            Value::Number(auth_time.into()),
        );

        if !scopes.is_empty() {
            claims.insert(
                Claim::Scope.as_str().into(),
                Value::String(scopes.join(" ")),
            );
        }

        self.inner.sign(&claims)
    }
}

/// OIDC id token issuer (`RS256`, `typ = JWT`).
#[derive(Debug, Clone)]
pub struct IdToken {
    inner: AsymmetricIssuer,
}

impl IdToken {
    /// Create an id token issuer.
    pub fn new(
        private_key_pem: impl Into<String>,
        public_key_pem: impl Into<String>,
        issuer: impl Into<String>,
    ) -> Result<Self, AuthError> {
        Self::with_key_id(private_key_pem, public_key_pem, issuer, None)
    }

    /// Create an id token issuer with an explicit key id.
    pub fn with_key_id(
        private_key_pem: impl Into<String>,
        public_key_pem: impl Into<String>,
        issuer: impl Into<String>,
        key_id: Option<String>,
    ) -> Result<Self, AuthError> {
        Ok(Self {
            inner: AsymmetricIssuer::new(private_key_pem, public_key_pem, issuer, "JWT", key_id)?,
        })
    }

    /// Generate a fresh RSA keypair suitable for RS256 signing.
    pub fn generate_key_pair(bits: usize) -> Result<(String, String), AuthError> {
        AsymmetricIssuer::generate_key_pair(bits)
    }

    /// Deterministic key identifier derived from the RSA modulus.
    pub fn key_id(&mut self) -> Result<String, AuthError> {
        self.inner.key_id()
    }

    /// Public key as a JWK suitable for JWKS endpoints.
    pub fn public_jwk(&mut self) -> Result<HashMap<String, String>, AuthError> {
        self.inner.public_jwk()
    }

    /// OIDC left-most-half token/code hash.
    pub fn left_half_hash(value: &str) -> String {
        let digest = Sha256::digest(value.as_bytes());
        base64_url_encode(&digest[..16])
    }

    /// Issue an OIDC id token.
    #[allow(clippy::too_many_arguments)]
    pub fn issue(
        &self,
        subject: &str,
        audience: &str,
        auth_time: i64,
        duration_secs: i64,
        nonce: Option<&str>,
        access_token: Option<&str>,
        code: Option<&str>,
        extra_claims: HashMap<String, Value>,
    ) -> Result<String, AuthError> {
        let now = now_timestamp()?;
        let mut claims = extra_claims;
        claims.remove(Claim::Nonce.as_str());
        claims.remove(Claim::AccessTokenHash.as_str());
        claims.remove(Claim::CodeHash.as_str());

        claims.insert(
            Claim::Issuer.as_str().into(),
            Value::String(self.inner.issuer.clone()),
        );
        claims.insert(
            Claim::Subject.as_str().into(),
            Value::String(subject.into()),
        );
        claims.insert(
            Claim::Audience.as_str().into(),
            Value::String(audience.into()),
        );
        claims.insert(
            Claim::Expiration.as_str().into(),
            Value::Number((now + duration_secs).into()),
        );
        claims.insert(Claim::IssuedAt.as_str().into(), Value::Number(now.into()));
        claims.insert(
            Claim::AuthTime.as_str().into(),
            Value::Number(auth_time.into()),
        );

        if let Some(nonce) = optional_oidc_value(nonce) {
            claims.insert(
                Claim::Nonce.as_str().into(),
                Value::String(nonce.to_owned()),
            );
        }
        if let Some(access_token) = optional_oidc_value(access_token) {
            claims.insert(
                Claim::AccessTokenHash.as_str().into(),
                Value::String(Self::left_half_hash(access_token)),
            );
        }
        if let Some(code) = optional_oidc_value(code) {
            claims.insert(
                Claim::CodeHash.as_str().into(),
                Value::String(Self::left_half_hash(code)),
            );
        }

        self.inner.sign(&claims)
    }
}

fn derive_key_id(modulus: &[u8]) -> String {
    let digest = Sha256::digest(modulus);
    hex::encode(digest)
}

fn base64_url_encode(bytes: &[u8]) -> String {
    use base64::engine::general_purpose::URL_SAFE_NO_PAD;
    use base64::Engine;
    URL_SAFE_NO_PAD.encode(bytes)
}

fn now_timestamp() -> Result<i64, AuthError> {
    Ok(std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map_err(|e| AuthError::SigningFailed(e.to_string()))?
        .as_secs() as i64)
}

fn optional_oidc_value(value: Option<&str>) -> Option<&str> {
    value.filter(|value| !value.is_empty() && *value != "0")
}
