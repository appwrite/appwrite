//! Gitea and its forks Forgejo and Gogs, with an OAuth or personal access
//! token.

use std::sync::Arc;

use async_trait::async_trait;
use php_std::{Array, ArrayKey, Value};
use utopia_cache::Cache;
use utopia_console::Command;

use crate::adapter::{Adapter, CheckRun, Git, VcsType, WebhookId, WebhookScope, unsupported};
use crate::error::{Error, Result};
use crate::git::{self, clone_command, normalize_repository_path};
use crate::github::{concat_if_both, contents_listing, joined};
use crate::gitlab::return_array;
use crate::php::{self, at, each, empty, object, or, path, s};
use crate::transport::{Call, Method, Response, Transport};

/// The class that declares the family's shared state and methods.
const CLASS: &str = "Utopia\\VCS\\Adapter\\Git\\Gitea";

/// Which member of the Gitea family an adapter speaks to. Forgejo answers
/// Gitea's API under its own names; Gogs, Gitea's ancestor, lacks pull
/// requests, statuses, languages and content writes (which go through git).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Flavor {
    /// Gitea.
    ///
    /// PHP: `Utopia\VCS\Adapter\Git\Gitea`.
    Gitea,
    /// Forgejo.
    ///
    /// PHP: `Utopia\VCS\Adapter\Git\Forgejo`.
    Forgejo,
    /// Gogs.
    ///
    /// PHP: `Utopia\VCS\Adapter\Git\Gogs`.
    Gogs,
}

impl Flavor {
    fn name(self) -> &'static str {
        match self {
            Flavor::Gitea => "gitea",
            Flavor::Forgejo => "forgejo",
            Flavor::Gogs => "gogs",
        }
    }

    fn class(self) -> &'static str {
        match self {
            Flavor::Gitea => "Utopia\\VCS\\Adapter\\Git\\Gitea",
            Flavor::Forgejo => "Utopia\\VCS\\Adapter\\Git\\Forgejo",
            Flavor::Gogs => "Utopia\\VCS\\Adapter\\Git\\Gogs",
        }
    }
}

/// The Gitea adapter, and through its [`Flavor`] the Forgejo and Gogs ones.
///
/// PHP: `Utopia\VCS\Adapter\Git\Gitea`, `Forgejo` and `Gogs`.
pub struct Gitea {
    flavor: Flavor,
    #[allow(dead_code)]
    cache: Arc<Cache>,
    transport: Transport,
    gitea_url: String,
    access_token: Option<String>,
    refresh_token: Option<String>,
}

impl Gitea {
    /// A Gitea adapter on `http://gitea:3000` (see [`Gitea::set_endpoint`]).
    pub fn new(cache: Arc<Cache>) -> Self {
        Self::flavored(Flavor::Gitea, cache)
    }

    /// A Forgejo adapter on `http://forgejo:3000`.
    pub fn forgejo(cache: Arc<Cache>) -> Self {
        Self::flavored(Flavor::Forgejo, cache)
    }

    /// A Gogs adapter on `http://gogs:3000`.
    pub fn gogs(cache: Arc<Cache>) -> Self {
        Self::flavored(Flavor::Gogs, cache)
    }

    /// An adapter of a flavor on its default host.
    pub fn flavored(flavor: Flavor, cache: Arc<Cache>) -> Self {
        let url = format!("http://{}:3000", flavor.name());
        Gitea {
            flavor,
            cache,
            transport: Transport::new(format!("{url}/api/v1")),
            gitea_url: url,
            access_token: None,
            refresh_token: None,
        }
    }

    /// The flavor.
    pub fn flavor(&self) -> Flavor {
        self.flavor
    }

    /// The refresh token given to [`Adapter::initialize`].
    pub fn refresh_token(&self) -> Option<&str> {
        self.refresh_token.as_deref()
    }

    /// Points the adapter at an instance (its web URL; the API is under
    /// `/api/v1`).
    ///
    /// PHP: `setEndpoint()`.
    pub fn set_endpoint(&mut self, endpoint: &str) {
        self.gitea_url = endpoint.trim_end_matches('/').to_owned();
        self.transport.endpoint = format!("{}/api/v1", self.gitea_url);
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
        self.transport.call(call.auth(format!("token {token}"))).await
    }

    async fn get(&self, path: impl Into<String>) -> Result<Response> {
        self.call(Call::get(path)).await
    }

    fn is_gogs(&self) -> bool {
        self.flavor == Flavor::Gogs
    }

    /// Creates an organization and returns its name.
    ///
    /// PHP: `createOrganization()` (Gogs: `POST /user/orgs`).
    pub async fn create_organization(&self, name: &str) -> Result<String> {
        if self.is_gogs() {
            let response = self.call(Call::post("/user/orgs").params(object([("username", s(name))]))).await?;
            return php::return_string(
                or(response.at("username"), ""),
                "Utopia\\VCS\\Adapter\\Git\\Gogs::createOrganization",
            );
        }
        let response =
            self.call(Call::post("/orgs").params(object([("username", s(name)), ("visibility", s("public"))]))).await?;
        php::return_string(or(response.at("name"), ""), &format!("{CLASS}::createOrganization"))
    }

