//! [`Structure`]: documents against their collection's attributes; and
//! the registry of attribute [`formats`].
//!
//! PHP: `Utopia\Database\Validator\Structure`.

use php_std::datetime::DateTime;
use php_std::value::Number;
use utopia_validators::{Boolean, Float, Integer, Range, Text, Type, Validator};

use crate::database::Database;
use crate::document::Document;
use crate::error::Result;
use crate::validator::big_int::BigInt;
use crate::validator::byte_length::ByteLength;
use crate::validator::datetime::{Datetime, default_max, default_min};
use crate::validator::object::ObjectValidator;
use crate::validator::operator::Operator as OperatorValidator;
use crate::validator::sequence::Sequence;
use crate::validator::spatial::Spatial;
use crate::validator::vector::Vector;
use crate::validator::{Message, array_key, index, index_or, rejection, text};
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value};

/// The registry of attribute formats: named validators an attribute's
/// `format` selects, for one attribute type (an `email` format of
/// `string` attributes, an `enum` of `string`, a `range` of `integer`).
///
/// The registry is process-wide, as PHP's static one: register formats at
/// startup.
///
/// ```
/// use std::sync::Arc;
/// use utopia_database::validator::structure::formats;
/// use utopia_validators::Text;
///
/// formats::add("short", Arc::new(|_attribute| Box::new(Text::new(5))), "string");
/// assert!(formats::has("short", "string"));
/// assert!(!formats::has("short", "integer"));
/// formats::remove("short");
/// ```
///
/// PHP: `Structure::addFormat`, `getFormats`, `hasFormat`, `getFormat`,
/// `removeFormat` (and the static `$formats`).
pub mod formats {
    use std::sync::{Arc, RwLock};

    use crate::document::Document;
    use crate::error::{Error, Result};

    /// Builds a format's validator for an attribute (its definition: `size`,
    /// `formatOptions`, ...).
    ///
    /// PHP: the `Closure` given to `addFormat`, called with the attribute.
    pub type Factory = Arc<dyn Fn(&Document) -> Box<dyn utopia_validators::Validator> + Send + Sync>;

    /// A registered format.
    #[derive(Clone)]
    pub struct Format {
        /// The name attributes give in `format`.
        pub name: String,
        /// The attribute type the format applies to.
        pub kind: String,
        /// Builds the validator.
        pub factory: Factory,
    }

    static FORMATS: RwLock<Vec<Format>> = RwLock::new(Vec::new());

    fn read() -> std::sync::RwLockReadGuard<'static, Vec<Format>> {
        FORMATS.read().unwrap_or_else(|e| e.into_inner())
    }

    /// `Structure::addFormat($name, $callback, $type)`: registers (or
    /// replaces) a format.
    pub fn add(name: &str, factory: Factory, kind: &str) {
        let mut formats = FORMATS.write().unwrap_or_else(|e| e.into_inner());
        let format = Format { name: name.to_owned(), kind: kind.to_owned(), factory };
        match formats.iter_mut().find(|f| f.name == name) {
            Some(existing) => *existing = format,
            None => formats.push(format),
        }
    }

    /// `Structure::getFormats()`: every format, in registration order.
    pub fn all() -> Vec<Format> {
        read().clone()
    }

    /// `Structure::hasFormat($name, $type)`.
    pub fn has(name: &str, kind: &str) -> bool {
        read().iter().any(|f| f.name == name && f.kind == kind)
    }

    /// `Structure::getFormat($name, $type)`.
    ///
    /// Fails ([`crate::Kind::Database`]) with `Format "..." not available
    /// for attribute type "..."` or `Unknown format validator "..."`.
    pub fn get(name: &str, kind: &str) -> Result<Format> {
        match read().iter().find(|f| f.name == name) {
            Some(f) if f.kind == kind => Ok(f.clone()),
            Some(_) => Err(Error::database(format!("Format \"{name}\" not available for attribute type \"{kind}\""))),
            None => Err(Error::database(format!("Unknown format validator \"{name}\""))),
        }
    }

    /// `Structure::removeFormat($name)`.
    pub fn remove(name: &str) {
        FORMATS.write().unwrap_or_else(|e| e.into_inner()).retain(|f| f.name != name);
    }
}

