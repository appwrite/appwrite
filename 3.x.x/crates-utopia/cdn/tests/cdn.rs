//! Ports of `tests/Cdn/*.php`. HTTP adapters hit wiremock through utopia-client.

use std::sync::{Arc, Mutex};

use bytes::Bytes;
use http::{Request, Response};
use serde_json::{json, Value};
use utopia_cdn::prelude::*;
use utopia_cdn::{CdnError, HttpClient, OptionKind, UnsupportedOperation, UntypedOption};
use utopia_test_wiremock::{method, Mock, MockServer, RecordedRequest, ResponseTemplate};

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
        let matcher = method(http_method);
        self.rt.block_on(async {
            Mock::given(matcher)
                .respond_with(template)
                .mount(&self.server)
                .await;
        });
    }

    fn requests(&self) -> Vec<RecordedRequest> {
        self.rt
            .block_on(self.server.received_requests())
            .unwrap_or_default()
    }

    fn uri(&self) -> String {
        self.server.uri()
    }
}

fn json_body(request: &RecordedRequest) -> Value {
    if request.body.is_empty() {
        return Value::Null;
    }
    serde_json::from_slice(&request.body)
        .unwrap_or_else(|_| Value::String(String::from_utf8_lossy(&request.body).into_owned()))
}

fn header<'a>(request: &'a RecordedRequest, name: &str) -> Option<&'a str> {
    request
        .headers
        .get(name)
        .and_then(|value| value.to_str().ok())
}

struct RecordingAdapter {
    name: String,
    calls: Arc<Mutex<Vec<String>>>,
    supports_keys: bool,
    fails: bool,
}

impl Adapter for RecordingAdapter {
    fn purge_paths(&self, _domain: &str, _paths: &[String]) -> Result<(), CdnError> {
        self.record("paths")
    }
    fn purge_domain(&self, _domain: &str) -> Result<(), CdnError> {
        self.record("domain")
    }
    fn purge_keys(&self, _keys: &[String]) -> Result<(), CdnError> {
        if !self.supports_keys {
            return Err(UnsupportedOperation(format!("{} cannot purge keys.", self.name)).into());
        }
        self.record("keys")
    }
    fn purge_zone(&self) -> Result<(), CdnError> {
        self.record("zone")
    }
}

impl RecordingAdapter {
    fn record(&self, op: &str) -> Result<(), CdnError> {
        self.calls
            .lock()
            .unwrap()
            .push(format!("{}:{op}", self.name));
        if self.fails {
            return Err(CdnError::runtime(format!("{} purge failed.", self.name)));
        }
        Ok(())
    }
}

fn adapter(
    name: &str,
    calls: &Arc<Mutex<Vec<String>>>,
    supports_keys: bool,
    fails: bool,
) -> RecordingAdapter {
    RecordingAdapter {
        name: name.into(),
        calls: Arc::clone(calls),
        supports_keys,
        fails,
    }
}

struct FailClient;

impl HttpClient for FailClient {
    fn send_request(&self, _request: Request<Bytes>) -> Result<Response<Bytes>, String> {
        Err("connection refused".into())
    }
}

struct StubProvider {
    name: String,
    status: String,
    instant: bool,
    date: Option<String>,
    renew: bool,
    calls: Arc<Mutex<Vec<String>>>,
}

impl Provider for StubProvider {
    fn issue_certificate(
        &self,
        _cert_name: &str,
        _domain: &str,
        _domain_type: Option<&str>,
    ) -> Result<Option<String>, CdnError> {
        self.calls
            .lock()
            .unwrap()
            .push(format!("{}:issue", self.name));
        Ok(self.date.clone())
    }
    fn is_instant_generation(
        &self,
        _domain: &str,
        _domain_type: Option<&str>,
    ) -> Result<bool, CdnError> {
        Ok(self.instant)
    }
    fn get_certificate_status(
        &self,
        _domain: &str,
        _domain_type: Option<&str>,
    ) -> Result<String, CdnError> {
        Ok(self.status.clone())
    }
    fn is_renew_required(
        &self,
        _domain: &str,
        _domain_type: Option<&str>,
    ) -> Result<bool, CdnError> {
        Ok(self.renew)
    }
    fn delete_certificate(
        &self,
        _domain: &str,
        _domain_type: Option<&str>,
    ) -> Result<(), CdnError> {
        self.calls
            .lock()
            .unwrap()
            .push(format!("{}:delete", self.name));
        Ok(())
    }
}

// --- Cache facade (CacheTest.php) ---

#[test]
fn cache_delegates() {
    let calls = Arc::new(Mutex::new(Vec::new()));
    let cache = Cache::new(adapter("x", &calls, true, false));
    cache
        .purge_paths("example.com", &["/file.png".into()])
        .unwrap();
    cache.purge_domain("example.com").unwrap();
    cache.purge_keys(&["key".into()]).unwrap();
    cache.purge_zone().unwrap();
    assert_eq!(
        *calls.lock().unwrap(),
        ["x:paths", "x:domain", "x:keys", "x:zone"]
    );
}

