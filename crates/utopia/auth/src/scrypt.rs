//! `scrypt()` of the PECL `scrypt` extension 2.0.1, which `Utopia\Auth\Hashes\Scrypt` and
//! `ScryptModified` call: the scrypt KDF of RFC 7914 (Colin Percival's `crypto_scrypt`), with
//! the extension's checks, in its order and with its messages.
//!
//! PHP allocates scrypt's working memory with the system allocator (`posix_memalign` and an
//! anonymous `mmap`), outside `memory_limit`, and returns `false` when it cannot; so does
//! [`scrypt`], whose buffers are reserved fallibly.

use sha2::{Digest, Sha256};
use zeroize::Zeroize;

/// How `scrypt()` fails.
#[derive(Debug, PartialEq, Eq)]
pub enum Failure {
    /// It throws an `Error` with this message.
    Error(String),
    /// It raises this `E_WARNING` and returns `false`.
    Warning(&'static str),
    /// It returns `false` without a warning: the memory could not be allocated.
    False,
}

/// `$keyLength`'s upper bound, `(2^32 - 1) * 32`.
const MAX_KEY_LENGTH: i64 = ((1 << 32) - 1) * 32;

/// `scrypt($password, $salt, $N, $r, $p, $keyLength, true)`: the raw derived key (PHP's
/// default output is its hex, `bin2hex()` of this).
///
/// # Errors
///
/// As PHP `scrypt()` fails: the `Error` its arguments raise (`$N` not a power of 2 greater
/// than 1, `$r` or `$p` below 1 or above `2^32 - 1`, `$keyLength` below 16 or above
/// `(2^32 - 1) * 32`), the warning and `false` of `crypto_scrypt`'s checks (`$r * $p` of
/// `2^30` or more, or memory beyond the address space), and `false` when memory runs out.
pub fn scrypt(password: &[u8], salt: &[u8], n: i64, r: i64, p: i64, key_length: i64) -> Result<Vec<u8>, Failure> {
    let argument = |position: u32, name: &str, rule: &str| {
        Failure::Error(format!("scrypt(): Argument #{position} (${name}) {rule}"))
    };
    // clampAndCast64(3, "N", N, 1), clampAndCast32(4, "r", r, 0), clampAndCast32(5, "p", p, 0).
    if n <= 1 {
        return Err(argument(3, "N", "must be greater than 1"));
    }
    let n = n.unsigned_abs();
    let cast32 = |position: u32, name: &str, value: i64| -> Result<u32, Failure> {
        if value <= 0 {
            return Err(argument(position, name, "must be greater than 0"));
        }
        u32::try_from(value).map_err(|_| argument(position, name, "is too large"))
    };
    let r = cast32(4, "r", r)?;
    let p = cast32(5, "p", p)?;
    if n & (n - 1) != 0 {
        return Err(argument(3, "N", "must be a power of 2"));
    }
    if key_length < 16 {
        return Err(argument(6, "key_length", "must be greater than or equal to 16"));
    }
    if key_length > MAX_KEY_LENGTH {
        return Err(argument(6, "key_length", "must be less than or equal to (2^32 - 1) * 32"));
    }
    let key_length = usize::try_from(key_length).map_err(|_| Failure::False)?;
    let mut key = reserve::<u8>(key_length)?;
    derive(password, salt, n, r, p, &mut key)?;
    Ok(key)
}

/// `crypto_scrypt()` into `key`, after the extension's own checks.
fn derive(password: &[u8], salt: &[u8], n: u64, r: u32, p: u32, key: &mut [u8]) -> Result<(), Failure> {
    let (r, p) = (u64::from(r), u64::from(p));
    if r * p >= 1 << 30 {
        return Err(Failure::Warning("Invalid Parameters; $r * $p is >= 2^30"));
    }
    let max = usize::MAX as u64;
    if r > max / 128 / p || n > max / 128 / r {
        return Err(Failure::Warning("Invalid Parameters"));
    }
    // Within the address space now: the checks above bound every product below.
    let (r, p, n) = (r as usize, p as usize, n as usize);
    let block = 32 * r; // 128 * r bytes, in 32-bit words
    let mut b = reserve::<u8>(128 * r * p)?;
    let mut xy = reserve::<u32>(2 * block)?;
    let mut v = reserve::<u32>(block * n)?;

    // 1: (B_0 ... B_{p-1}) <-- PBKDF2(P, S, 1, p * 128 * r)
    pbkdf2_sha256(password, salt, &mut b);
    // 2: for i = 0 to p - 1, B_i <-- ROMix(B_i, N)
    let mut x = vec![0u32; block];
    for chunk in b.chunks_exact_mut(128 * r) {
        for (word, bytes) in x.iter_mut().zip(chunk.chunks_exact(4)) {
            *word = u32::from_le_bytes([bytes[0], bytes[1], bytes[2], bytes[3]]);
        }
        romix(&mut x, &mut v, &mut xy, r, n);
        for (word, bytes) in x.iter().zip(chunk.chunks_exact_mut(4)) {
            bytes.copy_from_slice(&word.to_le_bytes());
        }
    }
    // 3: DK <-- PBKDF2(P, B, 1, dkLen)
    pbkdf2_sha256(password, &b, key);
    x.zeroize();
    b.zeroize();
    xy.zeroize();
    v.zeroize();
    Ok(())
}

/// A zeroed buffer of `len` items, or [`Failure::False`] where the allocator refuses it, as
/// `posix_memalign` or `mmap` fail in PHP.
fn reserve<T: Clone + Default>(len: usize) -> Result<Vec<T>, Failure> {
    let mut buffer = Vec::new();
    buffer.try_reserve_exact(len).map_err(|_| Failure::False)?;
    buffer.resize(len, T::default());
    Ok(buffer)
}

/// scrypt's ROMix over one block `x` (`32 * r` words), with `v` (`N` blocks) and `xy`
/// (two blocks) as working memory.
fn romix(x: &mut [u32], v: &mut [u32], xy: &mut [u32], r: usize, n: usize) {
    let block = 32 * r;
    let (y, scratch) = xy.split_at_mut(block);
    for i in 0..n {
        v[i * block..(i + 1) * block].copy_from_slice(x);
        blockmix_salsa8(x, y, scratch, r);
    }
    for _ in 0..n {
        // Integerify: the first 64 bits of the last 64-byte block, little-endian.
        let last = (2 * r - 1) * 16;
        let j = ((u64::from(x[last + 1]) << 32 | u64::from(x[last])) & (n as u64 - 1)) as usize;
        for (a, b) in x.iter_mut().zip(&v[j * block..(j + 1) * block]) {
            *a ^= b;
        }
        blockmix_salsa8(x, y, scratch, r);
    }
}

/// scrypt's BlockMix with Salsa20/8: `b` (`2r` 64-byte blocks) in place, `y` and the first
/// 16 words of `scratch` as working memory.
fn blockmix_salsa8(b: &mut [u32], y: &mut [u32], scratch: &mut [u32], r: usize) {
    let x = &mut scratch[..16];
    x.copy_from_slice(&b[(2 * r - 1) * 16..2 * r * 16]);
    for i in 0..2 * r {
        for (a, w) in x.iter_mut().zip(&b[i * 16..(i + 1) * 16]) {
            *a ^= w;
        }
        salsa20_8(x);
        // Even blocks to the first half, odd blocks to the second.
        let to = (i / 2 + (i & 1) * r) * 16;
        y[to..to + 16].copy_from_slice(x);
    }
    b.copy_from_slice(&y[..32 * r]);
}

/// The Salsa20/8 core, in place on 16 words.
fn salsa20_8(b: &mut [u32]) {
    let mut x: [u32; 16] = b.try_into().expect("a Salsa20 block is 16 words");
    let quarter = |x: &mut [u32; 16], a: usize, b: usize, c: usize, d: usize| {
        x[b] ^= x[a].wrapping_add(x[d]).rotate_left(7);
        x[c] ^= x[b].wrapping_add(x[a]).rotate_left(9);
        x[d] ^= x[c].wrapping_add(x[b]).rotate_left(13);
        x[a] ^= x[d].wrapping_add(x[c]).rotate_left(18);
    };
    for _ in 0..4 {
        // Columns, then rows.
        quarter(&mut x, 0, 4, 8, 12);
        quarter(&mut x, 5, 9, 13, 1);
        quarter(&mut x, 10, 14, 2, 6);
        quarter(&mut x, 15, 3, 7, 11);
        quarter(&mut x, 0, 1, 2, 3);
        quarter(&mut x, 5, 6, 7, 4);
        quarter(&mut x, 10, 11, 8, 9);
        quarter(&mut x, 15, 12, 13, 14);
    }
    for (out, mixed) in b.iter_mut().zip(x) {
        *out = out.wrapping_add(mixed);
    }
}

/// HMAC-SHA256 of `parts` (concatenated) under `key`.
fn hmac_sha256(key: &[u8], parts: &[&[u8]]) -> [u8; 32] {
    let mut block = [0u8; 64];
    if key.len() > 64 {
        block[..32].copy_from_slice(&Sha256::digest(key));
    } else {
        block[..key.len()].copy_from_slice(key);
    }
    let mut inner = Sha256::new();
    inner.update(block.map(|b| b ^ 0x36));
    for part in parts {
        inner.update(part);
    }
    let mut outer = Sha256::new();
    outer.update(block.map(|b| b ^ 0x5c));
    outer.update(inner.finalize());
    block.zeroize();
    outer.finalize().into()
}

/// PBKDF2-HMAC-SHA256 with one iteration, filling `out`.
fn pbkdf2_sha256(password: &[u8], salt: &[u8], out: &mut [u8]) {
    for (i, chunk) in out.chunks_mut(32).enumerate() {
        let index = u32::try_from(i + 1).expect("at most 2^32 - 1 blocks").to_be_bytes();
        let mut u = hmac_sha256(password, &[salt, &index]);
        chunk.copy_from_slice(&u[..chunk.len()]);
        u.zeroize();
    }
}

#[cfg(test)]
mod tests {
    use super::{Failure, scrypt};

