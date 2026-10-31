//! Resource records (RFC 1035 §3.2, §4.1.3) and their data.

use php_std::Str;

use super::domain;
use super::question::normalize;
use crate::Error;
use crate::error::quote;

/// A resource record: an owner name, type, class, TTL and data.
///
/// The data is in presentation form, as zone files write it: an address
/// for `A` and `AAAA`, a name for `CNAME`, `NS`, `PTR`, `MX` and `SRV`
/// (whose numbers are separate fields), `mname rname serial refresh retry
/// expire minimum` for `SOA`, `flags tag "value"` for `CAA`, the text for
/// `TXT`, and hex for other types. It is checked when the record is
/// encoded, so a zone may hold templates that are never sent.
///
/// ```
/// use utopia_dns::message::Record;
///
/// let record = Record::new("Mail.Example.com", Record::TYPE_MX)
///     .with_ttl(3600)
///     .with_rdata("mail.exchange.example.com")
///     .with_priority(10);
///
/// assert_eq!(record.name(), b"mail.example.com");
/// let wire = record.encode()?;
/// let (decoded, _) = Record::decode(&wire, 0)?;
/// assert_eq!(decoded, record);
/// # Ok::<(), utopia_dns::Error>(())
/// ```
///
/// ```php
/// use Utopia\DNS\Message\Record;
///
/// $record = new Record('Mail.Example.com', Record::TYPE_MX, ttl: 3600, rdata: 'mail.exchange.example.com', priority: 10);
/// echo $record->name, "\n"; // mail.example.com
/// $offset = 0;
/// var_dump(Record::decode($record->encode(), $offset) == $record); // bool(true)
/// ```
///
/// PHP: `Utopia\DNS\Message\Record`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Record {
    name: Str,
    ty: u16,
    class: u16,
    ttl: i64,
    rdata: Str,
    priority: Option<i64>,
    weight: Option<i64>,
    port: Option<i64>,
}

/// Record mnemonics and their codes.
const TYPES: [(&str, u16); 19] = [
    ("A", 1),
    ("NS", 2),
    ("MD", 3),
    ("MF", 4),
    ("CNAME", 5),
    ("SOA", 6),
    ("MB", 7),
    ("MG", 8),
    ("MR", 9),
    ("NULL", 10),
    ("WKS", 11),
    ("PTR", 12),
    ("HINFO", 13),
    ("MINFO", 14),
    ("MX", 15),
    ("TXT", 16),
    ("AAAA", 28),
    ("SRV", 33),
    ("CAA", 257),
];

const MAX_U16: i64 = 65535;
const MAX_CAA_FLAGS: i64 = 255;
const MAX_TXT_CHUNK: usize = 255;

impl Record {
    /// `A`: an IPv4 address.
    pub const TYPE_A: u16 = 1;
    /// `NS`: a name server.
    pub const TYPE_NS: u16 = 2;
    /// `MD` (obsolete).
    pub const TYPE_MD: u16 = 3;
    /// `MF` (obsolete).
    pub const TYPE_MF: u16 = 4;
    /// `CNAME`: the canonical name.
    pub const TYPE_CNAME: u16 = 5;
    /// `SOA`: the start of a zone of authority.
    pub const TYPE_SOA: u16 = 6;
    /// `MB` (experimental).
    pub const TYPE_MB: u16 = 7;
    /// `MG` (experimental).
    pub const TYPE_MG: u16 = 8;
    /// `MR` (experimental).
    pub const TYPE_MR: u16 = 9;
    /// `NULL`.
    pub const TYPE_NULL: u16 = 10;
    /// `WKS`: well-known services.
    pub const TYPE_WKS: u16 = 11;
    /// `PTR`: a domain name pointer.
    pub const TYPE_PTR: u16 = 12;
    /// `HINFO`: host information.
    pub const TYPE_HINFO: u16 = 13;
    /// `MINFO`: mailbox information.
    pub const TYPE_MINFO: u16 = 14;
    /// `MX`: a mail exchange.
    pub const TYPE_MX: u16 = 15;
    /// `TXT`: text.
    pub const TYPE_TXT: u16 = 16;
    /// `AAAA`: an IPv6 address.
    pub const TYPE_AAAA: u16 = 28;
    /// `SRV`: a service location.
    pub const TYPE_SRV: u16 = 33;
    /// `CAA`: certification authority authorization.
    pub const TYPE_CAA: u16 = 257;

