//! Segmented AES-256-GCM: a file encrypted in fixed 64 KiB segments, so it
//! can be encrypted in parts and any range read back without the rest.

use std::io;

use aes_gcm::aead::{AeadInPlace, KeyInit};
use aes_gcm::{Aes256Gcm, Nonce, Tag};
use async_trait::async_trait;
use bytes::Bytes;
use sha2::{Digest, Sha256};

use crate::device::{Device, Metadata, Sink};
use crate::{Body, Error, Result};

const NONCE: usize = 12;
const TAG: usize = 16;
const VERSION: u8 = 1;

/// AES-256-GCM over fixed 64 KiB segments. Each segment is stored as
/// `nonce | ciphertext | tag`, and authenticated with its index and whether
/// it is the last one, so segments cannot be swapped, reordered or cut off.
///
/// ```
/// use utopia_storage::Cipher;
///
/// let cipher = Cipher::new(&[7; 32])?;
/// let sealed = cipher.encrypt(b"Hello", 0, true)?;
/// assert_eq!(sealed.len() as i64, Cipher::size(5));
/// assert_eq!(cipher.decrypt(&sealed, 0, true)?, b"Hello");
/// # Ok::<(), utopia_storage::Error>(())
/// ```
///
/// ```php
/// use Utopia\Storage\Cipher;
///
/// $cipher = new Cipher(\str_repeat("\x07", 32));
/// $sealed = $cipher->encrypt('Hello', 0, true);
/// var_dump(\strlen($sealed) === Cipher::size(5)); // bool(true)
/// echo $cipher->decrypt($sealed, 0, true), "\n";   // Hello
/// ```
///
/// PHP: `Utopia\Storage\Cipher`.
#[derive(Clone)]
pub struct Cipher {
    key: [u8; 32],
}

impl std::fmt::Debug for Cipher {
    /// Writes the type's name, never the key.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("Cipher")
    }
}

impl Cipher {
    /// The scheme's name, also the additional data a wrapped key is sealed
    /// with.
    ///
    /// PHP: `Cipher::NAME`.
    pub const NAME: &'static str = "aes-256-gcm-segmented";

    /// Plaintext bytes per segment: 64 KiB.
    ///
    /// PHP: `Cipher::SEGMENT`.
    pub const SEGMENT: i64 = 65536;

    /// Bytes each segment adds: its nonce and tag.
    ///
    /// PHP: `Cipher::OVERHEAD`.
    pub const OVERHEAD: i64 = (NONCE + TAG) as i64;

    /// A cipher with `key`, which must be 32 bytes ([`Error::InvalidArgument`]
    /// otherwise).
    ///
    /// PHP: `new Cipher($key)`.
    pub fn new(key: &[u8]) -> Result<Self> {
        let key: [u8; 32] = key.try_into().map_err(|_| Error::InvalidArgument("Key must be 32 bytes".into()))?;
        Ok(Cipher { key })
    }

    /// A cipher with a new random key.
    ///
    /// PHP: `Cipher::generate()`.
    pub fn generate() -> Self {
        Cipher { key: random() }
    }

    /// Restores a cipher from a key wrapped with `master` by [`Cipher::wrap`].
    /// Fails with [`Error::Storage`] when it does not unwrap, and with
    /// [`Error::InvalidArgument`] for an empty master key.
    ///
    /// PHP: `Cipher::unwrap($wrapped, $master)`.
    pub fn unwrap(wrapped: &[u8], master: &[u8]) -> Result<Self> {
        let failed = || Error::Storage("Unable to unwrap the key".into());
        if wrapped.len() != NONCE + 32 + TAG {
            return Err(failed());
        }
        let aead = Aes256Gcm::new_from_slice(&master_key(master)?).map_err(|_| failed())?;
        let (nonce, rest) = wrapped.split_at(NONCE);
        let (sealed, tag) = rest.split_at(32);
        let mut key = sealed.to_vec();
        aead.decrypt_in_place_detached(Nonce::from_slice(nonce), Self::NAME.as_bytes(), &mut key, Tag::from_slice(tag))
            .map_err(|_| failed())?;
        Self::new(&key)
    }

    /// This cipher's key, encrypted with `master`, to store beside the file.
    /// Fails with [`Error::InvalidArgument`] for an empty master key.
    ///
    /// PHP: `wrap($master)`.
    pub fn wrap(&self, master: &[u8]) -> Result<Vec<u8>> {
        let aead = Aes256Gcm::new_from_slice(&master_key(master)?)
            .map_err(|_| Error::Storage("Failed to wrap the key".into()))?;
        let nonce: [u8; NONCE] = random();
        let mut sealed = self.key.to_vec();
        let tag = aead
            .encrypt_in_place_detached(Nonce::from_slice(&nonce), Self::NAME.as_bytes(), &mut sealed)
            .map_err(|_| Error::Storage("Failed to wrap the key".into()))?;
        let mut out = nonce.to_vec();
        out.extend_from_slice(&sealed);
        out.extend_from_slice(&tag);
        Ok(out)
    }

