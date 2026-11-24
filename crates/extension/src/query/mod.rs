//! `Utopia\Query` over [`utopia_query`], one part of the package at a time. What is here
//! so far: the statement classifiers, the tokenizers, `Query` (in `value`), the AST's parser
//! and serializers, and the schemas. Every other class of the package is still its PHP source.

pub mod ast;
pub mod builder;
pub mod classifier;
pub mod features;
pub mod schema;
pub mod tokenizer;
pub mod value;

use ext_php_rs::convert::IntoZval;
use ext_php_rs::exception::PhpException;
use ext_php_rs::zend::{ClassEntry, ce};

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
