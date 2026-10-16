//! [`Aggregation`]: a row as the engine reads it shaped into the row
//! [`Database::aggregate`] returns.
//!
//! PHP: `Utopia\Database\Aggregation`.

use php_std::json::{self, Flags};
use utopia_query::Method;
use utopia_query::schema::ColumnType;

use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::Database;
use crate::document::{self, Document};
use crate::error::Result;
use crate::filter::Filter;
use crate::query::Query;
use crate::storage;
use crate::validator::query::aggregate::MAX_ALIAS_LENGTH;
use crate::value::{Array, ArrayKey, Ext, Str, Value};

/// The aggregates that count: integers.
const COUNTS: [Method; 5] = [Method::Count, Method::CountDistinct, Method::BitAnd, Method::BitOr, Method::BitXor];

/// The statistics: floats.
const STATISTICS: [Method; 7] = [
    Method::Avg,
    Method::Stddev,
    Method::StddevPop,
    Method::StddevSamp,
    Method::Variance,
    Method::VarPop,
    Method::VarSamp,
];

const INTEGERS: [ColumnType; 7] = [
    ColumnType::TinyInteger,
    ColumnType::SmallInteger,
    ColumnType::Integer,
    ColumnType::BigInteger,
    ColumnType::Serial,
    ColumnType::SmallSerial,
    ColumnType::BigSerial,
];

const FLOATS: [ColumnType; 3] = [ColumnType::Float, ColumnType::Double, ColumnType::Decimal];

const DATETIMES: [ColumnType; 2] = [ColumnType::Datetime, ColumnType::Timestamp];

const OBJECTS: [ColumnType; 2] = [ColumnType::Json, ColumnType::Object];

/// Shapes a row as the engine reads it into the row
/// [`Database::aggregate`] returns: each aggregate typed by its method and
/// the attribute it reads, each group named, typed and decoded as a read
/// of the same attribute returns it.
///
/// Counts and bitwise aggregates are integers, averages, deviations and
/// variances floats, and a sum an integer over an integer attribute and a
/// float otherwise; `min()` and `max()` are typed as their attribute.
///
/// PHP: `Utopia\Database\Aggregation`.
#[derive(Clone)]
pub struct Aggregation {
    database: Database,
    /// The method of each aggregate, by its alias.
    aggregates: Vec<(String, Method)>,
    /// The attribute each aggregate reads, by its alias.
    operands: Vec<(String, Attribute)>,
    /// The name a read returns each group under, by the name the queries
    /// give it.
    groups: Vec<(String, String)>,
    /// The attribute of each group but the tenant, by the name a read
    /// returns it under.
    attributes: Vec<(String, Attribute)>,
    /// The join alias each group is read from, `""` for the collection
    /// read, by the name a read returns it under.
    sources: Vec<(String, String)>,
    /// The keys of the attributes every document carries.
    internal: Vec<String>,
}

fn lookup<'m, T>(map: &'m [(String, T)], key: &str) -> Option<&'m T> {
    map.iter().find(|(k, _)| k == key).map(|(_, v)| v)
}

/// `$map[$key] = $value`: a key keeps its first position.
fn assign<T>(map: &mut Vec<(String, T)>, key: &str, value: T) {
    match map.iter_mut().find(|(k, _)| k == key) {
        Some(entry) => entry.1 = value,
        None => map.push((key.to_owned(), value)),
    }
}

