//! Origin, Cursor's Git hosting, through an app installation.

use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use php_std::{Array, ArrayKey, Str, Value};
use utopia_cache::Cache;
use utopia_console::Command;

use crate::adapter::{Adapter, CheckRun, Git, VcsType, WebhookId, WebhookScope};
use crate::error::{Error, Result};
use crate::git::{self, clone_command, normalize_repository_path};
use crate::github::settle;
use crate::php::{self, at, each, empty, object, or, path, s};
use crate::transport::{Call, Method, Response, Transport};
use crate::{jwt, time};

/// App JWT lifetime in seconds: about five minutes, as Origin prescribes.
///
/// PHP: `Origin::APP_JWT_EXPIRY`.
pub const APP_JWT_EXPIRY: i64 = 60 * 5;

/// The webhook event type of a push.
///
/// PHP: `Origin::EVENT_PUSH`.
pub const EVENT_PUSH: &str = "repository.pushed";

/// Origin's pull request lifecycle events as the GitHub-style actions
/// consumers switch on (a merge closes the pull request).
const PULL_REQUEST_ACTIONS: [(&str, &str); 8] = [
    ("pull_request.created", "opened"),
    ("pull_request.head_ref.pushed", "synchronize"),
    ("pull_request.base_ref.updated", "edited"),
    ("pull_request.metadata.updated", "edited"),
    ("pull_request.closed", "closed"),
    ("pull_request.merged", "closed"),
    ("pull_request.reopened", "reopened"),
    ("pull_request.published", "ready_for_review"),
];

/// One run of [`Origin::batch_upsert_check_runs`]: a [`CheckRun`] (its
/// annotations, images and actions unused) and an optional key of its own.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct BatchCheckRun {
    /// The run.
    pub run: CheckRun,
    /// The run's key; empty: `<name>/<external id>`.
    pub key: String,
}

/// The Origin adapter: an Ed25519-signed (EdDSA) app JWT is exchanged for a
/// short-lived installation token, both cached under the installation id.
/// Content writes (files, branches, tags) go over Git HTTPS with the `git`
/// binary.
///
/// PHP: `Utopia\VCS\Adapter\Git\Origin`.
pub struct Origin {
    cache: Arc<Cache>,
    transport: Transport,
    web_endpoint: String,
    git_endpoint: String,
    access_token: String,
    jwt_token: String,
    installation_id: String,
    jwks: Mutex<Option<Vec<Value>>>,
}

impl Origin {
    /// An adapter on `https://api.cursor.com/v1/origin`.
    pub fn new(cache: Arc<Cache>) -> Self {
        Origin {
            cache,
            transport: Transport::new("https://api.cursor.com/v1/origin"),
            web_endpoint: "https://cursor.com/codebase".into(),
            git_endpoint: "https://origin.cursor.com".into(),
            access_token: String::new(),
            jwt_token: String::new(),
            installation_id: String::new(),
            jwks: Mutex::new(None),
        }
    }

    /// The same adapter on another API endpoint (also the issuer receipts
    /// must name). PHP sets the protected `$endpoint` in a subclass.
    pub fn with_endpoint(mut self, endpoint: impl Into<String>) -> Self {
        self.transport.endpoint = endpoint.into();
        self
    }

    /// Another browser-facing host. PHP: the protected `$webEndpoint`.
    pub fn with_web_endpoint(mut self, endpoint: impl Into<String>) -> Self {
        self.web_endpoint = endpoint.into();
        self
    }

    /// Another Git HTTPS host (an `https://` URL). PHP: the protected
    /// `$gitEndpoint`.
    pub fn with_git_endpoint(mut self, endpoint: impl Into<String>) -> Self {
        self.git_endpoint = endpoint.into();
        self
    }

    fn bearer(&self) -> String {
        format!("Bearer {}", self.access_token)
    }

    fn app_bearer(&self) -> String {
        format!("Bearer {}", self.jwt_token)
    }

    async fn get(&self, path: impl Into<String>, params: Option<Value>) -> Result<Response> {
        let mut call = Call::get(path).auth(self.bearer());
        if let Some(params) = params {
            call = call.params(params);
        }
        self.transport.call(call).await
    }

    /// `/repos/<owner>/<repository>`, each segment encoded.
    fn repository_path(owner: &str, repository_name: &str) -> String {
        format!("/repos/{}/{}", raw(owner), raw(repository_name))
    }

    /// Signs the EdDSA app JWT and exchanges it for an installation token.
    async fn generate_access_token(&mut self, private_key: &str, app_id: Option<&str>) -> Result<()> {
        let app_id = match app_id {
            Some(id) if php::filled(id) => id,
            _ => return Err(Error::exception("Origin requires the app id to sign the app JWT.", 0)),
        };
        self.jwt_token = self.app_jwt(private_key, app_id)?;
        let response = self
            .transport
            .call(
                Call::post(format!("/app/installations/{}/access_tokens", self.installation_id))
                    .auth(self.app_bearer())
                    .params(object([
                        ("scopes", Value::Array(Array::new())),
                        ("repositoryIds", Value::Array(Array::new())),
                    ])),
            )
            .await?;
        let has_token = matches!(&response.body, Value::Array(a) if a.contains_key(&ArrayKey::from("token")));
        if !has_token {
            let safe = match &response.body {
                Value::Array(fields) => {
                    let kept: Array = fields
                        .iter()
                        .filter(|(k, _)| matches!(k, php_std::types::KeyRef::Str(b"code" | b"message")))
                        .map(|(k, v)| (k.to_owned(), v.clone()))
                        .collect();
                    php::json_encode(&Value::Array(kept)).unwrap_or_default()
                }
                _ => String::new(),
            };
            return Err(Error::exception(
                format!(
                    "Failed to retrieve access token from Origin API. Status: {}. Response: {safe}",
                    response.status
                ),
                response.status,
            ));
        }
        self.access_token = php::text(&php::string(&or(response.at("token"), "")));
        Ok(())
    }

    /// The app JWT: EdDSA, `kid` and `iss` the app id, audience
    /// `origin-apps`.
    fn app_jwt(&self, private_key: &str, app_id: &str) -> Result<String> {
        let secret = ed25519_secret_key(private_key)?;
        let iat = time::now();
        let header = object([("alg", s("EdDSA")), ("kid", s(app_id)), ("typ", s("JWT"))]);
        let claims = object([
            ("iss", s(app_id)),
            ("aud", s("origin-apps")),
            ("iat", Value::Int(iat)),
            ("exp", Value::Int(iat + APP_JWT_EXPIRY)),
        ]);
        let input = format!(
            "{}.{}",
            jwt::base64url(php::json_encode(&header).unwrap_or_default().as_bytes()),
            jwt::base64url(php::json_encode(&claims).unwrap_or_default().as_bytes())
        );
        let signature = jwt::ed25519_sign(&secret, input.as_bytes()).ok_or_else(|| {
            Error::exception("The Origin private key does not hold a consistent Ed25519 key pair.", 0)
        })?;
        Ok(format!("{input}.{}", jwt::base64url(&signature)))
    }

    /// The URL of the app's installation page on Cursor, where a user
    /// approves an installation; Cursor answers on `redirect_uri` with a
    /// receipt ([`Origin::verify_receipt`]). No scopes reads the app's
    /// registered ones.
    ///
    /// PHP: `getInstallUrl()`.
    pub fn install_url(&self, app_id: &str, scopes: &[&str], redirect_uri: &str, state: &str) -> String {
        let mut params = serde_json::Map::new();
        params.insert("client_id".into(), app_id.into());
        if scopes.is_empty() {
            params.insert("source".into(), "app-metadata".into());
        } else {
            params.insert("scope".into(), scopes.join(" ").into());
        }
        if php::filled(redirect_uri) {
            params.insert("redirect_uri".into(), redirect_uri.into());
        }
        if php::filled(state) {
            params.insert("state".into(), state.into());
        }
        let query = php_std::encoding::http_build_query(
            &serde_json::Value::Object(params),
            b"",
            None,
            php_std::encoding::QueryEncoding::Rfc1738,
        );
        format!("{}/apps/install?{}", self.web_endpoint, String::from_utf8_lossy(&query))
    }

    /// Verifies an installation receipt against Origin's signing keys and
    /// returns its claims (`sub` the installation id, `state` the install
    /// URL's state, `namespace_id` the workspace).
    ///
    /// PHP: `verifyReceipt()`.
    pub async fn verify_receipt(&self, receipt: &str, app_id: &str) -> Result<Value> {
        let segments: Vec<&str> = receipt.split('.').collect();
        let [header_segment, claims_segment, signature_segment] = segments.as_slice() else {
            return Err(Error::exception("Installation receipt is not a JWT", 0));
        };
        let header = php::json_decode(&jwt::base64url_decode(header_segment.as_bytes())).unwrap_or(Value::Null);
        let claims = php::json_decode(&jwt::base64url_decode(claims_segment.as_bytes())).unwrap_or(Value::Null);
        let signature = jwt::base64url_decode(signature_segment.as_bytes());
        if !php::is_array(&header) || !php::is_array(&claims) {
            return Err(Error::exception("Installation receipt is malformed", 0));
        }
        if !php::is_str(&or(at(&header, "alg"), ""), "EdDSA") {
            return Err(Error::exception("Installation receipt has an unexpected algorithm", 0));
        }
        if php::isset(&header, "typ") && !php::is_str(&at(&header, "typ"), "origin-installation-receipt+jwt") {
            return Err(Error::exception("Installation receipt has an unexpected type", 0));
        }
        let key = self.signing_key(&php::string(&or(at(&header, "kid"), ""))).await?;
        let input = format!("{header_segment}.{claims_segment}");
        if signature.len() != 64 || !jwt::ed25519_verify(&key, input.as_bytes(), &signature) {
            return Err(Error::exception("Installation receipt signature is invalid", 0));
        }
        if !php::is_str(&or(at(&claims, "iss"), ""), &self.transport.endpoint) {
            return Err(Error::exception("Installation receipt issuer is invalid", 0));
        }
        if !php::is_str(&or(at(&claims, "aud"), ""), app_id) {
            return Err(Error::exception("Installation receipt audience does not match the app id", 0));
        }
        let now = time::now();
        let leeway = 60;
        if php::isset(&claims, "exp") && now >= php::int(&at(&claims, "exp")).saturating_add(leeway) {
            return Err(Error::exception("Installation receipt has expired", 0));
        }
        if php::isset(&claims, "nbf") && now < php::int(&at(&claims, "nbf")).saturating_sub(leeway) {
            return Err(Error::exception("Installation receipt is not yet valid", 0));
        }
        if php::isset(&claims, "iat") && now < php::int(&at(&claims, "iat")).saturating_sub(leeway) {
            return Err(Error::exception("Installation receipt is issued in the future", 0));
        }
        if empty(&at(&claims, "sub")) {
            return Err(Error::exception("Installation receipt is missing the installation ID", 0));
        }
        Ok(claims)
    }

    /// Origin's active Ed25519 signing keys as base64url key material (the
    /// form [`Adapter::validate_webhook_event`] takes). `refresh` fetches
    /// them again.
    ///
    /// PHP: `getSigningKeys()`.
    pub async fn signing_keys(&self, refresh: bool) -> Result<Vec<Value>> {
        let keys = self.jwks(refresh).await?;
        Ok(keys.iter().map(|key| Value::Str(php::string(&at(key, "x")))).collect())
    }

