//! [`Query`]: one filter, order, page, selection, aggregate, join or union,
//! as data; parsing it from JSON and grouping a list of them.

use std::cmp::Ordering;

use md5::{Digest, Md5};
use php_std::ArrayKey;
use php_std::json::{self, Flags};

use crate::enums::{CursorDirection, NullsPosition};
use crate::error::{Error, Result};
use crate::method::Method;
use crate::value::{self, Array, IntoValue, IntoValues, Object, Param, Value};

/// One query: a [`Method`], the attribute it applies to, its values, and the
/// alias a join or an aggregate is exposed under.
///
/// Values are PHP values ([`Value`]); nested queries (the children of `and`,
/// `or`, `having`, the conditions of a join) are values too.
///
/// PHP: `Utopia\Query\Query`.
///
/// ```
/// use utopia_query::{Method, Query};
///
/// let query = Query::equal("status", ["active", "pending"]);
/// assert_eq!(query.method(), Method::Equal);
/// assert_eq!(query.to_string()?, r#"{"method":"equal","attribute":"status","values":["active","pending"]}"#);
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Query;
///
/// $query = Query::equal('status', ['active', 'pending']);
/// echo $query->getMethod()->value, "\n"; // equal
/// echo $query->toString(), "\n";         // {"method":"equal","attribute":"status","values":["active","pending"]}
/// ```
#[derive(Debug, Clone, PartialEq)]
pub struct Query {
    method: Method,
    attribute: String,
    attribute_type: String,
    on_array: bool,
    values: Array,
    alias: String,
}

/// The queries grouped by what they do, as builders compile them.
///
/// PHP: `Utopia\Query\Builder\ParsedQuery`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct ParsedQuery {
    /// Filters (everything that is not grouped elsewhere).
    pub filters: Vec<Query>,
    /// `select` queries.
    pub selections: Vec<Query>,
    /// Aggregates.
    pub aggregations: Vec<Query>,
    /// `groupBy` columns, as the queries hold them (PHP does not check
    /// their type until it compiles them).
    pub group_by: Vec<Value>,
    /// `having` queries.
    pub having: Vec<Query>,
    /// Whether a `distinct` query is present.
    pub distinct: bool,
    /// Joins.
    pub joins: Vec<Query>,
    /// `union` / `unionAll` queries.
    pub unions: Vec<Query>,
    /// The first `limit`.
    pub limit: Option<i64>,
    /// The first `offset`.
    pub offset: Option<i64>,
    /// The first cursor's row.
    pub cursor: Option<Value>,
    /// The first cursor's direction.
    pub cursor_direction: Option<CursorDirection>,
    /// `groupByTimeBucket` queries: attribute and interval.
    pub time_buckets: Vec<TimeBucket>,
}

impl ParsedQuery {
    /// A parsed query from its parts.
    ///
    /// PHP: `new ParsedQuery(...)`.
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        filters: Vec<Query>,
        selections: Vec<Query>,
        aggregations: Vec<Query>,
        group_by: Vec<Value>,
        having: Vec<Query>,
        distinct: bool,
        joins: Vec<Query>,
        unions: Vec<Query>,
        limit: Option<i64>,
        offset: Option<i64>,
        cursor: Option<Value>,
        cursor_direction: Option<CursorDirection>,
        time_buckets: Vec<TimeBucket>,
    ) -> Self {
        ParsedQuery {
            filters,
            selections,
            aggregations,
            group_by,
            having,
            distinct,
            joins,
            unions,
            limit,
            offset,
            cursor,
            cursor_direction,
            time_buckets,
        }
    }
}

/// A `groupByTimeBucket` query: the attribute and the bucket size.
#[derive(Debug, Clone, PartialEq)]
pub struct TimeBucket {
    /// The attribute bucketed.
    pub attribute: String,
    /// The bucket size (`1m`, `5m`, `15m`, `1h`, `1d`, `1w`, `1M`), as the
    /// query holds it (`''` when absent).
    pub interval: Value,
}

/// What [`Query::fingerprint`] accepts: a query, or its JSON.
#[derive(Debug, Clone, Copy)]
pub enum QueryOrJson<'a> {
    /// A query.
    Query(&'a Query),
    /// A query as JSON, parsed (raw queries refused).
    Json(&'a str),
}

/// Something that compiles queries: a builder.
///
/// PHP: `Utopia\Query\Compiler`.
pub trait Compiler {
    /// A filter (comparisons, spatial, vector, logical, ...).
    fn compile_filter(&mut self, query: &Query) -> Result<String>;
    /// An order (`orderAsc`, `orderDesc`, `orderRandom`).
    fn compile_order(&mut self, query: &Query) -> Result<String>;
    /// A limit.
    fn compile_limit(&mut self, query: &Query) -> Result<String>;
    /// An offset.
    fn compile_offset(&mut self, query: &Query) -> Result<String>;
    /// A selection.
    fn compile_select(&mut self, query: &Query) -> Result<String>;
    /// A cursor (`cursorAfter`, `cursorBefore`).
    fn compile_cursor(&mut self, query: &Query) -> Result<String>;
    /// An aggregate.
    fn compile_aggregate(&mut self, query: &Query) -> Result<String>;
    /// A group by.
    fn compile_group_by(&mut self, query: &Query) -> Result<String>;
    /// A join.
    fn compile_join(&mut self, query: &Query) -> Result<String>;
}

/// The bucket sizes `groupByTimeBucket` accepts.
///
/// PHP: `Query::GROUP_BY_TIME_BUCKET_INTERVALS`.
pub const GROUP_BY_TIME_BUCKET_INTERVALS: [&str; 7] = ["1m", "5m", "15m", "1h", "1d", "1w", "1M"];

/// The alias a builder gives its main table when none is set.
///
/// PHP: `Query::DEFAULT_ALIAS`.
pub const DEFAULT_ALIAS: &str = "main";

fn list(values: Vec<Value>) -> Array {
    Array::from_list(values)
}

fn strings<S: Into<String>>(items: impl IntoIterator<Item = S>) -> Array {
    list(items.into_iter().map(|s| value::string(s.into())).collect())
}

fn queries(items: impl IntoIterator<Item = Query>) -> Array {
    list(items.into_iter().map(Value::from).collect())
}

/// `gettype()`.
fn gettype(value: &Value) -> &'static str {
    value.type_name()
}

impl Query {
    /// A query of `method` on `attribute` with `values` and `alias`.
    ///
    /// PHP: `new Query($method, $attribute, $values, $alias)`.
    pub fn new(method: Method, attribute: impl Into<String>, values: Array, alias: impl Into<String>) -> Self {
        Query {
            method,
            attribute: attribute.into(),
            attribute_type: String::new(),
            on_array: false,
            values,
            alias: alias.into(),
        }
    }

    fn of(method: Method, attribute: impl Into<String>, values: Vec<Value>) -> Self {
        Query::new(method, attribute, list(values), "")
    }

