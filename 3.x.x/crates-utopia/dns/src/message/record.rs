use std::net::{Ipv4Addr, Ipv6Addr};

use crate::error::{Error, Result};
use crate::message::domain::Domain;
use crate::wire::{normalize_name, push_u16, push_u32, read_u16, read_u32};

/// A DNS resource record. PHP `Utopia\DNS\Message\Record`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Record {
    pub name: String,
    pub type_code: u16,
    pub class: u16,
    pub ttl: u32,
    pub rdata: String,
    pub priority: Option<i64>,
    pub weight: Option<i64>,
    pub port: Option<i64>,
}

impl Record {
    pub const TYPE_A: u16 = 1;
    pub const TYPE_NS: u16 = 2;
    pub const TYPE_MD: u16 = 3;
    pub const TYPE_MF: u16 = 4;
    pub const TYPE_CNAME: u16 = 5;
    pub const TYPE_SOA: u16 = 6;
    pub const TYPE_MB: u16 = 7;
    pub const TYPE_MG: u16 = 8;
    pub const TYPE_MR: u16 = 9;
    pub const TYPE_NULL: u16 = 10;
    pub const TYPE_WKS: u16 = 11;
    pub const TYPE_PTR: u16 = 12;
    pub const TYPE_HINFO: u16 = 13;
    pub const TYPE_MINFO: u16 = 14;
    pub const TYPE_MX: u16 = 15;
    pub const TYPE_TXT: u16 = 16;
    pub const TYPE_AAAA: u16 = 28;
    pub const TYPE_SRV: u16 = 33;
    pub const TYPE_CAA: u16 = 257;

    pub const CLASS_IN: u16 = 1;
    pub const CLASS_CS: u16 = 2;
    pub const CLASS_CH: u16 = 3;
    pub const CLASS_HS: u16 = 4;

    const IPV4_LEN: usize = 4;
    const IPV6_LEN: usize = 16;
    const MAX_PRIORITY: i64 = 65_535;
    const MAX_WEIGHT: i64 = 65_535;
    const MAX_PORT: i64 = 65_535;
    const MAX_CAA_FLAGS: i64 = 255;
    const MAX_TXT_CHUNK: usize = 255;

    /// PHP `Record::__construct` with IN class, TTL 0, empty rdata.
    #[must_use]
    pub fn new(name: impl AsRef<str>, type_code: u16) -> Self {
        Self {
            name: normalize_name(name.as_ref()),
            type_code,
            class: Self::CLASS_IN,
            ttl: 0,
            rdata: String::new(),
            priority: None,
            weight: None,
            port: None,
        }
    }

    #[must_use]
    #[allow(clippy::should_implement_trait)]
    pub fn class(mut self, class: u16) -> Self {
        self.class = class;
        self
    }

    #[must_use]
    pub fn ttl(mut self, ttl: u32) -> Self {
        self.ttl = ttl;
        self
    }

    #[must_use]
    pub fn rdata(mut self, rdata: impl Into<String>) -> Self {
        self.rdata = rdata.into();
        self
    }

    #[must_use]
    pub fn priority(mut self, priority: i64) -> Self {
        self.priority = Some(priority);
        self
    }

    #[must_use]
    pub fn weight(mut self, weight: i64) -> Self {
        self.weight = Some(weight);
        self
    }

    #[must_use]
    pub fn port(mut self, port: i64) -> Self {
        self.port = Some(port);
        self
    }

    /// PHP `Record::typeNameToCode`.
    #[must_use]
    pub fn type_name_to_code(name: &str) -> Option<u16> {
        match name.to_ascii_uppercase().as_str() {
            "A" => Some(Self::TYPE_A),
            "NS" => Some(Self::TYPE_NS),
            "MD" => Some(Self::TYPE_MD),
            "MF" => Some(Self::TYPE_MF),
            "CNAME" => Some(Self::TYPE_CNAME),
            "SOA" => Some(Self::TYPE_SOA),
            "MB" => Some(Self::TYPE_MB),
            "MG" => Some(Self::TYPE_MG),
            "MR" => Some(Self::TYPE_MR),
            "NULL" => Some(Self::TYPE_NULL),
            "WKS" => Some(Self::TYPE_WKS),
            "PTR" => Some(Self::TYPE_PTR),
            "HINFO" => Some(Self::TYPE_HINFO),
            "MINFO" => Some(Self::TYPE_MINFO),
            "MX" => Some(Self::TYPE_MX),
            "TXT" => Some(Self::TYPE_TXT),
            "AAAA" => Some(Self::TYPE_AAAA),
            "SRV" => Some(Self::TYPE_SRV),
            "CAA" => Some(Self::TYPE_CAA),
            _ => None,
        }
    }

