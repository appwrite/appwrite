use std::sync::Arc;

use utopia_circuit_breaker::{
    Adapter, CacheValue, CircuitBreaker, CircuitBreakerError, CircuitState, Memory,
};
use utopia_telemetry::TestAdapter;

#[test]
fn uses_in_memory_state_by_default() {
    let breaker = CircuitBreaker::with_config(2, 30, 1);
    let first = breaker.call(|| "fallback", || Err::<&str, _>("failed"));
    let second = breaker.call(|| "fallback", || Err::<&str, _>("failed"));
    assert_eq!(first, "fallback");
    assert_eq!(second, "fallback");
    assert_eq!(breaker.get_state(), CircuitState::Open);
    assert_eq!(breaker.get_failure_count(), 2);
}

#[test]
fn cached_state_is_shared_across_breaker_instances() {
    let cache: Arc<dyn Adapter> = Arc::new(Memory::new());
    let first =
        CircuitBreaker::with_adapter(2, 30, 1, Some(Arc::clone(&cache)), "users-api", None, "")
            .unwrap();
    let second =
        CircuitBreaker::with_adapter(2, 30, 1, Some(Arc::clone(&cache)), "users-api", None, "")
            .unwrap();
    first.call(|| "fallback", || Err::<&str, _>("failed"));
    first.call(|| "fallback", || Err::<&str, _>("failed"));
    assert!(second.is_open());
    assert_eq!(second.get_failure_count(), 2);
    let result = second.call(
        || "shared fallback",
        || -> Result<&str, &str> {
            panic!("Closed callback should not run while the shared circuit is open.")
        },
    );
    assert_eq!(result, "shared fallback");
}

#[test]
fn closed_success_does_not_write_zero_failures_when_already_zero() {
    let cache = Arc::new(Memory::recording());
    let adapter: Arc<dyn Adapter> = cache.clone();
    let breaker =
        CircuitBreaker::with_adapter(1, 30, 1, Some(adapter), "users-api", None, "").unwrap();
    assert_eq!(breaker.call(|| "fallback", || Ok::<_, &str>("ok")), "ok");
    assert!(cache.writes.lock().is_empty());
}

#[test]
fn cached_transitions_write_state_last() {
    let cache = Arc::new(Memory::new());
    let adapter: Arc<dyn Adapter> = cache.clone();
    let breaker =
        CircuitBreaker::with_adapter(1, 30, 1, Some(adapter), "users-api", None, "").unwrap();
    breaker.call(|| "fallback", || Err::<&str, _>("failed"));
    assert_eq!(
        cache.get("users-api:state").unwrap(),
        Some(CacheValue::String("open".into()))
    );
}

#[test]
fn half_open_successes_close_the_circuit() {
    let breaker = CircuitBreaker::with_config(1, 0, 2);
    breaker.call(|| "fallback", || Err::<&str, _>("failed"));
    assert_eq!(
        breaker.call_half_open(|| "fallback", || Ok::<_, &str>("closed"), || Ok("probe-1")),
        "probe-1"
    );
    assert!(breaker.is_half_open());
    assert_eq!(breaker.get_success_count(), 1);
    assert_eq!(
        breaker.call_half_open(|| "fallback", || Ok::<_, &str>("closed"), || Ok("probe-2")),
        "probe-2"
    );
    assert!(breaker.is_closed());
    assert_eq!(breaker.get_failure_count(), 0);
    assert_eq!(breaker.get_success_count(), 0);
}

#[test]
fn records_telemetry_for_calls_fallbacks_and_transitions() {
    let telemetry = Arc::new(TestAdapter::new());
    let breaker =
        CircuitBreaker::with_adapter(1, 30, 1, None, "default", Some(telemetry.clone()), "")
            .unwrap();
    let result = breaker.call(|| "fallback", || Err::<&str, _>("failed"));
    assert_eq!(result, "fallback");
    assert_eq!(telemetry.counter_measurements("breaker.calls").len(), 1);
    assert_eq!(
        telemetry
            .counter_measurements("breaker.callback_failures")
            .len(),
        1
    );
    assert_eq!(telemetry.counter_measurements("breaker.fallbacks").len(), 1);
    assert_eq!(
        telemetry.counter_measurements("breaker.transitions").len(),
        1
    );
    assert_eq!(
        telemetry
            .up_down_counter_measurements("breaker.active_calls")
            .len(),
        2
    );
    assert_eq!(telemetry.gauge_measurements("breaker.state").len(), 1);
    assert_eq!(telemetry.gauge_measurements("breaker.failures").len(), 1);
    assert_eq!(telemetry.gauge_measurements("breaker.successes").len(), 1);
    assert_eq!(
        telemetry
            .gauge_measurements("breaker.event.timestamp")
            .len(),
        1
    );
}

#[test]
fn prefixes_telemetry_metric_names() {
    let telemetry = Arc::new(TestAdapter::new());
    let breaker = CircuitBreaker::with_adapter(1, 30, 1, None, "default", None, ".edge.").unwrap();
    breaker.set_telemetry(telemetry.clone());
    let result = breaker.call(|| "fallback", || Err::<&str, _>("failed"));
    assert_eq!(result, "fallback");
    assert_eq!(
        telemetry.counter_measurements("edge.breaker.calls").len(),
        1
    );
    assert!(telemetry.counter_measurements("breaker.calls").is_empty());
}

