//! Authenticators (PHP `Utopia\NATS\Auth`).

use crate::error::{AuthenticationException, NatsError};
use ed25519_dalek::{Signer, SigningKey};
use serde_json::{json, Map, Value};
use std::fs;

pub trait Authenticator: Send + Sync {
    fn authenticate(&self, nonce: Option<&str>) -> Result<Map<String, Value>, NatsError>;
}

#[derive(Debug, Clone, Copy, Default)]
pub struct NoAuth;

impl Authenticator for NoAuth {
    fn authenticate(&self, _nonce: Option<&str>) -> Result<Map<String, Value>, NatsError> {
        Ok(Map::new())
    }
}

#[derive(Debug, Clone)]
pub struct TokenAuth {
    token: String,
}

impl TokenAuth {
    pub fn new(token: impl Into<String>) -> Self {
        Self {
            token: token.into(),
        }
    }
}

impl Authenticator for TokenAuth {
    fn authenticate(&self, _nonce: Option<&str>) -> Result<Map<String, Value>, NatsError> {
        let mut m = Map::new();
        m.insert("auth_token".into(), Value::String(self.token.clone()));
        Ok(m)
    }
}

#[derive(Debug, Clone)]
pub struct UserPassAuth {
    user: String,
    pass: String,
}

impl UserPassAuth {
    pub fn new(user: impl Into<String>, pass: impl Into<String>) -> Self {
        Self {
            user: user.into(),
            pass: pass.into(),
        }
    }
}

impl Authenticator for UserPassAuth {
    fn authenticate(&self, _nonce: Option<&str>) -> Result<Map<String, Value>, NatsError> {
        let mut m = Map::new();
        m.insert("user".into(), Value::String(self.user.clone()));
        m.insert("pass".into(), Value::String(self.pass.clone()));
        Ok(m)
    }
}

const B32: &[u8] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

#[derive(Debug, Clone)]
pub struct NKeyAuth {
    public_key: String,
    seed: String,
}

impl NKeyAuth {
    pub fn new(public_key: impl Into<String>, seed: impl Into<String>) -> Self {
        Self {
            public_key: public_key.into(),
            seed: seed.into(),
        }
    }

    pub fn public_key(&self) -> Result<String, NatsError> {
        if !self.public_key.is_empty() {
            return Ok(self.public_key.clone());
        }
        self.derive_public_key()
    }

    fn derive_public_key(&self) -> Result<String, NatsError> {
        let decoded = base32_decode(&self.seed)?;
        if decoded.len() < 4 {
            return Err(AuthenticationException("Invalid NKey seed".into()).into());
        }
        let b1 = decoded[0];
        let b2 = decoded[1];
        let role = ((b1 & 7) << 5) | ((b2 >> 3) & 31);
        let raw_seed = &decoded[2..decoded.len() - 2];
        if raw_seed.len() != 32 {
            return Err(AuthenticationException("Invalid NKey seed".into()).into());
        }
        let mut seed = [0u8; 32];
        seed.copy_from_slice(raw_seed);
        let signing = SigningKey::from_bytes(&seed);
        let public = signing.verifying_key().to_bytes();
        Ok(encode_public_key(role, &public))
    }

    fn decode_seed(&self) -> Result<[u8; 32], NatsError> {
        let decoded = base32_decode(&self.seed)?;
        if decoded.len() < 4 {
            return Err(AuthenticationException("Invalid NKey seed".into()).into());
        }
        let raw = &decoded[2..decoded.len() - 2];
        if raw.len() != 32 {
            return Err(AuthenticationException("Invalid NKey seed".into()).into());
        }
        let mut seed = [0u8; 32];
        seed.copy_from_slice(raw);
        Ok(seed)
    }
}

impl Authenticator for NKeyAuth {
    fn authenticate(&self, nonce: Option<&str>) -> Result<Map<String, Value>, NatsError> {
        let nonce = nonce.ok_or_else(|| {
            AuthenticationException("NKey authentication requires a server nonce".to_owned())
        })?;
        let seed = self.decode_seed()?;
        let signing = SigningKey::from_bytes(&seed);
        let sig = signing.sign(nonce.as_bytes());
        let mut m = Map::new();
        m.insert("nkey".into(), Value::String(self.public_key()?));
        m.insert(
            "sig".into(),
            Value::String(base32_encode(sig.to_bytes().as_ref())),
        );
        Ok(m)
    }
}

