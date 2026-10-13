//! Structured output: the JSON Schema an agent's answers must follow.

use php_std::json::{self, Flags};
use php_std::types::{ArrayKey, KeyRef};
use php_std::{Array, Str, Value};

use crate::Error;
use crate::php;

/// A JSON Schema type a property may have.
///
/// PHP: the `SchemaObject::TYPE_*` constants.
pub mod types {
    /// `string`.
    pub const STRING: &str = "string";
    /// `array`.
    pub const ARRAY: &str = "array";
    /// `boolean`.
    pub const BOOLEAN: &str = "boolean";
    /// `integer`.
    pub const INTEGER: &str = "integer";
    /// `number`.
    pub const NUMBER: &str = "number";
    /// `object`.
    pub const OBJECT: &str = "object";
    /// `null`.
    pub const NULL: &str = "null";
}

/// Every type a property may have, in PHP's order.
///
/// PHP: `SchemaObject::getValidTypes()`.
pub const VALID_TYPES: [&str; 7] =
    [types::STRING, types::ARRAY, types::BOOLEAN, types::INTEGER, types::NUMBER, types::OBJECT, types::NULL];

/// The properties of a JSON Schema object: property name to its definition
/// (a PHP array in JSON Schema form, such as `['type' => 'string',
/// 'description' => '...']`), in insertion order. Every definition has a
/// `type` of [`VALID_TYPES`].
///
/// Definitions are PHP arrays ([`php_std::Array`]) because they are sent as
/// PHP sends them: `json_encode` decides what is a JSON list and what an
/// object, and property names are array keys (a decimal name is an integer
/// key, as in PHP).
///
/// PHP: `Utopia\Agents\Schema\SchemaObject`.
///
/// ```
/// use utopia_agents::{SchemaObject, property};
///
/// let mut object = SchemaObject::default();
/// object.add_property("location", property("string", "The city and state"))?;
/// object.add_property("unit", property("string", "celsius or fahrenheit"))?;
///
/// assert_eq!(object.names(), ["location", "unit"]);
/// assert!(object.add_property("bad", property("invalid_type", "")).is_err());
/// # Ok::<(), utopia_agents::Error>(())
/// ```
///
/// ```php
/// use Utopia\Agents\Schema\SchemaObject;
///
/// $object = new SchemaObject();
/// $object->addProperty('location', ['type' => 'string', 'description' => 'The city and state']);
/// $object->addProperty('unit', ['type' => 'string', 'description' => 'celsius or fahrenheit']);
///
/// print_r($object->getNames()); // [location, unit]
/// // $object->addProperty('bad', ['type' => 'invalid_type']) throws InvalidArgumentException
/// ```
#[derive(Debug, Clone, PartialEq, Default)]
pub struct SchemaObject {
    properties: Array,
}

/// A property definition with a type and a description, the common case.
pub fn property(kind: &str, description: &str) -> Array {
    let mut definition = Array::new();
    definition.set(ArrayKey::from("type"), Value::from(kind));
    definition.set(ArrayKey::from("description"), Value::from(description));
    definition
}

impl SchemaObject {
    /// An object with these properties, each validated in order.
    ///
    /// Fails with [`Error::InvalidArgument`] at the first definition without
    /// a valid type.
    ///
    /// PHP: `new SchemaObject($properties)`.
    pub fn new<N: AsRef<[u8]>>(properties: impl IntoIterator<Item = (N, Array)>) -> Result<SchemaObject, Error> {
        let mut object = SchemaObject::default();
        for (name, definition) in properties {
            let key = ArrayKey::normalize(Str::from(name.as_ref()));
            check(&key, &definition)?;
            object.properties.set(key, Value::Array(definition));
        }
        Ok(object)
    }

    /// Every property, name to definition.
    ///
    /// PHP: `getProperties()`.
    pub fn properties(&self) -> &Array {
        &self.properties
    }

    /// One property's definition.
    ///
    /// PHP: `getProperty($name)`.
    pub fn property(&self, name: &str) -> Option<&Array> {
        match self.properties.get_ref(KeyRef::normalize(name.as_bytes())) {
            Some(Value::Array(a)) => Some(a),
            _ => None,
        }
    }

    /// Adds a property, or replaces the one of that name in place.
    ///
    /// Fails with [`Error::InvalidArgument`] when the definition has no
    /// valid type.
    ///
    /// PHP: `addProperty($name, $property)`.
    pub fn add_property(&mut self, name: &str, definition: Array) -> Result<&mut SchemaObject, Error> {
        let key = ArrayKey::normalize(Str::from(name));
        check(&key, &definition)?;
        self.properties.set(key, Value::Array(definition));
        Ok(self)
    }

    /// Removes a property.
    ///
    /// PHP: `removeProperty($name)`.
    pub fn remove_property(&mut self, name: &str) -> &mut SchemaObject {
        self.properties.remove(&ArrayKey::normalize(Str::from(name)));
        self
    }

    /// The property names, in order (a decimal name is an integer key).
    ///
    /// PHP: `getNames()`.
    pub fn names(&self) -> Vec<String> {
        self.properties.keys().map(|k| php::key_string(k)).collect()
    }

