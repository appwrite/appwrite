//! Builder extensions a library on top of this one compiles with, set on a
//! builder rather than subclassed: a [`Flavor`] changes how some filters
//! compile, and a [`Scope`] makes `from()` take a collection id.
//!
//! PHP subclasses the builders for these (`Utopia\Database\Builder\MySQL`,
//! `MariaDB`, `Postgres` and `SQLite` override protected compile methods and
//! use the `ScopesCollections` trait). Rust builders are generic over their
//! dialect, so the subclasses' behaviour is opt-in state on the builder,
//! which [`Builder::reset`] keeps:
//!
//! | PHP (`utopia-php/database`) | Rust |
//! |---|---|
//! | `Builder\PreparesSearchTerms` | [`Flavor::search_terms`] |
//! | `Builder\MatchesContainsAllPatterns` | [`Flavor::contains_all_patterns`] |
//! | `Builder\Postgres::compileFilter()` (object paths) | [`Flavor::object_path`] |
//! | `Builder\Postgres::compileSpatialFilter()` | [`Flavor::indexed_distance`] |
//! | `Builder\SQLite` | [`Flavor::sqlite`] ([`SqliteFlavor`]) |
//! | `Builder\CompilesFilters`, `Builder\Filtering` | [`Builder::compile_filters`] |
//! | `Builder\Scope` | [`Scope`] |
//! | `Builder\ScopesCollections`, `Builder\Scoping`, `Builder\RefusesJoinedWrites` | [`Builder::scope`], [`Builder::is_scoped`], [`Builder::from_table`] and the checks of `from()`, `into()`, `build()` and the multi-table writes |
//!
//! PHP throws from `from()`, `into()` and the multi-table writes when a
//! scoped builder refuses them; these return the builder here, so the
//! refusal is kept and returned by the next statement the builder compiles
//! (`build()`, `insert()`, `update()`, `delete()`, `upsert()`, ...).

use std::sync::Arc;

use php_std::pcre::{StrOrArray, preg_replace};

use crate::builder::core::{Builder, Dialect};
use crate::builder::types::{Condition, Statement};
use crate::error::{Error, Result};
use crate::hook::Hook;
use crate::method::Method;
use crate::query::Query;
use crate::value::{self, Array, Value};

/// How a builder compiles some filters, as `utopia-php/database`'s builder
/// subclasses do. The default compiles as this library does.
#[derive(Clone, Default)]
pub struct Flavor {
    /// Prepares full-text search terms as 7.x did: every character other
    /// than a letter, a number, an underscore or whitespace separates words,
    /// and a term wrapped in double quotes stays an exact search (MySQL,
    /// MariaDB, PostgreSQL).
    ///
    /// PHP: `Utopia\Database\Builder\PreparesSearchTerms`.
    pub search_terms: bool,
    /// `containsAll()` on an attribute that is not an array matches as 7.x
    /// did: each value is a `LIKE` pattern on the whole value, and a row
    /// matching any one of them is returned (MySQL, MariaDB, PostgreSQL).
    ///
    /// PHP: `Utopia\Database\Builder\MatchesContainsAllPatterns`.
    pub contains_all_patterns: bool,
    /// Checks the path of a filter into an object attribute
    /// (`attribute.key.key`) before it compiles (PostgreSQL); its error is
    /// the filter's.
    ///
    /// PHP: `Utopia\Database\Builder\Postgres::compileFilter()`.
    pub object_path: Option<Arc<dyn Fn(&str) -> Result<()> + Send + Sync>>,
    /// `distanceLessThan()` leads with a predicate the spatial index serves,
    /// in this SRID (PostgreSQL).
    ///
    /// PHP: `Utopia\Database\Builder\Postgres::compileSpatialFilter()`.
    pub indexed_distance: Option<i64>,
    /// SQLite's overrides.
    pub sqlite: Option<SqliteFlavor>,
}

impl std::fmt::Debug for Flavor {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Flavor")
            .field("search_terms", &self.search_terms)
            .field("contains_all_patterns", &self.contains_all_patterns)
            .field("object_path", &self.object_path.is_some())
            .field("indexed_distance", &self.indexed_distance)
            .field("sqlite", &self.sqlite)
            .finish()
    }
}

