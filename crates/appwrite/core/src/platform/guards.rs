//! The hooks a route opts into with its labels and groups (PHP:
//! `app/controllers/shared/api.php`, `app/controllers/shared/api/auth.php`):
//! abuse limits, an active session, MFA freshness, a recent session and the
//! project's authentication methods, in the order PHP runs them after the
//! `api` group's authentication.

use http::StatusCode;
use serde_json::{Map, Value};
use utopia_abuse::TimeLimit;
use utopia_abuse::time_limit::Redis;
use utopia_database::validator::authorization::Input;
use utopia_database::{Document, PermissionType};
use utopia_http::Response;

use super::context::Context;
use crate::auth::roles;
use crate::database::documents::Attributes;
use crate::error::{Error, ErrorType};
use crate::{Result, datetime};

/// `MFA_RECENT_DURATION`: an MFA challenge stays fresh this long.
const MFA_RECENT: i64 = 1800;
/// `SESSION_RECENT_DURATION`: a sign-in (or MFA challenge) stays recent this long.
const SESSION_RECENT: i64 = 600;

/// The `api` group's abuse hook: counts the request against each of the
/// route's `abuse-key`s, reports the closest limit in `X-RateLimit-*`, and
/// refuses it when a limit is exceeded. Keys and privileged users, and every
/// caller when `_APP_OPTIONS_ABUSE=disabled`, are counted without being
/// limited (`peek`).
pub(super) async fn abuse(ctx: &mut Context) -> Result<()> {
    let labels = ctx.route.labels;
    let enforce = ctx.state.config.abuse && !roles::is_key(&ctx.roles) && !roles::is_privileged(&ctx.roles);
    let mut limit = labels.abuse_limit;
    if ctx.state.config.abuse_increased_projects.contains(&ctx.project.id) {
        limit *= 100;
    }
    ctx.abuse_params = abuse_params(ctx);
    let now = datetime::now().and_utc().timestamp();
    let mut closest: Option<i64> = None;
    for key in labels.abuse_key {
        let limited = match TimeLimit::new(*key, limit, labels.abuse_time, Redis::new(ctx.state.abuse.clone())) {
            Ok(time_limit) => {
                let time_limit = time_limit.with_params(ctx.abuse_params.clone());
                let verdict = if enforce { time_limit.check_at(now).await } else { time_limit.peek_at(now).await };
                match verdict {
                    Ok(verdict) => {
                        if verdict.limit != 0 && closest.is_none_or(|c| verdict.remaining < c) {
                            closest = Some(verdict.remaining);
                            ctx.response_headers.push(("x-ratelimit-limit", verdict.limit.to_string()));
                            ctx.response_headers.push(("x-ratelimit-remaining", verdict.remaining.to_string()));
                            ctx.response_headers.push(("x-ratelimit-reset", verdict.reset.to_string()));
                        }
                        enforce && verdict.limited
                    }
                    Err(e) => {
                        tracing::warn!(error = %e, "abuse check failed");
                        continue;
                    }
                }
            }
            Err(e) => {
                tracing::warn!(error = %e, "abuse check failed");
                continue;
            }
        };
        if limited {
            return Err(Error::new(ErrorType::GeneralRateLimitExceeded));
        }
    }
    Ok(())
}

/// The abuse key parameters: the project, the user, the client and the
/// request's non-empty parameters (`{param-<name>}`).
fn abuse_params(ctx: &Context) -> Vec<(String, String)> {
    let chunk = match ctx.request.content_range() {
        Some(r) => {
            let end = r.end.unwrap_or(0);
            let span = end + 1 - r.start;
            if span == 0 { 0 } else { r.start / span }
        }
        None => 0,
    };
    let mut params = vec![
        ("{projectId}".to_owned(), ctx.project.id.clone()),
        ("{userId}".to_owned(), ctx.user.as_ref().map(|u| u.id.clone()).unwrap_or_default()),
        ("{userAgent}".to_owned(), ctx.request.header_or_empty("user-agent").to_owned()),
        ("{ip}".to_owned(), ctx.ip.clone()),
        ("{url}".to_owned(), format!("{}{}", ctx.hostname, ctx.route.path)),
        ("{method}".to_owned(), ctx.request.method().to_string()),
        ("{chunkId}".to_owned(), chunk.to_string()),
    ];
    for (name, value) in ctx.request.params() {
        if php_std::value::empty(value) {
            continue;
        }
        let text = match value {
            Value::String(s) => s.clone(),
            Value::Bool(_) => "1".to_owned(),
            Value::Number(n) => n.to_string(),
            other => crate::json::to_string(other),
        };
        params.push((format!("{{param-{name}}}"), text));
    }
    params
}

