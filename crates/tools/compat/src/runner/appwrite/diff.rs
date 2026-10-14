//! `compat appwrite diff`: the same requests against the PHP and the Rust
//! API, compared on everything a client or a worker observes (see
//! `rfc/rust-lifecycle.md`, section 4).
//!
//! Each runtime gets a twin project, created and seeded the same way through
//! the PHP API (the control plane is PHP's for both). Every scenario sends
//! one request to the PHP API (`:9501`) on its project, then the same request
//! to the Rust API (`:9530`) on its own, and records for each:
//!
//! - the response: status, headers and body;
//! - the change to the project's tables (rows added and removed);
//! - what the runtime's container wrote to Redis while the request ran:
//!   queue messages (`LPUSH`/`RPUSH` on `utopia-queue.*`), publications and
//!   cache purges, read from `MONITOR` and kept only for the commands that
//!   came from that container (the workers' traffic is not the request's).
//!
//! Records are normalized before they are compared: the twins' project IDs,
//! table prefixes and secrets become placeholders, and generated values
//! (IDs, datetimes) are numbered in order of appearance, so the same
//! behaviour gives the same record and an ID generated in one runtime and
//! not the other is still a difference.
//!
//! The scenarios are the hook matrices of the RFC for the routes Rust
//! serves: each route under every identity (guest, keys with and without
//! the scope, an unknown key, another project's key, a user session, a
//! JWT, no project, an unknown project) and origin (none, an allowed
//! platform, a foreign one).

use std::collections::{BTreeMap, HashMap};
use std::path::Path;
use std::time::Duration;

use serde_json::{Map, Value, json};
use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use tokio::net::TcpStream;
use tokio::sync::mpsc;
use utopia_client::{Anywhere, Client, Http, Request};

/// Where the runtimes and the stack's services are, from the environment.
struct Stack {
    php: String,
    rust: String,
    redis: String,
    postgres: String,
    admin_email: String,
    admin_password: String,
}

impl Stack {
    fn from_env() -> Result<Stack, String> {
        let var = |name: &str, default: &str| std::env::var(name).unwrap_or_else(|_| default.to_owned());
        let (user, password, db) = postgres_credentials()?;
        Ok(Stack {
            php: var("COMPAT_DIFF_PHP", "http://localhost:9501/v1"),
            rust: var("COMPAT_DIFF_RUST", "http://localhost:9530/v1"),
            redis: var("COMPAT_DIFF_REDIS", "localhost:9513"),
            postgres: var(
                "COMPAT_DIFF_POSTGRES",
                &format!("host=localhost port=9512 user={user} password={password} dbname={db}"),
            ),
            // An owner of the instance's organization (self-hosted allows one);
            // on a fresh stack the account and the organization are created.
            admin_email: var("APPWRITE_ADMIN_EMAIL", "compat-diff@example.com"),
            admin_password: var("APPWRITE_ADMIN_PASSWORD", "benchmark-password"),
        })
    }
}

/// The dev stack's PostgreSQL credentials, from its container.
fn postgres_credentials() -> Result<(String, String, String), String> {
    let out = std::process::Command::new("docker")
        .args(["inspect", "-f", "{{range .Config.Env}}{{println .}}{{end}}", "appwrite-postgresql"])
        .output()
        .map_err(|e| format!("docker: {e}"))?;
    let env = String::from_utf8_lossy(&out.stdout);
    let get = |name: &str| {
        env.lines().find_map(|l| l.strip_prefix(&format!("{name}="))).map(str::to_owned).unwrap_or_default()
    };
    Ok((get("POSTGRES_USER"), get("POSTGRES_PASSWORD"), get("POSTGRES_DB")))
}

/// The addresses a container has on its networks.
fn container_ips(name: &str) -> Vec<String> {
    std::process::Command::new("docker")
        .args(["inspect", "-f", "{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}", name])
        .output()
        .map(|o| String::from_utf8_lossy(&o.stdout).split_whitespace().map(str::to_owned).collect())
        .unwrap_or_default()
}

/// A response, as recorded.
struct Reply {
    status: u16,
    headers: Vec<(String, String)>,
    body: Value,
}

struct Api {
    client: Client<Http>,
}

impl Api {
    fn new() -> Api {
        Api { client: Client::new(Http::new()).with_destinations(Anywhere).with_encoding_negotiation(false) }
    }

    async fn send(
        &self,
        method: &str,
        url: &str,
        headers: &[(String, String)],
        body: Option<&Value>,
    ) -> Result<Reply, String> {
        let mut request = Request::new(method, url).map_err(|e| e.to_string())?;
        for (name, value) in headers {
            request = request.added_header(name, value).map_err(|e| e.to_string())?;
        }
        if let Some(body) = body {
            request =
                request.header("content-type", "application/json").map_err(|e| e.to_string())?.body(body.to_string());
        }
        let response = self.client.send(request).await.map_err(|e| format!("{method} {url}: {e}"))?;
        let mut headers: Vec<(String, String)> = response
            .headers()
            .iter()
            .flat_map(|(name, values)| values.iter().map(move |v| (name.to_ascii_lowercase(), v.clone())))
            .collect();
        headers.sort();
        let text = response.text().into_owned();
        let body = serde_json::from_str(&text).unwrap_or(Value::String(text));
        Ok(Reply { status: response.status(), headers, body })
    }
}

