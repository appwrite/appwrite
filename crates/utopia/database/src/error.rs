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
}

/// The kinds of [`Error`], one per PHP exception class.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Kind {
    /// `Utopia\Database\Exception`: any other failure.
    Database,
    /// `Exception\Authorization`: the roles lack a permission.
    Authorization,
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
}

impl Kind {
    /// The PHP class of errors of this kind.
    pub fn php_class(self) -> &'static str {
        match self {
            Kind::Database => "Utopia\\Database\\Exception",
            Kind::Authorization => "Utopia\\Database\\Exception\\Authorization",
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
        }
    }

    /// The kind of errors of the PHP class `class` (the inverse of
    /// [`Kind::php_class`]); `None` for a class no kind stands for.
    pub fn from_php_class(class: &str) -> Option<Kind> {
        const ALL: [Kind; 30] = [
            Kind::Database,
            Kind::Authorization,
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
        ];
        let class = class.strip_prefix('\\').unwrap_or(class);
        ALL.into_iter().find(|k| k.php_class().eq_ignore_ascii_case(class))
    }

    /// The kind this kind's PHP class extends, if it extends another
    /// database exception (every `Exception\*` extends [`Kind::Database`];
    /// `Unique` extends [`Kind::Duplicate`]).
    pub fn parent(self) -> Option<Kind> {
        match self {
            Kind::Unique => Some(Kind::Duplicate),
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
            | Kind::Redis => None,
            _ => Some(Kind::Database),
        }
    }
}

impl Error {
    /// An error of `kind` with `message` and code `0`.
    pub fn new(kind: Kind, message: impl Into<String>) -> Self {
        Self { kind, message: message.into(), code: 0, attribute: None, previous: None }
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

    /// The same error with a string code, as [`Error::string_code`] turns it
    /// into an integer.
    pub fn with_string_code(self, code: &[u8]) -> Self {
        self.with_code(Self::string_code(code))
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

    /// The message (`getMessage()`).
    pub fn message(&self) -> &str {
        &self.message
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
