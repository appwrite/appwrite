//! The AST's engines: `AST\Grammar`, `AST\Parser`, and `AST\Serializer` with its dialects
//! `MySQL`, `MariaDB`, `PostgreSQL`, `SQLite` and `ClickHouse`.
//!
//! The nodes stay the package's own PHP classes: they are readonly data that PHP code builds,
//! reads and matches with `instanceof`. The parser returns them and the serializers read them,
//! both through their property slots. `AST\Walker` and the visitors stay PHP too, since a walk
//! is calls into a PHP visitor and nothing else.
//!
//! Strings: PHP's are bytes and the crate's nodes hold `String`s, so every string crossing in
//! is widened (a byte above 127 becomes the character of that number) and every string
//! crossing out is narrowed back. The crate only compares against and adds ASCII, so PHP gets
//! back exactly the bytes it gave.
//!
//! Stack: PHP recurses on its own heap and takes a tree as deep as its memory allows. Reading,
//! writing and dropping a tree here recurse on the native stack, which a coroutine keeps
//! small, so a tree deeper than [`INLINE_LEVELS`] is worked on a stack allocated for its
//! depth. Nothing that runs there calls into PHP code.

use std::borrow::Cow;
use std::cell::Cell;

use ext_php_rs::convert::{FromZval, IntoZval};
use ext_php_rs::exception::PhpException;
use ext_php_rs::ffi::zend_property_info;
use ext_php_rs::flags::{ClassFlags, DataType};
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ExecutorGlobals, ce};
use utopia_query::ast;
use utopia_query::tokenizer::{Token, TokenType};
use utopia_query::{NullsPosition, OrderDirection, StringLiteral, Value};

use super::tokenizer::{case, class};
use super::{Rules, exception};
use crate::emails::{construct, string};
use crate::string::PhpString;

const NAMESPACE: &str = "Utopia\\Query\\AST\\";
const EXPRESSION: &str = "Utopia\\Query\\AST\\Expression";
const SELECT: &str = "Utopia\\Query\\AST\\Statement\\Select";
const TOKEN: &str = "Utopia\\Query\\Tokenizer\\Token";
const TOKEN_TYPE: &str = "Utopia\\Query\\Tokenizer\\TokenType";
const ORDER_DIRECTION: &str = "Utopia\\Query\\OrderDirection";
const NULLS_POSITION: &str = "Utopia\\Query\\NullsPosition";

/// A tree up to this deep is read and written on the caller's stack.
const INLINE_LEVELS: usize = 32;

/// As [`INLINE_LEVELS`] for parsing, whose level count is a bound from the tokens (far above
/// what a statement really nests) and whose steps on this stack are the lightest.
const INLINE_PARSE_LEVELS: usize = 256;

/// The stack allowed for one level of a tree. Measured in a release build, the heaviest step
/// (reading a node) takes 3.1 KB a level, building one 1.1 KB, measuring 0.4 KB, the crate's
/// serializer 0.7 KB and its drop 0.2 KB; a debug build takes several times that.
const LEVEL_BYTES: usize = if cfg!(debug_assertions) { 64 << 10 } else { 8 << 10 };

/// The deepest tree taken. PHP has no such limit, but overflows its own stack freeing a tree
/// of some tens of thousands of levels.
const MAX_LEVELS: usize = 100_000;

/// `work` on a stack with room for a tree `levels` deep, the caller's when `inline` covers it.
fn roomy<T>(levels: usize, inline: usize, work: impl FnOnce() -> T) -> T {
    if levels <= inline { work() } else { stacker::grow(levels * LEVEL_BYTES + (64 << 10), work) }
}

/// `bytes` as text: a byte above 127 is the character of that number.
pub(super) fn wide(bytes: &[u8]) -> Cow<'_, str> {
    match std::str::from_utf8(bytes) {
        Ok(text) if bytes.is_ascii() => Cow::Borrowed(text),
        _ => Cow::Owned(bytes.iter().map(|&byte| char::from(byte)).collect()),
    }
}

/// The bytes [`wide`] read `text` from.
pub(super) fn narrow(text: &str) -> Cow<'_, [u8]> {
    if text.is_ascii() {
        Cow::Borrowed(text.as_bytes())
    } else {
        Cow::Owned(text.chars().map(|c| u8::try_from(u32::from(c)).unwrap_or(b'?')).collect())
    }
}

/// What went wrong on a stack that must not call PHP: raised once back on PHP's.
enum Failure {
    Type(String),
    Uninitialized(String),
    Query(utopia_query::Error),
}

impl From<utopia_query::Error> for Failure {
    fn from(error: utopia_query::Error) -> Self {
        Failure::Query(error)
    }
}

type Read<T> = Result<T, Failure>;

fn too_deep() -> utopia_query::Error {
    utopia_query::Error::new("Expression nesting too deep", 0)
}

/// An error of the crate as the package's exception, its message narrowed back to bytes.
pub(super) fn failed(error: &utopia_query::Error) -> PhpException {
    let message = error.message_bytes();
    match std::str::from_utf8(&message) {
        Ok(text) if !text.is_ascii() => {
            let class = ClassEntry::try_find(error.php_class()).unwrap_or_else(ce::exception);
            match construct(class, &mut [string(&narrow(text))])
                .and_then(|object| object.into_zval(false).map_err(PhpException::from))
            {
                Ok(object) => PhpException::new(error.to_string(), 0, class).with_object(object),
                Err(failed) => failed,
            }
        }
        _ => exception(error),
    }
}

