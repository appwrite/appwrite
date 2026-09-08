//! Port of `tests/e2e/DNS/Validator/{CAA,Name}Test.php`.

use serde_json::json;
use utopia_dns::message::Record;
use utopia_dns::validator::{Name, CAA};
use utopia_validators::Validator;

#[test]
fn caa_valid() {
    let validator = CAA::new();
    for value in [
        "0 issue \"letsencrypt.org\"",
        "128 issuewild \"certainly.com;account=123456;validationmethods=dns-01\"",
        "0 issuewild \"certainly.com\"",
        "0 iodef \"mailto:security@example.com\"",
        "0 issue \";\"",
        "0 issue \"certainly.com; validationmethods=dns-01\"",
    ] {
        assert!(validator.is_valid(&json!(value)), "expected valid: {value}");
    }
}

#[test]
fn caa_invalid() {
    let validator = CAA::new();
    let cases = [
        (
            "issue \"letsencrypt.org\"",
            CAA::FAILURE_REASON_INVALID_FORMAT,
        ),
        ("0 \"\"", CAA::FAILURE_REASON_INVALID_FORMAT),
        (
            "256 issue \"letsencrypt.org\"",
            CAA::FAILURE_REASON_INVALID_FLAGS,
        ),
        ("0 issue letsencrypt.org", CAA::FAILURE_REASON_INVALID_VALUE),
        ("0 issue \"\"", CAA::FAILURE_REASON_INVALID_VALUE),
    ];
    for (value, description) in cases {
        assert!(
            !validator.is_valid(&json!(value)),
            "expected invalid: {value}"
        );
        assert_eq!(validator.description(), description);
    }
}

#[test]
fn name_valid() {
    let validator = Name::new(Some(Record::TYPE_CNAME));
    let long = format!("{}.com", "a".repeat(63));
    for value in [
        "@",
        "example",
        "example.com",
        "EXAMPLE.COM",
        "a-b.com",
        "a123.example-domain.org",
        "xn--d1acufc.xn--p1ai",
        "123.com",
        "example.com.",
        long.as_str(),
        "*",
        "*.",
        "*.example.com",
        "*.example.com.",
        "_dmarc",
        "_acme-challenge",
        "selector1._domainkey",
        "mail._domainkey.example.com",
        "exa_mple.com",
    ] {
        assert!(validator.is_valid(&json!(value)), "expected valid: {value}");
    }
    let validator = Name::new(Some(Record::TYPE_SRV));
    assert!(validator.is_valid(&json!("example._tcp.com")));
    let validator = Name::new(None);
    assert!(validator.is_valid(&json!("selector1._domainkey")));
    assert!(validator.is_valid(&json!("*.example.com")));
    let validator = Name::new(Some(Record::TYPE_A));
    assert!(validator.is_valid(&json!("*")));
    assert!(validator.is_valid(&json!("*.example.com")));
    assert!(!validator.is_valid(&json!("_dmarc")));
}

#[test]
fn name_invalid() {
    let validator = Name::new(Some(Record::TYPE_A));
    let too_long_name = format!("{}.com", "a".repeat(256));
    let too_long_label = format!("{}.com", "a".repeat(64));
    let cases: Vec<(serde_json::Value, &str)> = vec![
        (json!(123), Name::FAILURE_REASON_GENERAL),
        (json!(""), Name::FAILURE_REASON_INVALID_NAME_LENGTH),
        (
            json!(too_long_name),
            Name::FAILURE_REASON_INVALID_NAME_LENGTH,
        ),
        (
            json!(too_long_label),
            Name::FAILURE_REASON_INVALID_LABEL_LENGTH,
        ),
        (
            json!("@.com"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE,
        ),
        (
            json!("-example.com"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE,
        ),
        (
            json!("example-.com"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE,
        ),
        (
            json!("exa_mple.com"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE,
        ),
        (
            json!("example..com"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE,
        ),
        (
            json!(".example.com"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE,
        ),
        (
            json!("example.com.."),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE,
        ),
        (
            json!("exa mple.com"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE,
        ),
    ];
    for (value, description) in cases {
        assert!(!validator.is_valid(&value), "expected invalid: {value}");
        assert_eq!(validator.description(), description);
    }

    let validator = Name::new(Some(Record::TYPE_TXT));
    let too_long_name = format!("{}.com", "a".repeat(256));
    let too_long_label = format!("{}.com", "a".repeat(64));
    let cases: Vec<(serde_json::Value, &str)> = vec![
        (json!(123), Name::FAILURE_REASON_GENERAL),
        (json!(""), Name::FAILURE_REASON_INVALID_NAME_LENGTH),
        (
            json!(too_long_name),
            Name::FAILURE_REASON_INVALID_NAME_LENGTH,
        ),
        (
            json!(too_long_label),
            Name::FAILURE_REASON_INVALID_LABEL_LENGTH,
        ),
        (
            json!("@.com"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE,
        ),
        (
            json!("-example.com"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE,
        ),
        (
            json!("example-.com"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE,
        ),
        (
            json!("example..com"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE,
        ),
        (
            json!(".example.com"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE,
        ),
        (
            json!("example.com.."),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE,
        ),
        (
            json!("exa mple.com"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE,
        ),
        (
            json!("google console"),
            Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE,
        ),
    ];
    for (value, description) in cases {
        assert!(!validator.is_valid(&value), "expected invalid: {value}");
        assert_eq!(validator.description(), description);
    }
}

#[test]
fn name_invalid_wildcard() {
    let validator = Name::new(Some(Record::TYPE_CNAME));
    for value in [
        "foo.*.com",
        "foo.*",
        "*foo.com",
        "f*o.com",
        "*a",
        "a*",
        "**",
        "*.*.example.com",
    ] {
        assert!(
            !validator.is_valid(&json!(value)),
            "expected invalid: {value}"
        );
        assert_eq!(
            validator.description(),
            Name::FAILURE_REASON_INVALID_WILDCARD
        );
    }
    assert!(!validator.is_valid(&json!("*..com")));
    assert_eq!(
        validator.description(),
        Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE
    );
}
