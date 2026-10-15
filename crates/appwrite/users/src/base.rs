//! Shared domain steps of the Users module (`Users\Base`).

use async_trait::async_trait;
use bytes::Bytes;
use utopia_auth::Hash;
use utopia_auth::hashes::{Argon2, Sha};
use utopia_database::Permission;
use utopia_database::Role;
use utopia_database::database::Callback;
use utopia_database::validator::query::cursor::Cursor as CursorValidator;
use utopia_database::value::{from_plain, list, to_text};
use utopia_database::{Database, Document, Kind, Query, Value};
use utopia_emails::Email;
use utopia_query::Method;

use appwrite_core::database::documents::{self, Attributes, Target, User, text};
use appwrite_core::database::filters;
use appwrite_core::datetime;
use appwrite_core::platform::Context;
use appwrite_core::response::UserModel;
use appwrite_core::{Error, ErrorType, Result};

/// The proof Appwrite uses for plaintext passwords (`proofForPassword`).
pub fn proof() -> Box<dyn Hash> {
    let mut argon2 = Argon2::new();
    argon2.set_memory_cost(7168).set_time_cost(5).set_threads(1);
    Box::new(argon2)
}

/// Hashes a plaintext password on the blocking pool.
pub async fn hash_password(password: String) -> Result<String> {
    let hashed = utopia_async::spawn_blocking(move || proof().hash(password.as_bytes()))
        .await
        .map_err(|e| Error::internal(e.to_string()))?
        .map_err(|e| Error::internal(e.to_string()))?;
    String::from_utf8(hashed).map_err(|e| Error::internal(e.to_string()))
}

/// `proofForToken->hash($secret)`: SHA-256 hex of a session or token secret.
pub fn token_hash(secret: &str) -> Result<String> {
    let hashed = Sha::new().hash(secret.as_bytes()).map_err(|e| Error::internal(e.to_string()))?;
    String::from_utf8(hashed).map_err(|e| Error::internal(e.to_string()))
}

/// The session store a client keeps (`Store` with `id` and `secret`), encoded.
pub fn session_store(id: &str, secret: &str) -> Result<String> {
    use php_std::Value;
    let mut store = utopia_auth::Store::new();
    store.set_property(b"id", Value::from(id)).set_property(b"secret", Value::from(secret));
    store.encode().map_err(|e| Error::internal(e.to_string()))
}

/// `$hash->getOptions()`, the `hashOptions` attribute.
pub fn hash_options(hash: &dyn Hash) -> Value {
    from_plain(php_std::Value::Array(hash.options().as_array().clone()))
}

/// `ID::unique()` for `unique()`, else the id given.
pub fn id_or_unique(id: String) -> String {
    if id == "unique()" { appwrite_core::database::unique_id() } else { id }
}

/// The `subQueryTargets` filter: the user model's `targets`.
pub const TARGETS: &str = "subQueryTargets";
/// The `subQuerySessions` filter.
pub const SESSIONS: &str = "subQuerySessions";
/// The `subQueryAuthenticators` filter.
pub const AUTHENTICATORS: &str = "subQueryAuthenticators";

/// The user sub-query filters (`APP_USERS_SUBQUERIES`) other than `keep`.
fn skipped(keep: &[&str]) -> Vec<String> {
    filters::USERS_SUBQUERIES.iter().filter(|f| !keep.contains(f)).map(|f| (*f).to_owned()).collect()
}

/// `getDocument('users', $userId)` running only the sub-queries in `keep`,
/// or `user_not_found`. Each sub-query is a find; PHP's user list skips
/// those it does not answer with the same `skipFilters`. An action reads
/// what it uses: one that changes the user purges it anyway, so the whole
/// document would be read and cached for nothing. (The document read is
/// cached apart from the whole one: its filters are part of the cache key.)
pub async fn user_or_404(ctx: &mut Context, id: &str, keep: &[&str]) -> Result<User> {
    let db = ctx.db()?;
    let skipped = skipped(keep);
    let user = db.skip_filters(Some(skipped.as_slice()), documents::load_user(&db, id)).await?;
    user.ok_or_else(|| Error::new(ErrorType::UserNotFound))
}

