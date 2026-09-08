//! Port of `tests/Unit/JetStream/ConfigTest.php`.

use serde_json::json;
use utopia_nats::jetstream::{ConsumerConfig, ConsumerInfo, SequenceInfo, StreamMessage};

#[test]
#[allow(clippy::field_reassign_with_default)]
fn test_push_and_flow_control_fields_serialize() {
    let mut config = ConsumerConfig::default();
    config.deliver_subject = Some("deliver.here".into());
    config.deliver_group = Some("group-a".into());
    config.flow_control = true;
    config.idle_heartbeat = Some(2.0);

    let arr = config.to_array();
    assert_eq!(arr["deliver_subject"], "deliver.here");
    assert_eq!(arr["deliver_group"], "group-a");
    assert_eq!(arr["flow_control"], true);
    assert_eq!(arr["idle_heartbeat"], 2_000_000_000i64);
}

#[test]
fn test_consumer_config_round_trip() {
    let config = ConsumerConfig::from_array(&json!({
        "deliver_subject": "x.y",
        "flow_control": true,
        "idle_heartbeat": 5_000_000_000i64,
    }));
    assert_eq!(config.deliver_subject.as_deref(), Some("x.y"));
    assert!(config.flow_control);
    assert!((config.idle_heartbeat.unwrap() - 5.0).abs() < f64::EPSILON);
}

#[test]
fn test_consumer_info_populates_sequences() {
    let info = ConsumerInfo::from_array(&json!({
        "stream_name": "S",
        "name": "C",
        "num_pending": 7,
        "num_ack_pending": 3,
        "delivered": {"consumer_seq": 4, "stream_seq": 10},
        "ack_floor": {"consumer_seq": 1, "stream_seq": 7},
    }));
    assert_eq!(info.num_pending, 7);
    assert_eq!(info.num_ack_pending, 3);
    let _ = info.delivered.clone();
    let _: SequenceInfo = info.delivered.clone();
    assert_eq!(info.delivered.consumer_seq, 4);
    assert_eq!(info.delivered.stream_seq, 10);
    assert_eq!(info.ack_floor.stream_seq, 7);
}

#[test]
fn test_stream_message_decodes_base64_payload() {
    use base64::Engine;
    let msg = StreamMessage::from_array(&json!({
        "subject": "foo.bar",
        "seq": 42,
        "data": base64::engine::general_purpose::STANDARD.encode("the-payload"),
    }));
    assert_eq!(msg.subject, "foo.bar");
    assert_eq!(msg.sequence, 42);
    assert_eq!(msg.data, b"the-payload");
}
