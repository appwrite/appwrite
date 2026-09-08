//! Gitea adapter (PHP `Utopia\VCS\Adapter\Git\Gitea`). Forgejo wraps this type.

use std::collections::HashMap;

use serde_json::{json, Value};

use crate::adapter::{
    WebhookId, CLONE_TYPE_BRANCH, CLONE_TYPE_COMMIT, CLONE_TYPE_TAG, TYPE_GIT,
    WEBHOOK_SCOPE_REPOSITORY,
};
use crate::cache::CacheStore;
use crate::error::{FileNotFound, RepositoryNotFound, VcsError};
use crate::http::{
    encode_ref_keep_slash, CallResponse, HttpClient, METHOD_DELETE, METHOD_GET, METHOD_PATCH,
    METHOD_POST,
};
use crate::php::{
    array_column_str, array_keys, escape_shell_arg, field_or_null, match_glob,
    normalize_repository_path, obj_field, php_empty_str, php_empty_value, php_rawurlencode,
    php_urlencode, str_field, strval, validate_hmac_sha256,
};

pub const CONTENTS_FILE: &str = "file";
pub const CONTENTS_DIRECTORY: &str = "dir";

#[derive(Debug, Clone, Copy)]
pub(crate) struct Identity {
    pub name: &'static str,
    pub hook_type: &'static str,
    pub event_header: &'static str,
    pub signature_header: &'static str,
    pub default_url: &'static str,
}

impl Identity {
    pub const GITEA: Self = Self {
        name: "gitea",
        hook_type: "gitea",
        event_header: "x-gitea-event",
        signature_header: "x-gitea-signature",
        default_url: "http://gitea:3000",
    };
    pub const FORGEJO: Self = Self {
        name: "forgejo",
        hook_type: "forgejo",
        event_header: "x-forgejo-event",
        signature_header: "x-forgejo-signature",
        default_url: "http://forgejo:3000",
    };
    pub const GOGS: Self = Self {
        name: "gogs",
        hook_type: "gogs",
        event_header: "x-gogs-event",
        signature_header: "x-gogs-signature",
        default_url: "http://gogs:3000",
    };
}

