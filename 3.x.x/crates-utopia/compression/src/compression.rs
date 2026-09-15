use std::collections::HashMap;

use crate::algorithms;
use crate::error::CompressionError;

/// Canonical algorithm names used by [`Compression::from_name`] and negotiation.
pub const NONE: &str = "none";
/// Deprecated alias for [`NONE`]; still recognized in `Accept-Encoding` headers.
pub const IDENTITY: &str = "identity";
pub const BROTLI: &str = "brotli";
pub const DEFLATE: &str = "deflate";
pub const GZIP: &str = "gzip";
pub const ZSTD: &str = "zstd";

/// Supported compression algorithm.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Compression {
    /// No compression (`none`).
    None,
    /// Gzip compression.
    Gzip,
    /// Raw deflate compression.
    Deflate,
    /// Brotli compression.
    Brotli { level: u32 },
    /// Zstandard compression.
    Zstd { level: i32 },
}

impl Default for Compression {
    fn default() -> Self {
        Self::None
    }
}

impl Compression {
    /// Create a compression algorithm from its canonical or alias name.
    ///
    /// Recognizes `br` as an alias for brotli. Returns `None` for unknown names and
    /// for `none` / `identity`, matching the PHP implementation.
    pub fn from_name(name: &str) -> Option<Self> {
        match name.trim().to_ascii_lowercase().as_str() {
            BROTLI | "br" => Some(Self::brotli()),
            DEFLATE => Some(Self::Deflate),
            GZIP => Some(Self::Gzip),
            ZSTD => Some(Self::zstd()),
            _ => None,
        }
    }

    /// Negotiate the preferred compression algorithm from an `Accept-Encoding` header.
    ///
    /// Returns `None` when the header is empty, equals `"0"`, contains no supported
    /// encodings, or when the highest-priority encoding is `none` / `identity`.
    pub fn from_accept_encoding(accept_encoding: &str) -> Option<Self> {
        Self::from_accept_encoding_with_supported(accept_encoding, None)
    }

    /// Negotiate compression using an explicit supported-encoding list.
    ///
    /// When `supported` is `None`, the default list is
    /// `[zstd, br, gzip, deflate, none, identity]` with availability determined by
    /// [`Compression::is_supported`].
    ///
    /// When `supported` is a flat list, every listed encoding is treated as supported.
    pub fn from_accept_encoding_with_supported(
        accept_encoding: &str,
        supported: Option<&[&str]>,
    ) -> Option<Self> {
        let trimmed = accept_encoding.trim();
        if trimmed.is_empty() || trimmed == "0" {
            return None;
        }

        let supported_map = match supported {
            None => default_supported_map(),
            Some(list) => list
                .iter()
                .map(|name| (name.trim().to_ascii_lowercase(), true))
                .collect(),
        };

        let mut candidates = Vec::new();

        for (index, raw) in trimmed.split(',').enumerate() {
            let raw = raw.trim();
            if raw.is_empty() {
                continue;
            }

            let mut parts = raw.split(';');
            let encoding = parts.next().unwrap_or("").trim().to_ascii_lowercase();
            let encoding = match encoding.as_str() {
                "br" => BROTLI.to_string(),
                other => other.to_string(),
            };

            let mut quality = 1.0_f64;
            if let Some(param) = parts.next() {
                let param = param.trim();
                if let Some(value) = param.strip_prefix("q=") {
                    quality = value.trim().parse().unwrap_or(0.0);
                }
            }

            if supported_map.get(&encoding).copied().unwrap_or(false) {
                candidates.push((index, encoding, quality));
            }
        }

        if candidates.is_empty() {
            return None;
        }

        candidates.sort_by(|a, b| {
            b.2.partial_cmp(&a.2)
                .unwrap_or(std::cmp::Ordering::Equal)
                .then_with(|| a.0.cmp(&b.0))
        });

        Self::from_name(&candidates[0].1)
    }