impl Aggregation {
    /// The aggregation of `queries` (each aggregate aliased) over
    /// `collection` and the collections its joins read, by alias
    /// (`from()`); `database`'s filters decode the groups.
    pub fn from(
        database: &Database,
        collection: &Collection,
        joined: &[(String, Collection)],
        queries: &[Query],
    ) -> Self {
        let database = database;
        let internal = database.internal_attributes();

        let mut schema: Vec<(String, Attribute)> = Vec::new();
        let mut origins: Vec<(String, String)> = Vec::new();
        for attribute in internal.iter().cloned().chain(attributes(collection)) {
            let key = attribute.key.clone();
            assign(&mut schema, &key, attribute);
            assign(&mut origins, &key, String::new());
        }
        for (alias, definition) in joined {
            for attribute in internal.iter().cloned().chain(attributes(definition)) {
                let key = format!("{alias}.{}", attribute.key);
                assign(&mut schema, &key, attribute);
                assign(&mut origins, &key, alias.clone());
            }
        }

        let mut aggregation = Aggregation {
            database: database.clone(),
            aggregates: Vec::new(),
            operands: Vec::new(),
            groups: Vec::new(),
            attributes: Vec::new(),
            sources: Vec::new(),
            internal: internal.iter().map(|a| a.key.clone()).collect(),
        };

        for query in queries {
            let method = query.method();
            if method.is_aggregate() {
                let alias = query.alias().to_owned();
                assign(&mut aggregation.aggregates, &alias, method);
                if let Some(operand) = lookup(&schema, &name(&schema, query.attribute())) {
                    assign(&mut aggregation.operands, &alias, operand.clone());
                }
                continue;
            }

            if method != Method::GroupBy {
                continue;
            }

            for group in query.values().values() {
                let utopia_query::Value::Str(group) = group else { continue };
                let group = String::from_utf8_lossy(group.as_bytes()).into_owned();
                if group.is_empty() {
                    continue;
                }
                let name = name(&schema, &group);
                assign(&mut aggregation.groups, &group, name.clone());
                if let Some(attribute) = lookup(&schema, &name)
                    && attribute.key != document::TENANT
                {
                    assign(&mut aggregation.attributes, &name, attribute.clone());
                    assign(&mut aggregation.sources, &name, lookup(&origins, &name).cloned().unwrap_or_default());
                }
            }
        }

        aggregation
    }

    /// The row the adapter read, shaped (`decode()`).
    ///
    /// Fails as a group's decode filter does.
    pub async fn decode(&self, row: Array) -> Result<Array> {
        let columns: Vec<String> = row.keys().map(|k| key_text(&k)).collect();
        let names = self.names(&columns);

        let mut shaped = Array::with_capacity(row.len());
        let mut filtered: Vec<(String, Vec<(String, Value)>)> = Vec::new();
        for (column, value) in row {
            let column = key_text(&column.as_ref());
            if let Some(method) = lookup(&self.aggregates, &column) {
                let value = aggregate(*method, lookup(&self.operands, &column), value);
                shaped.set(ArrayKey::Str(Str::from(column.as_str())), value);
                continue;
            }

            let name = lookup(&names, &column).cloned().unwrap_or(column);
            let Some(attribute) = lookup(&self.attributes, &name) else {
                shaped.set(ArrayKey::Str(Str::from(name.as_str())), value);
                continue;
            };

            let value = cast(attribute, value);
            shaped.set(ArrayKey::Str(Str::from(name.as_str())), value.clone());
            if !matches!(value, Value::Null) && !attribute.filters.is_empty() {
                let source = lookup(&self.sources, &name).cloned().unwrap_or_default();
                match filtered.iter_mut().find(|(s, _)| *s == source) {
                    Some((_, values)) => assign(values, &name, value),
                    None => filtered.push((source, vec![(name, value)])),
                }
            }
        }

        for (alias, values) in filtered {
            for (name, value) in self.filter(&alias, values).await? {
                shaped.set(ArrayKey::Str(Str::from(name.as_str())), value);
            }
        }

        Ok(shaped)
    }

    /// Groups read from one collection passed through the filters their
    /// attributes declare, as a read of that collection decodes them
    /// (`filter()`).
    async fn filter(&self, alias: &str, values: Vec<(String, Value)>) -> Result<Vec<(String, Value)>> {
        let prefix = if alias.is_empty() { String::new() } else { format!("{alias}.") };

        let mut keys: Vec<(String, String)> = Vec::with_capacity(values.len());
        let mut definitions = Vec::new();
        let mut stored = Document::empty();
        for (name, value) in values {
            let key = name.get(prefix.len()..).unwrap_or_default().to_owned();
            stored.put(key.as_str(), value);
            if !self.internal.contains(&key)
                && let Some(attribute) = lookup(&self.attributes, &name)
            {
                definitions.push(Value::from(attribute.to_document()?));
            }
            keys.push((name, key));
        }

        let mut definition = Document::empty();
        definition.put(document::ID, Value::Str(Str::from(alias)));
        definition.put("attributes", Value::Array(Array::from_list(definitions)));
        let collection = Collection::from_document(&definition)?;
        let selections: Vec<String> = keys.iter().map(|(_, key)| key.clone()).collect();
        let decoded = self.database.decode(&collection, stored, &selections).await?;

        Ok(keys.into_iter().map(|(name, key)| (name, decoded.get(&key).cloned().unwrap_or(Value::Null))).collect())
    }