fn raised(failure: Failure) -> PhpException {
    match failure {
        Failure::Type(message) => PhpException::new(message, 0, ce::type_error()),
        Failure::Uninitialized(message) => PhpException::new(message, 0, ce::error()),
        Failure::Query(error) => failed(&error),
    }
}

/// The node classes, in the order of [`NODES`].
#[derive(Clone, Copy, PartialEq, Eq)]
enum Node {
    Literal,
    Placeholder,
    Raw,
    Star,
    Column,
    Table,
    Cte,
    WindowDefinition,
    Aliased,
    Between,
    Binary,
    CaseWhen,
    Cast,
    Conditional,
    Exists,
    In,
    Subquery,
    Unary,
    Window,
    SubquerySource,
    JoinClause,
    OrderByItem,
    WindowSpecification,
    Func,
    Select,
}

/// Each node class: its name under `Utopia\Query\AST\` and its properties in slot order, which
/// is the order of its constructor's parameters.
const NODES: [(Node, &str, &[&str]); 25] = [
    (Node::Literal, "Literal", &["value"]),
    (Node::Placeholder, "Placeholder", &["value"]),
    (Node::Raw, "Raw", &["sql"]),
    (Node::Star, "Star", &["table", "schema"]),
    (Node::Column, "Reference\\Column", &["name", "table", "schema"]),
    (Node::Table, "Reference\\Table", &["name", "alias", "schema"]),
    (Node::Cte, "Definition\\Cte", &["name", "query", "columns", "recursive"]),
    (Node::WindowDefinition, "Definition\\Window", &["name", "specification"]),
    (Node::Aliased, "Expression\\Aliased", &["expression", "alias"]),
    (Node::Between, "Expression\\Between", &["expression", "low", "high", "negated"]),
    (Node::Binary, "Expression\\Binary", &["left", "operator", "right", "escape"]),
    (Node::CaseWhen, "Expression\\CaseWhen", &["condition", "result"]),
    (Node::Cast, "Expression\\Cast", &["expression", "type"]),
    (Node::Conditional, "Expression\\Conditional", &["operand", "whens", "else"]),
    (Node::Exists, "Expression\\Exists", &["subquery", "negated"]),
    (Node::In, "Expression\\In", &["expression", "list", "negated"]),
    (Node::Subquery, "Expression\\Subquery", &["query"]),
    (Node::Unary, "Expression\\Unary", &["operator", "operand", "prefix"]),
    (Node::Window, "Expression\\Window", &["function", "windowName", "specification"]),
    (Node::SubquerySource, "SubquerySource", &["query", "alias"]),
    (Node::JoinClause, "JoinClause", &["type", "table", "condition"]),
    (Node::OrderByItem, "OrderByItem", &["expression", "direction", "nulls"]),
    (
        Node::WindowSpecification,
        "Specification\\Window",
        &["partitionBy", "orderBy", "frameType", "frameStart", "frameEnd"],
    ),
    (Node::Func, "Call\\Func", &["name", "arguments", "distinct", "filter"]),
    (
        Node::Select,
        "Statement\\Select",
        &[
            "columns", "from", "joins", "where", "groupBy", "having", "orderBy", "limit", "offset", "distinct", "ctes",
            "windows",
        ],
    ),
];

/// The package's classes and enum cases, found once a request.
#[derive(Clone, Copy)]
struct Classes {
    nodes: [&'static ClassEntry; NODES.len()],
    expression: &'static ClassEntry,
    token: &'static ClassEntry,
    kinds: [*const ZendObject; TokenType::CASES.len()],
    /// `Asc`, `Desc`, `Random`.
    directions: [*mut ZendObject; 3],
    /// `First`, `Last`.
    nulls: [*mut ZendObject; 2],
}

thread_local! {
    static CLASSES: Cell<Option<Classes>> = const { Cell::new(None) };
}

/// Forgets the classes at the end of a request, when PHP frees them.
pub fn request_shutdown() {
    CLASSES.set(None);
}

/// Whether `class` declares exactly `names`, each in the slot of its position. Nodes are read
/// and written by slot, so a package whose classes differ must be refused, not misread.
fn laid_out(class: &ClassEntry, names: &[&str]) -> bool {
    let first = std::mem::offset_of!(ZendObject, properties_table);
    usize::try_from(class.default_properties_count) == Ok(names.len())
        && names.iter().enumerate().all(|(index, name)| {
            let info = class.properties_info.get(*name).filter(|found| found.is_ptr());
            // SAFETY: a class's `properties_info` holds a pointer to a `zend_property_info`
            // for each property it declares.
            let offset = info.map(|found| unsafe { (*found.value.ptr.cast::<zend_property_info>()).offset });
            offset.and_then(|offset| usize::try_from(offset).ok()) == Some(first + index * size_of::<Zval>())
        })
}

fn enum_case(class: &ClassEntry, name: &str) -> *mut ZendObject {
    let mut name = ZendStr::new(name, false);
    // SAFETY: `class` is a loaded enum of the package and `name` one of its cases, which is
    // what `zend_enum_get_case` requires; the object it returns lives as long as the class.
    unsafe { ext_php_rs::ffi::zend_enum_get_case(std::ptr::from_ref(class).cast_mut(), &raw mut *name) }
}

fn classes() -> PhpResult<Classes> {
    if let Some(found) = CLASSES.get() {
        return Ok(found);
    }
    let differs = |name: &str| {
        PhpException::new(format!("{name} is not the class the utopia extension was built for"), 0, ce::error())
    };
    let expression = class(EXPRESSION)?;
    let mut nodes = [expression; NODES.len()];
    for (index, (_, name, properties)) in NODES.iter().enumerate() {
        let name = format!("{NAMESPACE}{name}");
        nodes[index] = class(&name)?;
        if !laid_out(nodes[index], properties) {
            return Err(differs(&name));
        }
    }
    let token = class(TOKEN)?;
    if !laid_out(token, &["type", "value", "position"]) {
        return Err(differs(TOKEN));
    }
    let token_type = class(TOKEN_TYPE)?;
    let direction = class(ORDER_DIRECTION)?;
    let nulls = class(NULLS_POSITION)?;
    let found = Classes {
        nodes,
        expression,
        token,
        kinds: std::array::from_fn(|index| case(token_type, TokenType::CASES[index]).cast_const()),
        directions: ["Asc", "Desc", "Random"].map(|name| enum_case(direction, name)),
        nulls: ["First", "Last"].map(|name| enum_case(nulls, name)),
    };
    CLASSES.set(Some(found));
    Ok(found)
}

impl Classes {
    /// The node class `object` is an instance of: its own class or the nearest parent.
    fn node(&self, object: &ZendObject) -> Option<Node> {
        let mut class = Some(object.get_class_entry());
        while let Some(entry) = class {
            if let Some(index) = self.nodes.iter().position(|node| std::ptr::eq(*node, entry)) {
                return Some(NODES[index].0);
            }
            class = entry.parent();
        }
        None
    }
}

// ---------------------------------------------------------------------------------------
// PHP nodes to the crate's
// ---------------------------------------------------------------------------------------

/// The declared property slots of `object`.
pub(super) fn slots(object: &ZendObject) -> &[Zval] {
    let declared = usize::try_from(object.get_class_entry().default_properties_count).unwrap_or(0);
    // SAFETY: an object holds its class's `default_properties_count` slots after its header.
    unsafe { std::slice::from_raw_parts(object.properties_table.as_ptr(), declared) }
}

/// The property in slot `index` of a node, whose class [`laid_out`] has checked.
fn slot<'a>(object: &'a ZendObject, index: usize, name: &str) -> Read<&'a Zval> {
    // SAFETY: a slot's type tag is readable in any state; 0 is a property never initialized.
    slots(object).get(index).filter(|value| unsafe { value.u1.type_info } != 0).ok_or_else(|| {
        Failure::Uninitialized(format!(
            "Typed property {}::${name} must not be accessed before initialization",
            object.get_class_entry().name().unwrap_or_default()
        ))
    })
}

