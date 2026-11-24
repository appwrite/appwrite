//! The text leaf validators: `Text`, `Identifier`, `WhiteList`, `Contains` and `Label`.
//!
//! The parts that compare a value with the PHP values a validator holds (`in_array()`
//! over an allow list or a white list, `(string)` of a pattern) stay with the PHP engine, in
//! the extension; what is left here works on bytes, as the PHP methods do once they have a
//! string.

use php_std::{mb, pcre};

use crate::decimal;

/// `preg_match('/[^\p{Z}\p{Cf}\s]/u', $value) !== 1`: the value holds only Unicode separators,
/// format characters and whitespace (nothing at all included), or is not UTF-8, which
/// `preg_match()` refuses.
///
/// PHP: `Text::isValid()` with `$requireNonBlank`.
pub fn is_blank(value: &[u8]) -> bool {
    !matches!(
        pcre::preg_match_bare(br"/[^\p{Z}\p{Cf}\s]/u", value),
        Ok(result) if result.value == pcre::Value::Int(1)
    )
}

/// PHP `mb_strlen($value)`: each sequence that is not UTF-8 counts as one character.
pub fn chars(value: &[u8]) -> i64 {
    i64::try_from(mb::mb_strlen(value)).unwrap_or(i64::MAX)
}

/// `mb_strlen($value) >= $min` and, for a `$length` other than 0, `mb_strlen($value) <= $length`.
///
/// PHP: `Text::isValid()`'s length checks.
pub fn text_length(value: &[u8], min: i64, length: i64) -> bool {
    let chars = chars(value);
    chars >= min && !(chars > length && length != 0)
}

/// `Text::getDescription()`, given `implode(', ', $this->allowList)` when the list is not
/// empty.
///
/// Appended to `message`.
pub fn text_description(
    length: i64,
    min: i64,
    allow_list: Option<&[u8]>,
    require_non_blank: bool,
    message: &mut Vec<u8>,
) {
    message.extend_from_slice(b"Value must be a valid string");
    if min == length {
        message.extend_from_slice(b" and exactly ");
        decimal(length, message);
        message.extend_from_slice(b" chars");
    } else {
        if min != 0 {
            message.extend_from_slice(b" and at least ");
            decimal(min, message);
            message.extend_from_slice(b" chars");
        }
        if length != 0 {
            message.extend_from_slice(b" and no longer than ");
            decimal(length, message);
            message.extend_from_slice(b" chars");
        }
    }
    if let Some(list) = allow_list {
        message.extend_from_slice(b" and only consist of '");
        message.extend_from_slice(list);
        message.extend_from_slice(b"' chars");
    }
    if require_non_blank {
        message.extend_from_slice(b" and not be blank");
    }
}

/// `preg_match('/^[A-Za-z_]\w*$/D', $value) === 1`: an ASCII letter or `_`, then ASCII letters,
/// digits and `_` (the pattern has no `/u`, so `\w` is ASCII), up to the very end.
///
/// PHP: `Identifier::isValid()` after `Text::isValid()`.
pub fn identifier(value: &[u8]) -> bool {
    match value.split_first() {
        Some((first, rest)) => {
            (first.is_ascii_alphabetic() || *first == b'_')
                && rest.iter().all(|b| b.is_ascii_alphanumeric() || *b == b'_')
        }
        None => false,
    }
}

/// `Identifier::getDescription()`, appended to `description`.
pub fn identifier_description(length: i64, description: &mut Vec<u8>) {
    description
        .extend_from_slice(b"Value must contain only letters, digits and underscores and must not start with a digit");
    if length != 0 {
        description.extend_from_slice(b", and be at most ");
        decimal(length, description);
        description.extend_from_slice(b" chars");
    }
    description.push(b'.');
}

/// PHP `strtolower()`: ASCII only, whatever the locale (PHP 8.2 and later).
pub fn ascii_lower(value: &[u8]) -> Vec<u8> {
    value.to_ascii_lowercase()
}

/// What `WhiteList::getDescription()` puts around `implode(', ', $this->list)`.
pub const WHITE_LIST_DESCRIPTION: (&str, &str) = ("Value must be one of (", ")");

/// `WhiteList::getDescription()`, given `implode(', ', $this->list)`, appended to `message`.
pub fn white_list_description(list: &[u8], message: &mut Vec<u8>) {
    message.extend_from_slice(WHITE_LIST_DESCRIPTION.0.as_bytes());
    message.extend_from_slice(list);
    message.extend_from_slice(WHITE_LIST_DESCRIPTION.1.as_bytes());
}

/// PHP `mb_strtolower($value, 'UTF-8')`: full lower case, the final sigma rule, and `?` for
/// each sequence that is not UTF-8.
///
/// PHP: `Contains::isValid()` when it is not strict.
pub fn lower(value: &[u8]) -> Vec<u8> {
    mb::mb_strtolower(value)
}

/// PHP `str_contains($haystack, $needle)`: an empty needle is in every haystack.
pub fn contains(haystack: &[u8], needle: &[u8]) -> bool {
    needle.is_empty() || haystack.windows(needle.len()).any(|window| window == needle)
}

