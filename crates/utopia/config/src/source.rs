//! Where config contents come from.

use std::path::Path;

use php_std::{ArrayKey, Str};

use crate::{Array, Value};

/// Provides the raw contents a [`Parser`](crate::Parser) reads: a string for
/// file formats, an array for [`NoParser`](crate::NoParser), or null when
/// there is nothing to read.
///
/// PHP: `Utopia\Config\Source` (`getContents()`).
pub trait Source {
    /// The contents.
    ///
    /// PHP: `getContents()`.
    fn contents(&self) -> &Value;
}

/// The process environment, read once when created: an array of variable
/// names to values.
///
/// PHP: `Utopia\Config\Source\Environment` (`getenv()`).
///
/// ```
/// use utopia_config::{Environment, Source, Value};
///
/// let env = Environment::new();
/// assert!(matches!(env.contents(), Value::Array(_)));
/// ```
///
/// ```php
/// use Utopia\Config\Source\Environment;
///
/// var_dump(is_array((new Environment())->getContents())); // bool(true)
/// ```
#[derive(Debug, Clone)]
pub struct Environment {
    contents: Value,
}

impl Default for Environment {
    fn default() -> Self {
        Self::new()
    }
}

impl Environment {
    /// The current environment.
    ///
    /// PHP: `new Environment()`.
    pub fn new() -> Self {
        Self::from_vars(std::env::vars_os().map(|(k, v)| (k.into_encoded_bytes(), v.into_encoded_bytes())))
    }

    /// An environment of the given variables, as [`Environment::new`] reads
    /// the process's.
    pub fn from_vars(vars: impl IntoIterator<Item = (Vec<u8>, Vec<u8>)>) -> Self {
        let mut array = Array::default();
        for (k, v) in vars {
            array.set(ArrayKey::normalize(Str::from(k)), Value::Str(Str::from(v)));
        }
        Self { contents: Value::Array(array) }
    }
}

impl Source for Environment {
    fn contents(&self) -> &Value {
        &self.contents
    }
}

/// A file's contents, read once when created; null when it does not exist or
/// cannot be read.
///
/// PHP: `Utopia\Config\Source\File`.
///
/// ```
/// use utopia_config::{File, Source, Value};
///
/// assert_eq!(File::new("/nonexistent/config.json").contents(), &Value::Null);
/// ```
///
/// ```php
/// use Utopia\Config\Source\File;
///
/// var_dump((new File('/nonexistent/config.json'))->getContents()); // NULL
/// ```
#[derive(Debug, Clone)]
pub struct File {
    contents: Value,
}

impl File {
    /// The contents of the file at `path`.
    ///
    /// PHP: `new File($path)`.
    pub fn new(path: impl AsRef<Path>) -> Self {
        let contents = match std::fs::read(path) {
            Ok(bytes) => Value::Str(Str::from(bytes)),
            Err(_) => Value::Null,
        };
        Self { contents }
    }
}

impl Source for File {
    fn contents(&self) -> &Value {
        &self.contents
    }
}

/// Contents given directly: a string to parse, or an array.
///
/// PHP: `Utopia\Config\Source\Variable`.
///
/// ```
/// use utopia_config::{Source, Value, Variable};
///
/// assert_eq!(Variable::new("KEY=value").contents(), &Value::from("KEY=value"));
/// ```
///
/// ```php
/// use Utopia\Config\Source\Variable;
///
/// echo (new Variable('KEY=value'))->getContents(), "\n"; // KEY=value
/// ```
#[derive(Debug, Clone)]
pub struct Variable {
    contents: Value,
}

impl Variable {
    /// Contents of any value.
    ///
    /// PHP: `new Variable($contents)`.
    pub fn new(contents: impl Into<Value>) -> Self {
        Self { contents: contents.into() }
    }
}

impl Source for Variable {
    fn contents(&self) -> &Value {
        &self.contents
    }
}
