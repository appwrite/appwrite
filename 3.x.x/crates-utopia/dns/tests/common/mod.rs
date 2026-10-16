//! Shared helpers for PHP-oracle integration tests.

#![allow(dead_code)]
#![allow(clippy::too_many_arguments)]
#![allow(clippy::fn_params_excessive_bools)]

use std::net::SocketAddr;
use std::path::PathBuf;
use std::sync::Arc;
use std::time::Duration;

use tokio::task::JoinHandle;
use utopia_dns::adapter::native::{Native, Tcp, Transport, Udp};
use utopia_dns::adapter::swoole::{self, Swoole, Transport as SwooleTransport};
use utopia_dns::adapter::Adapter;
use utopia_dns::error::Error;
use utopia_dns::message::{Header, Message, Question, Record};
use utopia_dns::resolver::Resolver;
use utopia_dns::zone::{self, File, Zone};
use utopia_dns::{Protocol, Query, Server};

pub const DEFAULT_TTL: u32 = 3600;

pub fn resource(name: &str) -> String {
    let path = PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("tests/resources")
        .join(name);
    std::fs::read_to_string(&path).unwrap_or_else(|e| panic!("read {}: {e}", path.display()))
}

pub fn import(content: &str) -> Zone {
    File::import(content, None, DEFAULT_TTL).unwrap()
}

pub fn import_origin(content: &str, origin: &str) -> Zone {
    File::import(content, Some(origin), DEFAULT_TTL).unwrap()
}

pub fn soa(name: &str, rdata: &str, ttl: u32) -> Record {
    Record::new(name, Record::TYPE_SOA).ttl(ttl).rdata(rdata)
}

pub fn rec(name: &str, type_code: u16, ttl: u32, rdata: &str) -> Record {
    Record::new(name, type_code).ttl(ttl).rdata(rdata)
}

pub fn example_soa() -> Record {
    soa(
        "example.com",
        "ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300",
        3600,
    )
}

pub fn query(name: &str, type_code: u16) -> Message {
    Message::query(Question::new(name, type_code), Some(1), true).unwrap()
}

pub fn query_id(name: &str, type_code: u16, id: u16) -> Message {
    Message::query(Question::new(name, type_code), Some(id), true).unwrap()
}

#[allow(clippy::too_many_arguments)]
pub fn respond(
    header: &Header,
    rcode: u8,
    questions: Vec<Question>,
    answers: Vec<Record>,
    authority: Vec<Record>,
    additional: Vec<Record>,
    authoritative: bool,
    truncated: bool,
) -> Message {
    Message::response(
        header,
        rcode,
        questions,
        answers,
        authority,
        additional,
        authoritative,
        truncated,
        false,
    )
    .unwrap()
}

pub fn find_record(records: &[Record], type_code: u16) -> Option<&Record> {
    records.iter().find(|r| r.type_code == type_code)
}

/// Multi-zone resolver matching PHP `tests/resources/server.php`.
#[derive(Clone)]
pub struct MultiZone {
    pub zones: Vec<Zone>,
}

impl Resolver for MultiZone {
    fn resolve(&self, query: &Query) -> Result<Message, Error> {
        let message = &query.message;
        let Some(question) = message.questions.first() else {
            return Message::response(
                &message.header,
                Message::RCODE_FORMERR,
                Vec::new(),
                Vec::new(),
                Vec::new(),
                Vec::new(),
                true,
                false,
                false,
            );
        };
        let query_name = question.name.to_ascii_lowercase();
        for zone in &self.zones {
            if query_name == zone.name || query_name.ends_with(&format!(".{}", zone.name)) {
                return zone::Resolver::lookup(message, zone);
            }
        }
        Message::response(
            &message.header,
            Message::RCODE_NXDOMAIN,
            message.questions.clone(),
            Vec::new(),
            vec![self.zones[0].soa.clone()],
            Vec::new(),
            true,
            false,
            false,
        )
    }
}

pub fn appwrite_and_localhost() -> MultiZone {
    let records = vec![
        rec("dev.appwrite.io", Record::TYPE_A, 10, "180.12.3.24"),
        rec("dev2.appwrite.io", Record::TYPE_A, 1800, "142.6.0.1"),
        rec("dev2.appwrite.io", Record::TYPE_A, 1800, "142.6.0.2"),
        rec(
            "dev.appwrite.io",
            Record::TYPE_AAAA,
            20,
            "2001:0db8:0000:0000:0000:ff00:0042:8329",
        ),
        rec(
            "dev2.appwrite.io",
            Record::TYPE_AAAA,
            20,
            "2001:0db8:0000:0000:0000:ff00:0000:0001",
        ),
        rec(
            "dev2.appwrite.io",
            Record::TYPE_AAAA,
            20,
            "2001:0db8:0000:0000:0000:ff00:0000:0002",
        ),
        rec(
            "alias.appwrite.io",
            Record::TYPE_CNAME,
            30,
            "cloud.appwrite.io",
        ),
        rec(
            "dev.appwrite.io",
            Record::TYPE_TXT,
            30,
            "awesome-secret-key",
        ),
        Record::new("dev.appwrite.io", Record::TYPE_MX)
            .ttl(30)
            .rdata("mail.appwrite.io")
            .priority(10),
        rec(
            "dev.appwrite.io",
            Record::TYPE_CAA,
            30,
            "0 issue \"letsencrypt.org\"",
        ),
        rec("delegated.appwrite.io", Record::TYPE_NS, 30, "ns1.test.io"),
        rec("delegated.appwrite.io", Record::TYPE_NS, 30, "ns2.test.io"),
    ];
    let appwrite = Zone::new(
        "appwrite.io",
        records,
        soa(
            "appwrite.io",
            "ns1.appwrite.zone team.appwrite.io 1 7200 1800 1209600 3600",
            30,
        ),
    )
    .unwrap();
    let localhost = import(&resource("zone-valid-localhost.txt"));
    MultiZone {
        zones: vec![appwrite, localhost],
    }
}

