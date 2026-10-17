//! Compat adapter for `http`: maps `tests/compat/http/spec.json` operations
//! onto `utopia-http`. Applications are built from the same JSON description
//! as `tests/compat/http/Harness.php` and requests are dispatched in process,
//! without a socket; glue only.

use std::sync::{Arc, Mutex};

use serde_json::{Map, Value, json};
use utopia_http::{
    Check, Compression, Cookie, Error, Files, Hook, Http, ParamDefault, Request, Response, Route, Router, Scope,
    TrustedHeaders, View, Wire, params_value,
};
use utopia_validators::Validator;

use crate::adapter::{Args, Fault, OpResult, Outcome, bytes_value};

pub const OPS: &[&str] = &[
    "http.run",
    "http.start",
    "http.mode",
    "http.env",
    "request.inspect",
    "response.run",
    "router.run",
    "route.inspect",
    "view.run",
    "files.run",
    "trusted.new",
    "mode.settings",
    "compression.negotiate",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("match", "Match a document path with three params against a router of 40 API routes"),
    (
        "request",
        "Parse a JSON POST request (9 headers, a cookie, a query string) and read a header, the cookie, the query and the body params",
    ),
    ("response", "Build a 201 response with a header, a session cookie and a JSON user of 18 fields"),
    (
        "dispatch",
        "Run a GET request through an app of 40 routes: match, validate three path params, an init hook and a JSON action",
    ),
];

/// Benchmark inputs, the same as in Adapter.php.
mod fixture {
    use serde_json::{Value, json};

    /// An API's routes (method and path).
    pub const ROUTES: [(&str, &str); 40] = [
        ("GET", "/v1/account"),
        ("PATCH", "/v1/account/name"),
        ("POST", "/v1/account/sessions/email"),
        ("GET", "/v1/account/sessions"),
        ("DELETE", "/v1/account/sessions/:sessionId"),
        ("POST", "/v1/account/jwts"),
        ("GET", "/v1/users"),
        ("POST", "/v1/users"),
        ("GET", "/v1/users/:userId"),
        ("DELETE", "/v1/users/:userId"),
        ("GET", "/v1/users/:userId/sessions"),
        ("GET", "/v1/teams"),
        ("POST", "/v1/teams"),
        ("GET", "/v1/teams/:teamId"),
        ("GET", "/v1/teams/:teamId/memberships"),
        ("PATCH", "/v1/teams/:teamId/memberships/:membershipId/status"),
        ("GET", "/v1/databases"),
        ("POST", "/v1/databases"),
        ("GET", "/v1/databases/:databaseId"),
        ("GET", "/v1/databases/:databaseId/collections"),
        ("POST", "/v1/databases/:databaseId/collections"),
        ("GET", "/v1/databases/:databaseId/collections/:collectionId"),
        ("GET", "/v1/databases/:databaseId/collections/:collectionId/attributes"),
        ("POST", "/v1/databases/:databaseId/collections/:collectionId/attributes/string"),
        ("GET", "/v1/databases/:databaseId/collections/:collectionId/documents"),
        ("POST", "/v1/databases/:databaseId/collections/:collectionId/documents"),
        ("GET", DOCUMENT_ROUTE),
        ("PATCH", DOCUMENT_ROUTE),
        ("DELETE", DOCUMENT_ROUTE),
        ("GET", "/v1/storage/buckets"),
        ("GET", "/v1/storage/buckets/:bucketId/files"),
        ("POST", "/v1/storage/buckets/:bucketId/files"),
        ("GET", "/v1/storage/buckets/:bucketId/files/:fileId"),
        ("GET", "/v1/storage/buckets/:bucketId/files/:fileId/view"),
        ("GET", "/v1/storage/buckets/:bucketId/files/:fileId/preview"),
        ("GET", "/v1/functions"),
        ("POST", "/v1/functions/:functionId/executions"),
        ("GET", "/v1/functions/:functionId/executions/:executionId"),
        ("GET", "/v1/health"),
        ("GET", "/v1/locale"),
    ];
    pub const DOCUMENT_ROUTE: &str = "/v1/databases/:databaseId/collections/:collectionId/documents/:documentId";
    pub const DOCUMENT_PATH: &str = "/v1/databases/main/collections/posts/documents/6650c1f2a4b3e9d7c812";
    pub const SESSION: &str = "eyJpZCI6IjY2NTBjMWYyYTRiM2U5ZDdjODEyIiwic2VjcmV0IjoiNWYzYzlhMWU1ZjNjOWExZSJ9";
    pub const NOW: i64 = 1_700_000_000;

    /// A JSON POST as a browser SDK sends it.
    pub fn post() -> String {
        let body = r#"{"documentId":"unique()","data":{"title":"Release notes","status":"published","views":1024,"tags":["rust","php"]},"permissions":["read(\"any\")","update(\"user:6650c1f2a4b3e9d7c812\")"]}"#;
        format!(
            "POST /v1/databases/main/collections/posts/documents?project=6650c0de HTTP/1.1\r\n\
             Host: cloud.appwrite.io\r\n\
             User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36\r\n\
             Accept: application/json\r\n\
             Content-Type: application/json\r\n\
             Origin: https://app.example.com\r\n\
             X-Appwrite-Project: 6650c0de\r\n\
             X-Appwrite-Response-Format: 1.6.0\r\n\
             Cookie: a_session_6650c0de={SESSION}; a_session_6650c0de_legacy={SESSION}\r\n\
             Content-Length: {}\r\n\
             \r\n\
             {body}",
            body.len()
        )
    }

    /// A user as the API returns it.
    pub fn user() -> Value {
        json!({
            "$id": "6650c1f2a4b3e9d7c812",
            "$createdAt": "2024-05-24T10:15:00.000+00:00",
            "$updatedAt": "2024-05-24T10:15:00.000+00:00",
            "name": "Ada Lovelace",
            "registration": "2024-05-24T10:15:00.000+00:00",
            "status": true,
            "labels": ["admin", "beta"],
            "passwordUpdate": "2024-05-24T10:15:00.000+00:00",
            "email": "ada@example.com",
            "phone": "+14155550100",
            "emailVerification": true,
            "phoneVerification": false,
            "mfa": false,
            "prefs": {"theme": "dark", "language": "en"},
            "targets": [],
            "accessedAt": "2024-05-24T10:15:00.000+00:00",
            "hash": "argon2",
            "hashOptions": {"type": "argon2", "memoryCost": 7168, "timeCost": 5, "threads": 1},
        })
    }
}

