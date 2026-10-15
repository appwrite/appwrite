# Utopia HTTP

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/http`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/http) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

An HTTP framework on hyper: routes, hooks, parameters with validators, requests and responses.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-http = { git = "https://github.com/utopia-rs/http" }
```

Utopia HTTP is a small web framework: you declare routes with typed, validated parameters, add hooks that run around them, and write the response. An application is plain data, so you can build a request, run it through the application and read the response in-process, which is how every example below works. Serving it on a socket only adds a server around the same application. This guide covers the common tasks.

## Define a route

Register a route for a method and a path, and give it an action that writes the response. `run` dispatches a request through the application.

```rust
use utopia_http::{Bytes, Http, Request, Response};

let mut http = Http::new("UTC");
http.get("/hello")?.inject("response").action(async |scope| {
    scope.response.text("Hello, World!");
    Ok(())
});

let request = Request::new("GET", "/hello", [], Bytes::new());
let mut response = Response::new();
http.run(&request, &mut response, &http.context()).await?;

assert_eq!(response.status_code(), 200);
assert_eq!(response.content_type(), "text/plain; charset=UTF-8");
assert_eq!(response.wire().body, b"Hello, World!");
```

## Read path and query parameters

Name a path segment with `:` and declare it as a param. Query string values are params too. An optional param falls back to its default when the request does not send it.

```rust
use serde_json::{Value, json};
use utopia_http::{Bytes, Http, Param, Request, Response};
use utopia_validators::Text;

let mut http = Http::new("UTC");
http.get("/users/:userId")?
    .param("userId", Param::new(json!(""), Text::new(36), "The user ID."))
    .param("fields", Param::new(json!("name"), Text::new(64), "Fields to return.").optional(true))
    .inject("response")
    .action(async |scope| {
        let user = scope.param("userId").and_then(Value::as_str).unwrap_or_default();
        let fields = scope.param("fields").and_then(Value::as_str).unwrap_or_default();
        let text = format!("{user}: {fields}");
        scope.response.text(&text);
        Ok(())
    });

let mut bodies = Vec::new();
for target in ["/users/ada?fields=email", "/users/grace"] {
    let request = Request::new("GET", target, [], Bytes::new());
    let mut response = Response::new();
    http.run(&request, &mut response, &http.context()).await?;
    bodies.push(String::from_utf8(response.wire().body.clone())?);
}

assert_eq!(bodies, ["ada: email", "grace: name"]);
```

## Validate parameters

Every param has a validator. A value that fails it, or a missing required param, stops the request with a 400 before the action runs. An error hook turns that into a response.

```rust
use serde_json::{Value, json};
use utopia_http::{Bytes, Http, Param, Request, Response};
use utopia_validators::{Range, WhiteList};

let mut http = Http::new("UTC");
http.get("/files")?
    .param("limit", Param::new(json!("25"), Range::new(1, 100), "Page size.").optional(true))
    .param("order", Param::new(json!("asc"), WhiteList::new(&["asc", "desc"]), "Sort order.").optional(true))
    .inject("response")
    .action(async |scope| {
        let limit = scope.param("limit").and_then(Value::as_str).unwrap_or_default();
        let order = scope.param("order").and_then(Value::as_str).unwrap_or_default();
        let text = format!("{limit} {order}");
        scope.response.text(&text);
        Ok(())
    });
http.error().inject("error").inject("response").action(async |scope| {
    let error = scope.error().expect("error hooks receive the error");
    scope.response.set_status_code(error.code())?;
    scope.response.text(&error.to_string());
    Ok(())
});

let mut results = Vec::new();
for target in ["/files?limit=50&order=desc", "/files?limit=500", "/files?order=random"] {
    let request = Request::new("GET", target, [], Bytes::new());
    let mut response = Response::new();
    http.run(&request, &mut response, &http.context()).await?;
    results.push(format!("{} {}", response.status_code(), String::from_utf8(response.wire().body.clone())?));
}

assert_eq!(results, [
    "200 50 desc",
    "400 Invalid `limit` param: Value must be a valid range between 1 and 100",
    "400 Invalid `order` param: Value must be one of (asc, desc)",
]);
```

