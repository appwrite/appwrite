//! Port of `tests/unit/DNS/Zone/ResolverTest.php`.

mod common;

use common::{dns_query, example_soa, query, rec, soa};
use utopia_dns::message::{Message, Record};
use utopia_dns::zone::Resolver;
use utopia_dns::Zone;

fn lookup(zone: &Zone, name: &str, type_code: u16) -> Message {
    Resolver::lookup(&query(name, type_code), zone).unwrap()
}

#[test]
fn lookup_returns_formerr_when_query_has_no_question() {
    let zone = Zone::new("example.com", vec![], example_soa()).unwrap();
    let header =
        utopia_dns::message::Header::new(42, false, 0, false, false, true, false, 0, 0, 0, 0, 0)
            .unwrap();
    let q = Message::new(header, vec![], vec![], vec![], vec![]).unwrap();
    let response = Resolver::lookup(&q, &zone).unwrap();
    assert_eq!(response.header.response_code, Message::RCODE_FORMERR);
    assert!(response.questions.is_empty());
    assert!(response.header.authoritative);
}

#[test]
fn lookup_returns_exact_type_match() {
    let record = rec("www.example.com", Record::TYPE_A, 300, "1.2.3.4");
    let zone = Zone::new("example.com", vec![record.clone()], example_soa()).unwrap();
    let response = lookup(&zone, "www.example.com", Record::TYPE_A);
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert!(response.header.authoritative);
    assert_eq!(response.answers.len(), 1);
    assert_eq!(response.answers[0], record);
    assert!(!response.header.recursion_available);
}

#[test]
fn lookup_returns_cname_when_exact_type_missing() {
    let cname = rec(
        "alias.example.com",
        Record::TYPE_CNAME,
        1800,
        "target.example.com",
    );
    let zone = Zone::new("example.com", vec![cname.clone()], example_soa()).unwrap();
    let response = lookup(&zone, "alias.example.com", Record::TYPE_A);
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.answers.len(), 1);
    assert_eq!(response.answers[0], cname);
    assert!(response.header.authoritative);
}

#[test]
fn lookup_exact_match_nodata_returns_soa() {
    let txt = rec("www.example.com", Record::TYPE_TXT, 600, "\"hello\"");
    let soa = example_soa();
    let zone = Zone::new("example.com", vec![txt], soa.clone()).unwrap();
    let q = query("www.example.com", Record::TYPE_A);
    let response = Resolver::lookup(&q, &zone).unwrap();
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.questions, q.questions);
    assert!(response.answers.is_empty());
    assert_eq!(response.authority.len(), 1);
    assert_eq!(response.authority[0], soa);
    assert!(response.header.authoritative);
}

#[test]
fn lookup_returns_nxdomain_with_soa_when_name_missing() {
    let record = rec("www.example.com", Record::TYPE_A, 300, "1.2.3.4");
    let soa = example_soa();
    let zone = Zone::new("example.com", vec![record], soa.clone()).unwrap();
    let response = lookup(&zone, "missing.example.com", Record::TYPE_A);
    assert_eq!(response.header.response_code, Message::RCODE_NXDOMAIN);
    assert!(response.answers.is_empty());
    assert_eq!(response.authority.len(), 1);
    assert_eq!(response.authority[0], soa);
}

#[test]
fn lookup_synthesizes_wildcard_answer() {
    let wildcard = rec("*.example.com", Record::TYPE_A, 60, "1.1.1.1");
    let zone = Zone::new("example.com", vec![wildcard.clone()], example_soa()).unwrap();
    let response = lookup(&zone, "host.example.com", Record::TYPE_A);
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.answers.len(), 1);
    assert_eq!(response.answers[0].name, "host.example.com");
    assert_eq!(response.answers[0].rdata, wildcard.rdata);
    assert_eq!(response.answers[0].ttl, wildcard.ttl);
}

#[test]
fn lookup_synthesizes_wildcard_cname() {
    let wildcard = rec(
        "*.example.com",
        Record::TYPE_CNAME,
        600,
        "target.example.com",
    );
    let zone = Zone::new("example.com", vec![wildcard.clone()], example_soa()).unwrap();
    let response = lookup(&zone, "beta.example.com", Record::TYPE_A);
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.answers.len(), 1);
    let answer = &response.answers[0];
    assert_eq!(answer.name, "beta.example.com");
    assert_eq!(answer.type_code, Record::TYPE_CNAME);
    assert_eq!(answer.rdata, wildcard.rdata);
}

