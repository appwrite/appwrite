use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use tempfile::NamedTempFile;
use utopia_console::{Command, Console, ExecuteInput};

#[test]
fn command_rejects_empty_argument() {
    let error = Command::new("git").unwrap().argument("", None).unwrap_err();
    assert!(matches!(
        error,
        utopia_console::CommandError::EmptyValue { context } if context == "Command argument"
    ));
}

#[test]
fn command_rejects_invalid_option() {
    let error = Command::new("tar")
        .unwrap()
        .option("-cz", "archive.tar.gz", None)
        .unwrap_err();
    assert!(matches!(
        error,
        utopia_console::CommandError::InvalidOption(flag) if flag == "-cz"
    ));
}

#[test]
fn command_rejects_empty_redirect_target() {
    let error = Command::redirect_stdout(Command::new("php").unwrap(), "").unwrap_err();
    assert!(matches!(
        error,
        utopia_console::CommandError::EmptyValue { context } if context == "Command redirect target"
    ));
}

#[test]
fn command_redirects_to_string() {
    let command = Command::append_stdout(
        Command::pipe(vec![
            Command::new("cat")
                .unwrap()
                .argument("app.log", None)
                .unwrap(),
            Command::new("grep")
                .unwrap()
                .argument("ERROR", None)
                .unwrap(),
        ])
        .unwrap(),
        "errors.log",
    )
    .unwrap();

    assert_eq!(
        command.to_string_shell().unwrap(),
        "'cat' 'app.log' | 'grep' 'ERROR' >> 'errors.log'"
    );
}

#[test]
fn nested_command_expression_to_string() {
    let command = Command::redirect_stdout(
        Command::group(
            Command::and(vec![
                Command::or(vec![
                    Command::new("build").unwrap(),
                    Command::new("build:fallback").unwrap(),
                ])
                .unwrap(),
                Command::new("publish").unwrap(),
            ])
            .unwrap(),
        ),
        "deploy.log",
    )
    .unwrap();

    assert_eq!(
        command.to_string_shell().unwrap(),
        "( 'build' || 'build:fallback' && 'publish' ) > 'deploy.log'"
    );
}

#[test]
fn group_any_command_to_string() {
    let command = Command::group(Command::new("build").unwrap());
    assert_eq!(command.to_string_shell().unwrap(), "( 'build' )");
}

#[test]
fn composite_command_is_not_plain() {
    assert!(!Command::and(vec![
        Command::new("build").unwrap(),
        Command::new("publish").unwrap(),
    ])
    .unwrap()
    .is_plain());
}

#[test]
fn grouped_command_is_not_plain() {
    assert!(!Command::group(Command::new("build").unwrap()).is_plain());
}

#[test]
fn redirected_command_is_not_plain() {
    assert!(
        !Command::redirect_stdout(Command::new("build").unwrap(), "build.log")
            .unwrap()
            .is_plain()
    );
}

#[test]
fn composite_command_cannot_be_converted_to_array() {
    let error = Command::and(vec![
        Command::new("build").unwrap(),
        Command::new("publish").unwrap(),
    ])
    .unwrap()
    .to_array()
    .unwrap_err();
    assert!(matches!(error, utopia_console::CommandError::NotPlain));
}

#[test]
fn composite_command_requires_at_least_two_commands() {
    let error = Command::and(vec![Command::new("build").unwrap()]).unwrap_err();
    assert!(matches!(
        error,
        utopia_console::CommandError::CompositeTooFew
    ));
}

#[test]
fn grouped_command_rejects_additional_flags() {
    let error = Command::group(Command::new("build").unwrap())
        .flag("-v")
        .unwrap_err();
    assert!(matches!(
        error,
        utopia_console::CommandError::NotPlainMutation
    ));
}

#[test]
fn execute_env_variables() {
    let random_data = "dGVzdC1kYXRh";
    std::env::set_var("UTOPIA_CONSOLE_FOO", random_data);

    let mut stdout = String::new();
    let mut stderr = String::new();
    let code = Console::execute(
        ExecuteInput::Args(vec!["printenv".into()]),
        "",
        &mut stdout,
        &mut stderr,
        10,
        None,
    );

    assert_eq!(code, 0);
    assert!(stdout.contains(&format!("UTOPIA_CONSOLE_FOO={random_data}")));
}

#[test]
fn execute_stderr() {
    let command = Command::new("sh")
        .unwrap()
        .option("-c", "echo error 1>&2", None)
        .unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();

    let code = Console::execute(command, "", &mut stdout, &mut stderr, 3, None);

    assert!(stdout.is_empty());
    assert_eq!(stderr.trim(), "error");
    assert_eq!(code, 0);
}

