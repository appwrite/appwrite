use std::collections::HashMap;
use std::time::{SystemTime, UNIX_EPOCH};

use serde_json::json;
use utopia_logger::{Breadcrumb, Log, LoggerError, User};

fn now() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .expect("time")
        .as_secs_f64()
}

/// Port of `tests/unit/LogTest.php::testLog`.
#[test]
fn test_log() {
    let mut log = Log::new();

    let timestamp = now();
    log.set_timestamp(timestamp);
    assert!((log.get_timestamp() - timestamp).abs() < f64::EPSILON);

    log.set_type(Log::TYPE_ERROR).unwrap();
    assert_eq!(log.get_type(), Log::TYPE_ERROR);
    log.set_type(Log::TYPE_DEBUG).unwrap();
    assert_eq!(log.get_type(), Log::TYPE_DEBUG);
    log.set_type(Log::TYPE_WARNING).unwrap();
    assert_eq!(log.get_type(), Log::TYPE_WARNING);
    log.set_type(Log::TYPE_VERBOSE).unwrap();
    assert_eq!(log.get_type(), Log::TYPE_VERBOSE);
    log.set_type(Log::TYPE_INFO).unwrap();
    assert_eq!(log.get_type(), Log::TYPE_INFO);

    log.set_message("Cannot read 'user' of undefined");
    assert_eq!(log.get_message(), "Cannot read 'user' of undefined");

    log.set_version("0.11.0");
    assert_eq!(log.get_version(), "0.11.0");

    log.set_environment(Log::ENVIRONMENT_PRODUCTION).unwrap();
    assert_eq!(log.get_environment(), Log::ENVIRONMENT_PRODUCTION);
    log.set_environment(Log::ENVIRONMENT_STAGING).unwrap();
    assert_eq!(log.get_environment(), Log::ENVIRONMENT_STAGING);

    log.set_namespace("getAuthUser");
    assert_eq!(log.get_namespace(), "getAuthUser");

    log.set_action("authGuard");
    assert_eq!(log.get_action(), "authGuard");

    log.set_server(Some("aws-001"));
    assert_eq!(log.get_server(), Some("aws-001"));

    log.add_extra("isLoggedIn", false);
    assert_eq!(log.get_extra()["isLoggedIn"], json!(false));

    log.add_tag("authMethod", "session");
    log.add_tag("authProvider", "basic");
    let expected_tags: HashMap<String, String> = [
        ("authMethod".to_string(), "session".to_string()),
        ("authProvider".to_string(), "basic".to_string()),
    ]
    .into_iter()
    .collect();
    assert_eq!(log.get_tags(), expected_tags);

    let user_id = "myid123";
    let user = User::new(Some(user_id), None, None);
    log.set_user(user.clone());
    assert_eq!(log.get_user(), Some(&user));
    assert_eq!(log.get_user().and_then(User::get_id), Some(user_id));

    let breadcrumb = Breadcrumb::new(
        Log::TYPE_DEBUG,
        "http",
        "DELETE /api/v1/database/abcd1234/efgh5678",
        timestamp,
    )
    .unwrap();
    log.add_breadcrumb(breadcrumb.clone());
    assert_eq!(log.get_breadcrumbs(), std::slice::from_ref(&breadcrumb));
    assert_eq!(log.get_breadcrumbs()[0].get_type(), Log::TYPE_DEBUG);
    assert_eq!(log.get_breadcrumbs()[0].get_category(), "http");
    assert_eq!(
        log.get_breadcrumbs()[0].get_message(),
        "DELETE /api/v1/database/abcd1234/efgh5678"
    );
    assert!((log.get_breadcrumbs()[0].get_timestamp() - timestamp).abs() < f64::EPSILON);
}

/// Port of `tests/unit/LogTest.php::testLogMasked`.
#[test]
fn test_log_masked() {
    let mut log = Log::new();

    log.add_tag("password", "123456");
    log.add_extra("name", "John Doe");

    assert_eq!(
        log.get_tags(),
        HashMap::from([("password".to_string(), "123456".to_string())])
    );
    assert_eq!(log.get_extra()["name"], json!("John Doe"));

    log.set_masked(["password", "name"]);

    assert_eq!(
        log.get_tags(),
        HashMap::from([("password".to_string(), "******".to_string())])
    );
    assert_eq!(log.get_extra()["name"], json!("********"));

    log.add_extra("user", json!({"password": "abc"}));
    assert_eq!(log.get_extra()["user"], json!({"password": "***"}));

    log.set_masked(Vec::<String>::new());

    assert_eq!(
        log.get_tags(),
        HashMap::from([("password".to_string(), "123456".to_string())])
    );
    assert_eq!(log.get_extra()["name"], json!("John Doe"));
    assert_eq!(log.get_extra()["user"], json!({"password": "abc"}));
}

#[test]
fn test_log_invalid_type() {
    let mut log = Log::new();
    let err = log.set_type("fatal").unwrap_err();
    assert_eq!(err, LoggerError::UnsupportedType);
    assert_eq!(
        err.to_string(),
        "Unsupported log type. Must be one of Log::TYPE_DEBUG, Log::TYPE_ERROR, Log::TYPE_WARNING, Log::TYPE_INFO, Log::VERBOSE."
    );
}

#[test]
fn test_log_invalid_environment() {
    let mut log = Log::new();
    let err = log.set_environment("dev").unwrap_err();
    assert_eq!(err, LoggerError::UnsupportedEnvironment);
    assert_eq!(
        err.to_string(),
        "Unsupported environment of log. Must be one of ENVIRONMENT_PRODUCTION, ENVIRONMENT_STAGING."
    );
}

#[test]
fn test_mask_skips_non_string_values() {
    let mut log = Log::new();
    log.add_extra("count", 42);
    log.set_masked(["count"]);
    assert_eq!(log.get_extra()["count"], json!(42));
}

#[test]
fn test_default_namespace() {
    let log = Log::new();
    assert_eq!(log.get_namespace(), "UNKNOWN");
    assert!(log.get_server().is_none());
    assert!(log.get_user().is_none());
    assert!(log.get_breadcrumbs().is_empty());
}

#[test]
fn test_constructor_sets_timestamp() {
    let before = now();
    let log = Log::new();
    let after = now();
    assert!(log.get_timestamp() >= before - 0.01);
    assert!(log.get_timestamp() <= after + 0.01);
}
