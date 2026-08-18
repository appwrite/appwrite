//! Port of `tests/unit/DNS/MessageTest.php`.

mod common;

use common::{header, query_id, rec, respond};
use utopia_dns::error::Error;
use utopia_dns::message::{Message, Question, Record};

fn standard_answer_packet() -> Vec<u8> {
    let mut v = b"\x1a\x2b\x81\x80\x00\x01\x00\x01\x00\x00\x00\x00".to_vec();
    v.extend(b"\x03www\x07example\x03com\x00\x00\x01\x00\x01");
    v.extend(b"\x03www\x07example\x03com\x00");
    v.extend(b"\x00\x01\x00\x01\x00\x00\x01\x2C\x00\x04\x5D\xB8\xD8\x22");
    v
}

#[test]
fn decode_parses_standard_answer() {
    let response = Message::decode(&standard_answer_packet()).unwrap();
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert!(!response.questions.is_empty());
    let question = &response.questions[0];
    assert_eq!(question.name, "www.example.com");
    assert_eq!(question.type_code, Record::TYPE_A);
    assert_eq!(question.class, Record::CLASS_IN);
    assert_eq!(response.answers.len(), 1);
    let answer = &response.answers[0];
    assert_eq!(answer.name, "www.example.com");
    assert_eq!(answer.type_code, Record::TYPE_A);
    assert_eq!(answer.class, Record::CLASS_IN);
    assert_eq!(answer.ttl, 300);
    assert_eq!(answer.rdata, "93.184.216.34");
    assert_eq!(answer.priority, None);
    assert_eq!(answer.weight, None);
    assert_eq!(answer.port, None);
    assert!(response.authority.is_empty());
    assert!(response.additional.is_empty());
}

#[test]
fn encode_produces_original_bytes() {
    let packet = standard_answer_packet();
    let response = Message::decode(&packet).unwrap();
    assert_eq!(response.encode(None).unwrap(), packet);
}

#[test]
fn constructor_throws_when_question_count_mismatch() {
    let h = header(0x1010, false, 0, false, false, true, false, 0, 2, 0, 0, 0);
    let q = Question::new("example.com", Record::TYPE_A);
    let err = Message::new(h, vec![q], vec![], vec![], vec![]).unwrap_err();
    assert_eq!(
        err.to_string(),
        "Invalid DNS response: question count mismatch"
    );
}

#[test]
fn constructor_throws_when_answer_count_mismatch() {
    let h = header(0x2020, false, 0, false, false, true, false, 0, 0, 1, 0, 0);
    let err = Message::new(h, vec![], vec![], vec![], vec![]).unwrap_err();
    assert_eq!(
        err.to_string(),
        "Invalid DNS response: answer count mismatch"
    );
}

#[test]
fn constructor_throws_when_authority_count_mismatch() {
    let h = header(0x3030, false, 0, false, false, true, false, 0, 0, 0, 1, 0);
    let err = Message::new(h, vec![], vec![], vec![], vec![]).unwrap_err();
    assert_eq!(
        err.to_string(),
        "Invalid DNS response: authority count mismatch"
    );
}

#[test]
fn constructor_throws_when_additional_count_mismatch() {
    let h = header(0x4040, false, 0, false, false, true, false, 0, 0, 0, 0, 1);
    let err = Message::new(h, vec![], vec![], vec![], vec![]).unwrap_err();
    assert_eq!(
        err.to_string(),
        "Invalid DNS response: additional count mismatch"
    );
}

#[test]
fn decode_throws_for_nxdomain_without_authority() {
    let mut packet = b"\x1a\x2c\x85\x83\x00\x01\x00\x00\x00\x00\x00\x00".to_vec();
    packet.extend(b"\x07missing\x07example\x03com\x00\x00\x01\x00\x01");
    let err = Message::decode(&packet).unwrap_err();
    assert_eq!(err.to_string(), "NXDOMAIN requires SOA in authority");
}

#[test]
fn decode_throws_for_nodata_without_authority() {
    let mut packet = b"\x1a\x2d\x85\x80\x00\x01\x00\x00\x00\x00\x00\x00".to_vec();
    packet.extend(b"\x05empty\x07example\x03com\x00\x00\x01\x00\x01");
    let err = Message::decode(&packet).unwrap_err();
    assert_eq!(err.to_string(), "NODATA should include SOA in authority");
}

#[test]
fn decode_throws_when_packet_too_short() {
    let err = Message::decode(b"\x00\x01\x00").unwrap_err();
    assert!(matches!(err, Error::Decoding(_)));
    assert_eq!(err.to_string(), "Invalid DNS response: header too short");
}