/// SQLite as `utopia-php/database` compiles it: every `LIKE` declares the
/// backslash escape, `REGEXP` calls the user function the adapter
/// registers, array filters match JSON elements, and every equality on a
/// collated column compares in that collation (SQLite only uses an index
/// for a comparison in the index's collation).
///
/// PHP: `Utopia\Database\Builder\SQLite`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct SqliteFlavor {
    /// The collation of the collated columns (`NOCASE`).
    pub collation: String,
    /// The columns compared in it (`_uid`, `_document`).
    pub collated_columns: Vec<String>,
}

/// How a builder's `from()` reads a collection: the table it is stored
/// under, and the hooks that map its attributes to columns and keep its
/// statements to a tenant.
///
/// PHP: `Utopia\Database\Builder\Scope`.
pub trait Scope: Send + Sync {
    /// The name the collection's table is stored under (`table()`).
    fn table(&self, collection: &str) -> String;

    /// The name a builder that read a collection through this scope joins
    /// `table` under (`joinTable()`).
    fn join_table(&self, table: &str) -> String;

    /// The hooks for statements over the collection, stored as `table` and
    /// named `alias` in them when one is given, and what the builder runs
    /// before each statement it builds, which `reset()` does not clear
    /// (`bind()`).
    fn bind(&self, collection: &str, table: &str, alias: &str) -> Result<ScopeBinding>;

    /// The error a scoped builder refuses a call with (PHP throws
    /// `Utopia\Database\Exception\Query`); a plain query error by default.
    fn error(&self, message: String) -> Error {
        Error::new(message, 0)
    }
}

/// What [`Scope::bind`] registers on a builder.
#[derive(Clone, Default)]
pub struct ScopeBinding {
    /// The hooks to add.
    pub hooks: Vec<Hook>,
    /// What runs before each statement the builder builds.
    pub before_each_build: Vec<Arc<dyn Fn() + Send + Sync>>,
}

impl std::fmt::Debug for ScopeBinding {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("ScopeBinding")
            .field("hooks", &self.hooks.len())
            .field("before_each_build", &self.before_each_build.len())
            .finish()
    }
}

/// A builder's scope, and the collection it read through it.
#[derive(Clone)]
pub(crate) struct ScopeState {
    scope: Arc<dyn Scope>,
    bound: Option<(String, String)>,
    before_each_build: Vec<Arc<dyn Fn() + Send + Sync>>,
}

impl<D: Dialect> Builder<D> {
    /// Sets the builder's [`Flavor`].
    pub fn set_flavor(&mut self, flavor: Flavor) -> &mut Self {
        self.core.flavor = flavor;
        self
    }

    /// The builder's [`Flavor`].
    pub fn flavor(&self) -> &Flavor {
        &self.core.flavor
    }

    /// Reads collections through `scope`: `from()` then takes a collection
    /// id.
    ///
    /// Fails once `from()` has read a collection through a scope.
    ///
    /// PHP: `Scoping::scope()`.
    pub fn scope(&mut self, scope: Arc<dyn Scope>) -> Result<&mut Self> {
        self.require_unbound("take another scope")?;
        self.core.scope = Some(ScopeState { scope, bound: None, before_each_build: Vec::new() });
        Ok(self)
    }

    /// Whether the builder has a scope.
    ///
    /// PHP: `Scoping::isScoped()`.
    pub fn is_scoped(&self) -> bool {
        self.core.scope.is_some()
    }

    /// The collection and alias the builder read through its scope.
    pub fn bound_collection(&self) -> Option<(&str, &str)> {
        self.core.scope.as_ref().and_then(|s| s.bound.as_ref()).map(|(c, a)| (c.as_str(), a.as_str()))
    }

    /// Reads the table stored under the name as it is, kept to no tenant.
    ///
    /// Fails once `from()` has read a collection through the scope.
    ///
    /// PHP: `Scoping::fromTable()`.
    pub fn from_table(&mut self, table: impl Into<String>, alias: impl Into<String>) -> Result<&mut Self> {
        let table = table.into();
        self.require_unbound(&format!("read table '{table}'"))?;
        Ok(self.plain_from(table, alias.into()))
    }

