//! Reading raw schema values into the canonical [`Schema`] tree.

use std::borrow::Cow;

use php_std::types::KeyRef;
use php_std::value::Number;
use php_std::{Array, ArrayKey, Value};

use crate::schema::{
    AdditionalProperties, Annotations, ArraySchema, CompositeSchema, Composition, Discriminator, NumericSchema,
    ObjectSchema, SchemaKind, StringSchema, is_scalar,
};
use crate::shape::{self, Loc, key_text, text};
use crate::{Error, Schema, Version};

/// The JSON Schema rules an OpenAPI version permits.
///
/// Version differences inside schema reading are carried as data here
/// rather than as branches on [`Version`].
///
/// PHP: `Utopia\OpenAPI\Parser\Schema\Dialect`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Dialect {
    /// `true` and `false` are schemas (any value, no value).
    pub boolean_schemas: bool,
    /// `type` may be a list of types, `null` among them.
    pub type_arrays: bool,
    /// `const` is a single-value `enum`.
    pub const_keyword: bool,
}

impl Dialect {
    /// The rules of `version`: OpenAPI 3.1 (JSON Schema 2020-12) has all
    /// three, 2.0 and 3.0 none.
    ///
    /// PHP: `Dialect::for($version)`.
    pub fn for_version(version: Version) -> Self {
        let modern = version == Version::V3_1;
        Dialect { boolean_schemas: modern, type_arrays: modern, const_keyword: modern }
    }
}

/// Reads raw schema values into the canonical tree.
///
/// Depends on nothing but its [`Dialect`]: schema references are left
/// unexpanded, so reading never needs the document or a resolver.
///
/// PHP: `Utopia\OpenAPI\Parser\Schema\Reader`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct SchemaReader {
    dialect: Dialect,
}

/// Schema keywords an OpenAPI 2.0 parameter or header may carry inline.
const PARAMETER_FIELDS: [&str; 17] = [
    "type",
    "format",
    "items",
    "default",
    "enum",
    "maximum",
    "exclusiveMaximum",
    "minimum",
    "exclusiveMinimum",
    "maxLength",
    "minLength",
    "pattern",
    "maxItems",
    "minItems",
    "uniqueItems",
    "multipleOf",
    "description",
];

fn invalid(message: String) -> Error {
    Error::InvalidSpecification(message)
}

/// `isset($data[$key])`: present and not `null`.
fn isset<'v>(data: &'v Array, key: &str) -> Option<&'v Value> {
    data.get_str(key).filter(|v| !matches!(v, Value::Null))
}

fn optional(data: &Array, key: &str) -> Result<Option<String>, Error> {
    Ok(shape::optional_string(data, key)?.map(text))
}

fn truthy(data: &Array, key: &str) -> bool {
    data.get_str(key).is_some_and(Value::truthy)
}

/// `$data[$key] ?? null`.
fn or_null(data: &Array, key: &str) -> Value {
    data.get_str(key).cloned().unwrap_or(Value::Null)
}

impl SchemaReader {
    /// A reader with the rules of `dialect`.
    pub fn new(dialect: Dialect) -> Self {
        SchemaReader { dialect }
    }

    /// A reader with the rules of `version`.
    pub fn for_version(version: Version) -> Self {
        SchemaReader::new(Dialect::for_version(version))
    }

    /// The dialect this reader follows.
    pub fn dialect(&self) -> Dialect {
        self.dialect
    }

    /// Reads a raw schema (an object, or a boolean under 3.1) found at
    /// `location`. Errors name the location of the value they are about.
    ///
    /// PHP: `Reader::read($raw, $location)`.
    ///
    /// ```
    /// use utopia_openapi::{SchemaKind, SchemaReader, Version, decode};
    ///
    /// let raw = decode(br#"{"type": ["string", "null"], "maxLength": 8}"#)?;
    /// let schema = SchemaReader::for_version(Version::V3_1).read(&raw, "#/x")?;
    /// assert!(matches!(schema.kind, SchemaKind::String(ref s) if s.max_length == Some(8)));
    /// assert!(schema.nullable);
    ///
    /// let error = SchemaReader::for_version(Version::V3_0).read(&raw, "#/x").unwrap_err();
    /// assert_eq!(error.to_string(), "Invalid schema type at #/x/type");
    /// # Ok::<(), utopia_openapi::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\OpenAPI\Parser\Schema\Dialect;
    /// use Utopia\OpenAPI\Parser\Schema\Reader;
    /// use Utopia\OpenAPI\Version;
    ///
    /// $raw = ['type' => ['string', 'null'], 'maxLength' => 8];
    /// $schema = (new Reader(Dialect::for(Version::V3_1)))->read($raw, '#/x');
    /// var_dump($schema->maxLength, $schema->nullable); // int(8), bool(true)
    /// try {
    ///     (new Reader(Dialect::for(Version::V3_0)))->read($raw, '#/x');
    /// } catch (\Utopia\OpenAPI\Exception\InvalidSpecification $e) {
    ///     echo $e->getMessage(), "\n"; // Invalid schema type at #/x/type
    /// }
    /// ```
    pub fn read(&self, raw: &Value, location: &str) -> Result<Schema, Error> {
        self.read_at(raw, &Loc::Root(location))
    }

