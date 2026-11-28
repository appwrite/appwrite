//! The 12-byte message header (RFC 1035 §4.1.1).

use crate::Error;

/// A message header: the id, flags and section counts.
///
/// The reserved Z bits are ignored when decoding, as many clients and
/// proxies set them.
///
/// PHP: `Utopia\DNS\Message\Header`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct Header {
    /// The id a response repeats from its query.
    pub id: u16,
    /// `QR`: a response rather than a query.
    pub is_response: bool,
    /// `OPCODE`, 0..=15 (0 is a standard query).
    pub opcode: u8,
    /// `AA`: the answer is authoritative.
    pub authoritative: bool,
    /// `TC`: the message was truncated.
    pub truncated: bool,
    /// `RD`: recursion is desired.
    pub recursion_desired: bool,
    /// `RA`: recursion is available.
    pub recursion_available: bool,
    /// `RCODE`, 0..=15.
    pub response_code: u8,
    /// `QDCOUNT`.
    pub question_count: u16,
    /// `ANCOUNT`.
    pub answer_count: u16,
    /// `NSCOUNT`.
    pub authority_count: u16,
    /// `ARCOUNT`.
    pub additional_count: u16,
}

impl Header {
    /// The header length in bytes.
    pub const LENGTH: usize = 12;

    /// Checks the 4-bit fields.
    ///
    /// Fails with [`Error::Decoding`] (`Opcode must be 0-15`, `Response code
    /// must be 0-15`).
    ///
    /// PHP: the checks of `new Header(...)`.
    pub fn validate(&self) -> Result<(), Error> {
        if self.opcode > 15 {
            return Err(Error::decoding("Opcode must be 0-15"));
        }
        if self.response_code > 15 {
            return Err(Error::decoding("Response code must be 0-15"));
        }
        Ok(())
    }

    /// Decodes the header at `offset`.
    ///
    /// Fails with [`Error::Decoding`] (`DNS header too short`).
    ///
    /// PHP: `Header::decode()`.
    pub fn decode(data: &[u8], offset: usize) -> Result<Header, Error> {
        let Some(b) = data.get(offset..offset + Self::LENGTH) else {
            return Err(Error::decoding("DNS header too short"));
        };
        let word = |i: usize| u16::from_be_bytes([b[i], b[i + 1]]);
        let flags = word(2);
        Ok(Header {
            id: word(0),
            is_response: flags >> 15 & 1 == 1,
            opcode: (flags >> 11 & 0xF) as u8,
            authoritative: flags >> 10 & 1 == 1,
            truncated: flags >> 9 & 1 == 1,
            recursion_desired: flags >> 8 & 1 == 1,
            recursion_available: flags >> 7 & 1 == 1,
            response_code: (flags & 0xF) as u8,
            question_count: word(4),
            answer_count: word(6),
            authority_count: word(8),
            additional_count: word(10),
        })
    }

    /// The header in wire format.
    ///
    /// PHP: `encode()`.
    pub fn encode(&self) -> [u8; Self::LENGTH] {
        let flags = u16::from(self.is_response) << 15
            | u16::from(self.opcode & 0xF) << 11
            | u16::from(self.authoritative) << 10
            | u16::from(self.truncated) << 9
            | u16::from(self.recursion_desired) << 8
            | u16::from(self.recursion_available) << 7
            | u16::from(self.response_code & 0xF);
        let mut out = [0u8; Self::LENGTH];
        for (i, word) in
            [self.id, flags, self.question_count, self.answer_count, self.authority_count, self.additional_count]
                .into_iter()
                .enumerate()
        {
            out[i * 2..i * 2 + 2].copy_from_slice(&word.to_be_bytes());
        }
        out
    }
}