    /// PHP `Record::typeCodeToName`.
    #[must_use]
    pub fn type_code_to_name(code: u16) -> Option<&'static str> {
        match code {
            Self::TYPE_A => Some("A"),
            Self::TYPE_NS => Some("NS"),
            Self::TYPE_MD => Some("MD"),
            Self::TYPE_MF => Some("MF"),
            Self::TYPE_CNAME => Some("CNAME"),
            Self::TYPE_SOA => Some("SOA"),
            Self::TYPE_MB => Some("MB"),
            Self::TYPE_MG => Some("MG"),
            Self::TYPE_MR => Some("MR"),
            Self::TYPE_NULL => Some("NULL"),
            Self::TYPE_WKS => Some("WKS"),
            Self::TYPE_PTR => Some("PTR"),
            Self::TYPE_HINFO => Some("HINFO"),
            Self::TYPE_MINFO => Some("MINFO"),
            Self::TYPE_MX => Some("MX"),
            Self::TYPE_TXT => Some("TXT"),
            Self::TYPE_AAAA => Some("AAAA"),
            Self::TYPE_SRV => Some("SRV"),
            Self::TYPE_CAA => Some("CAA"),
            _ => None,
        }
    }

    /// PHP `Record::withName`.
    #[must_use]
    pub fn with_name(&self, name: impl AsRef<str>) -> Self {
        let mut clone = self.clone();
        clone.name = normalize_name(name.as_ref());
        clone
    }

    /// PHP `Record::decode`.
    #[allow(clippy::too_many_lines)]
    pub fn decode(data: &[u8], offset: &mut usize) -> Result<Self> {
        let name = Domain::decode(data, offset)?;
        let limit = data.len();
        if *offset + 10 > limit {
            return Err(Error::decoding("Truncated RR header"));
        }
        let type_code =
            read_u16(data, *offset).map_err(|_| Error::decoding("Failed to unpack record type"))?;
        *offset += 2;
        let class = read_u16(data, *offset)
            .map_err(|_| Error::decoding("Failed to unpack record class"))?;
        *offset += 2;
        let ttl =
            read_u32(data, *offset).map_err(|_| Error::decoding("Failed to unpack record TTL"))?;
        *offset += 4;
        let rdlength = usize::from(
            read_u16(data, *offset)
                .map_err(|_| Error::decoding("Failed to unpack record length"))?,
        );
        *offset += 2;
        if *offset + rdlength > limit {
            return Err(Error::decoding("RDATA exceeds packet bounds"));
        }
        let rdata_raw = &data[*offset..*offset + rdlength];
        *offset += rdlength;

        let rdata;
        let mut priority = None;
        let mut weight = None;
        let mut port = None;

        match type_code {
            Self::TYPE_A => {
                if rdata_raw.len() != Self::IPV4_LEN {
                    return Err(Error::decoding("Invalid IPv4 address length"));
                }
                rdata = Ipv4Addr::new(rdata_raw[0], rdata_raw[1], rdata_raw[2], rdata_raw[3])
                    .to_string();
            }
            Self::TYPE_AAAA => {
                if rdata_raw.len() != Self::IPV6_LEN {
                    return Err(Error::decoding("Invalid IPv6 address length"));
                }
                let mut octets = [0u8; 16];
                octets.copy_from_slice(rdata_raw);
                rdata = Ipv6Addr::from(octets).to_string();
            }
            Self::TYPE_CNAME | Self::TYPE_NS | Self::TYPE_PTR => {
                let mut temp = *offset - rdlength;
                rdata = Domain::decode(data, &mut temp)?;
            }
            Self::TYPE_MX => {
                if rdata_raw.len() < 3 {
                    return Err(Error::decoding(format!(
                        "Invalid MX RDATA length: {}",
                        rdata_raw.len()
                    )));
                }
                priority =
                    Some(i64::from(read_u16(rdata_raw, 0).map_err(|_| {
                        Error::decoding("Failed to unpack MX priority")
                    })?));
                let mut temp = *offset - rdlength + 2;
                rdata = Domain::decode(data, &mut temp)?;
            }
            Self::TYPE_SRV => {
                if rdata_raw.len() < 7 {
                    return Err(Error::decoding(format!(
                        "Invalid SRV RDATA length: {}",
                        rdata_raw.len()
                    )));
                }
                priority =
                    Some(i64::from(read_u16(rdata_raw, 0).map_err(|_| {
                        Error::decoding("Failed to unpack SRV priority")
                    })?));
                weight =
                    Some(i64::from(read_u16(rdata_raw, 2).map_err(|_| {
                        Error::decoding("Failed to unpack SRV weight")
                    })?));
                port = Some(i64::from(
                    read_u16(rdata_raw, 4)
                        .map_err(|_| Error::decoding("Failed to unpack SRV port"))?,
                ));
                let mut temp = *offset - rdlength + 6;
                rdata = Domain::decode(data, &mut temp)?;
            }
            Self::TYPE_SOA => {
                let mut temp = *offset - rdlength;
                let mname = Domain::decode(data, &mut temp)?;
                let rname = Domain::decode(data, &mut temp)?;
                if temp + 20 > data.len() {
                    return Err(Error::decoding("Invalid SOA record length"));
                }
                let serial = u64::from(
                    read_u32(data, temp)
                        .map_err(|_| Error::decoding("Unable to unpack SOA timings"))?,
                );
                let refresh = read_u32(data, temp + 4)
                    .map_err(|_| Error::decoding("Unable to unpack SOA timings"))?;
                let retry = read_u32(data, temp + 8)
                    .map_err(|_| Error::decoding("Unable to unpack SOA timings"))?;
                let expire = read_u32(data, temp + 12)
                    .map_err(|_| Error::decoding("Unable to unpack SOA timings"))?;
                let minimum = read_u32(data, temp + 16)
                    .map_err(|_| Error::decoding("Unable to unpack SOA timings"))?;
                rdata = format!("{mname} {rname} {serial} {refresh} {retry} {expire} {minimum}");
            }
            Self::TYPE_TXT => {
                if rdlength < 1 {
                    return Err(Error::decoding("Invalid TXT RDATA length: 0"));
                }
                let mut chunks = Vec::new();
                let mut pos = 0usize;
                while pos < rdlength {
                    let len = usize::from(rdata_raw[pos]);
                    if pos + 1 + len > rdlength {
                        return Err(Error::decoding("TXT chunk length exceeds RDATA size"));
                    }
                    chunks.push(
                        String::from_utf8_lossy(&rdata_raw[pos + 1..pos + 1 + len]).into_owned(),
                    );
                    pos += len + 1;
                }
                rdata = chunks.concat();
            }
            Self::TYPE_CAA => {
                if rdlength < 2 {
                    return Err(Error::decoding("Invalid CAA record length"));
                }
                let flags = rdata_raw[0];
                let tag_length = usize::from(rdata_raw[1]);
                if tag_length > rdata_raw.len() - 2 {
                    return Err(Error::decoding("Invalid CAA tag length"));
                }
                let tag = String::from_utf8_lossy(&rdata_raw[2..2 + tag_length]).into_owned();
                let value = String::from_utf8_lossy(&rdata_raw[2 + tag_length..]).into_owned();
                rdata = format!("{flags} {tag} \"{value}\"");
            }
            _ => {
                rdata = hex_encode(rdata_raw);
            }
        }

        Ok(Self {
            name: normalize_name(&name),
            type_code,
            class,
            ttl,
            rdata,
            priority,
            weight,
            port,
        })
    }

    /// PHP `Record::encode`.
    pub fn encode(&self) -> Result<Vec<u8>> {
        let mut data = Domain::encode(&self.name)?;
        push_u16(&mut data, self.type_code);
        push_u16(&mut data, self.class);
        push_u32(&mut data, self.ttl);
        let rdata = self.encode_rdata()?;
        push_u16(
            &mut data,
            u16::try_from(rdata.len()).map_err(|_| Error::invalid("RDATA exceeds 65535 bytes"))?,
        );
        data.extend_from_slice(&rdata);
        Ok(data)
    }

    /// PHP `Record::validateRdata`.
    pub fn validate_rdata(&self) -> Result<()> {
        self.encode_rdata().map(|_| ())
    }

    fn encode_rdata(&self) -> Result<Vec<u8>> {
        match self.type_code {
            Self::TYPE_A => encode_ipv4(&self.rdata),
            Self::TYPE_AAAA => encode_ipv6(&self.rdata),
            Self::TYPE_CNAME | Self::TYPE_NS | Self::TYPE_PTR => Domain::encode(&self.rdata),
            Self::TYPE_MX => {
                let priority = self.priority.unwrap_or(0);
                if !(0..=Self::MAX_PRIORITY).contains(&priority) {
                    return Err(Error::invalid(format!(
                        "MX priority must be between 0 and {}, got {priority}",
                        Self::MAX_PRIORITY
                    )));
                }
                let mut out = Vec::new();
                push_u16(&mut out, u16::try_from(priority).unwrap_or(0));
                out.extend(Domain::encode(&self.rdata)?);
                Ok(out)
            }
            Self::TYPE_SRV => {
                let priority = self.priority.unwrap_or(0);
                let weight = self.weight.unwrap_or(0);
                let port = self.port.unwrap_or(0);
                if !(0..=Self::MAX_PRIORITY).contains(&priority) {
                    return Err(Error::invalid(format!(
                        "SRV priority must be between 0 and {}, got {priority}",
                        Self::MAX_PRIORITY
                    )));
                }
                if !(0..=Self::MAX_WEIGHT).contains(&weight) {
                    return Err(Error::invalid(format!(
                        "SRV weight must be between 0 and {}, got {weight}",
                        Self::MAX_WEIGHT
                    )));
                }
                if !(0..=Self::MAX_PORT).contains(&port) {
                    return Err(Error::invalid(format!(
                        "SRV port must be between 0 and {}, got {port}",
                        Self::MAX_PORT
                    )));
                }
                let mut out = Vec::new();
                push_u16(&mut out, u16::try_from(priority).unwrap_or(0));
                push_u16(&mut out, u16::try_from(weight).unwrap_or(0));
                push_u16(&mut out, u16::try_from(port).unwrap_or(0));
                out.extend(Domain::encode(&self.rdata)?);
                Ok(out)
            }
            Self::TYPE_TXT => Ok(encode_txt(&self.rdata)),
            Self::TYPE_CAA => self.encode_caa_rdata(),
            Self::TYPE_SOA => self.encode_soa_rdata(),
            _ => hex_decode(&self.rdata).ok_or_else(|| {
                Error::invalid(format!(
                    "Invalid hexadecimal payload for record type {}",
                    self.type_code
                ))
            }),
        }
    }

    fn encode_soa_rdata(&self) -> Result<Vec<u8>> {
        let input = self.rdata.trim();
        if input.is_empty() {
            return Err(Error::invalid("SOA RDATA cannot be empty"));
        }
        let mut parts = Vec::new();
        for token in input.split_whitespace() {
            let clean = token.trim();
            if clean.is_empty() || clean == "(" || clean == ")" {
                continue;
            }
            parts.push(clean);
        }
        if parts.len() != 7 {
            return Err(Error::invalid(
                "SOA RDATA must contain MNAME, RNAME, SERIAL, REFRESH, RETRY, EXPIRE and MINIMUM fields",
            ));
        }
        let mut numbers = Vec::with_capacity(5);
        for value in &parts[2..] {
            if !value.bytes().all(|b| b.is_ascii_digit()) {
                return Err(Error::invalid(
                    "SOA timing fields must be unsigned integers",
                ));
            }
            let number: u64 = value
                .parse()
                .map_err(|_| Error::invalid(format!("SOA timing field out of range: {value}")))?;
            if number > 0xFFFF_FFFF {
                return Err(Error::invalid(format!(
                    "SOA timing field out of range: {value}"
                )));
            }
            numbers.push(u32::try_from(number).unwrap_or(u32::MAX));
        }
        let mut out = Domain::encode(parts[0])?;
        out.extend(encode_soa_rname(parts[1])?);
        for n in numbers {
            push_u32(&mut out, n);
        }
        Ok(out)
    }

    fn encode_caa_rdata(&self) -> Result<Vec<u8>> {
        let input = self.rdata.trim();
        if input.is_empty() {
            return Err(Error::invalid("CAA RDATA cannot be empty"));
        }
        let (flags, tag, value) = parse_caa(input)
            .ok_or_else(|| Error::invalid(format!("Invalid CAA RDATA format: {}", self.rdata)))?;
        if !(0..=Self::MAX_CAA_FLAGS).contains(&flags) {
            return Err(Error::invalid(format!(
                "CAA flags must be between 0 and {}, got {flags}",
                Self::MAX_CAA_FLAGS
            )));
        }
        if tag.len() > 255 {
            return Err(Error::invalid("CAA tag exceeds 255 bytes"));
        }
        let unescaped = php_stripcslashes(&value);
        let mut out = vec![
            u8::try_from(flags).unwrap_or(0),
            u8::try_from(tag.len()).unwrap_or(0),
        ];
        out.extend_from_slice(tag.as_bytes());
        out.extend_from_slice(unescaped.as_bytes());
        Ok(out)
    }
}

