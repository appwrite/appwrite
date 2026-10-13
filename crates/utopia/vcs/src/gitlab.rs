//! GitLab, with a personal, group or OAuth access token.

use std::sync::Arc;

use async_trait::async_trait;
use php_std::{Array, ArrayKey, Value};
use utopia_cache::Cache;
use utopia_console::Command;

use crate::adapter::{Adapter, CheckRun, Git, VcsType, WebhookId, WebhookScope, unsupported};
use crate::error::{Error, Result};
use crate::git::{self, clone_command, normalize_repository_path};
use crate::github::concat_if_both;
use crate::php::{self, at, each, empty, object, or, path, s};
use crate::transport::{Call, Method, Response, Transport};

/// The class PHP names in errors about this adapter's state.
const CLASS: &str = "Utopia\\VCS\\Adapter\\Git\\GitLab";

/// GitLab's merge request actions as the GitHub verbs consumers key off of;
/// a merge closes the merge request too.
const MERGE_REQUEST_ACTIONS: [(&str, &str); 5] =
    [("open", "opened"), ("reopen", "reopened"), ("update", "synchronize"), ("close", "closed"), ("merge", "closed")];

/// The GitLab adapter. Owners are group or user paths; a group created by
/// [`GitLab::create_organization`] is carried as `"<id>:<path>"`, so both
/// the namespace id and the path are at hand.
///
/// PHP: `Utopia\VCS\Adapter\Git\GitLab`.
pub struct GitLab {
    #[allow(dead_code)]
    cache: Arc<Cache>,
    transport: Transport,
    gitlab_url: String,
    access_token: Option<String>,
}

impl GitLab {
    /// An adapter on `http://gitlab:80` (see [`GitLab::set_endpoint`]).
    pub fn new(cache: Arc<Cache>) -> Self {
        GitLab {
            cache,
            transport: Transport::new("http://gitlab:80/api/v4"),
            gitlab_url: "http://gitlab:80".into(),
            access_token: None,
        }
    }

    /// Points the adapter at a GitLab instance (its web URL; the API is
    /// under `/api/v4`).
    ///
    /// PHP: `setEndpoint()`.
    pub fn set_endpoint(&mut self, endpoint: &str) {
        self.gitlab_url = endpoint.trim_end_matches('/').to_owned();
        self.transport.endpoint = format!("{}/api/v4", self.gitlab_url);
    }

    fn token(&self) -> Result<&str> {
        self.access_token.as_deref().ok_or_else(|| {
            Error::Engine(php_std::EngineError::new(
                "Error",
                format!("Typed property {CLASS}::$accessToken must not be accessed before initialization"),
            ))
        })
    }

    async fn call(&self, call: Call) -> Result<Response> {
        let token = self.token()?;
        self.transport.call(call.auth(format!("Bearer {token}"))).await
    }

    async fn get(&self, path: impl Into<String>) -> Result<Response> {
        self.call(Call::get(path)).await
    }

    /// Creates a public group and returns it as `"<id>:<path>"`.
    ///
    /// PHP: `createOrganization()`.
    pub async fn create_organization(&self, name: &str) -> Result<String> {
        let response = self
            .call(Call::post("/groups").params(object([
                ("name", s(name)),
                ("path", s(name)),
                ("visibility", s("public")),
            ])))
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Creating organization {name} failed with status code {}", response.status),
                response.status,
            ));
        }
        let id = php::string(&or(response.at("id"), ""));
        let group = php::string(&or(response.at("path"), ""));
        Ok(php::text(&[id.as_slice(), b":", group.as_slice()].concat()))
    }
}

/// The path of an owner given as `"<id>:<path>"`, or the owner itself.
fn owner_path(owner: &str) -> &str {
    owner.split_once(':').map_or(owner, |(_, path)| path)
}

/// The namespace id of an owner given as `"<id>:<path>"`, or the owner.
fn namespace_id(owner: &str) -> &str {
    owner.split_once(':').map_or(owner, |(id, _)| id)
}

/// `urlencode("{$ownerPath}/{$repositoryName}")`: the project as one path
/// segment.
fn project(owner: &str, repository_name: &str) -> String {
    php_std::encoding::urlencode(format!("{}/{repository_name}", owner_path(owner)).as_bytes())
}

/// `urlencode()`.
fn enc(s: &str) -> String {
    php_std::encoding::urlencode(s.as_bytes())
}

