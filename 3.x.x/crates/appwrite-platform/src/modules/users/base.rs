//! Users module base. Rust port of
//! `Appwrite\Platform\Modules\Users\Base` (`Base.php`).
//!
//! PHP: hash-create endpoints `extend Base` and call `$this->createUser(...)`.
//! Rust has no class inheritance, so action files call free functions on this
//! module instead (`base::create_user`, `base::create_hashed_user_action`, …).
//! That is the intended parity - shared protected-style API lives here, not in
//! ad-hoc `helpers.rs` / `shared.rs` files beside actions.
//!
//! Also holds small Users-scoped constants and MFA/session helpers that PHP
//! pulls from `app/init/constants.php` or Auth MFA types (not separate Base
//! subclasses).
//!
//! Simplifications versus PHP (documented, not silently dropped):
//! - `PersonalData` and the cloud `$plan` gate are not implemented, and the
//!   disposable/canonical/free/corporate email *policies* are not enforced
//!   (the metadata columns they read are populated). Self-hosted Appwrite
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
use utopia_auth::{Hash, Password, Proof};
use utopia_database::helpers::{Permission, Role};
use utopia_database::{AttrValue, DatabaseError, Query};
use utopia_http::ActionContext;
use utopia_platform::Action;
use utopia_validators::Text;

