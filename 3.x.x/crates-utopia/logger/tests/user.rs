use utopia_logger::User;

/// Port of `tests/unit/Log/UserTest.php::testLogUser`.
#[test]
fn test_log_user() {
    let user = User::new(None, None, None);

    assert_eq!(user.get_email(), None);
    assert_eq!(user.get_username(), None);
    assert_eq!(user.get_id(), None);

    let user = User::new(Some("618e291cd8949"), None, None);
    assert_eq!(user.get_id(), Some("618e291cd8949"));

    let user = User::new(None, Some("matej@appwrite.io"), None);
    assert_eq!(user.get_email(), Some("matej@appwrite.io"));

    let user = User::new(None, None, Some("Meldiron"));
    assert_eq!(user.get_username(), Some("Meldiron"));
}

#[test]
fn test_log_user_all_fields() {
    let user = User::new(Some("id"), Some("a@b.c"), Some("name"));
    assert_eq!(user.get_id(), Some("id"));
    assert_eq!(user.get_email(), Some("a@b.c"));
    assert_eq!(user.get_username(), Some("name"));
}
