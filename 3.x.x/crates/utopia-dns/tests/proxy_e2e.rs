//! Port of `tests/e2e/DNS/ProxyProtocolTest.php` using an in-process TCP server.

mod common;

use std::io::{Read, Write};
use std::net::{Ipv4Addr, TcpStream};
use std::time::Duration;

use common::start_proxy_tcp;
use utopia_dns::message::{Message, Question, Record};
use utopia_dns::ProxyProtocol;

fn connect(port: u16) -> TcpStream {
    let stream = TcpStream::connect_timeout(
        &format!("127.0.0.1:{port}").parse().unwrap(),
        Duration::from_secs(5),
    )
    .unwrap();
    stream
        .set_read_timeout(Some(Duration::from_secs(5)))
        .unwrap();
    stream
        .set_write_timeout(Some(Duration::from_secs(5)))
        .unwrap();
    stream
}

fn query_behind_proxy(port: u16, proxy_header: &[u8]) -> Message {
    let query = Message::query(Question::new("dev.appwrite.io", Record::TYPE_A), None, true)
        .unwrap()
        .encode(None)
        .unwrap();
    let mut stream = connect(port);
    stream.write_all(proxy_header).unwrap();
    stream
        .write_all(&u16::try_from(query.len()).unwrap().to_be_bytes())
        .unwrap();
    stream.write_all(&query).unwrap();
    let mut prefix = [0u8; 2];
    stream.read_exact(&mut prefix).unwrap();
    let len = u16::from_be_bytes(prefix) as usize;
    let mut payload = vec![0u8; len];
    stream.read_exact(&mut payload).unwrap();
    Message::decode(&payload).unwrap()
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn v1_header() {
    let server = start_proxy_tcp().await;
    let port = server.tcp.unwrap().port();
    let response = query_behind_proxy(port, b"PROXY TCP4 203.0.113.9 10.0.0.1 42424 53\r\n");
    assert_eq!(response.answers.len(), 1);
    assert_eq!(response.answers[0].name, "dev.appwrite.io");
    assert_eq!(response.answers[0].rdata, "180.12.3.24");
    server.stop();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn v2_header() {
    let server = start_proxy_tcp().await;
    let port = server.tcp.unwrap().port();
    let mut addresses = Ipv4Addr::new(203, 0, 113, 9).octets().to_vec();
    addresses.extend(Ipv4Addr::new(10, 0, 0, 1).octets());
    addresses.extend(42424u16.to_be_bytes());
    addresses.extend(53u16.to_be_bytes());
    let mut header = ProxyProtocol::SIGNATURE_V2.to_vec();
    header.extend(b"\x21\x11");
    header.extend(u16::try_from(addresses.len()).unwrap().to_be_bytes());
    header.extend(addresses);
    let response = query_behind_proxy(port, &header);
    assert_eq!(response.answers.len(), 1);
    assert_eq!(response.answers[0].rdata, "180.12.3.24");
    server.stop();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn invalid_header_closes_connection() {
    let server = start_proxy_tcp().await;
    let port = server.tcp.unwrap().port();
    let mut stream = connect(port);
    stream.write_all(b"NOT A PROXY HEADER\r\n").unwrap();
    let mut buf = [0u8; 2];
    let n = stream.read(&mut buf).unwrap_or(0);
    assert_eq!(n, 0);
    server.stop();
}
