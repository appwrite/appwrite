use std::net::{Ipv4Addr, Ipv6Addr};

use crate::error::{Error, Result};

/// Parsed PROXY protocol header (v1 text or v2 binary).
/// PHP `Utopia\DNS\ProxyProtocol`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ProxyProtocol {
    pub length: usize,
    pub ip: Option<String>,
    pub port: Option<u16>,
}

impl ProxyProtocol {
    pub const SIGNATURE_V2: &'static [u8] = b"\r\n\r\n\x00\r\nQUIT\n";
    const PREFIX_V1: &'static str = "PROXY ";
    const MAX_V1_LENGTH: usize = 107;

    /// PHP `ProxyProtocol::parse`. Returns `Ok(None)` when more bytes are needed.
    pub fn parse(buffer: &[u8]) -> Result<Option<Self>> {
        if buffer.starts_with(Self::SIGNATURE_V2) {
            return Self::parse_v2(buffer);
        }
        if buffer.starts_with(Self::PREFIX_V1.as_bytes()) {
            return Self::parse_v1(buffer);
        }
        if buffer.is_empty()
            || Self::SIGNATURE_V2.starts_with(buffer)
            || Self::PREFIX_V1.as_bytes().starts_with(buffer)
        {
            return Ok(None);
        }
        Err(Error::other("Invalid PROXY protocol header."))
    }

    fn parse_v1(buffer: &[u8]) -> Result<Option<Self>> {
        let Some(end) = find_crlf(buffer) else {
            if buffer.len() >= Self::MAX_V1_LENGTH {
                return Err(Error::other("PROXY protocol v1 header is not terminated."));
            }
            return Ok(None);
        };
        let length = end + 2;
        if length > Self::MAX_V1_LENGTH {
            return Err(Error::other(
                "PROXY protocol v1 header exceeds the maximum length.",
            ));
        }
        let line = std::str::from_utf8(&buffer[..end])
            .map_err(|_| Error::other("Invalid PROXY protocol v1 header."))?;
        let parts: Vec<&str> = line.split(' ').collect();
        let protocol = parts.get(1).copied().unwrap_or("");
        if protocol == "UNKNOWN" {
            return Ok(Some(Self {
                length,
                ip: None,
                port: None,
            }));
        }
        if !matches!(protocol, "TCP4" | "TCP6") || parts.len() != 6 {
            return Err(Error::other("Invalid PROXY protocol v1 header."));
        }
        let ip_raw = parts[2];
        let port_raw = parts[4];
        let ip_ok = if protocol == "TCP4" {
            ip_raw.parse::<Ipv4Addr>().is_ok()
        } else {
            ip_raw.parse::<Ipv6Addr>().is_ok()
        };
        let port: u16 = port_raw
            .parse()
            .map_err(|_| Error::other("Invalid PROXY protocol v1 source address."))?;
        if !ip_ok {
            return Err(Error::other("Invalid PROXY protocol v1 source address."));
        }
        Ok(Some(Self {
            length,
            ip: Some(ip_raw.to_string()),
            port: Some(port),
        }))
    }

    fn parse_v2(buffer: &[u8]) -> Result<Option<Self>> {
        if buffer.len() < 16 {
            return Ok(None);
        }
        let version_command = buffer[12];
        let command = version_command & 0x0F;
        if version_command >> 4 != 2 || command > 1 {
            return Err(Error::other("Invalid PROXY protocol v2 header."));
        }
        let addr_len = u16::from_be_bytes([buffer[14], buffer[15]]) as usize;
        let length = 16 + addr_len;
        if buffer.len() < length {
            return Ok(None);
        }
        if command == 0 {
            return Ok(Some(Self {
                length,
                ip: None,
                port: None,
            }));
        }
        let address_size = match buffer[13] >> 4 {
            1 => Some(4usize),
            2 => Some(16usize),
            _ => None,
        };
        let Some(address_size) = address_size else {
            return Ok(Some(Self {
                length,
                ip: None,
                port: None,
            }));
        };
        if addr_len < address_size * 2 + 4 {
            return Err(Error::other(
                "PROXY protocol v2 header is missing addresses.",
            ));
        }
        let ip_bytes = &buffer[16..16 + address_size];
        let ip = if address_size == 4 {
            Ipv4Addr::new(ip_bytes[0], ip_bytes[1], ip_bytes[2], ip_bytes[3]).to_string()
        } else {
            let mut octets = [0u8; 16];
            octets.copy_from_slice(ip_bytes);
            Ipv6Addr::from(octets).to_string()
        };
        let port_off = 16 + address_size * 2;
        let port = u16::from_be_bytes([buffer[port_off], buffer[port_off + 1]]);
        Ok(Some(Self {
            length,
            ip: Some(ip),
            port: Some(port),
        }))
    }
}

fn find_crlf(buffer: &[u8]) -> Option<usize> {
    buffer.windows(2).position(|w| w == b"\r\n")
}
