//! PHP `tests/Messaging/Adapter/SMS/SMSTest.php` plus canned-provider request shapes.

use std::collections::HashMap;
use std::sync::Arc;

use serde_json::json;
use utopia_messaging::adapter::sms::{
    Clickatell, Fast2SMS, Infobip, Inforu, Mock as SmsMock, Msg91, Plivo, Seven, Sinch, Telesign,
    Telnyx, TextMagic, Twilio, Vonage,
};
use utopia_messaging::http::{HttpClient, SequenceClient, StubResponse};
use utopia_messaging::messages::SMS;
use utopia_messaging::{Adapter, SendResult};
use utopia_test_wiremock::{header, method, path, Mock, MockServer, ResponseTemplate};

fn runtime() -> tokio::runtime::Runtime {
    tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .expect("tokio runtime")
}

fn sms() -> SMS {
    SMS::new(
        vec!["+123456789".into()],
        "Test Content",
        Some("+987654321".into()),
        None,
        None,
    )
}

fn bind(adapter: &dyn Adapter, client: &Arc<SequenceClient>) {
    let inner = Arc::clone(client);
    adapter.set_client_factory(Arc::new(move |_, _| {
        let cloned = Arc::clone(&inner);
        let boxed: Arc<dyn HttpClient> = cloned;
        boxed
    }));
}

fn stub(status: i32, response: serde_json::Value) -> StubResponse {
    StubResponse {
        status_code: status,
        response,
        headers: HashMap::new(),
    }
}

fn delivered(result: SendResult) -> i64 {
    match result {
        SendResult::Response(data) => data.delivered_to,
        SendResult::Grouped(_) => panic!("expected SMS response"),
    }
}

#[test]
fn mock_sms_hits_wiremock_with_php_headers() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("POST"))
            .and(path("/mock-sms"))
            .and(header("user-agent", "Appwrite Mock Message Sender"))
            .and(header("x-username", "username"))
            .and(header("x-key", "password"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({})))
            .mount(&server)
            .await;
    });

    let sender = SmsMock::new("username", "password");
    sender.set_endpoint(format!("{}/mock-sms", server.uri()));
    sender.send(&sms()).unwrap();

    let requests = rt.block_on(server.received_requests()).unwrap();
    assert_eq!(requests.len(), 1);
    let request = &requests[0];
    assert_eq!(request.method.as_str(), "POST");
    assert!(request.url.as_str().ends_with("/mock-sms"));
    let body: serde_json::Value = serde_json::from_slice(&request.body).unwrap();
    assert_eq!(body["from"], json!("+987654321"));
    assert_eq!(body["to"], json!("+123456789"));
    assert_eq!(body["message"], json!("Test Content"));
}

#[test]
fn twilio_posts_form_to_messages_json() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(201, json!({})));
    let adapter = Twilio::new("ACxxx", "token", Some("+987654321".into()), None);
    bind(&adapter, &client);
    assert_eq!(delivered(adapter.send(&sms()).unwrap()), 1);
    let captured = &client.captured_requests()[0];
    assert_eq!(captured.method, "POST");
    assert_eq!(
        captured.url,
        "https://api.twilio.com/2010-04-01/Accounts/ACxxx/Messages.json"
    );
    assert!(captured
        .headers
        .iter()
        .any(|h| h.starts_with("Authorization: Basic ")));
    let body = captured.body.as_ref().unwrap();
    assert_eq!(body["Body"], json!("Test Content"));
    assert_eq!(body["From"], json!("+987654321"));
    assert_eq!(body["To"], json!("+123456789"));
}

#[test]
fn clickatell_posts_json_messages() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(202, json!({})));
    let adapter = Clickatell::new("api-key", None);
    bind(&adapter, &client);
    adapter.send(&sms()).unwrap();
    let captured = &client.captured_requests()[0];
    assert_eq!(captured.url, "https://platform.clickatell.com/messages");
    assert!(captured
        .headers
        .iter()
        .any(|h| h == "Authorization: api-key"));
    assert_eq!(captured.body.as_ref().unwrap()["to"], json!(["+123456789"]));
}

