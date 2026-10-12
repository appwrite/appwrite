//! Stream and consumer configuration, as the JetStream API reads and
//! writes it.

use indexmap::IndexMap;
use serde_json::Value;

use crate::fields::{self, Object};

macro_rules! policy {
    ($(#[$meta:meta])* $name:ident { $($(#[$vmeta:meta])* $variant:ident => $text:literal),+ $(,)? } default $default:ident) => {
        $(#[$meta])*
        #[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Default)]
        pub enum $name {
            $(
                $(#[$vmeta])*
                $variant,
            )+
        }

        impl $name {
            /// The value on the wire.
            ///
            /// PHP: the enum case's `$value`.
            pub fn as_str(self) -> &'static str {
                match self {
                    $($name::$variant => $text,)+
                }
            }

            /// The case for a wire value.
            ///
            /// PHP: `tryFrom()`.
            pub fn parse(value: &str) -> Option<Self> {
                match value {
                    $($text => Some($name::$variant),)+
                    _ => None,
                }
            }
        }

        impl std::fmt::Display for $name {
            fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
                f.write_str(self.as_str())
            }
        }
    };
}

policy! {
    /// How a consumer's messages must be acknowledged.
    ///
    /// PHP: `Utopia\NATS\JetStream\AckPolicy`.
    AckPolicy {
        /// `none`: no acknowledgements.
        None => "none",
        /// `all`: acknowledging one acknowledges every earlier one.
        All => "all",
        /// `explicit`: every message on its own.
        #[default]
        Explicit => "explicit",
    } default Explicit
}

policy! {
    /// Where a consumer starts in its stream.
    ///
    /// PHP: `Utopia\NATS\JetStream\DeliverPolicy`.
    DeliverPolicy {
        /// `all`: from the first message.
        #[default]
        All => "all",
        /// `last`: from the last message.
        Last => "last",
        /// `new`: only messages stored from now on.
        New => "new",
        /// `by_start_sequence`: from `opt_start_seq`.
        ByStartSequence => "by_start_sequence",
        /// `by_start_time`: from `opt_start_time`.
        ByStartTime => "by_start_time",
        /// `last_per_subject`: the last message of each subject.
        LastPerSubject => "last_per_subject",
    } default All
}

policy! {
    /// What a full stream does with a new message.
    ///
    /// PHP: `Utopia\NATS\JetStream\DiscardPolicy`.
    DiscardPolicy {
        /// `old`: drops the oldest messages.
        #[default]
        Old => "old",
        /// `new`: refuses the new message.
        New => "new",
    } default Old
}

policy! {
    /// How fast a consumer replays stored messages.
    ///
    /// PHP: `Utopia\NATS\JetStream\ReplayPolicy`.
    ReplayPolicy {
        /// `instant`: as fast as possible.
        #[default]
        Instant => "instant",
        /// `original`: at the pace they were stored.
        Original => "original",
    } default Instant
}

policy! {
    /// When a stream lets go of messages.
    ///
    /// PHP: `Utopia\NATS\JetStream\RetentionPolicy`.
    RetentionPolicy {
        /// `limits`: when its limits are reached.
        #[default]
        Limits => "limits",
        /// `interest`: once every consumer acknowledged them.
        Interest => "interest",
        /// `workqueue`: once one consumer acknowledged them.
        WorkQueue => "workqueue",
    } default Limits
}

policy! {
    /// Where a stream keeps its messages.
    ///
    /// PHP: `Utopia\NATS\JetStream\StorageType`.
    StorageType {
        /// `file`.
        #[default]
        File => "file",
        /// `memory`.
        Memory => "memory",
    } default File
}

fn policy<T>(data: &Value, key: &str, parse: fn(&str) -> Option<T>, default: T) -> T {
    fields::get(data, key).and_then(Value::as_str).and_then(parse).unwrap_or(default)
}

/// Seconds to the nanoseconds the API takes, truncated.
///
/// PHP: `StreamConfig::secondsToNanos()`.
pub fn seconds_to_nanos(seconds: f64) -> i64 {
    fields::seconds_to_nanos(seconds)
}

/// The API's nanoseconds to seconds.
///
/// PHP: `StreamConfig::nanosToSeconds()`.
pub fn nanos_to_seconds(nanos: i64) -> f64 {
    fields::nanos_to_seconds(nanos)
}

fn opt_seconds(data: &Value, key: &str) -> Option<f64> {
    fields::opt_int(data, key).map(nanos_to_seconds)
}

/// A stream's configuration. Durations are in seconds (the API's
/// nanoseconds on the wire).
///
/// ```
/// use utopia_nats::jetstream::{StorageType, StreamConfig};
///
/// let config = StreamConfig { subjects: vec!["orders.>".into()], storage: StorageType::Memory, max_age: Some(86400.0), ..StreamConfig::new("ORDERS") };
/// let wire = config.to_value();
/// assert_eq!(wire["max_age"], 86_400_000_000_000i64);
/// assert_eq!(StreamConfig::from_value(&wire), config);
/// ```
///
/// ```php
/// use Utopia\NATS\JetStream\StorageType;
/// use Utopia\NATS\JetStream\StreamConfig;
///
/// $config = new StreamConfig(name: 'ORDERS', subjects: ['orders.>'], storage: StorageType::Memory, maxAge: 86400.0);
/// $config->toArray()['max_age']; // 86400000000000
/// ```
///
/// PHP: `Utopia\NATS\JetStream\StreamConfig`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct StreamConfig {
    /// `name`.
    pub name: String,
    /// `subjects` (left out when empty).
    pub subjects: Vec<String>,
    /// `description`.
    pub description: Option<String>,
    /// `retention`.
    pub retention: RetentionPolicy,
    /// `max_consumers` (`-1`: unlimited).
    pub max_consumers: i64,
    /// `max_msgs` (`-1`: unlimited).
    pub max_msgs: i64,
    /// `max_bytes` (`-1`: unlimited).
    pub max_bytes: i64,
    /// `max_msgs_per_subject` (`-1`: unlimited).
    pub max_msgs_per_subject: i64,
    /// `max_msg_size`.
    pub max_msg_size: Option<i64>,
    /// `max_age`, in seconds.
    pub max_age: Option<f64>,
    /// `storage`.
    pub storage: StorageType,
    /// `num_replicas`.
    pub replicas: i64,
    /// `discard`.
    pub discard: DiscardPolicy,
    /// `no_ack`.
    pub no_ack: bool,
    /// `duplicate_window`, in seconds.
    pub duplicate_window: Option<f64>,
    /// `allow_direct`.
    pub allow_direct: bool,
    /// `mirror_direct`.
    pub mirror_direct: bool,
    /// `sealed`.
    pub sealed: bool,
    /// `deny_delete`.
    pub deny_delete: bool,
    /// `deny_purge`.
    pub deny_purge: bool,
    /// `allow_rollup_hdrs`.
    pub allow_rollup: bool,
    /// `metadata`.
    pub metadata: Option<IndexMap<String, String>>,
    /// `mirror`.
    pub mirror: Option<StreamSource>,
    /// `sources`.
    pub sources: Option<Vec<StreamSource>>,
    /// `republish`.
    pub republish: Option<Republish>,
    /// `subject_transform`.
    pub subject_transform: Option<SubjectTransform>,
    /// `placement`.
    pub placement: Option<Placement>,
    /// `compression` (`none` or `s2`).
    pub compression: Option<String>,
    /// `first_seq`.
    pub first_seq: Option<i64>,
    /// `consumer_limits`.
    pub consumer_limits: Option<ConsumerLimits>,
    /// `allow_msg_ttl`: per-message TTLs (`Nats-TTL`).
    pub allow_msg_ttl: bool,
    /// `subject_delete_marker_ttl`, in seconds.
    pub subject_delete_marker_ttl: Option<f64>,
}

