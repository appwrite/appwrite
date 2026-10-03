use std::collections::BTreeMap;

use appwrite_event::{
    generate_events, AuditMessage, AuditPublisher, CallbackDeletePublisher, DeleteMessage,
    DeletePublisher, Event, EventError, MemoryAuditPublisher, MemoryDeletePublisher,
    DELETE_TYPE_DOCUMENT, RESOURCE_TYPE_USERS,
};
use serde_json::json;

#[test]
fn event_builder_to_message_shape() {
    let message = Event::new()
        .set_project(json!({"$id": "proj1"}))
        .set_user(json!({"$id": "user1"}))
        .set_event("users.[userId].create")
        .set_param("userId", "user1")
        .set_payload(json!({"$id": "user1", "email": "a@b.com"}))
        .set_context("user", json!({"$id": "user1"}))
        .to_message()
        .unwrap();

    assert_eq!(message["project"]["$id"], "proj1");
    assert_eq!(message["user"]["$id"], "user1");
    assert_eq!(message["userId"], "user1");
    assert_eq!(message["payload"]["email"], "a@b.com");
    assert_eq!(message["context"]["user"]["$id"], "user1");
    let events = message["events"].as_array().unwrap();
    assert!(events.iter().any(|e| e == "users.user1.create"));
    assert!(events.iter().any(|e| e == "users.*.create"));
    assert!(events.iter().any(|e| e == "users.user1"));
}

#[test]
fn event_without_project_or_user_defaults_to_null() {
    let message = Event::new()
        .set_event("users.[userId].create")
        .set_param("userId", "u1")
        .to_message()
        .unwrap();

    assert!(message["project"].is_null());
    assert!(message["user"].is_null());
    assert!(message["userId"].is_null());
}

#[test]
fn generate_events_expands_placeholders_and_wildcards() {
    let mut params = BTreeMap::new();
    params.insert("userId".to_string(), "user1".to_string());

    let events = generate_events("users.[userId].create", &params).unwrap();
    assert!(events.contains(&"users.user1.create".to_string()));
    assert!(events.contains(&"users.*.create".to_string()));
    assert!(events.contains(&"users.user1".to_string()));
    assert!(!events.iter().any(|e| e.contains('[') || e.contains(']')));
}

#[test]
fn generate_events_expands_attribute_pattern() {
    let mut params = BTreeMap::new();
    params.insert("userId".to_string(), "user1".to_string());

    let events = generate_events("users.[userId].update.email", &params).unwrap();
    assert!(events.contains(&"users.user1.update".to_string()));
    assert!(events.contains(&"users.user1.update.email".to_string()));
    assert!(events.contains(&"users.*.update.email".to_string()));
}

#[test]
fn generate_events_expands_sub_resource_pattern() {
    let mut params = BTreeMap::new();
    params.insert("userId".to_string(), "user1".to_string());
    params.insert("sessionId".to_string(), "session1".to_string());

    let events = generate_events("users.[userId].sessions.[sessionId].create", &params).unwrap();
    assert!(events.contains(&"users.user1.sessions.session1.create".to_string()));
    assert!(events.contains(&"users.*.sessions.*.create".to_string()));
    // Single-param wildcards, other param stays concrete.
    assert!(events.contains(&"users.*.sessions.session1.create".to_string()));
    assert!(events.contains(&"users.user1.sessions.*.create".to_string()));
    assert!(events.contains(&"users.user1".to_string()));
}

#[test]
fn generate_events_errors_on_missing_param() {
    let params = BTreeMap::new();
    let err = generate_events("users.[userId].create", &params).unwrap_err();
    assert_eq!(err, EventError::MissingParam("userId".to_string()));
}

#[test]
fn generate_events_empty_pattern_is_empty() {
    let params = BTreeMap::new();
    assert!(generate_events("", &params).unwrap().is_empty());
}