    /// The filters joined by `AND`, each compiled as `build()` compiles the
    /// filters of its `WHERE`, with their bindings in order. The builder's
    /// bindings are replaced by theirs.
    ///
    /// PHP: `Builder\Filtering::compileFilters()` (`CompilesFilters`).
    pub fn compile_filters(&mut self, filters: &[Query]) -> Result<Condition> {
        self.core.bindings.clear();
        self.core.resolved.clear();
        let mut expressions = Vec::with_capacity(filters.len());
        for filter in filters {
            expressions.push(D::compile_filter(self, filter)?);
        }
        Ok(Condition::new(expressions.join(" AND "), self.binding_values()))
    }

    pub(crate) fn plain_from(&mut self, table: String, alias: String) -> &mut Self {
        self.core.table = table;
        self.core.alias = alias;
        self.core.from_subquery = None;
        self.core.tableless = self.core.table.is_empty();
        self
    }

    /// `ScopesCollections::from()`: through the scope once it has one.
    pub(crate) fn scoped_from(&mut self, table: String, alias: String) -> &mut Self {
        let Some(state) = self.core.scope.clone() else {
            return self.plain_from(table, alias);
        };
        if table.is_empty() {
            return self.plain_from(table, alias);
        }
        if let Some((collection, bound_alias)) = &state.bound {
            if &table != collection || &alias != bound_alias {
                let mut message = format!("The builder reads collection '{collection}'");
                if !bound_alias.is_empty() {
                    message.push_str(&format!(" as '{bound_alias}'"));
                }
                message.push_str(&format!(", not '{table}'"));
                if !alias.is_empty() {
                    message.push_str(&format!(" as '{alias}'"));
                }
                message.push_str(": start another builder");
                self.defer(state.scope.error(message));
                return self;
            }
            let stored = state.scope.table(&table);
            return self.plain_from(stored, alias);
        }
        let stored = state.scope.table(&table);
        self.plain_from(stored.clone(), alias.clone());
        match state.scope.bind(&table, &stored, &alias) {
            Ok(binding) => {
                for hook in binding.hooks {
                    if let Err(e) = D::add_hook(self, hook) {
                        self.defer(e);
                    }
                }
                if let Some(state) = self.core.scope.as_mut() {
                    state.bound = Some((table, alias));
                    state.before_each_build = binding.before_each_build;
                }
            }
            Err(e) => self.defer(e),
        }
        self
    }

    /// Fails with the scope's error once `from()` has read a collection
    /// through it (`requireUnbound()`).
    pub(crate) fn require_unbound(&self, action: &str) -> Result<()> {
        match &self.core.scope {
            Some(ScopeState { scope, bound: Some((collection, _)), .. }) => Err(scope.error(format!(
                "The builder reads collection '{collection}' and cannot {action}: start another builder"
            ))),
            _ => Ok(()),
        }
    }

    /// [`Builder::require_unbound`] for a call that returns the builder: the
    /// refusal waits for the next statement.
    pub(crate) fn defer_unbound(&mut self, action: &str) {
        if let Err(e) = self.require_unbound(action) {
            self.defer(e);
        }
    }

    fn defer(&mut self, error: Error) {
        if self.core.deferred.is_none() {
            self.core.deferred = Some(error);
        }
    }

    /// The refusal kept from an earlier call, if any.
    pub(crate) fn check_deferred(&self) -> Result<()> {
        match &self.core.deferred {
            Some(e) => Err(e.clone()),
            None => Ok(()),
        }
    }

