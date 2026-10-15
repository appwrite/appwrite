//! [`Structure`]: documents against their collection's attributes; and
//! the registry of attribute [`formats`].
//!
//! PHP: `Utopia\Database\Validator\Structure`.

use std::collections::HashMap;
use std::fmt;
use std::sync::{Arc, OnceLock};

use php_std::value::Number;
use utopia_query::schema::ColumnType;
use utopia_validators::{Boolean, Float, Integer, Range, Text, Type, Validator};

use crate::adapter::Profile;
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::database;
use crate::document::{self, Document};
use crate::error::Result;
use crate::operator::Operator;
use crate::validator::big_int::BigInt;
use crate::validator::byte_length::ByteLength;
use crate::validator::datetime::Datetime;
use crate::validator::object_value::ObjectValue;
use crate::validator::sequence::Sequence;
use crate::validator::spatial::Spatial;
use crate::validator::vector::Vector;
use crate::validator::{Message, rejection, text_key};
use crate::value::{Array, Ext, KeyRef, Value};

/// The registry of attribute formats: named validators an attribute's
/// `format` selects, for one attribute type (an `email` format of
/// [`ColumnType::String`] attributes, a `range` of
/// [`ColumnType::Integer`]).
///
/// The registry is process-wide, as PHP's static one: register formats at
/// startup.
///
/// ```
/// use std::sync::Arc;
/// use utopia_database::validator::structure::formats;
/// use utopia_query::schema::ColumnType;
/// use utopia_validators::Text;
///
/// formats::add("short", Arc::new(|_attribute| Box::new(Text::new(5))), ColumnType::String);
/// assert!(formats::has("short", ColumnType::String));
/// assert!(!formats::has("short", ColumnType::Integer));
/// assert_eq!(
///     formats::get("short", ColumnType::Integer).err().unwrap().message(),
///     "Format \"short\" not available for attribute type \"integer\""
/// );
/// formats::remove("short");
/// ```
///
/// PHP: `Structure::addFormat`, `hasFormat`, `getFormat`, `removeFormat`
/// (and the static `$formats`).
pub mod formats {
    use std::sync::{Arc, RwLock};

    use utopia_query::schema::ColumnType;

    use crate::error::{Error, Result};
    use crate::value::Array;

    /// Builds a format's validator for an attribute, given the attribute as
    /// it is stored (`Attribute::toDocument()->getArrayCopy()`: `size`,
    /// `formatOptions`, ...).
    ///
    /// PHP: the `Closure` given to `addFormat`.
    pub type Factory = Arc<dyn Fn(&Array) -> Box<dyn utopia_validators::Validator> + Send + Sync>;

    /// A registered format.
    #[derive(Clone)]
    pub struct Format {
        /// The name attributes give in `format`.
        pub name: String,
        /// The attribute type the format applies to (PHP's `type`, its
        /// value).
        pub kind: ColumnType,
        /// Builds the validator (PHP's `callback`).
        pub factory: Factory,
    }

    impl std::fmt::Debug for Format {
        fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
            f.debug_struct("Format").field("name", &self.name).field("kind", &self.kind).finish()
        }
    }

    static FORMATS: RwLock<Vec<Format>> = RwLock::new(Vec::new());

    fn read() -> std::sync::RwLockReadGuard<'static, Vec<Format>> {
        FORMATS.read().unwrap_or_else(|e| e.into_inner())
    }

    /// `Structure::addFormat($name, $callback, $type)`: registers (or
    /// replaces) a format.
    pub fn add(name: &str, factory: Factory, kind: ColumnType) {
        let mut formats = FORMATS.write().unwrap_or_else(|e| e.into_inner());
        let format = Format { name: name.to_owned(), kind, factory };
        match formats.iter_mut().find(|f| f.name == name) {
            Some(existing) => *existing = format,
            None => formats.push(format),
        }
    }

    /// `Structure::hasFormat($name, $type)`.
    pub fn has(name: &str, kind: ColumnType) -> bool {
        read().iter().any(|f| f.name == name && f.kind.as_str() == kind.as_str())
    }

    /// `Structure::getFormat($name, $type)`.
    ///
    /// Fails ([`crate::Kind::Database`]) with `Format "<name>" not available
    /// for attribute type "<type>"` or `Unknown format validator "<name>"`.
    pub fn get(name: &str, kind: ColumnType) -> Result<Format> {
        match read().iter().find(|f| f.name == name) {
            Some(f) if f.kind.as_str() == kind.as_str() => Ok(f.clone()),
            Some(_) => Err(Error::database(format!(
                "Format \"{name}\" not available for attribute type \"{}\"",
                kind.as_str()
            ))),
            None => Err(Error::database(format!("Unknown format validator \"{name}\""))),
        }
    }

    /// `Structure::removeFormat($name)`.
    pub fn remove(name: &str) {
        FORMATS.write().unwrap_or_else(|e| e.into_inner()).retain(|f| f.name != name);
    }
}