pub async fn bench(name: &str, _session: &mut crate::adapter::Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;
    use std::rc::Rc;

    use crate::bench::Bench;
    use fixture::*;

    let failed = |e: Error| Fault::new(e.to_string());
    Ok(match name {
        "match" => {
            let mut router = Router::new();
            for (i, (method, path)) in ROUTES.iter().enumerate() {
                router.insert(&[method], path, i).map_err(failed)?;
            }
            Bench::sync(move || {
                black_box(router.find(black_box("GET"), black_box(DOCUMENT_PATH)).map(|m| m.params));
            })
        }
        "request" => {
            let raw = post().into_bytes();
            Bench::sync(move || {
                let mut headers = [httparse::EMPTY_HEADER; 16];
                let mut parsed = httparse::Request::new(&mut headers);
                let Ok(httparse::Status::Complete(head)) = parsed.parse(black_box(&raw)) else { return };
                let request = Request::new(
                    parsed.method.unwrap_or("GET"),
                    parsed.path.unwrap_or("/"),
                    parsed.headers.iter().map(|h| (h.name, std::str::from_utf8(h.value).unwrap_or(""))),
                    utopia_http::Bytes::copy_from_slice(&raw[head..]),
                );
                black_box(request.header_values("x-appwrite-project"));
                black_box(request.cookie("a_session_6650c0de"));
                black_box(request.query("project"));
                black_box(request.params());
            })
        }
        "response" => {
            let user = user();
            Bench::sync(move || {
                let mut response = Response::new();
                let _ = response.set_status_code(201);
                response.add_header("X-Appwrite-Request-Id", "6650c3b9d2e7f4a1c836");
                response.add_cookie(Cookie {
                    name: "a_session_6650c0de".to_owned(),
                    value: Some(SESSION.to_owned()),
                    expire: Some(NOW + 31_536_000),
                    path: Some("/".to_owned()),
                    domain: Some(".cloud.appwrite.io".to_owned()),
                    secure: Some(true),
                    http_only: Some(true),
                    same_site: Some("None".to_owned()),
                });
                let _ = response.json(black_box(&user));
                // The server renders each Set-Cookie line (Swoole does on PHP).
                for cookie in &response.wire().cookies {
                    black_box(cookie.header(NOW));
                }
                black_box(response);
            })
        }
        "dispatch" => {
            async fn nosniff(scope: Scope<'_>) -> utopia_http::Result<()> {
                scope.response.add_header("X-Content-Type-Options", "nosniff");
                Ok(())
            }
            async fn document(scope: Scope<'_>) -> utopia_http::Result<()> {
                let param = |key: &str| scope.param(key).cloned().unwrap_or(Value::Null);
                let body = json!({
                    "$id": param("documentId"),
                    "$collectionId": param("collectionId"),
                    "$databaseId": param("databaseId"),
                    "$createdAt": "2024-05-24T10:15:00.000+00:00",
                    "$updatedAt": "2024-05-24T10:15:00.000+00:00",
                    "$permissions": ["read(\"any\")"],
                    "title": "Release notes",
                    "views": 1024,
                });
                scope.response.json(&body)
            }
            let mut http = Http::new("UTC");
            http.init().groups(&["api"]).inject("response").action(nosniff);
            for (method, path) in ROUTES {
                let route = http.add_route(method, path).map_err(failed)?;
                if method == "GET" && path == DOCUMENT_ROUTE {
                    let id = || utopia_http::Param::new(json!(""), utopia_validators::Text::new(36), "");
                    route
                        .groups(&["api", "databases"])
                        .param("databaseId", id())
                        .param("collectionId", id())
                        .param("documentId", id())
                        .inject("response")
                        .action(document);
                }
            }
            let request = Request::new(
                "GET",
                DOCUMENT_PATH,
                [
                    ("Host", "cloud.appwrite.io"),
                    ("Accept", "application/json"),
                    ("X-Appwrite-Project", "6650c0de"),
                    ("X-Appwrite-Response-Format", "1.6.0"),
                ],
                utopia_http::Bytes::new(),
            );
            let (http, request) = (Rc::new(http), Rc::new(request));
            Bench::future(move || {
                let (http, request) = (http.clone(), request.clone());
                async move {
                    let context = http.context();
                    let mut response = Response::new();
                    black_box(http.run(&request, &mut response, &context).await.ok());
                    black_box(response);
                }
            })
        }
        _ => return Err(Fault::new(format!("http: no benchmark `{name}`"))),
    })
}

/// Values emitted by actions of the current dispatch.
pub(crate) type Out = Arc<Mutex<Vec<Value>>>;

/// A harness problem raised inside an action (PHP's `Fault`).
const FAULT: &str = "$fault";

fn fault(message: impl Into<String>) -> Error {
    Error::custom(FAULT, message, 0)
}

fn emit(out: &Out, v: Value) {
    out.lock().unwrap_or_else(|e| e.into_inner()).push(v);
}

fn take(out: &Out) -> Vec<Value> {
    std::mem::take(&mut *out.lock().unwrap_or_else(|e| e.into_inner()))
}

pub async fn call(op: &str, args: &Value, _session: &mut crate::adapter::Session) -> OpResult {
    let a = Args(args);
    let result = match op {
        "http.run" => run(&a).await,
        "http.start" => start(&a).await,
        "http.mode" => {
            let mut http = Http::new("UTC");
            if let Some(v) = a.0.get("set") {
                http.set_mode(v.as_str().unwrap_or(""));
            }
            Ok(json!({
                "mode": http.mode(),
                "production": http.is_production(),
                "development": http.is_development(),
                "stage": http.is_stage(),
            }))
        }
        "http.env" => Ok(Http::env(a.str("key")?)
            .map(Value::String)
            .unwrap_or_else(|| a.opt("default").cloned().unwrap_or(Value::Null))),
        "request.inspect" => inspect(&a),
        "response.run" => respond(&a).await,
        "router.run" => router(&a),
        "route.inspect" => route_inspect(&a),
        "view.run" => view(&a),
        "files.run" => files(&a),
        "trusted.new" => {
            let list = |k: &str| -> Vec<String> {
                a.opt(k).and_then(Value::as_array).map(|l| l.iter().map(php_string).collect()).unwrap_or_default()
            };
            let t = match a.opt("proto") {
                Some(_) => TrustedHeaders::new(list("ip"), list("proto")),
                None => TrustedHeaders::new(list("ip"), ["x-forwarded-proto"]),
            };
            Ok(json!({"ip": t.ip(), "proto": t.proto()}))
        }
        "mode.settings" => Ok(settings(a.str("mode")?)),
        "compression.negotiate" => {
            let supported: Vec<String> = a
                .opt("supported")
                .and_then(Value::as_array)
                .map(|l| l.iter().map(php_string).collect())
                .unwrap_or_default();
            Ok(utopia_http::compression::from_accept_encoding(a.str("accept")?, &supported)
                .map(|alg| Value::String(alg.content_encoding().to_owned()))
                .unwrap_or(Value::Null))
        }
        _ => return Err(Fault::new(format!("http: unknown operation `{op}`"))),
    };
    result.map(Outcome::Ok)
}

// ---------------------------------------------------------------------------
// Values
// ---------------------------------------------------------------------------

/// PHP's `(string)` of a value (arrays as JSON, like the PHP harness).
fn php_string(v: &Value) -> String {
    match v {
        Value::Null => String::new(),
        Value::Bool(true) => "1".into(),
        Value::Bool(false) => String::new(),
        Value::Number(n) => match n.as_i64() {
            Some(i) => i.to_string(),
            None => php_std::number::to_string(n.as_f64().unwrap_or(0.0)),
        },
        Value::String(s) => s.clone(),
        other => {
            php_std::json::encode(&php_std::Value::<php_std::Never>::from_json(other), php_std::json::Flags::NONE, 512)
                .unwrap_or_default()
        }
    }
}

/// A PHP array from the request model (empty is `[]`).
fn array(map: &Map<String, Value>) -> Value {
    params_value(map)
}

fn error_json(e: &Error) -> Value {
    json!({
        "class": e.php_class(),
        "message": e.to_string(),
        "code": e.code(),
        "previous": e.previous().map(error_json),
    })
}

fn is_fault(e: &Error) -> Option<Fault> {
    (e.php_class() == FAULT).then(|| Fault::new(e.to_string()))
}

fn wire_json(wire: &Wire) -> Value {
    let mut headers = Map::new();
    let mut order = Vec::new();
    for (name, values) in &wire.headers {
        order.push(Value::String(name.clone()));
        headers.insert(name.clone(), json!(values));
    }
    let now =
        std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.as_secs() as i64).unwrap_or(0);
    let decoded = wire
        .headers
        .iter()
        .find(|(n, _)| n == "content-encoding")
        .and_then(|(_, v)| v.first())
        .and_then(|e| utopia_http::Algorithm::from_name(e))
        .and_then(|a| a.decompress(&wire.body));
    let mut out = json!({
        "status": wire.status,
        "headers": if headers.is_empty() { json!([]) } else { Value::Object(headers) },
        "order": order,
        "cookies": wire.cookies.iter().map(|c| c.header(now).map(Value::String).unwrap_or(Value::Bool(false))).collect::<Vec<_>>(),
        "body": bytes_value(&wire.body),
        "writes": wire.writes,
        "ended": wire.ended,
    });
    if wire.aborted {
        out["aborted"] = Value::Bool(true);
    }
    if let Some(d) = decoded {
        out["decoded"] = bytes_value(&d);
    }
    out
}

// ---------------------------------------------------------------------------
// Requests
// ---------------------------------------------------------------------------

