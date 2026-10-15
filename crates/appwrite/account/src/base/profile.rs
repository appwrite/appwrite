//! Shared domain steps of the Account routes: the account response, the
//! user writes, tokens and their verification, the mails and messages the
//! routes queue, and the request resources they read (`redirectValidator`,
//! `domainVerification`, `cookieDomain`).

use async_trait::async_trait;
use bytes::Bytes;
use serde_json::{Map, Value as Json, json};
use utopia_auth::Hash;
use utopia_auth::hashes::{Argon2, Sha};
use utopia_auth::proofs::Password;
use utopia_database::database::Callback;
use utopia_database::helpers::permission::Permission;
use utopia_database::helpers::role::Role;
use utopia_database::value::{from_plain, list, to_long};
use utopia_database::{Database, Document, Kind, Query, Value};
use utopia_emails::Email;
use utopia_http::Response;
use utopia_validators::Validator;

use appwrite_core::database::documents::{self, Attributes, Token, User, text};
use appwrite_core::database::filters;
use appwrite_core::datetime;
use appwrite_core::platform::Context;
use appwrite_core::response::{AccountModel, Render, TokenModel};
use appwrite_core::template::Template;
use appwrite_core::{Error, ErrorType, Result};

// ---- constants (`app/init/constants.php`) ---------------------------------

pub const TOKEN_TYPE_VERIFICATION: i64 = 2;
pub const TOKEN_TYPE_RECOVERY: i64 = 3;
pub const TOKEN_TYPE_PHONE: i64 = 6;
pub const TOKEN_TYPE_VERIFICATION_OTP: i64 = 10;
pub const TOKEN_TYPE_RECOVERY_OTP: i64 = 11;

pub const TOKEN_EXPIRATION_RECOVERY: i64 = 3600;
pub const TOKEN_EXPIRATION_CONFIRM: i64 = 3600;
pub const TOKEN_EXPIRATION_OTP: i64 = 900;

/// `APP_LIMIT_SUBQUERY`.
pub const LIMIT_SUBQUERY: i64 = 1000;

/// Where PHP's mail templates live in its image (`__DIR__` of the account
/// controller): the mails worker reads `bodyTemplate` from this path.
const TEMPLATES_PHP: &str = "/usr/src/code/app/controllers/api/../../config/locale/templates/";

// ---- passwords --------------------------------------------------------------

/// The proof Appwrite uses for plaintext passwords (`proofForPassword`).
pub fn proof() -> Box<dyn Hash> {
    let mut argon2 = Argon2::new();
    argon2.set_memory_cost(7168).set_time_cost(5).set_threads(1);
    Box::new(argon2)
}

/// `proofForPassword->hash($password)`, on the blocking pool.
pub async fn hash_password(password: String) -> Result<String> {
    let hashed = tokio::task::spawn_blocking(move || proof().hash(password.as_bytes()))
        .await
        .map_err(|e| Error::internal(e.to_string()))?
        .map_err(|e| Error::internal(e.to_string()))?;
    String::from_utf8(hashed).map_err(|e| Error::internal(e.to_string()))
}

/// `$hash->getOptions()`, the `hashOptions` attribute.
pub fn hash_options(hash: &dyn Hash) -> Value {
    from_plain(php_std::Value::Array(hash.options().as_array().clone()))
}

/// `ProofsPassword::createHash($user->hash, $user->hashOptions)`.
fn user_hash(user: &User) -> Result<Box<dyn Hash>> {
    let kind = user.hash.clone().unwrap_or_else(|| "argon2".to_owned());
    let options = match php_std::Value::from_json(&user.hash_options.clone().unwrap_or(Json::Null)) {
        php_std::Value::Array(a) => a,
        _ => php_std::Array::new(),
    };
    Password::create_hash(&kind, &options).map_err(|e| Error::internal(e.to_string()))
}

/// `$userProofForPassword->verify($password, $user->password)`, on the
/// blocking pool.
pub async fn verify_password(user: &User, password: &str) -> Result<bool> {
    let hash = user_hash(user)?;
    let stored = user.password.clone().unwrap_or_default();
    let password = password.to_owned();
    tokio::task::spawn_blocking(move || hash.verify(password.as_bytes(), stored.as_bytes()).unwrap_or(false))
        .await
        .map_err(|e| Error::internal(e.to_string()))
}

