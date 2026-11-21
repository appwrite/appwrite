//! String validators: strings of bounded length, identifiers, substrings,
//! globs, colors and phone numbers.

use std::borrow::Cow;

use php_std::pcre;
use php_std::value::numeric_str;

use crate::input::loose_str_eq;
use crate::{Error, Input, Type, Validator, Verdict, compose, is_valid_via_validate, network, scalar, text};

/// `preg_match($pattern, $subject) === 1`.
pub(crate) fn preg_matches(pattern: &[u8], subject: &[u8]) -> bool {
    matches!(pcre::preg_match_bare(pattern, subject), Ok(p) if p.value == pcre::Value::Int(1))
}

/// The `i64` PHP holds for a length: lengths beyond `i64::MAX` saturate.
fn php_int(n: usize) -> i64 {
    i64::try_from(n).unwrap_or(i64::MAX)
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
        if self.require_non_blank && text::is_blank(s) {
            return false;
        }
        if !text::text_length(s, php_int(self.min), php_int(self.length)) {
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
        let allow_list = (!self.allow_list.is_empty()).then(|| self.allow_list.join(", "));
        let mut description = Vec::new();
        text::text_description(
            php_int(self.length),
            php_int(self.min),
            allow_list.as_deref().map(str::as_bytes),
            self.require_non_blank,
            &mut description,
        );
        String::from_utf8_lossy(&description).into_owned()
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

impl Validator for Identifier {
    /// `Value must contain only letters, digits and underscores and must not start
    /// with a digit`, with the length limit when there is one.
    fn description(&self) -> String {
        let mut description = Vec::new();
        text::identifier_description(php_int(self.length), &mut description);
        String::from_utf8_lossy(&description).into_owned()
    }

    is_valid_via_validate!();

    /// Valid for a non-empty identifier within the length limit.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(
            value.as_str().is_some_and(|s| Text::with_min(self.length, 1).accepts(s) && text::identifier(s)),
        ))
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}

/// A string containing at least one of the patterns, case-insensitively unless
/// strict.
///
/// PHP: `Utopia\Validator\Contains`; case is folded with `mb_strtolower`.
#[derive(Debug, Clone)]
pub struct Contains {
    patterns: Vec<String>,
    /// The patterns as matched: lower-cased unless strict.
    needles: Vec<Vec<u8>>,
    strict: bool,
}

impl Contains {
    /// A validator for strings containing one of `patterns`, compared
    /// case-sensitively when `strict`.
    ///
    /// Fails with [`Error::InvalidArgument`] for an empty list.
    ///
    /// PHP: `new Contains($patterns, $strict)`.
    pub fn new(patterns: Vec<String>, strict: bool) -> Result<Self, Error> {
        if patterns.is_empty() {
            return Err(Error::InvalidArgument("Patterns array cannot be empty".into()));
        }
        let needles =
            patterns.iter().map(|p| if strict { p.as_bytes().to_vec() } else { text::lower(p.as_bytes()) }).collect();
        Ok(Self { patterns, needles, strict })
    }

    /// The patterns, as given.
    pub fn patterns(&self) -> &[String] {
        &self.patterns
    }
}

impl Validator for Contains {
    /// Lists the patterns and the case rule: `Value must contain one of (a, b) (case-insensitive)`.
    fn description(&self) -> String {
        let mut description = Vec::new();
        text::contains_description(self.patterns.join(", ").as_bytes(), self.strict, &mut description);
        String::from_utf8_lossy(&description).into_owned()
    }

    is_valid_via_validate!();

    /// Valid for a string containing at least one pattern.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let Some(s) = value.as_str() else {
            return Ok(Verdict::INVALID);
        };
        let lowered;
        let haystack = if self.strict {
            s
        } else {
            lowered = text::lower(s);
            &lowered
        };
        Ok(Verdict::of(self.needles.iter().any(|n| text::contains(haystack, n))))
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}

/// A string matching gitignore-style glob patterns (`*`, `**`, `?`, `[...]`,
/// `\` escapes, `!` exclusions).
///
/// Inclusions are OR-ed and exclusions AND-ed; a literal inclusion beats a
/// wildcard exclusion; otherwise the last matching pattern wins. Without
/// patterns every string is valid.
///
/// PHP: `Utopia\Validator\Globstar`.
#[derive(Debug, Clone, Default)]
pub struct Globstar {
    /// The glob patterns; a leading `!` makes one an exclusion.
    pub patterns: Vec<String>,
}