/// Checks an operator a document sets on an attribute: `Ok(None)` when it
/// is valid, `Ok(Some(description))` when it is not.
///
/// PHP: `Structure` builds `Validator\Operator($collection,
/// $currentDocument, $supportUnsignedBigInt)` and asks it `isValid()` and
/// `getDescription()`. The operator arrives with its attribute set to the
/// key it is written to.
pub type OperatorCheck = Arc<dyn Fn(&Operator) -> Result<Option<String>> + Send + Sync>;

/// The system attributes every document has (`Structure::internalAttributes()`).
pub fn internal_attributes() -> &'static [Attribute] {
    static INTERNAL: OnceLock<Vec<Attribute>> = OnceLock::new();
    INTERNAL.get_or_init(|| {
        vec![
            Attribute::string(document::ID, 255),
            Attribute::id(document::SEQUENCE),
            Attribute::string(document::COLLECTION, 255).required(true),
            Attribute::id(document::TENANT),
            Attribute::string(document::PERMISSIONS, 67000).array(true),
            Attribute::datetime(document::CREATED_AT).required(true),
            Attribute::datetime(document::UPDATED_AT).required(true),
        ]
    })
}

/// The internal and collection attributes, by key (PHP's `$definitions`:
/// a later attribute of the same key replaces an earlier one in place).
#[derive(Clone, Debug, Default)]
pub(crate) struct Definitions {
    list: Vec<Attribute>,
    by_key: HashMap<String, usize>,
}

impl Definitions {
    fn insert(&mut self, attribute: Attribute) {
        match self.by_key.get(&attribute.key) {
            Some(&i) => self.list[i] = attribute,
            None => {
                self.by_key.insert(attribute.key.clone(), self.list.len());
                self.list.push(attribute);
            }
        }
    }

    /// `$definitions[$key] ?? null`.
    pub(crate) fn get(&self, key: &str) -> Option<&Attribute> {
        self.by_key.get(key).map(|&i| &self.list[i])
    }

    /// The attributes, in order.
    pub(crate) fn iter(&self) -> impl Iterator<Item = &Attribute> {
        self.list.iter()
    }
}

/// A document valid for its collection: it names a collection, has every
/// required attribute (unless the current document holds `null` there),
/// has no unknown attribute (with [`Capability::DefinedAttributes`]), and
/// each value suits its attribute: type, size, signedness, array-ness and
/// format. Operators are checked by the [`OperatorCheck`] given with
/// [`Structure::operators`].
///
/// ```
/// use std::sync::Arc;
/// use utopia_database::adapter::{Features, Limits, Profile};
/// use utopia_database::capability::{Capabilities, Capability};
/// use utopia_database::validator::structure::Structure;
/// use utopia_database::{Attribute, Collection, Document};
/// use utopia_database::value::string;
/// use utopia_database::validator::datetime::{default_max, default_min};
/// use utopia_query::schema::ColumnType;
///
/// let limits = Limits {
///     string: 0, varchar: 0, integer: 0, big_integer: 0, attributes: 0, indexes: 0,
///     default_attributes: 0, default_indexes: 0, index_length: 0, uid_length: 36, document_size: 0,
///     min_date_time: default_min(), max_date_time: default_max(),
///     id_type: ColumnType::Integer, keywords: vec![], internal_index_keys: vec![],
/// };
/// let profile = Profile::new(Arc::new(limits), Capabilities::of(&[Capability::DefinedAttributes]), Features::EMPTY, false, false, None);
/// let mut movies = Collection::create("movies", "", vec![Attribute::string("title", 10).required(true)], vec![], None, true, Default::default()).unwrap();
/// movies.set("$collection", string("_metadata")).unwrap();
/// let structure = Structure::new(movies.to_document(), profile);
/// let mut movie = Document::from_pairs([
///     ("$collection", string("movies")), ("$createdAt", string("2024-01-01 00:00:00")),
///     ("$updatedAt", string("2024-01-01 00:00:00")), ("title", string("Alien")),
/// ]).unwrap();
/// assert!(structure.validate(&mut movie).unwrap());
/// movie.set("title", string("The Empire Strikes Back")).unwrap();
/// assert!(!structure.validate(&mut movie).unwrap());
/// assert_eq!(
///     structure.description(),
///     "Invalid document structure: Attribute \"title\" has invalid type. Value must be a valid string and no longer than 10 chars"
/// );
/// ```
///
/// PHP: `Utopia\Database\Validator\Structure` (`new Structure($collection,
/// $profile, $currentDocument, $storedAttributes)`). Validation changes the
/// document as PHP's does: a big integer numeric string that fits a PHP
/// integer becomes one, and operators learn their attribute's key. The
/// definitions are read once: build a new validator after the collection's
/// attributes change.
#[derive(Clone)]
pub struct Structure {
    pub(crate) collection: Document,
    profile: Profile,
    current_document: Option<Document>,
    stored_attributes: Vec<String>,
    operators: Option<OperatorCheck>,
    definitions: OnceLock<Result<Definitions>>,
    pub(crate) message: Message,
}

