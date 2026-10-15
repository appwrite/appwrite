//! Shared domain steps of the Account module: proofs, the request's
//! cookie settings, session documents and responses, new users, email
//! metadata, templates and the mail and SMS messages the workers send.

/// The profile, recovery, verification, identity and target helpers.
pub mod profile;

use bytes::Bytes;
use chrono::NaiveDateTime;
use serde_json::{Map, Value as Json, json};
use utopia_auth::Hash;
use utopia_auth::hashes::{Argon2, Sha};
use utopia_database::Permission;
use utopia_database::Role;
use utopia_database::value::list;
use utopia_database::{Document, Value};
use utopia_emails::Email;
use utopia_http::Cookie;
use utopia_http::Response;

use appwrite_core::database::documents::{self, Session, User, text};
use appwrite_core::database::filters;
use appwrite_core::datetime;
use appwrite_core::platform::Context;
use appwrite_core::response::SessionModel;
use appwrite_core::{Error, ErrorType, Result};

/// `TOKEN_EXPIRATION_LOGIN_LONG`: a session's default length.
pub const SESSION_DURATION: i64 = 31_536_000;
/// `TOKEN_EXPIRATION_CONFIRM`.
pub const EXPIRATION_CONFIRM: i64 = 3600;
/// `TOKEN_EXPIRATION_OTP`.
pub const EXPIRATION_OTP: i64 = 900;

/// `TOKEN_TYPE_*`.
pub mod token_type {
    pub const MAGIC_URL: i64 = 5;
    pub const PHONE: i64 = 6;
    pub const OAUTH2: i64 = 7;
    pub const GENERIC: i64 = 8;
    pub const EMAIL: i64 = 9;
    pub const PASSKEY: i64 = 12;
}

/// Where the PHP workers find the email templates: messages name them by
/// the path PHP builds (`__DIR__` of the file that builds the message).
pub const PHP_ROOT: &str = "/usr/src/code";

/// `auths['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG`.
pub fn duration(ctx: &Context) -> i64 {
    ctx.project.auth_int("duration", SESSION_DURATION)
}

// ---- proofs ----------------------------------------------------------------

/// `proofForPassword`: Argon2id with Appwrite's costs.
pub fn proof() -> Box<dyn Hash> {
    let mut argon2 = Argon2::new();
    argon2.set_memory_cost(7168).set_time_cost(5).set_threads(1);
    Box::new(argon2)
}

/// Hashes a password with [`proof`] on the blocking pool.
pub async fn hash_password(password: String) -> Result<String> {
    let hashed = utopia_async::spawn_blocking(move || proof().hash(password.as_bytes()))
        .await
        .map_err(|e| Error::internal(e.to_string()))?
        .map_err(|e| Error::internal(e.to_string()))?;
    String::from_utf8(hashed).map_err(|e| Error::internal(e.to_string()))
}

/// `$hash->getOptions()`.
pub fn hash_options(hash: &dyn Hash) -> Value {
    utopia_database::value::from_plain(php_std::Value::Array(hash.options().as_array().clone()))
}

/// `proofForToken->hash($secret)` (SHA-256, hex).
pub fn token_hash(secret: &str) -> Result<String> {
    let hashed = Sha::new().hash(secret.as_bytes()).map_err(|e| Error::internal(e.to_string()))?;
    String::from_utf8(hashed).map_err(|e| Error::internal(e.to_string()))
}

/// `proofForToken->verify($secret, $hash)`.
pub fn token_matches(secret: &str, hash: &str) -> bool {
    !secret.is_empty() && token_hash(secret).is_ok_and(|h| utopia_auth::hash_equals(hash.as_bytes(), h.as_bytes()))
}

/// A random secret of `length` hex characters (`Proofs\Token`).
pub fn token_secret(length: i64) -> Result<String> {
    Ok(utopia_auth::proofs::Token::new(length).map_err(|e| Error::internal(e.to_string()))?.token())
}

/// A six digit code (`Proofs\Code`).
pub fn code_secret() -> String {
    utopia_auth::proofs::Code::default().code()
}

/// The session store a client keeps (`$store->encode()`).
pub fn encode_store(id: &str, secret: &str) -> Result<String> {
    use php_std::Value as Php;
    let mut store = utopia_auth::Store::new();
    store.set_property(b"id", Php::from(id)).set_property(b"secret", Php::from(secret));
    store.encode().map_err(|e| Error::internal(e.to_string()))
}

// ---- permissions -----------------------------------------------------------

/// `read`, `update` and `delete` for the user.
pub fn owner_permissions(user_id: &str) -> Value {
    let role = Role::user(user_id, "");
    list(
        [Permission::read(&role), Permission::update(&role), Permission::delete(&role)]
            .map(|p| Value::from(p.as_str())),
    )
}

/// A user's own permissions: `read` for anyone, `update` and `delete` for the user.
pub fn user_permissions(user_id: &str) -> Value {
    let owner = Role::user(user_id, "");
    list(
        [Permission::read(&Role::any()), Permission::update(&owner), Permission::delete(&owner)]
            .map(|p| Value::from(p.as_str())),
    )
}

// ---- request ---------------------------------------------------------------

