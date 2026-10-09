use std::io::Write;

/// Response compression settings (`_APP_COMPRESSION_*`).
#[derive(Debug, Clone, Copy)]
pub struct Compression {
    pub enabled: bool,
    /// Bodies strictly larger than this are compressed.
    pub min_size: usize,
}

impl Default for Compression {
    fn default() -> Self {
        Self { enabled: true, min_size: 1024 }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum Encoding {
    Zstd,
    Brotli,
    Gzip,
    Deflate,
    Identity,
}

impl Encoding {
    pub(crate) fn name(self) -> &'static str {
        match self {
            Encoding::Zstd => "zstd",
            Encoding::Brotli => "br",
            Encoding::Gzip => "gzip",
            Encoding::Deflate => "deflate",
            Encoding::Identity => "identity",
        }
    }
}

/// Picks the encoding from an `Accept-Encoding` header: highest q first,
/// header order on ties, unsupported codings skipped.
pub(crate) fn negotiate(accept: &str) -> Option<Encoding> {
    let mut candidates: Vec<(f32, usize, Encoding)> = Vec::new();
    for (index, part) in accept.split(',').enumerate() {
        let mut pieces = part.trim().split(';');
        let name = pieces.next().unwrap_or("").trim().to_ascii_lowercase();
        let mut q = 1.0f32;
        for p in pieces {
            if let Some(v) = p.trim().strip_prefix("q=") {
                q = v.trim().parse().unwrap_or(0.0);
            }
        }
        if q <= 0.0 {
            continue;
        }
        let enc = match name.as_str() {
            "zstd" => Encoding::Zstd,
            "br" => Encoding::Brotli,
            "gzip" => Encoding::Gzip,
            "deflate" => Encoding::Deflate,
            "identity" | "none" => Encoding::Identity,
            _ => continue,
        };
        candidates.push((q, index, enc));
    }
    candidates.sort_by(|a, b| b.0.partial_cmp(&a.0).unwrap_or(std::cmp::Ordering::Equal).then(a.1.cmp(&b.1)));
    candidates.first().map(|c| c.2)
}

pub(crate) fn compressible(content_type: &str) -> bool {
    let ct = content_type.split(';').next().unwrap_or("").trim().to_ascii_lowercase();
    ct.starts_with("text/")
        || ct == "application/json"
        || ct.ends_with("+json")
        || ct == "application/javascript"
        || ct == "application/xml"
        || ct.ends_with("+xml")
        || ct == "image/svg+xml"
}

pub(crate) fn compress(encoding: Encoding, body: &[u8]) -> Option<Vec<u8>> {
    match encoding {
        Encoding::Zstd => zstd::bulk::compress(body, 3).ok(),
        Encoding::Brotli => {
            let mut out = Vec::with_capacity(body.len() / 2);
            {
                let mut writer = brotli::CompressorWriter::new(&mut out, 4096, 4, 22);
                writer.write_all(body).ok()?;
            }
            Some(out)
        }
        Encoding::Gzip => {
            let mut enc =
                flate2::write::GzEncoder::new(Vec::with_capacity(body.len() / 2), flate2::Compression::new(6));
            enc.write_all(body).ok()?;
            enc.finish().ok()
        }
        Encoding::Deflate => {
            let mut enc =
                flate2::write::DeflateEncoder::new(Vec::with_capacity(body.len() / 2), flate2::Compression::new(6));
            enc.write_all(body).ok()?;
            enc.finish().ok()
        }
        Encoding::Identity => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn negotiation() {
        assert_eq!(negotiate("gzip, deflate, br"), Some(Encoding::Gzip));
        assert_eq!(negotiate("gzip;q=0.5, br"), Some(Encoding::Brotli));
        assert_eq!(negotiate("foo"), None);
        assert_eq!(negotiate("zstd;q=0, gzip;q=0.1"), Some(Encoding::Gzip));
    }
}