    /// Origin's published Ed25519 JWKS entries, fetched once.
    async fn jwks(&self, refresh: bool) -> Result<Vec<Value>> {
        if !refresh && let Some(keys) = self.jwks.lock().unwrap_or_else(|e| e.into_inner()).clone() {
            return Ok(keys);
        }
        let response = self.transport.call(Call::get("/keys")).await?;
        let body = if php::is_array(&response.body) { response.body } else { Value::Array(Array::new()) };
        let listed = match at(&body, "keys") {
            k @ Value::Array(_) => k,
            _ => Value::Array(Array::new()),
        };
        let keys: Vec<Value> = each(&listed)
            .into_iter()
            .filter(|key| {
                php::is_str(&or(at(key, "kty"), ""), "OKP")
                    && php::is_str(&or(at(key, "crv"), ""), "Ed25519")
                    && !empty(&at(key, "x"))
            })
            .collect();
        *self.jwks.lock().unwrap_or_else(|e| e.into_inner()) = Some(keys.clone());
        Ok(keys)
    }

    /// The raw public key of a JWKS key id.
    async fn signing_key(&self, kid: &[u8]) -> Result<Vec<u8>> {
        for key in self.jwks(false).await? {
            if php::string(&or(at(&key, "kid"), "")) != kid {
                continue;
            }
            if let Some(public) = ed25519_public_key(&php::string(&at(&key, "x"))) {
                return Ok(public);
            }
        }
        Err(Error::exception("Installation receipt is signed with an unknown key", 0))
    }

