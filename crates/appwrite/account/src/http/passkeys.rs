//! `/v1/account/passkeys` and `/v1/account/tokens/passkey`: registering the
//! caller's passkeys, managing them, and signing in with one.

use serde_json::{Map, Value as Json, json};
use utopia_auth::passkeys::{Ceremony, RelyingParty, TIMEOUT};
use utopia_database::query::Grouped;
use utopia_database::{Database, Document, Query, Value};
use utopia_http::Response;
use utopia_validators::{Assoc, Text};

use appwrite_core::database::documents::{self, Attributes, Authenticator, Token, User};
use appwrite_core::database::{LIMIT_COUNT, collections, filters};
use appwrite_core::datetime;
use appwrite_core::platform::{Audit, Context, Labels, Route};
use appwrite_core::response::{PasskeyChallengeModel, PasskeyListModel, PasskeyModel, TokenModel};
use appwrite_core::validators::{CustomId, uid};
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, token_type};
use crate::{GROUPS, route};

/// `Ceremony::TYPE`, and the challenge types.
const PASSKEY: &str = "passkey";
const REGISTRATION: &str = "passkeyRegistration";
const AUTHENTICATION: &str = "passkeyAuthentication";
/// `APP_LIMIT_USER_PASSKEYS`.
const LIMIT: usize = 10;
/// `PASSKEY_TOKEN_DURATION`.
const TOKEN_DURATION: i64 = 60;

pub fn routes() -> Vec<Route> {
    let ceremony = Labels { abuse_limit: 10, abuse_key: &["url:{url},userId:{userId}"], ..Labels::NONE };
    vec![
        route!(
            POST,
            "/v1/account/passkeys",
            "createPasskey",
            "createPasskey",
            &["account"],
            None,
            None,
            &["api", "account", "auth", "recentSession"],
            Labels { auth_type: "passkey", ..ceremony },
            create
        ),
        route!(
            PUT,
            "/v1/account/passkeys/:passkeyId/verification",
            "updatePasskeyVerification",
            "updatePasskeyVerification",
            &["account"],
            None,
            Some(Audit { event: "passkey.create", resource: "user/{user.$id}" }),
            &["api", "account", "recentSession"],
            ceremony,
            verify
        ),
        route!(
            GET,
            "/v1/account/passkeys",
            "listPasskeys",
            "listPasskeys",
            &["account"],
            None,
            None,
            GROUPS,
            Labels::NONE,
            list
        ),
        route!(
            GET,
            "/v1/account/passkeys/:passkeyId",
            "getPasskey",
            "getPasskey",
            &["account"],
            None,
            None,
            GROUPS,
            Labels::NONE,
            get
        ),
        route!(
            PATCH,
            "/v1/account/passkeys/:passkeyId",
            "updatePasskey",
            "updatePasskey",
            &["account"],
            None,
            Some(Audit { event: "passkey.update", resource: "user/{user.$id}" }),
            GROUPS,
            Labels::NONE,
            update
        ),
        route!(
            DELETE,
            "/v1/account/passkeys/:passkeyId",
            "deletePasskey",
            "deletePasskey",
            &["account"],
            None,
            Some(Audit { event: "passkey.delete", resource: "user/{user.$id}" }),
            &["api", "account", "recentSession"],
            Labels::NONE,
            delete
        ),
    ]
}

fn caller(ctx: &Context) -> Result<User> {
    ctx.user.clone().ok_or_else(|| Error::new(ErrorType::UserUnauthorized))
}

/// The `session` resource these actions take as a `Document`.
fn session_id(ctx: &Context) -> Result<String> {
    ctx.session.as_ref().map(|s| s.id.clone()).ok_or_else(|| Error::internal("session resource is null"))
}

/// A passkey library failure: an invalid credential, else a server error.
fn passkey_error(e: utopia_auth::Error) -> Error {
    match e {
        utopia_auth::Error::Passkey(_) => Error::new(ErrorType::UserPasskeyInvalid),
        other => Error::internal(other.to_string()),
    }
}

// ---- ceremonies --------------------------------------------------------------