pub struct TestServer<A: Adapter> {
    pub server: Arc<Server<A, MultiZone>>,
    pub udp: Option<SocketAddr>,
    pub tcp: Option<SocketAddr>,
    pub http: Option<SocketAddr>,
    task: JoinHandle<Result<(), Error>>,
}

impl<A: Adapter> TestServer<A> {
    pub fn stop(self) {
        self.server.stop();
        self.task.abort();
    }
}

pub async fn wait_addr<F>(mut f: F) -> SocketAddr
where
    F: FnMut() -> Option<SocketAddr>,
{
    let deadline = tokio::time::Instant::now() + Duration::from_secs(15);
    loop {
        if let Some(addr) = f() {
            return addr;
        }
        assert!(
            tokio::time::Instant::now() <= deadline,
            "timed out waiting for bind"
        );
        tokio::time::sleep(Duration::from_millis(5)).await;
    }
}

pub fn google_com_zone() -> Zone {
    Zone::new(
        "google.com",
        vec![
            rec("google.com", Record::TYPE_A, 300, "142.250.190.78"),
            rec(
                "google.com",
                Record::TYPE_AAAA,
                300,
                "2607:f8b0:4004:800::200e",
            ),
        ],
        soa(
            "google.com",
            "ns1.google.com dns-admin.google.com 1 7200 3600 1209600 300",
            300,
        ),
    )
    .unwrap()
}

pub async fn start_google_com() -> TestServer<Native> {
    let adapter = Native::new(vec![Transport::Udp(Udp::new("127.0.0.1", 0))]).unwrap();
    let server = Arc::new(Server::new(
        adapter,
        MultiZone {
            zones: vec![google_com_zone()],
        },
    ));
    let running = Arc::clone(&server);
    let task = tokio::spawn(async move { running.start_async().await });
    let udp = wait_addr(|| server.adapter().udp_addr()).await;
    TestServer {
        server,
        udp: Some(udp),
        tcp: None,
        http: None,
        task,
    }
}

pub async fn start_native() -> TestServer<Native> {
    let adapter = Native::new(vec![
        Transport::Udp(Udp::new("127.0.0.1", 0)),
        Transport::Tcp(Tcp::new("127.0.0.1", 0)),
    ])
    .unwrap();
    let server = Arc::new(Server::new(adapter, appwrite_and_localhost()));
    let running = Arc::clone(&server);
    let task = tokio::spawn(async move { running.start_async().await });
    let udp = wait_addr(|| server.adapter().udp_addr()).await;
    let tcp = wait_addr(|| server.adapter().tcp_addr()).await;
    TestServer {
        server,
        udp: Some(udp),
        tcp: Some(tcp),
        http: None,
        task,
    }
}

pub async fn start_proxy_tcp() -> TestServer<Native> {
    let adapter = Native::new(vec![Transport::Tcp(
        Tcp::new("127.0.0.1", 0).proxy_protocol(true),
    )])
    .unwrap();
    let server = Arc::new(Server::new(adapter, appwrite_and_localhost()));
    let running = Arc::clone(&server);
    let task = tokio::spawn(async move { running.start_async().await });
    let tcp = wait_addr(|| server.adapter().tcp_addr()).await;
    TestServer {
        server,
        udp: None,
        tcp: Some(tcp),
        http: None,
        task,
    }
}

pub async fn start_http() -> TestServer<Swoole> {
    let adapter = Swoole::new(
        vec![SwooleTransport::Http(
            swoole::Http::new("127.0.0.1", 0).unwrap(),
        )],
        1,
        30,
    )
    .unwrap();
    let server = Arc::new(Server::new(adapter, appwrite_and_localhost()));
    let running = Arc::clone(&server);
    let task = tokio::spawn(async move { running.start_async().await });
    let http = wait_addr(|| server.adapter().http_addr()).await;
    TestServer {
        server,
        udp: None,
        tcp: None,
        http: Some(http),
        task,
    }
}

pub fn rdatas(records: &[Record]) -> Vec<String> {
    records.iter().map(|r| r.rdata.clone()).collect()
}

pub fn header(
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

pub fn dns_query(name: &str, type_code: u16) -> Query {
    Query::new(query(name, type_code), "127.0.0.1", 53, Protocol::Udp)
}
