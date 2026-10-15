//! [`AttributeDefinition`]: attribute definitions before they are created.
//!
//! PHP: `Utopia\Database\Validator\AttributeDefinition`.

use std::fmt;

use php_std::value::Number;
use utopia_query::schema::ColumnType;

use crate::adapter::{Feature, Profile};
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::filter::Filter;
use crate::schema::Column;
use crate::validator::big_int::BigInt;
use crate::validator::spatial::Spatial;
use crate::validator::structure::{formats, is_integer_string};
use crate::validator::{Message, is_numeric};
use crate::value::{Ext, Value};

/// Counts the collection's attributes, or measures its row width, with a
/// new attribute (given as its stored document).
///
/// PHP: `$attributeCount` / `$attributeWidth` (`Closure(Document): int`).
pub type Measure = Box<dyn Fn(&Document) -> Result<i64> + Send + Sync>;

/// The name the adapter gives an attribute's column (PHP: `$filter`,
/// `Closure(string): string`).
pub type NameFilter = Box<dyn Fn(&str) -> String + Send + Sync>;

const STRING_TYPES: [ColumnType; 5] =
    [ColumnType::String, ColumnType::Varchar, ColumnType::Text, ColumnType::MediumText, ColumnType::LongText];

const SPATIAL_TYPES: [ColumnType; 3] = [ColumnType::Point, ColumnType::Linestring, ColumnType::Polygon];

/// An attribute definition the collection can take: a new key (in the
/// metadata and, with [`Capability::SchemaIntrospection`], in the table),
/// the filters and format its type needs, a supported type of a valid size,
/// within the attribute count and row width limits, and a default of its
/// type.
///
/// Every failure is an error, as PHP throws: [`crate::Kind::Duplicate`]
/// for an existing key, [`crate::Kind::Limit`] for a limit, and
/// [`crate::Kind::Database`] otherwise; the description is its message.
///
/// ```
/// use std::sync::Arc;
/// use utopia_database::Attribute;
/// use utopia_database::adapter::{Features, Limits, Profile};
/// use utopia_database::capability::Capabilities;
/// use utopia_database::validator::attribute_definition::AttributeDefinition;
/// use utopia_database::validator::datetime::{default_max, default_min};
/// use utopia_query::schema::ColumnType;
///
/// let limits = Limits {
///     string: 1000, varchar: 0, integer: 0, big_integer: 0, attributes: 0, indexes: 0,
///     default_attributes: 0, default_indexes: 0, index_length: 0, uid_length: 36, document_size: 0,
///     min_date_time: default_min(), max_date_time: default_max(),
///     id_type: ColumnType::Integer, keywords: vec![], internal_index_keys: vec![],
/// };
/// let profile = Profile::new(Arc::new(limits), Capabilities::EMPTY, Features::EMPTY, false, false, None);
/// let validator = AttributeDefinition::new(vec![], profile);
/// assert_eq!(validator.check(&Attribute::string("title", 2000)).unwrap_err().message(), "Max size allowed for string is: 1,000");
/// assert!(validator.check(&Attribute::string("title", 255)).unwrap());
/// ```
///
/// PHP: `Utopia\Database\Validator\AttributeDefinition` (`new
/// AttributeDefinition($attributes, $profile, $schemaAttributes,
/// $attributeCount, $attributeWidth, $filter)`); its public `check*`
/// methods are the steps of [`AttributeDefinition::check`].
pub struct AttributeDefinition {
    attributes: Vec<(String, Attribute)>,
    profile: Profile,
    schema_attributes: Vec<String>,
    attribute_count: Option<Measure>,
    attribute_width: Option<Measure>,
    filter: Option<NameFilter>,
    message: Message,
}

impl fmt::Debug for AttributeDefinition {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("AttributeDefinition")
            .field("attributes", &self.attributes)
            .field("profile", &self.profile)
            .field("schema_attributes", &self.schema_attributes)
            .finish()
    }
}

