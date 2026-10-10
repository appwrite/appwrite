//! SQL parameters and condition rendering (PostgreSQL dialect).

use bytes::BytesMut;
use chrono::NaiveDateTime;
use serde_json::Value;
use tokio_postgres::types::{IsNull, ToSql, Type, to_sql_checked};

use crate::datetime;
use crate::query::{Method, Query};
use crate::schema::{Attribute, AttributeType, Collection};
use crate::{Error, Result};

/// A bind parameter with an explicit PostgreSQL type.
///
/// Statements are sent with `query_typed` (one round trip, no server-side
/// prepare), so every parameter carries its type. JSON values travel as text
/// and are cast in SQL (`$n::jsonb`).
#[derive(Debug, Clone, PartialEq)]
pub enum Param {
    Null(Type),
    Text(String),
    Int(i64),
    Int4(i32),
    Float(f64),
    Bool(bool),
    Timestamp(NaiveDateTime),
    /// JSON text bound as TEXT and cast to JSONB in SQL.
    Jsonb(String),
    TextArray(Vec<String>),
}

impl Param {
    pub fn pg_type(&self) -> Type {
        match self {
            Param::Null(t) => t.clone(),
            Param::Text(_) | Param::Jsonb(_) => Type::TEXT,
            Param::Int(_) => Type::INT8,
            Param::Int4(_) => Type::INT4,
            Param::Float(_) => Type::FLOAT8,
            Param::Bool(_) => Type::BOOL,
            Param::Timestamp(_) => Type::TIMESTAMP,
            Param::TextArray(_) => Type::TEXT_ARRAY,
        }
    }

    /// SQL cast appended to the placeholder.
    pub fn cast(&self) -> &'static str {
        match self {
            Param::Jsonb(_) => "::jsonb",
            Param::Null(t) if *t == Type::JSONB => "::jsonb",
            _ => "",
        }
    }

    pub fn text(s: impl Into<String>) -> Self {
        Param::Text(s.into())
    }

    pub fn opt_text(s: Option<impl Into<String>>) -> Self {
        match s {
            Some(s) => Param::Text(s.into()),
            None => Param::Null(Type::TEXT),
        }
    }

    pub fn opt_bool(b: Option<bool>) -> Self {
        match b {
            Some(b) => Param::Bool(b),
            None => Param::Null(Type::BOOL),
        }
    }

    pub fn opt_timestamp(t: Option<NaiveDateTime>) -> Self {
        match t {
            Some(t) => Param::Timestamp(t),
            None => Param::Null(Type::TIMESTAMP),
        }
    }

    /// JSON array of strings for JSONB array columns.
    pub fn string_list(items: &[String]) -> Self {
        Param::Jsonb(serde_json::to_string(items).unwrap_or_else(|_| "[]".to_owned()))
    }
}

impl ToSql for Param {
    fn to_sql(
        &self,
        ty: &Type,
        out: &mut BytesMut,
    ) -> std::result::Result<IsNull, Box<dyn std::error::Error + Sync + Send>> {
        match self {
            Param::Null(_) => Ok(IsNull::Yes),
            Param::Text(s) | Param::Jsonb(s) => s.as_str().to_sql(&Type::TEXT, out),
            Param::Int(i) => {
                if *ty == Type::INT4 {
                    (*i as i32).to_sql(ty, out)
                } else {
                    i.to_sql(&Type::INT8, out)
                }
            }
            Param::Int4(i) => i.to_sql(&Type::INT4, out),
            Param::Float(f) => f.to_sql(&Type::FLOAT8, out),
            Param::Bool(b) => b.to_sql(&Type::BOOL, out),
            Param::Timestamp(t) => t.to_sql(&Type::TIMESTAMP, out),
            Param::TextArray(v) => v.to_sql(&Type::TEXT_ARRAY, out),
        }
    }

    fn accepts(_ty: &Type) -> bool {
        true
    }

    to_sql_checked!();
}

/// Quotes an identifier (attribute ids are filtered to `[A-Za-z0-9_-]` by PHP).
pub fn quote(identifier: &str) -> String {
    let filtered: String = identifier.chars().filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-').collect();
    format!("\"{filtered}\"")
}

/// Accumulates bind parameters while rendering SQL.
#[derive(Default)]
pub struct Builder {
    pub params: Vec<Param>,
}

impl Builder {
    pub fn new() -> Self {
        Self::default()
    }

    /// Binds a parameter and returns its placeholder (with cast).
    pub fn bind(&mut self, param: Param) -> String {
        let cast = param.cast();
        self.params.push(param);
        format!("${}{}", self.params.len(), cast)
    }

