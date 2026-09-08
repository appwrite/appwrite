use appwrite_response::{
    dynamic, spec, ModelDef, MODEL_ERROR, MODEL_JWT, MODEL_MEMBERSHIP, MODEL_MFA_FACTORS,
    MODEL_MFA_RECOVERY_CODES, MODEL_NONE, MODEL_PREFERENCES, MODEL_SESSION_LIST, MODEL_TARGET_LIST,
    MODEL_USER, MODEL_USER_LIST,
};
use serde_json::json;

fn sample_user() -> serde_json::Value {
    json!({
        "$id": "u1",
        "$createdAt": "2024-01-01T00:00:00.000+00:00",
        "$updatedAt": "2024-01-01T00:00:00.000+00:00",
        "name": "Ada Lovelace",
        "password": "hashed",
        "hash": "argon2",
        "registration": "2024-01-01T00:00:00.000+00:00",
        "status": true,
        "labels": ["vip"],
        "passwordUpdate": "2024-01-01T00:00:00.000+00:00",
        "email": "ada@appwrite.io",
        "phone": "+15555550100",
        "emailVerification": true,
        "phoneVerification": false,
        "mfa": false,
        "prefs": { "theme": "dark" },
        "targets": [
            {
                "$id": "t1",
                "$createdAt": "2024-01-01T00:00:00.000+00:00",
                "$updatedAt": "2024-01-01T00:00:00.000+00:00",
                "name": "Push",
                "userId": "u1",
                "providerType": "push",
                "identifier": "token",
                "expired": false,
            }
        ],
        "accessedAt": "2024-01-01T00:00:00.000+00:00",
        // Internal-only fields that must never leak through the model.
        "search": "internal index text",
        "tokens": ["secret-token"],
    })
}

#[test]
fn user_model_filters_internal_fields() {
    let filtered = dynamic(&sample_user(), MODEL_USER);
    assert_eq!(filtered["name"], "Ada Lovelace");
    assert_eq!(filtered["email"], "ada@appwrite.io");
    assert!(filtered.get("search").is_none());
    assert!(filtered.get("tokens").is_none());
}

#[test]
fn user_model_fills_missing_optional_fields_with_defaults() {
    let doc = json!({ "$id": "u2", "name": "Bare" });
    let filtered = dynamic(&doc, MODEL_USER);
    assert_eq!(filtered["password"], "");
    assert_eq!(filtered["status"], false);
    assert_eq!(filtered["labels"], json!([]));
    assert_eq!(filtered["prefs"], json!({}));
    assert_eq!(filtered["targets"], json!([]));
}

#[test]
fn user_model_recurses_into_targets() {
    let filtered = dynamic(&sample_user(), MODEL_USER);
    let targets = filtered["targets"].as_array().unwrap();
    assert_eq!(targets.len(), 1);
    assert_eq!(targets[0]["identifier"], "token");
    assert_eq!(targets[0]["providerType"], "push");
}

#[test]
fn user_list_model_wraps_total_and_items() {
    let doc = json!({ "total": 2, "documents": [sample_user(), sample_user()] });
    let wrapped = dynamic(&doc, MODEL_USER_LIST);
    assert_eq!(wrapped["total"], 2);
    assert_eq!(wrapped["users"].as_array().unwrap().len(), 2);
    assert_eq!(wrapped["users"][0]["name"], "Ada Lovelace");
}

#[test]
fn user_list_model_accepts_bare_array_and_infers_total() {
    let doc = json!([sample_user()]);
    let wrapped = dynamic(&doc, MODEL_USER_LIST);
    assert_eq!(wrapped["total"], 1);
    assert_eq!(wrapped["users"].as_array().unwrap().len(), 1);
}

#[test]
fn target_list_uses_plural_targets_key() {
    let doc = json!({ "total": 0, "documents": [] });
    let wrapped = dynamic(&doc, MODEL_TARGET_LIST);
    assert!(wrapped.get("targets").is_some());
}

#[test]
fn session_list_uses_plural_sessions_key() {
    let doc = json!({ "total": 0, "documents": [] });
    let wrapped = dynamic(&doc, MODEL_SESSION_LIST);
    assert!(wrapped.get("sessions").is_some());
}

#[test]
fn preferences_model_passes_through() {
    let prefs = json!({ "theme": "pink", "timezone": "UTC" });
    assert_eq!(dynamic(&prefs, MODEL_PREFERENCES), prefs);
    assert_eq!(dynamic(&json!(null), MODEL_PREFERENCES), json!({}));
}

#[test]
fn none_model_is_always_empty() {
    assert_eq!(dynamic(&json!({"anything": true}), MODEL_NONE), json!({}));
}

#[test]
fn error_model_passes_through_error_shape() {
    let err = json!({ "message": "nope", "code": 404, "type": "user_not_found", "version": "1.0" });
    assert_eq!(dynamic(&err, MODEL_ERROR), err);
}

#[test]
fn jwt_model_has_single_rule() {
    let spec = spec(MODEL_JWT).expect("jwt model registered");
    assert_eq!(spec.rules().len(), 1);
    assert_eq!(spec.rules()[0].name, "jwt");
}

#[test]
fn membership_model_includes_role_list() {
    let doc = json!({ "$id": "m1", "roles": ["owner", "developer"] });
    let filtered = dynamic(&doc, MODEL_MEMBERSHIP);
    assert_eq!(filtered["roles"], json!(["owner", "developer"]));
}

#[test]
fn mfa_factors_model_uses_mfa_type_names() {
    let doc = json!({ "totp": true, "phone": false, "email": true, "recoveryCode": false, "custom": false });
    let filtered = dynamic(&doc, MODEL_MFA_FACTORS);
    assert_eq!(filtered["totp"], true);
    assert_eq!(filtered["recoveryCode"], false);
}

#[test]
fn mfa_recovery_codes_model_is_string_array() {
    let doc = json!({ "recoveryCodes": ["a3kf0-s0cl2", "s0co1-as98s"] });
    let filtered = dynamic(&doc, MODEL_MFA_RECOVERY_CODES);
    assert_eq!(filtered["recoveryCodes"].as_array().unwrap().len(), 2);
}

#[test]
fn unregistered_model_passes_through() {
    let doc = json!({ "foo": "bar" });
    assert_eq!(dynamic(&doc, "totally_unregistered_model"), doc);
}

#[test]
fn model_spec_reports_name_and_type() {
    let spec = spec(MODEL_USER).unwrap();
    assert_eq!(spec.name(), "User");
    assert_eq!(spec.model_type(), MODEL_USER);
    assert!(spec.rules().iter().any(|r| r.name == "prefs"));
}
