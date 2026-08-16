use utopia_domains::{Domain, DomainsError};

#[allow(clippy::too_many_arguments, clippy::fn_params_excessive_bools)]
fn assert_domain(
    host: &str,
    get: &str,
    tld: &str,
    suffix: &str,
    registerable: &str,
    name: &str,
    sub: &str,
    known: bool,
    icann: bool,
    private: bool,
    test: bool,
) {
    let domain = Domain::new(host).expect(host);
    assert_eq!(domain.get(), get);
    assert_eq!(domain.get_tld(), tld);
    assert_eq!(domain.get_suffix(), suffix);
    assert_eq!(domain.get_registerable(), registerable);
    assert_eq!(domain.get_name(), name);
    assert_eq!(domain.get_sub(), sub);
    assert_eq!(domain.is_known(), known);
    assert_eq!(domain.is_icann(), icann);
    assert_eq!(domain.is_private(), private);
    assert_eq!(domain.is_test(), test);
}

#[test]
fn edgecase_domains() {
    let domain = Domain::new("httpmydomain.com").unwrap();
    assert_eq!(domain.get_registerable(), "httpmydomain.com");
}

#[test]
fn edgecase_domains_error() {
    let err = Domain::new("http://httpmydomain.com").unwrap_err();
    assert!(matches!(err, DomainsError::InvalidDomain { .. }));
    assert_eq!(
        err.to_string(),
        "'http://httpmydomain.com' must be a valid domain or hostname"
    );
}

#[test]
fn edgecase_domains_error2() {
    let err = Domain::new("https://httpmydomain.com").unwrap_err();
    assert!(matches!(err, DomainsError::InvalidDomain { .. }));
    assert_eq!(
        err.to_string(),
        "'https://httpmydomain.com' must be a valid domain or hostname"
    );
}

#[test]
fn example_co_uk() {
    let domain = Domain::new("demo.example.co.uk").unwrap();
    assert_eq!(domain.get(), "demo.example.co.uk");
    assert_eq!(domain.get_tld(), "uk");
    assert_eq!(domain.get_apex(), "example.co.uk");
    assert_eq!(domain.get_suffix(), "co.uk");
    assert_eq!(domain.get_registerable(), "example.co.uk");
    assert_eq!(domain.get_name(), "example");
    assert_eq!(domain.get_sub(), "demo");
    assert!(domain.is_known());
    assert!(domain.is_icann());
    assert!(!domain.is_private());
    assert!(!domain.is_test());
}

#[test]
fn sub_sub_example_co_uk() {
    let domain = Domain::new("subsub.demo.example.co.uk").unwrap();
    assert_eq!(domain.get(), "subsub.demo.example.co.uk");
    assert_eq!(domain.get_tld(), "uk");
    assert_eq!(domain.get_apex(), "example.co.uk");
    assert_eq!(domain.get_suffix(), "co.uk");
    assert_eq!(domain.get_registerable(), "example.co.uk");
    assert_eq!(domain.get_name(), "example");
    assert_eq!(domain.get_sub(), "subsub.demo");
    assert!(domain.is_known());
    assert!(domain.is_icann());
    assert!(!domain.is_private());
    assert!(!domain.is_test());
}

#[test]
fn localhost() {
    assert_domain(
        "localhost",
        "localhost",
        "localhost",
        "",
        "",
        "",
        "",
        false,
        false,
        false,
        true,
    );
}

#[test]
fn demo_localhost() {
    assert_domain(
        "demo.localhost",
        "demo.localhost",
        "localhost",
        "",
        "",
        "demo",
        "",
        false,
        false,
        false,
        true,
    );
}

#[test]
fn sub_sub_demo_localhost() {
    assert_domain(
        "sub.sub.demo.localhost",
        "sub.sub.demo.localhost",
        "localhost",
        "",
        "",
        "demo",
        "sub.sub",
        false,
        false,
        false,
        true,
    );
}

#[test]
fn sub_demo_localhost() {
    assert_domain(
        "sub.demo.localhost",
        "sub.demo.localhost",
        "localhost",
        "",
        "",
        "demo",
        "sub",
        false,
        false,
        false,
        true,
    );
}