fn encode_ipv4(rdata: &str) -> Result<Vec<u8>> {
    match rdata.parse::<Ipv4Addr>() {
        Ok(addr) => Ok(addr.octets().to_vec()),
        Err(_) => Err(Error::invalid(format!("Invalid IPv4 address: {rdata}"))),
    }
}

fn encode_ipv6(rdata: &str) -> Result<Vec<u8>> {
    match rdata.parse::<Ipv6Addr>() {
        Ok(addr) => Ok(addr.octets().to_vec()),
        Err(_) => Err(Error::invalid(format!("Invalid IPv6 address: {rdata}"))),
    }
}

fn encode_txt(rdata: &str) -> Vec<u8> {
    let bytes = rdata.as_bytes();
    if bytes.is_empty() {
        return vec![0];
    }
    let mut encoded = Vec::new();
    let mut pos = 0;
    while pos < bytes.len() {
        let chunk_len = (bytes.len() - pos).min(Record::MAX_TXT_CHUNK);
        encoded.push(u8::try_from(chunk_len).unwrap_or(255));
        encoded.extend_from_slice(&bytes[pos..pos + chunk_len]);
        pos += chunk_len;
    }
    encoded
}

fn encode_soa_rname(rname: &str) -> Result<Vec<u8>> {
    if !rname.contains('@') {
        return Domain::encode(rname);
    }
    if rname.bytes().filter(|&b| b == b'@').count() > 1 {
        return Err(Error::invalid(
            "SOA RNAME email must contain exactly one @ separator",
        ));
    }
    let (local_part, domain) = rname.split_once('@').unwrap_or(("", ""));
    if local_part.is_empty() || domain.is_empty() {
        return Err(Error::invalid(
            "SOA RNAME email must have non-empty local part and domain",
        ));
    }
    let local_length = local_part.len();
    if local_length > Domain::MAX_LABEL_LEN {
        return Err(Error::invalid(format!("Label too long: {local_part}")));
    }
    let mut encoded = Vec::with_capacity(1 + local_length + 16);
    encoded.push(u8::try_from(local_length).unwrap_or(u8::MAX));
    encoded.extend_from_slice(local_part.as_bytes());
    encoded.extend(Domain::encode(domain)?);
    if encoded.len() > Domain::MAX_DOMAIN_NAME_LEN {
        return Err(Error::invalid(format!(
            "Encoded domain exceeds maximum length of {} bytes",
            Domain::MAX_DOMAIN_NAME_LEN
        )));
    }
    Ok(encoded)
}

