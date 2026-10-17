//! Compat adapter for `vcs`: maps `tests/compat/vcs/spec.json` operations onto `utopia-vcs`.
//!
//! The twin of `tests/compat/vcs/Adapter.php`: `vcs.new` builds an adapter
//! (a handle), `vcs.<method>` calls the method of that name with the case's
//! named arguments (PHP's names, PHP's defaults) and reports its result or
//! `{"$error": {class, message, code}}`; `${vcs}` and `${vcs-tls}` in
//! arguments are the fixture server's bases for this runtime (`${vcs-host}`
//! and `${vcs-tls-host}` without the scheme), and go back into results in
//! their place. `adapter` is a handle, or a provider's name for a fresh
//! adapter (fuzzing); a `payload` that is not a string is its json_encode().

use std::hint::black_box;
use std::sync::{Arc, Once};

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use php_std::types::KeyRef;
use php_std::{Array, ArrayKey, Str, Value as Php};
use serde_json::{Map, Value, json};
use utopia_cache::{Cache, Memory, None as NoCache, Redis};
use utopia_console::Command;
use utopia_vcs::{
    Adapter, BatchCheckRun, Bitbucket, CheckRun, Error, Flavor, Git, GitHub, GitLab, Gitea, Origin, WebhookId,
};

use crate::adapter::{Fault, OpResult, Outcome, Session, bytes_value, float_value};
use crate::bench::Bench;

/// The adapter methods, as `vcs.<snake_case>` operations.
const METHODS: &[&str] = &[
    "batchGetRepositoryContents",
    "batchUpsertCheckRuns",
    "compareCommits",
    "createBranch",
    "createCheckRun",
    "createComment",
    "createFile",
    "createOrganization",
    "createPullRequest",
    "createPullRequestReview",
    "createRepository",
    "createTag",
    "createWebhook",
    "deleteInstallation",
    "deleteRepository",
    "dismissPullRequestReview",
    "generateCloneCommand",
    "getAuthenticatedApp",
    "getBlob",
    "getBranchUrl",
    "getCheckRun",
    "getCheckSuite",
    "getComment",
    "getCommit",
    "getCommitStatuses",
    "getCommitUrl",
    "getEventHeaderName",
    "getEvents",
    "getFileUrl",
    "getInstallUrl",
    "getInstallationRepository",
    "getLatestCommit",
    "getName",
    "getOrganizationUrl",
    "getOwnerName",
    "getPullRequest",
    "getPullRequestFiles",
    "getPullRequestFromBranch",
    "getRateLimit",
    "getRepository",
    "getRepositoryCloneHeaders",
    "getRepositoryCloneUrl",
    "getRepositoryContent",
    "getRepositoryName",
    "getRepositoryPresignedUrl",
    "getRepositoryPresignedUrlHeaders",
    "getRepositoryTree",
    "getRepositoryUrl",
    "getSignatureHeaderName",
    "getSigningKeys",
    "getSupportedWebhookScopes",
    "getType",
    "getUser",
    "hasAccessToAllRepositories",
    "initializeVariables",
    "listBranches",
    "listCheckRunsForCommit",
    "listCheckRunsForSuite",
    "listCommitFiles",
    "listCommits",
    "listInstallations",
    "listNamespaces",
    "listPullRequestComments",
    "listPullRequestCommits",
    "listPullRequestReviews",
    "listPullRequests",
    "listRepositoryContents",
    "listRepositoryLanguages",
    "listTags",
    "listWebhookDeliveries",
    "mergePullRequest",
    "redeliverWebhookDeliveries",
    "searchRepositories",
    "setEndpoint",
    "supportsCommentImages",
    "supportsPublicRepositories",
    "supportsRepositoryArchives",
    "supportsRepositoryCreation",
    "supportsRepositoryDeletion",
    "syncMirror",
    "updateCheckRun",
    "updateComment",
    "updateCommitStatus",
    "updatePullRequest",
    "updatePullRequestReview",
    "validateWebhookEvent",
    "verifyReceipt",
];

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "vcs.new",
    "fixture.requests",
    "fixture.script",
    "fixture.config",
    "fixture.caught",
    "clone.setup",
    "clone.tag",
    "clone.ref",
    "clone.command",
    "clone.run",
    "vcs.batch_get_repository_contents",
    "vcs.batch_upsert_check_runs",
    "vcs.compare_commits",
    "vcs.create_branch",
    "vcs.create_check_run",
    "vcs.create_comment",
    "vcs.create_file",
    "vcs.create_organization",
    "vcs.create_pull_request",
    "vcs.create_pull_request_review",
    "vcs.create_repository",
    "vcs.create_tag",
    "vcs.create_webhook",
    "vcs.delete_installation",
    "vcs.delete_repository",
    "vcs.dismiss_pull_request_review",
    "vcs.generate_clone_command",
    "vcs.get_authenticated_app",
    "vcs.get_blob",
    "vcs.get_branch_url",
    "vcs.get_check_run",
    "vcs.get_check_suite",
    "vcs.get_comment",
    "vcs.get_commit",
    "vcs.get_commit_statuses",
    "vcs.get_commit_url",
    "vcs.get_event_header_name",
    "vcs.get_events",
    "vcs.get_file_url",
    "vcs.get_install_url",
    "vcs.get_installation_repository",
    "vcs.get_latest_commit",
    "vcs.get_name",
    "vcs.get_organization_url",
    "vcs.get_owner_name",
    "vcs.get_pull_request",
    "vcs.get_pull_request_files",
    "vcs.get_pull_request_from_branch",
    "vcs.get_rate_limit",
    "vcs.get_repository",
    "vcs.get_repository_clone_headers",
    "vcs.get_repository_clone_url",
    "vcs.get_repository_content",
    "vcs.get_repository_name",
    "vcs.get_repository_presigned_url",
    "vcs.get_repository_presigned_url_headers",
    "vcs.get_repository_tree",
    "vcs.get_repository_url",
    "vcs.get_signature_header_name",
    "vcs.get_signing_keys",
    "vcs.get_supported_webhook_scopes",
    "vcs.get_type",
    "vcs.get_user",
    "vcs.has_access_to_all_repositories",
    "vcs.initialize_variables",
    "vcs.list_branches",
    "vcs.list_check_runs_for_commit",
    "vcs.list_check_runs_for_suite",
    "vcs.list_commit_files",
    "vcs.list_commits",
    "vcs.list_installations",
    "vcs.list_namespaces",
    "vcs.list_pull_request_comments",
    "vcs.list_pull_request_commits",
    "vcs.list_pull_request_reviews",
    "vcs.list_pull_requests",
    "vcs.list_repository_contents",
    "vcs.list_repository_languages",
    "vcs.list_tags",
    "vcs.list_webhook_deliveries",
    "vcs.merge_pull_request",
    "vcs.redeliver_webhook_deliveries",
    "vcs.search_repositories",
    "vcs.set_endpoint",
    "vcs.supports_comment_images",
    "vcs.supports_public_repositories",
    "vcs.supports_repository_archives",
    "vcs.supports_repository_creation",
    "vcs.supports_repository_deletion",
    "vcs.sync_mirror",
    "vcs.update_check_run",
    "vcs.update_comment",
    "vcs.update_commit_status",
    "vcs.update_pull_request",
    "vcs.update_pull_request_review",
    "vcs.validate_webhook_event",
    "vcs.verify_receipt",
];

