//! Shared domain steps of the Users module (`Users\Base`).

use bytes::Bytes;
use utopia_auth::Hash;
use utopia_database::sql::Builder;
use utopia_database::{Database, FromRow, Param, datetime, permission};
use utopia_emails::Metadata;

use appwrite_core::crypto;
use appwrite_core::database::documents::{self, Relations, Target, User};
use appwrite_core::platform::Context;
use appwrite_core::response::UserModel;
use appwrite_core::{Error, ErrorType, Result};

/// The proof Appwrite uses for plaintext passwords (`proofForPassword`).
pub fn proof() -> Hash {
    Hash::Argon2 { memory_cost: 7168, time_cost: 5, threads: 1 }
}

/// Hashes a plaintext password on the blocking pool.
pub async fn hash_password(password: String) -> Result<String> {
    tokio::task::spawn_blocking(move || proof().hash(&password))
        .await
        .map_err(|e| Error::internal(e.to_string()))?
        .map_err(|e| Error::internal(e.to_string()))
}

/// Loads a user or fails with `user_not_found`.
pub async fn user_or_404(ctx: &mut Context, id: &str, relations: Relations) -> Result<User> {
    let db = ctx.db()?;
    documents::load_user(&db, id, relations).await?.ok_or_else(|| Error::new(ErrorType::UserNotFound))
}

/// JSON of the `user` model (used as event payload).
pub fn user_payload(ctx: &Context, user: &User) -> Bytes {
    Bytes::from(appwrite_core::json::to_vec(&UserModel { user, render: ctx.render() }))
}

/// Targets of a user by `$id` (sub-select on the users table).
pub async fn targets_of(db: &Database, user_id: &str) -> Result<Vec<Target>> {
    let mut b = Builder::new();
    let p = b.bind(Param::text(user_id));
    let tenant_u = db.tenant_condition(&mut b, "u");
    let tenant_m = db.tenant_condition(&mut b, "main");
    let sql = format!(
        "SELECT {} FROM {} AS \"main\" WHERE \"main\".\"userInternalId\" = (SELECT u.\"_id\"::text FROM {} AS u WHERE u.\"_uid\" = {p}{tenant_u}){tenant_m} ORDER BY \"_id\" ASC LIMIT 1000",
        Target::COLUMNS,
        db.table("targets"),
        db.table("users")
    );
    let rows = db.query(&sql, &b).await?;
    Ok(rows.iter().map(Target::from_row).collect::<utopia_database::Result<Vec<_>>>()?)
}

