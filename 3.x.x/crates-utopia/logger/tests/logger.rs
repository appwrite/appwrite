use std::time::{SystemTime, UNIX_EPOCH};

use utopia_logger::{Adapter, Breadcrumb, Log, Logger, LoggerError, User};

#[derive(Debug)]
struct NoopAdapter {
    status: u16,
}

impl Adapter for NoopAdapter {
    fn get_name(&self) -> &'static str {
        "noop"
    }

    fn push(&self, _log: &Log) -> Result<u16, LoggerError> {
        Ok(self.status)
    }

    fn get_supported_types(&self) -> &'static [&'static str] {
        &[
            Log::TYPE_INFO,
            Log::TYPE_DEBUG,
            Log::TYPE_VERBOSE,
            Log::TYPE_WARNING,
            Log::TYPE_ERROR,
        ]
    }

    fn get_supported_environments(&self) -> &'static [&'static str] {
        &[Log::ENVIRONMENT_STAGING, Log::ENVIRONMENT_PRODUCTION]
    }

    fn get_supported_breadcrumb_types(&self) -> &'static [&'static str] {
        &[
            Log::TYPE_INFO,
            Log::TYPE_DEBUG,
            Log::TYPE_VERBOSE,
            Log::TYPE_WARNING,
            Log::TYPE_ERROR,
        ]
    }
}

struct FalseValidateAdapter;

impl Adapter for FalseValidateAdapter {
    fn get_name(&self) -> &'static str {
        "false"
    }

    fn push(&self, _log: &Log) -> Result<u16, LoggerError> {
        Ok(200)
    }

    fn get_supported_types(&self) -> &'static [&'static str] {
        &[Log::TYPE_ERROR]
    }

    fn get_supported_environments(&self) -> &'static [&'static str] {
        &[Log::ENVIRONMENT_PRODUCTION]
    }

    fn get_supported_breadcrumb_types(&self) -> &'static [&'static str] {
        &[Log::TYPE_DEBUG]
    }

    fn validate(&self, _log: &Log) -> Result<bool, LoggerError> {
        Ok(false)
    }
}

struct TypesAdapter;

impl Adapter for TypesAdapter {
    fn get_name(&self) -> &'static str {
        "types"
    }

    fn push(&self, _log: &Log) -> Result<u16, LoggerError> {
        Ok(200)
    }

    fn get_supported_types(&self) -> &'static [&'static str] {
        &[Log::TYPE_ERROR]
    }

    fn get_supported_environments(&self) -> &'static [&'static str] {
        &[Log::ENVIRONMENT_PRODUCTION]
    }

    fn get_supported_breadcrumb_types(&self) -> &'static [&'static str] {
        &[Log::TYPE_DEBUG]
    }
}

fn ready_log() -> Log {
    let mut log = Log::new();
    log.set_action("controller.database.deleteDocument");
    log.set_environment(Log::ENVIRONMENT_PRODUCTION).unwrap();
    log.set_namespace("api");
    log.set_server(Some("digitalocean-us-001"));
    log.set_type(Log::TYPE_ERROR).unwrap();
    log.set_version("0.11.5");
    log.set_message("Document efgh5678 not found");
    log.set_user(User::new(Some("efgh5678"), None, None));
    log
}

/// PHP `Logger::getProviders` / `hasProvider`.
#[test]
fn test_providers() {
    assert_eq!(
        Logger::get_providers(),
        &["raygun", "sentry", "appSignal", "logOwl"]
    );
    assert_eq!(Logger::PROVIDERS, Logger::get_providers());
    assert!(Logger::has_provider("raygun"));
    assert!(Logger::has_provider("sentry"));
    assert!(Logger::has_provider("appSignal"));
    assert!(Logger::has_provider("logOwl"));
    assert!(!Logger::has_provider("Sentry"));
    assert!(!Logger::has_provider("unknown"));
    assert_eq!(Logger::LIBRARY_VERSION, "0.1.0");
}

#[test]
fn test_add_log_not_ready() {
    let logger = Logger::new(NoopAdapter { status: 200 });
    let log = Log::new();
    let err = logger.add_log(&log).unwrap_err();
    assert_eq!(err, LoggerError::NotReady);
    assert_eq!(err.to_string(), "Log is not ready to be pushed.");
}