impl StreamConfig {
    /// The defaults for a stream called `name`: unlimited, file storage,
    /// one replica, discarding old messages.
    ///
    /// PHP: `new StreamConfig($name)`.
    pub fn new(name: impl Into<String>) -> Self {
        StreamConfig {
            name: name.into(),
            max_consumers: -1,
            max_msgs: -1,
            max_bytes: -1,
            max_msgs_per_subject: -1,
            replicas: 1,
            ..Default::default()
        }
    }

    /// The API's JSON object.
    ///
    /// PHP: `StreamConfig::toArray()`.
    pub fn to_value(&self) -> Value {
        let mut o = Object::new();
        o.set("name", self.name.as_str())
            .set("retention", self.retention.as_str())
            .set("max_consumers", self.max_consumers)
            .set("max_msgs", self.max_msgs)
            .set("max_bytes", self.max_bytes)
            .set("max_msgs_per_subject", self.max_msgs_per_subject)
            .set("storage", self.storage.as_str())
            .set("num_replicas", self.replicas)
            .set("discard", self.discard.as_str())
            .set("no_ack", self.no_ack)
            .set("allow_direct", self.allow_direct)
            .set("mirror_direct", self.mirror_direct)
            .set("sealed", self.sealed)
            .set("deny_delete", self.deny_delete)
            .set("deny_purge", self.deny_purge)
            .set("allow_rollup_hdrs", self.allow_rollup);
        if !self.subjects.is_empty() {
            o.set("subjects", fields::strings_value(&self.subjects));
        }
        o.opt("description", self.description.as_deref())
            .opt("max_msg_size", self.max_msg_size)
            .opt("max_age", self.max_age.map(seconds_to_nanos))
            .opt("duplicate_window", self.duplicate_window.map(seconds_to_nanos))
            .opt("metadata", self.metadata.as_ref().map(fields::map_value))
            .opt("mirror", self.mirror.as_ref().map(StreamSource::to_value))
            .opt("sources", self.sources.as_ref().map(|s| Value::Array(s.iter().map(StreamSource::to_value).collect())))
            .opt("republish", self.republish.as_ref().map(Republish::to_value))
            .opt("subject_transform", self.subject_transform.as_ref().map(SubjectTransform::to_value))
            .opt("placement", self.placement.as_ref().map(Placement::to_value))
            .opt("compression", self.compression.as_deref())
            .opt("first_seq", self.first_seq)
            .opt("consumer_limits", self.consumer_limits.as_ref().map(ConsumerLimits::to_value));
        if self.allow_msg_ttl {
            o.set("allow_msg_ttl", true);
        }
        o.opt("subject_delete_marker_ttl", self.subject_delete_marker_ttl.map(seconds_to_nanos));
        o.build()
    }

