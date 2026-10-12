//! A builder as a syntax tree and back: [`Builder::to_ast`] and [`Builder::from_ast`].

use crate::ast::{
    Aliased, Between, Binary, Column, Cte, Expression, Func, In, InList, JoinClause, Literal, OrderByItem, Parser, Raw,
    Select, Serializer, Star, Table, TableSource, Unary,
};
use crate::builder::AstDialect;
use crate::builder::core::{Builder, Dialect, assert_join_on_predicate, first, on_parts};
use crate::builder::types::{Condition, CteClause};
use crate::enums::OrderDirection;
use crate::error::{Error, Result};
use crate::method::Method;
use crate::query::{ParsedQuery, Query};
use crate::value::{self, Array, Object, Value};

/// The serializer a dialect converts expressions with.
///
/// PHP: `Builder::createAstSerializer()`.
fn serializer(dialect: AstDialect) -> Serializer {
    match dialect {
        AstDialect::Standard => Serializer::new(),
        AstDialect::MySql => Serializer::mysql(),
        AstDialect::MariaDb => Serializer::mariadb(),
        AstDialect::ClickHouse => Serializer::clickhouse(),
        AstDialect::PostgreSql => Serializer::postgresql(),
        AstDialect::Sqlite => Serializer::sqlite(),
    }
}

/// `new Literal($value)` of a scalar, else of `(string) $value`.
fn literal(value: Option<&Value>) -> Expression {
    let value = match value {
        None => Value::Null,
        Some(v @ (Value::Null | Value::Str(_) | Value::Int(_) | Value::Float(_) | Value::Bool(_))) => v.clone(),
        Some(other) => value::string(value::to_php_string(other)),
    };
    Literal { value }.into()
}

/// `(string)` of a scalar, `''` for anything else.
fn scalar(value: Option<&Value>) -> String {
    match value {
        Some(v @ (Value::Str(_) | Value::Int(_) | Value::Float(_) | Value::Bool(_))) => value::to_php_string(v),
        _ => String::new(),
    }
}

/// `string|int|float` kept, anything else as `(string)`.
fn comparable(value: &Value) -> Value {
    match value {
        Value::Str(_) | Value::Int(_) | Value::Float(_) => value.clone(),
        other => value::string(value::to_php_string(other)),
    }
}

/// PHP's `(int)` of a literal value.
fn to_int(value: &Value) -> i64 {
    match value {
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::dval_to_lval(*f),
        Value::Bool(b) => i64::from(*b),
        Value::Str(s) => php_std::format::str_to_int(s.as_bytes()),
        _ => 0,
    }
}

fn column(name: &str) -> Expression {
    Column::new(name).into()
}

fn combine(mut expressions: Vec<Expression>, operator: &str) -> Expression {
    let rest = expressions.split_off(1);
    let mut result = expressions.pop().unwrap_or_else(|| Raw::new("1 = 1").into());
    for expression in rest {
        result = Binary::new(result, operator, expression).into();
    }
    result
}

/// `schema.table.name` of a column reference.
fn column_string(reference: &Column) -> String {
    let mut parts: Vec<&str> = Vec::with_capacity(3);
    if let Some(schema) = &reference.schema {
        parts.push(schema);
    }
    if let Some(table) = &reference.table {
        parts.push(table);
    }
    parts.push(&reference.name);
    parts.join(".")
}

fn like_to_query(attribute: String, value: String, negated: bool) -> Query {
    let starts = value.starts_with('%');
    let ends = value.ends_with('%');
    if starts && ends && value.len() > 2 {
        let method = if negated { Method::NotContains } else { Method::Contains };
        return Query::new(method, attribute, value::list(vec![value::string(&value[1..value.len() - 1])]), "");
    }
    if ends && !starts {
        let prefix = &value[..value.len() - 1];
        return if negated { Query::not_starts_with(attribute, prefix) } else { Query::starts_with(attribute, prefix) };
    }
    if starts && !ends {
        let suffix = &value[1..];
        return if negated { Query::not_ends_with(attribute, suffix) } else { Query::ends_with(attribute, suffix) };
    }
    let keyword = if negated { " NOT LIKE ?" } else { " LIKE ?" };
    Query::raw(format!("{attribute}{keyword}"), value::list(vec![value::string(value)]))
}

