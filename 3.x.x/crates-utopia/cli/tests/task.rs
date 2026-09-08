use serde_json::json;
use utopia_cli::{Params, Task};
use utopia_validators::Text;

#[test]
fn test_name() {
    let task = Task::new("test");
    assert_eq!(task.get_name(), "test");
}

#[test]
fn test_description() {
    let mut task = Task::new("test");
    task.desc("test task");
    assert_eq!(task.get_desc(), "test task");
}

#[test]
fn test_action() {
    let mut task = Task::new("test");
    task.action(|_params: &Params| json!("result"));
    assert_eq!(task.get_action().unwrap()(&Params::new()), json!("result"));
}

#[test]
fn test_label() {
    let mut task = Task::new("test");
    task.label("key", "value");
    assert_eq!(task.get_label("key", json!("default")), json!("value"));
    assert_eq!(
        task.get_label("unknown", json!("default")),
        json!("default")
    );
}

#[test]
fn test_param() {
    let mut task = Task::new("test");
    task.param(
        "email",
        json!("me@example.com"),
        Text::new(0),
        "Param with valid email address",
        false,
    );
    assert_eq!(task.get_params().len(), 1);
}

#[test]
fn test_resources() {
    let mut task = Task::new("test");
    assert!(task.get_dependencies().is_empty());
    task.inject("user")
        .unwrap()
        .inject("time")
        .unwrap()
        .action(|_p| serde_json::Value::Null);
    assert_eq!(task.get_dependencies().len(), 2);
    assert_eq!(task.get_dependencies()[0], "user");
    assert_eq!(task.get_dependencies()[1], "time");
}
