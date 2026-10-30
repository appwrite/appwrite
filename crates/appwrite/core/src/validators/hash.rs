//! `Appwrite\Auth\Validator\Hash\*`: imported password hashes. Their costs
//! are honoured on every verify, so they are capped.

use serde_json::Value;
use utopia_validators::Validator;

use super::Password;

/// Whether every byte of `s` is in `alphabet` (PHP `strspn() === strlen()`).
fn within(s: &[u8], alphabet: &[u8]) -> bool {
    s.iter().all(|b| alphabet.contains(b))
}

/// PHP `ctype_digit()` then `(int)`: `None` unless all digits; values past
/// `u64` saturate like PHP's cast.
fn digits(s: &str) -> Option<u64> {
    if s.is_empty() || !s.bytes().all(|b| b.is_ascii_digit()) {
        return None;
    }
    Some(s.parse().unwrap_or(u64::MAX))
}

/// The value, when the parent `Password` validator accepts it.
fn password(value: &Value) -> Option<&str> {
    if !Password::default().is_valid(value) {
        return None;
    }
    value.as_str()
}

/// `Appwrite\Auth\Validator\Hash\Bcrypt`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Bcrypt;

impl Bcrypt {
    pub const COST_MIN: u64 = 4;
    pub const COST_MAX: u64 = 15;
    const ALPHABET: &'static [u8] = b"./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    const PREFIXES: [&'static str; 4] = ["$2a$", "$2b$", "$2x$", "$2y$"];
    const LENGTH: usize = 60;

    fn accepts(s: &str) -> bool {
        let b = s.as_bytes();
        if b.len() != Self::LENGTH || !Self::PREFIXES.iter().any(|p| b.starts_with(p.as_bytes())) || b[6] != b'$' {
            return false;
        }
        let Some(cost) = std::str::from_utf8(&b[4..6]).ok().and_then(digits) else {
            return false;
        };
        // A salt or digest outside the bcrypt alphabet makes crypt() fail, so the user could never sign in
        within(&b[7..], Self::ALPHABET) && (Self::COST_MIN..=Self::COST_MAX).contains(&cost)
    }
}

impl Validator for Bcrypt {
    fn description(&self) -> String {
        format!("Password must be a bcrypt hash with a cost of at most {}.", Self::COST_MAX)
    }

    fn is_valid(&self, value: &Value) -> bool {
        password(value).is_some_and(Self::accepts)
    }
}

/// `Appwrite\Auth\Validator\Hash\Argon2`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Argon2;

