//! Domain names, IP addresses and URLs.

use php_std::filter::{
    FILTER_FLAG_HOSTNAME, FILTER_FLAG_IPV4, FILTER_FLAG_IPV6, FILTER_VALIDATE_DOMAIN, FILTER_VALIDATE_IP,
    FILTER_VALIDATE_URL,
};
use php_std::string::{ctype_digit, explode, strtolower};
use php_std::url::parse_url;
use php_std::url::rfc3986::Uri;

use crate::input::{filter, filter_str, loose_str_eq};
use crate::{Error, Input, Type, Validator, Verdict, View, is_valid_via_validate};

/// A [`Domain`] restriction: domains ending with `hostname` must have exactly
/// `levels` labels (when set) and must not start with any of
/// `prefix_deny_list`.
///
/// PHP: `Domain::createRestriction()`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Restriction {
    /// The suffix of the domains the restriction applies to.
    pub hostname: String,
    /// The exact number of dot-separated labels a matching domain must have, when set.
    pub levels: Option<i64>,
    /// Prefixes a matching domain must not start with.
    pub prefix_deny_list: Vec<String>,
}

impl Restriction {
    /// A restriction on the domains ending with `hostname`.
    ///
    /// PHP: `Domain::createRestriction($hostname, $levels, $prefixDenyList)`.
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

/// A domain name, not ending with `.` or `-`, and allowed by every
/// [`Restriction`].
///
/// PHP: `Utopia\Validator\Domain`, checking with `FILTER_VALIDATE_DOMAIN` and,
/// when `hostnames` is set, `FILTER_FLAG_HOSTNAME`.
#[derive(Debug, Clone)]
pub struct Domain {
    /// Restrictions every domain must pass.
    pub restrictions: Vec<Restriction>,
    /// Whether the domain must also be a valid host name: letters, digits and hyphens in each label.
    pub hostnames: bool,
    /// Whether the empty string is valid.
    pub allow_empty: bool,
}

impl Default for Domain {
    /// No restrictions, host names required, the empty string refused.
    fn default() -> Self {
        Self { restrictions: Vec::new(), hostnames: true, allow_empty: false }
    }
}

impl Validator for Domain {
    /// Returns "Value must be a valid domain".
    fn description(&self) -> String {
        "Value must be a valid domain".to_owned()
    }

    is_valid_via_validate!();

    /// Valid for a non-empty string that is a domain name, does not end with `.`
    /// or `-`, and passes every restriction. A restriction whose label count
    /// cannot be computed fails with [`Error::Type`].
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

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}

/// The IP versions an [`Ip`] validator accepts.
///
/// PHP: the `IP::ALL`, `IP::V4` and `IP::V6` constants.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum IpVersion {
    /// IPv4 and IPv6.
    #[default]
    All,
    /// IPv4 only.
    V4,
    /// IPv6 only.
    V6,
}

impl IpVersion {
    /// The version's name: `all`, `ipv4` or `ipv6`.
    ///
    /// PHP: the value of the matching `IP` constant.
    pub const fn as_str(self) -> &'static str {
        match self {
            IpVersion::All => "all",
            IpVersion::V4 => "ipv4",
            IpVersion::V6 => "ipv6",
        }
    }

    /// The version a name from [`IpVersion::as_str`] names; [`Error::Exception`]
    /// for any other name.
    ///
    /// PHP: the type `new IP($type)` takes; PHP throws for any other type.
    pub fn from_php(value: &str) -> Result<Self, Error> {
        match value {
            "all" => Ok(IpVersion::All),
            "ipv4" => Ok(IpVersion::V4),
            "ipv6" => Ok(IpVersion::V6),
            _ => Err(Error::Exception("Unsupported IP type".into())),
        }
    }
}

/// An IP address of the accepted versions.
///
/// PHP: `Utopia\Validator\IP`, checking with `FILTER_VALIDATE_IP`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Ip {
    /// The versions accepted.
    pub version: IpVersion,
}

impl Ip {
    /// A validator accepting addresses of `version`.
    pub const fn new(version: IpVersion) -> Self {
        Self { version }
    }
}

impl Validator for Ip {
    /// Returns "Value must be a valid IP address".
    fn description(&self) -> String {
        "Value must be a valid IP address".to_owned()
    }

    is_valid_via_validate!();