    /// The name a read returns each group column of a row under: as the
    /// queries name its group, or failing that by the group's storage
    /// column (`names()`).
    fn names(&self, columns: &[String]) -> Vec<(String, String)> {
        let mut unclaimed: Vec<&str> =
            columns.iter().map(String::as_str).filter(|c| lookup(&self.aggregates, c).is_none()).collect();

        let mut names = Vec::new();
        let mut pending = Vec::new();
        for (group, name) in &self.groups {
            if let Some(position) = unclaimed.iter().position(|c| c == group) {
                assign(&mut names, group, name.clone());
                unclaimed.remove(position);
            } else {
                pending.push((group, name));
            }
        }

        for (group, name) in pending {
            let key = group.split_once('.').map_or(group.as_str(), |(_, key)| key);
            for column in [storage::column(key), key] {
                if let Some(position) = unclaimed.iter().position(|c| *c == column) {
                    assign(&mut names, column, name.clone());
                    unclaimed.remove(position);
                    break;
                }
            }
        }

        names
    }

    /// The name an aggregate the queries leave unaliased comes back under:
    /// `<method>_<attribute>`, or `<method>` for `count('*')`, each
    /// character other than a letter, digit or underscore replaced by an
    /// underscore (`alias()`).
    ///
    /// ```
    /// use utopia_database::Query;
    /// use utopia_database::aggregation::Aggregation;
    ///
    /// assert_eq!(Aggregation::alias(&Query::count("*", "")), "count");
    /// assert_eq!(Aggregation::alias(&Query::sum("o.total", "")), "sum_o_total");
    /// ```
    pub fn alias(query: &Query) -> String {
        let method = query.method().as_str();
        let attribute = query.attribute();
        let alias =
            if attribute == "*" || attribute.is_empty() { method.to_owned() } else { format!("{method}_{attribute}") };
        let sanitized: Vec<u8> =
            alias.bytes().map(|b| if b.is_ascii_alphanumeric() || b == b'_' { b } else { b'_' }).collect();
        let end = sanitized.len().min(MAX_ALIAS_LENGTH);
        String::from_utf8_lossy(&sanitized[..end]).into_owned()
    }
}

/// An aggregate's value typed by its method and the attribute it reads
/// (`aggregate()`).
fn aggregate(method: Method, operand: Option<&Attribute>, value: Value) -> Value {
    if matches!(value, Value::Null) {
        return Value::Null;
    }
    if COUNTS.contains(&method) {
        return integer(&value);
    }
    if STATISTICS.contains(&method) {
        return Value::Float(float(&value));
    }
    if method == Method::Sum {
        return match operand {
            Some(operand) if INTEGERS.contains(&operand.kind) => integer(&value),
            _ => Value::Float(float(&value)),
        };
    }
    match operand {
        None => value,
        Some(operand) => cast(operand, value),
    }
}

/// The name a read returns an attribute under: a joined one qualified by
/// its join alias, though the queries may name it bare when no other
/// collection of the read declares it (`name()`).
fn name(schema: &[(String, Attribute)], attribute: &str) -> String {
    if attribute.contains('.') || lookup(schema, attribute).is_some() {
        return attribute.to_owned();
    }
    let suffix = format!(".{attribute}");
    let matches: Vec<&String> = schema.iter().map(|(k, _)| k).filter(|k| k.ends_with(&suffix)).collect();
    match matches.as_slice() {
        [only] => (*only).clone(),
        _ => attribute.to_owned(),
    }
}

