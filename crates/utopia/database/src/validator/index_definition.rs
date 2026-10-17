//! [`IndexDefinition`]: index definitions before they are created.
//!
//! PHP: `Utopia\Database\Validator\IndexDefinition`.

use utopia_query::schema::{ColumnType, IndexType};

use crate::adapter::{Feature, Profile};
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::index::Index;
use crate::validator::Message;
use crate::value::{Ext, Str, Value};

const STRING_TYPES: [ColumnType; 5] =
    [ColumnType::String, ColumnType::Varchar, ColumnType::Text, ColumnType::MediumText, ColumnType::LongText];

const SPATIAL_TYPES: [ColumnType; 3] = [ColumnType::Point, ColumnType::Linestring, ColumnType::Polygon];

const BIG_INTEGER_SIZE: i64 = 8;

/// An index definition the collection can take: a type the adapter
/// supports, on existing attributes (or paths into object attributes),
/// without duplicates, within the length limits, under a key that is not
/// reserved, and with the constraints of its type (fulltext, array,
/// spatial, vector, object, trigram and TTL indexes), not identical to an
/// existing index where the adapter refuses that.
///
/// Failures are invalid, not errors; the description says why.
///
/// ```
/// use std::sync::Arc;
/// use utopia_database::{Attribute, Index};
/// use utopia_database::adapter::{Features, Limits, Profile};
/// use utopia_database::capability::{Capabilities, Capability};
/// use utopia_database::validator::index_definition::IndexDefinition;
/// use utopia_database::validator::datetime::{default_max, default_min};
/// use utopia_query::schema::ColumnType;
///
/// let limits = Limits {
///     string: 0, varchar: 0, integer: 0, big_integer: 0, attributes: 0, indexes: 0,
///     default_attributes: 0, default_indexes: 0, index_length: 768, uid_length: 36, document_size: 0,
///     min_date_time: default_min(), max_date_time: default_max(),
///     id_type: ColumnType::Integer, keywords: vec![], internal_index_keys: vec![],
/// };
/// let capabilities = Capabilities::of(&[Capability::DefinedAttributes, Capability::IndexKey]);
/// let profile = Profile::new(Arc::new(limits), capabilities, Features::EMPTY, false, false, None);
/// let validator = IndexDefinition::new(vec![Attribute::string("title", 255)], vec![], profile);
/// assert!(validator.check(&Index::key("idx", ["title"], vec![], vec![]).unwrap()).unwrap());
/// assert!(!validator.check(&Index::key("idx", ["year"], vec![], vec![]).unwrap()).unwrap());
/// assert_eq!(validator.description(), "Invalid index attribute \"year\" not found");
/// ```
///
/// PHP: `Utopia\Database\Validator\IndexDefinition` (`new
/// IndexDefinition($attributes, $indexes, $profile)`); its public `check*`
/// methods are the steps of [`IndexDefinition::check`].
#[derive(Clone, Debug)]
pub struct IndexDefinition {
    attributes: Vec<(String, Attribute)>,
    indexes: Vec<Index>,
    profile: Profile,
    message: Message,
}

impl IndexDefinition {
    /// `new IndexDefinition($attributes, $indexes, $profile)`: the
    /// collection's attributes (keyed by lower-cased key, the internal
    /// attributes added) and its indexes.
    pub fn new(attributes: Vec<Attribute>, indexes: Vec<Index>, profile: Profile) -> Self {
        let mut keyed: Vec<(String, Attribute)> = Vec::with_capacity(attributes.len() + 7);
        let mut insert = |attribute: Attribute| {
            let key = attribute.key.to_ascii_lowercase();
            match keyed.iter_mut().find(|(k, _)| *k == key) {
                Some(entry) => entry.1 = attribute,
                None => keyed.push((key, attribute)),
            }
        };
        for attribute in attributes {
            insert(attribute);
        }
        for attribute in database::internal_attributes(true) {
            insert(attribute.clone());
        }
        Self { attributes: keyed, indexes, profile, message: Message::new("Invalid index") }
    }

