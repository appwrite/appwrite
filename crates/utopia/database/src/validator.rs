//! The key validators: `Key`, `Label` and `UID` of `Utopia\Database\Validator`.
//!
//! Over bytes, as PHP reads a string: what `isValid()` accepts and what `getDescription()` says,
//! for a `$maxLength`. The rules are 10.2's: #14308's `legacy/validator` `key` and `label`, and
//! `uid` with 10.2's description (the legacy one is 8.x's).
//!
//! ```
//! use utopia_database::validator;
//!
//! assert!(validator::key(b"movie_1", false, 36));
//! assert!(!validator::key(b"_movie", false, 36));
//! assert!(!validator::key(b"$id", false, 36));
//! assert!(validator::key(b"$id", true, 36));
//! assert!(validator::label(b"vip2", false, 36));
//! assert!(!validator::label(b"v.i.p", false, 36));
//! ```

/// `Database::MAX_UID_DEFAULT_LENGTH`: the `$maxLength` the constructors default to.
pub const MAX_UID_DEFAULT_LENGTH: i64 = 36;

/// The internal keys an `$allowInternal` key may be: `Document::ID`, `CREATED_AT` and
/// `UPDATED_AT`.
pub const INTERNAL: [&[u8]; 3] = [b"$id", b"$createdAt", b"$updatedAt"];

/// What a key refuses before its length: the string's first problem, as `Key::isValid()` meets
/// it, so a caller reads `$allowInternal` and `$maxLength` only when PHP does.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Shape {
    /// Empty, or led by `_`, `.` or `-`: refused.
    Refused,
    /// Led by `$`: an internal key if `$allowInternal`, and then only one of [`INTERNAL`].
    Internal,
    /// Only `A-Z`, `a-z`, `0-9`, `_`, `-` and `.`: a key if no longer than `$maxLength`.
    Plain,
    /// Another byte somewhere: refused.
    Invalid,
}

/// The [`Shape`] of `value`.
///
/// PHP: `mb_substr($value, 0, 1)` is the first byte for every leading byte that matters (they are
/// ASCII), and `preg_match('/[^A-Za-z0-9\_\-\.]/', $value)` reads bytes.
pub fn shape(value: &[u8]) -> Shape {
    match value.first() {
        None | Some(b'_' | b'.' | b'-') => Shape::Refused,
        Some(b'$') => Shape::Internal,
        Some(_) if value.iter().all(|c| c.is_ascii_alphanumeric() || matches!(c, b'_' | b'-' | b'.')) => Shape::Plain,
        Some(_) => Shape::Invalid,
    }
}

/// Whether `value` is one of the internal keys (`in_array($value, $allowList)`: loose, but a
/// string led by `$` is never numeric, so it compares bytes).
pub fn internal(value: &[u8]) -> bool {
    INTERNAL.contains(&value)
}

/// `Key::isValid()` of a string, with `$allowInternal` and `$maxLength`. Only ASCII is left when
/// the length is checked, so `mb_strlen()` is the byte length.
pub fn key(value: &[u8], allow_internal: bool, max_length: i64) -> bool {
    match shape(value) {
        Shape::Refused | Shape::Invalid => false,
        Shape::Internal => allow_internal && internal(value),
        Shape::Plain => fits(value, max_length),
    }
}

/// `mb_strlen($value) <= $maxLength` for an ASCII `value`.
pub fn fits(value: &[u8], max_length: i64) -> bool {
    i64::try_from(value.len()).is_ok_and(|length| length <= max_length)
}

/// `Label::isValid()`'s own check, after `Key::isValid()`: `A-Z`, `a-z` and `0-9` only.
pub fn alphanumeric(value: &[u8]) -> bool {
    value.iter().all(u8::is_ascii_alphanumeric)
}

/// `Label::isValid()` of a string.
pub fn label(value: &[u8], allow_internal: bool, max_length: i64) -> bool {
    key(value, allow_internal, max_length) && alphanumeric(value)
}

/// Appends `Key`'s message for `max_length` to `out`.
pub fn key_description(max_length: i64, out: &mut Vec<u8>) {
    out.extend_from_slice(b"Parameter must contain at most ");
    out.extend_from_slice(max_length.to_string().as_bytes());
    out.extend_from_slice(
        b" chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char",
    );
}

/// Appends `Label`'s message for `max_length` to `out`.
pub fn label_description(max_length: i64, out: &mut Vec<u8>) {
    out.extend_from_slice(b"Value must be a valid string between 1 and ");
    out.extend_from_slice(max_length.to_string().as_bytes());
    out.extend_from_slice(b" chars containing only alphanumeric chars");
}

/// Appends `UID::getDescription()` for `max_length` to `out`.
pub fn uid_description(max_length: i64, out: &mut Vec<u8>) {
    out.extend_from_slice(b"UID must contain at most ");
    out.extend_from_slice(max_length.to_string().as_bytes());
    out.extend_from_slice(
        b" chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a leading period, hyphen, or underscore",
    );
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn keys() {
        for valid in [&b"a"[..], b"abc-DEF_09.x", b"0", b"A.", b"x-"] {
            assert!(key(valid, false, 36), "{valid:?}");
        }
        for invalid in [&b""[..], b"_a", b".a", b"-a", b"a b", b"h\xc3\xa9", b"a\0", b"$id", b"a$"] {
            assert!(!key(invalid, false, 36), "{invalid:?}");
        }
        assert!(key(b"$id", true, 36));
        assert!(key(b"$createdAt", true, 1));
        assert!(!key(b"$sequence", true, 36));
        assert!(!key(b"$ID", true, 36));
        assert!(key(&[b'x'; 36], false, 36));
        assert!(!key(&[b'x'; 37], false, 36));
        assert!(!key(b"a", false, 0));
        assert!(!key(b"a", false, -1));
    }

    #[test]
    fn labels() {
        assert!(label(b"vip2", false, 36));
        assert!(!label(b"v.i.p", false, 36));
        assert!(!label(b"$id", true, 36));
        assert!(!label(b"a_b", false, 36));
    }

    #[test]
    fn descriptions() {
        let mut out = Vec::new();
        key_description(36, &mut out);
        assert_eq!(
            out,
            b"Parameter must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char"
        );
        out.clear();
        label_description(-5, &mut out);
        assert_eq!(out, b"Value must be a valid string between 1 and -5 chars containing only alphanumeric chars");
        out.clear();
        uid_description(255, &mut out);
        assert!(out.starts_with(b"UID must contain at most 255 chars."));
    }
}
