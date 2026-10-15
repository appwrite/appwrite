//! Ported from utopia-php/database 8.1.3 `tests/unit/Authorization/AuthorizationTest.php`,
//! `tests/unit/Authorization/FluentTest.php` and
//! `tests/unit/Validator/AuthorizationTest.php`.
//!
//! PHP's `skip(callable)` is `skip_sync` for work that does not await and
//! `skip` for a future. `testIsValidReturnsFalseWithInvalidInput` is ported
//! through the `utopia_validators::Validator` trait (any value that is not
//! an `Input`).

use utopia_database::validator::authorization::{Authorization, Input};
use utopia_database::value::Value;
use utopia_database::{Document, Permission, PermissionType, Role, id};

fn strings(items: &[&str]) -> Vec<String> {
    items.iter().map(|s| s.to_string()).collect()
}

fn input(action: PermissionType, permissions: &[&str]) -> Input {
    Input::new(action, strings(permissions))
}

// Authorization/AuthorizationTest

#[test]
fn test_default_roles_contain_any() {
    let auth = Authorization::default();
    assert_eq!(auth.role_names(), ["any"]);
    assert_eq!(auth.roles(), [Value::from("any")]);
}

#[test]
fn test_is_valid_with_matching_role() {
    let auth = Authorization::default();
    auth.add_role("user:123");
    assert!(auth.is_valid(&input(PermissionType::Read, &["user:123"])));
}

#[test]
fn test_is_valid_with_non_matching_role() {
    let auth = Authorization::default();
    auth.add_role("user:123");
    assert!(!auth.is_valid(&input(PermissionType::Read, &["user:456"])));
}

#[test]
fn test_is_valid_with_any_role_matches_all_permissions() {
    assert!(Authorization::default().is_valid(&input(PermissionType::Read, &["any"])));
}

#[test]
fn test_is_valid_returns_false_with_empty_permissions() {
    let auth = Authorization::default();
    assert!(!auth.is_valid(&input(PermissionType::Read, &[])));
    assert!(auth.description().contains("No permissions provided"));
}

#[test]
fn test_is_valid_returns_false_with_invalid_input() {
    let auth = Authorization::default();
    assert!(!utopia_validators::Validator::is_valid(&auth, &serde_json::json!("not-an-input")));
    assert_eq!(auth.description(), "Invalid input provided");
}

#[test]
fn test_add_role() {
    let auth = Authorization::default();
    auth.add_role("user:123");
    assert!(auth.has_role("user:123"));
    assert!(auth.role_names().contains(&"user:123".to_owned()));
}

#[test]
fn test_remove_role() {
    let auth = Authorization::default();
    auth.add_role("user:123");
    assert!(auth.has_role("user:123"));
    auth.remove_role("user:123");
    assert!(!auth.has_role("user:123"));
}

#[test]
fn test_get_roles_returns_all_roles() {
    let auth = Authorization::default();
    auth.add_role("user:123");
    auth.add_role("team:456");
    auth.add_role("users");
    assert_eq!(auth.role_names(), ["any", "user:123", "team:456", "users"]);
}

#[test]
fn test_skip_bypasses_authorization() {
    let auth = Authorization::default();
    auth.clean_roles();
    let i = input(PermissionType::Read, &["user:999"]);
    assert!(!auth.is_valid(&i));
    assert!(auth.skip_sync(|| auth.is_valid(&i)));
}

#[test]
fn test_skip_restores_status_after_callback() {
    let auth = Authorization::default();
    assert!(auth.status());
    auth.skip_sync(|| assert!(!auth.status()));
    assert!(auth.status());
}

#[test]
fn test_skip_restores_status_on_exception() {
    let auth = Authorization::default();
    assert!(auth.status());
    let result = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| auth.skip_sync(|| panic!("test"))));
    assert!(result.is_err());
    assert!(auth.status());
}

#[tokio::test]
async fn test_skip_restores_status_after_a_future() {
    let auth = Authorization::default();
    let inside: Result<bool, &str> = auth.skip(async { Err("test") }).await.map(|()| auth.status());
    assert_eq!(inside, Err("test"));
    assert!(auth.skip(async { !auth.status() }).await);
    assert!(auth.status());
}

#[test]
fn test_is_valid_with_multiple_roles() {
    let auth = Authorization::default();
    auth.add_role("user:123");
    auth.add_role("team:456");
    assert!(auth.is_valid(&input(PermissionType::Read, &["team:456"])));
}

