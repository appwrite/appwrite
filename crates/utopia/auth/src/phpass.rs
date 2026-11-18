//! The pieces of phpass (Openwall's Portable PHP password hashing framework 0.5) that
//! `Utopia\Auth\Hashes\PHPass` implements in PHP: its base64 variant, its blowfish salt and
//! the MD5 rounds of its portable (`$P$`, phpBB's `$H$`) hashes.
//!
//! PHP reads a string offset past the end as `""` with the warning
//! `Uninitialized string offset N`; these functions read it the same way and report each such
//! offset to `warn`, in the order PHP raises them.

use md5::{Digest, Md5};

/// The alphabet of phpass's base64 (`PHPass::$itoa64`).
pub const ITOA64: &[u8; 64] = b"./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";

/// crypt_blowfish's alphabet, which `gensaltBlowfish()` encodes the salt in.
const BLOWFISH_ITOA64: &[u8; 64] = b"./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";

/// `ord($input[$i])`: 0 for an offset past the end, which is reported.
fn ord(input: &[u8], i: usize, warn: &mut dyn FnMut(usize)) -> u32 {
    input.get(i).map_or_else(
        || {
            warn(i);
            0
        },
        |b| u32::from(*b),
    )
}

/// `$output .= $alphabet[$i]`: nothing for an offset past the end, which is reported.
fn push(output: &mut Vec<u8>, alphabet: &[u8], i: u32, warn: &mut dyn FnMut(usize)) {
    let i = i as usize;
    match alphabet.get(i) {
        Some(c) => output.push(*c),
        None => warn(i),
    }
}

/// `PHPass::encode64($input, $count)` with `$this->itoa64` as `itoa64`, for `count` of at
/// least 1 (the method throws below that). Offsets past the end of `input` are reported to
/// `warn_input`, past the end of `itoa64` to `warn_itoa64`.
pub fn encode64(
    itoa64: &[u8],
    input: &[u8],
    count: usize,
    warn_input: &mut dyn FnMut(usize),
    warn_itoa64: &mut dyn FnMut(usize),
) -> Vec<u8> {
    let mut output = Vec::with_capacity(count.div_ceil(3) * 4);
    let mut i = 0;
    loop {
        let mut value = ord(input, i, warn_input);
        i += 1;
        push(&mut output, itoa64, value & 0x3F, warn_itoa64);
        if i < count {
            value |= ord(input, i, warn_input) << 8;
        }
        push(&mut output, itoa64, (value >> 6) & 0x3F, warn_itoa64);
        let at = i;
        i += 1;
        if at >= count {
            break;
        }
        if i < count {
            value |= ord(input, i, warn_input) << 16;
        }
        push(&mut output, itoa64, (value >> 12) & 0x3F, warn_itoa64);
        let at = i;
        i += 1;
        if at >= count {
            break;
        }
        push(&mut output, itoa64, (value >> 18) & 0x3F, warn_itoa64);
        if i >= count {
            break;
        }
    }
    output
}

/// The salt characters `gensaltBlowfish()` appends after `$2a$NN$`: 16 bytes of `input` in
/// crypt_blowfish's base64 (22 characters). Offsets past the end of `input` are reported.
pub fn blowfish_salt(input: &[u8], warn: &mut dyn FnMut(usize)) -> Vec<u8> {
    let mut output = Vec::with_capacity(22);
    let mut i = 0;
    loop {
        let mut c1 = ord(input, i, warn);
        i += 1;
        output.push(BLOWFISH_ITOA64[(c1 >> 2) as usize]);
        c1 = (c1 & 0x03) << 4;
        if i >= 16 {
            output.push(BLOWFISH_ITOA64[c1 as usize]);
            break;
        }
        let mut c2 = ord(input, i, warn);
        i += 1;
        c1 |= c2 >> 4;
        output.push(BLOWFISH_ITOA64[c1 as usize]);
        c1 = (c2 & 0x0F) << 2;
        c2 = ord(input, i, warn);
        i += 1;
        c1 |= c2 >> 6;
        output.push(BLOWFISH_ITOA64[c1 as usize]);
        output.push(BLOWFISH_ITOA64[(c2 & 0x3F) as usize]);
    }
    output
}

/// `md5($data, true)`.
#[must_use]
pub fn md5_raw(data: &[u8]) -> [u8; 16] {
    Md5::digest(data).into()
}

/// The MD5 rounds of a portable hash: `md5($salt . $password, true)`, then `count` times
/// `md5($hash . $password, true)`.
#[must_use]
pub fn md5_rounds(salt: &[u8], password: &[u8], count: u64) -> [u8; 16] {
    let mut hash: [u8; 16] = Md5::new().chain_update(salt).chain_update(password).finalize().into();
    for _ in 0..count {
        hash = Md5::new().chain_update(hash).chain_update(password).finalize().into();
    }
    hash
}

#[cfg(test)]
mod tests {
    use super::{ITOA64, blowfish_salt, encode64, md5_rounds};

    fn none(_: usize) {
        panic!("no offset past the end");
    }

    /// A portable hash: `$P$` + count character + 8-character salt + `encode64` of the MD5
    /// rounds, as WordPress stores them (WordPress's `wp_hash_password()` of "password").
    #[test]
    fn wordpress_hash() {
        let setting = b"$P$BkZSVUxvhvrYOqtXfIkMOz2sNVHU8M1";
        let count_log2 = ITOA64.iter().position(|c| *c == setting[3]).unwrap();
        let hash = md5_rounds(&setting[4..12], b"password", 1 << count_log2);
        let mut out = setting[..12].to_vec();
        out.extend(encode64(ITOA64, &hash, 16, &mut none, &mut none));
        assert_eq!(out.len(), 34);
    }

    #[test]
    fn short_input_reports_offsets() {
        let mut offsets = Vec::new();
        let out = encode64(ITOA64, b"ab", 6, &mut |i| offsets.push(i), &mut none);
        assert_eq!(out.len(), 8);
        assert_eq!(offsets, vec![2, 3, 4, 5]);
        let mut offsets = Vec::new();
        assert_eq!(blowfish_salt(b"", &mut |i| offsets.push(i)).len(), 22);
        assert_eq!(offsets, (0..16).collect::<Vec<_>>());
    }
}