impl<D: Dialect> Builder<D> {
    /// The builder's select as a syntax tree: columns and aggregates,
    /// table, joins, filters, grouping, `HAVING`, ordering, limit, offset,
    /// `DISTINCT` and CTEs (each CTE's SQL parsed with the standard tokenizer).
    ///
    /// PHP: `Builder::toAst()`.
    pub fn to_ast(&mut self) -> Result<Select> {
        let grouped = Query::group_by_type(&self.core.pending);
        self.prepare_alias_qualification(&grouped);

        let columns = self.ast_columns(&grouped);
        let from = self.ast_from();
        let joins = self.ast_joins(&grouped)?;
        let where_clause = match grouped.filters.is_empty() {
            true => None,
            false => Some(combine(grouped.filters.iter().map(|f| self.query_to_ast(f)).collect::<Result<_>>()?, "AND")),
        };
        let column =
            value::Param::method("Utopia\\Query\\Builder::columnNameToAstExpression", 1, "col", "Builder.php", 2305);
        let group_by =
            grouped.group_by.iter().map(|c| Ok(self.column_to_ast(&column.string(c)?))).collect::<Result<Vec<_>>>()?;
        let having = self.ast_having(&grouped)?;
        let order_by = self.ast_order_by();
        let limit = grouped.limit.map(|l| Literal { value: Value::Int(l) }.into());
        let offset = grouped.offset.map(|o| Literal { value: Value::Int(o) }.into());
        let parser = Parser::new();
        let ctes = self
            .core
            .ctes
            .iter()
            .map(|cte| {
                Ok(Cte {
                    name: cte.name.clone(),
                    query: parser.parse_sql(&cte.query)?,
                    columns: cte.columns.clone(),
                    recursive: cte.recursive,
                })
            })
            .collect::<Result<_>>()?;

        Ok(Select {
            columns,
            from,
            joins,
            where_clause,
            group_by,
            having,
            order_by,
            limit,
            offset,
            distinct: grouped.distinct,
            ctes,
            windows: Vec::new(),
        })
    }

    fn ast_columns(&self, grouped: &ParsedQuery) -> Vec<Expression> {
        let mut columns = Vec::new();
        for aggregate in &grouped.aggregations {
            let method = aggregate.method();
            let name = method.sql_function().map_or_else(|| method.as_str().to_ascii_uppercase(), str::to_owned);
            let attribute = aggregate.attribute();
            let argument: Expression =
                if attribute == "*" || attribute.is_empty() { Star::default().into() } else { column(attribute) };
            let call =
                Func { name, arguments: vec![argument], distinct: method == Method::CountDistinct, filter: None };
            columns.push(if aggregate.alias().is_empty() {
                call.into()
            } else {
                Aliased::new(call, aggregate.alias()).into()
            });
        }
        if let Some(selection) = grouped.selections.first() {
            for value in selection.values().values() {
                columns.push(self.column_to_ast(&value::to_php_string(value)));
            }
        }
        if columns.is_empty() {
            columns.push(Star::default().into());
        }
        columns
    }

    /// PHP: `Builder::columnNameToAstExpression()`.
    fn column_to_ast(&self, name: &str) -> Expression {
        if name == "*" {
            return Star::default().into();
        }
        if name.contains('.') {
            let parts: Vec<&str> = name.splitn(3, '.').collect();
            if let [schema, table, last] = parts[..] {
                if last == "*" {
                    return Star { table: Some(table.into()), schema: Some(schema.into()) }.into();
                }
                return Column { name: last.into(), table: Some(table.into()), schema: Some(schema.into()) }.into();
            }
            if parts[1] == "*" {
                return Star { table: Some(parts[0].into()), schema: None }.into();
            }
            return Column::of(parts[0], parts[1]).into();
        }
        if self.core.qualify && !self.core.aggregation_aliases.contains(name) {
            return Column::of(self.core.alias.clone(), name).into();
        }
        column(name)
    }

