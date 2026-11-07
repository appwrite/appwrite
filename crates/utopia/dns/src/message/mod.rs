//! DNS messages (RFC 1035 §4): the header, questions and the answer,
//! authority and additional records, in wire format.

pub mod domain;
mod header;
mod question;
mod record;

pub use header::Header;
pub use question::Question;
pub use record::Record;

use crate::Error;

/// The largest message: TCP frames carry a 2-byte length (RFC 1035 §4.2.2).
pub const MAX_SIZE: usize = 65535;
/// The largest UDP payload without EDNS0 (RFC 1035 §4.2.1).
pub const MAX_UDP_SIZE: usize = 512;

/// `NOERROR`
pub const RCODE_NOERROR: u8 = 0;
/// `FORMERR`: the query could not be interpreted.
pub const RCODE_FORMERR: u8 = 1;
/// `SERVFAIL`: the server failed.
pub const RCODE_SERVFAIL: u8 = 2;
/// `NXDOMAIN`: the name does not exist.
pub const RCODE_NXDOMAIN: u8 = 3;
/// `NOTIMP`: the kind of query is not supported.
pub const RCODE_NOTIMP: u8 = 4;
/// `REFUSED`
pub const RCODE_REFUSED: u8 = 5;
/// `YXDOMAIN`
pub const RCODE_YXDOMAIN: u8 = 6;
/// `YXRRSET`
pub const RCODE_YXRRSET: u8 = 7;
/// `NXRRSET`
pub const RCODE_NXRRSET: u8 = 8;
/// `NOTAUTH`
pub const RCODE_NOTAUTH: u8 = 9;
/// `NOTZONE`
pub const RCODE_NOTZONE: u8 = 10;

/// A DNS message: a header and four sections whose sizes the header counts.
///
/// [`Message::new`] checks that the counts match the sections, and that an
/// authoritative, complete `NXDOMAIN` or empty `NOERROR` response carries
/// the zone's `SOA` in its authority section (RFC 2308).
///
/// ```
/// use utopia_dns::message::{Message, Question, Record, RCODE_NOERROR};
///
/// let query = Message::query(Question::new("example.com", Record::TYPE_A), Some(0x1234), true);
/// let response = Message::response(query.header(), RCODE_NOERROR)
///     .questions(query.questions().to_vec())
///     .answers(vec![Record::new("example.com", Record::TYPE_A).with_ttl(60).with_rdata("192.0.2.1")])
///     .authoritative(true)
///     .build()?;
///
/// let wire = response.encode(Some(512))?;
/// let decoded = Message::decode(&wire)?;
/// assert_eq!(decoded.header().id, 0x1234);
/// assert_eq!(decoded.answers()[0].rdata(), b"192.0.2.1");
/// # Ok::<(), utopia_dns::Error>(())
/// ```
///
/// ```php
/// use Utopia\DNS\Message;
/// use Utopia\DNS\Message\Question;
/// use Utopia\DNS\Message\Record;
///
/// $query = Message::query(new Question('example.com', Record::TYPE_A), id: 0x1234);
/// $response = Message::response(
///     $query->header,
///     Message::RCODE_NOERROR,
///     questions: $query->questions,
///     answers: [new Record('example.com', Record::TYPE_A, ttl: 60, rdata: '192.0.2.1')],
///     authoritative: true,
/// );
///
/// $decoded = Message::decode($response->encode(512));
/// echo $decoded->header->id, "\n";        // 4660
/// echo $decoded->answers[0]->rdata, "\n"; // 192.0.2.1
/// ```
///
/// PHP: `Utopia\DNS\Message`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Message {
    header: Header,
    questions: Vec<Question>,
    answers: Vec<Record>,
    authority: Vec<Record>,
    additional: Vec<Record>,
}

impl Message {
    /// A message of these sections.
    ///
    /// Fails with [`Error::Decoding`] when the header's opcode or response
    /// code is out of range, and [`Error::InvalidArgument`] when a count
    /// does not match its section or a negative authoritative answer lacks
    /// its `SOA`.
    ///
    /// PHP: `new Message(...)`.
    pub fn new(
        header: Header,
        questions: Vec<Question>,
        answers: Vec<Record>,
        authority: Vec<Record>,
        additional: Vec<Record>,
    ) -> Result<Message, Error> {
        header.validate()?;
        if usize::from(header.question_count) != questions.len() {
            return Err(Error::invalid("Invalid DNS response: question count mismatch"));
        }
        if usize::from(header.answer_count) != answers.len() {
            return Err(Error::invalid("Invalid DNS response: answer count mismatch"));
        }
        if usize::from(header.authority_count) != authority.len() {
            return Err(Error::invalid("Invalid DNS response: authority count mismatch"));
        }
        if usize::from(header.additional_count) != additional.len() {
            return Err(Error::invalid("Invalid DNS response: additional count mismatch"));
        }
        // TC=1 signals an incomplete response: the client retries over TCP,
        // so the SOA a negative answer needs may be missing.
        if header.is_response
            && header.authoritative
            && !header.truncated
            && !authority.iter().any(|r| r.ty() == Record::TYPE_SOA)
        {
            if header.response_code == RCODE_NXDOMAIN {
                return Err(Error::invalid("NXDOMAIN requires SOA in authority"));
            }
            if header.response_code == RCODE_NOERROR && answers.is_empty() {
                return Err(Error::invalid("NODATA should include SOA in authority"));
            }
        }
        Ok(Message { header, questions, answers, authority, additional })
    }

