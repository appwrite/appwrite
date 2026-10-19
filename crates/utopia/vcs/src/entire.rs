//! Entire (entire.io), the agent-native Git host, through a plugin
//! installation.

use std::collections::HashMap;
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use php_std::{Array, Value};
use utopia_cache::Cache;
use utopia_console::Command;

use crate::adapter::{Adapter, Git, VcsType, WebhookId, WebhookScope};
use crate::error::{Error, Result};
use crate::git::{self, clone_command, match_glob, normalize_repository_path};
use crate::origin::confined_path;
use crate::php::{self, at, at_index, each, empty, object, s};
use crate::transport::{Call, Method, Response, Transport};
use crate::{jwt, time};

/// A ref was created.
///
/// PHP: `Entire::EVENT_REF_CREATED`.
pub const EVENT_REF_CREATED: &str = "ref.created";

/// A ref moved.
///
/// PHP: `Entire::EVENT_REF_UPDATED`.
pub const EVENT_REF_UPDATED: &str = "ref.updated";

/// A ref was deleted.
///
/// PHP: `Entire::EVENT_REF_DELETED`.
pub const EVENT_REF_DELETED: &str = "ref.deleted";

/// The prefix of installation lifecycle events (`installation.deleted`).
///
/// PHP: `Entire::EVENT_INSTALLATION`.
pub const EVENT_INSTALLATION: &str = "installation";

/// Plugin JWT lifetime in seconds: at most five minutes after `iat`.
///
/// PHP: `Entire::PLUGIN_JWT_EXPIRY`.
pub const PLUGIN_JWT_EXPIRY: i64 = 60 * 5;

/// How far in the past a plugin JWT is issued: the core allows no clock
/// skew on `iat`.
///
/// PHP: `Entire::PLUGIN_JWT_SKEW`.
pub const PLUGIN_JWT_SKEW: i64 = 30;

/// How long a minted installation token is reused, in seconds.
///
/// PHP: `Entire::TOKEN_CACHE_TTL`.
pub const TOKEN_CACHE_TTL: i64 = 60 * 40;

/// Entire's cores: a receipt is accepted from these only, and any of them
/// serves any installation.
///
/// PHP: `Entire::CORES`.
pub const CORES: [&str; 6] = [
    "https://us.auth.entire.io",
    "https://eu.auth.entire.io",
    "https://au.auth.entire.io",
    "https://in.auth.entire.io",
    "https://uk.auth.entire.io",
    "https://sg.auth.entire.io",
];

/// Clock skew tolerated when checking token times.
const LEEWAY: i64 = 60;

/// Joins the trail number and the discussion id into one comment id.
const COMMENT_SEPARATOR: char = '-';

/// The run status and conclusion of each state [`Adapter::update_check`]
/// takes.
const CHECK_STATES: [(&str, &str, Option<&str>); 7] = [
    ("queued", "queued", None),
    ("pending", "in_progress", None),
    ("in_progress", "in_progress", None),
    ("success", "completed", Some("success")),
    ("failure", "completed", Some("failure")),
    ("error", "completed", Some("failure")),
    ("cancelled", "completed", Some("cancelled")),
];

const TRIM: &[u8] = b" \t\n\r\0\x0B";

/// The Entire adapter: an Ed25519-signed (EdDSA) plugin JWT is exchanged on
/// a control plane ("core") for an installation token (cached under the
/// installation id); repository calls go to the data-plane cell serving each
/// repository. Pull requests are trails, comments their discussions, and Git
/// traffic goes through Entire's remote helper `git-remote-entire` with the
/// token in `ENTIRE_TOKEN`.
///
/// PHP: `Utopia\VCS\Adapter\Git\Entire`.
pub struct Entire {
    cache: Arc<Cache>,
    transport: Transport,
    core_endpoint: String,
    issuer: String,
    web_endpoint: String,
    plugin: String,
    installation_id: String,
    jwt_token: String,
    access_token: String,
    jwks: Mutex<HashMap<String, Vec<Value>>>,
    repositories: Mutex<Option<Vec<Value>>>,
    data_endpoints: Mutex<HashMap<String, String>>,
    regional_repositories: Mutex<HashMap<String, Value>>,
}

fn lock<T>(mutex: &Mutex<T>) -> std::sync::MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(|e| e.into_inner())
}

/// `rawurlencode()`.
fn raw(text: &str) -> String {
    php_std::encoding::rawurlencode(text.as_bytes())
}

/// `rtrim($text, '/')`.
fn rtrim_slash(text: &str) -> String {
    text.trim_end_matches('/').to_owned()
}

/// The array, or an empty one: Entire's `nested()`.
fn nested(data: &Value, key: &str) -> Value {
    match at(data, key) {
        v @ Value::Array(_) => v,
        _ => Value::Array(Array::new()),
    }
}

/// Entire's `nested()` with an integer key.
fn nested_index(data: &Value, index: i64) -> Value {
    match at_index(data, index) {
        v @ Value::Array(_) => v,
        _ => Value::Array(Array::new()),
    }
}

/// Entire's `text()`: a scalar as a string, `default` otherwise.
fn text_or(data: &Value, key: &str, default: &str) -> String {
    scalar_text(&at(data, key)).unwrap_or_else(|| default.to_owned())
}

fn text(data: &Value, key: &str) -> String {
    text_or(data, key, "")
}

fn scalar_text(value: &Value) -> Option<String> {
    match value {
        Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_) => Some(php::text(&php::string(value))),
        _ => None,
    }
}

/// Entire's `number()`: a numeric value as an integer, 0 otherwise.
fn number(data: &Value, key: &str) -> i64 {
    let value = at(data, key);
    match &value {
        Value::Int(i) => *i,
        Value::Float(_) => php::int(&value),
        Value::Str(text) if php_std::value::numeric_bytes_ex(text.as_bytes()).is_some() => php::int(&value),
        _ => 0,
    }
}

/// Entire's `strings()`: the scalar elements of a list field as strings.
fn strings(data: &Value, key: &str) -> Value {
    php::list(each(&nested(data, key)).iter().filter_map(scalar_text).map(s).collect())
}

/// `(bool) $value`.
fn truthy(value: &Value) -> bool {
    !empty(value)
}

fn empty_array() -> Value {
    Value::Array(Array::new())
}

/// The exception for a failed call, with Entire's problem detail.
fn request_failed(action: &str, response: &Response) -> Error {
    let body = body(response);
    let detail = text(&body, "detail");
    let detail = if detail.is_empty() || detail == "0" { text(&body, "title") } else { detail };
    let message = format!("{} {}", text(&body, "code"), detail);
    let message = php::text(php_std::string::trim(message.as_bytes(), TRIM));
    let suffix = if message.is_empty() { String::new() } else { format!(" ({message})") };
    Error::exception(format!("{action}: HTTP {}{suffix}", response.status), response.status)
}

/// The decoded object a call answered with, or nothing.
fn body(response: &Response) -> Value {
    match &response.body {
        v @ Value::Array(_) => v.clone(),
        _ => empty_array(),
    }
}

/// The base64 body of a PEM block, without armor lines and white space.
fn pem_body(pem: &[u8]) -> Vec<u8> {
    let mut body = Vec::new();
    for line in pem.split(|b| *b == b'\n') {
        let line = php_std::string::trim(line, TRIM);
        if line.is_empty() || line.starts_with(b"-----") {
            continue;
        }
        body.extend_from_slice(line);
    }
    body.retain(|b| !matches!(b, b' ' | b'\t' | b'\r'));
    body
}

/// `strtr($key, '-_', '+/')`.
fn unurl(key: &[u8]) -> Vec<u8> {
    key.iter()
        .map(|b| match b {
            b'-' => b'+',
            b'_' => b'/',
            other => *other,
        })
        .collect()
}

/// The libsodium Ed25519 secret key a configured private key stands for.
fn ed25519_secret_key(private_key: &str) -> Result<Vec<u8>> {
    let key = php_std::string::trim(private_key.as_bytes(), TRIM);
    let source = if php::contains(key, b"-----BEGIN") { pem_body(key) } else { unurl(key) };
    let decoded = php_std::encoding::base64_decode(&source, true)
        .ok_or_else(|| Error::exception("Failed to decode the Entire private key.", 0))?;
    if decoded.len() == 64 {
        return Ok(decoded);
    }
    let seed = if decoded.len() == 32 {
        decoded
    } else {
        let marker = b"\x04\x22\x04\x20";
        let seed = decoded
            .windows(4)
            .position(|w| w == marker)
            .map(|at| decoded[at + 4..(at + 36).min(decoded.len())].to_vec())
            .unwrap_or_default();
        if seed.len() != 32 {
            return Err(Error::exception("The Entire private key is not an Ed25519 PKCS#8 key.", 0));
        }
        seed
    };
    jwt::ed25519_secret_key(&seed).ok_or_else(|| Error::exception("The Entire private key is not an Ed25519 key.", 0))
}

