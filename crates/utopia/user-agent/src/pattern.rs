//! The library's regular expressions: PHP patterns run by `php_std::pcre`
//! (PCRE2 semantics, PHP's limits), each compiled once, on first use.

use std::sync::Arc;

use php_std::pcre::{Regex, preg_quote};
use php_std::string;

/// A pattern literal of this library, compiled on first use and kept for
/// the life of the process (`preg_match()` keeps it in its cache).
macro_rules! pattern {
    ($regex:literal) => {{
        static PATTERN: std::sync::LazyLock<std::sync::Arc<php_std::pcre::Regex>> =
            std::sync::LazyLock::new(|| $crate::pattern::compile($regex.as_bytes()));
        &**PATTERN
    }};
}

pub(crate) use pattern;

/// Compiles a pattern of this library through PHP's pattern cache. They
/// are constants, so a failure is a bug in the library (the crate tests
/// compile every one).
pub(crate) fn compile(regex: &[u8]) -> Arc<Regex> {
    match Regex::compiled(regex) {
        Ok(re) => re,
        Err(e) => {
            panic!("invalid pattern {}: {}", String::from_utf8_lossy(regex), String::from_utf8_lossy(&e.message))
        }
    }
}

/// `'/' . preg_quote($token, '/') . $suffix`, for patterns built around a
/// token.
pub(crate) fn around(prefix: &str, token: &str, suffix: &str) -> Arc<Regex> {
    let mut regex = prefix.as_bytes().to_vec();
    regex.extend_from_slice(&preg_quote(token.as_bytes(), Some(b"/")));
    regex.extend_from_slice(suffix.as_bytes());
    compile(&regex)
}

/// `preg_match($regex, $subject, $matches) === 1 ? $matches[1] : null`.
pub(crate) fn group<'s>(regex: &Regex, subject: &'s [u8]) -> Option<&'s [u8]> {
    regex.captures(subject)?.get(1)
}

/// `stripos($haystack, $needle) !== false`.
pub(crate) fn contains_ci(haystack: &[u8], needle: &str) -> bool {
    matches!(string::stripos(haystack, needle.as_bytes(), 0), Ok(Some(_)))
}
