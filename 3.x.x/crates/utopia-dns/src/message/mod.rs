pub mod domain;
pub mod header;
pub mod question;
pub mod record;

pub use domain::Domain;
pub use header::Header;
pub use question::Question;
pub use record::Record;

use crate::error::{Error, Result};

/// DNS message. PHP `Utopia\DNS\Message`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Message {
    pub header: Header,
    pub questions: Vec<Question>,
    pub answers: Vec<Record>,
    pub authority: Vec<Record>,
    pub additional: Vec<Record>,
}

impl Message {
    /// Maximum DNS message size per RFC 1035 Section 4.2.2.
    pub const MAX_SIZE: usize = 65_535;
    /// Maximum UDP payload size per RFC 1035 Section 4.2.1 (without EDNS0).
    pub const MAX_UDP_SIZE: usize = 512;

    pub const RCODE_NOERROR: u8 = 0;
    pub const RCODE_FORMERR: u8 = 1;
    pub const RCODE_SERVFAIL: u8 = 2;
    pub const RCODE_NXDOMAIN: u8 = 3;
    pub const RCODE_NOTIMP: u8 = 4;
    pub const RCODE_REFUSED: u8 = 5;
    pub const RCODE_YXDOMAIN: u8 = 6;
    pub const RCODE_YXRRSET: u8 = 7;
    pub const RCODE_NXRRSET: u8 = 8;
    pub const RCODE_NOTAUTH: u8 = 9;
    pub const RCODE_NOTZONE: u8 = 10;

    /// PHP `Message::__construct`.
    pub fn new(
        header: Header,
        questions: Vec<Question>,
        answers: Vec<Record>,
        authority: Vec<Record>,
        additional: Vec<Record>,
    ) -> Result<Self> {
        if usize::from(header.question_count) != questions.len() {
            return Err(Error::invalid(
                "Invalid DNS response: question count mismatch",
            ));
        }
        if usize::from(header.answer_count) != answers.len() {
            return Err(Error::invalid(
                "Invalid DNS response: answer count mismatch",
            ));
        }
        if usize::from(header.authority_count) != authority.len() {
            return Err(Error::invalid(
                "Invalid DNS response: authority count mismatch",
            ));
        }
        if usize::from(header.additional_count) != additional.len() {
            return Err(Error::invalid(
                "Invalid DNS response: additional count mismatch",
            ));
        }

        let soa_authority_count = authority
            .iter()
            .filter(|r| r.type_code == Record::TYPE_SOA)
            .count();

        if header.is_response
            && header.authoritative
            && !header.truncated
            && soa_authority_count < 1
        {
            if header.response_code == Self::RCODE_NXDOMAIN {
                return Err(Error::invalid("NXDOMAIN requires SOA in authority"));
            }
            if header.response_code == Self::RCODE_NOERROR && answers.is_empty() {
                return Err(Error::invalid("NODATA should include SOA in authority"));
            }
        }

        Ok(Self {
            header,
            questions,
            answers,
            authority,
            additional,
        })
    }

    /// PHP `Message::query`.
    pub fn query(question: Question, id: Option<u16>, recursion_desired: bool) -> Result<Self> {
        let id = id.unwrap_or_else(rand::random);
        let header = Header::new(
            id,
            false,
            0,
            false,
            false,
            recursion_desired,
            false,
            0,
            1,
            0,
            0,
            0,
        )?;
        Self::new(header, vec![question], Vec::new(), Vec::new(), Vec::new())
    }

    /// PHP `Message::response`.
    #[allow(clippy::too_many_arguments)]
    pub fn response(
        header: &Header,
        response_code: u8,
        questions: Vec<Question>,
        answers: Vec<Record>,
        authority: Vec<Record>,
        additional: Vec<Record>,
        authoritative: bool,
        truncated: bool,
        recursion_available: bool,
    ) -> Result<Self> {
        let header = Header::new(
            header.id,
            true,
            header.opcode,
            authoritative,
            truncated,
            header.recursion_desired,
            recursion_available,
            response_code,
            u16::try_from(questions.len()).unwrap_or(u16::MAX),
            u16::try_from(answers.len()).unwrap_or(u16::MAX),
            u16::try_from(authority.len()).unwrap_or(u16::MAX),
            u16::try_from(additional.len()).unwrap_or(u16::MAX),
        )?;
        Self::new(header, questions, answers, authority, additional)
    }

