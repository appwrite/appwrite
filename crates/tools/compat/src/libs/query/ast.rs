//! The `ast` area of the query adapter (see [`super::engine`]): the nodes,
//! `Parser`, the serializers, `Walker` and the visitors.

use std::collections::HashMap;

use serde_json::Value as Json;
use utopia_query::ast::{
    Aliased, Between, Binary, CaseWhen, Cast, Column, ColumnValidator, Conditional, Cte, Exists, Expression,
    FilterInjector, Func, In, InList, JoinClause, Literal, OrderByItem, Parser, Placeholder, Raw, Select, Serializer,
    Star, Subquery, SubquerySource, Table, TableRenamer, TableSource, Unary, Visitor, Walker, Window, WindowDefinition,
    WindowSpecification,
};
use utopia_query::tokenizer::Token;
use utopia_query::{Error, NullsPosition, OrderDirection};

use super::engine::{Args, Callback, Cx, Failure, Object, Outcome, Ret, fault};
use crate::query_object_boilerplate;

/// A serializer with the PHP class it was made as.
#[derive(Clone)]
pub(crate) struct SerializerObject {
    class: &'static str,
    serializer: Serializer,
}

fn serializer_of(class: &str) -> Option<(&'static str, Serializer)> {
    Some(match class {
        "AST\\Serializer" => ("AST\\Serializer", Serializer::new()),
        "AST\\Serializer\\MySQL" => ("AST\\Serializer\\MySQL", Serializer::mysql()),
        "AST\\Serializer\\MariaDB" => ("AST\\Serializer\\MariaDB", Serializer::mariadb()),
        "AST\\Serializer\\ClickHouse" => ("AST\\Serializer\\ClickHouse", Serializer::clickhouse()),
        "AST\\Serializer\\PostgreSQL" => ("AST\\Serializer\\PostgreSQL", Serializer::postgresql()),
        "AST\\Serializer\\SQLite" => ("AST\\Serializer\\SQLite", Serializer::sqlite()),
        _ => return None,
    })
}