/// The repository with `pushed_at` from `last_activity_at`.
fn with_pushed_at(mut repository: Value) -> Value {
    let pushed = or(at(&repository, "last_activity_at"), "");
    php::set(&mut repository, "pushed_at", pushed);
    repository
}

/// A merge request in the shape every adapter reports pull requests in.
fn pull_request_shape(mr: &Value) -> Value {
    object([
        ("number", or(at(mr, "iid"), Value::Int(0))),
        ("title", or(at(mr, "title"), "")),
        ("state", or(at(mr, "state"), "")),
        ("head", object([("ref", or(at(mr, "source_branch"), "")), ("sha", or(at(mr, "sha"), ""))])),
        ("base", object([("ref", or(at(mr, "target_branch"), ""))])),
    ])
}

/// A commit in the shape every adapter reports.
fn commit_shape(commit: &Value) -> Value {
    object([
        ("commitAuthor", or(at(commit, "author_name"), "Unknown")),
        ("commitMessage", or(at(commit, "message"), "No message")),
        ("commitHash", or(at(commit, "id"), "")),
        ("commitUrl", or(at(commit, "web_url"), "")),
        ("commitAuthorAvatar", s("")),
        ("commitAuthorUrl", s("")),
    ])
}

/// A function declared `: array` returning `value` (coercive mode refuses
/// anything but an array).
pub(crate) fn return_array(value: Value, function: &str) -> Result<Value> {
    match value {
        Value::Array(_) => Ok(value),
        other => Err(Error::type_error(format!(
            "{function}(): Return value must be of type array, {} returned",
            php::value_name(&other)
        ))),
    }
}

impl GitLab {
    /// Every page of a paginated list, keeping each item's `name`.
    async fn names(&self, base: &str) -> Result<Vec<Value>> {
        let mut names = Vec::new();
        let mut page = 1;
        loop {
            let response = self.get(format!("{base}?per_page=100&page={page}")).await?;
            if response.status >= 400 {
                return Ok(Vec::new());
            }
            let Value::Array(items) = &response.body else { break };
            if items.is_empty() {
                break;
            }
            for item in items.values() {
                names.push(or(at(item, "name"), ""));
            }
            page += 1;
            if items.len() != 100 {
                break;
            }
        }
        Ok(names)
    }
}

#[async_trait]
impl Adapter for GitLab {
    fn name(&self) -> &str {
        "gitlab"
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
        let response =
            self.get(format!("/users?username={}", php_std::encoding::rawurlencode(username.as_bytes()))).await?;
        if response.status >= 400 {
            return Err(Error::exception(format!("Failed to get user: HTTP {}", response.status), response.status));
        }
        let body = or(response.body, Value::Array(Array::new()));
        let first = php::at_index(&body, 0);
        if empty(&first) {
            return Err(Error::exception(format!("User not found: {username}"), 0));
        }
        return_array(first, &format!("{CLASS}::getUser"))
    }