/// The options of [`Structure::with_options`] past the collection and the
/// id type, with PHP's defaults.
#[derive(Clone, Debug)]
pub struct StructureOptions {
    /// `$minAllowedDate` (`0000-01-01`).
    pub min_allowed_date: DateTime,
    /// `$maxAllowedDate` (`9999-12-31`).
    pub max_allowed_date: DateTime,
    /// `$supportForAttributes` (true).
    pub support_for_attributes: bool,
    /// `$supportUnsignedBigInt` (true).
    pub support_unsigned_big_int: bool,
    /// `$currentDocument` (none): the stored document an update changes,
    /// whose `null` attributes may stay `null` though now required.
    pub current_document: Option<Document>,
}

impl Default for StructureOptions {
    fn default() -> Self {
        Self {
            min_allowed_date: default_min(),
            max_allowed_date: default_max(),
            support_for_attributes: true,
            support_unsigned_big_int: true,
            current_document: None,
        }
    }
}

/// The system attributes every document has (`Structure::$attributes`),
/// built once.
pub(crate) fn internal_attributes() -> &'static [Value] {
    static INTERNAL: std::sync::LazyLock<Vec<Value>> = std::sync::LazyLock::new(build_internal_attributes);
    &INTERNAL
}

/// The attributes a document is checked against, by `$id` (a later
/// attribute replaces an earlier one of the same id), borrowed from the
/// collection: PHP's `$keys` array.
#[derive(Default)]
pub(crate) struct Keys<'a>(Vec<(ArrayKey, &'a Value)>);

impl<'a> Keys<'a> {
    /// `$keys[$id] = $attribute`.
    pub(crate) fn set(&mut self, key: ArrayKey, attribute: &'a Value) {
        match self.0.iter_mut().find(|(k, _)| *k == key) {
            Some(entry) => entry.1 = attribute,
            None => self.0.push((key, attribute)),
        }
    }

    /// `$keys[$key] ?? null`.
    pub(crate) fn get(&self, key: KeyRef<'_>) -> Option<&'a Value> {
        self.0.iter().find(|(k, _)| k.as_ref() == key).map(|(_, a)| *a)
    }
}

fn build_internal_attributes() -> Vec<Value> {
    let attr = |id: &str, kind: &str, size: i64, required: bool, signed: bool, array: bool, default: bool| {
        let mut a = Array::new();
        a.set(ArrayKey::from("$id"), Value::from(id));
        a.set(ArrayKey::from("type"), Value::from(kind));
        a.set(ArrayKey::from("size"), Value::Int(size));
        a.set(ArrayKey::from("required"), Value::Bool(required));
        if default {
            a.set(ArrayKey::from("default"), Value::Null);
        }
        a.set(ArrayKey::from("signed"), Value::Bool(signed));
        a.set(ArrayKey::from("array"), Value::Bool(array));
        a.set(ArrayKey::from("filters"), Value::Array(Array::new()));
        Value::Array(a)
    };
    vec![
        attr("$id", Database::VAR_STRING, 255, false, true, false, false),
        attr("$sequence", Database::VAR_ID, 0, false, true, false, false),
        attr("$collection", Database::VAR_STRING, 255, true, true, false, false),
        attr("$tenant", Database::VAR_ID, 0, false, true, false, true),
        attr("$permissions", Database::VAR_STRING, 67000, false, true, true, false),
        attr("$createdAt", Database::VAR_DATETIME, 0, true, false, false, false),
        attr("$updatedAt", Database::VAR_DATETIME, 0, true, false, false, false),
    ]
}

/// A document valid for its collection: it names a collection, has every
/// required attribute (unless the current document holds `null` there),
/// has no unknown attribute (with a schema), and each value suits its
/// attribute: type, size, signedness, array-ness and format; operators
/// suit their attribute ([`OperatorValidator`]).
///
/// ```
/// use utopia_database::Document;
/// use utopia_database::validator::structure::Structure;
/// use utopia_database::value::{Value, list, string};
///
/// let title = Document::from_pairs([
///     ("$id", string("title")), ("type", string("string")), ("size", Value::Int(10)),
///     ("required", Value::Bool(true)), ("array", Value::Bool(false)),
/// ]).unwrap();
/// let collection = Document::from_pairs([
///     ("$id", string("movies")), ("$collection", string("_metadata")), ("attributes", list([title.into()])),
/// ]).unwrap();
/// let structure = Structure::new(collection, "integer");
/// let mut movie = Document::from_pairs([
///     ("$collection", string("movies")), ("$createdAt", string("2024-01-01 00:00:00")),
///     ("$updatedAt", string("2024-01-01 00:00:00")), ("title", string("Alien")),
/// ]).unwrap();
/// assert!(structure.validate(&mut movie).unwrap());
/// movie.set("title", string("The Empire Strikes Back"));
/// assert!(!structure.validate(&mut movie).unwrap());
/// assert_eq!(
///     structure.description(),
///     "Invalid document structure: Attribute \"title\" has invalid type. Value must be a valid string and no longer than 10 chars"
/// );
/// ```
///
/// PHP: `Utopia\Database\Validator\Structure` (`new Structure($collection,
/// $idAttributeType, $minAllowedDate, $maxAllowedDate, $supportForAttributes,
/// $supportUnsignedBigInt, $currentDocument)`). Validation changes the
/// document as PHP's does: a `bigint` numeric string that fits a PHP
/// integer becomes one, and operators learn their attribute's name.
#[derive(Clone, Debug)]
pub struct Structure {
    pub(crate) collection: Document,
    id_attribute_type: String,
    min_allowed_date: DateTime,
    max_allowed_date: DateTime,
    support_for_attributes: bool,
    support_unsigned_big_int: bool,
    current_document: Option<Document>,
    pub(crate) message: Message,
}

impl Structure {
    /// `new Structure($collection, $idAttributeType)` with PHP's defaults.
    pub fn new(collection: Document, id_attribute_type: impl Into<String>) -> Self {
        Self::with_options(collection, id_attribute_type, StructureOptions::default())
    }

