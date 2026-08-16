use crate::attribute::Attribute;
use crate::condition::{TYPE_EQUAL, TYPE_NOT_EQUAL};
use serde_json::Value;
use std::net::IpAddr;

/// IP typed matching (`Utopia\WAF\Attributes\IP`).
///
/// Lets IP-valued attributes match against CIDR blocks alongside plain IPs,
/// e.g. `equal('ip', ['203.0.113.10', '10.0.0.0/8'])`. Plain IP values fall
/// back to the default case-insensitive string equality.
#[derive(Debug, Clone, Copy, Default)]
pub struct Ip;

impl Attribute for Ip {
    fn compare(&self, method: &str, value: &Value, expected: &Value) -> Option<bool> {
        if method != TYPE_EQUAL {
            return None;
        }

        let expected = expected.as_str()?;
        if !is_cidr(expected) {
            return None;
        }

        let Some(value) = value.as_str() else {
            return Some(false);
        };

        Some(cidr_contains(expected, value))
    }

    fn validate_value(&self, method: &str, expected: &Value) -> Option<String> {
        if method != TYPE_EQUAL && method != TYPE_NOT_EQUAL {
            return None;
        }

        let Some(expected) = expected.as_str() else {
            return Some("Value must be an IP address or CIDR block string.".into());
        };

        if expected.parse::<IpAddr>().is_ok() || is_cidr(expected) {
            return None;
        }

        Some(format!(
            "Value \"{expected}\" is not a valid IP address or CIDR block."
        ))
    }
}

/// Check whether a candidate string is a valid CIDR block (`"<ip>/<prefix>"`).
fn is_cidr(candidate: &str) -> bool {
    parse_cidr(candidate).is_some()
}

/// Check whether an IP address falls inside a CIDR block.
///
/// Malformed input or mismatched address families (IPv4 vs IPv6) never match.
fn cidr_contains(cidr: &str, ip: &str) -> bool {
    let Some((network, prefix)) = parse_cidr(cidr) else {
        return false;
    };

    let Some(address) = inet_pton(ip) else {
        return false;
    };
    if address.len() != network.len() {
        return false;
    }

    let full_bytes = prefix / 8;
    if full_bytes > 0 && address[..full_bytes] != network[..full_bytes] {
        return false;
    }

    let remaining_bits = prefix % 8;
    if remaining_bits == 0 {
        return true;
    }

    let mask = (0xFFu32 << (8 - remaining_bits) & 0xFF) as u8;
    (address[full_bytes] & mask) == (network[full_bytes] & mask)
}

/// Parse a CIDR block into its packed network address and prefix length.
///
/// Mirrors PHP `parseCidr`: `inet_pton` + `ctype_digit` prefix, prefix may be
/// `0`, and must not exceed the address family width.
fn parse_cidr(candidate: &str) -> Option<(Vec<u8>, usize)> {
    let separator = candidate.find('/')?;
    let ip = &candidate[..separator];
    let prefix_part = &candidate[separator + 1..];

    if prefix_part.is_empty() || !prefix_part.bytes().all(|b| b.is_ascii_digit()) {
        return None;
    }

    let network = inet_pton(ip)?;
    let prefix: usize = prefix_part.parse().ok()?;
    let max_prefix = network.len() * 8;
    if prefix > max_prefix {
        return None;
    }

    Some((network, prefix))
}

/// PHP `inet_pton` - packed network-order bytes, or `None` when invalid.
fn inet_pton(ip: &str) -> Option<Vec<u8>> {
    match ip.parse::<IpAddr>() {
        Ok(IpAddr::V4(addr)) => Some(addr.octets().to_vec()),
        Ok(IpAddr::V6(addr)) => Some(addr.octets().to_vec()),
        Err(_) => None,
    }
}
