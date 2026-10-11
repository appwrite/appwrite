//! String validators: substrings, globs, colors and phone numbers.

use php_std::encoding::rawurldecode;
use php_std::mb::mb_strtolower;
use php_std::pcre::preg_quote;
use php_std::string::str_contains;

use crate::text::{dollar_body, is_e164_digits, preg_matches};
use crate::{Error, Input, Type, Validator, Verdict, is_valid_via_validate};

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
            patterns.iter().map(|p| if strict { p.as_bytes().to_vec() } else { mb_strtolower(p.as_bytes()) }).collect();
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
        let case = if self.strict { "case-sensitive" } else { "case-insensitive" };
        format!("Value must contain one of ({}) ({case})", self.patterns.join(", "))
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
            lowered = mb_strtolower(s);
            &lowered
        };
        Ok(Verdict::of(self.needles.iter().any(|n| str_contains(haystack, n))))
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

    fn is_wildcard(pattern: &[u8]) -> bool {
        pattern.iter().any(|b| matches!(b, b'*' | b'?' | b'['))
    }

    fn quote(byte: u8, regex: &mut Vec<u8>) {
        regex.extend_from_slice(&preg_quote(&[byte], Some(b"~")));
    }

    /// Whether `subject` matches the glob `pattern`, through the PCRE pattern PHP builds.
    fn matches(subject: &[u8], pattern: &[u8]) -> bool {
        let len = pattern.len();
        let mut regex = b"~^".to_vec();
        let mut i = 0;
        while i < len {
            let c = pattern[i];
            if c == b'\\' && i + 1 < len {
                Self::quote(pattern[i + 1], &mut regex);
                i += 2;
            } else if c == b'[' {
                let mut j = i + 1;
                let mut content = Vec::new();
                let mut negated = false;
                if j < len && (pattern[j] == b'!' || pattern[j] == b'^') {
                    let marker = pattern[j];
                    j += 1;
                    // `[!]` and `[^]`: the marker is a literal member.
                    negated = !(j < len && pattern[j] == b']');
                    content.push(marker);
                }
                // `]` right after `[` (or the negation marker) is a member.
                if j < len && pattern[j] == b']' && (content.is_empty() || negated) {
                    content.push(b']');
                    j += 1;
                }
                while j < len && pattern[j] != b']' {
                    content.push(pattern[j]);
                    j += 1;
                }
                if j < len {
                    regex.push(b'[');
                    if negated && content.starts_with(b"!") {
                        regex.push(b'^');
                        regex.extend_from_slice(&content[1..]);
                    } else if !negated && content.starts_with(b"^") {
                        regex.extend_from_slice(b"\\^");
                        regex.extend_from_slice(&content[1..]);
                    } else {
                        regex.extend_from_slice(&content);
                    }
                    regex.push(b']');
                    i = j + 1;
                } else {
                    // An unclosed bracket is a literal `[`.
                    Self::quote(b'[', &mut regex);
                    i += 1;
                }
            } else if c == b'*' && i + 1 < len && pattern[i + 1] == b'*' {
                let prev_slash = i == 0 || pattern[i - 1] == b'/';
                let next_slash = i + 2 < len && pattern[i + 2] == b'/';
                if prev_slash && next_slash {
                    regex.extend_from_slice(b"(?:.+/)?");
                    i += 3;
                } else {
                    regex.extend_from_slice(b".*");
                    i += 2;
                }
            } else if c == b'*' {
                regex.extend_from_slice(b"[^/]*");
                i += 1;
            } else if c == b'?' {
                regex.extend_from_slice(b"[^/]");
                i += 1;
            } else {
                Self::quote(c, &mut regex);
                i += 1;
            }
        }
        regex.extend_from_slice(b"$~");
        preg_matches(&regex, subject)
    }
}

/// The excluded pattern of a `!` pattern.
fn exclusion(pattern: &[u8]) -> Option<&[u8]> {
    pattern.strip_prefix(b"!")
}

impl Validator for Globstar {
    /// Returns "Value must match a specific inclusion, or a wildcard inclusion not overridden by any exclusion.".
    fn description(&self) -> String {
        "Value must match a specific inclusion, or a wildcard inclusion not overridden by any exclusion.".to_owned()
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
        if patterns().any(|p| exclusion(p).is_none() && !Self::is_wildcard(p) && Self::matches(subject, p)) {
            return Ok(Verdict::VALID);
        }
        // The last matching wildcard inclusion or exclusion wins; literal
        // inclusions were decided above.
        let mut state = false;
        for p in patterns() {
            match exclusion(p) {
                Some(excluded) if Self::matches(subject, excluded) => state = false,
                None if Self::is_wildcard(p) && Self::matches(subject, p) => state = true,
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
        // `/^([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/`
        Ok(Verdict::of(
            value
                .as_str()
                .map(dollar_body)
                .is_some_and(|s| matches!(s.len(), 3 | 6) && s.iter().all(u8::is_ascii_hexdigit)),
        ))
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
        let value = rawurldecode(value);
        // `/^ [1-9]\d{6,14}$/`
        if dollar_body(&value).strip_prefix(b" ").is_some_and(is_e164_digits) {
            let mut out = b"+".to_vec();
            out.extend_from_slice(&value[1..]);
            return out;
        }
        // `/^[1-9]\d{6,14}$/`
        if !value.is_empty() && !value.starts_with(b"+") && is_e164_digits(dollar_body(&value)) {
            let mut out = b"+".to_vec();
            out.extend_from_slice(&value);
            return out;
        }
        value
    }
}

impl Validator for Phone {
    /// Returns "Phone number must start with a '+' and contain between 7 and 15 digits.".
    fn description(&self) -> String {
        "Phone number must start with a '+' and contain between 7 and 15 digits.".to_owned()
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
        // `/^\+[1-9]\d{6,14}$/`
        let e164 = |s: &[u8]| dollar_body(s).strip_prefix(b"+").is_some_and(is_e164_digits);
        Ok(Verdict::of(if self.normalize { e164(&Self::normalize(s)) } else { e164(s) }))
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
