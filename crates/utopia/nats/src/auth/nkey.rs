//! NKey (Ed25519 challenge-response) and JWT credentials files.

use ring::signature::{Ed25519KeyPair, KeyPair};
use serde_json::Value;

use super::{Authenticator, Fields};
use crate::error::{Error, Result};

const ALPHABET: &[u8; 32] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

/// Signs the server's nonce with an NKey seed: `{"nkey": public key,
/// "sig": base32 signature}`.
///
/// ```
/// use utopia_nats::auth::{Authenticator, NKeyAuth};
///
/// // A user seed (base32 of the seed prefix, 32 key bytes and a CRC).
/// let seed = "SUAKKTOKDASTBOY5NUJSZXWWEN5S5WI6H5ZB7SYZOELUJFGWJE6J2XGBOY";
/// let auth = NKeyAuth::new("", seed);
/// let fields = auth.authenticate(Some("nonce"))?;
/// assert_eq!(fields["nkey"], auth.public_key()?.as_str());
/// assert!(auth.public_key()?.starts_with('U'));
/// # Ok::<(), utopia_nats::Error>(())
/// ```
///
/// ```php
/// use Utopia\NATS\Auth\NKeyAuth;
///
/// $auth = new NKeyAuth('', 'SUAKKTOKDASTBOY5NUJSZXWWEN5S5WI6H5ZB7SYZOELUJFGWJE6J2XGBOY');
/// $fields = $auth->authenticate('nonce'); // ['nkey' => 'U...', 'sig' => '...']
/// echo $fields['nkey'] === $auth->publicKey() ? 'same' : 'different', "\n"; // same
/// ```
///
/// PHP: `Utopia\NATS\Auth\NKeyAuth` (sodium's Ed25519; here `ring`'s, the
/// same signatures).
#[derive(Debug, Clone)]
pub struct NKeyAuth {
    public_key: String,
    seed: String,
}

impl NKeyAuth {
    /// Authenticates with `seed`, announcing `public_key`, or the key
    /// derived from the seed when `public_key` is empty.
    ///
    /// PHP: `new NKeyAuth($publicKey, $seed)`.
    pub fn new(public_key: impl Into<String>, seed: impl Into<String>) -> Self {
        NKeyAuth { public_key: public_key.into(), seed: seed.into() }
    }

    /// The public NKey: the one given, or the seed's (its role prefix
    /// kept, `U` for a user).
    ///
    /// # Errors
    ///
    /// [`Error::Authentication`] for a seed that is not base32 or too
    /// short, [`Error::Sodium`] for one that does not hold 32 key bytes.
    ///
    /// PHP: `NKeyAuth::publicKey()`.
    pub fn public_key(&self) -> Result<String> {
        if !self.public_key.is_empty() {
            return Ok(self.public_key.clone());
        }
        let decoded = base32_decode(&self.seed)?;
        if decoded.len() < 4 {
            return Err(Error::Authentication("Invalid NKey seed".into()));
        }
        let role = ((decoded[0] & 7) << 5) | ((decoded[1] >> 3) & 31);
        let pair = key_pair(&decoded[2..decoded.len() - 2])?;
        let mut raw = Vec::with_capacity(35);
        raw.push(role);
        raw.extend_from_slice(pair.public_key().as_ref());
        let crc = crc16(&raw);
        raw.push((crc & 0xFF) as u8);
        raw.push((crc >> 8) as u8);
        Ok(base32_encode(&raw))
    }
}

impl Authenticator for NKeyAuth {
    /// # Errors
    ///
    /// [`Error::Authentication`] without a nonce, or for a bad seed (see
    /// [`NKeyAuth::public_key`]).
    fn authenticate(&self, nonce: Option<&str>) -> Result<Fields> {
        let Some(nonce) = nonce else {
            return Err(Error::Authentication("NKey authentication requires a server nonce".into()));
        };
        let decoded = base32_decode(&self.seed)?;
        if decoded.len() < 4 {
            return Err(Error::Authentication("Invalid NKey seed".into()));
        }
        let signature = key_pair(&decoded[2..decoded.len() - 2])?.sign(nonce.as_bytes());
        let mut fields = Fields::new();
        fields.insert("nkey".into(), Value::String(self.public_key()?));
        fields.insert("sig".into(), Value::String(base32_encode(signature.as_ref())));
        Ok(fields)
    }
}

/// A user JWT and NKey seed from a `.creds` file: `{"jwt", "nkey", "sig"}`.
///
/// PHP: `Utopia\NATS\Auth\CredentialsAuth`.
#[derive(Debug, Clone)]
pub struct CredentialsAuth {
    jwt: String,
    nkey: NKeyAuth,
}

