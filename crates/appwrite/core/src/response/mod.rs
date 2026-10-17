//! Response models (`Appwrite\Utopia\Response\Model\*`).
//!
//! Models borrow from the typed documents and serialise straight to JSON in
//! the rule order PHP uses, applying defaults, `sensitive` blanking and the
//! response-format compatibility filters. No intermediate document maps are
//! built.

mod models;

pub use models::*;

use crate::datetime;
use chrono::NaiveDateTime;

/// A `X-Appwrite-Response-Format` version.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub struct Version(pub u32, pub u32, pub u32);

impl Version {
    pub fn parse(s: &str) -> Option<Self> {
        let mut parts = s.trim().split(['.', '-']).map(|p| p.parse::<u32>().ok());
        let major = parts.next()??;
        let minor = parts.next().flatten().unwrap_or(0);
        let patch = parts.next().flatten().unwrap_or(0);
        Some(Version(major, minor, patch))
    }

    /// The format `X-Appwrite-Response-Format` (or
    /// `_APP_SYSTEM_RESPONSE_FORMAT`) names, as `general.php` reads it: `None`
    /// when PHP skips it (empty or `"0"`), else the newest of the versions
    /// PHP's filters compare against (or `0.0.0`) that `version_compare()`
    /// does not put after it. Comparing the result with any of those
    /// versions answers what `version_compare($format, ...)` answers.
    pub fn from_format(format: &str) -> Option<Self> {
        if format.is_empty() || format == "0" {
            return None;
        }
        let mut found = Version(0, 0, 0);
        for &threshold in Self::THRESHOLDS {
            let Version(a, b, c) = threshold;
            if version_compare(format, &format!("{a}.{b}.{c}")) != std::cmp::Ordering::Less {
                found = threshold;
            }
        }
        Some(found)
    }

    /// Every version a request or response filter starts at (`general.php`),
    /// and the current one, in order.
    pub const THRESHOLDS: &[Version] = &[
        Version(1, 4, 0),
        Version(1, 5, 0),
        Version(1, 6, 0),
        Version(1, 7, 0),
        Version(1, 8, 0),
        Version(1, 9, 0),
        Version(1, 9, 1),
        Version(1, 9, 2),
        Version(1, 9, 3),
        Version(1, 9, 4),
        Version(1, 9, 5),
        Version(2, 0, 0),
        Version(2, 3, 0),
        Version(2, 4, 0),
        Version(2, 5, 0),
    ];
}

/// PHP's `version_compare($a, $b)`: both canonicalized (`-`, `_`, `+` and
/// other non-alphanumerics become `.`, and a `.` separates digits from
/// letters), then compared part by part: numbers by value, words by their
/// special form (`dev` < `alpha`/`a` < `beta`/`b` < `RC`/`rc` < a number <
/// `pl`/`p`, anything else first).
pub fn version_compare(a: &str, b: &str) -> std::cmp::Ordering {
    use std::cmp::Ordering;
    fn canonicalize(version: &str) -> Vec<u8> {
        let bytes = version.as_bytes();
        let Some((&first, rest)) = bytes.split_first() else { return Vec::new() };
        let digit = |c: u8| c.is_ascii_digit();
        let not_digit = |c: u8| !c.is_ascii_digit() && c != b'.';
        let mut out = vec![first];
        let mut last = first;
        for &c in rest {
            let separator = out.last() != Some(&b'.');
            if matches!(c, b'-' | b'_' | b'+') {
                if separator {
                    out.push(b'.');
                }
            } else if (not_digit(last) && digit(c)) || (digit(last) && not_digit(c)) {
                if separator {
                    out.push(b'.');
                }
                out.push(c);
            } else if !c.is_ascii_alphanumeric() {
                if separator {
                    out.push(b'.');
                }
            } else {
                out.push(c);
            }
            last = c;
        }
        out
    }
    fn special(form: &[u8]) -> i32 {
        const FORMS: [(&[u8], i32); 10] = [
            (b"dev", 0),
            (b"alpha", 1),
            (b"a", 1),
            (b"beta", 2),
            (b"b", 2),
            (b"RC", 3),
            (b"rc", 3),
            (b"#", 4),
            (b"pl", 5),
            (b"p", 5),
        ];
        FORMS.iter().find(|(name, _)| form.starts_with(name)).map_or(-1, |(_, order)| *order)
    }
    fn compare_special(a: &[u8], b: &[u8]) -> Ordering {
        special(a).cmp(&special(b))
    }
    // `strtol()`: the leading digits.
    fn number(part: &[u8]) -> i64 {
        part.iter()
            .take_while(|c| c.is_ascii_digit())
            .fold(0i64, |n, c| n.saturating_mul(10).saturating_add(i64::from(c - b'0')))
    }
    fn starts_digit(part: &[u8]) -> bool {
        part.first().is_some_and(u8::is_ascii_digit)
    }
    fn compare(a: &[u8], b: &[u8]) -> Ordering {
        match (a.is_empty(), b.is_empty()) {
            (true, true) => return Ordering::Equal,
            (true, false) => return Ordering::Less,
            (false, true) => return Ordering::Greater,
            _ => {}
        }
        let a = canonicalize(&String::from_utf8_lossy(a));
        let b = canonicalize(&String::from_utf8_lossy(b));
        // Each part with where it starts.
        fn parts(v: &[u8]) -> Vec<(usize, &[u8])> {
            let mut start = 0;
            v.split(|&c| c == b'.')
                .map(|part| {
                    let at = start;
                    start += part.len() + 1;
                    (at, part)
                })
                .collect()
        }
        let (left, right) = (parts(&a), parts(&b));
        // PHP's `p1`/`p2` (the part) and `n1`/`n2` (a `.` follows it).
        let (mut i, mut j) = (0, 0);
        let (mut n1, mut n2) = (true, true);
        while left[i].0 < a.len() && right[j].0 < b.len() && n1 && n2 {
            let (x, y) = (left[i].1, right[j].1);
            n1 = i + 1 < left.len();
            n2 = j + 1 < right.len();
            let result = match (starts_digit(x), starts_digit(y)) {
                (true, true) => number(x).cmp(&number(y)),
                (false, false) => compare_special(x, y),
                (true, false) => compare_special(b"#N#", y),
                (false, true) => compare_special(x, b"#N#"),
            };
            if result != Ordering::Equal {
                return result;
            }
            if n1 {
                i += 1;
            }
            if n2 {
                j += 1;
            }
        }
        // The longer one's rest, from its current part on.
        if n1 {
            let (at, x) = left[i];
            return if starts_digit(x) { Ordering::Greater } else { compare(&a[at..], b"#N#") };
        }
        if n2 {
            let (at, y) = right[j];
            return if starts_digit(y) { Ordering::Less } else { compare(b"#N#", &b[at..]) };
        }
        Ordering::Equal
    }
    compare(a.as_bytes(), b.as_bytes())
}

