use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};

use utopia_domains::registrar::{Contact, OpenSrs};
use utopia_domains::{
    Cache, DomainsError, NoneCache, Registrar, TransferStatusEnum, UpdateDetails,
};
use utopia_test_wiremock::{method, Mock, MockServer, RecordedRequest, Respond, ResponseTemplate};

static SEQ: AtomicU64 = AtomicU64::new(1);

fn runtime() -> tokio::runtime::Runtime {
    tokio::runtime::Builder::new_multi_thread()
        .worker_threads(2)
        .enable_all()
        .build()
        .expect("tokio runtime")
}

fn random_label() -> String {
    format!("ostest{}", SEQ.fetch_add(1, Ordering::SeqCst))
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

fn ops_ok(code: &str, extra_attrs: &str) -> String {
    format!(
        r#"<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<OPS_envelope>
<header><version>0.9</version></header>
<body>
<data_block>
<dt_assoc>
<item key="protocol">XCP</item>
<item key="is_success">1</item>
<item key="response_code">{code}</item>
<item key="response_text">Command successful</item>
<item key="attributes">
<dt_assoc>
{extra_attrs}
</dt_assoc>
</item>
</dt_assoc>
</data_block>
</body>
</OPS_envelope>"#
    )
}

fn ops_err(code: i64, text: &str) -> String {
    format!(
        r#"<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<OPS_envelope>
<header><version>0.9</version></header>
<body>
<data_block>
<dt_assoc>
<item key="is_success">0</item>
<item key="response_code">{code}</item>
<item key="response_text">{text}</item>
</dt_assoc>
</data_block>
</body>
</OPS_envelope>"#
    )
}

struct OpenSrsApi;

impl Respond for OpenSrsApi {
    fn respond(&self, request: &RecordedRequest) -> ResponseTemplate {
        let body = String::from_utf8_lossy(&request.body);
        let xml = if body.contains("<item key='action'>LOOKUP</item>")
            || body.contains("<item key=\"action\">LOOKUP</item>")
        {
            if body.contains("google.com") {
                ops_ok("211", "")
            } else {
                ops_ok("210", "")
            }
        } else if body.contains("SW_REGISTER") {
            if body.contains("google.com") {
                ops_err(485, "Domain taken")
            } else if body.contains("Invalid password")
                || body.contains("<item key='reg_password'>password</item>")
            {
                ops_err(465, "Invalid password")
            } else if body.contains("invalid-email") {
                ops_err(465, "Invalid data")
            } else if body.contains("reg_type") && body.contains("transfer") {
                if body.contains("kffsfudlvc.net") {
                    ops_err(
                        487,
                        "Cannot transfer\nDomain already exists in this account",
                    )
                } else {
                    ops_err(487, "Cannot transfer\nDomain not registered")
                }
            } else {
                ops_ok(
                    "200",
                    r#"<item key="id">order-1</item><item key="domain_id">d-1</item>"#,
                )
            }
        } else if body.contains("NAME_SUGGEST") {
            ops_ok(
                "200",
                r#"<item key="suggestion">
<dt_assoc>
<item key="items">
<dt_array>
<item key="0">
<dt_assoc>
<item key="domain">monkeys.com</item>
<item key="status">available</item>
</dt_assoc>
</item>
</dt_array>
</item>
</dt_assoc>
</item>
<item key="premium">
<dt_assoc>
<item key="items">
<dt_array>
<item key="0">
<dt_assoc>
<item key="domain">computer.com</item>
<item key="status">available</item>
<item key="price">250.00</item>
</dt_assoc>
</item>
</dt_array>
</item>
</dt_assoc>
</item>"#,
            )
        } else if body.contains("GET_PRICE") {
            if body.contains("invalid.invalidtld") {
                ops_err(400, "Price not found")
            } else {
                ops_ok("200", r#"<item key="price">12.99</item>"#)
            }
        } else if body.contains("domain_auth_info") {
            ops_ok("200", r#"<item key="domain_auth_info">EPPCODE</item>"#)
        } else if body.contains("all_info") {
            ops_ok(
                "200",
                r#"<item key="registry_createdate">2020-01-01 00:00:00</item>
<item key="registry_expiredate">2027-01-01 00:00:00</item>
<item key="auto_renew">0</item>
<item key="nameserver_list">
<dt_array>
<item key="0">
<dt_assoc>
<item key="name">ns1.systemdns.com</item>
</dt_assoc>
</item>
</dt_array>
</item>"#,
            )
        } else if body.contains("MODIFY") {
            ops_ok("220", "")
        } else if body.contains("RENEW") {
            ops_ok(
                "200",
                r#"<item key="order_id">renew-1</item>
<item key="registration expiration date">2028-01-01 00:00:00</item>"#,
            )
        } else if body.contains("CHECK_TRANSFER") {
            ops_ok(
                "200",
                r#"<item key="transferrable">1</item>
<item key="noservice">0</item>
<item key="reason">ok</item>
<item key="status">pending_owner</item>"#,
            )
        } else {
            // ADVANCED_UPDATE_NAMESERVERS, CANCEL_PENDING_ORDERS, and other
            // success-path ops return the same empty 200 envelope.
            ops_ok("200", "")
        };
        ResponseTemplate::new(200)
            .insert_header("content-type", "text/xml")
            .set_body_string(xml)
    }
}

fn mount(rt: &tokio::runtime::Runtime, server: &MockServer) {
    rt.block_on(async {
        Mock::given(method("POST"))
            .respond_with_dyn(OpenSrsApi)
            .mount(server)
            .await;
    });
}

fn registrar_for(server: &MockServer) -> Registrar {
    let adapter = OpenSrs::new("apikey", "username", "secret-pass", server.uri());
    Registrar::new_with(
        adapter,
        vec!["ns1.systemdns.com".into(), "ns2.systemdns.com".into()],
        None,
        5,
        10,
    )
}

#[test]
fn opensrs_request_shapes_and_base_api() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount(&rt, &server);
    let registrar = registrar_for(&server);

    assert_eq!(registrar.get_name(), "opensrs");
    assert!(registrar
        .available(&format!("{}.net", random_label()))
        .unwrap());
    assert!(!registrar.available("google.com").unwrap());

    let domain = format!("{}.net", random_label());
    let order = registrar
        .purchase(&domain, purchase_contact(), 1, Vec::new(), false, None)
        .unwrap();
    assert_eq!(order, "order-1");

    let err = registrar
        .purchase("google.com", purchase_contact(), 1, Vec::new(), false, None)
        .unwrap_err();
    assert!(matches!(err, DomainsError::DomainTaken { .. }));

    let info = registrar.get_domain("kffsfudlvc.net").unwrap();
    assert_eq!(info.domain, "kffsfudlvc.net");
    assert!(info.created_at.is_some());
    assert!(info.expires_at.is_some());

    assert!(registrar.cancel_purchase().unwrap());
    assert!(registrar.tlds().unwrap().is_empty());

    let suggestions = registrar
        .suggest(
            "example",
            vec!["com".into(), "net".into(), "org".into()],
            Some(5),
            None,
            None,
            None,
        )
        .unwrap();
    assert!(!suggestions.is_empty());

    let price = registrar
        .get_price("example.net", 1, Registrar::REG_TYPE_NEW, 3600)
        .unwrap();
    assert!(price.price > 0.0);

    let err = registrar
        .get_price("invalid.invalidtld", 1, Registrar::REG_TYPE_NEW, 3600)
        .unwrap_err();
    assert!(matches!(err, DomainsError::PriceNotFound { .. }));

    let ns = registrar
        .update_nameservers(
            "kffsfudlvc.net",
            vec!["ns1.systemdns.com".into(), "ns2.systemdns.com".into()],
        )
        .unwrap();
    assert!(ns.successful);

    assert!(registrar
        .update_domain("kffsfudlvc.net", &UpdateDetails::new(Some(true)))
        .unwrap());

    let renewal = registrar.renew("kffsfudlvc.net", 1).unwrap();
    assert_eq!(renewal.order_id.as_deref(), Some("renew-1"));

    let err = registrar
        .transfer(&format!("{}.net", random_label()), "test-auth-code", None)
        .unwrap_err();
    assert!(matches!(err, DomainsError::DomainNotTransferable { .. }));
    assert_eq!(err.code(), OpenSrs::RESPONSE_CODE_DOMAIN_NOT_TRANSFERABLE);
    assert_eq!(
        err.to_string(),
        "Domain is not transferable: Domain not registered"
    );

    assert_eq!(
        registrar.get_auth_code("kffsfudlvc.net").unwrap(),
        "EPPCODE"
    );
    let status = registrar.check_transfer_status("kffsfudlvc.net").unwrap();
    assert_eq!(status.status, TransferStatusEnum::Transferrable);

    let requests = rt.block_on(server.received_requests()).expect("requests");
    assert!(requests.iter().any(|r| {
        let body = String::from_utf8_lossy(&r.body);
        body.contains("LOOKUP") && r.headers.get("x-signature").is_some()
    }));
    assert!(requests.iter().any(|r| {
        r.headers
            .get("x-username")
            .is_some_and(|v| v.to_str().unwrap_or("") == "username")
    }));
}

#[test]
fn opensrs_invalid_password() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount(&rt, &server);
    let adapter = OpenSrs::new("apikey", "username", "password", server.uri());
    let registrar = Registrar::new_with(
        adapter,
        vec!["ns1.systemdns.com".into(), "ns2.systemdns.com".into()],
        None,
        5,
        10,
    );
    let err = registrar
        .purchase(
            &format!("{}.net", random_label()),
            purchase_contact(),
            1,
            Vec::new(),
            false,
            None,
        )
        .unwrap_err();
    assert!(matches!(err, DomainsError::Auth { .. }));
    assert_eq!(
        err.to_string(),
        "Failed to purchase domain: Invalid password"
    );
}

#[test]
fn opensrs_suggest_filters() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount(&rt, &server);
    let registrar = registrar_for(&server);
    let result = registrar
        .suggest(
            vec!["monkeys", "kittens"],
            vec!["com".into(), "net".into(), "org".into()],
            Some(5),
            Some("suggestion"),
            None,
            None,
        )
        .unwrap();
    for data in result.values() {
        assert_eq!(data.kind, "suggestion");
    }
    let premium = registrar
        .suggest(
            "computer",
            vec!["com".into(), "net".into()],
            Some(5),
            Some("premium"),
            Some(10000),
            Some(100),
        )
        .unwrap();
    assert!(premium.len() <= 5);
    for data in premium.values() {
        assert_eq!(data.kind, "premium");
        if let Some(price) = data.price {
            assert!((100.0..=10000.0).contains(&price));
        }
    }
}

