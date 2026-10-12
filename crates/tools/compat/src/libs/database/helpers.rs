//! document.*, id.*, datetime.*, authorization.*, connection.*, change.*,
//! error.*: Document, the ID helper, DateTime, Authorization, Connection,
//! Change and the exception classes. The twin of `ops/helpers.php` (read its
//! header for the conventions).

use std::future::Future;
use std::pin::Pin;

use serde_json::{Value as Json, json};
use utopia_database::document::{Document, SetType};
use utopia_database::validator::authorization::{Authorization, Input};
use utopia_database::value::{ArrayKey, Ext, Str, Value};
use utopia_database::{Change, Error, Kind, connection, datetime, helpers::id};

use super::codec::{decode, decode_array, document, encode, encode_array, encode_document, error};
use crate::adapter::{Args, Fault, OpResult, Outcome, Session};

/// Operations of this area (must match `spec.d/helpers.json` and `ops/helpers.php`).
pub const OPS: &[&str] = &[
    "document.new",
    "document.handle",
    "document.clone",
    "document.dump",
    "document.offset_get",
    "document.get_id",
    "document.get_sequence",
    "document.get_collection",
    "document.get_created_at",
    "document.get_updated_at",
    "document.get_tenant",
    "document.get_permissions",
    "document.get_read",
    "document.get_create",
    "document.get_update",
    "document.get_delete",
    "document.get_write",
    "document.get_permissions_by_type",
    "document.get_attributes",
    "document.get_attribute",
    "document.set_attribute",
    "document.set_attributes",
    "document.remove_attribute",
    "document.find",
    "document.find_and_replace",
    "document.find_and_remove",
    "document.is_empty",
    "document.is_set",
    "document.get_array_copy",
    "id.unique",
    "id.custom",
    "datetime.now",
    "datetime.format",
    "datetime.add_seconds",
    "datetime.set_timezone",
    "datetime.format_tz",
    "authorization.new",
    "authorization.is_valid",
    "authorization.is_valid_value",
    "authorization.add_role",
    "authorization.remove_role",
    "authorization.get_roles",
    "authorization.clean_roles",
    "authorization.has_role",
    "authorization.set_default_status",
    "authorization.set_status",
    "authorization.get_status",
    "authorization.enable",
    "authorization.disable",
    "authorization.reset",
    "authorization.get_description",
    "authorization.is_array",
    "authorization.get_type",
    "authorization.skip",
    "authorization.input",
    "connection.has_error",
    "change.new",
    "error.new",
];

const NOW: &str = "depends on now";

pub async fn call(op: &str, args: &Json, session: &mut Session) -> OpResult {
    let a = Args(args);
    if let Some(op) = op.strip_prefix("document.") {
        return document_op(op, &a, session);
    }
    if let Some(op) = op.strip_prefix("authorization.") {
        return authorization_op(op, &a, session).await;
    }
    Ok(match op {
        "id.unique" => {
            let made = match a.opt_i64("padding")? {
                Some(padding) => id::unique_padded(padding),
                None => id::unique(),
            };
            match made {
                Ok(id) => Outcome::ok(json!({
                    "id": id,
                    "length": id.len(),
                    "hex": id.bytes().all(|c| c.is_ascii_hexdigit()),
                })),
                Err(e) => error(&e),
            }
        }
        "id.custom" => Outcome::ok(id::custom(a.str("id")?)),
        "datetime.now" => Outcome::ok(datetime::now()),
        "datetime.format" => {
            let date = a.str("date")?;
            if depends_on_now(date) {
                return Ok(Outcome::ok(NOW));
            }
            match parse(date) {
                Ok(d) => Outcome::ok(datetime::format(&d)),
                Err(e) => e,
            }
        }
        "datetime.add_seconds" => {
            let date = a.str("date")?;
            if depends_on_now(date) {
                return Ok(Outcome::ok(NOW));
            }
            match parse(date) {
                Ok(mut d) => result(datetime::add_seconds(&mut d, a.i64("seconds")?).map(Json::from)),
                Err(e) => e,
            }
        }
        "datetime.set_timezone" => {
            let value = a.str("datetime")?;
            if depends_on_now(value) {
                return Ok(Outcome::ok(NOW));
            }
            result(datetime::set_timezone(value).map(Json::from))
        }
        "datetime.format_tz" => {
            let value = a.opt_str("value")?;
            if value.is_some_and(depends_on_now) {
                return Ok(Outcome::ok(NOW));
            }
            Outcome::ok(datetime::format_tz(value))
        }
        "connection.has_error" => Outcome::ok(connection::has_error(a.bytes("message")?)),
        "change.new" => {
            let mut change = Change::new(document_arg(&a, "old")?, document_arg(&a, "new")?);
            if a.0.get("set_old").is_some() {
                change.old = document_arg(&a, "set_old")?;
            }
            if a.0.get("set_new").is_some() {
                change.new = document_arg(&a, "set_new")?;
            }
            Outcome::ok(json!({ "old": encode_document(&change.old), "new": encode_document(&change.new) }))
        }
        "error.new" => {
            let class = a.opt_str("class")?.unwrap_or("Utopia\\Database\\Exception");
            let kind = Kind::from_php_class(class)
                .filter(|k| Error::new(*k, "").is_a(Kind::Database))
                .ok_or_else(|| Fault::new(format!("not a database exception: {class}")))?;
            let message = a.str("message")?;
            let e = if kind == Kind::Order {
                Error::order(message, a.opt_str("attribute")?.map(str::to_owned))
            } else {
                Error::new(kind, message)
            };
            let e = match a.opt("code") {
                None => e,
                Some(Json::String(s)) => e.with_string_code(s.as_bytes()),
                Some(code) => {
                    e.with_code(code.as_i64().ok_or_else(|| Fault::new("`code` must be an int or a string"))?)
                }
            };
            Outcome::ok(json!({
                "class": e.php_class(),
                "message": e.message(),
                "code": e.code(),
                "attribute": e.attribute(),
                "database": e.is_a(Kind::Database),
            }))
        }
        _ => return Err(Fault::new(format!("database: unknown operation `{op}`"))),
    })
}