/// The raw Ed25519 public key a verification key stands for: SPKI PEM, or
/// base64/base64url raw bytes.
fn ed25519_public_key(signature_key: &[u8]) -> Option<Vec<u8>> {
    let key = php_std::string::trim(signature_key, TRIM);
    if php::contains(key, b"-----BEGIN") {
        let der = php_std::encoding::base64_decode(&pem_body(key), true)?;
        let marker = b"\x03\x21\x00";
        let at = der.windows(3).position(|w| w == marker)?;
        let public = der[at + 3..(at + 35).min(der.len())].to_vec();
        return (public.len() == 32).then_some(public);
    }
    php_std::encoding::base64_decode(&unurl(key), true).filter(|d| d.len() == 32)
}

/// A JWT split into its decoded header and claims, raw signature and
/// signing input. Nothing is verified.
struct Jwt {
    header: Value,
    claims: Value,
    signature: Vec<u8>,
    input: Vec<u8>,
}

fn decode_jwt(token: &[u8]) -> Result<Jwt> {
    let token = php_std::string::trim(token, TRIM);
    let segments: Vec<&[u8]> = token.split(|b| *b == b'.').collect();
    let [header, claims, signature] = segments.as_slice() else {
        return Err(Error::exception("Token is not a JWT", 0));
    };
    let header_value = php::json_decode(&jwt::base64url_decode(header)).unwrap_or(Value::Null);
    let claims_value = php::json_decode(&jwt::base64url_decode(claims)).unwrap_or(Value::Null);
    if !php::is_array(&header_value) || !php::is_array(&claims_value) {
        return Err(Error::exception("Token is malformed", 0));
    }
    let mut input = header.to_vec();
    input.push(b'.');
    input.extend_from_slice(claims);
    Ok(Jwt { header: header_value, claims: claims_value, signature: jwt::base64url_decode(signature), input })
}

/// The token of an `Authorization` header (`Bearer <token>`) or a bare one.
fn bearer_token(authorization: &[u8]) -> Vec<u8> {
    let authorization = php_std::string::trim(authorization, TRIM);
    if authorization.len() >= 7 && authorization[..7].eq_ignore_ascii_case(b"Bearer ") {
        return php_std::string::trim(&authorization[7..], TRIM).to_vec();
    }
    authorization.to_vec()
}

/// The owner and name a repository path carries (`et/{project}/{repo}`).
fn split_path(path: &str) -> (String, String) {
    let mut path = path.trim_matches('/');
    if let Some(stripped) = path.strip_suffix(".git") {
        path = stripped;
    }
    let mut segments: Vec<&str> = path.split('/').collect();
    if segments.len() >= 3 {
        segments = segments[segments.len() - 2..].to_vec();
    }
    if segments.len() != 2 {
        return (String::new(), String::new());
    }
    (segments[0].to_owned(), segments[1].to_owned())
}

/// The short names of listed refs.
fn ref_names(refs: &[Value]) -> Vec<Value> {
    let mut names = Vec::new();
    for reference in refs {
        let mut name = match reference {
            Value::Array(_) => text(reference, "name"),
            Value::Str(_) => php::text(&php::string(reference)),
            _ => String::new(),
        };
        for prefix in ["refs/heads/", "refs/tags/"] {
            if let Some(stripped) = name.strip_prefix(prefix) {
                name = stripped.to_owned();
            }
        }
        if !name.is_empty() {
            names.push(s(name));
        }
    }
    names
}

/// `mb_strimwidth($line, 0, $width, $marker)` for a one-column marker.
fn strimwidth(line: &[u8], width: usize, marker: &str) -> Vec<u8> {
    let whole = php_std::mb::mb_strimwidth(line, usize::MAX);
    let fits = php_std::mb::mb_strimwidth(line, width);
    if fits.len() == whole.len() {
        return fits.into_owned();
    }
    let mut out = php_std::mb::mb_strimwidth(line, width.saturating_sub(marker.chars().count())).into_owned();
    out.extend_from_slice(marker.as_bytes());
    out
}

/// A discussion title: the comment's first line of text.
fn comment_title(comment: &str) -> Vec<u8> {
    for line in comment.split('\n') {
        let cleaned: String = line.chars().filter(|c| !matches!(c, '#' | '*' | '_' | '`' | '|' | '>')).collect();
        let line = php_std::string::trim(cleaned.as_bytes(), TRIM);
        if !line.is_empty() {
            return strimwidth(line, 80, "\u{2026}");
        }
    }
    b"Comment".to_vec()
}

/// The trail number and discussion id a comment id carries.
fn parse_comment_id(comment_id: &str) -> Result<(i64, String)> {
    let Some(separator) = comment_id.find(COMMENT_SEPARATOR) else {
        return Err(Error::exception("Comment id does not name a trail", 0));
    };
    Ok((php::int(&s(&comment_id[..separator])), comment_id[separator + 1..].to_owned()))
}

/// A trail in the pull request shape.
fn normalize_trail(trail: &Value) -> Value {
    let status = text(trail, "status");
    object([
        ("id", s(text(trail, "id"))),
        ("number", Value::Int(number(trail, "number"))),
        ("title", s(text(trail, "title"))),
        ("body", s("")),
        ("state", s(if status == "closed" { "closed" } else { "open" })),
        ("draft", Value::Bool(status == "draft")),
        ("merged", Value::Bool(!empty(&at(trail, "merged_at")))),
        ("head", object([("ref", s(text(trail, "branch"))), ("sha", s(text(trail, "head_sha")))])),
        ("base", object([("ref", s(text(trail, "base"))), ("sha", s(text(trail, "base_sha")))])),
        ("merge_base_sha", s(text(trail, "merge_base_sha"))),
    ])
}

impl Entire {
    /// An adapter on the `https://us.auth.entire.io` core.
    pub fn new(cache: Arc<Cache>) -> Self {
        Entire {
            cache,
            transport: Transport::new("https://us.auth.entire.io/api/v1"),
            core_endpoint: "https://us.auth.entire.io".into(),
            issuer: String::new(),
            web_endpoint: "https://entire.io".into(),
            plugin: String::new(),
            installation_id: String::new(),
            jwt_token: String::new(),
            access_token: String::new(),
            jwks: Mutex::new(HashMap::new()),
            repositories: Mutex::new(None),
            data_endpoints: Mutex::new(HashMap::new()),
            regional_repositories: Mutex::new(HashMap::new()),
        }
    }

    /// Points the adapter at another control plane (another jurisdiction,
    /// or a local development core).
    ///
    /// PHP: `setEndpoint()`.
    pub fn set_endpoint(&mut self, endpoint: &str) {
        self.core_endpoint = rtrim_slash(endpoint);
        self.transport.endpoint = format!("{}/api/v1", self.core_endpoint);
    }

    /// The issuer registered on the plugin: the `iss` of its JWTs and the
    /// audience of the receipts Entire signs for it.
    ///
    /// PHP: `setIssuer()`.
    pub fn set_issuer(&mut self, issuer: &str) {
        self.issuer = issuer.to_owned();
    }

    /// A call with Entire's RFC 9457 problem documents decoded.
    async fn call_at(&self, base: Option<&str>, call: Call) -> Result<Response> {
        let decode = call.decode;
        let mut response = match base {
            Some(base) => self.transport.call_at(&rtrim_slash(base), call).await?,
            None => self.transport.call(call).await?,
        };
        let content_type = response.header("content-type").unwrap_or("").to_ascii_lowercase();
        if decode
            && content_type.starts_with("application/problem+json")
            && let Value::Str(raw) = &response.body
            && let Some(problem @ Value::Array(_)) = php::json_decode(raw.as_bytes())
        {
            response.body = problem;
        }
        Ok(response)
    }

    /// The EdDSA plugin JWT the core takes.
    fn plugin_jwt(&self, private_key: &str, plugin: &str) -> Result<String> {
        let secret = ed25519_secret_key(private_key)?;
        let iat = time::now() - PLUGIN_JWT_SKEW;
        let header = object([("alg", s("EdDSA")), ("typ", s("JWT"))]);
        let claims = object([
            ("iss", s(&self.issuer)),
            ("aud", s(format!("{}/api/authz/sts", self.core_endpoint))),
            ("plugin_id", s(plugin)),
            ("installation_id", s(&self.installation_id)),
            ("iat", Value::Int(iat)),
            ("exp", Value::Int(iat + PLUGIN_JWT_EXPIRY)),
        ]);
        let input = format!(
            "{}.{}",
            jwt::base64url(php::json_encode(&header).unwrap_or_default().as_bytes()),
            jwt::base64url(php::json_encode(&claims).unwrap_or_default().as_bytes())
        );
        let signature = jwt::ed25519_sign(&secret, input.as_bytes()).ok_or_else(|| {
            Error::exception("The Entire private key does not hold a consistent Ed25519 key pair.", 0)
        })?;
        Ok(format!("{input}.{}", jwt::base64url(&signature)))
    }

