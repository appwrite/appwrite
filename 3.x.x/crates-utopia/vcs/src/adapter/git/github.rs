//! GitHub adapter (PHP `Utopia\VCS\Adapter\Git\GitHub`).

use std::collections::HashMap;

use jsonwebtoken::{encode, Algorithm, EncodingKey, Header};
use serde::Serialize;
use serde_json::{json, Map, Value};

use crate::adapter::{
    WebhookId, CLONE_TYPE_BRANCH, CLONE_TYPE_COMMIT, CLONE_TYPE_TAG, TYPE_GIT,
    WEBHOOK_SCOPE_INSTALLATION, WEBHOOK_SCOPE_REPOSITORY,
};
use crate::cache::CacheStore;
use crate::error::{FileNotFound, RepositoryNotFound, VcsError};
use crate::http::{
    encode_ref_keep_slash, filter_empty_object, CallResponse, HttpClient, METHOD_DELETE,
    METHOD_GET, METHOD_PATCH, METHOD_POST, METHOD_PUT,
};
use crate::php::{
    array_column_str, array_keys, escape_shell_arg, field_or_null, gmdate_iso, match_glob,
    normalize_repository_path, obj_field, php_empty_str, php_empty_value, php_urlencode, str_field,
    strval, validate_hmac_sha256_prefixed,
};

/// GitHub `push` event name.
pub const EVENT_PUSH: &str = "push";
/// GitHub `pull_request` event name.
pub const EVENT_PULL_REQUEST: &str = "pull_request";
/// GitHub `installation` event name.
pub const EVENT_INSTALLATION: &str = "installation";
/// Directory listing type.
pub const CONTENTS_DIRECTORY: &str = "dir";
/// File listing type.
pub const CONTENTS_FILE: &str = "file";
/// GitHub App JWT expiry in seconds (9 minutes).
pub const GITHUB_APP_JWT_EXPIRY: i64 = 60 * 9;

#[derive(Debug)]
pub struct GitHub {
    http: HttpClient,
    cache: Box<dyn CacheStore>,
    access_token: String,
    jwt_token: String,
    installation_id: String,
}

#[derive(Serialize)]
struct GitHubAppClaims {
    iat: u64,
    exp: u64,
    iss: String,
}

impl GitHub {
    /// PHP `new GitHub($cache)`.
    pub fn new(cache: impl CacheStore + 'static) -> Self {
        Self {
            http: HttpClient::new("https://api.github.com"),
            cache: Box::new(cache),
            access_token: String::new(),
            jwt_token: String::new(),
            installation_id: String::new(),
        }
    }

    /// Override the API host (GitHub Enterprise / tests). Not in PHP GitHub.
    pub fn set_endpoint(&mut self, endpoint: impl Into<String>) {
        self.http.endpoint = endpoint.into().trim_end_matches('/').to_string();
    }