/// A result or an error, as the driver reports them.
fn result(r: utopia_database::Result<Json>) -> Outcome {
    match r {
        Ok(v) => Outcome::Ok(v),
        Err(e) => error(&e),
    }
}

/// A document argument: a `$document` tag or a plain array.
fn document_arg(a: &Args<'_>, key: &str) -> Result<Document, Fault> {
    match a.0.get(key) {
        None | Some(Json::Null) => Ok(Document::empty()),
        Some(v) => document(v),
    }
}

/// `new \DateTime($s)` in UTC, or the error PHP throws.
fn parse(s: &str) -> Result<php_std::datetime::DateTime, Outcome> {
    php_std::datetime::DateTime::new(s.as_bytes(), None, &php_std::datetime::TzInfo::utc())
        .map_err(|e| Outcome::err_bytes(e.php_class(), e.message_bytes()))
}

/// Whether `new \DateTime($s)` reads the clock: a blank string, or one
/// `date_parse($s)` leaves the date unset in.
fn depends_on_now(s: &str) -> bool {
    if s.bytes().all(|c| matches!(c, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b)) {
        return true;
    }
    let p = php_std::datetime::date_parse(s.as_bytes()).to_php();
    p["error_count"] == json!(0) && ["year", "month", "day"].iter().any(|k| p[*k] == Json::Bool(false))
}

fn document_op(op: &str, a: &Args<'_>, session: &mut Session) -> OpResult {
    match op {
        "new" => {
            let input = array_arg(a, "input")?;
            return Ok(match Document::new(input) {
                Ok(d) => Outcome::ok(encode_document(&d)),
                Err(e) => error(&e),
            });
        }
        "handle" => {
            let doc = document_arg(a, "input")?;
            return Ok(Outcome::Ok(session.handle(doc)));
        }
        _ => {}
    }
    let mut scratch = Document::empty();
    // `at` may walk into arrays of documents: resolve the path element by element.
    let d = resolve(a, session, &mut scratch)?;
    let key = || a.str("key");
    let value = |k: &str| -> Result<Value, Fault> { a.0.get(k).map_or(Ok(Value::Null), decode) };
    Ok(match op {
        "clone" => {
            let copy = d.clone();
            return Ok(Outcome::Ok(session.handle(copy)));
        }
        "dump" => Outcome::ok(encode_document(d)),
        "offset_get" => Outcome::ok(d.raw(key()?).map_or(Json::Null, encode)),
        "get_id" => Outcome::ok(d.id()),
        "get_sequence" => Outcome::ok(d.sequence().map_or(Json::Null, encode)),
        "get_collection" => Outcome::ok(d.collection()),
        "get_created_at" => Outcome::ok(d.created_at()),
        "get_updated_at" => Outcome::ok(d.updated_at()),
        "get_tenant" => Outcome::ok(d.tenant().map_or(Json::Null, |t| encode(&t))),
        "get_permissions" => array(d.permission_list()),
        "get_read" => array(d.roles("read")),
        "get_create" => array(d.roles("create")),
        "get_update" => array(d.roles("update")),
        "get_delete" => array(d.roles("delete")),
        "get_write" => array(d.write_roles()),
        "get_permissions_by_type" => array(d.roles(a.str("type")?)),
        "get_attributes" => Outcome::ok(encode_array(&d.attributes())),
        "get_attribute" => {
            let default = value("default")?;
            Outcome::ok(encode(d.get_or(key()?, &default)))
        }
        "set_attribute" => {
            let how = match a.opt_str("type")? {
                None => SetType::Assign,
                Some(t) => SetType::from_name(t).ok_or_else(|| Fault::new(format!("unknown set type `{t}`")))?,
            };
            d.set_with(key()?, value("value")?, how);
            Outcome::ok(encode_document(d))
        }
        "set_attributes" => {
            d.set_many(array_arg(a, "attributes")?);
            Outcome::ok(encode_document(d))
        }
        "remove_attribute" => {
            d.remove(key()?);
            Outcome::ok(encode_document(d))
        }
        "find" => match d.try_find(key()?, &value("find")?, a.opt_str("subject")?.unwrap_or("")) {
            Ok(found) => Outcome::ok(found.map_or(Json::Bool(false), |v| encode(&v))),
            Err(e) => error(&e),
        },
        "find_and_replace" => {
            let (find, replace) = (value("find")?, value("replace")?);
            match d.try_find_and_replace(key()?, &find, replace, a.opt_str("subject")?.unwrap_or("")) {
                Ok(b) => Outcome::ok(b),
                Err(e) => error(&e),
            }
        }
        "find_and_remove" => {
            match d.try_find_and_remove(key()?, &value("find")?, a.opt_str("subject")?.unwrap_or("")) {
                Ok(b) => Outcome::ok(b),
                Err(e) => error(&e),
            }
        }
        "is_empty" => Outcome::ok(d.is_empty()),
        "is_set" => Outcome::ok(d.is_set(key()?)),
        "get_array_copy" => {
            let list =
                |k: &str| -> Result<Vec<Value>, Fault> { Ok(array_arg(a, k)?.into_iter().map(|(_, v)| v).collect()) };
            Outcome::ok(encode_array(&d.array_copy_values(&list("allow")?, &list("disallow")?)))
        }
        _ => return Err(Fault::new(format!("database: unknown operation `document.{op}`"))),
    })
}

