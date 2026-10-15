//! The errors database operations fail with.

use std::fmt;

/// What a database operation failed with: a kind (one per PHP exception
/// class), a message, and the integer code PHP's exception carries.
///
/// PHP: `Utopia\Database\Exception` and its subclasses in
/// `Utopia\Database\Exception\*`, plus the driver and engine exceptions that
/// escape the library unmapped (`PDOException`, `TypeError`, ...). The kind is
/// the class ([`Error::php_class`]); `instanceof` is [`Error::is_a`] (a
/// [`Kind::Unique`] error is also a [`Kind::Duplicate`] one, as `Unique`
/// extends `Duplicate`).
#[derive(Debug, Clone, PartialEq)]
pub struct Error {
    kind: Kind,
    message: String,
    code: i64,
    /// The attribute an [`Kind::Order`] error is about (`getAttribute()`).
    attribute: Option<String>,
    /// The exception this one wraps (`getPrevious()`).
    previous: Option<Box<Error>>,
    /// The message as PHP has it, when it is not UTF-8 (`message` is then
    /// its lossy form).
    raw: Option<Vec<u8>>,
    /// The string code (a SQLSTATE such as `HY000`) the error was built
    /// with (`$state`).
    state: Option<String>,
}

/// The kinds of [`Error`], one per PHP exception class.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Kind {
    /// `Utopia\Database\Exception`: any other failure.
    Database,
    /// `Exception\Authorization`: the roles lack a permission.
    Authorization,
    /// `Exception\Schema`: a schema definition or a document breaks the rules
    /// of its collection's schema (the parent of [`Kind::Structure`],
    /// [`Kind::Type`], [`Kind::Character`], [`Kind::Truncate`],
    /// [`Kind::Index`], [`Kind::Dependency`], [`Kind::Limit`] and
    /// [`Kind::Relationship`]).
    Schema,
    /// `Exception\Contention`: the engine aborted a statement over a lock
    /// conflict (a [`Kind::Transaction`]).
    Contention,
    /// `Exception\Mismatch`: a resource exists with another definition (a
    /// [`Kind::Duplicate`]).
    Mismatch,
    /// `Exception\Refused`: an adapter reported, without an error, that it
    /// did not apply a schema change.
    Refused,
    /// `Exception\Unconfirmed`: a commit could not be confirmed, so its
    /// writes may or may not be stored.
    Unconfirmed,
    /// `Exception\Character`: a value has characters the adapter cannot store.
    Character,
    /// `Exception\Conflict`: the document changed after the request started.
    Conflict,
    /// `Exception\Dependency`: an attribute is used by an index.
    Dependency,
    /// `Exception\Duplicate`: a collection, attribute, index or document exists.
    Duplicate,
    /// `Exception\Index`: an invalid index.
    Index,
    /// `Exception\Limit`: a limit (attributes, indexes, size, bounds) is reached.
    Limit,
    /// `Exception\NotFound`: a collection, attribute, index or document is missing.
    NotFound,
    /// `Exception\Operator`: an operator cannot apply.
    Operator,
    /// `Exception\Order`: an order or cursor attribute is unusable.
    Order,
    /// `Exception\Query`: an invalid query.
    Query,
    /// `Exception\Relationship`: an invalid relationship operation.
    Relationship,
    /// `Exception\Restricted`: an `onDelete: restrict` relationship blocks a delete.
    Restricted,
    /// `Exception\Structure`: a document does not match its collection.
    Structure,
    /// `Exception\Timeout`: the statement exceeded the configured timeout.
    Timeout,
    /// `Exception\Transaction`: a transaction could not start, commit or roll back.
    Transaction,
    /// `Exception\Truncate`: a value is longer than its column.
    Truncate,
    /// `Exception\Type`: a value of the wrong type for its column.
    Type,
    /// `Exception\Unique`: a unique index is violated (a [`Kind::Duplicate`]).
    Unique,
    /// `PDOException`: a driver error the adapter does not map.
    Pdo,
    /// `TypeError`, raised by the engine on a wrong argument type.
    TypeError,
    /// `ValueError`, raised by the engine on a wrong argument value.
    ValueError,
    /// `JsonException`.
    Json,
    /// `DivisionByZeroError`, raised by the engine on a division or modulo by zero.
    DivisionByZero,
    /// `Exception`: PHP's base exception, thrown by library code with `new \Exception`.
    Exception,
    /// `DateMalformedIntervalStringException`: `DateInterval` rejected an
    /// interval string (`DateTime::addSeconds` with `PHP_INT_MIN` seconds).
    DateMalformedIntervalString,
    /// `Error`: an engine error (using an object as an array, a string
    /// offset that cannot be assigned or unset, ...).
    Error,
    /// `RuntimeException`: raised by a caller's callback (a transaction's
    /// body, an `onNext`) and passed through.
    Runtime,
    /// `RedisException`: a Redis command of the Redis adapter failed outside
    /// a transaction wrapper (phpredis's exception, passed through).
    Redis,
    /// `Utopia\Mongo\Exception`: a MongoDB failure the Mongo adapter does
    /// not map (passed through from utopia-php/mongo).
    Mongo,
    /// `Utopia\Mongo\UnsentException`: a MongoDB command that never reached
    /// the server (passed through from utopia-php/mongo).
    MongoUnsent,
    /// `MongoDB\Driver\Exception\UnexpectedValueException`: PHP's MongoDB
    /// extension refused to encode a command (a key holding a null byte, a
    /// string that is not UTF-8).
    MongoUnexpectedValue,
    /// `DateMalformedStringException`: `new DateTime($value)` rejected a
    /// date string.
    DateMalformedString,
    /// `InvalidArgumentException`: an argument PHP's SPL refuses (the
    /// integer arithmetic of [`crate::validator::big_int::BigInt`]).
    InvalidArgument,
}

