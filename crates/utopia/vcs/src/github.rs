//! GitHub, through a GitHub App installation.

use std::sync::Arc;

use async_trait::async_trait;
use php_std::{Array, Value};
use utopia_cache::Cache;
use utopia_console::Command;

use crate::adapter::{Adapter, CheckRun, Git, VcsType, WebhookId, WebhookScope, unsupported};
use crate::error::{Error, Result};
use crate::git::{self, clone_command, normalize_repository_path};
use crate::php::{self, at, each, empty, object, or, path, s};
use crate::transport::{Call, Method, Transport};
use crate::{jwt, time, webhook};

/// GitHub App JWT lifetime in seconds: GitHub allows at most ten minutes,
/// nine leave a minute for clock drift.
///
/// PHP: `GitHub::GITHUB_APP_JWT_EXPIRY`.
pub const APP_JWT_EXPIRY: i64 = 60 * 9;

/// The class PHP names in errors about this adapter's state.
const CLASS: &str = "Utopia\\VCS\\Adapter\\Git\\GitHub";

/// The GitHub adapter: a GitHub App installation authenticates with an
/// RS256 app JWT exchanged for an installation access token, both cached
/// under the installation id for a minute less than the JWT lives.
///
/// PHP: `Utopia\VCS\Adapter\Git\GitHub`.
pub struct GitHub {
    cache: Arc<Cache>,
    transport: Transport,
    access_token: String,
    jwt_token: Option<String>,
    installation_id: Option<String>,
}

impl GitHub {
    /// An adapter on `https://api.github.com`, caching tokens in `cache`.
    pub fn new(cache: Arc<Cache>) -> Self {
        GitHub {
            cache,
            transport: Transport::new("https://api.github.com"),
            access_token: String::new(),
            jwt_token: None,
            installation_id: None,
        }
    }

    /// The same adapter on another API endpoint (GitHub Enterprise, a test
    /// server). PHP sets the protected `$endpoint` in a subclass.
    pub fn with_endpoint(mut self, endpoint: impl Into<String>) -> Self {
        self.transport.endpoint = endpoint.into();
        self
    }

    fn jwt(&self) -> Result<&str> {
        self.jwt_token.as_deref().ok_or_else(|| uninitialized("jwtToken"))
    }

    fn installation(&self) -> Result<&str> {
        self.installation_id.as_deref().ok_or_else(|| uninitialized("installationId"))
    }

    fn bearer(&self) -> String {
        format!("Bearer {}", self.access_token)
    }

    async fn get(&self, path: impl Into<String>) -> Result<crate::transport::Response> {
        self.transport.call(Call::get(path).auth(self.bearer())).await
    }

    /// Signs an app JWT and exchanges it for an installation access token.
    ///
    /// PHP: `generateAccessToken()`: a key given base64-encoded or with
    /// escaped newlines is accepted too; a numeric app id is the issuer as
    /// an integer.
    async fn generate_access_token(&mut self, private_key: &str, app_id: Option<&str>) -> Result<()> {
        let mut key = private_key.as_bytes().to_vec();
        if let Some(decoded) = php_std::encoding::base64_decode(&key, true)
            && php::contains(&decoded, b"-----BEGIN")
        {
            key = decoded;
        }
        if php::contains(&key, b"-----BEGIN") {
            key = php_std::string::str_replace(b"\\n", b"\n", &key).0.into_owned();
        }
        let Some(pkey) = jwt::private_key(&key) else {
            return Err(Error::exception("Failed to read the GitHub App private key", 0));
        };

        let iat = time::now();
        let issuer = match app_id {
            Some(id) if php_std::string::ctype_digit(id.as_bytes()) => Value::Int(php::int(&s(id))),
            Some(id) => s(id),
            None => Value::Null,
        };
        let claims = object([("iat", Value::Int(iat)), ("exp", Value::Int(iat + APP_JWT_EXPIRY)), ("iss", issuer)]);
        let token = jwt::rs256(&pkey, &claims)?;
        self.jwt_token = Some(token.clone());

        let installation = self.installation()?.to_owned();
        let response = self
            .transport
            .call(Call::post(format!("/app/installations/{installation}/access_tokens")).auth(format!("Bearer {token}")))
            .await?;
        let body = response.body.clone();
        let fields = php::expect_array(&body, "array_key_exists", "#2 ($array)")?;
        if !fields.contains_key(&php_std::ArrayKey::from("token")) {
            let safe: Array = fields
                .iter()
                .filter(|(k, _)| matches!(k, php_std::types::KeyRef::Str(b"message" | b"documentation_url")))
                .map(|(k, v)| (k.to_owned(), v.clone()))
                .collect();
            let safe = php::json_encode(&Value::Array(safe)).unwrap_or_default();
            return Err(Error::exception(
                format!(
                    "Failed to retrieve access token from GitHub API. Status: {}. Response: {safe}",
                    response.status
                ),
                response.status,
            ));
        }
        self.access_token = assign_string(or(at(&body, "token"), ""), "accessToken")?;
        Ok(())
    }

