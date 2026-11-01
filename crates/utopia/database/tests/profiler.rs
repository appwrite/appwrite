//! Ported from utopia-php/database 8.1.3 `tests/unit/Profiler/ProfilerTest.php`
//! and `tests/unit/Profiler/ProfilerAdvancedTest.php`.
//!
//! `Profiler/ProfiledStatementTest.php` is not ported: it profiles PDO
//! statements of an adapter.

mod common;

use std::sync::{Arc, Mutex};

use common::{assert_error, list, v};
use utopia_database::profiler::Log;
use utopia_database::value::Array;
use utopia_database::{Kind, Profiler};

fn queries(p: &Profiler) -> Vec<String> {
    p.logs().into_iter().map(|l| l.query).collect()
}

fn log(p: &Profiler, query: &str, ms: f64) {
    p.log(query, Array::new(), ms, "", "");
}

// ProfilerTest

#[test]
fn test_disabled_by_default() {
    assert!(!Profiler::new().is_enabled());
}

#[test]
fn test_enable_disable() {
    let p = Profiler::new();
    p.enable();
    assert!(p.is_enabled());
    p.disable();
    assert!(!p.is_enabled());
}

#[test]
fn test_log_when_disabled() {
    let p = Profiler::new();
    log(&p, "SELECT 1", 1.0);
    assert!(p.logs().is_empty());
}

#[test]
fn test_log_when_enabled() {
    let p = Profiler::new();
    p.enable();
    p.log("SELECT * FROM users", Array::new(), 5.5, "users", "find");
    p.log("SELECT * FROM posts", Array::new(), 3.2, "posts", "find");
    let logs = p.logs();
    assert_eq!(logs.len(), 2);
    assert_eq!(logs[0].query, "SELECT * FROM users");
    assert_eq!(logs[0].duration_ms, 5.5);
    assert_eq!(logs[0].collection, "users");
}

#[test]
fn test_query_count() {
    let p = Profiler::new();
    p.enable();
    log(&p, "Q1", 1.0);
    log(&p, "Q2", 2.0);
    log(&p, "Q3", 3.0);
    assert_eq!(p.query_count(), 3);
}

#[test]
fn test_total_time() {
    let p = Profiler::new();
    p.enable();
    log(&p, "Q1", 10.0);
    log(&p, "Q2", 20.0);
    assert_eq!(p.total_time(), 30.0);
}

#[test]
fn test_slow_query_detection() {
    let p = Profiler::new();
    p.enable();
    p.set_slow_threshold(50.0);
    log(&p, "fast", 10.0);
    log(&p, "slow", 100.0);
    log(&p, "medium", 49.0);
    let slow = p.slow_queries();
    assert_eq!(slow.len(), 1);
    assert_eq!(slow[0].1.query, "slow");
    assert_eq!(slow[0].0, 1, "getSlowQueries() keeps the keys of getLogs()");
}

#[test]
fn test_slow_query_callback() {
    let p = Profiler::new();
    p.enable();
    p.set_slow_threshold(50.0);
    let called = Arc::new(Mutex::new(false));
    let flag = called.clone();
    p.on_slow_query(move |_| *flag.lock().unwrap() = true);
    log(&p, "fast", 10.0);
    assert!(!*called.lock().unwrap());
    log(&p, "slow", 100.0);
    assert!(*called.lock().unwrap());
}

#[test]
fn test_n_plus_one_detection() {
    let p = Profiler::new();
    p.enable();
    for i in 0..10 {
        p.log("SELECT * FROM users WHERE id = ?", common::arr! {0 => i}, 1.0, "", "");
    }
    assert_eq!(p.detect_n_plus_one(5), [("SELECT * FROM users WHERE id = ?...".to_owned(), 10)]);
}

#[test]
fn test_reset() {
    let p = Profiler::new();
    p.enable();
    log(&p, "Q1", 1.0);
    p.reset();
    assert!(p.logs().is_empty());
    assert_eq!(p.query_count(), 0);
}

#[test]
fn test_logs_keep_only_the_newest_entries_within_the_capacity() {
    let p = Profiler::new();
    p.enable();
    p.set_capacity(3).unwrap();
    for q in ["Q1", "Q2", "Q3", "Q4", "Q5"] {
        log(&p, q, 1.0);
    }
    assert_eq!(queries(&p), ["Q3", "Q4", "Q5"]);
    assert_eq!(p.capacity(), 3);
}