    /// `IN`: the Internet.
    pub const CLASS_IN: u16 = 1;
    /// `CS`: CSNET (obsolete).
    pub const CLASS_CS: u16 = 2;
    /// `CH`: Chaos.
    pub const CLASS_CH: u16 = 3;
    /// `HS`: Hesiod.
    pub const CLASS_HS: u16 = 4;

    /// A record named `name` (lower-cased, then trimmed of white space and
    /// NUL bytes), in class `IN`, with a TTL of 0 and empty data. Set the
    /// rest with the builder methods.
    ///
    /// PHP: `new Record($name, $type)`.
    pub fn new(name: impl AsRef<[u8]>, ty: u16) -> Record {
        Record {
            name: normalize(name.as_ref()),
            ty,
            class: Record::CLASS_IN,
            ttl: 0,
            rdata: Str::EMPTY,
            priority: None,
            weight: None,
            port: None,
        }
    }

    /// The record in another class.
    pub fn with_class(mut self, class: u16) -> Record {
        self.class = class;
        self
    }

    /// The record with a TTL in seconds (encoded as its low 32 bits).
    pub fn with_ttl(mut self, ttl: i64) -> Record {
        self.ttl = ttl;
        self
    }

    /// The record with data in presentation form.
    pub fn with_rdata(mut self, rdata: impl AsRef<[u8]>) -> Record {
        self.rdata = Str::copy_from(rdata.as_ref());
        self
    }

    /// The record with a priority (`MX` preference, `SRV` priority).
    pub fn with_priority(mut self, priority: i64) -> Record {
        self.priority = Some(priority);
        self
    }

    /// The record with an `SRV` weight.
    pub fn with_weight(mut self, weight: i64) -> Record {
        self.weight = Some(weight);
        self
    }

    /// The record with an `SRV` port.
    pub fn with_port(mut self, port: i64) -> Record {
        self.port = Some(port);
        self
    }

    /// The owner name: lower case, without a trailing dot.
    pub fn name(&self) -> &[u8] {
        &self.name
    }

    /// The type (`Record::TYPE_*`).
    pub fn ty(&self) -> u16 {
        self.ty
    }

    /// The class (`Record::CLASS_*`).
    pub fn class(&self) -> u16 {
        self.class
    }

    /// The TTL in seconds.
    pub fn ttl(&self) -> i64 {
        self.ttl
    }

    /// The data in presentation form.
    pub fn rdata(&self) -> &[u8] {
        &self.rdata
    }

    /// The `MX` preference or `SRV` priority.
    pub fn priority(&self) -> Option<i64> {
        self.priority
    }

    /// The `SRV` weight.
    pub fn weight(&self) -> Option<i64> {
        self.weight
    }

    /// The `SRV` port.
    pub fn port(&self) -> Option<i64> {
        self.port
    }

    /// The same record under another name.
    ///
    /// PHP: `withName()`.
    pub fn with_name(mut self, name: impl AsRef<[u8]>) -> Record {
        self.name = normalize(name.as_ref());
        self
    }

    /// The code of a type mnemonic (any case): `Some(28)` for `"aaaa"`.
    ///
    /// PHP: `Record::typeNameToCode()`.
    pub fn type_name_to_code(name: &str) -> Option<u16> {
        TYPES.iter().find(|(n, _)| n.eq_ignore_ascii_case(name)).map(|&(_, code)| code)
    }