    /// The login of the account the token belongs to.
    async fn authenticated_user_login(&self) -> Result<String> {
        let response = self.get("/user").await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to get authenticated user: HTTP {}", response.status),
                response.status,
            ));
        }
        let login = or(response.at("login"), "");
        if empty(&login) {
            return Err(Error::exception("Authenticated user login missing or empty in response", 0));
        }
        php::return_string(login, &format!("{CLASS}::getAuthenticatedUserLogin"))
    }

    /// Gogs has no repository-by-id endpoint: the search API is paged
    /// through for it.
    async fn find_repository_by_id(&self, repository_id: i64) -> Result<Value> {
        let limit = 50;
        let mut page = 1;
        while page <= 100 {
            let response = self.get(format!("/repos/search?q=_&limit={limit}&page={page}")).await?;
            let repos = or(response.at("data"), Value::Array(Array::new()));
            if empty(&repos) {
                break;
            }
            let listed = each(&repos);
            for repo in &listed {
                if php::identical(&or(at(repo, "id"), Value::Int(0)), &Value::Int(repository_id)) {
                    return Ok(repo.clone());
                }
            }
            let count = match &repos {
                Value::Array(a) => a.len(),
                other => {
                    return Err(Error::type_error(format!(
                        "count(): Argument #1 ($value) must be of type Countable|array, {} given",
                        php::value_name(other)
                    )));
                }
            };
            if count < limit {
                break;
            }
            page += 1;
        }
        Err(Error::RepositoryNotFound("Repository not found".into()))
    }

    /// Gitea and Forgejo's paged branch and tag lists: undecoded pages of
    /// 50, each entry counted when it is an array with a `name`.
    async fn paged_names(&self, kind: &str, owner: &str, repository_name: &str) -> Result<Vec<Value>> {
        let per_page = 50;
        let mut names = Vec::new();
        for page in 1..=100 {
            let response = self
                .call(Call::get(format!("/repos/{owner}/{repository_name}/{kind}?page={page}&limit={per_page}")).raw())
                .await?;
            if response.status == 404 {
                return Ok(Vec::new());
            }
            if response.status >= 400 {
                if page == 1 {
                    return Err(Error::exception(
                        format!("Failed to list {kind}: HTTP {}", response.status),
                        response.status,
                    ));
                }
                break;
            }
            let Some(Value::Array(items)) = php::json_decode(&php::string(&or(response.body, ""))) else { break };
            let mut count = 0;
            for item in items.values() {
                if let Value::Array(fields) = item
                    && fields.contains_key(&ArrayKey::from("name"))
                {
                    names.push(or(at(item, "name"), ""));
                    count += 1;
                }
            }
            if count < per_page {
                break;
            }
        }
        Ok(names)
    }

    /// Gogs' unpaged branch and tag lists.
    async fn gogs_names(&self, kind: &str, owner: &str, repository_name: &str) -> Result<Vec<Value>> {
        let response = self.get(format!("/repos/{owner}/{repository_name}/{kind}")).await?;
        if response.status == 404 {
            return Ok(Vec::new());
        }
        if response.status >= 400 {
            return Err(Error::exception(format!("Failed to list {kind}: HTTP {}", response.status), response.status));
        }
        let Value::Array(items) = &response.body else { return Ok(Vec::new()) };
        Ok(items
            .values()
            .filter_map(|item| match item {
                Value::Array(fields) => fields.get(&ArrayKey::from("name")).cloned(),
                _ => None,
            })
            .collect())
    }

    /// Clones a branch (or the default one) into a fresh temporary directory
    /// over the API host, for the writes Gogs' API cannot make.
    async fn git_clone(&self, owner: &str, repository_name: &str, branch: &str) -> Result<String> {
        let token = self.token()?;
        let clone_url =
            format!("{}/{owner}/{repository_name}.git", self.gitea_url.replace("://", &format!("://{owner}:{token}@")));
        let dir = format!(
            "{}/gogs-{}",
            std::env::temp_dir().to_string_lossy().trim_end_matches('/'),
            php_std::system::uniqid("", false)
        );
        let mut clone = Command::new("git")?.argument("clone")?.option("--depth", "1")?;
        if php::filled(branch) {
            clone = clone.option("-b", branch)?;
        }
        exec(&clone.argument(clone_url)?.argument(dir.as_str())?).await?;
        exec(&git::git(&dir)?.argument("config")?.argument("user.email")?.argument("gogs@test.local")?).await?;
        exec(&git::git(&dir)?.argument("config")?.argument("user.name")?.argument("Gogs Test")?).await?;
        Ok(dir)
    }

    /// Writes a file onto a non-default branch through git: Gogs' contents
    /// API fails there.
    async fn create_file_via_cli(
        &self,
        owner: &str,
        repository_name: &str,
        filepath: &str,
        content: &[u8],
        message: &str,
        branch: &str,
    ) -> Result<Value> {
        let dir = self.git_clone(owner, repository_name, branch).await?;
        let result = async {
            let full = format!("{dir}/{filepath}");
            if let Some(parent) = std::path::Path::new(&full).parent()
                && !parent.is_dir()
            {
                let _ = tokio::fs::create_dir_all(parent).await;
            }
            let _ = tokio::fs::write(&full, content).await;
            exec(&git::git(&dir)?.argument("add")?.argument("--")?.argument(filepath)?).await?;
            exec(&git::git(&dir)?.argument("commit")?.option("-m", message)?).await?;
            exec(&git::git(&dir)?.argument("push")?.argument("origin")?.argument(branch)?).await?;
            Ok::<(), Error>(())
        }
        .await;
        exec(&Command::new("rm")?.flag("-rf")?.argument(dir.as_str())?).await?;
        result?;
        Ok(object([("content", object([("path", s(filepath))]))]))
    }

    /// Gogs' recursive tree: subtrees walked one request at a time.
    fn gogs_tree<'a>(
        &'a self,
        owner: &'a str,
        repository_name: &'a str,
        branch: String,
        recursive: bool,
    ) -> std::pin::Pin<Box<dyn std::future::Future<Output = Result<Vec<Value>>> + Send + 'a>> {
        Box::pin(async move {
            let response = self
                .get(format!(
                    "/repos/{owner}/{repository_name}/git/trees/{}",
                    php_std::encoding::urlencode(branch.as_bytes())
                ))
                .await?;
            if response.status == 404 {
                return Ok(Vec::new());
            }
            let entries = or(response.at("tree"), Value::Array(Array::new()));
            let mut paths = Vec::new();
            for entry in each(&entries) {
                let entry_path = at(&entry, "path");
                paths.push(entry_path.clone());
                if recursive && php::is_str(&or(at(&entry, "type"), ""), "tree") {
                    let sha = php::text(&php::string(&at(&entry, "sha")));
                    for sub in self.gogs_tree(owner, repository_name, sha, true).await? {
                        paths.push(joined(&[&php::string(&entry_path), b"/", &php::string(&sub)]));
                    }
                }
            }
            Ok(paths)
        })
    }
}