    /// Reads a schema from the keywords an OpenAPI 2.0 parameter or header
    /// carries inline (`type`, `format`, `items`, `minimum`, ...), where
    /// there is no nested `schema`; every other key is dropped.
    ///
    /// PHP: `Reader::readParameterFields($data, $location)`.
    pub fn read_parameter_fields(&self, data: &Array, location: &str) -> Result<Schema, Error> {
        self.parameter_fields_at(data, &Loc::Root(location))
    }

    pub(crate) fn parameter_fields_at(&self, data: &Array, location: &Loc<'_>) -> Result<Schema, Error> {
        let fields: Array = data
            .iter()
            .filter(|(k, _)| matches!(k, KeyRef::Str(s) if PARAMETER_FIELDS.iter().any(|f| f.as_bytes() == *s)))
            .map(|(k, v)| (k.to_owned(), v.clone()))
            .collect();
        self.read_object(Cow::Owned(fields), location)
    }

    pub(crate) fn read_at(&self, raw: &Value, location: &Loc<'_>) -> Result<Schema, Error> {
        if let Value::Bool(b) = raw {
            if !self.dialect.boolean_schemas {
                return Err(invalid(format!("Boolean schemas are only supported by OpenAPI 3.1 at {location}")));
            }
            return Ok(Schema::new(if *b { SchemaKind::Any } else { SchemaKind::Never }));
        }
        let data = shape::object_at(raw, location)?;
        self.read_object(data, location)
    }

