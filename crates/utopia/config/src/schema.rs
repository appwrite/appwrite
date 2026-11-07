//! Schemas and loading.

use std::sync::Arc;

use php_std::types::KeyRef;
use php_std::{ArrayKey, Str};
use utopia_validators::Validator;

use crate::{Array, Error, Instance, Parser, Source, Value, narrow};

/// The type a property holds.
///
/// PHP: the built-in or class name of a property's declared type.
#[derive(Clone)]
pub enum Kind {
    /// `string`.
    String,
    /// `int`.
    Int,
    /// `float`.
    Float,
    /// `bool`.
    Bool,
    /// `array`.
    Array,
    /// `mixed`: any value, null included.
    Mixed,
    /// A nested config of a schema.
    ///
    /// PHP: a class-typed property.
    Config(Arc<Schema>),
}

impl Kind {
    /// The type's name, as PHP writes it.
    pub fn name(&self) -> &str {
        match self {
            Kind::String => "string",
            Kind::Int => "int",
            Kind::Float => "float",
            Kind::Bool => "bool",
            Kind::Array => "array",
            Kind::Mixed => "mixed",
            Kind::Config(schema) => schema.name(),
        }
    }
}

impl std::fmt::Debug for Kind {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(self.name())
    }
}

/// A property's declared type: a [`Kind`], and whether it also accepts null.
///
/// PHP: `string`, `?string`, `TestConfig`, ...
#[derive(Clone, Debug)]
pub struct Type {
    /// What the property holds.
    pub kind: Kind,
    /// Whether it also holds null (`?type`).
    pub nullable: bool,
}

impl Type {
    /// A type that does not accept null.
    pub fn of(kind: Kind) -> Self {
        Self { kind, nullable: false }
    }

    /// A type that also accepts null (`?kind`).
    pub fn nullable(kind: Kind) -> Self {
        Self { kind, nullable: true }
    }

    fn allows_null(&self) -> bool {
        self.nullable || matches!(self.kind, Kind::Mixed)
    }
}

/// Where a property reads its value from.
#[derive(Clone)]
pub enum Field {
    /// A key of the parsed data, checked with a validator.
    ///
    /// PHP: `#[Key($name, $validator, $required)]`.
    Key {
        /// The key; dots reach into nested maps (`db.host`).
        name: String,
        /// Accepts or refuses the value; its description explains a refusal.
        validator: Arc<dyn Validator>,
        /// Whether a missing key is an error.
        required: bool,
    },
    /// A key of the parsed data holding an already loaded config of the
    /// property's schema.
    ///
    /// PHP: `#[ConfigKey($name, $required)]`; an empty name is the
    /// property's.
    Config {
        /// The key; the property's name when empty.
        name: String,
        /// Whether a missing key is an error.
        required: bool,
    },
}

/// One property of a [`Schema`].
///
/// PHP: a public typed property with a `#[Key]` or `#[ConfigKey]` attribute.
#[derive(Clone)]
pub struct Property {
    /// The property's name.
    pub name: String,
    /// Its type.
    pub kind: Type,
    /// The value it has when an optional key is missing.
    pub default: Option<Value>,
    /// Where it reads its value from.
    pub field: Field,
}

impl Property {
    /// A required property read from `key` and checked with `validator`.
    ///
    /// PHP: `#[Key($key, $validator)] public type $name;`.
    pub fn key(name: &str, kind: Type, key: &str, validator: impl Validator + 'static) -> Self {
        Self {
            name: name.to_owned(),
            kind,
            default: None,
            field: Field::Key { name: key.to_owned(), validator: Arc::new(validator), required: true },
        }
    }

    /// A required property holding a config loaded before, read from `key`
    /// (the property's name when empty).
    ///
    /// PHP: `#[ConfigKey($key)] public Schema $name;`.
    pub fn config(name: &str, kind: Type, key: &str) -> Self {
        Self {
            name: name.to_owned(),
            kind,
            default: None,
            field: Field::Config { name: key.to_owned(), required: true },
        }
    }

    /// Makes the key optional: when it is missing, the property keeps its
    /// default, or is null when it is nullable.
    ///
    /// PHP: `required: false`.
    pub fn optional(mut self) -> Self {
        match &mut self.field {
            Field::Key { required, .. } | Field::Config { required, .. } => *required = false,
        }
        self
    }

    /// The property's default value.
    ///
    /// PHP: `public type $name = $default;`.
    pub fn default(mut self, value: impl Into<Value>) -> Self {
        self.default = Some(value.into());
        self
    }
}

impl std::fmt::Debug for Property {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Property").field("name", &self.name).field("kind", &self.kind).finish()
    }
}