/// Web platforms' hostnames, lower case.
fn web_hostnames(ctx: &Context) -> Vec<String> {
    ctx.project
        .platforms
        .iter()
        .filter(|p| appwrite_core::database::project::map_platform_type(&p.kind.to_lowercase()) == "web")
        .filter(|p| !p.hostname.is_empty())
        .map(|p| p.hostname.to_lowercase())
        .collect()
}

/// The origins of the relying party `rp_id` among the project's platforms.
fn relying_party_origins(ctx: &Context, rp_id: &str) -> Vec<String> {
    let hostnames = web_hostnames(ctx);
    if rp_id == "localhost" {
        return if hostnames.iter().any(|h| h == "localhost") {
            vec!["http://localhost".to_owned(), "https://localhost".to_owned()]
        } else {
            Vec::new()
        };
    }
    let mut origins: Vec<String> = Vec::new();
    for platform in &ctx.project.platforms {
        let kind = appwrite_core::database::project::map_platform_type(&platform.kind.to_lowercase());
        if kind == "apple" {
            origins.push(format!("https://{rp_id}"));
        }
        if kind == "android" {
            for fingerprint in &platform.fingerprints {
                let bytes = hex::decode(fingerprint.replace(':', "")).unwrap_or_default();
                let encoded = php_std::encoding::base64_encode(&bytes).replace('+', "-").replace('/', "_");
                origins.push(format!("android:apk-key-hash:{}", encoded.trim_end_matches('=')));
            }
        }
    }
    for hostname in hostnames {
        let domain = hostname.strip_prefix("*.").unwrap_or(&hostname);
        if !domain.contains('*') && (domain == rp_id || domain.ends_with(&format!(".{rp_id}"))) {
            origins.push(format!("https://{hostname}"));
        }
    }
    let mut unique: Vec<String> = Vec::new();
    for o in origins {
        if !unique.contains(&o) {
            unique.push(o);
        }
    }
    unique
}

/// `Ceremony::fromProject($project, $request->getOrigin())`.
fn ceremony(ctx: &Context) -> Option<Ceremony> {
    let auths = &ctx.project.auths;
    let mut id = auths.get("passkeyRpId").and_then(Json::as_str).unwrap_or("").to_owned();
    let name = ctx.project.name.clone();
    if let Some(origins) = auths.get("passkeyOrigins").and_then(Json::as_array).filter(|o| !o.is_empty()) {
        let origins = origins.iter().filter_map(|o| o.as_str().map(str::to_owned)).collect();
        return (!id.is_empty()).then(|| Ceremony::new(RelyingParty::new(id, name, origins)));
    }
    let host = appwrite_core::network::url_host(ctx.request.header_or_empty("origin")).unwrap_or_default();
    if host == "localhost" && web_hostnames(ctx).iter().any(|h| h == "localhost") {
        id = "localhost".to_owned();
    }
    let origins = if id.is_empty() { Vec::new() } else { relying_party_origins(ctx, &id) };
    (!origins.is_empty()).then(|| Ceremony::new(RelyingParty::new(id, name, origins)))
}

fn not_configured() -> Error {
    Error::with_message(
        ErrorType::UserAuthMethodUnsupported,
        "Passkeys are not configured for this project. Set a relying party ID in the passkey policy and add your app's domain as a web platform.",
    )
}

/// `Challenges::issue()`: stores a ceremony's state, bound to its purpose,
/// owner, relying party and `binding`.
async fn issue(
    ctx: &mut Context,
    id: &str,
    kind: &str,
    ceremony: &Ceremony,
    state: &str,
    user: Option<&User>,
    binding: &[(&str, &str)],
) -> Result<Document> {
    let mut passkey = Map::new();
    passkey.insert("version".into(), json!(1));
    passkey.insert("relyingParty".into(), Json::String(ceremony.relying_party.fingerprint()));
    let binding: Map<String, Json> =
        binding.iter().map(|(k, v)| ((*k).to_owned(), Json::String((*v).to_owned()))).collect();
    passkey.insert("binding".into(), if binding.is_empty() { json!([]) } else { Json::Object(binding) });
    passkey.insert("state".into(), Json::String(state.to_owned()));
    let mut challenge = Document::default();
    challenge.set("$id", Value::from(id));
    challenge.set("userId", documents::text(user.map(|u| u.id.as_str())));
    challenge.set("userInternalId", user.map(|u| documents::internal_id(u.sequence)).unwrap_or(Value::Null));
    challenge.set("type", Value::from(kind));
    challenge.set("expire", datetime::value(&datetime::add_seconds(datetime::now(), TIMEOUT)));
    challenge.set("passkey", Value::from_json(&Json::Object(passkey)));
    let db = ctx.db()?;
    Ok(db.authorization().skip(|| db.create_document("challenges", challenge)).await?)
}

