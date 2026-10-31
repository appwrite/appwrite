//! Statement classifiers: whether SQL (or a MongoDB command) reads, writes
//! or controls a transaction.
//!
//! PHP: `Utopia\Query\Classifier`, `Utopia\Query\Classifier\*`.
//! The `classifier` area of the query adapter (see [`super::engine`]).

use utopia_query::Type;
use utopia_query::Value;
use utopia_query::classifier::{Classifier, MongoDb, MySql, PostgreSql, Sql};

use super::engine::{Args, Failure, Object, Outcome, Ret, fault};
use crate::query_object_boilerplate;

/// `new Class(...)` for the area's classes.
pub(crate) fn construct(class: &str, _args: &mut Args) -> Option<Outcome> {
    match class {
        "Classifier\\MySQL" => Some(Ok(Ret::obj(MySql))),
        "Classifier\\PostgreSQL" => Some(Ok(Ret::obj(PostgreSql))),
        "Classifier\\MongoDB" => Some(Ok(Ret::obj(MongoDb))),
        _ => None,
    }
}

/// `Class::method(...)` for the area's classes.
pub(crate) fn call_static(_class: &str, _method: &str, _args: &mut Args) -> Option<Outcome> {
    None
}

/// `Class::CONSTANT` for the area's classes.
pub(crate) fn constant(_class: &str, _name: &str) -> Option<Ret> {
    None
}

/// `Class::Case->method(...)` (and `->value` as method `->value`) for the area's enums.
pub(crate) fn enum_call(_class: &str, _case: &str, _method: &str, _args: &mut Args) -> Option<Outcome> {
    None
}

/// A `string` argument as raw bytes (wire messages are binary).
fn bytes(a: &Args, index: usize, name: &str) -> Result<Vec<u8>, Failure> {
    match a.value(index, name)? {
        Value::Str(s) => Ok(s.as_bytes().to_vec()),
        _ => Err(fault(format!("{name} is not a string"))),
    }
}

fn kind(t: Type) -> Ret {
    Ret::Enum("Type".into(), t.name())
}

/// The calls of a classifier; SQL ones also answer the `SQL` methods.
fn call<C: Classifier>(classifier: &C, sql: Option<&dyn Sql>, method: &str, a: &mut Args) -> Option<Outcome> {
    Some(match (method, sql) {
        ("classify", _) => bytes(a, 0, "data").map(|d| kind(classifier.classify(&d))),
        ("classifySQL", Some(s)) => bytes(a, 0, "query").map(|q| kind(s.classify_sql(&q))),
        ("extractKeyword", Some(s)) => bytes(a, 0, "query").map(|q| Ret::str(s.extract_keyword(&q))),
        _ => return None,
    })
}

const SQL_PARENTS: &[&str] = &["Classifier\\SQL"];
const INTERFACES: &[&str] = &["Classifier"];

impl Object for MySql {
    fn class(&self) -> String {
        "Classifier\\MySQL".into()
    }
    fn call(&mut self, method: &str, args: &mut Args) -> Option<Outcome> {
        call(self, Some(self), method, args)
    }
    fn parents(&self) -> &'static [&'static str] {
        SQL_PARENTS
    }
    fn interfaces(&self) -> &'static [&'static str] {
        INTERFACES
    }
    query_object_boilerplate!();
}

impl Object for PostgreSql {
    fn class(&self) -> String {
        "Classifier\\PostgreSQL".into()
    }
    fn call(&mut self, method: &str, args: &mut Args) -> Option<Outcome> {
        call(self, Some(self), method, args)
    }
    fn parents(&self) -> &'static [&'static str] {
        SQL_PARENTS
    }
    fn interfaces(&self) -> &'static [&'static str] {
        INTERFACES
    }
    query_object_boilerplate!();
}

impl Object for MongoDb {
    fn class(&self) -> String {
        "Classifier\\MongoDB".into()
    }
    fn call(&mut self, method: &str, args: &mut Args) -> Option<Outcome> {
        call(self, None, method, args)
    }
    fn interfaces(&self) -> &'static [&'static str] {
        INTERFACES
    }
    query_object_boilerplate!();
}
