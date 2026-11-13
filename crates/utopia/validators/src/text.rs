//! Strings of bounded length, and identifiers.

use std::borrow::Cow;

use php_std::value::numeric_str;
use php_std::{mb, pcre};

use crate::input::loose_str_eq;
use crate::{Error, Input, Type, Validator, Verdict, is_valid_via_validate};

/// `preg_match($pattern, $subject) === 1`.
pub(crate) fn preg_matches(pattern: &[u8], subject: &[u8]) -> bool {
    matches!(pcre::preg_match_bare(pattern, subject), Ok(p) if p.value == pcre::Value::Int(1))
}

/// What an anchored `/^...$/` pattern (without `/D`) must match whole when
/// its classes exclude `\n`: the subject, without the one final newline
/// before which `$` also matches.
pub(crate) fn dollar_body(subject: &[u8]) -> &[u8] {
    subject.strip_suffix(b"\n").unwrap_or(subject)
}

/// `/^[1-9]\d{6,14}$/` on [`dollar_body`]: 7 to 15 ASCII digits, the first
/// not 0.
pub(crate) fn is_e164_digits(body: &[u8]) -> bool {
    (7..=15).contains(&body.len()) && body[0] != b'0' && body.iter().all(u8::is_ascii_digit)
}

/// A string of `min` to `length` characters (`length` 0 is unlimited),
/// optionally made only of the characters in `allow_list`, and optionally not
/// blank.
///
/// PHP: `Utopia\Validator\Text`.
#[derive(Debug, Clone)]
pub struct Text {
    /// The most characters allowed; 0 for no limit.
    pub length: usize,
    /// The fewest characters allowed.
    pub min: usize,
    /// The only characters allowed, compared byte by byte; empty allows any.
    pub allow_list: Vec<Cow<'static, str>>,
    /// Whether values made only of whitespace, separators and format characters are refused.
    pub require_non_blank: bool,
}

impl Text {
    /// The digits `0` to `9`, for [`Text::with_allow_list`].
    pub const NUMBERS: [&'static str; 10] = ["0", "1", "2", "3", "4", "5", "6", "7", "8", "9"];
    /// The letters `A` to `Z`, for [`Text::with_allow_list`].
    pub const ALPHABET_UPPER: [&'static str; 26] = [
        "A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R", "S", "T", "U", "V",
        "W", "X", "Y", "Z",
    ];
    /// The letters `a` to `z`, for [`Text::with_allow_list`].
    pub const ALPHABET_LOWER: [&'static str; 26] = [
        "a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v",
        "w", "x", "y", "z",
    ];

    /// Text with a maximum length (0 = unlimited) and a minimum of 1 char.
    pub const fn new(length: usize) -> Self {
        Self::with_min(length, 1)
    }

    /// Text of at least `min` and at most `length` characters (`length` 0 is unlimited).
    pub const fn with_min(length: usize, min: usize) -> Self {
        Self { length, min, allow_list: Vec::new(), require_non_blank: false }
    }

    /// Allows only these characters, compared byte by byte; a digit also matches
    /// an entry of the same numeric value.
    ///
    /// PHP: compared loosely, as PHP's `in_array` does.
    pub fn with_allow_list<I, S>(mut self, list: I) -> Self
    where
        I: IntoIterator<Item = S>,
        S: Into<Cow<'static, str>>,
    {
        self.allow_list = list.into_iter().map(Into::into).collect();
        self
    }

    /// Rejects values made only of Unicode separators, whitespace and format characters.
    pub fn non_blank(mut self) -> Self {
        self.require_non_blank = true;
        self
    }

    fn accepts(&self, s: &[u8]) -> bool {
        if self.require_non_blank && !preg_matches(br"/[^\p{Z}\p{Cf}\s]/u", s) {
            return false;
        }
        let len = mb::mb_strlen(s);
        if len < self.min || (self.length != 0 && len > self.length) {
            return false;
        }
        // `str_split()` then a loose `in_array()` per byte. `==` compares two
        // strings as numbers only when both are numeric, so a byte that is not
        // numeric needs no more than a byte comparison.
        self.allow_list.is_empty()
            || s.chunks(1).all(|c| {
                let numeric = std::str::from_utf8(c).ok().and_then(numeric_str).is_some();
                self.allow_list.iter().any(|a| if numeric { loose_str_eq(c, a.as_bytes()) } else { c == a.as_bytes() })
            })
    }
}

impl Validator for Text {
    /// `Value must be a valid string`, followed by the length, character and blank limits that apply.
    fn description(&self) -> String {
        let mut message = String::from("Value must be a valid string");
        if self.min == self.length {
            message.push_str(&format!(" and exactly {} chars", self.length));
        } else {
            if self.min != 0 {
                message.push_str(&format!(" and at least {} chars", self.min));
            }
            if self.length != 0 {
                message.push_str(&format!(" and no longer than {} chars", self.length));
            }
        }
        if !self.allow_list.is_empty() {
            message.push_str(&format!(" and only consist of '{}' chars", self.allow_list.join(", ")));
        }
        if self.require_non_blank {
            message.push_str(" and not be blank");
        }
        message
    }

    is_valid_via_validate!();

    /// Valid for a string within the limits; length counts UTF-8 characters.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(value.as_str().is_some_and(|s| self.accepts(s))))
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}

/// An identifier: ASCII letters, digits and underscores, not starting with a
/// digit, of at most `length` characters (0 for no limit).
///
/// PHP: `Utopia\Validator\Identifier`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Identifier {
    /// The most characters allowed; 0 for no limit.
    pub length: usize,
}

impl Identifier {
    /// An identifier of at most `length` characters (0 for no limit).
    pub const fn new(length: usize) -> Self {
        Self { length }
    }
}

/// `/^[A-Za-z_]\w*$/D`: `\w` is ASCII without `/u`, and `/D` takes no final
/// newline.
pub(crate) fn is_identifier(s: &[u8]) -> bool {
    s.first().is_some_and(|&b| b.is_ascii_alphabetic() || b == b'_')
        && s.iter().all(|&b| b.is_ascii_alphanumeric() || b == b'_')
}

impl Validator for Identifier {
    /// `Value must contain only letters, digits and underscores and must not start
    /// with a digit`, with the length limit when there is one.
    fn description(&self) -> String {
        let mut description =
            String::from("Value must contain only letters, digits and underscores and must not start with a digit");
        if self.length != 0 {
            description.push_str(&format!(", and be at most {} chars", self.length));
        }
        description.push('.');
        description
    }

    is_valid_via_validate!();

    /// Valid for a non-empty identifier within the length limit.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(value.as_str().is_some_and(|s| Text::with_min(self.length, 1).accepts(s) && is_identifier(s))))
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}
