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
use utopia_async::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use utopia_async::net::TcpStream;
use utopia_async::sync::mpsc;
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

/// An ephemeral API key for `project`, signed as the API signs them (HS256
/// with the stack's `_APP_OPENSSL_KEY_V1`), leaving two usage metrics out.
fn ephemeral_key(project: &str) -> Result<String, String> {
    use base64::Engine as _;
    use base64::engine::general_purpose::URL_SAFE_NO_PAD;
    use hmac::{Hmac, Mac};
    let out = std::process::Command::new("docker")
        .args(["inspect", "-f", "{{range .Config.Env}}{{println .}}{{end}}", "appwrite"])
        .output()
        .map_err(|e| format!("docker: {e}"))?;
    let env = String::from_utf8_lossy(&out.stdout);
    let secret = env.lines().find_map(|l| l.strip_prefix("_APP_OPENSSL_KEY_V1=")).unwrap_or_default();
    let now = chrono::Utc::now().timestamp();
    let header = URL_SAFE_NO_PAD.encode(br#"{"typ":"JWT","alg":"HS256"}"#);
    let claims = json!({
        "projectId": project,
        "scopes": SCOPES,
        "disabledMetrics": ["requests", "outbound"],
        "iat": now,
        "exp": now + 3600,
    });
    let payload = URL_SAFE_NO_PAD.encode(claims.to_string());
    let mut mac = Hmac::<sha2::Sha256>::new_from_slice(secret.as_bytes()).map_err(|e| e.to_string())?;
    mac.update(format!("{header}.{payload}").as_bytes());
    let signature = URL_SAFE_NO_PAD.encode(mac.finalize().into_bytes());
    Ok(format!("ephemeral_{header}.{payload}.{signature}"))
}

/// `value` as the `encrypt` database filter stores it: AES-128-GCM under the
/// stack's `_APP_OPENSSL_KEY_V1`, in PHP's JSON envelope.
fn encrypted(value: &str) -> Result<String, String> {
    use base64::Engine as _;
    let out = std::process::Command::new("docker")
        .args(["inspect", "-f", "{{range .Config.Env}}{{println .}}{{end}}", "appwrite"])
        .output()
        .map_err(|e| format!("docker: {e}"))?;
    let env = String::from_utf8_lossy(&out.stdout);
    let secret = env.lines().find_map(|l| l.strip_prefix("_APP_OPENSSL_KEY_V1=")).unwrap_or_default();
    // PHP's OpenSSL truncates (or zero-pads) the key to the cipher's 16 bytes.
    let mut key = [0u8; 16];
    let n = secret.len().min(16);
    key[..n].copy_from_slice(&secret.as_bytes()[..n]);
    let mut iv = [0u8; 12];
    openssl::rand::rand_bytes(&mut iv).map_err(|e| e.to_string())?;
    let mut tag = [0u8; 16];
    let data = openssl::symm::encrypt_aead(
        openssl::symm::Cipher::aes_128_gcm(),
        &key,
        Some(&iv),
        b"",
        value.as_bytes(),
        &mut tag,
    )
    .map_err(|e| e.to_string())?;
    let hex = |bytes: &[u8]| bytes.iter().map(|b| format!("{b:02x}")).collect::<String>();
    Ok(json!({
        "data": base64::engine::general_purpose::STANDARD.encode(data),
        "method": "aes-128-gcm",
        "iv": hex(&iv),
        "tag": hex(&tag),
        "version": "1",
    })
    .to_string())
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
    /// An ephemeral key (a JWT the API signs) whose usage leaves metrics out.
    ephemeral: String,
    keys: OwnedKeys,
    /// The Account routes' own users (see [`ROUTE_USERS`]): user, session, JWT.
    accounts: Vec<(String, String, String)>,
    /// Values the Account routes' bodies name (`{recovery-secret}`, ...).
    vars: Vec<(String, String)>,
}

impl Twin {
    /// The session and JWT a route's client identities use: its own user's
    /// ([`ROUTE_USERS`]), else `u1`'s.
    fn credentials(&self, route: &Route) -> (&str, &str) {
        let user = ROUTE_USERS.iter().find(|(name, _)| *name == route.name).map(|(_, u)| *u);
        match user.and_then(|u| self.accounts.iter().find(|(id, _, _)| id == u)) {
            Some((_, session, jwt)) => (session, jwt),
            None => (&self.session, &self.jwt),
        }
    }
}

/// Account routes that change their user for good (block, delete, verify):
/// their session and JWT identities are a user of their own.
const ROUTE_USERS: &[(&str, &str)] =
    &[("account.status.update", "a3"), ("account.delete", "a4"), ("account.verifications.phone.create", "a5")];

/// The users the Account routes act on besides `u1` and `u2`: `a3`..`a5`
/// (see [`ROUTE_USERS`]), `a6` (its recoveries are confirmed) and `a7`
/// (a mock number: its phone verification has a known code).
const ACCOUNT_USERS: &[(&str, Option<&str>)] =
    &[("a3", None), ("a4", None), ("a5", Some("+15555550105")), ("a6", None), ("a7", Some("+15555550107"))];

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

/// The account's and the organization's keys the scenarios use (each twin
/// has its own console account, so its account's `keys` are its own). No route
/// creates them on a self-hosted instance, so `tests/compat/appwrite/fixtures.php`
/// writes them to the platform database in the API container. Each twin
/// has its own, so the first use of a key (which records its access) is
/// each runtime's.
struct OwnedKeys {
    /// The console account that owns the account keys, and its sequence.
    user: String,
    user_sequence: String,
    /// The organization's sequence (key locks are named after it).
    team_sequence: String,
    ids: Vec<String>,
    account: String,
    expired_account: String,
    organization: String,
}

fn fixtures(input: &Value) -> Result<Value, String> {
    let mut child = std::process::Command::new("docker")
        .args(["exec", "-i", "appwrite", "php", "/usr/src/code/tests/compat/appwrite/fixtures.php"])
        .stdin(std::process::Stdio::piped())
        .stdout(std::process::Stdio::piped())
        .stderr(std::process::Stdio::piped())
        .spawn()
        .map_err(|e| format!("docker: {e}"))?;
    if let Some(mut stdin) = child.stdin.take() {
        use std::io::Write;
        stdin.write_all(input.to_string().as_bytes()).map_err(|e| format!("fixtures.php: {e}"))?;
    }
    let out = child.wait_with_output().map_err(|e| format!("fixtures.php: {e}"))?;
    let text = String::from_utf8_lossy(&out.stdout);
    let last = text.lines().rev().find(|l| l.starts_with('{')).unwrap_or_default();
    serde_json::from_str(last).map_err(|_| format!("fixtures.php: {text}{}", String::from_utf8_lossy(&out.stderr)))
}

fn seed_owned_keys(
    console: &Console,
    project: &str,
    user: String,
    user_sequence: String,
    team_sequence: String,
) -> Result<OwnedKeys, String> {
    let run = project;
    let account = format!("account_diff{run}");
    let expired_account = format!("account_diff{run}expired");
    let organization = format!("organization_diff{run}");
    let created = fixtures(&json!({"create": [
        {"resourceType": "users", "resourceId": user, "secret": account, "scopes": SCOPES},
        {"resourceType": "users", "resourceId": user, "secret": expired_account, "scopes": SCOPES,
            "expire": "2020-01-01T00:00:00.000+00:00"},
        {"resourceType": "teams", "resourceId": console.team, "secret": organization, "scopes": SCOPES},
    ]}))?;
    let ids = created["keys"]
        .as_array()
        .ok_or(format!("fixtures.php: {created}"))?
        .iter()
        .filter_map(|id| id.as_str().map(str::to_owned))
        .collect();
    Ok(OwnedKeys { user, user_sequence, team_sequence, ids, account, expired_account, organization })
}

/// The scopes of the key scenarios use, and of the one that seeds.
const SCOPES: &[&str] = &[
    "users.read",
    "users.write",
    "sessions.read",
    "sessions.write",
    "targets.read",
    "targets.write",
    "teams.read",
    "teams.write",
];

/// The secret of the invitation the status scenarios accept (`m3`, of `u4`
/// to `t1`), written over the one its email carries.
const INVITE_SECRET: &str = "compat-invite-secret";

async fn twin(
    api: &Api,
    stack: &Stack,
    console: &Console,
    db: &tokio_postgres::Client,
    runtime: &'static str,
    run: &str,
) -> Result<Twin, String> {
    let php = &stack.php;
    let project = format!("{}{run}{}", prefix(), &runtime[..1]);
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
    for (id, name) in [("u1", "Ada"), ("u2", "Grace"), ("u3", "Linus"), ("u4", "Ken")] {
        post(
            "/users",
            json!({"userId": id, "email": format!("{id}@example.com"), "password": "password-1234", "name": name}),
        )
        .await?;
    }
    // Account: `s1` signs in with its password; `u3` gets the sessions
    // scenarios delete, `u4` the tokens they exchange.
    post("/users", json!({"userId": "s1", "email": "s1@example.com", "password": "password-1234", "name": "Signer"}))
        .await?;
    // The mock OAuth2 provider (the browser flow and the native ID tokens).
    let console_patch = |path: String, body: Value| {
        let headers = console.headers.clone();
        async move {
            let r = api.send("PATCH", &format!("{php}{path}"), &headers, Some(&body)).await?;
            if r.status >= 300 {
                return Err(format!("{path}: {} {}", r.status, r.body));
            }
            Ok(())
        }
    };
    console_patch(
        format!("/projects/{project}/oauth2"),
        json!({"provider": "mock", "appId": "1", "secret": "123456", "enabled": true}),
    )
    .await?;
    console_patch(
        "/mock/tests/general/oauth2/native".to_owned(),
        json!({"projectId": project, "provider": "mock", "enabled": true, "clientIds": []}),
    )
    .await?;
    let session = post("/users/u1/sessions", json!({})).await?["secret"].as_str().unwrap_or_default().to_owned();
    // The longest a JWT lives: a run outlasts the default 15 minutes, and the
    // PHP twin's token, made first, would expire a few seconds before the
    // Rust twin's, the scenarios in between seeing different identities.
    let jwt =
        post("/users/u1/jwts", json!({"duration": JWT_DURATION})).await?["jwt"].as_str().unwrap_or_default().to_owned();
    let (accounts, vars) = seed_accounts(api, stack, console, &project, &server, &session).await?;
    let sequence = db
        .query_one("SELECT _id::text FROM appwrite._console_projects WHERE _uid = $1", &[&project])
        .await
        .map_err(|e| format!("project sequence: {e}"))?
        .get::<_, String>(0);
    // Teams: `t1` owned by u1 (the session's user) with u2 a member, `t2`
    // with u2 (deleted with its memberships), and u1's pending invitation
    // of u4 to `t1`, whose secret is set to a known one.
    post("/teams", json!({"teamId": "t1", "name": "Alpha"})).await?;
    post("/teams", json!({"teamId": "t2", "name": "Beta"})).await?;
    for (team, user, membership, role) in
        [("t1", "u1", "m1", "owner"), ("t1", "u2", "m2", "member"), ("t2", "u2", "m4", "member")]
    {
        post(
            &format!("/teams/{team}/memberships"),
            json!({"userId": user, "roles": [role], "membershipId": membership}),
        )
        .await?;
    }
    let invite = api
        .send(
            "POST",
            &format!("{php}/teams/t1/memberships"),
            &[("x-appwrite-project".to_owned(), project.clone()), ("x-appwrite-session".to_owned(), session.clone())],
            Some(&json!({"userId": "u4", "roles": ["member"], "url": "http://localhost", "membershipId": "m3"})),
        )
        .await?;
    if invite.status != 201 {
        return Err(format!("invitation: {} {}", invite.status, invite.body));
    }
    // The stored secret is the token's SHA-256, encrypted. Nothing read the
    // invitation since it was created, so no cached copy holds the old one.
    let hash: String = {
        use sha2::Digest as _;
        sha2::Sha256::digest(INVITE_SECRET.as_bytes()).iter().map(|b| format!("{b:02x}")).collect()
    };
    // Dedicated tables are the project's own (`_<sequence>_memberships`);
    // shared tables hold every project's rows, told apart by `_tenant`.
    let secret = encrypted(&hash)?;
    let dedicated = format!("_{sequence}_memberships");
    let exists = db
        .query_one("SELECT to_regclass($1) IS NOT NULL", &[&format!("appwrite.\"{dedicated}\"")])
        .await
        .map_err(|e| format!("invitation secret: {e}"))?
        .get::<_, bool>(0);
    if exists {
        db.execute(&format!("UPDATE appwrite.\"{dedicated}\" SET secret = $1 WHERE _uid = 'm3'"), &[&secret])
            .await
            .map_err(|e| format!("invitation secret: {e}"))?;
    } else {
        let shared: Vec<String> = db
            .query(
                "SELECT table_name::text FROM information_schema.columns WHERE table_schema = 'appwrite' \
                 AND column_name = '_tenant' AND right(table_name::text, 12) = '_memberships'",
                &[],
            )
            .await
            .map_err(|e| format!("invitation secret: {e}"))?
            .iter()
            .map(|r| r.get::<_, String>(0))
            .collect();
        let mut updated = 0;
        for table in shared {
            updated += db
                .execute(
                    &format!("UPDATE appwrite.\"{table}\" SET secret = $1 WHERE _uid = 'm3' AND _tenant::text = $2"),
                    &[&secret, &sequence],
                )
                .await
                .map_err(|e| format!("invitation secret: {table}: {e}"))?;
        }
        if updated == 0 {
            return Err("invitation secret: no membership row".to_owned());
        }
    }
    let account = api
        .send(
            "POST",
            &format!("{php}/account"),
            &[("x-appwrite-project".to_owned(), "console".to_owned())],
            Some(&json!({"userId": format!("{project}-account"), "email": format!("{project}@example.com"),
                "password": "password-1234", "name": "Account"})),
        )
        .await?;
    let user = account.body["$id"].as_str().ok_or(format!("account: {} {}", account.status, account.body))?.to_owned();
    let user_sequence = db
        .query_one("SELECT _id::text FROM appwrite._console_users WHERE _uid = $1", &[&user])
        .await
        .map_err(|e| format!("account sequence: {e}"))?
        .get::<_, String>(0);
    let team_sequence = db
        .query_one("SELECT _id::text FROM appwrite._console_teams WHERE _uid = $1", &[&console.team])
        .await
        .map_err(|e| format!("organization sequence: {e}"))?
        .get::<_, String>(0);
    let keys = seed_owned_keys(console, &project, user, user_sequence, team_sequence)?;
    let ephemeral = ephemeral_key(&project)?;
    fixtures(&json!({"rules": [{"projectId": project, "domain": rule_domain(&project)}]}))?;
    let (endpoint, container) =
        if runtime == "php" { (stack.php.clone(), "appwrite") } else { (stack.rust.clone(), "appwrite-rust") };
    Ok(Twin {
        runtime,
        endpoint,
        // A Rust server run on the host (`COMPAT_DIFF_RUST_IPS`: the
        // addresses Redis sees it from) instead of its container.
        ips: match std::env::var("COMPAT_DIFF_RUST_IPS") {
            Ok(ips) if runtime == "rust" => ips.split(',').map(str::to_owned).collect(),
            _ => container_ips(container),
        },
        project,
        prefix: format!("_{sequence}_"),
        key: full,
        narrow_key: narrow,
        seed_key: server[1].1.clone(),
        session,
        jwt,
        ephemeral,
        keys,
        accounts,
        vars,
    })
}

/// Seeds what the Account routes need, through PHP: the users of
/// [`ACCOUNT_USERS`] with a session and a JWT each, a mock number for `a7`
/// and its phone verification (code `123456`), `a6`'s recoveries (their
/// secrets are the `{recovery-secret}` and `{recovery-otp}` body values), and
/// two push targets of `u1`'s session (`pt1`, `pt2`).
async fn seed_accounts(
    api: &Api,
    stack: &Stack,
    console: &Console,
    project: &str,
    server: &[(String, String)],
    session: &str,
) -> Result<(Vec<(String, String, String)>, Vec<(String, String)>), String> {
    let php = &stack.php;
    let send = |method: &'static str, path: String, headers: Vec<(String, String)>, body: Value| async move {
        let url = format!("{php}{path}");
        let r = api.send(method, &url, &headers, Some(&body)).await?;
        if r.status >= 300 {
            return Err(format!("{method} {url}: {} {}", r.status, r.body));
        }
        Ok(r.body)
    };
    let mock = json!({"numbers": [{"phone": "+15555550107", "otp": "123456"}]});
    send("PATCH", format!("/projects/{project}/auth/mock-numbers"), console.headers.clone(), mock).await?;
    let mut accounts = Vec::new();
    for (id, phone) in ACCOUNT_USERS {
        let mut body = json!({"userId": id, "email": format!("{id}@example.com"), "password": "password-1234",
            "name": format!("User {id}")});
        if let Some(phone) = phone {
            body["phone"] = json!(phone);
        }
        send("POST", "/users".into(), server.to_vec(), body).await?;
        let session = send("POST", format!("/users/{id}/sessions"), server.to_vec(), json!({})).await?["secret"]
            .as_str()
            .unwrap_or_default()
            .to_owned();
        let jwt = send("POST", format!("/users/{id}/jwts"), server.to_vec(), json!({"duration": JWT_DURATION})).await?
            ["jwt"]
            .as_str()
            .unwrap_or_default()
            .to_owned();
        accounts.push(((*id).to_owned(), session, jwt));
    }
    let client = |session: &str| {
        vec![
            ("x-appwrite-project".to_owned(), project.to_owned()),
            ("x-appwrite-session".to_owned(), session.to_owned()),
        ]
    };
    let a7 = accounts.iter().find(|(id, _, _)| id == "a7").map(|(_, s, _)| s.clone()).unwrap_or_default();
    send("POST", "/account/verifications/phone".into(), client(&a7), json!({})).await?;
    let recovery = json!({"email": "a6@example.com", "url": "http://localhost/recover"});
    let secret = send("POST", "/account/recovery".into(), server.to_vec(), recovery).await?["secret"]
        .as_str()
        .unwrap_or_default()
        .to_owned();
    let otp = send("POST", "/account/recovery/otp".into(), server.to_vec(), json!({"email": "a6@example.com"})).await?
        ["secret"]
        .as_str()
        .unwrap_or_default()
        .to_owned();
    for target in ["pt1", "pt2"] {
        let body = json!({"targetId": target, "identifier": format!("push-{target}")});
        send("POST", "/account/targets/push".into(), client(session), body).await?;
    }
    let vars = vec![("{recovery-secret}".to_owned(), secret), ("{recovery-otp}".to_owned(), otp)];
    Ok((accounts, vars))
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
    /// An ephemeral key with `disabledMetrics`.
    EphemeralKey,
    UnknownProject,
    /// The console account's key, with `x-appwrite-user`.
    AccountKey,
    /// The account's key without `x-appwrite-user`.
    AccountKeyNoUser,
    /// The account's expired key.
    ExpiredAccountKey,
    /// The account's key alongside a session.
    AccountKeySession,
    /// The organization's key, with `x-appwrite-organization`.
    OrganizationKey,
    /// The organization's key without `x-appwrite-organization`.
    OrganizationKeyNoTeam,
}

