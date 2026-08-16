//! GitLab adapter (PHP `Utopia\VCS\Adapter\Git\GitLab`).

use std::collections::HashMap;
use std::thread;
use std::time::Duration;

use serde_json::{json, Value};

use crate::adapter::{
    WebhookId, CLONE_TYPE_BRANCH, CLONE_TYPE_COMMIT, CLONE_TYPE_TAG, TYPE_GIT,
    WEBHOOK_SCOPE_REPOSITORY,
};
use crate::cache::CacheStore;
use crate::error::{FileNotFound, RepositoryNotFound, VcsError};
use crate::http::{CallResponse, HttpClient, METHOD_DELETE, METHOD_GET, METHOD_POST, METHOD_PUT};
use crate::php::{
    array_column_str, array_keys, escape_shell_arg, field_or_null, hash_equals, match_glob,
    normalize_repository_path, obj_field, php_empty_str, php_rawurlencode, php_urlencode,
    str_field, strval,
};

/// Directory listing type.
pub const CONTENTS_DIRECTORY: &str = "dir";
/// File listing type.
pub const CONTENTS_FILE: &str = "file";

const MERGE_REQUEST_ACTION_MAP: &[(&str, &str)] = &[
    ("open", "opened"),
    ("reopen", "reopened"),
    ("update", "synchronize"),
    ("close", "closed"),
    ("merge", "closed"),
];

#[derive(Debug)]
pub struct GitLab {
    http: HttpClient,
    gitlab_url: String,
    #[allow(dead_code)]
    cache: Box<dyn CacheStore>,
    access_token: String,
}

impl GitLab {
    pub fn new(cache: impl CacheStore + 'static) -> Self {
        Self {
            http: HttpClient::new("http://gitlab:80/api/v4"),
            gitlab_url: "http://gitlab:80".into(),
            cache: Box::new(cache),
            access_token: String::new(),
        }
    }

    pub fn set_endpoint(&mut self, endpoint: impl Into<String>) {
        self.gitlab_url = endpoint.into().trim_end_matches('/').to_string();
        self.http.endpoint = format!("{}/api/v4", self.gitlab_url);
    }