    /// Reads the API's JSON object; missing fields take their defaults.
    ///
    /// PHP: `StreamConfig::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        StreamConfig {
            name: fields::string(data, "name", ""),
            subjects: fields::opt_strings(data, "subjects").unwrap_or_default(),
            description: fields::opt_string(data, "description"),
            retention: policy(data, "retention", RetentionPolicy::parse, RetentionPolicy::Limits),
            max_consumers: fields::int(data, "max_consumers", -1),
            max_msgs: fields::int(data, "max_msgs", -1),
            max_bytes: fields::int(data, "max_bytes", -1),
            max_msgs_per_subject: fields::int(data, "max_msgs_per_subject", -1),
            max_msg_size: fields::opt_int(data, "max_msg_size"),
            max_age: opt_seconds(data, "max_age"),
            storage: policy(data, "storage", StorageType::parse, StorageType::File),
            replicas: fields::int(data, "num_replicas", 1),
            discard: policy(data, "discard", DiscardPolicy::parse, DiscardPolicy::Old),
            no_ack: fields::flag(data, "no_ack"),
            duplicate_window: opt_seconds(data, "duplicate_window"),
            allow_direct: fields::flag(data, "allow_direct"),
            mirror_direct: fields::flag(data, "mirror_direct"),
            sealed: fields::flag(data, "sealed"),
            deny_delete: fields::flag(data, "deny_delete"),
            deny_purge: fields::flag(data, "deny_purge"),
            allow_rollup: fields::flag(data, "allow_rollup_hdrs"),
            metadata: fields::opt_map(data, "metadata"),
            mirror: fields::get(data, "mirror").map(StreamSource::from_value),
            sources: fields::get(data, "sources")
                .map(|s| s.as_array().map(|a| a.iter().map(StreamSource::from_value).collect()).unwrap_or_default()),
            republish: fields::get(data, "republish").map(Republish::from_value),
            subject_transform: fields::get(data, "subject_transform").map(SubjectTransform::from_value),
            placement: fields::get(data, "placement").map(Placement::from_value),
            compression: fields::opt_string(data, "compression"),
            first_seq: fields::opt_int(data, "first_seq"),
            consumer_limits: fields::get(data, "consumer_limits").map(ConsumerLimits::from_value),
            allow_msg_ttl: fields::flag(data, "allow_msg_ttl"),
            subject_delete_marker_ttl: opt_seconds(data, "subject_delete_marker_ttl"),
        }
    }
}

