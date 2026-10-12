//! What the JetStream API reports: stream and consumer state, account
//! usage, acknowledgements, stored messages and errors.

use bytes::Bytes;
use indexmap::IndexMap;
use serde_json::Value;

use super::config::{ConsumerConfig, StreamConfig};
use crate::error::{Error, Result};
use crate::fields;
use crate::headers::Headers;

/// An error the JetStream API answered with.
///
/// PHP: `Utopia\NATS\JetStream\ApiError`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ApiError {
    /// `code`: HTTP-like (`404`, `400`, `503`).
    pub code: i64,
    /// `err_code`: the JetStream error number (`10071` for a wrong last
    /// sequence).
    pub err_code: i64,
    /// `description`.
    pub description: String,
}

impl ApiError {
    /// Reads the `error` object.
    ///
    /// PHP: `ApiError::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        ApiError {
            code: fields::int(data, "code", 0),
            err_code: fields::int(data, "err_code", 0),
            description: fields::string(data, "description", "Unknown error"),
        }
    }
}

/// A stream's message and consumer counts.
///
/// PHP: `Utopia\NATS\JetStream\StreamState`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct StreamState {
    /// `messages`.
    pub messages: i64,
    /// `bytes`.
    pub bytes: i64,
    /// `first_seq`.
    pub first_seq: i64,
    /// `first_ts`.
    pub first_ts: Option<String>,
    /// `last_seq`.
    pub last_seq: i64,
    /// `last_ts`.
    pub last_ts: Option<String>,
    /// `consumer_count`.
    pub consumer_count: i64,
    /// `num_deleted`.
    pub num_deleted: i64,
    /// `num_subjects`.
    pub num_subjects: i64,
}

impl StreamState {
    /// Reads the `state` object.
    ///
    /// PHP: `StreamState::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        StreamState {
            messages: fields::int(data, "messages", 0),
            bytes: fields::int(data, "bytes", 0),
            first_seq: fields::int(data, "first_seq", 0),
            first_ts: fields::opt_string(data, "first_ts"),
            last_seq: fields::int(data, "last_seq", 0),
            last_ts: fields::opt_string(data, "last_ts"),
            consumer_count: fields::int(data, "consumer_count", 0),
            num_deleted: fields::int(data, "num_deleted", 0),
            num_subjects: fields::int(data, "num_subjects", 0),
        }
    }
}

/// A stream's configuration, state and creation time.
///
/// PHP: `Utopia\NATS\JetStream\StreamInfo`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct StreamInfo {
    /// `config`.
    pub config: StreamConfig,
    /// `state`.
    pub state: StreamState,
    /// `created`.
    pub created: String,
}

impl StreamInfo {
    /// Reads a stream info response.
    ///
    /// PHP: `StreamInfo::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        StreamInfo {
            config: StreamConfig::from_value(fields::get(data, "config").unwrap_or(&Value::Null)),
            state: StreamState::from_value(fields::get(data, "state").unwrap_or(&Value::Null)),
            created: fields::string(data, "created", ""),
        }
    }
}

/// A consumer and stream position.
///
/// PHP: `Utopia\NATS\JetStream\SequenceInfo`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct SequenceInfo {
    /// `consumer_seq`.
    pub consumer_seq: i64,
    /// `stream_seq`.
    pub stream_seq: i64,
    /// `last_active`.
    pub last_active: Option<String>,
}

impl SequenceInfo {
    /// Reads a sequence object.
    ///
    /// PHP: `SequenceInfo::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        SequenceInfo {
            consumer_seq: fields::int(data, "consumer_seq", 0),
            stream_seq: fields::int(data, "stream_seq", 0),
            last_active: fields::opt_string(data, "last_active"),
        }
    }
}