    /// `read()` once the value is known to be an object.
    fn read_object(&self, data: Cow<'_, Array>, location: &Loc<'_>) -> Result<Schema, Error> {
        let mut data = data;
        if self.dialect.const_keyword
            && let Some(constant) = data.get_str("const")
            && let Some(raw_enum) = isset(&data, "enum")
        {
            let constant = constant.clone();
            let enum_values = shape::list_at(raw_enum, &location.name("enum"))?;
            if !is_scalar(&constant) && !matches!(constant, Value::Null) {
                let mut rest = data.into_owned();
                rest.remove(&ArrayKey::from("const"));
                let mut constraint = Array::new();
                constraint.set(ArrayKey::from("const"), constant);
                let first = self.read_object(Cow::Borrowed(&rest), location)?;
                let second = self.read_object(Cow::Owned(constraint), location)?;
                let discriminator = self.discriminator(&rest)?;
                let annotations = self.annotations(&rest)?;
                return CompositeSchema::build(
                    Some(Composition::AllOf),
                    vec![first, second],
                    None,
                    discriminator,
                    annotations,
                    location,
                );
            }
            let matches = enum_values.values().any(|value| {
                value.strict_eq(&constant)
                    || match (value, &constant) {
                        (Value::Int(_) | Value::Float(_), Value::Int(_) | Value::Float(_)) => value.loose_eq(&constant),
                        _ => false,
                    }
            });
            if !matches {
                return Ok(Schema::annotated(SchemaKind::Never, self.annotations(&data)?));
            }
            data.to_mut().set(ArrayKey::from("enum"), Value::Array(Array::from_list(vec![constant])));
        }
        let mut annotations = self.annotations(&data)?;

        if isset(&data, "$ref").is_some() {
            let reference = text(shape::required_at(&data, "$ref", location)?);
            return Ok(Schema::annotated(SchemaKind::Reference(reference), annotations));
        }

        let mut kind: Option<&[u8]> = None;
        let raw_type = data.get_str("type").filter(|v| !matches!(v, Value::Null));
        match raw_type {
            Some(Value::Array(types)) => {
                if !self.dialect.type_arrays || !types.is_list() {
                    return Err(invalid(format!("Invalid schema type at {location}/type")));
                }
                let members: Vec<&Value> =
                    types.values().filter(|t| !matches!(t, Value::Str(s) if s.as_bytes() == b"null")).collect();
                annotations.nullable = members.len() != types.len();
                if members.len() > 1 {
                    let mut schemas = Vec::with_capacity(members.len());
                    for (index, member) in members.iter().enumerate() {
                        let Value::Str(_) = member else {
                            return Err(invalid(format!("Invalid schema type at {location}/type/{index}")));
                        };
                        let mut single = Array::new();
                        single.set(ArrayKey::from("type"), (*member).clone());
                        let type_location = location.name("type");
                        schemas.push(self.read_object(Cow::Owned(single), &type_location.index(index))?);
                    }
                    let discriminator = self.discriminator(&data)?;
                    return CompositeSchema::build(
                        Some(Composition::AnyOf),
                        schemas,
                        None,
                        discriminator,
                        annotations,
                        location,
                    );
                }
                match members.first() {
                    None | Some(Value::Null) => {}
                    Some(Value::Str(s)) => kind = Some(s.as_bytes()),
                    Some(_) => return Err(invalid(format!("Invalid schema type at {location}/type"))),
                }
            }
            Some(Value::Str(s)) => kind = Some(s.as_bytes()),
            Some(_) => return Err(invalid(format!("Invalid schema type at {location}/type"))),
            None => {}
        }

        for composition in [Composition::OneOf, Composition::AnyOf, Composition::AllOf] {
            let keyword = composition.as_str();
            if let Some(members) = data.get_str(keyword) {
                let members_location = location.name(keyword);
                let members = shape::list_at(members, &members_location)?;
                let mut schemas = Vec::with_capacity(members.len());
                for (index, member) in members.values().enumerate() {
                    schemas.push(self.read_at(member, &members_location.index(index))?);
                }
                let not = match data.get_str("not") {
                    Some(not) => Some(self.read_at(not, &location.name("not"))?),
                    None => None,
                };
                let discriminator = self.discriminator(&data)?;
                return CompositeSchema::build(Some(composition), schemas, not, discriminator, annotations, location);
            }
        }
        if let Some(not) = data.get_str("not") {
            let not = self.read_at(not, &location.name("not"))?;
            let discriminator = self.discriminator(&data)?;
            return CompositeSchema::build(None, Vec::new(), Some(not), discriminator, annotations, location);
        }

        // A scalar singleton already fixes the instance type: keep that
        // type's constraints instead of dropping them into an any schema.
        if kind.is_none()
            && let [only] = annotations.enum_values.as_slice()
        {
            kind = match only {
                Value::Str(_) => Some(b"string"),
                Value::Int(_) | Value::Float(_) => Some(b"number"),
                Value::Bool(_) => Some(b"boolean"),
                _ => None,
            };
        }
        if kind.is_none() {
            if isset(&data, "properties").is_some() || data.get_str("additionalProperties").is_some() {
                kind = Some(b"object");
            } else if isset(&data, "items").is_some() {
                kind = Some(b"array");
            }
        }

        let Some(kind) = kind else { return Ok(Schema::annotated(SchemaKind::Any, annotations)) };
        let kind = match kind {
            b"string" | b"file" => {
                let keywords = StringSchema {
                    min_length: shape::int_at(data.get_str("minLength"), &location.name("minLength"))?,
                    max_length: shape::int_at(data.get_str("maxLength"), &location.name("maxLength"))?,
                    pattern: optional(&data, "pattern")?,
                    ..StringSchema::default()
                };
                if kind == b"file" {
                    annotations.format = Some("binary".to_owned());
                }
                SchemaKind::String(keywords)
            }
            b"integer" => SchemaKind::Integer(self.numeric(&data, location)?),
            b"number" => SchemaKind::Number(self.numeric(&data, location)?),
            b"boolean" => SchemaKind::Boolean,
            b"array" => {
                let items = match data.get_str("items") {
                    Some(items) => self.read_at(items, &location.name("items"))?,
                    None => Schema::new(SchemaKind::Any),
                };
                SchemaKind::Array(ArraySchema {
                    items: Box::new(items),
                    min_items: shape::int_at(data.get_str("minItems"), &location.name("minItems"))?,
                    max_items: shape::int_at(data.get_str("maxItems"), &location.name("maxItems"))?,
                    unique_items: truthy(&data, "uniqueItems"),
                })
            }
            b"object" => SchemaKind::Object(self.object(&data, location)?),
            b"null" => {
                annotations.nullable = true;
                SchemaKind::Any
            }
            other => {
                return Err(invalid(format!(
                    "Unsupported schema type '{}' at {location}",
                    String::from_utf8_lossy(other)
                )));
            }
        };
        Ok(Schema::annotated(kind, annotations))
    }