/// How deep the tree under `value` is, counting arrays as levels. Only nodes are entered:
/// they are readonly, so the tree has no cycle.
fn measure(classes: &Classes, value: &Zval, level: usize, deepest: &mut usize) -> Read<()> {
    let table = value.array();
    let node = value.object().filter(|object| classes.node(object).is_some());
    if table.is_none() && node.is_none() {
        return Ok(());
    }
    if level >= MAX_LEVELS {
        return Err(too_deep().into());
    }
    *deepest = (*deepest).max(level + 1);
    let mut descend = || -> Read<()> {
        if let Some(table) = table {
            for child in table.values() {
                measure(classes, child, level + 1, deepest)?;
            }
        } else if let Some(node) = node {
            for child in slots(node) {
                measure(classes, child, level + 1, deepest)?;
            }
        }
        Ok(())
    };
    // Measuring cannot know the depth ahead, so it moves to a new stack every so many levels.
    if level > 0 && level.is_multiple_of(128) { roomy(128, 0, &mut descend) } else { descend() }
}

fn text(value: &Zval, what: &str) -> Read<String> {
    match value.zend_str() {
        Some(found) => Ok(wide(found.as_bytes()).into_owned()),
        None => Err(Failure::Type(format!("{what} must be of type string"))),
    }
}

/// `(string) $float` at the request's `precision` setting.
pub(super) fn float(value: f64) -> String {
    let precision = i32::try_from(ExecutorGlobals::get().precision).unwrap_or(-1);
    php_std::number::gcvt(value, if precision == 0 { 1 } else { precision }, 'E')
}

/// `value` as PHP passes it to a `string` parameter outside strict mode: a scalar converts.
fn coerced(value: &Zval, what: &str) -> Read<String> {
    if let Some(found) = value.long() {
        Ok(found.to_string())
    } else if let Some(found) = value.double() {
        Ok(float(found))
    } else if let Some(found) = value.bool() {
        Ok(if found { "1".to_owned() } else { String::new() })
    } else {
        text(value, what)
    }
}

fn optional(value: &Zval, what: &str) -> Read<Option<String>> {
    if value.is_null() { Ok(None) } else { text(value, what).map(Some) }
}

fn list<T>(value: &Zval, what: &str, mut each: impl FnMut(&Zval) -> Read<T>) -> Read<Vec<T>> {
    let table = value.array().ok_or_else(|| Failure::Type(format!("{what} must be of type array")))?;
    let mut out = Vec::with_capacity(table.len());
    for item in table.values() {
        out.push(each(item)?);
    }
    Ok(out)
}

/// Reads PHP nodes into the crate's.
struct Reader<'c>(&'c Classes);

