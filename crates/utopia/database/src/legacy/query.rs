//! Query model and JSON parsing (`Utopia\Database\Query`).

use serde_json::{Map, Value, json};

/// Query methods.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Method {
    Equal,
    NotEqual,
    LessThan,
    LessThanEqual,
    GreaterThan,
    GreaterThanEqual,
    Contains,
    ContainsAny,
    ContainsAll,
    NotContains,
    Search,
    NotSearch,
    IsNull,
    IsNotNull,
    Between,
    NotBetween,
    StartsWith,
    NotStartsWith,
    EndsWith,
    NotEndsWith,
    Regex,
    Exists,
    NotExists,
    Select,
    OrderAsc,
    OrderDesc,
    OrderRandom,
    Limit,
    Offset,
    CursorAfter,
    CursorBefore,
    And,
    Or,
    ElemMatch,
    Spatial(&'static str),
    Vector(&'static str),
}

const SPATIAL: [&str; 12] = [
    "crosses",
    "notCrosses",
    "distanceEqual",
    "distanceNotEqual",
    "distanceGreaterThan",
    "distanceLessThan",
    "intersects",
    "notIntersects",
    "overlaps",
    "notOverlaps",
    "touches",
    "notTouches",
];
const VECTOR: [&str; 3] = ["vectorDot", "vectorCosine", "vectorEuclidean"];

impl Method {
    pub fn parse(name: &str) -> Option<Self> {
        Some(match name {
            "equal" => Method::Equal,
            "notEqual" => Method::NotEqual,
            "lessThan" => Method::LessThan,
            "lessThanEqual" => Method::LessThanEqual,
            "greaterThan" => Method::GreaterThan,
            "greaterThanEqual" => Method::GreaterThanEqual,
            "contains" => Method::Contains,
            "containsAny" => Method::ContainsAny,
            "containsAll" => Method::ContainsAll,
            "notContains" => Method::NotContains,
            "search" => Method::Search,
            "notSearch" => Method::NotSearch,
            "isNull" => Method::IsNull,
            "isNotNull" => Method::IsNotNull,
            "between" => Method::Between,
            "notBetween" => Method::NotBetween,
            "startsWith" => Method::StartsWith,
            "notStartsWith" => Method::NotStartsWith,
            "endsWith" => Method::EndsWith,
            "notEndsWith" => Method::NotEndsWith,
            "regex" => Method::Regex,
            "exists" => Method::Exists,
            "notExists" => Method::NotExists,
            "select" => Method::Select,
            "orderAsc" => Method::OrderAsc,
            "orderDesc" => Method::OrderDesc,
            "orderRandom" => Method::OrderRandom,
            "limit" => Method::Limit,
            "offset" => Method::Offset,
            "cursorAfter" => Method::CursorAfter,
            "cursorBefore" => Method::CursorBefore,
            "and" => Method::And,
            "or" => Method::Or,
            "elemMatch" => Method::ElemMatch,
            other => {
                if let Some(s) = SPATIAL.iter().find(|s| **s == other) {
                    Method::Spatial(s)
                } else {
                    let v = VECTOR.iter().find(|v| **v == other)?;
                    Method::Vector(v)
                }
            }
        })
    }

    pub fn name(self) -> &'static str {
        match self {
            Method::Equal => "equal",
            Method::NotEqual => "notEqual",
            Method::LessThan => "lessThan",
            Method::LessThanEqual => "lessThanEqual",
            Method::GreaterThan => "greaterThan",
            Method::GreaterThanEqual => "greaterThanEqual",
            Method::Contains => "contains",
            Method::ContainsAny => "containsAny",
            Method::ContainsAll => "containsAll",
            Method::NotContains => "notContains",
            Method::Search => "search",
            Method::NotSearch => "notSearch",
            Method::IsNull => "isNull",
            Method::IsNotNull => "isNotNull",
            Method::Between => "between",
            Method::NotBetween => "notBetween",
            Method::StartsWith => "startsWith",
            Method::NotStartsWith => "notStartsWith",
            Method::EndsWith => "endsWith",
            Method::NotEndsWith => "notEndsWith",
            Method::Regex => "regex",
            Method::Exists => "exists",
            Method::NotExists => "notExists",
            Method::Select => "select",
            Method::OrderAsc => "orderAsc",
            Method::OrderDesc => "orderDesc",
            Method::OrderRandom => "orderRandom",
            Method::Limit => "limit",
            Method::Offset => "offset",
            Method::CursorAfter => "cursorAfter",
            Method::CursorBefore => "cursorBefore",
            Method::And => "and",
            Method::Or => "or",
            Method::ElemMatch => "elemMatch",
            Method::Spatial(s) | Method::Vector(s) => s,
        }
    }

    pub fn is_logical(self) -> bool {
        matches!(self, Method::And | Method::Or | Method::ElemMatch)
    }

    pub fn is_filter(self) -> bool {
        !matches!(
            self,
            Method::Select
                | Method::OrderAsc
                | Method::OrderDesc
                | Method::OrderRandom
                | Method::Limit
                | Method::Offset
                | Method::CursorAfter
                | Method::CursorBefore
        )
    }
}