impl fmt::Debug for Structure {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Structure")
            .field("collection", &self.collection)
            .field("profile", &self.profile)
            .field("current_document", &self.current_document)
            .field("stored_attributes", &self.stored_attributes)
            .finish()
    }
}

impl Structure {
    /// `new Structure($collection, $profile)`: `collection` is the
    /// collection's definition document (in `_metadata`).
    pub fn new(collection: Document, profile: Profile) -> Self {
        Self {
            collection,
            profile,
            current_document: None,
            stored_attributes: Vec::new(),
            operators: None,
            definitions: OnceLock::new(),
            message: Message::new("General Error"),
        }
    }

    /// The same validator with `$currentDocument`: the stored document an
    /// update changes, whose `null` attributes may stay `null` though now
    /// required.
    #[must_use]
    pub fn current_document(mut self, current: Option<Document>) -> Self {
        self.current_document = current;
        self
    }

    /// The same validator with `$storedAttributes`: attributes whose values
    /// are the stored ones, unchanged by the write, and not validated again
    /// (the rules may have tightened since they were stored).
    #[must_use]
    pub fn stored_attributes<S: Into<String>>(mut self, keys: impl IntoIterator<Item = S>) -> Self {
        self.stored_attributes = keys.into_iter().map(Into::into).collect();
        self
    }

    /// The same validator checking operators with `check`. Without one,
    /// operators are accepted (the 8.x `Validator\Operator` is ported
    /// separately; the database supplies it).
    #[must_use]
    pub fn operators(mut self, check: OperatorCheck) -> Self {
        self.operators = Some(check);
        self
    }

    /// The profile the validator was built with.
    pub fn profile(&self) -> &Profile {
        &self.profile
    }