    /// `ScopesCollections::build()`: the scope's callbacks, then the joins
    /// named as the scope joins their tables, for this statement only.
    pub(crate) fn scoped_build(&mut self) -> Result<Statement> {
        self.check_deferred()?;
        let Some(state) = self.core.scope.clone() else {
            return D::build(self);
        };
        if state.bound.is_none() {
            return D::build(self);
        }
        for callback in &state.before_each_build {
            callback();
        }
        let written = self.core.pending.clone();
        self.core.pending = written
            .iter()
            .map(|query| {
                if !query.method().is_join() {
                    return query.clone();
                }
                let table = state.scope.join_table(query.attribute());
                if table == query.attribute() {
                    query.clone()
                } else {
                    Query::new(query.method(), table, query.values().clone(), query.alias())
                }
            })
            .collect();
        let result = D::build(self);
        self.core.pending = written;
        result
    }

    // ----- PreparesSearchTerms ------------------------------------------

    /// The values of a search with the term prepared as
    /// [`Flavor::search_terms`] says, when it is set.
    pub(crate) fn flavored_search_values(&self, values: &Array) -> Option<Array> {
        if !self.core.flavor.search_terms {
            return None;
        }
        let term = match values.get(&php_std::ArrayKey::Int(0)) {
            Some(Value::Str(s)) => s.as_bytes().to_vec(),
            _ => Vec::new(),
        };
        Some(value::list([Value::Str(prepare_search_term(&term).into())]))
    }

    // ----- MatchesContainsAllPatterns -----------------------------------

    /// `containsAll()` as [`Flavor::contains_all_patterns`] says, when it is
    /// set.
    pub(crate) fn flavored_contains_all(
        &mut self,
        attribute: &str,
        values: &Array,
        column: Option<&str>,
    ) -> Option<String> {
        if !self.core.flavor.contains_all_patterns {
            return None;
        }
        let like = D::like_keyword(self);
        let mut parts = Vec::with_capacity(values.len());
        for value in values.values() {
            self.add_binding(value.clone(), column);
            parts.push(format!("{attribute} {like} ?"));
        }
        Some(format!("({})", parts.join(" OR ")))
    }

    // ----- SQLite -------------------------------------------------------

    /// Whether the column resolves to a collated column.
    pub(crate) fn is_collated(&mut self, column: &str) -> Result<bool> {
        let Some(sqlite) = self.core.flavor.sqlite.clone() else {
            return Ok(false);
        };
        let resolved = self.resolve_attribute(column)?;
        let name = match resolved.rfind('.') {
            Some(i) => &resolved[i + 1..],
            None => resolved.as_str(),
        };
        Ok(sqlite.collated_columns.iter().any(|c| c == name))
    }

    /// The attribute in the collation when its column is collated
    /// (`SQLite::collate()`).
    pub(crate) fn collate(&mut self, attribute: &str, column: Option<&str>) -> Result<String> {
        let Some(sqlite) = self.core.flavor.sqlite.clone() else {
            return Ok(attribute.to_owned());
        };
        match column {
            Some(column) if self.is_collated(column)? => Ok(format!("{attribute} COLLATE {}", sqlite.collation)),
            _ => Ok(attribute.to_owned()),
        }
    }

    /// `SQLite::compileJoin()`: every `=`, `!=` and `<>` comparison of the
    /// ON with a collated side compares in the collation.
    pub(crate) fn collate_join(&mut self, query: &Query, mut sql: String) -> Result<String> {
        let Some(sqlite) = self.core.flavor.sqlite.clone() else {
            return Ok(sql);
        };
        let collate = format!(" COLLATE {}", sqlite.collation);
        for (left, operator, right) in on_comparisons(&query.join_on_queries()) {
            if !matches!(operator.as_str(), "=" | "!=" | "<>") || (!self.is_collated(&left)? && !self.is_collated(&right)?)
            {
                continue;
            }
            let comparison = format!(" {operator} {}", self.resolve_and_wrap(&right)?);
            let wrapped_left = self.resolve_and_wrap(&left)?;
            sql = sql.replace(
                &format!("{wrapped_left}{comparison}"),
                &format!("{wrapped_left}{collate}{comparison}"),
            );
        }
        Ok(sql)
    }