/// A request from its parts (as Swoole parses the same bytes on PHP).
fn request(spec: &Value) -> Result<Request, Fault> {
    let s = Args(spec);
    const METHODS: [&str; 9] = ["GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS", "TRACE", "CONNECT"];
    let target = s.opt_str("uri")?.unwrap_or("/");
    let origin_or_absolute =
        (target.starts_with('/') || target.starts_with("http://") || target.starts_with("https://"))
            && target.bytes().all(|b| b.is_ascii_graphic());
    if !METHODS.contains(&s.opt_str("method")?.unwrap_or("GET")) || !origin_or_absolute {
        return Err(Fault::new("a request line needs a known method and a target"));
    }
    let body: Vec<u8> = match spec.get("body_json") {
        Some(v) => {
            php_std::json::encode(&php_std::Value::<php_std::Never>::from_json(v), php_std::json::Flags::NONE, 512)
                .unwrap_or_default()
                .into_bytes()
        }
        None => match s.opt("body") {
            Some(b) => crate::adapter::bytes(b).ok_or_else(|| Fault::new("body"))?,
            None => Vec::new(),
        },
    };
    let mut headers: Vec<(String, String)> = Vec::new();
    for h in s.opt("headers").and_then(Value::as_array).into_iter().flatten() {
        let (n, v) = match h {
            Value::Array(pair) => (pair.first(), pair.get(1)),
            Value::Object(o) => (o.get("n"), o.get("v")),
            _ => (None, None),
        };
        let name = n.map(php_string).unwrap_or_default();
        if name.is_empty() {
            return Err(Fault::new("a header needs a name"));
        }
        headers.push((name, v.map(php_string).unwrap_or_default()));
    }
    if !body.is_empty() && !headers.iter().any(|(n, _)| n.eq_ignore_ascii_case("content-length")) {
        headers.push(("Content-Length".into(), body.len().to_string()));
    }
    let mut request = Request::new(
        s.opt_str("method")?.unwrap_or("GET"),
        s.opt_str("uri")?.unwrap_or("/"),
        headers.iter().map(|(n, v)| (n.as_str(), v.as_str())),
        utopia_http::Bytes::from(body),
    )
    .with_protocol(s.opt_str("protocol")?.unwrap_or("HTTP/1.1"));
    if let Some(addr) = s.opt_str("remote_addr")? {
        request = request.with_remote(addr, None);
    }
    if let Some(t) = s.opt("trusted") {
        let list = |k: &str| -> Option<Vec<String>> {
            t.get(k).and_then(Value::as_array).map(|l| l.iter().map(php_string).collect())
        };
        let trusted = TrustedHeaders::new(
            list("ip").unwrap_or_default(),
            list("proto").unwrap_or_else(|| vec!["x-forwarded-proto".into()]),
        );
        request = request.with_trusted(Arc::new(trusted));
    }
    Ok(request)
}

fn split(getter: &Value) -> Result<(String, Vec<Value>), Fault> {
    match getter {
        Value::String(s) => Ok((s.clone(), Vec::new())),
        Value::Array(a) if !a.is_empty() => Ok((php_string(&a[0]), a[1..].to_vec())),
        _ => Err(Fault::new(format!("getter: {getter}"))),
    }
}

fn arg_str(a: &[Value], i: usize) -> Option<String> {
    a.get(i).map(php_string)
}

/// Uploaded files with each `tmp_name` replaced by the file's content.
fn contents(v: &Value, tmp: bool) -> Value {
    match v {
        Value::Object(o) => {
            Value::Object(o.iter().map(|(k, x)| (k.clone(), contents(x, tmp || k == "tmp_name"))).collect())
        }
        Value::Array(a) => Value::Array(a.iter().map(|x| contents(x, tmp)).collect()),
        Value::String(s) if tmp && !s.is_empty() => bytes_value(&std::fs::read(s).unwrap_or_default()),
        other => other.clone(),
    }
}

fn request_get(r: &Request, getter: &Value) -> Result<Outcome, Fault> {
    let (name, a) = split(getter)?;
    if a.is_empty()
        && matches!(
            name.as_str(),
            "query" | "payload" | "param" | "cookie" | "header" | "header_line" | "has_header" | "files" | "server"
        )
    {
        return Err(Fault::new(format!("getter {name} needs a key")));
    }
    let or = |v: Option<&Value>, i: usize| v.cloned().unwrap_or_else(|| a.get(i).cloned().unwrap_or(Value::Null));
    let opt_int = |v: Option<i64>| v.map(Value::from).unwrap_or(Value::Null);
    let v = match name.as_str() {
        "method" => Value::String(r.method().to_owned()),
        "uri" => Value::String(r.uri().to_owned()),
        "protocol" => Value::String(r.protocol()),
        "hostname" => Value::String(r.hostname()),
        "port" => Value::String(r.port()),
        "ip" => Value::String(r.ip()),
        "raw_payload" => bytes_value(r.raw_payload()),
        "size" => Value::from(r.size()),
        "params" => array(r.params()),
        "headers" => {
            let mut m = Map::new();
            for (k, v) in r.headers().iter() {
                m.insert(k.to_owned(), json!(v));
            }
            if m.is_empty() { json!([]) } else { Value::Object(m) }
        }
        "cookies" => array(r.cookies()),
        "referer" => Value::String(r.referer(&arg_str(&a, 0).unwrap_or_default())),
        "origin" => Value::String(r.origin(&arg_str(&a, 0).unwrap_or_default())),
        "user_agent" => Value::String(r.user_agent(&arg_str(&a, 0).unwrap_or_default())),
        "accept" => Value::String(r.accept(&arg_str(&a, 0).unwrap_or_default())),
        "query" => or(r.query(&arg_str(&a, 0).unwrap_or_default()), 1),
        "payload" => or(r.payload(&arg_str(&a, 0).unwrap_or_default()), 1),
        "param" => or(r.param(&arg_str(&a, 0).unwrap_or_default()), 1),
        "cookie" => {
            let key = arg_str(&a, 0).unwrap_or_default();
            match r.cookies().get(&key).filter(|v| !v.is_null()) {
                Some(Value::String(s)) => Value::String(s.clone()),
                // PHP's getCookie() is declared to return a string.
                Some(_) => {
                    return Ok(Outcome::err(
                        "TypeError",
                        "Utopia\\Http\\Request::getCookie(): Return value must be of type string, array returned",
                    ));
                }
                None => Value::String(arg_str(&a, 1).unwrap_or_default()),
            }
        }
        "header" => json!(r.header_values(&arg_str(&a, 0).unwrap_or_default())),
        "header_line" => {
            Value::String(r.header_line_or(&arg_str(&a, 0).unwrap_or_default(), &arg_str(&a, 1).unwrap_or_default()))
        }
        "has_header" => Value::Bool(r.has_header(&arg_str(&a, 0).unwrap_or_default())),
        "files" => match r.files(&arg_str(&a, 0).unwrap_or_default()) {
            Some(v) => contents(v, false),
            None => json!([]),
        },
        "server" => r
            .server(&arg_str(&a, 0).unwrap_or_default())
            .map(Value::String)
            .unwrap_or_else(|| a.get(1).cloned().unwrap_or(Value::Null)),
        "content_range_start" => opt_int(r.content_range().map(|c| c.start)),
        "content_range_end" => opt_int(r.content_range().and_then(|c| c.end)),
        "content_range_size" => opt_int(r.content_range().and_then(|c| c.size)),
        "content_range_unit" => r.content_range().map(|c| Value::String(c.unit)).unwrap_or(Value::Null),
        "range_start" => opt_int(r.range().map(|c| c.start)),
        "range_end" => opt_int(r.range().and_then(|c| c.end)),
        "range_unit" => r.range().map(|c| Value::String(c.unit)).unwrap_or(Value::Null),
        _ => return Err(Fault::new(format!("unknown request getter {name}"))),
    };
    Ok(Outcome::Ok(v))
}

fn to_params(v: &Value) -> utopia_http::Params {
    match v {
        Value::Object(o) => o.clone(),
        Value::Array(a) => a.iter().enumerate().map(|(i, x)| (i.to_string(), x.clone())).collect(),
        _ => Map::new(),
    }
}