    /// Signs the plugin JWT and exchanges it for an installation token with
    /// every registered permission (contents alone when refused more).
    async fn generate_access_token(&mut self, private_key: &str, plugin: Option<&str>) -> Result<()> {
        let plugin = match plugin {
            Some(p) if php::filled(p) => p,
            _ => return Err(Error::exception("Entire requires the plugin slug to sign the plugin JWT.", 0)),
        };
        self.jwt_token = self.plugin_jwt(private_key, plugin)?;
        let path = format!("/plugins/{}/installations/{}/access-tokens", raw(plugin), raw(&self.installation_id));
        let bearer = format!("Bearer {}", self.jwt_token);
        let permissions = object([("contents", s("read")), ("checks", s("write"))]);
        let mut response =
            self.call_at(None, Call::post(&path).auth(&bearer).params(object([("permissions", permissions)]))).await?;
        if response.status == 400 && text(&body(&response), "code") == "unsupported_permission" {
            response = self
                .call_at(
                    None,
                    Call::post(&path)
                        .auth(&bearer)
                        .params(object([("permissions", object([("contents", s("read"))]))])),
                )
                .await?;
        }
        let response_body = body(&response);
        let has_token = matches!(&response_body, Value::Array(a) if a.contains_key(&php_std::ArrayKey::from("token")));
        if !has_token {
            let kept: Array = match &response_body {
                Value::Array(fields) => fields
                    .iter()
                    .filter(|(k, _)| matches!(k, php_std::types::KeyRef::Str(b"code" | b"title" | b"detail")))
                    .map(|(k, v)| (k.to_owned(), v.clone()))
                    .collect(),
                _ => Array::new(),
            };
            let safe = php::json_encode(&Value::Array(kept)).unwrap_or_default();
            return Err(Error::exception(
                format!(
                    "Failed to retrieve access token from Entire API. Status: {}. Response: {safe}",
                    response.status
                ),
                response.status,
            ));
        }
        self.access_token = text(&response_body, "token");
        let expires_at = php_std::datetime::strtotime(
            text(&response_body, "expires_at").as_bytes(),
            time::now(),
            &php_std::datetime::TzInfo::utc(),
        )
        .unwrap_or(0);
        let cached = object([("accessToken", s(&self.access_token)), ("expiresAt", Value::Int(expires_at))]);
        let cached = php::json_encode(&cached).unwrap_or_else(|| "{}".into());
        self.cache.save(&format!("entire-{}", self.installation_id), &s(cached), "", 0).await?;
        Ok(())
    }

    /// The URL of the plugin's installation page on Entire; Entire answers
    /// on `redirect_uri` with an installation receipt
    /// ([`Entire::verify_receipt`]).
    ///
    /// PHP: `getInstallUrl()`.
    pub fn install_url(&self, plugin: &str, redirect_uri: &str, state: &str) -> String {
        let mut params = serde_json::Map::new();
        if php::filled(redirect_uri) {
            params.insert("redirect_uri".into(), redirect_uri.into());
        }
        if php::filled(state) {
            params.insert("state".into(), state.into());
        }
        let url = format!("{}/plugins/{}/install", self.web_endpoint, raw(plugin));
        if params.is_empty() {
            return url;
        }
        let query = php_std::encoding::http_build_query(
            &serde_json::Value::Object(params),
            b"",
            None,
            php_std::encoding::QueryEncoding::Rfc1738,
        );
        format!("{url}?{}", String::from_utf8_lossy(&query))
    }

    /// Verifies an installation receipt against the signing keys of the
    /// core that issued it and returns its claims.
    ///
    /// PHP: `verifyReceipt()`.
    pub async fn verify_receipt(&self, receipt: &str, plugin: &str) -> Result<Value> {
        let token = match decode_jwt(receipt.as_bytes()) {
            Ok(token) => token,
            Err(e) => {
                let message = php::text(&e.message());
                let mut chars = message.chars();
                let lowered = match chars.next() {
                    Some(first) => first.to_ascii_lowercase().to_string() + chars.as_str(),
                    None => String::new(),
                };
                return Err(Error::exception(format!("Installation receipt {lowered}"), e.code()));
            }
        };
        let Jwt { header, claims, signature, input } = token;
        if !php::is_str(&at(&header, "alg"), "EdDSA") {
            return Err(Error::exception("Installation receipt has an unexpected algorithm", 0));
        }
        if !php::is_str(&at(&header, "typ"), "entire-install-receipt+jwt") {
            return Err(Error::exception("Installation receipt has an unexpected type", 0));
        }
        let issuer = rtrim_slash(&text(&claims, "iss"));
        if !self.is_trusted_core(&issuer) {
            return Err(Error::exception("Installation receipt issuer is not an Entire core", 0));
        }
        let key = self.signing_key(&issuer, &text(&header, "kid")).await?;
        if signature.len() != 64 || !jwt::ed25519_verify(&key, &input, &signature) {
            return Err(Error::exception("Installation receipt signature is invalid", 0));
        }
        let audience = match at(&claims, "aud") {
            Value::Array(a) => a.values().cloned().collect::<Vec<_>>(),
            Value::Null => vec![s("")],
            other => vec![other],
        };
        if self.issuer.is_empty() || !audience.iter().any(|a| php::is_str(a, &self.issuer)) {
            return Err(Error::exception("Installation receipt audience does not include the registered audience", 0));
        }
        let named = match at(&claims, "plugin_id") {
            Value::Null => s(""),
            other => other,
        };
        if !php::is_str(&named, plugin) {
            return Err(Error::exception("Installation receipt does not name this plugin", 0));
        }
        let now = time::now();
        if php::isset(&claims, "exp") && now >= number(&claims, "exp").saturating_add(LEEWAY) {
            return Err(Error::exception("Installation receipt has expired", 0));
        }
        if php::isset(&claims, "nbf") && now < number(&claims, "nbf").saturating_sub(LEEWAY) {
            return Err(Error::exception("Installation receipt is not yet valid", 0));
        }
        if php::isset(&claims, "iat") && now < number(&claims, "iat").saturating_sub(LEEWAY) {
            return Err(Error::exception("Installation receipt is issued in the future", 0));
        }
        if empty(&at(&claims, "installation_id")) {
            return Err(Error::exception("Installation receipt is missing the installation ID", 0));
        }
        Ok(claims)
    }

    /// Whether an origin is one of Entire's cores, or the configured one.
    ///
    /// PHP: `isTrustedCore()`.
    pub fn is_trusted_core(&self, origin: &str) -> bool {
        let origin = rtrim_slash(origin);
        !origin.is_empty() && (origin == self.core_endpoint || CORES.contains(&origin.as_str()))
    }

    /// The active Ed25519 signing keys of an Entire origin (the core when
    /// `issuer` is empty) as base64url key material; `refresh` fetches them
    /// again.
    ///
    /// PHP: `getSigningKeys()`.
    pub async fn signing_keys(&self, issuer: &str, refresh: bool) -> Result<Vec<Value>> {
        let issuer = if issuer.is_empty() { self.core_endpoint.clone() } else { rtrim_slash(issuer) };
        let keys = self.jwks(&issuer, refresh).await?;
        Ok(keys.iter().map(|key| s(text(key, "x"))).collect())
    }

    /// The published Ed25519 JWKS entries of an origin, memoized.
    async fn jwks(&self, issuer: &str, refresh: bool) -> Result<Vec<Value>> {
        if !refresh && let Some(keys) = lock(&self.jwks).get(issuer) {
            return Ok(keys.clone());
        }
        let response = self.call_at(Some(issuer), Call::get("/.well-known/jwks.json")).await?;
        let keys: Vec<Value> = each(&nested(&body(&response), "keys"))
            .into_iter()
            .filter(|key| {
                php::is_array(key)
                    && php::is_str(&at(key, "kty"), "OKP")
                    && php::is_str(&at(key, "crv"), "Ed25519")
                    && !empty(&at(key, "x"))
            })
            .collect();
        lock(&self.jwks).insert(issuer.to_owned(), keys.clone());
        Ok(keys)
    }

    /// The raw public key of a JWKS key id of an origin.
    async fn signing_key(&self, issuer: &str, kid: &str) -> Result<Vec<u8>> {
        for key in self.jwks(issuer, false).await? {
            if text(&key, "kid") != kid {
                continue;
            }
            if let Some(public) = ed25519_public_key(text(&key, "x").as_bytes()) {
                return Ok(public);
            }
        }
        Err(Error::exception("Token is signed with an unknown key", 0))
    }

