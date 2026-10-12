//! Area `relationships` of `core`: relationship attributes and the
//! relationship scopes (`database.*` operations on `Database`'s
//! `relationships.rs` and the relationship scopes of `config.rs`).
//!
//! Owner: the relationships agent. See [`super::args`] for the shared API.

use serde_json::Value as Json;
use utopia_database::Database;
use utopia_database::value::Value;

use super::args::{self, Args, Calls, R, encode, fault};
use crate::adapter::Session;

/// Operations of this area.
pub const OPS: &[&str] = &[
    "database.create_relationship",
    "database.update_relationship",
    "database.delete_relationship",
    "database.skip_relationships",
    "database.skip_relationships_exist_check",
];

/// Runs one operation of this area.
pub async fn call(op: &str, json: &Json, session: &mut Session) -> R<Json> {
    let a = Args::new(json);
    let db = args::db(json, session)?;
    match op {
        "database.create_relationship" => {
            let id = a.opt_string("id")?;
            let two_way_key = a.opt_string("twoWayKey")?;
            let on_delete = a.string_or("onDelete", Database::RELATION_MUTATE_RESTRICT)?;
            Ok(Json::Bool(
                db.create_relationship(
                    &a.string("collection")?,
                    &a.string("relatedCollection")?,
                    &a.string("type")?,
                    a.bool_or("twoWay", false)?,
                    id.as_deref(),
                    two_way_key.as_deref(),
                    &on_delete,
                )
                .await?,
            ))
        }
        "database.update_relationship" => {
            let new_key = a.opt_string("newKey")?;
            let new_two_way_key = a.opt_string("newTwoWayKey")?;
            let on_delete = a.opt_string("onDelete")?;
            Ok(Json::Bool(
                db.update_relationship(
                    &a.string("collection")?,
                    &a.string("id")?,
                    new_key.as_deref(),
                    new_two_way_key.as_deref(),
                    a.opt_bool("twoWay")?,
                    on_delete.as_deref(),
                )
                .await?,
            ))
        }
        "database.delete_relationship" => {
            Ok(Json::Bool(db.delete_relationship(&a.string("collection")?, &a.string("id")?).await?))
        }
        "database.skip_relationships" | "database.skip_relationships_exist_check" => {
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = if op == "database.skip_relationships" {
                db.skip_relationships(|| cb.invoke(Vec::new())).await
            } else {
                db.skip_relationships_exist_check(|| cb.invoke(Vec::new())).await
            };
            let r = r.map(|v| encode(&v.unwrap_or(Value::Null))).map_err(Into::into);
            calls.finish(r)
        }
        _ => fault(format!("database: unknown operation `{op}`")),
    }
}