fn inspect(a: &Args<'_>) -> Result<Value, Fault> {
    let mut r = request(a.value("request")?)?;
    let mut out = Vec::new();
    for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
        let (op, v) = step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("step"))?;
        let s = |i: usize| v.get(i).map(php_string).unwrap_or_default();
        match op.as_str() {
            "get" => match request_get(&r, v)? {
                Outcome::Ok(v) => out.push(v),
                err => return Ok(err.to_json()),
            },
            "set_header" => {
                r.set_header(&s(0), s(1));
            }
            "add_header" => {
                r.add_header(&s(0), s(1));
            }
            "remove_header" => {
                r.remove_header(&php_string(v));
            }
            "set_query" => {
                r.set_query(to_params(v));
            }
            "set_payload" => {
                r.set_payload(to_params(v));
            }
            "set_cookies" => {
                r.set_cookies(to_params(v));
            }
            "set_server" => {
                r.set_server(&s(0), s(1));
            }
            "set_method" => {
                r.set_method(php_string(v));
            }
            "set_uri" => {
                r.set_uri(php_string(v));
            }
            _ => return Err(Fault::new(format!("unknown request step {op}"))),
        }
    }
    Ok(Value::Array(out))
}

// ---------------------------------------------------------------------------
// Responses and statements
// ---------------------------------------------------------------------------

fn response_get(r: &Response, getter: &Value) -> Result<Value, Fault> {
    let (name, a) = split(getter)?;
    Ok(match name.as_str() {
        "status" => Value::from(r.status_code()),
        "content_type" => Value::String(r.content_type().to_owned()),
        "is_sent" => Value::Bool(r.is_sent()),
        "size" => Value::from(r.size()),
        "headers" => {
            let mut m = Map::new();
            for (k, v) in r.headers().iter() {
                m.insert(k.to_owned(), json!(v));
            }
            if m.is_empty() { json!([]) } else { Value::Object(m) }
        }
        "header" => json!(r.header(&arg_str(&a, 0).unwrap_or_default())),
        "header_line" => Value::String(
            r.header_line(&arg_str(&a, 0).unwrap_or_default()).unwrap_or_else(|| arg_str(&a, 1).unwrap_or_default()),
        ),
        "has_header" => Value::Bool(r.has_header(&arg_str(&a, 0).unwrap_or_default())),
        "cookies" => {
            let cookie = |c: &Cookie| {
                json!({
                    "name": c.name, "value": c.value, "expire": c.expire, "path": c.path, "domain": c.domain,
                    "secure": c.secure, "httponly": c.http_only, "samesite": c.same_site,
                })
            };
            let list = r.cookies();
            if list.iter().enumerate().all(|(i, (k, _))| i == *k) {
                Value::Array(list.iter().map(|(_, c)| cookie(c)).collect())
            } else {
                Value::Object(list.iter().map(|(k, c)| (k.to_string(), cookie(c))).collect())
            }
        }
        _ => return Err(Fault::new(format!("unknown response getter {name}"))),
    })
}

fn route_get(route: Option<&Route>, getter: &Value) -> Result<Value, Fault> {
    let Some(route) = route else { return Ok(Value::Null) };
    let (name, a) = split(getter)?;
    Ok(match name.as_str() {
        "path" => Value::String(route.path().to_owned()),
        "method" => Value::String(route.method().to_owned()),
        "methods" => json!(route.methods()),
        "groups" => json!(route.get_groups()),
        "desc" => Value::String(route.description().to_owned()),
        "hook" => Value::Bool(route.hooks()),
        "label" => route
            .get_label(&arg_str(&a, 0).unwrap_or_default())
            .cloned()
            .unwrap_or_else(|| a.get(1).cloned().unwrap_or(Value::Null)),
        "params" => json!(route.params().keys().collect::<Vec<_>>()),
        "injections" => json!(route.injections()),
        "order" => Value::Bool(route.order() > 0),
        _ => return Err(Fault::new(format!("unknown route getter {name}"))),
    })
}

/// What statements run against: an action's scope or a bare response.
enum Env<'s, 'a> {
    Scope(&'s mut Scope<'a>),
    Bare(&'s mut Response),
}

impl Env<'_, '_> {
    fn response(&mut self) -> &mut Response {
        match self {
            Env::Scope(s) => s.response,
            Env::Bare(r) => r,
        }
    }

