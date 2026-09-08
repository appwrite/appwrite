//! PHP `Utopia\Database\Query`.

use md5::{Digest, Md5};
use serde_json::{Map, Value};

use crate::constants::{
    CURSOR_AFTER, CURSOR_BEFORE, ORDER_ASC, ORDER_DESC, ORDER_RANDOM, SPATIAL_TYPES, VAR_OBJECT,
};
use crate::document::Document;
use crate::error::{DatabaseError, Result};
use crate::value::{php_gettype, AttrValue};

pub const TYPE_EQUAL: &str = "equal";
pub const TYPE_NOT_EQUAL: &str = "notEqual";
pub const TYPE_LESSER: &str = "lessThan";
pub const TYPE_LESSER_EQUAL: &str = "lessThanEqual";
pub const TYPE_GREATER: &str = "greaterThan";
pub const TYPE_GREATER_EQUAL: &str = "greaterThanEqual";
pub const TYPE_CONTAINS: &str = "contains";
pub const TYPE_CONTAINS_ANY: &str = "containsAny";
pub const TYPE_NOT_CONTAINS: &str = "notContains";
pub const TYPE_SEARCH: &str = "search";
pub const TYPE_NOT_SEARCH: &str = "notSearch";
pub const TYPE_IS_NULL: &str = "isNull";
pub const TYPE_IS_NOT_NULL: &str = "isNotNull";
pub const TYPE_BETWEEN: &str = "between";
pub const TYPE_NOT_BETWEEN: &str = "notBetween";
pub const TYPE_STARTS_WITH: &str = "startsWith";
pub const TYPE_NOT_STARTS_WITH: &str = "notStartsWith";
pub const TYPE_ENDS_WITH: &str = "endsWith";
pub const TYPE_NOT_ENDS_WITH: &str = "notEndsWith";
pub const TYPE_REGEX: &str = "regex";
pub const TYPE_EXISTS: &str = "exists";
pub const TYPE_NOT_EXISTS: &str = "notExists";
pub const TYPE_CROSSES: &str = "crosses";
pub const TYPE_NOT_CROSSES: &str = "notCrosses";
pub const TYPE_DISTANCE_EQUAL: &str = "distanceEqual";
pub const TYPE_DISTANCE_NOT_EQUAL: &str = "distanceNotEqual";
pub const TYPE_DISTANCE_GREATER_THAN: &str = "distanceGreaterThan";
pub const TYPE_DISTANCE_LESS_THAN: &str = "distanceLessThan";
pub const TYPE_INTERSECTS: &str = "intersects";
pub const TYPE_NOT_INTERSECTS: &str = "notIntersects";
pub const TYPE_OVERLAPS: &str = "overlaps";
pub const TYPE_NOT_OVERLAPS: &str = "notOverlaps";
pub const TYPE_TOUCHES: &str = "touches";
pub const TYPE_NOT_TOUCHES: &str = "notTouches";
pub const TYPE_VECTOR_DOT: &str = "vectorDot";
pub const TYPE_VECTOR_COSINE: &str = "vectorCosine";
pub const TYPE_VECTOR_EUCLIDEAN: &str = "vectorEuclidean";
pub const TYPE_SELECT: &str = "select";
pub const TYPE_ORDER_DESC: &str = "orderDesc";
pub const TYPE_ORDER_ASC: &str = "orderAsc";
pub const TYPE_ORDER_RANDOM: &str = "orderRandom";
pub const TYPE_LIMIT: &str = "limit";
pub const TYPE_OFFSET: &str = "offset";
pub const TYPE_CURSOR_AFTER: &str = "cursorAfter";
pub const TYPE_CURSOR_BEFORE: &str = "cursorBefore";
pub const TYPE_AND: &str = "and";
pub const TYPE_OR: &str = "or";
pub const TYPE_CONTAINS_ALL: &str = "containsAll";
pub const TYPE_ELEM_MATCH: &str = "elemMatch";
pub const DEFAULT_ALIAS: &str = "main";

