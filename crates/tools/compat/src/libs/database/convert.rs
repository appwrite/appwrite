//! Rust results as [`Obj`]s: what each Rust return type is in PHP.

use std::sync::Arc;

use utopia_database::collection::Collection;
use utopia_database::document::Document;
use utopia_database::query::Query;
use utopia_database::value::{Array, Str, Value};

use super::args::collection_value;
use super::ctx::{Fail, R};
use super::obj::{Obj, PhpObject};

/// A Rust value as the PHP value it stands for.
pub trait IntoObj {
    /// The object.
    fn into_obj(self) -> Obj;
}

impl IntoObj for Obj {
    fn into_obj(self) -> Obj {
        self
    }
}

impl IntoObj for () {
    fn into_obj(self) -> Obj {
        Obj::null()
    }
}

impl IntoObj for bool {
    fn into_obj(self) -> Obj {
        Obj::Value(Value::Bool(self))
    }
}

impl IntoObj for i64 {
    fn into_obj(self) -> Obj {
        Obj::Value(Value::Int(self))
    }
}

impl IntoObj for i32 {
    fn into_obj(self) -> Obj {
        Obj::Value(Value::Int(self as i64))
    }
}

impl IntoObj for usize {
    fn into_obj(self) -> Obj {
        Obj::Value(Value::Int(self as i64))
    }
}

impl IntoObj for f64 {
    fn into_obj(self) -> Obj {
        Obj::Value(Value::Float(self))
    }
}

impl IntoObj for String {
    fn into_obj(self) -> Obj {
        Obj::Value(Value::Str(Str::from(self)))
    }
}

impl IntoObj for &str {
    fn into_obj(self) -> Obj {
        Obj::Value(Value::Str(Str::from(self)))
    }
}

impl IntoObj for Value {
    fn into_obj(self) -> Obj {
        Obj::Value(self)
    }
}

impl IntoObj for Array {
    fn into_obj(self) -> Obj {
        Obj::Value(Value::Array(self))
    }
}

impl IntoObj for Document {
    fn into_obj(self) -> Obj {
        Obj::Value(Value::from(self))
    }
}

impl IntoObj for Collection {
    fn into_obj(self) -> Obj {
        Obj::Value(collection_value(&self))
    }
}

impl IntoObj for Query {
    fn into_obj(self) -> Obj {
        Obj::Value(Value::from(self))
    }
}

impl IntoObj for utopia_query::Query {
    fn into_obj(self) -> Obj {
        Obj::Value(Value::Ext(utopia_database::value::Ext::Query(Box::new(self))))
    }
}

impl<T: IntoObj> IntoObj for Option<T> {
    fn into_obj(self) -> Obj {
        match self {
            Some(t) => t.into_obj(),
            None => Obj::null(),
        }
    }
}

impl<T: IntoObj> IntoObj for Vec<T> {
    fn into_obj(self) -> Obj {
        let items: Vec<Obj> = self.into_iter().map(IntoObj::into_obj).collect();
        // A list of values is a value (an array); of objects, a list.
        if items.iter().all(|o| matches!(o, Obj::Value(_))) {
            Obj::Value(Value::Array(
                items
                    .into_iter()
                    .map(|o| match o {
                        Obj::Value(v) => v,
                        _ => Value::Null,
                    })
                    .collect(),
            ))
        } else {
            Obj::List(items)
        }
    }
}

/// An object of the library as an [`Obj`].
pub fn object<T: PhpObject>(t: T) -> Obj {
    Obj::object(t)
}

/// Objects of the library as a list.
pub fn objects<T: PhpObject>(items: Vec<T>) -> Obj {
    Obj::List(items.into_iter().map(Obj::object).collect())
}

/// A library result as an object.
pub fn ok<T: IntoObj>(r: utopia_database::Result<T>) -> R<Obj> {
    r.map(IntoObj::into_obj).map_err(Fail::Error)
}

/// Strings as a PHP list.
pub fn strings<S: AsRef<str>>(items: &[S]) -> Obj {
    Obj::Value(Value::Array(items.iter().map(|s| Value::Str(Str::from(s.as_ref()))).collect()))
}

/// An `Arc` service held as an object: the same instance for every copy.
pub fn shared<T: ?Sized + Send + Sync + 'static>(t: Arc<T>) -> Arc<T> {
    t
}
