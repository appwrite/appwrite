//! Application configuration: a process-wide registry of parameters, and
//! typed configs loaded from a source, parsed, and checked against a schema.
//!
//! [`Params`] holds named values and reads nested ones with dotted keys
//! (`platform.hostname`); [`set_param`] and [`get_param`] work on the
//! process-wide instance. [`load`] reads a [`Source`] ([`Environment`],
//! [`File`], [`Variable`]), parses it with a [`Parser`] ([`Dotenv`],
//! [`Json`], [`Yaml`], [`Php`], [`NoParser`]) and fills an [`Instance`] of a
//! [`Schema`]: each property reads one key, is validated by a validator of
//! `utopia-validators`, and is converted to its declared [`Type`].
//!
//! Values are PHP values ([`Value`], from `php-std`), as configs come from
//! PHP-shaped files and are shared with PHP; a loaded config nested in
//! another is a [`Value::Ext`] holding its [`Instance`].
//!
//! PHP: ports `utopia-php/config`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Config::setParam()`, `Config::getParam()`, `Config::$params` | [`set_param`], [`get_param`] ([`Params`]) |
//! | `Config::load($source, $parser, $className)` | [`load`] |
//! | a schema class with `#[Key]` / `#[ConfigKey]` properties | [`Schema`], [`Property::key`], [`Property::config`] |
//! | an instance of the schema class | [`Instance`] |
//! | `Source`, `Source\Environment`, `Source\File`, `Source\Variable` | [`Source`], [`Environment`], [`File`], [`Variable`] |
//! | `Parser`, `Parser\Dotenv`, `JSON`, `YAML`, `PHP`, `None` | [`Parser`], [`Dotenv`], [`Json`], [`Yaml`], [`Php`], [`NoParser`] |
//! | `Exception\Load`, `Exception\Parse` | [`Error::Load`], [`Error::Parse`] |
//!
//! PHP: a schema is a class whose public typed properties carry attributes,
//! read by reflection, and a config is an instance of it. Here a schema is a
//! value describing the same properties, and a config is an [`Instance`]
//! listing their values; the class shapes PHP refuses (methods, a
//! constructor, a property without a type, an attribute or public
//! visibility) cannot be described.
//!
//! ```
//! use utopia_config::{Dotenv, Kind, Property, Schema, Type, Value, Variable, load};
//! use utopia_validators::{Text, Wildcard};
//!
//! let schema = Schema::new("App\\DatabaseConfig")
//!     .property(Property::key("host", Type::of(Kind::String), "DB_HOST", Text::with_min(255, 0)))
//!     .property(Property::key("tls", Type::of(Kind::Bool), "DB_TLS", Wildcard).optional().default(false));
//!
//! let config = load(&Variable::new("DB_HOST=db.internal\nDB_TLS=yes"), &Dotenv, &schema)?;
//! assert_eq!(config.get("host"), Some(&Value::from("db.internal")));
//! assert_eq!(config.get("tls"), Some(&Value::Bool(true)));
//! # Ok::<(), utopia_config::Error>(())
//! ```
//!
//! ```php
//! use Utopia\Config\Attribute\Key;
//! use Utopia\Config\Config;
//! use Utopia\Config\Parser\Dotenv;
//! use Utopia\Config\Source\Variable;
//! use Utopia\Validator\Text;
//! use Utopia\Validator\Wildcard;
//!
//! class DatabaseConfig
//! {
//!     #[Key('DB_HOST', new Text(255, 0))]
//!     public string $host;
//!
//!     #[Key('DB_TLS', new Wildcard(), required: false)]
//!     public bool $tls = false;
//! }
//!
//! $config = Config::load(new Variable("DB_HOST=db.internal\nDB_TLS=yes"), new Dotenv(), DatabaseConfig::class);
//! echo $config->host, "\n";      // db.internal
//! var_dump($config->tls);        // bool(true)
//! ```

#![warn(missing_docs)]

mod params;
mod parser;
mod php;
mod schema;
mod source;

use std::sync::Arc;

