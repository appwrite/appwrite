//! Brotli.

use std::borrow::Cow;

use crate::{Algorithm, Compression, Error, Sink};

/// The kind of data Brotli tunes its encoding for.
///
/// PHP: `BROTLI_GENERIC`, `BROTLI_TEXT`, `BROTLI_FONT`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum Mode {
    /// Any data (the default).
    #[default]
    Generic,
    /// UTF-8 text.
    Text,
    /// WOFF 2.0 fonts.
    Font,
}

/// Brotli (RFC 7932): `Content-Encoding: br`.
///
/// Compresses at quality [`Brotli::DEFAULT_LEVEL`] (11) unless
/// [`Brotli::set_level`] says otherwise, with a 4 MiB window (`lgwin` 22).
/// Decompressing accepts what `brotli_uncompress()` accepts, including
/// trailing bytes after the stream.
///
/// ```
/// use utopia_compression::{Algorithm, Brotli};
///
/// let mut brotli = Brotli::new();
/// brotli.set_level(4)?;
/// let body = brotli.compress(b"This is a demo string")?;
/// assert_eq!(brotli.decompress(&body)?, b"This is a demo string");
/// assert_eq!(brotli.content_encoding(), "br");
/// # Ok::<(), utopia_compression::Error>(())
/// ```
///
/// ```php
/// use Utopia\Compression\Algorithms\Brotli;
///
/// $brotli = new Brotli();
/// $brotli->setLevel(4);
/// $body = $brotli->compress('This is a demo string');
/// echo $brotli->decompress($body), "\n"; // This is a demo string
/// echo $brotli->getContentEncoding(), "\n"; // br
/// ```
///
/// The codec is C brotli 1.2.1, the library PHP's ext-brotli links, called the way
/// `brotli_compress()` calls it: the output is PHP's, byte for byte, at every quality.
///
/// Requires the `brotli` feature. PHP: `Utopia\Compression\Algorithms\Brotli`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Brotli {
    level: u32,
    mode: Mode,
}

impl Default for Brotli {
    fn default() -> Self {
        Self::new()
    }
}

impl Brotli {
    /// The lowest quality. PHP: `BROTLI_COMPRESS_LEVEL_MIN`.
    pub const MIN_LEVEL: u32 = 0;
    /// The highest quality. PHP: `BROTLI_COMPRESS_LEVEL_MAX`.
    pub const MAX_LEVEL: u32 = 11;
    /// The quality of a new instance. PHP: `BROTLI_COMPRESS_LEVEL_DEFAULT`.
    pub const DEFAULT_LEVEL: u32 = 11;

    /// Brotli at quality 11 in generic mode.
    ///
    /// PHP: `new Brotli()`.
    pub const fn new() -> Self {
        Brotli { level: Self::DEFAULT_LEVEL, mode: Mode::Generic }
    }

    /// The quality, `0` to `11`.
    ///
    /// PHP: `getLevel()`.
    pub const fn level(&self) -> u32 {
        self.level
    }

    /// Sets the quality. Fails with [`Error::InvalidArgument`] outside `0`
    /// to `11`.
    ///
    /// PHP: `setLevel($level)`.
    pub fn set_level(&mut self, level: i64) -> Result<(), Error> {
        if level < i64::from(Self::MIN_LEVEL) || level > i64::from(Self::MAX_LEVEL) {
            return Err(Error::InvalidArgument(format!(
                "Level must be between {} and {}",
                Self::MIN_LEVEL,
                Self::MAX_LEVEL
            )));
        }
        self.level = level as u32;
        Ok(())
    }

    /// Tunes the encoding for any data (the default).
    ///
    /// PHP: `useGenericMode()`.
    pub fn use_generic_mode(&mut self) {
        self.mode = Mode::Generic;
    }

    /// Meant to tune the encoding for UTF-8 text, but keeps generic mode, as
    /// PHP does.
    ///
    /// PHP: `useTextMode()`, which sets `BROTLI_GENERIC` (a quirk kept for
    /// identical output).
    pub fn use_text_mode(&mut self) {
        self.mode = Mode::Generic;
    }

    /// Meant to tune the encoding for WOFF 2.0 fonts, but keeps generic
    /// mode, as PHP does.
    ///
    /// PHP: `useFontMode()`, which sets `BROTLI_GENERIC` (a quirk kept for
    /// identical output).
    pub fn use_font_mode(&mut self) {
        self.mode = Mode::Generic;
    }