    /// The annotations every kind shares (PHP's `common()`), in PHP's order
    /// of checks: `enum`, `title`, `description`, `format`.
    fn annotations(&self, data: &Array) -> Result<Annotations, Error> {
        let mut enum_values = match isset(data, "enum") {
            Some(raw) => shape::list_at(raw, &Loc::Root("schema/enum"))?.values().cloned().collect(),
            None => Vec::new(),
        };
        if self.dialect.const_keyword
            && isset(data, "enum").is_none()
            && let Some(constant) = data.get_str("const")
        {
            enum_values = vec![constant.clone()];
        }
        Ok(Annotations {
            title: optional(data, "title")?,
            description: optional(data, "description")?.unwrap_or_default(),
            nullable: truthy(data, "nullable"),
            default: or_null(data, "default"),
            enum_values,
            format: optional(data, "format")?,
            read_only: truthy(data, "readOnly"),
            write_only: truthy(data, "writeOnly"),
            deprecated: truthy(data, "deprecated"),
            example: or_null(data, "example"),
            extensions: shape::extensions(data),
        })
    }

    fn numeric(&self, data: &Array, location: &Loc<'_>) -> Result<NumericSchema, Error> {
        let mut minimum = shape::number_at(data.get_str("minimum"), &location.name("minimum"))?;
        let mut maximum = shape::number_at(data.get_str("maximum"), &location.name("maximum"))?;
        let exclusive = |bound: &mut Option<Number>, key: &str| match data.get_str(key) {
            Some(Value::Int(i)) => {
                *bound = Some(Number::Int(*i));
                true
            }
            Some(Value::Float(f)) => {
                *bound = Some(Number::Float(*f));
                true
            }
            Some(other) => other.truthy(),
            None => false,
        };
        let exclusive_minimum = exclusive(&mut minimum, "exclusiveMinimum");
        let exclusive_maximum = exclusive(&mut maximum, "exclusiveMaximum");
        Ok(NumericSchema {
            minimum,
            maximum,
            exclusive_minimum,
            exclusive_maximum,
            multiple_of: shape::number_at(data.get_str("multipleOf"), &location.name("multipleOf"))?,
        })
    }

    fn object(&self, data: &Array, location: &Loc<'_>) -> Result<ObjectSchema, Error> {
        let properties_location = location.name("properties");
        let empty = Value::Array(Array::new());
        let raw_properties = isset(data, "properties").unwrap_or(&empty);
        let raw_properties = shape::object_at(raw_properties, &properties_location)?;
        let mut properties = crate::model::Map::with_capacity(raw_properties.len());
        for (name, raw) in raw_properties.iter() {
            properties.insert(key_text(name), self.read_at(raw, &properties_location.key(name))?);
        }
        let required = match isset(data, "required") {
            Some(raw) => {
                let names = shape::list_at(raw, &location.name("required"))?;
                let mut required = Vec::with_capacity(names.len());
                for name in names.values() {
                    required.push(text(&name.to_php_string().map_err(Error::Engine)?));
                }
                required
            }
            None => Vec::new(),
        };
        let additional_properties = match isset(data, "additionalProperties") {
            None => None,
            Some(Value::Bool(b)) => Some(AdditionalProperties::Allowed(*b)),
            Some(raw) => {
                Some(AdditionalProperties::Schema(Box::new(self.read_at(raw, &location.name("additionalProperties"))?)))
            }
        };
        Ok(ObjectSchema {
            properties,
            required,
            additional_properties,
            min_properties: shape::int_at(data.get_str("minProperties"), &location.name("minProperties"))?,
            max_properties: shape::int_at(data.get_str("maxProperties"), &location.name("maxProperties"))?,
        })
    }

    fn discriminator(&self, data: &Array) -> Result<Option<Discriminator>, Error> {
        let Some(raw) = isset(data, "discriminator") else { return Ok(None) };
        if let Value::Str(name) = raw {
            return Ok(Some(Discriminator { property_name: text(name), ..Discriminator::default() }));
        }
        let location = Loc::Root("schema/discriminator");
        let value = shape::object_at(raw, &location)?;
        let empty = Value::Array(Array::new());
        let raw_mapping = value.get_str("mapping").filter(|v| !matches!(v, Value::Null)).unwrap_or(&empty);
        let raw_mapping = shape::object_at(raw_mapping, &Loc::Root("schema/discriminator/mapping"))?;
        let mut mapping = crate::model::Map::with_capacity(raw_mapping.len());
        for (name, reference) in raw_mapping.iter() {
            let Value::Str(reference) = reference else {
                return Err(invalid("Discriminator mappings must be strings".to_owned()));
            };
            mapping.insert(key_text(name), text(reference));
        }
        Ok(Some(Discriminator {
            property_name: text(shape::required_at(&value, "propertyName", &location)?),
            mapping,
            extensions: shape::extensions(&value),
        }))
    }
}
