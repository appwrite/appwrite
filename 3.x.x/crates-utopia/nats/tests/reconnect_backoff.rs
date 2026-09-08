//! Port of `tests/Unit/ReconnectBackoffTest.php`.

use utopia_nats::Connection;

fn assert_delta(actual: f64, expected: f64) {
    assert!(
        (actual - expected).abs() < f64::EPSILON,
        "expected {expected}, got {actual}"
    );
}

#[test]
fn test_first_attempt_is_immediate() {
    assert_delta(Connection::reconnect_backoff(0, 2.0, 30.0, 2.0), 0.0);
}

#[test]
fn test_backoff_grows_exponentially() {
    assert_delta(Connection::reconnect_backoff(1, 2.0, 100.0, 2.0), 2.0);
    assert_delta(Connection::reconnect_backoff(2, 2.0, 100.0, 2.0), 4.0);
    assert_delta(Connection::reconnect_backoff(3, 2.0, 100.0, 2.0), 8.0);
    assert_delta(Connection::reconnect_backoff(4, 2.0, 100.0, 2.0), 16.0);
}

#[test]
fn test_backoff_is_capped() {
    let cap = 8.0;
    for attempt in 1..=20 {
        assert!(Connection::reconnect_backoff(attempt, 2.0, cap, 2.0) <= cap);
    }
    assert_delta(Connection::reconnect_backoff(10, 2.0, cap, 2.0), cap);
}

#[test]
fn test_custom_factor() {
    assert_delta(Connection::reconnect_backoff(1, 1.0, 100.0, 3.0), 1.0);
    assert_delta(Connection::reconnect_backoff(2, 1.0, 100.0, 3.0), 3.0);
    assert_delta(Connection::reconnect_backoff(3, 1.0, 100.0, 3.0), 9.0);
}

#[test]
fn test_buffer_accepts_until_cap() {
    let cap = 100;
    assert!(Connection::reconnect_buffer_accepts(0, 50, cap));
    assert!(Connection::reconnect_buffer_accepts(50, 50, cap));
    assert!(!Connection::reconnect_buffer_accepts(50, 51, cap));
    assert!(!Connection::reconnect_buffer_accepts(100, 1, cap));
}

#[test]
fn test_zero_cap_disables_buffering() {
    assert!(!Connection::reconnect_buffer_accepts(0, 1, 0));
    assert!(!Connection::reconnect_buffer_accepts(0, 1, -5));
}