/// `PasswordHistory($history, $hash)->isValid($password)` failing: the
/// password is outside 8..=256 bytes or matches a previous one.
pub async fn recently_used(user: &User, history: &[String], password: &str) -> Result<bool> {
    let hash = user_hash(user)?;
    let candidate = password.to_owned();
    let entries = history.to_vec();
    tokio::task::spawn_blocking(move || {
        if !(8..=256).contains(&candidate.len()) {
            return true;
        }
        entries
            .iter()
            .filter(|h| !h.is_empty())
            .any(|h| hash.verify(candidate.as_bytes(), h.as_bytes()).unwrap_or(false))
    })
    .await
    .map_err(|e| Error::internal(e.to_string()))
}

/// The password policy's breach check: `None` when the policy does not
/// look, else whether the password was found; refused when the policy says
/// so (`USER_PASSWORD_PWNED`).
pub async fn pwned(ctx: &Context, password: &str) -> Result<Option<bool>> {
    let policy = ctx.project.auth("passwordPwned").cloned().unwrap_or(Json::Null);
    let enabled = policy.get("enabled").map(utopia_validators::php::truthy).unwrap_or(true);
    if !enabled {
        return Ok(None);
    }
    let found = ctx.state.pwned.is_pwned(password).await?;
    if found && policy.get("users").map(utopia_validators::php::truthy).unwrap_or(false) {
        return Err(Error::new(ErrorType::UserPasswordPwned));
    }
    Ok(Some(found))
}

/// The attributes a new password sets (`password`, `passwordHistory`, ...).
pub fn password_updates(updates: &mut Document, hashed: &str, history: &[String], pwned: Option<bool>) {
    let proof = proof();
    updates.set("password", Value::from(hashed));
    updates.set("passwordHistory", documents::strings(history));
    updates.set("passwordPwned", pwned.map(Value::Bool).unwrap_or(Value::Null));
    updates.set("passwordUpdate", datetime::value(&datetime::now()));
    updates.set("hash", Value::from(proof.name()));
    updates.set("hashOptions", hash_options(proof.as_ref()));
}

// ---- users ------------------------------------------------------------------

/// The `subQueryTargets` filter: the account model's `targets`.
pub const TARGETS: &str = "subQueryTargets";
/// The `subQuerySessions` filter.
pub const SESSIONS: &str = "subQuerySessions";
/// The `subQueryTokens` filter.
pub const TOKENS: &str = "subQueryTokens";

/// The user sub-query filters (`APP_USERS_SUBQUERIES`) other than `keep`.
pub fn skipped(keep: &[&str]) -> Vec<String> {
    filters::USERS_SUBQUERIES.iter().filter(|f| !keep.contains(f)).map(|f| (*f).to_owned()).collect()
}

/// `getDocument('users', $id)` with only the sub-queries in `keep`; empty
/// when there is none (or it is not readable).
pub async fn user_document(db: &Database, id: &str, keep: &[&str]) -> Result<Document> {
    let skipped = skipped(keep);
    if skipped.is_empty() {
        // An empty list skips every filter.
        return Ok(db.get_document("users", id, Vec::new(), false).await?);
    }
    Ok(db.skip_filters(|| db.get_document("users", id, Vec::new(), false), Some(&skipped)).await?)
}

/// `updateDocument('users', $id, $updates)`: the user as stored, decoded
/// with the sub-queries in `keep`.
pub async fn update_user(db: &Database, id: &str, updates: Document, keep: &[&str]) -> Result<User> {
    let skipped = skipped(&[]);
    let mut updated = db.skip_filters(|| db.update_document("users", id, updates), Some(&skipped)).await?;
    if updated.is_empty() {
        return Err(Error::new(ErrorType::UserNotFound));
    }
    for filter in keep {
        let value = filters::sub_query(db, filter, &updated).await?;
        updated.set(sub_query_attribute(filter), value);
    }
    Ok(User::from(&updated))
}