const IDENTITIES: [Identity; 17] = [
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
    Identity::AccountKey,
    Identity::AccountKeyNoUser,
    Identity::ExpiredAccountKey,
    Identity::AccountKeySession,
    Identity::OrganizationKey,
    Identity::OrganizationKeyNoTeam,
    Identity::EphemeralKey,
];

#[derive(Clone, Copy, Debug)]
enum Origin {
    None,
    Platform,
    Foreign,
    /// The twin's custom domain rule (an allowed hostname).
    Rule,
    /// The other twin's custom domain rule (not this project's).
    OtherRule,
}

const ORIGINS: [Origin; 3] = [Origin::None, Origin::Platform, Origin::Foreign];

/// The custom domain origins, under fewer identities (the rule depends on
/// the project, not on who calls).
const RULE_ORIGINS: [Origin; 2] = [Origin::Rule, Origin::OtherRule];
const RULE_IDENTITIES: [Identity; 3] = [Identity::Guest, Identity::Key, Identity::Session];

/// The twins' JWT lifetime in seconds: the most `POST /users/:userId/jwts`
/// accepts, longer than a run.
const JWT_DURATION: u32 = 3600;

/// The twins' project ID prefix (`COMPAT_DIFF_PREFIX`, `diff` by default):
/// runs on one stack with different prefixes leave each other's twins alone.
fn prefix() -> String {
    std::env::var("COMPAT_DIFF_PREFIX").ok().filter(|p| !p.is_empty()).unwrap_or_else(|| "diff".to_owned())
}

