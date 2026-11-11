An HTTP application has two lifecycles. The application's own: you build it once, start it, and serve it. And each request's: every request runs the same steps in the same order. Knowing that order tells you where code goes. Checking who is calling goes in an init hook, logging in a shutdown hook, and error pages in an error hook.

## The application

```flow
Build | `Http::new`, then routes, hooks and shared resources. All of it is registered once, before the first request
Start | `start` runs the `on_start` hooks once, with the server as the `server` resource
> Until the shutdown future resolves
Run | `serve` takes each request through the lifecycle below, in a fresh context
<
Shut down | requests in flight get `shutdown_grace` to finish, then `serve` returns
```

An application is plain data until it serves. `start` runs the start hooks, and `serve` puts the application on a socket. Every request then goes through `Http::run`.

```no_run
use std::sync::Arc;
use utopia_http::{App, Http, ServerOptions, serve};

# #[utopia_async::main]
# async fn main() -> Result<(), Box<dyn std::error::Error>> {
let mut http = Http::new("UTC");
http.on_start().action(async |_| println!("listening"));
http.get("/health")?.inject("response").action(async |scope| {
    scope.response.text("ok");
});

let http = Arc::new(http);
http.start(Arc::new("http")).await?;
serve("0.0.0.0:80".parse()?, Arc::new(App(http)), ServerOptions::default(), std::future::pending()).await?;
# Ok(())
# }
```

## A request

```flow
> For each request
Request hooks | `on_request`, for every request, before static files and routing
Static file | a file loaded with `load_files` is sent as is, and the request ends here
Match | the route for the method and path. `HEAD` runs as `GET` without a body
Init hooks | the global (`*`) hooks first, then the hooks of the route's groups
Action | the route's callback. It is skipped when an init hook already sent the response
Shutdown hooks | the hooks of the route's groups first, then the global (`*`) hooks
! Error hooks | a step failed, or no route matched (a 404). The hooks of the route's groups run, then the global ones, with the failure as `error`
```

Every hook and action receives a `Scope`: the request, the response being built, the matched route, its params and the resources it injects. Each request gets its own context, so a resource set while handling it is visible to that request only.

The example records each step. The global hooks wrap the group hooks, and the group hooks wrap the action:

```
use std::sync::{Arc, Mutex};
use utopia_http::{Bytes, Http, Request, Response, Scope};

type Log = Mutex<Vec<&'static str>>;

/// Records a step in the injected log.
fn step(scope: &Scope<'_>, name: &'static str) {
    scope.resource::<Log>("log").unwrap().lock().unwrap().push(name);
}

# #[utopia_async::main]
# async fn main() -> Result<(), Box<dyn std::error::Error>> {
let mut http = Http::new("UTC");
let log: Arc<Log> = Arc::default();
http.resources().set("log", log.clone());

http.on_request().inject("log").action(async |scope| step(&scope, "request"));
http.init().inject("log").action(async |scope| step(&scope, "init"));
http.init().groups(&["api"]).inject("log").action(async |scope| step(&scope, "api init"));
http.get("/v1/users")?.groups(&["api"]).inject("log").action(async |scope| step(&scope, "action"));
http.shutdown().groups(&["api"]).inject("log").action(async |scope| step(&scope, "api shutdown"));
http.shutdown().inject("log").action(async |scope| step(&scope, "shutdown"));

let request = Request::new("GET", "/v1/users", [], Bytes::new());
http.run(&request, &mut Response::new(), &http.context()).await?;

assert_eq!(*log.lock().unwrap(), ["request", "init", "api init", "action", "api shutdown", "shutdown"]);
# Ok(())
# }
```

## Stop a request early

An init hook that sends the response stops the action from running. This is how a hook turns away a caller. The shutdown hooks still run, so logging and metrics see the request too.

```
use std::sync::{Arc, Mutex};
use utopia_http::{Bytes, Http, Request, Response};

type Log = Mutex<Vec<&'static str>>;

# #[utopia_async::main]
# async fn main() -> Result<(), Box<dyn std::error::Error>> {
let mut http = Http::new("UTC");
let log: Arc<Log> = Arc::default();
http.resources().set("log", log.clone());

http.init().inject("request").inject("response").action(async |scope| {
    if scope.request.header_line_or("authorization", "").is_empty() {
        scope.response.set_status_code(401)?;
        scope.response.text("Sign in first");
    }
    Ok(())
});
http.get("/account")?.inject("log").action(async |scope| {
    scope.resource::<Log>("log").unwrap().lock().unwrap().push("action");
});
http.shutdown().inject("log").action(async |scope| {
    scope.resource::<Log>("log").unwrap().lock().unwrap().push("shutdown");
});

let request = Request::new("GET", "/account", [], Bytes::new());
let mut response = Response::new();
http.run(&request, &mut response, &http.context()).await?;

assert_eq!(response.status_code(), 401);
assert_eq!(*log.lock().unwrap(), ["shutdown"]);
# Ok(())
# }
```

## When something fails

```flow
Init hooks | run until one fails
Action | runs, and returns an error
! Error hooks | run in place of the rest, with the failure as `error`
```

A failure stops the request where it happens: the remaining hooks, the action and the shutdown hooks do not run. The error hooks run in their place and write the error response. A request that matches no route goes straight to the global error hooks with a 404. `run` itself returns an error only when an error hook fails too.

```
use std::sync::{Arc, Mutex};
use utopia_http::{Bytes, Error, Http, Request, Response};

type Log = Mutex<Vec<String>>;

# #[utopia_async::main]
# async fn main() -> Result<(), Box<dyn std::error::Error>> {
let mut http = Http::new("UTC");
let log: Arc<Log> = Arc::default();
http.resources().set("log", log.clone());

http.get("/teams/:teamId")?.action(async |_| Err(Error::http("Team not found", 404)));
http.shutdown().inject("log").action(async |scope| {
    scope.resource::<Log>("log").unwrap().lock().unwrap().push("shutdown".into());
});
http.error().inject("error").inject("response").inject("log").action(async |scope| {
    let error = scope.error().expect("error hooks receive the error");
    scope.resource::<Log>("log").unwrap().lock().unwrap().push(format!("error {}", error.code()));
    scope.response.set_status_code(error.code())?;
    scope.response.text(&error.to_string());
    Ok(())
});

for target in ["/teams/missing", "/nowhere"] {
    let request = Request::new("GET", target, [], Bytes::new());
    http.run(&request, &mut Response::new(), &http.context()).await?;
}

assert_eq!(*log.lock().unwrap(), ["error 404", "error 404"]);
# Ok(())
# }
```

## Special requests

| Request | What runs |
|---|---|
| `HEAD` | The `GET` route's lifecycle, and the response is sent without a body |
| `OPTIONS` | Only the options hooks (`Http::options`): those of the route's groups, then the global ones |
| A loaded static file | The request hooks, then the file. No routing and no init or shutdown hooks |
| No matching route | The global error hooks, with a 404 |
| A route with `hook(false)` | Only its groups' hooks. The global (`*`) init and shutdown hooks are skipped |
