//! XZ.

use crate::{Algorithm, Compression, Error};

/// XZ: LZMA2 at preset 5 in an `.xz` container with a CRC64 check.
///
/// The settings are those of PHP's ext-xz `xzencode()` (its default
/// `xz.compression_level` of 5); decompressing reads concatenated `.xz`
/// streams.
///
/// ```
/// use utopia_compression::{Algorithm, Xz};
///
/// let xz = Xz::new();
/// match xz.compress(b"This is a demo string") {
///     Ok(body) => assert_eq!(xz.decompress(&body)?, b"This is a demo string"),
///     // Built without the `xz` feature.
///     Err(e) => assert_eq!(e.php_class(), "Error"),
/// }
/// # Ok::<(), utopia_compression::Error>(())
/// ```
///
/// ```php
/// use Utopia\Compression\Algorithms\XZ;
///
/// $xz = new XZ();
/// if (XZ::isSupported()) {
///     echo $xz->decompress($xz->compress('This is a demo string')), "\n"; // This is a demo string
/// }
/// ```
///
/// Requires the `xz` feature, which is off by default: without it, as in
/// PHP without ext-xz, `compress()` and `decompress()` fail with
/// [`Error::Unsupported`]. Output is not compared with PHP's: the PHP image
/// the conversion is checked against has no ext-xz.
///
/// PHP: `Utopia\Compression\Algorithms\XZ`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct Xz;

impl Xz {
    /// XZ at preset 5.
    ///
    /// PHP: `new XZ()`.
    pub const fn new() -> Self {
        Xz
    }

    /// Whether this build has XZ (the `xz` feature).
    ///
    /// PHP: `XZ::isSupported()`, whether `xzencode()` exists.
    pub const fn is_supported() -> bool {
        cfg!(feature = "xz")
    }
}

impl Algorithm for Xz {
    fn name(&self) -> &str {
        Compression::XZ
    }

    fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        imp::compress(data)
    }

    fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        imp::decompress(data)
    }

    fn supported(&self) -> bool {
        Self::is_supported()
    }
}

#[cfg(feature = "xz")]
mod imp {
    use std::io::{Read, Write};

    use lzma_rust2::{XzOptions, XzReader, XzWriter};

    use crate::Error;

    /// `xz.compression_level`'s default.
    const PRESET: u32 = 5;

    pub(super) fn compress(data: &[u8]) -> Result<Vec<u8>, Error> {
        let fail = |e: std::io::Error| Error::Codec(format!("xzencode(): {e}"));
        let mut writer =
            XzWriter::new(Vec::with_capacity(data.len() / 2 + 64), XzOptions::with_preset(PRESET)).map_err(fail)?;
        writer.write_all(data).map_err(fail)?;
        writer.finish().map_err(fail)
    }

    /// PHP's `xzdecode()` returns `false` without a warning on invalid data;
    /// the message here is Rust's own.
    pub(super) fn decompress(data: &[u8]) -> Result<Vec<u8>, Error> {
        if data.is_empty() {
            return Err(Error::Codec("xzdecode(): empty input".into()));
        }
        let mut out = Vec::new();
        XzReader::new(data, true).read_to_end(&mut out).map_err(|e| Error::Codec(format!("xzdecode(): {e}")))?;
        Ok(out)
    }
}

#[cfg(not(feature = "xz"))]
mod imp {
    use crate::Error;

    pub(super) fn compress(_: &[u8]) -> Result<Vec<u8>, Error> {
        Err(Error::undefined("xzencode"))
    }

    pub(super) fn decompress(_: &[u8]) -> Result<Vec<u8>, Error> {
        Err(Error::undefined("xzdecode"))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[cfg(feature = "xz")]
    #[test]
    fn round_trips() {
        let xz = Xz::new();
        for data in [&b""[..], b"This is a demo string", &b"Lorem ipsum dolor sit amet ".repeat(5000)] {
            let compressed = xz.compress(data).unwrap();
            assert_eq!(&compressed[..6], b"\xfd7zXZ\x00");
            assert_eq!(xz.decompress(&compressed).unwrap(), data);
        }
        assert!(xz.compress(&b"Lorem ipsum ".repeat(5000)).unwrap().len() < 1000);
        assert!(matches!(xz.decompress(b"garbage"), Err(Error::Codec(_))));
    }

    #[cfg(not(feature = "xz"))]
    #[test]
    fn unsupported() {
        assert!(!Xz::is_supported());
        assert_eq!(
            Xz::new().compress(b"x"),
            Err(Error::Unsupported("Call to undefined function Utopia\\Compression\\Algorithms\\xzencode()".into()))
        );
    }
}
