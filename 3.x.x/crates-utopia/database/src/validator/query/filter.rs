//! PHP `Utopia\Database\Validator\Query\Filter`.

use chrono::NaiveDateTime;
use indexmap::IndexMap;
use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Boolean, FloatValidator, Integer, Text, Validator, ValueType};

use crate::constants::{
    RELATION_MANY_TO_MANY, RELATION_MANY_TO_ONE, RELATION_ONE_TO_MANY, RELATION_ONE_TO_ONE,
    RELATION_SIDE_CHILD, RELATION_SIDE_PARENT, SPATIAL_TYPES, STRING_TYPES, VAR_BIGINT,
    VAR_BOOLEAN, VAR_DATETIME, VAR_FLOAT, VAR_ID, VAR_INTEGER, VAR_LINESTRING, VAR_LONGTEXT,
    VAR_MEDIUMTEXT, VAR_OBJECT, VAR_POINT, VAR_POLYGON, VAR_RELATIONSHIP, VAR_STRING, VAR_TEXT,
    VAR_VARCHAR, VAR_VECTOR,
};
use crate::document::Document;
use crate::query::{
    Query, TYPE_AND, TYPE_BETWEEN, TYPE_CONTAINS, TYPE_CONTAINS_ALL, TYPE_CONTAINS_ANY,
    TYPE_DISTANCE_EQUAL, TYPE_DISTANCE_GREATER_THAN, TYPE_DISTANCE_LESS_THAN,
    TYPE_DISTANCE_NOT_EQUAL, TYPE_ELEM_MATCH, TYPE_ENDS_WITH, TYPE_EQUAL, TYPE_EXISTS,
    TYPE_GREATER, TYPE_GREATER_EQUAL, TYPE_IS_NOT_NULL, TYPE_IS_NULL, TYPE_LESSER,
    TYPE_LESSER_EQUAL, TYPE_NOT_BETWEEN, TYPE_NOT_CONTAINS, TYPE_NOT_ENDS_WITH, TYPE_NOT_EQUAL,
    TYPE_NOT_EXISTS, TYPE_NOT_SEARCH, TYPE_NOT_STARTS_WITH, TYPE_OR, TYPE_REGEX, TYPE_SEARCH,
    TYPE_STARTS_WITH, TYPE_VECTOR_COSINE, TYPE_VECTOR_DOT, TYPE_VECTOR_EUCLIDEAN, VECTOR_TYPES,
};
use crate::validator::datetime::Datetime as DatetimeValidator;
use crate::validator::query::base::{QueryMethodValidator, METHOD_TYPE_FILTER};
use crate::validator::{BigInt, Sequence};
use crate::value::AttrValue;

/// PHP `Utopia\Database\Validator\Query\Filter`.
#[derive(Debug)]
pub struct Filter {
    schema: IndexMap<String, Value>,
    id_attribute_type: String,
    max_values_count: i64,
    min_allowed_date: NaiveDateTime,
    max_allowed_date: NaiveDateTime,
    support_for_attributes: bool,
    support_unsigned_big_int: bool,
    message: Mutex<String>,
}

impl Clone for Filter {
    fn clone(&self) -> Self {
        Self {
            schema: self.schema.clone(),
            id_attribute_type: self.id_attribute_type.clone(),
            max_values_count: self.max_values_count,
            min_allowed_date: self.min_allowed_date,
            max_allowed_date: self.max_allowed_date,
            support_for_attributes: self.support_for_attributes,
            support_unsigned_big_int: self.support_unsigned_big_int,
            message: Mutex::new(self.message.lock().clone()),
        }
    }
}

impl Filter {
    #[must_use]
    pub fn new(
        attributes: &[Document],
        id_attribute_type: impl Into<String>,
        max_values_count: i64,
        min_allowed_date: NaiveDateTime,
        max_allowed_date: NaiveDateTime,
        support_for_attributes: bool,
        support_unsigned_big_int: bool,
    ) -> Self {
        let mut schema = IndexMap::new();
        for attribute in attributes {
            let key = match attribute.get_attribute("key") {
                AttrValue::String(s) if !s.is_empty() => s.clone(),
                _ => attribute.get_id(),
            };
            schema.insert(key, Value::Object(attribute.get_array_copy_json(&[], &[])));
        }
        Self {
            schema,
            id_attribute_type: id_attribute_type.into(),
            max_values_count,
            min_allowed_date,
            max_allowed_date,
            support_for_attributes,
            support_unsigned_big_int,
            message: Mutex::new("Invalid query".into()),
        }
    }

