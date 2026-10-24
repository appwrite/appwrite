use utopia_compression::{Algorithm as _, Brotli, Compression as Codec, Deflate, Gzip, Lz4, Snappy, Xz, Zstd};

/// A compression algorithm a response can be encoded with.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Algorithm {
    /// Zstandard (`zstd`).
    Zstd,
    /// Brotli (`br`).
    Brotli,
    /// Gzip (`gzip`).
    Gzip,
    /// Raw deflate (`deflate`).
    Deflate,
    /// LZ4 (`lz4`), when the supported list offers it.
    Lz4,
    /// Snappy (`snappy`), when the supported list offers it.
    Snappy,
    /// XZ (`xz`), when the supported list offers it.
    Xz,
}

impl Algorithm {
    /// The algorithm named `name` (case-insensitive; `br` and `brotli` are
    /// both Brotli), or `None` for `none`, `identity` and unknown names.
    ///
    /// PHP: `Compression::fromName($name)`.
    pub fn from_name(name: &str) -> Option<Self> {
        Codec::from_name(name).map(Self::from_codec)
    }

    pub(super) fn from_codec(codec: Codec) -> Self {
        match codec {
            Codec::Brotli(_) => Algorithm::Brotli,
            Codec::Deflate(_) => Algorithm::Deflate,
            Codec::Gzip(_) => Algorithm::Gzip,
            Codec::Lz4(_) => Algorithm::Lz4,
            Codec::Snappy(_) => Algorithm::Snappy,
            Codec::Xz(_) => Algorithm::Xz,
            Codec::Zstd(_) => Algorithm::Zstd,
        }
    }

    /// The algorithm at the level responses use: zstd level 3, Brotli
    /// quality 4, the others at their defaults.
    ///
    /// PHP: the levels `Response::send()` sets.
    fn codec(self) -> Codec {
        match self {
            Algorithm::Zstd => Codec::Zstd(Zstd::with_level(crate::COMPRESSION_ZSTD_LEVEL_DEFAULT.into())),
            Algorithm::Brotli => {
                let mut brotli = Brotli::new();
                // The level is within 0..=11.
                let _ = brotli.set_level(crate::COMPRESSION_BROTLI_LEVEL_DEFAULT.into());
                Codec::Brotli(brotli)
            }
            Algorithm::Gzip => Codec::Gzip(Gzip::new()),
            Algorithm::Deflate => Codec::Deflate(Deflate::new()),
            Algorithm::Lz4 => Codec::Lz4(Lz4::new()),
            Algorithm::Snappy => Codec::Snappy(Snappy::new()),
            Algorithm::Xz => Codec::Xz(Xz::new()),
        }
    }

    /// The `Content-Encoding` header value of the algorithm.
    ///
    /// PHP: `getContentEncoding()`.
    pub fn content_encoding(self) -> &'static str {
        match self {
            Algorithm::Zstd => "zstd",
            Algorithm::Brotli => "br",
            Algorithm::Gzip => "gzip",
            Algorithm::Deflate => "deflate",
            Algorithm::Lz4 => "lz4",
            Algorithm::Snappy => "snappy",
            Algorithm::Xz => "xz",
        }
    }

    /// Compresses `data` at the level responses use: zstd level 3, Brotli
    /// quality 4, gzip and deflate level 6. Returns an empty buffer if
    /// compression fails.
    ///
    /// PHP: the levels `Response::send()` uses; a failed compress returns
    /// an empty string there too.
    pub fn compress(self, data: &[u8]) -> Vec<u8> {
        self.codec().compress(data).unwrap_or_default()
    }

    /// Decompresses `data`; `None` when it is not valid.
    pub fn decompress(self, data: &[u8]) -> Option<Vec<u8>> {
        self.codec().decompress(data).ok()
    }
}