pub const TYPES: &[&str] = &[
    TYPE_EQUAL,
    TYPE_NOT_EQUAL,
    TYPE_LESSER,
    TYPE_LESSER_EQUAL,
    TYPE_GREATER,
    TYPE_GREATER_EQUAL,
    TYPE_CONTAINS,
    TYPE_CONTAINS_ANY,
    TYPE_NOT_CONTAINS,
    TYPE_SEARCH,
    TYPE_NOT_SEARCH,
    TYPE_IS_NULL,
    TYPE_IS_NOT_NULL,
    TYPE_BETWEEN,
    TYPE_NOT_BETWEEN,
    TYPE_STARTS_WITH,
    TYPE_NOT_STARTS_WITH,
    TYPE_ENDS_WITH,
    TYPE_NOT_ENDS_WITH,
    TYPE_CROSSES,
    TYPE_NOT_CROSSES,
    TYPE_DISTANCE_EQUAL,
    TYPE_DISTANCE_NOT_EQUAL,
    TYPE_DISTANCE_GREATER_THAN,
    TYPE_DISTANCE_LESS_THAN,
    TYPE_INTERSECTS,
    TYPE_NOT_INTERSECTS,
    TYPE_OVERLAPS,
    TYPE_NOT_OVERLAPS,
    TYPE_TOUCHES,
    TYPE_NOT_TOUCHES,
    TYPE_VECTOR_DOT,
    TYPE_VECTOR_COSINE,
    TYPE_VECTOR_EUCLIDEAN,
    TYPE_EXISTS,
    TYPE_NOT_EXISTS,
    TYPE_SELECT,
    TYPE_ORDER_DESC,
    TYPE_ORDER_ASC,
    TYPE_ORDER_RANDOM,
    TYPE_LIMIT,
    TYPE_OFFSET,
    TYPE_CURSOR_AFTER,
    TYPE_CURSOR_BEFORE,
    TYPE_AND,
    TYPE_OR,
    TYPE_CONTAINS_ALL,
    TYPE_ELEM_MATCH,
    TYPE_REGEX,
];

pub const VECTOR_TYPES: &[&str] = &[TYPE_VECTOR_DOT, TYPE_VECTOR_COSINE, TYPE_VECTOR_EUCLIDEAN];
const LOGICAL_TYPES: &[&str] = &[TYPE_AND, TYPE_OR, TYPE_ELEM_MATCH];

/// Grouped query view from PHP `Query::groupByType`.
#[derive(Debug, Clone)]
pub struct GroupedQueries {
    pub filters: Vec<Query>,
    pub selections: Vec<Query>,
    pub limit: Option<i64>,
    pub offset: Option<i64>,
    pub order_attributes: Vec<String>,
    pub order_types: Vec<String>,
    pub cursor: Option<Document>,
    pub cursor_direction: Option<String>,
}

/// PHP `Utopia\Database\Query`.
#[derive(Debug, Clone)]
pub struct Query {
    method: String,
    attribute: String,
    attribute_type: String,
    on_array: bool,
    values: Vec<AttrValue>,
}

impl Query {
    pub const TYPE_LIMIT: &'static str = TYPE_LIMIT;
    pub const TYPE_OFFSET: &'static str = TYPE_OFFSET;
    pub const TYPE_CURSOR_AFTER: &'static str = TYPE_CURSOR_AFTER;
    pub const TYPE_CURSOR_BEFORE: &'static str = TYPE_CURSOR_BEFORE;
    pub const TYPE_ORDER_ASC: &'static str = TYPE_ORDER_ASC;
    pub const TYPE_ORDER_DESC: &'static str = TYPE_ORDER_DESC;
    pub const TYPE_ORDER_RANDOM: &'static str = TYPE_ORDER_RANDOM;
    pub const TYPE_SELECT: &'static str = TYPE_SELECT;

    #[must_use]
    pub fn new(
        method: impl Into<String>,
        attribute: impl Into<String>,
        values: Vec<AttrValue>,
    ) -> Self {
        let method = method.into();
        let mut attribute = attribute.into();
        if attribute.is_empty() && (method == TYPE_ORDER_ASC || method == TYPE_ORDER_DESC) {
            attribute = "$sequence".into();
        }
        Self {
            method,
            attribute,
            attribute_type: String::new(),
            on_array: false,
            values,
        }
    }

    #[must_use]
    pub fn get_method(&self) -> &str {
        &self.method
    }