#[test]
fn utf() {
    assert_domain(
        "אשקלון.קום",
        "אשקלון.קום",
        "קום",
        "קום",
        "אשקלון.קום",
        "אשקלון",
        "",
        true,
        true,
        false,
        false,
    );
}

#[test]
fn utf_subdomain() {
    assert_domain(
        "חדשות.אשקלון.קום",
        "חדשות.אשקלון.קום",
        "קום",
        "קום",
        "אשקלון.קום",
        "אשקלון",
        "חדשות",
        true,
        true,
        false,
        false,
    );
}

#[test]
fn private_tld() {
    let domain = Domain::new("blog.potager.org").unwrap();
    assert_eq!(domain.get(), "blog.potager.org");
    assert_eq!(domain.get_tld(), "org");
    assert_eq!(domain.get_apex(), "blog.potager.org");
    assert_eq!(domain.get_suffix(), "potager.org");
    assert_eq!(domain.get_registerable(), "blog.potager.org");
    assert_eq!(domain.get_name(), "blog");
    assert_eq!(domain.get_sub(), "");
    assert!(domain.is_known());
    assert!(!domain.is_icann());
    assert!(domain.is_private());
    assert!(!domain.is_test());
}

#[test]
fn http_exception1() {
    assert!(matches!(
        Domain::new("http://www.facbook.com"),
        Err(DomainsError::InvalidDomain { .. })
    ));
}

#[test]
fn http_exception2() {
    assert!(matches!(
        Domain::new("http://facbook.com"),
        Err(DomainsError::InvalidDomain { .. })
    ));
}

#[test]
fn https_exception1() {
    assert!(matches!(
        Domain::new("https://www.facbook.com"),
        Err(DomainsError::InvalidDomain { .. })
    ));
}

#[test]
fn https_exception2() {
    assert!(matches!(
        Domain::new("https://facbook.com"),
        Err(DomainsError::InvalidDomain { .. })
    ));
}

#[test]
fn example_example_ck() {
    let domain = Domain::new("example.example.ck").unwrap();
    assert_eq!(domain.get(), "example.example.ck");
    assert_eq!(domain.get_tld(), "ck");
    assert_eq!(domain.get_suffix(), "example.ck");
    assert_eq!(domain.get_apex(), "example.example.ck");
    assert_eq!(domain.get_registerable(), "example.example.ck");
    assert_eq!(domain.get_name(), "example");
    assert_eq!(domain.get_sub(), "");
    assert!(domain.is_known());
    assert!(domain.is_icann());
    assert!(!domain.is_private());
    assert!(!domain.is_test());
    assert_eq!(domain.get_rule(), "*.ck");
}

#[test]
fn sub_sub_example_example_ck() {
    let domain = Domain::new("subsub.demo.example.example.ck").unwrap();
    assert_eq!(domain.get(), "subsub.demo.example.example.ck");
    assert_eq!(domain.get_tld(), "ck");
    assert_eq!(domain.get_apex(), "example.example.ck");
    assert_eq!(domain.get_suffix(), "example.ck");
    assert_eq!(domain.get_registerable(), "example.example.ck");
    assert_eq!(domain.get_name(), "example");
    assert_eq!(domain.get_sub(), "subsub.demo");
    assert!(domain.is_known());
    assert!(domain.is_icann());
    assert!(!domain.is_private());
    assert!(!domain.is_test());
}

#[test]
fn www_ck() {
    let domain = Domain::new("www.ck").unwrap();
    assert_eq!(domain.get(), "www.ck");
    assert_eq!(domain.get_tld(), "ck");
    assert_eq!(domain.get_apex(), "www.ck");
    assert_eq!(domain.get_suffix(), "ck");
    assert_eq!(domain.get_registerable(), "www.ck");
    assert_eq!(domain.get_name(), "www");
    assert_eq!(domain.get_sub(), "");
    assert!(domain.is_known());
    assert!(domain.is_icann());
    assert!(!domain.is_private());
    assert!(!domain.is_test());
    assert_eq!(domain.get_rule(), "!www.ck");
}