    /// What the query does.
    ///
    /// PHP: `getMethod()`.
    pub fn method(&self) -> Method {
        self.method
    }

    /// The attribute (for a join: the joined collection; for `raw`: the SQL).
    ///
    /// PHP: `getAttribute()`.
    pub fn attribute(&self) -> &str {
        &self.attribute
    }

    /// The values.
    ///
    /// PHP: `getValues()`.
    pub fn values(&self) -> &Array {
        &self.values
    }

    /// The first value (`values[0]`), if any.
    ///
    /// PHP: `getValue($default)` is `value().unwrap_or(default)`.
    pub fn value(&self) -> Option<&Value> {
        self.values.get(&ArrayKey::Int(0)).filter(|v| !matches!(v, Value::Null))
    }

    /// The name a join or an aggregate is exposed under, or `""`.
    ///
    /// PHP: `getAlias()`.
    pub fn alias(&self) -> &str {
        &self.alias
    }

    /// Whether the attribute holds arrays (set by the database layer).
    ///
    /// PHP: `onArray()`.
    pub fn on_array(&self) -> bool {
        self.on_array
    }

    /// The attribute's type (set by the database layer), or `""`.
    ///
    /// PHP: `getAttributeType()`.
    pub fn attribute_type(&self) -> &str {
        &self.attribute_type
    }

    /// Sets the method.
    ///
    /// PHP: `setMethod()`.
    pub fn set_method(&mut self, method: Method) -> &mut Self {
        self.method = method;
        self
    }

    /// Sets the attribute.
    ///
    /// PHP: `setAttribute()`.
    pub fn set_attribute(&mut self, attribute: impl Into<String>) -> &mut Self {
        self.attribute = attribute.into();
        self
    }

    /// Replaces the values.
    ///
    /// PHP: `setValues()`.
    pub fn set_values(&mut self, values: Array) -> &mut Self {
        self.values = values;
        self
    }

    /// Replaces the values with one value.
    ///
    /// PHP: `setValue()`.
    pub fn set_value(&mut self, value: Value) -> &mut Self {
        self.values = list(vec![value]);
        self
    }

    /// Sets whether the attribute holds arrays.
    ///
    /// PHP: `setOnArray()`.
    pub fn set_on_array(&mut self, on_array: bool) {
        self.on_array = on_array;
    }

    /// Sets the attribute's type.
    ///
    /// PHP: `setAttributeType()`.
    pub fn set_attribute_type(&mut self, attribute_type: impl Into<String>) {
        self.attribute_type = attribute_type.into();
    }

    /// Whether this is a join whose values hold ON conditions.
    ///
    /// PHP: `isNestedJoin()`.
    pub fn is_nested_join(&self) -> bool {
        self.method.is_join() && self.values.values().any(|v| matches!(v, Value::Ext(Object::Query(_))))
    }

    /// The ON conditions of a join (empty for other queries).
    ///
    /// PHP: `getJoinOnQueries()`.
    pub fn join_on_queries(&self) -> Vec<&Query> {
        if !self.is_nested_join() {
            return Vec::new();
        }
        self.nested().collect()
    }

    /// The queries among the values, in order.
    pub fn nested(&self) -> impl Iterator<Item = &Query> {
        self.values.values().filter_map(|v| match v {
            Value::Ext(Object::Query(q)) => Some(&**q),
            _ => None,
        })
    }

    /// Whether `value` is the name of a method.
    ///
    /// PHP: `Query::isMethod()`.
    pub fn is_method(value: &str) -> bool {
        Method::try_from_value(value).is_some()
    }

    /// Whether the method is spatial.
    ///
    /// PHP: `isSpatialQuery()`.
    pub fn is_spatial_query(&self) -> bool {
        self.method.is_spatial()
    }

    /// Whether the method holds other queries.
    ///
    /// PHP: `isNested()`.
    pub fn is_nested(&self) -> bool {
        self.method.is_nested()
    }

    /// Parses a query from JSON. Raw queries are refused unless `allow_raw`:
    /// they bypass bindings, so JSON from an untrusted source must not carry them.
    ///
    /// PHP: `Query::parse($query, $allowRaw)`.
    ///
    /// ```
    /// use utopia_query::{Method, Query};
    ///
    /// let query = Query::parse(r#"{"method":"greaterThan","attribute":"age","values":[18]}"#, false)?;
    /// assert_eq!(query.method(), Method::GreaterThan);
    /// assert!(Query::parse(r#"{"method":"raw","attribute":"1 = 1"}"#, false).is_err());
    /// # Ok::<(), utopia_query::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Query\Query;
    ///
    /// $query = Query::parse('{"method":"greaterThan","attribute":"age","values":[18]}');
    /// echo $query->getMethod()->value, "\n"; // greaterThan
    /// ```
    pub fn parse(query: &str, allow_raw: bool) -> Result<Query> {
        Query::parse_bytes(query.as_bytes(), allow_raw)
    }

    /// [`Query::parse`] on bytes (PHP strings need not be UTF-8).
    pub fn parse_bytes(query: &[u8], allow_raw: bool) -> Result<Query> {
        let decoded: Value = json::decode_ext(query, Some(true), json::DEFAULT_DEPTH, Flags::THROW_ON_ERROR)
            .map_err(|e| Error::query(format!("Invalid query: {}", e.message())))?;
        match decoded {
            Value::Array(array) => Query::parse_query(&array, allow_raw),
            other => Err(Error::query(format!("Invalid query. Must be an array, got {}", gettype(&other)))),
        }
    }

    /// Parses a query from its decoded array form (`method`, `attribute`,
    /// `values`, `alias`). Raw queries are refused unless `allow_raw`.
    ///
    /// PHP: `Query::parseQuery($query, $allowRaw)`.
    pub fn parse_query(query: &Array, allow_raw: bool) -> Result<Query> {
        let get = |key: &str| query.get_str(key).filter(|v| !matches!(v, Value::Null));
        let method = get("method").cloned().unwrap_or_else(|| value::string(""));
        let attribute = get("attribute").cloned().unwrap_or_else(|| value::string(""));
        let values = get("values").cloned().unwrap_or(Value::Array(Array::default()));
        let alias = get("alias").cloned().unwrap_or_else(|| value::string(""));

        let Value::Str(method) = method else {
            return Err(Error::query(format!("Invalid query method. Must be a string, got {}", gettype(&method))));
        };
        let Some(method) = method.as_str().and_then(Method::try_from_value) else {
            return Err(Error::query(format!("Invalid query method: {}", String::from_utf8_lossy(method.as_bytes()))));
        };
        let Value::Str(attribute) = attribute else {
            return Err(Error::query(format!(
                "Invalid query attribute. Must be a string, got {}",
                gettype(&attribute)
            )));
        };
        let Value::Array(mut values) = values else {
            return Err(Error::query(format!("Invalid query values. Must be an array, got {}", gettype(&values))));
        };
        let Value::Str(alias) = alias else {
            return Err(Error::query(format!("Invalid query alias. Must be a string, got {}", gettype(&alias))));
        };
        let attribute = lossy(attribute.as_bytes());
        let mut alias = lossy(alias.as_bytes());

        if method == Method::Raw && !allow_raw {
            return Err(Error::Validation(
                "Raw queries cannot be parsed from untrusted input; construct via Query::raw() in code".into(),
            ));
        }

        if method.is_join() {
            return Query::parse_join(method, attribute, &values, alias, allow_raw);
        }

        if method.is_aggregate() {
            if alias.is_empty()
                && let Some(Value::Str(legacy)) = values.get(&ArrayKey::Int(0))
            {
                alias = lossy(legacy.as_bytes());
            }
            return Ok(Query::new(method, attribute, Array::default(), alias));
        }

        if method.is_nested() {
            let mut parsed = Array::with_capacity(values.len());
            for (key, value) in values.iter() {
                let child =
                    Param::method("Utopia\\Query\\Query::parseQuery", 1, "query", "Query.php", 268).array(value)?;
                parsed.set(key.to_owned(), Value::from(Query::parse_query(child, allow_raw)?));
            }
            values = parsed;
        }

        Ok(Query::new(method, attribute, values, alias))
    }

