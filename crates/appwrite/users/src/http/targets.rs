//! Messaging targets of a user.

use serde_json::{Value, json};
use utopia_database::PermissionType;
use utopia_database::{Document, Kind, Query, Value as DbValue};
use utopia_emails::EmailValidator;
use utopia_http::Response;
use utopia_validators::{Text, Validator, WhiteList, php};

use appwrite_core::database::documents::{self, Attributes, Provider, Target, text};
use appwrite_core::database::{LIMIT_COUNT, collections};
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
    documents::get::<Provider>(&db, "providers", id).await
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

    let target_id = base::id_or_unique(target_id);
    let provider = provider(ctx, &provider_id).await?;
    validate_identifier(&provider_type, &identifier)?;
    let user = base::user_or_404(ctx, &user_id, &[]).await?;
    let db = ctx.db()?;
    if !db.get_document("targets", &target_id, Vec::new(), false).await?.is_empty() {
        return Err(Error::new(ErrorType::UserTargetAlreadyExists));
    }
    let mut target = Document::default();
    target.put("$id", DbValue::from(target_id.as_str()));
    target.set("$permissions", base::owner_permissions(&user.id))?;
    target.put("providerId", text(provider.as_ref().map(|p| p.id.as_str())));
    target.put(
        "providerInternalId",
        provider.as_ref().map(|p| documents::internal_id(p.sequence)).unwrap_or(DbValue::Null),
    );
    target.put("providerType", DbValue::from(provider_type.as_str()));
    target.put("userId", DbValue::from(user_id.as_str()));
    target.put("userInternalId", documents::internal_id(user.sequence));
    target.put("identifier", DbValue::from(identifier.as_str()));
    target.put("name", text(Some(name.as_str()).filter(|n| !n.is_empty())));
    let target = match db.create_document("targets", target).await {
        Ok(target) => Target::from(&target),
        Err(e) if e.is_a(Kind::Duplicate) => return Err(Error::new(ErrorType::UserTargetAlreadyExists)),
        Err(e) => return Err(e.into()),
    };
    db.purge_cached_document("users", &user.id).await?;
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("targetId", target.id.clone());
    Ok(ctx.created(&TargetModel { target: &target }))
}

/// `GET /v1/users/:userId/targets`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let mut queries = ctx.queries(&collections::TARGETS)?;
    let include_total = ctx.optional_bool("total", true)?;
    base::user_or_404(ctx, &user_id, &[]).await?;
    queries.push(Query::equal("userId", [user_id.as_str()]));
    let db = ctx.db()?;
    base::resolve_cursor(&db, "targets", &mut queries, "Target").await?;
    let targets = db.find("targets", queries.clone(), PermissionType::Read).await?;
    let total = if include_total { db.count("targets", queries, Some(LIMIT_COUNT)).await? } else { 0 };
    let targets: Vec<Target> = documents::all(&targets);
    Ok(ctx.ok(&TargetListModel { total, targets: &targets }))
}

/// `GET /v1/users/:userId/targets/:targetId`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let target_id = ctx.required_str("targetId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, &[base::TARGETS]).await?;
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

/// The target `target_id` of the user, or `user_target_not_found`.
async fn owned_target(ctx: &mut Context, user_id: &str, target_id: &str) -> Result<Document> {
    let db = ctx.db()?;
    let target = db.get_document("targets", target_id, Vec::new(), false).await?;
    if target.is_empty() || target.string("userId").as_deref() != Some(user_id) {
        return Err(Error::new(ErrorType::UserTargetNotFound));
    }
    Ok(target)
}

/// `PATCH /v1/users/:userId/targets/:targetId`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let target_id = ctx.required_str("targetId", &uid())?;
    let identifier = ctx.optional_str("identifier", "", &Text::new(255))?.unwrap_or_default();
    let provider_id = ctx.optional_str("providerId", "", &uid())?.unwrap_or_default();
    let name = ctx.optional_str("name", "", &Text::new(128))?.unwrap_or_default();

    let user = base::user_or_404(ctx, &user_id, &[]).await?;
    let mut target = owned_target(ctx, &user.id, &target_id).await?;
    let truthy = |s: &str| php::truthy(&Value::String(s.to_owned()));
    if truthy(&identifier) {
        validate_identifier(&target.string_or_empty("providerType"), &identifier)?;
        target.put("identifier", DbValue::from(identifier.as_str()));
        target.put("expired", DbValue::Bool(false));
    }
    if truthy(&provider_id) {
        let Some(p) = provider(ctx, &provider_id).await? else {
            return Err(Error::new(ErrorType::ProviderNotFound));
        };
        if target.string("providerType").as_deref() != Some(p.kind.as_str()) {
            return Err(Error::new(ErrorType::ProviderIncorrectType));
        }
        target.put("providerId", DbValue::from(p.id.as_str()));
        target.put("providerInternalId", documents::internal_id(p.sequence));
    }
    if truthy(&name) {
        target.put("name", DbValue::from(name.as_str()));
    }
    let mut updates = Document::default();
    for key in ["identifier", "expired", "providerId", "providerInternalId", "name"] {
        updates.put(key, target.get(key).cloned().unwrap_or(DbValue::Null));
    }
    let db = ctx.db()?;
    let updated = match db.update_document("targets", target.id(), updates).await {
        Ok(updated) => Target::from(&updated),
        Err(e) if e.is_a(Kind::Duplicate) => return Err(Error::new(ErrorType::UserTargetAlreadyExists)),
        Err(e) => return Err(e.into()),
    };
    db.purge_cached_document("users", &user.id).await?;
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("targetId", updated.id.clone());
    Ok(ctx.ok(&TargetModel { target: &updated }))
}

/// `DELETE /v1/users/:userId/targets/:targetId`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let target_id = ctx.required_str("targetId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, &[]).await?;
    let target = Target::from(&owned_target(ctx, &user.id, &target_id).await?);
    let db = ctx.db()?;
    db.delete_document("targets", &target.id).await?;
    db.purge_cached_document("users", &user.id).await?;
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