/// The attribute a user sub-query filter fills (`subQueryTargets`: `targets`).
fn sub_query_attribute(filter: &str) -> String {
    let name = filter.strip_prefix("subQuery").unwrap_or(filter);
    let mut chars = name.chars();
    chars.next().map(|first| first.to_ascii_lowercase().to_string() + chars.as_str()).unwrap_or_default()
}

/// The session user (`user`), or `USER_NOT_FOUND` when the request has none
/// (the scope check lets only sessions and JWTs through).
pub fn session_user(ctx: &Context) -> Result<User> {
    ctx.user.clone().ok_or_else(|| Error::new(ErrorType::UserNotFound))
}

/// The impersonator's ID when `user` is the impersonated user
/// (`Response::dynamic` adds `impersonatorUserId` to the account model).
fn impersonator_id<'a>(ctx: &'a Context, user: &User) -> Option<&'a str> {
    match (&ctx.target_user, &ctx.user) {
        (Some(target), Some(actor)) if target.id == user.id => Some(actor.id.as_str()),
        _ => None,
    }
}

/// `$response->dynamic($user, Response::MODEL_ACCOUNT)`.
pub fn account(ctx: &Context, user: &User) -> Response {
    ctx.ok(&AccountModel { user, render: ctx.render(), impersonator_user_id: impersonator_id(ctx, user) })
}

