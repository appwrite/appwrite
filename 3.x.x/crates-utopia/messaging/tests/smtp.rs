//! PHP `tests/Messaging/Adapter/Email/SMTPTest.php` (unit paths + SMTP catcher).

mod common;

use utopia_messaging::adapter::email::SMTP;
use utopia_messaging::messages::email::Attachment;
use utopia_messaging::messages::{Email, RecipientInput};
use utopia_messaging::{Adapter, SendResult};

fn message(attachments: Option<Vec<Attachment>>) -> Email {
    Email::new(
        vec!["tester@localhost.test".into()],
        "Test Subject",
        "Test Content",
        "Test Sender",
        "sender@localhost.test",
        None,
        None,
        None,
        None,
        attachments,
        false,
    )
    .unwrap()
}

#[test]
fn attachment_with_string_content() {
    let content = b"Hello, this is raw file content.";
    let attachment = Attachment::new("readme.txt", "", "text/plain", Some(content.to_vec()));
    assert_eq!(attachment.get_name(), "readme.txt");
    assert_eq!(attachment.get_path(), "");
    assert_eq!(attachment.get_type(), "text/plain");
    assert_eq!(attachment.get_content(), Some(content.as_slice()));
}

#[test]
fn attachment_without_string_content_defaults_to_none() {
    let attachment = Attachment::new("image.png", "/tmp/image.png", "image/png", None);
    assert!(attachment.get_content().is_none());
}

#[test]
fn smtp_constructor_with_keep_alive_and_timelimit() {
    let sender = SMTP::new("127.0.0.1", 11025, "", "", "", false, "", 30, true, 60).unwrap();
    assert_eq!(sender.get_name(), "SMTP");
}

#[test]
fn smtp_constructor_defaults_are_backwards_compatible() {
    let sender = SMTP::with_host_port("127.0.0.1", 11025).unwrap();
    assert_eq!(sender.get_name(), "SMTP");
}

#[test]
fn reports_why_a_server_could_not_be_reached() {
    let sender = SMTP::new("127.0.0.1", 1, "", "", "", false, "", 2, false, 2).unwrap();
    match sender.send(&message(None)).unwrap() {
        SendResult::Response(data) => {
            assert_eq!(data.delivered_to, 0);
            assert_eq!(data.results[0].recipient, "tester@localhost.test");
            assert!(data.results[0].error.contains("127.0.0.1:1"));
        }
        SendResult::Grouped(_) => panic!("expected SMTP response"),
    }
}

#[test]
fn live_send_email() {
    let (host, port) = common::smtp_target();
    let sender = SMTP::with_host_port(host, port).unwrap();
    match sender.send(&message(None)).unwrap() {
        SendResult::Response(data) => assert!(data.delivered_to >= 1),
        SendResult::Grouped(_) => panic!("expected SMTP response"),
    }
}

#[test]
fn live_send_email_with_attachment() {
    let path = format!("{}/assets/image.png", env!("CARGO_MANIFEST_DIR"));
    let (host, port) = common::smtp_target();
    let sender = SMTP::with_host_port(host, port).unwrap();
    let message = Email::new(
        vec!["tester@localhost.test".into()],
        "Test Subject",
        "Test Content",
        "Test Sender",
        "sender@localhost.test",
        None,
        None,
        None,
        None,
        Some(vec![Attachment::new("image.png", path, "image/png", None)]),
        false,
    )
    .unwrap();
    match sender.send(&message).unwrap() {
        SendResult::Response(data) => assert!(data.delivered_to >= 1),
        SendResult::Grouped(_) => panic!("expected SMTP response"),
    }
}

#[test]
fn live_send_email_only_bcc() {
    let (host, port) = common::smtp_target();
    let sender = SMTP::with_host_port(host, port).unwrap();
    let message = Email::new(
        Vec::<RecipientInput>::new(),
        "Test Subject",
        "Test Content",
        "Test Sender",
        "sender@localhost.test",
        None,
        None,
        None,
        Some(vec![RecipientInput::named(
            "tester2@localhost.test",
            "Test Recipient 2",
        )]),
        None,
        false,
    )
    .unwrap();
    match sender.send(&message).unwrap() {
        SendResult::Response(data) => assert!(data.delivered_to >= 1),
        SendResult::Grouped(_) => panic!("expected SMTP response"),
    }
}