#[test]
fn decode_throws_partial_decoding_on_truncated_question() {
    let mut packet = b"\x12\x34\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00".to_vec();
    packet.extend(b"\x03www\x07example\x03com\x00");
    let err = Message::decode(&packet).unwrap_err();
    match err {
        Error::PartialDecoding { header, message } => {
            assert_eq!(header.id, 0x1234);
            assert_eq!(header.question_count, 1);
            assert_eq!(message, "Question section truncated");
        }
        other => panic!("expected PartialDecoding, got {other:?}"),
    }
}

#[test]
fn decode_throws_partial_decoding_on_truncated_answer() {
    let question = b"\x03www\x07example\x03com\x00\x00\x01\x00\x01";
    let header_bytes = b"\xab\xcd\x81\x80\x00\x01\x00\x01\x00\x00\x00\x00";
    let answer = b"\x03www\x07example\x03com\x00\x00\x01\x00\x01\x00\x00\x01\x2C\x00\x04";
    let mut packet = header_bytes.to_vec();
    packet.extend(question);
    packet.extend(answer);
    let err = Message::decode(&packet).unwrap_err();
    match err {
        Error::PartialDecoding { header, message } => {
            assert_eq!(header.id, 0xABCD);
            assert_eq!(message, "RDATA exceeds packet bounds");
        }
        other => panic!("expected PartialDecoding, got {other:?}"),
    }
}

#[test]
fn decode_throws_partial_decoding_on_extra_bytes() {
    let mut packet = standard_answer_packet();
    packet.push(0xFF);
    let err = Message::decode(&packet).unwrap_err();
    assert!(matches!(err, Error::PartialDecoding { .. }));
    assert_eq!(err.to_string(), "Invalid packet length");
}

#[test]
fn decode_nxdomain_with_authority() {
    let authority_rdata = b"\x03ns1\x07example\x03com\x00\x0Ahostmaster\x07example\x03com\x00\x00\x00\x00\x01\x00\x00\x0E\x10\x00\x00\x03\x84\x00\x09\x3A\x80\x00\x00\x01\x2C";
    let mut packet = b"\x1a\x2e\x81\x83\x00\x01\x00\x00\x00\x01\x00\x00".to_vec();
    packet.extend(b"\x07missing\x07example\x03com\x00\x00\x01\x00\x01");
    packet.extend(b"\x07example\x03com\x00\x00\x06\x00\x01\x00\x00\x03\x84\x00\x3D");
    packet.extend(authority_rdata);
    let response = Message::decode(&packet).unwrap();
    assert_eq!(response.header.response_code, Message::RCODE_NXDOMAIN);
    assert_eq!(response.authority.len(), 1);
    let soa = &response.authority[0];
    assert_eq!(soa.name, "example.com");
    assert_eq!(soa.type_code, Record::TYPE_SOA);
    assert_eq!(soa.class, Record::CLASS_IN);
    assert_eq!(soa.ttl, 900);
    assert_eq!(
        soa.rdata,
        "ns1.example.com hostmaster.example.com 1 3600 900 604800 300"
    );
}

#[test]
fn encode_truncates_when_exceeding_max_size() {
    let query = query_id("example.com", Record::TYPE_A, 0x1234);
    let answers: Vec<Record> = (0..100)
        .map(|i| {
            rec(
                "example.com",
                Record::TYPE_A,
                60,
                &format!("192.168.{}.{}", i % 256, i % 256),
            )
        })
        .collect();
    let response = respond(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        answers,
        vec![],
        vec![],
        false,
        false,
    );
    let truncated = response.encode(Some(512)).unwrap();
    let decoded = Message::decode(&truncated).unwrap();
    assert!(decoded.header.truncated);
    assert!(!decoded.answers.is_empty());
    assert!(decoded.answers.len() < 100);
    assert!(decoded.authority.is_empty());
    assert!(decoded.additional.is_empty());
    assert_eq!(decoded.questions.len(), 1);
    assert_eq!(decoded.questions[0].name, query.questions[0].name);
    assert!(truncated.len() <= 512);
}

#[test]
fn truncation_drops_additional_section_first() {
    let query = query_id("example.com", Record::TYPE_MX, 0x5678);
    let answers = vec![Record::new("example.com", Record::TYPE_MX)
        .class(Record::CLASS_IN)
        .ttl(300)
        .rdata("mail.example.com")
        .priority(10)];
    let additional: Vec<Record> = (0..50)
        .map(|i| {
            rec(
                &format!("mail{i}.example.com"),
                Record::TYPE_A,
                300,
                &format!("192.168.1.{i}"),
            )
        })
        .collect();
    let response = respond(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        answers,
        vec![],
        additional,
        false,
        false,
    );
    let truncated = response.encode(Some(512)).unwrap();
    let decoded = Message::decode(&truncated).unwrap();
    assert!(!decoded.header.truncated);
    assert_eq!(decoded.answers.len(), 1);
    assert_eq!(decoded.answers[0].name, "example.com");
    assert!(decoded.additional.is_empty());
}