    fn ast_from(&self) -> Option<TableSource> {
        if self.core.tableless || self.core.table.is_empty() {
            return None;
        }
        let alias = (!self.core.alias.is_empty()).then(|| self.core.alias.clone());
        Some(Table { name: self.core.table.clone(), alias, schema: None }.into())
    }

    fn ast_joins(&self, grouped: &ParsedQuery) -> Result<Vec<JoinClause>> {
        let mut joins = Vec::with_capacity(grouped.joins.len());
        for join in &grouped.joins {
            let kind = match join.method() {
                Method::LeftJoin => "LEFT JOIN",
                Method::RightJoin => "RIGHT JOIN",
                Method::CrossJoin => "CROSS JOIN",
                Method::FullOuterJoin => "FULL OUTER JOIN",
                Method::NaturalJoin => "NATURAL JOIN",
                _ => "JOIN",
            };
            let alias = (!join.alias().is_empty()).then(|| join.alias().to_owned());
            let table = Table { name: join.attribute().to_owned(), alias, schema: None };
            let on = join.join_on_queries();
            let condition = if on.is_empty() {
                None
            } else {
                let mut expressions = Vec::with_capacity(on.len());
                for query in on {
                    assert_join_on_predicate(query)?;
                    expressions.push(self.query_to_ast(query)?);
                }
                Some(combine(expressions, "AND"))
            };
            joins.push(JoinClause { kind: kind.to_owned(), table: table.into(), condition });
        }
        Ok(joins)
    }

    /// PHP: `Builder::queryToAstExpression()`.
    fn query_to_ast(&self, query: &Query) -> Result<Expression> {
        let attribute = query.attribute();
        let values = query.values();
        let at = |i: i64| values.get(&php_std::ArrayKey::Int(i)).filter(|v| !matches!(v, Value::Null));
        let compare = |op: &str| Binary::new(column(attribute), op, literal(at(0))).into();
        let like =
            |op: &str, text: String| Binary::new(column(attribute), op, Literal { value: value::string(text) }).into();
        Ok(match query.method() {
            Method::Equal => self.equal_to_ast(attribute, values, false),
            Method::NotEqual => self.equal_to_ast(attribute, values, true),
            Method::GreaterThan => compare(">"),
            Method::GreaterThanEqual => compare(">="),
            Method::LessThan => compare("<"),
            Method::LessThanEqual => compare("<="),
            Method::Between | Method::NotBetween => {
                Between::new(column(attribute), literal(at(0)), literal(at(1)), query.method() == Method::NotBetween)
                    .into()
            }
            Method::IsNull => Unary::new("IS NULL", column(attribute), false).into(),
            Method::IsNotNull => Unary::new("IS NOT NULL", column(attribute), false).into(),
            Method::Contains | Method::ContainsAny => self.contains_to_ast(attribute, values, false),
            Method::NotContains => self.contains_to_ast(attribute, values, true),
            Method::StartsWith => like("LIKE", format!("{}%", scalar(at(0)))),
            Method::NotStartsWith => like("NOT LIKE", format!("{}%", scalar(at(0)))),
            Method::EndsWith => like("LIKE", format!("%{}", scalar(at(0)))),
            Method::NotEndsWith => like("NOT LIKE", format!("%{}", scalar(at(0)))),
            Method::And | Method::Or => {
                let operator = if query.method() == Method::Or { "OR" } else { "AND" };
                let parts = query.nested().map(|q| self.query_to_ast(q)).collect::<Result<Vec<_>>>()?;
                if parts.is_empty() {
                    Literal { value: Value::Bool(operator != "OR") }.into()
                } else {
                    combine(parts, operator)
                }
            }
            Method::On => {
                let (left, operator, right) = on_parts(query);
                if left.is_empty() || right.is_empty() {
                    return Err(Error::Validation("Join ON requires left and right columns".into()));
                }
                if !["=", "!=", "<", ">", "<=", ">=", "<>"].contains(&operator.as_str()) {
                    return Err(Error::Validation(format!("Invalid join operator: {operator}")));
                }
                Binary::new(self.column_to_ast(&left), operator, self.column_to_ast(&right)).into()
            }
            Method::Raw => Raw::new(attribute).into(),
            _ => Raw::new(if attribute.is_empty() { "1 = 1" } else { attribute }).into(),
        })
    }