/// An adapter of any provider, as a handle holds it.
enum Any {
    GitHub(GitHub),
    GitLab(GitLab),
    Gitea(Gitea),
    Bitbucket(Bitbucket),
    Origin(Origin),
}

impl Any {
    fn git(&self) -> &dyn Git {
        match self {
            Any::GitHub(a) => a,
            Any::GitLab(a) => a,
            Any::Gitea(a) => a,
            Any::Bitbucket(a) => a,
            Any::Origin(a) => a,
        }
    }

    fn git_mut(&mut self) -> &mut dyn Git {
        match self {
            Any::GitHub(a) => a,
            Any::GitLab(a) => a,
            Any::Gitea(a) => a,
            Any::Bitbucket(a) => a,
            Any::Origin(a) => a,
        }
    }

    /// The PHP class, for "undefined method" errors.
    fn class(&self) -> &'static str {
        match self {
            Any::GitHub(_) => "Utopia\\VCS\\Adapter\\Git\\GitHub",
            Any::GitLab(_) => "Utopia\\VCS\\Adapter\\Git\\GitLab",
            Any::Gitea(g) => match g.flavor() {
                Flavor::Gitea => "Utopia\\VCS\\Adapter\\Git\\Gitea",
                Flavor::Forgejo => "Utopia\\VCS\\Adapter\\Git\\Forgejo",
                Flavor::Gogs => "Utopia\\VCS\\Adapter\\Git\\Gogs",
            },
            Any::Bitbucket(_) => "Utopia\\VCS\\Adapter\\Git\\Bitbucket",
            Any::Origin(_) => "Utopia\\VCS\\Adapter\\Git\\Origin",
        }
    }
}

/// The process environment the cases' git commands run in (see the PHP
/// adapter's `environment()`).
fn environment() {
    static ONCE: Once = Once::new();
    ONCE.call_once(|| {
        let config = "/tmp/compat-fs/vcs-gitconfig-rust";
        let _ = std::fs::create_dir_all("/tmp/compat-fs");
        let _ = std::fs::write(config, "[http]\n\tsslVerify = false\n[advice]\n\tdetachedHead = false\n");
        // SAFETY: set once, before any of this driver's git commands run;
        // PHP's adapter calls putenv() the same way.
        unsafe {
            std::env::set_var("GIT_CONFIG_GLOBAL", config);
            std::env::set_var("GIT_CONFIG_NOSYSTEM", "1");
            std::env::set_var("GIT_TERMINAL_PROMPT", "0");
            std::env::set_var("GIT_AUTHOR_DATE", "1767225600 +0000");
            std::env::set_var("GIT_COMMITTER_DATE", "1767225600 +0000");
        }
    });
}

/// `Codec::decode`: JSON as the PHP driver decodes it.
fn decode(json: &Value) -> Result<Php, Fault> {
    Ok(match json {
        Value::Null => Php::Null,
        Value::Bool(b) => Php::Bool(*b),
        Value::Number(n) => match n.as_i64() {
            Some(i) => Php::Int(i),
            None => Php::Float(n.as_f64().unwrap_or(0.0)),
        },
        Value::String(s) => Php::Str(Str::from(s.as_str())),
        Value::Array(items) => Php::Array(items.iter().map(decode).collect::<Result<Array, Fault>>()?),
        Value::Object(o) if o.is_empty() => Php::Object(Array::new()),
        Value::Object(o) => {
            if o.len() == 1
                && let Some(b) = o.get("$bytes").and_then(Value::as_str)
            {
                return Ok(Php::Str(Str::from(BASE64.decode(b).map_err(|_| Fault::new("invalid $bytes"))?)));
            }
            if o.len() == 1
                && let Some(f) = o.get("$float").and_then(Value::as_str)
            {
                return Ok(Php::Float(match f {
                    "INF" => f64::INFINITY,
                    "-INF" => f64::NEG_INFINITY,
                    _ => f64::NAN,
                }));
            }
            Php::Array(
                o.iter().map(|(k, v)| Ok((ArrayKey::normalize(Str::from(k.as_str())), decode(v)?))).collect::<Result<
                    Array,
                    Fault,
                >>(
                )?,
            )
        }
    })
}

/// `Codec::encode` then `json_encode`: lists as arrays, other arrays as
/// objects, strings that are not UTF-8 as `$bytes`.
fn encode(value: &Php) -> Value {
    match value {
        Php::Null => Value::Null,
        Php::Bool(b) => Value::Bool(*b),
        Php::Int(i) => json!(i),
        Php::Float(f) => float_value(*f),
        Php::Str(s) => bytes_value(s.as_bytes()),
        Php::Array(a) if a.is_list() => Value::Array(a.values().map(encode).collect()),
        Php::Array(a) | Php::Object(a) => Value::Object(
            a.iter()
                .map(|(k, v)| {
                    let key = match k {
                        KeyRef::Int(i) => i.to_string(),
                        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                    };
                    (key, encode(v))
                })
                .collect(),
        ),
        Php::Ext(_) => Value::Null,
    }
}

/// A base without its scheme, as it follows the credentials in a clone URL.
fn host(base: &str) -> &str {
    base.find("://").map_or(base, |i| &base[i + 3..])
}

/// `${vcs}`, `${vcs-tls}`, `${vcs-host}` and `${vcs-tls-host}` as this
/// runtime's fixture server bases.
fn inward(value: &Value, http: &str, tls: &str) -> Value {
    match value {
        Value::String(s) => Value::String(
            s.replace("${vcs-tls-host}", host(tls))
                .replace("${vcs-host}", host(http))
                .replace("${vcs-tls}", tls)
                .replace("${vcs}", http),
        ),
        Value::Array(items) => Value::Array(items.iter().map(|v| inward(v, http, tls)).collect()),
        Value::Object(o) => Value::Object(o.iter().map(|(k, v)| (k.clone(), inward(v, http, tls))).collect()),
        other => other.clone(),
    }
}

