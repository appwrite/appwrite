//! Shared Users-API helpers. Rust port of the pieces of
//! `Appwrite\Platform\Modules\Users\Base::createUser()`
//! (`src/Appwrite/Platform/Modules/Users/Base.php`) and common
//! error/response plumbing every `http/*` handler in this module needs.
//!
//! Simplifications versus PHP (documented, not silently dropped):
//! - `PersonalData`, disposable/canonical/free/corporate email policy, and
//!   the cloud `$plan` gate are not implemented -- self-hosted Appwrite
//!   leaves all of these disabled unless the project is `console`, which
//!   this milestone's dev-seeded project never is.
//! - Duplicate target identifiers are not de-duplicated against an existing
//!   target (PHP's `catch (Duplicate)` fallback) because collections here
//!   have no unique index; every create/verify path just creates a new
//!   target document.

use std::collections::HashMap;
use std::sync::Arc;

use appwrite_exception::Exception;
use appwrite_hooks::Hooks;
use chrono::Utc;
use serde_json::{json, Value};
use utopia_auth::{Hash, Password};
use utopia_database::helpers::{Permission, Role};
use utopia_database::{AttrValue, DatabaseError, Query};
use utopia_http::ActionContext;

use crate::state::{document_from_json, document_to_json, ProjectDatabase};

/// Write a filtered [`appwrite_response::dynamic`] JSON body plus status
/// code. Rust equivalent of PHP's `$response->setStatusCode(...)
/// ->dynamic($doc, Response::MODEL_*)`.
pub fn respond(
    ctx: &ActionContext,
    status: u16,
    model: &str,
    doc: &Value,
) -> utopia_http::Result<()> {
    let _ = ctx.response().set_status(status);
    ctx.response().json(&appwrite_response::dynamic(doc, model))
}

/// Run a handler body that returns `Result<Value, Exception>` and translate
/// it into either a [`respond`] body or the shared `Error` JSON body (the
/// same shape [`crate::modules::core::hooks::error`] produces), without each
/// `http/*` action re-writing the match arm.
pub fn finish(
    ctx: &ActionContext,
    status: u16,
    model: &str,
    result: Result<Value, Exception>,
) -> utopia_http::Result<()> {
    match result {
        Ok(doc) => respond(ctx, status, model, &doc),
        Err(exc) => crate::modules::core::hooks::send_error(ctx, &exc),
    }
}

/// `finish` for `DELETE`-style endpoints that reply `204 No Content` on
/// success (PHP `$response->noContent()`).
pub fn finish_no_content(
    ctx: &ActionContext,
    result: Result<(), Exception>,
) -> utopia_http::Result<()> {
    match result {
        Ok(()) => ctx.response().no_content(),
        Err(exc) => crate::modules::core::hooks::send_error(ctx, &exc),
    }
}

/// PHP `inject('...')`-chain builder: applies every injection in `names` to
/// `action`, panicking (at platform-build time, not per-request) on the
/// `DuplicateInjection` case the fixed name lists below never trigger.
#[must_use]
pub fn inject(action: utopia_platform::Action, names: &[&str]) -> utopia_platform::Action {
    names.iter().fold(action, |action, name| {
        action
            .inject(*name)
            .unwrap_or_else(|err| panic!("inject {name}: {err}"))
    })
}

/// Resolve the request-scoped `dbForProject` (PHP `inject('dbForProject')`).
pub fn get_db(ctx: &ActionContext) -> Result<ProjectDatabase, Exception> {
    ctx.container
        .get_as::<ProjectDatabase>("dbForProject")
        .map_err(|_| Exception::new(Exception::GENERAL_SERVER_ERROR))
}

/// Resolve the request-scoped `project` document (PHP `inject('project')`).
pub fn get_project(ctx: &ActionContext) -> Result<Value, Exception> {
    ctx.container
        .get_as::<Value>("project")
        .map_err(|_| Exception::new(Exception::GENERAL_SERVER_ERROR))
}