/// `Challenges::consume()`: the state of a challenge issued for this
/// purpose, owner, relying party and binding, deleted so it is used once.
async fn consume(
    ctx: &mut Context,
    id: &str,
    kind: &str,
    ceremony: &Ceremony,
    user: Option<&User>,
    binding: &[(&str, &str)],
) -> Result<String> {
    let db = ctx.db()?;
    let challenge = db.authorization().skip(|| db.get_document("challenges", id, Vec::new(), false)).await?;
    let passkey = challenge.json("passkey").unwrap_or(Json::Null);
    let expected_binding: Map<String, Json> =
        binding.iter().map(|(k, v)| ((*k).to_owned(), Json::String((*v).to_owned()))).collect();
    let stored_binding = match passkey.get("binding") {
        Some(Json::Object(m)) => Some(m.clone()),
        Some(Json::Array(a)) if a.is_empty() => Some(Map::new()),
        _ => None,
    };
    let owner = user.map(|u| u.sequence.to_string());
    let valid = !challenge.is_empty()
        && challenge.string("type").as_deref() == Some(kind)
        && challenge.string("userInternalId") == owner
        && challenge.datetime("expire").is_some_and(|e| e >= datetime::now())
        && passkey.get("version") == Some(&json!(1))
        && stored_binding.is_some_and(|b| b.iter().eq(expected_binding.iter()))
        && passkey.get("relyingParty").and_then(Json::as_str) == Some(ceremony.relying_party.fingerprint().as_str());
    if !valid {
        return Err(Error::new(ErrorType::UserInvalidToken));
    }
    if !db.authorization().skip(|| db.delete_document("challenges", id)).await? {
        return Err(Error::new(ErrorType::UserInvalidToken));
    }
    Ok(passkey.get("state").and_then(Json::as_str).unwrap_or("").to_owned())
}

/// A JSON object as the PHP array the passkey library reads.
fn php_array(value: &Json) -> php_std::types::Array {
    match php_std::Value::from_json(value) {
        php_std::Value::Array(a) => a,
        _ => php_std::types::Array::new(),
    }
}

/// The options of a started ceremony as JSON.
fn options_json(options: &php_std::types::Array) -> Json {
    match php_std::Value::Array(options.clone()).to_json() {
        Json::Array(a) if a.is_empty() => Json::Object(Map::new()),
        other => other,
    }
}

// ---- registration ----------------------------------------------------------------

/// The user's passkeys, verified or still inside their ceremony window; the
/// abandoned registrations are deleted.
async fn passkeys_of(ctx: &mut Context, user: &User) -> Result<Vec<Document>> {
    let db = ctx.db()?;
    let passkeys = db
        .find(
            "authenticators",
            vec![
                Query::equal("userInternalId", [documents::internal_id(user.sequence)]),
                Query::equal("type", [PASSKEY]),
                Query::limit(filters::LIMIT_SUBQUERY),
            ],
            Database::PERMISSION_READ,
        )
        .await?;
    let abandoned = datetime::add_seconds(datetime::now(), -TIMEOUT);
    let mut kept = Vec::with_capacity(passkeys.len());
    for passkey in passkeys {
        let verified = passkey.boolean("verified") == Some(true);
        if verified || passkey.datetime("$createdAt").is_some_and(|c| c >= abandoned) {
            kept.push(passkey);
        } else {
            db.delete_document("authenticators", passkey.id()).await?;
        }
    }
    Ok(kept)
}