/// The `api` group's abuse reset (after the action): a response whose status
/// the route lists in `abuse-reset` clears its limits.
pub(super) async fn abuse_reset(state: &super::State, labels: super::Labels, status: u16, params: &[(String, String)]) {
    if !state.config.abuse || !labels.abuse_reset.contains(&status) {
        return;
    }
    let now = datetime::now().and_utc().timestamp();
    for key in labels.abuse_key {
        let Ok(time_limit) =
            TimeLimit::new(*key, labels.abuse_limit, labels.abuse_time, Redis::new(state.abuse.clone()))
        else {
            continue;
        };
        if let Err(e) = time_limit.with_params(params.to_vec()).reset_at(now).await {
            tracing::warn!(error = %e, "abuse reset failed");
        }
    }
}

/// `APP_STORAGE_CACHE`: the project caches live under it (`app-<projectId>`).
const STORAGE_CACHE: &str = "/storage/cache";
/// How long a cached response is served (180 days).
const CACHE_TTL: i64 = 60 * 60 * 24 * 180;
/// `APP_CACHE_UPDATE`: a hit refreshes the cache entry this often.
const CACHE_UPDATE: i64 = 24 * 60 * 60;
/// `APP_CACHE_BUSTER`.
const CACHE_BUSTER: &str = "4327";
/// `APP_PROJECT_ACCESS`: a file's `transformedAt` is refreshed this often.
const PROJECT_ACCESS: i64 = 24 * 60 * 60;
/// `APP_RESOURCE_TOKEN_ACCESS`.
const RESOURCE_TOKEN_ACCESS: i64 = 24 * 60 * 60;
/// `RESOURCE_TOKEN_MAX_AGE` and `RESOURCE_TOKEN_LEEWAY`.
const RESOURCE_TOKEN_MAX_AGE: i64 = 86_400 * 365 * 10;
const RESOURCE_TOKEN_LEEWAY: i64 = 10;
/// The image preview route, whose cache the plan's image transformations
/// switch also governs.
const PREVIEW: &str = "/v1/storage/buckets/:bucketId/files/:fileId/preview";

/// The file a resource token grants (the `resourceToken` resource).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ResourceToken {
    pub bucket_id: String,
    pub file_id: String,
    pub bucket_internal_id: String,
    pub file_internal_id: String,
}

