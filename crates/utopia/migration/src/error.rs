//! The errors a migration fails with.

use std::fmt;

use crate::exception::Exception;

/// A PHP exception code: `getCode()`, an integer except for `PDOException`,
/// whose code is the SQLSTATE string.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Code {
    /// An integer code.
    Int(i64),
    /// A string code (a SQLSTATE).
    Str(String),
}

impl Code {
    /// `(int) $e->getCode()`.
    pub fn as_int(&self) -> i64 {
        match self {
            Code::Int(i) => *i,
            Code::Str(s) => php_std::value::numeric_str(s)
                .map(|n| match n {
                    php_std::value::Number::Int(i) => i,
                    php_std::value::Number::Float(f) => f as i64,
                })
                .unwrap_or_else(|| leading_int(s)),
        }
    }

    /// `(int) $e->getCode() ?: Exception::CODE_INTERNAL`.
    pub fn or_internal(&self) -> i64 {
        match self.as_int() {
            0 => Exception::CODE_INTERNAL,
            c => c,
        }
    }
}

/// `(int) "42P01"`: the leading decimal digits (PHP's integer cast of a
/// leading-numeric string).
fn leading_int(s: &str) -> i64 {
    let b = s.trim_start_matches([' ', '\t', '\n', '\r', '\x0b', '\x0c']).as_bytes();
    let mut i = 0;
    let neg = matches!(b.first(), Some(b'-'));
    if matches!(b.first(), Some(b'-' | b'+')) {
        i = 1;
    }
    let mut n: i64 = 0;
    while i < b.len() && b[i].is_ascii_digit() {
        n = n.saturating_mul(10).saturating_add(i64::from(b[i] - b'0'));
        i += 1;
    }
    if neg { -n } else { n }
}

impl From<i64> for Code {
    fn from(i: i64) -> Self {
        Code::Int(i)
    }
}

/// Why a migration operation failed.
///
/// PHP: one variant per exception class the library throws or lets through
/// ([`Error::php_class`]); the message and code are the exception's.
#[derive(Debug, Clone, PartialEq, thiserror::Error)]
pub enum Error {
    /// A failure tied to a resource.
    ///
    /// PHP: `Utopia\Migration\Exception`.
    #[error("{}", .0.message)]
    Migration(Box<Exception>),
    /// A generic failure.
    ///
    /// PHP: `\Exception`.
    #[error("{message}")]
    Exception {
        /// The message.
        message: String,
        /// The code.
        code: Code,
        /// The exception this one wraps.
        previous: Option<Box<Error>>,
    },
    /// An invalid argument.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A value of an unexpected shape.
    ///
    /// PHP: `\UnexpectedValueException`.
    #[error("{0}")]
    UnexpectedValue(String),
    /// A wrong argument type, raised by the engine.
    ///
    /// PHP: `TypeError`.
    #[error("{0}")]
    TypeError(String),
    /// A wrong argument value, raised by the engine.
    ///
    /// PHP: `ValueError`.
    #[error("{0}")]
    ValueError(String),
    /// A streaming JSON parse error.
    ///
    /// PHP: `JsonMachine\Exception\*` (see [`JsonMachineKind`]).
    #[error("{message}")]
    JsonMachine {
        /// The exception class.
        kind: JsonMachineKind,
        /// The message.
        message: String,
    },
    /// The Appwrite API refused a request or could not be reached.
    ///
    /// PHP: `Appwrite\AppwriteException`.
    #[error("{message}")]
    Appwrite {
        /// The message.
        message: String,
        /// The HTTP status (0 when the request never got an answer).
        code: i64,
        /// The error type the API reported (`getType()`).
        error_type: String,
        /// The raw response (`getResponse()`).
        response: String,
    },
    /// A database failure.
    ///
    /// PHP: `Utopia\Database\Exception` and its subclasses.
    #[error("{0}")]
    Database(#[from] utopia_database::Error),
    /// A PostgreSQL driver failure.
    ///
    /// PHP: `PDOException`, its code the SQLSTATE.
    #[error("{message}")]
    Pdo {
        /// The message.
        message: String,
        /// The SQLSTATE.
        sqlstate: String,
    },
    /// An exception of a class this crate does not throw itself, raised by a
    /// caller's callback.
    ///
    /// PHP: the callback's exception, passed through.
    #[error("{message}")]
    Other {
        /// The PHP class.
        class: String,
        /// The message.
        message: String,
        /// The code.
        code: i64,
    },
}

/// The `JsonMachine\Exception` classes.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum JsonMachineKind {
    /// `JsonMachine\Exception\SyntaxErrorException`.
    Syntax,
    /// `JsonMachine\Exception\UnexpectedEndSyntaxErrorException`.
    UnexpectedEnd,
    /// `JsonMachine\Exception\InvalidArgumentException`.
    InvalidArgument,
}

