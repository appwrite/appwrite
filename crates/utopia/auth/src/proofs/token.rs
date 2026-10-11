//! Random hex tokens, such as those in magic links and recovery emails.

use super::{Proof, proof_hash};
use crate::hash::random_bytes;
use crate::hashes::Argon2;
use crate::{Error, Hash};

/// A random hex token, 256 characters by default.
///
/// PHP: `Utopia\Auth\Proofs\Token`.
///
/// ```
/// use utopia_auth::proofs::{Proof, Token};
///
/// let token = Token::new(64)?;
/// let secret = token.generate()?;
/// assert_eq!(secret.len(), 64);
/// assert!(secret.iter().all(u8::is_ascii_hexdigit));
/// # Ok::<(), utopia_auth::Error>(())
/// ```
///
/// ```php
/// use Utopia\Auth\Proofs\Token;
///
/// $secret = (new Token(64))->generate();
/// echo strlen($secret), "\n";                // 64
/// var_dump(ctype_xdigit($secret));          // bool(true)
/// ```
#[derive(Debug, Clone)]
pub struct Token {
    length: i64,
    hash: Box<dyn Hash>,
}

fn check(length: i64) -> Result<i64, Error> {
    if length <= 0 {
        return Err(Error::Exception("Token length must be greater than 0".into()));
    }
    Ok(length)
}

impl Token {
    /// Tokens of `length` hex characters, hashed with Argon2.
    ///
    /// Fails with [`Error::Exception`] unless `length` is positive.
    ///
    /// PHP: `new Token($length)`.
    pub fn new(length: i64) -> Result<Self, Error> {
        Ok(Self { length: check(length)?, hash: Box::new(Argon2::new()) })
    }

    /// The number of characters in a token.
    ///
    /// PHP: `getLength()`.
    pub fn length(&self) -> i64 {
        self.length
    }

    /// Sets the number of characters in a token.
    ///
    /// Fails with [`Error::Exception`] unless `length` is positive.
    ///
    /// PHP: `setLength()`.
    pub fn set_length(&mut self, length: i64) -> Result<&mut Self, Error> {
        self.length = check(length)?;
        Ok(self)
    }

    /// A new token: `length` random lowercase hex characters.
    ///
    /// PHP: `generate()`.
    pub fn token(&self) -> String {
        let length = usize::try_from(self.length).unwrap_or(0);
        let mut bytes = vec![0u8; length.div_ceil(2).max(1)];
        random_bytes(&mut bytes);
        let mut out = hex::encode(bytes);
        out.truncate(length);
        out
    }
}

impl Default for Token {
    /// Tokens of 256 characters, hashed with Argon2.
    fn default() -> Self {
        Self { length: 256, hash: Box::new(Argon2::new()) }
    }
}

impl Proof for Token {
    proof_hash!();

    /// A new token as bytes, the same as [`Token::token`].
    fn generate(&self) -> Result<Vec<u8>, Error> {
        Ok(self.token().into_bytes())
    }
}