/// `$request->getProtocol()`: the trusted `X-Forwarded-Proto`, else `http`.
pub fn protocol(ctx: &Context) -> String {
    if let Some(value) = ctx.request.header("x-forwarded-proto").filter(|v| !v.is_empty()) {
        let scheme = value.split(',').next().unwrap_or("").trim().to_lowercase();
        if matches!(scheme.as_str(), "http" | "https" | "ws" | "wss") {
            return scheme;
        }
    }
    "http".to_owned()
}

/// `$request->getPort()`: `X-Forwarded-Port`, else the port of the host
/// (`''` when it names none).
pub fn port(ctx: &Context) -> String {
    if let Some(port) = ctx.request.header("x-forwarded-port") {
        return port.to_owned();
    }
    let host = ctx.request.header("x-forwarded-host").unwrap_or_else(|| ctx.request.header_or_empty("host"));
    let url = format!("{}://{host}", protocol(ctx));
    php_std::url::parse_url(url.as_bytes()).and_then(|u| u.port()).map(|p| p.to_string()).unwrap_or_default()
}

/// The `cookieDomain` resource: none for local hosts and IPs, else the
/// request host (the registrable domain for console root sessions).
pub fn cookie_domain(ctx: &Context) -> Option<String> {
    let port = port(ctx);
    let hostname = &ctx.hostname;
    let mut local = vec!["localhost".to_owned(), format!("localhost:{port}")];
    if let Some(host) = &ctx.state.config.migration_host {
        local.push(host.clone());
        local.push(format!("{host}:{port}"));
    }
    if local.contains(hostname) || hostname.parse::<std::net::IpAddr>().is_ok() {
        return None;
    }
    if ctx.project.is_console() && ctx.state.config.console_root_session {
        let registerable = utopia_domains::Domain::new(hostname).map(|d| d.registerable()).unwrap_or_default();
        return Some(format!(".{registerable}"));
    }
    Some(format!(".{hostname}"))
}

/// The `domainVerification` resource: the origin (or referer) is on the
/// request host's registrable domain.
pub fn domain_verification(ctx: &Context) -> bool {
    let origin = ctx
        .request
        .header("origin")
        .or_else(|| ctx.request.header("referer"))
        .and_then(appwrite_core::network::url_host)
        .unwrap_or_default();
    let registerable = |host: &str| utopia_domains::Domain::new(host).map(|d| d.registerable()).unwrap_or_default();
    let end = registerable(&origin);
    registerable(&ctx.hostname) == end && !end.is_empty()
}

/// The `X-Fallback-Cookies` header and the session cookies (`<key>_legacy`
/// without `SameSite`, `<key>` with `SameSite=None`).
pub fn add_session_cookies(ctx: &Context, response: &mut Response, encoded: &str, expire: &NaiveDateTime) {
    let key = ctx.store.key.clone();
    if !domain_verification(ctx) {
        let mut fallback = Map::new();
        fallback.insert(key.clone(), Json::String(encoded.to_owned()));
        response.add("x-fallback-cookies", &appwrite_core::json::to_string(&Json::Object(fallback)));
    }
    add_session_cookies_only(ctx, response, encoded, expire);
}

/// The session cookies (`<key>_legacy` without `SameSite`, `<key>` with
/// `SameSite=None`).
pub fn add_session_cookies_only(ctx: &Context, response: &mut Response, encoded: &str, expire: &NaiveDateTime) {
    let key = ctx.store.key.clone();
    let secure = protocol(ctx) == "https";
    let domain = cookie_domain(ctx);
    let expire = expire.and_utc().timestamp();
    for (name, same_site) in [(format!("{key}_legacy"), None), (key, Some("None".to_owned()))] {
        response.add_cookie(Cookie {
            name,
            value: Some(encoded.to_owned()),
            expire: Some(expire),
            path: Some("/".to_owned()),
            domain: domain.clone(),
            secure: Some(secure),
            http_only: Some(true),
            same_site,
        });
    }
}

/// Deletes the session cookies (`expire` an hour ago).
pub fn clear_session_cookies(ctx: &Context, response: &mut Response) {
    let key = ctx.store.key.clone();
    let secure = protocol(ctx) == "https";
    let domain = cookie_domain(ctx);
    let expire = chrono::Utc::now().timestamp() - 3600;
    for (name, same_site) in [(format!("{key}_legacy"), None), (key, Some("None".to_owned()))] {
        response.add_cookie(Cookie {
            name,
            value: Some(String::new()),
            expire: Some(expire),
            path: Some("/".to_owned()),
            domain: domain.clone(),
            secure: Some(secure),
            http_only: Some(true),
            same_site,
        });
    }
}

/// `$platform['consoleUrl']`, with the request port when the console URL
/// is derived (an explicit `_APP_CONSOLE_URL` carries its own).
pub fn console_base(ctx: &Context) -> String {
    let mut base = ctx.state.config.platform.get("consoleUrl").and_then(Json::as_str).unwrap_or_default().to_owned();
    let port = port(ctx);
    let default = if ctx.state.config.link_scheme == "https" { "443" } else { "80" };
    if !ctx.state.config.console_url_explicit && port != default {
        base.push(':');
        base.push_str(&port);
    }
    base
}

/// `$platform[$key]`.
pub fn platform(ctx: &Context, key: &str) -> String {
    ctx.state.config.platform.get(key).and_then(Json::as_str).unwrap_or_default().to_owned()
}

/// The project's name in messages (the platform's for the console).
pub fn project_name(ctx: &Context) -> String {
    if ctx.project.is_console() { platform(ctx, "platformName") } else { ctx.project.name.clone() }
}

