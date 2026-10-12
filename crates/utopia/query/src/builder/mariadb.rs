//! MariaDB: [`MariaDb`], MySQL with `RETURNING`, sequences, its own geometry
//! constructor and `ST_DISTANCE_SPHERE` for distances in meters.
//!
//! PHP: `Utopia\Query\Builder\MariaDB`.

use crate::builder::core::{Builder, Dialect, ExplainOptions, first};
use crate::builder::feature::{
    BitwiseAggregates, ConditionalAggregates, CrossJoins, FullTextSearch, Hints, InsertOrIgnore, Json, LateralJoins,
    Locking, NegatedFullTextSearch, RawSql, Returning, Rollup, Sequences, Spatial, StatisticalAggregates,
    StringAggregates, Transactions, Upsert, UpsertSelect,
};
use crate::builder::mysql::MYSQL_CLASS;
use crate::builder::mysql::{self, MySqlFamily, MySqlState, conflict_clause, json_sets};
use crate::builder::sql::{self, SqlDialect, distance_operator, wkt_of};
use crate::builder::types::Statement;
use crate::error::{Error, Result};
use crate::method::Method;
use crate::query::Query;
use crate::value::{Array, Value};

/// A MariaDB builder.
///
/// PHP: `Utopia\Query\Builder\MariaDB`.
///
/// ```
/// use utopia_query::Query;
/// use utopia_query::builder::MariaDb;
///
/// let statement = MariaDb::new()
///     .from("users", "")
///     .filter([Query::equal("id", [1])])
///     .returning(["id"])
///     .delete()?;
/// assert_eq!(statement.query, "DELETE FROM `users` WHERE `id` IN (?) RETURNING `id`");
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Builder\MariaDB;
/// use Utopia\Query\Query;
///
/// echo (new MariaDB())->from('users')->filter([Query::equal('id', [1])])->returning(['id'])->delete()->query, "\n";
/// // DELETE FROM `users` WHERE `id` IN (?) RETURNING `id`
/// ```
pub type MariaDb = Builder<MariaDbDialect>;

/// The MariaDB dialect: MySQL's state.
#[derive(Debug, Clone, Default)]
pub struct MariaDbDialect {
    state: MySqlState,
}

const NO_RETURNING_UPSERT: &str = "MariaDB does not support RETURNING with ON DUPLICATE KEY UPDATE. \
     Call returning([]) to clear before upsert(), or use a separate update() statement.";

impl MySqlFamily for MariaDbDialect {
    fn mysql(b: &Builder<Self>) -> &MySqlState {
        &b.dialect.state
    }

    fn mysql_mut(b: &mut Builder<Self>) -> &mut MySqlState {
        &mut b.dialect.state
    }

    fn geom_from_text(srid: i64) -> String {
        format!("ST_GeomFromText(?, {srid})")
    }
}

impl Dialect for MariaDbDialect {
    const CLASS: &'static str = "Utopia\\Query\\Builder\\MariaDB";

    fn compile_random(_b: &Builder<Self>) -> String {
        "RAND()".into()
    }

    fn compile_regex(b: &mut Builder<Self>, attribute: &str, values: &Array, column: Option<&str>) -> Result<String> {
        b.add_binding(first(values), column);
        Ok(format!("{attribute} REGEXP ?"))
    }

    fn compile_filter(b: &mut Builder<Self>, query: &Query) -> Result<String> {
        sql::sql_compile_filter(b, query)
    }

    fn build(b: &mut Builder<Self>) -> Result<Statement> {
        mysql::build(b)
    }

    fn insert(b: &mut Builder<Self>) -> Result<Statement> {
        let result = b.base_insert()?;
        b.append_returning(result)
    }

    fn update(b: &mut Builder<Self>) -> Result<Statement> {
        let result = mysql::update(b)?;
        b.append_returning(result)
    }

    fn delete(b: &mut Builder<Self>) -> Result<Statement> {
        let result = mysql::delete(b)?;
        b.append_returning(result)
    }

    fn insert_default_values(b: &mut Builder<Self>) -> Result<Statement> {
        mysql::insert_default_values(b)
    }

    fn explain(b: &mut Builder<Self>, analyze: bool, options: &ExplainOptions) -> Result<Statement> {
        mysql::explain(b, analyze, options)
    }

    fn reset(b: &mut Builder<Self>) {
        mysql::reset(b);
        b.core.returning.clear();
    }

    fn ast_dialect() -> crate::builder::AstDialect {
        crate::builder::AstDialect::MariaDb
    }
}

impl SqlDialect for MariaDbDialect {
    fn compile_search_expression(b: &mut Builder<Self>, attribute: &str, values: &Array, not: bool) -> Result<String> {
        mysql::search_expression(b, attribute, values, not)
    }

    fn compile_spatial_filter(b: &mut Builder<Self>, method: Method, attribute: &str, query: &Query) -> Result<String> {
        use Method::*;
        if matches!(method, DistanceLessThan | DistanceGreaterThan | DistanceEqual | DistanceNotEqual) {
            let filter = sql::distance_filter(query.values(), "Builder/MariaDB.php", 83)?;
            if filter.meters && !query.attribute_type().is_empty() {
                let wkt = crate::value::to_php_string(&wkt_of(&filter.geometry, MYSQL_CLASS)?);
                let wkt_type = match wkt.find('(') {
                    Some(position) => {
                        wkt[..position].trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B']).to_ascii_lowercase()
                    }
                    None => String::new(),
                };
                let attribute_type = query.attribute_type().to_ascii_lowercase();
                if wkt_type != "point" || attribute_type != "point" {
                    return Err(Error::Validation(format!(
                        "Distance in meters is not supported between {attribute_type} and {wkt_type}"
                    )));
                }
            }
        }
        sql::sql_compile_spatial_filter(b, method, attribute, query)
    }

