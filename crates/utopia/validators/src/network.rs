//! Domain names, IP addresses and URLs.

use php_std::filter::{
    FILTER_FLAG_HOSTNAME, FILTER_FLAG_IPV4, FILTER_FLAG_IPV6, FILTER_VALIDATE_DOMAIN, FILTER_VALIDATE_IP,
    FILTER_VALIDATE_URL,
};
use php_std::string::{explode, strtolower};
use php_std::url::parse_url;
use php_std::url::rfc3986::Uri;

use crate::input::{filter, filter_str, loose_str_eq};
use crate::{Error, Input, Type, Validator, Verdict, is_valid_via_validate};

/// A [`Domain`] restriction (`Domain::createRestriction()`): domains ending
/// with `hostname` must have exactly `levels` labels (when set) and must not
/// start with any of `prefix_deny_list`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Restriction {
    pub hostname: String,
    pub levels: Option<i64>,
    pub prefix_deny_list: Vec<String>,
}

impl Restriction {
    /// `Domain::createRestriction($hostname, $levels, $prefixDenyList)`.
    pub fn new(hostname: impl Into<String>, levels: Option<i64>, prefix_deny_list: Vec<String>) -> Self {
        Self { hostname: hostname.into(), levels, prefix_deny_list }
    }

    /// Whether `domain` passes this restriction.
    fn allows(&self, domain: &[u8]) -> Result<bool, Error> {
        if !domain.ends_with(self.hostname.as_bytes()) {
            return Ok(true);
        }
        if let Some(levels) = self.levels {
            // `$levels + 1` overflows to a float, which `explode()` rejects.
            let limit = levels.checked_add(1).ok_or_else(|| {
                Error::Type("explode(): Argument #3 ($limit) must be of type int, float given".into())
            })?;
            if explode(b".", domain, limit)?.len() as i64 != levels {
                return Ok(false);
            }
        }
        Ok(!self.prefix_deny_list.iter().any(|p| domain.starts_with(p.as_bytes())))
    }
}

/// `Utopia\Validator\Domain`: a domain name (`FILTER_VALIDATE_DOMAIN`, with
/// `FILTER_FLAG_HOSTNAME` when `hostnames`), not ending with `.` or `-`, and
/// allowed by every [`Restriction`].
#[derive(Debug, Clone)]
pub struct Domain {
    pub restrictions: Vec<Restriction>,
    pub hostnames: bool,
    pub allow_empty: bool,
}

impl Default for Domain {
    fn default() -> Self {
        Self { restrictions: Vec::new(), hostnames: true, allow_empty: false }
    }
}

impl Validator for Domain {
    fn description(&self) -> String {
        "Value must be a valid domain".to_owned()
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        if self.allow_empty && value.as_str() == Some(b"") {
            return Ok(Verdict::VALID);
        }
        // `empty($value)`, then `is_string($value)`.
        let Some(domain) = value.as_str().filter(|_| value.truthy()) else {
            return Ok(Verdict::INVALID);
        };
        let flags = if self.hostnames { FILTER_FLAG_HOSTNAME } else { 0 };
        if !filter_str(domain, FILTER_VALIDATE_DOMAIN, flags) || domain.ends_with(b".") || domain.ends_with(b"-") {
            return Ok(Verdict::INVALID);
        }
        for restriction in &self.restrictions {
            if !restriction.allows(domain)? {
                return Ok(Verdict::INVALID);
            }
        }
        Ok(Verdict::VALID)
    }

    fn kind(&self) -> Type {
        Type::String
    }
}

/// The IP versions [`Ip`] accepts (`IP::ALL`, `IP::V4`, `IP::V6`).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum IpVersion {
    #[default]
    All,
    V4,
    V6,
}

impl IpVersion {
    /// The PHP constant's value.
    pub const fn as_str(self) -> &'static str {
        match self {
            IpVersion::All => "all",
            IpVersion::V4 => "ipv4",
            IpVersion::V6 => "ipv6",
        }
    }

    /// The version `new IP($type)` takes: PHP throws for any other type.
    pub fn from_php(value: &str) -> Result<Self, Error> {
        match value {
            "all" => Ok(IpVersion::All),
            "ipv4" => Ok(IpVersion::V4),
            "ipv6" => Ok(IpVersion::V6),
            _ => Err(Error::Exception("Unsupported IP type".into())),
        }
    }
}

/// `Utopia\Validator\IP`: an IP address (`FILTER_VALIDATE_IP`).
#[derive(Debug, Clone, Copy, Default)]
pub struct Ip {
    pub version: IpVersion,
}

impl Ip {
    pub const fn new(version: IpVersion) -> Self {
        Self { version }
    }
}

