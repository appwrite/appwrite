//! Zstandard.

use crate::{Algorithm, Compression, Error, Sink};

/// Zstandard (RFC 8878): `Content-Encoding: zstd`.
///
/// Compresses at level 3 unless told otherwise, one frame with the content size in its
/// header. Output is byte for byte PHP's `zstd_compress()` (libzstd 1.5.7). Decompressing
/// reads the first frame, like `zstd_uncompress()`.
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

    /// Zstandard at `level`, unchecked: zstd's negative (fast) levels work, and a level above
    /// 22 makes [`Algorithm::compress`] fail with [`Error::Codec`], as in PHP.
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

    /// Sets the level. Fails with [`Error::InvalidArgument`] outside `1` to `22`.
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

    /// [`Algorithm::compress`], appended to `out`.
    pub fn compress_into<S: Sink + ?Sized>(&self, data: &[u8], out: &mut S) -> Result<(), Error> {
        imp::compress(data, self.level, out)
    }

    /// [`Algorithm::decompress`], appended to `out`.
    pub fn decompress_into<S: Sink + ?Sized>(&self, data: &[u8], out: &mut S) -> Result<(), Error> {
        imp::decompress(data, out)
    }
}

impl Algorithm for Zstd {
    fn name(&self) -> &str {
        Compression::ZSTD
    }

    fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        let mut out = Vec::new();
        self.compress_into(data, &mut out)?;
        Ok(out)
    }

    fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        let mut out = Vec::new();
        self.decompress_into(data, &mut out)?;
        Ok(out)
    }

    fn supported(&self) -> bool {
        Self::is_supported()
    }
}

#[cfg(feature = "zstd")]
mod imp {
    //! ext-zstd's `zstd_compress()` and `zstd_uncompress()`: the same streaming calls into
    //! libzstd with the same buffer sizes, each call's output written where it ends up rather
    //! than into a buffer it is then copied out of.
    //!
    //! Each call creates its context and frees it, as PHP does: a kept context carries state
    //! from one call to the next that changes which error a corrupt frame reports. The sink's
    //! allocator ([`Sink::allocator`]) makes that cheap where it has a faster one than `malloc()`.

    use std::ffi::{CStr, c_void};

    use zstd_safe::zstd_sys as z;

    use crate::sink::spare;
    use crate::{Allocator, Error, Sink};

    fn codec(function: &str, code: usize) -> Error {
        // SAFETY: libzstd returns a static NUL-terminated string for any code.
        let name = unsafe { CStr::from_ptr(z::ZSTD_getErrorName(code)) };
        Error::Codec(format!("{function}(): {}", name.to_string_lossy()))
    }

    fn is_error(code: usize) -> bool {
        // SAFETY: a pure function of its argument.
        unsafe { z::ZSTD_isError(code) != 0 }
    }

    /// `allocator` as libzstd takes one.
    fn custom(allocator: Option<Allocator>) -> Option<z::ZSTD_customMem> {
        allocator.map(|allocator| z::ZSTD_customMem {
            customAlloc: Some(allocator.alloc as unsafe extern "C" fn(*mut c_void, usize) -> *mut c_void),
            customFree: Some(allocator.free as unsafe extern "C" fn(*mut c_void, *mut c_void)),
            opaque: allocator.opaque,
        })
    }

    /// A compression context, freed when dropped.
    struct CCtx(*mut z::ZSTD_CCtx);

    impl CCtx {
        /// A new context, as `ZSTD_createCCtx()` makes one, on `allocator`.
        fn new(allocator: Option<Allocator>) -> Option<Self> {
            // SAFETY: creates a context or returns null; the allocator outlives it (the call).
            let cctx = unsafe {
                match custom(allocator) {
                    Some(memory) => z::ZSTD_createCCtx_advanced(memory),
                    None => z::ZSTD_createCCtx(),
                }
            };
            (!cctx.is_null()).then_some(CCtx(cctx))
        }
    }

    impl Drop for CCtx {
        fn drop(&mut self) {
            // SAFETY: created by ZSTD_createCCtx(_advanced) and freed only here.
            unsafe { z::ZSTD_freeCCtx(self.0) };
        }
    }

    /// A decompression context, freed when dropped.
    struct DCtx(*mut z::ZSTD_DCtx);