/// A group's value as a read of its attribute returns it (`cast()`).
fn cast(attribute: &Attribute, value: Value) -> Value {
    if matches!(value, Value::Null) {
        return Value::Null;
    }

    let encoded = attribute.array
        || OBJECTS.contains(&attribute.kind)
        || attribute.filters.iter().any(|f| f == Filter::Json.as_str());

    let value = match value {
        Value::Str(text) if encoded => {
            json::decode_ext::<Ext>(text.as_bytes(), Some(true), json::DEFAULT_DEPTH, Flags::NONE)
                .unwrap_or(Value::Null)
        }
        other => other,
    };

    match value {
        Value::Array(items) if attribute.array => {
            Value::Array(items.into_iter().map(|(k, v)| (k, scalar(attribute.kind, v))).collect())
        }
        other => scalar(attribute.kind, other),
    }
}

/// One value as its type reads it (`scalar()`).
fn scalar(kind: ColumnType, value: Value) -> Value {
    if matches!(value, Value::Null | Value::Array(_)) {
        return value;
    }
    if kind == ColumnType::Boolean {
        return match value {
            Value::Bool(_) => value,
            other => Value::Bool(matches!(
                filter_var(&other, php_std::filter::FILTER_VALIDATE_BOOL),
                Some(php_std::filter::Value::Bool(true))
            )),
        };
    }
    if kind == ColumnType::Id {
        return match value {
            Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_) => {
                value.to_php_string().map(Value::Str).unwrap_or(value)
            }
            other => other,
        };
    }
    if INTEGERS.contains(&kind) {
        return integer(&value);
    }
    if FLOATS.contains(&kind) {
        return Value::Float(float(&value));
    }
    if DATETIMES.contains(&kind)
        && let Value::Str(text) = &value
    {
        let text = String::from_utf8_lossy(text.as_bytes()).into_owned();
        return crate::datetime::format_tz(Some(&text)).map(|t| Value::Str(Str::from(t.as_str()))).unwrap_or(value);
    }
    value
}

/// An integer, though MariaDB sums integers into `DECIMAL`, so a whole value
/// can arrive as `"7.0000"`; one past the platform's integers stays a float
/// rather than wrap (`integer()`).
fn integer(value: &Value) -> Value {
    if let Value::Int(_) = value {
        return value.clone();
    }
    if let Some(php_std::filter::Value::Int(i)) = filter_var(value, php_std::filter::FILTER_VALIDATE_INT) {
        return Value::Int(i);
    }
    let float = float(value);
    if float.floor() == float && float.abs() < i64::MAX as f64 {
        Value::Int(float as i64)
    } else {
        Value::Float(float)
    }
}

/// `is_numeric($value) ? (float) $value : 0.0` (`float()`).
fn float(value: &Value) -> f64 {
    let numeric = match value {
        Value::Int(_) | Value::Float(_) => true,
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    };
    if numeric { value.to_double() } else { 0.0 }
}

/// `filter_var($value, $filter)` of a scalar.
fn filter_var(value: &Value, filter: i64) -> Option<php_std::filter::Value> {
    let value = match value {
        Value::Null => php_std::filter::Value::Null,
        Value::Bool(b) => php_std::filter::Value::Bool(*b),
        Value::Int(i) => php_std::filter::Value::Int(*i),
        Value::Float(f) => php_std::filter::Value::Float(*f),
        Value::Str(s) => php_std::filter::Value::Str(s.as_bytes().to_vec()),
        _ => return None,
    };
    php_std::filter::filter_var(&value, filter, &php_std::filter::Options::Flags(0)).ok().map(|f| f.value)
}

/// The attributes of a collection, leaving out those that do not read as
/// attributes (`attributes()`).
fn attributes(collection: &Collection) -> Vec<Attribute> {
    if let Ok(attributes) = collection.attributes() {
        return attributes.to_vec();
    }
    match collection.document().get("attributes") {
        Some(Value::Array(stored)) => stored
            .values()
            .filter_map(|attribute| match attribute {
                Value::Ext(Ext::Document(d)) => Attribute::from_document(d).ok(),
                _ => None,
            })
            .collect(),
        _ => Vec::new(),
    }
}

/// A row key as text.
fn key_text(key: &php_std::types::KeyRef<'_>) -> String {
    match key {
        php_std::types::KeyRef::Int(i) => i.to_string(),
        php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}
