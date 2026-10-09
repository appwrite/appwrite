use std::time::{SystemTime, UNIX_EPOCH};

use argon2::password_hash::{PasswordHash, PasswordHasher, PasswordVerifier, SaltString};
use base64::Engine;
use md5::Digest as _;
use serde_json::{Map, Value, json};

use crate::{hash_equals, phpass, random_bytes};

/// Errors raised while configuring or running a hash.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum HashError {
    /// An invalid option (PHP `InvalidArgumentException` with this message).
    InvalidArgument(String),
    /// The stored hash type is unknown (`Unsupported hash type: X`).
    Unsupported(String),
    /// The underlying primitive failed.
    Failure(String),
}

impl std::fmt::Display for HashError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            HashError::InvalidArgument(m) | HashError::Failure(m) => f.write_str(m),
            HashError::Unsupported(t) => write!(f, "Unsupported hash type: {t}"),
        }
    }
}

impl std::error::Error for HashError {}

pub const SHA_VERSIONS: [&str; 9] =
    ["sha1", "sha224", "sha256", "sha384", "sha512", "sha3-224", "sha3-256", "sha3-384", "sha3-512"];

/// A configured hashing algorithm.
#[derive(Debug, Clone, PartialEq)]
pub enum Hash {
    Argon2 { memory_cost: u32, time_cost: u32, threads: u32 },
    Bcrypt { cost: u32 },
    Md5,
    Sha { version: String },
    PHPass { iteration_count_log2: u32, portable_hashes: bool, random_state: String },
    Scrypt { cost_cpu: u64, cost_memory: u32, cost_parallel: u32, length: usize, salt: String },
    ScryptModified { salt: String, salt_separator: String, signer_key: String },
    Plaintext,
}

fn is_base64(s: &str) -> bool {
    // /^[A-Za-z0-9+\/]+={0,2}$/ (PHP `$` also matches before a final "\n").
    let s = s.strip_suffix('\n').unwrap_or(s);
    let body = s.trim_end_matches('=');
    let padding = s.len() - body.len();
    !body.is_empty() && padding <= 2 && body.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'+' || b == b'/')
}

fn microtime_state() -> String {
    let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default();
    format!("0.{:06}00 {}{}", now.subsec_micros(), now.as_secs(), std::process::id())
}

fn b64() -> base64::engine::GeneralPurpose {
    base64::engine::general_purpose::STANDARD
}

impl Hash {
    /// `new Argon2()` defaults (used by the pre-hashed import endpoint).
    pub fn argon2_default() -> Self {
        Hash::Argon2 { memory_cost: 65536, time_cost: 4, threads: 3 }
    }

    /// `new Bcrypt()->setCost(8)`.
    pub fn bcrypt_default() -> Self {
        Hash::Bcrypt { cost: 8 }
    }

    /// `new Sha()` with an optional `setVersion`.
    pub fn sha(version: Option<&str>) -> Result<Self, HashError> {
        match version {
            None | Some("") => Ok(Hash::Sha { version: "sha256".to_owned() }),
            Some(v) if SHA_VERSIONS.contains(&v) => Ok(Hash::Sha { version: v.to_owned() }),
            Some(_) => Err(HashError::InvalidArgument(format!(
                "Invalid SHA version. Valid versions are: {}",
                SHA_VERSIONS.join(", ")
            ))),
        }
    }

    /// `new PHPass()`.
    pub fn phpass_default() -> Self {
        Hash::PHPass { iteration_count_log2: 8, portable_hashes: false, random_state: microtime_state() }
    }