/// Whether a passkey is a pending registration of the user.
fn pending(passkey: &Document, user: &User) -> bool {
    !passkey.is_empty()
        && passkey.string("type").as_deref() == Some(PASSKEY)
        && passkey.string_or_empty("userInternalId") == user.sequence.to_string()
        && passkey.boolean("verified") != Some(true)
}

/// `POST /v1/account/passkeys`.
pub async fn create(ctx: &mut Context) -> Result<Response> {
    let passkey_id = ctx.optional_str("passkeyId", "unique()", &CustomId)?.unwrap_or_else(|| "unique()".to_owned());
    let name = ctx.optional_str("name", "", &Text::with_min(128, 0))?.unwrap_or_default();
    let user = caller(ctx)?;
    let session = session_id(ctx)?;
    let Some(ceremony) = ceremony(ctx) else {
        return Err(not_configured());
    };
    let nonempty = |v: &Option<String>| v.clone().filter(|s| !s.is_empty() && s != "0");
    let user_name = nonempty(&user.email)
        .or_else(|| nonempty(&user.phone))
        .or_else(|| nonempty(&user.name))
        .unwrap_or_else(|| user.id.clone());
    let passkey_id = if passkey_id == "unique()" { appwrite_core::database::unique_id() } else { passkey_id };

    // A restarted registration replaces the pending one.
    let db = ctx.db()?;
    let restart = db
        .with_transaction(|| {
            let db = db.clone();
            let user = user.clone();
            let passkey_id = passkey_id.clone();
            async move {
                let existing = db.get_document("authenticators", &passkey_id, Vec::new(), true).await?;
                if pending(&existing, &user) {
                    db.delete_document("authenticators", &passkey_id).await?;
                }
                Ok(())
            }
        })
        .await;
    restart?;

    let passkeys = passkeys_of(ctx, &user).await?;
    if passkeys.len() >= LIMIT {
        return Err(Error::new(ErrorType::UserPasskeyLimitExceeded));
    }
    let records: Vec<php_std::types::Array> = passkeys
        .iter()
        .filter(|p| p.boolean("verified") == Some(true))
        .map(|p| php_array(p.json("data").as_ref().and_then(|d| d.get("record")).unwrap_or(&Json::Null)))
        .collect();
    let display = nonempty(&user.name).unwrap_or_else(|| user_name.clone());
    let challenge = ceremony
        .register(user_name.as_bytes(), display.as_bytes(), &records)
        .map_err(|e| Error::internal(e.to_string()))?;
    let challenge_id = appwrite_core::database::unique_id();

    let mut passkey = Document::default();
    passkey.set("$id", Value::from(passkey_id.as_str()));
    passkey.set("$permissions", base::owner_permissions(&user.id));
    passkey.set("userId", Value::from(user.id.as_str()));
    passkey.set("userInternalId", documents::internal_id(user.sequence));
    passkey.set("type", Value::from(PASSKEY));
    passkey.set("verified", Value::Bool(false));
    passkey.set("name", Value::from(name.as_str()));
    passkey.set("data", Value::from_json(&json!({"challengeId": challenge_id})));
    let db = ctx.db()?;
    let passkey = match db.create_document("authenticators", passkey).await {
        Ok(p) => p,
        Err(e) if e.is_a(utopia_database::Kind::Duplicate) => {
            return Err(Error::with_message(
                ErrorType::UserPasskeyAlreadyExists,
                "A passkey with the requested ID already exists.",
            ));
        }
        Err(e) => return Err(e.into()),
    };
    if passkeys_of(ctx, &user).await?.len() > LIMIT {
        db.delete_document("authenticators", passkey.id()).await?;
        return Err(Error::new(ErrorType::UserPasskeyLimitExceeded));
    }
    let stored = issue(
        ctx,
        &challenge_id,
        REGISTRATION,
        &ceremony,
        &challenge.state,
        Some(&user),
        &[("passkeyId", passkey.id()), ("sessionId", &session)],
    )
    .await?;
    db.purge_cached_document("users", Some(&user.id)).await?;
    let options = options_json(&challenge.options);
    Ok(ctx.created(&PasskeyChallengeModel {
        id: stored.id(),
        created_at: &stored.datetime("$createdAt"),
        passkey_id: passkey.id(),
        expire: &stored.datetime("expire"),
        public_key: &options,
    }))
}