/// The document `doc` names, then down `at`: a document attribute, or an
/// element of an array attribute.
fn resolve<'s>(a: &Args<'_>, session: &'s mut Session, scratch: &'s mut Document) -> Result<&'s mut Document, Fault> {
    let path: Vec<String> =
        a.0.get("at")
            .and_then(Json::as_array)
            .into_iter()
            .flatten()
            .map(|k| match k {
                Json::String(s) => s.clone(),
                other => other.to_string(),
            })
            .collect();
    let doc = a.0.get("doc").unwrap_or(&Json::Null);
    let root: &mut Document = if doc.get("$handle").is_some() {
        session.get_mut::<Document>(doc)?
    } else {
        *scratch = document_arg(a, "doc")?;
        scratch
    };
    let Some((first, rest)) = path.split_first() else { return Ok(root) };
    let missing = || Fault::new("`at` does not lead to a document");
    let mut current: &mut Value = root.get_mut(first).ok_or_else(missing)?;
    for key in rest {
        current = match current {
            Value::Ext(Ext::Document(d)) => d.get_mut(key).ok_or_else(missing)?,
            Value::Array(items) => items.get_mut(&ArrayKey::normalize(Str::from(key.as_str()))).ok_or_else(missing)?,
            _ => return Err(missing()),
        };
    }
    match current {
        Value::Ext(Ext::Document(d)) => Ok(d),
        _ => Err(missing()),
    }
}

/// An array argument (`Values::decodeArray($a[$key] ?? [])`).
fn array_arg(a: &Args<'_>, key: &str) -> Result<utopia_database::Array, Fault> {
    match a.0.get(key) {
        None | Some(Json::Null) => Ok(utopia_database::Array::new()),
        Some(v) => decode_array(v),
    }
}

/// An array result, or the error computing it.
fn array(r: utopia_database::Result<utopia_database::Array>) -> Outcome {
    match r {
        Ok(a) => Outcome::ok(encode_array(&a)),
        Err(e) => error(&e),
    }
}

/// The authorization an operation works on: `auth` (a handle), or a new
/// one with `roles` added.
fn with_auth<T>(a: &Args<'_>, session: &mut Session, f: impl FnOnce(&Authorization) -> T) -> Result<T, Fault> {
    if let Some(h) = a.opt("auth") {
        return Ok(f(session.get::<Authorization>(h)?));
    }
    Ok(f(&new_auth(a)?))
}

fn new_auth(a: &Args<'_>) -> Result<Authorization, Fault> {
    let auth = Authorization::new();
    for role in a.opt("roles").and_then(Json::as_array).into_iter().flatten() {
        auth.add_role(role.as_str().ok_or_else(|| Fault::new("roles must be strings"))?);
    }
    Ok(auth)
}