    /// The current document, if any.
    pub fn current(&self) -> Option<&Document> {
        self.current_document.as_ref()
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
    /// [`Structure::validate`] (leaving it as it is), anything else invalid.
    ///
    /// Fails as [`Structure::validate`] does.
    pub fn is_valid(&self, value: &Value) -> Result<bool> {
        match value {
            Value::Ext(Ext::Document(d)) => self.run(d, None),
            _ => self.fail("Value must be an instance of Document"),
        }
    }

    /// `isValid($document)`.
    ///
    /// Fails ([`crate::Kind::Structure`]) when the collection's attributes
    /// cannot be read, and ([`crate::Kind::Database`]) when an attribute
    /// names a format that is not registered for its type.
    pub fn validate(&self, document: &mut Document) -> Result<bool> {
        let copy = document.clone();
        self.run(&copy, Some(document))
    }

    fn run(&self, document: &Document, write: Option<&mut Document>) -> Result<bool> {
        let collection = document.collection();
        if collection.is_empty() || collection == "0" {
            return self.fail(format!("Missing collection attribute {}", document::COLLECTION));
        }
        if !self.collection_ok() {
            return self.fail("Collection not found");
        }
        let structure = document.array_copy();
        let definitions = self.definitions()?;
        if !self.check_required(&structure, definitions.iter()) {
            return Ok(false);
        }
        if !self.check_unknown(&structure, definitions) {
            return Ok(false);
        }
        self.check_values(write, &structure, definitions)
    }

    /// The collection exists: it has an id and is a `_metadata` document.
    pub(crate) fn collection_ok(&self) -> bool {
        let id = self.collection.id();
        !(id.is_empty() || id == "0") && self.collection.collection() == database::METADATA
    }

    /// `definitions()`: the internal attributes, then the collection's.
    pub(crate) fn definitions(&self) -> Result<&Definitions> {
        self.definitions
            .get_or_init(|| {
                let collection = Collection::from_document(&self.collection)?;
                let mut definitions = Definitions::default();
                for attribute in internal_attributes() {
                    definitions.insert(attribute.clone());
                }
                for attribute in collection.attributes()? {
                    definitions.insert(attribute.clone());
                }
                Ok(definitions)
            })
            .as_ref()
            .map_err(Clone::clone)
    }

    /// `isStoredAsNull($key)`: a document stored before the attribute
    /// became required holds `null` for it, and may keep it.
    fn stored_as_null(&self, key: &str) -> bool {
        self.current_document.as_ref().is_some_and(|current| current.get(key).is_none())
    }

    /// `checkForAllRequiredValues`.
    pub(crate) fn check_required<'a>(&self, structure: &Array, attributes: impl IntoIterator<Item = &'a Attribute>) -> bool {
        if !self.profile.supports(Capability::DefinedAttributes) {
            return true;
        }
        for attribute in attributes {
            let set = structure.get(&text_key(&attribute.key)).is_some_and(|v| !matches!(v, Value::Null));
            if attribute.required && !set && !self.stored_as_null(&attribute.key) {
                self.message.set(format!("Missing required attribute \"{}\"", attribute.key));
                return false;
            }
        }
        true
    }