// ---- users -----------------------------------------------------------------

/// The user sub-query filters other than `keep`.
pub fn skipped(keep: &[&str]) -> Vec<String> {
    filters::USERS_SUBQUERIES.iter().filter(|f| !keep.contains(f)).map(|f| (*f).to_owned()).collect()
}

/// `getDocument('users', $id)` with authorization skipped.
pub async fn load_user(ctx: &mut Context, id: &str) -> Result<Option<User>> {
    let db = ctx.db()?;
    db.authorization().skip(documents::load_user(&db, id)).await
}

/// The user's tokens (the `subQueryTokens` filter) as `(id, type, secret, expire)`.
pub async fn user_tokens(ctx: &mut Context, user: &User) -> Result<Vec<Document>> {
    let db = ctx.db()?;
    let mut document = Document::default();
    document.put("$id", Value::from(user.id.as_str()));
    document.put("$sequence", Value::from(user.sequence.to_string().as_str()));
    let tokens = filters::sub_query(&db, "subQueryTokens", &document).await?;
    Ok(match tokens {
        Value::Array(items) => items.values().filter_map(utopia_database::value::as_document).cloned().collect(),
        _ => Vec::new(),
    })
}

/// Email metadata stored on users (`emailCanonical`, `emailIs*`).
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Metadata {
    pub canonical: Option<String>,
    pub is_canonical: Option<bool>,
    pub is_corporate: Option<bool>,
    pub is_disposable: Option<bool>,
    pub is_free: Option<bool>,
}

impl Metadata {
    /// The metadata of `email` as sign-up computes it; an address without
    /// a canonical form is `general_invalid_email`.
    pub fn of(email: &str) -> Result<Self> {
        let parsed = Email::new(email).map_err(|_| Error::new(ErrorType::GeneralInvalidEmail))?;
        let canonical = parsed.canonical().map_err(|_| Error::new(ErrorType::GeneralInvalidEmail))?;
        Ok(Self {
            is_canonical: Some(parsed.get() == canonical),
            canonical: Some(canonical),
            is_corporate: Some(parsed.is_corporate()),
            is_disposable: Some(parsed.is_disposable()),
            is_free: Some(parsed.is_free()),
        })
    }

    /// Sets the `emailCanonical` and `emailIs*` attributes.
    pub fn apply(&self, document: &mut Document) {
        let flag = |b: Option<bool>| b.map(Value::Bool).unwrap_or(Value::Null);
        document.put("emailCanonical", text(self.canonical.as_deref()));
        document.put("emailIsCanonical", flag(self.is_canonical));
        document.put("emailIsCorporate", flag(self.is_corporate));
        document.put("emailIsDisposable", flag(self.is_disposable));
        document.put("emailIsFree", flag(self.is_free));
    }
}

/// The email policies (`disposable`, `canonical`, `free`, `corporate`) a
/// sign-up checks, each gated on the plan (all in CE and the console).
pub fn check_email_policy(ctx: &Context, meta: &Metadata, canonicalized: bool) -> Result<()> {
    let project = &ctx.project;
    if ctx.plan_allows("supportsDisposableEmailValidation")
        && project.auth_bool("disposableEmails", false)
        && meta.is_disposable == Some(true)
    {
        return Err(Error::new(ErrorType::UserEmailDisposable));
    }
    if ctx.plan_allows("supportsCanonicalEmailValidation")
        && project.auth_bool("canonicalEmails", false)
        && meta.is_canonical == Some(false)
        && !canonicalized
    {
        return Err(Error::new(ErrorType::UserEmailNotCanonical));
    }
    if ctx.plan_allows("supportsFreeEmailValidation")
        && project.auth_bool("freeEmails", false)
        && meta.is_free == Some(true)
    {
        return Err(Error::new(ErrorType::UserEmailFree));
    }
    if ctx.plan_allows("supportsCorporateEmailValidation")
        && project.auth_bool("corporateEmails", false)
        && meta.is_corporate != Some(true)
    {
        return Err(Error::new(ErrorType::UserEmailNotCorporate));
    }
    Ok(())
}

/// `auths['limit']`: refuses a new user once the project has that many.
pub async fn check_user_limit(ctx: &mut Context, console_error: bool) -> Result<()> {
    let limit = ctx.project.auth_int("limit", 0);
    if limit == 0 {
        return Ok(());
    }
    let db = ctx.db()?;
    let total = db.count("users", Vec::new(), Some(10_000)).await?;
    if total >= limit {
        if console_error && ctx.project.is_console() {
            return Err(Error::new(ErrorType::UserConsoleCountExceeded));
        }
        return Err(Error::new(ErrorType::UserCountExceeded));
    }
    Ok(())
}

/// A new user's document: the attributes every sign-up sets.
pub fn new_user(user_id: &str) -> Document {
    let mut user = Document::default();
    user.put("$id", Value::from(user_id));
    user.put("$permissions", user_permissions(user_id));
    user.put("emailVerification", Value::Bool(false));
    user.put("status", Value::Bool(true));
    user.put("registration", datetime::value(&datetime::now()));
    user.put("reset", Value::Bool(false));
    user.put("prefs", Value::Object(Default::default()));
    user.put("sessions", Value::Null);
    user.put("tokens", Value::Null);
    user.put("memberships", Value::Null);
    user.put("accessedAt", datetime::value(&datetime::now()));
    user
}