impl Reader<'_> {
    /// `value` as a node of class `node`, or the type error PHP raises for anything else.
    fn node<'a>(&self, value: &'a Zval, node: Node, what: &str) -> Read<&'a ZendObject> {
        value
            .object()
            .filter(|object| self.0.node(object) == Some(node))
            .ok_or_else(|| Failure::Type(format!("{what} must be of type {NAMESPACE}{}", NODES[node as usize].1)))
    }

    fn select(&self, value: &Zval) -> Read<ast::Select> {
        let node = self.node(value, Node::Select, "A statement")?;
        let from = slot(node, 1, "from")?;
        Ok(ast::Select {
            columns: list(slot(node, 0, "columns")?, "columns", |item| self.expression(item))?,
            from: if from.is_null() { None } else { Some(self.source(from)?) },
            joins: list(slot(node, 2, "joins")?, "joins", |item| self.join(item))?,
            where_clause: self.maybe(slot(node, 3, "where")?)?,
            group_by: list(slot(node, 4, "groupBy")?, "groupBy", |item| self.expression(item))?,
            having: self.maybe(slot(node, 5, "having")?)?,
            order_by: list(slot(node, 6, "orderBy")?, "orderBy", |item| self.order(item))?,
            limit: self.maybe(slot(node, 7, "limit")?)?,
            offset: self.maybe(slot(node, 8, "offset")?)?,
            distinct: slot(node, 9, "distinct")?.is_true(),
            ctes: list(slot(node, 10, "ctes")?, "ctes", |item| self.cte(item))?,
            windows: list(slot(node, 11, "windows")?, "windows", |item| self.window(item))?,
        })
    }

    fn maybe(&self, value: &Zval) -> Read<Option<ast::Expression>> {
        if value.is_null() { Ok(None) } else { self.expression(value).map(Some) }
    }

    fn boxed(&self, value: &Zval) -> Read<Box<ast::Expression>> {
        self.expression(value).map(Box::new)
    }

    fn expression(&self, value: &Zval) -> Read<ast::Expression> {
        let not_one = || Failure::Type(format!("An expression must be of type {EXPRESSION}"));
        let node = value.object().ok_or_else(not_one)?;
        Ok(match self.0.node(node) {
            Some(Node::Literal) => {
                let value = slot(node, 0, "value")?;
                let value = if let Some(found) = value.zend_str() {
                    Value::Str(php_std::Str::from(wide(found.as_bytes()).as_bytes()))
                } else if let Some(found) = value.long() {
                    Value::Int(found)
                } else if let Some(found) = value.double() {
                    // `(string) $float` follows the `precision` setting, and the crate writes
                    // floats for Appwrite's (-1). A placeholder is written as it is.
                    return Ok(ast::Placeholder { value: float(found) }.into());
                } else if let Some(found) = value.bool() {
                    Value::Bool(found)
                } else {
                    Value::Null
                };
                ast::Literal { value }.into()
            }
            Some(Node::Placeholder) => ast::Placeholder { value: text(slot(node, 0, "value")?, "value")? }.into(),
            Some(Node::Raw) => ast::Raw { sql: text(slot(node, 0, "sql")?, "sql")? }.into(),
            Some(Node::Star) => ast::Star {
                table: optional(slot(node, 0, "table")?, "table")?,
                schema: optional(slot(node, 1, "schema")?, "schema")?,
            }
            .into(),
            Some(Node::Column) => ast::Column {
                name: text(slot(node, 0, "name")?, "name")?,
                table: optional(slot(node, 1, "table")?, "table")?,
                schema: optional(slot(node, 2, "schema")?, "schema")?,
            }
            .into(),
            Some(Node::Aliased) => ast::Aliased {
                expression: self.boxed(slot(node, 0, "expression")?)?,
                alias: text(slot(node, 1, "alias")?, "alias")?,
            }
            .into(),
            Some(Node::Between) => ast::Between {
                expression: self.boxed(slot(node, 0, "expression")?)?,
                low: self.boxed(slot(node, 1, "low")?)?,
                high: self.boxed(slot(node, 2, "high")?)?,
                negated: slot(node, 3, "negated")?.is_true(),
            }
            .into(),
            Some(Node::Binary) => ast::Binary {
                left: self.boxed(slot(node, 0, "left")?)?,
                operator: text(slot(node, 1, "operator")?, "operator")?,
                right: self.boxed(slot(node, 2, "right")?)?,
                escape: self.maybe(slot(node, 3, "escape")?)?.map(Box::new),
            }
            .into(),
            Some(Node::CaseWhen) => self.when(value)?.into(),
            Some(Node::Cast) => ast::Cast {
                expression: self.boxed(slot(node, 0, "expression")?)?,
                data_type: text(slot(node, 1, "type")?, "type")?,
            }
            .into(),
            Some(Node::Conditional) => ast::Conditional {
                operand: self.maybe(slot(node, 0, "operand")?)?.map(Box::new),
                whens: list(slot(node, 1, "whens")?, "whens", |item| self.when(item))?,
                else_result: self.maybe(slot(node, 2, "else")?)?.map(Box::new),
            }
            .into(),
            Some(Node::Exists) => ast::Exists {
                subquery: Box::new(self.select(slot(node, 0, "subquery")?)?),
                negated: slot(node, 1, "negated")?.is_true(),
            }
            .into(),
            Some(Node::In) => {
                let values = slot(node, 1, "list")?;
                ast::In {
                    expression: self.boxed(slot(node, 0, "expression")?)?,
                    list: if values.is_array() {
                        ast::InList::Values(list(values, "list", |item| self.expression(item))?)
                    } else {
                        ast::InList::Subquery(Box::new(self.select(values)?))
                    },
                    negated: slot(node, 2, "negated")?.is_true(),
                }
                .into()
            }
            Some(Node::Subquery) => ast::Subquery { query: Box::new(self.select(slot(node, 0, "query")?)?) }.into(),
            Some(Node::Unary) => ast::Unary {
                operator: text(slot(node, 0, "operator")?, "operator")?,
                operand: self.boxed(slot(node, 1, "operand")?)?,
                prefix: slot(node, 2, "prefix")?.is_true(),
            }
            .into(),
            Some(Node::Window) => {
                let specification = slot(node, 2, "specification")?;
                ast::Window {
                    function: self.boxed(slot(node, 0, "function")?)?,
                    window_name: optional(slot(node, 1, "windowName")?, "windowName")?,
                    specification: if specification.is_null() {
                        None
                    } else {
                        Some(Box::new(self.specification(specification)?))
                    },
                }
                .into()
            }
            Some(Node::Func) => ast::Func {
                name: text(slot(node, 0, "name")?, "name")?,
                arguments: list(slot(node, 1, "arguments")?, "arguments", |item| self.expression(item))?,
                distinct: slot(node, 2, "distinct")?.is_true(),
                filter: self.maybe(slot(node, 3, "filter")?)?.map(Box::new),
            }
            .into(),
            _ if node.instance_of(self.0.expression) => {
                let class = node.get_class_entry().name().unwrap_or_default();
                return Err(utopia_query::Error::new(format!("Unsupported expression type: {class}"), 0).into());
            }
            _ => return Err(not_one()),
        })
    }

    fn when(&self, value: &Zval) -> Read<ast::CaseWhen> {
        let node = self.node(value, Node::CaseWhen, "A CASE branch")?;
        Ok(ast::CaseWhen {
            condition: self.boxed(slot(node, 0, "condition")?)?,
            result: self.boxed(slot(node, 1, "result")?)?,
        })
    }

    fn source(&self, value: &Zval) -> Read<ast::TableSource> {
        let node = value.object();
        match node.and_then(|node| self.0.node(node).map(|kind| (node, kind))) {
            Some((node, Node::Table)) => Ok(ast::TableSource::Table(ast::Table {
                name: text(slot(node, 0, "name")?, "name")?,
                alias: optional(slot(node, 1, "alias")?, "alias")?,
                schema: optional(slot(node, 2, "schema")?, "schema")?,
            })),
            Some((node, Node::SubquerySource)) => Ok(ast::TableSource::Subquery(ast::SubquerySource {
                query: Box::new(self.select(slot(node, 0, "query")?)?),
                alias: text(slot(node, 1, "alias")?, "alias")?,
            })),
            _ => Err(Failure::Type(format!(
                "A table source must be of type {NAMESPACE}Reference\\Table|{NAMESPACE}SubquerySource"
            ))),
        }
    }

    fn join(&self, value: &Zval) -> Read<ast::JoinClause> {
        let node = self.node(value, Node::JoinClause, "A join")?;
        Ok(ast::JoinClause {
            kind: text(slot(node, 0, "type")?, "type")?,
            table: self.source(slot(node, 1, "table")?)?,
            condition: self.maybe(slot(node, 2, "condition")?)?,
        })
    }

    fn order(&self, value: &Zval) -> Read<ast::OrderByItem> {
        let node = self.node(value, Node::OrderByItem, "An ORDER BY item")?;
        let case = |value: &Zval| value.object().map(|case| std::ptr::from_ref(case).cast_mut());
        let direction = case(slot(node, 1, "direction")?);
        let nulls = case(slot(node, 2, "nulls")?);
        Ok(ast::OrderByItem {
            expression: self.expression(slot(node, 0, "expression")?)?,
            direction: if direction == Some(self.0.directions[1]) {
                OrderDirection::Desc
            } else if direction == Some(self.0.directions[2]) {
                OrderDirection::Random
            } else {
                OrderDirection::Asc
            },
            nulls: if nulls == Some(self.0.nulls[0]) {
                Some(NullsPosition::First)
            } else if nulls == Some(self.0.nulls[1]) {
                Some(NullsPosition::Last)
            } else {
                None
            },
        })
    }

    fn specification(&self, value: &Zval) -> Read<ast::WindowSpecification> {
        let node = self.node(value, Node::WindowSpecification, "A window specification")?;
        Ok(ast::WindowSpecification {
            partition_by: list(slot(node, 0, "partitionBy")?, "partitionBy", |item| self.expression(item))?,
            order_by: list(slot(node, 1, "orderBy")?, "orderBy", |item| self.order(item))?,
            frame_type: optional(slot(node, 2, "frameType")?, "frameType")?,
            frame_start: optional(slot(node, 3, "frameStart")?, "frameStart")?,
            frame_end: optional(slot(node, 4, "frameEnd")?, "frameEnd")?,
        })
    }

    fn cte(&self, value: &Zval) -> Read<ast::Cte> {
        let node = self.node(value, Node::Cte, "A CTE")?;
        Ok(ast::Cte {
            name: text(slot(node, 0, "name")?, "name")?,
            query: self.select(slot(node, 1, "query")?)?,
            columns: list(slot(node, 2, "columns")?, "columns", |item| coerced(item, "A CTE column"))?,
            recursive: slot(node, 3, "recursive")?.is_true(),
        })
    }

    fn window(&self, value: &Zval) -> Read<ast::WindowDefinition> {
        let node = self.node(value, Node::WindowDefinition, "A window")?;
        Ok(ast::WindowDefinition {
            name: text(slot(node, 0, "name")?, "name")?,
            specification: self.specification(slot(node, 1, "specification")?)?,
        })
    }
}