    /// The property names as PHP array keys.
    pub fn keys(&self) -> impl ExactSizeIterator<Item = KeyRef<'_>> {
        self.properties.keys()
    }

    /// Whether a definition has a `type` of [`VALID_TYPES`].
    ///
    /// PHP: `validateProperty($name, $property)`.
    pub fn validate_property(&self, _name: &str, definition: &Array) -> bool {
        valid(definition)
    }

    /// Every type a property may have.
    ///
    /// PHP: `SchemaObject::getValidTypes()`.
    pub fn valid_types() -> &'static [&'static str] {
        &VALID_TYPES
    }
}

fn valid(definition: &Array) -> bool {
    match definition.get_str("type") {
        Some(Value::Str(s)) => VALID_TYPES.iter().any(|t| s.as_bytes() == t.as_bytes()),
        _ => false,
    }
}

/// `validateProperty()` and the exception the constructor and
/// `addProperty()` throw.
fn check(name: &ArrayKey, definition: &Array) -> Result<(), Error> {
    if valid(definition) {
        return Ok(());
    }
    // A missing `type` reads as null (after PHP's "Undefined array key" warning).
    let kind = definition.get_str("type").cloned().unwrap_or(Value::Null);
    let exported = php_std::serialize::var_export(&kind);
    Err(Error::InvalidArgument(format!(
        "Invalid type {} for property '{}'. Must be one of: {}",
        String::from_utf8_lossy(&exported),
        php::key_string(name.as_ref()),
        VALID_TYPES.join(", ")
    )))
}

/// A named JSON Schema the agent's answer must follow: an object whose
/// properties and required names are given.
///
/// PHP: `Utopia\Agents\Schema`.
///
/// ```
/// use utopia_agents::{Schema, SchemaObject, property};
///
/// let object = SchemaObject::new([
///     ("id", property("string", "The ID of the user")),
///     ("age", property("integer", "The age of the user")),
/// ])?;
/// let schema = Schema::new("User", "A user", object, vec!["id".into()]);
///
/// assert_eq!(schema.to_json()?, r#"{"id":"The ID of the user (string)","age":"The age of the user (integer)"}"#);
/// # Ok::<(), utopia_agents::Error>(())
/// ```
///
/// ```php
/// use Utopia\Agents\Schema;
/// use Utopia\Agents\Schema\SchemaObject;
///
/// $object = new SchemaObject([
///     'id' => ['type' => 'string', 'description' => 'The ID of the user'],
///     'age' => ['type' => 'integer', 'description' => 'The age of the user'],
/// ]);
/// $schema = new Schema('User', 'A user', $object, ['id']);
///
/// echo $schema->toJson(), "\n"; // {"id":"The ID of the user (string)","age":"The age of the user (integer)"}
/// ```
#[derive(Debug, Clone, PartialEq)]
pub struct Schema {
    name: String,
    description: String,
    object: SchemaObject,
    required: Vec<String>,
}

impl Schema {
    /// A schema named `name`, of `object`'s properties, `required` naming
    /// the properties an answer must have.
    ///
    /// PHP: `new Schema($name, $description, $object, $required)`.
    pub fn new(name: &str, description: &str, object: SchemaObject, required: Vec<String>) -> Schema {
        Schema { name: name.to_owned(), description: description.to_owned(), object, required }
    }

    /// The name.
    ///
    /// PHP: `getName()`.
    pub fn name(&self) -> &str {
        &self.name
    }

    /// The description.
    ///
    /// PHP: `getDescription()`.
    pub fn description(&self) -> &str {
        &self.description
    }

    /// The object of properties.
    ///
    /// PHP: `getObject()`.
    pub fn object(&self) -> &SchemaObject {
        &self.object
    }

    /// The object of properties, to change it.
    pub fn object_mut(&mut self) -> &mut SchemaObject {
        &mut self.object
    }

    /// The required property names.
    ///
    /// PHP: `getRequired()`.
    pub fn required(&self) -> &[String] {
        &self.required
    }

    /// The properties of the object.
    ///
    /// PHP: `getProperties()`.
    pub fn properties(&self) -> &Array {
        self.object.properties()
    }

    /// The schema as a JSON object of property name to `"<description>
    /// (<type>)"`, the hint providers without structured output get.
    ///
    /// Fails with [`Error::Exception`] (`Invalid JSON: ...`) when it cannot
    /// be encoded (a description that is not UTF-8).
    ///
    /// PHP: `toJson()`.
    pub fn to_json(&self) -> Result<String, Error> {
        let mut hints: Array = Array::with_capacity(self.object.properties.len());
        for (name, definition) in self.object.properties.iter() {
            let field = |key: &str| match definition {
                Value::Array(a) => match a.get_str(key) {
                    Some(Value::Str(s)) => s.as_bytes().to_vec(),
                    _ => Vec::new(),
                },
                _ => Vec::new(),
            };
            let mut hint = field("description");
            hint.extend_from_slice(b" (");
            hint.extend_from_slice(&field("type"));
            hint.push(b')');
            hints.set(name.to_owned(), Value::Str(Str::from(hint)));
        }
        json::encode(&Value::Array(hints), Flags::NONE, json::DEFAULT_DEPTH)
            .map_err(|e| Error::exception(format!("Invalid JSON: {}", e.message())))
    }
}
