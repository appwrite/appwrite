use php_std::encoding::bin2hex;

use super::hash_plumbing;
use crate::hash::{hash_equals, random_bytes};
use crate::{Error, Hash, Options};

/// Scrypt through php-scrypt's `scrypt()`, lowercase hex
/// (`Utopia\Auth\Hashes\Scrypt`).
///
/// Options: `type`, `costCpu` (N), `costMemory` (r), `costParallel` (p),
/// `length` (bytes) and `salt` (used as given, a random hex string by default).
#[derive(Debug, Clone, PartialEq)]
pub struct Scrypt {
    options: Options,
}

impl Default for Scrypt {
    fn default() -> Self {
        Self::new()
    }
}

impl Scrypt {
    /// `new Scrypt()`: N 8, r 14, p 1, 64 bytes, a random salt.
    pub fn new() -> Self {
        let mut salt = [0u8; 16];
        random_bytes(&mut salt);
        let mut options = Options::new();
        options.set("type", "scrypt");
        options.set("costCpu", 8);
        options.set("costMemory", 14);
        options.set("costParallel", 1);
        options.set("length", 64);
        options.set("salt", bin2hex(&salt));
        Self { options }
    }

    /// `setCpuCost()`: N, a power of 2 above 1.
    pub fn set_cpu_cost(&mut self, cost: i64) -> Result<&mut Self, Error> {
        if cost <= 1 || (cost & (cost - 1)) != 0 {
            return Err(Error::InvalidArgument("CPU cost must be > 1 and a power of 2".into()));
        }
        self.options.set("costCpu", cost);
        Ok(self)
    }

    /// `setMemoryCost()`: r, at least 1.
    pub fn set_memory_cost(&mut self, cost: i64) -> Result<&mut Self, Error> {
        if cost < 1 {
            return Err(Error::InvalidArgument("Memory cost must be >= 1".into()));
        }
        self.options.set("costMemory", cost);
        Ok(self)
    }

    /// `setParallelCost()`: p, at least 1.
    pub fn set_parallel_cost(&mut self, cost: i64) -> Result<&mut Self, Error> {
        if cost < 1 {
            return Err(Error::InvalidArgument("Parallel cost must be >= 1".into()));
        }
        self.options.set("costParallel", cost);
        Ok(self)
    }

    /// `setLength()`: at least 16 bytes.
    pub fn set_length(&mut self, length: i64) -> Result<&mut Self, Error> {
        if length < 16 {
            return Err(Error::InvalidArgument("Length must be >= 16 bytes".into()));
        }
        self.options.set("length", length);
        Ok(self)
    }

    /// `setSalt()`: neither `""` nor `"0"`.
    pub fn set_salt(&mut self, salt: &[u8]) -> Result<&mut Self, Error> {
        if salt.is_empty() || salt == b"0" {
            return Err(Error::InvalidArgument("Salt cannot be empty".into()));
        }
        self.options.set("salt", salt.to_vec());
        Ok(self)
    }
}

/// php-scrypt's `scrypt($password, $salt, $N, $r, $p, $keyLength)`: hex output.
///
/// The argument checks are php-scrypt's, in its order; the derivation is
/// Tarsnap's `crypto_scrypt` (which, unlike RFC 7914 implementations, accepts
/// any `N` below `2^(128 r / 8)`... and above it).
pub(crate) fn scrypt(password: &[u8], salt: &[u8], n: i64, r: i64, p: i64, length: i64) -> Result<Vec<u8>, Error> {
    let argument = |m: &str| Err(Error::Engine(format!("scrypt(): {m}")));
    if n < 2 {
        return argument("Argument #3 ($N) must be greater than 1");
    }
    if r < 1 {
        return argument("Argument #4 ($r) must be greater than 0");
    }
    if p < 1 {
        return argument("Argument #5 ($p) must be greater than 0");
    }
    if n & (n - 1) != 0 {
        return argument("Argument #3 ($N) must be a power of 2");
    }
    if length < 16 {
        return argument("Argument #6 ($key_length) must be greater than or equal to 16");
    }
    let failed = || Error::Runtime("Failed to hash using scrypt".into());
    // crypto_scrypt: EFBIG for r * p >= 2^30, ENOMEM where the buffers cannot be sized.
    if r.saturating_mul(p) >= 1 << 30 {
        return Err(failed());
    }
    let (n, r, p) = (n as usize, r as usize, p as usize);
    let length = usize::try_from(length).map_err(|_| failed())?;
    let block = 128usize.checked_mul(r).ok_or_else(failed)?;
    let v_len = block.checked_mul(n).ok_or_else(failed)?;
    let b_len = block.checked_mul(p).ok_or_else(failed)?;
    let mut b = Vec::new();
    b.try_reserve_exact(b_len).map_err(|_| failed())?;
    b.resize(b_len, 0);
    let mut v: Vec<u32> = Vec::new();
    v.try_reserve_exact(v_len / 4).map_err(|_| failed())?;
    v.resize(v_len / 4, 0);
    pbkdf2_sha256(password, salt, &mut b);
    let mut x = vec![0u32; block / 4];
    let mut y = vec![0u32; block / 4];
    for chunk in b.chunks_mut(block) {
        romix(chunk, n, r, &mut v, &mut x, &mut y);
    }
    let mut out = vec![0u8; length];
    pbkdf2_sha256(password, &b, &mut out);
    Ok(bin2hex(&out).into_bytes())
}

