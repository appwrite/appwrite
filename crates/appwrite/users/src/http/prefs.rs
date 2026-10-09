//! User preferences.

use serde_json::{Map, Value};
use utopia_database::Param;
use utopia_http::Response;
use utopia_validators::Assoc;

use appwrite_core::Result;
use appwrite_core::database::documents::Relations;
use appwrite_core::platform::Context;
use appwrite_core::response::prefs_value;
use appwrite_core::validators::uid;

use crate::base;

/// `GET /v1/users/:userId/prefs`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    Ok(ctx.ok(&prefs_value(&user.prefs)))
}

/// `PATCH /v1/users/:userId/prefs`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let user_id = ctx.required_str("userId", &uid())?;
    let prefs = ctx.required("prefs", &Assoc::default())?;
    let mut user = base::user_or_404(ctx, &user_id, Relations::NONE).await?;
    let stored = match &prefs {
        Value::Array(a) if a.is_empty() => "[]".to_owned(),
        other => appwrite_core::json::to_string(other),
    };
    user.prefs = Some(prefs.clone());
    let updated = base::update_user(ctx, &user, vec![("prefs", Param::Text(stored))]).await?;
    ctx.event_param("userId", updated.id.clone());
    let payload = base::user_payload(ctx, &updated);
    ctx.events.payload = Some(payload);
    let body = match prefs {
        Value::Object(m) if !m.is_empty() => Value::Object(m),
        _ => Value::Object(Map::new()),
    };
    Ok(ctx.ok(&body))
}
