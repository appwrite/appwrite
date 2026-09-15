//! PHP `tests/Messaging/Adapter/Email/SESRoutingTest.php`.

use std::collections::HashMap;
use std::sync::Arc;

use serde_json::{json, Value};
use utopia_messaging::adapter::email::SES;
use utopia_messaging::http::{HttpClient, SequenceClient, StubResponse};
use utopia_messaging::messages::email::Attachment;
use utopia_messaging::messages::{Email, RecipientInput};
use utopia_messaging::{Adapter, SendResult};

fn bind(ses: &SES, client: &Arc<SequenceClient>) {
    let inner = Arc::clone(client);
    ses.set_client_factory(Arc::new(move |_, _| {
        let cloned = Arc::clone(&inner);
        let boxed: Arc<dyn HttpClient> = cloned;
        boxed
    }));
}

fn stub(status: i32, response: Value) -> StubResponse {
    StubResponse {
        status_code: status,
        response,
        headers: HashMap::new(),
    }
}

fn stub_headers(status: i32, response: Value, headers: HashMap<String, String>) -> StubResponse {
    StubResponse {
        status_code: status,
        response,
        headers,
    }
}

fn email(
    to: Vec<RecipientInput>,
    subject: &str,
    content: &str,
    html: bool,
    attachments: Option<Vec<Attachment>>,
    cc: Option<Vec<RecipientInput>>,
    bcc: Option<Vec<RecipientInput>>,
    from_name: &str,
) -> Email {
    Email::new(
        to,
        subject,
        content,
        from_name,
        "from@example.com",
        None,
        None,
        cc,
        bcc,
        attachments,
        html,
    )
    .unwrap()
}

fn send(ses: &SES, message: &Email) -> utopia_messaging::ResponseData {
    match ses.send(message).unwrap() {
        SendResult::Response(data) => data,
        SendResult::Grouped(_) => panic!("expected SES response"),
    }
}

fn body_at(client: &SequenceClient, index: usize) -> Value {
    client.captured_requests()[index].body.clone().unwrap()
}

#[test]
fn max_messages_per_request_is_fifty() {
    assert_eq!(
        SES::new("key", "secret", "us-east-1", None).get_max_messages_per_request(),
        50
    );
}

#[test]
fn without_attachments_uses_bulk_endpoint() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [
            {"Status": "SUCCESS", "MessageId": "a"},
            {"Status": "SUCCESS", "MessageId": "b"},
        ]}),
    ));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    let response = send(
        &ses,
        &email(
            vec![
                RecipientInput::email_only("a@example.com"),
                RecipientInput::email_only("b@example.com"),
            ],
            "Subject",
            "Body",
            false,
            None,
            None,
            None,
            "Sender",
        ),
    );
    let captured = client.captured_requests();
    assert_eq!(captured.len(), 1);
    assert_eq!(captured[0].method, "POST");
    assert!(captured[0].url.ends_with("/v2/email/outbound-bulk-emails"));
    assert!(captured[0].url.contains("email.us-east-1.amazonaws.com"));
    let body = captured[0].body.as_ref().unwrap();
    let entries = body["BulkEmailEntries"].as_array().unwrap();
    assert_eq!(entries.len(), 2);
    assert_eq!(
        entries[0]["Destination"]["ToAddresses"],
        json!(["a@example.com"])
    );
    assert_eq!(
        entries[1]["Destination"]["ToAddresses"],
        json!(["b@example.com"])
    );
    assert!(body["DefaultContent"]["Template"]["TemplateName"].is_string());
    assert_eq!(body["FromEmailAddress"], json!("Sender <from@example.com>"));
    assert_eq!(response.delivered_to, 2);
    assert_eq!(response.results[0].status, "success");
    assert_eq!(response.results[1].status, "success");
}