/// `PUT /v1/account/passkeys/:passkeyId/verification`.
pub async fn verify(ctx: &mut Context) -> Result<Response> {
    let passkey_id = ctx.required_str("passkeyId", &uid())?;
    let credential = ctx.required("credential", &Assoc::default())?;
    let user = caller(ctx)?;
    let session = session_id(ctx)?;
    let Some(ceremony) = ceremony(ctx) else {
        return Err(Error::new(ErrorType::UserInvalidToken));
    };
    let db = ctx.db()?;
    let passkey = db.get_document("authenticators", &passkey_id, Vec::new(), false).await?;
    if !pending(&passkey, &user) {
        return Err(Error::new(ErrorType::UserPasskeyNotFound));
    }
    let challenge_id = passkey
        .json("data")
        .and_then(|d| d.get("challengeId").and_then(Json::as_str).map(str::to_owned))
        .unwrap_or_default();
    let state = consume(
        ctx,
        &challenge_id,
        REGISTRATION,
        &ceremony,
        Some(&user),
        &[("passkeyId", &passkey_id), ("sessionId", &session)],
    )
    .await?;
    let verified = ceremony.verify_registration(state.as_bytes(), &php_array(&credential)).map_err(passkey_error)?;
    let record = php_std::Value::Array(verified.record.clone()).to_json();
    let updated = db
        .with_transaction(|| {
            let db = db.clone();
            let user = user.clone();
            let passkey_id = passkey_id.clone();
            let challenge_id = challenge_id.clone();
            let identifier = verified.identifier.clone();
            let record = record.clone();
            async move {
                let current = db.get_document("authenticators", &passkey_id, Vec::new(), true).await?;
                let same = current.json("data").and_then(|d| d.get("challengeId").cloned())
                    == Some(Json::String(challenge_id));
                if !pending(&current, &user) || !same {
                    return Err(utopia_database::Error::new(utopia_database::Kind::NotFound, "passkey not pending"));
                }
                let mut updates = Document::default();
                updates.set("verified", Value::Bool(true));
                updates.set("identifier", Value::from(identifier.as_str()));
                updates.set("data", Value::from_json(&json!({"record": record})));
                db.update_document("authenticators", &passkey_id, updates).await
            }
        })
        .await;
    let updated = match updated {
        Ok(d) => d,
        Err(e) if e.is_a(utopia_database::Kind::NotFound) => return Err(Error::new(ErrorType::UserPasskeyNotFound)),
        Err(e) if e.is_a(utopia_database::Kind::Duplicate) => {
            return Err(Error::new(ErrorType::UserPasskeyAlreadyExists));
        }
        Err(e) => return Err(e.into()),
    };
    db.purge_cached_document("users", Some(&user.id)).await?;
    Ok(ctx.ok(&PasskeyModel { passkey: &Authenticator::from(&updated) }))
}

// ---- management --------------------------------------------------------------------

/// `GET /v1/account/passkeys`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let mut queries = ctx.queries(&collections::PASSKEYS)?;
    let include_total = ctx.optional_bool("total", true)?;
    let user = caller(ctx)?;
    queries.push(Query::equal("userInternalId", [documents::internal_id(user.sequence)]));
    queries.push(Query::equal("type", [PASSKEY]));
    queries.push(Query::equal("verified", [true]));
    let db = ctx.db()?;
    if let Some(cursor) = queries.iter().find(|q| {
        matches!(q.method(), utopia_database::query::Method::CursorAfter | utopia_database::query::Method::CursorBefore)
    }) {
        let validator = utopia_database::validator::query::cursor::Cursor::default();
        if !validator.check(cursor) {
            return Err(Error::with_message(ErrorType::GeneralQueryInvalid, validator.description()));
        }
        let id = cursor.value().map(utopia_database::value::to_text).unwrap_or_default();
        let document = db.get_document("authenticators", &id, Vec::new(), false).await?;
        let owned = !document.is_empty()
            && document.string("type").as_deref() == Some(PASSKEY)
            && document.string_or_empty("userInternalId") == user.sequence.to_string();
        if !owned {
            return Err(Error::with_message(
                ErrorType::GeneralCursorNotFound,
                format!("Passkey '{id}' for the 'cursor' value not found."),
            ));
        }
        if let Some(cursor) = queries.iter_mut().find(|q| {
            matches!(
                q.method(),
                utopia_database::query::Method::CursorAfter | utopia_database::query::Method::CursorBefore
            )
        }) {
            cursor.set_value(Value::from(document));
        }
    }
    let Grouped { filters, .. } = Query::group_by_type(&queries);
    let passkeys = db.find("authenticators", queries, Database::PERMISSION_READ).await?;
    let total = if include_total { db.count("authenticators", filters, Some(LIMIT_COUNT)).await? } else { 0 };
    let passkeys: Vec<Authenticator> = documents::all(&passkeys);
    Ok(ctx.ok(&PasskeyListModel { total, passkeys: &passkeys }))
}