/// A twin's custom domain.
fn rule_domain(project: &str) -> String {
    format!("{project}.compat-diff.test")
}

/// A route under test: its request, with `{n}` replaced by the scenario's
/// number (to create distinct resources), `{project}` by the twin's
/// project, and `{token-user}` / `{token-secret}` by its setup's token.
struct Route {
    name: &'static str,
    method: &'static str,
    path: &'static str,
    body: Option<&'static str>,
    /// Work done through PHP before each scenario's request.
    setup: Setup,
    /// The identities, origins and variants the route runs under.
    matrix: Matrix,
}

/// What a scenario needs made before its request (through PHP, as the
/// twins are seeded), so a request that consumes it finds it.
#[derive(Clone, Copy, Debug, PartialEq)]
enum Setup {
    None,
    /// A session of `u3` (and a JWT bound to it) replaces the session and
    /// JWT identities: the request may delete it.
    FreshSession,
    /// A token of `u4` to exchange (`{token-user}`, `{token-secret}`).
    Token,
    /// An ID token the mock provider signed for a new subject (`{id-token}`).
    IdToken,
}

/// Which identities, origins and variants a route runs under.
#[derive(Clone, Copy, Debug, PartialEq)]
enum Matrix {
    /// Every identity, origin and variant.
    Full,
    /// The identities and origins that decide the outcome, the preflight,
    /// and the response format and locale variants.
    Reduced,
}

/// The reduced matrix's identities.
const REDUCED_IDENTITIES: [Identity; 12] = [
    Identity::Guest,
    Identity::Key,
    Identity::NarrowKey,
    Identity::UnknownKey,
    Identity::Session,
    Identity::Jwt,
    Identity::InvalidJwt,
    Identity::NoProject,
    Identity::UnknownProject,
    Identity::AccountKey,
    Identity::OrganizationKey,
    Identity::EphemeralKey,
];

/// The reduced matrix's variants.
const REDUCED_VARIANTS: [Variant; 4] = [Variant::Preflight, Variant::OldFormat, Variant::NewFormat, Variant::Locale];

const fn route(name: &'static str, method: &'static str, path: &'static str, body: Option<&'static str>) -> Route {
    Route { name, method, path, body, setup: Setup::None, matrix: Matrix::Full }
}

const fn account(
    name: &'static str,
    method: &'static str,
    path: &'static str,
    body: Option<&'static str>,
    setup: Setup,
    matrix: Matrix,
) -> Route {
    Route { name, method, path, body, setup, matrix }
}

use Matrix::{Full, Reduced};

/// `success`, `failure`, `token`, an empty `nonce` and `state` of the OAuth2
/// redirect route (URL-encoded), as a token flow or a session flow.
const OAUTH2_TOKEN_STATE: &str = "%7B%22success%22%3A%22http%3A%2F%2Flocalhost%2Fsuccess%22%2C%22failure%22%3A%22http%3A%2F%2Flocalhost%2Ffailure%22%2C%22token%22%3Atrue%2C%22nonce%22%3A%22%22%2C%22state%22%3A%22app%22%7D";