#[test]
fn template_name_is_deterministic_for_same_content() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "SUCCESS"}]}),
    ));
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "SUCCESS"}]}),
    ));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    let build = || {
        email(
            vec![RecipientInput::email_only("a@example.com")],
            "Same Subject",
            "Same Body",
            false,
            None,
            None,
            None,
            "Sender",
        )
    };
    send(&ses, &build());
    send(&ses, &build());
    let first = body_at(&client, 0)["DefaultContent"]["Template"]["TemplateName"]
        .as_str()
        .unwrap()
        .to_string();
    let second = body_at(&client, 1)["DefaultContent"]["Template"]["TemplateName"]
        .as_str()
        .unwrap()
        .to_string();
    assert_eq!(first, second);
    assert!(first.starts_with("utopia-"));
}

#[test]
fn template_name_differs_for_different_content() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "SUCCESS"}]}),
    ));
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "SUCCESS"}]}),
    ));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    send(
        &ses,
        &email(
            vec![RecipientInput::email_only("a@example.com")],
            "Subject A",
            "Body",
            false,
            None,
            None,
            None,
            "Sender",
        ),
    );
    send(
        &ses,
        &email(
            vec![RecipientInput::email_only("a@example.com")],
            "Subject B",
            "Body",
            false,
            None,
            None,
            None,
            "Sender",
        ),
    );
    let first = body_at(&client, 0)["DefaultContent"]["Template"]["TemplateName"].clone();
    let second = body_at(&client, 1)["DefaultContent"]["Template"]["TemplateName"].clone();
    assert_ne!(first, second);
}

#[test]
fn template_not_found_triggers_create_and_retry() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "TEMPLATE_NOT_FOUND"}]}),
    ));
    client.push_stub(stub(200, json!({})));
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "SUCCESS", "MessageId": "x"}]}),
    ));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    let response = send(
        &ses,
        &email(
            vec![RecipientInput::email_only("a@example.com")],
            "Subject",
            "<h1>Body</h1>",
            true,
            None,
            None,
            None,
            "Sender",
        ),
    );
    let captured = client.captured_requests();
    assert_eq!(captured.len(), 3);
    assert!(captured[0].url.ends_with("/v2/email/outbound-bulk-emails"));
    assert!(captured[1].url.ends_with("/v2/email/templates"));
    assert!(captured[2].url.ends_with("/v2/email/outbound-bulk-emails"));
    let template = captured[1].body.as_ref().unwrap();
    assert_eq!(template["TemplateContent"]["Subject"], json!("Subject"));
    assert_eq!(template["TemplateContent"]["Html"], json!("<h1>Body</h1>"));
    assert!(template["TemplateContent"].get("Text").is_none());
    assert_eq!(response.delivered_to, 1);
    assert_eq!(response.results[0].status, "success");
}

#[test]
fn top_level_missing_template_triggers_create_and_retry() {
    let client = Arc::new(SequenceClient::new());
    let mut headers = HashMap::new();
    headers.insert("x-amzn-errortype".into(), "BadRequestException".into());
    client.push_stub(stub_headers(
        400,
        json!({"message": "Template utopia-abc123 does not exist."}),
        headers,
    ));
    client.push_stub(stub(200, json!({})));
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "SUCCESS", "MessageId": "x"}]}),
    ));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    let response = send(
        &ses,
        &email(
            vec![RecipientInput::email_only("a@example.com")],
            "Subject",
            "Body",
            false,
            None,
            None,
            None,
            "Sender",
        ),
    );
    let captured = client.captured_requests();
    assert_eq!(captured.len(), 3);
    assert!(captured[0].url.ends_with("/v2/email/outbound-bulk-emails"));
    assert!(captured[1].url.ends_with("/v2/email/templates"));
    assert!(captured[2].url.ends_with("/v2/email/outbound-bulk-emails"));
    assert_eq!(response.delivered_to, 1);
    assert_eq!(response.results[0].status, "success");
}

