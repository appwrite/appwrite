//! Network helpers: `ip2long`/`long2ip`, `inet_pton`/`inet_ntop` and
//! `idn_to_ascii`/`idn_to_utf8`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `ip2long($ip)` | [`ip2long`] |
//! | `long2ip($ip)` | [`long2ip`] |
//! | `inet_pton($ip)` | [`inet_pton`] |
//! | `inet_ntop($ip)` | [`inet_ntop`] |
//! | `idn_to_ascii($domain, $flags, INTL_IDNA_VARIANT_UTS46)` | [`idn::to_ascii`] |
//! | `idn_to_utf8($domain, $flags, INTL_IDNA_VARIANT_UTS46)` | [`idn::to_utf8`] |
//!
//! PHP's address functions call the C library; the dev image is Alpine, so
//! these are ports of musl's `inet_pton`/`inet_ntop` (`src/network/`), whose
//! IPv6 formatting differs from glibc's in which zero run it compresses.
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `net.*`).

use std::fmt;

pub mod idn;

/// PHP's `ValueError` for a string argument that must not contain NUL bytes
/// (`Z_PARAM_PATH`): `"<function>(): Argument #1 ($ip) must not contain any
/// null bytes"`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct NulByteError {
    function: &'static str,
}

impl NulByteError {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        "ValueError"
    }
}

impl fmt::Display for NulByteError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}(): Argument #1 ($ip) must not contain any null bytes", self.function)
    }
}

impl std::error::Error for NulByteError {}

fn check_nul(function: &'static str, ip: &[u8]) -> Result<(), NulByteError> {
    if ip.contains(&0) { Err(NulByteError { function }) } else { Ok(()) }
}

/// PHP `ip2long($ip)`: the address as an unsigned 32-bit integer, `None`
/// where PHP returns `false`. Only strict dotted quads parse (musl
/// `inet_pton(AF_INET)`: four decimal octets of up to three digits, no
/// leading zeros, no surrounding space).
pub fn ip2long(ip: &[u8]) -> Result<Option<u32>, NulByteError> {
    check_nul("ip2long", ip)?;
    if ip.is_empty() {
        return Ok(None);
    }
    Ok(pton4(ip).map(u32::from_be_bytes))
}

/// PHP `long2ip($ip)`: the low 32 bits of `ip` as a dotted quad.
pub fn long2ip(ip: i64) -> String {
    let b = (ip as u32).to_be_bytes();
    format!("{}.{}.{}.{}", b[0], b[1], b[2], b[3])
}

/// A packed address from [`inet_pton`].
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PackedAddr {
    V4([u8; 4]),
    V6([u8; 16]),
}

impl PackedAddr {
    pub fn as_bytes(&self) -> &[u8] {
        match self {
            PackedAddr::V4(b) => b,
            PackedAddr::V6(b) => b,
        }
    }
}

/// PHP `inet_pton($ip)`: IPv6 when the string contains `:`, IPv4 when it
/// contains `.`, `None` (PHP's `false`) otherwise or when invalid.
pub fn inet_pton(ip: &[u8]) -> Result<Option<PackedAddr>, NulByteError> {
    check_nul("inet_pton", ip)?;
    Ok(if ip.contains(&b':') {
        pton6(ip).map(PackedAddr::V6)
    } else if ip.contains(&b'.') {
        pton4(ip).map(PackedAddr::V4)
    } else {
        None
    })
}

/// PHP `inet_ntop($ip)`: a 4-byte (IPv4) or 16-byte (IPv6) packed address
/// in text form; `None` (PHP's `false`) for any other length.
pub fn inet_ntop(packed: &[u8]) -> Option<String> {
    match packed.len() {
        4 => Some(format!("{}.{}.{}.{}", packed[0], packed[1], packed[2], packed[3])),
        16 => Some(ntop6(packed)),
        _ => None,
    }
}

/// musl `inet_pton(AF_INET)`. The input never contains NUL here.
fn pton4(s: &[u8]) -> Option<[u8; 4]> {
    let mut out = [0u8; 4];
    let mut s = s;
    for (i, slot) in out.iter_mut().enumerate() {
        let mut v: u32 = 0;
        let mut j = 0;
        while j < 3 && j < s.len() && s[j].is_ascii_digit() {
            v = 10 * v + u32::from(s[j] - b'0');
            j += 1;
        }
        if j == 0 || (j > 1 && s[0] == b'0') || v > 255 {
            return None;
        }
        *slot = v as u8;
        if j == s.len() && i == 3 {
            return Some(out);
        }
        if j >= s.len() || s[j] != b'.' {
            return None;
        }
        s = &s[j + 1..];
    }
    None
}

fn hexval(c: u8) -> Option<u16> {
    match c {
        b'0'..=b'9' => Some(u16::from(c - b'0')),
        b'a'..=b'f' => Some(u16::from(c - b'a' + 10)),
        b'A'..=b'F' => Some(u16::from(c - b'A' + 10)),
        _ => None,
    }
}