impl Kind {
    /// The PHP class of errors of this kind.
    pub fn php_class(self) -> &'static str {
        match self {
            Kind::Database => "Utopia\\Database\\Exception",
            Kind::Authorization => "Utopia\\Database\\Exception\\Authorization",
            Kind::Schema => "Utopia\\Database\\Exception\\Schema",
            Kind::Contention => "Utopia\\Database\\Exception\\Contention",
            Kind::Mismatch => "Utopia\\Database\\Exception\\Mismatch",
            Kind::Refused => "Utopia\\Database\\Exception\\Refused",
            Kind::Unconfirmed => "Utopia\\Database\\Exception\\Unconfirmed",
            Kind::Character => "Utopia\\Database\\Exception\\Character",
            Kind::Conflict => "Utopia\\Database\\Exception\\Conflict",
            Kind::Dependency => "Utopia\\Database\\Exception\\Dependency",
            Kind::Duplicate => "Utopia\\Database\\Exception\\Duplicate",
            Kind::Index => "Utopia\\Database\\Exception\\Index",
            Kind::Limit => "Utopia\\Database\\Exception\\Limit",
            Kind::NotFound => "Utopia\\Database\\Exception\\NotFound",
            Kind::Operator => "Utopia\\Database\\Exception\\Operator",
            Kind::Order => "Utopia\\Database\\Exception\\Order",
            Kind::Query => "Utopia\\Database\\Exception\\Query",
            Kind::Relationship => "Utopia\\Database\\Exception\\Relationship",
            Kind::Restricted => "Utopia\\Database\\Exception\\Restricted",
            Kind::Structure => "Utopia\\Database\\Exception\\Structure",
            Kind::Timeout => "Utopia\\Database\\Exception\\Timeout",
            Kind::Transaction => "Utopia\\Database\\Exception\\Transaction",
            Kind::Truncate => "Utopia\\Database\\Exception\\Truncate",
            Kind::Type => "Utopia\\Database\\Exception\\Type",
            Kind::Unique => "Utopia\\Database\\Exception\\Unique",
            Kind::Pdo => "PDOException",
            Kind::TypeError => "TypeError",
            Kind::ValueError => "ValueError",
            Kind::Json => "JsonException",
            Kind::DivisionByZero => "DivisionByZeroError",
            Kind::Exception => "Exception",
            Kind::DateMalformedIntervalString => "DateMalformedIntervalStringException",
            Kind::Error => "Error",
            Kind::Runtime => "RuntimeException",
            Kind::Redis => "RedisException",
            Kind::Mongo => "Utopia\\Mongo\\Exception",
            Kind::MongoUnsent => "Utopia\\Mongo\\UnsentException",
            Kind::MongoUnexpectedValue => "MongoDB\\Driver\\Exception\\UnexpectedValueException",
            Kind::DateMalformedString => "DateMalformedStringException",
            Kind::InvalidArgument => "InvalidArgumentException",
        }
    }

    /// The kind of errors of the PHP class `class` (the inverse of
    /// [`Kind::php_class`]); `None` for a class no kind stands for.
    pub fn from_php_class(class: &str) -> Option<Kind> {
        const ALL: [Kind; 40] = [
            Kind::Database,
            Kind::Authorization,
            Kind::Schema,
            Kind::Contention,
            Kind::Mismatch,
            Kind::Refused,
            Kind::Unconfirmed,
            Kind::Character,
            Kind::Conflict,
            Kind::Dependency,
            Kind::Duplicate,
            Kind::Index,
            Kind::Limit,
            Kind::NotFound,
            Kind::Operator,
            Kind::Order,
            Kind::Query,
            Kind::Relationship,
            Kind::Restricted,
            Kind::Structure,
            Kind::Timeout,
            Kind::Transaction,
            Kind::Truncate,
            Kind::Type,
            Kind::Unique,
            Kind::Pdo,
            Kind::TypeError,
            Kind::ValueError,
            Kind::Json,
            Kind::DivisionByZero,
            Kind::Exception,
            Kind::DateMalformedIntervalString,
            Kind::Error,
            Kind::Runtime,
            Kind::Redis,
            Kind::Mongo,
            Kind::MongoUnsent,
            Kind::MongoUnexpectedValue,
            Kind::DateMalformedString,
            Kind::InvalidArgument,
        ];
        let class = class.strip_prefix('\\').unwrap_or(class);
        ALL.into_iter().find(|k| k.php_class().eq_ignore_ascii_case(class))
    }

    /// The kind this kind's PHP class extends, if it extends another
    /// database exception: `Unique` and `Mismatch` extend
    /// [`Kind::Duplicate`], `Contention` extends [`Kind::Transaction`],
    /// `Order` and `Operator` extend [`Kind::Query`], the schema violations
    /// extend [`Kind::Schema`], and every other `Exception\*` extends
    /// [`Kind::Database`].
    pub fn parent(self) -> Option<Kind> {
        match self {
            Kind::Unique | Kind::Mismatch => Some(Kind::Duplicate),
            Kind::Contention => Some(Kind::Transaction),
            Kind::Order | Kind::Operator => Some(Kind::Query),
            Kind::Structure
            | Kind::Type
            | Kind::Character
            | Kind::Truncate
            | Kind::Index
            | Kind::Dependency
            | Kind::Limit
            | Kind::Relationship => Some(Kind::Schema),
            Kind::Database
            | Kind::Pdo
            | Kind::TypeError
            | Kind::ValueError
            | Kind::Json
            | Kind::DivisionByZero
            | Kind::Exception
            | Kind::DateMalformedIntervalString
            | Kind::Error
            | Kind::Runtime
            | Kind::Redis
            | Kind::Mongo
            | Kind::MongoUnexpectedValue
            | Kind::DateMalformedString
            | Kind::InvalidArgument => None,
            Kind::MongoUnsent => Some(Kind::Mongo),
            _ => Some(Kind::Database),
        }
    }
}

