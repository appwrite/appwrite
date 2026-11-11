//! Response compression: choosing an algorithm from the client's
//! `Accept-Encoding` header, and compressing bodies with it.
//!
//! The algorithms and the negotiation are `utopia-compression`'s; this
//! module adds what `Response::send()` decides on top: the levels, and which
//! content types are compressed.
//!
//! PHP: `Utopia\Compression\Compression::fromAcceptEncoding` and the
//! algorithms `Utopia\Http\Response::send()` uses.

mod algorithm;

use utopia_compression::Compression as Codec;

pub use algorithm::Algorithm;

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
