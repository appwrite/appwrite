//! [`Attribute`]: attribute definitions before they are created.
//!
//! PHP: `Utopia\Database\Validator\Attribute`.

use php_std::value::Number;

use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::validator::big_int::BigInt;
use crate::validator::structure::formats;
use crate::validator::{Message, attribute_key, is_numeric, lower, text};
use crate::value::{Value, as_document};

/// Counts the collection's attributes, or measures its row width, with a
/// new attribute. PHP: `$attributeCountCallback` / `$attributeWidthCallback`.
pub type Measure = Box<dyn Fn(&Document) -> Result<i64> + Send + Sync>;

/// The adapter's name for an attribute id (PHP: `$filterCallback`).
pub type Filter = Box<dyn Fn(&str) -> String + Send + Sync>;

/// The limits and capabilities an [`Attribute`] checks against, with PHP's
/// defaults.
///
/// PHP: the constructor parameters of `Validator\Attribute` after
/// `$schemaAttributes`.
#[derive(Default)]
pub struct AttributeOptions {
    /// `$maxAttributes` (0: no limit).
    pub max_attributes: i64,
    /// `$maxWidth` (0: no limit).
    pub max_width: i64,
    /// `$maxStringLength`.
    pub max_string_length: i64,
    /// `$maxVarcharLength`.
    pub max_varchar_length: i64,
    /// `$maxIntLength`.
    pub max_int_length: i64,
    /// `$maxBigIntLength` (0: `max_int_length`).
    pub max_big_int_length: i64,
    /// `$supportForSchemaAttributes`.
    pub support_for_schema_attributes: bool,
    /// `$supportForVectors`.
    pub support_for_vectors: bool,
    /// `$supportForSpatialAttributes`.
    pub support_for_spatial_attributes: bool,
    /// `$supportForObject`.
    pub support_for_object: bool,
    /// `$supportUnsignedBigInt`.
    pub support_unsigned_big_int: bool,
    /// `$attributeCountCallback`.
    pub attribute_count: Option<Measure>,
    /// `$attributeWidthCallback`.
    pub attribute_width: Option<Measure>,
    /// `$filterCallback`.
    pub filter: Option<Filter>,
    /// `$isMigrating`.
    pub is_migrating: bool,
    /// `$sharedTables`.
    pub shared_tables: bool,
}

/// An attribute definition the collection can take: a new key (in the
/// metadata and, with schema attributes, in the table), the filters and
/// format its type needs, within the attribute count and row width
/// limits, a supported type of a valid size, and a default of its type.
///
/// Every failure is an error, as PHP throws: [`crate::Kind::Duplicate`]
/// for an existing key, [`crate::Kind::Limit`] for a limit, and
/// [`crate::Kind::Database`] otherwise; the description is its message.
///
/// ```
/// use utopia_database::Document;
/// use utopia_database::validator::attribute::{Attribute, AttributeOptions};
/// use utopia_database::value::{Value, string};
///
/// let validator = Attribute::new(&[], &[], AttributeOptions { max_string_length: 1000, ..Default::default() });
/// let title = Document::from_pairs([("$id", string("title")), ("type", string("string")), ("size", Value::Int(2000))]).unwrap();
/// assert_eq!(validator.check(&title).unwrap_err().message(), "Max size allowed for string is: 1,000");
/// ```
///
/// PHP: `Utopia\Database\Validator\Attribute` (`new Attribute($attributes,
/// $schemaAttributes, ...)`); its public `check*` methods are the steps of
/// [`Attribute::check`].
pub struct Attribute {
    attributes: Vec<(String, Document)>,
    schema_attributes: Vec<(String, Document)>,
    options: AttributeOptions,
    message: Message,
}

/// Documents by lower-case `key` (or `$id`), later ones replacing earlier
/// ones in place (PHP array assignment).
fn by_key(attributes: &[Document]) -> Vec<(String, Document)> {
    let mut out: Vec<(String, Document)> = Vec::new();
    for attribute in attributes {
        let key = lower(&attribute_key(attribute));
        match out.iter_mut().find(|(k, _)| *k == key) {
            Some(entry) => entry.1 = attribute.clone(),
            None => out.push((key, attribute.clone())),
        }
    }
    out
}

const SUPPORTED: [&str; 11] = [
    "string",
    "varchar",
    "text",
    "mediumtext",
    "longtext",
    "integer",
    "bigint",
    "double",
    "boolean",
    "datetime",
    "relationship",
];

fn number_format(n: Number) -> String {
    String::from_utf8_lossy(&php_std::format::number_format(n, 0, b".", b",")).into_owned()
}