#[test]
fn test_is_valid_with_multiple_permissions_matches_first() {
    let auth = Authorization::default();
    auth.add_role("user:123");
    assert!(auth.is_valid(&input(PermissionType::Read, &["user:123", "team:456"])));
}

#[test]
fn test_is_valid_with_multiple_permissions_matches_last() {
    let auth = Authorization::default();
    auth.add_role("team:456");
    assert!(auth.is_valid(&input(PermissionType::Read, &["user:123", "team:456"])));
}

#[test]
fn test_is_valid_with_named_roles() {
    for role in ["guests", "users", "user:123/admin"] {
        let auth = Authorization::default();
        auth.add_role(role);
        assert!(auth.is_valid(&input(PermissionType::Read, &[role])), "{role}");
    }
}

#[test]
fn test_dimensional_role_does_not_match_without_dimension() {
    let auth = Authorization::default();
    auth.add_role("user:123/admin");
    assert!(!auth.is_valid(&input(PermissionType::Read, &["user:123"])));
}

#[test]
fn test_non_dimensional_role_does_not_match_with_dimension() {
    let auth = Authorization::default();
    auth.add_role("user:123");
    assert!(!auth.is_valid(&input(PermissionType::Read, &["user:123/admin"])));
}

#[test]
fn test_get_description_on_failure() {
    let auth = Authorization::default();
    auth.clean_roles();
    auth.add_role("user:123");
    assert!(!auth.is_valid(&input(PermissionType::Read, &["team:456"])));
    assert_eq!(
        auth.description(),
        "Missing \"read\" permission for role \"team:456\". Only \"[\"user:123\"]\" scopes are allowed and \"[\"team:456\"]\" was given."
    );
}

#[test]
fn test_get_description_on_empty_permissions() {
    let auth = Authorization::default();
    assert!(!auth.is_valid(&input(PermissionType::Write, &[])));
    assert_eq!(auth.description(), "No permissions provided for action 'write'");
}

#[test]
fn test_clean_roles_removes_all() {
    let auth = Authorization::default();
    auth.add_role("user:123");
    auth.add_role("team:456");
    assert_eq!(auth.roles().len(), 3);
    auth.clean_roles();
    assert!(auth.roles().is_empty());
    assert!(!auth.has_role("any"));
}

#[test]
fn test_disable_and_enable() {
    let auth = Authorization::default();
    assert!(auth.status());
    auth.disable();
    assert!(!auth.status());
    auth.enable();
    assert!(auth.status());
}

#[test]
fn test_disabled_authorization_bypasses_all_checks() {
    let auth = Authorization::default();
    auth.disable();
    auth.clean_roles();
    assert!(auth.is_valid(&input(PermissionType::Read, &["user:999"])));
}

#[test]
fn test_constructor_sets_the_default_status() {
    let auth = Authorization::new(false);
    assert!(!auth.status());
    auth.enable();
    auth.reset();
    assert!(!auth.status());
}

#[test]
fn test_reset_restores_default_status() {
    let auth = Authorization::default();
    auth.disable();
    assert!(!auth.status());
    auth.reset();
    assert!(auth.status());
}

#[test]
fn test_permission_type_matching() {
    for kind in [
        PermissionType::Read,
        PermissionType::Create,
        PermissionType::Update,
        PermissionType::Delete,
        PermissionType::Write,
    ] {
        let auth = Authorization::default();
        auth.add_role("user:123");
        assert!(auth.is_valid(&input(kind, &["user:123"])));
    }
}

#[test]
fn test_has_role() {
    let auth = Authorization::default();
    assert!(auth.has_role("any"));
    assert!(!auth.has_role("user:123"));
    auth.add_role("user:123");
    assert!(auth.has_role("user:123"));
}

#[test]
fn test_is_array_and_type() {
    let auth = Authorization::default();
    assert!(!utopia_validators::Validator::is_array(&auth));
    assert_eq!(utopia_validators::Validator::kind(&auth), utopia_validators::Type::Array);
}

#[test]
fn test_input_setters_and_getters() {
    let mut i = input(PermissionType::Read, &["user:123"]);
    assert_eq!(i.action, "read");
    assert_eq!(i.permissions, ["user:123"]);
    i.set_action(PermissionType::Write);
    assert_eq!(i.action, "write");
    i.set_permissions(strings(&["team:456"]));
    assert_eq!(i.permissions, ["team:456"]);
}

