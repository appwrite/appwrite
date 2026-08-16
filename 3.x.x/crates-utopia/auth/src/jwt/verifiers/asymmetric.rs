//! RS256 asymmetric JWT verifier.

use rsa::pkcs8::DecodePublicKey;
use rsa::traits::PublicKeyParts;
use rsa::{pkcs1v15::VerifyingKey, signature::Verifier as RsaVerifier, RsaPublicKey};
use sha2::{Digest, Sha256};

use crate::error::AuthError;
use crate::jwt::verifier::{Verifier, VerifierConfig};

/// Verifies tokens signed with RS256.
#[derive(Debug, Clone)]
pub struct AsymmetricVerifier {
    public_key_pem: String,
    config: VerifierConfig,
}

impl AsymmetricVerifier {
    /// Create an RS256 verifier from a PEM-encoded public key.
    pub fn new(
        public_key_pem: impl Into<String>,
        config: VerifierConfig,
    ) -> Result<Self, AuthError> {
        let public_key_pem = public_key_pem.into();
        if public_key_pem.is_empty() || public_key_pem == "0" {
            return Err(AuthError::InvalidInput("a public key is required".into()));
        }
        Ok(Self {
            public_key_pem,
            config,
        })
    }

    /// Convenience constructor with default configuration.
    pub fn with_public_key(public_key_pem: impl Into<String>) -> Result<Self, AuthError> {
        Self::new(public_key_pem, VerifierConfig::default())
    }

    /// Deterministic key identifier derived from the RSA modulus.
    pub fn key_id(&self) -> Result<String, AuthError> {
        let modulus = self.modulus()?;
        Ok(derive_key_id(&modulus))
    }

    fn modulus(&self) -> Result<Vec<u8>, AuthError> {
        let public_key = RsaPublicKey::from_public_key_pem(&self.public_key_pem)
            .map_err(|e| AuthError::Verification(e.to_string()))?;
        Ok(public_key.n().to_bytes_be())
    }
}

impl Verifier for AsymmetricVerifier {
    fn algorithm(&self) -> &'static str {
        "RS256"
    }

    fn config(&self) -> &VerifierConfig {
        &self.config
    }

    fn verify_signature(&self, signing_input: &str, signature: &[u8]) -> Result<(), AuthError> {
        let public_key = RsaPublicKey::from_public_key_pem(&self.public_key_pem)
            .map_err(|e| AuthError::Verification(e.to_string()))?;
        let verifying_key = VerifyingKey::<Sha256>::new(public_key);
        let signature = rsa::pkcs1v15::Signature::try_from(signature)
            .map_err(|e| AuthError::Verification(e.to_string()))?;

        verifying_key
            .verify(signing_input.as_bytes(), &signature)
            .map_err(|_| AuthError::Verification("signature verification failed".into()))
    }
}

fn derive_key_id(modulus: &[u8]) -> String {
    let digest = Sha256::digest(modulus);
    hex::encode(digest)
}
