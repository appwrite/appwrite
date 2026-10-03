use crate::error::{Error, Result};

/// Domain name codec. PHP `Utopia\DNS\Message\Domain`.
#[derive(Debug)]
pub struct Domain;

impl Domain {
    pub const MAX_LABEL_LEN: usize = 63;
    pub const MAX_LABELS: usize = 127;
    pub const MAX_DOMAIN_NAME_LEN: usize = 255;

    /// PHP `Domain::encode`.
    pub fn encode(name: &str) -> Result<Vec<u8>> {
        if name.is_empty() {
            return Ok(vec![0]);
        }
        if name.ends_with("..") {
            return Err(Error::invalid("Domain labels must not be empty"));
        }
        let trimmed = name.trim_end_matches('.');
        if trimmed.is_empty() {
            return Ok(vec![0]);
        }

        let labels: Vec<&str> = trimmed.split('.').collect();
        let label_count = labels.len();
        if label_count > Self::MAX_LABELS {
            return Err(Error::invalid(format!(
                "Domain has too many labels: {label_count}"
            )));
        }

        let mut encoded = Vec::new();
        let mut total_length = 0usize;
        for label in labels {
            if label.is_empty() {
                return Err(Error::invalid("Domain labels must not be empty"));
            }
            if label.contains('@') {
                return Err(Error::invalid("Domain label contains invalid characters"));
            }
            let label_length = label.len();
            if label_length > Self::MAX_LABEL_LEN {
                return Err(Error::invalid(format!("Label too long: {label}")));
            }
            encoded.push(u8::try_from(label_length).unwrap_or(u8::MAX));
            encoded.extend_from_slice(label.as_bytes());
            total_length += label_length + 1;
        }
        total_length += 1;
        if total_length > Self::MAX_DOMAIN_NAME_LEN {
            return Err(Error::invalid(format!(
                "Encoded domain exceeds maximum length of {} bytes",
                Self::MAX_DOMAIN_NAME_LEN
            )));
        }
        encoded.push(0);
        Ok(encoded)
    }

    /// PHP `Domain::decode`. Updates `offset` to the first byte after the name.
    pub fn decode(data: &[u8], offset: &mut usize) -> Result<String> {
        let mut labels: Vec<String> = Vec::new();
        let mut jumped = false;
        let mut pos = *offset;
        let data_length = data.len();
        let mut visited_pointers = std::collections::HashSet::new();
        let mut label_count = 0usize;

        loop {
            if pos >= data_length {
                return Err(Error::decoding(
                    "Unexpected end of data while decoding domain name",
                ));
            }
            let len = data[pos];
            if len == 0 {
                if !jumped {
                    *offset = pos + 1;
                }
                break;
            }

            if len & 0xC0 == 0xC0 {
                if pos + 1 >= data_length {
                    return Err(Error::decoding(
                        "Truncated compression pointer in domain name",
                    ));
                }
                let pointer = (usize::from(len & 0x3F) << 8) | usize::from(data[pos + 1]);
                if pointer >= pos {
                    return Err(Error::decoding(
                        "Compression pointer must reference earlier position in packet",
                    ));
                }
                if pointer >= data_length {
                    return Err(Error::decoding(
                        "Compression pointer out of bounds in domain name",
                    ));
                }
                if !visited_pointers.insert(pointer) {
                    return Err(Error::decoding(
                        "Compression pointer loop detected in domain name",
                    ));
                }
                if !jumped {
                    *offset = pos + 2;
                }
                pos = pointer;
                jumped = true;
                continue;
            }

            if len & 0xC0 != 0 {
                return Err(Error::decoding(
                    "Reserved label type encountered in domain name",
                ));
            }

            let label_len = usize::from(len);
            if pos + 1 + label_len > data_length {
                return Err(Error::decoding(
                    "Label length exceeds remaining data while decoding domain name",
                ));
            }
            let label_bytes = &data[pos + 1..pos + 1 + label_len];
            labels.push(String::from_utf8_lossy(label_bytes).into_owned());
            label_count += 1;
            pos += label_len + 1;
            if label_count > Self::MAX_LABELS {
                return Err(Error::decoding("Domain name exceeds maximum label count"));
            }
            if !jumped {
                *offset = pos;
            }
        }

        Ok(labels.join("."))
    }
}
