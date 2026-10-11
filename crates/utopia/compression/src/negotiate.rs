//! `Accept-Encoding` negotiation.

use php_std::format::str_to_float;
use php_std::string::{TRIM_CHARACTERS, str_replace, strtolower, trim};

use crate::Compression;

/// The accepted encoding with the highest quality among those `supported`
/// accepts (the first listed on a tie), as an algorithm.
///
/// PHP: the body of `Compression::fromAcceptEncoding()`: `explode(',')`,
/// `trim`, `strtolower`, `explode(';')`, the `br` alias, `floatval` of the
/// second part without `q=`, then a stable `usort` by descending quality.
pub(crate) fn choose(accept: &str, supported: impl Fn(&str) -> bool) -> Option<Compression> {
    if accept.is_empty() || accept == "0" {
        return None;
    }
    let mut best: Option<(f64, String)> = None;
    for encoding in accept.as_bytes().split(|&b| b == b',') {
        let encoding = strtolower(trim(encoding, TRIM_CHARACTERS));
        let mut parts = encoding.split(|&b| b == b';');
        let name = parts.next().unwrap_or_default();
        let name: &[u8] = if name == b"br" { b"brotli" } else { name };
        let quality = match parts.next() {
            Some(q) => str_to_float(&str_replace(b"q=", b"", q).0),
            None => 1.0,
        };
        // Supported names are strings; bytes that are not UTF-8 never match one.
        let Ok(name) = std::str::from_utf8(name) else {
            continue;
        };
        if !supported(name) {
            continue;
        }
        if best.as_ref().is_none_or(|(q, _)| quality > *q) {
            best = Some((quality, name.to_owned()));
        }
    }
    best.and_then(|(_, name)| Compression::from_name(&name))
}
