//! Port of `tests/Unit/InboxTest.php`.

use utopia_nats::Inbox;

#[test]
fn test_create_with_default_prefix() {
    let inbox = Inbox::create();
    assert!(inbox.starts_with("_INBOX."));
    assert_eq!(inbox.len(), 29);
}

#[test]
fn test_create_with_custom_prefix() {
    let inbox = Inbox::with_prefix("MY_INBOX");
    assert!(inbox.starts_with("MY_INBOX."));
}

#[test]
fn test_create_unique() {
    let inbox1 = Inbox::create();
    let inbox2 = Inbox::create();
    assert_ne!(inbox1, inbox2);
}

#[test]
fn test_generate_id() {
    let id = Inbox::generate_id();
    assert_eq!(id.len(), 22);
    assert!(id.chars().all(|c| c.is_ascii_alphanumeric()));
}
