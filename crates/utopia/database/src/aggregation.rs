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
use crate::error::{Error, Result};
use crate::filter::Filter;
use crate::query::Query;
use crate::storage;
use crate::validator::big_int::BigInt;
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
                let value = aggregate(*method, lookup(&self.operands, &column), value)?;
                shaped.set(ArrayKey::Str(Str::from(column.as_str())), value);
                continue;
            }

            let name = lookup(&names, &column).cloned().unwrap_or(column);
            let Some(attribute) = lookup(&self.attributes, &name) else {
                shaped.set(ArrayKey::Str(Str::from(name.as_str())), value);
                continue;
            };

            let value = cast(attribute, value)?;
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
fn aggregate(method: Method, operand: Option<&Attribute>, value: Value) -> Result<Value> {
    if matches!(value, Value::Null) {
        return Ok(Value::Null);
    }
    if COUNTS.contains(&method) {
        return integer(&value);
    }
    if STATISTICS.contains(&method) {
        return Ok(Value::Float(float(&value)));
    }
    if method == Method::Sum {
        return match operand {
            Some(operand) if INTEGERS.contains(&operand.kind) => integer(&value),
            _ => Ok(Value::Float(float(&value))),
        };
    }
    match operand {
        None => Ok(value),
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
fn cast(attribute: &Attribute, value: Value) -> Result<Value> {
    if matches!(value, Value::Null) {
        return Ok(Value::Null);
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
            let mut scalars = Array::with_capacity(items.len());
            for (k, v) in items {
                scalars.set(k, scalar(attribute.kind, v)?);
            }
            Ok(Value::Array(scalars))
        }
        other => scalar(attribute.kind, other),
    }
}

/// One value as its type reads it (`scalar()`).
fn scalar(kind: ColumnType, value: Value) -> Result<Value> {
    if matches!(value, Value::Null | Value::Array(_)) {
        return Ok(value);
    }
    if kind == ColumnType::Boolean {
        return Ok(match value {
            Value::Bool(_) => value,
            other => Value::Bool(matches!(
                filter_var(&other, php_std::filter::FILTER_VALIDATE_BOOL),
                Some(php_std::filter::Value::Bool(true))
            )),
        });
    }
    if kind == ColumnType::Id {
        return Ok(match value {
            Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_) => {
                value.to_php_string().map(Value::Str).unwrap_or(value)
            }
            other => other,
        });
    }
    // An unsigned big integer past PHP's integers keeps its digits, as a
    // read returns it (8.3.4).
    if kind == ColumnType::BigInteger
        && let Value::Str(text) = &value
        && BigInt::is_integer_string(text.as_bytes(), true)
        && !BigInt::fits_php_int(text.as_bytes(), true)
    {
        return Ok(value);
    }
    if INTEGERS.contains(&kind) {
        return integer(&value);
    }
    if FLOATS.contains(&kind) {
        return Ok(Value::Float(float(&value)));
    }
    if DATETIMES.contains(&kind)
        && let Value::Str(text) = &value
    {
        let text = String::from_utf8_lossy(text.as_bytes()).into_owned();
        return Ok(crate::datetime::format_tz(Some(&text)).map(|t| Value::Str(Str::from(t.as_str()))).unwrap_or(value));
    }
    Ok(value)
}

/// `-INTEGER_LIMIT ..= INTEGER_LIMIT` bounds the doubles that are 64-bit
/// integers (`2^63`).
const INTEGER_LIMIT: f64 = 9223372036854775808.0;

/// An integer aggregate, or a group or bound of an integer attribute, as the
/// integer it is (`integer()`). MariaDB sums integers into `DECIMAL`, so a
/// whole value can arrive as `"7.0000"`; it and MySQL and PostgreSQL sum
/// integers exactly past the range of a 64-bit integer, and a whole value
/// that does not fit one is refused (`Limit`) rather than answered
/// inexactly (8.3.4).
fn integer(value: &Value) -> Result<Value> {
    if let Value::Int(_) = value {
        return Ok(value.clone());
    }
    if let Value::Str(text) = value
        && let Some(whole) = BigInt::whole(text.as_bytes())
    {
        if !BigInt::fits_php_int(whole, true) {
            return Err(Error::limit("Value out of range"));
        }
        return Ok(Value::Int(php_std::format::str_to_int(whole)));
    }
    let float = float(value);
    if float.floor() != float {
        return Ok(Value::Float(float));
    }
    if !(-INTEGER_LIMIT..INTEGER_LIMIT).contains(&float) {
        return Err(Error::limit("Value out of range"));
    }
    Ok(Value::Int(float as i64))
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

#[cfg(test)]
mod tests {
    use super::*;
    use crate::error::Kind;

    fn text(s: &str) -> Value {
        Value::Str(Str::from(s))
    }

    fn refused(result: Result<Value>) -> bool {
        matches!(result, Err(e) if e.kind() == Kind::Limit && e.message() == "Value out of range")
    }

    /// PHP 8.3.6 Aggregation.php:378-404: an integer aggregate is read
    /// exactly from the engine's digits, and one past a 64-bit integer is
    /// refused rather than answered as an inexact float.
    #[test]
    fn an_integer_is_read_exactly_and_refused_past_int64() {
        assert_eq!(integer(&text("7.0000")).unwrap(), Value::Int(7));
        assert_eq!(integer(&text("-9223372036854775808")).unwrap(), Value::Int(i64::MIN));
        assert_eq!(integer(&text("7.5")).unwrap(), Value::Float(7.5));
        assert_eq!(integer(&Value::Float(1e3)).unwrap(), Value::Int(1000));
        assert!(refused(integer(&text("18446744073709551614.0000"))));
        assert!(refused(integer(&text("9223372036854775808"))));
        assert!(refused(integer(&Value::Float(9223372036854775808.0))));
        assert!(refused(integer(&Value::Float(f64::INFINITY))));
    }

    /// Aggregation.php:363: a bound of an unsigned big integer past PHP's
    /// integers keeps its digits, as a read returns it.
    #[test]
    fn an_unsigned_big_integer_past_php_integers_keeps_its_digits() {
        assert_eq!(scalar(ColumnType::BigInteger, text("18446744073709551615")).unwrap(), text("18446744073709551615"));
        assert_eq!(scalar(ColumnType::BigInteger, text("42")).unwrap(), Value::Int(42));
        assert!(refused(scalar(ColumnType::Integer, text("18446744073709551615"))));
    }

    /// Validator/BigInt.php:74-86 (`BigInt::whole()`).
    #[test]
    fn a_whole_number_is_its_integer_digits() {
        assert_eq!(BigInt::whole(b"7.0000"), Some(&b"7"[..]));
        assert_eq!(BigInt::whole(b"-12"), Some(&b"-12"[..]));
        assert_eq!(BigInt::whole(b"7."), Some(&b"7"[..]));
        assert_eq!(BigInt::whole(b"7.05"), None);
        assert_eq!(BigInt::whole(b"1e3"), None);
        assert_eq!(BigInt::whole(b"."), None);
    }
}