    #[must_use]
    pub fn get_name(&self) -> &'static str {
        "github"
    }

    #[must_use]
    pub fn get_type(&self) -> &'static str {
        TYPE_GIT
    }

    /// PHP `initializeVariables`.
    pub fn initialize_variables(
        &mut self,
        installation_id: &str,
        private_key: &str,
        app_id: Option<&str>,
        _access_token: Option<&str>,
        _refresh_token: Option<&str>,
    ) -> Result<(), VcsError> {
        self.installation_id = installation_id.to_string();
        let ttl = GITHUB_APP_JWT_EXPIRY - 60;
        if let Some(response) = self.cache.load(installation_id, ttl) {
            if let Ok(parsed) = serde_json::from_str::<Value>(&response) {
                self.jwt_token = str_field(&parsed, "jwtToken");
                self.access_token = str_field(&parsed, "accessToken");
                return Ok(());
            }
        }
        self.generate_access_token(private_key, app_id)?;
        let tokens = json!({
            "jwtToken": self.jwt_token,
            "accessToken": self.access_token,
        })
        .to_string();
        self.cache.save(installation_id, &tokens);
        Ok(())
    }

    fn generate_access_token(
        &mut self,
        private_key: &str,
        app_id: Option<&str>,
    ) -> Result<(), VcsError> {
        let iat = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs();
        let exp = iat + GITHUB_APP_JWT_EXPIRY as u64;
        let claims = GitHubAppClaims {
            iat,
            exp,
            iss: app_id.unwrap_or_default().to_string(),
        };
        let key = EncodingKey::from_rsa_pem(private_key.as_bytes()).map_err(|error| {
            VcsError::message(format!("Failed to parse GitHub private key: {error}"))
        })?;
        let token = encode(&Header::new(Algorithm::RS256), &claims, &key).map_err(|error| {
            VcsError::message(format!("Failed to sign GitHub App JWT: {error}"))
        })?;
        let response = self.call(
            METHOD_POST,
            &format!("/app/installations/{}/access_tokens", self.installation_id),
            self.bearer(&token),
            &json!({}),
        )?;
        self.jwt_token = token;
        let body = response.body_object();
        let status = response.status_code();
        if body.get("token").is_none() {
            let safe = match &body {
                Value::Object(map) => {
                    let mut clipped = Map::new();
                    if let Some(message) = map.get("message") {
                        clipped.insert("message".into(), message.clone());
                    }
                    if let Some(url) = map.get("documentation_url") {
                        clipped.insert("documentation_url".into(), url.clone());
                    }
                    serde_json::to_string(&clipped).unwrap_or_default()
                }
                _ => String::new(),
            };
            return Err(VcsError::with_status(
                format!(
                    "Failed to retrieve access token from GitHub API. Status: {status}. Response: {safe}"
                ),
                status,
            ));
        }
        self.access_token = str_field(&body, "token");
        Ok(())
    }

    fn bearer(&self, token: &str) -> HashMap<String, String> {
        HashMap::from([("Authorization".into(), format!("Bearer {token}"))])
    }

    fn auth(&self) -> HashMap<String, String> {
        self.bearer(&self.access_token)
    }

    fn jwt_auth(&self) -> HashMap<String, String> {
        self.bearer(&self.jwt_token)
    }

    fn call(
        &self,
        method: &str,
        path: &str,
        headers: HashMap<String, String>,
        params: &Value,
    ) -> Result<CallResponse, VcsError> {
        self.http.call(method, path, headers, params, true, true)
    }

    fn call_raw(
        &self,
        method: &str,
        path: &str,
        headers: HashMap<String, String>,
        params: &Value,
        decode: bool,
        follow_redirects: bool,
    ) -> Result<CallResponse, VcsError> {
        self.http
            .call(method, path, headers, params, decode, follow_redirects)
    }

    pub fn create_repository(
        &self,
        owner: &str,
        repository_name: &str,
        private: bool,
    ) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_POST,
            &format!("/orgs/{owner}/repos"),
            self.auth(),
            &json!({"name": repository_name, "private": private}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Creating repository {repository_name} failed with status code {status}"),
                status,
            ));
        }
        Ok(response.body_object())
    }

    pub fn create_pull_request(
        &self,
        _owner: &str,
        _repository_name: &str,
        _title: &str,
        _head: &str,
        _base: &str,
        _body: &str,
    ) -> Result<Value, VcsError> {
        Err(VcsError::message("Not implemented"))
    }

    pub fn create_webhook(
        &self,
        owner: &str,
        repository_name: &str,
        url: &str,
        secret: &str,
        events: &[&str],
    ) -> Result<WebhookId, VcsError> {
        let events = if events.is_empty() {
            vec!["push", "pull_request"]
        } else {
            events.to_vec()
        };
        let response = self.call(
            METHOD_POST,
            &format!("/repos/{owner}/{repository_name}/hooks"),
            self.auth(),
            &json!({
                "name": "web",
                "active": true,
                "events": events,
                "config": {
                    "url": url,
                    "content_type": "json",
                    "secret": secret,
                },
            }),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to create webhook: HTTP {status}"),
                status,
            ));
        }
        let id = response.body.get("id").cloned().ok_or_else(|| {
            VcsError::message("Webhook created but response did not include an id")
        })?;
        Ok(WebhookId::Number(id.as_i64().unwrap_or(0)))
    }

    pub fn create_file(
        &self,
        owner: &str,
        repository_name: &str,
        filepath: &str,
        content: &str,
        message: &str,
        branch: &str,
    ) -> Result<Value, VcsError> {
        let mut payload = json!({
            "message": message,
            "content": base64::Engine::encode(&base64::engine::general_purpose::STANDARD, content.as_bytes()),
        });
        if !php_empty_str(branch) {
            payload["branch"] = json!(branch);
        }
        let response = self.call(
            METHOD_PUT,
            &format!("/repos/{owner}/{repository_name}/contents/{filepath}"),
            self.auth(),
            &payload,
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to create file {filepath}: HTTP {status}"),
                status,
            ));
        }
        Ok(response.body_object())
    }

    pub fn create_branch(
        &self,
        owner: &str,
        repository_name: &str,
        new_branch_name: &str,
        old_branch_name: &str,
    ) -> Result<Value, VcsError> {
        let latest = self.get_latest_commit(owner, repository_name, old_branch_name)?;
        let sha = str_field(&latest, "commitHash");
        let response = self.call(
            METHOD_POST,
            &format!("/repos/{owner}/{repository_name}/git/refs"),
            self.auth(),
            &json!({"ref": format!("refs/heads/{new_branch_name}"), "sha": sha}),
        )?;
        Ok(response.body_object())
    }

    pub fn has_access_to_all_repositories(&self) -> Result<bool, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/app/installations/{}", self.installation_id),
            self.jwt_auth(),
            &json!({}),
        )?;
        Ok(str_field(&response.body, "repository_selection") == "all")
    }

    pub fn search_repositories(
        &self,
        owner: &str,
        page: i64,
        per_page: i64,
        search: &str,
    ) -> Result<Value, VcsError> {
        if self.has_access_to_all_repositories()? {
            let response = self.call(
                METHOD_GET,
                "/search/repositories",
                self.auth(),
                &json!({
                    "q": format!("{search} user:{owner} fork:true"),
                    "page": page,
                    "per_page": per_page,
                    "sort": "updated",
                }),
            )?;
            let status = response.status_code();
            if status == 422 {
                return Ok(json!({"items": [], "total": 0}));
            }
            if status >= 400 {
                return Err(VcsError::with_status(
                    format!("Failed to search repositories: HTTP {status}"),
                    status,
                ));
            }
            let body = response.body_object();
            return Ok(json!({
                "items": body.get("items").cloned().unwrap_or_else(|| json!([])),
                "total": body.get("total_count").cloned().unwrap_or(json!(0)),
            }));
        }

        if php_empty_str(search) {
            let response = self.call(
                METHOD_GET,
                "/installation/repositories",
                self.auth(),
                &json!({"page": page, "per_page": per_page}),
            )?;
            let status = response.status_code();
            if status >= 400 {
                return Err(VcsError::with_status(
                    format!("Failed to list installation repositories: HTTP {status}"),
                    status,
                ));
            }
            let body = response.body_object();
            return Ok(json!({
                "items": body.get("repositories").cloned().unwrap_or_else(|| json!([])),
                "total": body.get("total_count").cloned().unwrap_or(json!(0)),
            }));
        }

        let mut repositories = Vec::new();
        let mut current_page = 1_i64;
        loop {
            let response = self.call(
                METHOD_GET,
                "/installation/repositories",
                self.auth(),
                &json!({"page": current_page, "per_page": 100}),
            )?;
            let status = response.status_code();
            if status >= 400 {
                return Err(VcsError::with_status(
                    format!("Failed to list installation repositories: HTTP {status}"),
                    status,
                ));
            }
            let repos = response
                .body
                .get("repositories")
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            for repo in &repos {
                let name = str_field(repo, "name");
                if name
                    .to_ascii_lowercase()
                    .contains(&search.to_ascii_lowercase())
                {
                    repositories.push(repo.clone());
                }
            }
            if repos.len() < 100 {
                break;
            }
            current_page += 1;
        }
        let start = ((page - 1) * per_page).max(0) as usize;
        let total = repositories.len();
        let items: Vec<Value> = repositories
            .into_iter()
            .skip(start)
            .take(per_page.max(0) as usize)
            .collect();
        Ok(json!({"items": items, "total": total}))
    }

    pub fn get_installation_repository(&self, repository_name: &str) -> Result<Value, VcsError> {
        let mut current_page = 1_i64;
        let per_page = 100_i64;
        let mut total_repositories = 0_i64;
        while total_repositories < 1000 {
            let response = self.call(
                METHOD_GET,
                "/installation/repositories",
                self.auth(),
                &json!({"page": current_page, "per_page": per_page}),
            )?;
            let body = response.body_object();
            let Some(repos) = body.get("repositories").and_then(Value::as_array) else {
                return Err(VcsError::message(
                    "Repositories list missing in the response.",
                ));
            };
            for repo in repos {
                if str_field(repo, "name").eq_ignore_ascii_case(repository_name) {
                    return Ok(repo.clone());
                }
            }
            if (repos.len() as i64) < per_page {
                break;
            }
            current_page += 1;
            total_repositories += per_page;
        }
        Err(RepositoryNotFound::new("Repository not found.").into())
    }

    pub fn get_repository(&self, owner: &str, repository_name: &str) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}"),
            self.auth(),
            &json!({}),
        )?;
        let status = response.status_code();
        if status == 404 || status == 422 {
            return Err(RepositoryNotFound::new("Repository not found.").into());
        }
        Ok(response.body_object())
    }

    pub fn get_repository_name(&self, repository_id: &str) -> Result<String, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repositories/{repository_id}"),
            self.auth(),
            &json!({}),
        )?;
        if response.body.get("name").is_none() {
            return Err(RepositoryNotFound::new("Repository not found").into());
        }
        Ok(str_field(&response.body, "name"))
    }

    pub fn get_repository_tree(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
        recursive: bool,
    ) -> Result<Vec<String>, VcsError> {
        let suffix = if recursive { "?recursive=1" } else { "" };
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/git/trees/{branch}{suffix}"),
            self.auth(),
            &json!({}),
        )?;
        if response.status_code() == 404 {
            return Ok(Vec::new());
        }
        let tree = response
            .body
            .get("tree")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default();
        Ok(array_column_str(&tree, "path"))
    }

    pub fn list_repository_languages(
        &self,
        owner: &str,
        repository_name: &str,
    ) -> Result<Vec<String>, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/languages"),
            self.auth(),
            &json!({}),
        )?;
        if php_empty_value(&response.body) {
            Ok(Vec::new())
        } else {
            Ok(array_keys(&response.body))
        }
    }

    pub fn get_repository_content(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        ref_name: &str,
    ) -> Result<Value, VcsError> {
        let mut url = format!(
            "/repos/{owner}/{repository_name}/contents/{}",
            normalize_repository_path(path)
        );
        if !php_empty_str(ref_name) {
            url.push_str("?ref=");
            url.push_str(ref_name);
        }
        let response = self.call(METHOD_GET, &url, self.auth(), &json!({}))?;
        if response.status_code() != 200 {
            return Err(FileNotFound::new().into());
        }
        if str_field(&response.body, "encoding") != "base64" {
            return Err(FileNotFound::new().into());
        }
        let raw = str_field(&response.body, "content");
        let cleaned: String = raw.chars().filter(|c| !c.is_whitespace()).collect();
        let content = base64::Engine::decode(&base64::engine::general_purpose::STANDARD, cleaned)
            .unwrap_or_default();
        Ok(json!({
            "sha": str_field(&response.body, "sha"),
            "size": response.body.get("size").cloned().unwrap_or(json!(0)),
            "content": String::from_utf8_lossy(&content),
        }))
    }

    pub fn list_repository_contents(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        ref_name: &str,
    ) -> Result<Vec<Value>, VcsError> {
        let path = normalize_repository_path(path);
        let mut url = format!("/repos/{owner}/{repository_name}/contents");
        if !path.is_empty() {
            url.push('/');
            url.push_str(&path);
        }
        if !php_empty_str(ref_name) {
            url.push_str("?ref=");
            url.push_str(ref_name);
        }
        let response = self.call(METHOD_GET, &url, self.auth(), &json!({}))?;
        if response.status_code() == 404 {
            return Ok(Vec::new());
        }
        let items = match &response.body {
            Value::Array(items) if !items.is_empty() => items.clone(),
            Value::Object(map) if !map.is_empty() => vec![response.body.clone()],
            _ => Vec::new(),
        };
        let mut contents = Vec::new();
        for item in items {
            let kind = str_field(&item, "type");
            contents.push(json!({
                "name": str_field(&item, "name"),
                "size": item.get("size").cloned().unwrap_or(json!(0)),
                "type": if kind == "file" { CONTENTS_FILE } else { CONTENTS_DIRECTORY },
            }));
        }
        Ok(contents)
    }

    pub fn delete_repository(&self, owner: &str, repository_name: &str) -> Result<bool, VcsError> {
        let response = self.call(
            METHOD_DELETE,
            &format!("/repos/{owner}/{repository_name}"),
            self.auth(),
            &json!({}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Deleting repository {repository_name} failed with status code {status}"),
                status,
            ));
        }
        Ok(true)
    }

    pub fn create_comment(
        &self,
        owner: &str,
        repository_name: &str,
        pull_request_number: i64,
        comment: &str,
    ) -> Result<String, VcsError> {
        let response = self.call(
            METHOD_POST,
            &format!("/repos/{owner}/{repository_name}/issues/{pull_request_number}/comments"),
            self.auth(),
            &json!({"body": comment}),
        )?;
        if response.body.get("id").is_none() {
            return Err(VcsError::message(
                "Comment creation response is missing comment ID.",
            ));
        }
        Ok(str_field(&response.body, "id"))
    }

    pub fn get_comment(
        &self,
        owner: &str,
        repository_name: &str,
        comment_id: &str,
    ) -> Result<String, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/issues/comments/{comment_id}"),
            self.auth(),
            &json!({}),
        )?;
        Ok(str_field(&response.body, "body"))
    }

    pub fn update_comment(
        &self,
        owner: &str,
        repository_name: &str,
        comment_id: &str,
        comment: &str,
    ) -> Result<String, VcsError> {
        let response = self.call(
            METHOD_PATCH,
            &format!("/repos/{owner}/{repository_name}/issues/comments/{comment_id}"),
            self.auth(),
            &json!({"body": comment}),
        )?;
        if response.body.get("id").is_none() {
            return Err(VcsError::message(
                "Comment update response is missing comment ID.",
            ));
        }
        Ok(str_field(&response.body, "id"))
    }

    pub fn get_user(&self, username: &str) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/users/{username}"),
            HashMap::new(),
            &json!({}),
        )?;
        Ok(response.to_value())
    }

    pub fn get_owner_name(
        &self,
        installation_id: &str,
        _repository_id: Option<i64>,
    ) -> Result<String, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/app/installations/{installation_id}"),
            self.jwt_auth(),
            &json!({}),
        )?;
        let account = obj_field(&response.body, "account");
        if account.get("login").is_none() {
            return Err(VcsError::message(
                "Owner name retrieval response is missing account login.",
            ));
        }
        Ok(str_field(account, "login"))
    }

    pub fn get_pull_request(
        &self,
        owner: &str,
        repository_name: &str,
        pull_request_number: i64,
    ) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/pulls/{pull_request_number}"),
            self.auth(),
            &json!({}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get pull request: HTTP {status}"),
                status,
            ));
        }
        Ok(response.body_object())
    }

    pub fn get_pull_request_files(
        &self,
        owner: &str,
        repository_name: &str,
        pull_request_number: i64,
    ) -> Result<Vec<Value>, VcsError> {
        let mut all_files = Vec::new();
        let per_page = 30_i64;
        let mut current_page = 1_i64;
        loop {
            let response = self.call(
                METHOD_GET,
                &format!("/repos/{owner}/{repository_name}/pulls/{pull_request_number}/files"),
                self.auth(),
                &json!({"per_page": per_page, "page": current_page}),
            )?;
            let files = response.body.as_array().cloned().unwrap_or_default();
            let count = files.len();
            all_files.extend(files);
            if (count as i64) < per_page {
                break;
            }
            current_page += 1;
        }
        Ok(all_files)
    }

    pub fn get_pull_request_from_branch(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
    ) -> Result<Value, VcsError> {
        let head = format!("{owner}:{branch}");
        let response = self.call(
            METHOD_GET,
            &format!(
                "/repos/{owner}/{repository_name}/pulls?head={head}&state=open&sort=updated&per_page=1"
            ),
            self.auth(),
            &json!({}),
        )?;
        Ok(response
            .body
            .as_array()
            .and_then(|items| items.first())
            .cloned()
            .unwrap_or(json!({})))
    }

    pub fn list_branches(
        &self,
        owner: &str,
        repository_name: &str,
    ) -> Result<Vec<String>, VcsError> {
        self.list_branches_paginated(owner, repository_name, 100, 1, "")
    }

    /// PHP `GitHub::listBranches` extra `$perPage`, `$page`, `$search` args.
    pub fn list_branches_paginated(
        &self,
        owner: &str,
        repository_name: &str,
        per_page: i64,
        page: i64,
        search: &str,
    ) -> Result<Vec<String>, VcsError> {
        let per_page = per_page.clamp(1, 100);
        if !search.is_empty() {
            let encoded = encode_ref_keep_slash(search);
            let response = self.call(
                METHOD_GET,
                &format!("/repos/{owner}/{repository_name}/git/matching-refs/heads/{encoded}"),
                self.auth(),
                &json!({}),
            )?;
            let status = response.status_code();
            let Some(items) = response.body.as_array() else {
                return Ok(Vec::new());
            };
            if !(200..300).contains(&status) {
                return Ok(Vec::new());
            }
            let branches: Vec<String> = items
                .iter()
                .map(|r| str_field(r, "ref").replacen("refs/heads/", "", 1))
                .collect();
            let offset = ((page - 1) * per_page).max(0) as usize;
            return Ok(branches
                .into_iter()
                .skip(offset)
                .take(per_page as usize)
                .collect());
        }
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/branches"),
            self.auth(),
            &json!({"page": page, "per_page": per_page}),
        )?;
        let status = response.status_code();
        let Some(items) = response.body.as_array() else {
            return Ok(Vec::new());
        };
        if !(200..300).contains(&status) {
            return Ok(Vec::new());
        }
        Ok(items.iter().map(|b| str_field(b, "name")).collect())
    }

    pub fn list_tags(
        &self,
        owner: &str,
        repository_name: &str,
        search: &str,
    ) -> Result<Vec<String>, VcsError> {
        let headers = if php_empty_str(&self.access_token) {
            HashMap::new()
        } else {
            self.auth()
        };
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/git/matching-refs/tags/"),
            headers,
            &json!({}),
        )?;
        let status = response.status_code();
        let Some(items) = response.body.as_array() else {
            return Ok(Vec::new());
        };
        if !(200..300).contains(&status) {
            return Ok(Vec::new());
        }
        let tags: Vec<String> = items
            .iter()
            .map(|r| str_field(r, "ref").replacen("refs/tags/", "", 1))
            .collect();
        Ok(match_glob(tags, search))
    }

    pub fn get_commit(
        &self,
        owner: &str,
        repository_name: &str,
        commit_hash: &str,
    ) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/commits/{commit_hash}"),
            self.auth(),
            &json!({}),
        )?;
        let status = response.status_code();
        if status == 404 {
            return Err(RepositoryNotFound::new("Commit not found.").into());
        }
        if status == 422 {
            return Err(VcsError::message("Commit not found or inaccessible."));
        }
        let author = obj_field(&response.body, "author");
        let commit = obj_field(&response.body, "commit");
        let commit_author = obj_field(commit, "author");
        Ok(json!({
            "commitAuthor": nonempty_or(str_field(commit_author, "name"), "Unknown"),
            "commitMessage": nonempty_or(str_field(commit, "message"), "No message"),
            "commitAuthorAvatar": str_field(author, "avatar_url"),
            "commitAuthorUrl": str_field(author, "html_url"),
            "commitHash": str_field(&response.body, "sha"),
            "commitUrl": str_field(&response.body, "html_url"),
        }))
    }

    pub fn get_latest_commit(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
    ) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/commits/{branch}?per_page=1"),
            self.auth(),
            &json!({}),
        )?;
        let status = response.status_code();
        if status == 404 {
            return Err(RepositoryNotFound::new(format!("Branch not found: {branch}")).into());
        }
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get latest commit: HTTP {status}"),
                status,
            ));
        }
        let commit = obj_field(&response.body, "commit");
        let commit_author = obj_field(commit, "author");
        let author = if response.body.get("author").is_some_and(Value::is_object) {
            obj_field(&response.body, "author")
        } else {
            &json!({})
        };
        Ok(json!({
            "commitAuthor": str_field(commit_author, "name"),
            "commitMessage": str_field(commit, "message"),
            "commitHash": str_field(&response.body, "sha"),
            "commitUrl": str_field(&response.body, "html_url"),
            "commitAuthorAvatar": str_field(author, "avatar_url"),
            "commitAuthorUrl": str_field(author, "html_url"),
        }))
    }

    pub fn get_repository_presigned_url(
        &self,
        owner: &str,
        repository_name: &str,
        ref_name: &str,
        format: &str,
    ) -> Result<String, VcsError> {
        if format != "tarball" && format != "zipball" {
            return Err(VcsError::message(format!(
                "Invalid archive format: {format}. Use 'tarball' or 'zipball'."
            )));
        }
        let mut url = format!("/repos/{owner}/{repository_name}/{format}");
        if !php_empty_str(ref_name) {
            url.push('/');
            url.push_str(&encode_ref_keep_slash(ref_name));
        }
        let response = self.call_raw(METHOD_GET, &url, self.auth(), &json!({}), false, false)?;
        let status = response.status_code();
        if status == 404 {
            return Err(RepositoryNotFound::new("Repository or ref not found.").into());
        }
        if status == 401 || status == 403 {
            return Err(VcsError::with_status(
                "Access denied to repository archive; check the access token and its permissions.",
                status,
            ));
        }
        let presigned = response.header("location");
        if php_empty_str(&presigned) {
            return Err(VcsError::with_status(
                format!("Failed to get presigned URL: HTTP {status}"),
                status,
            ));
        }
        Ok(presigned)
    }

    #[must_use]
    pub fn get_repository_presigned_url_headers(&self) -> HashMap<String, String> {
        HashMap::new()
    }

    pub fn update_commit_status(
        &self,
        repository_name: &str,
        commit_hash: &str,
        owner: &str,
        state: &str,
        description: &str,
        target_url: &str,
        context: &str,
    ) -> Result<(), VcsError> {
        let response = self.call(
            METHOD_POST,
            &format!("/repos/{owner}/{repository_name}/statuses/{commit_hash}"),
            self.auth(),
            &json!({
                "state": state,
                "target_url": target_url,
                "description": description,
                "context": context,
            }),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to update commit status: HTTP {status}"),
                status,
            ));
        }
        Ok(())
    }

    #[allow(clippy::too_many_arguments)]
    pub fn create_check_run(
        &self,
        owner: &str,
        repository_name: &str,
        head_sha: &str,
        name: &str,
        status: &str,
        conclusion: &str,
        title: &str,
        summary: &str,
        text: &str,
        annotations: &[Value],
        images: &[Value],
        actions: &[Value],
        details_url: &str,
        external_id: &str,
        started_at: &str,
        completed_at: &str,
    ) -> Result<Value, VcsError> {
        let (status, conclusion, completed_at) =
            settle_check_run(status, conclusion, completed_at)?;
        let mut body = json!({
            "name": name,
            "head_sha": head_sha,
            "status": status,
        });
        let extra = filter_empty_object(vec![
            ("conclusion", json!(conclusion)),
            ("completed_at", json!(completed_at)),
            ("details_url", json!(details_url)),
            ("external_id", json!(external_id)),
            ("started_at", json!(started_at)),
        ]);
        if let Value::Object(map) = &mut body {
            map.extend(extra);
        }
        attach_check_output(&mut body, title, summary, text, annotations, images);
        if !actions.is_empty() {
            body["actions"] = json!(actions);
        }
        let response = self.call(
            METHOD_POST,
            &format!("/repos/{owner}/{repository_name}/check-runs"),
            self.auth(),
            &body,
        )?;
        let status_code = response.status_code();
        if status_code >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to create check run: HTTP {status_code}"),
                status_code,
            ));
        }
        Ok(stringify_check_run_id(response.body_object()))
    }

    pub fn get_check_run(
        &self,
        owner: &str,
        repository_name: &str,
        check_run_id: &str,
    ) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/check-runs/{check_run_id}"),
            self.auth(),
            &json!({}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get check run {check_run_id}: HTTP {status}"),
                status,
            ));
        }
        Ok(stringify_check_run_id(response.body_object()))
    }

    #[allow(clippy::too_many_arguments)]
    pub fn update_check_run(
        &self,
        owner: &str,
        repository_name: &str,
        check_run_id: &str,
        name: &str,
        status: &str,
        conclusion: &str,
        title: &str,
        summary: &str,
        text: &str,
        annotations: &[Value],
        images: &[Value],
        actions: &[Value],
        details_url: &str,
        external_id: &str,
        started_at: &str,
        completed_at: &str,
    ) -> Result<Value, VcsError> {
        let (status, conclusion, completed_at) =
            settle_check_run(status, conclusion, completed_at)?;
        let mut body = Value::Object(filter_empty_object(vec![
            ("name", json!(name)),
            ("status", json!(status)),
            ("details_url", json!(details_url)),
            ("external_id", json!(external_id)),
            ("started_at", json!(started_at)),
            ("conclusion", json!(conclusion)),
            ("completed_at", json!(completed_at)),
        ]));
        attach_check_output(&mut body, title, summary, text, annotations, images);
        if !actions.is_empty() {
            body["actions"] = json!(actions);
        }
        let response = self.call(
            METHOD_PATCH,
            &format!("/repos/{owner}/{repository_name}/check-runs/{check_run_id}"),
            self.auth(),
            &body,
        )?;
        let status_code = response.status_code();
        if status_code >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to update check run {check_run_id}: HTTP {status_code}"),
                status_code,
            ));
        }
        Ok(stringify_check_run_id(response.body_object()))
    }

    pub fn generate_clone_command(
        &self,
        owner: &str,
        repository_name: &str,
        version: &str,
        version_type: &str,
        directory: &str,
        root_directory: &str,
    ) -> Result<String, VcsError> {
        let root_directory = if php_empty_str(root_directory) {
            "*"
        } else {
            root_directory
        };
        let owner_enc = php_urlencode(owner);
        let repo_enc = php_urlencode(repository_name);
        let access = if php_empty_str(&self.access_token) {
            String::new()
        } else {
            format!(":{}", php_urlencode(&self.access_token))
        };
        let clone_url = format!("https://{owner_enc}{access}@github.com/{owner_enc}/{repo_enc}");
        let directory = escape_shell_arg(directory);
        let root_directory = escape_shell_arg(root_directory);
        let mut commands = vec![
            format!("mkdir -p {directory}"),
            format!("cd {directory}"),
            "git config --global init.defaultBranch main".into(),
            "git init".into(),
            format!("git remote add origin {clone_url}"),
            "git config core.sparseCheckout true".into(),
            format!("echo {root_directory} >> .git/info/sparse-checkout"),
            "git config --add remote.origin.fetch '+refs/heads/*:refs/remotes/origin/*'".into(),
            "git config remote.origin.tagopt --no-tags".into(),
        ];
        match version_type {
            CLONE_TYPE_BRANCH => {
                let branch = escape_shell_arg(version);
                commands.push(format!(
                    "if git ls-remote --exit-code --heads origin {branch}; then git pull --depth=1 origin {branch} && git checkout {branch}; else git checkout -b {branch}; fi"
                ));
            }
            CLONE_TYPE_COMMIT => {
                let hash = escape_shell_arg(version);
                commands.push(format!(
                    "git fetch --depth=1 origin {hash} && git checkout {hash}"
                ));
            }
            CLONE_TYPE_TAG => {
                let tag = escape_shell_arg(version);
                commands.push(format!(
                    "git fetch --depth=1 origin refs/tags/$(git ls-remote --tags origin {tag} | tail -n 1 | awk -F '/' '{{print $3}}') && git checkout FETCH_HEAD"
                ));
            }
            _ => {}
        }
        Ok(commands.join(" && "))
    }

    #[must_use]
    pub fn get_event_header_name(&self) -> &'static str {
        "x-github-event"
    }

    #[must_use]
    pub fn get_signature_header_name(&self) -> &'static str {
        "x-hub-signature-256"
    }

    #[must_use]
    pub fn get_supported_webhook_scopes(&self) -> &'static [&'static str] {
        &[WEBHOOK_SCOPE_INSTALLATION, WEBHOOK_SCOPE_REPOSITORY]
    }

    #[must_use]
    pub fn get_repository_url(&self, owner: &str, repository_name: &str) -> String {
        format!("https://github.com/{owner}/{repository_name}")
    }

    #[must_use]
    pub fn get_branch_url(&self, owner: &str, repository_name: &str, branch: &str) -> String {
        format!(
            "{}/tree/{branch}",
            self.get_repository_url(owner, repository_name)
        )
    }

    #[must_use]
    pub fn get_commit_url(&self, owner: &str, repository_name: &str, commit_hash: &str) -> String {
        format!(
            "{}/commit/{commit_hash}",
            self.get_repository_url(owner, repository_name)
        )
    }

    #[must_use]
    pub fn get_file_url(&self, owner: &str, repository_name: &str, reference: &str) -> String {
        format!(
            "{}/blob/{reference}",
            self.get_repository_url(owner, repository_name)
        )
    }

    pub fn get_events(&self, event: &str, payload: &str) -> Result<Vec<Value>, VcsError> {
        github_get_events(event, payload)
    }

    #[must_use]
    pub fn validate_webhook_event(
        &self,
        payload: &str,
        signature: &str,
        signature_key: &str,
    ) -> bool {
        validate_hmac_sha256_prefixed(payload, signature, signature_key)
    }

    pub fn create_tag(
        &self,
        _owner: &str,
        _repository_name: &str,
        _tag_name: &str,
        _target: &str,
        _message: &str,
    ) -> Result<Value, VcsError> {
        Err(VcsError::message(
            "createTag() is not implemented for GitHub",
        ))
    }

    pub fn get_commit_statuses(
        &self,
        _owner: &str,
        _repository_name: &str,
        _commit_hash: &str,
    ) -> Result<Vec<Value>, VcsError> {
        Err(VcsError::message(
            "getCommitStatuses() is not implemented for GitHub",
        ))
    }

    pub fn list_namespaces(
        &self,
        _page: i64,
        _per_page: i64,
        _search: &str,
    ) -> Result<Value, VcsError> {
        Err(VcsError::message(format!(
            "listNamespaces() is not supported by {}",
            self.get_name()
        )))
    }
}