    #[must_use]
    pub fn get_attribute(&self) -> &str {
        &self.attribute
    }

    #[must_use]
    pub fn get_values(&self) -> &[AttrValue] {
        &self.values
    }

    #[must_use]
    pub fn values(&self) -> &[AttrValue] {
        &self.values
    }

    #[must_use]
    pub fn get_value(&self) -> &AttrValue {
        self.values.first().unwrap_or(&AttrValue::Null)
    }

    pub fn set_method(&mut self, method: impl Into<String>) -> &mut Self {
        self.method = method.into();
        self
    }

    pub fn set_attribute(&mut self, attribute: impl Into<String>) -> &mut Self {
        self.attribute = attribute.into();
        self
    }

    pub fn set_values(&mut self, values: Vec<AttrValue>) -> &mut Self {
        self.values = values;
        self
    }

    pub fn set_value(&mut self, value: impl Into<AttrValue>) -> &mut Self {
        self.values = vec![value.into()];
        self
    }

    #[must_use]
    pub fn is_method(value: &str) -> bool {
        matches!(
            value,
            TYPE_EQUAL
                | TYPE_NOT_EQUAL
                | TYPE_LESSER
                | TYPE_LESSER_EQUAL
                | TYPE_GREATER
                | TYPE_GREATER_EQUAL
                | TYPE_CONTAINS
                | TYPE_CONTAINS_ANY
                | TYPE_NOT_CONTAINS
                | TYPE_SEARCH
                | TYPE_NOT_SEARCH
                | TYPE_ORDER_ASC
                | TYPE_ORDER_DESC
                | TYPE_ORDER_RANDOM
                | TYPE_LIMIT
                | TYPE_OFFSET
                | TYPE_CURSOR_AFTER
                | TYPE_CURSOR_BEFORE
                | TYPE_IS_NULL
                | TYPE_IS_NOT_NULL
                | TYPE_BETWEEN
                | TYPE_NOT_BETWEEN
                | TYPE_STARTS_WITH
                | TYPE_NOT_STARTS_WITH
                | TYPE_ENDS_WITH
                | TYPE_NOT_ENDS_WITH
                | TYPE_CROSSES
                | TYPE_NOT_CROSSES
                | TYPE_DISTANCE_EQUAL
                | TYPE_DISTANCE_NOT_EQUAL
                | TYPE_DISTANCE_GREATER_THAN
                | TYPE_DISTANCE_LESS_THAN
                | TYPE_INTERSECTS
                | TYPE_NOT_INTERSECTS
                | TYPE_OVERLAPS
                | TYPE_NOT_OVERLAPS
                | TYPE_TOUCHES
                | TYPE_NOT_TOUCHES
                | TYPE_OR
                | TYPE_AND
                | TYPE_CONTAINS_ALL
                | TYPE_ELEM_MATCH
                | TYPE_SELECT
                | TYPE_VECTOR_DOT
                | TYPE_VECTOR_COSINE
                | TYPE_VECTOR_EUCLIDEAN
                | TYPE_EXISTS
                | TYPE_NOT_EXISTS
        )
    }

    #[must_use]
    pub fn is_spatial_query(&self) -> bool {
        matches!(
            self.method.as_str(),
            TYPE_CROSSES
                | TYPE_NOT_CROSSES
                | TYPE_DISTANCE_EQUAL
                | TYPE_DISTANCE_NOT_EQUAL
                | TYPE_DISTANCE_GREATER_THAN
                | TYPE_DISTANCE_LESS_THAN
                | TYPE_INTERSECTS
                | TYPE_NOT_INTERSECTS
                | TYPE_OVERLAPS
                | TYPE_NOT_OVERLAPS
                | TYPE_TOUCHES
                | TYPE_NOT_TOUCHES
        )
    }

    pub fn parse(query: &str) -> Result<Self> {
        let decoded: Value = serde_json::from_str(query)
            .map_err(|e| DatabaseError::query(format!("Invalid query: {e}")))?;
        let Value::Object(obj) = decoded else {
            return Err(DatabaseError::query(format!(
                "Invalid query. Must be an array, got {}",
                php_gettype(&decoded)
            )));
        };
        Self::parse_query(&obj)
    }