/// Runs a command and fails with its output when it exits non-zero (the
/// command itself is left out: its clone URL carries the access token).
async fn exec(command: &Command) -> Result<Vec<u8>> {
    let output = utopia_console::execute(command, b"", None, None).await?;
    if output.status != 0 {
        let mut printed = output.stdout.clone();
        printed.push(b'\n');
        printed.extend_from_slice(&output.stderr);
        let printed = php_std::string::trim(&printed, b" \t\n\r\0\x0B");
        let mut message = format!("Command failed (exit {}): ", output.status).into_bytes();
        message.extend_from_slice(printed);
        return Err(Error::exception(message, 0));
    }
    Ok(output.stdout)
}

/// The repository with `pushed_at` from `updated_at` when it has none.
fn with_pushed_at(mut repository: Value) -> Value {
    if matches!(repository, Value::Array(_)) && matches!(at(&repository, "pushed_at"), Value::Null) {
        let updated = or(at(&repository, "updated_at"), "");
        php::set(&mut repository, "pushed_at", updated);
    }
    repository
}

/// A commit in the shape the family reports for `getCommit()`.
fn commit_shape(body: &Value) -> Value {
    let commit = or(at(body, "commit"), Value::Array(Array::new()));
    let commit_author = or(at(&commit, "author"), Value::Array(Array::new()));
    let author = or(at(body, "author"), Value::Array(Array::new()));
    object([
        ("commitAuthor", or(at(&commit_author, "name"), "Unknown")),
        ("commitMessage", or(at(&commit, "message"), "No message")),
        ("commitAuthorAvatar", or(at(&author, "avatar_url"), "")),
        ("commitAuthorUrl", or(at(&author, "html_url"), "")),
        ("commitHash", or(at(body, "sha"), "")),
        ("commitUrl", or(at(body, "html_url"), "")),
    ])
}

#[async_trait]
impl Adapter for Gitea {
    fn name(&self) -> &str {
        self.flavor.name()
    }

    fn vcs_type(&self) -> VcsType {
        VcsType::Git
    }

