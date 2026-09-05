//! Port of `tests/unit/DNS/Message/RecordTest.php`.

use utopia_dns::message::Record;

fn a_example() -> Record {
    Record::new("example.com", Record::TYPE_A)
        .class(Record::CLASS_IN)
        .ttl(300)
        .rdata("93.184.216.34")
}

#[test]
fn encode_a_record_matches_bytes() {
    let expected =
        b"\x07example\x03com\x00\x00\x01\x00\x01\x00\x00\x01\x2C\x00\x04\x5D\xB8\xD8\x22";
    assert_eq!(a_example().encode().unwrap(), expected);
}

#[test]
fn decode_a_record_parses_fields() {
    let data = b"\x07example\x03com\x00\x00\x01\x00\x01\x00\x00\x01\x2C\x00\x04\x5D\xB8\xD8\x22";
    let mut offset = 0;
    let record = Record::decode(data, &mut offset).unwrap();
    assert_eq!(record.name, "example.com");
    assert_eq!(record.type_code, Record::TYPE_A);
    assert_eq!(record.class, Record::CLASS_IN);
    assert_eq!(record.ttl, 300);
    assert_eq!(record.rdata, "93.184.216.34");
    assert_eq!(record.priority, None);
    assert_eq!(record.weight, None);
    assert_eq!(record.port, None);
    assert_eq!(offset, data.len());
}

#[test]
fn encode_mx_record_matches_bytes() {
    let record = Record::new("mail.example.com", Record::TYPE_MX)
        .class(Record::CLASS_IN)
        .ttl(3600)
        .rdata("mail.exchange.example.com")
        .priority(10);
    let expected = b"\x04mail\x07example\x03com\x00\x00\x0F\x00\x01\x00\x00\x0E\x10\x00\x1D\x00\x0A\x04mail\x08exchange\x07example\x03com\x00";
    assert_eq!(record.encode().unwrap(), expected);
}

#[test]
fn decode_mx_record_parses_fields() {
    let data = b"\x04mail\x07example\x03com\x00\x00\x0F\x00\x01\x00\x00\x0E\x10\x00\x1D\x00\x0A\x04mail\x08exchange\x07example\x03com\x00";
    let mut offset = 0;
    let record = Record::decode(data, &mut offset).unwrap();
    assert_eq!(record.name, "mail.example.com");
    assert_eq!(record.type_code, Record::TYPE_MX);
    assert_eq!(record.class, Record::CLASS_IN);
    assert_eq!(record.ttl, 3600);
    assert_eq!(record.priority, Some(10));
    assert_eq!(record.rdata, "mail.exchange.example.com");
    assert_eq!(offset, data.len());
}

#[test]
fn encode_srv_record_matches_bytes() {
    let record = Record::new("_sip._tcp.example.com", Record::TYPE_SRV)
        .class(Record::CLASS_IN)
        .ttl(7200)
        .rdata("sip.example.com")
        .priority(5)
        .weight(10)
        .port(5060);
    let expected = b"\x04_sip\x04_tcp\x07example\x03com\x00\x00\x21\x00\x01\x00\x00\x1C\x20\x00\x17\x00\x05\x00\x0A\x13\xC4\x03sip\x07example\x03com\x00";
    assert_eq!(record.encode().unwrap(), expected);
}

#[test]
fn decode_srv_record_parses_fields() {
    let data = b"\x04_sip\x04_tcp\x07example\x03com\x00\x00\x21\x00\x01\x00\x00\x1C\x20\x00\x17\x00\x05\x00\x0A\x13\xC4\x03sip\x07example\x03com\x00";
    let mut offset = 0;
    let record = Record::decode(data, &mut offset).unwrap();
    assert_eq!(record.name, "_sip._tcp.example.com");
    assert_eq!(record.type_code, Record::TYPE_SRV);
    assert_eq!(record.class, Record::CLASS_IN);
    assert_eq!(record.ttl, 7200);
    assert_eq!(record.priority, Some(5));
    assert_eq!(record.weight, Some(10));
    assert_eq!(record.port, Some(5060));
    assert_eq!(record.rdata, "sip.example.com");
    assert_eq!(offset, data.len());
}

#[test]
fn encode_txt_record_matches_bytes() {
    let record = Record::new("example.com", Record::TYPE_TXT)
        .class(Record::CLASS_IN)
        .ttl(600)
        .rdata("hello");
    let expected = b"\x07example\x03com\x00\x00\x10\x00\x01\x00\x00\x02\x58\x00\x06\x05hello";
    assert_eq!(record.encode().unwrap(), expected);
}

