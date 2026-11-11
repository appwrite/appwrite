//! Numeric one-time codes, such as those sent by email or SMS.

use super::{Proof, proof_hash};
use crate::hash::random_int;
use crate::hashes::Argon2;
use crate::{Error, Hash};

/// A numeric one-time code, 6 digits by default.
///
/// PHP: `Utopia\Auth\Proofs\Code`.
///
/// ```
/// use utopia_auth::proofs::{Code, Proof};
///
/// let code = Code::new(8)?;
/// let secret = code.generate()?;
/// assert_eq!(secret.len(), 8);
/// assert!(secret.iter().all(u8::is_ascii_digit));
/// # Ok::<(), utopia_auth::Error>(())
/// ```
///
/// ```php
/// use Utopia\Auth\Proofs\Code;
///
/// $secret = (new Code(8))->generate();
/// echo strlen($secret), "\n";       // 8
/// var_dump(ctype_digit($secret));  // bool(true)
/// ```
#[derive(Debug, Clone)]
pub struct Code {
    length: i64,
    hash: Box<dyn Hash>,
}

fn check(length: i64) -> Result<i64, Error> {
    if length <= 0 {
        return Err(Error::Exception("Code length must be greater than 0".into()));
    }
    Ok(length)
}

impl Code {
    /// Codes of `length` digits, hashed with Argon2.
    ///
    /// Fails with [`Error::Exception`] unless `length` is positive.
    ///
    /// PHP: `new Code($length)`.
    pub fn new(length: i64) -> Result<Self, Error> {
        Ok(Self { length: check(length)?, hash: Box::new(Argon2::new()) })
    }

    /// The number of digits in a code.
    ///
    /// PHP: `getLength()`.
    pub fn length(&self) -> i64 {
        self.length
    }

    /// Sets the number of digits in a code.
    ///
    /// Fails with [`Error::Exception`] unless `length` is positive.
    ///
    /// PHP: `setLength()`.
    pub fn set_length(&mut self, length: i64) -> Result<&mut Self, Error> {
        self.length = check(length)?;
        Ok(self)
    }

    /// A new code: `length` random decimal digits.
    ///
    /// PHP: `generate()`.
    pub fn code(&self) -> String {
        (0..self.length).map(|_| char::from(b'0' + random_int(9) as u8)).collect()
    }
}

impl Default for Code {
    /// Codes of 6 digits, hashed with Argon2.
    fn default() -> Self {
        Self { length: 6, hash: Box::new(Argon2::new()) }
    }
}

impl Proof for Code {
    proof_hash!();

    /// A new code as bytes, the same as [`Code::code`].
    fn generate(&self) -> Result<Vec<u8>, Error> {
        Ok(self.code().into_bytes())
    }
}
