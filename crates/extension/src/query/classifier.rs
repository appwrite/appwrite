//! The statement classifiers (`Classifier`, `Classifier\SQL`, `Classifier\MySQL`,
//! `Classifier\PostgreSQL`, `Classifier\MongoDB`) and the `Type` they return.

use std::cell::Cell;
use std::sync::OnceLock;

use ext_php_rs::class::RegisteredClass;
use ext_php_rs::convert::IntoZval;
use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendObject, Zval};
use ext_php_rs::zend::ExecuteData;
use utopia_query::classifier::{Classifier as _, MongoDb, MySql, PostgreSql, Sql as _};

use crate::classes::leaf::FunctionHandler;
use crate::classes::{engine, retarget};
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

thread_local! {
    /// The `Type` case objects this request, by case: a case is one object for the request.
    static CASES: [Cell<*mut ZendObject>; 6] = const { [const { Cell::new(std::ptr::null_mut()) }; 6] };
}

/// Each class's own `classify()` handler, for the calls the ones below hand back: ext-php-rs's,
/// the same functions of this library whichever MINIT registered the classes.
static ORIGINAL: OnceLock<[Option<retarget::Original>; 3]> = OnceLock::new();

/// Forgets the cases at the end of a request, when PHP frees them.
pub fn request_shutdown() {
    CASES.with(|cases| cases.iter().for_each(|case| case.set(std::ptr::null_mut())));
}

/// Points each protocol's `classify()` at a handler that takes a string straight from the call
/// and returns the case it already holds, without ext-php-rs's per-call `setjmp` and enum
/// lookup. Any other call (another type, which PHP converts, or the wrong number of arguments)
/// goes to the method's own handler, so it converts and throws as before.
pub fn retarget() {
    let classes = [
        (MySqlClassifier::get_metadata().ce(), classify_mysql as FunctionHandler),
        (PostgreSqlClassifier::get_metadata().ce(), classify_postgresql),
        (MongoDbClassifier::get_metadata().ce(), classify_mongodb),
    ];
    let mut original = [None; 3];
    for (slot, (class, _)) in original.iter_mut().zip(&classes) {
        *slot = retarget::handler(class, "classify");
    }
    let original = ORIGINAL.get_or_init(|| original);
    for (i, (class, handler)) in classes.into_iter().enumerate() {
        if original[i].is_some() {
            retarget::method(class, "classify", handler);
        }
    }
}

/// The case object of `kind`, made by ext-php-rs the first time this request.
fn case(kind: Type) -> Option<Zval> {
    let index = match kind {
        Type::Read => 0,
        Type::Write => 1,
        Type::TransactionBegin => 2,
        Type::TransactionEnd => 3,
        Type::Transaction => 4,
        Type::Unknown => 5,
    };
    let mut object = CASES.with(|cases| cases[index].get());
    if object.is_null() {
        let made = kind.into_zval(false).ok()?;
        object = std::ptr::from_ref(made.object()?).cast_mut();
        CASES.with(|cases| cases[index].set(object));
    }
    let mut zval = Zval::new();
    // SAFETY: the case object lives as long as its enum does, which is past this request.
    zval.set_object(unsafe { &mut *object });
    Some(zval)
}

/// `classify($data)` with one string, which is how it is called; anything else to `original`.
fn classify(ex: &mut ExecuteData, retval: &mut Zval, original: usize, kind: impl FnOnce(&[u8]) -> utopia_query::Type) {
    let data = (engine::args_count(ex) == 1).then(|| engine::arg(ex, 0)).flatten().and_then(|data| data.zend_str());
    if let Some(data) = data
        && let Some(case) = case(kind(data.as_bytes()).into())
    {
        *retval = case;
        return;
    }
    if let Some(original) = ORIGINAL.get().and_then(|handlers| handlers[original]) {
        original.call(ex, retval);
    }
}

extern "C" fn classify_mysql(ex: &mut ExecuteData, retval: &mut Zval) {
    classify(ex, retval, 0, |data| MySql.classify(data));
}
extern "C" fn classify_postgresql(ex: &mut ExecuteData, retval: &mut Zval) {
    classify(ex, retval, 1, |data| PostgreSql.classify(data));
}
extern "C" fn classify_mongodb(ex: &mut ExecuteData, retval: &mut Zval) {
    classify(ex, retval, 2, |data| MongoDb.classify(data));
}
