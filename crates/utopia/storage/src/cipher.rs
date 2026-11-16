//! Segmented AES-256-GCM: a file is split into segments of [`SEGMENT`]
//! bytes, each sealed on its own with a fresh nonce, so any range of the file
//! can be read and decrypted without the rest of it. Each segment's
//! additional data binds its index and whether it is the last one, so
//! segments cannot be reordered or the file truncated unnoticed.
//!
//! A stored segment is `nonce (12) | ciphertext | tag (16)`.
//!
//! PHP: `Utopia\Storage\Cipher`.

use aes_gcm::aead::{Aead, KeyInit, Payload};
use aes_gcm::{Aes256Gcm, Nonce};
use sha2::{Digest, Sha256};

use crate::error::{Error, Result};

/// The cipher's name, as stored with a file (`openSSLCipher`).
pub const NAME: &str = "aes-256-gcm-segmented";

/// Plaintext bytes per segment.
pub const SEGMENT: i64 = 65536;

const NONCE: usize = 12;
const TAG: usize = 16;

/// Bytes each segment adds: its nonce and its tag.
pub const OVERHEAD: i64 = (NONCE + TAG) as i64;

const VERSION: u8 = 1;

/// A file key.
#[derive(Clone)]
pub struct Cipher {
    key: [u8; 32],
}

impl std::fmt::Debug for Cipher {
    /// Never prints the key.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("Cipher")
    }
}

fn random<const N: usize>() -> [u8; N] {
    let mut bytes = [0u8; N];
    getrandom::fill(&mut bytes).expect("the system random source is available");
    bytes
}

/// `hash('sha256', $master, true)`: the key a master secret wraps with.
fn master(master: &[u8]) -> Result<[u8; 32]> {
    if master.is_empty() {
        return Err(Error::Storage("Master key must not be empty".into()));
    }
    Ok(Sha256::digest(master).into())
}

impl Cipher {
    /// A cipher for a 32-byte key.
    pub fn new(key: &[u8]) -> Result<Cipher> {
        let key: [u8; 32] = key.try_into().map_err(|_| Error::Storage("Key must be 32 bytes".into()))?;
        Ok(Cipher { key })
    }

    /// A cipher with a new random key.
    pub fn generate() -> Cipher {
        Cipher { key: random() }
    }

    /// The key `wrapped` holds, sealed with `master` by [`Cipher::wrap`].
    pub fn unwrap(wrapped: &[u8], master_key: &[u8]) -> Result<Cipher> {
        let failed = || Error::Storage("Unable to unwrap the key".into());
        if wrapped.len() != NONCE + 32 + TAG {
            return Err(failed());
        }
        let aes = Aes256Gcm::new(&master(master_key)?.into());
        let key = aes
            .decrypt(Nonce::from_slice(&wrapped[..NONCE]), Payload { msg: &wrapped[NONCE..], aad: NAME.as_bytes() })
            .map_err(|_| failed())?;
        Cipher::new(&key)
    }

    /// The key sealed with the SHA-256 of `master`: `nonce | ciphertext | tag`.
    pub fn wrap(&self, master_key: &[u8]) -> Result<Vec<u8>> {
        let aes = Aes256Gcm::new(&master(master_key)?.into());
        let nonce: [u8; NONCE] = random();
        let sealed = aes
            .encrypt(Nonce::from_slice(&nonce), Payload { msg: &self.key, aad: NAME.as_bytes() })
            .map_err(|_| Error::Storage("Failed to wrap the key".into()))?;
        let mut out = nonce.to_vec();
        out.extend_from_slice(&sealed);
        Ok(out)
    }

    /// Seals `data`, which starts at segment `index` of the file; `last` when
    /// it ends the file. Every segment but the last must be full.
    pub fn encrypt(&self, data: &[u8], index: i64, last: bool) -> Result<Vec<u8>> {
        let aes = Aes256Gcm::new(&self.key.into());
        let count = segments(data.len() as i64);
        let mut output = Vec::with_capacity(data.len() + (count * OVERHEAD) as usize);
        for segment in 0..count {
            let start = ((segment * SEGMENT) as usize).min(data.len());
            let end = (start + SEGMENT as usize).min(data.len());
            let nonce: [u8; NONCE] = random();
            let aad = aad(index + segment, last && segment == count - 1);
            let sealed = aes
                .encrypt(Nonce::from_slice(&nonce), Payload { msg: &data[start..end], aad: &aad })
                .map_err(|_| Error::Storage(format!("Failed to encrypt segment {}", index + segment)))?;
            output.extend_from_slice(&nonce);
            output.extend_from_slice(&sealed);
        }
        Ok(output)
    }