    /// A join in its array form, accepting the 0.6 layouts as well: the alias
    /// as the first value (cross, natural and alias-first joins) and the
    /// column form `[left, operator, right, alias]`.
    fn parse_join(method: Method, collection: String, values: &Array, alias: String, allow_raw: bool) -> Result<Query> {
        let mut on = Vec::new();
        let mut names: Vec<String> = Vec::new();
        for value in values.values() {
            match value {
                Value::Array(child) => on.push(Query::parse_query(child, allow_raw)?),
                Value::Str(s) => names.push(lossy(s.as_bytes())),
                other => {
                    return Err(Error::query(format!(
                        "Invalid join value. Must be a query or a string, got {}",
                        gettype(other)
                    )));
                }
            }
        }
        let column_form = on.is_empty() && names.len() >= 3;
        if column_form {
            on.push(Query::on(&names[0], &names[2], &names[1]));
        }
        let legacy = if column_form { names.get(3) } else { names.first() }.cloned().unwrap_or_default();
        if names.len() > if column_form { 4 } else { 1 } {
            return Err(Error::query(format!("Invalid join values for {} {}", method.as_str(), collection)));
        }
        Query::create_join(method, collection, if alias.is_empty() { legacy } else { alias }, on)
    }

    /// Parses each query of `queries` (see [`Query::parse`]).
    ///
    /// PHP: `Query::parseQueries()`.
    pub fn parse_queries<S: AsRef<str>>(queries: &[S], allow_raw: bool) -> Result<Vec<Query>> {
        queries.iter().map(|q| Query::parse(q.as_ref(), allow_raw)).collect()
    }