    /// The mnemonic of a type code: `Some("AAAA")` for 28.
    ///
    /// PHP: `Record::typeCodeToName()`.
    pub fn type_code_to_name(code: u16) -> Option<&'static str> {
        TYPES.iter().find(|&&(_, c)| c == code).map(|&(name, _)| name)
    }

    /// Decodes the record at `offset` in a whole packet (names may point
    /// anywhere before), returning it and the offset after it.
    ///
    /// Fails with [`Error::Decoding`].
    ///
    /// PHP: `Record::decode()`.
    pub fn decode(data: &[u8], offset: usize) -> Result<(Record, usize), Error> {
        let (name, offset) = domain::decode(data, offset)?;
        let Some(fixed) = data.get(offset..offset + 10) else {
            return Err(Error::decoding("Truncated RR header"));
        };
        let ty = u16::from_be_bytes([fixed[0], fixed[1]]);
        let class = u16::from_be_bytes([fixed[2], fixed[3]]);
        let ttl = u32::from_be_bytes([fixed[4], fixed[5], fixed[6], fixed[7]]);
        let length = usize::from(u16::from_be_bytes([fixed[8], fixed[9]]));
        let start = offset + 10;
        let Some(raw) = data.get(start..start + length) else {
            return Err(Error::decoding("RDATA exceeds packet bounds"));
        };
        let end = start + length;
        let mut record = Record::new(name, ty).with_class(class).with_ttl(i64::from(ttl));
        record.rdata = match ty {
            Record::TYPE_A | Record::TYPE_AAAA => {
                let (expected, message) = if ty == Record::TYPE_A {
                    (4, "Invalid IPv4 address length")
                } else {
                    (16, "Invalid IPv6 address length")
                };
                if raw.len() != expected {
                    return Err(Error::decoding(message));
                }
                let text =
                    php_std::net::inet_ntop(raw).ok_or_else(|| Error::decoding("Invalid IPv4 address payload"))?;
                Str::from(text)
            }
            Record::TYPE_CNAME | Record::TYPE_NS | Record::TYPE_PTR => Str::from(domain::decode(data, start)?.0),
            Record::TYPE_MX => {
                if raw.len() < 3 {
                    return Err(Error::decoding(format!("Invalid MX RDATA length: {}", raw.len())));
                }
                record.priority = Some(i64::from(u16::from_be_bytes([raw[0], raw[1]])));
                Str::from(domain::decode(data, start + 2)?.0)
            }
            Record::TYPE_SRV => {
                if raw.len() < 7 {
                    return Err(Error::decoding(format!("Invalid SRV RDATA length: {}", raw.len())));
                }
                record.priority = Some(i64::from(u16::from_be_bytes([raw[0], raw[1]])));
                record.weight = Some(i64::from(u16::from_be_bytes([raw[2], raw[3]])));
                record.port = Some(i64::from(u16::from_be_bytes([raw[4], raw[5]])));
                Str::from(domain::decode(data, start + 6)?.0)
            }
            Record::TYPE_SOA => {
                let (mname, at) = domain::decode(data, start)?;
                let (rname, at) = domain::decode(data, at)?;
                let Some(timing) = data.get(at..at + 20) else {
                    return Err(Error::decoding("Invalid SOA record length"));
                };
                let field = |i: usize| u32::from_be_bytes([timing[i], timing[i + 1], timing[i + 2], timing[i + 3]]);
                let mut text = Vec::with_capacity(mname.len() + rname.len() + 60);
                text.extend_from_slice(&mname);
                text.push(b' ');
                text.extend_from_slice(&rname);
                for i in 0..5 {
                    text.push(b' ');
                    text.extend_from_slice(field(i * 4).to_string().as_bytes());
                }
                Str::from(text)
            }
            Record::TYPE_TXT => {
                if raw.is_empty() {
                    return Err(Error::decoding("Invalid TXT RDATA length: 0"));
                }
                let mut text = Vec::with_capacity(raw.len());
                let mut pos = 0;
                while pos < raw.len() {
                    let len = usize::from(raw[pos]);
                    if pos + 1 + len > raw.len() {
                        return Err(Error::decoding("TXT chunk length exceeds RDATA size"));
                    }
                    text.extend_from_slice(&raw[pos + 1..pos + 1 + len]);
                    pos += len + 1;
                }
                Str::from(text)
            }
            Record::TYPE_CAA => {
                if raw.len() < 2 {
                    return Err(Error::decoding("Invalid CAA record length"));
                }
                let tag_length = usize::from(raw[1]);
                if tag_length > raw.len() - 2 {
                    return Err(Error::decoding("Invalid CAA tag length"));
                }
                let mut text = raw[0].to_string().into_bytes();
                text.push(b' ');
                text.extend_from_slice(&raw[2..2 + tag_length]);
                text.extend_from_slice(b" \"");
                text.extend_from_slice(&raw[2 + tag_length..]);
                text.push(b'"');
                Str::from(text)
            }
            _ => Str::from(php_std::encoding::bin2hex(raw)),
        };
        Ok((record, end))
    }

    /// The record in wire format.
    ///
    /// Fails with [`Error::InvalidArgument`] when the name or the data
    /// cannot be encoded.
    ///
    /// PHP: `encode()`.
    pub fn encode(&self) -> Result<Vec<u8>, Error> {
        let mut out = Vec::with_capacity(self.name.len() + self.rdata.len() + 16);
        self.encode_into(&mut out)?;
        Ok(out)
    }

    pub(crate) fn encode_into(&self, out: &mut Vec<u8>) -> Result<(), Error> {
        let start = out.len();
        domain::encode_into(&self.name, out)?;
        out.extend_from_slice(&self.ty.to_be_bytes());
        out.extend_from_slice(&self.class.to_be_bytes());
        out.extend_from_slice(&(self.ttl as u32).to_be_bytes());
        let length_at = out.len();
        out.extend_from_slice(&[0, 0]);
        if let Err(e) = self.rdata_into(out) {
            out.truncate(start);
            return Err(e);
        }
        let length = out.len() - length_at - 2;
        out[length_at..length_at + 2].copy_from_slice(&(length as u16).to_be_bytes());
        Ok(())
    }

    /// Checks the data the way encoding it would.
    ///
    /// PHP: `validateRdata()`.
    pub fn validate(&self) -> Result<(), Error> {
        self.rdata_into(&mut Vec::new())
    }

    fn rdata_into(&self, out: &mut Vec<u8>) -> Result<(), Error> {
        let rdata: &[u8] = &self.rdata;
        match self.ty {
            Record::TYPE_A => match php_std::net::inet_pton(rdata)? {
                Some(php_std::net::PackedAddr::V4(b)) => out.extend_from_slice(&b),
                _ => return Err(Error::InvalidArgument(quote("Invalid IPv4 address: ", rdata))),
            },
            Record::TYPE_AAAA => match php_std::net::inet_pton(rdata)? {
                Some(php_std::net::PackedAddr::V6(b)) => out.extend_from_slice(&b),
                _ => return Err(Error::InvalidArgument(quote("Invalid IPv6 address: ", rdata))),
            },
            Record::TYPE_CNAME | Record::TYPE_NS | Record::TYPE_PTR => domain::encode_into(rdata, out)?,
            Record::TYPE_MX => {
                let priority = self.priority.unwrap_or(0);
                check("MX priority", priority, MAX_U16)?;
                out.extend_from_slice(&(priority as u16).to_be_bytes());
                domain::encode_into(rdata, out)?;
            }
            Record::TYPE_SRV => {
                let (priority, weight, port) =
                    (self.priority.unwrap_or(0), self.weight.unwrap_or(0), self.port.unwrap_or(0));
                check("SRV priority", priority, MAX_U16)?;
                check("SRV weight", weight, MAX_U16)?;
                check("SRV port", port, MAX_U16)?;
                for n in [priority, weight, port] {
                    out.extend_from_slice(&(n as u16).to_be_bytes());
                }
                domain::encode_into(rdata, out)?;
            }
            Record::TYPE_TXT => {
                if rdata.is_empty() {
                    out.push(0);
                }
                for chunk in rdata.chunks(MAX_TXT_CHUNK) {
                    out.push(chunk.len() as u8);
                    out.extend_from_slice(chunk);
                }
            }
            Record::TYPE_CAA => caa_into(rdata, out)?,
            Record::TYPE_SOA => soa_into(rdata, out)?,
            ty => match php_std::encoding::hex2bin(rdata) {
                Some(binary) => out.extend_from_slice(&binary),
                None => {
                    return Err(Error::invalid(format!("Invalid hexadecimal payload for record type {ty}")));
                }
            },
        }
        Ok(())
    }
}