use crate::modules::users::validators::Email;
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
pub fn inject(action: Action, names: &[&str]) -> Action {
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

/// PHP `Base::createUser()`'s `users` document permissions: public read plus
/// update/delete for the owning user.
#[must_use]
pub fn owner_permissions(user_id: &str) -> Vec<String> {
    vec![
        Permission::read(&Role::any()),
        Permission::update(&Role::user(user_id.to_string(), String::new())),
        Permission::delete(&Role::user(user_id.to_string(), String::new())),
    ]
}

/// PHP's permission triple for user-owned sub-resources (`sessions`,
/// `targets`): read/update/delete scoped to the owning user only, with no
/// `any()` read.
#[must_use]
pub fn user_permissions(user_id: &str) -> Vec<String> {
    vec![
        Permission::read(&Role::user(user_id.to_string(), String::new())),
        Permission::update(&Role::user(user_id.to_string(), String::new())),
        Permission::delete(&Role::user(user_id.to_string(), String::new())),
    ]
}

/// PHP `$user->getSequence()`, the `userInternalId` every user sub-resource
/// (`sessions`, `tokens`, `targets`, `identities`, `memberships`) stores so
/// PHP's `subQuery*` relationship filters can find it again. Always a string:
/// the column is `VAR_STRING` in every one of those collections, and handing
/// the driver a number instead fails to serialize.
#[must_use]
pub fn sequence_of(document: &utopia_database::Document) -> Value {
    document
        .get_sequence()
        .map_or(Value::Null, |sequence| json!(sequence))
}

/// [`sequence_of`] as a plain string, for building queries against the
/// `userInternalId` column.
#[must_use]
pub fn sequence_str(document: &utopia_database::Document) -> String {
    document.get_sequence().unwrap_or_default()
}

/// A `Boolean::new().loose(true)` param read back as a `bool`. `loose`
/// accepts PHP's `http_build_query` encoding (`total=0` / `total=1`), so the
/// stored param may still be the string form.
#[must_use]
pub fn param_bool(ctx: &ActionContext, key: &str, default: bool) -> bool {
    match ctx.param_value(key) {
        Some(Value::Bool(value)) => *value,
        Some(Value::String(value)) => !matches!(value.as_str(), "" | "0" | "false"),
        Some(Value::Number(value)) => value.as_f64().is_some_and(|value| value != 0.0),
        _ => default,
    }
}

/// Rust port of the `userSearch` filter (`app/init/database/filters.php`):
/// `implode(' ', array_filter([$id, $email, $name, $phone, ...'label:'.$label]))`.
/// The field order and the `label:` prefix both matter -- this is the haystack
/// `Query::search('search', ...)` matches against, and `array_filter` drops
/// the empty entries so a user with no phone has no stray double space.
///
/// PHP registers this as a `Database` filter, which recomputes it on every
/// write because `updateDocument` encodes the merged document. The Rust filter
/// registry passes only the attribute value, not the owning document, so the
/// handlers that change one of these fields call [`refreshed_search`] instead.
#[must_use]
pub fn user_search(
    user_id: &str,
    email: Option<&str>,
    name: &str,
    phone: Option<&str>,
    labels: &[String],
) -> String {
    let mut parts = vec![
        user_id.to_string(),
        email.unwrap_or_default().to_string(),
        name.to_string(),
        phone.unwrap_or_default().to_string(),
    ];
    parts.extend(labels.iter().map(|label| format!("label:{label}")));
    parts.retain(|part| !part.is_empty());
    parts.join(" ")
}

/// Rebuild [`user_search`] from an already-loaded `users` document, applying
/// `overrides` (the sparse update about to be written) on top of its current
/// attributes first -- the same merged view PHP's filter sees.
#[must_use]
pub fn refreshed_search(user: &utopia_database::Document, overrides: &Value) -> String {
    let current = document_to_json(user);
    let pick = |key: &str| -> Option<String> {
        overrides
            .get(key)
            .or_else(|| current.get(key))
            .and_then(Value::as_str)
            .map(str::to_string)
    };
    let labels = overrides
        .get("labels")
        .or_else(|| current.get("labels"))
        .and_then(Value::as_array)
        .map(|values| {
            values
                .iter()
                .filter_map(|value| value.as_str().map(str::to_string))
                .collect::<Vec<_>>()
        })
        .unwrap_or_default();
    user_search(
        &user.get_id(),
        pick("email").as_deref(),
        pick("name").unwrap_or_default().as_str(),
        pick("phone").as_deref(),
        &labels,
    )
}

/// [`update_user_fields`] plus the `search` rebuild every handler that
/// changes `name`/`email`/`phone`/`labels` needs.
pub fn update_user_fields_and_search(
    db: &mut crate::state::ProjectDb,
    user_id: &str,
    mut fields: Value,
) -> Result<Value, Exception> {
    let user = require_document(db, "users", user_id, Exception::USER_NOT_FOUND)?;
    let search = refreshed_search(&user, &fields);
    if let Some(fields) = fields.as_object_mut() {
        fields.insert("search".to_string(), json!(search));
    }
    update_user_fields(db, user_id, fields)
}

/// Rust port of `Base::createUser()`'s `$emailMetadata` block: the five
/// `email*` columns derived from `Utopia\Emails\Email`, all `null` when the
/// address is missing or unparseable (PHP's `catch (\Throwable)`).
#[must_use]
pub fn email_metadata(email: Option<&str>) -> Value {
    let parsed = email
        .filter(|value| !value.is_empty())
        .and_then(|value| utopia_emails::Email::new(value).ok());
    let Some(parsed) = parsed else {
        return json!({
            "emailCanonical": Value::Null,
            "emailIsCanonical": Value::Null,
            "emailIsCorporate": Value::Null,
            "emailIsDisposable": Value::Null,
            "emailIsFree": Value::Null,
        });
    };
    let Ok(canonical) = parsed.get_canonical() else {
        return json!({
            "emailCanonical": Value::Null,
            "emailIsCanonical": Value::Null,
            "emailIsCorporate": Value::Null,
            "emailIsDisposable": Value::Null,
            "emailIsFree": Value::Null,
        });
    };
    json!({
        "emailCanonical": canonical,
        "emailIsCanonical": parsed.get() == canonical,
        "emailIsCorporate": parsed.is_corporate(),
        "emailIsDisposable": parsed.is_disposable(),
        "emailIsFree": parsed.is_free(),
    })
}

/// Merge `extra`'s keys into `target` (both JSON objects).
pub fn merge_into(target: &mut Value, extra: &Value) {
    let (Some(target), Some(extra)) = (target.as_object_mut(), extra.as_object()) else {
        return;
    };
    for (key, value) in extra {
        target.insert(key.clone(), value.clone());
    }
}

/// Parameters for [`create_user`], mirroring `Base::createUser()`'s
/// positional arguments. `password` is not here - resolve it via
/// [`resolve_password`] before locking `db` and pass the result instead.
#[derive(Debug)]
pub struct CreateUserParams {
    pub user_id: String,
    pub email: Option<String>,
    pub phone: Option<String>,
    pub name: Option<String>,
}

/// Output of [`resolve_password`]: the pieces of `create_user`'s document
/// that come from hashing, computed with no `dbForProject` connection held.
#[derive(Debug)]
pub struct ResolvedPassword {
    pub hashed: Option<String>,
    pub hash_name: String,
    pub hash_options: HashMap<String, Value>,
}

/// The hashing half of PHP `Base::createUser()`, split out so callers can run
/// it **before** checking a `dbForProject` connection out of the pool
/// (`create_user` no longer touches a hasher at all). For the plaintext
/// `POST /v1/users` endpoint this is the Argon2 computation itself -
/// milliseconds of pure CPU that, run under the lock, used to hold a pooled
/// connection idle for no reason. Every `/v1/users/{argon2,bcrypt,md5,sha,
/// phpass,scrypt,scrypt-modified}` endpoint's `password` is already a hash
/// from the caller, so this is a cheap passthrough for those.
pub fn resolve_password(
    hasher: &dyn Hash,
    password: Option<&str>,
    hooks: &Hooks,
) -> Result<ResolvedPassword, Exception> {
    let is_plaintext = hasher.name() == "plaintext";
    let mut hashed: Option<String> = None;
    let mut hash_name = hasher.name().to_string();
    let mut hash_options: HashMap<String, Value> = hasher.options().clone();

    if let Some(pw) = password.filter(|p| !p.is_empty()) {
        if is_plaintext {
            let default_hasher =
                Password::create_hash(Password::ARGON2, HashMap::new()).map_err(hash_error)?;
            hashed = Some(default_hasher.hash(pw).map_err(hash_error)?);
            hash_name = default_hasher.name().to_string();
            hash_options.clone_from(default_hasher.options());
            let _ = hooks.trigger(appwrite_hooks::PASSWORD_VALIDATOR, &[json!(pw)]);
        } else {
            hashed = Some(pw.to_string());
        }
    } else {
        let default_hasher =
            Password::create_hash(Password::ARGON2, HashMap::new()).map_err(hash_error)?;
        hash_name = default_hasher.name().to_string();
        hash_options.clone_from(default_hasher.options());
    }

    Ok(ResolvedPassword {
        hashed,
        hash_name,
        hash_options,
    })
}

/// Rust port of `Appwrite\Platform\Modules\Users\Base::createUser()`.
///
/// `resolved_password` is computed by [`resolve_password`] *before* the
/// caller checks out `db` (see that function's docs for why): this function
/// never hashes anything, so it never holds a pooled connection through a
/// CPU-bound Argon2 call.
pub fn create_user(
    db: &mut crate::state::ProjectDb,
    resolved_password: ResolvedPassword,
    params: CreateUserParams,
) -> Result<Value, Exception> {
    let CreateUserParams {
        user_id,
        email,
        phone,
        name,
    } = params;
    let name = name.unwrap_or_default();
    let email = email.filter(|e| !e.is_empty()).map(|e| e.to_lowercase());
    let phone = phone.filter(|p| !p.is_empty());

    if let Some(email) = &email {
        // PHP uses findOne; do not scan the whole identities match set.
        let match_doc = db
            .find_one(
                "identities",
                &[Query::equal(
                    "providerEmail",
                    vec![AttrValue::from(email.as_str())],
                )],
            )
            .map_err(db_error)?;
        if !match_doc.is_empty() {
            return Err(Exception::new(Exception::USER_EMAIL_ALREADY_EXISTS));
        }
    }

    let resolved_id = appwrite_database::resolve_id(&user_id);
    let ResolvedPassword {
        hashed: hashed_password,
        hash_name,
        hash_options,
    } = resolved_password;

    let now = now_iso();
    let search = user_search(
        resolved_id.as_str(),
        email.as_deref(),
        name.as_str(),
        phone.as_deref(),
        &[],
    );
    let mut doc_json = json!({
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
        "reset": false,
        "mfa": false,
        "name": name,
        "prefs": {},
        "accessedAt": now,
        "search": search,
    });
    merge_into(&mut doc_json, &email_metadata(email.as_deref()));

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
    purge_user(db, &created.get_id());

    Ok(user_json)
}

/// PHP `$dbForProject->purgeCachedDocument('users', $user->getId())`.
///
/// Writing a session, target or token leaves the user document's cached
/// relationships stale, and that cache is shared with the PHP server, so the
/// handlers that write one purge the other. Failures are ignored for the same
/// reason PHP's is not checked: a cold cache is correct, just slower.
pub fn purge_user(db: &mut crate::state::ProjectDb, user_id: &str) {
    let _ = db.purge_cached_document("users", user_id);
}

/// PHP's `foreach ($sessions as $session) { $dbForProject->deleteDocument(
/// 'sessions', $session->getId()); }` loop, shared by `Sessions\Bulk\Delete`
/// and `Password\Update`'s `invalidateSessions` branch.
pub fn delete_user_sessions(
    db: &mut crate::state::ProjectDb,
    user_id: &str,
) -> Result<(), Exception> {
    let sessions = db
        .find(
            "sessions",
            &[
                Query::equal("userId", vec![AttrValue::from(user_id)]),
                Query::limit(1000),
            ],
            "read",
        )
        .map_err(db_error)?;
    for session in sessions {
        db.delete_document("sessions", &session.get_id())
            .map_err(db_error)?;
    }
    purge_user(db, user_id);
    Ok(())
}

/// PHP `Users\Get`'s `$user->setAttribute('targets', ...)` enrichment for a
/// single user. Prefer [`users_with_targets`] for list handlers (one batched
/// query instead of N+1).
#[must_use]
pub fn user_with_targets(
    db: &mut crate::state::ProjectDb,
    user: &utopia_database::Document,
) -> Value {
    users_with_targets(db, std::slice::from_ref(user))
        .into_iter()
        .next()
        .unwrap_or_else(|| document_to_json(user))
}

/// PHP `Users\XList` target enrichment: one `find('targets', equal(userInternalId,
/// sequences))` grouped in memory, matching
/// `src/Appwrite/Platform/Modules/Users/Http/Users/XList.php`.
#[must_use]
pub fn users_with_targets(
    db: &mut crate::state::ProjectDb,
    users: &[utopia_database::Document],
) -> Vec<Value> {
    if users.is_empty() {
        return Vec::new();
    }
    let sequences: Vec<AttrValue> = users
        .iter()
        .map(|user| AttrValue::from(sequence_str(user)))
        .filter(|value| !matches!(value, AttrValue::String(s) if s.is_empty()))
        .collect();
    let mut targets_by_user: HashMap<String, Vec<Value>> = HashMap::new();
    if !sequences.is_empty() {
        let targets = db
            .find(
                "targets",
                &[
                    Query::equal("userInternalId", sequences),
                    Query::limit(i64::MAX),
                ],
                "read",
            )
            .unwrap_or_default();
        for target in targets {
            let key = match target.get_attribute("userInternalId") {
                AttrValue::String(s) => s.clone(),
                AttrValue::Number(n) => n.to_string(),
                _ => sequence_str(&target), // fall back; unlikely for this attribute
            };
            targets_by_user
                .entry(key)
                .or_default()
                .push(document_to_json(&target));
        }
    }
    users
        .iter()
        .map(|user| {
            let mut user_json = document_to_json(user);
            let seq = sequence_str(user);
            user_json["targets"] = Value::Array(targets_by_user.remove(&seq).unwrap_or_default());
            user_json
        })
        .collect()
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
        "$permissions": user_permissions(&user_id),
        "userId": user_id,
        "userInternalId": sequence_of(user),
        "providerType": provider_type,
        "identifier": identifier,
        "expired": false,
    });
    let document = document_from_json(target_json);
    let created = db.create_document("targets", document).map_err(db_error)?;
    Ok(document_to_json(&created))
}

