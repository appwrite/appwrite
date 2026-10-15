//! Ported from utopia-php/database 8.1.3 `tests/unit/RoleTest.php`.

mod common;

use common::assert_error;
use utopia_database::role::RoleName;
use utopia_database::{Kind, Role, id};

#[track_caller]
fn parsed(role: &str, name: &str, identifier: &str, dimension: &str) {
    let r = Role::parse(role).unwrap();
    assert_eq!(r.name().as_str(), name);
    assert_eq!(r.identifier(), identifier);
    assert_eq!(r.dimension(), dimension);
}

#[test]
fn test_output_from_string() {
    parsed("any", "any", "", "");
    parsed("guests", "guests", "", "");
    parsed("users", "users", "", "");
    parsed("user:123", "user", "123", "");
    parsed("team:123", "team", "123", "");
    parsed("team:123/456", "team", "123", "456");
    parsed("team:123/project-456-owner", "team", "123", "project-456-owner");
    parsed("team:123/project-456", "team", "123", "project-456");
    parsed("user:123/verified", "user", "123", "verified");
    parsed("users/verified", "users", "", "verified");
    parsed("label:vip", "label", "vip", "");
    assert_eq!(Role::parse("label:vip").unwrap().name(), &RoleName::Label);
}

#[test]
fn test_input_from_parameters() {
    assert_eq!(Role::named("any", "", "").to_string(), "any");
    assert_eq!(Role::named("guests", "", "").to_string(), "guests");
    assert_eq!(Role::named("users", "", "").to_string(), "users");
    assert_eq!(Role::named("user", "123", "").to_string(), "user:123");
    assert_eq!(Role::named("team", "123", "").to_string(), "team:123");
    assert_eq!(Role::named("team", "123", "456").to_string(), "team:123/456");
    assert_eq!(Role::named("team", "123", "project-456-owner").to_string(), "team:123/project-456-owner");
    assert_eq!(Role::named("team", "123", "project-456").to_string(), "team:123/project-456");
    assert_eq!(Role::named("label", "vip", "").to_string(), "label:vip");
}

#[test]
fn test_input_from_roles() {
    assert_eq!(Role::any().to_string(), "any");
    assert_eq!(Role::guests().to_string(), "guests");
    assert_eq!(Role::users("").to_string(), "users");
    assert_eq!(Role::user(id::custom("123"), "").to_string(), "user:123");
    assert_eq!(Role::team(id::custom("123"), "").to_string(), "team:123");
    assert_eq!(Role::team(id::custom("123"), "456").to_string(), "team:123/456");
    assert_eq!(Role::team(id::custom("123"), "project-456-owner").to_string(), "team:123/project-456-owner");
    assert_eq!(Role::team(id::custom("123"), "project-456").to_string(), "team:123/project-456");
    assert_eq!(Role::label("vip").to_string(), "label:vip");
}

#[test]
fn test_input_from_id() {
    assert_eq!(Role::user(id::custom("123"), "").to_string(), "user:123");
    assert_eq!(Role::team(id::custom("123"), "").to_string(), "team:123");
    assert_eq!(Role::team(id::custom("123"), "456").to_string(), "team:123/456");
}

#[test]
fn test_parse_rejects_malformed_dimensions() {
    for (role, message) in [
        ("team/a/b", "Only one dimension can be provided"),
        ("team/", "Dimension must not be empty"),
        ("team:abc/x/y", "Only one dimension can be provided"),
        ("team:abc/", "Dimension must not be empty"),
    ] {
        assert_error(Role::parse(role), Kind::Exception, message);
    }
}
