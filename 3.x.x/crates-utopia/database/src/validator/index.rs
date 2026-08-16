//! PHP `Utopia\Database\Validator\Index`.

use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::constants::{
    INDEX_FULLTEXT, INDEX_HNSW_COSINE, INDEX_HNSW_DOT, INDEX_HNSW_EUCLIDEAN, INDEX_KEY,
    INDEX_OBJECT, INDEX_SPATIAL, INDEX_TRIGRAM, INDEX_TTL, INDEX_UNIQUE, INTERNAL_ATTRIBUTES,
    MAX_ARRAY_INDEX_LENGTH, SPATIAL_TYPES, STRING_TYPES, VAR_FLOAT, VAR_OBJECT, VAR_VECTOR,
};
use crate::document::Document;
use crate::value::AttrValue;

/// PHP `Utopia\Database\Validator\Index`.
#[derive(Debug)]
pub struct Index {
    attributes: indexmap::IndexMap<String, Document>,
    indexes: Vec<Document>,
    max_length: i64,
    reserved_keys: Vec<String>,
    support_for_array_indexes: bool,
    support_for_spatial_index_null: bool,
    support_for_spatial_index_order: bool,
    support_for_vector_indexes: bool,
    support_for_attributes: bool,
    support_for_multiple_fulltext_indexes: bool,
    support_for_identical_indexes: bool,
    support_for_object_indexes: bool,
    support_for_trigram_indexes: bool,
    support_for_spatial_indexes: bool,
    support_for_key_indexes: bool,
    support_for_unique_indexes: bool,
    support_for_fulltext_indexes: bool,
    support_for_ttl_indexes: bool,
    support_for_objects: bool,
    message: Mutex<String>,
}