    async fn owner_name(&self, _installation_id: &str, repository_id: Option<i64>) -> Result<String> {
        if let Some(id) = repository_id.filter(|id| *id > 0) {
            let response = self.get(format!("/projects/{id}")).await?;
            if response.status >= 400 {
                return Err(Error::exception(
                    format!("Failed to get owner name for repository {id}: HTTP {}", response.status),
                    response.status,
                ));
            }
            let namespace = or(response.at("namespace"), Value::Array(Array::new()));
            return php::return_string(or(at(&namespace, "path"), ""), &format!("{CLASS}::getOwnerName"));
        }
        let response = self.get("/user").await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to get current user: HTTP {}", response.status),
                response.status,
            ));
        }
        php::return_string(or(response.at("username"), ""), &format!("{CLASS}::getOwnerName"))
    }

    async fn has_access_to_all_repositories(&self) -> Result<bool> {
        Ok(true)
    }

    async fn search_repositories(&self, owner: &str, page: i64, per_page: i64, search: &str) -> Result<Value> {
        let owner_path = owner_path(owner);
        let search_query = if php::filled(search) { format!("&search={}", enc(search)) } else { String::new() };
        let mut response = self.get(format!("/groups/{owner_path}/projects?page={page}&per_page={per_page}{search_query}")).await?;

        // No group by that name: the user's own projects, filtered to the owner below.
        let filter = response.status == 404;
        if filter {
            response = self.get(format!("/projects?membership=true&page={page}&per_page={per_page}{search_query}")).await?;
        }
        let empty_result = || object([("items", Value::Array(Array::new())), ("total", Value::Int(0))]);
        if response.status >= 400 {
            return Ok(empty_result());
        }
        let Value::Array(body) = &response.body else { return Ok(empty_result()) };

        let mut repositories = Vec::new();
        for repository in body.values() {
            if filter && !php::is_str(&or(path(repository, &["namespace", "path"]), ""), owner_path) {
                continue;
            }
            repositories.push(object([
                ("id", or(at(repository, "id"), Value::Int(0))),
                ("name", or(at(repository, "name"), "")),
                ("description", or(at(repository, "description"), "")),
                ("private", Value::Bool(php::is_str(&or(at(repository, "visibility"), ""), "private"))),
                ("pushed_at", or(at(repository, "last_activity_at"), "")),
            ]));
        }
        let total = if filter {
            repositories.len() as i64
        } else {
            match response.header("x-total") {
                Some(total) => php::int(&s(total)),
                None => repositories.len() as i64,
            }
        };
        Ok(object([("items", php::list(repositories)), ("total", Value::Int(total))]))
    }

    async fn installation_repository(&self, _repository_name: &str) -> Result<Value> {
        Err(Error::exception("getInstallationRepository is not applicable for this adapter", 0))
    }

    async fn repository(&self, owner: &str, repository_name: &str) -> Result<Value> {
        let response = self.get(format!("/projects/{}", project(owner, repository_name))).await?;
        if response.status >= 400 {
            return Err(Error::RepositoryNotFound("Repository not found".into()));
        }
        Ok(match response.body {
            body @ Value::Array(_) => with_pushed_at(body),
            Value::Null => Value::Array(Array::new()),
            _ => Value::Array(Array::new()),
        })
    }

    async fn create_repository(&self, owner: &str, repository_name: &str, private: bool) -> Result<Value> {
        let namespace = php::int(&s(namespace_id(owner)));
        let response = self
            .call(Call::post("/projects").params(object([
                ("name", s(repository_name)),
                ("path", s(repository_name)),
                ("namespace_id", Value::Int(namespace)),
                ("visibility", s(if private { "private" } else { "public" })),
            ])))
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Creating repository {repository_name} failed with status code {}", response.status),
                response.status,
            ));
        }
        let body = match response.body {
            body @ Value::Array(_) => body,
            _ => Value::Array(Array::new()),
        };
        Ok(with_pushed_at(body))
    }

    async fn delete_repository(&self, owner: &str, repository_name: &str) -> Result<bool> {
        let response =
            self.call(Call::new(Method::Delete, format!("/projects/{}", project(owner, repository_name)))).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Deleting repository {repository_name} failed with status code {}", response.status),
                response.status,
            ));
        }
        Ok(true)
    }

    async fn pull_request_from_branch(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value> {
        let response = self
            .get(format!(
                "/projects/{}/merge_requests?state=opened&source_branch={}",
                project(owner, repository_name),
                enc(branch)
            ))
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to list merge requests: HTTP {}", response.status),
                response.status,
            ));
        }
        let first = php::at_index(&or(response.body, Value::Array(Array::new())), 0);
        if empty(&first) {
            return Ok(Value::Array(Array::new()));
        }
        Ok(pull_request_shape(&first))
    }

    async fn pull_request(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        let response =
            self.get(format!("/projects/{}/merge_requests/{number}", project(owner, repository_name))).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to get merge request: HTTP {}", response.status),
                response.status,
            ));
        }
        Ok(pull_request_shape(&or(response.body, Value::Array(Array::new()))))
    }

    async fn pull_request_files(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        let project = project(owner, repository_name);
        // Wait until the diff is ready (patch_id_sha set).
        for _ in 0..10 {
            let response = self.get(format!("/projects/{project}/merge_requests/{number}")).await?;
            if !matches!(response.at("patch_id_sha"), Value::Null) {
                break;
            }
            tokio::time::sleep(std::time::Duration::from_secs(1)).await;
        }

        let mut files = Vec::new();
        let mut page = 1;
        loop {
            let response = self
                .get(format!("/projects/{project}/merge_requests/{number}/diffs?page={page}&per_page=100"))
                .await?;
            if response.status >= 400 {
                return Err(Error::exception(
                    format!("Failed to get merge request files: HTTP {}", response.status),
                    response.status,
                ));
            }
            let Value::Array(diffs) = &response.body else { break };
            if diffs.is_empty() {
                break;
            }
            for diff in diffs.values() {
                files.push(object([("filename", or(or(at(diff, "new_path"), at(diff, "old_path")), ""))]));
            }
            if diffs.len() < 100 {
                break;
            }
            page += 1;
        }
        Ok(php::list(files))
    }

    async fn create_comment(&self, owner: &str, repository_name: &str, number: i64, comment: &str) -> Result<String> {
        let response = self
            .call(
                Call::post(format!("/projects/{}/merge_requests/{number}/notes", project(owner, repository_name)))
                    .params(object([("body", s(comment))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to create comment: HTTP {}", response.status),
                response.status,
            ));
        }
        let body = or(response.body, Value::Array(Array::new()));
        let fields = php::expect_array(&body, "array_key_exists", "#2 ($array)")?;
        if !fields.contains_key(&ArrayKey::from("id")) {
            return Err(Error::exception("Comment creation response is missing comment ID.", 0));
        }
        let id = php::string(&or(at(&body, "id"), ""));
        Ok(format!("{number}:{}", php::text(&id)))
    }

    async fn comment(&self, owner: &str, repository_name: &str, comment_id: &str) -> Result<String> {
        let Some((mr, note)) = comment_id.split_once(':') else { return Ok(String::new()) };
        let response =
            self.get(format!("/projects/{}/merge_requests/{mr}/notes/{note}", project(owner, repository_name))).await?;
        php::return_string(or(response.at("body"), ""), &format!("{CLASS}::getComment"))
    }

    async fn update_comment(
        &self,
        owner: &str,
        repository_name: &str,
        comment_id: &str,
        comment: &str,
    ) -> Result<String> {
        let Some((mr, note)) = comment_id.split_once(':') else {
            return Err(Error::exception(format!("Invalid comment ID format: {comment_id}"), 0));
        };
        let response = self
            .call(
                Call::new(
                    Method::Put,
                    format!("/projects/{}/merge_requests/{mr}/notes/{note}", project(owner, repository_name)),
                )
                .params(object([("body", s(comment))])),
            )
            .await?;
        if response.status != 200 {
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
        let base = match &self.access_token {
            Some(token) if php::filled(token) => {
                self.gitlab_url.replace("://", &format!("://oauth2:{}@", enc(token)))
            }
            _ => self.gitlab_url.clone(),
        };
        let clone_url = format!("{base}/{}/{repository_name}.git", owner_path(owner));
        clone_command(&clone_url, version, version_type, directory, root_directory)
    }

    /// GitLab sends the configured secret token back as it is, so the
    /// signature is the secret.
    fn validate_webhook_event(&self, _payload: &[u8], signature: &[u8], signature_key: &[u8]) -> bool {
        crate::webhook::hash_equals(signature_key, signature)
    }

    fn events(&self, event: &str, payload: &[u8]) -> Result<Vec<Value>> {
        let payload = match php::json_decode(payload) {
            Some(v @ Value::Array(_)) => v,
            _ => return Err(Error::exception("Invalid payload.", 0)),
        };
        match event {
            "Push Hook" => {
                let project = or(at(&payload, "project"), Value::Array(Array::new()));
                let commits = or(at(&payload, "commits"), Value::Array(Array::new()));
                let checkout_sha = or(at(&payload, "checkout_sha"), "");
                let mut latest = Value::Array(Array::new());
                for commit in each(&commits) {
                    if php::identical(&or(at(&commit, "id"), ""), &checkout_sha) {
                        latest = commit;
                        break;
                    }
                }
                if empty(&latest) && !empty(&commits) {
                    let list = php::expect_array(&commits, "array_key_last", "#1 ($array)")?;
                    latest = list.values().last().cloned().unwrap_or(Value::Null);
                }

                let repository_url = or(at(&project, "web_url"), "");
                let branch = php::str_replace("refs/heads/", "", &or(at(&payload, "ref"), ""));
                let branch_url = concat_if_both(&repository_url, "/-/tree/", &branch);

                let mut affected: Array = Array::new();
                for commit in each(&commits) {
                    let added = or(at(&commit, "added"), Value::Array(Array::new()));
                    let modified = or(at(&commit, "modified"), Value::Array(Array::new()));
                    let removed = or(at(&commit, "removed"), Value::Array(Array::new()));
                    let changed = php::array_merge(&[&added, &modified, &removed])?;
                    for file in changed.values() {
                        affected.set(php::write_key(file)?, Value::Bool(true));
                    }
                }

                let zero = s("0".repeat(40));
                Ok(vec![object([
                    ("branchCreated", Value::Bool(php::identical(&or(at(&payload, "before"), ""), &zero))),
                    ("branchDeleted", Value::Bool(php::identical(&or(at(&payload, "after"), ""), &zero))),
                    ("branch", branch),
                    ("branchUrl", branch_url),
                    ("repositoryId", s(php::string(&or(at(&project, "id"), "")))),
                    ("repositoryName", or(at(&project, "name"), "")),
                    ("repositoryUrl", repository_url),
                    ("installationId", s("")),
                    ("commitHash", checkout_sha),
                    ("owner", or(at(&project, "namespace"), "")),
                    ("authorUrl", s("")),
                    ("authorAvatarUrl", or(at(&payload, "user_avatar"), "")),
                    ("headCommitAuthorName", or(path(&latest, &["author", "name"]), "")),
                    ("headCommitAuthorEmail", or(path(&latest, &["author", "email"]), "")),
                    ("headCommitMessage", or(at(&latest, "message"), "")),
                    ("headCommitUrl", or(at(&latest, "url"), "")),
                    ("external", Value::Bool(false)),
                    ("pullRequestNumber", s("")),
                    ("action", s("")),
                    ("affectedFiles", php::list(php::array_keys(&affected))),
                ])])
            }
            "Merge Request Hook" => {
                let project = or(at(&payload, "project"), Value::Array(Array::new()));
                let mr = or(at(&payload, "object_attributes"), Value::Array(Array::new()));
                let repository_url = or(at(&project, "web_url"), "");
                let branch = or(at(&mr, "source_branch"), "");
                let branch_url = concat_if_both(&repository_url, "/-/tree/", &branch);
                let key = php::isset_key(&or(at(&mr, "action"), ""))?;
                let action = match key {
                    ArrayKey::Str(k) => MERGE_REQUEST_ACTIONS
                        .iter()
                        .find(|(native, _)| native.as_bytes() == k.as_bytes())
                        .map_or("", |(_, mapped)| mapped),
                    ArrayKey::Int(_) => "",
                };
                let source = at(&mr, "source_project_id");
                let target = at(&mr, "target_project_id");
                let external = !matches!(source, Value::Null)
                    && !matches!(target, Value::Null)
                    && !php::identical(&source, &target);

                Ok(vec![object([
                    ("branch", branch),
                    ("branchUrl", branch_url),
                    ("repositoryId", s(php::string(&or(at(&project, "id"), "")))),
                    ("repositoryName", or(at(&project, "name"), "")),
                    ("repositoryUrl", repository_url),
                    ("installationId", s("")),
                    ("commitHash", or(path(&mr, &["last_commit", "id"]), "")),
                    ("owner", or(at(&project, "namespace"), "")),
                    ("authorUrl", s("")),
                    ("authorAvatarUrl", or(path(&payload, &["user", "avatar_url"]), "")),
                    ("headCommitUrl", or(path(&mr, &["last_commit", "url"]), "")),
                    ("external", Value::Bool(external)),
                    ("pullRequestNumber", or(at(&mr, "iid"), "")),
                    ("action", s(action)),
                ])])
            }
            _ => Ok(Vec::new()),
        }
    }

    fn event_header_name(&self) -> &str {
        "x-gitlab-event"
    }

    fn signature_header_name(&self) -> &str {
        "x-gitlab-token"
    }

    fn supported_webhook_scopes(&self) -> &'static [WebhookScope] {
        &[WebhookScope::Repository]
    }

    fn repository_url(&self, owner: &str, repository_name: &str) -> String {
        format!("{}/{owner}/{repository_name}", self.gitlab_url)
    }

    fn organization_url(&self, owner: &str) -> Result<String> {
        Ok(format!("{}/{owner}", self.gitlab_url))
    }

    fn branch_url(&self, owner: &str, repository_name: &str, branch: &str) -> String {
        format!("{}/-/tree/{branch}", self.repository_url(owner, repository_name))
    }

    fn commit_url(&self, owner: &str, repository_name: &str, commit_hash: &str) -> String {
        format!("{}/-/commit/{commit_hash}", self.repository_url(owner, repository_name))
    }

    fn file_url(&self, owner: &str, repository_name: &str, reference: &str) -> String {
        format!("{}/-/blob/{reference}", self.repository_url(owner, repository_name))
    }

    async fn repository_name(&self, repository_id: &str) -> Result<String> {
        let response = self.get(format!("/projects/{repository_id}")).await?;
        if response.status >= 400 {
            return Err(Error::exception(format!("Repository {repository_id} not found"), 0));
        }
        php::return_string(or(response.at("path"), ""), &format!("{CLASS}::getRepositoryName"))
    }

    async fn list_branches(&self, owner: &str, repository_name: &str) -> Result<Vec<Value>> {
        self.names(&format!("/projects/{}/repository/branches", project(owner, repository_name))).await
    }

    async fn list_tags(&self, owner: &str, repository_name: &str, search: &str) -> Result<Vec<Value>> {
        let tags = self.names(&format!("/projects/{}/repository/tags", project(owner, repository_name))).await?;
        git::match_glob(tags, search)
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
        let state = match state {
            "pending" => "pending",
            "success" => "success",
            "failure" | "error" => "failed",
            "cancelled" => "canceled",
            other => other,
        };
        let mut payload = object([("state", s(state))]);
        if php::filled(description) {
            php::set(&mut payload, "description", s(description));
        }
        if php::filled(target_url) {
            php::set(&mut payload, "target_url", s(target_url));
        }
        if php::filled(context) {
            php::set(&mut payload, "name", s(context));
        }
        let response = self
            .call(
                Call::post(format!("/projects/{}/statuses/{}", project(owner, repository_name), enc(sha)))
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

    async fn create_check_run(&self, _owner: &str, _repository_name: &str, _head_sha: &str, _run: &CheckRun) -> Result<Value> {
        Err(unsupported("createCheckRun", self.name()))
    }

    async fn check_run(&self, _owner: &str, _repository_name: &str, _check_run_id: &str) -> Result<Value> {
        Err(unsupported("getCheckRun", self.name()))
    }

    async fn update_check_run(
        &self,
        _owner: &str,
        _repository_name: &str,
        _check_run_id: &str,
        _run: &CheckRun,
    ) -> Result<Value> {
        Err(unsupported("updateCheckRun", self.name()))
    }

    async fn repository_tree(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
        recursive: bool,
    ) -> Result<Vec<Value>> {
        let url = format!("/projects/{}/repository/tree?ref={}", project(owner, repository_name), enc(branch));
        if recursive {
            let mut page = 1;
            let mut all = Value::Array(Array::new());
            loop {
                let response = self.get(format!("{url}&recursive=true&per_page=100&page={page}")).await?;
                if response.status >= 400 {
                    return Ok(Vec::new());
                }
                let body = response.body;
                let count = match &body {
                    Value::Array(items) if !items.is_empty() => items.len(),
                    _ => break,
                };
                all = Value::Array(php::array_merge(&[&all, &body])?);
                page += 1;
                if count != 100 {
                    break;
                }
            }
            return php::array_column(&all, "path");
        }
        let response = self.get(url).await?;
        if response.status >= 400 {
            return Ok(Vec::new());
        }
        match &response.body {
            body @ Value::Array(_) => php::array_column(body, "path"),
            _ => Ok(Vec::new()),
        }
    }

    async fn list_repository_languages(&self, owner: &str, repository_name: &str) -> Result<Vec<Value>> {
        let response = self.get(format!("/projects/{}/languages", project(owner, repository_name))).await?;
        if response.status >= 400 {
            return Ok(Vec::new());
        }
        match &response.body {
            Value::Array(languages) => Ok(php::array_keys(languages)),
            _ => Ok(Vec::new()),
        }
    }

    async fn list_repository_contents(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        reference: &str,
    ) -> Result<Vec<Value>> {
        let path = normalize_repository_path(path);
        let mut url = format!("/projects/{}/repository/tree", project(owner, repository_name));
        let has_ref = php::filled(reference);
        if has_ref {
            url.push_str(&format!("?ref={}", enc(reference)));
        }
        if !path.is_empty() {
            url.push_str(&format!("{}path={}", if has_ref { "&" } else { "?" }, enc(&path)));
        }
        let response = self.get(url).await?;
        if response.status >= 400 {
            return Ok(Vec::new());
        }
        let Value::Array(items) = &response.body else { return Ok(Vec::new()) };
        Ok(items
            .values()
            .map(|item| {
                object([
                    ("name", or(at(item, "name"), "")),
                    ("size", Value::Int(0)),
                    ("type", s(if php::is_str(&or(at(item, "type"), ""), "blob") { "file" } else { "dir" })),
                ])
            })
            .collect())
    }

    async fn repository_content(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        reference: &str,
    ) -> Result<Value> {
        let reference = if php::filled(reference) { reference } else { "HEAD" };
        let response = self
            .get(format!(
                "/projects/{}/repository/files/{}?ref={}",
                project(owner, repository_name),
                enc(&normalize_repository_path(path)),
                enc(reference)
            ))
            .await?;
        if response.status != 200 {
            return Err(Error::file_not_found());
        }
        let body = or(response.body, Value::Array(Array::new()));
        if !php::is_str(&or(at(&body, "encoding"), ""), "base64") {
            return Err(Error::file_not_found());
        }
        let raw = php::string(&or(at(&body, "content"), ""));
        let Some(content) = php_std::encoding::base64_decode(&raw, true) else {
            return Err(Error::file_not_found());
        };
        Ok(object([
            ("sha", or(at(&body, "blob_id"), "")),
            ("size", or(at(&body, "size"), Value::Int(0))),
            ("content", s(content)),
        ]))
    }

    async fn commit(&self, owner: &str, repository_name: &str, commit_hash: &str) -> Result<Value> {
        let response = self
            .get(format!("/projects/{}/repository/commits/{}", project(owner, repository_name), enc(commit_hash)))
            .await?;
        if response.status >= 400 {
            return Err(Error::exception("Commit not found or inaccessible", 0));
        }
        Ok(commit_shape(&or(response.body, Value::Array(Array::new()))))
    }

    async fn latest_commit(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value> {
        let response = self
            .get(format!(
                "/projects/{}/repository/commits?ref_name={}&per_page=1",
                project(owner, repository_name),
                enc(branch)
            ))
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to get latest commit: HTTP {}", response.status),
                response.status,
            ));
        }
        let first = php::at_index(&or(response.body, Value::Array(Array::new())), 0);
        if empty(&first) {
            return Err(Error::exception("Latest commit response is missing required information.", 0));
        }
        Ok(commit_shape(&first))
    }

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
        let token = self.token()?;
        let mut url = format!(
            "{}/projects/{}/repository/archive.{extension}?access_token={}",
            self.transport.endpoint,
            project(owner, repository_name),
            enc(token)
        );
        if php::filled(reference) {
            url.push_str(&format!("&sha={}", enc(reference)));
        }
        Ok(url)
    }
}

