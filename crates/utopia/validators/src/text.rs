use std::borrow::Cow;

use php_std::value::numeric_str;
use php_std::{mb, pcre};

use crate::input::loose_str_eq;
use crate::{Error, Input, Type, Validator, Verdict, is_valid_via_validate};

/// `preg_match($pattern, $subject) === 1`.
pub(crate) fn preg_matches(pattern: &[u8], subject: &[u8]) -> bool {
    matches!(pcre::preg_match_bare(pattern, subject), Ok(p) if p.value == pcre::Value::Int(1))
}

/// `Utopia\Validator\Text`: a string of `min..=length` characters
/// (`length` 0 is unlimited), optionally made only of the bytes in
/// `allow_list` and not blank.
#[derive(Debug, Clone)]
pub struct Text {
    pub length: usize,
    pub min: usize,
    pub allow_list: Vec<Cow<'static, str>>,
    pub require_non_blank: bool,
}

impl Text {
    pub const NUMBERS: [&'static str; 10] = ["0", "1", "2", "3", "4", "5", "6", "7", "8", "9"];
    pub const ALPHABET_UPPER: [&'static str; 26] = [
        "A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R", "S", "T", "U", "V",
        "W", "X", "Y", "Z",
    ];
    pub const ALPHABET_LOWER: [&'static str; 26] = [
        "a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v",
        "w", "x", "y", "z",
    ];

    /// Text with a maximum length (0 = unlimited) and a minimum of 1 char.
    pub const fn new(length: usize) -> Self {
        Self::with_min(length, 1)
    }

    pub const fn with_min(length: usize, min: usize) -> Self {
        Self { length, min, allow_list: Vec::new(), require_non_blank: false }
    }

    /// Only these characters (compared byte by byte, loosely, as PHP's `in_array`).
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

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(value.as_str().is_some_and(|s| self.accepts(s))))
    }

    fn kind(&self) -> Type {
        Type::String
    }
}

/// `Utopia\Validator\Identifier`: a C-style identifier (letters, digits and
/// underscores, not starting with a digit) of at most `length` characters
/// (0 = unlimited).
#[derive(Debug, Clone, Copy, Default)]
pub struct Identifier {
    pub length: usize,
}

impl Identifier {
    pub const fn new(length: usize) -> Self {
        Self { length }
    }
}

impl Validator for Identifier {
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

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(
            value
                .as_str()
                .is_some_and(|s| Text::with_min(self.length, 1).accepts(s) && preg_matches(br"/^[A-Za-z_]\w*$/D", s)),
        ))
    }

    fn kind(&self) -> Type {
        Type::String
    }
}
