//! Deflate and GZIP on zlib, called exactly as PHP's ext-zlib calls it.

use crate::{Algorithm, Compression, Error, Sink};

/// Raw deflate (RFC 1951) at level 6: `Content-Encoding: deflate`.
///
/// Output is byte for byte PHP's `gzdeflate($data, 6)`: the system's zlib (the one PHP links)
/// at memory level 9. Decompressing accepts what `gzinflate()` accepts, including trailing
/// bytes after the stream, which are ignored.
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

    /// [`Algorithm::compress`], appended to `out`.
    pub fn compress_into<S: Sink + ?Sized>(&self, data: &[u8], out: &mut S) -> Result<(), Error> {
        imp::encode(data, 6, imp::RAW, "gzdeflate", out)
    }

    /// [`Algorithm::decompress`], appended to `out`.
    pub fn decompress_into<S: Sink + ?Sized>(&self, data: &[u8], out: &mut S) -> Result<(), Error> {
        imp::decode(data, imp::RAW, "gzinflate", out)
    }
}

impl Algorithm for Deflate {
    fn name(&self) -> &str {
        Compression::DEFLATE
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

/// Gzip (RFC 1952) at zlib's default level: `Content-Encoding: gzip`.
///
/// Output is byte for byte PHP's `gzencode($data)`: a header with no name, a modification
/// time of 0 and the Unix OS byte (3), then the system zlib's deflate at level 6 and memory
/// level 9. Decompressing reads the first member, like `gzdecode()`.
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

    /// [`Algorithm::compress`], appended to `out`.
    pub fn compress_into<S: Sink + ?Sized>(&self, data: &[u8], out: &mut S) -> Result<(), Error> {
        imp::encode(data, -1, imp::GZIP, "gzencode", out)
    }

    /// [`Algorithm::decompress`], appended to `out`.
    pub fn decompress_into<S: Sink + ?Sized>(&self, data: &[u8], out: &mut S) -> Result<(), Error> {
        imp::decode(data, imp::GZIP, "gzdecode", out)
    }
}

impl Algorithm for Gzip {
    fn name(&self) -> &str {
        Compression::GZIP
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

#[cfg(feature = "zlib")]
mod imp {
    //! The zlib calls of PHP's `php_zlib_encode()` and `php_zlib_decode()` (ext/zlib/zlib.c),
    //! with the same buffer sizes, writing straight into the sink.
    //!
    //! zlib's deflate at memory level 9 is what PHP writes, and no safe binding exposes the
    //! memory level (flate2 hardcodes 8, which changes the output). A stream lives boxed in a
    //! `MaybeUninit` that is only ever handed to zlib by pointer, so the null allocator
    //! pointers zlib expects (to use its defaults) never become a Rust value; zlib keeps a
    //! pointer back to it, so it never moves.
    //!
    //! PHP sets up a stream for every call (about 330 KiB of state for deflate, all of it
    //! allocated and initialised again). Here each thread keeps one stream of each kind and
    //! resets it, which zlib defines as the state `deflateInit2()` leaves: the output is the
    //! same.

    use std::cell::Cell;
    use std::mem::MaybeUninit;
    use std::os::raw::c_int;
    use std::thread::LocalKey;

    use libz_sys as z;

    use crate::sink::spare;
    use crate::{Error, Sink};

    /// `ZLIB_ENCODING_RAW`: raw deflate, no header.
    pub(super) const RAW: c_int = -15;
    /// `ZLIB_ENCODING_GZIP`: a gzip header and trailer.
    pub(super) const GZIP: c_int = 31;

    /// The terminating NUL of a PHP string, which PHP passes to inflate.
    static NUL: [u8; 1] = [0];

    /// `MAX_MEM_LEVEL`, the memory level PHP deflates with.
    const MEM_LEVEL: c_int = 9;

    /// The most deflate expands: 1,032 output bytes per input byte (a run of 258 in under two
    /// bits), so a gzip trailer claiming more is not believed.
    const MAX_RATIO: usize = 1032;

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

    /// A deflate or inflate stream of zlib, set up for one encoding.
    struct Stream {
        raw: Box<MaybeUninit<z::z_stream>>,
        inflate: bool,
    }

    impl Stream {
        fn ptr(&mut self) -> *mut z::z_stream {
            self.raw.as_mut_ptr()
        }
    }

    impl Drop for Stream {
        fn drop(&mut self) {
            let s = self.ptr();
            // SAFETY: a stream is only built once deflateInit2_ or inflateInit2_ succeeded; the
            // matching End frees its state.
            unsafe {
                if self.inflate {
                    z::inflateEnd(s);
                } else {
                    z::deflateEnd(s);
                }
            }
        }
    }

    thread_local! {
        static DEFLATE_RAW: Cell<Option<Stream>> = const { Cell::new(None) };
        static DEFLATE_GZIP: Cell<Option<Stream>> = const { Cell::new(None) };
        static INFLATE_RAW: Cell<Option<Stream>> = const { Cell::new(None) };
        static INFLATE_GZIP: Cell<Option<Stream>> = const { Cell::new(None) };
    }

    /// The thread's slot for the stream of `encoding`. A stream is taken out of it while in
    /// use, so a call that never puts it back (one PHP abandons on a fatal error) only leaves
    /// the next call to set up a new one.
    fn slot(inflate: bool, encoding: c_int) -> &'static LocalKey<Cell<Option<Stream>>> {
        match (inflate, encoding == GZIP) {
            (false, false) => &DEFLATE_RAW,
            (false, true) => &DEFLATE_GZIP,
            (true, false) => &INFLATE_RAW,
            (true, true) => &INFLATE_GZIP,
        }
    }

    /// A deflate stream at `level` for `encoding`, as `deflateInit2()` leaves it. Each
    /// encoding is only ever deflated at one level, so a kept stream already has it.
    fn deflater(level: c_int, encoding: c_int) -> Result<Stream, c_int> {
        if let Some(mut stream) = slot(false, encoding).with(Cell::take) {
            // SAFETY: an initialised deflate stream.
            if unsafe { z::deflateReset(stream.ptr()) } == z::Z_OK {
                return Ok(stream);
            }
        }
        let mut raw = Box::new(MaybeUninit::<z::z_stream>::zeroed());
        // SAFETY: a zeroed z_stream, which deflateInit2_ initialises (null zalloc/zfree/opaque
        // select zlib's allocator).
        let status = unsafe {
            z::deflateInit2_(
                raw.as_mut_ptr(),
                level,
                z::Z_DEFLATED,
                encoding,
                MEM_LEVEL,
                z::Z_DEFAULT_STRATEGY,
                z::zlibVersion(),
                std::mem::size_of::<z::z_stream>() as c_int,
            )
        };
        if status != z::Z_OK {
            return Err(status);
        }
        Ok(Stream { raw, inflate: false })
    }

    /// An inflate stream for `encoding`, as `inflateInit2()` leaves it.
    fn inflater(encoding: c_int) -> Result<Stream, c_int> {
        if let Some(mut stream) = slot(true, encoding).with(Cell::take) {
            // SAFETY: an initialised inflate stream.
            if unsafe { z::inflateReset(stream.ptr()) } == z::Z_OK {
                return Ok(stream);
            }
        }
        let mut raw = Box::new(MaybeUninit::<z::z_stream>::zeroed());
        // SAFETY: as in `deflater`.
        let status = unsafe {
            z::inflateInit2_(raw.as_mut_ptr(), encoding, z::zlibVersion(), std::mem::size_of::<z::z_stream>() as c_int)
        };
        if status != z::Z_OK {
            return Err(status);
        }
        Ok(Stream { raw, inflate: true })
    }

    /// Puts `stream` back for the thread's next call.
    fn keep(stream: Stream, encoding: c_int) {
        slot(stream.inflate, encoding).with(|slot| slot.set(Some(stream)));
    }

    /// `php_zlib_encode()`: one `deflate(Z_FINISH)` into a buffer of
    /// `PHP_ZLIB_BUFFER_SIZE_GUESS(len)` bytes.
    pub(super) fn encode<S: Sink + ?Sized>(
        data: &[u8],
        level: c_int,
        encoding: c_int,
        function: &str,
        out: &mut S,
    ) -> Result<(), Error> {
        let start = out.len();
        let guess = (data.len() as f64 * 1.015) as usize + 10 + 8 + 4 + 1;
        // The output is allocated before the stream is taken, as PHP allocates it.
        if !out.grow(start + guess) {
            return Err(codec(function, z::Z_MEM_ERROR));
        }
        let mut stream = deflater(level, encoding).map_err(|status| codec(function, status))?;
        let (at, _) = spare(out);
        let s = stream.ptr();
        // SAFETY: the stream is initialised; next_in covers `data` (zlib only reads it) and
        // next_out the `guess` bytes of room made in `out`; both outlive the call. PHP hands
        // zlib its sizes as `uInt`, truncating them the same way.
        let (status, written) = unsafe {
            (&raw mut (*s).next_in).write(data.as_ptr().cast_mut());
            (&raw mut (*s).avail_in).write(data.len() as z::uInt);
            (&raw mut (*s).next_out).write(at);
            (&raw mut (*s).avail_out).write(guess as z::uInt);
            let status = z::deflate(s, z::Z_FINISH);
            (status, (&raw const (*s).total_out).read() as usize)
        };
        keep(stream, encoding);
        if status != z::Z_STREAM_END {
            return Err(codec(function, status));
        }
        // SAFETY: deflate wrote `written` bytes (at most `guess`) after the `start` held.
        unsafe { out.set_len(start + written.min(guess)) };
        // zlib writes the OS of the build into the gzip header (19 on macOS, 10 on Windows);
        // PHP runs on Linux, which writes 3 (Unix).
        if encoding == GZIP && written > 9 {
            // SAFETY: byte 9 of the header was just written.
            unsafe { *out.as_mut_ptr().add(start + 9) = 3 };
        }
        Ok(())
    }

    /// `php_zlib_decode()` and `php_zlib_inflate_rounds()`: the input with its terminating
    /// NUL, inflated into a buffer that starts at the input's size and grows by an eighth, for
    /// at most 100 rounds.
    ///
    /// Each round hands zlib PHP's buffer size, so the rounds, the output and the status are
    /// PHP's. Only the allocation differs: a gzip trailer that gives the size reserves it at
    /// once (then grows as PHP's does), and without one growing goes by halves rather than
    /// eighths, so a large output is not copied again and again.
    ///
    /// PHP hands zlib the string's terminating NUL too. Rust feeds it as a second buffer once
    /// the data is used up, within the same round: inflate streams, so the output and status
    /// are those of one contiguous input.
    pub(super) fn decode<S: Sink + ?Sized>(
        data: &[u8],
        encoding: c_int,
        function: &str,
        out: &mut S,
    ) -> Result<(), Error> {
        if data.is_empty() {
            return Err(codec(function, z::Z_DATA_ERROR));
        }
        let start = out.len();
        // ISIZE, the length of the member a gzip stream ends with: when it is believable, the
        // whole output fits at once.
        let mut hinted = false;
        if encoding == GZIP
            && data.len() >= 18
            && let Some(trailer) = data.last_chunk::<4>()
        {
            let size = u32::from_le_bytes(*trailer) as usize;
            if size <= data.len().saturating_mul(MAX_RATIO) {
                hinted = out.reserve(start + size.max(data.len() + 1));
            }
        }
        let mut stream = inflater(encoding).map_err(|status| codec(function, status))?;
        let s = stream.ptr();
        // SAFETY: the stream is initialised; next_in covers `data` or NUL and next_out the room
        // made in `out` before each call; `used` only grows by what inflate reports writing, so
        // set_len stays within what was written.
        let status = unsafe {
            (&raw mut (*s).next_in).write(data.as_ptr().cast_mut());
            (&raw mut (*s).avail_in).write(data.len() as z::uInt);
            let mut nul = true;
            let feed_nul = |s: *mut z::z_stream| {
                (&raw mut (*s).next_in).write(NUL.as_ptr().cast_mut());
                (&raw mut (*s).avail_in).write(1);
            };

            let mut size = data.len() + 1;
            let mut used = 0;
            let mut round = 0;
            loop {
                // Within a trailer's size, a round gets the room left when PHP's would be larger:
                // inflate streams, so it only stops sooner, and a full buffer gets another round
                // (past the trailer's size, as PHP's buffer would have had room). Without one,
                // growing goes by half, so a large output is copied less often.
                let capacity = out.capacity();
                let mut free = (size - used) as z::uInt;
                let mut capped = false;
                if start + size > capacity {
                    if hinted && capacity > start + used {
                        free = (capacity - start - used) as z::uInt;
                        capped = true;
                    } else if !out.grow((start + size).max(capacity + capacity / 2)) {
                        break z::Z_MEM_ERROR;
                    }
                }
                let (at, _) = spare(out);
                (&raw mut (*s).next_out).write(at);
                (&raw mut (*s).avail_out).write(free);
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
                used += (free - (&raw const (*s).avail_out).read()) as usize;
                out.set_len(start + used);
                if capped && (&raw const (*s).avail_out).read() == 0 && matches!(status, z::Z_OK | z::Z_BUF_ERROR) {
                    hinted = false;
                    continue;
                }
                size += (size >> 3) + 1;
                round += 1;
                let more =
                    status == z::Z_BUF_ERROR || (status == z::Z_OK && ((&raw const (*s).avail_in).read() != 0 || nul));
                if !(more && round < 100) {
                    break status;
                }
            }
        };
        keep(stream, encoding);
        match status {
            z::Z_STREAM_END => Ok(()),
            // zpipe.c's rule: a stream that stops early is a data error.
            z::Z_OK => Err(codec(function, z::Z_DATA_ERROR)),
            status => Err(codec(function, status)),
        }
    }
}

#[cfg(not(feature = "zlib"))]
mod imp {
    use crate::{Error, Sink};