/// `new Class(...)` for the area's classes.
pub(crate) fn construct(class: &str, a: &mut Args) -> Option<Outcome> {
    if let Some((class, serializer)) = serializer_of(class) {
        return Some(Ok(Ret::obj(SerializerObject { class, serializer })));
    }
    const CLASSES: &[&str] = &[
        "AST\\Literal",
        "AST\\Placeholder",
        "AST\\Raw",
        "AST\\Star",
        "AST\\Reference\\Column",
        "AST\\Reference\\Table",
        "AST\\Call\\Func",
        "AST\\Expression\\Aliased",
        "AST\\Expression\\Between",
        "AST\\Expression\\Binary",
        "AST\\Expression\\CaseWhen",
        "AST\\Expression\\Cast",
        "AST\\Expression\\Conditional",
        "AST\\Expression\\Exists",
        "AST\\Expression\\In",
        "AST\\Expression\\Subquery",
        "AST\\Expression\\Unary",
        "AST\\Expression\\Window",
        "AST\\JoinClause",
        "AST\\OrderByItem",
        "AST\\SubquerySource",
        "AST\\Statement\\Select",
        "AST\\Specification\\Window",
        "AST\\Definition\\Cte",
        "AST\\Definition\\Window",
        "AST\\Parser",
        "AST\\Walker",
        "AST\\Visitor\\ColumnValidator",
        "AST\\Visitor\\FilterInjector",
        "AST\\Visitor\\TableRenamer",
    ];
    if !CLASSES.contains(&class) {
        return None;
    }
    Some((|| -> Outcome {
        Ok(match class {
            "AST\\Literal" => Ret::obj(Expression::from(Literal { value: a.value(0, "value")? })),
            "AST\\Placeholder" => Ret::obj(Expression::from(Placeholder::new(a.str(0, "value")?))),
            "AST\\Raw" => Ret::obj(Expression::from(Raw::new(a.str(0, "sql")?))),
            "AST\\Star" => {
                Ret::obj(Expression::from(Star { table: a.opt_str(0, "table")?, schema: a.opt_str(1, "schema")? }))
            }
            "AST\\Reference\\Column" => Ret::obj(Expression::from(Column {
                name: a.str(0, "name")?,
                table: a.opt_str(1, "table")?,
                schema: a.opt_str(2, "schema")?,
            })),
            "AST\\Reference\\Table" => Ret::obj(Table {
                name: a.str(0, "name")?,
                alias: a.opt_str(1, "alias")?,
                schema: a.opt_str(2, "schema")?,
            }),
            "AST\\Call\\Func" => Ret::obj(Expression::from(Func {
                name: a.str(0, "name")?,
                arguments: expressions(a, 1, "arguments")?,
                distinct: a.bool_or(2, "distinct", false)?,
                filter: opt_expression(a, 3, "filter")?.map(Box::new),
            })),
            "AST\\Expression\\Aliased" => {
                Ret::obj(Expression::from(Aliased::new(expression(a, 0, "expression")?, a.str(1, "alias")?)))
            }
            "AST\\Expression\\Between" => Ret::obj(Expression::from(Between::new(
                expression(a, 0, "expression")?,
                expression(a, 1, "low")?,
                expression(a, 2, "high")?,
                a.bool_or(3, "negated", false)?,
            ))),
            "AST\\Expression\\Binary" => Ret::obj(Expression::from(Binary::new(
                expression(a, 0, "left")?,
                a.str(1, "operator")?,
                expression(a, 2, "right")?,
            ))),
            "AST\\Expression\\CaseWhen" => {
                Ret::obj(Expression::from(CaseWhen::new(expression(a, 0, "condition")?, expression(a, 1, "result")?)))
            }
            "AST\\Expression\\Cast" => {
                Ret::obj(Expression::from(Cast::new(expression(a, 0, "expression")?, a.str(1, "type")?)))
            }
            "AST\\Expression\\Conditional" => {
                let whens = a
                    .items(1, "whens")?
                    .into_iter()
                    .map(|raw| match a.object_of::<Expression>(raw)? {
                        Expression::CaseWhen(when) => Ok(when),
                        other => Err(fault(format!("{} is not a CaseWhen", other.php_class()))),
                    })
                    .collect::<Result<_, Failure>>()?;
                Ret::obj(Expression::from(Conditional {
                    operand: opt_expression(a, 0, "operand")?.map(Box::new),
                    whens,
                    else_result: opt_expression(a, 2, "else")?.map(Box::new),
                }))
            }
            "AST\\Expression\\Exists" => {
                Ret::obj(Expression::from(Exists::new(a.object(0, "subquery")?, a.bool_or(1, "negated", false)?)))
            }
            "AST\\Expression\\In" => {
                let raw = a.raw(1, "list").ok_or_else(|| fault("missing argument $list"))?;
                let list = match raw {
                    Json::Object(o) if o.contains_key("$o") => InList::Subquery(Box::new(a.object_of(raw)?)),
                    _ => InList::Values(expressions(a, 1, "list")?),
                };
                Ret::obj(Expression::from(In::new(
                    expression(a, 0, "expression")?,
                    list,
                    a.bool_or(2, "negated", false)?,
                )))
            }
            "AST\\Expression\\Subquery" => Ret::obj(Expression::from(Subquery::new(a.object(0, "query")?))),
            "AST\\Expression\\Unary" => Ret::obj(Expression::from(Unary::new(
                a.str(0, "operator")?,
                expression(a, 1, "operand")?,
                a.bool_or(2, "prefix", true)?,
            ))),
            "AST\\Expression\\Window" => Ret::obj(Expression::from(Window {
                function: Box::new(expression(a, 0, "function")?),
                window_name: a.opt_str(1, "windowName")?,
                specification: a.opt_object(2, "specification")?.map(Box::new),
            })),
            "AST\\JoinClause" => Ret::obj(JoinClause {
                kind: a.str(0, "type")?,
                table: source(a, a.raw(1, "table").ok_or_else(|| fault("missing argument $table"))?)?,
                condition: opt_expression(a, 2, "condition")?,
            }),
            "AST\\OrderByItem" => Ret::obj(OrderByItem {
                expression: expression(a, 0, "expression")?,
                direction: match a.enum_case(1, "direction")? {
                    Some((_, case)) => {
                        OrderDirection::from_name(&case).ok_or_else(|| fault(format!("no OrderDirection::{case}")))?
                    }
                    None => OrderDirection::Asc,
                },
                nulls: match a.enum_case(2, "nulls")? {
                    Some((_, case)) => Some(
                        NullsPosition::from_name(&case).ok_or_else(|| fault(format!("no NullsPosition::{case}")))?,
                    ),
                    None => None,
                },
            }),
            "AST\\SubquerySource" => Ret::obj(SubquerySource::new(a.object(0, "query")?, a.str(1, "alias")?)),
            "AST\\Statement\\Select" => Ret::obj(Select {
                columns: opt_list(a, 0, "columns", expression_of)?.unwrap_or_default(),
                from: opt_source(a, 1, "from")?,
                joins: opt_list(a, 2, "joins", |a, raw| a.object_of(raw))?.unwrap_or_default(),
                where_clause: opt_expression(a, 3, "where")?,
                group_by: opt_list(a, 4, "groupBy", expression_of)?.unwrap_or_default(),
                having: opt_expression(a, 5, "having")?,
                order_by: opt_list(a, 6, "orderBy", |a, raw| a.object_of(raw))?.unwrap_or_default(),
                limit: opt_expression(a, 7, "limit")?,
                offset: opt_expression(a, 8, "offset")?,
                distinct: a.bool_or(9, "distinct", false)?,
                ctes: opt_list(a, 10, "ctes", |a, raw| a.object_of(raw))?.unwrap_or_default(),
                windows: opt_list(a, 11, "windows", |a, raw| a.object_of(raw))?.unwrap_or_default(),
            }),
            "AST\\Specification\\Window" => Ret::obj(WindowSpecification {
                partition_by: opt_list(a, 0, "partitionBy", expression_of)?.unwrap_or_default(),
                order_by: opt_list(a, 1, "orderBy", |a, raw| a.object_of(raw))?.unwrap_or_default(),
                frame_type: a.opt_str(2, "frameType")?,
                frame_start: a.opt_str(3, "frameStart")?,
                frame_end: a.opt_str(4, "frameEnd")?,
            }),
            "AST\\Definition\\Cte" => Ret::obj(Cte {
                name: a.str(0, "name")?,
                query: a.object(1, "query")?,
                columns: a.strings_or_empty(2, "columns")?,
                recursive: a.bool_or(3, "recursive", false)?,
            }),
            "AST\\Definition\\Window" => {
                Ret::obj(WindowDefinition { name: a.str(0, "name")?, specification: a.object(1, "specification")? })
            }
            "AST\\Parser" => Ret::obj(Parser::new()),
            "AST\\Walker" => Ret::obj(Walker::new()),
            "AST\\Visitor\\ColumnValidator" => {
                Ret::obj(ColumnValidator::new(a.strings(0, "allowedColumns")?, a.bool_or(1, "allowStar", false)?))
            }
            "AST\\Visitor\\FilterInjector" => Ret::obj(FilterInjector::new(expression(a, 0, "condition")?)),
            "AST\\Visitor\\TableRenamer" => {
                let mut renames = HashMap::new();
                for (key, raw) in a.entries(0, "renames")? {
                    let key = match key {
                        php_std::ArrayKey::Int(i) => i.to_string(),
                        php_std::ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                    };
                    let value = a.cx().decoded(raw).str(0, "renames")?;
                    renames.entry(key).or_insert(value);
                }
                Ret::obj(TableRenamer::new(renames))
            }
            _ => return Err(fault(format!("no constructor for {class}"))),
        })
    })())
}