macro_rules! unsupported_checks_and_namespaces {
    () => {
        #[allow(clippy::too_many_arguments)]
        pub fn create_check_run(
            &self,
            _owner: &str,
            _repository_name: &str,
            _head_sha: &str,
            _name: &str,
            _status: &str,
            _conclusion: &str,
            _title: &str,
            _summary: &str,
            _text: &str,
            _annotations: &[Value],
            _images: &[Value],
            _actions: &[Value],
            _details_url: &str,
            _external_id: &str,
            _started_at: &str,
            _completed_at: &str,
        ) -> Result<Value, VcsError> {
            Err(VcsError::message(format!(
                "createCheckRun() is not supported by {}",
                self.get_name()
            )))
        }

        pub fn get_check_run(
            &self,
            _owner: &str,
            _repository_name: &str,
            _check_run_id: &str,
        ) -> Result<Value, VcsError> {
            Err(VcsError::message(format!(
                "getCheckRun() is not supported by {}",
                self.get_name()
            )))
        }

        #[allow(clippy::too_many_arguments)]
        pub fn update_check_run(
            &self,
            _owner: &str,
            _repository_name: &str,
            _check_run_id: &str,
            _name: &str,
            _status: &str,
            _conclusion: &str,
            _title: &str,
            _summary: &str,
            _text: &str,
            _annotations: &[Value],
            _images: &[Value],
            _actions: &[Value],
            _details_url: &str,
            _external_id: &str,
            _started_at: &str,
            _completed_at: &str,
        ) -> Result<Value, VcsError> {
            Err(VcsError::message(format!(
                "updateCheckRun() is not supported by {}",
                self.get_name()
            )))
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
    };
}
pub(crate) use unsupported_checks_and_namespaces;

#[derive(Debug)]
pub struct Gitea {
    pub(crate) http: HttpClient,
    #[allow(dead_code)]
    cache: Box<dyn CacheStore>,
    pub(crate) access_token: String,
    refresh_token: Option<String>,
    pub(crate) gitea_url: String,
    identity: Identity,
}

impl Gitea {
    pub fn new(cache: impl CacheStore + 'static) -> Self {
        Self::new_with(cache, Identity::GITEA)
    }

    pub(crate) fn new_with(cache: impl CacheStore + 'static, identity: Identity) -> Self {
        Self {
            http: HttpClient::new(format!("{}/api/v1", identity.default_url)),
            cache: Box::new(cache),
            access_token: String::new(),
            refresh_token: None,
            gitea_url: identity.default_url.to_string(),
            identity,
        }
    }

    pub fn set_endpoint(&mut self, endpoint: impl Into<String>) {
        self.gitea_url = endpoint.into().trim_end_matches('/').to_string();
        self.http.endpoint = format!("{}/api/v1", self.gitea_url);
    }

    #[must_use]
    pub fn get_name(&self) -> &'static str {
        self.identity.name
    }

    #[must_use]
    pub fn get_type(&self) -> &'static str {
        TYPE_GIT
    }

    pub fn initialize_variables(
        &mut self,
        _installation_id: &str,
        _private_key: &str,
        _app_id: Option<&str>,
        access_token: Option<&str>,
        refresh_token: Option<&str>,
    ) -> Result<(), VcsError> {
        if let Some(token) = access_token.filter(|t| !php_empty_str(t)) {
            self.access_token = token.to_string();
            self.refresh_token = refresh_token.map(str::to_string);
            return Ok(());
        }
        Err(VcsError::message(
            "accessToken is required for this adapter.",
        ))
    }

    pub(crate) fn auth(&self) -> HashMap<String, String> {
        HashMap::from([(
            "Authorization".into(),
            format!("token {}", self.access_token),
        )])
    }

    pub(crate) fn call(
        &self,
        method: &str,
        path: &str,
        params: &Value,
    ) -> Result<CallResponse, VcsError> {
        self.http
            .call(method, path, self.auth(), params, true, true)
    }

    fn call_raw(&self, method: &str, path: &str) -> Result<CallResponse, VcsError> {
        self.http
            .call(method, path, self.auth(), &json!({}), false, true)
    }

    fn with_pushed_at(&self, mut result: Value) -> Value {
        if result.is_object() && php_empty_str(&str_field(&result, "pushed_at")) {
            result["pushed_at"] = json!(str_field(&result, "updated_at"));
        }
        result
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
            &json!({"name": repository_name, "private": private}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Creating repository {repository_name} failed with status code {status}"),
                status,
            ));
        }
        Ok(self.with_pushed_at(response.body_object()))
    }

    pub fn create_organization(&self, org_name: &str) -> Result<String, VcsError> {
        let response = self.call(
            METHOD_POST,
            "/orgs",
            &json!({"username": org_name, "visibility": "public"}),
        )?;
        Ok(str_field(&response.body, "name"))
    }

    pub fn has_access_to_all_repositories(&self) -> Result<bool, VcsError> {
        Ok(true)
    }

    pub fn search_repositories(
        &self,
        owner: &str,
        page: i64,
        per_page: i64,
        search: &str,
    ) -> Result<Value, VcsError> {
        let mut filtered = Vec::new();
        let mut current_page = 1_i64;
        let needed = page * per_page;
        let max_collect = needed + per_page;
        while current_page <= 50 {
            let mut query = vec![("page", json!(current_page)), ("limit", json!(100))];
            if !php_empty_str(search) {
                query.push(("q", json!(search)));
            }
            let mut map = serde_json::Map::new();
            for (k, v) in query {
                map.insert(k.into(), v);
            }
            let qs = crate::php::http_build_query(&Value::Object(map));
            let response = self.call(METHOD_GET, &format!("/repos/search?{qs}"), &json!({}))?;
            let status = response.status_code();
            if status >= 400 {
                return Err(VcsError::with_status(
                    format!("Repository search failed with status code {status}"),
                    status,
                ));
            }
            if !response.body.is_object() {
                return Err(VcsError::message(format!(
                    "Unexpected response body: {}",
                    response.body
                )));
            }
            let Some(repos) = response.body.get("data").and_then(Value::as_array) else {
                return Err(VcsError::message(format!(
                    "Repositories list missing in response: {}",
                    response.body
                )));
            };
            if repos.is_empty() {
                break;
            }
            let count = repos.len();
            for repo in repos {
                if str_field(obj_field(repo, "owner"), "login") == owner {
                    filtered.push(repo.clone());
                    if (filtered.len() as i64) >= max_collect {
                        break;
                    }
                }
            }
            if (filtered.len() as i64) >= max_collect || count < 100 {
                break;
            }
            current_page += 1;
        }
        let total = filtered.len();
        let offset = ((page - 1) * per_page).max(0) as usize;
        let mut paged: Vec<Value> = filtered
            .into_iter()
            .skip(offset)
            .take(per_page.max(0) as usize)
            .collect();
        for repo in &mut paged {
            *repo = self.with_pushed_at(repo.clone());
        }
        Ok(json!({"items": paged, "total": total}))
    }

    pub fn get_installation_repository(&self, _repository_name: &str) -> Result<Value, VcsError> {
        Err(VcsError::message(
            "getInstallationRepository is not applicable for this adapter - use getRepository() with owner and repo name instead",
        ))
    }

    pub fn get_repository(&self, owner: &str, repository_name: &str) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}"),
            &json!({}),
        )?;
        if response.status_code() >= 400 {
            return Err(RepositoryNotFound::new("Repository not found").into());
        }
        Ok(self.with_pushed_at(response.body_object()))
    }

    pub fn get_repository_presigned_url(
        &self,
        owner: &str,
        repository_name: &str,
        ref_name: &str,
        format: &str,
    ) -> Result<String, VcsError> {
        let extension = match format {
            "tarball" => "tar.gz",
            "zipball" => "zip",
            _ => {
                return Err(VcsError::message(format!(
                    "Invalid archive format: {format}. Use 'tarball' or 'zipball'."
                )))
            }
        };
        let mut ref_name = ref_name.to_string();
        if php_empty_str(&ref_name) {
            ref_name = str_field(
                &self.get_repository(owner, repository_name)?,
                "default_branch",
            );
            if php_empty_str(&ref_name) {
                return Err(VcsError::message(
                    "Unable to resolve default branch for archive download.",
                ));
            }
        }
        Ok(format!(
            "{}/repos/{owner}/{repository_name}/archive/{}.{extension}?token={}",
            self.http.endpoint,
            encode_ref_keep_slash(&ref_name),
            php_urlencode(&self.access_token)
        ))
    }

    #[must_use]
    pub fn get_repository_presigned_url_headers(&self) -> HashMap<String, String> {
        HashMap::new()
    }

    pub fn get_repository_name(&self, repository_id: &str) -> Result<String, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repositories/{repository_id}"),
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
            &format!(
                "/repos/{owner}/{repository_name}/git/trees/{}{suffix}",
                php_urlencode(branch)
            ),
            &json!({}),
        )?;
        if response.status_code() == 404 {
            return Ok(Vec::new());
        }
        Ok(array_column_str(
            response
                .body
                .get("tree")
                .and_then(Value::as_array)
                .map_or(&[][..], |v| v),
            "path",
        ))
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
            "content": base64::Engine::encode(&base64::engine::general_purpose::STANDARD, content.as_bytes()),
            "message": message,
        });
        if !php_empty_str(branch) {
            payload["branch"] = json!(branch);
        }
        let response = self.call(
            METHOD_POST,
            &format!("/repos/{owner}/{repository_name}/contents/{filepath}"),
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
        let response = self.call(
            METHOD_POST,
            &format!("/repos/{owner}/{repository_name}/branches"),
            &json!({
                "new_branch_name": new_branch_name,
                "old_branch_name": old_branch_name,
            }),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to create branch {new_branch_name}: HTTP {status}"),
                status,
            ));
        }
        Ok(response.body_object())
    }

    pub fn list_repository_languages(
        &self,
        owner: &str,
        repository_name: &str,
    ) -> Result<Vec<String>, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/languages"),
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
        let path = normalize_repository_path(path);
        let mut url = format!("/repos/{owner}/{repository_name}/contents/{path}");
        if !php_empty_str(ref_name) {
            url.push_str("?ref=");
            url.push_str(&php_urlencode(ref_name));
        }
        let response = self.call(METHOD_GET, &url, &json!({}))?;
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
            url.push_str(&php_urlencode(ref_name));
        }
        let response = self.call(METHOD_GET, &url, &json!({}))?;
        if response.status_code() == 404 {
            return Ok(Vec::new());
        }
        let items = match &response.body {
            Value::Array(items) if !items.is_empty() => items.clone(),
            Value::Object(map) if !map.is_empty() => vec![response.body.clone()],
            _ => Vec::new(),
        };
        Ok(items
            .iter()
            .map(|item| {
                let kind = str_field(item, "type");
                json!({
                    "name": str_field(item, "name"),
                    "size": item.get("size").cloned().unwrap_or(json!(0)),
                    "type": if kind == "file" { CONTENTS_FILE } else { CONTENTS_DIRECTORY },
                })
            })
            .collect())
    }

    pub fn delete_repository(&self, owner: &str, repository_name: &str) -> Result<bool, VcsError> {
        let response = self.call(
            METHOD_DELETE,
            &format!("/repos/{owner}/{repository_name}"),
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

    pub fn create_pull_request(
        &self,
        owner: &str,
        repository_name: &str,
        title: &str,
        head: &str,
        base: &str,
        body: &str,
    ) -> Result<Value, VcsError> {
        let mut payload = json!({"title": title, "head": head, "base": base});
        if !php_empty_str(body) {
            payload["body"] = json!(body);
        }
        let response = self.call(
            METHOD_POST,
            &format!("/repos/{owner}/{repository_name}/pulls"),
            &payload,
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to create pull request: HTTP {status}"),
                status,
            ));
        }
        Ok(response.body_object())
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
            &json!({
                "type": self.identity.hook_type,
                "active": true,
                "events": events,
                "config": {"url": url, "content_type": "json", "secret": secret},
            }),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to create webhook: HTTP {status}"),
                status,
            ));
        }
        Ok(WebhookId::Number(
            response.body.get("id").and_then(Value::as_i64).unwrap_or(0),
        ))
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
            &json!({"body": comment}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to create comment: HTTP {status}"),
                status,
            ));
        }
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
            &json!({"body": comment}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to update comment: HTTP {status}"),
                status,
            ));
        }
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
            &format!("/users/{}", php_rawurlencode(username)),
            &json!({}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get user: HTTP {status}"),
                status,
            ));
        }
        Ok(response.body_object())
    }

    pub(crate) fn get_authenticated_user_login(&self) -> Result<String, VcsError> {
        let response = self.call(METHOD_GET, "/user", &json!({}))?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get authenticated user: HTTP {status}"),
                status,
            ));
        }
        let login = str_field(&response.body, "login");
        if php_empty_str(&login) {
            return Err(VcsError::message(
                "Authenticated user login missing or empty in response",
            ));
        }
        Ok(login)
    }

    pub fn get_owner_name(
        &self,
        _installation_id: &str,
        repository_id: Option<i64>,
    ) -> Result<String, VcsError> {
        if repository_id.is_none()
            || repository_id == Some(0)
            || repository_id.is_some_and(|id| id < 0)
        {
            return self.get_authenticated_user_login();
        }
        let id = repository_id.unwrap_or(0);
        let response = self.call(METHOD_GET, &format!("/repositories/{id}"), &json!({}))?;
        let status = response.status_code();
        if status == 404 {
            return Err(RepositoryNotFound::new("Repository not found").into());
        }
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get repository: HTTP {status}"),
                status,
            ));
        }
        let login = str_field(obj_field(&response.body, "owner"), "login");
        if php_empty_str(&login) {
            return Err(VcsError::message(
                "Owner login missing or empty in response",
            ));
        }
        Ok(login)
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
        let limit = 30_i64;
        for current_page in 1..=100 {
            let response = self.call(
                METHOD_GET,
                &format!(
                    "/repos/{owner}/{repository_name}/pulls/{pull_request_number}/files?page={current_page}&limit={limit}"
                ),
                &json!({}),
            )?;
            let status = response.status_code();
            if status >= 400 {
                return Err(VcsError::with_status(
                    format!("Failed to get pull request files: HTTP {status}"),
                    status,
                ));
            }
            let files = response.body.as_array().cloned().unwrap_or_default();
            let count = files.len();
            all_files.extend(files);
            if (count as i64) < limit {
                break;
            }
        }
        Ok(all_files)
    }

    pub fn get_pull_request_from_branch(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
    ) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!(
                "/repos/{owner}/{repository_name}/pulls?state=open&head={}",
                php_urlencode(branch)
            ),
            &json!({}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to list pull requests: HTTP {status}"),
                status,
            ));
        }
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
        self.list_named(
            "/branches",
            owner,
            repository_name,
            "Failed to list branches",
        )
    }

    pub fn list_tags(
        &self,
        owner: &str,
        repository_name: &str,
        search: &str,
    ) -> Result<Vec<String>, VcsError> {
        Ok(match_glob(
            self.list_named("/tags", owner, repository_name, "Failed to list tags")?,
            search,
        ))
    }

    fn list_named(
        &self,
        suffix: &str,
        owner: &str,
        repository_name: &str,
        fail: &str,
    ) -> Result<Vec<String>, VcsError> {
        let mut names = Vec::new();
        let per_page = 50_i64;
        for current_page in 1..=100 {
            let response = self.call_raw(
                METHOD_GET,
                &format!(
                    "/repos/{owner}/{repository_name}{suffix}?page={current_page}&limit={per_page}"
                ),
            )?;
            let status = response.status_code();
            if status == 404 {
                return Ok(Vec::new());
            }
            if status >= 400 {
                if current_page == 1 {
                    return Err(VcsError::with_status(
                        format!("{fail}: HTTP {status}"),
                        status,
                    ));
                }
                break;
            }
            let text = match &response.body {
                Value::String(s) => s.clone(),
                other => other.to_string(),
            };
            let Ok(parsed) = serde_json::from_str::<Value>(&text) else {
                break;
            };
            let Some(items) = parsed.as_array() else {
                break;
            };
            let mut page_count = 0;
            for item in items {
                if item.get("name").is_some() {
                    names.push(str_field(item, "name"));
                    page_count += 1;
                }
            }
            if page_count < per_page {
                break;
            }
        }
        Ok(names)
    }

    pub fn get_commit(
        &self,
        owner: &str,
        repository_name: &str,
        commit_hash: &str,
    ) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/git/commits/{commit_hash}"),
            &json!({}),
        )?;
        if response.status_code() >= 400 {
            return Err(VcsError::message("Commit not found or inaccessible"));
        }
        Ok(parse_gitea_commit(&response.body))
    }

    pub fn get_latest_commit(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
    ) -> Result<Value, VcsError> {
        let qs = crate::php::http_build_query(&json!({"sha": branch, "limit": 1}));
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/commits?{qs}"),
            &json!({}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Latest commit response failed with status code {status}"),
                status,
            ));
        }
        let first = response
            .body
            .as_array()
            .and_then(|items| items.first())
            .filter(|item| !php_empty_value(item))
            .ok_or_else(|| {
                VcsError::message("Latest commit response is missing required information.")
            })?;
        Ok(parse_gitea_commit(first))
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
        let mut body = json!({"state": state});
        if !php_empty_str(description) {
            body["description"] = json!(description);
        }
        if !php_empty_str(target_url) {
            body["target_url"] = json!(target_url);
        }
        if !php_empty_str(context) {
            body["context"] = json!(context);
        }
        let response = self.call(
            METHOD_POST,
            &format!("/repos/{owner}/{repository_name}/statuses/{commit_hash}"),
            &body,
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
        let mut clone_url = format!("{}/{owner}/{repository_name}", self.gitea_url);
        if !php_empty_str(&self.access_token) {
            clone_url = format!(
                "{}/{owner}/{repository_name}",
                self.gitea_url
                    .replace("://", &format!("://{owner}:{}@", self.access_token))
            );
        }
        let clone_url = escape_shell_arg(&clone_url);
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
                let _tag = escape_shell_arg(version);
                commands.push(format!(
                    "git fetch --depth=1 origin refs/tags/{version} && git checkout FETCH_HEAD"
                ));
            }
            other => {
                return Err(VcsError::message(format!(
                    "Unsupported clone type: {other}"
                )))
            }
        }
        Ok(commands.join(" && "))
    }

    pub fn get_events(&self, event: &str, payload: &str) -> Result<Vec<Value>, VcsError> {
        gitea_get_events(event, payload)
    }

    #[must_use]
    pub fn validate_webhook_event(
        &self,
        payload: &str,
        signature: &str,
        signature_key: &str,
    ) -> bool {
        validate_hmac_sha256(payload, signature, signature_key)
    }

    pub fn create_tag(
        &self,
        owner: &str,
        repository_name: &str,
        tag_name: &str,
        target: &str,
        message: &str,
    ) -> Result<Value, VcsError> {
        let mut payload = json!({"tag_name": tag_name, "target": target});
        if !php_empty_str(message) {
            payload["message"] = json!(message);
        }
        let response = self.call(
            METHOD_POST,
            &format!("/repos/{owner}/{repository_name}/tags"),
            &payload,
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to create tag {tag_name}: HTTP {status}"),
                status,
            ));
        }
        Ok(response.body_object())
    }

    pub fn get_commit_statuses(
        &self,
        owner: &str,
        repository_name: &str,
        commit_hash: &str,
    ) -> Result<Vec<Value>, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/commits/{commit_hash}/statuses"),
            &json!({}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get commit statuses: HTTP {status}"),
                status,
            ));
        }
        let Some(items) = response.body.as_array() else {
            return Ok(Vec::new());
        };
        Ok(items
            .iter()
            .map(|s| {
                json!({
                    "state": str_field(s, "status"),
                    "description": str_field(s, "description"),
                    "target_url": str_field(s, "target_url"),
                    "context": str_field(s, "context"),
                })
            })
            .collect())
    }

    unsupported_checks_and_namespaces!();

    #[must_use]
    pub fn get_event_header_name(&self) -> &'static str {
        self.identity.event_header
    }

    #[must_use]
    pub fn get_signature_header_name(&self) -> &'static str {
        self.identity.signature_header
    }

    #[must_use]
    pub fn get_supported_webhook_scopes(&self) -> &'static [&'static str] {
        &[WEBHOOK_SCOPE_REPOSITORY]
    }

    #[must_use]
    pub fn get_repository_url(&self, owner: &str, repository_name: &str) -> String {
        format!("{}/{owner}/{repository_name}", self.gitea_url)
    }

    #[must_use]
    pub fn get_branch_url(&self, owner: &str, repository_name: &str, branch: &str) -> String {
        format!(
            "{}/src/branch/{branch}",
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
            "{}/src/{reference}",
            self.get_repository_url(owner, repository_name)
        )
    }
}

