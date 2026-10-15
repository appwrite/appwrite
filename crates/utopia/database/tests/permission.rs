//! Ported from utopia-php/database 8.1.3 `tests/unit/PermissionTest.php`.

mod common;

use common::assert_error;
use utopia_database::{Kind, Permission, PermissionType, Role, id};

#[track_caller]
fn parsed(permission: &str, kind: &str, role: &str, identifier: &str, dimension: &str) {
    let p = Permission::parse(permission).unwrap();
    assert_eq!(p.permission().as_str(), kind);
    assert_eq!(p.role().name().as_str(), role);
    assert_eq!(p.role().identifier(), identifier);
    assert_eq!(p.role().dimension(), dimension);
}

#[test]
fn test_output_from_string() {
    for kind in ["read", "create", "update", "delete"] {
        parsed(&format!("{kind}(\"any\")"), kind, "any", "", "");
        parsed(&format!("{kind}(\"users\")"), kind, "users", "", "");
        parsed(&format!("{kind}(\"user:123\")"), kind, "user", "123", "");
        parsed(&format!("{kind}(\"team:123/admin\")"), kind, "team", "123", "admin");
        parsed(&format!("{kind}(\"guests\")"), kind, "guests", "", "");
    }
    parsed("read(\"users/verified\")", "read", "users", "", "verified");
    parsed("read(\"users/unverified\")", "read", "users", "", "unverified");
}

#[test]
fn test_input_from_parameters() {
    for kind in ["read", "create", "update", "delete"] {
        let t = PermissionType::from_name(kind).unwrap();
        assert_eq!(Permission::new(t, Role::named("any", "", "")).to_string(), format!("{kind}(\"any\")"));
        assert_eq!(Permission::new(t, Role::named("users", "", "")).to_string(), format!("{kind}(\"users\")"));
        assert_eq!(Permission::new(t, Role::named("user", "123", "")).to_string(), format!("{kind}(\"user:123\")"));
        assert_eq!(
            Permission::new(t, Role::named("team", "123", "admin")).to_string(),
            format!("{kind}(\"team:123/admin\")")
        );
    }
}

#[test]
fn test_input_from_roles() {
    type Make = fn(&Role) -> String;
    let makers: [(&str, Make); 4] = [
        ("read", Permission::read),
        ("create", Permission::create),
        ("update", Permission::update),
        ("delete", Permission::delete),
    ];
    for (kind, make) in makers {
        assert_eq!(make(&Role::any()), format!("{kind}(\"any\")"));
        assert_eq!(make(&Role::users("")), format!("{kind}(\"users\")"));
        assert_eq!(make(&Role::user(id::custom("123"), "")), format!("{kind}(\"user:123\")"));
        assert_eq!(make(&Role::team(id::custom("123"), "admin")), format!("{kind}(\"team:123/admin\")"));
        assert_eq!(make(&Role::guests()), format!("{kind}(\"guests\")"));
    }
    assert_eq!(Permission::write(&Role::any()), "write(\"any\")");
}

#[test]
fn test_invalid_formats() {
    assert_error(Permission::parse("read"), Kind::Database, "Invalid permission string format: \"read\".");
    assert_error(Permission::parse("read((\"any\")"), Kind::Database, "Invalid permission type: \"read(\".");
    assert_error(Permission::parse("read(\"users/un/verified\")"), Kind::Database, "Only one dimension can be provided");
    assert_error(Permission::parse("read(\"users/\")"), Kind::Database, "Dimension must not be empty");
}

#[test]
fn test_custom_type_rejected_as_in_7_4() {
    assert_error(Permission::parse("execute(\"any\")"), Kind::Database, "Invalid permission type: \"execute\".");
}

#[test]
fn test_aggregation() {
    let permissions = ["write(\"any\")"];
    assert_eq!(
        Permission::aggregate(&permissions, &PermissionType::ALL).unwrap(),
        ["create(\"any\")", "update(\"any\")", "delete(\"any\")"]
    );
    assert_eq!(
        Permission::aggregate(&permissions, &[PermissionType::Update, PermissionType::Delete]).unwrap(),
        ["update(\"any\")", "delete(\"any\")"]
    );

    let permissions = [
        "read(\"any\")",
        "read(\"user:123\")",
        "read(\"user:123\")",
        "write(\"user:123\")",
        "update(\"user:123\")",
        "delete(\"user:123\")",
    ];
    assert_eq!(
        Permission::aggregate(&permissions, &PermissionType::ALL).unwrap(),
        ["read(\"any\")", "read(\"user:123\")", "create(\"user:123\")", "update(\"user:123\")", "delete(\"user:123\")"]
    );
}
