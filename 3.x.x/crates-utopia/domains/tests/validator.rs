use parking_lot::Mutex;
use serde_json::json;
use utopia_domains::{ApexDomain, PublicDomain};
use utopia_validators::Validator;

// PHPUnit runs these sequentially and shares PublicDomain's static allow-list.
static VALIDATOR_LOCK: Mutex<()> = Mutex::new(());

#[test]
fn public_domain_is_valid() {
    let _guard = VALIDATOR_LOCK.lock();
    PublicDomain::reset_allowed();
    let domain = PublicDomain::new();
    assert_eq!(domain.description(), "Value must be a public domain");
    assert!(!domain.is_array());
    assert_eq!(domain.value_type().as_str(), "string");

    assert!(domain.is_valid(&json!("example.com")));
    assert!(domain.is_valid(&json!("google.com")));
    assert!(domain.is_valid(&json!("bbc.co.uk")));
    assert!(domain.is_valid(&json!("appwrite.io")));
    assert!(domain.is_valid(&json!("usa.gov")));
    assert!(domain.is_valid(&json!("stanford.edu")));

    assert!(domain.is_valid(&json!("http://google.com")));
    assert!(domain.is_valid(&json!("http://www.google.com")));
    assert!(domain.is_valid(&json!("https://example.com")));

    assert!(!domain.is_valid(&json!("localhost")));
    assert!(!domain.is_valid(&json!("http://localhost")));
    assert!(!domain.is_valid(&json!("sub.demo.localhost")));
    assert!(!domain.is_valid(&json!("test.app.internal")));
    assert!(!domain.is_valid(&json!("home.local")));
    assert!(!domain.is_valid(&json!("qa.testing.internal")));
    assert!(!domain.is_valid(&json!("wiki.team.local")));
    assert!(!domain.is_valid(&json!("example.test")));
    assert!(!domain.is_valid(&json!(123)));
}

#[test]
fn public_domain_allow() {
    let _guard = VALIDATOR_LOCK.lock();
    PublicDomain::reset_allowed();
    let domain = PublicDomain::new();
    PublicDomain::allow(["localhost"]);
    assert!(domain.is_valid(&json!("localhost")));
    assert!(domain.is_valid(&json!("http://localhost")));
    assert!(!domain.is_valid(&json!("test.app.internal")));

    PublicDomain::allow(["test.app.internal", "home.local"]);
    assert!(domain.is_valid(&json!("test.app.internal")));
    assert!(domain.is_valid(&json!("home.local")));
    PublicDomain::reset_allowed();
}

#[test]
fn apex_domain_is_valid() {
    let _guard = VALIDATOR_LOCK.lock();
    PublicDomain::reset_allowed();
    let domain = ApexDomain::new();
    assert_eq!(domain.description(), "Value must be a public apex domain");

    assert!(domain.is_valid(&json!("example.com")));
    assert!(domain.is_valid(&json!("google.com")));
    assert!(domain.is_valid(&json!("bbc.co.uk")));
    assert!(domain.is_valid(&json!("appwrite.io")));
    assert!(domain.is_valid(&json!("usa.gov")));
    assert!(domain.is_valid(&json!("stanford.edu")));
    assert!(domain.is_valid(&json!("http://google.com")));

    assert!(!domain.is_valid(&json!("blog.bbc.co.uk")));
    assert!(!domain.is_valid(&json!("www.google.com")));
    assert!(!domain.is_valid(&json!("test.usa.gov")));
    assert!(!domain.is_valid(&json!("test.com.test")));
    assert!(!domain.is_valid(&json!("http://www.google.com")));
    assert!(!domain.is_valid(&json!(false)));
}