    /// The unverified claims of a delivery token, plus its header's key id
    /// as `kid`.
    ///
    /// PHP: `getWebhookClaims()`.
    pub fn webhook_claims(&self, authorization: &str) -> Result<Value> {
        let token = decode_jwt(&bearer_token(authorization.as_bytes()))?;
        let mut claims = token.claims;
        php::set(&mut claims, "kid", s(text(&token.header, "kid")));
        Ok(claims)
    }

    /// Whether a delivery issuer belongs to the configured Entire
    /// deployment: the core's domain or a host beneath it, over HTTPS (or
    /// the core's own scheme).
    ///
    /// PHP: `isTrustedIssuer()`.
    pub fn is_trusted_issuer(&self, issuer: &str) -> bool {
        let issuer = rtrim_slash(issuer);
        let Some(parts) = php_std::url::parse_url(issuer.as_bytes()) else {
            return false;
        };
        let host = php::lower(&parts.host().map(|h| h.into_owned()).unwrap_or_default());
        let scheme = php::lower(&parts.scheme().map(|h| h.into_owned()).unwrap_or_default());
        let filled = |v: Option<std::borrow::Cow<'_, [u8]>>| v.is_some_and(|v| !v.is_empty() && v.as_ref() != b"0");
        if host.is_empty() || filled(parts.path()) || filled(parts.query()) {
            return false;
        }
        let core_scheme = php_std::url::parse_url(self.core_endpoint.as_bytes())
            .map(|core| php::lower(&core.scheme().map(|h| h.into_owned()).unwrap_or_default()))
            .unwrap_or_default();
        if scheme != b"https" && scheme != core_scheme {
            return false;
        }
        let domain = self.trusted_domain();
        let mut suffix = b".".to_vec();
        suffix.extend_from_slice(&domain);
        host == domain || host.ends_with(&suffix)
    }

    /// The registrable domain of the core (its last two labels).
    fn trusted_domain(&self) -> Vec<u8> {
        let host = php_std::url::parse_url(self.core_endpoint.as_bytes())
            .map(|core| php::lower(&core.host().map(|h| h.into_owned()).unwrap_or_default()))
            .unwrap_or_default();
        let labels: Vec<&[u8]> = host.split(|b| *b == b'.').collect();
        labels[labels.len().saturating_sub(2)..].join(&b'.')
    }

    /// The event type a delivery announces (`ref.updated`,
    /// `installation.deleted`): Entire types deliveries in the body.
    ///
    /// PHP: `getEventType()`.
    pub fn event_type(&self, payload: &[u8]) -> Result<String> {
        let decoded = match php::json_decode(payload) {
            Some(v @ Value::Array(_)) => v,
            _ => return Err(Error::exception("Invalid payload.", 0)),
        };
        Ok(text(&nested(&decoded, "event"), "type"))
    }

    fn ref_events(&self, kind: &str, payload: &Value, delivery: &[(&str, String); 3]) -> Vec<Value> {
        let reference = text(payload, "ref");
        let Some(branch) = reference.strip_prefix("refs/heads/") else {
            return Vec::new();
        };
        let repository = nested(payload, "repository");
        let (owner, repository_name) = split_path(&text(&repository, "path"));
        let repository_url = if !owner.is_empty() && !repository_name.is_empty() {
            self.repository_url(&owner, &repository_name)
        } else {
            String::new()
        };
        let deleted = kind == EVENT_REF_DELETED;
        let commit_hash = if deleted { String::new() } else { text(payload, "after") };
        let account = nested(&nested(payload, "pusher"), "account");
        let branch_url = if !repository_url.is_empty() && !branch.is_empty() {
            self.branch_url(&owner, &repository_name, branch)
        } else {
            String::new()
        };
        let head_commit_url = if !repository_url.is_empty() && !commit_hash.is_empty() {
            self.commit_url(&owner, &repository_name, &commit_hash)
        } else {
            String::new()
        };
        vec![object([
            ("branchCreated", Value::Bool(kind == EVENT_REF_CREATED)),
            ("branchDeleted", Value::Bool(deleted)),
            ("branch", s(branch)),
            ("branchUrl", s(branch_url)),
            ("repositoryId", s(text(&repository, "id"))),
            ("repositoryName", s(&repository_name)),
            ("repositoryUrl", s(&repository_url)),
            ("installationId", s(&delivery[1].1)),
            ("deliveryId", s(&delivery[0].1)),
            ("apiUrl", s(&delivery[2].1)),
            ("defaultBranch", s(text(&repository, "default_branch"))),
            ("commitHash", s(&commit_hash)),
            ("previousCommitHash", s(text(payload, "before"))),
            ("forced", Value::Bool(truthy(&at(payload, "forced")))),
            ("owner", s(&owner)),
            ("authorUrl", s("")),
            ("authorAvatarUrl", s("")),
            ("headCommitAuthorName", s(text(&account, "handle"))),
            ("headCommitAuthorEmail", s("")),
            ("headCommitMessage", s("")),
            ("headCommitUrl", s(head_commit_url)),
            ("external", Value::Bool(false)),
            ("pullRequestNumber", s("")),
            ("action", s("")),
            ("affectedFiles", empty_array()),
        ])]
    }

    /// Names the data-plane origin serving a repository, as a delivery
    /// reports it.
    ///
    /// PHP: `setRepositoryEndpoint()`.
    pub fn set_repository_endpoint(&self, repository_id: &str, api_base_url: &str) {
        let api_base_url = rtrim_slash(api_base_url);
        if !repository_id.is_empty() && !api_base_url.is_empty() {
            lock(&self.data_endpoints).insert(repository_id.to_owned(), api_base_url);
        }
    }

    /// Every repository the installation covers, normalized and memoized.
    async fn installation_repositories(&self) -> Result<Vec<Value>> {
        if let Some(repositories) = lock(&self.repositories).clone() {
            return Ok(repositories);
        }
        let mut repositories = Vec::new();
        let mut cursor = String::new();
        let path = format!("/plugins/{}/installations/{}/repositories", raw(&self.plugin), raw(&self.installation_id));
        loop {
            let mut call = Call::get(&path).auth(format!("Bearer {}", self.jwt_token));
            if !cursor.is_empty() {
                call = call.params(object([("cursor", s(&cursor))]));
            }
            let response = self.call_at(None, call).await?;
            if response.status >= 400 {
                return Err(request_failed("Failed to list installation repositories", &response));
            }
            let response_body = body(&response);
            for repository in each(&nested(&response_body, "repositories")) {
                if php::is_array(&repository) {
                    repositories.push(self.normalize_repository(&repository));
                }
            }
            let next = text(&response_body, "next_cursor");
            if next == cursor {
                break;
            }
            cursor = next;
            if cursor.is_empty() {
                break;
            }
        }
        *lock(&self.repositories) = Some(repositories.clone());
        Ok(repositories)
    }

    /// A plugin repository reshaped: its project is the organization, its
    /// processing-primary placement the cell serving it.
    fn normalize_repository(&self, repository: &Value) -> Value {
        let full_name = text(repository, "full_name");
        let (owner, name) = split_path(&full_name);
        let id = text(repository, "id");
        let mut api_base_url = String::new();
        for placement in each(&nested(repository, "placements")) {
            if !php::is_array(&placement) {
                continue;
            }
            let candidate = rtrim_slash(&text(&placement, "api_base_url"));
            if candidate.is_empty() {
                continue;
            }
            api_base_url = candidate;
            if php::is_str(&at(&placement, "role"), "processing_primary") {
                break;
            }
        }
        if !id.is_empty() && !api_base_url.is_empty() {
            lock(&self.data_endpoints).entry(id.clone()).or_insert(api_base_url);
        }
        object([
            ("id", s(&id)),
            ("name", s(name)),
            ("full_name", s(&full_name)),
            ("organization", s(owner)),
            ("clone_url", s(text(repository, "clone_url"))),
            ("entire_clone_url", s(text(repository, "entire_clone_url"))),
            ("private", Value::Bool(true)),
            ("pushed_at", s("")),
        ])
    }

    /// The repository of the installation under this owner and name.
    async fn find_repository(&self, owner: &str, repository_name: &str) -> Result<Value> {
        let owner = php::lower(owner.as_bytes());
        let name = php::lower(repository_name.as_bytes());
        for repository in self.installation_repositories().await? {
            if php::lower(text(&repository, "organization").as_bytes()) == owner
                && php::lower(text(&repository, "name").as_bytes()) == name
            {
                return Ok(repository);
            }
        }
        Err(Error::RepositoryNotFound("Repository not found.".into()))
    }

    async fn repository_id(&self, owner: &str, repository_name: &str) -> Result<String> {
        Ok(text(&self.find_repository(owner, repository_name).await?, "id"))
    }

    /// The data-plane origin serving a repository.
    async fn data_endpoint(&self, repository_id: &str) -> Result<String> {
        if !lock(&self.data_endpoints).contains_key(repository_id) {
            self.installation_repositories().await?;
        }
        lock(&self.data_endpoints)
            .get(repository_id)
            .cloned()
            .ok_or_else(|| Error::RepositoryNotFound("Repository has no reachable placement.".into()))
    }

