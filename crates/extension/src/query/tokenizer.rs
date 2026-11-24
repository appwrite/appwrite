//! The tokenizers: `Tokenizer\Tokenizer` and its dialects `MySQL`, `MariaDB`, `PostgreSQL`,
//! `SQLite` and `ClickHouse`. The tokens they return are the package's own `Token` objects,
//! made by its PHP constructor with the cases of its `TokenType` enum.

use ext_php_rs::convert::IntoZval;
use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ce};
use utopia_query::StringLiteral;
use utopia_query::tokenizer::{TokenType, Tokenizer as Dialect};

use super::{Rules, exception};
use crate::emails::{construct, string};
use crate::string::PhpString;

const TOKEN: &str = "Utopia\\Query\\Tokenizer\\Token";
const TOKEN_TYPE: &str = "Utopia\\Query\\Tokenizer\\TokenType";

/// A class of the PHP package, loaded through the autoloader.
pub(super) fn class(name: &str) -> PhpResult<&'static ClassEntry> {
    ClassEntry::try_find(name).ok_or_else(|| PhpException::new(format!("Class \"{name}\" not found"), 0, ce::error()))
}

/// The object of the `TokenType` case `kind`.
pub(super) fn case(token_type: &ClassEntry, kind: TokenType) -> *mut ZendObject {
    let mut name = ZendStr::new(kind.name(), false);
    // SAFETY: `token_type` is the loaded enum and `kind.name()` is one of its cases, which is
    // what `zend_enum_get_case` requires; the object it returns lives as long as the class.
    unsafe { ext_php_rs::ffi::zend_enum_get_case(std::ptr::from_ref(token_type).cast_mut(), &raw mut *name) }
}

/// `$tokenizer->tokenize($sql)`: the tokens as `Token` objects.
fn tokenize(dialect: Dialect, sql: &[u8]) -> PhpResult<Vec<Zval>> {
    let tokens = dialect.tokenize(sql).map_err(|error| exception(&error))?;
    let token = class(TOKEN)?;
    let token_type = class(TOKEN_TYPE)?;
    let mut cases: [*mut ZendObject; TokenType::CASES.len()] = [std::ptr::null_mut(); TokenType::CASES.len()];
    let mut objects = Vec::with_capacity(tokens.len());
    for found in tokens {
        let slot = &mut cases[found.kind as usize];
        if slot.is_null() {
            *slot = case(token_type, found.kind);
        }
        let mut kind = Zval::new();
        // SAFETY: the pointer is a case object of the loaded enum, set just above.
        kind.set_object(unsafe { &mut **slot });
        let mut position = Zval::new();
        position.set_long(i64::try_from(found.position).unwrap_or(i64::MAX));
        let object = construct(token, &mut [kind, string(&found.value), position])?;
        objects.push(object.into_zval(false).map_err(PhpException::from)?);
    }
    Ok(objects)
}

/// `Tokenizer::filter($tokens)`: the tokens that are not whitespace or comments.
fn filter(tokens: &ZendHashTable) -> PhpResult<Vec<Zval>> {
    let token_type = class(TOKEN_TYPE)?;
    let trivia = [TokenType::Whitespace, TokenType::LineComment, TokenType::BlockComment]
        .map(|kind| case(token_type, kind).cast_const());
    let mut kept = Vec::with_capacity(tokens.len());
    for token in tokens.values() {
        let kind = token
            .object()
            .and_then(|object| object.get_properties().ok())
            .and_then(|properties| properties.get("type"))
            .and_then(|kind| kind.indirect().unwrap_or(kind).object())
            .map(std::ptr::from_ref);
        if !kind.is_some_and(|kind| trivia.contains(&kind)) {
            kept.push(token.shallow_clone());
        }
    }
    Ok(kept)
}

/// The methods every tokenizer class declares: a parent's method reads its own Rust type,
/// so each class has its own.
macro_rules! tokenizer_methods {
    ($class:ident, $dialect:expr, $rules:literal, $quote:literal) => {
        #[php_impl]
        impl $class {
            #[php(defaults(literal = $rules))]
            pub fn __construct(literal: Rules) -> Self {
                Self(literal.0)
            }

            pub fn tokenize(&self, sql: PhpString<'_>) -> PhpResult<Vec<Zval>> {
                tokenize($dialect.with_literal(self.0), sql.bytes())
            }

            pub fn filter(tokens: &ZendHashTable) -> PhpResult<Vec<Zval>> {
                filter(tokens)
            }

            #[php(name = "getIdentifierQuoteChar", vis = "protected")]
            pub fn get_identifier_quote_char(&self) -> &'static str {
                $quote
            }
        }
    };
}

#[php_class]
#[php(name = "Utopia\\Query\\Tokenizer\\Tokenizer")]
#[derive(Clone)]
pub struct Tokenizer(StringLiteral);
tokenizer_methods!(Tokenizer, Dialect::new(), "Rules(StringLiteral::Backslash)", "`");

/// `Utopia\Query\Tokenizer\MySQL`: also reads `#` line comments.
#[php_class]
#[php(name = "Utopia\\Query\\Tokenizer\\MySQL")]
#[php(extends(Tokenizer))]
#[derive(Clone)]
pub struct MySql(StringLiteral);
tokenizer_methods!(MySql, Dialect::mysql(), "Rules(StringLiteral::Backslash)", "`");

/// `Utopia\Query\Tokenizer\MariaDB`: as MySQL.
#[php_class]
#[php(name = "Utopia\\Query\\Tokenizer\\MariaDB")]
#[php(extends(MySql))]
#[derive(Clone)]
pub struct MariaDb(StringLiteral);
tokenizer_methods!(MariaDb, Dialect::mariadb(), "Rules(StringLiteral::Backslash)", "`");

/// `Utopia\Query\Tokenizer\PostgreSQL`: double-quoted identifiers and multi-character operators.
#[php_class]
#[php(name = "Utopia\\Query\\Tokenizer\\PostgreSQL")]
#[php(extends(Tokenizer))]
#[derive(Clone)]
pub struct PostgreSql(StringLiteral);
tokenizer_methods!(PostgreSql, Dialect::postgresql(), "Rules(StringLiteral::Extended)", "\"");

/// `Utopia\Query\Tokenizer\SQLite`: double-quoted identifiers.
#[php_class]
#[php(name = "Utopia\\Query\\Tokenizer\\SQLite")]
#[php(extends(Tokenizer))]
#[derive(Clone)]
pub struct Sqlite(StringLiteral);
tokenizer_methods!(Sqlite, Dialect::sqlite(), "Rules(StringLiteral::Standard)", "`");

/// `Utopia\Query\Tokenizer\ClickHouse`: as the standard tokenizer.
#[php_class]
#[php(name = "Utopia\\Query\\Tokenizer\\ClickHouse")]
#[php(extends(Tokenizer))]
#[derive(Clone)]
pub struct ClickHouse(StringLiteral);
tokenizer_methods!(ClickHouse, Dialect::clickhouse(), "Rules(StringLiteral::BackslashUtf8)", "`");
