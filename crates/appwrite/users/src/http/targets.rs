//! Messaging targets of a user.

use serde_json::{Value, json};
use utopia_database::{FindOptions, FromRow, Param, Query, QueryGroups};
use utopia_emails::EmailValidator;
use utopia_http::Response;
use utopia_validators::{Text, Validator, WhiteList, php};

use appwrite_core::database::collections::{self, allowed};
use appwrite_core::database::documents::{Provider, Relations, Target};
use appwrite_core::platform::Context;
use appwrite_core::response::{TargetListModel, TargetModel};
use appwrite_core::validators::{CustomId, Phone, uid};
use appwrite_core::{Error, ErrorType, Result};

use crate::base;

async fn provider(ctx: &mut Context, id: &str) -> Result<Option<Provider>> {
    if id.is_empty() {
        return Ok(None);
    }
    let db = ctx.db()?;
    Ok(db.get::<Provider>("providers", id).await?)
}

fn validate_identifier(provider_type: &str, identifier: &str) -> Result<()> {
    let value = Value::String(identifier.to_owned());
    match provider_type {
        "email" if !EmailValidator::default().is_valid(&value) => Err(Error::new(ErrorType::GeneralInvalidEmail)),
        "sms" if !Phone::default().is_valid(&value) => Err(Error::new(ErrorType::GeneralInvalidPhone)),
        "email" | "sms" | "push" => Ok(()),
        _ => Err(Error::new(ErrorType::ProviderIncorrectType)),
    }
}

/// `POST /v1/users/:userId/targets`.
pub async fn create(ctx: &mut Context) -> Result<Response> {
    let target_id = ctx.required_str("targetId", &CustomId)?;
    let user_id = ctx.required_str("userId", &uid())?;
    let provider_type = ctx.required_str("providerType", &WhiteList::new(&["email", "sms", "push"]))?;
    let identifier = ctx.required_str("identifier", &Text::new(255))?;
    let provider_id = ctx.optional_str("providerId", "", &uid())?.unwrap_or_default();
    let name = ctx.optional_str("name", "", &Text::new(128))?.unwrap_or_default();

    let target_id = if target_id == "unique()" { utopia_database::id::unique() } else { target_id };
    let provider = provider(ctx, &provider_id).await?;
    validate_identifier(&provider_type, &identifier)?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    let db = ctx.db()?;
    if db.exists("targets", &target_id).await? {
        return Err(Error::new(ErrorType::UserTargetAlreadyExists));
    }
    let columns = vec![
        ("providerId", Param::opt_text(provider.as_ref().map(|p| p.id.clone()))),
        ("providerInternalId", Param::opt_text(provider.as_ref().map(|p| p.sequence.to_string()))),
        ("providerType", Param::Text(provider_type)),
        ("userId", Param::text(user.id.as_str())),
        ("userInternalId", Param::Text(user.sequence.to_string())),
        ("identifier", Param::Text(identifier)),
        ("name", Param::opt_text(if name.is_empty() { None } else { Some(name) })),
        ("expired", Param::Bool(false)),
    ];
    let row = match db.insert("targets", &target_id, &base::owner_permissions(&user.id), columns, Target::COLUMNS).await
    {
        Ok(row) => row,
        Err(utopia_database::Error::Duplicate(_)) => return Err(Error::new(ErrorType::UserTargetAlreadyExists)),
        Err(e) => return Err(e.into()),
    };
    let target = Target::from_row(&row)?;
    db.purge_cached_document("users", &user.id).await;
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("targetId", target.id.clone());
    Ok(ctx.created(&TargetModel { target: &target }))
}

/// `GET /v1/users/:userId/targets`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let mut queries = ctx.queries(&collections::TARGETS, allowed::TARGETS)?;
    let include_total = ctx.optional_bool("total", true)?;
    base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    queries.push(Query::equal("userId", vec![json!(user_id)]));
    let groups = QueryGroups::from_queries(&queries);
    let db = ctx.db()?;
    let targets = match db.find::<Target>(&collections::TARGETS, FindOptions::from_groups(&groups, None)).await {
        Ok(t) => t,
        Err(utopia_database::Error::NotFound(m)) if m.starts_with("cursor:") => {
            let id = groups.cursor.as_ref().map(|c| c.id.clone()).unwrap_or_default();
            return Err(Error::with_message(
                ErrorType::GeneralCursorNotFound,
                format!("Target '{id}' for the 'cursor' value not found."),
            ));
        }
        Err(e) => return Err(e.into()),
    };
    let total =
        if include_total { db.count(&collections::TARGETS, &groups.filters, Some(5000), None).await? } else { 0 };
    Ok(ctx.ok(&TargetListModel { total, targets: &targets }))
}

