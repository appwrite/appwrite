//! [`Queries`]: lists of queries, each checked by the [`QueryValidator`]s
//! of its kind.
//!
//! PHP: `Utopia\Database\Validator\Queries\Base`.

use std::borrow::Cow;
use std::sync::{Arc, Mutex, MutexGuard};

use utopia_query::{Method, Value as QValue};

use crate::document::Document;
use crate::error::Result;
use crate::index::Index;
use crate::query::LOGICAL_TYPES;
use crate::validator::Message;
use crate::validator::query::aggregate::Aggregate;
use crate::validator::query::base::{MethodType, QueryValidator, nested};
use crate::validator::query::filter::Filter;
use crate::validator::query::group_by::GroupBy;
use crate::validator::query::having::Having;
use crate::validator::query::join::Join;
use crate::validator::query::joined::collection::{Collection, Definition};
use crate::validator::query::order::Order;
use crate::validator::query::select::Select;
use crate::value::{Ext, Value};

/// The methods 7.x parsed: when no validator takes one of them, its message
/// has no `Invalid query: ` prefix.
const LEGACY_METHODS: [Method; 48] = [
    Method::Equal,
    Method::NotEqual,
    Method::LessThan,
    Method::LessThanEqual,
    Method::GreaterThan,
    Method::GreaterThanEqual,
    Method::Contains,
    Method::ContainsAny,
    Method::NotContains,
    Method::Search,
    Method::NotSearch,
    Method::IsNull,
    Method::IsNotNull,
    Method::Between,
    Method::NotBetween,
    Method::StartsWith,
    Method::NotStartsWith,
    Method::EndsWith,
    Method::NotEndsWith,
    Method::Crosses,
    Method::NotCrosses,
    Method::DistanceEqual,
    Method::DistanceNotEqual,
    Method::DistanceGreaterThan,
    Method::DistanceLessThan,
    Method::Intersects,
    Method::NotIntersects,
    Method::Overlaps,
    Method::NotOverlaps,
    Method::Touches,
    Method::NotTouches,
    Method::VectorDot,
    Method::VectorCosine,
    Method::VectorEuclidean,
    Method::Exists,
    Method::NotExists,
    Method::Select,
    Method::OrderDesc,
    Method::OrderAsc,
    Method::OrderRandom,
    Method::Limit,
    Method::Offset,
    Method::CursorAfter,
    Method::CursorBefore,
    Method::And,
    Method::Or,
    Method::ContainsAll,
    Method::ElemMatch,
];

/// Where PHP reports a `Query::parse()` argument error from: the
/// `TypeError` names the calling file and line, as installed in
/// Appwrite's image.
const PARSE_CALLER: &str =
    "/usr/src/code/vendor/utopia-php/database/src/Database/Validator/Queries/Base.php on line 186";

/// One entry of a query list, as PHP's `isValid(array)` reads it: a query,
/// a string to parse, or any other value.
#[derive(Debug, Clone, Copy)]
pub enum Entry<'a> {
    /// A query (`$q instanceof Query`).
    Query(&'a utopia_query::Query),
    /// A query as JSON.
    Json(&'a [u8]),
    /// Anything else: scalars are parsed as PHP converts them to strings,
    /// other values refused as PHP's `TypeError` refuses them.
    Other(&'a Value),
}

/// What a query list holds: queries, JSON strings or database values.
pub trait QueryEntry {
    /// The entry.
    fn entry(&self) -> Entry<'_>;
}

impl QueryEntry for crate::Query {
    fn entry(&self) -> Entry<'_> {
        Entry::Query(self)
    }
}

impl QueryEntry for utopia_query::Query {
    fn entry(&self) -> Entry<'_> {
        Entry::Query(self)
    }
}

impl QueryEntry for str {
    fn entry(&self) -> Entry<'_> {
        Entry::Json(self.as_bytes())
    }
}

impl QueryEntry for String {
    fn entry(&self) -> Entry<'_> {
        Entry::Json(self.as_bytes())
    }
}

impl QueryEntry for Value {
    fn entry(&self) -> Entry<'_> {
        match self {
            Value::Ext(Ext::Query(q)) => Entry::Query(q),
            Value::Str(s) => Entry::Json(s.as_bytes()),
            other => Entry::Other(other),
        }
    }
}

impl<T: QueryEntry + ?Sized> QueryEntry for &T {
    fn entry(&self) -> Entry<'_> {
        (**self).entry()
    }
}

