//! The question section (RFC 1035 §4.1.2).

use php_std::Str;

use super::{Record, domain};
use crate::Error;

/// A question: a name, a record type and a class.
///
/// PHP: `Utopia\DNS\Message\Question`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Question {
    name: Str,
    /// The record type asked for (`Record::TYPE_*`).
    pub ty: u16,
    /// The class (`Record::CLASS_*`).
    pub class: u16,
}

impl Question {
    /// A question for `name` (lower-cased, then trimmed of white space and
    /// NUL bytes) in class `IN`.
    ///
    /// PHP: `new Question($name, $type)`.
    pub fn new(name: impl AsRef<[u8]>, ty: u16) -> Question {
        Question::with_class(name, ty, Record::CLASS_IN)
    }

    /// A question in another class.
    ///
    /// PHP: `new Question($name, $type, $class)`.
    pub fn with_class(name: impl AsRef<[u8]>, ty: u16, class: u16) -> Question {
        Question { name: normalize(name.as_ref()), ty, class }
    }

    /// The name: lower case, without surrounding white space.
    pub fn name(&self) -> &[u8] {
        &self.name
    }

    /// Decodes the question at `offset`, returning it and the offset after it.
    ///
    /// Fails with [`Error::Decoding`].
    ///
    /// PHP: `Question::decode()`.
    pub fn decode(data: &[u8], offset: usize) -> Result<(Question, usize), Error> {
        let (name, offset) = domain::decode(data, offset)?;
        let Some(fixed) = data.get(offset..offset + 4) else {
            return Err(Error::decoding("Question section truncated"));
        };
        let ty = u16::from_be_bytes([fixed[0], fixed[1]]);
        let class = u16::from_be_bytes([fixed[2], fixed[3]]);
        Ok((Question { name: normalize(&name), ty, class }, offset + 4))
    }

    /// The question in wire format.
    ///
    /// Fails with [`Error::InvalidArgument`] when the name cannot be encoded.
    ///
    /// PHP: `encode()`.
    pub fn encode(&self) -> Result<Vec<u8>, Error> {
        let mut out = Vec::with_capacity(self.name.len() + 6);
        self.encode_into(&mut out)?;
        Ok(out)
    }

    pub(crate) fn encode_into(&self, out: &mut Vec<u8>) -> Result<(), Error> {
        domain::encode_into(&self.name, out)?;
        out.extend_from_slice(&self.ty.to_be_bytes());
        out.extend_from_slice(&self.class.to_be_bytes());
        Ok(())
    }
}

/// `trim(strtolower($name))`.
pub(crate) fn normalize(name: &[u8]) -> Str {
    let trimmed = php_std::string::trim(name, php_std::string::TRIM_CHARACTERS);
    if trimmed.iter().any(u8::is_ascii_uppercase) {
        Str::from(trimmed.to_ascii_lowercase())
    } else {
        Str::copy_from(trimmed)
    }
}