## Read a JSON body and return JSON

For `POST`, `PUT`, `PATCH` and `DELETE`, params come from the body: a JSON object or form fields. `json` encodes a value and sets the content type.

```rust
use serde_json::{Value, json};
use utopia_http::{Bytes, Http, Param, Request, Response};
use utopia_validators::{Range, Text};

let mut http = Http::new("UTC");
http.post("/users")?
    .param("name", Param::new(json!(""), Text::new(128), "Full name."))
    .param("age", Param::new(json!(0), Range::new(0, 150), "Age in years."))
    .inject("response")
    .action(async |scope| {
        let user = json!({
            "name": scope.param("name").cloned().unwrap_or(Value::Null),
            "age": scope.param("age").cloned().unwrap_or(Value::Null),
        });
        scope.response.set_status_code(201)?;
        scope.response.json(&user)?;
        Ok(())
    });

let body = Bytes::from_static(br#"{"name":"Ada Lovelace","age":36}"#);
let request = Request::new("POST", "/users", [("content-type", "application/json")], body);
let mut response = Response::new();
http.run(&request, &mut response, &http.context()).await?;

assert_eq!(response.status_code(), 201);
assert_eq!(response.content_type(), "application/json; charset=UTF-8");
assert_eq!(response.wire().body, br#"{"name":"Ada Lovelace","age":36}"#);
```

## Set headers and cookies

Read request headers from the request, and add headers and cookies to the response before sending it. Header names are case-insensitive and cookie names are lowercased.

```rust
use utopia_http::{Bytes, Cookie, Http, Request, Response};

let mut http = Http::new("UTC");
http.get("/session")?.inject("request").inject("response").action(async |scope| {
    let agent = scope.request.header_line_or("user-agent", "unknown");
    scope.response.add_header("Cache-Control", "no-store");
    scope.response.add_cookie(Cookie {
        name: "Session".into(),
        value: Some("abc123".into()),
        http_only: Some(true),
        ..Cookie::default()
    });
    scope.response.text(&agent);
    Ok(())
});

let request = Request::new("GET", "/session", [("User-Agent", "curl/8.0")], Bytes::new());
let mut response = Response::new();
http.run(&request, &mut response, &http.context()).await?;

assert_eq!(response.header_line("cache-control").as_deref(), Some("no-store"));
assert_eq!(response.cookies()[0].1.name, "session");
assert_eq!(response.wire().body, b"curl/8.0");
```

## Run code before and after actions

Init hooks run before the action and shutdown hooks after it. A hook without groups runs for every route. A hook with groups runs only for routes in one of them, after the global ones.

```rust
use std::sync::{Arc, Mutex};
use utopia_http::{Bytes, Http, Request, Response};

type Log = Mutex<Vec<&'static str>>;

let mut http = Http::new("UTC");
let log: Arc<Log> = Arc::default();
http.resources().set("log", log.clone());

http.init().inject("log").action(async |scope| {
    scope.resource::<Log>("log").unwrap().lock().unwrap().push("init");
    Ok(())
});
http.init().groups(&["api"]).inject("log").action(async |scope| {
    scope.resource::<Log>("log").unwrap().lock().unwrap().push("api init");
    Ok(())
});
http.shutdown().inject("log").action(async |scope| {
    scope.resource::<Log>("log").unwrap().lock().unwrap().push("shutdown");
    Ok(())
});
http.get("/v1/users")?.groups(&["api"]).inject("log").action(async |scope| {
    scope.resource::<Log>("log").unwrap().lock().unwrap().push("action");
    Ok(())
});

let request = Request::new("GET", "/v1/users", [], Bytes::new());
http.run(&request, &mut Response::new(), &http.context()).await?;

assert_eq!(*log.lock().unwrap(), ["init", "api init", "action", "shutdown"]);
```

