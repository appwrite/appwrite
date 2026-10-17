//! Cursors, orders and pages, the bounded page of a left-joined read, and
//! the vector order of PostgreSQL.
//!
//! PHP: `SQL::applyFindCursor()`, `cursorConditions()`, `cursorEquality()`,
//! `nullableCursorComparison()`, `getVectorCursorCondition()`,
//! `applyFindPage()`, `boundsJoinedSort()` (MariaDB, MySQL),
//! `boundedPage()`, `joinFromBoundedPage()`, `isMainSearch()`,
//! `mainOrderPrefix()`, `isMainRowCondition()`, `joinAliasOf()`,
//! `getNullOrder()`, `getVectorOrderRaw()`, `getSqlReadableDistance()` (and
//! PostgreSQL's), `Adapter\SQL\BoundedPage`.

use utopia_query::Compiler as _;
use utopia_query::builder::Statement;
use utopia_query::{CursorDirection, Method, OrderDirection};

use super::super::{Engine, Sql};
use super::{
    COLLECTION_GRANTED, Expression, JoinAlias, ROW_CONDITION_GROUPS, UNBOUNDED_LIMIT, all_of, any_of, from_sub, wrap,
};
use crate::builder::SqlBuilder;
use crate::collection::Collection;
use crate::error::{Error, Result};
use crate::permission::PermissionType;
use crate::query::Query;
use crate::storage;
use crate::value::{Array, Value};

/// The main rows a left-joined read's page can come from: the first `rows`
/// main documents in the main order of the read that meet its main
/// conditions and its searches.
///
/// PHP: `Utopia\Database\Adapter\SQL\BoundedPage`.
#[derive(Debug, Clone)]
pub(crate) struct BoundedPage {
    /// The main order attributes.
    pub order_attributes: Vec<String>,
    /// Their directions.
    pub order_types: Vec<OrderDirection>,
    /// How many main rows the page can come from.
    pub rows: i64,
    /// The read's conditions on main attributes, its searches included.
    pub conditions: Vec<Query>,
    /// The read's searches the adapter compiles itself.
    pub adapter_conditions: Vec<Query>,
    /// The positions of the read's own searches among its queries.
    searches: Vec<usize>,
    /// The positions of its adapter searches among the adapter's queries.
    adapter_searches: Vec<usize>,
}

impl BoundedPage {
    /// `withoutSearches($queries)` of the read's queries.
    pub(crate) fn without_searches(&self, queries: &[Query]) -> Vec<Query> {
        queries.iter().enumerate().filter(|(i, _)| !self.searches.contains(i)).map(|(_, q)| q.clone()).collect()
    }

    /// `withoutSearches($adapterFilterQueries)`.
    pub(crate) fn without_adapter_searches(&self, queries: &[Query]) -> Vec<Query> {
        queries.iter().enumerate().filter(|(i, _)| !self.adapter_searches.contains(i)).map(|(_, q)| q.clone()).collect()
    }
}

/// A cursor's value (`$cursor[$attribute]`, `null` when missing).
fn cursor_value(cursor: &Array, attribute: &str) -> Value {
    cursor.get_str(attribute).cloned().unwrap_or(Value::Null)
}

/// A cursor value as `Query::lessThan()` / `greaterThan()` take it
/// (`string|int|float|bool`): PHP refuses `null` and a list with a
/// `TypeError` from the call at `Adapter/SQL.php` line `less_line` (the
/// `greaterThan()` one follows two lines later).
fn comparable(value: &Value, direction: OrderDirection, less_line: u32) -> Result<utopia_query::Value> {
    if matches!(value, Value::Null | Value::Array(_) | Value::Object(_) | Value::Ext(_)) {
        let (method, line) =
            if direction == OrderDirection::Desc { ("lessThan", less_line) } else { ("greaterThan", less_line + 2) };
        return Err(crate::error::Error::new(
            crate::error::Kind::TypeError,
            format!(
                "Utopia\\Query\\Query::{method}(): Argument #2 ($value) must be of type string|int|float|bool, {} given, called in /usr/src/code/vendor/utopia-php/database/src/Database/Adapter/SQL.php on line {line}",
                value.debug_type()
            ),
        ));
    }
    Ok(crate::value::to_query(value))
}