/// A verified passkey of the caller, else `user_passkey_not_found`.
async fn owned(ctx: &mut Context, user: &User, id: &str, verified: bool) -> Result<Authenticator> {
    let db = ctx.db()?;
    match documents::get::<Authenticator>(&db, "authenticators", id).await? {
        Some(a)
            if a.kind.as_deref() == Some(PASSKEY)
                && a.user_internal_id == user.sequence.to_string()
                && (!verified || a.verified == Some(true)) =>
        {
            Ok(a)
        }
        _ => Err(Error::new(ErrorType::UserPasskeyNotFound)),
    }
}

/// `GET /v1/account/passkeys/:passkeyId`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let passkey_id = ctx.required_str("passkeyId", &uid())?;
    let user = caller(ctx)?;
    let passkey = owned(ctx, &user, &passkey_id, true).await?;
    Ok(ctx.ok(&PasskeyModel { passkey: &passkey }))
}

/// `PATCH /v1/account/passkeys/:passkeyId`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let passkey_id = ctx.required_str("passkeyId", &uid())?;
    let name = ctx.required_str("name", &Text::new(128))?;
    let user = caller(ctx)?;
    owned(ctx, &user, &passkey_id, true).await?;
    let db = ctx.db()?;
    let mut updates = Document::default();
    updates.set("name", Value::from(name.as_str()));
    let updated = db.update_document("authenticators", &passkey_id, updates).await?;
    db.purge_cached_document("users", Some(&user.id)).await?;
    Ok(ctx.ok(&PasskeyModel { passkey: &Authenticator::from(&updated) }))
}

/// `DELETE /v1/account/passkeys/:passkeyId`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let passkey_id = ctx.required_str("passkeyId", &uid())?;
    let user = caller(ctx)?;
    owned(ctx, &user, &passkey_id, false).await?;
    let db = ctx.db()?;
    db.delete_document("authenticators", &passkey_id).await?;
    db.purge_cached_document("users", Some(&user.id)).await?;
    Ok(ctx.no_content())
}

// ---- sign-in -----------------------------------------------------------------------

/// `POST /v1/account/tokens/passkey`.
pub async fn create_token(ctx: &mut Context) -> Result<Response> {
    let Some(ceremony) = ceremony(ctx) else {
        return Err(not_configured());
    };
    let challenge = ceremony.authenticate().map_err(|e| Error::internal(e.to_string()))?;
    let id = appwrite_core::database::unique_id();
    let stored = issue(ctx, &id, AUTHENTICATION, &ceremony, &challenge.state, None, &[]).await?;
    let options = options_json(&challenge.options);
    Ok(ctx.created(&PasskeyChallengeModel {
        id: stored.id(),
        created_at: &stored.datetime("$createdAt"),
        passkey_id: "",
        expire: &stored.datetime("expire"),
        public_key: &options,
    }))
}