#[test]
fn cache_rejects_invalid_input() {
    let cache = Cache::new(adapter("x", &Arc::new(Mutex::new(Vec::new())), true, false));
    let err = cache
        .purge_paths("https://example.com", &["relative".into()])
        .unwrap_err();
    assert!(matches!(err, CdnError::InvalidArgument(_)));
}

#[test]
fn domain_validate() {
    assert_eq!(Domain::validate("example.com").unwrap(), "example.com");
    assert_eq!(Domain::validate("localhost").unwrap(), "localhost");
    assert!(Domain::validate("").is_err());
    assert!(Domain::validate("EXAMPLE.com").is_err());
    assert!(Domain::validate("https://example.com").is_err());
    assert!(Domain::validate("example.com/").is_err());
    assert!(Domain::validate("example.com:443").is_err());
    assert!(Domain::validate("ex_ample.com").is_err());
    assert!(Domain::validate_paths(&["relative".into()]).is_err());
    assert!(Domain::validate_paths(&["/ok".into()]).is_ok());
}

// --- Adapter contract (AdapterTest.php) ---

#[test]
fn adapter_constants() {
    assert_eq!(FastlyCache::KEYS_PER_PURGE, 256);
    assert_eq!(CloudflareCache::KEYS_PER_PURGE, 30);
    assert_eq!(CloudflareCache::PATHS_PER_PURGE, 30);
}

// --- Cloudflare cache (Cache/Adapter/CloudflareTest.php) ---

#[test]
fn cloudflare_purges_paths_and_domain() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"success":true}"#);
    let cdn = CloudflareCache::new("zone-id", "token").with_api_base(harness.uri());
    cdn.purge_paths("example.com", &["/a".into(), "/b?x=1".into()])
        .unwrap();
    cdn.purge_domain("example.com").unwrap();
    let calls = harness.requests();
    assert_eq!(
        json_body(&calls[0]),
        json!({"files": ["https://example.com/a", "https://example.com/b?x=1"]})
    );
    assert_eq!(json_body(&calls[1]), json!({"hosts": ["example.com"]}));
    assert!(calls[1]
        .url
        .as_str()
        .ends_with("/zones/zone-id/purge_cache"));
    assert_eq!(
        header(&calls[0], "user-agent"),
        Some("Utopia CDN Cloudflare Adapter")
    );
    assert_eq!(header(&calls[0], "authorization"), Some("Bearer token"));
}

#[test]
fn cloudflare_batches_paths() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"success":true}"#);
    let n = CloudflareCache::PATHS_PER_PURGE + 1;
    CloudflareCache::new("zone", "token")
        .with_api_base(harness.uri())
        .purge_paths("example.com", &vec!["/a".into(); n])
        .unwrap();
    let calls = harness.requests();
    assert_eq!(calls.len(), 2);
    assert_eq!(
        json_body(&calls[0])["files"].as_array().unwrap().len(),
        CloudflareCache::PATHS_PER_PURGE
    );
    assert_eq!(json_body(&calls[1])["files"].as_array().unwrap().len(), 1);
}

#[test]
fn cloudflare_purges_cache_tags() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"success":true}"#);
    CloudflareCache::new("zone", "token")
        .with_api_base(harness.uri())
        .purge_keys(&["tag-a".into(), "tag-b".into()])
        .unwrap();
    assert_eq!(
        json_body(&harness.requests()[0]),
        json!({"tags": ["tag-a", "tag-b"]})
    );
}

#[test]
fn cloudflare_batches_cache_tags() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"success":true}"#);
    CloudflareCache::new("zone", "token")
        .with_api_base(harness.uri())
        .purge_keys(&vec!["tag".into(); CloudflareCache::KEYS_PER_PURGE + 1])
        .unwrap();
    assert_eq!(harness.requests().len(), 2);
}

#[test]
fn cloudflare_zone_purge() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"success":true}"#);
    CloudflareCache::new("zone", "token")
        .with_api_base(harness.uri())
        .purge_zone()
        .unwrap();
    assert_eq!(
        json_body(&harness.requests()[0]),
        json!({"purge_everything": true})
    );
}

#[test]
fn cloudflare_rejects_body_failure() {
    let harness = Harness::new();
    harness.mount(
        "POST",
        200,
        r#"{"success":false,"errors":[{"message":"Invalid zone"}]}"#,
    );
    let err = CloudflareCache::new("zone", "token")
        .with_api_base(harness.uri())
        .purge_domain("example.com")
        .unwrap_err();
    assert!(err
        .to_string()
        .contains("Cloudflare purge failed with status 200: Invalid zone"));
}