/// The direction an order runs in, flipped before a cursor.
fn directed(order_type: OrderDirection, cursor_direction: CursorDirection) -> OrderDirection {
    if cursor_direction == CursorDirection::Before {
        if order_type == OrderDirection::Asc { OrderDirection::Desc } else { OrderDirection::Asc }
    } else {
        order_type
    }
}

/// The order type at a position (`$orderTypes[$i] ?? Asc`).
fn order_type(order_types: &[OrderDirection], i: usize) -> OrderDirection {
    order_types.get(i).copied().unwrap_or(OrderDirection::Asc)
}

impl Sql {
    /// `getNullOrder()`: the direction the engine sorts null first in.
    pub(crate) fn null_order(&self) -> OrderDirection {
        if self.engine == Engine::Postgres { OrderDirection::Desc } else { OrderDirection::Asc }
    }

    /// `boundsJoinedSort()`: MariaDB and MySQL read a left-joined page from
    /// a derived table of the main rows it can come from.
    pub(crate) fn bounds_joined_sort(&self) -> bool {
        self.engine.is_mariadb_family()
    }

    /// `getVectorOrderRaw()`: PostgreSQL's distance of a vector query, the
    /// query's attribute set to its column; `None` elsewhere.
    pub(crate) fn vector_order_raw(&self, query: &mut Query, alias: &str) -> Option<Expression> {
        if self.engine != Engine::Postgres {
            return None;
        }
        let column = storage::column(query.attribute()).to_owned();
        query.set_attribute(column.as_str());
        let attribute = self.quote(&self.filter(&column));
        let quoted_alias = self.quote(alias);

        // `array_map()` keeps the keys: a map is encoded as a JSON object,
        // which the engine then refuses (`Adapter/Postgres.php` line 1836).
        let vector: Array = match query.values().values().next() {
            Some(utopia_query::Value::Array(values)) => values
                .iter()
                .map(|(k, v)| {
                    let v = crate::value::from_query(v);
                    (k.to_owned(), Value::Float(if super::is_numeric(&v) { v.to_double() } else { 0.0 }))
                })
                .collect(),
            _ => Array::new(),
        };
        let vector =
            php_std::json::encode(&Value::Array(vector), php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH)
                .unwrap_or_default();

        let expression = match query.method() {
            Method::VectorDot => format!("({quoted_alias}.{attribute} <#> ?::vector)"),
            Method::VectorCosine => format!("({quoted_alias}.{attribute} <=> ?::vector)"),
            Method::VectorEuclidean => format!("({quoted_alias}.{attribute} <-> ?::vector)"),
            _ => return None,
        };
        Some(Expression::new(expression, vec![utopia_query::Value::Str(vector.as_str().into())]))
    }

    /// `getSqlReadableDistance()`: PostgreSQL reads the distance as text.
    pub(crate) fn readable_distance(&self, distance: &str) -> String {
        if self.engine == Engine::Postgres { format!("{distance}::text") } else { distance.to_owned() }
    }