/// `$response->output($user, Response::MODEL_ACCOUNT)`: an event payload.
pub fn account_payload(ctx: &Context, user: &User) -> Bytes {
    Bytes::from(appwrite_core::response::current(&AccountModel {
        user,
        render: ctx.render(),
        impersonator_user_id: None,
    }))
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
    /// The metadata of an address, or `None` when it cannot be parsed.
    pub fn of(email: &str) -> Option<Self> {
        let parsed = Email::new(email).ok()?;
        let canonical = parsed.canonical().ok()?;
        Some(Self {
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
        document.set("emailCanonical", text(self.canonical.as_deref()));
        document.set("emailIsCanonical", flag(self.is_canonical));
        document.set("emailIsCorporate", flag(self.is_corporate));
        document.set("emailIsDisposable", flag(self.is_disposable));
        document.set("emailIsFree", flag(self.is_free));
    }
}

/// Email policy gates (`disposable`, `canonical`, `free`, `corporate`).
pub fn check_email_policy(ctx: &Context, meta: &Metadata) -> Result<()> {
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

/// `read`, `update` and `delete` for the user (`Role::user($userId)`).
pub fn owner_permissions(user_id: &str) -> Value {
    let role = Role::user(user_id, "");
    list(
        [Permission::read(&role), Permission::update(&role), Permission::delete(&role)]
            .map(|p| Value::from(p.as_str())),
    )
}

/// `User::invalidateAuthentication`: deletes the user's sessions (but
/// `keep`) and MFA challenges.
pub async fn invalidate_authentication(db: &Database, user: &User, keep: Option<&str>) -> Result<()> {
    for session in &user.sessions {
        if keep == Some(session.id.as_str()) {
            continue;
        }
        db.delete_document("sessions", &session.id).await?;
    }
    if user.sequence == 0 {
        return Ok(());
    }
    db.delete_documents(
        "challenges",
        vec![Query::equal("userInternalId", [documents::internal_id(user.sequence)])],
        Database::DELETE_BATCH_SIZE,
        None,
        None,
    )
    .await?;
    Ok(())
}

// ---- tokens -----------------------------------------------------------------

/// `proofForToken`/`proofForCode->hash($secret)`: SHA-256 hex.
pub fn secret_hash(secret: &str) -> Result<String> {
    let hashed = Sha::new().hash(secret.as_bytes()).map_err(|e| Error::internal(e.to_string()))?;
    String::from_utf8(hashed).map_err(|e| Error::internal(e.to_string()))
}

/// A new token document (`tokens`), without its permissions.
pub fn new_token(
    ctx: &Context,
    user_id: &str,
    user_internal_id: Value,
    kind: i64,
    secret: &str,
    expire: &Value,
) -> Result<Document> {
    let mut token = Document::default();
    token.set("$id", Value::from(appwrite_core::database::unique_id().as_str()));
    token.set("userId", Value::from(user_id));
    token.set("userInternalId", user_internal_id);
    token.set("type", Value::Int(kind));
    token.set("secret", Value::from(secret_hash(secret)?.as_str()));
    token.set("expire", expire.clone());
    token.set("userAgent", Value::from(ctx.user_agent("UNKNOWN").as_str()));
    token.set("ip", Value::from(ctx.ip.as_str()));
    Ok(token)
}

/// `$authorization->addRole(Role::user($userId))` then
/// `createDocument('tokens', ...)` with the owner's permissions.
pub async fn create_token(ctx: &mut Context, user_id: &str, mut token: Document) -> Result<Document> {
    ctx.authorization.add_role(&format!("user:{user_id}"));
    token.set("$permissions", owner_permissions(user_id));
    let db = ctx.db()?;
    Ok(db.create_document("tokens", token).await?)
}

/// `$profile->tokenVerify($type, $secret, $proof)`: the user's first token
/// of `kind` whose secret matches and that has not expired.
pub fn token_verify(user: &Document, kind: i64, secret: &str) -> Option<Document> {
    let hashed = secret_hash(secret).ok()?;
    let now = datetime::now();
    user.documents("tokens").into_iter().find_map(|token| {
        let (Some(stored), Some(expire), Some(stored_kind)) =
            (token.string("secret"), token.datetime("expire"), token.get("type").map(to_long))
        else {
            return None;
        };
        let verified = Sha::new().verify(secret.as_bytes(), stored.as_bytes()).unwrap_or(stored == hashed);
        (stored_kind == kind && verified && datetime::truncate(expire) >= datetime::truncate(now))
            .then(|| token.clone())
    })
}

/// The token model with its secret shown: the event payload
/// (`showSensitive(fn () => output($token, MODEL_TOKEN))`).
pub fn token_payload(token: &Token) -> Bytes {
    Bytes::from(appwrite_core::response::current(&TokenModel {
        token,
        secret: None,
        phrase: "",
        reveal: false,
        render: Render { sensitive: true, format: None },
    }))
}

// ---- queues -----------------------------------------------------------------

/// `$mqtt->revoke($projectId, $userId)`: asks the MQTT brokers to drop the
/// user's connections. Failures are logged, as in PHP.
pub async fn mqtt_revoke(ctx: &Context, user_id: &str) {
    let message = appwrite_core::json::to_string(&json!({
        "action": "revoke",
        "project": ctx.project.id,
        "userId": user_id,
    }));
    let mut pubsub = ctx.state.pubsub.clone();
    let sent: std::result::Result<i64, _> =
        utopia_cache::redis::cmd("PUBLISH").arg("mqtt").arg(message).query_async(&mut pubsub).await;
    if let Err(e) = sent {
        tracing::error!(error = %e, "Failed to revoke MQTT connections");
    }
}

// ---- templates ----------------------------------------------------------------

/// `Template::fromFile(<templates>/<name>)`.
pub async fn template_file(ctx: &Context, name: &str) -> Result<Template> {
    let path = ctx.state.config.asset(&format!("app/config/locale/templates/{name}"));
    let content = tokio::fs::read_to_string(&path)
        .await
        .map_err(|_| Error::internal(format!("{} view template is not readable.", path.display())))?;
    Ok(Template::new(content))
}

/// `Template::fromString($content)`: the content through the `nl2p` filter.
pub fn template_text(content: &str) -> Template {
    let mut paragraphs = String::new();
    for line in content.split("\n\n") {
        if !line.trim().is_empty() {
            paragraphs.push_str("<p>");
            paragraphs.push_str(line);
            paragraphs.push_str("</p>");
        }
    }
    Template::new(paragraphs.replace('\n', "<br />"))
}

// ---- mails ------------------------------------------------------------------

/// A mail an account route queues (`MailMessage`): its locale texts are
/// under `emails.<key>`, its custom template is `email.<key>-<locale>`.
pub struct Mail {
    pub key: &'static str,
    pub template: &'static str,
    pub recipient: String,
    pub name: String,
    pub subject: String,
    pub preview: String,
    pub body: String,
    pub variables: Map<String, Json>,
}

/// The project's SMTP base template (`smtpBaseTemplate`): the console's
/// branded one, else the project's (`email-base` by default).
pub fn base_template(ctx: &Context) -> String {
    if ctx.project.is_console() {
        return "email-base-styled".to_owned();
    }
    ctx.project.smtp_base_template.clone().filter(|t| !t.is_empty()).unwrap_or_else(|| "email-base".to_owned())
}

/// `$project->getAttribute('name')`, or the platform's name on the console.
pub fn project_name(ctx: &Context) -> String {
    if ctx.project.is_console() {
        return ctx.state.config.platform.get("platformName").and_then(Json::as_str).unwrap_or("").to_owned();
    }
    if ctx.project.id.is_empty() { "Console".to_owned() } else { ctx.project.name.clone() }
}

/// The branded base template's variables, appended.
pub fn branded_variables(ctx: &Context, variables: &mut Map<String, Json>) {
    if base_template(ctx) != "email-base-styled" {
        return;
    }
    let platform = &ctx.state.config.platform;
    let get = |k: &str| platform.get(k).cloned().unwrap_or(Json::Null);
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
        variables.insert(key.to_owned(), get(from));
    }
}

fn non_empty(v: Option<&Json>) -> Option<Json> {
    v.filter(|v| !php_std::value::empty(v)).cloned()
}

/// Queues `mail` (`publisherForMails`) with the project's SMTP settings and
/// custom template.
pub fn queue_mail(ctx: &mut Context, mail: Mail) {
    let project = ctx.project.clone();
    let templates = &project.templates;
    let template = |locale: &str| templates.get(format!("email.{}-{locale}", mail.key)).cloned();
    let custom = template(&ctx.locale).or_else(|| template("en")).unwrap_or(Json::Array(Vec::new()));
    let body_template = format!("{TEMPLATES_PHP}{}.tpl", base_template(ctx));

    let smtp = &project.smtp;
    let mut subject = Json::String(mail.subject);
    let mut body = Json::String(mail.body);
    let mut smtp_config = Json::Array(Vec::new());
    if smtp.get("enabled").is_some_and(utopia_validators::php::truthy) {
        let mut sender_email = Json::String(ctx.state.config.system_email_address.clone());
        let mut sender_name = Json::String(ctx.state.config.system_email_name.clone());
        let mut reply_email = Json::String(String::new());
        let mut reply_name = Json::String(String::new());
        let apply = |source: &Json,
                     sender_email: &mut Json,
                     sender_name: &mut Json,
                     reply_email: &mut Json,
                     reply_name: &mut Json| {
            if let Some(v) = non_empty(source.get("senderEmail")) {
                *sender_email = v;
            }
            if let Some(v) = non_empty(source.get("senderName")) {
                *sender_name = v;
            }
            // Includes backwards compatibility: the legacy `replyTo` key.
            let reply = source.get("replyToEmail").filter(|v| !v.is_null()).or_else(|| source.get("replyTo"));
            if let Some(v) = non_empty(reply) {
                *reply_email = v;
            }
            if let Some(v) = non_empty(source.get("replyToName")) {
                *reply_name = v;
            }
        };
        apply(smtp, &mut sender_email, &mut sender_name, &mut reply_email, &mut reply_name);
        if !php_std::value::empty(&custom) {
            apply(&custom, &mut sender_email, &mut sender_name, &mut reply_email, &mut reply_name);
            body = custom.get("message").filter(|v| !v.is_null()).cloned().unwrap_or(Json::String(String::new()));
            if let Some(s) = custom.get("subject").filter(|v| !v.is_null()) {
                subject = s.clone();
            }
        }
        let field = |k: &str| smtp.get(k).filter(|v| !v.is_null()).cloned().unwrap_or(Json::String(String::new()));
        smtp_config = json!({
            "host": field("host"),
            "port": field("port"),
            "username": field("username"),
            "password": field("password"),
            "secure": field("secure"),
            "replyToEmail": reply_email,
            "replyToName": reply_name,
            "senderEmail": sender_email,
            "senderName": sender_name,
        });
    }

    let platform = ctx.state.config.platform.clone();
    let custom_options = if project.is_console() {
        json!({"senderName": platform.get("emailSenderName").cloned().unwrap_or(Json::Null)})
    } else {
        Json::Array(Vec::new())
    };
    let message = json!({
        "project": project.message_payload(),
        "recipient": mail.recipient,
        "name": mail.name,
        "subject": subject,
        "template": mail.template,
        "bodyTemplate": body_template,
        "body": body,
        "preview": mail.preview,
        "smtp": smtp_config,
        "variables": mail.variables,
        "attachment": [],
        "customMailOptions": custom_options,
        "events": [],
        "platform": platform,
        "headers": [],
    });
    let queue = ctx.state.config.queue_mails.clone();
    ctx.events.queued.push((queue, message));
}

// ---- request resources -------------------------------------------------------

/// The `redirectValidator` resource (`Appwrite\Network\Validator\Redirect`).
pub struct Redirect<'a> {
    ctx: &'a Context,
    description: std::sync::Mutex<String>,
}

impl<'a> Redirect<'a> {
    pub fn new(ctx: &'a Context) -> Self {
        Self { ctx, description: std::sync::Mutex::new("Invalid URI.".to_owned()) }
    }
}

impl Validator for Redirect<'_> {
    fn description(&self) -> String {
        self.description.lock().map(|d| d.clone()).unwrap_or_default()
    }

    fn is_valid(&self, value: &Json) -> bool {
        let Json::String(url) = value else { return false };
        match self.ctx.validate_redirect(url) {
            Ok(()) => true,
            Err(description) => {
                if let Ok(mut d) = self.description.lock() {
                    *d = description;
                }
                false
            }
        }
    }
}

