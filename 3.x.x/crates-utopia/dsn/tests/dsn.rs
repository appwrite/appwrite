//! Port of `tests/DSN/DSNTest.php` plus extra error-path coverage.

use std::fmt::Write as _;

use utopia_dsn::{Dsn, DsnError};

fn php_urlencode(input: &str) -> String {
    let mut out = String::new();
    for b in input.bytes() {
        if b == b' ' {
            out.push('+');
        } else if b.is_ascii_alphanumeric() || matches!(b, b'-' | b'_' | b'.') {
            out.push(char::from(b));
        } else {
            let _ = write!(out, "%{b:02X}");
        }
    }
    out
}

fn php_empty_opt(value: Option<&str>) -> bool {
    matches!(value, None | Some("" | "0"))
}

/// `DSNTest::testSuccess`
#[test]
fn test_success() {
    let dsn = Dsn::new("mariadb://user:password@localhost:3306/database?charset=utf8&timezone=UTC")
        .unwrap();
    assert_eq!(dsn.get_scheme(), "mariadb");
    assert_eq!(dsn.get_user(), Some("user"));
    assert_eq!(dsn.get_password(), Some("password"));
    assert_eq!(dsn.get_host(), "localhost");
    assert_eq!(dsn.get_port(), Some("3306"));
    assert_eq!(dsn.get_path(), "database");
    assert_eq!(dsn.get_query(), Some("charset=utf8&timezone=UTC"));
    assert_eq!(dsn.get_param("charset", ""), "utf8");
    assert_eq!(dsn.get_param("timezone", ""), "UTC");
    assert!(dsn.get_param("doesNotExist", "").is_empty());

    let dsn = Dsn::new("mariadb://user@localhost:3306/database?charset=utf8&timezone=UTC").unwrap();
    assert_eq!(dsn.get_scheme(), "mariadb");
    assert_eq!(dsn.get_user(), Some("user"));
    assert_eq!(dsn.get_password(), None);
    assert_eq!(dsn.get_host(), "localhost");
    assert_eq!(dsn.get_port(), Some("3306"));
    assert_eq!(dsn.get_path(), "database");
    assert_eq!(dsn.get_query(), Some("charset=utf8&timezone=UTC"));
    assert_eq!(dsn.get_param("charset", ""), "utf8");
    assert_eq!(dsn.get_param("timezone", ""), "UTC");
    assert!(dsn.get_param("doesNotExist", "").is_empty());

    let dsn = Dsn::new("mariadb://user@localhost/database?charset=utf8&timezone=UTC").unwrap();
    assert_eq!(dsn.get_scheme(), "mariadb");
    assert_eq!(dsn.get_user(), Some("user"));
    assert_eq!(dsn.get_password(), None);
    assert_eq!(dsn.get_host(), "localhost");
    assert_eq!(dsn.get_port(), None);
    assert_eq!(dsn.get_path(), "database");
    assert_eq!(dsn.get_query(), Some("charset=utf8&timezone=UTC"));
    assert_eq!(dsn.get_param("charset", ""), "utf8");
    assert_eq!(dsn.get_param("timezone", ""), "UTC");
    assert!(dsn.get_param("doesNotExist", "").is_empty());

    let dsn = Dsn::new("mariadb://user@localhost?charset=utf8&timezone=UTC").unwrap();
    assert_eq!(dsn.get_scheme(), "mariadb");
    assert_eq!(dsn.get_user(), Some("user"));
    assert_eq!(dsn.get_password(), None);
    assert_eq!(dsn.get_host(), "localhost");
    assert_eq!(dsn.get_port(), None);
    assert!(dsn.get_path().is_empty());
    assert_eq!(dsn.get_query(), Some("charset=utf8&timezone=UTC"));
    assert_eq!(dsn.get_param("charset", ""), "utf8");
    assert_eq!(dsn.get_param("timezone", ""), "UTC");
    assert!(dsn.get_param("doesNotExist", "").is_empty());

    let dsn = Dsn::new("mariadb://user@localhost").unwrap();
    assert_eq!(dsn.get_scheme(), "mariadb");
    assert_eq!(dsn.get_user(), Some("user"));
    assert_eq!(dsn.get_password(), None);
    assert_eq!(dsn.get_host(), "localhost");
    assert_eq!(dsn.get_port(), None);
    assert!(dsn.get_path().is_empty());
    assert_eq!(dsn.get_query(), None);

    let dsn = Dsn::new("mariadb://user:@localhost").unwrap();
    assert_eq!(dsn.get_scheme(), "mariadb");
    assert_eq!(dsn.get_user(), Some("user"));
    assert!(php_empty_opt(dsn.get_password()));
    assert_eq!(dsn.get_password(), Some(""));
    assert_eq!(dsn.get_host(), "localhost");
    assert_eq!(dsn.get_port(), None);
    assert!(dsn.get_path().is_empty());
    assert_eq!(dsn.get_query(), None);

    let dsn = Dsn::new("mariadb://localhost").unwrap();
    assert_eq!(dsn.get_scheme(), "mariadb");
    assert!(php_empty_opt(dsn.get_user()));
    assert!(php_empty_opt(dsn.get_password()));
    assert_eq!(dsn.get_host(), "localhost");
    assert!(php_empty_opt(dsn.get_port()));
    assert!(dsn.get_path().is_empty());
    assert!(php_empty_opt(dsn.get_query()));

    let dsn = Dsn::new("mysql://localhost:3306").unwrap();
    assert_eq!(dsn.get_scheme(), "mysql");
    assert!(php_empty_opt(dsn.get_user()));
    assert!(php_empty_opt(dsn.get_password()));
    assert_eq!(dsn.get_host(), "localhost");
    // PHP `assertEquals(3306, $dsn->getPort())` is loose-equal to the string `"3306"`.
    assert_eq!(dsn.get_port(), Some("3306"));
    assert!(dsn.get_path().is_empty());
    assert!(php_empty_opt(dsn.get_query()));

    let dsn = Dsn::new("s3://user:secret@host:3306/bucket?region=us-east-1").unwrap();
    assert_eq!(dsn.get_scheme(), "s3");
    assert_eq!(dsn.get_user(), Some("user"));
    assert_eq!(dsn.get_password(), Some("secret"));
    assert_eq!(dsn.get_host(), "host");
    assert_eq!(dsn.get_port(), Some("3306"));
    assert_eq!(dsn.get_path(), "bucket");
    assert_eq!(dsn.get_query(), Some("region=us-east-1"));
    assert_eq!(dsn.get_param("region", ""), "us-east-1");
    assert!(dsn.get_param("doesNotExist", "").is_empty());

    let password = "sl/sh+$@no:her";
    let encoded = php_urlencode(password);
    let dsn = Dsn::new(format!("sms://user:{encoded}@localhost")).unwrap();
    assert_eq!(dsn.get_scheme(), "sms");
    assert_eq!(dsn.get_user(), Some("user"));
    assert_eq!(dsn.get_password(), Some(password));
    assert_eq!(dsn.get_host(), "localhost");
    assert_eq!(dsn.get_port(), None);
    assert!(dsn.get_path().is_empty());
    assert_eq!(dsn.get_query(), None);

    let user = "admin@example.com";
    let encoded = php_urlencode(user);
    let dsn = Dsn::new(format!("sms://{encoded}@localhost")).unwrap();
    assert_eq!(dsn.get_scheme(), "sms");
    assert_eq!(dsn.get_user(), Some(user));
    assert_eq!(dsn.get_password(), None);
    assert_eq!(dsn.get_host(), "localhost");
    assert_eq!(dsn.get_port(), None);
    assert!(dsn.get_path().is_empty());
    assert_eq!(dsn.get_query(), None);

    let value = r#"I am 100% value=<complex>, "right"?!"#;
    let encoded = php_urlencode(value);
    let dsn = Dsn::new(format!("sms://localhost?value={encoded}")).unwrap();
    assert_eq!(dsn.get_scheme(), "sms");
    assert_eq!(dsn.get_user(), None);
    assert_eq!(dsn.get_password(), None);
    assert_eq!(dsn.get_host(), "localhost");
    assert_eq!(dsn.get_port(), None);
    assert!(dsn.get_path().is_empty());
    let expected_query = format!("value={encoded}");
    assert_eq!(dsn.get_query(), Some(expected_query.as_str()));
    // Extra: PHP `getParam` decodes via `parse_str` (not asserted in DSNTest.php).
    assert_eq!(dsn.get_param("value", ""), value);
}