    /// `getVectorCursorCondition()`: the rows after a vector cursor, by
    /// distance and then by the order attributes.
    #[allow(clippy::too_many_arguments)]
    pub(crate) fn vector_cursor_condition(
        &self,
        vector: &Expression,
        distance: f64,
        order_attributes: &[String],
        order_types: &[OrderDirection],
        cursor: &Array,
        cursor_direction: CursorDirection,
        alias: &str,
        join_aliases: &[String],
        nullable: bool,
    ) -> Result<Expression> {
        let distance = php_std::json::encode(&Value::Float(distance), php_std::json::Flags::THROW_ON_ERROR, 512)
            .map_err(|e| Error::new(crate::Kind::Json, e.to_string()))?;
        let distance = utopia_query::Value::Str(distance.as_str().into());
        let distance_operator = if cursor_direction == CursorDirection::Before { "<" } else { ">" };
        let mut clauses = vec![format!("({}) {distance_operator} ?", vector.sql)];
        let mut bindings = vector.bindings.clone();
        bindings.push(distance.clone());

        for (index, attribute) in order_attributes.iter().enumerate() {
            if !cursor.contains_key(&super::key(attribute)) {
                return Err(Error::query(format!("Vector cursor is missing order attribute '{attribute}'")));
            }
            let mut parts = vec![format!("({}) = ?", vector.sql)];
            let mut clause_bindings = vector.bindings.clone();
            clause_bindings.push(distance.clone());

            for previous in &order_attributes[..index] {
                if !cursor.contains_key(&super::key(previous)) {
                    return Err(Error::query(format!("Vector cursor is missing order attribute '{previous}'")));
                }
                let column = self.quote_order_column(&self.qualify_order_attribute(previous, join_aliases), alias);
                let value = cursor_value(cursor, previous);
                if nullable && value == Value::Null {
                    parts.push(format!("{column} IS NULL"));
                    continue;
                }
                parts.push(format!("{column} = ?"));
                clause_bindings.push(crate::value::to_query(&value));
            }

            let direction = directed(order_type(order_types, index), cursor_direction);
            let operator = if direction == OrderDirection::Desc { "<" } else { ">" };
            let column = self.quote_order_column(&self.qualify_order_attribute(attribute, join_aliases), alias);
            let value = cursor_value(cursor, attribute);
            if nullable && value == Value::Null {
                if direction != self.null_order() {
                    continue;
                }
                parts.push(format!("{column} IS NOT NULL"));
            } else if nullable && direction != self.null_order() {
                parts.push(format!("COALESCE({column} {operator} ?, TRUE)"));
                clause_bindings.push(crate::value::to_query(&value));
            } else {
                parts.push(format!("{column} {operator} ?"));
                clause_bindings.push(crate::value::to_query(&value));
            }
            clauses.push(format!("({})", parts.join(" AND ")));
            bindings.extend(clause_bindings);
        }

        Ok(Expression::new(format!("({})", clauses.join(" OR ")), bindings))
    }

    /// `applyFindCursor()`: the rows after the cursor, in the order's
    /// direction.
    #[allow(clippy::too_many_arguments)]
    pub(crate) fn apply_find_cursor(
        &self,
        builder: &mut SqlBuilder,
        order_attributes: &[String],
        order_types: &[OrderDirection],
        cursor: &Array,
        cursor_direction: CursorDirection,
        join_aliases: &[String],
        nullable: bool,
    ) -> Result<()> {
        if cursor.is_empty() {
            return Ok(());
        }
        let conditions =
            self.cursor_conditions(order_attributes, order_types, cursor, cursor_direction, join_aliases, nullable)?;
        if conditions.is_empty() {
            return Ok(());
        }
        builder.filter(vec![any_of(conditions).into_inner()]);
        Ok(())
    }

    /// `cursorConditions()`: one condition per order position.
    fn cursor_conditions(
        &self,
        order_attributes: &[String],
        order_types: &[OrderDirection],
        cursor: &Array,
        cursor_direction: CursorDirection,
        join_aliases: &[String],
        nullable: bool,
    ) -> Result<Vec<Query>> {
        let mut conditions = Vec::new();
        for (i, original) in order_attributes.iter().enumerate() {
            let kind = order_type(order_types, i);
            if kind == OrderDirection::Random {
                continue;
            }
            let direction = directed(kind, cursor_direction);
            let internal = self.qualify_order_attribute(original, join_aliases);

            if !nullable && order_attributes.len() == 1 && i == 0 && original == crate::document::SEQUENCE {
                let value = comparable(&cursor_value(cursor, original), direction, 5710)?;
                conditions.push(wrap(if direction == OrderDirection::Desc {
                    utopia_query::Query::less_than(internal, value)
                } else {
                    utopia_query::Query::greater_than(internal, value)
                }));
                break;
            }

            let mut and = Vec::new();
            for previous in &order_attributes[..i] {
                and.push(self.cursor_equality(previous, cursor, join_aliases, nullable));
            }

            let value = cursor_value(cursor, original);
            if nullable {
                match self.nullable_cursor_comparison(&internal, &value, direction) {
                    Some(comparison) => and.push(comparison),
                    None => continue,
                }
            } else {
                let value = comparable(&value, direction, 5735)?;
                and.push(wrap(if direction == OrderDirection::Desc {
                    utopia_query::Query::less_than(internal, value)
                } else {
                    utopia_query::Query::greater_than(internal, value)
                }));
            }
            conditions.push(all_of(and));
        }
        Ok(conditions)
    }