impl Error {
    /// An error of `kind` with `message` and code `0`.
    pub fn new(kind: Kind, message: impl Into<String>) -> Self {
        Self { kind, message: message.into(), code: 0, attribute: None, previous: None, raw: None, state: None }
    }

    /// An error of `kind` whose message is bytes (PHP messages are: one can
    /// quote a string that is not UTF-8).
    pub fn from_bytes(kind: Kind, message: Vec<u8>) -> Self {
        match String::from_utf8(message) {
            Ok(message) => Self::new(kind, message),
            Err(e) => {
                let raw = e.into_bytes();
                Self { raw: Some(raw.clone()), ..Self::new(kind, String::from_utf8_lossy(&raw).into_owned()) }
            }
        }
    }

    /// The same error with a code. PHP: `Utopia\Database\Exception` takes an
    /// `int|string` code and turns a non-numeric string into `0`; pass the
    /// integer it ends up as.
    pub fn with_code(mut self, code: i64) -> Self {
        self.code = code;
        self
    }

    /// The integer a string code becomes (`Utopia\Database\Exception`'s
    /// constructor): a numeric string is cast to an integer (`"12"` is 12,
    /// `" 1.9"` is 1, `"1e3"` is 1000, beyond `i64` it saturates), anything
    /// else is 0.
    ///
    /// ```
    /// use utopia_database::Error;
    ///
    /// assert_eq!(Error::string_code(b"42"), 42);
    /// assert_eq!(Error::string_code(b"1.5"), 1);
    /// assert_eq!(Error::string_code(b"HY000"), 0);
    /// ```
    ///
    /// PHP: `Utopia\Database\Exception::__construct` with a string `$code`
    /// (`is_numeric($code) ? (int) $code : 0`).
    pub fn string_code(code: &[u8]) -> i64 {
        if php_std::value::numeric_bytes_ex(code).is_some() { php_std::format::str_to_int(code) } else { 0 }
    }

    /// The same error with a string code: kept as [`Error::state`], and as
    /// the integer [`Error::string_code`] turns it into.
    pub fn with_string_code(mut self, code: &[u8]) -> Self {
        self.state = Some(String::from_utf8_lossy(code).into_owned());
        self.with_code(Self::string_code(code))
    }