#[test]
fn test_is_valid_with_scoped_roles() {
    for (role, other) in
        [("team:abc/owner", "team:abc/member"), ("label:vip", "label:premium"), ("member:abc123", "member:def456")]
    {
        let auth = Authorization::default();
        auth.add_role(role);
        assert!(auth.is_valid(&input(PermissionType::Read, &[role])));
        assert!(!auth.is_valid(&input(PermissionType::Read, &[other])));
    }
}

#[test]
fn test_adding_duplicate_role_does_not_duplicate() {
    let auth = Authorization::default();
    auth.add_role("user:123");
    auth.add_role("user:123");
    assert_eq!(auth.role_names().iter().filter(|r| *r == "user:123").count(), 1);
}

#[test]
fn test_removing_non_existent_role_does_not_throw() {
    let auth = Authorization::default();
    auth.remove_role("nonexistent");
    assert!(!auth.has_role("nonexistent"));
}

#[test]
fn test_numeric_roles_read_back_as_integers() {
    // PHP's roles are array keys: "123" is the integer key 123.
    let auth = Authorization::default();
    auth.add_role("123");
    assert_eq!(auth.roles(), [Value::from("any"), Value::Int(123)]);
    assert!(auth.is_valid(&input(PermissionType::Read, &["123"])));
    auth.clean_roles();
    auth.add_role("7");
    assert!(!auth.is_valid(&input(PermissionType::Read, &["a/b"])));
    assert_eq!(
        auth.description(),
        "Missing \"read\" permission for role \"a/b\". Only \"[7]\" scopes are allowed and \"[\"a\\/b\"]\" was given."
    );
}

#[tokio::test]
async fn test_with_roles_scopes_the_roles() {
    let auth = Authorization::default();
    let inside = auth
        .with_roles(&strings(&["user:1"]), async {
            auth.add_role("user:2");
            auth.role_names()
        })
        .await;
    assert_eq!(inside, ["user:1", "user:2"]);
    assert_eq!(auth.role_names(), ["any"]);
}

#[test]
fn test_a_clone_is_scoped_independently() {
    let auth = Authorization::default();
    auth.add_role("user:1");
    let copy = auth.clone();
    copy.add_role("user:2");
    copy.disable();
    assert_eq!(auth.role_names(), ["any", "user:1"]);
    assert!(auth.status());
    assert_eq!(copy.role_names(), ["any", "user:1", "user:2"]);
}

// Authorization/FluentTest

#[test]
fn test_role_setters_chain_on_the_same_instance() {
    let auth = Authorization::default();
    let user = Role::user("123", "").to_string();
    let team = Role::team("abc", "").to_string();
    let chained = auth.clean_roles().add_role(&user).add_role(&team).remove_role(&team);
    assert!(std::ptr::eq(chained, &auth));
    assert_eq!(auth.role_names(), [user]);
}

#[test]
fn test_status_setters_chain_on_the_same_instance() {
    let auth = Authorization::default();
    assert!(std::ptr::eq(auth.disable(), &auth));
    assert!(!auth.status());
    assert!(std::ptr::eq(auth.enable(), &auth));
    assert!(auth.status());
    assert!(std::ptr::eq(auth.set_status(false), &auth));
    assert!(!auth.status());
}

#[test]
fn test_the_default_status_is_true() {
    let auth = Authorization::default();
    assert!(auth.status());
    auth.disable().reset();
    assert!(auth.status());
}

#[test]
fn test_a_disabled_default_status_skips_checks_until_enabled() {
    let auth = Authorization::new(false);
    auth.clean_roles();
    let i = Input::new(PermissionType::Read, vec![Role::user("123", "").to_string()]);
    assert!(!auth.status());
    assert!(auth.is_valid(&i));
    auth.enable();
    assert!(!auth.is_valid(&i));
    auth.reset();
    assert!(auth.is_valid(&i));
}

#[test]
fn test_skip_lifts_the_check_only_inside_the_callback() {
    let auth = Authorization::default();
    auth.clean_roles();
    let i = Input::new(PermissionType::Delete, vec![Role::user("123", "").to_string()]);
    assert!(auth.skip_sync(|| auth.is_valid(&i)));
    assert!(!auth.is_valid(&i));
}

#[test]
fn test_input_takes_the_permission_type() {
    let mut i = input(PermissionType::Update, &["any"]);
    assert_eq!(i.action, PermissionType::Update.as_str());
    assert_eq!(i.permissions, ["any"]);
    i.set_action(PermissionType::Create).set_permissions(strings(&["users"]));
    assert_eq!(i.action, PermissionType::Create.as_str());
    assert_eq!(i.permissions, ["users"]);
}