## Handle errors and unknown routes

An action fails by returning an error with a message and an HTTP code. The error hooks receive it, and they also run with a 404 when no route matches.

```rust
use serde_json::json;
use utopia_http::{Bytes, Error, Http, Request, Response};

let mut http = Http::new("UTC");
http.get("/teams/:teamId")?.action(async |_| Err(Error::http("Team not found", 404)));
http.error().inject("error").inject("response").action(async |scope| {
    let error = scope.error().expect("error hooks receive the error");
    scope.response.set_status_code(error.code())?;
    scope.response.json(&json!({ "message": error.to_string(), "code": error.code() }))?;
    Ok(())
});

let mut bodies = Vec::new();
for target in ["/teams/missing", "/nowhere"] {
    let request = Request::new("GET", target, [], Bytes::new());
    let mut response = Response::new();
    http.run(&request, &mut response, &http.context()).await?;
    bodies.push(String::from_utf8(response.wire().body.clone())?);
}

assert_eq!(bodies, [r#"{"message":"Team not found","code":404}"#, r#"{"message":"Not Found","code":404}"#]);
```

## Share resources with actions

Register services such as a database or a mailer once, and inject them by name into the routes and hooks that need them. Resources set on a request's context are visible to that request only.

```rust
use std::sync::Arc;
use serde_json::{Value, json};
use utopia_http::{Bytes, Http, Param, Request, Response};
use utopia_validators::Text;

struct Greeter {
    greeting: String,
}

let mut http = Http::new("UTC");
http.resources().set("greeter", Arc::new(Greeter { greeting: "Hello".into() }));

http.get("/greet/:name")?
    .param("name", Param::new(json!(""), Text::new(64), "Who to greet."))
    .inject("greeter")
    .inject("locale")
    .inject("response")
    .action(async |scope| {
        let greeting = &scope.resource::<Greeter>("greeter").unwrap().greeting;
        let locale = scope.resource::<String>("locale").unwrap();
        let name = scope.param("name").and_then(Value::as_str).unwrap_or_default();
        let text = format!("{greeting}, {name}! ({locale})");
        scope.response.text(&text);
        Ok(())
    });

let context = http.context();
context.set("locale", Arc::new("en".to_owned()));
let request = Request::new("GET", "/greet/Ada", [], Bytes::new());
let mut response = Response::new();
http.run(&request, &mut response, &context).await?;

assert_eq!(response.wire().body, b"Hello, Ada! (en)");
```

## Serve a route under several paths and methods

`alias` registers the same route under another path, for example to keep a legacy URL working. `routes` registers one route for several methods. The action, params and hooks are defined once.

```rust
use serde_json::{Value, json};
use utopia_http::{Bytes, Http, Param, Request, Response};
use utopia_validators::Text;

let mut http = Http::new("UTC");
let mut route = http
    .get("/users/:userId")?
    .param("userId", Param::new(json!(""), Text::new(36), "The user ID."))
    .inject("response")
    .action(async |scope| {
        let user = scope.param("userId").and_then(Value::as_str).unwrap_or_default().to_owned();
        scope.response.text(&user);
        Ok(())
    });
route.alias("/members/:userId")?;

http.routes(&["GET", "POST"], "/oauth/userinfo")?.inject("request").inject("response").action(async |scope| {
    let method = scope.request.method().to_owned();
    scope.response.text(&method);
    Ok(())
});

let mut bodies = Vec::new();
for (method, target) in [("GET", "/users/ada"), ("GET", "/members/ada"), ("GET", "/oauth/userinfo"), ("POST", "/oauth/userinfo")] {
    let request = Request::new(method, target, [], Bytes::new());
    let mut response = Response::new();
    http.run(&request, &mut response, &http.context()).await?;
    bodies.push(String::from_utf8(response.wire().body.clone())?);
}

assert_eq!(bodies, ["ada", "ada", "GET", "POST"]);
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