    /// `new Scrypt()` followed by the setters, with their validation messages.
    pub fn scrypt(salt: &str, cpu: i64, memory: i64, parallel: i64, length: i64) -> Result<Self, HashError> {
        if salt.is_empty() || salt == "0" {
            return Err(HashError::InvalidArgument("Salt cannot be empty".into()));
        }
        if cpu <= 1 || (cpu & (cpu - 1)) != 0 {
            return Err(HashError::InvalidArgument("CPU cost must be > 1 and a power of 2".into()));
        }
        if memory < 1 {
            return Err(HashError::InvalidArgument("Memory cost must be >= 1".into()));
        }
        if parallel < 1 {
            return Err(HashError::InvalidArgument("Parallel cost must be >= 1".into()));
        }
        if length < 16 {
            return Err(HashError::InvalidArgument("Length must be >= 16 bytes".into()));
        }
        Ok(Hash::Scrypt {
            cost_cpu: cpu as u64,
            cost_memory: memory as u32,
            cost_parallel: parallel as u32,
            length: length as usize,
            salt: salt.to_owned(),
        })
    }

    /// `new ScryptModified()->setSalt()->setSaltSeparator()->setSignerKey()`.
    pub fn scrypt_modified(salt: &str, separator: &str, signer_key: &str) -> Result<Self, HashError> {
        if salt.is_empty() || salt == "0" {
            return Err(HashError::InvalidArgument("Salt cannot be empty".into()));
        }
        if !is_base64(salt) {
            return Err(HashError::InvalidArgument("Salt must be base64 encoded".into()));
        }
        if !is_base64(separator) {
            return Err(HashError::InvalidArgument("Salt separator must be base64 encoded".into()));
        }
        if signer_key.is_empty() || signer_key == "0" {
            return Err(HashError::InvalidArgument("Signer key cannot be empty".into()));
        }
        if !is_base64(signer_key) {
            return Err(HashError::InvalidArgument("Signer key must be base64 encoded".into()));
        }
        Ok(Hash::ScryptModified {
            salt: salt.to_owned(),
            salt_separator: separator.to_owned(),
            signer_key: signer_key.to_owned(),
        })
    }

    /// `Proofs\Password::createHash($type, $options)`: rebuilds the stored algorithm.
    pub fn from_stored(kind: &str, options: &Value) -> Result<Self, HashError> {
        let get = |k: &str| options.get(k);
        let int = |k: &str, d: i64| {
            get(k).and_then(|v| v.as_i64().or_else(|| v.as_str().and_then(|s| s.parse().ok()))).unwrap_or(d)
        };
        let string =
            |k: &str, d: &str| get(k).and_then(Value::as_str).map(str::to_owned).unwrap_or_else(|| d.to_owned());
        match kind {
            "argon2" => Ok(Hash::Argon2 {
                memory_cost: int("memory_cost", 65536) as u32,
                time_cost: int("time_cost", 4) as u32,
                threads: int("threads", 3) as u32,
            }),
            "bcrypt" => Ok(Hash::Bcrypt { cost: int("cost", 8) as u32 }),
            "md5" => Ok(Hash::Md5),
            "sha" => Ok(Hash::Sha { version: string("version", "sha256") }),
            "phpass" => Ok(Hash::PHPass {
                iteration_count_log2: int("iteration_count_log2", 8) as u32,
                portable_hashes: get("portable_hashes").and_then(Value::as_bool).unwrap_or(false),
                random_state: string("random_state", ""),
            }),
            "scrypt" => Ok(Hash::Scrypt {
                cost_cpu: int("costCpu", 8) as u64,
                cost_memory: int("costMemory", 14) as u32,
                cost_parallel: int("costParallel", 1) as u32,
                length: int("length", 64) as usize,
                salt: string("salt", ""),
            }),
            "scryptMod" => Ok(Hash::ScryptModified {
                salt: string("salt", ""),
                salt_separator: string("saltSeparator", ""),
                signer_key: string("signerKey", ""),
            }),
            other => Err(HashError::Unsupported(other.to_owned())),
        }
    }