fn parse_gitea_commit(body: &Value) -> Value {
    let commit = obj_field(body, "commit");
    let commit_author = obj_field(commit, "author");
    let author = obj_field(body, "author");
    json!({
        "commitAuthor": nonempty(&str_field(commit_author, "name"), "Unknown"),
        "commitMessage": nonempty(&str_field(commit, "message"), "No message"),
        "commitAuthorAvatar": str_field(author, "avatar_url"),
        "commitAuthorUrl": str_field(author, "html_url"),
        "commitHash": str_field(body, "sha"),
        "commitUrl": str_field(body, "html_url"),
    })
}

fn nonempty(value: &str, fallback: &str) -> String {
    if value.is_empty() {
        fallback.to_string()
    } else {
        value.to_string()
    }
}

/// Parsed webhook events (PHP `Gitea::getEvents`).
pub fn gitea_get_events(event: &str, payload: &str) -> Result<Vec<Value>, VcsError> {
    let payload: Value = serde_json::from_str(payload)
        .ok()
        .filter(Value::is_object)
        .ok_or_else(|| VcsError::message("Invalid payload."))?;
    match event {
        "push" => Ok(vec![parse_gitea_push(&payload)]),
        "pull_request" => Ok(vec![parse_gitea_pr(&payload)]),
        _ => Ok(Vec::new()),
    }
}