/// The shape of a config: a name and properties, each read from one key.
///
/// PHP: a class whose public typed properties carry `#[Key]` or
/// `#[ConfigKey]` attributes.
///
/// ```
/// use utopia_config::{Kind, Property, Schema, Type};
/// use utopia_validators::Text;
///
/// let schema = Schema::new("App\\Config")
///     .property(Property::key("host", Type::of(Kind::String), "db.host", Text::new(1024)))
///     .property(Property::key("port", Type::nullable(Kind::String), "db.port", Text::new(5)).optional());
/// assert_eq!(schema.properties().len(), 2);
/// ```
#[derive(Clone, Debug)]
pub struct Schema {
    name: Arc<str>,
    properties: Vec<Property>,
}

impl Schema {
    /// A schema without properties.
    ///
    /// PHP: the class name.
    pub fn new(name: &str) -> Self {
        Self { name: Arc::from(name), properties: Vec::new() }
    }

    /// Adds a property.
    pub fn property(mut self, property: Property) -> Self {
        self.properties.push(property);
        self
    }

    /// The schema's name.
    pub fn name(&self) -> &str {
        &self.name
    }

    /// The properties, in order.
    pub fn properties(&self) -> &[Property] {
        &self.properties
    }
}

/// Reads `key` in `data`: the key itself, or else a path through nested
/// maps where each step may itself contain dots (`db.config.tls` finds
/// `['db' => ['config.tls' => ...]]`). `None` when absent; a present null is
/// `Some(Null)`.
fn resolve<'a>(data: &'a Array, key: &str) -> Option<&'a Value> {
    if let Some(v) = data.get_ref(KeyRef::normalize(key.as_bytes())) {
        return Some(v);
    }
    let parts: Vec<&str> = key.split('.').collect();
    resolve_parts(data, &parts)
}

fn resolve_parts<'a>(data: &'a Array, parts: &[&str]) -> Option<&'a Value> {
    match parts.len() {
        0 => None,
        1 => data.get_ref(KeyRef::normalize(parts[0].as_bytes())),
        _ => {
            for length in 1..=parts.len() {
                let key = parts[..length].join(".");
                if let Some(value) = data.get_ref(KeyRef::normalize(key.as_bytes())) {
                    if length == parts.len() {
                        return Some(value);
                    }
                    if let Value::Array(nested) = value
                        && let Some(found) = resolve_parts(nested, &parts[length..])
                    {
                        return Some(found);
                    }
                }
            }
            None
        }
    }
}

/// Converts `value` to `kind` as PHP assigns a typed property outside strict
/// mode; `None` where PHP throws a `TypeError`.
fn coerce(value: &Value, kind: &Type) -> Option<Value> {
    if matches!(value, Value::Null) {
        return kind.allows_null().then_some(Value::Null);
    }
    let numeric = |s: &Str| php_std::value::numeric_bytes_ex(s.as_bytes()).map(|(n, _)| n);
    Some(match (&kind.kind, value) {
        (Kind::Mixed, v) => v.clone(),
        (Kind::Array, Value::Array(_)) => value.clone(),
        (Kind::Config(schema), Value::Ext(instance)) if instance.schema() == schema.name() => value.clone(),
        (Kind::Int, Value::Int(_)) => value.clone(),
        (Kind::Int, Value::Float(f)) => Value::Int(float_to_int(*f)?),
        (Kind::Int, Value::Bool(b)) => Value::Int(i64::from(*b)),
        (Kind::Int, Value::Str(s)) => match numeric(s)? {
            php_std::value::Number::Int(i) => Value::Int(i),
            php_std::value::Number::Float(f) => Value::Int(float_to_int(f)?),
        },
        (Kind::Float, Value::Float(_)) => value.clone(),
        (Kind::Float, Value::Int(i)) => Value::Float(*i as f64),
        (Kind::Float, Value::Bool(b)) => Value::Float(if *b { 1.0 } else { 0.0 }),
        (Kind::Float, Value::Str(s)) => Value::Float(numeric(s)?.as_f64()),
        (Kind::String, Value::Str(_)) => value.clone(),
        (Kind::String, Value::Int(_) | Value::Float(_) | Value::Bool(_)) => Value::Str(value.to_php_string().ok()?),
        (Kind::Bool, Value::Bool(_)) => value.clone(),
        (Kind::Bool, Value::Int(_) | Value::Float(_) | Value::Str(_)) => Value::Bool(value.truthy()),
        _ => return None,
    })
}

/// A float as an `int` property takes it: integral or not (PHP deprecates a
/// fraction), but finite and within `i64`.
fn float_to_int(f: f64) -> Option<i64> {
    const LIMIT: f64 = 9_223_372_036_854_775_808.0;
    (-LIMIT..LIMIT).contains(&f).then_some(f as i64)
}

/// The value of an optional property whose key is missing: its default, or
/// null for a nullable property.
fn unset(property: &Property, key: &str) -> Result<Value, Error> {
    if let Some(default) = &property.default {
        return Ok(default.clone());
    }
    if property.kind.allows_null() {
        return Ok(Value::Null);
    }
    Err(Error::Load(format!(
        "Missing optional key: {key}. Property {} needs a default or a nullable type.",
        property.name
    )))
}