impl Attribute {
    /// `new Attribute($attributes, $schemaAttributes, ...)`.
    pub fn new(attributes: &[Document], schema_attributes: &[Document], mut options: AttributeOptions) -> Self {
        if options.max_big_int_length == 0 {
            options.max_big_int_length = options.max_int_length;
        }
        Self {
            attributes: by_key(attributes),
            schema_attributes: by_key(schema_attributes),
            options,
            message: Message::new("Invalid attribute"),
        }
    }

    /// `getDescription()`: the last failure, or `Invalid attribute`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }

    fn fail<T>(&self, error: fn(String) -> Error, message: String) -> Result<T> {
        self.message.set(message.clone());
        Err(error(message))
    }

    /// `isValid($value)` of any value: a document as [`Attribute::check`];
    /// PHP fails on another value when it reads it, an invalid one here.
    pub fn is_valid(&self, value: &Value) -> Result<bool> {
        match as_document(value) {
            Some(attribute) => self.check(attribute),
            None => Ok(false),
        }
    }

    /// `isValid($attribute)`: every check, in PHP's order; `Ok(true)` or the
    /// first failure.
    pub fn check(&self, attribute: &Document) -> Result<bool> {
        self.check_duplicate_id(attribute)?;
        self.check_duplicate_in_schema(attribute)?;
        self.check_required_filters(attribute)?;
        self.check_format(attribute)?;
        self.check_attribute_limits(attribute)?;
        self.check_type(attribute)?;
        self.check_default_value(attribute)?;
        Ok(true)
    }

    /// `checkDuplicateId($attribute)`: no attribute with the same key (any case).
    pub fn check_duplicate_id(&self, attribute: &Document) -> Result<bool> {
        let id = lower(&attribute_key(attribute));
        if self.attributes.iter().any(|(_, existing)| existing.id().to_ascii_lowercase() == id) {
            return self.fail(Error::duplicate, "Attribute already exists in metadata".to_owned());
        }
        Ok(true)
    }

    /// `checkDuplicateInSchema($attribute)`: no column with the same name,
    /// when the adapter has schema attributes (except migrating shared tables).
    pub fn check_duplicate_in_schema(&self, attribute: &Document) -> Result<bool> {
        if !self.options.support_for_schema_attributes || (self.options.shared_tables && self.options.is_migrating) {
            return Ok(true);
        }
        let id = lower(&attribute_key(attribute));
        for (_, schema) in &self.schema_attributes {
            let schema_id = match &self.options.filter {
                Some(filter) => filter(schema.id()),
                None => schema.id().to_owned(),
            };
            if schema_id.to_ascii_lowercase() == id {
                return self.fail(Error::duplicate, "Attribute already exists in schema".to_owned());
            }
        }
        Ok(true)
    }

    /// `checkRequiredFilters($attribute)`: datetimes need the `datetime` filter.
    pub fn check_required_filters(&self, attribute: &Document) -> Result<bool> {
        let kind = attribute.get("type");
        if !kind.is_some_and(|k| k.strict_eq(&Value::from(Database::VAR_DATETIME))) {
            return Ok(true);
        }
        let has = match attribute.get("filters") {
            Some(Value::Array(filters)) => filters.values().any(|f| text(f) == "datetime"),
            _ => false,
        };
        if !has {
            return self.fail(
                Error::database,
                format!(
                    "Attribute of type: {} requires the following filters: datetime",
                    kind.map(text).unwrap_or_default()
                ),
            );
        }
        Ok(true)
    }

    /// `checkFormat($attribute)`: a format registered for the type.
    pub fn check_format(&self, attribute: &Document) -> Result<bool> {
        let Some(format) = attribute.get("format").filter(|f| f.truthy()) else { return Ok(true) };
        let kind = attribute.get("type").map(text).unwrap_or_default();
        if !formats::has(&text(format), &kind) {
            return self.fail(
                Error::database,
                format!("Format (\"{}\") not available for this attribute type (\"{kind}\")", text(format)),
            );
        }
        Ok(true)
    }