// ---------------------------------------------------------------------------
// Inherited-style API (PHP `class Create extends Base`)
// ---------------------------------------------------------------------------

/// Common `userId` route param on most user property endpoints.
#[must_use]
pub fn user_id_param(action: Action) -> Action {
    action.param("userId", json!(""), Text::new(36), "User ID.", false)
}

fn hashed_create_common_params(action: Action) -> Action {
    action
        .param(
            "userId",
            json!(""),
            appwrite_database::CustomId::default(),
            "User ID.",
            false,
        )
        .param("email", json!(""), Email::new(false), "User email.", false)
}

/// Skeleton action for `Http/Users/{Argon2,Bcrypt,...}/Create.php` - the
/// shared constructor wiring those PHP classes inherit from [`Base`].
#[must_use]
pub fn create_hashed_user_action(path: &'static str, desc: &'static str) -> Action {
    inject(
        hashed_create_common_params(
            Action::new()
                .set_http_method(utopia_platform::HttpMethod::Post)
                .set_http_path(path)
                .desc(desc)
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.create")
                .label("audits.resource", "user/{response.$id}"),
        ),
        &["response", "project", "dbForProject", "hooks"],
    )
}

/// Body of every pre-hashed `Create` action: resolve hasher, then
/// [`create_user`] (PHP `$this->createUser($hash, ...)`). The hasher never
/// does real work here (`password` is already a hash for every one of these
/// endpoints), but [`resolve_password`] still runs before [`get_db`] so the
/// db checkout happens as late as possible either way.
pub fn create_hashed_user(
    ctx: &ActionContext,
    hasher: Result<Arc<dyn Hash>, utopia_auth::AuthError>,
) -> Result<Value, Exception> {
    let hasher = hasher.map_err(hash_error)?;
    let hooks = get_hooks(ctx)?;
    let password = param_str(ctx, "password")?;
    let resolved_password = resolve_password(hasher.as_ref(), Some(password.as_str()), &hooks)?;

    let db_handle = get_db(ctx)?;
    let mut db = db_handle.lock();
    create_user(
        &mut db,
        resolved_password,
        CreateUserParams {
            user_id: param_str(ctx, "userId")?,
            email: Some(param_str(ctx, "email")?),
            phone: None,
            name: ctx
                .param_value("name")
                .and_then(Value::as_str)
                .map(str::to_string),
        },
    )
}