/// The proof's name and options on a user without a password.
pub fn set_default_hash(user: &mut Document) {
    let hash = proof();
    user.put("password", Value::Null);
    user.put("hash", Value::from(hash.name()));
    user.put("hashOptions", hash_options(hash.as_ref()));
    user.put("passwordUpdate", Value::Null);
}

/// `createDocument('users', $user)` over the injected user, with authorization skipped; a
/// duplicate is `user_already_exists`.
pub async fn create_user(ctx: &mut Context, user: Document) -> Result<User> {
    let user = over_signed_in(ctx, user).await?;
    let db = ctx.db()?;
    let skipped = skipped(&[]);
    let created =
        db.authorization().skip(db.skip_filters(Some(skipped.as_slice()), db.create_document("users", user))).await;
    match created {
        Ok(created) => Ok(User::from(&created)),
        Err(e) if e.is_a(utopia_database::Kind::Duplicate) => Err(Error::new(ErrorType::UserAlreadyExists)),
        Err(e) => Err(e.into()),
    }
}

/// `$user->setAttributes([...])` on the injected `user` before it is
/// created: a signed-in user's attributes the new user does not set stay
/// in the document created (its dates and sequence are the new user's).
pub async fn over_signed_in(ctx: &mut Context, user: Document) -> Result<Document> {
    let Some(id) = ctx.user.as_ref().map(|u| u.id.clone()) else { return Ok(user) };
    // An account API key's user is a console user.
    let db = if ctx.account_keys.is_empty() { ctx.db()? } else { ctx.platform_db()? };
    let skipped = skipped(&[]);
    let mut base = db
        .authorization()
        .skip(db.skip_filters(Some(skipped.as_slice()), db.get_document("users", &id, Vec::new(), false)))
        .await?;
    if base.is_empty() {
        return Ok(user);
    }
    for key in ["$sequence", "$createdAt", "$updatedAt", "$collection", "$tenant"] {
        base.remove(key);
    }
    for key in filters::USERS_SUBQUERIES.iter().filter_map(|f| f.strip_prefix("subQuery")) {
        base.remove(&key.to_lowercase());
    }
    base.set_many(user.into_array())?;
    Ok(base)
}

/// A messaging target for the user (`email`, `sms`).
pub fn new_target(user: &User, provider_type: &str, identifier: &str) -> Document {
    let mut target = Document::default();
    target.put("$permissions", owner_permissions(&user.id));
    target.put("userId", Value::from(user.id.as_str()));
    target.put("userInternalId", documents::internal_id(user.sequence));
    target.put("providerType", Value::from(provider_type));
    target.put("identifier", Value::from(identifier));
    target
}

/// Creates the user's target, with authorization skipped; on a duplicate
/// identifier, the existing target (when one is readable).
pub async fn create_target(
    ctx: &mut Context,
    user: &User,
    provider_type: &str,
    identifier: &str,
) -> Result<Option<documents::Target>> {
    let db = ctx.db()?;
    let created =
        db.authorization().skip(db.create_document("targets", new_target(user, provider_type, identifier))).await;
    match created {
        Ok(target) => Ok(Some(documents::Target::from(&target))),
        Err(e) if e.is_a(utopia_database::Kind::Duplicate) => {
            let existing =
                db.find_one("targets", vec![utopia_database::Query::equal("identifier", [identifier])]).await?;
            Ok(if existing.is_empty() { None } else { Some(documents::Target::from(&existing)) })
        }
        Err(e) => Err(e.into()),
    }
}

// ---- sessions --------------------------------------------------------------

/// A new session's document for `user`: the client's device, its location
/// and the session's provider, factors and expiry.
pub async fn new_session(
    ctx: &Context,
    user: &User,
    provider: &str,
    secret: &str,
    factors: &[&str],
    expire: &NaiveDateTime,
) -> Result<Document> {
    let user_agent = ctx.user_agent("UNKNOWN");
    let detected = appwrite_core::detector::detect(&user_agent);
    let geo = ctx.state.geo.get(&ctx.ip).await;
    let string = |s: &Option<String>| text(s.as_deref());
    let float = |f: Option<f64>| f.map(Value::Float).unwrap_or(Value::Null);
    let mut session = Document::default();
    session.put("$id", Value::from(appwrite_core::database::unique_id().as_str()));
    session.put("userId", Value::from(user.id.as_str()));
    session.put("userInternalId", documents::internal_id(user.sequence));
    session.put("provider", Value::from(provider));
    session.put("secret", Value::from(token_hash(secret)?.as_str()));
    session.put("userAgent", Value::from(user_agent.as_str()));
    session.put("ip", Value::from(ctx.ip.as_str()));
    session.put("factors", documents::strings(&factors.iter().map(|f| (*f).to_owned()).collect::<Vec<_>>()));
    session.put("countryCode", Value::from(geo.country_code.to_lowercase().as_str()));
    session.put(
        "continentCode",
        if geo.continent_code == "--" { Value::Null } else { Value::from(geo.continent_code.as_str()) },
    );
    session.put("latitude", float(geo.latitude));
    session.put("longitude", float(geo.longitude));
    session.put("timeZone", string(&geo.time_zone));
    session.put("weatherCode", string(&geo.weather_code));
    session.put("postalCode", string(&geo.postal_code));
    session.put("autonomousSystemNumber", string(&geo.autonomous_system_number));
    session.put("autonomousSystemOrganization", string(&geo.autonomous_system_organization));
    session.put("connectionType", string(&geo.connection_type));
    session.put("connectionUsageType", string(&geo.connection_usage_type));
    session.put("connectionOrganization", string(&geo.connection_organization));
    session.put("isp", string(&geo.isp));
    session.put("expire", datetime::value(expire));
    session.put("osCode", Value::from(detected.os_code.as_str()));
    session.put("osName", Value::from(detected.os_name.as_str()));
    session.put("osVersion", Value::from(detected.os_version.as_str()));
    session.put("clientType", Value::from(detected.client_type.as_str()));
    session.put("clientCode", Value::from(detected.client_code.as_str()));
    session.put("clientName", Value::from(detected.client_name.as_str()));
    session.put("clientVersion", Value::from(detected.client_version.as_str()));
    session.put("clientEngine", Value::from(detected.client_engine.as_str()));
    session.put("clientEngineVersion", Value::from(detected.client_engine_version.as_str()));
    session.put("deviceName", text(detected.device_name.as_deref()));
    session.put("deviceBrand", text(detected.device_brand.as_deref()));
    session.put("deviceModel", text(detected.device_model.as_deref()));
    session.set("$permissions", owner_permissions(&user.id))?;
    Ok(session)
}

