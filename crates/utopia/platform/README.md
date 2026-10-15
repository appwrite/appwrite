# Utopia Platform

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/platform`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/platform) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Modules, services and actions: describe an application's HTTP routes, CLI tasks and queue workers once, and register them on their servers.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-platform = { git = "https://github.com/utopia-rs/platform" }
```

A platform describes an application once: actions grouped into services, services grouped into modules. Each service belongs to one server type, and initializing the platform for a type registers its actions on that server: HTTP routes and hooks, CLI tasks and hooks, or queue jobs and worker hooks.

## Describe an endpoint and serve it

An action carries its method, path, params, injections and labels; `init(Http)` turns it into a route.

```rust
use serde_json::json;
use utopia_http::{Bytes, Request, Response};
use utopia_platform::{Action, Callback, Init, Module, Param, Platform, Service, ServiceType};
use utopia_validators::Text;

let mut get = Action::new();
get.set_http_method("GET")
    .set_http_path("/v1/teams/:teamId")
    .http_alias("/v1/groups/:teamId")
    .label("scope", json!("teams.read"))
    .param("teamId", Param::new(json!(""), Text::new(36), "Team ID."))
    .inject("response")
    .callback(Callback::http(async |scope| {
        let id = scope.param("teamId").and_then(|v| v.as_str()).unwrap_or("").to_owned();
        scope.response.send(format!("team {id}").as_bytes());
        Ok(())
    }));
let mut teams = Service::new(ServiceType::HTTP);
teams.add_action("get", get);

let mut platform = Platform::new(Module::new());
platform.add_service("teams", teams);
platform.init(Init::Http)?;

let http = platform.http();
let request = Request::new("GET", "/v1/groups/t1", std::iter::empty::<(&str, &str)>(), Bytes::new());
let mut response = Response::new();
http.run(&request, &mut response, &http.context()).await?;
assert_eq!(response.wire().body, b"team t1");
```

## Run tasks from the command line

Actions of a task service become CLI tasks named by their key; init, shutdown and error actions become the hooks around them.

```rust
use std::sync::{Arc, Mutex};
use serde_json::{json, Value};
use utopia_cli::Cli;
use utopia_platform::{Action, ActionType, Callback, Init, Module, Param, Platform, Service, ServiceType};
use utopia_validators::Text;

let log = Arc::new(Mutex::new(Vec::new()));
let (l1, l2) = (log.clone(), log.clone());
let mut init = Action::new();
init.set_type(ActionType::INIT).callback(Callback::task(move |_| {
    l1.lock().unwrap().push("init".to_owned());
    async { Ok(()) }
}));
let mut migrate = Action::new();
migrate
    .param("project", Param::new(Value::Null, Text::new(36), "Project ID."))
    .callback(Callback::task(move |args| {
        l2.lock().unwrap().push(format!("migrate {}", args.value("project").and_then(Value::as_str).unwrap_or("")));
        async { Ok(()) }
    }));
let mut tasks = Service::new(ServiceType::TASK);
tasks.add_action("init", init).add_action("migrate", migrate);

let mut platform = Platform::new(Module::new());
platform.add_service("tasks", tasks);
platform.set_cli(Cli::new(&["app", "migrate", "--project=console"])?);
platform.init(Init::Task)?;
platform.cli().unwrap().run().await?;

assert_eq!(*log.lock().unwrap(), ["init", "migrate console"]);
```

## Register queue workers

Actions of a worker service become jobs, each on its queue (`v1-<name>` unless configured), with its own concurrency and prefetch; worker-start and worker-stop actions become worker hooks.

```rust
use std::sync::Arc;
use indexmap::IndexMap;
use utopia_platform::{Action, Callback, Init, JobConfig, Module, Platform, Service, ServiceType, WorkerParams};
use utopia_queue::Server;
use utopia_queue::adapter::{Adapter, Runtime};

let job = || {
    let mut action = Action::new();
    action.callback(Callback::worker(|_scope| async { Ok(()) }));
    action
};
let mut workers = Service::new(ServiceType::WORKER);
workers.add_action("databases", job()).add_action("functions", job());

let mut platform = Platform::new(Module::new());
platform.add_service("workers", workers);
platform.set_worker(Server::new(Adapter::new(Arc::new(Broker), 1, Runtime::Workers)));
let mut jobs = IndexMap::new();
jobs.insert("functions".to_owned(), JobConfig { coroutines: Some(8), ..JobConfig::default() });
platform.init(Init::Worker(WorkerParams { workers: vec!["all".into()], jobs, ..WorkerParams::default() }))?;

let worker = platform.worker().unwrap();
assert_eq!(worker.jobs(), ["v1-databases", "v1-functions"]);
assert_eq!(worker.coroutines("v1-functions"), 8);
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