impl Validator for Ip {
    fn description(&self) -> String {
        "Value must be a valid IP address".to_owned()
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let flags = match self.version {
            IpVersion::All => 0,
            IpVersion::V4 => FILTER_FLAG_IPV4,
            IpVersion::V6 => FILTER_FLAG_IPV6,
        };
        Ok(Verdict::of(filter(value, FILTER_VALIDATE_IP, flags).is_some()))
    }

    fn kind(&self) -> Type {
        Type::String
    }
}

/// `Utopia\Validator\URL`: a URL (`FILTER_VALIDATE_URL`), optionally limited
/// to `allowed_schemes`, without a fragment, or to https (and http on a
/// loopback host); `allow_private_use_schemes` also accepts authority-less
/// private-use scheme URIs (`com.example.app:/oauth`, RFC 8252 §7.1).
#[derive(Debug, Clone)]
pub struct Url {
    pub allowed_schemes: Vec<String>,
    pub allow_empty: bool,
    pub allow_fragments: bool,
    pub allow_private_use_schemes: bool,
    pub https_or_loopback: bool,
}

impl Default for Url {
    fn default() -> Self {
        Self {
            allowed_schemes: Vec::new(),
            allow_empty: false,
            allow_fragments: true,
            allow_private_use_schemes: false,
            https_or_loopback: false,
        }
    }
}

/// A component of `parse_url($url, PHP_URL_*)`: `false` when the URL does
/// not parse, `null` when the component is missing.
enum Component<'a> {
    False,
    Null,
    Value(std::borrow::Cow<'a, [u8]>),
}

/// An owned component.
fn owned(c: std::borrow::Cow<'_, [u8]>) -> Component<'static> {
    Component::Value(std::borrow::Cow::Owned(c.into_owned()))
}

impl Component<'_> {
    /// `(string) $component`.
    fn bytes(&self) -> &[u8] {
        match self {
            Component::Value(v) => v,
            _ => b"",
        }
    }
}

impl Url {
    /// An authority-less private-use URI scheme (`Uri\Rfc3986\Uri::parse()`:
    /// a scheme with a dot and no host).
    fn is_private_use_scheme_uri(value: Input<'_>) -> bool {
        let Some(uri) = value.as_str().and_then(Uri::parse) else {
            return false;
        };
        uri.scheme().is_some_and(|s| s.contains(&b'.')) && uri.host().is_none()
    }

    /// `in_array($scheme, $this->allowedSchemes)`.
    fn scheme_allowed(&self, scheme: &Component<'_>) -> bool {
        self.allowed_schemes.iter().any(|allowed| match scheme {
            Component::False => allowed.is_empty() || allowed == "0",
            Component::Null => allowed.is_empty(),
            Component::Value(s) => loose_str_eq(s, allowed.as_bytes()),
        })
    }
}

impl Validator for Url {
    fn description(&self) -> String {
        let transport = if self.https_or_loopback { " restricted to https or http on a loopback host" } else { "" };
        if !self.allowed_schemes.is_empty() {
            let mut description =
                format!("Value must be a valid URL with following schemes ({})", self.allowed_schemes.join(", "));
            if !self.allow_fragments {
                description.push_str(" and without a fragment component");
            }
            return description + transport;
        }
        if !self.allow_fragments {
            return format!("Value must be a valid URL without a fragment component{transport}");
        }
        format!("Value must be a valid URL{transport}")
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        if self.allow_empty && value.as_str() == Some(b"") {
            return Ok(Verdict::VALID);
        }
        let private = self.allow_private_use_schemes && Self::is_private_use_scheme_uri(value);
        if !private && filter(value, FILTER_VALIDATE_URL, 0).is_none() {
            return Ok(Verdict::INVALID);
        }
        let url = value.to_bytes()?;
        let parsed = parse_url(&url);
        let component = |get: fn(&php_std::url::Url<'_>) -> Option<Component<'static>>| match &parsed {
            None => Component::False,
            Some(u) => get(u).unwrap_or(Component::Null),
        };
        if !self.allowed_schemes.is_empty() && !self.scheme_allowed(&component(|u| u.scheme().map(owned))) {
            return Ok(Verdict::INVALID);
        }
        if !self.allow_fragments && !matches!(component(|u| u.fragment().map(owned)), Component::Null) {
            return Ok(Verdict::INVALID);
        }
        if self.https_or_loopback && !private {
            let scheme = component(|u| u.scheme().map(owned));
            let scheme = strtolower(scheme.bytes());
            if scheme.as_ref() == b"http" {
                let host = component(|u| u.host().map(owned));
                let host = strtolower(host.bytes());
                if !matches!(host.as_ref(), b"localhost" | b"127.0.0.1" | b"[::1]") {
                    return Ok(Verdict::INVALID);
                }
            } else if scheme.as_ref() != b"https" {
                return Ok(Verdict::INVALID);
            }
        }
        Ok(Verdict::VALID)
    }

    fn kind(&self) -> Type {
        Type::String
    }
}
