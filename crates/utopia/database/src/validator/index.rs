//! [`Index`]: index definitions against a collection's attributes and the
//! adapter's capabilities.
//!
//! PHP: `Utopia\Database\Validator\Index`.

use std::collections::HashMap;

use php_std::value::Number;

use crate::database::Database;
use crate::document::Document;
use crate::validator::{Message, attribute_key, lower, nth, text};
use crate::value::{KeyRef, Value, as_document};

/// What the adapter supports, for [`Index`]; PHP's defaults.
///
/// PHP: the `$supportFor*` constructor parameters of `Validator\Index`.
#[derive(Clone, Debug)]
pub struct IndexSupport {
    /// `$supportForArrayIndexes` (false).
    pub array_indexes: bool,
    /// `$supportForSpatialIndexNull` (false).
    pub spatial_index_null: bool,
    /// `$supportForSpatialIndexOrder` (false).
    pub spatial_index_order: bool,
    /// `$supportForVectorIndexes` (false).
    pub vector_indexes: bool,
    /// `$supportForAttributes` (true).
    pub attributes: bool,
    /// `$supportForMultipleFulltextIndexes` (true).
    pub multiple_fulltext_indexes: bool,
    /// `$supportForIdenticalIndexes` (true).
    pub identical_indexes: bool,
    /// `$supportForObjectIndexes` (false).
    pub object_indexes: bool,
    /// `$supportForTrigramIndexes` (false).
    pub trigram_indexes: bool,
    /// `$supportForSpatialIndexes` (false).
    pub spatial_indexes: bool,
    /// `$supportForKeyIndexes` (true).
    pub key_indexes: bool,
    /// `$supportForUniqueIndexes` (true).
    pub unique_indexes: bool,
    /// `$supportForFulltextIndexes` (true).
    pub fulltext_indexes: bool,
    /// `$supportForTTLIndexes` (false).
    pub ttl_indexes: bool,
    /// `$supportForObjects` (false).
    pub objects: bool,
}

impl Default for IndexSupport {
    fn default() -> Self {
        Self {
            array_indexes: false,
            spatial_index_null: false,
            spatial_index_order: false,
            vector_indexes: false,
            attributes: true,
            multiple_fulltext_indexes: true,
            identical_indexes: true,
            object_indexes: false,
            trigram_indexes: false,
            spatial_indexes: false,
            key_indexes: true,
            unique_indexes: true,
            fulltext_indexes: true,
            ttl_indexes: false,
            objects: false,
        }
    }
}

const STRING_TYPES: [&str; 5] = ["string", "varchar", "text", "mediumtext", "longtext"];

/// `in_array($needle, $haystack)` (loose) among strings.
fn in_strings(needle: &Value, haystack: &[&str]) -> bool {
    haystack.iter().any(|h| needle.loose_eq(&Value::from(*h)))
}

/// `in_array($needle, $haystack, true)` among strings.
fn in_strings_strict(needle: &Value, haystack: &[&str]) -> bool {
    haystack.iter().any(|h| needle.strict_eq(&Value::from(*h)))
}

fn is(value: Option<&Value>, s: &str) -> bool {
    value.is_some_and(|v| v.strict_eq(&Value::from(s)))
}

/// The entries of an array value (none for other values, as `foreach`
/// over them warns and skips).
fn entries(value: Option<&Value>) -> Vec<(KeyRef<'_>, &Value)> {
    match value {
        Some(Value::Array(a)) => a.iter().collect(),
        _ => Vec::new(),
    }
}

fn count(value: Option<&Value>) -> usize {
    match value {
        Some(Value::Array(a)) => a.len(),
        _ => 0,
    }
}

fn at<'a>(value: Option<&'a Value>, key: KeyRef<'_>) -> &'a Value {
    const NULL: &Value = &Value::Null;
    match value {
        Some(Value::Array(a)) => a.get_ref(key).unwrap_or(NULL),
        _ => NULL,
    }
}

fn empty(value: Option<&Value>) -> bool {
    !value.is_some_and(Value::truthy)
}