/// A twin project and what its scenarios authenticate with.
struct Twin {
    runtime: &'static str,
    endpoint: String,
    ips: Vec<String>,
    project: String,
    /// The table prefix, `_<sequence>_`.
    prefix: String,
    key: String,
    narrow_key: String,
    seed_key: String,
    session: String,
    jwt: String,
}

/// The console session and organization the twins are created in.
struct Console {
    headers: Vec<(String, String)>,
    team: String,
}

async fn console(api: &Api, stack: &Stack) -> Result<Console, String> {
    let base = vec![("x-appwrite-project".to_owned(), "console".to_owned())];
    // 201 on a fresh stack, 409 when the account exists.
    api.send(
        "POST",
        &format!("{}/account", stack.php),
        &base,
        Some(&json!({"userId": "unique()", "email": stack.admin_email, "password": stack.admin_password, "name": "compat"})),
    )
    .await?;
    let session = api
        .send(
            "POST",
            &format!("{}/account/sessions/email", stack.php),
            &base,
            Some(&json!({"email": stack.admin_email, "password": stack.admin_password})),
        )
        .await?;
    if session.status != 201 {
        return Err(format!("console sign-in: {} {}", session.status, session.body));
    }
    let cookie = session
        .headers
        .iter()
        .filter(|(n, _)| n == "set-cookie")
        .filter_map(|(_, v)| v.split(';').next())
        .collect::<Vec<_>>()
        .join("; ");
    let mut headers = base;
    headers.push(("cookie".to_owned(), cookie));
    let teams = api.send("GET", &format!("{}/teams", stack.php), &headers, None).await?;
    let team = match teams.body["teams"][0]["$id"].as_str() {
        Some(id) => id.to_owned(),
        None => {
            let created = api
                .send(
                    "POST",
                    &format!("{}/teams", stack.php),
                    &headers,
                    Some(&json!({"teamId": "unique()", "name": "compat"})),
                )
                .await?;
            created.body["$id"].as_str().ok_or(format!("organization: {} {}", created.status, created.body))?.to_owned()
        }
    };
    Ok(Console { headers, team })
}

/// The scopes of the key scenarios use, and of the one that seeds.
const SCOPES: &[&str] =
    &["users.read", "users.write", "sessions.read", "sessions.write", "targets.read", "targets.write"];

async fn twin(
    api: &Api,
    stack: &Stack,
    console: &Console,
    db: &tokio_postgres::Client,
    runtime: &'static str,
    run: &str,
) -> Result<Twin, String> {
    let php = &stack.php;
    let project = format!("diff{run}{}", &runtime[..1]);
    let created = api
        .send(
            "POST",
            &format!("{php}/projects"),
            &console.headers,
            Some(&json!({"projectId": project, "name": format!("diff {run}"), "teamId": console.team, "region": "default"})),
        )
        .await?;
    if created.status != 201 {
        return Err(format!("project: {} {}", created.status, created.body));
    }
    let key = |id: &str, scopes: &[&str]| {
        // Key IDs are unique across the instance.
        let body = json!({"keyId": format!("{project}-{id}"), "name": id, "scopes": scopes});
        let url = format!("{php}/projects/{project}/keys");
        let headers = console.headers.clone();
        async move {
            let r = api.send("POST", &url, &headers, Some(&body)).await?;
            r.body["secret"].as_str().map(str::to_owned).ok_or(format!("key: {} {}", r.status, r.body))
        }
    };
    let mut seeding: Vec<&str> = SCOPES.to_vec();
    seeding.push("platforms.write");
    let seed = key("seed", &seeding).await?;
    let full = key("full", SCOPES).await?;
    let narrow = key("narrow", &["teams.read"]).await?;
    let server = vec![("x-appwrite-project".to_owned(), project.clone()), ("x-appwrite-key".to_owned(), seed)];
    let post = |path: &str, body: Value| {
        let url = format!("{php}{path}");
        let headers = server.clone();
        async move {
            let r = api.send("POST", &url, &headers, Some(&body)).await?;
            if r.status >= 300 {
                return Err(format!("{url}: {} {}", r.status, r.body));
            }
            Ok(r.body)
        }
    };
    post(
        "/project/platforms/web",
        json!({"platformId": format!("{project}-web"), "name": "Web", "hostname": "localhost"}),
    )
    .await?;
    for (id, name) in [("u1", "Ada"), ("u2", "Grace")] {
        post(
            "/users",
            json!({"userId": id, "email": format!("{id}@example.com"), "password": "password-1234", "name": name}),
        )
        .await?;
    }
    let session = post("/users/u1/sessions", json!({})).await?["secret"].as_str().unwrap_or_default().to_owned();
    let jwt = post("/users/u1/jwts", json!({})).await?["jwt"].as_str().unwrap_or_default().to_owned();
    let sequence = db
        .query_one("SELECT _id::text FROM appwrite._console_projects WHERE _uid = $1", &[&project])
        .await
        .map_err(|e| format!("project sequence: {e}"))?
        .get::<_, String>(0);
    let (endpoint, container) =
        if runtime == "php" { (stack.php.clone(), "appwrite") } else { (stack.rust.clone(), "appwrite-rust") };
    Ok(Twin {
        runtime,
        endpoint,
        ips: container_ips(container),
        project,
        prefix: format!("_{sequence}_"),
        key: full,
        narrow_key: narrow,
        seed_key: server[1].1.clone(),
        session,
        jwt,
    })
}