/// The `domainVerification` resource: the origin (or referer) shares the
/// request host's registrable domain.
pub fn domain_verification(ctx: &Context) -> bool {
    let referer = ctx.request.referer("");
    let origin = ctx.request.origin(&referer);
    let origin = appwrite_core::network::url_host(&origin).unwrap_or_default();
    let registerable = |host: &str| utopia_domains::Domain::new(host).map(|d| d.registerable()).unwrap_or_default();
    let end = registerable(&origin);
    registerable(&ctx.hostname) == end && !end.is_empty()
}

/// The `cookieDomain` resource: none on localhost or an IP, else the
/// request's host (the console's registrable domain with root sessions).
pub fn cookie_domain(ctx: &Context) -> Option<String> {
    let port = ctx.request.port();
    let mut local = vec!["localhost".to_owned(), format!("localhost:{port}")];
    if let Some(migration) = utopia_system::env("_APP_MIGRATION_HOST") {
        local.push(format!("{migration}:{port}"));
        local.push(migration);
    }
    let hostname = ctx.request.hostname();
    if local.contains(&hostname) || hostname.parse::<std::net::IpAddr>().is_ok() {
        return None;
    }
    if ctx.project.is_console() && utopia_system::env_or("_APP_CONSOLE_ROOT_SESSION", "disabled") == "enabled" {
        let registerable = utopia_domains::Domain::new(&hostname).map(|d| d.registerable()).unwrap_or_default();
        return Some(format!(".{registerable}"));
    }
    Some(format!(".{hostname}"))
}

