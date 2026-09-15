//! Port of `tests/FirewallTest.php` plus extra action-coverage.

mod common;

use common::attrs;
use serde_json::json;
use utopia_waf::{
    Bypass, Challenge, Condition, Deny, Firewall, RateLimit, Redirect, Rule, ACTION_CHALLENGE,
    ACTION_DENY, ACTION_REDIRECT,
};

#[test]
fn verify_uses_populated_request_attributes_and_exposes_matched_rule() {
    let mut firewall = Firewall::new();
    firewall.set_attributes(&attrs(json!({
        "requestIP": "127.0.0.1",
        "requestPath": "/v1/locale",
        "requestCountry": "IL",
    })));

    let deny = Deny::new(vec![
        Condition::equal("ip", vec![json!("127.0.0.1")]),
        Condition::contains("path", vec![json!("/v1")]),
        Condition::equal("country", vec![json!("IL")]),
    ]);
    firewall.add_rule(deny);

    assert!(!firewall.verify());
    let matched = firewall.get_last_matched_rule().unwrap();
    assert_eq!(matched.get_action(), ACTION_DENY);

    firewall.clear_rules();
    firewall.add_rule(Deny::new(vec![Condition::equal(
        "country",
        vec![json!("US")],
    )]));

    assert!(!firewall.verify());
    assert!(firewall.get_last_matched_rule().is_none());
}

#[test]
fn rule_order() {
    let mut firewall = Firewall::new();
    firewall.set_attribute("requestIP", "127.0.0.1");
    firewall.set_attribute("requestPath", "/index");

    let deny = Deny::new(vec![
        Condition::equal("ip", vec![json!("127.0.0.1")]),
        Condition::not_equal("path", "/health"),
    ]);
    let bypass = Bypass::new(vec![Condition::equal("ip", vec![json!("127.0.0.1")])]);

    firewall.add_rule(deny.clone());
    firewall.add_rule(bypass.clone());
    assert!(!firewall.verify(), "Deny should be executed first");

    firewall.clear_rules();
    firewall.add_rule(bypass);
    firewall.add_rule(deny);
    assert!(
        firewall.verify(),
        "Bypass should pass when it is the first matching rule"
    );
}

#[test]
fn rate_limit_metadata() {
    let mut firewall = Firewall::new();
    firewall.set_attributes(&attrs(json!({
        "requestIP": "192.168.1.10",
        "requestPath": "/api",
    })));

    let rate_limit = RateLimit::new(
        vec![Condition::equal("ip", vec![json!("192.168.1.10")])],
        2,
        60,
    )
    .unwrap();
    firewall.add_rule(rate_limit);

    assert!(firewall.verify());
    let matched = firewall
        .get_last_matched_rule()
        .unwrap()
        .downcast_ref::<RateLimit>()
        .expect("RateLimit");
    assert_eq!(matched.get_limit(), 2);
    assert_eq!(matched.get_interval(), 60);
}

#[test]
fn rule_identifier_round_trip() {
    let rule = Deny::new(vec![Condition::equal("ip", vec![json!("127.0.0.1")])]).set_id("rule_abc");

    let mut firewall = Firewall::new();
    firewall.set_attribute("requestIP", "127.0.0.1");
    firewall.add_rule(rule);

    assert!(!firewall.verify());
    assert_eq!(
        firewall.get_last_matched_rule().and_then(Rule::get_id),
        Some("rule_abc")
    );
}

#[test]
fn ip_conditions_match_cidr_blocks_by_default() {
    let deny =
        Deny::new(vec![Condition::equal("ip", vec![json!("10.0.0.0/8")])]).set_id("rule_cidr");

    let mut firewall = Firewall::new();
    firewall.set_attribute("requestIP", "10.4.20.9");
    firewall.add_rule(deny.clone());

    assert!(!firewall.verify());
    assert_eq!(
        firewall.get_last_matched_rule().and_then(Rule::get_id),
        Some("rule_cidr")
    );

    let mut miss = Firewall::new();
    miss.set_attribute("requestIP", "11.0.0.1");
    miss.add_rule(deny);
    miss.verify();
    assert!(miss.get_last_matched_rule().is_none());
}