    /// PHP `Message::decode`.
    pub fn decode(packet: &[u8]) -> Result<Self> {
        if packet.len() < Header::LENGTH {
            return Err(Error::decoding("Invalid DNS response: header too short"));
        }
        let header = Header::decode(packet, 0)?;
        let decoded = (|| {
            let mut offset = Header::LENGTH;
            let mut questions = Vec::new();
            for _ in 0..header.question_count {
                questions.push(Question::decode(packet, &mut offset)?);
            }
            let mut answers = Vec::new();
            for _ in 0..header.answer_count {
                answers.push(Record::decode(packet, &mut offset)?);
            }
            let mut authority = Vec::new();
            for _ in 0..header.authority_count {
                authority.push(Record::decode(packet, &mut offset)?);
            }
            let mut additional = Vec::new();
            for _ in 0..header.additional_count {
                additional.push(Record::decode(packet, &mut offset)?);
            }
            if offset != packet.len() {
                return Err(Error::decoding("Invalid packet length"));
            }
            Ok((questions, answers, authority, additional))
        })();

        match decoded {
            Ok((questions, answers, authority, additional)) => {
                Self::new(header, questions, answers, authority, additional)
            }
            Err(Error::Decoding(msg)) => Err(Error::partial(header, msg)),
            Err(other) => Err(other),
        }
    }

    /// PHP `Message::encode`.
    pub fn encode(&self, max_size: Option<usize>) -> Result<Vec<u8>> {
        let mut packet = self.header.encode();
        for question in &self.questions {
            packet.extend(question.encode()?);
        }

        let mut answer_count = 0usize;
        for answer in &self.answers {
            let encoded = answer.encode()?;
            if max_size.is_some_and(|max| packet.len() + encoded.len() > max) {
                break;
            }
            packet.extend(encoded);
            answer_count += 1;
        }
        let answers_truncated = answer_count < self.answers.len();

        let mut authority_count = 0usize;
        let mut additional_count = 0usize;
        if !answers_truncated {
            let with_authority = append_records(&packet, &self.authority)?;
            if max_size.map_or(true, |max| with_authority.len() <= max) {
                packet = with_authority;
                authority_count = self.authority.len();
                let with_additional = append_records(&packet, &self.additional)?;
                if max_size.map_or(true, |max| with_additional.len() <= max) {
                    packet = with_additional;
                    additional_count = self.additional.len();
                }
            }
        }

        let sections_unchanged = answer_count == self.answers.len()
            && authority_count == self.authority.len()
            && additional_count == self.additional.len();
        if sections_unchanged {
            return Ok(packet);
        }

        let authority_dropped = authority_count < self.authority.len();
        let is_nodata_or_nxdomain = (self.header.response_code == Self::RCODE_NOERROR
            && self.answers.is_empty())
            || self.header.response_code == Self::RCODE_NXDOMAIN;
        let authoritative = if authority_dropped && is_nodata_or_nxdomain {
            false
        } else {
            self.header.authoritative
        };

        let header = Header::new(
            self.header.id,
            self.header.is_response,
            self.header.opcode,
            authoritative,
            answers_truncated || self.header.truncated,
            self.header.recursion_desired,
            self.header.recursion_available,
            self.header.response_code,
            u16::try_from(self.questions.len()).unwrap_or(u16::MAX),
            u16::try_from(answer_count).unwrap_or(u16::MAX),
            u16::try_from(authority_count).unwrap_or(u16::MAX),
            u16::try_from(additional_count).unwrap_or(u16::MAX),
        )?;

        let mut out = header.encode();
        out.extend_from_slice(&packet[Header::LENGTH..]);
        Ok(out)
    }

    /// PHP `Message::validate`.
    pub fn validate(&self) -> Result<()> {
        for records in [&self.answers, &self.authority, &self.additional] {
            for record in records {
                record.validate_rdata()?;
            }
        }
        Ok(())
    }
}

fn append_records(packet: &[u8], records: &[Record]) -> Result<Vec<u8>> {
    let mut out = packet.to_vec();
    for record in records {
        out.extend(record.encode()?);
    }
    Ok(out)
}