    /// A call against the cell serving a repository, with the installation
    /// token.
    async fn data_call(&self, repository_id: &str, call: Call) -> Result<Response> {
        let base = format!("{}/api/v1", self.data_endpoint(repository_id).await?);
        self.call_at(Some(&base), call.auth(format!("Bearer {}", self.access_token))).await
    }

    /// The serving cell's record of a repository, memoized.
    async fn regional_repository(&self, repository_id: &str) -> Result<Value> {
        if let Some(record) = lock(&self.regional_repositories).get(repository_id) {
            return Ok(record.clone());
        }
        let response = self.data_call(repository_id, Call::get(format!("/repos/{}", raw(repository_id)))).await?;
        if response.status == 404 {
            return Err(Error::RepositoryNotFound("Repository not found.".into()));
        }
        if response.status >= 400 {
            return Err(request_failed("Failed to get repository", &response));
        }
        let record = body(&response);
        lock(&self.regional_repositories).insert(repository_id.to_owned(), record.clone());
        Ok(record)
    }

    async fn git_repository_id(&self, repository_id: &str) -> Result<String> {
        let id = text(&self.regional_repository(repository_id).await?, "id");
        Ok(if id.is_empty() { repository_id.to_owned() } else { id })
    }

    async fn default_branch(&self, repository_id: &str) -> Result<String> {
        Ok(text_or(&self.regional_repository(repository_id).await?, "default_branch", "main"))
    }

    /// `/git/repos/<the cell's id><rest>`.
    async fn git_path(&self, repository_id: &str, rest: &str) -> Result<String> {
        Ok(format!("/git/repos/{}{rest}", raw(&self.git_repository_id(repository_id).await?)))
    }

    /// Every page of a token-paginated data-plane collection.
    async fn collect_pages(&self, repository_id: &str, path: &str, field: &str) -> Result<Vec<Value>> {
        let mut items = Vec::new();
        let mut page_token = String::new();
        loop {
            let mut query = object([("pageSize", Value::Int(100))]);
            if !page_token.is_empty() {
                php::set(&mut query, "pageToken", s(&page_token));
            }
            let response = self.data_call(repository_id, Call::get(path).params(query)).await?;
            if response.status >= 400 {
                return Err(request_failed(&format!("Failed to list {field}"), &response));
            }
            let response_body = body(&response);
            items.extend(each(&nested(&response_body, field)));
            let next = text(&response_body, "nextPageToken");
            if next == page_token {
                break;
            }
            page_token = next;
            if page_token.is_empty() {
                break;
            }
        }
        Ok(items)
    }

    async fn branch_refs(&self, owner: &str, repository_name: &str, kind: &str) -> Result<Vec<Value>> {
        let repository_id = self.repository_id(owner, repository_name).await?;
        let path = self.git_path(&repository_id, &format!("/{kind}")).await?;
        self.collect_pages(&repository_id, &path, kind).await
    }

    fn normalize_commit(&self, commit: &Value, owner: &str, repository_name: &str) -> Value {
        let author = nested(commit, "author");
        let sha = text(commit, "sha");
        let url = if sha.is_empty() { String::new() } else { self.commit_url(owner, repository_name, &sha) };
        object([
            ("commitAuthor", s(text_or(&author, "name", "Unknown"))),
            ("commitAuthorEmail", s(text(&author, "email"))),
            ("commitMessage", s(text_or(commit, "message", "No message"))),
            ("commitAuthorAvatar", s("")),
            ("commitAuthorUrl", s("")),
            ("commitHash", s(&sha)),
            ("commitUrl", s(url)),
        ])
    }