impl Argon2 {
    pub const MEMORY_MAX: u64 = 262_144;
    pub const TIME_MAX: u64 = 10;
    pub const THREADS_MAX: u64 = 16;
    pub const SALT_MIN: usize = 8;
    pub const TAG_MIN: usize = 4;
    const ALGORITHMS: [&'static str; 2] = ["argon2i", "argon2id"];
    // Argon2 1.0 (0x10) and 1.3 (0x13)
    const VERSIONS: [&'static str; 2] = ["v=16", "v=19"];
    // Salt and digest are unpadded base64
    const ALPHABET: &'static [u8] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

    fn accepts(s: &str) -> bool {
        let parts: Vec<&str> = s.split('$').collect();
        if parts.len() != 6
            || !parts[0].is_empty()
            || !Self::ALGORITHMS.contains(&parts[1])
            || !Self::VERSIONS.contains(&parts[2])
        {
            return false;
        }
        // Argon2 needs a salt of at least 8 bytes and a tag of at least 4, or the hash can never be verified
        for (encoded, minimum) in [(parts[4], Self::SALT_MIN), (parts[5], Self::TAG_MIN)] {
            let n = encoded.len();
            if n == 0 || !within(encoded.as_bytes(), Self::ALPHABET) || n % 4 == 1 || n * 3 / 4 < minimum {
                return false;
            }
        }
        let costs: Vec<&str> = parts[3].split(',').collect();
        let limits = [("m=", Self::MEMORY_MAX), ("t=", Self::TIME_MAX), ("p=", Self::THREADS_MAX)];
        if costs.len() != limits.len() {
            return false;
        }
        let mut values = [0u64; 3];
        for (index, (prefix, limit)) in limits.iter().enumerate() {
            let Some(cost) = costs[index].strip_prefix(prefix).and_then(digits) else {
                return false;
            };
            if cost < 1 || cost > *limit {
                return false;
            }
            values[index] = cost;
        }
        // Argon2 needs at least 8 KiB of memory per lane, or the hash can never be verified
        values[0] >= 8 * values[2]
    }
}

impl Validator for Argon2 {
    fn description(&self) -> String {
        format!(
            "Password must be an Argon2 hash with a memory cost of at most {} KiB, a time cost of at most {} and a parallelism of at most {}.",
            Self::MEMORY_MAX,
            Self::TIME_MAX,
            Self::THREADS_MAX
        )
    }

    fn is_valid(&self, value: &Value) -> bool {
        password(value).is_some_and(Self::accepts)
    }
}

/// `Appwrite\Auth\Validator\Hash\PHPass`: a portable hash, or a bcrypt one.
#[derive(Debug, Clone, Copy, Default)]
pub struct PHPass;

impl PHPass {
    pub const ITERATIONS_MIN: usize = 7;
    pub const ITERATIONS_MAX: usize = 16;
    const ALPHABET: &'static [u8] = b"./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
    const PREFIXES: [&'static str; 2] = ["$P$", "$H$"];
    const LENGTH: usize = 34;

    fn accepts(s: &str) -> bool {
        if s.starts_with("$2") {
            return Bcrypt::accepts(s);
        }
        let b = s.as_bytes();
        if b.len() != Self::LENGTH || !Self::PREFIXES.iter().any(|p| b.starts_with(p.as_bytes())) {
            return false;
        }
        // A salt or digest outside the PHPass alphabet can never match, so the user could never sign in
        if !within(&b[4..], Self::ALPHABET) {
            return false;
        }
        Self::ALPHABET
            .iter()
            .position(|c| *c == b[3])
            .is_some_and(|i| (Self::ITERATIONS_MIN..=Self::ITERATIONS_MAX).contains(&i))
    }
}

impl Validator for PHPass {
    fn description(&self) -> String {
        format!(
            "Password must be a PHPass portable hash with an iteration count of at most 2^{}, or a bcrypt hash with a cost of at most {}.",
            Self::ITERATIONS_MAX,
            Bcrypt::COST_MAX
        )
    }

    fn is_valid(&self, value: &Value) -> bool {
        password(value).is_some_and(Self::accepts)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    const DIGEST: &str = "xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxBLXmOPHrYMwV0mHRBBSBOq";
    const TAIL: &str = "$TmtWSjZ3aE5keWNEdFlhcg$KjIALjgKcbZxSfJh1RkK6/9wqD+WbGVRjzTJ3BIQaBg";

    #[test]
    fn bcrypt() {
        assert!(Bcrypt.is_valid(&json!(format!("$2y$10${DIGEST}"))));
        assert!(Bcrypt.is_valid(&json!(format!("$2a$15${DIGEST}"))));
        assert!(!Bcrypt.is_valid(&json!(format!("$2y$16${DIGEST}"))));
        assert!(!Bcrypt.is_valid(&json!(format!("$2y$03${DIGEST}"))));
        assert!(!Bcrypt.is_valid(&json!(format!("$2y$10${}", "!".repeat(53)))));
        assert!(!Bcrypt.is_valid(&json!(format!("$2y$10{DIGEST}"))));
        assert!(!Bcrypt.is_valid(&json!(10)));
    }

    #[test]
    fn argon2() {
        assert!(Argon2.is_valid(&json!(format!("$argon2id$v=19$m=65536,t=4,p=1{TAIL}"))));
        assert!(Argon2.is_valid(&json!(format!("$argon2i$v=16$m=262144,t=10,p=16{TAIL}"))));
        // The E2E suite's imports
        assert!(
            Argon2
                .is_valid(&json!("$argon2i$v=19$m=20,t=3,p=2$YXBwd3JpdGU$A/54i238ed09ZR4NwlACU5XnkjNBZU9QeOEuhjLiexI"))
        );
        assert!(Argon2.is_valid(&json!(
            "$argon2id$v=19$m=65536,t=4,p=3$azFVSGhPaXVBYnZMblNRaw$Pzm3TowCIbab0S5GL2id3OhZMexfFvutVSDoZ8D/Z8o"
        )));
        for bad in [
            format!("$argon2id$v=19$m=262145,t=4,p=1{TAIL}"),
            format!("$argon2id$v=19$m=1024,t=64,p=1{TAIL}"),
            format!("$argon2id$v=19$m=65536,t=4,p=17{TAIL}"),
            format!("$argon2id$v=19$m=64,t=1,p=16{TAIL}"),
            format!("$argon2id$v=19$m=65536,t=0,p=1{TAIL}"),
            format!("$argon2id$v=19$t=4,m=65536,p=1{TAIL}"),
            format!("$argon2id$v=19$m=-1,t=4,p=1{TAIL}"),
            format!("$argon2id$v=20$m=65536,t=4,p=1{TAIL}"),
            format!("$argon2d$v=19$m=65536,t=4,p=1{TAIL}"),
            "$argon2id$v=19$m=8,t=1,p=1$YQ$YQ".to_owned(),
            "$argon2id$v=19$m=65536,t=4,p=1$YWJj$KjIALjgKcbZxSfJh1RkK6/9wqD+WbGVRjzTJ3BIQaBg".to_owned(),
            "$argon2id$v=19$m=65536,t=4,p=1$TmtWSjZ3aE5keWNEdFlhcg$YWI".to_owned(),
            "$argon2id$v=19$m=65536,t=4,p=1$TmtWSjZ3aE5keWNEdFlhcg$".to_owned(),
            "$argon2id$v=19$m=65536,t=4,p=1$TmtWSjZ3aE5keWNEdFlhcg$KjIALjgKcbZxSfJh1RkK6/9wqD+WbGVRjzTJ3BIQaBgaa"
                .to_owned(),
        ] {
            assert!(!Argon2.is_valid(&json!(bad)), "{bad}");
        }
    }

    #[test]
    fn phpass() {
        assert!(PHPass.is_valid(&json!("$P$Br387rwferoKN7uwHZqNMu98q3U8RO.")));
        assert!(PHPass.is_valid(&json!(format!("$2a$10${DIGEST}"))));
        for bad in [
            "$P$Sr387rwferoKN7uwHZqNMu98q3U8RO.",
            "$P$4r387rwferoKN7uwHZqNMu98q3U8RO.",
            "$P$-r387rwferoKN7uwHZqNMu98q3U8RO.",
            "$P$Br387rwferoKN7uwHZqNMu98q3U8RO",
            "$S$Br387rwferoKN7uwHZqNMu98q3U8RO.",
            "_zzzzsaltiAm7ZTrEBv.k",
        ] {
            assert!(!PHPass.is_valid(&json!(bad)), "{bad}");
        }
        assert!(!PHPass.is_valid(&json!(format!("$2a$31${DIGEST}"))));
    }
}