impl CredentialsAuth {
    /// Reads the JWT and the seed between their `-----BEGIN ...-----` and
    /// `------END ...------` markers.
    ///
    /// # Errors
    ///
    /// [`Error::Authentication`] when the file is missing or unreadable, or
    /// lacks the JWT or the seed.
    ///
    /// PHP: `new CredentialsAuth($credentialsFile)`.
    pub async fn from_file(path: &str) -> Result<Self> {
        if utopia_async::fs::metadata(path).await.is_err() {
            return Err(Error::Authentication(format!("Credentials file not found: {path}")));
        }
        let contents = utopia_async::fs::read(path)
            .await
            .map_err(|_| Error::Authentication(format!("Failed to read credentials file: {path}")))?;
        Self::parse(&contents)
    }

    /// [`CredentialsAuth::from_file`] on the file's contents.
    ///
    /// # Errors
    ///
    /// [`Error::Authentication`] when the JWT or the seed is missing.
    pub fn parse(contents: &[u8]) -> Result<Self> {
        let jwt = between(contents, b"-----BEGIN NATS USER JWT-----", b"------END NATS USER JWT------");
        let seed = between(contents, b"-----BEGIN USER NKEY SEED-----", b"------END USER NKEY SEED------");
        let Some(jwt) = jwt else {
            return Err(Error::Authentication("No JWT found in credentials file".into()));
        };
        let Some(seed) = seed else {
            return Err(Error::Authentication("No NKey seed found in credentials file".into()));
        };
        Ok(CredentialsAuth { jwt, nkey: NKeyAuth::new("", seed) })
    }
}

impl Authenticator for CredentialsAuth {
    fn authenticate(&self, nonce: Option<&str>) -> Result<Fields> {
        let nkey = self.nkey.authenticate(nonce)?;
        let mut fields = Fields::new();
        fields.insert("jwt".into(), Value::String(self.jwt.clone()));
        fields.insert("nkey".into(), nkey.get("nkey").cloned().unwrap_or_else(|| Value::String(String::new())));
        fields.insert("sig".into(), nkey.get("sig").cloned().unwrap_or_else(|| Value::String(String::new())));
        Ok(fields)
    }
}

fn between(content: &[u8], begin: &[u8], end: &[u8]) -> Option<String> {
    let start = content.windows(begin.len()).position(|w| w == begin)? + begin.len();
    let stop = content[start..].windows(end.len()).position(|w| w == end)? + start;
    let trimmed = php_std::string::trim(&content[start..stop], b" \t\n\r\0\x0B");
    Some(String::from_utf8_lossy(trimmed).into_owned())
}

fn key_pair(seed: &[u8]) -> Result<Ed25519KeyPair> {
    if seed.len() != 32 {
        return Err(Error::Sodium(
            "sodium_crypto_sign_seed_keypair(): Argument #1 ($seed) must be SODIUM_CRYPTO_SIGN_SEEDBYTES bytes long"
                .into(),
        ));
    }
    Ed25519KeyPair::from_seed_unchecked(seed).map_err(|_| Error::Authentication("Invalid NKey seed".into()))
}

/// CRC-16/XMODEM (poly 0x1021, init 0), as NATS nkeys checksum keys.
fn crc16(data: &[u8]) -> u16 {
    let mut crc: u16 = 0;
    for &byte in data {
        crc ^= u16::from(byte) << 8;
        for _ in 0..8 {
            crc = if crc & 0x8000 != 0 { (crc << 1) ^ 0x1021 } else { crc << 1 };
        }
    }
    crc
}

/// RFC 4648 base32 without padding, upper case, trailing `=` ignored.
fn base32_decode(input: &str) -> Result<Vec<u8>> {
    let input = input.trim_end_matches('=');
    let mut output = Vec::with_capacity(input.len() * 5 / 8);
    let mut buffer: u64 = 0;
    let mut bits = 0;
    for &c in input.as_bytes() {
        let c = c.to_ascii_uppercase();
        let Some(value) = ALPHABET.iter().position(|&a| a == c) else {
            return Err(Error::Authentication("Invalid base32 character in NKey seed".into()));
        };
        buffer = (buffer << 5) | value as u64;
        bits += 5;
        if bits >= 8 {
            bits -= 8;
            output.push((buffer >> bits) as u8);
        }
    }
    Ok(output)
}

fn base32_encode(input: &[u8]) -> String {
    let mut output = String::with_capacity(input.len().div_ceil(5) * 8);
    let mut buffer: u64 = 0;
    let mut bits = 0;
    for &byte in input {
        buffer = (buffer << 8) | u64::from(byte);
        bits += 8;
        while bits >= 5 {
            bits -= 5;
            output.push(ALPHABET[((buffer >> bits) & 0x1F) as usize] as char);
        }
    }
    if bits > 0 {
        output.push(ALPHABET[((buffer << (5 - bits)) & 0x1F) as usize] as char);
    }
    output
}