    impl DCtx {
        /// A new context, as `ZSTD_createDCtx()` makes one, on `allocator`.
        fn new(allocator: Option<Allocator>) -> Option<Self> {
            // SAFETY: creates a context or returns null; the allocator outlives it (the call).
            let dctx = unsafe {
                match custom(allocator) {
                    Some(memory) => z::ZSTD_createDCtx_advanced(memory),
                    None => z::ZSTD_createDCtx(),
                }
            };
            (!dctx.is_null()).then_some(DCtx(dctx))
        }
    }

    impl Drop for DCtx {
        fn drop(&mut self) {
            // SAFETY: created by ZSTD_createDCtx(_advanced) and freed only here.
            unsafe { z::ZSTD_freeDCtx(self.0) };
        }
    }

    /// Makes room for `window` more bytes after what `out` holds.
    fn room<S: Sink + ?Sized>(out: &mut S, window: usize) -> bool {
        let (len, capacity) = (out.len(), out.capacity());
        len + window <= capacity || out.grow((len + window).max(capacity + capacity / 2))
    }

    /// `zstd_compress()`: `ZSTD_compressStream2(ZSTD_e_end)` until it is done, each call into
    /// a window of `ZSTD_CStreamOutSize()` bytes.
    ///
    /// A window that holds the whole bound of the input lets libzstd compress in one pass
    /// rather than block by block; PHP's does for inputs up to a block (128 KiB), so the
    /// window here is the smaller of the two, the same choice.
    pub(super) fn compress<S: Sink + ?Sized>(data: &[u8], level: i64, out: &mut S) -> Result<(), Error> {
        // SAFETY: pure functions.
        let (max, bound, window) =
            unsafe { (z::ZSTD_maxCLevel(), z::ZSTD_compressBound(data.len()), z::ZSTD_CStreamOutSize()) };
        if level > i64::from(max) {
            return Err(Error::Codec(format!(
                "zstd_compress(): compression level ({level}) must be within 1..{max} or smaller then 0"
            )));
        }
        let window = window.min(bound);
        let fail = || Error::Codec("zstd_compress(): insufficient memory".into());
        out.reserve(out.len() + bound + window);
        let cctx = CCtx::new(out.allocator())
            .ok_or_else(|| Error::Codec("zstd_compress(): failed to create compress context".into()))?;
        // SAFETY: a live context. PHP resets the session and sets the level, cast to a C int (a
        // refused level ignored).
        unsafe {
            z::ZSTD_CCtx_reset(cctx.0, z::ZSTD_ResetDirective::ZSTD_reset_session_only);
            z::ZSTD_CCtx_setParameter(cctx.0, z::ZSTD_cParameter::ZSTD_c_compressionLevel, level as i32);
        }
        let mut input = z::ZSTD_inBuffer { src: data.as_ptr().cast(), size: data.len(), pos: 0 };
        loop {
            if !room(out, window) {
                return Err(fail());
            }
            let (at, _) = spare(out);
            let mut output = z::ZSTD_outBuffer { dst: at.cast(), size: window, pos: 0 };
            // SAFETY: the context is live; the input covers `data` and the output `window`
            // bytes of room in `out`, both alive for the call.
            let remaining = unsafe {
                z::ZSTD_compressStream2(cctx.0, &raw mut output, &raw mut input, z::ZSTD_EndDirective::ZSTD_e_end)
            };
            if is_error(remaining) {
                return Err(codec("zstd_compress", remaining));
            }
            // SAFETY: libzstd wrote `pos` bytes into the room.
            unsafe { out.set_len(out.len() + output.pos) };
            if remaining == 0 {
                return Ok(());
            }
        }
    }