    /// Encrypts `data` that starts at segment `index`; `last` marks its final
    /// segment as the end of the file.
    ///
    /// PHP: `encrypt($data, $index, $last)`.
    pub fn encrypt(&self, data: &[u8], index: i64, last: bool) -> Result<Vec<u8>> {
        let aead = self.aead();
        let count = Self::segments(data.len() as i64);
        let mut out = Vec::with_capacity(Self::size(data.len() as i64) as usize);
        for segment in 0..count {
            let start = (segment * Self::SEGMENT) as usize;
            let end = (start + Self::SEGMENT as usize).min(data.len());
            let mut block = data.get(start..end).unwrap_or_default().to_vec();
            let nonce: [u8; NONCE] = random();
            let aad = aad(index + segment, last && segment == count - 1);
            let tag = aead
                .encrypt_in_place_detached(Nonce::from_slice(&nonce), &aad, &mut block)
                .map_err(|_| Error::Storage(format!("Failed to encrypt segment {}", index + segment)))?;
            out.extend_from_slice(&nonce);
            out.extend_from_slice(&block);
            out.extend_from_slice(&tag);
        }
        Ok(out)
    }

    /// Decrypts whole segments that start at segment `index`; `last` says the
    /// data ends with the final segment of the file. Fails with
    /// [`Error::Storage`] for a truncated segment or one that does not
    /// authenticate.
    ///
    /// PHP: `decrypt($data, $index, $last)`.
    pub fn decrypt(&self, data: &[u8], index: i64, last: bool) -> Result<Vec<u8>> {
        let aead = self.aead();
        let size = (Self::SEGMENT + Self::OVERHEAD) as usize;
        let count = data.len().div_ceil(size).max(1);
        let mut out = Vec::with_capacity(data.len());
        for segment in 0..count {
            let start = segment * size;
            let block = data.get(start..(start + size).min(data.len())).unwrap_or_default();
            let fin = segment == count - 1;
            let number = index + segment as i64;
            if block.len() < Self::OVERHEAD as usize || ((!fin || !last) && block.len() != size) {
                return Err(Error::Storage(format!("Segment {number} is truncated")));
            }
            let (nonce, rest) = block.split_at(NONCE);
            let (sealed, tag) = rest.split_at(rest.len() - TAG);
            let mut plain = sealed.to_vec();
            aead.decrypt_in_place_detached(
                Nonce::from_slice(nonce),
                &aad(number, last && fin),
                &mut plain,
                Tag::from_slice(tag),
            )
            .map_err(|_| Error::Storage(format!("Segment {number} failed authentication")))?;
            out.extend_from_slice(&plain);
        }
        Ok(out)
    }

    /// Plaintext bytes `start` to `end` of the file at `path`, which holds
    /// `size` bytes of plaintext. Reads only the segments the range falls in.
    ///
    /// PHP: `range($device, $path, $size, $start, $end)`.
    pub async fn range(&self, device: &dyn Device, path: &str, size: i64, start: i64, end: i64) -> Result<Vec<u8>> {
        let first = start / Self::SEGMENT;
        let last = end / Self::SEGMENT;
        let offset = Self::offset(first);
        let length = Self::offset(last - first + 1);
        if offset < 0 || length <= 0 {
            return Err(Error::InvalidArgument(format!("Invalid range {start}-{end}")));
        }
        let data = device.read(path, offset as u64, Some(length as u64), None, None).await?.bytes().await;
        let data = data.map_err(Error::Body)?;
        let fin = last == Self::segments(size) - 1;
        let plain = self.decrypt(&data, first, fin)?;
        Ok(php_std::string::substr(&plain, start - first * Self::SEGMENT, Some(end - start + 1)).to_vec())
    }

    /// Sends the file at `path`, which holds `size` bytes of plaintext, to
    /// `sink` as plaintext. Keeps one device read and one segment in memory
    /// at a time.
    ///
    /// PHP: `stream($device, $path, $size, $sink)`.
    pub async fn stream(&self, device: &dyn Device, path: &str, size: i64, sink: &mut dyn Sink) -> Result<()> {
        let mut segments =
            Segments { cipher: self, sink, total: Self::segments(size), buffer: Vec::new(), index: 0, error: None };
        if let Err(e) = device.stream(path, &mut segments, 0, None, None).await {
            return Err(segments.error.take().unwrap_or(e));
        }
        if segments.index != segments.total - 1 {
            return Err(Error::Storage("Incomplete file read".into()));
        }
        let plain = self.decrypt(&segments.buffer, segments.index, true)?;
        segments.sink.write(Bytes::from(plain)).await.map_err(Error::Body)
    }