#[test]
fn opensrs_transfer_not_registered() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount(&rt, &server);
    let registrar = registrar_for(&server);
    let err = registrar
        .transfer(&format!("{}.net", random_label()), "test-auth-code", None)
        .unwrap_err();
    assert!(matches!(err, DomainsError::DomainNotTransferable { .. }));
    assert_eq!(err.code(), OpenSrs::RESPONSE_CODE_DOMAIN_NOT_TRANSFERABLE);
    assert_eq!(
        err.to_string(),
        "Domain is not transferable: Domain not registered"
    );
}

#[test]
fn opensrs_transfer_already_exists() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount(&rt, &server);
    let registrar = registrar_for(&server);
    let err = registrar
        .transfer("kffsfudlvc.net", "test-auth-code", None)
        .unwrap_err();
    assert!(matches!(err, DomainsError::DomainNotTransferable { .. }));
    assert!(err
        .to_string()
        .contains("Domain is not transferable: Domain already exists"));
}

#[test]
fn opensrs_price_with_cache() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount(&rt, &server);
    let adapter = OpenSrs::new("apikey", "username", "secret-pass", server.uri());
    let registrar = Registrar::new_with(
        adapter,
        vec!["ns1.systemdns.com".into()],
        Some(Cache::new(NoneCache::new())),
        5,
        10,
    );
    let a = registrar
        .get_price("example.net", 1, Registrar::REG_TYPE_NEW, 3600)
        .unwrap();
    let b = registrar
        .get_price("example.net", 1, Registrar::REG_TYPE_NEW, 7200)
        .unwrap();
    assert_eq!(a, b);
}

#[test]
fn opensrs_live() {
    let adapter = OpenSrs::new(
        "test-key",
        "test-user",
        "password",
        "https://horizon.opensrs.net:55443",
    );
    let registrar = Registrar::new_with(
        adapter,
        vec!["ns1.systemdns.com".into(), "ns2.systemdns.com".into()],
        None,
        5,
        10,
    );
    assert_eq!(registrar.get_name(), "opensrs");
}
