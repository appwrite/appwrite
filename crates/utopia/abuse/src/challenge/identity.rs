//! [`Identity`]: the coarse client identity tokens are bound to.

use sha2::{Digest, Sha256};

use crate::{Error, Result};

/// The coarse client identity challenge tokens are bound to: an IP prefix
/// and a User-Agent hash. Coarse on purpose: an IP can drift between issue
/// and verify (CGNAT, Wi-Fi to cellular), and a prefix tolerates that while
/// still making a credential copied to another network fail.
///
/// PHP: `Utopia\Abuse\Challenge\Identity`.
///
/// ```
/// use utopia_abuse::challenge::Identity;
///
/// let identity = Identity::new(24, 48)?;
/// assert_eq!(identity.ip_prefix(b"203.0.113.45")?, b"203.0.113.0/24");
/// assert_eq!(identity.ip_prefix(b"2001:db8:abcd:1234::1")?, b"2001:db8:abcd::/48");
/// assert_eq!(identity.user_agent_hash(b"Mozilla/5.0").len(), 64);
/// # Ok::<(), utopia_abuse::Error>(())
/// ```
///
/// ```php
/// use Utopia\Abuse\Challenge\Identity;
///
/// $identity = new Identity(24, 48);
/// echo $identity->ipPrefix('203.0.113.45'), "\n";           // 203.0.113.0/24
/// echo $identity->ipPrefix('2001:db8:abcd:1234::1'), "\n";  // 2001:db8:abcd::/48
/// echo strlen($identity->userAgentHash('Mozilla/5.0')), "\n"; // 64
/// ```
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Identity {
    ipv4_bits: i64,
    ipv6_bits: i64,
}

impl Identity {
    /// The IPv4 prefix length PHP defaults to.
    pub const DEFAULT_IPV4_BITS: i64 = 24;

    /// The IPv6 prefix length PHP defaults to.
    pub const DEFAULT_IPV6_BITS: i64 = 48;

    /// An identity keeping `ipv4_bits` (0 to 32) of an IPv4 address and
    /// `ipv6_bits` (0 to 128) of an IPv6 one.
    ///
    /// Fails with [`Error::InvalidArgument`] outside those ranges (`ipv4Bits
    /// must be between 0 and 32`, `ipv6Bits must be between 0 and 128`).
    ///
    /// PHP: `new Identity($ipv4Bits = 24, $ipv6Bits = 48)`.
    pub fn new(ipv4_bits: i64, ipv6_bits: i64) -> Result<Self> {
        if !(0..=32).contains(&ipv4_bits) {
            return Err(Error::InvalidArgument("ipv4Bits must be between 0 and 32".into()));
        }
        if !(0..=128).contains(&ipv6_bits) {
            return Err(Error::InvalidArgument("ipv6Bits must be between 0 and 128".into()));
        }
        Ok(Identity { ipv4_bits, ipv6_bits })
    }

    /// The network `ip` belongs to, in CIDR form, such as `203.0.113.0/24`.
    /// Surrounding whitespace is trimmed; an empty value stays empty, and a
    /// value that is not an address is returned as given (trimmed), so
    /// malformed input does not collapse everyone into one prefix.
    ///
    /// Fails with [`Error::Value`] when `ip` contains a NUL byte, as
    /// `inet_pton()` does.
    ///
    /// PHP: `ipPrefix($ip)`.
    pub fn ip_prefix(&self, ip: &[u8]) -> Result<Vec<u8>> {
        let ip = php_std::string::trim(ip, php_std::string::TRIM_CHARACTERS);
        if ip.is_empty() {
            return Ok(Vec::new());
        }
        let packed = php_std::net::inet_pton(ip).map_err(|e| Error::Value(e.to_string()))?;
        let Some(packed) = packed else {
            return Ok(ip.to_vec());
        };
        let binary = packed.as_bytes();
        let bits = if binary.len() == 4 { self.ipv4_bits } else { self.ipv6_bits };
        let masked: Vec<u8> = binary
            .iter()
            .enumerate()
            .map(|(i, byte)| {
                let keep = (bits - i as i64 * 8).clamp(0, 8);
                byte & ((0xFF_u32 << (8 - keep)) & 0xFF) as u8
            })
            .collect();
        let mut prefix = match php_std::net::inet_ntop(&masked) {
            Some(prefix) => prefix.into_bytes(),
            None => ip.to_vec(),
        };
        prefix.extend_from_slice(format!("/{bits}").as_bytes());
        Ok(prefix)
    }

    /// The SHA-256 of the User-Agent, in hex, so a credential minted for one
    /// User-Agent fails under another.
    ///
    /// PHP: `userAgentHash($userAgent)`.
    pub fn user_agent_hash(&self, user_agent: &[u8]) -> String {
        hex::encode(Sha256::digest(user_agent))
    }
}

impl Default for Identity {
    /// `/24` for IPv4 and `/48` for IPv6.
    ///
    /// PHP: `new Identity()`.
    fn default() -> Self {
        Identity { ipv4_bits: Self::DEFAULT_IPV4_BITS, ipv6_bits: Self::DEFAULT_IPV6_BITS }
    }
}
