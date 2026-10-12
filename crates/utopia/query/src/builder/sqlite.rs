//! SQLite: [`Sqlite`], with `INSERT OR IGNORE`, `ON CONFLICT ... excluded`,
//! `json_each` JSON filters and updates, and no regex, full-text or spatial
//! filters.
//!
//! PHP: `Utopia\Query\Builder\SQLite`.

use crate::builder::core::{Builder, Dialect};
use crate::builder::feature::{
    BitwiseAggregates, ConditionalAggregates, CrossJoins, InsertOrIgnore, Json, Locking, RawSql, StatisticalAggregates,
    StringAggregates, Transactions, Upsert, UpsertSelect,
};
use crate::builder::mysql::conflict_clause;
use crate::builder::postgresql::{json_binding, json_path_parts, on_conflict_header};
use crate::builder::sql::{self, SqlDialect};
use crate::builder::types::{Condition, Statement};
use crate::error::{Error, Result};
use crate::method::Method;
use crate::query::Query;
use crate::value::{self, Array, Value};

/// A SQLite builder.
///
/// PHP: `Utopia\Query\Builder\SQLite`.
///
/// ```
/// use utopia_query::Query;
/// use utopia_query::builder::Sqlite;
///
/// let statement = Sqlite::new().from("users", "").filter([Query::regex("name", "^a")]).build();
/// assert_eq!(statement.unwrap_err().to_string(), "REGEXP is not natively supported in SQLite.");
///
/// let statement = Sqlite::new().from("users", "").sort_random().limit(5).build()?;
/// assert_eq!(statement.query, "SELECT * FROM `users` ORDER BY RANDOM() LIMIT ?");
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Builder\SQLite;
/// use Utopia\Query\Query;
///
/// try {
///     (new SQLite())->from('users')->filter([Query::regex('name', '^a')])->build();
/// } catch (\Utopia\Query\Exception\UnsupportedException $e) {
///     echo $e->getMessage(), "\n"; // REGEXP is not natively supported in SQLite.
/// }
/// echo (new SQLite())->from('users')->sortRandom()->limit(5)->build()->query, "\n";
/// // SELECT * FROM `users` ORDER BY RANDOM() LIMIT ?
/// ```
pub type Sqlite = Builder<SqliteDialect>;

/// The SQLite dialect.
#[derive(Debug, Clone, Default)]
pub struct SqliteDialect;

/// `EXISTS (SELECT 1 FROM json_each(attribute) WHERE json_each.value = json(?))`
/// for each element of the first value, joined with `glue`.
fn json_each_conditions(b: &mut Builder<SqliteDialect>, attribute: &str, values: &Array, glue: &str) -> String {
    let items = match values.get(&php_std::ArrayKey::Int(0)) {
        Some(Value::Array(items) | Value::Object(items)) => items.values().cloned().collect(),
        Some(Value::Null) | None => Vec::new(),
        Some(other) => vec![other.clone()],
    };
    let mut conditions = Vec::with_capacity(items.len());
    for item in &items {
        b.add_binding(json_binding(item), None);
        conditions.push(format!("EXISTS (SELECT 1 FROM json_each({attribute}) WHERE json_each.value = json(?))"));
    }
    format!("({})", conditions.join(glue))
}

impl Dialect for SqliteDialect {
    const CLASS: &'static str = "Utopia\\Query\\Builder\\SQLite";

    fn compile_random(_b: &Builder<Self>) -> String {
        "RANDOM()".into()
    }

    fn compile_regex(
        _b: &mut Builder<Self>,
        _attribute: &str,
        _values: &Array,
        _column: Option<&str>,
    ) -> Result<String> {
        Err(Error::Unsupported("REGEXP is not natively supported in SQLite.".into()))
    }

    fn compile_filter(b: &mut Builder<Self>, query: &Query) -> Result<String> {
        sql::sql_compile_filter(b, query)
    }

    fn wrap_union_member(_b: &Builder<Self>, sql: &str) -> String {
        // SQLite's compound-SELECT parser rejects parenthesised members.
        sql.to_owned()
    }

    fn update(b: &mut Builder<Self>) -> Result<Statement> {
        for (column, condition) in b.core.json_sets.0.clone() {
            b.core.raw_sets.set(column.clone(), condition.expression);
            b.core.raw_set_bindings.set(column, condition.bindings);
        }
        let result = b.base_update()?;
        b.core.json_sets.clear();
        Ok(result)
    }

    fn reset(b: &mut Builder<Self>) {
        b.core.json_sets.clear();
    }

    fn ast_dialect() -> crate::builder::AstDialect {
        crate::builder::AstDialect::Sqlite
    }
}

impl SqlDialect for SqliteDialect {
    fn compile_search_expression(
        _b: &mut Builder<Self>,
        _attribute: &str,
        _values: &Array,
        _not: bool,
    ) -> Result<String> {
        Err(Error::Unsupported("Full-text search is not supported in the SQLite query builder.".into()))
    }

    fn compile_spatial_distance(
        _b: &mut Builder<Self>,
        _method: Method,
        _attribute: &str,
        _values: &Array,
    ) -> Result<String> {
        Err(Error::Unsupported("Spatial distance queries are not supported in SQLite.".into()))
    }

    fn compile_spatial_predicate(
        _b: &mut Builder<Self>,
        _function: &str,
        _attribute: &str,
        _values: &Array,
        _not: bool,
    ) -> Result<String> {
        Err(Error::Unsupported("Spatial predicates are not supported in SQLite.".into()))
    }

    fn compile_spatial_covers_predicate(
        _b: &mut Builder<Self>,
        _attribute: &str,
        _values: &Array,
        _not: bool,
    ) -> Result<String> {
        Err(Error::Unsupported("Spatial covers predicates are not supported in SQLite.".into()))
    }