    /// The mode the encoder runs in.
    pub const fn mode(&self) -> Mode {
        self.mode
    }

    /// Whether this build has Brotli (the `brotli` feature).
    ///
    /// PHP: `Brotli::isSupported()`, whether `brotli_compress()` exists.
    pub const fn is_supported() -> bool {
        cfg!(feature = "brotli")
    }

    /// [`Algorithm::compress`], appended to `out`.
    pub fn compress_into<S: Sink + ?Sized>(&self, data: &[u8], out: &mut S) -> Result<(), Error> {
        let mode = match self.mode {
            Mode::Generic => 0,
            Mode::Text => 1,
            Mode::Font => 2,
        };
        imp::compress(data, i64::from(self.level), mode, out)
    }

    /// Compresses `data` into `out` at `level` and `mode` (`BROTLI_GENERIC`, `BROTLI_TEXT` or
    /// `BROTLI_FONT`: 0, 1 or 2), unchecked, as `brotli_compress($data, $level, $mode)` takes
    /// them: either out of range fails with [`Error::Codec`] and PHP's warning.
    ///
    /// PHP: `compress()` on a subclass that set `$level` or `$mode` itself.
    pub fn compress_with<S: Sink + ?Sized>(data: &[u8], level: i64, mode: i64, out: &mut S) -> Result<(), Error> {
        imp::compress(data, level, mode, out)
    }

    /// [`Algorithm::decompress`], appended to `out`.
    pub fn decompress_into<S: Sink + ?Sized>(&self, data: &[u8], out: &mut S) -> Result<(), Error> {
        imp::decompress(data, out)
    }
}

impl Algorithm for Brotli {
    fn name(&self) -> &str {
        Compression::BROTLI
    }

    fn content_encoding(&self) -> Cow<'_, str> {
        Cow::Borrowed("br")
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

#[cfg(feature = "brotli")]
mod imp {
    //! ext-brotli's `brotli_compress()` and `brotli_uncompress()`: the same calls into C brotli
    //! 1.2.1 (the library PHP links), each call's output written where it ends up rather than
    //! into a buffer it is then copied out of, its encoder on the sink's allocator
    //! ([`Sink::allocator`]). The decoder stays on `malloc()`: its ring buffer, sized to the
    //! stream, was slower to take from PHP's allocator.

    use brotlic_sys as b;

    use crate::sink::spare;
    use crate::{Error, Sink};

    /// `BROTLI_DEFAULT_WINDOW`, which PHP asks for with an `lgwin` of 0.
    const WINDOW: u32 = b::BROTLI_DEFAULT_WINDOW as u32;

    /// The output chunk of `brotli_uncompress()` (`PHP_BROTLI_BUFFER_SIZE`).
    const CHUNK: usize = 1 << 19;

    /// The output size past which it grows by a quarter rather than a chunk.
    const GEOMETRIC: usize = 8 << 20;

    /// The sink's allocator as brotli takes one; none for brotli's default (`malloc()`).
    fn allocator<S: Sink + ?Sized>(out: &S) -> (b::brotli_alloc_func, b::brotli_free_func, *mut std::ffi::c_void) {
        match out.allocator() {
            Some(allocator) => (Some(allocator.alloc), Some(allocator.free), allocator.opaque),
            None => (None, None, std::ptr::null_mut()),
        }
    }

    /// An encoder, destroyed when dropped.
    struct Encoder(*mut b::BrotliEncoderState);

    impl Drop for Encoder {
        fn drop(&mut self) {
            // SAFETY: created by BrotliEncoderCreateInstance and destroyed only here.
            unsafe { b::BrotliEncoderDestroyInstance(self.0) };
        }
    }

    /// A decoder, destroyed when dropped.
    struct Decoder(*mut b::BrotliDecoderState);

    impl Drop for Decoder {
        fn drop(&mut self) {
            // SAFETY: created by BrotliDecoderCreateInstance and destroyed only here.
            unsafe { b::BrotliDecoderDestroyInstance(self.0) };
        }
    }