    /// The files that differ between two commits, as
    /// [`Adapter::pull_request_files`] reports them.
    ///
    /// PHP: `compareFiles()`.
    pub async fn compare_files(
        &self,
        owner: &str,
        repository_name: &str,
        base: &str,
        head: &str,
    ) -> Result<Vec<Value>> {
        let repository_id = self.repository_id(owner, repository_name).await?;
        let path = self.git_path(&repository_id, "/compare").await?;
        let response = self
            .data_call(&repository_id, Call::get(path).params(object([("base", s(base)), ("head", s(head))])))
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to compare commits", &response));
        }
        Ok(each(&nested(&body(&response), "files"))
            .iter()
            .filter(|file| php::is_array(file))
            .map(|file| {
                object([
                    ("filename", s(text(file, "path"))),
                    ("status", s(text(file, "status"))),
                    ("previous_filename", s(text(file, "previousPath"))),
                ])
            })
            .collect())
    }

    /// The message that opened a discussion.
    async fn opening_message(&self, repository_id: &str, comment_id: &str) -> Result<Value> {
        let (number, discussion_id) = parse_comment_id(comment_id)?;
        let response = self
            .data_call(
                repository_id,
                Call::get(format!("/repos/{}/trails/{number}/discussions/{}", raw(repository_id), raw(&discussion_id))),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to get comment", &response));
        }
        let message = nested_index(&nested(&body(&response), "messages"), 0);
        if empty(&at(&message, "id")) {
            return Err(Error::exception("Discussion has no opening message.", 0));
        }
        Ok(message)
    }

    /// The check run write for one report: a suite and its single run.
    fn check_runs(
        &self,
        branch: &str,
        state: &str,
        name: &str,
        title: &str,
        summary: &str,
        details_url: &str,
    ) -> Value {
        let key = if php::filled(name) { name } else { "default" };
        let now = time::rfc3339_micros_now();
        let (status, conclusion) = CHECK_STATES
            .iter()
            .find(|(s, _, _)| *s == state)
            .map(|(_, status, conclusion)| (*status, *conclusion))
            .unwrap_or(("completed", Some("failure")));
        let mut run = object([
            ("key", s(key)),
            ("name", s(key)),
            ("status", s(status)),
            ("external_id", s(key)),
            ("external_updated_at", s(&now)),
        ]);
        let mut suite = run.clone();
        if let Some(conclusion) = conclusion {
            php::set(&mut run, "conclusion", s(conclusion));
            php::set(&mut suite, "conclusion", s(conclusion));
            php::set(&mut run, "completed_at", s(&now));
            php::set(&mut suite, "completed_at", s(&now));
        }
        if !title.is_empty() {
            let trimmed = php_std::mb::mb_strimwidth(title.as_bytes(), 255).into_owned();
            php::set(
                &mut run,
                "output",
                object([("title", s(trimmed)), ("summary", s(if summary.is_empty() { title } else { summary }))]),
            );
        }
        if let Some(rest) = details_url.strip_prefix("https://")
            && !rest.contains('@')
        {
            php::set(&mut run, "details_url", s(details_url));
            php::set(&mut suite, "details_url", s(details_url));
        }
        object([("branch", s(branch)), ("check_suite", suite), ("check_runs", php::list(vec![run]))])
    }

    /// A branch holding a commit, which a check run write must name.
    async fn branch_containing(&self, repository_id: &str, commit_hash: &str) -> Result<String> {
        let path = self.git_path(repository_id, "/branches-containing").await?;
        let response = self
            .data_call(repository_id, Call::post(path).params(object([("targets", php::list(vec![s(commit_hash)]))])))
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to find the branch of a commit", &response));
        }
        let branches = nested(&body(&response), commit_hash);
        let branch = scalar_text(&at_index(&branches, 0)).unwrap_or_default();
        if branch.is_empty() {
            return Err(Error::exception(format!("No branch contains commit {commit_hash}"), 0));
        }
        Ok(branch.strip_prefix("refs/heads/").map(str::to_owned).unwrap_or(branch))
    }

    /// A trail's page.
    ///
    /// PHP: `getPullRequestUrl()`.
    pub fn pull_request_url(&self, owner: &str, repository_name: &str, number: i64) -> String {
        format!("{}/trails/{number}", self.repository_url(owner, repository_name))
    }

    /// A command run with the installation token where `git-remote-entire`
    /// reads it.
    fn with_token(&self, command: Command) -> Result<Command> {
        if !php::filled(&self.access_token) {
            return Ok(command);
        }
        Ok(Command::and(vec![
            Command::new("export")?.argument(format!("ENTIRE_TOKEN={}", self.access_token))?,
            command,
        ])?)
    }

    async fn execute(&self, command: &Command, action: &str) -> Result<Vec<u8>> {
        let output = utopia_console::execute(command, b"", None, None).await?;
        if output.status != 0 {
            let mut printed = output.stdout.clone();
            printed.push(b'\n');
            printed.extend_from_slice(&output.stderr);
            let mut printed = php_std::string::trim(&printed, TRIM).to_vec();
            if php::filled(&self.access_token) {
                printed = php_std::string::str_replace(self.access_token.as_bytes(), b"***", &printed).0.into_owned();
            }
            let mut message = format!("{action} failed: ").into_bytes();
            message.extend_from_slice(&printed);
            return Err(Error::exception(message, 0));
        }
        Ok(output.stdout)
    }

    async fn try_execute(&self, command: &Command) -> Result<bool> {
        Ok(utopia_console::execute(command, b"", None, None).await?.status == 0)
    }

    async fn temporary_directory(&self) -> Result<String> {
        let directory = format!(
            "{}/utopia-vcs-entire-{}",
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

    fn fetch(&self, directory: &str, remote: &str, reference: &str, depth: bool) -> Result<Command> {
        let mut command = git::git(directory)?.argument("fetch")?.flag("-q")?;
        if depth {
            command = command.option("--depth", "1")?;
        }
        self.with_token(command.argument(remote)?.argument(reference)?)
    }

    fn push(&self, directory: &str, remote: &str, refspec: &str) -> Result<Command> {
        self.with_token(git::git(directory)?.argument("push")?.flag("-q")?.argument(remote)?.argument(refspec)?)
    }
}

#[async_trait]
impl Adapter for Entire {
    fn name(&self) -> &str {
        "entire"
    }

    fn vcs_type(&self) -> VcsType {
        VcsType::Git
    }

    /// `app_id` is the plugin slug; the private key the plugin's Ed25519 key
    /// (PKCS#8 PEM, its base64 body, a base64 seed or libsodium secret key).
    async fn initialize(
        &mut self,
        installation_id: &str,
        private_key: &str,
        app_id: Option<&str>,
        _access_token: Option<&str>,
        _refresh_token: Option<&str>,
    ) -> Result<()> {
        let plugin = match app_id {
            Some(p) if php::filled(p) => p.to_owned(),
            _ => return Err(Error::exception("Entire requires the plugin slug to sign the plugin JWT.", 0)),
        };
        self.installation_id = installation_id.to_owned();
        self.plugin = plugin.clone();
        self.jwt_token = self.plugin_jwt(private_key, &plugin)?;
        let cached = self.cache.load(&format!("entire-{installation_id}"), TOKEN_CACHE_TTL, "").await?;
        let parsed = match cached {
            Some(Value::Str(raw)) => match php::json_decode(raw.as_bytes()) {
                Some(v @ Value::Array(_)) => v,
                _ => empty_array(),
            },
            _ => empty_array(),
        };
        let expires_at = number(&parsed, "expiresAt");
        let access_token = text(&parsed, "accessToken");
        if access_token.is_empty() || (expires_at > 0 && expires_at - 5 * LEEWAY <= time::now()) {
            return self.generate_access_token(private_key, Some(&plugin)).await;
        }
        self.access_token = access_token;
        Ok(())
    }

    async fn user(&self, _username: &str) -> Result<Value> {
        Err(Error::exception("getUser() is not supported by Entire", 0))
    }

    /// The project the installation's repositories belong to.
    async fn owner_name(&self, _installation_id: &str, _repository_id: Option<i64>) -> Result<String> {
        Ok(self.installation_repositories().await?.first().map(|r| text(r, "organization")).unwrap_or_default())
    }

    async fn has_access_to_all_repositories(&self) -> Result<bool> {
        Ok(false)
    }

    async fn search_repositories(&self, owner: &str, page: i64, per_page: i64, search: &str) -> Result<Value> {
        let owner_lower = php::lower(owner.as_bytes());
        let mut repositories = Vec::new();
        for repository in self.installation_repositories().await? {
            if !owner.is_empty() && php::lower(text(&repository, "organization").as_bytes()) != owner_lower {
                continue;
            }
            if !search.is_empty()
                && php_std::string::stripos(text(&repository, "name").as_bytes(), search.as_bytes(), 0)
                    .ok()
                    .flatten()
                    .is_none()
            {
                continue;
            }
            repositories.push(repository);
        }
        let total = repositories.len() as i64;
        let items = php::slice(&repositories, (page - 1).saturating_mul(per_page), per_page);
        Ok(object([("items", php::list(items)), ("total", Value::Int(total))]))
    }

    async fn installation_repository(&self, repository_name: &str) -> Result<Value> {
        let name = php::lower(repository_name.as_bytes());
        for repository in self.installation_repositories().await? {
            if php::lower(text(&repository, "name").as_bytes()) == name {
                return Ok(repository);
            }
        }
        Err(Error::RepositoryNotFound("Repository not found.".into()))
    }

    async fn repository(&self, owner: &str, repository_name: &str) -> Result<Value> {
        let mut repository = self.find_repository(owner, repository_name).await?;
        let default_branch = self.default_branch(&text(&repository, "id")).await?;
        php::set(&mut repository, "default_branch", s(default_branch));
        Ok(repository)
    }

    async fn create_repository(&self, _owner: &str, _repository_name: &str, _private: bool) -> Result<Value> {
        Err(Error::exception("createRepository() is not supported by entire", 0))
    }

    async fn delete_repository(&self, _owner: &str, _repository_name: &str) -> Result<bool> {
        Err(Error::exception("deleteRepository() is not supported by entire", 0))
    }

    async fn pull_request_from_branch(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value> {
        let repository_id = self.repository_id(owner, repository_name).await?;
        let mut cursor = String::new();
        loop {
            let mut params = object([("status", s("open")), ("per_page", Value::Int(100))]);
            if !cursor.is_empty() {
                php::set(&mut params, "cursor", s(&cursor));
            }
            let response = self
                .data_call(&repository_id, Call::get(format!("/repos/{}/trails", raw(&repository_id))).params(params))
                .await?;
            if response.status >= 400 {
                return Err(request_failed("Failed to list trails", &response));
            }
            let response_body = body(&response);
            for trail in each(&nested(&response_body, "items")) {
                if php::is_array(&trail) && text(&trail, "branch") == branch {
                    return Ok(normalize_trail(&trail));
                }
            }
            let next = text(&response_body, "next_cursor");
            if next == cursor {
                break;
            }
            cursor = next;
            if cursor.is_empty() {
                break;
            }
        }
        Ok(empty_array())
    }

    async fn pull_request(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        let repository_id = self.repository_id(owner, repository_name).await?;
        let response = self
            .data_call(&repository_id, Call::get(format!("/repos/{}/trails/{number}", raw(&repository_id))))
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to get trail", &response));
        }
        Ok(normalize_trail(&body(&response)))
    }

    /// The trail's head compared to the base it branched from.
    async fn pull_request_files(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value> {
        let trail = self.pull_request(owner, repository_name, number).await?;
        let head = text(&nested(&trail, "head"), "sha");
        let mut base = text(&trail, "merge_base_sha");
        if base.is_empty() {
            base = text(&nested(&trail, "base"), "sha");
        }
        if base.is_empty() {
            base = text(&nested(&trail, "base"), "ref");
        }
        if head.is_empty() || base.is_empty() {
            return Ok(empty_array());
        }
        Ok(php::list(self.compare_files(owner, repository_name, &base, &head).await?))
    }

    /// Starts a discussion on the trail; the id carries the trail number. A
    /// refusal (403: discussions take a user principal) is no comment.
    async fn create_comment(&self, owner: &str, repository_name: &str, number: i64, comment: &str) -> Result<String> {
        let repository_id = self.repository_id(owner, repository_name).await?;
        let response = self
            .data_call(
                &repository_id,
                Call::post(format!("/repos/{}/trails/{number}/discussions", raw(&repository_id)))
                    .params(object([("title", s(comment_title(comment))), ("body", s(comment))])),
            )
            .await?;
        if response.status == 403 {
            return Ok(String::new());
        }
        if response.status >= 400 {
            return Err(request_failed("Failed to create comment", &response));
        }
        let discussion = nested(&body(&response), "discussion");
        if empty(&at(&discussion, "id")) {
            return Err(Error::exception("Comment creation response is missing discussion ID.", 0));
        }
        Ok(format!("{number}{COMMENT_SEPARATOR}{}", text(&discussion, "id")))
    }

    async fn comment(&self, owner: &str, repository_name: &str, comment_id: &str) -> Result<String> {
        let message = async {
            let repository_id = self.repository_id(owner, repository_name).await?;
            self.opening_message(&repository_id, comment_id).await
        }
        .await;
        Ok(match message {
            Ok(message) => text(&message, "body"),
            Err(_) => String::new(),
        })
    }

    async fn update_comment(
        &self,
        owner: &str,
        repository_name: &str,
        comment_id: &str,
        comment: &str,
    ) -> Result<String> {
        let repository_id = self.repository_id(owner, repository_name).await?;
        let (number, discussion_id) = parse_comment_id(comment_id)?;
        let message = self.opening_message(&repository_id, comment_id).await?;
        let response = self
            .data_call(
                &repository_id,
                Call::new(
                    Method::Patch,
                    format!(
                        "/repos/{}/trails/{number}/discussions/{}/messages/{}",
                        raw(&repository_id),
                        raw(&discussion_id),
                        raw(&text(&message, "id"))
                    ),
                )
                .params(object([("body", s(comment))])),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to update comment", &response));
        }
        Ok(comment_id.to_owned())
    }

    async fn clone_command(
        &self,
        owner: &str,
        repository_name: &str,
        version: &str,
        version_type: &str,
        directory: &str,
        root_directory: &str,
    ) -> Result<Command> {
        let url = Git::repository_clone_url(self, owner, repository_name).await?;
        self.with_token(clone_command(&url, version, version_type, directory, root_directory)?)
    }

    /// The delivery token: an EdDSA JWT bound to the body by `body_sha256`
    /// (base64url SHA-256), checked against an issuer's Ed25519 key (SPKI
    /// PEM, or base64/base64url raw bytes).
    fn validate_webhook_event(&self, payload: &[u8], signature: &[u8], signature_key: &[u8]) -> bool {
        let Some(public) = ed25519_public_key(signature_key) else { return false };
        let Ok(token) = decode_jwt(&bearer_token(signature)) else { return false };
        if !php::is_str(&at(&token.header, "alg"), "EdDSA") {
            return false;
        }
        if token.signature.len() != 64 || !jwt::ed25519_verify(&public, &token.input, &token.signature) {
            return false;
        }
        let now = time::now();
        if !php::isset(&token.claims, "exp") || now >= number(&token.claims, "exp").saturating_add(LEEWAY) {
            return false;
        }
        if php::isset(&token.claims, "iat") && now < number(&token.claims, "iat").saturating_sub(LEEWAY) {
            return false;
        }
        use sha2::Digest as _;
        let digest = jwt::base64url(&sha2::Sha256::digest(payload));
        digest == text(&token.claims, "body_sha256")
    }

    /// A ref delivery yields one event (none for a tag); an installation
    /// delivery its action.
    fn events(&self, event: &str, payload: &[u8]) -> Result<Vec<Value>> {
        let decoded = match php::json_decode(payload) {
            Some(v @ Value::Array(_)) => v,
            _ => return Err(Error::exception("Invalid payload.", 0)),
        };
        let envelope = nested(&decoded, "event");
        let kind = text(&envelope, "type");
        let kind = if php::filled(&kind) { kind } else { event.to_owned() };
        let event_payload = nested(&envelope, "payload");
        let delivery = [
            ("deliveryId", text(&decoded, "delivery_id")),
            ("installationId", text(&decoded, "installation_id")),
            ("apiUrl", rtrim_slash(&text(&decoded, "api_url"))),
        ];
        if [EVENT_REF_CREATED, EVENT_REF_UPDATED, EVENT_REF_DELETED].contains(&kind.as_str()) {
            return Ok(self.ref_events(&kind, &event_payload, &delivery));
        }
        if let Some(action) = kind.strip_prefix("installation.") {
            let installation = nested(&event_payload, "installation");
            return Ok(vec![object([
                ("action", s(action)),
                ("installationId", s(&delivery[1].1)),
                ("deliveryId", s(&delivery[0].1)),
                ("projectId", s(text(&installation, "project_id"))),
                ("repositorySelection", s(text(&installation, "repository_selection"))),
                ("addedRepositoryIds", strings(&installation, "added_repository_ids")),
                ("removedRepositoryIds", strings(&installation, "removed_repository_ids")),
                ("userName", s("")),
            ])]);
        }
        Ok(Vec::new())
    }

    fn event_header_name(&self) -> &str {
        "x-entire-event"
    }

    fn signature_header_name(&self) -> &str {
        "authorization"
    }

    fn supported_webhook_scopes(&self) -> &'static [WebhookScope] {
        &[WebhookScope::Installation]
    }

    fn repository_url(&self, owner: &str, repository_name: &str) -> String {
        format!("{}/et/{owner}/{repository_name}", self.web_endpoint)
    }

    fn organization_url(&self, owner: &str) -> Result<String> {
        Ok(format!("{}/et/{owner}", self.web_endpoint))
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
        for repository in self.installation_repositories().await? {
            if text(&repository, "id") == repository_id {
                return Ok(text(&repository, "name"));
            }
        }
        Err(Error::RepositoryNotFound("Repository not found.".into()))
    }

    /// A missing repository, or one with no commits yet, has no branches.
    async fn list_branches(&self, owner: &str, repository_name: &str) -> Result<Vec<Value>> {
        match self.branch_refs(owner, repository_name, "branches").await {
            Ok(refs) => Ok(ref_names(&refs)),
            Err(_) => Ok(Vec::new()),
        }
    }

    async fn list_tags(&self, owner: &str, repository_name: &str, search: &str) -> Result<Vec<Value>> {
        match self.branch_refs(owner, repository_name, "tags").await {
            Ok(refs) => match_glob(ref_names(&refs), search),
            Err(_) => Ok(Vec::new()),
        }
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
        self.update_check(owner, repository_name, sha, state, context, description, "", target_url).await
    }

    /// A check run upsert keyed by the check's name, in a suite of the same
    /// name, on a branch holding the commit.
    async fn update_check(
        &self,
        owner: &str,
        repository_name: &str,
        sha: &str,
        state: &str,
        name: &str,
        title: &str,
        summary: &str,
        details_url: &str,
    ) -> Result<()> {
        let repository_id = self.repository_id(owner, repository_name).await?;
        let path = format!("/repos/{}/commits/{}/check-runs", raw(&repository_id), raw(sha));
        let branch = self.branch_containing(&repository_id, sha).await?;
        let write = self.check_runs(&branch, state, name, title, summary, details_url);
        let response = self.data_call(&repository_id, Call::post(path).params(write)).await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to update commit status", &response));
        }
        Ok(())
    }

    async fn repository_tree(
        &self,
        owner: &str,
        repository_name: &str,
        branch: &str,
        recursive: bool,
    ) -> Result<Vec<Value>> {
        let repository_id = match self.repository_id(owner, repository_name).await {
            Ok(id) => id,
            Err(Error::RepositoryNotFound(_)) => return Ok(Vec::new()),
            Err(e) => return Err(e),
        };
        let mut params = object([("ref", s(branch))]);
        if recursive {
            php::set(&mut params, "recursive", s("true"));
        }
        let path = self.git_path(&repository_id, "/tree").await?;
        let response = self.data_call(&repository_id, Call::get(path).params(params)).await?;
        if response.status >= 400 {
            return Ok(Vec::new());
        }
        Ok(each(&nested(&body(&response), "entries"))
            .iter()
            .filter(|entry| php::is_array(entry) && !empty(&at(entry, "path")))
            .map(|entry| s(text(entry, "path")))
            .collect())
    }

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
        let repository_id = match self.repository_id(owner, repository_name).await {
            Ok(id) => id,
            Err(Error::RepositoryNotFound(_)) => return Ok(Vec::new()),
            Err(e) => return Err(e),
        };
        let reference =
            if php::filled(reference) { reference.to_owned() } else { self.default_branch(&repository_id).await? };
        let mut params = object([("ref", s(reference))]);
        let path = normalize_repository_path(path);
        if !path.is_empty() {
            php::set(&mut params, "path", s(path));
        }
        let tree = self.git_path(&repository_id, "/tree").await?;
        let response = self.data_call(&repository_id, Call::get(tree).params(params)).await?;
        if response.status >= 400 {
            return Ok(Vec::new());
        }
        let mut contents = Vec::new();
        for entry in each(&nested(&body(&response), "entries")) {
            if !php::is_array(&entry) {
                continue;
            }
            let entry_path = text(&entry, "path");
            let kind = match at(&entry, "type") {
                Value::Null => s("blob"),
                other => other,
            };
            contents.push(object([
                ("name", s(php_std::path::basename(entry_path.as_bytes(), b""))),
                ("size", Value::Int(number(&entry, "size"))),
                ("type", s(if php::is_str(&kind, "tree") { "dir" } else { "file" })),
            ]));
        }
        Ok(contents)
    }

    async fn repository_content(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        reference: &str,
    ) -> Result<Value> {
        let repository_id = match self.repository_id(owner, repository_name).await {
            Ok(id) => id,
            Err(Error::RepositoryNotFound(_)) => return Err(Error::file_not_found()),
            Err(e) => return Err(e),
        };
        let raw_path = self.git_path(&repository_id, "/raw").await?;
        let reference =
            if php::filled(reference) { reference.to_owned() } else { self.default_branch(&repository_id).await? };
        let params = object([("ref", s(reference)), ("path", s(normalize_repository_path(path)))]);
        let response = self.data_call(&repository_id, Call::get(raw_path).params(params).raw()).await?;
        let content = match (&response.body, response.status) {
            (Value::Str(content), 200) => content.clone(),
            _ => return Err(Error::file_not_found()),
        };
        Ok(object([("sha", s("")), ("size", Value::Int(content.len() as i64)), ("content", Value::Str(content))]))
    }

    async fn commit(&self, owner: &str, repository_name: &str, commit_hash: &str) -> Result<Value> {
        let repository_id = self.repository_id(owner, repository_name).await?;
        let path = self.git_path(&repository_id, "/commits").await?;
        let response = self
            .data_call(
                &repository_id,
                Call::get(path).params(object([("ref", s(commit_hash)), ("pageSize", Value::Int(1))])),
            )
            .await?;
        if response.status == 404 {
            return Err(Error::RepositoryNotFound("Commit not found.".into()));
        }
        if response.status >= 400 {
            return Err(Error::exception("Commit not found or inaccessible.", response.status));
        }
        let commit = nested_index(&nested(&body(&response), "commits"), 0);
        if matches!(&commit, Value::Array(a) if a.is_empty()) {
            return Err(Error::RepositoryNotFound("Commit not found.".into()));
        }
        Ok(self.normalize_commit(&commit, owner, repository_name))
    }

    async fn latest_commit(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value> {
        match self.commit(owner, repository_name, branch).await {
            Err(Error::RepositoryNotFound(_)) => Err(Error::RepositoryNotFound(format!("Branch not found: {branch}"))),
            other => other,
        }
    }
}

