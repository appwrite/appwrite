//! Where a client may connect.
//!
//! A transport checks its [`Destinations`] against the address each
//! connection actually reached (every connection, every redirect hop), not a
//! DNS answer taken earlier, so a host name that resolves differently at
//! connect time cannot get past the check.

use php_std::filter::{self, Options};

use crate::Error;

/// Where requests may connect. Implement it for a policy of your own.
///
/// PHP: `Utopia\Client\Destinations`.
pub trait Destinations: Send + Sync + std::fmt::Debug {
    /// Whether a connection to this IP address (text form, IPv6 without
    /// brackets) may carry the request.
    ///
    /// PHP: `allows($address)`.
    fn allows(&self, address: &str) -> bool;

    /// Whether the request may go through a proxy. Through a proxy the
    /// connected address is the proxy's, so destinations that restrict
    /// addresses must not permit one.
    ///
    /// PHP: `permitsProxy()`.
    fn permits_proxy(&self) -> bool;
}

/// Any address, through any proxy: for services whose address the operator
/// configured, never for a URL a user supplied. The default.
///
/// PHP: `Utopia\Client\Destinations\Anywhere`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Anywhere;

impl Destinations for Anywhere {
    fn allows(&self, _address: &str) -> bool {
        true
    }

    fn permits_proxy(&self) -> bool {
        true
    }
}

/// An IPv4 or IPv6 address or CIDR range. A single address is its /32 or
/// /128, so every spelling of an address (`::1`, `0:0:0:0:0:0:0:1`) is the
/// same range.
///
/// PHP: `Utopia\Client\Destinations\IPRange`.
///
/// ```
/// use utopia_client::IpRange;
///
/// let range = IpRange::new("10.0.0.0/8")?;
/// assert!(range.contains("10.1.2.3"));
/// assert!(!range.contains("11.0.0.1"));
/// assert!(IpRange::new("10.0.0.0/").is_err());
/// # Ok::<(), utopia_client::Error>(())
/// ```
///
/// ```php
/// use Utopia\Client\Destinations\IPRange;
///
/// $range = new IPRange('10.0.0.0/8');
/// var_dump($range->contains('10.1.2.3')); // bool(true)
/// var_dump($range->contains('11.0.0.1')); // bool(false)
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct IpRange {
    network: Vec<u8>,
    prefix: usize,
}

impl IpRange {
    /// Parses `address` or `address/prefix`.
    ///
    /// Fails with [`Error::InvalidArgument`] when the range is not an
    /// address or a CIDR with a numeric prefix in bounds (`10.0.0.0/` and
    /// `10.0.0.0/x` must not read as `/0`, which would match everything),
    /// and with [`Error::Value`] when the address holds a NUL byte (PHP's
    /// `inet_pton()` throws).
    ///
    /// PHP: `new IPRange($range)`.
    pub fn new(range: &str) -> Result<IpRange, Error> {
        let invalid = || Error::InvalidArgument(format!("Not an IP address or range: '{range}'."));
        let trimmed = php_std::string::trim(range.as_bytes(), b" \t\n\r\0\x0B");
        let mut parts = trimmed.splitn(2, |&b| b == b'/');
        let address = parts.next().unwrap_or_default();
        let network = php_std::net::inet_pton(address)
            .map_err(|e| Error::Value(e.to_string()))?
            .map(|p| p.as_bytes().to_vec())
            .ok_or_else(invalid)?;
        let bits = network.len() * 8;
        let prefix = match parts.next() {
            None => bits,
            Some(p) if !p.is_empty() && p.iter().all(u8::is_ascii_digit) => {
                // PHP's (int) saturates; anything that long is out of bounds anyway.
                let digits = std::str::from_utf8(p).unwrap_or_default();
                digits.parse::<usize>().unwrap_or(usize::MAX)
            }
            Some(_) => return Err(invalid()),
        };
        if prefix > bits {
            return Err(invalid());
        }
        Ok(IpRange { network, prefix })
    }

    /// Whether the address is inside the range; `false` for anything that
    /// is not an address of the range's family.
    ///
    /// PHP: `contains($address)`.
    pub fn contains(&self, address: &str) -> bool {
        let Some(packed) = pton(address.as_bytes()) else { return false };
        if packed.len() != self.network.len() {
            return false;
        }
        let bytes = self.prefix / 8;
        if packed[..bytes] != self.network[..bytes] {
            return false;
        }
        let bits = self.prefix % 8;
        if bits == 0 {
            return true;
        }
        let mask = (0xFFu16 << (8 - bits)) as u8;
        packed[bytes] & mask == self.network[bytes] & mask
    }
}

