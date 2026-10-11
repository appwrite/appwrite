//! The regular expressions native code does not replace (see
//! `crate::scan`), each compiled once, on first use.
//!
//! PHP: the patterns are PHP's, run by `php_std::pcre` (PCRE2 semantics,
//! PHP's limits).

use std::sync::Arc;

use php_std::pcre::Regex;

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

/// `preg_match($regex, $subject, $matches) === 1 ? $matches[1] : null`.
pub(crate) fn group<'s>(regex: &Regex, subject: &'s [u8]) -> Option<&'s [u8]> {
    regex.captures(subject)?.get(1)
}