    /// `compileLike()` with SQLite's `ESCAPE '\'`.
    pub(crate) fn sqlite_like(
        &mut self,
        attribute: &str,
        values: &Array,
        prefix: &str,
        suffix: &str,
        not: bool,
        column: Option<&str>,
    ) -> Result<String> {
        Ok(self.base_compile_like(attribute, values, prefix, suffix, not, column)? + " ESCAPE '\\'")
    }

    /// `SQLite::compileSubstrings()`.
    pub(crate) fn sqlite_substrings(
        &mut self,
        attribute: &str,
        values: &Array,
        not: bool,
        column: Option<&str>,
    ) -> Result<Vec<String>> {
        let mut predicates = Vec::with_capacity(values.len());
        for value in values.values() {
            predicates.push(self.sqlite_like(attribute, &value::list([value.clone()]), "%", "%", not, column)?);
        }
        Ok(predicates)
    }

    /// `SQLite::compileElementMatches()`.
    pub(crate) fn sqlite_element_matches(&mut self, attribute: &str, needles: &Value) -> Result<Vec<String>> {
        let needles: Vec<Value> = match needles {
            Value::Array(items) => items.values().cloned().collect(),
            Value::Null => Vec::new(),
            Value::Object(items) => items.values().cloned().collect(),
            other => vec![other.clone()],
        };
        let mut matches = Vec::with_capacity(needles.len());
        for needle in &needles {
            let json = value::json_encode(needle, php_std::json::Flags::THROW_ON_ERROR)
                .map_err(|e| Error::Validation(format!("Invalid JSON payload: {}", e.message())))?;
            self.add_binding(value::string(json), None);
            matches.push(format!(
                "EXISTS (SELECT 1 FROM json_each({attribute}) WHERE json_each.value = json_extract(?, '$'))"
            ));
        }
        Ok(matches)
    }
}

impl Builder<crate::builder::PostgreSqlDialect> {
    /// `Builder\Postgres::compileSpatialFilter()` for `distanceLessThan()`:
    /// `ST_DWithin()` on the geometry, or for a distance in meters from a
    /// point to a point column a degree box around the point, then the exact
    /// `ST_Distance()` check.
    pub(crate) fn indexed_distance(&mut self, attribute: &str, query: &Query, srid: i64) -> Result<String> {
        let tuple = match query.values().get(&php_std::ArrayKey::Int(0)) {
            Some(Value::Array(tuple)) => tuple.clone(),
            _ => Array::new(),
        };
        let filter = crate::builder::SpatialDistanceFilter::from_tuple(&tuple)?;
        let wkt = crate::builder::sql::wkt_of(&filter.geometry, POSTGRES)?;
        let geometry = format!("ST_GeomFromText(?, {srid})");
        if !filter.meters {
            self.add_binding(wkt.clone(), None);
            self.add_binding(Value::Float(filter.distance), None);
            self.add_binding(wkt, None);
            self.add_binding(Value::Float(filter.distance), None);
            return Ok(format!(
                "(ST_DWithin({attribute}, {geometry}, ?) AND ST_Distance({attribute}, {geometry}) < ?)"
            ));
        }
        let distance = format!(
            "ST_Distance(({attribute}::geography), ST_SetSRID(ST_GeomFromText(?), {srid})::geography) < ?"
        );
        let degrees = if query.attribute_type() == "point" {
            degrees_within_meters(&filter.geometry, filter.distance)
        } else {
            None
        };
        let Some((longitude, latitude)) = degrees else {
            self.add_binding(wkt, None);
            self.add_binding(Value::Float(filter.distance), None);
            return Ok(distance);
        };
        self.add_binding(wkt.clone(), None);
        self.add_binding(Value::Float(longitude), None);
        self.add_binding(Value::Float(latitude), None);
        self.add_binding(wkt, None);
        self.add_binding(Value::Float(filter.distance), None);
        Ok(format!("({attribute} && ST_Expand({geometry}, ?, ?) AND {distance})"))
    }
}

/// The class PHP names the closures of `Builder\Postgres` after.
const POSTGRES: &str = "Utopia\\Database\\Builder\\Postgres";

/// A degree of latitude spans at least this many meters.
const METERS_PER_LATITUDE_DEGREE: f64 = 110574.0;