#[derive(Clone, Copy, Debug)]
enum Identity {
    Guest,
    Key,
    NarrowKey,
    UnknownKey,
    OtherProjectKey,
    Session,
    Jwt,
    InvalidJwt,
    NoProject,
    UnknownProject,
}

const IDENTITIES: [Identity; 10] = [
    Identity::Guest,
    Identity::Key,
    Identity::NarrowKey,
    Identity::UnknownKey,
    Identity::OtherProjectKey,
    Identity::Session,
    Identity::Jwt,
    Identity::InvalidJwt,
    Identity::NoProject,
    Identity::UnknownProject,
];

#[derive(Clone, Copy, Debug)]
enum Origin {
    None,
    Platform,
    Foreign,
}

const ORIGINS: [Origin; 3] = [Origin::None, Origin::Platform, Origin::Foreign];

/// A route under test: its request, with `{n}` replaced by the scenario's
/// number (to create distinct resources).
struct Route {
    name: &'static str,
    method: &'static str,
    path: &'static str,
    body: Option<&'static str>,
}

const ROUTES: &[Route] = &[
    Route { name: "users.list", method: "GET", path: "/users", body: None },
    Route { name: "users.list.invalid", method: "GET", path: "/users?queries[]=bogus", body: None },
    Route { name: "users.get", method: "GET", path: "/users/u1", body: None },
    Route { name: "users.get.missing", method: "GET", path: "/users/missing", body: None },
    Route { name: "users.prefs.get", method: "GET", path: "/users/u1/prefs", body: None },
    Route { name: "users.name.update", method: "PATCH", path: "/users/u2/name", body: Some(r#"{"name":"Name {n}"}"#) },
    Route {
        name: "users.create",
        method: "POST",
        path: "/users",
        body: Some(r#"{"userId":"c{n}","email":"c{n}@example.com","password":"password-1234","name":"Created {n}"}"#),
    },
    Route { name: "users.delete.missing", method: "DELETE", path: "/users/missing", body: None },
];

/// What else a request carries, beyond its identity and origin: the hooks
/// those do not reach (preflight, response formats, locale).
#[derive(Clone, Copy, Debug)]
enum Variant {
    Plain,
    /// The browser's CORS preflight of the request (`OPTIONS`).
    Preflight,
    /// `X-Appwrite-Response-Format` of an older version (response filters).
    OldFormat,
    /// `X-Appwrite-Response-Format` of a newer version (the warning header).
    NewFormat,
    /// `X-Appwrite-Locale` (translated error messages).
    Locale,
}

const VARIANTS: [Variant; 4] = [Variant::Preflight, Variant::OldFormat, Variant::NewFormat, Variant::Locale];

struct Scenario {
    id: String,
    n: usize,
    route: &'static Route,
    identity: Identity,
    origin: Origin,
    variant: Variant,
}

fn scenarios(filter: Option<&str>) -> Vec<Scenario> {
    let mut out = Vec::new();
    for route in ROUTES {
        for identity in IDENTITIES {
            for origin in ORIGINS {
                let n = out.len();
                let id = format!("{} {identity:?} origin:{origin:?}", route.name).to_ascii_lowercase();
                if filter.is_some_and(|f| !id.contains(&f.to_ascii_lowercase())) {
                    continue;
                }
                out.push(Scenario { id, n, route, identity, origin, variant: Variant::Plain });
            }
        }
        // Each variant with a key from an allowed platform, and for the
        // locale as a guest too (its error messages are translated).
        for variant in VARIANTS {
            let identities: &[Identity] =
                if matches!(variant, Variant::Locale) { &[Identity::Key, Identity::Guest] } else { &[Identity::Key] };
            for &identity in identities {
                let n = out.len();
                let id =
                    format!("{} {identity:?} origin:platform variant:{variant:?}", route.name).to_ascii_lowercase();
                if filter.is_some_and(|f| !id.contains(&f.to_ascii_lowercase())) {
                    continue;
                }
                out.push(Scenario { id, n, route, identity, origin: Origin::Platform, variant });
            }
        }
    }
    out
}

/// The request a scenario sends to `twin` (`other` is the other twin).
fn request(scenario: &Scenario, twin: &Twin, other: &Twin) -> (String, String, Vec<(String, String)>, Option<Value>) {
    let route = scenario.route;
    let n = scenario.n.to_string();
    let path = route.path.replace("{n}", &n);
    let body = route.body.map(|b| serde_json::from_str(&b.replace("{n}", &n)).unwrap_or(Value::Null));
    let mut headers: Vec<(String, String)> = Vec::new();
    let project = |h: &mut Vec<(String, String)>, id: &str| h.push(("x-appwrite-project".into(), id.into()));
    match scenario.identity {
        Identity::Guest => project(&mut headers, &twin.project),
        Identity::Key => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-key".into(), twin.key.clone()));
        }
        Identity::NarrowKey => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-key".into(), twin.narrow_key.clone()));
        }
        Identity::UnknownKey => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-key".into(), "standard_0000000000000000".into()));
        }
        Identity::OtherProjectKey => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-key".into(), other.key.clone()));
        }
        Identity::Session => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-session".into(), twin.session.clone()));
        }
        Identity::Jwt => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-jwt".into(), twin.jwt.clone()));
        }
        Identity::InvalidJwt => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-jwt".into(), "eyJhbGciOiJIUzI1NiJ9.e30.invalid".into()));
        }
        Identity::NoProject => headers.push(("x-appwrite-key".into(), twin.key.clone())),
        Identity::UnknownProject => {
            project(&mut headers, "unknown-project");
            headers.push(("x-appwrite-key".into(), twin.key.clone()));
        }
    }
    match scenario.origin {
        Origin::None => {}
        Origin::Platform => headers.push(("origin".into(), "http://localhost".into())),
        Origin::Foreign => headers.push(("origin".into(), "https://foreign.example".into())),
    }
    let mut method = route.method.to_owned();
    let mut body = body;
    match scenario.variant {
        Variant::Plain => {}
        Variant::Preflight => {
            // A browser sends no credentials on a preflight, only what the
            // request will carry.
            headers.retain(|(name, _)| name == "origin");
            headers.push(("access-control-request-method".into(), method.clone()));
            headers.push((
                "access-control-request-headers".into(),
                "content-type,x-appwrite-project,x-appwrite-key".into(),
            ));
            method = "OPTIONS".to_owned();
            body = None;
        }
        Variant::OldFormat => headers.push(("x-appwrite-response-format".into(), "1.4.0".into())),
        Variant::NewFormat => headers.push(("x-appwrite-response-format".into(), "99.0.0".into())),
        Variant::Locale => headers.push(("x-appwrite-locale".into(), "fr".into())),
    }
    (method, format!("{}{path}", twin.endpoint), headers, body)
}