/// A consumer's configuration. Durations are in seconds.
///
/// PHP: `Utopia\NATS\JetStream\ConsumerConfig`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct ConsumerConfig {
    /// `name`.
    pub name: Option<String>,
    /// `durable_name`.
    pub durable_name: Option<String>,
    /// `description`.
    pub description: Option<String>,
    /// `deliver_policy`.
    pub deliver_policy: DeliverPolicy,
    /// `ack_policy`.
    pub ack_policy: AckPolicy,
    /// `ack_wait`, in seconds.
    pub ack_wait: Option<f64>,
    /// `max_deliver`.
    pub max_deliver: Option<i64>,
    /// `filter_subject`.
    pub filter_subject: Option<String>,
    /// `filter_subjects`.
    pub filter_subjects: Option<Vec<String>>,
    /// `replay_policy`.
    pub replay_policy: ReplayPolicy,
    /// `max_waiting`.
    pub max_waiting: Option<i64>,
    /// `max_ack_pending`.
    pub max_ack_pending: Option<i64>,
    /// `inactive_threshold`, in seconds.
    pub inactive_threshold: Option<f64>,
    /// `opt_start_seq`.
    pub opt_start_seq: Option<i64>,
    /// `opt_start_time`.
    pub opt_start_time: Option<String>,
    /// `max_batch`.
    pub max_batch: Option<i64>,
    /// `max_bytes`.
    pub max_bytes: Option<i64>,
    /// `mem_storage`.
    pub mem_storage: bool,
    /// `num_replicas`.
    pub num_replicas: Option<i64>,
    /// `deliver_subject`: makes it a push consumer.
    pub deliver_subject: Option<String>,
    /// `deliver_group`.
    pub deliver_group: Option<String>,
    /// `flow_control`.
    pub flow_control: bool,
    /// `idle_heartbeat`, in seconds.
    pub idle_heartbeat: Option<f64>,
    /// `metadata`.
    pub metadata: Option<IndexMap<String, String>>,
    /// `backoff`: redelivery delays in seconds.
    pub backoff: Option<Vec<f64>>,
}

impl ConsumerConfig {
    /// The API's JSON object.
    ///
    /// PHP: `ConsumerConfig::toArray()`.
    pub fn to_value(&self) -> Value {
        let mut o = Object::new();
        o.set("deliver_policy", self.deliver_policy.as_str())
            .set("ack_policy", self.ack_policy.as_str())
            .set("replay_policy", self.replay_policy.as_str())
            .opt("name", self.name.as_deref())
            .opt("durable_name", self.durable_name.as_deref())
            .opt("description", self.description.as_deref())
            .opt("ack_wait", self.ack_wait.map(seconds_to_nanos))
            .opt("max_deliver", self.max_deliver)
            .opt("filter_subject", self.filter_subject.as_deref())
            .opt("filter_subjects", self.filter_subjects.as_deref().map(fields::strings_value))
            .opt("max_waiting", self.max_waiting)
            .opt("max_ack_pending", self.max_ack_pending)
            .opt("inactive_threshold", self.inactive_threshold.map(seconds_to_nanos))
            .opt("opt_start_seq", self.opt_start_seq)
            .opt("opt_start_time", self.opt_start_time.as_deref())
            .opt("max_batch", self.max_batch)
            .opt("max_bytes", self.max_bytes);
        if self.mem_storage {
            o.set("mem_storage", true);
        }
        o.opt("num_replicas", self.num_replicas)
            .opt("deliver_subject", self.deliver_subject.as_deref())
            .opt("deliver_group", self.deliver_group.as_deref());
        if self.flow_control {
            o.set("flow_control", true);
        }
        o.opt("idle_heartbeat", self.idle_heartbeat.map(seconds_to_nanos))
            .opt("metadata", self.metadata.as_ref().map(fields::map_value))
            .opt(
                "backoff",
                self.backoff
                    .as_ref()
                    .map(|b| Value::Array(b.iter().map(|s| Value::from(seconds_to_nanos(*s))).collect())),
            );
        o.build()
    }