impl Index {
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        attributes: Vec<Document>,
        indexes: Vec<Document>,
        max_length: i64,
        reserved_keys: Vec<String>,
        support_for_array_indexes: bool,
        support_for_spatial_index_null: bool,
        support_for_spatial_index_order: bool,
        support_for_vector_indexes: bool,
        support_for_attributes: bool,
        support_for_multiple_fulltext_indexes: bool,
        support_for_identical_indexes: bool,
        support_for_object_indexes: bool,
        support_for_trigram_indexes: bool,
        support_for_spatial_indexes: bool,
        support_for_key_indexes: bool,
        support_for_unique_indexes: bool,
        support_for_fulltext_indexes: bool,
        support_for_ttl_indexes: bool,
        support_for_objects: bool,
    ) -> Self {
        let mut map = indexmap::IndexMap::new();
        for attribute in attributes {
            let key = attr_key(&attribute).to_ascii_lowercase();
            map.insert(key, attribute);
        }
        for attribute in INTERNAL_ATTRIBUTES.iter() {
            if let Some(id) = attribute.get("$id").and_then(Value::as_str) {
                if let Ok(doc) = Document::try_from_json(attribute.clone()) {
                    map.insert(id.to_ascii_lowercase(), doc);
                }
            }
        }
        Self {
            attributes: map,
            indexes,
            max_length,
            reserved_keys,
            support_for_array_indexes,
            support_for_spatial_index_null,
            support_for_spatial_index_order,
            support_for_vector_indexes,
            support_for_attributes,
            support_for_multiple_fulltext_indexes,
            support_for_identical_indexes,
            support_for_object_indexes,
            support_for_trigram_indexes,
            support_for_spatial_indexes,
            support_for_key_indexes,
            support_for_unique_indexes,
            support_for_fulltext_indexes,
            support_for_ttl_indexes,
            support_for_objects,
            message: Mutex::new("Invalid index".into()),
        }
    }

    fn set_message(&self, message: impl Into<String>) {
        *self.message.lock() = message.into();
    }

    pub fn is_valid_document(&self, value: &Document) -> bool {
        self.check_valid_index(value)
            && self.check_valid_attributes(value)
            && self.check_empty(value)
            && self.check_duplicated(value)
            && self.check_multiple_fulltext(value)
            && self.check_fulltext_string(value)
            && self.check_array_indexes(value)
            && self.check_index_lengths(value)
            && self.check_reserved(value)
            && self.check_spatial(value)
            && self.check_non_spatial_on_spatial(value)
            && self.check_vector(value)
            && self.check_identical(value)
            && self.check_object(value)
            && self.check_trigram(value)
            && self.check_ttl(value)
    }

    fn check_valid_index(&self, index: &Document) -> bool {
        let type_ = index.get_attribute("type").as_str().unwrap_or("");
        match type_ {
            INDEX_KEY if !self.support_for_key_indexes => {
                self.set_message("Key index is not supported");
                false
            }
            INDEX_UNIQUE if !self.support_for_unique_indexes => {
                self.set_message("Unique index is not supported");
                false
            }
            INDEX_FULLTEXT if !self.support_for_fulltext_indexes => {
                self.set_message("Fulltext index is not supported");
                false
            }
            INDEX_SPATIAL if !self.support_for_spatial_indexes => {
                self.set_message("Spatial indexes are not supported");
                false
            }
            INDEX_HNSW_EUCLIDEAN | INDEX_HNSW_COSINE | INDEX_HNSW_DOT
                if !self.support_for_vector_indexes =>
            {
                self.set_message("Vector indexes are not supported");
                false
            }
            INDEX_OBJECT if !self.support_for_object_indexes => {
                self.set_message("Object indexes are not supported");
                false
            }
            INDEX_TRIGRAM if !self.support_for_trigram_indexes => {
                self.set_message("Trigram indexes are not supported");
                false
            }
            INDEX_TTL if !self.support_for_ttl_indexes => {
                self.set_message("TTL indexes are not supported");
                false
            }
            INDEX_KEY | INDEX_UNIQUE | INDEX_FULLTEXT | INDEX_SPATIAL | INDEX_HNSW_EUCLIDEAN
            | INDEX_HNSW_COSINE | INDEX_HNSW_DOT | INDEX_OBJECT | INDEX_TRIGRAM | INDEX_TTL => true,
            _ => {
                self.set_message(format!(
                    "Unknown index type: {type_}. Must be one of {INDEX_KEY}, {INDEX_UNIQUE}, {INDEX_FULLTEXT}, {INDEX_SPATIAL}, {INDEX_OBJECT}, {INDEX_HNSW_EUCLIDEAN}, {INDEX_HNSW_COSINE}, {INDEX_HNSW_DOT}, {INDEX_TRIGRAM}, {INDEX_TTL}"
                ));
                false
            }
        }
    }

    fn check_valid_attributes(&self, index: &Document) -> bool {
        if !self.support_for_attributes {
            return true;
        }
        for attribute in attr_list(index.get_attribute("attributes")) {
            if self
                .attributes
                .contains_key(&attribute.to_ascii_lowercase())
            {
                continue;
            }
            if self.support_for_objects {
                let base = attribute.split('.').next().unwrap_or(&attribute);
                if self.attributes.contains_key(&base.to_ascii_lowercase()) {
                    continue;
                }
            }
            self.set_message(format!("Invalid index attribute \"{attribute}\" not found"));
            return false;
        }
        true
    }

    fn check_empty(&self, index: &Document) -> bool {
        if attr_list(index.get_attribute("attributes")).is_empty() {
            self.set_message("No attributes provided for index");
            return false;
        }
        true
    }

    fn check_duplicated(&self, index: &Document) -> bool {
        let mut stack = Vec::new();
        for attribute in attr_list(index.get_attribute("attributes")) {
            let value = attribute.to_ascii_lowercase();
            if stack.contains(&value) {
                self.set_message("Duplicate attributes provided");
                return false;
            }
            stack.push(value);
        }
        true
    }

    fn check_fulltext_string(&self, index: &Document) -> bool {
        if !self.support_for_attributes
            || index.get_attribute("type").as_str() != Some(INDEX_FULLTEXT)
        {
            return true;
        }
        for attribute in attr_list(index.get_attribute("attributes")) {
            let attr = self
                .attributes
                .get(&attribute.to_ascii_lowercase())
                .cloned()
                .unwrap_or_default();
            let ty = attr.get_attribute("type").as_str().unwrap_or("");
            if !STRING_TYPES.contains(&ty) {
                self.set_message(format!(
                    "Attribute \"{}\" cannot be part of a fulltext index, must be of type string",
                    attr_key(&attr)
                ));
                return false;
            }
        }
        true
    }

    fn check_array_indexes(&self, index: &Document) -> bool {
        if !self.support_for_attributes {
            return true;
        }
        let attributes = attr_list(index.get_attribute("attributes"));
        let orders = attr_list(index.get_attribute("orders"));
        let lengths = num_list(index.get_attribute("lengths"));
        let mut array_count = 0;
        for (i, name) in attributes.iter().enumerate() {
            let attr = self
                .attributes
                .get(&name.to_ascii_lowercase())
                .cloned()
                .unwrap_or_default();
            if attr.get_attribute("array").as_bool().unwrap_or(false) {
                if index.get_attribute("type").as_str() != Some(INDEX_KEY) {
                    let ty = index.get_attribute("type").as_str().unwrap_or("");
                    let mut c = ty.chars();
                    let titled = c
                        .next()
                        .map(|ch| ch.to_uppercase().to_string() + c.as_str())
                        .unwrap_or_default();
                    self.set_message(format!(
                        "\"{titled}\" index is forbidden on array attributes"
                    ));
                    return false;
                }
                if lengths.get(i).copied().unwrap_or(0) == 0 {
                    self.set_message("Index length for array not specified");
                    return false;
                }
                array_count += 1;
                if array_count > 1 {
                    self.set_message("An index may only contain one array attribute");
                    return false;
                }
                let direction = orders.get(i).cloned().unwrap_or_default();
                if !direction.is_empty() {
                    self.set_message(format!(
                        "Invalid index order \"{direction}\" on array attribute \"{}\"",
                        attr_key(&attr)
                    ));
                    return false;
                }
                if !self.support_for_array_indexes {
                    self.set_message("Indexing an array attribute is not supported");
                    return false;
                }
            } else {
                let ty = attr.get_attribute("type").as_str().unwrap_or("");
                if !STRING_TYPES.contains(&ty) && lengths.get(i).copied().unwrap_or(0) != 0 {
                    self.set_message(format!("Cannot set a length on \"{ty}\" attributes"));
                    return false;
                }
            }
        }
        true
    }

    fn check_index_lengths(&self, index: &Document) -> bool {
        if index.get_attribute("type").as_str() == Some(INDEX_FULLTEXT)
            || !self.support_for_attributes
        {
            return true;
        }
        let lengths = num_list(index.get_attribute("lengths"));
        let attributes = attr_list(index.get_attribute("attributes"));
        if lengths.len() > attributes.len() {
            self.set_message(
                "Invalid index lengths. Count of lengths must be equal or less than the number of attributes.",
            );
            return false;
        }
        let mut total = 0i64;
        for (i, name) in attributes.iter().enumerate() {
            let mut attr_name = name.clone();
            if self.support_for_objects && !self.attributes.contains_key(&name.to_ascii_lowercase())
            {
                attr_name = name.split('.').next().unwrap_or(name).to_owned();
            }
            let attr = match self.attributes.get(&attr_name.to_ascii_lowercase()) {
                Some(a) => a,
                None => continue,
            };
            let ty = attr.get_attribute("type").as_str().unwrap_or("");
            let (attribute_size, mut index_length) = if STRING_TYPES.contains(&ty) {
                let size = attr.get_attribute("size").as_i64().unwrap_or(0);
                (
                    size,
                    if lengths.get(i).copied().unwrap_or(0) != 0 {
                        lengths[i]
                    } else {
                        size
                    },
                )
            } else if ty == VAR_FLOAT {
                (2, 2)
            } else {
                (1, 1)
            };
            if index_length < 0 {
                self.set_message(format!("Negative index length provided for {attr_name}"));
                return false;
            }
            let mut attribute_size = attribute_size;
            if attr.get_attribute("array").as_bool().unwrap_or(false) {
                attribute_size = MAX_ARRAY_INDEX_LENGTH;
                index_length = MAX_ARRAY_INDEX_LENGTH;
            }
            if index_length > attribute_size {
                self.set_message(format!(
                    "Index length {index_length} is larger than the size for {attr_name}: {attribute_size}\""
                ));
                return false;
            }
            total += index_length;
        }
        if total > self.max_length && self.max_length > 0 {
            self.set_message(format!(
                "Index length is longer than the maximum: {}",
                self.max_length
            ));
            return false;
        }
        true
    }

    fn check_reserved(&self, index: &Document) -> bool {
        let key = attr_key(index);
        if self
            .reserved_keys
            .iter()
            .any(|r| r.eq_ignore_ascii_case(&key))
        {
            self.set_message("Index key name is reserved");
            return false;
        }
        true
    }

    fn check_spatial(&self, index: &Document) -> bool {
        if index.get_attribute("type").as_str() != Some(INDEX_SPATIAL) {
            return true;
        }
        if !self.support_for_spatial_indexes {
            self.set_message("Spatial indexes are not supported");
            return false;
        }
        let attributes = attr_list(index.get_attribute("attributes"));
        if attributes.len() != 1 {
            self.set_message("Spatial index must have exactly one attribute");
            return false;
        }
        for name in &attributes {
            let attr = self
                .attributes
                .get(&name.to_ascii_lowercase())
                .cloned()
                .unwrap_or_default();
            let ty = attr.get_attribute("type").as_str().unwrap_or("");
            if !SPATIAL_TYPES.contains(&ty) {
                self.set_message(format!(
                    "Spatial index can only be created on spatial attributes (point, linestring, polygon). Attribute \"{name}\" is of type \"{ty}\""
                ));
                return false;
            }
            if !attr.get_attribute("required").as_bool().unwrap_or(false)
                && !self.support_for_spatial_index_null
            {
                self.set_message(format!(
                    "Spatial indexes do not allow null values. Mark the attribute \"{name}\" as required or create the index on a column with no null values."
                ));
                return false;
            }
        }
        let orders = attr_list(index.get_attribute("orders"));
        if !orders.is_empty() && !self.support_for_spatial_index_order {
            self.set_message("Spatial indexes with explicit orders are not supported. Remove the orders to create this index.");
            return false;
        }
        true
    }

    fn check_non_spatial_on_spatial(&self, index: &Document) -> bool {
        if index.get_attribute("type").as_str() == Some(INDEX_SPATIAL) {
            return true;
        }
        let type_ = index.get_attribute("type").as_str().unwrap_or("");
        for name in attr_list(index.get_attribute("attributes")) {
            let attr = self
                .attributes
                .get(&name.to_ascii_lowercase())
                .cloned()
                .unwrap_or_default();
            let ty = attr.get_attribute("type").as_str().unwrap_or("");
            if SPATIAL_TYPES.contains(&ty) {
                self.set_message(format!(
                    "Cannot create {type_} index on spatial attribute \"{name}\". Spatial attributes require spatial indexes."
                ));
                return false;
            }
        }
        true
    }

    fn check_vector(&self, index: &Document) -> bool {
        let type_ = index.get_attribute("type").as_str().unwrap_or("");
        if type_ != INDEX_HNSW_DOT && type_ != INDEX_HNSW_COSINE && type_ != INDEX_HNSW_EUCLIDEAN {
            return true;
        }
        if !self.support_for_vector_indexes {
            self.set_message("Vector indexes are not supported");
            return false;
        }
        let attributes = attr_list(index.get_attribute("attributes"));
        if attributes.len() != 1 {
            self.set_message("Vector index must have exactly one attribute");
            return false;
        }
        let attr = self
            .attributes
            .get(&attributes[0].to_ascii_lowercase())
            .cloned()
            .unwrap_or_default();
        if attr.get_attribute("type").as_str() != Some(VAR_VECTOR) {
            self.set_message("Vector index can only be created on vector attributes");
            return false;
        }
        true
    }

    fn check_trigram(&self, index: &Document) -> bool {
        if index.get_attribute("type").as_str() != Some(INDEX_TRIGRAM) {
            return true;
        }
        if !self.support_for_trigram_indexes {
            self.set_message("Trigram indexes are not supported");
            return false;
        }
        for name in attr_list(index.get_attribute("attributes")) {
            let attr = self
                .attributes
                .get(&name.to_ascii_lowercase())
                .cloned()
                .unwrap_or_default();
            if !STRING_TYPES.contains(&attr.get_attribute("type").as_str().unwrap_or("")) {
                self.set_message("Trigram index can only be created on string type attributes");
                return false;
            }
        }
        true
    }

    fn check_multiple_fulltext(&self, index: &Document) -> bool {
        if self.support_for_multiple_fulltext_indexes {
            return true;
        }
        if index.get_attribute("type").as_str() != Some(INDEX_FULLTEXT) {
            return true;
        }
        for existing in &self.indexes {
            if existing.get_id() == index.get_id() {
                continue;
            }
            if existing.get_attribute("type").as_str() == Some(INDEX_FULLTEXT) {
                self.set_message("There is already a fulltext index in the collection");
                return false;
            }
        }
        true
    }

    fn check_identical(&self, index: &Document) -> bool {
        if self.support_for_identical_indexes {
            return true;
        }
        let attrs = attr_list(index.get_attribute("attributes"));
        for existing in &self.indexes {
            if existing.get_id() == index.get_id() {
                continue;
            }
            if attr_list(existing.get_attribute("attributes")) == attrs
                && existing.get_attribute("type") == index.get_attribute("type")
            {
                self.set_message("There is already an index with matching attributes");
                return false;
            }
        }
        true
    }

    fn check_object(&self, index: &Document) -> bool {
        if index.get_attribute("type").as_str() != Some(INDEX_OBJECT) {
            return true;
        }
        if !self.support_for_object_indexes {
            self.set_message("Object indexes are not supported");
            return false;
        }
        for name in attr_list(index.get_attribute("attributes")) {
            let attr = self
                .attributes
                .get(&name.to_ascii_lowercase())
                .cloned()
                .unwrap_or_default();
            if attr.get_attribute("type").as_str() != Some(VAR_OBJECT) && !name.contains('.') {
                self.set_message("Object index can only be created on object attributes");
                return false;
            }
        }
        true
    }

    fn check_ttl(&self, index: &Document) -> bool {
        if index.get_attribute("type").as_str() != Some(INDEX_TTL) {
            return true;
        }
        if !self.support_for_ttl_indexes {
            self.set_message("TTL indexes are not supported");
            return false;
        }
        true
    }
}

fn attr_key(attribute: &Document) -> String {
    match attribute.get_attribute("key") {
        AttrValue::String(s) if !s.is_empty() => s.clone(),
        _ => attribute.get_id(),
    }
}

fn attr_list(value: &AttrValue) -> Vec<String> {
    match value {
        AttrValue::Array(items) => items
            .values()
            .filter_map(AttrValue::as_str)
            .map(str::to_owned)
            .collect(),
        _ => Vec::new(),
    }
}

fn num_list(value: &AttrValue) -> Vec<i64> {
    match value {
        AttrValue::Array(items) => items.values().filter_map(AttrValue::as_i64).collect(),
        _ => Vec::new(),
    }
}

impl Validator for Index {
    fn description(&self) -> String {
        self.message.lock().clone()
    }
    fn value_type(&self) -> ValueType {
        ValueType::Object
    }
    fn is_valid(&self, value: &Value) -> bool {
        Document::try_from_json(value.clone())
            .map(|d| self.is_valid_document(&d))
            .unwrap_or(false)
    }
}