/// Every command Redis receives, as `(client address, arguments)`, from a
/// `MONITOR` connection.
struct Monitor {
    lines: mpsc::UnboundedReceiver<(String, Vec<String>)>,
    marker: redis::aio::MultiplexedConnection,
}

impl Monitor {
    async fn start(address: &str) -> Result<Monitor, String> {
        let mut stream = TcpStream::connect(address).await.map_err(|e| format!("redis {address}: {e}"))?;
        stream.write_all(b"MONITOR\r\n").await.map_err(|e| e.to_string())?;
        let (tx, rx) = mpsc::unbounded_channel();
        tokio::spawn(async move {
            let mut reader = BufReader::new(stream);
            let mut line = String::new();
            loop {
                line.clear();
                match reader.read_line(&mut line).await {
                    Ok(0) | Err(_) => break,
                    Ok(_) => {
                        if let Some(parsed) = parse_monitor(line.trim_end()) {
                            let _ = tx.send(parsed);
                        }
                    }
                }
            }
        });
        let client = redis::Client::open(format!("redis://{address}")).map_err(|e| e.to_string())?;
        let marker = client.get_multiplexed_async_connection().await.map_err(|e| e.to_string())?;
        Ok(Monitor { lines: rx, marker })
    }

    /// Sends a marker command and returns every command received before it
    /// (since the previous marker).
    async fn until_marker(&mut self, name: &str) -> Result<Vec<(String, Vec<String>)>, String> {
        let key = format!("compat-diff-marker:{name}");
        let _: Option<String> =
            redis::cmd("GET").arg(&key).query_async(&mut self.marker).await.map_err(|e| e.to_string())?;
        let mut out = Vec::new();
        let deadline = tokio::time::Instant::now() + Duration::from_secs(10);
        loop {
            let next = tokio::time::timeout_at(deadline, self.lines.recv()).await;
            match next {
                Ok(Some((client, args))) => {
                    if args.len() == 2 && args[0].eq_ignore_ascii_case("GET") && args[1] == key {
                        return Ok(out);
                    }
                    out.push((client, args));
                }
                _ => return Err("the MONITOR stream stopped".into()),
            }
        }
    }
}

/// `1791933357.614927 [0 172.16.238.37:5544] "LPUSH" "key" "value"`.
fn parse_monitor(line: &str) -> Option<(String, Vec<String>)> {
    let line = line.strip_prefix('+').unwrap_or(line);
    let open = line.find('[')?;
    let close = line[open..].find(']')? + open;
    let source = line[open + 1..close].split_whitespace().nth(1)?;
    let client = source.rsplit_once(':').map_or(source, |(ip, _)| ip).to_owned();
    let mut args = Vec::new();
    let bytes = &line.as_bytes()[close + 1..];
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] != b'"' {
            i += 1;
            continue;
        }
        i += 1;
        let mut arg = Vec::new();
        while i < bytes.len() && bytes[i] != b'"' {
            if bytes[i] == b'\\' && i + 1 < bytes.len() {
                i += 1;
                match bytes[i] {
                    b'n' => arg.push(b'\n'),
                    b'r' => arg.push(b'\r'),
                    b't' => arg.push(b'\t'),
                    b'x' if i + 2 < bytes.len() => {
                        let hex = std::str::from_utf8(&bytes[i + 1..i + 3]).ok()?;
                        arg.push(u8::from_str_radix(hex, 16).ok()?);
                        i += 2;
                    }
                    other => arg.push(other),
                }
            } else {
                arg.push(bytes[i]);
            }
            i += 1;
        }
        args.push(String::from_utf8_lossy(&arg).into_owned());
        i += 1;
    }
    Some((client, args))
}