    /// Reads the API's JSON object; missing fields take their defaults.
    ///
    /// PHP: `ConsumerConfig::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        ConsumerConfig {
            name: fields::opt_string(data, "name"),
            durable_name: fields::opt_string(data, "durable_name"),
            description: fields::opt_string(data, "description"),
            deliver_policy: policy(data, "deliver_policy", DeliverPolicy::parse, DeliverPolicy::All),
            ack_policy: policy(data, "ack_policy", AckPolicy::parse, AckPolicy::Explicit),
            ack_wait: opt_seconds(data, "ack_wait"),
            max_deliver: fields::opt_int(data, "max_deliver"),
            filter_subject: fields::opt_string(data, "filter_subject"),
            filter_subjects: fields::opt_strings(data, "filter_subjects"),
            replay_policy: policy(data, "replay_policy", ReplayPolicy::parse, ReplayPolicy::Instant),
            max_waiting: fields::opt_int(data, "max_waiting"),
            max_ack_pending: fields::opt_int(data, "max_ack_pending"),
            inactive_threshold: opt_seconds(data, "inactive_threshold"),
            opt_start_seq: fields::opt_int(data, "opt_start_seq"),
            opt_start_time: fields::opt_string(data, "opt_start_time"),
            max_batch: fields::opt_int(data, "max_batch"),
            max_bytes: fields::opt_int(data, "max_bytes"),
            mem_storage: fields::flag(data, "mem_storage"),
            num_replicas: fields::opt_int(data, "num_replicas"),
            deliver_subject: fields::opt_string(data, "deliver_subject"),
            deliver_group: fields::opt_string(data, "deliver_group"),
            flow_control: fields::flag(data, "flow_control"),
            idle_heartbeat: opt_seconds(data, "idle_heartbeat"),
            metadata: fields::opt_map(data, "metadata"),
            backoff: fields::get(data, "backoff").map(|b| {
                b.as_array()
                    .map(|a| a.iter().filter_map(Value::as_i64).map(nanos_to_seconds).collect())
                    .unwrap_or_default()
            }),
        }
    }
}

/// Limits a stream sets on its consumers.
///
/// PHP: `Utopia\NATS\JetStream\ConsumerLimits`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct ConsumerLimits {
    /// `inactive_threshold`, in seconds.
    pub inactive_threshold: Option<f64>,
    /// `max_ack_pending`.
    pub max_ack_pending: Option<i64>,
}

impl ConsumerLimits {
    /// The API's JSON object (`[]` when empty, as PHP encodes it).
    ///
    /// PHP: `ConsumerLimits::toArray()`.
    pub fn to_value(&self) -> Value {
        if self.inactive_threshold.is_none() && self.max_ack_pending.is_none() {
            return Value::Array(Vec::new());
        }
        Object::new()
            .opt("inactive_threshold", self.inactive_threshold.map(seconds_to_nanos))
            .opt("max_ack_pending", self.max_ack_pending)
            .build()
    }

    /// Reads the API's JSON object.
    ///
    /// PHP: `ConsumerLimits::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        ConsumerLimits {
            inactive_threshold: opt_seconds(data, "inactive_threshold"),
            max_ack_pending: fields::opt_int(data, "max_ack_pending"),
        }
    }
}

/// Where a stream's replicas are placed.
///
/// PHP: `Utopia\NATS\JetStream\Placement`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Placement {
    /// `cluster`.
    pub cluster: Option<String>,
    /// `tags`.
    pub tags: Option<Vec<String>>,
}

impl Placement {
    /// The API's JSON object (`[]` when empty).
    ///
    /// PHP: `Placement::toArray()`.
    pub fn to_value(&self) -> Value {
        if self.cluster.is_none() && self.tags.is_none() {
            return Value::Array(Vec::new());
        }
        Object::new()
            .opt("cluster", self.cluster.as_deref())
            .opt("tags", self.tags.as_deref().map(fields::strings_value))
            .build()
    }

    /// Reads the API's JSON object.
    ///
    /// PHP: `Placement::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        Placement { cluster: fields::opt_string(data, "cluster"), tags: fields::opt_strings(data, "tags") }
    }
}

/// Republishes stored messages to another subject.
///
/// PHP: `Utopia\NATS\JetStream\Republish`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Republish {
    /// `src`.
    pub source: String,
    /// `dest`.
    pub destination: String,
    /// `headers_only`.
    pub headers_only: bool,
}