    /// The branch names of a repository, a page at a time, optionally only
    /// those starting with `search` (all matching ones, paged locally).
    ///
    /// PHP: `listBranches($owner, $repositoryName, $perPage, $page,
    /// $search)`; `per_page` is clamped to 1..100.
    pub async fn list_branches_page(
        &self,
        owner: &str,
        repository_name: &str,
        per_page: i64,
        page: i64,
        search: &str,
    ) -> Result<Vec<Value>> {
        let per_page = per_page.clamp(1, 100);
        if !search.is_empty() {
            let encoded = php_std::encoding::rawurlencode(search.as_bytes()).replace("%2F", "/");
            let response =
                self.get(format!("/repos/{owner}/{repository_name}/git/matching-refs/heads/{encoded}")).await?;
            let Value::Array(refs) = &response.body else { return Ok(Vec::new()) };
            if !(200..300).contains(&response.status) {
                return Ok(Vec::new());
            }
            let mut branches = Vec::with_capacity(refs.len());
            for r in refs.values() {
                let reference = closure_array(r, CLASS, "listBranches", 838, "ref")?;
                branches.push(php::str_replace("refs/heads/", "", &or(at(reference, "ref"), "")));
            }
            return Ok(php::slice(&branches, (page - 1).saturating_mul(per_page), per_page));
        }

        let response = self
            .transport
            .call(
                Call::get(format!("/repos/{owner}/{repository_name}/branches"))
                    .auth(self.bearer())
                    .params(object([("page", Value::Int(page)), ("per_page", Value::Int(per_page))])),
            )
            .await?;
        let Value::Array(branches) = &response.body else { return Ok(Vec::new()) };
        if !(200..300).contains(&response.status) {
            return Ok(Vec::new());
        }
        branches.values().map(|b| Ok(or(at(closure_array(b, CLASS, "listBranches", 857, "branch")?, "name"), ""))).collect()
    }
}

/// The `Error` PHP throws reading a typed property no call has set yet.
fn uninitialized(property: &str) -> Error {
    Error::Engine(php_std::EngineError::new(
        "Error",
        format!("Typed property {CLASS}::${property} must not be accessed before initialization"),
    ))
}

/// A value assigned to a `string` property in coercive mode.
fn assign_string(value: Value, property: &str) -> Result<String> {
    match value {
        Value::Array(_) | Value::Null => Err(Error::type_error(format!(
            "Cannot assign {} to property {CLASS}::${property} of type string",
            php::value_name(&value)
        ))),
        other => Ok(php::text(&php::string(&other))),
    }
}

/// The argument of an `fn (array $x)` closure an array function calls on
/// each element: anything but an array is PHP's `TypeError`, naming the
/// closure by the method and line it is declared on.
pub(crate) fn closure_array<'a>(value: &'a Value, class: &str, method: &str, line: u32, parameter: &str) -> Result<&'a Value> {
    match value {
        Value::Array(_) => Ok(value),
        other => Err(Error::type_error(format!(
            "{{closure:{class}::{method}():{line}}}(): Argument #1 (${parameter}) must be of type array, {} given",
            php::value_name(other)
        ))),
    }
}

/// `$response['headers']['status-code'] >= 400` as the exception GitHub's
/// adapter throws for it.
fn failed(message: impl Into<String>, status: i64) -> Error {
    Error::exception(message.into(), status)
}

/// A commit as every adapter reports it.
fn commit_shape(entries: [(&str, Value); 6]) -> Value {
    object(entries)
}

#[async_trait]
impl Adapter for GitHub {
    fn name(&self) -> &str {
        "github"
    }

    fn vcs_type(&self) -> VcsType {
        VcsType::Git
    }

    async fn initialize(
        &mut self,
        installation_id: &str,
        private_key: &str,
        app_id: Option<&str>,
        _access_token: Option<&str>,
        _refresh_token: Option<&str>,
    ) -> Result<()> {
        self.installation_id = Some(installation_id.to_owned());
        let cached = self.cache.load(installation_id, APP_JWT_EXPIRY - 60, "").await?;
        match cached {
            Some(response) if !response.loose_eq(&Value::Bool(false)) => {
                let parsed = php::json_decode(&php::string(&response)).unwrap_or(Value::Null);
                self.jwt_token = Some(assign_string(or(at(&parsed, "jwtToken"), ""), "jwtToken")?);
                self.access_token = assign_string(or(at(&parsed, "accessToken"), ""), "accessToken")?;
            }
            _ => {
                self.generate_access_token(private_key, app_id).await?;
                let tokens = object([
                    ("jwtToken", s(self.jwt_token.as_deref().unwrap_or_default())),
                    ("accessToken", s(&self.access_token)),
                ]);
                let tokens = php::json_encode(&tokens).unwrap_or_else(|| "{}".to_owned());
                self.cache.save(installation_id, &s(tokens), "", 0).await?;
            }
        }
        Ok(())
    }

    async fn user(&self, username: &str) -> Result<Value> {
        let response = self.transport.call(Call::get(format!("/users/{username}"))).await?;
        let mut headers: Array = response.headers.iter().map(|(k, v)| (php_std::ArrayKey::from(k.as_str()), s(v))).collect();
        headers.set(php_std::ArrayKey::from("status-code"), Value::Int(response.status));
        Ok(object([("headers", Value::Array(headers)), ("body", response.body)]))
    }

    async fn owner_name(&self, installation_id: &str, _repository_id: Option<i64>) -> Result<String> {
        let response = self
            .transport
            .call(Call::get(format!("/app/installations/{installation_id}")).auth(format!("Bearer {}", self.jwt()?)))
            .await?;
        if response.status == 404 {
            return Err(Error::OwnerNotFound(format!("Installation '{installation_id}' was not found.")));
        }
        let account = or(response.at("account"), Value::Array(Array::new()));
        let fields = php::expect_array(&account, "array_key_exists", "#2 ($array)")?;
        if !fields.contains_key(&php_std::ArrayKey::from("login")) {
            return Err(Error::exception("Owner name retrieval response is missing account login.", 0));
        }
        php::return_string(or(at(&account, "login"), ""), &format!("{CLASS}::getOwnerName"))
    }

