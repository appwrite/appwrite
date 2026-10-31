//! Deflate and GZIP on zlib, called exactly as PHP's ext-zlib calls it.

use crate::{Algorithm, Compression, Error};

/// Raw deflate (RFC 1951) at level 6: `Content-Encoding: deflate`.
///
/// Output is byte for byte PHP's `gzdeflate($data, 6)`: zlib 1.3.2 at
/// memory level 9. Decompressing accepts what `gzinflate()` accepts,
/// including trailing bytes after the stream, which are ignored.
///
/// ```
/// use utopia_compression::{Algorithm, Deflate};
///
/// let deflate = Deflate::new();
/// assert_eq!(deflate.compress(b"a")?, b"\x4b\x04\x00");
/// assert_eq!(deflate.decompress(b"\x4b\x04\x00")?, b"a");
/// # Ok::<(), utopia_compression::Error>(())
/// ```
///
/// ```php
/// use Utopia\Compression\Algorithms\Deflate;
///
/// $deflate = new Deflate();
/// echo bin2hex($deflate->compress('a')), "\n"; // 4b0400
/// echo $deflate->decompress("\x4b\x04\x00"), "\n"; // a
/// ```
///
/// Requires the `zlib` feature. PHP: `Utopia\Compression\Algorithms\Deflate`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct Deflate;

impl Deflate {
    /// Raw deflate at level 6.
    ///
    /// PHP: `new Deflate()`.
    pub const fn new() -> Self {
        Deflate
    }

    /// Whether this build has zlib (the `zlib` feature).
    ///
    /// PHP: `Deflate::isSupported()`, whether `gzdeflate()` exists.
    pub const fn is_supported() -> bool {
        cfg!(feature = "zlib")
    }
}

impl Algorithm for Deflate {
    fn name(&self) -> &str {
        Compression::DEFLATE
    }

    fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        imp::encode(data, 6, imp::RAW, "gzdeflate")
    }

    fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        imp::decode(data, imp::RAW, "gzinflate")
    }

    fn supported(&self) -> bool {
        Self::is_supported()
    }
}

/// Gzip (RFC 1952) at zlib's default level: `Content-Encoding: gzip`.
///
/// Output is byte for byte PHP's `gzencode($data)`: a header with no name,
/// a modification time of 0 and the Unix OS byte (3), then zlib 1.3.2's
/// deflate at level 6 and memory level 9. Decompressing reads the first
/// member, like `gzdecode()`.
///
/// ```
/// use utopia_compression::{Algorithm, Gzip};
///
/// let gzip = Gzip::new();
/// let body = gzip.compress(b"a")?;
/// assert_eq!(&body[..10], b"\x1f\x8b\x08\x00\x00\x00\x00\x00\x00\x03");
/// assert_eq!(gzip.decompress(&body)?, b"a");
/// # Ok::<(), utopia_compression::Error>(())
/// ```
///
/// ```php
/// use Utopia\Compression\Algorithms\GZIP;
///
/// $gzip = new GZIP();
/// $body = $gzip->compress('a');
/// echo bin2hex(substr($body, 0, 10)), "\n"; // 1f8b0800000000000003
/// echo $gzip->decompress($body), "\n"; // a
/// ```
///
/// Requires the `zlib` feature. PHP: `Utopia\Compression\Algorithms\GZIP`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct Gzip;

impl Gzip {
    /// Gzip at zlib's default level.
    ///
    /// PHP: `new GZIP()`.
    pub const fn new() -> Self {
        Gzip
    }

    /// Whether this build has zlib (the `zlib` feature).
    ///
    /// PHP: `GZIP::isSupported()`, whether `gzencode()` exists.
    pub const fn is_supported() -> bool {
        cfg!(feature = "zlib")
    }
}

impl Algorithm for Gzip {
    fn name(&self) -> &str {
        Compression::GZIP
    }

    fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        imp::encode(data, -1, imp::GZIP, "gzencode")
    }

    fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        imp::decode(data, imp::GZIP, "gzdecode")
    }

    fn supported(&self) -> bool {
        Self::is_supported()
    }
}

