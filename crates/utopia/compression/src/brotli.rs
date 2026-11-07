//! Brotli.

use std::borrow::Cow;

use crate::{Algorithm, Compression, Error};

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
/// The encoder is the `brotli` crate, a Rust port of Google's C library. Its
/// output is PHP's at qualities 0 and 1, and for many inputs at the other
/// qualities, but PHP's ext-brotli links C brotli 1.2.0, whose encoder makes
/// different choices at qualities 2 to 11 on some inputs: both runtimes read
/// each other's output, but it is not always the same bytes.
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
}

impl Algorithm for Brotli {
    fn name(&self) -> &str {
        Compression::BROTLI
    }

    fn content_encoding(&self) -> Cow<'_, str> {
        Cow::Borrowed("br")
    }

    fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        imp::compress(data, self.level, self.mode)
    }

    fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        imp::decompress(data)
    }

    fn supported(&self) -> bool {
        Self::is_supported()
    }
}

#[cfg(feature = "brotli")]
mod imp {
    //! The encoder and decoder calls of ext-brotli's `brotli_compress()` and
    //! `brotli_uncompress()`.

    use brotli::enc::StandardAlloc;
    use brotli::enc::encode::{
        BrotliEncoderMaxCompressedSize, BrotliEncoderOperation, BrotliEncoderParameter, BrotliEncoderStateStruct,
    };
    use brotli::{BrotliDecompressStream, BrotliResult, BrotliState};

    use super::Mode;
    use crate::Error;

    /// `BROTLI_DEFAULT_WINDOW`.
    const WINDOW: u32 = 22;

    /// The output chunk of `brotli_uncompress()` (`PHP_BROTLI_BUFFER_SIZE`).
    const CHUNK: usize = 1 << 19;

    /// One `BROTLI_OPERATION_FINISH` stream over the whole input, into a
    /// buffer of `max(BrotliEncoderMaxCompressedSize(len), 64)` bytes.
    pub(super) fn compress(data: &[u8], level: u32, mode: Mode) -> Result<Vec<u8>, Error> {
        let mut encoder = BrotliEncoderStateStruct::new(StandardAlloc::default());
        let mode = match mode {
            Mode::Generic => 0,
            Mode::Text => 1,
            Mode::Font => 2,
        };
        if !encoder.set_parameter(BrotliEncoderParameter::BROTLI_PARAM_QUALITY, level)
            || !encoder.set_parameter(BrotliEncoderParameter::BROTLI_PARAM_LGWIN, WINDOW)
            || !encoder.set_parameter(BrotliEncoderParameter::BROTLI_PARAM_MODE, mode)
        {
            return Err(Error::Codec("brotli_compress(): failed to set compression parameters".into()));
        }
        let size = BrotliEncoderMaxCompressedSize(data.len()).max(64);
        let mut buffer = vec![0u8; size];
        let mut out = Vec::with_capacity(size.min(data.len() / 2 + 64));
        let (mut available_in, mut next_in) = (data.len(), 0);
        while !encoder.is_finished() {
            let (mut available_out, mut next_out) = (size, 0);
            if !encoder.compress_stream(
                BrotliEncoderOperation::BROTLI_OPERATION_FINISH,
                &mut available_in,
                data,
                &mut next_in,
                &mut available_out,
                &mut buffer,
                &mut next_out,
                &mut None,
                &mut |_: &mut _, _: &mut [_], _, _: &mut _| (),
            ) {
                return Err(Error::Codec("brotli_compress(): failed to compress".into()));
            }
            out.extend_from_slice(&buffer[..next_out]);
        }
        Ok(out)
    }

    /// Decodes while the decoder needs more output; anything but success
    /// (truncated or corrupt input) is a failure.
    pub(super) fn decompress(data: &[u8]) -> Result<Vec<u8>, Error> {
        let mut state = BrotliState::new(StandardAlloc::default(), StandardAlloc::default(), StandardAlloc::default());
        let mut buffer = vec![0u8; CHUNK.min(data.len().saturating_mul(4).max(1024))];
        let mut out = Vec::new();
        let (mut available_in, mut next_in, mut total) = (data.len(), 0, 0);
        let result = loop {
            let (mut available_out, mut next_out) = (buffer.len(), 0);
            let result = BrotliDecompressStream(
                &mut available_in,
                &mut next_in,
                data,
                &mut available_out,
                &mut next_out,
                &mut buffer,
                &mut total,
                &mut state,
            );
            out.extend_from_slice(&buffer[..next_out]);
            if !matches!(result, BrotliResult::NeedsMoreOutput) {
                break result;
            }
            if buffer.len() < CHUNK {
                buffer.resize(CHUNK, 0);
            }
        };
        match result {
            BrotliResult::ResultSuccess => Ok(out),
            _ => Err(Error::Codec("brotli_uncompress(): failed to uncompress".into())),
        }
    }
}

#[cfg(not(feature = "brotli"))]
mod imp {
    use super::Mode;
    use crate::Error;

    pub(super) fn compress(_: &[u8], _: u32, _: Mode) -> Result<Vec<u8>, Error> {
        Err(Error::undefined("brotli_compress"))
    }

    pub(super) fn decompress(_: &[u8]) -> Result<Vec<u8>, Error> {
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
    }
}