/// The `api` group's context hook, after the events and audit context
/// (which the shutdown reads from the route): resolves the resource token,
/// and for routes labelled `cache` serves a stored response (`hit`, after
/// the bucket, file and token checks) or marks a `miss` for the action to
/// fill.
pub(super) async fn context(ctx: &mut Context) -> Result<()> {
    let token = resource_token(ctx).await?;
    if !ctx.route.labels.cache {
        return Ok(());
    }
    let roles = ctx.roles.clone();
    let key_user = roles::is_key(&roles);
    let privileged = roles::is_privileged(&roles);
    let preview = ctx.route.path == PREVIEW;
    // `$plan['imageTransformations'] === -1`.
    let disabled = ctx.state.hooks.plan(&ctx.project).is_some_and(|plan| {
        plan.get("imageTransformations").and_then(Value::as_i64) == Some(-1)
            && plan.get("imageTransformations").is_some_and(Value::is_i64)
    }) && !privileged;

    let key = cache_key(
        ctx.request.uri(),
        ctx.request.params(),
        ctx.route.labels.cache_params,
        ctx.request.header_or_empty("x-appwrite-project"),
    );
    let db = ctx.db()?;
    let authorization = db.authorization();
    let log = authorization.skip(db.get_document("cache", &key, Vec::new(), false)).await?;
    let cache =
        utopia_cache::Cache::new(utopia_cache::Filesystem::new(format!("{STORAGE_CACHE}/app-{}", ctx.project.id)));
    let data = cache
        .load(&key, CACHE_TTL, "")
        .await
        .map_err(|e| Error::with_message(ErrorType::GeneralUnknown, e.to_string()))?;
    // `!empty($data)`.
    let data = match data {
        Some(php_std::Value::Str(s)) if !s.as_bytes().is_empty() && s.as_bytes() != b"0" => Some(s.as_bytes().to_vec()),
        _ => None,
    };
    let (Some(data), false) = (data, log.is_empty()) else {
        ctx.response_headers.push(("cache-control", "no-cache, no-store, must-revalidate".to_owned()));
        ctx.response_headers.push(("pragma", "no-cache".to_owned()));
        ctx.response_headers.push(("expires", "0".to_owned()));
        ctx.response_headers.push(("x-appwrite-cache", "miss".to_owned()));
        return Ok(());
    };

    let mut cache_control = format!("private, max-age={CACHE_TTL}");
    let resource_type = log.string_or_empty("resourceType");
    let mut parts = resource_type.split('/');
    if parts.next() == Some("bucket") && (!preview || !disabled) {
        let bucket_id = parts.next().unwrap_or("");
        let bucket = authorization.skip(db.get_document("buckets", bucket_id, Vec::new(), false)).await?;
        let bucket_sequence = crate::database::documents::sequence_text(&bucket);
        let is_token = token.as_ref().is_some_and(|t| t.bucket_internal_id == bucket_sequence);
        if bucket.is_empty() || (bucket.boolean("enabled") != Some(true) && !key_user && !privileged) {
            return Err(Error::new(ErrorType::StorageBucketNotFound));
        }
        if bucket.boolean("transformations") == Some(false) && !key_user && !privileged {
            return Err(Error::new(ErrorType::StorageBucketTransformationsDisabled));
        }
        let file_security = bucket.boolean("fileSecurity").unwrap_or(false);
        let read = bucket.permissions_by_type("read").unwrap_or_default();
        let valid = ctx.authorization.is_valid(&Input::new(PermissionType::Read, read));
        if !file_security && !valid && !is_token {
            return Err(Error::new(ErrorType::UserUnauthorized));
        }
        let resource = log.string_or_empty("resource");
        let file_id = resource.split('/').nth(1).unwrap_or("");
        let collection = format!("bucket_{bucket_sequence}");
        let file = if file_security && !valid && !is_token {
            db.get_document(&collection, file_id, Vec::new(), false).await?
        } else {
            authorization.skip(db.get_document(&collection, file_id, Vec::new(), false)).await?
        };
        let file_sequence = crate::database::documents::sequence_text(&file);
        if token.as_ref().is_some_and(|t| t.file_internal_id != file_sequence) {
            return Err(Error::new(ErrorType::UserUnauthorized));
        }
        if file.is_empty() {
            return Err(Error::new(ErrorType::StorageFileNotFound));
        }
        // A console user's view does not count as a transformation.
        if !privileged {
            let now = datetime::now();
            let stale =
                file.datetime("transformedAt").is_none_or(|at| at < datetime::add_seconds(now, -PROJECT_ACCESS));
            if stale {
                let mut updates = Document::default();
                updates.put("transformedAt", datetime::value(&now));
                let collection = format!("bucket_{}", file.string_or_empty("bucketInternalId"));
                authorization.skip(db.update_document(&collection, file.id(), updates)).await?;
            }
        }
        // `cacheControlForStorage` (CE: the same private max-age).
        if preview {
            cache_control = format!("private, max-age={CACHE_TTL}");
        }
    }

    let now = datetime::now();
    if log.datetime("accessedAt").is_none_or(|at| at < datetime::add_seconds(now, -CACHE_UPDATE)) {
        let mut updates = Document::default();
        updates.put("accessedAt", datetime::value(&now));
        authorization.skip(db.update_document("cache", log.id(), updates)).await?;
        // Refreshes the file's time, which the TTL reads.
        let saved = cache.save(&key, &php_std::Value::Str(php_std::Str::from(data.clone())), "", 0).await;
        saved.map_err(|e| Error::with_message(ErrorType::GeneralUnknown, e.to_string()))?;
    }

    let mime = log.string_or_empty("mimeType");
    if !preview || !disabled {
        let mut response = Response::with_body(StatusCode::OK, &mime, data);
        response.add("cache-control", &cache_control);
        response.add("x-appwrite-cache", "hit");
        ctx.send(response);
    } else {
        ctx.response_headers.push(("cache-control", cache_control));
        ctx.response_headers.push(("x-appwrite-cache", "hit".to_owned()));
    }
    Ok(())
}