    pub fn parse_query(query: &Map<String, Value>) -> Result<Self> {
        let method_v = query
            .get("method")
            .cloned()
            .unwrap_or(Value::String(String::new()));
        let Value::String(method) = method_v else {
            return Err(DatabaseError::query(format!(
                "Invalid query method. Must be a string, got {}",
                php_gettype(&method_v)
            )));
        };
        if !Self::is_method(&method) {
            return Err(DatabaseError::query(format!(
                "Invalid query method: {method}"
            )));
        }
        let attribute_v = query
            .get("attribute")
            .cloned()
            .unwrap_or(Value::String(String::new()));
        let Value::String(attribute) = attribute_v else {
            return Err(DatabaseError::query(format!(
                "Invalid query attribute. Must be a string, got {}",
                php_gettype(&attribute_v)
            )));
        };
        let values_v = query.get("values").cloned().unwrap_or(Value::Array(vec![]));
        let Value::Array(values) = values_v else {
            return Err(DatabaseError::query(format!(
                "Invalid query values. Must be an array, got {}",
                php_gettype(&values_v)
            )));
        };
        let mut parsed_values = Vec::new();
        if LOGICAL_TYPES.contains(&method.as_str()) {
            for value in values {
                let Value::Object(obj) = value else {
                    return Err(DatabaseError::query(
                        "Invalid query. Must be an array, got NULL",
                    ));
                };
                parsed_values.push(AttrValue::Query(Box::new(Self::parse_query(&obj)?)));
            }
        } else {
            for value in values {
                parsed_values.push(AttrValue::from_json(value));
            }
        }
        Ok(Self::new(method, attribute, parsed_values))
    }

    pub fn parse_queries(queries: &[String]) -> Result<Vec<Self>> {
        queries.iter().map(|q| Self::parse(q)).collect()
    }

    pub fn fingerprint(queries: &[AttrValue]) -> Result<String> {
        let mut shapes = Vec::new();
        for query in queries {
            let q = match query {
                AttrValue::String(s) => Self::parse(s)?,
                AttrValue::Query(q) => *q.clone(),
                _ => {
                    return Err(DatabaseError::query(
                        "Invalid query element for fingerprint: expected string or Query instance",
                    ));
                }
            };
            shapes.push(q.shape());
        }
        shapes.sort();
        let joined = shapes.join("|");
        let digest = Md5::digest(joined.as_bytes());
        Ok(format!("{digest:x}"))
    }

    #[must_use]
    pub fn shape(&self) -> String {
        fn walk(node: &Query, out: &mut Vec<String>) {
            if LOGICAL_TYPES.contains(&node.method.as_str()) {
                let mut child_shapes = Vec::new();
                for child in &node.values {
                    if let AttrValue::Query(q) = child {
                        walk(q, &mut child_shapes);
                    }
                }
                child_shapes.sort();
                out.push(format!(
                    "{}:{}({})",
                    node.method,
                    node.attribute,
                    child_shapes.join("|")
                ));
            } else {
                out.push(format!("{}:{}", node.method, node.attribute));
            }
        }
        let mut shapes = Vec::new();
        walk(self, &mut shapes);
        shapes.pop().unwrap_or_default()
    }

    #[must_use]
    pub fn eq_shape(&self, other: &Self) -> bool {
        self.method == other.method && self.attribute == other.attribute
    }

    #[must_use]
    pub fn to_array(&self) -> Map<String, Value> {
        let mut array = Map::new();
        array.insert("method".into(), Value::String(self.method.clone()));
        if !self.attribute.is_empty() {
            array.insert("attribute".into(), Value::String(self.attribute.clone()));
        }
        if LOGICAL_TYPES.contains(&self.method.as_str()) {
            let values: Vec<Value> = self
                .values
                .iter()
                .filter_map(|v| v.as_query().map(|q| Value::Object(q.to_array())))
                .collect();
            array.insert("values".into(), Value::Array(values));
        } else {
            let mut values = Vec::new();
            for value in &self.values {
                if matches!(self.method.as_str(), TYPE_CURSOR_AFTER | TYPE_CURSOR_BEFORE) {
                    if let AttrValue::Document(doc) = value {
                        values.push(Value::String(doc.get_id()));
                        continue;
                    }
                }
                values.push(value.to_json());
            }
            array.insert("values".into(), Value::Array(values));
        }
        array
    }

