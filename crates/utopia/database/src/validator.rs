//! `queries` parameter validation (`Utopia\Database\Validator\Queries` with
//! Appwrite's `Queries\Base` configuration).

use serde_json::Value;
use utopia_validators::Validator;
use utopia_validators::php::{self, Number};

use crate::datetime;
use crate::id::Uid;
use crate::query::{Method, Query};
use crate::schema::{Attribute, AttributeType, Collection, INTERNAL};

/// Upper bound printed by PHP's `number_format(PHP_INT_MAX)` (float cast).
const PHP_INT_MAX_FORMATTED: &str = "9,223,372,036,854,775,808";

/// Validates a list of serialized queries against an allow-listed schema.
pub struct Queries {
    attributes: Vec<Attribute>,
    max_values: usize,
    allow_select: bool,
}

impl Queries {
    /// Appwrite `Queries\Base($collection, $allowedAttributes)`.
    pub fn new(collection: &Collection, allowed: &[&str]) -> Self {
        let mut attributes: Vec<Attribute> =
            collection.attributes.iter().filter(|a| allowed.contains(&a.id)).copied().collect();
        attributes.extend(INTERNAL.iter().copied());
        Self { attributes, max_values: 500, allow_select: false }
    }

    fn attribute(&self, id: &str) -> Option<&Attribute> {
        self.attributes.iter().find(|a| a.id == id)
    }

    /// Validates every entry; returns the PHP message of the first failure.
    pub fn validate(&self, value: &Value) -> Result<Vec<Query>, String> {
        if !php::is_array(value) && !matches!(value, Value::Array(_)) {
            return Err("Queries must be an array".to_owned());
        }
        let mut parsed = Vec::new();
        for item in php::array_values(value) {
            let query = match item {
                Value::String(s) => Query::parse(s).map_err(|e| format!("Invalid query: {e}"))?,
                _ => return Err("Invalid query: Invalid query: Syntax error".to_owned()),
            };
            self.validate_query(&query)?;
            parsed.push(query);
        }
        Ok(parsed)
    }

    fn validate_query(&self, query: &Query) -> Result<(), String> {
        match query.method {
            Method::Limit => self.limit(query).map_err(|m| format!("Invalid query: {m}")),
            Method::Offset => self.offset(query).map_err(|m| format!("Invalid query: {m}")),
            Method::CursorAfter | Method::CursorBefore => self.cursor(query).map_err(|m| format!("Invalid query: {m}")),
            Method::OrderAsc | Method::OrderDesc | Method::OrderRandom => {
                self.order(query).map_err(|m| format!("Invalid query: {m}"))
            }
            Method::Select if !self.allow_select => Err(format!("Invalid query method: {}", query.method.name())),
            Method::Select => Ok(()),
            _ => self.filter(query).map_err(|m| format!("Invalid query: {m}")),
        }
    }

    fn limit(&self, query: &Query) -> Result<(), String> {
        let value = query.values.first().cloned().unwrap_or(Value::Null);
        match php::numeric(&value) {
            None => Err("Invalid limit: Value must be a valid number".to_owned()),
            Some(Number::Int(i)) if i >= 1 => Ok(()),
            Some(_) => Err(format!("Invalid limit: Value must be a valid range between 1 and {PHP_INT_MAX_FORMATTED}")),
        }
    }

    fn offset(&self, query: &Query) -> Result<(), String> {
        let value = query.values.first().cloned().unwrap_or(Value::Null);
        match php::numeric(&value) {
            // Upstream bug: the message says "limit".
            None => Err("Invalid limit: Value must be a valid number".to_owned()),
            Some(Number::Int(i)) if i >= 0 => Ok(()),
            Some(_) => {
                Err(format!("Invalid offset: Value must be a valid range between 0 and {PHP_INT_MAX_FORMATTED}"))
            }
        }
    }

    fn cursor(&self, query: &Query) -> Result<(), String> {
        let uid = Uid::default();
        let value = query.values.first().cloned().unwrap_or(Value::Null);
        if uid.is_valid(&value) { Ok(()) } else { Err(format!("Invalid cursor: {}", uid.description())) }
    }