    /// Typed parameter slice for `query_typed`.
    pub fn typed(&self) -> Vec<(&(dyn ToSql + Sync), Type)> {
        self.params.iter().map(|p| (p as &(dyn ToSql + Sync), p.pg_type())).collect()
    }

    /// Renders filters joined with AND, or `None` when empty.
    pub fn conditions(&mut self, collection: &Collection, filters: &[Query]) -> Result<Option<String>> {
        let mut parts = Vec::with_capacity(filters.len());
        for q in filters {
            parts.push(self.condition(collection, q)?);
        }
        if parts.is_empty() { Ok(None) } else { Ok(Some(format!("({})", parts.join(" AND ")))) }
    }

    fn condition(&mut self, collection: &Collection, query: &Query) -> Result<String> {
        if matches!(query.method, Method::And | Method::Or) {
            let separator = if query.method == Method::And { " and " } else { " or " };
            let mut parts = Vec::new();
            for q in &query.queries {
                parts.push(self.condition(collection, q)?);
            }
            return Ok(format!("({})", parts.join(separator)));
        }

        let attribute = collection
            .attribute(&query.attribute)
            .copied()
            .ok_or_else(|| Error::Query(format!("Attribute not found in schema: {}", query.attribute)))?;
        let column = quote(Attribute::column(&query.attribute));
        let main = "\"main\"";

        Ok(match query.method {
            Method::Search | Method::NotSearch => {
                let value = fulltext_value(query.value_str().unwrap_or(""));
                if value.is_empty() {
                    return Ok(if query.method == Method::Search { "0 = 1" } else { "1 = 1" }.to_owned());
                }
                let p = self.bind(Param::Text(value));
                let expr =
                    format!("to_tsvector(regexp_replace({column}, '[^\\w]+',' ','g')) @@ websearch_to_tsquery({p})");
                if query.method == Method::Search { expr } else { format!("NOT ({expr})") }
            }
            Method::Between | Method::NotBetween => {
                let a = self.value(&attribute, query.values.first())?;
                let b = self.value(&attribute, query.values.get(1))?;
                let a = self.bind(a);
                let b = self.bind(b);
                let not = if query.method == Method::NotBetween { "NOT " } else { "" };
                format!("{main}.{column} {not}BETWEEN {a} AND {b}")
            }
            Method::IsNull | Method::NotExists => format!("{main}.{column} IS NULL"),
            Method::IsNotNull | Method::Exists => format!("{main}.{column} IS NOT NULL"),
            Method::ContainsAll if attribute.array => {
                let p = self.bind(Param::Jsonb(serde_json::to_string(&query.values).unwrap_or_default()));
                format!("{main}.{column} @> {p}")
            }
            _ => {
                let is_not = matches!(query.method, Method::NotStartsWith | Method::NotEndsWith | Method::NotContains);
                let mut parts = Vec::with_capacity(query.values.len());
                for value in &query.values {
                    let (operator, param) = match query.method {
                        Method::Equal => ("=", self.value(&attribute, Some(value))?),
                        Method::NotEqual => ("!=", self.value(&attribute, Some(value))?),
                        Method::LessThan => ("<", self.value(&attribute, Some(value))?),
                        Method::LessThanEqual => ("<=", self.value(&attribute, Some(value))?),
                        Method::GreaterThan => (">", self.value(&attribute, Some(value))?),
                        Method::GreaterThanEqual => (">=", self.value(&attribute, Some(value))?),
                        Method::Regex => ("~", Param::Text(value_string(value))),
                        Method::StartsWith | Method::NotStartsWith => {
                            ("ILIKE", Param::Text(format!("{}%", escape_wildcards(&value_string(value)))))
                        }
                        Method::EndsWith | Method::NotEndsWith => {
                            ("ILIKE", Param::Text(format!("%{}", escape_wildcards(&value_string(value)))))
                        }
                        Method::Contains | Method::ContainsAny | Method::NotContains | Method::ContainsAll => {
                            if attribute.array {
                                ("@>", Param::Jsonb(serde_json::to_string(value).unwrap_or_default()))
                            } else {
                                ("ILIKE", Param::Text(format!("%{}%", escape_wildcards(&value_string(value)))))
                            }
                        }
                        other => {
                            return Err(Error::Query(format!("Unsupported query method: {}", other.name())));
                        }
                    };
                    let p = self.bind(param);
                    parts.push(if is_not && attribute.array {
                        format!("NOT ({main}.{column} {operator} {p})")
                    } else if is_not {
                        format!("{main}.{column} NOT {operator} {p}")
                    } else {
                        format!("{main}.{column} {operator} {p}")
                    });
                }
                if parts.is_empty() {
                    return Ok("1 = 1".to_owned());
                }
                let separator = if is_not { " AND " } else { " OR " };
                format!("({})", parts.join(separator))
            }
        })
    }

