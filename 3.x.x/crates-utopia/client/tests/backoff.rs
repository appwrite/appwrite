//! PHP `tests/Client/Decorator/Retry/BackoffTest.php`.

mod support;

use support::{request, response};
use utopia_client::{Backoff, Error, Strategy};

fn strategy() -> Backoff {
    Backoff::new().with_randomizer(|| 1.0)
}

#[test]
fn it_retries_transient_transport_failures() {
    let req = request("GET", "https://example.com/resource");
    let delay = strategy().delay(
        &req,
        1,
        None,
        Some(&Error::network(req.clone(), "reset", 0)),
    );
    assert!((delay.unwrap() - 0.1).abs() < f64::EPSILON);
}

#[test]
fn it_does_not_retry_request_exceptions() {
    let req = request("GET", "https://example.com/resource");
    assert!(strategy()
        .delay(&req, 1, None, Some(&Error::invalid_uri(req.clone(), "bad")))
        .is_none());
}

#[test]
fn it_does_not_retry_non_idempotent_methods() {
    let req = request("POST", "https://example.com/resource");
    assert!(strategy()
        .delay(
            &req,
            1,
            None,
            Some(&Error::network(req.clone(), "reset", 0))
        )
        .is_none());
}

#[test]
fn it_does_not_retry_successful_responses() {
    let req = request("GET", "https://example.com/resource");
    let ok = response(200);
    assert!(strategy().delay(&req, 1, Some(&ok), None).is_none());
}

#[test]
fn it_retries_overloaded_status_responses() {
    let req = request("GET", "https://example.com/resource");
    let overloaded = response(503);
    let delay = strategy().delay(&req, 1, Some(&overloaded), None);
    assert!((delay.unwrap() - 0.1).abs() < f64::EPSILON);
}

#[test]
fn it_stops_at_max_attempts() {
    let req = request("GET", "https://example.com/resource");
    assert!(strategy()
        .delay(
            &req,
            3,
            None,
            Some(&Error::network(req.clone(), "reset", 0))
        )
        .is_none());
}

#[test]
fn it_grows_the_delay_exponentially() {
    let req = request("GET", "https://example.com/resource");
    let strategy = strategy();
    let error = Error::network(req.clone(), "reset", 0);
    assert!((strategy.delay(&req, 1, None, Some(&error)).unwrap() - 0.1).abs() < f64::EPSILON);
    assert!((strategy.delay(&req, 2, None, Some(&error)).unwrap() - 0.2).abs() < f64::EPSILON);
}

#[test]
fn it_honours_numeric_retry_after_capped_to_max_delay() {
    let req = request("GET", "https://example.com/resource");
    let mut response = response(503);
    response
        .headers_mut()
        .insert("retry-after", "999".parse().unwrap());
    let delay = strategy().delay(&req, 1, Some(&response), None);
    assert!((delay.unwrap() - 10.0).abs() < f64::EPSILON);
}

#[test]
fn it_ignores_non_numeric_retry_after() {
    let req = request("GET", "https://example.com/resource");
    let mut response = response(503);
    response.headers_mut().insert(
        "retry-after",
        "Wed, 21 Oct 2025 07:28:00 GMT".parse().unwrap(),
    );
    let delay = strategy().delay(&req, 1, Some(&response), None);
    assert!((delay.unwrap() - 0.1).abs() < f64::EPSILON);
}

#[test]
fn it_applies_full_jitter_within_the_ceiling() {
    let req = request("GET", "https://example.com/resource");
    let strategy = Backoff::new();
    let error = Error::network(req.clone(), "reset", 0);
    for _ in 0..50 {
        let delay = strategy.delay(&req, 1, None, Some(&error)).unwrap();
        assert!((0.0..=0.1).contains(&delay));
    }
}