#[async_trait]
impl Git for GitLab {
    async fn create_file(
        &self,
        owner: &str,
        repository_name: &str,
        filepath: &str,
        content: &[u8],
        message: &str,
        branch: &str,
    ) -> Result<Value> {
        let payload = object([
            ("branch", s(if php::filled(branch) { branch } else { "main" })),
            ("content", s(php_std::encoding::base64_encode(content))),
            ("encoding", s("base64")),
            ("commit_message", s(message)),
            ("author_name", s("utopia")),
            ("author_email", s("utopia@example.com")),
        ]);
        let response = self
            .call(
                Call::post(format!("/projects/{}/repository/files/{}", project(owner, repository_name), enc(filepath)))
                    .params(payload),
            )
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to create file {filepath}: HTTP {}", response.status),
                response.status,
            ));
        }
        Ok(or(response.body, Value::Array(Array::new())))
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
                Call::post(format!("/projects/{}/repository/branches", project(owner, repository_name)))
                    .params(object([("branch", s(new_branch)), ("ref", s(old_branch))])),
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
        let response = self
            .call(Call::post(format!("/projects/{}/merge_requests", project(owner, repository_name))).params(object([
                ("title", s(title)),
                ("source_branch", s(head)),
                ("target_branch", s(base)),
                ("description", s(body)),
            ])))
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to create merge request: HTTP {}", response.status),
                response.status,
            ));
        }
        Ok(or(response.body, Value::Array(Array::new())))
    }

    async fn create_webhook(
        &self,
        owner: &str,
        repository_name: &str,
        url: &str,
        secret: &str,
        events: &[&str],
    ) -> Result<WebhookId> {
        let response = self
            .call(Call::post(format!("/projects/{}/hooks", project(owner, repository_name))).params(object([
                ("url", s(url)),
                ("token", s(secret)),
                ("enable_ssl_verification", Value::Bool(false)),
                ("push_events", Value::Bool(events.iter().any(|e| loose_is(e, "push")))),
                (
                    "merge_requests_events",
                    Value::Bool(events.iter().any(|e| loose_is(e, "pull_request"))),
                ),
            ])))
            .await?;
        if response.status >= 400 {
            let body = php::json_encode(&response.body).unwrap_or_default();
            return Err(Error::exception(
                format!("Failed to create webhook: HTTP {} - {body}", response.status),
                response.status,
            ));
        }
        let id = or(response.at("id"), Value::Int(0));
        Ok(WebhookId::Int(php::return_int(id, &format!("{CLASS}::createWebhook"))?))
    }

    async fn create_tag(
        &self,
        owner: &str,
        repository_name: &str,
        tag: &str,
        target: &str,
        message: &str,
    ) -> Result<Value> {
        let mut payload = object([("tag_name", s(tag)), ("ref", s(target))]);
        if php::filled(message) {
            php::set(&mut payload, "message", s(message));
        }
        let response = self
            .call(Call::post(format!("/projects/{}/repository/tags", project(owner, repository_name))).params(payload))
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to create tag {tag}: HTTP {}", response.status),
                response.status,
            ));
        }
        Ok(or(response.body, Value::Array(Array::new())))
    }

    async fn list_namespaces(&self, page: i64, per_page: i64, search: &str) -> Result<Value> {
        let mut url = format!("/namespaces?page={page}&per_page={per_page}");
        if php::filled(search) {
            url.push_str(&format!("&search={}", enc(search)));
        }
        let response = self.get(url).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to list namespaces: HTTP {}", response.status),
                response.status,
            ));
        }
        let items = match &response.body {
            Value::Array(items) => items.clone(),
            _ => Array::new(),
        };
        let mut namespaces: Array = Array::new();
        for (key, namespace) in items.iter() {
            if !php::is_array(namespace) {
                return Err(Error::type_error(format!(
                    "{{closure:{CLASS}::listNamespaces():272}}(): Argument #1 ($namespace) must be of type array, {} given",
                    php::value_name(namespace)
                )));
            }
            namespaces.set(
                key.to_owned(),
                object([
                    ("id", s(php::string(&or(at(namespace, "id"), "")))),
                    ("name", or(at(namespace, "name"), or(at(namespace, "path"), ""))),
                    ("path", or(at(namespace, "full_path"), or(at(namespace, "path"), ""))),
                    ("kind", or(at(namespace, "kind"), "group")),
                    ("avatarUrl", or(at(namespace, "avatar_url"), "")),
                ]),
            );
        }
        let total = match response.header("x-total") {
            Some(total) => php::int(&s(total)),
            None => namespaces.len() as i64,
        };
        Ok(object([("items", Value::Array(namespaces)), ("total", Value::Int(total))]))
    }

    async fn commit_statuses(&self, owner: &str, repository_name: &str, commit_hash: &str) -> Result<Vec<Value>> {
        let response = self
            .get(format!(
                "/projects/{}/repository/commits/{}/statuses",
                project(owner, repository_name),
                enc(commit_hash)
            ))
            .await?;
        if response.status >= 400 {
            return Ok(Vec::new());
        }
        let Value::Array(statuses) = &response.body else { return Ok(Vec::new()) };
        Ok(statuses
            .values()
            .map(|status| {
                object([
                    ("state", or(at(status, "status"), "")),
                    ("description", or(at(status, "description"), "")),
                    ("target_url", or(at(status, "target_url"), "")),
                    ("context", or(at(status, "name"), "")),
                ])
            })
            .collect())
    }
}

/// `$event == $name` as `in_array()` compares (both strings: numeric ones
/// compare as numbers).
fn loose_is(event: &str, name: &str) -> bool {
    s(event).loose_eq(&s(name))
}