/// The commands that are side effects of a request: queue messages,
/// publications and cache purges. Reads, cache fills and leases are how
/// each runtime uses the cache, not what the request did.
fn effect(args: &[String]) -> Option<Value> {
    let command = args.first()?.to_ascii_uppercase();
    match command.as_str() {
        "LPUSH" | "RPUSH" if args.get(1).is_some_and(|k| k.starts_with("utopia-queue")) => {
            let messages: Vec<Value> =
                args[2..].iter().map(|m| serde_json::from_str(m).unwrap_or(Value::String(m.clone()))).collect();
            Some(json!({"queue": args[1], "messages": messages}))
        }
        "PUBLISH" => Some(
            json!({"publish": args.get(1), "message": args.get(2).map(|m| serde_json::from_str::<Value>(m).unwrap_or(Value::String(m.clone())))}),
        ),
        "DEL" | "UNLINK" => Some(json!({"purge": &args[1..]})),
        "HDEL" => Some(json!({"purge": args.get(1), "fields": &args[2..]})),
        _ => None,
    }
}

/// The project's tables, as rows of text.
async fn tables(
    db: &tokio_postgres::Client,
    prefix: &str,
) -> Result<BTreeMap<String, Vec<Vec<Option<String>>>>, String> {
    let names: Vec<String> = db
        .query(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'appwrite' AND left(tablename, length($1)) = $1 ORDER BY 1",
            &[&prefix],
        )
        .await
        .map_err(|e| e.to_string())?
        .iter()
        .map(|r| r.get::<_, String>(0))
        .collect();
    let mut out = BTreeMap::new();
    for name in names {
        let mut rows = Vec::new();
        for message in
            db.simple_query(&format!("SELECT * FROM appwrite.\"{name}\"")).await.map_err(|e| format!("{name}: {e}"))?
        {
            if let tokio_postgres::SimpleQueryMessage::Row(row) = message {
                rows.push((0..row.len()).map(|i| row.get(i).map(str::to_owned)).collect());
            }
        }
        out.insert(name[prefix.len()..].to_owned(), rows);
    }
    Ok(out)
}

type Rows = BTreeMap<String, Vec<Vec<Option<String>>>>;

/// Rows each table gained and lost.
fn delta(before: &Rows, after: &Rows) -> Value {
    let mut out = Map::new();
    for (table, rows) in after {
        let old = before.get(table).cloned().unwrap_or_default();
        let mut remaining = old.clone();
        let mut added = Vec::new();
        for row in rows {
            match remaining.iter().position(|r| r == row) {
                Some(i) => {
                    remaining.remove(i);
                }
                None => added.push(row.clone()),
            }
        }
        if !added.is_empty() || !remaining.is_empty() {
            out.insert(table.clone(), json!({"added": added, "removed": remaining}));
        }
    }
    Value::Object(out)
}

/// Placeholders for one runtime's record: the twin's own names, then
/// generated values numbered in order of appearance.
struct Normalizer {
    fixed: Vec<(String, String)>,
    generated: HashMap<String, String>,
    /// The project's sequence, as a string.
    sequence: String,
}

impl Normalizer {
    fn new(twin: &Twin, other: &Twin) -> Normalizer {
        let mut fixed = vec![
            (twin.key.clone(), "{key}".to_owned()),
            (twin.narrow_key.clone(), "{narrow-key}".to_owned()),
            (twin.seed_key.clone(), "{seed-key}".to_owned()),
            (other.key.clone(), "{other-key}".to_owned()),
            (twin.session.clone(), "{session}".to_owned()),
            (twin.jwt.clone(), "{jwt}".to_owned()),
            (twin.project.clone(), "{project}".to_owned()),
            (twin.prefix.clone(), "_{seq}_".to_owned()),
            (twin.prefix.trim_end_matches('_').to_owned(), "_{seq}".to_owned()),
            (format!("platform:{}:", twin.prefix.trim_matches('_')), "platform:{seq}:".to_owned()),
            (format!("project:{}:", twin.prefix.trim_matches('_')), "project:{seq}:".to_owned()),
            // Shared tables: the tenant in cache keys (`<cache>::<tenant>:collection:...`).
            (format!("::{}:", twin.prefix.trim_matches('_')), "::{seq}:".to_owned()),
        ];
        fixed.retain(|(from, _)| !from.is_empty());
        // Longest first, so a secret is replaced before a project ID it may contain.
        fixed.sort_by_key(|(from, _)| std::cmp::Reverse(from.len()));
        Normalizer { fixed, generated: HashMap::new(), sequence: twin.prefix.trim_matches('_').to_owned() }
    }