    /// Stored `hash` attribute value.
    pub fn name(&self) -> &'static str {
        match self {
            Hash::Argon2 { .. } => "argon2",
            Hash::Bcrypt { .. } => "bcrypt",
            Hash::Md5 => "md5",
            Hash::Sha { .. } => "sha",
            Hash::PHPass { .. } => "phpass",
            Hash::Scrypt { .. } => "scrypt",
            Hash::ScryptModified { .. } => "scryptMod",
            Hash::Plaintext => "plaintext",
        }
    }

    /// Stored `hashOptions` document, keys in PHP insertion order.
    pub fn options(&self) -> Value {
        let mut map = Map::new();
        match self {
            Hash::Argon2 { memory_cost, time_cost, threads } => {
                map.insert("type".into(), json!("argon2"));
                map.insert("memory_cost".into(), json!(memory_cost));
                map.insert("time_cost".into(), json!(time_cost));
                map.insert("threads".into(), json!(threads));
            }
            Hash::Bcrypt { cost } => {
                map.insert("type".into(), json!("bcrypt"));
                map.insert("cost".into(), json!(cost));
            }
            Hash::Md5 => {
                map.insert("type".into(), json!("md5"));
            }
            Hash::Sha { version } => {
                map.insert("version".into(), json!(version));
            }
            Hash::PHPass { iteration_count_log2, portable_hashes, random_state } => {
                map.insert("type".into(), json!("phpass"));
                map.insert("iteration_count_log2".into(), json!(iteration_count_log2));
                map.insert("portable_hashes".into(), json!(portable_hashes));
                map.insert("random_state".into(), json!(random_state));
            }
            Hash::Scrypt { cost_cpu, cost_memory, cost_parallel, length, salt } => {
                map.insert("type".into(), json!("scrypt"));
                map.insert("costCpu".into(), json!(cost_cpu));
                map.insert("costMemory".into(), json!(cost_memory));
                map.insert("costParallel".into(), json!(cost_parallel));
                map.insert("length".into(), json!(length));
                map.insert("salt".into(), json!(salt));
            }
            Hash::ScryptModified { salt, salt_separator, signer_key } => {
                map.insert("type".into(), json!("scryptMod"));
                map.insert("salt".into(), json!(salt));
                map.insert("saltSeparator".into(), json!(salt_separator));
                map.insert("signerKey".into(), json!(signer_key));
            }
            Hash::Plaintext => {
                map.insert("type".into(), json!("plaintext"));
            }
        }
        Value::Object(map)
    }

    /// Hashes `value`.
    pub fn hash(&self, value: &str) -> Result<String, HashError> {
        match self {
            Hash::Argon2 { memory_cost, time_cost, threads } => {
                let params = argon2::Params::new(*memory_cost, *time_cost, *threads, Some(32))
                    .map_err(|e| HashError::Failure(e.to_string()))?;
                let argon = argon2::Argon2::new(argon2::Algorithm::Argon2id, argon2::Version::V0x13, params);
                let mut salt = [0u8; 16];
                random_bytes(&mut salt);
                let salt = SaltString::encode_b64(&salt).map_err(|e| HashError::Failure(e.to_string()))?;
                argon
                    .hash_password(value.as_bytes(), &salt)
                    .map(|h| h.to_string())
                    .map_err(|e| HashError::Failure(e.to_string()))
            }
            Hash::Bcrypt { cost } => bcrypt::hash_with_result(value, *cost)
                .map(|h| h.format_for_version(bcrypt::Version::TwoY))
                .map_err(|e| HashError::Failure(e.to_string())),
            Hash::Md5 => Ok(hex::encode(md5::Md5::digest(value.as_bytes()))),
            Hash::Sha { version } => sha_hex(version, value.as_bytes()),
            Hash::PHPass { iteration_count_log2, portable_hashes, .. } => {
                Ok(phpass::hash(value, *iteration_count_log2, *portable_hashes))
            }
            Hash::Scrypt { cost_cpu, cost_memory, cost_parallel, length, salt } => {
                scrypt_hex(value.as_bytes(), salt.as_bytes(), *cost_cpu, *cost_memory, *cost_parallel, *length)
            }
            Hash::ScryptModified { salt, salt_separator, signer_key } => {
                scrypt_modified(value, salt, salt_separator, signer_key)
            }
            Hash::Plaintext => Ok(value.to_owned()),
        }
    }

    /// Verifies `value` against a stored `hash`.
    pub fn verify(&self, value: &str, hash: &str) -> bool {
        match self {
            Hash::Argon2 { .. } => match PasswordHash::new(hash) {
                Ok(parsed) => argon2::Argon2::default().verify_password(value.as_bytes(), &parsed).is_ok(),
                Err(_) => false,
            },
            Hash::Bcrypt { .. } => bcrypt::verify(value, hash).unwrap_or(false),
            Hash::PHPass { .. } => phpass::verify(value, hash),
            _ => match self.hash(value) {
                Ok(computed) => hash_equals(hash, &computed),
                Err(_) => false,
            },
        }
    }
}

