//! Port of `tests/RulesTest.php` plus constructor error paths.

use utopia_waf::{
    Bypass, Challenge, Condition, Deny, InvalidArgumentError, RateLimit, Redirect, Rule,
    ACTION_BYPASS, ACTION_CHALLENGE, ACTION_DENY, ACTION_RATE_LIMIT, ACTION_REDIRECT,
};

#[test]
fn bypass_rule_matches() {
    let rule = Bypass::new(vec![Condition::equal("ip", vec!["127.0.0.1".into()])]);
    let mut attrs = serde_json::Map::new();
    attrs.insert("ip".into(), serde_json::json!("127.0.0.1"));
    assert!(rule.matches(&attrs, &utopia_waf::AttributeTypes::new()));
    assert_eq!(rule.get_action(), ACTION_BYPASS);
    assert_eq!(rule.get_action(), "bypass");
}

#[test]
fn deny_rule() {
    let rule = Deny::new(vec![Condition::equal("method", vec!["POST".into()])]);
    let mut attrs = serde_json::Map::new();
    attrs.insert("method".into(), serde_json::json!("POST"));
    assert!(rule.matches(&attrs, &utopia_waf::AttributeTypes::new()));
    assert_eq!(rule.get_action(), ACTION_DENY);
    assert_eq!(rule.get_action(), "deny");
}

#[test]
fn challenge_rule_type_defaults() {
    let default_rule = Challenge::new(Vec::<Condition>::new());
    let custom_rule =
        Challenge::with_type(Vec::<Condition>::new(), Challenge::TYPE_CUSTOM).unwrap();
    let compute_rule =
        Challenge::with_type(Vec::<Condition>::new(), Challenge::TYPE_COMPUTE).unwrap();

    assert_eq!(default_rule.get_action(), ACTION_CHALLENGE);
    assert_eq!(default_rule.get_action(), "challenge");
    assert_eq!(default_rule.get_type(), Challenge::TYPE_CAPTCHA);
    assert_eq!(custom_rule.get_type(), Challenge::TYPE_CUSTOM);
    assert_eq!(compute_rule.get_type(), Challenge::TYPE_COMPUTE);
}

#[test]
fn challenge_rule_rejects_unknown_type() {
    let err = Challenge::with_type(Vec::<Condition>::new(), "not-a-real-type").unwrap_err();
    assert!(matches!(
        err,
        InvalidArgumentError::InvalidChallengeType(ref t) if t == "not-a-real-type"
    ));
}

#[test]
fn rate_limit_metadata() {
    let rule = RateLimit::new(Vec::<Condition>::new(), 10, 600).unwrap();
    assert_eq!(rule.get_action(), ACTION_RATE_LIMIT);
    assert_eq!(rule.get_action(), "rateLimit");
    assert_eq!(rule.get_limit(), 10);
    assert_eq!(rule.get_interval(), 600);
}

#[test]
fn rate_limit_rejects_non_positive() {
    assert!(matches!(
        RateLimit::new(Vec::<Condition>::new(), 0, 60).unwrap_err(),
        InvalidArgumentError::InvalidRateLimit
    ));
    assert!(matches!(
        RateLimit::new(Vec::<Condition>::new(), 10, 0).unwrap_err(),
        InvalidArgumentError::InvalidRateLimit
    ));
}

#[test]
fn redirect_rule() {
    let rule = Redirect::new(Vec::<Condition>::new(), "/new", 301);
    assert_eq!(rule.get_action(), ACTION_REDIRECT);
    assert_eq!(rule.get_action(), "redirect");
    assert_eq!(rule.get_location(), "/new");
    assert_eq!(rule.get_status_code(), 301);
}

#[test]
fn add_condition_and_empty_conditions_match() {
    let mut rule = Deny::new(Vec::<Condition>::new());
    assert!(rule.matches(&serde_json::Map::new(), &utopia_waf::AttributeTypes::new()));
    rule.add_condition(Condition::equal("ip", vec!["1.1.1.1".into()]));
    assert_eq!(rule.get_conditions().len(), 1);
}