    fn set_message(&self, message: impl Into<String>) {
        *self.message.lock() = message.into();
    }

    fn is_empty(values: &[AttrValue]) -> bool {
        if values.is_empty() {
            return true;
        }
        matches!(values.first(), Some(AttrValue::Array(a)) if a.is_empty())
    }

    fn is_valid_attribute(&self, attribute: &str) -> bool {
        if let Some(schema) = self.schema.get(attribute) {
            if schema
                .get("filters")
                .and_then(Value::as_array)
                .is_some_and(|f| f.iter().any(|v| v.as_str() == Some("encrypt")))
            {
                self.set_message(format!("Cannot query encrypted attribute: {attribute}"));
                return false;
            }
        }
        let mut attr = attribute;
        if attribute.contains('.') {
            if self.schema.contains_key(attribute) {
                return true;
            }
            attr = attribute.split('.').next().unwrap_or(attribute);
        }
        if self.support_for_attributes && !self.schema.contains_key(attr) {
            self.set_message(format!("Attribute not found in schema: {attribute}"));
            return false;
        }
        true
    }

    fn is_valid_attribute_and_values(
        &self,
        attribute: &str,
        values: &[AttrValue],
        method: &str,
    ) -> bool {
        if !self.is_valid_attribute(attribute) {
            return false;
        }
        let original = attribute;
        let mut attr = attribute;
        if attribute.contains('.') && !self.schema.contains_key(attribute) {
            attr = attribute.split('.').next().unwrap_or(attribute);
        }
        if matches!(method, TYPE_EXISTS | TYPE_NOT_EXISTS) {
            return self.is_valid_attribute(attr);
        }
        if !self.support_for_attributes && !self.schema.contains_key(attr) {
            if values.len() as i64 > self.max_values_count {
                self.set_message(format!(
                    "Query on attribute has greater than {} values: {attr}",
                    self.max_values_count
                ));
                return false;
            }
            return true;
        }
        let Some(attribute_schema) = self.schema.get(attr) else {
            return true;
        };
        let attribute_type = attribute_schema
            .get("type")
            .and_then(Value::as_str)
            .unwrap_or("");
        if attribute_type == VAR_RELATIONSHIP && original != attr {
            return true;
        }
        if values.len() as i64 > self.max_values_count {
            self.set_message(format!(
                "Query on attribute has greater than {} values: {attr}",
                self.max_values_count
            ));
            return false;
        }
        let query = Query::new(method, "", vec![]);
        if query.is_spatial_query() && !SPATIAL_TYPES.contains(&attribute_type) {
            self.set_message(format!(
                "Spatial query \"{method}\" cannot be applied on non-spatial attribute: {attr}"
            ));
            return false;
        }
        let is_dotted_on_object = original.contains('.') && attribute_type == VAR_OBJECT;
        for value in values {
            match attribute_type {
                VAR_ID => {
                    let validator = Sequence::new(&self.id_attribute_type, attr == "$sequence");
                    if !validator.is_valid(&value.to_json()) {
                        self.set_message(format!(
                            "Query value is invalid for attribute \"{attr}\""
                        ));
                        return false;
                    }
                }
                VAR_STRING | VAR_VARCHAR | VAR_TEXT | VAR_MEDIUMTEXT | VAR_LONGTEXT => {
                    if !Text::new(0).is_valid(&value.to_json()) {
                        self.set_message(format!(
                            "Query value is invalid for attribute \"{attr}\""
                        ));
                        return false;
                    }
                }
                VAR_INTEGER => {
                    let size = attribute_schema
                        .get("size")
                        .and_then(Value::as_i64)
                        .unwrap_or(4);
                    let signed = attribute_schema
                        .get("signed")
                        .and_then(Value::as_bool)
                        .unwrap_or(true);
                    let bits = if size >= 8 { 64 } else { 32 };
                    let unsigned = !signed && bits < 64;
                    let validator = Integer::new().bits(bits).unsigned(unsigned);
                    if !validator.is_valid(&value.to_json()) {
                        self.set_message(format!(
                            "Query value is invalid for attribute \"{attr}\""
                        ));
                        return false;
                    }
                }
                VAR_BIGINT => {
                    let signed = attribute_schema
                        .get("signed")
                        .and_then(Value::as_bool)
                        .unwrap_or(true);
                    let validator = BigInt::new(signed, self.support_unsigned_big_int);
                    if !validator.is_valid(&value.to_json()) {
                        self.set_message(format!(
                            "Query value is invalid for attribute \"{attr}\""
                        ));
                        return false;
                    }
                }
                VAR_FLOAT => {
                    if !FloatValidator::new().is_valid(&value.to_json()) {
                        self.set_message(format!(
                            "Query value is invalid for attribute \"{attr}\""
                        ));
                        return false;
                    }
                }
                VAR_BOOLEAN => {
                    if !Boolean::new().is_valid(&value.to_json()) {
                        self.set_message(format!(
                            "Query value is invalid for attribute \"{attr}\""
                        ));
                        return false;
                    }
                }
                VAR_DATETIME => {
                    let validator = DatetimeValidator::new(
                        self.min_allowed_date,
                        self.max_allowed_date,
                        false,
                        crate::validator::datetime::PRECISION_ANY,
                        0,
                    )
                    .unwrap_or_else(|_| DatetimeValidator::default_range());
                    if !validator.is_valid(&value.to_json()) {
                        self.set_message(format!(
                            "Query value is invalid for attribute \"{attr}\""
                        ));
                        return false;
                    }
                }
                VAR_RELATIONSHIP => {
                    if !Text::new(255).is_valid(&value.to_json()) {
                        self.set_message(format!(
                            "Query value is invalid for attribute \"{attr}\""
                        ));
                        return false;
                    }
                }
                VAR_OBJECT => {
                    if is_dotted_on_object {
                        if !Text::new(0).is_valid(&value.to_json()) {
                            self.set_message(format!(
                                "Query value is invalid for attribute \"{attr}\""
                            ));
                            return false;
                        }
                    } else if matches!(
                        method,
                        TYPE_EQUAL
                            | TYPE_NOT_EQUAL
                            | TYPE_CONTAINS
                            | TYPE_CONTAINS_ANY
                            | TYPE_CONTAINS_ALL
                            | TYPE_NOT_CONTAINS
                    ) && !is_valid_object_query_values(value)
                    {
                        self.set_message(format!(
                            "Invalid object query structure for attribute \"{attr}\""
                        ));
                        return false;
                    }
                }
                VAR_POINT | VAR_LINESTRING | VAR_POLYGON => {
                    if !matches!(value, AttrValue::Array(_)) {
                        self.set_message("Spatial data must be an array");
                        return false;
                    }
                }
                VAR_VECTOR => {
                    let Some(arr) = value.as_array() else {
                        self.set_message("Vector query value must be an array");
                        return false;
                    };
                    if !arr
                        .values()
                        .all(|c| c.as_f64().is_some() || c.as_i64().is_some())
                    {
                        self.set_message("Vector query value must contain only numeric values");
                        return false;
                    }
                    let expected = attribute_schema
                        .get("size")
                        .and_then(Value::as_u64)
                        .unwrap_or(0) as usize;
                    if arr.len() != expected {
                        self.set_message(format!(
                            "Vector query value must have {expected} elements"
                        ));
                        return false;
                    }
                }
                _ => {
                    self.set_message("Unknown Data type");
                    return false;
                }
            }
        }
        if attribute_type == VAR_RELATIONSHIP {
            let options = attribute_schema
                .get("options")
                .cloned()
                .unwrap_or(Value::Object(Default::default()));
            let relation_type = options
                .get("relationType")
                .and_then(Value::as_str)
                .unwrap_or("");
            let two_way = options
                .get("twoWay")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            let side = options.get("side").and_then(Value::as_str).unwrap_or("");
            if (relation_type == RELATION_ONE_TO_ONE && !two_way && side == RELATION_SIDE_CHILD)
                || (relation_type == RELATION_ONE_TO_MANY && side == RELATION_SIDE_PARENT)
                || (relation_type == RELATION_MANY_TO_ONE && side == RELATION_SIDE_CHILD)
                || relation_type == RELATION_MANY_TO_MANY
            {
                self.set_message("Cannot query on virtual relationship attribute");
                return false;
            }
        }
        let array = attribute_schema
            .get("array")
            .and_then(Value::as_bool)
            .unwrap_or(false);
        if !array
            && matches!(
                method,
                TYPE_CONTAINS | TYPE_CONTAINS_ANY | TYPE_CONTAINS_ALL | TYPE_NOT_CONTAINS
            )
            && !STRING_TYPES.contains(&attribute_type)
            && attribute_type != VAR_OBJECT
            && !SPATIAL_TYPES.contains(&attribute_type)
        {
            let query_type = if method == TYPE_NOT_CONTAINS {
                "notContains"
            } else {
                "contains"
            };
            self.set_message(format!(
                "Cannot query {query_type} on attribute \"{attr}\" because it is not an array, string, or object."
            ));
            return false;
        }
        if array
            && !matches!(
                method,
                TYPE_CONTAINS
                    | TYPE_CONTAINS_ANY
                    | TYPE_CONTAINS_ALL
                    | TYPE_NOT_CONTAINS
                    | TYPE_IS_NULL
                    | TYPE_IS_NOT_NULL
                    | TYPE_EXISTS
                    | TYPE_NOT_EXISTS
            )
        {
            self.set_message(format!(
                "Cannot query {method} on attribute \"{attr}\" because it is an array."
            ));
            return false;
        }
        if VECTOR_TYPES.contains(&method) {
            if attribute_type != VAR_VECTOR {
                self.set_message("Vector queries can only be used on vector attributes");
                return false;
            }
            if array {
                self.set_message("Vector queries cannot be used on array attributes");
                return false;
            }
        }
        true
    }
}