/// Resolve the global `hooks` registry (PHP `inject('hooks')`).
pub fn get_hooks(ctx: &ActionContext) -> Result<Arc<Hooks>, Exception> {
    ctx.container
        .get_as::<Arc<Hooks>>("hooks")
        .map_err(|_| Exception::new(Exception::GENERAL_SERVER_ERROR))
}

/// `ctx.param_str(key)?` wrapper that maps `utopia_http::HttpError` (a
/// missing/invalid param, surfaced as a plain `Result` by the framework)
/// into the same [`Exception`] shape the `api`-group `Error` hook would
/// produce for it, since `appwrite_exception::Exception` deliberately has
/// no `From<HttpError>` impl (it stays decoupled from `utopia-http`). Every
/// `http/*` handler calls this instead of `ctx.param_str` directly so `?`
/// type-checks inside a `Result<_, Exception>` closure.
pub fn param_str(ctx: &ActionContext, key: &str) -> Result<String, Exception> {
    ctx.param_str(key).map_err(param_error)
}

fn param_error(err: utopia_http::HttpError) -> Exception {
    match err {
        utopia_http::HttpError::MissingParam(key) => Exception::with_message(
            Exception::GENERAL_ARGUMENT_INVALID,
            format!("Param \"{key}\" is not optional."),
        ),
        utopia_http::HttpError::InvalidParam { key, description } => Exception::with_message(
            Exception::GENERAL_ARGUMENT_INVALID,
            format!("Invalid `{key}` param: {description}"),
        ),
        other => Exception::with_message(Exception::GENERAL_SERVER_ERROR, other.to_string()),
    }
}

/// `PHP $dbForProject->getDocument('users', $userId)` + the
/// `if ($user->isEmpty()) throw USER_NOT_FOUND` guard every Users endpoint
/// repeats.
pub fn require_document(
    db: &mut crate::state::ProjectDb,
    collection: &str,
    id: &str,
    not_found: &str,
) -> Result<utopia_database::Document, Exception> {
    let doc = db
        .get_document(collection, id, &[], false)
        .map_err(db_error)?;
    if doc.is_empty() {
        return Err(Exception::new(not_found));
    }
    Ok(doc)
}

/// PHP `$dbForProject->updateDocument('users', $id, new Document($fields))`
/// followed by the [`user_with_targets`] enrichment -- the shape most
/// property-update endpoints share once their own validation/business logic
/// has produced the sparse `fields` update.
pub fn update_user_fields(
    db: &mut crate::state::ProjectDb,
    user_id: &str,
    fields: Value,
) -> Result<Value, Exception> {
    require_document(db, "users", user_id, Exception::USER_NOT_FOUND)?;
    let document = document_from_json(fields);
    let updated = db
        .update_document("users", user_id, document)
        .map_err(db_error)?;
    Ok(user_with_targets(db, &updated))
}

/// PHP `$user->find('identifier', $identifier, 'targets')`: the Memory
/// adapter has no relationship attributes, so this queries `targets`
/// directly by `userId` + `identifier` rather than scanning an
/// already-populated in-memory array.
pub fn find_target_by_identifier(
    db: &mut crate::state::ProjectDb,
    user_id: &str,
    identifier: &str,
) -> Result<Option<utopia_database::Document>, Exception> {
    if identifier.is_empty() {
        return Ok(None);
    }
    let mut matches = db
        .find(
            "targets",
            &[
                Query::equal("userId", vec![AttrValue::from(user_id)]),
                Query::equal("identifier", vec![AttrValue::from(identifier)]),
                Query::limit(1),
            ],
            "read",
        )
        .map_err(db_error)?;
    Ok(if matches.is_empty() {
        None
    } else {
        Some(matches.remove(0))
    })
}

/// PHP `$dbForProject->findOne($collection, [Query::equal($attribute, [$value])])`.
pub fn find_one(
    db: &mut crate::state::ProjectDb,
    collection: &str,
    attribute: &str,
    value: impl Into<AttrValue>,
) -> Result<Option<utopia_database::Document>, Exception> {
    let mut matches = db
        .find(
            collection,
            &[Query::equal(attribute, vec![value.into()]), Query::limit(1)],
            "read",
        )
        .map_err(db_error)?;
    Ok(if matches.is_empty() {
        None
    } else {
        Some(matches.remove(0))
    })
}

