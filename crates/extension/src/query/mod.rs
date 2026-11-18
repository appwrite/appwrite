//! `Utopia\Query` over [`utopia_query`], one part of the package at a time. What is here
//! so far: the statement classifiers, the tokenizers, `Query` (in `value`), the AST's parser
//! and serializers, and the schemas. Every other class of the package is still its PHP source.

pub mod ast;
pub mod builder;
pub mod classifier;
pub mod schema;
pub mod tokenizer;
pub mod value;

use ext_php_rs::convert::{FromZval, IntoZval, StubLiteral};
use ext_php_rs::exception::PhpException;
use ext_php_rs::flags::DataType;
use ext_php_rs::types::Zval;
use ext_php_rs::zend::{ClassEntry, ce};
use utopia_query::StringLiteral;

use crate::emails::{construct, string};

/// `error` as the exception the PHP package throws for it, its message byte for byte.
/// The exception classes are the package's own PHP.
pub fn exception(error: &utopia_query::Error) -> PhpException {
    let class = ClassEntry::try_find(error.php_class()).unwrap_or_else(ce::exception);
    match construct(class, &mut [string(&error.message_bytes())])
        .and_then(|object| object.into_zval(false).map_err(PhpException::from))
    {
        Ok(object) => PhpException::new(error.to_string(), 0, class).with_object(object),
        Err(failed) => failed,
    }
}

/// A `Utopia\Query\StringLiteral` argument: the enum is the package's own PHP, and a case
/// object keeps its name in its first slot.
pub struct Rules(pub StringLiteral);

impl<'a> FromZval<'a> for Rules {
    const TYPE: DataType = DataType::object("Utopia\\Query\\StringLiteral");

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let object = zval.object().filter(|object| {
            ClassEntry::try_find("Utopia\\Query\\StringLiteral").is_some_and(|class| object.instance_of(class))
        })?;
        let name = ast::slots(object).first()?.str()?;
        StringLiteral::CASES.iter().find(|(_, case)| *case == name).map(|(rules, _)| Rules(*rules))
    }
}

impl StubLiteral for Rules {
    fn stub_literal(&self) -> String {
        let name = StringLiteral::CASES.iter().find(|(rules, _)| *rules == self.0).map_or("Backslash", |found| found.1);
        format!("\\Utopia\\Query\\StringLiteral::{name}")
    }
}