/// `PUT /v1/account/tokens/passkey`.
pub async fn update_token(ctx: &mut Context) -> Result<Response> {
    let challenge_id = ctx.required_str("challengeId", &uid())?;
    let credential = ctx.required("credential", &Assoc::default())?;
    let Some(ceremony) = ceremony(ctx) else {
        return Err(Error::new(ErrorType::UserInvalidToken));
    };
    // Guests call this: only the members of `PublicKeyCredential.toJSON()`.
    let mut allowed = Map::new();
    if let Json::Object(fields) = &credential {
        for key in ["id", "rawId", "type", "response", "authenticatorAttachment", "clientExtensionResults"] {
            if let Some(v) = fields.get(key) {
                allowed.insert(key.to_owned(), v.clone());
            }
        }
    }
    let credential = php_array(&Json::Object(allowed));
    let state = consume(ctx, &challenge_id, AUTHENTICATION, &ceremony, None, &[]).await?;
    let identifier = ceremony.identify(&credential).map_err(passkey_error)?;
    let db = ctx.db()?;
    let passkey = db
        .authorization()
        .skip(|| {
            db.find_one(
                "authenticators",
                vec![
                    Query::equal("identifier", [identifier.as_str()]),
                    Query::equal("type", [PASSKEY]),
                    Query::equal("verified", [true]),
                ],
            )
        })
        .await?;
    if passkey.is_empty() {
        return Err(Error::new(ErrorType::UserPasskeyInvalid));
    }
    let owner = passkey.string_or_empty("userId");
    let user = db.authorization().skip(|| documents::load_user(&db, &owner)).await?;
    let Some(user) = user.filter(|u| u.sequence.to_string() == passkey.string_or_empty("userInternalId")) else {
        return Err(Error::new(ErrorType::UserPasskeyInvalid));
    };

    let verified = db
        .with_transaction(|| {
            let db = db.clone();
            let ceremony = ceremony.clone();
            let state = state.clone();
            let credential = credential.clone();
            let id = passkey.id().to_owned();
            async move {
                let current =
                    db.authorization().skip(|| db.get_document("authenticators", &id, Vec::new(), true)).await?;
                if current.is_empty() || current.boolean("verified") != Some(true) {
                    return Ok(Err(Error::new(ErrorType::UserPasskeyInvalid)));
                }
                let mut data = current.json("data").unwrap_or(Json::Null);
                let record = php_array(data.get("record").unwrap_or(&Json::Null));
                let verified = match ceremony.verify_authentication(state.as_bytes(), &credential, &record) {
                    Ok(v) => v,
                    Err(e) => return Ok(Err(passkey_error(e))),
                };
                if let Json::Object(m) = &mut data {
                    m.insert("record".into(), php_std::Value::Array(verified.record).to_json());
                } else {
                    data = json!({"record": php_std::Value::Array(verified.record).to_json()});
                }
                let mut updates = Document::default();
                updates.set("data", Value::from_json(&data));
                updates.set("accessedAt", datetime::value(&datetime::now()));
                db.authorization().skip(|| db.update_document("authenticators", &id, updates)).await?;
                Ok(Ok(()))
            }
        })
        .await?;
    verified?;
    if user.status == Some(false) {
        return Err(Error::new(ErrorType::UserBlocked));
    }

    let secret = base::token_secret(256)?;
    let mut token = Document::default();
    token.set("$id", Value::from(appwrite_core::database::unique_id().as_str()));
    token.set("$permissions", base::owner_permissions(&user.id));
    token.set("userId", Value::from(user.id.as_str()));
    token.set("userInternalId", documents::internal_id(user.sequence));
    token.set("type", Value::Int(token_type::PASSKEY));
    token.set("secret", Value::from(base::token_hash(&secret)?.as_str()));
    token.set("expire", datetime::value(&datetime::add_seconds(datetime::now(), TOKEN_DURATION)));
    token.set("userAgent", Value::from(ctx.user_agent("UNKNOWN").as_str()));
    token.set("ip", Value::from(ctx.ip.as_str()));
    let token = Token::from(&db.authorization().skip(|| db.create_document("tokens", token)).await?);
    db.purge_cached_document("users", Some(&user.id)).await?;
    Ok(ctx.created(&TokenModel {
        token: &token,
        secret: Some(&secret),
        phrase: "",
        reveal: true,
        render: ctx.render(),
    }))
}
