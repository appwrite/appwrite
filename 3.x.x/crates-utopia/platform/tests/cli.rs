use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};

use serde_json::Value;
use utopia_cli::Cli;
use utopia_platform::{Action, ActionType, Module, Platform, Service, ServiceType};
use utopia_validators::{ArrayList, Text};

fn task_cli(args: Vec<&str>) -> Cli {
    Cli::with_args(args.into_iter().map(ToOwned::to_owned).collect()).expect("cli argv")
}

fn test_action_cli(output: Arc<Mutex<String>>) -> Action {
    Action::new()
        .param("email", Value::Null, Text::new(0), "", false)
        .param(
            "list",
            Value::Null,
            ArrayList::new(Text::new(256)),
            "List of strings",
            false,
        )
        .cli_action(move |params| {
            let email = params.get_str("email").unwrap_or("");
            let list = params.get_list("list").unwrap_or_default();
            *output.lock().expect("output lock") = format!("{}-{}", email, list.join("-"));
            Value::Null
        })
}

/// Port of PHP `tests/e2e/CLITest.php`.
#[test]
fn cli_setup_registers_and_runs_tasks() {
    let output = Arc::new(Mutex::new(String::new()));
    let build = test_action_cli(output.clone());
    let build2 = test_action_cli(Arc::new(Mutex::new(String::new())));
    let service = Service::task()
        .add_action("build", build)
        .add_action("build2", build2);

    let mut platform = Platform::new(Module::new()).add_service("testCli", service);
    let mut cli = task_cli(vec![
        "test.php",
        "build",
        "--email=me@example.com",
        "--list=item1",
        "--list=item2",
    ]);
    platform.init_cli(&mut cli).unwrap();
    cli.run();

    assert_eq!(
        output.lock().expect("output lock").as_str(),
        "me@example.com-item1-item2"
    );
    assert_eq!(cli.get_tasks().len(), 2);
    assert!(cli.get_tasks().contains_key("build"));
    assert!(cli.get_tasks().contains_key("build2"));
}

#[test]
fn cli_task_callback_invokes() {
    let called = Arc::new(AtomicBool::new(false));
    let flag = called.clone();
    let build = Action::new().callback(move || {
        flag.store(true, Ordering::SeqCst);
    });
    let service = Service::task().add_action("build", build);

    let mut platform = Platform::new(Module::new()).add_service("testCli", service);
    let mut cli = task_cli(vec!["test.php", "build"]);
    platform.init_cli(&mut cli).unwrap();
    cli.run();

    assert!(called.load(Ordering::SeqCst));
}

#[test]
fn cli_init_hook_registers() {
    let called = Arc::new(AtomicBool::new(false));
    let flag = called.clone();
    let init = Action::new().set_type(ActionType::Init).callback(move || {
        flag.store(true, Ordering::SeqCst);
    });
    let build = Action::new().callback(|| {});
    let service = Service::task()
        .add_action("initHook", init)
        .add_action("build", build);

    let mut platform = Platform::new(Module::new()).add_service("testCli", service);
    let mut cli = task_cli(vec!["test.php", "build"]);
    platform.init_cli(&mut cli).unwrap();
    cli.run();

    assert!(called.load(Ordering::SeqCst));
}

#[test]
fn init_task_type_directs_to_init_cli() {
    let mut platform = Platform::new(Module::new());
    let err = platform.init(ServiceType::Task).unwrap_err();
    assert!(err.to_string().contains("init_cli"));
}