/// The bases back as placeholders (also JSON-escaped, inside recorded bodies).
fn outward(value: Value, http: &str, tls: &str) -> Value {
    let swap = |s: &str| {
        s.replace(tls, "${vcs-tls}")
            .replace(&tls.replace('/', "\\/"), "${vcs-tls}")
            .replace(http, "${vcs}")
            .replace(&http.replace('/', "\\/"), "${vcs}")
            .replace(host(tls), "${vcs-tls-host}")
            .replace(host(http), "${vcs-host}")
    };
    match value {
        Value::String(s) => Value::String(swap(&s)),
        Value::Array(items) => Value::Array(items.into_iter().map(|v| outward(v, http, tls)).collect()),
        Value::Object(o) => Value::Object(o.into_iter().map(|(k, v)| (swap(&k), outward(v, http, tls))).collect()),
        other => other,
    }
}

fn fault(e: impl std::fmt::Display) -> Fault {
    Fault::new(e.to_string())
}

/// A command as its shell line, or the `ValueError` PHP's `(string)` throws
/// for a word that cannot be quoted.
fn shell(command: &Command) -> Value {
    match command.to_shell() {
        Ok(line) => Value::String(line),
        Err(e) => json!({"$error": {"class": e.php_class(), "message": e.to_string(), "code": 0}}),
    }
}

/// A library error as PHP's adapter reports it.
fn error(e: &Error) -> Value {
    json!({"$error": {"class": e.php_class(), "message": bytes_value(&e.message()), "code": e.code()}})
}

/// The `Error` PHP throws calling a method the adapter's class lacks.
fn undefined(class: &str, method: &str) -> Value {
    json!({"$error": {"class": "Error", "message": format!("Call to undefined method {class}::{method}()"), "code": 0}})
}

/// The named argument `key`, or `default`.
fn string<'a>(a: &'a Map<String, Value>, key: &str, default: &'a str) -> Result<&'a str, Fault> {
    match a.get(key) {
        None => Ok(default),
        Some(Value::String(s)) => Ok(s),
        Some(other) => Err(Fault::new(format!("argument `{key}` must be a string, got {other}"))),
    }
}

/// A required string argument.
fn required<'a>(a: &'a Map<String, Value>, key: &str) -> Result<&'a str, Fault> {
    match a.get(key) {
        Some(Value::String(s)) => Ok(s),
        other => Err(Fault::new(format!("argument `{key}` must be a string, got {other:?}"))),
    }
}

/// An optional nullable string argument.
fn nullable<'a>(a: &'a Map<String, Value>, key: &str) -> Result<Option<&'a str>, Fault> {
    match a.get(key) {
        None | Some(Value::Null) => Ok(None),
        Some(Value::String(s)) => Ok(Some(s)),
        Some(other) => Err(Fault::new(format!("argument `{key}` must be a string or null, got {other}"))),
    }
}

fn int(a: &Map<String, Value>, key: &str, default: Option<i64>) -> Result<i64, Fault> {
    match a.get(key) {
        None => default.ok_or_else(|| Fault::new(format!("missing argument `{key}`"))),
        Some(v) => v.as_i64().ok_or_else(|| Fault::new(format!("argument `{key}` must be an integer"))),
    }
}

fn boolean(a: &Map<String, Value>, key: &str, default: bool) -> Result<bool, Fault> {
    match a.get(key) {
        None => Ok(default),
        Some(v) => v.as_bool().ok_or_else(|| Fault::new(format!("argument `{key}` must be a boolean"))),
    }
}

fn byte_string(a: &Map<String, Value>, key: &str) -> Result<Vec<u8>, Fault> {
    let value = a.get(key).ok_or_else(|| Fault::new(format!("missing argument `{key}`")))?;
    if key == "payload" && (value.is_object() || value.is_array()) {
        // A payload given as JSON rather than as its text: its json_encode()
        let encoded = php_std::json::encode(&decode(value)?, php_std::json::Flags::NONE, 512).map_err(fault)?;
        return Ok(encoded.into_bytes());
    }
    crate::adapter::bytes(value).ok_or_else(|| Fault::new(format!("argument `{key}` must be a string")))
}

fn strings(a: &Map<String, Value>, key: &str, default: &[&str]) -> Result<Vec<String>, Fault> {
    match a.get(key) {
        None => Ok(default.iter().map(|s| (*s).to_owned()).collect()),
        Some(Value::Array(items)) => items
            .iter()
            .map(|v| v.as_str().map(str::to_owned).ok_or_else(|| Fault::new(format!("`{key}` must hold strings"))))
            .collect(),
        Some(_) => Err(Fault::new(format!("argument `{key}` must be a list"))),
    }
}

fn php_array(a: &Map<String, Value>, key: &str) -> Result<Array, Fault> {
    match a.get(key) {
        None => Ok(Array::new()),
        Some(v) => match decode(v)? {
            Php::Array(arr) => Ok(arr),
            Php::Object(_) => Ok(Array::new()),
            _ => Err(Fault::new(format!("argument `{key}` must be an array"))),
        },
    }
}

/// `createCheckRun()`/`updateCheckRun()`'s named parameters, with the
/// status default of the method.
fn check_run(a: &Map<String, Value>, status: &str) -> Result<CheckRun, Fault> {
    Ok(CheckRun {
        name: string(a, "name", "")?.to_owned(),
        status: string(a, "status", status)?.to_owned(),
        conclusion: string(a, "conclusion", "")?.to_owned(),
        title: string(a, "title", "")?.to_owned(),
        summary: string(a, "summary", "")?.to_owned(),
        text: string(a, "text", "")?.to_owned(),
        annotations: php_array(a, "annotations")?,
        images: php_array(a, "images")?,
        actions: php_array(a, "actions")?,
        details_url: string(a, "detailsUrl", "")?.to_owned(),
        external_id: string(a, "externalId", "")?.to_owned(),
        started_at: string(a, "startedAt", "")?.to_owned(),
        completed_at: string(a, "completedAt", "")?.to_owned(),
    })
}

/// A list result.
fn list(items: Vec<Php>) -> Value {
    Value::Array(items.iter().map(encode).collect())
}