fn nonempty_or(value: String, fallback: &str) -> String {
    if value.is_empty() {
        fallback.to_string()
    } else {
        value
    }
}

fn settle_check_run(
    status: &str,
    conclusion: &str,
    completed_at: &str,
) -> Result<(String, String, String), VcsError> {
    let mut status = status.to_string();
    let conclusion = conclusion.to_string();
    let mut completed_at = completed_at.to_string();
    if status == "completed" && php_empty_str(&conclusion) {
        return Err(VcsError::message(
            "conclusion is required when status is 'completed'",
        ));
    }
    if !php_empty_str(&conclusion) {
        status = "completed".into();
        if php_empty_str(&completed_at) {
            completed_at = gmdate_iso();
        }
    }
    Ok((status, conclusion, completed_at))
}

fn attach_check_output(
    body: &mut Value,
    title: &str,
    summary: &str,
    text: &str,
    annotations: &[Value],
    images: &[Value],
) {
    if php_empty_str(title) || php_empty_str(summary) {
        return;
    }
    let mut output = filter_empty_object(vec![
        ("title", json!(title)),
        ("summary", json!(summary)),
        ("text", json!(text)),
    ]);
    if !annotations.is_empty() {
        output.insert("annotations".into(), json!(annotations));
    }
    if !images.is_empty() {
        output.insert("images".into(), json!(images));
    }
    body["output"] = Value::Object(output);
}

