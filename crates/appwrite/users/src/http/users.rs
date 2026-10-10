//! `POST/GET /v1/users`, `GET/DELETE /v1/users/:userId`.

use serde_json::{Value, json};
use utopia_auth::hashes::Plaintext;
use utopia_database::sql::Builder;
use utopia_database::{FindOptions, FromRow, Param, Query, QueryGroups};
use utopia_http::Response;
use utopia_validators::{AllOf, Nullable, Text, Validator};

use appwrite_core::database::collections::{self, allowed};
use appwrite_core::database::documents::{Relations, Target, User};
use appwrite_core::platform::Context;
use appwrite_core::response::{UserListModel, UserModel};
use appwrite_core::validators::{CustomId, PasswordDictionary, PasswordStrength, Phone, uid};
use appwrite_core::{Error, ErrorType, Result};
use utopia_emails::EmailValidator;

use crate::base::{self, NewUser};

/// `POST /v1/users`.
pub async fn create(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &CustomId)?;
    let email = ctx.optional("email", Value::Null, &Nullable(EmailValidator::default()))?;
    let phone = ctx.optional("phone", Value::Null, &Nullable(Phone::default()))?;
    let state = ctx.state.clone();
    let password_validator = Nullable(AllOf::new(vec![
        Box::new(PasswordStrength::from_policy(ctx.project.auth("passwordStrength"), false)) as Box<dyn Validator + '_>,
        Box::new(PasswordDictionary {
            dictionary: &state.dictionary,
            enabled: ctx.project.auth_bool("passwordDictionary", false),
            allow_empty: false,
        }),
    ]));
    let password = ctx.optional("password", json!(""), &password_validator)?;
    let name = ctx.optional_str("name", "", &Text::new(128))?;

    let email = email.as_str().map(str::to_owned);
    let phone = phone.as_str().map(str::to_owned);
    let password = password.as_str().map(str::to_owned);

    let policy = ctx.project.auth("passwordPwned").cloned().unwrap_or(Value::Null);
    let enabled = policy.get("enabled").map(utopia_validators::php::truthy).unwrap_or(true);
    let password_pwned = match password.as_deref() {
        Some(p) if !p.is_empty() && enabled => Some(ctx.state.pwned.is_pwned(p).await?),
        _ => None,
    };
    if password_pwned == Some(true) && policy.get("users").map(utopia_validators::php::truthy).unwrap_or(false) {
        return Err(Error::new(ErrorType::UserPasswordPwned));
    }

    let user = base::create_user(
        ctx,
        NewUser { hash: Box::new(Plaintext::new()), user_id, email, password, phone, name, password_pwned },
    )
    .await?;
    Ok(ctx.created(&UserModel { user: &user, render: ctx.render() }))
}

/// `GET /v1/users`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let mut queries = ctx.queries(&collections::USERS, allowed::USERS)?;
    let search = ctx.optional_str("search", "", &Text::new(256))?.unwrap_or_default();
    let include_total = ctx.optional_bool("total", true)?;

    if !search.is_empty() {
        queries.push(Query::search("search", &search));
    }
    let groups = QueryGroups::from_queries(&queries);
    let db = ctx.db()?;
    let roles = ctx.auth_roles().map(|r| r.to_vec());
    let find = db.find::<User>(&collections::USERS, FindOptions::from_groups(&groups, roles.as_deref()));
    let count = async {
        if include_total {
            db.count(&collections::USERS, &groups.filters, Some(5000), roles.as_deref()).await.map(Some)
        } else {
            Ok(None)
        }
    };
    let (users, total) = futures_util::join!(find, count);
    let mut users = match users {
        Ok(u) => u,
        Err(utopia_database::Error::NotFound(m)) if m.starts_with("cursor:") => {
            let id = groups.cursor.as_ref().map(|c| c.id.clone()).unwrap_or_default();
            return Err(Error::with_message(
                ErrorType::GeneralCursorNotFound,
                format!("User '{id}' for the 'cursor' value not found."),
            ));
        }
        Err(e) => return Err(e.into()),
    };
    let total = total?.unwrap_or(0);

    if !users.is_empty() {
        let sequences: Vec<String> = users.iter().map(|u| u.sequence.to_string()).collect();
        let mut b = Builder::new();
        let p = b.bind(Param::TextArray(sequences));
        let tenant = db.tenant_condition(&mut b, "main");
        let sql = format!(
            "SELECT {} FROM {} AS \"main\" WHERE \"main\".\"userInternalId\" = ANY({p}){tenant} ORDER BY \"_id\" ASC",
            Target::COLUMNS,
            db.table("targets")
        );
        let rows = db.query(&sql, &b).await?;
        for row in rows {
            let target = Target::from_row(&row)?;
            if let Some(u) = users.iter_mut().find(|u| u.sequence.to_string() == target.user_internal_id) {
                u.targets.push(target);
            }
        }
    }

    Ok(ctx.ok(&UserListModel { total, users: &users, render: ctx.render() }))
}

/// `GET /v1/users/:userId`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, Relations::TARGETS).await?;
    if let Some(roles) = ctx.auth_roles() {
        let readable = utopia_database::permission::roles_for(&user.permissions, "read").any(|r| roles.contains(&r));
        if !readable {
            return Err(Error::new(ErrorType::UserNotFound));
        }
    }
    Ok(ctx.ok(&UserModel { user: &user, render: ctx.render() }))
}

/// `DELETE /v1/users/:userId`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, Relations::TARGETS).await?;
    let db = ctx.db()?;
    db.delete("users", &user.id).await?;
    base::delete_user_identities(&db, user.sequence).await?;
    base::delete_user_targets(&db, user.sequence).await?;

    let document = json!({
        "$id": user.id,
        "$sequence": user.sequence.to_string(),
        "$collection": "users",
        "$permissions": user.permissions,
        "email": user.email,
        "phone": user.phone,
        "name": user.name,
    });
    let message = appwrite_core::event::delete_message(&ctx.project.message_payload(), "document", document);
    ctx.events.deletes.push(message);
    ctx.event_param("userId", user.id.clone());
    let payload = base::user_payload(ctx, &user);
    ctx.events.payload = Some(payload);
    Ok(ctx.no_content())
}
