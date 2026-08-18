//! Port of `tests/unit/DNS/Message/HeaderTest.php`.

use utopia_dns::error::Error;
use utopia_dns::message::Header;

#[allow(clippy::too_many_arguments, clippy::fn_params_excessive_bools)]
fn header(
    id: u16,
    is_response: bool,
    opcode: u8,
    authoritative: bool,
    truncated: bool,
    rd: bool,
    ra: bool,
    rcode: u8,
    qd: u16,
    an: u16,
    ns: u16,
    ar: u16,
) -> Header {
    Header::new(
        id,
        is_response,
        opcode,
        authoritative,
        truncated,
        rd,
        ra,
        rcode,
        qd,
        an,
        ns,
        ar,
    )
    .unwrap()
}

#[test]
fn encode_decode_round_trip() {
    let h = header(0x1234, true, 0, false, true, true, true, 0, 1, 2, 3, 4);
    let binary = h.encode();
    let decoded = Header::decode(&binary, 0).unwrap();
    assert_eq!(0x1234, decoded.id);
    assert!(decoded.is_response);
    assert_eq!(0, decoded.opcode);
    assert!(!decoded.authoritative);
    assert!(decoded.truncated);
    assert!(decoded.recursion_desired);
    assert!(decoded.recursion_available);
    assert_eq!(0, decoded.response_code);
    assert_eq!(1, decoded.question_count);
    assert_eq!(2, decoded.answer_count);
    assert_eq!(3, decoded.authority_count);
    assert_eq!(4, decoded.additional_count);
}

#[test]
fn decode_throws_on_short_data() {
    let err = Header::decode(&[0x00, 0x01], 0).unwrap_err();
    assert!(matches!(err, Error::Decoding(_)));
}

#[test]
fn decode_honors_offset() {
    let binary_header = header(0x0a0b, true, 0, false, true, true, true, 0, 1, 2, 3, 4).encode();
    let mut payload = vec![0xff, 0xff, 0xff, 0xff];
    payload.extend_from_slice(&binary_header);
    payload.extend_from_slice(&[0x00, 0x00]);
    let decoded = Header::decode(&payload, 4).unwrap();
    assert_eq!(0x0a0b, decoded.id);
    assert!(decoded.is_response);
    assert!(decoded.truncated);
    assert!(decoded.recursion_desired);
    assert!(decoded.recursion_available);
    assert_eq!(1, decoded.question_count);
}

#[test]
fn encode_uses_network_byte_order() {
    let h = header(
        0x1a2b, false, 0, false, false, true, false, 3, 0x0506, 0x0708, 0x090a, 0x0b0c,
    );
    assert_eq!(hex::encode(h.encode()), "1a2b010305060708090a0b0c");
}

#[test]
fn opcode_validation() {
    let err = Header::new(1, false, 16, false, false, false, false, 0, 0, 0, 0, 0).unwrap_err();
    assert_eq!(err.to_string(), "Opcode must be 0-15");
}

#[test]
fn response_code_validation() {
    let err = Header::new(1, false, 0, false, false, false, false, 16, 0, 0, 0, 0).unwrap_err();
    assert_eq!(err.to_string(), "Response code must be 0-15");
}

#[test]
fn decode_accepts_non_zero_z_bits() {
    let flags = 0x81F0u16;
    let mut buf = Vec::new();
    buf.extend(0x1234u16.to_be_bytes());
    buf.extend(flags.to_be_bytes());
    buf.extend(1u16.to_be_bytes());
    buf.extend(0u16.to_be_bytes());
    buf.extend(0u16.to_be_bytes());
    buf.extend(0u16.to_be_bytes());
    let decoded = Header::decode(&buf, 0).unwrap();
    assert_eq!(0x1234, decoded.id);
    assert!(decoded.is_response);
    assert!(decoded.recursion_desired);
    assert!(decoded.recursion_available);
    assert_eq!(0, decoded.response_code);
    assert_eq!(1, decoded.question_count);
}

#[test]
fn decode_accepts_various_z_bit_patterns() {
    let patterns = [0x0010, 0x0020, 0x0040, 0x0030, 0x0050, 0x0060, 0x0070];
    for z in patterns {
        let flags = 0x0100u16 | z;
        let mut buf = Vec::new();
        buf.extend(0xABCDu16.to_be_bytes());
        buf.extend(flags.to_be_bytes());
        buf.extend(1u16.to_be_bytes());
        buf.extend([0u8; 6]);
        let decoded = Header::decode(&buf, 0).unwrap();
        assert_eq!(0xABCD, decoded.id, "z={z:#06x}");
        assert!(!decoded.is_response);
        assert!(decoded.recursion_desired);
        assert_eq!(0, decoded.response_code);
    }
}

mod hex {
    pub fn encode(data: Vec<u8>) -> String {
        const DIGITS: &[u8; 16] = b"0123456789abcdef";
        let mut out = String::with_capacity(data.len() * 2);
        for b in data {
            out.push(char::from(DIGITS[usize::from(b >> 4)]));
            out.push(char::from(DIGITS[usize::from(b & 0x0f)]));
        }
        out
    }
}