    async fn has_access_to_all_repositories(&self) -> Result<bool> {
        let installation = self.installation()?;
        let response = self
            .transport
            .call(Call::get(format!("/app/installations/{installation}")).auth(format!("Bearer {}", self.jwt()?)))
            .await?;
        Ok(php::is_str(&or(response.at("repository_selection"), ""), "all"))
    }

    async fn search_repositories(&self, owner: &str, page: i64, per_page: i64, search: &str) -> Result<Value> {
        // An installation with access to every repository uses the search API.
        if self.has_access_to_all_repositories().await? {
            let response = self
                .transport
                .call(Call::get("/search/repositories").auth(self.bearer()).params(object([
                    ("q", s(format!("{search} user:{owner} fork:true"))),
                    ("page", Value::Int(page)),
                    ("per_page", Value::Int(per_page)),
                    ("sort", s("updated")),
                ])))
                .await?;
            if response.status != 422 {
                if response.status >= 400 {
                    return Err(failed(format!("Failed to search repositories: HTTP {}", response.status), response.status));
                }
                return Ok(object([
                    ("items", or(response.at("items"), Value::Array(Array::new()))),
                    ("total", or(response.at("total_count"), Value::Int(0))),
                ]));
            }
            // A private profile cannot be searched, but its installation can
            // still list repositories; an unknown owner has none.
            let installation = self.installation()?.to_owned();
            let installation_owner = self.owner_name(&installation, None).await?;
            if php_std::string::strcasecmp(owner.as_bytes(), installation_owner.as_bytes()) != 0 {
                return Ok(object([("items", Value::Array(Array::new())), ("total", Value::Int(0))]));
            }
        }

        if search.is_empty() {
            let response = self
                .transport
                .call(
                    Call::get("/installation/repositories")
                        .auth(self.bearer())
                        .params(object([("page", Value::Int(page)), ("per_page", Value::Int(per_page))])),
                )
                .await?;
            if response.status >= 400 {
                return Err(failed(
                    format!("Failed to list installation repositories: HTTP {}", response.status),
                    response.status,
                ));
            }
            return Ok(object([
                ("items", or(response.at("repositories"), Value::Array(Array::new()))),
                ("total", or(response.at("total_count"), Value::Int(0))),
            ]));
        }

        // With a search, every repository of the installation is fetched and filtered here.
        let mut repositories: Vec<Value> = Vec::new();
        let mut current = 1i64;
        loop {
            let response = self
                .transport
                .call(
                    Call::get("/installation/repositories")
                        .auth(self.bearer())
                        .params(object([("page", Value::Int(current)), ("per_page", Value::Int(100))])),
                )
                .await?;
            if response.status >= 400 {
                return Err(failed(
                    format!("Failed to list installation repositories: HTTP {}", response.status),
                    response.status,
                ));
            }
            let listed = or(response.at("repositories"), Value::Array(Array::new()));
            let listed_array = php::expect_array(&listed, "array_filter", "#1 ($array)")?;
            for repository in listed_array.values() {
                let repository = closure_array(repository, CLASS, "searchRepositories", 325, "repo")?;
                let name = php::string(&or(at(repository, "name"), ""));
                if php_std::string::stripos(&name, search.as_bytes(), 0).ok().flatten().is_some() {
                    repositories.push(repository.clone());
                }
            }
            if listed_array.len() < 100 {
                break;
            }
            current += 1;
        }
        let total = repositories.len() as i64;
        Ok(object([
            ("items", php::list(php::slice(&repositories, (page - 1).saturating_mul(per_page), per_page))),
            ("total", Value::Int(total)),
        ]))
    }

    async fn installation_repository(&self, repository_name: &str) -> Result<Value> {
        let per_page = 100i64;
        let mut current = 1i64;
        let mut total = 0i64;
        while total < 1000 {
            let response = self
                .transport
                .call(
                    Call::get("/installation/repositories")
                        .auth(self.bearer())
                        .params(object([("page", Value::Int(current)), ("per_page", Value::Int(per_page))])),
                )
                .await?;
            let body = response.body;
            let fields = php::expect_array(&body, "array_key_exists", "#2 ($array)")?;
            if !fields.contains_key(&php_std::ArrayKey::from("repositories")) {
                return Err(Error::exception("Repositories list missing in the response.", 0));
            }
            let listed = or(at(&body, "repositories"), Value::Array(Array::new()));
            for repository in each(&listed) {
                let name = php::string(&or(at(&repository, "name"), ""));
                if php::lower(&name) == php::lower(repository_name.as_bytes()) {
                    return Ok(repository);
                }
            }
            if php::expect_array(&listed, "count", "#1 ($value)").map(|a| a.len()).unwrap_or(0) < per_page as usize {
                break;
            }
            current += 1;
            total += per_page;
        }
        Err(Error::RepositoryNotFound("Repository not found.".into()))
    }

    async fn repository(&self, owner: &str, repository_name: &str) -> Result<Value> {
        let response = self.get(format!("/repos/{owner}/{repository_name}")).await?;
        if response.status == 404 || response.status == 422 {
            return Err(Error::RepositoryNotFound("Repository not found.".into()));
        }
        Ok(or(response.body, Value::Array(Array::new())))
    }

