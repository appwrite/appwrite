//! PHP's `password_hash()` / `password_verify()` for the algorithms the
//! hashes use (bcrypt and Argon2id), with ext/standard/password.c's option
//! handling and error messages, and the blowfish branch of `crypt()`.

use argon2::{Algorithm, Argon2, Params, Version};
use php_std::encoding::base64_encode;

use crate::Error;
use crate::hash::{hash_equals, random_bytes};
use crate::options::Options;

/// crypt_blowfish's base64 alphabet.
pub(crate) const BF_ITOA64: &[u8; 64] = b"./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";

fn bf_atoi(c: u8) -> Option<u32> {
    BF_ITOA64.iter().position(|x| *x == c).map(|p| p as u32)
}

/// `BF_encode`: crypt_blowfish base64 of `src`.
pub(crate) fn bf_encode(src: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(src.len() * 4 / 3 + 2);
    let mut i = 0;
    while i < src.len() {
        let c1 = src[i] as u32;
        i += 1;
        out.push(BF_ITOA64[(c1 >> 2) as usize]);
        let mut c1 = (c1 & 0x03) << 4;
        if i >= src.len() {
            out.push(BF_ITOA64[c1 as usize]);
            break;
        }
        let c2 = src[i] as u32;
        i += 1;
        c1 |= c2 >> 4;
        out.push(BF_ITOA64[c1 as usize]);
        let mut c1 = (c2 & 0x0f) << 2;
        if i >= src.len() {
            out.push(BF_ITOA64[c1 as usize]);
            break;
        }
        let c2 = src[i] as u32;
        i += 1;
        c1 |= c2 >> 6;
        out.push(BF_ITOA64[c1 as usize]);
        out.push(BF_ITOA64[(c2 & 0x3f) as usize]);
    }
    out
}

/// `BF_decode`: 16 salt bytes from 22 characters (the last one's low bits are ignored).
fn bf_decode_salt(src: &[u8]) -> Option<[u8; 16]> {
    let mut out = [0u8; 16];
    let mut n = 0;
    let mut i = 0;
    let get = |i: usize| src.get(i).copied().and_then(bf_atoi);
    while n < 16 {
        let c1 = get(i)?;
        let c2 = get(i + 1)?;
        out[n] = ((c1 << 2) | ((c2 & 0x30) >> 4)) as u8;
        n += 1;
        if n >= 16 {
            break;
        }
        let c3 = get(i + 2)?;
        out[n] = (((c2 & 0x0f) << 4) | ((c3 & 0x3c) >> 2)) as u8;
        n += 1;
        let c4 = get(i + 3)?;
        out[n] = (((c3 & 0x03) << 6) | c4) as u8;
        n += 1;
        i += 4;
    }
    Some(out)
}

/// The blowfish branch of `crypt($password, $setting)` (`$2a$`, `$2b$`, `$2y$`):
/// `None` where crypt_blowfish fails.
pub(crate) fn crypt_blowfish(password: &[u8], setting: &[u8]) -> Option<Vec<u8>> {
    if setting.len() < 29
        || setting[0] != b'$'
        || setting[1] != b'2'
        || !matches!(setting[2], b'a' | b'b' | b'y' | b'x')
        || setting[3] != b'$'
        || !(b'0'..=b'3').contains(&setting[4])
        || !setting[5].is_ascii_digit()
        || (setting[4] == b'3' && setting[5] > b'1')
        || setting[6] != b'$'
    {
        return None;
    }
    let cost = u32::from(setting[4] - b'0') * 10 + u32::from(setting[5] - b'0');
    if cost < 4 {
        return None;
    }
    let salt = bf_decode_salt(&setting[7..29])?;
    // `$2x$` reproduces the sign extension bug of old crypt_blowfish for
    // 8-bit characters; for ASCII passwords it equals the correct algorithm.
    if setting[2] == b'x' && password.iter().any(|b| *b >= 0x80) {
        return None;
    }
    // The password is a C string: it ends at the first NUL.
    let password = password.split(|b| *b == 0).next().unwrap_or_default();
    let mut key = Vec::with_capacity(password.len() + 1);
    key.extend_from_slice(password);
    key.push(0);
    key.truncate(72);
    let raw = bcrypt::bcrypt(cost, salt, &key);
    let mut out = setting[..28].to_vec();
    // The last salt character is canonicalised (its low 4 bits dropped).
    let last = bf_atoi(setting[28])?;
    out.push(BF_ITOA64[(last & 0x30) as usize]);
    out.extend_from_slice(&bf_encode(&raw[..23]));
    Some(out)
}

