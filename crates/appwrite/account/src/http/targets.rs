//! Push targets of the session's device: `/v1/account/targets/push`.

use utopia_database::{Database, Document, Query, Value};
use utopia_http::Response;
use utopia_validators::Text;

use appwrite_core::database::documents::{self, Attributes, Target, User, text};
use appwrite_core::platform::{Context, Route};
use appwrite_core::response::TargetModel;
use appwrite_core::validators::{CustomId, uid};
use appwrite_core::{Error, ErrorType, Result};

use crate::audit;
use crate::base::profile as base;

const TARGETS_WRITE: &[&str] = &["targets.write"];

/// `Database::LENGTH_KEY`.
const LENGTH_KEY: usize = 255;

pub fn routes() -> Vec<Route> {
    vec![
        profile_route!(
            POST,
            "/v1/account/targets/push",
            "createPushTarget",
            "createPushTarget",
            TARGETS_WRITE,
            Some("users.[userId].targets.[targetId].create"),
            audit("target.create", "target/response.$id"),
            create
        ),
        profile_route!(
            PUT,
            "/v1/account/targets/:targetId/push",
            "updatePushTarget",
            "updatePushTarget",
            TARGETS_WRITE,
            Some("users.[userId].targets.[targetId].update"),
            audit("target.update", "target/response.$id"),
            update
        ),
        profile_route!(
            DELETE,
            "/v1/account/targets/:targetId/push",
            "deletePushTarget",
            "deletePushTarget",
            TARGETS_WRITE,
            Some("users.[userId].targets.[targetId].delete"),
            audit("target.delete", "target/{request.targetId}"),
            delete
        ),
    ]
}

/// The device's name: `"<brand> <model>"`, empty parts for unknown ones.
fn device_name(ctx: &Context) -> String {
    let detected = appwrite_core::detector::detect(ctx.request.header_or_empty("user-agent"));
    format!("{} {}", detected.device_brand.unwrap_or_default(), detected.device_model.unwrap_or_default())
}

/// The request's user: an empty one for keys (PHP's empty `User`).
fn request_user(ctx: &Context) -> User {
    ctx.user.clone().unwrap_or_default()
}

/// `getDocument('targets', $id)`, skipping authorization.
async fn target(db: &Database, id: &str) -> Result<Document> {
    let authorization = db.authorization();
    Ok(authorization.skip(|| db.get_document("targets", id, Vec::new(), false)).await?)
}

/// `POST /v1/account/targets/push`.
pub async fn create(ctx: &mut Context) -> Result<Response> {
    let target_id = ctx.required_str("targetId", &CustomId)?;
    let identifier = ctx.required_str("identifier", &Text::new(LENGTH_KEY))?;
    let provider_id = ctx.optional_str("providerId", "", &uid())?.unwrap_or_default();
    let target_id = if target_id == "unique()" { appwrite_core::database::unique_id() } else { target_id };
    let user = request_user(ctx);
    let db = ctx.db()?;

    let authorization = db.authorization();
    let provider = authorization.skip(|| db.get_document("providers", &provider_id, Vec::new(), false)).await?;
    if !target(&db, &target_id).await?.is_empty() {
        return Err(Error::new(ErrorType::UserTargetAlreadyExists));
    }
    let name = device_name(ctx);
    let session_id = ctx.session.as_ref().map(|s| s.id.clone()).unwrap_or_default();
    let session = db.get_document("sessions", &session_id, Vec::new(), false).await?;

    // A session is one device install holding one push token per provider:
    // a rotated token replaces the one it superseded.
    let mut siblings = if session.is_empty() {
        Vec::new()
    } else {
        let provider_query = if provider_id.is_empty() {
            Query::is_null("providerId")
        } else {
            Query::equal("providerId", [provider_id.as_str()])
        };
        authorization
            .skip(|| {
                db.find(
                    "targets",
                    vec![
                        Query::equal("userInternalId", [documents::internal_id(user.sequence)]),
                        Query::equal("sessionInternalId", [session.sequence().cloned().unwrap_or(Value::Null)]),
                        Query::equal("providerType", ["push"]),
                        provider_query,
                        Query::order_asc("$sequence"),
                        Query::limit(base::LIMIT_SUBQUERY),
                    ],
                    Database::PERMISSION_READ,
                )
            })
            .await?
    };
    // The sibling already holding this token is reused, else the oldest.
    let current = match siblings.iter().position(|s| s.string("identifier").as_deref() == Some(identifier.as_str())) {
        Some(i) => Some(siblings.remove(i)),
        None if !siblings.is_empty() => Some(siblings.remove(0)),
        None => None,
    };

    let written = match &current {
        None => {
            let mut document = Document::default();
            document.set("$id", Value::from(target_id.as_str()));
            document.set("$permissions", base::owner_permissions(&user.id));
            let provided = !provider_id.is_empty();
            document.set("providerId", text(provided.then_some(provider_id.as_str())));
            document.set(
                "providerInternalId",
                if provided { provider.sequence().cloned().unwrap_or(Value::Null) } else { Value::Null },
            );
            document.set("providerType", Value::from("push"));
            document.set("userId", Value::from(user.id.as_str()));
            document.set("userInternalId", documents::internal_id(user.sequence));
            document.set("sessionId", Value::from(session.id()));
            document.set("sessionInternalId", session.sequence().cloned().unwrap_or(Value::Null));
            document.set("identifier", Value::from(identifier.as_str()));
            document.set("name", Value::from(name.as_str()));
            db.create_document("targets", document).await
        }
        Some(current) => {
            let mut updates = Document::default();
            updates.set("identifier", Value::from(identifier.as_str()));
            updates.set("expired", Value::Bool(false));
            updates.set("name", Value::from(name.as_str()));
            db.update_document("targets", current.id(), updates).await
        }
    };
    let written = match written {
        Ok(target) => Target::from(&target),
        Err(e) if e.is_a(utopia_database::Kind::Duplicate) => {
            return Err(Error::new(ErrorType::UserTargetAlreadyExists));
        }
        Err(e) => return Err(e.into()),
    };
    // The rest hold tokens the client no longer uses: expired, for the
    // maintenance sweep to release.
    for sibling in &siblings {
        let mut updates = Document::default();
        updates.set("expired", Value::Bool(true));
        db.update_document("targets", sibling.id(), updates).await?;
    }
    db.purge_cached_document("users", Some(&user.id)).await?;

    ctx.event_param("userId", user.id.clone());
    ctx.event_param("targetId", written.id.clone());
    Ok(ctx.created(&TargetModel { target: &written }))
}

