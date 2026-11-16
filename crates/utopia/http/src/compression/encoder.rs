//! Incremental encoders for streamed responses: gzip and raw deflate over
//! zlib (PHP's `deflate_init()`/`deflate_add()`), and Brotli (ext-brotli's
//! `brotli_compress_init()`/`brotli_compress_add()`).

use std::mem::MaybeUninit;
use std::os::raw::c_int;

use brotli::enc::StandardAlloc;
use brotli::enc::encode::{BrotliEncoderOperation, BrotliEncoderParameter, BrotliEncoderStateStruct};
use libz_sys as z;

/// `ZLIB_ENCODING_GZIP`.
const GZIP: c_int = 31;
/// `ZLIB_ENCODING_RAW`.
const RAW: c_int = -15;
/// `deflate_init()`'s default memory level.
const MEM_LEVEL: c_int = 8;
/// `BROTLI_DEFAULT_WINDOW`.
const WINDOW: u32 = 22;
/// Output buffer per encoder call.
const CHUNK: usize = 1 << 15;

/// An incremental encoder a streamed body was negotiated to.
pub(crate) enum Encoder {
    /// gzip or raw deflate: a zlib stream, and whether the gzip header's OS
    /// byte is still to be written as Linux's.
    Deflate(Box<Zlib>),
    /// Brotli.
    Brotli(Box<BrotliEncoderStateStruct<StandardAlloc>>),
}

impl std::fmt::Debug for Encoder {
    /// Writes the encoding's name.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(match self {
            Encoder::Deflate(z) if z.gzip => "Encoder::Gzip",
            Encoder::Deflate(_) => "Encoder::Deflate",
            Encoder::Brotli(_) => "Encoder::Brotli",
        })
    }
}

/// A zlib deflate stream, freed on drop.
pub(crate) struct Zlib {
    stream: Box<MaybeUninit<z::z_stream>>,
    gzip: bool,
    header: bool,
}

// SAFETY: the stream is owned by this value alone and only used through
// `&mut self`; zlib keeps no thread-local state.
unsafe impl Send for Zlib {}
// SAFETY: no method takes `&self` to touch the stream.
unsafe impl Sync for Zlib {}

impl Drop for Zlib {
    fn drop(&mut self) {
        // SAFETY: the stream was initialised by deflateInit2_ in `Zlib::new`.
        unsafe {
            z::deflateEnd(self.stream.as_mut_ptr());
        }
    }
}

impl Zlib {
    /// `deflate_init($encoding)`: level 6, memory level 8, the default
    /// strategy.
    fn new(gzip: bool) -> Option<Self> {
        let mut stream = Box::new(MaybeUninit::<z::z_stream>::zeroed());
        // SAFETY: the zeroed stream (null zalloc/zfree/opaque select zlib's
        // allocator) is initialised by deflateInit2_.
        let status = unsafe {
            z::deflateInit2_(
                stream.as_mut_ptr(),
                z::Z_DEFAULT_COMPRESSION,
                z::Z_DEFLATED,
                if gzip { GZIP } else { RAW },
                MEM_LEVEL,
                z::Z_DEFAULT_STRATEGY,
                z::zlibVersion(),
                std::mem::size_of::<z::z_stream>() as c_int,
            )
        };
        (status == z::Z_OK).then_some(Zlib { stream, gzip, header: gzip })
    }

