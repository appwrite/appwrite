//! Send validation, HTTP helpers, JWT, Discord, Response, Mailgun/Sendgrid.

use std::collections::HashMap;
use std::sync::Arc;

use serde_json::json;
use utopia_messaging::adapter::chat::Discord;
use utopia_messaging::adapter::email::{Mailgun, Sendgrid};
use utopia_messaging::adapter::sms::{Mock, Vonage};
use utopia_messaging::adapter::{Adapter, AdapterBase};
use utopia_messaging::helpers::JWT;
use utopia_messaging::http::{HttpClient, SequenceClient, StubResponse};
use utopia_messaging::messages::{Discord as DiscordMessage, Email, Push, RecipientInput, SMS};
use utopia_messaging::{MessageKind, MessagingError, Response, SendResult};

struct MissingProcess {
    base: AdapterBase,
}

impl Adapter for MissingProcess {
    fn get_name(&self) -> &'static str {
        "Missing"
    }
    fn get_type(&self) -> &'static str {
        "sms"
    }
    fn get_message_type(&self) -> MessageKind {
        MessageKind::SMS
    }
    fn get_max_messages_per_request(&self) -> usize {
        10
    }
    fn base(&self) -> &AdapterBase {
        &self.base
    }
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

#[test]
fn invalid_message_type() {
    let adapter = Mock::new("u", "s");
    let email = Email::new(
        vec!["a@example.com".into()],
        "s",
        "c",
        "n",
        "from@example.com",
        None,
        None,
        None,
        None,
        None,
        false,
    )
    .unwrap();
    let err = adapter.send(&email).unwrap_err();
    assert_eq!(err.to_string(), "Invalid message type.");
}

#[test]
fn too_many_recipients() {
    let adapter = Vonage::new("k", "s", None);
    let sms = SMS::new(vec!["+1".into(), "+2".into()], "hi", None, None, None);
    let err = adapter.send(&sms).unwrap_err();
    assert_eq!(
        err.to_string(),
        "Vonage can only send 1 messages per request."
    );
}

#[test]
fn missing_process_method() {
    let adapter = MissingProcess {
        base: AdapterBase::default(),
    };
    let err = adapter
        .send(&SMS::new(vec!["+1".into()], "hi", None, None, None))
        .unwrap_err();
    assert_eq!(
        err.to_string(),
        "Adapter does not implement process method."
    );
}

#[test]
fn add_result_treats_empty_and_zero_as_success() {
    let mut response = Response::new("sms");
    response.add_result("+1", "");
    response.add_result("+2", "0");
    response.add_result("+3", "nope");
    let data = response.to_array();
    assert_eq!(data.results[0].status, "success");
    assert_eq!(data.results[1].status, "success");
    assert_eq!(data.results[2].status, "failure");
    assert_eq!(data.type_name, "sms");
}

#[test]
fn jwt_hs256_does_not_escape_slashes() {
    let token = JWT::encode(
        &json!({"iss": "https://example.com/app"}),
        "secret",
        "HS256",
        None,
    )
    .unwrap();
    let payload = token.split('.').nth(1).unwrap();
    let decoded =
        base64::Engine::decode(&base64::engine::general_purpose::URL_SAFE_NO_PAD, payload).unwrap();
    let text = String::from_utf8(decoded).unwrap();
    assert!(text.contains("https://example.com/app"));
    assert!(!text.contains(r"https:\/\/"));
    assert_eq!(token.split('.').count(), 3);
}

#[test]
fn jwt_hs384_hs512_and_unsupported() {
    JWT::encode(&json!({"a": 1}), "secret", "HS384", None).unwrap();
    JWT::encode(&json!({"a": 1}), "secret", "HS512", None).unwrap();
    assert!(matches!(
        JWT::encode(&json!({}), "secret", "ES256K", None),
        Err(MessagingError::AlgorithmNotSupported)
    ));
}

#[test]
fn discord_webhook_validation() {
    assert!(Discord::new("http://discord.com/api/webhooks/1/token")
        .unwrap_err()
        .to_string()
        .contains("HTTPS"));
    assert!(Discord::new("https://example.com/api/webhooks/1/token")
        .unwrap_err()
        .to_string()
        .contains("discord.com"));
    assert!(Discord::new("https://discord.com/api/webhooks/0/token")
        .unwrap_err()
        .to_string()
        .contains("cannot be empty"));
    let adapter =
        Discord::new("https://discord.com/api/webhooks/123456789012345678/token").unwrap();
    assert_eq!(adapter.get_name(), "Discord");
    assert_eq!(adapter.get_type(), "chat");
}