/// `Contains::getDescription()`, given `implode(', ', $this->patterns)`, appended to `message`.
pub fn contains_description(patterns: &[u8], strict: bool, message: &mut Vec<u8>) {
    message.extend_from_slice(b"Value must contain one of (");
    message.extend_from_slice(patterns);
    message.push(b')');
    message.extend_from_slice(if strict { b" (case-sensitive)" } else { b" (case-insensitive)" });
}

/// `Label::MAX_LENGTH`: the longest DNS label.
pub const LABEL_MAX_LENGTH: i64 = 63;

/// `Label::isValid()` on a string: 1 to 63 bytes of lowercase ASCII letters, digits and
/// hyphens, not starting or ending with a hyphen. (`ctype_alnum()` of what is left without the
/// hyphens, so a label of hyphens only is refused.)
pub fn label(value: &[u8]) -> bool {
    if value.is_empty() || value.len() > LABEL_MAX_LENGTH as usize || value.starts_with(b"-") || value.ends_with(b"-") {
        return false;
    }
    let rest: Vec<u8> = value.iter().copied().filter(|b| *b != b'-').collect();
    !rest.is_empty() && rest.iter().all(u8::is_ascii_alphanumeric) && !value.iter().any(u8::is_ascii_uppercase)
}

/// `Label::getDescription()`: `Label::MAX_LENGTH` is [`LABEL_MAX_LENGTH`].
pub const LABEL_DESCRIPTION: &str =
    "Value must be 1 to 63 lowercase letters, digits and hyphens, and must not start or end with a hyphen.";

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn blank() {
        assert!(is_blank(b""));
        assert!(is_blank(b" \t\n\r\x0b\x0c"));
        assert!(is_blank("\u{00a0}\u{2028}\u{200b}\u{feff}".as_bytes()));
        assert!(!is_blank(b" a "));
        // Not UTF-8: `preg_match()` fails, so the value is refused as blank.
        assert!(is_blank(b"a\xff"));
    }

    #[test]
    fn lengths() {
        assert!(text_length("héllo".as_bytes(), 5, 5));
        assert!(!text_length("héllo".as_bytes(), 6, 0));
        assert!(text_length(b"", 0, 0));
        assert!(!text_length(b"abc", 1, 2));
        assert!(text_length(b"abc", 1, 0));
    }

    #[test]
    fn descriptions() {
        fn described(describe: impl FnOnce(&mut Vec<u8>)) -> Vec<u8> {
            let mut out = Vec::new();
            describe(&mut out);
            out
        }
        assert_eq!(
            described(|out| text_description(10, 10, None, false, out)),
            b"Value must be a valid string and exactly 10 chars"
        );
        assert_eq!(
            described(|out| text_description(10, 1, Some(b"a, b"), true, out)),
            b"Value must be a valid string and at least 1 chars and no longer than 10 chars and only consist of 'a, b' chars and not be blank"
        );
        assert_eq!(
            described(|out| text_description(0, 0, None, false, out)),
            b"Value must be a valid string and exactly 0 chars"
        );
        assert_eq!(
            described(|out| text_description(-5, 2, None, false, out)),
            b"Value must be a valid string and at least 2 chars and no longer than -5 chars"
        );
        assert_eq!(
            described(|out| identifier_description(0, out)),
            b"Value must contain only letters, digits and underscores and must not start with a digit."
        );
        assert_eq!(
            described(|out| identifier_description(64, out)),
            b"Value must contain only letters, digits and underscores and must not start with a digit, and be at most 64 chars."
        );
        assert_eq!(
            described(|out| contains_description(b"a, b", false, out)),
            b"Value must contain one of (a, b) (case-insensitive)"
        );
        assert_eq!(described(|out| white_list_description(b"a, b", out)), b"Value must be one of (a, b)");
        assert_eq!(
            LABEL_DESCRIPTION,
            format!(
                "Value must be 1 to {LABEL_MAX_LENGTH} lowercase letters, digits and hyphens, and must not start or end with a hyphen."
            )
        );
    }

    #[test]
    fn identifiers() {
        assert!(identifier(b"_a1"));
        assert!(identifier(b"A"));
        assert!(!identifier(b"1a"));
        assert!(!identifier(b"a-b"));
        assert!(!identifier(b"a\n"));
        assert!(!identifier("é".as_bytes()));
        assert!(!identifier(b""));
    }

    #[test]
    fn labels() {
        assert!(label(b"a-1"));
        assert!(!label(b"A"));
        assert!(!label(b"-a"));
        assert!(!label(b"a-"));
        assert!(!label(b"a.b"));
        assert!(!label(&[b'a'; 64]));
        assert!(label(&[b'a'; 63]));
        assert!(!label(b""));
    }

    #[test]
    fn contains_needles() {
        assert!(contains(b"abc", b""));
        assert!(contains(b"abc", b"bc"));
        assert!(!contains(b"", b"a"));
        assert_eq!(lower("ÀB\u{03a3}".as_bytes()), "àbς".as_bytes());
    }
}
