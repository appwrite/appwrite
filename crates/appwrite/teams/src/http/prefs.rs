//! Team preferences.

use serde_json::{Map, Value};
use utopia_database::Document;
use utopia_http::Response;
use utopia_validators::Assoc;

use appwrite_core::Result;
use appwrite_core::platform::Context;
use appwrite_core::response::prefs_value;
use appwrite_core::validators::uid;

use crate::base;

/// `GET /v1/teams/:teamId/prefs`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let team_id = ctx.required_str("teamId", &uid())?;
    let db = ctx.db()?;
    let (_, team) = base::team_or_404(&db, &team_id).await?;
    Ok(ctx.ok(&prefs_value(&team.prefs)))
}

/// `PUT /v1/teams/:teamId/prefs`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let team_id = ctx.required_str("teamId", &uid())?;
    let prefs = ctx.required("prefs", &Assoc::default())?;
    let db = ctx.db()?;
    let (document, _) = base::team_or_404(&db, &team_id).await?;
    let mut updates = Document::default();
    // The request model's value: the `json` filter encodes it as PHP does.
    updates.put("prefs", utopia_database::Value::from_json(&prefs));
    let team = db.update_document("teams", document.id(), updates).await?;
    ctx.event_param("teamId", team.id().to_owned());
    let body = match prefs {
        Value::Object(m) if !m.is_empty() => Value::Object(m),
        _ => Value::Object(Map::new()),
    };
    Ok(ctx.ok(&body))
}
