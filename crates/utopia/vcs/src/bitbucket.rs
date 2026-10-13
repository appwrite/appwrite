//! Bitbucket Cloud, with a bearer token or an Atlassian account API token.

use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use php_std::{Array, ArrayKey, Value};
use sha1::{Digest, Sha1};
use utopia_cache::Cache;
use utopia_console::Command;

use crate::adapter::{Adapter, CheckRun, Git, VcsType, WebhookId, WebhookScope};
use crate::error::{Error, Result};
use crate::git::{self, clone_command, normalize_repository_path};
use crate::github::{concat, settle};
use crate::php::{self, at, each, empty, object, or, path, s};
use crate::transport::{Call, Method, Params, Response, Transport};
use crate::webhook;

/// The class PHP names in errors about this adapter's state.
const CLASS: &str = "Utopia\\VCS\\Adapter\\Git\\Bitbucket";

/// Largest page Bitbucket serves.
const PAGE_SIZE: i64 = 100;

/// The depth a recursive tree walk asks for (Bitbucket has no unlimited one).
const MAX_TREE_DEPTH: i64 = 100;

/// The shared status vocabulary (GitHub's) as Bitbucket build states.
const COMMIT_STATE_MAP: [(&str, &str); 6] = [
    ("pending", "INPROGRESS"),
    ("in_progress", "INPROGRESS"),
    ("success", "SUCCESSFUL"),
    ("failure", "FAILED"),
    ("error", "FAILED"),
    ("cancelled", "STOPPED"),
];

/// Build states back in the vocabulary `updateCommitStatus()` takes.
const COMMIT_STATE_MAP_REVERSE: [(&str, &str); 4] =
    [("INPROGRESS", "pending"), ("SUCCESSFUL", "success"), ("FAILED", "failure"), ("STOPPED", "cancelled")];

/// Check run conclusions as build states.
const CHECK_RUN_CONCLUSION_MAP: [(&str, &str); 7] = [
    ("success", "SUCCESSFUL"),
    ("failure", "FAILED"),
    ("timed_out", "FAILED"),
    ("action_required", "FAILED"),
    ("cancelled", "STOPPED"),
    ("neutral", "STOPPED"),
    ("skipped", "STOPPED"),
];

/// Pull request event keys as the verbs consumers key off of.
const PULL_REQUEST_ACTION_MAP: [(&str, &str); 4] = [
    ("pullrequest:created", "opened"),
    ("pullrequest:updated", "synchronize"),
    ("pullrequest:fulfilled", "closed"),
    ("pullrequest:rejected", "closed"),
];

/// A lookup in one of the maps above by a PHP string key.
fn lookup<'a>(map: &'a [(&'a str, &'a str)], key: &[u8]) -> Option<&'a str> {
    match ArrayKey::from_bytes(key) {
        ArrayKey::Str(k) => map.iter().find(|(from, _)| from.as_bytes() == k.as_bytes()).map(|(_, to)| *to),
        ArrayKey::Int(_) => None,
    }
}

/// The Bitbucket adapter. Repositories have no numeric ids: their
/// `"workspace/slug"` is the id. A token holding a colon is an Atlassian
/// `email:token` pair (HTTP Basic); anything else is a bearer token.
///
/// PHP: `Utopia\VCS\Adapter\Git\Bitbucket`.
pub struct Bitbucket {
    #[allow(dead_code)]
    cache: Arc<Cache>,
    transport: Transport,
    bitbucket_url: String,
    access_token: Option<String>,
    authenticated_user: Mutex<Option<Value>>,
}

impl Bitbucket {
    /// An adapter on `https://api.bitbucket.org/2.0`.
    pub fn new(cache: Arc<Cache>) -> Self {
        Bitbucket {
            cache,
            transport: Transport::new("https://api.bitbucket.org/2.0"),
            bitbucket_url: "https://bitbucket.org".into(),
            access_token: None,
            authenticated_user: Mutex::new(None),
        }
    }

    /// Moves the API host; the browser-facing host stays.
    ///
    /// PHP: `setEndpoint()`.
    pub fn set_endpoint(&mut self, endpoint: &str) {
        self.transport.endpoint = endpoint.trim_end_matches('/').to_owned();
    }

    /// The same adapter with another browser-facing (and clone) host. PHP
    /// sets the protected `$bitbucketUrl` in a subclass.
    pub fn with_web_url(mut self, url: impl Into<String>) -> Self {
        self.bitbucket_url = url.into();
        self
    }

    fn token(&self) -> Result<&str> {
        self.access_token.as_deref().ok_or_else(|| {
            Error::Engine(php_std::EngineError::new(
                "Error",
                format!("Typed property {CLASS}::$accessToken must not be accessed before initialization"),
            ))
        })
    }

    /// The `Authorization` header for the credential.
    fn authorization(&self) -> Result<String> {
        let token = self.token()?;
        Ok(if token.contains(':') {
            format!("Basic {}", php_std::encoding::base64_encode(token.as_bytes()))
        } else {
            format!("Bearer {token}")
        })
    }

    async fn call(&self, call: Call) -> Result<Response> {
        let authorization = self.authorization()?;
        self.transport.call(call.auth(authorization)).await
    }

    async fn get(&self, path: impl Into<String>) -> Result<Response> {
        self.call(Call::get(path)).await
    }

    /// A nested branch name read as one path segment would be split, so it
    /// resolves to the hash it points at.
    async fn resolve_ref(&self, owner: &str, repository_name: &str, reference: &str) -> Result<String> {
        if !reference.contains('/') {
            return Ok(reference.to_owned());
        }
        let response = self.get(format!("/repositories/{owner}/{repository_name}/refs/branches/{reference}")).await?;
        let hash = match &response.body {
            Value::Array(_) => or(path(&response.body, &["target", "hash"]), ""),
            _ => s(""),
        };
        Ok(if empty(&hash) { reference.to_owned() } else { php::text(&php::string(&hash)) })
    }

    /// The main branch's name, or `""` before the first commit.
    async fn main_branch_name(&self, owner: &str, repository_name: &str) -> Result<String> {
        let repository = self.repository(owner, repository_name).await?;
        let main = or(at(&repository, "mainbranch"), Value::Array(Array::new()));
        Ok(php::text(&php::string(&or(at(&main, "name"), ""))))
    }

    /// The path of a file or directory in the source tree, at `reference` or
    /// the main branch.
    async fn source_url(&self, owner: &str, repository_name: &str, path: &str, reference: &str) -> Result<String> {
        let mut reference = reference.to_owned();
        if !php::filled(&reference) {
            reference = self.main_branch_name(owner, repository_name).await?;
            if !php::filled(&reference) {
                return Err(Error::exception(
                    format!("Unable to resolve the main branch of {owner}/{repository_name}."),
                    0,
                ));
            }
        }
        let reference = self.resolve_ref(owner, repository_name, &reference).await?;
        let path = normalize_repository_path(path)
            .split('/')
            .map(|segment| php_std::encoding::rawurlencode(segment.as_bytes()))
            .collect::<Vec<_>>()
            .join("/");
        Ok(format!(
            "/repositories/{owner}/{repository_name}/src/{}/{path}",
            php_std::encoding::rawurlencode(reference.as_bytes())
        ))
    }