/// PHP `/^(?:(\d+)\s+)?([A-Za-z0-9-]+)\s+"((?:\\\\.|[^"])*)"$/`.
fn parse_caa(input: &str) -> Option<(i64, String, String)> {
    let bytes = input.as_bytes();
    let mut i = 0;
    let mut flags = 0i64;
    if bytes.first().is_some_and(u8::is_ascii_digit) {
        let start = i;
        while i < bytes.len() && bytes[i].is_ascii_digit() {
            i += 1;
        }
        flags = input.get(start..i)?.parse().ok()?;
        if i >= bytes.len() || bytes[i] != b' ' {
            return None;
        }
        i += 1;
        while i < bytes.len() && bytes[i] == b' ' {
            i += 1;
        }
    }
    let tag_start = i;
    while i < bytes.len() && (bytes[i].is_ascii_alphanumeric() || bytes[i] == b'-') {
        i += 1;
    }
    if i == tag_start {
        return None;
    }
    let tag = input.get(tag_start..i)?.to_string();
    if i >= bytes.len() || bytes[i] != b' ' {
        return None;
    }
    i += 1;
    while i < bytes.len() && bytes[i] == b' ' {
        i += 1;
    }
    if i >= bytes.len() || bytes[i] != b'"' {
        return None;
    }
    i += 1;
    let mut value = String::new();
    while i < bytes.len() {
        if bytes[i] == b'\\' {
            value.push('\\');
            i += 1;
            if i >= bytes.len() {
                return None;
            }
            value.push(bytes[i] as char);
            i += 1;
            continue;
        }
        if bytes[i] == b'"' {
            i += 1;
            return if i == bytes.len() {
                Some((flags, tag, value))
            } else {
                None
            };
        }
        value.push(bytes[i] as char);
        i += 1;
    }
    None
}