// ---------------------------------------------------------------------------------------
// The crate's nodes to PHP
// ---------------------------------------------------------------------------------------

pub(super) fn null() -> Zval {
    let mut value = Zval::new();
    value.set_null();
    value
}

fn boolean(flag: bool) -> Zval {
    let mut value = Zval::new();
    value.set_bool(flag);
    value
}

fn words(value: &str) -> Zval {
    string(&narrow(value))
}

fn maybe_words(value: Option<&String>) -> Zval {
    value.map_or_else(null, |value| words(value))
}

fn array(items: Vec<Zval>) -> Zval {
    items.into_zval(false).unwrap_or_else(|_| null())
}

fn case_of(case: *mut ZendObject) -> Zval {
    let mut value = Zval::new();
    // SAFETY: the pointer is a case object of a loaded enum, found this request.
    value.set_object(unsafe { &mut *case });
    value
}

/// Makes the PHP nodes of a parsed statement. A node is its class's object with each property
/// slot written, as its constructor leaves it: the constructors only assign, and what two of
/// them check (a function's name, a cast's type) the parser has checked already.
struct Builder<'c>(&'c Classes);

impl Builder<'_> {
    fn make<const N: usize>(&self, node: Node, values: [Zval; N]) -> Zval {
        debug_assert_eq!(N, NODES[node as usize].2.len());
        let mut object = self.0.nodes[node as usize].new();
        for (index, value) in values.into_iter().enumerate() {
            // SAFETY: `laid_out` checked the class declares exactly these slots, and a new
            // object's are uninitialized, so there is nothing to release before the write.
            unsafe { object.properties_table.as_mut_ptr().add(index).write(value) };
        }
        object.into_zval(false).unwrap_or_else(|_| null())
    }

    fn each<T>(&self, items: &[T], each: impl Fn(&Self, &T) -> Zval) -> Zval {
        array(items.iter().map(|item| each(self, item)).collect())
    }

    fn maybe(&self, expression: Option<&ast::Expression>) -> Zval {
        expression.map_or_else(null, |expression| self.expression(expression))
    }

    fn select(&self, select: &ast::Select) -> Zval {
        self.make(
            Node::Select,
            [
                self.each(&select.columns, Self::expression),
                select.from.as_ref().map_or_else(null, |source| self.source(source)),
                self.each(&select.joins, Self::join),
                self.maybe(select.where_clause.as_ref()),
                self.each(&select.group_by, Self::expression),
                self.maybe(select.having.as_ref()),
                self.each(&select.order_by, Self::order),
                self.maybe(select.limit.as_ref()),
                self.maybe(select.offset.as_ref()),
                boolean(select.distinct),
                self.each(&select.ctes, Self::cte),
                self.each(&select.windows, Self::window),
            ],
        )
    }

    fn expression(&self, expression: &ast::Expression) -> Zval {
        match expression {
            ast::Expression::Literal(literal) => {
                let mut value = Zval::new();
                match &literal.value {
                    Value::Bool(flag) => value.set_bool(*flag),
                    Value::Int(number) => value.set_long(*number),
                    Value::Float(number) => value.set_double(*number),
                    Value::Str(found) => value = words(&String::from_utf8_lossy(found.as_bytes())),
                    _ => value.set_null(),
                }
                self.make(Node::Literal, [value])
            }
            ast::Expression::Placeholder(placeholder) => self.make(Node::Placeholder, [words(&placeholder.value)]),
            ast::Expression::Raw(raw) => self.make(Node::Raw, [words(&raw.sql)]),
            ast::Expression::Star(star) => {
                self.make(Node::Star, [maybe_words(star.table.as_ref()), maybe_words(star.schema.as_ref())])
            }
            ast::Expression::Column(column) => self.make(
                Node::Column,
                [words(&column.name), maybe_words(column.table.as_ref()), maybe_words(column.schema.as_ref())],
            ),
            ast::Expression::Aliased(aliased) => {
                self.make(Node::Aliased, [self.expression(&aliased.expression), words(&aliased.alias)])
            }
            ast::Expression::Between(between) => self.make(
                Node::Between,
                [
                    self.expression(&between.expression),
                    self.expression(&between.low),
                    self.expression(&between.high),
                    boolean(between.negated),
                ],
            ),
            ast::Expression::Binary(binary) => self.make(
                Node::Binary,
                [
                    self.expression(&binary.left),
                    words(&binary.operator),
                    self.expression(&binary.right),
                    self.maybe(binary.escape.as_deref()),
                ],
            ),
            ast::Expression::CaseWhen(when) => self.when(when),
            ast::Expression::Cast(cast) => {
                self.make(Node::Cast, [self.expression(&cast.expression), words(&cast.data_type)])
            }
            ast::Expression::Conditional(conditional) => self.make(
                Node::Conditional,
                [
                    self.maybe(conditional.operand.as_deref()),
                    self.each(&conditional.whens, Self::when),
                    self.maybe(conditional.else_result.as_deref()),
                ],
            ),
            ast::Expression::Exists(exists) => {
                self.make(Node::Exists, [self.select(&exists.subquery), boolean(exists.negated)])
            }
            ast::Expression::In(found) => {
                let list = match &found.list {
                    ast::InList::Values(values) => self.each(values, Self::expression),
                    ast::InList::Subquery(select) => self.select(select),
                };
                self.make(Node::In, [self.expression(&found.expression), list, boolean(found.negated)])
            }
            ast::Expression::Subquery(subquery) => self.make(Node::Subquery, [self.select(&subquery.query)]),
            ast::Expression::Unary(unary) => {
                self.make(Node::Unary, [words(&unary.operator), self.expression(&unary.operand), boolean(unary.prefix)])
            }
            ast::Expression::Window(window) => self.make(
                Node::Window,
                [
                    self.expression(&window.function),
                    maybe_words(window.window_name.as_ref()),
                    window
                        .specification
                        .as_deref()
                        .map_or_else(null, |specification| self.specification(specification)),
                ],
            ),
            ast::Expression::Func(function) => self.make(
                Node::Func,
                [
                    words(&function.name),
                    self.each(&function.arguments, Self::expression),
                    boolean(function.distinct),
                    self.maybe(function.filter.as_deref()),
                ],
            ),
        }
    }

    fn when(&self, when: &ast::CaseWhen) -> Zval {
        self.make(Node::CaseWhen, [self.expression(&when.condition), self.expression(&when.result)])
    }

    fn source(&self, source: &ast::TableSource) -> Zval {
        match source {
            ast::TableSource::Table(table) => self.make(
                Node::Table,
                [words(&table.name), maybe_words(table.alias.as_ref()), maybe_words(table.schema.as_ref())],
            ),
            ast::TableSource::Subquery(subquery) => {
                self.make(Node::SubquerySource, [self.select(&subquery.query), words(&subquery.alias)])
            }
        }
    }

    fn join(&self, join: &ast::JoinClause) -> Zval {
        self.make(Node::JoinClause, [words(&join.kind), self.source(&join.table), self.maybe(join.condition.as_ref())])
    }

    fn order(&self, item: &ast::OrderByItem) -> Zval {
        let direction = match item.direction {
            OrderDirection::Asc => 0,
            OrderDirection::Desc => 1,
            OrderDirection::Random => 2,
        };
        let nulls = match item.nulls {
            Some(NullsPosition::First) => case_of(self.0.nulls[0]),
            Some(NullsPosition::Last) => case_of(self.0.nulls[1]),
            None => null(),
        };
        self.make(Node::OrderByItem, [self.expression(&item.expression), case_of(self.0.directions[direction]), nulls])
    }

    fn specification(&self, specification: &ast::WindowSpecification) -> Zval {
        self.make(
            Node::WindowSpecification,
            [
                self.each(&specification.partition_by, Self::expression),
                self.each(&specification.order_by, Self::order),
                maybe_words(specification.frame_type.as_ref()),
                maybe_words(specification.frame_start.as_ref()),
                maybe_words(specification.frame_end.as_ref()),
            ],
        )
    }

    fn cte(&self, cte: &ast::Cte) -> Zval {
        self.make(
            Node::Cte,
            [
                words(&cte.name),
                self.select(&cte.query),
                array(cte.columns.iter().map(|column| words(column)).collect()),
                boolean(cte.recursive),
            ],
        )
    }

    fn window(&self, window: &ast::WindowDefinition) -> Zval {
        self.make(Node::WindowDefinition, [words(&window.name), self.specification(&window.specification)])
    }
}

