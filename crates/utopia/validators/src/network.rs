//! The checks of the network validators: `Hostname`, `Domain`, `Host`, `IP`, `URL` and
//! `Phone`.
//!
//! PHP runs `filter_var()`, `parse_url()` and `Uri\Rfc3986\Uri::parse()` for them; these
//! functions run [`php_std`]'s ports of the same, so a value is accepted exactly when PHP
//! accepts it. This crate's validators ([`crate::Hostname`], [`crate::Domain`],
//! [`crate::Host`], [`crate::Ip`], [`crate::Url`], [`crate::Phone`]) run them, and so do the
//! classes of the PHP extension (`crates/extension`), which keep the constructors' state
//! themselves and pass it in.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Hostname::isValid()`, `getDescription()` | [`hostname`], [`hostname_allowed`], [`HOSTNAME_DESCRIPTION`] |
//! | `Domain::isValid()`, `getDescription()` | [`domain`], [`explode_count`], [`DOMAIN_DESCRIPTION`] |
//! | `IP::isValid()`, `getDescription()` | [`ip`], [`ip_str`], [`IP_DESCRIPTION`] |
//! | `URL::isValid()`, `getDescription()`, `isPrivateUseSchemeURI()` | [`url_ascii`], [`url`], [`url_str`], [`url_component`], [`private_use_scheme`], [`url_description`], [`LOOPBACK_HOSTS`] |
//! | `Host::getDescription()` | [`host_description`] |
//! | `Phone::isValid()`, `normalize()`, `getDescription()` | [`phone`], [`phone_normalize`], [`PHONE_DESCRIPTION`] |

use std::borrow::Cow;

use php_std::filter::{
    self, FILTER_FLAG_IPV4, FILTER_FLAG_IPV6, FILTER_VALIDATE_IP, FILTER_VALIDATE_URL, Options, Value,
};
use php_std::url::rfc3986::Uri;
use php_std::url::{Component, ComponentValue, parse_url};

use crate::chars;

/// Whether `filter_var($value, $filter, $flags)` returns something other than `false`.
fn passes(value: &Value, filter: i64, flags: i64) -> bool {
    filter::filter_var(value, filter, &Options::Flags(flags))
        .is_ok_and(|filtered| !matches!(filtered.value, Value::Bool(false)))
}

/// Whether `filter_var($value, $filter, $flags)` returns a truthy value (`IP` tests it with
/// `if (filter_var(...))`).
fn truthy(value: &Value, filter: i64, flags: i64) -> bool {
    filter::filter_var(value, filter, &Options::Flags(flags)).is_ok_and(|filtered| match filtered.value {
        Value::Bool(b) => b,
        Value::Str(s) => !(s.is_empty() || s == b"0"),
        Value::Null => false,
        Value::Int(i) => i != 0,
        Value::Float(f) => f != 0.0,
        Value::Array(a) => !a.is_empty(),
        Value::Object => true,
    })
}

// Hostname --------------------------------------------------------------------------------

/// `Hostname::getDescription()`.
pub const HOSTNAME_DESCRIPTION: &str = "Value must be a valid hostname without path, port and protocol.";

/// The checks of `Hostname::isValid()` before its allow list: not `''` or `'0'`, at most 253
/// characters (`mb_strlen()`), no `/` and no `:`.
pub fn hostname(value: &[u8]) -> bool {
    !(value.is_empty() || value == b"0") && chars(value) <= 253 && !value.contains(&b'/') && !value.contains(&b':')
}

/// Whether `value` matches an allow-list entry `allowed` that is a string: equal to it, `*`,
/// or a `*.suffix` wildcard that `value` ends with (the root itself is not matched).
pub fn hostname_allowed(value: &[u8], allowed: &[u8]) -> bool {
    value == allowed || allowed == b"*" || allowed.strip_prefix(b"*").is_some_and(|suffix| value.ends_with(suffix))
}

// Domain ----------------------------------------------------------------------------------

/// `Domain::getDescription()`.
pub const DOMAIN_DESCRIPTION: &str = "Value must be a valid domain";

/// `Domain::isValid()`'s format checks on a non-empty string: `filter_var(FILTER_VALIDATE_DOMAIN)`
/// (with `FILTER_FLAG_HOSTNAME` when `hostnames`) and no trailing `.` or `-`.
pub fn domain(value: &[u8], hostnames: bool) -> bool {
    // `filter_var()` of a string runs the filter on it as it is.
    filter::validate_domain(value, hostnames) && !value.ends_with(b".") && !value.ends_with(b"-")
}