/// Sparse update of a user. `user` holds the merged (post-update) values so
/// the `search` column can be recomputed when searchable fields change.
/// Returns the stored user with its targets.
pub async fn update_user(ctx: &mut Context, user: &User, mut columns: Vec<(&'static str, Param)>) -> Result<User> {
    let db = ctx.db()?;
    if columns.iter().any(|(c, _)| matches!(*c, "name" | "email" | "phone" | "labels")) {
        columns.push(("search", Param::Text(user.search())));
    }
    let (updated, targets) =
        futures_util::join!(db.update("users", &user.id, columns, User::COLUMNS), targets_of(&db, &user.id));
    let row = updated?.ok_or_else(|| Error::new(ErrorType::UserNotFound))?;
    let mut fresh = User::from_row(&row)?;
    fresh.targets = targets?;
    Ok(fresh)
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

/// Permissions granted to a user on their own documents.
pub fn owner_permissions(user_id: &str) -> Vec<String> {
    let role = permission::role::user(user_id);
    vec![permission::read(&role), permission::update(&role), permission::delete(&role)]
}

/// Creates a messaging target; on a duplicate identifier returns the
/// existing target (PHP appends it without raising).
pub async fn create_target(
    db: &Database,
    user: &User,
    provider_type: &str,
    identifier: &str,
) -> Result<Option<Target>> {
    let id = utopia_database::id::unique();
    let columns = vec![
        ("userId", Param::text(user.id.as_str())),
        ("userInternalId", Param::Text(user.sequence.to_string())),
        ("providerType", Param::text(provider_type)),
        ("identifier", Param::text(identifier)),
        ("expired", Param::Bool(false)),
    ];
    match db.insert("targets", &id, &owner_permissions(&user.id), columns, Target::COLUMNS).await {
        Ok(row) => Ok(Some(Target::from_row(&row)?)),
        Err(utopia_database::Error::Duplicate(_)) => target_by_identifier(db, identifier).await,
        Err(e) => Err(e.into()),
    }
}

/// `findOne('targets', [equal('identifier', [$identifier])])`.
pub async fn target_by_identifier(db: &Database, identifier: &str) -> Result<Option<Target>> {
    let mut b = Builder::new();
    let p = b.bind(Param::text(identifier));
    let tenant = db.tenant_condition(&mut b, "main");
    let sql = format!(
        "SELECT {} FROM {} AS \"main\" WHERE \"main\".\"identifier\" = {p}{tenant} ORDER BY \"_id\" ASC LIMIT 1",
        Target::COLUMNS,
        db.table("targets")
    );
    Ok(match db.query_opt(&sql, &b).await? {
        Some(row) => Some(Target::from_row(&row)?),
        None => None,
    })
}

/// Whether an identity with `providerEmail = email` exists (optionally for another user).
pub async fn identity_email_taken(db: &Database, email: &str, exclude_user_sequence: Option<i64>) -> Result<bool> {
    let mut b = Builder::new();
    let p = b.bind(Param::text(email));
    let exclude = match exclude_user_sequence {
        Some(seq) => format!(" AND \"main\".\"userInternalId\" != {}", b.bind(Param::Text(seq.to_string()))),
        None => String::new(),
    };
    let tenant = db.tenant_condition(&mut b, "main");
    let sql = format!(
        "SELECT 1 FROM {} AS \"main\" WHERE \"main\".\"providerEmail\" = {p}{exclude}{tenant} LIMIT 1",
        db.table("identities")
    );
    Ok(db.query_opt(&sql, &b).await?.is_some())
}

/// Input of [`create_user`].
pub struct NewUser {
    pub hash: Hash,
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

    let user_id = if input.user_id == "unique()" { utopia_database::id::unique() } else { input.user_id };

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

    let plaintext = matches!(input.hash, Hash::Plaintext);
    let password = input.password.filter(|p| !p.is_empty());
    let (hashed, hash) = match &password {
        Some(p) if plaintext => {
            ctx.state.hooks.password_validator(ctx, p, None).await?;
            (Some(hash_password(p.clone()).await?), proof())
        }
        Some(p) => (Some(p.clone()), input.hash),
        None => (None, proof()),
    };

    let now = datetime::now();
    let mut user = User {
        id: user_id.clone(),
        email: email.clone(),
        phone: input.phone.clone(),
        name: Some(name.clone()),
        labels: Vec::new(),
        ..Default::default()
    };
    let history: Vec<String> = match (&hashed, history_limit) {
        (Some(h), limit) if limit != 0 => vec![h.clone()],
        _ => Vec::new(),
    };
    let columns = vec![
        ("email", Param::opt_text(email.clone())),
        ("emailVerification", Param::Bool(false)),
        ("phone", Param::opt_text(input.phone.clone())),
        ("phoneVerification", Param::Bool(false)),
        ("status", Param::Bool(true)),
        ("labels", Param::string_list(&[])),
        ("password", Param::opt_text(hashed.as_deref().map(crypto::encrypt_env))),
        ("passwordHistory", Param::string_list(&history)),
        ("passwordUpdate", Param::opt_timestamp(hashed.as_ref().map(|_| now))),
        ("hash", Param::text(hash.name())),
        ("hashOptions", Param::Text(appwrite_core::json::to_string(&hash.options()))),
        ("registration", Param::Timestamp(now)),
        ("reset", Param::Bool(false)),
        ("name", Param::Text(name)),
        ("prefs", Param::text("{}")),
        ("search", Param::Text(user.search())),
        ("emailCanonical", Param::opt_text(meta.canonical.clone())),
        ("emailIsCanonical", Param::opt_bool(meta.is_canonical)),
        ("emailIsCorporate", Param::opt_bool(meta.is_corporate)),
        ("emailIsDisposable", Param::opt_bool(meta.is_disposable)),
        ("emailIsFree", Param::opt_bool(meta.is_free)),
        ("passwordPwned", Param::opt_bool(input.password_pwned)),
        ("mfaRecoveryCodes", Param::string_list(&[])),
        ("impersonator", Param::Bool(false)),
        ("photoSize", Param::Int(0)),
    ];
    let permissions = vec![
        permission::read("any"),
        permission::update(&permission::role::user(&user_id)),
        permission::delete(&permission::role::user(&user_id)),
    ];
    let row = match db.insert("users", &user_id, &permissions, columns, User::COLUMNS).await {
        Ok(row) => row,
        Err(utopia_database::Error::Duplicate(_)) => return Err(Error::new(ErrorType::UserAlreadyExists)),
        Err(e) => return Err(e.into()),
    };
    user = User::from_row(&row)?;

    // Database listener: `users.[userId].create` with the user as stored.
    if !ctx.project.is_console() {
        let payload = user_payload(ctx, &user);
        ctx.events.created_users.push((user.id.clone(), payload));
    }

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
    db.purge_cached_document("users", &user.id).await;
    Ok(user)
}

/// Deletes targets of a user together with their subscribers, decrementing
/// topic counters (`Deletes\Targets::delete`). Returns deleted target ids.
pub async fn delete_user_targets(db: &Database, user_sequence: i64) -> Result<()> {
    let mut b = Builder::new();
    let seq = b.bind(Param::Text(user_sequence.to_string()));
    let (tenant_and, tenant_eq) = match db.tenant() {
        Some(t) => {
            let p = b.bind(Param::Int(t));
            (format!(" AND \"_tenant\" = {p}"), format!(" AND tp.\"_tenant\" = {p}"))
        }
        None => (String::new(), String::new()),
    };
    let sql = format!(
        r#"WITH t AS (
    DELETE FROM {targets} WHERE "userInternalId" = {seq}{tenant_and} RETURNING "_id", "_uid", "providerType"
), tperm AS (
    DELETE FROM {targets_perms} WHERE "_document" IN (SELECT "_uid" FROM t){tenant_and}
), s AS (
    DELETE FROM {subscribers} WHERE "targetInternalId" IN (SELECT "_id"::text FROM t){tenant_and}
    RETURNING "_uid", "topicInternalId", "targetInternalId"
), sp AS (
    DELETE FROM {subscribers_perms} WHERE "_document" IN (SELECT "_uid" FROM s){tenant_and}
), counts AS (
    SELECT s."topicInternalId" AS topic,
        COUNT(*) FILTER (WHERE t."providerType" = 'email') AS email,
        COUNT(*) FILTER (WHERE t."providerType" = 'sms') AS sms,
        COUNT(*) FILTER (WHERE t."providerType" = 'push') AS push
    FROM s JOIN t ON s."targetInternalId" = t."_id"::text GROUP BY s."topicInternalId"
), topics AS (
    UPDATE {topics} AS tp SET
        "emailTotal" = GREATEST(COALESCE(tp."emailTotal", 0) - counts.email, 0),
        "smsTotal" = GREATEST(COALESCE(tp."smsTotal", 0) - counts.sms, 0),
        "pushTotal" = GREATEST(COALESCE(tp."pushTotal", 0) - counts.push, 0),
        "_updatedAt" = {now}
    FROM counts WHERE tp."_id"::text = counts.topic{tenant_eq}
    RETURNING tp."_uid"
)
SELECT 'targets' AS c, "_uid" FROM t
UNION ALL SELECT 'subscribers', "_uid" FROM s
UNION ALL SELECT 'topics', "_uid" FROM topics"#,
        targets = db.table("targets"),
        targets_perms = db.perms_table("targets"),
        subscribers = db.table("subscribers"),
        subscribers_perms = db.perms_table("subscribers"),
        topics = db.table("topics"),
        now = b.bind(Param::Timestamp(datetime::now())),
    );
    let rows = db.query(&sql, &b).await?;
    for row in rows {
        let collection: String = row.try_get(0).unwrap_or_default();
        let id: String = row.try_get(1).unwrap_or_default();
        db.purge_cached_document(&collection, &id).await;
    }
    Ok(())
}

/// Deletes identities of a user (`Deletes\Identities::delete`).
pub async fn delete_user_identities(db: &Database, user_sequence: i64) -> Result<()> {
    let mut b = Builder::new();
    let seq = b.bind(Param::Text(user_sequence.to_string()));
    let tenant = match db.tenant() {
        Some(t) => format!(" AND \"_tenant\" = {}", b.bind(Param::Int(t))),
        None => String::new(),
    };
    let sql = format!(
        "WITH d AS (DELETE FROM {} WHERE \"userInternalId\" = {seq}{tenant} RETURNING \"_uid\"), \
         p AS (DELETE FROM {} WHERE \"_document\" IN (SELECT \"_uid\" FROM d){tenant}) SELECT \"_uid\" FROM d",
        db.table("identities"),
        db.perms_table("identities")
    );
    for row in db.query(&sql, &b).await? {
        let id: String = row.try_get(0).unwrap_or_default();
        db.purge_cached_document("identities", &id).await;
    }
    Ok(())
}