/// PHP `Utopia\Database\DateTime::now()`, formatted like
/// `Appwrite\Auth\Key`'s `formatTz` (fixed-width ISO 8601 UTC).
#[must_use]
pub fn now_iso() -> String {
    Utc::now().format("%Y-%m-%dT%H:%M:%S%.3f+00:00").to_string()
}

/// Map a [`DatabaseError`] to the closest [`Exception`] catalog entry.
/// Route handlers should check `is_empty()` themselves before calling
/// mutating operations, so a `NotFound` surfacing here is treated as a
/// server error rather than re-guessed as `USER_NOT_FOUND`.
#[must_use]
pub fn db_error(err: DatabaseError) -> Exception {
    match err {
        DatabaseError::Duplicate(_) | DatabaseError::Unique(_) => {
            Exception::new(Exception::USER_ALREADY_EXISTS)
        }
        other => Exception::with_message(Exception::GENERAL_SERVER_ERROR, other.to_string()),
    }
}

/// Map a `utopia-auth` hashing failure to a generic server error, matching
/// PHP's unwrapped `Throwable` -> 500 behavior for hashing failures (which
/// are not part of the documented error catalog).
pub fn hash_error(err: impl std::fmt::Display) -> Exception {
    Exception::with_message(Exception::GENERAL_SERVER_ERROR, err.to_string())
}

/// `Appwrite\Extend\Exception::USER_NOT_FOUND` shorthand.
#[must_use]
pub fn user_not_found() -> Exception {
    Exception::new(Exception::USER_NOT_FOUND)
}

/// PHP `Utopia\Database\Helpers\Permission::{read,update,delete}` triple
/// scoping a resource to its owning user, plus public read (`any()`).
#[must_use]
pub fn owner_permissions(user_id: &str) -> Vec<String> {
    vec![
        Permission::read(&Role::any()),
        Permission::update(&Role::user(user_id.to_string(), String::new())),
        Permission::delete(&Role::user(user_id.to_string(), String::new())),
    ]
}

/// Parameters for [`create_user`], mirroring `Base::createUser()`'s
/// positional arguments.
#[derive(Debug)]
pub struct CreateUserParams {
    pub user_id: String,
    pub email: Option<String>,
    pub password: Option<String>,
    pub phone: Option<String>,
    pub name: Option<String>,
}