/// `PUT /v1/account/targets/:targetId/push`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let target_id = ctx.required_str("targetId", &uid())?;
    let identifier = ctx.required_str("identifier", &Text::new(LENGTH_KEY))?;
    let user = request_user(ctx);
    let db = ctx.db()?;

    let mut document = target(&db, &target_id).await?;
    if document.is_empty() || document.string("userId").as_deref() != Some(user.id.as_str()) {
        return Err(Error::new(ErrorType::UserTargetNotFound));
    }
    if !identifier.is_empty() && identifier != "0" {
        document.set("identifier", Value::from(identifier.as_str()));
        document.set("expired", Value::Bool(false));
    }
    let mut updates = Document::default();
    updates.set("identifier", document.get("identifier").cloned().unwrap_or(Value::Null));
    updates.set("expired", document.get("expired").cloned().unwrap_or(Value::Null));
    updates.set("name", Value::from(device_name(ctx).as_str()));
    let updated = match db.update_document("targets", document.id(), updates).await {
        Ok(target) => Target::from(&target),
        Err(e) if e.is_a(utopia_database::Kind::Duplicate) => {
            return Err(Error::new(ErrorType::UserTargetAlreadyExists));
        }
        Err(e) => return Err(e.into()),
    };
    db.purge_cached_document("users", Some(&user.id)).await?;

    ctx.event_param("userId", user.id.clone());
    ctx.event_param("targetId", updated.id.clone());
    Ok(ctx.ok(&TargetModel { target: &updated }))
}

/// `DELETE /v1/account/targets/:targetId/push`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let target_id = ctx.required_str("targetId", &uid())?;
    let user = request_user(ctx);
    let db = ctx.db()?;

    let document = target(&db, &target_id).await?;
    let sequence = if user.sequence == 0 { String::new() } else { user.sequence.to_string() };
    if document.is_empty() || document.string("userInternalId").unwrap_or_default() != sequence {
        return Err(Error::new(ErrorType::UserTargetNotFound));
    }
    db.delete_document("targets", document.id()).await?;
    db.purge_cached_document("users", Some(&user.id)).await?;

    let payload = Value::Array(document.array_copy(&[], &[])).to_json();
    let message = appwrite_core::event::delete_message(&ctx.project.message_payload(), "target", payload);
    ctx.events.deletes.push(message);

    let target = Target::from(&document);
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("targetId", target.id.clone());
    ctx.event_payload(&TargetModel { target: &target });
    Ok(ctx.no_content())
}