fn stringify_check_run_id(mut check_run: Value) -> Value {
    let id = strval(field_or_null(&check_run, "id"));
    check_run["id"] = json!(id);
    check_run
}

/// Parsed webhook events (PHP `GitHub::getEvents`).
pub fn github_get_events(event: &str, payload: &str) -> Result<Vec<Value>, VcsError> {
    let payload: Value = serde_json::from_str(payload)
        .ok()
        .filter(Value::is_object)
        .ok_or_else(|| VcsError::message("Invalid payload."))?;
    let installation = obj_field(&payload, "installation");
    let installation_id = strval(field_or_null(installation, "id"));
    match event {
        "push" => Ok(vec![parse_github_push(&payload, &installation_id)]),
        "pull_request" => Ok(vec![parse_github_pull_request(&payload, &installation_id)]),
        "installation" | "installation_repositories" => {
            let account = obj_field(installation, "account");
            Ok(vec![json!({
                "action": str_field(&payload, "action"),
                "installationId": installation_id,
                "userName": str_field(account, "login"),
            })])
        }
        _ => Ok(Vec::new()),
    }
}

fn parse_github_push(payload: &Value, installation_id: &str) -> Value {
    let repository = obj_field(payload, "repository");
    let owner_obj = obj_field(repository, "owner");
    let sender = obj_field(payload, "sender");
    let head = obj_field(payload, "head_commit");
    let head_author = obj_field(head, "author");
    let branch = str_field(payload, "ref").replacen("refs/heads/", "", 1);
    let repository_url = str_field(repository, "html_url");
    let branch_url = if !repository_url.is_empty() && !branch.is_empty() {
        format!("{repository_url}/tree/{branch}")
    } else {
        String::new()
    };
    let mut affected = Map::new();
    if let Some(commits) = payload.get("commits").and_then(Value::as_array) {
        for commit in commits {
            for key in ["added", "removed", "modified"] {
                if let Some(files) = commit.get(key).and_then(Value::as_array) {
                    for file in files {
                        affected.insert(strval(file), json!(true));
                    }
                }
            }
        }
    }
    json!({
        "branchCreated": payload.get("created").and_then(Value::as_bool).unwrap_or(false),
        "branchDeleted": payload.get("deleted").and_then(Value::as_bool).unwrap_or(false),
        "branch": branch,
        "branchUrl": branch_url,
        "repositoryId": strval(field_or_null(repository, "id")),
        "repositoryName": str_field(repository, "name"),
        "repositoryUrl": repository_url,
        "installationId": installation_id,
        "commitHash": str_field(payload, "after"),
        "owner": str_field(owner_obj, "name"),
        "authorUrl": str_field(sender, "html_url"),
        "authorAvatarUrl": str_field(sender, "avatar_url"),
        "headCommitAuthorName": str_field(head_author, "name"),
        "headCommitAuthorEmail": str_field(head_author, "email"),
        "headCommitMessage": str_field(head, "message"),
        "headCommitUrl": str_field(head, "url"),
        "external": false,
        "pullRequestNumber": "",
        "action": "",
        "affectedFiles": affected.keys().cloned().collect::<Vec<_>>(),
    })
}