    /// `checkAttributeLimits($attribute)`: within the attribute count and
    /// row width, when both measures are given.
    pub fn check_attribute_limits(&self, attribute: &Document) -> Result<bool> {
        let (Some(count), Some(width)) = (&self.options.attribute_count, &self.options.attribute_width) else {
            return Ok(true);
        };
        let count = count(attribute)?;
        let width = width(attribute)?;
        let max_attributes = self.options.max_attributes;
        if max_attributes > 0 && count > max_attributes {
            return self.fail(
                Error::limit,
                format!(
                    "Column limit reached. Cannot create new attribute. Current attribute count is {count} but the maximum is {max_attributes}. Remove some attributes to free up space."
                ),
            );
        }
        let max_width = self.options.max_width;
        if max_width > 0 && width >= max_width {
            return self.fail(
                Error::limit,
                format!(
                    "Row width limit reached. Cannot create new attribute. Current row width is {width} bytes but the maximum is {max_width} bytes. Reduce the size of existing attributes or remove some attributes to free up space."
                ),
            );
        }
        Ok(true)
    }

    fn unknown_type(&self, kind: &Value, with_object: bool) -> Result<bool> {
        let mut supported: Vec<&str> = SUPPORTED.to_vec();
        if self.options.support_for_vectors {
            supported.push(Database::VAR_VECTOR);
        }
        if self.options.support_for_spatial_attributes {
            supported.extend(Database::SPATIAL_TYPES);
        }
        if with_object && self.options.support_for_object {
            supported.push(Database::VAR_OBJECT);
        }
        self.fail(
            Error::database,
            format!("Unknown attribute type: {}. Must be one of {}", text(kind), supported.join(", ")),
        )
    }

    /// `checkType($attribute)`: a supported type, within its size limits.
    pub fn check_type(&self, attribute: &Document) -> Result<bool> {
        let kind = attribute.get("type").cloned().unwrap_or(Value::Null);
        let size = attribute.get("size").cloned().unwrap_or(Value::Int(0));
        let signed = attribute.get("signed").cloned().unwrap_or(Value::Bool(true));
        let array = attribute.get("array").cloned().unwrap_or(Value::Bool(false));
        let default = attribute.get("default").cloned().unwrap_or(Value::Null);
        let over = |limit: i64| size.compare(&Value::Int(limit)) > 0;
        let db = |m: String| self.fail::<bool>(Error::database, m);
        let kind_str = match &kind {
            Value::Str(s) => s.as_str().unwrap_or(""),
            _ => "\0",
        };
        match kind_str {
            Database::VAR_ID
            | Database::VAR_BIGINT
            | Database::VAR_FLOAT
            | Database::VAR_BOOLEAN
            | Database::VAR_DATETIME
            | Database::VAR_RELATIONSHIP => {}
            Database::VAR_STRING => {
                if over(self.options.max_string_length) {
                    return db(format!(
                        "Max size allowed for string is: {}",
                        number_format(Number::Int(self.options.max_string_length))
                    ));
                }
            }
            Database::VAR_VARCHAR => {
                if over(self.options.max_varchar_length) {
                    return db(format!(
                        "Max size allowed for varchar is: {}",
                        number_format(Number::Int(self.options.max_varchar_length))
                    ));
                }
            }
            Database::VAR_TEXT => {
                if over(Database::MAX_TEXT_BYTES) {
                    return db(format!("Max size allowed for text is: {}", Database::MAX_TEXT_BYTES));
                }
            }
            Database::VAR_MEDIUMTEXT => {
                if over(Database::MAX_MEDIUMTEXT_BYTES) {
                    return db(format!("Max size allowed for mediumtext is: {}", Database::MAX_MEDIUMTEXT_BYTES));
                }
            }
            Database::VAR_LONGTEXT => {
                if over(Database::MAX_LONGTEXT_BYTES) {
                    return db(format!("Max size allowed for longtext is: {}", Database::MAX_LONGTEXT_BYTES));
                }
            }
            Database::VAR_INTEGER => {
                let max = self.options.max_int_length;
                let limit = if signed.truthy() {
                    if max % 2 == 0 { Number::Int(max / 2) } else { Number::Float(max as f64 / 2.0) }
                } else {
                    Number::Int(max)
                };
                let limit_value = match limit {
                    Number::Int(i) => Value::Int(i),
                    Number::Float(f) => Value::Float(f),
                };
                if size.compare(&limit_value) > 0 {
                    return db(format!("Max size allowed for int is: {}", number_format(limit)));
                }
            }
            Database::VAR_OBJECT => {
                if !self.options.support_for_object {
                    return db("Object attributes are not supported".to_owned());
                }
                if size.truthy() {
                    return db("Size must be empty for object attributes".to_owned());
                }
                if array.truthy() {
                    return db("Object attributes cannot be arrays".to_owned());
                }
            }
            Database::VAR_POINT | Database::VAR_LINESTRING | Database::VAR_POLYGON => {
                if !self.options.support_for_spatial_attributes {
                    return db("Spatial attributes are not supported".to_owned());
                }
                if size.truthy() {
                    return db("Size must be empty for spatial attributes".to_owned());
                }
                if array.truthy() {
                    return db("Spatial attributes cannot be arrays".to_owned());
                }
            }
            Database::VAR_VECTOR => {
                if !self.options.support_for_vectors {
                    return db("Vector types are not supported by the current database".to_owned());
                }
                if array.truthy() {
                    return db("Vector type cannot be an array".to_owned());
                }
                if size.compare(&Value::Int(0)) <= 0 {
                    return db("Vector dimensions must be a positive integer".to_owned());
                }
                if over(Database::MAX_VECTOR_DIMENSIONS) {
                    return db(format!("Vector dimensions cannot exceed {}", Database::MAX_VECTOR_DIMENSIONS));
                }
                if !matches!(default, Value::Null) {
                    let Value::Array(components) = &default else {
                        return db("Vector default value must be an array".to_owned());
                    };
                    if !Value::Int(components.len() as i64).strict_eq(&size) {
                        return db(format!("Vector default value must have exactly {} elements", text(&size)));
                    }
                    if !components.values().all(is_numeric) {
                        return db("Vector default value must contain only numeric elements".to_owned());
                    }
                }
            }
            _ => return self.unknown_type(&kind, true),
        }
        Ok(true)
    }