fn strings(a: &Args<'_>, key: &str) -> Result<Vec<String>, Fault> {
    a.opt(key)
        .and_then(Json::as_array)
        .into_iter()
        .flatten()
        .map(|p| p.as_str().map(str::to_owned).ok_or_else(|| Fault::new(format!("`{key}` must be strings"))))
        .collect()
}

fn verdict(auth: &Authorization, valid: bool) -> Json {
    json!({ "valid": valid, "description": auth.description() })
}

async fn authorization_op(op: &str, a: &Args<'_>, session: &mut Session) -> OpResult {
    let role = || a.str("role");
    let status = || a.bool("status");
    let null = |_: ()| Json::Null;
    Ok(Outcome::Ok(match op {
        "new" => {
            let auth = new_auth(a)?;
            session.handle(auth)
        }
        "is_valid" => {
            let input = Input::new(a.str("action")?, strings(a, "permissions")?);
            with_auth(a, session, |auth| verdict(auth, auth.is_valid(&input)))?
        }
        "is_valid_value" => {
            let value = a.0.get("value").cloned().unwrap_or(Json::Null);
            with_auth(a, session, |auth| verdict(auth, utopia_validators::Validator::is_valid(auth, &value)))?
        }
        "add_role" => null(with_auth(a, session, |auth| role().map(|r| auth.add_role(r)))??),
        "remove_role" => null(with_auth(a, session, |auth| role().map(|r| auth.remove_role(r)))??),
        "get_roles" => with_auth(a, session, |auth| Json::Array(auth.roles().iter().map(encode).collect()))?,
        "clean_roles" => null(with_auth(a, session, Authorization::clean_roles)?),
        "has_role" => with_auth(a, session, |auth| role().map(|r| Json::Bool(auth.has_role(r))))??,
        "set_default_status" => null(with_auth(a, session, |auth| status().map(|s| auth.set_default_status(s)))??),
        "set_status" => null(with_auth(a, session, |auth| status().map(|s| auth.set_status(s)))??),
        "get_status" => with_auth(a, session, |auth| Json::Bool(auth.status()))?,
        "enable" => null(with_auth(a, session, Authorization::enable)?),
        "disable" => null(with_auth(a, session, Authorization::disable)?),
        "reset" => null(with_auth(a, session, Authorization::reset)?),
        "get_description" => with_auth(a, session, |auth| Json::String(auth.description()))?,
        "is_array" => with_auth(a, session, |auth| Json::Bool(utopia_validators::Validator::is_array(auth)))?,
        "get_type" => {
            with_auth(a, session, |auth| Json::String(utopia_validators::Validator::kind(auth).as_str().to_owned()))?
        }
        "skip" => {
            let owned;
            let auth: &Authorization = match a.opt("auth") {
                Some(h) => session.get::<Authorization>(h)?,
                None => {
                    owned = new_auth(a)?;
                    &owned
                }
            };
            let check = match a.opt_str("action")? {
                Some(action) => Some(Input::new(action, strings(a, "permissions")?)),
                None => None,
            };
            let throw = a.opt_bool("throw")?.unwrap_or(false);
            let mut inside = Vec::new();
            let depth = a.opt_i64("depth")?.unwrap_or(1);
            let valid = auth.skip(|| observe(auth, depth, &mut inside, check.as_ref(), throw)).await;
            return Ok(match valid {
                Ok(valid) => Outcome::ok(json!({ "inside": inside, "valid": valid, "after": auth.status() })),
                Err(message) => Outcome::err("Exception", message),
            });
        }
        "input" => {
            let mut input = Input::new(a.str("action")?, strings(a, "permissions")?);
            if a.0.get("set_action").is_some() {
                input.action = a.str("set_action")?.to_owned();
            }
            if a.0.get("set_permissions").is_some() {
                input.permissions = strings(a, "set_permissions")?;
            }
            json!({ "action": input.action, "permissions": input.permissions })
        }
        _ => return Err(Fault::new(format!("database: unknown operation `authorization.{op}`"))),
    }))
}

/// What the innermost level of `authorization.skip` saw: `isValid()`, or the
/// message of the exception it threw.
type Observed<'a> = Pin<Box<dyn Future<Output = Result<Option<bool>, &'static str>> + 'a>>;

/// The body of `authorization.skip`: records the status at each level,
/// nests another skip until `level` 1, then checks `input` (or throws).
fn observe<'a>(
    auth: &'a Authorization,
    level: i64,
    inside: &'a mut Vec<bool>,
    input: Option<&'a Input>,
    throw: bool,
) -> Observed<'a> {
    Box::pin(async move {
        inside.push(auth.status());
        if level > 1 {
            let result = auth.skip(|| observe(auth, level - 1, &mut *inside, input, throw)).await;
            inside.push(auth.status());
            return result;
        }
        if throw {
            return Err("thrown inside skip");
        }
        Ok(input.map(|i| auth.is_valid(i)))
    })
}