#[test]
fn create_template_tolerates_already_exists() {
    let client = Arc::new(SequenceClient::new());
    let mut missing = HashMap::new();
    missing.insert("x-amzn-errortype".into(), "BadRequestException".into());
    client.push_stub(stub_headers(
        400,
        json!({"message": "Template utopia-abc123 does not exist."}),
        missing,
    ));
    let mut exists = HashMap::new();
    exists.insert("x-amzn-errortype".into(), "AlreadyExistsException".into());
    client.push_stub(stub_headers(
        400,
        json!({"message": "Template utopia-abc123 already exists."}),
        exists,
    ));
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "SUCCESS"}]}),
    ));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    let response = send(
        &ses,
        &email(
            vec![RecipientInput::email_only("a@example.com")],
            "Subject",
            "Body",
            false,
            None,
            None,
            None,
            "Sender",
        ),
    );
    assert_eq!(client.captured_requests().len(), 3);
    assert_eq!(response.delivered_to, 1);
    assert_eq!(response.results[0].status, "success");
}

#[test]
fn text_template_uses_text_content() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "TEMPLATE_NOT_FOUND"}]}),
    ));
    client.push_stub(stub(200, json!({})));
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "SUCCESS"}]}),
    ));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    send(
        &ses,
        &email(
            vec![RecipientInput::email_only("a@example.com")],
            "Plain Subject",
            "Plain body",
            false,
            None,
            None,
            None,
            "Sender",
        ),
    );
    let template = body_at(&client, 1);
    assert_eq!(template["TemplateContent"]["Text"], json!("Plain body"));
    assert!(template["TemplateContent"].get("Html").is_none());
}

#[test]
fn partial_failure_maps_per_recipient_results() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [
            {"Status": "SUCCESS", "MessageId": "ok"},
            {"Status": "MESSAGE_REJECTED", "Error": "Email address is not verified"},
        ]}),
    ));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    let response = send(
        &ses,
        &email(
            vec![
                RecipientInput::email_only("good@example.com"),
                RecipientInput::email_only("bad@example.com"),
            ],
            "Subject",
            "Body",
            false,
            None,
            None,
            None,
            "Sender",
        ),
    );
    assert_eq!(response.delivered_to, 1);
    assert_eq!(response.results[0].status, "success");
    assert_eq!(response.results[0].recipient, "good@example.com");
    assert_eq!(response.results[1].status, "failure");
    assert_eq!(response.results[1].recipient, "bad@example.com");
    assert_eq!(response.results[1].error, "Email address is not verified");
}

#[test]
fn whole_request_failure_marks_all_recipients_failed() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(
        400,
        json!({"message": "The sending domain is not verified"}),
    ));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    let response = send(
        &ses,
        &email(
            vec![
                RecipientInput::email_only("a@example.com"),
                RecipientInput::email_only("b@example.com"),
            ],
            "Subject",
            "Body",
            false,
            None,
            None,
            None,
            "Sender",
        ),
    );
    assert_eq!(response.delivered_to, 0);
    for result in &response.results {
        assert_eq!(result.status, "failure");
        assert_eq!(result.error, "The sending domain is not verified");
    }
}

#[test]
fn fifty_recipients_produce_single_bulk_request() {
    let client = Arc::new(SequenceClient::new());
    let entry_results: Vec<Value> = (0..50).map(|_| json!({"Status": "SUCCESS"})).collect();
    client.push_stub(stub(200, json!({"BulkEmailEntryResults": entry_results})));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    let to: Vec<RecipientInput> = (0..50)
        .map(|i| RecipientInput::email_only(format!("user{i}@example.com")))
        .collect();
    let response = send(
        &ses,
        &email(to, "Subject", "Body", false, None, None, None, "Sender"),
    );
    assert_eq!(client.captured_requests().len(), 1);
    assert_eq!(
        body_at(&client, 0)["BulkEmailEntries"]
            .as_array()
            .unwrap()
            .len(),
        50
    );
    assert_eq!(response.delivered_to, 50);
}

