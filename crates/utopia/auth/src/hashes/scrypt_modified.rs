use aes::cipher::{KeyIvInit, StreamCipher};
use php_std::encoding::{base64_decode, base64_encode, hex2bin};
use php_std::mb::mb_scrub;
use php_std::pcre::{Value, preg_match_bare};

use super::hash_plumbing;
use super::scrypt::scrypt;
use crate::hash::{hash_equals, random_bytes};
use crate::{Error, Hash, Options};

/// Firebase's modified scrypt (`Utopia\Auth\Hashes\ScryptModified`): the
/// signer key encrypted with AES-256-CTR under a scrypt-derived key, base64.
///
/// Options: `type`, `salt`, `saltSeparator`, `signerKey` (all base64).
#[derive(Debug, Clone, PartialEq)]
pub struct ScryptModified {
    options: Options,
}

impl Default for ScryptModified {
    fn default() -> Self {
        Self::new()
    }
}

const BASE64: &[u8] = b"/^[A-Za-z0-9+\\/]+={0,2}$/";

fn is_base64(value: &[u8]) -> bool {
    matches!(preg_match_bare(BASE64, value), Ok(p) if p.value == Value::Int(1))
}

impl ScryptModified {
    /// `new ScryptModified()`: random salt (16 bytes), separator (16) and signer key (32).
    pub fn new() -> Self {
        let (mut salt, mut separator, mut signer) = ([0u8; 16], [0u8; 16], [0u8; 32]);
        random_bytes(&mut salt);
        random_bytes(&mut separator);
        random_bytes(&mut signer);
        let mut options = Options::new();
        options.set("type", "scryptMod");
        options.set("salt", base64_encode(&salt));
        options.set("saltSeparator", base64_encode(&separator));
        options.set("signerKey", base64_encode(&signer));
        Self { options }
    }

    /// `setSalt()`: non-empty base64.
    pub fn set_salt(&mut self, salt: &[u8]) -> Result<&mut Self, Error> {
        if salt.is_empty() || salt == b"0" {
            return Err(Error::InvalidArgument("Salt cannot be empty".into()));
        }
        if !is_base64(salt) {
            return Err(Error::InvalidArgument("Salt must be base64 encoded".into()));
        }
        self.options.set("salt", salt.to_vec());
        Ok(self)
    }

    /// `setSaltSeparator()`: base64.
    pub fn set_salt_separator(&mut self, separator: &[u8]) -> Result<&mut Self, Error> {
        if !is_base64(separator) {
            return Err(Error::InvalidArgument("Salt separator must be base64 encoded".into()));
        }
        self.options.set("saltSeparator", separator.to_vec());
        Ok(self)
    }

    /// `setSignerKey()`: non-empty base64.
    pub fn set_signer_key(&mut self, key: &[u8]) -> Result<&mut Self, Error> {
        if key.is_empty() || key == b"0" {
            return Err(Error::InvalidArgument("Signer key cannot be empty".into()));
        }
        if !is_base64(key) {
            return Err(Error::InvalidArgument("Signer key must be base64 encoded".into()));
        }
        self.options.set("signerKey", key.to_vec());
        Ok(self)
    }
}

impl Hash for ScryptModified {
    hash_plumbing!("scryptMod");

    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        let o = &self.options;
        let Some(signer) = o.string("signerKey") else {
            return Err(Error::InvalidArgument("Signer key must be a string".into()));
        };
        let (Some(salt), Some(separator)) = (o.string("salt"), o.string("saltSeparator")) else {
            return Err(Error::InvalidArgument("Salt and salt separator must be strings".into()));
        };
        let mut full = base64_decode(salt, false).unwrap_or_default();
        full.extend_from_slice(&base64_decode(separator, false).unwrap_or_default());
        let derived = scrypt(&mb_scrub(value), &full, 16384, 8, 1, 64)?;
        let derived = hex2bin(&derived)
            .ok_or_else(|| Error::Runtime("Failed to convert derived key from hex to binary".into()))?;
        let mut data = base64_decode(signer, false).unwrap_or_default();
        let iv = [0u8; 16];
        let mut cipher = ctr::Ctr128BE::<aes::Aes256>::new((&derived[..32]).into(), (&iv).into());
        cipher.apply_keystream(&mut data);
        Ok(base64_encode(&data).into_bytes())
    }

    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(hash_equals(hash, &self.hash(value)?))
    }
}
