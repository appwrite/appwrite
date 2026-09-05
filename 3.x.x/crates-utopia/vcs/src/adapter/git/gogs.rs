//! Gogs adapter (PHP `Utopia\VCS\Adapter\Git\Gogs`).

use std::fs;
use std::process::Command;

use serde_json::{json, Value};

use crate::adapter::git::gitea::{gitea_get_events, Gitea, Identity};
use crate::adapter::WebhookId;
use crate::cache::CacheStore;
use crate::error::{RepositoryNotFound, VcsError};
use crate::http::{METHOD_GET, METHOD_POST, METHOD_PUT};
use crate::php::{escape_shell_arg, match_glob, php_empty_str, str_field, validate_hmac_sha256};
use crate::TYPE_GIT;

#[derive(Debug)]
pub struct Gogs {
    inner: Gitea,
}

#[allow(clippy::many_single_char_names)]
impl Gogs {
    pub fn new(cache: impl CacheStore + 'static) -> Self {
        Self {
            inner: Gitea::new_with(cache, Identity::GOGS),
        }
    }

    pub fn set_endpoint(&mut self, endpoint: impl Into<String>) {
        self.inner.set_endpoint(endpoint);
    }

    #[must_use]
    pub fn get_name(&self) -> &'static str {
        "gogs"
    }

    #[must_use]
    pub fn get_type(&self) -> &'static str {
        TYPE_GIT
    }

    pub fn initialize_variables(
        &mut self,
        installation_id: &str,
        private_key: &str,
        app_id: Option<&str>,
        access_token: Option<&str>,
        refresh_token: Option<&str>,
    ) -> Result<(), VcsError> {
        self.inner.initialize_variables(
            installation_id,
            private_key,
            app_id,
            access_token,
            refresh_token,
        )
    }

    pub fn create_repository(
        &self,
        owner: &str,
        repository_name: &str,
        private: bool,
    ) -> Result<Value, VcsError> {
        let response = self.inner.call(
            METHOD_POST,
            &format!("/org/{owner}/repos"),
            &json!({
                "name": repository_name,
                "private": private,
                "auto_init": true,
                "readme": "Default",
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
        if result.is_object() && php_empty_str(&str_field(&result, "pushed_at")) {
            result["pushed_at"] = json!(str_field(&result, "updated_at"));
        }
        Ok(result)
    }

    pub fn create_organization(&self, org_name: &str) -> Result<String, VcsError> {
        let response =
            self.inner
                .call(METHOD_POST, "/user/orgs", &json!({"username": org_name}))?;
        Ok(str_field(&response.body, "username"))
    }

    pub fn search_repositories(
        &self,
        owner: &str,
        page: i64,
        per_page: i64,
        search: &str,
    ) -> Result<Value, VcsError> {
        if !php_empty_str(search) {
            return self
                .inner
                .search_repositories(owner, page, per_page, search);
        }
        let response = self
            .inner
            .call(METHOD_GET, &format!("/orgs/{owner}/repos"), &json!({}))?;
        let mut body = response.body.as_array().cloned().unwrap_or_default();
        let total = body.len();
        let offset = ((page - 1) * per_page).max(0) as usize;
        let mut paged: Vec<Value> = body
            .drain(..)
            .skip(offset)
            .take(per_page.max(0) as usize)
            .collect();
        for repo in &mut paged {
            if repo.is_object() && php_empty_str(&str_field(repo, "pushed_at")) {
                repo["pushed_at"] = json!(str_field(repo, "updated_at"));
            }
        }
        Ok(json!({"items": paged, "total": total}))
    }

    pub fn get_repository_tree(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
        recursive: bool,
    ) -> Result<Vec<String>, VcsError> {
        let response = self.inner.call(
            METHOD_GET,
            &format!(
                "/repos/{owner}/{repository_name}/git/trees/{}",
                crate::php::php_urlencode(branch)
            ),
            &json!({}),
        )?;
        if response.status_code() == 404 {
            return Ok(Vec::new());
        }
        let entries = response
            .body
            .get("tree")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default();
        let mut paths = Vec::new();
        for entry in entries {
            let path = str_field(&entry, "path");
            paths.push(path.clone());
            if recursive && str_field(&entry, "type") == "tree" {
                let sha = str_field(&entry, "sha");
                for sub in self.get_repository_tree(owner, repository_name, &sha, true)? {
                    paths.push(format!("{path}/{sub}"));
                }
            }
        }
        Ok(paths)
    }

    pub fn get_repository_name(&self, repository_id: &str) -> Result<String, VcsError> {
        let id: i64 = repository_id.parse().unwrap_or(0);
        Ok(str_field(&self.find_repository_by_id(id)?, "name"))
    }

    pub fn get_owner_name(
        &self,
        _installation_id: &str,
        repository_id: Option<i64>,
    ) -> Result<String, VcsError> {
        if repository_id.is_none() || repository_id.map_or(true, |id| id <= 0) {
            return self.inner.get_authenticated_user_login();
        }
        let repo = self.find_repository_by_id(repository_id.unwrap_or(0))?;
        let login = str_field(crate::php::obj_field(&repo, "owner"), "login");
        if php_empty_str(&login) {
            return Err(VcsError::message(
                "Owner login missing or empty in response",
            ));
        }
        Ok(login)
    }

    fn find_repository_by_id(&self, repository_id: i64) -> Result<Value, VcsError> {
        let mut page = 1_i64;
        while page <= 100 {
            let response = self.inner.call(
                METHOD_GET,
                &format!("/repos/search?q=_&limit=50&page={page}"),
                &json!({}),
            )?;
            let repos = response
                .body
                .get("data")
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            if repos.is_empty() {
                break;
            }
            let count = repos.len();
            for repo in &repos {
                if repo.get("id").and_then(Value::as_i64) == Some(repository_id) {
                    return Ok(repo.clone());
                }
            }
            if count < 50 {
                break;
            }
            page += 1;
        }
        Err(RepositoryNotFound::new("Repository not found").into())
    }

    pub fn get_commit(
        &self,
        owner: &str,
        repository_name: &str,
        commit_hash: &str,
    ) -> Result<Value, VcsError> {
        let response = self.inner.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/commits/{commit_hash}"),
            &json!({}),
        )?;
        if response.status_code() >= 400 {
            return Err(VcsError::message("Commit not found or inaccessible"));
        }
        let commit = crate::php::obj_field(&response.body, "commit");
        let commit_author = crate::php::obj_field(commit, "author");
        let author = crate::php::obj_field(&response.body, "author");
        Ok(json!({
            "commitAuthor": if str_field(commit_author, "name").is_empty() {
                "Unknown".into()
            } else {
                str_field(commit_author, "name")
            },
            "commitMessage": if str_field(commit, "message").is_empty() {
                "No message".into()
            } else {
                str_field(commit, "message")
            },
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
        let branches = self.list_branches(owner, repository_name)?;
        if !branches.iter().any(|b| b == branch) {
            return Err(VcsError::message(format!("Branch '{branch}' not found")));
        }
        self.inner.get_latest_commit(owner, repository_name, branch)
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
        if !php_empty_str(branch) {
            let response = self.inner.call(
                METHOD_GET,
                &format!("/repos/{owner}/{repository_name}"),
                &json!({}),
            )?;
            let default_branch = {
                let d = str_field(&response.body, "default_branch");
                if d.is_empty() {
                    "master".into()
                } else {
                    d
                }
            };
            if branch != default_branch {
                return self.create_file_via_cli(
                    owner,
                    repository_name,
                    filepath,
                    content,
                    message,
                    branch,
                );
            }
        }
        let response = self.inner.call(
            METHOD_PUT,
            &format!("/repos/{owner}/{repository_name}/contents/{filepath}"),
            &json!({
                "content": base64::Engine::encode(&base64::engine::general_purpose::STANDARD, content.as_bytes()),
                "message": message,
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

    fn create_file_via_cli(
        &self,
        owner: &str,
        repository_name: &str,
        filepath: &str,
        content: &str,
        message: &str,
        branch: &str,
    ) -> Result<Value, VcsError> {
        let dir = self.git_clone(owner, repository_name, branch)?;
        let result = (|| {
            let full = dir.join(filepath);
            if let Some(parent) = full.parent() {
                fs::create_dir_all(parent).map_err(|e| VcsError::message(e.to_string()))?;
            }
            fs::write(&full, content).map_err(|e| VcsError::message(e.to_string()))?;
            exec(&format!(
                "git -C {} add {}",
                dir.display(),
                escape_shell_arg(filepath)
            ))?;
            exec(&format!(
                "git -C {} commit -m {}",
                dir.display(),
                escape_shell_arg(message)
            ))?;
            exec(&format!(
                "git -C {} push origin {}",
                dir.display(),
                escape_shell_arg(branch)
            ))?;
            Ok(json!({"content": {"path": filepath}}))
        })();
        let _ = exec(&format!("rm -rf {}", dir.display()));
        result
    }

    pub fn create_branch(
        &self,
        owner: &str,
        repository_name: &str,
        new_branch_name: &str,
        old_branch_name: &str,
    ) -> Result<Value, VcsError> {
        let dir = self.git_clone(owner, repository_name, old_branch_name)?;
        let result = (|| {
            exec(&format!(
                "git -C {} checkout -b {}",
                dir.display(),
                escape_shell_arg(new_branch_name)
            ))?;
            exec(&format!(
                "git -C {} push origin {}",
                dir.display(),
                escape_shell_arg(new_branch_name)
            ))?;
            Ok(json!({"name": new_branch_name}))
        })();
        let _ = exec(&format!("rm -rf {}", dir.display()));
        result
    }

    fn git_clone(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
    ) -> Result<std::path::PathBuf, VcsError> {
        let clone_url = format!(
            "{}/{owner}/{repository_name}.git",
            self.inner
                .gitea_url
                .replace("://", &format!("://{owner}:{}@", self.inner.access_token))
        );
        let dir = std::env::temp_dir().join(format!("gogs-{}", uniqid()));
        let dir_arg = escape_shell_arg(&dir.to_string_lossy());
        let branch_arg = if php_empty_str(branch) {
            String::new()
        } else {
            format!(" -b {}", escape_shell_arg(branch))
        };
        exec(&format!(
            "git clone --depth=1{branch_arg} {} {dir_arg}",
            escape_shell_arg(&clone_url)
        ))?;
        exec(&format!(
            "git -C {dir_arg} config user.email 'gogs@test.local'"
        ))?;
        exec(&format!("git -C {dir_arg} config user.name 'Gogs Test'"))?;
        Ok(dir)
    }

    pub fn list_repository_languages(
        &self,
        _owner: &str,
        _repository_name: &str,
    ) -> Result<Vec<String>, VcsError> {
        Err(VcsError::message(
            "Listing repository languages is not supported by Gogs",
        ))
    }

    pub fn create_tag(
        &self,
        owner: &str,
        repository_name: &str,
        tag_name: &str,
        target: &str,
        message: &str,
    ) -> Result<Value, VcsError> {
        let dir = self.git_clone(owner, repository_name, "")?;
        let result = (|| {
            exec(&format!(
                "git -C {} fetch origin {}",
                dir.display(),
                escape_shell_arg(target)
            ))?;
            if php_empty_str(message) {
                exec(&format!(
                    "git -C {} tag {} {}",
                    dir.display(),
                    escape_shell_arg(tag_name),
                    escape_shell_arg(target)
                ))?;
            } else {
                exec(&format!(
                    "git -C {} tag -a {} {} -m {}",
                    dir.display(),
                    escape_shell_arg(tag_name),
                    escape_shell_arg(target),
                    escape_shell_arg(message)
                ))?;
            }
            exec(&format!(
                "git -C {} push origin {}",
                dir.display(),
                escape_shell_arg(tag_name)
            ))?;
            Ok(json!({"name": tag_name, "commit": {"sha": target}}))
        })();
        let _ = exec(&format!("rm -rf {}", dir.display()));
        result
    }

    pub fn create_pull_request(
        &self,
        _o: &str,
        _r: &str,
        _t: &str,
        _h: &str,
        _b: &str,
        _body: &str,
    ) -> Result<Value, VcsError> {
        Err(VcsError::message(
            "Pull request API is not supported by Gogs",
        ))
    }

    pub fn get_pull_request(&self, _o: &str, _r: &str, _n: i64) -> Result<Value, VcsError> {
        Err(VcsError::message(
            "Pull request API is not supported by Gogs",
        ))
    }

    pub fn get_pull_request_from_branch(
        &self,
        _o: &str,
        _r: &str,
        _b: &str,
    ) -> Result<Value, VcsError> {
        Err(VcsError::message(
            "Pull request API is not supported by Gogs",
        ))
    }

    pub fn get_pull_request_files(
        &self,
        _o: &str,
        _r: &str,
        _n: i64,
    ) -> Result<Vec<Value>, VcsError> {
        Err(VcsError::message(
            "Pull request API is not supported by Gogs",
        ))
    }

    pub fn update_commit_status(
        &self,
        _r: &str,
        _c: &str,
        _o: &str,
        _s: &str,
        _d: &str,
        _t: &str,
        _x: &str,
    ) -> Result<(), VcsError> {
        Err(VcsError::message(
            "Commit status API is not supported by Gogs",
        ))
    }

    pub fn get_commit_statuses(
        &self,
        _o: &str,
        _r: &str,
        _c: &str,
    ) -> Result<Vec<Value>, VcsError> {
        Err(VcsError::message(
            "Commit status API is not supported by Gogs",
        ))
    }

    pub fn list_branches(
        &self,
        owner: &str,
        repository_name: &str,
    ) -> Result<Vec<String>, VcsError> {
        let response = self.inner.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/branches"),
            &json!({}),
        )?;
        let status = response.status_code();
        if status == 404 {
            return Ok(Vec::new());
        }
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to list branches: HTTP {status}"),
                status,
            ));
        }
        Ok(response
            .body
            .as_array()
            .cloned()
            .unwrap_or_default()
            .iter()
            .filter(|b| b.get("name").is_some())
            .map(|b| str_field(b, "name"))
            .collect())
    }

    pub fn list_tags(
        &self,
        owner: &str,
        repository_name: &str,
        search: &str,
    ) -> Result<Vec<String>, VcsError> {
        let response = self.inner.call(
            METHOD_GET,
            &format!("/repos/{owner}/{repository_name}/tags"),
            &json!({}),
        )?;
        let status = response.status_code();
        if status == 404 {
            return Ok(Vec::new());
        }
        if status >= 400 {
            return Err(VcsError::with_status(
                format!("Failed to list tags: HTTP {status}"),
                status,
            ));
        }
        let tags = response
            .body
            .as_array()
            .cloned()
            .unwrap_or_default()
            .iter()
            .filter(|t| t.get("name").is_some())
            .map(|t| str_field(t, "name"))
            .collect();
        Ok(match_glob(tags, search))
    }

    // Forwarded Gitea surface -------------------------------------------------

    pub fn has_access_to_all_repositories(&self) -> Result<bool, VcsError> {
        self.inner.has_access_to_all_repositories()
    }
    pub fn get_installation_repository(&self, n: &str) -> Result<Value, VcsError> {
        self.inner.get_installation_repository(n)
    }
    pub fn get_repository(&self, o: &str, r: &str) -> Result<Value, VcsError> {
        self.inner.get_repository(o, r)
    }
    pub fn get_repository_presigned_url(
        &self,
        o: &str,
        r: &str,
        f: &str,
        fmt: &str,
    ) -> Result<String, VcsError> {
        self.inner.get_repository_presigned_url(o, r, f, fmt)
    }
    pub fn get_repository_presigned_url_headers(
        &self,
    ) -> std::collections::HashMap<String, String> {
        self.inner.get_repository_presigned_url_headers()
    }
    pub fn get_repository_content(
        &self,
        o: &str,
        r: &str,
        p: &str,
        f: &str,
    ) -> Result<Value, VcsError> {
        self.inner.get_repository_content(o, r, p, f)
    }
    pub fn list_repository_contents(
        &self,
        o: &str,
        r: &str,
        p: &str,
        f: &str,
    ) -> Result<Vec<Value>, VcsError> {
        self.inner.list_repository_contents(o, r, p, f)
    }
    pub fn delete_repository(&self, o: &str, r: &str) -> Result<bool, VcsError> {
        self.inner.delete_repository(o, r)
    }
    pub fn create_webhook(
        &self,
        o: &str,
        r: &str,
        u: &str,
        s: &str,
        e: &[&str],
    ) -> Result<WebhookId, VcsError> {
        self.inner.create_webhook(o, r, u, s, e)
    }
    pub fn create_comment(&self, o: &str, r: &str, n: i64, c: &str) -> Result<String, VcsError> {
        self.inner.create_comment(o, r, n, c)
    }
    pub fn get_comment(&self, o: &str, r: &str, c: &str) -> Result<String, VcsError> {
        self.inner.get_comment(o, r, c)
    }
    pub fn update_comment(&self, o: &str, r: &str, c: &str, b: &str) -> Result<String, VcsError> {
        self.inner.update_comment(o, r, c, b)
    }
    pub fn get_user(&self, u: &str) -> Result<Value, VcsError> {
        self.inner.get_user(u)
    }
    pub fn generate_clone_command(
        &self,
        o: &str,
        r: &str,
        v: &str,
        t: &str,
        d: &str,
        root: &str,
    ) -> Result<String, VcsError> {
        self.inner.generate_clone_command(o, r, v, t, d, root)
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
    #[must_use]
    pub fn get_event_header_name(&self) -> &'static str {
        "x-gogs-event"
    }
    #[must_use]
    pub fn get_signature_header_name(&self) -> &'static str {
        "x-gogs-signature"
    }
    #[must_use]
    pub fn get_supported_webhook_scopes(&self) -> &'static [&'static str] {
        self.inner.get_supported_webhook_scopes()
    }
    #[must_use]
    pub fn get_repository_url(&self, o: &str, r: &str) -> String {
        self.inner.get_repository_url(o, r)
    }
    #[must_use]
    pub fn get_branch_url(&self, o: &str, r: &str, b: &str) -> String {
        format!("{}/src/{b}", self.get_repository_url(o, r))
    }
    #[must_use]
    pub fn get_commit_url(&self, o: &str, r: &str, c: &str) -> String {
        self.inner.get_commit_url(o, r, c)
    }
    #[must_use]
    pub fn get_file_url(&self, o: &str, r: &str, f: &str) -> String {
        self.inner.get_file_url(o, r, f)
    }

    crate::adapter::git::gitea::unsupported_checks_and_namespaces!();
}

fn exec(command: &str) -> Result<String, VcsError> {
    let output = Command::new("sh")
        .arg("-c")
        .arg(format!("{command} 2>&1"))
        .output()
        .map_err(|e| VcsError::message(e.to_string()))?;
    let text = String::from_utf8_lossy(&output.stdout).to_string();
    if !output.status.success() {
        return Err(VcsError::message(format!(
            "Command failed (exit {}): {command}\n{text}",
            output.status.code().unwrap_or(1)
        )));
    }
    Ok(text)
}

fn uniqid() -> String {
    use std::time::{SystemTime, UNIX_EPOCH};
    let now = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default();
    format!("{:x}{:x}", now.as_secs(), now.subsec_nanos())
}