    /// `deflate_add($context, $data, $flush)`.
    fn add(&mut self, data: &[u8], flush: c_int) -> Option<Vec<u8>> {
        let mut out = Vec::new();
        let mut buffer = vec![0u8; CHUNK];
        let s = self.stream.as_mut_ptr();
        // SAFETY: next_in covers `data` (zlib only reads it) and next_out
        // `buffer`, both alive for the calls; avail_out tells how much of
        // `buffer` was written.
        unsafe {
            (&raw mut (*s).next_in).write(data.as_ptr().cast_mut());
            (&raw mut (*s).avail_in).write(data.len() as z::uInt);
            loop {
                (&raw mut (*s).next_out).write(buffer.as_mut_ptr());
                (&raw mut (*s).avail_out).write(CHUNK as z::uInt);
                let status = z::deflate(s, flush);
                if status != z::Z_OK && status != z::Z_STREAM_END && status != z::Z_BUF_ERROR {
                    return None;
                }
                let written = CHUNK - (&raw const (*s).avail_out).read() as usize;
                out.extend_from_slice(&buffer[..written]);
                if (&raw const (*s).avail_out).read() != 0 || status == z::Z_STREAM_END {
                    break;
                }
            }
            (&raw mut (*s).next_in).write(std::ptr::null_mut());
            (&raw mut (*s).avail_in).write(0);
        }
        // zlib writes the OS of the build into the gzip header; PHP runs on
        // Linux, which writes 3 (Unix).
        if self.header && out.len() > 9 {
            out[9] = 3;
            self.header = false;
        }
        Some(out)
    }
}

impl Encoder {
    /// `deflate_init(ZLIB_ENCODING_GZIP)`.
    pub(crate) fn gzip() -> Option<Self> {
        Zlib::new(true).map(|z| Encoder::Deflate(Box::new(z)))
    }

    /// `deflate_init(ZLIB_ENCODING_RAW)`.
    pub(crate) fn deflate() -> Option<Self> {
        Zlib::new(false).map(|z| Encoder::Deflate(Box::new(z)))
    }

    /// `brotli_compress_init($quality)`, in generic mode.
    pub(crate) fn brotli(quality: u32) -> Option<Self> {
        let mut encoder = BrotliEncoderStateStruct::new(StandardAlloc::default());
        let set = encoder.set_parameter(BrotliEncoderParameter::BROTLI_PARAM_QUALITY, quality)
            && encoder.set_parameter(BrotliEncoderParameter::BROTLI_PARAM_LGWIN, WINDOW)
            && encoder.set_parameter(BrotliEncoderParameter::BROTLI_PARAM_MODE, 0);
        set.then(|| Encoder::Brotli(Box::new(encoder)))
    }

    /// Encodes `data`, flushed so the client can decode it on arrival, or
    /// finishing the stream when `end` is set. `None` when the encoder fails.
    ///
    /// PHP: `deflate_add($ctx, $data, $end ? ZLIB_FINISH : ZLIB_SYNC_FLUSH)`
    /// or `brotli_compress_add($ctx, $data, $end ? BROTLI_FINISH : BROTLI_FLUSH)`.
    pub(crate) fn add(&mut self, data: &[u8], end: bool) -> Option<Vec<u8>> {
        match self {
            Encoder::Deflate(zlib) => zlib.add(data, if end { z::Z_FINISH } else { z::Z_SYNC_FLUSH }),
            Encoder::Brotli(encoder) => {
                let op = if end {
                    BrotliEncoderOperation::BROTLI_OPERATION_FINISH
                } else {
                    BrotliEncoderOperation::BROTLI_OPERATION_FLUSH
                };
                let mut out = Vec::new();
                let mut buffer = vec![0u8; CHUNK];
                let (mut available_in, mut next_in) = (data.len(), 0);
                loop {
                    let (mut available_out, mut next_out) = (CHUNK, 0);
                    if !encoder.compress_stream(
                        op,
                        &mut available_in,
                        data,
                        &mut next_in,
                        &mut available_out,
                        &mut buffer,
                        &mut next_out,
                        &mut None,
                        &mut |_: &mut _, _: &mut [_], _, _: &mut _| (),
                    ) {
                        return None;
                    }
                    out.extend_from_slice(&buffer[..next_out]);
                    let done = if end { encoder.is_finished() } else { available_in == 0 };
                    if done && !encoder.has_more_output() {
                        break;
                    }
                }
                Some(out)
            }
        }
    }
}
