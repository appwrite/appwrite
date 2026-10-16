//! [`Query`]: a filter, order, page, selection, aggregate or join of a
//! database read, and [`ParsedQuery`], queries grouped by kind.
//!
//! A database query is a `utopia_query::Query` ([`Query`] derefs to one), as
//! PHP's `Utopia\Database\Query` extends `Utopia\Query\Query`: the builders
//! of `utopia-query` compile it as it is ([`Query::into_inner`] moves it out,
//! nothing is copied). What the database adds is here: the default order
//! attribute (`$sequence`), document cursors, parsing that reports
//! [`Kind::Query`](crate::Kind::Query) and accepts nested queries given as
//! JSON strings, the shape and array forms of its own, and grouping that
//! keeps the orders ([`ParsedQuery`]).
//!
//! Its values are query values ([`utopia_query::Value`]); see
//! [`crate::value`] for how they meet document values.
//!
//! PHP: `Utopia\Database\Query`, `Utopia\Database\ParsedQuery`.

use std::ops::{Deref, DerefMut};

use php_std::json::{self, Flags};
use utopia_query::{
    Array as QArray, CursorDirection, IntoValue, IntoValues, Method, NullsPosition, Object, OrderDirection,
    QueryOrJson, Value as QValue,
};

use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::value::{self as dbvalue};

/// The alias of the queried collection in generated SQL.
///
/// PHP: `Query::DEFAULT_ALIAS`.
pub const DEFAULT_ALIAS: &str = "table_main";

/// The methods that compose child queries (`and`, `or`, `elemMatch`).
///
/// PHP: `Query::LOGICAL_TYPES`.
pub const LOGICAL_TYPES: [Method; 3] = [Method::And, Method::Or, Method::ElemMatch];

/// The most nodes [`Query::shape`] walks.
///
/// PHP: `Query::SHAPE_MAX_NODES`.
pub const SHAPE_MAX_NODES: usize = 10_000;

/// A database query.
///
/// ```
/// use utopia_database::Query;
/// use utopia_query::Method;
///
/// let query = Query::equal("status", ["active"]);
/// assert_eq!(query.method(), Method::Equal);
/// assert_eq!(Query::order_asc("", None).attribute(), "$sequence");
/// let parsed = Query::parse(r#"{"method":"or","values":["{\"method\":\"isNull\",\"attribute\":\"a\"}"]}"#, false).unwrap();
/// assert_eq!(parsed.shape().unwrap(), "or:(isNull:a)");
/// ```
///
/// PHP: `Utopia\Database\Query`; its methods inherited from
/// `Utopia\Query\Query` are reached through [`Deref`].
#[derive(Debug, Clone, PartialEq)]
pub struct Query(utopia_query::Query);

/// [`query_error`] for a statement PHP's adapter builds without catching the
/// query library's exceptions: a `ValidationException` keeps its class
/// ([`Kind::QueryValidation`]) instead of becoming a [`Kind::Query`] error,
/// which PHP raises only around a select (`Adapter/SQL.php`
/// `executeSelect()` and `compileFilters()`).
pub(crate) fn statement_error(e: utopia_query::Error) -> Error {
    match &e {
        utopia_query::Error::Validation(message) if e.previous().is_none() => {
            Error::new(Kind::QueryValidation, message.clone()).with_code(e.code())
        }
        _ => query_error(e),
    }
}

/// A `utopia-query` error as the database reports it: the library's
/// exceptions (`Utopia\Query\Exception` and its subclasses) as
/// [`Kind::Query`] with the same message and code, the engine's errors as
/// theirs. A database error a hook raised inside a builder (carried as the
/// previous error of a `Utopia\Query\Exception`, as the SQL hooks do)
/// comes back unchanged.
pub(crate) fn query_error(e: utopia_query::Error) -> Error {
    use utopia_query::Error as Q;
    if let Some(error) = e.previous().and_then(|previous| previous.downcast_ref::<Error>()) {
        return error.clone();
    }
    let message = String::from_utf8_lossy(&e.message_bytes()).into_owned();
    let code = e.code();
    let kind = match &e {
        Q::Query { .. } | Q::Validation(_) | Q::Unsupported(_) => Kind::Query,
        Q::InvalidArgument(_) => Kind::InvalidArgument,
        Q::InvalidValue(_) => Kind::ValueError,
        Q::Type(_) => Kind::TypeError,
        Q::NoExecutor(_) => Kind::BadMethodCall,
        Q::Engine(_) => Kind::Error,
        Q::Json { .. } => Kind::Json,
    };
    Error::new(kind, message).with_code(code)
}

/// `$sequence` for an order query without an attribute (the database's
/// constructor).
fn with_default_order(mut query: utopia_query::Query) -> utopia_query::Query {
    if query.attribute().is_empty() && matches!(query.method(), Method::OrderAsc | Method::OrderDesc) {
        query.set_attribute(document::SEQUENCE);
    }
    query
}