#[test]
fn test_a_missing_permission_names_the_action() {
    let auth = Authorization::default();
    auth.clean_roles();
    assert!(!auth.is_valid(&Input::new(PermissionType::Update, vec![Role::user("123", "").to_string()])));
    assert!(auth.description().contains("\"update\""));
}

// Validator/AuthorizationTest

#[test]
fn test_values() {
    let auth = Authorization::default();
    auth.add_role(&Role::any().to_string());
    let document = Document::from_pairs([
        ("$id", Value::from(id::unique().unwrap().as_str())),
        ("$collection", Value::from(id::unique().unwrap().as_str())),
        (
            "$permissions",
            Value::Array(
                [
                    Permission::read(&Role::user(id::custom("123"), "")),
                    Permission::read(&Role::team(id::custom("123"), "")),
                    Permission::create(&Role::any()),
                    Permission::update(&Role::any()),
                    Permission::delete(&Role::any()),
                ]
                .into_iter()
                .map(|p| Value::from(p.as_str()))
                .collect(),
            ),
        ),
    ])
    .unwrap();
    let read = || Input::new(PermissionType::Read, document.roles(PermissionType::Read).unwrap());

    assert!(!auth.is_valid(&read()));
    assert!(!auth.is_valid(&Input::new(PermissionType::Read, vec![])));
    assert_eq!(auth.description(), "No permissions provided for action 'read'");

    auth.add_role(&Role::user("456", "").to_string());
    auth.add_role(&Role::user("123", "").to_string());
    assert!(auth.has_role(&Role::user("456", "").to_string()));
    assert!(!auth.has_role(&Role::user("457", "").to_string()));
    assert!(!auth.has_role(""));
    assert!(auth.has_role(&Role::any().to_string()));
    assert!(auth.is_valid(&read()));

    auth.clean_roles();
    assert!(!auth.is_valid(&read()));
    auth.add_role(&Role::team("123", "").to_string());
    assert!(auth.is_valid(&read()));

    auth.clean_roles();
    auth.disable();
    assert!(auth.is_valid(&read()));
    auth.reset();
    assert!(!auth.is_valid(&read()));

    let auth = Authorization::new(false);
    auth.clean_roles();
    assert!(!auth.status());
    auth.disable();
    assert!(auth.is_valid(&read()));
    auth.reset();
    assert!(auth.is_valid(&read()));
    auth.enable();
    assert!(!auth.is_valid(&read()));

    auth.add_role("textX");
    assert!(auth.role_names().contains(&"textX".to_owned()));
    auth.remove_role("textX");
    assert!(!auth.role_names().contains(&"textX".to_owned()));

    assert!(!auth.is_valid(&read()));
    assert!(auth.skip_sync(|| auth.is_valid(&read())));
}

#[test]
fn test_nested_skips() {
    let auth = Authorization::default();
    assert!(auth.status());
    auth.skip_sync(|| {
        assert!(!auth.status());
        auth.skip_sync(|| {
            assert!(!auth.status());
            auth.skip_sync(|| assert!(!auth.status()));
            assert!(!auth.status());
        });
        assert!(!auth.status());
    });
    assert!(auth.status());
}

#[test]
fn test_custom_action_granted() {
    let auth = Authorization::default();
    auth.add_role(&Role::user(id::custom("123"), "").to_string());
    assert!(auth.is_valid(&Input::new("execute", vec![Role::user(id::custom("123"), "").to_string()])));
    assert!(auth.is_valid(&Input::new("subscribe", vec![Role::any().to_string()])));
}

#[test]
fn test_custom_action_denied() {
    let auth = Authorization::default();
    let i = Input::new("execute", vec![Role::user(id::custom("456"), "").to_string()]);
    assert_eq!(i.action, "execute");
    assert!(!auth.is_valid(&i));
    assert!(auth.description().contains("Missing \"execute\" permission"));
    assert!(!auth.is_valid(&Input::new("execute", vec![])));
    assert_eq!(auth.description(), "No permissions provided for action 'execute'");
}

#[test]
fn test_set_action_accepts_custom_string_and_enum() {
    let mut i = Input::new(PermissionType::Read, vec![]);
    assert_eq!(i.set_action("subscribe").action, "subscribe");
    assert_eq!(i.set_action(PermissionType::Update).action, "update");
}
