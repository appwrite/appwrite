//! Response compression: `Utopia\Compression\Compression::fromAcceptEncoding`
//! and the algorithms `Utopia\Http\Response::send()` uses.
//!
//! `utopia-php/compression` has no crate of its own yet; the part of it the
//! HTTP layer needs lives here.

use std::io::{Read, Write};

/// Response compression settings (`Http::setCompression*`).
#[derive(Debug, Clone)]
pub struct Compression {
    pub enabled: bool,
    /// Bodies strictly larger than this are compressed (`COMPRESSION_MIN_SIZE_DEFAULT`).
    pub min_size: usize,
    /// Algorithms offered (`setCompressionSupported`); empty means every
    /// supported one (`zstd`, `brotli`, `gzip`, `deflate`, `none`, `identity`).
    pub supported: Vec<String>,
}

impl Default for Compression {
    fn default() -> Self {
        Self { enabled: true, min_size: crate::COMPRESSION_MIN_SIZE_DEFAULT, supported: Vec::new() }
    }
}

/// A compression algorithm.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Algorithm {
    Zstd,
    Brotli,
    Gzip,
    Deflate,
}

impl Algorithm {
    /// `Compression::fromName($name)`: `None` for `none`, `identity` and unknown names.
    pub fn from_name(name: &str) -> Option<Self> {
        match name.to_ascii_lowercase().as_str() {
            "brotli" | "br" => Some(Algorithm::Brotli),
            "deflate" => Some(Algorithm::Deflate),
            "gzip" => Some(Algorithm::Gzip),
            "zstd" => Some(Algorithm::Zstd),
            _ => None,
        }
    }

    /// `getContentEncoding()`.
    pub fn content_encoding(self) -> &'static str {
        match self {
            Algorithm::Zstd => "zstd",
            Algorithm::Brotli => "br",
            Algorithm::Gzip => "gzip",
            Algorithm::Deflate => "deflate",
        }
    }

    /// Compresses `data` at the level `Response::send()` uses.
    pub fn compress(self, data: &[u8]) -> Vec<u8> {
        match self {
            Algorithm::Zstd => zstd::bulk::compress(data, crate::COMPRESSION_ZSTD_LEVEL_DEFAULT).unwrap_or_default(),
            Algorithm::Brotli => {
                let mut out = Vec::with_capacity(data.len() / 2 + 16);
                {
                    let mut writer = brotli::CompressorWriter::new(
                        &mut out,
                        4096,
                        crate::COMPRESSION_BROTLI_LEVEL_DEFAULT as u32,
                        22,
                    );
                    let _ = writer.write_all(data);
                }
                out
            }
            Algorithm::Gzip => {
                let mut enc = flate2::GzBuilder::new()
                    .operating_system(3)
                    .write(Vec::with_capacity(data.len() / 2 + 32), flate2::Compression::new(6));
                let _ = enc.write_all(data);
                enc.finish().unwrap_or_default()
            }
            Algorithm::Deflate => {
                let mut enc = flate2::write::DeflateEncoder::new(
                    Vec::with_capacity(data.len() / 2 + 16),
                    flate2::Compression::new(6),
                );
                let _ = enc.write_all(data);
                enc.finish().unwrap_or_default()
            }
        }
    }

    /// Decompresses `data`; `None` when it is not valid.
    pub fn decompress(self, data: &[u8]) -> Option<Vec<u8>> {
        let mut out = Vec::new();
        match self {
            Algorithm::Zstd => return zstd::stream::decode_all(data).ok(),
            Algorithm::Brotli => brotli::Decompressor::new(data, 4096).read_to_end(&mut out).ok()?,
            Algorithm::Gzip => flate2::read::GzDecoder::new(data).read_to_end(&mut out).ok()?,
            Algorithm::Deflate => flate2::read::DeflateDecoder::new(data).read_to_end(&mut out).ok()?,
        };
        Some(out)
    }
}

/// `Compression::fromAcceptEncoding($acceptEncoding, $supported)`: the
/// accepted algorithm with the highest `q` (header order on ties) among the
/// supported ones. `none` and `identity` can win, and mean no compression.
pub fn from_accept_encoding(accept: &str, supported: &[String]) -> Option<Algorithm> {
    if accept.is_empty() || accept == "0" {
        return None;
    }
    let is_supported = |name: &str| {
        if supported.is_empty() {
            matches!(name, "zstd" | "brotli" | "gzip" | "deflate" | "none" | "identity")
        } else {
            supported.iter().any(|s| s == name)
        }
    };
    let mut best: Option<(f64, String)> = None;
    for encoding in accept.split(',') {
        let encoding = php_std::string::trim(encoding.as_bytes(), b" \t\n\r\0\x0B").to_ascii_lowercase();
        let encoding = String::from_utf8_lossy(&encoding).into_owned();
        let mut parts = encoding.split(';');
        let name = parts.next().unwrap_or("");
        let name = if name == "br" { "brotli" } else { name };
        let quality = match parts.next() {
            Some(q) => php_std::format::str_to_float(q.replace("q=", "").as_bytes()),
            None => 1.0,
        };
        if !is_supported(name) {
            continue;
        }
        // usort is stable: the first of equal qualities wins.
        if best.as_ref().is_none_or(|(q, _)| quality > *q) {
            best = Some((quality, name.to_owned()));
        }
    }
    best.and_then(|(_, name)| Algorithm::from_name(&name))
}

/// Content types `Response::send()` compresses (parameters stripped, case-insensitive).
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