    /// A standard query for one question, with a random id unless given.
    ///
    /// PHP: `Message::query()`.
    pub fn query(question: Question, id: Option<u16>, recursion_desired: bool) -> Message {
        let id = id.unwrap_or_else(|| {
            let mut bytes = [0u8; 2];
            let _ = getrandom::fill(&mut bytes);
            u16::from_be_bytes(bytes)
        });
        Message {
            header: Header { id, recursion_desired, question_count: 1, ..Header::default() },
            questions: vec![question],
            answers: Vec::new(),
            authority: Vec::new(),
            additional: Vec::new(),
        }
    }

    /// A response to the query with `header`: its id, opcode and `RD`
    /// flag, and `response_code`. Add the sections and flags, then
    /// [`ResponseBuilder::build`].
    ///
    /// PHP: `Message::response()`.
    pub fn response(header: &Header, response_code: u8) -> ResponseBuilder {
        ResponseBuilder {
            query: *header,
            response_code,
            questions: Vec::new(),
            answers: Vec::new(),
            authority: Vec::new(),
            additional: Vec::new(),
            authoritative: false,
            truncated: false,
            recursion_available: false,
        }
    }

    /// The header.
    pub fn header(&self) -> &Header {
        &self.header
    }

    /// The questions.
    pub fn questions(&self) -> &[Question] {
        &self.questions
    }

    /// The answer records.
    pub fn answers(&self) -> &[Record] {
        &self.answers
    }

    /// The authority records.
    pub fn authority(&self) -> &[Record] {
        &self.authority
    }

    /// The additional records.
    pub fn additional(&self) -> &[Record] {
        &self.additional
    }

    /// The message's parts: header, questions, answers, authority, additional.
    pub fn into_parts(self) -> (Header, Vec<Question>, Vec<Record>, Vec<Record>, Vec<Record>) {
        (self.header, self.questions, self.answers, self.authority, self.additional)
    }

    /// Decodes a whole packet.
    ///
    /// Fails with [`Error::Decoding`] for a packet shorter than a header,
    /// [`Error::PartialDecoding`] (carrying the header) when a section
    /// cannot be decoded or bytes are left over, and
    /// [`Error::InvalidArgument`] for a message [`Message::new`] refuses.
    ///
    /// PHP: `Message::decode()`.
    pub fn decode(packet: &[u8]) -> Result<Message, Error> {
        if packet.len() < Header::LENGTH {
            return Err(Error::decoding("Invalid DNS response: header too short"));
        }
        let header = Header::decode(packet, 0)?;
        let partial = |e: Error| match e {
            Error::Decoding(message) => Error::PartialDecoding { header, message },
            other => other,
        };
        let mut offset = Header::LENGTH;
        let mut questions = Vec::with_capacity(usize::from(header.question_count).min(16));
        for _ in 0..header.question_count {
            let (question, next) = Question::decode(packet, offset).map_err(partial)?;
            questions.push(question);
            offset = next;
        }
        let mut sections: [Vec<Record>; 3] = Default::default();
        for (section, count) in
            sections.iter_mut().zip([header.answer_count, header.authority_count, header.additional_count])
        {
            section.reserve(usize::from(count).min(64));
            for _ in 0..count {
                let (record, next) = Record::decode(packet, offset).map_err(partial)?;
                section.push(record);
                offset = next;
            }
        }
        if offset != packet.len() {
            return Err(partial(Error::decoding("Invalid packet length")));
        }
        let [answers, authority, additional] = sections;
        Message::new(header, questions, answers, authority, additional)
    }

