//! Portable PHP password hashing framework (phpass), as used by WordPress.

use md5::{Digest, Md5};

use crate::{hash_equals, random_bytes};

const ITOA64: &[u8] = b"./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
const BCRYPT64: &[u8] = b"./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";

fn encode64(input: &[u8], count: usize) -> String {
    let mut output = String::new();
    let mut i = 0;
    loop {
        let mut value = input[i] as u32;
        i += 1;
        output.push(ITOA64[(value & 0x3f) as usize] as char);
        if i < count {
            value |= (input[i] as u32) << 8;
        }
        output.push(ITOA64[((value >> 6) & 0x3f) as usize] as char);
        let done = i >= count;
        i += 1;
        if done {
            break;
        }
        if i < count {
            value |= (input[i] as u32) << 16;
        }
        output.push(ITOA64[((value >> 12) & 0x3f) as usize] as char);
        let done = i >= count;
        i += 1;
        if done {
            break;
        }
        output.push(ITOA64[((value >> 18) & 0x3f) as usize] as char);
        if i >= count {
            break;
        }
    }
    output
}

fn crypt_private(password: &str, setting: &str) -> String {
    let mut output = "*0".to_owned();
    if setting.starts_with("*0") {
        output = "*1".to_owned();
    }
    let id = setting.get(0..3).unwrap_or("");
    if id != "$P$" && id != "$H$" {
        return output;
    }
    let Some(log_char) = setting.as_bytes().get(3) else {
        return output;
    };
    let Some(count_log2) = ITOA64.iter().position(|c| c == log_char) else {
        return output;
    };
    if !(7..=30).contains(&count_log2) {
        return output;
    }
    let Some(salt) = setting.get(4..12) else {
        return output;
    };
    let mut count = 1u64 << count_log2;
    let mut hasher = Md5::new();
    hasher.update(salt.as_bytes());
    hasher.update(password.as_bytes());
    let mut hash = hasher.finalize();
    loop {
        let mut hasher = Md5::new();
        hasher.update(hash);
        hasher.update(password.as_bytes());
        hash = hasher.finalize();
        count -= 1;
        if count == 0 {
            break;
        }
    }
    let mut out = setting[..12].to_owned();
    out.push_str(&encode64(&hash, 16));
    out
}

fn gensalt_blowfish(input: &[u8; 16], iteration_count_log2: u32) -> String {
    let mut output = String::from("$2a$");
    output.push((b'0' + (iteration_count_log2 / 10) as u8) as char);
    output.push((b'0' + (iteration_count_log2 % 10) as u8) as char);
    output.push('$');
    let mut i = 0;
    loop {
        let mut c1 = input[i] as usize;
        i += 1;
        output.push(BCRYPT64[c1 >> 2] as char);
        c1 = (c1 & 0x03) << 4;
        if i >= 16 {
            output.push(BCRYPT64[c1] as char);
            break;
        }
        let mut c2 = input[i] as usize;
        i += 1;
        c1 |= c2 >> 4;
        output.push(BCRYPT64[c1] as char);
        c1 = (c2 & 0x0f) << 2;
        c2 = input[i] as usize;
        i += 1;
        c1 |= c2 >> 6;
        output.push(BCRYPT64[c1] as char);
        output.push(BCRYPT64[c2 & 0x3f] as char);
    }
    output
}

pub(crate) fn hash(password: &str, iteration_count_log2: u32, portable: bool) -> String {
    if !portable {
        let mut random = [0u8; 16];
        random_bytes(&mut random);
        let setting = gensalt_blowfish(&random, iteration_count_log2);
        // `crypt()` with a `$2a$` setting is bcrypt.
        let salt_raw = &setting[7..29];
        if let Ok(salt) = bcrypt_salt(salt_raw)
            && let Ok(parts) = bcrypt::hash_with_salt(password, iteration_count_log2.max(4), salt)
        {
            let h = parts.format_for_version(bcrypt::Version::TwoA);
            if h.len() == 60 {
                return h;
            }
        }
    }
    let mut random = [0u8; 6];
    random_bytes(&mut random);
    let mut setting = String::from("$P$");
    setting.push(ITOA64[(iteration_count_log2 as usize + 5).min(30)] as char);
    setting.push_str(&encode64(&random, 6));
    let h = crypt_private(password, &setting);
    if h.len() == 34 { h } else { "*".to_owned() }
}

fn bcrypt_salt(encoded: &str) -> Result<[u8; 16], ()> {
    // Decode the 22-char bcrypt base64 salt into 16 bytes.
    let mut bits: u32 = 0;
    let mut nbits = 0;
    let mut out = Vec::with_capacity(16);
    for ch in encoded.bytes() {
        let v = BCRYPT64.iter().position(|c| *c == ch).ok_or(())? as u32;
        bits = (bits << 6) | v;
        nbits += 6;
        if nbits >= 8 {
            nbits -= 8;
            out.push((bits >> nbits) as u8);
            bits &= (1 << nbits) - 1;
        }
    }
    out.truncate(16);
    out.try_into().map_err(|_| ())
}

pub(crate) fn verify(password: &str, stored: &str) -> bool {
    let computed = crypt_private(password, stored);
    if computed.starts_with('*') {
        return bcrypt::verify(password, stored).unwrap_or(false);
    }
    hash_equals(stored, &computed)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn portable_round_trip() {
        let h = hash("secret", 8, true);
        assert!(h.starts_with("$P$B"), "{h}");
        assert_eq!(h.len(), 34);
        assert!(verify("secret", &h));
        assert!(!verify("nope", &h));
    }

    #[test]
    fn blowfish_round_trip() {
        let h = hash("secret", 8, false);
        assert!(h.starts_with("$2a$08$"), "{h}");
        assert!(verify("secret", &h));
    }
}