/// `Class::method(...)` for the area's classes.
pub(crate) fn call_static(_class: &str, _method: &str, _args: &mut Args) -> Option<Outcome> {
    None
}

/// `Class::CONSTANT` for the area's classes.
pub(crate) fn constant(_class: &str, _name: &str) -> Option<Ret> {
    None
}

/// `Class::Case->method(...)` (and `->value` as method `->value`) for the area's enums.
pub(crate) fn enum_call(_class: &str, _case: &str, _method: &str, _args: &mut Args) -> Option<Outcome> {
    None
}

fn expression_of(a: &Args, raw: &Json) -> Result<Expression, Failure> {
    a.object_of::<Expression>(raw)
}

fn expression(a: &Args, index: usize, name: &str) -> Result<Expression, Failure> {
    a.object::<Expression>(index, name)
}

fn opt_expression(a: &Args, index: usize, name: &str) -> Result<Option<Expression>, Failure> {
    a.opt_object::<Expression>(index, name)
}

fn expressions(a: &Args, index: usize, name: &str) -> Result<Vec<Expression>, Failure> {
    opt_list(a, index, name, expression_of).map(Option::unwrap_or_default)
}

/// An array argument decoded item by item (`None` when not given or null).
fn opt_list<T>(
    a: &Args,
    index: usize,
    name: &str,
    each: impl Fn(&Args, &Json) -> Result<T, Failure>,
) -> Result<Option<Vec<T>>, Failure> {
    match a.raw(index, name) {
        None | Some(Json::Null) => Ok(None),
        Some(_) => a.items(index, name)?.into_iter().map(|raw| each(a, raw)).collect::<Result<_, _>>().map(Some),
    }
}

