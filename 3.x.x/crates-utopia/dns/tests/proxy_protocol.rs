//! Port of `tests/unit/DNS/ProxyProtocolTest.php`.

use std::net::{Ipv4Addr, Ipv6Addr};

use utopia_dns::ProxyProtocol;

#[test]
fn v1_tcp4() {
    let raw = b"PROXY TCP4 192.168.0.1 192.168.0.11 56324 443\r\n";
    let mut buf = raw.to_vec();
    buf.extend(b"payload");
    let header = ProxyProtocol::parse(&buf).unwrap().unwrap();
    assert_eq!(header.length, raw.len());
    assert_eq!(header.ip.as_deref(), Some("192.168.0.1"));
    assert_eq!(header.port, Some(56324));
}

#[test]
fn v1_tcp6() {
    let raw = b"PROXY TCP6 2001:db8::1 2001:db8::2 4124 53\r\n";
    let header = ProxyProtocol::parse(raw).unwrap().unwrap();
    assert_eq!(header.length, raw.len());
    assert_eq!(header.ip.as_deref(), Some("2001:db8::1"));
    assert_eq!(header.port, Some(4124));
}

#[test]
fn v1_unknown() {
    let raw = b"PROXY UNKNOWN\r\n";
    let header = ProxyProtocol::parse(raw).unwrap().unwrap();
    assert_eq!(header.length, raw.len());
    assert_eq!(header.ip, None);
    assert_eq!(header.port, None);
}

#[test]
fn incomplete_returns_none() {
    assert!(ProxyProtocol::parse(b"").unwrap().is_none());
    assert!(ProxyProtocol::parse(b"P").unwrap().is_none());
    assert!(ProxyProtocol::parse(b"PROXY TCP4 192.168.0.1")
        .unwrap()
        .is_none());
    assert!(ProxyProtocol::parse(b"\r\n\r\n").unwrap().is_none());
    assert!(ProxyProtocol::parse(ProxyProtocol::SIGNATURE_V2)
        .unwrap()
        .is_none());
    let mut buf = ProxyProtocol::SIGNATURE_V2.to_vec();
    buf.extend(b"\x21\x11");
    assert!(ProxyProtocol::parse(&buf).unwrap().is_none());
}

#[test]
fn v2_incomplete_addresses_returns_none() {
    let mut buf = ProxyProtocol::SIGNATURE_V2.to_vec();
    buf.extend(b"\x21\x11");
    buf.extend(12u16.to_be_bytes());
    assert!(ProxyProtocol::parse(&buf).unwrap().is_none());
}

#[test]
fn garbage_throws() {
    assert!(ProxyProtocol::parse(b"GET / HTTP/1.1").is_err());
}

#[test]
fn v1_invalid_address_throws() {
    assert!(ProxyProtocol::parse(b"PROXY TCP4 999.0.0.1 192.168.0.11 56324 443\r\n").is_err());
}

#[test]
fn v1_invalid_protocol_throws() {
    assert!(ProxyProtocol::parse(b"PROXY TCP9 192.168.0.1 192.168.0.11 56324 443\r\n").is_err());
}

#[test]
fn v1_unterminated_throws() {
    let mut raw = b"PROXY TCP4 ".to_vec();
    raw.extend(std::iter::repeat(b'x').take(107));
    assert!(ProxyProtocol::parse(&raw).is_err());
}

#[test]
fn v2_tcp4() {
    let mut addresses = Ipv4Addr::new(10, 1, 2, 3).octets().to_vec();
    addresses.extend(Ipv4Addr::new(10, 0, 0, 1).octets());
    addresses.extend(4124u16.to_be_bytes());
    addresses.extend(53u16.to_be_bytes());
    let mut buffer = ProxyProtocol::SIGNATURE_V2.to_vec();
    buffer.extend(b"\x21\x11");
    buffer.extend(u16::try_from(addresses.len()).unwrap().to_be_bytes());
    buffer.extend(&addresses);
    let mut with_payload = buffer.clone();
    with_payload.extend(b"payload");
    let header = ProxyProtocol::parse(&with_payload).unwrap().unwrap();
    assert_eq!(header.length, buffer.len());
    assert_eq!(header.ip.as_deref(), Some("10.1.2.3"));
    assert_eq!(header.port, Some(4124));
}

#[test]
fn v2_tcp6() {
    let src: Ipv6Addr = "2001:db8::1".parse().unwrap();
    let dst: Ipv6Addr = "2001:db8::2".parse().unwrap();
    let mut addresses = src.octets().to_vec();
    addresses.extend(dst.octets());
    addresses.extend(4124u16.to_be_bytes());
    addresses.extend(53u16.to_be_bytes());
    let mut buffer = ProxyProtocol::SIGNATURE_V2.to_vec();
    buffer.extend(b"\x21\x21");
    buffer.extend(u16::try_from(addresses.len()).unwrap().to_be_bytes());
    buffer.extend(&addresses);
    let header = ProxyProtocol::parse(&buffer).unwrap().unwrap();
    assert_eq!(header.ip.as_deref(), Some("2001:db8::1"));
    assert_eq!(header.port, Some(4124));
}

#[test]
fn v2_with_tlv_extension() {
    let mut addresses = Ipv4Addr::new(10, 1, 2, 3).octets().to_vec();
    addresses.extend(Ipv4Addr::new(10, 0, 0, 1).octets());
    addresses.extend(4124u16.to_be_bytes());
    addresses.extend(53u16.to_be_bytes());
    let tlv = b"\x04\x00\x02ok";
    let mut addr_tlv = addresses.clone();
    addr_tlv.extend(tlv);
    let mut buffer = ProxyProtocol::SIGNATURE_V2.to_vec();
    buffer.extend(b"\x21\x11");
    buffer.extend(u16::try_from(addr_tlv.len()).unwrap().to_be_bytes());
    buffer.extend(&addr_tlv);
    let header = ProxyProtocol::parse(&buffer).unwrap().unwrap();
    assert_eq!(header.length, buffer.len());
    assert_eq!(header.ip.as_deref(), Some("10.1.2.3"));
}

#[test]
fn v2_local() {
    let mut buffer = ProxyProtocol::SIGNATURE_V2.to_vec();
    buffer.extend(b"\x20\x00");
    buffer.extend(0u16.to_be_bytes());
    let header = ProxyProtocol::parse(&buffer).unwrap().unwrap();
    assert_eq!(header.length, 16);
    assert_eq!(header.ip, None);
    assert_eq!(header.port, None);
}

#[test]
fn v2_invalid_version_throws() {
    let mut buf = ProxyProtocol::SIGNATURE_V2.to_vec();
    buf.extend(b"\x31\x11");
    buf.extend(0u16.to_be_bytes());
    assert!(ProxyProtocol::parse(&buf).is_err());
}

#[test]
fn v2_truncated_addresses_throws() {
    let mut buf = ProxyProtocol::SIGNATURE_V2.to_vec();
    buf.extend(b"\x21\x11");
    buf.extend(4u16.to_be_bytes());
    buf.extend(b"abcd");
    assert!(ProxyProtocol::parse(&buf).is_err());
}