/// The query an entry holds, parsed when it is not a query; the failure is
/// the message of PHP's `Query::parse()`.
pub(crate) fn parse_entry(entry: Entry<'_>) -> std::result::Result<Cow<'_, utopia_query::Query>, String> {
    let parsed = match entry {
        Entry::Query(q) => return Ok(Cow::Borrowed(q)),
        Entry::Json(json) => crate::Query::parse_bytes(json, false),
        Entry::Other(value @ (Value::Int(_) | Value::Float(_) | Value::Bool(_))) => {
            crate::Query::parse(&crate::value::to_text(value), false)
        }
        Entry::Other(value) => {
            return Err(format!(
                "Utopia\\Database\\Query::parse(): Argument #1 ($query) must be of type string, {} given, called in {PARSE_CALLER}",
                value.debug_type()
            ));
        }
    };
    parsed.map(|q| Cow::Owned(q.into_inner())).map_err(|e| e.message().to_owned())
}

/// A collection the query lists may join, read once.
#[derive(Debug, Clone)]
pub(crate) struct Joinable {
    id: String,
    definition: Arc<Definition>,
    pub(crate) indexes: Arc<[Index]>,
}

/// Which of this module's validators a validator is (PHP's `instanceof`).
enum Known<'a> {
    Order(&'a Order),
    Select(&'a Select),
    Filter(&'a Filter),
    Aggregate(&'a Aggregate),
    GroupBy(&'a GroupBy),
    Join(&'a Join),
    Having(&'a Having),
    Other,
}

fn known(validator: &dyn QueryValidator) -> Known<'_> {
    let any = validator.as_any();
    if let Some(v) = any.downcast_ref::<Order>() {
        Known::Order(v)
    } else if let Some(v) = any.downcast_ref::<Select>() {
        Known::Select(v)
    } else if let Some(v) = any.downcast_ref::<Filter>() {
        Known::Filter(v)
    } else if let Some(v) = any.downcast_ref::<Aggregate>() {
        Known::Aggregate(v)
    } else if let Some(v) = any.downcast_ref::<GroupBy>() {
        Known::GroupBy(v)
    } else if let Some(v) = any.downcast_ref::<Join>() {
        Known::Join(v)
    } else if let Some(v) = any.downcast_ref::<Having>() {
        Known::Having(v)
    } else {
        Known::Other
    }
}

/// The kind of query a method is (the `match` of `isValidQuery()`), `None`
/// for a method no validator takes.
pub(crate) fn method_type(method: Method) -> Option<MethodType> {
    if method.is_aggregate() {
        return Some(MethodType::Aggregate);
    }
    Some(match method {
        Method::Select => MethodType::Select,
        Method::Limit => MethodType::Limit,
        Method::Offset => MethodType::Offset,
        Method::CursorAfter | Method::CursorBefore => MethodType::Cursor,
        Method::OrderAsc | Method::OrderDesc | Method::OrderRandom => MethodType::Order,
        Method::Equal
        | Method::NotEqual
        | Method::LessThan
        | Method::LessThanEqual
        | Method::GreaterThan
        | Method::GreaterThanEqual
        | Method::Search
        | Method::NotSearch
        | Method::IsNull
        | Method::IsNotNull
        | Method::Between
        | Method::NotBetween
        | Method::StartsWith
        | Method::NotStartsWith
        | Method::EndsWith
        | Method::NotEndsWith
        | Method::Contains
        | Method::ContainsAny
        | Method::NotContains
        | Method::And
        | Method::Or
        | Method::ContainsAll
        | Method::ElemMatch
        | Method::Crosses
        | Method::NotCrosses
        | Method::DistanceEqual
        | Method::DistanceNotEqual
        | Method::DistanceGreaterThan
        | Method::DistanceLessThan
        | Method::Intersects
        | Method::NotIntersects
        | Method::Overlaps
        | Method::NotOverlaps
        | Method::Touches
        | Method::NotTouches
        | Method::Covers
        | Method::NotCovers
        | Method::SpatialEquals
        | Method::NotSpatialEquals
        | Method::VectorDot
        | Method::VectorCosine
        | Method::VectorEuclidean
        | Method::Regex
        | Method::Exists
        | Method::NotExists => MethodType::Filter,
        Method::Distinct => MethodType::Distinct,
        Method::GroupBy => MethodType::GroupBy,
        Method::Having => MethodType::Having,
        Method::Join
        | Method::LeftJoin
        | Method::RightJoin
        | Method::CrossJoin
        | Method::FullOuterJoin
        | Method::NaturalJoin => MethodType::Join,
        Method::Relationship => MethodType::Relationship,
        _ => return None,
    })
}

/// A list of queries: at most `length` of them (0: no limit), and as many
/// in each nested group; each parsed and valid for every validator of its
/// kind (and at least one), the children of a logical query first, a
/// join's ON filters after it. The validators are handed the joins,
/// aliases and aggregates of the list before any query is checked.
///
/// ```
/// use std::sync::Arc;
/// use utopia_database::Query;
/// use utopia_database::validator::queries::base::Queries;
/// use utopia_database::validator::query::limit::Limit;
///
/// let queries = Queries::new(vec![Arc::new(Limit::default())], 0);
/// assert!(queries.check(&[Query::limit(10)]));
/// assert!(!queries.check(&[Query::offset(10)]));
/// assert_eq!(queries.description(), "Invalid query method: offset");
/// ```
///
/// PHP: `Utopia\Database\Validator\Queries\Base` (`new Base($validators,
/// $length)`).
pub struct Queries {
    validators: Vec<Arc<dyn QueryValidator>>,
    length: usize,
    joined: Mutex<Vec<Joinable>>,
    pub(crate) message: Message,
}

impl std::fmt::Debug for Queries {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Queries").field("length", &self.length).field("message", &self.message).finish_non_exhaustive()
    }
}

impl Default for Queries {
    /// `new Base()`: no validators, no length cap.
    fn default() -> Self {
        Queries::new(Vec::new(), 0)
    }
}

impl Queries {
    /// `new Base($validators, $length)`.
    pub fn new(validators: Vec<Arc<dyn QueryValidator>>, length: usize) -> Self {
        Queries { validators, length, joined: Mutex::new(Vec::new()), message: Message::new("Invalid queries") }
    }

    /// The validators, in order.
    pub fn validators(&self) -> &[Arc<dyn QueryValidator>] {
        &self.validators
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    fn fail(&self, message: impl Into<String>) -> bool {
        self.message.set(message);
        false
    }

    fn joined(&self) -> MutexGuard<'_, Vec<Joinable>> {
        self.joined.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// The collections the query lists may join, replacing those given
    /// before (`setJoinedCollections()`): a column under a join alias has to
    /// be valid on the alias's collection, a bare aggregate or groupBy
    /// attribute resolves through the one join that declares it, and a
    /// search on a join alias needs a fulltext index of that collection.
    ///
    /// Fails as [`crate::Collection::from_document`],
    /// [`crate::Collection::attributes`] and [`crate::Collection::indexes`]
    /// do (PHP fails when a list joins the collection).
    pub fn set_joined_collections(&self, collections: &[Document]) -> Result<()> {
        let mut joined: Vec<Joinable> = Vec::with_capacity(collections.len());
        for document in collections {
            let collection = crate::Collection::from_document(document)?;
            let joinable = Joinable {
                id: document.id().to_owned(),
                definition: Arc::new(Definition::of(&collection)?),
                indexes: collection.indexes()?.into(),
            };
            match joined.iter_mut().find(|j| j.id == joinable.id) {
                Some(existing) => *existing = joinable,
                None => joined.push(joinable),
            }
        }
        *self.joined() = joined;
        Ok(())
    }

    pub(crate) fn set_joinables(&self, joinables: Vec<Joinable>) {
        *self.joined() = joinables;
    }

    pub(crate) fn joinables(&self) -> Vec<Joinable> {
        self.joined().clone()
    }

    /// The collection a list may join, by id (`getJoinedCollection()`).
    pub(crate) fn joinable(&self, id: &str) -> Option<Joinable> {
        self.joined().iter().find(|j| j.id == id).cloned()
    }

    /// `isValid($value)`: `value` must be an array of queries (or query
    /// strings).
    pub fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::Array(queries) => self.check(&queries.values().collect::<Vec<_>>()),
            _ => self.fail("Queries must be an array"),
        }
    }

    /// [`Queries::is_valid`] of a list.
    pub fn check<T: QueryEntry>(&self, queries: &[T]) -> bool {
        self.run(queries).is_some()
    }

    /// Validates the list; the parsed queries when it is valid.
    pub(crate) fn run<'a, T: QueryEntry>(&self, queries: &'a [T]) -> Option<Vec<Cow<'a, utopia_query::Query>>> {
        if !self.is_valid_length(queries.len(), None) {
            return None;
        }

        let mut has_filter_validator = false;
        for validator in &self.validators {
            match known(validator.as_ref()) {
                Known::Order(v) => {
                    v.reset_aggregation_aliases();
                    v.reset_join_aliases();
                }
                Known::Select(v) => v.reset_join_aliases(),
                Known::Filter(v) => v.reset_join_aliases(),
                Known::Aggregate(v) => v.reset_join_aliases(),
                Known::GroupBy(v) => v.reset_join_aliases(),
                Known::Join(v) => v.reset_join_aliases(),
                Known::Having(_) | Known::Other => {}
            }
            if validator.method_type() == MethodType::Filter {
                has_filter_validator = true;
            }
        }

        let mut parsed: Vec<Cow<'a, utopia_query::Query>> = Vec::with_capacity(queries.len());
        let mut aggregation_aliases: Vec<&str> = Vec::new();
        let mut join_aliases: Vec<&str> = Vec::new();
        let mut parse_failure = None;
        for entry in queries {
            match parse_entry(entry.entry()) {
                Ok(query) => parsed.push(query),
                Err(message) => {
                    parse_failure = Some(format!("Invalid query: {message}"));
                    break;
                }
            }
        }
        let mut has_joins = false;
        for query in &parsed {
            let method = query.method();
            if method.is_aggregate() && !query.alias().is_empty() {
                aggregation_aliases.push(query.alias());
            }
            if method.is_join() {
                has_joins = true;
                join_aliases.push(query.alias());
            }
        }

        if has_joins {
            let joins = self.joins(&parsed);
            for validator in &self.validators {
                match known(validator.as_ref()) {
                    Known::Order(v) => v.allow_joins(&joins),
                    Known::Select(v) => v.allow_joins(&joins),
                    Known::Filter(v) => v.allow_joins(&joins),
                    Known::Aggregate(v) => v.allow_joins(&joins),
                    Known::GroupBy(v) => v.allow_joins(&joins),
                    Known::Join(v) => v.allow_joins(&joins),
                    Known::Having(_) | Known::Other => {}
                }
            }
        }

        if !aggregation_aliases.is_empty() || !join_aliases.is_empty() {
            for validator in &self.validators {
                let known = known(validator.as_ref());
                if !aggregation_aliases.is_empty()
                    && let Known::Order(v) = known
                {
                    v.add_aggregation_aliases(&aggregation_aliases);
                }
                if !join_aliases.is_empty() {
                    match known {
                        Known::Order(v) => v.allow_join_aliases(&join_aliases),
                        Known::Select(v) => v.allow_join_aliases(&join_aliases),
                        Known::Filter(v) => v.allow_join_aliases(&join_aliases),
                        Known::Aggregate(v) => v.allow_join_aliases(&join_aliases),
                        Known::GroupBy(v) => v.allow_join_aliases(&join_aliases),
                        _ => {}
                    }
                }
            }
        }

        if !self.is_valid_join_count(&parsed) {
            return None;
        }

        self.prepare_aggregations(&parsed);

        for query in &parsed {
            if !self.is_valid_query(query, has_filter_validator) {
                return None;
            }
        }

        if let Some(failure) = parse_failure {
            self.message.set(failure);
            return None;
        }
        Some(parsed)
    }

    /// Validates the children of a logical query before the query itself;
    /// a join's ON conditions after the join.
    fn is_valid_query(&self, query: &utopia_query::Query, has_filter_validator: bool) -> bool {
        if query.is_nested() && !self.is_valid_length(query.values().len(), Some(query.method())) {
            return false;
        }

        if LOGICAL_TYPES.contains(&query.method()) {
            for value in query.values().values() {
                let child = match value {
                    QValue::Ext(utopia_query::Object::Query(child)) => Cow::Borrowed(&**child),
                    QValue::Str(s) => match crate::Query::parse_bytes(s.as_bytes(), false) {
                        Ok(child) => Cow::Owned(child.into_inner()),
                        Err(e) => return self.fail(format!("Invalid query: {}", e.message())),
                    },
                    _ => return self.fail("Invalid query: nested query must be a string"),
                };
                if !self.is_valid_query(&child, has_filter_validator) {
                    return false;
                }
            }
        }

        let method = query.method();
        let method_type = method_type(method);
        let mut method_is_valid = false;
        for validator in &self.validators {
            if Some(validator.method_type()) != method_type {
                continue;
            }
            if !validator.check(query) {
                return self.fail(format!("Invalid query: {}", validator.description()));
            }
            method_is_valid = true;
        }

        if !method_is_valid {
            let prefix = if LEGACY_METHODS.contains(&method) { "" } else { "Invalid query: " };
            return self.fail(format!("{prefix}Invalid query method: {}", method.as_str()));
        }

        if has_filter_validator && method.is_join() && query.is_nested_join() {
            for condition in query.join_on_queries() {
                if condition.method() == Method::On {
                    continue;
                }
                if !self.is_valid_query(condition, has_filter_validator) {
                    return false;
                }
            }
        }
        true
    }

    /// The collection each join of the list reads, for the joins whose
    /// collection is given (`joins()`).
    fn joins(&self, queries: &[Cow<'_, utopia_query::Query>]) -> Vec<Arc<Collection>> {
        let joined = self.joined();
        queries
            .iter()
            .filter(|q| q.method().is_join())
            .filter_map(|q| {
                joined
                    .iter()
                    .find(|j| j.id == q.attribute())
                    .map(|j| Arc::new(Collection::new(q.alias(), j.definition.clone())))
            })
            .collect()
    }

    /// A group of queries, the whole list or the children of one nested
    /// query, may hold at most `length` queries (`isValidLength()`).
    fn is_valid_length(&self, count: usize, group: Option<Method>) -> bool {
        if self.length == 0 || count <= self.length {
            return true;
        }
        self.fail(match group {
            None => format!("Too many queries: at most {} are allowed", self.length),
            Some(method) => format!("Too many queries in {}: at most {} are allowed", method.as_str(), self.length),
        })
    }

    fn is_valid_join_count(&self, queries: &[Cow<'_, utopia_query::Query>]) -> bool {
        let count = queries.iter().filter(|q| q.method().is_join()).count();
        for validator in &self.validators {
            if let Known::Join(join) = known(validator.as_ref())
                && !join.is_valid_count(count)
            {
                return self.fail(join.description());
            }
        }
        true
    }

    /// Hands each having, aggregate, select and order validator the
    /// aggregates and groupBy attributes of the list, and each having
    /// validator the filter rules (`prepareAggregations()`).
    fn prepare_aggregations(&self, queries: &[Cow<'_, utopia_query::Query>]) {
        let mut filter: Option<Arc<dyn QueryValidator>> = None;
        let mut takes_aggregations = false;
        for validator in &self.validators {
            match known(validator.as_ref()) {
                Known::Filter(_) if filter.is_none() => filter = Some(validator.clone()),
                Known::Having(_) | Known::Aggregate(_) => takes_aggregations = true,
                _ => {}
            }
        }
        if !takes_aggregations {
            return;
        }

        let mut aggregations: Vec<&utopia_query::Query> = Vec::new();
        let mut group_by: Vec<QValue> = Vec::new();
        for query in queries {
            let method = query.method();
            if method.is_aggregate() {
                aggregations.push(query);
            } else if method == Method::GroupBy {
                group_by.extend(query.values().values().cloned());
            }
        }

        for validator in &self.validators {
            if let Known::Having(v) = known(validator.as_ref()) {
                v.set_filter(filter.clone());
                v.set_aggregations(&aggregations);
                v.set_group_by(&group_by);
            }
        }
        for validator in &self.validators {
            if let Known::Aggregate(v) = known(validator.as_ref()) {
                v.set_aggregations(&aggregations);
                v.set_group_by(&group_by);
            }
        }
        for validator in &self.validators {
            match known(validator.as_ref()) {
                Known::Select(v) => {
                    v.set_aggregations(&aggregations);
                    v.set_group_by(&group_by);
                }
                Known::Order(v) => {
                    v.set_aggregations(&aggregations);
                    v.set_group_by(&group_by);
                }
                _ => {}
            }
        }
    }

    /// `isArray()`: true.
    pub fn is_array(&self) -> bool {
        true
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }
}

/// The nested queries among a query's values.
pub(crate) fn children(query: &utopia_query::Query) -> impl Iterator<Item = &utopia_query::Query> {
    query.values().values().filter_map(nested)
}

crate::validator::validator!(Queries, utopia_validators::Type::Object, true);