#[test]
fn inspection_methods_do_not_emit_telemetry() {
    let telemetry = Arc::new(TestAdapter::new());
    let breaker =
        CircuitBreaker::with_adapter(3, 30, 2, None, "default", Some(telemetry.clone()), "")
            .unwrap();
    assert_eq!(breaker.get_state(), CircuitState::Closed);
    assert_eq!(breaker.get_failure_count(), 0);
    assert_eq!(breaker.get_success_count(), 0);
    assert!(telemetry.gauge_measurements("breaker.state").is_empty());
    assert!(telemetry.counter_measurements("breaker.calls").is_empty());
    assert!(telemetry
        .counter_measurements("breaker.callback_failures")
        .is_empty());
}

#[test]
fn rare_telemetry_instruments_are_created_on_first_record() {
    let telemetry = Arc::new(TestAdapter::new());
    let breaker =
        CircuitBreaker::with_adapter(3, 30, 2, None, "default", Some(telemetry.clone()), "")
            .unwrap();
    assert!(telemetry
        .counter_measurements("breaker.callback_failures")
        .is_empty());
    breaker.trip();
    assert!(telemetry
        .counter_measurements("breaker.callback_failures")
        .is_empty());
    assert_eq!(
        telemetry.counter_measurements("breaker.transitions").len(),
        1
    );
    assert_eq!(
        telemetry
            .gauge_measurements("breaker.event.timestamp")
            .len(),
        1
    );
}

#[test]
fn successful_calls_do_not_create_rare_telemetry_instruments() {
    let telemetry = Arc::new(TestAdapter::new());
    let breaker =
        CircuitBreaker::with_adapter(3, 30, 2, None, "default", Some(telemetry.clone()), "")
            .unwrap();
    assert_eq!(breaker.call(|| "fallback", || Ok::<_, &str>("ok")), "ok");
    assert_eq!(telemetry.counter_measurements("breaker.calls").len(), 1);
    assert!(telemetry
        .counter_measurements("breaker.callback_failures")
        .is_empty());
    assert!(telemetry
        .counter_measurements("breaker.fallbacks")
        .is_empty());
    assert!(telemetry
        .counter_measurements("breaker.transitions")
        .is_empty());
}

#[test]
fn rejects_empty_cache_key_when_cache_is_configured() {
    let cache: Arc<dyn Adapter> = Arc::new(Memory::new());
    let err = CircuitBreaker::with_adapter(3, 30, 2, Some(cache), "", None, "").unwrap_err();
    assert!(matches!(err, CircuitBreakerError::InvalidArgument(_)));
}

#[test]
fn trip_transitions_to_open() {
    let breaker = CircuitBreaker::new();
    assert_eq!(breaker.get_state(), CircuitState::Closed);
    breaker.trip();
    assert_eq!(breaker.get_state(), CircuitState::Open);
    assert!(breaker.is_open());
}

#[test]
fn tripped_breaker_short_circuits_calls() {
    let breaker = CircuitBreaker::with_config(100, 30, 1);
    breaker.trip();
    let result = breaker.call(
        || "fallback",
        || -> Result<&str, &str> {
            panic!("Closed callback should not run when the breaker has been tripped.")
        },
    );
    assert_eq!(result, "fallback");
    assert!(breaker.is_open());
}

#[test]
fn trip_is_idempotent() {
    let breaker = CircuitBreaker::new();
    breaker.trip();
    breaker.trip();
    breaker.trip();
    assert_eq!(breaker.get_state(), CircuitState::Open);
}

#[test]
fn trip_persists_state_through_cache_adapter() {
    let cache: Arc<dyn Adapter> = Arc::new(Memory::new());
    let first =
        CircuitBreaker::with_adapter(3, 30, 2, Some(Arc::clone(&cache)), "users-api", None, "")
            .unwrap();
    first.trip();
    let second =
        CircuitBreaker::with_adapter(3, 30, 2, Some(cache), "users-api", None, "").unwrap();
    assert!(second.is_open());
}

#[test]
fn trip_emits_transition_telemetry() {
    let telemetry = Arc::new(TestAdapter::new());
    let breaker =
        CircuitBreaker::with_adapter(3, 30, 2, None, "default", Some(telemetry.clone()), "")
            .unwrap();
    breaker.trip();
    assert_eq!(
        telemetry.counter_measurements("breaker.transitions").len(),
        1
    );
    assert_eq!(telemetry.gauge_measurements("breaker.state").len(), 1);
}

#[test]
fn active_call_telemetry_uses_post_update_state() {
    let cache = Memory::with_values(
        [
            ("users-api:state".into(), CacheValue::String("open".into())),
            ("users-api:failures".into(), CacheValue::Int(1)),
            ("users-api:successes".into(), CacheValue::Int(0)),
            (
                "users-api:opened_at".into(),
                CacheValue::Int(
                    i32::try_from(
                        std::time::SystemTime::now()
                            .duration_since(std::time::UNIX_EPOCH)
                            .unwrap()
                            .as_secs() as i64
                            - 10,
                    )
                    .unwrap_or(0),
                ),
            ),
        ]
        .into_iter()
        .collect(),
    );
    let telemetry = Arc::new(TestAdapter::new());
    let adapter: Arc<dyn Adapter> = Arc::new(cache);
    let breaker = CircuitBreaker::with_adapter(
        1,
        0,
        1,
        Some(adapter),
        "users-api",
        Some(telemetry.clone()),
        "",
    )
    .unwrap();
    let result = breaker.call_half_open(|| "fallback", || Ok::<_, &str>("closed"), || Ok("probe"));
    assert_eq!(result, "probe");
    let active = telemetry.up_down_counter_measurements("breaker.active_calls");
    assert_eq!(active.len(), 2);
    assert_eq!(
        active[0]
            .attributes
            .get("circuit_breaker.state")
            .map(String::as_str),
        Some("half_open")
    );
}