#[test]
fn cloudflare_empty_purges() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"success":true}"#);
    let cdn = CloudflareCache::new("zone", "token").with_api_base(harness.uri());
    cdn.purge_paths("example.com", &[]).unwrap();
    cdn.purge_keys(&[]).unwrap();
    assert!(harness.requests().is_empty());
}

#[test]
fn cloudflare_connection_error() {
    let err = CloudflareCache::new("zone", "token")
        .with_client(Arc::new(FailClient))
        .purge_domain("example.com")
        .unwrap_err();
    assert!(err
        .to_string()
        .contains("Cloudflare purge failed with status 0: connection refused"));
}

// --- Fastly cache (Cache/Adapter/FastlyTest.php) ---

#[test]
fn fastly_purges_paths_and_keys() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"status":"ok"}"#);
    let cdn = FastlyCache::new("token", "domain-")
        .with_service_id("service-id")
        .with_soft_purge(true)
        .with_api_base(harness.uri());
    cdn.purge_paths("example.com", &["/hello world?x=1".into()])
        .unwrap();
    cdn.purge_keys(&["key".into()]).unwrap();
    let calls = harness.requests();
    assert!(calls[0]
        .url
        .as_str()
        .contains("/purge/example.com/hello%20world?x=1"));
    assert!(calls[1].url.as_str().ends_with("/service/service-id/purge"));
    assert_eq!(json_body(&calls[1]), json!({"surrogate_keys": ["key"]}));
    assert_eq!(header(&calls[0], "fastly-soft-purge"), Some("1"));
    assert_eq!(header(&calls[0], "fastly-key"), Some("token"));
}

#[test]
fn fastly_domain_purge_targets_surrogate_key() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"status":"ok"}"#);
    FastlyCache::new("token", "domain-")
        .with_service_id("shared-service")
        .with_api_base(harness.uri())
        .purge_domain("example.com")
        .unwrap();
    let calls = harness.requests();
    assert!(calls[0]
        .url
        .as_str()
        .ends_with("/service/shared-service/purge"));
    assert_eq!(
        json_body(&calls[0]),
        json!({"surrogate_keys": ["domain-example.com"]})
    );
    assert_eq!(calls.len(), 1);
}

#[test]
fn fastly_domain_purge_bare_hostname_key() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"status":"ok"}"#);
    FastlyCache::new("token", "")
        .with_service_id("service-id")
        .with_api_base(harness.uri())
        .purge_domain("example.com")
        .unwrap();
    assert_eq!(
        json_body(&harness.requests()[0]),
        json!({"surrogate_keys": ["example.com"]})
    );
}

#[test]
fn fastly_keys_are_sent_unencoded() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"status":"ok"}"#);
    FastlyCache::new("token", "domain-")
        .with_service_id("service-id")
        .with_api_base(harness.uri())
        .purge_keys(&["domain-example.com-summer sale".into()])
        .unwrap();
    assert_eq!(
        json_body(&harness.requests()[0]),
        json!({"surrogate_keys": ["domain-example.com-summer sale"]})
    );
}

#[test]
fn fastly_keys_are_purged_in_batches() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"status":"ok"}"#);
    let keys: Vec<String> = (1..=FastlyCache::KEYS_PER_PURGE + 1)
        .map(|i| format!("key-{i}"))
        .collect();
    FastlyCache::new("token", "domain-")
        .with_service_id("service-id")
        .with_api_base(harness.uri())
        .purge_keys(&keys)
        .unwrap();
    let calls = harness.requests();
    assert_eq!(calls.len(), 2);
    assert_eq!(
        json_body(&calls[0])["surrogate_keys"]
            .as_array()
            .unwrap()
            .len(),
        FastlyCache::KEYS_PER_PURGE
    );
    assert_eq!(json_body(&calls[1])["surrogate_keys"], json!(["key-257"]));
}

#[test]
fn fastly_zone_purge() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"status":"ok"}"#);
    FastlyCache::new("token", "domain-")
        .with_service_id("service-id")
        .with_api_base(harness.uri())
        .purge_zone()
        .unwrap();
    assert!(harness.requests()[0]
        .url
        .as_str()
        .ends_with("/service/service-id/purge_all"));
}

#[test]
fn fastly_requires_service_id() {
    let cdn = FastlyCache::new("token", "domain-");
    assert!(matches!(
        cdn.purge_zone().unwrap_err(),
        CdnError::UnsupportedOperation(_)
    ));
    assert!(cdn
        .purge_keys(&["key".into()])
        .unwrap_err()
        .to_string()
        .contains("service ID"));
    assert!(matches!(
        cdn.purge_domain("example.com").unwrap_err(),
        CdnError::UnsupportedOperation(_)
    ));
}