/// A `Table|SubquerySource` program value.
fn source(a: &Args, raw: &Json) -> Result<TableSource, Failure> {
    if let Ok(table) = a.object_of::<Table>(raw) {
        return Ok(TableSource::Table(table));
    }
    Ok(TableSource::Subquery(a.object_of::<SubquerySource>(raw)?))
}

fn opt_source(a: &Args, index: usize, name: &str) -> Result<Option<TableSource>, Failure> {
    match a.raw(index, name) {
        None | Some(Json::Null) => Ok(None),
        Some(raw) => source(a, raw).map(Some),
    }
}

fn expr_ret(expression: &Expression) -> Ret {
    Ret::obj(expression.clone())
}

fn opt_expr_ret(expression: Option<&Expression>) -> Ret {
    expression.map_or_else(Ret::null, expr_ret)
}

fn exprs_ret(expressions: &[Expression]) -> Ret {
    Ret::List(expressions.iter().map(expr_ret).collect())
}

fn list_ret<T: Object + Clone>(items: &[T]) -> Ret {
    Ret::List(items.iter().cloned().map(Ret::obj).collect())
}

fn source_ret(source: &TableSource) -> Ret {
    match source {
        TableSource::Table(t) => Ret::obj(t.clone()),
        TableSource::Subquery(s) => Ret::obj(s.clone()),
    }
}

fn select_ret(select: &Select) -> Ret {
    Ret::obj(select.clone())
}

fn opt_string(s: &Option<String>) -> Ret {
    Ret::opt_str(s.as_deref())
}

const EXPRESSION: &[&str] = &["AST\\Expression"];
const VISITOR: &[&str] = &["AST\\Visitor"];

