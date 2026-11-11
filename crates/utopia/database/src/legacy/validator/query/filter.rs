//! [`Filter`]: filter queries (`equal`, `contains`, `between`, spatial,
//! vector, logical, ...).
//!
//! PHP: `Utopia\Database\Validator\Query\Filter`.

use php_std::datetime::DateTime;
use utopia_validators::{Boolean, Float, Integer, Text};

use crate::database::Database;
use crate::document::Document;
use crate::query::{Method, Query};
use crate::validator::big_int::BigInt;
use crate::validator::datetime::{Datetime, default_max, default_min};
use crate::validator::query::base::{INVALID_QUERY, MethodType, query_validator};
use crate::validator::sequence::Sequence;
use crate::validator::{Message, accepts, array_key, index, index_or, is_numeric, text, text_key};
use crate::value::{Array, KeyRef, Value, as_query};

/// A filter query on an attribute of the collection whose values suit the
/// attribute's type: at most `max_values_count` values, strings for
/// strings, integers in range for integers, dates between the allowed
/// dates, ... Logical queries (`and`, `or`, `elemMatch`) must nest filters.
///
/// ```
/// use utopia_database::Document;
/// use utopia_database::query::{Method, Query};
/// use utopia_database::validator::query::filter::Filter;
/// use utopia_database::value::{Value, string};
///
/// let attributes = [Document::from_pairs([
///     ("key", string("age")),
///     ("type", string("integer")),
///     ("array", Value::Bool(false)),
/// ])
/// .unwrap()];
/// let filter = Filter::new(&attributes, "integer");
/// assert!(filter.check(&Query::new(Method::GreaterThan, "age", vec![Value::Int(18)])));
/// assert!(!filter.check(&Query::new(Method::GreaterThan, "age", vec![string("18")])));
/// assert_eq!(filter.description(), "Query value is invalid for attribute \"age\"");
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Filter` (`new Filter($attributes,
/// $idAttributeType, $maxValuesCount, $minAllowedDate, $maxAllowedDate,
/// $supportForAttributes, $supportUnsignedBigInt)`).
#[derive(Clone, Debug)]
pub struct Filter {
    schema: Array,
    id_attribute_type: String,
    max_values_count: i64,
    min_allowed_date: DateTime,
    max_allowed_date: DateTime,
    support_for_attributes: bool,
    support_unsigned_big_int: bool,
    message: Message,
}

/// The options of [`Filter::with_options`] past the attributes and the id
/// type, with PHP's defaults.
#[derive(Clone, Debug)]
pub struct FilterOptions {
    /// `$maxValuesCount` (5000).
    pub max_values_count: i64,
    /// `$minAllowedDate` (`0000-01-01`).
    pub min_allowed_date: DateTime,
    /// `$maxAllowedDate` (`9999-12-31`).
    pub max_allowed_date: DateTime,
    /// `$supportForAttributes` (true).
    pub support_for_attributes: bool,
    /// `$supportUnsignedBigInt` (true).
    pub support_unsigned_big_int: bool,
}

impl Default for FilterOptions {
    fn default() -> Self {
        Self {
            max_values_count: 5000,
            min_allowed_date: default_min(),
            max_allowed_date: default_max(),
            support_for_attributes: true,
            support_unsigned_big_int: true,
        }
    }
}

const STRING_METHODS: [&str; 5] = ["string", "varchar", "text", "mediumtext", "longtext"];

/// `in_array($needle, $haystack)` (loose) of a value among strings.
fn in_strings(needle: &Value, haystack: &[&str]) -> bool {
    haystack.iter().any(|h| needle.loose_eq(&Value::from(*h)))
}

/// `ucfirst($method)`.
fn ucfirst(s: &str) -> String {
    let mut c = s.chars();
    match c.next() {
        Some(f) => f.to_ascii_uppercase().to_string() + c.as_str(),
        None => String::new(),
    }
}

/// Whether a query of this method is a filter (`Query::groupByType`
/// puts it under `filters`).
pub(crate) fn is_filter(method: &Method) -> bool {
    !matches!(
        method,
        Method::OrderAsc
            | Method::OrderDesc
            | Method::OrderRandom
            | Method::Limit
            | Method::Offset
            | Method::CursorAfter
            | Method::CursorBefore
            | Method::Select
    )
}

/// How many values are filter queries (`count(Query::groupByType($values)['filters'])`).
pub(crate) fn filters<'a>(values: impl IntoIterator<Item = &'a Value>) -> usize {
    values.into_iter().filter_map(as_query).filter(|q| is_filter(q.method())).count()
}