    fn eval(&self, e: &Value) -> Result<Value, Error> {
        let Value::Object(o) = e else { return Ok(e.clone()) };
        if o.len() != 1 {
            return Ok(e.clone());
        }
        let (kind, a) = o.iter().next().unwrap_or_else(|| unreachable!());
        let scope = match self {
            Env::Scope(s) => Some(&**s),
            Env::Bare(_) => None,
        };
        Ok(match kind.as_str() {
            "lit" => a.clone(),
            "param" => scope.and_then(|s| s.param(&php_string(a)).cloned()).unwrap_or(Value::Null),
            "inject" => {
                let name = php_string(a);
                let Some(s) = scope else { return Ok(Value::Null) };
                // Only what the hook declared reaches its callback.
                if !s.injections().any(|n| n == name) {
                    return Ok(Value::Null);
                }
                match name.as_str() {
                    "route" => s.route.map(|r| Value::String(r.path().to_owned())).unwrap_or(Value::Null),
                    "params" => {
                        let m: Map<String, Value> =
                            s.path.iter().map(|(k, v)| (k.clone(), Value::String(v.clone()))).collect();
                        if m.is_empty() { json!([]) } else { Value::Object(m) }
                    }
                    "error" => s.error().map(|e| error_json(&e)).unwrap_or(Value::Null),
                    "request" | "response" => Value::Bool(s.injections().any(|n| n == name)),
                    _ => s.resource::<Value>(&name).cloned().unwrap_or(Value::Null),
                }
            }
            "request" => match scope {
                Some(s) => match request_get(
                    s.request,
                    &match a {
                        Value::Array(l) => Value::Array(l.iter().map(|x| self.eval(x)).collect::<Result<_, _>>()?),
                        other => other.clone(),
                    },
                )
                .map_err(|f| fault(f.0))?
                {
                    Outcome::Ok(v) => v,
                    Outcome::Err { class, message } => return Err(Error::custom(class, php_string(&message), 0)),
                },
                None => return Err(fault("no request injected")),
            },
            "response" => match self {
                Env::Scope(s) => response_get(s.response, a).map_err(|f| fault(f.0))?,
                Env::Bare(r) => response_get(r, a).map_err(|f| fault(f.0))?,
            },
            "route" => route_get(scope.and_then(|s| s.route), a).map_err(|f| fault(f.0))?,
            "concat" => {
                let mut out = String::new();
                for x in a.as_array().into_iter().flatten() {
                    out.push_str(&php_string(&self.eval(x)?));
                }
                Value::String(out)
            }
            "json" => Value::String(
                php_std::json::encode(
                    &php_std::Value::<php_std::Never>::from_json(&self.eval(a)?),
                    php_std::json::Flags::NONE,
                    512,
                )
                .unwrap_or_default(),
            ),
            "map" => {
                let mut map = Map::new();
                for pair in a.as_array().into_iter().flatten() {
                    let key = pair.get(0).map(php_string).unwrap_or_default();
                    let value = self.eval(pair.get(1).unwrap_or(&Value::Null))?;
                    map.insert(key, value);
                }
                Value::Object(map)
            }
            _ => e.clone(),
        })
    }
}

fn exception(a: &Value) -> Error {
    let class = a.get("class").and_then(Value::as_str).unwrap_or("Exception").to_owned();
    let message = a.get("message").map(php_string).unwrap_or_default();
    let code = a.get("code").and_then(Value::as_i64).unwrap_or(0);
    match class.as_str() {
        "Utopia\\Http\\Exception" => Error::http(message, code),
        "Exception" | "DomainException" | "LogicException" | "RuntimeException" | "InvalidArgumentException" => {
            Error::custom(class, message, code)
        }
        _ => fault(format!("exception class {class}")),
    }
}

async fn statements(list: &[Value], env: &mut Env<'_, '_>, out: &Out) -> Result<(), Error> {
    for statement in list {
        let (op, a) = statement.as_object().and_then(|o| o.iter().next()).ok_or_else(|| fault("statement"))?;
        let at = |i: usize| a.get(i).cloned().unwrap_or(Value::Null);
        match op.as_str() {
            "emit" => {
                let v = env.eval(a)?;
                emit(out, v);
            }
            "send" => {
                let v = php_string(&env.eval(a)?);
                env.response().send(v.as_bytes());
            }
            "text" => {
                let v = php_string(&env.eval(a)?);
                env.response().text(&v);
            }
            "html" => {
                let v = php_string(&env.eval(a)?);
                env.response().html(&v);
            }
            "json" => {
                let v = env.eval(a)?;
                env.response().json(&v)?;
            }
            "jsonp" => {
                let v = env.eval(&at(1))?;
                env.response().jsonp(&php_string(&at(0)), &v);
            }
            "iframe" => {
                let v = env.eval(&at(1))?;
                env.response().iframe(&php_string(&at(0)), &v);
            }
            "redirect" => {
                let status = a.get(1).and_then(Value::as_i64).unwrap_or(301);
                env.response().redirect(&php_string(&at(0)), status)?;
            }
            "no_content" => env.response().no_content(),
            "abort" => env.response().abort(),
            "chunk" => {
                let v = php_string(&env.eval(&at(0))?);
                let end = at(1).as_bool().unwrap_or(false);
                env.response().chunk(v.as_bytes(), end)?;
            }
            "status" => {
                env.response().set_status_code(a.as_i64().unwrap_or(0))?;
            }
            "content_type" => {
                env.response().set_content_type(&php_string(&at(0)), &php_string(&at(1)));
            }
            "add_header" => {
                let v = php_string(&env.eval(&at(1))?);
                env.response().add_header(&php_string(&at(0)), v);
            }
            "set_header" => {
                let v = php_string(&env.eval(&at(1))?);
                env.response().set_header(&php_string(&at(0)), v);
            }
            "remove_header" => {
                env.response().remove_header(&php_string(a));
            }
            "add_cookie" => {
                let s = |k: &str| a.get(k).filter(|v| !v.is_null()).map(php_string);
                let b = |k: &str| a.get(k).and_then(Value::as_bool);
                env.response().add_cookie(Cookie {
                    name: s("name").unwrap_or_default(),
                    value: s("value"),
                    expire: a.get("expire").and_then(Value::as_i64),
                    path: s("path"),
                    domain: s("domain"),
                    secure: b("secure"),
                    http_only: b("httponly"),
                    same_site: s("samesite"),
                });
            }
            "remove_cookie" => {
                env.response().remove_cookie(&php_string(a));
            }
            "disable_payload" => {
                env.response().disable_payload();
            }
            "enable_payload" => {
                env.response().enable_payload();
            }
            "accept_encoding" => {
                env.response().set_accept_encoding(php_string(a));
            }
            "compression_min_size" => {
                env.response().set_compression_min_size(a.as_u64().unwrap_or(0) as usize);
            }
            "compression_supported" => {
                let list = a.as_array().map(|l| l.iter().map(php_string).collect()).unwrap_or_default();
                env.response().set_compression_supported(list);
            }
            "throw" => return Err(exception(a)),
            "set_resource" => {
                let v = env.eval(&at(1))?;
                let Env::Scope(s) = env else { return Err(fault("no application")) };
                s.http.resources().set(&php_string(&at(0)), Arc::new(v));
            }
            "execute" => {
                let Env::Scope(s) = env else { return Err(fault("no application")) };
                let inner = request(a).map_err(|f| fault(f.0))?;
                let mut response = Response::new();
                s.http.execute(&inner, &mut response, s.context).await?;
                emit(out, bytes_value(&response.wire().body));
            }
            _ => return Err(fault(format!("unknown statement {op}"))),
        }
    }
    Ok(())
}

// ---------------------------------------------------------------------------
// Telemetry
// ---------------------------------------------------------------------------

/// Values by instrument, in the order of their first write.
type Recorded = Arc<Mutex<Vec<(String, Vec<f64>)>>>;

/// `Utopia\Telemetry\Adapter\Test`: values by instrument, instruments in
/// the order of their first write.
#[derive(Default)]
struct TestTelemetry {
    recorded: Recorded,
}

struct TestInstrument {
    name: String,
    recorded: Recorded,
}

impl TestInstrument {
    fn push(&self, value: f64) {
        let mut recorded = self.recorded.lock().unwrap_or_else(|e| e.into_inner());
        match recorded.iter_mut().find(|(n, _)| *n == self.name) {
            Some((_, values)) => values.push(value),
            None => recorded.push((self.name.clone(), vec![value])),
        }
    }
}

impl utopia_http::telemetry::Histogram for TestInstrument {
    fn record(&self, value: f64, _attributes: &utopia_http::telemetry::Attributes) {
        self.push(value);
    }
}

impl utopia_http::telemetry::UpDownCounter for TestInstrument {
    fn add(&self, value: i64, _attributes: &utopia_http::telemetry::Attributes) {
        self.push(value as f64);
    }
}

impl utopia_http::telemetry::Telemetry for TestTelemetry {
    fn histogram(
        &self,
        name: &str,
        _unit: &str,
        _boundaries: Option<&[f64]>,
    ) -> Arc<dyn utopia_http::telemetry::Histogram> {
        Arc::new(TestInstrument { name: name.to_owned(), recorded: self.recorded.clone() })
    }

    fn up_down_counter(&self, name: &str, _unit: &str) -> Arc<dyn utopia_http::telemetry::UpDownCounter> {
        Arc::new(TestInstrument { name: name.to_owned(), recorded: self.recorded.clone() })
    }
}

impl TestTelemetry {
    fn values(&self, name: &str) -> Vec<f64> {
        let recorded = self.recorded.lock().unwrap_or_else(|e| e.into_inner());
        recorded.iter().find(|(n, _)| n == name).map(|(_, v)| v.clone()).unwrap_or_default()
    }

    /// Up-down counters first, then histograms (the Test adapter's arrays).
    fn order(&self) -> Vec<String> {
        let recorded = self.recorded.lock().unwrap_or_else(|e| e.into_inner());
        let names: Vec<String> = recorded.iter().map(|(n, _)| n.clone()).collect();
        let (counters, histograms): (Vec<String>, Vec<String>) =
            names.into_iter().partition(|n| n == "http.server.active_requests");
        counters.into_iter().chain(histograms).collect()
    }
}

// ---------------------------------------------------------------------------
// Validators
// ---------------------------------------------------------------------------

/// `Utopia\Validator\AnyOf` (its description is the last rule tried).
struct AnyOf {
    rules: Vec<Box<dyn Validator>>,
    failed: Mutex<Option<usize>>,
}

impl Validator for AnyOf {
    fn description(&self) -> String {
        let i = self.failed.lock().map(|f| f.unwrap_or(0)).unwrap_or(0);
        self.rules.get(i).map(|r| r.description()).unwrap_or_default()
    }

    fn is_valid(&self, value: &Value) -> bool {
        for (i, rule) in self.rules.iter().enumerate() {
            let valid = rule.is_valid(value);
            if let Ok(mut f) = self.failed.lock() {
                *f = Some(i);
            }
            if valid {
                return true;
            }
        }
        false
    }
}

/// Accepts anything (the E2E object route's validator).
struct AnyValue;

impl Validator for AnyValue {
    fn description(&self) -> String {
        "Value must be anything".into()
    }

    fn is_valid(&self, _value: &Value) -> bool {
        true
    }
}

pub(crate) fn validator(spec: &Value) -> Result<Box<dyn Validator>, Fault> {
    let (kind, a) =
        spec.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new(format!("validator spec: {spec}")))?;
    let int = |i: usize, d: i64| a.get(i).and_then(Value::as_i64).unwrap_or(d);
    let malformed = spec.as_object().is_some_and(|o| o.len() != 1)
        || (matches!(kind.as_str(), "text" | "any_of" | "array_list" | "range") && !a.is_array())
        || (kind == "whitelist" && !a.get(0).is_some_and(Value::is_array))
        || (matches!(kind.as_str(), "integer" | "boolean") && !(a.is_boolean() || a.is_null()));
    if malformed {
        return Err(Fault::new(format!("validator spec: {spec}")));
    }
    Ok(match kind.as_str() {
        "text" => Box::new(utopia_validators::Text::with_min(int(0, 0).max(0) as usize, int(1, 1).max(0) as usize)),
        "integer" => Box::new(utopia_validators::Integer { loose: a.as_bool().unwrap_or(false), ..Default::default() }),
        "boolean" => Box::new(utopia_validators::Boolean { loose: a.as_bool().unwrap_or(false) }),
        "whitelist" => {
            let list: Vec<String> =
                a.get(0).and_then(Value::as_array).map(|l| l.iter().map(php_string).collect()).unwrap_or_default();
            let list: Vec<&str> = list.iter().map(String::as_str).collect();
            if a.get(1).and_then(Value::as_bool).unwrap_or(false) {
                Box::new(utopia_validators::WhiteList::strict(&list))
            } else {
                Box::new(utopia_validators::WhiteList::new(&list))
            }
        }
        "nullable" => Box::new(utopia_validators::Nullable(validator(a)?)),
        "range" => Box::new(utopia_validators::Range::new(int(0, 0), int(1, 0))),
        "array_list" => Box::new(utopia_validators::ArrayList::new(
            validator(a.get(0).unwrap_or(&Value::Null))?,
            int(1, 0).max(0) as usize,
        )),
        "any_of" => Box::new(AnyOf {
            rules: a.as_array().into_iter().flatten().map(validator).collect::<Result<_, _>>()?,
            failed: Mutex::new(None),
        }),
        "any" => Box::new(AnyValue),
        _ => return Err(Fault::new(format!("unknown validator {kind}"))),
    })
}