    #[must_use]
    pub fn to_json_value(&self) -> Value {
        Value::Object(self.to_array())
    }

    pub fn to_string(&self) -> Result<String> {
        serde_json::to_string(&self.to_json_value())
            .map_err(|e| DatabaseError::query(format!("Invalid Json: {e}")))
    }

    #[must_use]
    pub fn equal(attribute: impl Into<String>, values: Vec<AttrValue>) -> Self {
        Self::new(TYPE_EQUAL, attribute, values)
    }

    #[must_use]
    pub fn not_equal(attribute: impl Into<String>, value: AttrValue) -> Self {
        let values = if value.is_list() {
            match value {
                AttrValue::Array(items) => items.into_values().collect(),
                other => vec![other],
            }
        } else {
            vec![value]
        };
        Self::new(TYPE_NOT_EQUAL, attribute, values)
    }

    #[must_use]
    pub fn less_than(attribute: impl Into<String>, value: impl Into<AttrValue>) -> Self {
        Self::new(TYPE_LESSER, attribute, vec![value.into()])
    }
    #[must_use]
    pub fn less_than_equal(attribute: impl Into<String>, value: impl Into<AttrValue>) -> Self {
        Self::new(TYPE_LESSER_EQUAL, attribute, vec![value.into()])
    }
    #[must_use]
    pub fn greater_than(attribute: impl Into<String>, value: impl Into<AttrValue>) -> Self {
        Self::new(TYPE_GREATER, attribute, vec![value.into()])
    }
    #[must_use]
    pub fn greater_than_equal(attribute: impl Into<String>, value: impl Into<AttrValue>) -> Self {
        Self::new(TYPE_GREATER_EQUAL, attribute, vec![value.into()])
    }
    #[must_use]
    pub fn contains(attribute: impl Into<String>, values: Vec<AttrValue>) -> Self {
        Self::new(TYPE_CONTAINS, attribute, values)
    }
    #[must_use]
    pub fn contains_any(attribute: impl Into<String>, values: Vec<AttrValue>) -> Self {
        Self::new(TYPE_CONTAINS_ANY, attribute, values)
    }
    #[must_use]
    pub fn not_contains(attribute: impl Into<String>, values: Vec<AttrValue>) -> Self {
        Self::new(TYPE_NOT_CONTAINS, attribute, values)
    }
    #[must_use]
    pub fn between(
        attribute: impl Into<String>,
        start: impl Into<AttrValue>,
        end: impl Into<AttrValue>,
    ) -> Self {
        Self::new(TYPE_BETWEEN, attribute, vec![start.into(), end.into()])
    }
    #[must_use]
    pub fn not_between(
        attribute: impl Into<String>,
        start: impl Into<AttrValue>,
        end: impl Into<AttrValue>,
    ) -> Self {
        Self::new(TYPE_NOT_BETWEEN, attribute, vec![start.into(), end.into()])
    }
    #[must_use]
    pub fn search(attribute: impl Into<String>, value: impl Into<String>) -> Self {
        Self::new(
            TYPE_SEARCH,
            attribute,
            vec![AttrValue::String(value.into())],
        )
    }
    #[must_use]
    pub fn not_search(attribute: impl Into<String>, value: impl Into<String>) -> Self {
        Self::new(
            TYPE_NOT_SEARCH,
            attribute,
            vec![AttrValue::String(value.into())],
        )
    }
    #[must_use]
    pub fn select(attributes: Vec<String>) -> Self {
        Self::new(
            TYPE_SELECT,
            "",
            attributes.into_iter().map(AttrValue::String).collect(),
        )
    }
    #[must_use]
    pub fn order_desc(attribute: impl Into<String>) -> Self {
        Self::new(TYPE_ORDER_DESC, attribute, vec![])
    }
    #[must_use]
    pub fn order_asc(attribute: impl Into<String>) -> Self {
        Self::new(TYPE_ORDER_ASC, attribute, vec![])
    }
    #[must_use]
    pub fn order_random() -> Self {
        Self::new(TYPE_ORDER_RANDOM, "", vec![])
    }
    #[must_use]
    pub fn limit(value: i64) -> Self {
        Self::new(TYPE_LIMIT, "", vec![AttrValue::from(value)])
    }
    #[must_use]
    pub fn offset(value: i64) -> Self {
        Self::new(TYPE_OFFSET, "", vec![AttrValue::from(value)])
    }
    #[must_use]
    pub fn cursor_after(value: Document) -> Self {
        Self::new(
            TYPE_CURSOR_AFTER,
            "",
            vec![AttrValue::Document(Box::new(value))],
        )
    }
    #[must_use]
    pub fn cursor_before(value: Document) -> Self {
        Self::new(
            TYPE_CURSOR_BEFORE,
            "",
            vec![AttrValue::Document(Box::new(value))],
        )
    }
    #[must_use]
    pub fn is_null(attribute: impl Into<String>) -> Self {
        Self::new(TYPE_IS_NULL, attribute, vec![])
    }
    #[must_use]
    pub fn is_not_null(attribute: impl Into<String>) -> Self {
        Self::new(TYPE_IS_NOT_NULL, attribute, vec![])
    }
    #[must_use]
    pub fn starts_with(attribute: impl Into<String>, value: impl Into<String>) -> Self {
        Self::new(
            TYPE_STARTS_WITH,
            attribute,
            vec![AttrValue::String(value.into())],
        )
    }
    #[must_use]
    pub fn not_starts_with(attribute: impl Into<String>, value: impl Into<String>) -> Self {
        Self::new(
            TYPE_NOT_STARTS_WITH,
            attribute,
            vec![AttrValue::String(value.into())],
        )
    }
    #[must_use]
    pub fn ends_with(attribute: impl Into<String>, value: impl Into<String>) -> Self {
        Self::new(
            TYPE_ENDS_WITH,
            attribute,
            vec![AttrValue::String(value.into())],
        )
    }
    #[must_use]
    pub fn not_ends_with(attribute: impl Into<String>, value: impl Into<String>) -> Self {
        Self::new(
            TYPE_NOT_ENDS_WITH,
            attribute,
            vec![AttrValue::String(value.into())],
        )
    }
    #[must_use]
    pub fn created_before(value: impl Into<String>) -> Self {
        Self::less_than("$createdAt", value.into())
    }
    #[must_use]
    pub fn created_after(value: impl Into<String>) -> Self {
        Self::greater_than("$createdAt", value.into())
    }
    #[must_use]
    pub fn updated_before(value: impl Into<String>) -> Self {
        Self::less_than("$updatedAt", value.into())
    }
    #[must_use]
    pub fn updated_after(value: impl Into<String>) -> Self {
        Self::greater_than("$updatedAt", value.into())
    }
    #[must_use]
    pub fn created_between(start: impl Into<String>, end: impl Into<String>) -> Self {
        Self::between("$createdAt", start.into(), end.into())
    }
    #[must_use]
    pub fn updated_between(start: impl Into<String>, end: impl Into<String>) -> Self {
        Self::between("$updatedAt", start.into(), end.into())
    }
    #[must_use]
    pub fn or(queries: Vec<Query>) -> Self {
        Self::new(
            TYPE_OR,
            "",
            queries.into_iter().map(AttrValue::from).collect(),
        )
    }
    #[must_use]
    pub fn and(queries: Vec<Query>) -> Self {
        Self::new(
            TYPE_AND,
            "",
            queries.into_iter().map(AttrValue::from).collect(),
        )
    }
    #[must_use]
    pub fn contains_all(attribute: impl Into<String>, values: Vec<AttrValue>) -> Self {
        Self::new(TYPE_CONTAINS_ALL, attribute, values)
    }