#[test]
fn decode_txt_record_parses_fields() {
    let data = b"\x07example\x03com\x00\x00\x10\x00\x01\x00\x00\x02\x58\x00\x06\x05hello";
    let mut offset = 0;
    let record = Record::decode(data, &mut offset).unwrap();
    assert_eq!(record.name, "example.com");
    assert_eq!(record.type_code, Record::TYPE_TXT);
    assert_eq!(record.class, Record::CLASS_IN);
    assert_eq!(record.ttl, 600);
    assert_eq!(record.rdata, "hello");
    assert_eq!(offset, data.len());
}

#[test]
fn decode_cname_record_parses_name_rdata() {
    let data = b"\x03www\x07example\x03com\x00\x00\x05\x00\x01\x00\x00\x0F\xA0\x00\x11\x03cdn\x07example\x03com\x00";
    let mut offset = 0;
    let record = Record::decode(data, &mut offset).unwrap();
    assert_eq!(record.name, "www.example.com");
    assert_eq!(record.type_code, Record::TYPE_CNAME);
    assert_eq!(record.class, Record::CLASS_IN);
    assert_eq!(record.ttl, 4000);
    assert_eq!(record.rdata, "cdn.example.com");
    assert_eq!(offset, data.len());
}

#[test]
fn decode_unknown_record_keeps_hex_data() {
    let data = b"\x07example\x03com\x00\xFE\xF8\x00\x01\x00\x00\x00\x3C\x00\x02\x0A\xFF";
    let mut offset = 0;
    let record = Record::decode(data, &mut offset).unwrap();
    assert_eq!(record.name, "example.com");
    assert_eq!(record.type_code, 0xFEF8);
    assert_eq!(record.class, Record::CLASS_IN);
    assert_eq!(record.ttl, 60);
    assert_eq!(record.rdata, "0aff");
    assert_eq!(offset, data.len());
}

fn soa_wire() -> &'static [u8] {
    b"\x07example\x03com\x00\x00\x06\x00\x01\x00\x00\x0E\x10\x00\x38\x03ns1\x07example\x03com\x00\x05admin\x07example\x03com\x00\x78\xa5\x5b\x2d\x00\x00\x1C\x20\x00\x00\x0E\x10\x00\x12\x75\x00\x00\x01\x51\x80"
}

#[test]
fn decode_soa_record_parses_fields() {
    let data = soa_wire();
    let mut offset = 0;
    let record = Record::decode(data, &mut offset).unwrap();
    assert_eq!(record.name, "example.com");
    assert_eq!(record.type_code, Record::TYPE_SOA);
    assert_eq!(record.class, Record::CLASS_IN);
    assert_eq!(record.ttl, 3600);
    assert_eq!(
        record.rdata,
        "ns1.example.com admin.example.com 2024102701 7200 3600 1209600 86400"
    );
    assert_eq!(offset, data.len());
}

#[test]
fn encode_soa_record_matches_bytes() {
    let record = Record::new("example.com", Record::TYPE_SOA)
        .class(Record::CLASS_IN)
        .ttl(3600)
        .rdata("ns1.example.com admin.example.com 2024102701 7200 3600 1209600 86400");
    assert_eq!(record.encode().unwrap(), soa_wire());
}

#[test]
fn encode_soa_record_accepts_email_rname() {
    let record = Record::new("example.com", Record::TYPE_SOA)
        .class(Record::CLASS_IN)
        .ttl(3600)
        .rdata("ns1.example.com hostmaster@example.com 2024102701 7200 3600 1209600 86400");
    let encoded = record.encode().unwrap();
    let needle = b"\x0Ahostmaster\x07example\x03com\x00";
    assert!(encoded.windows(needle.len()).any(|w| w == needle));
}

#[test]
fn encode_soa_record_escapes_dots_in_email_rname_local_part() {
    let record = Record::new("example.com", Record::TYPE_SOA)
        .class(Record::CLASS_IN)
        .ttl(3600)
        .rdata("ns1.example.com first.last@example.com 2024102701 7200 3600 1209600 86400");
    let encoded = record.encode().unwrap();
    let needle = b"\x0Afirst.last\x07example\x03com\x00";
    assert!(encoded.windows(needle.len()).any(|w| w == needle));
}

#[test]
fn decode_txt_record_with_multiple_chunks() {
    let data = b"\x07example\x03com\x00\x00\x10\x00\x01\x00\x00\x02\x58\x00\x0C\x05hello\x05world";
    let mut offset = 0;
    let record = Record::decode(data, &mut offset).unwrap();
    assert_eq!(record.rdata, "helloworld");
    assert_eq!(offset, data.len());
}

#[test]
fn decode_txt_record_with_three_chunks() {
    let data =
        b"\x07example\x03com\x00\x00\x10\x00\x01\x00\x00\x02\x58\x00\x0C\x03foo\x03bar\x03baz";
    let mut offset = 0;
    let record = Record::decode(data, &mut offset).unwrap();
    assert_eq!(record.rdata, "foobarbaz");
}