/// `count(explode('.', $value, $limit))`.
pub fn explode_count(value: &[u8], limit: i64) -> i64 {
    php_std::string::explode(b".", value, limit).map_or(0, |parts| i64::try_from(parts.len()).unwrap_or(i64::MAX))
}

// IP --------------------------------------------------------------------------------------

/// `IP::getDescription()`.
pub const IP_DESCRIPTION: &str = "Value must be a valid IP address";

/// The IP versions `IP` accepts: `IP::ALL`, `IP::V4` or `IP::V6`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum IpType {
    /// IPv4 and IPv6 (`IP::ALL`).
    All,
    /// IPv4 only (`IP::V4`).
    V4,
    /// IPv6 only (`IP::V6`).
    V6,
}

impl IpType {
    /// The type named `name` (`'all'`, `'ipv4'`, `'ipv6'`), as `IP`'s constructor and
    /// `isValid()` compare it.
    pub fn from_name(name: &[u8]) -> Option<Self> {
        match name {
            b"all" => Some(IpType::All),
            b"ipv4" => Some(IpType::V4),
            b"ipv6" => Some(IpType::V6),
            _ => None,
        }
    }
}

/// `IP::isValid()`: `filter_var($value, FILTER_VALIDATE_IP[, flag])` is truthy.
pub fn ip(value: &Value, kind: IpType) -> bool {
    truthy(value, FILTER_VALIDATE_IP, kind.flags())
}

/// [`ip`] of a string: the filter on the string as it is (an address it accepts is never
/// `''` or `'0'`, so accepted is truthy).
pub fn ip_str(value: &[u8], kind: IpType) -> bool {
    filter::validate_ip(value, kind.flags())
}

impl IpType {
    /// The `filter_var()` flags `IP` passes for the type.
    fn flags(self) -> i64 {
        match self {
            IpType::All => 0,
            IpType::V4 => FILTER_FLAG_IPV4,
            IpType::V6 => FILTER_FLAG_IPV6,
        }
    }
}

// URL -------------------------------------------------------------------------------------

/// `URL::getDescription()`, given `implode(', ', $allowedSchemes)` when there are schemes.
pub fn url_description(schemes: Option<&[u8]>, allow_fragments: bool, https_or_loopback: bool) -> Vec<u8> {
    let transport: &[u8] = if https_or_loopback { b" restricted to https or http on a loopback host" } else { b"" };
    let mut description = Vec::new();
    if let Some(schemes) = schemes {
        description.extend_from_slice(b"Value must be a valid URL with following schemes (");
        description.extend_from_slice(schemes);
        description.push(b')');
        if !allow_fragments {
            description.extend_from_slice(b" and without a fragment component");
        }
    } else if !allow_fragments {
        description.extend_from_slice(b"Value must be a valid URL without a fragment component");
    } else {
        description.extend_from_slice(b"Value must be a valid URL");
    }
    description.extend_from_slice(transport);
    description
}

/// `URL::isValid()`'s IRI step: each byte above 127 as `rawurlencode()` encodes it.
pub fn url_ascii(value: &[u8]) -> Cow<'_, [u8]> {
    if value.is_ascii() {
        return Cow::Borrowed(value);
    }
    let mut ascii = Vec::with_capacity(value.len() * 3);
    for &byte in value {
        if byte > 127 {
            const HEX: &[u8; 16] = b"0123456789ABCDEF";
            ascii.extend_from_slice(&[b'%', HEX[usize::from(byte >> 4)], HEX[usize::from(byte & 15)]]);
        } else {
            ascii.push(byte);
        }
    }
    Cow::Owned(ascii)
}

/// Whether `filter_var($value, FILTER_VALIDATE_URL)` is not `false`.
pub fn url(value: &Value) -> bool {
    passes(value, FILTER_VALIDATE_URL, 0)
}

/// [`url`] of a string: the filter on the string as it is.
pub fn url_str(value: &[u8]) -> bool {
    filter::validate_url(value, 0, None).unwrap_or(false)
}