    /// Return the canonical algorithm name.
    pub fn name(&self) -> &'static str {
        match self {
            Self::None => NONE,
            Self::Gzip => GZIP,
            Self::Deflate => DEFLATE,
            Self::Brotli { .. } => BROTLI,
            Self::Zstd { .. } => ZSTD,
        }
    }

    /// Return the value for `Content-Encoding` / `Accept-Encoding` headers.
    pub fn content_encoding(&self) -> &'static str {
        match self {
            Self::None => NONE,
            Self::Gzip => GZIP,
            Self::Deflate => DEFLATE,
            Self::Brotli { .. } => "br",
            Self::Zstd { .. } => ZSTD,
        }
    }

    /// Return whether this algorithm is available in the current build.
    pub fn is_supported(&self) -> bool {
        match self {
            Self::None => true,
            Self::Gzip => is_gzip_supported(),
            Self::Deflate => is_deflate_supported(),
            Self::Brotli { .. } => is_brotli_supported(),
            Self::Zstd { .. } => is_zstd_supported(),
        }
    }

    /// Compress bytes using this algorithm.
    pub fn compress(&self, data: &[u8]) -> Result<Vec<u8>, CompressionError> {
        match self {
            Self::None => Ok(data.to_vec()),
            #[cfg(feature = "gzip")]
            Self::Gzip => {
                ensure_supported(is_gzip_supported(), GZIP)?;
                algorithms::gzip::compress(data)
            }
            #[cfg(not(feature = "gzip"))]
            Self::Gzip => Err(CompressionError::Unsupported(GZIP)),
            #[cfg(feature = "deflate")]
            Self::Deflate => {
                ensure_supported(is_deflate_supported(), DEFLATE)?;
                algorithms::deflate::compress(data)
            }
            #[cfg(not(feature = "deflate"))]
            Self::Deflate => Err(CompressionError::Unsupported(DEFLATE)),
            #[cfg(feature = "brotli")]
            Self::Brotli { level } => {
                ensure_supported(is_brotli_supported(), BROTLI)?;
                algorithms::brotli::compress(data, *level)
            }
            #[cfg(not(feature = "brotli"))]
            Self::Brotli { .. } => Err(CompressionError::Unsupported(BROTLI)),
            #[cfg(feature = "zstd")]
            Self::Zstd { level } => {
                ensure_supported(is_zstd_supported(), ZSTD)?;
                algorithms::zstd::compress(data, *level)
            }
            #[cfg(not(feature = "zstd"))]
            Self::Zstd { .. } => Err(CompressionError::Unsupported(ZSTD)),
        }
    }

    /// Decompress bytes using this algorithm.
    pub fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, CompressionError> {
        match self {
            Self::None => Ok(data.to_vec()),
            #[cfg(feature = "gzip")]
            Self::Gzip => {
                ensure_supported(is_gzip_supported(), GZIP)?;
                algorithms::gzip::decompress(data)
            }
            #[cfg(not(feature = "gzip"))]
            Self::Gzip => Err(CompressionError::Unsupported(GZIP)),
            #[cfg(feature = "deflate")]
            Self::Deflate => {
                ensure_supported(is_deflate_supported(), DEFLATE)?;
                algorithms::deflate::decompress(data)
            }
            #[cfg(not(feature = "deflate"))]
            Self::Deflate => Err(CompressionError::Unsupported(DEFLATE)),
            #[cfg(feature = "brotli")]
            Self::Brotli { .. } => {
                ensure_supported(is_brotli_supported(), BROTLI)?;
                algorithms::brotli::decompress(data)
            }
            #[cfg(not(feature = "brotli"))]
            Self::Brotli { .. } => Err(CompressionError::Unsupported(BROTLI)),
            #[cfg(feature = "zstd")]
            Self::Zstd { .. } => {
                ensure_supported(is_zstd_supported(), ZSTD)?;
                algorithms::zstd::decompress(data)
            }
            #[cfg(not(feature = "zstd"))]
            Self::Zstd { .. } => Err(CompressionError::Unsupported(ZSTD)),
        }
    }

    /// Create a brotli compressor with the default quality level.
    pub fn brotli() -> Self {
        Self::Brotli {
            #[cfg(feature = "brotli")]
            level: algorithms::brotli::LEVEL_DEFAULT,
            #[cfg(not(feature = "brotli"))]
            level: 11,
        }
    }

    /// Create a zstd compressor with the default compression level.
    pub fn zstd() -> Self {
        Self::Zstd {
            #[cfg(feature = "zstd")]
            level: algorithms::zstd::LEVEL_DEFAULT,
            #[cfg(not(feature = "zstd"))]
            level: 3,
        }
    }

    /// Return the brotli compression level, if applicable.
    pub fn brotli_level(&self) -> Option<u32> {
        match self {
            Self::Brotli { level } => Some(*level),
            _ => None,
        }
    }

    /// Return the zstd compression level, if applicable.
    pub fn zstd_level(&self) -> Option<i32> {
        match self {
            Self::Zstd { level } => Some(*level),
            _ => None,
        }
    }

    /// Set the brotli compression level (0–11).
    pub fn set_brotli_level(&mut self, level: u32) -> Result<(), CompressionError> {
        #[cfg(feature = "brotli")]
        {
            if !(algorithms::brotli::LEVEL_MIN..=algorithms::brotli::LEVEL_MAX).contains(&level) {
                return Err(CompressionError::InvalidLevel {
                    min: algorithms::brotli::LEVEL_MIN as i32,
                    max: algorithms::brotli::LEVEL_MAX as i32,
                });
            }
        }

        #[cfg(not(feature = "brotli"))]
        let _ = level;

        if let Self::Brotli { level: current } = self {
            *current = level;
            Ok(())
        } else {
            *self = Self::Brotli { level };
            Ok(())
        }
    }

    /// Set the zstd compression level (1–22).
    pub fn set_zstd_level(&mut self, level: i32) -> Result<(), CompressionError> {
        #[cfg(feature = "zstd")]
        {
            if !(algorithms::zstd::LEVEL_MIN..=algorithms::zstd::LEVEL_MAX).contains(&level) {
                return Err(CompressionError::InvalidLevel {
                    min: algorithms::zstd::LEVEL_MIN,
                    max: algorithms::zstd::LEVEL_MAX,
                });
            }
        }

        #[cfg(not(feature = "zstd"))]
        let _ = level;

        if let Self::Zstd { level: current } = self {
            *current = level;
            Ok(())
        } else {
            *self = Self::Zstd { level };
            Ok(())
        }
    }
}

