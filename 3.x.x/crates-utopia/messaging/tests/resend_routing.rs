//! PHP `tests/Messaging/Adapter/Email/ResendRoutingTest.php`.

use std::collections::HashMap;
use std::sync::Arc;

use serde_json::json;
use utopia_messaging::adapter::email::Resend;
use utopia_messaging::http::{HttpClient, SequenceClient, StubResponse};
use utopia_messaging::messages::email::Attachment;
use utopia_messaging::messages::{Email, RecipientInput};
use utopia_messaging::{Adapter, SendResult};

fn bind(adapter: &Resend, client: &Arc<SequenceClient>) {
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

fn message(attachments: Option<Vec<Attachment>>) -> Email {
    Email::new(
        vec![
            RecipientInput::email_only("a@example.com"),
            RecipientInput::email_only("b@example.com"),
        ],
        "Subject",
        "Body",
        "Sender",
        "from@example.com",
        None,
        None,
        None,
        None,
        attachments,
        false,
    )
    .unwrap()
}

fn send(adapter: &Resend, email: &Email) -> utopia_messaging::ResponseData {
    match adapter.send(email).unwrap() {
        SendResult::Response(data) => data,
        SendResult::Grouped(_) => panic!("expected Resend response"),
    }
}

#[test]
fn without_attachments_uses_batch_endpoint() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(200, json!({})));
    let adapter = Resend::new("test-key");
    bind(&adapter, &client);
    let response = send(&adapter, &message(None));
    let captured = client.captured_requests();
    assert_eq!(captured.len(), 1);
    assert_eq!(captured[0].url, "https://api.resend.com/emails/batch");
    let body = captured[0].body.as_ref().unwrap().as_array().unwrap();
    assert_eq!(body.len(), 2);
    assert!(body[0].get("attachments").is_none());
    assert_eq!(response.delivered_to, 2);
}

#[test]
fn with_attachments_uses_single_endpoint_per_recipient() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(200, json!({"id": "one"})));
    client.push_stub(stub(200, json!({"id": "two"})));
    let adapter = Resend::new("test-key");
    bind(&adapter, &client);
    let note = Attachment::new("note.txt", "", "text/plain", Some(b"hello".to_vec()));
    let response = send(&adapter, &message(Some(vec![note])));
    let captured = client.captured_requests();
    assert_eq!(captured.len(), 2);
    let encoded = base64::Engine::encode(&base64::engine::general_purpose::STANDARD, b"hello");
    for request in &captured {
        assert_eq!(request.url, "https://api.resend.com/emails");
        let body = request.body.as_ref().unwrap();
        let attachments = body["attachments"].as_array().unwrap();
        assert_eq!(attachments.len(), 1);
        assert_eq!(attachments[0]["filename"], json!("note.txt"));
        assert_eq!(attachments[0]["content_type"], json!("text/plain"));
        assert_eq!(attachments[0]["content"], json!(encoded));
    }
    assert_eq!(response.delivered_to, 2);
}

#[test]
fn partial_failure_with_attachments_aggregates_results() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(200, json!({"id": "one"})));
    client.push_stub(stub(422, json!({"message": "Invalid recipient"})));
    let adapter = Resend::new("test-key");
    bind(&adapter, &client);
    let note = Attachment::new("note.txt", "", "text/plain", Some(b"hello".to_vec()));
    let response = send(&adapter, &message(Some(vec![note])));
    assert_eq!(response.delivered_to, 1);
    assert_eq!(response.results[0].status, "success");
    assert_eq!(response.results[1].status, "failure");
    assert_eq!(response.results[1].error, "Invalid recipient");
}

#[test]
fn attachment_exceeding_max_size_throws() {
    let adapter = Resend::new("test-key");
    let message = Email::new(
        vec![RecipientInput::email_only("a@example.com")],
        "Subject",
        "Body",
        "Sender",
        "from@example.com",
        None,
        None,
        None,
        None,
        Some(vec![Attachment::new(
            "large.bin",
            "",
            "application/octet-stream",
            Some(vec![b'x'; 40 * 1024 * 1024 + 1]),
        )]),
        false,
    )
    .unwrap();
    let err = adapter.send(&message).unwrap_err();
    assert!(err.to_string().contains("Total attachment size exceeds"));
}
