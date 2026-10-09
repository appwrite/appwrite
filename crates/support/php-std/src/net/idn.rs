//! `idn_to_ascii` / `idn_to_utf8` (intl, ICU UTS #46).

use std::fmt;

/// `IDNA_*` option flags.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct Flags(i64);

impl Flags {
    pub fn from_bits(bits: i64) -> Self {
        Flags(bits)
    }
}

/// An argument error (`ValueError`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Error {
    message: String,
}

impl Error {
    pub fn php_class(&self) -> &'static str {
        "ValueError"
    }
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.message)
    }
}

pub fn to_ascii(_domain: &[u8], _flags: Flags) -> Result<Option<Vec<u8>>, Error> {
    Ok(None)
}

pub fn to_utf8(_domain: &[u8], _flags: Flags) -> Result<Option<Vec<u8>>, Error> {
    Ok(None)
}