// ---------------------------------------------------------------------------------------
// The classes
// ---------------------------------------------------------------------------------------

/// `Utopia\Query\AST\Grammar`: what may stand as a function name or a cast type.
#[php_class]
#[php(name = "Utopia\\Query\\AST\\Grammar")]
#[php(flags = ClassFlags::Final)]
#[derive(Clone)]
pub struct Grammar;

#[php_impl]
impl Grammar {
    #[php(name = "isIdentifier")]
    pub fn is_identifier(value: PhpString<'_>) -> bool {
        ast::Grammar::is_identifier(&wide(value.bytes()))
    }

    #[php(name = "isType")]
    pub fn is_type(value: PhpString<'_>) -> bool {
        ast::Grammar::is_type(&wide(value.bytes()))
    }
}

/// The tokens of `$tokens`, read from the package's `Token` objects, and how deep a tree they
/// can make. PHP reads them by index from 0, so a missing index is the type error its `Token`
/// return type raises. Every level of a tree takes a keyword, an operator or a parenthesis,
/// so their number bounds its depth.
fn tokens<'a>(classes: &Classes, table: &'a ZendHashTable) -> PhpResult<(Vec<Token<'a>>, usize)> {
    let not_a_token = || Failure::Type(format!("{NAMESPACE}Parser: every token must be of type {TOKEN}"));
    let mut out = Vec::with_capacity(table.len());
    let mut levels = 8;
    for index in 0..table.len() {
        let found = i64::try_from(index)
            .ok()
            .and_then(|index| table.get_index(index))
            .and_then(Zval::object)
            .filter(|object| object.instance_of(classes.token))
            .ok_or_else(|| raised(not_a_token()))?;
        let kind = slot(found, 0, "type").map_err(raised)?.object().map(std::ptr::from_ref);
        let kind = classes.kinds.iter().position(|case| Some(*case) == kind).ok_or_else(|| raised(not_a_token()))?;
        let kind = TokenType::CASES[kind];
        let value = slot(found, 1, "value").map_err(raised)?.zend_str().ok_or_else(|| raised(not_a_token()))?;
        let value = match wide(value.as_bytes()) {
            Cow::Borrowed(_) => Cow::Borrowed(value.as_bytes()),
            Cow::Owned(widened) => Cow::Owned(widened.into_bytes()),
        };
        let position = slot(found, 2, "position").map_err(raised)?.long().and_then(|at| usize::try_from(at).ok());
        levels += match kind {
            TokenType::Keyword | TokenType::Operator => 1,
            TokenType::LeftParen => 2,
            _ => 0,
        };
        out.push(Token { kind, value, position: position.unwrap_or(0) });
    }
    if out.is_empty() {
        return Err(raised(not_a_token()));
    }
    Ok((out, levels))
}

