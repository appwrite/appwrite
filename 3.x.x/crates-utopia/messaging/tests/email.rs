//! PHP `tests/Messaging/Adapter/Email/EmailTest.php` (normalization + SMTP catcher).

mod common;

use utopia_messaging::adapter::email::Mock;
use utopia_messaging::messages::{Email, RecipientInput};
use utopia_messaging::{Adapter, SendResult};

fn sample(
    to: Vec<RecipientInput>,
    cc: Option<Vec<RecipientInput>>,
    bcc: Option<Vec<RecipientInput>>,
) -> Result<Email, utopia_messaging::MessagingError> {
    Email::new(
        to,
        "Test Subject",
        "Test Content",
        "Test Sender",
        "sender@localhost.test",
        None,
        None,
        cc,
        bcc,
        None,
        false,
    )
}

#[test]
fn mixed_to_formats_are_normalized() {
    let message = sample(
        vec![
            "plain@localhost.test".into(),
            RecipientInput::named("named@localhost.test", "Named User"),
        ],
        None,
        None,
    )
    .unwrap();
    let to = message.get_to();
    assert_eq!(to[0].email, "plain@localhost.test");
    assert_eq!(to[0].name, None);
    assert_eq!(to[1].email, "named@localhost.test");
    assert_eq!(to[1].name.as_deref(), Some("Named User"));
}

#[test]
fn cc_accepts_plain_strings() {
    let message = sample(
        vec!["tester@localhost.test".into()],
        Some(vec!["cc@localhost.test".into()]),
        None,
    )
    .unwrap();
    let cc = message.get_cc().unwrap();
    assert_eq!(cc[0].email, "cc@localhost.test");
}

#[test]
fn bcc_accepts_plain_strings() {
    let message = sample(
        vec!["tester@localhost.test".into()],
        None,
        Some(vec!["bcc@localhost.test".into()]),
    )
    .unwrap();
    let bcc = message.get_bcc().unwrap();
    assert_eq!(bcc[0].email, "bcc@localhost.test");
}

#[test]
fn rejects_empty_email_string() {
    let err = sample(vec!["".into()], None, None).unwrap_err();
    assert!(err
        .to_string()
        .contains("Recipient email must not be empty."));
}

#[test]
fn rejects_empty_email_in_array() {
    let err = sample(vec![RecipientInput::named("", "Ghost")], None, None).unwrap_err();
    assert!(err
        .to_string()
        .contains("Each recipient must have a non-empty \"email\" key."));
}

#[test]
fn rejects_missing_email_key() {
    let err = sample(
        vec![RecipientInput::Named {
            email: String::new(),
            name: Some("No Email".into()),
        }],
        None,
        None,
    )
    .unwrap_err();
    assert!(err
        .to_string()
        .contains("Each recipient must have a non-empty \"email\" key."));
}

#[test]
fn rejects_empty_email_in_cc() {
    let err = sample(
        vec!["valid@localhost.test".into()],
        Some(vec!["".into()]),
        None,
    )
    .unwrap_err();
    assert!(err
        .to_string()
        .contains("Recipient email must not be empty."));
}

#[test]
fn live_mock_email() {
    let (host, port) = common::smtp_target();
    let sender = Mock::new(host, port).unwrap();
    let message = sample(
        vec!["tester@localhost.test".into()],
        Some(vec![RecipientInput::email_only("tester2@localhost.test")]),
        Some(vec![RecipientInput::named(
            "tester3@localhost.test",
            "Tester3",
        )]),
    )
    .unwrap();
    match sender.send(&message).unwrap() {
        SendResult::Response(data) => assert_eq!(data.delivered_to, 3),
        SendResult::Grouped(_) => panic!("expected email response"),
    }
}