/// `number_format($n)`.
fn number_format(n: Number) -> String {
    String::from_utf8_lossy(&php_std::format::number_format(n, 0, b".", b",")).into_owned()
}

/// What an attribute document declared, before the model normalised it.
#[derive(Default)]
struct Declared {
    size: Option<i64>,
    array: Option<bool>,
    filters: Option<Vec<String>>,
}

impl Declared {
    /// `declaredSize()`, `declaredArray()`, `declaredFilters()`.
    fn of(declaration: &Document) -> Self {
        let size = match declaration.get("size") {
            Some(v) if is_numeric(v) => crate::value::to_long(v),
            _ => 0,
        };
        let array = declaration.get("array").is_some_and(Value::truthy);
        let filters = match declaration.get("filters") {
            None => Vec::new(),
            Some(Value::Array(a)) => a.values().map(crate::value::to_text).collect(),
            Some(_) => Vec::new(),
        };
        Declared { size: Some(size), array: Some(array), filters: Some(filters) }
    }
}

impl AttributeDefinition {
    /// `new AttributeDefinition($attributes, $profile)`: `attributes` are
    /// the collection's attributes (keyed by lower-cased key, a later one
    /// replacing an earlier one).
    pub fn new(attributes: Vec<Attribute>, profile: Profile) -> Self {
        let mut keyed: Vec<(String, Attribute)> = Vec::with_capacity(attributes.len());
        for attribute in attributes {
            let key = attribute.key.to_ascii_lowercase();
            match keyed.iter_mut().find(|(k, _)| *k == key) {
                Some(entry) => entry.1 = attribute,
                None => keyed.push((key, attribute)),
            }
        }
        Self {
            attributes: keyed,
            profile,
            schema_attributes: Vec::new(),
            attribute_count: None,
            attribute_width: None,
            filter: None,
            message: Message::new("Invalid attribute"),
        }
    }

    /// `new AttributeDefinition(...)` of stored attribute documents.
    ///
    /// Fails as [`Attribute::from_document`] does.
    pub fn from_documents(attributes: &[Document], profile: Profile) -> Result<Self> {
        let attributes = attributes.iter().map(Attribute::from_document).collect::<Result<Vec<_>>>()?;
        Ok(Self::new(attributes, profile))
    }

    /// The same validator with `$schemaAttributes`: the engine's columns,
    /// read only for their names.
    #[must_use]
    pub fn schema_attributes(mut self, columns: &[Column]) -> Self {
        self.schema_attributes = columns.iter().map(|c| c.name.clone()).collect();
        self
    }

    /// The same validator with `$attributeCount` and `$attributeWidth`
    /// (the limits are checked only when both are given).
    #[must_use]
    pub fn measures(mut self, count: Option<Measure>, width: Option<Measure>) -> Self {
        self.attribute_count = count;
        self.attribute_width = width;
        self
    }

