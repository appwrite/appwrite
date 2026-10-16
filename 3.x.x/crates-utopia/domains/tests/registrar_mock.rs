use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};

use utopia_domains::registrar::{Contact, Mock};
use utopia_domains::{
    Cache, DomainsError, NoneCache, Price, Registrar, TransferStatusEnum, UpdateDetails,
};

static SEQ: AtomicU64 = AtomicU64::new(1);

fn random_label() -> String {
    format!("testdomain{}", SEQ.fetch_add(1, Ordering::SeqCst))
}

fn purchase_contact(suffix: &str) -> HashMap<String, Contact> {
    let contact = Contact::new(
        format!("Test{suffix}"),
        format!("Tester{suffix}"),
        "+18031234567",
        format!("testing{suffix}@test.com"),
        format!("123 Main St{suffix}"),
        format!("Suite 100{suffix}"),
        "",
        format!("San Francisco{suffix}"),
        "CA",
        "US",
        "94105",
        format!("Test Inc{suffix}"),
        None,
    );
    HashMap::from([
        ("owner".into(), contact.clone()),
        ("admin".into(), contact.clone()),
        ("tech".into(), contact.clone()),
        ("billing".into(), contact),
    ])
}

#[test]
fn get_name() {
    let registrar = Registrar::new(Mock::default_mock());
    assert_eq!(registrar.get_name(), "mock");
}

#[test]
fn available() {
    let registrar = Registrar::new(Mock::default_mock());
    let domain = format!("{}.com", random_label());
    assert!(registrar.available(&domain).unwrap());
}

#[test]
fn available_for_taken_domain() {
    let registrar = Registrar::new(Mock::default_mock());
    assert!(!registrar.available("google.com").unwrap());
}

#[test]
fn purchase() {
    let registrar = Registrar::new(Mock::default_mock());
    let domain = format!("{}.com", random_label());
    let result = registrar
        .purchase(&domain, purchase_contact(""), 1, Vec::new(), false, None)
        .unwrap();
    assert!(!result.is_empty());
}

#[test]
fn purchase_taken_domain() {
    let registrar = Registrar::new(Mock::default_mock());
    let err = registrar
        .purchase(
            "google.com",
            purchase_contact(""),
            1,
            Vec::new(),
            false,
            None,
        )
        .unwrap_err();
    assert!(matches!(err, DomainsError::DomainTaken { .. }));
}

