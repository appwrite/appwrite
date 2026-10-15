//! Area `core`: `db.*`, `database.*` and `db.authorization.*`, the twin
//! of `tests/compat/database/ops/core.php`. The README (`database`: the
//! core vocabulary) documents every operation.

use serde_json::{Value as Json, json};
use utopia_database::Value;
use utopia_database::value::{self, Str};

use super::args::{self, Args};
use super::convert::{IntoObj, object, strings};
use super::ctx::{R, fault};
use super::database::{self, AuthorizationObj, ProfilerObj};
use super::obj::Obj;
use super::{codec, fixtures, objects, open};

/// `fooBarBaz` from `foo_bar_baz` (the inverse of `Core::snake`).
pub fn camel(snake: &str) -> String {
    let mut out = String::with_capacity(snake.len());
    let mut upper = false;
    for c in snake.chars() {
        if c == '_' {
            upper = true;
        } else if upper {
            out.extend(c.to_uppercase());
            upper = false;
        } else {
            out.push(c);
        }
    }
    out
}

/// Runs one `core` operation.
pub async fn call(op: &str, json: &Json, a: &Args) -> R<Obj> {
    let cx = &a.cx;
    match op {
        "db.open" => return open::open(a).await,
        "db.select" => {
            let db = cx.db(json)?;
            cx.set_current(db);
            return Ok(true.into_obj());
        }
        "db.log" => return Ok(Obj::Value(codec::decode(&Json::Array(cx.take_log()))?)),
        "db.add_format" => {
            fixtures::add_format(&a.string("name")?)?;
            return Ok(true.into_obj());
        }
        "database.add_filter" => {
            let name = a.string("name")?;
            let fixture = a.string_or("fixture", &name)?;
            utopia_database::Database::add_filter(&name, fixtures::filter(&fixture)?);
            return Ok(Obj::null());
        }
        "db.sql" => return sql(a).await,
        "db.cache" => return cache_call(a).await,
        "db.redis" => {
            let command: Vec<String> = a.strings("command")?;
            if command.is_empty() {
                return fault("db.redis: `command` is a non-empty list");
            }
            let mut connection = open::redis(cx, "database-redis-adapter").await?;
            let mut cmd = redis::cmd(&command[0]);
            for arg in &command[1..] {
                cmd.arg(arg);
            }
            let _: redis::Value = cmd
                .query_async(&mut connection)
                .await
                .map_err(|e| crate::adapter::Fault::new(format!("db.redis: {e}")))?;
            return Ok(Obj::null());
        }
        "db.mirror" | "database.on_error" | "database.get_source" | "database.get_destination"
        | "database.create_upgrades" | "database.await_replications" => {
            return fault(format!("{op}: Mirror is not ported to the Rust database 8 crate (src/legacy)"));
        }
        "db.adapter" => {
            let db = cx.db(json)?;
            let inner = Args::new(cx, json.get("args").unwrap_or(&Json::Null))?;
            return super::adapter::invoke(db.database.adapter(), &a.string_or("method", "")?, &inner).await;
        }
        "db.profiler" => {
            let db = cx.db(json)?;
            let Some(profiler) = db.database.profiler() else {
                return fault("db.profiler: the database is not profiling");
            };
            let inner = Args::new(cx, json.get("args").unwrap_or(&Json::Null))?;
            let mut this = object(ProfilerObj(profiler));
            return objects::invoke(&mut this, &a.string_or("method", "")?, &inner).await;
        }
        "database.collection_definition" => return database::call_static("collectionDefinition", a),
        "database.internal_attributes_for" => return database::call_static("internalAttributesFor", a),
        _ => {}
    }
    if let Some(method) = op.strip_prefix("db.authorization.") {
        let db = cx.db(json)?;
        let mut this = object(AuthorizationObj(db.database.authorization()));
        return objects::invoke(&mut this, &camel(method), a).await;
    }
    if let Some(method) = op.strip_prefix("database.") {
        let db = cx.db(json)?;
        return database::invoke(&db.database, &camel(method), a).await;
    }
    fault(format!("database: unknown operation `{op}`"))
}

/// `db.sql`: a statement on the database's own connection, in its schema
/// only (`Core::sql`).
async fn sql(a: &Args) -> R<Obj> {
    let db = a.cx.db(&Json::Object(a.json().clone()))?;
    let sql = a.string("sql")?;
    let schema = db.database.database();
    for quote in ['"', '`'] {
        let marker = format!("{quote}.");
        let mut at = 0;
        while let Some(found) = sql[at..].find(&marker) {
            let end = at + found;
            let start = sql[..end].rfind(quote);
            let name = start.map(|s| &sql[s + 1..end]).unwrap_or("");
            if name != schema {
                return fault(format!("db.sql: only schema `{schema}` may be named, not `{name}`"));
            }
            at = end + 2;
        }
    }
    let Some(adapter) = db.sql else {
        return fault("db.sql: this database has no connection of its own");
    };
    let mut pdo = adapter.pdo().lock().await;
    let mut statement = pdo.query(&sql).await?;
    let rows = statement.fetch_all_assoc();
    Ok(Obj::Value(Value::Array(rows.into_iter().map(Value::Array).collect())))
}

/// `db.cache`: a method of the database's cache (`Core::cacheCall`). Saves
/// report whether they stored.
async fn cache_call(a: &Args) -> R<Obj> {
    let db = a.cx.db(&Json::Object(a.json().clone()))?;
    let cache = db.database.cache();
    let inner = Args::new(&a.cx, a.raw("args").unwrap_or(&Json::Null))?;
    let key = inner.string_or("key", "")?;
    let hash = inner.string_or("hash", "")?;
    let failed = |e: utopia_cache::Error| super::ctx::Fail::Error(utopia_database::Error::from(e));
    Ok(match a.string_or("method", "")?.as_str() {
        "load" => {
            let ttl = inner.int_or("ttl", utopia_database::database::TTL)?;
            match cache.load(&key, ttl, &hash).await.map_err(failed)? {
                Some(v) => Obj::Value(value::from_plain(v)),
                None => false.into_obj(),
            }
        }
        "save" => {
            let data = value::to_plain(&inner.value("data")?);
            cache.save(&key, &data, &hash, inner.int_or("ttl", 0)?).await.map_err(failed)?.into_obj()
        }
        "save_with_lease" => {
            let data = value::to_plain(&inner.value("data")?);
            let generation = inner.string_or("generation", "")?;
            cache.save_with_lease(&key, &data, &hash, &generation).await.map_err(failed)?.into_obj()
        }
        "get_generation" => cache.generation(&key).await.map_err(failed)?.into_obj(),
        "touch" => cache.touch(&key, &hash).await.map_err(failed)?.into_obj(),
        "list" => strings(&cache.list(&key).await.map_err(failed)?),
        "purge" => cache.purge(&key, &hash).await.map_err(failed)?.into_obj(),
        other => return fault(format!("db.cache: no method `{other}`")),
    })
}

/// The handle of a stored database (`{"$handle": ...}`).
pub fn handle_json(id: &Json) -> Json {
    json!({"$handle": id})
}

#[allow(dead_code)]
fn unused(_: Str) {
    let _ = args::tags;
}
