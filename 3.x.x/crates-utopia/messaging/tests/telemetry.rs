//! PHP `tests/Messaging/Adapter/TelemetryTest.php`.

use std::sync::Arc;

use utopia_messaging::adapter::sms::geosms::CallingCode;
use utopia_messaging::adapter::sms::GEOSMS;
use utopia_messaging::adapter::sms::TYPE;
use utopia_messaging::adapter::{Adapter, AdapterBase};
use utopia_messaging::messages::SMS;
use utopia_messaging::{Message, MessageKind, MessagingError, ResponseData, ResultRow, SendResult};
use utopia_telemetry::TestAdapter;

struct TelemetrySms {
    base: AdapterBase,
    canned: Option<ResponseData>,
    error: Option<&'static str>,
}

impl TelemetrySms {
    fn new(canned: Option<ResponseData>, error: Option<&'static str>) -> Self {
        Self {
            base: AdapterBase::default(),
            canned,
            error,
        }
    }
}

impl Adapter for TelemetrySms {
    fn get_name(&self) -> &'static str {
        "Test"
    }

    fn get_type(&self) -> &'static str {
        TYPE
    }

    fn get_message_type(&self) -> MessageKind {
        MessageKind::SMS
    }

    fn get_max_messages_per_request(&self) -> usize {
        100
    }

    fn base(&self) -> &AdapterBase {
        &self.base
    }

    fn process(&self, _message: &dyn Message) -> Result<SendResult, MessagingError> {
        if let Some(message) = self.error {
            return Err(MessagingError::message(message));
        }
        Ok(SendResult::Response(self.canned.clone().unwrap_or(
            ResponseData {
                delivered_to: 0,
                type_name: TYPE.into(),
                results: Vec::new(),
            },
        )))
    }
}

fn row(recipient: &str, status: &str, error: &str) -> ResultRow {
    ResultRow {
        recipient: recipient.into(),
        status: status.into(),
        error: error.into(),
    }
}

fn sms(to: Vec<&str>) -> SMS {
    SMS::new(
        to.into_iter().map(str::to_string).collect(),
        "Hello",
        None,
        None,
        None,
    )
}

fn tel(t: Arc<TestAdapter>) -> Arc<dyn utopia_telemetry::Adapter> {
    t
}

#[test]
fn records_successes_and_failures() {
    let telemetry = Arc::new(TestAdapter::new());
    let adapter = TelemetrySms::new(
        Some(ResponseData {
            delivered_to: 2,
            type_name: TYPE.into(),
            results: vec![
                row("+1", "success", ""),
                row("+2", "success", ""),
                row("+3", "failure", "Nope"),
            ],
        }),
        None,
    );
    adapter.set_telemetry(tel(Arc::clone(&telemetry)));
    adapter
        .send(&sms(vec!["+1", "+2", "+3"]).with_origin(Some("external".into())))
        .unwrap();

    let records = telemetry.counter_measurements("messaging.send");
    assert_eq!(records.len(), 2);
    assert_eq!(records[0].value as i64, 2);
    assert_eq!(records[0].attributes["result"], "success");
    assert_eq!(records[0].attributes["origin"], "external");
    assert_eq!(records[0].attributes["type"], "sms");
    assert_eq!(records[0].attributes["provider"], "test");
    assert_eq!(records[1].value as i64, 1);
    assert_eq!(records[1].attributes["result"], "failure");
    assert_eq!(records[1].attributes["origin"], "external");
    assert_eq!(records[1].attributes["type"], "sms");
    assert_eq!(records[1].attributes["provider"], "test");
}

#[test]
fn records_thrown_send_as_failure() {
    let telemetry = Arc::new(TestAdapter::new());
    let adapter = TelemetrySms::new(None, Some("Provider failed"));
    adapter.set_telemetry(tel(Arc::clone(&telemetry)));
    let err = adapter.send(&sms(vec!["+1", "+2"])).unwrap_err();
    assert!(err.to_string().contains("Provider failed"));
    let records = telemetry.counter_measurements("messaging.send");
    assert_eq!(records.len(), 1);
    assert_eq!(records[0].value as i64, 2);
    assert_eq!(records[0].attributes["result"], "failure");
    assert_eq!(records[0].attributes["type"], "sms");
    assert_eq!(records[0].attributes["provider"], "test");
    assert!(!records[0].attributes.contains_key("origin"));
}

#[test]
fn records_counts_from_results() {
    let telemetry = Arc::new(TestAdapter::new());
    let adapter = TelemetrySms::new(
        Some(ResponseData {
            delivered_to: 99,
            type_name: TYPE.into(),
            results: vec![row("+1", "success", ""), row("+2", "pending", "")],
        }),
        None,
    );
    adapter.set_telemetry(tel(Arc::clone(&telemetry)));
    adapter.send(&sms(vec!["+1", "+2"])).unwrap();
    let records = telemetry.counter_measurements("messaging.send");
    assert_eq!(records.len(), 2);
    assert_eq!(records[0].value as i64, 1);
    assert_eq!(records[0].attributes["result"], "success");
    assert_eq!(records[1].value as i64, 1);
    assert_eq!(records[1].attributes["result"], "failure");
}

#[test]
fn geosms_propagates_telemetry_to_local_adapters() {
    let telemetry = Arc::new(TestAdapter::new());
    let default = TelemetrySms::new(
        Some(ResponseData {
            delivered_to: 0,
            type_name: TYPE.into(),
            results: Vec::new(),
        }),
        None,
    );
    let local = TelemetrySms::new(
        Some(ResponseData {
            delivered_to: 1,
            type_name: TYPE.into(),
            results: vec![row("+911234567890", "success", "")],
        }),
        None,
    );
    let adapter = GEOSMS::new(Arc::new(default));
    adapter.set_telemetry(tel(Arc::clone(&telemetry)));
    adapter.set_local(CallingCode::INDIA, Arc::new(local));
    adapter
        .send(&sms(vec!["+911234567890"]).with_origin(Some("internal".into())))
        .unwrap();
    let records = telemetry.counter_measurements("messaging.send");
    assert_eq!(records.len(), 1);
    assert_eq!(records[0].value as i64, 1);
    assert_eq!(records[0].attributes["result"], "success");
    assert_eq!(records[0].attributes["origin"], "internal");
    assert_eq!(records[0].attributes["type"], "sms");
    assert_eq!(records[0].attributes["provider"], "test");
}

#[test]
fn default_telemetry_does_nothing() {
    let adapter = TelemetrySms::new(
        Some(ResponseData {
            delivered_to: 1,
            type_name: TYPE.into(),
            results: vec![row("+1", "success", "")],
        }),
        None,
    );
    match adapter.send(&sms(vec!["+1"])).unwrap() {
        SendResult::Response(data) => assert_eq!(data.delivered_to, 1),
        SendResult::Grouped(_) => panic!("expected response, got grouped"),
    }
}