#[test]
fn truncation_drops_authority_section_second() {
    let query = query_id("example.com", Record::TYPE_A, 0x9ABC);
    let answers = vec![rec("example.com", Record::TYPE_A, 60, "192.168.1.1")];
    let authority: Vec<Record> = (0..30)
        .map(|i| {
            rec(
                "example.com",
                Record::TYPE_NS,
                3600,
                &format!("ns{i}.example.com"),
            )
        })
        .collect();
    let response = respond(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        answers,
        authority,
        vec![],
        false,
        false,
    );
    let truncated = response.encode(Some(512)).unwrap();
    let decoded = Message::decode(&truncated).unwrap();
    assert!(!decoded.header.truncated);
    assert_eq!(decoded.answers.len(), 1);
    assert!(decoded.authority.is_empty());
}

#[test]
fn encode_without_max_size_does_not_truncate() {
    let query = query_id("example.com", Record::TYPE_A, 0x1234);
    let answers: Vec<Record> = (0..5)
        .map(|i| rec("example.com", Record::TYPE_A, 60, &format!("192.168.1.{i}")))
        .collect();
    let response = respond(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        answers,
        vec![],
        vec![],
        false,
        false,
    );
    let encoded = response.encode(None).unwrap();
    let decoded = Message::decode(&encoded).unwrap();
    assert!(!decoded.header.truncated);
    assert_eq!(decoded.answers.len(), 5);
}

#[test]
fn encode_nodata_with_truncation_dropping_authority() {
    let query = query_id("empty.example.com", Record::TYPE_TXT, 0x1234);
    let soa = Record::new("example.com", Record::TYPE_SOA)
        .class(Record::CLASS_IN)
        .ttl(300)
        .rdata("ns.example.com. hostmaster.example.com. 2024010101 3600 600 86400 300");
    let response = respond(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        vec![],
        vec![soa],
        vec![],
        true,
        false,
    );
    let truncated = response.encode(Some(80)).unwrap();
    let decoded = Message::decode(&truncated).unwrap();
    assert!(decoded.answers.is_empty());
    assert!(decoded.authority.is_empty());
    assert!(!decoded.header.authoritative);
}

#[test]
fn truncation_drops_additional_when_authority_overflows() {
    let query = query_id("example.com", Record::TYPE_A, 0xAB01);
    let answers = vec![rec("example.com", Record::TYPE_A, 60, "192.168.1.1")];
    let authority: Vec<Record> = (0..30)
        .map(|i| {
            rec(
                "example.com",
                Record::TYPE_NS,
                3600,
                &format!("ns{i}.example.com"),
            )
        })
        .collect();
    let additional = vec![rec("glue.example.com", Record::TYPE_A, 60, "192.168.1.2")];
    let response = respond(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        answers,
        authority,
        additional,
        false,
        false,
    );
    let truncated = response.encode(Some(512)).unwrap();
    let decoded = Message::decode(&truncated).unwrap();
    assert!(!decoded.header.truncated);
    assert_eq!(decoded.answers.len(), 1);
    assert!(decoded.authority.is_empty());
    assert!(decoded.additional.is_empty());
    assert!(truncated.len() <= 512);
}

#[test]
fn answer_truncation_drops_populated_authority_and_additional() {
    let query = query_id("example.com", Record::TYPE_A, 0xCD02);
    let answers: Vec<Record> = (0..100)
        .map(|i| {
            rec(
                "example.com",
                Record::TYPE_A,
                60,
                &format!("10.0.{}.{}", i % 256, i % 256),
            )
        })
        .collect();
    let authority: Vec<Record> = (0..5)
        .map(|i| {
            rec(
                "example.com",
                Record::TYPE_NS,
                3600,
                &format!("ns{i}.example.com"),
            )
        })
        .collect();
    let additional: Vec<Record> = (0..5)
        .map(|i| {
            rec(
                &format!("ns{i}.example.com"),
                Record::TYPE_A,
                60,
                &format!("192.168.2.{i}"),
            )
        })
        .collect();
    let response = respond(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        answers,
        authority,
        additional,
        false,
        false,
    );
    let truncated = response.encode(Some(512)).unwrap();
    let decoded = Message::decode(&truncated).unwrap();
    assert!(decoded.header.truncated);
    assert!(!decoded.answers.is_empty());
    assert!(decoded.answers.len() < 100);
    assert!(decoded.authority.is_empty());
    assert!(decoded.additional.is_empty());
    assert!(truncated.len() <= 512);
}