/// `array_diff($a, $b)` is empty: every element of `a` is (as a string) in `b`.
fn covered(a: Option<&Value>, b: Option<&Value>) -> bool {
    let b: Vec<String> = entries(b).into_iter().map(|(_, v)| text(v)).collect();
    entries(a).into_iter().all(|(_, v)| b.contains(&text(v)))
}

/// `$a + $b` of index lengths (numbers; numeric strings as numbers).
fn add(a: Number, b: &Value) -> Number {
    let b = match b {
        Value::Int(i) => Number::Int(*i),
        Value::Float(f) => Number::Float(*f),
        Value::Bool(x) => Number::Int(i64::from(*x)),
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).map_or(Number::Int(0), |(n, _)| n),
        _ => Number::Int(0),
    };
    match (a, b) {
        (Number::Int(x), Number::Int(y)) => x.checked_add(y).map_or(Number::Float(x as f64 + y as f64), Number::Int),
        (x, y) => Number::Float(x.as_f64() + y.as_f64()),
    }
}

fn number_value(n: Number) -> Value {
    match n {
        Number::Int(i) => Value::Int(i),
        Number::Float(f) => Value::Float(f),
    }
}

/// An index definition: a known type the adapter supports, on existing
/// attributes (no duplicates), with lengths, orders and attribute types
/// that suit its type, and not clashing with the collection's other
/// `indexes` (a second full-text or TTL index, an identical index).
///
/// ```
/// use utopia_database::Document;
/// use utopia_database::validator::index::{Index, IndexSupport};
/// use utopia_database::value::{Value, list, string};
///
/// let title = Document::from_pairs([("$id", string("title")), ("type", string("string")), ("size", Value::Int(255))]).unwrap();
/// let validator = Index::new(&[title], vec![], 768, vec![], IndexSupport::default());
/// let index = Document::from_pairs([("$id", string("idx")), ("type", string("key")), ("attributes", list([string("title")]))]).unwrap();
/// assert!(validator.check(&index));
/// let missing = Document::from_pairs([("$id", string("idx")), ("type", string("key")), ("attributes", list([string("name")]))]).unwrap();
/// assert!(!validator.check(&missing));
/// assert_eq!(validator.description(), "Invalid index attribute \"name\" not found");
/// ```
///
/// PHP: `Utopia\Database\Validator\Index` (`new Index($attributes, $indexes,
/// $maxLength, $reservedKeys, $supportFor...)`); its public `check*`
/// methods are the steps of [`Index::check`].
#[derive(Clone, Debug)]
pub struct Index {
    attributes: HashMap<String, Document>,
    indexes: Vec<Document>,
    max_length: i64,
    reserved_keys: Vec<String>,
    support: IndexSupport,
    message: Message,
}