fn is_valid_object_query_values(values: &AttrValue) -> bool {
    let Some(arr) = values.as_array() else {
        return true;
    };
    let mut has_int = false;
    let mut has_string = false;
    for key in arr.keys() {
        if key.parse::<i64>().is_ok() {
            has_int = true;
        } else {
            has_string = true;
        }
    }
    if has_int && has_string {
        return false;
    }
    arr.values().all(is_valid_object_query_values)
}

fn ucfirst(s: &str) -> String {
    let mut c = s.chars();
    match c.next() {
        Some(f) => f.to_uppercase().collect::<String>() + c.as_str(),
        None => String::new(),
    }
}

impl QueryMethodValidator for Filter {
    fn method_type(&self) -> &'static str {
        METHOD_TYPE_FILTER
    }

    fn is_valid_query(&self, value: &Query) -> bool {
        let method = value.get_method();
        let attribute = value.get_attribute();
        match method {
            TYPE_EQUAL | TYPE_CONTAINS | TYPE_CONTAINS_ANY | TYPE_NOT_CONTAINS
            | TYPE_CONTAINS_ALL | TYPE_EXISTS | TYPE_NOT_EXISTS => {
                if Self::is_empty(value.get_values()) {
                    self.set_message(format!(
                        "{} queries require at least one value.",
                        ucfirst(method)
                    ));
                    return false;
                }
                self.is_valid_attribute_and_values(attribute, value.get_values(), method)
            }
            TYPE_DISTANCE_EQUAL
            | TYPE_DISTANCE_NOT_EQUAL
            | TYPE_DISTANCE_GREATER_THAN
            | TYPE_DISTANCE_LESS_THAN => {
                if value.get_values().len() != 1
                    || value
                        .get_values()
                        .first()
                        .and_then(AttrValue::as_array)
                        .map_or(true, |a| a.len() != 3)
                {
                    self.set_message("Distance query requires [[geometry, distance]] parameters");
                    return false;
                }
                self.is_valid_attribute_and_values(attribute, value.get_values(), method)
            }
            TYPE_NOT_EQUAL | TYPE_LESSER | TYPE_LESSER_EQUAL | TYPE_GREATER
            | TYPE_GREATER_EQUAL | TYPE_SEARCH | TYPE_NOT_SEARCH | TYPE_STARTS_WITH
            | TYPE_NOT_STARTS_WITH | TYPE_ENDS_WITH | TYPE_NOT_ENDS_WITH | TYPE_REGEX => {
                if value.get_values().len() != 1 {
                    self.set_message(format!(
                        "{} queries require exactly one value.",
                        ucfirst(method)
                    ));
                    return false;
                }
                self.is_valid_attribute_and_values(attribute, value.get_values(), method)
            }
            TYPE_BETWEEN | TYPE_NOT_BETWEEN => {
                if value.get_values().len() != 2 {
                    self.set_message(format!(
                        "{} queries require exactly two values.",
                        ucfirst(method)
                    ));
                    return false;
                }
                self.is_valid_attribute_and_values(attribute, value.get_values(), method)
            }
            TYPE_IS_NULL | TYPE_IS_NOT_NULL => {
                self.is_valid_attribute_and_values(attribute, value.get_values(), method)
            }
            TYPE_VECTOR_DOT | TYPE_VECTOR_COSINE | TYPE_VECTOR_EUCLIDEAN => {
                if !self.is_valid_attribute(attribute) {
                    return false;
                }
                let mut key = attribute;
                if attribute.contains('.') && !self.schema.contains_key(attribute) {
                    key = attribute.split('.').next().unwrap_or(attribute);
                }
                let ty = self
                    .schema
                    .get(key)
                    .and_then(|s| s.get("type"))
                    .and_then(Value::as_str)
                    .unwrap_or("");
                if ty != VAR_VECTOR {
                    self.set_message("Vector queries can only be used on vector attributes");
                    return false;
                }
                if value.get_values().len() != 1 {
                    self.set_message(format!(
                        "{} queries require exactly one vector value.",
                        ucfirst(method)
                    ));
                    return false;
                }
                self.is_valid_attribute_and_values(attribute, value.get_values(), method)
            }
            TYPE_OR | TYPE_AND => {
                let nested: Vec<Query> = value
                    .get_values()
                    .iter()
                    .filter_map(AttrValue::as_query)
                    .cloned()
                    .collect();
                let grouped = Query::group_by_type(&nested);
                if value.get_values().len() != grouped.filters.len() {
                    self.set_message(format!(
                        "{} queries can only contain filter queries",
                        ucfirst(method)
                    ));
                    return false;
                }
                if grouped.filters.len() < 2 {
                    self.set_message(format!(
                        "{} queries require at least two queries",
                        ucfirst(method)
                    ));
                    return false;
                }
                true
            }
            TYPE_ELEM_MATCH => {
                if self.support_for_attributes {
                    self.set_message("elemMatch is not supported by the database");
                    return false;
                }
                if !self.is_valid_attribute(attribute) {
                    return false;
                }
                let nested: Vec<Query> = value
                    .get_values()
                    .iter()
                    .filter_map(AttrValue::as_query)
                    .cloned()
                    .collect();
                let grouped = Query::group_by_type(&nested);
                if value.get_values().len() != grouped.filters.len() {
                    self.set_message("elemMatch queries can only contain filter queries");
                    return false;
                }
                if grouped.filters.is_empty() {
                    self.set_message("elemMatch queries require at least one query");
                    return false;
                }
                true
            }
            _ => {
                if value.is_spatial_query() {
                    if Self::is_empty(value.get_values()) {
                        self.set_message(format!(
                            "{} queries require at least one value.",
                            ucfirst(method)
                        ));
                        return false;
                    }
                    return self.is_valid_attribute_and_values(
                        attribute,
                        value.get_values(),
                        method,
                    );
                }
                false
            }
        }
    }
}

impl Validator for Filter {
    fn description(&self) -> String {
        self.message.lock().clone()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Object
    }

    fn is_valid(&self, _value: &Value) -> bool {
        false
    }
}