fn parse_gitea_push(payload: &Value) -> Value {
    let repository = obj_field(payload, "repository");
    let owner_obj = obj_field(repository, "owner");
    let sender = obj_field(payload, "sender");
    let head = obj_field(payload, "head_commit");
    let head_author = obj_field(head, "author");
    let branch = str_field(payload, "ref").replacen("refs/heads/", "", 1);
    let repository_url = str_field(repository, "html_url");
    let branch_url = if !repository_url.is_empty() && !branch.is_empty() {
        format!("{repository_url}/src/branch/{branch}")
    } else {
        String::new()
    };
    let mut affected = serde_json::Map::new();
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
        "installationId": "",
        "commitHash": str_field(payload, "after"),
        "owner": str_field(owner_obj, "login"),
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

fn parse_gitea_pr(payload: &Value) -> Value {
    let repository = obj_field(payload, "repository");
    let owner_obj = obj_field(repository, "owner");
    let sender = obj_field(payload, "sender");
    let pull_request = obj_field(payload, "pull_request");
    let head = obj_field(pull_request, "head");
    let head_repo = obj_field(head, "repo");
    let user = obj_field(pull_request, "user");
    let branch = str_field(head, "ref");
    let repository_url = str_field(repository, "html_url");
    let branch_url = if !repository_url.is_empty() && !branch.is_empty() {
        format!("{repository_url}/src/branch/{branch}")
    } else {
        String::new()
    };
    let commit_hash = str_field(head, "sha");
    let head_commit_url = if repository_url.is_empty() {
        String::new()
    } else {
        format!("{repository_url}/commit/{commit_hash}")
    };
    let head_full = str_field(head_repo, "full_name");
    let base_full = str_field(repository, "full_name");
    let external = !head_full.is_empty() && !base_full.is_empty() && head_full != base_full;
    json!({
        "branch": branch,
        "branchUrl": branch_url,
        "repositoryId": strval(field_or_null(repository, "id")),
        "repositoryName": str_field(repository, "name"),
        "repositoryUrl": repository_url,
        "installationId": "",
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