    /// Converts a query value to a typed parameter for `attribute`.
    pub fn value(&self, attribute: &Attribute, value: Option<&Value>) -> Result<Param> {
        let value = value.ok_or_else(|| Error::Query("Missing query value".to_owned()))?;
        let invalid = || Error::Query(format!("Query value is invalid for attribute \"{}\"", attribute.id));
        Ok(match attribute.kind {
            AttributeType::String => Param::Text(value_string(value)),
            AttributeType::Integer | AttributeType::Id => match value {
                Value::Number(n) => Param::Int(n.as_i64().ok_or_else(invalid)?),
                Value::String(s) => Param::Int(s.trim().parse().map_err(|_| invalid())?),
                _ => return Err(invalid()),
            },
            AttributeType::Float => Param::Float(value.as_f64().ok_or_else(invalid)?),
            AttributeType::Boolean => Param::Bool(value.as_bool().ok_or_else(invalid)?),
            AttributeType::Datetime => {
                Param::Timestamp(datetime::parse(value.as_str().ok_or_else(invalid)?).ok_or_else(invalid)?)
            }
        })
    }
}

fn value_string(value: &Value) -> String {
    match value {
        Value::String(s) => s.clone(),
        other => other.to_string(),
    }
}

/// `SQL::escapeWildcards`.
pub fn escape_wildcards(value: &str) -> String {
    let mut out = String::with_capacity(value.len() + 4);
    for c in value.chars() {
        if matches!(c, '%' | '_' | '[' | ']' | '^' | '-' | '.' | '*' | '+' | '?' | '(' | ')' | '{' | '}' | '|') {
            out.push('\\');
        }
        out.push(c);
    }
    out
}

/// `Postgres::getFulltextValue`.
pub fn fulltext_value(value: &str) -> String {
    let exact = value.len() >= 2 && value.starts_with('"') && value.ends_with('"');
    let cleaned: String = value
        .chars()
        .map(|c| if c.is_alphabetic() || c.is_numeric() || c == '_' || c.is_whitespace() { c } else { ' ' })
        .collect();
    let collapsed = cleaned.split_whitespace().collect::<Vec<_>>().join(" ");
    if collapsed.is_empty() {
        return String::new();
    }
    let body = if exact { collapsed } else { collapsed.replace(' ', " or ") };
    format!("'{body}'")
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::schema::Attribute;
    use serde_json::json;

    const ATTRS: &[Attribute] = &[
        Attribute::string("name", 256),
        Attribute::string("labels", 128).array(),
        Attribute::boolean("status"),
        Attribute::string("search", 16384),
    ];
    const C: Collection = Collection { id: "users", attributes: ATTRS, searchable: true };

    #[test]
    fn renders_conditions() {
        let mut b = Builder::new();
        let sql = b
            .conditions(
                &C,
                &[
                    Query::equal("name", vec![json!("a"), json!("b")]),
                    Query::new(Method::Contains, "labels", vec![json!("vip")]),
                    Query::new(Method::StartsWith, "name", vec![json!("Jo_")]),
                    Query::equal("status", vec![json!(true)]),
                    Query::search("search", "john doe"),
                ],
            )
            .unwrap()
            .unwrap();
        assert_eq!(
            sql,
            "((\"main\".\"name\" = $1 OR \"main\".\"name\" = $2) AND (\"main\".\"labels\" @> $3::jsonb) AND (\"main\".\"name\" ILIKE $4) AND (\"main\".\"status\" = $5) AND to_tsvector(regexp_replace(\"search\", '[^\\w]+',' ','g')) @@ websearch_to_tsquery($6))"
        );
        assert_eq!(b.params[2], Param::Jsonb("\"vip\"".into()));
        assert_eq!(b.params[3], Param::Text("Jo\\_%".into()));
        assert_eq!(b.params[5], Param::Text("'john or doe'".into()));
    }

    #[test]
    fn fulltext() {
        assert_eq!(fulltext_value("\"users.service@updated.com\""), "'users service updated com'");
        assert_eq!(fulltext_value("man"), "'man'");
        assert_eq!(fulltext_value(">"), "");
        assert_eq!(fulltext_value("+910000000000"), "'910000000000'");
    }
}