impl Republish {
    /// The API's JSON object.
    ///
    /// PHP: `Republish::toArray()`.
    pub fn to_value(&self) -> Value {
        let mut o = Object::new();
        o.set("src", self.source.as_str()).set("dest", self.destination.as_str());
        if self.headers_only {
            o.set("headers_only", true);
        }
        o.build()
    }

    /// Reads the API's JSON object.
    ///
    /// PHP: `Republish::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        Republish {
            source: fields::string(data, "src", ""),
            destination: fields::string(data, "dest", ""),
            headers_only: fields::flag(data, "headers_only"),
        }
    }
}

/// Rewrites subjects as messages are stored.
///
/// PHP: `Utopia\NATS\JetStream\SubjectTransform`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct SubjectTransform {
    /// `src`.
    pub source: String,
    /// `dest`.
    pub destination: String,
}

impl SubjectTransform {
    /// The API's JSON object.
    ///
    /// PHP: `SubjectTransform::toArray()`.
    pub fn to_value(&self) -> Value {
        Object::new().set("src", self.source.as_str()).set("dest", self.destination.as_str()).build()
    }

    /// Reads the API's JSON object.
    ///
    /// PHP: `SubjectTransform::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        SubjectTransform { source: fields::string(data, "src", ""), destination: fields::string(data, "dest", "") }
    }
}

/// A stream in another account or domain.
///
/// PHP: `Utopia\NATS\JetStream\ExternalStream`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct ExternalStream {
    /// `api`: its API prefix.
    pub api: String,
    /// `deliver`: its delivery prefix.
    pub deliver: Option<String>,
}

impl ExternalStream {
    /// The API's JSON object.
    ///
    /// PHP: `ExternalStream::toArray()`.
    pub fn to_value(&self) -> Value {
        Object::new().set("api", self.api.as_str()).opt("deliver", self.deliver.as_deref()).build()
    }

    /// Reads the API's JSON object.
    ///
    /// PHP: `ExternalStream::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        ExternalStream { api: fields::string(data, "api", ""), deliver: fields::opt_string(data, "deliver") }
    }
}

/// A stream a mirror copies, or one of the streams a stream sources.
///
/// PHP: `Utopia\NATS\JetStream\StreamSource`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct StreamSource {
    /// `name`.
    pub name: String,
    /// `opt_start_seq`.
    pub opt_start_seq: Option<i64>,
    /// `opt_start_time`.
    pub opt_start_time: Option<String>,
    /// `filter_subject`.
    pub filter_subject: Option<String>,
    /// `subject_transforms`.
    pub subject_transforms: Option<Vec<SubjectTransform>>,
    /// `external`.
    pub external: Option<ExternalStream>,
}

impl StreamSource {
    /// The source stream `name`, from its start.
    ///
    /// PHP: `new StreamSource($name)`.
    pub fn new(name: impl Into<String>) -> Self {
        StreamSource { name: name.into(), ..Default::default() }
    }

    /// The API's JSON object.
    ///
    /// PHP: `StreamSource::toArray()`.
    pub fn to_value(&self) -> Value {
        Object::new()
            .set("name", self.name.as_str())
            .opt("opt_start_seq", self.opt_start_seq)
            .opt("opt_start_time", self.opt_start_time.as_deref())
            .opt("filter_subject", self.filter_subject.as_deref())
            .opt(
                "subject_transforms",
                self.subject_transforms
                    .as_ref()
                    .map(|t| Value::Array(t.iter().map(SubjectTransform::to_value).collect())),
            )
            .opt("external", self.external.as_ref().map(ExternalStream::to_value))
            .build()
    }

    /// Reads the API's JSON object.
    ///
    /// PHP: `StreamSource::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        StreamSource {
            name: fields::string(data, "name", ""),
            opt_start_seq: fields::opt_int(data, "opt_start_seq"),
            opt_start_time: fields::opt_string(data, "opt_start_time"),
            filter_subject: fields::opt_string(data, "filter_subject"),
            subject_transforms: fields::get(data, "subject_transforms").map(|t| {
                t.as_array().map(|a| a.iter().map(SubjectTransform::from_value).collect()).unwrap_or_default()
            }),
            external: fields::get(data, "external").map(ExternalStream::from_value),
        }
    }
}