fn parse_github_pull_request(payload: &Value, installation_id: &str) -> Value {
    let repository = obj_field(payload, "repository");
    let owner_obj = obj_field(repository, "owner");
    let sender = obj_field(payload, "sender");
    let pull_request = obj_field(payload, "pull_request");
    let head = obj_field(pull_request, "head");
    let head_user = obj_field(head, "user");
    let user = obj_field(pull_request, "user");
    let base = obj_field(pull_request, "base");
    let base_user = obj_field(base, "user");
    let branch = str_field(head, "ref");
    let repository_url = str_field(repository, "html_url");
    let branch_url = if !repository_url.is_empty() && !branch.is_empty() {
        format!("{repository_url}/tree/{branch}")
    } else {
        String::new()
    };
    let commit_hash = str_field(head, "sha");
    let head_commit_url = if repository_url.is_empty() {
        String::new()
    } else {
        format!("{repository_url}/commits/{commit_hash}")
    };
    let external = str_field(head_user, "login") != str_field(base_user, "login");
    json!({
        "branch": branch,
        "branchUrl": branch_url,
        "repositoryId": strval(field_or_null(repository, "id")),
        "repositoryName": str_field(repository, "name"),
        "repositoryUrl": repository_url,
        "installationId": installation_id,
        "commitHash": commit_hash,
        "owner": str_field(owner_obj, "login"),
        "authorUrl": str_field(sender, "html_url"),
        "authorAvatarUrl": str_field(user, "avatar_url"),
        "headCommitUrl": head_commit_url,
        "external": external,
        "pullRequestNumber": field_or_null(payload, "number").clone(),
        "action": str_field(payload, "action"),
    })
}
