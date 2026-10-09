//! How the hashes call PHP's `password_hash()`, `password_verify()` and
//! `crypt()` (implemented in `php_std::string`): the options they pass and
//! the random salt PHP draws.

use crate::Error;
use crate::hash::random_bytes;
use crate::options::Options;

fn engine(e: php_std::string::Error) -> Error {
    match e {
        php_std::string::Error::Value(m) => Error::Value(m),
        php_std::string::Error::Type(m) => Error::Type(m),
        other => Error::Engine(other.message().to_owned()),
    }
}

fn salt() -> [u8; 16] {
    let mut salt = [0u8; 16];
    random_bytes(&mut salt);
    salt
}

/// `password_hash($value, PASSWORD_BCRYPT, $options)`: reads `cost` (12 when absent).
pub(crate) fn hash_bcrypt(value: &[u8], options: &Options) -> Result<Vec<u8>, Error> {
    php_std::string::password_hash_bcrypt(value, options.long_or("cost", 12), &salt()).map_err(engine)
}

/// `password_hash($value, PASSWORD_ARGON2ID, $options)`: reads `memory_cost`,
/// `time_cost` and `threads` (PHP's defaults when absent).
pub(crate) fn hash_argon2id(value: &[u8], options: &Options) -> Result<Vec<u8>, Error> {
    php_std::string::password_hash_argon2id(
        value,
        options.long_or("memory_cost", 65536),
        options.long_or("time_cost", 4),
        options.long_or("threads", 1),
        &salt(),
    )
    .map_err(engine)
}

/// `password_verify($value, $hash)`.
pub(crate) fn verify(value: &[u8], hash: &[u8]) -> bool {
    php_std::string::password_verify(value, hash)
}

/// `crypt($password, $salt)`.
pub(crate) fn crypt(password: &[u8], salt: &[u8]) -> Vec<u8> {
    php_std::string::crypt(password, salt)
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
            crypt(b"x", b"$2a$04$abcdefghijklmnopqrstuu"),
            b"$2a$04$abcdefghijklmnopqrstuuPp7HPfoAs8I2dCQCQ/fW7zEJv8I8C8e".to_vec()
        );
        // From PHP's crypt().
        assert_eq!(crypt(b"x", b"$1$abc$"), b"$1$abc$OGyl6dDvZCDiGmIVbeuCq/".to_vec());
        assert_eq!(crypt(b"x", b"$5$abc$"), b"$5$abc$sCOGUciM.GdmYMCUvmUcgu03oRH87O0clF96.9mwWGB".to_vec());
        assert_eq!(
            crypt(b"x", b"$6$rounds=1000$abc$"),
            b"$6$rounds=1000$abc$zaWpAwySRl8PX4W2aEMJwxpN82bCKtDZP0RBdOD6W7BQlilBqAsWnAZuS10iUyJZneS8Ob1gxs1BZkqJi1nTi."
                .to_vec()
        );
        assert_eq!(crypt(b"x", b"ab"), b"abiQ6Ep3EYTHc".to_vec());
        assert_eq!(crypt(b"x", b"_J9..abcd"), b"_J9..abcd5WNy9VUCfAY".to_vec());
        assert!(verify(b"x", b"abiQ6Ep3EYTHc"));
        assert_eq!(crypt(b"x", b"*0"), b"*1".to_vec());
        assert_eq!(crypt(b"x", b""), b"*0".to_vec());
    }
}