/// A degree of longitude at the equator spans at least this many meters.
const METERS_PER_EQUATORIAL_LONGITUDE_DEGREE: f64 = 111319.0;

/// `Builder\Postgres::degreesWithinMeters()`: the longitude and latitude
/// degrees that hold every point within `meters` of `point`; `None` for a
/// line or polygon, a distance that is not finite, and a box reaching a pole
/// or the antimeridian.
fn degrees_within_meters(point: &Value, meters: f64) -> Option<(f64, f64)> {
    let Value::Array(point) = point else {
        return None;
    };
    let at = |i: i64| point.get(&php_std::ArrayKey::Int(i));
    if !meters.is_finite()
        || point.len() != 2
        || !at(0).is_some_and(crate::query::is_numeric)
        || !at(1).is_some_and(crate::query::is_numeric)
    {
        return None;
    }
    let longitude = at(0).map(Value::to_double).unwrap_or(0.0);
    let latitude = at(1).map(Value::to_double).unwrap_or(0.0);
    let latitude_degrees = meters / METERS_PER_LATITUDE_DEGREE;
    if latitude.abs() + latitude_degrees >= 90.0 {
        return None;
    }
    let longitude_degrees =
        meters / (METERS_PER_EQUATORIAL_LONGITUDE_DEGREE * ((latitude.abs() + latitude_degrees) / 180.0 * std::f64::consts::PI).cos());
    if longitude - longitude_degrees <= -180.0 || longitude + longitude_degrees >= 180.0 {
        return None;
    }
    Some((longitude_degrees, latitude_degrees))
}

/// `SQLite::onComparisons()`: the `[left, operator, right]` of every `on`
/// among the queries, through `and` and `or`.
fn on_comparisons(queries: &[&Query]) -> Vec<(String, String, String)> {
    let mut comparisons = Vec::new();
    for query in queries {
        match query.method() {
            Method::On => {
                let at = |i: i64| query.values().get(&php_std::ArrayKey::Int(i)).cloned().unwrap_or(Value::Null);
                if let (Value::Str(left), Value::Str(operator), Value::Str(right)) = (at(0), at(1), at(2))
                    && !left.is_empty()
                    && !right.is_empty()
                {
                    comparisons.push((
                        String::from_utf8_lossy(left.as_bytes()).into_owned(),
                        String::from_utf8_lossy(operator.as_bytes()).into_owned(),
                        String::from_utf8_lossy(right.as_bytes()).into_owned(),
                    ));
                }
            }
            Method::And | Method::Or => {
                let nested: Vec<&Query> = query.nested().collect();
                comparisons.extend(on_comparisons(&nested));
            }
            _ => {}
        }
    }
    comparisons
}

/// `PreparesSearchTerms::prepareSearchTerm()`.
pub(crate) fn prepare_search_term(term: &[u8]) -> Vec<u8> {
    let words = search_words(term);
    let exact = term.starts_with(b"\"") && term.ends_with(b"\"");
    if exact && !words.is_empty() {
        let mut quoted = Vec::with_capacity(words.len() + 2);
        quoted.push(b'"');
        quoted.extend_from_slice(&words);
        quoted.push(b'"');
        return quoted;
    }
    words
}

/// `PreparesSearchTerms::searchWords()`: separators to spaces, runs of
/// whitespace to one space, trimmed (`preg_replace` failing gives `''`).
fn search_words(term: &[u8]) -> Vec<u8> {
    let replace = |pattern: &[u8], replacement: &[u8], subject: &[u8]| -> Vec<u8> {
        match preg_replace(&StrOrArray::Str(pattern), &StrOrArray::Str(replacement), &StrOrArray::Str(subject), -1) {
            Ok(preg) => match preg.value.result {
                php_std::pcre::Value::Str(s) => s,
                _ => Vec::new(),
            },
            Err(_) => Vec::new(),
        }
    };
    let words = replace(b"/[^\\p{L}\\p{N}_\\s]/u", b" ", term);
    let words = replace(b"/\\s+/", b" ", &words);
    php_std::string::trim(&words, b" \n\r\t\x0b\0").to_vec()
}