/// Rendering options shared by all models.
#[derive(Debug, Clone, Copy, Default)]
pub struct Render {
    /// Show `sensitive` fields (API keys and privileged users).
    pub sensitive: bool,
    /// Client response format, when it differs from the latest.
    pub format: Option<Version>,
}

impl Render {
    /// `true` when the client format is older than `version` (never while
    /// [`current`] renders).
    pub fn before(&self, major: u32, minor: u32, patch: u32) -> bool {
        !CURRENT.with(std::cell::Cell::get) && matches!(self.format, Some(v) if v < Version(major, minor, patch))
    }
}

thread_local! {
    /// Set while [`current`] serializes a model.
    static CURRENT: std::cell::Cell<bool> = const { std::cell::Cell::new(false) };
}

/// `model` as the latest format renders it, whatever the client's: the
/// event payload an action sets in PHP (`$response->output()`, before the
/// format filters; without one, events carry the filtered response).
pub fn current<T: serde::Serialize>(model: &T) -> Vec<u8> {
    struct Reset;
    impl Drop for Reset {
        fn drop(&mut self) {
            CURRENT.with(|c| c.set(false));
        }
    }
    CURRENT.with(|c| c.set(true));
    let _reset = Reset;
    crate::json::to_vec(model)
}

/// Datetime field (`''` when unset).
pub fn dt(value: &Option<NaiveDateTime>) -> String {
    value.as_ref().map(datetime::format_tz).unwrap_or_default()
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::cmp::Ordering::{Equal, Greater, Less};

    #[test]
    fn version_compare_matches_php() {
        // `php -r 'var_dump(version_compare($a, $b));'`
        let cases = [
            ("1.4.0", "1.5.0", Less),
            ("1.5.0", "1.5.0", Equal),
            ("1.5", "1.5.0", Less),
            ("1.5.0", "1.5", Greater),
            ("1.5.0-rc1", "1.5.0", Less),
            ("1.0rc1", "1.0.0", Less),
            ("1.0pl1", "1.0", Greater),
            ("1.0-dev", "1.0-alpha", Less),
            ("abc", "1.4.0", Less),
            ("2.5.1", "2.5.0", Greater),
            ("10.0.0", "9.9.9", Greater),
            ("", "1.0", Less),
            ("1.9.5", "2.0.0", Less),
            ("1.4.0.", "1.4.0", Less),
        ];
        for (a, b, expected) in cases {
            assert_eq!(version_compare(a, b), expected, "version_compare({a:?}, {b:?})");
        }
    }

    #[test]
    fn from_format_lands_on_the_filter_versions() {
        assert_eq!(Version::from_format(""), None);
        assert_eq!(Version::from_format("0"), None);
        assert_eq!(Version::from_format("1.4.0"), Some(Version(1, 4, 0)));
        assert_eq!(Version::from_format("1.8.1"), Some(Version(1, 8, 0)));
        assert_eq!(Version::from_format("1.5.0-rc1"), Some(Version(1, 4, 0)));
        assert_eq!(Version::from_format("abc"), Some(Version(0, 0, 0)));
        assert_eq!(Version::from_format("2.6.0"), Some(Version(2, 5, 0)));
    }
}