/// PBKDF2-HMAC-SHA256 with one iteration, filling `out`.
fn pbkdf2_sha256(password: &[u8], salt: &[u8], out: &mut [u8]) {
    use hmac::{Hmac, Mac};
    let prf = Hmac::<sha2::Sha256>::new_from_slice(password).expect("HMAC takes any key length");
    for (i, chunk) in out.chunks_mut(32).enumerate() {
        let mut mac = prf.clone();
        mac.update(salt);
        mac.update(&(i as u32 + 1).to_be_bytes());
        let t = mac.finalize().into_bytes();
        chunk.copy_from_slice(&t[..chunk.len()]);
    }
}

/// The Salsa20/8 core, in place.
fn salsa20_8(b: &mut [u32; 16]) {
    let mut x = *b;
    for _ in 0..4 {
        macro_rules! quarter {
            ($a:expr, $b:expr, $c:expr, $d:expr) => {
                x[$b] ^= x[$a].wrapping_add(x[$d]).rotate_left(7);
                x[$c] ^= x[$b].wrapping_add(x[$a]).rotate_left(9);
                x[$d] ^= x[$c].wrapping_add(x[$b]).rotate_left(13);
                x[$a] ^= x[$d].wrapping_add(x[$c]).rotate_left(18);
            };
        }
        quarter!(0, 4, 8, 12);
        quarter!(5, 9, 13, 1);
        quarter!(10, 14, 2, 6);
        quarter!(15, 3, 7, 11);
        quarter!(0, 1, 2, 3);
        quarter!(5, 6, 7, 4);
        quarter!(10, 11, 8, 9);
        quarter!(15, 12, 13, 14);
    }
    for (b, x) in b.iter_mut().zip(x) {
        *b = b.wrapping_add(x);
    }
}

/// `blockmix_salsa8`: `y = BlockMix(x)` over `2 r` 64-byte blocks.
fn block_mix(x: &[u32], y: &mut [u32], r: usize) {
    let mut t: [u32; 16] = x[(2 * r - 1) * 16..2 * r * 16].try_into().expect("16 words");
    for i in 0..2 * r {
        for (t, x) in t.iter_mut().zip(&x[i * 16..i * 16 + 16]) {
            *t ^= x;
        }
        salsa20_8(&mut t);
        // Even blocks go to the first half, odd blocks to the second.
        let at = if i % 2 == 0 { (i / 2) * 16 } else { (r + i / 2) * 16 };
        y[at..at + 16].copy_from_slice(&t);
    }
}

/// `smix`: ROMix on one `128 r` byte block.
fn romix(block: &mut [u8], n: usize, r: usize, v: &mut [u32], x: &mut [u32], y: &mut [u32]) {
    let words = 32 * r;
    for (w, c) in x.iter_mut().zip(block.chunks_exact(4)) {
        *w = u32::from_le_bytes([c[0], c[1], c[2], c[3]]);
    }
    for i in 0..n {
        v[i * words..(i + 1) * words].copy_from_slice(x);
        block_mix(x, y, r);
        x.copy_from_slice(y);
    }
    for _ in 0..n {
        let j = (x[(2 * r - 1) * 16] as usize) & (n - 1);
        for (x, v) in x.iter_mut().zip(&v[j * words..(j + 1) * words]) {
            *x ^= v;
        }
        block_mix(x, y, r);
        x.copy_from_slice(y);
    }
    for (c, w) in block.chunks_exact_mut(4).zip(x.iter()) {
        c.copy_from_slice(&w.to_le_bytes());
    }
}

impl Hash for Scrypt {
    hash_plumbing!("scrypt");

    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        let o = &self.options;
        let salt = o.get("salt").and(o.string("salt"));
        let Some(salt) = salt else {
            return Err(Error::InvalidArgument("Salt must be a string".into()));
        };
        let (Some(n), Some(r), Some(p), Some(length)) =
            (o.int("costCpu"), o.int("costMemory"), o.int("costParallel"), o.int("length"))
        else {
            return Err(Error::InvalidArgument("Scrypt cost and length options must be integers".into()));
        };
        scrypt(value, salt, n, r, p, length)
    }

    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(hash_equals(hash, &self.hash(value)?))
    }
}

#[cfg(test)]
mod tests {
    use super::scrypt;

    #[test]
    fn php_scrypt_vectors() {
        let hex = |n, r, p, l| String::from_utf8(scrypt(b"", b"", n, r, p, l).unwrap()).unwrap();
        // RFC 7914 §12, first vector.
        assert_eq!(
            hex(16, 1, 1, 64),
            "77d6576238657b203b19ca42c18a0497f16b4844e3074ae8dfdffa3fede21442fcd0069ded0948f8326a753a0fc81f17e8d3e0fb2e0d3628cf35e20c38d18906"
        );
        // From PHP: N = 2, and N = 2^16 with r = 1, which RFC 7914 implementations refuse.
        assert_eq!(hex(2, 1, 1, 16), "fa76e020d54d9e8aa24023c6baecdd46");
        assert_eq!(hex(65536, 1, 1, 16), "2f417181e071649663b17119437b3b64");
    }
}