#[test]
fn sub_sub_www_ck() {
    let domain = Domain::new("subsub.demo.www.ck").unwrap();
    assert_eq!(domain.get(), "subsub.demo.www.ck");
    assert_eq!(domain.get_tld(), "ck");
    assert_eq!(domain.get_apex(), "www.ck");
    assert_eq!(domain.get_suffix(), "ck");
    assert_eq!(domain.get_registerable(), "www.ck");
    assert_eq!(domain.get_name(), "www");
    assert_eq!(domain.get_sub(), "subsub.demo");
    assert!(domain.is_known());
    assert!(domain.is_icann());
    assert!(!domain.is_private());
    assert!(!domain.is_test());
}

#[test]
fn wildcard_nom_br() {
    let domain = Domain::new("sub.example.com.nom.br").unwrap();
    assert_eq!(domain.get(), "sub.example.com.nom.br");
    assert_eq!(domain.get_tld(), "br");
    assert_eq!(domain.get_apex(), "example.com.nom.br");
    assert_eq!(domain.get_suffix(), "com.nom.br");
    assert_eq!(domain.get_registerable(), "example.com.nom.br");
    assert_eq!(domain.get_name(), "example");
    assert_eq!(domain.get_sub(), "sub");
    assert!(domain.is_known());
    assert!(domain.is_icann());
    assert!(!domain.is_private());
    assert!(!domain.is_test());
    assert_eq!(domain.get_rule(), "*.nom.br");
}

#[test]
fn wildcard_kawasaki_jp() {
    let domain = Domain::new("sub.example.com.kawasaki.jp").unwrap();
    assert_eq!(domain.get(), "sub.example.com.kawasaki.jp");
    assert_eq!(domain.get_tld(), "jp");
    assert_eq!(domain.get_apex(), "example.com.kawasaki.jp");
    assert_eq!(domain.get_suffix(), "com.kawasaki.jp");
    assert_eq!(domain.get_registerable(), "example.com.kawasaki.jp");
    assert_eq!(domain.get_name(), "example");
    assert_eq!(domain.get_sub(), "sub");
    assert!(domain.is_known());
    assert!(domain.is_icann());
    assert!(!domain.is_private());
    assert!(!domain.is_test());
}

#[test]
fn exception_kawasaki_jp() {
    let domain = Domain::new("sub.city.kawasaki.jp").unwrap();
    assert_eq!(domain.get(), "sub.city.kawasaki.jp");
    assert_eq!(domain.get_tld(), "jp");
    assert_eq!(domain.get_suffix(), "kawasaki.jp");
    assert_eq!(domain.get_registerable(), "city.kawasaki.jp");
    assert_eq!(domain.get_name(), "city");
    assert_eq!(domain.get_sub(), "sub");
    assert!(domain.is_known());
    assert!(domain.is_icann());
    assert!(!domain.is_private());
    assert!(!domain.is_test());
    assert_eq!(domain.get_rule(), "!city.kawasaki.jp");
}

#[test]
fn wildcard_private_domain() {
    let domain = Domain::new("sub.example.com.dev.adobeaemcloud.com").unwrap();
    assert_eq!(domain.get(), "sub.example.com.dev.adobeaemcloud.com");
    assert_eq!(domain.get_tld(), "com");
    assert_eq!(domain.get_suffix(), "com.dev.adobeaemcloud.com");
    assert_eq!(
        domain.get_registerable(),
        "example.com.dev.adobeaemcloud.com"
    );
    assert_eq!(domain.get_name(), "example");
    assert_eq!(domain.get_sub(), "sub");
    assert!(domain.is_known());
    assert!(!domain.is_icann());
    assert!(domain.is_private());
    assert!(!domain.is_test());
}

#[test]
fn private_domain() {
    let domain = Domain::new("sub.example.adobeaemcloud.net").unwrap();
    assert_eq!(domain.get(), "sub.example.adobeaemcloud.net");
    assert_eq!(domain.get_tld(), "net");
    assert_eq!(domain.get_suffix(), "adobeaemcloud.net");
    assert_eq!(domain.get_registerable(), "example.adobeaemcloud.net");
    assert_eq!(domain.get_name(), "example");
    assert_eq!(domain.get_sub(), "sub");
    assert!(domain.is_known());
    assert!(!domain.is_icann());
    assert!(domain.is_private());
    assert!(!domain.is_test());
}

#[test]
fn lowercases_unicode_and_ascii() {
    let domain = Domain::new("Demo.Example.COM").unwrap();
    assert_eq!(domain.get(), "demo.example.com");
    assert_eq!(domain.get_name(), "example");
}