/// `URL::isPrivateUseSchemeURI()` on a string: `Uri\Rfc3986\Uri::parse()` succeeds, its
/// (normalized) scheme contains a `.` and it has no host.
pub fn private_use_scheme(value: &[u8]) -> bool {
    Uri::parse(value)
        .is_some_and(|uri| uri.scheme().is_some_and(|scheme| scheme.contains(&b'.')) && uri.host().is_none())
}

/// `parse_url($value, $component)` for a string component: `None` where PHP returns `false`
/// (a malformed URL), `Some(None)` for `null` (no such component).
pub fn url_component(value: &[u8], component: Component) -> Option<Option<Vec<u8>>> {
    let url = parse_url(value)?;
    Some(url.component(component).map(|found| match found {
        ComponentValue::Str(s) => s.into_owned(),
        ComponentValue::Port(port) => port.to_string().into_bytes(),
    }))
}

/// The hosts `URL`'s `httpsOrLoopback` lets plain `http` reach.
pub const LOOPBACK_HOSTS: [&[u8]; 3] = [b"localhost", b"127.0.0.1", b"[::1]"];

// Host ------------------------------------------------------------------------------------

/// `Host::getDescription()`, given `implode(', ', $whitelist)`.
pub fn host_description(whitelist: &[u8]) -> Vec<u8> {
    let mut description = b"URL host must be one of: ".to_vec();
    description.extend_from_slice(whitelist);
    description
}

// Phone -----------------------------------------------------------------------------------

/// `Phone::getDescription()`.
pub const PHONE_DESCRIPTION: &str = "Phone number must start with a '+' and contain between 7 and 15 digits.";

/// `[1-9]\d{6,14}$` against all of `value`: PCRE's `$` (no `D` modifier) also matches before
/// a final newline.
fn e164_digits(value: &[u8]) -> bool {
    let digits = value.strip_suffix(b"\n").unwrap_or(value);
    matches!(digits.first(), Some(b'1'..=b'9'))
        && (7..=15).contains(&digits.len())
        && digits.iter().all(u8::is_ascii_digit)
}

/// `Phone::isValid()`'s pattern, `/^\+[1-9]\d{6,14}$/`.
pub fn phone(value: &[u8]) -> bool {
    value.strip_prefix(b"+").is_some_and(e164_digits)
}

/// `Phone::normalize()`: `rawurldecode()`, then a leading space (a `+` a query string decoded
/// as a space) or no `+` at all before 7 to 15 digits becomes a `+`.
pub fn phone_normalize(value: &[u8]) -> Vec<u8> {
    let value = php_std::encoding::rawurldecode(value);
    if value.strip_prefix(b" ").is_some_and(e164_digits) {
        let mut normalized = b"+".to_vec();
        normalized.extend_from_slice(&value[1..]);
        return normalized;
    }
    if !value.is_empty() && !value.starts_with(b"+") && e164_digits(&value) {
        let mut normalized = b"+".to_vec();
        normalized.extend_from_slice(&value);
        return normalized;
    }
    value
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn phone_pattern_matches_pcre() {
        assert!(phone(b"+1234567"));
        assert!(phone(b"+1234567\n"));
        assert!(!phone(b"+1234567\n\n"));
        assert!(!phone(b"+0234567"));
        assert!(!phone(b"+123456"));
        assert!(phone(b"+123456789012345"));
        assert!(!phone(b"+1234567890123456"));
        assert_eq!(phone_normalize(b"%2B1234567"), b"+1234567");
        assert_eq!(phone_normalize(b" 1234567"), b"+1234567");
        assert_eq!(phone_normalize(b" 1234567\n"), b"+1234567\n");
        assert_eq!(phone_normalize(b"1234567"), b"+1234567");
        assert_eq!(phone_normalize(b"0234567"), b"0234567");
    }

    #[test]
    fn hostname_shape_and_wildcards() {
        assert!(hostname(b"a.b"));
        assert!(!hostname(b"0"));
        assert!(!hostname(b"a/b"));
        assert!(!hostname(b"a:1"));
        assert!(hostname_allowed(b"x.a.com", b"*.a.com"));
        assert!(!hostname_allowed(b"a.com", b"*.a.com"));
        assert!(hostname_allowed(b"anything", b"*"));
    }

    #[test]
    fn url_ascii_encodes_high_bytes() {
        assert_eq!(&*url_ascii("https://e.com/č".as_bytes()), b"https://e.com/%C4%8D");
    }
}