    /// A directory's entries, following pagination; an empty list for a ref
    /// or path that does not exist.
    async fn list_source(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        reference: &str,
        suffix: &str,
    ) -> Result<Value> {
        let base = match self.source_url(owner, repository_name, path, reference).await {
            Ok(base) => base,
            Err(e) if e.is_exception() => return Ok(Value::Array(Array::new())),
            Err(e) => return Err(e),
        };
        let mut items = Value::Array(Array::new());
        let mut url = format!("{base}?pagelen={PAGE_SIZE}{suffix}");
        while !url.is_empty() {
            let response = self.get(url.clone()).await?;
            if response.status == 404 {
                return Ok(items);
            }
            if response.status >= 400 {
                return Err(Error::exception(
                    format!("Listing {owner}/{repository_name} failed with status code {}", response.status),
                    response.status,
                ));
            }
            if !php::is_array(&response.body) {
                break;
            }
            let values = or(response.at("values"), Value::Array(Array::new()));
            if !php::is_array(&values) {
                break;
            }
            items = Value::Array(php::array_merge(&[&items, &values])?);
            let next = php::text(&php::string(&or(response.at("next"), "")));
            url = match next.strip_prefix(self.transport.endpoint.as_str()) {
                Some(rest) => rest.to_owned(),
                None => String::new(),
            };
        }
        Ok(items)
    }

    /// The names of the repository's branches or tags, every page.
    async fn list_refs(&self, owner: &str, repository_name: &str, kind: &str) -> Result<Vec<Value>> {
        let mut names = Vec::new();
        let mut page = 1;
        loop {
            let response = self
                .get(format!("/repositories/{owner}/{repository_name}/refs/{kind}?pagelen={PAGE_SIZE}&page={page}"))
                .await?;
            if response.status >= 400 {
                return Ok(if page == 1 { Vec::new() } else { names });
            }
            if !php::is_array(&response.body) {
                break;
            }
            let values = or(response.at("values"), Value::Array(Array::new()));
            let Value::Array(values) = &values else { break };
            for reference in values.values() {
                names.push(or(at(reference, "name"), ""));
            }
            page += 1;
            if empty(&response.at("next")) {
                break;
            }
        }
        Ok(names)
    }

    /// The account the token belongs to (asked once).
    async fn authenticated_user(&self) -> Result<Value> {
        if let Some(user) = self.authenticated_user.lock().unwrap_or_else(|e| e.into_inner()).clone() {
            return Ok(user);
        }
        let response = self.get("/user").await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to get current user: HTTP {}", response.status),
                response.status,
            ));
        }
        let user = match response.body {
            body @ Value::Array(_) => body,
            _ => Value::Array(Array::new()),
        };
        *self.authenticated_user.lock().unwrap_or_else(|e| e.into_inner()) = Some(user.clone());
        Ok(user)
    }

    /// The web host with the credential as HTTP Basic userinfo.
    fn authenticated_url(&self) -> String {
        let Some(token) = self.access_token.as_deref().filter(|t| php::filled(t)) else {
            return self.bitbucket_url.clone();
        };
        let userinfo = match token.split_once(':') {
            Some((user, password)) => format!(
                "{}:{}",
                php_std::encoding::urlencode(user.as_bytes()),
                php_std::encoding::urlencode(password.as_bytes())
            ),
            None => format!("x-token-auth:{}", php_std::encoding::urlencode(token.as_bytes())),
        };
        self.bitbucket_url.replace("://", &format!("://{userinfo}@"))
    }

    async fn write_build_status(
        &self,
        owner: &str,
        repository_name: &str,
        commit_hash: &str,
        payload: Vec<(&str, Value)>,
    ) -> Result<Value> {
        let body: Array =
            payload.into_iter().filter(|(_, v)| !php::is_str(v, "")).map(|(k, v)| (ArrayKey::from(k), v)).collect();
        let response = self
            .call(
                Call::post(format!(
                    "/repositories/{owner}/{repository_name}/commit/{}/statuses/build",
                    php_std::encoding::rawurlencode(commit_hash.as_bytes())
                ))
                .params(Value::Array(body)),
            )
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to write check run: HTTP {}", response.status),
                response.status,
            ));
        }
        Ok(match response.body {
            body @ Value::Array(_) => body,
            _ => Value::Array(Array::new()),
        })
    }

    /// A build status as a check run, with `overrides` replacing fields.
    fn parse_check_run(
        &self,
        status: &Value,
        owner: &str,
        repository_name: &str,
        commit_hash: &str,
        overrides: Vec<(&str, Value)>,
    ) -> Value {
        let state = php::string(&or(at(status, "state"), ""));
        let (run_status, conclusion) = match state.as_slice() {
            b"INPROGRESS" => ("in_progress", Value::Null),
            b"SUCCESSFUL" => ("completed", s("success")),
            b"FAILED" => ("completed", s("failure")),
            b"STOPPED" => ("completed", s("cancelled")),
            _ => ("completed", Value::Null),
        };
        let commit_url = self.commit_url(owner, repository_name, commit_hash);
        let key = php::string(&or(at(status, "key"), ""));
        let completed = if matches!(conclusion, Value::Null) {
            Value::Null
        } else {
            s(php::string(&or(at(status, "updated_on"), "")))
        };
        let mut run = object([
            ("id", s(concat(&[commit_hash.as_bytes(), b":", &key]))),
            ("name", s(php::string(&or(at(status, "name"), "")))),
            ("status", s(run_status)),
            ("conclusion", conclusion),
            ("head_sha", s(commit_hash)),
            ("url", s(php::string(&or(path(status, &["links", "self", "href"]), commit_url.as_str())))),
            ("html_url", s(php::string(&or(at(status, "url"), commit_url.as_str())))),
            ("started_at", s(php::string(&or(at(status, "created_on"), "")))),
            ("completed_at", completed),
            (
                "output",
                object([
                    ("title", s("")),
                    ("summary", s(php::string(&or(at(status, "description"), "")))),
                    ("text", s("")),
                ]),
            ),
        ]);
        for (key, value) in overrides {
            php::set(&mut run, key, value);
        }
        run
    }
}

/// A check run id: `"<commit>:<key>"`.
fn split_check_run_id(check_run_id: &str) -> Result<(&str, &str)> {
    match check_run_id.split_once(':') {
        Some((commit, key)) if !commit.is_empty() && !key.is_empty() => Ok((commit, key)),
        _ => Err(Error::exception(format!("Check run {check_run_id} was not found"), 404)),
    }
}

/// The build state of a check run's conclusion.
fn check_run_state(conclusion: &str) -> &'static str {
    if !php::filled(conclusion) {
        return "INPROGRESS";
    }
    CHECK_RUN_CONCLUSION_MAP.iter().find(|(from, _)| *from == conclusion).map_or("FAILED", |(_, to)| to)
}