// ---------------------------------------------------------------------------
// Session / token constants (PHP `app/init/constants.php`)
// ---------------------------------------------------------------------------

/// PHP `SESSION_PROVIDER_SERVER`.
pub const SESSION_PROVIDER_SERVER: &str = "server";
/// PHP `TOKEN_EXPIRATION_LOGIN_LONG`: 1 year, in seconds.
pub const TOKEN_EXPIRATION_LOGIN_LONG: i64 = 31_536_000;
/// PHP `TOKEN_EXPIRATION_GENERIC`: 15 minutes, in seconds.
pub const TOKEN_EXPIRATION_GENERIC: i64 = 900;
/// PHP `TOKEN_TYPE_GENERIC`.
pub const TOKEN_TYPE_GENERIC: i64 = 8;

#[must_use]
pub fn expire_at(seconds: i64) -> String {
    let expire = Utc::now() + chrono::Duration::seconds(seconds);
    expire.format("%Y-%m-%dT%H:%M:%S%.3f+00:00").to_string()
}

pub fn token_proof() -> Result<utopia_auth::Token, Exception> {
    let mut token = utopia_auth::Token::new(32).map_err(hash_error)?;
    token.set_hasher(Arc::new(utopia_auth::Sha::new()));
    Ok(token)
}

// ---------------------------------------------------------------------------
// MFA helpers (PHP Auth MFA types / document attributes)
// ---------------------------------------------------------------------------

/// PHP `TOTP::getAuthenticatorFromUser()`.
pub fn totp_authenticator(
    db: &mut crate::state::ProjectDb,
    user_id: &str,
) -> Result<Option<utopia_database::Document>, Exception> {
    let mut matches = db
        .find(
            "authenticators",
            &[
                Query::equal("userId", vec![AttrValue::from(user_id)]),
                Query::equal("type", vec![AttrValue::from(appwrite_auth::mfa::TOTP)]),
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

/// PHP `Appwrite\Auth\MFA\Type::generateBackupCodes(10, 6)`.
pub fn generate_backup_codes() -> Result<Vec<String>, Exception> {
    let proof = utopia_auth::Token::new(10).map_err(hash_error)?;
    (0..6)
        .map(|_| proof.generate().map_err(hash_error))
        .collect()
}

/// PHP `$user->getAttribute('mfaRecoveryCodes', [])`.
#[must_use]
pub fn recovery_codes_of(user: &utopia_database::Document) -> Vec<Value> {
    document_to_json(user)
        .get("mfaRecoveryCodes")
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default()
}
