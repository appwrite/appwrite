//! The PROXY protocol header load balancers prepend to TCP connections to
//! pass on the client's address (<https://www.haproxy.org/download/1.8/doc/proxy-protocol.txt>).

use php_std::filter::{FILTER_FLAG_IPV4, FILTER_FLAG_IPV6, FILTER_VALIDATE_INT, FILTER_VALIDATE_IP, Options, Value};

use crate::Error;

/// The binary (version 2) signature.
pub const SIGNATURE_V2: &[u8; 12] = b"\r\n\r\n\x00\r\nQUIT\n";

const PREFIX_V1: &[u8] = b"PROXY ";
/// The longest version 1 header, `\r\n` included.
const MAX_V1_LENGTH: usize = 107;

/// A parsed PROXY protocol header (text version 1 or binary version 2).
///
/// PHP: `Utopia\DNS\ProxyProtocol`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ProxyProtocol {
    /// The header's size in bytes, to strip from the stream.
    pub length: usize,
    /// The original client's address and port; `None` when the header
    /// carries none (`LOCAL`, `UNKNOWN`, `AF_UNSPEC`, `AF_UNIX`).
    pub source: Option<(String, u16)>,
}

impl ProxyProtocol {
    /// Parses the header at the start of `buffer`: `Ok(None)` while it is
    /// a valid but incomplete prefix.
    ///
    /// Fails with [`Error::Exception`] when the buffer does not start with
    /// a valid header.
    ///
    /// ```
    /// use utopia_dns::ProxyProtocol;
    ///
    /// let header = ProxyProtocol::parse(b"PROXY TCP4 192.168.0.1 192.168.0.11 56324 443\r\npayload")?.unwrap();
    /// assert_eq!(header.length, 47);
    /// assert_eq!(header.source, Some(("192.168.0.1".to_owned(), 56324)));
    /// assert_eq!(ProxyProtocol::parse(b"PROXY TCP4")?, None);
    /// # Ok::<(), utopia_dns::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DNS\ProxyProtocol;
    ///
    /// $header = ProxyProtocol::parse("PROXY TCP4 192.168.0.1 192.168.0.11 56324 443\r\npayload");
    /// echo $header->length, ' ', $header->ip, ' ', $header->port, "\n"; // 47 192.168.0.1 56324
    /// var_dump(ProxyProtocol::parse('PROXY TCP4')); // NULL
    /// ```
    ///
    /// PHP: `ProxyProtocol::parse()`.
    pub fn parse(buffer: &[u8]) -> Result<Option<ProxyProtocol>, Error> {
        if buffer.starts_with(SIGNATURE_V2) {
            return parse_v2(buffer);
        }
        if buffer.starts_with(PREFIX_V1) {
            return parse_v1(buffer);
        }
        if buffer.is_empty() || SIGNATURE_V2.starts_with(buffer) || PREFIX_V1.starts_with(buffer) {
            return Ok(None);
        }
        Err(Error::exception("Invalid PROXY protocol header."))
    }
}

fn parse_v1(buffer: &[u8]) -> Result<Option<ProxyProtocol>, Error> {
    let Some(end) = buffer.windows(2).position(|w| w == b"\r\n") else {
        if buffer.len() >= MAX_V1_LENGTH {
            return Err(Error::exception("PROXY protocol v1 header is not terminated."));
        }
        return Ok(None);
    };
    let length = end + 2;
    if length > MAX_V1_LENGTH {
        return Err(Error::exception("PROXY protocol v1 header exceeds the maximum length."));
    }
    let parts: Vec<&[u8]> = buffer[..end].split(|&b| b == b' ').collect();
    let protocol = parts.get(1).copied().unwrap_or_default();
    if protocol == b"UNKNOWN" {
        return Ok(Some(ProxyProtocol { length, source: None }));
    }
    if (protocol != b"TCP4" && protocol != b"TCP6") || parts.len() != 6 {
        return Err(Error::exception("Invalid PROXY protocol v1 header."));
    }
    let flag = if protocol == b"TCP4" { FILTER_FLAG_IPV4 } else { FILTER_FLAG_IPV6 };
    let invalid = || Error::exception("Invalid PROXY protocol v1 source address.");
    let ip =
        match php_std::filter::filter_var(&Value::Str(parts[2].to_vec()), FILTER_VALIDATE_IP, &Options::Flags(flag)) {
            Ok(f) => match f.value {
                Value::Str(ip) => String::from_utf8(ip).map_err(|_| invalid())?,
                _ => return Err(invalid()),
            },
            Err(_) => return Err(invalid()),
        };
    let port = valid_port(parts[4]).ok_or_else(invalid)?;
    Ok(Some(ProxyProtocol { length, source: Some((ip, port)) }))
}

/// `filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]])`.
fn valid_port(text: &[u8]) -> Option<u16> {
    let filtered =
        php_std::filter::filter_var(&Value::Str(text.to_vec()), FILTER_VALIDATE_INT, &Options::Flags(0)).ok()?;
    match filtered.value {
        Value::Int(n) => u16::try_from(n).ok(),
        _ => None,
    }
}

fn parse_v2(buffer: &[u8]) -> Result<Option<ProxyProtocol>, Error> {
    if buffer.len() < 16 {
        return Ok(None);
    }
    let version_command = buffer[12];
    let command = version_command & 0x0F;
    if version_command >> 4 != 2 || command > 1 {
        return Err(Error::exception("Invalid PROXY protocol v2 header."));
    }
    let declared = usize::from(u16::from_be_bytes([buffer[14], buffer[15]]));
    let length = 16 + declared;
    if buffer.len() < length {
        return Ok(None);
    }
    if command == 0 {
        return Ok(Some(ProxyProtocol { length, source: None }));
    }
    let size = match buffer[13] >> 4 {
        1 => 4,
        2 => 16,
        _ => return Ok(Some(ProxyProtocol { length, source: None })),
    };
    if declared < size * 2 + 4 {
        return Err(Error::exception("PROXY protocol v2 header is missing addresses."));
    }
    let ip = php_std::net::inet_ntop(&buffer[16..16 + size])
        .ok_or_else(|| Error::exception("Invalid PROXY protocol v2 source address."))?;
    let at = 16 + size * 2;
    let port = u16::from_be_bytes([buffer[at], buffer[at + 1]]);
    Ok(Some(ProxyProtocol { length, source: Some((ip, port)) }))
}