    fn text(&mut self, s: &str) -> String {
        let mut out = s.to_owned();
        for (from, to) in &self.fixed {
            if out.contains(from.as_str()) {
                out = out.replace(from.as_str(), to);
            }
        }
        if let Some(shape) = hash_shape(&out).or_else(|| envelope_shape(&out)) {
            return shape;
        }
        if let Some(kind) = generated(&out) {
            // Two datetimes may share a millisecond in one run and not the
            // other: they are not numbered.
            if kind == "datetime" {
                return "{datetime}".to_owned();
            }
            return self.number(out, kind);
        }
        // Lock keys name documents by their sequence, which differs between
        // the twins when tables are shared: numbered like generated IDs.
        if out.starts_with("lock:") {
            let parts: Vec<String> = out
                .split(':')
                .map(|p| {
                    if !p.is_empty() && p.bytes().all(|b| b.is_ascii_digit()) {
                        self.number(p.to_owned(), "seq")
                    } else {
                        p.to_owned()
                    }
                })
                .collect();
            return parts.join(":");
        }
        // IDs inside a longer string (cache keys, paths): each run of 20
        // hexadecimal characters between other characters.
        if out.len() > 20 {
            let bytes = out.as_bytes();
            let mut result = String::new();
            let mut i = 0;
            while i < bytes.len() {
                let run = bytes[i..].iter().take_while(|b| b.is_ascii_alphanumeric()).count();
                if run == 0 {
                    result.push(bytes[i] as char);
                    i += 1;
                    continue;
                }
                let token = &out[i..i + run];
                if generated(token) == Some("id") {
                    result.push_str(&self.number(token.to_owned(), "id"));
                } else {
                    result.push_str(token);
                }
                i += run;
            }
            return result;
        }
        out
    }

    /// The placeholder of a generated value, numbered by first appearance.
    fn number(&mut self, value: String, kind: &str) -> String {
        let n = self.generated.len() + 1;
        self.generated.entry(value).or_insert_with(|| format!("{{{kind}{n}}}")).clone()
    }

    fn value(&mut self, v: &Value) -> Value {
        match v {
            Value::String(s) => Value::String(self.text(s)),
            Value::Array(a) => Value::Array(a.iter().map(|x| self.value(x)).collect()),
            Value::Object(o) => {
                // The project document's sequence, and the times messages
                // are stamped with, differ between the twins by nature.
                // Documents named after the project (it, and its keys and
                // platforms, created once per twin) have twin sequences.
                let project =
                    o.get("$id").and_then(Value::as_str).is_some_and(|id| self.text(id).contains("{project}"));
                Value::Object(
                    o.iter()
                        .map(|(k, x)| {
                            let x = match (k.as_str(), x) {
                                ("$sequence", _) if project => Value::String("{seq}".into()),
                                (k, Value::String(v))
                                    if k.starts_with("project") && k.ends_with("InternalId") && *v == self.sequence =>
                                {
                                    Value::String("{seq}".into())
                                }
                                ("resourceInternalId", Value::String(v)) if *v == self.sequence => {
                                    Value::String("{seq}".into())
                                }
                                ("timestamp", Value::Number(_)) => Value::String("{timestamp}".into()),
                                // The response's size counts the `X-Debug-Speed`
                                // header, a time whose digits vary.
                                ("value", Value::Number(_))
                                    if o.get("key").and_then(Value::as_str) == Some("network.outbound") =>
                                {
                                    Value::String("{bytes}".into())
                                }
                                _ => self.value(x),
                            };
                            (self.text(k), x)
                        })
                        .collect(),
                )
            }
            other => other.clone(),
        }
    }
}

/// An Argon2 hash with its salt and digest replaced, keeping what is not
/// random: the variant, the parameters and the salt's alphabet (PHP's
/// `password_hash()` salts are 16 characters of `./0-9A-Za-z`).
fn hash_shape(s: &str) -> Option<String> {
    let rest = s.strip_prefix("$argon2")?;
    let parts: Vec<&str> = rest.split('$').collect();
    let [variant, version, params, salt, _digest] = parts.as_slice() else { return None };
    use base64::Engine;
    let bytes = base64::engine::general_purpose::STANDARD_NO_PAD.decode(salt).ok()?;
    let alphabet = if bytes.len() == 16 && bytes.iter().all(|b| b.is_ascii_alphanumeric() || *b == b'.' || *b == b'/') {
        "php-salt"
    } else {
        "raw-salt"
    };
    Some(format!("$argon2{variant}${version}${params}${{{alphabet}}}${{digest}}"))
}

/// An `encrypt` filter envelope (`{"data","method","iv","tag","version"}`)
/// with its random parts replaced, keeping the method, the version and how
/// the JSON was written (escaped slashes).
fn envelope_shape(s: &str) -> Option<String> {
    if !s.starts_with("{\"data\"") {
        return None;
    }
    let v: Value = serde_json::from_str(s).ok()?;
    let o = v.as_object()?;
    if !["data", "iv", "tag"].iter().all(|k| o.contains_key(*k)) {
        return None;
    }
    let keys: Vec<&str> = o.keys().map(String::as_str).collect();
    Some(format!(
        "{{encrypted keys={} method={} version={} escaped-slashes={}}}",
        keys.join(","),
        o.get("method").map(Value::to_string).unwrap_or_default(),
        o.get("version").map(Value::to_string).unwrap_or_default(),
        s.contains("\\/")
    ))
}