#[test]
fn exceeding_fifty_recipients_throws() {
    let ses = SES::new("key", "secret", "us-east-1", None);
    let to: Vec<RecipientInput> = (0..51)
        .map(|i| RecipientInput::email_only(format!("user{i}@example.com")))
        .collect();
    let message = email(to, "Subject", "Body", false, None, None, None, "Sender");
    let err = ses.send(&message).unwrap_err();
    assert!(err
        .to_string()
        .contains("can only send 50 messages per request"));
}

#[test]
fn with_attachments_uses_send_email_raw_per_recipient() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(200, json!({"MessageId": "one"})));
    client.push_stub(stub(200, json!({"MessageId": "two"})));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    let attachment = Attachment::new(
        "note.txt",
        "",
        "text/plain",
        Some(b"hello attachment".to_vec()),
    );
    let response = send(
        &ses,
        &email(
            vec![
                RecipientInput::email_only("a@example.com"),
                RecipientInput::email_only("b@example.com"),
            ],
            "Subject",
            "Body",
            false,
            Some(vec![attachment]),
            None,
            None,
            "Sender",
        ),
    );
    let captured = client.captured_requests();
    assert_eq!(captured.len(), 2);
    let encoded = base64::Engine::encode(
        &base64::engine::general_purpose::STANDARD,
        b"hello attachment",
    );
    for request in &captured {
        assert!(request.url.ends_with("/v2/email/outbound-emails"));
        let mime = request.body.as_ref().unwrap()["Content"]["Raw"]["Data"]
            .as_str()
            .unwrap();
        let decoded =
            base64::Engine::decode(&base64::engine::general_purpose::STANDARD, mime).unwrap();
        let mime = String::from_utf8(decoded).unwrap();
        assert!(mime.contains("Subject: Subject"));
        assert!(mime.contains("note.txt"));
        assert!(mime.contains(&encoded));
    }
    assert_eq!(response.delivered_to, 2);
}

#[test]
fn attachment_partial_failure_aggregates_results() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(200, json!({"MessageId": "one"})));
    client.push_stub(stub(400, json!({"message": "Invalid recipient"})));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    let response = send(
        &ses,
        &email(
            vec![
                RecipientInput::email_only("a@example.com"),
                RecipientInput::email_only("b@example.com"),
            ],
            "Subject",
            "Body",
            false,
            Some(vec![Attachment::new(
                "note.txt",
                "",
                "text/plain",
                Some(b"hello".to_vec()),
            )]),
            None,
            None,
            "Sender",
        ),
    );
    assert_eq!(response.delivered_to, 1);
    assert_eq!(response.results[0].status, "success");
    assert_eq!(response.results[1].status, "failure");
    assert_eq!(response.results[1].error, "Invalid recipient");
}

#[test]
fn attachment_exceeding_max_size_throws() {
    let ses = SES::new("key", "secret", "us-east-1", None);
    let message = email(
        vec![RecipientInput::email_only("a@example.com")],
        "Subject",
        "Body",
        false,
        Some(vec![Attachment::new(
            "large.bin",
            "",
            "application/octet-stream",
            Some(vec![b'x'; 25 * 1024 * 1024 + 1]),
        )]),
        None,
        None,
        "Sender",
    );
    let err = ses.send(&message).unwrap_err();
    assert!(err.to_string().contains("Total attachment size exceeds"));
}

#[test]
fn session_token_adds_security_token_header() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "SUCCESS"}]}),
    ));
    let ses = SES::new(
        "key",
        "secret",
        "us-east-1",
        Some("session-token-value".into()),
    );
    bind(&ses, &client);
    send(
        &ses,
        &email(
            vec![RecipientInput::email_only("a@example.com")],
            "Subject",
            "Body",
            false,
            None,
            None,
            None,
            "Sender",
        ),
    );
    let joined = client.captured_requests()[0].headers.join("\n");
    assert!(joined.contains("X-Amz-Security-Token: session-token-value"));
    assert!(joined.to_ascii_lowercase().contains("x-amz-security-token"));
}