#[async_trait]
impl Git for Entire {
    /// Entire has no contents-write API: the file is committed locally and
    /// pushed through `git-remote-entire`.
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
        let default_branch = text_or(&repository, "default_branch", "main");
        let target = if php::filled(branch) { branch.to_owned() } else { default_branch.clone() };
        let remote = Git::repository_clone_url(self, owner, repository_name).await?;
        let directory = self.temporary_directory().await?;

        let result = async {
            self.execute(&git::git(&directory)?.argument("init")?.flag("-q")?, "Initializing the working repository")
                .await?;
            let mut has_base =
                self.try_execute(&self.fetch(&directory, &remote, &format!("refs/heads/{target}"), true)?).await?;
            if !has_base && !["", "0", default_branch.as_str()].contains(&branch) {
                has_base = self
                    .try_execute(&self.fetch(&directory, &remote, &format!("refs/heads/{default_branch}"), true)?)
                    .await?;
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
            let hash = php::text(php_std::string::trim(&hash, TRIM));
            self.execute(&self.push(&directory, &remote, &format!("HEAD:refs/heads/{target}"))?, "Pushing the commit")
                .await?;
            Ok(object([("path", s(&relative)), ("branch", s(&target)), ("commitHash", s(hash))]))
        }
        .await;
        self.remove_directory(&directory).await?;
        result
    }