#[test]
fn lookup_returns_multiple_exact_type_records() {
    let a1 = rec("www.example.com", Record::TYPE_A, 300, "203.0.113.10");
    let a2 = rec("www.example.com", Record::TYPE_A, 180, "203.0.113.20");
    let zone = Zone::new("example.com", vec![a1, a2], example_soa()).unwrap();
    let response = lookup(&zone, "www.example.com", Record::TYPE_A);
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.answers.len(), 2);
    let rdatas: Vec<_> = response.answers.iter().map(|r| r.rdata.as_str()).collect();
    assert!(rdatas.contains(&"203.0.113.10"));
    assert!(rdatas.contains(&"203.0.113.20"));
    for answer in &response.answers {
        assert_eq!(answer.name, "www.example.com");
        assert_eq!(answer.type_code, Record::TYPE_A);
    }
}

#[test]
fn lookup_synthesizes_wildcard_mx_preserving_priority() {
    let mx1 = Record::new("*.example.com", Record::TYPE_MX)
        .ttl(3600)
        .rdata("mail1.example.com")
        .priority(10);
    let mx2 = Record::new("*.example.com", Record::TYPE_MX)
        .ttl(3600)
        .rdata("mail2.example.com")
        .priority(20);
    let zone = Zone::new("example.com", vec![mx1, mx2], example_soa()).unwrap();
    let response = lookup(&zone, "api.example.com", Record::TYPE_MX);
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.answers.len(), 2);
    let priorities: Vec<_> = response.answers.iter().map(|r| r.priority).collect();
    let rdatas: Vec<_> = response.answers.iter().map(|r| r.rdata.as_str()).collect();
    for answer in &response.answers {
        assert_eq!(answer.name, "api.example.com");
        assert_eq!(answer.type_code, Record::TYPE_MX);
    }
    assert!(priorities.contains(&Some(10)));
    assert!(priorities.contains(&Some(20)));
    assert!(rdatas.contains(&"mail1.example.com"));
    assert!(rdatas.contains(&"mail2.example.com"));
}

#[test]
fn lookup_returns_referral_for_delegated_subdomain() {
    let delegation = rec(
        "delegated.example.com",
        Record::TYPE_NS,
        86400,
        "ns1.delegated.example.com",
    );
    let zone = Zone::new("example.com", vec![delegation.clone()], example_soa()).unwrap();
    let q = query("delegated.example.com", Record::TYPE_A);
    let response = Resolver::lookup(&q, &zone).unwrap();
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.questions, q.questions);
    assert!(response.answers.is_empty());
    assert_eq!(response.authority.len(), 1);
    assert_eq!(response.authority[0], delegation);
    assert!(!response.header.authoritative);
}

#[test]
fn lookup_wildcard_nodata_returns_soa() {
    let wildcard = rec("*.example.com", Record::TYPE_TXT, 120, "\"v=spf1 ~all\"");
    let soa = example_soa();
    let zone = Zone::new("example.com", vec![wildcard], soa.clone()).unwrap();
    let q = query("svc.example.com", Record::TYPE_A);
    let response = Resolver::lookup(&q, &zone).unwrap();
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.questions, q.questions);
    assert!(response.answers.is_empty());
    assert_eq!(response.authority.len(), 1);
    assert_eq!(response.authority[0], soa);
    assert!(response.header.authoritative);
}

#[test]
fn lookup_prefers_exact_match_over_wildcard() {
    let exact = rec("www.example.com", Record::TYPE_A, 300, "2.2.2.2");
    let wildcard = rec("*.example.com", Record::TYPE_A, 60, "3.3.3.3");
    let zone = Zone::new("example.com", vec![exact.clone(), wildcard], example_soa()).unwrap();
    let response = lookup(&zone, "www.example.com", Record::TYPE_A);
    assert_eq!(response.answers.len(), 1);
    assert_eq!(response.answers[0], exact);
}

#[test]
fn lookup_uses_closest_enclosing_wildcard() {
    let broad = rec("*.example.com", Record::TYPE_A, 60, "1.1.1.1");
    let specific = rec("*.sub.example.com", Record::TYPE_A, 60, "2.2.2.2");
    let zone = Zone::new("example.com", vec![broad, specific], example_soa()).unwrap();
    let response = lookup(&zone, "host.sub.example.com", Record::TYPE_A);
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.answers.len(), 1);
    assert_eq!(response.answers[0].name, "host.sub.example.com");
    assert_eq!(response.answers[0].rdata, "2.2.2.2");
    assert_eq!(response.answers[0].type_code, Record::TYPE_A);
}