/// Loads a config: reads `source`, parses it with `parser`, and fills each
/// property of `schema` from its key.
///
/// Fails with [`Error::Load`] when the source has no contents, a required
/// key is missing, an optional key without default is missing from a
/// non-nullable property, a validator refuses a value
/// (`Invalid value for <key>: <description>`), a value cannot be converted
/// to its property's type, or a nested config is not of the property's
/// schema; and with [`Error::Parse`] when the contents cannot be parsed.
///
/// PHP: `Config::load($source, $parser, $className)`.
///
/// ```
/// use utopia_config::{Json, Kind, Property, Schema, Type, Value, Variable, load};
/// use utopia_validators::{Boolean, Text};
///
/// let schema = Schema::new("App\\Database")
///     .property(Property::key("host", Type::of(Kind::String), "db.host", Text::new(1024)))
///     .property(Property::key("tls", Type::of(Kind::Bool), "db.config.tls", Boolean::default()));
///
/// let json = r#"{"db": {"host": "docker.internal", "config": {"tls": true}}}"#;
/// let config = load(&Variable::new(json), &Json, &schema)?;
/// assert_eq!(config.get("host"), Some(&Value::from("docker.internal")));
/// assert_eq!(config.get("tls"), Some(&Value::Bool(true)));
///
/// let error = load(&Variable::new(r#"{"db": {}}"#), &Json, &schema).unwrap_err();
/// assert_eq!(error.to_string(), "Missing required key: db.host");
/// # Ok::<(), utopia_config::Error>(())
/// ```
///
/// ```php
/// use Utopia\Config\Attribute\Key;
/// use Utopia\Config\Config;
/// use Utopia\Config\Parser\JSON;
/// use Utopia\Config\Source\Variable;
/// use Utopia\Validator\Boolean;
/// use Utopia\Validator\Text;
///
/// class Database
/// {
///     #[Key('db.host', new Text(1024))]
///     public string $host;
///
///     #[Key('db.config.tls', new Boolean())]
///     public bool $tls;
/// }
///
/// $json = '{"db": {"host": "docker.internal", "config": {"tls": true}}}';
/// $config = Config::load(new Variable($json), new JSON(), Database::class);
/// echo $config->host, "\n"; // docker.internal
///
/// try {
///     Config::load(new Variable('{"db": {}}'), new JSON(), Database::class);
/// } catch (\Utopia\Config\Exception\Load $e) {
///     echo $e->getMessage(), "\n"; // Missing required key: db.host
/// }
/// ```
pub fn load(source: &dyn Source, parser: &dyn Parser, schema: &Schema) -> Result<Instance, Error> {
    let contents = source.contents();
    if matches!(contents, Value::Null) {
        return Err(Error::Load("Loader returned null contents.".into()));
    }
    let data = parser.parse(contents, Some(schema))?;
    let mut properties = Array::default();
    for property in &schema.properties {
        let value = match &property.field {
            Field::Key { name, validator, required } => match resolve(&data, name) {
                None if *required => return Err(Error::Load(format!("Missing required key: {name}"))),
                None => unset(property, name)?,
                Some(value) => {
                    let plain = narrow(value);
                    let verdict = validator.validate((&plain).into());
                    let valid = verdict.as_ref().is_ok_and(|v| v.valid);
                    if !valid {
                        let description = match &verdict {
                            Ok(v) => v.describe(validator.as_ref()),
                            Err(_) => validator.description(),
                        };
                        return Err(Error::Load(format!("Invalid value for {name}: {description}")));
                    }
                    coerce(value, &property.kind).ok_or_else(|| {
                        Error::Load(format!(
                            "Invalid value for {name}: does not match the type of property {}.",
                            property.name
                        ))
                    })?
                }
            },
            Field::Config { name, required } => {
                // PHP: `empty($name)`, so "0" also means the property's name.
                let key = if name.is_empty() || name == "0" { property.name.as_str() } else { name.as_str() };
                match resolve(&data, key) {
                    None if *required => return Err(Error::Load(format!("Missing required key: {key}"))),
                    None => unset(property, key)?,
                    Some(value) => match (&property.kind.kind, value) {
                        (Kind::Config(expected), Value::Ext(instance)) if instance.schema() == expected.name() => {
                            value.clone()
                        }
                        (kind, _) => {
                            return Err(Error::Load(format!(
                                "Invalid value for {key}: Must be instance of {}.",
                                kind.name()
                            )));
                        }
                    },
                }
            }
        };
        properties.set(ArrayKey::Str(Str::from(property.name.as_str())), value);
    }
    Ok(Instance { class: schema.name.clone(), properties })
}