/// A consumer's configuration and progress.
///
/// PHP: `Utopia\NATS\JetStream\ConsumerInfo`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct ConsumerInfo {
    /// `stream_name`.
    pub stream_name: String,
    /// `name`.
    pub name: String,
    /// `config`.
    pub config: ConsumerConfig,
    /// `created`.
    pub created: String,
    /// `num_ack_pending`: delivered, not acknowledged.
    pub num_ack_pending: i64,
    /// `num_redelivered`.
    pub num_redelivered: i64,
    /// `num_waiting`: pull requests waiting.
    pub num_waiting: i64,
    /// `num_pending`: not delivered yet.
    pub num_pending: i64,
    /// `delivered`.
    pub delivered: SequenceInfo,
    /// `ack_floor`.
    pub ack_floor: SequenceInfo,
    /// `push_bound`.
    pub push_bound: bool,
    /// `cluster.name`.
    pub cluster: Option<String>,
    /// The configuration's metadata.
    pub metadata: Option<IndexMap<String, String>>,
}

impl ConsumerInfo {
    /// Reads a consumer info response.
    ///
    /// PHP: `ConsumerInfo::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        let config = ConsumerConfig::from_value(fields::get(data, "config").unwrap_or(&Value::Null));
        let metadata = config.metadata.clone();
        ConsumerInfo {
            stream_name: fields::string(data, "stream_name", ""),
            name: fields::string(data, "name", ""),
            config,
            created: fields::string(data, "created", ""),
            num_ack_pending: fields::int(data, "num_ack_pending", 0),
            num_redelivered: fields::int(data, "num_redelivered", 0),
            num_waiting: fields::int(data, "num_waiting", 0),
            num_pending: fields::int(data, "num_pending", 0),
            delivered: SequenceInfo::from_value(fields::get(data, "delivered").unwrap_or(&Value::Null)),
            ack_floor: SequenceInfo::from_value(fields::get(data, "ack_floor").unwrap_or(&Value::Null)),
            push_bound: fields::flag(data, "push_bound"),
            cluster: fields::get(data, "cluster").and_then(|c| fields::get(c, "name")).map(fields::php_string),
            metadata,
        }
    }
}

/// The account's JetStream usage and limits.
///
/// PHP: `Utopia\NATS\JetStream\AccountInfo`.
#[derive(Debug, Clone, PartialEq)]
pub struct AccountInfo {
    /// `memory` bytes used.
    pub memory: i64,
    /// `storage` bytes used.
    pub storage: i64,
    /// `streams`.
    pub streams: i64,
    /// `consumers`.
    pub consumers: i64,
    /// `limits`, as sent.
    pub limits: Value,
    /// `api.total`.
    pub api_total: i64,
    /// `api.errors`.
    pub api_errors: i64,
    /// `domain`.
    pub domain: Option<String>,
    /// The whole response.
    pub raw: Value,
}

impl AccountInfo {
    /// Reads an account info response.
    ///
    /// PHP: `AccountInfo::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        let api = fields::get(data, "api").unwrap_or(&Value::Null);
        AccountInfo {
            memory: fields::int(data, "memory", 0),
            storage: fields::int(data, "storage", 0),
            streams: fields::int(data, "streams", 0),
            consumers: fields::int(data, "consumers", 0),
            limits: fields::get(data, "limits").cloned().unwrap_or(Value::Array(Vec::new())),
            api_total: fields::int(api, "total", 0),
            api_errors: fields::int(api, "errors", 0),
            domain: fields::opt_string(data, "domain"),
            raw: data.clone(),
        }
    }
}

/// The acknowledgement of a JetStream publish.
///
/// PHP: `Utopia\NATS\JetStream\PubAck`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct PubAck {
    /// `stream` that stored it.
    pub stream: String,
    /// `seq`: its stream sequence.
    pub sequence: i64,
    /// `domain`.
    pub domain: Option<String>,
    /// `duplicate`: an earlier message had its `Nats-Msg-Id`.
    pub duplicate: bool,
}

impl PubAck {
    /// Reads a publish acknowledgement.
    ///
    /// PHP: `PubAck::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        PubAck {
            stream: fields::string(data, "stream", ""),
            sequence: fields::int(data, "seq", 0),
            domain: fields::opt_string(data, "domain"),
            duplicate: fields::flag(data, "duplicate"),
        }
    }
}