/// Rust port of `Appwrite\Platform\Modules\Users\Base::createUser()`.
///
/// `hasher` is the caller's requested algorithm (`Plaintext` for the plain
/// `POST /v1/users` endpoint, a concrete hash for the `/v1/users/{argon2,
/// bcrypt, md5, sha, phpass, scrypt, scrypt-modified}` endpoints where
/// `password` is assumed already hashed by the caller).
pub fn create_user(
    db: &mut crate::state::ProjectDb,
    hooks: &Hooks,
    hasher: Arc<dyn Hash>,
    params: CreateUserParams,
) -> Result<Value, Exception> {
    let CreateUserParams {
        user_id,
        email,
        password,
        phone,
        name,
    } = params;
    let name = name.unwrap_or_default();
    let email = email.filter(|e| !e.is_empty()).map(|e| e.to_lowercase());
    let phone = phone.filter(|p| !p.is_empty());

    if let Some(email) = &email {
        let matches = db
            .find(
                "identities",
                &[Query::equal(
                    "providerEmail",
                    vec![AttrValue::from(email.as_str())],
                )],
                "read",
            )
            .map_err(db_error)?;
        if !matches.is_empty() {
            return Err(Exception::new(Exception::USER_EMAIL_ALREADY_EXISTS));
        }
    }

    let resolved_id = appwrite_database::resolve_id(&user_id);

    let is_plaintext = hasher.name() == "plaintext";
    let mut hashed_password: Option<String> = None;
    let mut hash_name = hasher.name().to_string();
    let mut hash_options: HashMap<String, Value> = hasher.options().clone();

    if let Some(pw) = password.as_deref().filter(|p| !p.is_empty()) {
        if is_plaintext {
            let default_hasher =
                Password::create_hash(Password::ARGON2, HashMap::new()).map_err(hash_error)?;
            hashed_password = Some(default_hasher.hash(pw).map_err(hash_error)?);
            hash_name = default_hasher.name().to_string();
            hash_options.clone_from(default_hasher.options());
            let _ = hooks.trigger(appwrite_hooks::PASSWORD_VALIDATOR, &[json!(pw)]);
        } else {
            hashed_password = Some(pw.to_string());
        }
    } else {
        let default_hasher =
            Password::create_hash(Password::ARGON2, HashMap::new()).map_err(hash_error)?;
        hash_name = default_hasher.name().to_string();
        hash_options.clone_from(default_hasher.options());
    }

    let now = now_iso();
    // PHP `'search' => implode(' ', [$userId, $email, $phone, $name])`, the
    // fulltext-index field `Query::search('search', ...)` filters against
    // (see `http::crud::list`). Not refreshed on email/phone/name updates
    // (documented simplification: those handlers don't rewrite `search`).
    let search = [
        resolved_id.as_str(),
        email.as_deref().unwrap_or_default(),
        phone.as_deref().unwrap_or_default(),
        name.as_str(),
    ]
    .join(" ");
    let doc_json = json!({
        "$id": resolved_id,
        "$permissions": owner_permissions(&resolved_id),
        "email": email,
        "emailVerification": false,
        "phone": phone,
        "phoneVerification": false,
        "status": true,
        "labels": Vec::<String>::new(),
        "password": hashed_password,
        "passwordHistory": hashed_password.clone().map(|p| vec![p]).unwrap_or_default(),
        "passwordUpdate": hashed_password.as_ref().map(|_| now.clone()),
        "hash": hash_name,
        "hashOptions": hash_options,
        "registration": now.clone(),
        "mfa": false,
        "name": name,
        "prefs": {},
        "accessedAt": now,
        "search": search,
    });

    let document = document_from_json(doc_json);
    let created = db.create_document("users", document).map_err(db_error)?;
    let mut user_json = document_to_json(&created);

    let mut targets: Vec<Value> = Vec::new();
    if let Some(email) = &email {
        targets.push(create_target(db, &created, "email", email)?);
    }
    if let Some(phone) = &phone {
        targets.push(create_target(db, &created, "sms", phone)?);
    }
    user_json["targets"] = Value::Array(targets);

    Ok(user_json)
}

/// PHP `Users\Get`/`Users\XList`'s `$user->setAttribute('targets', ...)`
/// enrichment: attach every `targets` document scoped to `user`'s id (the
/// Memory adapter has no relationship attributes, so this is a direct query
/// rather than an already-populated `$user->getAttribute('targets')`).
#[must_use]
pub fn user_with_targets(
    db: &mut crate::state::ProjectDb,
    user: &utopia_database::Document,
) -> Value {
    let mut user_json = document_to_json(user);
    let user_id = user.get_id();
    let targets = db
        .find(
            "targets",
            &[
                Query::equal("userId", vec![AttrValue::from(user_id.as_str())]),
                Query::limit(100),
            ],
            "read",
        )
        .unwrap_or_default();
    user_json["targets"] = Value::Array(targets.iter().map(document_to_json).collect());
    user_json
}

/// PHP `$dbForProject->createDocument('targets', new Document([...]))` from
/// `Base::createUser()`. Not de-duplicated against an existing target (see
/// module docs).
pub fn create_target(
    db: &mut crate::state::ProjectDb,
    user: &utopia_database::Document,
    provider_type: &str,
    identifier: &str,
) -> Result<Value, Exception> {
    let user_id = user.get_id();
    let target_json = json!({
        "$id": appwrite_database::resolve_id(appwrite_database::UNIQUE_SENTINEL),
        "$permissions": owner_permissions(&user_id),
        "userId": user_id,
        "providerType": provider_type,
        "identifier": identifier,
        "expired": false,
    });
    let document = document_from_json(target_json);
    let created = db.create_document("targets", document).map_err(db_error)?;
    Ok(document_to_json(&created))
}