fn dep_values(deps: &[utopia_http::Resource]) -> Vec<Value> {
    deps.iter().map(|d| d.downcast_ref::<Value>().cloned().unwrap_or(Value::Null)).collect()
}

// ---------------------------------------------------------------------------
// Applications
// ---------------------------------------------------------------------------

fn configure(hook: &mut Hook, spec: &Value, out: &Out) -> Result<(), Fault> {
    let s = Args(spec);
    if let Some(d) = s.opt_str("desc")? {
        hook.desc(d);
    }
    if let Some(groups) = s.opt("groups").and_then(Value::as_array) {
        let g: Vec<String> = groups.iter().map(php_string).collect();
        hook.groups(&g.iter().map(String::as_str).collect::<Vec<_>>());
    }
    if let Some(labels) = s.opt("labels").and_then(Value::as_object) {
        for (k, v) in labels {
            hook.label(k, v.clone());
        }
    }
    for p in s.opt("params").and_then(Value::as_array).into_iter().flatten() {
        let pa = Args(p);
        let default = p.get("default").cloned().unwrap_or(Value::Null);
        let default = if pa.opt_bool("default_fn")?.unwrap_or(false) {
            let out = out.clone();
            ParamDefault::Factory(Arc::new(move |deps| {
                for d in dep_values(deps) {
                    emit(&out, d);
                }
                default.clone()
            }))
        } else {
            ParamDefault::Value(default)
        };
        let vspec = p.get("validator").cloned().unwrap_or_else(|| json!({"any": true}));
        let check = match vspec.get("factory") {
            Some(inner) => {
                validator(inner)?;
                let inner = inner.clone();
                let out = out.clone();
                Check::Factory(Arc::new(move |deps| {
                    for d in dep_values(deps) {
                        emit(&out, d);
                    }
                    Arc::from(validator(&inner).unwrap_or_else(|_| Box::new(AnyValue)))
                }))
            }
            None => Check::Validator(Arc::from(validator(&vspec)?)),
        };
        let strings = |k: &str| -> Vec<String> {
            p.get(k).and_then(Value::as_array).map(|l| l.iter().map(php_string).collect()).unwrap_or_default()
        };
        let mut param = utopia_http::Param::new(default, check, pa.opt_str("description")?.unwrap_or(""))
            .optional(pa.opt_bool("optional")?.unwrap_or(false))
            .skip_validation(pa.opt_bool("skip_validation")?.unwrap_or(false));
        param.injections = strings("injections");
        param.aliases = strings("aliases");
        hook.param(pa.str("key")?, param);
    }
    for name in s.opt("inject").and_then(Value::as_array).into_iter().flatten() {
        hook.inject(&php_string(name));
    }
    hook.set_action(statement_action(s.opt("action").and_then(Value::as_array).cloned().unwrap_or_default(), out));
    Ok(())
}

/// A callback running action statements (as Harness.php's hook callbacks).
/// It borrows the statements it captured, so it builds the [`Action`]
/// itself instead of going through `utopia_http::action`.
///
/// [`Action`]: utopia_http::Action
pub(crate) fn statement_action(list: Vec<Value>, out: &Out) -> utopia_http::Action {
    let list = Arc::new(list);
    let out = out.clone();
    Arc::new(move |mut scope| {
        let list = list.clone();
        let out = out.clone();
        Box::pin(async move { statements(&list, &mut Env::Scope(&mut scope), &out).await })
    })
}

/// Dispatches one request of `http.run`'s `requests` through `run()` (or
/// `execute()`): what the server was asked to send, the values actions
/// emitted and the error run() raised.
pub(crate) async fn dispatch(http: &Http, spec: &Value, out: &Out) -> Result<Value, Fault> {
    take(out);
    let request = request(spec)?;
    let mut response = Response::new();
    let context = http.context();
    let result = if spec.get("via").and_then(Value::as_str) == Some("execute") {
        http.execute(&request, &mut response, &context).await
    } else {
        http.run(&request, &mut response, &context).await
    };
    let error = match result {
        Ok(()) => Value::Null,
        Err(e) => {
            if let Some(f) = is_fault(&e) {
                return Err(f);
            }
            error_json(&e)
        }
    };
    let mut result = json!({"wire": wire_json(response.wire()), "out": take(out), "error": error});
    if spec.get("match").is_some() {
        result["match"] = http.find(&request).map(|m| Value::String(m.route.path().to_owned())).unwrap_or(Value::Null);
    }
    if spec.get("size").is_some() {
        result["size"] = Value::from(response.size());
    }
    Ok(result)
}

fn build(app: &Value, out: &Out) -> Result<(Http, Vec<Value>), Fault> {
    let a = Args(app);
    let mut http = Http::new(a.opt_str("timezone")?.unwrap_or("UTC"));
    let mut setup = Vec::new();
    if let Some(mode) = a.opt_str("mode")? {
        http.set_mode(mode);
    }
    http.set_allow_override(a.opt_bool("allow_override")?.unwrap_or(false));
    if let Some(c) = a.opt("compression") {
        http.set_compression(Some(Compression {
            enabled: true,
            min_size: c
                .get("min_size")
                .and_then(Value::as_u64)
                .map(|n| n as usize)
                .unwrap_or(utopia_http::COMPRESSION_MIN_SIZE_DEFAULT),
            supported: c
                .get("supported")
                .and_then(Value::as_array)
                .map(|l| l.iter().map(php_string).collect())
                .unwrap_or_default(),
        }));
    }
    for pair in a.opt("write").and_then(Value::as_array).into_iter().flatten() {
        write_file(&pair.get(0).map(php_string).unwrap_or_default(), &pair.get(1).map(php_string).unwrap_or_default())?;
    }
    for (name, value) in a.opt("resources").and_then(Value::as_object).into_iter().flatten() {
        http.resources().set(name, Arc::new(value.clone()));
    }
    if let Some(files) = a.opt("files").and_then(Value::as_array) {
        let dir = files.first().map(php_string).unwrap_or_default();
        let root = files.get(1).filter(|v| !v.is_null()).map(php_string);
        if let Err(e) = http.load_files(&dir, root.as_deref()) {
            setup.push(error_json(&e));
        }
    }
    for kind in ["init", "shutdown", "options", "error", "start", "request"] {
        for spec in a.opt(kind).and_then(Value::as_array).into_iter().flatten() {
            let hook = match kind {
                "init" => http.init(),
                "shutdown" => http.shutdown(),
                "options" => http.options(),
                "error" => http.error(),
                "start" => http.on_start(),
                _ => http.on_request(),
            };
            configure(hook, spec, out)?;
        }
    }
    for spec in a.opt("routes").and_then(Value::as_array).into_iter().flatten() {
        let s = Args(spec);
        let path = s.str("path")?;
        let methods: Vec<String> = spec
            .get("methods")
            .and_then(Value::as_array)
            .map(|l| l.iter().map(php_string).collect())
            .unwrap_or_else(|| vec!["GET".into()]);
        let created = match s.opt_str("method")? {
            Some(m) => http.add_route(m, path),
            None => http.routes(&methods.iter().map(String::as_str).collect::<Vec<_>>(), path),
        };
        let mut builder = match created {
            Ok(b) => b,
            Err(e) => {
                setup.push(error_json(&e));
                continue;
            }
        };
        let mut failed = None;
        for alias in spec.get("aliases").and_then(Value::as_array).into_iter().flatten() {
            match builder.alias(&php_string(alias)) {
                Ok(_) => {}
                Err(e) => {
                    failed = Some(e);
                    break;
                }
            }
        }
        if let Some(e) = failed {
            setup.push(error_json(&e));
            continue;
        }
        let route = builder.get();
        if let Some(h) = s.opt_bool("hook")? {
            route.set_hooks(h);
        }
        configure(route, spec, out)?;
        setup.push(Value::Bool(true));
    }
    if let Some(spec) = a.opt("wildcard") {
        let mut builder = http.wildcard();
        let route = builder.get();
        if let Some(h) = spec.get("hook").and_then(Value::as_bool) {
            route.set_hooks(h);
        }
        configure(route, spec, out)?;
    }
    Ok((http, setup))
}

