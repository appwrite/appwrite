use appwrite_database::{queries, resolve_id, CustomId, UNIQUE_SENTINEL};
use serde_json::json;
use utopia_validators::Validator;

#[test]
fn custom_id_accepts_unique_sentinel() {
    let validator = CustomId::default();
    assert!(validator.is_valid(&json!(UNIQUE_SENTINEL)));
}

#[test]
fn custom_id_accepts_key_like_ids() {
    let validator = CustomId::default();
    assert!(validator.is_valid(&json!("my-custom-id")));
    assert!(validator.is_valid(&json!("user_123")));
    assert!(validator.is_valid(&json!("user.123")));
    assert!(validator.is_valid(&json!("a".repeat(36))));
}

#[test]
fn custom_id_rejects_leading_special_chars() {
    let validator = CustomId::default();
    assert!(!validator.is_valid(&json!(".leading-dot")));
    assert!(!validator.is_valid(&json!("-leading-dash")));
    assert!(!validator.is_valid(&json!("_leading-underscore")));
}

#[test]
fn custom_id_rejects_too_long_and_invalid_chars() {
    let validator = CustomId::default();
    assert!(!validator.is_valid(&json!("a".repeat(37))));
    assert!(!validator.is_valid(&json!("has space")));
    assert!(!validator.is_valid(&json!("has/slash")));
}

#[test]
fn custom_id_rejects_non_string() {
    let validator = CustomId::default();
    assert!(!validator.is_valid(&json!(12345)));
}

#[test]
fn custom_id_respects_custom_max_length() {
    let validator = CustomId::new(false, 5);
    assert_eq!(validator.max_length(), 5);
    assert!(validator.is_valid(&json!("abcde")));
    assert!(!validator.is_valid(&json!("abcdef")));
    // The unique() sentinel bypasses length checks entirely.
    assert!(validator.is_valid(&json!(UNIQUE_SENTINEL)));
}

#[test]
fn resolve_id_passes_through_custom_ids() {
    assert_eq!(resolve_id("my-custom-id"), "my-custom-id");
}

#[test]
fn resolve_id_generates_unique_ids_for_sentinel() {
    let a = resolve_id(UNIQUE_SENTINEL);
    let b = resolve_id(UNIQUE_SENTINEL);
    assert_ne!(a, UNIQUE_SENTINEL);
    assert_ne!(b, UNIQUE_SENTINEL);
    assert_ne!(a, b);
    assert!(!a.is_empty());
}

#[test]
fn query_helpers_build_expected_shapes() {
    let query = queries::search("john");
    assert_eq!(query.get_attribute(), "search");

    let query = queries::by_user_internal_id(42);
    assert_eq!(query.get_attribute(), "userInternalId");

    let query = queries::by_user_id("user1");
    assert_eq!(query.get_attribute(), "userId");

    let query = queries::by_target_identifier("a@b.com");
    assert_eq!(query.get_attribute(), "identifier");

    let query = queries::by_provider_email("a@b.com");
    assert_eq!(query.get_attribute(), "providerEmail");
}
