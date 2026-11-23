//! Native matching for the library's simple patterns: case-insensitive
//! substring searches and `<token><separator><version>` captures, without
//! allocating and without a regex engine.
//!
//! PHP: each function names the pattern it replaces. Patterns are `/i`
//! without `/u`, so letters fold in ASCII only, as here, and `\b` is a
//! boundary between `[A-Za-z0-9_]` and anything else.

use memchr::{memchr, memchr2};

/// The first position, from `from`, where `needle` occurs in `haystack`,
/// ignoring ASCII case.
pub(crate) fn find_ci(haystack: &[u8], needle: &[u8], from: usize) -> Option<usize> {
    let (&first, rest) = needle.split_first()?;
    let last = haystack.len().checked_sub(needle.len())?;
    let (lower, upper) = (first.to_ascii_lowercase(), first.to_ascii_uppercase());
    let mut at = from;
    while at <= last {
        let window = &haystack[at..=last];
        let found = if lower == upper { memchr(lower, window) } else { memchr2(lower, upper, window) };
        at += found?;
        if haystack[at + 1..at + needle.len()].eq_ignore_ascii_case(rest) {
            return Some(at);
        }
        at += 1;
    }
    None
}

/// `stripos($haystack, $needle) !== false`, or `preg_match('/needle/i')`
/// for a needle without metacharacters.
pub(crate) fn contains_ci(haystack: &[u8], needle: &str) -> bool {
    find_ci(haystack, needle.as_bytes(), 0).is_some()
}

/// `preg_match('/(?:a|b|c)/i')` for needles without metacharacters.
pub(crate) fn contains_any_ci(haystack: &[u8], needles: &[&str]) -> bool {
    needles.iter().any(|needle| contains_ci(haystack, needle))
}

/// Whether `haystack[at..]` starts with `needle`, ignoring ASCII case.
pub(crate) fn starts_with_ci(haystack: &[u8], at: usize, needle: &str) -> bool {
    haystack.get(at..at + needle.len()).is_some_and(|s| s.eq_ignore_ascii_case(needle.as_bytes()))
}

/// `\w` without `/u`.
pub(crate) fn is_word(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_'
}

/// `\b` before `haystack[at]`, when that byte is a word byte: start of the
/// subject or a non-word byte before it.
fn word_start(haystack: &[u8], at: usize) -> bool {
    at == 0 || !is_word(haystack[at - 1])
}

/// `\b` after a word byte ending at `end`: end of the subject or a
/// non-word byte.
pub(crate) fn word_end(haystack: &[u8], end: usize) -> bool {
    haystack.get(end).is_none_or(|&b| !is_word(b))
}

/// `[0-9.]`.
pub(crate) fn dotted(b: u8) -> bool {
    b.is_ascii_digit() || b == b'.'
}

/// `[0-9._]`.
pub(crate) fn dotted_underscored(b: u8) -> bool {
    dotted(b) || b == b'_'
}

/// `[0-9_]`.
pub(crate) fn underscored(b: u8) -> bool {
    b.is_ascii_digit() || b == b'_'
}

/// `[0-9]`.
pub(crate) fn digit(b: u8) -> bool {
    b.is_ascii_digit()
}

/// `/\b<word>\b/i` for a word that starts and ends with a word byte.
pub(crate) fn contains_word_ci(haystack: &[u8], word: &str) -> bool {
    let mut from = 0;
    while let Some(at) = find_ci(haystack, word.as_bytes(), from) {
        if word_start(haystack, at) && word_end(haystack, at + word.len()) {
            return true;
        }
        from = at + 1;
    }
    false
}