    /// `new Structure(...)` with every option.
    pub fn with_options(collection: Document, id_attribute_type: impl Into<String>, options: StructureOptions) -> Self {
        Self {
            collection,
            id_attribute_type: id_attribute_type.into(),
            min_allowed_date: options.min_allowed_date,
            max_allowed_date: options.max_allowed_date,
            support_for_attributes: options.support_for_attributes,
            support_unsigned_big_int: options.support_unsigned_big_int,
            current_document: options.current_document,
            message: Message::new("General Error"),
        }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        format!("Invalid document structure: {}", self.message.get())
    }

    /// `getType()`: `array`.
    pub fn type_name(&self) -> &'static str {
        "array"
    }

    pub(crate) fn fail(&self, message: impl Into<String>) -> Result<bool> {
        self.message.set(message);
        Ok(false)
    }

    /// `isValid($value)` of any value: a document as
    /// [`Structure::validate`] (leaving it as it is), anything else
    /// invalid.
    pub fn is_valid(&self, value: &Value) -> Result<bool> {
        match value {
            Value::Ext(Ext::Document(d)) => match self.structure_of(d) {
                Some(structure) => self.check(&structure, None),
                None => Ok(false),
            },
            _ => self.fail("Value must be an instance of Document"),
        }
    }

    /// `isValid($document)`.
    ///
    /// Fails ([`crate::Kind::Database`]) when an attribute names a format
    /// that is not registered for its type.
    pub fn validate(&self, document: &mut Document) -> Result<bool> {
        match self.structure_of(document) {
            Some(structure) => self.check(&structure, Some(document)),
            None => Ok(false),
        }
    }

    /// The document's attributes to check (`getArrayCopy()`), or `None`
    /// (the message set) when it names no collection or the collection is
    /// not one.
    fn structure_of(&self, document: &Document) -> Option<Array> {
        if document.collection().is_empty() || document.collection() == "0" {
            self.message.set("Missing collection attribute $collection");
            return None;
        }
        if !self.collection_ok() {
            self.message.set("Collection not found");
            return None;
        }
        Some(document.array_copy(&[], &[]))
    }

    /// [`Structure::validate`] of a document's attributes, writing what PHP
    /// changes in the document (normalized `bigint`s, operators'
    /// attributes) to `write` when given.
    fn check(&self, structure: &Array, write: Option<&mut Document>) -> Result<bool> {
        let mut keys = Keys::default();
        let attributes = internal_attributes().iter().chain(self.collection_attributes());
        if !self.check_required(structure, attributes, &mut keys) {
            return Ok(false);
        }
        if !self.check_unknown(structure, &keys) {
            return Ok(false);
        }
        self.check_values(write, structure, &keys)
    }

    /// The collection exists: it has an id and is a `_metadata` document.
    pub(crate) fn collection_ok(&self) -> bool {
        let id = self.collection.id();
        !(id.is_empty() || id == "0") && self.collection.collection() == Database::METADATA
    }

    /// `$collection->getAttribute('attributes', [])`.
    pub(crate) fn collection_attributes(&self) -> impl Iterator<Item = &Value> {
        match self.collection.get("attributes") {
            Some(Value::Array(a)) => Some(a.values()),
            _ => None,
        }
        .into_iter()
        .flatten()
    }