    /// A fingerprint of the queries' shape: methods and attributes, without
    /// values, independent of order, as an MD5 hex digest. Two lists that
    /// differ only in values have the same fingerprint (useful to group slow
    /// queries).
    ///
    /// PHP: `Query::fingerprint()`.
    ///
    /// ```
    /// use utopia_query::{Query, QueryOrJson};
    ///
    /// let a = Query::equal("status", ["active"]);
    /// let b = Query::equal("status", ["archived"]);
    /// assert_eq!(Query::fingerprint(&[QueryOrJson::Query(&a)])?, Query::fingerprint(&[QueryOrJson::Query(&b)])?);
    /// # Ok::<(), utopia_query::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Query\Query;
    ///
    /// var_dump(Query::fingerprint([Query::equal('status', ['active'])]) === Query::fingerprint([Query::equal('status', ['archived'])])); // bool(true)
    /// ```
    pub fn fingerprint(queries: &[QueryOrJson<'_>]) -> Result<String> {
        let mut shapes = Vec::with_capacity(queries.len());
        for query in queries {
            shapes.push(match query {
                QueryOrJson::Query(q) => q.shape(),
                QueryOrJson::Json(json) => Query::parse(json, false)?.shape(),
            });
        }
        shapes.sort_by(|a, b| compare_strings(a, b));
        let digest = Md5::digest(shapes.join("|").as_bytes());
        Ok(digest.iter().map(|b| format!("{b:02x}")).collect())
    }

    /// The shape of this query, without values: `method:attribute`, and for
    /// `and`, `or`, `elemMatch` and joins with conditions
    /// `method:attribute(child|child)` with the children's shapes sorted.
    ///
    /// PHP: `shape()`.
    pub fn shape(&self) -> String {
        let logical = matches!(self.method, Method::And | Method::Or | Method::ElemMatch);
        if !logical && !self.is_nested_join() {
            return format!("{}:{}", self.method.as_str(), self.attribute);
        }
        let mut children: Vec<String> = self.nested().map(Query::shape).collect();
        children.sort_by(|a, b| compare_strings(a, b));
        format!("{}:{}({})", self.method.as_str(), self.attribute, children.join("|"))
    }

    /// The query as an array: `method`, `attribute` (unless empty), `alias`
    /// (unless empty) and `values`, nested queries as arrays.
    ///
    /// PHP: `toArray()`.
    pub fn to_array(&self) -> Array {
        let mut array = Array::with_capacity(4);
        array.set(ArrayKey::from("method"), value::string(self.method.as_str()));
        if !php_empty_str(&self.attribute) {
            array.set(ArrayKey::from("attribute"), value::string(&self.attribute));
        }
        if !self.alias.is_empty() {
            array.set(ArrayKey::from("alias"), value::string(&self.alias));
        }
        let values = self
            .values
            .values()
            .map(|v| match v {
                Value::Ext(Object::Query(q)) => Value::Array(q.to_array()),
                other => other.clone(),
            })
            .collect();
        array.set(ArrayKey::from("values"), Value::Array(list(values)));
        array
    }

    /// The query as JSON.
    ///
    /// PHP: `toString()`.
    #[allow(clippy::inherent_to_string_shadow_display, clippy::inherent_to_string)]
    pub fn to_string(&self) -> Result<String> {
        value::json_encode(&Value::Array(self.to_array()), Flags::THROW_ON_ERROR)
            .map_err(|e| Error::query(format!("Invalid Json: {}", e.message())))
    }

    /// Compiles this query with `compiler`, by kind.
    ///
    /// PHP: `compile(Compiler $compiler)`.
    pub fn compile(&self, compiler: &mut dyn Compiler) -> Result<String> {
        use Method::*;
        match self.method {
            OrderAsc | OrderDesc | OrderRandom => compiler.compile_order(self),
            Limit => compiler.compile_limit(self),
            Offset => compiler.compile_offset(self),
            CursorAfter | CursorBefore => compiler.compile_cursor(self),
            Select => compiler.compile_select(self),
            m if m.is_aggregate() => compiler.compile_aggregate(self),
            GroupBy | GroupByTimeBucket => compiler.compile_group_by(self),
            m if m.is_join() => compiler.compile_join(self),
            _ => compiler.compile_filter(self),
        }
    }

    /// `equal`: the attribute is one of `values`.
    ///
    /// PHP: `Query::equal()`.
    pub fn equal(attribute: impl Into<String>, values: impl IntoValues) -> Self {
        Query::new(Method::Equal, attribute, values.into_values(), "")
    }

    /// `notEqual`: the attribute is not `value`; a list means none of its
    /// items (an associative array is one value).
    ///
    /// PHP: `Query::notEqual()`.
    pub fn not_equal(attribute: impl Into<String>, value: impl IntoValue) -> Self {
        let values = match value.into_value() {
            Value::Array(a) if a.is_list() => a,
            other => list(vec![other]),
        };
        Query::new(Method::NotEqual, attribute, values, "")
    }

    /// `lessThan`.
    ///
    /// PHP: `Query::lessThan()`.
    pub fn less_than(attribute: impl Into<String>, value: impl IntoValue) -> Self {
        Query::of(Method::LessThan, attribute, vec![value.into_value()])
    }

    /// `lessThanEqual`.
    ///
    /// PHP: `Query::lessThanEqual()`.
    pub fn less_than_equal(attribute: impl Into<String>, value: impl IntoValue) -> Self {
        Query::of(Method::LessThanEqual, attribute, vec![value.into_value()])
    }

    /// `greaterThan`.
    ///
    /// PHP: `Query::greaterThan()`.
    pub fn greater_than(attribute: impl Into<String>, value: impl IntoValue) -> Self {
        Query::of(Method::GreaterThan, attribute, vec![value.into_value()])
    }

    /// `greaterThanEqual`.
    ///
    /// PHP: `Query::greaterThanEqual()`.
    pub fn greater_than_equal(attribute: impl Into<String>, value: impl IntoValue) -> Self {
        Query::of(Method::GreaterThanEqual, attribute, vec![value.into_value()])
    }

    /// `contains`: a substring of a string attribute, or an element of an
    /// array attribute.
    ///
    /// PHP: `Query::contains()` (deprecated: prefer [`Query::contains_string`]
    /// or [`Query::contains_any`]).
    pub fn contains(attribute: impl Into<String>, values: impl IntoValues) -> Self {
        Query::new(Method::Contains, attribute, values.into_values(), "")
    }

    /// `contains` on a string attribute: `LIKE '%value%'` for each value.
    ///
    /// PHP: `Query::containsString()`.
    pub fn contains_string(attribute: impl Into<String>, values: impl IntoValues) -> Self {
        Query::new(Method::Contains, attribute, values.into_values(), "")
    }

    /// `containsAny`: an array attribute holds any of the values.
    ///
    /// PHP: `Query::containsAny()`.
    pub fn contains_any(attribute: impl Into<String>, values: impl IntoValues) -> Self {
        Query::new(Method::ContainsAny, attribute, values.into_values(), "")
    }

    /// `notContains`.
    ///
    /// PHP: `Query::notContains()`.
    pub fn not_contains(attribute: impl Into<String>, values: impl IntoValues) -> Self {
        Query::new(Method::NotContains, attribute, values.into_values(), "")
    }

    /// `between` (inclusive).
    ///
    /// PHP: `Query::between()`.
    pub fn between(attribute: impl Into<String>, start: impl IntoValue, end: impl IntoValue) -> Self {
        Query::of(Method::Between, attribute, vec![start.into_value(), end.into_value()])
    }

    /// `notBetween`.
    ///
    /// PHP: `Query::notBetween()`.
    pub fn not_between(attribute: impl Into<String>, start: impl IntoValue, end: impl IntoValue) -> Self {
        Query::of(Method::NotBetween, attribute, vec![start.into_value(), end.into_value()])
    }

    /// `search`: full-text search.
    ///
    /// PHP: `Query::search()`.
    pub fn search(attribute: impl Into<String>, value: impl IntoValue) -> Self {
        Query::of(Method::Search, attribute, vec![value.into_value()])
    }

    /// `notSearch`.
    ///
    /// PHP: `Query::notSearch()`.
    pub fn not_search(attribute: impl Into<String>, value: impl IntoValue) -> Self {
        Query::of(Method::NotSearch, attribute, vec![value.into_value()])
    }

    /// `select`: the attributes to return.
    ///
    /// PHP: `Query::select()`.
    pub fn select<S: Into<String>>(attributes: impl IntoIterator<Item = S>) -> Self {
        Query::new(Method::Select, "", strings(attributes), "")
    }

    /// `select` with any values (PHP accepts an array of anything).
    pub fn select_values(values: Array) -> Self {
        Query::new(Method::Select, "", values, "")
    }

    /// `orderDesc`, with where `NULL`s sort.
    ///
    /// PHP: `Query::orderDesc()`.
    pub fn order_desc(attribute: impl Into<String>, nulls: Option<NullsPosition>) -> Self {
        Query::of(Method::OrderDesc, attribute, nulls.map(Value::from).into_iter().collect())
    }

    /// `orderAsc`, with where `NULL`s sort.
    ///
    /// PHP: `Query::orderAsc()`.
    pub fn order_asc(attribute: impl Into<String>, nulls: Option<NullsPosition>) -> Self {
        Query::of(Method::OrderAsc, attribute, nulls.map(Value::from).into_iter().collect())
    }

    /// `orderRandom`.
    ///
    /// PHP: `Query::orderRandom()`.
    pub fn order_random() -> Self {
        Query::of(Method::OrderRandom, "", Vec::new())
    }

    /// `limit`.
    ///
    /// PHP: `Query::limit()`.
    pub fn limit(value: i64) -> Self {
        Query::of(Method::Limit, "", vec![Value::Int(value)])
    }

    /// `offset`.
    ///
    /// PHP: `Query::offset()`.
    pub fn offset(value: i64) -> Self {
        Query::of(Method::Offset, "", vec![Value::Int(value)])
    }

    /// `cursorAfter`: page after a row (an array of its columns, a
    /// `stdClass`, or a [`Object::Row`]).
    ///
    /// PHP: `Query::cursorAfter()`.
    pub fn cursor_after(row: Value) -> Self {
        Query::of(Method::CursorAfter, "", vec![row])
    }

    /// `cursorBefore`: page before a row.
    ///
    /// PHP: `Query::cursorBefore()`.
    pub fn cursor_before(row: Value) -> Self {
        Query::of(Method::CursorBefore, "", vec![row])
    }

    /// `isNull`.
    ///
    /// PHP: `Query::isNull()`.
    pub fn is_null(attribute: impl Into<String>) -> Self {
        Query::of(Method::IsNull, attribute, Vec::new())
    }

    /// `isNotNull`.
    ///
    /// PHP: `Query::isNotNull()`.
    pub fn is_not_null(attribute: impl Into<String>) -> Self {
        Query::of(Method::IsNotNull, attribute, Vec::new())
    }

    /// `startsWith`.
    ///
    /// PHP: `Query::startsWith()`.
    pub fn starts_with(attribute: impl Into<String>, value: impl IntoValue) -> Self {
        Query::of(Method::StartsWith, attribute, vec![value.into_value()])
    }

    /// `notStartsWith`.
    ///
    /// PHP: `Query::notStartsWith()`.
    pub fn not_starts_with(attribute: impl Into<String>, value: impl IntoValue) -> Self {
        Query::of(Method::NotStartsWith, attribute, vec![value.into_value()])
    }

    /// `endsWith`.
    ///
    /// PHP: `Query::endsWith()`.
    pub fn ends_with(attribute: impl Into<String>, value: impl IntoValue) -> Self {
        Query::of(Method::EndsWith, attribute, vec![value.into_value()])
    }

    /// `notEndsWith`.
    ///
    /// PHP: `Query::notEndsWith()`.
    pub fn not_ends_with(attribute: impl Into<String>, value: impl IntoValue) -> Self {
        Query::of(Method::NotEndsWith, attribute, vec![value.into_value()])
    }

    /// Documents created before a date: `lessThan('$createdAt', value)`.
    ///
    /// PHP: `Query::createdBefore()`.
    pub fn created_before(value: impl IntoValue) -> Self {
        Query::less_than("$createdAt", value)
    }

    /// Documents created after a date.
    ///
    /// PHP: `Query::createdAfter()`.
    pub fn created_after(value: impl IntoValue) -> Self {
        Query::greater_than("$createdAt", value)
    }

    /// Documents updated before a date.
    ///
    /// PHP: `Query::updatedBefore()`.
    pub fn updated_before(value: impl IntoValue) -> Self {
        Query::less_than("$updatedAt", value)
    }

    /// Documents updated after a date.
    ///
    /// PHP: `Query::updatedAfter()`.
    pub fn updated_after(value: impl IntoValue) -> Self {
        Query::greater_than("$updatedAt", value)
    }

    /// Documents created between two dates.
    ///
    /// PHP: `Query::createdBetween()`.
    pub fn created_between(start: impl IntoValue, end: impl IntoValue) -> Self {
        Query::between("$createdAt", start, end)
    }

    /// Documents updated between two dates.
    ///
    /// PHP: `Query::updatedBetween()`.
    pub fn updated_between(start: impl IntoValue, end: impl IntoValue) -> Self {
        Query::between("$updatedAt", start, end)
    }

    /// `or` of queries.
    ///
    /// PHP: `Query::or()`.
    pub fn or(queries: impl IntoIterator<Item = Query>) -> Self {
        Query::new(Method::Or, "", self::queries(queries), "")
    }

    /// `and` of queries.
    ///
    /// PHP: `Query::and()`.
    pub fn and(queries: impl IntoIterator<Item = Query>) -> Self {
        Query::new(Method::And, "", self::queries(queries), "")
    }

    /// A logical query of any values (PHP accepts an array of anything).
    pub fn logical(method: Method, values: Array) -> Self {
        Query::new(method, "", values, "")
    }

    /// `containsAll`: an array attribute holds every value.
    ///
    /// PHP: `Query::containsAll()`.
    pub fn contains_all(attribute: impl Into<String>, values: impl IntoValues) -> Self {
        Query::new(Method::ContainsAll, attribute, values.into_values(), "")
    }

    /// The queries of the given methods.
    ///
    /// PHP: `Query::getByType()` (the clone flag is moot: these are references).
    pub fn by_type<'a>(queries: &'a [Query], methods: &[Method]) -> Vec<&'a Query> {
        queries.iter().filter(|q| methods.contains(&q.method)).collect()
    }

    /// The cursor queries.
    ///
    /// PHP: `Query::getCursorQueries()`.
    pub fn cursor_queries(queries: &[Query]) -> Vec<&Query> {
        Query::by_type(queries, &[Method::CursorAfter, Method::CursorBefore])
    }

    /// Groups queries by what they do (see [`ParsedQuery`]).
    ///
    /// PHP: `Query::groupByType()`.
    pub fn group_by_type(queries: &[Query]) -> ParsedQuery {
        let mut parsed = ParsedQuery::default();
        for query in queries {
            let method = query.method;
            let first = query.values.get(&ArrayKey::Int(0));
            match method {
                Method::OrderAsc | Method::OrderDesc | Method::OrderRandom => {}
                Method::Limit => {
                    if parsed.limit.is_none()
                        && let Some(v) = first.filter(|v| is_numeric(v))
                    {
                        parsed.limit = Some(intval(v));
                    }
                }
                Method::Offset => {
                    if parsed.offset.is_none()
                        && let Some(v) = first.filter(|v| is_numeric(v))
                    {
                        parsed.offset = Some(intval(v));
                    }
                }
                Method::CursorAfter | Method::CursorBefore => {
                    if parsed.cursor.is_none() {
                        parsed.cursor = first.filter(|v| !matches!(v, Value::Null)).cloned();
                        parsed.cursor_direction = Some(if method == Method::CursorAfter {
                            CursorDirection::After
                        } else {
                            CursorDirection::Before
                        });
                    }
                }
                Method::Select => parsed.selections.push(query.clone()),
                m if m.is_aggregate() => parsed.aggregations.push(query.clone()),
                Method::GroupBy => parsed.group_by.extend(query.values.values().cloned()),
                Method::GroupByTimeBucket => parsed.time_buckets.push(TimeBucket {
                    attribute: query.attribute.clone(),
                    interval: first.filter(|v| !matches!(v, Value::Null)).cloned().unwrap_or_else(|| value::string("")),
                }),
                Method::Having => parsed.having.push(query.clone()),
                Method::Distinct => parsed.distinct = true,
                m if m.is_join() => parsed.joins.push(query.clone()),
                Method::Union | Method::UnionAll => parsed.unions.push(query.clone()),
                _ => parsed.filters.push(query.clone()),
            }
        }
        parsed
    }

    /// `distanceEqual`: the distance from `values` (a point or a geometry)
    /// equals `distance` (in meters when `meters`).
    ///
    /// PHP: `Query::distanceEqual()`.
    pub fn distance_equal(attribute: impl Into<String>, values: Array, distance: impl IntoValue, meters: bool) -> Self {
        Query::distance(Method::DistanceEqual, attribute, values, distance.into_value(), meters)
    }

    /// `distanceNotEqual`.
    ///
    /// PHP: `Query::distanceNotEqual()`.
    pub fn distance_not_equal(
        attribute: impl Into<String>,
        values: Array,
        distance: impl IntoValue,
        meters: bool,
    ) -> Self {
        Query::distance(Method::DistanceNotEqual, attribute, values, distance.into_value(), meters)
    }

    /// `distanceGreaterThan`.
    ///
    /// PHP: `Query::distanceGreaterThan()`.
    pub fn distance_greater_than(
        attribute: impl Into<String>,
        values: Array,
        distance: impl IntoValue,
        meters: bool,
    ) -> Self {
        Query::distance(Method::DistanceGreaterThan, attribute, values, distance.into_value(), meters)
    }

    /// `distanceLessThan`.
    ///
    /// PHP: `Query::distanceLessThan()`.
    pub fn distance_less_than(
        attribute: impl Into<String>,
        values: Array,
        distance: impl IntoValue,
        meters: bool,
    ) -> Self {
        Query::distance(Method::DistanceLessThan, attribute, values, distance.into_value(), meters)
    }

    fn distance(method: Method, attribute: impl Into<String>, values: Array, distance: Value, meters: bool) -> Self {
        let tuple = list(vec![Value::Array(values), distance, Value::Bool(meters)]);
        Query::of(method, attribute, vec![Value::Array(tuple)])
    }

    fn geometry(method: Method, attribute: impl Into<String>, values: Array) -> Self {
        Query::of(method, attribute, vec![Value::Array(values)])
    }

    /// `intersects` (spatial).
    ///
    /// PHP: `Query::intersects()`.
    pub fn intersects(attribute: impl Into<String>, values: Array) -> Self {
        Query::geometry(Method::Intersects, attribute, values)
    }

    /// `notIntersects` (spatial).
    ///
    /// PHP: `Query::notIntersects()`.
    pub fn not_intersects(attribute: impl Into<String>, values: Array) -> Self {
        Query::geometry(Method::NotIntersects, attribute, values)
    }

    /// `crosses` (spatial).
    ///
    /// PHP: `Query::crosses()`.
    pub fn crosses(attribute: impl Into<String>, values: Array) -> Self {
        Query::geometry(Method::Crosses, attribute, values)
    }

    /// `notCrosses` (spatial).
    ///
    /// PHP: `Query::notCrosses()`.
    pub fn not_crosses(attribute: impl Into<String>, values: Array) -> Self {
        Query::geometry(Method::NotCrosses, attribute, values)
    }

    /// `overlaps` (spatial).
    ///
    /// PHP: `Query::overlaps()`.
    pub fn overlaps(attribute: impl Into<String>, values: Array) -> Self {
        Query::geometry(Method::Overlaps, attribute, values)
    }

    /// `notOverlaps` (spatial).
    ///
    /// PHP: `Query::notOverlaps()`.
    pub fn not_overlaps(attribute: impl Into<String>, values: Array) -> Self {
        Query::geometry(Method::NotOverlaps, attribute, values)
    }

    /// `touches` (spatial).
    ///
    /// PHP: `Query::touches()`.
    pub fn touches(attribute: impl Into<String>, values: Array) -> Self {
        Query::geometry(Method::Touches, attribute, values)
    }

    /// `notTouches` (spatial).
    ///
    /// PHP: `Query::notTouches()`.
    pub fn not_touches(attribute: impl Into<String>, values: Array) -> Self {
        Query::geometry(Method::NotTouches, attribute, values)
    }

    /// `vectorDot`: similarity to `vector` by dot product.
    ///
    /// PHP: `Query::vectorDot()`.
    pub fn vector_dot(attribute: impl Into<String>, vector: Array) -> Self {
        Query::geometry(Method::VectorDot, attribute, vector)
    }

    /// `vectorCosine`.
    ///
    /// PHP: `Query::vectorCosine()`.
    pub fn vector_cosine(attribute: impl Into<String>, vector: Array) -> Self {
        Query::geometry(Method::VectorCosine, attribute, vector)
    }

    /// `vectorEuclidean`.
    ///
    /// PHP: `Query::vectorEuclidean()`.
    pub fn vector_euclidean(attribute: impl Into<String>, vector: Array) -> Self {
        Query::geometry(Method::VectorEuclidean, attribute, vector)
    }

    /// `regex`.
    ///
    /// PHP: `Query::regex()`.
    pub fn regex(attribute: impl Into<String>, pattern: impl IntoValue) -> Self {
        Query::of(Method::Regex, attribute, vec![pattern.into_value()])
    }

    /// `exists`: the attributes are present.
    ///
    /// PHP: `Query::exists()`.
    pub fn exists(attributes: Array) -> Self {
        Query::new(Method::Exists, "", attributes, "")
    }

    /// `notExists`: the attribute (or each of a list) is absent.
    ///
    /// PHP: `Query::notExists()`.
    pub fn not_exists(attribute: impl IntoValue) -> Self {
        let values = match attribute.into_value() {
            Value::Array(a) => a,
            other => list(vec![other]),
        };
        Query::new(Method::NotExists, "", values, "")
    }

    /// `elemMatch`: an element of the array attribute matches every query.
    ///
    /// PHP: `Query::elemMatch()`.
    pub fn elem_match(attribute: impl Into<String>, queries: impl IntoIterator<Item = Query>) -> Self {
        Query::new(Method::ElemMatch, attribute, self::queries(queries), "")
    }

    fn aggregate(method: Method, attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::new(method, attribute, Array::default(), alias)
    }

    /// `count` (`*` counts rows).
    ///
    /// PHP: `Query::count()`.
    pub fn count(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::Count, attribute, alias)
    }

    /// `countDistinct`.
    ///
    /// PHP: `Query::countDistinct()`.
    pub fn count_distinct(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::CountDistinct, attribute, alias)
    }

    /// `sum`.
    ///
    /// PHP: `Query::sum()`.
    pub fn sum(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::Sum, attribute, alias)
    }

    /// `avg`.
    ///
    /// PHP: `Query::avg()`.
    pub fn avg(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::Avg, attribute, alias)
    }

    /// `min`.
    ///
    /// PHP: `Query::min()`.
    pub fn min(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::Min, attribute, alias)
    }

    /// `max`.
    ///
    /// PHP: `Query::max()`.
    pub fn max(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::Max, attribute, alias)
    }

    /// `stddev`.
    ///
    /// PHP: `Query::stddev()`.
    pub fn stddev(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::Stddev, attribute, alias)
    }

    /// `stddevPop`.
    ///
    /// PHP: `Query::stddevPop()`.
    pub fn stddev_pop(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::StddevPop, attribute, alias)
    }

    /// `stddevSamp`.
    ///
    /// PHP: `Query::stddevSamp()`.
    pub fn stddev_samp(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::StddevSamp, attribute, alias)
    }

    /// `variance`.
    ///
    /// PHP: `Query::variance()`.
    pub fn variance(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::Variance, attribute, alias)
    }

    /// `varPop`.
    ///
    /// PHP: `Query::varPop()`.
    pub fn var_pop(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::VarPop, attribute, alias)
    }

    /// `varSamp`.
    ///
    /// PHP: `Query::varSamp()`.
    pub fn var_samp(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::VarSamp, attribute, alias)
    }

    /// `bitAnd`.
    ///
    /// PHP: `Query::bitAnd()`.
    pub fn bit_and(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::BitAnd, attribute, alias)
    }

    /// `bitOr`.
    ///
    /// PHP: `Query::bitOr()`.
    pub fn bit_or(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::BitOr, attribute, alias)
    }

    /// `bitXor`.
    ///
    /// PHP: `Query::bitXor()`.
    pub fn bit_xor(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        Query::aggregate(Method::BitXor, attribute, alias)
    }

    /// `groupBy`.
    ///
    /// PHP: `Query::groupBy()`.
    pub fn group_by<S: Into<String>>(attributes: impl IntoIterator<Item = S>) -> Self {
        Query::new(Method::GroupBy, "", strings(attributes), "")
    }

    /// `groupByTimeBucket`: groups by `attribute` cut into windows of
    /// `interval` (one of [`GROUP_BY_TIME_BUCKET_INTERVALS`]).
    ///
    /// PHP: `Query::groupByTimeBucket()`.
    pub fn group_by_time_bucket(attribute: impl Into<String>, interval: &str) -> Result<Self> {
        if !GROUP_BY_TIME_BUCKET_INTERVALS.contains(&interval) {
            return Err(Error::Validation(format!(
                "Invalid groupByTimeBucket interval: {interval}. Allowed: {}",
                GROUP_BY_TIME_BUCKET_INTERVALS.join(", ")
            )));
        }
        Ok(Query::of(Method::GroupByTimeBucket, attribute, vec![value::string(interval)]))
    }

    /// `having`: conditions on aggregates.
    ///
    /// PHP: `Query::having()`.
    pub fn having(queries: impl IntoIterator<Item = Query>) -> Self {
        Query::new(Method::Having, "", self::queries(queries), "")
    }

    /// `distinct`.
    ///
    /// PHP: `Query::distinct()`.
    pub fn distinct() -> Self {
        Query::of(Method::Distinct, "", Vec::new())
    }

    /// `on`: a column-to-column join condition (`operator` defaults to `=` in PHP).
    ///
    /// PHP: `Query::on()`.
    pub fn on(left: impl Into<String>, right: impl Into<String>, operator: impl Into<String>) -> Self {
        Query::new(
            Method::On,
            "",
            list(vec![value::string(left.into()), value::string(operator.into()), value::string(right.into())]),
            "",
        )
    }

    /// An inner join of `collection` as `alias` on conditions (`on` queries and filters).
    ///
    /// PHP: `Query::join()`.
    pub fn join(collection: impl Into<String>, alias: impl Into<String>, on: Vec<Query>) -> Result<Self> {
        Query::create_join(Method::Join, collection.into(), alias.into(), on)
    }

    /// A left join.
    ///
    /// PHP: `Query::leftJoin()`.
    pub fn left_join(collection: impl Into<String>, alias: impl Into<String>, on: Vec<Query>) -> Result<Self> {
        Query::create_join(Method::LeftJoin, collection.into(), alias.into(), on)
    }

    /// A right join.
    ///
    /// PHP: `Query::rightJoin()`.
    pub fn right_join(collection: impl Into<String>, alias: impl Into<String>, on: Vec<Query>) -> Result<Self> {
        Query::create_join(Method::RightJoin, collection.into(), alias.into(), on)
    }

    /// A full outer join.
    ///
    /// PHP: `Query::fullOuterJoin()`.
    pub fn full_outer_join(collection: impl Into<String>, alias: impl Into<String>, on: Vec<Query>) -> Result<Self> {
        Query::create_join(Method::FullOuterJoin, collection.into(), alias.into(), on)
    }

    /// A cross join (no conditions).
    ///
    /// PHP: `Query::crossJoin()`.
    pub fn cross_join(collection: impl Into<String>, alias: impl Into<String>) -> Result<Self> {
        Query::create_join(Method::CrossJoin, collection.into(), alias.into(), Vec::new())
    }

    /// A natural join (no conditions).
    ///
    /// PHP: `Query::naturalJoin()`.
    pub fn natural_join(collection: impl Into<String>, alias: impl Into<String>) -> Result<Self> {
        Query::create_join(Method::NaturalJoin, collection.into(), alias.into(), Vec::new())
    }

    /// A join of any conditions (values that must all be queries, as PHP checks).
    pub fn join_values(
        method: Method,
        collection: impl Into<String>,
        alias: impl Into<String>,
        on: Array,
    ) -> Result<Self> {
        let mut conditions = Vec::with_capacity(on.len());
        let collection = collection.into();
        let alias = alias.into();
        Query::check_join_alias(method, &collection, &alias, on.is_empty())?;
        for value in on.values() {
            match value {
                Value::Ext(Object::Query(q)) => conditions.push((**q).clone()),
                _ => return Err(Error::Validation("Join ON conditions must be Query objects".into())),
            }
        }
        Query::create_join(method, collection, alias, conditions)
    }

    fn check_join_alias(method: Method, collection: &str, alias: &str, empty: bool) -> Result<()> {
        if alias.is_empty() {
            return Err(Error::Validation(format!("Join alias is required: {} {}", method.as_str(), collection)));
        }
        let unconditioned = method == Method::CrossJoin || method == Method::NaturalJoin;
        if unconditioned && !empty {
            return Err(Error::Validation(format!("Join ON conditions are not allowed in {}", method.as_str())));
        }
        if !unconditioned && empty {
            return Err(Error::Validation("Join ON requires at least one condition".into()));
        }
        Ok(())
    }

    fn create_join(method: Method, collection: String, alias: String, on: Vec<Query>) -> Result<Self> {
        Query::check_join_alias(method, &collection, &alias, on.is_empty())?;
        for condition in &on {
            Query::assert_join_condition(condition)?;
        }
        Ok(Query::new(method, collection, self::queries(on), alias))
    }

    fn assert_join_condition(condition: &Query) -> Result<()> {
        let method = condition.method;
        if !method.is_join_condition() {
            return Err(Error::Validation(format!("Unsupported join ON condition: {}", method.as_str())));
        }
        if method != Method::And && method != Method::Or {
            return Ok(());
        }
        for child in condition.values.values() {
            match child {
                Value::Ext(Object::Query(q)) => Query::assert_join_condition(q)?,
                _ => return Err(Error::Validation("Join ON conditions must be Query objects".into())),
            }
        }
        Ok(())
    }

    /// `union` of queries.
    ///
    /// PHP: `Query::union()`.
    pub fn union(queries: impl IntoIterator<Item = Query>) -> Self {
        Query::new(Method::Union, "", self::queries(queries), "")
    }

    /// `unionAll` of queries.
    ///
    /// PHP: `Query::unionAll()`.
    pub fn union_all(queries: impl IntoIterator<Item = Query>) -> Self {
        Query::new(Method::UnionAll, "", self::queries(queries), "")
    }

    /// `jsonContains`: a JSON attribute contains `value`.
    ///
    /// PHP: `Query::jsonContains()`.
    pub fn json_contains(attribute: impl Into<String>, value: impl IntoValue) -> Self {
        Query::of(Method::JsonContains, attribute, vec![value.into_value()])
    }

    /// `jsonNotContains`.
    ///
    /// PHP: `Query::jsonNotContains()`.
    pub fn json_not_contains(attribute: impl Into<String>, value: impl IntoValue) -> Self {
        Query::of(Method::JsonNotContains, attribute, vec![value.into_value()])
    }

    /// `jsonOverlaps`: a JSON array attribute shares an element with `values`.
    ///
    /// PHP: `Query::jsonOverlaps()`.
    pub fn json_overlaps(attribute: impl Into<String>, values: Array) -> Self {
        Query::of(Method::JsonOverlaps, attribute, vec![Value::Array(values)])
    }

    /// `jsonPath`: the value at `path` compares to `value` with `operator`.
    ///
    /// PHP: `Query::jsonPath()`.
    pub fn json_path(
        attribute: impl Into<String>,
        path: impl Into<String>,
        operator: impl Into<String>,
        value: impl IntoValue,
    ) -> Self {
        Query::of(
            Method::JsonPath,
            attribute,
            vec![value::string(path.into()), value::string(operator.into()), value.into_value()],
        )
    }

    /// `covers` (spatial).
    ///
    /// PHP: `Query::covers()`.
    pub fn covers(attribute: impl Into<String>, values: Array) -> Self {
        Query::geometry(Method::Covers, attribute, values)
    }

    /// `notCovers` (spatial).
    ///
    /// PHP: `Query::notCovers()`.
    pub fn not_covers(attribute: impl Into<String>, values: Array) -> Self {
        Query::geometry(Method::NotCovers, attribute, values)
    }

    /// `spatialEquals` (spatial).
    ///
    /// PHP: `Query::spatialEquals()`.
    pub fn spatial_equals(attribute: impl Into<String>, values: Array) -> Self {
        Query::geometry(Method::SpatialEquals, attribute, values)
    }

    /// `notSpatialEquals` (spatial).
    ///
    /// PHP: `Query::notSpatialEquals()`.
    pub fn not_spatial_equals(attribute: impl Into<String>, values: Array) -> Self {
        Query::geometry(Method::NotSpatialEquals, attribute, values)
    }

    /// `raw`: SQL used as given, with its bindings.
    ///
    /// PHP: `Query::raw()`.
    pub fn raw(sql: impl Into<String>, bindings: Array) -> Self {
        Query::new(Method::Raw, sql, bindings, "")
    }

    /// The `limit` and `offset` of page `page` (from 1) of `per_page` rows.
    ///
    /// PHP: `Query::page()`.
    pub fn page(page: i64, per_page: i64) -> Result<[Query; 2]> {
        if page < 1 {
            return Err(Error::Validation(format!("Page must be >= 1, got {page}")));
        }
        if per_page < 1 {
            return Err(Error::Validation(format!("Per page must be >= 1, got {per_page}")));
        }
        Ok([Query::limit(per_page), Query::offset((page - 1).wrapping_mul(per_page))])
    }

    /// `a` followed by `b`, where a `limit`, `offset` or cursor in `b`
    /// replaces any of the same method in `a`.
    ///
    /// PHP: `Query::merge()`.
    pub fn merge(a: &[Query], b: &[Query]) -> Vec<Query> {
        let singular = [Method::Limit, Method::Offset, Method::CursorAfter, Method::CursorBefore];
        let mut result = a.to_vec();
        for query in b {
            if singular.contains(&query.method) {
                result.retain(|q| q.method != query.method);
            }
            result.push(query.clone());
        }
        result
    }

    /// The queries of `a` not in `b` (compared by their array form).
    ///
    /// PHP: `Query::diff()`.
    pub fn diff(a: &[Query], b: &[Query]) -> Vec<Query> {
        let b: Vec<Value> = b.iter().map(|q| Value::Array(q.to_array())).collect();
        a.iter()
            .filter(|q| {
                let array = Value::Array(q.to_array());
                !b.iter().any(|other| value::identical(&array, other))
            })
            .cloned()
            .collect()
    }

    /// Error messages for attributes outside `allowed` (nested queries
    /// included; pagination, selections and existence checks skipped). Fails
    /// as PHP does when a `groupBy` column is an object it cannot print.
    ///
    /// PHP: `Query::validate()`.
    pub fn validate<S: AsRef<str>>(queries: &[Query], allowed: &[S]) -> Result<Vec<String>> {
        let allowed: Vec<&str> = allowed.iter().map(AsRef::as_ref).collect();
        let mut errors = Vec::new();
        Query::validate_into(queries.iter(), &allowed, &mut errors)?;
        Ok(errors)
    }

    fn validate_into<'a>(
        queries: impl Iterator<Item = &'a Query>,
        allowed: &[&str],
        errors: &mut Vec<String>,
    ) -> Result<()> {
        use Method::*;
        for query in queries {
            let method = query.method;
            if method.is_nested() {
                Query::validate_into(query.nested(), allowed, errors)?;
                continue;
            }
            if matches!(
                method,
                Limit | Offset | CursorAfter | CursorBefore | OrderRandom | Distinct | Select | Exists | NotExists
            ) {
                continue;
            }
            if method == GroupBy {
                for column in query.values.values() {
                    let is_allowed =
                        matches!(column, Value::Str(s) if s.as_str().is_some_and(|s| allowed.contains(&s)));
                    if !is_allowed {
                        errors.push(format!(
                            "Invalid attribute \"{}\" used in {}",
                            value::concat(column)?,
                            method.as_str()
                        ));
                    }
                }
                continue;
            }
            let attribute = query.attribute.as_str();
            if attribute.is_empty() || attribute == "*" {
                continue;
            }
            if !allowed.contains(&attribute) {
                errors.push(format!("Invalid attribute \"{attribute}\" used in {}", method.as_str()));
            }
        }
        Ok(())
    }
}

impl From<NullsPosition> for Object {
    /// The enum case as an object value.
    fn from(nulls: NullsPosition) -> Self {
        Object::Nulls(nulls)
    }
}

/// PHP `empty($string)`: `""` and `"0"`.
fn php_empty_str(s: &str) -> bool {
    s.is_empty() || s == "0"
}

/// `is_numeric($value)`.
pub(crate) fn is_numeric(value: &Value) -> bool {
    match value {
        Value::Int(_) | Value::Float(_) => true,
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    }
}

/// `intval($value)`.
pub(crate) fn intval(value: &Value) -> i64 {
    match value {
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::dval_to_lval(*f),
        Value::Str(s) => php_std::format::Arg::Str(s.as_bytes()).to_int(),
        Value::Bool(b) => *b as i64,
        Value::Null => 0,
        Value::Array(a) => !a.is_empty() as i64,
        Value::Object(_) | Value::Ext(_) => 1,
    }
}

/// PHP's `sort()` order for strings (`zend_compare` on two strings: numeric
/// strings compare as numbers).
pub(crate) fn compare_strings(a: &str, b: &str) -> Ordering {
    value::string(a).cmp_php(&value::string(b))
}

fn lossy(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}