fn sha_hex(version: &str, data: &[u8]) -> Result<String, HashError> {
    Ok(match version {
        "sha1" => hex::encode(sha1::Sha1::digest(data)),
        "sha224" => hex::encode(sha2::Sha224::digest(data)),
        "sha256" => hex::encode(sha2::Sha256::digest(data)),
        "sha384" => hex::encode(sha2::Sha384::digest(data)),
        "sha512" => hex::encode(sha2::Sha512::digest(data)),
        "sha512/224" => hex::encode(sha2::Sha512_224::digest(data)),
        "sha512/256" => hex::encode(sha2::Sha512_256::digest(data)),
        "sha3-224" => hex::encode(sha3::Sha3_224::digest(data)),
        "sha3-256" => hex::encode(sha3::Sha3_256::digest(data)),
        "sha3-384" => hex::encode(sha3::Sha3_384::digest(data)),
        "sha3-512" => hex::encode(sha3::Sha3_512::digest(data)),
        other => return Err(HashError::Failure(format!("Unsupported SHA version: {other}"))),
    })
}

/// php-scrypt `scrypt($password, $salt, $N, $r, $p, $keyLength)` (hex output).
fn scrypt_hex(password: &[u8], salt: &[u8], n: u64, r: u32, p: u32, length: usize) -> Result<String, HashError> {
    if n < 2 || !n.is_power_of_two() {
        return Err(HashError::Failure("N must be a power of 2 greater than 1".into()));
    }
    let log_n = n.trailing_zeros() as u8;
    let params = scrypt::Params::new(log_n, r, p, length).map_err(|e| HashError::Failure(e.to_string()))?;
    let mut out = vec![0u8; length];
    scrypt::scrypt(password, salt, &params, &mut out).map_err(|e| HashError::Failure(e.to_string()))?;
    Ok(hex::encode(out))
}

