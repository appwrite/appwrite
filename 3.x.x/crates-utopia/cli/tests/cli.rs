use std::collections::HashMap;
use std::sync::{Arc, Mutex};

use serde_json::{json, Value};
use utopia_cli::adapters::{Generic, Swoole};
use utopia_cli::{Adapter, ArgValue, Cli, CliError, Params};
use utopia_di::{Container, Resource};
use utopia_validators::{ArrayList, Boolean, Nullable, Text};

fn args(items: &[&str]) -> Vec<String> {
    items.iter().map(|s| (*s).to_string()).collect()
}

fn make_cli(argv: &[&str]) -> Cli {
    Cli::new(Some(Box::new(Generic::new())), args(argv), None).expect("cli")
}

#[test]
fn test_resources() {
    let cli = make_cli(&["test.php", "build"]);

    cli.set_resource("rand", || Resource::i64(42));
    cli.set_resource("second", || Resource::string("second"));
    cli.set_resource_with("first", &["second"], |deps| {
        let second = deps[0].get_as::<String>("second").expect("string");
        Resource::string(format!("first-{second}"))
    });

    let second = cli.get_resource("second").unwrap();
    let first = cli.get_resource("first").unwrap();
    assert_eq!(second.get_as::<String>("second").unwrap(), "second");
    assert_eq!(first.get_as::<String>("first").unwrap(), "first-second");

    let resource = cli.get_resource("rand").unwrap();
    assert_eq!(resource.get_as::<i64>("rand").unwrap(), 42);
    assert_eq!(
        cli.get_resource("rand")
            .unwrap()
            .get_as::<i64>("rand")
            .unwrap(),
        42
    );
    assert_eq!(
        cli.get_resource("rand")
            .unwrap()
            .get_as::<i64>("rand")
            .unwrap(),
        42
    );
}

#[test]
fn test_app_success() {
    let captured = Arc::new(Mutex::new(String::new()));
    let out = captured.clone();
    let mut cli = make_cli(&["test.php", "build", "--email=me@example.com"]);
    cli.task("build")
        .param(
            "email",
            Value::Null,
            Text::new(0),
            "Valid email address",
            false,
        )
        .action(move |params: &Params| {
            *out.lock().unwrap() = params.get_str("email").unwrap().to_string();
            Value::Null
        });
    cli.run();
    assert_eq!(captured.lock().unwrap().as_str(), "me@example.com");
}

#[test]
fn test_app_failure() {
    let captured = Arc::new(Mutex::new(String::new()));
    let out = captured.clone();
    let mut cli = make_cli(&["test.php", "build", "--email=me.example.com"]);
    cli.task("build")
        .param(
            "email",
            Value::Null,
            Text::new(10),
            "Valid email address",
            false,
        )
        .action(move |params: &Params| {
            *out.lock().unwrap() = params.get_str("email").unwrap().to_string();
            Value::Null
        });
    cli.run();
    assert_eq!(captured.lock().unwrap().as_str(), "");
}

#[test]
fn test_app_array() {
    let captured = Arc::new(Mutex::new(String::new()));
    let out = captured.clone();
    let mut cli = make_cli(&[
        "test.php",
        "build",
        "--email=me@example.com",
        "--list=item1",
        "--list=item2",
    ]);
    cli.task("build")
        .param(
            "email",
            Value::Null,
            Text::new(0),
            "Valid email address",
            false,
        )
        .param(
            "list",
            Value::Null,
            ArrayList::new(Text::new(256)),
            "List of strings",
            false,
        )
        .action(move |params: &Params| {
            let email = params.get_str("email").unwrap();
            let list = params.get_list("list").unwrap();
            *out.lock().unwrap() = format!("{email}-{}", list.join("-"));
            Value::Null
        });
    cli.run();
    assert_eq!(
        captured.lock().unwrap().as_str(),
        "me@example.com-item1-item2"
    );
}

#[test]
fn test_get_tasks() {
    let mut cli = make_cli(&[
        "test.php",
        "build",
        "--email=me@example.com",
        "--list=item1",
        "--list=item2",
    ]);
    cli.task("build1")
        .param(
            "email",
            Value::Null,
            Text::new(0),
            "Valid email address",
            false,
        )
        .param(
            "list",
            Value::Null,
            ArrayList::new(Text::new(256)),
            "List of strings",
            false,
        )
        .action(|_p| Value::Null);
    cli.task("build2")
        .param(
            "email",
            Value::Null,
            Text::new(0),
            "Valid email address",
            false,
        )
        .param(
            "list",
            Value::Null,
            ArrayList::new(Text::new(256)),
            "List of strings",
            false,
        )
        .action(|_p| Value::Null);
    assert_eq!(cli.get_tasks().len(), 2);
}