#[test]
fn test_default_capacity_bounds_the_logs() {
    let p = Profiler::new();
    p.enable();
    for i in 0..=Profiler::DEFAULT_CAPACITY {
        log(&p, &format!("Q{i}"), 1.0);
    }
    let logs = p.logs();
    assert_eq!(logs.len(), Profiler::DEFAULT_CAPACITY);
    assert_eq!(logs[0].query, "Q1");
    assert_eq!(logs[Profiler::DEFAULT_CAPACITY - 1].query, format!("Q{}", Profiler::DEFAULT_CAPACITY));
}

#[test]
fn test_count_and_total_time_cover_entries_past_the_capacity() {
    let p = Profiler::new();
    p.enable();
    p.set_capacity(2).unwrap();
    log(&p, "Q1", 1.0);
    log(&p, "Q2", 2.0);
    log(&p, "Q3", 4.0);
    assert_eq!(queries(&p), ["Q2", "Q3"]);
    assert_eq!(p.query_count(), 3);
    assert_eq!(p.total_time(), 7.0);
}

#[test]
fn test_shrinking_the_capacity_keeps_the_newest_entries() {
    let p = Profiler::new();
    p.enable();
    p.set_capacity(3).unwrap();
    for q in ["Q1", "Q2", "Q3", "Q4"] {
        log(&p, q, 1.0);
    }
    p.set_capacity(2).unwrap();
    assert_eq!(queries(&p), ["Q3", "Q4"]);
    log(&p, "Q5", 1.0);
    assert_eq!(queries(&p), ["Q4", "Q5"]);
}

#[test]
fn test_growing_the_capacity_keeps_the_order() {
    let p = Profiler::new();
    p.enable();
    p.set_capacity(2).unwrap();
    for q in ["Q1", "Q2", "Q3"] {
        log(&p, q, 1.0);
    }
    p.set_capacity(3).unwrap();
    log(&p, "Q4", 1.0);
    assert_eq!(queries(&p), ["Q2", "Q3", "Q4"]);
    log(&p, "Q5", 1.0);
    assert_eq!(queries(&p), ["Q3", "Q4", "Q5"]);
}

#[test]
fn test_capacity_must_be_at_least_one() {
    let p = Profiler::new();
    assert_error(p.set_capacity(0).map(|_| ()), Kind::InvalidArgument, "Profiler capacity must be at least 1");
    assert_eq!(Kind::InvalidArgument.php_class(), "InvalidArgumentException");
}

#[test]
fn test_disable_stops_recording_and_keeps_the_logs_for_inspection() {
    let p = Profiler::new();
    p.enable();
    log(&p, "Q1", 1.0);
    p.disable();
    log(&p, "Q2", 1.0);
    assert_eq!(queries(&p), ["Q1"]);
    assert_eq!(p.query_count(), 1);
}

// ProfilerAdvancedTest

#[test]
fn test_backtrace_capture_when_enabled() {
    let p = Profiler::new();
    p.enable();
    p.enable_backtrace(true);
    log(&p, "SELECT 1", 1.0);
    let logs = p.logs();
    assert_eq!(logs.len(), 1);
    assert!(logs[0].backtrace.as_ref().is_some_and(|b| !b.is_empty()));
}

#[test]
fn test_backtrace_is_null_when_disabled() {
    let p = Profiler::new();
    p.enable();
    log(&p, "SELECT 1", 1.0);
    assert!(p.logs()[0].backtrace.is_none());
}

#[test]
fn test_enable_backtrace_toggle() {
    let p = Profiler::new();
    p.enable();
    p.enable_backtrace(true);
    log(&p, "Q1", 1.0);
    assert!(p.logs()[0].backtrace.is_some());
    p.enable_backtrace(false);
    log(&p, "Q2", 1.0);
    assert!(p.logs()[1].backtrace.is_none());
}

#[test]
fn test_multiple_slow_query_callbacks() {
    let p = Profiler::new();
    p.enable();
    p.set_slow_threshold(10.0);
    let received: Arc<Mutex<Option<Log>>> = Arc::new(Mutex::new(None));
    let sink = received.clone();
    p.on_slow_query(move |entry| *sink.lock().unwrap() = Some(entry.clone()));
    log(&p, "fast", 5.0);
    assert!(p.slow_queries().is_empty());
    log(&p, "slow", 20.0);
    assert_eq!(received.lock().unwrap().as_ref().unwrap().query, "slow");
}

