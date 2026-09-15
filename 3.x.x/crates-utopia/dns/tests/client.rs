//! Port of `tests/e2e/DNS/ClientTest.php` using an in-process Native+Memory server.

mod common;

use common::{rdatas, start_native};
use utopia_dns::message::{Message, Question, Record};
use utopia_dns::Client;

fn query_udp(port: u16, name: &str, type_code: u16) -> Message {
    let client = Client::new("127.0.0.1", port, 5, false).unwrap();
    client
        .query(&Message::query(Question::new(name, type_code), None, true).unwrap())
        .unwrap()
}

fn query_tcp(port: u16, name: &str, type_code: u16) -> Message {
    let client = Client::new("127.0.0.1", port, 5, true).unwrap();
    client
        .query(&Message::query(Question::new(name, type_code), None, true).unwrap())
        .unwrap()
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn tcp_queries() {
    let server = start_native().await;
    let port = server.tcp.unwrap().port();
    let response = query_tcp(port, "dev2.appwrite.io", Record::TYPE_A);
    assert_eq!(response.answers.len(), 2);
    assert_eq!(response.answers[0].name, "dev2.appwrite.io");
    assert_eq!(response.answers[0].type_code, Record::TYPE_A);
    assert_eq!(response.answers[0].class, Record::CLASS_IN);
    assert_eq!(response.answers[0].ttl, 1800);
    let mut values = rdatas(&response.answers);
    values.sort();
    assert_eq!(values, ["142.6.0.1", "142.6.0.2"]);
    server.stop();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn a_records() {
    let server = start_native().await;
    let port = server.udp.unwrap().port();
    let records = query_udp(port, "dev.appwrite.io", Record::TYPE_A).answers;
    assert_eq!(records.len(), 1);
    assert_eq!(records[0].name, "dev.appwrite.io");
    assert_eq!(records[0].class, Record::CLASS_IN);
    assert_eq!(records[0].ttl, 10);
    assert_eq!(records[0].type_code, Record::TYPE_A);
    assert_eq!(records[0].rdata, "180.12.3.24");

    let records = query_udp(port, "dev2.appwrite.io", Record::TYPE_A).answers;
    assert_eq!(records.len(), 2);
    assert_eq!(records[0].name, "dev2.appwrite.io");
    assert_eq!(records[0].class, Record::CLASS_IN);
    assert_eq!(records[0].ttl, 1800);
    assert_eq!(records[0].type_code, Record::TYPE_A);
    let mut values = rdatas(&records);
    values.sort();
    assert_eq!(values, ["142.6.0.1", "142.6.0.2"]);

    let response = query_udp(port, "dev3.appwrite.io", Record::TYPE_A);
    assert!(response.answers.is_empty());
    server.stop();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn aaaa_records() {
    let server = start_native().await;
    let port = server.udp.unwrap().port();
    let records = query_udp(port, "dev.appwrite.io", Record::TYPE_AAAA).answers;
    assert_eq!(records.len(), 1);
    assert_eq!(records[0].name, "dev.appwrite.io");
    assert_eq!(records[0].class, Record::CLASS_IN);
    assert_eq!(records[0].ttl, 20);
    assert_eq!(records[0].type_code, Record::TYPE_AAAA);
    assert_eq!(records[0].rdata, "2001:db8::ff00:42:8329");

    let records = query_udp(port, "dev2.appwrite.io", Record::TYPE_AAAA).answers;
    assert_eq!(records.len(), 2);
    let mut values = rdatas(&records);
    values.sort();
    assert_eq!(values, ["2001:db8::ff00:0:1", "2001:db8::ff00:0:2"]);

    let response = query_udp(port, "dev3.appwrite.io", Record::TYPE_AAAA);
    assert!(response.answers.is_empty());
    server.stop();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn cname_records() {
    let server = start_native().await;
    let port = server.udp.unwrap().port();
    let records = query_udp(port, "alias.appwrite.io", Record::TYPE_CNAME).answers;
    assert_eq!(records.len(), 1);
    assert_eq!(records[0].name, "alias.appwrite.io");
    assert_eq!(records[0].class, Record::CLASS_IN);
    assert_eq!(records[0].ttl, 30);
    assert_eq!(records[0].type_code, Record::TYPE_CNAME);
    assert_eq!(records[0].rdata, "cloud.appwrite.io");

    let records = query_udp(port, "alias-missing.appwrite.io", Record::TYPE_CNAME).answers;
    assert!(records.is_empty());
    server.stop();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn txt_records() {
    let server = start_native().await;
    let port = server.udp.unwrap().port();
    let records = query_udp(port, "dev.appwrite.io", Record::TYPE_TXT).answers;
    assert_eq!(records.len(), 1);
    assert_eq!(records[0].name, "dev.appwrite.io");
    assert_eq!(records[0].class, Record::CLASS_IN);
    assert_eq!(records[0].ttl, 30);
    assert_eq!(records[0].type_code, Record::TYPE_TXT);
    assert_eq!(records[0].rdata, "awesome-secret-key");

    assert!(query_udp(port, "dev2.appwrite.io", Record::TYPE_TXT)
        .answers
        .is_empty());
    assert!(query_udp(port, "dev3.appwrite.io", Record::TYPE_TXT)
        .answers
        .is_empty());
    server.stop();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn ns_records() {
    let server = start_native().await;
    let port = server.udp.unwrap().port();
    let response = query_udp(port, "delegated.appwrite.io", Record::TYPE_NS);
    assert!(response.answers.is_empty());
    assert_eq!(response.authority.len(), 2);
    assert_eq!(response.authority[0].name, "delegated.appwrite.io");
    assert_eq!(response.authority[0].class, Record::CLASS_IN);
    assert_eq!(response.authority[0].ttl, 30);
    assert_eq!(response.authority[0].type_code, Record::TYPE_NS);
    assert_eq!(response.authority[1].type_code, Record::TYPE_NS);
    assert_eq!(response.authority[0].rdata, "ns1.test.io");
    assert_eq!(response.authority[1].rdata, "ns2.test.io");

    let response = query_udp(port, "dev2.appwrite.io", Record::TYPE_NS);
    assert!(response.answers.is_empty());
    assert_eq!(response.authority.len(), 1);
    assert_eq!(response.authority[0].name, "appwrite.io");
    assert_eq!(response.authority[0].type_code, Record::TYPE_SOA);

    let response = query_udp(port, "dev3.appwrite.io", Record::TYPE_NS);
    assert!(response.answers.is_empty());
    server.stop();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn caa_records() {
    let server = start_native().await;
    let port = server.udp.unwrap().port();
    let records = query_udp(port, "dev.appwrite.io", Record::TYPE_CAA).answers;
    assert_eq!(records.len(), 1);
    assert_eq!(records[0].name, "dev.appwrite.io");
    assert_eq!(records[0].class, Record::CLASS_IN);
    assert_eq!(records[0].type_code, Record::TYPE_CAA);
    assert_eq!(records[0].rdata, "0 issue \"letsencrypt.org\"");
    assert!(query_udp(port, "dev2.appwrite.io", Record::TYPE_CAA)
        .answers
        .is_empty());
    assert!(query_udp(port, "dev3.appwrite.io", Record::TYPE_CAA)
        .answers
        .is_empty());
    server.stop();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn soa_records() {
    let server = start_native().await;
    let port = server.udp.unwrap().port();
    let response = query_udp(port, "appwrite.io", Record::TYPE_SOA);
    assert!(response.authority.is_empty());
    assert_eq!(response.answers.len(), 1);
    assert_eq!(response.answers[0].name, "appwrite.io");
    assert_eq!(response.answers[0].class, Record::CLASS_IN);
    assert_eq!(response.answers[0].ttl, 30);
    assert_eq!(response.answers[0].type_code, Record::TYPE_SOA);
    let rdata = &response.answers[0].rdata;
    assert!(rdata.contains("ns1.appwrite.zone"));
    assert!(rdata.contains("team.appwrite.io"));
    assert!(rdata.contains("1 7200 1800 1209600 3600"));

    let response = query_udp(port, "dev2.appwrite.io", Record::TYPE_SOA);
    assert!(response.answers.is_empty());
    assert_eq!(response.authority.len(), 1);
    assert_eq!(response.authority[0].name, "appwrite.io");
    let rdata = &response.authority[0].rdata;
    assert!(rdata.contains("ns1.appwrite.zone"));
    assert!(rdata.contains("team.appwrite.io"));
    assert!(rdata.contains("1 7200 1800 1209600 3600"));
    server.stop();
}

#[test]
fn invalid_server() {
    let err = Client::new("not-ip-address", 5300, 5, false).unwrap_err();
    assert_eq!(err.to_string(), "Server must be an IP address.");
    let err = Client::new("ns1.digitalocean.com", 5300, 5, false).unwrap_err();
    assert_eq!(err.to_string(), "Server must be an IP address.");
    Client::new("172.64.52.210", 5300, 5, false).unwrap();
    Client::new("127.0.0.1", 5300, 5, false).unwrap();
    Client::new("::1", 5300, 5, false).unwrap();
    Client::new("2606:4700:52::ac40:34d2", 5300, 5, false).unwrap();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn tcp_fallback_after_udp_truncation() {
    let server = start_native().await;
    let udp_port = server.udp.unwrap().port();
    let tcp_port = server.tcp.unwrap().port();
    let question = Question::new("large.localhost", Record::TYPE_TXT);
    let query = Message::query(question, None, true).unwrap();
    let udp_client = Client::new("127.0.0.1", udp_port, 5, false).unwrap();
    let udp_response = udp_client.query(&query).unwrap();
    assert!(udp_response.header.truncated);
    let tcp_client = Client::new("127.0.0.1", tcp_port, 5, true).unwrap();
    let tcp_response = tcp_client.query(&query).unwrap();
    assert!(!tcp_response.header.truncated);
    assert_eq!(tcp_response.answers.len(), 8);
    assert!(tcp_response.answers.len() > udp_response.answers.len());
    server.stop();
}