#[test]
fn decode_soa_record_round_trip() {
    let original = soa_wire();
    let mut offset = 0;
    let record = Record::decode(original, &mut offset).unwrap();
    assert_eq!(record.encode().unwrap(), original);
}

fn txt_rdata_of(encoded: &[u8]) -> &[u8] {
    let name_len = b"\x07example\x03com\x00".len();
    let header_len = name_len + 2 + 2 + 4 + 2;
    &encoded[header_len..]
}

#[test]
fn encode_txt_record_with_multiple_chunks() {
    let exactly_256 = "a".repeat(256);
    let record = Record::new("example.com", Record::TYPE_TXT)
        .class(Record::CLASS_IN)
        .ttl(600)
        .rdata(&exactly_256);
    let encoded = record.encode().unwrap();
    let rdata = txt_rdata_of(&encoded);
    assert_eq!(rdata.len(), 258);
    assert_eq!(rdata[0], 255);
    assert_eq!(rdata[256], 1);
    let mut offset = 0;
    let decoded = Record::decode(&encoded, &mut offset).unwrap();
    assert_eq!(decoded.rdata, exactly_256);
}

#[test]
fn encode_txt_record_with_long_string() {
    let long = "a".repeat(300);
    let record = Record::new("example.com", Record::TYPE_TXT)
        .class(Record::CLASS_IN)
        .ttl(600)
        .rdata(&long);
    let encoded = record.encode().unwrap();
    let mut offset = 0;
    let decoded = Record::decode(&encoded, &mut offset).unwrap();
    assert_eq!(decoded.rdata, long);
    assert_eq!(decoded.type_code, Record::TYPE_TXT);
    assert_eq!(decoded.ttl, 600);
    let rdata = txt_rdata_of(&encoded);
    assert_eq!(rdata.len(), 302);
    assert_eq!(rdata[0], 255);
    assert_eq!(rdata[256], 45);
}

#[test]
fn encode_txt_record_round_trip_with_multiple_chunks() {
    let original =
        b"\x07example\x03com\x00\x00\x10\x00\x01\x00\x00\x02\x58\x00\x0C\x03foo\x03bar\x03baz";
    let mut offset = 0;
    let record = Record::decode(original, &mut offset).unwrap();
    let encoded = record.encode().unwrap();
    let mut offset2 = 0;
    let record2 = Record::decode(&encoded, &mut offset2).unwrap();
    assert_eq!(record2.rdata, "foobarbaz");
    assert_eq!(record2.type_code, Record::TYPE_TXT);
    assert_eq!(record2.ttl, 600);
}

#[test]
fn encode_txt_record_with_empty_rdata() {
    let record = Record::new("example.com", Record::TYPE_TXT)
        .class(Record::CLASS_IN)
        .ttl(600)
        .rdata("");
    let encoded = record.encode().unwrap();
    let rdata = txt_rdata_of(&encoded);
    assert_eq!(rdata.len(), 1);
    assert_eq!(rdata[0], 0);
    let mut offset = 0;
    let decoded = Record::decode(&encoded, &mut offset).unwrap();
    assert_eq!(decoded.rdata, "");
    assert_eq!(decoded.type_code, Record::TYPE_TXT);
    assert_eq!(decoded.ttl, 600);
}

#[test]
fn validate_rdata_rejects_hostname_for_a_record() {
    let record = Record::new("example.com", Record::TYPE_A)
        .class(Record::CLASS_IN)
        .ttl(300)
        .rdata("ns2.appwrite.zone");
    let err = record.validate_rdata().unwrap_err();
    assert_eq!(err.to_string(), "Invalid IPv4 address: ns2.appwrite.zone");
}

#[test]
fn validate_rdata_accepts_hostname_for_ns_record() {
    let record = Record::new("example.com", Record::TYPE_NS)
        .class(Record::CLASS_IN)
        .ttl(300)
        .rdata("ns2.appwrite.zone");
    record.validate_rdata().unwrap();
}

#[test]
fn constructor_trims_whitespace_from_name() {
    let record = Record::new("  example.com  ", Record::TYPE_A)
        .class(Record::CLASS_IN)
        .ttl(300)
        .rdata("93.184.216.34");
    assert_eq!(record.name, "example.com");
}

#[test]
fn constructor_trims_tabs_and_newlines_from_name() {
    let record = Record::new("\t\nexample.com\r\n", Record::TYPE_A)
        .class(Record::CLASS_IN)
        .ttl(300)
        .rdata("93.184.216.34");
    assert_eq!(record.name, "example.com");
}

#[test]
fn with_name_trims_whitespace() {
    let record = Record::new("example.com", Record::TYPE_A)
        .class(Record::CLASS_IN)
        .ttl(300)
        .rdata("93.184.216.34");
    let renamed = record.with_name("  other.com  ");
    assert_eq!(renamed.name, "other.com");
}
