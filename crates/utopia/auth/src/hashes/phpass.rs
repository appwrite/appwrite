use md5::{Digest, Md5};
use php_std::format::{dval_to_lval, str_to_float};
use php_std::value::{Number, numeric_str_ex};
use php_std::zval::Zval;

use super::{hash_plumbing, password};
use crate::hash::{hash_equals, random_bytes};
use crate::options::{to_long, truthy, type_name};
use crate::{Error, Hash, Options};

const ITOA64: &[u8; 64] = b"./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";

/// The Portable PHP password hashing framework, as WordPress and phpBB use
/// it (`Utopia\Auth\Hashes\PHPass`): `$2a$` blowfish hashes, or `$P$`
/// portable MD5 hashes when `portable_hashes` is set (or blowfish fails).
///
/// Options: `type`, `iteration_count_log2`, `portable_hashes`, `random_state`.
#[derive(Debug, Clone, PartialEq)]
pub struct PHPass {
    options: Options,
}

impl Default for PHPass {
    fn default() -> Self {
        Self::new()
    }
}

impl PHPass {
    /// `new PHPass()`: 2^8 iterations, blowfish, `random_state` from `microtime() . getmypid()`.
    pub fn new() -> Self {
        let mut options = Options::new();
        options.set("type", "phpass");
        options.set("iteration_count_log2", 8);
        options.set("portable_hashes", false);
        options.set("random_state", format!("{}{}", php_std::datetime::microtime(), php_std::system::getmypid()));
        Self { options }
    }

    /// `setIterationCount()`: log2 of the iterations, between 4 and 31.
    pub fn set_iteration_count(&mut self, count: i64) -> Result<&mut Self, Error> {
        if !(4..=31).contains(&count) {
            return Err(Error::InvalidArgument("Iteration count must be between 4 and 31".into()));
        }
        self.options.set("iteration_count_log2", count);
        Ok(self)
    }

    /// `setPortableHashes()`.
    pub fn set_portable_hashes(&mut self, portable: bool) -> &mut Self {
        self.options.set("portable_hashes", portable);
        self
    }

    /// `iteration_count_log2` as PHP arithmetic sees it.
    fn count(&self, op: &str) -> Result<Number, Error> {
        let value = self.options.raw("iteration_count_log2").unwrap_or(&Zval::Null);
        operand(value, op)
    }

    /// `gensaltBlowfish()`.
    fn gensalt_blowfish(&self, input: &[u8; 16]) -> Result<Vec<u8>, Error> {
        let count = self.count("/")?;
        let tens = match count {
            Number::Int(i) => i / 10,
            Number::Float(f) => dval_to_lval(f / 10.0),
        };
        let units = match count {
            Number::Int(i) => i % 10,
            Number::Float(f) => {
                let i = dval_to_lval(f);
                i % 10
            }
        };
        let mut out = b"$2a$".to_vec();
        out.push((i64::from(b'0') + tens).rem_euclid(256) as u8);
        out.push((i64::from(b'0') + units).rem_euclid(256) as u8);
        out.push(b'$');
        out.extend_from_slice(&password::bf_encode(input)[..22]);
        Ok(out)
    }

    /// `gensaltPrivate()`.
    fn gensalt_private(&self, input: &[u8]) -> Result<Vec<u8>, Error> {
        let index = match self.count("+")? {
            Number::Int(i) => i.saturating_add(5).min(30),
            Number::Float(f) => dval_to_lval((f + 5.0).min(30.0)),
        };
        let mut out = b"$P$".to_vec();
        let at = if index < 0 { 64 + index } else { index };
        if (0..64).contains(&at) {
            out.push(ITOA64[at as usize]);
        }
        out.extend_from_slice(&encode64(input, 6));
        Ok(out)
    }
}

