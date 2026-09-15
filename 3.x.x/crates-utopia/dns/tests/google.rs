//! Port of `tests/e2e/DNS/Resolver/GoogleTest.php`.
//! PHP talks UDP to 8.8.8.8; we point `Google::with_nameserver` at an in-process zone.

mod common;

use common::{dns_query, start_google_com};
use utopia_dns::message::Record;
use utopia_dns::resolver::{Google, Resolver};

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn resolve_google_a() {
    let server = start_google_com().await;
    let port = server.udp.unwrap().port();
    let resolver = Google::with_nameserver("127.0.0.1", port).unwrap();
    let response = resolver
        .resolve(&dns_query("google.com", Record::TYPE_A))
        .unwrap();
    assert!(!response.answers.is_empty());
    let record = &response.answers[0];
    assert_eq!(record.type_code, Record::TYPE_A);
    assert_eq!(record.name, "google.com");
    assert!(record.rdata.parse::<std::net::Ipv4Addr>().is_ok());
    server.stop();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn resolve_google_aaaa() {
    let server = start_google_com().await;
    let port = server.udp.unwrap().port();
    let resolver = Google::with_nameserver("127.0.0.1", port).unwrap();
    let response = resolver
        .resolve(&dns_query("google.com", Record::TYPE_AAAA))
        .unwrap();
    assert!(!response.answers.is_empty());
    let record = &response.answers[0];
    assert_eq!(record.type_code, Record::TYPE_AAAA);
    assert_eq!(record.name, "google.com");
    assert!(record.rdata.parse::<std::net::Ipv6Addr>().is_ok());
    server.stop();
}
