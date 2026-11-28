//! Document identifiers: [`unique`] generates one, [`custom`] takes one given.
//!
//! PHP: `Utopia\Database\Helpers\ID`.

use crate::error::{Error, Result};

/// The random hex characters [`unique`] appends to the time-based part.
pub const PADDING: i64 = 7;

/// A new unique ID: 13 hex characters of the current time (`uniqid()`),
/// then [`PADDING`] random hex characters (20 characters in all).
///
/// ```
/// use utopia_database::helpers::id;
///
/// let id = id::unique().unwrap();
/// assert_eq!(id.len(), 20);
/// assert!(id.bytes().all(|c| c.is_ascii_hexdigit()));
/// ```
///
/// PHP: `ID::unique()`.
pub fn unique() -> Result<String> {
    unique_padded(PADDING)
}

/// A new unique ID with `padding` random hex characters after the 13 of
/// `uniqid()` (none when `padding` is 0 or less).
///
/// Fails like PHP when the system cannot provide random bytes.
///
/// PHP: `ID::unique($padding)`.
pub fn unique_padded(padding: i64) -> Result<String> {
    let mut id = php_std::system::uniqid("", false);
    if padding > 0 {
        // One random byte is two hex characters: max(1, ceil(padding / 2)) bytes.
        let len = usize::try_from(padding / 2 + padding % 2)
            .map_err(|_| Error::database("Could not gather sufficient random data"))?;
        let mut bytes = vec![0u8; len.max(1)];
        getrandom::fill(&mut bytes).map_err(|_| Error::database("Could not gather sufficient random data"))?;
        let hex = hex::encode(bytes);
        id.push_str(&hex[..hex.len().min(padding as usize)]);
    }
    Ok(id)
}

/// An ID given by the caller, as it is.
///
/// PHP: `ID::custom($id)`.
pub fn custom(id: impl Into<String>) -> String {
    id.into()
}