    /// The same validator with `$filter`.
    #[must_use]
    pub fn filter(mut self, filter: NameFilter) -> Self {
        self.filter = Some(filter);
        self
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

    /// `isValid($value)` of any value: an attribute document as
    /// [`AttributeDefinition::check_document`]; anything else is invalid
    /// (`Value must be an attribute`).
    pub fn is_valid(&self, value: &Value) -> Result<bool> {
        match value {
            Value::Ext(Ext::Document(d)) => self.check_document(d),
            _ => {
                self.message.set("Value must be an attribute");
                Ok(false)
            }
        }
    }

    /// `isValid($document)`: the attribute the document declares, checked
    /// against its declared size, array flag and filters (the model
    /// normalises them away for types that take none).
    ///
    /// Fails ([`crate::Kind::Database`], with the error as its previous)
    /// when the document is not a valid attribute, and as
    /// [`AttributeDefinition::check`] does.
    pub fn check_document(&self, declaration: &Document) -> Result<bool> {
        let attribute = match Attribute::from_document(declaration) {
            Ok(a) => a,
            Err(error) => {
                let message = error.message().to_owned();
                self.message.set(message.clone());
                return Err(Error::database(message).with_previous(error));
            }
        };
        self.run(&attribute, Declared::of(declaration))
    }

    /// `isValid($attribute)`: every check, in PHP's order; `Ok(true)` or the
    /// first failure.
    pub fn check(&self, attribute: &Attribute) -> Result<bool> {
        self.run(attribute, Declared::default())
    }

    fn run(&self, attribute: &Attribute, declared: Declared) -> Result<bool> {
        self.check_duplicate_id(attribute)?;
        self.check_duplicate_in_schema(attribute)?;
        self.check_required_filters(attribute, declared.filters.as_deref())?;
        self.check_format(attribute)?;
        self.check_type(attribute, declared.size, declared.array)?;
        self.check_attribute_limits(attribute)?;
        self.check_default_value(attribute)?;
        Ok(true)
    }

    /// `checkDuplicateId($attribute)`: no attribute with the same key (any
    /// case).
    ///
    /// Fails ([`crate::Kind::Duplicate`]) with `Attribute already exists in
    /// metadata`.
    pub fn check_duplicate_id(&self, attribute: &Attribute) -> Result<bool> {
        let id = attribute.key.to_ascii_lowercase();
        if self.attributes.iter().any(|(_, existing)| existing.key.to_ascii_lowercase() == id) {
            return self.fail(Error::duplicate, "Attribute already exists in metadata".to_owned());
        }
        Ok(true)
    }

    /// `checkDuplicateInSchema($attribute)`: no column of the same name,
    /// with [`Capability::SchemaIntrospection`] (unless migrating shared
    /// tables).
    ///
    /// Fails ([`crate::Kind::Duplicate`]) with `Attribute already exists in
    /// schema`.
    pub fn check_duplicate_in_schema(&self, attribute: &Attribute) -> Result<bool> {
        if !self.profile.supports(Capability::SchemaIntrospection) {
            return Ok(true);
        }
        if self.profile.shared_tables && self.profile.migrating {
            return Ok(true);
        }
        let id = attribute.key.to_ascii_lowercase();
        for column in &self.schema_attributes {
            let name = match &self.filter {
                Some(filter) => filter(column),
                None => column.clone(),
            };
            if name.to_ascii_lowercase() == id {
                return self.fail(Error::duplicate, "Attribute already exists in schema".to_owned());
            }
        }
        Ok(true)
    }

    /// `checkRequiredFilters($attribute, $filters)`: the filters its type
    /// needs are among `filters` (the declared ones, before the model adds
    /// the type's filter), else among the attribute's.
    ///
    /// Fails ([`crate::Kind::Database`]) with `Attribute of type: <type>
    /// requires the following filters: <filters>`.
    pub fn check_required_filters(&self, attribute: &Attribute, filters: Option<&[String]>) -> Result<bool> {
        let required = Self::required_filters(attribute.kind);
        let filters = filters.unwrap_or(&attribute.filters);
        if required.iter().any(|r| !filters.iter().any(|f| f == r)) {
            return self.fail(
                Error::database,
                format!(
                    "Attribute of type: {} requires the following filters: {}",
                    attribute.kind.as_str(),
                    required.join(",")
                ),
            );
        }
        Ok(true)
    }

    /// `getRequiredFilters($type)`: `datetime` for datetimes.
    pub fn required_filters(kind: ColumnType) -> Vec<&'static str> {
        match kind {
            ColumnType::Datetime => vec![Filter::Datetime.as_str()],
            _ => Vec::new(),
        }
    }

