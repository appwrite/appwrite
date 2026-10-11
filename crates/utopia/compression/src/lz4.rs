//! LZ4.

use crate::{Algorithm, Compression, Error};

/// LZ4 blocks with ext-lz4's framing: the uncompressed size as a 4-byte
/// little-endian prefix, then one LZ4 block.
///
/// Level 0 (the default) is LZ4's fast mode; levels 1 to 12 are its high
/// compression (HC) mode. Output is byte for byte PHP's `lz4_compress()`
/// (LZ4 1.10.0).
///
/// ```
/// use utopia_compression::{Algorithm, Lz4};
///
/// let lz4 = Lz4::new();
/// let body = lz4.compress(b"This is a demo string")?;
/// assert_eq!(body.len(), 27);
/// assert_eq!(lz4.decompress(&body)?, b"This is a demo string");
/// # Ok::<(), utopia_compression::Error>(())
/// ```
///
/// ```php
/// use Utopia\Compression\Algorithms\LZ4;
///
/// $lz4 = new LZ4();
/// $body = $lz4->compress('This is a demo string');
/// echo strlen($body), "\n"; // 27
/// echo $lz4->decompress($body), "\n"; // This is a demo string
/// ```
///
/// Requires the `lz4` feature. PHP: `Utopia\Compression\Algorithms\LZ4`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct Lz4 {
    level: i64,
}

impl Lz4 {
    /// LZ4 in fast mode (level 0).
    ///
    /// PHP: `new LZ4()`.
    pub const fn new() -> Self {
        Lz4 { level: 0 }
    }

    /// LZ4 at `level`, unchecked: a level outside `0` to `12` makes
    /// [`Algorithm::compress`] fail with [`Error::Codec`], as in PHP.
    ///
    /// PHP: `new LZ4($level)`.
    pub const fn with_level(level: i64) -> Self {
        Lz4 { level }
    }

    /// The level: 0 for fast mode, 1 to 12 for high compression.
    ///
    /// PHP: `getLevel()`.
    pub const fn level(&self) -> i64 {
        self.level
    }

    /// Sets the level. Fails with [`Error::InvalidArgument`] outside `0` to
    /// `12`.
    ///
    /// PHP: `setLevel($level)`.
    pub fn set_level(&mut self, level: i64) -> Result<(), Error> {
        if !(0..=12).contains(&level) {
            return Err(Error::InvalidArgument("Level must be between 0 and 12".into()));
        }
        self.level = level;
        Ok(())
    }

    /// Whether this build has LZ4 (the `lz4` feature).
    ///
    /// PHP: `LZ4::isSupported()`, whether `lz4_compress()` exists.
    pub const fn is_supported() -> bool {
        cfg!(feature = "lz4")
    }
}

impl Algorithm for Lz4 {
    fn name(&self) -> &str {
        Compression::LZ4
    }

    fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        imp::compress(data, self.level)
    }

    fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        imp::decompress(data)
    }

    fn supported(&self) -> bool {
        Self::is_supported()
    }
}

#[cfg(feature = "lz4")]
mod imp {
    //! ext-lz4's `php_lz4_compress()` and `php_lz4_uncompress()`.

    use lz4::block::{self, CompressionMode};

    use crate::Error;

    /// `LZ4_MAX_INPUT_SIZE`: the largest block LZ4 handles.
    const MAX_INPUT_SIZE: usize = 0x7E00_0000;

    fn data_error() -> Error {
        Error::Codec("lz4_uncompress : data error".into())
    }

    pub(super) fn compress(data: &[u8], level: i64) -> Result<Vec<u8>, Error> {
        let mode = match level {
            0 => None,
            1..=12 => Some(CompressionMode::HIGHCOMPRESSION(level as i32)),
            _ => {
                // The level is printed as the C int it is cast to.
                return Err(Error::Codec(format!(
                    "lz4_compress: compression level ({}) must be within 1..12",
                    level as i32
                )));
            }
        };
        block::compress(data, mode, true).map_err(|_| Error::Codec("lz4_compress : data error".into()))
    }

    /// Reads the size prefix, then decodes the block into a buffer of that
    /// size (`LZ4_decompress_safe`). An empty result is a failure too, as in
    /// PHP.
    pub(super) fn decompress(data: &[u8]) -> Result<Vec<u8>, Error> {
        // PHP reads the prefix past the end of shorter strings, then hands LZ4
        // a negative length, which it refuses.
        let Some(prefix) = data.first_chunk::<4>() else {
            return Err(data_error());
        };
        let size = i32::from_le_bytes(*prefix);
        if size < 0 {
            return Err(Error::Codec("lz4_uncompress : allocate size error".into()));
        }
        let block = &data[4..];
        // A block never decodes to more than 255 bytes per input byte, so a
        // larger buffer than that (plus LZ4's end margins) behaves the same as
        // the size PHP allocates, without reserving gigabytes for a corrupt
        // prefix.
        let bound = block.len().saturating_mul(255).saturating_add(64);
        let capacity = (size as usize).min(bound).min(MAX_INPUT_SIZE);
        match block::decompress(block, Some(capacity as i32)) {
            Ok(out) if !out.is_empty() => Ok(out),
            _ => Err(data_error()),
        }
    }
}

#[cfg(not(feature = "lz4"))]
mod imp {
    use crate::Error;

    pub(super) fn compress(_: &[u8], _: i64) -> Result<Vec<u8>, Error> {
        Err(Error::undefined("lz4_compress"))
    }

    pub(super) fn decompress(_: &[u8]) -> Result<Vec<u8>, Error> {
        Err(Error::undefined("lz4_uncompress"))
    }
}

#[cfg(all(test, feature = "lz4"))]
mod tests {
    use super::*;

    #[test]
    fn levels() {
        let mut lz4 = Lz4::new();
        assert_eq!(lz4.set_level(13), Err(Error::InvalidArgument("Level must be between 0 and 12".into())));
        let data = b"Lorem ipsum dolor sit amet ".repeat(500);
        for level in 0..=12 {
            lz4.set_level(level).unwrap();
            assert_eq!(lz4.decompress(&lz4.compress(&data).unwrap()).unwrap(), data);
        }
        assert_eq!(
            Lz4::with_level(13).compress(b"x"),
            Err(Error::Codec("lz4_compress: compression level (13) must be within 1..12".into()))
        );
    }

    #[test]
    fn empty() {
        let lz4 = Lz4::new();
        assert_eq!(lz4.compress(b"").unwrap(), b"\0\0\0\0\0");
        assert_eq!(lz4.decompress(b"\0\0\0\0\0"), Err(Error::Codec("lz4_uncompress : data error".into())));
    }
}