fn default_supported_map() -> HashMap<String, bool> {
    HashMap::from([
        (ZSTD.to_string(), is_zstd_supported()),
        (BROTLI.to_string(), is_brotli_supported()),
        (GZIP.to_string(), is_gzip_supported()),
        (DEFLATE.to_string(), is_deflate_supported()),
        (NONE.to_string(), true),
        (IDENTITY.to_string(), true),
    ])
}

fn ensure_supported(supported: bool, name: &'static str) -> Result<(), CompressionError> {
    if supported {
        Ok(())
    } else {
        Err(CompressionError::Unsupported(name))
    }
}

#[cfg(feature = "gzip")]
fn is_gzip_supported() -> bool {
    algorithms::gzip::is_supported()
}

#[cfg(not(feature = "gzip"))]
fn is_gzip_supported() -> bool {
    false
}

#[cfg(feature = "deflate")]
fn is_deflate_supported() -> bool {
    algorithms::deflate::is_supported()
}

#[cfg(not(feature = "deflate"))]
fn is_deflate_supported() -> bool {
    false
}

#[cfg(feature = "brotli")]
fn is_brotli_supported() -> bool {
    algorithms::brotli::is_supported()
}

#[cfg(not(feature = "brotli"))]
fn is_brotli_supported() -> bool {
    false
}

#[cfg(feature = "zstd")]
fn is_zstd_supported() -> bool {
    algorithms::zstd::is_supported()
}

#[cfg(not(feature = "zstd"))]
fn is_zstd_supported() -> bool {
    false
}
