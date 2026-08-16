//! PHP `Utopia\Database\Validator\Queries`.

use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::query::{
    Query, TYPE_AND, TYPE_BETWEEN, TYPE_CONTAINS, TYPE_CONTAINS_ALL, TYPE_CONTAINS_ANY,
    TYPE_CROSSES, TYPE_CURSOR_AFTER, TYPE_CURSOR_BEFORE, TYPE_DISTANCE_EQUAL,
    TYPE_DISTANCE_GREATER_THAN, TYPE_DISTANCE_LESS_THAN, TYPE_DISTANCE_NOT_EQUAL, TYPE_ELEM_MATCH,
    TYPE_ENDS_WITH, TYPE_EQUAL, TYPE_EXISTS, TYPE_GREATER, TYPE_GREATER_EQUAL, TYPE_INTERSECTS,
    TYPE_IS_NOT_NULL, TYPE_IS_NULL, TYPE_LESSER, TYPE_LESSER_EQUAL, TYPE_LIMIT, TYPE_NOT_BETWEEN,
    TYPE_NOT_CONTAINS, TYPE_NOT_CROSSES, TYPE_NOT_ENDS_WITH, TYPE_NOT_EQUAL, TYPE_NOT_EXISTS,
    TYPE_NOT_INTERSECTS, TYPE_NOT_OVERLAPS, TYPE_NOT_SEARCH, TYPE_NOT_STARTS_WITH,
    TYPE_NOT_TOUCHES, TYPE_OFFSET, TYPE_OR, TYPE_ORDER_ASC, TYPE_ORDER_DESC, TYPE_ORDER_RANDOM,
    TYPE_OVERLAPS, TYPE_REGEX, TYPE_SEARCH, TYPE_SELECT, TYPE_STARTS_WITH, TYPE_TOUCHES,
    TYPE_VECTOR_COSINE, TYPE_VECTOR_DOT, TYPE_VECTOR_EUCLIDEAN,
};
use crate::validator::query::base::{
    QueryMethodValidator, METHOD_TYPE_CURSOR, METHOD_TYPE_FILTER, METHOD_TYPE_LIMIT,
    METHOD_TYPE_OFFSET, METHOD_TYPE_ORDER, METHOD_TYPE_SELECT,
};

/// PHP `Utopia\Database\Validator\Queries`.
pub struct Queries {
    validators: Vec<Box<dyn QueryMethodValidator>>,
    length: i64,
    message: Mutex<String>,
}

impl std::fmt::Debug for Queries {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Queries")
            .field("length", &self.length)
            .finish_non_exhaustive()
    }
}

impl Queries {
    #[must_use]
    pub fn new(validators: Vec<Box<dyn QueryMethodValidator>>, length: i64) -> Self {
        Self {
            validators,
            length,
            message: Mutex::new("Invalid queries".into()),
        }
    }

    pub(crate) fn set_message(&self, message: impl Into<String>) {
        *self.message.lock() = message.into();
    }

    fn method_type_of(method: &str) -> &'static str {
        match method {
            TYPE_SELECT => METHOD_TYPE_SELECT,
            TYPE_LIMIT => METHOD_TYPE_LIMIT,
            TYPE_OFFSET => METHOD_TYPE_OFFSET,
            TYPE_CURSOR_AFTER | TYPE_CURSOR_BEFORE => METHOD_TYPE_CURSOR,
            TYPE_ORDER_ASC | TYPE_ORDER_DESC | TYPE_ORDER_RANDOM => METHOD_TYPE_ORDER,
            TYPE_EQUAL
            | TYPE_NOT_EQUAL
            | TYPE_LESSER
            | TYPE_LESSER_EQUAL
            | TYPE_GREATER
            | TYPE_GREATER_EQUAL
            | TYPE_SEARCH
            | TYPE_NOT_SEARCH
            | TYPE_IS_NULL
            | TYPE_IS_NOT_NULL
            | TYPE_BETWEEN
            | TYPE_NOT_BETWEEN
            | TYPE_STARTS_WITH
            | TYPE_NOT_STARTS_WITH
            | TYPE_ENDS_WITH
            | TYPE_NOT_ENDS_WITH
            | TYPE_CONTAINS
            | TYPE_CONTAINS_ANY
            | TYPE_NOT_CONTAINS
            | TYPE_AND
            | TYPE_OR
            | TYPE_CONTAINS_ALL
            | TYPE_ELEM_MATCH
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
            | TYPE_VECTOR_DOT
            | TYPE_VECTOR_COSINE
            | TYPE_VECTOR_EUCLIDEAN
            | TYPE_REGEX
            | TYPE_EXISTS
            | TYPE_NOT_EXISTS => METHOD_TYPE_FILTER,
            _ => "",
        }
    }

    pub fn is_valid_queries(&self, value: &[Query]) -> bool {
        if self.length > 0 && value.len() as i64 > self.length {
            return false;
        }
        for query in value {
            if query.is_nested() {
                let nested: Vec<Query> = query
                    .get_values()
                    .iter()
                    .filter_map(crate::value::AttrValue::as_query)
                    .cloned()
                    .collect();
                if !self.is_valid_queries(&nested) {
                    return false;
                }
            }
            let method = query.get_method();
            let method_type = Self::method_type_of(method);
            let mut method_is_valid = false;
            for validator in &self.validators {
                if validator.method_type() != method_type {
                    continue;
                }
                if !validator.is_valid_query(query) {
                    self.set_message(format!("Invalid query: {}", validator.description()));
                    return false;
                }
                method_is_valid = true;
            }
            if !method_is_valid {
                self.set_message(format!("Invalid query method: {method}"));
                return false;
            }
        }
        true
    }
}

impl Validator for Queries {
    fn description(&self) -> String {
        self.message.lock().clone()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Array
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(arr) = value.as_array() else {
            self.set_message("Queries must be an array");
            return false;
        };
        let mut queries = Vec::new();
        for item in arr {
            // A query arrives either already decoded (an object, as when the
            // caller sent a JSON body) or as the JSON *string* an SDK's
            // `Query::equal(...)->toString()` produces. Re-encoding the latter
            // would hand `parse` a quoted string instead of the query.
            let parsed = match item {
                Value::String(query) => Query::parse(query),
                other => Query::parse(&other.to_string()),
            };
            match parsed {
                Ok(q) => queries.push(q),
                Err(e) => {
                    self.set_message(format!("Invalid query: {}", e.message()));
                    return false;
                }
            }
        }
        self.is_valid_queries(&queries)
    }
}
