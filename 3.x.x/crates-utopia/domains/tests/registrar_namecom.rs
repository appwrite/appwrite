use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};

use serde_json::{json, Value};
use utopia_domains::registrar::{Contact, NameCom};
use utopia_domains::{
    Cache, DomainsError, NoneCache, Registrar, TransferStatusEnum, UpdateDetails,
};
use utopia_test_wiremock::{
    method, path, path_regex, Mock, MockServer, RecordedRequest, Respond, ResponseTemplate,
};

static SEQ: AtomicU64 = AtomicU64::new(1);

fn runtime() -> tokio::runtime::Runtime {
    tokio::runtime::Builder::new_multi_thread()
        .worker_threads(2)
        .enable_all()
        .build()
        .expect("tokio runtime")
}

fn random_label() -> String {
    format!("nctest{}", SEQ.fetch_add(1, Ordering::SeqCst))
}

fn purchase_contact() -> HashMap<String, Contact> {
    let contact = Contact::new(
        "Test",
        "Tester",
        "+18031234567",
        "testing@test.com",
        "123 Main St",
        "Suite 100",
        "",
        "San Francisco",
        "CA",
        "US",
        "94105",
        "Test Inc",
        None,
    );
    HashMap::from([
        ("owner".into(), contact.clone()),
        ("admin".into(), contact.clone()),
        ("tech".into(), contact.clone()),
        ("billing".into(), contact),
    ])
}

struct NameComApi;

impl Respond for NameComApi {
    fn respond(&self, request: &RecordedRequest) -> ResponseTemplate {
        let path = request.url.path();
        let method = request.method.as_str();
        let body: Value = serde_json::from_slice(&request.body).unwrap_or(Value::Null);

        if path == "/core/v1/domains:checkAvailability" && method == "POST" {
            let domain = body
                .pointer("/domainNames/0")
                .and_then(Value::as_str)
                .unwrap_or("");
            let purchasable = domain != "google.com" && !domain.is_empty();
            return ResponseTemplate::new(200).set_body_json(json!({
                "results": [{ "domainName": domain, "purchasable": purchasable, "purchasePrice": 12.99 }]
            }));
        }

        if path == "/core/v1/domains" && method == "POST" {
            let domain = body
                .pointer("/domain/domainName")
                .and_then(Value::as_str)
                .unwrap_or("");
            if domain == "google.com" {
                return ResponseTemplate::new(400).set_body_json(json!({
                    "message": "Domain is not available"
                }));
            }
            let email = body
                .pointer("/domain/contacts/registrant/email")
                .and_then(Value::as_str)
                .unwrap_or("");
            if email == "invalid-email" {
                return ResponseTemplate::new(400).set_body_json(json!({
                    "message": "invalid value for email"
                }));
            }
            if request
                .headers
                .get("authorization")
                .is_some_and(|v| v.to_str().unwrap_or("").contains("invalid"))
            {
                return ResponseTemplate::new(401).set_body_json(json!({
                    "message": "Unauthorized"
                }));
            }
            return ResponseTemplate::new(200).set_body_json(json!({ "order": 4242 }));
        }

        if path == "/core/v1/domains:search" && method == "POST" {
            return ResponseTemplate::new(200).set_body_json(json!({
                "results": [
                    {
                        "domainName": "example.com",
                        "purchasable": true,
                        "purchasePrice": 12.99,
                        "renewalPrice": 14.99,
                        "purchaseType": "registration",
                        "premium": false
                    },
                    {
                        "domainName": "business.com",
                        "purchasable": true,
                        "purchasePrice": 500.0,
                        "renewalPrice": 14.99,
                        "purchaseType": "aftermarket",
                        "premium": true
                    }
                ]
            }));
        }

        if path == "/core/v1/transfers" && method == "POST" {
            let domain = body.get("domainName").and_then(Value::as_str).unwrap_or("");
            let tld = domain.rsplit('.').next().unwrap_or("");
            if tld.eq_ignore_ascii_case("in") || tld.eq_ignore_ascii_case("xyz") {
                return ResponseTemplate::new(400).set_body_json(json!({
                    "message": "do not support transfers for this tld"
                }));
            }
            return ResponseTemplate::new(200).set_body_json(json!({ "order": 99 }));
        }

        if path.contains(":getPricing") && method == "GET" {
            if path.contains("invalid.invalidtld") {
                return ResponseTemplate::new(422).set_body_json(json!({
                    "message": "unsupported tld"
                }));
            }
            if path.contains(".ai:") {
                return ResponseTemplate::new(400).set_body_json(json!({
                    "message": "Invalid value for years for this domain"
                }));
            }
            return ResponseTemplate::new(200).set_body_json(json!({
                "purchasePrice": 12.99,
                "renewalPrice": 14.99,
                "transferPrice": 12.99,
                "premium": false
            }));
        }

        if path.contains(":setNameservers") && method == "POST" {
            return ResponseTemplate::new(200).set_body_json(json!({
                "nameservers": ["ns1.name.com", "ns2.name.com"]
            }));
        }

        if path.contains(":renew") && method == "POST" {
            return ResponseTemplate::new(200).set_body_json(json!({
                "order": 7,
                "domain": { "expireDate": "2028-01-01T00:00:00Z" }
            }));
        }

        if path.contains(":getAuthCode") && method == "GET" {
            return ResponseTemplate::new(200).set_body_json(json!({ "authCode": "AUTH123" }));
        }

        if path.starts_with("/core/v1/transfers/") && method == "GET" {
            return ResponseTemplate::new(200).set_body_json(json!({
                "status": "completed",
                "statusDetails": "done",
                "created": "2024-01-01T00:00:00Z"
            }));
        }

        if path.starts_with("/core/v1/domains/") && method == "PATCH" {
            return ResponseTemplate::new(200).set_body_json(json!({ "autorenewEnabled": true }));
        }

        if path.starts_with("/core/v1/domains/") && method == "GET" {
            return ResponseTemplate::new(200).set_body_json(json!({
                "domainName": "example.com",
                "createDate": "2020-01-01T00:00:00Z",
                "expireDate": "2027-01-01T00:00:00Z",
                "autorenewEnabled": false,
                "nameservers": ["ns1.name.com", "ns2.name.com"]
            }));
        }

        ResponseTemplate::new(404).set_body_json(json!({ "message": "Not Found" }))
    }
}