impl Error {
    /// A generic exception (`new \Exception($message, $code)`).
    pub fn exception(message: impl Into<String>, code: i64) -> Self {
        Error::Exception { message: message.into(), code: Code::Int(code), previous: None }
    }

    /// A generic exception wrapping `previous`.
    pub fn exception_with(message: impl Into<String>, code: impl Into<Code>, previous: Error) -> Self {
        Error::Exception { message: message.into(), code: code.into(), previous: Some(Box::new(previous)) }
    }

    /// A [`Error::Migration`] error.
    pub fn migration(exception: Exception) -> Self {
        Error::Migration(Box::new(exception))
    }

    /// The PHP class of the exception.
    pub fn php_class(&self) -> &str {
        match self {
            Error::Migration(_) => "Utopia\\Migration\\Exception",
            Error::Exception { .. } => "Exception",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::UnexpectedValue(_) => "UnexpectedValueException",
            Error::TypeError(_) => "TypeError",
            Error::ValueError(_) => "ValueError",
            Error::JsonMachine { kind, .. } => match kind {
                JsonMachineKind::Syntax => "JsonMachine\\Exception\\SyntaxErrorException",
                JsonMachineKind::UnexpectedEnd => "JsonMachine\\Exception\\UnexpectedEndSyntaxErrorException",
                JsonMachineKind::InvalidArgument => "JsonMachine\\Exception\\InvalidArgumentException",
            },
            Error::Appwrite { .. } => "Appwrite\\AppwriteException",
            Error::Database(e) => e.php_class(),
            Error::Pdo { .. } => "PDOException",
            Error::Other { class, .. } => class,
        }
    }

    /// The message (`getMessage()`).
    pub fn message(&self) -> String {
        match self {
            Error::Migration(e) => e.message.clone(),
            Error::Database(e) => e.message().to_owned(),
            other => other.to_string(),
        }
    }

    /// The code (`getCode()`).
    pub fn code(&self) -> Code {
        match self {
            Error::Migration(e) => Code::Int(e.code),
            Error::Exception { code, .. } => code.clone(),
            Error::Appwrite { code, .. } => Code::Int(*code),
            Error::Database(e) => Code::Int(e.code()),
            Error::Pdo { sqlstate, .. } => Code::Str(sqlstate.clone()),
            Error::Other { code, .. } => Code::Int(*code),
            _ => Code::Int(0),
        }
    }

    /// The exception this one wraps (`getPrevious()`).
    pub fn previous(&self) -> Option<&Error> {
        match self {
            Error::Migration(e) => e.previous.as_deref(),
            Error::Exception { previous, .. } => previous.as_deref(),
            _ => None,
        }
    }

    /// Whether the exception is an instance of `class` (its class or a parent).
    pub fn is_a(&self, class: &str) -> bool {
        let own = self.php_class();
        if own == class {
            return true;
        }
        match self {
            Error::Database(e) => {
                class == "Utopia\\Database\\Exception"
                    || utopia_database::Kind::from_php_class(class).is_some_and(|k| e.is_a(k))
            }
            Error::JsonMachine { kind: JsonMachineKind::UnexpectedEnd, .. } => {
                class == "JsonMachine\\Exception\\SyntaxErrorException"
                    || class == "JsonMachine\\Exception\\JsonMachineException"
            }
            Error::JsonMachine { .. } => class == "JsonMachine\\Exception\\JsonMachineException",
            _ => false,
        }
    }
}

/// A result of this crate.
pub type Result<T, E = Error> = std::result::Result<T, E>;

impl fmt::Display for JsonMachineKind {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(match self {
            JsonMachineKind::Syntax => "syntax",
            JsonMachineKind::UnexpectedEnd => "unexpected end",
            JsonMachineKind::InvalidArgument => "invalid argument",
        })
    }
}

impl From<utopia_storage::Error> for Error {
    fn from(e: utopia_storage::Error) -> Self {
        Error::Other { class: e.php_class().to_owned(), message: e.to_string(), code: 0 }
    }
}