/// Firebase's modified scrypt.
fn scrypt_modified(value: &str, salt: &str, separator: &str, signer_key: &str) -> Result<String, HashError> {
    use aes::cipher::{KeyIvInit, StreamCipher};
    let decode = |s: &str| b64().decode(s.trim_end_matches('\n')).unwrap_or_default();
    let mut full_salt = decode(salt);
    full_salt.extend_from_slice(&decode(separator));
    let params = scrypt::Params::new(14, 8, 1, 64).map_err(|e| HashError::Failure(e.to_string()))?;
    let mut derived = [0u8; 64];
    scrypt::scrypt(value.as_bytes(), &full_salt, &params, &mut derived)
        .map_err(|e| HashError::Failure(e.to_string()))?;
    let mut data = decode(signer_key);
    let iv = [0u8; 16];
    let mut cipher = ctr::Ctr128BE::<aes::Aes256>::new((&derived[..32]).into(), (&iv).into());
    cipher.apply_keystream(&mut data);
    Ok(b64().encode(data))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn options_documents() {
        assert_eq!(
            serde_json::to_string(&Hash::Argon2 { memory_cost: 7168, time_cost: 5, threads: 1 }.options()).unwrap(),
            r#"{"type":"argon2","memory_cost":7168,"time_cost":5,"threads":1}"#
        );
        assert_eq!(
            serde_json::to_string(&Hash::sha(Some("sha512")).unwrap().options()).unwrap(),
            r#"{"version":"sha512"}"#
        );
        assert_eq!(serde_json::to_string(&Hash::bcrypt_default().options()).unwrap(), r#"{"type":"bcrypt","cost":8}"#);
    }

    #[test]
    fn argon2_round_trip() {
        let h = Hash::Argon2 { memory_cost: 1024, time_cost: 1, threads: 1 };
        let hashed = h.hash("password").unwrap();
        assert!(hashed.starts_with("$argon2id$v=19$m=1024,t=1,p=1$"), "{hashed}");
        assert!(h.verify("password", &hashed));
        assert!(!h.verify("nope", &hashed));
    }

    #[test]
    fn php_fixtures() {
        // Fixtures from tests/e2e/Services/Users/UsersBase.php (password "appwrite").
        assert!(Hash::Md5.verify("appwrite", "144fa7eaa4904e8ee120651997f70dcc"));
        assert!(
            Hash::bcrypt_default().verify("appwrite", "$2a$15$xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxBLXmOPHrYMwV0mHRBBSBOq")
        );
        assert!(
            Hash::Argon2 { memory_cost: 0, time_cost: 0, threads: 0 }.verify(
                "appwrite",
                "$argon2i$v=19$m=20,t=3,p=2$YXBwd3JpdGU$A/54i238ed09ZR4NwlACU5XnkjNBZU9QeOEuhjLiexI"
            )
        );
        assert!(Hash::argon2_default().verify(
            "appwrite",
            "$argon2id$v=19$m=65536,t=4,p=3$azFVSGhPaXVBYnZMblNRaw$Pzm3TowCIbab0S5GL2id3OhZMexfFvutVSDoZ8D/Z8o"
        ));
        assert!(Hash::sha(Some("sha512")).unwrap().verify(
            "appwrite",
            "4243da0a694e8a2f727c8060fe0507c8fa01ca68146c76d2c190805b638c20c6bf6ba04e21f11ae138785d0bff63c416e6f87badbffad37f6dee50094cc38c70"
        ));
        assert!(Hash::phpass_default().verify("appwrite", "$P$Br387rwferoKN7uwHZqNMu98q3U8RO."));
        let scrypt = Hash::scrypt("appwrite", 16384, 13, 2, 64).unwrap();
        assert!(scrypt.verify(
            "appwrite",
            "3fdef49701bc4cfaacd551fe017283513284b4731e6945c263246ef948d3cf63b5d269c31fd697246085111a428245e24a4ddc6b64c687bc60a8910dbafc1d5b"
        ));
        let modified = Hash::scrypt_modified(
            "UxLMreBr6tYyjQ==",
            "Bw==",
            "XyEKE9RcTDeLEsL/RjwPDBv/RqDl8fb3gpYEOQaPihbxf1ZAtSOHCjuAAa7Q3oHpCYhXSN9tizHgVOwn6krflQ==",
        )
        .unwrap();
        assert!(modified.verify(
            "appwrite",
            "UlM7JiXRcQhzAGlaonpSqNSLIz475WMddOgLjej5De9vxTy48K6WtqlEzrRFeK4t0COfMhWCb8wuMHgxOFCHFQ=="
        ));
    }

    #[test]
    fn scrypt_modified_validation() {
        assert_eq!(
            Hash::scrypt_modified("0", "Bw==", "a").unwrap_err(),
            HashError::InvalidArgument("Salt cannot be empty".into())
        );
        assert_eq!(
            Hash::scrypt_modified("not base64!", "Bw==", "a").unwrap_err(),
            HashError::InvalidArgument("Salt must be base64 encoded".into())
        );
    }
}