/// The workspace of a repository: `workspace.slug`, or the first segment of
/// `full_name`.
fn workspace_slug_of(repository: &Value) -> Vec<u8> {
    let slug = php::string(&or(path(repository, &["workspace", "slug"]), ""));
    if !slug.is_empty() && slug != b"0" {
        return slug;
    }
    let full_name = php::string(&or(at(repository, "full_name"), ""));
    if php::contains(&full_name, b"/") {
        full_name.split(|b| *b == b'/').next().unwrap_or_default().to_vec()
    } else {
        Vec::new()
    }
}

/// A repository with the keys the other adapters report: `id`
/// (`"workspace/slug"`), `private`, `pushed_at` and `workspace.slug`.
fn normalize_repository(mut repository: Value) -> Value {
    if !php::is_array(&repository) {
        repository = Value::Array(Array::new());
    }
    let id = s(php::string(&or(at(&repository, "full_name"), "")));
    php::set(&mut repository, "id", id);
    let private = php::identical(&or(at(&repository, "is_private"), false), &Value::Bool(true));
    php::set(&mut repository, "private", Value::Bool(private));
    let pushed = or(at(&repository, "updated_on"), "");
    php::set(&mut repository, "pushed_at", pushed);
    if empty(&path(&repository, &["workspace", "slug"])) {
        let slug = s(workspace_slug_of(&repository));
        php::set(&mut repository, "workspace", object([("slug", slug)]));
    }
    repository
}

/// An unlinked author's name from its raw `Name <email>`, or the linked
/// account's display name.
fn author_name_of(author: &Value) -> Value {
    let user = match at(author, "user") {
        u @ Value::Array(_) => u,
        _ => Value::Array(Array::new()),
    };
    let name = or(at(&user, "display_name"), "");
    if !empty(&name) {
        return s(php::string(&name));
    }
    let raw = php::string(&or(at(author, "raw"), ""));
    s(php_std::string::trim(&strip_angles(&raw), b" \t\n\r\0\x0B"))
}

/// `preg_replace('/<[^>]*>/', '', $raw)`.
fn strip_angles(raw: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(raw.len());
    let mut i = 0;
    while i < raw.len() {
        if raw[i] == b'<'
            && let Some(close) = raw[i + 1..].iter().position(|b| *b == b'>')
        {
            i += close + 2;
            continue;
        }
        out.push(raw[i]);
        i += 1;
    }
    out
}

/// `preg_match('/<([^>]*)>/', $raw)`'s group: what the first `<...>` holds.
fn angle_group(raw: &[u8]) -> Option<&[u8]> {
    let open = raw.iter().position(|b| *b == b'<')?;
    let close = raw[open + 1..].iter().position(|b| *b == b'>')?;
    Some(&raw[open + 1..open + 1 + close])
}

/// `preg_match('#/commit/([0-9a-f]+)#', $location)`'s group.
fn commit_in_location(location: &[u8]) -> Option<&[u8]> {
    let needle = b"/commit/";
    let mut from = 0;
    while let Some(found) = location[from..].windows(needle.len()).position(|w| w == needle) {
        let start = from + found + needle.len();
        let len = location[start..].iter().take_while(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(*b)).count();
        if len > 0 {
            return Some(&location[start..start + len]);
        }
        from += found + 1;
    }
    None
}

/// A commit in the shape every adapter reports.
fn parse_commit(commit: &Value) -> Value {
    let author = match at(commit, "author") {
        a @ Value::Array(_) => a,
        _ => Value::Array(Array::new()),
    };
    let user = match at(&author, "user") {
        u @ Value::Array(_) => u,
        _ => Value::Array(Array::new()),
    };
    let links = match at(&user, "links") {
        l @ Value::Array(_) => l,
        _ => Value::Array(Array::new()),
    };
    let name = author_name_of(&author);
    let author_name = if php::is_str(&name, "") || php::is_str(&name, "0") { s("Unknown") } else { name };
    object([
        ("commitAuthor", author_name),
        ("commitMessage", or(at(commit, "message"), "No message")),
        ("commitHash", or(at(commit, "hash"), "")),
        ("commitUrl", or(path(commit, &["links", "html", "href"]), "")),
        ("commitAuthorAvatar", or(path(&links, &["avatar", "href"]), "")),
        ("commitAuthorUrl", or(path(&links, &["html", "href"]), "")),
    ])
}

/// A pull request in the shape every adapter reports.
fn parse_pull_request(pull_request: &Value) -> Value {
    let source = match at(pull_request, "source") {
        v @ Value::Array(_) => v,
        _ => Value::Array(Array::new()),
    };
    let destination = match at(pull_request, "destination") {
        v @ Value::Array(_) => v,
        _ => Value::Array(Array::new()),
    };
    object([
        ("number", or(at(pull_request, "id"), Value::Int(0))),
        ("title", or(at(pull_request, "title"), "")),
        ("state", s(php::lower(&php::string(&or(at(pull_request, "state"), ""))))),
        (
            "head",
            object([
                ("ref", or(path(&source, &["branch", "name"]), "")),
                ("sha", or(path(&source, &["commit", "hash"]), "")),
            ]),
        ),
        ("base", object([("ref", or(path(&destination, &["branch", "name"]), ""))])),
    ])
}

/// An argument of a private method declared `array`.
fn expect_param_array<'a>(value: &'a Value, method: &str) -> Result<&'a Value> {
    match value {
        Value::Array(_) => Ok(value),
        other => Err(Error::type_error(format!(
            "{CLASS}::{method}(): Argument #1 must be of type array, {} given",
            php::value_name(other)
        ))),
    }
}

/// `str_replace(['\\', '"'], ['\\\\', '\\"'], $search)`: a value quoted in
/// Bitbucket's filter grammar.
fn escape_filter(search: &str) -> String {
    search.replace('\\', "\\\\").replace('"', "\\\"")
}

/// Whether `(string) $value` is neither `''` nor `'0'`.
fn filled_value(value: &Value) -> bool {
    let text = php::string(value);
    !text.is_empty() && text != b"0"
}

#[async_trait]
impl Adapter for Bitbucket {
    fn name(&self) -> &str {
        "bitbucket"
    }

    fn vcs_type(&self) -> VcsType {
        VcsType::Git
    }

    async fn initialize(
        &mut self,
        _installation_id: &str,
        _private_key: &str,
        _app_id: Option<&str>,
        access_token: Option<&str>,
        _refresh_token: Option<&str>,
    ) -> Result<()> {
        match access_token {
            Some(token) if php::filled(token) => {
                self.access_token = Some(token.to_owned());
                Ok(())
            }
            _ => Err(Error::exception("accessToken is required for this adapter.", 0)),
        }
    }