fn mount_api(rt: &tokio::runtime::Runtime, server: &MockServer) {
    rt.block_on(async {
        Mock::given(path_regex(".*"))
            .respond_with_dyn(NameComApi)
            .mount(server)
            .await;
    });
}

fn registrar_for(server: &MockServer) -> Registrar {
    let adapter = NameCom::new("user", "token", server.uri());
    Registrar::new_with(
        adapter,
        vec!["ns1.name.com".into(), "ns2.name.com".into()],
        None,
        5,
        10,
    )
}

#[test]
fn namecom_request_shapes_and_base_api() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_api(&rt, &server);
    let registrar = registrar_for(&server);

    assert_eq!(registrar.get_name(), "namecom");
    assert!(registrar
        .available(&format!("{}.com", random_label()))
        .unwrap());
    assert!(!registrar.available("google.com").unwrap());

    let domain = format!("{}.com", random_label());
    let order = registrar
        .purchase(&domain, purchase_contact(), 1, Vec::new(), false, None)
        .unwrap();
    assert_eq!(order, "4242");

    let err = registrar
        .purchase("google.com", purchase_contact(), 1, Vec::new(), false, None)
        .unwrap_err();
    assert!(matches!(err, DomainsError::DomainTaken { .. }));

    let err = registrar
        .purchase(
            &format!("{}.com", random_label()),
            vec![Contact::new(
                "John",
                "Doe",
                "+1234567890",
                "invalid-email",
                "123 Main St",
                "Suite 100",
                "",
                "San Francisco",
                "CA",
                "InvalidCountry",
                "94105",
                "Test Inc",
                None,
            )],
            1,
            Vec::new(),
            false,
            None,
        )
        .unwrap_err();
    assert!(matches!(err, DomainsError::InvalidContact { .. }));

    let info = registrar.get_domain("owned.com").unwrap();
    assert_eq!(info.domain, "owned.com");
    assert!(info.created_at.is_some());
    assert!(info.expires_at.is_some());

    assert!(registrar.cancel_purchase().unwrap());
    assert!(registrar.tlds().unwrap().is_empty());

    let suggestions = registrar
        .suggest("example", vec!["com".into()], Some(5), None, None, None)
        .unwrap();
    assert!(!suggestions.is_empty());

    let price = registrar
        .get_price("example-test-domain.com", 1, Registrar::REG_TYPE_NEW, 3600)
        .unwrap();
    assert!(price.price > 0.0);

    let err = registrar
        .get_price("invalid.invalidtld", 1, Registrar::REG_TYPE_NEW, 3600)
        .unwrap_err();
    assert!(matches!(err, DomainsError::UnsupportedTld { .. }));

    let ns = registrar
        .update_nameservers(
            "owned.com",
            vec!["ns1.name.com".into(), "ns2.name.com".into()],
        )
        .unwrap();
    assert!(ns.successful);

    assert!(registrar
        .update_domain("owned.com", &UpdateDetails::new(Some(true)))
        .unwrap());

    let renewal = registrar.renew("owned.com", 1).unwrap();
    assert_eq!(renewal.order_id.as_deref(), Some("7"));

    let transfer = registrar.transfer(&format!("{}.com", random_label()), "code", None);
    assert!(
        transfer.is_ok()
            || matches!(
                transfer,
                Err(DomainsError::InvalidAuthCode { .. }
                    | DomainsError::DomainNotTransferable { .. })
            )
    );

    assert_eq!(registrar.get_auth_code("owned.com").unwrap(), "AUTH123");
    let status = registrar.check_transfer_status("owned.com").unwrap();
    assert_eq!(status.status, TransferStatusEnum::Completed);

    let requests = rt.block_on(server.received_requests()).expect("requests");
    assert!(requests
        .iter()
        .any(|r| r.url.path() == "/core/v1/domains:checkAvailability"));
    assert!(requests
        .iter()
        .any(|r| r.url.path() == "/core/v1/domains" && r.method.as_str() == "POST"));
    assert!(requests.iter().any(|r| {
        r.headers
            .get("authorization")
            .is_some_and(|v| v.to_str().unwrap_or("").starts_with("Basic "))
    }));
}