/// Headers as PHP's associative array (`[]` when empty).
fn headers(pairs: Vec<(String, String)>) -> Value {
    if pairs.is_empty() {
        return json!([]);
    }
    Value::Object(pairs.into_iter().map(|(k, v)| (k, Value::String(v))).collect())
}

/// The snake_case operation name of a PHP method.
fn snake(method: &str) -> String {
    let mut out = String::new();
    for (i, c) in method.chars().enumerate() {
        if c.is_ascii_uppercase() && i > 0 {
            out.push('_');
        }
        out.push(c.to_ascii_lowercase());
    }
    out
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    environment();
    let http = session.service("vcs-http").unwrap_or("http://localhost:16950").to_owned();
    let tls = session.service("vcs-https").unwrap_or("https://localhost:16951").to_owned();
    let args = inward(args, &http, &tls);
    let a = args.as_object().cloned().unwrap_or_default();
    let back = |v: Value| Ok(Outcome::Ok(outward(v, &http, &tls)));

    match op {
        "vcs.new" => {
            let any = build(&a, session).await?;
            Ok(Outcome::Ok(session.handle(any)))
        }
        "fixture.requests" => back(control(session, &http, "requests", None).await?),
        "fixture.script" => back(
            control(session, &http, "script", Some(json!({"routes": a.get("routes").cloned().unwrap_or(json!([]))})))
                .await?,
        ),
        "fixture.config" => {
            back(control(session, &http, "config", Some(a.get("config").cloned().unwrap_or(json!({})))).await?)
        }
        "fixture.caught" => back(control(session, &http, "caught", None).await?),
        "clone.setup" => Ok(Outcome::ok(clone_setup(&a).await?)),
        "clone.tag" => Ok(Outcome::ok(clone_tag(&a).await?)),
        "clone.ref" => Ok(Outcome::ok(
            git(&["-C", required(&a, "repository")?, "update-ref", required(&a, "ref")?, required(&a, "target")?])
                .await?,
        )),
        "clone.command" => {
            let repository = required(&a, "repository")?;
            let result = utopia_vcs::clone_command(
                &format!("file://{repository}"),
                required(&a, "version")?,
                required(&a, "versionType")?,
                required(&a, "directory")?,
                required(&a, "rootDirectory")?,
            );
            back(match result {
                Ok(command) => shell(&command),
                Err(e) => error(&e),
            })
        }
        "clone.run" => Ok(Outcome::ok(
            run(required(&a, "command")?, required(&a, "directory")?, a.get("workspace").and_then(Value::as_str))
                .await?,
        )),
        _ => {
            let Some(name) = op.strip_prefix("vcs.") else {
                return Err(Fault::new(format!("vcs: unknown operation `{op}`")));
            };
            let Some(method) = METHODS.iter().find(|m| snake(m) == name) else {
                return Err(Fault::new(format!("vcs: unknown operation `{op}`")));
            };
            // A provider's name instead of a handle: a fresh adapter of it,
            // for fuzzing what needs no provider (payloads, signatures, URLs)
            if let Some(name) = a.get("adapter").and_then(Value::as_str) {
                let fresh = Map::from_iter([("adapter".to_owned(), json!(name)), ("cache".to_owned(), json!("none"))]);
                let mut any = build(&fresh, session).await?;
                return back(invoke(&mut any, method, &a).await?);
            }
            let handle = a.get("adapter").cloned().unwrap_or(Value::Null);
            let any = session.get_mut::<Any>(&handle)?;
            back(invoke(any, method, &a).await?)
        }
    }
}

/// `vcs.new`: an adapter of `adapter` (`github`, `gitlab`, `gitea`,
/// `forgejo`, `gogs`, `bitbucket`, `origin`) with a `memory` (default),
/// `none` or shared `redis` token cache, `seed`ed with entries.
async fn build(a: &Map<String, Value>, session: &Session) -> Result<Any, Fault> {
    let cache: Arc<Cache> = Arc::new(match string(a, "cache", "memory")? {
        "none" => Cache::from_dyn(Arc::new(NoCache)),
        "redis" => {
            let url = session.service("cache-redis-shared")?;
            let client = redis::Client::open(url).map_err(fault)?;
            Cache::from_dyn(Arc::new(Redis::new(client.get_connection_manager().await.map_err(fault)?)))
        }
        _ => Cache::from_dyn(Arc::new(Memory::new())),
    });
    if let Some(Value::Object(seed)) = a.get("seed") {
        for (key, value) in seed {
            cache.save(key, &decode(value)?, "", 0).await.map_err(fault)?;
        }
    }
    let endpoint = string(a, "endpoint", "")?;
    Ok(match required(a, "adapter")? {
        "github" if endpoint.is_empty() => Any::GitHub(GitHub::new(cache)),
        "github" => Any::GitHub(GitHub::new(cache).with_endpoint(endpoint)),
        "gitlab" => Any::GitLab(GitLab::new(cache)),
        "gitea" => Any::Gitea(Gitea::new(cache)),
        "forgejo" => Any::Gitea(Gitea::forgejo(cache)),
        "gogs" => Any::Gitea(Gitea::gogs(cache)),
        "bitbucket" => match a.get("web").and_then(Value::as_str) {
            Some(web) => Any::Bitbucket(Bitbucket::new(cache).with_web_url(web)),
            None => Any::Bitbucket(Bitbucket::new(cache)),
        },
        "origin" if endpoint.is_empty() => Any::Origin(Origin::new(cache)),
        "origin" => Any::Origin(
            Origin::new(cache)
                .with_endpoint(endpoint)
                .with_web_endpoint(string(a, "web", "")?)
                .with_git_endpoint(string(a, "git", "")?),
        ),
        other => return Err(Fault::new(format!("unknown adapter {other}"))),
    })
}