/// `crypt($password, $salt)`: the hash, or `*0` / `*1` where it fails.
///
/// Only the blowfish formats are computed; every other setting fails like
/// an unsupported one.
pub(crate) fn crypt(password: &[u8], salt: &[u8]) -> Vec<u8> {
    match crypt_blowfish(password, salt) {
        Some(hash) => hash,
        None if salt.starts_with(b"*0") => b"*1".to_vec(),
        None => b"*0".to_vec(),
    }
}

/// `password_hash($value, PASSWORD_BCRYPT, $options)`.
pub(crate) fn hash_bcrypt(value: &[u8], options: &Options) -> Result<Vec<u8>, Error> {
    let cost = options.long_or("cost", 12);
    if !(4..=31).contains(&cost) {
        return Err(Error::Value(format!("Invalid bcrypt cost parameter specified: {cost}")));
    }
    if value.contains(&0) {
        return Err(Error::Value("Bcrypt password must not contain null character".into()));
    }
    let mut salt = [0u8; 16];
    random_bytes(&mut salt);
    let mut setting = format!("$2y${cost:02}$").into_bytes();
    setting.extend_from_slice(&bf_encode(&salt)[..22]);
    crypt_blowfish(value, &setting).ok_or_else(|| Error::Value("Invalid bcrypt cost parameter specified".into()))
}

const ARGON2_MAX: i64 = 0xFFFF_FFFF;
/// Largest memory cost (KiB) this port will allocate when verifying; PHP's
/// allocation fails somewhere beyond it, which verification also reports as false.
const ARGON2_VERIFY_MEMORY_LIMIT: u32 = 1 << 22;

/// `password_hash($value, PASSWORD_ARGON2ID, $options)`.
pub(crate) fn hash_argon2id(value: &[u8], options: &Options) -> Result<Vec<u8>, Error> {
    let memory = options.long_or("memory_cost", 65536);
    if !(8..=ARGON2_MAX).contains(&memory) {
        return Err(Error::Value("Memory cost is outside of allowed memory range".into()));
    }
    let time = options.long_or("time_cost", 4);
    if !(1..=ARGON2_MAX).contains(&time) {
        return Err(Error::Value("Time cost is outside of allowed time range".into()));
    }
    let threads = options.long_or("threads", 1);
    if !(1..=0xFF_FFFF).contains(&threads) {
        return Err(Error::Value("Invalid number of threads".into()));
    }
    let (memory, time, threads) = (memory as u32, time as u32, threads as u32);
    if memory < 8 * threads {
        return Err(Error::Value("Memory cost is too small".into()));
    }
    let mut salt = [0u8; 16];
    random_bytes(&mut salt);
    let params = Params::new(memory, time, threads, Some(32)).map_err(|e| Error::Value(e.to_string()))?;
    let mut out = [0u8; 32];
    Argon2::new(Algorithm::Argon2id, Version::V0x13, params)
        .hash_password_into(value, &salt, &mut out)
        .map_err(|_| Error::Value("Memory allocation error".into()))?;
    let b64 = |b: &[u8]| base64_encode(b).trim_end_matches('=').to_owned();
    Ok(format!("$argon2id$v=19$m={memory},t={time},p={threads}${}${}", b64(&salt), b64(&out)).into_bytes())
}

/// `password_verify($value, $hash)`.
pub(crate) fn verify(value: &[u8], hash: &[u8]) -> bool {
    match ident(hash) {
        Some(b"argon2i") => verify_argon2(value, hash, Algorithm::Argon2i, b"argon2i"),
        Some(b"argon2id") => verify_argon2(value, hash, Algorithm::Argon2id, b"argon2id"),
        _ => {
            if hash.len() < 13 {
                return false;
            }
            match crypt_blowfish(value, hash) {
                Some(computed) => hash_equals(&computed, hash),
                None => false,
            }
        }
    }
}

/// `php_password_algo_extract_ident`: between the first byte and the next `$`.
fn ident(hash: &[u8]) -> Option<&[u8]> {
    if hash.len() < 3 {
        return None;
    }
    let rest = &hash[1..];
    let end = rest.iter().position(|b| *b == b'$')?;
    Some(&rest[..end])
}