/// `Utopia\Query\AST\Parser`: filtered tokens to a `Statement\Select`.
#[php_class]
#[php(name = "Utopia\\Query\\AST\\Parser")]
#[derive(Clone)]
pub struct Parser(StringLiteral);

#[php_impl]
impl Parser {
    #[php(defaults(literal = "Rules(StringLiteral::Backslash)"))]
    pub fn __construct(literal: Rules) -> Self {
        Self(literal.0)
    }

    pub fn parse(&self, tokens: &ZendHashTable) -> PhpResult<Zval> {
        let classes = classes()?;
        let (read, levels) = self::tokens(&classes, tokens)?;
        if levels > MAX_LEVELS {
            return Err(failed(&too_deep()));
        }
        // The statement is dropped where it was made: on the stack with room for it.
        roomy(levels, INLINE_PARSE_LEVELS, || {
            ast::Parser::new()
                .with_literal(self.0)
                .widened()
                .parse(&read)
                .map(|select| Builder(&classes).select(&select))
        })
        .map_err(|error| failed(&error))
    }
}

/// A `Statement\Select` argument.
pub struct Statement<'a>(&'a Zval);

impl<'a> FromZval<'a> for Statement<'a> {
    const TYPE: DataType = DataType::object(SELECT);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let classes = classes().ok()?;
        zval.object().filter(|object| classes.node(object) == Some(Node::Select)).map(|_| Statement(zval))
    }
}

