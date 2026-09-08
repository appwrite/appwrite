use appwrite_auth::{mfa, verify_password, Argon2, Key, Password, Phone, ROLE_GUESTS, ROLE_KEYS};
use serde_json::json;
use utopia_validators::Validator;

#[test]
fn password_validator_enforces_length_bounds() {
    let validator = Password::new(false);
    assert!(!validator.is_valid(&json!("short")));
    assert!(validator.is_valid(&json!("exactly8")));
    assert!(validator.is_valid(&json!("a".repeat(256))));
    assert!(!validator.is_valid(&json!("a".repeat(257))));
    assert!(!validator.is_valid(&json!(12_345_678)));
}

#[test]
fn password_validator_allow_empty() {
    let strict = Password::new(false);
    let lenient = Password::new(true);
    assert!(!strict.is_valid(&json!("")));
    assert!(lenient.is_valid(&json!("")));
}

#[test]
fn phone_validator_accepts_e164() {
    let validator = Phone::new();
    assert!(validator.is_valid(&json!("+16175551212")));
    assert!(!validator.is_valid(&json!("not-a-phone")));
    assert!(!validator.is_valid(&json!(123)));
}

#[test]
fn mfa_type_constants_match_php() {
    assert_eq!(mfa::TOTP, "totp");
    assert_eq!(mfa::EMAIL, "email");
    assert_eq!(mfa::PHONE, "phone");
    assert_eq!(mfa::RECOVERY_CODE, "recoveryCode");
    assert_eq!(mfa::CUSTOM, "custom");
    assert_eq!(mfa::ALL.len(), 5);
}

#[test]
fn key_decode_standard_finds_matching_secret() {
    let project = json!({
        "$id": "proj1",
        "keys": [
            { "secret": "abc123", "scopes": ["users.read", "users.write"], "name": "CI Key" },
        ],
    });

    let key = Key::decode_standard(&project, "abc123");
    assert_eq!(key.project_id, "proj1");
    assert_eq!(key.name, "CI Key");
    assert_eq!(key.role, ROLE_KEYS);
    assert!(!key.expired);
    assert_eq!(
        key.scopes,
        vec!["users.read".to_string(), "users.write".to_string()]
    );
}

#[test]
fn key_decode_standard_falls_back_to_guest_when_missing() {
    let project = json!({ "$id": "proj1", "keys": [] });
    let key = Key::decode_standard(&project, "does-not-exist");
    assert_eq!(key.role, ROLE_GUESTS);
    assert!(key.scopes.is_empty());
    assert!(!key.expired);
}

#[test]
fn key_decode_standard_falls_back_to_guest_when_no_keys_array() {
    let project = json!({ "$id": "proj1" });
    let key = Key::decode_standard(&project, "anything");
    assert_eq!(key.role, ROLE_GUESTS);
}

#[test]
fn key_decode_standard_detects_expired_key() {
    let project = json!({
        "$id": "proj1",
        "keys": [
            { "secret": "expired-secret", "scopes": [], "name": "Old", "expire": "2000-01-01T00:00:00.000+00:00" },
        ],
    });
    let key = Key::decode_standard(&project, "expired-secret");
    assert!(key.expired);
}

#[test]
fn key_decode_standard_detects_non_expired_key() {
    let project = json!({
        "$id": "proj1",
        "keys": [
            { "secret": "future-secret", "scopes": [], "name": "Future", "expire": "2999-01-01T00:00:00.000+00:00" },
        ],
    });
    let key = Key::decode_standard(&project, "future-secret");
    assert!(!key.expired);
}

#[test]
fn hash_and_verify_password_round_trip() {
    let hasher = Argon2::default();
    let hashed = appwrite_auth::hash_password(&hasher, "correct horse battery staple").unwrap();
    assert!(verify_password(
        &hasher,
        "correct horse battery staple",
        &hashed
    ));
    assert!(!verify_password(&hasher, "wrong password", &hashed));
}
