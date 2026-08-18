//! PHP `tests/Messaging/Adapter/Email/MimeTest.php`.

use utopia_messaging::adapter::email::Mime;
use utopia_messaging::messages::email::Attachment;
use utopia_messaging::messages::{Email, RecipientInput};

fn email(
    content: &str,
    html: bool,
    attachments: Vec<Attachment>,
    cc: Vec<RecipientInput>,
    subject: &str,
) -> Email {
    Email::new(
        vec![RecipientInput::named("john@example.test", "John Doe")],
        subject,
        content,
        "Jane Doe",
        "jane@example.test",
        None,
        None,
        if cc.is_empty() { None } else { Some(cc) },
        None,
        if attachments.is_empty() {
            None
        } else {
            Some(attachments)
        },
        html,
    )
    .unwrap()
}

fn render(email: &Email) -> String {
    Mime::message(
        email,
        email.get_to(),
        email.get_cc().unwrap_or(&[]),
        &[],
        &[],
    )
    .to_string()
}

fn qp_decode(input: &str) -> String {
    let mut out = Vec::new();
    let bytes = input.as_bytes();
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'=' {
            if i + 1 < bytes.len() && bytes[i + 1] == b'\r' {
                i += 2;
                if i < bytes.len() && bytes[i] == b'\n' {
                    i += 1;
                }
                continue;
            }
            if i + 2 < bytes.len() {
                let hex = std::str::from_utf8(&bytes[i + 1..i + 3]).unwrap_or("00");
                if let Ok(v) = u8::from_str_radix(hex, 16) {
                    out.push(v);
                    i += 3;
                    continue;
                }
            }
        }
        out.push(bytes[i]);
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

#[test]
fn carries_the_addresses_and_subject() {
    let rendered = render(&email(
        "Plain body",
        false,
        vec![],
        vec![RecipientInput::email_only("ada@example.test")],
        "Test Subject",
    ));
    assert!(rendered.contains(r#"From: "Jane Doe" <jane@example.test>"#));
    assert!(rendered.contains(r#"To: "John Doe" <john@example.test>"#));
    assert!(rendered.contains("Cc: <ada@example.test>"));
    assert!(rendered.contains("Subject: Test Subject"));
    assert!(rendered.contains("MIME-Version: 1.0"));
}

#[test]
fn plain_text_is_a_single_part() {
    let rendered = render(&email("Plain body", false, vec![], vec![], "Test Subject"));
    assert!(rendered.contains("Content-Type: text/plain; charset=utf-8"));
    assert!(!rendered.contains("multipart"));
}

#[test]
fn markup_brings_a_plain_alternative() {
    let rendered = render(&email(
        "<style>p{color:red}</style><p>Hello <b>world</b></p>",
        true,
        vec![],
        vec![],
        "Test Subject",
    ));
    assert!(rendered.contains("Content-Type: multipart/alternative;"));
    assert!(rendered.contains("Content-Type: text/html; charset=utf-8"));
    let parts: Vec<&str> = rendered.split("Content-Type: text/").collect();
    let rest = &parts[1..];
    let plain = qp_decode(rest[0]);
    let markup = qp_decode(rest[1]);
    assert!(plain.contains("Hello world"));
    assert!(!plain.contains("color:red"));
    assert!(markup.contains("color:red"));
}

#[test]
fn an_attachment_is_wrapped_around_the_body() {
    let rendered = render(&email(
        "Plain body",
        false,
        vec![Attachment::new(
            "notes.txt",
            "",
            "text/plain",
            Some(b"the notes".to_vec()),
        )],
        vec![],
        "Test Subject",
    ));
    assert!(rendered.contains("Content-Type: multipart/mixed;"));
    assert!(rendered.contains(r#"Content-Disposition: attachment; filename="notes.txt""#));
    assert!(rendered.contains("Content-Transfer-Encoding: base64"));
    use base64::engine::general_purpose::STANDARD;
    use base64::Engine;
    assert!(rendered.contains(&STANDARD.encode("the notes")));
}

#[test]
fn a_non_ascii_subject_is_encoded() {
    let rendered = render(&email(
        "Plain body",
        false,
        vec![],
        vec![],
        "Quarterly résumé",
    ));
    use base64::engine::general_purpose::STANDARD;
    use base64::Engine;
    assert!(rendered.contains(&format!(
        "Subject: =?UTF-8?B?{}",
        STANDARD.encode("Quarterly résumé")
    )));
}

#[test]
fn no_line_is_longer_than_the_standard_allows() {
    let rendered = render(&email(
        &"a long line of text that will need breaking up ".repeat(40),
        false,
        vec![Attachment::new(
            "notes.txt",
            "",
            "text/plain",
            Some(vec![7u8; 4096]),
        )],
        vec![],
        "Test Subject",
    ));
    for line in rendered.split("\r\n") {
        assert!(line.len() <= 998, "line too long: {}", line.len());
    }
}

#[test]
fn blind_recipients_never_reach_the_headers() {
    let email = Email::new(
        vec!["john@example.test".into()],
        "Test Subject",
        "Plain body",
        "Jane Doe",
        "jane@example.test",
        None,
        None,
        None,
        Some(vec!["eve@example.test".into()]),
        None,
        false,
    )
    .unwrap();
    let rendered = Mime::message(
        &email,
        email.get_to(),
        &[],
        email.get_bcc().unwrap_or(&[]),
        &[],
    )
    .to_string();
    assert!(!rendered.contains("eve@example.test"));
    assert!(!rendered.contains("Bcc"));
}

#[test]
fn weighs_attachments_before_they_are_encoded() {
    assert_eq!(
        Mime::size(&email("Plain body", false, vec![], vec![], "Test Subject")).unwrap(),
        0
    );
    assert_eq!(
        Mime::size(&email(
            "Plain body",
            false,
            vec![Attachment::new(
                "notes.txt",
                "",
                "text/plain",
                Some(b"the notes".to_vec()),
            )],
            vec![],
            "Test Subject",
        ))
        .unwrap(),
        9
    );
}
