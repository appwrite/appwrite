//! Bitbucket adapter (PHP `Utopia\VCS\Adapter\Git\Bitbucket`).

use std::collections::HashMap;

use base64::Engine;
use rand::RngCore;
use serde_json::{json, Value};
use sha1::{Digest, Sha1};

use crate::adapter::git::gitlab::sparse_clone_commands;
use crate::adapter::{WebhookId, TYPE_GIT, WEBHOOK_SCOPE_REPOSITORY};
use crate::cache::CacheStore;
use crate::error::{FileNotFound, RepositoryNotFound, VcsError};
use crate::http::{CallResponse, HttpClient, METHOD_DELETE, METHOD_GET, METHOD_POST, METHOD_PUT};
use crate::php::{
    array_column_str, escape_shell_arg, field_or_null, gmdate_iso, match_glob,
    normalize_repository_path, obj_field, php_empty_str, php_rawurlencode, php_urlencode,
    str_field, validate_hmac_sha256_prefixed,
};

pub const CONTENTS_FILE: &str = "file";
pub const CONTENTS_DIRECTORY: &str = "dir";
const PAGE_SIZE: i64 = 100;
const MAX_TREE_DEPTH: i64 = 100;

const COMMIT_STATE_MAP: &[(&str, &str)] = &[
    ("pending", "INPROGRESS"),
    ("in_progress", "INPROGRESS"),
    ("success", "SUCCESSFUL"),
    ("failure", "FAILED"),
    ("error", "FAILED"),
    ("cancelled", "STOPPED"),
];
const COMMIT_STATE_MAP_REVERSE: &[(&str, &str)] = &[
    ("INPROGRESS", "pending"),
    ("SUCCESSFUL", "success"),
    ("FAILED", "failure"),
    ("STOPPED", "cancelled"),
];
const CHECK_RUN_CONCLUSION_MAP: &[(&str, &str)] = &[
    ("success", "SUCCESSFUL"),
    ("failure", "FAILED"),
    ("timed_out", "FAILED"),
    ("action_required", "FAILED"),
    ("cancelled", "STOPPED"),
    ("neutral", "STOPPED"),
    ("skipped", "STOPPED"),
];
const PULL_REQUEST_ACTION_MAP: &[(&str, &str)] = &[
    ("pullrequest:created", "opened"),
    ("pullrequest:updated", "synchronize"),
    ("pullrequest:fulfilled", "closed"),
    ("pullrequest:rejected", "closed"),
];

#[derive(Debug)]
pub struct Bitbucket {
    http: HttpClient,
    bitbucket_url: String,
    #[allow(dead_code)]
    cache: Box<dyn CacheStore>,
    access_token: String,
}

impl Bitbucket {
    pub fn new(cache: impl CacheStore + 'static) -> Self {
        Self {
            http: HttpClient::new("https://api.bitbucket.org/2.0"),
            bitbucket_url: "https://bitbucket.org".into(),
            cache: Box::new(cache),
            access_token: String::new(),
        }
    }

    pub fn set_endpoint(&mut self, endpoint: impl Into<String>) {
        self.http.endpoint = endpoint.into().trim_end_matches('/').to_string();
    }

    #[must_use]
    pub fn get_name(&self) -> &'static str {
        "bitbucket"
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

    fn authorization_header(&self) -> String {
        if self.access_token.contains(':') {
            format!(
                "Basic {}",
                Engine::encode(
                    &base64::engine::general_purpose::STANDARD,
                    self.access_token.as_bytes()
                )
            )
        } else {
            format!("Bearer {}", self.access_token)
        }
    }

    fn auth(&self) -> HashMap<String, String> {
        HashMap::from([("Authorization".into(), self.authorization_header())])
    }

    fn call(&self, method: &str, path: &str, params: &Value) -> Result<CallResponse, VcsError> {
        self.http
            .call(method, path, self.auth(), params, true, true)
    }

    fn call_ct(
        &self,
        method: &str,
        path: &str,
        extra: HashMap<String, String>,
        params: &Value,
        decode: bool,
    ) -> Result<CallResponse, VcsError> {
        let mut headers = self.auth();
        headers.extend(extra);
        self.http.call(method, path, headers, params, decode, true)
    }

    fn workspace_slug_of(&self, repository: &Value) -> String {
        let slug = str_field(obj_field(repository, "workspace"), "slug");
        if !php_empty_str(&slug) {
            return slug;
        }
        let full_name = str_field(repository, "full_name");
        full_name
            .split_once('/')
            .map(|(w, _)| w.to_string())
            .unwrap_or_default()
    }

    fn normalize_repository(&self, mut repository: Value) -> Value {
        repository["id"] = json!(str_field(&repository, "full_name"));
        repository["private"] = json!(repository
            .get("is_private")
            .and_then(Value::as_bool)
            .unwrap_or(false));
        repository["pushed_at"] = json!(str_field(&repository, "updated_on"));
        if php_empty_str(&str_field(obj_field(&repository, "workspace"), "slug")) {
            repository["workspace"] = json!({"slug": self.workspace_slug_of(&repository)});
        }
        repository
    }

    fn resolve_ref(
        &self,
        owner: &str,
        repository_name: &str,
        ref_name: &str,
    ) -> Result<String, VcsError> {
        if !ref_name.contains('/') {
            return Ok(ref_name.to_string());
        }
        let response = self.call(
            METHOD_GET,
            &format!("/repositories/{owner}/{repository_name}/refs/branches/{ref_name}"),
            &json!({}),
        )?;
        let hash = str_field(obj_field(&response.body, "target"), "hash");
        Ok(if php_empty_str(&hash) {
            ref_name.to_string()
        } else {
            hash
        })
    }

