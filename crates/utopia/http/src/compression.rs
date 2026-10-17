//! Response compression: choosing an algorithm from the client's
//! `Accept-Encoding` header, and compressing bodies with it.
//!
//! The algorithms and the negotiation are `utopia-compression`'s; this
//! module adds what `Response::send()` decides on top: the levels, and which
//! content types are compressed.
//!
//! PHP: `Utopia\Compression\Compression::fromAcceptEncoding` and the
//! algorithms `Utopia\Http\Response::send()` uses.

use utopia_compression::{Algorithm as _, Brotli, Compression as Codec, Deflate, Gzip, Lz4, Snappy, Xz, Zstd};

/// Response compression settings of an application.
///
/// PHP: `Http::setCompression()`, `setCompressionMinSize()`,
/// `setCompressionSupported()`.
#[derive(Debug, Clone)]
pub struct Compression {
    /// Whether responses are compressed.
    pub enabled: bool,
    /// Bodies strictly larger than this many bytes are compressed
    /// ([`COMPRESSION_MIN_SIZE_DEFAULT`](crate::COMPRESSION_MIN_SIZE_DEFAULT) by default).
    pub min_size: usize,
    /// Algorithm names offered; empty means every supported one (`zstd`,
    /// `brotli`, `gzip`, `deflate`, `none`, `identity`).
    pub supported: Vec<String>,
}

impl Default for Compression {
    /// Enabled, for bodies over 1024 bytes, with every supported algorithm.
    fn default() -> Self {
        Self { enabled: true, min_size: crate::COMPRESSION_MIN_SIZE_DEFAULT, supported: Vec::new() }
    }
}

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

    fn from_codec(codec: Codec) -> Self {
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

/// The algorithm to compress with for an `Accept-Encoding` header: the
/// accepted one with the highest `q` (header order on ties) among
/// `supported` (every algorithm when empty).
///
/// `none` and `identity` can win, and mean no compression; `None` is also
/// returned when nothing supported is accepted.
///
/// PHP: `Compression::fromAcceptEncoding($acceptEncoding, $supported)`.
pub fn from_accept_encoding(accept: &str, supported: &[String]) -> Option<Algorithm> {
    Codec::from_accept_encoding(accept, supported).map(Algorithm::from_codec)
}

/// Whether responses of a content type are compressed: text, scripts, JSON,
/// XML, fonts, SVG and similar. Parameters are ignored and the match is
/// case-insensitive.
///
/// PHP: the content types `Response::send()` compresses.
pub fn compressible(content_type: &str) -> bool {
    let ct = content_type.split(';').next().unwrap_or("");
    let ct = php_std::string::trim(ct.as_bytes(), b" \t\n\r\0\x0B").to_ascii_lowercase();
    COMPRESSIBLE.iter().any(|c| c.as_bytes() == ct.as_slice())
}

const COMPRESSIBLE: &[&str] = &[
    "text/html",
    "text/richtext",
    "text/plain",
    "text/css",
    "text/x-script",
    "text/x-component",
    "text/x-java-source",
    "text/x-markdown",
    "application/javascript",
    "application/x-javascript",
    "text/javascript",
    "text/js",
    "image/x-icon",
    "image/vnd.microsoft.icon",
    "application/x-perl",
    "application/x-httpd-cgi",
    "text/xml",
    "application/xml",
    "application/rss+xml",
    "application/vnd.api+json",
    "application/x-protobuf",
    "application/json",
    "application/manifest+json",
    "application/ld+json",
    "application/graphql+json",
    "application/geo+json",
    "multipart/bag",
    "multipart/mixed",
    "application/xhtml+xml",
    "font/ttf",
    "font/otf",
    "font/x-woff",
    "image/svg+xml",
    "application/vnd.ms-fontobject",
    "application/ttf",
    "application/x-ttf",
    "application/otf",
    "application/x-otf",
    "application/truetype",
    "application/opentype",
    "application/x-opentype",
    "application/font-woff",
    "application/eot",
    "application/font",
    "application/font-sfnt",
    "application/wasm",
    "application/javascript-binast",
];

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn negotiation() {
        assert_eq!(from_accept_encoding("gzip, deflate, br", &[]), Some(Algorithm::Gzip));
        assert_eq!(from_accept_encoding("gzip;q=0.5, br", &[]), Some(Algorithm::Brotli));
        assert_eq!(from_accept_encoding("foo", &[]), None);
        assert_eq!(from_accept_encoding("identity, gzip", &[]), None);
        assert_eq!(from_accept_encoding("zstd;q=0, gzip;q=0.1", &[]), Some(Algorithm::Gzip));
        let data = b"hello hello hello hello".repeat(10);
        for a in [Algorithm::Zstd, Algorithm::Brotli, Algorithm::Gzip, Algorithm::Deflate] {
            assert_eq!(a.decompress(&a.compress(&data)).unwrap(), data);
        }
    }
}