    #[must_use]
    pub fn get_name(&self) -> &'static str {
        "gitlab"
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
        _refresh_token: Option<&str>,
    ) -> Result<(), VcsError> {
        if let Some(token) = access_token.filter(|t| !php_empty_str(t)) {
            self.access_token = token.to_string();
            return Ok(());
        }
        Err(VcsError::message(
            "accessToken is required for this adapter.",
        ))
    }

    fn auth(&self) -> HashMap<String, String> {
        HashMap::from([(
            "Authorization".into(),
            format!("Bearer {}", self.access_token),
        )])
    }

    fn call(&self, method: &str, path: &str, params: &Value) -> Result<CallResponse, VcsError> {
        self.http
            .call(method, path, self.auth(), params, true, true)
    }

    fn owner_path<'a>(&self, owner: &'a str) -> &'a str {
        owner.split_once(':').map_or(owner, |(_, path)| path)
    }

    fn namespace_id<'a>(&self, owner: &'a str) -> &'a str {
        owner.split_once(':').map_or(owner, |(id, _)| id)
    }

    fn project_path(&self, owner: &str, repository_name: &str) -> String {
        php_urlencode(&format!("{}/{}", self.owner_path(owner), repository_name))
    }

    pub fn create_organization(&self, org_name: &str) -> Result<String, VcsError> {
        let response = self.call(
            METHOD_POST,
            "/groups",
            &json!({
                "name": org_name,
                "path": org_name,
                "visibility": "public",
            }),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Creating organization {org_name} failed with status code {status}"),
                status,
            ));
        }
        Ok(format!(
            "{}:{}",
            str_field(&response.body, "id"),
            str_field(&response.body, "path")
        ))
    }

    pub fn create_repository(
        &self,
        owner: &str,
        repository_name: &str,
        private: bool,
    ) -> Result<Value, VcsError> {
        let namespace_id: i64 = self.namespace_id(owner).parse().unwrap_or(0);
        let response = self.call(
            METHOD_POST,
            "/projects",
            &json!({
                "name": repository_name,
                "path": repository_name,
                "namespace_id": namespace_id,
                "visibility": if private { "private" } else { "public" },
            }),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Creating repository {repository_name} failed with status code {status}"),
                status,
            ));
        }
        let mut result = response.body_object();
        result["pushed_at"] = json!(str_field(&result, "last_activity_at"));
        Ok(result)
    }

    pub fn delete_repository(&self, owner: &str, repository_name: &str) -> Result<bool, VcsError> {
        let project = self.project_path(owner, repository_name);
        let response = self.call(METHOD_DELETE, &format!("/projects/{project}"), &json!({}))?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Deleting repository {repository_name} failed with status code {status}"),
                status,
            ));
        }
        Ok(true)
    }

    pub fn get_repository(&self, owner: &str, repository_name: &str) -> Result<Value, VcsError> {
        let project = self.project_path(owner, repository_name);
        let response = self.call(METHOD_GET, &format!("/projects/{project}"), &json!({}))?;
        if response.status_code() >= 400 {
            return Err(RepositoryNotFound::new("Repository not found").into());
        }
        let mut result = response.body_object();
        result["pushed_at"] = json!(str_field(&result, "last_activity_at"));
        Ok(result)
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
        let project = self.project_path(owner, repository_name);
        let mut url = format!(
            "{}/projects/{project}/repository/archive.{extension}?access_token={}",
            self.http.endpoint,
            php_urlencode(&self.access_token)
        );
        if !php_empty_str(ref_name) {
            url.push_str("&sha=");
            url.push_str(&php_urlencode(ref_name));
        }
        Ok(url)
    }

    #[must_use]
    pub fn get_repository_presigned_url_headers(&self) -> HashMap<String, String> {
        HashMap::new()
    }

    pub fn has_access_to_all_repositories(&self) -> Result<bool, VcsError> {
        Ok(true)
    }

    pub fn get_installation_repository(&self, _repository_name: &str) -> Result<Value, VcsError> {
        Err(VcsError::message(
            "getInstallationRepository is not applicable for this adapter",
        ))
    }

    pub fn list_namespaces(
        &self,
        page: i64,
        per_page: i64,
        search: &str,
    ) -> Result<Value, VcsError> {
        let mut url = format!("/namespaces?page={page}&per_page={per_page}");
        if !php_empty_str(search) {
            url.push_str("&search=");
            url.push_str(&php_urlencode(search));
        }
        let response = self.call(METHOD_GET, &url, &json!({}))?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to list namespaces: HTTP {status}"),
                status,
            ));
        }
        let items = response.body.as_array().cloned().unwrap_or_default();
        let namespaces: Vec<Value> = items
            .iter()
            .map(|namespace| {
                json!({
                    "id": strval(field_or_null(namespace, "id")),
                    "name": if str_field(namespace, "name").is_empty() {
                        str_field(namespace, "path")
                    } else {
                        str_field(namespace, "name")
                    },
                    "path": if str_field(namespace, "full_path").is_empty() {
                        str_field(namespace, "path")
                    } else {
                        str_field(namespace, "full_path")
                    },
                    "kind": if str_field(namespace, "kind").is_empty() {
                        "group".into()
                    } else {
                        str_field(namespace, "kind")
                    },
                    "avatarUrl": str_field(namespace, "avatar_url"),
                })
            })
            .collect();
        let total = response
            .headers
            .get("x-total")
            .and_then(Value::as_str)
            .and_then(|s| s.parse::<i64>().ok())
            .unwrap_or(namespaces.len() as i64);
        Ok(json!({"items": namespaces, "total": total}))
    }

    pub fn search_repositories(
        &self,
        owner: &str,
        page: i64,
        per_page: i64,
        search: &str,
    ) -> Result<Value, VcsError> {
        let owner_path = self.owner_path(owner).to_string();
        let mut url = format!("/groups/{owner_path}/projects?page={page}&per_page={per_page}");
        if !php_empty_str(search) {
            url.push_str("&search=");
            url.push_str(&php_urlencode(search));
        }
        let mut response = self.call(METHOD_GET, &url, &json!({}))?;
        let mut status = response.status_code();
        let mut filter_by_namespace = false;
        if status == 404 {
            filter_by_namespace = true;
            url = format!("/projects?membership=true&page={page}&per_page={per_page}");
            if !php_empty_str(search) {
                url.push_str("&search=");
                url.push_str(&php_urlencode(search));
            }
            response = self.call(METHOD_GET, &url, &json!({}))?;
            status = response.status_code();
        }
        if status >= 400 {
            return Ok(json!({"items": [], "total": 0}));
        }
        let Some(body) = response.body.as_array() else {
            return Ok(json!({"items": [], "total": 0}));
        };
        let mut repositories = Vec::new();
        for repo in body {
            if filter_by_namespace {
                let ns = obj_field(repo, "namespace");
                if str_field(ns, "path") != owner_path {
                    continue;
                }
            }
            repositories.push(json!({
                "id": repo.get("id").cloned().unwrap_or(json!(0)),
                "name": str_field(repo, "name"),
                "description": str_field(repo, "description"),
                "private": str_field(repo, "visibility") == "private",
                "pushed_at": str_field(repo, "last_activity_at"),
            }));
        }
        let total = if filter_by_namespace {
            repositories.len() as i64
        } else {
            response
                .headers
                .get("x-total")
                .and_then(Value::as_str)
                .and_then(|s| s.parse::<i64>().ok())
                .unwrap_or(repositories.len() as i64)
        };
        Ok(json!({"items": repositories, "total": total}))
    }

    pub fn get_repository_name(&self, repository_id: &str) -> Result<String, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/projects/{repository_id}"),
            &json!({}),
        )?;
        if response.status_code() >= 400 {
            return Err(VcsError::message(format!(
                "Repository {repository_id} not found"
            )));
        }
        Ok(str_field(&response.body, "path"))
    }

    pub fn get_repository_tree(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
        recursive: bool,
    ) -> Result<Vec<String>, VcsError> {
        let project = self.project_path(owner, repository_name);
        let base = format!(
            "/projects/{project}/repository/tree?ref={}",
            php_urlencode(branch)
        );
        if recursive {
            let mut page = 1_i64;
            let mut all_items = Vec::new();
            loop {
                let response = self.call(
                    METHOD_GET,
                    &format!("{base}&recursive=true&per_page=100&page={page}"),
                    &json!({}),
                )?;
                if response.status_code() >= 400 {
                    return Ok(Vec::new());
                }
                let Some(items) = response.body.as_array() else {
                    break;
                };
                if items.is_empty() {
                    break;
                }
                let count = items.len();
                all_items.extend(items.iter().cloned());
                if count != 100 {
                    break;
                }
                page += 1;
            }
            return Ok(array_column_str(&all_items, "path"));
        }
        let response = self.call(METHOD_GET, &base, &json!({}))?;
        if response.status_code() >= 400 {
            return Ok(Vec::new());
        }
        Ok(array_column_str(
            response.body.as_array().map_or(&[][..], |v| v),
            "path",
        ))
    }

    pub fn get_repository_content(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        ref_name: &str,
    ) -> Result<Value, VcsError> {
        let project = self.project_path(owner, repository_name);
        let encoded = php_urlencode(&normalize_repository_path(path));
        let r = if php_empty_str(ref_name) {
            "HEAD"
        } else {
            ref_name
        };
        let response = self.call(
            METHOD_GET,
            &format!(
                "/projects/{project}/repository/files/{encoded}?ref={}",
                php_urlencode(r)
            ),
            &json!({}),
        )?;
        if response.status_code() != 200 {
            return Err(FileNotFound::new().into());
        }
        if str_field(&response.body, "encoding") != "base64" {
            return Err(FileNotFound::new().into());
        }
        let raw = str_field(&response.body, "content");
        let Ok(bytes) = base64::Engine::decode(&base64::engine::general_purpose::STANDARD, raw)
        else {
            return Err(FileNotFound::new().into());
        };
        Ok(json!({
            "sha": str_field(&response.body, "blob_id"),
            "size": response.body.get("size").cloned().unwrap_or(json!(0)),
            "content": String::from_utf8_lossy(&bytes),
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
        let project = self.project_path(owner, repository_name);
        let mut url = format!("/projects/{project}/repository/tree");
        if !php_empty_str(ref_name) {
            url.push_str("?ref=");
            url.push_str(&php_urlencode(ref_name));
        }
        if !path.is_empty() {
            url.push(if php_empty_str(ref_name) { '?' } else { '&' });
            url.push_str("path=");
            url.push_str(&php_urlencode(&path));
        }
        let response = self.call(METHOD_GET, &url, &json!({}))?;
        if response.status_code() >= 400 {
            return Ok(Vec::new());
        }
        let Some(items) = response.body.as_array() else {
            return Ok(Vec::new());
        };
        Ok(items
            .iter()
            .map(|item| {
                let kind = if str_field(item, "type") == "blob" {
                    CONTENTS_FILE
                } else {
                    CONTENTS_DIRECTORY
                };
                json!({"name": str_field(item, "name"), "size": 0, "type": kind})
            })
            .collect())
    }

    pub fn list_repository_languages(
        &self,
        owner: &str,
        repository_name: &str,
    ) -> Result<Vec<String>, VcsError> {
        let project = self.project_path(owner, repository_name);
        let response = self.call(
            METHOD_GET,
            &format!("/projects/{project}/languages"),
            &json!({}),
        )?;
        if response.status_code() >= 400 {
            return Ok(Vec::new());
        }
        Ok(array_keys(&response.body))
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
        let project = self.project_path(owner, repository_name);
        let encoded = php_urlencode(filepath);
        let branch = if php_empty_str(branch) {
            "main"
        } else {
            branch
        };
        let response = self.call(
            METHOD_POST,
            &format!("/projects/{project}/repository/files/{encoded}"),
            &json!({
                "branch": branch,
                "content": base64::Engine::encode(&base64::engine::general_purpose::STANDARD, content.as_bytes()),
                "encoding": "base64",
                "commit_message": message,
                "author_name": "utopia",
                "author_email": "utopia@example.com",
            }),
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
        let project = self.project_path(owner, repository_name);
        let response = self.call(
            METHOD_POST,
            &format!("/projects/{project}/repository/branches"),
            &json!({"branch": new_branch_name, "ref": old_branch_name}),
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

    pub fn create_pull_request(
        &self,
        owner: &str,
        repository_name: &str,
        title: &str,
        head: &str,
        base: &str,
        body: &str,
    ) -> Result<Value, VcsError> {
        let project = self.project_path(owner, repository_name);
        let response = self.call(
            METHOD_POST,
            &format!("/projects/{project}/merge_requests"),
            &json!({
                "title": title,
                "source_branch": head,
                "target_branch": base,
                "description": body,
            }),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to create merge request: HTTP {status}"),
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
        let project = self.project_path(owner, repository_name);
        let response = self.call(
            METHOD_POST,
            &format!("/projects/{project}/hooks"),
            &json!({
                "url": url,
                "token": secret,
                "enable_ssl_verification": false,
                "push_events": events.contains(&"push"),
                "merge_requests_events": events.contains(&"pull_request"),
            }),
        )?;
        let status = response.status_code();
        if status >= 400 {
            let body = serde_json::to_string(&response.body).unwrap_or_default();
            return Err(VcsError::with_status(
                format!("Failed to create webhook: HTTP {status} - {body}"),
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
        let project = self.project_path(owner, repository_name);
        let response = self.call(
            METHOD_POST,
            &format!("/projects/{project}/merge_requests/{pull_request_number}/notes"),
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
        Ok(format!(
            "{pull_request_number}:{}",
            str_field(&response.body, "id")
        ))
    }

    pub fn get_comment(
        &self,
        owner: &str,
        repository_name: &str,
        comment_id: &str,
    ) -> Result<String, VcsError> {
        let Some((mr_iid, note_id)) = comment_id.split_once(':') else {
            return Ok(String::new());
        };
        let project = self.project_path(owner, repository_name);
        let response = self.call(
            METHOD_GET,
            &format!("/projects/{project}/merge_requests/{mr_iid}/notes/{note_id}"),
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
        let Some((mr_iid, note_id)) = comment_id.split_once(':') else {
            return Err(VcsError::message(format!(
                "Invalid comment ID format: {comment_id}"
            )));
        };
        let project = self.project_path(owner, repository_name);
        let response = self.call(
            METHOD_PUT,
            &format!("/projects/{project}/merge_requests/{mr_iid}/notes/{note_id}"),
            &json!({"body": comment}),
        )?;
        if response.status_code() != 200 {
            let status = response.status_code();
            return Err(VcsError::with_status(
                format!("Failed to update comment: HTTP {status}"),
                status,
            ));
        }
        Ok(comment_id.to_string())
    }

    pub fn get_user(&self, username: &str) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/users?username={}", php_rawurlencode(username)),
            &json!({}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get user: HTTP {status}"),
                status,
            ));
        }
        response
            .body
            .as_array()
            .and_then(|items| items.first())
            .cloned()
            .ok_or_else(|| VcsError::message(format!("User not found: {username}")))
    }

    pub fn get_owner_name(
        &self,
        _installation_id: &str,
        repository_id: Option<i64>,
    ) -> Result<String, VcsError> {
        if let Some(id) = repository_id.filter(|id| *id > 0) {
            let response = self.call(METHOD_GET, &format!("/projects/{id}"), &json!({}))?;
            let status = response.status_code();
            if status >= 400 {
                return Err(VcsError::with_status(
                    format!("Failed to get owner name for repository {id}: HTTP {status}"),
                    status,
                ));
            }
            return Ok(str_field(obj_field(&response.body, "namespace"), "path"));
        }
        let response = self.call(METHOD_GET, "/user", &json!({}))?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get current user: HTTP {status}"),
                status,
            ));
        }
        Ok(str_field(&response.body, "username"))
    }

    fn normalize_mr(&self, mr: &Value) -> Value {
        json!({
            "number": mr.get("iid").cloned().unwrap_or(json!(0)),
            "title": str_field(mr, "title"),
            "state": str_field(mr, "state"),
            "head": {
                "ref": str_field(mr, "source_branch"),
                "sha": str_field(mr, "sha"),
            },
            "base": { "ref": str_field(mr, "target_branch") },
        })
    }

    pub fn get_pull_request(
        &self,
        owner: &str,
        repository_name: &str,
        pull_request_number: i64,
    ) -> Result<Value, VcsError> {
        let project = self.project_path(owner, repository_name);
        let response = self.call(
            METHOD_GET,
            &format!("/projects/{project}/merge_requests/{pull_request_number}"),
            &json!({}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get merge request: HTTP {status}"),
                status,
            ));
        }
        Ok(self.normalize_mr(&response.body))
    }

    pub fn get_pull_request_files(
        &self,
        owner: &str,
        repository_name: &str,
        pull_request_number: i64,
    ) -> Result<Vec<Value>, VcsError> {
        let project = self.project_path(owner, repository_name);
        for _ in 0..10 {
            let mr = self.call(
                METHOD_GET,
                &format!("/projects/{project}/merge_requests/{pull_request_number}"),
                &json!({}),
            )?;
            if !mr.body.get("patch_id_sha").map_or(true, Value::is_null) {
                break;
            }
            thread::sleep(Duration::from_secs(1));
        }
        let mut all_files = Vec::new();
        let mut page = 1_i64;
        let per_page = 100_i64;
        loop {
            let response = self.call(
                METHOD_GET,
                &format!(
                    "/projects/{project}/merge_requests/{pull_request_number}/diffs?page={page}&per_page={per_page}"
                ),
                &json!({}),
            )?;
            let status = response.status_code();
            if status >= 400 {
                return Err(VcsError::with_status(
                    format!("Failed to get merge request files: HTTP {status}"),
                    status,
                ));
            }
            let Some(files) = response.body.as_array() else {
                break;
            };
            if files.is_empty() {
                break;
            }
            let count = files.len();
            for diff in files {
                let filename = if str_field(diff, "new_path").is_empty() {
                    str_field(diff, "old_path")
                } else {
                    str_field(diff, "new_path")
                };
                all_files.push(json!({"filename": filename}));
            }
            if (count as i64) < per_page {
                break;
            }
            page += 1;
        }
        Ok(all_files)
    }

    pub fn get_pull_request_from_branch(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
    ) -> Result<Value, VcsError> {
        let project = self.project_path(owner, repository_name);
        let response = self.call(
            METHOD_GET,
            &format!(
                "/projects/{project}/merge_requests?state=opened&source_branch={}",
                php_urlencode(branch)
            ),
            &json!({}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to list merge requests: HTTP {status}"),
                status,
            ));
        }
        Ok(response
            .body
            .as_array()
            .and_then(|items| items.first())
            .map(|mr| self.normalize_mr(mr))
            .unwrap_or(json!({})))
    }

    pub fn list_branches(
        &self,
        owner: &str,
        repository_name: &str,
    ) -> Result<Vec<String>, VcsError> {
        self.list_ref_names(owner, repository_name, "branches")
    }

    pub fn list_tags(
        &self,
        owner: &str,
        repository_name: &str,
        search: &str,
    ) -> Result<Vec<String>, VcsError> {
        Ok(match_glob(
            self.list_ref_names(owner, repository_name, "tags")?,
            search,
        ))
    }

    fn list_ref_names(
        &self,
        owner: &str,
        repository_name: &str,
        kind: &str,
    ) -> Result<Vec<String>, VcsError> {
        let project = self.project_path(owner, repository_name);
        let mut names = Vec::new();
        let mut page = 1_i64;
        loop {
            let response = self.call(
                METHOD_GET,
                &format!("/projects/{project}/repository/{kind}?per_page=100&page={page}"),
                &json!({}),
            )?;
            if response.status_code() >= 400 {
                return Ok(if page == 1 { Vec::new() } else { names });
            }
            let Some(items) = response.body.as_array() else {
                break;
            };
            if items.is_empty() {
                break;
            }
            let count = items.len();
            for item in items {
                names.push(str_field(item, "name"));
            }
            if count != 100 {
                break;
            }
            page += 1;
        }
        Ok(names)
    }

    pub fn get_commit(
        &self,
        owner: &str,
        repository_name: &str,
        commit_hash: &str,
    ) -> Result<Value, VcsError> {
        let project = self.project_path(owner, repository_name);
        let response = self.call(
            METHOD_GET,
            &format!(
                "/projects/{project}/repository/commits/{}",
                php_urlencode(commit_hash)
            ),
            &json!({}),
        )?;
        if response.status_code() >= 400 {
            return Err(VcsError::message("Commit not found or inaccessible"));
        }
        Ok(self.parse_commit(&response.body))
    }

    pub fn get_latest_commit(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
    ) -> Result<Value, VcsError> {
        let project = self.project_path(owner, repository_name);
        let response = self.call(
            METHOD_GET,
            &format!(
                "/projects/{project}/repository/commits?ref_name={}&per_page=1",
                php_urlencode(branch)
            ),
            &json!({}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get latest commit: HTTP {status}"),
                status,
            ));
        }
        let commit = response
            .body
            .as_array()
            .and_then(|items| items.first())
            .ok_or_else(|| {
                VcsError::message("Latest commit response is missing required information.")
            })?;
        Ok(self.parse_commit(commit))
    }

    fn parse_commit(&self, commit: &Value) -> Value {
        json!({
            "commitAuthor": nonempty(&str_field(commit, "author_name"), "Unknown"),
            "commitMessage": nonempty(&str_field(commit, "message"), "No message"),
            "commitHash": str_field(commit, "id"),
            "commitUrl": str_field(commit, "web_url"),
            "commitAuthorAvatar": "",
            "commitAuthorUrl": "",
        })
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
        let project = self.project_path(owner, repository_name);
        let gitlab_state = match state {
            "pending" => "pending",
            "success" => "success",
            "failure" | "error" => "failed",
            "cancelled" => "canceled",
            other => other,
        };
        let mut payload = json!({"state": gitlab_state});
        if !php_empty_str(description) {
            payload["description"] = json!(description);
        }
        if !php_empty_str(target_url) {
            payload["target_url"] = json!(target_url);
        }
        if !php_empty_str(context) {
            payload["name"] = json!(context);
        }
        let response = self.call(
            METHOD_POST,
            &format!(
                "/projects/{project}/statuses/{}",
                php_urlencode(commit_hash)
            ),
            &payload,
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
        let root_directory = if php_empty_str(root_directory) || root_directory == "/" {
            "*"
        } else {
            root_directory
        };
        let owner_path = self.owner_path(owner);
        let mut base_url = self.gitlab_url.clone();
        if !php_empty_str(&self.access_token) {
            base_url = self.gitlab_url.replace(
                "://",
                &format!("://oauth2:{}@", php_urlencode(&self.access_token)),
            );
        }
        let clone_url = escape_shell_arg(&format!("{base_url}/{owner_path}/{repository_name}.git"));
        sparse_clone_commands(&clone_url, directory, root_directory, version, version_type)
    }

    pub fn get_events(&self, event: &str, payload: &str) -> Result<Vec<Value>, VcsError> {
        gitlab_get_events(event, payload)
    }

    #[must_use]
    pub fn validate_webhook_event(
        &self,
        _payload: &str,
        signature: &str,
        signature_key: &str,
    ) -> bool {
        hash_equals(signature_key, signature)
    }

    pub fn create_tag(
        &self,
        owner: &str,
        repository_name: &str,
        tag_name: &str,
        target: &str,
        message: &str,
    ) -> Result<Value, VcsError> {
        let project = self.project_path(owner, repository_name);
        let mut payload = json!({"tag_name": tag_name, "ref": target});
        if !php_empty_str(message) {
            payload["message"] = json!(message);
        }
        let response = self.call(
            METHOD_POST,
            &format!("/projects/{project}/repository/tags"),
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
        let project = self.project_path(owner, repository_name);
        let response = self.call(
            METHOD_GET,
            &format!(
                "/projects/{project}/repository/commits/{}/statuses",
                php_urlencode(commit_hash)
            ),
            &json!({}),
        )?;
        if response.status_code() >= 400 {
            return Ok(Vec::new());
        }
        let Some(items) = response.body.as_array() else {
            return Ok(Vec::new());
        };
        Ok(items
            .iter()
            .map(|status| {
                json!({
                    "state": str_field(status, "status"),
                    "description": str_field(status, "description"),
                    "target_url": str_field(status, "target_url"),
                    "context": str_field(status, "name"),
                })
            })
            .collect())
    }

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

    #[must_use]
    pub fn get_event_header_name(&self) -> &'static str {
        "x-gitlab-event"
    }

    #[must_use]
    pub fn get_signature_header_name(&self) -> &'static str {
        "x-gitlab-token"
    }

    #[must_use]
    pub fn get_supported_webhook_scopes(&self) -> &'static [&'static str] {
        &[WEBHOOK_SCOPE_REPOSITORY]
    }

    #[must_use]
    pub fn get_repository_url(&self, owner: &str, repository_name: &str) -> String {
        format!(
            "{}/{}/{}",
            self.gitlab_url,
            self.owner_path(owner),
            repository_name
        )
    }

    #[must_use]
    pub fn get_branch_url(&self, owner: &str, repository_name: &str, branch: &str) -> String {
        format!(
            "{}/-/tree/{branch}",
            self.get_repository_url(owner, repository_name)
        )
    }

    #[must_use]
    pub fn get_commit_url(&self, owner: &str, repository_name: &str, commit_hash: &str) -> String {
        format!(
            "{}/-/commit/{commit_hash}",
            self.get_repository_url(owner, repository_name)
        )
    }

    #[must_use]
    pub fn get_file_url(&self, owner: &str, repository_name: &str, reference: &str) -> String {
        format!(
            "{}/-/blob/{reference}",
            self.get_repository_url(owner, repository_name)
        )
    }
}

fn nonempty(value: &str, fallback: &str) -> String {
    if value.is_empty() {
        fallback.to_string()
    } else {
        value.to_string()
    }
}

pub(crate) fn sparse_clone_commands(
    clone_url_escaped: &str,
    directory: &str,
    root_directory: &str,
    version: &str,
    version_type: &str,
) -> Result<String, VcsError> {
    let directory = escape_shell_arg(directory);
    let root_directory = escape_shell_arg(root_directory);
    let mut commands = vec![
        format!("mkdir -p {directory}"),
        format!("cd {directory}"),
        "git config --global init.defaultBranch main".into(),
        "git init".into(),
        format!("git remote add origin {clone_url_escaped}"),
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
                "git fetch --depth=1 origin refs/tags/{tag} && git checkout FETCH_HEAD"
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

/// Parsed webhook events (PHP `GitLab::getEvents`).
pub fn gitlab_get_events(event: &str, payload: &str) -> Result<Vec<Value>, VcsError> {
    let payload: Value = serde_json::from_str(payload)
        .ok()
        .filter(Value::is_object)
        .ok_or_else(|| VcsError::message("Invalid payload."))?;
    match event {
        "Push Hook" => Ok(vec![parse_gitlab_push(&payload)]),
        "Merge Request Hook" => Ok(vec![parse_gitlab_mr(&payload)]),
        _ => Ok(Vec::new()),
    }
}

fn parse_gitlab_push(payload: &Value) -> Value {
    let project = obj_field(payload, "project");
    let commits = payload.get("commits").and_then(Value::as_array);
    let checkout_sha = str_field(payload, "checkout_sha");
    let latest = commits
        .and_then(|items| {
            items
                .iter()
                .find(|c| str_field(c, "id") == checkout_sha)
                .or_else(|| items.last())
        })
        .cloned()
        .unwrap_or(json!({}));
    let repository_url = str_field(project, "web_url");
    let branch = str_field(payload, "ref").replacen("refs/heads/", "", 1);
    let branch_url = if !repository_url.is_empty() && !branch.is_empty() {
        format!("{repository_url}/-/tree/{branch}")
    } else {
        String::new()
    };
    let mut affected = serde_json::Map::new();
    if let Some(commits) = commits {
        for commit in commits {
            for key in ["added", "modified", "removed"] {
                if let Some(files) = commit.get(key).and_then(Value::as_array) {
                    for file in files {
                        affected.insert(strval(file), json!(true));
                    }
                }
            }
        }
    }
    let zeros = "0".repeat(40);
    json!({
        "branchCreated": str_field(payload, "before") == zeros,
        "branchDeleted": str_field(payload, "after") == zeros,
        "branch": branch,
        "branchUrl": branch_url,
        "repositoryId": strval(field_or_null(project, "id")),
        "repositoryName": str_field(project, "name"),
        "repositoryUrl": repository_url,
        "installationId": "",
        "commitHash": checkout_sha,
        "owner": str_field(project, "namespace"),
        "authorUrl": "",
        "authorAvatarUrl": str_field(payload, "user_avatar"),
        "headCommitAuthorName": str_field(obj_field(&latest, "author"), "name"),
        "headCommitAuthorEmail": str_field(obj_field(&latest, "author"), "email"),
        "headCommitMessage": str_field(&latest, "message"),
        "headCommitUrl": str_field(&latest, "url"),
        "external": false,
        "pullRequestNumber": "",
        "action": "",
        "affectedFiles": affected.keys().cloned().collect::<Vec<_>>(),
    })
}

fn parse_gitlab_mr(payload: &Value) -> Value {
    let project = obj_field(payload, "project");
    let mr = obj_field(payload, "object_attributes");
    let repository_url = str_field(project, "web_url");
    let branch = str_field(mr, "source_branch");
    let branch_url = if !repository_url.is_empty() && !branch.is_empty() {
        format!("{repository_url}/-/tree/{branch}")
    } else {
        String::new()
    };
    let native = str_field(mr, "action");
    let action = MERGE_REQUEST_ACTION_MAP
        .iter()
        .find(|(k, _)| *k == native)
        .map(|(_, v)| (*v).to_string())
        .unwrap_or_default();
    let external = mr.get("source_project_id").is_some()
        && mr.get("target_project_id").is_some()
        && mr.get("source_project_id") != mr.get("target_project_id");
    json!({
        "branch": branch,
        "branchUrl": branch_url,
        "repositoryId": strval(field_or_null(project, "id")),
        "repositoryName": str_field(project, "name"),
        "repositoryUrl": repository_url,
        "installationId": "",
        "commitHash": str_field(obj_field(mr, "last_commit"), "id"),
        "owner": str_field(project, "namespace"),
        "authorUrl": "",
        "authorAvatarUrl": str_field(obj_field(payload, "user"), "avatar_url"),
        "headCommitUrl": str_field(obj_field(mr, "last_commit"), "url"),
        "external": external,
        "pullRequestNumber": field_or_null(mr, "iid").clone(),
        "action": action,
    })
}