    /// The message in wire format, within `max_size` bytes when given.
    ///
    /// Truncation follows RFC 1035 §6.2 and RFC 2181 §9: questions are
    /// always kept; answers are kept while they fit; authority and then
    /// additional records are kept whole or dropped, and only once every
    /// answer fit. `TC` is set when answers were dropped (and kept when the
    /// message had it). Dropping the authority of an `NXDOMAIN` or empty
    /// answer clears `AA`, which would otherwise need the missing `SOA`.
    ///
    /// Fails with [`Error::InvalidArgument`] when a name or record data
    /// cannot be encoded.
    ///
    /// PHP: `encode()`.
    pub fn encode(&self, max_size: Option<usize>) -> Result<Vec<u8>, Error> {
        let mut packet = Vec::with_capacity(max_size.unwrap_or(512).min(MAX_SIZE));
        packet.extend_from_slice(&self.header.encode());
        for question in &self.questions {
            question.encode_into(&mut packet)?;
        }
        let fits = |len: usize| max_size.is_none_or(|max| len <= max);
        let mut scratch = Vec::new();
        let mut answer_count = 0usize;
        for answer in &self.answers {
            scratch.clear();
            answer.encode_into(&mut scratch)?;
            if !fits(packet.len() + scratch.len()) {
                break;
            }
            packet.extend_from_slice(&scratch);
            answer_count += 1;
        }
        let answers_truncated = answer_count < self.answers.len();
        let (mut authority_count, mut additional_count) = (0, 0);
        if !answers_truncated {
            let mark = packet.len();
            for record in &self.authority {
                record.encode_into(&mut packet)?;
            }
            if fits(packet.len()) {
                authority_count = self.authority.len();
                let mark = packet.len();
                for record in &self.additional {
                    record.encode_into(&mut packet)?;
                }
                if fits(packet.len()) {
                    additional_count = self.additional.len();
                } else {
                    packet.truncate(mark);
                }
            } else {
                packet.truncate(mark);
            }
        }
        if answer_count == self.answers.len()
            && authority_count == self.authority.len()
            && additional_count == self.additional.len()
        {
            return Ok(packet);
        }
        let authority_dropped = authority_count < self.authority.len();
        let negative = (self.header.response_code == RCODE_NOERROR && self.answers.is_empty())
            || self.header.response_code == RCODE_NXDOMAIN;
        let header = Header {
            authoritative: if authority_dropped && negative { false } else { self.header.authoritative },
            truncated: answers_truncated || self.header.truncated,
            question_count: self.questions.len() as u16,
            answer_count: answer_count as u16,
            authority_count: authority_count as u16,
            additional_count: additional_count as u16,
            ..self.header
        };
        packet[..Header::LENGTH].copy_from_slice(&header.encode());
        Ok(packet)
    }

    /// Checks every record's data the way encoding would.
    ///
    /// PHP: `validate()`.
    pub fn validate(&self) -> Result<(), Error> {
        for record in self.answers.iter().chain(&self.authority).chain(&self.additional) {
            record.validate()?;
        }
        Ok(())
    }
}

/// A response being built by [`Message::response`].
#[derive(Debug, Clone)]
#[must_use = "build() creates the response"]
pub struct ResponseBuilder {
    query: Header,
    response_code: u8,
    questions: Vec<Question>,
    answers: Vec<Record>,
    authority: Vec<Record>,
    additional: Vec<Record>,
    authoritative: bool,
    truncated: bool,
    recursion_available: bool,
}

impl ResponseBuilder {
    /// The questions (usually the query's).
    pub fn questions(mut self, questions: Vec<Question>) -> Self {
        self.questions = questions;
        self
    }

    /// The answer records.
    pub fn answers(mut self, answers: Vec<Record>) -> Self {
        self.answers = answers;
        self
    }

    /// The authority records.
    pub fn authority(mut self, authority: Vec<Record>) -> Self {
        self.authority = authority;
        self
    }

    /// The additional records.
    pub fn additional(mut self, additional: Vec<Record>) -> Self {
        self.additional = additional;
        self
    }

    /// `AA`: whether the response is authoritative.
    pub fn authoritative(mut self, authoritative: bool) -> Self {
        self.authoritative = authoritative;
        self
    }

    /// `TC`: whether the response is truncated.
    pub fn truncated(mut self, truncated: bool) -> Self {
        self.truncated = truncated;
        self
    }

    /// `RA`: whether recursion is available.
    pub fn recursion_available(mut self, recursion_available: bool) -> Self {
        self.recursion_available = recursion_available;
        self
    }

    /// The response, checked as [`Message::new`] checks a message.
    pub fn build(self) -> Result<Message, Error> {
        let header = Header {
            id: self.query.id,
            is_response: true,
            opcode: self.query.opcode,
            authoritative: self.authoritative,
            truncated: self.truncated,
            recursion_desired: self.query.recursion_desired,
            recursion_available: self.recursion_available,
            response_code: self.response_code,
            question_count: self.questions.len() as u16,
            answer_count: self.answers.len() as u16,
            authority_count: self.authority.len() as u16,
            additional_count: self.additional.len() as u16,
        };
        Message::new(header, self.questions, self.answers, self.authority, self.additional)
    }
}
