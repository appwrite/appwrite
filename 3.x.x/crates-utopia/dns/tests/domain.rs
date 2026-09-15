//! Port of `tests/unit/DNS/Message/DomainTest.php`.

use utopia_dns::error::Error;
use utopia_dns::message::Domain;

#[test]
fn encode_produces_expected_wire_format() {
    let encoded = Domain::encode("www.example.com").unwrap();
    assert_eq!(encoded, b"\x03www\x07example\x03com\x00");
}

#[test]
fn encode_treats_single_trailing_dot_as_absolute() {
    assert_eq!(
        Domain::encode("example.com").unwrap(),
        Domain::encode("example.com.").unwrap()
    );
}

#[test]
fn encode_allows_root_via_empty_string() {
    assert_eq!(Domain::encode("").unwrap(), b"\x00");
}

#[test]
fn encode_allows_root_via_dot() {
    assert_eq!(Domain::encode(".").unwrap(), b"\x00");
}

#[test]
fn decode_simple_domain() {
    let data = b"\x03www\x07example\x03com\x00";
    let mut offset = 0;
    let decoded = Domain::decode(data, &mut offset).unwrap();
    assert_eq!(decoded, "www.example.com");
    assert_eq!(offset, data.len());
}

#[test]
fn decode_root_domain() {
    let data = b"\x00";
    let mut offset = 0;
    let decoded = Domain::decode(data, &mut offset).unwrap();
    assert_eq!(decoded, "");
    assert_eq!(offset, 1);
}

#[test]
fn decode_compression_pointer() {
    let first = b"\x05first\x07example\x03com\x00";
    let pointer = b"\xC0\x00";
    let mut data = first.to_vec();
    data.extend_from_slice(pointer);
    let mut offset = 0;
    let decoded = Domain::decode(&data, &mut offset).unwrap();
    assert_eq!(decoded, "first.example.com");
    assert_eq!(offset, first.len());
    let decoded_pointer = Domain::decode(&data, &mut offset).unwrap();
    assert_eq!(decoded_pointer, "first.example.com");
    assert_eq!(offset, first.len() + pointer.len());
}

#[test]
fn decode_pointer_loop_raises_exception() {
    let data = b"\xC0\x00";
    let mut offset = 0;
    let err = Domain::decode(data, &mut offset).unwrap_err();
    assert!(matches!(err, Error::Decoding(_)));
    assert!(err
        .to_string()
        .contains("Compression pointer must reference earlier position"));
}

#[test]
fn decode_forward_pointer_raises_exception() {
    let data = b"\xC0\x05\x03www\x00";
    let mut offset = 0;
    let err = Domain::decode(data, &mut offset).unwrap_err();
    assert!(err
        .to_string()
        .contains("Compression pointer must reference earlier position"));
}

#[test]
fn decode_pointer_cycle_prevented_by_forward_check() {
    let data = b"\xC0\x04\x00\x00\xC0\x00";
    let mut offset = 0;
    let err = Domain::decode(data, &mut offset).unwrap_err();
    assert!(err
        .to_string()
        .contains("Compression pointer must reference earlier position"));
}

#[test]
fn decode_revisited_pointer_raises_exception() {
    let data = b"\x01a\xC0\x00\x01b\x00";
    let mut offset = 2;
    let err = Domain::decode(data, &mut offset).unwrap_err();
    assert!(err
        .to_string()
        .contains("Compression pointer loop detected"));
}

#[test]
fn decode_truncated_pointer_raises_exception() {
    let data = b"\xC0";
    let mut offset = 0;
    let err = Domain::decode(data, &mut offset).unwrap_err();
    assert!(err.to_string().contains("Truncated compression pointer"));
}

#[test]
fn encode_rejects_invalid_domains() {
    let long_label = "a".repeat(Domain::MAX_LABEL_LEN + 1);
    let too_many_labels = vec!["a"; Domain::MAX_LABELS + 1].join(".");
    let max_label = "a".repeat(Domain::MAX_LABEL_LEN);
    let over_length = [max_label.as_str(); 4].join(".");
    let cases = [
        ("www..example.com", "Domain labels must not be empty"),
        ("example..", "Domain labels must not be empty"),
        ("example.com..", "Domain labels must not be empty"),
        ("@", "Domain label contains invalid characters"),
        (
            &format!("{long_label}.com"),
            &format!("Label too long: {long_label}"),
        ),
        (
            &too_many_labels,
            &format!("Domain has too many labels: {}", Domain::MAX_LABELS + 1),
        ),
        (
            &over_length,
            &format!(
                "Encoded domain exceeds maximum length of {} bytes",
                Domain::MAX_DOMAIN_NAME_LEN
            ),
        ),
    ];
    for (domain, expected) in cases {
        let err = Domain::encode(domain).unwrap_err();
        assert_eq!(err.to_string(), expected, "domain={domain}");
    }
}