#[test]
fn msg91_posts_flow_with_authkey() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(200, json!({})));
    let adapter = Msg91::new("SNDR", "auth-key", "tmpl");
    bind(&adapter, &client);
    adapter.send(&sms()).unwrap();
    let captured = &client.captured_requests()[0];
    assert_eq!(captured.url, "https://api.msg91.com/api/v5/flow/");
    assert!(captured.headers.iter().any(|h| h == "Authkey: auth-key"));
    assert_eq!(
        captured.body.as_ref().unwrap()["template_id"],
        json!("tmpl")
    );
}

#[test]
fn vonage_treats_integer_zero_status_as_success() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(
        200,
        json!({"messages": [{"status": 0, "to": "123456789"}]}),
    ));
    let adapter = Vonage::new("key", "secret", None);
    bind(&adapter, &client);
    assert_eq!(delivered(adapter.send(&sms()).unwrap()), 1);
    assert_eq!(
        client.captured_requests()[0].url,
        "https://rest.nexmo.com/sms/json"
    );
}

#[test]
fn infobip_plivo_seven_sinch_telnyx_textmagic_inforu_fast2sms_telesign() {
    let cases: Vec<(&str, Box<dyn Adapter>, i32, serde_json::Value)> = vec![
        (
            "https://api.infobip.test/sms/2/text/advanced",
            Box::new(Infobip::new("api.infobip.test", "key", None)),
            200,
            json!({}),
        ),
        (
            "https://api.plivo.com/v1/Account/MAID/Message/",
            Box::new(Plivo::new("MAID", "token", None)),
            202,
            json!({}),
        ),
        (
            "https://gateway.sms77.io/api/sms",
            Box::new(Seven::new("seven-key", None)),
            200,
            json!({}),
        ),
        (
            "https://sms.api.sinch.com/xms/v1/plan/batches",
            Box::new(Sinch::new("plan", "token", None)),
            201,
            json!({}),
        ),
        (
            "https://api.telnyx.com/v2/messages",
            Box::new(Telnyx::new("telnyx-key", None)),
            200,
            json!({}),
        ),
        (
            "https://rest.textmagic.com/api/v2/messages",
            Box::new(TextMagic::new("user", "key", None)),
            201,
            json!({}),
        ),
        (
            "https://capi.inforu.co.il/api/v2/SMS/SendSms",
            Box::new(Inforu::new("sender", "token")),
            200,
            json!({}),
        ),
        (
            "https://www.fast2sms.com/dev/bulkV2",
            Box::new(Fast2SMS::new("key", "SNDR", "", false)),
            200,
            json!({"return": true}),
        ),
        (
            "https://rest-ww.telesign.com/v1/verify/bulk_sms",
            Box::new(Telesign::new("cid", "key")),
            200,
            json!({}),
        ),
    ];

    for (url, adapter, status, body) in cases {
        let client = Arc::new(SequenceClient::new());
        client.push_stub(stub(status, body));
        bind(&*adapter, &client);
        adapter.send(&sms()).unwrap();
        assert_eq!(
            client.captured_requests()[0].url,
            url,
            "unexpected URL for {}",
            adapter.get_name()
        );
        assert_eq!(client.captured_requests()[0].method, "POST");
    }
}

#[test]
fn live_twilio() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(201, json!({"sid": "SM123", "status": "queued"})));
    let adapter = Twilio::new("ACxxx", "token", Some("+987654321".into()), None);
    bind(&adapter, &client);
    adapter
        .send(&SMS::new(
            vec!["+123456789".into()],
            "utopia-messaging live test",
            None,
            None,
            None,
        ))
        .unwrap();
    assert_eq!(
        client.captured_requests()[0].url,
        "https://api.twilio.com/2010-04-01/Accounts/ACxxx/Messages.json"
    );
}
