//! `Document` (and a `Collection` read as one).

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