fn check(field: &str, value: i64, max: i64) -> Result<(), Error> {
    if (0..=max).contains(&value) {
        return Ok(());
    }
    Err(Error::invalid(format!("{field} must be between 0 and {max}, got {value}")))
}

/// PCRE's `\s` without `/u`.
fn is_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')
}

/// `MNAME RNAME SERIAL REFRESH RETRY EXPIRE MINIMUM`, parentheses ignored;
/// an RNAME with `@` is a mailbox whose local part is one label.
fn soa_into(rdata: &[u8], out: &mut Vec<u8>) -> Result<(), Error> {
    let input = php_std::string::trim(rdata, php_std::string::TRIM_CHARACTERS);
    if input.is_empty() {
        return Err(Error::invalid("SOA RDATA cannot be empty"));
    }
    let parts: Vec<&[u8]> = input
        .split(|&b| is_space(b))
        .map(|t| php_std::string::trim(t, php_std::string::TRIM_CHARACTERS))
        .filter(|t| !t.is_empty() && *t != b"(" && *t != b")")
        .collect();
    if parts.len() != 7 {
        return Err(Error::invalid(
            "SOA RDATA must contain MNAME, RNAME, SERIAL, REFRESH, RETRY, EXPIRE and MINIMUM fields",
        ));
    }
    let mut numbers = [0u32; 5];
    for (slot, value) in numbers.iter_mut().zip(&parts[2..]) {
        if !value.iter().all(u8::is_ascii_digit) {
            return Err(Error::invalid("SOA timing fields must be unsigned integers"));
        }
        let number = php_std::format::str_to_int(value);
        if !(0..=0xFFFF_FFFF).contains(&number) {
            return Err(Error::InvalidArgument(quote("SOA timing field out of range: ", value)));
        }
        *slot = number as u32;
    }
    let start = out.len();
    domain::encode_into(parts[0], out)?;
    if let Err(e) = rname_into(parts[1], out) {
        out.truncate(start);
        return Err(e);
    }
    for n in numbers {
        out.extend_from_slice(&n.to_be_bytes());
    }
    Ok(())
}

