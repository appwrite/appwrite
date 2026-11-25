//! The statement classifiers (`Classifier`, `Classifier\SQL`, `Classifier\MySQL`,
//! `Classifier\PostgreSQL`, `Classifier\MongoDB`) and the `Type` they return.

use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use utopia_query::classifier::{Classifier as _, MongoDb, MySql, PostgreSql, Sql as _};

use crate::emails::Text;
use crate::string::PhpString;

/// `Utopia\Query\Type`: what kind of statement a classifier found.
#[php_enum]
#[php(name = "Utopia\\Query\\Type")]
pub enum Type {
    #[php(value = "read")]
    Read,
    #[php(value = "write")]
    Write,
    #[php(value = "transaction_begin")]
    TransactionBegin,
    #[php(value = "transaction_end")]
    TransactionEnd,
    #[php(value = "transaction")]
    Transaction,
    #[php(value = "unknown")]
    Unknown,
}

impl From<utopia_query::Type> for Type {
    fn from(kind: utopia_query::Type) -> Self {
        match kind {
            utopia_query::Type::Read => Type::Read,
            utopia_query::Type::Write => Type::Write,
            utopia_query::Type::TransactionBegin => Type::TransactionBegin,
            utopia_query::Type::TransactionEnd => Type::TransactionEnd,
            utopia_query::Type::Transaction => Type::Transaction,
            utopia_query::Type::Unknown => Type::Unknown,
        }
    }
}

/// `Utopia\Query\Classifier`: classifies a raw wire protocol message. The macro reads the
/// trait to declare the interface; nothing in Rust implements or calls it.
#[php_interface]
#[php(name = "Utopia\\Query\\Classifier")]
#[allow(dead_code)]
pub trait Classifier {
    fn classify(&self, data: PhpString<'_>) -> Type;
}

/// `SQL::classifySQL()` and `SQL::extractKeyword()`, which do not depend on the protocol.
fn classify_sql(query: &[u8]) -> Type {
    PostgreSql.classify_sql(query).into()
}

fn extract_keyword(query: &[u8]) -> Vec<u8> {
    PostgreSql.extract_keyword(query)
}

/// `Utopia\Query\Classifier\SQL`: the keyword logic the SQL protocols share. A PHP subclass
/// supplies `classify()` for its own packet format.
///
/// None of these classes derives `Default`: with it, ext-php-rs 0.16.5 makes `new` of a PHP
/// subclass an object of the Rust class, so the subclass's own methods are never called.
#[php_class]
#[php(name = "Utopia\\Query\\Classifier\\SQL")]
#[php(flags = ClassFlags::Abstract)]
#[php(implements(PhpInterfaceClassifier))]
#[derive(Clone)]
pub struct Sql;

#[php_impl]
impl Sql {
    pub fn __construct() -> Self {
        Self
    }

    #[php(name = "classifySQL")]
    pub fn classify_sql(&self, query: PhpString<'_>) -> Type {
        classify_sql(query.bytes())
    }

    #[php(name = "extractKeyword")]
    pub fn extract_keyword(&self, query: PhpString<'_>) -> Text {
        Text(extract_keyword(query.bytes()))
    }
}

/// `Utopia\Query\Classifier\MySQL`: the MySQL wire protocol.
#[php_class]
#[php(name = "Utopia\\Query\\Classifier\\MySQL")]
#[php(extends(Sql))]
#[derive(Clone)]
pub struct MySqlClassifier;

#[php_impl]
impl MySqlClassifier {
    pub fn __construct() -> Self {
        Self
    }

    pub fn classify(&self, data: PhpString<'_>) -> Type {
        MySql.classify(data.bytes()).into()
    }

    /// A parent's method reads its own Rust type, so the child declares it again.
    #[php(name = "classifySQL")]
    pub fn classify_sql(&self, query: PhpString<'_>) -> Type {
        classify_sql(query.bytes())
    }

    #[php(name = "extractKeyword")]
    pub fn extract_keyword(&self, query: PhpString<'_>) -> Text {
        Text(extract_keyword(query.bytes()))
    }
}

/// `Utopia\Query\Classifier\PostgreSQL`: the PostgreSQL wire protocol.
#[php_class]
#[php(name = "Utopia\\Query\\Classifier\\PostgreSQL")]
#[php(extends(Sql))]
#[derive(Clone)]
pub struct PostgreSqlClassifier;

#[php_impl]
impl PostgreSqlClassifier {
    pub fn __construct() -> Self {
        Self
    }

    pub fn classify(&self, data: PhpString<'_>) -> Type {
        PostgreSql.classify(data.bytes()).into()
    }

    #[php(name = "classifySQL")]
    pub fn classify_sql(&self, query: PhpString<'_>) -> Type {
        classify_sql(query.bytes())
    }

    #[php(name = "extractKeyword")]
    pub fn extract_keyword(&self, query: PhpString<'_>) -> Text {
        Text(extract_keyword(query.bytes()))
    }
}

/// `Utopia\Query\Classifier\MongoDB`: the MongoDB wire protocol (`OP_MSG`).
#[php_class]
#[php(name = "Utopia\\Query\\Classifier\\MongoDB")]
#[php(implements(PhpInterfaceClassifier))]
#[derive(Clone)]
pub struct MongoDbClassifier;

#[php_impl]
impl MongoDbClassifier {
    pub fn __construct() -> Self {
        Self
    }

    pub fn classify(&self, data: PhpString<'_>) -> Type {
        MongoDb.classify(data.bytes()).into()
    }
}