/// PHP `stripcslashes` (subset used by CAA RDATA).
fn php_stripcslashes(s: &str) -> String {
    let bytes = s.as_bytes();
    let mut out = String::new();
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] != b'\\' {
            out.push(bytes[i] as char);
            i += 1;
            continue;
        }
        i += 1;
        if i >= bytes.len() {
            out.push('\\');
            break;
        }
        match bytes[i] {
            b'n' => out.push('\n'),
            b'r' => out.push('\r'),
            b't' => out.push('\t'),
            b'a' => out.push('\u{07}'),
            b'b' => out.push('\u{08}'),
            b'f' => out.push('\u{0C}'),
            b'v' => out.push('\u{0B}'),
            b'\\' => out.push('\\'),
            b'"' => out.push('"'),
            b'\'' => out.push('\''),
            c if c.is_ascii_digit() => {
                let mut oct = 0u32;
                let mut count = 0;
                while i < bytes.len() && bytes[i].is_ascii_digit() && count < 3 {
                    oct = oct * 8 + u32::from(bytes[i] - b'0');
                    i += 1;
                    count += 1;
                }
                if let Some(ch) = char::from_u32(oct) {
                    out.push(ch);
                }
                continue;
            }
            c => out.push(c as char),
        }
        i += 1;
    }
    out
}

fn hex_encode(data: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let mut out = String::with_capacity(data.len() * 2);
    for b in data {
        out.push(HEX[usize::from(b >> 4)] as char);
        out.push(HEX[usize::from(b & 0x0F)] as char);
    }
    out
}

fn hex_decode(s: &str) -> Option<Vec<u8>> {
    if s.len() % 2 != 0 {
        return None;
    }
    let mut out = Vec::with_capacity(s.len() / 2);
    let bytes = s.as_bytes();
    let mut i = 0;
    while i < bytes.len() {
        let hi = from_hex(bytes[i])?;
        let lo = from_hex(bytes[i + 1])?;
        out.push((hi << 4) | lo);
        i += 2;
    }
    Some(out)
}

fn from_hex(b: u8) -> Option<u8> {
    match b {
        b'0'..=b'9' => Some(b - b'0'),
        b'a'..=b'f' => Some(b - b'a' + 10),
        b'A'..=b'F' => Some(b - b'A' + 10),
        _ => None,
    }
}