/// The localized name of a country code (`countries.<code>`).
pub fn country_name(ctx: &Context, code: &str) -> String {
    let unknown = ctx.text("locale.country.unknown", None);
    ctx.text(&format!("countries.{}", code.to_lowercase()), Some(&unknown))
}

/// JSON of a session as the latest format renders it (an event payload).
pub fn session_payload(ctx: &Context, session: &Session, current: bool, country: &str, secret: Option<&str>) -> Bytes {
    let model = SessionModel { session, country_name: country, current, secret, render: ctx.render() };
    Bytes::from(appwrite_core::response::current(&model))
}

// ---- URLs ------------------------------------------------------------------

/// The `redirectValidator` resource: URLs to the request's allowed
/// hostnames and schemes (`Appwrite\Network\Validator\Redirect`).
pub struct Redirect {
    pub hosts: Vec<String>,
    pub schemes: Vec<String>,
}

impl Redirect {
    pub fn of(ctx: &Context) -> Self {
        Self { hosts: ctx.allowed_hostnames(), schemes: ctx.allowed_schemes() }
    }

    /// Whether `url` is allowed, else the validator's description.
    pub fn allows(&self, url: &str) -> std::result::Result<(), String> {
        appwrite_core::network::validate_redirect(url, &self.hosts, &self.schemes)
    }
}

impl utopia_validators::Validator for Redirect {
    fn description(&self) -> String {
        "Invalid URI.".to_owned()
    }

    fn is_valid(&self, value: &Json) -> bool {
        matches!(value, Json::String(s) if self.allows(s).is_ok())
    }

    fn check(&self, value: &Json) -> std::result::Result<(), String> {
        match value {
            Json::String(s) => self.allows(s),
            _ => Err("Invalid URI.".to_owned()),
        }
    }
}

/// A URL's parts as PHP's `parse_url()` gives them.
#[derive(Debug, Clone, Default)]
pub struct UrlParts {
    pub scheme: Option<String>,
    pub host: Option<String>,
    pub port: Option<u16>,
    pub user: Option<String>,
    pub pass: Option<String>,
    pub path: Option<String>,
    pub query: Option<String>,
    pub fragment: Option<String>,
}

impl UrlParts {
    /// `parse_url($url)`; `None` where PHP returns `false`.
    pub fn parse(url: &str) -> Option<Self> {
        let u = php_std::url::parse_url(url.as_bytes())?;
        let s = |c: Option<std::borrow::Cow<'_, [u8]>>| c.map(|b| String::from_utf8_lossy(&b).into_owned());
        Some(Self {
            scheme: s(u.scheme()),
            host: s(u.host()),
            port: u.port(),
            user: s(u.user()),
            pass: s(u.pass()),
            path: s(u.path()),
            query: s(u.query()),
            fragment: s(u.fragment()),
        })
    }

    /// `Template::unParseURL($url)`.
    pub fn unparse_template(&self) -> String {
        let mut out = String::new();
        if let Some(scheme) = &self.scheme {
            out.push_str(scheme);
            out.push_str("://");
        }
        let user = self.user.clone().unwrap_or_default();
        let pass = self.pass.as_ref().map(|p| format!(":{p}")).unwrap_or_default();
        out.push_str(&user);
        if !user.is_empty() || !pass.is_empty() {
            out.push_str(&pass);
            out.push('@');
        }
        out.push_str(self.host.as_deref().unwrap_or(""));
        if let Some(port) = self.port {
            out.push_str(&format!(":{port}"));
        }
        out.push_str(self.path.as_deref().unwrap_or(""));
        if let Some(query) = self.query.as_deref().filter(|q| !q.is_empty() && *q != "0") {
            out.push('?');
            out.push_str(query);
        }
        if let Some(fragment) = &self.fragment {
            out.push('#');
            out.push_str(fragment);
        }
        out
    }
}

/// `parse_str($query)` as a JSON object (lists keyed by index).
pub fn parse_query(query: &str) -> Map<String, Json> {
    match php_std::Value::Array(php_std::encoding::parse_str(query.as_bytes())).to_json() {
        Json::Object(m) => m,
        Json::Array(items) => items.into_iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect(),
        _ => Map::new(),
    }
}