impl Object for Expression {
    fn class(&self) -> String {
        format!("AST\\{}", self.php_class())
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        match self {
            Expression::Func(f) => vec![
                ("name", Ret::str(&f.name)),
                ("arguments", exprs_ret(&f.arguments)),
                ("distinct", Ret::bool(f.distinct)),
                ("filter", opt_expr_ret(f.filter.as_deref())),
            ],
            Expression::Aliased(x) => vec![("expression", expr_ret(&x.expression)), ("alias", Ret::str(&x.alias))],
            Expression::Between(x) => vec![
                ("expression", expr_ret(&x.expression)),
                ("low", expr_ret(&x.low)),
                ("high", expr_ret(&x.high)),
                ("negated", Ret::bool(x.negated)),
            ],
            Expression::Binary(x) => {
                vec![("left", expr_ret(&x.left)), ("operator", Ret::str(&x.operator)), ("right", expr_ret(&x.right))]
            }
            Expression::CaseWhen(x) => vec![("condition", expr_ret(&x.condition)), ("result", expr_ret(&x.result))],
            Expression::Cast(x) => vec![("expression", expr_ret(&x.expression)), ("type", Ret::str(&x.data_type))],
            Expression::Conditional(x) => vec![
                ("operand", opt_expr_ret(x.operand.as_deref())),
                ("whens", Ret::List(x.whens.iter().map(|w| Ret::obj(Expression::CaseWhen(w.clone()))).collect())),
                ("else", opt_expr_ret(x.else_result.as_deref())),
            ],
            Expression::Exists(x) => vec![("subquery", select_ret(&x.subquery)), ("negated", Ret::bool(x.negated))],
            Expression::In(x) => vec![
                ("expression", expr_ret(&x.expression)),
                (
                    "list",
                    match &x.list {
                        InList::Values(items) => exprs_ret(items),
                        InList::Subquery(query) => select_ret(query),
                    },
                ),
                ("negated", Ret::bool(x.negated)),
            ],
            Expression::Subquery(x) => vec![("query", select_ret(&x.query))],
            Expression::Unary(x) => vec![
                ("operator", Ret::str(&x.operator)),
                ("operand", expr_ret(&x.operand)),
                ("prefix", Ret::bool(x.prefix)),
            ],
            Expression::Window(x) => vec![
                ("function", expr_ret(&x.function)),
                ("windowName", opt_string(&x.window_name)),
                ("specification", x.specification.as_deref().cloned().map_or_else(Ret::null, Ret::obj)),
            ],
            Expression::Literal(x) => vec![("value", Ret::Value(x.value.clone()))],
            Expression::Placeholder(x) => vec![("value", Ret::str(&x.value))],
            Expression::Raw(x) => vec![("sql", Ret::str(&x.sql))],
            Expression::Column(x) => {
                vec![("name", Ret::str(&x.name)), ("table", opt_string(&x.table)), ("schema", opt_string(&x.schema))]
            }
            Expression::Star(x) => vec![("table", opt_string(&x.table)), ("schema", opt_string(&x.schema))],
        }
    }
    fn call(&mut self, _method: &str, _args: &mut Args) -> Option<Outcome> {
        None
    }
    fn interfaces(&self) -> &'static [&'static str] {
        EXPRESSION
    }
    query_object_boilerplate!();
}

impl Object for Select {
    fn class(&self) -> String {
        "AST\\Statement\\Select".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![
            ("columns", exprs_ret(&self.columns)),
            ("from", self.from.as_ref().map_or_else(Ret::null, source_ret)),
            ("joins", list_ret(&self.joins)),
            ("where", opt_expr_ret(self.where_clause.as_ref())),
            ("groupBy", exprs_ret(&self.group_by)),
            ("having", opt_expr_ret(self.having.as_ref())),
            ("orderBy", list_ret(&self.order_by)),
            ("limit", opt_expr_ret(self.limit.as_ref())),
            ("offset", opt_expr_ret(self.offset.as_ref())),
            ("distinct", Ret::bool(self.distinct)),
            ("ctes", list_ret(&self.ctes)),
            ("windows", list_ret(&self.windows)),
        ]
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        if method != "with" {
            return None;
        }
        Some((|| -> Outcome {
            // Nullable properties default to `false`: "not passed".
            let given = |index: usize, name: &str| match a.raw(index, name) {
                None | Some(Json::Bool(false)) => None,
                Some(raw) => Some(raw),
            };
            let mut s = self.clone();
            if let Some(columns) = opt_list(a, 0, "columns", expression_of)? {
                s.columns = columns;
            }
            if let Some(raw) = given(1, "from") {
                s.from = if raw.is_null() { None } else { Some(source(a, raw)?) };
            }
            if let Some(joins) = opt_list(a, 2, "joins", |a, raw| a.object_of(raw))? {
                s.joins = joins;
            }
            let expression_at = |index: usize, name: &str| -> Result<Option<Option<Expression>>, Failure> {
                match given(index, name) {
                    None => Ok(None),
                    Some(Json::Null) => Ok(Some(None)),
                    Some(raw) => Ok(Some(Some(expression_of(a, raw)?))),
                }
            };
            if let Some(e) = expression_at(3, "where")? {
                s.where_clause = e;
            }
            if let Some(group_by) = opt_list(a, 4, "groupBy", expression_of)? {
                s.group_by = group_by;
            }
            if let Some(e) = expression_at(5, "having")? {
                s.having = e;
            }
            if let Some(order_by) = opt_list(a, 6, "orderBy", |a, raw| a.object_of(raw))? {
                s.order_by = order_by;
            }
            if let Some(e) = expression_at(7, "limit")? {
                s.limit = e;
            }
            if let Some(e) = expression_at(8, "offset")? {
                s.offset = e;
            }
            if a.raw(9, "distinct").is_some_and(|raw| !raw.is_null()) {
                s.distinct = a.bool(9, "distinct")?;
            }
            if let Some(ctes) = opt_list(a, 10, "ctes", |a, raw| a.object_of(raw))? {
                s.ctes = ctes;
            }
            if let Some(windows) = opt_list(a, 11, "windows", |a, raw| a.object_of(raw))? {
                s.windows = windows;
            }
            Ok(Ret::obj(s))
        })())
    }
    query_object_boilerplate!();
}