#[test]
fn execute_exit_codes() {
    for (script, expected) in [("echo hello; exit 2", 2), ("echo hello; exit 100", 100)] {
        let command = Command::new("sh")
            .unwrap()
            .option("-c", script, None)
            .unwrap();
        let mut stdout = String::new();
        let mut stderr = String::new();
        let code = Console::execute(command, "", &mut stdout, &mut stderr, 10, None);
        assert_eq!(stdout.trim(), "hello");
        assert_eq!(code, expected);
    }
}

#[test]
fn execute_stream_progress() {
    let command = Command::new("sh")
        .unwrap()
        .option(
            "-c",
            "for i in 1 2 3 4 5; do echo -n \"$i\"; sleep 0.2; done",
            None,
        )
        .unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();
    let mut stream = String::new();

    let code = Console::execute(
        command,
        "",
        &mut stdout,
        &mut stderr,
        10,
        Some(&mut |chunk| {
            stream.push_str(chunk);
        }),
    );

    assert_eq!(stdout, "12345");
    assert_eq!(stream, "12345");
    assert_eq!(code, 0);
}

#[test]
fn execute_grouped_fallback_expression() {
    let command = Command::and(vec![
        Command::group(
            Command::or(vec![
                Command::new("sh")
                    .unwrap()
                    .option("-c", "exit 1", None)
                    .unwrap(),
                Command::new("sh")
                    .unwrap()
                    .option("-c", "echo -n fallback", None)
                    .unwrap(),
            ])
            .unwrap(),
        ),
        Command::new("sh")
            .unwrap()
            .option("-c", "echo -n ' publish'", None)
            .unwrap(),
    ])
    .unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();

    let code = Console::execute(command, "", &mut stdout, &mut stderr, 10, None);

    assert_eq!(stdout, "fallback publish");
    assert!(stderr.is_empty());
    assert_eq!(code, 0);
}

#[test]
fn execute_and_stops_on_failure() {
    let command = Command::and(vec![
        Command::new("sh")
            .unwrap()
            .option("-c", "echo -n start; exit 1", None)
            .unwrap(),
        Command::new("sh")
            .unwrap()
            .option("-c", "echo -n never", None)
            .unwrap(),
    ])
    .unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();

    let code = Console::execute(command, "", &mut stdout, &mut stderr, 10, None);

    assert_eq!(stdout, "start");
    assert_eq!(code, 1);
}

#[test]
fn execute_or_stops_after_success() {
    let command = Command::or(vec![
        Command::new("sh")
            .unwrap()
            .option("-c", "echo -n done", None)
            .unwrap(),
        Command::new("sh")
            .unwrap()
            .option("-c", "echo -n fallback", None)
            .unwrap(),
    ])
    .unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();

    let code = Console::execute(command, "", &mut stdout, &mut stderr, 10, None);

    assert_eq!(stdout, "done");
    assert_eq!(code, 0);
}

#[test]
fn execute_append_stdout_expression() {
    let file = NamedTempFile::new().unwrap();
    std::fs::write(file.path(), "first\n").unwrap();

    let command = Command::append_stdout(
        Command::new("sh")
            .unwrap()
            .option("-c", "echo -n second", None)
            .unwrap(),
        file.path().to_string_lossy(),
    )
    .unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();

    let code = Console::execute(command, "", &mut stdout, &mut stderr, 10, None);

    assert_eq!(code, 0);
    assert_eq!(
        std::fs::read_to_string(file.path()).unwrap(),
        "first\nsecond"
    );
}

#[test]
fn execute_redirect_input_expression() {
    let file = NamedTempFile::new().unwrap();
    std::fs::write(file.path(), "delta\nalpha\n").unwrap();

    let command =
        Command::redirect_input(Command::new("sort").unwrap(), file.path().to_string_lossy())
            .unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();

    let code = Console::execute(command, "", &mut stdout, &mut stderr, 10, None);

    assert_eq!(stdout, "alpha\ndelta\n");
    assert_eq!(code, 0);
}

#[test]
fn loop_runs_until_timeout() {
    let command = Command::new("sh")
        .unwrap()
        .option(
            "-c",
            "i=0; while [ $i -lt 100 ]; do echo Hello; sleep 1; i=$((i+1)); done",
            None,
        )
        .unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();

    let code = Console::execute(command, "", &mut stdout, &mut stderr, 3, None);
    let lines: Vec<_> = stdout.lines().filter(|line| !line.is_empty()).collect();

    assert!(lines.len() > 2);
    assert!(lines.len() < 6);
    assert_eq!(code, 1);
}

#[test]
fn run_loop_forever_alias_matches_php_loop() {
    let counter = Arc::new(AtomicUsize::new(0));
    let counter_clone = Arc::clone(&counter);

    Console::run_loop_with_max_iterations(
        move || -> Result<bool, ()> {
            counter_clone.fetch_add(1, Ordering::SeqCst);
            Ok(true)
        },
        0.01,
        0.0,
        2,
        None,
    )
    .unwrap();

    assert_eq!(counter.load(Ordering::SeqCst), 2);
}