    /// `checkDefaultValue($attribute)`: no default on a required attribute,
    /// an array default only for arrays (and vectors, objects, spatial
    /// types), and defaults of the attribute's type.
    pub fn check_default_value(&self, attribute: &Document) -> Result<bool> {
        let Some(default) = attribute.get("default") else { return Ok(true) };
        let required = attribute.get("required").cloned().unwrap_or(Value::Bool(false));
        let kind = attribute.get("type").cloned().unwrap_or(Value::Null);
        let array = attribute.get("array").is_some_and(Value::truthy);
        let signed = attribute.get("signed").is_none_or(Value::truthy);
        if required.strict_eq(&Value::Bool(true)) {
            return self.fail(Error::database, "Cannot set a default value for a required attribute".to_owned());
        }
        let array_kinds = [Database::VAR_VECTOR, Database::VAR_OBJECT, "point", "linestring", "polygon"];
        if matches!(default, Value::Array(_)) && !array && !array_kinds.iter().any(|k| kind.strict_eq(&Value::from(*k)))
        {
            return self
                .fail(Error::database, "Cannot set an array default value for a non-array attribute".to_owned());
        }
        self.validate_default_types(&text(&kind), default, signed)?;
        Ok(true)
    }

    fn validate_default_types(&self, kind: &str, default: &Value, signed: bool) -> Result<()> {
        let default_type = default.type_name();
        if let Value::Null = default {
            return Ok(());
        }
        if let Value::Array(items) = default {
            if !Database::SPATIAL_TYPES.contains(&kind) && kind != Database::VAR_OBJECT {
                for value in items.values() {
                    self.validate_default_types(kind, value, signed)?;
                }
            }
            return Ok(());
        }
        let mismatch = || format!("Default value {} does not match given type {kind}", text(default));
        match kind {
            "string" | "varchar" | "text" | "mediumtext" | "longtext" => {
                if default_type != "string" {
                    return self.fail(Error::database, mismatch());
                }
            }
            Database::VAR_INTEGER | Database::VAR_FLOAT | Database::VAR_BOOLEAN => {
                if kind != default_type {
                    return self.fail(Error::database, mismatch());
                }
            }
            Database::VAR_BIGINT => {
                if default_type != "integer" && default_type != "string" {
                    return self.fail(Error::database, mismatch());
                }
                if let Value::Str(s) = default
                    && !BigInt::is_integer_string(s.as_bytes(), signed)
                {
                    return self.fail(
                        Error::database,
                        format!("Default value {} is not a valid integer string for type bigint", text(default)),
                    );
                }
            }
            Database::VAR_DATETIME => {
                if default_type != "string" {
                    return self.fail(Error::database, mismatch());
                }
            }
            Database::VAR_VECTOR => {
                if default_type != "double" && default_type != "integer" {
                    return self.fail(
                        Error::database,
                        "Vector components must be numeric values (float or integer)".to_owned(),
                    );
                }
            }
            _ => {
                self.unknown_type(&Value::from(kind), false)?;
            }
        }
        Ok(())
    }
}

crate::validator::validator!(Attribute, utopia_validators::Type::Object, false, fallible);
