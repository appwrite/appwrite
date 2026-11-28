//! How a stored file is encrypted (PHP: `Appwrite\Storage\Encryption`).
//! Files written before segmented encryption keep one AES-128-GCM envelope
//! over the whole file. Segmented files have their own random key, wrapped by
//! `_APP_OPENSSL_KEY_V*` and kept in the file's internal metadata
//! (`metadata.encryption = {key, version}`).

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD;
use serde_json::{Value as Json, json};
use utopia_database::Document;
use utopia_storage::Cipher;
const NAME: &str = Cipher::NAME;
const OVERHEAD: i64 = Cipher::OVERHEAD;
const SEGMENT: i64 = Cipher::SEGMENT;

use appwrite_core::Result;
use appwrite_core::database::documents::Attributes;
use appwrite_core::error::{Error, ErrorType};

use crate::base;
use crate::device::Stored;

/// `EncryptionKey::get($version)`.
fn master(version: &str) -> String {
    appwrite_core::crypto::env_key(version).unwrap_or_default()
}

/// `Encryption::create()`: a new file key, wrapped with the current key, as
/// stored in the file's metadata.
pub fn create() -> Result<Json> {
    let version = appwrite_core::crypto::key_version();
    let key = master(&version);
    if key.is_empty() {
        return Err(Error::with_message(
            ErrorType::GeneralServerError,
            format!("Encryption key is not configured. Please configure _APP_OPENSSL_KEY_V{version} in .env file."),
        ));
    }
    let wrapped = Cipher::generate()
        .wrap(key.as_bytes())
        .map_err(|e| Error::with_message(ErrorType::GeneralServerError, e.to_string()))?;
    Ok(json!({"key": STANDARD.encode(wrapped), "version": version}))
}

/// `Encryption::cipher($envelope)`: the file key an envelope holds.
pub fn cipher(envelope: &Json) -> Result<Cipher> {
    let version = match envelope.get("version") {
        Some(Json::String(v)) => v.clone(),
        Some(Json::Number(n)) => n.to_string(),
        _ => String::new(),
    };
    let wrapped = envelope.get("key").and_then(Json::as_str).and_then(|k| STANDARD.decode(k).ok()).unwrap_or_default();
    let key = master(&version);
    if !wrapped.is_empty()
        && !key.is_empty()
        && let Ok(cipher) = Cipher::unwrap(&wrapped, key.as_bytes())
    {
        return Ok(cipher);
    }
    Err(Error::with_message(
        ErrorType::GeneralServerError,
        format!("Unable to unwrap the file key. Please check _APP_OPENSSL_KEY_V{version} in .env file."),
    ))
}

/// The envelope in a file's metadata.
pub fn envelope(file: &Document) -> Option<Json> {
    file.json("metadata").and_then(|m| m.get("encryption").cloned()).filter(|e| !e.is_null())
}

fn open(file: &Document) -> Result<Cipher> {
    match envelope(file) {
        Some(envelope) => cipher(&envelope),
        None => {
            Err(Error::with_message(ErrorType::GeneralServerError, format!("File {} has no encryption key", file.id())))
        }
    }
}

/// `isSegmented()`: segmented files stored without compression, which can
/// be read by range.
pub fn is_segmented(file: &Document) -> bool {
    file.string_or_empty("openSSLCipher") == NAME && matches!(file.string_or_empty("algorithm").as_str(), "" | "none")
}

/// `decrypt()`: the whole stored file, in either format.
pub fn decrypt(file: &Document, data: &[u8]) -> Result<Vec<u8>> {
    if file.string_or_empty("openSSLCipher") == NAME {
        return open(file)?
            .decrypt(data, 0, true)
            .map_err(|e| Error::with_message(ErrorType::GeneralServerError, e.to_string()));
    }
    let iv = hex::decode(file.string_or_empty("openSSLIV")).unwrap_or_default();
    let tag = hex::decode(file.string_or_empty("openSSLTag")).unwrap_or_default();
    let key = master(&file.string_or_empty("openSSLVersion"));
    appwrite_core::crypto::open_encoded(&key, data, &iv, &tag)
        // Raw ciphertext, as early Rust builds of this service wrote it.
        .or_else(|| appwrite_core::crypto::open(&key, data, &iv, &tag))
        .ok_or_else(|| {
            Error::with_message(ErrorType::GeneralServerError, format!("Failed to decrypt file {}", file.id()))
        })
}

/// `range()`: bytes `start..=end` of a segmented file's plaintext, read
/// segment by segment.
pub async fn range(device: &Stored, file: &Document, start: i64, end: i64) -> Result<Vec<u8>> {
    let cipher = open(file)?;
    let size = base::long(file, "sizeOriginal");
    let first = start / SEGMENT;
    let last = end / SEGMENT;
    let offset = Cipher::offset(first);
    let length = Cipher::offset(last - first + 1);
    if offset < 0 || length <= 0 {
        return Err(Error::with_message(ErrorType::GeneralServerError, format!("Invalid range {start}-{end}")));
    }
    let path = file.string_or_empty("path");
    let body = device.read(&path, offset as u64, Some(length as u64)).await?;
    let data = body.bytes().await.map_err(|e| Error::with_message(ErrorType::GeneralServerError, e.to_string()))?;
    let plain = cipher
        .decrypt(&data, first, last == Cipher::segments(size) - 1)
        .map_err(|e| Error::with_message(ErrorType::GeneralServerError, e.to_string()))?;
    let from = usize::try_from(start - first * SEGMENT).unwrap_or(0).min(plain.len());
    let to = (from + usize::try_from(end - start + 1).unwrap_or(0)).min(plain.len());
    Ok(plain[from..to].to_vec())
}

/// `stream()`: a segmented file's plaintext, decrypted as it is read. The
/// final segment waits for the end of the file, where it is checked as the
/// last one.
pub async fn stream(device: &Stored, file: &Document, mut sink: impl FnMut(Vec<u8>) -> Result<()>) -> Result<()> {
    let cipher = open(file)?;
    let total = Cipher::segments(base::long(file, "sizeOriginal"));
    let length = (SEGMENT + OVERHEAD) as usize;
    let mut body = device.read(&file.string_or_empty("path"), 0, None).await?;
    let failed = |e: utopia_storage::Error| Error::with_message(ErrorType::GeneralServerError, e.to_string());
    let mut buffer: Vec<u8> = Vec::new();
    let mut index = 0i64;
    loop {
        let chunk = body
            .read(utopia_http::CHUNK_SIZE)
            .await
            .map_err(|e| Error::with_message(ErrorType::GeneralServerError, e.to_string()))?;
        if chunk.is_empty() {
            break;
        }
        buffer.extend_from_slice(&chunk);
        let count = ((buffer.len() / length) as i64).min(total - 1 - index);
        if count <= 0 {
            continue;
        }
        let take = count as usize * length;
        sink(cipher.decrypt(&buffer[..take], index, false).map_err(failed)?)?;
        buffer.drain(..take);
        index += count;
    }
    if index != total - 1 {
        return Err(Error::with_message(ErrorType::GeneralServerError, "Incomplete file read"));
    }
    sink(cipher.decrypt(&buffer, index, true).map_err(failed)?)
}