    #[must_use]
    pub fn get_by_type(queries: &[Query], types: &[&str], clone: bool) -> Vec<Query> {
        queries
            .iter()
            .filter(|q| types.contains(&q.get_method()))
            .map(|q| if clone { q.clone() } else { q.clone() })
            .collect()
    }

    #[must_use]
    pub fn get_cursor_queries(queries: &[Query], clone: bool) -> Vec<Query> {
        Self::get_by_type(queries, &[TYPE_CURSOR_AFTER, TYPE_CURSOR_BEFORE], clone)
    }

    #[must_use]
    pub fn group_by_type(queries: &[Query]) -> GroupedQueries {
        let mut grouped = GroupedQueries {
            filters: Vec::new(),
            selections: Vec::new(),
            limit: None,
            offset: None,
            order_attributes: Vec::new(),
            order_types: Vec::new(),
            cursor: None,
            cursor_direction: None,
        };
        for query in queries {
            match query.get_method() {
                TYPE_ORDER_ASC | TYPE_ORDER_DESC | TYPE_ORDER_RANDOM => {
                    if !query.get_attribute().is_empty() {
                        grouped
                            .order_attributes
                            .push(query.get_attribute().to_owned());
                    }
                    grouped.order_types.push(match query.get_method() {
                        TYPE_ORDER_ASC => ORDER_ASC.to_owned(),
                        TYPE_ORDER_DESC => ORDER_DESC.to_owned(),
                        _ => ORDER_RANDOM.to_owned(),
                    });
                }
                TYPE_LIMIT => {
                    if grouped.limit.is_none() {
                        grouped.limit = query.get_value().as_i64();
                    }
                }
                TYPE_OFFSET => {
                    if grouped.offset.is_none() {
                        grouped.offset = query.get_value().as_i64();
                    }
                }
                TYPE_CURSOR_AFTER | TYPE_CURSOR_BEFORE => {
                    if grouped.cursor.is_none() {
                        grouped.cursor = match query.get_value() {
                            AttrValue::Document(d) => Some(*d.clone()),
                            _ => None,
                        };
                        grouped.cursor_direction =
                            Some(if query.get_method() == TYPE_CURSOR_AFTER {
                                CURSOR_AFTER.to_owned()
                            } else {
                                CURSOR_BEFORE.to_owned()
                            });
                    }
                }
                TYPE_SELECT => grouped.selections.push(query.clone()),
                _ => grouped.filters.push(query.clone()),
            }
        }
        grouped
    }