    /// `brotli_compress($data, $level, $mode)`: the encoder's parameters checked and set as
    /// `php_brotli_context_create_encoder_ex()` does (a level or mode out of range is PHP's
    /// warning), then `BROTLI_OPERATION_FINISH` over the whole input until the encoder is
    /// done, each call into a window of `max(BrotliEncoderMaxCompressedSize(len), 64)` bytes.
    pub(super) fn compress<S: Sink + ?Sized>(data: &[u8], level: i64, mode: i64, out: &mut S) -> Result<(), Error> {
        // SAFETY: a pure function.
        let window = unsafe { b::BrotliEncoderMaxCompressedSize(data.len()) }.max(64);
        let start = out.len();
        let (alloc, free, opaque) = allocator(out);
        // SAFETY: creates an encoder on the sink's allocator (or the default), or returns null.
        let encoder = Encoder(unsafe { b::BrotliEncoderCreateInstance(alloc, free, opaque) });
        if encoder.0.is_null() {
            return Err(Error::Codec("brotli_compress(): failed to prepare compression".into()));
        }
        if !(0..=11).contains(&level) {
            return Err(Error::Codec(format!(
                "brotli_compress(): failed to compression level ({level}): must be within 0..11"
            )));
        }
        if !(0..=2).contains(&mode) {
            return Err(Error::Codec(format!(
                "brotli_compress(): failed to compression mode ({mode}): must be BROTLI_GENERIC(0)|BROTLI_TEXT(1)|BROTLI_FONT(2)"
            )));
        }
        // SAFETY: a live encoder.
        let set = unsafe {
            b::BrotliEncoderSetParameter(encoder.0, b::BrotliEncoderParameter_BROTLI_PARAM_QUALITY, level as u32) != 0
                && b::BrotliEncoderSetParameter(encoder.0, b::BrotliEncoderParameter_BROTLI_PARAM_LGWIN, WINDOW) != 0
                && b::BrotliEncoderSetParameter(encoder.0, b::BrotliEncoderParameter_BROTLI_PARAM_MODE, mode as u32)
                    != 0
        };
        if !set {
            return Err(Error::Codec("brotli_compress(): failed to set compression parameters".into()));
        }
        let (mut available_in, mut next_in) = (data.len(), data.as_ptr());
        // SAFETY: a live encoder.
        while unsafe { b::BrotliEncoderIsFinished(encoder.0) } == 0 {
            let len = out.len();
            if !out.grow((len + window).max(if len == start { 0 } else { out.capacity() + out.capacity() / 2 })) {
                return Err(Error::Codec("brotli_compress(): insufficient memory".into()));
            }
            let (at, _) = spare(out);
            let (mut available_out, mut next_out) = (window, at);
            // SAFETY: the encoder is live; the input covers what is left of `data` and the
            // output `window` bytes of room in `out`.
            let ok = unsafe {
                b::BrotliEncoderCompressStream(
                    encoder.0,
                    b::BrotliEncoderOperation_BROTLI_OPERATION_FINISH,
                    &raw mut available_in,
                    &raw mut next_in,
                    &raw mut available_out,
                    &raw mut next_out,
                    std::ptr::null_mut(),
                )
            };
            if ok == 0 {
                return Err(Error::Codec("brotli_compress(): failed to compress".into()));
            }
            // SAFETY: the encoder wrote `window - available_out` bytes into the room.
            unsafe { out.set_len(len + (window - available_out)) };
        }
        Ok(())
    }