    fn main_branch_name(&self, owner: &str, repository_name: &str) -> Result<String, VcsError> {
        let repo = self.get_repository(owner, repository_name)?;
        Ok(str_field(obj_field(&repo, "mainbranch"), "name"))
    }

    pub fn create_repository(
        &self,
        owner: &str,
        repository_name: &str,
        private: bool,
    ) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_POST,
            &format!("/repositories/{owner}/{repository_name}"),
            &json!({"scm": "git", "name": repository_name, "is_private": private}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            let error = str_field(obj_field(&response.body, "error"), "message");
            let suffix = if error.is_empty() {
                String::new()
            } else {
                format!(": {error}")
            };
            return Err(VcsError::with_status(
                format!("Creating repository {repository_name} failed with status code {status}{suffix}"),
                status,
            ));
        }
        Ok(self.normalize_repository(response.body_object()))
    }

    pub fn delete_repository(&self, owner: &str, repository_name: &str) -> Result<bool, VcsError> {
        let response = self.call(
            METHOD_DELETE,
            &format!("/repositories/{owner}/{repository_name}"),
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

    pub fn get_repository(&self, owner: &str, repository_name: &str) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repositories/{owner}/{repository_name}"),
            &json!({}),
        )?;
        if response.status_code() >= 400 {
            return Err(RepositoryNotFound::new("Repository not found").into());
        }
        Ok(self.normalize_repository(response.body_object()))
    }

    pub fn get_repository_name(&self, repository_id: &str) -> Result<String, VcsError> {
        let decoded = {
            let bytes = php_rawurldecode(repository_id);
            String::from_utf8_lossy(&bytes).into_owned()
        };
        let Some((workspace, slug)) = decoded.split_once('/') else {
            return Err(
                RepositoryNotFound::new(format!("Repository {repository_id} not found")).into(),
            );
        };
        let repo = self.get_repository(workspace, slug)?;
        let name = str_field(&repo, "slug");
        Ok(if name.is_empty() {
            slug.to_string()
        } else {
            name
        })
    }

    pub fn has_access_to_all_repositories(&self) -> Result<bool, VcsError> {
        Ok(true)
    }

    pub fn get_installation_repository(&self, _repository_name: &str) -> Result<Value, VcsError> {
        Err(VcsError::message(
            "getInstallationRepository is not applicable for this adapter",
        ))
    }

    pub fn search_repositories(
        &self,
        owner: &str,
        page: i64,
        per_page: i64,
        search: &str,
    ) -> Result<Value, VcsError> {
        let mut url = format!("/repositories/{owner}?page={page}&pagelen={per_page}");
        if !php_empty_str(search) {
            let escaped = search.replace('\\', "\\\\").replace('"', "\\\"");
            url.push_str("&q=");
            url.push_str(&php_urlencode(&format!("name~\"{escaped}\"")));
        }
        let response = self.call(METHOD_GET, &url, &json!({}))?;
        if response.status_code() >= 400 {
            return Ok(json!({"items": [], "total": 0}));
        }
        let values = response
            .body
            .get("values")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default();
        let repositories: Vec<Value> = values
            .into_iter()
            .map(|repo| {
                let repo = self.normalize_repository(repo);
                json!({
                    "id": repo.get("id").cloned().unwrap_or(json!("")),
                    "name": str_field(&repo, "name"),
                    "description": str_field(&repo, "description"),
                    "private": repo.get("private").cloned().unwrap_or(json!(false)),
                    "pushed_at": str_field(&repo, "pushed_at"),
                })
            })
            .collect();
        let total = response
            .body
            .get("size")
            .and_then(Value::as_i64)
            .unwrap_or(repositories.len() as i64);
        Ok(json!({"items": repositories, "total": total}))
    }

    fn source_url(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        mut ref_name: String,
    ) -> Result<String, VcsError> {
        if php_empty_str(&ref_name) {
            ref_name = self.main_branch_name(owner, repository_name)?;
            if php_empty_str(&ref_name) {
                return Err(VcsError::message(format!(
                    "Unable to resolve the main branch of {owner}/{repository_name}."
                )));
            }
        }
        let ref_name = self.resolve_ref(owner, repository_name, &ref_name)?;
        let path = normalize_repository_path(path)
            .split('/')
            .map(php_rawurlencode)
            .collect::<Vec<_>>()
            .join("/");
        Ok(format!(
            "/repositories/{owner}/{repository_name}/src/{}/{path}",
            php_rawurlencode(&ref_name)
        ))
    }

    fn list_source(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        ref_name: &str,
        suffix: &str,
    ) -> Result<Vec<Value>, VcsError> {
        let Ok(base) = self.source_url(owner, repository_name, path, ref_name.to_string()) else {
            return Ok(Vec::new());
        };
        let mut items = Vec::new();
        let mut url = format!("{base}?pagelen={PAGE_SIZE}{suffix}");
        while !url.is_empty() {
            let response = self.call(METHOD_GET, &url, &json!({}))?;
            let status = response.status_code();
            if status == 404 {
                return Ok(items);
            }
            if status >= 400 {
                return Err(VcsError::with_status(
                    format!("Listing {owner}/{repository_name} failed with status code {status}"),
                    status,
                ));
            }
            let values = response
                .body
                .get("values")
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            items.extend(values);
            let next = str_field(&response.body, "next");
            url = if next.starts_with(&self.http.endpoint) {
                next[self.http.endpoint.len()..].to_string()
            } else {
                String::new()
            };
        }
        Ok(items)
    }

    pub fn get_repository_tree(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
        recursive: bool,
    ) -> Result<Vec<String>, VcsError> {
        let suffix = if recursive {
            format!("&max_depth={MAX_TREE_DEPTH}")
        } else {
            String::new()
        };
        let items = self.list_source(owner, repository_name, "", branch, &suffix)?;
        Ok(array_column_str(&items, "path"))
    }

    pub fn list_repository_contents(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        ref_name: &str,
    ) -> Result<Vec<Value>, VcsError> {
        let items = self.list_source(owner, repository_name, path, ref_name, "")?;
        Ok(items
            .iter()
            .map(|item| {
                let item_path = str_field(item, "path");
                let name = item_path
                    .rsplit('/')
                    .next()
                    .unwrap_or(&item_path)
                    .to_string();
                json!({
                    "name": name,
                    "size": item.get("size").cloned().unwrap_or(json!(0)),
                    "type": if str_field(item, "type") == "commit_directory" {
                        CONTENTS_DIRECTORY
                    } else {
                        CONTENTS_FILE
                    },
                })
            })
            .collect())
    }

    pub fn get_repository_content(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        ref_name: &str,
    ) -> Result<Value, VcsError> {
        let url = self
            .source_url(owner, repository_name, path, ref_name.to_string())
            .map_err(|_| FileNotFound::new())?;
        let meta = self
            .call(METHOD_GET, &format!("{url}?format=meta"), &json!({}))
            .map_err(|_| FileNotFound::new())?;
        if meta.status_code() != 200 {
            return Err(FileNotFound::new().into());
        }
        if str_field(&meta.body, "type") != "commit_file" {
            return Err(FileNotFound::new().into());
        }
        let content_response = self
            .call_ct(METHOD_GET, &url, HashMap::new(), &json!({}), false)
            .map_err(|_| FileNotFound::new())?;
        if content_response.status_code() != 200 {
            return Err(FileNotFound::new().into());
        }
        let content = match &content_response.body {
            Value::String(s) => s.clone(),
            other => other.to_string(),
        };
        let mut hasher = Sha1::new();
        hasher.update(format!("blob {}\0", content.len()).as_bytes());
        hasher.update(content.as_bytes());
        Ok(json!({
            "sha": hex::encode(hasher.finalize()),
            "size": content.len(),
            "content": content,
        }))
    }

    pub fn list_repository_languages(
        &self,
        owner: &str,
        repository_name: &str,
    ) -> Result<Vec<String>, VcsError> {
        let repository = match self.get_repository(owner, repository_name) {
            Ok(repo) => repo,
            Err(VcsError::RepositoryNotFound(_)) => return Ok(Vec::new()),
            Err(error) => return Err(error),
        };
        let language = str_field(&repository, "language");
        Ok(if php_empty_str(&language) {
            Vec::new()
        } else {
            vec![language]
        })
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
        let mut branch = branch.to_string();
        if php_empty_str(&branch) {
            branch = self.main_branch_name(owner, repository_name)?;
        }
        let mut payload = json!({
            "message": message,
            format!("/{}", normalize_repository_path(filepath)): content,
        });
        if !php_empty_str(&branch) {
            payload["branch"] = json!(branch);
        }
        let response = self.call_ct(
            METHOD_POST,
            &format!("/repositories/{owner}/{repository_name}/src"),
            HashMap::from([(
                "content-type".into(),
                "application/x-www-form-urlencoded".into(),
            )]),
            &payload,
            true,
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to create file {filepath}: HTTP {status}"),
                status,
            ));
        }
        let location = response.header("location");
        let commit_hash = regex::Regex::new(r"/commit/([0-9a-f]+)")
            .ok()
            .and_then(|re| {
                re.captures(&location)
                    .and_then(|c| c.get(1).map(|m| m.as_str().to_string()))
            })
            .unwrap_or_default();
        Ok(json!({"path": filepath, "branch": branch, "commitHash": commit_hash}))
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
            &format!("/repositories/{owner}/{repository_name}/refs/branches"),
            &json!({"name": new_branch_name, "target": {"hash": old_branch_name}}),
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

    pub fn list_branches(
        &self,
        owner: &str,
        repository_name: &str,
    ) -> Result<Vec<String>, VcsError> {
        self.list_refs(owner, repository_name, "branches")
    }

    pub fn list_tags(
        &self,
        owner: &str,
        repository_name: &str,
        search: &str,
    ) -> Result<Vec<String>, VcsError> {
        Ok(match_glob(
            self.list_refs(owner, repository_name, "tags")?,
            search,
        ))
    }

    fn list_refs(
        &self,
        owner: &str,
        repository_name: &str,
        kind: &str,
    ) -> Result<Vec<String>, VcsError> {
        let mut names = Vec::new();
        let mut page = 1_i64;
        loop {
            let response = self.call(
                METHOD_GET,
                &format!(
                    "/repositories/{owner}/{repository_name}/refs/{kind}?pagelen={PAGE_SIZE}&page={page}"
                ),
                &json!({}),
            )?;
            let status = response.status_code();
            if status >= 400 {
                return Ok(if page == 1 { Vec::new() } else { names });
            }
            let values = response
                .body
                .get("values")
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            for item in &values {
                names.push(str_field(item, "name"));
            }
            if str_field(&response.body, "next").is_empty() {
                break;
            }
            page += 1;
        }
        Ok(names)
    }

    pub fn create_tag(
        &self,
        owner: &str,
        repository_name: &str,
        tag_name: &str,
        target: &str,
        message: &str,
    ) -> Result<Value, VcsError> {
        let mut payload = json!({"name": tag_name, "target": {"hash": target}});
        if !php_empty_str(message) {
            payload["message"] = json!(message);
        }
        let response = self.call(
            METHOD_POST,
            &format!("/repositories/{owner}/{repository_name}/refs/tags"),
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

    fn author_name_of(&self, author: &Value) -> String {
        author_name_of(author)
    }

    fn parse_commit(&self, commit: &Value) -> Value {
        let author = obj_field(commit, "author");
        let user = obj_field(author, "user");
        let links = obj_field(user, "links");
        let name = self.author_name_of(author);
        json!({
            "commitAuthor": if php_empty_str(&name) { "Unknown".into() } else { name },
            "commitMessage": nonempty(&str_field(commit, "message"), "No message"),
            "commitHash": str_field(commit, "hash"),
            "commitUrl": str_field(obj_field(obj_field(commit, "links"), "html"), "href"),
            "commitAuthorAvatar": str_field(obj_field(links, "avatar"), "href"),
            "commitAuthorUrl": str_field(obj_field(links, "html"), "href"),
        })
    }

    pub fn get_commit(
        &self,
        owner: &str,
        repository_name: &str,
        commit_hash: &str,
    ) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!(
                "/repositories/{owner}/{repository_name}/commit/{}",
                php_rawurlencode(commit_hash)
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
        let branch = self.resolve_ref(owner, repository_name, branch)?;
        let response = self.call(
            METHOD_GET,
            &format!(
                "/repositories/{owner}/{repository_name}/commits/{}?pagelen=1",
                php_rawurlencode(&branch)
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
            .get("values")
            .and_then(Value::as_array)
            .and_then(|v| v.first())
            .ok_or_else(|| {
                VcsError::message("Latest commit response is missing required information.")
            })?;
        Ok(self.parse_commit(commit))
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
        let key = if php_empty_str(context) {
            self.get_name()
        } else {
            context
        };
        let mapped = COMMIT_STATE_MAP
            .iter()
            .find(|(k, _)| *k == state)
            .map_or(state, |(_, v)| *v);
        let url = if php_empty_str(target_url) {
            self.get_commit_url(owner, repository_name, commit_hash)
        } else {
            target_url.to_string()
        };
        let mut payload = json!({"key": key, "name": key, "state": mapped, "url": url});
        if !php_empty_str(description) {
            payload["description"] = json!(description);
        }
        let response = self.call(
            METHOD_POST,
            &format!(
                "/repositories/{owner}/{repository_name}/commit/{}/statuses/build",
                php_rawurlencode(commit_hash)
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

    pub fn get_commit_statuses(
        &self,
        owner: &str,
        repository_name: &str,
        commit_hash: &str,
    ) -> Result<Vec<Value>, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!(
                "/repositories/{owner}/{repository_name}/commit/{}/statuses?pagelen={PAGE_SIZE}",
                php_rawurlencode(commit_hash)
            ),
            &json!({}),
        )?;
        if response.status_code() >= 400 {
            return Ok(Vec::new());
        }
        let values = response
            .body
            .get("values")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default();
        Ok(values
            .iter()
            .map(|status| {
                let state = str_field(status, "state");
                let mapped = COMMIT_STATE_MAP_REVERSE
                    .iter()
                    .find(|(k, _)| *k == state)
                    .map(|(_, v)| (*v).to_string())
                    .unwrap_or(state);
                json!({
                    "state": mapped,
                    "description": str_field(status, "description"),
                    "target_url": str_field(status, "url"),
                    "context": str_field(status, "key"),
                })
            })
            .collect())
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
        _annotations: &[Value],
        _images: &[Value],
        _actions: &[Value],
        details_url: &str,
        _external_id: &str,
        started_at: &str,
        completed_at: &str,
    ) -> Result<Value, VcsError> {
        let (status, conclusion, completed_at) =
            settle_check_run(status, conclusion, completed_at)?;
        let mut key_bytes = [0u8; 8];
        rand::thread_rng().fill_bytes(&mut key_bytes);
        let key = format!("check-run-{}", hex::encode(key_bytes));
        let url = if php_empty_str(details_url) {
            self.get_commit_url(owner, repository_name, head_sha)
        } else {
            details_url.to_string()
        };
        let written = self.write_build_status(
            owner,
            repository_name,
            head_sha,
            json!({
                "key": key,
                "name": name,
                "state": check_run_state(&status, &conclusion),
                "url": url,
                "description": summary,
            }),
        )?;
        let started = if php_empty_str(started_at) {
            str_field(&written, "created_on")
        } else {
            started_at.to_string()
        };
        Ok(self.parse_check_run(
            &written,
            owner,
            repository_name,
            head_sha,
            json!({
                "status": status,
                "conclusion": if conclusion.is_empty() { Value::Null } else { json!(conclusion) },
                "output": {"title": title, "summary": summary, "text": text},
                "started_at": started,
                "completed_at": if php_empty_str(&completed_at) { Value::Null } else { json!(completed_at) },
            }),
        ))
    }

    pub fn get_check_run(
        &self,
        owner: &str,
        repository_name: &str,
        check_run_id: &str,
    ) -> Result<Value, VcsError> {
        let (commit_hash, key) = split_check_run_id(check_run_id)?;
        let response = self.call(
            METHOD_GET,
            &format!(
                "/repositories/{owner}/{repository_name}/commit/{}/statuses/build/{}",
                php_rawurlencode(&commit_hash),
                php_rawurlencode(&key)
            ),
            &json!({}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get check run {check_run_id}: HTTP {status}"),
                status,
            ));
        }
        Ok(self.parse_check_run(
            &response.body,
            owner,
            repository_name,
            &commit_hash,
            json!({}),
        ))
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
        _annotations: &[Value],
        _images: &[Value],
        _actions: &[Value],
        details_url: &str,
        _external_id: &str,
        _started_at: &str,
        completed_at: &str,
    ) -> Result<Value, VcsError> {
        let (commit_hash, key) = split_check_run_id(check_run_id)?;
        let (status, conclusion, completed_at) =
            settle_check_run(status, conclusion, completed_at)?;
        let current = self.get_check_run(owner, repository_name, check_run_id)?;
        let written = self.write_build_status(
            owner,
            repository_name,
            &commit_hash,
            json!({
                "key": key,
                "name": if php_empty_str(name) { str_field(&current, "name") } else { name.to_string() },
                "state": check_run_state(&status, &conclusion),
                "url": if php_empty_str(details_url) { str_field(&current, "html_url") } else { details_url.to_string() },
                "description": if php_empty_str(summary) {
                    str_field(obj_field(&current, "output"), "summary")
                } else {
                    summary.to_string()
                },
            }),
        )?;
        Ok(self.parse_check_run(
            &written,
            owner,
            repository_name,
            &commit_hash,
            json!({
                "status": if php_empty_str(&status) { str_field(&current, "status") } else { status },
                "conclusion": if conclusion.is_empty() {
                    current.get("conclusion").cloned().unwrap_or(Value::Null)
                } else {
                    json!(conclusion)
                },
                "output": {"title": title, "summary": summary, "text": text},
                "completed_at": if php_empty_str(&completed_at) {
                    current.get("completed_at").cloned().unwrap_or(Value::Null)
                } else {
                    json!(completed_at)
                },
            }),
        ))
    }

    fn write_build_status(
        &self,
        owner: &str,
        repository_name: &str,
        commit_hash: &str,
        payload: Value,
    ) -> Result<Value, VcsError> {
        let filtered = match payload {
            Value::Object(map) => {
                Value::Object(map.into_iter().filter(|(_, v)| v != &json!("")).collect())
            }
            other => other,
        };
        let response = self.call(
            METHOD_POST,
            &format!(
                "/repositories/{owner}/{repository_name}/commit/{}/statuses/build",
                php_rawurlencode(commit_hash)
            ),
            &filtered,
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to write check run: HTTP {status}"),
                status,
            ));
        }
        Ok(response.body_object())
    }

    fn parse_check_run(
        &self,
        status: &Value,
        owner: &str,
        repository_name: &str,
        commit_hash: &str,
        overrides: Value,
    ) -> Value {
        let state = str_field(status, "state");
        let (run_status, conclusion) = match state.as_str() {
            "INPROGRESS" => ("in_progress", Value::Null),
            "SUCCESSFUL" => ("completed", json!("success")),
            "FAILED" => ("completed", json!("failure")),
            "STOPPED" => ("completed", json!("cancelled")),
            _ => ("completed", Value::Null),
        };
        let commit_url = self.get_commit_url(owner, repository_name, commit_hash);
        let mut result = json!({
            "id": format!("{commit_hash}:{}", str_field(status, "key")),
            "name": str_field(status, "name"),
            "status": run_status,
            "conclusion": conclusion,
            "head_sha": commit_hash,
            "url": nonempty(&str_field(obj_field(obj_field(status, "links"), "self"), "href"), &commit_url),
            "html_url": nonempty(&str_field(status, "url"), &commit_url),
            "started_at": str_field(status, "created_on"),
            "completed_at": if conclusion.is_null() {
                Value::Null
            } else {
                json!(str_field(status, "updated_on"))
            },
            "output": {
                "title": "",
                "summary": str_field(status, "description"),
                "text": "",
            },
        });
        if let (Value::Object(base), Value::Object(over)) = (&mut result, overrides) {
            for (k, v) in over {
                base.insert(k, v);
            }
        }
        result
    }

    fn parse_pull_request(&self, pull_request: &Value) -> Value {
        let source = obj_field(pull_request, "source");
        let destination = obj_field(pull_request, "destination");
        json!({
            "number": pull_request.get("id").cloned().unwrap_or(json!(0)),
            "title": str_field(pull_request, "title"),
            "state": str_field(pull_request, "state").to_ascii_lowercase(),
            "head": {
                "ref": str_field(obj_field(source, "branch"), "name"),
                "sha": str_field(obj_field(source, "commit"), "hash"),
            },
            "base": { "ref": str_field(obj_field(destination, "branch"), "name") },
        })
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
        let mut payload = json!({
            "title": title,
            "source": {"branch": {"name": head}},
            "destination": {"branch": {"name": base}},
        });
        if !php_empty_str(body) {
            payload["description"] = json!(body);
        }
        let response = self.call(
            METHOD_POST,
            &format!("/repositories/{owner}/{repository_name}/pullrequests"),
            &payload,
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to create pull request: HTTP {status}"),
                status,
            ));
        }
        let mut body = response.body_object();
        body["number"] = body.get("id").cloned().unwrap_or(json!(0));
        Ok(body)
    }

    pub fn get_pull_request(
        &self,
        owner: &str,
        repository_name: &str,
        pull_request_number: i64,
    ) -> Result<Value, VcsError> {
        let response = self.call(
            METHOD_GET,
            &format!("/repositories/{owner}/{repository_name}/pullrequests/{pull_request_number}"),
            &json!({}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get pull request: HTTP {status}"),
                status,
            ));
        }
        Ok(self.parse_pull_request(&response.body))
    }

    pub fn get_pull_request_from_branch(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
    ) -> Result<Value, VcsError> {
        let query = php_urlencode(&format!("source.branch.name=\"{branch}\""));
        let response = self.call(
            METHOD_GET,
            &format!("/repositories/{owner}/{repository_name}/pullrequests?state=OPEN&q={query}"),
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
            .get("values")
            .and_then(Value::as_array)
            .and_then(|v| v.first())
            .map(|pr| self.parse_pull_request(pr))
            .unwrap_or(json!({})))
    }

    pub fn get_pull_request_files(
        &self,
        owner: &str,
        repository_name: &str,
        pull_request_number: i64,
    ) -> Result<Vec<Value>, VcsError> {
        let mut files = Vec::new();
        let mut page = 1_i64;
        loop {
            let response = self.call(
                METHOD_GET,
                &format!(
                    "/repositories/{owner}/{repository_name}/pullrequests/{pull_request_number}/diffstat?pagelen={PAGE_SIZE}&page={page}"
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
            let values = response
                .body
                .get("values")
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            for diff in &values {
                let new = obj_field(diff, "new");
                let old = obj_field(diff, "old");
                let filename = if str_field(new, "path").is_empty() {
                    str_field(old, "path")
                } else {
                    str_field(new, "path")
                };
                files.push(json!({"filename": filename}));
            }
            if str_field(&response.body, "next").is_empty() {
                break;
            }
            page += 1;
        }
        Ok(files)
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
            &format!(
                "/repositories/{owner}/{repository_name}/pullrequests/{pull_request_number}/comments"
            ),
            &json!({"content": {"raw": comment}}),
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
        let Some((pr, id)) = comment_id.split_once(':') else {
            return Ok(String::new());
        };
        let response = self.call(
            METHOD_GET,
            &format!("/repositories/{owner}/{repository_name}/pullrequests/{pr}/comments/{id}"),
            &json!({}),
        )?;
        Ok(str_field(obj_field(&response.body, "content"), "raw"))
    }

    pub fn update_comment(
        &self,
        owner: &str,
        repository_name: &str,
        comment_id: &str,
        comment: &str,
    ) -> Result<String, VcsError> {
        let Some((pr, id)) = comment_id.split_once(':') else {
            return Err(VcsError::message(format!(
                "Invalid comment ID format: {comment_id}"
            )));
        };
        let response = self.call(
            METHOD_PUT,
            &format!("/repositories/{owner}/{repository_name}/pullrequests/{pr}/comments/{id}"),
            &json!({"content": {"raw": comment}}),
        )?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to update comment: HTTP {status}"),
                status,
            ));
        }
        Ok(comment_id.to_string())
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
        let mut payload = json!({
            "description": "utopia",
            "url": url,
            "active": true,
            "events": map_webhook_events(&events),
        });
        if !php_empty_str(secret) {
            payload["secret"] = json!(secret);
        }
        let response = self.call(
            METHOD_POST,
            &format!("/repositories/{owner}/{repository_name}/hooks"),
            &payload,
        )?;
        let status = response.status_code();
        if status >= 400 {
            let body = serde_json::to_string(&response.body).unwrap_or_default();
            return Err(VcsError::with_status(
                format!("Failed to create webhook: HTTP {status} - {body}"),
                status,
            ));
        }
        let uuid = str_field(&response.body, "uuid");
        if php_empty_str(&uuid) {
            return Err(VcsError::message(
                "Webhook created but response did not include a uuid",
            ));
        }
        Ok(WebhookId::Text(uuid))
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
        if php_empty_str(&str_field(&response.body, "uuid")) {
            return Err(VcsError::message(format!("User not found: {username}")));
        }
        let mut body = response.body_object();
        body["id"] = json!(str_field(&body, "uuid"));
        if php_empty_str(&str_field(&body, "username")) {
            body["username"] = json!(str_field(&body, "nickname"));
        }
        Ok(body)
    }

    fn get_authenticated_user(&self) -> Result<Value, VcsError> {
        let response = self.call(METHOD_GET, "/user", &json!({}))?;
        let status = response.status_code();
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to get current user: HTTP {status}"),
                status,
            ));
        }
        Ok(response.body_object())
    }

    pub fn get_owner_name(
        &self,
        _installation_id: &str,
        _repository_id: Option<i64>,
    ) -> Result<String, VcsError> {
        let response = self.call(METHOD_GET, "/user/workspaces", &json!({}))?;
        if response.status_code() < 400 {
            let values = response
                .body
                .get("values")
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            if let Some(first) = values.first() {
                let slug = str_field(obj_field(first, "workspace"), "slug");
                if !php_empty_str(&slug) {
                    return Ok(slug);
                }
            }
        }
        let user = self.get_authenticated_user()?;
        let username = str_field(&user, "username");
        Ok(if php_empty_str(&username) {
            str_field(&user, "nickname")
        } else {
            username
        })
    }

    fn authenticated_bitbucket_url(&self) -> String {
        if php_empty_str(&self.access_token) {
            return self.bitbucket_url.clone();
        }
        let userinfo = if self.access_token.contains(':') {
            self.access_token.split_once(':').map_or_else(
                || php_urlencode(&self.access_token),
                |(a, b)| format!("{}:{}", php_urlencode(a), php_urlencode(b)),
            )
        } else {
            format!("x-token-auth:{}", php_urlencode(&self.access_token))
        };
        self.bitbucket_url
            .replace("://", &format!("://{userinfo}@"))
    }

    #[must_use]
    pub fn get_repository_presigned_url_headers(&self) -> HashMap<String, String> {
        HashMap::from([("Authorization".into(), self.authorization_header())])
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
        let ref_name = if php_empty_str(ref_name) {
            "HEAD"
        } else {
            ref_name
        };
        let encoded = php_rawurlencode(&self.resolve_ref(owner, repository_name, ref_name)?);
        Ok(format!(
            "{}/{owner}/{repository_name}/get/{encoded}.{extension}",
            self.bitbucket_url
        ))
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
        let clone_url = escape_shell_arg(&format!(
            "{}/{owner}/{repository_name}.git",
            self.authenticated_bitbucket_url()
        ));
        sparse_clone_commands(&clone_url, directory, root_directory, version, version_type)
    }

    pub fn get_events(&self, event: &str, payload: &str) -> Result<Vec<Value>, VcsError> {
        bitbucket_get_events(event, payload)
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

    #[must_use]
    pub fn get_event_header_name(&self) -> &'static str {
        "x-event-key"
    }

    #[must_use]
    pub fn get_signature_header_name(&self) -> &'static str {
        "x-hub-signature"
    }

    #[must_use]
    pub fn get_supported_webhook_scopes(&self) -> &'static [&'static str] {
        &[WEBHOOK_SCOPE_REPOSITORY]
    }

    #[must_use]
    pub fn get_repository_url(&self, owner: &str, repository_name: &str) -> String {
        format!("{}/{owner}/{repository_name}", self.bitbucket_url)
    }

    #[must_use]
    pub fn get_branch_url(&self, owner: &str, repository_name: &str, branch: &str) -> String {
        format!(
            "{}/branch/{branch}",
            self.get_repository_url(owner, repository_name)
        )
    }

    #[must_use]
    pub fn get_commit_url(&self, owner: &str, repository_name: &str, commit_hash: &str) -> String {
        format!(
            "{}/commits/{commit_hash}",
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

fn php_rawurldecode(input: &str) -> Vec<u8> {
    let mut out = Vec::new();
    let bytes = input.as_bytes();
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'%' && i + 2 < bytes.len() {
            if let Ok(v) =
                u8::from_str_radix(std::str::from_utf8(&bytes[i + 1..i + 3]).unwrap_or(""), 16)
            {
                out.push(v);
                i += 3;
                continue;
            }
        }
        out.push(bytes[i]);
        i += 1;
    }
    out
}

fn nonempty(value: &str, fallback: &str) -> String {
    if value.is_empty() {
        fallback.to_string()
    } else {
        value.to_string()
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

fn check_run_state(status: &str, conclusion: &str) -> &'static str {
    let _ = status;
    if php_empty_str(conclusion) {
        return "INPROGRESS";
    }
    CHECK_RUN_CONCLUSION_MAP
        .iter()
        .find(|(k, _)| *k == conclusion)
        .map_or("FAILED", |(_, v)| *v)
}

fn split_check_run_id(check_run_id: &str) -> Result<(String, String), VcsError> {
    let Some((commit, key)) = check_run_id.split_once(':') else {
        return Err(VcsError::with_status(
            format!("Check run {check_run_id} was not found"),
            404,
        ));
    };
    if commit.is_empty() || key.is_empty() {
        return Err(VcsError::with_status(
            format!("Check run {check_run_id} was not found"),
            404,
        ));
    }
    Ok((commit.to_string(), key.to_string()))
}

fn map_webhook_events(events: &[&str]) -> Vec<String> {
    let mut keys = Vec::new();
    for event in events {
        if event.contains(':') {
            keys.push((*event).to_string());
            continue;
        }
        match *event {
            "push" => keys.push("repo:push".into()),
            "pull_request" => {
                keys.extend(
                    PULL_REQUEST_ACTION_MAP
                        .iter()
                        .map(|(k, _)| (*k).to_string()),
                );
            }
            _ => {}
        }
    }
    keys.sort();
    keys.dedup();
    keys
}

/// Parsed webhook events (PHP `Bitbucket::getEvents`).
pub fn bitbucket_get_events(event: &str, payload: &str) -> Result<Vec<Value>, VcsError> {
    let payload: Value = serde_json::from_str(payload)
        .ok()
        .filter(Value::is_object)
        .ok_or_else(|| VcsError::message("Invalid payload."))?;
    let repository = obj_field(&payload, "repository").clone();
    let actor = obj_field(&payload, "actor").clone();
    match event {
        "repo:push" => {
            let changes = payload
                .pointer("/push/changes")
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            Ok(changes
                .into_iter()
                .filter(is_branch_change)
                .map(|change| parse_push_change(&change, &repository, &actor))
                .collect())
        }
        "pullrequest:created"
        | "pullrequest:updated"
        | "pullrequest:fulfilled"
        | "pullrequest:rejected" => Ok(vec![parse_pr_event(event, &payload, &repository, &actor)]),
        _ => Ok(Vec::new()),
    }
}

fn is_branch_change(change: &Value) -> bool {
    let kind = {
        let new_type = str_field(obj_field(change, "new"), "type");
        if new_type.is_empty() {
            let old_type = str_field(obj_field(change, "old"), "type");
            if old_type.is_empty() {
                "branch".into()
            } else {
                old_type
            }
        } else {
            new_type
        }
    };
    kind == "branch" || kind == "named_branch"
}

fn author_name_of(author: &Value) -> String {
    let user = obj_field(author, "user");
    let name = str_field(user, "display_name");
    if !php_empty_str(&name) {
        return name;
    }
    let raw = str_field(author, "raw");
    regex::Regex::new("<[^>]*>").ok().map_or_else(
        || raw.trim().to_string(),
        |re| re.replace_all(&raw, "").trim().to_string(),
    )
}

fn parse_push_change(change: &Value, repository: &Value, actor: &Value) -> Value {
    let actor_links = obj_field(actor, "links");
    let owner = {
        let slug = str_field(obj_field(repository, "workspace"), "slug");
        if php_empty_str(&slug) {
            str_field(repository, "full_name")
                .split_once('/')
                .map(|(w, _)| w.to_string())
                .unwrap_or_default()
        } else {
            slug
        }
    };
    let repository_url = str_field(obj_field(obj_field(repository, "links"), "html"), "href");
    let new = obj_field(change, "new");
    let old = obj_field(change, "old");
    let branch = if str_field(new, "name").is_empty() {
        str_field(old, "name")
    } else {
        str_field(new, "name")
    };
    let target = obj_field(new, "target");
    let author = obj_field(target, "author");
    let raw = str_field(author, "raw");
    let author_name = author_name_of(author);
    let author_email = regex::Regex::new(r"<([^>]*)>")
        .ok()
        .and_then(|re| {
            re.captures(&raw)
                .and_then(|c| c.get(1).map(|m| m.as_str().to_string()))
        })
        .unwrap_or_default();
    json!({
        "branchCreated": change.get("created").and_then(Value::as_bool).unwrap_or(false),
        "branchDeleted": change.get("closed").and_then(Value::as_bool).unwrap_or(false),
        "branch": branch,
        "branchUrl": if !repository_url.is_empty() && !branch.is_empty() {
            format!("{repository_url}/branch/{branch}")
        } else {
            String::new()
        },
        "repositoryId": str_field(repository, "full_name"),
        "repositoryName": str_field(repository, "name"),
        "repositoryUrl": repository_url,
        "installationId": "",
        "commitHash": str_field(target, "hash"),
        "owner": owner,
        "authorUrl": str_field(obj_field(actor_links, "html"), "href"),
        "authorAvatarUrl": str_field(obj_field(actor_links, "avatar"), "href"),
        "headCommitAuthorName": author_name,
        "headCommitAuthorEmail": author_email,
        "headCommitMessage": str_field(target, "message"),
        "headCommitUrl": str_field(obj_field(obj_field(target, "links"), "html"), "href"),
        "external": false,
        "pullRequestNumber": "",
        "action": "",
        "affectedFiles": [],
    })
}

fn parse_pr_event(event: &str, payload: &Value, repository: &Value, actor: &Value) -> Value {
    let actor_links = obj_field(actor, "links");
    let owner = {
        let slug = str_field(obj_field(repository, "workspace"), "slug");
        if php_empty_str(&slug) {
            str_field(repository, "full_name")
                .split_once('/')
                .map(|(w, _)| w.to_string())
                .unwrap_or_default()
        } else {
            slug
        }
    };
    let repository_url = str_field(obj_field(obj_field(repository, "links"), "html"), "href");
    let pull_request = obj_field(payload, "pullrequest");
    let source = obj_field(pull_request, "source");
    let destination = obj_field(pull_request, "destination");
    let branch = str_field(obj_field(source, "branch"), "name");
    let commit_hash = str_field(obj_field(source, "commit"), "hash");
    let source_id = source.get("repository").and_then(|r| r.get("uuid"));
    let dest_id = destination.get("repository").and_then(|r| r.get("uuid"));
    let external = source_id.is_some() && dest_id.is_some() && source_id != dest_id;
    let action = PULL_REQUEST_ACTION_MAP
        .iter()
        .find(|(k, _)| *k == event)
        .map_or("", |(_, v)| *v);
    json!({
        "branch": branch,
        "branchUrl": if !repository_url.is_empty() && !branch.is_empty() {
            format!("{repository_url}/branch/{branch}")
        } else {
            String::new()
        },
        "repositoryId": str_field(repository, "full_name"),
        "repositoryName": str_field(repository, "name"),
        "repositoryUrl": repository_url,
        "installationId": "",
        "commitHash": commit_hash,
        "owner": owner,
        "authorUrl": str_field(obj_field(actor_links, "html"), "href"),
        "authorAvatarUrl": str_field(obj_field(actor_links, "avatar"), "href"),
        "headCommitUrl": if !repository_url.is_empty() && !commit_hash.is_empty() {
            format!("{repository_url}/commits/{commit_hash}")
        } else {
            String::new()
        },
        "external": external,
        "pullRequestNumber": field_or_null(pull_request, "id").clone(),
        "action": action,
    })
}
