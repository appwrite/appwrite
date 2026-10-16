use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::time::Duration;
use tempfile::NamedTempFile;
use utopia_console::ansi;
use utopia_console::{Command, Console, ExecuteInput};

#[test]
fn log_formatting_matches_php_ansi_codes() {
    assert_eq!(ansi::format_log("log"), "log\n");
    assert_eq!(ansi::format_success("success"), "\x1b[32msuccess\x1b[0m\n");
    assert_eq!(ansi::format_info("info"), "\x1b[34minfo\x1b[0m\n");
    assert_eq!(
        ansi::format_warning("warning"),
        "\x1b[1;33mwarning\x1b[0m\n"
    );
    assert_eq!(ansi::format_error("error"), "\x1b[31merror\x1b[0m\n");
}

#[test]
fn command_to_array_matches_php() {
    let command = Command::new("tar")
        .unwrap()
        .flag("-cz")
        .unwrap()
        .option("-f", "archive.tar.gz", None)
        .unwrap()
        .option("-C", "/tmp/project", None)
        .unwrap()
        .argument(".", None)
        .unwrap();

    assert_eq!(
        command.to_array().unwrap(),
        vec![
            "tar",
            "-cz",
            "-f",
            "archive.tar.gz",
            "-C",
            "/tmp/project",
            "."
        ]
    );
}

#[test]
fn command_to_string_escapes_arguments() {
    let command = Command::new("php")
        .unwrap()
        .option("-r", "echo 'hello'; rm -rf /", None)
        .unwrap();

    assert_eq!(
        command.to_string_shell().unwrap(),
        "'php' '-r' 'echo '\\''hello'\\''; rm -rf /'"
    );
}

#[test]
fn command_composition_to_string() {
    let command = Command::and(vec![
        Command::group(
            Command::or(vec![
                Command::new("build").unwrap(),
                Command::new("build:fallback").unwrap(),
            ])
            .unwrap(),
        ),
        Command::new("publish").unwrap(),
    ])
    .unwrap();

    assert_eq!(
        command.to_string_shell().unwrap(),
        "( 'build' || 'build:fallback' ) && 'publish'"
    );
}

#[test]
fn command_pipe_to_string() {
    let command = Command::pipe(vec![
        Command::new("ps").unwrap().flag("-ef").unwrap(),
        Command::new("grep")
            .unwrap()
            .argument("php-fpm", None)
            .unwrap(),
        Command::new("wc").unwrap().flag("-l").unwrap(),
    ])
    .unwrap();

    assert_eq!(
        command.to_string_shell().unwrap(),
        "'ps' '-ef' | 'grep' 'php-fpm' | 'wc' '-l'"
    );
}

#[test]
fn command_rejects_invalid_flag() {
    let error = Command::new("git").unwrap().flag("verbose").unwrap_err();
    assert!(matches!(
        error,
        utopia_console::CommandError::InvalidFlag(flag) if flag == "verbose"
    ));
}

#[test]
fn command_validator_success_and_failure() {
    let command = Command::new("git")
        .unwrap()
        .argument("checkout", None)
        .unwrap()
        .argument(
            "develop",
            Some(Box::new(|value| {
                ["main", "develop", "staging"].contains(&value)
            })),
        )
        .unwrap();

    assert_eq!(
        command.to_array().unwrap(),
        vec!["git", "checkout", "develop"]
    );

    let error = Command::new("git")
        .unwrap()
        .argument("checkout", None)
        .unwrap()
        .argument(
            "feature/test; rm -rf /",
            Some(Box::new(|value| {
                regex::Regex::new(r"^[A-Za-z0-9._/-]+$")
                    .unwrap()
                    .is_match(value)
            })),
        )
        .unwrap_err();

    assert!(matches!(
        error,
        utopia_console::CommandError::InvalidArgument { value } if value == "feature/test; rm -rf /"
    ));
}

#[test]
fn execute_echo_basic() {
    let command = Command::new("echo")
        .unwrap()
        .argument("hello world", None)
        .unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();

    let code = Console::execute(command, "", &mut stdout, &mut stderr, 10, None);

    assert_eq!(stdout.trim(), "hello world");
    assert!(stderr.is_empty());
    assert_eq!(code, 0);
}