/// `Request::cacheIdentifier()`: the MD5 of the URI, the (allowed) params
/// with the project, sorted by key and `serialize()`d, and the cache buster.
pub(super) fn cache_key(uri: &str, params: &Map<String, Value>, allowed: Option<&[&str]>, project: &str) -> String {
    use md5::{Digest, Md5};
    // The params as PHP holds them (`json_decode`, so keys such as `"5"`
    // become integers).
    let json = serde_json::to_vec(params).unwrap_or_default();
    let decoded = php_std::json::decode(&json, Some(true), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE);
    let mut array = match decoded {
        Ok(php_std::Value::Array(array)) => array,
        _ => php_std::Array::new(),
    };
    // `array_intersect_key($params, array_flip($allowed))`.
    if let Some(allowed) = allowed {
        array.retain(|k, _| {
            let name = match k {
                php_std::types::KeyRef::Int(i) => i.to_string(),
                php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
            };
            allowed.contains(&name.as_str())
        });
    }
    let project_key = php_std::ArrayKey::from("project");
    if array.get(&project_key).is_none_or(|v| matches!(v, php_std::Value::Null)) {
        array.set(project_key, php_std::Value::Str(php_std::Str::from(project.as_bytes().to_vec())));
    }
    php_std::sort::ksort(&mut array, php_std::sort::SortFlags::Regular);
    let mut input = Vec::new();
    input.extend_from_slice(uri.as_bytes());
    input.push(b'*');
    input.extend_from_slice(&php_std::serialize::serialize(&php_std::Value::Array(array)));
    input.push(b'*');
    input.extend_from_slice(CACHE_BUSTER.as_bytes());
    hex::encode(Md5::digest(&input))
}

/// The `resourceToken` resource: the file a `token` parameter (a resource
/// token's JWT) grants, refreshing the token's `accessedAt` once a day.
async fn resource_token(ctx: &mut Context) -> Result<Option<ResourceToken>> {
    let Some(Value::String(jwt)) = ctx.request.params().get("token").cloned() else { return Ok(None) };
    if jwt.is_empty() || !ctx.project_found {
        return Ok(None);
    }
    let key = ctx.state.config.openssl_key_v1.clone();
    let Ok(claims) = crate::auth::jwt::decode_with_leeway(&key, &jwt, RESOURCE_TOKEN_MAX_AGE, RESOURCE_TOKEN_LEEWAY)
    else {
        return Ok(None);
    };
    let token_id = match claims.get("tokenId") {
        Some(Value::String(s)) if !s.is_empty() && s != "0" => s.clone(),
        _ => return Ok(None),
    };
    let db = ctx.db()?;
    let authorization = db.authorization();
    let token = authorization.skip(db.get_document("resourceTokens", &token_id, Vec::new(), false)).await?;
    if token.is_empty() {
        return Ok(None);
    }
    let now = datetime::now();
    if token.datetime("expire").is_some_and(|expire| expire < now) {
        return Ok(None);
    }
    if token.string_or_empty("resourceType") != "files" {
        return Err(Error::new(ErrorType::TokenResourceTypeInvalid));
    }
    let sequences = token.string_or_empty("resourceInternalId");
    let ids = token.string_or_empty("resourceId");
    let (Some((bucket_internal_id, file_internal_id)), Some((bucket_id, file_id))) = (two(&sequences), two(&ids))
    else {
        return Ok(None);
    };
    if token.datetime("accessedAt").is_none_or(|at| at < datetime::add_seconds(now, -RESOURCE_TOKEN_ACCESS)) {
        let mut updates = Document::default();
        updates.put("accessedAt", datetime::value(&now));
        authorization.skip(db.update_document("resourceTokens", token.id(), updates)).await?;
    }
    Ok(Some(ResourceToken {
        bucket_id: bucket_id.to_owned(),
        file_id: file_id.to_owned(),
        bucket_internal_id: bucket_internal_id.to_owned(),
        file_internal_id: file_internal_id.to_owned(),
    }))
}

/// `explode(':', $value)` of exactly two parts.
fn two(value: &str) -> Option<(&str, &str)> {
    let mut parts = value.split(':');
    match (parts.next(), parts.next(), parts.next()) {
        (Some(a), Some(b), None) => Some((a, b)),
        _ => None,
    }
}

/// The `session` group: a session is not created for a caller who has one,
/// unless the route is labelled `session.allowActive`.
pub(super) fn session_active(ctx: &Context) -> Result<()> {
    if !ctx.route.has_group("session") || ctx.route.labels.session_allow_active {
        return Ok(());
    }
    if ctx.user.is_some() {
        return Err(Error::new(ErrorType::UserSessionAlreadyExists));
    }
    Ok(())
}