#[test]
fn test_get_args() {
    let mut cli = make_cli(&[
        "test.php",
        "build",
        "--email=me@example.com",
        "--list=item1",
        "--list=item2",
    ]);
    cli.task("build1")
        .param(
            "email",
            Value::Null,
            Text::new(0),
            "Valid email address",
            false,
        )
        .param(
            "list",
            Value::Null,
            ArrayList::new(Text::new(256)),
            "List of strings",
            false,
        )
        .action(|_p| Value::Null);
    cli.task("build2")
        .param(
            "email",
            Value::Null,
            Text::new(0),
            "Valid email address",
            false,
        )
        .param(
            "list",
            Value::Null,
            ArrayList::new(Text::new(256)),
            "List of strings",
            false,
        )
        .action(|_p| Value::Null);

    assert_eq!(cli.get_args().len(), 2);
    let mut expected = HashMap::new();
    expected.insert(
        "email".to_string(),
        ArgValue::String("me@example.com".into()),
    );
    expected.insert(
        "list".to_string(),
        ArgValue::List(vec!["item1".into(), "item2".into()]),
    );
    assert_eq!(cli.get_args(), &expected);
}

#[test]
fn test_hook() {
    let captured = Arc::new(Mutex::new(String::new()));
    let init_out = captured.clone();
    let task_out = captured.clone();
    let shutdown_out = captured.clone();
    let mut cli = make_cli(&[
        "test.php",
        "build",
        "--email=me@example.com",
        "--list=item1",
        "--list=item2",
    ]);
    cli.init().action(move |_p| {
        init_out.lock().unwrap().push_str("(init)-");
        Value::Null
    });
    cli.shutdown().action(move |_p| {
        shutdown_out.lock().unwrap().push_str("-(shutdown)");
        Value::Null
    });
    cli.task("build")
        .param(
            "email",
            Value::Null,
            Text::new(0),
            "Valid email address",
            false,
        )
        .param(
            "list",
            Value::Null,
            ArrayList::new(Text::new(256)),
            "List of strings",
            false,
        )
        .action(move |params: &Params| {
            let email = params.get_str("email").unwrap();
            let list = params.get_list("list").unwrap();
            let joined = list.join("-");
            let mut buf = task_out.lock().unwrap();
            *buf = format!("{buf}{email}-{joined}");
            Value::Null
        });
    cli.run();
    assert_eq!(
        captured.lock().unwrap().as_str(),
        "(init)-me@example.com-item1-item2-(shutdown)"
    );
}

#[test]
fn test_injection() {
    let captured = Arc::new(Mutex::new(String::new()));
    let out = captured.clone();
    let mut cli = make_cli(&["test.php", "build", "--email=me@example.com"]);
    cli.set_resource("test", || Resource::string("test-value"));
    cli.task("build")
        .inject("test")
        .unwrap()
        .param(
            "email",
            Value::Null,
            Text::new(15),
            "valid email address",
            false,
        )
        .action(move |params: &Params| {
            *out.lock().unwrap() = format!(
                "{}-{}",
                params.get_str("test").unwrap(),
                params.get_str("email").unwrap()
            );
            Value::Null
        });
    cli.run();
    assert_eq!(
        captured.lock().unwrap().as_str(),
        "test-value-me@example.com"
    );
}

#[test]
fn test_provided_container() {
    let captured = Arc::new(Mutex::new(String::new()));
    let out = captured.clone();
    let container = Container::new();
    container.set("test", || Ok(Resource::string("test-value")));
    let mut cli = Cli::new(
        Some(Box::new(Generic::new())),
        args(&["test.php", "build"]),
        Some(container.clone()),
    )
    .unwrap();
    let child = cli.get_container();
    assert!(!std::ptr::eq(child, &container));
    assert_eq!(
        cli.get_resource("test")
            .unwrap()
            .get_as::<String>("test")
            .unwrap(),
        "test-value"
    );
    cli.task("build")
        .inject("test")
        .unwrap()
        .action(move |params: &Params| {
            *out.lock().unwrap() = params.get_str("test").unwrap().to_string();
            Value::Null
        });
    cli.run();
    assert_eq!(captured.lock().unwrap().as_str(), "test-value");
}