/// The session cookie's name (`$store->getKey()`).
pub fn cookie_name(ctx: &Context) -> String {
    let project = if ctx.mode == appwrite_core::platform::Mode::Admin { "console" } else { ctx.project.id.as_str() };
    format!("a_session_{project}")
}

// ---- deletes -------------------------------------------------------------------

/// `Deletes\Targets::delete`: the user's targets, each with its subscribers
/// (decrementing their topics' totals).
pub async fn delete_user_targets(db: &Database, user_sequence: i64) -> Result<()> {
    db.delete_documents(
        "targets",
        vec![Query::equal("userInternalId", [documents::internal_id(user_sequence)]), Query::order_asc("")],
        Database::DELETE_BATCH_SIZE,
        Some(&mut DeleteSubscribers { db }),
        None,
    )
    .await?;
    Ok(())
}

/// `Deletes\Targets::deleteSubscribers`, for each deleted target.
struct DeleteSubscribers<'a> {
    db: &'a Database,
}

#[async_trait]
impl Callback<(Document, Document)> for DeleteSubscribers<'_> {
    async fn call(&mut self, (target, _): (Document, Document)) -> utopia_database::Result<()> {
        let mut topics = DecreaseTopic { db: self.db, provider_type: target.string_or_empty("providerType") };
        self.db
            .delete_documents(
                "subscribers",
                vec![
                    Query::equal("targetInternalId", [target.sequence().cloned().unwrap_or(Value::Null)]),
                    Query::order_asc(""),
                ],
                Database::DELETE_BATCH_SIZE,
                Some(&mut topics),
                None,
            )
            .await?;
        Ok(())
    }
}