/// Calls one adapter method with PHP's named arguments and defaults.
async fn invoke(any: &mut Any, method: &str, a: &Map<String, Value>) -> Result<Value, Fault> {
    macro_rules! ok {
        ($result:expr, $encode:expr) => {
            match $result {
                Ok(value) => $encode(value),
                Err(e) => error(&e),
            }
        };
    }
    let owner = || required(a, "owner");
    let repo = || required(a, "repositoryName");
    let value = |v: Php| encode(&v);

    Ok(match method {
        "getName" => json!(any.git().name()),
        "getType" => json!(any.git().vcs_type().as_str()),
        "getEventHeaderName" => json!(any.git().event_header_name()),
        "getSignatureHeaderName" => json!(any.git().signature_header_name()),
        "getSupportedWebhookScopes" => {
            Value::Array(any.git().supported_webhook_scopes().iter().map(|s| json!(s.as_str())).collect())
        }
        "supportsRepositoryCreation" => json!(any.git().supports_repository_creation()),
        "supportsRepositoryDeletion" => json!(any.git().supports_repository_deletion()),
        "supportsRepositoryArchives" => json!(any.git().supports_repository_archives()),
        "supportsCommentImages" => json!(any.git().supports_comment_images()),
        "supportsPublicRepositories" => json!(any.git().supports_public_repositories()),
        "getRepositoryCloneHeaders" => headers(any.git().repository_clone_headers()),
        "getRepositoryPresignedUrlHeaders" => headers(any.git().repository_presigned_url_headers()),
        "getRepositoryUrl" => json!(any.git().repository_url(owner()?, repo()?)),
        "getOrganizationUrl" => ok!(any.git().organization_url(owner()?), |v: String| json!(v)),
        "getBranchUrl" => json!(any.git().branch_url(owner()?, repo()?, required(a, "branch")?)),
        "getCommitUrl" => json!(any.git().commit_url(owner()?, repo()?, required(a, "commitHash")?)),
        "getFileUrl" => json!(any.git().file_url(owner()?, repo()?, required(a, "reference")?)),
        "getRepositoryCloneUrl" => ok!(any.git().repository_clone_url(owner()?, repo()?), |v: String| json!(v)),
        "validateWebhookEvent" => json!(any.git().validate_webhook_event(
            &byte_string(a, "payload")?,
            &byte_string(a, "signature")?,
            &byte_string(a, "signatureKey")?,
        )),
        "getEvents" => ok!(any.git().events(required(a, "event")?, &byte_string(a, "payload")?), list),
        "generateCloneCommand" => ok!(
            any.git().clone_command(
                owner()?,
                repo()?,
                required(a, "version")?,
                required(a, "versionType")?,
                required(a, "directory")?,
                required(a, "rootDirectory")?,
            ),
            |c: Command| shell(&c)
        ),
        "initializeVariables" => ok!(
            any.git_mut()
                .initialize(
                    required(a, "installationId")?,
                    required(a, "privateKey")?,
                    nullable(a, "appId")?,
                    nullable(a, "accessToken")?,
                    nullable(a, "refreshToken")?,
                )
                .await,
            |()| Value::Null
        ),
        "getUser" => ok!(any.git().user(required(a, "username")?).await, value),
        "getOwnerName" => {
            let repository_id = match a.get("repositoryId") {
                None | Some(Value::Null) => None,
                Some(v) => Some(v.as_i64().ok_or_else(|| Fault::new("repositoryId must be an integer"))?),
            };
            ok!(any.git().owner_name(required(a, "installationId")?, repository_id).await, |v: String| json!(v))
        }
        "hasAccessToAllRepositories" => ok!(any.git().has_access_to_all_repositories().await, |v: bool| json!(v)),
        "searchRepositories" => ok!(
            any.git()
                .search_repositories(
                    owner()?,
                    int(a, "page", None)?,
                    int(a, "per_page", None)?,
                    string(a, "search", "")?
                )
                .await,
            value
        ),
        "getInstallationRepository" => ok!(any.git().installation_repository(repo()?).await, value),
        "getRepository" => ok!(any.git().repository(owner()?, repo()?).await, value),
        "createRepository" => {
            ok!(any.git().create_repository(owner()?, repo()?, boolean(a, "private", false)?).await, value)
        }
        "deleteRepository" => ok!(any.git().delete_repository(owner()?, repo()?).await, |v: bool| json!(v)),
        "getPullRequestFromBranch" => {
            ok!(any.git().pull_request_from_branch(owner()?, repo()?, required(a, "branch")?).await, value)
        }
        "getPullRequest" => {
            ok!(any.git().pull_request(owner()?, repo()?, int(a, "pullRequestNumber", None)?).await, value)
        }
        "getPullRequestFiles" => {
            ok!(any.git().pull_request_files(owner()?, repo()?, int(a, "pullRequestNumber", None)?).await, value)
        }
        "createComment" => ok!(
            any.git()
                .create_comment(owner()?, repo()?, int(a, "pullRequestNumber", None)?, required(a, "comment")?)
                .await,
            |v: String| json!(v)
        ),
        "getComment" => {
            ok!(any.git().comment(owner()?, repo()?, required(a, "commentId")?).await, |v: String| json!(v))
        }
        "updateComment" => ok!(
            any.git().update_comment(owner()?, repo()?, required(a, "commentId")?, required(a, "comment")?).await,
            |v: String| json!(v)
        ),
        "getRepositoryName" => {
            ok!(any.git().repository_name(required(a, "repositoryId")?).await, |v: String| json!(v))
        }
        "listBranches" => match any {
            Any::GitHub(g) => ok!(
                g.list_branches_page(
                    owner()?,
                    repo()?,
                    int(a, "perPage", Some(100))?,
                    int(a, "page", Some(1))?,
                    string(a, "search", "")?,
                )
                .await,
                list
            ),
            other => ok!(other.git().list_branches(owner()?, repo()?).await, list),
        },
        "listTags" => ok!(any.git().list_tags(owner()?, repo()?, string(a, "search", "")?).await, list),
        "updateCommitStatus" => ok!(
            any.git()
                .update_commit_status(
                    repo()?,
                    // Git names it $SHA, the adapters that rename it $commitHash
                    required(a, "SHA").or_else(|_| required(a, "commitHash"))?,
                    owner()?,
                    required(a, "state")?,
                    string(a, "description", "")?,
                    string(a, "target_url", "")?,
                    string(a, "context", "")?,
                )
                .await,
            |()| Value::Null
        ),
        "createCheckRun" => {
            let run = check_run(a, "queued")?;
            ok!(any.git().create_check_run(owner()?, repo()?, required(a, "headSha")?, &run).await, value)
        }
        "getCheckRun" => ok!(any.git().check_run(owner()?, repo()?, required(a, "checkRunId")?).await, value),
        "updateCheckRun" => {
            let run = check_run(a, "")?;
            ok!(any.git().update_check_run(owner()?, repo()?, required(a, "checkRunId")?, &run).await, value)
        }
        "getRepositoryTree" => ok!(
            any.git().repository_tree(owner()?, repo()?, required(a, "branch")?, boolean(a, "recursive", false)?).await,
            list
        ),
        "listRepositoryLanguages" => ok!(any.git().list_repository_languages(owner()?, repo()?).await, list),
        "listRepositoryContents" => ok!(
            any.git().list_repository_contents(owner()?, repo()?, string(a, "path", "")?, string(a, "ref", "")?).await,
            list
        ),
        "getRepositoryContent" => ok!(
            any.git().repository_content(owner()?, repo()?, required(a, "path")?, string(a, "ref", "")?).await,
            value
        ),
        "getCommit" => ok!(any.git().commit(owner()?, repo()?, required(a, "commitHash")?).await, value),
        "getLatestCommit" => ok!(any.git().latest_commit(owner()?, repo()?, required(a, "branch")?).await, value),
        "getRepositoryPresignedUrl" => ok!(
            any.git()
                .repository_presigned_url(owner()?, repo()?, string(a, "ref", "")?, string(a, "format", "tarball")?)
                .await,
            |v: String| json!(v)
        ),
        "createFile" => ok!(
            any.git()
                .create_file(
                    owner()?,
                    repo()?,
                    required(a, "filepath")?,
                    &byte_string(a, "content")?,
                    string(a, "message", "Add file")?,
                    string(a, "branch", "")?,
                )
                .await,
            value
        ),
        "createBranch" => ok!(
            any.git()
                .create_branch(owner()?, repo()?, required(a, "newBranchName")?, required(a, "oldBranchName")?)
                .await,
            value
        ),
        "createPullRequest" => ok!(
            any.git()
                .create_pull_request(
                    owner()?,
                    repo()?,
                    required(a, "title")?,
                    required(a, "head")?,
                    required(a, "base")?,
                    string(a, "body", "")?,
                )
                .await,
            value
        ),
        "createWebhook" => {
            let events = strings(a, "events", &["push", "pull_request"])?;
            let events: Vec<&str> = events.iter().map(String::as_str).collect();
            ok!(
                any.git().create_webhook(owner()?, repo()?, required(a, "url")?, required(a, "secret")?, &events).await,
                |id: WebhookId| match id {
                    WebhookId::Int(i) => json!(i),
                    WebhookId::Str(s) => json!(s),
                }
            )
        }
        "createTag" => ok!(
            any.git()
                .create_tag(
                    owner()?,
                    repo()?,
                    required(a, "tagName")?,
                    required(a, "target")?,
                    string(a, "message", "")?
                )
                .await,
            value
        ),
        "listNamespaces" => ok!(
            any.git().list_namespaces(int(a, "page", None)?, int(a, "per_page", None)?, string(a, "search", "")?).await,
            value
        ),
        "getCommitStatuses" => {
            ok!(any.git().commit_statuses(owner()?, repo()?, required(a, "commitHash")?).await, list)
        }
        "setEndpoint" => match any {
            Any::GitLab(g) => {
                g.set_endpoint(required(a, "endpoint")?);
                Value::Null
            }
            Any::Gitea(g) => {
                g.set_endpoint(required(a, "endpoint")?);
                Value::Null
            }
            Any::Bitbucket(g) => {
                g.set_endpoint(required(a, "endpoint")?);
                Value::Null
            }
            other => undefined(other.class(), method),
        },
        "createOrganization" => match any {
            Any::GitLab(g) => ok!(g.create_organization(required(a, "orgName")?).await, |v: String| json!(v)),
            Any::Gitea(g) => ok!(g.create_organization(required(a, "orgName")?).await, |v: String| json!(v)),
            other => undefined(other.class(), method),
        },
        _ => match any {
            Any::Origin(origin) => origin_method(origin, method, a).await?,
            other => undefined(other.class(), method),
        },
    })
}