/// `GET /v1/users/:userId/targets/:targetId`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let target_id = ctx.required_str("targetId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, Relations::TARGETS).await?;
    if let Some(target) = user.targets.iter().find(|t| t.id == target_id) {
        return Ok(ctx.ok(&TargetModel { target }));
    }
    // `Document::find` falls back to the user document when it has no targets.
    if user.targets.is_empty() && target_id == user.id {
        let pseudo = Target {
            id: user.id.clone(),
            created_at: user.created_at,
            updated_at: user.updated_at,
            name: user.name.clone(),
            ..Default::default()
        };
        return Ok(ctx.ok(&TargetModel { target: &pseudo }));
    }
    Err(Error::new(ErrorType::UserTargetNotFound))
}

async fn owned_target(ctx: &mut Context, user_id: &str, target_id: &str) -> Result<Target> {
    let db = ctx.db()?;
    match db.get::<Target>("targets", target_id).await? {
        Some(t) if t.user_id == user_id => Ok(t),
        _ => Err(Error::new(ErrorType::UserTargetNotFound)),
    }
}

/// `PATCH /v1/users/:userId/targets/:targetId`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let target_id = ctx.required_str("targetId", &uid())?;
    let identifier = ctx.optional_str("identifier", "", &Text::new(255))?.unwrap_or_default();
    let provider_id = ctx.optional_str("providerId", "", &uid())?.unwrap_or_default();
    let name = ctx.optional_str("name", "", &Text::new(128))?.unwrap_or_default();

    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    let target = owned_target(ctx, &user.id, &target_id).await?;
    let mut columns: Vec<(&'static str, Param)> = Vec::new();
    let truthy = |s: &str| php::truthy(&Value::String(s.to_owned()));
    if truthy(&identifier) {
        validate_identifier(&target.provider_type, &identifier)?;
        columns.push(("identifier", Param::Text(identifier)));
        columns.push(("expired", Param::Bool(false)));
    }
    if truthy(&provider_id) {
        let Some(p) = provider(ctx, &provider_id).await? else {
            return Err(Error::new(ErrorType::ProviderNotFound));
        };
        if p.kind != target.provider_type {
            return Err(Error::new(ErrorType::ProviderIncorrectType));
        }
        columns.push(("providerId", Param::Text(p.id)));
        columns.push(("providerInternalId", Param::Text(p.sequence.to_string())));
    }
    if truthy(&name) {
        columns.push(("name", Param::Text(name)));
    }
    let db = ctx.db()?;
    let row = match db.update("targets", &target.id, columns, Target::COLUMNS).await {
        Ok(Some(row)) => row,
        Ok(None) => return Err(Error::new(ErrorType::UserTargetNotFound)),
        Err(utopia_database::Error::Duplicate(_)) => return Err(Error::new(ErrorType::UserTargetAlreadyExists)),
        Err(e) => return Err(e.into()),
    };
    let updated = Target::from_row(&row)?;
    db.purge_cached_document("users", &user.id).await;
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("targetId", updated.id.clone());
    Ok(ctx.ok(&TargetModel { target: &updated }))
}

/// `DELETE /v1/users/:userId/targets/:targetId`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let target_id = ctx.required_str("targetId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    let target = owned_target(ctx, &user.id, &target_id).await?;
    let db = ctx.db()?;
    db.delete("targets", &target.id).await?;
    db.purge_cached_document("users", &user.id).await;
    let document = json!({
        "$id": target.id,
        "$sequence": target.sequence.to_string(),
        "$collection": "targets",
        "providerType": target.provider_type,
        "userId": target.user_id,
        "identifier": target.identifier,
    });
    let message = appwrite_core::event::delete_message(&ctx.project.message_payload(), "target", document);
    ctx.events.deletes.push(message);
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("targetId", target.id.clone());
    Ok(ctx.no_content())
}