/// Decrements the subscriber total of a deleted subscriber's topic.
struct DecreaseTopic<'a> {
    db: &'a Database,
    provider_type: String,
}

#[async_trait]
impl Callback<(Document, Document)> for DecreaseTopic<'_> {
    async fn call(&mut self, (subscriber, _): (Document, Document)) -> utopia_database::Result<()> {
        let db = self.db;
        let topic_id = subscriber.string_or_empty("topicId");
        let topic_internal_id = subscriber.get("topicInternalId").cloned().unwrap_or(Value::Null);
        let skipped = filters::names(filters::TOPICS_SUBQUERIES);
        let topic = db
            .skip_filters(
                || {
                    db.find_one(
                        "topics",
                        vec![Query::select(["$id", "$sequence"]), Query::equal("$sequence", [topic_internal_id])],
                    )
                },
                Some(&skipped),
            )
            .await?;
        if topic.is_empty() {
            return Ok(());
        }
        let total = match self.provider_type.as_str() {
            "email" => "emailTotal",
            "sms" => "smsTotal",
            "push" => "pushTotal",
            _ => return Err(utopia_database::Error::new(Kind::Exception, "Invalid target provider type")),
        };
        let decreased = db
            .skip_filters(
                || db.decrease_document_attribute("topics", &topic_id, total, Value::Int(1), Some(Value::Int(0))),
                Some(&skipped),
            )
            .await;
        match decreased {
            Err(e) if e.is_a(Kind::Limit) => {
                tracing::error!(topic = topic_id, error = %e, "Delete subscribers decreaseDocumentAttribute");
                Ok(())
            }
            other => other.map(|_| ()),
        }
    }
}

/// `Deletes\Identities::delete`: the user's identities.
pub async fn delete_user_identities(db: &Database, user_sequence: i64) -> Result<()> {
    db.delete_documents(
        "identities",
        vec![Query::equal("userInternalId", [documents::internal_id(user_sequence)]), Query::order_asc("")],
        Database::DELETE_BATCH_SIZE,
        None,
        None,
    )
    .await?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn text_templates_are_paragraphs() {
        let t = template_text("Hello {{user}}\n\nBye\nnow").param("{{user}}", "<Ada>", true);
        assert_eq!(t.render(), "<p>Hello &lt;Ada&gt;</p><p>Bye<br />now</p>");
    }

    /// A token of `kind` expiring `seconds` from now.
    fn token(kind: i64, secret: &str, seconds: i64) -> Value {
        let mut token = Document::default();
        token.set("$id", Value::from(format!("t{kind}{seconds}").as_str()));
        token.set("type", Value::Int(kind));
        token.set("secret", Value::from(secret_hash(secret).unwrap().as_str()));
        token.set("expire", datetime::value(&datetime::add_seconds(datetime::now(), seconds)));
        Value::from(token)
    }

    #[test]
    fn tokens_verify_by_kind_secret_and_expiry() {
        let mut user = Document::default();
        user.set(
            "tokens",
            list([
                token(TOKEN_TYPE_RECOVERY, "old", -60),
                token(TOKEN_TYPE_VERIFICATION, "s3cret", 60),
                token(TOKEN_TYPE_RECOVERY, "s3cret", 60),
            ]),
        );
        let found = token_verify(&user, TOKEN_TYPE_RECOVERY, "s3cret").expect("a valid recovery token");
        assert_eq!(found.id(), "t360");
        assert!(token_verify(&user, TOKEN_TYPE_RECOVERY, "old").is_none(), "expired");
        assert!(token_verify(&user, TOKEN_TYPE_PHONE, "s3cret").is_none(), "another kind");
        assert!(token_verify(&user, TOKEN_TYPE_RECOVERY, "wrong").is_none(), "another secret");
    }
}
