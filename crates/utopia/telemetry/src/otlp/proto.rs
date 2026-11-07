//! The OTLP metrics protobuf messages this backend writes
//! (`opentelemetry/proto/collector/metrics/v1`), encoded by hand: the
//! messages are few and fixed, and a schema compiler would cost more than it
//! saves.

use crate::Value;

/// A protobuf message being written.
#[derive(Default)]
pub(crate) struct Message(pub(crate) Vec<u8>);

const VARINT: u64 = 0;
const FIXED64: u64 = 1;
const LEN: u64 = 2;

impl Message {
    fn varint(&mut self, mut value: u64) {
        while value >= 0x80 {
            self.0.push((value as u8) | 0x80);
            value >>= 7;
        }
        self.0.push(value as u8);
    }

    fn tag(&mut self, field: u64, wire: u64) {
        self.varint((field << 3) | wire);
    }

    /// A string field, skipped when empty (the proto3 default).
    pub(crate) fn string(&mut self, field: u64, value: &str) {
        if !value.is_empty() {
            self.bytes(field, value.as_bytes());
        }
    }

    pub(crate) fn bytes(&mut self, field: u64, value: &[u8]) {
        self.tag(field, LEN);
        self.varint(value.len() as u64);
        self.0.extend_from_slice(value);
    }

    /// A nested message field, written even when empty.
    pub(crate) fn message(&mut self, field: u64, build: impl FnOnce(&mut Message)) {
        let mut nested = Message::default();
        build(&mut nested);
        self.bytes(field, &nested.0);
    }

    /// An unsigned varint field (`uint32`, `bool`, enums), skipped when zero.
    pub(crate) fn uint(&mut self, field: u64, value: u64) {
        if value != 0 {
            self.tag(field, VARINT);
            self.varint(value);
        }
    }

    /// A `fixed64` field, skipped when zero.
    pub(crate) fn fixed64(&mut self, field: u64, value: u64) {
        if value != 0 {
            self.tag(field, FIXED64);
            self.0.extend_from_slice(&value.to_le_bytes());
        }
    }

    /// A `sfixed64` field in a oneof: always written.
    pub(crate) fn sfixed64(&mut self, field: u64, value: i64) {
        self.tag(field, FIXED64);
        self.0.extend_from_slice(&value.to_le_bytes());
    }

    /// A `double` field in a oneof or marked `optional`: always written.
    pub(crate) fn double(&mut self, field: u64, value: f64) {
        self.tag(field, FIXED64);
        self.0.extend_from_slice(&value.to_le_bytes());
    }

    /// A packed `repeated fixed64` field, skipped when empty.
    pub(crate) fn packed_fixed64(&mut self, field: u64, values: &[u64]) {
        if !values.is_empty() {
            let bytes: Vec<u8> = values.iter().flat_map(|v| v.to_le_bytes()).collect();
            self.bytes(field, &bytes);
        }
    }

    /// A packed `repeated double` field, skipped when empty.
    pub(crate) fn packed_double(&mut self, field: u64, values: &[f64]) {
        if !values.is_empty() {
            let bytes: Vec<u8> = values.iter().flat_map(|v| v.to_le_bytes()).collect();
            self.bytes(field, &bytes);
        }
    }

    /// A `KeyValue` (`key = 1`, `AnyValue value = 2`) in field `field`.
    pub(crate) fn key_value(&mut self, field: u64, key: &str, value: &Value<'_>) {
        self.message(field, |kv| {
            kv.string(1, key);
            kv.message(2, |any| match value {
                Value::Str(s) => any.bytes(1, s.as_bytes()),
                Value::Bool(b) => {
                    any.tag(2, VARINT);
                    any.varint(u64::from(*b));
                }
                Value::Int(i) => {
                    any.tag(3, VARINT);
                    any.varint(*i as u64);
                }
                Value::Float(f) => any.double(4, *f),
            });
        });
    }
}