#[test]
fn lookup_resolves_wildcard_cname_query() {
    let soa = soa(
        "test-dns.appwrite.org",
        "ns1-stage.appwrite.zone team.appwrite.io 1 3600 600 86400 300",
        300,
    );
    let zone = Zone::new(
        "test-dns.appwrite.org",
        vec![
            rec(
                "test-dns.appwrite.org",
                Record::TYPE_NS,
                3600,
                "ns1.example.org",
            ),
            rec(
                "*.wildcard.test-dns.appwrite.org",
                Record::TYPE_CNAME,
                3600,
                "stage.appwrite.network",
            ),
        ],
        soa,
    )
    .unwrap();
    let response = lookup(
        &zone,
        "baz.wildcard.test-dns.appwrite.org",
        Record::TYPE_CNAME,
    );
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert!(response.header.authoritative);
    assert_eq!(response.answers.len(), 1);
    let answer = &response.answers[0];
    assert_eq!(answer.name, "baz.wildcard.test-dns.appwrite.org");
    assert_eq!(answer.type_code, Record::TYPE_CNAME);
    assert_eq!(answer.rdata, "stage.appwrite.network");
    assert_eq!(answer.ttl, 3600);
}

#[test]
fn is_authoritative_detects_delegation() {
    let ns = rec(
        "delegated.example.com",
        Record::TYPE_NS,
        3600,
        "ns1.delegated.example.com",
    );
    let zone = Zone::new("example.com", vec![ns], example_soa()).unwrap();
    assert!(!zone.is_authoritative("delegated.example.com"));
    assert!(zone.is_authoritative("www.example.com"));
}

#[test]
fn lookup_returns_apex_a_record() {
    let soa = soa(
        "example.com",
        "ns1.appwrite.zone. team@appwrite.io. 1761705275 3600 600 86400 300",
        300,
    );
    let a = rec("example.com", Record::TYPE_A, 3600, "1.1.1.1");
    let zone = Zone::new(
        "example.com",
        vec![
            rec(
                "example.com",
                Record::TYPE_NS,
                3600,
                "ns1-stage.appwrite.zone",
            ),
            rec(
                "example.com",
                Record::TYPE_NS,
                3600,
                "ns2-stage.appwrite.zone",
            ),
            a.clone(),
            rec("example.com", Record::TYPE_AAAA, 3600, "2606:4700::1111"),
            rec(
                "*.example.com",
                Record::TYPE_CNAME,
                3600,
                "stage.appwrite.network",
            ),
        ],
        soa,
    )
    .unwrap();
    let response = lookup(&zone, "example.com", Record::TYPE_A);
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.answers.len(), 1);
    assert_eq!(response.answers[0], a);
    assert!(response.header.authoritative);
}

#[test]
fn lookup_returns_apex_aaaa_record() {
    let soa = soa(
        "example.com",
        "ns1.appwrite.zone. team@appwrite.io. 1761705275 3600 600 86400 300",
        300,
    );
    let aaaa = rec("example.com", Record::TYPE_AAAA, 3600, "2606:4700::1111");
    let zone = Zone::new(
        "example.com",
        vec![
            rec("example.com", Record::TYPE_A, 3600, "1.1.1.1"),
            aaaa.clone(),
        ],
        soa,
    )
    .unwrap();
    let response = lookup(&zone, "example.com", Record::TYPE_AAAA);
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.answers.len(), 1);
    assert_eq!(response.answers[0], aaaa);
}

#[test]
fn lookup_returns_soa_answer_for_apex_soa_query_with_records() {
    let soa = soa(
        "example.com",
        "ns1.appwrite.zone. team@appwrite.io. 1761705275 3600 600 86400 300",
        300,
    );
    let zone = Zone::new(
        "example.com",
        vec![rec("example.com", Record::TYPE_A, 3600, "1.1.1.1")],
        soa.clone(),
    )
    .unwrap();
    let response = lookup(&zone, "example.com", Record::TYPE_SOA);
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.answers.len(), 1);
    assert_eq!(response.answers[0], soa);
    assert!(response.header.authoritative);
    assert!(!response.header.recursion_available);
}

#[test]
fn lookup_returns_soa_answer_for_apex_soa_query_with_no_records() {
    let soa = soa(
        "example.com",
        "ns1.appwrite.zone. team@appwrite.io. 1761705275 3600 600 86400 300",
        300,
    );
    let zone = Zone::new("example.com", vec![], soa.clone()).unwrap();
    let response = lookup(&zone, "example.com", Record::TYPE_SOA);
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.answers.len(), 1);
    assert_eq!(response.answers[0], soa);
    assert!(response.header.authoritative);
    assert!(!response.header.recursion_available);
}

