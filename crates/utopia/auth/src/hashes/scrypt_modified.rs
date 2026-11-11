//! Firebase's modified scrypt password hash.

use aes::cipher::{KeyIvInit, StreamCipher};
use php_std::encoding::{base64_decode, base64_encode, hex2bin};
use php_std::mb::mb_scrub;

use super::hash_plumbing;
use super::scrypt::scrypt;
use crate::hash::{hash_equals, random_bytes};
use crate::{Error, Hash, Options};

/// Firebase's modified scrypt: the signer key encrypted with AES-256-CTR
/// under a key scrypt derives from the password, as base64.
///
/// Use it to verify passwords exported from Firebase Authentication.
///
/// PHP: `Utopia\Auth\Hashes\ScryptModified`.
///
/// Options: `type`, `salt`, `saltSeparator`, `signerKey` (all base64).
#[derive(Debug, Clone, PartialEq)]
pub struct ScryptModified {
    options: Options,
}

impl Default for ScryptModified {
    /// The same as [`ScryptModified::new`].
    fn default() -> Self {
        Self::new()
    }
}

/// `preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $value) === 1`: base64
/// characters, then at most two `=`. Without `/D`, `$` also matches before
/// one final newline.
fn is_base64(value: &[u8]) -> bool {
    let body = value.strip_suffix(b"\n").unwrap_or(value);
    let digits = body.iter().take_while(|&&b| b.is_ascii_alphanumeric() || b == b'+' || b == b'/').count();
    let padding = &body[digits..];
    digits > 0 && padding.len() <= 2 && padding.iter().all(|&b| b == b'=')
}

impl ScryptModified {
    /// Modified scrypt with a random salt (16 bytes), salt separator (16) and
    /// signer key (32).
    ///
    /// PHP: `new ScryptModified()`.
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

    /// Sets the base64 salt.
    ///
    /// Fails with [`Error::InvalidArgument`] when it is empty, `"0"` or not base64.
    ///
    /// PHP: `setSalt()`.
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

    /// Sets the base64 salt separator.
    ///
    /// Fails with [`Error::InvalidArgument`] when it is not base64.
    ///
    /// PHP: `setSaltSeparator()`.
    pub fn set_salt_separator(&mut self, separator: &[u8]) -> Result<&mut Self, Error> {
        if !is_base64(separator) {
            return Err(Error::InvalidArgument("Salt separator must be base64 encoded".into()));
        }
        self.options.set("saltSeparator", separator.to_vec());
        Ok(self)
    }

    /// Sets the base64 signer key.
    ///
    /// Fails with [`Error::InvalidArgument`] when it is empty, `"0"` or not base64.
    ///
    /// PHP: `setSignerKey()`.
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

    /// Encrypts the signer key under a key derived from `value`, as base64.
    ///
    /// Invalid UTF-8 in `value` is replaced before the key is derived. Fails
    /// when the salt, separator or signer key is not a string.
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

    /// Whether hashing `value` gives `hash`, compared in constant time.
    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(hash_equals(hash, &self.hash(value)?))
    }
}

#[cfg(test)]
mod tests {
    use php_std::pcre::{Value, preg_match_bare};

    use super::is_base64;

    #[test]
    fn base64_check_matches_the_pattern() {
        const PIECES: [&[u8]; 10] = [b"", b"a", b"Z", b"0", b"+", b"/", b"=", b"\n", b"-", b"\xff"];
        let mut seed = 0x9e37_79b9_7f4a_7c15_u64;
        for _ in 0..50_000 {
            seed ^= seed << 13;
            seed ^= seed >> 7;
            seed ^= seed << 17;
            let value: Vec<u8> =
                (0..seed % 7).flat_map(|i| PIECES[((seed >> (i * 4)) % 10) as usize]).copied().collect();
            let php =
                matches!(preg_match_bare(br"/^[A-Za-z0-9+\/]+={0,2}$/", &value), Ok(p) if p.value == Value::Int(1));
            assert_eq!(is_base64(&value), php, "{value:?}");
        }
    }
}