#[test]
fn test_add_log_not_ready_empty_fields() {
    let logger = Logger::new(NoopAdapter { status: 200 });
    for missing in ["action", "environment", "message", "type", "version"] {
        let mut log = ready_log();
        match missing {
            "action" => log.set_action(""),
            "environment" => log.set_environment(Log::ENVIRONMENT_PRODUCTION).unwrap(),
            "message" => log.set_message(""),
            "type" => {}
            "version" => log.set_version(""),
            _ => unreachable!(),
        }
        if missing == "environment" {
            // overwrite with empty via a raw field isn't possible; use "0" PHP-empty
            // environment cannot be set to empty through set_environment.
            continue;
        }
        if missing == "type" {
            let mut blank = Log::new();
            blank.set_action("a");
            blank.set_environment(Log::ENVIRONMENT_PRODUCTION).unwrap();
            blank.set_message("m");
            blank.set_version("v");
            assert_eq!(logger.add_log(&blank).unwrap_err(), LoggerError::NotReady);
            continue;
        }
        assert_eq!(
            logger.add_log(&log).unwrap_err(),
            LoggerError::NotReady,
            "missing {missing}"
        );
    }
}

#[test]
fn test_add_log_php_empty_zero_string() {
    let logger = Logger::new(NoopAdapter { status: 200 });
    let mut log = ready_log();
    log.set_message("0");
    assert_eq!(logger.add_log(&log).unwrap_err(), LoggerError::NotReady);
}

#[test]
fn test_add_log_success() {
    let logger = Logger::new(NoopAdapter { status: 200 });
    assert_eq!(logger.add_log(&ready_log()).unwrap(), 200);
}

#[test]
fn test_set_sample_stores_percent() {
    let mut logger = Logger::new(NoopAdapter { status: 200 });
    assert!(logger.get_sample().is_none());
    logger.set_sample(0.1);
    assert!((logger.get_sample().unwrap() - 10.0).abs() < f64::EPSILON);
    logger.set_sample(1.0);
    assert!((logger.get_sample().unwrap() - 100.0).abs() < f64::EPSILON);
}

/// Port of `AdapterBase::testSampler` (sample 0.1 → >85% skipped).
#[test]
fn test_sampler() {
    let mut logger = Logger::new(NoopAdapter { status: 200 });
    logger.set_sample(0.1);
    let log = ready_log();

    let mut zero_count = 0;
    let mut results = Vec::new();
    for _ in 0..=100 {
        let result = logger.add_log(&log).unwrap();
        results.push(result);
        if result == 0 {
            zero_count += 1;
        }
    }
    let zero_percentage = (f64::from(zero_count) / results.len() as f64) * 100.0;
    assert!(
        zero_percentage > 85.0,
        "expected >85% sampled out, got {zero_percentage}"
    );
}

#[test]
fn test_sample_zero_always_skips() {
    let mut logger = Logger::new(NoopAdapter { status: 200 });
    logger.set_sample(0.0);
    let log = ready_log();
    for _ in 0..20 {
        assert_eq!(logger.add_log(&log).unwrap(), 0);
    }
}

#[test]
fn test_validate_false_returns_500() {
    let logger = Logger::new(FalseValidateAdapter);
    assert_eq!(logger.add_log(&ready_log()).unwrap(), 500);
}

#[test]
fn test_validate_unsupported_type() {
    let logger = Logger::new(TypesAdapter);
    let mut log = ready_log();
    log.set_type(Log::TYPE_INFO).unwrap();
    let err = logger.add_log(&log).unwrap_err();
    assert_eq!(
        err.to_string(),
        "Supported log types for this adapter are: error"
    );
}

#[test]
fn test_validate_unsupported_environment() {
    let logger = Logger::new(TypesAdapter);
    let mut log = ready_log();
    log.set_environment(Log::ENVIRONMENT_STAGING).unwrap();
    let err = logger.add_log(&log).unwrap_err();
    assert_eq!(
        err.to_string(),
        "Supported environments for this adapter are: production"
    );
}

#[test]
fn test_validate_unsupported_breadcrumb_type() {
    let logger = Logger::new(TypesAdapter);
    let mut log = ready_log();
    let ts = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .expect("time")
        .as_secs_f64();
    log.add_breadcrumb(Breadcrumb::new(Log::TYPE_INFO, "http", "x", ts).unwrap());
    let err = logger.add_log(&log).unwrap_err();
    assert_eq!(
        err.to_string(),
        "Supported breadcrumb types for this adapter are: debug"
    );
}