impl Globstar {
    /// A validator for the glob `patterns`.
    pub fn new(patterns: Vec<String>) -> Self {
        Self { patterns }
    }

    /// Whether `subject` matches the glob `pattern`, through the PCRE pattern PHP builds.
    fn matches(subject: &[u8], pattern: &[u8]) -> bool {
        preg_matches(&compose::globstar_regex(pattern), subject)
    }
}

/// The excluded pattern of a `!` pattern.
fn exclusion(pattern: &[u8]) -> Option<&[u8]> {
    compose::exclusion(pattern).then(|| &pattern[1..])
}

impl Validator for Globstar {
    /// Returns "Value must match a specific inclusion, or a wildcard inclusion not overridden by any exclusion.".
    fn description(&self) -> String {
        compose::GLOBSTAR_DESCRIPTION.to_owned()
    }

    is_valid_via_validate!();

    /// Valid for a string the patterns allow: any string without patterns, and any
    /// string no pattern excludes when all are exclusions.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let Some(subject) = value.as_str() else {
            return Ok(Verdict::INVALID);
        };
        if self.patterns.is_empty() {
            return Ok(Verdict::VALID);
        }
        let patterns = || self.patterns.iter().map(|p| p.as_bytes());
        if patterns().all(|p| exclusion(p).is_some()) {
            return Ok(Verdict::of(patterns().all(|p| !Self::matches(subject, exclusion(p).unwrap_or(p)))));
        }
        if patterns().any(|p| exclusion(p).is_none() && !compose::wildcard(p) && Self::matches(subject, p)) {
            return Ok(Verdict::VALID);
        }
        // The last matching wildcard inclusion or exclusion wins; literal
        // inclusions were decided above.
        let mut state = false;
        for p in patterns() {
            match exclusion(p) {
                Some(excluded) if Self::matches(subject, excluded) => state = false,
                None if compose::wildcard(p) && Self::matches(subject, p) => state = true,
                _ => {}
            }
        }
        Ok(Verdict::of(state))
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}

/// A hexadecimal color code of 3 or 6 digits, without `#`: `fff` or `ffffff`.
///
/// PHP: `Utopia\Validator\HexColor`.
#[derive(Debug, Clone, Copy, Default)]
pub struct HexColor;

impl Validator for HexColor {
    /// Returns "Value must be a valid Hex color code".
    fn description(&self) -> String {
        "Value must be a valid Hex color code".to_owned()
    }

    is_valid_via_validate!();

    /// Valid for a string of exactly 3 or 6 hexadecimal digits, in either case.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(scalar::hex_color(value.scalar())))
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}

/// An E.164 phone number (`+` and 7 to 15 digits, the first not 0),
/// optionally repaired by [`Phone::normalize`] first.
///
/// PHP: `Utopia\Validator\Phone`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Phone {
    /// Whether the empty string is valid.
    pub allow_empty: bool,
    /// Whether to repair the number with [`Phone::normalize`] before checking it.
    pub normalize: bool,
}

impl Phone {
    /// Recovers a phone number from URL transport damage: `%2B` left encoded, `+`
    /// decoded to a space, or `+` omitted.
    ///
    /// PHP: `Phone::normalize()`.
    pub fn normalize(value: &[u8]) -> Vec<u8> {
        network::phone_normalize(value)
    }
}

impl Validator for Phone {
    /// Returns "Phone number must start with a '+' and contain between 7 and 15 digits.".
    fn description(&self) -> String {
        network::PHONE_DESCRIPTION.to_owned()
    }

    is_valid_via_validate!();

    /// Valid for a string that is an E.164 number, after repair when `normalize` is set.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let Some(s) = value.as_str() else {
            return Ok(Verdict::INVALID);
        };
        if self.allow_empty && s.is_empty() {
            return Ok(Verdict::VALID);
        }
        Ok(Verdict::of(if self.normalize { network::phone(&Self::normalize(s)) } else { network::phone(s) }))
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}

/// Accepts every value.
///
/// PHP: `Utopia\Validator\Wildcard`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Wildcard;

impl Validator for Wildcard {
    /// Returns "Every input is valid".
    fn description(&self) -> String {
        "Every input is valid".to_owned()
    }

    /// Always `true`.
    fn is_valid(&self, _value: &serde_json::Value) -> bool {
        true
    }

    /// Always valid.
    fn validate(&self, _value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::VALID)
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}