    fn hex(bytes: &[u8]) -> String {
        bytes.iter().map(|b| format!("{b:02x}")).collect()
    }

    #[test]
    fn rfc_7914_vectors() {
        assert_eq!(
            hex(&scrypt(b"", b"", 16, 1, 1, 64).unwrap()),
            "77d6576238657b203b19ca42c18a0497f16b4844e3074ae8dfdffa3fede21442fcd0069ded0948f8326a753a0fc81f17e8d3e0fb2e0d3628cf35e20c38d18906"
        );
        assert_eq!(
            hex(&scrypt(b"password", b"NaCl", 1024, 8, 16, 64).unwrap()),
            "fdbabe1c9d3472007856e7190d01e9fe7c6ad7cbc8237830e77376634b3731622eaf30d92e22a3886ff109279d9830dac727afb94a83ee6d8360cbdfa2cc0640"
        );
    }

    /// Password, salt, N, r, p, key length and PECL's hex output.
    type Case = (&'static [u8], &'static [u8], i64, i64, i64, i64, &'static str);

    /// What PECL scrypt 2.0.1 returns in the development image, `bin2hex()`ed.
    #[test]
    fn pecl_outputs() {
        let cases: [Case; 4] = [
            (
                b"pw",
                b"salt",
                8,
                14,
                1,
                64,
                "95b38cb05a94c075852e73a18dcc67bf47bf43599196ba17ed71293fa872f6fea9cbf466d1b5745cb709785b554fe505530ddd90f340835a9723d5196a53cb01",
            ),
            (b"pw", b"", 16, 8, 1, 16, "d2edcaceddb98a212607650d7fb42a5d"),
            (b"", b"salt", 16, 8, 1, 16, "bcda6cb6c38d6459c333688c498370f4"),
            (b"p\0w", b"s\0alt", 16, 8, 1, 16, "58d5604cbe4c2055289421bbad920a06"),
        ];
        for (password, salt, n, r, p, length, expected) in cases {
            assert_eq!(hex(&scrypt(password, salt, n, r, p, length).unwrap()), expected);
        }
    }

    #[test]
    fn pecl_failures() {
        let error = |message: &str| Err(Failure::Error(format!("scrypt(): {message}")));
        assert_eq!(scrypt(b"", b"", 1, 8, 1, 16), error("Argument #3 ($N) must be greater than 1"));
        assert_eq!(scrypt(b"", b"", -16, 8, 1, 16), error("Argument #3 ($N) must be greater than 1"));
        assert_eq!(scrypt(b"", b"", 3, 8, 1, 16), error("Argument #3 ($N) must be a power of 2"));
        assert_eq!(scrypt(b"", b"", 16, 0, 1, 16), error("Argument #4 ($r) must be greater than 0"));
        assert_eq!(scrypt(b"", b"", 16, 1 << 32, 1, 16), error("Argument #4 ($r) is too large"));
        assert_eq!(scrypt(b"", b"", 16, 8, 0, 16), error("Argument #5 ($p) must be greater than 0"));
        assert_eq!(
            scrypt(b"", b"", 16, 8, 1, 15),
            error("Argument #6 ($key_length) must be greater than or equal to 16")
        );
        assert_eq!(
            scrypt(b"", b"", 16, 8, 1, ((1 << 32) - 1) * 32 + 1),
            error("Argument #6 ($key_length) must be less than or equal to (2^32 - 1) * 32")
        );
        assert_eq!(
            scrypt(b"", b"", 2, 1 << 30, 1, 16),
            Err(Failure::Warning("Invalid Parameters; $r * $p is >= 2^30"))
        );
        assert_eq!(
            scrypt(b"", b"", 2, 1 << 15, 1 << 15, 16),
            Err(Failure::Warning("Invalid Parameters; $r * $p is >= 2^30"))
        );
        assert_eq!(scrypt(b"", b"", 1 << 62, 1, 1, 16), Err(Failure::Warning("Invalid Parameters")));
    }
}