    fn equal_to_ast(&self, attribute: &str, values: &Array, negated: bool) -> Expression {
        if values.len() == 1 {
            let value = first(values);
            if matches!(value, Value::Null) {
                let operator = if negated { "IS NOT NULL" } else { "IS NULL" };
                return Unary::new(operator, column(attribute), false).into();
            }
            return Binary::new(column(attribute), if negated { "!=" } else { "=" }, literal(Some(&value))).into();
        }
        let items = values.values().map(|v| literal(Some(v))).collect();
        In::new(column(attribute), InList::Values(items), negated).into()
    }

    fn contains_to_ast(&self, attribute: &str, values: &Array, negated: bool) -> Expression {
        let operator = if negated { "NOT LIKE" } else { "LIKE" };
        let pattern = |v: Option<&Value>| {
            Binary::new(column(attribute), operator, Literal { value: value::string(format!("%{}%", scalar(v))) })
        };
        if values.len() == 1 {
            return pattern(Some(&first(values))).into();
        }
        let parts = values.values().map(|v| pattern(Some(v)).into()).collect();
        combine(parts, if negated { "AND" } else { "OR" })
    }

    fn ast_having(&self, grouped: &ParsedQuery) -> Result<Option<Expression>> {
        let mut parts = Vec::new();
        for having in &grouped.having {
            for query in having.nested() {
                parts.push(self.query_to_ast(query)?);
            }
        }
        Ok((!parts.is_empty()).then(|| combine(parts, "AND")))
    }

    fn ast_order_by(&self) -> Vec<OrderByItem> {
        Query::by_type(&self.core.pending, &[Method::OrderAsc, Method::OrderDesc, Method::OrderRandom])
            .into_iter()
            .map(|query| {
                if query.method() == Method::OrderRandom {
                    return OrderByItem::new(Raw::new("RAND()"), OrderDirection::Asc);
                }
                let direction =
                    if query.method() == Method::OrderAsc { OrderDirection::Asc } else { OrderDirection::Desc };
                let nulls = match query.value() {
                    Some(Value::Ext(Object::Nulls(n))) => Some(*n),
                    _ => None,
                };
                OrderByItem { expression: self.column_to_ast(query.attribute()), direction, nulls }
            })
            .collect()
    }