    /// Takes the OAuth access token (and keeps the refresh token); the other
    /// parameters are unused.
    async fn initialize(
        &mut self,
        _installation_id: &str,
        _private_key: &str,
        _app_id: Option<&str>,
        access_token: Option<&str>,
        refresh_token: Option<&str>,
    ) -> Result<()> {
        match access_token {
            Some(token) if php::filled(token) => {
                self.access_token = Some(token.to_owned());
                self.refresh_token = refresh_token.map(str::to_owned);
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
        return_array(or(response.body, Value::Array(Array::new())), &format!("{CLASS}::getUser"))
    }

    async fn owner_name(&self, _installation_id: &str, repository_id: Option<i64>) -> Result<String> {
        let Some(id) = repository_id.filter(|id| *id > 0) else {
            return self.authenticated_user_login().await;
        };
        let repository = if self.is_gogs() {
            self.find_repository_by_id(id).await?
        } else {
            let response = self.get(format!("/repositories/{id}")).await?;
            if response.status == 404 {
                return Err(Error::RepositoryNotFound("Repository not found".into()));
            }
            if response.status >= 400 {
                return Err(Error::exception(
                    format!("Failed to get repository: HTTP {}", response.status),
                    response.status,
                ));
            }
            response.body
        };
        let owner = or(at(&repository, "owner"), Value::Array(Array::new()));
        if empty(&at(&owner, "login")) {
            return Err(Error::exception("Owner login missing or empty in response", 0));
        }
        let class = if self.is_gogs() { "Utopia\\VCS\\Adapter\\Git\\Gogs" } else { CLASS };
        php::return_string(at(&owner, "login"), &format!("{class}::getOwnerName"))
    }

    async fn has_access_to_all_repositories(&self) -> Result<bool> {
        Ok(true)
    }

    async fn search_repositories(&self, owner: &str, page: i64, per_page: i64, search: &str) -> Result<Value> {
        if self.is_gogs() && !php::filled(search) {
            // Gogs' search API finds nothing without a query: list the organization instead.
            let response = self.get(format!("/orgs/{owner}/repos")).await?;
            let listed = match response.body {
                Value::Array(a) => a.values().cloned().collect::<Vec<_>>(),
                _ => Vec::new(),
            };
            let total = listed.len() as i64;
            let paged: Vec<Value> = php::slice(&listed, (page - 1).saturating_mul(per_page), per_page)
                .into_iter()
                .map(with_pushed_at)
                .collect();
            return Ok(object([("items", php::list(paged)), ("total", Value::Int(total))]));
        }

        let mut filtered: Vec<Value> = Vec::new();
        let needed = page.saturating_mul(per_page);
        let max = needed.saturating_add(per_page);
        let mut current = 1;
        'pages: while current <= 50 {
            let mut query = vec![("page", Value::Int(current)), ("limit", Value::Int(100))];
            if php::filled(search) {
                query.push(("q", s(search)));
            }
            let query = Value::Array(query.into_iter().map(|(k, v)| (ArrayKey::from(k), v)).collect());
            let query = php_std::encoding::http_build_query(
                &query.to_json(),
                b"",
                None,
                php_std::encoding::QueryEncoding::Rfc1738,
            );
            let response = self.get(format!("/repos/search?{}", String::from_utf8_lossy(&query))).await?;
            if response.status >= 400 {
                return Err(Error::exception(
                    format!("Repository search failed with status code {}", response.status),
                    response.status,
                ));
            }
            let body = response.body;
            let Value::Array(fields) = &body else {
                return Err(Error::exception(
                    format!("Unexpected response body: {}", php::json_encode(&body).unwrap_or_default()),
                    0,
                ));
            };
            if !fields.contains_key(&ArrayKey::from("data")) {
                return Err(Error::exception(
                    format!("Repositories list missing in response: {}", php::json_encode(&body).unwrap_or_default()),
                    0,
                ));
            }
            let repos = at(&body, "data");
            if empty(&repos) {
                break;
            }
            let listed = each(&repos);
            for repo in &listed {
                if php::is_str(&or(path(repo, &["owner", "login"]), ""), owner) {
                    filtered.push(repo.clone());
                    if filtered.len() as i64 >= max {
                        break 'pages;
                    }
                }
            }
            let count = match &repos {
                Value::Array(a) => a.len(),
                other => {
                    return Err(Error::type_error(format!(
                        "count(): Argument #1 ($value) must be of type Countable|array, {} given",
                        php::value_name(other)
                    )));
                }
            };
            if count < 100 {
                break;
            }
            current += 1;
        }
        let total = filtered.len() as i64;
        let paged: Vec<Value> = php::slice(&filtered, (page - 1).saturating_mul(per_page), per_page)
            .into_iter()
            .map(with_pushed_at)
            .collect();
        Ok(object([("items", php::list(paged)), ("total", Value::Int(total))]))
    }

    async fn installation_repository(&self, _repository_name: &str) -> Result<Value> {
        Err(Error::exception(
            "getInstallationRepository is not applicable for this adapter - use getRepository() with owner and repo name instead",
            0,
        ))
    }

    async fn repository(&self, owner: &str, repository_name: &str) -> Result<Value> {
        let response = self.get(format!("/repos/{owner}/{repository_name}")).await?;
        if response.status >= 400 {
            return Err(Error::RepositoryNotFound("Repository not found".into()));
        }
        Ok(match response.body {
            body @ Value::Array(_) => with_pushed_at(body),
            _ => Value::Array(Array::new()),
        })
    }

    async fn create_repository(&self, owner: &str, repository_name: &str, private: bool) -> Result<Value> {
        let call = if self.is_gogs() {
            Call::post(format!("/org/{owner}/repos")).params(object([
                ("name", s(repository_name)),
                ("private", Value::Bool(private)),
                ("auto_init", Value::Bool(true)),
                ("readme", s("Default")),
            ]))
        } else {
            Call::post(format!("/orgs/{owner}/repos"))
                .params(object([("name", s(repository_name)), ("private", Value::Bool(private))]))
        };
        let response = self.call(call).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Creating repository {repository_name} failed with status code {}", response.status),
                response.status,
            ));
        }
        Ok(match response.body {
            body @ Value::Array(_) => with_pushed_at(body),
            _ => Value::Array(Array::new()),
        })
    }

    async fn delete_repository(&self, owner: &str, repository_name: &str) -> Result<bool> {
        let response = self.call(Call::new(Method::Delete, format!("/repos/{owner}/{repository_name}"))).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Deleting repository {repository_name} failed with status code {}", response.status),
                response.status,
            ));
        }
        Ok(true)
    }

    async fn pull_request_from_branch(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value> {
        if self.is_gogs() {
            return Err(Error::exception("Pull request API is not supported by Gogs", 0));
        }
        let response = self
            .get(format!(
                "/repos/{owner}/{repository_name}/pulls?state=open&head={}",
                php_std::encoding::urlencode(branch.as_bytes())
            ))
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to list pull requests: HTTP {}", response.status),
                response.status,
            ));
        }
        let body = or(response.body, Value::Array(Array::new()));
        return_array(
            or(php::at_index(&body, 0), Value::Array(Array::new())),
            &format!("{CLASS}::getPullRequestFromBranch"),
        )
    }

    async fn pull_request(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        if self.is_gogs() {
            return Err(Error::exception("Pull request API is not supported by Gogs", 0));
        }
        let response = self.get(format!("/repos/{owner}/{repository_name}/pulls/{number}")).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to get pull request: HTTP {}", response.status),
                response.status,
            ));
        }
        return_array(or(response.body, Value::Array(Array::new())), &format!("{CLASS}::getPullRequest"))
    }

    async fn pull_request_files(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        if self.is_gogs() {
            return Err(Error::exception("Pull request API is not supported by Gogs", 0));
        }
        let limit = 30;
        let mut all = Value::Array(Array::new());
        for page in 1..=100 {
            let response = self
                .get(format!("/repos/{owner}/{repository_name}/pulls/{number}/files?page={page}&limit={limit}"))
                .await?;
            if response.status >= 400 {
                return Err(Error::exception(
                    format!("Failed to get pull request files: HTTP {}", response.status),
                    response.status,
                ));
            }
            let files = or(response.body, Value::Array(Array::new()));
            let Value::Array(listed) = &files else {
                return Err(Error::exception(
                    format!("Failed to get pull request files: HTTP {} returned a non-JSON body", response.status),
                    0,
                ));
            };
            let count = listed.len();
            all = Value::Array(php::array_merge(&[&all, &files])?);
            if count < limit {
                break;
            }
        }
        Ok(all)
    }

    async fn create_comment(&self, owner: &str, repository_name: &str, number: i64, comment: &str) -> Result<String> {
        let response = self
            .call(
                Call::post(format!("/repos/{owner}/{repository_name}/issues/{number}/comments"))
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
        Ok(php::text(&php::string(&or(at(&body, "id"), ""))))
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
            .call(
                Call::new(Method::Patch, format!("/repos/{owner}/{repository_name}/issues/comments/{comment_id}"))
                    .params(object([("body", s(comment))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to update comment: HTTP {}", response.status),
                response.status,
            ));
        }
        let body = or(response.body, Value::Array(Array::new()));
        let fields = php::expect_array(&body, "array_key_exists", "#2 ($array)")?;
        if !fields.contains_key(&ArrayKey::from("id")) {
            return Err(Error::exception("Comment update response is missing comment ID.", 0));
        }
        Ok(php::text(&php::string(&or(at(&body, "id"), ""))))
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
        let clone_url = match &self.access_token {
            Some(token) if php::filled(token) => {
                format!("{}/{owner}/{repository_name}", self.gitea_url.replace("://", &format!("://{owner}:{token}@")))
            }
            _ => format!("{}/{owner}/{repository_name}", self.gitea_url),
        };
        clone_command(&clone_url, version, version_type, directory, root_directory)
    }

    fn events(&self, event: &str, payload: &[u8]) -> Result<Vec<Value>> {
        let payload = match php::json_decode(payload) {
            Some(v @ Value::Array(_)) => v,
            _ => return Err(Error::exception("Invalid payload.", 0)),
        };
        match event {
            "push" => {
                let repository = or(at(&payload, "repository"), Value::Array(Array::new()));
                let repository_owner = or(at(&repository, "owner"), Value::Array(Array::new()));
                let sender = or(at(&payload, "sender"), Value::Array(Array::new()));
                let head_commit = or(at(&payload, "head_commit"), Value::Array(Array::new()));
                let head_commit_author = or(at(&head_commit, "author"), Value::Array(Array::new()));

                let branch = php::str_replace("refs/heads/", "", &or(at(&payload, "ref"), ""));
                let repository_url = or(at(&repository, "html_url"), "");
                let branch_url = concat_if_both(&repository_url, "/src/branch/", &branch);

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
                    ("repositoryId", Value::Str(php::string(&or(at(&repository, "id"), "")))),
                    ("repositoryName", or(at(&repository, "name"), "")),
                    ("repositoryUrl", repository_url),
                    ("installationId", s("")),
                    ("commitHash", or(at(&payload, "after"), "")),
                    ("owner", or(at(&repository_owner, "login"), "")),
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
                let head_repo = or(at(&head, "repo"), Value::Array(Array::new()));
                let user = or(at(&pull_request, "user"), Value::Array(Array::new()));

                let branch = or(at(&head, "ref"), "");
                let repository_url = or(at(&repository, "html_url"), "");
                let branch_url = concat_if_both(&repository_url, "/src/branch/", &branch);
                let commit_hash = or(at(&head, "sha"), "");
                let head_commit_url = if repository_url.truthy() {
                    joined(&[&php::string(&repository_url), b"/commit/", &php::string(&commit_hash)])
                } else {
                    s("")
                };
                let head_full = or(at(&head_repo, "full_name"), "");
                let base_full = or(at(&repository, "full_name"), "");
                let external = !empty(&head_full) && !empty(&base_full) && !php::identical(&head_full, &base_full);

                Ok(vec![object([
                    ("branch", branch),
                    ("branchUrl", branch_url),
                    ("repositoryId", Value::Str(php::string(&or(at(&repository, "id"), "")))),
                    ("repositoryName", or(at(&repository, "name"), "")),
                    ("repositoryUrl", repository_url),
                    ("installationId", s("")),
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
            _ => Ok(Vec::new()),
        }
    }

    fn event_header_name(&self) -> &str {
        match self.flavor {
            Flavor::Gitea => "x-gitea-event",
            Flavor::Forgejo => "x-forgejo-event",
            Flavor::Gogs => "x-gogs-event",
        }
    }

    fn signature_header_name(&self) -> &str {
        match self.flavor {
            Flavor::Gitea => "x-gitea-signature",
            Flavor::Forgejo => "x-forgejo-signature",
            Flavor::Gogs => "x-gogs-signature",
        }
    }

    fn supported_webhook_scopes(&self) -> &'static [WebhookScope] {
        &[WebhookScope::Repository]
    }

    fn repository_url(&self, owner: &str, repository_name: &str) -> String {
        format!("{}/{owner}/{repository_name}", self.gitea_url)
    }

    fn organization_url(&self, owner: &str) -> Result<String> {
        Ok(format!("{}/{owner}", self.gitea_url))
    }

    fn branch_url(&self, owner: &str, repository_name: &str, branch: &str) -> String {
        if self.is_gogs() {
            return format!("{}/src/{branch}", self.repository_url(owner, repository_name));
        }
        format!("{}/src/branch/{branch}", self.repository_url(owner, repository_name))
    }

    fn commit_url(&self, owner: &str, repository_name: &str, commit_hash: &str) -> String {
        format!("{}/commit/{commit_hash}", self.repository_url(owner, repository_name))
    }

    /// The untyped `/src/{ref}` form, which redirects to the canonical URL
    /// for whichever kind of ref it resolves to.
    fn file_url(&self, owner: &str, repository_name: &str, reference: &str) -> String {
        format!("{}/src/{reference}", self.repository_url(owner, repository_name))
    }

    async fn repository_name(&self, repository_id: &str) -> Result<String> {
        if self.is_gogs() {
            let repository = self.find_repository_by_id(php::int(&s(repository_id))).await?;
            return php::return_string(at(&repository, "name"), "Utopia\\VCS\\Adapter\\Git\\Gogs::getRepositoryName");
        }
        let response = self.get(format!("/repositories/{repository_id}")).await?;
        let body = or(response.body, Value::Array(Array::new()));
        let fields = php::expect_array(&body, "array_key_exists", "#2 ($array)")?;
        if !fields.contains_key(&ArrayKey::from("name")) {
            return Err(Error::RepositoryNotFound("Repository not found".into()));
        }
        php::return_string(or(at(&body, "name"), ""), &format!("{CLASS}::getRepositoryName"))
    }

    async fn list_branches(&self, owner: &str, repository_name: &str) -> Result<Vec<Value>> {
        if self.is_gogs() {
            return self.gogs_names("branches", owner, repository_name).await;
        }
        self.paged_names("branches", owner, repository_name).await
    }

    async fn list_tags(&self, owner: &str, repository_name: &str, search: &str) -> Result<Vec<Value>> {
        let tags = if self.is_gogs() {
            self.gogs_names("tags", owner, repository_name).await?
        } else {
            self.paged_names("tags", owner, repository_name).await?
        };
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
        if self.is_gogs() {
            return Err(Error::exception("Commit status API is not supported by Gogs", 0));
        }
        let mut body = object([("state", s(state))]);
        if php::filled(description) {
            php::set(&mut body, "description", s(description));
        }
        if php::filled(target_url) {
            php::set(&mut body, "target_url", s(target_url));
        }
        if php::filled(context) {
            php::set(&mut body, "context", s(context));
        }
        let response =
            self.call(Call::post(format!("/repos/{owner}/{repository_name}/statuses/{sha}")).params(body)).await?;
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
        _owner: &str,
        _repository_name: &str,
        _head_sha: &str,
        _run: &CheckRun,
    ) -> Result<Value> {
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
        if self.is_gogs() {
            return self.gogs_tree(owner, repository_name, branch.to_owned(), recursive).await;
        }
        let suffix = if recursive { "?recursive=1" } else { "" };
        let response = self
            .get(format!(
                "/repos/{owner}/{repository_name}/git/trees/{}{suffix}",
                php_std::encoding::urlencode(branch.as_bytes())
            ))
            .await?;
        if response.status == 404 {
            return Ok(Vec::new());
        }
        php::array_column(&or(response.at("tree"), Value::Array(Array::new())), "path")
    }

    async fn list_repository_languages(&self, owner: &str, repository_name: &str) -> Result<Vec<Value>> {
        if self.is_gogs() {
            return Err(Error::exception("Listing repository languages is not supported by Gogs", 0));
        }
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
            url.push_str(&format!("?ref={}", php_std::encoding::urlencode(reference.as_bytes())));
        }
        let response = self.get(url).await?;
        if response.status == 404 {
            return Ok(Vec::new());
        }
        contents_listing(&or(response.body, Value::Array(Array::new())))
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
            url.push_str(&format!("?ref={}", php_std::encoding::urlencode(reference.as_bytes())));
        }
        let response = self.get(url).await?;
        if response.status != 200 {
            return Err(Error::file_not_found());
        }
        let body = or(response.body, Value::Array(Array::new()));
        if !php::is_str(&or(at(&body, "encoding"), ""), "base64") {
            return Err(Error::file_not_found());
        }
        let content =
            php_std::encoding::base64_decode(&php::string(&or(at(&body, "content"), "")), false).unwrap_or_default();
        Ok(object([
            ("sha", or(at(&body, "sha"), "")),
            ("size", or(at(&body, "size"), Value::Int(0))),
            ("content", s(content)),
        ]))
    }

    async fn commit(&self, owner: &str, repository_name: &str, commit_hash: &str) -> Result<Value> {
        let path = if self.is_gogs() { "commits" } else { "git/commits" };
        let response = self.get(format!("/repos/{owner}/{repository_name}/{path}/{commit_hash}")).await?;
        if response.status >= 400 {
            return Err(Error::exception("Commit not found or inaccessible", 0));
        }
        Ok(commit_shape(&or(response.body, Value::Array(Array::new()))))
    }

    async fn latest_commit(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value> {
        if self.is_gogs() {
            // Gogs ignores the sha parameter: check the branch exists first.
            let branches = self.list_branches(owner, repository_name).await?;
            if !branches.iter().any(|b| php::is_str(b, branch)) {
                return Err(Error::exception(format!("Branch '{branch}' not found"), 0));
            }
        }
        let query = php_std::encoding::http_build_query(
            &serde_json::json!({"sha": branch, "limit": 1}),
            b"",
            None,
            php_std::encoding::QueryEncoding::Rfc1738,
        );
        let response =
            self.get(format!("/repos/{owner}/{repository_name}/commits?{}", String::from_utf8_lossy(&query))).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Latest commit response failed with status code {}", response.status),
                response.status,
            ));
        }
        let first = php::at_index(&or(response.body, Value::Array(Array::new())), 0);
        if empty(&first) {
            return Err(Error::exception("Latest commit response is missing required information.", 0));
        }
        let commit = or(at(&first, "commit"), Value::Array(Array::new()));
        let commit_author = or(at(&commit, "author"), Value::Array(Array::new()));
        let author = or(at(&first, "author"), Value::Array(Array::new()));
        Ok(object([
            ("commitAuthor", or(at(&commit_author, "name"), "Unknown")),
            ("commitMessage", or(at(&commit, "message"), "No message")),
            ("commitHash", or(at(&first, "sha"), "")),
            ("commitUrl", or(at(&first, "html_url"), "")),
            ("commitAuthorAvatar", or(at(&author, "avatar_url"), "")),
            ("commitAuthorUrl", or(at(&author, "html_url"), "")),
        ]))
    }

    /// An archive URL carrying the access token as a query parameter; an
    /// empty ref resolves to the default branch.
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
        let reference = if php::filled(reference) {
            s(reference)
        } else {
            let resolved = or(at(&self.repository(owner, repository_name).await?, "default_branch"), "");
            if empty(&resolved) {
                return Err(Error::exception("Unable to resolve default branch for archive download.", 0));
            }
            resolved
        };
        let encoded = php_std::encoding::rawurlencode(&php::string(&reference)).replace("%2F", "/");
        Ok(format!(
            "{}/repos/{owner}/{repository_name}/archive/{encoded}.{extension}?token={}",
            self.transport.endpoint,
            php_std::encoding::urlencode(self.token()?.as_bytes())
        ))
    }
}

