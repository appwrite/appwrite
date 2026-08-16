use crate::error::{Error, Result};
use crate::wire::{push_u16, read_u16};

/// DNS header. PHP `Utopia\DNS\Message\Header`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Header {
    pub id: u16,
    pub is_response: bool,
    pub opcode: u8,
    pub authoritative: bool,
    pub truncated: bool,
    pub recursion_desired: bool,
    pub recursion_available: bool,
    pub response_code: u8,
    pub question_count: u16,
    pub answer_count: u16,
    pub authority_count: u16,
    pub additional_count: u16,
}

impl Header {
    pub const LENGTH: usize = 12;

    /// PHP `Header::__construct`.
    #[allow(clippy::fn_params_excessive_bools)]
    pub fn new(
        id: u16,
        is_response: bool,
        opcode: u8,
        authoritative: bool,
        truncated: bool,
        recursion_desired: bool,
        recursion_available: bool,
        response_code: u8,
        question_count: u16,
        answer_count: u16,
        authority_count: u16,
        additional_count: u16,
    ) -> Result<Self> {
        if opcode > 15 {
            return Err(Error::decoding("Opcode must be 0-15"));
        }
        if response_code > 15 {
            return Err(Error::decoding("Response code must be 0-15"));
        }
        Ok(Self {
            id,
            is_response,
            opcode,
            authoritative,
            truncated,
            recursion_desired,
            recursion_available,
            response_code,
            question_count,
            answer_count,
            authority_count,
            additional_count,
        })
    }

    /// PHP `Header::decode`.
    pub fn decode(data: &[u8], offset: usize) -> Result<Self> {
        if data.len() < offset + Self::LENGTH {
            return Err(Error::decoding("DNS header too short"));
        }
        let id = read_u16(data, offset)?;
        let flags = read_u16(data, offset + 2)?;
        let qdcount = read_u16(data, offset + 4)?;
        let ancount = read_u16(data, offset + 6)?;
        let nscount = read_u16(data, offset + 8)?;
        let arcount = read_u16(data, offset + 10)?;

        // Z bits (4-6) are ignored for interoperability with Google and others.
        Self::new(
            id,
            (flags >> 15) & 0x1 == 1,
            ((flags >> 11) & 0xF) as u8,
            (flags >> 10) & 0x1 == 1,
            (flags >> 9) & 0x1 == 1,
            (flags >> 8) & 0x1 == 1,
            (flags >> 7) & 0x1 == 1,
            (flags & 0xF) as u8,
            qdcount,
            ancount,
            nscount,
            arcount,
        )
    }

    /// PHP `Header::encode`.
    #[must_use]
    pub fn encode(&self) -> Vec<u8> {
        let flags = u16::from(self.is_response) << 15
            | u16::from(self.opcode & 0xF) << 11
            | u16::from(self.authoritative) << 10
            | u16::from(self.truncated) << 9
            | u16::from(self.recursion_desired) << 8
            | u16::from(self.recursion_available) << 7
            | u16::from(self.response_code & 0xF);
        let mut buf = Vec::with_capacity(Self::LENGTH);
        push_u16(&mut buf, self.id);
        push_u16(&mut buf, flags);
        push_u16(&mut buf, self.question_count);
        push_u16(&mut buf, self.answer_count);
        push_u16(&mut buf, self.authority_count);
        push_u16(&mut buf, self.additional_count);
        buf
    }
}