    async fn user(&self, username: &str) -> Result<Value> {
        let response = self.get(format!("/users/{}", php_std::encoding::rawurlencode(username.as_bytes()))).await?;
        if response.status >= 400 {
            return Err(Error::exception(format!("Failed to get user: HTTP {}", response.status), response.status));
        }
        let mut body = response.body;
        if !php::is_array(&body) || empty(&at(&body, "uuid")) {
            return Err(Error::exception(format!("User not found: {username}"), 0));
        }
        let uuid = at(&body, "uuid");
        php::set(&mut body, "id", uuid);
        if matches!(at(&body, "username"), Value::Null) {
            let nickname = or(at(&body, "nickname"), "");
            php::set(&mut body, "username", nickname);
        }
        Ok(body)
    }

    async fn owner_name(&self, _installation_id: &str, _repository_id: Option<i64>) -> Result<String> {
        let response = self.get("/user/workspaces").await?;
        if response.status < 400 {
            let values = if php::is_array(&response.body) {
                or(response.at("values"), Value::Array(Array::new()))
            } else {
                Value::Array(Array::new())
            };
            let slug = or(path(&php::at_index(&values, 0), &["workspace", "slug"]), "");
            if !empty(&slug) {
                return Ok(php::text(&php::string(&slug)));
            }
        }
        let user = self.authenticated_user().await?;
        Ok(php::text(&php::string(&or(at(&user, "username"), or(at(&user, "nickname"), "")))))
    }

    async fn has_access_to_all_repositories(&self) -> Result<bool> {
        Ok(true)
    }

    async fn search_repositories(&self, owner: &str, page: i64, per_page: i64, search: &str) -> Result<Value> {
        let mut url = format!("/repositories/{owner}?page={page}&pagelen={per_page}");
        if php::filled(search) {
            let filter = format!("name~\"{}\"", escape_filter(search));
            url.push_str(&format!("&q={}", php_std::encoding::urlencode(filter.as_bytes())));
        }
        let response = self.get(url).await?;
        let empty_result = || object([("items", Value::Array(Array::new())), ("total", Value::Int(0))]);
        if response.status >= 400 || !php::is_array(&response.body) {
            return Ok(empty_result());
        }
        let mut repositories = Vec::new();
        for repository in each(&or(response.at("values"), Value::Array(Array::new()))) {
            let repository = normalize_repository(repository);
            repositories.push(object([
                ("id", at(&repository, "id")),
                ("name", or(at(&repository, "name"), "")),
                ("description", or(at(&repository, "description"), "")),
                ("private", at(&repository, "private")),
                ("pushed_at", at(&repository, "pushed_at")),
            ]));
        }
        let total = php::int(&or(response.at("size"), Value::Int(repositories.len() as i64)));
        Ok(object([("items", php::list(repositories)), ("total", Value::Int(total))]))
    }

    async fn installation_repository(&self, _repository_name: &str) -> Result<Value> {
        Err(Error::exception("getInstallationRepository is not applicable for this adapter", 0))
    }

    async fn repository(&self, owner: &str, repository_name: &str) -> Result<Value> {
        let response = self.get(format!("/repositories/{owner}/{repository_name}")).await?;
        if response.status >= 400 {
            return Err(Error::RepositoryNotFound("Repository not found".into()));
        }
        Ok(normalize_repository(response.body))
    }

    async fn create_repository(&self, owner: &str, repository_name: &str, private: bool) -> Result<Value> {
        let response = self
            .call(Call::post(format!("/repositories/{owner}/{repository_name}")).params(object([
                ("scm", s("git")),
                ("name", s(repository_name)),
                ("is_private", Value::Bool(private)),
            ])))
            .await?;
        if response.status >= 400 {
            let error = or(path(&response.body, &["error", "message"]), "");
            let mut message =
                format!("Creating repository {repository_name} failed with status code {}", response.status)
                    .into_bytes();
            if !php::is_str(&error, "") {
                message.extend_from_slice(b": ");
                message.extend_from_slice(&php::string(&error));
            }
            return Err(Error::exception(message, response.status));
        }
        Ok(normalize_repository(response.body))
    }