    /// Opens the segments in `data`, the first being segment `index` of the
    /// file; `last` when they end the file.
    pub fn decrypt(&self, data: &[u8], index: i64, last: bool) -> Result<Vec<u8>> {
        let aes = Aes256Gcm::new(&self.key.into());
        let size = (SEGMENT + OVERHEAD) as usize;
        let count = (data.len().div_ceil(size)).max(1);
        let mut output = Vec::with_capacity(data.len());
        for segment in 0..count {
            let start = (segment * size).min(data.len());
            let end = (start + size).min(data.len());
            let block = &data[start..end];
            let final_ = segment == count - 1;
            let at = index + segment as i64;
            if block.len() < OVERHEAD as usize || ((!final_ || !last) && block.len() != size) {
                return Err(Error::Storage(format!("Segment {at} is truncated")));
            }
            let aad = aad(at, last && final_);
            let opened = aes
                .decrypt(Nonce::from_slice(&block[..NONCE]), Payload { msg: &block[NONCE..], aad: &aad })
                .map_err(|_| Error::Storage(format!("Segment {at} failed authentication")))?;
            output.extend_from_slice(&opened);
        }
        Ok(output)
    }
}

/// `pack('CJC', VERSION, $index, $last)`: the version, the segment index
/// (unsigned 64-bit big-endian) and whether it is the last segment.
fn aad(index: i64, last: bool) -> [u8; 10] {
    let mut out = [0u8; 10];
    out[0] = VERSION;
    out[1..9].copy_from_slice(&(index as u64).to_be_bytes());
    out[9] = u8::from(last);
    out
}

/// How many segments a file of `size` bytes takes (at least one).
pub fn segments(size: i64) -> i64 {
    ((size + SEGMENT - 1) / SEGMENT).max(1)
}

/// The stored size of a file of `size` bytes.
pub fn size(size: i64) -> i64 {
    size + segments(size) * OVERHEAD
}

/// Where segment `index` starts in the stored file.
pub fn offset(index: i64) -> i64 {
    index * (SEGMENT + OVERHEAD)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn round_trips_across_segments() {
        let cipher = Cipher::generate();
        let data: Vec<u8> = (0..(SEGMENT as usize * 2 + 5)).map(|i| (i % 251) as u8).collect();
        let sealed = cipher.encrypt(&data, 0, true).unwrap();
        assert_eq!(sealed.len() as i64, size(data.len() as i64));
        assert_eq!(cipher.decrypt(&sealed, 0, true).unwrap(), data);
        // The middle segment alone, by its index.
        let start = offset(1) as usize;
        let middle = &sealed[start..start + (SEGMENT + OVERHEAD) as usize];
        assert_eq!(cipher.decrypt(middle, 1, false).unwrap(), &data[SEGMENT as usize..2 * SEGMENT as usize]);
        // Out of place, or read as the last, it fails.
        assert!(cipher.decrypt(middle, 0, false).is_err());
        assert!(cipher.decrypt(middle, 1, true).is_err());
    }

    #[test]
    fn an_empty_file_is_one_segment() {
        let cipher = Cipher::generate();
        let sealed = cipher.encrypt(b"", 0, true).unwrap();
        assert_eq!(sealed.len() as i64, OVERHEAD);
        assert_eq!(cipher.decrypt(&sealed, 0, true).unwrap(), b"");
    }

    #[test]
    fn keys_wrap_with_the_master() {
        let cipher = Cipher::generate();
        let wrapped = cipher.wrap(b"secret").unwrap();
        assert_eq!(wrapped.len(), NONCE + 32 + TAG);
        let unwrapped = Cipher::unwrap(&wrapped, b"secret").unwrap();
        let sealed = cipher.encrypt(b"hello", 0, true).unwrap();
        assert_eq!(unwrapped.decrypt(&sealed, 0, true).unwrap(), b"hello");
        assert!(Cipher::unwrap(&wrapped, b"other").is_err());
        assert!(cipher.wrap(b"").is_err());
    }

    /// A key wrapped and a segment sealed by PHP's `Cipher`
    /// (`wrap('your-secret-key')`, `encrypt('hello segmented world', 3, true)`).
    #[test]
    fn opens_what_php_seals() {
        use base64::Engine as _;
        let b64 = |s: &str| base64::engine::general_purpose::STANDARD.decode(s).unwrap();
        let wrapped = b64("NubhfyBOuiSVA5Korf9qFjiw73P+5iADs0Ha1VIKhLd+Xnl7L0b3gG2FC+sRvk6VWGBgDHMWhRXRHz6R");
        let sealed = b64("iwUkpYZwIUH3M1qe4OgyjNDma4BsOQiCOsAFiNwG5l8uS86h7AJJmTX9amTfdi7fuw==");
        let cipher = Cipher::unwrap(&wrapped, b"your-secret-key").unwrap();
        assert_eq!(cipher.decrypt(&sealed, 3, true).unwrap(), b"hello segmented world");
        assert!(cipher.decrypt(&sealed, 2, true).is_err());
    }

    #[test]
    fn sizes_as_php() {
        assert_eq!(segments(0), 1);
        assert_eq!(segments(SEGMENT), 1);
        assert_eq!(segments(SEGMENT + 1), 2);
        assert_eq!(size(10), 10 + OVERHEAD);
        assert_eq!(offset(2), 2 * (SEGMENT + OVERHEAD));
    }
}