/// [`with_default_order`] through every nested query, as the database's
/// `parseQuery()` builds each level with its own constructor.
fn with_default_orders(query: utopia_query::Query) -> utopia_query::Query {
    let mut query = with_default_order(query);
    if query.values().values().any(|v| matches!(v, QValue::Ext(Object::Query(_)))) {
        let values = query.values().clone();
        let mut out = QArray::with_capacity(values.len());
        for (k, v) in values.iter() {
            let v = match v {
                QValue::Ext(Object::Query(child)) => QValue::from(with_default_orders((**child).clone())),
                other => other.clone(),
            };
            out.set(k.to_owned(), v);
        }
        query.set_values(out);
    }
    query
}

/// `gettype()`.
fn gettype(value: &QValue) -> &'static str {
    value.type_name()
}

impl Deref for Query {
    type Target = utopia_query::Query;

    fn deref(&self) -> &utopia_query::Query {
        &self.0
    }
}

impl DerefMut for Query {
    fn deref_mut(&mut self) -> &mut utopia_query::Query {
        &mut self.0
    }
}

impl From<utopia_query::Query> for Query {
    /// A database query of a base query: an order without an attribute
    /// orders by `$sequence` (`new static(...)`).
    fn from(query: utopia_query::Query) -> Self {
        Query(with_default_order(query))
    }
}

impl From<Query> for utopia_query::Query {
    fn from(query: Query) -> Self {
        query.0
    }
}

impl AsRef<utopia_query::Query> for Query {
    fn as_ref(&self) -> &utopia_query::Query {
        &self.0
    }
}