/// Origin's own methods.
async fn origin_method(origin: &mut Origin, method: &str, a: &Map<String, Value>) -> Result<Value, Fault> {
    macro_rules! ok {
        ($result:expr, $encode:expr) => {
            match $result {
                Ok(value) => $encode(value),
                Err(e) => error(&e),
            }
        };
    }
    let owner = || required(a, "owner");
    let repo = || required(a, "repositoryName");
    let number = || int(a, "pullRequestNumber", None);
    let value = |v: Php| encode(&v);
    Ok(match method {
        "getInstallUrl" => {
            let scopes = strings(a, "scopes", &[])?;
            let scopes: Vec<&str> = scopes.iter().map(String::as_str).collect();
            json!(origin.install_url(
                required(a, "appId")?,
                &scopes,
                string(a, "redirectUri", "")?,
                string(a, "state", "")?
            ))
        }
        "verifyReceipt" => ok!(origin.verify_receipt(required(a, "receipt")?, required(a, "appId")?).await, value),
        "getSigningKeys" => ok!(origin.signing_keys(boolean(a, "refresh", false)?).await, list),
        "updatePullRequest" => {
            let draft = match a.get("draft") {
                None | Some(Value::Null) => None,
                Some(v) => Some(v.as_bool().ok_or_else(|| Fault::new("draft must be a boolean"))?),
            };
            ok!(
                origin
                    .update_pull_request(
                        owner()?,
                        repo()?,
                        number()?,
                        nullable(a, "title")?,
                        nullable(a, "body")?,
                        nullable(a, "state")?,
                        draft,
                        nullable(a, "base")?,
                    )
                    .await,
                value
            )
        }
        "mergePullRequest" => ok!(origin.merge_pull_request(owner()?, repo()?, number()?).await, value),
        "listPullRequests" => ok!(
            origin.list_pull_requests(owner()?, repo()?, string(a, "state", "open")?, string(a, "head", "")?).await,
            value
        ),
        "listPullRequestComments" => ok!(origin.list_pull_request_comments(owner()?, repo()?, number()?).await, value),
        "listPullRequestCommits" => ok!(origin.list_pull_request_commits(owner()?, repo()?, number()?).await, value),
        "createPullRequestReview" => ok!(
            origin
                .create_pull_request_review(
                    owner()?,
                    repo()?,
                    number()?,
                    required(a, "verdict")?,
                    string(a, "body", "")?,
                    string(a, "versionNumber", "")?,
                )
                .await,
            value
        ),
        "listPullRequestReviews" => ok!(origin.list_pull_request_reviews(owner()?, repo()?, number()?).await, value),
        "updatePullRequestReview" => ok!(
            origin
                .update_pull_request_review(
                    owner()?,
                    repo()?,
                    number()?,
                    required(a, "reviewId")?,
                    required(a, "body")?
                )
                .await,
            value
        ),
        "dismissPullRequestReview" => ok!(
            origin
                .dismiss_pull_request_review(
                    owner()?,
                    repo()?,
                    number()?,
                    required(a, "reviewId")?,
                    required(a, "message")?
                )
                .await,
            value
        ),
        "listCommits" => ok!(origin.list_commits(owner()?, repo()?, string(a, "sha", "")?).await, list),
        "listCommitFiles" => ok!(origin.list_commit_files(owner()?, repo()?, required(a, "sha")?).await, value),
        "compareCommits" => {
            ok!(origin.compare_commits(owner()?, repo()?, required(a, "base")?, required(a, "head")?).await, value)
        }
        "getBlob" => ok!(origin.blob(owner()?, repo()?, required(a, "sha")?).await, value),
        "batchGetRepositoryContents" => {
            let paths = strings(a, "paths", &[])?;
            let paths: Vec<&str> = paths.iter().map(String::as_str).collect();
            ok!(origin.batch_get_repository_contents(owner()?, repo()?, &paths, string(a, "ref", "")?).await, value)
        }
        "getCheckSuite" => ok!(origin.check_suite(owner()?, repo()?, required(a, "checkSuiteId")?).await, value),
        "listCheckRunsForCommit" => {
            ok!(origin.list_check_runs_for_commit(owner()?, repo()?, required(a, "sha")?).await, value)
        }
        "listCheckRunsForSuite" => {
            ok!(origin.list_check_runs_for_suite(owner()?, repo()?, required(a, "checkSuiteId")?).await, value)
        }
        "batchUpsertCheckRuns" => {
            let mut runs = Vec::new();
            for item in a.get("checkRuns").and_then(Value::as_array).cloned().unwrap_or_default() {
                let fields = item.as_object().cloned().unwrap_or_default();
                runs.push(BatchCheckRun {
                    run: check_run(&fields, "queued")?,
                    key: string(&fields, "key", "")?.to_owned(),
                });
            }
            ok!(
                origin
                    .batch_upsert_check_runs(
                        owner()?,
                        repo()?,
                        required(a, "headSha")?,
                        required(a, "suiteName")?,
                        &runs
                    )
                    .await,
                value
            )
        }
        "getAuthenticatedApp" => ok!(origin.authenticated_app().await, value),
        "listInstallations" => ok!(origin.list_installations().await, value),
        "deleteInstallation" => {
            ok!(origin.delete_installation(required(a, "installationId")?).await, |v: bool| json!(v))
        }
        "listWebhookDeliveries" => {
            let delivered = match a.get("delivered") {
                None | Some(Value::Null) => None,
                Some(v) => Some(v.as_bool().ok_or_else(|| Fault::new("delivered must be a boolean"))?),
            };
            ok!(
                origin
                    .list_webhook_deliveries(
                        delivered,
                        string(a, "eventType", "")?,
                        string(a, "installationId", "")?,
                        string(a, "pageToken", "")?,
                    )
                    .await,
                value
            )
        }
        "redeliverWebhookDeliveries" => {
            let ids = strings(a, "deliveryIds", &[])?;
            let ids: Vec<&str> = ids.iter().map(String::as_str).collect();
            ok!(origin.redeliver_webhook_deliveries(&ids).await, value)
        }
        "getRateLimit" => ok!(origin.rate_limit().await, value),
        "syncMirror" => ok!(
            origin
                .sync_mirror(owner()?, repo()?, required(a, "ref")?, boolean(a, "wait", false)?, string(a, "sha", "")?)
                .await,
            |v: bool| json!(v)
        ),
        _ => undefined("Utopia\\VCS\\Adapter\\Git\\Origin", method),
    })
}