    /// `checkForAllRequiredValues`: records every attribute in `keys` and
    /// checks the required ones are set.
    pub(crate) fn check_required<'a>(
        &self,
        structure: &Array,
        attributes: impl IntoIterator<Item = &'a Value>,
        keys: &mut Keys<'a>,
    ) -> bool {
        if !self.support_for_attributes {
            return true;
        }
        let empty = Value::from("");
        for attribute in attributes {
            let name = index_or(attribute, "$id", &empty);
            let required = index(attribute, "required").truthy();
            keys.set(array_key(name), attribute);
            let set = structure.get(&array_key(name)).is_some_and(|v| !matches!(v, Value::Null));
            if required && !set {
                if let Some(current) = &self.current_document
                    && current.get(&text(name)).is_none()
                {
                    continue;
                }
                self.message.set(format!("Missing required attribute \"{}\"", text(name)));
                return false;
            }
        }
        true
    }

    /// `checkForUnknownAttributes`.
    pub(crate) fn check_unknown(&self, structure: &Array, keys: &Keys<'_>) -> bool {
        if !self.support_for_attributes {
            return true;
        }
        for (key, _) in structure.iter() {
            if keys.get(key).is_none() {
                self.message.set(format!("Unknown attribute: \"{}\"", key_text(key)));
                return false;
            }
        }
        true
    }

    /// `checkForInvalidAttributeValues`.
    pub(crate) fn check_values(
        &self,
        mut document: Option<&mut Document>,
        structure: &Array,
        keys: &Keys<'_>,
    ) -> Result<bool> {
        for (key, value) in structure.iter() {
            let key_name = key_text(key);
            if let Value::Ext(Ext::Operator(operator)) = value {
                let mut operator = operator.as_ref().clone();
                operator.set_attribute(key_name.as_str());
                if let Some(Value::Ext(Ext::Operator(stored))) = document.as_mut().and_then(|d| d.get_mut(&key_name)) {
                    stored.set_attribute(key_name.as_str());
                }
                let validator = OperatorValidator::new(&self.collection, self.current_document.clone());
                if !validator.check(&operator)? {
                    return self.fail(validator.description());
                }
                continue;
            }

            const NULL: &Value = &Value::Null;
            let attribute = keys.get(key).unwrap_or(NULL);
            let empty = Value::from("");
            let kind = index_or(attribute, "type", &empty).clone();
            let array = index_or(attribute, "array", &Value::Bool(false)).clone();
            let format = index_or(attribute, "format", &empty).clone();
            let required = index_or(attribute, "required", &Value::Bool(false)).clone();
            let size = index_or(attribute, "size", &Value::Int(0)).clone();
            let signed = index_or(attribute, "signed", &Value::Bool(true)).truthy();

            if required.strict_eq(&Value::Bool(false)) && matches!(value, Value::Null) {
                continue;
            }
            if matches!(value, Value::Null)
                && let Some(current) = &self.current_document
                && current.get(&key_name).is_none()
            {
                continue;
            }
            let kind_str = match &kind {
                Value::Str(s) => s.as_str().unwrap_or(""),
                _ => "",
            };
            if kind_str == Database::VAR_RELATIONSHIP {
                continue;
            }

            let mut value = value.clone();
            if kind_str == Database::VAR_BIGINT
                && let Value::Str(s) = &value
                && BigInt::fits_php_int(s.as_bytes(), signed)
            {
                let normalized = Value::Int(int_cast(s.as_bytes()));
                if let Some(document) = document.as_mut() {
                    document.set(Str::from(key_name.as_str()), normalized.clone());
                }
                value = normalized;
            }

            let size_int = int_of(&size);
            let mut validators: Vec<Box<dyn Validator>> = Vec::new();
            match kind_str {
                Database::VAR_ID => validators.push(Box::new(Sequence::new(
                    self.id_attribute_type.clone(),
                    index(attribute, "$id").strict_eq(&Value::from("$sequence")),
                ))),
                Database::VAR_TEXT => {
                    validators.push(Box::new(ByteLength::new(size_int)));
                    validators.push(Box::new(ByteLength::new(Database::MAX_TEXT_BYTES)));
                }
                Database::VAR_MEDIUMTEXT => {
                    validators.push(Box::new(ByteLength::new(size_int)));
                    validators.push(Box::new(ByteLength::new(Database::MAX_MEDIUMTEXT_BYTES)));
                }
                Database::VAR_LONGTEXT => {
                    validators.push(Box::new(ByteLength::new(size_int)));
                    validators.push(Box::new(ByteLength::new(Database::MAX_LONGTEXT_BYTES)));
                }
                Database::VAR_VARCHAR | Database::VAR_STRING => {
                    validators.push(Box::new(Text::with_min(usize::try_from(size_int).unwrap_or(0), 0)));
                }
                Database::VAR_INTEGER => {
                    let bits = if size.compare(&Value::Int(8)) >= 0 { 64 } else { 32 };
                    let unsigned = !signed && bits < 64;
                    if let Ok(integer) = Integer::new(false, bits, unsigned) {
                        validators.push(Box::new(integer));
                    }
                    let max = if bits == 64 { Database::MAX_BIG_INT } else { Database::MAX_INT };
                    let min = if signed { -max } else { 0 };
                    validators.push(Box::new(Range::with_format(Number::Int(min), Number::Int(max), Type::Integer)));
                }
                Database::VAR_BIGINT => {
                    validators.push(Box::new(BigInt::new(signed, self.support_unsigned_big_int)));
                }
                Database::VAR_FLOAT => {
                    validators.push(Box::new(Float::default()));
                    let min = if signed { Number::Float(-Database::MAX_DOUBLE) } else { Number::Int(0) };
                    validators.push(Box::new(Range::with_format(
                        min,
                        Number::Float(Database::MAX_DOUBLE),
                        Type::Float,
                    )));
                }
                Database::VAR_BOOLEAN => validators.push(Box::new(Boolean::STRICT)),
                Database::VAR_DATETIME => validators
                    .push(Box::new(Datetime::between(self.min_allowed_date.clone(), self.max_allowed_date.clone()))),
                Database::VAR_OBJECT => validators.push(Box::new(ObjectValidator)),
                Database::VAR_POINT | Database::VAR_LINESTRING | Database::VAR_POLYGON => {
                    validators.push(Box::new(Spatial::new(kind_str)));
                }
                Database::VAR_VECTOR => validators.push(Box::new(Vector::new(int_of(&size)))),
                _ => {
                    if self.support_for_attributes {
                        return self.fail(format!("Unknown attribute type \"{}\"", text(&kind)));
                    }
                }
            }

            let label = if format.truthy() { "format" } else { "type" };
            if format.truthy() {
                let registered = formats::get(&text(&format), kind_str)?;
                let definition = match attribute {
                    Value::Ext(Ext::Document(d)) => d.as_ref().clone(),
                    Value::Array(a) => Document::from_array_unchecked(a.clone()),
                    _ => Document::empty(),
                };
                validators.push((registered.factory)(&definition));
            }

            if array.truthy() {
                let not_required = !required.truthy();
                let empty_or_null = match &value {
                    Value::Array(a) => a.is_empty(),
                    Value::Null => true,
                    _ => false,
                };
                if not_required && empty_or_null {
                    continue;
                }
                let Value::Array(items) = &value else {
                    return self.fail(format!("Attribute \"{key_name}\" must be an array"));
                };
                if !items.is_list() {
                    return self.fail(format!("Attribute \"{key_name}\" must be an array"));
                }
                for (x, child) in items.iter() {
                    if not_required && matches!(child, Value::Null) {
                        continue;
                    }
                    for validator in &validators {
                        if let Some(description) = rejection(validator.as_ref(), child) {
                            return self.fail(format!(
                                "Attribute \"{key_name}['{}']\" has invalid {label}. {description}",
                                key_text(x)
                            ));
                        }
                    }
                }
            } else {
                for validator in &validators {
                    if let Some(description) = rejection(validator.as_ref(), &value) {
                        return self.fail(format!("Attribute \"{key_name}\" has invalid {label}. {description}"));
                    }
                }
            }
        }
        Ok(true)
    }
}

/// An array key as text.
pub(crate) fn key_text(key: KeyRef<'_>) -> String {
    match key {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

/// `(int) $value` of a value PHP passes to an `int` parameter.
fn int_of(value: &Value) -> i64 {
    match value {
        Value::Int(i) => *i,
        Value::Float(f) => *f as i64,
        Value::Bool(b) => i64::from(*b),
        Value::Str(s) => int_cast(s.as_bytes()),
        _ => 0,
    }
}

/// `(int) $string`.
fn int_cast(s: &[u8]) -> i64 {
    match php_std::value::numeric_bytes_ex(s) {
        Some((Number::Int(i), _)) => i,
        Some((Number::Float(f), _)) => f as i64,
        None => 0,
    }
}

super::validator!(Structure, utopia_validators::Type::Array, false, fallible);