    async fn delete_repository(&self, owner: &str, repository_name: &str) -> Result<bool> {
        let response = self.call(Call::new(Method::Delete, format!("/repositories/{owner}/{repository_name}"))).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Deleting repository {repository_name} failed with status code {}", response.status),
                response.status,
            ));
        }
        Ok(true)
    }

    async fn pull_request_from_branch(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value> {
        let query = php_std::encoding::urlencode(format!("source.branch.name=\"{branch}\"").as_bytes());
        let response =
            self.get(format!("/repositories/{owner}/{repository_name}/pullrequests?state=OPEN&q={query}")).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to list pull requests: HTTP {}", response.status),
                response.status,
            ));
        }
        let values = if php::is_array(&response.body) {
            or(response.at("values"), Value::Array(Array::new()))
        } else {
            Value::Array(Array::new())
        };
        let first = php::at_index(&values, 0);
        if empty(&first) {
            return Ok(Value::Array(Array::new()));
        }
        Ok(parse_pull_request(expect_param_array(&first, "parsePullRequest")?))
    }

    async fn pull_request(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        let response = self.get(format!("/repositories/{owner}/{repository_name}/pullrequests/{number}")).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to get pull request: HTTP {}", response.status),
                response.status,
            ));
        }
        let body = if php::is_array(&response.body) { response.body } else { Value::Array(Array::new()) };
        Ok(parse_pull_request(&body))
    }

    async fn pull_request_files(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        let mut files = Vec::new();
        let mut page = 1;
        loop {
            let response = self
                .get(format!(
                    "/repositories/{owner}/{repository_name}/pullrequests/{number}/diffstat?pagelen={PAGE_SIZE}&page={page}"
                ))
                .await?;
            if response.status >= 400 {
                return Err(Error::exception(
                    format!("Failed to get pull request files: HTTP {}", response.status),
                    response.status,
                ));
            }
            if !php::is_array(&response.body) {
                break;
            }
            let values = or(response.at("values"), Value::Array(Array::new()));
            let Value::Array(diffs) = &values else { break };
            for diff in diffs.values() {
                let new = match at(diff, "new") {
                    v @ Value::Array(_) => v,
                    _ => Value::Array(Array::new()),
                };
                let old = match at(diff, "old") {
                    v @ Value::Array(_) => v,
                    _ => Value::Array(Array::new()),
                };
                files.push(object([("filename", or(or(at(&new, "path"), at(&old, "path")), ""))]));
            }
            page += 1;
            if empty(&response.at("next")) {
                break;
            }
        }
        Ok(php::list(files))
    }

    async fn create_comment(&self, owner: &str, repository_name: &str, number: i64, comment: &str) -> Result<String> {
        let response = self
            .call(
                Call::post(format!("/repositories/{owner}/{repository_name}/pullrequests/{number}/comments"))
                    .params(object([("content", object([("raw", s(comment))]))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to create comment: HTTP {}", response.status),
                response.status,
            ));
        }
        let has_id = matches!(&response.body, Value::Array(a) if a.contains_key(&ArrayKey::from("id")));
        if !has_id {
            return Err(Error::exception("Comment creation response is missing comment ID.", 0));
        }
        Ok(php::text(&concat(&[number.to_string().as_bytes(), b":", &php::string(&response.at("id"))])))
    }

    async fn comment(&self, owner: &str, repository_name: &str, comment_id: &str) -> Result<String> {
        let Some((number, id)) = comment_id.split_once(':') else { return Ok(String::new()) };
        let response =
            self.get(format!("/repositories/{owner}/{repository_name}/pullrequests/{number}/comments/{id}")).await?;
        php::return_string(or(path(&response.body, &["content", "raw"]), ""), &format!("{CLASS}::getComment"))
    }

    async fn update_comment(
        &self,
        owner: &str,
        repository_name: &str,
        comment_id: &str,
        comment: &str,
    ) -> Result<String> {
        let Some((number, id)) = comment_id.split_once(':') else {
            return Err(Error::exception(format!("Invalid comment ID format: {comment_id}"), 0));
        };
        let response = self
            .call(
                Call::new(
                    Method::Put,
                    format!("/repositories/{owner}/{repository_name}/pullrequests/{number}/comments/{id}"),
                )
                .params(object([("content", object([("raw", s(comment))]))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to update comment: HTTP {}", response.status),
                response.status,
            ));
        }
        Ok(comment_id.to_owned())
    }

    fn clone_command(
        &self,
        owner: &str,
        repository_name: &str,
        version: &str,
        version_type: &str,
        directory: &str,
        root_directory: &str,
    ) -> Result<Command> {
        let clone_url = format!("{}/{owner}/{repository_name}.git", self.authenticated_url());
        clone_command(&clone_url, version, version_type, directory, root_directory)
    }

    fn validate_webhook_event(&self, payload: &[u8], signature: &[u8], signature_key: &[u8]) -> bool {
        webhook::hmac_matches(b"sha256=", payload, signature, signature_key)
    }

    fn events(&self, event: &str, payload: &[u8]) -> Result<Vec<Value>> {
        let payload = match php::json_decode(payload) {
            Some(v @ Value::Array(_)) => v,
            _ => return Err(Error::exception("Invalid payload.", 0)),
        };
        let repository = match at(&payload, "repository") {
            v @ Value::Array(_) => v,
            _ => Value::Array(Array::new()),
        };
        let actor = match at(&payload, "actor") {
            v @ Value::Array(_) => v,
            _ => Value::Array(Array::new()),
        };
        let actor_links = match at(&actor, "links") {
            v @ Value::Array(_) => v,
            _ => Value::Array(Array::new()),
        };
        let owner = s(workspace_slug_of(&repository));
        let repository_url = php::string(&or(path(&repository, &["links", "html", "href"]), ""));
        let repository_url_filled = !repository_url.is_empty() && repository_url != b"0";

        match event {
            "repo:push" => {
                let mut events = Vec::new();
                for change in each(&or(path(&payload, &["push", "changes"]), Value::Array(Array::new()))) {
                    if !php::is_array(&change) {
                        continue;
                    }
                    let kind = or(path(&change, &["new", "type"]), or(path(&change, &["old", "type"]), "branch"));
                    if !php::is_str(&kind, "branch") && !php::is_str(&kind, "named_branch") {
                        continue;
                    }
                    let new = match at(&change, "new") {
                        v @ Value::Array(_) => v,
                        _ => Value::Array(Array::new()),
                    };
                    let old = match at(&change, "old") {
                        v @ Value::Array(_) => v,
                        _ => Value::Array(Array::new()),
                    };
                    let branch = or(at(&new, "name"), or(at(&old, "name"), ""));
                    let target = match at(&new, "target") {
                        v @ Value::Array(_) => v,
                        _ => Value::Array(Array::new()),
                    };
                    let author = match at(&target, "author") {
                        v @ Value::Array(_) => v,
                        _ => Value::Array(Array::new()),
                    };
                    let raw = php::string(&or(at(&author, "raw"), ""));
                    let email = angle_group(&raw).map(<[u8]>::to_vec).unwrap_or_default();
                    let branch_url = if repository_url_filled && !empty(&branch) {
                        s(concat(&[&repository_url, b"/branch/", &php::string(&branch)]))
                    } else {
                        s("")
                    };
                    events.push(object([
                        (
                            "branchCreated",
                            Value::Bool(php::identical(&or(at(&change, "created"), false), &Value::Bool(true))),
                        ),
                        (
                            "branchDeleted",
                            Value::Bool(php::identical(&or(at(&change, "closed"), false), &Value::Bool(true))),
                        ),
                        ("branch", branch),
                        ("branchUrl", branch_url),
                        ("repositoryId", s(php::string(&or(at(&repository, "full_name"), "")))),
                        ("repositoryName", or(at(&repository, "name"), "")),
                        ("repositoryUrl", s(repository_url.clone())),
                        ("installationId", s("")),
                        ("commitHash", or(at(&target, "hash"), "")),
                        ("owner", owner.clone()),
                        ("authorUrl", or(path(&actor_links, &["html", "href"]), "")),
                        ("authorAvatarUrl", or(path(&actor_links, &["avatar", "href"]), "")),
                        ("headCommitAuthorName", author_name_of(&author)),
                        ("headCommitAuthorEmail", s(email)),
                        ("headCommitMessage", or(at(&target, "message"), "")),
                        ("headCommitUrl", or(path(&target, &["links", "html", "href"]), "")),
                        ("external", Value::Bool(false)),
                        ("pullRequestNumber", s("")),
                        ("action", s("")),
                        ("affectedFiles", Value::Array(Array::new())),
                    ]));
                }
                Ok(events)
            }
            "pullrequest:created" | "pullrequest:updated" | "pullrequest:fulfilled" | "pullrequest:rejected" => {
                let pull_request = match at(&payload, "pullrequest") {
                    v @ Value::Array(_) => v,
                    _ => Value::Array(Array::new()),
                };
                let source = match at(&pull_request, "source") {
                    v @ Value::Array(_) => v,
                    _ => Value::Array(Array::new()),
                };
                let destination = match at(&pull_request, "destination") {
                    v @ Value::Array(_) => v,
                    _ => Value::Array(Array::new()),
                };
                let branch = or(path(&source, &["branch", "name"]), "");
                let commit_hash = or(path(&source, &["commit", "hash"]), "");
                let source_id = path(&source, &["repository", "uuid"]);
                let destination_id = path(&destination, &["repository", "uuid"]);
                let external = !matches!(source_id, Value::Null)
                    && !matches!(destination_id, Value::Null)
                    && !php::identical(&source_id, &destination_id);
                let branch_url = if repository_url_filled && !empty(&branch) {
                    s(concat(&[&repository_url, b"/branch/", &php::string(&branch)]))
                } else {
                    s("")
                };
                let head_commit_url = if repository_url_filled && !empty(&commit_hash) {
                    s(concat(&[&repository_url, b"/commits/", &php::string(&commit_hash)]))
                } else {
                    s("")
                };
                let action = lookup(&PULL_REQUEST_ACTION_MAP, event.as_bytes()).unwrap_or_default();
                Ok(vec![object([
                    ("branch", branch),
                    ("branchUrl", branch_url),
                    ("repositoryId", s(php::string(&or(at(&repository, "full_name"), "")))),
                    ("repositoryName", or(at(&repository, "name"), "")),
                    ("repositoryUrl", s(repository_url)),
                    ("installationId", s("")),
                    ("commitHash", commit_hash),
                    ("owner", owner),
                    ("authorUrl", or(path(&actor_links, &["html", "href"]), "")),
                    ("authorAvatarUrl", or(path(&actor_links, &["avatar", "href"]), "")),
                    ("headCommitUrl", head_commit_url),
                    ("external", Value::Bool(external)),
                    ("pullRequestNumber", or(at(&pull_request, "id"), "")),
                    ("action", s(action)),
                ])])
            }
            _ => Ok(Vec::new()),
        }
    }

    fn event_header_name(&self) -> &str {
        "x-event-key"
    }

    fn signature_header_name(&self) -> &str {
        "x-hub-signature"
    }

    fn supported_webhook_scopes(&self) -> &'static [WebhookScope] {
        &[WebhookScope::Repository]
    }

    fn repository_url(&self, owner: &str, repository_name: &str) -> String {
        format!("{}/{owner}/{repository_name}", self.bitbucket_url)
    }

    fn organization_url(&self, owner: &str) -> Result<String> {
        Ok(format!("{}/{owner}", self.bitbucket_url))
    }

    fn branch_url(&self, owner: &str, repository_name: &str, branch: &str) -> String {
        format!("{}/branch/{branch}", self.repository_url(owner, repository_name))
    }

    fn commit_url(&self, owner: &str, repository_name: &str, commit_hash: &str) -> String {
        format!("{}/commits/{commit_hash}", self.repository_url(owner, repository_name))
    }

    fn file_url(&self, owner: &str, repository_name: &str, reference: &str) -> String {
        format!("{}/src/{reference}", self.repository_url(owner, repository_name))
    }

    async fn repository_name(&self, repository_id: &str) -> Result<String> {
        let id = php::text(&php_std::encoding::rawurldecode(repository_id.as_bytes()));
        let Some((workspace, slug)) = id.split_once('/') else {
            return Err(Error::RepositoryNotFound(format!("Repository {id} not found")));
        };
        let repository = self.repository(workspace, slug).await?;
        Ok(php::text(&php::string(&or(at(&repository, "slug"), slug))))
    }

    async fn list_branches(&self, owner: &str, repository_name: &str) -> Result<Vec<Value>> {
        self.list_refs(owner, repository_name, "branches").await
    }

    async fn list_tags(&self, owner: &str, repository_name: &str, search: &str) -> Result<Vec<Value>> {
        git::match_glob(self.list_refs(owner, repository_name, "tags").await?, search)
    }

    async fn update_commit_status(
        &self,
        repository_name: &str,
        sha: &str,
        owner: &str,
        state: &str,
        description: &str,
        target_url: &str,
        context: &str,
    ) -> Result<()> {
        // Bitbucket keys a status and overwrites one posted under a key it has.
        let key = if php::filled(context) { context } else { self.name() };
        let state = lookup(&COMMIT_STATE_MAP, state.as_bytes()).unwrap_or(state);
        let url =
            if php::filled(target_url) { target_url.to_owned() } else { self.commit_url(owner, repository_name, sha) };
        let mut payload = object([("key", s(key)), ("name", s(key)), ("state", s(state)), ("url", s(url))]);
        if php::filled(description) {
            php::set(&mut payload, "description", s(description));
        }
        let response = self
            .call(
                Call::post(format!(
                    "/repositories/{owner}/{repository_name}/commit/{}/statuses/build",
                    php_std::encoding::rawurlencode(sha.as_bytes())
                ))
                .params(payload),
            )
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to update commit status: HTTP {}", response.status),
                response.status,
            ));
        }
        Ok(())
    }

    async fn create_check_run(
        &self,
        owner: &str,
        repository_name: &str,
        head_sha: &str,
        run: &CheckRun,
    ) -> Result<Value> {
        let (status, completed_at) = settle(run)?;
        let mut random = [0u8; 8];
        getrandom::fill(&mut random).map_err(|e| Error::exception(format!("random_bytes(): {e}"), 0))?;
        let key = format!("check-run-{}", webhook::hex(&random));
        let url = if php::filled(&run.details_url) {
            run.details_url.clone()
        } else {
            self.commit_url(owner, repository_name, head_sha)
        };
        let written = self
            .write_build_status(
                owner,
                repository_name,
                head_sha,
                vec![
                    ("key", s(&key)),
                    ("name", s(&run.name)),
                    ("state", s(check_run_state(&run.conclusion))),
                    ("url", s(url)),
                    ("description", s(&run.summary)),
                ],
            )
            .await?;
        let started =
            if php::filled(&run.started_at) { s(&run.started_at) } else { or(at(&written, "created_on"), Value::Null) };
        Ok(self.parse_check_run(
            &written,
            owner,
            repository_name,
            head_sha,
            vec![
                ("status", s(&status)),
                ("conclusion", if run.conclusion.is_empty() { Value::Null } else { s(&run.conclusion) }),
                ("output", object([("title", s(&run.title)), ("summary", s(&run.summary)), ("text", s(&run.text))])),
                ("started_at", started),
                ("completed_at", if empty(&s(&completed_at)) { Value::Null } else { s(&completed_at) }),
            ],
        ))
    }

    async fn check_run(&self, owner: &str, repository_name: &str, check_run_id: &str) -> Result<Value> {
        let (commit, key) = split_check_run_id(check_run_id)?;
        let response = self
            .get(format!(
                "/repositories/{owner}/{repository_name}/commit/{}/statuses/build/{}",
                php_std::encoding::rawurlencode(commit.as_bytes()),
                php_std::encoding::rawurlencode(key.as_bytes())
            ))
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to get check run {check_run_id}: HTTP {}", response.status),
                response.status,
            ));
        }
        let body = if php::is_array(&response.body) { response.body } else { Value::Array(Array::new()) };
        Ok(self.parse_check_run(&body, owner, repository_name, commit, Vec::new()))
    }

    async fn update_check_run(
        &self,
        owner: &str,
        repository_name: &str,
        check_run_id: &str,
        run: &CheckRun,
    ) -> Result<Value> {
        let (commit, key) = split_check_run_id(check_run_id)?;
        let (status, completed_at) = settle(run)?;
        // A repost rewrites every field: what the caller left out is read back first.
        let current = self.check_run(owner, repository_name, check_run_id).await?;
        let name = if php::filled(&run.name) { s(&run.name) } else { at(&current, "name") };
        let url = if php::filled(&run.details_url) { s(&run.details_url) } else { at(&current, "html_url") };
        let description =
            if php::filled(&run.summary) { s(&run.summary) } else { or(path(&current, &["output", "summary"]), "") };
        let written = self
            .write_build_status(
                owner,
                repository_name,
                commit,
                vec![
                    ("key", s(key)),
                    ("name", name),
                    ("state", s(check_run_state(&run.conclusion))),
                    ("url", url),
                    ("description", description),
                ],
            )
            .await?;
        Ok(self.parse_check_run(
            &written,
            owner,
            repository_name,
            commit,
            vec![
                ("status", if empty(&s(&status)) { at(&current, "status") } else { s(&status) }),
                ("conclusion", if run.conclusion.is_empty() { at(&current, "conclusion") } else { s(&run.conclusion) }),
                ("output", object([("title", s(&run.title)), ("summary", s(&run.summary)), ("text", s(&run.text))])),
                (
                    "completed_at",
                    if empty(&s(&completed_at)) { at(&current, "completed_at") } else { s(&completed_at) },
                ),
            ],
        ))
    }

    async fn repository_tree(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
        recursive: bool,
    ) -> Result<Vec<Value>> {
        let suffix = if recursive { format!("&max_depth={MAX_TREE_DEPTH}") } else { String::new() };
        let items = self.list_source(owner, repository_name, "", branch, &suffix).await?;
        php::array_column(&items, "path")
    }

    /// The repository's one manually set `language`, when it has one.
    async fn list_repository_languages(&self, owner: &str, repository_name: &str) -> Result<Vec<Value>> {
        let repository = match self.repository(owner, repository_name).await {
            Ok(r) => r,
            Err(Error::RepositoryNotFound(_)) => return Ok(Vec::new()),
            Err(e) => return Err(e),
        };
        let language = php::string(&or(at(&repository, "language"), ""));
        if language.is_empty() || language == b"0" { Ok(Vec::new()) } else { Ok(vec![s(language)]) }
    }

    async fn list_repository_contents(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        reference: &str,
    ) -> Result<Vec<Value>> {
        let items = self.list_source(owner, repository_name, path, reference, "").await?;
        Ok(each(&items)
            .iter()
            .map(|item| {
                let item_path = php::string(&or(at(item, "path"), ""));
                object([
                    ("name", s(php_std::path::basename(&item_path, b""))),
                    ("size", or(at(item, "size"), Value::Int(0))),
                    (
                        "type",
                        s(if php::is_str(&or(at(item, "type"), ""), "commit_directory") { "dir" } else { "file" }),
                    ),
                ])
            })
            .collect())
    }

    /// A file's raw content, with the blob hash git stores for it (Bitbucket
    /// reports none).
    async fn repository_content(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        reference: &str,
    ) -> Result<Value> {
        let wrap = |e: Error| -> Error {
            if e.is_exception() { Error::FileNotFound { message: e.message().into(), code: e.code() } } else { e }
        };
        let url = self.source_url(owner, repository_name, path, reference).await.map_err(wrap)?;
        let meta = self.get(format!("{url}?format=meta")).await.map_err(wrap)?;
        if meta.status != 200 {
            return Err(Error::file_not_found());
        }
        if !php::is_array(&meta.body) || !php::is_str(&or(meta.at("type"), ""), "commit_file") {
            return Err(Error::file_not_found());
        }
        let content = match self.call(Call::get(url).raw()).await {
            Ok(r) => r,
            Err(e) if e.is_exception() => return Err(Error::file_not_found()),
            Err(e) => return Err(e),
        };
        if content.status != 200 {
            return Err(Error::file_not_found());
        }
        let bytes = match &content.body {
            Value::Str(b) => b.as_bytes().to_vec(),
            _ => Vec::new(),
        };
        let mut blob = format!("blob {}\0", bytes.len()).into_bytes();
        blob.extend_from_slice(&bytes);
        let sha = webhook::hex(&Sha1::digest(&blob));
        Ok(object([("sha", s(sha)), ("size", Value::Int(bytes.len() as i64)), ("content", s(bytes))]))
    }

    async fn commit(&self, owner: &str, repository_name: &str, commit_hash: &str) -> Result<Value> {
        let response = self
            .get(format!(
                "/repositories/{owner}/{repository_name}/commit/{}",
                php_std::encoding::rawurlencode(commit_hash.as_bytes())
            ))
            .await?;
        if response.status >= 400 {
            return Err(Error::exception("Commit not found or inaccessible", 0));
        }
        let body = if php::is_array(&response.body) { response.body } else { Value::Array(Array::new()) };
        Ok(parse_commit(&body))
    }

    async fn latest_commit(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value> {
        let branch = self.resolve_ref(owner, repository_name, branch).await?;
        let response = self
            .get(format!(
                "/repositories/{owner}/{repository_name}/commits/{}?pagelen=1",
                php_std::encoding::rawurlencode(branch.as_bytes())
            ))
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to get latest commit: HTTP {}", response.status),
                response.status,
            ));
        }
        let values = if php::is_array(&response.body) {
            or(response.at("values"), Value::Array(Array::new()))
        } else {
            Value::Array(Array::new())
        };
        let first = php::at_index(&values, 0);
        if empty(&first) {
            return Err(Error::exception("Latest commit response is missing required information.", 0));
        }
        Ok(parse_commit(expect_param_array(&first, "parseCommit")?))
    }

    /// The archive URL (the credential travels as a header, see
    /// [`Git::repository_presigned_url_headers`]); an empty ref is `HEAD`.
    async fn repository_presigned_url(
        &self,
        owner: &str,
        repository_name: &str,
        reference: &str,
        format: &str,
    ) -> Result<String> {
        let extension = match format {
            "tarball" => "tar.gz",
            "zipball" => "zip",
            other => {
                return Err(Error::exception(
                    format!("Invalid archive format: {other}. Use 'tarball' or 'zipball'."),
                    0,
                ));
            }
        };
        let reference = if php::filled(reference) { reference } else { "HEAD" };
        let resolved = self.resolve_ref(owner, repository_name, reference).await?;
        Ok(format!(
            "{}/{owner}/{repository_name}/get/{}.{extension}",
            self.bitbucket_url,
            php_std::encoding::rawurlencode(resolved.as_bytes())
        ))
    }
}