#[test]
fn test_reset_preserves_injected_container() {
    let container = Container::new();
    container.set("base", || Ok(Resource::string("base-value")));
    let cli = Cli::new(
        Some(Box::new(Generic::new())),
        args(&["test.php", "build"]),
        Some(container),
    )
    .unwrap();
    cli.set_resource("runtime", || Resource::string("runtime-value"));
    assert_eq!(
        cli.get_resource("base")
            .unwrap()
            .get_as::<String>("base")
            .unwrap(),
        "base-value"
    );
    assert_eq!(
        cli.get_resource("runtime")
            .unwrap()
            .get_as::<String>("runtime")
            .unwrap(),
        "runtime-value"
    );

    let mut cli = cli;
    cli.reset();
    assert_eq!(
        cli.get_resource("base")
            .unwrap()
            .get_as::<String>("base")
            .unwrap(),
        "base-value"
    );
    let err = cli.get_resource("runtime").unwrap_err();
    assert!(matches!(err, CliError::ResourceNotFound(name) if name == "runtime"));
}

#[test]
fn test_match() {
    let mut cli = make_cli(&[
        "test.php",
        "build2",
        "--email=me@example.com",
        "--list=item1",
        "--list=item2",
    ]);
    cli.task("build1")
        .param(
            "email",
            Value::Null,
            Text::new(0),
            "Valid email address",
            false,
        )
        .param(
            "list",
            Value::Null,
            ArrayList::new(Text::new(256)),
            "List of strings",
            false,
        )
        .action(|_p| Value::Null);
    cli.task("build2")
        .param(
            "email",
            Value::Null,
            Text::new(0),
            "Valid email address",
            false,
        )
        .param(
            "list",
            Value::Null,
            ArrayList::new(Text::new(256)),
            "List of strings",
            false,
        )
        .action(|_p| Value::Null);
    assert_eq!(cli.match_task().unwrap().get_name(), "build2");

    let mut cli = make_cli(&[
        "test.php",
        "buildx",
        "--email=me@example.com",
        "--list=item1",
        "--list=item2",
    ]);
    cli.task("build1")
        .param(
            "email",
            Value::Null,
            Text::new(0),
            "Valid email address",
            false,
        )
        .param(
            "list",
            Value::Null,
            ArrayList::new(Text::new(256)),
            "List of strings",
            false,
        )
        .action(|_p| Value::Null);
    cli.task("build2")
        .param(
            "email",
            Value::Null,
            Text::new(0),
            "Valid email address",
            false,
        )
        .param(
            "list",
            Value::Null,
            ArrayList::new(Text::new(256)),
            "List of strings",
            false,
        )
        .action(|_p| Value::Null);
    assert!(cli.match_task().is_none());
}

#[test]
fn test_boolean_param_coerces_string_input() {
    for (input, expected) in [("false", false), ("true", true), ("0", false), ("1", true)] {
        let captured = Arc::new(Mutex::new(None));
        let out = captured.clone();
        let mut cli = make_cli(&["test.php", "build", &format!("--commit={input}")]);
        cli.task("build")
            .param(
                "commit",
                Value::Bool(false),
                Boolean::new().loose(true),
                "Commit changes",
                true,
            )
            .action(move |params: &Params| {
                *out.lock().unwrap() = params.get_bool("commit");
                Value::Null
            });
        cli.run();
        assert_eq!(*captured.lock().unwrap(), Some(expected), "input={input}");
    }
}

#[test]
fn test_boolean_param_uses_default_when_omitted() {
    let captured = Arc::new(Mutex::new(None));
    let out = captured.clone();
    let mut cli = make_cli(&["test.php", "build"]);
    cli.task("build")
        .param(
            "commit",
            Value::Bool(false),
            Boolean::new().loose(true),
            "Commit changes",
            true,
        )
        .action(move |params: &Params| {
            *out.lock().unwrap() = params.get_bool("commit");
            Value::Null
        });
    cli.run();
    assert_eq!(*captured.lock().unwrap(), Some(false));
}

#[test]
fn test_boolean_param_coercion_unwraps_nullable() {
    let captured = Arc::new(Mutex::new(None));
    let out = captured.clone();
    let mut cli = make_cli(&["test.php", "build", "--commit=false"]);
    cli.task("build")
        .param(
            "commit",
            Value::Null,
            Nullable::new(Boolean::new().loose(true)),
            "Commit changes",
            true,
        )
        .action(move |params: &Params| {
            *out.lock().unwrap() = params.get_bool("commit");
            Value::Null
        });
    cli.run();
    assert_eq!(*captured.lock().unwrap(), Some(false));
}

#[test]
fn test_boolean_param_preserves_empty_string_sentinel() {
    let captured = Arc::new(Mutex::new(json!("untouched")));
    let out = captured.clone();
    let mut cli = make_cli(&["test.php", "build"]);
    cli.task("build")
        .param(
            "commit",
            Value::String(String::new()),
            Boolean::new().loose(true),
            "Commit changes",
            true,
        )
        .action(move |params: &Params| {
            *out.lock().unwrap() = params.get_value("commit").cloned().unwrap_or(Value::Null);
            Value::Null
        });
    cli.run();
    assert_eq!(*captured.lock().unwrap(), Value::String(String::new()));
}