/// `@inet_pton($ip)`: the packed address, `None` for `false` (and for the
/// `ValueError` a NUL byte raises, which PHP's `@` does not silence but
/// which no address contains).
fn pton(ip: &[u8]) -> Option<Vec<u8>> {
    php_std::net::inet_pton(ip).ok().flatten().map(|p| p.as_bytes().to_vec())
}

/// Reserved ranges PHP's `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`
/// miss. The IPv6 ones include the IPv4-mapped, translated and 6to4 ranges
/// that could smuggle a private IPv4 destination past an IPv6-only check.
const RESERVED: [&str; 17] = [
    "100.64.0.0/10",      // CGNAT (RFC 6598)
    "192.0.0.0/24",       // IETF protocol assignments
    "192.0.2.0/24",       // TEST-NET-1
    "198.18.0.0/15",      // Benchmarking
    "198.51.100.0/24",    // TEST-NET-2
    "203.0.113.0/24",     // TEST-NET-3
    "224.0.0.0/4",        // Multicast
    "255.255.255.255/32", // Broadcast
    "::/128",             // Unspecified
    "::ffff:0:0/96",      // IPv4-mapped (::ffff:127.0.0.1)
    "64:ff9b::/96",       // IPv4/IPv6 translation
    "64:ff9b:1::/48",     // Local-use IPv4/IPv6 translation
    "100::/64",           // Discard
    "2001::/32",          // Teredo
    "2001:db8::/32",      // Documentation
    "2002::/16",          // 6to4 (2002:7f00::/24 is 127.0.0.0/8)
    "ff00::/8",           // Multicast
];

/// Globally routable addresses only: never a private, loopback, link-local,
/// multicast or otherwise reserved one (cloud metadata, the host, the
/// internal network), unless one of the given ranges admits it. Never
/// through a proxy. For fetching a URL a user supplied.
///
/// PHP: `Utopia\Client\Destinations\PublicInternet`.
///
/// ```
/// use utopia_client::{Destinations, IpRange, PublicInternet};
///
/// let public = PublicInternet::new([]);
/// assert!(public.allows("8.8.8.8"));
/// assert!(!public.allows("169.254.169.254"));
///
/// let internal = PublicInternet::new([IpRange::new("10.0.0.0/8")?]);
/// assert!(internal.allows("10.0.0.7"));
/// # Ok::<(), utopia_client::Error>(())
/// ```
///
/// ```php
/// use Utopia\Client\Destinations\IPRange;
/// use Utopia\Client\Destinations\PublicInternet;
///
/// $public = new PublicInternet();
/// var_dump($public->allows('8.8.8.8')); // bool(true)
/// var_dump($public->allows('169.254.169.254')); // bool(false)
/// var_dump((new PublicInternet(new IPRange('10.0.0.0/8')))->allows('10.0.0.7')); // bool(true)
/// ```
#[derive(Debug, Clone)]
pub struct PublicInternet {
    reserved: Vec<IpRange>,
    also_allowed: Vec<IpRange>,
}

impl PublicInternet {
    /// Public addresses, plus the private or reserved ranges given.
    ///
    /// PHP: `new PublicInternet(...$alsoAllowed)`.
    pub fn new(also_allowed: impl IntoIterator<Item = IpRange>) -> PublicInternet {
        PublicInternet {
            reserved: RESERVED.iter().filter_map(|r| IpRange::new(r).ok()).collect(),
            also_allowed: also_allowed.into_iter().collect(),
        }
    }
}

impl Default for PublicInternet {
    fn default() -> PublicInternet {
        PublicInternet::new([])
    }
}

impl Destinations for PublicInternet {
    fn allows(&self, address: &str) -> bool {
        if self.also_allowed.iter().any(|range| range.contains(address)) {
            return true;
        }
        let flags = filter::FILTER_FLAG_NO_PRIV_RANGE | filter::FILTER_FLAG_NO_RES_RANGE;
        let valid = matches!(
            filter::filter_var(&filter::Value::Str(address.as_bytes().to_vec()), filter::FILTER_VALIDATE_IP, &Options::Flags(flags)),
            Ok(f) if f.value != filter::Value::Bool(false)
        );
        valid && self.reserved.iter().all(|range| !range.contains(address))
    }

    fn permits_proxy(&self) -> bool {
        false
    }
}