#[test]
fn fastly_path_purge_works_without_service_id() {
    let harness = Harness::new();
    harness.mount("POST", 200, r#"{"status":"ok"}"#);
    FastlyCache::new("token", "domain-")
        .with_api_base(harness.uri())
        .purge_paths("example.com", &["/a.png".into()])
        .unwrap();
    assert!(harness.requests()[0]
        .url
        .as_str()
        .ends_with("/purge/example.com/a.png"));
}

#[test]
fn fastly_http_error() {
    let harness = Harness::new();
    harness.mount("POST", 400, r#"{"msg":"bad key"}"#);
    let err = FastlyCache::new("token", "domain-")
        .with_service_id("svc")
        .with_api_base(harness.uri())
        .purge_keys(&["k".into()])
        .unwrap_err();
    assert!(err
        .to_string()
        .contains("Fastly purge failed with status 400: bad key"));
}

// --- Balancer (Cache/Adapter/BalancerTest.php) ---

#[test]
fn balancer_fans_out_to_every_matching_option() {
    let calls = Arc::new(Mutex::new(Vec::new()));
    let mut balancer = OptionBalancer::new();
    balancer
        .add_option(CdnOption::new(
            adapter("fastly-edge", &calls, true, false),
            CdnOption::PROVIDER_FASTLY,
            true,
        ))
        .add_option(CdnOption::new(
            adapter("fastly-run", &calls, true, false),
            CdnOption::PROVIDER_FASTLY,
            false,
        ))
        .add_option(CdnOption::new(
            adapter("cloudflare", &calls, true, false),
            CdnOption::PROVIDER_CLOUDFLARE,
            false,
        ));
    let cache = Cache::new(Balancer::new(balancer.clone()));
    cache.purge_domain("example.com").unwrap();
    cache
        .purge_paths("example.com", &["/index.html".into()])
        .unwrap();
    cache.purge_keys(&["domain-example.com".into()]).unwrap();
    assert_eq!(
        *calls.lock().unwrap(),
        [
            "fastly-edge:domain",
            "fastly-run:domain",
            "cloudflare:domain",
            "fastly-edge:paths",
            "fastly-run:paths",
            "cloudflare:paths",
            "fastly-edge:keys",
            "fastly-run:keys",
            "cloudflare:keys",
        ]
    );
}

#[test]
fn balancer_zone_purge_respects_filters() {
    let calls = Arc::new(Mutex::new(Vec::new()));
    let mut balancer = OptionBalancer::new();
    balancer
        .add_option(CdnOption::new(
            adapter("fastly-edge", &calls, true, false),
            CdnOption::PROVIDER_FASTLY,
            true,
        ))
        .add_option(CdnOption::new(
            adapter("fastly-run", &calls, true, false),
            CdnOption::PROVIDER_FASTLY,
            false,
        ))
        .add_option(CdnOption::new(
            adapter("cloudflare", &calls, true, false),
            CdnOption::PROVIDER_CLOUDFLARE,
            false,
        ));
    balancer.add_filter(|option: &CdnOption| !option.is_edge());
    Cache::new(Balancer::new(balancer)).purge_zone().unwrap();
    assert_eq!(
        *calls.lock().unwrap(),
        ["fastly-run:zone".to_string(), "cloudflare:zone".into()]
    );
}

#[test]
fn balancer_filters_narrow_options() {
    let calls = Arc::new(Mutex::new(Vec::new()));
    let mut balancer = OptionBalancer::new();
    balancer
        .add_option(CdnOption::new(
            adapter("fastly-edge", &calls, true, false),
            CdnOption::PROVIDER_FASTLY,
            true,
        ))
        .add_option(CdnOption::new(
            adapter("fastly-run", &calls, true, false),
            CdnOption::PROVIDER_FASTLY,
            false,
        ))
        .add_option(CdnOption::new(
            adapter("cloudflare", &calls, true, false),
            CdnOption::PROVIDER_CLOUDFLARE,
            false,
        ));
    balancer
        .add_filter(|option: &CdnOption| {
            option.get_provider().unwrap() == CdnOption::PROVIDER_FASTLY
        })
        .add_filter(|option: &CdnOption| option.is_edge());
    Cache::new(Balancer::new(balancer))
        .purge_domain("example.com")
        .unwrap();
    assert_eq!(*calls.lock().unwrap(), ["fastly-edge:domain"]);
}

#[test]
fn balancer_custom_domains_reach_both_providers() {
    let calls = Arc::new(Mutex::new(Vec::new()));
    let mut balancer = OptionBalancer::new();
    balancer
        .add_option(CdnOption::new(
            adapter("fastly-edge", &calls, true, false),
            CdnOption::PROVIDER_FASTLY,
            true,
        ))
        .add_option(CdnOption::new(
            adapter("fastly-run", &calls, true, false),
            CdnOption::PROVIDER_FASTLY,
            false,
        ))
        .add_option(CdnOption::new(
            adapter("cloudflare", &calls, true, false),
            CdnOption::PROVIDER_CLOUDFLARE,
            false,
        ));
    balancer.add_filter(|option: &CdnOption| !option.is_edge());
    Cache::new(Balancer::new(balancer))
        .purge_domain("customer.example.com")
        .unwrap();
    assert_eq!(
        *calls.lock().unwrap(),
        ["fastly-run:domain".to_string(), "cloudflare:domain".into()]
    );
}

#[test]
fn balancer_one_failing_provider_still_purges_the_others() {
    let calls = Arc::new(Mutex::new(Vec::new()));
    let mut balancer = OptionBalancer::new();
    balancer
        .add_option(CdnOption::new(
            adapter("fastly", &calls, true, true),
            CdnOption::PROVIDER_FASTLY,
            false,
        ))
        .add_option(CdnOption::new(
            adapter("cloudflare", &calls, true, false),
            CdnOption::PROVIDER_CLOUDFLARE,
            false,
        ));
    let err = Cache::new(Balancer::new(balancer))
        .purge_keys(&["domain-example.com".into()])
        .unwrap_err();
    match err {
        CdnError::Purge(purge) => {
            assert_eq!(
                purge.get_message(),
                "Cache cache key purging failed for fastly."
            );
            assert_eq!(purge.get_errors().len(), 1);
        }
        other => panic!("{other:?}"),
    }
    assert_eq!(
        *calls.lock().unwrap(),
        ["fastly:keys".to_string(), "cloudflare:keys".into()]
    );
}

#[test]
fn balancer_unsupported_options_are_skipped() {
    let calls = Arc::new(Mutex::new(Vec::new()));
    let mut balancer = OptionBalancer::new();
    balancer
        .add_option(CdnOption::new(
            adapter("fastly-no-service", &calls, false, false),
            CdnOption::PROVIDER_FASTLY,
            false,
        ))
        .add_option(CdnOption::new(
            adapter("cloudflare", &calls, true, false),
            CdnOption::PROVIDER_CLOUDFLARE,
            false,
        ));
    Cache::new(Balancer::new(balancer))
        .purge_keys(&["domain-example.com".into()])
        .unwrap();
    assert_eq!(*calls.lock().unwrap(), ["cloudflare:keys"]);
}

#[test]
fn balancer_fails_when_every_option_is_unsupported() {
    let mut balancer = OptionBalancer::new();
    balancer.add_option(CdnOption::new(
        adapter("fastly", &Arc::new(Mutex::new(Vec::new())), false, false),
        CdnOption::PROVIDER_FASTLY,
        false,
    ));
    let err = Cache::new(Balancer::new(balancer))
        .purge_keys(&["k".into()])
        .unwrap_err();
    assert!(matches!(err, CdnError::UnsupportedOperation(_)));
}

#[test]
fn balancer_fails_when_no_option_matches() {
    let mut balancer = OptionBalancer::new();
    balancer
        .add_option(CdnOption::new(
            adapter("fastly", &Arc::new(Mutex::new(Vec::new())), true, false),
            CdnOption::PROVIDER_FASTLY,
            false,
        ))
        .add_filter(|option: &CdnOption| {
            option.get_provider().unwrap() == CdnOption::PROVIDER_CLOUDFLARE
        });
    let err = Cache::new(Balancer::new(balancer))
        .purge_domain("example.com")
        .unwrap_err();
    assert!(err.to_string().contains("No cache options matched"));
}

#[test]
fn balancer_rejects_untyped_options() {
    let mut balancer = OptionBalancer::new();
    balancer.add_option(OptionKind::Untyped(UntypedOption));
    let err = Cache::new(Balancer::new(balancer))
        .purge_domain("example.com")
        .unwrap_err();
    assert!(err.to_string().contains("must be instances of"));
}

#[test]
fn balancer_empty_purges_touch_no_provider() {
    let calls = Arc::new(Mutex::new(Vec::new()));
    let mut balancer = OptionBalancer::new();
    balancer.add_option(CdnOption::new(
        adapter("fastly", &calls, true, false),
        CdnOption::PROVIDER_FASTLY,
        false,
    ));
    let cache = Cache::new(Balancer::new(balancer));
    cache.purge_paths("example.com", &[]).unwrap();
    cache.purge_keys(&[]).unwrap();
    assert!(calls.lock().unwrap().is_empty());
}

#[test]
fn balancer_rejects_invalid_domain() {
    let mut balancer = OptionBalancer::new();
    balancer.add_option(CdnOption::new(
        adapter("fastly", &Arc::new(Mutex::new(Vec::new())), true, false),
        CdnOption::PROVIDER_FASTLY,
        false,
    ));
    let err = Balancer::new(balancer)
        .purge_domain("https://example.com")
        .unwrap_err();
    assert!(matches!(err, CdnError::InvalidArgument(_)));
}

// --- CdnOption (Extend/CdnOptionTest.php) ---

#[test]
fn cdn_option_typed_state() {
    let option = CdnOption::new(
        adapter("x", &Arc::new(Mutex::new(Vec::new())), true, false),
        CdnOption::PROVIDER_FASTLY,
        true,
    );
    assert_eq!(option.get_provider().unwrap(), "fastly");
    assert!(option.is_edge());
    assert!(option.get_adapter().is_ok());
    assert!(!CdnOption::new(
        adapter("x", &Arc::new(Mutex::new(Vec::new())), true, false),
        CdnOption::PROVIDER_CLOUDFLARE,
        false,
    )
    .is_edge());

    let mut option = CdnOption::new(
        adapter("x", &Arc::new(Mutex::new(Vec::new())), true, false),
        CdnOption::PROVIDER_FASTLY,
        false,
    );
    option.set_state(CdnOption::ADAPTER, "fastly");
    assert!(option.get_adapter().is_err());
}

#[test]
fn cdn_option_filters_on_typed_accessors() {
    let edge = CdnOption::new(
        adapter("e", &Arc::new(Mutex::new(Vec::new())), true, false),
        CdnOption::PROVIDER_FASTLY,
        true,
    );
    let run = CdnOption::new(
        adapter("r", &Arc::new(Mutex::new(Vec::new())), true, false),
        CdnOption::PROVIDER_FASTLY,
        false,
    );
    let cloudflare = CdnOption::new(
        adapter("c", &Arc::new(Mutex::new(Vec::new())), true, false),
        CdnOption::PROVIDER_CLOUDFLARE,
        false,
    );
    let mut balancer = OptionBalancer::new();
    balancer
        .add_option(edge)
        .add_option(run)
        .add_option(cloudflare);
    balancer.add_filter(|option: &CdnOption| !option.is_edge());
    let filtered = balancer.get_filtered_options();
    assert_eq!(filtered.len(), 2);
    assert_eq!(
        filtered[0].get_provider().unwrap(),
        CdnOption::PROVIDER_FASTLY
    );
    assert!(!filtered[0].is_edge());
    assert_eq!(
        filtered[1].get_provider().unwrap(),
        CdnOption::PROVIDER_CLOUDFLARE
    );
    balancer.add_filter(|option: &CdnOption| {
        option.get_provider().unwrap() == CdnOption::PROVIDER_CLOUDFLARE
    });
    let filtered = balancer.get_filtered_options();
    assert_eq!(filtered.len(), 1);
    assert_eq!(
        filtered[0].get_provider().unwrap(),
        CdnOption::PROVIDER_CLOUDFLARE
    );
    assert_eq!(
        balancer.run().unwrap().get_provider().unwrap(),
        CdnOption::PROVIDER_CLOUDFLARE
    );
}

// --- Certificates facade (CertificatesTest.php) ---

#[test]
fn certificates_delegates_issue() {
    struct Issue;
    impl Provider for Issue {
        fn issue_certificate(
            &self,
            _cert_name: &str,
            _domain: &str,
            domain_type: Option<&str>,
        ) -> Result<Option<String>, CdnError> {
            if domain_type == Some("pending") {
                Ok(None)
            } else {
                Ok(Some("2027-01-01 00:00:00.000".into()))
            }
        }
        fn is_instant_generation(
            &self,
            _domain: &str,
            _domain_type: Option<&str>,
        ) -> Result<bool, CdnError> {
            Ok(false)
        }
        fn get_certificate_status(
            &self,
            _domain: &str,
            _domain_type: Option<&str>,
        ) -> Result<String, CdnError> {
            Ok(Status::UNKNOWN.into())
        }
        fn is_renew_required(
            &self,
            _domain: &str,
            _domain_type: Option<&str>,
        ) -> Result<bool, CdnError> {
            Ok(false)
        }
        fn delete_certificate(
            &self,
            _domain: &str,
            _domain_type: Option<&str>,
        ) -> Result<(), CdnError> {
            Ok(())
        }
    }
    let certificates = Certificates::new(Issue);
    assert_eq!(
        certificates
            .issue_certificate("cert-name", "cdn.example.com", None)
            .unwrap()
            .as_deref(),
        Some("2027-01-01 00:00:00.000")
    );
}

// --- Proxy (Certificates/Provider/ProxyTest.php) ---

#[test]
fn proxy_routes_and_aggregates() {
    let calls = Arc::new(Mutex::new(Vec::new()));
    let mk = |name: &str, status: &str, instant: bool, date: Option<&str>, renew: bool| {
        Box::new(StubProvider {
            name: name.into(),
            status: status.into(),
            instant,
            date: date.map(str::to_owned),
            renew,
            calls: Arc::clone(&calls),
        })
    };
    let app = StubProvider {
        name: "app".into(),
        status: Status::ISSUED.into(),
        instant: false,
        date: None,
        renew: false,
        calls: Arc::clone(&calls),
    };
    let network = StubProvider {
        name: "network".into(),
        status: Status::PENDING.into(),
        instant: false,
        date: None,
        renew: true,
        calls: Arc::clone(&calls),
    };
    let proxy = Proxy::new(
        "app.example.com",
        app,
        network,
        vec![
            mk("cloudflare", Status::UNKNOWN, true, None, false),
            mk("fastly", Status::ISSUED, false, Some("2027-01-01"), false),
        ],
    )
    .unwrap();
    assert_eq!(
        proxy
            .issue_certificate("cert", "custom.example.com", None)
            .unwrap()
            .as_deref(),
        Some("2027-01-01")
    );
    assert!(!proxy
        .is_instant_generation("custom.example.com", None)
        .unwrap());
    assert_eq!(
        proxy
            .get_certificate_status("custom.example.com", None)
            .unwrap(),
        Status::ISSUED
    );
    assert!(proxy
        .is_renew_required("site.example.com", Some("site"))
        .unwrap());
    proxy.delete_certificate("app.example.com", None).unwrap();
    proxy
        .delete_certificate("site.example.com", Some("site"))
        .unwrap();
    let recorded = calls.lock().unwrap().clone();
    assert!(recorded.iter().any(|call| call == "app:delete"));
    assert!(recorded.iter().any(|call| call == "network:delete"));
}

#[test]
fn proxy_rejects_missing_custom_providers() {
    let provider = StubProvider {
        name: "app".into(),
        status: Status::ISSUED.into(),
        instant: false,
        date: None,
        renew: false,
        calls: Arc::new(Mutex::new(Vec::new())),
    };
    let proxy = Proxy::new(
        "app.example.com",
        provider,
        StubProvider {
            name: "net".into(),
            status: Status::ISSUED.into(),
            instant: false,
            date: None,
            renew: false,
            calls: Arc::new(Mutex::new(Vec::new())),
        },
        vec![],
    )
    .unwrap();
    let err = proxy
        .issue_certificate("cert", "custom.example.com", None)
        .unwrap_err();
    assert!(err
        .to_string()
        .contains("No certificate providers are configured for custom domains."));
}

// --- Cloudflare certificates ---

#[test]
fn cloudflare_creates_custom_hostname() {
    let harness = Harness::new();
    harness.mount("POST", 201, r#"{"success":true,"result":{"id":"host_1"}}"#);
    let provider = CloudflareCertificates::new("zone", "token").with_api_base(harness.uri());
    assert!(provider
        .issue_certificate("ignored", "example.com", None)
        .unwrap()
        .is_none());
    let body = json_body(&harness.requests()[0]);
    assert_eq!(body["hostname"], "example.com");
    assert_eq!(body["ssl"]["method"], "http");
}

#[test]
fn cloudflare_duplicate_hostname_is_idempotent() {
    let harness = Harness::new();
    harness.mount(
        "POST",
        409,
        r#"{"success":false,"errors":[{"code":1406,"message":"duplicate"}]}"#,
    );
    let provider = CloudflareCertificates::new("zone", "token").with_api_base(harness.uri());
    assert!(provider
        .issue_certificate("ignored", "example.com", None)
        .unwrap()
        .is_none());
}

#[test]
fn cloudflare_lookup_exact_match_and_delete() {
    let harness = Harness::new();
    harness.mount(
        "GET",
        200,
        r#"{"success":true,"result":[{"id":"wrong","hostname":"other.com"},{"id":"right","hostname":"example.com"}]}"#,
    );
    harness.mount("DELETE", 204, "");
    CloudflareCertificates::new("zone", "token")
        .with_api_base(harness.uri())
        .delete_certificate("example.com", None)
        .unwrap();
    let calls = harness.requests();
    assert!(calls[1].url.as_str().ends_with("/custom_hostnames/right"));
}

#[test]
fn cloudflare_renewal_and_unsupported_status() {
    let harness = Harness::new();
    harness.mount("GET", 200, r#"{"success":true,"result":[]}"#);
    let provider = CloudflareCertificates::new("zone", "token").with_api_base(harness.uri());
    assert!(provider.is_renew_required("example.com", None).unwrap());
    assert!(matches!(
        provider
            .get_certificate_status("example.com", None)
            .unwrap_err(),
        CdnError::UnsupportedOperation(_)
    ));
    assert!(provider.is_instant_generation("example.com", None).unwrap());
}

#[test]
fn cloudflare_rejects_malformed_lookup() {
    let harness = Harness::new();
    harness.mount("GET", 200, "invalid");
    let provider = CloudflareCertificates::new("zone", "token").with_api_base(harness.uri());
    assert!(provider.is_renew_required("example.com", None).is_err());
}

#[test]
fn cloudflare_delete_missing_id() {
    let harness = Harness::new();
    harness.mount(
        "GET",
        200,
        r#"{"success":true,"result":[{"hostname":"example.com"}]}"#,
    );
    let err = CloudflareCertificates::new("zone", "token")
        .with_api_base(harness.uri())
        .delete_certificate("example.com", None)
        .unwrap_err();
    assert!(err.to_string().contains("missing an ID"));
}

// --- Fastly TLS ---

#[test]
fn fastly_tls_creates_subscription_when_missing() {
    let harness = Harness::new();
    harness.mount("GET", 200, r#"{"data":[]}"#);
    harness.mount(
        "POST",
        200,
        r#"{"data":{"id":"sub_123","attributes":{"state":"pending"}}}"#,
    );
    let provider = FastlyTls::new("token", "tls-config-id")
        .with_authority("certainly")
        .with_api_base(harness.uri());
    assert!(provider
        .issue_certificate("ignored", "example.com", None)
        .unwrap()
        .is_none());
    let calls = harness.requests();
    assert_eq!(calls.len(), 2);
    assert_eq!(calls[0].method.as_str(), "GET");
    assert!(calls[0]
        .url
        .as_str()
        .contains("filter%5Btls_domains.id%5D=example.com"));
    assert_eq!(calls[1].method.as_str(), "POST");
    assert_eq!(
        json_body(&calls[1])["data"]["relationships"]["tls_configuration"]["data"]["id"],
        "tls-config-id"
    );
}

#[test]
fn fastly_tls_status_and_renew() {
    let harness = Harness::new();
    harness.mount(
        "GET",
        200,
        r#"{"data":[{"id":"sub_123","attributes":{"state":"issued"}}]}"#,
    );
    let provider = FastlyTls::new("token", "tls-config-id").with_api_base(harness.uri());
    assert_eq!(
        provider
            .get_certificate_status("example.com", None)
            .unwrap(),
        Status::ISSUED
    );
    assert!(!provider.is_renew_required("example.com", None).unwrap());
}

#[test]
fn fastly_tls_delete() {
    let harness = Harness::new();
    harness.mount(
        "GET",
        200,
        r#"{"data":[{"id":"sub_123","attributes":{"state":"issued"}}]}"#,
    );
    harness.mount("DELETE", 204, "");
    FastlyTls::new("token", "tls-config-id")
        .with_api_base(harness.uri())
        .delete_certificate("example.com", None)
        .unwrap();
    let calls = harness.requests();
    assert_eq!(calls.len(), 2);
    assert_eq!(calls[1].method.as_str(), "DELETE");
    assert!(calls[1]
        .url
        .as_str()
        .ends_with("/tls/subscriptions/sub_123"));
}

#[test]
fn fastly_tls_returns_renew_date() {
    let harness = Harness::new();
    let body = json!({
        "data": [{
            "id": "sub_123",
            "attributes": {"state": "issued"},
            "relationships": {"tls_certificates": {"data": [{"type": "tls_certificate", "id": "cert_1"}]}},
        }],
        "included": [{
            "type": "tls_certificate",
            "id": "cert_1",
            "attributes": {"not_after": "2027-02-01T00:00:00Z"},
        }],
    });
    harness.mount("GET", 200, &body.to_string());
    let date = FastlyTls::new("token", "tls-config-id")
        .with_api_base(harness.uri())
        .issue_certificate("cert", "example.com", None)
        .unwrap();
    assert_eq!(date.as_deref(), Some("2027-01-02 00:00:00.000"));
}

#[test]
fn fastly_tls_retries_failed_subscription() {
    let harness = Harness::new();
    harness.mount(
        "GET",
        200,
        r#"{"data":[{"id":"sub_123","attributes":{"state":"failed"}}]}"#,
    );
    harness.mount(
        "PATCH",
        200,
        r#"{"data":{"id":"sub_123","attributes":{"state":"processing"}}}"#,
    );
    FastlyTls::new("token", "config")
        .with_api_base(harness.uri())
        .issue_certificate("cert", "example.com", None)
        .unwrap();
    assert_eq!(harness.requests()[1].method.as_str(), "PATCH");
}

#[test]
fn fastly_tls_rejects_malformed_response() {
    let harness = Harness::new();
    harness.mount("GET", 200, "not-json");
    let err = FastlyTls::new("token", "config")
        .with_api_base(harness.uri())
        .get_certificate_status("example.com", None)
        .unwrap_err();
    assert!(err.to_string().contains("valid JSON"));
}

#[test]
fn fastly_tls_unknown_when_missing() {
    let harness = Harness::new();
    harness.mount("GET", 200, r#"{"data":[]}"#);
    let provider = FastlyTls::new("token", "config").with_api_base(harness.uri());
    assert_eq!(
        provider
            .get_certificate_status("example.com", None)
            .unwrap(),
        Status::UNKNOWN
    );
    assert!(provider.is_renew_required("example.com", None).unwrap());
    assert!(!provider.is_instant_generation("example.com", None).unwrap());
}