    fn order(&self, query: &Query) -> Result<(), String> {
        if query.method == Method::OrderRandom {
            return Ok(());
        }
        let attribute = query.attribute.as_str();
        if attribute.contains('.') {
            if self.attribute(attribute).is_some() {
                return Ok(());
            }
            let top = attribute.split('.').next().unwrap_or("");
            if self.attribute(top).is_some() {
                return Err(format!("Cannot order by nested attribute: {top}"));
            }
        }
        if self.attribute(attribute).is_none() {
            return Err(format!("Attribute not found in schema: {attribute}"));
        }
        Ok(())
    }

    fn filter(&self, query: &Query) -> Result<(), String> {
        let name = query.method.name();
        let ucfirst = || {
            let mut c = name.chars();
            match c.next() {
                Some(f) => f.to_uppercase().collect::<String>() + c.as_str(),
                None => String::new(),
            }
        };
        let is_empty = |values: &[Value]| -> bool {
            match values.first() {
                None => true,
                Some(Value::Array(a)) => a.is_empty(),
                _ => false,
            }
        };
        match query.method {
            Method::Equal
            | Method::Contains
            | Method::ContainsAny
            | Method::NotContains
            | Method::ContainsAll
            | Method::Exists
            | Method::NotExists => {
                if is_empty(&query.values) {
                    return Err(format!("{} queries require at least one value.", ucfirst()));
                }
                self.attribute_and_values(&query.attribute, &query.values, query.method)
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
                if query.values.len() != 1 {
                    return Err(format!("{} queries require exactly one value.", ucfirst()));
                }
                self.attribute_and_values(&query.attribute, &query.values, query.method)
            }
            Method::Between | Method::NotBetween => {
                if query.values.len() != 2 {
                    return Err(format!("{} queries require exactly two values.", ucfirst()));
                }
                self.attribute_and_values(&query.attribute, &query.values, query.method)
            }
            Method::IsNull | Method::IsNotNull => {
                self.attribute_and_values(&query.attribute, &query.values, query.method)
            }
            Method::And | Method::Or => {
                if query.queries.iter().any(|q| !q.method.is_filter()) {
                    return Err(format!("{} queries can only contain filter queries", ucfirst()));
                }
                if query.queries.len() < 2 {
                    return Err(format!("{} queries require at least two queries", ucfirst()));
                }
                for q in &query.queries {
                    self.filter(q)?;
                }
                Ok(())
            }
            Method::ElemMatch => Err("elemMatch is not supported by the database".to_owned()),
            Method::Vector(_) => {
                self.valid_attribute(&query.attribute)?;
                Err("Vector queries can only be used on vector attributes".to_owned())
            }
            Method::Spatial(s) => {
                if s.starts_with("distance") {
                    let ok = query.values.len() == 1
                        && matches!(query.values.first(), Some(Value::Array(a)) if a.len() == 3);
                    if !ok {
                        return Err("Distance query requires [[geometry, distance]] parameters".to_owned());
                    }
                } else if is_empty(&query.values) {
                    return Err(format!("{} queries require at least one value.", ucfirst()));
                }
                self.attribute_and_values(&query.attribute, &query.values, query.method)
            }
            _ => Err(format!("Invalid query method: {name}")),
        }
    }

    fn valid_attribute(&self, attribute: &str) -> Result<(), String> {
        let lookup = if attribute.contains('.') && self.attribute(attribute).is_none() {
            attribute.split('.').next().unwrap_or("")
        } else {
            attribute
        };
        if let Some(a) = self.attribute(lookup) {
            if a.encrypted {
                return Err(format!("Cannot query encrypted attribute: {attribute}"));
            }
            return Ok(());
        }
        Err(format!("Attribute not found in schema: {lookup}"))
    }

