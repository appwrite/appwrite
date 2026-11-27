//! `Accept-Encoding` negotiation.

use php_std::format::str_to_float;
use php_std::string::{TRIM_CHARACTERS, str_replace, strtolower, trim};

use crate::Compression;

/// The accepted encoding with the highest quality among those `supported`
/// accepts (the first listed on a tie), as an algorithm.
///
/// PHP: the body of `Compression::fromAcceptEncoding()`: `explode(',')`,
/// `trim`, `strtolower`, `explode(';')`, the `br` alias, `floatval` of the
/// second part without `q=`, dropping a quality that is not above zero (the
/// client refuses it), then a stable `usort` by descending quality. The
/// header and the names are bytes, as PHP's strings are.
pub(crate) fn choose(accept: &[u8], supported: impl Fn(&[u8]) -> bool) -> Option<Compression> {
    if accept.is_empty() || accept == b"0" {
        return None;
    }
    let mut best: Option<(f64, Vec<u8>)> = None;
    for encoding in accept.split(|&b| b == b',') {
        let encoding = strtolower(trim(encoding, TRIM_CHARACTERS));
        let mut parts = encoding.split(|&b| b == b';');
        let name = parts.next().unwrap_or_default();
        let name: &[u8] = if name == b"br" { b"brotli" } else { name };
        let quality = match parts.next() {
            Some(q) => str_to_float(&str_replace(b"q=", b"", q).0),
            None => 1.0,
        };
        // q=0 means the client refuses that encoding.
        if quality <= 0.0 || quality.is_nan() || !supported(name) {
            continue;
        }
        if best.as_ref().is_none_or(|(q, _)| quality > *q) {
            best = Some((quality, name.to_vec()));
        }
    }
    best.and_then(|(_, name)| Compression::from_name_bytes(&name))
}