    /// Valid for an IP address of the accepted versions.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let flags = match self.version {
            IpVersion::All => 0,
            IpVersion::V4 => FILTER_FLAG_IPV4,
            IpVersion::V6 => FILTER_FLAG_IPV6,
        };
        Ok(Verdict::of(filter(value, FILTER_VALIDATE_IP, flags).is_some()))
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}

/// A URL, optionally limited to some schemes, to URLs without a fragment, or
/// to https (and http on a loopback host).
///
/// With `allow_private_use_schemes`, URIs of private-use schemes without an
/// authority (`com.example.app:/oauth`, RFC 8252 §7.1) are valid too.
///
/// PHP: `Utopia\Validator\URL`, checking with `FILTER_VALIDATE_URL`.
#[derive(Debug, Clone)]
pub struct Url {
    /// The schemes allowed; empty for any scheme.
    pub allowed_schemes: Vec<String>,
    /// Whether the empty string is valid.
    pub allow_empty: bool,
    /// Whether a fragment (`#section`) is allowed.
    pub allow_fragments: bool,
    /// Whether authority-less URIs of private-use schemes, such as `com.example.app:/oauth`, are valid.
    pub allow_private_use_schemes: bool,
    /// Whether the URL must use https, or http on `localhost`, `127.0.0.1` or `[::1]`.
    pub https_or_loopback: bool,
}

impl Default for Url {
    /// Any scheme, fragments allowed, the empty string refused, and no transport limit.
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
    /// `Value must be a valid URL`, followed by the scheme, fragment and transport limits that apply.
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

    /// Valid for a URL that passes every limit set.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        if self.allow_empty && value.as_str() == Some(b"") {
            return Ok(Verdict::VALID);
        }
        let private = self.allow_private_use_schemes && Self::is_private_use_scheme_uri(value);
        if !private && filter(value, FILTER_VALIDATE_URL, 0).is_none() {
            return Ok(Verdict::INVALID);
        }
        // Only the scheme, fragment and transport limits read the URL's parts
        // (PHP calls `parse_url((string) $value)` only for them).
        let transport = self.https_or_loopback && !private;
        if self.allowed_schemes.is_empty() && self.allow_fragments && !transport {
            return Ok(Verdict::VALID);
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
        if transport {
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

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}

/// A published port as Docker accepts it: a port (`8080`), or a port bound to
/// one host interface (`127.0.0.1:8080`, `[::1]:8080`).
///
/// PHP: `Utopia\Validator\Port`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Port;

impl Port {
    /// An IPv4 address, or an IPv6 address in brackets.
    fn is_valid_host(host: &[u8]) -> bool {
        if let Some(inner) = host.strip_prefix(b"[").and_then(|h| h.strip_suffix(b"]")) {
            return filter_str(inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        }
        // `[` alone starts and ends with a bracket too; `substr('[', 1, -1)` is empty.
        if host == b"[" {
            return false;
        }
        filter_str(host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
    }

    /// Decimal digits without a leading zero, between 1 and 65535.
    fn is_valid_port(port: &[u8]) -> bool {
        if !ctype_digit(port) || port.starts_with(b"0") {
            return false;
        }
        // Six digits or more without a leading zero exceed 65535 (PHP's `(int)`
        // saturates the longest, which are out of range too).
        port.len() <= 5 && port.iter().fold(0u32, |n, d| n * 10 + u32::from(d - b'0')) <= 65535
    }
}

impl Validator for Port {
    /// Returns "Value must be a port between 1 and 65535, optionally prefixed
    /// by a host IP address (e.g. 127.0.0.1:80)".
    fn description(&self) -> String {
        "Value must be a port between 1 and 65535, optionally prefixed by a host IP address (e.g. 127.0.0.1:80)"
            .to_owned()
    }

    is_valid_via_validate!();

    /// Valid for an integer or a string that is a port, or an IPv4 address or
    /// a bracketed IPv6 address, `:` and a port (split at the last `:`).
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let int;
        let value: &[u8] = match value.view() {
            View::Int(i) => {
                int = i.to_string();
                int.as_bytes()
            }
            View::Str(s) => s,
            _ => return Ok(Verdict::INVALID),
        };
        let valid = match value.iter().rposition(|&b| b == b':') {
            None => Self::is_valid_port(value),
            Some(at) => Self::is_valid_host(&value[..at]) && Self::is_valid_port(&value[at + 1..]),
        };
        Ok(Verdict::of(valid))
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}