async fn run(a: &Args<'_>) -> Result<Value, Fault> {
    let out: Out = Arc::new(Mutex::new(Vec::new()));
    let empty = json!({});
    let (mut http, setup) = build(a.opt("app").unwrap_or(&empty), &out)?;
    let telemetry = Arc::new(TestTelemetry::default());
    if a.opt_bool("telemetry")?.unwrap_or(false) {
        http.set_telemetry(&*telemetry);
    }
    let http = http;
    let mut responses = Vec::new();
    for spec in a.opt("requests").and_then(Value::as_array).into_iter().flatten() {
        responses.push(dispatch(&http, spec, &out).await?);
    }
    let mut result = json!({"setup": setup, "responses": responses});
    if a.opt_bool("telemetry")?.unwrap_or(false) {
        let values = |name: &str| telemetry.values(name);
        result["telemetry"] = json!({
            "instruments": telemetry.order(),
            "active": values("http.server.active_requests").iter().map(|v| *v as i64).collect::<Vec<_>>(),
            "durations": values("http.server.request.duration").len(),
            "request_sizes": values("http.server.request.body.size").iter().map(|v| *v as i64).collect::<Vec<_>>(),
            "response_sizes": values("http.server.response.body.size").len(),
        });
    }
    Ok(result)
}

async fn start(a: &Args<'_>) -> Result<Value, Fault> {
    let out: Out = Arc::new(Mutex::new(Vec::new()));
    let empty = json!({});
    let (http, setup) = build(a.opt("app").unwrap_or(&empty), &out)?;
    let error = match http.start(Arc::new(Value::Null)).await {
        Ok(()) => Value::Null,
        Err(e) => {
            if let Some(f) = is_fault(&e) {
                return Err(f);
            }
            error_json(&e)
        }
    };
    Ok(json!({"setup": setup, "out": take(&out), "error": error}))
}

async fn respond(a: &Args<'_>) -> Result<Value, Fault> {
    let out: Out = Arc::new(Mutex::new(Vec::new()));
    let mut response = Response::new();
    let mut error = Value::Null;
    for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
        let result = match step.get("get") {
            Some(g) => match response_get(&response, g) {
                Ok(v) => {
                    emit(&out, v);
                    Ok(())
                }
                Err(f) => return Err(f),
            },
            None => statements(std::slice::from_ref(step), &mut Env::Bare(&mut response), &out).await,
        };
        if let Err(e) = result {
            if let Some(f) = is_fault(&e) {
                return Err(f);
            }
            error = error_json(&e);
            break;
        }
    }
    Ok(json!({"wire": wire_json(response.wire()), "out": take(&out), "error": error}))
}

// ---------------------------------------------------------------------------
// Router, routes, views, files, modes
// ---------------------------------------------------------------------------

fn router(a: &Args<'_>) -> Result<Value, Fault> {
    let mut http = Http::new("UTC");
    http.set_allow_override(a.opt_bool("allow_override")?.unwrap_or(false));
    // Route index (PHP's position in the list of created routes) by router id.
    let mut index_of: std::collections::HashMap<usize, usize> = std::collections::HashMap::new();
    let mut created = 0usize;
    let mut setup = Vec::new();
    for spec in a.opt("routes").and_then(Value::as_array).into_iter().flatten() {
        let s = Args(spec);
        let path = s.str("path")?;
        let methods: Vec<String> = spec
            .get("methods")
            .and_then(Value::as_array)
            .map(|l| l.iter().map(php_string).collect())
            .unwrap_or_else(|| vec!["GET".into()]);
        let refs: Vec<&str> = methods.iter().map(String::as_str).collect();
        let via_routes = s.opt_str("via")? == Some("routes");
        if !via_routes {
            // `new Route()` exists (and is listed) before Router::addRoute runs.
            setup.push(Value::from(created));
            created += 1;
        }
        let result = if via_routes { http.routes(&refs, path) } else { http.insert(&refs, path) };
        let mut builder = match result {
            Ok(b) => b,
            Err(e) => {
                setup.push(error_json(&e));
                continue;
            }
        };
        if via_routes {
            setup.push(Value::from(created));
            created += 1;
        }
        index_of.insert(builder.id(), created - 1);
        for alias in spec.get("aliases").and_then(Value::as_array).into_iter().flatten() {
            match builder.alias(&php_string(alias)) {
                Ok(_) => {}
                Err(e) => {
                    setup.push(error_json(&e));
                    break;
                }
            }
        }
    }
    if a.opt_bool("wildcard")?.unwrap_or(false) {
        let id = http.wildcard().id();
        index_of.insert(id, created);
    }
    let mut pairs: Vec<(String, String)> = Vec::new();
    for pair in a.opt("match").and_then(Value::as_array).into_iter().flatten() {
        pairs.push((pair.get(0).map(php_string).unwrap_or_default(), pair.get(1).map(php_string).unwrap_or_default()));
    }
    for m in a.opt("methods").and_then(Value::as_array).into_iter().flatten() {
        for p in a.opt("paths").and_then(Value::as_array).into_iter().flatten() {
            pairs.push((php_string(m), php_string(p)));
        }
    }
    let matches: Vec<Value> = pairs
        .iter()
        .map(|(method, path)| match http.router().find(method, path) {
            None => Value::Null,
            Some(m) => {
                let params: Map<String, Value> =
                    m.params.iter().map(|(k, v)| (k.clone(), Value::String(v.clone()))).collect();
                json!({
                    "route": index_of.get(&m.id).copied(),
                    "params": if params.is_empty() { json!([]) } else { Value::Object(params) },
                })
            }
        })
        .collect();
    let mut prepared = Vec::new();
    let mut probe: Router<()> = Router::new();
    for p in a.opt("prepare").and_then(Value::as_array).into_iter().flatten() {
        let (template, params) = probe.prepare(&php_string(p));
        let params: Map<String, Value> = params.into_iter().map(|(k, v)| (k, Value::from(v))).collect();
        prepared.push(json!([template, if params.is_empty() { json!([]) } else { Value::Object(params) }]));
    }
    let mut table = Map::new();
    for method in utopia_http::METHODS {
        let templates: Vec<Value> = http
            .router()
            .routes(method)
            .map(|r| r.keys().map(|k| Value::String(k.clone())).collect())
            .unwrap_or_default();
        table.insert(method.to_owned(), Value::Array(templates));
    }
    Ok(json!({
        "setup": setup,
        "matches": matches,
        "prepared": prepared,
        "routes": table,
        "allow_override": http.allow_override(),
    }))
}

