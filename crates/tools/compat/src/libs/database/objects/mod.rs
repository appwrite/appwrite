//! Area `objects`: every class of the library outside `Database` and its
//! adapters, by name (`object.new`, `object.call`, `object.get`), the twin
//! of `tests/compat/database/ops/objects.php` and `Objects.php`.
//!
//! PHP drives the classes by reflection; here each class has its entries in
//! one of the submodules, which the router below tries in turn:
//!
//! | Module | Classes |
//! |---|---|
//! | [`document`] | `Document` (and `Collection` read as one) |
//! | [`query`] | `Query`, `Operator`, `ParsedQuery` |
//! | [`model`] | `Attribute`, `Index`, `Relationship`, `Collection`, the update models, `Format`, `Change`, `Permission`, `Role`, `Id`, `DateTime`, `Storage`, `Connection`, the exceptions, the events, the enums |
//! | [`validators`] | `Validator\*` (but `Validator\Authorization`) |
//! | [`services`] | `Validator\Authorization` and its `Input`, `Profiler`, `Cache\*`, `Filter\*`, `Hook\*`, `State\*`, `Builder\*`, `Query\Builder\*`, `Adapter\SQL\Hook\*`, `Adapter\Limits`, `Adapter\Profile` |
//!
//! Each submodule exposes `construct(class, args)`, `call_static(class,
//! method, args)` and `invoke(this, method, args)`, returning `Ok(None)`
//! for what it does not handle.

use std::sync::{LazyLock, Mutex};

use serde_json::Value as Json;

use super::args::{self, Args};
use super::ctx::{R, fault, lock};
use super::database::{self, DatabaseObj};
use super::obj::Obj;
use super::open::AdapterObj;

pub mod document;
pub mod model;
pub mod query;
pub mod services;
pub mod validators;

/// The formats registered through `Structure::addFormat` calls, removed
/// when the next case starts.
static FORMATS: LazyLock<Mutex<Vec<String>>> = LazyLock::new(|| Mutex::new(Vec::new()));

/// Records a format registered by a case.
pub fn note_format(name: &str) {
    lock(&FORMATS).push(name.to_owned());
}

/// The formats cases registered since the last call.
pub fn take_formats() -> Vec<String> {
    std::mem::take(&mut *lock(&FORMATS))
}

/// Runs one `objects` operation.
pub async fn call(op: &str, a: &Args) -> R<Obj> {
    let inner = Args::new(&a.cx, a.raw("args").unwrap_or(&Json::Null))?;
    match op {
        "object.new" => construct(&a.string("class")?, &inner).await,
        "object.call" => {
            let method = a.string("method")?;
            match a.raw("this") {
                Some(this) => call_on(this, &method, &inner).await,
                None => call_static(&a.string("class")?, &method, &inner).await,
            }
        }
        "object.get" => {
            let this = args::decode_obj(&a.cx, a.raw("this").unwrap_or(&Json::Null))?;
            property(&this, &a.string("property")?)
        }
        _ => fault(format!("database: unknown operation `{op}`")),
    }
}

/// `object.call` on `this`: a stored object (changed in place, as PHP's
/// objects are references) or a value tag decoded afresh.
pub async fn call_on(this: &Json, method: &str, a: &Args) -> R<Obj> {
    if let Some(name) =
        this.get("$obj").and_then(Json::as_str).filter(|_| this.as_object().is_some_and(|o| o.len() == 1))
    {
        let mut target = a.cx.stored(name)?.into_result();
        let result = invoke(&mut target, method, a).await;
        a.cx.store(name, target);
        return result;
    }
    let mut target = args::decode_obj(&a.cx, this)?;
    invoke(&mut target, method, a).await
}

/// `new <class>(...args)`.
pub async fn construct(class: &str, a: &Args) -> R<Obj> {
    if class == "Database" {
        return new_database(a).await;
    }
    if class.starts_with("Adapter\\")
        && !class.starts_with("Adapter\\SQL\\")
        && class != "Adapter\\Limits"
        && class != "Adapter\\Profile"
    {
        if let Some(obj) = super::adapter::construct(class, a).await? {
            return Ok(obj);
        }
    }
    if let Some(obj) = document::construct(class, a).await? {
        return Ok(obj);
    }
    if let Some(obj) = query::construct(class, a).await? {
        return Ok(obj);
    }
    if let Some(obj) = model::construct(class, a).await? {
        return Ok(obj);
    }
    if let Some(obj) = validators::construct(class, a).await? {
        return Ok(obj);
    }
    if let Some(obj) = services::construct(class, a).await? {
        return Ok(obj);
    }
    fault(format!("object.new: class `{class}` is not supported"))
}