    /// `checkForUnknownAttributes`.
    pub(crate) fn check_unknown(&self, structure: &Array, definitions: &Definitions) -> bool {
        if !self.profile.supports(Capability::DefinedAttributes) {
            return true;
        }
        for (key, _) in structure.iter() {
            if definitions.get(&key_text(key)).is_none() {
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
        definitions: &Definitions,
    ) -> Result<bool> {
        let support_for_attributes = self.profile.supports(Capability::DefinedAttributes);
        let support_unsigned = self.profile.supports(Capability::UnsignedBigInt);
        for (key, value) in structure.iter() {
            let key_name = key_text(key);
            if let Value::Ext(Ext::Operator(operator)) = value {
                let mut operator = operator.as_ref().clone();
                operator.set_attribute(key_name.as_str());
                if let Some(Value::Ext(Ext::Operator(stored))) =
                    document.as_mut().and_then(|d| d.as_array_mut().get_mut(&key.to_owned()))
                {
                    stored.set_attribute(key_name.as_str());
                }
                if let Some(check) = &self.operators
                    && let Some(description) = check(&operator)?
                {
                    return self.fail(description);
                }
                continue;
            }

            if self.stored_attributes.iter().any(|k| *k == key_name) {
                continue;
            }

            let Some(attribute) = definitions.get(&key_name) else { continue };
            let kind = attribute.kind;
            let size = attribute.size.unwrap_or(0);
            let signed = attribute.signed;
            let required = attribute.required;

            if matches!(value, Value::Null) && (!required || self.stored_as_null(&key_name)) {
                continue;
            }
            if kind == ColumnType::Relationship {
                continue;
            }

            let mut value = value.clone();
            if kind == ColumnType::BigInteger
                && let Value::Str(s) = &value
                && fits_php_int(s.as_bytes(), signed)
            {
                let normalized = Value::Int(php_std::format::str_to_int(s.as_bytes()));
                if let Some(document) = document.as_mut() {
                    document.put(key_name.as_str(), normalized.clone());
                }
                value = normalized;
            }

            let mut validators: Vec<Box<dyn Validator>> = Vec::new();
            match kind {
                ColumnType::Id => validators
                    .push(Box::new(Sequence::new(self.profile.limits.id_type.as_str(), key_name == document::SEQUENCE))),
                ColumnType::Text => {
                    validators.push(Box::new(ByteLength::new(size)));
                    validators.push(Box::new(ByteLength::new(database::MAX_TEXT_BYTES)));
                }
                ColumnType::MediumText => {
                    validators.push(Box::new(ByteLength::new(size)));
                    validators.push(Box::new(ByteLength::new(database::MAX_MEDIUMTEXT_BYTES)));
                }
                ColumnType::LongText => {
                    validators.push(Box::new(ByteLength::new(size)));
                    validators.push(Box::new(ByteLength::new(database::MAX_LONGTEXT_BYTES)));
                }
                ColumnType::Varchar | ColumnType::String => {
                    validators.push(Box::new(Text::with_min(usize::try_from(size).unwrap_or(0), 0)));
                }
                ColumnType::Integer => {
                    let bits = if size >= 8 { 64 } else { 32 };
                    // 64-bit unsigned is checked as signed (PHP has no
                    // unsigned 64-bit integers); the range keeps it positive.
                    let unsigned = !signed && bits < 64;
                    if let Ok(integer) = Integer::new(false, bits, unsigned) {
                        validators.push(Box::new(integer));
                    }
                    let max = if bits == 64 { database::MAX_BIG_INT } else { database::MAX_INT };
                    let min = if signed { -max } else { 0 };
                    validators.push(Box::new(Range::with_format(Number::Int(min), Number::Int(max), Type::Integer)));
                }
                ColumnType::BigInteger => validators.push(Box::new(BigInt::new(signed, support_unsigned))),
                ColumnType::Float | ColumnType::Double => {
                    // Float and Range both: Range casts non-numeric values.
                    validators.push(Box::new(Float::default()));
                    let min = if signed { Number::Float(-database::MAX_DOUBLE) } else { Number::Int(0) };
                    validators.push(Box::new(Range::with_format(min, Number::Float(database::MAX_DOUBLE), Type::Float)));
                }
                ColumnType::Boolean => validators.push(Box::new(Boolean::STRICT)),
                ColumnType::Datetime => validators.push(Box::new(Datetime::between(
                    self.profile.limits.min_date_time.clone(),
                    self.profile.limits.max_date_time.clone(),
                ))),
                ColumnType::Object => validators.push(Box::new(ObjectValue)),
                ColumnType::Point | ColumnType::Linestring | ColumnType::Polygon => {
                    validators.push(Box::new(Spatial::new(kind.as_str())));
                }
                ColumnType::Vector => validators.push(Box::new(Vector::new(size))),
                _ => {
                    if support_for_attributes {
                        return self.fail(format!("Unknown attribute type \"{}\"", kind.as_str()));
                    }
                }
            }

            let label = if attribute.format.is_some() { "format" } else { "type" };
            if let Some(format) = &attribute.format {
                let registered = formats::get(&format.name, kind)?;
                validators.push((registered.factory)(&attribute.to_document()?.array_copy()));
            }

            if attribute.array {
                let empty_or_null = match &value {
                    Value::Array(a) => a.is_empty(),
                    Value::Null => true,
                    _ => false,
                };
                if !required && empty_or_null {
                    continue;
                }
                let Value::Array(items) = &value else {
                    return self.fail(format!("Attribute \"{key_name}\" must be an array"));
                };
                if !items.is_list() {
                    return self.fail(format!("Attribute \"{key_name}\" must be an array"));
                }
                for (x, child) in items.iter() {
                    if !required && matches!(child, Value::Null) {
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

/// `BigInt::isIntegerString($value, $signed)`: `/^-?\d+$/` (or `/^\d+$/`
/// unsigned), where `$` also matches before a final newline.
pub(crate) fn is_integer_string(value: &[u8], signed: bool) -> bool {
    let body = value.strip_suffix(b"\n").unwrap_or(value);
    let digits = match body.split_first() {
        Some((b'-', rest)) if signed => rest,
        _ => body,
    };
    !digits.is_empty() && digits.iter().all(u8::is_ascii_digit)
}

/// `BigInt::fitsPhpInt($value, $signed)`: an integer string within PHP's
/// integer range.
pub(crate) fn fits_php_int(value: &[u8], signed: bool) -> bool {
    if !is_integer_string(value, signed) {
        return false;
    }
    let (digits, limit): (&[u8], &[u8]) = match value.strip_prefix(b"-") {
        Some(rest) if signed => (rest, b"9223372036854775808"),
        _ => (value, b"9223372036854775807"),
    };
    let digits = digits.strip_suffix(b"\n").unwrap_or(digits);
    let digits = digits.iter().position(|b| *b != b'0').map_or(&b"0"[..], |i| &digits[i..]);
    digits.len() < limit.len() || (digits.len() == limit.len() && digits <= limit)
}

super::validator!(Structure, utopia_validators::Type::Array, false, fallible);
