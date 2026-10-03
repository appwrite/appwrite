//! PHP `tests/Messaging/Adapter/SMS/GEOSMSTest.php`.

use std::sync::Arc;

use parking_lot::Mutex;
use serde_json::{json, Map, Value};
use utopia_messaging::adapter::sms::geosms::CallingCode;
use utopia_messaging::adapter::sms::GEOSMS;
use utopia_messaging::adapter::sms::TYPE;
use utopia_messaging::adapter::{Adapter, AdapterBase, GroupedSend};
use utopia_messaging::messages::SMS;
use utopia_messaging::{Message, MessageKind, MessagingError, Response, SendResult};

struct RecordingSms {
    name: &'static str,
    base: AdapterBase,
    seen: Mutex<Vec<Option<Map<String, Value>>>>,
}

impl RecordingSms {
    fn new(name: &'static str) -> Self {
        Self {
            name,
            base: AdapterBase::default(),
            seen: Mutex::new(Vec::new()),
        }
    }

    fn success_response() -> SendResult {
        let mut response = Response::new(TYPE);
        response.add_result("to", "");
        SendResult::Response(response.to_array())
    }
}

impl Adapter for RecordingSms {
    fn get_name(&self) -> &'static str {
        self.name
    }

    fn get_type(&self) -> &'static str {
        TYPE
    }

    fn get_message_type(&self) -> MessageKind {
        MessageKind::SMS
    }

    fn get_max_messages_per_request(&self) -> usize {
        1000
    }

    fn base(&self) -> &AdapterBase {
        &self.base
    }

    fn process(&self, message: &dyn Message) -> Result<SendResult, MessagingError> {
        let sms = message.as_sms().expect("sms");
        self.seen.lock().push(sms.get_metadata().cloned());
        Ok(Self::success_response())
    }
}

fn sms(to: Vec<&str>) -> SMS {
    SMS::new(
        to.into_iter().map(str::to_string).collect(),
        "Test Content",
        Some("Sender".into()),
        None,
        None,
    )
}

fn grouped(result: &SendResult) -> &std::collections::HashMap<String, GroupedSend> {
    match result {
        SendResult::Grouped(map) => map,
        SendResult::Response(_) => panic!("expected grouped GEOSMS result"),
    }
}

fn as_adapter(adapter: Arc<RecordingSms>) -> Arc<dyn Adapter> {
    adapter
}

fn success_status(send: &GroupedSend) -> &str {
    match send {
        GroupedSend::Response(data) => data.results[0].status.as_str(),
        GroupedSend::Error { .. } => panic!("expected nested response"),
    }
}

#[test]
fn send_sms_using_default_adapter() {
    let geo = GEOSMS::new(Arc::new(RecordingSms::new("default")));
    let result = geo.send(&sms(vec!["+11234567890"])).unwrap();
    let map = grouped(&result);
    assert_eq!(map.len(), 1);
    assert_eq!(success_status(&map["default"]), "success");
}

#[test]
fn send_sms_using_local_adapter() {
    let geo = GEOSMS::new(Arc::new(RecordingSms::new("default")));
    geo.set_local(CallingCode::INDIA, Arc::new(RecordingSms::new("local")));
    let result = geo.send(&sms(vec!["+911234567890"])).unwrap();
    let map = grouped(&result);
    assert_eq!(map.len(), 1);
    assert_eq!(success_status(&map["local"]), "success");
}

#[test]
fn send_sms_using_local_adapter_and_default() {
    let geo = GEOSMS::new(Arc::new(RecordingSms::new("default")));
    geo.set_local(CallingCode::INDIA, Arc::new(RecordingSms::new("local")));
    let result = geo
        .send(&sms(vec!["+911234567890", "+11234567890"]))
        .unwrap();
    let map = grouped(&result);
    assert_eq!(map.len(), 2);
    assert_eq!(success_status(&map["local"]), "success");
    assert_eq!(success_status(&map["default"]), "success");
}

#[test]
fn send_sms_using_grouped_local_adapter() {
    let geo = GEOSMS::new(Arc::new(RecordingSms::new("default")));
    let local = Arc::new(RecordingSms::new("local"));
    geo.set_local(CallingCode::INDIA, as_adapter(Arc::clone(&local)));
    geo.set_local(CallingCode::NORTH_AMERICA, as_adapter(Arc::clone(&local)));
    let result = geo
        .send(&sms(vec!["+911234567890", "+11234567890"]))
        .unwrap();
    let map = grouped(&result);
    assert_eq!(map.len(), 1);
    assert_eq!(success_status(&map["local"]), "success");
}

#[test]
fn send_sms_handles_metadata() {
    let metadata = {
        let mut map = Map::new();
        map.insert("clientId".into(), json!("client-123"));
        map.insert("CRQID".into(), json!("request_123"));
        map.insert("UUID".into(), json!("uuid.123"));
        map
    };

    let default = Arc::new(RecordingSms::new("default"));
    let geo = GEOSMS::new(as_adapter(Arc::clone(&default)));
    let message = SMS::new(
        vec!["+11234567890".into()],
        "Test Content",
        None,
        None,
        Some(metadata.clone()),
    );
    let result = geo.send(&message).unwrap();
    let map = grouped(&result);
    assert_eq!(map.len(), 1);
    assert_eq!(success_status(&map["default"]), "success");
    assert_eq!(default.seen.lock()[0].as_ref(), Some(&metadata));

    let default = Arc::new(RecordingSms::new("default"));
    let local = Arc::new(RecordingSms::new("local"));
    let geo = GEOSMS::new(as_adapter(Arc::clone(&default)));
    geo.set_local(CallingCode::INDIA, as_adapter(Arc::clone(&local)));
    let message = SMS::new(
        vec!["+911234567890".into(), "+11234567890".into()],
        "Test Content",
        None,
        None,
        Some(metadata.clone()),
    );
    let result = geo.send(&message).unwrap();
    let map = grouped(&result);
    assert_eq!(map.len(), 2);
    assert_eq!(success_status(&map["local"]), "success");
    assert_eq!(success_status(&map["default"]), "success");

    let local_meta = local.seen.lock()[0].clone().unwrap();
    assert_eq!(local_meta["clientId"], json!("client-123"));
    assert_eq!(local_meta["CRQID"], json!("request_123-1"));
    assert_eq!(local_meta["UUID"], json!("uuid.123-1"));

    let default_meta = default.seen.lock()[0].clone().unwrap();
    assert_eq!(default_meta["clientId"], json!("client-123"));
    assert_eq!(default_meta["CRQID"], json!("request_123-2"));
    assert_eq!(default_meta["UUID"], json!("uuid.123-2"));

    let mut invalid = Map::new();
    invalid.insert("CRQID".into(), json!([]));
    let message = SMS::new(
        vec!["+911234567890".into(), "+11234567890".into()],
        "Test Content",
        None,
        None,
        Some(invalid),
    );
    let err = geo.send(&message).unwrap_err();
    assert!(err
        .to_string()
        .contains("Msg91 CRQID metadata must be a string"));

    let mut empty = Map::new();
    empty.insert("CRQID".into(), json!(""));
    let message = SMS::new(
        vec!["+911234567890".into(), "+11234567890".into()],
        "Test Content",
        None,
        None,
        Some(empty),
    );
    let err = geo.send(&message).unwrap_err();
    assert!(err
        .to_string()
        .contains("Msg91 CRQID metadata must be 80 characters or less"));
}