#[test]
fn namecom_invalid_credentials() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("POST"))
            .and(path("/core/v1/domains"))
            .respond_with(ResponseTemplate::new(401).set_body_json(json!({
                "message": "Unauthorized"
            })))
            .mount(&server)
            .await;
    });
    let adapter = NameCom::new("invalid-username", "invalid-token", server.uri());
    let registrar = Registrar::new_with(
        adapter,
        vec!["ns1.name.com".into(), "ns2.name.com".into()],
        None,
        5,
        10,
    );
    let err = registrar
        .purchase(
            &format!("{}.com", random_label()),
            purchase_contact(),
            1,
            Vec::new(),
            false,
            None,
        )
        .unwrap_err();
    assert!(matches!(err, DomainsError::Auth { .. }));
    assert_eq!(err.to_string(), "Failed to purchase domain: Unauthorized");
}

#[test]
fn namecom_suggest_filters() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_api(&rt, &server);
    let registrar = registrar_for(&server);
    let premium = registrar
        .suggest(
            "business",
            vec!["com".into()],
            Some(5),
            Some("premium"),
            Some(10000),
            Some(100),
        )
        .unwrap();
    for data in premium.values() {
        assert_eq!(data.kind, "premium");
        if let Some(price) = data.price {
            assert!((100.0..=10000.0).contains(&price));
        }
    }
    let suggestions = registrar
        .suggest(
            "testdomain",
            vec!["com".into()],
            Some(5),
            Some("suggestion"),
            None,
            None,
        )
        .unwrap();
    for data in suggestions.values() {
        assert_eq!(data.kind, "suggestion");
    }
}

#[test]
fn namecom_transfer_unsupported_tlds() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_api(&rt, &server);
    let registrar = registrar_for(&server);
    let err = registrar
        .transfer(&format!("{}.in", random_label()), "test-auth-code", None)
        .unwrap_err();
    assert!(matches!(err, DomainsError::UnsupportedTld { .. }));
    let err = registrar
        .transfer(&format!("{}.xyz", random_label()), "test-auth-code", None)
        .unwrap_err();
    assert!(matches!(err, DomainsError::UnsupportedTld { .. }));
}

#[test]
fn namecom_get_price_invalid_period() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_api(&rt, &server);
    let registrar = registrar_for(&server);
    let domain = format!("{}.ai", random_label());
    let err = registrar
        .get_price(&domain, 1, Registrar::REG_TYPE_NEW, 3600)
        .unwrap_err();
    assert!(matches!(err, DomainsError::InvalidPeriod { .. }));
    assert_eq!(
        err.to_string(),
        format!(
            "Failed to get price for domain: {domain} - Invalid value for years for this domain"
        )
    );
}

#[test]
fn namecom_price_with_cache() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_api(&rt, &server);
    let adapter = NameCom::new("user", "token", server.uri());
    let registrar = Registrar::new_with(
        adapter,
        vec!["ns1.name.com".into()],
        Some(Cache::new(NoneCache::new())),
        5,
        10,
    );
    let a = registrar
        .get_price("example-test-domain.com", 1, Registrar::REG_TYPE_NEW, 3600)
        .unwrap();
    let b = registrar
        .get_price("example-test-domain.com", 1, Registrar::REG_TYPE_NEW, 7200)
        .unwrap();
    assert_eq!(a, b);
}

#[test]
fn namecom_live() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("POST"))
            .and(path("/core/v1/domains:checkAvailability"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
                "results": [{ "domainName": "google.com", "purchasable": false, "purchasePrice": 12.99 }]
            })))
            .mount(&server)
            .await;
    });
    let adapter = NameCom::new("user", "token", server.uri());
    let registrar = Registrar::new_with(
        adapter,
        vec!["ns1.name.com".into(), "ns2.name.com".into()],
        None,
        5,
        10,
    );
    assert_eq!(registrar.get_name(), "namecom");
    let _ = registrar.available("google.com").unwrap();
}
