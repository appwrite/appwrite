use crate::error::{Error, Result};
use crate::message::domain::Domain;
use crate::message::record::Record;
use crate::wire::{normalize_name, push_u16, read_u16};

/// DNS question. PHP `Utopia\DNS\Message\Question`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Question {
    pub name: String,
    pub type_code: u16,
    pub class: u16,
}

impl Question {
    /// PHP `Question::__construct`. Name is trimmed and lowercased.
    #[must_use]
    pub fn new(name: impl AsRef<str>, type_code: u16) -> Self {
        Self::with_class(name, type_code, Record::CLASS_IN)
    }

    #[must_use]
    pub fn with_class(name: impl AsRef<str>, type_code: u16, class: u16) -> Self {
        Self {
            name: normalize_name(name.as_ref()),
            type_code,
            class,
        }
    }

    /// PHP `Question::decode`.
    pub fn decode(data: &[u8], offset: &mut usize) -> Result<Self> {
        let name = Domain::decode(data, offset)?;
        let remaining = data.len().saturating_sub(*offset);
        if remaining < 4 {
            return Err(Error::decoding("Question section truncated"));
        }
        let type_code = read_u16(data, *offset)
            .map_err(|_| Error::decoding("Failed to unpack question type"))?;
        *offset += 2;
        let class = read_u16(data, *offset)
            .map_err(|_| Error::decoding("Failed to unpack question class"))?;
        *offset += 2;
        Ok(Self::with_class(name, type_code, class))
    }

    /// PHP `Question::encode`.
    pub fn encode(&self) -> Result<Vec<u8>> {
        let mut data = Domain::encode(&self.name)?;
        push_u16(&mut data, self.type_code);
        push_u16(&mut data, self.class);
        Ok(data)
    }
}
