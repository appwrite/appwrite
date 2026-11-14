//! Version strings as the detectors normalise them.

use std::borrow::Cow;

use php_std::string;

/// `trim(str_replace('_', '.', $version), '.-')`.
pub(crate) fn normalize(version: &[u8]) -> Cow<'_, [u8]> {
    match string::str_replace(b"_", b".", version).0 {
        Cow::Borrowed(v) => Cow::Borrowed(string::trim(v, b".-")),
        Cow::Owned(v) => Cow::Owned(string::trim(&v, b".-").to_vec()),
    }
}

/// `implode('.', array_slice(explode('.', version($version)), 0, 2))`: the
/// normalised version up to its second dot.
pub(crate) fn display(version: &[u8]) -> Cow<'_, [u8]> {
    let normalized = normalize(version);
    let end = normalized.iter().enumerate().filter(|&(_, &b)| b == b'.').nth(1).map(|(i, _)| i);
    match (normalized, end) {
        (v, None) => v,
        (Cow::Borrowed(v), Some(end)) => Cow::Borrowed(&v[..end]),
        (Cow::Owned(mut v), Some(end)) => {
            v.truncate(end);
            Cow::Owned(v)
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn normalizes() {
        assert_eq!(&*normalize(b"17_4_1"), b"17.4.1");
        assert_eq!(&*normalize(b"._1.2-"), b"1.2");
        assert_eq!(&*normalize(b""), b"");
        assert_eq!(&*display(b"126.0.6478.54"), b"126.0");
        assert_eq!(&*display(b"1..2"), b"1.");
        assert_eq!(&*display(b"10_15_7"), b"10.15");
        assert_eq!(&*display(b"5"), b"5");
    }
}