#[test]
fn lookup_returns_soa_in_authority_for_apex_non_ns_query() {
    let soa = soa(
        "example.com",
        "ns1.appwrite.zone. team@appwrite.io. 1761705275 3600 600 86400 300",
        300,
    );
    let zone = Zone::new(
        "example.com",
        vec![
            rec(
                "example.com",
                Record::TYPE_NS,
                3600,
                "ns1-stage.appwrite.zone",
            ),
            rec(
                "example.com",
                Record::TYPE_NS,
                3600,
                "ns2-stage.appwrite.zone",
            ),
        ],
        soa.clone(),
    )
    .unwrap();
    let response = lookup(&zone, "example.com", Record::TYPE_A);
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert!(response.answers.is_empty());
    assert_eq!(response.authority.len(), 1);
    assert_eq!(response.authority[0], soa);
}

#[test]
fn memory_resolve_returns_exact_record() {
    use utopia_dns::resolver::{Memory, Resolver as _};
    let zone = Zone::new(
        "example.com",
        vec![rec("www.example.com", Record::TYPE_A, 0, "192.0.2.10")],
        soa(
            "example.com",
            "ns1.example.com. admin.example.com. 1 3600 600 1209600 300",
            0,
        ),
    )
    .unwrap();
    let resolver = Memory::new(zone);
    let response = resolver
        .resolve(&dns_query("www.example.com", Record::TYPE_A))
        .unwrap();
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert_eq!(response.answers.len(), 1);
    assert_eq!(response.answers[0].name, "www.example.com");
    assert_eq!(response.answers[0].type_code, Record::TYPE_A);
    assert_eq!(response.answers[0].rdata, "192.0.2.10");
    assert!(response.authority.is_empty());
}

#[test]
fn memory_resolve_nodata_includes_authority() {
    use utopia_dns::resolver::{Memory, Resolver as _};
    let zone = Zone::new(
        "example.com",
        vec![rec("www.example.com", Record::TYPE_A, 0, "192.0.2.10")],
        soa(
            "example.com",
            "ns1.example.com. admin.example.com. 1 3600 600 1209600 300",
            0,
        ),
    )
    .unwrap();
    let resolver = Memory::new(zone);
    let response = resolver
        .resolve(&dns_query("www.example.com", Record::TYPE_AAAA))
        .unwrap();
    assert_eq!(response.header.response_code, Message::RCODE_NOERROR);
    assert!(response.answers.is_empty());
    assert!(!response.authority.is_empty());
    assert_eq!(response.authority[0].type_code, Record::TYPE_SOA);
}

#[test]
fn memory_resolve_nxdomain() {
    use utopia_dns::resolver::{Memory, Resolver as _};
    let zone = Zone::new(
        "example.com",
        vec![rec("www.example.com", Record::TYPE_A, 0, "192.0.2.10")],
        soa(
            "example.com",
            "ns1.example.com. admin.example.com. 1 3600 600 1209600 300",
            0,
        ),
    )
    .unwrap();
    let resolver = Memory::new(zone);
    let response = resolver
        .resolve(&dns_query("missing.example.com", Record::TYPE_A))
        .unwrap();
    assert_eq!(response.header.response_code, Message::RCODE_NXDOMAIN);
    assert!(response.answers.is_empty());
    assert!(!response.authority.is_empty());
    assert_eq!(response.authority[0].type_code, Record::TYPE_SOA);
}

#[test]
fn memory_resolve_soa_falls_back_to_parent_zone() {
    use utopia_dns::resolver::{Memory, Resolver as _};
    let zone = Zone::new(
        "example.com",
        vec![rec("www.example.com", Record::TYPE_A, 0, "192.0.2.10")],
        soa(
            "example.com",
            "ns1.example.com. admin.example.com. 1 3600 600 1209600 300",
            0,
        ),
    )
    .unwrap();
    let resolver = Memory::new(zone);
    let response = resolver
        .resolve(&dns_query("child.www.example.com", Record::TYPE_SOA))
        .unwrap();
    assert_eq!(response.header.response_code, Message::RCODE_NXDOMAIN);
    assert!(response.answers.is_empty());
    assert!(!response.authority.is_empty());
    assert_eq!(response.authority[0].type_code, Record::TYPE_SOA);
    assert_eq!(response.authority[0].name, "example.com");
}