/// What kind of generated value `s` is, if it is one: an ID from
/// `ID::unique()` (20 hexadecimal characters), a `uniqid('', true)`, or a
/// datetime.
fn generated(s: &str) -> Option<&'static str> {
    if s.len() == 20 && s.bytes().all(|b| b.is_ascii_hexdigit()) {
        return Some("id");
    }
    if let Some((hex, digits)) = s.split_once('.')
        && (13..=14).contains(&hex.len())
        && hex.bytes().all(|b| b.is_ascii_hexdigit())
        && digits.len() == 8
        && digits.bytes().all(|b| b.is_ascii_digit())
    {
        return Some("uniqid");
    }
    let datetime = s.len() >= 19
        && s.as_bytes()[4] == b'-'
        && s.as_bytes()[7] == b'-'
        && (s.as_bytes()[10] == b'T' || s.as_bytes()[10] == b' ')
        && s.as_bytes()[13] == b':'
        && s[..4].bytes().all(|b| b.is_ascii_digit());
    datetime.then_some("datetime")
}

/// Headers that differ by nature (time, connection handling), each with why.
const MASKED_HEADERS: &[(&str, &str)] = &[
    ("date", "the time the response was sent"),
    ("x-ratelimit-reset", "a time"),
    ("connection", "transport: keep-alive handling is the server's"),
    ("keep-alive", "transport"),
    ("x-debug-speed", "timing"),
    ("content-length", "follows the body, which is compared"),
];

/// Error fields the development environment adds, each runtime pointing at
/// its own source; masked when present.
const MASKED_ERROR_FIELDS: &[(&str, &str)] = &[
    ("file", "the runtime's source file (development only)"),
    ("line", "the runtime's source line (development only)"),
    ("trace", "the runtime's stack (development only)"),
];

/// One runtime's record of a scenario.
async fn record(
    api: &Api,
    db: &tokio_postgres::Client,
    monitor: &mut Monitor,
    scenario: &Scenario,
    twin: &Twin,
    other: &Twin,
) -> Result<Value, String> {
    let before = tables(db, &twin.prefix).await?;
    monitor.until_marker(&format!("{}:{}:start", scenario.n, twin.runtime)).await?;
    let (method, url, headers, body) = request(scenario, twin, other);
    let reply = api.send(&method, &url, &headers, body.as_ref()).await?;
    // Work after the response (Rust's shutdown jobs) lands within this.
    tokio::time::sleep(Duration::from_millis(300)).await;
    let commands = monitor.until_marker(&format!("{}:{}:end", scenario.n, twin.runtime)).await?;
    let after = tables(db, &twin.prefix).await?;

    // Commands a script runs come from `lua`. MONITOR logs the `EVAL` first,
    // then its commands, together (a script runs atomically): they belong to
    // the client of the `EVAL` before them.
    let mut attributed: Vec<(String, &Vec<String>)> = Vec::new();
    let mut caller = String::new();
    for (client, args) in &commands {
        if client == "lua" {
            attributed.push((caller.clone(), args));
            continue;
        }
        if args.first().is_some_and(|c| c.to_ascii_uppercase().starts_with("EVAL")) {
            caller = client.clone();
        }
        attributed.push((client.clone(), args));
    }
    let mut effects: Vec<Value> = Vec::new();
    let mut purges: Vec<Value> = Vec::new();
    for (client, args) in attributed {
        if twin.ips.contains(&client)
            && let Some(e) = effect(args)
        {
            // Purges are idempotent and independent: their order is not
            // observable. Messages keep their order.
            if e.get("purge").is_some() { purges.push(e) } else { effects.push(e) }
        }
    }
    let headers: Vec<Value> = reply
        .headers
        .iter()
        .filter(|(n, _)| !MASKED_HEADERS.iter().any(|(m, _)| m == n))
        .map(|(n, v)| json!([n, v]))
        .collect();
    let mut body = reply.body;
    if let Value::Object(fields) = &mut body
        && fields.contains_key("trace")
    {
        for (field, _) in MASKED_ERROR_FIELDS {
            if let Some(v) = fields.get_mut(*field) {
                *v = Value::String("{development}".into());
            }
        }
    }
    let mut normalizer = Normalizer::new(twin, other);
    let raw = json!({
        "status": reply.status,
        "headers": headers,
        "body": body,
        "database": delta(&before, &after),
        "effects": effects,
        "purges": purges,
    });
    let mut normalized = normalizer.value(&raw);
    // Every command the runtime sent, to read a difference (not compared).
    if std::env::var_os("COMPAT_DIFF_RAW").is_some() {
        let sent: Vec<Value> = commands
            .iter()
            .map(|(client, args)| json!([client, args]))
            .filter(|c| c[0].as_str().is_some_and(|ip| twin.ips.iter().any(|t| t == ip) || ip == "lua"))
            .collect();
        normalized["redis"] = normalizer.value(&Value::Array(sent));
    }
    if let Some(Value::Array(p)) = normalized.get_mut("purges") {
        p.sort_by_key(|v| v.to_string());
        p.dedup();
    }
    Ok(normalized)
}