#[test]
fn execute_argv_array() {
    let mut stdout = String::new();
    let mut stderr = String::new();
    let cmd: ExecuteInput = (&["echo", "hello world"][..]).into();

    let code = Console::execute(cmd, "", &mut stdout, &mut stderr, 10, None);

    assert_eq!(stdout.trim(), "hello world");
    assert_eq!(code, 0);
}

#[test]
fn execute_shell_string() {
    let mut stdout = String::new();
    let mut stderr = String::new();

    let code = Console::execute("echo hello world", "", &mut stdout, &mut stderr, 10, None);

    assert_eq!(stdout.trim(), "hello world");
    assert_eq!(code, 0);
}

#[test]
fn execute_timeout_kills_slow_command() {
    let command = Command::new("sh")
        .unwrap()
        .option("-c", "sleep 4; echo hello world", None)
        .unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();

    let code = Console::execute(command, "", &mut stdout, &mut stderr, 1, None);

    assert!(stdout.is_empty());
    assert_eq!(code, 1);
}

#[test]
fn execute_pipe_expression() {
    let command = Command::pipe(vec![
        Command::new("printf")
            .unwrap()
            .argument("alpha\\nbeta\\n", None)
            .unwrap(),
        Command::new("grep")
            .unwrap()
            .argument("beta", None)
            .unwrap(),
    ])
    .unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();

    let code = Console::execute(command, "", &mut stdout, &mut stderr, 10, None);

    assert_eq!(stdout, "beta\n");
    assert_eq!(code, 0);
}

#[test]
fn execute_redirect_stdout_expression() {
    let file = NamedTempFile::new().unwrap();
    let path = file.path().to_string_lossy().to_string();
    let command = Command::redirect_stdout(
        Command::new("printf")
            .unwrap()
            .argument("saved", None)
            .unwrap(),
        path,
    )
    .unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();

    let code = Console::execute(command, "", &mut stdout, &mut stderr, 10, None);

    assert!(stdout.is_empty());
    assert_eq!(code, 0);
    assert_eq!(std::fs::read_to_string(file.path()).unwrap(), "saved");
}

#[test]
fn run_loop_with_max_iterations_stops() {
    let counter = Arc::new(AtomicUsize::new(0));
    let counter_clone = Arc::clone(&counter);

    Console::run_loop_with_max_iterations(
        move || -> Result<bool, ()> {
            counter_clone.fetch_add(1, Ordering::SeqCst);
            Ok(true)
        },
        0.01,
        0.0,
        3,
        None,
    )
    .unwrap();

    assert_eq!(counter.load(Ordering::SeqCst), 3);
}

#[test]
fn run_loop_callback_can_stop_early() {
    let counter = Arc::new(AtomicUsize::new(0));
    let counter_clone = Arc::clone(&counter);

    Console::run_loop_with_max_iterations(
        move || -> Result<bool, ()> {
            let count = counter_clone.fetch_add(1, Ordering::SeqCst) + 1;
            Ok(count < 2)
        },
        0.0,
        0.0,
        10,
        None,
    )
    .unwrap();

    assert_eq!(counter.load(Ordering::SeqCst), 2);
}

#[test]
fn confirm_non_interactive_returns_empty() {
    // Stdin is not a terminal in `cargo test`.
    assert_eq!(Console::confirm("question?").unwrap(), "");
    assert!(!Console::is_interactive());
}

#[test]
fn title_sets_linux_process_name_when_available() {
    if cfg!(target_os = "linux") {
        assert!(Console::title("utopia-console"));
        let comm = std::fs::read_to_string("/proc/self/comm").unwrap();
        assert!(comm.starts_with("utopia-console"));
    }
}

#[test]
fn execute_respects_stdin_input() {
    let command = Command::new("cat").unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();

    let code = Console::execute(command, "stdin-data", &mut stdout, &mut stderr, 10, None);

    assert_eq!(stdout, "stdin-data");
    assert_eq!(code, 0);
}

#[test]
fn execute_fast_command_finishes_within_timeout() {
    let command = Command::new("sh")
        .unwrap()
        .option("-c", "sleep 1; echo hello world", None)
        .unwrap();
    let mut stdout = String::new();
    let mut stderr = String::new();

    let start = std::time::Instant::now();
    let code = Console::execute(command, "", &mut stdout, &mut stderr, 3, None);
    let elapsed = start.elapsed();

    assert_eq!(stdout.trim(), "hello world");
    assert_eq!(code, 0);
    assert!(elapsed < Duration::from_secs(3));
}