/// `DSNTest::testGetParam`
#[test]
fn test_get_param() {
    let dsn = Dsn::new("mariadb://user:password@localhost:3306/database?charset=utf8&timezone=UTC")
        .unwrap();
    assert_eq!(dsn.get_param("charset", ""), "utf8");
    assert_eq!(dsn.get_param("timezone", ""), "UTC");
    assert!(dsn.get_param("doesNotExist", "").is_empty());
    assert_eq!(dsn.get_param("region", "us-east-1"), "us-east-1");
    assert_eq!(dsn.get_param("region", "us-east-2"), "us-east-2");
}

/// `DSNTest::testFail` - `mariadb://` is unparseable (PHP `parse_url` returns `false`).
#[test]
fn test_fail() {
    let err = Dsn::new("mariadb://").unwrap_err();
    assert!(matches!(
        err,
        DsnError::InvalidArgument(msg) if msg == "Unable to parse DSN: mariadb://"
    ));
}

#[test]
fn test_fail_scheme_required() {
    let err = Dsn::new("localhost").unwrap_err();
    assert!(matches!(
        err,
        DsnError::InvalidArgument(msg) if msg == "Unable to parse DSN: scheme is required"
    ));

    let err = Dsn::new("//localhost").unwrap_err();
    assert!(matches!(
        err,
        DsnError::InvalidArgument(msg) if msg == "Unable to parse DSN: scheme is required"
    ));
}

#[test]
fn test_fail_host_required() {
    let err = Dsn::new("mariadb:database").unwrap_err();
    assert!(matches!(
        err,
        DsnError::InvalidArgument(msg) if msg == "Unable to parse DSN: host is required"
    ));
}

#[test]
fn test_fail_invalid_port() {
    let err = Dsn::new("mariadb://localhost:99999").unwrap_err();
    assert!(matches!(
        err,
        DsnError::InvalidArgument(msg) if msg == "Unable to parse DSN: mariadb://localhost:99999"
    ));
}

#[test]
fn php_class_alias_dsn() {
    let _dsn: utopia_dsn::DSN =
        Dsn::new("mariadb://localhost").expect("alias constructs the same type");
}