/// The fixture server's control API for this namespace.
async fn control(session: &Session, http: &str, action: &str, body: Option<Value>) -> Result<Value, Fault> {
    use utopia_client::{Client, Http, Request};
    let url = format!("{http}/_fixture/{}/{action}", session.ns);
    let client = Client::new(Http::new());
    let request = match body {
        None => Request::new("GET", &url).map_err(fault)?,
        Some(body) => Request::new("POST", &url)
            .and_then(|r| r.header("Content-Type", "application/json"))
            .map_err(fault)?
            .body(serde_json::to_vec(&body).map_err(fault)?),
    };
    let response = client.send(request).await.map_err(|e| {
        Fault::new(format!("the vcs-http fixture server is not running (tests/compat/vcs/services.sh up): {e}"))
    })?;
    serde_json::from_slice(response.bytes()).map_err(fault)
}

/// Runs git and returns its exit code.
async fn git(args: &[&str]) -> Result<i64, Fault> {
    environment();
    let mut command = Command::new("git").map_err(fault)?;
    for arg in args {
        command = command.argument(*arg).map_err(fault)?;
    }
    Ok(i64::from(utopia_console::execute(&command, b"", None, None).await.map_err(fault)?.status))
}

/// CloneCommandTest's remote (see the PHP adapter's `cloneSetup()`).
async fn clone_setup(a: &Map<String, Value>) -> Result<i64, Fault> {
    let workspace = required(a, "workspace")?;
    let source = format!("{workspace}/source");
    if let Some(Value::Object(files)) = a.get("files") {
        for (path, content) in files {
            let file = format!("{source}/{path}");
            if let Some(parent) = std::path::Path::new(&file).parent() {
                std::fs::create_dir_all(parent).map_err(fault)?;
            }
            std::fs::write(&file, content.as_str().unwrap_or_default()).map_err(fault)?;
        }
    }
    git(&["init", "-q", "-b", "main", &source]).await?;
    git(&["-C", &source, "add", "."]).await?;
    git(&["-C", &source, "-c", "user.name=Test", "-c", "user.email=test@example.com", "commit", "-q", "-m", "Initial"])
        .await?;
    git(&["clone", "-q", "--bare", &source, &format!("{workspace}/remote.git")]).await
}

/// CloneCommandTest::tag() (see the PHP adapter's `cloneTag()`).
async fn clone_tag(a: &Map<String, Value>) -> Result<i64, Fault> {
    let workspace = required(a, "workspace")?;
    let work = format!("{workspace}/work");
    if !std::path::Path::new(&format!("{work}/.git")).is_dir() {
        git(&["clone", "-q", &format!("{workspace}/remote.git"), &work]).await?;
    }
    std::fs::write(format!("{work}/README.md"), required(a, "content")?).map_err(fault)?;
    let name = required(a, "name")?;
    git(&["-C", &work, "add", "README.md"]).await?;
    git(&["-C", &work, "-c", "user.name=Test", "-c", "user.email=test@example.com", "commit", "-q", "-m", name])
        .await?;
    let mut tag = vec!["-C", &work, "-c", "user.name=Test", "-c", "user.email=test@example.com", "tag"];
    if boolean(a, "annotated", false)? {
        tag.extend(["-a", "-m", name]);
    }
    tag.push(name);
    let reference = format!("refs/tags/{name}");
    Ok(git(&tag).await? + git(&["-C", &work, "push", "-q", "origin", &reference]).await?)
}

