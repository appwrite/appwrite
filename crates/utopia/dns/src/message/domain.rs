//! Domain names in DNS wire format (RFC 1035 §3.1, §4.1.4).

use crate::Error;
use crate::error::quote;

/// The longest label.
pub const MAX_LABEL_LEN: usize = 63;
/// The most labels a name has.
pub const MAX_LABELS: usize = 127;
/// The longest encoded name, length octets and the root included.
pub const MAX_DOMAIN_NAME_LEN: usize = 255;

/// Encodes a name as length-prefixed labels ending with the root. An empty
/// name, `.` or a name ending in one `.` is absolute; `@` and empty labels
/// are refused.
///
/// PHP: `Domain::encode()`.
pub fn encode(name: &[u8]) -> Result<Vec<u8>, Error> {
    let mut out = Vec::with_capacity(name.len() + 2);
    encode_into(name, &mut out)?;
    Ok(out)
}

/// [`encode`] appended to `out`.
pub fn encode_into(name: &[u8], out: &mut Vec<u8>) -> Result<(), Error> {
    if name.is_empty() {
        out.push(0);
        return Ok(());
    }
    if name.ends_with(b"..") {
        return Err(Error::invalid("Domain labels must not be empty"));
    }
    let end = name.iter().rposition(|&b| b != b'.').map_or(0, |i| i + 1);
    let trimmed = &name[..end];
    if trimmed.is_empty() {
        out.push(0);
        return Ok(());
    }
    let count = trimmed.iter().filter(|&&b| b == b'.').count() + 1;
    if count > MAX_LABELS {
        return Err(Error::invalid(format!("Domain has too many labels: {count}")));
    }
    let start = out.len();
    let mut total = 0usize;
    for label in trimmed.split(|&b| b == b'.') {
        if label.is_empty() {
            out.truncate(start);
            return Err(Error::invalid("Domain labels must not be empty"));
        }
        if label.contains(&b'@') {
            out.truncate(start);
            return Err(Error::invalid("Domain label contains invalid characters"));
        }
        if label.len() > MAX_LABEL_LEN {
            out.truncate(start);
            return Err(Error::InvalidArgument(quote("Label too long: ", label)));
        }
        out.push(label.len() as u8);
        out.extend_from_slice(label);
        total += label.len() + 1;
    }
    if total + 1 > MAX_DOMAIN_NAME_LEN {
        out.truncate(start);
        return Err(Error::invalid(format!("Encoded domain exceeds maximum length of {MAX_DOMAIN_NAME_LEN} bytes")));
    }
    out.push(0);
    Ok(())
}

/// Decodes the name at `offset` in a whole packet, following compression
/// pointers (which must point backwards, and never to the same place twice).
/// Returns the dotted name and the offset after it in the packet.
///
/// PHP: `Domain::decode()`.
pub fn decode(data: &[u8], offset: usize) -> Result<(Vec<u8>, usize), Error> {
    let mut name = Vec::with_capacity(32);
    let mut next = offset;
    let mut jumped = false;
    let mut pos = offset;
    let mut visited: Vec<usize> = Vec::new();
    let mut labels = 0usize;
    loop {
        let Some(&len) = data.get(pos) else {
            return Err(Error::decoding("Unexpected end of data while decoding domain name"));
        };
        if len == 0 {
            if !jumped {
                next = pos + 1;
            }
            break;
        }
        if len & 0xC0 == 0xC0 {
            let Some(&low) = data.get(pos + 1) else {
                return Err(Error::decoding("Truncated compression pointer in domain name"));
            };
            let pointer = (usize::from(len & 0x3F) << 8) | usize::from(low);
            if pointer >= pos {
                return Err(Error::decoding("Compression pointer must reference earlier position in packet"));
            }
            if visited.contains(&pointer) {
                return Err(Error::decoding("Compression pointer loop detected in domain name"));
            }
            visited.push(pointer);
            if !jumped {
                next = pos + 2;
            }
            pos = pointer;
            jumped = true;
            continue;
        }
        if len & 0xC0 != 0 {
            return Err(Error::decoding("Reserved label type encountered in domain name"));
        }
        let len = usize::from(len);
        if pos + 1 + len > data.len() {
            return Err(Error::decoding("Label length exceeds remaining data while decoding domain name"));
        }
        if labels > 0 {
            name.push(b'.');
        }
        name.extend_from_slice(&data[pos + 1..pos + 1 + len]);
        labels += 1;
        pos += len + 1;
        if labels > MAX_LABELS {
            return Err(Error::decoding("Domain name exceeds maximum label count"));
        }
        if !jumped {
            next = pos;
        }
    }
    Ok((name, next))
}
