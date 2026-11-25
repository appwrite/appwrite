//! LZ4.

use crate::{Algorithm, Compression, Error, Sink};

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

    /// [`Algorithm::compress`], appended to `out`.
    pub fn compress_into<S: Sink + ?Sized>(&self, data: &[u8], out: &mut S) -> Result<(), Error> {
        imp::compress(data, self.level, out)
    }

    /// [`Algorithm::decompress`], appended to `out`.
    pub fn decompress_into<S: Sink + ?Sized>(&self, data: &[u8], out: &mut S) -> Result<(), Error> {
        imp::decompress(data, out)
    }
}

impl Algorithm for Lz4 {
    fn name(&self) -> &str {
        Compression::LZ4
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

#[cfg(feature = "lz4")]
mod imp {
    //! ext-lz4's `php_lz4_compress()` and `php_lz4_uncompress()`: the same calls into LZ4
    //! 1.10.0 with the same sizes, the output written where it ends up rather than into a
    //! buffer it is then copied out of.

    use std::os::raw::{c_char, c_int};

    use lz4_sys as l;

    use crate::sink::{append, spare};
    use crate::{Error, Sink};

    /// `PHP_LZ4_CLEVEL_MAX`.
    const MAX_LEVEL: c_int = 12;

    /// The most an LZ4 block expands: under 255 output bytes per input byte (a match length
    /// grows by 255 per extra byte), so a size prefix claiming more is not reserved. The
    /// margin keeps the decoder's end-of-buffer checks as far away as with PHP's buffer.
    const MAX_RATIO: usize = 255;
    const MARGIN: usize = 4096;

    /// Whether `malloc(size)` succeeds, which PHP asks for a size prefix larger than the block
    /// can expand to (the block's own bound is what is reserved here): when the system refuses
    /// it, PHP warns, and so does this.
    fn allocatable(size: usize) -> bool {
        use std::alloc::{GlobalAlloc, Layout, System};
        let Ok(layout) = Layout::from_size_align(size, 1) else { return false };
        // SAFETY: a layout of non-zero size; the block is freed straight away, never touched.
        unsafe {
            let block = System.alloc(layout);
            if block.is_null() {
                return false;
            }
            System.dealloc(block, layout);
        }
        true
    }

    fn data_error() -> Error {
        Error::Codec("lz4_uncompress : data error".into())
    }

    /// A pointer LZ4 may read through for an empty input: an empty slice's is dangling, and
    /// LZ4's high-compression mode reads through it on x86-64 (a segfault).
    fn input(data: &[u8]) -> *const c_char {
        static BYTE: [u8; 1] = [0];
        if data.is_empty() { BYTE.as_ptr().cast() } else { data.as_ptr().cast() }
    }

    /// `lz4_compress($data, $level)`: the input's length as a 4-byte little-endian `int`,
    /// then one block, fast at level 0 and high compression at 1 to 12, into a buffer of
    /// `LZ4_compressBound(len) + 4` bytes of which LZ4 is told one less.
    pub(super) fn compress<S: Sink + ?Sized>(data: &[u8], level: i64, out: &mut S) -> Result<(), Error> {
        // PHP takes the length and the level as C ints.
        let (len, level) = (data.len() as c_int, level as c_int);
        // SAFETY: a pure function (0 for a length LZ4 cannot take).
        let bound = unsafe { l::LZ4_compressBound(len) };
        let size = bound as usize + 4;
        let start = out.len();
        if !out.grow(start + size) {
            return Err(Error::Codec("lz4_compress : memory error".into()));
        }
        let (at, _) = spare(out);
        // SAFETY: room for `size` bytes was made, the prefix's 4 and LZ4's `bound - 1` (plus
        // one); the input covers `data`.
        let written = unsafe {
            at.cast::<[u8; 4]>().write(len.to_le_bytes());
            let block = at.add(4).cast::<c_char>();
            match level {
                0 => l::LZ4_compress_default(input(data), block, len, bound - 1),
                1..=MAX_LEVEL => l::LZ4_compress_HC(input(data), block, len, bound - 1, level),
                _ => {
                    return Err(Error::Codec(format!(
                        "lz4_compress: compression level ({level}) must be within 1..{MAX_LEVEL}"
                    )));
                }
            }
        };
        if written <= 0 {
            return Err(Error::Codec("lz4_compress : data error".into()));
        }
        // SAFETY: LZ4 wrote `written` bytes after the prefix.
        unsafe { out.set_len(start + 4 + written as usize) };
        Ok(())
    }

    /// `lz4_uncompress($data)`: the size prefix, then `LZ4_decompress_safe` into a buffer of
    /// that size. An empty result is a failure too, as in PHP.
    ///
    /// PHP allocates the prefix's size with `malloc()`, outside its memory limit, then copies
    /// the result into a string. The result goes straight into the sink here, which reserves
    /// no more than a block can expand to; when even that is not to be had, it decodes into a
    /// buffer of its own and copies, as PHP does.
    pub(super) fn decompress<S: Sink + ?Sized>(data: &[u8], out: &mut S) -> Result<(), Error> {
        // PHP checks the length as a C int.
        let len = data.len() as c_int;
        let Some(prefix) = data.first_chunk::<4>().filter(|_| len >= 4) else {
            return Err(data_error());
        };
        let size = i32::from_le_bytes(*prefix);
        if size < 0 {
            return Err(Error::Codec("lz4_uncompress : allocate size error".into()));
        }
        let block = &data[4..];
        let capacity = (size as usize).min(block.len().saturating_mul(MAX_RATIO).saturating_add(MARGIN));
        // PHP's `malloc(var_len + 1)`, the sum a C int (so 0x7fffffff asks for a negative size,
        // which fails): its warning, before decoding.
        if capacity < size as usize && !allocatable(size.wrapping_add(1) as isize as usize) {
            return Err(Error::Codec("lz4_uncompress : memory error".into()));
        }
        let decode = |at: *mut u8| {
            // SAFETY: `at` has room for `capacity` bytes; the block covers what follows the
            // prefix, its length as PHP computes it.
            unsafe { l::LZ4_decompress_safe(block.as_ptr().cast(), at.cast(), len - 4, capacity as c_int) }
        };
        let start = out.len();
        if out.reserve(start + capacity) {
            let (at, _) = spare(out);
            let written = decode(at);
            if written <= 0 {
                return Err(data_error());
            }
            // SAFETY: LZ4 wrote `written` bytes into the room.
            unsafe { out.set_len(start + written as usize) };
            return Ok(());
        }
        let mut buffer = Vec::<u8>::new();
        if buffer.try_reserve_exact(capacity).is_err() {
            return Err(Error::Codec("lz4_uncompress : memory error".into()));
        }
        let written = decode(buffer.as_mut_ptr());
        if written <= 0 {
            return Err(data_error());
        }
        // SAFETY: LZ4 wrote `written` bytes into the buffer.
        unsafe { buffer.set_len(written as usize) };
        if !append(out, &buffer) {
            return Err(Error::Codec("lz4_uncompress : memory error".into()));
        }
        Ok(())
    }
}

#[cfg(not(feature = "lz4"))]
mod imp {
    use crate::{Error, Sink};

    pub(super) fn compress<S: Sink + ?Sized>(_: &[u8], _: i64, _: &mut S) -> Result<(), Error> {
        Err(Error::undefined("lz4_compress"))
    }

    pub(super) fn decompress<S: Sink + ?Sized>(_: &[u8], _: &mut S) -> Result<(), Error> {
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
        // High compression of an empty input crashed on x86-64.
        for level in 1..=12 {
            assert_eq!(Lz4::with_level(level).compress(b"").unwrap(), b"\0\0\0\0\0");
        }
        assert_eq!(lz4.decompress(b"\0\0\0\0\0"), Err(Error::Codec("lz4_uncompress : data error".into())));
    }

    #[test]
    fn prefixes() {
        let lz4 = Lz4::new();
        let body = lz4.compress(b"This is a demo string").unwrap();
        assert_eq!(&body[..4], &21u32.to_le_bytes());
        assert_eq!(lz4.decompress(b"abc"), Err(Error::Codec("lz4_uncompress : data error".into())));
        assert_eq!(
            lz4.decompress(b"\xff\xff\xff\xffabc"),
            Err(Error::Codec("lz4_uncompress : allocate size error".into()))
        );
        // A prefix larger than the block: the block's own length decides.
        let mut large = body.clone();
        large[..4].copy_from_slice(&0x0fff_0000u32.to_le_bytes());
        assert_eq!(lz4.decompress(&large).unwrap(), b"This is a demo string");
        // PHP's `var_len + 1` overflows a C int for the largest prefix.
        large[..4].copy_from_slice(&0x7fff_ffffu32.to_le_bytes());
        assert_eq!(lz4.decompress(&large), Err(Error::Codec("lz4_uncompress : memory error".into())));
        // A prefix smaller than the output: LZ4 refuses to write past it.
        let mut small = body;
        small[..4].copy_from_slice(&20u32.to_le_bytes());
        assert_eq!(lz4.decompress(&small), Err(Error::Codec("lz4_uncompress : data error".into())));
        // A level PHP's C int makes 0 is fast mode.
        assert_eq!(Lz4::with_level(1 << 32).compress(b"abc").unwrap(), lz4.compress(b"abc").unwrap());
    }
}