#[test]
fn delete_message_to_json_matches_php_shape() {
    let message = DeleteMessage::new(DELETE_TYPE_DOCUMENT)
        .with_project(json!({"$id": "proj1"}))
        .with_document(json!({"$id": "user1"}))
        .with_resource_type(RESOURCE_TYPE_USERS);

    let json = message.to_json();
    assert_eq!(json["type"], "document");
    assert_eq!(json["project"]["$id"], "proj1");
    assert_eq!(json["document"]["$id"], "user1");
    assert_eq!(json["resourceType"], "users");
    assert!(json["resource"].is_null());
}

#[test]
fn audit_message_to_json_trims_project_and_matches_php_shape() {
    let message = AuditMessage::new("users.user1.create", json!({"email": "a@b.com"}))
        .with_project(
            json!({"$id": "proj1", "$sequence": 42, "database": "db1", "secret": "hidden"}),
        )
        .with_user(json!({"$id": "user1"}))
        .with_resource("user/user1")
        .with_mode("default")
        .with_ip("127.0.0.1")
        .with_user_agent("test-agent");

    let json = message.to_json();
    assert_eq!(json["event"], "users.user1.create");
    assert_eq!(json["resource"], "user/user1");
    assert_eq!(json["mode"], "default");
    assert_eq!(json["ip"], "127.0.0.1");
    assert_eq!(json["userAgent"], "test-agent");
    assert_eq!(json["user"]["$id"], "user1");
    assert_eq!(json["project"]["$id"], "proj1");
    assert_eq!(json["project"]["$sequence"], 42);
    assert_eq!(json["project"]["database"], "db1");
    // Only $id/$sequence/database survive trimming, matching PHP's trimPayload().
    assert!(json["project"].get("secret").is_none());
}

#[test]
fn audit_message_to_json_defaults_missing_project_and_user() {
    let message = AuditMessage::new("users.user1.create", json!({}));
    let json = message.to_json();
    assert_eq!(json["project"]["$id"], "");
    assert_eq!(json["project"]["$sequence"], 0);
    assert_eq!(json["user"], json!({}));
}

#[test]
fn memory_delete_publisher_enqueues_and_drains() {
    let publisher = MemoryDeletePublisher::new();
    assert_eq!(publisher.size(), 0);

    let ok = publisher
        .enqueue(DeleteMessage::new(DELETE_TYPE_DOCUMENT).with_resource_type(RESOURCE_TYPE_USERS));
    assert!(ok);
    assert_eq!(publisher.size(), 1);

    let drained = publisher.drain();
    assert_eq!(drained.len(), 1);
    assert_eq!(drained[0].type_, DELETE_TYPE_DOCUMENT);
    assert_eq!(publisher.size(), 0);
}

#[test]
fn memory_audit_publisher_enqueues_and_lists_messages() {
    let publisher = MemoryAuditPublisher::new();
    publisher.enqueue(AuditMessage::new("users.user1.create", json!({})));
    publisher.enqueue(AuditMessage::new("users.user1.update", json!({})));

    assert_eq!(publisher.size(), 2);
    let messages = publisher.messages();
    assert_eq!(messages[0].event, "users.user1.create");
    assert_eq!(messages[1].event, "users.user1.update");
}

#[test]
fn callback_delete_publisher_forwards_messages() {
    let seen: std::sync::Arc<std::sync::Mutex<Vec<String>>> =
        std::sync::Arc::new(std::sync::Mutex::new(Vec::new()));
    let seen_clone = seen.clone();
    let publisher = CallbackDeletePublisher::new(move |message: DeleteMessage| {
        seen_clone.lock().unwrap().push(message.type_);
        true
    });

    publisher.enqueue(DeleteMessage::new(DELETE_TYPE_DOCUMENT));
    assert_eq!(publisher.size(), 1);
    assert_eq!(seen.lock().unwrap().as_slice(), ["document".to_string()]);
}