fn route_inspect(a: &Args<'_>) -> Result<Value, Fault> {
    let methods: Vec<String> = match a.opt("methods") {
        Some(Value::Array(l)) => l.iter().map(php_string).collect(),
        Some(v) => vec![php_string(v)],
        None => vec!["GET".into()],
    };
    let mut route =
        Route::new(&methods.iter().map(String::as_str).collect::<Vec<_>>(), a.opt_str("path")?.unwrap_or("/"), 1);
    let mut out = Vec::new();
    for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
        let (op, v) = step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("step"))?;
        match op.as_str() {
            "get" => out.push(route_get(Some(&route), v)?),
            "desc" => {
                route.desc(&php_string(v));
            }
            "groups" => {
                let g: Vec<String> = v.as_array().map(|l| l.iter().map(php_string).collect()).unwrap_or_default();
                route.groups(&g.iter().map(String::as_str).collect::<Vec<_>>());
            }
            "label" => {
                route.label(&v.get(0).map(php_string).unwrap_or_default(), v.get(1).cloned().unwrap_or(Value::Null));
            }
            "path" => {
                route.set_path(&php_string(v));
            }
            "hook" => {
                route.set_hooks(v.as_bool().unwrap_or(true));
            }
            "param" => {
                let check = Check::Validator(Arc::from(validator(v.get(2).unwrap_or(&json!({"text": [10]})))?));
                let default = v.get(1).cloned().unwrap_or_else(|| Value::String(String::new()));
                route.param(&v.get(0).map(php_string).unwrap_or_default(), utopia_http::Param::new(default, check, ""));
            }
            "inject" => {
                route.inject(&php_string(v));
            }
            _ => return Err(Fault::new(format!("unknown route step {op}"))),
        }
    }
    Ok(Value::Array(out))
}

fn view_error(e: &Error) -> Value {
    json!({"$error": error_json(e)})
}

fn view(a: &Args<'_>) -> Result<Value, Fault> {
    let mut views: Vec<(String, View)> = Vec::new();
    let mut out = Vec::new();
    let find = |views: &[(String, View)], name: &str| views.iter().position(|(n, _)| n == name);
    for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
        let (op, v) = step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("step"))?;
        let name = v.get(0).map(php_string).unwrap_or_default();
        if op == "new" {
            views.push((name, View::new(&v.get(1).map(php_string).unwrap_or_default())));
            continue;
        }
        if op == "write" {
            write_file(&name, &v.get(1).map(php_string).unwrap_or_default())?;
            continue;
        }
        let i = find(&views, &name).ok_or_else(|| Fault::new(format!("no view {name}")))?;
        let result: Option<Value> = match op.as_str() {
            "set_param" => match views[i].1.set_param(
                &v.get(1).map(php_string).unwrap_or_default(),
                v.get(2).cloned().unwrap_or(Value::Null),
                v.get(3).and_then(Value::as_bool).unwrap_or(true),
            ) {
                Ok(_) => None,
                Err(e) => Some(view_error(&e)),
            },
            "get_param" => Some(
                views[i]
                    .1
                    .param(&v.get(1).map(php_string).unwrap_or_default())
                    .unwrap_or_else(|| v.get(2).cloned().unwrap_or(Value::Null)),
            ),
            "set_path" => {
                views[i].1.set_path(&v.get(1).map(php_string).unwrap_or_default());
                None
            }
            "set_rendered" => {
                views[i].1.set_rendered(v.get(1).and_then(Value::as_bool).unwrap_or(true));
                None
            }
            "is_rendered" => Some(Value::Bool(views[i].1.is_rendered())),
            "print" => {
                let filters: Vec<String> = match v.get(2) {
                    Some(Value::Array(l)) => l.iter().map(php_string).collect(),
                    Some(Value::String(s)) if !s.is_empty() && s != "0" => vec![s.clone()],
                    _ => Vec::new(),
                };
                match views[i].1.print(
                    &v.get(1).map(php_string).unwrap_or_default(),
                    &filters.iter().map(String::as_str).collect::<Vec<_>>(),
                ) {
                    Ok(s) => Some(Value::String(s)),
                    Err(e) => Some(view_error(&e)),
                }
            }
            "render" => match views[i].1.render(v.get(1).and_then(Value::as_bool).unwrap_or(true)) {
                Ok(s) => Some(Value::String(s)),
                Err(e) => Some(view_error(&e)),
            },
            "exec" => {
                let names: Vec<String> =
                    v.get(1).and_then(Value::as_array).map(|l| l.iter().map(php_string).collect()).unwrap_or_default();
                let mut children: Vec<View> =
                    names.iter().filter_map(|n| find(&views, n).map(|j| views[j].1.clone())).collect();
                let result = views[i].1.exec(&mut children);
                for (n, child) in names.iter().zip(children) {
                    if let Some(j) = find(&views, n) {
                        views[j].1 = child;
                    }
                }
                match result {
                    Ok(s) => Some(Value::String(s)),
                    Err(e) => Some(view_error(&e)),
                }
            }
            "set_parent" => {
                let parent = v.get(1).map(php_string).unwrap_or_default();
                let j = find(&views, &parent).ok_or_else(|| Fault::new("no parent"))?;
                let p = views[j].1.clone();
                views[i].1.set_parent(p);
                None
            }
            "parent" => Some(match views[i].1.parent() {
                None => Value::Null,
                Some(p) => views
                    .iter()
                    .find(|(_, v)| v.path() == p.path())
                    .map(|(n, _)| Value::String(n.clone()))
                    .unwrap_or(Value::Bool(false)),
            }),
            _ => return Err(Fault::new(format!("unknown view step {op}"))),
        };
        if let Some(r) = result {
            out.push(r);
        }
    }
    Ok(Value::Array(out))
}

/// Writes a fixture file (creating its directory).
fn write_file(path: &str, content: &str) -> Result<(), Fault> {
    if let Some(dir) = std::path::Path::new(path).parent() {
        std::fs::create_dir_all(dir).map_err(|e| Fault::new(e.to_string()))?;
    }
    std::fs::write(path, content).map_err(|e| Fault::new(e.to_string()))
}

fn files(a: &Args<'_>) -> Result<Value, Fault> {
    let mut files = Files::new();
    let mut out = Vec::new();
    for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
        let (op, v) = step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("step"))?;
        let s = php_string(v);
        let result = match op.as_str() {
            "write" => {
                write_file(
                    &v.get(0).map(php_string).unwrap_or_default(),
                    &v.get(1).map(php_string).unwrap_or_default(),
                )?;
                continue;
            }
            "load" => {
                let root = v.get(1).filter(|r| !r.is_null()).map(php_string);
                match files.load(&v.get(0).map(php_string).unwrap_or_default(), root.as_deref()) {
                    Ok(()) => Value::Null,
                    Err(e) => view_error(&e),
                }
            }
            "count" => Value::from(files.count()),
            "is_loaded" => Value::Bool(files.is_loaded(&s)),
            "contents" => match files.get(&s) {
                Ok(f) => bytes_value(&f.contents),
                Err(e) => view_error(&e),
            },
            "mime" => match files.get(&s) {
                Ok(f) => Value::String(f.mime.clone()),
                Err(e) => view_error(&e),
            },
            "add_mime_type" => {
                files.add_mime_type(&s);
                Value::Null
            }
            "remove_mime_type" => {
                files.remove_mime_type(&s);
                Value::Null
            }
            "mime_types" => {
                let m: Map<String, Value> =
                    files.mime_types().iter().map(|(k, v)| (k.clone(), Value::Bool(*v))).collect();
                if m.is_empty() { json!([]) } else { Value::Object(m) }
            }
            "reset" => {
                files.reset();
                Value::Null
            }
            _ => return Err(Fault::new(format!("unknown files step {op}"))),
        };
        out.push(result);
    }
    Ok(Value::Array(out))
}

fn settings(mode: &str) -> Value {
    let s =
        if mode == "HYPERLOOP_A" { utopia_http::Mode::HyperloopA } else { utopia_http::Mode::HyperloopB }.settings();
    json!({
        "compression": s.compression,
        "tcp_nodelay": s.tcp_nodelay,
        "tcp_fastopen": s.tcp_fastopen,
        "tcp_defer_accept": s.tcp_defer_accept,
        "reuse_port": s.reuse_port,
        "max_wait_time": s.max_wait_time,
        "max_concurrency": s.max_concurrency,
        "reload_async": s.reload_async,
        "coroutine": s.coroutine,
        "dispatch_mode": s.dispatch_mode,
        "hook_all": s.hook_all,
        "send_yield": s.send_yield,
        "max_request": s.max_request,
        "workers_at_least_reactors": s.workers >= s.reactors,
        "workers_positive": s.workers >= 1,
        "reactors_positive": s.reactors >= 1,
        "aio": s.aio_workers.zip(s.aio_core_workers).map(|(w, c)| w >= c),
    })
}