    /// Pushed through `git-remote-entire`: only the ref moves.
    async fn create_branch(
        &self,
        owner: &str,
        repository_name: &str,
        new_branch: &str,
        old_branch: &str,
    ) -> Result<Value> {
        let remote = Git::repository_clone_url(self, owner, repository_name).await?;
        let directory = self.temporary_directory().await?;
        let result = async {
            self.execute(&git::git(&directory)?.argument("init")?.flag("-q")?, "Initializing the working repository")
                .await?;
            self.execute(
                &self.fetch(&directory, &remote, &format!("refs/heads/{old_branch}"), true)?,
                &format!("Fetching branch {old_branch}"),
            )
            .await?;
            let sha = self
                .execute(
                    &git::git(&directory)?.argument("rev-parse")?.argument("FETCH_HEAD")?,
                    "Reading the branch tip",
                )
                .await?;
            let sha = php::text(php_std::string::trim(&sha, TRIM));
            self.execute(
                &self.push(&directory, &remote, &format!("FETCH_HEAD:refs/heads/{new_branch}"))?,
                &format!("Creating branch {new_branch}"),
            )
            .await?;
            Ok(object([("name", s(new_branch)), ("ref", s(format!("refs/heads/{new_branch}"))), ("sha", s(sha))]))
        }
        .await;
        self.remove_directory(&directory).await?;
        result
    }

    /// A trail linked to an existing branch.
    async fn create_pull_request(
        &self,
        owner: &str,
        repository_name: &str,
        title: &str,
        head: &str,
        base: &str,
        body_text: &str,
    ) -> Result<Value> {
        let repository_id = self.repository_id(owner, repository_name).await?;
        let response = self
            .data_call(
                &repository_id,
                Call::post(format!("/repos/{}/trails", raw(&repository_id))).params(object([
                    ("title", s(title)),
                    ("body", s(body_text)),
                    ("base", s(base)),
                    ("branch_name", s(head)),
                    ("branch_action", s("link")),
                    ("status", s("open")),
                ])),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to create trail", &response));
        }
        let response_body = body(&response);
        let trail = nested(&response_body, "trail");
        let empty_trail = matches!(&trail, Value::Array(a) if a.is_empty());
        Ok(normalize_trail(if empty_trail { &response_body } else { &trail }))
    }

    /// Entire delivers webhooks per plugin installation to the URL on the
    /// plugin; repositories carry no hooks.
    async fn create_webhook(
        &self,
        _owner: &str,
        _repository_name: &str,
        _url: &str,
        _secret: &str,
        _events: &[&str],
    ) -> Result<WebhookId> {
        Err(Error::exception(
            "createWebhook() is not supported by Entire. Configure the webhook URL on the Entire plugin.",
            0,
        ))
    }

    /// Pushed through `git-remote-entire`; a message makes an annotated tag.
    async fn create_tag(
        &self,
        owner: &str,
        repository_name: &str,
        tag: &str,
        target: &str,
        message: &str,
    ) -> Result<Value> {
        let remote = Git::repository_clone_url(self, owner, repository_name).await?;
        let directory = self.temporary_directory().await?;
        let result = async {
            self.execute(&git::git(&directory)?.argument("init")?.flag("-q")?, "Initializing the working repository")
                .await?;
            if !self.try_execute(&self.fetch(&directory, &remote, target, true)?).await? {
                self.execute(
                    &self.fetch(&directory, &remote, "refs/heads/*:refs/remotes/origin/*", false)?,
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
                    &self.push(&directory, &remote, &format!("refs/tags/{tag}"))?,
                    &format!("Pushing tag {tag}"),
                )
                .await?;
            } else {
                self.execute(
                    &self.push(&directory, &remote, &format!("{target}:refs/tags/{tag}"))?,
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

    /// The `entire://` address only `git-remote-entire` fetches.
    async fn repository_clone_url(&self, owner: &str, repository_name: &str) -> Result<String> {
        let repository = self.find_repository(owner, repository_name).await?;
        let entire_url = text(&repository, "entire_clone_url");
        if !entire_url.is_empty() {
            return Ok(entire_url);
        }
        let clone_url = text(&repository, "clone_url");
        if let Some(rest) = clone_url.strip_prefix("https://") {
            return Ok(format!("entire://{}", rest.strip_suffix(".git").unwrap_or(rest)));
        }
        Err(Error::exception("Repository reports no clone URL.", 0))
    }

    fn supports_repository_clone_url(&self) -> bool {
        false
    }

    fn push_command(&self, owner: &str, repository_name: &str, directory: &str, branch: &str) -> Result<Command> {
        let _ = (owner, repository_name);
        self.with_token(git::git(directory)?.argument("push")?.argument("origin")?.argument(branch)?)
    }

    fn supports_comment_images(&self) -> bool {
        false
    }

    fn supports_public_repositories(&self) -> bool {
        false
    }

    /// The commit's check runs in commit-status shape.
    async fn commit_statuses(&self, owner: &str, repository_name: &str, commit_hash: &str) -> Result<Vec<Value>> {
        let repository_id = self.repository_id(owner, repository_name).await?;
        let response = self
            .data_call(
                &repository_id,
                Call::get(format!("/repos/{}/commits/{}/check-runs", raw(&repository_id), raw(commit_hash))),
            )
            .await?;
        if response.status >= 400 {
            return Err(request_failed("Failed to list check runs", &response));
        }
        let mut statuses = Vec::new();
        for check_run in each(&nested(&body(&response), "check_runs")) {
            if !php::is_array(&check_run) {
                continue;
            }
            let conclusion = text(&check_run, "conclusion");
            let state = if !php::is_str(&at(&check_run, "status"), "completed") {
                "pending"
            } else if ["success", "neutral", "skipped"].contains(&conclusion.as_str()) {
                "success"
            } else {
                "failure"
            };
            let output = nested(&check_run, "output");
            let key = text(&check_run, "key");
            let context = if php::filled(&key) { key } else { text(&check_run, "name") };
            statuses.push(object([
                ("state", s(state)),
                ("description", s(text(&output, "title"))),
                ("target_url", s(text(&check_run, "details_url"))),
                ("context", s(context)),
            ]));
        }
        Ok(statuses)
    }
}