pub use params::{Params, get_param, set_param, with_params};
pub use parser::{Dotenv, Json, NoParser, Parser, Php, Yaml};
pub use schema::{Field, Kind, Property, Schema, Type, load};
pub use source::{Environment, File, Source, Variable};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// A config value: a PHP value, where objects are loaded configs.
pub type Value = php_std::Value<Instance>;

/// A PHP array of config values.
pub type Array = php_std::Array<Instance>;

/// Why a config could not be parsed or loaded.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// The contents could not be parsed.
    ///
    /// PHP: `Utopia\Config\Exception\Parse`, with the same message.
    #[error("{0}")]
    Parse(String),
    /// The parsed data does not fit the schema, or the source had nothing.
    ///
    /// PHP: `Utopia\Config\Exception\Load`, with the same message.
    #[error("{0}")]
    Load(String),
}

impl Error {
    /// The name of the PHP exception class this error matches.
    ///
    /// PHP: the class `utopia-php/config` throws.
    ///
    /// ```
    /// use utopia_config::Error;
    ///
    /// assert_eq!(Error::Parse("x".into()).php_class(), "Utopia\\Config\\Exception\\Parse");
    /// ```
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Parse(_) => "Utopia\\Config\\Exception\\Parse",
            Error::Load(_) => "Utopia\\Config\\Exception\\Load",
        }
    }
}

/// A loaded config: the schema it was loaded with, and the value of each of
/// its properties, in the schema's order.
///
/// PHP: an instance of the schema class; `get($name)` reads
/// `$config->$name`.
#[derive(Debug, Clone, PartialEq)]
pub struct Instance {
    class: Arc<str>,
    properties: php_std::Array<Instance>,
}

impl Instance {
    /// The value of a property.
    ///
    /// PHP: `$config->$property`.
    pub fn get(&self, property: &str) -> Option<&Value> {
        self.properties.get_str(property)
    }

    /// The name of the schema it was loaded with.
    ///
    /// PHP: `$config::class`.
    pub fn schema(&self) -> &str {
        &self.class
    }

    /// Every property and its value, in order.
    pub fn properties(&self) -> &Array {
        &self.properties
    }
}

impl php_std::Extension for Instance {
    fn class(&self) -> &str {
        &self.class
    }

    fn to_array(&self) -> php_std::Array<Self> {
        self.properties.clone()
    }
}

/// A value of `php-std`'s plain model as a config value.
pub fn widen(value: php_std::Value) -> Value {
    match value {
        php_std::Value::Null => Value::Null,
        php_std::Value::Bool(b) => Value::Bool(b),
        php_std::Value::Int(i) => Value::Int(i),
        php_std::Value::Float(f) => Value::Float(f),
        php_std::Value::Str(s) => Value::Str(s),
        php_std::Value::Array(a) => Value::Array(widen_array(a)),
        php_std::Value::Object(a) => Value::Object(widen_array(a)),
        php_std::Value::Ext(never) => match never {},
    }
}

fn widen_array(array: php_std::Array) -> Array {
    let next = array.next_index();
    Array::from_entries(array.into_iter().map(|(k, v)| (k, widen(v))), next)
}

/// A config value in `php-std`'s plain model; a loaded config becomes a
/// `stdClass` of its properties.
pub fn narrow(value: &Value) -> php_std::Value {
    match value {
        Value::Null => php_std::Value::Null,
        Value::Bool(b) => php_std::Value::Bool(*b),
        Value::Int(i) => php_std::Value::Int(*i),
        Value::Float(f) => php_std::Value::Float(*f),
        Value::Str(s) => php_std::Value::Str(s.clone()),
        Value::Array(a) => php_std::Value::Array(narrow_array(a)),
        Value::Object(a) => php_std::Value::Object(narrow_array(a)),
        Value::Ext(instance) => php_std::Value::Object(narrow_array(&instance.properties)),
    }
}

fn narrow_array(array: &Array) -> php_std::Array {
    php_std::Array::from_entries(array.iter().map(|(k, v)| (k.to_owned(), narrow(v))), array.next_index())
}