    async fn create_repository(&self, owner: &str, repository_name: &str, private: bool) -> Result<Value> {
        let response = self
            .transport
            .call(
                Call::post(format!("/orgs/{owner}/repos"))
                    .auth(self.bearer())
                    .params(object([("name", s(repository_name)), ("private", Value::Bool(private))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(failed(
                format!("Creating repository {repository_name} failed with status code {}", response.status),
                response.status,
            ));
        }
        Ok(or(response.body, Value::Array(Array::new())))
    }

    async fn delete_repository(&self, owner: &str, repository_name: &str) -> Result<bool> {
        let response = self
            .transport
            .call(Call::new(Method::Delete, format!("/repos/{owner}/{repository_name}")).auth(self.bearer()))
            .await?;
        if response.status >= 400 {
            return Err(failed(
                format!("Deleting repository {repository_name} failed with status code {}", response.status),
                response.status,
            ));
        }
        Ok(true)
    }

    async fn pull_request_from_branch(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value> {
        let response = self
            .get(format!("/repos/{owner}/{repository_name}/pulls?head={owner}:{branch}&state=open&sort=updated&per_page=1"))
            .await?;
        Ok(or(php::at_index(&response.body, 0), Value::Array(Array::new())))
    }

    async fn pull_request(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        let response = self.get(format!("/repos/{owner}/{repository_name}/pulls/{number}")).await?;
        if response.status >= 400 {
            return Err(failed(format!("Failed to get pull request: HTTP {}", response.status), response.status));
        }
        Ok(or(response.body, Value::Array(Array::new())))
    }

    async fn pull_request_files(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        let mut all: Vec<Value> = Vec::new();
        let mut merged = Value::Array(Array::new());
        let per_page = 100i64;
        let mut current = 1i64;
        loop {
            let response = self
                .transport
                .call(
                    Call::get(format!("/repos/{owner}/{repository_name}/pulls/{number}/files"))
                        .auth(self.bearer())
                        .params(object([("per_page", Value::Int(per_page)), ("page", Value::Int(current))])),
                )
                .await?;
            if response.status >= 400 {
                return Err(failed(
                    format!("Failed to get pull request files: HTTP {}", response.status),
                    response.status,
                ));
            }
            let files = or(response.body, Value::Array(Array::new()));
            let Value::Array(page) = &files else {
                return Err(Error::exception(
                    format!("Failed to get pull request files: HTTP {} returned a non-JSON body", response.status),
                    0,
                ));
            };
            merged = Value::Array(php::array_merge(&[&merged, &files])?);
            all.clear();
            if (page.len() as i64) < per_page {
                break;
            }
            current += 1;
        }
        Ok(merged)
    }

    async fn create_comment(&self, owner: &str, repository_name: &str, number: i64, comment: &str) -> Result<String> {
        let response = self
            .transport
            .call(
                Call::post(format!("/repos/{owner}/{repository_name}/issues/{number}/comments"))
                    .auth(self.bearer())
                    .params(object([("body", s(comment))])),
            )
            .await?;
        let body = or(response.body, Value::Array(Array::new()));
        let fields = php::expect_array(&body, "array_key_exists", "#2 ($array)")?;
        if !fields.contains_key(&php_std::ArrayKey::from("id")) {
            return Err(Error::exception("Comment creation response is missing comment ID.", 0));
        }
        php::return_string(or(at(&body, "id"), ""), &format!("{CLASS}::createComment"))
    }

    async fn comment(&self, owner: &str, repository_name: &str, comment_id: &str) -> Result<String> {
        let response = self.get(format!("/repos/{owner}/{repository_name}/issues/comments/{comment_id}")).await?;
        let body = or(response.body, Value::Array(Array::new()));
        php::return_string(or(at(&body, "body"), ""), &format!("{CLASS}::getComment"))
    }

    async fn update_comment(
        &self,
        owner: &str,
        repository_name: &str,
        comment_id: &str,
        comment: &str,
    ) -> Result<String> {
        let response = self
            .transport
            .call(
                Call::new(Method::Patch, format!("/repos/{owner}/{repository_name}/issues/comments/{comment_id}"))
                    .auth(self.bearer())
                    .params(object([("body", s(comment))])),
            )
            .await?;
        let body = or(response.body, Value::Array(Array::new()));
        let fields = php::expect_array(&body, "array_key_exists", "#2 ($array)")?;
        if !fields.contains_key(&php_std::ArrayKey::from("id")) {
            return Err(Error::exception("Comment update response is missing comment ID.", 0));
        }
        php::return_string(or(at(&body, "id"), ""), &format!("{CLASS}::updateComment"))
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
        let owner = php_std::encoding::urlencode(owner.as_bytes());
        let repository_name = php_std::encoding::urlencode(repository_name.as_bytes());
        let token = if php::filled(&self.access_token) {
            format!(":{}", php_std::encoding::urlencode(self.access_token.as_bytes()))
        } else {
            String::new()
        };
        let clone_url = format!("https://{owner}{token}@github.com/{owner}/{repository_name}");
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
        let installation = or(at(&payload, "installation"), Value::Array(Array::new()));
        let installation_id = s(php::string(&or(at(&installation, "id"), "")));

        match event {
            "push" => {
                let repository = or(at(&payload, "repository"), Value::Array(Array::new()));
                let repository_owner = or(at(&repository, "owner"), Value::Array(Array::new()));
                let sender = or(at(&payload, "sender"), Value::Array(Array::new()));
                let head_commit = or(at(&payload, "head_commit"), Value::Array(Array::new()));
                let head_commit_author = or(at(&head_commit, "author"), Value::Array(Array::new()));

                let branch = php::str_replace("refs/heads/", "", &or(at(&payload, "ref"), ""));
                let repository_url = or(at(&repository, "html_url"), "");
                let branch_url = concat_if_both(&repository_url, "/tree/", &branch);

                let mut affected: Array = Array::new();
                for commit in each(&or(at(&payload, "commits"), Value::Array(Array::new()))) {
                    for list in ["added", "removed", "modified"] {
                        for file in each(&or(at(&commit, list), Value::Array(Array::new()))) {
                            affected.set(php::write_key(&file)?, Value::Bool(true));
                        }
                    }
                }

                Ok(vec![object([
                    ("branchCreated", or(at(&payload, "created"), false)),
                    ("branchDeleted", or(at(&payload, "deleted"), false)),
                    ("branch", branch),
                    ("branchUrl", branch_url),
                    ("repositoryId", s(php::string(&or(at(&repository, "id"), "")))),
                    ("repositoryName", or(at(&repository, "name"), "")),
                    ("repositoryUrl", repository_url),
                    ("installationId", installation_id),
                    ("commitHash", or(at(&payload, "after"), "")),
                    ("owner", or(at(&repository_owner, "name"), "")),
                    ("authorUrl", or(at(&sender, "html_url"), "")),
                    ("authorAvatarUrl", or(at(&sender, "avatar_url"), "")),
                    ("headCommitAuthorName", or(at(&head_commit_author, "name"), "")),
                    ("headCommitAuthorEmail", or(at(&head_commit_author, "email"), "")),
                    ("headCommitMessage", or(at(&head_commit, "message"), "")),
                    ("headCommitUrl", or(at(&head_commit, "url"), "")),
                    ("external", Value::Bool(false)),
                    ("pullRequestNumber", s("")),
                    ("action", s("")),
                    ("affectedFiles", php::list(php::array_keys(&affected))),
                ])])
            }
            "pull_request" => {
                let repository = or(at(&payload, "repository"), Value::Array(Array::new()));
                let repository_owner = or(at(&repository, "owner"), Value::Array(Array::new()));
                let sender = or(at(&payload, "sender"), Value::Array(Array::new()));
                let pull_request = or(at(&payload, "pull_request"), Value::Array(Array::new()));
                let head = or(at(&pull_request, "head"), Value::Array(Array::new()));
                let head_user = or(at(&head, "user"), Value::Array(Array::new()));
                let user = or(at(&pull_request, "user"), Value::Array(Array::new()));
                let base = or(at(&pull_request, "base"), Value::Array(Array::new()));
                let base_user = or(at(&base, "user"), Value::Array(Array::new()));

                let branch = or(at(&head, "ref"), "");
                let repository_url = or(at(&repository, "html_url"), "");
                let branch_url = concat_if_both(&repository_url, "/tree/", &branch);
                let commit_hash = or(at(&head, "sha"), "");
                let head_commit_url = if repository_url.truthy() {
                    s(concat(&[&php::string(&repository_url), b"/commits/", &php::string(&commit_hash)]))
                } else {
                    s("")
                };
                let external = !php::identical(&or(at(&head_user, "login"), ""), &or(at(&base_user, "login"), ""));

                Ok(vec![object([
                    ("branch", branch),
                    ("branchUrl", branch_url),
                    ("repositoryId", s(php::string(&or(at(&repository, "id"), "")))),
                    ("repositoryName", or(at(&repository, "name"), "")),
                    ("repositoryUrl", repository_url),
                    ("installationId", installation_id),
                    ("commitHash", commit_hash),
                    ("owner", or(at(&repository_owner, "login"), "")),
                    ("authorUrl", or(at(&sender, "html_url"), "")),
                    ("authorAvatarUrl", or(at(&user, "avatar_url"), "")),
                    ("headCommitUrl", head_commit_url),
                    ("external", Value::Bool(external)),
                    ("pullRequestNumber", or(at(&payload, "number"), "")),
                    ("action", or(at(&payload, "action"), "")),
                ])])
            }
            "installation" | "installation_repositories" => {
                let account = or(at(&installation, "account"), Value::Array(Array::new()));
                Ok(vec![object([
                    ("action", or(at(&payload, "action"), "")),
                    ("installationId", installation_id),
                    ("userName", or(at(&account, "login"), "")),
                ])])
            }
            _ => Ok(Vec::new()),
        }
    }

    fn event_header_name(&self) -> &str {
        "x-github-event"
    }

    fn signature_header_name(&self) -> &str {
        "x-hub-signature-256"
    }

    fn supported_webhook_scopes(&self) -> &'static [WebhookScope] {
        &[WebhookScope::Installation, WebhookScope::Repository]
    }

    fn repository_url(&self, owner: &str, repository_name: &str) -> String {
        format!("https://github.com/{owner}/{repository_name}")
    }

    fn organization_url(&self, owner: &str) -> Result<String> {
        Ok(format!("https://github.com/{owner}"))
    }

    fn branch_url(&self, owner: &str, repository_name: &str, branch: &str) -> String {
        format!("{}/tree/{branch}", self.repository_url(owner, repository_name))
    }

    fn commit_url(&self, owner: &str, repository_name: &str, commit_hash: &str) -> String {
        format!("{}/commit/{commit_hash}", self.repository_url(owner, repository_name))
    }

    fn file_url(&self, owner: &str, repository_name: &str, reference: &str) -> String {
        format!("{}/blob/{reference}", self.repository_url(owner, repository_name))
    }

    async fn repository_name(&self, repository_id: &str) -> Result<String> {
        let response = self.get(format!("/repositories/{repository_id}")).await?;
        let body = or(response.body, Value::Array(Array::new()));
        let fields = php::expect_array(&body, "array_key_exists", "#2 ($array)")?;
        if !fields.contains_key(&php_std::ArrayKey::from("name")) {
            return Err(Error::RepositoryNotFound("Repository not found".into()));
        }
        php::return_string(or(at(&body, "name"), ""), &format!("{CLASS}::getRepositoryName"))
    }

    async fn list_branches(&self, owner: &str, repository_name: &str) -> Result<Vec<Value>> {
        self.list_branches_page(owner, repository_name, 100, 1, "").await
    }

    async fn list_tags(&self, owner: &str, repository_name: &str, search: &str) -> Result<Vec<Value>> {
        let mut call = Call::get(format!("/repos/{owner}/{repository_name}/git/matching-refs/tags/"));
        if php::filled(&self.access_token) {
            call = call.auth(self.bearer());
        }
        let response = self.transport.call(call).await?;
        let Value::Array(refs) = &response.body else { return Ok(Vec::new()) };
        if !(200..300).contains(&response.status) {
            return Ok(Vec::new());
        }
        let mut tags = Vec::with_capacity(refs.len());
        for r in refs.values() {
            let reference = closure_array(r, CLASS, "listTags", 882, "ref")?;
            tags.push(php::str_replace("refs/tags/", "", &or(at(reference, "ref"), "")));
        }
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
        let response = self
            .transport
            .call(Call::post(format!("/repos/{owner}/{repository_name}/statuses/{sha}")).auth(self.bearer()).params(
                object([
                    ("state", s(state)),
                    ("target_url", s(target_url)),
                    ("description", s(description)),
                    ("context", s(context)),
                ]),
            ))
            .await?;
        if response.status >= 400 {
            return Err(failed(format!("Failed to update commit status: HTTP {}", response.status), response.status));
        }
        Ok(())
    }

    async fn create_check_run(&self, owner: &str, repository_name: &str, head_sha: &str, run: &CheckRun) -> Result<Value> {
        let (status, completed_at) = settle(run)?;
        let mut body = object([("name", s(&run.name)), ("head_sha", s(head_sha)), ("status", s(&status))]);
        for (key, value) in [
            ("conclusion", run.conclusion.as_str()),
            ("completed_at", completed_at.as_str()),
            ("details_url", run.details_url.as_str()),
            ("external_id", run.external_id.as_str()),
            ("started_at", run.started_at.as_str()),
        ] {
            if php::filled(value) {
                php::set(&mut body, key, s(value));
            }
        }
        output(&mut body, run);
        let response = self
            .transport
            .call(Call::post(format!("/repos/{owner}/{repository_name}/check-runs")).auth(self.bearer()).params(body))
            .await?;
        if response.status >= 400 {
            return Err(failed(format!("Failed to create check run: HTTP {}", response.status), response.status));
        }
        Ok(with_string_id(response.body))
    }

    async fn check_run(&self, owner: &str, repository_name: &str, check_run_id: &str) -> Result<Value> {
        let response = self.get(format!("/repos/{owner}/{repository_name}/check-runs/{check_run_id}")).await?;
        if response.status >= 400 {
            return Err(failed(
                format!("Failed to get check run {check_run_id}: HTTP {}", response.status),
                response.status,
            ));
        }
        Ok(with_string_id(response.body))
    }

    async fn update_check_run(
        &self,
        owner: &str,
        repository_name: &str,
        check_run_id: &str,
        run: &CheckRun,
    ) -> Result<Value> {
        let (status, completed_at) = settle(run)?;
        let mut body = Value::Array(Array::new());
        for (key, value) in [
            ("name", run.name.as_str()),
            ("status", status.as_str()),
            ("details_url", run.details_url.as_str()),
            ("external_id", run.external_id.as_str()),
            ("started_at", run.started_at.as_str()),
            ("conclusion", run.conclusion.as_str()),
            ("completed_at", completed_at.as_str()),
        ] {
            if php::filled(value) {
                php::set(&mut body, key, s(value));
            }
        }
        output(&mut body, run);
        let response = self
            .transport
            .call(
                Call::new(Method::Patch, format!("/repos/{owner}/{repository_name}/check-runs/{check_run_id}"))
                    .auth(self.bearer())
                    .params(body),
            )
            .await?;
        if response.status >= 400 {
            return Err(failed(
                format!("Failed to update check run {check_run_id}: HTTP {}", response.status),
                response.status,
            ));
        }
        Ok(with_string_id(response.body))
    }

    async fn repository_tree(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
        recursive: bool,
    ) -> Result<Vec<Value>> {
        let suffix = if recursive { "?recursive=1" } else { "" };
        let response = self.get(format!("/repos/{owner}/{repository_name}/git/trees/{branch}{suffix}")).await?;
        if response.status == 404 {
            return Ok(Vec::new());
        }
        php::array_column(&or(response.at("tree"), Value::Array(Array::new())), "path")
    }

    async fn list_repository_languages(&self, owner: &str, repository_name: &str) -> Result<Vec<Value>> {
        let response = self.get(format!("/repos/{owner}/{repository_name}/languages")).await?;
        let body = or(response.body, Value::Array(Array::new()));
        if !empty(&body) {
            return Ok(php::array_keys(php::expect_array(&body, "array_keys", "#1 ($array)")?));
        }
        Ok(Vec::new())
    }

    async fn list_repository_contents(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        reference: &str,
    ) -> Result<Vec<Value>> {
        let path = normalize_repository_path(path);
        let mut url = format!("/repos/{owner}/{repository_name}/contents");
        if php::filled(&path) {
            url.push_str(&format!("/{path}"));
        }
        if php::filled(reference) {
            url.push_str(&format!("?ref={reference}"));
        }
        let response = self.get(url).await?;
        if response.status == 404 {
            return Ok(Vec::new());
        }
        let body = or(response.body, Value::Array(Array::new()));
        contents_listing(&body)
    }

    async fn repository_content(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        reference: &str,
    ) -> Result<Value> {
        let mut url = format!("/repos/{owner}/{repository_name}/contents/{}", normalize_repository_path(path));
        if php::filled(reference) {
            url.push_str(&format!("?ref={reference}"));
        }
        let response = self.get(url).await?;
        if response.status != 200 {
            return Err(Error::file_not_found());
        }
        let body = or(response.body, Value::Array(Array::new()));
        if !php::is_str(&or(at(&body, "encoding"), ""), "base64") {
            return Err(Error::file_not_found());
        }
        let content = php_std::encoding::base64_decode(&php::string(&or(at(&body, "content"), "")), false)
            .unwrap_or_default();
        Ok(object([
            ("sha", or(at(&body, "sha"), "")),
            ("size", or(at(&body, "size"), Value::Int(0))),
            ("content", s(content)),
        ]))
    }

    async fn commit(&self, owner: &str, repository_name: &str, commit_hash: &str) -> Result<Value> {
        let response = self.get(format!("/repos/{owner}/{repository_name}/commits/{commit_hash}")).await?;
        if response.status == 404 {
            return Err(Error::RepositoryNotFound("Commit not found.".into()));
        }
        if response.status == 422 {
            return Err(Error::exception("Commit not found or inaccessible.", 0));
        }
        let body = response.body;
        let author = or(at(&body, "author"), Value::Array(Array::new()));
        let commit = or(at(&body, "commit"), Value::Array(Array::new()));
        let commit_author = or(at(&commit, "author"), Value::Array(Array::new()));
        Ok(commit_shape([
            ("commitAuthor", or(at(&commit_author, "name"), "Unknown")),
            ("commitMessage", or(at(&commit, "message"), "No message")),
            ("commitAuthorAvatar", or(at(&author, "avatar_url"), "")),
            ("commitAuthorUrl", or(at(&author, "html_url"), "")),
            ("commitHash", or(at(&body, "sha"), "")),
            ("commitUrl", or(at(&body, "html_url"), "")),
        ]))
    }

    async fn latest_commit(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value> {
        let response = self.get(format!("/repos/{owner}/{repository_name}/commits/{branch}?per_page=1")).await?;
        if response.status == 404 {
            return Err(Error::RepositoryNotFound(format!("Branch not found: {branch}")));
        }
        if response.status >= 400 {
            return Err(failed(format!("Failed to get latest commit: HTTP {}", response.status), response.status));
        }
        let body = response.body;
        let commit = or(at(&body, "commit"), Value::Array(Array::new()));
        let commit_author = or(at(&commit, "author"), Value::Array(Array::new()));
        let author = match at(&body, "author") {
            a @ Value::Array(_) => a,
            _ => Value::Array(Array::new()),
        };
        Ok(commit_shape([
            ("commitAuthor", or(at(&commit_author, "name"), "")),
            ("commitMessage", or(at(&commit, "message"), "")),
            ("commitHash", or(at(&body, "sha"), "")),
            ("commitUrl", or(at(&body, "html_url"), "")),
            ("commitAuthorAvatar", or(at(&author, "avatar_url"), "")),
            ("commitAuthorUrl", or(at(&author, "html_url"), "")),
        ]))
    }

    async fn repository_presigned_url(
        &self,
        owner: &str,
        repository_name: &str,
        reference: &str,
        format: &str,
    ) -> Result<String> {
        if format != "tarball" && format != "zipball" {
            return Err(Error::exception(
                format!("Invalid archive format: {format}. Use 'tarball' or 'zipball'."),
                0,
            ));
        }
        let mut url = format!("/repos/{owner}/{repository_name}/{format}");
        if php::filled(reference) {
            // Keep slashes so nested branch names still resolve.
            url.push('/');
            url.push_str(&php_std::encoding::rawurlencode(reference.as_bytes()).replace("%2F", "/"));
        }
        let response = self.transport.call(Call::get(url).auth(self.bearer()).raw().no_follow()).await?;
        if response.status == 404 {
            return Err(Error::RepositoryNotFound("Repository or ref not found.".into()));
        }
        if response.status == 401 || response.status == 403 {
            return Err(Error::exception(
                "Access denied to repository archive; check the access token and its permissions.",
                response.status,
            ));
        }
        let location = response.header("location").unwrap_or("");
        if location.is_empty() || location == "0" {
            return Err(failed(format!("Failed to get presigned URL: HTTP {}", response.status), response.status));
        }
        Ok(location.to_owned())
    }
}

#[async_trait]
impl Git for GitHub {
    async fn create_file(
        &self,
        owner: &str,
        repository_name: &str,
        filepath: &str,
        content: &[u8],
        message: &str,
        branch: &str,
    ) -> Result<Value> {
        let mut payload =
            object([("message", s(message)), ("content", s(php_std::encoding::base64_encode(content)))]);
        if php::filled(branch) {
            php::set(&mut payload, "branch", s(branch));
        }
        let response = self
            .transport
            .call(
                Call::new(Method::Put, format!("/repos/{owner}/{repository_name}/contents/{filepath}"))
                    .auth(self.bearer())
                    .params(payload),
            )
            .await?;
        if response.status >= 400 {
            return Err(failed(format!("Failed to create file {filepath}: HTTP {}", response.status), response.status));
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
        let latest = self.latest_commit(owner, repository_name, old_branch).await?;
        let sha = at(&latest, "commitHash");
        let response = self
            .transport
            .call(Call::post(format!("/repos/{owner}/{repository_name}/git/refs")).auth(self.bearer()).params(
                object([("ref", s(format!("refs/heads/{new_branch}"))), ("sha", sha)]),
            ))
            .await?;
        Ok(or(response.body, Value::Array(Array::new())))
    }

    async fn create_pull_request(
        &self,
        _owner: &str,
        _repository_name: &str,
        _title: &str,
        _head: &str,
        _base: &str,
        _body: &str,
    ) -> Result<Value> {
        Err(Error::exception("Not implemented", 0))
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
            .transport
            .call(Call::post(format!("/repos/{owner}/{repository_name}/hooks")).auth(self.bearer()).params(object([
                ("name", s("web")),
                ("active", Value::Bool(true)),
                ("events", php::list(events.iter().map(s).collect())),
                (
                    "config",
                    object([("url", s(url)), ("content_type", s("json")), ("secret", s(secret))]),
                ),
            ])))
            .await?;
        if response.status >= 400 {
            return Err(failed(format!("Failed to create webhook: HTTP {}", response.status), response.status));
        }
        let id = path(&response.body, &["id"]);
        if matches!(id, Value::Null) {
            return Err(Error::exception("Webhook created but response did not include an id", 0));
        }
        Ok(WebhookId::Int(php::int(&id)))
    }

    async fn create_tag(
        &self,
        _owner: &str,
        _repository_name: &str,
        _tag: &str,
        _target: &str,
        _message: &str,
    ) -> Result<Value> {
        Err(Error::exception("createTag() is not implemented for GitHub", 0))
    }

    async fn commit_statuses(&self, _owner: &str, _repository_name: &str, _commit_hash: &str) -> Result<Vec<Value>> {
        Err(Error::exception("getCommitStatuses() is not implemented for GitHub", 0))
    }
}

/// A check run's status and completion time once a conclusion settles it:
/// a conclusion completes the run, now unless given.
pub(crate) fn settle(run: &CheckRun) -> Result<(String, String)> {
    if run.status == "completed" && !php::filled(&run.conclusion) {
        return Err(Error::exception("conclusion is required when status is 'completed'", 0));
    }
    let mut status = run.status.clone();
    let mut completed_at = run.completed_at.clone();
    if php::filled(&run.conclusion) {
        status = "completed".into();
        if !php::filled(&completed_at) {
            completed_at = time::gmdate_now("Y-m-d\\TH:i:s\\Z");
        }
    }
    Ok((status, completed_at))
}

/// The output of a check run (only with both a title and a summary).
fn output(body: &mut Value, run: &CheckRun) {
    if php::filled(&run.title) && php::filled(&run.summary) {
        let mut output = Value::Array(Array::new());
        for (key, value) in [("title", &run.title), ("summary", &run.summary), ("text", &run.text)] {
            if php::filled(value) {
                php::set(&mut output, key, s(value));
            }
        }
        if !run.annotations.is_empty() {
            php::set(&mut output, "annotations", Value::Array(run.annotations.clone()));
        }
        if !run.images.is_empty() {
            php::set(&mut output, "images", Value::Array(run.images.clone()));
        }
        php::set(body, "output", output);
    }
    if !run.actions.is_empty() {
        php::set(body, "actions", Value::Array(run.actions.clone()));
    }
}

/// A check run with its id as a string.
fn with_string_id(body: Value) -> Value {
    let mut run = match body {
        Value::Null => Value::Array(Array::new()),
        other => other,
    };
    let id = s(php::string(&or(at(&run, "id"), "")));
    php::set(&mut run, "id", id);
    run
}

/// `"$a$infix$b"` when both are non-empty, `''` otherwise.
pub(crate) fn concat_if_both(a: &Value, infix: &str, b: &Value) -> Value {
    if !empty(a) && !empty(b) {
        s(concat(&[&php::string(a), infix.as_bytes(), &php::string(b)]))
    } else {
        s("")
    }
}

/// Byte strings joined.
pub(crate) fn concat(parts: &[&[u8]]) -> Vec<u8> {
    parts.concat()
}

/// A contents listing as `{name, size, type}` entries: a list of items, or
/// one item for a file.
pub(crate) fn contents_listing(body: &Value) -> Result<Vec<Value>> {
    let items = if !empty(&php::at_index(body, 0)) {
        body.clone()
    } else if !empty(body) {
        php::list(vec![body.clone()])
    } else {
        Value::Array(Array::new())
    };
    let Value::Array(items) = items else {
        // A non-empty string body: foreach over it warns and lists nothing.
        return Ok(Vec::new());
    };
    Ok(items
        .values()
        .map(|item| {
            let kind = or(at(item, "type"), "file");
            object([
                ("name", or(at(item, "name"), "")),
                ("size", or(at(item, "size"), Value::Int(0))),
                ("type", s(if php::is_str(&kind, "file") { "file" } else { "dir" })),
            ])
        })
        .collect())
}

/// An unsupported Git method of this adapter.
#[allow(dead_code)]
fn not_supported(method: &str) -> Error {
    unsupported(method, "github")
}