/// An operand of PHP arithmetic: numbers as they are, numeric strings as
/// their number, `null`/`bool` as `0`/`1`; anything else is a `TypeError`.
fn operand(value: &Zval, op: &str) -> Result<Number, Error> {
    let unsupported = || Error::Type(format!("Unsupported operand types: {} {op} int", type_name(value)));
    Ok(match value {
        Zval::Int(i) => Number::Int(*i),
        Zval::Float(f) => Number::Float(*f),
        Zval::Null | Zval::Bool(_) => Number::Int(to_long(value)),
        Zval::String(s) => match std::str::from_utf8(s).ok().and_then(numeric_str_ex) {
            Some((n, _)) => n,
            None => {
                // A leading-numeric string ("9abc") is used with a warning.
                let digits = s.iter().take_while(|b| b.is_ascii_whitespace()).count();
                let rest = &s[digits..];
                let rest = rest.strip_prefix(b"-").or_else(|| rest.strip_prefix(b"+")).unwrap_or(rest);
                if rest.first().is_some_and(|b| b.is_ascii_digit() || *b == b'.') {
                    let f = str_to_float(s);
                    if f.fract() == 0.0 && f.abs() < 9.2e18 && !s.contains(&b'.') && !s.iter().any(|b| *b == b'e' || *b == b'E') {
                        Number::Int(f as i64)
                    } else {
                        Number::Float(f)
                    }
                } else {
                    return Err(unsupported());
                }
            }
        },
        Zval::Array(_) | Zval::Object(_) => return Err(unsupported()),
    })
}

/// `encode64()`: phpass's base64 of the first `count` bytes.
fn encode64(input: &[u8], count: usize) -> Vec<u8> {
    let at = |i: usize| u32::from(input.get(i).copied().unwrap_or(0));
    let mut out = Vec::new();
    let mut i = 0;
    loop {
        let mut value = at(i);
        i += 1;
        out.push(ITOA64[(value & 0x3f) as usize]);
        if i < count {
            value |= at(i) << 8;
        }
        out.push(ITOA64[((value >> 6) & 0x3f) as usize]);
        if i >= count {
            break;
        }
        i += 1;
        if i < count {
            value |= at(i) << 16;
        }
        out.push(ITOA64[((value >> 12) & 0x3f) as usize]);
        if i >= count {
            break;
        }
        i += 1;
        out.push(ITOA64[((value >> 18) & 0x3f) as usize]);
        if i >= count {
            break;
        }
    }
    out
}

/// `cryptPrivate()`: the portable `$P$`/`$H$` hash, or `*0`/`*1` when the
/// setting is not one.
fn crypt_private(password: &[u8], setting: &[u8]) -> Vec<u8> {
    let failure = if setting.starts_with(b"*0") { b"*1".to_vec() } else { b"*0".to_vec() };
    let id = &setting[..setting.len().min(3)];
    if id != b"$P$" && id != b"$H$" {
        return failure;
    }
    // `strpos($itoa64, $setting[3])`: a missing offset is "", found at 0.
    let count_log2 = match setting.get(3) {
        None => 0,
        Some(c) => match ITOA64.iter().position(|x| x == c) {
            Some(p) => p,
            None => return failure,
        },
    };
    if !(7..=30).contains(&count_log2) {
        return failure;
    }
    let Some(salt) = setting.get(4..12) else {
        return failure;
    };
    let mut hash = Md5::new().chain_update(salt).chain_update(password).finalize();
    for _ in 0..(1u64 << count_log2) {
        hash = Md5::new().chain_update(hash).chain_update(password).finalize();
    }
    let mut out = setting[..12].to_vec();
    out.extend_from_slice(&encode64(&hash, 16));
    out
}

impl Hash for PHPass {
    hash_plumbing!("phpass");

    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        let portable = self.options.raw("portable_hashes").is_some_and(truthy);
        let mut random = Vec::new();
        if !portable {
            let mut bytes = [0u8; 16];
            random_bytes(&mut bytes);
            let setting = self.gensalt_blowfish(&bytes)?;
            random = bytes.to_vec();
            let hash = password::crypt(value, &setting);
            if hash.len() == 60 {
                return Ok(hash);
            }
        }
        if random.len() < 6 {
            random = vec![0u8; 6];
            random_bytes(&mut random);
        }
        let hash = crypt_private(value, &self.gensalt_private(&random)?);
        Ok(if hash.len() == 34 { hash } else { b"*".to_vec() })
    }

    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        let mut computed = crypt_private(value, hash);
        if computed.first() == Some(&b'*') {
            computed = password::crypt(value, hash);
        }
        Ok(hash_equals(hash, &computed))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn fixtures() {
        let p = PHPass::new();
        assert!(p.verify(b"appwrite", b"$P$Br387rwferoKN7uwHZqNMu98q3U8RO.").unwrap());
        let mut portable = PHPass::new();
        portable.set_portable_hashes(true);
        let h = portable.hash(b"secret").unwrap();
        assert!(h.starts_with(b"$P$B") && h.len() == 34);
        assert!(portable.verify(b"secret", &h).unwrap());
        let h = p.hash(b"secret").unwrap();
        assert!(h.starts_with(b"$2a$08$"));
        assert!(p.verify(b"secret", &h).unwrap());
    }
}
