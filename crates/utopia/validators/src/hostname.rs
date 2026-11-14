//! Host names checked against an allow list, alone or as the host of a
//! URL.

use php_std::mb;
use php_std::url::parse_url;

use crate::{Error, Input, Type, Url, Validator, Verdict, is_valid_via_validate};

/// A host name allowed by a list of names and patterns.
///
/// The value must be a non-empty string of at most 253 characters, without a
/// path (`/`), port or scheme (`:`); `"0"` is refused. An empty list allows
/// every such name. A pattern starting with `*` allows any name ending with
/// the rest: `*` allows everything, and `*.example.com` any subdomain of
/// `example.com` (but not the apex).
///
/// PHP: `Utopia\Validator\Hostname`.
#[derive(Debug, Clone, Default)]
pub struct Hostname {
    /// The allowed names and patterns.
    pub allow_list: Vec<String>,
}

impl Hostname {
    /// A validator allowing the names and patterns of `allow_list`.
    pub fn new(allow_list: Vec<String>) -> Self {
        Self { allow_list }
    }

    /// Whether `value` is a host name the list allows.
    pub fn matches(&self, value: &str) -> bool {
        self.matches_bytes(value.as_bytes())
    }

    fn matches_bytes(&self, value: &[u8]) -> bool {
        allowed(&self.allow_list, value)
    }
}

/// `Hostname::isValid()` of a string against `allow_list`.
fn allowed(allow_list: &[String], value: &[u8]) -> bool {
    if value.is_empty() || value == b"0" {
        return false;
    }
    // At most 253 characters, no path (`/`), no port or scheme (`:`).
    if mb::mb_strlen(value) > 253 || value.contains(&b'/') || value.contains(&b':') {
        return false;
    }
    if allow_list.is_empty() {
        return true;
    }
    allow_list.iter().any(|allowed| {
        let allowed = allowed.as_bytes();
        value == allowed || allowed == b"*" || allowed.strip_prefix(b"*").is_some_and(|suffix| value.ends_with(suffix))
    })
}

impl Validator for Hostname {
    /// Returns "Value must be a valid hostname without path, port and protocol.".
    fn description(&self) -> String {
        "Value must be a valid hostname without path, port and protocol.".to_owned()
    }

    is_valid_via_validate!();

    /// Valid for a string the list allows ([`Hostname::matches`]).
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(value.as_str().is_some_and(|s| self.matches_bytes(s))))
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}

/// A URL whose host is allowed by a list of names and patterns, matched as
/// [`Hostname`] matches them.
///
/// PHP: `Utopia\Validator\Host`.
#[derive(Debug, Clone, Default)]
pub struct Host {
    /// The allowed host names and patterns.
    pub whitelist: Vec<String>,
}

impl Host {
    /// A validator allowing URLs whose host matches `whitelist`.
    pub fn new(whitelist: Vec<String>) -> Self {
        Self { whitelist }
    }
}

impl Validator for Host {
    /// Lists the allowed hosts: `URL host must be one of: a, b`.
    fn description(&self) -> String {
        format!("URL host must be one of: {}", self.whitelist.join(", "))
    }

    is_valid_via_validate!();

    /// Valid for a URL (as [`Url`] checks it by default) whose host the list allows.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        if !Url::default().validate(value)?.valid {
            return Ok(Verdict::INVALID);
        }
        let url = value.to_bytes()?;
        let host = parse_url(&url).and_then(|u| u.host());
        Ok(Verdict::of(host.is_some_and(|h| allowed(&self.whitelist, &h))))
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}

/// A single DNS label as RFC 1123 allows it in a host name: 1 to
/// [`Label::MAX_LENGTH`] lowercase ASCII letters, digits and hyphens, not
/// starting or ending with a hyphen. Dots are refused, so a value can sit
/// between fixed parts of a host name without adding or changing a label.
///
/// PHP: `Utopia\Validator\Label`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Label;

impl Label {
    /// The longest label allowed.
    ///
    /// PHP: `Label::MAX_LENGTH`.
    pub const MAX_LENGTH: usize = 63;
}

impl Validator for Label {
    /// `Value must be 1 to 63 lowercase letters, digits and hyphens, and must
    /// not start or end with a hyphen.`
    fn description(&self) -> String {
        format!(
            "Value must be 1 to {} lowercase letters, digits and hyphens, and must not start or end with a hyphen.",
            Self::MAX_LENGTH
        )
    }

    is_valid_via_validate!();

    /// Valid for a string that is a DNS label.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(value.as_str().is_some_and(|s| {
            !s.is_empty()
                && s.len() <= Self::MAX_LENGTH
                && !s.starts_with(b"-")
                && !s.ends_with(b"-")
                && s.iter().all(|&b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'-')
        })))
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}