    /// `new IndexDefinition(...)` of stored attribute and index documents.
    ///
    /// Fails as [`Attribute::from_document`] and [`Index::from_document`] do.
    pub fn from_documents(attributes: &[Document], indexes: &[Document], profile: Profile) -> Result<Self> {
        let attributes = attributes.iter().map(Attribute::from_document).collect::<Result<Vec<_>>>()?;
        let indexes = indexes.iter().map(Index::from_document).collect::<Result<Vec<_>>>()?;
        Ok(Self::new(attributes, indexes, profile))
    }

    /// `getDescription()`: the last failure, or `Invalid index`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }

    fn fail(&self, message: impl Into<String>) -> bool {
        self.message.set(message);
        false
    }

    /// `isValid($value)` of any value: an index document as
    /// [`IndexDefinition::check_document`]; anything else is invalid
    /// (`Value must be an index`).
    pub fn is_valid(&self, value: &Value) -> Result<bool> {
        match value {
            Value::Ext(Ext::Document(d)) => self.check_document(d),
            _ => Ok(self.fail("Value must be an index")),
        }
    }

    /// `isValid($document)`: the stored type must be an index type and a
    /// TTL index must have a TTL, before the document is read as an index
    /// (which reads an unknown type as a key index).
    ///
    /// Fails as [`Index::from_document`] and [`IndexDefinition::check`] do.
    pub fn check_document(&self, index: &Document) -> Result<bool> {
        if !self.check_stored_definition(index) {
            return Ok(false);
        }
        self.check(&Index::from_document(index)?)
    }

