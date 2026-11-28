A command-line application runs one command and exits. You give it the command line, register tasks and hooks, and call `run`. `run` picks the task the command names and runs it between the init and shutdown hooks. If anything fails, the error hooks run instead.

## The application

```flow
Parse | `Cli::new` reads the command line: the command first, then `--key=value` arguments. Without a command it fails with `Missing command`
Register | tasks, init, shutdown and error hooks, and resources
Match | `run` finds the task the command names
Init hooks | every init hook, in the order they were added
Task | its params are read from the arguments and validated, its resources are injected, then its callback runs
Shutdown hooks | every shutdown hook, in the order they were added
! Error hooks | no task matched, an argument was invalid, or a step failed. Every error hook runs, with the failure as `error`
```

Hooks have no groups here: every init and shutdown hook runs around every task. Hooks can declare params and inject resources, the same way tasks do. A resource is built the first time something injects it, and the same value is then shared for the rest of the run.

The example records each step:

```
# futures_util::FutureExt::now_or_never(async {
use std::sync::{Arc, Mutex};
use serde_json::Value;
use utopia_cli::Cli;
use utopia_servers::Param;
use utopia_validators::Text;

let mut cli = Cli::new(&["app", "migrate", "--to=v2"])?;
let log = Arc::new(Mutex::new(Vec::new()));

let l = log.clone();
cli.init().action(move |_| { l.lock().unwrap().push("init".to_owned()); async {} });
let l = log.clone();
cli.task("migrate")
    .param("to", Param::new(Value::Null, Text::new(16), "Target version"))
    .action(move |args| {
        let to = args.value("to").and_then(Value::as_str).unwrap_or_default();
        l.lock().unwrap().push(format!("migrate to {to}"));
        async {}
    });
let l = log.clone();
cli.shutdown().action(move |_| { l.lock().unwrap().push("shutdown".to_owned()); async {} });

cli.run().await?;
assert_eq!(*log.lock().unwrap(), ["init", "migrate to v2", "shutdown"]);
# Ok::<(), utopia_cli::Error>(())
# }).unwrap()?;
# Ok::<(), utopia_cli::Error>(())
```

## When something fails

```flow
Init hooks | run
Task | fails: an invalid argument, or its callback returns an error
! Error hooks | run in place of the shutdown hooks, with the failure as `error`
```

A failure stops the run where it happens, and the shutdown hooks do not run. The error hooks run in their place, so this is where a task reports a failure or sets the exit code. `run` itself returns an error only when an error hook fails too.

```
# futures_util::FutureExt::now_or_never(async {
use std::sync::{Arc, Mutex};
use utopia_cli::{Cli, Error};

let mut cli = Cli::new(&["app", "backup"])?;
let log = Arc::new(Mutex::new(Vec::new()));

cli.task("backup").action(|_| async { Err(Error::exception("Disk full", 1)) });
let l = log.clone();
cli.shutdown().action(move |_| { l.lock().unwrap().push("shutdown".to_owned()); async {} });
let l = log.clone();
cli.error().inject("error").action(move |args| {
    let error = args.resource::<Arc<Error>>("error").map(|e| e.to_string()).unwrap_or_default();
    l.lock().unwrap().push(format!("error: {error}"));
    async {}
});

cli.run().await?;
assert_eq!(*log.lock().unwrap(), ["error: Disk full"]);
# Ok::<(), utopia_cli::Error>(())
# }).unwrap()?;
# Ok::<(), utopia_cli::Error>(())
```

## Run in several workers

```flow
> For each worker
Worker start | the adapter's `on_worker_start` callbacks, with the worker's number
Run | the whole lifecycle above: init hooks, task, shutdown hooks
Worker stop | the adapter's `on_worker_stop` callbacks
```

The adapter decides how many times the lifecycle runs. `Generic`, the default, runs it once. `Workers::new(n)` runs it in `n` workers at once, for work that splits across workers, such as draining a queue faster. `run` returns when every worker is done.

```
# futures_util::FutureExt::now_or_never(async {
use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};
use utopia_cli::{Cli, Workers};

let runs = Arc::new(AtomicUsize::new(0));
let mut cli = Cli::with_adapter(Arc::new(Workers::new(3)), &["app", "build"])?;
let r = runs.clone();
cli.task("build").action(move |_| {
    r.fetch_add(1, Ordering::SeqCst);
    async {}
});

cli.run().await?;
assert_eq!(runs.load(Ordering::SeqCst), 3);
# Ok::<(), utopia_cli::Error>(())
# }).unwrap()?;
# Ok::<(), utopia_cli::Error>(())
```
