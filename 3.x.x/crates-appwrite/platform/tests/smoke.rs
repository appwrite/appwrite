use appwrite_event::{AuditPublisher, DeletePublisher};
use appwrite_platform::AppwritePlatform;
use serde_json::json;

#[test]
fn smoke() {
    assert!(AppwritePlatform::new().ensure_ready().is_ok());
}

#[test]
fn default_password_validator_hook_is_registered() {
    let platform = AppwritePlatform::new();
    assert!(platform.hooks().has(appwrite_hooks::PASSWORD_VALIDATOR));
    assert_eq!(
        platform
            .hooks()
            .trigger(appwrite_hooks::PASSWORD_VALIDATOR, &[json!("short")]),
        Some(json!(false))
    );
    assert_eq!(
        platform.hooks().trigger(
            appwrite_hooks::PASSWORD_VALIDATOR,
            &[json!("longenoughpassword")]
        ),
        Some(json!(true))
    );
}

#[test]
fn hooks_mut_allows_overriding_project_policy() {
    let mut platform = AppwritePlatform::new();
    platform
        .hooks_mut()
        .add(appwrite_hooks::PASSWORD_VALIDATOR, |_| json!(false));
    assert_eq!(
        platform.hooks().trigger(
            appwrite_hooks::PASSWORD_VALIDATOR,
            &[json!("longenoughpassword")]
        ),
        Some(json!(false))
    );
}

#[test]
fn delete_and_audit_publishers_start_empty() {
    let platform = AppwritePlatform::new();
    assert_eq!(platform.deletes().size(), 0);
    assert_eq!(platform.audits().size(), 0);
}

#[test]
fn default_impl_matches_new() {
    let platform = AppwritePlatform::default();
    assert!(platform.ensure_ready().is_ok());
}