    /// `cursorEquality()`: the rows tied with the cursor on an attribute.
    fn cursor_equality(&self, attribute: &str, cursor: &Array, join_aliases: &[String], nullable: bool) -> Query {
        let column = self.qualify_order_attribute(attribute, join_aliases);
        let value = cursor_value(cursor, attribute);
        if nullable && value == Value::Null {
            return wrap(utopia_query::Query::is_null(column));
        }
        wrap(utopia_query::Query::equal(column, vec![crate::value::to_query(&value)]))
    }

    /// `nullableCursorComparison()`: the rows after a cursor value in one
    /// order position, nulls placed as the engine sorts them.
    fn nullable_cursor_comparison(&self, attribute: &str, value: &Value, direction: OrderDirection) -> Option<Query> {
        let nulls_first = direction == self.null_order();
        if *value == Value::Null {
            return nulls_first.then(|| wrap(utopia_query::Query::is_not_null(attribute)));
        }
        let value = crate::value::to_query(value);
        let comparison = if direction == OrderDirection::Desc {
            utopia_query::Query::less_than(attribute, value)
        } else {
            utopia_query::Query::greater_than(attribute, value)
        };
        if nulls_first {
            return Some(wrap(comparison));
        }
        Some(wrap(utopia_query::Query::or([comparison, utopia_query::Query::is_null(attribute)])))
    }

    /// `applyFindPage()`: the order, limit and offset; after a union, the
    /// union read as one table (ordered by the columns its halves project).
    #[allow(clippy::too_many_arguments)]
    pub(crate) fn apply_find_page(
        &self,
        builder: &mut SqlBuilder,
        order_attributes: &[String],
        order_types: &[OrderDirection],
        limit: Option<i64>,
        offset: Option<i64>,
        cursor_direction: CursorDirection,
        after_union: bool,
        join_aliases: &[String],
    ) -> Result<()> {
        let limit = if limit.is_none() && offset.is_some() { Some(UNBOUNDED_LIMIT) } else { limit };

        if after_union {
            let quote = self.identifier_quote();
            let random = {
                let mut dialect = self.dialect_builder();
                let query = utopia_query::Query::order_random();
                crate::each_builder!(&mut dialect, b => b.compile_order(&query)).map_err(crate::query::query_error)?
            };
            let types: Vec<OrderDirection> = (0..order_attributes.len()).map(|i| order_type(order_types, i)).collect();
            builder_after_build(builder, move |result: Statement| {
                let mut sql = result.query.clone();
                let mut bindings = result.bindings.clone();
                let mut order_parts = Vec::new();
                for (i, kind) in types.iter().enumerate() {
                    if *kind == OrderDirection::Random {
                        order_parts.push(random.clone());
                        sql = format!("SELECT * FROM ({}) AS {quote}{}{quote}", result.query, super::FOJ_ROWS_ALIAS);
                        continue;
                    }
                    let direction = directed(*kind, cursor_direction);
                    order_parts.push(format!(
                        "{quote}{}{i}{quote}{}",
                        super::super::row::FOJ_ORDER_ALIAS_PREFIX,
                        if direction == OrderDirection::Desc { " DESC" } else { " ASC" }
                    ));
                }
                if !order_parts.is_empty() {
                    sql.push_str(" ORDER BY ");
                    sql.push_str(&order_parts.join(", "));
                }
                if let Some(limit) = limit {
                    sql.push_str(" LIMIT ?");
                    bindings.push(utopia_query::Value::Int(limit));
                }
                if let Some(offset) = offset {
                    sql.push_str(" OFFSET ?");
                    bindings.push(utopia_query::Value::Int(offset));
                }
                let mut statement = result;
                statement.query = sql;
                statement.bindings = bindings;
                Ok(statement)
            });
            return Ok(());
        }

        for (i, original) in order_attributes.iter().enumerate() {
            let kind = order_type(order_types, i);
            if kind == OrderDirection::Random {
                builder.sort_random();
                continue;
            }
            let internal = self.qualify_order_attribute(original, join_aliases);
            if directed(kind, cursor_direction) == OrderDirection::Desc {
                builder.sort_desc(&internal);
            } else {
                builder.sort_asc(&internal);
            }
        }
        if let Some(limit) = limit {
            builder.limit(limit);
        }
        if let Some(offset) = offset {
            builder.offset(offset);
        }
        Ok(())
    }

