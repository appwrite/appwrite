//! String validators: substrings, globs, colors and phone numbers.

use php_std::encoding::rawurldecode;
use php_std::mb::mb_strtolower;
use php_std::pcre::preg_quote;
use php_std::string::str_contains;

use crate::text::preg_matches;
use crate::{Error, Input, Type, Validator, Verdict, is_valid_via_validate};

/// `Utopia\Validator\Contains`: a string containing at least one of the
/// patterns (case-insensitively, with `mb_strtolower`, unless `strict`).
#[derive(Debug, Clone)]
pub struct Contains {
    patterns: Vec<String>,
    /// The patterns as matched: lower-cased unless strict.
    needles: Vec<Vec<u8>>,
    strict: bool,
}

impl Contains {
    /// `new Contains($patterns, $strict)`; PHP rejects an empty list.
    pub fn new(patterns: Vec<String>, strict: bool) -> Result<Self, Error> {
        if patterns.is_empty() {
            return Err(Error::InvalidArgument("Patterns array cannot be empty".into()));
        }
        let needles =
            patterns.iter().map(|p| if strict { p.as_bytes().to_vec() } else { mb_strtolower(p.as_bytes()) }).collect();
        Ok(Self { patterns, needles, strict })
    }

    pub fn patterns(&self) -> &[String] {
        &self.patterns
    }
}

impl Validator for Contains {
    fn description(&self) -> String {
        let case = if self.strict { "case-sensitive" } else { "case-insensitive" };
        format!("Value must contain one of ({}) ({case})", self.patterns.join(", "))
    }

    is_valid_via_validate!();

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

    fn kind(&self) -> Type {
        Type::String
    }
}

/// `Utopia\Validator\Globstar`: a string matching gitignore-style glob
/// patterns (`*`, `**`, `?`, `[...]`, `\` escapes, `!` exclusions).
///
/// Inclusions are OR-ed and exclusions AND-ed; a literal inclusion beats a
/// wildcard exclusion; otherwise the last matching pattern wins.
#[derive(Debug, Clone, Default)]
pub struct Globstar {
    pub patterns: Vec<String>,
}

impl Globstar {
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
    fn description(&self) -> String {
        "Value must match a specific inclusion, or a wildcard inclusion not overridden by any exclusion.".to_owned()
    }

    is_valid_via_validate!();

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

    fn kind(&self) -> Type {
        Type::String
    }
}

/// `Utopia\Validator\HexColor`: `fff` or `ffffff`.
#[derive(Debug, Clone, Copy, Default)]
pub struct HexColor;

impl Validator for HexColor {
    fn description(&self) -> String {
        "Value must be a valid Hex color code".to_owned()
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(value.as_str().is_some_and(|s| preg_matches(b"/^([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/", s))))
    }

    fn kind(&self) -> Type {
        Type::String
    }
}

/// `Utopia\Validator\Phone`: an E.164 phone number (`+` and 7 to 15
/// digits), optionally repaired by [`Phone::normalize`] first.
#[derive(Debug, Clone, Copy, Default)]
pub struct Phone {
    pub allow_empty: bool,
    pub normalize: bool,
}

impl Phone {
    /// `Phone::normalize()`: recovers a number from URL transport damage
    /// (`%2B` left encoded, `+` decoded to a space, `+` omitted).
    pub fn normalize(value: &[u8]) -> Vec<u8> {
        let value = rawurldecode(value);
        if preg_matches(br"/^ [1-9]\d{6,14}$/", &value) {
            let mut out = b"+".to_vec();
            out.extend_from_slice(&value[1..]);
            return out;
        }
        if !value.is_empty() && !value.starts_with(b"+") && preg_matches(br"/^[1-9]\d{6,14}$/", &value) {
            let mut out = b"+".to_vec();
            out.extend_from_slice(&value);
            return out;
        }
        value
    }
}

impl Validator for Phone {
    fn description(&self) -> String {
        "Phone number must start with a '+' and contain between 7 and 15 digits.".to_owned()
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let Some(s) = value.as_str() else {
            return Ok(Verdict::INVALID);
        };
        if self.allow_empty && s.is_empty() {
            return Ok(Verdict::VALID);
        }
        let pattern = br"/^\+[1-9]\d{6,14}$/";
        Ok(Verdict::of(if self.normalize {
            preg_matches(pattern, &Self::normalize(s))
        } else {
            preg_matches(pattern, s)
        }))
    }

    fn kind(&self) -> Type {
        Type::String
    }
}

/// `Utopia\Validator\Wildcard`: every value is valid.
#[derive(Debug, Clone, Copy, Default)]
pub struct Wildcard;

impl Validator for Wildcard {
    fn description(&self) -> String {
        "Every input is valid".to_owned()
    }

    fn is_valid(&self, _value: &serde_json::Value) -> bool {
        true
    }

    fn validate(&self, _value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::VALID)
    }

    fn kind(&self) -> Type {
        Type::String
    }
}