    /// A builder with the select `select` describes: its table, columns
    /// (aggregates become aggregate queries, other expressions raw selects),
    /// joins, filters, grouping, `HAVING`, ordering, limit, offset, CTEs and
    /// `DISTINCT`. Expressions without a query equivalent are kept as raw
    /// SQL, written by the dialect's serializer.
    ///
    /// PHP: `Builder::fromAst()`.
    pub fn from_ast(select: &Select) -> Result<Self> {
        let mut builder = Self::new();
        let serializer = serializer(D::ast_dialect());

        if let Some(TableSource::Table(table)) = &select.from {
            builder.from(table.name.clone(), table.alias.clone().unwrap_or_default());
        }
        builder.apply_ast_columns(select, &serializer)?;
        builder.apply_ast_joins(select, &serializer)?;
        if let Some(condition) = &select.where_clause {
            let queries = where_to_queries(condition, &serializer)?;
            builder.core.pending.extend(queries);
        }
        let group_by: Vec<String> = select
            .group_by
            .iter()
            .filter_map(|e| match e {
                Expression::Column(c) => Some(column_string(c)),
                _ => None,
            })
            .collect();
        if !group_by.is_empty() {
            builder.group_by(group_by);
        }
        if let Some(having) = &select.having {
            let queries = where_to_queries(having, &serializer)?;
            if !queries.is_empty() {
                builder.having(queries);
            }
        }
        for item in &select.order_by {
            match &item.expression {
                Expression::Column(c) => {
                    if item.direction == OrderDirection::Desc {
                        builder.sort_desc(column_string(c), item.nulls);
                    } else {
                        builder.sort_asc(column_string(c), item.nulls);
                    }
                }
                other => {
                    let direction = if item.direction == OrderDirection::Desc { " DESC" } else { " ASC" };
                    let sql = serializer.serialize_expression(other)?;
                    builder.core.raw_orders.push(Condition::new(sql + direction, Vec::new()));
                }
            }
        }
        if let Some(Expression::Literal(Literal { value })) = &select.limit
            && !matches!(value, Value::Null)
        {
            builder.limit(to_int(value));
        }
        if let Some(Expression::Literal(Literal { value })) = &select.offset
            && !matches!(value, Value::Null)
        {
            builder.offset(to_int(value));
        }
        for cte in &select.ctes {
            let sql = serializer.serialize(&cte.query)?;
            builder.core.ctes.push(CteClause::new(
                cte.name.clone(),
                sql,
                Vec::new(),
                cte.recursive,
                cte.columns.clone(),
            ));
        }
        if select.distinct {
            builder.distinct();
        }
        Ok(builder)
    }

    fn apply_ast_columns(&mut self, select: &Select, serializer: &Serializer) -> Result<()> {
        let mut columns = Vec::new();
        for expression in &select.columns {
            match expression {
                Expression::Star(Star { table: None, .. }) => {}
                Expression::Aliased(a) if matches!(*a.expression, Expression::Func(_)) => {
                    let Expression::Func(f) = &*a.expression else { unreachable!() };
                    let name = f.name.to_ascii_uppercase();
                    let attribute = func_attribute(f);
                    if f.distinct && name == "COUNT" {
                        self.core.pending.push(Query::count_distinct(attribute, a.alias.clone()));
                        continue;
                    }
                    let method = match name.as_str() {
                        "COUNT" => Some(Method::Count),
                        "SUM" => Some(Method::Sum),
                        "AVG" => Some(Method::Avg),
                        "MIN" => Some(Method::Min),
                        "MAX" => Some(Method::Max),
                        "STDDEV" => Some(Method::Stddev),
                        "STDDEV_POP" => Some(Method::StddevPop),
                        "STDDEV_SAMP" => Some(Method::StddevSamp),
                        "VARIANCE" => Some(Method::Variance),
                        "VAR_POP" => Some(Method::VarPop),
                        "VAR_SAMP" => Some(Method::VarSamp),
                        "BIT_AND" => Some(Method::BitAnd),
                        "BIT_OR" => Some(Method::BitOr),
                        "BIT_XOR" => Some(Method::BitXor),
                        _ => None,
                    };
                    match method {
                        Some(m) => self.core.pending.push(Query::new(m, attribute, Array::default(), a.alias.clone())),
                        None => {
                            self.select_raw(serializer.serialize_expression(expression)?, Array::default());
                        }
                    }
                }
                Expression::Func(f) => {
                    let name = f.name.to_ascii_uppercase();
                    let attribute = func_attribute(f);
                    if f.distinct && name == "COUNT" {
                        self.core.pending.push(Query::count_distinct(attribute, ""));
                        continue;
                    }
                    let method = match name.as_str() {
                        "COUNT" => Some(Method::Count),
                        "SUM" => Some(Method::Sum),
                        "AVG" => Some(Method::Avg),
                        "MIN" => Some(Method::Min),
                        "MAX" => Some(Method::Max),
                        _ => None,
                    };
                    match method {
                        Some(m) => self.core.pending.push(Query::new(m, attribute, Array::default(), "")),
                        None => {
                            self.select_raw(serializer.serialize_expression(expression)?, Array::default());
                        }
                    }
                }
                Expression::Column(c) => columns.push(column_string(c)),
                Expression::Star(s) => columns.push(format!("{}.*", s.table.as_deref().unwrap_or(""))),
                Expression::Aliased(a) if matches!(*a.expression, Expression::Column(_)) => {
                    let Expression::Column(c) = &*a.expression else { unreachable!() };
                    let mut column = column_string(c);
                    if !a.alias.is_empty() {
                        column.push_str(" AS ");
                        column.push_str(&a.alias);
                    }
                    columns.push(column);
                }
                other => {
                    self.select_raw(serializer.serialize_expression(other)?, Array::default());
                }
            }
        }
        if !columns.is_empty() {
            self.select(columns);
        }
        Ok(())
    }

