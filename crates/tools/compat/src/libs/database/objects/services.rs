//! `Validator\Authorization` and its `Input`, `Profiler`, `Cache\*`, `Filter\*`, `Hook\*`, `State\*`, `Builder\*`, `Query\Builder\*`, `Adapter\SQL\Hook\*`, `Adapter\Limits`, `Adapter\Profile`.

use crate::libs::database::args::Args;
use crate::libs::database::ctx::R;
use crate::libs::database::obj::Obj;

/// `new <class>(...)` for the classes of this module (`None`: not one).
pub async fn construct(class: &str, a: &Args) -> R<Option<Obj>> {
    let _ = (class, a);
    Ok(None)
}

/// `<class>::<method>(...)` for the classes of this module.
pub async fn call_static(class: &str, method: &str, a: &Args) -> R<Option<Obj>> {
    let _ = (class, method, a);
    Ok(None)
}

/// `$this-><method>(...)` for the objects of this module.
pub async fn invoke(this: &mut Obj, method: &str, a: &Args) -> R<Option<Obj>> {
    let _ = (this, method, a);
    Ok(None)
}

/// A `$value` tag of a class of this module.
pub fn decode_value(class: &str, fields: &serde_json::Value) -> R<Option<Obj>> {
    let _ = (class, fields);
    Ok(None)
}

/// A `State\Snapshot` on the wire.
pub fn snapshot(s: &utopia_database::state::Snapshot) -> serde_json::Value {
    let _ = s;
    serde_json::json!({"$value": "State\\Snapshot", "fields": {}})
}

/// A raw statement from a stored statement or builder.
pub fn statement_of(obj: &Obj) -> R<utopia_query::builder::Statement> {
    crate::libs::database::ctx::fault(format!("not a statement: {obj:?}"))
}

/// A `Query\Builder\Statement` from its fields.
pub fn decode_statement(
    cx: &crate::libs::database::ctx::Ctx,
    fields: &serde_json::Value,
) -> R<utopia_query::builder::Statement> {
    let _ = cx;
    crate::libs::database::ctx::fault(format!("statement: {fields}"))
}