    /// `boundedPage()`: the main rows a left-joined read ordered by a
    /// unique main prefix can take its page from, or `None` when its rows
    /// are not bounded so.
    #[allow(clippy::too_many_arguments)]
    pub(crate) fn bounded_page(
        &self,
        collection: &Collection,
        queries: &[Query],
        adapter_filter_queries: &[Query],
        joins: &[JoinAlias],
        order_attributes: &[String],
        order_types: &[OrderDirection],
        limit: Option<i64>,
        offset: Option<i64>,
        cursor: &Array,
    ) -> Result<Option<BoundedPage>> {
        let Some(limit) = limit else {
            return Ok(None);
        };
        let reach = offset.unwrap_or(0) + i64::from(!cursor.is_empty());
        if limit > i64::MAX - reach {
            return Ok(None);
        }

        let join_aliases = super::aliases_of(joins);
        let Some((main_attributes, main_types)) = main_order_prefix(order_attributes, order_types, &join_aliases)
        else {
            return Ok(None);
        };

        let mut conditions = Vec::new();
        let mut searches = Vec::new();
        for (i, query) in queries.iter().enumerate() {
            let method = query.method();
            if method == Method::Select {
                continue;
            }
            if method.is_join() {
                if method != Method::LeftJoin {
                    return Ok(None);
                }
                continue;
            }
            if is_main_search(query, &join_aliases) {
                searches.push(i);
            } else if !is_main_row_condition(query, &join_aliases) {
                return Ok(None);
            }
            conditions.push(query.clone());
        }

        let mut adapter_conditions = Vec::new();
        let mut adapter_searches = Vec::new();
        for (i, query) in adapter_filter_queries.iter().enumerate() {
            if !is_main_search(query, &join_aliases) {
                return Ok(None);
            }
            adapter_conditions.push(query.clone());
            adapter_searches.push(i);
        }

        self.remap_dotted_query_attributes(&mut conditions, joins, collection)?;

        Ok(Some(BoundedPage {
            order_attributes: main_attributes,
            order_types: main_types,
            rows: limit + reach,
            conditions,
            adapter_conditions,
            searches,
            adapter_searches,
        }))
    }

    /// `joinFromBoundedPage()`: the read joins from a derived table of the
    /// main rows its page can come from.
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn join_from_bounded_page(
        &self,
        builder: &mut SqlBuilder,
        collection: &Collection,
        bound: &BoundedPage,
        cursor: &Array,
        cursor_direction: CursorDirection,
        join_aliases: &[String],
        name: &str,
        alias: &str,
        roles: &[String],
        for_permission: PermissionType,
        authorize: bool,
    ) -> Result<()> {
        let mut page = self.simple_builder(name, alias)?;
        page.select(vec![format!("{alias}.*")]);
        page.filter(super::inners(&bound.conditions));
        self.apply_filters(&mut page, &bound.adapter_conditions, name, alias).await?;

        if authorize
            && !matches!(collection.get(COLLECTION_GRANTED), Some(Value::Bool(true)))
            && super::filters_per_document(collection)
        {
            let hook = self.permission_hook(
                name,
                roles.to_vec(),
                for_permission.as_str(),
                &format!("{alias}.{}", storage::UID),
            )?;
            super::add_hook(&mut page, hook.into())?;
        }

        if !cursor.is_empty() {
            let mut conditions = self.cursor_conditions(
                &bound.order_attributes,
                &bound.order_types,
                cursor,
                cursor_direction,
                join_aliases,
                true,
            )?;
            let equalities = bound
                .order_attributes
                .iter()
                .map(|attribute| self.cursor_equality(attribute, cursor, join_aliases, true))
                .collect();
            conditions.push(all_of(equalities));
            page.filter(vec![any_of(conditions).into_inner()]);
        }

        self.apply_find_page(
            &mut page,
            &bound.order_attributes,
            &bound.order_types,
            Some(bound.rows),
            None,
            cursor_direction,
            false,
            &[],
        )?;

        from_sub(builder, page, alias);
        Ok(())
    }
}