#[async_trait]
impl Git for Bitbucket {
    async fn create_file(
        &self,
        owner: &str,
        repository_name: &str,
        filepath: &str,
        content: &[u8],
        message: &str,
        branch: &str,
    ) -> Result<Value> {
        let branch =
            if php::filled(branch) { branch.to_owned() } else { self.main_branch_name(owner, repository_name).await? };
        // Paths travel as form field names; a leading slash keeps one called
        // e.g. `message` from reading as commit metadata.
        let mut form = vec![
            (b"message".to_vec(), message.as_bytes().to_vec()),
            (format!("/{}", normalize_repository_path(filepath)).into_bytes(), content.to_vec()),
        ];
        if php::filled(&branch) {
            form.push((b"branch".to_vec(), branch.as_bytes().to_vec()));
        }
        let mut call = Call::post(format!("/repositories/{owner}/{repository_name}/src"));
        call.params = Params::Form(form);
        let response = self.call(call).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to create file {filepath}: HTTP {}", response.status),
                response.status,
            ));
        }
        let location = response.header("location").unwrap_or("").as_bytes().to_vec();
        let commit_hash = commit_in_location(&location).map(<[u8]>::to_vec).unwrap_or_default();
        Ok(object([("path", s(filepath)), ("branch", s(&branch)), ("commitHash", s(commit_hash))]))
    }

    async fn create_branch(
        &self,
        owner: &str,
        repository_name: &str,
        new_branch: &str,
        old_branch: &str,
    ) -> Result<Value> {
        let response = self
            .call(
                Call::post(format!("/repositories/{owner}/{repository_name}/refs/branches"))
                    .params(object([("name", s(new_branch)), ("target", object([("hash", s(old_branch))]))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to create branch {new_branch}: HTTP {}", response.status),
                response.status,
            ));
        }
        Ok(or(response.body, Value::Array(Array::new())))
    }

    async fn create_pull_request(
        &self,
        owner: &str,
        repository_name: &str,
        title: &str,
        head: &str,
        base: &str,
        body: &str,
    ) -> Result<Value> {
        let mut payload = object([
            ("title", s(title)),
            ("source", object([("branch", object([("name", s(head))]))])),
            ("destination", object([("branch", object([("name", s(base))]))])),
        ]);
        if php::filled(body) {
            php::set(&mut payload, "description", s(body));
        }
        let response = self
            .call(Call::post(format!("/repositories/{owner}/{repository_name}/pullrequests")).params(payload))
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to create pull request: HTTP {}", response.status),
                response.status,
            ));
        }
        let mut body = if php::is_array(&response.body) { response.body } else { Value::Array(Array::new()) };
        let number = or(at(&body, "id"), Value::Int(0));
        php::set(&mut body, "number", number);
        Ok(body)
    }

    async fn create_webhook(
        &self,
        owner: &str,
        repository_name: &str,
        url: &str,
        secret: &str,
        events: &[&str],
    ) -> Result<WebhookId> {
        let mut keys: Vec<String> = Vec::new();
        for event in events {
            if event.contains(':') {
                keys.push((*event).to_owned());
                continue;
            }
            match *event {
                "push" => keys.push("repo:push".into()),
                "pull_request" => keys.extend(PULL_REQUEST_ACTION_MAP.iter().map(|(k, _)| (*k).to_owned())),
                _ => {}
            }
        }
        let mut unique: Vec<String> = Vec::new();
        for key in keys {
            if !unique.contains(&key) {
                unique.push(key);
            }
        }
        let mut payload = object([
            ("description", s("utopia")),
            ("url", s(url)),
            ("active", Value::Bool(true)),
            ("events", php::list(unique.iter().map(s).collect())),
        ]);
        if php::filled(secret) {
            php::set(&mut payload, "secret", s(secret));
        }
        let response =
            self.call(Call::post(format!("/repositories/{owner}/{repository_name}/hooks")).params(payload)).await?;
        if response.status >= 400 {
            let body = php::json_encode(&response.body).unwrap_or_default();
            return Err(Error::exception(
                format!("Failed to create webhook: HTTP {} - {body}", response.status),
                response.status,
            ));
        }
        let uuid = path(&response.body, &["uuid"]);
        if matches!(uuid, Value::Null) || php::is_str(&uuid, "") {
            return Err(Error::exception("Webhook created but response did not include a uuid", 0));
        }
        Ok(WebhookId::Str(php::text(&php::string(&uuid))))
    }

    async fn create_tag(
        &self,
        owner: &str,
        repository_name: &str,
        tag: &str,
        target: &str,
        message: &str,
    ) -> Result<Value> {
        let mut payload = object([("name", s(tag)), ("target", object([("hash", s(target))]))]);
        if php::filled(message) {
            php::set(&mut payload, "message", s(message));
        }
        let response =
            self.call(Call::post(format!("/repositories/{owner}/{repository_name}/refs/tags")).params(payload)).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to create tag {tag}: HTTP {}", response.status),
                response.status,
            ));
        }
        Ok(or(response.body, Value::Array(Array::new())))
    }

    fn repository_presigned_url_headers(&self) -> Vec<(String, String)> {
        match self.authorization() {
            Ok(authorization) => vec![("Authorization".into(), authorization)],
            Err(_) => Vec::new(),
        }
    }

    /// Every workspace reports as a group: nothing tells a personal one apart.
    async fn list_namespaces(&self, page: i64, per_page: i64, search: &str) -> Result<Value> {
        let mut url = format!("/user/workspaces?page={page}&pagelen={per_page}");
        if php::filled(search) {
            let filter = format!("slug~\"{}\"", escape_filter(search));
            url.push_str(&format!("&q={}", php_std::encoding::urlencode(filter.as_bytes())));
        }
        let response = self.get(url).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to list namespaces: HTTP {}", response.status),
                response.status,
            ));
        }
        if !php::is_array(&response.body) {
            return Ok(object([("items", Value::Array(Array::new())), ("total", Value::Int(0))]));
        }
        let mut namespaces = Vec::new();
        for entry in each(&or(response.at("values"), Value::Array(Array::new()))) {
            let workspace = match at(&entry, "workspace") {
                w @ Value::Array(_) if php::is_array(&entry) => w,
                _ => Value::Array(Array::new()),
            };
            let slug = php::string(&or(at(&workspace, "slug"), ""));
            if slug.is_empty() {
                continue;
            }
            let uuid = php::string(&or(at(&workspace, "uuid"), ""));
            namespaces.push(object([
                ("id", s(if uuid.is_empty() { slug.clone() } else { uuid })),
                ("name", s(php::string(&or(at(&workspace, "name"), s(&slug))))),
                ("path", s(&slug)),
                ("kind", s("group")),
                ("avatarUrl", s(php::string(&or(path(&workspace, &["links", "avatar", "href"]), "")))),
            ]));
        }
        let total = php::int(&or(response.at("size"), Value::Int(namespaces.len() as i64)));
        Ok(object([("items", php::list(namespaces)), ("total", Value::Int(total))]))
    }

    async fn commit_statuses(&self, owner: &str, repository_name: &str, commit_hash: &str) -> Result<Vec<Value>> {
        let response = self
            .get(format!(
                "/repositories/{owner}/{repository_name}/commit/{}/statuses?pagelen={PAGE_SIZE}",
                php_std::encoding::rawurlencode(commit_hash.as_bytes())
            ))
            .await?;
        if response.status >= 400 || !php::is_array(&response.body) {
            return Ok(Vec::new());
        }
        Ok(each(&or(response.at("values"), Value::Array(Array::new())))
            .iter()
            .map(|status| {
                let state = php::string(&or(at(status, "state"), ""));
                let mapped = lookup(&COMMIT_STATE_MAP_REVERSE, &state).map(s).unwrap_or_else(|| s(&state));
                object([
                    ("state", mapped),
                    ("description", or(at(status, "description"), "")),
                    ("target_url", or(at(status, "url"), "")),
                    ("context", or(at(status, "key"), "")),
                ])
            })
            .collect())
    }
}

#[allow(dead_code)]
fn unused(value: &Value) -> bool {
    filled_value(value)
}
