# utopia-console

CLI helpers for Utopia. Rust port of [utopia-php/console](https://github.com/utopia-php/console).

## Install

```toml
utopia-console = { path = "../utopia-console" } # workspace
```

## Usage

```rust
use utopia_console::{Command, Console};

Console::success("Ready to work!");

let answer = Console::confirm("Continue? [y/N]").unwrap();
if answer != "y" {
    Console::warning("Aborting...");
}

let command = Command::new("echo")
    .unwrap()
    .argument("Hello", None)
    .unwrap();

let mut stdout = String::new();
let mut stderr = String::new();
let exit_code = Console::execute(command, "", &mut stdout, &mut stderr, 3, None);

println!("exit={exit_code} stdout={stdout}");
```

### Build commands

```rust
use utopia_console::Command;

let pipeline = Command::pipe(vec![
    Command::new("ps").unwrap().flag("-ef").unwrap(),
    Command::new("grep").unwrap().argument("php-fpm", None).unwrap(),
    Command::new("wc").unwrap().flag("-l").unwrap(),
])
.unwrap();

let deploy = Command::and(vec![
    Command::group(Command::or(vec![
        Command::new("build").unwrap(),
        Command::new("build:fallback").unwrap(),
    ])
    .unwrap()),
    Command::new("publish").unwrap(),
])
.unwrap();
```

Plain commands execute in argv mode. Composed, grouped, and redirected commands execute through shell syntax.

### Daemon loop

```rust
use utopia_console::Console;

Console::run_loop(
    || {
        println!("tick");
        Ok(true) // return `false` to stop
    },
    1.0,  // sleep between iterations (seconds)
    0.0,  // initial delay (seconds)
    None::<&mut dyn FnMut(&str)>,
)
.unwrap();
```

For tests, use `Console::run_loop_with_max_iterations` to bound iterations.

## API Reference

### `Console`

| Method | Signature | Description |
|--------|-----------|-------------|
| `title` | `fn title(title: &str) -> bool` | Sets process title when supported. Linux writes `/proc/self/comm` (truncated to 15 bytes). Other platforms: no-op, returns `false`. |
| `log` | `fn log(message: &str) -> io::Result<usize>` | Plain log line to stdout. |
| `success` | `fn success(message: &str) -> io::Result<usize>` | Green (`\x1b[32m`) log to stdout. |
| `error` | `fn error(message: &str) -> io::Result<usize>` | Red (`\x1b[31m`) log to stderr. |
| `info` | `fn info(message: &str) -> io::Result<usize>` | Blue (`\x1b[34m`) log to stdout. |
| `warning` | `fn warning(message: &str) -> io::Result<usize>` | Bold yellow (`\x1b[1;33m`) log to stderr. |
| `confirm` | `fn confirm(question: &str) -> io::Result<String>` | Prompts on stdin when [`is_interactive`](#console) is true; otherwise returns `""`. |
| `is_interactive` | `fn is_interactive() -> bool` | `true` when stdin is a terminal. |
| `execute` | `fn execute(cmd, stdin, stdout, stderr, timeout_secs, on_progress) -> i32` | Runs a command via `std::process::Command`. `timeout_secs`: `-1` = no timeout, `> 0` = max seconds (returns `1` on timeout). Optional `on_progress` receives streamed stdout chunks. |
| `run_loop` | `fn run_loop(callback, sleep_secs, delay_secs, on_error) -> Result<(), E>` | Repeatedly calls `callback`. Return `Ok(true)` to continue, `Ok(false)` to stop. Errors go to `on_error` or propagate. |
| `run_loop_with_max_iterations` | `fn run_loop_with_max_iterations(callback, sleep_secs, delay_secs, max_iterations, on_error) -> Result<(), E>` | Test helper wrapping `run_loop` with a hard iteration cap. |

**`execute` input types** (`ExecuteInput`):

| Source | Execution mode |
|--------|----------------|
| `Command` (plain) | argv (`program` + args, no shell) |
| `Command` (composite/group/redirect) | shell string via `sh -c` / `cmd /C` |
| `Vec<String>` / `&[String]` / `&[&str]` | argv |
| `&str` / `String` | shell string |

### `Command`

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(executable) -> Result<Self, CommandError>` | Plain command with executable/path as first argv element. |
| `pipe` | `fn pipe(commands: Vec<Command>) -> Result<Self, CommandError>` | Pipe (`\|`). Requires ≥ 2 commands. |
| `and` | `fn and(commands: Vec<Command>) -> Result<Self, CommandError>` | Logical AND (`&&`). |
| `or` | `fn or(commands: Vec<Command>) -> Result<Self, CommandError>` | Logical OR (`\|\|`). |
| `group` | `fn group(command: Command) -> Self` | Parenthesized sub-expression. |
| `redirect_stdout` | `fn redirect_stdout(command, path) -> Result<Self, CommandError>` | `>` redirect. |
| `append_stdout` | `fn append_stdout(command, path) -> Result<Self, CommandError>` | `>>` append redirect. |
| `redirect_input` | `fn redirect_input(command, path) -> Result<Self, CommandError>` | `<` input redirect. |
| `flag` | `fn flag(self, key) -> Result<Self, CommandError>` | Add `-x` / `--long` flag. |
| `option` | `fn option(self, key, value, validator) -> Result<Self, CommandError>` | Add key/value option; optional [`CommandValidator`]. PHP `Validator` objects wrap via [`from_validator`]. |
| `argument` | `fn argument(self, value, validator) -> Result<Self, CommandError>` | Add positional argument with optional validator. |
| `is_plain` | `fn is_plain(&self) -> bool` | Whether command is a plain argv command. |
| `to_array` | `fn to_array(&self) -> Result<Vec<String>, CommandError>` | argv slice (plain only). |
| `to_string_shell` | `fn to_string_shell(&self) -> Result<String, CommandError>` | Shell string with PHP-compatible `escapeshellarg` quoting. |
| `Display` | `fmt::Display` | Same as `to_string_shell` (invalid commands render `<invalid command>`). |

### `escape_shell_arg`

| Function | Signature | Description |
|----------|-----------|-------------|
| `escape_shell_arg` | `fn escape_shell_arg(value: &str) -> String` | PHP `escapeshellarg` compatible single-quote escaping. |

### Errors

| Type | Variants |
|------|----------|
| `CommandError` | `NotPlain`, `CompositeTooFew`, `NotPlainMutation`, `InvalidFlag`, `InvalidOption`, `EmptyValue`, `InvalidArgument`, `InvalidArgumentWithDescription`, `UnsupportedType` |
| `ConsoleError` | `Spawn`, `StdinWrite`, `OutputRead` (used internally; `execute` maps failures to exit code `1`) |

### `ansi` (test helpers)

Hidden module re-exported for formatting assertions: `format_log`, `format_success`, `format_error`, `format_info`, `format_warning`, and color constants.

## Tests

```bash
cargo test --manifest-path crates-utopia/console/Cargo.toml
```

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/console/Cargo.toml
```

Prints `console_command_build: <ops/s> (<duration> for N iters)`.

## Code quality

- **rustfmt** - `cargo fmt --manifest-path crates-utopia/console/Cargo.toml`
- **Clippy** - `cargo clippy --manifest-path crates-utopia/console/Cargo.toml --all-targets -- -D warnings`
- Inherits workspace lint policy when added as a workspace member (`[lints] workspace = true`).
