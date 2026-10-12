A command-line application maps commands to tasks. Each task declares the arguments it reads, with a validator each, and the resources it needs; the application parses `<command> --key=value`, validates, injects and runs the task between its init and shutdown hooks.

## Run a task

Arguments arrive as strings; a key given several times is a list.

```
# futures_util::FutureExt::now_or_never(async {
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
        async { Ok(()) }
    });

cli.run().await?;
assert_eq!(*out.lock().unwrap(), "me@example.com-item1-item2");
assert_eq!(cli.args()["list"], json!(["item1", "item2"]));
# Ok::<(), utopia_cli::Error>(())
# }).unwrap()?;
# Ok::<(), utopia_cli::Error>(())
```

```php
use Utopia\CLI\Adapters\Generic;
use Utopia\CLI\CLI;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

$cli = new CLI(new Generic(), ['app', 'build', '--email=me@example.com', '--list=item1', '--list=item2']);
$cli->task('build')
    ->param('email', null, new Text(0), 'Valid email address')
    ->param('list', null, new ArrayList(new Text(256)), 'List of strings')
    ->action(function (string $email, array $list) {
        echo $email . '-' . implode('-', $list), "\n"; // me@example.com-item1-item2
    });
$cli->run();
```

## Inject resources and use hooks

Resources are built once from their dependencies; init and shutdown hooks run around every task.

```
# futures_util::FutureExt::now_or_never(async {
use std::sync::{Arc, Mutex};
use utopia_cli::Cli;

let mut cli = Cli::new(&["app", "migrate"])?;
cli.set_resource("dsn", &[], |_| Ok(String::from("postgresql://db")));
let log = Arc::new(Mutex::new(Vec::new()));

let l = log.clone();
cli.init().action(move |_| { l.lock().unwrap().push("init".to_owned()); async { Ok(()) } });
let l = log.clone();
cli.task("migrate").inject("dsn").action(move |args| {
    l.lock().unwrap().push(args.resource::<String>("dsn").cloned().unwrap_or_default());
    async { Ok(()) }
});
let l = log.clone();
cli.shutdown().action(move |_| { l.lock().unwrap().push("shutdown".to_owned()); async { Ok(()) } });

cli.run().await?;
assert_eq!(*log.lock().unwrap(), ["init", "postgresql://db", "shutdown"]);
# Ok::<(), utopia_cli::Error>(())
# }).unwrap()?;
# Ok::<(), utopia_cli::Error>(())
```

```php
use Utopia\CLI\Adapters\Generic;
use Utopia\CLI\CLI;

$cli = new CLI(new Generic(), ['app', 'migrate']);
$cli->setResource('dsn', fn () => 'postgresql://db');
$cli->init()->action(fn () => print("init\n"));
$cli->task('migrate')->inject('dsn')->action(fn (string $dsn) => print("$dsn\n"));
$cli->shutdown()->action(fn () => print("shutdown\n"));
$cli->run(); // init, postgresql://db, shutdown
```

## Handle failures

A missing task, an invalid argument or a failing task runs the error hooks, with the failure injected as `error`.

```
# futures_util::FutureExt::now_or_never(async {
use std::sync::{Arc, Mutex};
use utopia_cli::{Cli, Error};

let mut cli = Cli::new(&["app", "unknown"])?;
let seen = Arc::new(Mutex::new(String::new()));
let s = seen.clone();
cli.error().inject("error").action(move |args| {
    *s.lock().unwrap() = args.resource::<Arc<Error>>("error").map(|e| e.to_string()).unwrap_or_default();
    async { Ok(()) }
});

cli.run().await?;
assert_eq!(*seen.lock().unwrap(), "No command found");
# Ok::<(), utopia_cli::Error>(())
# }).unwrap()?;
# Ok::<(), utopia_cli::Error>(())
```

```php
use Utopia\CLI\Adapters\Generic;
use Utopia\CLI\CLI;

$cli = new CLI(new Generic(), ['app', 'unknown']);
$cli->error()->inject('error')->action(fn (\Throwable $error) => print($error->getMessage() . "\n"));
$cli->run(); // No command found
```