/// `$builder->afterBuild($callback)`.
fn builder_after_build(
    builder: &mut SqlBuilder,
    callback: impl Fn(Statement) -> utopia_query::Result<Statement> + Send + Sync + Clone + 'static,
) {
    crate::each_builder!(builder, b => { b.after_build(callback); });
}

/// `isMainSearch()`: a search on a main attribute.
fn is_main_search(query: &Query, join_aliases: &[String]) -> bool {
    matches!(query.method(), Method::Search | Method::NotSearch)
        && join_alias_of(query.attribute(), join_aliases).is_none()
}

/// `mainOrderPrefix()`: the leading main attributes of an order, when they
/// hold a unique one and joined attributes follow them.
fn main_order_prefix(
    order_attributes: &[String],
    order_types: &[OrderDirection],
    join_aliases: &[String],
) -> Option<(Vec<String>, Vec<OrderDirection>)> {
    let mut attributes = Vec::new();
    let mut types = Vec::new();
    let mut unique = false;
    let mut joined = false;
    for (position, attribute) in order_attributes.iter().enumerate() {
        let kind = order_type(order_types, position);
        if kind == OrderDirection::Random {
            return None;
        }
        if join_alias_of(attribute, join_aliases).is_some() {
            joined = true;
            break;
        }
        attributes.push(attribute.clone());
        types.push(kind);
        unique = unique || attribute == crate::document::SEQUENCE || attribute == crate::document::ID;
    }
    if !unique || !joined || attributes.is_empty() {
        return None;
    }
    Some((attributes, types))
}

/// `isMainRowCondition()`: whether a condition reads only main attributes.
fn is_main_row_condition(query: &utopia_query::Query, join_aliases: &[String]) -> bool {
    let method = query.method();
    if matches!(method, Method::Search | Method::NotSearch)
        || (!method.is_filter() && !method.is_spatial() && !method.is_json() && !ROW_CONDITION_GROUPS.contains(&method))
    {
        return false;
    }
    if join_alias_of(query.attribute(), join_aliases).is_some() {
        return false;
    }
    for value in query.values().values() {
        match value {
            utopia_query::Value::Ext(utopia_query::Object::Query(child)) => {
                if !is_main_row_condition(child, join_aliases) {
                    return false;
                }
            }
            utopia_query::Value::Str(s)
                if matches!(method, Method::Exists | Method::NotExists)
                    && join_alias_of(&String::from_utf8_lossy(s.as_bytes()), join_aliases).is_some() =>
            {
                return false;
            }
            _ => {}
        }
    }
    true
}

/// `joinAliasOf()`: the join alias an attribute is qualified by.
fn join_alias_of<'a>(attribute: &str, join_aliases: &'a [String]) -> Option<&'a str> {
    let (prefix, _) = attribute.split_once('.')?;
    join_aliases.iter().find(|a| *a == prefix).map(String::as_str)
}

#[cfg(all(test, feature = "sqlite"))]
mod tests {
    use utopia_query::{Method, Value as Q};

    use super::super::super::pdo::Pdo;
    use super::super::super::{Engine, Sql};
    use crate::query::Query;

    /// `getVectorOrderRaw()` maps the vector with `array_map()`, which keeps
    /// the keys: a map is bound as a JSON object (`Adapter/Postgres.php`
    /// line 1836), a list as a JSON array.
    #[utopia_async::test]
    async fn a_vector_order_keeps_the_vectors_keys() {
        let pdo = Pdo::connect("sqlite::memory:", None, None).await.unwrap();
        let sql = Sql::new(Engine::Postgres, pdo);
        let binding = |vector: Q| {
            let values = std::iter::once(vector).collect();
            let mut query = Query::from(utopia_query::Query::new(Method::VectorCosine, "emb", values, ""));
            sql.vector_order_raw(&mut query, "t").unwrap().bindings
        };
        let map: utopia_query::Array = [(php_std::ArrayKey::from("a"), Q::Int(1))].into_iter().collect();
        assert_eq!(binding(Q::Array(map)), [Q::Str("{\"a\":1}".into())]);
        let list = utopia_query::Array::from_list(vec![Q::Int(1), Q::Str("x".into())]);
        assert_eq!(binding(Q::Array(list)), [Q::Str("[1,0]".into())]);
    }
}