/// A message read from a stream by sequence or subject.
///
/// PHP: `Utopia\NATS\JetStream\StreamMessage`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct StreamMessage {
    /// `subject`.
    pub subject: String,
    /// `seq`.
    pub sequence: i64,
    /// `data`, base64-decoded.
    pub data: Bytes,
    /// `time`.
    pub time: Option<String>,
    /// `hdrs`, decoded.
    pub headers: Option<Headers>,
}

impl StreamMessage {
    /// Reads the `message` object of `STREAM.MSG.GET` (payload and headers
    /// are base64). A payload that does not decode, or decodes to `"0"`,
    /// reads as empty (PHP's `?: ''`).
    ///
    /// # Errors
    ///
    /// [`Error::Protocol`] for headers that decode to an invalid block.
    ///
    /// PHP: `StreamMessage::fromArray()`.
    pub fn from_value(data: &Value) -> Result<Self> {
        let payload = match fields::get(data, "data") {
            Some(encoded) => match php_std::encoding::base64_decode(fields::php_string(encoded).as_bytes(), true) {
                Some(bytes) if !(bytes.is_empty() || bytes == b"0") => bytes,
                _ => Vec::new(),
            },
            None => Vec::new(),
        };
        let headers = match fields::get(data, "hdrs") {
            Some(encoded) => match php_std::encoding::base64_decode(fields::php_string(encoded).as_bytes(), true) {
                Some(raw) if !raw.is_empty() => Some(Headers::from_wire(&raw)?),
                _ => None,
            },
            None => None,
        };
        Ok(StreamMessage {
            subject: fields::string(data, "subject", ""),
            sequence: fields::int(data, "seq", 0),
            data: payload.into(),
            time: fields::opt_string(data, "time"),
            headers,
        })
    }
}

/// What a JetStream message's reply subject says about it.
///
/// PHP: `Utopia\NATS\JetStream\MsgMetadata`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MsgMetadata {
    /// The stream.
    pub stream: String,
    /// The consumer.
    pub consumer: String,
    /// How many times it was delivered.
    pub num_delivered: i64,
    /// Its stream sequence.
    pub stream_sequence: i64,
    /// Its consumer sequence.
    pub consumer_sequence: i64,
    /// When it was stored (nanoseconds since the epoch, as text).
    pub timestamp: String,
    /// Messages still pending for the consumer.
    pub num_pending: i64,
    /// The JetStream domain, for the long form.
    pub domain: Option<String>,
}

impl MsgMetadata {
    /// Parses `$JS.ACK.<stream>.<consumer>.<delivered>.<sseq>.<cseq>.<ts>.<pending>`,
    /// or the domain form with two more tokens after `ACK`.
    ///
    /// # Errors
    ///
    /// [`Error::InvalidArgument`] for any other subject.
    ///
    /// PHP: `MsgMetadata::fromReplySubject()`.
    pub fn from_reply_subject(reply: &str) -> Result<Self> {
        // The first 11 tokens and how many there are, without collecting.
        let mut parts = [""; 11];
        let mut count = 0;
        for token in reply.split('.') {
            if count < parts.len() {
                parts[count] = token;
            }
            count += 1;
        }
        let int = |s: &str| php_std::format::str_to_int(s.as_bytes());
        if count >= 9 && parts[0] == "$JS" && parts[1] == "ACK" {
            if count == 9 {
                return Ok(MsgMetadata {
                    stream: parts[2].to_owned(),
                    consumer: parts[3].to_owned(),
                    num_delivered: int(parts[4]),
                    stream_sequence: int(parts[5]),
                    consumer_sequence: int(parts[6]),
                    timestamp: parts[7].to_owned(),
                    num_pending: int(parts[8]),
                    domain: None,
                });
            }
            if count >= 11 {
                return Ok(MsgMetadata {
                    stream: parts[4].to_owned(),
                    consumer: parts[5].to_owned(),
                    num_delivered: int(parts[6]),
                    stream_sequence: int(parts[7]),
                    consumer_sequence: int(parts[8]),
                    timestamp: parts[9].to_owned(),
                    num_pending: int(parts[10]),
                    domain: Some(parts[2].to_owned()),
                });
            }
        }
        Err(Error::InvalidArgument(format!("Cannot parse JetStream reply subject: {reply}")))
    }
}