    fn apply_ast_joins(&mut self, select: &Select, serializer: &Serializer) -> Result<()> {
        for join in &select.joins {
            let TableSource::Table(table) = &join.table else { continue };
            let method = match join.kind.to_ascii_uppercase().as_str() {
                "CROSS JOIN" => Method::CrossJoin,
                "NATURAL JOIN" => Method::NaturalJoin,
                "LEFT JOIN" | "LEFT OUTER JOIN" => Method::LeftJoin,
                "RIGHT JOIN" | "RIGHT OUTER JOIN" => Method::RightJoin,
                "FULL OUTER JOIN" | "FULL JOIN" => Method::FullOuterJoin,
                _ => Method::Join,
            };
            let unconditioned = method == Method::CrossJoin || method == Method::NaturalJoin;
            let on = match &join.condition {
                Some(condition) if !unconditioned => join_condition_to_queries(condition, serializer)?,
                _ => Vec::new(),
            };
            let on = value::list(on.into_iter().map(Value::from).collect::<Vec<_>>());
            self.core.pending.push(Query::new(method, table.name.clone(), on, table.alias.clone().unwrap_or_default()));
        }
        Ok(())
    }
}

/// The attribute an aggregate's first argument names (`*` otherwise).
fn func_attribute(func: &Func) -> String {
    match func.arguments.first() {
        Some(Expression::Column(c)) => column_string(c),
        _ => "*".to_owned(),
    }
}

/// PHP: `Builder::astJoinConditionToQueries()`.
fn join_condition_to_queries(condition: &Expression, serializer: &Serializer) -> Result<Vec<Query>> {
    if let Expression::Binary(b) = condition {
        let operator = b.operator.to_ascii_uppercase();
        if operator == "AND" {
            let mut queries = join_condition_to_queries(&b.left, serializer)?;
            queries.extend(join_condition_to_queries(&b.right, serializer)?);
            return Ok(queries);
        }
        if operator == "OR" {
            return Ok(vec![Query::or([
                join_condition_to_query(&b.left, serializer)?,
                join_condition_to_query(&b.right, serializer)?,
            ])]);
        }
        if let (Expression::Column(left), Expression::Column(right)) = (&*b.left, &*b.right) {
            return Ok(vec![Query::on(column_string(left), column_string(right), b.operator.clone())]);
        }
    }
    match expression_to_query(condition, serializer)? {
        Some(query) if query.method().is_join_condition() => Ok(vec![query]),
        _ => Err(Error::Validation(format!(
            "Unsupported join ON condition: {}",
            serializer.serialize_expression(condition)?
        ))),
    }
}

fn join_condition_to_query(condition: &Expression, serializer: &Serializer) -> Result<Query> {
    let mut queries = join_condition_to_queries(condition, serializer)?;
    Ok(if queries.len() == 1 { queries.remove(0) } else { Query::and(queries) })
}

