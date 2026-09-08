# utopia-cli

Command-line task runner for Utopia. Rust port of [utopia-php/cli](https://github.com/utopia-php/cli) (`packages/cli` in the [utopia-php monorepo](https://github.com/utopia-php/monorepo)).

## Install

```toml
utopia-cli = { path = "../utopia-cli" } # workspace
```

## Usage

```rust
use serde_json::Value;
use utopia_cli::adapters::Generic;
use utopia_cli::{Cli, Params};
use utopia_validators::Text;
use utopia_console::Console;

let mut cli = Cli::new(
    Some(Box::new(Generic::new())),
    std::env::args().collect(),
    None,
)
.unwrap();

cli.task("command-name")
    .param("email", Value::Null, Text::new(0), "email", false)
    .action(|params: &Params| {
        if let Some(email) = params.get_str("email") {
            let _ = Console::success(email);
        }
        Value::Null
    });

cli.run();
```

```bash
cargo run -p my-app -- command-name --email=me@example.com
```

### Hooks

Init hooks run before the matched task, shutdown hooks after it, and error hooks when dispatch fails (unknown command, validation, missing resource). Multiple hooks per stage are supported.

```rust
use serde_json::Value;
use utopia_cli::adapters::Generic;
use utopia_cli::{Cli, CliError, Params};
use utopia_di::Resource;
use utopia_validators::Wildcard;

let mut cli = Cli::new(Some(Box::new(Generic::new())), std::env::args().collect(), None).unwrap();
cli.set_resource("res1", || Resource::string("resource 1"));

cli.init()
    .inject("res1")
    .unwrap()
    .action(|params: &Params| {
        println!("{}", params.get_str("res1").unwrap_or_default());
        Value::Null
    });

cli.error()
    .inject("error")
    .unwrap()
    .action(|params: &Params| {
        if let Some(err) = params.get_resource("error").and_then(|r| r.downcast_ref::<CliError>()) {
            eprintln!("{err}");
        }
        Value::Null
    });

cli.task("command-name")
    .param("email", Value::Null, Wildcard::new(), "email", false)
    .action(|params: &Params| {
        println!("{}", params.get_str("email").unwrap_or_default());
        Value::Null
    });

cli.run();
```

## API Reference

### `Cli`

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(adapter, args, container) -> Result<Self, CliError>` | PHP constructor. Empty `args` uses `std::env::args()`. Always allowed (no PHP `php_sapi_name() === 'cli'` check). |
| `with_args` | `fn with_args(args) -> Result<Self, CliError>` | Generic adapter, no parent container. |
| `init` / `shutdown` / `error` | `fn …(&mut self) -> &mut CliHook` | Register a lifecycle hook. |
| `task` | `fn task(&mut self, name) -> &mut Task` | Register or replace a named command. |
| `parse` | `fn parse(&mut self, args) -> Result<HashMap<String, ArgValue>, CliError>` | Drops argv0, sets the command, parses `--key=value` (repeatable keys become lists). |
| `match_task` | `fn match_task(&self) -> Option<&Task>` | PHP `match()`. |
| `run` | `fn run(&mut self) -> &mut Self` | Dispatch via the adapter. Failures go to error hooks, or are swallowed if none are registered. |
| `get_tasks` / `get_args` | | Registered tasks and parsed flags. |
| `get_resource` / `get_resources` | | Resolve DI resources (`Failed to find resource: "…"`). |
| `set_resource` / `set_resource_with` | | PHP `setResource` without / with dependency list. |
| `get_container` / `set_container` / `reset` | | Child container over an optional parent; `reset` drops runtime bindings. |

Argv shape: `program command --email=me@example.com --list=a --list=b`. Values may contain `=`. Boolean params whose validator `value_type()` is `Boolean` coerce `"true"` / `"false"` / `"1"` / `"0"` / `"on"` / `"off"` / `"yes"` / `"no"` (PHP `filter_var` + `FILTER_NULL_ON_FAILURE`). Empty-string defaults stay sentinels and are not coerced.

Action callbacks receive [`Params`] keyed with PHP `camelCaseIt` names (`foo-bar` → `fooBar`).

### `Task` / `CliHook`

| Method | Description |
|--------|-------------|
| `desc` / `get_desc` | Task description. |
| `param` / `param_full` | Declare a flag (optional aliases via `param_full`). |
| `inject` | Declare a DI dependency (duplicate names error). |
| `action` | Set the callback (`Fn(&Params) -> Value`). |
| `label` / `get_label` | Arbitrary metadata. |
| `get_name` | Task name (`Task` only). |
| `get_params` / `get_dependencies` | Param map and inject list. |

### Adapters

| Type | PHP | Behavior |
|------|-----|----------|
| `adapters::Generic` | `Utopia\CLI\Adapters\Generic` | `start` / `on_job` run the callback once on this thread. |
| `adapters::Swoole` | `Utopia\CLI\Adapters\Swoole` | Runs the start callback once per `worker_num` (current thread; no Swoole process pool). `get_native()` returns the worker count. |

## Tests

```bash
cargo test -p utopia-cli
```

PHPUnit suites `CLITest` and `TaskTest` are ported in `tests/cli.rs` and `tests/task.rs`, plus adapter / error-hook / camelCase coverage.

## Benchmarks

```bash
cargo bench -p utopia-cli --bench cli
```

PHP twin: `benchmarks/cli/`. Metrics: `cli_dispatch`, `cli_camel_case`.

## Code quality

Workspace lints (`[lints] workspace = true`). `unsafe_code` is forbid.

## License

MIT