impl Object for Table {
    fn class(&self) -> String {
        "AST\\Reference\\Table".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![("name", Ret::str(&self.name)), ("alias", opt_string(&self.alias)), ("schema", opt_string(&self.schema))]
    }
    fn call(&mut self, _method: &str, _args: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}

impl Object for SubquerySource {
    fn class(&self) -> String {
        "AST\\SubquerySource".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![("query", select_ret(&self.query)), ("alias", Ret::str(&self.alias))]
    }
    fn call(&mut self, _method: &str, _args: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}

impl Object for JoinClause {
    fn class(&self) -> String {
        "AST\\JoinClause".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![
            ("type", Ret::str(&self.kind)),
            ("table", source_ret(&self.table)),
            ("condition", opt_expr_ret(self.condition.as_ref())),
        ]
    }
    fn call(&mut self, _method: &str, _args: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}

impl Object for OrderByItem {
    fn class(&self) -> String {
        "AST\\OrderByItem".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![
            ("expression", expr_ret(&self.expression)),
            ("direction", Ret::Enum("OrderDirection".into(), self.direction.name())),
            ("nulls", self.nulls.map_or_else(Ret::null, |n| Ret::Enum("NullsPosition".into(), n.name()))),
        ]
    }
    fn call(&mut self, _method: &str, _args: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}

impl Object for Cte {
    fn class(&self) -> String {
        "AST\\Definition\\Cte".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![
            ("name", Ret::str(&self.name)),
            ("query", select_ret(&self.query)),
            ("columns", Ret::strings(&self.columns)),
            ("recursive", Ret::bool(self.recursive)),
        ]
    }
    fn call(&mut self, _method: &str, _args: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}

impl Object for WindowDefinition {
    fn class(&self) -> String {
        "AST\\Definition\\Window".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![("name", Ret::str(&self.name)), ("specification", Ret::obj(self.specification.clone()))]
    }
    fn call(&mut self, _method: &str, _args: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}

impl Object for WindowSpecification {
    fn class(&self) -> String {
        "AST\\Specification\\Window".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![
            ("partitionBy", exprs_ret(&self.partition_by)),
            ("orderBy", list_ret(&self.order_by)),
            ("frameType", opt_string(&self.frame_type)),
            ("frameStart", opt_string(&self.frame_start)),
            ("frameEnd", opt_string(&self.frame_end)),
        ]
    }
    fn call(&mut self, _method: &str, _args: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}

impl Object for Parser {
    fn class(&self) -> String {
        "AST\\Parser".into()
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        if method != "parse" {
            return None;
        }
        Some((|| -> Outcome {
            let tokens = a
                .items(0, "tokens")?
                .into_iter()
                .map(|raw| a.object_of::<Token<'static>>(raw))
                .collect::<Result<Vec<_>, _>>()?;
            Ok(Ret::obj(self.parse(&tokens)?))
        })())
    }
    query_object_boilerplate!();
}

impl Object for SerializerObject {
    fn class(&self) -> String {
        self.class.into()
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        Some((|| -> Outcome {
            Ok(match method {
                "serialize" => Ret::str(self.serializer.serialize(&a.object::<Select>(0, "stmt")?)?),
                "serializeExpression" => {
                    Ret::str(self.serializer.serialize_expression(&expression(a, 0, "expression")?)?)
                }
                _ => return Err(fault(format!("{} has no method {method}", self.class))),
            })
        })())
    }
    fn parents(&self) -> &'static [&'static str] {
        match self.class {
            "AST\\Serializer" => &[],
            "AST\\Serializer\\MariaDB" => &["AST\\Serializer\\MySQL", "AST\\Serializer"],
            _ => &["AST\\Serializer"],
        }
    }
    query_object_boilerplate!();
}

impl Object for Walker {
    fn class(&self) -> String {
        "AST\\Walker".into()
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        if method != "walk" {
            return None;
        }
        Some((|| -> Outcome {
            let select = a.object::<Select>(0, "stmt")?;
            let mut visitor = visitor_arg(a, 1, "visitor")?;
            Ok(Ret::obj(self.walk(select, &mut *visitor)?))
        })())
    }
    query_object_boilerplate!();
}

/// A visitor argument: a test object implementing `AST\Visitor`, or one of the library's visitors.
fn visitor_arg(a: &Args, index: usize, name: &str) -> Result<Box<dyn Visitor>, Failure> {
    if let Some((k, _)) = a.test_object(index, name)? {
        return Ok(Box::new(TestVisitor { cx: a.cx().clone(), k }));
    }
    a.with(index, name, |o| -> Result<Box<dyn Visitor>, Failure> {
        let any = o.as_any();
        if let Some(v) = any.downcast_ref::<ColumnValidator>() {
            Ok(Box::new(v.clone()))
        } else if let Some(v) = any.downcast_ref::<FilterInjector>() {
            Ok(Box::new(v.clone()))
        } else if let Some(v) = any.downcast_ref::<TableRenamer>() {
            Ok(Box::new(v.clone()))
        } else {
            Err(fault(format!("{} is not a visitor", o.class())))
        }
    })?
}

fn lib_error(f: Failure) -> Error {
    match f {
        Failure::Lib(e) => e,
        Failure::Engine(_, m) | Failure::Fault(m) => Error::new(m, 0),
    }
}

/// A test object implementing `AST\Visitor`: each visit calls its method.
struct TestVisitor {
    cx: Cx,
    k: i64,
}

impl TestVisitor {
    fn visit<T: Object + Clone>(&self, method: &str, node: T) -> utopia_query::Result<T> {
        let callback = Callback::method(&self.cx, self.k, method);
        let ret = callback.invoke(vec![Ret::obj(node)]).map_err(lib_error)?;
        callback.cx().decoded(&ret).object::<T>(0, "ret").map_err(lib_error)
    }
}

impl Visitor for TestVisitor {
    fn visit_expression(&mut self, expression: Expression) -> utopia_query::Result<Expression> {
        self.visit("visitExpression", expression)
    }
    fn visit_table_reference(&mut self, reference: Table) -> utopia_query::Result<Table> {
        self.visit("visitTableReference", reference)
    }
    fn visit_select(&mut self, select: Select) -> utopia_query::Result<Select> {
        self.visit("visitSelect", select)
    }
}

/// Calls a visitor's method on the program's arguments.
fn visit_call(visitor: &mut dyn Visitor, method: &str, a: &Args) -> Option<Outcome> {
    Some((|| -> Outcome {
        Ok(match method {
            "visitExpression" => Ret::obj(visitor.visit_expression(expression(a, 0, "expression")?)?),
            "visitTableReference" => Ret::obj(visitor.visit_table_reference(a.object::<Table>(0, "reference")?)?),
            "visitSelect" => Ret::obj(visitor.visit_select(a.object::<Select>(0, "stmt")?)?),
            _ => return Err(fault(format!("visitor has no method {method}"))),
        })
    })())
}

macro_rules! visitor_object {
    ($ty:ty, $class:literal) => {
        impl Object for $ty {
            fn class(&self) -> String {
                $class.into()
            }
            fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
                visit_call(self, method, a)
            }
            fn interfaces(&self) -> &'static [&'static str] {
                VISITOR
            }
            query_object_boilerplate!();
        }
    };
}

visitor_object!(ColumnValidator, "AST\\Visitor\\ColumnValidator");
visitor_object!(FilterInjector, "AST\\Visitor\\FilterInjector");
visitor_object!(TableRenamer, "AST\\Visitor\\TableRenamer");