/// The first path at which `a` and `b` differ.
fn first_difference(a: &Value, b: &Value, path: &str) -> Option<String> {
    match (a, b) {
        (Value::Object(x), Value::Object(y)) => {
            for key in x.keys().chain(y.keys()) {
                let (l, r) = (x.get(key).unwrap_or(&Value::Null), y.get(key).unwrap_or(&Value::Null));
                if let Some(d) = first_difference(l, r, &format!("{path}/{key}")) {
                    return Some(d);
                }
            }
            None
        }
        (Value::Array(x), Value::Array(y)) => {
            if x.len() != y.len() {
                return Some(format!("{path}: {} items vs {}", x.len(), y.len()));
            }
            x.iter().zip(y).enumerate().find_map(|(i, (l, r))| first_difference(l, r, &format!("{path}/{i}")))
        }
        _ if a == b => None,
        _ => Some(format!("{path}: {} vs {}", short(a), short(b))),
    }
}

fn short(v: &Value) -> String {
    let s = v.to_string();
    match s.char_indices().nth(160) {
        Some((cut, _)) => format!("{}…", &s[..cut]),
        None => s,
    }
}

/// Runs the scenarios whose id contains `filter` (all without one) and
/// returns the summary, with every difference.
pub fn run(_root: &Path, filter: Option<&str>, verbose: bool) -> Result<Value, String> {
    let stack = Stack::from_env()?;
    let runtime = tokio::runtime::Builder::new_current_thread().enable_all().build().map_err(|e| e.to_string())?;
    runtime.block_on(async move {
        let api = Api::new();
        let (db, connection) =
            tokio_postgres::connect(&stack.postgres, tokio_postgres::NoTls).await.map_err(|e| format!("postgres: {e}"))?;
        tokio::spawn(connection);
        let console = console(&api, &stack).await?;
        // Twins an interrupted run left behind.
        let query = json!({"method": "startsWith", "attribute": "$id", "values": ["diff"]}).to_string();
        let mut url = utopia_client::Uri::parse(&format!("{}/projects", stack.php)).map_err(|e| e.to_string())?;
        url = url.with_query(&format!("queries[]={}", php_std::encoding::rawurlencode(query.as_bytes())));
        let leftovers = api.send("GET", &url.to_string(), &console.headers, None).await?;
        for project in leftovers.body["projects"].as_array().into_iter().flatten() {
            if let Some(id) = project["$id"].as_str() {
                api.send("DELETE", &format!("{}/projects/{id}", stack.php), &console.headers, None).await?;
            }
        }
        let run = chrono::Utc::now().format("%m%d%H%M%S").to_string();
        let php = twin(&api, &stack, &console, &db, "php", &run).await?;
        let rust = twin(&api, &stack, &console, &db, "rust", &run).await?;
        eprintln!("compat appwrite diff: projects {} (PHP) and {} (Rust)", php.project, rust.project);
        let mut monitor = Monitor::start(&stack.redis).await?;

        let list = scenarios(filter);
        let mut differences = Vec::new();
        let mut passed = 0;
        for scenario in &list {
            let a = record(&api, &db, &mut monitor, scenario, &php, &rust).await?;
            let b = record(&api, &db, &mut monitor, scenario, &rust, &php).await?;
            let compared = |r: &Value| {
                let mut r = r.clone();
                if let Some(o) = r.as_object_mut() {
                    o.remove("redis");
                }
                r
            };
            match first_difference(&compared(&a), &compared(&b), "") {
                None => {
                    passed += 1;
                    if verbose {
                        eprintln!("ok    {}", scenario.id);
                    }
                }
                Some(at) => {
                    eprintln!("DIFF  {}: {at}", scenario.id);
                    differences.push(json!({"scenario": scenario.id, "at": at, "php": a, "rust": b}));
                }
            }
        }
        eprintln!("compat appwrite diff: {} scenarios, {passed} identical, {} different", list.len(), differences.len());
        for twin in [&php, &rust] {
            api.send("DELETE", &format!("{}/projects/{}", stack.php, twin.project), &console.headers, None).await?;
        }
        Ok(json!({
            "projects": {"php": php.project, "rust": rust.project},
            "scenarios": list.len(),
            "identical": passed,
            "masked_headers": MASKED_HEADERS.iter().map(|(h, why)| json!({"header": h, "why": why})).collect::<Vec<_>>(),
            "masked_error_fields": MASKED_ERROR_FIELDS.iter().map(|(f, why)| json!({"field": f, "why": why})).collect::<Vec<_>>(),
            "differences": differences,
        }))
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn monitor_lines_parse() {
        let (client, args) = parse_monitor(
            r#"+1791933357.614927 [0 172.16.238.37:5544] "LPUSH" "utopia-queue.queue.v1-events" "{\"a\":\"x\\\"y\"}""#,
        )
        .unwrap();
        assert_eq!(client, "172.16.238.37");
        assert_eq!(args, vec!["LPUSH", "utopia-queue.queue.v1-events", r#"{"a":"x\"y"}"#]);
        let (_, args) = parse_monitor(r#"1.0 [0 127.0.0.1:1] "SET" "k" "\xff\x00""#).unwrap();
        assert_eq!(args[2], "\u{FFFD}\0");
    }

    #[test]
    fn generated_values_are_numbered_by_appearance() {
        assert_eq!(generated("6527a1b2c3d4e5f6a7b8"), Some("id"));
        assert_eq!(generated("2026-10-14T00:15:57.614+00:00"), Some("datetime"));
        assert_eq!(generated("u1"), None);
    }
}