/// `count($value)` of an array value.
fn count(value: &Value) -> Option<usize> {
    match value {
        Value::Array(a) => Some(a.len()),
        _ => None,
    }
}

impl Filter {
    /// `new Filter($attributes, $idAttributeType)` with PHP's defaults.
    pub fn new(attributes: &[Document], id_attribute_type: impl Into<String>) -> Self {
        Self::with_options(attributes, id_attribute_type, FilterOptions::default())
    }

    /// `new Filter(...)` with every option.
    pub fn with_options(attributes: &[Document], id_attribute_type: impl Into<String>, options: FilterOptions) -> Self {
        let mut schema = Array::new();
        for attribute in attributes {
            let key = match attribute.get("key") {
                Some(k) => k.clone(),
                None => Value::from(attribute.id()),
            };
            schema.set(array_key(&key), Value::Array(attribute.array_copy(&[], &[])));
        }
        Self {
            schema,
            id_attribute_type: id_attribute_type.into(),
            max_values_count: options.max_values_count,
            min_allowed_date: options.min_allowed_date,
            max_allowed_date: options.max_allowed_date,
            support_for_attributes: options.support_for_attributes,
            support_unsigned_big_int: options.support_unsigned_big_int,
            message: Message::new(INVALID_QUERY),
        }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    /// `getMaxValuesCount()`.
    pub fn max_values_count(&self) -> i64 {
        self.max_values_count
    }

    fn schema_of(&self, attribute: &str) -> Option<&Value> {
        self.schema.get(&text_key(attribute)).filter(|v| !matches!(v, Value::Null))
    }

    fn has(&self, attribute: &str) -> bool {
        self.schema_of(attribute).is_some()
    }

    fn fail(&self, message: impl Into<String>) -> bool {
        self.message.set(message);
        false
    }

    fn is_valid_attribute(&self, attribute: &str) -> bool {
        if let Some(Value::Array(schema)) = self.schema_of(attribute)
            && let Some(Value::Array(filters)) = schema.get_str("filters")
            && filters.values().any(|f| f.loose_eq(&Value::from("encrypt")))
        {
            return self.fail(format!("Cannot query encrypted attribute: {attribute}"));
        }
        let mut attribute = attribute;
        if attribute.contains('.') {
            if self.has(attribute) {
                return true;
            }
            attribute = attribute.split('.').next().unwrap_or("");
        }
        if self.support_for_attributes && !self.has(attribute) {
            return self.fail(format!("Attribute not found in schema: {attribute}"));
        }
        true
    }

    fn is_valid_attribute_and_values(&self, attribute: &str, values: &[Value], method: &Method) -> bool {
        if !self.is_valid_attribute(attribute) {
            return false;
        }
        let original = attribute;
        let attribute = if attribute.contains('.') && !self.has(attribute) {
            attribute.split('.').next().unwrap_or("")
        } else {
            attribute
        };

        if matches!(method, Method::Exists | Method::NotExists) {
            return self.is_valid_attribute(attribute);
        }

        if !self.support_for_attributes && !self.has(attribute) {
            if values.len() as i64 > self.max_values_count {
                return self.fail(format!(
                    "Query on attribute has greater than {} values: {attribute}",
                    self.max_values_count
                ));
            }
            return true;
        }

        const NULL: &Value = &Value::Null;
        let schema = self.schema_of(attribute).unwrap_or(NULL);
        let kind = index(schema, "type");

        if kind.strict_eq(&Value::from(Database::VAR_RELATIONSHIP)) && original != attribute {
            return true;
        }
        if values.len() as i64 > self.max_values_count {
            return self
                .fail(format!("Query on attribute has greater than {} values: {attribute}", self.max_values_count));
        }

        let kind_str = match kind {
            Value::Str(s) => s.as_str(),
            _ => None,
        };
        let dotted_on_object = original.contains('.') && kind_str == Some(Database::VAR_OBJECT);

        if method.is_spatial() && !matches!(kind_str, Some("point" | "linestring" | "polygon")) {
            return self
                .fail(format!("Spatial query \"{method}\" cannot be applied on non-spatial attribute: {attribute}"));
        }

        for value in values {
            let valid = match kind_str {
                Some(Database::VAR_ID) => {
                    Sequence::new(self.id_attribute_type.clone(), attribute == "$sequence").is_valid(value)
                }
                Some("string" | "varchar" | "text" | "mediumtext" | "longtext") => {
                    accepts(&Text::with_min(0, 0), value)
                }
                Some(Database::VAR_INTEGER) => {
                    let four = Value::Int(4);
                    let size = index_or(schema, "size", &four);
                    let signed = index_or(schema, "signed", &Value::Bool(true)).truthy();
                    let bits = if size.compare(&Value::Int(8)) >= 0 { 64 } else { 32 };
                    let unsigned = !signed && bits < 64;
                    match Integer::new(false, bits, unsigned) {
                        Ok(v) => accepts(&v, value),
                        Err(_) => false,
                    }
                }
                Some(Database::VAR_BIGINT) => {
                    let signed = index_or(schema, "signed", &Value::Bool(true)).truthy();
                    BigInt::new(signed, self.support_unsigned_big_int).is_valid(value)
                }
                Some(Database::VAR_FLOAT) => accepts(&Float::default(), value),
                Some(Database::VAR_BOOLEAN) => accepts(&Boolean::STRICT, value),
                Some(Database::VAR_DATETIME) => {
                    Datetime::between(self.min_allowed_date.clone(), self.max_allowed_date.clone()).is_valid(value)
                }
                Some(Database::VAR_RELATIONSHIP) => accepts(&Text::with_min(255, 0), value),
                Some(Database::VAR_OBJECT) => {
                    if dotted_on_object {
                        accepts(&Text::with_min(0, 0), value)
                    } else {
                        if matches!(
                            method,
                            Method::Equal
                                | Method::NotEqual
                                | Method::Contains
                                | Method::ContainsAny
                                | Method::ContainsAll
                                | Method::NotContains
                        ) && !valid_object_query_values(value)
                        {
                            return self.fail(format!("Invalid object query structure for attribute \"{attribute}\""));
                        }
                        continue;
                    }
                }
                Some("point" | "linestring" | "polygon") => {
                    if !matches!(value, Value::Array(_)) {
                        return self.fail("Spatial data must be an array");
                    }
                    continue;
                }
                Some(Database::VAR_VECTOR) => {
                    let Value::Array(components) = value else {
                        return self.fail("Vector query value must be an array");
                    };
                    if !components.values().all(is_numeric) {
                        return self.fail("Vector query value must contain only numeric values");
                    }
                    let zero = Value::Int(0);
                    let expected = index_or(schema, "size", &zero);
                    if !Value::Int(components.len() as i64).strict_eq(expected) {
                        return self.fail(format!("Vector query value must have {} elements", text(expected)));
                    }
                    continue;
                }
                _ => return self.fail("Unknown Data type"),
            };
            if !valid {
                return self.fail(format!("Query value is invalid for attribute \"{attribute}\""));
            }
        }

        if kind_str == Some(Database::VAR_RELATIONSHIP) {
            let options = index(schema, "options");
            let relation_type = index(options, "relationType");
            let side = index(options, "side");
            let is = |v: &Value, s: &str| v.strict_eq(&Value::from(s));
            let virtual_side = (is(relation_type, Database::RELATION_ONE_TO_ONE)
                && index(options, "twoWay").strict_eq(&Value::Bool(false))
                && is(side, Database::RELATION_SIDE_CHILD))
                || (is(relation_type, Database::RELATION_ONE_TO_MANY) && is(side, Database::RELATION_SIDE_PARENT))
                || (is(relation_type, Database::RELATION_MANY_TO_ONE) && is(side, Database::RELATION_SIDE_CHILD))
                || is(relation_type, Database::RELATION_MANY_TO_MANY);
            if virtual_side {
                return self.fail("Cannot query on virtual relationship attribute");
            }
        }

        let array = index_or(schema, "array", &Value::Bool(false)).truthy();
        let contains =
            matches!(method, Method::Contains | Method::ContainsAny | Method::ContainsAll | Method::NotContains);
        if !array
            && contains
            && !in_strings(kind, &STRING_METHODS)
            && !kind.strict_eq(&Value::from(Database::VAR_OBJECT))
            && !in_strings(kind, &Database::SPATIAL_TYPES)
        {
            let query_type = if method == &Method::NotContains { "notContains" } else { "contains" };
            return self.fail(format!(
                "Cannot query {query_type} on attribute \"{attribute}\" because it is not an array, string, or object."
            ));
        }
        if array
            && !contains
            && !matches!(method, Method::IsNull | Method::IsNotNull | Method::Exists | Method::NotExists)
        {
            return self.fail(format!("Cannot query {method} on attribute \"{attribute}\" because it is an array."));
        }
        if method.is_vector() {
            if !kind.strict_eq(&Value::from(Database::VAR_VECTOR)) {
                return self.fail("Vector queries can only be used on vector attributes");
            }
            if array {
                return self.fail("Vector queries cannot be used on array attributes");
            }
        }
        true
    }

    /// `isEmpty($values)`: no values, or a first value that is an empty array.
    fn is_empty(values: &[Value]) -> bool {
        match values.first() {
            None => true,
            Some(Value::Array(a)) => a.is_empty(),
            Some(_) => false,
        }
    }

    /// `isValid($query)`.
    pub fn check(&self, query: &Query) -> bool {
        let method = query.method();
        let attribute = query.attribute();
        let values = query.values();
        match method {
            Method::Equal
            | Method::Contains
            | Method::ContainsAny
            | Method::NotContains
            | Method::ContainsAll
            | Method::Exists
            | Method::NotExists => {
                if Self::is_empty(values) {
                    return self.fail(format!("{} queries require at least one value.", ucfirst(method.as_str())));
                }
                self.is_valid_attribute_and_values(attribute, values, method)
            }
            Method::DistanceEqual
            | Method::DistanceNotEqual
            | Method::DistanceGreaterThan
            | Method::DistanceLessThan => {
                if values.len() != 1 || count(&values[0]) != Some(3) {
                    return self.fail("Distance query requires [[geometry, distance]] parameters");
                }
                self.is_valid_attribute_and_values(attribute, values, method)
            }
            Method::NotEqual
            | Method::LessThan
            | Method::LessThanEqual
            | Method::GreaterThan
            | Method::GreaterThanEqual
            | Method::Search
            | Method::NotSearch
            | Method::StartsWith
            | Method::NotStartsWith
            | Method::EndsWith
            | Method::NotEndsWith
            | Method::Regex => {
                if values.len() != 1 {
                    return self.fail(format!("{} queries require exactly one value.", ucfirst(method.as_str())));
                }
                self.is_valid_attribute_and_values(attribute, values, method)
            }
            Method::Between | Method::NotBetween => {
                if values.len() != 2 {
                    return self.fail(format!("{} queries require exactly two values.", ucfirst(method.as_str())));
                }
                self.is_valid_attribute_and_values(attribute, values, method)
            }
            Method::IsNull | Method::IsNotNull => self.is_valid_attribute_and_values(attribute, values, method),
            Method::VectorDot | Method::VectorCosine | Method::VectorEuclidean => {
                if !self.is_valid_attribute(attribute) {
                    return false;
                }
                let key = if attribute.contains('.') && !self.has(attribute) {
                    attribute.split('.').next().unwrap_or("")
                } else {
                    attribute
                };
                let kind = self.schema_of(key).map_or(&Value::Null, |s| index(s, "type"));
                if !kind.strict_eq(&Value::from(Database::VAR_VECTOR)) {
                    return self.fail("Vector queries can only be used on vector attributes");
                }
                if values.len() != 1 {
                    return self
                        .fail(format!("{} queries require exactly one vector value.", ucfirst(method.as_str())));
                }
                self.is_valid_attribute_and_values(attribute, values, method)
            }
            Method::Or | Method::And => {
                let filters = filters(values);
                if values.len() != filters {
                    return self.fail(format!("{} queries can only contain filter queries", ucfirst(method.as_str())));
                }
                if filters < 2 {
                    return self.fail(format!("{} queries require at least two queries", ucfirst(method.as_str())));
                }
                true
            }
            Method::ElemMatch => {
                if self.support_for_attributes {
                    return self.fail("elemMatch is not supported by the database");
                }
                if !self.is_valid_attribute(attribute) {
                    return false;
                }
                let filters = filters(values);
                if values.len() != filters {
                    return self.fail("elemMatch queries can only contain filter queries");
                }
                if filters < 1 {
                    return self.fail("elemMatch queries require at least one query");
                }
                true
            }
            _ if method.is_spatial() => {
                if Self::is_empty(values) {
                    return self.fail(format!("{} queries require at least one value.", ucfirst(method.as_str())));
                }
                self.is_valid_attribute_and_values(attribute, values, method)
            }
            _ => false,
        }
    }
}

/// `isValidObjectQueryValues($values)`: no array mixes integer and string
/// keys, at any depth.
fn valid_object_query_values(value: &Value) -> bool {
    let Value::Array(a) = value else { return true };
    let has_int = a.keys().any(|k| matches!(k, KeyRef::Int(_)));
    let has_string = a.keys().any(|k| matches!(k, KeyRef::Str(_)));
    if has_int && has_string {
        return false;
    }
    a.values().all(valid_object_query_values)
}

query_validator!(Filter, MethodType::Filter);