    fn compile_json_contains_expression(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        not: bool,
    ) -> Result<String> {
        let expression = json_each_conditions(b, attribute, values, " AND ");
        Ok(if not { format!("NOT {expression}") } else { expression })
    }

    fn compile_json_overlaps_expression(b: &mut Builder<Self>, attribute: &str, values: &Array) -> Result<String> {
        Ok(json_each_conditions(b, attribute, values, " OR "))
    }

    fn compile_json_path_expression(b: &mut Builder<Self>, attribute: &str, values: &Array) -> Result<String> {
        let (path, operator) = json_path_parts(b, values)?;
        Ok(format!("json_extract({attribute}, '$.{path}') {operator} ?"))
    }

    fn compile_conflict_header(b: &mut Builder<Self>) -> Result<String> {
        on_conflict_header(b)
    }

    fn compile_conflict_assignment(wrapped: &str) -> String {
        format!("excluded.{wrapped}")
    }
}

impl Locking for SqliteDialect {}
impl Transactions for SqliteDialect {}
impl StatisticalAggregates for SqliteDialect {}
impl BitwiseAggregates for SqliteDialect {}
impl RawSql for SqliteDialect {}
impl CrossJoins for SqliteDialect {}
impl ConditionalAggregates for SqliteDialect {}

impl StringAggregates for SqliteDialect {
    fn group_concat_expr(column: &str, order_by: &str) -> String {
        if order_by.is_empty() {
            format!("GROUP_CONCAT({column}, ?)")
        } else {
            format!("GROUP_CONCAT({column} {order_by}, ?)")
        }
    }
    fn json_array_agg_expr(column: &str) -> String {
        format!("json_group_array({column})")
    }
    fn json_object_agg_expr(key: &str, value: &str) -> String {
        format!("json_group_object({key}, {value})")
    }
}

impl Upsert for SqliteDialect {
    fn upsert(b: &mut Builder<Self>) -> Result<Statement> {
        b.compile_upsert(conflict_clause)
    }
}

impl UpsertSelect for SqliteDialect {
    fn upsert_select(b: &mut Builder<Self>) -> Result<Statement> {
        b.compile_upsert_select(conflict_clause)
    }
}

impl InsertOrIgnore for SqliteDialect {
    fn insert_or_ignore(b: &mut Builder<Self>) -> Result<Statement> {
        b.core.bindings.clear();
        let (sql, bindings) = b.compile_insert_body()?;
        b.add_bindings(bindings);
        let sql = match sql.strip_prefix("INSERT INTO") {
            Some(rest) => format!("INSERT OR IGNORE INTO{rest}"),
            None => sql,
        };
        Ok(b.statement(sql))
    }
}

impl Json for SqliteDialect {
    fn set_json_append(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.core.json_sets.set(
            column,
            Condition::new(
                format!(
                    "json_group_array(value) FROM (SELECT value FROM json_each(IFNULL({wrapped}, '[]')) UNION ALL SELECT value FROM json_each(?))"
                ),
                vec![json_binding(&Value::Array(values.clone()))],
            ),
        );
        Ok(())
    }

    fn set_json_prepend(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.core.json_sets.set(
            column,
            Condition::new(
                format!(
                    "json_group_array(value) FROM (SELECT value FROM json_each(?) UNION ALL SELECT value FROM json_each(IFNULL({wrapped}, '[]')))"
                ),
                vec![json_binding(&Value::Array(values.clone()))],
            ),
        );
        Ok(())
    }

    fn set_json_insert(b: &mut Builder<Self>, column: &str, index: i64, value: Value) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.core.json_sets.set(
            column,
            Condition::new(format!("json_insert({wrapped}, '$[{index}]', json(?))"), vec![json_binding(&value)]),
        );
        Ok(())
    }

    fn set_json_remove(b: &mut Builder<Self>, column: &str, value: Value) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.core.json_sets.set(
            column,
            Condition::new(
                format!("(SELECT json_group_array(value) FROM json_each({wrapped}) WHERE value != json(?))"),
                vec![json_binding(&value)],
            ),
        );
        Ok(())
    }

    fn set_json_intersect(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.set_raw(
            column,
            format!(
                "(SELECT json_group_array(value) FROM json_each(IFNULL({wrapped}, '[]')) WHERE value IN (SELECT value FROM json_each(?)))"
            ),
            vec![json_binding(&Value::Array(values.clone()))],
        );
        Ok(())
    }

    fn set_json_diff(b: &mut Builder<Self>, column: &str, values: &Array) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.set_raw(
            column,
            format!(
                "(SELECT json_group_array(value) FROM json_each(IFNULL({wrapped}, '[]')) WHERE value NOT IN (SELECT value FROM json_each(?)))"
            ),
            vec![json_binding(&Value::Array(values.clone()))],
        );
        Ok(())
    }

    fn set_json_unique(b: &mut Builder<Self>, column: &str) -> Result<()> {
        let wrapped = b.resolve_and_wrap(column)?;
        b.set_raw(
            column,
            format!("(SELECT json_group_array(DISTINCT value) FROM json_each(IFNULL({wrapped}, '[]')))"),
            Vec::<Value>::new(),
        );
        Ok(())
    }

    fn set_json_path(b: &mut Builder<Self>, column: &str, path: &str, value: Value) -> Result<()> {
        if !path.starts_with('$') {
            return Err(Error::Validation(format!("JSON path must start with '$': {path}")));
        }
        let wrapped = b.resolve_and_wrap(column)?;
        b.core
            .json_sets
            .set(column, Condition::new(format!("json_set({wrapped}, ?, ?)"), vec![value::string(path), value]));
        Ok(())
    }
}