    /// Copies the file at `path`, which holds `size` bytes of plaintext, to
    /// `target` on `to` as plaintext, uploading `chunk` bytes at a time.
    ///
    /// PHP: `copy($from, $path, $size, $to, $target, $contentType, $chunk)`.
    #[allow(clippy::too_many_arguments)]
    pub async fn copy(
        &self,
        from: &dyn Device,
        path: &str,
        size: i64,
        to: &dyn Device,
        target: &str,
        content_type: &str,
        chunk: i64,
    ) -> Result<()> {
        if chunk == 0 {
            return Err(Error::InvalidArgument("Division by zero".into()));
        }
        let chunks = ((size + chunk - 1) / chunk).max(1);
        let mut metadata = Metadata { content_type: Some(content_type.to_owned()), ..Metadata::default() };
        for index in 0..chunks {
            let start = index * chunk;
            let end = size.min(start + chunk) - 1;
            let data = if size == 0 { Vec::new() } else { self.range(from, path, size, start, end).await? };
            to.upload(Body::from(data), target, content_type, index + 1, chunks, &mut metadata).await?;
        }
        Ok(())
    }

    /// Number of segments for `size` bytes of plaintext. An empty file is one
    /// empty segment.
    ///
    /// PHP: `Cipher::segments($size)`.
    pub fn segments(size: i64) -> i64 {
        ((size + Self::SEGMENT - 1) / Self::SEGMENT).max(1)
    }

    /// Encrypted size of `size` bytes of plaintext.
    ///
    /// PHP: `Cipher::size($size)`.
    pub fn size(size: i64) -> i64 {
        size + Self::segments(size) * Self::OVERHEAD
    }

    /// Encrypted offset of segment `index`.
    ///
    /// PHP: `Cipher::offset($index)`.
    pub fn offset(index: i64) -> i64 {
        index * (Self::SEGMENT + Self::OVERHEAD)
    }

    fn aead(&self) -> Aes256Gcm {
        Aes256Gcm::new((&self.key).into())
    }
}

/// `pack('CJC', VERSION, $index, $last)`: the version, the index as an
/// unsigned 64-bit big-endian integer, and whether the segment is the last.
fn aad(index: i64, last: bool) -> [u8; 10] {
    let mut out = [0u8; 10];
    out[0] = VERSION;
    out[1..9].copy_from_slice(&(index as u64).to_be_bytes());
    out[9] = u8::from(last);
    out
}

/// The SHA-256 of a non-empty master key.
fn master_key(master: &[u8]) -> Result<[u8; 32]> {
    if master.is_empty() {
        return Err(Error::InvalidArgument("Master key must not be empty".into()));
    }
    Ok(Sha256::digest(master).into())
}

fn random<const N: usize>() -> [u8; N] {
    let mut out = [0u8; N];
    // The operating system's generator, as PHP's random_bytes().
    getrandom::fill(&mut out).expect("the operating system's random number generator failed");
    out
}

/// The sink [`Cipher::stream`] hands the device: buffers the file and
/// passes every whole segment but the final one on as plaintext.
struct Segments<'a> {
    cipher: &'a Cipher,
    sink: &'a mut dyn Sink,
    total: i64,
    buffer: Vec<u8>,
    index: i64,
    error: Option<Error>,
}

#[async_trait]
impl Sink for Segments<'_> {
    async fn write(&mut self, chunk: Bytes) -> io::Result<()> {
        let length = (Cipher::SEGMENT + Cipher::OVERHEAD) as usize;
        self.buffer.extend_from_slice(&chunk);
        // The final segment waits for the end of the file, where it is checked as the last one.
        let count = ((self.buffer.len() / length) as i64).min(self.total - 1 - self.index);
        if count <= 0 {
            return Ok(());
        }
        let take = count as usize * length;
        let plain = match self.cipher.decrypt(&self.buffer[..take], self.index, false) {
            Ok(plain) => plain,
            Err(e) => {
                let message = e.to_string();
                self.error = Some(e);
                return Err(io::Error::other(message));
            }
        };
        self.sink.write(Bytes::from(plain)).await?;
        self.buffer.drain(..take);
        self.index += count;
        Ok(())
    }
}
