//! Port of `tests/unit/DNS/Message/QuestionTest.php`.

use utopia_dns::message::{Question, Record};

#[test]
fn constructor_sets_name() {
    let question = Question::with_class("www.example.com", Record::TYPE_A, Record::CLASS_IN);
    assert_eq!(question.name, "www.example.com");
}

#[test]
fn constructor_sets_name_case_insensitive() {
    let question = Question::with_class("WWW.EXAMPLE.COM", Record::TYPE_A, Record::CLASS_IN);
    assert_eq!(question.name, "www.example.com");
}

#[test]
fn encode_produces_exact_bytes() {
    let question = Question::with_class("www.example.com", Record::TYPE_A, Record::CLASS_IN);
    let expected = b"\x03www\x07example\x03com\x00\x00\x01\x00\x01";
    assert_eq!(question.encode().unwrap(), expected);
}

#[test]
fn decode_parses_expected_fields() {
    let data = b"\x03api\x07example\x03com\x00\x00\x1C\x00\x01";
    let mut offset = 0;
    let question = Question::decode(data, &mut offset).unwrap();
    assert_eq!(question.name, "api.example.com");
    assert_eq!(question.type_code, Record::TYPE_AAAA);
    assert_eq!(question.class, Record::CLASS_IN);
    assert_eq!(offset, data.len());
}

#[test]
fn decode_handles_compression_pointer() {
    let mut offset = 0;
    let first = b"\x05first\x07example\x03com\x00\x00\x01\x00\x01";
    let pointer = b"\xC0\x00\x00\x1C\x00\x01";
    let mut message = first.to_vec();
    message.extend_from_slice(pointer);
    let parsed_first = Question::decode(&message, &mut offset).unwrap();
    assert_eq!(parsed_first.name, "first.example.com");
    assert_eq!(parsed_first.type_code, Record::TYPE_A);
    assert_eq!(parsed_first.class, Record::CLASS_IN);
    let parsed_second = Question::decode(&message, &mut offset).unwrap();
    assert_eq!(parsed_second.name, "first.example.com");
    assert_eq!(parsed_second.type_code, Record::TYPE_AAAA);
    assert_eq!(parsed_second.class, Record::CLASS_IN);
    assert_eq!(offset, message.len());
}

#[test]
fn constructor_trims_whitespace_from_name() {
    let question = Question::with_class("  www.example.com  ", Record::TYPE_A, Record::CLASS_IN);
    assert_eq!(question.name, "www.example.com");
}

#[test]
fn constructor_trims_tabs_and_newlines_from_name() {
    let question =
        Question::with_class("\t\nwww.example.com\r\n", Record::TYPE_A, Record::CLASS_IN);
    assert_eq!(question.name, "www.example.com");
}
