//! PHP `tests/Messaging/Adapter/Email/SMTPHostsTest.php`.

use utopia_messaging::adapter::email::{SMTPEncryption, SMTP};

fn hosts(smtp: &SMTP) -> Vec<(String, u16, SMTPEncryption)> {
    smtp.hosts()
}

#[test]
fn one_host_with_the_default_port() {
    let smtp = SMTP::with_host_port("smtp.example.com", 25).unwrap();
    assert_eq!(
        hosts(&smtp),
        vec![("smtp.example.com".into(), 25, SMTPEncryption::None)]
    );
}

#[test]
fn a_port_after_the_host() {
    let smtp = SMTP::new(
        "smtp.example.com:587",
        25,
        "",
        "",
        "",
        false,
        "",
        30,
        false,
        30,
    )
    .unwrap();
    assert_eq!(
        hosts(&smtp),
        vec![("smtp.example.com".into(), 587, SMTPEncryption::None)]
    );
}

#[test]
fn each_entry_carries_its_own_port_and_encryption() {
    let smtp = SMTP::new(
        "tls://smtp1.example.com:587;ssl://smtp2.example.com:465;smtp3.example.com",
        25,
        "",
        "",
        "",
        false,
        "",
        30,
        false,
        30,
    )
    .unwrap();
    assert_eq!(
        hosts(&smtp),
        vec![
            ("smtp1.example.com".into(), 587, SMTPEncryption::StartTls),
            ("smtp2.example.com".into(), 465, SMTPEncryption::Implicit),
            ("smtp3.example.com".into(), 25, SMTPEncryption::None),
        ]
    );
}

#[test]
fn an_address_literal_keeps_its_colons() {
    let smtp = SMTP::with_host_port("::1", 25).unwrap();
    assert_eq!(hosts(&smtp), vec![("::1".into(), 25, SMTPEncryption::None)]);
}

#[test]
fn a_bracketed_address_literal_may_carry_a_port() {
    let smtp = SMTP::new("[::1]:587", 25, "", "", "", false, "", 30, false, 30).unwrap();
    assert_eq!(
        hosts(&smtp),
        vec![("[::1]".into(), 587, SMTPEncryption::None)]
    );
}

#[test]
fn a_bracketed_address_literal_without_a_port() {
    let smtp = SMTP::with_host_port("[::1]", 25).unwrap();
    assert_eq!(
        hosts(&smtp),
        vec![("[::1]".into(), 25, SMTPEncryption::None)]
    );
}

#[test]
fn empty_entries_are_skipped() {
    let smtp = SMTP::new(
        "smtp.example.com;;",
        25,
        "",
        "",
        "",
        false,
        "",
        30,
        false,
        30,
    )
    .unwrap();
    assert_eq!(
        hosts(&smtp),
        vec![("smtp.example.com".into(), 25, SMTPEncryption::None)]
    );
}

#[test]
fn the_auto_tls_flag_decides_when_no_prefix_says_so() {
    let smtp = SMTP::new("smtp.example.com", 25, "", "", "", true, "", 30, false, 30).unwrap();
    assert_eq!(
        hosts(&smtp),
        vec![("smtp.example.com".into(), 25, SMTPEncryption::Opportunistic)]
    );
}

#[test]
fn invalid_secure_prefix_is_rejected() {
    let err = SMTP::new(
        "smtp.example.com",
        25,
        "",
        "",
        "foo",
        false,
        "",
        30,
        false,
        30,
    )
    .unwrap_err();
    assert!(err.to_string().contains("Invalid SMTP secure prefix"));
}