    /// `zstd_uncompress()`: a window of the frame's content size (`ZSTD_DStreamOutSize()` when
    /// the frame does not say), grown by that much whenever a call fills it, until the input
    /// is used up or the first frame ends. A truncated frame gives what it decoded, as in PHP.
    pub(super) fn decompress<S: Sink + ?Sized>(data: &[u8], out: &mut S) -> Result<(), Error> {
        let not_zstd = || Error::Codec("zstd_uncompress(): it was not compressed by zstd".into());
        // zstd-sys builds every legacy decoder (ZSTD_LEGACY_SUPPORT=1); PHP's libzstd is built
        // with the default ZSTD_LEGACY_SUPPORT=5, which knows frames from v0.5 on only.
        if let Some(magic) = data.first_chunk::<4>()
            && matches!(u32::from_le_bytes(*magic), 0x1EB5_2FFD | 0xFD2F_B522..=0xFD2F_B524)
        {
            return Err(not_zstd());
        }
        // SAFETY: reads at most `data.len()` bytes of `data`.
        let size = unsafe { z::ZSTD_getFrameContentSize(data.as_ptr().cast(), data.len()) };
        // SAFETY: a pure function.
        let chunk = unsafe { z::ZSTD_DStreamOutSize() };
        let mut window = match size {
            size if size == z::ZSTD_CONTENTSIZE_ERROR as u64 => return Err(not_zstd()),
            size if size == z::ZSTD_CONTENTSIZE_UNKNOWN as u64 => chunk,
            // A size past the address space: PHP's allocation of it fails the same way.
            size => usize::try_from(size).unwrap_or(usize::MAX),
        };
        let fail = || Error::Codec("zstd_uncompress(): insufficient memory".into());
        // PHP allocates the window before anything else, so a frame claiming more than the
        // memory there is fails as early.
        if !out.grow(out.len().saturating_add(window)) {
            return Err(fail());
        }
        let dctx = DCtx::new(out.allocator())
            .ok_or_else(|| Error::Codec("zstd_uncompress(): failed to prepare uncompression".into()))?;
        // SAFETY: a live context.
        unsafe { z::ZSTD_DCtx_reset(dctx.0, z::ZSTD_ResetDirective::ZSTD_reset_session_only) };
        let mut input = z::ZSTD_inBuffer { src: data.as_ptr().cast(), size: data.len(), pos: 0 };
        let mut filled = 0;
        while input.pos < input.size {
            if filled == window {
                window += chunk;
            }
            if !room(out, window) {
                return Err(fail());
            }
            let (at, _) = spare(out);
            let mut output = z::ZSTD_outBuffer { dst: at.cast(), size: window, pos: 0 };
            // SAFETY: the context is live; the input covers `data` and the output `window`
            // bytes of room in `out`, both alive for the call.
            let hint = unsafe { z::ZSTD_decompressStream(dctx.0, &raw mut output, &raw mut input) };
            if is_error(hint) {
                return Err(codec("zstd_uncompress", hint));
            }
            filled = output.pos;
            // SAFETY: libzstd wrote `pos` bytes into the room.
            unsafe { out.set_len(out.len() + filled) };
            if hint == 0 {
                break;
            }
        }
        Ok(())
    }
}

#[cfg(not(feature = "zstd"))]
mod imp {
    use crate::{Error, Sink};

    pub(super) fn compress<S: Sink + ?Sized>(_: &[u8], _: i64, _: &mut S) -> Result<(), Error> {
        Err(Error::undefined("zstd_compress"))
    }

    pub(super) fn decompress<S: Sink + ?Sized>(_: &[u8], _: &mut S) -> Result<(), Error> {
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

    #[test]
    fn large_input_in_blocks() {
        // Larger than a block, so compressed in PHP's window by window.
        let data: Vec<u8> = (0..1_000_000u32).map(|i| (i.wrapping_mul(2_654_435_761) >> 27) as u8).collect();
        let first = Zstd::new().compress(&data).unwrap();
        assert_eq!(Zstd::new().compress(&data).unwrap(), first);
        assert_eq!(Zstd::new().decompress(&first).unwrap(), data);
    }

    #[test]
    fn truncated_and_invalid() {
        let data = b"Lorem ipsum dolor sit amet ".repeat(5000);
        let body = Zstd::new().compress(&data).unwrap();
        // A truncated frame gives what it decoded, without an error, as in PHP.
        let partial = Zstd::new().decompress(&body[..body.len() / 2]).unwrap();
        assert!(data.starts_with(&partial));
        let not_zstd = Err(Error::Codec("zstd_uncompress(): it was not compressed by zstd".into()));
        assert_eq!(Zstd::new().decompress(b""), not_zstd);
        assert_eq!(Zstd::new().decompress(b"garbage"), not_zstd);
    }
}