#[test]
fn test_non_boolean_validator_passes_value_through_unchanged() {
    let captured = Arc::new(Mutex::new(String::new()));
    let out = captured.clone();
    let mut cli = make_cli(&["test.php", "build", "--name=false"]);
    cli.task("build")
        .param(
            "name",
            Value::String(String::new()),
            Text::new(64),
            "A name",
            false,
        )
        .action(move |params: &Params| {
            *out.lock().unwrap() = params.get_str("name").unwrap().to_string();
            Value::Null
        });
    cli.run();
    assert_eq!(captured.lock().unwrap().as_str(), "false");
}

#[test]
fn test_escaping() {
    let captured = Arc::new(Mutex::new(String::new()));
    let out = captured.clone();
    let database = "appwrite://database_db_fra1_self_hosted_0_0?database=appwrite&namespace=_1";
    let mut cli = make_cli(&["test.php", "connect", &format!("--database={database}")]);
    cli.task("connect")
        .param(
            "database",
            Value::Null,
            Text::new(2048),
            "Database DSN",
            false,
        )
        .action(move |params: &Params| {
            *out.lock().unwrap() = params.get_str("database").unwrap().to_string();
            Value::Null
        });
    cli.run();
    assert_eq!(captured.lock().unwrap().as_str(), database);
}

#[test]
fn test_param_aliases() {
    let captured = Arc::new(Mutex::new(String::new()));
    let out = captured.clone();
    let mut cli = make_cli(&["test.php", "build", "--e=me@example.com"]);
    cli.task("build")
        .param_full(
            "email",
            Value::Null,
            Text::new(0),
            "Valid email address",
            false,
            Vec::new(),
            false,
            false,
            "",
            vec!["e".into(), "em".into()],
            None,
        )
        .action(move |params: &Params| {
            *out.lock().unwrap() = params.get_str("email").unwrap().to_string();
            Value::Null
        });
    cli.run();
    assert_eq!(captured.lock().unwrap().as_str(), "me@example.com");
}

#[test]
fn test_error_hook_receives_error_resource() {
    let captured = Arc::new(Mutex::new(String::new()));
    let out = captured.clone();
    let mut cli = make_cli(&["test.php", "build", "--email=nope"]);
    cli.error()
        .inject("error")
        .unwrap()
        .action(move |params: &Params| {
            let err = params
                .get_resource("error")
                .and_then(|r| r.downcast_ref::<CliError>())
                .map(ToString::to_string)
                .unwrap_or_default();
            *out.lock().unwrap() = err;
            Value::Null
        });
    cli.task("build")
        .param("email", Value::Null, Text::new(3), "short", false)
        .action(|_p| Value::Null);
    cli.run();
    let msg = captured.lock().unwrap().clone();
    assert!(msg.starts_with("Invalid email:"), "{msg}");
}

#[test]
fn test_missing_command() {
    let err = Cli::new(Some(Box::new(Generic::new())), args(&["test.php"]), None).unwrap_err();
    assert_eq!(err, CliError::MissingCommand);
}

#[test]
fn test_generic_on_job_invokes() {
    let called = Arc::new(Mutex::new(false));
    let flag = called.clone();
    let mut generic = Generic::new();
    generic.on_job(&mut || *flag.lock().unwrap() = true);
    assert!(*called.lock().unwrap());
}

#[test]
fn test_swoole_start_runs_per_worker() {
    let count = Arc::new(Mutex::new(0u32));
    let n = count.clone();
    let mut swoole = Swoole::new(3);
    swoole.start(&mut || *n.lock().unwrap() += 1);
    assert_eq!(*count.lock().unwrap(), 3);
    assert_eq!(swoole.get_native(), 3);
    assert_eq!(swoole.worker_num(), 3);
}

#[test]
fn test_camel_case_it() {
    assert_eq!(utopia_cli::camel_case_it("email"), "email");
    assert_eq!(utopia_cli::camel_case_it("foo-bar"), "fooBar");
    assert_eq!(utopia_cli::camel_case_it("foo_bar"), "fooBar");
}

#[test]
fn test_get_resources() {
    let cli = make_cli(&["test.php", "build"]);
    cli.set_resource("a", || Resource::string("A"));
    cli.set_resource("b", || Resource::string("B"));
    let got = cli.get_resources(&["a", "b"]).unwrap();
    assert_eq!(got["a"].get_as::<String>("a").unwrap(), "A");
    assert_eq!(got["b"].get_as::<String>("b").unwrap(), "B");
}
