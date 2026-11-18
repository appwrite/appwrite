//! Domain names, IP addresses, URLs and ports, run by the [`network`](crate::network) and
//! [`scalar`](crate::scalar) checks.

use std::borrow::Cow;

use php_std::string::strtolower;
use php_std::url::Component as UrlComponent;

use crate::input::loose_str_eq;
use crate::network::{self, IpType};
use crate::{Error, Input, Type, Validator, Verdict, is_valid_via_validate, scalar};

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
            if network::explode_count(domain, limit) != levels {
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
        network::DOMAIN_DESCRIPTION.to_owned()
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
        if !network::domain(domain, self.hostnames) {
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
        network::IP_DESCRIPTION.to_owned()
    }

    is_valid_via_validate!();

    /// Valid for an IP address of the accepted versions.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let kind = match self.version {
            IpVersion::All => IpType::All,
            IpVersion::V4 => IpType::V4,
            IpVersion::V6 => IpType::V6,
        };
        Ok(Verdict::of(match value.as_str() {
            Some(s) => network::ip_str(s, kind),
            None => network::ip(&value.filter_value(), kind),
        }))
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
enum Component {
    False,
    Null,
    Value(Vec<u8>),
}

impl Component {
    /// `parse_url($url, $component)`.
    fn of(url: &[u8], component: UrlComponent) -> Self {
        match network::url_component(url, component) {
            None => Component::False,
            Some(None) => Component::Null,
            Some(Some(value)) => Component::Value(value),
        }
    }

    /// `(string) $component`.
    fn bytes(&self) -> &[u8] {
        match self {
            Component::Value(v) => v,
            _ => b"",
        }
    }
}

impl Url {
    /// `in_array($scheme, $this->allowedSchemes)`.
    fn scheme_allowed(&self, scheme: &Component) -> bool {
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
        let schemes = (!self.allowed_schemes.is_empty()).then(|| self.allowed_schemes.join(", "));
        let description = network::url_description(
            schemes.as_deref().map(str::as_bytes),
            self.allow_fragments,
            self.https_or_loopback,
        );
        String::from_utf8_lossy(&description).into_owned()
    }

    is_valid_via_validate!();

    /// Valid for a URL that passes every limit set.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        if self.allow_empty && value.as_str() == Some(b"") {
            return Ok(Verdict::VALID);
        }
        let private = self.allow_private_use_schemes && value.as_str().is_some_and(network::private_use_scheme);
        // FILTER_VALIDATE_URL is ASCII-only: a string's non-ASCII bytes
        // (IRIs, RFC 3987) are percent-encoded before it is validated.
        let url = match value.as_str() {
            Some(s) => network::url_str(&network::url_ascii(s)),
            None => network::url(&value.filter_value()),
        };
        if !private && !url {
            return Ok(Verdict::INVALID);
        }
        // Only the scheme, fragment and transport limits read the URL's parts
        // (PHP calls `parse_url((string) $value)` only for them).
        let transport = self.https_or_loopback && !private;
        if self.allowed_schemes.is_empty() && self.allow_fragments && !transport {
            return Ok(Verdict::VALID);
        }
        let url: Cow<'_, [u8]> = value.to_bytes()?;
        let component = |component| Component::of(&url, component);
        if !self.allowed_schemes.is_empty() && !self.scheme_allowed(&component(UrlComponent::Scheme)) {
            return Ok(Verdict::INVALID);
        }
        if !self.allow_fragments && !matches!(component(UrlComponent::Fragment), Component::Null) {
            return Ok(Verdict::INVALID);
        }
        if transport {
            let scheme = component(UrlComponent::Scheme);
            let scheme = strtolower(scheme.bytes());
            if scheme.as_ref() == b"http" {
                let host = component(UrlComponent::Host);
                let host = strtolower(host.bytes());
                if !network::LOOPBACK_HOSTS.contains(&host.as_ref()) {
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
        Ok(Verdict::of(scalar::port(value.scalar())))
    }

    /// Expects a string ([`Type::String`]).
    fn kind(&self) -> Type {
        Type::String
    }
}
