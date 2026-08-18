use serde_json::json;
use utopia_servers::Hook;
use utopia_validators::Text;

#[test]
fn param_inject_order_and_deps() {
    let mut hook = Hook::new();
    hook.desc("demo")
        .groups(["api"])
        .param("name", json!("World"), Text::new(256), "Name", true)
        .inject("response")
        .unwrap()
        .inject("request")
        .unwrap()
        .label("scope", "public")
        .action_marker();

    assert_eq!(hook.get_desc(), "demo");
    assert_eq!(hook.get_groups(), &["api".to_string()]);
    assert_eq!(hook.get_label("scope", json!(null)), json!("public"));
    assert_eq!(
        hook.get_dependencies(),
        vec!["response".to_string(), "request".to_string()]
    );
    assert!(hook.has_action());

    let order = hook.argument_order();
    assert_eq!(order[0].1, "name");
    assert_eq!(order[1].1, "response");
    assert_eq!(order[2].1, "request");
}

#[test]
fn duplicate_injection_errors() {
    let mut hook = Hook::new();
    hook.inject("response").unwrap();
    assert!(hook.inject("response").is_err());
}

#[test]
fn param_value_roundtrip() {
    let mut hook = Hook::new();
    hook.param("x", json!("def"), Text::new(10), "", true);
    hook.set_param_value("x", json!("set")).unwrap();
    assert_eq!(hook.get_param_value("x").unwrap(), Some(&json!("set")));
    assert!(hook.set_param_value("missing", json!(1)).is_err());
}