/// The first cursor query's document id, checked by the `Cursor` validator.
pub fn cursor_id(queries: &[Query]) -> Result<Option<String>> {
    let Some(cursor) = queries.iter().find(|q| matches!(q.method(), Method::CursorAfter | Method::CursorBefore)) else {
        return Ok(None);
    };
    let validator = CursorValidator::default();
    if !validator.check(cursor) {
        return Err(Error::with_message(ErrorType::GeneralQueryInvalid, validator.description()));
    }
    Ok(Some(cursor.value().map(|v| to_text(&utopia_database::value::from_query(v))).unwrap_or_default()))
}

/// `$cursor->setValue($cursorDocument)` on the first cursor query.
pub fn set_cursor(queries: &mut [Query], document: Document) {
    if let Some(cursor) = queries.iter_mut().find(|q| matches!(q.method(), Method::CursorAfter | Method::CursorBefore))
    {
        cursor.set_value(utopia_database::value::to_query(&Value::from(document)));
    }
}

/// Resolves the cursor of a list to the document it names in
/// `collection`, or fails with `general_cursor_not_found` naming the
/// cursor as a `label`.
pub async fn resolve_cursor(db: &Database, collection: &str, queries: &mut [Query], label: &str) -> Result<()> {
    let Some(id) = cursor_id(queries)? else {
        return Ok(());
    };
    let document = db.get_document(collection, &id, Vec::new(), false).await?;
    if document.is_empty() {
        return Err(Error::with_message(
            ErrorType::GeneralCursorNotFound,
            format!("{label} '{id}' for the 'cursor' value not found."),
        ));
    }
    set_cursor(queries, document);
    Ok(())
}

/// JSON of the `user` model as the latest format renders it (an event
/// payload, which PHP takes before the response's format filters).
pub fn user_payload(ctx: &Context, user: &User) -> Bytes {
    Bytes::from(appwrite_core::response::current(&UserModel { user, render: ctx.render() }))
}

/// `updateDocument('users', $id, $updates)`: the user as stored, decoded
/// with the sub-queries in `keep` (the response's `targets`, and what the
/// action reads from it). The update runs none ([`user_or_404`]): it would
/// run them on its locked read of the user too. The kept ones run once on
/// its result, as its decode would.
pub async fn update_user(ctx: &mut Context, id: &str, updates: Document, keep: &[&str]) -> Result<User> {
    let db = ctx.db()?;
    let skipped = skipped(&[]);
    let mut updated = db.skip_filters(Some(skipped.as_slice()), db.update_document("users", id, updates)).await?;
    if updated.is_empty() {
        return Err(Error::new(ErrorType::UserNotFound));
    }
    for filter in keep {
        let value = filters::sub_query(&db, filter, &updated).await?;
        updated.put(sub_query_attribute(filter), value);
    }
    Ok(User::from(&updated))
}

