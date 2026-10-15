//! The model classes: `Attribute`, `Index`, `Relationship`, `Collection`, the update models, `Format`, `Change`, `Permission`, `Role`, `Id`, `DateTime`, `Storage`, `Connection`, the exceptions, the events, the enums.

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

/// A public property of an object of this module (`object.get`), for
/// objects whose wire form does not list it.
pub fn property(this: &Obj, property: &str) -> R<Option<Obj>> {
    let _ = (this, property);
    Ok(None)
}

/// A `$value` tag of a class of this module.
pub fn decode_value(class: &str, fields: &serde_json::Value) -> R<Option<Obj>> {
    let _ = (class, fields);
    Ok(None)
}