    fn compile_spatial_distance(
        b: &mut Builder<Self>,
        method: Method,
        attribute: &str,
        values: &Array,
    ) -> Result<String> {
        let filter = sql::distance_filter(values, "Builder/MariaDB.php", 111)?;
        let operator = distance_operator(method);
        b.add_binding(wkt_of(&filter.geometry, MYSQL_CLASS)?, None);
        b.add_binding(Value::Float(filter.distance), None);
        if filter.meters {
            return Ok(format!("ST_DISTANCE_SPHERE({attribute}, ST_GeomFromText(?, 4326)) {operator} ?"));
        }
        Ok(format!("ST_Distance({attribute}, ST_GeomFromText(?, 4326)) {operator} ?"))
    }

    fn compile_spatial_predicate(
        b: &mut Builder<Self>,
        function: &str,
        attribute: &str,
        values: &Array,
        not: bool,
    ) -> Result<String> {
        mysql::spatial_predicate(b, function, attribute, values, not)
    }

    fn compile_spatial_covers_predicate(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        not: bool,
    ) -> Result<String> {
        mysql::spatial_predicate(b, "ST_Contains", attribute, values, not)
    }

    fn compile_json_contains_expression(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        not: bool,
    ) -> Result<String> {
        mysql::json_contains(b, attribute, values, not)
    }

    fn compile_json_overlaps_expression(b: &mut Builder<Self>, attribute: &str, values: &Array) -> Result<String> {
        mysql::json_overlaps(b, attribute, values)
    }

    fn compile_json_path_expression(b: &mut Builder<Self>, attribute: &str, values: &Array) -> Result<String> {
        mysql::json_path(b, attribute, values)
    }

    fn compile_conflict_header(_b: &mut Builder<Self>) -> Result<String> {
        Ok("ON DUPLICATE KEY UPDATE".into())
    }

    fn compile_conflict_assignment(wrapped: &str) -> String {
        format!("VALUES({wrapped})")
    }
}

impl Locking for MariaDbDialect {}
impl Transactions for MariaDbDialect {}
impl StatisticalAggregates for MariaDbDialect {}
impl BitwiseAggregates for MariaDbDialect {}
impl RawSql for MariaDbDialect {}
impl CrossJoins for MariaDbDialect {}
impl LateralJoins for MariaDbDialect {}
impl Spatial for MariaDbDialect {}
impl FullTextSearch for MariaDbDialect {}
impl NegatedFullTextSearch for MariaDbDialect {}
impl ConditionalAggregates for MariaDbDialect {}
impl Returning for MariaDbDialect {}

impl Json for MariaDbDialect {
    fn set_json_append(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        json_sets::append(b, column, values)
    }
    fn set_json_prepend(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        json_sets::prepend(b, column, values)
    }
    fn set_json_insert(b: &mut Builder<Self>, column: &str, index: i64, value: Value) -> Result<()> {
        json_sets::insert(b, column, index, value)
    }
    fn set_json_remove(b: &mut Builder<Self>, column: &str, value: Value) -> Result<()> {
        json_sets::remove(b, column, value)
    }
    fn set_json_intersect(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        json_sets::intersect(b, column, values)
    }
    fn set_json_diff(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        json_sets::diff(b, column, values)
    }
    fn set_json_unique(b: &mut Builder<Self>, column: &str) -> Result<()> {
        json_sets::unique(b, column)
    }
    fn set_json_path(b: &mut Builder<Self>, column: &str, path: &str, value: Value) -> Result<()> {
        json_sets::path(b, column, path, value)
    }
}

impl Hints for MariaDbDialect {
    fn hint(b: &mut Builder<Self>, hint: &str) -> Result<()> {
        mysql::add_hint(b, hint)
    }
}

impl StringAggregates for MariaDbDialect {
    fn group_concat_expr(column: &str, order_by: &str) -> String {
        if order_by.is_empty() {
            format!("GROUP_CONCAT({column} SEPARATOR ?)")
        } else {
            format!("GROUP_CONCAT({column} {order_by} SEPARATOR ?)")
        }
    }
    fn json_array_agg_expr(column: &str) -> String {
        format!("JSON_ARRAYAGG({column})")
    }
    fn json_object_agg_expr(key: &str, value: &str) -> String {
        format!("JSON_OBJECTAGG({key}, {value})")
    }
}

impl Rollup for MariaDbDialect {
    fn with_rollup(b: &mut Builder<Self>) {
        b.core.group_by_modifier = Some("WITH ROLLUP".into());
    }
}

impl Upsert for MariaDbDialect {
    fn upsert(b: &mut Builder<Self>) -> Result<Statement> {
        if !b.core.returning.is_empty() {
            return Err(Error::Validation(NO_RETURNING_UPSERT.into()));
        }
        b.compile_upsert(conflict_clause)
    }
}

impl UpsertSelect for MariaDbDialect {
    fn upsert_select(b: &mut Builder<Self>) -> Result<Statement> {
        if !b.core.returning.is_empty() {
            return Err(Error::Validation(NO_RETURNING_UPSERT.into()));
        }
        b.compile_upsert_select(conflict_clause)
    }
}

impl InsertOrIgnore for MariaDbDialect {
    fn insert_or_ignore(b: &mut Builder<Self>) -> Result<Statement> {
        let result = mysql::insert_or_ignore(b)?;
        b.append_returning(result)
    }
}

impl Sequences for MariaDbDialect {
    fn sequence_expression(b: &Builder<Self>, next: bool, sequence: &str) -> Result<String> {
        Ok(format!("{}({})", if next { "NEXTVAL" } else { "LASTVAL" }, b.quote(sequence)?))
    }
}