    /// The string code the error was built with, such as a SQLSTATE
    /// (`$state`); `None` for an integer code.
    pub fn state(&self) -> Option<&str> {
        self.state.as_deref()
    }

    /// The same error wrapping `previous`.
    pub fn with_previous(mut self, previous: Error) -> Self {
        self.previous = Some(Box::new(previous));
        self
    }

    /// An [`Kind::Order`] error about `attribute`.
    pub fn order(message: impl Into<String>, attribute: Option<String>) -> Self {
        Self { attribute, ..Self::new(Kind::Order, message) }
    }

    /// The kind (PHP class) of the error.
    pub fn kind(&self) -> Kind {
        self.kind
    }

    /// The PHP class of the error.
    pub fn php_class(&self) -> &'static str {
        self.kind.php_class()
    }

    /// Whether the error is of `kind` or of a kind extending it
    /// (`instanceof`).
    pub fn is_a(&self, kind: Kind) -> bool {
        let mut k = Some(self.kind);
        while let Some(current) = k {
            if current == kind {
                return true;
            }
            k = current.parent();
        }
        false
    }

    /// Whether the error is an engine `\Error` (`TypeError`, `ValueError`,
    /// `DivisionByZeroError`, `Error`) rather than an `\Exception`: code
    /// that catches `Exception` lets it through.
    pub fn is_engine_error(&self) -> bool {
        matches!(self.kind, Kind::TypeError | Kind::ValueError | Kind::DivisionByZero | Kind::Error)
    }

    /// The message (`getMessage()`), lossy when [`Error::message_bytes`]
    /// is not UTF-8.
    pub fn message(&self) -> &str {
        &self.message
    }

    /// The message (`getMessage()`) byte for byte.
    pub fn message_bytes(&self) -> &[u8] {
        self.raw.as_deref().unwrap_or(self.message.as_bytes())
    }

    /// The code (`getCode()`).
    pub fn code(&self) -> i64 {
        self.code
    }

    /// The attribute of an [`Kind::Order`] error (`getAttribute()`).
    pub fn attribute(&self) -> Option<&str> {
        self.attribute.as_deref()
    }

    /// The wrapped error (`getPrevious()`).
    pub fn previous(&self) -> Option<&Error> {
        self.previous.as_deref()
    }
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.message)
    }
}

impl std::error::Error for Error {
    fn source(&self) -> Option<&(dyn std::error::Error + 'static)> {
        self.previous.as_deref().map(|e| e as &(dyn std::error::Error + 'static))
    }
}

impl From<php_std::EngineError> for Error {
    fn from(e: php_std::EngineError) -> Self {
        let kind = match e.class {
            "TypeError" => Kind::TypeError,
            "ValueError" => Kind::ValueError,
            "JsonException" => Kind::Json,
            "Error" => Kind::Error,
            _ => Kind::Exception,
        };
        Error::new(kind, e.message)
    }
}

/// `Result` with [`Error`].
pub type Result<T, E = Error> = std::result::Result<T, E>;

/// The message of every unique index violation (`Exception\Unique::MESSAGE`).
pub const UNIQUE_MESSAGE: &str = "Unique index violation";

/// Shorthands for the error of each kind.
macro_rules! constructors {
    ($($name:ident => $kind:ident),* $(,)?) => {
        impl Error {
            $(
                #[doc = concat!("A [`Kind::", stringify!($kind), "`] error.")]
                pub fn $name(message: impl Into<String>) -> Self {
                    Self::new(Kind::$kind, message)
                }
            )*
        }
    };
}

constructors! {
    database => Database,
    authorization => Authorization,
    schema => Schema,
    contention => Contention,
    mismatch => Mismatch,
    refused => Refused,
    unconfirmed => Unconfirmed,
    exception => Exception,
    character => Character,
    conflict => Conflict,
    dependency => Dependency,
    duplicate => Duplicate,
    index => Index,
    limit => Limit,
    not_found => NotFound,
    operator => Operator,
    query => Query,
    relationship => Relationship,
    restricted => Restricted,
    structure => Structure,
    timeout => Timeout,
    transaction => Transaction,
    truncate => Truncate,
    type_error => Type,
    unique => Unique,
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn unique_is_a_duplicate() {
        let e = Error::unique("Unique index violation");
        assert!(e.is_a(Kind::Unique));
        assert!(e.is_a(Kind::Duplicate));
        assert!(e.is_a(Kind::Database));
        assert!(!e.is_a(Kind::NotFound));
        assert_eq!(e.php_class(), "Utopia\\Database\\Exception\\Unique");
    }
}