/// The attribute a user sub-query filter fills (`subQueryTargets`:
/// `targets`).
fn sub_query_attribute(filter: &str) -> String {
    let name = filter.strip_prefix("subQuery").unwrap_or(filter);
    let mut chars = name.chars();
    chars.next().map(|first| first.to_ascii_lowercase().to_string() + chars.as_str()).unwrap_or_default()
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
    /// Computes the metadata the way `Users\Base::createUser` does: all
    /// `None` when the address cannot be parsed or has no canonical form.
    pub fn of(email: Option<&str>) -> Self {
        let Ok(parsed) = Email::new(email.unwrap_or("")) else {
            return Self::default();
        };
        let Ok(canonical) = parsed.canonical() else {
            return Self::default();
        };
        Self {
            is_canonical: Some(parsed.get() == canonical),
            canonical: Some(canonical),
            is_corporate: Some(parsed.is_corporate()),
            is_disposable: Some(parsed.is_disposable()),
            is_free: Some(parsed.is_free()),
        }
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
        && !meta.is_canonical.unwrap_or(true)
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
        && !meta.is_corporate.unwrap_or(true)
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

/// The target document `createUser` and the email/phone updates create.
pub fn new_target(user: &User, provider_type: &str, identifier: &str) -> Document {
    let mut target = Document::default();
    target.put("$permissions", owner_permissions(&user.id));
    target.put("userId", Value::from(user.id.as_str()));
    target.put("userInternalId", documents::internal_id(user.sequence));
    target.put("providerType", Value::from(provider_type));
    target.put("identifier", Value::from(identifier));
    target
}

/// Creates a messaging target; on a duplicate identifier returns the
/// existing target (PHP appends it without raising).
pub async fn create_target(
    db: &Database,
    user: &User,
    provider_type: &str,
    identifier: &str,
) -> Result<Option<Target>> {
    match db.create_document("targets", new_target(user, provider_type, identifier)).await {
        Ok(target) => Ok(Some(Target::from(&target))),
        Err(e) if e.is_a(Kind::Duplicate) => target_by_identifier(db, identifier).await,
        Err(e) => Err(e.into()),
    }
}

/// `findOne('targets', [equal('identifier', [$identifier])])`.
pub async fn target_by_identifier(db: &Database, identifier: &str) -> Result<Option<Target>> {
    let target = db.find_one("targets", vec![Query::equal("identifier", [identifier])]).await?;
    Ok(if target.is_empty() { None } else { Some(Target::from(&target)) })
}

/// Whether an identity has `providerEmail = $email` (of another user than
/// `exclude_user_sequence`, when given).
pub async fn identity_email_taken(db: &Database, email: &str, exclude_user_sequence: Option<i64>) -> Result<bool> {
    let mut queries = vec![Query::equal("providerEmail", [email])];
    if let Some(sequence) = exclude_user_sequence {
        queries.push(Query::not_equal("userInternalId", sequence.to_string()));
    }
    Ok(!db.find_one("identities", queries).await?.is_empty())
}

/// Input of [`create_user`].
pub struct NewUser {
    pub hash: Box<dyn Hash>,
    pub user_id: String,
    pub email: Option<String>,
    pub password: Option<String>,
    pub phone: Option<String>,
    pub name: Option<String>,
    pub password_pwned: Option<bool>,
}

/// `Users\Base::createUser`.
pub async fn create_user(ctx: &mut Context, input: NewUser) -> Result<User> {
    let db = ctx.db()?;
    let name = input.name.unwrap_or_default();
    let history_limit = ctx.project.auth_int("passwordHistory", 0);
    let email = input.email.filter(|e| !e.is_empty()).map(|e| e.to_lowercase());

    if let Some(email) = &email
        && identity_email_taken(&db, email, None).await?
    {
        return Err(Error::new(ErrorType::UserEmailAlreadyExists));
    }

    let user_id = id_or_unique(input.user_id);

    if ctx.project.auth_bool("personalDataCheck", false)
        && !appwrite_core::validators::personal_data_ok(
            input.password.as_deref(),
            &user_id,
            email.as_deref(),
            Some(&name),
            input.phone.as_deref(),
            true,
        )
    {
        return Err(Error::new(ErrorType::UserPasswordPersonalData));
    }

    let meta = Metadata::of(email.as_deref());
    check_email_policy(ctx, &meta)?;

    let plaintext = input.hash.name() == "plaintext";
    let password = input.password.filter(|p| !p.is_empty());
    let (hashed, hash) = match &password {
        Some(p) if plaintext => {
            ctx.state.hooks.password_validator(ctx, p, None).await?;
            (Some(hash_password(p.clone()).await?), proof())
        }
        Some(p) => (Some(p.clone()), input.hash),
        None => (None, proof()),
    };

    let now = datetime::value(&datetime::now());
    let history: Vec<String> = match (&hashed, history_limit) {
        (Some(h), limit) if limit != 0 => vec![h.clone()],
        _ => Vec::new(),
    };
    let search = [Some(user_id.as_str()), email.as_deref(), input.phone.as_deref(), Some(name.as_str())]
        .map(|v| v.unwrap_or(""))
        .join(" ");
    let mut user = Document::default();
    user.put("$id", Value::from(user_id.as_str()));
    let any = Role::any();
    let owner = Role::user(&user_id, "");
    user.put(
        "$permissions",
        list(
            [Permission::read(&any), Permission::update(&owner), Permission::delete(&owner)]
                .map(|p| Value::from(p.as_str())),
        ),
    );
    user.put("email", text(email.as_deref()));
    user.put("emailVerification", Value::Bool(false));
    user.put("phone", text(input.phone.as_deref()));
    user.put("phoneVerification", Value::Bool(false));
    user.put("status", Value::Bool(true));
    user.put("labels", list([]));
    user.put("password", text(hashed.as_deref()));
    user.put("passwordHistory", documents::strings(&history));
    user.put("passwordUpdate", if hashed.is_some() { now.clone() } else { Value::Null });
    user.put("hash", Value::from(hash.name()));
    user.put("hashOptions", hash_options(hash.as_ref()));
    user.put("registration", now);
    user.put("reset", Value::Bool(false));
    user.put("name", Value::from(name.as_str()));
    user.put("prefs", Value::Object(Default::default()));
    user.put("sessions", Value::Null);
    user.put("tokens", Value::Null);
    user.put("memberships", Value::Null);
    user.put("search", Value::from(search.as_str()));
    meta.apply(&mut user);
    user.put("passwordPwned", input.password_pwned.map(Value::Bool).unwrap_or(Value::Null));

    // A new user has no sessions, targets, ... yet: its sub-queries would
    // find nothing (its targets are created below).
    let skipped = skipped(&[]);
    let created = match db.skip_filters(Some(skipped.as_slice()), db.create_document("users", user)).await {
        Ok(created) => created,
        Err(e) if e.is_a(Kind::Duplicate) => return Err(Error::new(ErrorType::UserAlreadyExists)),
        Err(e) => return Err(e.into()),
    };
    let mut user = User::from(&created);

    if let Some(email) = &email
        && let Some(t) = create_target(&db, &user, "email", email).await?
    {
        user.targets.push(t);
    }
    if let Some(phone) = input.phone.as_deref().filter(|p| !p.is_empty())
        && let Some(t) = create_target(&db, &user, "sms", phone).await?
    {
        user.targets.push(t);
    }
    db.purge_cached_document("users", &user.id).await?;
    Ok(user)
}

/// `Deletes\Targets::delete`: the user's targets, each with its subscribers
/// (decrementing their topics' totals).
pub async fn delete_user_targets(db: &Database, user_sequence: i64) -> Result<()> {
    db.delete_documents(
        "targets",
        vec![Query::equal("userInternalId", [user_sequence.to_string()]), Query::order_asc("", None)],
        utopia_database::database::BATCH_SIZE,
        Some(&mut DeleteSubscribers { db }),
    )
    .await?;
    Ok(())
}

/// `Deletes\Targets::deleteSubscribers`, for each deleted target.
struct DeleteSubscribers<'a> {
    db: &'a Database,
}

#[async_trait]
impl Callback<Document> for DeleteSubscribers<'_> {
    async fn call(&mut self, target: Document) -> utopia_database::Result<()> {
        let mut topics = DecreaseTopic { db: self.db, provider_type: target.string_or_empty("providerType") };
        self.db
            .delete_documents(
                "subscribers",
                vec![
                    Query::equal("targetInternalId", [documents::query_value(target.sequence())]),
                    Query::order_asc("", None),
                ],
                utopia_database::database::BATCH_SIZE,
                Some(&mut topics),
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
impl Callback<Document> for DecreaseTopic<'_> {
    async fn call(&mut self, subscriber: Document) -> utopia_database::Result<()> {
        let db = self.db;
        let topic_id = subscriber.string_or_empty("topicId");
        let topic_internal_id = documents::query_value(subscriber.get("topicInternalId"));
        let skipped = filters::names(filters::TOPICS_SUBQUERIES);
        let topic = db
            .skip_filters(
                Some(skipped.as_slice()),
                db.find_one(
                    "topics",
                    vec![Query::select(["$id", "$sequence"]), Query::equal("$sequence", [topic_internal_id])],
                ),
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
                Some(skipped.as_slice()),
                db.decrease_document_attribute("topics", &topic_id, total, Value::Int(1), Some(Value::Int(0))),
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
        vec![Query::equal("userInternalId", [user_sequence.to_string()]), Query::order_asc("", None)],
        utopia_database::database::BATCH_SIZE,
        None,
    )
    .await?;
    Ok(())
}
