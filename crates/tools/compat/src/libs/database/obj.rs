//! What an operation returns and what `as` stores: a PHP value of the
//! library, as Rust holds it ([`Obj`]), and how it crosses the wire
//! (`Core::result` and `Values::encode` of `tests/compat/database`).
//!
//! PHP passes objects around by reference and encodes them by reflection.
//! Here every object of the library a case can hold is an [`Obj`]: a plain
//! PHP value ([`Value`], which carries documents, queries and operators),
//! an enum case, a list of objects, or an object of a class the adapter
//! knows ([`PhpObject`], one impl per Rust type, see [`php_object!`]).

use std::any::Any;
use std::sync::Arc;

use serde_json::{Map, Value as Json, json};
use utopia_database::value::Value;

use super::codec;

/// A library object as a case holds it.
pub trait PhpObject: Any + Send + Sync {
    /// The class, relative to `Utopia\Database` (`Attribute`,
    /// `Validator\Structure`) or, with a `Query\` prefix, to `Utopia`
    /// (`Values::shortName`).
    fn class(&self) -> &str;

    /// The object on the wire (`Core::result`).
    fn encode(&self) -> Json;

    /// A copy (PHP `clone`; for services such as a `Database`, the same
    /// object: they are reference counted).
    fn clone_box(&self) -> Box<dyn PhpObject>;

    /// For downcasts.
    fn as_any(&self) -> &dyn Any;

    /// For downcasts.
    fn as_any_mut(&mut self) -> &mut dyn Any;
}

impl dyn PhpObject {
    /// The object as `T`, if it is one.
    pub fn downcast_ref<T: Any>(&self) -> Option<&T> {
        self.as_any().downcast_ref::<T>()
    }

    /// The object as `T`, if it is one.
    pub fn downcast_mut<T: Any>(&mut self) -> Option<&mut T> {
        self.as_any_mut().downcast_mut::<T>()
    }
}

/// Implements [`PhpObject`] for a Rust type: its PHP class and how it is
/// encoded (a closure over `&Self`).
#[macro_export]
macro_rules! php_object {
    ($ty:ty, $class:expr, |$this:ident| $encode:expr) => {
        impl $crate::libs::database::obj::PhpObject for $ty {
            fn class(&self) -> &str {
                $class
            }
            fn encode(&self) -> serde_json::Value {
                let $this = self;
                $encode
            }
            fn clone_box(&self) -> Box<dyn $crate::libs::database::obj::PhpObject> {
                Box::new(self.clone())
            }
            fn as_any(&self) -> &dyn std::any::Any {
                self
            }
            fn as_any_mut(&mut self) -> &mut dyn std::any::Any {
                self
            }
        }
    };
}

/// An enum case (`{"$enum": "<class>::<Case>"}`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct EnumCase {
    /// The class (`Query\Schema\ColumnType`, `RelationshipType`).
    pub class: String,
    /// The case name.
    pub case: String,
}

/// A library value: what an operation returns, what `as` stores and what
/// `{"$obj": <name>}` names.
pub enum Obj {
    /// A PHP value: scalars, arrays, `stdClass`, documents, queries,
    /// operators, BSON values.
    Value(Value),
    /// An enum case.
    Enum(EnumCase),
    /// A PHP list of objects (`array<Attribute>`, ...).
    List(Vec<Obj>),
    /// A PHP array with string keys holding objects.
    Map(Vec<(String, Obj)>),
    /// An object of the library.
    Object(Box<dyn PhpObject>),
    /// A result with the calls its callbacks received (`Called`).
    Called(Box<Obj>, Json),
}

impl Clone for Obj {
    fn clone(&self) -> Self {
        match self {
            Obj::Value(v) => Obj::Value(v.clone()),
            Obj::Enum(e) => Obj::Enum(e.clone()),
            Obj::List(l) => Obj::List(l.clone()),
            Obj::Map(m) => Obj::Map(m.clone()),
            Obj::Object(o) => Obj::Object(o.clone_box()),
            Obj::Called(r, c) => Obj::Called(r.clone(), c.clone()),
        }
    }
}

impl std::fmt::Debug for Obj {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Obj::Value(v) => write!(f, "Value({v:?})"),
            Obj::Enum(e) => write!(f, "Enum({}::{})", e.class, e.case),
            Obj::List(l) => f.debug_list().entries(l).finish(),
            Obj::Map(m) => f.debug_map().entries(m.iter().map(|(k, v)| (k, v))).finish(),
            Obj::Object(o) => write!(f, "Object({})", o.class()),
            Obj::Called(r, _) => write!(f, "Called({r:?})"),
        }
    }
}

impl Obj {
    /// `null`.
    pub fn null() -> Obj {
        Obj::Value(Value::Null)
    }

    /// An object of the library.
    pub fn object<T: PhpObject>(object: T) -> Obj {
        Obj::Object(Box::new(object))
    }

    /// The object on the wire (`Core::result`).
    pub fn encode(&self) -> Json {
        match self {
            Obj::Value(v) => codec::encode(v),
            Obj::Enum(e) => json!({"$enum": format!("{}::{}", e.class, e.case)}),
            Obj::List(l) => Json::Array(l.iter().map(Obj::encode).collect()),
            Obj::Map(m) => Json::Object(m.iter().map(|(k, v)| (k.clone(), v.encode())).collect::<Map<_, _>>()),
            Obj::Object(o) => o.encode(),
            Obj::Called(r, calls) => json!({"result": r.encode(), "calls": calls}),
        }
    }

    /// The result without the calls of its callbacks.
    pub fn into_result(self) -> Obj {
        match self {
            Obj::Called(r, _) => *r,
            other => other,
        }
    }

    /// The PHP class of an object (`None` for values, enums and lists).
    pub fn class(&self) -> Option<&str> {
        match self {
            Obj::Object(o) => Some(o.class()),
            _ => None,
        }
    }

    /// The object as `T`, if it is one.
    pub fn downcast_ref<T: Any>(&self) -> Option<&T> {
        match self {
            Obj::Object(o) => o.downcast_ref::<T>(),
            _ => None,
        }
    }

    /// The object as `T`, if it is one.
    pub fn downcast_mut<T: Any>(&mut self) -> Option<&mut T> {
        match self {
            Obj::Object(o) => o.downcast_mut::<T>(),
            _ => None,
        }
    }

    /// The value, if this is one.
    pub fn as_value(&self) -> Option<&Value> {
        match self {
            Obj::Value(v) => Some(v),
            _ => None,
        }
    }
}

impl From<Value> for Obj {
    fn from(v: Value) -> Self {
        Obj::Value(v)
    }
}

/// A marker result (`{"<tag>": true}`).
pub fn marker(tag: &str) -> Json {
    let mut o = Map::new();
    o.insert(tag.to_owned(), Json::Bool(true));
    Json::Object(o)
}

/// An `Arc` held as an object (services: the same object for every clone).
#[derive(Clone)]
pub struct Shared<T: ?Sized + Send + Sync>(pub Arc<T>);

/// A result already in its wire form (`db.log`'s entries).
#[derive(Clone)]
pub struct Raw(pub Json);
crate::php_object!(Raw, "array", |r| r.0.clone());