    /// `checkFormat($attribute)`: a format registered for the type.
    ///
    /// Fails ([`crate::Kind::Database`]) with `Format ("<format>") not
    /// available for this attribute type ("<type>")`.
    pub fn check_format(&self, attribute: &Attribute) -> Result<bool> {
        if let Some(format) = &attribute.format
            && !format.name.is_empty()
            && format.name != "0"
            && !formats::has(&format.name, attribute.kind)
        {
            return self.fail(
                Error::database,
                format!(
                    "Format (\"{}\") not available for this attribute type (\"{}\")",
                    format.name,
                    attribute.kind.as_str()
                ),
            );
        }
        Ok(true)
    }

    /// `checkAttributeLimits($attribute)`: within the attribute count and
    /// row width, when both measures are given.
    ///
    /// Fails ([`crate::Kind::Limit`]) past either limit, and as the
    /// measures do.
    pub fn check_attribute_limits(&self, attribute: &Attribute) -> Result<bool> {
        let (Some(count), Some(width)) = (&self.attribute_count, &self.attribute_width) else {
            return Ok(true);
        };
        let document = attribute.to_document()?;
        let count = count(&document)?;
        let width = width(&document)?;
        let max_attributes = self.profile.limits.attributes;
        let max_width = self.profile.limits.document_size;
        if max_attributes > 0 && count > max_attributes {
            return self.fail(
                Error::limit,
                format!(
                    "Column limit reached. Cannot create new attribute. Current attribute count is {count} but the maximum is {max_attributes}. Remove some attributes to free up space."
                ),
            );
        }
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

    /// `checkType($attribute, $size, $array)`: a supported type, within
    /// its size limits; `size` and `array` are the declared ones when given.
    ///
    /// Fails ([`crate::Kind::Database`]) with the reason.
    pub fn check_type(&self, attribute: &Attribute, size: Option<i64>, array: Option<bool>) -> Result<bool> {
        let kind = attribute.kind;
        let size = size.unwrap_or(attribute.size.unwrap_or(0));
        let array = array.unwrap_or(attribute.array);
        let limits = &self.profile.limits;
        let db = |m: String| self.fail::<bool>(Error::database, m);
        match kind {
            ColumnType::Id
            | ColumnType::BigInteger
            | ColumnType::Float
            | ColumnType::Double
            | ColumnType::Boolean
            | ColumnType::Datetime
            | ColumnType::Relationship => {}
            ColumnType::String => {
                if size > limits.string {
                    return db(format!(
                        "Max size allowed for string is: {}",
                        number_format(Number::Int(limits.string))
                    ));
                }
            }
            ColumnType::Varchar => {
                if size > limits.varchar {
                    return db(format!(
                        "Max size allowed for varchar is: {}",
                        number_format(Number::Int(limits.varchar))
                    ));
                }
            }
            ColumnType::Text => {
                if size > database::MAX_TEXT_BYTES {
                    return db("Max size allowed for text is: 65535".to_owned());
                }
            }
            ColumnType::MediumText => {
                if size > database::MAX_MEDIUMTEXT_BYTES {
                    return db("Max size allowed for mediumtext is: 16777215".to_owned());
                }
            }
            ColumnType::LongText => {
                if size > database::MAX_LONGTEXT_BYTES {
                    return db("Max size allowed for longtext is: 4294967295".to_owned());
                }
            }
            ColumnType::Integer => {
                // `$integer / 2` is a float when the integer is odd.
                let max = limits.integer;
                let limit = if attribute.signed {
                    if max % 2 == 0 { Number::Int(max / 2) } else { Number::Float(max as f64 / 2.0) }
                } else {
                    Number::Int(max)
                };
                let over = match limit {
                    Number::Int(l) => size > l,
                    Number::Float(l) => (size as f64) > l,
                };
                if over {
                    return db(format!("Max size allowed for int is: {}", number_format(limit)));
                }
            }
            ColumnType::Object => {
                if !self.profile.supports(Capability::Objects) {
                    return db("Object attributes are not supported".to_owned());
                }
                if size != 0 {
                    return db("Size must be empty for object attributes".to_owned());
                }
                if array {
                    return db("Object attributes cannot be arrays".to_owned());
                }
            }
            ColumnType::Point | ColumnType::Linestring | ColumnType::Polygon => {
                if !self.profile.has_feature(Feature::Spatial) {
                    return db("Spatial attributes are not supported".to_owned());
                }
                if size != 0 {
                    return db("Size must be empty for spatial attributes".to_owned());
                }
                if array {
                    return db("Spatial attributes cannot be arrays".to_owned());
                }
            }
            ColumnType::Vector => {
                if !self.profile.supports(Capability::Vectors) {
                    return db("Vector types are not supported by the current database".to_owned());
                }
                if array {
                    return db("Vector type cannot be an array".to_owned());
                }
                if size <= 0 {
                    return db("Vector dimensions must be a positive integer".to_owned());
                }
                if size > database::MAX_VECTOR_DIMENSIONS {
                    return db(format!("Vector dimensions cannot exceed {}", database::MAX_VECTOR_DIMENSIONS));
                }
                match &attribute.default {
                    Value::Null => {}
                    Value::Array(components) => {
                        if components.len() as i64 != size {
                            return db(format!("Vector default value must have exactly {size} elements"));
                        }
                        if !components.values().all(is_numeric) {
                            return db("Vector default value must contain only numeric elements".to_owned());
                        }
                    }
                    _ => return db("Vector default value must be an array".to_owned()),
                }
            }
            _ => {
                let available: Vec<&str> =
                    Attribute::available_types(&self.profile).into_iter().map(Attribute::stored_type).collect();
                return db(format!(
                    "Unknown attribute type: {}. Must be one of {}",
                    kind.as_str(),
                    available.join(", ")
                ));
            }
        }
        Ok(true)
    }

    /// `checkDefaultValue($attribute)`: no default on a required attribute,
    /// a JSON-encodable default for a `json`-filtered string attribute, an
    /// array default only for arrays (and vectors, objects, spatial types),
    /// and defaults of the attribute's type.
    ///
    /// Fails ([`crate::Kind::Database`]) with the reason.
    pub fn check_default_value(&self, attribute: &Attribute) -> Result<bool> {
        let default = &attribute.default;
        if matches!(default, Value::Null) {
            return Ok(true);
        }
        if attribute.required {
            return self.fail(Error::database, "Cannot set a default value for a required attribute".to_owned());
        }
        if Self::is_json_document_default(attribute) {
            self.check_json_encodable(attribute)?;
            return Ok(true);
        }
        let array_kinds =
            [ColumnType::Vector, ColumnType::Object, ColumnType::Point, ColumnType::Linestring, ColumnType::Polygon];
        if matches!(default, Value::Array(_)) && !attribute.array && !array_kinds.contains(&attribute.kind) {
            return self
                .fail(Error::database, "Cannot set an array default value for a non-array attribute".to_owned());
        }
        self.validate_default_types(attribute.kind, default, attribute.signed)?;
        Ok(true)
    }

    /// `isJsonDocumentDefault()`: the `json` filter writes arrays, objects
    /// and documents to a string column as their JSON.
    fn is_json_document_default(attribute: &Attribute) -> bool {
        attribute.filters.iter().any(|f| f == Filter::Json.as_str())
            && !attribute.array
            && STRING_TYPES.contains(&attribute.kind)
            && matches!(attribute.default, Value::Array(_) | Value::Object(_) | Value::Ext(Ext::Document(_)))
    }

    /// `checkJsonEncodable()`.
    fn check_json_encodable(&self, attribute: &Attribute) -> Result<()> {
        let value = match &attribute.default {
            Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy()),
            other => other.clone(),
        };
        if let Err(error) = php_std::json::encode(&value, php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH) {
            return self.fail(
                Error::database,
                format!(
                    "Default value of json attribute \"{}\" is not JSON-encodable: {}",
                    attribute.key,
                    error.code().message()
                ),
            );
        }
        Ok(())
    }

