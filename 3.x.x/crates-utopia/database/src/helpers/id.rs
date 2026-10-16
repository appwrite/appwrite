//! PHP `Utopia\Database\Helpers\ID`.

use crate::error::{DatabaseError, Result};

/// PHP `Utopia\Database\Helpers\ID`.
#[derive(Debug, Clone, Copy)]
pub struct Id;

impl Id {
    /// PHP `ID::unique(int $padding = 7)`.
    pub fn unique() -> Result<String> {
        Self::unique_padded(7)
    }

    /// PHP `ID::unique` with an explicit padding length.
    pub fn unique_padded(padding: i32) -> Result<String> {
        let now = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap_or_default();
        let sec = now.as_secs();
        let usec = now.subsec_micros();
        let mut uniqid = format!("{sec:08x}{usec:05x}");
        if uniqid.len() > 13 {
            uniqid.truncate(13);
        }
        if padding > 0 {
            let n = (padding as usize).div_ceil(2).max(1);
            let mut bytes = vec![0u8; n];
            if getrandom(&mut bytes).is_err() {
                return Err(DatabaseError::database("Failed to generate random bytes"));
            }
            let hex = hex::encode(bytes);
            uniqid.push_str(&hex[..padding as usize]);
        }
        Ok(uniqid)
    }

    /// PHP `ID::custom(string $id)`.
    #[must_use]
    pub fn custom(id: impl Into<String>) -> String {
        id.into()
    }
}

fn getrandom(buf: &mut [u8]) -> std::result::Result<(), ()> {
    use rand::RngCore;
    rand::thread_rng().fill_bytes(buf);
    Ok(())
}