/// `<class>::<method>(...args)`.
pub async fn call_static(class: &str, method: &str, a: &Args) -> R<Obj> {
    if class == "Database" {
        return database::call_static(method, a);
    }
    if let Some(obj) = document::call_static(class, method, a).await? {
        return Ok(obj);
    }
    if let Some(obj) = query::call_static(class, method, a).await? {
        return Ok(obj);
    }
    if let Some(obj) = model::call_static(class, method, a).await? {
        return Ok(obj);
    }
    if let Some(obj) = validators::call_static(class, method, a).await? {
        return Ok(obj);
    }
    if let Some(obj) = services::call_static(class, method, a).await? {
        return Ok(obj);
    }
    if (class.starts_with("Adapter\\") || class == "Connection")
        && let Some(obj) = super::adapter::call_static(class, method, a).await?
    {
        return Ok(obj);
    }
    fault(format!("object.call: `{class}::{method}` is not supported"))
}

/// `$this->method(...args)`.
pub async fn invoke(this: &mut Obj, method: &str, a: &Args) -> R<Obj> {
    if let Some(db) = this.downcast_ref::<DatabaseObj>() {
        let db = db.0.clone();
        return database::invoke(&db, method, a).await;
    }
    if let Some(adapter) = this.downcast_ref::<AdapterObj>() {
        let adapter = adapter.0.clone();
        return super::adapter::invoke(&adapter, method, a).await;
    }
    if let Some(auth) = this.downcast_ref::<super::database::AuthorizationObj>() {
        let auth = auth.0.clone();
        if let Some(obj) = super::core::authorization(&auth, method, a).await? {
            return Ok(obj);
        }
    }
    if let Some(obj) = document::invoke(this, method, a).await? {
        return Ok(obj);
    }
    if let Some(obj) = query::invoke(this, method, a).await? {
        return Ok(obj);
    }
    if let Some(obj) = model::invoke(this, method, a).await? {
        return Ok(obj);
    }
    if let Some(obj) = validators::invoke(this, method, a).await? {
        return Ok(obj);
    }
    if let Some(obj) = services::invoke(this, method, a).await? {
        return Ok(obj);
    }
    fault(format!("object.call: `{}->{method}` is not supported", describe(this)))
}

/// A public property of an object (`object.get`).
pub fn property(this: &Obj, property: &str) -> R<Obj> {
    // A value object's property is its field on the wire.
    let encoded = this.encode();
    if let Some(fields) = encoded.get("fields").and_then(Json::as_object)
        && let Some(v) = fields.get(property)
    {
        return args::decode_obj_static(v);
    }
    if let Some(obj) = model::property(this, property)? {
        return Ok(obj);
    }
    fault(format!("{} has no public property `{property}`", describe(this)))
}

/// What an object is, for messages.
pub fn describe(this: &Obj) -> String {
    match this {
        Obj::Object(o) => o.class().to_owned(),
        Obj::Value(v) => match v {
            utopia_database::Value::Ext(e) => php_std::Extension::class(e).to_owned(),
            other => other.type_name().to_owned(),
        },
        Obj::Enum(e) => format!("{}::{}", e.class, e.case),
        Obj::List(_) | Obj::Map(_) => "array".to_owned(),
        Obj::Called(r, _) => describe(r),
    }
}

/// `new Database($adapter, $cache, $filters)`: a database like `db.open`'s,
/// without its setup.
async fn new_database(a: &Args) -> R<Obj> {
    let adapter = super::adapter::adapter_param(a, "adapter")?;
    let cache = database::cache(a, "cache").await?;
    let codecs = match a.raw("filters") {
        None | Some(Json::Null) => Vec::new(),
        Some(list) => super::codec::list(list)?
            .into_iter()
            .map(|spec| match args::stored_obj(&a.cx, spec)? {
                Some(obj) => Ok(args::downcast::<super::fixtures::CodecObj>(&obj)?.0),
                None => Ok(super::fixtures::make_codec(spec)?.0),
            })
            .collect::<R<Vec<_>>>()?,
    };
    let db = if codecs.is_empty() {
        utopia_database::Database::new(adapter, cache)
    } else {
        utopia_database::Database::with_filters(adapter, cache, codecs)?
    };
    Ok(Obj::object(DatabaseObj(db)))
}

/// A `$value` tag of a class [`super::values::decode`] does not know.
pub fn decode_value(class: &str, fields: &Json) -> R<Obj> {
    if let Some(obj) = model::decode_value(class, fields)? {
        return Ok(obj);
    }
    if let Some(obj) = services::decode_value(class, fields)? {
        return Ok(obj);
    }
    if let Some(obj) = query::decode_value(class, fields)? {
        return Ok(obj);
    }
    fault(format!("no value class `{class}`"))
}

/// A `State\Snapshot` on the wire.
pub fn snapshot(s: &utopia_database::state::Snapshot) -> Json {
    services::snapshot(s)
}

/// A raw statement from a stored statement or builder.
pub fn statement_of(obj: &Obj) -> R<utopia_query::builder::Statement> {
    services::statement_of(obj)
}

/// A `Query\Builder\Statement` from its fields.
pub fn decode_statement(cx: &super::ctx::Ctx, fields: &Json) -> R<utopia_query::builder::Statement> {
    services::decode_statement(cx, fields)
}