/// Factories forwarded to `utopia_query::Query`, each building a database
/// query.
macro_rules! forward {
    ($( $(#[$m:meta])* fn $name:ident $(<$($g:ident: $b:path),*>)? ($($arg:ident: $ty:ty),*); )*) => {
        $(
            $(#[$m])*
            pub fn $name $(<$($g: $b),*>)? ($($arg: $ty),*) -> Self {
                Query::from(utopia_query::Query::$name($($arg),*))
            }
        )*
    };
}

/// Fallible factories forwarded to `utopia_query::Query`.
macro_rules! forward_result {
    ($( $(#[$m:meta])* fn $name:ident ($($arg:ident: $ty:ty),*); )*) => {
        $(
            $(#[$m])*
            pub fn $name($($arg: $ty),*) -> Result<Self> {
                utopia_query::Query::$name($($arg),*).map(Query::from).map_err(query_error)
            }
        )*
    };
}

impl Query {
    /// A query of `method` on `attribute` with `values` and `alias`; an
    /// order without an attribute orders by `$sequence`.
    ///
    /// PHP: `new Query($method, $attribute, $values, $alias)`.
    pub fn new(method: Method, attribute: impl Into<String>, values: QArray, alias: impl Into<String>) -> Self {
        Query::from(utopia_query::Query::new(method, attribute, values, alias))
    }

    /// [`Query::new`] with the method by name, failing as `Method::from()`
    /// does (`ValueError`) for a name that is not a method.
    pub fn named(method: &str, attribute: impl Into<String>, values: QArray, alias: impl Into<String>) -> Result<Self> {
        let method = Method::from_value(method).map_err(query_error)?;
        Ok(Query::new(method, attribute, values, alias))
    }

    /// The base query, moved out (what a builder takes).
    pub fn into_inner(self) -> utopia_query::Query {
        self.0
    }

    /// The base queries of `queries`, moved out.
    pub fn into_inners(queries: impl IntoIterator<Item = Query>) -> Vec<utopia_query::Query> {
        queries.into_iter().map(Query::into_inner).collect()
    }

    forward! {
        /// `equal`.
        fn equal(attribute: impl Into<String>, values: impl IntoValues);
        /// `notEqual`.
        fn not_equal(attribute: impl Into<String>, value: impl IntoValue);
        /// `lessThan`.
        fn less_than(attribute: impl Into<String>, value: impl IntoValue);
        /// `lessThanEqual`.
        fn less_than_equal(attribute: impl Into<String>, value: impl IntoValue);
        /// `greaterThan`.
        fn greater_than(attribute: impl Into<String>, value: impl IntoValue);
        /// `greaterThanEqual`.
        fn greater_than_equal(attribute: impl Into<String>, value: impl IntoValue);
        /// `contains` (deprecated in PHP for `containsString()` and `containsAny()`).
        fn contains(attribute: impl Into<String>, values: impl IntoValues);
        /// `containsString`.
        fn contains_string(attribute: impl Into<String>, values: impl IntoValues);
        /// `containsAny`.
        fn contains_any(attribute: impl Into<String>, values: impl IntoValues);
        /// `containsAll`.
        fn contains_all(attribute: impl Into<String>, values: impl IntoValues);
        /// `notContains`.
        fn not_contains(attribute: impl Into<String>, values: impl IntoValues);
        /// `between`.
        fn between(attribute: impl Into<String>, start: impl IntoValue, end: impl IntoValue);
        /// `notBetween`.
        fn not_between(attribute: impl Into<String>, start: impl IntoValue, end: impl IntoValue);
        /// `search`.
        fn search(attribute: impl Into<String>, value: impl IntoValue);
        /// `notSearch`.
        fn not_search(attribute: impl Into<String>, value: impl IntoValue);
        /// `select`.
        fn select<S: Into<String>>(attributes: impl IntoIterator<Item = S>);
        /// `select` of any values.
        fn select_values(values: QArray);
        /// `orderDesc` (`$sequence` when `attribute` is empty).
        fn order_desc(attribute: impl Into<String>, nulls: Option<NullsPosition>);
        /// `orderAsc` (`$sequence` when `attribute` is empty).
        fn order_asc(attribute: impl Into<String>, nulls: Option<NullsPosition>);
        /// `orderRandom`.
        fn order_random();
        /// `limit`.
        fn limit(value: i64);
        /// `offset`.
        fn offset(value: i64);
        /// `isNull`.
        fn is_null(attribute: impl Into<String>);
        /// `isNotNull`.
        fn is_not_null(attribute: impl Into<String>);
        /// `startsWith`.
        fn starts_with(attribute: impl Into<String>, value: impl IntoValue);
        /// `notStartsWith`.
        fn not_starts_with(attribute: impl Into<String>, value: impl IntoValue);
        /// `endsWith`.
        fn ends_with(attribute: impl Into<String>, value: impl IntoValue);
        /// `notEndsWith`.
        fn not_ends_with(attribute: impl Into<String>, value: impl IntoValue);
        /// `createdBefore`.
        fn created_before(value: impl IntoValue);
        /// `createdAfter`.
        fn created_after(value: impl IntoValue);
        /// `updatedBefore`.
        fn updated_before(value: impl IntoValue);
        /// `updatedAfter`.
        fn updated_after(value: impl IntoValue);
        /// `createdBetween`.
        fn created_between(start: impl IntoValue, end: impl IntoValue);
        /// `updatedBetween`.
        fn updated_between(start: impl IntoValue, end: impl IntoValue);
        /// A logical query of any values.
        fn logical(method: Method, values: QArray);
        /// `distanceEqual`.
        fn distance_equal(attribute: impl Into<String>, values: QArray, distance: impl IntoValue, meters: bool);
        /// `distanceNotEqual`.
        fn distance_not_equal(attribute: impl Into<String>, values: QArray, distance: impl IntoValue, meters: bool);
        /// `distanceGreaterThan`.
        fn distance_greater_than(attribute: impl Into<String>, values: QArray, distance: impl IntoValue, meters: bool);
        /// `distanceLessThan`.
        fn distance_less_than(attribute: impl Into<String>, values: QArray, distance: impl IntoValue, meters: bool);
        /// `intersects`.
        fn intersects(attribute: impl Into<String>, values: QArray);
        /// `notIntersects`.
        fn not_intersects(attribute: impl Into<String>, values: QArray);
        /// `crosses`.
        fn crosses(attribute: impl Into<String>, values: QArray);
        /// `notCrosses`.
        fn not_crosses(attribute: impl Into<String>, values: QArray);
        /// `overlaps`.
        fn overlaps(attribute: impl Into<String>, values: QArray);
        /// `notOverlaps`.
        fn not_overlaps(attribute: impl Into<String>, values: QArray);
        /// `touches`.
        fn touches(attribute: impl Into<String>, values: QArray);
        /// `notTouches`.
        fn not_touches(attribute: impl Into<String>, values: QArray);
        /// `covers`.
        fn covers(attribute: impl Into<String>, values: QArray);
        /// `notCovers`.
        fn not_covers(attribute: impl Into<String>, values: QArray);
        /// `spatialEquals`.
        fn spatial_equals(attribute: impl Into<String>, values: QArray);
        /// `notSpatialEquals`.
        fn not_spatial_equals(attribute: impl Into<String>, values: QArray);
        /// `vectorDot`.
        fn vector_dot(attribute: impl Into<String>, vector: QArray);
        /// `vectorCosine`.
        fn vector_cosine(attribute: impl Into<String>, vector: QArray);
        /// `vectorEuclidean`.
        fn vector_euclidean(attribute: impl Into<String>, vector: QArray);
        /// `regex`.
        fn regex(attribute: impl Into<String>, pattern: impl IntoValue);
        /// `exists`.
        fn exists(attributes: QArray);
        /// `notExists`.
        fn not_exists(attribute: impl IntoValue);
        /// `count`.
        fn count(attribute: impl Into<String>, alias: impl Into<String>);
        /// `countDistinct`.
        fn count_distinct(attribute: impl Into<String>, alias: impl Into<String>);
        /// `sum`.
        fn sum(attribute: impl Into<String>, alias: impl Into<String>);
        /// `avg`.
        fn avg(attribute: impl Into<String>, alias: impl Into<String>);
        /// `min`.
        fn min(attribute: impl Into<String>, alias: impl Into<String>);
        /// `max`.
        fn max(attribute: impl Into<String>, alias: impl Into<String>);
        /// `stddev`: over the population (the adapters pin it to `STDDEV_POP`).
        fn stddev(attribute: impl Into<String>, alias: impl Into<String>);
        /// `stddevPop`.
        fn stddev_pop(attribute: impl Into<String>, alias: impl Into<String>);
        /// `stddevSamp`.
        fn stddev_samp(attribute: impl Into<String>, alias: impl Into<String>);
        /// `variance`: over the population (the adapters pin it to `VAR_POP`).
        fn variance(attribute: impl Into<String>, alias: impl Into<String>);
        /// `varPop`.
        fn var_pop(attribute: impl Into<String>, alias: impl Into<String>);
        /// `varSamp`.
        fn var_samp(attribute: impl Into<String>, alias: impl Into<String>);
        /// `bitAnd`.
        fn bit_and(attribute: impl Into<String>, alias: impl Into<String>);
        /// `bitOr`.
        fn bit_or(attribute: impl Into<String>, alias: impl Into<String>);
        /// `bitXor`.
        fn bit_xor(attribute: impl Into<String>, alias: impl Into<String>);
        /// `groupBy`.
        fn group_by<S: Into<String>>(attributes: impl IntoIterator<Item = S>);
        /// `distinct`.
        fn distinct();
        /// `on`: a join condition.
        fn on(left: impl Into<String>, right: impl Into<String>, operator: impl Into<String>);
        /// `jsonContains`.
        fn json_contains(attribute: impl Into<String>, value: impl IntoValue);
        /// `jsonNotContains`.
        fn json_not_contains(attribute: impl Into<String>, value: impl IntoValue);
        /// `jsonOverlaps`.
        fn json_overlaps(attribute: impl Into<String>, values: QArray);
        /// `jsonPath`.
        fn json_path(attribute: impl Into<String>, path: impl Into<String>, operator: impl Into<String>, value: impl IntoValue);
        /// `raw`.
        fn raw(sql: impl Into<String>, bindings: QArray);
    }

    forward_result! {
        /// `groupByTimeBucket`.
        fn group_by_time_bucket(attribute: impl Into<String>, interval: &str);
        /// `crossJoin`.
        fn cross_join(collection: impl Into<String>, alias: impl Into<String>);
        /// `naturalJoin`.
        fn natural_join(collection: impl Into<String>, alias: impl Into<String>);
    }

    /// `or`.
    pub fn or(queries: impl IntoIterator<Item = Query>) -> Self {
        Query::from(utopia_query::Query::or(queries.into_iter().map(Query::into_inner)))
    }

    /// `and`.
    pub fn and(queries: impl IntoIterator<Item = Query>) -> Self {
        Query::from(utopia_query::Query::and(queries.into_iter().map(Query::into_inner)))
    }

    /// `elemMatch`.
    pub fn elem_match(attribute: impl Into<String>, queries: impl IntoIterator<Item = Query>) -> Self {
        Query::from(utopia_query::Query::elem_match(attribute, queries.into_iter().map(Query::into_inner)))
    }

    /// `relationship`: queries, written against the related collection, that
    /// shape the documents populated for the relationship attribute (filter,
    /// order, select and page them); they match no row of the collection
    /// read.
    ///
    /// ```
    /// use utopia_database::Query;
    ///
    /// let query = Query::relationship("comments", [Query::equal("approved", [true]), Query::limit(2)]);
    /// assert_eq!(query.shape().unwrap(), "relationship:comments(equal:approved|limit:)");
    /// ```
    ///
    /// PHP: `Query::relationship()`.
    pub fn relationship(attribute: impl Into<String>, queries: impl IntoIterator<Item = Query>) -> Self {
        Query::from(utopia_query::Query::relationship(attribute, queries.into_iter().map(Query::into_inner)))
    }

    /// `having`.
    pub fn having(queries: impl IntoIterator<Item = Query>) -> Self {
        Query::from(utopia_query::Query::having(queries.into_iter().map(Query::into_inner)))
    }

    /// `union`.
    pub fn union(queries: impl IntoIterator<Item = Query>) -> Self {
        Query::from(utopia_query::Query::union(queries.into_iter().map(Query::into_inner)))
    }

    /// `unionAll`.
    pub fn union_all(queries: impl IntoIterator<Item = Query>) -> Self {
        Query::from(utopia_query::Query::union_all(queries.into_iter().map(Query::into_inner)))
    }

    /// `join` (inner).
    pub fn join(collection: impl Into<String>, alias: impl Into<String>, on: Vec<Query>) -> Result<Self> {
        utopia_query::Query::join(collection, alias, Query::into_inners(on)).map(Query::from).map_err(query_error)
    }

    /// `leftJoin`.
    pub fn left_join(collection: impl Into<String>, alias: impl Into<String>, on: Vec<Query>) -> Result<Self> {
        utopia_query::Query::left_join(collection, alias, Query::into_inners(on)).map(Query::from).map_err(query_error)
    }

    /// `rightJoin`.
    pub fn right_join(collection: impl Into<String>, alias: impl Into<String>, on: Vec<Query>) -> Result<Self> {
        utopia_query::Query::right_join(collection, alias, Query::into_inners(on)).map(Query::from).map_err(query_error)
    }

    /// `fullOuterJoin`.
    pub fn full_outer_join(collection: impl Into<String>, alias: impl Into<String>, on: Vec<Query>) -> Result<Self> {
        utopia_query::Query::full_outer_join(collection, alias, Query::into_inners(on))
            .map(Query::from)
            .map_err(query_error)
    }

    /// A join of `method` with any conditions.
    pub fn join_values(
        method: Method,
        collection: impl Into<String>,
        alias: impl Into<String>,
        on: QArray,
    ) -> Result<Self> {
        utopia_query::Query::join_values(method, collection, alias, on).map(Query::from).map_err(query_error)
    }

    /// `cursorAfter`: the page after `cursor`, a document.
    ///
    /// PHP: `Query::cursorAfter($document)`.
    pub fn cursor_after(cursor: &Document) -> Self {
        Query::cursor_after_value(dbvalue::to_query(&crate::Value::from(cursor.clone())))
    }

    /// `cursorBefore`: the page before `cursor`, a document.
    ///
    /// PHP: `Query::cursorBefore($document)`.
    pub fn cursor_before(cursor: &Document) -> Self {
        Query::cursor_before_value(dbvalue::to_query(&crate::Value::from(cursor.clone())))
    }

    /// `cursorAfter` with any value, such as a document id
    /// (`Validator\Query\Cursor` accepts an id and refuses an array).
    pub fn cursor_after_value(value: QValue) -> Self {
        Query::new(Method::CursorAfter, "", QArray::from_list(vec![value]), "")
    }

    /// `cursorBefore` with any value.
    pub fn cursor_before_value(value: QValue) -> Self {
        Query::new(Method::CursorBefore, "", QArray::from_list(vec![value]), "")
    }

    /// `limit` and `offset` of page `page` of `per_page` items.
    pub fn page(page: i64, per_page: i64) -> Result<[Query; 2]> {
        let [limit, offset] = utopia_query::Query::page(page, per_page).map_err(query_error)?;
        Ok([Query::from(limit), Query::from(offset)])
    }

    /// Whether `value` names a method (`isMethod()`).
    pub fn is_method(value: &str) -> bool {
        Method::try_from_value(value).is_some()
    }

    /// The query in `query`, JSON (`parse()`).
    ///
    /// Nested queries (`and`, `or`, `elemMatch`, ...) may be given as JSON
    /// strings as well as objects. Raw queries are refused unless
    /// `allow_raw`.
    ///
    /// Fails ([`Kind::Query`]) with the base parser's message, `Invalid
    /// query method: raw`, or `Invalid nested query. Must be an array or
    /// string, got <type>`.
    pub fn parse(query: &str, allow_raw: bool) -> Result<Self> {
        Query::parse_bytes(query.as_bytes(), allow_raw)
    }

    /// [`Query::parse`] on bytes.
    pub fn parse_bytes(query: &[u8], allow_raw: bool) -> Result<Self> {
        let decoded: QValue = json::decode_ext(query, Some(true), json::DEFAULT_DEPTH, Flags::THROW_ON_ERROR)
            .map_err(|e| Error::query(format!("Invalid query: {}", e.message())))?;
        match decoded {
            QValue::Array(array) => Query::parse_query(&array, allow_raw),
            other => Err(Error::query(format!("Invalid query. Must be an array, got {}", gettype(&other)))),
        }
    }

    /// The query of a decoded array (`parseQuery()`).
    ///
    /// Fails as [`Query::parse`] does.
    pub fn parse_query(query: &QArray, allow_raw: bool) -> Result<Self> {
        let prepared = Query::prepare(query, allow_raw)?;
        let parsed = utopia_query::Query::parse_query(&prepared, allow_raw).map_err(query_error)?;
        Ok(Query(with_default_orders(parsed)))
    }

    /// Each query of `queries`, JSON (`parseQueries()`).
    pub fn parse_queries<S: AsRef<str>>(queries: &[S], allow_raw: bool) -> Result<Vec<Self>> {
        queries.iter().map(|q| Query::parse(q.as_ref(), allow_raw)).collect()
    }

    /// The array the base parser reads, at every level as the database's
    /// `parseQuery()` hands it on: a raw query refused unless `allow_raw`,
    /// the string children of a nested query decoded.
    fn prepare(query: &QArray, allow_raw: bool) -> Result<QArray> {
        let method = query.get_str("method");
        if !allow_raw && matches!(method, Some(QValue::Str(m)) if m.as_bytes() == Method::Raw.as_str().as_bytes()) {
            return Err(Error::query(format!("Invalid query method: {}", Method::Raw.as_str())));
        }
        let Some(QValue::Str(method)) = method else { return Ok(query.clone()) };
        let Some(method) = method.as_str().and_then(Method::try_from_value) else { return Ok(query.clone()) };
        let nested = method.is_nested();
        if !nested && !method.is_join() {
            return Ok(query.clone());
        }
        let Some(QValue::Array(values)) = query.get_str("values").filter(|v| !matches!(v, QValue::Null)) else {
            return Ok(query.clone());
        };
        let mut out = QArray::with_capacity(values.len());
        for (index, value) in values.iter() {
            let value = match value {
                QValue::Array(child) => QValue::Array(Query::prepare(child, allow_raw)?),
                QValue::Str(s) if nested => QValue::Array(Query::parse_bytes(s.as_bytes(), allow_raw)?.to_array()?),
                other if nested => {
                    return Err(Error::query(format!(
                        "Invalid nested query. Must be an array or string, got {}",
                        gettype(other)
                    )));
                }
                other => other.clone(),
            };
            out.set(index.to_owned(), value);
        }
        let mut prepared = query.clone();
        prepared.set(php_std::ArrayKey::from("values"), QValue::Array(out));
        Ok(prepared)
    }

    /// A fingerprint of the queries' shape: their [`Query::shape`]s, sorted
    /// as PHP's `sort()` sorts strings, joined by `|`, as an MD5 hex digest.
    /// A query given as JSON is parsed first.
    ///
    /// PHP: `Query::fingerprint()`.
    pub fn fingerprint(queries: &[QueryOrJson<'_>]) -> Result<String> {
        let mut shapes = Vec::with_capacity(queries.len());
        for query in queries {
            shapes.push(match query {
                QueryOrJson::Query(q) => shape_of(q)?,
                QueryOrJson::Json(json) => Query::parse(json, false)?.shape()?,
            });
        }
        sort_strings(&mut shapes);
        Ok(md5_hex(shapes.join("|").as_bytes()))
    }

    /// [`Query::fingerprint`] of database queries.
    pub fn fingerprint_queries(queries: &[Query]) -> Result<String> {
        let mut shapes = queries.iter().map(Query::shape).collect::<Result<Vec<_>>>()?;
        sort_strings(&mut shapes);
        Ok(md5_hex(shapes.join("|").as_bytes()))
    }

    /// The shape of the query, without values: `method:attribute`, and for
    /// `and`, `or`, `elemMatch` and `relationship`
    /// `method:attribute(child|child)` with the children's shapes sorted.
    ///
    /// Fails ([`Kind::Query`]) for a tree of more than [`SHAPE_MAX_NODES`]
    /// nodes.
    ///
    /// PHP: `Query::shape()`.
    pub fn shape(&self) -> Result<String> {
        shape_of(&self.0)
    }

    /// The query as an array: `method`, `attribute` (unless empty or
    /// `"0"`), `alias` (unless empty), and `values`: the children of
    /// `and`, `or`, `elemMatch` and `having` as arrays under their keys,
    /// any other values as a list with nested queries as arrays and a
    /// document cursor as its id.
    ///
    /// Fails ([`Kind::Query`]) for a child of a logical or `having` query
    /// that is not a query (`Invalid child query in <method> at index <i>:
    /// expected Query, got <type>`).
    ///
    /// PHP: `Query::toArray()`.
    pub fn to_array(&self) -> Result<QArray> {
        to_array_of(&self.0)
    }

    /// The query as JSON (`toString()`).
    pub fn to_json(&self) -> Result<String> {
        let array = enums_as_values(&QValue::Array(self.to_array()?));
        json::encode(&array, Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH)
            .map_err(|e| Error::query(format!("Invalid Json: {}", e.message())))
    }

    /// Groups the queries by kind, with their orders in the order the
    /// queries give them and the relationship queries kept apart from the
    /// filters.
    ///
    /// Fails ([`Kind::Query`]) when the cursor is not a document.
    ///
    /// PHP: `Query::groupByType()`.
    pub fn group_by_type(queries: &[Query]) -> Result<ParsedQuery> {
        ParsedQuery::group(queries.iter())
    }

    /// The queries of the given methods (`getByType()`).
    pub fn by_type<'a>(queries: &'a [Query], methods: &[Method]) -> Vec<&'a Query> {
        queries.iter().filter(|q| methods.contains(&q.method())).collect()
    }

    /// The cursor queries (`getCursorQueries()`).
    pub fn cursor_queries(queries: &[Query]) -> Vec<&Query> {
        Query::by_type(queries, &[Method::CursorAfter, Method::CursorBefore])
    }

    /// Whether the query's attribute is spatial (point, linestring or
    /// polygon), by its attribute type (`isSpatialAttribute()`).
    pub fn is_spatial_attribute(&self) -> bool {
        matches!(self.attribute_type(), "point" | "linestring" | "polygon")
    }

    /// Whether the query's attribute is an object attribute
    /// (`isObjectAttribute()`).
    pub fn is_object_attribute(&self) -> bool {
        self.attribute_type() == "object"
    }

    /// The first value as a document, if it is one (a cursor).
    pub fn document_value(&self) -> Option<Document> {
        match self.value()? {
            v @ QValue::Ext(Object::Row { .. }) => match dbvalue::from_query(v) {
                crate::Value::Ext(crate::value::Ext::Document(d)) => Some(*d),
                _ => None,
            },
            _ => None,
        }
    }
}

/// [`Query::shape`] of a base query (children are base queries).
fn shape_of(root: &utopia_query::Query) -> Result<String> {
    // Preorder, then reversed so children are shaped before parents.
    let mut nodes: Vec<&utopia_query::Query> = Vec::new();
    let mut stack = vec![root];
    while let Some(node) = stack.pop() {
        nodes.push(node);
        if nodes.len() > SHAPE_MAX_NODES {
            return Err(Error::query(format!(
                "Query is too deeply nested to fingerprint: exceeded {SHAPE_MAX_NODES} nodes, which means a cycle or a child shared across too many parents"
            )));
        }
        if !shapes_children(node.method()) {
            continue;
        }
        for child in node.values().values() {
            if let QValue::Ext(Object::Query(q)) = child {
                stack.push(q);
            }
        }
    }
    let mut shapes: Vec<Option<String>> = vec![None; nodes.len()];
    let index_of =
        |q: &utopia_query::Query, nodes: &[&utopia_query::Query]| nodes.iter().position(|n| std::ptr::eq(*n, q));
    for i in (0..nodes.len()).rev() {
        let node = nodes[i];
        if !shapes_children(node.method()) {
            shapes[i] = Some(format!("{}:{}", node.method().as_str(), node.attribute()));
            continue;
        }
        let mut children = Vec::new();
        for child in node.values().values() {
            if let QValue::Ext(Object::Query(q)) = child
                && let Some(j) = index_of(q, &nodes)
            {
                children.push(shapes[j].clone().unwrap_or_default());
            }
        }
        sort_strings(&mut children);
        shapes[i] = Some(format!("{}:{}({})", node.method().as_str(), node.attribute(), children.join("|")));
    }
    Ok(shapes[0].take().unwrap_or_default())
}

/// Whether a query's shape holds its children's: the logical queries' and a
/// relationship query's (`shapesChildren()`).
fn shapes_children(method: Method) -> bool {
    method == Method::Relationship || LOGICAL_TYPES.contains(&method)
}

/// [`Query::to_array`] of a base query.
fn to_array_of(query: &utopia_query::Query) -> Result<QArray> {
    let method = query.method();
    let mut array = QArray::with_capacity(4);
    array.set(php_std::ArrayKey::from("method"), utopia_query::string(method.as_str()));
    let attribute = query.attribute();
    if !(attribute.is_empty() || attribute == "0") {
        array.set(php_std::ArrayKey::from("attribute"), utopia_query::string(attribute));
    }
    if !query.alias().is_empty() {
        array.set(php_std::ArrayKey::from("alias"), utopia_query::string(query.alias()));
    }
    if LOGICAL_TYPES.contains(&method) || method == Method::Having {
        let mut values = QArray::with_capacity(query.values().len());
        for (index, value) in query.values().iter() {
            let QValue::Ext(Object::Query(child)) = value else {
                let index = match index {
                    php_std::types::KeyRef::Int(i) => i.to_string(),
                    php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                };
                return Err(Error::query(format!(
                    "Invalid child query in {} at index {index}: expected Query, got {}",
                    method.as_str(),
                    value.debug_type()
                )));
            };
            values.set(index.to_owned(), QValue::Array(to_array_of(child)?));
        }
        // PHP only sets `values` once a child is written.
        if !values.is_empty() {
            array.set(php_std::ArrayKey::from("values"), QValue::Array(values));
        }
    } else {
        let cursor = matches!(method, Method::CursorAfter | Method::CursorBefore);
        let mut values = Vec::with_capacity(query.values().len());
        for value in query.values().values() {
            values.push(match value {
                QValue::Ext(Object::Query(child)) => QValue::Array(to_array_of(child)?),
                QValue::Ext(Object::Row { class, fields }) if cursor && is_document_class(class) => {
                    match fields.get_str(document::ID) {
                        Some(QValue::Str(id)) => QValue::Str(id.clone()),
                        _ => utopia_query::string(""),
                    }
                }
                other => other.clone(),
            });
        }
        array.set(php_std::ArrayKey::from("values"), QValue::Array(QArray::from_list(values)));
    }
    Ok(array)
}

/// Whether a row's class is a document's (anything a document converts to
/// with [`crate::value::to_query`] but an operator or a BSON value).
fn is_document_class(class: &str) -> bool {
    class != "Utopia\\Database\\Operator" && !class.starts_with("MongoDB\\")
}

/// PHP's `sort()` of strings.
fn sort_strings(strings: &mut [String]) {
    strings.sort_by(|a, b| {
        crate::Value::Str(php_std::Str::from(a.as_str())).cmp_php(&crate::Value::Str(php_std::Str::from(b.as_str())))
    });
}

pub(crate) fn md5_hex(bytes: &[u8]) -> String {
    use md5::Digest;
    md5::Md5::digest(bytes).iter().map(|b| format!("{b:02x}")).collect()
}

/// Enum cases replaced by their values, as `json_encode` writes them.
fn enums_as_values(value: &QValue) -> QValue {
    match value {
        QValue::Ext(Object::Nulls(n)) => utopia_query::string(n.as_str()),
        // An operator has no public properties: `json_encode()` writes `{}`
        // (Operator.php declares them protected).
        QValue::Ext(Object::Row { class, .. }) if class == "Utopia\\Database\\Operator" => {
            QValue::Object(QArray::new())
        }
        QValue::Ext(Object::Row { fields, .. }) => QValue::Object(map_array(fields)),
        QValue::Array(a) => QValue::Array(map_array(a)),
        QValue::Object(a) => QValue::Object(map_array(a)),
        other => other.clone(),
    }
}

fn map_array(a: &QArray) -> QArray {
    let mut out = QArray::with_capacity(a.len());
    for (k, v) in a.iter() {
        out.set(k.to_owned(), enums_as_values(v));
    }
    out
}

/// Database queries grouped by kind, with their orders in the order the
/// queries give them.
///
/// PHP: `Utopia\Database\ParsedQuery` (which extends
/// `Utopia\Query\Builder\ParsedQuery`).
#[derive(Debug, Clone, PartialEq, Default)]
pub struct ParsedQuery {
    /// Filters (everything not grouped elsewhere).
    pub filters: Vec<Query>,
    /// `select` queries.
    pub selections: Vec<Query>,
    /// Aggregates.
    pub aggregations: Vec<Query>,
    /// `groupBy` columns, as the queries hold them.
    pub group_by: Vec<QValue>,
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
    /// The first cursor's document.
    pub cursor: Option<Document>,
    /// The first cursor's direction.
    pub cursor_direction: Option<CursorDirection>,
    /// `groupByTimeBucket` queries.
    pub time_buckets: Vec<utopia_query::TimeBucket>,
    /// The attributes of the orders, in order.
    pub order_attributes: Vec<String>,
    /// The directions of the orders, in order.
    pub order_types: Vec<OrderDirection>,
    /// `relationship` queries, which shape the documents populated for a
    /// relationship rather than filter the documents read.
    pub relationships: Vec<Query>,
}

impl ParsedQuery {
    /// Whether the queries read one row per group, through an aggregate or
    /// a `groupBy`, instead of documents (`isAggregation()`).
    pub fn is_aggregation(&self) -> bool {
        !self.aggregations.is_empty() || !self.group_by.is_empty()
    }

    /// [`Query::group_by_type`] over any iterator of queries.
    pub fn group<'a>(queries: impl IntoIterator<Item = &'a Query>) -> Result<Self> {
        let mut parsed = ParsedQuery::default();
        let mut cursor: Option<QValue> = None;
        for query in queries {
            let method = query.method();
            let first = query.values().get(&php_std::ArrayKey::Int(0));
            match method {
                Method::OrderAsc | Method::OrderDesc | Method::OrderRandom => {
                    parsed.order_attributes.push(query.attribute().to_owned());
                    parsed.order_types.push(match method {
                        Method::OrderAsc => OrderDirection::Asc,
                        Method::OrderDesc => OrderDirection::Desc,
                        _ => OrderDirection::Random,
                    });
                }
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
                    if cursor.is_none() {
                        cursor = first.filter(|v| !matches!(v, QValue::Null)).cloned();
                        parsed.cursor_direction = Some(if method == Method::CursorAfter {
                            CursorDirection::After
                        } else {
                            CursorDirection::Before
                        });
                    }
                }
                Method::Select => parsed.selections.push(query.clone()),
                m if m.is_aggregate() => parsed.aggregations.push(query.clone()),
                Method::GroupBy => parsed.group_by.extend(query.values().values().cloned()),
                Method::GroupByTimeBucket => parsed.time_buckets.push(utopia_query::TimeBucket {
                    attribute: query.attribute().to_owned(),
                    interval: first
                        .filter(|v| !matches!(v, QValue::Null))
                        .cloned()
                        .unwrap_or_else(|| utopia_query::string("")),
                }),
                Method::Having => parsed.having.push(query.clone()),
                Method::Distinct => parsed.distinct = true,
                m if m.is_join() => parsed.joins.push(query.clone()),
                Method::Union | Method::UnionAll => parsed.unions.push(query.clone()),
                Method::Relationship => parsed.relationships.push(query.clone()),
                _ => parsed.filters.push(query.clone()),
            }
        }
        if let Some(value) = cursor {
            match dbvalue::from_query(&value) {
                crate::Value::Ext(crate::value::Ext::Document(d))
                    if matches!(value, QValue::Ext(Object::Row { .. })) =>
                {
                    parsed.cursor = Some(*d);
                }
                // Named as PHP's get_debug_type() names it: a nested query
                // is a `Utopia\Database\Query`.
                other => {
                    return Err(Error::query(format!(
                        "Invalid query: Invalid cursor: a cursor must be a document, {} given",
                        other.debug_type()
                    )));
                }
            }
        }
        Ok(parsed)
    }
}

/// `is_numeric($value)`.
fn is_numeric(value: &QValue) -> bool {
    match value {
        QValue::Int(_) | QValue::Float(_) => true,
        QValue::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    }
}

/// `intval($value)`.
fn intval(value: &QValue) -> i64 {
    match value {
        QValue::Int(i) => *i,
        QValue::Float(f) => php_std::format::dval_to_lval(*f),
        QValue::Str(s) => php_std::format::Arg::Str(s.as_bytes()).to_int(),
        QValue::Bool(b) => i64::from(*b),
        QValue::Null => 0,
        QValue::Array(a) => i64::from(!a.is_empty()),
        QValue::Object(_) | QValue::Ext(_) => 1,
    }
}

#[cfg(test)]
mod statement_error_tests {
    use super::{Kind, query_error, statement_error};

    #[test]
    fn a_statement_keeps_the_query_libraries_validation_exception() {
        let error = statement_error(utopia_query::Error::Validation(
            "Conflict update column 'n' is not present in the row data.".into(),
        ));
        assert_eq!(error.kind(), Kind::QueryValidation);
        assert_eq!(error.php_class(), "Utopia\\Query\\Exception\\ValidationException");
        assert_eq!(query_error(utopia_query::Error::Validation("x".into())).kind(), Kind::Query);
    }
}