#[test]
fn re_encode_preserves_original_truncated_flag() {
    let query = query_id("example.com", Record::TYPE_A, 0xEF03);
    let answers = vec![rec("example.com", Record::TYPE_A, 60, "192.168.1.1")];
    let response = respond(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        answers,
        vec![],
        vec![],
        false,
        true,
    );
    let encoded = response.encode(None).unwrap();
    let decoded = Message::decode(&encoded).unwrap();
    assert!(decoded.header.truncated);
    assert_eq!(encoded, decoded.encode(None).unwrap());
}

#[test]
fn encode_fits_exactly_at_max_size_boundary() {
    let query = query_id("example.com", Record::TYPE_A, 0x1104);
    let answers = vec![
        rec("example.com", Record::TYPE_A, 60, "192.168.1.1"),
        rec("example.com", Record::TYPE_A, 60, "192.168.1.2"),
    ];
    let response = respond(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        answers.clone(),
        vec![],
        vec![],
        false,
        false,
    );
    let natural = response.encode(None).unwrap();
    let exact = natural.len();
    assert_eq!(response.encode(Some(exact)).unwrap(), natural);
    let below = Message::decode(&response.encode(Some(exact - 1)).unwrap()).unwrap();
    assert!(below.answers.len() < answers.len());
}

#[test]
fn re_encode_with_max_size_preserves_original_truncated_flag() {
    let query = query_id("example.com", Record::TYPE_MX, 0x4407);
    let answers = vec![Record::new("example.com", Record::TYPE_MX)
        .class(Record::CLASS_IN)
        .ttl(300)
        .rdata("mail.example.com")
        .priority(10)];
    let additional: Vec<Record> = (0..50)
        .map(|i| {
            rec(
                &format!("mail{i}.example.com"),
                Record::TYPE_A,
                300,
                &format!("192.168.1.{}", i % 256),
            )
        })
        .collect();
    let response = respond(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        answers,
        vec![],
        additional,
        false,
        true,
    );
    let re_encoded = response.encode(Some(512)).unwrap();
    let decoded = Message::decode(&re_encoded).unwrap();
    assert!(decoded.header.truncated);
    assert!(decoded.additional.is_empty());
}

#[test]
fn extreme_answer_truncation_preserves_authoritative_flag() {
    let query = query_id("example.com", Record::TYPE_A, 0x3306);
    let answers: Vec<Record> = (0..5)
        .map(|i| {
            rec(
                &format!("verylongname{i}.example.com"),
                Record::TYPE_A,
                60,
                &format!("10.0.0.{i}"),
            )
        })
        .collect();
    let response = respond(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        answers,
        vec![],
        vec![],
        true,
        false,
    );
    let truncated = response.encode(Some(40)).unwrap();
    let decoded = Message::decode(&truncated).unwrap();
    assert!(decoded.header.truncated);
    assert!(decoded.answers.is_empty());
    assert!(decoded.header.authoritative);
}

#[test]
fn validate_checks_answer_authority_and_additional_records() {
    let query = query_id("example.com", Record::TYPE_A, 0x5508);
    let invalid = vec![Record::new("ns2.appwrite.zone", Record::TYPE_A)
        .class(Record::CLASS_IN)
        .ttl(300)
        .rdata("ns2.appwrite.zone")];
    let valid = vec![Record::new("example.com", Record::TYPE_NS)
        .class(Record::CLASS_IN)
        .ttl(300)
        .rdata("ns2.appwrite.zone")];
    for section in ["answers", "authority", "additional"] {
        let response = respond(
            &query.header,
            Message::RCODE_NOERROR,
            query.questions.clone(),
            if section == "answers" {
                invalid.clone()
            } else {
                valid.clone()
            },
            if section == "authority" {
                invalid.clone()
            } else {
                valid.clone()
            },
            if section == "additional" {
                invalid.clone()
            } else {
                valid.clone()
            },
            false,
            false,
        );
        let err = response.validate().unwrap_err();
        assert_eq!(err.to_string(), "Invalid IPv4 address: ns2.appwrite.zone");
    }
}

#[test]
fn no_answers_with_oversized_authority_drops_without_truncation() {
    let query = query_id("example.com", Record::TYPE_A, 0x2205);
    let authority: Vec<Record> = (0..30)
        .map(|i| {
            rec(
                "example.com",
                Record::TYPE_NS,
                3600,
                &format!("ns{i}.example.com"),
            )
        })
        .collect();
    let response = respond(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        vec![],
        authority,
        vec![],
        false,
        false,
    );
    let truncated = response.encode(Some(512)).unwrap();
    let decoded = Message::decode(&truncated).unwrap();
    assert!(!decoded.header.truncated);
    assert!(decoded.answers.is_empty());
    assert!(decoded.authority.is_empty());
    assert!(truncated.len() <= 512);
}