impl Index {
    /// `new Index($attributes, $indexes, $maxLength, $reservedKeys, ...)`:
    /// the attributes by lower-case `key` (or `$id`), with the internal
    /// attributes.
    pub fn new(
        attributes: &[Document],
        indexes: Vec<Document>,
        max_length: i64,
        reserved_keys: Vec<String>,
        support: IndexSupport,
    ) -> Self {
        let mut map: HashMap<String, Document> = HashMap::new();
        for attribute in attributes {
            map.insert(lower(&attribute_key(attribute)), attribute.clone());
        }
        for attribute in Database::internal_attributes_const() {
            map.insert(lower(attribute.get("$id").unwrap_or(&Value::Null)), attribute);
        }
        Self { attributes: map, indexes, max_length, reserved_keys, support, message: Message::new("Invalid index") }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    fn fail(&self, message: impl Into<String>) -> bool {
        self.message.set(message);
        false
    }

    fn attribute(&self, name: &Value) -> Option<&Document> {
        self.attributes.get(&lower(name))
    }

    /// `isValid($index)`: every check, in PHP's order.
    pub fn check(&self, index: &Document) -> bool {
        self.check_valid_index(index)
            && self.check_valid_attributes(index)
            && self.check_empty_index_attributes(index)
            && self.check_duplicated_attributes(index)
            && self.check_multiple_fulltext_indexes(index)
            && self.check_fulltext_index_non_string(index)
            && self.check_array_indexes(index)
            && self.check_index_lengths(index)
            && self.check_reserved_names(index)
            && self.check_spatial_indexes(index)
            && self.check_non_spatial_index_on_spatial_attributes(index)
            && self.check_vector_indexes(index)
            && self.check_identical_indexes(index)
            && self.check_object_indexes(index)
            && self.check_trigram_indexes(index)
            && self.check_key_unique_fulltext_support(index)
            && self.check_ttl_indexes(index)
    }

    /// `isValid($value)`: a document as [`Index::check`]; any other value is
    /// invalid (PHP fails reading it).
    pub fn is_valid(&self, value: &Value) -> bool {
        as_document(value).is_some_and(|index| self.check(index))
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }

    fn base_attribute(attribute: &str) -> &str {
        attribute.split('.').next().unwrap_or("")
    }

    /// `checkValidIndex($index)`: a known type the adapter supports.
    pub fn check_valid_index(&self, index: &Document) -> bool {
        let kind = index.get("type");
        if self.support.objects {
            for (_, attribute) in entries(index.get("attributes")) {
                let name = text(attribute);
                if self.attribute(attribute).is_some() || !name.contains('.') {
                    continue;
                }
                let base = Value::from(Self::base_attribute(&name));
                if let Some(base) = self.attribute(&base)
                    && !base.get("type").is_some_and(|t| t.loose_eq(&Value::from(Database::VAR_OBJECT)))
                {
                    return self.fail(format!("Index attribute \"{name}\" is only supported on object attributes"));
                }
            }
        }
        let kind_str = match kind {
            Some(Value::Str(s)) => s.as_str(),
            _ => None,
        };
        match kind_str {
            Some(Database::INDEX_KEY) if !self.support.key_indexes => self.fail("Key index is not supported"),
            Some(Database::INDEX_UNIQUE) if !self.support.unique_indexes => self.fail("Unique index is not supported"),
            Some(Database::INDEX_FULLTEXT) if !self.support.fulltext_indexes => {
                self.fail("Fulltext index is not supported")
            }
            Some(Database::INDEX_SPATIAL) => {
                if !self.support.spatial_indexes {
                    return self.fail("Spatial indexes are not supported");
                }
                if !empty(index.get("orders")) && !self.support.spatial_index_order {
                    return self.fail(
                        "Spatial indexes with explicit orders are not supported. Remove the orders to create this index.",
                    );
                }
                true
            }
            Some(Database::INDEX_HNSW_EUCLIDEAN | Database::INDEX_HNSW_COSINE | Database::INDEX_HNSW_DOT)
                if !self.support.vector_indexes =>
            {
                self.fail("Vector indexes are not supported")
            }
            Some(Database::INDEX_OBJECT) if !self.support.object_indexes => self.fail("Object indexes are not supported"),
            Some(Database::INDEX_TRIGRAM) if !self.support.trigram_indexes => {
                self.fail("Trigram indexes are not supported")
            }
            Some(Database::INDEX_TTL) if !self.support.ttl_indexes => self.fail("TTL indexes are not supported"),
            Some(
                Database::INDEX_KEY
                | Database::INDEX_UNIQUE
                | Database::INDEX_FULLTEXT
                | Database::INDEX_HNSW_EUCLIDEAN
                | Database::INDEX_HNSW_COSINE
                | Database::INDEX_HNSW_DOT
                | Database::INDEX_OBJECT
                | Database::INDEX_TRIGRAM
                | Database::INDEX_TTL,
            ) => true,
            _ => self.fail(format!(
                "Unknown index type: {}. Must be one of key, unique, fulltext, spatial, object, hnsw_euclidean, hnsw_cosine, hnsw_dot, trigram, ttl",
                kind.map(text).unwrap_or_default()
            )),
        }
    }

    /// `checkValidAttributes($index)`: every attribute exists (or, with
    /// object support, the base of a dotted one).
    pub fn check_valid_attributes(&self, index: &Document) -> bool {
        if !self.support.attributes {
            return true;
        }
        for (_, attribute) in entries(index.get("attributes")) {
            if self.attribute(attribute).is_some() {
                continue;
            }
            let name = text(attribute);
            if self.support.objects && self.attribute(&Value::from(Self::base_attribute(&name))).is_some() {
                continue;
            }
            return self.fail(format!("Invalid index attribute \"{name}\" not found"));
        }
        true
    }

    /// `checkEmptyIndexAttributes($index)`.
    pub fn check_empty_index_attributes(&self, index: &Document) -> bool {
        if empty(index.get("attributes")) {
            return self.fail("No attributes provided for index");
        }
        true
    }

    /// `checkDuplicatedAttributes($index)`: no attribute twice (compared in
    /// lower case, loosely as PHP's `in_array`).
    pub fn check_duplicated_attributes(&self, index: &Document) -> bool {
        let mut stack: Vec<Value> = Vec::new();
        for (_, attribute) in entries(index.get("attributes")) {
            let value = Value::from(lower(attribute).as_str());
            if stack.iter().any(|s| s.loose_eq(&value)) {
                return self.fail("Duplicate attributes provided");
            }
            stack.push(value);
        }
        true
    }

    /// `checkFulltextIndexNonString($index)`: full-text indexes only on strings.
    pub fn check_fulltext_index_non_string(&self, index: &Document) -> bool {
        if !self.support.attributes {
            return true;
        }
        if is(index.get("type"), Database::INDEX_FULLTEXT) {
            let empty_doc = Document::empty();
            for (_, attribute) in entries(index.get("attributes")) {
                let attribute = self.attribute(attribute).unwrap_or(&empty_doc);
                let kind = attribute.get("type").cloned().unwrap_or(Value::from(""));
                if !in_strings(&kind, &STRING_TYPES) {
                    return self.fail(format!(
                        "Attribute \"{}\" cannot be part of a fulltext index, must be of type string",
                        text(&attribute_key(attribute))
                    ));
                }
            }
        }
        true
    }

    /// `checkArrayIndexes($index)`: array attributes only in key indexes,
    /// one per index, with a length and no order, when supported.
    pub fn check_array_indexes(&self, index: &Document) -> bool {
        if !self.support.attributes {
            return true;
        }
        let orders = index.get("orders");
        let lengths = index.get("lengths");
        let empty_doc = Document::empty();
        let mut array_attributes = 0;
        for (position, name) in entries(index.get("attributes")) {
            let attribute = self.attribute(name).unwrap_or(&empty_doc);
            if attribute.get("array").is_some_and(Value::truthy) {
                let kind = index.get("type").cloned().unwrap_or(Value::Null);
                if !kind.loose_eq(&Value::from(Database::INDEX_KEY)) {
                    let kind = text(&kind);
                    let upper = php_std::string::ucfirst(kind.as_bytes()).into_owned();
                    return self.fail(format!(
                        "\"{}\" index is forbidden on array attributes",
                        String::from_utf8_lossy(&upper)
                    ));
                }
                if !at(lengths, position).truthy() {
                    return self.fail("Index length for array not specified");
                }
                array_attributes += 1;
                if array_attributes > 1 {
                    return self.fail("An index may only contain one array attribute");
                }
                let direction = at(orders, position);
                if direction.truthy() {
                    return self.fail(format!(
                        "Invalid index order \"{}\" on array attribute \"{}\"",
                        text(direction),
                        attribute.get("key").map(text).unwrap_or_default()
                    ));
                }
                if !self.support.array_indexes {
                    return self.fail("Indexing an array attribute is not supported");
                }
            } else if !in_strings(attribute.get("type").unwrap_or(&Value::Null), &STRING_TYPES)
                && at(lengths, position).truthy()
            {
                return self.fail(format!(
                    "Cannot set a length on \"{}\" attributes",
                    attribute.get("type").map(text).unwrap_or_default()
                ));
            }
        }
        true
    }

    /// `checkIndexLengths($index)`: lengths within each attribute's size and,
    /// in total, the adapter's maximum.
    pub fn check_index_lengths(&self, index: &Document) -> bool {
        if is(index.get("type"), Database::INDEX_FULLTEXT) || !self.support.attributes {
            return true;
        }
        let lengths = index.get("lengths");
        let attributes = index.get("attributes");
        if count(lengths) > count(attributes) {
            return self
                .fail("Invalid index lengths. Count of lengths must be equal or less than the number of attributes.");
        }
        let empty_doc = Document::empty();
        let mut total = Number::Int(0);
        for (position, name) in entries(attributes) {
            let mut name_text = text(name);
            if self.support.objects && self.attribute(name).is_none() {
                name_text = Self::base_attribute(&name_text).to_owned();
            }
            let attribute = self.attribute(&Value::from(name_text.as_str())).unwrap_or(&empty_doc);
            let kind = attribute.get("type").cloned().unwrap_or(Value::Null);
            let (mut size, mut length) = if in_strings(&kind, &STRING_TYPES) {
                let size = attribute.get("size").cloned().unwrap_or(Value::Int(0));
                let given = at(lengths, position);
                let length = if given.truthy() { given.clone() } else { size.clone() };
                (size, length)
            } else if kind.loose_eq(&Value::from(Database::VAR_FLOAT)) {
                (Value::Int(2), Value::Int(2))
            } else {
                (Value::Int(1), Value::Int(1))
            };
            if length.compare(&Value::Int(0)) < 0 {
                return self.fail(format!("Negative index length provided for {name_text}"));
            }
            if attribute.get("array").is_some_and(Value::truthy) {
                size = Value::Int(Database::MAX_ARRAY_INDEX_LENGTH);
                length = Value::Int(Database::MAX_ARRAY_INDEX_LENGTH);
            }
            if length.compare(&size) > 0 {
                return self.fail(format!(
                    "Index length {} is larger than the size for {name_text}: {}\"",
                    text(&length),
                    text(&size)
                ));
            }
            total = add(total, &length);
        }
        if number_value(total).compare(&Value::Int(self.max_length)) > 0 && self.max_length > 0 {
            return self.fail(format!("Index length is longer than the maximum: {}", self.max_length));
        }
        true
    }

    /// `checkReservedNames($index)`: the key is not reserved (any case).
    pub fn check_reserved_names(&self, index: &Document) -> bool {
        let key = lower(&attribute_key(index));
        if self.reserved_keys.iter().any(|r| r.to_ascii_lowercase() == key) {
            return self.fail("Index key name is reserved");
        }
        true
    }

    /// `checkSpatialIndexes($index)`: one required spatial attribute, no
    /// orders (unless supported).
    pub fn check_spatial_indexes(&self, index: &Document) -> bool {
        if !is(index.get("type"), Database::INDEX_SPATIAL) {
            return true;
        }
        if !self.support.spatial_indexes {
            return self.fail("Spatial indexes are not supported");
        }
        let attributes = index.get("attributes");
        if count(attributes) != 1 {
            return self.fail("Spatial index must have exactly one attribute");
        }
        let empty_doc = Document::empty();
        for (_, name) in entries(attributes) {
            let attribute = self.attribute(name).unwrap_or(&empty_doc);
            let kind = attribute.get("type").cloned().unwrap_or(Value::from(""));
            if !in_strings_strict(&kind, &Database::SPATIAL_TYPES) {
                return self.fail(format!(
                    "Spatial index can only be created on spatial attributes (point, linestring, polygon). Attribute \"{}\" is of type \"{}\"",
                    text(name),
                    text(&kind)
                ));
            }
            let required = attribute.get("required").is_some_and(Value::truthy);
            if !required && !self.support.spatial_index_null {
                return self.fail(format!(
                    "Spatial indexes do not allow null values. Mark the attribute \"{}\" as required or create the index on a column with no null values.",
                    text(name)
                ));
            }
        }
        if !empty(index.get("orders")) && !self.support.spatial_index_order {
            return self.fail(
                "Spatial indexes with explicit orders are not supported. Remove the orders to create this index.",
            );
        }
        true
    }

    /// `checkNonSpatialIndexOnSpatialAttributes($index)`.
    pub fn check_non_spatial_index_on_spatial_attributes(&self, index: &Document) -> bool {
        let kind = index.get("type");
        if is(kind, Database::INDEX_SPATIAL) {
            return true;
        }
        let empty_doc = Document::empty();
        for (_, name) in entries(index.get("attributes")) {
            let attribute = self.attribute(name).unwrap_or(&empty_doc);
            let attribute_type = attribute.get("type").cloned().unwrap_or(Value::from(""));
            if in_strings_strict(&attribute_type, &Database::SPATIAL_TYPES) {
                return self.fail(format!(
                    "Cannot create {} index on spatial attribute \"{}\". Spatial attributes require spatial indexes.",
                    kind.map(text).unwrap_or_default(),
                    text(name)
                ));
            }
        }
        true
    }

    /// `checkVectorIndexes($index)`: one vector attribute, no orders or lengths.
    pub fn check_vector_indexes(&self, index: &Document) -> bool {
        let kind = index.get("type");
        if !is(kind, Database::INDEX_HNSW_DOT)
            && !is(kind, Database::INDEX_HNSW_COSINE)
            && !is(kind, Database::INDEX_HNSW_EUCLIDEAN)
        {
            return true;
        }
        if !self.support.vector_indexes {
            return self.fail("Vector indexes are not supported");
        }
        let attributes = index.get("attributes");
        if count(attributes) != 1 {
            return self.fail("Vector index must have exactly one attribute");
        }
        let first = attributes.map_or(&Value::Null, |a| nth(a, 0));
        let empty_doc = Document::empty();
        let attribute = self.attribute(first).unwrap_or(&empty_doc);
        if !is(attribute.get("type"), Database::VAR_VECTOR) {
            return self.fail("Vector index can only be created on vector attributes");
        }
        if !empty(index.get("orders")) || entries(index.get("lengths")).iter().any(|(_, l)| l.truthy()) {
            return self.fail("Vector indexes do not support orders or lengths");
        }
        true
    }

    /// `checkTrigramIndexes($index)`: string attributes, no orders or lengths.
    pub fn check_trigram_indexes(&self, index: &Document) -> bool {
        if !is(index.get("type"), Database::INDEX_TRIGRAM) {
            return true;
        }
        if !self.support.trigram_indexes {
            return self.fail("Trigram indexes are not supported");
        }
        let empty_doc = Document::empty();
        for (_, name) in entries(index.get("attributes")) {
            let attribute = self.attribute(name).unwrap_or(&empty_doc);
            if !in_strings(&attribute.get("type").cloned().unwrap_or(Value::from("")), &STRING_TYPES) {
                return self.fail("Trigram index can only be created on string type attributes");
            }
        }
        if !empty(index.get("orders")) || entries(index.get("lengths")).iter().any(|(_, l)| l.truthy()) {
            return self.fail("Trigram indexes do not support orders or lengths");
        }
        true
    }

    /// `checkKeyUniqueFulltextSupport($index)`.
    pub fn check_key_unique_fulltext_support(&self, index: &Document) -> bool {
        let kind = index.get("type");
        if is(kind, Database::INDEX_KEY) && !self.support.key_indexes {
            return self.fail("Key index is not supported");
        }
        if is(kind, Database::INDEX_UNIQUE) && !self.support.unique_indexes {
            return self.fail("Unique index is not supported");
        }
        true
    }

    /// `checkMultipleFulltextIndexes($index)`: one full-text index per
    /// collection, unless supported.
    pub fn check_multiple_fulltext_indexes(&self, index: &Document) -> bool {
        if self.support.multiple_fulltext_indexes {
            return true;
        }
        if is(index.get("type"), Database::INDEX_FULLTEXT) {
            for existing in &self.indexes {
                if existing.id() == index.id() {
                    continue;
                }
                if is(existing.get("type"), Database::INDEX_FULLTEXT) {
                    return self.fail("There is already a fulltext index in the collection");
                }
            }
        }
        true
    }

    /// `checkIdenticalIndexes($index)`: no key or unique index on the same
    /// attributes and orders, unless supported.
    pub fn check_identical_indexes(&self, index: &Document) -> bool {
        if self.support.identical_indexes {
            return true;
        }
        let attributes = index.get("attributes");
        let orders = index.get("orders");
        let kind = index.get("type").cloned().unwrap_or(Value::from(""));
        let regular = [Database::INDEX_KEY, Database::INDEX_UNIQUE];
        for existing in &self.indexes {
            let existing_attributes = existing.get("attributes");
            let existing_orders = existing.get("orders");
            let existing_type = existing.get("type").cloned().unwrap_or(Value::from(""));
            let attributes_match = covered(existing_attributes, attributes) && covered(attributes, existing_attributes);
            let orders_match = covered(existing_orders, orders) && covered(orders, existing_orders);
            if attributes_match && orders_match && in_strings(&kind, &regular) && in_strings(&existing_type, &regular) {
                return self.fail("There is already an index with the same attributes and orders");
            }
        }
        true
    }

    /// `checkObjectIndexes($index)`: one top-level object attribute, no orders.
    pub fn check_object_indexes(&self, index: &Document) -> bool {
        if !is(index.get("type"), Database::INDEX_OBJECT) {
            return true;
        }
        if !self.support.object_indexes {
            return self.fail("Object indexes are not supported");
        }
        let attributes = index.get("attributes");
        if count(attributes) != 1 {
            return self.fail("Object index can be created on a single object attribute");
        }
        if !empty(index.get("orders")) {
            return self.fail("Object index do not support explicit orders. Remove the orders to create this index.");
        }
        let name = attributes.map_or(&Value::Null, |a| nth(a, 0));
        let name = if matches!(name, Value::Null) { Value::from("") } else { name.clone() };
        if text(&name).contains('.') {
            return self.fail("Object index can only be created on a top-level object attribute");
        }
        let empty_doc = Document::empty();
        let attribute = self.attribute(&name).unwrap_or(&empty_doc);
        let kind = attribute.get("type").cloned().unwrap_or(Value::from(""));
        if !kind.strict_eq(&Value::from(Database::VAR_OBJECT)) {
            return self.fail(format!(
                "Object index can only be created on object attributes. Attribute \"{}\" is of type \"{}\"",
                text(&name),
                text(&kind)
            ));
        }
        true
    }

    /// `checkTTLIndexes($index)`: one datetime attribute, a TTL of at least
    /// a second, one TTL index per collection.
    pub fn check_ttl_indexes(&self, index: &Document) -> bool {
        if !is(index.get("type"), Database::INDEX_TTL) {
            return true;
        }
        let attributes = index.get("attributes");
        if count(attributes) != 1 {
            return self.fail("TTL indexes must be created on a single datetime attribute.");
        }
        let name = attributes.map_or(&Value::Null, |a| nth(a, 0));
        let name = if matches!(name, Value::Null) { Value::from("") } else { name.clone() };
        let empty_doc = Document::empty();
        let attribute = self.attribute(&name).unwrap_or(&empty_doc);
        let kind = attribute.get("type").cloned().unwrap_or(Value::from(""));
        if self.support.attributes && !kind.strict_eq(&Value::from(Database::VAR_DATETIME)) {
            return self.fail(format!(
                "TTL index can only be created on datetime attributes. Attribute \"{}\" is of type \"{}\"",
                text(&name),
                text(&kind)
            ));
        }
        let ttl = index.get("ttl").cloned().unwrap_or(Value::Int(0));
        if ttl.compare(&Value::Int(1)) < 0 {
            return self.fail("TTL must be at least 1 second");
        }
        for existing in &self.indexes {
            if existing.id() == index.id() {
                continue;
            }
            if is(existing.get("type"), Database::INDEX_TTL) {
                return self.fail("There can be only one TTL index in a collection");
            }
        }
        true
    }
}

super::validator!(Index, utopia_validators::Type::Object, false);