const ROUTES: &[Route] = &[
    route("users.list", "GET", "/users", None),
    route("users.list.invalid", "GET", "/users?queries[]=bogus", None),
    route("users.get", "GET", "/users/u1", None),
    route("users.get.missing", "GET", "/users/missing", None),
    route("users.prefs.get", "GET", "/users/u1/prefs", None),
    route("users.name.update", "PATCH", "/users/u2/name", Some(r#"{"name":"Name {n}"}"#)),
    route(
        "users.create",
        "POST",
        "/users",
        Some(r#"{"userId":"c{n}","email":"c{n}@example.com","password":"password-1234","name":"Created {n}"}"#),
    ),
    route("users.delete.missing", "DELETE", "/users/missing", None),
    // Sessions: the database listener counts them in usage.
    route("users.sessions.create", "POST", "/users/u2/sessions", None),
    route("users.sessions.delete", "DELETE", "/users/u2/sessions", None),
    // Account: sign-up and sessions. The sign-ins of `s1` pass its session limit.
    account(
        "account.create",
        "POST",
        "/account",
        Some(r#"{"userId":"a{n}","email":"a{n}@example.com","password":"password-1234","name":"Account {n}"}"#),
        Setup::None,
        Full,
    ),
    account(
        "account.create.invalid",
        "POST",
        "/account",
        Some(r#"{"userId":"a{n}","email":"not-an-email","password":"short"}"#),
        Setup::None,
        Reduced,
    ),
    account("account.sessions.list", "GET", "/account/sessions", None, Setup::None, Full),
    account("account.sessions.get", "GET", "/account/sessions/current", None, Setup::None, Reduced),
    account("account.sessions.get.missing", "GET", "/account/sessions/missing", None, Setup::None, Reduced),
    account("account.sessions.update", "PATCH", "/account/sessions/current", None, Setup::None, Reduced),
    account("account.sessions.delete", "DELETE", "/account/sessions/current", None, Setup::FreshSession, Reduced),
    account("account.sessions.delete.all", "DELETE", "/account/sessions", None, Setup::FreshSession, Reduced),
    account(
        "account.sessions.delete.others",
        "DELETE",
        "/account/sessions",
        Some(r#"{"current":false}"#),
        Setup::FreshSession,
        Reduced,
    ),
    account(
        "account.sessions.email",
        "POST",
        "/account/sessions/email",
        Some(r#"{"email":"s1@example.com","password":"password-1234"}"#),
        Setup::None,
        Full,
    ),
    account(
        "account.sessions.email.invalid",
        "POST",
        "/account/sessions/email",
        Some(r#"{"email":"s1@example.com","password":"wrong-password"}"#),
        Setup::None,
        Reduced,
    ),
    account("account.sessions.anonymous", "POST", "/account/sessions/anonymous", None, Setup::None, Reduced),
    account(
        "account.sessions.token",
        "POST",
        "/account/sessions/token",
        Some(r#"{"userId":"{token-user}","secret":"{token-secret}"}"#),
        Setup::Token,
        Full,
    ),
    account(
        "account.sessions.token.invalid",
        "POST",
        "/account/sessions/token",
        Some(r#"{"userId":"u1","secret":"bogus"}"#),
        Setup::None,
        Reduced,
    ),
    account(
        "account.sessions.magic-url",
        "PUT",
        "/account/sessions/magic-url",
        Some(r#"{"userId":"{token-user}","secret":"{token-secret}"}"#),
        Setup::Token,
        Reduced,
    ),
    account(
        "account.sessions.phone",
        "PUT",
        "/account/sessions/phone",
        Some(r#"{"userId":"{token-user}","secret":"{token-secret}"}"#),
        Setup::Token,
        Reduced,
    ),
    account(
        "account.sessions.id-token",
        "POST",
        "/account/sessions/id-token",
        Some(r#"{"provider":"mock","idToken":"{id-token}"}"#),
        Setup::IdToken,
        Reduced,
    ),
    account(
        "account.sessions.id-token.invalid",
        "POST",
        "/account/sessions/id-token",
        Some(r#"{"provider":"mock","idToken":"not-a-token"}"#),
        Setup::None,
        Reduced,
    ),
    // Tokens.
    account(
        "account.tokens.magic-url",
        "POST",
        "/account/tokens/magic-url",
        Some(r#"{"userId":"m{n}","email":"m{n}@example.com","url":"http://localhost/magic"}"#),
        Setup::None,
        Reduced,
    ),
    account(
        "account.tokens.email",
        "POST",
        "/account/tokens/email",
        Some(r#"{"userId":"e{n}","email":"e{n}@example.com","phrase":true}"#),
        Setup::None,
        Reduced,
    ),
    account(
        "account.tokens.phone",
        "POST",
        "/account/tokens/phone",
        Some(r#"{"userId":"p{n}","phone":"+1555000{n}"}"#),
        Setup::None,
        Reduced,
    ),
    account("account.jwts", "POST", "/account/jwts", None, Setup::None, Full),
    // MFA.
    account("account.mfa.factors", "GET", "/account/mfa/factors", None, Setup::None, Reduced),
    account("account.mfa.update", "PATCH", "/account/mfa", Some(r#"{"mfa":false}"#), Setup::None, Reduced),
    account("account.mfa.authenticator.create", "POST", "/account/mfa/authenticators/totp", None, Setup::None, Reduced),
    account(
        "account.mfa.authenticator.update",
        "PUT",
        "/account/mfa/authenticators/totp",
        Some(r#"{"otp":"000000"}"#),
        Setup::None,
        Reduced,
    ),
    account(
        "account.mfa.authenticator.delete",
        "DELETE",
        "/account/mfa/authenticators/totp",
        None,
        Setup::None,
        Reduced,
    ),
    account("account.mfa.recovery-codes.create", "POST", "/account/mfa/recovery-codes", None, Setup::None, Reduced),
    account("account.mfa.recovery-codes.get", "GET", "/account/mfa/recovery-codes", None, Setup::None, Reduced),
    account("account.mfa.recovery-codes.update", "PATCH", "/account/mfa/recovery-codes", None, Setup::None, Reduced),
    account(
        "account.mfa.challenges.create",
        "POST",
        "/account/mfa/challenges",
        Some(r#"{"factor":"totp"}"#),
        Setup::None,
        Reduced,
    ),
    account(
        "account.mfa.challenges.email",
        "POST",
        "/account/mfa/challenges",
        Some(r#"{"factor":"email"}"#),
        Setup::None,
        Reduced,
    ),
    account(
        "account.mfa.challenges.update",
        "PUT",
        "/account/mfa/challenges",
        Some(r#"{"challengeId":"missing","otp":"123456"}"#),
        Setup::None,
        Reduced,
    ),
    // Passkeys.
    account("account.passkeys.list", "GET", "/account/passkeys", None, Setup::None, Reduced),
    account("account.passkeys.get", "GET", "/account/passkeys/missing", None, Setup::None, Reduced),
    account(
        "account.passkeys.update",
        "PATCH",
        "/account/passkeys/missing",
        Some(r#"{"name":"Key"}"#),
        Setup::None,
        Reduced,
    ),
    account("account.passkeys.delete", "DELETE", "/account/passkeys/missing", None, Setup::FreshSession, Reduced),
    account("account.passkeys.create", "POST", "/account/passkeys", None, Setup::FreshSession, Reduced),
    account("account.tokens.passkey.create", "POST", "/account/tokens/passkey", None, Setup::None, Reduced),
    account(
        "account.tokens.passkey.update",
        "PUT",
        "/account/tokens/passkey",
        Some(r#"{"challengeId":"missing","credential":{"id":"x"}}"#),
        Setup::None,
        Reduced,
    ),
    // OAuth2: the mock provider, and a disabled one.
    account("account.oauth2.disabled", "GET", "/account/sessions/oauth2/discord", None, Setup::None, Reduced),
    account(
        "account.oauth2.session",
        "GET",
        "/account/sessions/oauth2/mock?success=http://localhost/success&failure=http://localhost/failure&state=app",
        None,
        Setup::None,
        Reduced,
    ),
    account(
        "account.oauth2.token",
        "GET",
        "/account/tokens/oauth2/mock?success=http://localhost/success&failure=http://localhost/failure",
        None,
        Setup::None,
        Reduced,
    ),
    account(
        "account.oauth2.callback",
        "GET",
        "/account/sessions/oauth2/callback/mock/{project}?code=abcdef&state=%7B%7D",
        None,
        Setup::None,
        Reduced,
    ),
    account(
        "account.oauth2.redirect",
        "GET",
        "/account/sessions/oauth2/mock/redirect?project={project}&code=abcdef&state={oauth2-token-state}",
        None,
        Setup::None,
        Reduced,
    ),
    account(
        "account.oauth2.redirect.unbound",
        "GET",
        "/account/sessions/oauth2/mock/redirect?project={project}&code=abcdef&state=%7B%22success%22%3A%22http%3A%2F%2Flocalhost%2Fsuccess%22%2C%22failure%22%3A%22http%3A%2F%2Flocalhost%2Ffailure%22%7D",
        None,
        Setup::None,
        Reduced,
    ),
    // Account: client routes act as `u1` (or their own user, `ROUTE_USERS`).
    route("account.get", "GET", "/account", None),
    route("account.prefs.get", "GET", "/account/prefs", None),
    route("account.prefs.update", "PATCH", "/account/prefs", Some(r#"{"prefs":{"theme":"dark","n":{n}}}"#)),
    route("account.name.update", "PATCH", "/account/name", Some(r#"{"name":"Name {n}"}"#)),
    route("account.name.invalid", "PATCH", "/account/name", Some(r#"{"name":""}"#)),
    route(
        "account.password.update",
        "PATCH",
        "/account/password",
        Some(r#"{"password":"password-1234","oldPassword":"password-1234"}"#),
    ),
    route(
        "account.password.wrong",
        "PATCH",
        "/account/password",
        Some(r#"{"password":"password-5678","oldPassword":"not-the-password"}"#),
    ),
    route(
        "account.email.update",
        "PATCH",
        "/account/email",
        Some(r#"{"email":"U1-{n}@Example.com","password":"password-1234"}"#),
    ),
    route(
        "account.email.taken",
        "PATCH",
        "/account/email",
        Some(r#"{"email":"u2@example.com","password":"password-1234"}"#),
    ),
    route(
        "account.phone.update",
        "PATCH",
        "/account/phone",
        Some(r#"{"phone":"+1555200{n}","password":"password-1234"}"#),
    ),
    route("account.identities.list", "GET", "/account/identities", None),
    route("account.identities.delete.missing", "DELETE", "/account/identities/missing", None),
    route(
        "account.targets.push.create",
        "POST",
        "/account/targets/push",
        Some(r#"{"targetId":"t{n}","identifier":"push-{n}"}"#),
    ),
    route("account.targets.push.update", "PUT", "/account/targets/pt1/push", Some(r#"{"identifier":"push-pt1-{n}"}"#)),
    route("account.targets.push.delete", "DELETE", "/account/targets/pt2/push", None),
    route("account.targets.push.delete.missing", "DELETE", "/account/targets/missing/push", None),
    route(
        "account.recovery.create",
        "POST",
        "/account/recovery",
        Some(r#"{"email":"A6@example.com","url":"http://localhost/recover?from=mail"}"#),
    ),
    route(
        "account.recovery.unknown",
        "POST",
        "/account/recovery",
        Some(r#"{"email":"nobody@example.com","url":"http://localhost/recover"}"#),
    ),
    route(
        "account.recovery.redirect.invalid",
        "POST",
        "/account/recovery",
        Some(r#"{"email":"a6@example.com","url":"https://evil.example/recover"}"#),
    ),
    route(
        "account.recovery.update",
        "PUT",
        "/account/recovery",
        Some(r#"{"userId":"a6","secret":"{recovery-secret}","password":"password-5678"}"#),
    ),
    route("account.recovery.otp.create", "POST", "/account/recovery/otp", Some(r#"{"email":"a6@example.com"}"#)),
    route(
        "account.recovery.otp.update",
        "PUT",
        "/account/recovery/otp",
        Some(r#"{"userId":"a6","secret":"{recovery-otp}","password":"password-9012"}"#),
    ),
    route(
        "account.verifications.email.create",
        "POST",
        "/account/verifications/email",
        Some(r#"{"url":"http://localhost/verify"}"#),
    ),
    route(
        "account.verifications.email.update",
        "PUT",
        "/account/verifications/email",
        Some(r#"{"userId":"u1","secret":"not-the-secret"}"#),
    ),
    route("account.verifications.email.otp.create", "POST", "/account/verifications/email/otp", Some(r#"{}"#)),
    route(
        "account.verifications.email.otp.update",
        "PUT",
        "/account/verifications/email/otp",
        Some(r#"{"userId":"missing","secret":"000000"}"#),
    ),
    route("account.verifications.phone.create", "POST", "/account/verifications/phone", Some(r#"{}"#)),
    route(
        "account.verifications.phone.update",
        "PUT",
        "/account/verifications/phone",
        Some(r#"{"userId":"a7","secret":"123456"}"#),
    ),
    route("account.status.update", "PATCH", "/account/status", None),
    route("account.delete", "DELETE", "/account", None),
    // Teams. A client creating a team joins it as its owner.
    route("teams.create", "POST", "/teams", Some(r#"{"teamId":"t{n}","name":"Team {n}"}"#)),
    route(
        "teams.create.duplicate",
        "POST",
        "/teams",
        Some(r#"{"teamId":"t1","name":"Again","roles":["owner","lead"]}"#),
    ),
    route("teams.list", "GET", "/teams", None),
    route("teams.list.search", "GET", "/teams?search=Alpha&total=false", None),
    route("teams.list.invalid", "GET", "/teams?queries[]=bogus", None),
    route("teams.get", "GET", "/teams/t1", None),
    route("teams.get.missing", "GET", "/teams/missing", None),
    route("teams.name.update", "PUT", "/teams/t1", Some(r#"{"name":"Alpha {n}"}"#)),
    route("teams.prefs.get", "GET", "/teams/t1/prefs", None),
    route("teams.prefs.update", "PUT", "/teams/t1/prefs", Some(r#"{"prefs":{"n":"{n}"}}"#)),
    // Deletes its memberships too; the first identity allowed to wins.
    route("teams.delete", "DELETE", "/teams/t2", None),
    route("teams.delete.missing", "DELETE", "/teams/missing", None),
    // An invitation by email: keys add a new user at once, the owner's
    // session invites them (the email goes to the mails queue).
    route(
        "teams.memberships.create",
        "POST",
        "/teams/t1/memberships",
        Some(r#"{"email":"i{n}@example.com","roles":["member"],"url":"http://localhost","name":"Invitee {n}"}"#),
    ),
    route(
        "teams.memberships.create.user",
        "POST",
        "/teams/t1/memberships",
        Some(r#"{"userId":"u3","roles":["editor"],"url":"http://localhost"}"#),
    ),
    route(
        "teams.memberships.create.invalid",
        "POST",
        "/teams/t1/memberships",
        Some(r#"{"roles":["member"],"url":"https://foreign.example"}"#),
    ),
    route("teams.memberships.list", "GET", "/teams/t1/memberships", None),
    route("teams.memberships.get", "GET", "/teams/t1/memberships/m1", None),
    route("teams.memberships.get.missing", "GET", "/teams/t1/memberships/missing", None),
    route("teams.memberships.update", "PATCH", "/teams/t1/memberships/m2", Some(r#"{"roles":["member","n{n}"]}"#)),
    route(
        "teams.memberships.status",
        "PATCH",
        "/teams/t1/memberships/m3/status",
        Some(r#"{"userId":"u4","secret":"compat-invite-secret"}"#),
    ),
    route(
        "teams.memberships.status.invalid",
        "PATCH",
        "/teams/t1/memberships/m1/status",
        Some(r#"{"userId":"u1","secret":"wrong"}"#),
    ),
    route("teams.memberships.delete", "DELETE", "/teams/t1/memberships/m2", None),
    route("teams.memberships.delete.missing", "DELETE", "/teams/t1/memberships/missing", None),
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
    /// The CLI's `X-SDK-Name` (its onboarding install stage).
    Cli,
    /// The MCP server's `X-SDK-Name` (its onboarding install stage).
    Mcp,
    /// `X-Appwrite-Timeout` (the development database timeout).
    Timeout,
}

const VARIANTS: [Variant; 7] = [
    Variant::Preflight,
    Variant::OldFormat,
    Variant::NewFormat,
    Variant::Locale,
    Variant::Cli,
    Variant::Mcp,
    Variant::Timeout,
];

struct Scenario {
    id: String,
    n: usize,
    route: &'static Route,
    identity: Identity,
    origin: Origin,
    variant: Variant,
}

/// The identities and origins a route outside Users runs under in quick
/// mode (`COMPAT_DIFF_QUICK`, as CI runs it): the Users routes run every
/// identity, origin and variant, which covers the generic hooks; the other
/// services' routes need the ones that change what an action does.
const QUICK_IDENTITIES: [Identity; 6] =
    [Identity::Guest, Identity::Key, Identity::NarrowKey, Identity::Session, Identity::Jwt, Identity::InvalidJwt];
const QUICK_ORIGINS: [Origin; 2] = [Origin::None, Origin::Platform];
const QUICK_VARIANTS: [Variant; 2] = [Variant::OldFormat, Variant::Locale];

fn scenarios(filter: Option<&str>) -> Vec<Scenario> {
    let quick = std::env::var_os("COMPAT_DIFF_QUICK").is_some();
    let mut out = Vec::new();
    for route in ROUTES {
        let (identities, origins, rule_identities, variants): (&[Identity], &[Origin], &[Identity], &[Variant]) =
            match route.matrix {
                // CI's quick mode reduces every route outside Users further.
                _ if quick && !route.name.starts_with("users.") => {
                    (&QUICK_IDENTITIES, &QUICK_ORIGINS, &[], &QUICK_VARIANTS)
                }
                Matrix::Full => (&IDENTITIES, &ORIGINS, &RULE_IDENTITIES, &VARIANTS),
                Matrix::Reduced => (&REDUCED_IDENTITIES, &ORIGINS, &[], &REDUCED_VARIANTS),
            };
        for &identity in identities {
            for &origin in origins {
                let n = out.len();
                let id = format!("{} {identity:?} origin:{origin:?}", route.name).to_ascii_lowercase();
                if filter.is_some_and(|f| !id.contains(&f.to_ascii_lowercase())) {
                    continue;
                }
                out.push(Scenario { id, n, route, identity, origin, variant: Variant::Plain });
            }
        }
        for &identity in rule_identities {
            for origin in RULE_ORIGINS {
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
        for &variant in variants {
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

/// What a scenario's setup made for one twin.
#[derive(Default)]
struct Prepared {
    session: Option<String>,
    jwt: Option<String>,
    token_user: String,
    token_secret: String,
    id_token: String,
}

/// Runs a scenario's setup for `twin`, through PHP.
async fn prepare(api: &Api, stack: &Stack, scenario: &Scenario, twin: &Twin) -> Result<Prepared, String> {
    let headers = vec![
        ("x-appwrite-project".to_owned(), twin.project.clone()),
        ("x-appwrite-key".to_owned(), twin.seed_key.clone()),
    ];
    let post = |path: String, body: Value| {
        let headers = headers.clone();
        async move {
            let r = api.send("POST", &format!("{}{path}", stack.php), &headers, Some(&body)).await?;
            if r.status >= 300 {
                return Err(format!("{path}: {} {}", r.status, r.body));
            }
            Ok(r.body)
        }
    };
    let mut prepared = Prepared::default();
    // A Rust server run on the host names its cache keys after `localhost`
    // (the containers after `postgresql`): PHP's purges miss its entries, so
    // the setup's user is purged there too, as PHP purged it for itself.
    let purge_host_cache = |user: &'static str| {
        let key = format!("default-cache-localhost:{}::collection:users:{user}", twin.prefix.trim_end_matches('_'));
        let address = stack.redis.clone();
        async move {
            if twin.runtime != "rust" || std::env::var_os("COMPAT_DIFF_RUST_IPS").is_none() {
                return Ok::<(), String>(());
            }
            let client = redis::Client::open(format!("redis://{address}")).map_err(|e| e.to_string())?;
            let mut connection = client.get_multiplexed_async_connection().await.map_err(|e| e.to_string())?;
            let _: i64 = redis::cmd("DEL").arg(&key).query_async(&mut connection).await.map_err(|e| e.to_string())?;
            Ok(())
        }
    };
    match scenario.route.setup {
        Setup::None => {}
        Setup::FreshSession => {
            let session = post("/users/u3/sessions".into(), json!({})).await?;
            let id = session["$id"].as_str().unwrap_or_default().to_owned();
            prepared.session = session["secret"].as_str().map(str::to_owned);
            prepared.jwt =
                post("/users/u3/jwts".into(), json!({"sessionId": id})).await?["jwt"].as_str().map(str::to_owned);
        }
        Setup::Token => {
            let token = post("/users/u4/tokens".into(), json!({})).await?;
            prepared.token_user = token["userId"].as_str().unwrap_or_default().to_owned();
            prepared.token_secret = token["secret"].as_str().unwrap_or_default().to_owned();
        }
        Setup::IdToken => {
            let now = chrono::Utc::now().timestamp();
            let claims = json!({
                "iss": "https://localhost/v1/mock",
                "aud": "1",
                "sub": format!("native-{}", scenario.n),
                "email": format!("native{}@example.com", scenario.n),
                "email_verified": true,
                "name": "Native",
                "iat": now,
                "exp": now + 600,
            })
            .to_string();
            let url = format!(
                "{}/mock/tests/general/oauth2/id-token?claims={}",
                stack.php,
                php_std::encoding::rawurlencode(claims.as_bytes())
            );
            let r = api.send("GET", &url, &[], None).await?;
            prepared.id_token = r.body["token"].as_str().unwrap_or_default().to_owned();
        }
    }
    match scenario.route.setup {
        Setup::FreshSession => purge_host_cache("u3").await?,
        Setup::Token => purge_host_cache("u4").await?,
        _ => {}
    }
    Ok(prepared)
}

/// The request a scenario sends to `twin` (`other` is the other twin).
fn request(
    scenario: &Scenario,
    twin: &Twin,
    other: &Twin,
    console: &Console,
    prepared: &Prepared,
) -> (String, String, Vec<(String, String)>, Option<Value>) {
    let route = scenario.route;
    let n = scenario.n.to_string();
    let fill = |t: &str| {
        let t = t
            .replace("{n}", &n)
            .replace("{project}", &twin.project)
            .replace("{token-user}", &prepared.token_user)
            .replace("{token-secret}", &prepared.token_secret)
            .replace("{id-token}", &prepared.id_token)
            .replace("{oauth2-token-state}", OAUTH2_TOKEN_STATE);
        twin.vars.iter().fold(t, |t, (name, value)| t.replace(name.as_str(), value))
    };
    let path = fill(route.path);
    let body = route.body.map(|b| serde_json::from_str(&fill(b)).unwrap_or(Value::Null));
    let (session, jwt) = twin.credentials(route);
    let session = prepared.session.clone().unwrap_or_else(|| session.to_owned());
    let jwt = prepared.jwt.clone().unwrap_or_else(|| jwt.to_owned());
    let mut headers: Vec<(String, String)> = Vec::new();
    let keys = &twin.keys;
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
            headers.push(("x-appwrite-session".into(), session.clone()));
        }
        Identity::Jwt => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-jwt".into(), jwt.clone()));
        }
        Identity::InvalidJwt => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-jwt".into(), "eyJhbGciOiJIUzI1NiJ9.e30.invalid".into()));
        }
        Identity::NoProject => headers.push(("x-appwrite-key".into(), twin.key.clone())),
        Identity::EphemeralKey => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-key".into(), twin.ephemeral.clone()));
        }
        Identity::UnknownProject => {
            project(&mut headers, "unknown-project");
            headers.push(("x-appwrite-key".into(), twin.key.clone()));
        }
        Identity::AccountKey => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-key".into(), keys.account.clone()));
            headers.push(("x-appwrite-user".into(), keys.user.clone()));
        }
        Identity::AccountKeyNoUser => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-key".into(), keys.account.clone()));
        }
        Identity::ExpiredAccountKey => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-key".into(), keys.expired_account.clone()));
            headers.push(("x-appwrite-user".into(), keys.user.clone()));
        }
        Identity::AccountKeySession => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-key".into(), keys.account.clone()));
            headers.push(("x-appwrite-user".into(), keys.user.clone()));
            headers.push(("x-appwrite-session".into(), session.clone()));
        }
        Identity::OrganizationKey => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-key".into(), keys.organization.clone()));
            headers.push(("x-appwrite-organization".into(), console.team.clone()));
        }
        Identity::OrganizationKeyNoTeam => {
            project(&mut headers, &twin.project);
            headers.push(("x-appwrite-key".into(), keys.organization.clone()));
        }
    }
    match scenario.origin {
        Origin::None => {}
        Origin::Platform => headers.push(("origin".into(), "http://localhost".into())),
        Origin::Foreign => headers.push(("origin".into(), "https://foreign.example".into())),
        Origin::Rule => headers.push(("origin".into(), format!("https://{}", rule_domain(&twin.project)))),
        Origin::OtherRule => headers.push(("origin".into(), format!("https://{}", rule_domain(&other.project)))),
    }
    // A Rust server run on the host sees the client as localhost: it is
    // told the address the containers see (`COMPAT_DIFF_RUST_FORWARDED_FOR`).
    // Both twins get it, so the requests (and their counted size) match.
    if let Ok(ip) = std::env::var("COMPAT_DIFF_RUST_FORWARDED_FOR") {
        headers.push(("x-forwarded-for".into(), ip));
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
        Variant::Cli => headers.push(("x-sdk-name".into(), "Command Line".into())),
        Variant::Mcp => headers.push(("x-sdk-name".into(), "mcp".into())),
        Variant::Timeout => headers.push(("x-appwrite-timeout".into(), "5000".into())),
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
        utopia_async::spawn(async move {
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
        let deadline = std::time::Instant::now() + Duration::from_secs(10);
        loop {
            let next = utopia_async::time::timeout_at(deadline, self.lines.recv()).await;
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
        // A collection's cache owners are random tokens (`<time>.<hex>`),
        // released when the write that registered them is done.
        "HDEL" if args.get(1).is_some_and(|k| k.ends_with("#owners")) => {
            Some(json!({"purge": args.get(1), "fields": args[2..].iter().map(|_| "{owner}").collect::<Vec<_>>()}))
        }
        "HDEL" => Some(json!({"purge": args.get(1), "fields": &args[2..]})),
        _ => None,
    }
}

/// The project's tables, as rows of text keyed by column (so a column's
/// value is normalized by its name, as a document attribute is).
async fn tables(db: &tokio_postgres::Client, prefix: &str) -> Result<Rows, String> {
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
        for message in db
            .simple_query(&format!("SELECT * FROM appwrite.\"{name}\" ORDER BY 1"))
            .await
            .map_err(|e| format!("{name}: {e}"))?
        {
            if let tokio_postgres::SimpleQueryMessage::Row(row) = message {
                let columns = row.columns();
                // A permission row's `_id` is a counter no API shows: it
                // drifts between the twins with every permission written.
                let perms = name.ends_with("_perms");
                rows.push(Value::Object(
                    (0..row.len())
                        .filter(|&i| !(perms && columns[i].name() == "_id"))
                        .map(|i| (columns[i].name().to_owned(), row.get(i).map_or(Value::Null, |v| json!(v))))
                        .collect(),
                ));
            }
        }
        out.insert(name[prefix.len()..].to_owned(), rows);
    }
    Ok(out)
}

type Rows = BTreeMap<String, Vec<Value>>;

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
    /// The twin's console account's sequence.
    account_sequence: String,
    /// The twin's console account's ID (its documents name it).
    account_user: String,
}

impl Normalizer {
    fn new(twin: &Twin, other: &Twin) -> Normalizer {
        let mut fixed = vec![
            (twin.key.clone(), "{key}".to_owned()),
            (twin.narrow_key.clone(), "{narrow-key}".to_owned()),
            (twin.seed_key.clone(), "{seed-key}".to_owned()),
            (other.key.clone(), "{other-key}".to_owned()),
            (twin.ephemeral.clone(), "{ephemeral-key}".to_owned()),
            (other.project.clone(), "{other-project}".to_owned()),
            (twin.session.clone(), "{session}".to_owned()),
            (twin.jwt.clone(), "{jwt}".to_owned()),
        ];
        for (user, session, jwt) in &twin.accounts {
            fixed.push((session.clone(), format!("{{session-{user}}}")));
            fixed.push((jwt.clone(), format!("{{jwt-{user}}}")));
        }
        fixed.extend([
            (twin.project.clone(), "{project}".to_owned()),
            (twin.prefix.clone(), "_{seq}_".to_owned()),
            (twin.prefix.trim_end_matches('_').to_owned(), "_{seq}".to_owned()),
            (format!("platform:{}:", twin.prefix.trim_matches('_')), "platform:{seq}:".to_owned()),
            (format!("project:{}:", twin.prefix.trim_matches('_')), "project:{seq}:".to_owned()),
            // Shared tables: the tenant in cache keys (`<cache>::<tenant>:collection:...`).
            (format!("::{}:", twin.prefix.trim_matches('_')), "::{seq}:".to_owned()),
        ]);
        // The account's and the organization's keys, by ID, and the locks
        // named after them and their owner's sequence (which may equal the
        // project's on one twin and not the other).
        let names = ["{account-key-id}", "{expired-account-key-id}", "{organization-key-id}"];
        for (i, (id, name)) in twin.keys.ids.iter().zip(names).enumerate() {
            let (owner, placeholder) = if i < 2 {
                (&twin.keys.user_sequence, "{account-seq}")
            } else {
                (&twin.keys.team_sequence, "{team-seq}")
            };
            fixed.push((format!("platform:{owner}:keys:{id}"), format!("platform:{placeholder}:keys:{name}")));
            fixed.push((id.clone(), name.to_owned()));
        }
        // The API's own host and port (callback and redirect URLs).
        if let Some(authority) = twin.endpoint.split("://").nth(1).and_then(|r| r.split('/').next()) {
            fixed.push((authority.to_owned(), "{api-host}".to_owned()));
        }
        // A Rust server run on the host reaches PostgreSQL as `localhost`,
        // which names its cache keys; the containers name it `postgresql`.
        if twin.runtime == "rust" && std::env::var_os("COMPAT_DIFF_RUST_IPS").is_some() {
            fixed.push(("-cache-localhost:".to_owned(), "-cache-postgresql:".to_owned()));
        }
        fixed.retain(|(from, _)| !from.is_empty());
        // Longest first, so a secret is replaced before a project ID it may contain.
        fixed.sort_by_key(|(from, _)| std::cmp::Reverse(from.len()));
        Normalizer {
            fixed,
            generated: HashMap::new(),
            sequence: twin.prefix.trim_matches('_').to_owned(),
            account_sequence: twin.keys.user_sequence.clone(),
            account_user: twin.keys.user.clone(),
        }
    }

    /// A URL with a query (redirects, links in messages): each parameter
    /// decoded and normalized (JSON values as JSON).
    fn url_shape(&mut self, s: &str) -> Option<String> {
        if !(s.contains("://") || s.starts_with("appwrite-callback-")) || s.contains(' ') {
            return None;
        }
        let (base, query) = s.split_once('?')?;
        let mut parts = Vec::new();
        for pair in query.split('&').filter(|p| !p.is_empty()) {
            let (k, v) = pair.split_once('=').unwrap_or((pair, ""));
            let decode = |t: &str| String::from_utf8_lossy(&php_std::encoding::urldecode(t.as_bytes())).into_owned();
            let (k, v) = (decode(k), decode(v));
            let value = match serde_json::from_str::<Value>(&v) {
                Ok(json @ (Value::Object(_) | Value::Array(_))) => self.value(&json).to_string(),
                _ if SECRET_KEYS.contains(&k.as_str()) && !v.is_empty() => {
                    // A known value becomes a whole placeholder; a random one
                    // that only contains, say, a sequence is still a secret.
                    let text = self.text(&v);
                    if whole_placeholder(&text) { text } else { self.number(v, "secret") }
                }
                _ => self.text(&v),
            };
            parts.push(format!("{k}={value}"));
        }
        Some(format!("{{url {}?{}}}", self.text(base), parts.join("&")))
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
        if let Some((keys, id, length)) = session_shape(&out) {
            // A user created by the request has a generated ID.
            let id = self.text(&id);
            return format!("{{session keys={keys} id={id} secret-length={length}}}");
        }
        if let Some(shape) = jwt_shape(&out) {
            return shape;
        }
        if let Some(shape) = self.url_shape(&out) {
            return shape;
        }
        // A token link's expiry (`expire=<urlencoded datetime>`) is a time.
        if out.contains("expire=") {
            out = query_datetime(&out);
        }
        // A session store inside a longer string (a cookie, the
        // `X-Fallback-Cookies` JSON), and a cookie's expiry time.
        if out.contains("eyJ") {
            out = embedded_sessions(&out);
        }
        // A token in a link (an invitation's `secret=`): random, of a fixed length.
        if let Some(at) = out.find("secret=") {
            let start = at + "secret=".len();
            let run = out[start..].bytes().take_while(u8::is_ascii_hexdigit).count();
            if run >= 32 {
                out.replace_range(start..start + run, &format!("{{token length={run}}}"));
            }
        }
        if let Some(at) = out.find("; expires=")
            && let Some(end) = out[at + 2..].find(';')
        {
            out.replace_range(at + 2..at + 2 + end, "expires={time}");
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
                if let Some(kind @ ("id" | "secret")) = generated(token) {
                    result.push_str(&self.number(token.to_owned(), kind));
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
                // platforms, created once per twin) have twin sequences, as
                // do the organization's keys and the twin's console account's
                // documents (its keys, sessions and targets).
                // The account's documents name it (`userId`, or `resourceId`
                // for its keys): a project user's sequence can equal its own.
                let owned = ["userId", "resourceId"]
                    .iter()
                    .any(|k| o.get(*k).and_then(Value::as_str) == Some(self.account_user.as_str()));
                let project = owned
                    || o.get("$id").and_then(Value::as_str).is_some_and(|id| {
                        let id = self.text(id);
                        id.contains("{project}") || id.ends_with("key-id}")
                    });
                Value::Object(
                    o.iter()
                        .map(|(k, x)| {
                            let x = match (k.as_str(), x) {
                                ("$sequence", _) if project => Value::String("{seq}".into()),
                                // Any other row's sequence: with shared tables the
                                // twins' rows share one counter, so they interleave.
                                // Numbered per collection: a user and a target may
                                // share a sequence in one twin and not the other.
                                ("$sequence", Value::String(v)) => {
                                    let collection = o.get("$collection").and_then(Value::as_str).unwrap_or("");
                                    Value::String(self.number(format!("{collection}:{v}"), "sequence"))
                                }
                                (k, Value::String(v))
                                    if k.starts_with("project") && k.ends_with("InternalId") && *v == self.sequence =>
                                {
                                    Value::String("{seq}".into())
                                }
                                ("resourceInternalId", Value::String(v)) if *v == self.sequence => {
                                    Value::String("{seq}".into())
                                }
                                // The twin's console account (its keys and sessions).
                                ("resourceInternalId" | "userInternalId", Value::String(v))
                                    if owned && *v == self.account_sequence =>
                                {
                                    Value::String("{account-seq}".into())
                                }
                                // Shared tables: a row's tenant is the twin's project.
                                ("$tenant", Value::String(v)) if *v == self.sequence => Value::String("{seq}".into()),
                                ("$tenant", Value::Number(v)) if v.to_string() == self.sequence => {
                                    Value::String("{seq}".into())
                                }
                                // Any other row's sequence named by a reference (a
                                // session's `userInternalId`): numbered as the
                                // `$sequence` of the collection it names (`users`).
                                (k, Value::String(v))
                                    if k.ends_with("InternalId")
                                        && !v.is_empty()
                                        && v.bytes().all(|b| b.is_ascii_digit()) =>
                                {
                                    let collection = format!("{}s", k.trim_end_matches("InternalId"));
                                    Value::String(self.number(format!("{collection}:{v}"), "sequence"))
                                }
                                ("timestamp", Value::Number(_)) => Value::String("{timestamp}".into()),
                                (k, Value::String(code)) if one_time_code(k, x) => {
                                    Value::String(self.number(code.clone(), "otp"))
                                }
                                (k, Value::String(hash)) if secret_hash(k, x) => {
                                    Value::String(self.number(hash.clone(), "secret-hash"))
                                }
                                // Generated secrets: OTPs, codes, token secrets, phrases.
                                (k, Value::String(v)) if SECRET_KEYS.contains(&k) && !v.is_empty() => {
                                    let text = self.text(v);
                                    if whole_placeholder(&text) {
                                        Value::String(text)
                                    } else {
                                        Value::String(self.number(v.clone(), "secret"))
                                    }
                                }
                                ("recoveryCodes", Value::Array(codes)) => Value::Array(
                                    codes
                                        .iter()
                                        .map(|c| {
                                            Value::String(self.number(c.as_str().unwrap_or("").to_owned(), "code"))
                                        })
                                        .collect(),
                                ),
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
/// A session secret (base64 of `{"id": <user>, "secret": <random hex>}`):
/// its user and the secret's length, which are not random.
fn session_shape(s: &str) -> Option<(String, String, usize)> {
    if !s.starts_with("eyJ") {
        return None;
    }
    let decoded = php_std::encoding::base64_decode(s.as_bytes(), true)?;
    let v: Value = serde_json::from_slice(&decoded).ok()?;
    let o = v.as_object()?;
    let (id, secret) = (o.get("id")?.as_str()?, o.get("secret")?.as_str()?);
    let keys: Vec<&str> = o.keys().map(String::as_str).collect();
    Some((keys.join(","), id.to_owned(), secret.len()))
}

/// The fields whose string values are generated secrets (OTPs, codes,
/// token secrets, security phrases, TOTP secrets): numbered like IDs.
const SECRET_KEYS: &[&str] = &["otp", "code", "content", "secret", "phrase", "challenge"];

/// Whether a normalized value is one placeholder or shape as a whole
/// (`{recovery-secret}`, `{url ...}`), not random text that merely contains
/// one.
fn whole_placeholder(text: &str) -> bool {
    text.starts_with('{') && text.ends_with('}')
}

/// A JWT (`header.payload.signature`): its header and its claims' keys,
/// which are not random.
fn jwt_shape(s: &str) -> Option<String> {
    if !s.starts_with("eyJ") {
        return None;
    }
    let parts: Vec<&str> = s.split('.').collect();
    let [header, payload, _signature] = parts.as_slice() else { return None };
    use base64::Engine;
    let decode = |p: &str| {
        base64::engine::general_purpose::URL_SAFE_NO_PAD
            .decode(p.trim_end_matches('='))
            .ok()
            .and_then(|b| serde_json::from_slice::<Value>(&b).ok())
    };
    let (header, payload) = (decode(header)?, decode(payload)?);
    let keys: Vec<&str> = payload.as_object()?.keys().map(String::as_str).collect();
    Some(format!("{{jwt header={header} claims={}}}", keys.join(",")))
}

/// `s` with each session store it contains (a run of base64 starting with
/// `eyJ`) replaced by its [`session_shape`].
fn embedded_sessions(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    let mut rest = s;
    while let Some(at) = rest.find("eyJ") {
        out.push_str(&rest[..at]);
        let run = rest[at..]
            .bytes()
            .take_while(|b| b.is_ascii_alphanumeric() || matches!(b, b'+' | b'/' | b'=' | b'%'))
            .count();
        let token = &rest[at..at + run];
        let decoded = php_std::encoding::rawurldecode(token.as_bytes());
        match session_shape(&String::from_utf8_lossy(&decoded)) {
            Some((keys, id, length)) => {
                out.push_str(&format!("{{session keys={keys} id={id} secret-length={length}}}"))
            }
            None => out.push_str(token),
        }
        rest = &rest[at + run..];
    }
    out.push_str(rest);
    out
}

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

/// `expire=<value>` query parameters whose value is an urlencoded datetime
/// (`2026-10-14T22%3A...`), as `expire={datetime}`.
fn query_datetime(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    let mut rest = s;
    while let Some(at) = rest.find("expire=") {
        let (head, tail) = rest.split_at(at + "expire=".len());
        out.push_str(head);
        let end = tail.find(['&', '#', '"', ' ']).unwrap_or(tail.len());
        let value = &tail[..end];
        let datetime = value.len() >= 10 && value[..4].bytes().all(|b| b.is_ascii_digit()) && &value[4..5] == "-";
        out.push_str(if datetime { "{datetime}" } else { value });
        rest = &tail[end..];
    }
    out.push_str(rest);
    out
}

/// One-time codes (`proofForCode->generate()`: six digits) under the keys
/// that carry them: a token's `secret`, a mail's `otp` and an SMS's `content`.
fn one_time_code(key: &str, value: &Value) -> bool {
    matches!(key, "secret" | "otp" | "content")
        && value.as_str().is_some_and(|s| s.len() == 6 && s.bytes().all(|b| b.is_ascii_digit()))
}

/// A stored session or token secret (`proofForToken->hash()`: SHA-256 hex
/// of a random secret), under `secret` (the deletes worker's user document).
fn secret_hash(key: &str, value: &Value) -> bool {
    key == "secret"
        && value
            .as_str()
            .is_some_and(|s| s.len() == 64 && s.bytes().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b)))
}

/// What kind of generated value `s` is, if it is one: an ID from
/// `ID::unique()` (20 hexadecimal characters), a `uniqid('', true)`, or a
/// datetime.
fn generated(s: &str) -> Option<&'static str> {
    if s.len() == 20 && s.bytes().all(|b| b.is_ascii_hexdigit()) {
        return Some("id");
    }
    // A token secret (`proofForToken->generate()`: 256 lowercase hex).
    if s.len() == 256 && s.bytes().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b)) {
        return Some("secret");
    }
    // OAuth2 nonces (32), token secrets (64), TOTP secrets (base32 of 64 bytes).
    if (s.len() == 32 || s.len() == 64) && s.bytes().all(|b| b.is_ascii_hexdigit()) {
        return Some("hex");
    }
    if s.len() == 103 && s.bytes().all(|b| b.is_ascii_uppercase() || (b'2'..=b'7').contains(&b)) {
        return Some("totp");
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

/// The placeholders of a twin's own names: a side effect that names none of
/// them is another run's.
const OWN_PLACEHOLDERS: &[&str] = &[
    "{project}",
    "_{seq}_",
    "::{seq}:",
    "project:{seq}:",
    "platform:{seq}:",
    "{account-seq}",
    "{team-seq}",
    "key-id}",
    "{other-project}",
];

/// Whether a normalized text still names a twin project (a run stamp of ten
/// digits then `p` or `r`): the twins of this run are placeholders by then,
/// so it is another run's.
fn names_another_twin(text: &str) -> bool {
    let bytes = text.as_bytes();
    bytes.windows(12).enumerate().any(|(i, w)| {
        w[..10].iter().all(u8::is_ascii_digit)
            && matches!(w[10], b'p' | b'r')
            && !w[11].is_ascii_alphanumeric()
            && (i == 0 || !bytes[i - 1].is_ascii_digit())
    })
}

/// One runtime's record of a scenario.
#[allow(clippy::too_many_arguments)]
async fn record(
    api: &Api,
    db: &tokio_postgres::Client,
    monitor: &mut Monitor,
    scenario: &Scenario,
    twin: &Twin,
    other: &Twin,
    console: &Console,
    stack: &Stack,
) -> Result<Value, String> {
    let prepared = prepare(api, stack, scenario, twin).await?;
    let before = tables(db, &twin.prefix).await?;
    monitor.until_marker(&format!("{}:{}:start", scenario.n, twin.runtime)).await?;
    let (method, url, headers, body) = request(scenario, twin, other, console, &prepared);
    let reply = api.send(&method, &url, &headers, body.as_ref()).await?;
    // Work after the response (Rust's shutdown jobs) lands within this.
    utopia_async::sleep(Duration::from_millis(150)).await;
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
            if e.get("purge").is_some() {
                // Likewise a purge of another project's cache (its tables'
                // `_<sequence>` namespace).
                if !other_namespace(&e.to_string(), twin.prefix.trim_matches('_')) {
                    purges.push(e)
                }
            } else if e.to_string().contains(&twin.project) {
                // Every message names its project: one that does not name
                // the twin's is another run's on the same stack (a run
                // sharing the runtime's address).
                effects.push(e)
            }
        }
    }
    let headers: Vec<Value> = reply
        .headers
        .iter()
        .filter(|(n, _)| !MASKED_HEADERS.iter().any(|(m, _)| m == n))
        .map(|(n, v)| match n.as_str() {
            "set-cookie" => json!([n, cookie_shape(v)]),
            "x-fallback-cookies" => json!([n, serde_json::from_str::<Value>(v).unwrap_or(Value::String(v.clone()))]),
            _ => json!([n, v]),
        })
        .collect();
    let mut body = reply.body;
    // The HTML error page's trace (development only) lists the runtime's frames.
    if let Value::String(html) = &mut body
        && let Some(start) = html.find("Error trace</div>").map(|i| i + "Error trace</div>".len())
        && let Some(end) = html.rfind("</div> </div> <script>")
        && end >= start
    {
        html.replace_range(start..end, "{trace}");
    }
    if let Value::Object(fields) = &mut body
        && fields.contains_key("trace")
    {
        for (field, _) in MASKED_ERROR_FIELDS {
            if let Some(v) = fields.get_mut(*field) {
                *v = Value::String("{development}".into());
            }
        }
    }
    // Several runs can share the stack: the PHP container also serves other
    // twins' requests while this one runs. What this request did names its
    // twin (its project, tables or keys); the rest is another run's, and is
    // left out before generated values are numbered.
    let own = |item: &Value| {
        let text = Normalizer::new(twin, other).value(item).to_string();
        OWN_PLACEHOLDERS.iter().any(|p| text.contains(p)) && !names_another_twin(&text)
    };
    effects.retain(own);
    purges.retain(own);
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

/// A `Set-Cookie` header as its parts: the name, the value (decoded; a
/// comma list split), and the attributes with the expiry masked and the
/// max age rounded to ten seconds.
fn cookie_shape(header: &str) -> Value {
    let mut parts = header.split("; ");
    let (name, value) = parts.next().unwrap_or("").split_once('=').unwrap_or(("", ""));
    let value = String::from_utf8_lossy(&php_std::encoding::urldecode(value.as_bytes())).into_owned();
    let values: Vec<Value> = value.split(',').map(|v| Value::String(v.to_owned())).collect();
    let attributes: Vec<Value> = parts
        .map(|a| match a.split_once('=') {
            Some(("expires", d)) if d != "Thu, 01-Jan-1970 00:00:01 GMT" => Value::String("expires={date}".into()),
            Some(("Max-Age", n)) => {
                let n: i64 = n.parse().unwrap_or(-1);
                Value::String(format!("Max-Age~{}", if n > 0 { (n + 5) / 10 * 10 } else { n }))
            }
            _ => Value::String(a.to_owned()),
        })
        .collect();
    json!({"cookie": name, "value": values, "attributes": attributes})
}

/// Whether `s` names a project namespace (`_<sequence>` followed by `_` or
/// `:`) other than `sequence`'s.
fn other_namespace(s: &str, sequence: &str) -> bool {
    let bytes = s.as_bytes();
    let mut i = 0;
    while let Some(at) = s[i..].find('_') {
        let start = i + at + 1;
        let digits = bytes[start..].iter().take_while(|b| b.is_ascii_digit()).count();
        let end = start + digits;
        if digits > 0
            && (start < 2 || !bytes[start - 2].is_ascii_alphanumeric())
            && matches!(bytes.get(end), Some(b'_' | b':'))
            && &s[start..end] != sequence
        {
            return true;
        }
        i = start;
    }
    false
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
    let runtime = utopia_async::Builder::current_thread().build().map_err(|e| e.to_string())?;
    runtime.block_on(async move {
        let api = Api::new();
        let (db, connection) =
            tokio_postgres::connect(&stack.postgres, tokio_postgres::NoTls).await.map_err(|e| format!("postgres: {e}"))?;
        utopia_async::spawn(connection);
        let console = console(&api, &stack).await?;
        // Twins an interrupted run left behind.
        let query = json!({"method": "startsWith", "attribute": "$id", "values": [prefix()]}).to_string();
        let mut url = utopia_client::Uri::parse(&format!("{}/projects", stack.php)).map_err(|e| e.to_string())?;
        url = url.with_query(&format!("queries[]={}", php_std::encoding::rawurlencode(query.as_bytes())));
        let leftovers = api.send("GET", &url.to_string(), &console.headers, None).await?;
        for project in leftovers.body["projects"].as_array().into_iter().flatten() {
            if let Some(id) = project["$id"].as_str() {
                api.send("DELETE", &format!("{}/projects/{id}", stack.php), &console.headers, None).await?;
            }
        }
        fixtures(&json!({"prune": true}))?;
        let run = chrono::Utc::now().format("%m%d%H%M%S").to_string();
        let php = twin(&api, &stack, &console, &db, "php", &run).await?;
        let rust = twin(&api, &stack, &console, &db, "rust", &run).await?;
        eprintln!("compat appwrite diff: projects {} (PHP) and {} (Rust)", php.project, rust.project);
        let mut monitor = Monitor::start(&stack.redis).await?;

        let list = scenarios(filter);
        let mut differences = Vec::new();
        let mut passed = 0;
        for scenario in &list {
            let a = record(&api, &db, &mut monitor, scenario, &php, &rust, &console, &stack).await?;
            let b = record(&api, &db, &mut monitor, scenario, &rust, &php, &console, &stack).await?;
            let compared = |r: &Value| {
                let mut r = r.clone();
                if let Some(o) = r.as_object_mut() {
                    o.remove("redis");
                }
                r
            };
            // `COMPAT_DIFF_SHOW=<text>`: print both records of the scenarios
            // whose id contains it, to read what was compared.
            if std::env::var("COMPAT_DIFF_SHOW").is_ok_and(|s| scenario.id.contains(&s)) {
                eprintln!("SHOW php  {}: {}", scenario.id, compared(&a));
                eprintln!("SHOW rust {}: {}", scenario.id, compared(&b));
            }
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
        // `COMPAT_DIFF_KEEP` keeps the twins, to look into a difference.
        if std::env::var_os("COMPAT_DIFF_KEEP").is_none() {
            for twin in [&php, &rust] {
                api.send("DELETE", &format!("{}/projects/{}", stack.php, twin.project), &console.headers, None).await?;
            }
        } else {
            eprintln!(
                "compat appwrite diff: kept {} and {}; seed key {} / {}",
                php.project, rust.project, php.seed_key, rust.seed_key
            );
        }
        fixtures(&json!({"prune": true}))?;
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
    fn embedded_session_stores_keep_their_shape() {
        // base64 of {"id":"u4","secret":"abcd"}
        let cookie = "a_session_p=eyJpZCI6InU0Iiwic2VjcmV0IjoiYWJjZCJ9; path=/";
        assert_eq!(embedded_sessions(cookie), "a_session_p={session keys=id,secret id=u4 secret-length=4}; path=/");
        assert_eq!(embedded_sessions("eyJnope"), "eyJnope");
    }

    #[test]
    fn other_projects_namespaces_are_told_apart() {
        assert!(other_namespace("default-cache-postgresql:_208::collection:users", "207"));
        assert!(!other_namespace("default-cache-postgresql:_207::collection:users", "207"));
        assert!(!other_namespace("platform:console:keys:a_1", "207"));
        assert!(other_namespace("appwrite._208_memberships", "207"));
    }

    #[test]
    fn generated_values_are_numbered_by_appearance() {
        assert_eq!(generated("6527a1b2c3d4e5f6a7b8"), Some("id"));
        assert_eq!(generated("2026-10-14T00:15:57.614+00:00"), Some("datetime"));
        assert_eq!(generated("u1"), None);
    }
}
