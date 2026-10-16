use std::time::{SystemTime, UNIX_EPOCH};

use utopia_logger::{Breadcrumb, Log, LoggerError};

fn now() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .expect("time")
        .as_secs_f64()
}

/// Port of `tests/unit/Log/BreadcrumbTest.php::testLogBreadcrumb`.
#[test]
fn test_log_breadcrumb() {
    let timestamp = now();
    let breadcrumb = Breadcrumb::new(Log::TYPE_DEBUG, "http", "POST /user", timestamp).unwrap();

    assert_eq!(breadcrumb.get_type(), Log::TYPE_DEBUG);
    assert_eq!(breadcrumb.get_category(), "http");
    assert_eq!(breadcrumb.get_message(), "POST /user");
    assert!((breadcrumb.get_timestamp() - timestamp).abs() < f64::EPSILON);

    let breadcrumb = Breadcrumb::new(Log::TYPE_INFO, "http", "POST /user", timestamp).unwrap();
    assert_eq!(breadcrumb.get_type(), Log::TYPE_INFO);
    let breadcrumb = Breadcrumb::new(Log::TYPE_VERBOSE, "http", "POST /user", timestamp).unwrap();
    assert_eq!(breadcrumb.get_type(), Log::TYPE_VERBOSE);
    let breadcrumb = Breadcrumb::new(Log::TYPE_ERROR, "http", "POST /user", timestamp).unwrap();
    assert_eq!(breadcrumb.get_type(), Log::TYPE_ERROR);
    let breadcrumb = Breadcrumb::new(Log::TYPE_WARNING, "http", "POST /user", timestamp).unwrap();
    assert_eq!(breadcrumb.get_type(), Log::TYPE_WARNING);
}

#[test]
fn test_breadcrumb_invalid_type() {
    let err = Breadcrumb::new("fatal", "http", "POST /user", now()).unwrap_err();
    assert_eq!(err, LoggerError::InvalidBreadcrumbType);
    assert_eq!(
        err.to_string(),
        "Type has to be one of Log::TYPE_DEBUG, Log::TYPE_ERROR, Log::TYPE_INFO, Log::TYPE_WARNING, Log::TYPE_VERBOSE."
    );
}
