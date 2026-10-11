//! Errors recorded on spans.

use std::panic::Location;

/// One call in a [`Failure`]'s stack trace, innermost first.
///
/// PHP: an entry of `$throwable->getTrace()`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Frame {
    /// The file of the call site, when known.
    pub file: Option<String>,
    /// The line of the call site, when known.
    pub line: Option<u32>,
    /// The function called.
    pub function: String,
    /// The class of the method called, for a method call.
    pub class: Option<String>,
    /// How the method was called: `->` or `::`.
    pub call: Option<String>,
}

/// An error recorded on a span: what exporters report about it.
///
/// It carries what exporters print: the error's type (`class`), message,
/// code, where it was raised, its stack trace and the error that caused it.
/// [`Failure::new`] records the caller's location; [`Failure::from_error`]
/// converts any [`std::error::Error`] with its chain of sources.
///
/// PHP: the `\Throwable` passed to `setError()` or `finish()`; `class` is
/// `$e::class`, `previous` is `getPrevious()`.
///
/// ```
/// use utopia_span::Failure;
///
/// let root = Failure::new("LogicException", "root cause");
/// let error = Failure::new("RuntimeException", "outer").code(42).previous(root);
///
/// assert_eq!(error.class(), "RuntimeException");
/// assert_eq!(error.code_value(), 42);
/// assert_eq!(error.cause().map(|c| c.message()), Some("root cause"));
/// ```
///
/// ```php
/// $root = new \LogicException('root cause');
/// $error = new \RuntimeException('outer', 42, $root);
///
/// echo $error::class, "\n";                    // RuntimeException
/// echo $error->getCode(), "\n";                // 42
/// echo $error->getPrevious()->getMessage(), "\n"; // root cause
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Failure {
    class: String,
    message: Vec<u8>,
    code: i64,
    file: String,
    line: u32,
    trace: Vec<Frame>,
    previous: Option<Box<Failure>>,
}

impl Failure {
    /// A failure of type `class` with `message`, raised where this is called,
    /// with code 0, no trace and no cause.
    ///
    /// PHP: `new $class($message)`.
    #[track_caller]
    pub fn new(class: impl Into<String>, message: impl Into<Vec<u8>>) -> Self {
        let at = Location::caller();
        Self {
            class: class.into(),
            message: message.into(),
            code: 0,
            file: at.file().to_owned(),
            line: at.line(),
            trace: Vec::new(),
            previous: None,
        }
    }

    /// A failure describing `error`: its type name, message and chain of
    /// sources (each source becomes the [`Failure::cause`] of the one before,
    /// with the type `std::error::Error`).
    ///
    /// ```
    /// use utopia_span::Failure;
    ///
    /// let error = "x".parse::<i32>().unwrap_err();
    /// let failure = Failure::from_error(&error);
    /// assert_eq!(failure.class(), "core::num::error::ParseIntError");
    /// assert_eq!(failure.message(), "invalid digit found in string");
    /// ```
    #[track_caller]
    pub fn from_error<E: std::error::Error + ?Sized>(error: &E) -> Self {
        let mut failure = Failure::new(std::any::type_name::<E>(), error.to_string());
        let mut sources = Vec::new();
        let mut source = error.source();
        while let Some(s) = source {
            sources.push(Failure::new("std::error::Error", s.to_string()));
            source = s.source();
        }
        let mut cause = None;
        for mut s in sources.into_iter().rev() {
            s.previous = cause.map(Box::new);
            cause = Some(s);
        }
        failure.previous = cause.map(Box::new);
        failure
    }

    /// Sets the error code.
    pub fn code(mut self, code: i64) -> Self {
        self.code = code;
        self
    }

    /// Sets where the error was raised.
    pub fn at(mut self, file: impl Into<String>, line: u32) -> Self {
        self.file = file.into();
        self.line = line;
        self
    }

    /// Sets the stack trace, innermost call first.
    pub fn trace(mut self, trace: Vec<Frame>) -> Self {
        self.trace = trace;
        self
    }

    /// Sets the error that caused this one.
    pub fn previous(mut self, previous: Failure) -> Self {
        self.previous = Some(Box::new(previous));
        self
    }

    /// The error's type.
    ///
    /// PHP: `$e::class`.
    pub fn class(&self) -> &str {
        &self.class
    }

    /// The message, when it is UTF-8 (the empty string otherwise; see
    /// [`Failure::message_bytes`]).
    ///
    /// PHP: `getMessage()`.
    pub fn message(&self) -> &str {
        std::str::from_utf8(&self.message).unwrap_or_default()
    }

    /// The message, as bytes.
    pub fn message_bytes(&self) -> &[u8] {
        &self.message
    }

    /// The error code.
    ///
    /// PHP: `getCode()`.
    pub fn code_value(&self) -> i64 {
        self.code
    }

    /// The file the error was raised in.
    ///
    /// PHP: `getFile()`.
    pub fn file(&self) -> &str {
        &self.file
    }

    /// The line the error was raised at.
    ///
    /// PHP: `getLine()`.
    pub fn line(&self) -> u32 {
        self.line
    }

    /// The stack trace, innermost call first.
    ///
    /// PHP: `getTrace()`.
    pub fn frames(&self) -> &[Frame] {
        &self.trace
    }

    /// The error that caused this one.
    ///
    /// PHP: `getPrevious()`.
    pub fn cause(&self) -> Option<&Failure> {
        self.previous.as_deref()
    }
}

impl std::fmt::Display for Failure {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "{}: {}", self.class, String::from_utf8_lossy(&self.message))
    }
}

impl std::error::Error for Failure {
    fn source(&self) -> Option<&(dyn std::error::Error + 'static)> {
        self.previous.as_deref().map(|p| p as &(dyn std::error::Error + 'static))
    }
}