#[cfg(feature = "zlib")]
mod imp {
    //! The zlib calls of PHP's `php_zlib_encode()` and `php_zlib_decode()`
    //! (ext/zlib/zlib.c).
    //!
    //! This is the crate's only `unsafe`: zlib's deflate at memory level 9 is
    //! what PHP writes, and no safe binding exposes the memory level (flate2
    //! hardcodes 8, which changes the output). The stream lives in a
    //! `MaybeUninit` that is only ever handed to zlib by pointer, so the null
    //! allocator pointers zlib expects (to use its defaults) never become a
    //! Rust value.

    use std::mem::MaybeUninit;
    use std::os::raw::c_int;

    use libz_sys as z;

    use crate::Error;

    /// `ZLIB_ENCODING_RAW`: raw deflate, no header.
    pub(super) const RAW: c_int = -15;
    /// `ZLIB_ENCODING_GZIP`: a gzip header and trailer.
    pub(super) const GZIP: c_int = 31;

    /// The terminating NUL of a PHP string, which PHP passes to inflate.
    static NUL: [u8; 1] = [0];

    /// `MAX_MEM_LEVEL`, the memory level PHP deflates with.
    const MEM_LEVEL: c_int = 9;

    /// zlib's `zError()`.
    fn message(status: c_int) -> &'static str {
        match status {
            z::Z_NEED_DICT => "need dictionary",
            z::Z_STREAM_END => "stream end",
            z::Z_ERRNO => "file error",
            z::Z_STREAM_ERROR => "stream error",
            z::Z_DATA_ERROR => "data error",
            z::Z_MEM_ERROR => "insufficient memory",
            z::Z_BUF_ERROR => "buffer error",
            z::Z_VERSION_ERROR => "incompatible version",
            _ => "",
        }
    }

    /// PHP's warning: `gzinflate(): data error`.
    fn codec(function: &str, status: c_int) -> Error {
        Error::Codec(format!("{function}(): {}", message(status)))
    }

    /// `php_zlib_encode()`: one `deflate(Z_FINISH)` into a buffer of
    /// `PHP_ZLIB_BUFFER_SIZE_GUESS(len)` bytes.
    pub(super) fn encode(data: &[u8], level: c_int, encoding: c_int, function: &str) -> Result<Vec<u8>, Error> {
        let capacity = (data.len() as f64 * 1.015) as usize + 10 + 8 + 4 + 1;
        let mut out = Vec::<u8>::with_capacity(capacity);
        let mut stream = MaybeUninit::<z::z_stream>::zeroed();
        let s = stream.as_mut_ptr();
        // SAFETY: `s` points to a zeroed z_stream, which deflateInit2_
        // initialises (null zalloc/zfree/opaque select zlib's allocator).
        // next_in covers `data` (zlib only reads it) and next_out the spare
        // capacity of `out`; both outlive the calls. total_out bytes were
        // written into `out` when deflate returns, so set_len is in bounds.
        // deflateEnd frees the state deflateInit2_ allocated.
        unsafe {
            let status = z::deflateInit2_(
                s,
                level,
                z::Z_DEFLATED,
                encoding,
                MEM_LEVEL,
                z::Z_DEFAULT_STRATEGY,
                z::zlibVersion(),
                std::mem::size_of::<z::z_stream>() as c_int,
            );
            if status != z::Z_OK {
                return Err(codec(function, status));
            }
            (&raw mut (*s).next_in).write(data.as_ptr().cast_mut());
            (&raw mut (*s).avail_in).write(data.len() as z::uInt);
            (&raw mut (*s).next_out).write(out.as_mut_ptr());
            (&raw mut (*s).avail_out).write(capacity as z::uInt);
            let status = z::deflate(s, z::Z_FINISH);
            let written = (&raw const (*s).total_out).read() as usize;
            z::deflateEnd(s);
            if status != z::Z_STREAM_END {
                return Err(codec(function, status));
            }
            out.set_len(written.min(capacity));
        }
        // zlib writes the OS of the build into the gzip header (19 on macOS,
        // 10 on Windows); PHP runs on Linux, which writes 3 (Unix).
        if encoding == GZIP
            && let Some(os) = out.get_mut(9)
        {
            *os = 3;
        }
        Ok(out)
    }

    /// `php_zlib_decode()` and `php_zlib_inflate_rounds()`: the input with
    /// its terminating NUL, inflated into a buffer that starts at the input's
    /// size and grows by an eighth, for at most 100 rounds.
    ///
    /// PHP hands zlib the string's terminating NUL too. Rust feeds it as a
    /// second buffer once the data is used up, within the same round: inflate
    /// streams, so the output and status are those of one contiguous input.
    pub(super) fn decode(data: &[u8], encoding: c_int, function: &str) -> Result<Vec<u8>, Error> {
        if data.is_empty() {
            return Err(codec(function, z::Z_DATA_ERROR));
        }
        // Only `avail_out` follows PHP's buffer sizes; the allocation grows
        // geometrically, so the rounds do not copy the output again and again.
        let mut out = Vec::<u8>::with_capacity(data.len().saturating_mul(4));
        let mut stream = MaybeUninit::<z::z_stream>::zeroed();
        let s = stream.as_mut_ptr();
        // SAFETY: as in `encode`: the stream is initialised by inflateInit2_,
        // next_in covers `data` or NUL and next_out the spare capacity of `out`
        // (reserved to `size` bytes before each call); `used` only grows by
        // what inflate reports writing, so set_len stays within what was
        // written; inflateEnd frees the state.
        let status = unsafe {
            let status = z::inflateInit2_(s, encoding, z::zlibVersion(), std::mem::size_of::<z::z_stream>() as c_int);
            if status != z::Z_OK {
                return Err(codec(function, status));
            }
            (&raw mut (*s).next_in).write(data.as_ptr().cast_mut());
            (&raw mut (*s).avail_in).write(data.len() as z::uInt);
            let mut nul = true;
            let feed_nul = |s: *mut z::z_stream| {
                (&raw mut (*s).next_in).write(NUL.as_ptr().cast_mut());
                (&raw mut (*s).avail_in).write(1);
            };

            let mut size = data.len() + 1;
            let mut round = 0;
            let status = loop {
                let used = out.len();
                out.reserve(size - used);
                let free = size - used;
                (&raw mut (*s).next_out).write(out.as_mut_ptr().add(used));
                (&raw mut (*s).avail_out).write(free as z::uInt);
                if nul && (&raw const (*s).avail_in).read() == 0 {
                    feed_nul(s);
                    nul = false;
                }
                let mut status = z::inflate(s, z::Z_NO_FLUSH);
                if nul
                    && status == z::Z_OK
                    && (&raw const (*s).avail_in).read() == 0
                    && (&raw const (*s).avail_out).read() != 0
                {
                    feed_nul(s);
                    nul = false;
                    status = z::inflate(s, z::Z_NO_FLUSH);
                }
                let left = (&raw const (*s).avail_out).read() as usize;
                out.set_len(used + (free - left));
                size += (size >> 3) + 1;
                round += 1;
                let more =
                    status == z::Z_BUF_ERROR || (status == z::Z_OK && ((&raw const (*s).avail_in).read() != 0 || nul));
                if !(more && round < 100) {
                    break status;
                }
            };
            z::inflateEnd(s);
            status
        };
        match status {
            z::Z_STREAM_END => Ok(out),
            // zpipe.c's rule: a stream that stops early is a data error.
            z::Z_OK => Err(codec(function, z::Z_DATA_ERROR)),
            status => Err(codec(function, status)),
        }
    }
}

#[cfg(not(feature = "zlib"))]
mod imp {
    use crate::Error;

    pub(super) const RAW: i32 = -15;
    pub(super) const GZIP: i32 = 31;

    pub(super) fn encode(_: &[u8], _: i32, _: i32, function: &str) -> Result<Vec<u8>, Error> {
        Err(Error::undefined(function))
    }

    pub(super) fn decode(_: &[u8], _: i32, function: &str) -> Result<Vec<u8>, Error> {
        Err(Error::undefined(function))
    }
}

#[cfg(all(test, feature = "zlib"))]
mod tests {
    use super::*;

    #[test]
    fn round_trips() {
        let data = b"hello hello hello hello".repeat(100);
        for algorithm in [&Deflate as &dyn Algorithm, &Gzip] {
            let compressed = algorithm.compress(&data).unwrap();
            assert!(compressed.len() < data.len());
            assert_eq!(algorithm.decompress(&compressed).unwrap(), data);
        }
    }

    #[test]
    fn invalid_data() {
        assert_eq!(Deflate.decompress(b""), Err(Error::Codec("gzinflate(): data error".into())));
        assert_eq!(Gzip.decompress(b"garbage"), Err(Error::Codec("gzdecode(): data error".into())));
    }
}