    /// `brotli_uncompress()`: a decoder that accepts large windows, run while it needs more
    /// output; anything but success (truncated or corrupt input) is a failure. The output is
    /// written in place, grown as [`GEOMETRIC`] says.
    pub(super) fn decompress<S: Sink + ?Sized>(data: &[u8], out: &mut S) -> Result<(), Error> {
        let failed = || Error::Codec("brotli_uncompress(): failed to uncompress".into());
        // SAFETY: creates a decoder with brotli's default allocator, or returns null.
        let decoder = Decoder(unsafe { b::BrotliDecoderCreateInstance(None, None, std::ptr::null_mut()) });
        if decoder.0.is_null() {
            return Err(Error::Codec("brotli_uncompress(): failed to prepare uncompression".into()));
        }
        // SAFETY: a live decoder.
        if unsafe {
            b::BrotliDecoderSetParameter(decoder.0, b::BrotliDecoderParameter_BROTLI_DECODER_PARAM_LARGE_WINDOW, 1)
        } == 0
        {
            return Err(Error::Codec("brotli_uncompress(): failed to set uncompression parameters".into()));
        }
        let (mut available_in, mut next_in) = (data.len(), data.as_ptr());
        let mut first = CHUNK.min(data.len().saturating_mul(4).max(1024));
        loop {
            let (len, capacity) = (out.len(), out.capacity());
            // 512 KiB at a time, as PHP grows its output, up to 8 MiB; then by a quarter, so a
            // large output is copied a bounded number of times when the block cannot be
            // extended in place.
            let wanted = if capacity < GEOMETRIC { len + first } else { (len + first).max(capacity + capacity / 4) };
            if len + first > capacity && !out.grow(wanted) {
                return Err(Error::Codec("brotli_uncompress(): insufficient memory".into()));
            }
            first = CHUNK;
            let (at, room) = spare(out);
            let (mut available_out, mut next_out) = (room, at);
            // SAFETY: the decoder is live; the input covers what is left of `data` and the
            // output the room in `out`.
            let result = unsafe {
                b::BrotliDecoderDecompressStream(
                    decoder.0,
                    &raw mut available_in,
                    &raw mut next_in,
                    &raw mut available_out,
                    &raw mut next_out,
                    std::ptr::null_mut(),
                )
            };
            // SAFETY: the decoder wrote `room - available_out` bytes into the room.
            unsafe { out.set_len(len + (room - available_out)) };
            match result {
                b::BrotliDecoderResult_BROTLI_DECODER_RESULT_NEEDS_MORE_OUTPUT => {}
                b::BrotliDecoderResult_BROTLI_DECODER_RESULT_SUCCESS => return Ok(()),
                _ => return Err(failed()),
            }
        }
    }
}

#[cfg(not(feature = "brotli"))]
mod imp {
    use crate::{Error, Sink};

    pub(super) fn compress<S: Sink + ?Sized>(_: &[u8], _: i64, _: i64, _: &mut S) -> Result<(), Error> {
        Err(Error::undefined("brotli_compress"))
    }

    pub(super) fn decompress<S: Sink + ?Sized>(_: &[u8], _: &mut S) -> Result<(), Error> {
        Err(Error::undefined("brotli_uncompress"))
    }
}

#[cfg(all(test, feature = "brotli"))]
mod tests {
    use super::*;

    #[test]
    fn levels() {
        let mut brotli = Brotli::new();
        assert_eq!(brotli.set_level(-1), Err(Error::InvalidArgument("Level must be between 0 and 11".into())));
        assert_eq!(brotli.set_level(12), Err(Error::InvalidArgument("Level must be between 0 and 11".into())));
        let data = b"Lorem ipsum dolor sit amet ".repeat(500);
        for level in 0..=11 {
            brotli.set_level(level).unwrap();
            assert_eq!(brotli.decompress(&brotli.compress(&data).unwrap()).unwrap(), data);
        }
    }

    #[test]
    fn modes_stay_generic() {
        let mut brotli = Brotli::new();
        brotli.use_text_mode();
        assert_eq!(brotli.mode(), Mode::Generic);
        brotli.use_font_mode();
        assert_eq!(brotli.mode(), Mode::Generic);
    }

    #[test]
    fn invalid_data() {
        let failed = Err(Error::Codec("brotli_uncompress(): failed to uncompress".into()));
        assert_eq!(Brotli::new().decompress(b""), failed);
        assert_eq!(Brotli::new().decompress(b"garbage"), failed);
        let body = Brotli::new().compress(&b"Lorem ipsum ".repeat(1000)).unwrap();
        assert_eq!(Brotli::new().decompress(&body[..body.len() - 1]), failed);
    }

    #[test]
    fn unchecked_parameters() {
        let mut out = Vec::new();
        assert_eq!(
            Brotli::compress_with(b"x", 12, 0, &mut out),
            Err(Error::Codec("brotli_compress(): failed to compression level (12): must be within 0..11".into()))
        );
        assert_eq!(
            Brotli::compress_with(b"x", 4, 3, &mut out),
            Err(Error::Codec(
                "brotli_compress(): failed to compression mode (3): must be BROTLI_GENERIC(0)|BROTLI_TEXT(1)|BROTLI_FONT(2)"
                    .into()
            ))
        );
        Brotli::compress_with(b"text", 4, 1, &mut out).unwrap();
        assert_eq!(Brotli::new().decompress(&out).unwrap(), b"text");
    }

    #[test]
    fn large_output() {
        // More than one 512 KiB chunk out of a small input.
        let data = vec![b'a'; 3 << 20];
        let body = Brotli::new().compress(&data).unwrap();
        assert!(body.len() < 1000);
        assert_eq!(Brotli::new().decompress(&body).unwrap(), data);
    }
}