/// The `mfaProtected` group: an MFA challenge completed within
/// `MFA_RECENT_DURATION`.
pub(super) fn mfa_fresh(ctx: &Context) -> Result<()> {
    if !ctx.route.has_group("mfaProtected") {
        return Ok(());
    }
    let now = datetime::now();
    let fresh = ctx
        .session
        .as_ref()
        .and_then(|s| s.mfa_updated_at)
        .is_some_and(|at| datetime::add_seconds(at, MFA_RECENT) >= now);
    if !fresh {
        return Err(Error::new(ErrorType::UserChallengeRequired));
    }
    Ok(())
}

/// The `recentSession` group: a session created, or MFA-verified, within
/// `SESSION_RECENT_DURATION`. Guests are left to the scope check.
pub(super) fn recent_session(ctx: &Context) -> Result<()> {
    if !ctx.route.has_group("recentSession") {
        return Ok(());
    }
    let Some(session) = ctx.session.as_ref() else { return Ok(()) };
    let recent = datetime::add_seconds(datetime::now(), -SESSION_RECENT);
    let signed_in = session.created_at.is_some_and(|at| at >= recent);
    let verified = session.mfa_updated_at.is_some_and(|at| at >= recent);
    if !signed_in && !verified {
        return Err(Error::new(ErrorType::UserReauthenticationRequired));
    }
    Ok(())
}

/// The `auth` group: the console refuses denied countries, and the project's
/// disabled authentication methods are refused to all but keys and
/// privileged users.
pub(super) async fn auth_methods(ctx: &Context) -> Result<()> {
    if !ctx.route.has_group("auth") {
        return Ok(());
    }
    let denylist = &ctx.state.config.console_countries_denylist;
    if !denylist.is_empty() && ctx.project.is_console() {
        let country = ctx.state.geo.country_code(&ctx.ip).await.to_uppercase();
        if denylist.contains(&country) {
            return Err(Error::new(ErrorType::GeneralRegionAccessDenied));
        }
    }
    if roles::is_key(&ctx.roles) || roles::is_privileged(&ctx.roles) {
        return Ok(());
    }
    // `app/config/auth.php`: the method, its `auths` key, the default and
    // the message when it is off.
    let (key, default, name) = match ctx.route.labels.auth_type {
        "email-password" => ("emailPassword", true, "Email / Password"),
        "magic-url" => ("usersAuthMagicURL", true, "Magic URL"),
        "anonymous" => ("anonymous", true, "Anonymous"),
        "phone" => ("phone", true, "Phone"),
        "invites" => ("invites", true, "Invites"),
        "jwt" => ("JWT", true, "JWT"),
        "email-otp" => ("emailOtp", true, "Email OTP"),
        "passkey" => ("passkey", false, "Passkey"),
        _ => {
            return Err(Error::with_message(ErrorType::UserAuthMethodUnsupported, "Unsupported authentication route"));
        }
    };
    // PHP refuses `=== false`, and a passkey `!== true`.
    let value = ctx.project.auths.get(key);
    let refused = if default { value == Some(&Value::Bool(false)) } else { value != Some(&Value::Bool(true)) };
    if refused {
        return Err(Error::with_message(
            ErrorType::UserAuthMethodUnsupported,
            format!("{name} authentication is disabled for this project"),
        ));
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn map(value: Value) -> Map<String, Value> {
        match value {
            Value::Object(map) => map,
            _ => unreachable!(),
        }
    }

    /// `Request::cacheIdentifier()`, by PHP:
    /// `md5($uri . '*' . serialize($params) . '*' . APP_CACHE_BUSTER)`.
    #[test]
    fn cache_key_matches_php() {
        let flags = map(json!({"width": "100", "height": "50"}));
        assert_eq!(cache_key("/v1/avatars/flags/us", &flags, None, "p1"), "b7170e084d342dfbca17eb415a94a5a2");
        let preview = map(json!({"width": "100", "token": "x", "project": "p2", "5": "a"}));
        assert_eq!(
            cache_key("/v1/storage/buckets/b/files/f/preview", &preview, Some(&["width", "project"]), "p1"),
            "faf402ff8bd061a60af429200a4d86d7"
        );
        let numeric = map(json!({"name": "A B", "10": "x", "9": "y", "b": "z"}));
        assert_eq!(cache_key("/v1/avatars/initials", &numeric, None, ""), "7f301db9ba4c392d96602a02b688431a");
    }

    #[test]
    fn two_parts_only() {
        assert_eq!(two("a:b"), Some(("a", "b")));
        assert_eq!(two("a"), None);
        assert_eq!(two("a:b:c"), None);
        assert_eq!(two(":"), Some(("", "")));
    }
}
