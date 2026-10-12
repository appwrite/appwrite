A platform describes an application once: actions grouped into services, services grouped into modules. Each service belongs to one server type, and initializing the platform for a type registers its actions on that server: HTTP routes and hooks, CLI tasks and hooks, or queue jobs and worker hooks.

## Describe an endpoint and serve it

An action carries its method, path, params, injections and labels; `init(Http)` turns it into a route.

```
# futures_util::FutureExt::now_or_never(async {
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
    .callback(Callback::Http(utopia_http::action(|scope| {
        let id = scope.param("teamId").and_then(|v| v.as_str()).unwrap_or("").to_owned();
        Box::pin(async move {
            scope.response.send(format!("team {id}").as_bytes());
            Ok(())
        })
    })));
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
# Ok::<(), Box<dyn std::error::Error>>(())
# }).unwrap()?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Platform\Action;
use Utopia\Platform\Module;
use Utopia\Platform\Platform;
use Utopia\Platform\Service;
use Utopia\Validator\Text;

$get = new class () extends Action {
    public function __construct()
    {
        $this->setHttpMethod('GET')
            ->setHttpPath('/v1/teams/:teamId')
            ->httpAlias('/v1/groups/:teamId')
            ->label('scope', 'teams.read')
            ->param('teamId', '', new Text(36), 'Team ID.')
            ->inject('response')
            ->callback(fn (string $teamId, $response) => $response->send("team {$teamId}"));
    }
};
$teams = new class ($get) extends Service {
    public function __construct(Action $get) { $this->type = Service::TYPE_HTTP; $this->actions = []; $this->addAction('get', $get); }
};
$platform = new class (new class () extends Module {}) extends Platform {};
$platform->addService('teams', $teams);
$platform->init(Service::TYPE_HTTP); // GET /v1/teams/:teamId and /v1/groups/:teamId
```

## Run tasks from the command line

Actions of a task service become CLI tasks named by their key; init, shutdown and error actions become the hooks around them.

```
# futures_util::FutureExt::now_or_never(async {
use std::sync::{Arc, Mutex};
use serde_json::{json, Value};
use utopia_cli::Cli;
use utopia_platform::{Action, ActionType, Callback, Init, Module, Param, Platform, Service, ServiceType};
use utopia_validators::Text;

let log = Arc::new(Mutex::new(Vec::new()));
let (l1, l2) = (log.clone(), log.clone());
let mut init = Action::new();
init.set_type(ActionType::INIT).callback(Callback::Task(Arc::new(move |_| {
    l1.lock().unwrap().push("init".to_owned());
    Box::pin(async { Ok(()) })
})));
let mut migrate = Action::new();
migrate
    .param("project", Param::new(Value::Null, Text::new(36), "Project ID."))
    .callback(Callback::Task(Arc::new(move |args| {
        l2.lock().unwrap().push(format!("migrate {}", args.value("project").and_then(Value::as_str).unwrap_or("")));
        Box::pin(async { Ok(()) })
    })));
let mut tasks = Service::new(ServiceType::TASK);
tasks.add_action("init", init).add_action("migrate", migrate);

let mut platform = Platform::new(Module::new());
platform.add_service("tasks", tasks);
platform.set_cli(Cli::new(&["app", "migrate", "--project=console"])?);
platform.init(Init::Task)?;
platform.cli().unwrap().run().await?;

assert_eq!(*log.lock().unwrap(), ["init", "migrate console"]);
# let _ = json!(null);
# Ok::<(), Box<dyn std::error::Error>>(())
# }).unwrap()?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\CLI\Adapters\Generic;
use Utopia\CLI\CLI;
use Utopia\Platform\Action;
use Utopia\Platform\Module;
use Utopia\Platform\Platform;
use Utopia\Platform\Service;
use Utopia\Validator\Text;

$init = new class () extends Action {
    public function __construct() { $this->setType(Action::TYPE_INIT)->callback(fn () => print("init\n")); }
};
$migrate = new class () extends Action {
    public function __construct()
    {
        $this->param('project', null, new Text(36), 'Project ID.')
            ->callback(fn (string $project) => print("migrate {$project}\n"));
    }
};
$tasks = new class ($init, $migrate) extends Service {
    public function __construct(Action $init, Action $migrate)
    {
        $this->type = Service::TYPE_TASK;
        $this->actions = [];
        $this->addAction('init', $init)->addAction('migrate', $migrate);
    }
};
$platform = new class (new class () extends Module {}) extends Platform {};
$platform->addService('tasks', $tasks);
$platform->setCli(new CLI(new Generic(), ['app', 'migrate', '--project=console']));
$platform->init(Service::TYPE_TASK);
$platform->getCli()->run(); // init, then migrate console
```

## Register queue workers

Actions of a worker service become jobs, each on its queue (`v1-<name>` unless configured), with its own concurrency and prefetch; worker-start and worker-stop actions become worker hooks.

```
use std::sync::Arc;
use indexmap::IndexMap;
use utopia_platform::{Action, Callback, Init, JobConfig, Module, Platform, Service, ServiceType, WorkerParams};
use utopia_queue::Server;
use utopia_queue::adapter::{Adapter, Runtime};
# use utopia_queue::{Consumer, Message, Queue};
# use utopia_queue::connection::BoxFuture;
# struct Broker;
# impl Consumer for Broker {
#     fn receive<'a>(&'a self, _: &'a Queue, _: i64, _: i64) -> BoxFuture<'a, utopia_queue::Result<Vec<Message>>> { Box::pin(async { Ok(Vec::new()) }) }
#     fn commit<'a>(&'a self, _: &'a Queue, _: &'a Message) -> BoxFuture<'a, utopia_queue::Result<()>> { Box::pin(async { Ok(()) }) }
#     fn reject<'a>(&'a self, _: &'a Queue, _: &'a Message) -> BoxFuture<'a, utopia_queue::Result<()>> { Box::pin(async { Ok(()) }) }
#     fn close(&self) -> BoxFuture<'_, ()> { Box::pin(async {}) }
# }

let job = || {
    let mut action = Action::new();
    action.callback(Callback::Worker(Arc::new(|_scope| Box::pin(async { Ok(()) }))));
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
# Ok::<(), utopia_platform::Error>(())
```

```php
use Utopia\Platform\Action;
use Utopia\Platform\Module;
use Utopia\Platform\Platform;
use Utopia\Platform\Service;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\Redis as Connection;
use Utopia\Queue\Server;

$job = fn () => new class () extends Action { public function __construct() { $this->callback(fn () => null); } };
$workers = new class ($job(), $job()) extends Service {
    public function __construct(Action $databases, Action $functions)
    {
        $this->type = Service::TYPE_WORKER;
        $this->actions = [];
        $this->addAction('databases', $databases)->addAction('functions', $functions);
    }
};
$platform = new class (new class () extends Module {}) extends Platform {};
$platform->addService('workers', $workers);
$broker = new Redis(new Connection('redis'), new Connection('redis'));
$platform->setWorker(new Server(new Swoole($broker, 1)));
$platform->init(Service::TYPE_WORKER, ['workers' => ['all'], 'jobs' => ['functions' => ['coroutines' => 8]]]);

echo implode(',', array_keys($platform->getWorker()->jobs())), "\n"; // v1-databases,v1-functions
echo $platform->getWorker()->coroutines('v1-functions'), "\n";     // 8
```