/// `http_build_query($query)`.
pub fn build_query(query: &Map<String, Json>) -> String {
    let bytes = php_std::encoding::http_build_query(
        &Json::Object(query.clone()),
        b"",
        None,
        php_std::encoding::QueryEncoding::Rfc1738,
    );
    String::from_utf8_lossy(&bytes).into_owned()
}

/// `Template::parseURL()`, `mergeQuery()` with `params`, `unParseURL()`:
/// a link with the token's parameters added to its query.
pub fn link(url: &str, params: &[(&str, &str)]) -> String {
    let mut parts = UrlParts::parse(url).unwrap_or_default();
    let mut query = parse_query(parts.query.as_deref().unwrap_or(""));
    for (k, v) in params {
        query.insert((*k).to_owned(), Json::String((*v).to_owned()));
    }
    parts.query = Some(build_query(&query));
    parts.unparse_template()
}

// ---- templates and messages ------------------------------------------------

/// `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`.
pub fn escape(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for c in value.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#039;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            c => out.push(c),
        }
    }
    out
}

/// `Appwrite\Template\Template`: a file (or a string, rendered through the
/// `nl2p` filter) whose `{{params}}` are replaced, values HTML-escaped.
pub struct Template {
    content: String,
    params: Vec<(String, String)>,
}

impl Template {
    /// `Template::fromFile($path)` for a template under
    /// `app/config/locale/templates`.
    pub fn file(ctx: &Context, name: &str) -> Result<Self> {
        let path = ctx.state.config.asset(&format!("app/config/locale/templates/{name}"));
        let content = std::fs::read_to_string(&path)
            .map_err(|_| Error::internal(format!("{} view template is not readable.", path.display())))?;
        Ok(Self { content, params: Vec::new() })
    }

    /// `Template::fromString($content)`, rendered with `nl2p`.
    pub fn string(content: &str) -> Self {
        let mut paragraphs = String::new();
        for line in content.split("\n\n") {
            if !line.trim().is_empty() {
                paragraphs.push_str("<p>");
                paragraphs.push_str(line);
                paragraphs.push_str("</p>");
            }
        }
        Self { content: paragraphs.replace('\n', "<br />"), params: Vec::new() }
    }

    /// `setParam($key, $value)`: the value is HTML-escaped.
    pub fn param(mut self, key: &str, value: &str) -> Self {
        let value = escape(value);
        match self.params.iter_mut().find(|(k, _)| k == key) {
            Some(p) => p.1 = value,
            None => self.params.push((key.to_owned(), value)),
        }
        self
    }

    /// `render()`: the params are replaced in each other, then in the template.
    pub fn render(&self) -> String {
        let replace = |subject: &str| {
            let mut out = subject.to_owned();
            for (k, v) in &self.params {
                out = out.replace(k.as_str(), v);
            }
            out
        };
        let params: Vec<(String, String)> = self.params.iter().map(|(k, v)| (k.clone(), replace(v))).collect();
        let mut out = self.content.clone();
        for (k, v) in &params {
            out = out.replace(k.as_str(), v);
        }
        out
    }
}

/// `$project->getAttribute('templates')['<type>-<locale>']`, else the
/// fallback locale's (`en`), else none.
pub fn custom_template(ctx: &Context, kind: &str) -> Option<Map<String, Json>> {
    let templates = &ctx.project.templates;
    [format!("{kind}-{}", ctx.locale), format!("{kind}-en")]
        .iter()
        .find_map(|key| templates.get(key))
        .and_then(Json::as_object)
        .cloned()
}

/// A string setting of a custom template or the SMTP settings (`''` when unset).
fn setting(map: &Json, key: &str) -> String {
    match map.get(key) {
        Some(Json::String(s)) => s.clone(),
        Some(Json::Null) | None => String::new(),
        Some(v) => utopia_validators::php::to_string(v).unwrap_or_default(),
    }
}

/// The SMTP settings an email is sent with (`[]` without custom SMTP), and
/// the subject and body the custom template replaces, as the sign-in
/// emails (magic URL, email OTP, MFA challenge) pick them.
pub fn smtp_settings(
    ctx: &Context,
    custom: Option<&Map<String, Json>>,
    subject: &mut String,
    body: &mut String,
) -> Json {
    let smtp = &ctx.project.smtp;
    if !smtp.get("enabled").is_some_and(utopia_validators::php::truthy) {
        return Json::Array(Vec::new());
    }
    let nonempty = |s: String| if s.is_empty() || s == "0" { None } else { Some(s) };
    let mut sender_email = ctx.state.config.system_email_address.clone();
    let mut sender_name = ctx.state.config.system_email_name.clone();
    let mut reply_email = String::new();
    let mut reply_name = String::new();
    if let Some(v) = nonempty(setting(smtp, "senderEmail")) {
        sender_email = v;
    }
    if let Some(v) = nonempty(setting(smtp, "senderName")) {
        sender_name = v;
    }
    let smtp_reply = if smtp.get("replyToEmail").is_some_and(|v| !v.is_null()) {
        setting(smtp, "replyToEmail")
    } else {
        setting(smtp, "replyTo")
    };
    if let Some(v) = nonempty(smtp_reply) {
        reply_email = v;
    }
    if let Some(v) = nonempty(setting(smtp, "replyToName")) {
        reply_name = v;
    }
    if let Some(custom) = custom.filter(|c| !c.is_empty()) {
        let custom = Json::Object(custom.clone());
        if let Some(v) = nonempty(setting(&custom, "senderEmail")) {
            sender_email = v;
        }
        if let Some(v) = nonempty(setting(&custom, "senderName")) {
            sender_name = v;
        }
        let custom_reply = if custom.get("replyToEmail").is_some_and(|v| !v.is_null()) {
            setting(&custom, "replyToEmail")
        } else {
            setting(&custom, "replyTo")
        };
        if let Some(v) = nonempty(custom_reply) {
            reply_email = v;
        }
        if let Some(v) = nonempty(setting(&custom, "replyToName")) {
            reply_name = v;
        }
        *body = setting(&custom, "message");
        if custom.get("subject").is_some_and(|v| !v.is_null()) {
            *subject = setting(&custom, "subject");
        }
    }
    let field = |key: &str| smtp.get(key).cloned().unwrap_or(Json::String(String::new()));
    json!({
        "host": field("host"),
        "port": field("port"),
        "username": field("username"),
        "password": field("password"),
        "secure": field("secure"),
        "replyToEmail": reply_email,
        "replyToName": reply_name,
        "senderEmail": sender_email,
        "senderName": sender_name,
    })
}