/// A parsed query. Logical queries (`and`, `or`, `elemMatch`) keep their
/// children in `queries`.
#[derive(Debug, Clone, PartialEq)]
pub struct Query {
    pub method: Method,
    pub attribute: String,
    pub values: Vec<Value>,
    pub queries: Vec<Query>,
}

fn php_type(v: &Value) -> &'static str {
    match v {
        Value::Null => "NULL",
        Value::Bool(_) => "boolean",
        Value::Number(n) if n.is_f64() => "double",
        Value::Number(_) => "integer",
        Value::String(_) => "string",
        Value::Array(_) | Value::Object(_) => "array",
    }
}

impl Query {
    pub fn new(method: Method, attribute: impl Into<String>, values: Vec<Value>) -> Self {
        Self { method, attribute: attribute.into(), values, queries: Vec::new() }
    }

    pub fn equal(attribute: &str, values: Vec<Value>) -> Self {
        Self::new(Method::Equal, attribute, values)
    }

    pub fn search(attribute: &str, value: &str) -> Self {
        Self::new(Method::Search, attribute, vec![Value::String(value.to_owned())])
    }

    pub fn limit(limit: i64) -> Self {
        Self::new(Method::Limit, "", vec![json!(limit)])
    }

    pub fn order_asc(attribute: &str) -> Self {
        Self::new(Method::OrderAsc, attribute, Vec::new())
    }

    pub fn order_desc(attribute: &str) -> Self {
        Self::new(Method::OrderDesc, attribute, Vec::new())
    }

    /// `Query::parse()`; error messages match PHP exactly.
    pub fn parse(input: &str) -> Result<Self, String> {
        let value: Value = serde_json::from_str(input).map_err(|_| "Invalid query: Syntax error".to_owned())?;
        match value {
            Value::Object(map) => Self::parse_map(&map),
            Value::Array(list) => {
                // A JSON list is a PHP array without the expected keys.
                let map: Map<String, Value> = list.into_iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect();
                Self::parse_map(&map)
            }
            other => Err(format!("Invalid query. Must be an array, got {}", php_type(&other))),
        }
    }

    fn parse_map(map: &Map<String, Value>) -> Result<Self, String> {
        let method = map.get("method").filter(|v| !v.is_null()).cloned().unwrap_or(Value::String(String::new()));
        let attribute = map.get("attribute").filter(|v| !v.is_null()).cloned().unwrap_or(Value::String(String::new()));
        let values = map.get("values").filter(|v| !v.is_null()).cloned().unwrap_or(Value::Array(Vec::new()));

        let Value::String(method_name) = &method else {
            return Err(format!("Invalid query method. Must be a string, got {}", php_type(&method)));
        };
        let Some(method) = Method::parse(method_name) else {
            return Err(format!("Invalid query method: {method_name}"));
        };
        let Value::String(attribute) = attribute else {
            return Err(format!("Invalid query attribute. Must be a string, got {}", php_type(&attribute)));
        };
        let values: Vec<Value> = match values {
            Value::Array(v) => v,
            Value::Object(o) if !o.is_empty() => o.into_iter().map(|(_, v)| v).collect(),
            other => return Err(format!("Invalid query values. Must be an array, got {}", php_type(&other))),
        };

        let mut queries = Vec::new();
        if method.is_logical() {
            for value in &values {
                match value {
                    Value::String(s) => queries.push(Self::parse(s)?),
                    Value::Object(m) => queries.push(Self::parse_map(m)?),
                    Value::Array(list) => {
                        let m: Map<String, Value> =
                            list.iter().cloned().enumerate().map(|(i, v)| (i.to_string(), v)).collect();
                        queries.push(Self::parse_map(&m)?)
                    }
                    other => {
                        return Err(format!(
                            "Invalid nested query. Must be an array or string, got {}",
                            php_type(other)
                        ));
                    }
                }
            }
        }
        Ok(Self { method, attribute, values, queries })
    }

    /// First value as a string, if any.
    pub fn value_str(&self) -> Option<&str> {
        self.values.first().and_then(Value::as_str)
    }
}

/// Order direction.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum OrderDirection {
    Asc,
    Desc,
    Random,
}