/// `/\b(<prefix>[A-Z0-9]{min,})\b/i`: the first such word, from the
/// prefix to the end of its alphanumeric run.
///
/// With `/i` the class takes both cases. The greedy run can only end at a
/// boundary where the alphanumerics stop, so the match is the whole run,
/// provided no `_` follows it.
pub(crate) fn prefixed_word_ci<'a>(haystack: &'a [u8], prefix: &str, min: usize) -> Option<&'a [u8]> {
    let mut from = 0;
    while let Some(at) = find_ci(haystack, prefix.as_bytes(), from) {
        from = at + 1;
        if !word_start(haystack, at) {
            continue;
        }
        let run = run(haystack, at + prefix.len(), |b| b.is_ascii_alphanumeric());
        let end = at + prefix.len() + run;
        if run >= min && word_end(haystack, end) {
            return Some(&haystack[at..end]);
        }
    }
    None
}

/// The length of the run of bytes matching `class` from `at`.
pub(crate) fn run(haystack: &[u8], at: usize, class: impl Fn(u8) -> bool) -> usize {
    haystack.get(at..).map_or(0, |rest| rest.iter().position(|&b| !class(b)).unwrap_or(rest.len()))
}

/// `/(?:<token>|...)[<separators>](<first><rest>*)/i`: group 1 of the
/// leftmost match, taking the tokens in order at each position.
///
/// The leftmost match of each token is found separately: the earliest
/// start wins, and on a tie the token listed first, as PCRE tries the
/// alternatives at each start position in order. The capture is greedy and
/// nothing follows it, so it is the whole run.
pub(crate) fn version_after<'a>(
    haystack: &'a [u8],
    tokens: &[&str],
    separators: &[u8],
    first: fn(u8) -> bool,
    rest: fn(u8) -> bool,
) -> Option<&'a [u8]> {
    let mut best: Option<(usize, &'a [u8])> = None;
    for token in tokens {
        let mut from = 0;
        while let Some(at) = find_ci(haystack, token.as_bytes(), from) {
            if best.is_some_and(|(start, _)| at >= start) {
                break;
            }
            from = at + 1;
            let separator = at + token.len();
            let start = separator + 1;
            if !haystack.get(separator).is_some_and(|b| separators.contains(b))
                || !haystack.get(start).is_some_and(|&b| first(b))
            {
                continue;
            }
            let end = start + 1 + run(haystack, start + 1, rest);
            best = Some((at, &haystack[start..end]));
            break;
        }
    }
    best.map(|(_, version)| version)
}

/// `/<token>\/([0-9.]+)/i`.
pub(crate) fn slash_version<'a>(haystack: &'a [u8], token: &str) -> Option<&'a [u8]> {
    version_after(haystack, &[token], b"/", dotted, dotted)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn finds_ignoring_case() {
        assert_eq!(find_ci(b"Mozilla/5.0 CHROME/1", b"chrome", 0), Some(12));
        assert_eq!(find_ci(b"abc", b"abcd", 0), None);
        assert_eq!(find_ci(b"aXbxB", b"xb", 2), Some(3));
        assert_eq!(find_ci(b"5.0 / 5.0", b"/ 5", 0), Some(4));
        assert_eq!(find_ci(b"\xffA", b"a", 0), Some(1));
        assert!(!contains_ci(b"\xc3\xa9", "\u{c9}"));
    }

    #[test]
    fn captures_versions() {
        let ua = b"Edg/x Edge/18.1 Edg/2";
        assert_eq!(version_after(ua, &["EdgA", "EdgiOS", "Edg", "Edge"], b"/", dotted, dotted), Some(&b"18.1"[..]));
        assert_eq!(slash_version(b"Chrome/ Chrome/.5a", "chrome"), Some(&b".5"[..]));
        assert_eq!(prefixed_word_ci(b"x KFMAWI)", "KF", 2), Some(&b"KFMAWI"[..]));
        assert_eq!(prefixed_word_ci(b"x KFMAWI_", "KF", 2), None);
        assert_eq!(prefixed_word_ci(b"xKFMA KFA KFAB", "kf", 2), Some(&b"KFAB"[..]));
        assert!(contains_word_ci(b"(Red Hat)", "Red Hat"));
        assert!(!contains_word_ci(b"Ubuntus", "Ubuntu"));
    }
}