    #[must_use]
    pub fn is_nested(&self) -> bool {
        LOGICAL_TYPES.contains(&self.method.as_str())
    }

    #[must_use]
    pub fn on_array(&self) -> bool {
        self.on_array
    }

    pub fn set_on_array(&mut self, value: bool) {
        self.on_array = value;
    }

    pub fn set_attribute_type(&mut self, type_: impl Into<String>) {
        self.attribute_type = type_.into();
    }

    #[must_use]
    pub fn get_attribute_type(&self) -> &str {
        &self.attribute_type
    }

    #[must_use]
    pub fn is_spatial_attribute(&self) -> bool {
        SPATIAL_TYPES.contains(&self.attribute_type.as_str())
    }

    #[must_use]
    pub fn is_object_attribute(&self) -> bool {
        self.attribute_type == VAR_OBJECT
    }

    #[must_use]
    pub fn distance_equal(
        attribute: impl Into<String>,
        values: AttrValue,
        distance: f64,
        meters: bool,
    ) -> Self {
        Self::new(
            TYPE_DISTANCE_EQUAL,
            attribute,
            vec![AttrValue::list_from_iter([
                values,
                AttrValue::from(distance),
                AttrValue::Bool(meters),
            ])],
        )
    }
    #[must_use]
    pub fn distance_not_equal(
        attribute: impl Into<String>,
        values: AttrValue,
        distance: f64,
        meters: bool,
    ) -> Self {
        Self::new(
            TYPE_DISTANCE_NOT_EQUAL,
            attribute,
            vec![AttrValue::list_from_iter([
                values,
                AttrValue::from(distance),
                AttrValue::Bool(meters),
            ])],
        )
    }
    #[must_use]
    pub fn distance_greater_than(
        attribute: impl Into<String>,
        values: AttrValue,
        distance: f64,
        meters: bool,
    ) -> Self {
        Self::new(
            TYPE_DISTANCE_GREATER_THAN,
            attribute,
            vec![AttrValue::list_from_iter([
                values,
                AttrValue::from(distance),
                AttrValue::Bool(meters),
            ])],
        )
    }
    #[must_use]
    pub fn distance_less_than(
        attribute: impl Into<String>,
        values: AttrValue,
        distance: f64,
        meters: bool,
    ) -> Self {
        Self::new(
            TYPE_DISTANCE_LESS_THAN,
            attribute,
            vec![AttrValue::list_from_iter([
                values,
                AttrValue::from(distance),
                AttrValue::Bool(meters),
            ])],
        )
    }
    #[must_use]
    pub fn intersects(attribute: impl Into<String>, values: AttrValue) -> Self {
        Self::new(TYPE_INTERSECTS, attribute, vec![values])
    }
    #[must_use]
    pub fn not_intersects(attribute: impl Into<String>, values: AttrValue) -> Self {
        Self::new(TYPE_NOT_INTERSECTS, attribute, vec![values])
    }
    #[must_use]
    pub fn crosses(attribute: impl Into<String>, values: AttrValue) -> Self {
        Self::new(TYPE_CROSSES, attribute, vec![values])
    }
    #[must_use]
    pub fn not_crosses(attribute: impl Into<String>, values: AttrValue) -> Self {
        Self::new(TYPE_NOT_CROSSES, attribute, vec![values])
    }
    #[must_use]
    pub fn overlaps(attribute: impl Into<String>, values: AttrValue) -> Self {
        Self::new(TYPE_OVERLAPS, attribute, vec![values])
    }
    #[must_use]
    pub fn not_overlaps(attribute: impl Into<String>, values: AttrValue) -> Self {
        Self::new(TYPE_NOT_OVERLAPS, attribute, vec![values])
    }
    #[must_use]
    pub fn touches(attribute: impl Into<String>, values: AttrValue) -> Self {
        Self::new(TYPE_TOUCHES, attribute, vec![values])
    }
    #[must_use]
    pub fn not_touches(attribute: impl Into<String>, values: AttrValue) -> Self {
        Self::new(TYPE_NOT_TOUCHES, attribute, vec![values])
    }
    #[must_use]
    pub fn vector_dot(attribute: impl Into<String>, vector: Vec<f64>) -> Self {
        Self::new(
            TYPE_VECTOR_DOT,
            attribute,
            vec![AttrValue::list_from_iter(
                vector.into_iter().map(AttrValue::from),
            )],
        )
    }
    #[must_use]
    pub fn vector_cosine(attribute: impl Into<String>, vector: Vec<f64>) -> Self {
        Self::new(
            TYPE_VECTOR_COSINE,
            attribute,
            vec![AttrValue::list_from_iter(
                vector.into_iter().map(AttrValue::from),
            )],
        )
    }
    #[must_use]
    pub fn vector_euclidean(attribute: impl Into<String>, vector: Vec<f64>) -> Self {
        Self::new(
            TYPE_VECTOR_EUCLIDEAN,
            attribute,
            vec![AttrValue::list_from_iter(
                vector.into_iter().map(AttrValue::from),
            )],
        )
    }
    #[must_use]
    pub fn regex(attribute: impl Into<String>, pattern: impl Into<String>) -> Self {
        Self::new(
            TYPE_REGEX,
            attribute,
            vec![AttrValue::String(pattern.into())],
        )
    }
    #[must_use]
    pub fn exists(attributes: Vec<String>) -> Self {
        Self::new(
            TYPE_EXISTS,
            "",
            attributes.into_iter().map(AttrValue::String).collect(),
        )
    }
    #[must_use]
    pub fn not_exists(attribute: AttrValue) -> Self {
        let values = if attribute.is_list() {
            match attribute {
                AttrValue::Array(items) => items.into_values().collect(),
                other => vec![other],
            }
        } else {
            vec![attribute]
        };
        Self::new(TYPE_NOT_EXISTS, "", values)
    }
    #[must_use]
    pub fn elem_match(attribute: impl Into<String>, queries: Vec<Query>) -> Self {
        Self::new(
            TYPE_ELEM_MATCH,
            attribute,
            queries.into_iter().map(AttrValue::from).collect(),
        )
    }
}

impl PartialEq for Query {
    fn eq(&self, other: &Self) -> bool {
        self.method == other.method
            && self.attribute == other.attribute
            && self.values == other.values
    }
}