    fn attribute_and_values(&self, attribute: &str, values: &[Value], method: Method) -> Result<(), String> {
        self.valid_attribute(attribute)?;
        let attribute = if attribute.contains('.') && self.attribute(attribute).is_none() {
            attribute.split('.').next().unwrap_or("")
        } else {
            attribute
        };
        if matches!(method, Method::Exists | Method::NotExists) {
            return Ok(());
        }
        let schema = *self.attribute(attribute).expect("validated");
        if values.len() > self.max_values {
            return Err(format!("Query on attribute has greater than {} values: {attribute}", self.max_values));
        }
        if matches!(method, Method::Spatial(_)) {
            return Err(format!(
                "Spatial query \"{}\" cannot be applied on non-spatial attribute: {attribute}",
                method.name()
            ));
        }
        for value in values {
            let ok = match schema.kind {
                AttributeType::String => value.is_string(),
                AttributeType::Integer => match value {
                    Value::Number(n) if n.is_i64() || n.is_u64() => {
                        let v = n.as_i64().unwrap_or(i64::MAX);
                        if schema.size >= 8 { true } else { (i32::MIN as i64..=i32::MAX as i64).contains(&v) }
                    }
                    _ => false,
                },
                AttributeType::Float => matches!(value, Value::Number(_)),
                AttributeType::Boolean => value.is_boolean(),
                AttributeType::Datetime => match value {
                    Value::String(s) if !s.is_empty() && s != "0" => datetime::parse(s).is_some(),
                    _ => false,
                },
                AttributeType::Id => match value {
                    Value::Number(n) => n.as_i64().map(|v| v >= 1).unwrap_or(false),
                    Value::String(s) => s.parse::<i64>().map(|v| v >= 1).unwrap_or(false),
                    _ => false,
                },
            };
            if !ok {
                return Err(format!("Query value is invalid for attribute \"{attribute}\""));
            }
        }
        let contains =
            matches!(method, Method::Contains | Method::ContainsAny | Method::ContainsAll | Method::NotContains);
        if !schema.array && contains && schema.kind != AttributeType::String {
            let kind = if method == Method::NotContains { "notContains" } else { "contains" };
            return Err(format!(
                "Cannot query {kind} on attribute \"{attribute}\" because it is not an array, string, or object."
            ));
        }
        if schema.array
            && !contains
            && !matches!(method, Method::IsNull | Method::IsNotNull | Method::Exists | Method::NotExists)
        {
            return Err(format!("Cannot query {} on attribute \"{attribute}\" because it is an array.", method.name()));
        }
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    const ATTRS: &[Attribute] = &[
        Attribute::string("name", 256),
        Attribute::boolean("status"),
        Attribute::string("roles", 128).array(),
        Attribute::datetime("registration"),
        Attribute::string("password", 16384).encrypted(),
    ];
    const COLLECTION: Collection = Collection { id: "users", attributes: ATTRS, searchable: true };

    fn check(q: &str) -> Result<Vec<Query>, String> {
        Queries::new(&COLLECTION, &["name", "status", "roles", "registration", "password"]).validate(&json!([q]))
    }

    #[test]
    fn messages() {
        assert!(check(r#"{"method":"equal","attribute":"name","values":["x"]}"#).is_ok());
        assert_eq!(
            check(r#"{"method":"equal","attribute":"roles","values":["x"]}"#).unwrap_err(),
            "Invalid query: Cannot query equal on attribute \"roles\" because it is an array."
        );
        assert!(check(r#"{"method":"contains","attribute":"roles","values":["x"]}"#).is_ok());
        assert_eq!(check(r#"{"method":"select","values":["name"]}"#).unwrap_err(), "Invalid query method: select");
        assert_eq!(
            check(r#"{"method":"equal","attribute":"nope","values":["x"]}"#).unwrap_err(),
            "Invalid query: Attribute not found in schema: nope"
        );
        assert_eq!(
            check(r#"{"method":"equal","attribute":"status","values":["x"]}"#).unwrap_err(),
            "Invalid query: Query value is invalid for attribute \"status\""
        );
        assert_eq!(
            check(r#"{"method":"cursorAfter","values":["_bad"]}"#).unwrap_err(),
            "Invalid query: Invalid cursor: UID must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, and underscore. Can't start with a leading underscore"
        );
        assert_eq!(
            check(r#"{"method":"limit","values":[0]}"#).unwrap_err(),
            "Invalid query: Invalid limit: Value must be a valid range between 1 and 9,223,372,036,854,775,808"
        );
        assert_eq!(
            check(r#"{"method":"equal","attribute":"password","values":["x"]}"#).unwrap_err(),
            "Invalid query: Cannot query encrypted attribute: password"
        );
        assert_eq!(check("{").unwrap_err(), "Invalid query: Invalid query: Syntax error");
        assert!(check(r#"{"method":"orderDesc","attribute":"$createdAt"}"#).is_ok());
        assert!(
            check(r#"{"method":"equal","attribute":"registration","values":["2024-01-01T00:00:00.000+00:00"]}"#)
                .is_ok()
        );
    }
}