    /// `checkStoredDefinition()`.
    fn check_stored_definition(&self, index: &Document) -> bool {
        let kind = match index.get("type") {
            Some(Value::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
            _ => None,
        };
        let known = kind.as_deref().and_then(IndexType::try_from_value);
        let Some(known) = known else {
            return self.fail(Index::unknown_type_message(kind.as_deref().unwrap_or("")));
        };
        if known == IndexType::Ttl && index.get("ttl").is_none() {
            return self.fail("TTL must be at least 1 second");
        }
        true
    }

    /// `isValid($index)`: every check, in PHP's order.
    ///
    /// Fails ([`crate::Kind::Error`]) only where PHP fails reading a
    /// missing attribute ([`IndexDefinition::check_index_lengths`]).
    pub fn check(&self, index: &Index) -> Result<bool> {
        Ok(self.check_valid_index(index)
            && self.check_valid_attributes(index)
            && self.check_empty_index_attributes(index)
            && self.check_duplicated_attributes(index)
            && self.check_multiple_fulltext_indexes(index)
            && self.check_fulltext_index_non_string(index)
            && self.check_array_indexes(index)
            && self.check_index_lengths(index)?
            && self.check_reserved_names(index)
            && self.check_spatial_indexes(index)
            && self.check_non_spatial_index_on_spatial_attributes(index)
            && self.check_vector_indexes(index)
            && self.check_identical_indexes(index)
            && self.check_object_indexes(index)
            && self.check_trigram_indexes(index)
            && self.check_key_unique_fulltext_support(index)
            && self.check_ttl_indexes(index))
    }

    /// `checkValidIndex($index)`: a type the adapter supports, and paths
    /// only into object attributes.
    pub fn check_valid_index(&self, index: &Index) -> bool {
        if self.profile.supports(Capability::Objects) {
            for attribute in &index.attributes {
                if self.find(attribute).is_some() || !attribute.contains('.') {
                    continue;
                }
                if let Some(base) = self.find(base_attribute(attribute))
                    && base.kind != ColumnType::Object
                {
                    return self
                        .fail(format!("Index attribute \"{attribute}\" is only supported on object attributes"));
                }
            }
        }
        let supports = |c| self.profile.supports(c);
        match index.kind {
            IndexType::Key if !supports(Capability::IndexKey) => self.fail("Key index is not supported"),
            IndexType::Unique if !supports(Capability::IndexUnique) => self.fail("Unique index is not supported"),
            IndexType::Fulltext if !supports(Capability::IndexFulltext) => self.fail("Fulltext index is not supported"),
            IndexType::Spatial if !self.profile.has_feature(Feature::Spatial) => {
                self.fail("Spatial indexes are not supported")
            }
            IndexType::Spatial if !index.orders.is_empty() && !supports(Capability::IndexSpatialOrder) => self.fail(
                "Spatial indexes with explicit orders are not supported. Remove the orders to create this index.",
            ),
            IndexType::HnswEuclidean | IndexType::HnswCosine | IndexType::HnswDot if !supports(Capability::Vectors) => {
                self.fail("Vector indexes are not supported")
            }
            IndexType::Object if !supports(Capability::IndexObject) => self.fail("Object indexes are not supported"),
            IndexType::Trigram if !supports(Capability::IndexTrigram) => self.fail("Trigram indexes are not supported"),
            IndexType::Ttl if !supports(Capability::IndexTtl) => self.fail("TTL indexes are not supported"),
            IndexType::Key
            | IndexType::Unique
            | IndexType::Fulltext
            | IndexType::Spatial
            | IndexType::HnswEuclidean
            | IndexType::HnswCosine
            | IndexType::HnswDot
            | IndexType::Object
            | IndexType::Trigram
            | IndexType::Ttl => true,
            other => self.fail(Index::unknown_type_message(other.as_str())),
        }
    }

    /// `checkValidAttributes($index)`: every attribute exists (or, with
    /// objects, its base attribute does), with
    /// [`Capability::DefinedAttributes`].
    pub fn check_valid_attributes(&self, index: &Index) -> bool {
        if !self.profile.supports(Capability::DefinedAttributes) {
            return true;
        }
        for attribute in &index.attributes {
            if self.find(attribute).is_some() {
                continue;
            }
            if self.profile.supports(Capability::Objects) && self.find(base_attribute(attribute)).is_some() {
                continue;
            }
            return self.fail(format!("Invalid index attribute \"{attribute}\" not found"));
        }
        true
    }

    /// `checkEmptyIndexAttributes($index)`.
    pub fn check_empty_index_attributes(&self, index: &Index) -> bool {
        if index.attributes.is_empty() {
            return self.fail("No attributes provided for index");
        }
        true
    }

    /// `checkDuplicatedAttributes($index)`: no attribute twice (any case;
    /// numeric names compare as numbers, as `in_array()` does).
    pub fn check_duplicated_attributes(&self, index: &Index) -> bool {
        let mut stack: Vec<Value> = Vec::with_capacity(index.attributes.len());
        for attribute in &index.attributes {
            let value = Value::Str(Str::from(attribute.to_ascii_lowercase()));
            if stack.iter().any(|v| v.loose_eq(&value)) {
                return self.fail("Duplicate attributes provided");
            }
            stack.push(value);
        }
        true
    }

    /// `checkFulltextIndexNonString($index)`: fulltext indexes on string
    /// attributes only.
    pub fn check_fulltext_index_non_string(&self, index: &Index) -> bool {
        if !self.profile.supports(Capability::DefinedAttributes) || index.kind != IndexType::Fulltext {
            return true;
        }
        for name in &index.attributes {
            let attribute = self.find(name);
            if !is_string(attribute) {
                let key = attribute.map_or(name.as_str(), |a| a.key.as_str());
                return self
                    .fail(format!("Attribute \"{key}\" cannot be part of a fulltext index, must be of type string"));
            }
        }
        true
    }

    /// `checkArrayIndexes($index)`: an array attribute only in a key index,
    /// with a length, no order, at most one per index, where the adapter
    /// indexes arrays; lengths only on string attributes.
    pub fn check_array_indexes(&self, index: &Index) -> bool {
        if !self.profile.supports(Capability::DefinedAttributes) {
            return true;
        }
        let mut arrays = 0;
        for (position, name) in index.attributes.iter().enumerate() {
            let attribute = self.find(name);
            let length = index.lengths.get(position).copied().flatten().unwrap_or(0);
            match attribute {
                Some(attribute) if attribute.array => {
                    // MariaDB and MySQL disagree on what makes array values
                    // unique, so an array attribute takes only a key index.
                    if index.kind != IndexType::Key {
                        return self.fail(format!(
                            "\"{}\" index is forbidden on array attributes",
                            ucfirst(index.kind.as_str())
                        ));
                    }
                    if length == 0 {
                        return self.fail("Index length for array not specified");
                    }
                    arrays += 1;
                    if arrays > 1 {
                        return self.fail("An index may only contain one array attribute");
                    }
                    if let Some(Some(direction)) = index.orders.get(position) {
                        return self.fail(format!(
                            "Invalid index order \"{}\" on array attribute \"{}\"",
                            direction.as_str(),
                            attribute.key
                        ));
                    }
                    if !self.profile.supports(Capability::IndexArray) {
                        return self.fail("Indexing an array attribute is not supported");
                    }
                }
                _ if !is_string(attribute) && length != 0 => {
                    let kind = attribute.map_or("", |a| a.kind.as_str());
                    return self.fail(format!("Cannot set a length on \"{kind}\" attributes"));
                }
                _ => {}
            }
        }
        true
    }

    /// `checkIndexLengths($index)`: no more lengths than attributes, each
    /// within its attribute's size, and the total within the adapter's
    /// index length.
    ///
    /// Fails ([`crate::Kind::Error`]) for an attribute that does not exist,
    /// as PHP fails reading it (`checkValidAttributes()` refuses those
    /// first).
    pub fn check_index_lengths(&self, index: &Index) -> Result<bool> {
        if index.kind == IndexType::Fulltext || !self.profile.supports(Capability::DefinedAttributes) {
            return Ok(true);
        }
        if index.lengths.len() > index.attributes.len() {
            return Ok(self
                .fail("Invalid index lengths. Count of lengths must be equal or less than the number of attributes."));
        }
        let mut total: i64 = 0;
        for (position, name) in index.attributes.iter().enumerate() {
            let mut name = name.as_str();
            if self.profile.supports(Capability::Objects) && self.find(name).is_none() {
                name = base_attribute(name);
            }
            let Some(attribute) = self.find(name) else {
                return Err(Error::new(Kind::Error, "Call to a member function resolvedSize() on null"));
            };
            let resolved = attribute.resolved_size();
            let length = index.lengths.get(position).copied().flatten().unwrap_or(0);
            let (mut size, mut index_length) = match attribute.kind {
                ColumnType::String
                | ColumnType::Varchar
                | ColumnType::Text
                | ColumnType::MediumText
                | ColumnType::LongText => (resolved, if length != 0 { length } else { resolved }),
                ColumnType::Float | ColumnType::Double | ColumnType::BigInteger | ColumnType::Id => (2, 2),
                ColumnType::Integer if resolved >= BIG_INTEGER_SIZE => (2, 2),
                _ => (1, 1),
            };
            if index_length < 0 {
                return Ok(self.fail(format!("Negative index length provided for {name}")));
            }
            if attribute.array {
                size = database::MAX_ARRAY_INDEX_LENGTH;
                index_length = database::MAX_ARRAY_INDEX_LENGTH;
            }
            if index_length > size {
                return Ok(self.fail(format!("Index length {index_length} is larger than the size for {name}: {size}")));
            }
            total = total.wrapping_add(index_length);
        }
        let max = self.profile.limits.index_length;
        if total > max && max > 0 {
            return Ok(self.fail(format!("Index length is longer than the maximum: {max}")));
        }
        Ok(true)
    }

    /// `checkReservedNames($index)`: not the key of an internal index.
    pub fn check_reserved_names(&self, index: &Index) -> bool {
        let key = index.key.to_ascii_lowercase();
        if self.profile.limits.internal_index_keys.iter().any(|r| r.to_ascii_lowercase() == key) {
            return self.fail("Index key name is reserved");
        }
        true
    }

    /// `checkSpatialIndexes($index)`: one required spatial attribute
    /// (unless the adapter indexes nulls), no orders unless supported.
    pub fn check_spatial_indexes(&self, index: &Index) -> bool {
        if index.kind != IndexType::Spatial {
            return true;
        }
        if !self.profile.has_feature(Feature::Spatial) {
            return self.fail("Spatial indexes are not supported");
        }
        if index.attributes.len() != 1 {
            return self.fail("Spatial index must have exactly one attribute");
        }
        for name in &index.attributes {
            let attribute = self.find(name);
            let kind = attribute.map_or(ColumnType::String, |a| a.kind);
            if !SPATIAL_TYPES.contains(&kind) {
                return self.fail(format!(
                    "Spatial index can only be created on spatial attributes (point, linestring, polygon). Attribute \"{name}\" is of type \"{}\"",
                    kind.as_str()
                ));
            }
            if !attribute.is_some_and(|a| a.required) && !self.profile.supports(Capability::IndexSpatialNull) {
                return self.fail(format!(
                    "Spatial indexes do not allow null values. Mark the attribute \"{name}\" as required or create the index on a column with no null values."
                ));
            }
        }
        if !index.orders.is_empty() && !self.profile.supports(Capability::IndexSpatialOrder) {
            return self.fail(
                "Spatial indexes with explicit orders are not supported. Remove the orders to create this index.",
            );
        }
        true
    }

    /// `checkNonSpatialIndexOnSpatialAttributes($index)`.
    pub fn check_non_spatial_index_on_spatial_attributes(&self, index: &Index) -> bool {
        if index.kind == IndexType::Spatial {
            return true;
        }
        for name in &index.attributes {
            let kind = self.find(name).map_or(ColumnType::String, |a| a.kind);
            if SPATIAL_TYPES.contains(&kind) {
                return self.fail(format!(
                    "Cannot create {} index on spatial attribute \"{name}\". Spatial attributes require spatial indexes.",
                    index.kind.as_str()
                ));
            }
        }
        true
    }

    /// `checkVectorIndexes($index)`: one vector attribute, no orders or
    /// lengths.
    pub fn check_vector_indexes(&self, index: &Index) -> bool {
        if !matches!(index.kind, IndexType::HnswDot | IndexType::HnswCosine | IndexType::HnswEuclidean) {
            return true;
        }
        if !self.profile.supports(Capability::Vectors) {
            return self.fail("Vector indexes are not supported");
        }
        if index.attributes.len() != 1 {
            return self.fail("Vector index must have exactly one attribute");
        }
        if self.find(&index.attributes[0]).map(|a| a.kind) != Some(ColumnType::Vector) {
            return self.fail("Vector index can only be created on vector attributes");
        }
        if !index.orders.is_empty() || has_lengths(index) {
            return self.fail("Vector indexes do not support orders or lengths");
        }
        true
    }

    /// `checkTrigramIndexes($index)`: string attributes, no orders or
    /// lengths.
    pub fn check_trigram_indexes(&self, index: &Index) -> bool {
        if index.kind != IndexType::Trigram {
            return true;
        }
        if !self.profile.supports(Capability::IndexTrigram) {
            return self.fail("Trigram indexes are not supported");
        }
        if index.attributes.iter().any(|name| !is_string(self.find(name))) {
            return self.fail("Trigram index can only be created on string type attributes");
        }
        if !index.orders.is_empty() || has_lengths(index) {
            return self.fail("Trigram indexes do not support orders or lengths");
        }
        true
    }

    /// `checkKeyUniqueFulltextSupport($index)`.
    pub fn check_key_unique_fulltext_support(&self, index: &Index) -> bool {
        if index.kind == IndexType::Key && !self.profile.supports(Capability::IndexKey) {
            return self.fail("Key index is not supported");
        }
        if index.kind == IndexType::Unique && !self.profile.supports(Capability::IndexUnique) {
            return self.fail("Unique index is not supported");
        }
        true
    }

    /// `checkMultipleFulltextIndexes($index)`: one fulltext index, unless
    /// the adapter takes several.
    pub fn check_multiple_fulltext_indexes(&self, index: &Index) -> bool {
        if self.profile.supports(Capability::IndexFulltextMultiple) || index.kind != IndexType::Fulltext {
            return true;
        }
        if self.indexes.iter().any(|existing| existing.key != index.key && existing.kind == IndexType::Fulltext) {
            return self.fail("There is already a fulltext index in the collection");
        }
        true
    }

    /// `checkIdenticalIndexes($index)`: no other key or unique index on the
    /// same attributes and orders, unless the adapter allows it (the index
    /// itself is skipped, so revalidating it does not compare it with
    /// itself).
    pub fn check_identical_indexes(&self, index: &Index) -> bool {
        if self.profile.supports(Capability::IndexIdentical) {
            return true;
        }
        let key = index.key.to_ascii_lowercase();
        let regular = |kind: IndexType| matches!(kind, IndexType::Key | IndexType::Unique);
        let incoming_orders = order_values(index);
        for existing in &self.indexes {
            if existing.key.to_ascii_lowercase() == key {
                continue;
            }
            let attributes_match = same_set(&existing.attributes, &index.attributes);
            let orders_match = same_set(&order_values(existing), &incoming_orders);
            if attributes_match && orders_match && regular(index.kind) && regular(existing.kind) {
                return self.fail("There is already an index with the same attributes and orders");
            }
        }
        true
    }

    /// `checkObjectIndexes($index)`: one top-level object attribute, no
    /// orders.
    pub fn check_object_indexes(&self, index: &Index) -> bool {
        if index.kind != IndexType::Object {
            return true;
        }
        if !self.profile.supports(Capability::IndexObject) {
            return self.fail("Object indexes are not supported");
        }
        if index.attributes.len() != 1 {
            return self.fail("Object index can be created on a single object attribute");
        }
        if !index.orders.is_empty() {
            return self.fail("Object index do not support explicit orders. Remove the orders to create this index.");
        }
        let name = &index.attributes[0];
        // Only the top-level object attribute, not paths into it.
        if name.contains('.') {
            return self.fail("Object index can only be created on a top-level object attribute");
        }
        let kind = self.find(name).map_or(ColumnType::String, |a| a.kind);
        if kind != ColumnType::Object {
            return self.fail(format!(
                "Object index can only be created on object attributes. Attribute \"{name}\" is of type \"{}\"",
                kind.as_str()
            ));
        }
        true
    }

    /// `checkTtlIndexes($index)`: one datetime attribute (with
    /// [`Capability::DefinedAttributes`]), a TTL of at least a second, and
    /// no other TTL index.
    pub fn check_ttl_indexes(&self, index: &Index) -> bool {
        if index.kind != IndexType::Ttl {
            return true;
        }
        if index.attributes.len() != 1 {
            return self.fail("TTL indexes must be created on a single datetime attribute.");
        }
        let name = &index.attributes[0];
        let kind = self.find(name).map_or(ColumnType::String, |a| a.kind);
        if self.profile.supports(Capability::DefinedAttributes) && kind != ColumnType::Datetime {
            return self.fail(format!(
                "TTL index can only be created on datetime attributes. Attribute \"{name}\" is of type \"{}\"",
                kind.as_str()
            ));
        }
        if index.ttl.unwrap_or(0) < 1 {
            return self.fail("TTL must be at least 1 second");
        }
        if self.indexes.iter().any(|existing| existing.key != index.key && existing.kind == IndexType::Ttl) {
            return self.fail("There can be only one TTL index in a collection");
        }
        true
    }

    /// `findAttribute($name)`: the attribute of that key (any case); `None`
    /// for names outside the schema, such as paths into object attributes.
    fn find(&self, name: &str) -> Option<&Attribute> {
        let key = name.to_ascii_lowercase();
        self.attributes.iter().find(|(k, _)| *k == key).map(|(_, a)| a)
    }
}

/// `isStringAttribute()`.
fn is_string(attribute: Option<&Attribute>) -> bool {
    attribute.is_some_and(|a| STRING_TYPES.contains(&a.kind))
}

/// `getBaseAttributeFromDottedAttribute()`: the part before the first dot.
fn base_attribute(attribute: &str) -> &str {
    attribute.split_once('.').map_or(attribute, |(base, _)| base)
}

/// `count(array_filter($index->lengths)) > 0`.
fn has_lengths(index: &Index) -> bool {
    index.lengths.iter().any(|l| l.is_some_and(|l| l != 0))
}

/// `orderValues()`.
fn order_values(index: &Index) -> Vec<String> {
    index.orders.iter().map(|o| o.map_or(String::new(), |o| o.as_str().to_owned())).collect()
}

/// `empty(array_diff($a, $b)) && empty(array_diff($b, $a))`: the same
/// values as strings, ignoring order and repeats.
fn same_set<S: AsRef<str>>(a: &[S], b: &[S]) -> bool {
    a.iter().all(|x| b.iter().any(|y| x.as_ref() == y.as_ref()))
        && b.iter().all(|y| a.iter().any(|x| x.as_ref() == y.as_ref()))
}

/// `ucfirst()`: the first byte upper-cased (ASCII).
fn ucfirst(s: &str) -> String {
    let mut chars = s.chars();
    match chars.next() {
        Some(c) => c.to_ascii_uppercase().to_string() + chars.as_str(),
        None => String::new(),
    }
}

super::validator!(IndexDefinition, utopia_validators::Type::Object, false, fallible);