    pub(super) const RAW: i32 = -15;
    pub(super) const GZIP: i32 = 31;

    pub(super) fn encode<S: Sink + ?Sized>(_: &[u8], _: i32, _: i32, function: &str, _: &mut S) -> Result<(), Error> {
        Err(Error::undefined(function))
    }

    pub(super) fn decode<S: Sink + ?Sized>(_: &[u8], _: i32, function: &str, _: &mut S) -> Result<(), Error> {
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
            // Again, on the thread's kept streams.
            assert_eq!(algorithm.compress(&data).unwrap(), compressed);
            assert_eq!(algorithm.decompress(&compressed).unwrap(), data);
        }
    }

    #[test]
    fn appends() {
        let mut out = b"prefix".to_vec();
        Gzip.compress_into(b"abc", &mut out).unwrap();
        assert_eq!(&out[..6], b"prefix");
        assert_eq!(out[6 + 9], 3);
        let mut plain = b"x".to_vec();
        Gzip.decompress_into(&out[6..], &mut plain).unwrap();
        assert_eq!(plain, b"xabc");
    }

    #[test]
    fn invalid_data() {
        assert_eq!(Deflate.decompress(b""), Err(Error::Codec("gzinflate(): data error".into())));
        assert_eq!(Gzip.decompress(b"garbage"), Err(Error::Codec("gzdecode(): data error".into())));
        // The trailer's size is only a guess at the room needed: a wrong one still fails the
        // member's check, and a right one leaves the output as it was.
        let data = b"abc".repeat(1000);
        let mut body = Gzip.compress(&data).unwrap();
        assert_eq!(Gzip.decompress(&body).unwrap(), data);
        let len = body.len();
        body[len - 4..].copy_from_slice(&7u32.to_le_bytes());
        assert_eq!(Gzip.decompress(&body), Err(Error::Codec("gzdecode(): data error".into())));
    }
}