#[test]
fn not_equal_ip_condition_excludes_cidr_block() {
    let deny = Deny::new(vec![Condition::not_equal("ip", "10.0.0.0/8")]).set_id("rule_outside");

    let mut inside = Firewall::new();
    inside.set_attribute("ip", "10.4.20.9");
    inside.add_rule(deny.clone());
    inside.verify();
    assert!(inside.get_last_matched_rule().is_none());

    let mut outside = Firewall::new();
    outside.set_attribute("ip", "203.0.113.10");
    outside.add_rule(deny);
    assert!(!outside.verify());
    assert_eq!(
        outside.get_last_matched_rule().and_then(Rule::get_id),
        Some("rule_outside")
    );
}

#[test]
fn challenge_and_redirect_block_when_matched() {
    let mut challenge_fw = Firewall::new();
    challenge_fw.set_attribute("path", "/admin");
    challenge_fw.add_rule(Challenge::new(vec![Condition::starts_with(
        "path", "/admin",
    )]));
    assert!(!challenge_fw.verify());
    assert_eq!(
        challenge_fw.get_last_matched_rule().map(Rule::get_action),
        Some(ACTION_CHALLENGE)
    );

    let mut redirect_fw = Firewall::new();
    redirect_fw.set_attribute("path", "/legacy");
    redirect_fw.add_rule(Redirect::new(
        vec![Condition::starts_with("path", "/legacy")],
        "/new",
        301,
    ));
    assert!(!redirect_fw.verify());
    assert_eq!(
        redirect_fw.get_last_matched_rule().map(Rule::get_action),
        Some(ACTION_REDIRECT)
    );
}

#[test]
fn no_rules_verify_false() {
    let mut firewall = Firewall::new();
    firewall.set_attribute("ip", "127.0.0.1");
    assert!(!firewall.verify());
    assert!(firewall.get_last_matched_rule().is_none());
}

#[test]
fn request_ip_aliases_and_normalize_attribute_name() {
    let mut firewall = Firewall::new();
    firewall.set_attribute("requestIP", "203.0.113.10");
    assert_eq!(
        firewall
            .get_attribute("requestIP")
            .and_then(serde_json::Value::as_str),
        Some("203.0.113.10")
    );
    assert_eq!(
        firewall
            .get_attribute("ip")
            .and_then(serde_json::Value::as_str),
        Some("203.0.113.10")
    );
    assert_eq!(Firewall::normalize_attribute_name("requestIp"), "ip");
    assert_eq!(Firewall::normalize_attribute_name("IP"), "ip");
    assert_eq!(Firewall::normalize_attribute_name("requestPath"), "path");
    assert!(firewall.get_attribute_types().contains_key("ip"));
}

#[test]
fn ipv6_cidr_default_ip_type() {
    let deny = Deny::new(vec![Condition::equal("ip", vec![json!("2001:db8::/32")])]).set_id("v6");
    let mut firewall = Firewall::new();
    firewall.set_attribute("ip", "2001:db8::1");
    firewall.add_rule(deny);
    assert!(!firewall.verify());
    assert_eq!(
        firewall.get_last_matched_rule().and_then(Rule::get_id),
        Some("v6")
    );
}

#[test]
fn get_set_clear_rules_and_attribute_or() {
    let mut firewall = Firewall::new();
    assert!(firewall.get_rules().is_empty());
    firewall.add_rule(Bypass::new(vec![]));
    assert_eq!(firewall.get_rules().len(), 1);
    firewall.clear_rules();
    assert!(firewall.get_rules().is_empty());
    assert_eq!(
        firewall.get_attribute_or("missing", json!("fallback")),
        json!("fallback")
    );
}