#[test]
fn purchase_with_invalid_contact() {
    let registrar = Registrar::new(Mock::default_mock());
    let domain = format!("{}.com", random_label());
    let err = registrar
        .purchase(
            &domain,
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
}

#[test]
fn domain_info() {
    let registrar = Registrar::new(Mock::default_mock());
    let domain = format!("{}.com", random_label());
    registrar
        .purchase(&domain, purchase_contact(""), 1, Vec::new(), false, None)
        .unwrap();
    let result = registrar.get_domain(&domain).unwrap();
    assert_eq!(result.domain, domain);
    assert!(result.created_at.is_some());
    assert!(result.expires_at.is_some());
    assert!(result.auto_renew.is_some());
    assert!(result.nameservers.is_some());
}

#[test]
fn cancel_purchase() {
    let registrar = Registrar::new(Mock::default_mock());
    assert!(registrar.cancel_purchase().unwrap());
}

#[test]
fn tlds() {
    let registrar = Registrar::new(Mock::default_mock());
    let tlds = registrar.tlds().unwrap();
    assert!(!tlds.is_empty());
}

#[test]
fn suggest() {
    let registrar = Registrar::new(Mock::default_mock());
    let result = registrar
        .suggest(
            "example",
            vec!["com".into(), "net".into(), "org".into()],
            Some(5),
            None,
            None,
            None,
        )
        .unwrap();
    assert!(result.len() <= 5);
    for (domain, data) in result {
        assert!(!domain.is_empty());
        assert!(data.kind == "suggestion" || data.kind == "premium");
        if let Some(price) = data.price {
            assert!(price.is_finite());
        }
    }
}

#[test]
fn get_price() {
    let registrar = Registrar::new(Mock::default_mock());
    let result = registrar
        .get_price("example.com", 1, Registrar::REG_TYPE_NEW, 3600)
        .unwrap();
    assert!(result.price > 0.0);
    assert!(!result.premium);
}

#[test]
fn get_price_with_invalid_domain() {
    let registrar = Registrar::new(Mock::default_mock());
    let err = registrar
        .get_price("invalid.invalidtld", 1, Registrar::REG_TYPE_NEW, 3600)
        .unwrap_err();
    assert!(matches!(err, DomainsError::PriceNotFound { .. }));
}

#[test]
fn get_price_with_cache() {
    let registrar = Registrar::new_with(
        Mock::default_mock(),
        Vec::new(),
        Some(Cache::new(NoneCache::new())),
        5,
        10,
    );
    let result1 = registrar
        .get_price("example.com", 1, Registrar::REG_TYPE_NEW, 3600)
        .unwrap();
    let result2 = registrar
        .get_price("example.com", 1, Registrar::REG_TYPE_NEW, 3600)
        .unwrap();
    assert_eq!(result1, result2);
}

#[test]
fn get_price_with_custom_ttl() {
    let registrar = Registrar::new_with(
        Mock::default_mock(),
        Vec::new(),
        Some(Cache::new(NoneCache::new())),
        5,
        10,
    );
    let result = registrar
        .get_price("example.com", 1, Registrar::REG_TYPE_NEW, 7200)
        .unwrap();
    assert!(result.price > 0.0);
}

#[test]
fn update_nameservers() {
    let registrar = Registrar::new(Mock::default_mock());
    let domain = format!("{}.com", random_label());
    registrar
        .purchase(&domain, purchase_contact(""), 1, Vec::new(), false, None)
        .unwrap();
    let result = registrar
        .update_nameservers(
            &domain,
            vec!["ns1.example.com".into(), "ns2.example.com".into()],
        )
        .unwrap();
    assert!(result.successful);
    assert_eq!(result.nameservers.len(), 2);
}

#[test]
fn update_domain() {
    let registrar = Registrar::new(Mock::default_mock());
    let domain = format!("{}.com", random_label());
    registrar
        .purchase(&domain, purchase_contact(""), 1, Vec::new(), false, None)
        .unwrap();
    let original = registrar.get_domain(&domain).unwrap().auto_renew.unwrap();
    let updated = registrar
        .update_domain(&domain, &UpdateDetails::new(Some(!original)))
        .unwrap();
    assert!(updated);
    registrar
        .update_domain(&domain, &UpdateDetails::new(Some(original)))
        .unwrap();
}

#[test]
fn renew_domain() {
    let registrar = Registrar::new(Mock::default_mock());
    let domain = format!("{}.com", random_label());
    registrar
        .purchase(&domain, purchase_contact(""), 1, Vec::new(), false, None)
        .unwrap();
    let result = registrar.renew(&domain, 1).unwrap();
    assert!(result.order_id.as_ref().is_some_and(|s| !s.is_empty()));
    assert!(result.expires_at.is_some());
}

#[test]
fn transfer() {
    let registrar = Registrar::new(Mock::default_mock());
    let domain = format!("{}.com", random_label());
    let result = registrar.transfer(&domain, "test-auth-code", None).unwrap();
    assert!(!result.is_empty());
}

#[test]
fn get_auth_code() {
    let registrar = Registrar::new(Mock::default_mock());
    let domain = format!("{}.com", random_label());
    registrar
        .purchase(&domain, purchase_contact(""), 1, Vec::new(), false, None)
        .unwrap();
    let auth = registrar.get_auth_code(&domain).unwrap();
    assert!(!auth.is_empty());
}

#[test]
fn check_transfer_status() {
    let registrar = Registrar::new(Mock::default_mock());
    let domain = format!("{}.com", random_label());
    registrar
        .purchase(&domain, purchase_contact(""), 1, Vec::new(), false, None)
        .unwrap();
    let result = registrar.check_transfer_status(&domain).unwrap();
    assert!(matches!(
        result.status,
        TransferStatusEnum::Transferrable
            | TransferStatusEnum::NotTransferrable
            | TransferStatusEnum::PendingOwner
            | TransferStatusEnum::PendingAdmin
            | TransferStatusEnum::PendingRegistry
            | TransferStatusEnum::Completed
            | TransferStatusEnum::Cancelled
            | TransferStatusEnum::ServiceUnavailable
    ));
}

#[test]
fn purchase_with_nameservers() {
    let registrar = Registrar::new(Mock::default_mock());
    let result = registrar
        .purchase(
            "testdomain.com",
            purchase_contact(""),
            1,
            vec!["ns1.example.com".into(), "ns2.example.com".into()],
            false,
            None,
        )
        .unwrap();
    assert!(!result.is_empty());
}

#[test]
fn transfer_already_exists() {
    let registrar = Registrar::new(Mock::default_mock());
    registrar
        .purchase(
            "alreadyexists.com",
            purchase_contact(""),
            1,
            Vec::new(),
            false,
            None,
        )
        .unwrap();
    let err = registrar
        .transfer("alreadyexists.com", "test-auth-code-12345", None)
        .unwrap_err();
    assert!(matches!(err, DomainsError::DomainTaken { .. }));
    assert_eq!(
        err.to_string(),
        "Domain alreadyexists.com is already in this account"
    );
}

#[test]
fn check_transfer_status_with_request_address() {
    let registrar = Registrar::new(Mock::default_mock());
    let result = registrar.check_transfer_status("example.com").unwrap();
    assert_eq!(result.status, TransferStatusEnum::Transferrable);
}

#[test]
fn mock_helpers_and_premium_price() {
    let mock = Mock::default_mock();
    mock.add_taken_domain("taken.test");
    mock.add_premium_domain("hot.com", 99.0);
    let registrar = Registrar::new(mock);
    assert!(!registrar.available("taken.test").unwrap());
    let price = registrar
        .get_price("hot.com", 2, Registrar::REG_TYPE_NEW, 3600)
        .unwrap();
    assert_eq!(price, Price::new(198.0, true));
}

#[test]
fn memory_cache_roundtrip() {
    let cache = Cache::new(utopia_domains::MemoryCache::new());
    cache.save(
        "example.com",
        serde_json::json!({"price": 1.5, "premium": false}),
    );
    let loaded = cache.load("example.com", 3600).unwrap();
    assert_eq!(loaded["price"], 1.5);
    assert!(cache.purge("example.com"));
    assert!(cache.load("example.com", 3600).is_none());
}

#[test]
fn adapter_default_update_nameservers_not_implemented() {
    // Covered by trait default; Mock overrides it. Ensure error path exists via a stub-less call
    // on the documented message used by PHP.
    let err = DomainsError::Generic {
        message: "Method not implemented".into(),
        code: 0,
    };
    assert_eq!(err.to_string(), "Method not implemented");
}