/// Runs a command line (see the PHP adapter's `run()`).
async fn run(command: &str, directory: &str, workspace: Option<&str>) -> Result<Value, Fault> {
    environment();
    let cwd = std::env::current_dir().ok();
    if let Some(workspace) = workspace {
        std::fs::create_dir_all(workspace).map_err(fault)?;
        std::env::set_current_dir(workspace).map_err(fault)?;
    }
    let output = utopia_console::execute(command, b"", None, None).await.map_err(fault)?;
    if let Some(cwd) = cwd {
        let _ = std::env::set_current_dir(cwd);
    }
    let mut files = std::collections::BTreeMap::new();
    let root = std::path::Path::new(directory);
    let mut stack = vec![root.to_path_buf()];
    while let Some(dir) = stack.pop() {
        let Ok(entries) = std::fs::read_dir(&dir) else { continue };
        for entry in entries.flatten() {
            let path = entry.path();
            let relative = path.strip_prefix(root).map(|p| p.to_string_lossy().into_owned()).unwrap_or_default();
            if relative == ".git" || relative.starts_with(".git/") {
                continue;
            }
            if path.is_dir() {
                stack.push(path);
            } else if path.is_file() {
                files.insert(relative, bytes_value(&std::fs::read(&path).unwrap_or_default()));
            }
        }
    }
    let stderr = String::from_utf8_lossy(&output.stderr);
    let fatal: Vec<Value> =
        stderr.split('\n').filter(|l| l.starts_with("fatal: no tag matching")).map(|l| json!(l)).collect();
    let pwned = workspace.is_some_and(|w| {
        std::path::Path::new(&format!("{w}/pwned")).exists()
            || std::path::Path::new(&format!("{directory}/pwned")).exists()
    });
    let files = if files.is_empty() { json!({}) } else { Value::Object(files.into_iter().collect()) };
    Ok(json!({"exit": output.status, "files": files, "fatal": fatal, "pwned": pwned}))
}

/// Benchmarks (`Adapter.php`'s `benchmarks()`).
pub const BENCHES: &[(&str, &str)] = &[
    ("github_push_event", "Parse a GitHub push delivery into its event"),
    ("github_validate_webhook", "Validate a GitHub push delivery's HMAC-SHA256 signature"),
    ("gitlab_merge_request_event", "Parse a GitLab merge request delivery into its event"),
    ("bitbucket_push_event", "Parse a Bitbucket push delivery naming three refs into its events"),
    ("clone_command", "Build the sparse shallow clone command of a tag glob"),
    ("gitea_latest_commit", "Read a branch's latest commit from the fixture server (one API call)"),
];

/// A fixture payload of `tests/compat/vcs/benchmarks/`.
fn bench_payload(name: &str) -> Result<Vec<u8>, Fault> {
    let path = format!("{}/../../../tests/compat/vcs/benchmarks/{name}", env!("CARGO_MANIFEST_DIR"));
    std::fs::read(&path).map_err(|e| Fault::new(format!("{path}: {e}")))
}

pub async fn bench(name: &str, session: &mut Session) -> Result<Bench, Fault> {
    let none = || Arc::new(Cache::from_dyn(Arc::new(NoCache)));
    Ok(match name {
        "github_push_event" => {
            let payload = bench_payload("github-push.json")?;
            Bench::sync(move || {
                black_box(GitHub::new(none()).events("push", black_box(&payload)).ok());
            })
        }
        "github_validate_webhook" => {
            let payload = bench_payload("github-push.json")?;
            let secret = b"bench-webhook-secret".to_vec();
            let signature = {
                use hmac::Mac as _;
                let mut mac = hmac::Hmac::<sha2::Sha256>::new_from_slice(&secret).map_err(fault)?;
                mac.update(&payload);
                format!("sha256={}", php_std::encoding::bin2hex(&mac.finalize().into_bytes()))
            };
            Bench::sync(move || {
                black_box(GitHub::new(none()).validate_webhook_event(
                    black_box(&payload),
                    signature.as_bytes(),
                    &secret,
                ));
            })
        }
        "gitlab_merge_request_event" => {
            let payload = bench_payload("gitlab-merge-request.json")?;
            let gitlab = GitLab::new(none());
            Bench::sync(move || {
                black_box(gitlab.events("Merge Request Hook", black_box(&payload)).ok());
            })
        }
        "bitbucket_push_event" => {
            let payload = bench_payload("bitbucket-push.json")?;
            let bitbucket = Bitbucket::new(none());
            Bench::sync(move || {
                black_box(bitbucket.events("repo:push", black_box(&payload)).ok());
            })
        }
        "clone_command" => {
            let mut gitea = Gitea::new(none());
            gitea.initialize("", "", None, Some("bench-token"), None).await.map_err(fault)?;
            Bench::sync(move || {
                black_box(
                    gitea
                        .clone_command("acme", "website", "v1.*", "tag", "/tmp/build", "apps/web")
                        .and_then(|c| c.to_shell().map_err(Error::from))
                        .ok(),
                );
            })
        }
        "gitea_latest_commit" => {
            let http = session.service("vcs-http")?.to_owned();
            let mut gitea = Gitea::new(none());
            gitea.initialize("", "", None, Some("bench-token"), None).await.map_err(fault)?;
            gitea.set_endpoint(&format!("{http}/~{}-bench/gitea", session.ns));
            gitea.create_organization("bench-org").await.map_err(fault)?;
            gitea.create_repository("bench-org", "bench-repo", false).await.map_err(fault)?;
            gitea
                .create_file("bench-org", "bench-repo", "README.md", b"# Bench", "Add file", "")
                .await
                .map_err(fault)?;
            let gitea = std::rc::Rc::new(gitea);
            Bench::future(move || {
                let gitea = gitea.clone();
                async move {
                    black_box(gitea.latest_commit("bench-org", "bench-repo", "main").await.ok());
                }
            })
        }
        other => return Err(Fault::new(format!("vcs: no benchmark `{other}`"))),
    })
}