    /// `defaultText()`: a scalar as PHP prints it (a string unquoted),
    /// anything else as its JSON.
    fn default_text(default: &Value) -> String {
        match default {
            Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_) => crate::value::to_text(default),
            // json_encode() of an ArrayObject: it has no public properties.
            Value::Ext(Ext::Document(_)) => "{}".to_owned(),
            other => php_std::json::encode(other, php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH)
                .unwrap_or_default(),
        }
    }

    /// `validateDefaultTypes($type, $default, $signed)`: a default (or each
    /// element of an array default) of the type.
    ///
    /// Fails ([`crate::Kind::Database`]) with the mismatch.
    pub fn validate_default_types(&self, kind: ColumnType, default: &Value, signed: bool) -> Result<()> {
        let default_type = default.type_name();
        if let Value::Null = default {
            return Ok(());
        }
        if let Value::Array(items) = default {
            if SPATIAL_TYPES.contains(&kind) {
                let spatial = Spatial::new(kind.as_str());
                if !spatial.is_valid(default) {
                    return self.fail(Error::database, format!("Invalid default value: {}", spatial.description()));
                }
                return Ok(());
            }
            if kind != ColumnType::Object {
                for value in items.values() {
                    self.validate_default_types(kind, value, signed)?;
                }
            }
            return Ok(());
        }
        let mismatch = || {
            format!(
                "Default value {} does not match given type {}",
                Self::default_text(default),
                Attribute::stored_type(kind)
            )
        };
        match kind {
            ColumnType::String
            | ColumnType::Varchar
            | ColumnType::Text
            | ColumnType::MediumText
            | ColumnType::LongText
            | ColumnType::Datetime => {
                if default_type != "string" {
                    return self.fail(Error::database, mismatch());
                }
            }
            ColumnType::Integer | ColumnType::Boolean => {
                if kind.as_str() != default_type {
                    return self.fail(Error::database, mismatch());
                }
            }
            ColumnType::BigInteger => {
                let validator = BigInt::new(signed, self.profile.supports(Capability::UnsignedBigInt));
                if !validator.is_valid(default) {
                    let message = match default {
                        Value::Str(s) if !is_integer_string(s.as_bytes(), signed) => format!(
                            "Default value {} is not a valid integer string for type bigint",
                            crate::value::to_text(default)
                        ),
                        _ => mismatch(),
                    };
                    return self.fail(Error::database, message);
                }
            }
            ColumnType::Float | ColumnType::Double => {
                if default_type != "double" {
                    return self.fail(Error::database, mismatch());
                }
            }
            ColumnType::Vector => {
                if default_type != "double" && default_type != "integer" {
                    return self.fail(
                        Error::database,
                        "Vector components must be numeric values (float or integer)".to_owned(),
                    );
                }
            }
            _ => {
                let mut supported = vec![
                    ColumnType::String.as_str(),
                    ColumnType::Varchar.as_str(),
                    ColumnType::Text.as_str(),
                    ColumnType::MediumText.as_str(),
                    ColumnType::LongText.as_str(),
                    ColumnType::Integer.as_str(),
                    Attribute::stored_type(ColumnType::BigInteger),
                    ColumnType::Float.as_str(),
                    ColumnType::Double.as_str(),
                    ColumnType::Boolean.as_str(),
                    ColumnType::Datetime.as_str(),
                    ColumnType::Relationship.as_str(),
                ];
                if self.profile.supports(Capability::Vectors) {
                    supported.push(ColumnType::Vector.as_str());
                }
                if self.profile.has_feature(Feature::Spatial) {
                    supported.extend(SPATIAL_TYPES.iter().map(|t| t.as_str()));
                }
                return self.fail(
                    Error::database,
                    format!("Unknown attribute type: {}. Must be one of {}", kind.as_str(), supported.join(", ")),
                );
            }
        }
        Ok(())
    }
}

super::validator!(AttributeDefinition, utopia_validators::Type::Object, false, fallible);
