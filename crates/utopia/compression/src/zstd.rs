//! Zstandard.

use crate::{Algorithm, Compression, Error};

/// Zstandard (RFC 8878): `Content-Encoding: zstd`.
///
/// Compresses at level 3 unless told otherwise, one frame with the content
/// size in its header. Output is byte for byte PHP's `zstd_compress()`
/// (libzstd 1.5.7). Decompressing reads the first frame, like
/// `zstd_uncompress()`.
///
/// ```
/// use utopia_compression::{Algorithm, Zstd};
///
/// let zstd = Zstd::new();
/// let body = zstd.compress(b"This is a demo string")?;
/// assert_eq!(body.len(), 30);
/// assert_eq!(zstd.decompress(&body)?, b"This is a demo string");
/// # Ok::<(), utopia_compression::Error>(())
/// ```
///
/// ```php
/// use Utopia\Compression\Algorithms\Zstd;
///
/// $zstd = new Zstd();
/// $body = $zstd->compress('This is a demo string');
/// echo strlen($body), "\n"; // 30
/// echo $zstd->decompress($body), "\n"; // This is a demo string
/// ```
///
/// Requires the `zstd` feature. PHP: `Utopia\Compression\Algorithms\Zstd`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Zstd {
    level: i64,
}

impl Default for Zstd {
    fn default() -> Self {
        Self::new()
    }
}

impl Zstd {
    /// Zstandard at level 3.
    ///
    /// PHP: `new Zstd()`.
    pub const fn new() -> Self {
        Zstd { level: 3 }
    }

    /// Zstandard at `level`, unchecked: zstd's negative (fast) levels work,
    /// and a level above 22 makes [`Algorithm::compress`] fail with
    /// [`Error::Codec`], as in PHP.
    ///
    /// PHP: `new Zstd($level)`.
    pub const fn with_level(level: i64) -> Self {
        Zstd { level }
    }

    /// The level.
    ///
    /// PHP: `getLevel()`.
    pub const fn level(&self) -> i64 {
        self.level
    }

    /// Sets the level. Fails with [`Error::InvalidArgument`] outside `1` to
    /// `22`.
    ///
    /// PHP: `setLevel($level)`.
    pub fn set_level(&mut self, level: i64) -> Result<(), Error> {
        if !(1..=22).contains(&level) {
            return Err(Error::InvalidArgument("Level must be between 1 and 22".into()));
        }
        self.level = level;
        Ok(())
    }

    /// Whether this build has Zstandard (the `zstd` feature).
    ///
    /// PHP: `Zstd::isSupported()`, whether `zstd_compress()` exists.
    pub const fn is_supported() -> bool {
        cfg!(feature = "zstd")
    }
}