#[test]
fn discord_send_posts_webhook() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(204, json!({})));
    let adapter =
        Discord::new("https://discord.com/api/webhooks/123456789012345678/token").unwrap();
    bind(&adapter, &client);
    let message = DiscordMessage::new(
        "hello", None, None, None, None, None, None, None, None, None, None, None,
    );
    match adapter.send(&message).unwrap() {
        SendResult::Response(data) => {
            assert_eq!(data.delivered_to, 1);
            assert_eq!(data.type_name, "chat");
        }
        SendResult::Grouped(_) => panic!("expected chat response"),
    }
    let captured = &client.captured_requests()[0];
    assert_eq!(
        captured.url,
        "https://discord.com/api/webhooks/123456789012345678/token"
    );
    assert_eq!(captured.body.as_ref().unwrap()["content"], json!("hello"));
}

#[test]
fn request_multi_validates_url_and_body_counts() {
    let adapter = Mock::new("u", "s");
    let err = adapter
        .request_multi("GET", &[], &[], &[], 1, 1)
        .unwrap_err();
    assert_eq!(
        err.to_string(),
        "No URLs provided. Must provide at least one URL."
    );
    let err = adapter
        .request_multi(
            "GET",
            &["http://a".into(), "http://b".into()],
            &[],
            &[json!({}), json!({}), json!({})],
            1,
            1,
        )
        .unwrap_err();
    assert_eq!(
        err.to_string(),
        "URL and body counts must be equal or one must equal 1."
    );
}

#[test]
fn request_multi_broadcasts_single_body() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(200, json!({"ok": 1})));
    client.push_stub(stub(200, json!({"ok": 2})));
    let adapter = Mock::new("u", "s");
    bind(&adapter, &client);
    let results = adapter
        .request_multi(
            "POST",
            &["http://one.test/".into(), "http://two.test/".into()],
            &["Content-Type: application/json".into()],
            &[json!({"n": 1})],
            1,
            1,
        )
        .unwrap();
    assert_eq!(results.len(), 2);
    assert_eq!(client.captured_requests().len(), 2);
}

#[test]
fn client_error_returns_status_zero() {
    let adapter = Mock::new("u", "s");
    let result = adapter.request("GET", "http://127.0.0.1:1/", &[], None, 1, 1);
    assert_eq!(result.status_code, 0);
    assert!(!result.error.is_empty());
}

#[test]
fn mailgun_and_sendgrid_urls() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(200, json!({})));
    let mailgun = Mailgun::new("key", "example.com", true);
    bind(&mailgun, &client);
    let email = Email::new(
        vec![RecipientInput::email_only("a@example.com")],
        "S",
        "B",
        "N",
        "from@example.com",
        None,
        None,
        None,
        None,
        None,
        false,
    )
    .unwrap();
    mailgun.send(&email).unwrap();
    assert_eq!(
        client.captured_requests()[0].url,
        "https://api.eu.mailgun.net/v3/example.com/messages"
    );

    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(202, json!({})));
    let sendgrid = Sendgrid::new("sg-key");
    bind(&sendgrid, &client);
    sendgrid.send(&email).unwrap();
    assert_eq!(
        client.captured_requests()[0].url,
        "https://api.sendgrid.com/v3/mail/send"
    );
}

#[test]
fn push_requires_title_body_or_data() {
    let err = Push::new(
        vec!["token".into()],
        None,
        None,
        None,
        None,
        None,
        None,
        None,
        None,
        None,
        None,
        None,
        None,
        None,
    )
    .unwrap_err();
    assert!(err
        .to_string()
        .contains("At least one of the following parameters must be set"));
}

#[test]
fn metadata_parameter_values() {
    use utopia_messaging::adapter::sms::msg91::MetadataParameter;
    assert_eq!(MetadataParameter::ClientId.as_str(), "clientId");
    assert_eq!(MetadataParameter::Crqid.as_str(), "CRQID");
    assert_eq!(MetadataParameter::Uuid.as_str(), "UUID");
    assert_eq!(MetadataParameter::cases().len(), 3);
}