/// musl `inet_pton(AF_INET6)`. Reading past the end yields NUL, as in C.
fn pton6(input: &[u8]) -> Option<[u8; 16]> {
    let at = |i: usize| input.get(i).copied().unwrap_or(0);
    let mut ip = [0u16; 8];
    let mut s = 0usize;
    let mut brk: Option<usize> = None;
    let mut need_v4 = false;

    if at(s) == b':' {
        s += 1;
        if at(s) != b':' {
            return None;
        }
    }

    let mut i = 0usize;
    loop {
        if at(s) == b':' && brk.is_none() {
            brk = Some(i);
            ip[i & 7] = 0;
            s += 1;
            if at(s) == 0 {
                break;
            }
            if i == 7 {
                return None;
            }
            i += 1;
            continue;
        }
        let mut v: u16 = 0;
        let mut j = 0;
        while j < 4 {
            match hexval(at(s + j)) {
                Some(d) => v = 16 * v + d,
                None => break,
            }
            j += 1;
        }
        if j == 0 {
            return None;
        }
        ip[i & 7] = v;
        if at(s + j) == 0 && (brk.is_some() || i == 7) {
            break;
        }
        if i == 7 {
            return None;
        }
        if at(s + j) != b':' {
            if at(s + j) != b'.' || (i < 6 && brk.is_none()) {
                return None;
            }
            need_v4 = true;
            i += 1;
            ip[i & 7] = 0;
            break;
        }
        s += j + 1;
        i += 1;
    }
    if let Some(brk) = brk {
        // memmove(ip+brk+7-i, ip+brk, 2*(i+1-brk)); zero the gap.
        let count = i + 1 - brk;
        let dst = brk + 7 - i;
        let moved: Vec<u16> = ip[brk..brk + count].to_vec();
        ip[dst..dst + count].copy_from_slice(&moved);
        for slot in ip.iter_mut().skip(brk).take(7 - i) {
            *slot = 0;
        }
    }
    let mut out = [0u8; 16];
    for (j, v) in ip.iter().enumerate() {
        out[2 * j] = (v >> 8) as u8;
        out[2 * j + 1] = *v as u8;
    }
    if need_v4 {
        let v4 = pton4(&input[s..])?;
        out[12..].copy_from_slice(&v4);
    }
    Some(out)
}

/// musl `inet_ntop(AF_INET6)`: prints every group, then replaces the longest
/// `(^0|:)[:0]{2,}` run with `::` (a leading run needs one more character to
/// lose to a later one).
fn ntop6(a: &[u8]) -> String {
    let w = |i: usize| (u32::from(a[2 * i]) << 8) | u32::from(a[2 * i + 1]);
    let mut buf = if a[..12] != [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0xff, 0xff] {
        format!("{:x}:{:x}:{:x}:{:x}:{:x}:{:x}:{:x}:{:x}", w(0), w(1), w(2), w(3), w(4), w(5), w(6), w(7))
    } else {
        format!(
            "{:x}:{:x}:{:x}:{:x}:{:x}:{:x}:{}.{}.{}.{}",
            w(0),
            w(1),
            w(2),
            w(3),
            w(4),
            w(5),
            a[12],
            a[13],
            a[14],
            a[15]
        )
    }
    .into_bytes();
    let (mut best, mut max) = (0usize, 2usize);
    for i in 0..buf.len() {
        if i != 0 && buf[i] != b':' {
            continue;
        }
        let j = buf[i..].iter().take_while(|&&c| c == b':' || c == b'0').count();
        if j > max + usize::from(best == 0) {
            best = i;
            max = j;
        }
    }
    if max > 3 {
        buf[best] = b':';
        buf[best + 1] = b':';
        buf.drain(best + 2..best + max);
    }
    String::from_utf8(buf).unwrap_or_default()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ipv4() {
        assert_eq!(ip2long(b"1.2.3.4"), Ok(Some(16909060)));
        assert_eq!(ip2long(b"01.2.3.4"), Ok(None));
        assert_eq!(ip2long(b"1.2.3"), Ok(None));
        assert!(ip2long(b"1.2.3.4\0").is_err());
        assert_eq!(long2ip(-1), "255.255.255.255");
        assert_eq!(long2ip(4294967296), "0.0.0.0");
    }

    #[test]
    fn ipv6() {
        let rt = |s: &str| inet_ntop(inet_pton(s.as_bytes()).unwrap().unwrap().as_bytes()).unwrap();
        assert_eq!(rt("::1"), "::1");
        assert_eq!(rt("0:0:1:0:0:1:0:0"), "::1:0:0:1:0:0");
        assert_eq!(rt("1:0:0:0:1:0:0:0"), "1::1:0:0:0");
        assert_eq!(rt("::1.2.3.4"), "::102:304");
        assert_eq!(rt("::ffff:0:0"), "::ffff:0.0.0.0");
        assert_eq!(rt("0:0:1::"), "0:0:1::");
        assert_eq!(inet_pton(b"1:::2"), Ok(None));
        assert_eq!(inet_ntop(b"abc"), None);
    }
}