#[test]
fn bulk_entries_include_cc_and_bcc() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "SUCCESS"}]}),
    ));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    send(
        &ses,
        &email(
            vec![RecipientInput::email_only("a@example.com")],
            "Subject",
            "Body",
            false,
            None,
            Some(vec![RecipientInput::named("cc@example.com", "CC Person")]),
            Some(vec![RecipientInput::email_only("bcc@example.com")]),
            "Sender",
        ),
    );
    let destination = &body_at(&client, 0)["BulkEmailEntries"][0]["Destination"];
    assert_eq!(destination["ToAddresses"], json!(["a@example.com"]));
    assert_eq!(
        destination["CcAddresses"],
        json!(["CC Person <cc@example.com>"])
    );
    assert_eq!(destination["BccAddresses"], json!(["bcc@example.com"]));
}

#[test]
fn bulk_entries_omit_cc_and_bcc_when_absent() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "SUCCESS"}]}),
    ));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    send(
        &ses,
        &email(
            vec![RecipientInput::email_only("a@example.com")],
            "Subject",
            "Body",
            false,
            None,
            None,
            None,
            "Sender",
        ),
    );
    let destination = &body_at(&client, 0)["BulkEmailEntries"][0]["Destination"];
    assert!(destination.get("CcAddresses").is_none());
    assert!(destination.get("BccAddresses").is_none());
}

#[test]
fn display_name_with_special_characters_is_quoted() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "SUCCESS"}]}),
    ));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    send(
        &ses,
        &email(
            vec![RecipientInput::email_only("a@example.com")],
            "Subject",
            "Body",
            false,
            None,
            None,
            None,
            "Acme, Inc.",
        ),
    );
    assert_eq!(
        body_at(&client, 0)["FromEmailAddress"],
        json!("\"Acme, Inc.\" <from@example.com>")
    );
}

#[test]
fn template_name_respects_ses_length_limit() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(
        200,
        json!({"BulkEmailEntryResults": [{"Status": "SUCCESS"}]}),
    ));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    send(
        &ses,
        &email(
            vec![RecipientInput::email_only("a@example.com")],
            &"long subject ".repeat(64),
            &"long body ".repeat(64),
            false,
            None,
            None,
            None,
            "Sender",
        ),
    );
    let body = body_at(&client, 0);
    let name = body["DefaultContent"]["Template"]["TemplateName"]
        .as_str()
        .unwrap();
    assert!(name.len() <= 64);
    assert!(name.starts_with("utopia-"));
}

#[test]
fn success_without_entry_results_marks_all_recipients_failed() {
    let client = Arc::new(SequenceClient::new());
    client.push_stub(stub(200, json!({})));
    let ses = SES::new("key", "secret", "us-east-1", None);
    bind(&ses, &client);
    let response = send(
        &ses,
        &email(
            vec![
                RecipientInput::email_only("a@example.com"),
                RecipientInput::email_only("b@example.com"),
            ],
            "Subject",
            "Body",
            false,
            None,
            None,
            None,
            "Sender",
        ),
    );
    assert_eq!(response.delivered_to, 0);
    for result in &response.results {
        assert_eq!(result.status, "failure");
        assert!(!result.error.is_empty());
    }
}

#[test]
fn mime_exceeding_ses_limit_throws() {
    let ses = SES::new("key", "secret", "us-east-1", None);
    let message = email(
        vec![RecipientInput::email_only("a@example.com")],
        "Subject",
        "Body",
        false,
        Some(vec![Attachment::new(
            "big.bin",
            "",
            "application/octet-stream",
            Some(vec![b'x'; 8 * 1024 * 1024]),
        )]),
        None,
        None,
        "Sender",
    );
    let err = ses.send(&message).unwrap_err();
    assert!(err
        .to_string()
        .contains("MIME message size exceeds SES limit"));
}