/// libargon2's `decode_decimal`: digits without leading zeros.
fn decimal(s: &[u8]) -> Option<(u64, &[u8])> {
    let len = s.iter().take_while(|b| b.is_ascii_digit()).count();
    if len == 0 || (s[0] == b'0' && len > 1) {
        return None;
    }
    let mut acc: u64 = 0;
    for d in &s[..len] {
        acc = acc.checked_mul(10)?.checked_add(u64::from(d - b'0'))?;
    }
    Some((acc, &s[len..]))
}

fn u32_field<'a>(s: &'a [u8], prefix: &[u8]) -> Option<(u32, &'a [u8])> {
    let s = s.strip_prefix(prefix)?;
    let (v, rest) = decimal(s)?;
    Some((u32::try_from(v).ok()?, rest))
}

/// libargon2's `from_base64`: standard alphabet, no padding, zero trailing bits.
fn from_base64(s: &[u8]) -> Option<(Vec<u8>, &[u8])> {
    let val = |c: u8| -> Option<u32> {
        Some(match c {
            b'A'..=b'Z' => u32::from(c - b'A'),
            b'a'..=b'z' => u32::from(c - b'a') + 26,
            b'0'..=b'9' => u32::from(c - b'0') + 52,
            b'+' => 62,
            b'/' => 63,
            _ => return None,
        })
    };
    let (mut acc, mut bits, mut out, mut i) = (0u32, 0u32, Vec::new(), 0);
    while let Some(d) = s.get(i).copied().and_then(val) {
        i += 1;
        acc = (acc << 6) | d;
        bits += 6;
        if bits >= 8 {
            bits -= 8;
            out.push((acc >> bits) as u8);
        }
        acc &= (1 << bits) - 1;
    }
    if bits > 4 || acc != 0 {
        return None;
    }
    Some((out, &s[i..]))
}

/// A decoded Argon2 hash: version, memory, time, lanes, salt and output.
type Encoded = (u32, u32, u32, u32, Vec<u8>, Vec<u8>);

fn verify_argon2(value: &[u8], hash: &[u8], algorithm: Algorithm, name: &[u8]) -> bool {
    let parse = || -> Option<Encoded> {
        let s = hash.strip_prefix(b"$")?.strip_prefix(name)?;
        let (version, s) = match u32_field(s, b"$v=") {
            Some((v, rest)) => (v, rest),
            None if s.starts_with(b"$v=") => return None,
            None => (0x10, s),
        };
        let (m, s) = u32_field(s, b"$m=")?;
        let (t, s) = u32_field(s, b",t=")?;
        let (p, s) = u32_field(s, b",p=")?;
        let (salt, s) = from_base64(s.strip_prefix(b"$")?)?;
        let (out, s) = from_base64(s.strip_prefix(b"$")?)?;
        s.is_empty().then_some((version, m, t, p, salt, out))
    };
    let Some((version, m, t, p, salt, expected)) = parse() else {
        return false;
    };
    let version = match version {
        0x10 => Version::V0x10,
        0x13 => Version::V0x13,
        _ => return false,
    };
    if expected.len() < 4 || salt.len() < 8 || m > ARGON2_VERIFY_MEMORY_LIMIT {
        return false;
    }
    let Ok(params) = Params::new(m, t, p, Some(expected.len())) else {
        return false;
    };
    let mut out = vec![0u8; expected.len()];
    if Argon2::new(algorithm, version, params).hash_password_into(value, &salt, &mut out).is_err() {
        return false;
    }
    hash_equals(&out, &expected)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn php_fixtures() {
        assert!(verify(b"appwrite", b"$2a$15$xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxBLXmOPHrYMwV0mHRBBSBOq"));
        assert!(verify(
            b"appwrite",
            b"$argon2i$v=19$m=20,t=3,p=2$YXBwd3JpdGU$A/54i238ed09ZR4NwlACU5XnkjNBZU9QeOEuhjLiexI"
        ));
        assert!(!verify(
            b"appwrite",
            b"$argon2d$v=19$m=20,t=3,p=2$YXBwd3JpdGU$A/54i238ed09ZR4NwlACU5XnkjNBZU9QeOEuhjLiexI"
        ));
        assert_eq!(
            crypt_blowfish(b"x", b"$2a$04$abcdefghijklmnopqrstuu").unwrap(),
            b"$2a$04$abcdefghijklmnopqrstuuPp7HPfoAs8I2dCQCQ/fW7zEJv8I8C8e".to_vec()
        );
    }
}