#[async_trait]
impl Git for Gitea {
    async fn create_file(
        &self,
        owner: &str,
        repository_name: &str,
        filepath: &str,
        content: &[u8],
        message: &str,
        branch: &str,
    ) -> Result<Value> {
        if self.is_gogs() {
            if php::filled(branch) {
                let response = self.get(format!("/repos/{owner}/{repository_name}")).await?;
                let default = or(response.at("default_branch"), "master");
                if !php::identical(&s(branch), &default) {
                    return self.create_file_via_cli(owner, repository_name, filepath, content, message, branch).await;
                }
            }
            let response = self
                .call(Call::new(Method::Put, format!("/repos/{owner}/{repository_name}/contents/{filepath}")).params(
                    object([("content", s(php_std::encoding::base64_encode(content))), ("message", s(message))]),
                ))
                .await?;
            if response.status >= 400 {
                return Err(Error::exception(
                    format!("Failed to create file {filepath}: HTTP {}", response.status),
                    response.status,
                ));
            }
            return Ok(or(response.body, Value::Array(Array::new())));
        }

        let mut payload = object([("content", s(php_std::encoding::base64_encode(content))), ("message", s(message))]);
        if php::filled(branch) {
            php::set(&mut payload, "branch", s(branch));
        }
        let response = self
            .call(Call::post(format!("/repos/{owner}/{repository_name}/contents/{filepath}")).params(payload))
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
        if self.is_gogs() {
            // Gogs cannot create branches through its API: push one with git.
            let dir = self.git_clone(owner, repository_name, old_branch).await?;
            let result = async {
                exec(&git::git(&dir)?.argument("checkout")?.flag("-b")?.argument(new_branch)?).await?;
                exec(&git::git(&dir)?.argument("push")?.argument("origin")?.argument(new_branch)?).await?;
                Ok::<(), Error>(())
            }
            .await;
            exec(&Command::new("rm")?.flag("-rf")?.argument(dir.as_str())?).await?;
            result?;
            return Ok(object([("name", s(new_branch))]));
        }
        let response = self
            .call(
                Call::post(format!("/repos/{owner}/{repository_name}/branches"))
                    .params(object([("new_branch_name", s(new_branch)), ("old_branch_name", s(old_branch))])),
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
        if self.is_gogs() {
            return Err(Error::exception("Pull request API is not supported by Gogs", 0));
        }
        let mut payload = object([("title", s(title)), ("head", s(head)), ("base", s(base))]);
        if php::filled(body) {
            php::set(&mut payload, "body", s(body));
        }
        let response = self.call(Call::post(format!("/repos/{owner}/{repository_name}/pulls")).params(payload)).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to create pull request: HTTP {}", response.status),
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
            .call(Call::post(format!("/repos/{owner}/{repository_name}/hooks")).params(object([
                ("type", s(self.flavor.name())),
                ("active", Value::Bool(true)),
                ("events", php::list(events.iter().map(s).collect())),
                ("config", object([("url", s(url)), ("content_type", s("json")), ("secret", s(secret))])),
            ])))
            .await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to create webhook: HTTP {}", response.status),
                response.status,
            ));
        }
        Ok(WebhookId::Int(php::int(&or(response.at("id"), Value::Int(0)))))
    }

    async fn create_tag(
        &self,
        owner: &str,
        repository_name: &str,
        tag: &str,
        target: &str,
        message: &str,
    ) -> Result<Value> {
        if self.is_gogs() {
            // Gogs cannot create tags through its API: push one with git.
            let dir = self.git_clone(owner, repository_name, "").await?;
            let result = async {
                exec(&git::git(&dir)?.argument("fetch")?.argument("origin")?.argument(target)?).await?;
                if php::filled(message) {
                    exec(
                        &git::git(&dir)?
                            .argument("tag")?
                            .flag("-a")?
                            .argument(tag)?
                            .argument(target)?
                            .option("-m", message)?,
                    )
                    .await?;
                } else {
                    exec(&git::git(&dir)?.argument("tag")?.argument(tag)?.argument(target)?).await?;
                }
                exec(&git::git(&dir)?.argument("push")?.argument("origin")?.argument(tag)?).await?;
                Ok::<(), Error>(())
            }
            .await;
            exec(&Command::new("rm")?.flag("-rf")?.argument(dir.as_str())?).await?;
            result?;
            return Ok(object([("name", s(tag)), ("commit", object([("sha", s(target))]))]));
        }
        let mut payload = object([("tag_name", s(tag)), ("target", s(target))]);
        if php::filled(message) {
            php::set(&mut payload, "message", s(message));
        }
        let response = self.call(Call::post(format!("/repos/{owner}/{repository_name}/tags")).params(payload)).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to create tag {tag}: HTTP {}", response.status),
                response.status,
            ));
        }
        Ok(or(response.body, Value::Array(Array::new())))
    }

    async fn commit_statuses(&self, owner: &str, repository_name: &str, commit_hash: &str) -> Result<Vec<Value>> {
        if self.is_gogs() {
            return Err(Error::exception("Commit status API is not supported by Gogs", 0));
        }
        let response = self.get(format!("/repos/{owner}/{repository_name}/commits/{commit_hash}/statuses")).await?;
        if response.status >= 400 {
            return Err(Error::exception(
                format!("Failed to get commit statuses: HTTP {}", response.status),
                response.status,
            ));
        }
        let Value::Array(statuses) = &response.body else { return Ok(Vec::new()) };
        Ok(statuses
            .values()
            .map(|status| {
                object([
                    ("state", or(at(status, "status"), "")),
                    ("description", or(at(status, "description"), "")),
                    ("target_url", or(at(status, "target_url"), "")),
                    ("context", or(at(status, "context"), "")),
                ])
            })
            .collect())
    }
}

#[allow(dead_code)]
fn class_of(flavor: Flavor) -> &'static str {
    flavor.class()
}
