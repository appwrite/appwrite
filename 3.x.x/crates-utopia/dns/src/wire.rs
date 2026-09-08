//! Big-endian DNS wire helpers.

use crate::error::{Error, Result};

pub fn read_u16(data: &[u8], offset: usize) -> Result<u16> {
    let slice = data
        .get(offset..offset + 2)
        .ok_or_else(|| Error::decoding("truncated integer"))?;
    Ok(u16::from_be_bytes([slice[0], slice[1]]))
}

pub fn read_u32(data: &[u8], offset: usize) -> Result<u32> {
    let slice = data
        .get(offset..offset + 4)
        .ok_or_else(|| Error::decoding("truncated integer"))?;
    Ok(u32::from_be_bytes([slice[0], slice[1], slice[2], slice[3]]))
}

pub fn push_u16(buf: &mut Vec<u8>, value: u16) {
    buf.extend_from_slice(&value.to_be_bytes());
}

pub fn push_u32(buf: &mut Vec<u8>, value: u32) {
    buf.extend_from_slice(&value.to_be_bytes());
}

/// PHP `trim()` default character mask: space, tab, LF, CR, NUL, VT.
pub fn php_trim(s: &str) -> &str {
    s.trim_matches(|c: char| matches!(c, ' ' | '\t' | '\n' | '\r' | '\0' | '\u{0B}'))
}

pub fn normalize_name(name: &str) -> String {
    php_trim(name).to_ascii_lowercase()
}