fn rname_into(rname: &[u8], out: &mut Vec<u8>) -> Result<(), Error> {
    let ats = rname.iter().filter(|&&b| b == b'@').count();
    if ats == 0 {
        return domain::encode_into(rname, out);
    }
    if ats > 1 {
        return Err(Error::invalid("SOA RNAME email must contain exactly one @ separator"));
    }
    let at = rname.iter().position(|&b| b == b'@').unwrap_or(0);
    let (local, domain_part) = (&rname[..at], &rname[at + 1..]);
    if local.is_empty() || domain_part.is_empty() {
        return Err(Error::invalid("SOA RNAME email must have non-empty local part and domain"));
    }
    if local.len() > domain::MAX_LABEL_LEN {
        return Err(Error::InvalidArgument(quote("Label too long: ", local)));
    }
    let start = out.len();
    out.push(local.len() as u8);
    out.extend_from_slice(local);
    domain::encode_into(domain_part, out)?;
    if out.len() - start > domain::MAX_DOMAIN_NAME_LEN {
        out.truncate(start);
        return Err(Error::invalid(format!(
            "Encoded domain exceeds maximum length of {} bytes",
            domain::MAX_DOMAIN_NAME_LEN
        )));
    }
    Ok(())
}

/// `[flags] tag "value"`: the match of
/// `/^(?:(\d+)\s+)?([A-Za-z0-9-]+)\s+"((?:\\.|[^"])*)"$/` on the trimmed data.
fn caa_into(rdata: &[u8], out: &mut Vec<u8>) -> Result<(), Error> {
    let input = php_std::string::trim(rdata, php_std::string::TRIM_CHARACTERS);
    if input.is_empty() {
        return Err(Error::invalid("CAA RDATA cannot be empty"));
    }
    let (flags, tag, value) =
        caa_parts(input).ok_or_else(|| Error::InvalidArgument(quote("Invalid CAA RDATA format: ", rdata)))?;
    let flags = php_std::format::str_to_int(flags);
    if !(0..=MAX_CAA_FLAGS).contains(&flags) {
        return Err(Error::invalid(format!("CAA flags must be between 0 and {MAX_CAA_FLAGS}, got {flags}")));
    }
    if tag.len() > 255 {
        return Err(Error::invalid("CAA tag exceeds 255 bytes"));
    }
    out.push(flags as u8);
    out.push(tag.len() as u8);
    out.extend_from_slice(tag);
    out.extend_from_slice(&php_std::string::stripcslashes(value));
    Ok(())
}

/// Flags (`""` when absent), tag and the raw value of a CAA presentation.
fn caa_parts(input: &[u8]) -> Option<(&[u8], &[u8], &[u8])> {
    let digits = input.iter().take_while(|b| b.is_ascii_digit()).count();
    if digits > 0 {
        let spaces = input[digits..].iter().take_while(|&&b| is_space(b)).count();
        if spaces > 0
            && let Some((tag, value)) = caa_tail(&input[digits + spaces..])
        {
            return Some((&input[..digits], tag, value));
        }
    }
    caa_tail(input).map(|(tag, value)| (&b""[..], tag, value))
}

/// `([A-Za-z0-9-]+)\s+"((?:\\.|[^"])*)"$`.
fn caa_tail(input: &[u8]) -> Option<(&[u8], &[u8])> {
    let tag_len = input.iter().take_while(|b| b.is_ascii_alphanumeric() || **b == b'-').count();
    if tag_len == 0 {
        return None;
    }
    let spaces = input[tag_len..].iter().take_while(|&&b| is_space(b)).count();
    if spaces == 0 {
        return None;
    }
    let rest = &input[tag_len + spaces..];
    let inner = rest.strip_prefix(b"\"")?;
    // `$` also matches before a final newline.
    let inner = inner.strip_suffix(b"\"").or_else(|| inner.strip_suffix(b"\"\n"))?;
    // A quote inside the value must be escaped: `\"` (any earlier backslash
    // can stand alone, so the one before the quote can always escape it).
    for (i, &b) in inner.iter().enumerate() {
        if b == b'"' && (i == 0 || inner[i - 1] != b'\\') {
            return None;
        }
    }
    Some((&input[..tag_len], inner))
}
