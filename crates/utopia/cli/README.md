# Utopia CLI

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/cli`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/cli) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Command-line applications: tasks with validated params and injected resources, init, shutdown and error hooks, and adapters that run them.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-cli = { git = "https://github.com/utopia-rs/cli" }
```

A command-line application maps commands to tasks. Each task declares the arguments it reads, with a validator each, and the resources it needs; the application parses `<command> --key=value`, validates, injects and runs the task between its init and shutdown hooks.

## Run a task

Arguments arrive as strings; a key given several times is a list.

```rust
use std::sync::{Arc, Mutex};
use serde_json::{json, Value};
use utopia_cli::Cli;
use utopia_servers::Param;
use utopia_validators::{ArrayList, Text};

let mut cli = Cli::new(&["app", "build", "--email=me@example.com", "--list=item1", "--list=item2"])?;
let out = Arc::new(Mutex::new(String::new()));
let sink = out.clone();
cli.task("build")
    .param("email", Param::new(Value::Null, Text::new(0), "Valid email address"))
    .param("list", Param::new(Value::Null, ArrayList::new(Text::new(256), 0), "List of strings"))
    .action(move |args| {
        let list: Vec<&str> = args.value("list").and_then(Value::as_array).into_iter().flatten().filter_map(Value::as_str).collect();
        *sink.lock().unwrap() = format!("{}-{}", args.value("email").and_then(Value::as_str).unwrap_or(""), list.join("-"));
        async {}
    });

cli.run().await?;
assert_eq!(*out.lock().unwrap(), "me@example.com-item1-item2");
assert_eq!(cli.args()["list"], json!(["item1", "item2"]));
```

## Inject resources and use hooks

Resources are built once from their dependencies; init and shutdown hooks run around every task.

```rust
use std::sync::{Arc, Mutex};
use utopia_cli::Cli;

let mut cli = Cli::new(&["app", "migrate"])?;
cli.set_resource("dsn", &[], |_| Ok(String::from("postgresql://db")));
let log = Arc::new(Mutex::new(Vec::new()));

let l = log.clone();
cli.init().action(move |_| { l.lock().unwrap().push("init".to_owned()); async {} });
let l = log.clone();
cli.task("migrate").inject("dsn").action(move |args| {
    l.lock().unwrap().push(args.resource::<String>("dsn").cloned().unwrap_or_default());
    async {}
});
let l = log.clone();
cli.shutdown().action(move |_| { l.lock().unwrap().push("shutdown".to_owned()); async {} });

cli.run().await?;
assert_eq!(*log.lock().unwrap(), ["init", "postgresql://db", "shutdown"]);
```

## Handle failures

A missing task, an invalid argument or a failing task runs the error hooks, with the failure injected as `error`.

```rust
use std::sync::{Arc, Mutex};
use utopia_cli::{Cli, Error};

let mut cli = Cli::new(&["app", "unknown"])?;
let seen = Arc::new(Mutex::new(String::new()));
let s = seen.clone();
cli.error().inject("error").action(move |args| {
    *s.lock().unwrap() = args.resource::<Arc<Error>>("error").map(|e| e.to_string()).unwrap_or_default();
    async {}
});

cli.run().await?;
assert_eq!(*seen.lock().unwrap(), "No command found");
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
