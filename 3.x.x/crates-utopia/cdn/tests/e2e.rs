//! Provider e2e against the WireMock container (PHP hits live CDN APIs).

use utopia_cdn::{
    Cache, Certificates, CloudflareCache, CloudflareCertificates, FastlyCache, FastlyTls,
};
use utopia_test_wiremock::{method, Mock, MockServer, ResponseTemplate};

struct Harness {
    rt: tokio::runtime::Runtime,
    server: MockServer,
}

impl Harness {
    fn new() -> Self {
        let rt = tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
            .expect("tokio");
        let server = rt.block_on(MockServer::start());
        Self { rt, server }
    }

    fn mount(&self, http_method: &str, status: u16, body: &str) {
        let template = ResponseTemplate::new(status).set_body_string(body.to_owned());
        self.rt.block_on(async {
            Mock::given(method(http_method))
                .respond_with(template)
                .mount(&self.server)
                .await;
        });
    }

    fn uri(&self) -> String {
        self.server.uri()
    }
}

#[test]
fn live_cloudflare_purge_domain() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"success":true}"#);
    Cache::new(CloudflareCache::new("zone", "token").with_api_base(harness.uri()))
        .purge_domain("example.com")
        .expect("cloudflare purge_domain");
}

#[test]
fn live_fastly_purge_paths() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"status":"ok"}"#);
    Cache::new(
        FastlyCache::new("token", "")
            .with_service_id("svc")
            .with_api_base(harness.uri()),
    )
    .purge_paths("example.com", &["/".into()])
    .expect("fastly purge_paths");
}

#[test]
fn live_cloudflare_certificate_status_unsupported() {
    let err = Certificates::new(CloudflareCertificates::new("zone", "token"))
        .get_certificate_status("example.com", None)
        .unwrap_err();
    assert!(err.to_string().contains("not supported"));
}

#[test]
fn live_fastly_tls_status() {
    let harness = Harness::new();
    harness.mount(
        "GET",
        200,
        r#"{"data":[{"id":"sub_123","attributes":{"state":"issued"}}]}"#,
    );
    let status =
        Certificates::new(FastlyTls::new("token", "tls-config").with_api_base(harness.uri()))
            .get_certificate_status("example.com", None)
            .expect("fastly tls status");
    assert!(!status.is_empty());
}