    /// An installation, with the app JWT.
    async fn installation(&self, installation_id: &str) -> Result<Value> {
        let response = self
            .transport
            .call(Call::get(format!("/app/installations/{installation_id}")).auth(self.app_bearer()))
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to get installation {$installationId}", &response));
        }
        Ok(array_or_empty(response.body))
    }

    /// Every repository the installation reaches, page by page, until `found`
    /// returns a value.
    async fn walk_installation_repositories<T>(
        &self,
        mut found: impl FnMut(&Value) -> Option<T> + Send,
    ) -> Result<Option<T>> {
        let mut token = String::new();
        loop {
            let mut params = object([("pageSize", Value::Int(100))]);
            if !token.is_empty() {
                php::set(&mut params, "pageToken", s(&token));
            }
            let response = self.get("/installation/repos", Some(params)).await?;
            if response.status >= 400 {
                return Err(request_failed("Failed to list installation repositories", &response));
            }
            let body = array_or_empty(response.body);
            let repositories = match at(&body, "repositories") {
                r @ Value::Array(_) => r,
                _ => Value::Array(Array::new()),
            };
            for repository in each(&repositories) {
                if php::is_array(&repository)
                    && let Some(result) = found(&repository)
                {
                    return Ok(Some(result));
                }
            }
            // A next-page token that does not advance would page forever.
            let next = php::text(&php::string(&or(at(&body, "nextPageToken"), "")));
            if next == token {
                return Ok(None);
            }
            token = next;
            if token.is_empty() {
                return Ok(None);
            }
        }
    }

    /// The fallback search over the installation's repositories.
    async fn search_installation_repositories(
        &self,
        owner: &str,
        page: i64,
        per_page: i64,
        search: &str,
    ) -> Result<Value> {
        let mut repositories = Vec::new();
        let owner_lower = php::lower(owner.as_bytes());
        self.walk_installation_repositories(|repository| {
            let repository_owner = match at(repository, "owner") {
                o @ Value::Array(_) => o,
                _ => Value::Array(Array::new()),
            };
            if php::lower(&php::string(&or(at(&repository_owner, "slug"), ""))) != owner_lower {
                return None::<()>;
            }
            if !search.is_empty() {
                let name = php::string(&or(at(repository, "name"), ""));
                php_std::string::stripos(&name, search.as_bytes(), 0).ok().flatten()?;
            }
            repositories.push(normalize_repository(repository.clone()));
            None
        })
        .await?;
        let total = repositories.len() as i64;
        Ok(object([
            ("items", php::list(php::slice(&repositories, (page - 1).saturating_mul(per_page), per_page))),
            ("total", Value::Int(total)),
        ]))
    }

    /// Every page of a token-paginated collection.
    async fn collect_pages(
        &self,
        path: &str,
        field: &str,
        params: Vec<(&str, Value)>,
        authorization: Option<String>,
    ) -> Result<Value> {
        let authorization = authorization.unwrap_or_else(|| self.bearer());
        let mut items = Value::Array(Array::new());
        let mut token = String::new();
        loop {
            let mut query: Array = params.iter().map(|(k, v)| (ArrayKey::from(*k), v.clone())).collect();
            query.set(ArrayKey::from("pageSize"), Value::Int(100));
            if !token.is_empty() {
                query.set(ArrayKey::from("pageToken"), s(&token));
            }
            let response =
                self.transport.call(Call::get(path).auth(authorization.clone()).params(Value::Array(query))).await?;
            if response.status >= 400 {
                return Err(request_failed("Failed to list {$field}", &response));
            }
            let body = array_or_empty(response.body);
            let page = match at(&body, field) {
                p @ Value::Array(_) => p,
                _ => Value::Array(Array::new()),
            };
            items = Value::Array(php::array_merge(&[&items, &page])?);
            token = php::text(&php::string(&or(at(&body, "nextPageToken"), "")));
            if token.is_empty() {
                break;
            }
        }
        Ok(items)
    }

    /// A check run as fetched.
    async fn fetch_check_run(&self, owner: &str, repository_name: &str, check_run_id: &str) -> Result<Value> {
        let response = self
            .get(format!("{}/check-runs/{}", Self::repository_path(owner, repository_name), raw(check_run_id)), None)
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to get check run {$checkRunId}", &response));
        }
        Ok(array_or_empty(response.body))
    }

    /// A check suite as fetched.
    async fn fetch_check_suite(&self, owner: &str, repository_name: &str, check_suite_id: &str) -> Result<Value> {
        let response = self
            .get(
                format!("{}/check-suites/{}", Self::repository_path(owner, repository_name), raw(check_suite_id)),
                None,
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to get check suite {$checkSuiteId}", &response));
        }
        Ok(array_or_empty(response.body))
    }

    /// An Origin check run in the GitHub-style shape.
    fn normalize_check_run(&self, check_run: &Value, owner: &str, repository_name: &str) -> Value {
        let sha = php::string(&or(at(check_run, "sha"), ""));
        let id = php::string(&or(at(check_run, "id"), ""));
        let output = match at(check_run, "output") {
            o @ Value::Array(_) => o,
            _ => Value::Array(Array::new()),
        };
        let suite = match at(check_run, "checkSuite") {
            o @ Value::Array(_) => o,
            _ => Value::Array(Array::new()),
        };
        let sha_text = php::text(&sha);
        let nullable = |key: &str| {
            let v = at(check_run, key);
            if empty(&v) { Value::Null } else { Value::Str(php::string(&v)) }
        };
        object([
            ("id", s(&id)),
            ("name", Value::Str(php::string(&or(at(check_run, "name"), "")))),
            ("status", Value::Str(php::string(&or(at(check_run, "status"), "")))),
            ("conclusion", nullable("conclusion")),
            ("head_sha", s(&sha)),
            (
                "url",
                s(format!(
                    "{}{}/check-runs/{}",
                    self.transport.endpoint,
                    Self::repository_path(owner, repository_name),
                    php::text(&id)
                )),
            ),
            (
                "html_url",
                s(if php::filled(&sha_text) {
                    self.commit_url(owner, repository_name, &sha_text)
                } else {
                    self.repository_url(owner, repository_name)
                }),
            ),
            ("started_at", nullable("startedAt")),
            ("completed_at", nullable("completedAt")),
            ("details_url", Value::Str(php::string(&or(at(check_run, "detailsUrl"), "")))),
            ("external_id", Value::Str(php::string(&or(at(check_run, "externalId"), "")))),
            ("key", Value::Str(php::string(&or(at(check_run, "key"), "")))),
            ("check_suite_id", Value::Str(php::string(&or(at(&suite, "id"), "")))),
            (
                "output",
                object([
                    ("title", Value::Str(php::string(&or(at(&output, "title"), "")))),
                    ("summary", Value::Str(php::string(&or(at(&output, "summary"), "")))),
                    ("text", Value::Str(php::string(&or(at(&output, "text"), "")))),
                ]),
            ),
        ])
    }

    /// The Git HTTPS URL with the installation token as Basic credentials
    /// (user `x-access-token`).
    fn authenticated_clone_url(&self, owner: &str, repository_name: &str) -> String {
        let credentials = if php::filled(&self.access_token) {
            format!("x-access-token:{}@", php_std::encoding::urlencode(self.access_token.as_bytes()))
        } else {
            String::new()
        };
        let host = self.git_endpoint.get("https://".len()..).unwrap_or("");
        format!(
            "https://{credentials}{host}/{}/{}.git",
            php_std::encoding::urlencode(owner.as_bytes()),
            php_std::encoding::urlencode(repository_name.as_bytes())
        )
    }

    /// Runs a command and fails with what it printed, the token scrubbed out.
    async fn execute(&self, command: &Command, action: &str) -> Result<Vec<u8>> {
        let output = utopia_console::execute(command, b"", None, None).await?;
        if output.status != 0 {
            let mut printed = output.stdout.clone();
            printed.push(b'\n');
            printed.extend_from_slice(&output.stderr);
            let mut printed = php_std::string::trim(&printed, b" \t\n\r\0\x0B").to_vec();
            if php::filled(&self.access_token) {
                for secret in [
                    self.access_token.clone().into_bytes(),
                    php_std::encoding::urlencode(self.access_token.as_bytes()).into_bytes(),
                ] {
                    printed = php_std::string::str_replace(&secret, b"***", &printed).0.into_owned();
                }
            }
            let mut message = format!("{action} failed: ").into_bytes();
            message.extend_from_slice(&printed);
            return Err(Error::exception(message, 0));
        }
        Ok(output.stdout)
    }

    /// Whether a command succeeds.
    async fn try_execute(&self, command: &Command) -> Result<bool> {
        Ok(utopia_console::execute(command, b"", None, None).await?.status == 0)
    }

    async fn temporary_directory(&self) -> Result<String> {
        let directory = format!(
            "{}/utopia-vcs-origin-{}",
            std::env::temp_dir().to_string_lossy().trim_end_matches('/'),
            php_std::system::uniqid("", false)
        );
        utopia_async::fs::create_dir_all(&directory)
            .await
            .map_err(|_| Error::exception("Failed to create a temporary directory for the Git operation.", 0))?;
        Ok(directory)
    }

    async fn remove_directory(&self, directory: &str) -> Result<()> {
        if std::path::Path::new(directory).is_dir() {
            let _ = utopia_console::execute(&Command::new("rm")?.flag("-rf")?.argument(directory)?, b"", None, None)
                .await?;
        }
        Ok(())
    }

    /// Updates a pull request's title, body, state (`open`, `closed`), draft
    /// flag or base; `None` leaves a field as it is.
    ///
    /// PHP: `updatePullRequest()`.
    #[allow(clippy::too_many_arguments)]
    pub async fn update_pull_request(
        &self,
        owner: &str,
        repository_name: &str,
        number: i64,
        title: Option<&str>,
        body: Option<&str>,
        state: Option<&str>,
        draft: Option<bool>,
        base: Option<&str>,
    ) -> Result<Value> {
        let mut params = Value::Array(Array::new());
        if let Some(title) = title {
            php::set(&mut params, "title", s(title));
        }
        if let Some(body) = body {
            php::set(&mut params, "body", s(body));
        }
        if let Some(state) = state {
            php::set(&mut params, "state", s(state));
        }
        if let Some(draft) = draft {
            php::set(&mut params, "draft", Value::Bool(draft));
        }
        if let Some(base) = base {
            php::set(&mut params, "base", s(base));
        }
        let response = self
            .transport
            .call(
                Call::new(Method::Patch, format!("{}/pulls/{number}", Self::repository_path(owner, repository_name)))
                    .auth(self.bearer())
                    .params(params),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to update pull request", &response));
        }
        Ok(normalize_pull_request(array_or_empty(response.body)))
    }

    /// Merges a pull request (a stacked one with everything below it), as
    /// `{mergeCommitSha, mergedPullNumbers, pullRequest}`.
    ///
    /// PHP: `mergePullRequest()`.
    pub async fn merge_pull_request(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        let response = self
            .transport
            .call(
                Call::post(format!("{}/pulls/{number}/merge", Self::repository_path(owner, repository_name)))
                    .auth(self.bearer()),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to merge pull request", &response));
        }
        let body = array_or_empty(response.body);
        let merged = match at(&body, "mergedPullNumbers") {
            Value::Array(numbers) => {
                Value::Array(numbers.iter().map(|(k, v)| (k.to_owned(), Value::Int(php::int(v)))).collect())
            }
            _ => Value::Array(Array::new()),
        };
        Ok(object([
            ("mergeCommitSha", Value::Str(php::string(&or(at(&body, "mergeCommitSha"), "")))),
            ("mergedPullNumbers", merged),
            ("pullRequest", normalize_pull_request(array_or_empty(at(&body, "pullRequest")))),
        ]))
    }

    /// Pull requests in a state (`open`, `closed`, `all`), optionally from
    /// one head branch.
    ///
    /// PHP: `listPullRequests()`.
    pub async fn list_pull_requests(
        &self,
        owner: &str,
        repository_name: &str,
        state: &str,
        head: &str,
    ) -> Result<Value> {
        let mut params = vec![("state", s(state))];
        if !head.is_empty() {
            params.push(("head", s(head)));
        }
        let pulls = self
            .collect_pages(
                &format!("{}/pulls", Self::repository_path(owner, repository_name)),
                "pullRequests",
                params,
                None,
            )
            .await?;
        Ok(map_values(&pulls, |pr| normalize_pull_request(array_or_empty(pr.clone()))))
    }

    /// A pull request's general discussion comments.
    ///
    /// PHP: `listPullRequestComments()`.
    pub async fn list_pull_request_comments(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        self.collect_pages(
            &format!("{}/pulls/{number}/comments", Self::repository_path(owner, repository_name)),
            "comments",
            Vec::new(),
            None,
        )
        .await
    }

    /// The commits a pull request carries.
    ///
    /// PHP: `listPullRequestCommits()`.
    pub async fn list_pull_request_commits(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        self.collect_pages(
            &format!("{}/pulls/{number}/commits", Self::repository_path(owner, repository_name)),
            "commits",
            Vec::new(),
            None,
        )
        .await
    }

    /// Submits a review (`approve`, `request_changes`, `comment`); an empty
    /// version reviews the latest.
    ///
    /// PHP: `createPullRequestReview()`.
    pub async fn create_pull_request_review(
        &self,
        owner: &str,
        repository_name: &str,
        number: i64,
        verdict: &str,
        body: &str,
        version_number: &str,
    ) -> Result<Value> {
        let mut params = object([("verdict", s(verdict)), ("body", s(body))]);
        if !version_number.is_empty() {
            php::set(&mut params, "versionNumber", s(version_number));
        }
        let response = self
            .transport
            .call(
                Call::post(format!("{}/pulls/{number}/reviews", Self::repository_path(owner, repository_name)))
                    .auth(self.bearer())
                    .params(params),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to create pull request review", &response));
        }
        Ok(array_or_empty(response.body))
    }

    /// A pull request's submitted reviews (dismissed ones included).
    ///
    /// PHP: `listPullRequestReviews()`.
    pub async fn list_pull_request_reviews(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        self.collect_pages(
            &format!("{}/pulls/{number}/reviews", Self::repository_path(owner, repository_name)),
            "reviews",
            Vec::new(),
            None,
        )
        .await
    }

    /// Replaces a review's body.
    ///
    /// PHP: `updatePullRequestReview()`.
    pub async fn update_pull_request_review(
        &self,
        owner: &str,
        repository_name: &str,
        number: i64,
        review_id: &str,
        body: &str,
    ) -> Result<Value> {
        let response = self
            .transport
            .call(
                Call::new(
                    Method::Patch,
                    format!(
                        "{}/pulls/{number}/reviews/{}",
                        Self::repository_path(owner, repository_name),
                        raw(review_id)
                    ),
                )
                .auth(self.bearer())
                .params(object([("body", s(body))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to update pull request review", &response));
        }
        Ok(array_or_empty(response.body))
    }

    /// Dismisses a review so its verdict no longer counts.
    ///
    /// PHP: `dismissPullRequestReview()`.
    pub async fn dismiss_pull_request_review(
        &self,
        owner: &str,
        repository_name: &str,
        number: i64,
        review_id: &str,
        message: &str,
    ) -> Result<Value> {
        let response = self
            .transport
            .call(
                Call::new(
                    Method::Put,
                    format!(
                        "{}/pulls/{number}/reviews/{}/dismissals",
                        Self::repository_path(owner, repository_name),
                        raw(review_id)
                    ),
                )
                .auth(self.bearer())
                .params(object([("message", s(message))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to dismiss pull request review", &response));
        }
        Ok(array_or_empty(response.body))
    }

    /// Commits reachable from a SHA, branch or tag (empty: the default
    /// branch), shaped as [`Adapter::commit`] reports them.
    ///
    /// PHP: `listCommits()`.
    pub async fn list_commits(&self, owner: &str, repository_name: &str, sha: &str) -> Result<Vec<Value>> {
        let params = if sha.is_empty() { Vec::new() } else { vec![("sha", s(sha))] };
        let commits = self
            .collect_pages(
                &format!("{}/commits", Self::repository_path(owner, repository_name)),
                "commits",
                params,
                None,
            )
            .await?;
        let mut normalized = Vec::new();
        for item in each(&commits) {
            if !php::is_array(&item) {
                continue;
            }
            let commit = match at(&item, "commit") {
                c @ Value::Array(_) => c,
                _ => Value::Array(Array::new()),
            };
            let author = match at(&commit, "author") {
                a @ Value::Array(_) => a,
                _ => Value::Array(Array::new()),
            };
            let commit_sha = php::text(&php::string(&or(at(&item, "sha"), "")));
            normalized.push(object([
                ("commitAuthor", Value::Str(php::string(&or(at(&author, "name"), "")))),
                ("commitMessage", Value::Str(php::string(&or(at(&commit, "message"), "")))),
                ("commitAuthorAvatar", s("")),
                ("commitAuthorUrl", s("")),
                ("commitHash", s(&commit_sha)),
                (
                    "commitUrl",
                    s(if php::filled(&commit_sha) {
                        self.commit_url(owner, repository_name, &commit_sha)
                    } else {
                        String::new()
                    }),
                ),
            ]));
        }
        Ok(normalized)
    }

    /// The files a commit changed, with unified patches.
    ///
    /// PHP: `listCommitFiles()`.
    pub async fn list_commit_files(&self, owner: &str, repository_name: &str, sha: &str) -> Result<Value> {
        self.collect_pages(
            &format!("{}/commits/{}/files", Self::repository_path(owner, repository_name), raw(sha)),
            "files",
            Vec::new(),
            None,
        )
        .await
    }

    /// Compares two commits relative to their merge base.
    ///
    /// PHP: `compareCommits()`.
    pub async fn compare_commits(&self, owner: &str, repository_name: &str, base: &str, head: &str) -> Result<Value> {
        let response = self
            .get(
                format!("{}/compare/{}...{}", Self::repository_path(owner, repository_name), raw(base), raw(head)),
                None,
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to compare commits", &response));
        }
        Ok(array_or_empty(response.body))
    }

    /// A Git blob, decoded, as `{sha, size, content}`.
    ///
    /// PHP: `getBlob()`.
    pub async fn blob(&self, owner: &str, repository_name: &str, sha: &str) -> Result<Value> {
        let response =
            self.get(format!("{}/git/blobs/{}", Self::repository_path(owner, repository_name), raw(sha)), None).await?;
        if response.status >= 400 {
            return Err(Error::file_not_found());
        }
        let body = array_or_empty(response.body);
        Ok(object([
            ("sha", Value::Str(php::string(&or(at(&body, "sha"), "")))),
            ("size", Value::Int(php::int(&or(at(&body, "size"), Value::Int(0))))),
            (
                "content",
                s(php_std::encoding::base64_decode(&php::string(&or(at(&body, "content"), "")), false)
                    .unwrap_or_default()),
            ),
        ]))
    }

    /// Up to 20 files at one ref in one request: their decoded contents by
    /// path, `null` where a path is not a file.
    ///
    /// PHP: `batchGetRepositoryContents()`.
    pub async fn batch_get_repository_contents(
        &self,
        owner: &str,
        repository_name: &str,
        paths: &[&str],
        reference: &str,
    ) -> Result<Value> {
        let mut params = object([("paths", php::list(paths.iter().map(s).collect()))]);
        if !reference.is_empty() {
            php::set(&mut params, "ref", s(reference));
        }
        let response = self
            .transport
            .call(
                Call::post(format!("{}/contents:batchGet", Self::repository_path(owner, repository_name)))
                    .auth(self.bearer())
                    .params(params),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to batch get contents", &response));
        }
        let body = array_or_empty(response.body);
        let results = match at(&body, "results") {
            r @ Value::Array(_) => r,
            _ => Value::Array(Array::new()),
        };
        let mut contents: Array = Array::new();
        for result in each(&results) {
            if !php::is_array(&result) {
                continue;
            }
            let path = php::string(&or(at(&result, "path"), ""));
            let content = match at(&result, "content") {
                c @ Value::Array(_) => c,
                _ => Value::Array(Array::new()),
            };
            let value = if !empty(&at(&result, "found")) && php::is_str(&or(at(&content, "type"), ""), "file") {
                s(php_std::encoding::base64_decode(&php::string(&or(at(&content, "content"), "")), false)
                    .unwrap_or_default())
            } else {
                Value::Null
            };
            contents.set(ArrayKey::from_bytes(&path), value);
        }
        Ok(Value::Array(contents))
    }

    /// A check suite as Origin reports it.
    ///
    /// PHP: `getCheckSuite()`.
    pub async fn check_suite(&self, owner: &str, repository_name: &str, check_suite_id: &str) -> Result<Value> {
        self.fetch_check_suite(owner, repository_name, check_suite_id).await
    }

    /// Every check run of a commit, shaped as [`Adapter::check_run`].
    ///
    /// PHP: `listCheckRunsForCommit()`.
    pub async fn list_check_runs_for_commit(&self, owner: &str, repository_name: &str, sha: &str) -> Result<Value> {
        let runs = self
            .collect_pages(
                &format!("{}/commits/{}/check-runs", Self::repository_path(owner, repository_name), raw(sha)),
                "checkRuns",
                Vec::new(),
                None,
            )
            .await?;
        Ok(map_values(&runs, |run| self.normalize_check_run(&array_or_empty(run.clone()), owner, repository_name)))
    }

    /// The check runs of one suite.
    ///
    /// PHP: `listCheckRunsForSuite()`.
    pub async fn list_check_runs_for_suite(
        &self,
        owner: &str,
        repository_name: &str,
        check_suite_id: &str,
    ) -> Result<Value> {
        let runs = self
            .collect_pages(
                &format!(
                    "{}/check-suites/{}/check-runs",
                    Self::repository_path(owner, repository_name),
                    raw(check_suite_id)
                ),
                "checkRuns",
                Vec::new(),
                None,
            )
            .await?;
        Ok(map_values(&runs, |run| self.normalize_check_run(&array_or_empty(run.clone()), owner, repository_name)))
    }

    /// Creates or updates up to 10 check runs of one suite atomically.
    ///
    /// PHP: `batchUpsertCheckRuns()` (each input array's fields are a
    /// [`BatchCheckRun`]'s).
    pub async fn batch_upsert_check_runs(
        &self,
        owner: &str,
        repository_name: &str,
        head_sha: &str,
        suite_name: &str,
        check_runs: &[BatchCheckRun],
    ) -> Result<Value> {
        let suite_external_id = php_std::system::uniqid("attempt-", false);
        let mut runs = Vec::new();
        for input in check_runs {
            let (status, completed_at) = settle(&input.run)?;
            let external_id = if php::filled(&input.run.external_id) {
                input.run.external_id.clone()
            } else {
                php_std::system::uniqid("attempt-", false)
            };
            let key =
                if php::filled(&input.key) { input.key.clone() } else { format!("{}/{external_id}", input.run.name) };
            let mut run = object([
                ("key", s(&key)),
                ("name", s(&input.run.name)),
                ("status", s(&status)),
                ("externalId", s(&external_id)),
                ("externalUpdatedAt", s(time::rfc3339_micros_now())),
            ]);
            for (field, value) in [
                ("conclusion", input.run.conclusion.as_str()),
                ("startedAt", input.run.started_at.as_str()),
                ("completedAt", completed_at.as_str()),
                ("detailsUrl", input.run.details_url.as_str()),
            ] {
                if php::filled(value) {
                    php::set(&mut run, field, s(value));
                }
            }
            if let Some(output) = output(&input.run) {
                php::set(&mut run, "output", output);
            }
            runs.push(run);
        }
        let response = self
            .transport
            .call(
                Call::post(format!("{}/check-runs:batchUpsert", Self::repository_path(owner, repository_name)))
                    .auth(self.bearer())
                    .params(object([
                        ("headSha", s(head_sha)),
                        (
                            "checkSuite",
                            object([
                                ("key", s(suite_name)),
                                ("name", s(suite_name)),
                                ("externalId", s(&suite_external_id)),
                            ]),
                        ),
                        ("checkRuns", php::list(runs)),
                    ])),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to batch upsert check runs", &response));
        }
        let body = array_or_empty(response.body);
        let listed = match at(&body, "checkRuns") {
            r @ Value::Array(_) => r,
            _ => Value::Array(Array::new()),
        };
        Ok(map_values(&listed, |run| self.normalize_check_run(&array_or_empty(run.clone()), owner, repository_name)))
    }

    /// The authenticated app's metadata, with the app JWT.
    ///
    /// PHP: `getAuthenticatedApp()`.
    pub async fn authenticated_app(&self) -> Result<Value> {
        let response = self.transport.call(Call::get("/app").auth(self.app_bearer())).await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to get the authenticated app", &response));
        }
        Ok(array_or_empty(response.body))
    }

    /// Every installation of the app, with the app JWT.
    ///
    /// PHP: `listInstallations()`.
    pub async fn list_installations(&self) -> Result<Value> {
        self.collect_pages("/app/installations", "installations", Vec::new(), Some(self.app_bearer())).await
    }

    /// Deletes an installation, with the app JWT.
    ///
    /// PHP: `deleteInstallation()`.
    pub async fn delete_installation(&self, installation_id: &str) -> Result<bool> {
        let response = self
            .transport
            .call(
                Call::new(Method::Delete, format!("/app/installations/{}", raw(installation_id)))
                    .auth(self.app_bearer()),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to delete installation {$installationId}", &response));
        }
        Ok(true)
    }

    /// One page of the app's webhook deliveries, newest first, as
    /// `{deliveries, nextPageToken}`.
    ///
    /// PHP: `listWebhookDeliveries()`.
    pub async fn list_webhook_deliveries(
        &self,
        delivered: Option<bool>,
        event_type: &str,
        installation_id: &str,
        page_token: &str,
    ) -> Result<Value> {
        let mut params = Value::Array(Array::new());
        if let Some(delivered) = delivered {
            php::set(&mut params, "delivered", s(if delivered { "true" } else { "false" }));
        }
        for (key, value) in [("eventType", event_type), ("installationId", installation_id), ("pageToken", page_token)]
        {
            if !value.is_empty() {
                php::set(&mut params, key, s(value));
            }
        }
        let response =
            self.transport.call(Call::get("/app/webhook/deliveries").auth(self.app_bearer()).params(params)).await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to list webhook deliveries", &response));
        }
        let body = array_or_empty(response.body);
        Ok(object([
            ("deliveries", array_or_empty(at(&body, "deliveries"))),
            ("nextPageToken", Value::Str(php::string(&or(at(&body, "nextPageToken"), "")))),
        ]))
    }

    /// Asks Origin to send deliveries again: each id's outcome.
    ///
    /// PHP: `redeliverWebhookDeliveries()`.
    pub async fn redeliver_webhook_deliveries(&self, delivery_ids: &[&str]) -> Result<Value> {
        let response = self
            .transport
            .call(
                Call::post("/app/webhook/deliveries:batchRedeliver")
                    .auth(self.app_bearer())
                    .params(object([("deliveryIds", php::list(delivery_ids.iter().map(s).collect()))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to redeliver webhook deliveries", &response));
        }
        let body = array_or_empty(response.body);
        let results = match at(&body, "results") {
            r @ Value::Array(_) => r,
            _ => Value::Array(Array::new()),
        };
        let mut outcomes: Array = Array::new();
        for result in each(&results) {
            if php::is_array(&result) {
                outcomes.set(
                    ArrayKey::from_bytes(&php::string(&or(at(&result, "deliveryId"), ""))),
                    Value::Str(php::string(&or(at(&result, "outcome"), ""))),
                );
            }
        }
        Ok(Value::Array(outcomes))
    }

    /// The principal's rate limit, as `{limit, remaining, reset, used}`.
    ///
    /// PHP: `getRateLimit()`.
    pub async fn rate_limit(&self) -> Result<Value> {
        let response = self.get("/rate_limit", None).await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to get the rate limit", &response));
        }
        let body = array_or_empty(response.body);
        let core = array_or_empty(array_or_empty(at(&body, "resources")).clone());
        let core = array_or_empty(at(&core, "core"));
        let field = |key: &str| Value::Int(php::int(&or(at(&core, key), Value::Int(0))));
        Ok(object([
            ("limit", field("limit")),
            ("remaining", field("remaining")),
            ("reset", field("reset")),
            ("used", field("used")),
        ]))
    }

    /// Syncs one ref of a mirror from its upstream: `true` once synced
    /// (HTTP 200), `false` while pending (202).
    ///
    /// PHP: `syncMirror()`.
    pub async fn sync_mirror(
        &self,
        owner: &str,
        repository_name: &str,
        reference: &str,
        wait: bool,
        sha: &str,
    ) -> Result<bool> {
        let mut params = object([("ref", s(reference)), ("wait", Value::Bool(wait))]);
        if !sha.is_empty() {
            php::set(&mut params, "sha", s(sha));
        }
        let response = self
            .transport
            .call(
                Call::post(format!("{}:syncMirror", Self::repository_path(owner, repository_name)))
                    .auth(self.bearer())
                    .params(params),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to sync mirror", &response));
        }
        Ok(response.status == 200)
    }
}

/// `rawurlencode()`.
fn raw(s: &str) -> String {
    php_std::encoding::rawurlencode(s.as_bytes())
}

/// The array, or an empty one.
fn array_or_empty(value: Value) -> Value {
    if php::is_array(&value) { value } else { Value::Array(Array::new()) }
}

/// `array_map($f, $array)`: keys kept.
fn map_values(values: &Value, mut f: impl FnMut(&Value) -> Value) -> Value {
    match values {
        Value::Array(a) => Value::Array(a.iter().map(|(k, v)| (k.to_owned(), f(v))).collect()),
        _ => Value::Array(Array::new()),
    }
}

/// The exception for a failed call, with Origin's own message when it sent
/// one.
fn request_failed(action: &str, response: &Response) -> Error {
    let message = match &response.body {
        Value::Array(_) => php::text(&php::string(&or(response.at("message"), ""))),
        _ => String::new(),
    };
    let suffix = if message.is_empty() { String::new() } else { format!(" ({message})") };
    Error::exception(format!("{action}: HTTP {}{suffix}", response.status), response.status)
}

/// `pushed_at` from `pushedAt`, `updatedAt` or `createdAt` when it has none.
fn normalize_repository(mut repository: Value) -> Value {
    let has = matches!(&repository, Value::Array(a) if a.contains_key(&ArrayKey::from("pushed_at")));
    if !has {
        let pushed = or(at(&repository, "pushedAt"), or(at(&repository, "updatedAt"), at(&repository, "createdAt")));
        if !matches!(pushed, Value::Null) {
            php::set(&mut repository, "pushed_at", pushed);
        }
    }
    repository
}

/// A pull request with an integer `number` and short head and base refs.
fn normalize_pull_request(mut pull_request: Value) -> Value {
    let has_number = matches!(&pull_request, Value::Array(a) if a.contains_key(&ArrayKey::from("number")));
    if has_number {
        let number = Value::Int(php::int(&at(&pull_request, "number")));
        php::set(&mut pull_request, "number", number);
    }
    for side in ["head", "base"] {
        let mut part = at(&pull_request, side);
        if let (Value::Array(_), Value::Str(reference)) = (&part, at(&part, "ref")) {
            let short = short_branch_name(reference.as_bytes());
            php::set(&mut part, "ref", s(short));
            php::set(&mut pull_request, side, part);
        }
    }
    pull_request
}

/// A ref without `refs/heads/`.
fn short_branch_name(reference: &[u8]) -> &[u8] {
    reference.strip_prefix(b"refs/heads/").unwrap_or(reference)
}

/// A check run's output (with both a title and a summary).
fn output(run: &CheckRun) -> Option<Value> {
    if !(php::filled(&run.title) && php::filled(&run.summary)) {
        return None;
    }
    let mut output = Value::Array(Array::new());
    for (key, value) in [("title", &run.title), ("summary", &run.summary), ("text", &run.text)] {
        if php::filled(value) {
            php::set(&mut output, key, s(value));
        }
    }
    Some(output)
}

/// `preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem)`: a PEM's base64 body.
fn pem_body(pem: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(pem.len());
    let mut i = 0;
    while i < pem.len() {
        if pem[i..].starts_with(b"-----") {
            let run = pem[i + 5..].iter().take_while(|b| b.is_ascii_uppercase() || **b == b' ').count();
            if run > 0 && pem[i + 5 + run..].starts_with(b"-----") {
                i += 10 + run;
                continue;
            }
        }
        if matches!(pem[i], b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r') {
            i += 1;
            continue;
        }
        out.push(pem[i]);
        i += 1;
    }
    out
}

/// The libsodium Ed25519 secret key a configured private key stands for: a
/// PKCS#8 PEM, a base64 32-byte seed or a base64 64-byte secret key.
fn ed25519_secret_key(private_key: &str) -> Result<Vec<u8>> {
    let key = php_std::string::trim(private_key.as_bytes(), b" \t\n\r\0\x0B");
    let seed = if php::contains(key, b"-----BEGIN") {
        let der = php_std::encoding::base64_decode(&pem_body(key), true)
            .ok_or_else(|| Error::exception("Failed to decode the Origin private key PEM.", 0))?;
        let marker = b"\x04\x22\x04\x20";
        let seed = der.windows(4).position(|w| w == marker).map(|at| {
            let start = at + 4;
            der[start..(start + 32).min(der.len())].to_vec()
        });
        match seed {
            Some(seed) if seed.len() == 32 => seed,
            _ => return Err(Error::exception("The Origin private key is not an Ed25519 PKCS#8 key.", 0)),
        }
    } else {
        let translated: Vec<u8> = key
            .iter()
            .map(|b| match b {
                b'-' => b'+',
                b'_' => b'/',
                other => *other,
            })
            .collect();
        let decoded = php_std::encoding::base64_decode(&translated, true)
            .ok_or_else(|| Error::exception("Failed to decode the Origin private key.", 0))?;
        if decoded.len() == 64 {
            return Ok(decoded);
        }
        if decoded.len() != 32 {
            return Err(Error::exception("The Origin private key has an unexpected length.", 0));
        }
        decoded
    };
    jwt::ed25519_secret_key(&seed).ok_or_else(|| Error::exception("The Origin private key is not an Ed25519 key.", 0))
}

/// The raw Ed25519 public key a verification key stands for: an SPKI PEM,
/// or base64/base64url raw bytes.
fn ed25519_public_key(signature_key: &[u8]) -> Option<Vec<u8>> {
    let key = php_std::string::trim(signature_key, b" \t\n\r\0\x0B");
    if php::contains(key, b"-----BEGIN") {
        let der = php_std::encoding::base64_decode(&pem_body(key), true)?;
        let marker = b"\x03\x21\x00";
        let at = der.windows(3).position(|w| w == marker)?;
        let start = at + 3;
        let public = der[start..(start + 32).min(der.len())].to_vec();
        return (public.len() == 32).then_some(public);
    }
    let translated: Vec<u8> = key
        .iter()
        .map(|b| match b {
            b'-' => b'+',
            b'_' => b'/',
            other => *other,
        })
        .collect();
    php_std::encoding::base64_decode(&translated, true).filter(|d| d.len() == 32)
}

/// `preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/i', $ref)`: a full commit
/// SHA (a final newline allowed, as `$` allows it).
fn is_sha(reference: &str) -> bool {
    let body = reference.strip_suffix('\n').unwrap_or(reference);
    (body.len() == 40 || body.len() == 64) && body.bytes().all(|b| b.is_ascii_hexdigit())
}

/// A file path confined to the repository, normalized lexically.
fn confined_path(filepath: &str) -> Result<String> {
    let mut segments: Vec<&str> = Vec::new();
    let replaced = filepath.replace('\\', "/");
    for segment in replaced.split('/') {
        match segment {
            "" | "." => continue,
            ".." => {
                if segments.pop().is_none() {
                    return Err(Error::exception(format!("File path escapes the repository: {filepath}"), 0));
                }
            }
            other => segments.push(other),
        }
    }
    if segments.is_empty() {
        return Err(Error::exception(format!("File path names no file: {filepath}"), 0));
    }
    Ok(segments.join("/"))
}

#[async_trait]
impl Adapter for Origin {
    fn name(&self) -> &str {
        "origin"
    }

    fn vcs_type(&self) -> VcsType {
        VcsType::Git
    }

    /// `installation_id` is the installation (`i_...`), `private_key` the
    /// app's Ed25519 key, `app_id` the app (`app_...`).
    async fn initialize(
        &mut self,
        installation_id: &str,
        private_key: &str,
        app_id: Option<&str>,
        _access_token: Option<&str>,
        _refresh_token: Option<&str>,
    ) -> Result<()> {
        self.installation_id = installation_id.to_owned();
        let key = format!("origin-{installation_id}");
        match self.cache.load(&key, APP_JWT_EXPIRY - 60, "").await? {
            Some(response @ Value::Str(_)) if !response.loose_eq(&Value::Bool(false)) => {
                let parsed = array_or_empty(php::json_decode(&php::string(&response)).unwrap_or(Value::Null));
                self.jwt_token = php::text(&php::string(&or(at(&parsed, "jwtToken"), "")));
                self.access_token = php::text(&php::string(&or(at(&parsed, "accessToken"), "")));
            }
            _ => {
                self.generate_access_token(private_key, app_id).await?;
                let tokens = object([("jwtToken", s(&self.jwt_token)), ("accessToken", s(&self.access_token))]);
                let tokens = php::json_encode(&tokens).unwrap_or_else(|| "{}".into());
                self.cache.save(&key, &s(tokens), "", 0).await?;
            }
        }
        Ok(())
    }

    async fn user(&self, _username: &str) -> Result<Value> {
        Err(Error::exception("getUser() is not supported by Origin", 0))
    }

    async fn owner_name(&self, installation_id: &str, _repository_id: Option<i64>) -> Result<String> {
        let installation = self.installation(installation_id).await?;
        let target = array_or_empty(at(&installation, "target"));
        let has = matches!(&target, Value::Array(a) if a.contains_key(&ArrayKey::from("slug")));
        if !has {
            return Err(Error::exception("Owner name retrieval response is missing the installation target slug.", 0));
        }
        Ok(php::text(&php::string(&at(&target, "slug"))))
    }

    async fn has_access_to_all_repositories(&self) -> Result<bool> {
        let installation = self.installation(&self.installation_id).await?;
        Ok(php::is_str(&or(at(&installation, "repoSelectionMode"), ""), "all"))
    }

    /// Origin reports no totals and pages with tokens: every matching page is
    /// collected, then paged locally. Owner-wide listing may be refused; the
    /// installation's own repositories answer then.
    async fn search_repositories(&self, owner: &str, page: i64, per_page: i64, search: &str) -> Result<Value> {
        let mut repositories = Value::Array(Array::new());
        let mut token = String::new();
        loop {
            let mut params = object([("pageSize", Value::Int(100))]);
            if !search.is_empty() {
                php::set(&mut params, "filter", s(search));
            }
            if !token.is_empty() {
                php::set(&mut params, "pageToken", s(&token));
            }
            let response = self.get(format!("/repos/{}", raw(owner)), Some(params)).await?;
            if response.status == 403 || response.status == 404 {
                return self.search_installation_repositories(owner, page, per_page, search).await;
            }
            if response.status >= 400 {
                return Err(request_failed("Failed to search repositories", &response));
            }
            let body = array_or_empty(response.body);
            let listed = array_or_empty(at(&body, "repositories"));
            repositories = Value::Array(php::array_merge(&[&repositories, &listed])?);
            token = php::text(&php::string(&or(at(&body, "nextPageToken"), "")));
            if token.is_empty() {
                break;
            }
        }
        let all = each(&repositories);
        let total = all.len() as i64;
        let items = php::slice(&all, (page - 1).saturating_mul(per_page), per_page)
            .into_iter()
            .map(|r| normalize_repository(array_or_empty(r)))
            .collect();
        Ok(object([("items", php::list(items)), ("total", Value::Int(total))]))
    }

    async fn installation_repository(&self, repository_name: &str) -> Result<Value> {
        let wanted = php::lower(repository_name.as_bytes());
        let found = self
            .walk_installation_repositories(|repository| {
                (php::lower(&php::string(&or(at(repository, "name"), ""))) == wanted)
                    .then(|| normalize_repository(repository.clone()))
            })
            .await?;
        found.ok_or_else(|| Error::RepositoryNotFound("Repository not found.".into()))
    }

    async fn repository(&self, owner: &str, repository_name: &str) -> Result<Value> {
        let response = self.get(Self::repository_path(owner, repository_name), None).await?;
        if response.status == 403 || response.status == 404 {
            return Err(Error::RepositoryNotFound("Repository not found.".into()));
        }
        if response.status >= 400 {
            return Err(request_failed("Failed to get repository {$repositoryName}", &response));
        }
        Ok(normalize_repository(array_or_empty(response.body)))
    }

    async fn create_repository(&self, _owner: &str, _repository_name: &str, _private: bool) -> Result<Value> {
        Err(Error::exception("createRepository() is not supported by origin", 0))
    }

    async fn delete_repository(&self, _owner: &str, _repository_name: &str) -> Result<bool> {
        Err(Error::exception("deleteRepository() is not supported by origin", 0))
    }

    async fn pull_request_from_branch(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value> {
        let response = self
            .get(
                format!("{}/pulls", Self::repository_path(owner, repository_name)),
                Some(object([("head", s(branch)), ("state", s("open")), ("pageSize", Value::Int(1))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to list pull requests", &response));
        }
        let body = array_or_empty(response.body);
        let pulls = array_or_empty(at(&body, "pullRequests"));
        let first = or(php::at_index(&pulls, 0), Value::Array(Array::new()));
        match &first {
            Value::Array(a) if !a.is_empty() => Ok(normalize_pull_request(first)),
            _ => Ok(Value::Array(Array::new())),
        }
    }

    async fn pull_request(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        let response =
            self.get(format!("{}/pulls/{number}", Self::repository_path(owner, repository_name)), None).await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to get pull request", &response));
        }
        Ok(normalize_pull_request(array_or_empty(response.body)))
    }

    async fn pull_request_files(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        self.collect_pages(
            &format!("{}/pulls/{number}/files", Self::repository_path(owner, repository_name)),
            "files",
            Vec::new(),
            None,
        )
        .await
    }

    async fn create_comment(&self, owner: &str, repository_name: &str, number: i64, comment: &str) -> Result<String> {
        let response = self
            .transport
            .call(
                Call::post(format!("{}/pulls/{number}/comments", Self::repository_path(owner, repository_name)))
                    .auth(self.bearer())
                    .params(object([("body", s(comment))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to create comment", &response));
        }
        let body = array_or_empty(response.body);
        let has = matches!(&body, Value::Array(a) if a.contains_key(&ArrayKey::from("id")));
        if !has {
            return Err(Error::exception("Comment creation response is missing comment ID.", 0));
        }
        Ok(php::text(&php::string(&at(&body, "id"))))
    }

    async fn comment(&self, owner: &str, repository_name: &str, comment_id: &str) -> Result<String> {
        let response = self
            .get(format!("{}/pulls/comments/{}", Self::repository_path(owner, repository_name), raw(comment_id)), None)
            .await?;
        if response.status >= 400 {
            return Ok(String::new());
        }
        let body = array_or_empty(response.body);
        Ok(php::text(&php::string(&or(at(&body, "body"), ""))))
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
                Call::new(
                    Method::Patch,
                    format!("{}/pulls/comments/{}", Self::repository_path(owner, repository_name), raw(comment_id)),
                )
                .auth(self.bearer())
                .params(object([("body", s(comment))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to update comment", &response));
        }
        let body = array_or_empty(response.body);
        let has = matches!(&body, Value::Array(a) if a.contains_key(&ArrayKey::from("id")));
        if !has {
            return Err(Error::exception("Comment update response is missing comment ID.", 0));
        }
        Ok(php::text(&php::string(&at(&body, "id"))))
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
        clone_command(
            &self.authenticated_clone_url(owner, repository_name),
            version,
            version_type,
            directory,
            root_directory,
        )
    }

    /// Origin signs `"<webhook-id>.<webhook-timestamp>.<body>"` (the caller
    /// assembles it) with Ed25519 over its lowercase hex SHA-256; the header
    /// may carry several space-separated `v1ed,<base64>` signatures.
    fn validate_webhook_event(&self, payload: &[u8], signature: &[u8], signature_key: &[u8]) -> bool {
        let Some(public) = ed25519_public_key(signature_key) else { return false };
        use sha2::Digest as _;
        let digest = crate::webhook::hex(&sha2::Sha256::digest(payload));
        let trimmed = php_std::string::trim(signature, b" \t\n\r\0\x0B");
        let is_space = |b: &u8| matches!(*b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r');
        // preg_split('/\s+/', trim($signature)): pieces between runs of white space.
        let mut candidates: Vec<&[u8]> = Vec::new();
        let mut start = 0;
        let mut i = 0;
        while i < trimmed.len() {
            if is_space(&trimmed[i]) {
                candidates.push(&trimmed[start..i]);
                while i < trimmed.len() && is_space(&trimmed[i]) {
                    i += 1;
                }
                start = i;
            } else {
                i += 1;
            }
        }
        candidates.push(&trimmed[start..]);
        for candidate in candidates {
            let Some(encoded) = candidate.strip_prefix(b"v1ed,") else { continue };
            let Some(decoded) = php_std::encoding::base64_decode(encoded, true) else { continue };
            if decoded.len() != 64 {
                continue;
            }
            if jwt::ed25519_verify(&public, digest.as_bytes(), &decoded) {
                return true;
            }
        }
        false
    }

    /// Every delivery is wrapped in an envelope whose `event.type` is
    /// authoritative; one push may update several refs.
    fn events(&self, event: &str, payload: &[u8]) -> Result<Vec<Value>> {
        let decoded = match php::json_decode(payload) {
            Some(v @ Value::Array(_)) => v,
            _ => return Err(Error::exception("Invalid payload.", 0)),
        };
        let (kind, body, installation_id) = match at(&decoded, "event") {
            envelope @ Value::Array(_) => (
                php::string(&or(at(&envelope, "type"), event)),
                array_or_empty(at(&envelope, "payload")),
                php::string(&or(at(&decoded, "installationId"), "")),
            ),
            _ => (Str::copy_from(event.as_bytes()), decoded.clone(), Str::EMPTY),
        };
        if kind == *EVENT_PUSH.as_bytes() {
            return Ok(self.push_events(&body, &installation_id));
        }
        if let ArrayKey::Str(k) = ArrayKey::from_bytes(&kind)
            && let Some((_, action)) = PULL_REQUEST_ACTIONS.iter().find(|(name, _)| name.as_bytes() == k.as_bytes())
        {
            return Ok(vec![self.pull_request_event(action, &body, &installation_id)]);
        }
        if let Some(action) = kind.strip_prefix(b"installation.") {
            let installation = array_or_empty(at(&body, "installation"));
            let target = array_or_empty(at(&installation, "target"));
            let id = if installation_id.is_empty() {
                php::string(&or(at(&installation, "id"), ""))
            } else {
                installation_id
            };
            return Ok(vec![object([
                ("action", s(action)),
                ("installationId", s(id)),
                ("userName", Value::Str(php::string(&or(at(&target, "slug"), "")))),
            ])]);
        }
        Ok(Vec::new())
    }

    fn event_header_name(&self) -> &str {
        "webhook-event-type"
    }

    fn signature_header_name(&self) -> &str {
        "webhook-signature"
    }

    fn supported_webhook_scopes(&self) -> &'static [WebhookScope] {
        &[WebhookScope::Installation]
    }

    fn repository_url(&self, owner: &str, repository_name: &str) -> String {
        format!("{}/{owner}/{repository_name}", self.web_endpoint)
    }

    fn organization_url(&self, owner: &str) -> Result<String> {
        Ok(format!("{}/{owner}", self.web_endpoint))
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
        let found = self
            .walk_installation_repositories(|repository| {
                (php::string(&or(at(repository, "id"), "")) == repository_id.as_bytes())
                    .then(|| php::text(&php::string(&or(at(repository, "name"), ""))))
            })
            .await?;
        found.ok_or_else(|| Error::RepositoryNotFound("Repository not found.".into()))
    }

    async fn list_branches(&self, owner: &str, repository_name: &str) -> Result<Vec<Value>> {
        let mut branches = Vec::new();
        let mut token = String::new();
        loop {
            let mut params = object([("pageSize", Value::Int(100))]);
            if !token.is_empty() {
                php::set(&mut params, "pageToken", s(&token));
            }
            let response =
                self.get(format!("{}/branches", Self::repository_path(owner, repository_name)), Some(params)).await?;
            if response.status >= 400 {
                // A missing repository, or one with no commits yet, has no branches.
                return Ok(Vec::new());
            }
            let body = array_or_empty(response.body);
            for branch in each(&array_or_empty(at(&body, "branches"))) {
                if php::is_array(&branch) && !empty(&at(&branch, "name")) {
                    branches.push(Value::Str(php::string(&at(&branch, "name"))));
                }
            }
            token = php::text(&php::string(&or(at(&body, "nextPageToken"), "")));
            if token.is_empty() {
                break;
            }
        }
        Ok(branches)
    }

    async fn list_tags(&self, owner: &str, repository_name: &str, search: &str) -> Result<Vec<Value>> {
        let response = self
            .get(
                format!("{}/git/matching-refs", Self::repository_path(owner, repository_name)),
                Some(object([("ref", s("tags/"))])),
            )
            .await?;
        // 404 is a missing repository, 409 one with no commits: neither has tags.
        let Value::Array(refs) = &response.body else { return Ok(Vec::new()) };
        if !(200..300).contains(&response.status) {
            return Ok(Vec::new());
        }
        let tags = refs
            .values()
            .filter_map(|r| match at(r, "ref") {
                reference @ Value::Str(_) if php::is_array(r) => Some(php::str_replace("refs/tags/", "", &reference)),
                _ => None,
            })
            .collect();
        git::match_glob(tags, search)
    }

    /// Origin models CI feedback as check runs only: the status context is
    /// a run's stable key, so repeated updates of one context land on one
    /// run.
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
        let context = if php::filled(context) { context } else { "default" };
        let mut run = object([
            ("key", s(context)),
            ("name", s(context)),
            ("status", s(if state == "pending" { "in_progress" } else { "completed" })),
            ("externalId", s(context)),
            ("externalUpdatedAt", s(time::rfc3339_micros_now())),
        ]);
        if state != "pending" {
            php::set(&mut run, "conclusion", s(if state == "success" { "success" } else { "failure" }));
            php::set(&mut run, "completedAt", s(time::gmdate_now("Y-m-d\\TH:i:s\\Z")));
        }
        if php::filled(description) {
            php::set(
                &mut run,
                "output",
                object([
                    ("title", s(php_std::mb::mb_strimwidth(description.as_bytes(), 255))),
                    ("summary", s(description)),
                ]),
            );
        }
        if php::filled(target_url) {
            php::set(&mut run, "detailsUrl", s(target_url));
        }
        let response = self
            .transport
            .call(
                Call::post(format!("{}/check-runs", Self::repository_path(owner, repository_name)))
                    .auth(self.bearer())
                    .params(object([
                        ("headSha", s(sha)),
                        ("checkSuite", object([("key", s(context)), ("name", s(context)), ("externalId", s(context))])),
                        ("checkRun", run),
                    ])),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to update commit status", &response));
        }
        Ok(())
    }

    /// Every created run gets a unique key (keeping create-a-new-run
    /// semantics on Origin's upsert); the suite key is the run name.
    /// Annotations, images and actions are not supported and are ignored.
    async fn create_check_run(
        &self,
        owner: &str,
        repository_name: &str,
        head_sha: &str,
        run: &CheckRun,
    ) -> Result<Value> {
        let (status, completed_at) = settle(run)?;
        let external_id = if php::filled(&run.external_id) {
            run.external_id.clone()
        } else {
            php_std::system::uniqid("attempt-", false)
        };
        let mut check_run = object([
            ("key", s(format!("{}/{external_id}", run.name))),
            ("name", s(&run.name)),
            ("status", s(if php::filled(&status) { status.as_str() } else { "queued" })),
            ("externalId", s(&external_id)),
            ("externalUpdatedAt", s(time::rfc3339_micros_now())),
        ]);
        for (field, value) in [
            ("conclusion", run.conclusion.as_str()),
            ("startedAt", run.started_at.as_str()),
            ("completedAt", completed_at.as_str()),
            ("detailsUrl", run.details_url.as_str()),
        ] {
            if php::filled(value) {
                php::set(&mut check_run, field, s(value));
            }
        }
        if let Some(output) = output(run) {
            php::set(&mut check_run, "output", output);
        }
        let response = self
            .transport
            .call(
                Call::post(format!("{}/check-runs", Self::repository_path(owner, repository_name)))
                    .auth(self.bearer())
                    .params(object([
                        ("headSha", s(head_sha)),
                        (
                            "checkSuite",
                            object([("key", s(&run.name)), ("name", s(&run.name)), ("externalId", s(&external_id))]),
                        ),
                        ("checkRun", check_run),
                    ])),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to create check run", &response));
        }
        let body = array_or_empty(response.body);
        Ok(self.normalize_check_run(&array_or_empty(at(&body, "checkRun")), owner, repository_name))
    }

    async fn check_run(&self, owner: &str, repository_name: &str, check_run_id: &str) -> Result<Value> {
        let run = self.fetch_check_run(owner, repository_name, check_run_id).await?;
        Ok(self.normalize_check_run(&run, owner, repository_name))
    }

    /// Origin has no update by id: the run and its suite are read back and
    /// upserted again under the same keys and external id.
    async fn update_check_run(
        &self,
        owner: &str,
        repository_name: &str,
        check_run_id: &str,
        run: &CheckRun,
    ) -> Result<Value> {
        let (status, completed_at) = settle(run)?;
        let existing = self.fetch_check_run(owner, repository_name, check_run_id).await?;
        let existing_suite = array_or_empty(at(&existing, "checkSuite"));
        let suite = self
            .fetch_check_suite(owner, repository_name, &php::text(&php::string(&or(at(&existing_suite, "id"), ""))))
            .await?;
        let existing_output = array_or_empty(at(&existing, "output"));
        let field = |key: &str| Value::Str(php::string(&or(at(&existing, key), "")));
        let pick = |given: &str, key: &str| if php::filled(given) { s(given) } else { field(key) };

        let mut check_run = object([
            ("key", field("key")),
            ("name", pick(&run.name, "name")),
            ("status", pick(&status, "status")),
            ("externalId", field("externalId")),
            ("externalUpdatedAt", s(time::rfc3339_micros_now())),
        ]);
        for (key, value) in [
            ("conclusion", pick(&run.conclusion, "conclusion")),
            ("startedAt", pick(&run.started_at, "startedAt")),
            ("completedAt", pick(&completed_at, "completedAt")),
            ("detailsUrl", pick(&run.details_url, "detailsUrl")),
        ] {
            if filled_value(&value) {
                php::set(&mut check_run, key, value);
            }
        }
        if let Some(output) = output(run) {
            php::set(&mut check_run, "output", output);
        } else if matches!(&existing_output, Value::Array(a) if !a.is_empty()) {
            php::set(&mut check_run, "output", existing_output);
        }

        let suite_field = |key: &str| Value::Str(php::string(&or(at(&suite, key), "")));
        let mut check_suite = object([
            ("key", suite_field("key")),
            ("name", suite_field("name")),
            ("externalId", suite_field("externalId")),
        ]);
        let details = suite_field("detailsUrl");
        if filled_value(&details) {
            php::set(&mut check_suite, "detailsUrl", details);
        }

        let response = self
            .transport
            .call(
                Call::post(format!("{}/check-runs", Self::repository_path(owner, repository_name)))
                    .auth(self.bearer())
                    .params(object([("headSha", field("sha")), ("checkSuite", check_suite), ("checkRun", check_run)])),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to update check run {$checkRunId}", &response));
        }
        let body = array_or_empty(response.body);
        Ok(self.normalize_check_run(&array_or_empty(at(&body, "checkRun")), owner, repository_name))
    }

    /// A branch resolves to its tip first: slashes in its name would read as
    /// path segments.
    async fn repository_tree(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
        recursive: bool,
    ) -> Result<Vec<Value>> {
        let base = Self::repository_path(owner, repository_name);
        let mut sha = branch.to_owned();
        if !is_sha(branch) {
            let encoded = php_std::encoding::rawurlencode(branch.as_bytes()).replace("%2F", "/");
            let response = self.get(format!("{base}/git/ref/heads/{encoded}"), None).await?;
            if response.status >= 400 {
                return Ok(Vec::new());
            }
            let body = array_or_empty(response.body);
            let object_field = array_or_empty(at(&body, "object"));
            sha = php::text(&php::string(&or(at(&object_field, "sha"), "")));
            if sha.is_empty() {
                return Ok(Vec::new());
            }
        }
        let suffix = if recursive { "?recursive=1" } else { "" };
        let response = self.get(format!("{base}/git/trees/{sha}{suffix}"), None).await?;
        if response.status >= 400 {
            return Ok(Vec::new());
        }
        let body = array_or_empty(response.body);
        php::array_column(&array_or_empty(at(&body, "tree")), "path")
    }

    /// Origin computes no language statistics.
    async fn list_repository_languages(&self, _owner: &str, _repository_name: &str) -> Result<Vec<Value>> {
        Ok(Vec::new())
    }

    async fn list_repository_contents(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        reference: &str,
    ) -> Result<Vec<Value>> {
        let mut params = object([("path", s(normalize_repository_path(path)))]);
        if php::filled(reference) {
            php::set(&mut params, "ref", s(reference));
        }
        let response =
            self.get(format!("{}/contents", Self::repository_path(owner, repository_name)), Some(params)).await?;
        if response.status >= 400 {
            return Ok(Vec::new());
        }
        let body = array_or_empty(response.body);
        let items = if php::is_str(&or(at(&body, "type"), ""), "dir") {
            each(&array_or_empty(at(&body, "entries")))
        } else if matches!(&body, Value::Array(a) if !a.is_empty()) {
            vec![body.clone()]
        } else {
            Vec::new()
        };
        Ok(items
            .iter()
            .filter(|item| php::is_array(item))
            .map(|item| {
                object([
                    ("name", Value::Str(php::string(&or(at(item, "name"), "")))),
                    ("size", Value::Int(php::int(&or(at(item, "size"), Value::Int(0))))),
                    ("type", s(if php::is_str(&or(at(item, "type"), "file"), "dir") { "dir" } else { "file" })),
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
        let mut params = object([("path", s(normalize_repository_path(path)))]);
        if php::filled(reference) {
            php::set(&mut params, "ref", s(reference));
        }
        let response =
            self.get(format!("{}/contents", Self::repository_path(owner, repository_name)), Some(params)).await?;
        if response.status != 200 {
            return Err(Error::file_not_found());
        }
        let body = array_or_empty(response.body);
        if !php::is_str(&or(at(&body, "type"), ""), "file") || !php::is_str(&or(at(&body, "encoding"), ""), "base64") {
            return Err(Error::file_not_found());
        }
        Ok(object([
            ("sha", Value::Str(php::string(&or(at(&body, "sha"), "")))),
            ("size", Value::Int(php::int(&or(at(&body, "size"), Value::Int(0))))),
            (
                "content",
                s(php_std::encoding::base64_decode(&php::string(&or(at(&body, "content"), "")), false)
                    .unwrap_or_default()),
            ),
        ]))
    }

    async fn commit(&self, owner: &str, repository_name: &str, commit_hash: &str) -> Result<Value> {
        let response = self
            .get(format!("{}/commits/{}", Self::repository_path(owner, repository_name), raw(commit_hash)), None)
            .await?;
        if response.status == 404 {
            return Err(Error::RepositoryNotFound("Commit not found.".into()));
        }
        if response.status >= 400 {
            return Err(Error::exception("Commit not found or inaccessible.", response.status));
        }
        let body = array_or_empty(response.body);
        let commit = array_or_empty(at(&body, "commit"));
        let author = array_or_empty(at(&commit, "author"));
        let sha = php::text(&php::string(&or(at(&body, "sha"), "")));
        Ok(object([
            ("commitAuthor", Value::Str(php::string(&or(at(&author, "name"), "Unknown")))),
            ("commitMessage", Value::Str(php::string(&or(at(&commit, "message"), "No message")))),
            ("commitAuthorAvatar", s("")),
            ("commitAuthorUrl", s("")),
            ("commitHash", s(&sha)),
            (
                "commitUrl",
                s(if php::filled(&sha) { self.commit_url(owner, repository_name, &sha) } else { String::new() }),
            ),
        ]))
    }

    async fn latest_commit(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value> {
        match self.commit(owner, repository_name, branch).await {
            Err(Error::RepositoryNotFound(_)) => Err(Error::RepositoryNotFound(format!("Branch not found: {branch}"))),
            other => other,
        }
    }

    async fn repository_presigned_url(
        &self,
        _owner: &str,
        _repository_name: &str,
        _reference: &str,
        _format: &str,
    ) -> Result<String> {
        Err(crate::adapter::unsupported("getRepositoryPresignedUrl", self.name()))
    }
}

impl Origin {
    fn push_events(&self, body: &Value, installation_id: &[u8]) -> Vec<Value> {
        let repository = array_or_empty(at(body, "repository"));
        let repository_owner = array_or_empty(at(&repository, "owner"));
        let repository_id = php::string(&or(at(&repository, "id"), ""));
        let repository_name = php::text(&php::string(&or(at(&repository, "name"), "")));
        let owner = php::text(&php::string(&or(at(&repository_owner, "slug"), "")));
        let repository_url = if php::filled(&owner) && php::filled(&repository_name) {
            self.repository_url(&owner, &repository_name)
        } else {
            String::new()
        };
        let mut events = Vec::new();
        for update in each(&array_or_empty(at(body, "refUpdates"))) {
            if !php::is_array(&update) {
                continue;
            }
            let branch = php::text(short_branch_name(&php::string(&or(at(&update, "ref"), ""))));
            let head_commit = array_or_empty(at(&update, "headCommit"));
            let author = array_or_empty(at(&head_commit, "author"));
            let commit_hash = php::text(&php::string(&or(at(&update, "after"), "")));
            events.push(object([
                ("branchCreated", Value::Bool(or(at(&update, "created"), false).truthy())),
                ("branchDeleted", Value::Bool(or(at(&update, "deleted"), false).truthy())),
                ("branch", s(&branch)),
                (
                    "branchUrl",
                    s(if php::filled(&repository_url) && php::filled(&branch) {
                        self.branch_url(&owner, &repository_name, &branch)
                    } else {
                        String::new()
                    }),
                ),
                ("repositoryId", s(&repository_id)),
                ("repositoryName", s(&repository_name)),
                ("repositoryUrl", s(&repository_url)),
                ("installationId", s(installation_id)),
                ("commitHash", s(&commit_hash)),
                ("owner", s(&owner)),
                ("authorUrl", s("")),
                ("authorAvatarUrl", s("")),
                ("headCommitAuthorName", Value::Str(php::string(&or(at(&author, "name"), "")))),
                ("headCommitAuthorEmail", Value::Str(php::string(&or(at(&author, "email"), "")))),
                ("headCommitMessage", Value::Str(php::string(&or(at(&head_commit, "message"), "")))),
                (
                    "headCommitUrl",
                    s(if php::filled(&repository_url) && php::filled(&commit_hash) {
                        self.commit_url(&owner, &repository_name, &commit_hash)
                    } else {
                        String::new()
                    }),
                ),
                ("external", Value::Bool(false)),
                ("pullRequestNumber", s("")),
                ("action", s("")),
                ("affectedFiles", Value::Array(Array::new())),
            ]));
        }
        events
    }

    fn pull_request_event(&self, action: &str, body: &Value, installation_id: &[u8]) -> Value {
        let pull_request = array_or_empty(at(body, "pullRequest"));
        let repository = array_or_empty(at(body, "repository"));
        let repository_owner = array_or_empty(at(&repository, "owner"));
        let head = array_or_empty(at(&pull_request, "head"));
        let repository_id = php::string(&or(at(&repository, "id"), ""));
        let repository_name = php::text(&php::string(&or(at(&repository, "name"), "")));
        let owner = php::text(&php::string(&or(at(&repository_owner, "slug"), "")));
        let repository_url = if php::filled(&owner) && php::filled(&repository_name) {
            self.repository_url(&owner, &repository_name)
        } else {
            String::new()
        };
        let branch = php::text(short_branch_name(&php::string(&or(at(&head, "ref"), ""))));
        let commit_hash = php::text(&php::string(&or(at(&head, "sha"), "")));
        object([
            ("branch", s(&branch)),
            (
                "branchUrl",
                s(if php::filled(&repository_url) && php::filled(&branch) {
                    self.branch_url(&owner, &repository_name, &branch)
                } else {
                    String::new()
                }),
            ),
            ("repositoryId", s(&repository_id)),
            ("repositoryName", s(&repository_name)),
            ("repositoryUrl", s(&repository_url)),
            ("installationId", s(installation_id)),
            ("commitHash", s(&commit_hash)),
            ("owner", s(&owner)),
            ("authorUrl", s("")),
            ("authorAvatarUrl", s("")),
            (
                "headCommitUrl",
                s(if php::filled(&repository_url) && php::filled(&commit_hash) {
                    self.commit_url(&owner, &repository_name, &commit_hash)
                } else {
                    String::new()
                }),
            ),
            ("external", Value::Bool(false)),
            ("pullRequestNumber", Value::Int(php::int(&or(at(&pull_request, "number"), Value::Int(0))))),
            ("action", s(action)),
        ])
    }
}

#[async_trait]
impl Git for Origin {
    /// Origin has no contents-write API: the file is committed locally and
    /// pushed over Git HTTPS.
    async fn create_file(
        &self,
        owner: &str,
        repository_name: &str,
        filepath: &str,
        content: &[u8],
        message: &str,
        branch: &str,
    ) -> Result<Value> {
        let relative = confined_path(filepath)?;
        let repository = self.repository(owner, repository_name).await?;
        let default_branch = php::text(&php::string(&or(at(&repository, "defaultBranch"), "main")));
        let target = if php::filled(branch) { branch.to_owned() } else { default_branch.clone() };
        let remote = self.authenticated_clone_url(owner, repository_name);
        let directory = self.temporary_directory().await?;

        let result = async {
            self.execute(&git::git(&directory)?.argument("init")?.flag("-q")?, "Initializing the working repository")
                .await?;
            let fetch = |reference: String| -> Result<Command> {
                Ok(git::git(&directory)?
                    .argument("fetch")?
                    .flag("-q")?
                    .option("--depth", "1")?
                    .argument(remote.as_str())?
                    .argument(reference)?)
            };
            let mut has_base = self.try_execute(&fetch(format!("refs/heads/{target}"))?).await?;
            if !has_base && !["", "0", default_branch.as_str()].contains(&branch) {
                has_base = self.try_execute(&fetch(format!("refs/heads/{default_branch}"))?).await?;
            }
            if has_base {
                self.execute(
                    &git::git(&directory)?.argument("checkout")?.flag("-q")?.argument("FETCH_HEAD")?,
                    "Checking out the branch tip",
                )
                .await?;
            }
            let absolute = format!("{directory}/{relative}");
            let parent = std::path::Path::new(&absolute).parent().map(|p| p.to_path_buf()).unwrap_or_default();
            if !parent.is_dir() && utopia_async::fs::create_dir_all(&parent).await.is_err() {
                return Err(Error::exception(format!("Failed to create directory for {filepath}"), 0));
            }
            let checkout = std::fs::canonicalize(&directory).ok();
            let resolved = std::fs::canonicalize(&parent).ok();
            let escapes = match (checkout, resolved) {
                (Some(checkout), Some(resolved)) => {
                    let root = format!("{}/", checkout.to_string_lossy());
                    let inside = format!("{}/", resolved.to_string_lossy());
                    !inside.starts_with(&root)
                }
                _ => true,
            };
            let is_link = std::fs::symlink_metadata(&absolute).map(|m| m.file_type().is_symlink()).unwrap_or(false);
            if escapes || is_link {
                return Err(Error::exception(format!("File path escapes the repository: {filepath}"), 0));
            }
            if utopia_async::fs::write(&absolute, content).await.is_err() {
                return Err(Error::exception(format!("Failed to write {filepath}"), 0));
            }
            self.execute(
                &git::git(&directory)?.argument("add")?.argument("--")?.argument(relative.as_str())?,
                "Staging the file",
            )
            .await?;
            self.execute(
                &git::git(&directory)?
                    .option("-c", "user.name=Utopia VCS")?
                    .option("-c", "user.email=vcs@utopia.dev")?
                    .argument("commit")?
                    .flag("-q")?
                    .option("-m", message)?,
                "Committing the file",
            )
            .await?;
            let hash = self
                .execute(&git::git(&directory)?.argument("rev-parse")?.argument("HEAD")?, "Reading the commit hash")
                .await?;
            let hash = php::text(php_std::string::trim(&hash, b" \t\n\r\0\x0B"));
            self.execute(
                &git::git(&directory)?
                    .argument("push")?
                    .flag("-q")?
                    .argument(remote.as_str())?
                    .argument(format!("HEAD:refs/heads/{target}"))?,
                "Pushing the commit",
            )
            .await?;
            Ok(object([("path", s(&relative)), ("branch", s(&target)), ("commitHash", s(hash))]))
        }
        .await;
        self.remove_directory(&directory).await?;
        result
    }

    /// Pushed over Git HTTPS: the remote has the objects, only the ref moves.
    async fn create_branch(
        &self,
        owner: &str,
        repository_name: &str,
        new_branch: &str,
        old_branch: &str,
    ) -> Result<Value> {
        let remote = self.authenticated_clone_url(owner, repository_name);
        let directory = self.temporary_directory().await?;
        let result = async {
            self.execute(&git::git(&directory)?.argument("init")?.flag("-q")?, "Initializing the working repository")
                .await?;
            self.execute(
                &git::git(&directory)?
                    .argument("fetch")?
                    .flag("-q")?
                    .option("--depth", "1")?
                    .argument(remote.as_str())?
                    .argument(format!("refs/heads/{old_branch}"))?,
                &format!("Fetching branch {old_branch}"),
            )
            .await?;
            let sha = self
                .execute(
                    &git::git(&directory)?.argument("rev-parse")?.argument("FETCH_HEAD")?,
                    "Reading the branch tip",
                )
                .await?;
            let sha = php::text(php_std::string::trim(&sha, b" \t\n\r\0\x0B"));
            self.execute(
                &git::git(&directory)?
                    .argument("push")?
                    .flag("-q")?
                    .argument(remote.as_str())?
                    .argument(format!("FETCH_HEAD:refs/heads/{new_branch}"))?,
                &format!("Creating branch {new_branch}"),
            )
            .await?;
            Ok(object([("name", s(new_branch)), ("ref", s(format!("refs/heads/{new_branch}"))), ("sha", s(sha))]))
        }
        .await;
        self.remove_directory(&directory).await?;
        result
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
            .transport
            .call(
                Call::post(format!("{}/pulls", Self::repository_path(owner, repository_name)))
                    .auth(self.bearer())
                    .params(object([("title", s(title)), ("head", s(head)), ("base", s(base)), ("body", s(body))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to create pull request", &response));
        }
        Ok(normalize_pull_request(array_or_empty(response.body)))
    }

    /// Origin delivers webhooks per installation to the app's configured URL.
    async fn create_webhook(
        &self,
        _owner: &str,
        _repository_name: &str,
        _url: &str,
        _secret: &str,
        _events: &[&str],
    ) -> Result<WebhookId> {
        Err(Error::exception(
            "createWebhook() is not supported by Origin. Configure the webhook URL in the Origin app settings.",
            0,
        ))
    }

    /// Pushed over Git HTTPS: annotated with a message, lightweight without.
    async fn create_tag(
        &self,
        owner: &str,
        repository_name: &str,
        tag: &str,
        target: &str,
        message: &str,
    ) -> Result<Value> {
        let remote = self.authenticated_clone_url(owner, repository_name);
        let directory = self.temporary_directory().await?;
        let result = async {
            self.execute(&git::git(&directory)?.argument("init")?.flag("-q")?, "Initializing the working repository")
                .await?;
            let direct = git::git(&directory)?
                .argument("fetch")?
                .flag("-q")?
                .option("--depth", "1")?
                .argument(remote.as_str())?
                .argument(target)?;
            if !self.try_execute(&direct).await? {
                self.execute(
                    &git::git(&directory)?
                        .argument("fetch")?
                        .flag("-q")?
                        .argument(remote.as_str())?
                        .argument("refs/heads/*:refs/remotes/origin/*")?,
                    &format!("Fetching commit {target}"),
                )
                .await?;
            }
            if php::filled(message) {
                self.execute(
                    &git::git(&directory)?
                        .option("-c", "user.name=Utopia VCS")?
                        .option("-c", "user.email=vcs@utopia.dev")?
                        .argument("tag")?
                        .flag("-a")?
                        .argument(tag)?
                        .option("-m", message)?
                        .argument(target)?,
                    &format!("Creating tag {tag}"),
                )
                .await?;
                self.execute(
                    &git::git(&directory)?
                        .argument("push")?
                        .flag("-q")?
                        .argument(remote.as_str())?
                        .argument(format!("refs/tags/{tag}"))?,
                    &format!("Pushing tag {tag}"),
                )
                .await?;
            } else {
                self.execute(
                    &git::git(&directory)?
                        .argument("push")?
                        .flag("-q")?
                        .argument(remote.as_str())?
                        .argument(format!("{target}:refs/tags/{tag}"))?,
                    &format!("Pushing tag {tag}"),
                )
                .await?;
            }
            Ok(object([("name", s(tag)), ("sha", s(target)), ("message", s(message))]))
        }
        .await;
        self.remove_directory(&directory).await?;
        result
    }

    fn supports_repository_creation(&self) -> bool {
        false
    }

    fn supports_repository_deletion(&self) -> bool {
        false
    }

    fn supports_repository_archives(&self) -> bool {
        false
    }

    fn repository_clone_url(&self, owner: &str, repository_name: &str) -> Result<String> {
        Ok(format!(
            "{}/{}/{}.git",
            self.git_endpoint,
            php_std::encoding::urlencode(owner.as_bytes()),
            php_std::encoding::urlencode(repository_name.as_bytes())
        ))
    }

    fn repository_clone_headers(&self) -> Vec<(String, String)> {
        if !php::filled(&self.access_token) {
            return Vec::new();
        }
        vec![(
            "Authorization".into(),
            format!(
                "Basic {}",
                php_std::encoding::base64_encode(format!("x-access-token:{}", self.access_token).as_bytes())
            ),
        )]
    }

    fn supports_comment_images(&self) -> bool {
        false
    }

    fn supports_public_repositories(&self) -> bool {
        false
    }

    /// The commit's check runs in commit-status shape.
    async fn commit_statuses(&self, owner: &str, repository_name: &str, commit_hash: &str) -> Result<Vec<Value>> {
        let runs = self.list_check_runs_for_commit(owner, repository_name, commit_hash).await?;
        let mut statuses = Vec::new();
        for run in each(&runs) {
            if !php::is_array(&run) {
                continue;
            }
            let conclusion = php::string(&or(at(&run, "conclusion"), ""));
            let state = if !php::is_str(&or(at(&run, "status"), ""), "completed") {
                "pending"
            } else if matches!(conclusion.as_bytes(), b"success" | b"neutral" | b"skipped") {
                "success"
            } else {
                "failure"
            };
            let output = array_or_empty(at(&run, "output"));
            statuses.push(object([
                ("state", s(state)),
                ("description", Value::Str(php::string(&or(at(&output, "title"), "")))),
                ("target_url", Value::Str(php::string(&or(at(&run, "details_url"), "")))),
                ("context", Value::Str(php::string(&or(at(&run, "key"), or(at(&run, "name"), ""))))),
            ]));
        }
        Ok(statuses)
    }
}

/// Whether `(string) $value` is neither `''` nor `'0'`.
fn filled_value(value: &Value) -> bool {
    let text = php::string(value);
    !text.is_empty() && text != b"0"
}

#[allow(dead_code)]
fn unused_path(value: &Value) -> Value {
    path(value, &[])
}