/// The branded base template's extra variables (`email-base-styled`).
pub fn branded_variables(ctx: &Context, variables: &mut Map<String, Json>) {
    for (key, from) in [
        ("accentColor", "accentColor"),
        ("logoUrl", "logoUrl"),
        ("twitter", "twitterUrl"),
        ("discord", "discordUrl"),
        ("github", "githubUrl"),
        ("terms", "termsUrl"),
        ("privacy", "privacyUrl"),
        ("platform", "platformName"),
    ] {
        variables.insert(key.to_owned(), Json::String(platform(ctx, from)));
    }
}

/// A mails queue message (`Appwrite\Event\Message\Mail::toArray()`).
pub struct Mail {
    pub recipient: String,
    pub subject: String,
    pub template: &'static str,
    pub body_template: String,
    pub body: String,
    pub preview: String,
    pub smtp: Json,
    pub variables: Map<String, Json>,
    pub custom_mail_options: Json,
}

impl Mail {
    /// Queues the message for the mails queue (published after the
    /// request's created users' events, as PHP sends them).
    pub async fn send(self, ctx: &mut Context) {
        let message = json!({
            "project": ctx.project.message_payload(),
            "recipient": self.recipient,
            "name": "",
            "subject": self.subject,
            "template": self.template,
            "bodyTemplate": self.body_template,
            "body": self.body,
            "preview": self.preview,
            "smtp": self.smtp,
            "variables": Json::Object(self.variables),
            "attachment": [],
            "customMailOptions": self.custom_mail_options,
            "events": [],
            "platform": ctx.state.config.platform,
            "headers": [],
        });
        let queue = ctx.state.config.queue_mails.clone();
        ctx.events.queued.push((queue, message));
    }
}

/// Publishes an internal SMS (`Message\Messaging` of type `internal`) with
/// `content` to `phone`, and counts it in usage.
pub async fn send_sms(ctx: &mut Context, message_id: &str, content: &str, phone: &str) {
    let message = json!({
        "type": "internal",
        "project": ctx.project.message_payload(),
        "messageId": null,
        "message": {"$id": message_id, "data": {"content": content}},
        "recipients": [phone],
        "providerType": "sms",
    });
    let queue = ctx.state.config.queue_messaging.clone();
    ctx.events.queued.push((queue, message));
    if let Some(code) = appwrite_core::validators::calling_code(phone) {
        ctx.metric(&format!("auth.method.phone.{code}"), 1);
    }
    ctx.metric("auth.method.phone", 1);
}

/// The user agent's device, client and OS for the sign-in emails.
pub fn agent_variables(ctx: &Context, variables: &mut Map<String, Json>) {
    let detected = appwrite_core::detector::detect(&ctx.user_agent("UNKNOWN"));
    variables.insert("agentDevice".into(), Json::String(detected.device_brand.unwrap_or_else(|| "UNKNOWN".into())));
    variables.insert("agentClient".into(), Json::String(detected.client_name));
    variables.insert("agentOs".into(), Json::String(detected.os_name));
}