fn encode_public_key(role: u8, public_key: &[u8; 32]) -> String {
    let mut raw = Vec::with_capacity(35);
    raw.push(role);
    raw.extend_from_slice(public_key);
    let crc = crc16(&raw);
    raw.push((crc & 0xff) as u8);
    raw.push((crc >> 8) as u8);
    base32_encode(&raw)
}

fn crc16(data: &[u8]) -> u16 {
    let mut crc: u16 = 0;
    for &b in data {
        crc ^= u16::from(b) << 8;
        for _ in 0..8 {
            crc = if crc & 0x8000 != 0 {
                (crc << 1) ^ 0x1021
            } else {
                crc << 1
            };
        }
    }
    crc
}

fn base32_decode(input: &str) -> Result<Vec<u8>, NatsError> {
    let input = input.trim_end_matches('=').to_ascii_uppercase();
    let mut output = Vec::new();
    let mut buffer: u32 = 0;
    let mut bits_left = 0i32;
    for ch in input.bytes() {
        let val = B32.iter().position(|&c| c == ch).ok_or_else(|| {
            AuthenticationException("Invalid base32 character in NKey seed".into())
        })?;
        buffer = (buffer << 5) | val as u32;
        bits_left += 5;
        if bits_left >= 8 {
            bits_left -= 8;
            output.push(((buffer >> bits_left) & 0xff) as u8);
        }
    }
    Ok(output)
}

fn base32_encode(input: &[u8]) -> String {
    let mut output = String::new();
    let mut buffer: u32 = 0;
    let mut bits_left = 0i32;
    for &b in input {
        buffer = (buffer << 8) | u32::from(b);
        bits_left += 8;
        while bits_left >= 5 {
            bits_left -= 5;
            let idx = ((buffer >> bits_left) & 0x1f) as usize;
            output.push(B32[idx] as char);
        }
    }
    if bits_left > 0 {
        let idx = ((buffer << (5 - bits_left)) & 0x1f) as usize;
        output.push(B32[idx] as char);
    }
    output
}

#[derive(Debug)]
pub struct CredentialsAuth {
    jwt: String,
    nkey: NKeyAuth,
}

impl CredentialsAuth {
    pub fn new(credentials_file: &str) -> Result<Self, NatsError> {
        let contents = fs::read_to_string(credentials_file).map_err(|_| {
            AuthenticationException(format!("Credentials file not found: {credentials_file}"))
        })?;
        let jwt = extract_between(
            &contents,
            "-----BEGIN NATS USER JWT-----",
            "------END NATS USER JWT------",
        )
        .ok_or_else(|| AuthenticationException("No JWT found in credentials file".into()))?;
        let seed = extract_between(
            &contents,
            "-----BEGIN USER NKEY SEED-----",
            "------END USER NKEY SEED------",
        )
        .ok_or_else(|| AuthenticationException("No NKey seed found in credentials file".into()))?;
        Ok(Self {
            jwt,
            nkey: NKeyAuth::new("", seed),
        })
    }
}

impl Authenticator for CredentialsAuth {
    fn authenticate(&self, nonce: Option<&str>) -> Result<Map<String, Value>, NatsError> {
        let nkey_fields = self.nkey.authenticate(nonce)?;
        let mut m = Map::new();
        m.insert("jwt".into(), json!(self.jwt));
        m.insert(
            "nkey".into(),
            nkey_fields.get("nkey").cloned().unwrap_or(json!("")),
        );
        m.insert(
            "sig".into(),
            nkey_fields.get("sig").cloned().unwrap_or(json!("")),
        );
        Ok(m)
    }
}

fn extract_between(content: &str, begin: &str, end: &str) -> Option<String> {
    let start = content.find(begin)? + begin.len();
    let end_pos = content[start..].find(end)? + start;
    Some(content[start..end_pos].trim().to_owned())
}
