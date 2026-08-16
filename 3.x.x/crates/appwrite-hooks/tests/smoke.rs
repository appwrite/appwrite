use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;

use appwrite_hooks::{Hooks, PASSWORD_VALIDATOR};
use serde_json::json;

#[test]
fn trigger_unregistered_hook_returns_none() {
    let hooks = Hooks::new();
    assert_eq!(hooks.trigger("missing", &[]), None);
    assert!(hooks.is_empty());
}

#[test]
fn add_and_trigger_hook_with_params() {
    let mut hooks = Hooks::new();
    hooks.add("sum", |params| {
        let total: i64 = params.iter().filter_map(serde_json::Value::as_i64).sum();
        json!(total)
    });

    assert!(hooks.has("sum"));
    assert_eq!(
        hooks.trigger("sum", &[json!(1), json!(2), json!(3)]),
        Some(json!(6))
    );
}

#[test]
fn password_validator_hook_slot() {
    let mut hooks = Hooks::new();
    hooks.add(PASSWORD_VALIDATOR, |params| {
        let password = params.first().and_then(|v| v.as_str()).unwrap_or_default();
        json!(password.chars().count() >= 8)
    });

    assert_eq!(
        hooks.trigger(PASSWORD_VALIDATOR, &[json!("short")]),
        Some(json!(false))
    );
    assert_eq!(
        hooks.trigger(PASSWORD_VALIDATOR, &[json!("longenoughpassword")]),
        Some(json!(true))
    );
}

#[test]
fn hook_that_itself_returns_null_is_distinguishable_from_unregistered() {
    let mut hooks = Hooks::new();
    hooks.add("returns-null", |_| serde_json::Value::Null);

    assert_eq!(
        hooks.trigger("returns-null", &[]),
        Some(serde_json::Value::Null)
    );
    assert_eq!(hooks.trigger("never-registered", &[]), None);
}

#[test]
fn remove_unregisters_hook() {
    let mut hooks = Hooks::new();
    hooks.add("temp", |_| json!(true));
    assert!(hooks.has("temp"));

    hooks.remove("temp");
    assert!(!hooks.has("temp"));
    assert_eq!(hooks.trigger("temp", &[]), None);
}

#[test]
fn hooks_can_close_over_shared_state() {
    let calls = Arc::new(AtomicUsize::new(0));
    let calls_clone = Arc::clone(&calls);

    let mut hooks = Hooks::new();
    hooks.add("count", move |_| {
        calls_clone.fetch_add(1, Ordering::SeqCst);
        json!(null)
    });

    let _ = hooks.trigger("count", &[]);
    let _ = hooks.trigger("count", &[]);
    assert_eq!(calls.load(Ordering::SeqCst), 2);
}

#[test]
fn len_tracks_registered_hooks() {
    let mut hooks = Hooks::new();
    assert_eq!(hooks.len(), 0);
    hooks.add("a", |_| json!(1));
    hooks.add("b", |_| json!(2));
    assert_eq!(hooks.len(), 2);
}