impl Algorithm for Zstd {
    fn name(&self) -> &str {
        Compression::ZSTD
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

#[cfg(feature = "zstd")]
mod imp {
    //! ext-zstd's `zstd_compress()` and `zstd_uncompress()`: the same
    //! streaming calls into libzstd, with the same buffer sizes.

    use zstd::zstd_safe::zstd_sys::ZSTD_EndDirective;
    use zstd::zstd_safe::{self, CCtx, CParameter, DCtx, InBuffer, OutBuffer, ResetDirective};

    use crate::Error;

    fn codec(function: &str, code: usize) -> Error {
        Error::Codec(format!("{function}(): {}", zstd_safe::get_error_name(code)))
    }

    pub(super) fn compress(data: &[u8], level: i64) -> Result<Vec<u8>, Error> {
        let max = zstd_safe::max_c_level();
        if level > i64::from(max) {
            return Err(Error::Codec(format!(
                "zstd_compress(): compression level ({level}) must be within 1..{max} or smaller then 0"
            )));
        }
        let mut cctx = CCtx::create();
        cctx.reset(ResetDirective::SessionOnly).map_err(|code| codec("zstd_compress", code))?;
        // PHP casts the level to a C int and ignores a refused parameter.
        let _ = cctx.set_parameter(CParameter::CompressionLevel(level as i32));
        let mut buffer = vec![0u8; CCtx::out_size()];
        let mut out = Vec::with_capacity(data.len() / 2 + 64);
        let mut input = InBuffer::around(data);
        loop {
            let mut output = OutBuffer::around(&mut buffer[..]);
            let remaining = cctx
                .compress_stream2(&mut output, &mut input, ZSTD_EndDirective::ZSTD_e_end)
                .map_err(|code| codec("zstd_compress", code))?;
            let written = output.pos();
            out.extend_from_slice(&buffer[..written]);
            if remaining == 0 {
                return Ok(out);
            }
        }
    }

    /// Decodes into a buffer of the frame's content size (or
    /// `ZSTD_DStreamOutSize()` when unknown) that grows by that chunk when a
    /// call fills it, until the input is used up or the first frame ends.
    pub(super) fn decompress(data: &[u8]) -> Result<Vec<u8>, Error> {
        let not_zstd = || Error::Codec("zstd_uncompress(): it was not compressed by zstd".into());
        // zstd-sys builds every legacy decoder (ZSTD_LEGACY_SUPPORT=1);
        // PHP's libzstd is built with the default ZSTD_LEGACY_SUPPORT=5,
        // which knows frames from v0.5 on only.
        if let Some(magic) = data.first_chunk::<4>()
            && matches!(u32::from_le_bytes(*magic), 0x1EB5_2FFD | 0xFD2F_B522..=0xFD2F_B524)
        {
            return Err(not_zstd());
        }
        let size = match zstd_safe::get_frame_content_size(data) {
            Err(_) => return Err(not_zstd()),
            Ok(None) => DCtx::out_size(),
            Ok(Some(size)) => usize::try_from(size).unwrap_or(usize::MAX),
        };
        let chunk = DCtx::out_size();
        let mut dctx = DCtx::create();
        dctx.reset(ResetDirective::SessionOnly).map_err(|code| codec("zstd_uncompress", code))?;
        let mut buffer = Vec::new();
        // A corrupt header can claim any size; PHP would die past its memory
        // limit, Rust fails instead of aborting.
        buffer.try_reserve_exact(size).map_err(|_| Error::Codec("zstd_uncompress(): insufficient memory".into()))?;
        buffer.resize(size, 0);
        let mut out = Vec::new();
        let mut input = InBuffer::around(data);
        let mut last = 0;
        while input.pos() < data.len() {
            if last == buffer.len() {
                buffer.resize(buffer.len() + chunk, 0);
            }
            let mut output = OutBuffer::around(&mut buffer[..]);
            let hint =
                dctx.decompress_stream(&mut output, &mut input).map_err(|code| codec("zstd_uncompress", code))?;
            last = output.pos();
            out.extend_from_slice(&buffer[..last]);
            if hint == 0 {
                break;
            }
        }
        Ok(out)
    }
}

#[cfg(not(feature = "zstd"))]
mod imp {
    use crate::Error;

    pub(super) fn compress(_: &[u8], _: i64) -> Result<Vec<u8>, Error> {
        Err(Error::undefined("zstd_compress"))
    }

    pub(super) fn decompress(_: &[u8]) -> Result<Vec<u8>, Error> {
        Err(Error::undefined("zstd_uncompress"))
    }
}

#[cfg(all(test, feature = "zstd"))]
mod tests {
    use super::*;

    #[test]
    fn levels() {
        let mut zstd = Zstd::new();
        assert_eq!(zstd.set_level(0), Err(Error::InvalidArgument("Level must be between 1 and 22".into())));
        let data = b"Lorem ipsum dolor sit amet ".repeat(500);
        for level in [-5, -1, 0, 1, 3, 9, 19, 22] {
            let zstd = Zstd::with_level(level);
            assert_eq!(zstd.decompress(&zstd.compress(&data).unwrap()).unwrap(), data);
        }
        assert_eq!(
            Zstd::with_level(23).compress(b"x"),
            Err(Error::Codec("zstd_compress(): compression level (23) must be within 1..22 or smaller then 0".into()))
        );
    }

    #[test]
    fn known_output() {
        assert_eq!(
            Zstd::new().compress(b"This is a demo string").unwrap(),
            b"\x28\xb5\x2f\xfd\x20\x15\xa9\x00\x00This is a demo string"
        );
    }
}