/// PHP: `Builder::astWhereToQueries()`.
fn where_to_queries(expression: &Expression, serializer: &Serializer) -> Result<Vec<Query>> {
    if let Expression::Binary(b) = expression
        && b.operator.eq_ignore_ascii_case("AND")
    {
        let mut queries = where_to_queries(&b.left, serializer)?;
        queries.extend(where_to_queries(&b.right, serializer)?);
        return Ok(queries);
    }
    if let Some(query) = expression_to_query(expression, serializer)? {
        return Ok(vec![query]);
    }
    Ok(vec![Query::raw(serializer.serialize_expression(expression)?, Array::default())])
}

/// PHP: `Builder::astExpressionToSingleQuery()`.
fn expression_to_query(expression: &Expression, serializer: &Serializer) -> Result<Option<Query>> {
    match expression {
        Expression::Binary(b) => {
            let operator = b.operator.to_ascii_uppercase();
            if operator == "AND" {
                let mut queries = where_to_queries(&b.left, serializer)?;
                queries.extend(where_to_queries(&b.right, serializer)?);
                return Ok(Some(Query::and(queries)));
            }
            if operator == "OR" {
                let parts: Vec<Query> =
                    [expression_to_query(&b.left, serializer)?, expression_to_query(&b.right, serializer)?]
                        .into_iter()
                        .flatten()
                        .collect();
                return Ok((!parts.is_empty()).then(|| Query::or(parts)));
            }
            if let (Expression::Column(left), Expression::Literal(Literal { value })) = (&*b.left, &*b.right) {
                let attribute = column_string(left);
                return Ok(match operator.as_str() {
                    "=" => Some(Query::equal(attribute, [value.clone()])),
                    "!=" | "<>" => Some(Query::not_equal(
                        attribute,
                        match value {
                            Value::Bool(v) => Value::Int(i64::from(*v)),
                            other => other.clone(),
                        },
                    )),
                    ">" => Some(Query::greater_than(attribute, comparable(value))),
                    ">=" => Some(Query::greater_than_equal(attribute, comparable(value))),
                    "<" => Some(Query::less_than(attribute, comparable(value))),
                    "<=" => Some(Query::less_than_equal(attribute, comparable(value))),
                    "LIKE" => Some(like_to_query(attribute, value::to_php_string(value), false)),
                    "NOT LIKE" => Some(like_to_query(attribute, value::to_php_string(value), true)),
                    _ => None,
                });
            }
            Ok(None)
        }
        Expression::In(i) => {
            let (Expression::Column(c), InList::Values(items)) = (&*i.expression, &i.list) else { return Ok(None) };
            let attribute = column_string(c);
            let values: Vec<Value> = items
                .iter()
                .map(|item| match item {
                    Expression::Literal(l) => l.value.clone(),
                    _ => Value::Null,
                })
                .collect();
            Ok(Some(if i.negated {
                Query::not_equal(attribute, Value::Array(value::list(values)))
            } else {
                Query::equal(attribute, values)
            }))
        }
        Expression::Between(b) => {
            let Expression::Column(c) = &*b.expression else { return Ok(None) };
            let bound = |e: &Expression| match e {
                Expression::Literal(l) => comparable(&l.value),
                _ => Value::Int(0),
            };
            let attribute = column_string(c);
            let (low, high) = (bound(&b.low), bound(&b.high));
            Ok(Some(if b.negated {
                Query::not_between(attribute, low, high)
            } else {
                Query::between(attribute, low, high)
            }))
        }
        Expression::Unary(u) => {
            let Expression::Column(c) = &*u.operand else { return Ok(None) };
            Ok(match u.operator.to_ascii_uppercase().as_str() {
                "IS NULL" => Some(Query::is_null(column_string(c))),
                "IS NOT NULL" => Some(Query::is_not_null(column_string(c))),
                _ => None,
            })
        }
        _ => Ok(None),
    }
}