/// The session alert a new session sends (the `mails` bus listener of
/// `SessionCreated`), when the project turned them on.
pub async fn session_alert(ctx: &mut Context, user: &User, session: &Session) -> Result<()> {
    if !ctx.project.auth_bool("sessionAlerts", false) {
        return Ok(());
    }
    let Some(email) = user.email.clone().filter(|e| !e.is_empty()) else { return Ok(()) };
    let provider = session.provider.as_deref().unwrap_or("");
    if matches!(provider, "magic-url" | "token") && session.factors.iter().any(|f| f == "email") {
        return Ok(());
    }
    let db = ctx.db()?;
    let sessions =
        db.count("sessions", vec![utopia_database::Query::equal("userId", [user.id.as_str()])], None).await?;
    if sessions == 1 {
        return Ok(());
    }
    let base = smtp_base_template(ctx);
    if base.is_empty() || base.contains('/') || base.contains('\\') || base.contains("..") {
        return Err(Error::internal("Invalid template path"));
    }
    let custom = custom_template(ctx, "email.sessionAlert").unwrap_or_default();
    let branded = base == "email-base-styled";
    let custom_json = Json::Object(custom.clone());
    let subject = match custom.get("subject") {
        Some(v) if !v.is_null() => setting(&custom_json, "subject"),
        _ => ctx.text("emails.sessionAlert.subject", None),
    };
    let preview = ctx.text("emails.sessionAlert.preview", None);
    let body = match custom.get("message").filter(|m| !m.is_null() && m.as_str() != Some("")) {
        Some(_) => setting(&custom_json, "message"),
        None => Template::file(ctx, "email-session-alert.tpl")?
            .param("{{hello}}", &ctx.text("emails.sessionAlert.hello", None))
            .param("{{body}}", &ctx.text("emails.sessionAlert.body", None))
            .param("{{listDevice}}", &ctx.text("emails.sessionAlert.listDevice", None))
            .param("{{listIpAddress}}", &ctx.text("emails.sessionAlert.listIpAddress", None))
            .param("{{listCountry}}", &ctx.text("emails.sessionAlert.listCountry", None))
            .param("{{footer}}", &ctx.text("emails.sessionAlert.footer", None))
            .param("{{thanks}}", &ctx.text("emails.sessionAlert.thanks", None))
            .param("{{signature}}", &ctx.text("emails.sessionAlert.signature", None))
            .render(),
    };
    let client = session
        .client_name
        .clone()
        .filter(|c| !c.is_empty())
        .or_else(|| session.user_agent.clone().filter(|u| !u.is_empty()))
        .unwrap_or_else(|| "UNKNOWN".to_owned());
    let now = chrono::Utc::now();
    let unknown = ctx.text("locale.country.unknown", None);
    let country = ctx.text(&format!("countries.{}", session.country_code.as_deref().unwrap_or("")), Some(&unknown));
    let mut variables = Map::new();
    variables.insert("direction".into(), Json::String(ctx.text("settings.direction", None)));
    variables.insert("date".into(), Json::String(now.format("%B %-d").to_string()));
    let year = now.format("%Y").to_string();
    variables.insert("year".into(), Json::String(year.repeat(4)));
    variables.insert("time".into(), Json::String(now.format("%H:%M:%S").to_string()));
    variables.insert("user".into(), Json::String(user.name.clone().unwrap_or_default()));
    variables.insert("project".into(), Json::String(project_name(ctx)));
    variables.insert("device".into(), Json::String(client));
    variables.insert("ipAddress".into(), text_json(&session.ip));
    variables.insert("country".into(), Json::String(country));
    if branded {
        branded_variables(ctx, &mut variables);
    }
    let smtp = &ctx.project.smtp;
    let smtp_config = if smtp.get("enabled").is_some_and(utopia_validators::php::truthy) {
        let pick = |c: &str, s: &str, d: Json| -> Json {
            custom
                .get(c)
                .filter(|v| !v.is_null())
                .cloned()
                .or_else(|| smtp.get(s).filter(|v| !v.is_null()).cloned())
                .unwrap_or(d)
        };
        let field = |key: &str| smtp.get(key).filter(|v| !v.is_null()).cloned().unwrap_or(Json::String(String::new()));
        let reply = custom
            .get("replyToEmail")
            .or_else(|| custom.get("replyTo"))
            .or_else(|| smtp.get("replyToEmail"))
            .or_else(|| smtp.get("replyTo"))
            .filter(|v| !v.is_null())
            .cloned()
            .unwrap_or(Json::String(String::new()));
        json!({
            "host": field("host"),
            "port": field("port"),
            "username": field("username"),
            "password": field("password"),
            "secure": field("secure"),
            "replyToEmail": reply,
            "replyToName": pick("replyToName", "replyToName", Json::String(String::new())),
            "senderEmail": pick("senderEmail", "senderEmail", Json::String(ctx.state.config.system_email_address.clone())),
            "senderName": pick("senderName", "senderName", Json::String(ctx.state.config.system_email_name.clone())),
        })
    } else {
        Json::Array(Vec::new())
    };
    Mail {
        recipient: email,
        subject,
        template: "session-alert",
        body_template: format!(
            "{PHP_ROOT}/src/Appwrite/Bus/Listeners/../../../../app/config/locale/templates/{base}.tpl"
        ),
        body,
        preview,
        smtp: smtp_config,
        variables,
        custom_mail_options: if branded {
            json!({"senderName": platform(ctx, "emailSenderName")})
        } else {
            Json::Array(Vec::new())
        },
    }
    .send(ctx)
    .await;
    Ok(())
}

/// A string attribute as JSON (`null` when unset).
fn text_json(value: &Option<String>) -> Json {
    value.as_ref().map(|v| Json::String(v.clone())).unwrap_or(Json::Null)
}

/// `$user->setAttributes($profile->getArrayCopy())`: the injected `user`
/// becomes the account signed in or created, and the request's events
/// carry it.
pub fn act_as(ctx: &mut Context, user: &User) {
    ctx.events.actor = Some(user.message_payload());
}

/// `smtpBaseTemplate`: `email-base` by default, the styled one on the console.
pub fn smtp_base_template(ctx: &Context) -> String {
    ctx.project
        .smtp_base_template
        .clone()
        .unwrap_or_else(|| if ctx.project.is_console() { "email-base-styled" } else { "email-base" }.to_owned())
}
