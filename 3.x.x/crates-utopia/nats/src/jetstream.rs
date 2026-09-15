//! `JetStream` types and client (PHP `Utopia\NATS\JetStream`).

use crate::connection::Connection;
use crate::error::{JetStreamException, NatsError};
use crate::headers::Headers;
use crate::message::Message;
use crate::subscription::{MessageCallback, Subscription};
use base64::Engine;
use serde_json::{json, Map, Value};
use std::sync::Arc;
use std::thread;
use std::time::{Duration, Instant};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum AckPolicy {
    None,
    All,
    Explicit,
}
impl AckPolicy {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::None => "none",
            Self::All => "all",
            Self::Explicit => "explicit",
        }
    }
    pub fn from_str_php(s: &str) -> Self {
        match s {
            "none" => Self::None,
            "all" => Self::All,
            _ => Self::Explicit,
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum DeliverPolicy {
    All,
    Last,
    New,
    ByStartSequence,
    ByStartTime,
    LastPerSubject,
}
impl DeliverPolicy {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::All => "all",
            Self::Last => "last",
            Self::New => "new",
            Self::ByStartSequence => "by_start_sequence",
            Self::ByStartTime => "by_start_time",
            Self::LastPerSubject => "last_per_subject",
        }
    }
    pub fn from_str_php(s: &str) -> Self {
        match s {
            "last" => Self::Last,
            "new" => Self::New,
            "by_start_sequence" => Self::ByStartSequence,
            "by_start_time" => Self::ByStartTime,
            "last_per_subject" => Self::LastPerSubject,
            _ => Self::All,
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ReplayPolicy {
    Instant,
    Original,
}
impl ReplayPolicy {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Instant => "instant",
            Self::Original => "original",
        }
    }
    pub fn from_str_php(s: &str) -> Self {
        if s == "original" {
            Self::Original
        } else {
            Self::Instant
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum RetentionPolicy {
    Limits,
    Interest,
    WorkQueue,
}
impl RetentionPolicy {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Limits => "limits",
            Self::Interest => "interest",
            Self::WorkQueue => "workqueue",
        }
    }
    pub fn from_str_php(s: &str) -> Self {
        match s {
            "interest" => Self::Interest,
            "workqueue" => Self::WorkQueue,
            _ => Self::Limits,
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum StorageType {
    File,
    Memory,
}
impl StorageType {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::File => "file",
            Self::Memory => "memory",
        }
    }
    pub fn from_str_php(s: &str) -> Self {
        if s == "memory" {
            Self::Memory
        } else {
            Self::File
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum DiscardPolicy {
    Old,
    New,
}
impl DiscardPolicy {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Old => "old",
            Self::New => "new",
        }
    }
    pub fn from_str_php(s: &str) -> Self {
        if s == "new" {
            Self::New
        } else {
            Self::Old
        }
    }
}

pub fn seconds_to_nanos(seconds: f64) -> i64 {
    (seconds * 1_000_000_000.0) as i64
}

pub fn nanos_to_seconds(nanos: i64) -> f64 {
    nanos as f64 / 1_000_000_000.0
}

#[derive(Debug, Clone)]
pub struct ConsumerConfig {
    pub name: Option<String>,
    pub durable_name: Option<String>,
    pub description: Option<String>,
    pub deliver_policy: DeliverPolicy,
    pub ack_policy: AckPolicy,
    pub ack_wait: Option<f64>,
    pub max_deliver: Option<i64>,
    pub filter_subject: Option<String>,
    pub filter_subjects: Option<Vec<String>>,
    pub replay_policy: ReplayPolicy,
    pub max_waiting: Option<i64>,
    pub max_ack_pending: Option<i64>,
    pub inactive_threshold: Option<f64>,
    pub opt_start_seq: Option<i64>,
    pub opt_start_time: Option<String>,
    pub max_batch: Option<i64>,
    pub max_bytes: Option<i64>,
    pub mem_storage: bool,
    pub num_replicas: Option<i64>,
    pub deliver_subject: Option<String>,
    pub deliver_group: Option<String>,
    pub flow_control: bool,
    pub idle_heartbeat: Option<f64>,
    pub headers_only: bool,
    pub metadata: Option<Map<String, Value>>,
}

impl Default for ConsumerConfig {
    fn default() -> Self {
        Self {
            name: None,
            durable_name: None,
            description: None,
            deliver_policy: DeliverPolicy::All,
            ack_policy: AckPolicy::Explicit,
            ack_wait: None,
            max_deliver: None,
            filter_subject: None,
            filter_subjects: None,
            replay_policy: ReplayPolicy::Instant,
            max_waiting: None,
            max_ack_pending: None,
            inactive_threshold: None,
            opt_start_seq: None,
            opt_start_time: None,
            max_batch: None,
            max_bytes: None,
            mem_storage: false,
            num_replicas: None,
            deliver_subject: None,
            deliver_group: None,
            flow_control: false,
            idle_heartbeat: None,
            headers_only: false,
            metadata: None,
        }
    }
}

impl ConsumerConfig {
    pub fn to_array(&self) -> Map<String, Value> {
        let mut data = Map::new();
        data.insert("deliver_policy".into(), json!(self.deliver_policy.as_str()));
        data.insert("ack_policy".into(), json!(self.ack_policy.as_str()));
        data.insert("replay_policy".into(), json!(self.replay_policy.as_str()));
        if let Some(v) = &self.name {
            data.insert("name".into(), json!(v));
        }
        if let Some(v) = &self.durable_name {
            data.insert("durable_name".into(), json!(v));
        }
        if let Some(v) = &self.description {
            data.insert("description".into(), json!(v));
        }
        if let Some(v) = self.ack_wait {
            data.insert("ack_wait".into(), json!(seconds_to_nanos(v)));
        }
        if let Some(v) = self.max_deliver {
            data.insert("max_deliver".into(), json!(v));
        }
        if let Some(v) = &self.filter_subject {
            data.insert("filter_subject".into(), json!(v));
        }
        if let Some(v) = &self.filter_subjects {
            data.insert("filter_subjects".into(), json!(v));
        }
        if let Some(v) = self.max_waiting {
            data.insert("max_waiting".into(), json!(v));
        }
        if let Some(v) = self.max_ack_pending {
            data.insert("max_ack_pending".into(), json!(v));
        }
        if let Some(v) = self.inactive_threshold {
            data.insert("inactive_threshold".into(), json!(seconds_to_nanos(v)));
        }
        if let Some(v) = self.opt_start_seq {
            data.insert("opt_start_seq".into(), json!(v));
        }
        if let Some(v) = &self.opt_start_time {
            data.insert("opt_start_time".into(), json!(v));
        }
        if let Some(v) = self.max_batch {
            data.insert("max_batch".into(), json!(v));
        }
        if let Some(v) = self.max_bytes {
            data.insert("max_bytes".into(), json!(v));
        }
        if self.mem_storage {
            data.insert("mem_storage".into(), json!(true));
        }
        if let Some(v) = self.num_replicas {
            data.insert("num_replicas".into(), json!(v));
        }
        if let Some(v) = &self.deliver_subject {
            data.insert("deliver_subject".into(), json!(v));
        }
        if let Some(v) = &self.deliver_group {
            data.insert("deliver_group".into(), json!(v));
        }
        if self.flow_control {
            data.insert("flow_control".into(), json!(true));
        }
        if let Some(v) = self.idle_heartbeat {
            data.insert("idle_heartbeat".into(), json!(seconds_to_nanos(v)));
        }
        if self.headers_only {
            data.insert("headers_only".into(), json!(true));
        }
        if let Some(v) = &self.metadata {
            data.insert("metadata".into(), Value::Object(v.clone()));
        }
        data
    }

    #[allow(clippy::field_reassign_with_default)]
    pub fn from_array(data: &Value) -> Self {
        let mut c = Self::default();
        c.name = data.get("name").and_then(Value::as_str).map(str::to_owned);
        c.durable_name = data
            .get("durable_name")
            .and_then(Value::as_str)
            .map(str::to_owned);
        c.description = data
            .get("description")
            .and_then(Value::as_str)
            .map(str::to_owned);
        c.deliver_policy = DeliverPolicy::from_str_php(
            data.get("deliver_policy")
                .and_then(Value::as_str)
                .unwrap_or(""),
        );
        c.ack_policy =
            AckPolicy::from_str_php(data.get("ack_policy").and_then(Value::as_str).unwrap_or(""));
        c.ack_wait = data
            .get("ack_wait")
            .and_then(Value::as_i64)
            .map(nanos_to_seconds);
        c.max_deliver = data.get("max_deliver").and_then(Value::as_i64);
        c.filter_subject = data
            .get("filter_subject")
            .and_then(Value::as_str)
            .map(str::to_owned);
        c.filter_subjects = data
            .get("filter_subjects")
            .and_then(Value::as_array)
            .map(|a| {
                a.iter()
                    .filter_map(|v| v.as_str().map(str::to_owned))
                    .collect()
            });
        c.replay_policy = ReplayPolicy::from_str_php(
            data.get("replay_policy")
                .and_then(Value::as_str)
                .unwrap_or(""),
        );
        c.max_waiting = data.get("max_waiting").and_then(Value::as_i64);
        c.max_ack_pending = data.get("max_ack_pending").and_then(Value::as_i64);
        c.inactive_threshold = data
            .get("inactive_threshold")
            .and_then(Value::as_i64)
            .map(nanos_to_seconds);
        c.opt_start_seq = data.get("opt_start_seq").and_then(Value::as_i64);
        c.opt_start_time = data
            .get("opt_start_time")
            .and_then(Value::as_str)
            .map(str::to_owned);
        c.max_batch = data.get("max_batch").and_then(Value::as_i64);
        c.max_bytes = data.get("max_bytes").and_then(Value::as_i64);
        c.mem_storage = data
            .get("mem_storage")
            .and_then(Value::as_bool)
            .unwrap_or(false);
        c.num_replicas = data.get("num_replicas").and_then(Value::as_i64);
        c.deliver_subject = data
            .get("deliver_subject")
            .and_then(Value::as_str)
            .map(str::to_owned);
        c.deliver_group = data
            .get("deliver_group")
            .and_then(Value::as_str)
            .map(str::to_owned);
        c.flow_control = data
            .get("flow_control")
            .and_then(Value::as_bool)
            .unwrap_or(false);
        c.idle_heartbeat = data
            .get("idle_heartbeat")
            .and_then(Value::as_i64)
            .map(nanos_to_seconds);
        c.headers_only = data
            .get("headers_only")
            .and_then(Value::as_bool)
            .unwrap_or(false);
        c.metadata = data.get("metadata").and_then(Value::as_object).cloned();
        c
    }
}

#[derive(Debug, Clone)]
pub struct SequenceInfo {
    pub consumer_seq: i64,
    pub stream_seq: i64,
    pub last_active: Option<String>,
}

impl SequenceInfo {
    pub fn from_array(data: &Value) -> Self {
        Self {
            consumer_seq: data
                .get("consumer_seq")
                .and_then(Value::as_i64)
                .unwrap_or(0),
            stream_seq: data.get("stream_seq").and_then(Value::as_i64).unwrap_or(0),
            last_active: data
                .get("last_active")
                .and_then(Value::as_str)
                .map(str::to_owned),
        }
    }
}

#[derive(Debug, Clone)]
pub struct ConsumerInfo {
    pub stream_name: String,
    pub name: String,
    pub config: ConsumerConfig,
    pub created: String,
    pub num_ack_pending: i64,
    pub num_redelivered: i64,
    pub num_waiting: i64,
    pub num_pending: i64,
    pub delivered: SequenceInfo,
    pub ack_floor: SequenceInfo,
    pub push_bound: bool,
    pub cluster: Option<String>,
    pub metadata: Option<Map<String, Value>>,
}

impl ConsumerInfo {
    pub fn from_array(data: &Value) -> Self {
        let config = ConsumerConfig::from_array(data.get("config").unwrap_or(&json!({})));
        let metadata = config.metadata.clone();
        Self {
            stream_name: data
                .get("stream_name")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
            name: data
                .get("name")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
            created: data
                .get("created")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
            num_ack_pending: data
                .get("num_ack_pending")
                .and_then(Value::as_i64)
                .unwrap_or(0),
            num_redelivered: data
                .get("num_redelivered")
                .and_then(Value::as_i64)
                .unwrap_or(0),
            num_waiting: data.get("num_waiting").and_then(Value::as_i64).unwrap_or(0),
            num_pending: data.get("num_pending").and_then(Value::as_i64).unwrap_or(0),
            delivered: SequenceInfo::from_array(data.get("delivered").unwrap_or(&json!({}))),
            ack_floor: SequenceInfo::from_array(data.get("ack_floor").unwrap_or(&json!({}))),
            push_bound: data
                .get("push_bound")
                .and_then(Value::as_bool)
                .unwrap_or(false),
            cluster: data
                .get("cluster")
                .and_then(|c| c.get("name"))
                .and_then(Value::as_str)
                .map(str::to_owned),
            metadata,
            config,
        }
    }
}

#[derive(Debug, Clone)]
pub struct StreamMessage {
    pub subject: String,
    pub sequence: i64,
    pub data: Vec<u8>,
    pub time: Option<String>,
    pub headers: Option<Headers>,
}

impl StreamMessage {
    pub fn from_array(data: &Value) -> Self {
        let payload = data
            .get("data")
            .and_then(Value::as_str)
            .and_then(|s| base64::engine::general_purpose::STANDARD.decode(s).ok())
            .unwrap_or_default();
        let headers = data.get("hdrs").and_then(Value::as_str).and_then(|s| {
            base64::engine::general_purpose::STANDARD
                .decode(s)
                .ok()
                .and_then(|raw| {
                    if raw.is_empty() {
                        None
                    } else {
                        Headers::from_wire(&String::from_utf8_lossy(&raw)).ok()
                    }
                })
        });
        Self {
            subject: data
                .get("subject")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
            sequence: data.get("seq").and_then(Value::as_i64).unwrap_or(0),
            data: payload,
            time: data.get("time").and_then(Value::as_str).map(str::to_owned),
            headers,
        }
    }
}

#[derive(Debug, Clone)]
pub struct ApiError {
    pub code: i64,
    pub err_code: i64,
    pub description: String,
}

impl ApiError {
    pub fn from_value(data: &Value) -> Option<Self> {
        let err = data.get("error")?;
        Some(Self {
            code: err.get("code").and_then(Value::as_i64).unwrap_or(0),
            err_code: err.get("err_code").and_then(Value::as_i64).unwrap_or(0),
            description: err
                .get("description")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
        })
    }
}

fn json_str(data: &Value, key: &str) -> Option<String> {
    data.get(key).and_then(Value::as_str).map(str::to_owned)
}

fn json_i64(data: &Value, key: &str) -> Option<i64> {
    data.get(key).and_then(Value::as_i64)
}

fn json_bool(data: &Value, key: &str) -> bool {
    data.get(key).and_then(Value::as_bool).unwrap_or(false)
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct SubjectTransform {
    pub source: String,
    pub destination: String,
}

impl SubjectTransform {
    pub fn to_array(&self) -> Map<String, Value> {
        let mut data = Map::new();
        data.insert("src".into(), json!(self.source));
        data.insert("dest".into(), json!(self.destination));
        data
    }
    pub fn from_array(data: &Value) -> Self {
        Self {
            source: json_str(data, "src").unwrap_or_default(),
            destination: json_str(data, "dest").unwrap_or_default(),
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ExternalStream {
    pub api: String,
    pub deliver: Option<String>,
}

impl ExternalStream {
    pub fn to_array(&self) -> Map<String, Value> {
        let mut data = Map::new();
        data.insert("api".into(), json!(self.api));
        if let Some(d) = &self.deliver {
            data.insert("deliver".into(), json!(d));
        }
        data
    }
    pub fn from_array(data: &Value) -> Self {
        Self {
            api: json_str(data, "api").unwrap_or_default(),
            deliver: json_str(data, "deliver"),
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct StreamSource {
    pub name: String,
    pub opt_start_seq: Option<i64>,
    pub opt_start_time: Option<String>,
    pub filter_subject: Option<String>,
    pub subject_transforms: Option<Vec<SubjectTransform>>,
    pub external: Option<ExternalStream>,
}

impl StreamSource {
    pub fn to_array(&self) -> Map<String, Value> {
        let mut data = Map::new();
        data.insert("name".into(), json!(self.name));
        if let Some(v) = self.opt_start_seq {
            data.insert("opt_start_seq".into(), json!(v));
        }
        if let Some(v) = &self.opt_start_time {
            data.insert("opt_start_time".into(), json!(v));
        }
        if let Some(v) = &self.filter_subject {
            data.insert("filter_subject".into(), json!(v));
        }
        if let Some(transforms) = &self.subject_transforms {
            data.insert(
                "subject_transforms".into(),
                json!(transforms
                    .iter()
                    .map(|t| Value::Object(t.to_array()))
                    .collect::<Vec<_>>()),
            );
        }
        if let Some(ext) = &self.external {
            data.insert("external".into(), Value::Object(ext.to_array()));
        }
        data
    }
    pub fn from_array(data: &Value) -> Self {
        Self {
            name: json_str(data, "name").unwrap_or_default(),
            opt_start_seq: json_i64(data, "opt_start_seq"),
            opt_start_time: json_str(data, "opt_start_time"),
            filter_subject: json_str(data, "filter_subject"),
            subject_transforms: data
                .get("subject_transforms")
                .and_then(Value::as_array)
                .map(|a| a.iter().map(SubjectTransform::from_array).collect()),
            external: data.get("external").map(ExternalStream::from_array),
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Republish {
    pub source: String,
    pub destination: String,
    pub headers_only: bool,
}

impl Republish {
    pub fn to_array(&self) -> Map<String, Value> {
        let mut data = Map::new();
        data.insert("src".into(), json!(self.source));
        data.insert("dest".into(), json!(self.destination));
        if self.headers_only {
            data.insert("headers_only".into(), json!(true));
        }
        data
    }
    pub fn from_array(data: &Value) -> Self {
        Self {
            source: json_str(data, "src").unwrap_or_default(),
            destination: json_str(data, "dest").unwrap_or_default(),
            headers_only: json_bool(data, "headers_only"),
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Placement {
    pub cluster: Option<String>,
    pub tags: Option<Vec<String>>,
}

impl Placement {
    pub fn to_array(&self) -> Map<String, Value> {
        let mut data = Map::new();
        if let Some(c) = &self.cluster {
            data.insert("cluster".into(), json!(c));
        }
        if let Some(tags) = &self.tags {
            data.insert("tags".into(), json!(tags));
        }
        data
    }
    pub fn from_array(data: &Value) -> Self {
        Self {
            cluster: json_str(data, "cluster"),
            tags: data.get("tags").and_then(Value::as_array).map(|a| {
                a.iter()
                    .filter_map(|v| v.as_str().map(str::to_owned))
                    .collect()
            }),
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct ConsumerLimits {
    pub inactive_threshold: Option<f64>,
    pub max_ack_pending: Option<i64>,
}

impl ConsumerLimits {
    pub fn to_array(&self) -> Map<String, Value> {
        let mut data = Map::new();
        if let Some(v) = self.inactive_threshold {
            data.insert("inactive_threshold".into(), json!(seconds_to_nanos(v)));
        }
        if let Some(v) = self.max_ack_pending {
            data.insert("max_ack_pending".into(), json!(v));
        }
        data
    }
    pub fn from_array(data: &Value) -> Self {
        Self {
            inactive_threshold: json_i64(data, "inactive_threshold").map(nanos_to_seconds),
            max_ack_pending: json_i64(data, "max_ack_pending"),
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct StreamState {
    pub messages: i64,
    pub bytes: i64,
    pub first_seq: i64,
    pub first_ts: Option<String>,
    pub last_seq: i64,
    pub last_ts: Option<String>,
    pub consumer_count: i64,
    pub num_deleted: i64,
    pub num_subjects: i64,
    pub subjects: Option<Map<String, Value>>,
}

impl StreamState {
    pub fn from_array(data: &Value) -> Self {
        Self {
            messages: json_i64(data, "messages").unwrap_or(0),
            bytes: json_i64(data, "bytes").unwrap_or(0),
            first_seq: json_i64(data, "first_seq").unwrap_or(0),
            first_ts: json_str(data, "first_ts"),
            last_seq: json_i64(data, "last_seq").unwrap_or(0),
            last_ts: json_str(data, "last_ts"),
            consumer_count: json_i64(data, "consumer_count").unwrap_or(0),
            num_deleted: json_i64(data, "num_deleted").unwrap_or(0),
            num_subjects: json_i64(data, "num_subjects").unwrap_or(0),
            subjects: data.get("subjects").and_then(Value::as_object).cloned(),
        }
    }
}

#[derive(Debug, Clone)]
pub struct StreamConfig {
    pub name: String,
    pub subjects: Vec<String>,
    pub description: Option<String>,
    pub retention: RetentionPolicy,
    pub max_consumers: i64,
    pub max_msgs: i64,
    pub max_bytes: i64,
    pub max_msgs_per_subject: i64,
    pub max_msg_size: Option<i64>,
    pub max_age: Option<f64>,
    pub storage: StorageType,
    pub replicas: i64,
    pub discard: DiscardPolicy,
    pub no_ack: bool,
    pub duplicate_window: Option<f64>,
    pub allow_direct: bool,
    pub mirror_direct: bool,
    pub sealed: bool,
    pub deny_delete: bool,
    pub deny_purge: bool,
    pub allow_rollup: bool,
    pub metadata: Option<Map<String, Value>>,
    pub mirror: Option<StreamSource>,
    pub sources: Option<Vec<StreamSource>>,
    pub republish: Option<Republish>,
    pub subject_transform: Option<SubjectTransform>,
    pub placement: Option<Placement>,
    pub compression: Option<String>,
    pub first_seq: Option<i64>,
    pub consumer_limits: Option<ConsumerLimits>,
    pub allow_msg_ttl: bool,
    pub subject_delete_marker_ttl: Option<f64>,
}

impl StreamConfig {
    pub fn new(name: impl Into<String>) -> Self {
        Self {
            name: name.into(),
            subjects: Vec::new(),
            description: None,
            retention: RetentionPolicy::Limits,
            max_consumers: -1,
            max_msgs: -1,
            max_bytes: -1,
            max_msgs_per_subject: -1,
            max_msg_size: None,
            max_age: None,
            storage: StorageType::File,
            replicas: 1,
            discard: DiscardPolicy::Old,
            no_ack: false,
            duplicate_window: None,
            allow_direct: false,
            mirror_direct: false,
            sealed: false,
            deny_delete: false,
            deny_purge: false,
            allow_rollup: false,
            metadata: None,
            mirror: None,
            sources: None,
            republish: None,
            subject_transform: None,
            placement: None,
            compression: None,
            first_seq: None,
            consumer_limits: None,
            allow_msg_ttl: false,
            subject_delete_marker_ttl: None,
        }
    }

    pub fn to_array(&self) -> Map<String, Value> {
        let mut data = Map::new();
        data.insert("name".into(), json!(self.name));
        data.insert("retention".into(), json!(self.retention.as_str()));
        data.insert("max_consumers".into(), json!(self.max_consumers));
        data.insert("max_msgs".into(), json!(self.max_msgs));
        data.insert("max_bytes".into(), json!(self.max_bytes));
        data.insert(
            "max_msgs_per_subject".into(),
            json!(self.max_msgs_per_subject),
        );
        data.insert("storage".into(), json!(self.storage.as_str()));
        data.insert("num_replicas".into(), json!(self.replicas));
        data.insert("discard".into(), json!(self.discard.as_str()));
        data.insert("no_ack".into(), json!(self.no_ack));
        data.insert("allow_direct".into(), json!(self.allow_direct));
        data.insert("mirror_direct".into(), json!(self.mirror_direct));
        data.insert("sealed".into(), json!(self.sealed));
        data.insert("deny_delete".into(), json!(self.deny_delete));
        data.insert("deny_purge".into(), json!(self.deny_purge));
        data.insert("allow_rollup_hdrs".into(), json!(self.allow_rollup));
        if !self.subjects.is_empty() {
            data.insert("subjects".into(), json!(self.subjects));
        }
        if let Some(v) = &self.description {
            data.insert("description".into(), json!(v));
        }
        if let Some(v) = self.max_msg_size {
            data.insert("max_msg_size".into(), json!(v));
        }
        if let Some(v) = self.max_age {
            data.insert("max_age".into(), json!(seconds_to_nanos(v)));
        }
        if let Some(v) = self.duplicate_window {
            data.insert("duplicate_window".into(), json!(seconds_to_nanos(v)));
        }
        if let Some(v) = &self.metadata {
            data.insert("metadata".into(), Value::Object(v.clone()));
        }
        if let Some(v) = &self.mirror {
            data.insert("mirror".into(), Value::Object(v.to_array()));
        }
        if let Some(sources) = &self.sources {
            data.insert(
                "sources".into(),
                json!(sources
                    .iter()
                    .map(|s| Value::Object(s.to_array()))
                    .collect::<Vec<_>>()),
            );
        }
        if let Some(v) = &self.republish {
            data.insert("republish".into(), Value::Object(v.to_array()));
        }
        if let Some(v) = &self.subject_transform {
            data.insert("subject_transform".into(), Value::Object(v.to_array()));
        }
        if let Some(v) = &self.placement {
            data.insert("placement".into(), Value::Object(v.to_array()));
        }
        if let Some(v) = &self.compression {
            data.insert("compression".into(), json!(v));
        }
        if let Some(v) = self.first_seq {
            data.insert("first_seq".into(), json!(v));
        }
        if let Some(v) = &self.consumer_limits {
            data.insert("consumer_limits".into(), Value::Object(v.to_array()));
        }
        if self.allow_msg_ttl {
            data.insert("allow_msg_ttl".into(), json!(true));
        }
        if let Some(v) = self.subject_delete_marker_ttl {
            data.insert(
                "subject_delete_marker_ttl".into(),
                json!(seconds_to_nanos(v)),
            );
        }
        data
    }

    pub fn from_array(data: &Value) -> Self {
        let mut c = Self::new(json_str(data, "name").unwrap_or_default());
        c.subjects = data
            .get("subjects")
            .and_then(Value::as_array)
            .map(|a| {
                a.iter()
                    .filter_map(|v| v.as_str().map(str::to_owned))
                    .collect()
            })
            .unwrap_or_default();
        c.description = json_str(data, "description");
        c.retention = RetentionPolicy::from_str_php(
            data.get("retention").and_then(Value::as_str).unwrap_or(""),
        );
        c.max_consumers = json_i64(data, "max_consumers").unwrap_or(-1);
        c.max_msgs = json_i64(data, "max_msgs").unwrap_or(-1);
        c.max_bytes = json_i64(data, "max_bytes").unwrap_or(-1);
        c.max_msgs_per_subject = json_i64(data, "max_msgs_per_subject").unwrap_or(-1);
        c.max_msg_size = json_i64(data, "max_msg_size");
        c.max_age = json_i64(data, "max_age").map(nanos_to_seconds);
        c.storage =
            StorageType::from_str_php(data.get("storage").and_then(Value::as_str).unwrap_or(""));
        c.replicas = json_i64(data, "num_replicas").unwrap_or(1);
        c.discard =
            DiscardPolicy::from_str_php(data.get("discard").and_then(Value::as_str).unwrap_or(""));
        c.no_ack = json_bool(data, "no_ack");
        c.duplicate_window = json_i64(data, "duplicate_window").map(nanos_to_seconds);
        c.allow_direct = json_bool(data, "allow_direct");
        c.mirror_direct = json_bool(data, "mirror_direct");
        c.sealed = json_bool(data, "sealed");
        c.deny_delete = json_bool(data, "deny_delete");
        c.deny_purge = json_bool(data, "deny_purge");
        c.allow_rollup = json_bool(data, "allow_rollup_hdrs");
        c.metadata = data.get("metadata").and_then(Value::as_object).cloned();
        c.mirror = data.get("mirror").map(StreamSource::from_array);
        c.sources = data
            .get("sources")
            .and_then(Value::as_array)
            .map(|a| a.iter().map(StreamSource::from_array).collect());
        c.republish = data.get("republish").map(Republish::from_array);
        c.subject_transform = data
            .get("subject_transform")
            .map(SubjectTransform::from_array);
        c.placement = data.get("placement").map(Placement::from_array);
        c.compression = json_str(data, "compression");
        c.first_seq = json_i64(data, "first_seq");
        c.consumer_limits = data.get("consumer_limits").map(ConsumerLimits::from_array);
        c.allow_msg_ttl = json_bool(data, "allow_msg_ttl");
        c.subject_delete_marker_ttl =
            json_i64(data, "subject_delete_marker_ttl").map(nanos_to_seconds);
        c
    }
}

#[derive(Debug, Clone)]
pub struct StreamInfo {
    pub config: StreamConfig,
    pub state: StreamState,
    pub created: String,
    pub raw: Value,
}

impl StreamInfo {
    pub fn from_array(data: &Value) -> Self {
        Self {
            config: StreamConfig::from_array(data.get("config").unwrap_or(&json!({}))),
            state: StreamState::from_array(data.get("state").unwrap_or(&json!({}))),
            created: json_str(data, "created").unwrap_or_default(),
            raw: data.clone(),
        }
    }
}

#[derive(Clone)]
pub struct JetStream {
    conn: Connection,
    api_prefix: String,
}

impl std::fmt::Debug for JetStream {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("JetStream")
            .field("api_prefix", &self.api_prefix)
            .finish_non_exhaustive()
    }
}

impl JetStream {
    pub fn new(conn: Connection, domain: Option<&str>, api_prefix: Option<&str>) -> Self {
        let api_prefix = if let Some(p) = api_prefix {
            p.to_owned()
        } else if let Some(d) = domain {
            format!("$JS.{d}.API")
        } else {
            "$JS.API".into()
        };
        Self { conn, api_prefix }
    }

    pub fn conn(&self) -> &Connection {
        &self.conn
    }

    pub fn api_prefix(&self) -> &str {
        &self.api_prefix
    }

    pub fn create_stream(&self, config: &StreamConfig) -> Result<Stream, NatsError> {
        let data = self.api_request(
            &format!("STREAM.CREATE.{}", config.name),
            Some(&Value::Object(config.to_array())),
        )?;
        Ok(Stream {
            js: self.clone(),
            info: StreamInfo::from_array(&data),
        })
    }

    pub fn update_stream(&self, config: &StreamConfig) -> Result<Stream, NatsError> {
        let data = self.api_request(
            &format!("STREAM.UPDATE.{}", config.name),
            Some(&Value::Object(config.to_array())),
        )?;
        Ok(Stream {
            js: self.clone(),
            info: StreamInfo::from_array(&data),
        })
    }

    pub fn create_or_update_stream(&self, config: &StreamConfig) -> Result<Stream, NatsError> {
        match self.update_stream(config) {
            Ok(s) => Ok(s),
            Err(NatsError::JetStream(e)) if e.api_error.as_ref().is_some_and(|a| a.code == 404) => {
                self.create_stream(config)
            }
            Err(e) => Err(e),
        }
    }

    pub fn delete_stream(&self, name: &str) -> Result<(), NatsError> {
        self.api_request(&format!("STREAM.DELETE.{name}"), None)?;
        Ok(())
    }

    pub fn get_stream(&self, name: &str) -> Result<Stream, NatsError> {
        let info = self.get_stream_info(name)?;
        Ok(Stream {
            js: self.clone(),
            info,
        })
    }

    pub fn get_stream_info(&self, name: &str) -> Result<StreamInfo, NatsError> {
        let data = self.api_request(&format!("STREAM.INFO.{name}"), None)?;
        Ok(StreamInfo::from_array(&data))
    }

    pub fn get_stream_names(&self, subject: Option<&str>) -> Result<Vec<String>, NatsError> {
        let payload = subject.map(|s| json!({"subject": s}));
        let data = self.api_request("STREAM.NAMES", payload.as_ref())?;
        Ok(data
            .get("streams")
            .and_then(Value::as_array)
            .map(|a| {
                a.iter()
                    .filter_map(|v| v.as_str().map(str::to_owned))
                    .collect()
            })
            .unwrap_or_default())
    }

    pub fn list_streams(&self, subject: Option<&str>) -> Result<Vec<StreamInfo>, NatsError> {
        let payload = subject.map(|s| json!({"subject": s}));
        let data = self.api_request("STREAM.LIST", payload.as_ref())?;
        Ok(data
            .get("streams")
            .and_then(Value::as_array)
            .map(|a| a.iter().map(StreamInfo::from_array).collect())
            .unwrap_or_default())
    }

    pub fn purge_stream(&self, name: &str, subject: Option<&str>) -> Result<(), NatsError> {
        let payload = subject.map(|s| json!({"filter": s}));
        self.api_request(&format!("STREAM.PURGE.{name}"), payload.as_ref())?;
        Ok(())
    }

    pub fn create_consumer(
        &self,
        stream: &str,
        config: &ConsumerConfig,
    ) -> Result<Consumer, NatsError> {
        let consumer_name = config.name.as_deref().or(config.durable_name.as_deref());
        let subject = match consumer_name {
            Some(n) => format!("CONSUMER.CREATE.{stream}.{n}"),
            None => format!("CONSUMER.CREATE.{stream}"),
        };
        let payload = json!({
            "stream_name": stream,
            "config": Value::Object(config.to_array()),
        });
        let data = self.api_request(&subject, Some(&payload))?;
        Ok(Consumer::new(
            self.conn.clone(),
            stream,
            ConsumerInfo::from_array(&data),
            &self.api_prefix,
        ))
    }

    pub fn update_consumer(
        &self,
        stream: &str,
        config: &ConsumerConfig,
    ) -> Result<Consumer, NatsError> {
        self.create_consumer(stream, config)
    }

    pub fn delete_consumer(&self, stream: &str, consumer: &str) -> Result<(), NatsError> {
        self.api_request(&format!("CONSUMER.DELETE.{stream}.{consumer}"), None)?;
        Ok(())
    }

    pub fn get_consumer(&self, stream: &str, consumer: &str) -> Result<Consumer, NatsError> {
        let data = self.api_request(&format!("CONSUMER.INFO.{stream}.{consumer}"), None)?;
        Ok(Consumer::new(
            self.conn.clone(),
            stream,
            ConsumerInfo::from_array(&data),
            &self.api_prefix,
        ))
    }

    pub fn get_consumer_names(&self, stream: &str) -> Result<Vec<String>, NatsError> {
        let data = self.api_request(&format!("CONSUMER.NAMES.{stream}"), None)?;
        Ok(data
            .get("consumers")
            .and_then(Value::as_array)
            .map(|a| {
                a.iter()
                    .filter_map(|v| v.as_str().map(str::to_owned))
                    .collect()
            })
            .unwrap_or_default())
    }

    pub fn get_consumers(&self, stream: &str) -> Result<Vec<ConsumerInfo>, NatsError> {
        let mut consumers = Vec::new();
        let mut offset = 0i64;
        loop {
            let data = self.api_request(
                &format!("CONSUMER.LIST.{stream}"),
                Some(&json!({"offset": offset})),
            )?;
            let page = data
                .get("consumers")
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            let page_len = page.len();
            for entry in &page {
                consumers.push(ConsumerInfo::from_array(entry));
            }
            let total = json_i64(&data, "total").unwrap_or(consumers.len() as i64);
            offset = consumers.len() as i64;
            if page_len == 0 || offset >= total {
                break;
            }
        }
        Ok(consumers)
    }

    pub fn push_subscribe(
        &self,
        stream: &str,
        mut config: ConsumerConfig,
        callback: impl Fn(JetStreamMessage) + Send + Sync + 'static,
    ) -> Result<PushSubscription, NatsError> {
        if config.deliver_subject.is_none() {
            config.deliver_subject = Some(self.conn.new_inbox());
        }
        let mut consumer = self.create_consumer(stream, &config)?;
        PushSubscription::new(self.conn.clone(), consumer.info(false), callback)
    }

    pub fn ordered_consumer(
        &self,
        stream: &str,
        deliver_policy: DeliverPolicy,
        filter_subject: Option<&str>,
        idle_heartbeat: f64,
    ) -> Result<OrderedConsumer, NatsError> {
        OrderedConsumer::new(
            self.conn.clone(),
            self.clone(),
            stream,
            deliver_policy,
            filter_subject,
            idle_heartbeat,
        )
    }

    pub fn get_message(&self, stream: &str, seq: i64) -> Result<StreamMessage, NatsError> {
        let data = self.api_request(
            &format!("STREAM.MSG.GET.{stream}"),
            Some(&json!({"seq": seq})),
        )?;
        Ok(StreamMessage::from_array(
            data.get("message").unwrap_or(&json!({})),
        ))
    }

    pub fn get_last_message(
        &self,
        stream: &str,
        subject: &str,
    ) -> Result<StreamMessage, NatsError> {
        let data = self.api_request(
            &format!("STREAM.MSG.GET.{stream}"),
            Some(&json!({"last_by_subj": subject})),
        )?;
        Ok(StreamMessage::from_array(
            data.get("message").unwrap_or(&json!({})),
        ))
    }

    pub fn delete_message(&self, stream: &str, seq: i64, no_erase: bool) -> Result<(), NatsError> {
        let mut payload = json!({"seq": seq});
        if no_erase {
            payload["no_erase"] = json!(true);
        }
        self.api_request(&format!("STREAM.MSG.DELETE.{stream}"), Some(&payload))?;
        Ok(())
    }

    #[allow(clippy::too_many_arguments)]
    pub fn publish(
        &self,
        subject: &str,
        data: &[u8],
        headers: Option<Headers>,
        msg_id: Option<&str>,
        expected_last_msg_id: Option<&str>,
        expected_last_seq: Option<i64>,
        expected_last_subject_seq: Option<i64>,
        expected_stream: Option<&str>,
        ttl: Option<&str>,
        retry_on_no_responders: i64,
    ) -> Result<PubAck, NatsError> {
        let mut headers = headers.unwrap_or_default();
        if let Some(v) = msg_id {
            headers.set("Nats-Msg-Id", v);
        }
        if let Some(v) = expected_last_msg_id {
            headers.set("Nats-Expected-Last-Msg-Id", v);
        }
        if let Some(v) = expected_last_seq {
            headers.set("Nats-Expected-Last-Sequence", v.to_string());
        }
        if let Some(v) = expected_last_subject_seq {
            headers.set("Nats-Expected-Last-Subject-Sequence", v.to_string());
        }
        if let Some(v) = expected_stream {
            headers.set("Nats-Expected-Stream", v);
        }
        if let Some(v) = ttl {
            headers.set("Nats-TTL", v);
        }
        let use_headers = if headers.is_empty() {
            None
        } else {
            Some(headers)
        };
        let mut attempt = 0i64;
        let response = loop {
            match self.conn.request(subject, data, None, use_headers.as_ref()) {
                Ok(r) => break r,
                Err(e) if e.message() == "No responders for request" => {
                    if attempt >= retry_on_no_responders {
                        return Err(e);
                    }
                    attempt += 1;
                    thread::sleep(Duration::from_millis(50 * attempt as u64));
                }
                Err(e) => return Err(e),
            }
        };
        let response_data: Value = serde_json::from_slice(&response.data).unwrap_or(json!({}));
        Self::check_error(&response_data)?;
        Ok(PubAck::from_array(&response_data))
    }

    pub fn create_key_value(
        &self,
        config: &crate::key_value::KeyValueConfig,
    ) -> Result<crate::key_value::KeyValue, NatsError> {
        let stream_config = config.to_stream_config();
        self.create_or_update_stream(&stream_config)?;
        Ok(crate::key_value::KeyValue::new(
            self.conn.clone(),
            self.clone(),
            &config.bucket,
        ))
    }

    pub fn get_key_value(&self, bucket: &str) -> Result<crate::key_value::KeyValue, NatsError> {
        self.get_stream_info(&format!("KV_{bucket}"))?;
        Ok(crate::key_value::KeyValue::new(
            self.conn.clone(),
            self.clone(),
            bucket,
        ))
    }

    pub fn delete_key_value(&self, bucket: &str) -> Result<(), NatsError> {
        self.delete_stream(&format!("KV_{bucket}"))
    }

    pub fn get_object_store(
        &self,
        bucket: &str,
    ) -> Result<crate::object_store::ObjectStore, NatsError> {
        self.get_stream_info(&format!("OBJ_{bucket}"))?;
        Ok(crate::object_store::ObjectStore::new(
            self.conn.clone(),
            self.clone(),
            bucket,
        ))
    }

    pub fn delete_object_store(&self, bucket: &str) -> Result<(), NatsError> {
        self.delete_stream(&format!("OBJ_{bucket}"))
    }

    pub fn account_info(&self) -> Result<AccountInfo, NatsError> {
        Ok(AccountInfo::from_array(&self.api_request("INFO", None)?))
    }

    pub fn key_value(&self, bucket: &str) -> crate::key_value::KeyValue {
        crate::key_value::KeyValue::new(self.conn.clone(), self.clone(), bucket)
    }

    pub fn object_store(&self, bucket: &str) -> crate::object_store::ObjectStore {
        crate::object_store::ObjectStore::new(self.conn.clone(), self.clone(), bucket)
    }

    pub fn api_request(&self, endpoint: &str, payload: Option<&Value>) -> Result<Value, NatsError> {
        let subject = format!("{}.{}", self.api_prefix, endpoint);
        let body = payload.map_or_else(Vec::new, |p| {
            serde_json::to_vec(p).unwrap_or_else(|_| b"{}".to_vec())
        });
        let msg = self.conn.request(&subject, &body, None, None)?;
        let data: Value = serde_json::from_slice(&msg.data).unwrap_or(json!({}));
        Self::check_error(&data)?;
        Ok(data)
    }

    pub fn check_error(data: &Value) -> Result<(), NatsError> {
        if let Some(err) = ApiError::from_value(data) {
            return Err(JetStreamException {
                message: err.description.clone(),
                api_error: Some(err),
            }
            .into());
        }
        Ok(())
    }
}

#[derive(Clone, Debug)]
pub struct Stream {
    pub js: JetStream,
    pub info: StreamInfo,
}

impl Stream {
    pub fn get_name(&self) -> &str {
        &self.info.config.name
    }
    pub fn get_config(&self) -> &StreamConfig {
        &self.info.config
    }
    pub fn get_state(&self) -> &StreamState {
        &self.info.state
    }
    pub fn info(&mut self, refresh: bool) -> Result<&StreamInfo, NatsError> {
        if refresh {
            self.info = self.js.get_stream_info(self.get_name())?;
        }
        Ok(&self.info)
    }
    pub fn create_consumer(&self, config: &ConsumerConfig) -> Result<Consumer, NatsError> {
        self.js.create_consumer(self.get_name(), config)
    }
    pub fn get_consumer(&self, name: &str) -> Result<Consumer, NatsError> {
        self.js.get_consumer(self.get_name(), name)
    }
    pub fn delete_consumer(&self, name: &str) -> Result<(), NatsError> {
        self.js.delete_consumer(self.get_name(), name)
    }
    pub fn purge(&self, subject: Option<&str>) -> Result<(), NatsError> {
        self.js.purge_stream(self.get_name(), subject)
    }
    pub fn delete(&self) -> Result<(), NatsError> {
        self.js.delete_stream(self.get_name())
    }
}

#[derive(Debug, Clone)]
pub struct PubAck {
    pub stream: String,
    pub sequence: i64,
    pub domain: Option<String>,
    pub duplicate: bool,
}

impl PubAck {
    pub fn from_array(data: &Value) -> Self {
        Self {
            stream: json_str(data, "stream").unwrap_or_default(),
            sequence: json_i64(data, "seq").unwrap_or(0),
            domain: json_str(data, "domain"),
            duplicate: json_bool(data, "duplicate"),
        }
    }
}

#[derive(Debug, Clone)]
pub struct AccountInfo {
    pub memory: i64,
    pub storage: i64,
    pub streams: i64,
    pub consumers: i64,
    pub limits: Value,
    pub api_total: i64,
    pub api_errors: i64,
    pub domain: Option<String>,
    pub raw: Value,
}

impl AccountInfo {
    pub fn from_array(data: &Value) -> Self {
        Self {
            memory: json_i64(data, "memory").unwrap_or(0),
            storage: json_i64(data, "storage").unwrap_or(0),
            streams: json_i64(data, "streams").unwrap_or(0),
            consumers: json_i64(data, "consumers").unwrap_or(0),
            limits: data.get("limits").cloned().unwrap_or(json!({})),
            api_total: data
                .get("api")
                .and_then(|a| a.get("total"))
                .and_then(Value::as_i64)
                .unwrap_or(0),
            api_errors: data
                .get("api")
                .and_then(|a| a.get("errors"))
                .and_then(Value::as_i64)
                .unwrap_or(0),
            domain: json_str(data, "domain"),
            raw: data.clone(),
        }
    }
}

#[derive(Debug, Clone)]
pub struct MsgMetadata {
    pub stream: String,
    pub consumer: String,
    pub num_delivered: i64,
    pub stream_sequence: i64,
    pub consumer_sequence: i64,
    pub timestamp: String,
    pub num_pending: i64,
    pub domain: Option<String>,
}

impl MsgMetadata {
    pub fn from_reply_subject(reply: &str) -> Result<Self, NatsError> {
        let parts: Vec<&str> = reply.split('.').collect();
        if parts.len() >= 9 && parts[0] == "$JS" && parts[1] == "ACK" {
            if parts.len() == 9 {
                return Ok(Self {
                    stream: parts[2].to_owned(),
                    consumer: parts[3].to_owned(),
                    num_delivered: parts[4].parse().unwrap_or(0),
                    stream_sequence: parts[5].parse().unwrap_or(0),
                    consumer_sequence: parts[6].parse().unwrap_or(0),
                    timestamp: parts[7].to_owned(),
                    num_pending: parts[8].parse().unwrap_or(0),
                    domain: None,
                });
            }
            if parts.len() >= 11 {
                return Ok(Self {
                    stream: parts[4].to_owned(),
                    consumer: parts[5].to_owned(),
                    num_delivered: parts[6].parse().unwrap_or(0),
                    stream_sequence: parts[7].parse().unwrap_or(0),
                    consumer_sequence: parts[8].parse().unwrap_or(0),
                    timestamp: parts[9].to_owned(),
                    num_pending: parts[10].parse().unwrap_or(0),
                    domain: Some(parts[2].to_owned()),
                });
            }
        }
        Err(
            crate::error::NatsException(format!("Cannot parse JetStream reply subject: {reply}"))
                .into(),
        )
    }
}

#[derive(Clone, Debug)]
pub struct JetStreamMessage {
    conn: Connection,
    pub message: Message,
}

impl JetStreamMessage {
    pub fn new(conn: Connection, message: Message) -> Self {
        Self { conn, message }
    }
    pub fn ack(&self) -> Result<(), NatsError> {
        self.respond(b"")
    }
    pub fn ack_sync(&self, timeout: Option<f64>) -> Result<(), NatsError> {
        let reply = self.message.reply_to.as_ref().ok_or_else(|| {
            crate::error::NatsException("Cannot acknowledge: message has no reply subject".into())
        })?;
        self.conn
            .request(reply, b"", Some(timeout.unwrap_or(5.0)), None)?;
        Ok(())
    }
    pub fn nak(&self, delay: Option<f64>) -> Result<(), NatsError> {
        if let Some(d) = delay {
            let nanos = seconds_to_nanos(d);
            self.respond(format!("-NAK {{\"delay\":{nanos}}}").as_bytes())
        } else {
            self.respond(b"-NAK")
        }
    }
    pub fn in_progress(&self) -> Result<(), NatsError> {
        self.respond(b"+WPI")
    }
    pub fn term(&self, reason: Option<&str>) -> Result<(), NatsError> {
        if let Some(r) = reason {
            self.respond(format!("+TERM {r}").as_bytes())
        } else {
            self.respond(b"+TERM")
        }
    }
    pub fn metadata(&self) -> Result<MsgMetadata, NatsError> {
        let reply = self.message.reply_to.as_ref().ok_or_else(|| {
            crate::error::NatsException("Message has no reply subject for metadata parsing".into())
        })?;
        MsgMetadata::from_reply_subject(reply)
    }
    pub fn get_data(&self) -> &[u8] {
        &self.message.data
    }
    pub fn get_subject(&self) -> &str {
        &self.message.subject
    }
    pub fn get_headers(&self) -> Option<&Headers> {
        self.message.headers.as_ref()
    }
    fn respond(&self, data: &[u8]) -> Result<(), NatsError> {
        let reply = self.message.reply_to.as_ref().ok_or_else(|| {
            crate::error::NatsException("Cannot acknowledge: message has no reply subject".into())
        })?;
        self.conn.publish(reply, data, None, None)
    }
}

#[derive(Clone, Debug)]
pub struct MessageBatch {
    conn: Connection,
    messages: Vec<JetStreamMessage>,
}

impl MessageBatch {
    pub fn new(conn: Connection) -> Self {
        Self {
            conn,
            messages: Vec::new(),
        }
    }
    pub fn add_message(&mut self, msg: Message) {
        self.messages
            .push(JetStreamMessage::new(self.conn.clone(), msg));
    }
    pub fn get_messages(&self) -> &[JetStreamMessage] {
        &self.messages
    }
    pub fn len(&self) -> usize {
        self.messages.len()
    }
    pub fn is_empty(&self) -> bool {
        self.messages.is_empty()
    }
}

impl IntoIterator for MessageBatch {
    type Item = JetStreamMessage;
    type IntoIter = std::vec::IntoIter<JetStreamMessage>;
    fn into_iter(self) -> Self::IntoIter {
        self.messages.into_iter()
    }
}

#[derive(Clone, Debug)]
pub struct Consumer {
    conn: Connection,
    stream: String,
    info: ConsumerInfo,
    api_prefix: String,
}

impl Consumer {
    pub fn new(
        conn: Connection,
        stream: impl Into<String>,
        info: ConsumerInfo,
        api_prefix: impl Into<String>,
    ) -> Self {
        Self {
            conn,
            stream: stream.into(),
            info,
            api_prefix: api_prefix.into(),
        }
    }

    pub fn fetch(
        &self,
        batch: i64,
        timeout: Option<f64>,
        no_wait: bool,
        max_bytes: Option<i64>,
    ) -> Result<MessageBatch, NatsError> {
        let timeout = timeout.unwrap_or(5.0);
        let request_subject = format!(
            "{}.CONSUMER.MSG.NEXT.{}.{}",
            self.api_prefix,
            self.stream,
            self.get_name()
        );
        let mut request = json!({
            "batch": batch,
            "expires": seconds_to_nanos(timeout),
        });
        if no_wait {
            request["no_wait"] = json!(true);
        }
        if let Some(b) = max_bytes {
            request["max_bytes"] = json!(b);
        }
        let payload = serde_json::to_vec(&request).unwrap_or_else(|_| b"{}".to_vec());
        let inbox = self.conn.new_inbox();
        let sub = self.conn.subscribe(&inbox, None, None)?;
        self.conn
            .publish(&request_subject, &payload, Some(&inbox), None)?;
        let mut message_batch = MessageBatch::new(self.conn.clone());
        let deadline = Instant::now() + Duration::from_secs_f64(timeout);
        while (message_batch.len() as i64) < batch {
            let remaining = deadline
                .saturating_duration_since(Instant::now())
                .as_secs_f64();
            if remaining <= 0.0 {
                break;
            }
            let Some(msg) = sub.next_message(Some(remaining)) else {
                break;
            };
            if let Some(headers) = &msg.headers {
                let status = headers.get_status();
                if matches!(status, "408" | "404" | "409") {
                    break;
                }
                if status == "100" {
                    if let Some(reply) = msg.reply_to.as_deref().filter(|s| !s.is_empty()) {
                        let _ = self.conn.publish(reply, b"", None, None);
                    }
                    continue;
                }
            }
            message_batch.add_message(msg);
        }
        sub.unsubscribe(None);
        Ok(message_batch)
    }

    pub fn next(&self, timeout: Option<f64>) -> Result<Option<JetStreamMessage>, NatsError> {
        let batch = self.fetch(1, timeout, false, None)?;
        Ok(batch.into_iter().next())
    }

    pub fn info(&mut self, refresh: bool) -> ConsumerInfo {
        if refresh {
            let subject = format!(
                "{}.CONSUMER.INFO.{}.{}",
                self.api_prefix,
                self.stream,
                self.get_name()
            );
            match self.conn.request(&subject, b"", None, None) {
                Ok(response) => {
                    let data: Value = serde_json::from_slice(&response.data).unwrap_or(json!({}));
                    if JetStream::check_error(&data).is_ok() {
                        self.info = ConsumerInfo::from_array(&data);
                    }
                }
                Err(e) if e.is_timeout() => {}
                Err(_) => {}
            }
        }
        self.info.clone()
    }

    pub fn get_name(&self) -> &str {
        &self.info.name
    }
    pub fn get_stream(&self) -> &str {
        &self.stream
    }
}

#[derive(Clone, Debug)]
pub struct PushSubscription {
    sub: Subscription,
    info: ConsumerInfo,
}

impl PushSubscription {
    pub fn new(
        conn: Connection,
        info: ConsumerInfo,
        callback: impl Fn(JetStreamMessage) + Send + Sync + 'static,
    ) -> Result<Self, NatsError> {
        let deliver_subject = info.config.deliver_subject.clone().ok_or_else(|| {
            crate::error::NatsException(
                "Consumer has no deliver subject; not a push consumer".into(),
            )
        })?;
        let conn_cb = conn.clone();
        let handler: MessageCallback = Arc::new(move |msg| {
            if Self::handle_control(&conn_cb, &msg) {
                return;
            }
            callback(JetStreamMessage::new(conn_cb.clone(), msg));
        });
        let queue = info.config.deliver_group.as_deref();
        let sub = conn.subscribe(&deliver_subject, Some(handler), queue)?;
        Ok(Self { sub, info })
    }

    pub fn handle_control(conn: &Connection, msg: &Message) -> bool {
        let Some(headers) = &msg.headers else {
            return false;
        };
        if headers.get_status() != "100" {
            return false;
        }
        if let Some(reply) = msg.reply_to.as_deref().filter(|s| !s.is_empty()) {
            let _ = conn.publish(reply, b"", None, None);
            return true;
        }
        if let Some(stalled) = headers
            .get("Nats-Consumer-Stalled")
            .filter(|s| !s.is_empty())
        {
            let _ = conn.publish(stalled, b"", None, None);
        }
        true
    }

    pub fn get_subscription(&self) -> &Subscription {
        &self.sub
    }
    pub fn get_consumer_info(&self) -> &ConsumerInfo {
        &self.info
    }
    pub fn get_consumer_name(&self) -> &str {
        &self.info.name
    }
    pub fn unsubscribe(&self) {
        self.sub.unsubscribe(None);
    }
}

#[derive(Debug)]
pub struct OrderedConsumer {
    conn: Connection,
    js: JetStream,
    stream: String,
    deliver_policy: DeliverPolicy,
    filter_subject: Option<String>,
    idle_heartbeat: f64,
    sub: Subscription,
    info: ConsumerInfo,
    expected_consumer_seq: i64,
    last_stream_seq: i64,
}

impl OrderedConsumer {
    pub fn new(
        conn: Connection,
        js: JetStream,
        stream: impl Into<String>,
        deliver_policy: DeliverPolicy,
        filter_subject: Option<&str>,
        idle_heartbeat: f64,
    ) -> Result<Self, NatsError> {
        let mut oc = Self {
            conn,
            js,
            stream: stream.into(),
            deliver_policy,
            filter_subject: filter_subject.map(str::to_owned),
            idle_heartbeat,
            sub: Subscription::new("0", "", None, None, 0, 0, None),
            info: ConsumerInfo::from_array(&json!({})),
            expected_consumer_seq: 1,
            last_stream_seq: 0,
        };
        oc.create(None)?;
        Ok(oc)
    }

    pub fn next(&mut self, timeout: Option<f64>) -> Result<Option<JetStreamMessage>, NatsError> {
        let timeout = timeout.unwrap_or(5.0);
        let deadline = Instant::now() + Duration::from_secs_f64(timeout);
        loop {
            let remaining = deadline
                .saturating_duration_since(Instant::now())
                .as_secs_f64();
            if remaining <= 0.0 {
                return Ok(None);
            }
            let Some(msg) = self.sub.next_message(Some(remaining)) else {
                return Ok(None);
            };
            if PushSubscription::handle_control(&self.conn, &msg) {
                if let Some(last) = msg
                    .headers
                    .as_ref()
                    .and_then(|h| h.get("Nats-Last-Consumer"))
                {
                    if last.parse::<i64>().unwrap_or(0) > self.expected_consumer_seq - 1 {
                        self.reset(self.last_stream_seq + 1)?;
                    }
                }
                continue;
            }
            let js_msg = JetStreamMessage::new(self.conn.clone(), msg);
            let meta = js_msg.metadata()?;
            if meta.consumer_sequence != self.expected_consumer_seq {
                self.reset(self.last_stream_seq + 1)?;
                continue;
            }
            self.expected_consumer_seq += 1;
            self.last_stream_seq = meta.stream_sequence;
            return Ok(Some(js_msg));
        }
    }

    pub fn get_consumer_name(&self) -> &str {
        &self.info.name
    }

    pub fn stop(&mut self) {
        self.teardown();
    }

    fn reset(&mut self, start_seq: i64) -> Result<(), NatsError> {
        self.teardown();
        self.create(Some(start_seq))
    }

    fn teardown(&mut self) {
        self.sub.unsubscribe(None);
        let _ = self.js.delete_consumer(&self.stream, &self.info.name);
    }

    fn create(&mut self, start_seq: Option<i64>) -> Result<(), NatsError> {
        let deliver_subject = self.conn.new_inbox();
        self.sub = self.conn.subscribe(&deliver_subject, None, None)?;
        let config = ConsumerConfig {
            deliver_policy: if start_seq.is_some() {
                DeliverPolicy::ByStartSequence
            } else {
                self.deliver_policy
            },
            ack_policy: AckPolicy::None,
            filter_subject: self.filter_subject.clone(),
            replay_policy: ReplayPolicy::Instant,
            inactive_threshold: Some(30.0),
            opt_start_seq: start_seq,
            deliver_subject: Some(deliver_subject),
            flow_control: true,
            idle_heartbeat: Some(self.idle_heartbeat),
            ..ConsumerConfig::default()
        };
        let mut consumer = self.js.create_consumer(&self.stream, &config)?;
        self.info = consumer.info(false);
        self.expected_consumer_seq = 1;
        Ok(())
    }
}