/// A single ORDER BY entry.
#[derive(Debug, Clone, PartialEq)]
pub struct Order {
    pub attribute: String,
    pub direction: OrderDirection,
}

/// Cursor direction.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum CursorDirection {
    After,
    Before,
}

/// A cursor: the document id it points to.
#[derive(Debug, Clone, PartialEq)]
pub struct Cursor {
    pub direction: CursorDirection,
    pub id: String,
}

/// Queries grouped by role (`Query::groupByType`).
#[derive(Debug, Clone, Default)]
pub struct QueryGroups {
    pub filters: Vec<Query>,
    pub selections: Vec<Query>,
    pub limit: Option<i64>,
    pub offset: Option<i64>,
    pub orders: Vec<Order>,
    pub cursor: Option<Cursor>,
}

impl QueryGroups {
    pub fn from_queries(queries: &[Query]) -> Self {
        let mut groups = QueryGroups::default();
        let to_int = |v: Option<&Value>| -> Option<i64> {
            match v? {
                Value::Number(n) => n.as_i64().or_else(|| n.as_f64().map(|f| f as i64)),
                Value::String(s) => s.trim().parse().ok(),
                _ => None,
            }
        };
        for q in queries {
            match q.method {
                Method::OrderAsc | Method::OrderDesc | Method::OrderRandom => {
                    let direction = match q.method {
                        Method::OrderAsc => OrderDirection::Asc,
                        Method::OrderDesc => OrderDirection::Desc,
                        _ => OrderDirection::Random,
                    };
                    let attribute = if q.attribute.is_empty() { "$sequence".to_owned() } else { q.attribute.clone() };
                    groups.orders.push(Order { attribute, direction });
                }
                Method::Limit => {
                    if groups.limit.is_none() {
                        groups.limit = to_int(q.values.first());
                    }
                }
                Method::Offset => {
                    if groups.offset.is_none() {
                        groups.offset = to_int(q.values.first());
                    }
                }
                Method::CursorAfter | Method::CursorBefore => {
                    if groups.cursor.is_none()
                        && let Some(id) = q.value_str()
                    {
                        groups.cursor = Some(Cursor {
                            direction: if q.method == Method::CursorAfter {
                                CursorDirection::After
                            } else {
                                CursorDirection::Before
                            },
                            id: id.to_owned(),
                        });
                    }
                }
                Method::Select => groups.selections.push(q.clone()),
                _ => groups.filters.push(q.clone()),
            }
        }
        groups
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_queries() {
        let q = Query::parse(r#"{"method":"equal","attribute":"name","values":["John"]}"#).unwrap();
        assert_eq!(q.method, Method::Equal);
        assert_eq!(q.attribute, "name");
        assert_eq!(q.values, vec![json!("John")]);

        let q = Query::parse(r#"{"method":"or","values":[{"method":"equal","attribute":"a","values":[1]},"{\"method\":\"isNull\",\"attribute\":\"b\"}"]}"#).unwrap();
        assert_eq!(q.queries.len(), 2);
        assert_eq!(q.queries[1].method, Method::IsNull);
    }

    #[test]
    fn parse_errors() {
        assert_eq!(Query::parse("{").unwrap_err(), "Invalid query: Syntax error");
        assert_eq!(Query::parse("1").unwrap_err(), "Invalid query. Must be an array, got integer");
        assert_eq!(Query::parse(r#"{"method":"foo"}"#).unwrap_err(), "Invalid query method: foo");
        assert_eq!(Query::parse(r#"{"method":1}"#).unwrap_err(), "Invalid query method. Must be a string, got integer");
        assert_eq!(
            Query::parse(r#"{"method":"equal","attribute":1}"#).unwrap_err(),
            "Invalid query attribute. Must be a string, got integer"
        );
        assert_eq!(
            Query::parse(r#"{"method":"equal","values":"x"}"#).unwrap_err(),
            "Invalid query values. Must be an array, got string"
        );
    }

    #[test]
    fn groups() {
        let qs = vec![
            Query::parse(r#"{"method":"limit","values":[5]}"#).unwrap(),
            Query::parse(r#"{"method":"limit","values":[9]}"#).unwrap(),
            Query::parse(r#"{"method":"cursorAfter","values":["abc"]}"#).unwrap(),
            Query::parse(r#"{"method":"orderDesc"}"#).unwrap(),
            Query::equal("name", vec![json!("x")]),
        ];
        let g = QueryGroups::from_queries(&qs);
        assert_eq!(g.limit, Some(5));
        assert_eq!(g.cursor.unwrap().id, "abc");
        assert_eq!(g.orders[0].attribute, "$sequence");
        assert_eq!(g.filters.len(), 1);
    }
}