#[test]
fn test_detect_n_plus_one_with_varied_query_patterns() {
    let p = Profiler::new();
    p.enable();
    for i in 0..10 {
        log(&p, &format!("SELECT * FROM users WHERE id = {i}"), 1.0);
    }
    for i in 0..3 {
        log(&p, &format!("SELECT * FROM posts WHERE id = {i}"), 1.0);
    }
    let violations = p.detect_n_plus_one(5);
    assert!(!violations.is_empty());
    assert!(violations.iter().any(|(_, count)| *count >= 10));
    assert_eq!(violations, [("SELECT * FROM users WHERE id = ?".to_owned(), 10)]);
}

#[test]
fn test_detect_n_plus_one_below_threshold_returns_empty() {
    let p = Profiler::new();
    p.enable();
    log(&p, "SELECT * FROM users WHERE id = 1", 1.0);
    log(&p, "SELECT * FROM users WHERE id = 2", 1.0);
    assert!(p.detect_n_plus_one(5).is_empty());
}

#[test]
fn test_get_total_time_with_no_logs_returns_zero() {
    assert_eq!(Profiler::new().total_time(), 0.0);
}

#[test]
fn test_get_slow_queries_returns_empty_when_none_exceed_threshold() {
    let p = Profiler::new();
    p.enable();
    p.set_slow_threshold(50.0);
    log(&p, "fast1", 10.0);
    log(&p, "fast2", 20.0);
    assert!(p.slow_queries().is_empty());
}

#[test]
fn test_log_with_all_parameters() {
    let p = Profiler::new();
    p.enable();
    p.log("SELECT * FROM orders", list!["active"], 15.5, "orders", "find");
    let logs = p.logs();
    assert_eq!(logs.len(), 1);
    assert_eq!(logs[0].query, "SELECT * FROM orders");
    assert_eq!(logs[0].bindings, list!["active"]);
    assert_eq!(logs[0].duration_ms, 15.5);
    assert_eq!(logs[0].collection, "orders");
    assert_eq!(logs[0].operation, "find");
}

#[test]
fn test_reset_clears_everything() {
    let p = Profiler::new();
    p.enable();
    log(&p, "Q1", 10.0);
    log(&p, "Q2", 20.0);
    p.reset();
    assert!(p.logs().is_empty());
    assert_eq!(p.query_count(), 0);
    assert_eq!(p.total_time(), 0.0);
    assert!(p.slow_queries().is_empty());
}

#[test]
fn test_slow_query_callback_receives_the_log_entry() {
    let p = Profiler::new();
    p.enable();
    p.set_slow_threshold(10.0);
    let received: Arc<Mutex<Option<Log>>> = Arc::new(Mutex::new(None));
    let sink = received.clone();
    p.on_slow_query(move |entry| *sink.lock().unwrap() = Some(entry.clone()));
    p.log("SELECT slow", list!["param"], 50.0, "users", "find");
    let entry = received.lock().unwrap().clone().unwrap();
    assert_eq!(entry.query, "SELECT slow");
    assert_eq!(entry.duration_ms, 50.0);
    assert_eq!(entry.collection, "users");
    assert_eq!(entry.bindings, list![v("param")]);
}

#[test]
fn test_detect_n_plus_one_normalizes_query_parameters() {
    let p = Profiler::new();
    p.enable();
    for i in 0..6 {
        log(&p, &format!("SELECT * FROM users WHERE name = 'user_{i}'"), 1.0);
    }
    assert_eq!(p.detect_n_plus_one(5), [("SELECT * FROM users WHERE name = ?".to_owned(), 6)]);
}

#[test]
fn test_get_slow_queries_at_exact_threshold() {
    let p = Profiler::new();
    p.enable();
    p.set_slow_threshold(50.0);
    log(&p, "exact", 50.0);
    assert_eq!(p.slow_queries().len(), 1);
}

#[test]
fn test_enabled_profiler_logs_total_time_correctly() {
    let p = Profiler::new();
    p.enable();
    log(&p, "Q1", 1.5);
    log(&p, "Q2", 2.5);
    log(&p, "Q3", 3.0);
    assert_eq!(p.total_time(), 7.0);
}

#[test]
fn test_n_plus_one_patterns_follow_storage_order() {
    // PHP walks the ring as stored, not oldest first.
    let p = Profiler::new();
    p.enable();
    p.set_capacity(2).unwrap();
    for q in ["A 1", "B 1", "A 2"] {
        log(&p, q, 1.0);
    }
    assert_eq!(p.detect_n_plus_one(1), [("A ?".to_owned(), 1), ("B ?".to_owned(), 1)]);
}
