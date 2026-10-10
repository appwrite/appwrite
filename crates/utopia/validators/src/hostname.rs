use php_std::mb;
use php_std::url::parse_url;

use crate::{Error, Input, Type, Url, Validator, Verdict, is_valid_via_validate};

/// `Utopia\Validator\Hostname`: hostname matched against an allow list.
///
/// An empty list allows everything; `*` allows everything; `*.example.com`
/// allows any subdomain of `example.com` (but not the apex).
#[derive(Debug, Clone, Default)]
pub struct Hostname {
    pub allow_list: Vec<String>,
}

impl Hostname {
    pub fn new(allow_list: Vec<String>) -> Self {
        Self { allow_list }
    }

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
    fn description(&self) -> String {
        "Value must be a valid hostname without path, port and protocol.".to_owned()
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(value.as_str().is_some_and(|s| self.matches_bytes(s))))
    }

    fn kind(&self) -> Type {
        Type::String
    }
}

/// `Utopia\Validator\Host`: a URL whose host is allowed by a [`Hostname`]
/// allow list.
#[derive(Debug, Clone, Default)]
pub struct Host {
    pub whitelist: Vec<String>,
}

impl Host {
    pub fn new(whitelist: Vec<String>) -> Self {
        Self { whitelist }
    }
}

impl Validator for Host {
    fn description(&self) -> String {
        format!("URL host must be one of: {}", self.whitelist.join(", "))
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        if !Url::default().validate(value)?.valid {
            return Ok(Verdict::INVALID);
        }
        let url = value.to_bytes()?;
        let host = parse_url(&url).and_then(|u| u.host());
        Ok(Verdict::of(host.is_some_and(|h| allowed(&self.whitelist, &h))))
    }

    fn kind(&self) -> Type {
        Type::String
    }
}