/// An `Expression` argument.
pub struct Expression<'a>(&'a Zval);

impl<'a> FromZval<'a> for Expression<'a> {
    const TYPE: DataType = DataType::object(EXPRESSION);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let classes = classes().ok()?;
        zval.object().filter(|object| object.instance_of(classes.expression)).map(|_| Expression(zval))
    }
}

/// `value` as SQL: a statement, or an expression.
fn written(serializer: ast::Serializer, value: &Zval, statement: bool) -> PhpResult<Zval> {
    let classes = classes()?;
    let mut deepest = 0;
    measure(&classes, value, 0, &mut deepest).map_err(raised)?;
    // What is read is dropped where it was read: on the stack with room for it.
    let sql = roomy(deepest, INLINE_LEVELS, || -> Read<String> {
        let reader = Reader(&classes);
        Ok(if statement {
            serializer.serialize(&reader.select(value)?)?
        } else {
            serializer.serialize_expression(&reader.expression(value)?)?
        })
    });
    sql.map(|sql| words(&sql)).map_err(raised)
}

/// The methods every serializer class declares: a parent's method reads its own Rust type,
/// so each class has its own.
macro_rules! serializer_methods {
    ($class:ident, $serializer:expr, $rules:literal) => {
        #[php_impl]
        impl $class {
            #[php(defaults(literal = $rules))]
            pub fn __construct(literal: Rules) -> Self {
                Self(literal.0)
            }

            pub fn serialize(&self, stmt: Statement<'_>) -> PhpResult<Zval> {
                written($serializer.with_literal(self.0), stmt.0, true)
            }

            #[php(name = "serializeExpression")]
            pub fn serialize_expression(&self, expression: Expression<'_>) -> PhpResult<Zval> {
                written($serializer.with_literal(self.0), expression.0, false)
            }

            #[php(name = "quoteIdentifier", vis = "protected")]
            pub fn quote_identifier(&self, name: PhpString<'_>) -> Zval {
                words(&$serializer.quote_identifier(&wide(name.bytes())))
            }
        }
    };
}

/// `Utopia\Query\AST\Serializer`: a statement as SQL, identifiers in backticks.
#[php_class]
#[php(name = "Utopia\\Query\\AST\\Serializer")]
#[derive(Clone)]
pub struct Serializer(StringLiteral);
serializer_methods!(Serializer, ast::Serializer::new(), "Rules(StringLiteral::Backslash)");

/// `Utopia\Query\AST\Serializer\MySQL`.
#[php_class]
#[php(name = "Utopia\\Query\\AST\\Serializer\\MySQL")]
#[php(extends(Serializer))]
#[derive(Clone)]
pub struct MySqlSerializer(StringLiteral);
serializer_methods!(MySqlSerializer, ast::Serializer::mysql(), "Rules(StringLiteral::Backslash)");

/// `Utopia\Query\AST\Serializer\MariaDB`.
#[php_class]
#[php(name = "Utopia\\Query\\AST\\Serializer\\MariaDB")]
#[php(extends(MySqlSerializer))]
#[derive(Clone)]
pub struct MariaDbSerializer(StringLiteral);
serializer_methods!(MariaDbSerializer, ast::Serializer::mariadb(), "Rules(StringLiteral::Backslash)");

/// `Utopia\Query\AST\Serializer\PostgreSQL`: identifiers in double quotes.
#[php_class]
#[php(name = "Utopia\\Query\\AST\\Serializer\\PostgreSQL")]
#[php(extends(Serializer))]
#[derive(Clone)]
pub struct PostgreSqlSerializer(StringLiteral);
serializer_methods!(PostgreSqlSerializer, ast::Serializer::postgresql(), "Rules(StringLiteral::Extended)");

/// `Utopia\Query\AST\Serializer\SQLite`: identifiers in double quotes.
#[php_class]
#[php(name = "Utopia\\Query\\AST\\Serializer\\SQLite")]
#[php(extends(Serializer))]
#[derive(Clone)]
pub struct SqliteSerializer(StringLiteral);
serializer_methods!(SqliteSerializer, ast::Serializer::sqlite(), "Rules(StringLiteral::Standard)");

/// `Utopia\Query\AST\Serializer\ClickHouse`.
#[php_class]
#[php(name = "Utopia\\Query\\AST\\Serializer\\ClickHouse")]
#[php(extends(Serializer))]
#[derive(Clone)]
pub struct ClickHouseSerializer(StringLiteral);
serializer_methods!(ClickHouseSerializer, ast::Serializer::clickhouse(), "Rules(StringLiteral::BackslashUtf8)");
