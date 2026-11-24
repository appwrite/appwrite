//! The query builders, registered with the rest of `query`.
//!
//! PHP code subclasses the builders and overrides their steps (`utopia-php/database` does),
//! so every step is one of the crate's [`Step`]s. The crate asks [`Subclass`] before it runs
//! one; a step the object's class declares in PHP is called there, and the native method of
//! the same name runs the crate's own, which is what `parent::step()` reaches.
//!
//! An override runs in the middle of a Rust build and calls back into the builder
//! (`$this->addBinding()`, `parent::compileFilter()`). The builder is therefore not a field
//! of the PHP object but a heap allocation it owns, and the crate lends its own `&mut` for
//! the length of the override: a method that re-enters uses the lent pointer, so only one
//! path to the builder is ever in use.
//!
//! Strings: a SQL dialect's are bytes to PHP, so they are widened going in and narrowed
//! coming out, as the AST's are. MongoDB's statements are JSON, which is UTF-8, so its
//! strings pass as they are and one that is not UTF-8 fails the statement as `json_encode()`
//! does.

// The parameters are PHP's, in name and number, and a fluent method names its lifetime whether or not
// an argument borrows.
#![allow(non_snake_case, clippy::needless_lifetimes, clippy::too_many_arguments)]

use std::borrow::Cow;
use std::cell::{Cell, RefCell};
use std::marker::PhantomData;
use std::ptr::NonNull;
use std::sync::Arc;

use ext_php_rs::class::RegisteredClass;
use ext_php_rs::convert::{FromZval, IntoZval, IntoZvalDyn, StubLiteral};
use ext_php_rs::exception::PhpException;
use ext_php_rs::ffi::{zend_function, zend_hash_str_find};
use ext_php_rs::flags::{ClassFlags, DataType};
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendCallable, ZendClassObject, ZendHashTable, ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecutorGlobals, ce};
use php_std::types::KeyRef;
use php_std::{ArrayKey as Key, Str};
use utopia_query::builder::{
    AnyBuilder, Arg, AsofOperator, AstWriter, Binding, Builder, CaseExpression, ClickHouseDialect, Condition, Dialect,
    ExplainOptions, Format, FormattedInsertStatement, IndexHint, JoinBuilder, JoinOn, JoinType, Kind, MariaDbDialect,
    MongoDbDialect, MySqlDialect, Operator, Overrides, PostgreSqlDialect, Ret, SqliteDialect, Statement, Step,
    VectorMetric, WindowFrame,
};
use utopia_query::hook::{self, Hook};
use utopia_query::{Array, Error, Method, NullsPosition, Object, Query, Value};

use super::ast::{failed, narrow, null, slots, wide};
use super::schema::slot_of;
use super::tokenizer::class;
use super::value::{
    Arr, Bool, Float, Int, called_scope, case_method, clone_object, empty, enum_case, method_case, parts,
};
use crate::emails::{Text as Bytes, construct, string, thrown};
use crate::string::PhpString;

const BUILDER: &str = "Utopia\\Query\\Builder";
const HOOK: &str = "Utopia\\Query\\Hook";
/// One of the protected array properties, as PHP holds it.
pub struct Slot(Zval);

impl IntoZval for Slot {
    const TYPE: DataType = DataType::Array;
    const NULLABLE: bool = false;

    fn set_zval(self, zval: &mut Zval, _persistent: bool) -> ext_php_rs::error::Result<()> {
        *zval = self.0;
        Ok(())
    }
}

impl<'a> FromZval<'a> for Slot {
    const TYPE: DataType = DataType::Array;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        zval.is_array().then(|| Self(zval.shallow_clone()))
    }
}

const FORMATTED: &str = "Utopia\\Query\\Builder\\ClickHouse\\FormattedInsertStatement";
const PLACEMENT: &str = "Utopia\\Query\\Hook\\Join\\Placement";
const PARSED: &str = "Utopia\\Query\\Builder\\ParsedQuery";
const CASE: &str = "Utopia\\Query\\Builder\\Case\\Expression";
const BINDING: &str = "Utopia\\Query\\Builder\\Binding";
const CONDITION: &str = "Utopia\\Query\\Builder\\Condition";
const JOIN_BUILDER: &str = "Utopia\\Query\\Builder\\JoinBuilder";
const JOIN_ON: &str = "Utopia\\Query\\Builder\\JoinOn";
const WINDOW_FRAME: &str = "Utopia\\Query\\Builder\\WindowFrame";
const QUERY: &str = "Utopia\\Query\\Query";
const STATEMENT: &str = "Utopia\\Query\\Builder\\Statement";

/// The PHP functions a class declares for the steps it overrides, by [`Step`].
type Declared = [*mut zend_function; Step::ALL.len()];

const NONE: Declared = [std::ptr::null_mut(); Step::ALL.len()];

thread_local! {
    /// The steps each class seen this request overrides.
    static CLASSES: RefCell<Vec<(*const ClassEntry, Declared)>> = const { RefCell::new(Vec::new()) };
}

/// Forgets the classes at the end of a request, when PHP frees them.
pub fn request_shutdown() {
    CLASSES.with_borrow_mut(Vec::clear);
}

/// The steps `class` declares in PHP: a native method is the builder's own.
fn declared(class: &ClassEntry) -> Declared {
    if let Some(found) = CLASSES
        .with_borrow(|classes| classes.iter().find(|(known, _)| std::ptr::eq(*known, class)).map(|found| found.1))
    {
        return found;
    }
    let mut functions = NONE;
    for (index, step) in Step::ALL.iter().enumerate() {
        let name = step.method().to_ascii_lowercase();
        // SAFETY: a class's function table holds a `zend_function` for each method, by its
        // lower-case name, and the first byte of one is its type (1 is internal).
        unsafe {
            let found = zend_hash_str_find(&raw const class.function_table, name.as_ptr().cast(), name.len());
            if let Some(function) = found.as_ref().map(|slot| slot.value.func)
                && (*function).type_ != 1
            {
                functions[index] = function;
            }
        }
    }
    CLASSES.with_borrow_mut(|classes| classes.push((class, functions)));
    functions
}

/// Whether a dialect's statements are JSON, which is UTF-8, or SQL, which is bytes.
pub trait Text: Dialect {
    /// MongoDB's are JSON.
    const JSON: bool = false;

    /// What `insert()` returns when the builder was given a format to insert in, which only
    /// ClickHouse can be.
    fn formatted(_builder: &mut Builder<Self>) -> Result<Option<FormattedInsertStatement>, Error> {
        Ok(None)
    }

    /// The class `createTokenizer()` makes: the dialect's own, or the standard one.
    const TOKENIZER: &'static str = "Utopia\\Query\\Tokenizer\\Tokenizer";

    /// The class `createAstSerializer()` makes.
    const SERIALIZER: &'static str = "Utopia\\Query\\AST\\Serializer";
}

impl Text for MySqlDialect {
    const TOKENIZER: &'static str = "Utopia\\Query\\Tokenizer\\MySQL";
    const SERIALIZER: &'static str = "Utopia\\Query\\AST\\Serializer\\MySQL";
}
impl Text for MariaDbDialect {
    const TOKENIZER: &'static str = "Utopia\\Query\\Tokenizer\\MariaDB";
    const SERIALIZER: &'static str = "Utopia\\Query\\AST\\Serializer\\MariaDB";
}
impl Text for PostgreSqlDialect {
    const TOKENIZER: &'static str = "Utopia\\Query\\Tokenizer\\PostgreSQL";
    const SERIALIZER: &'static str = "Utopia\\Query\\AST\\Serializer\\PostgreSQL";
}
impl Text for SqliteDialect {
    const TOKENIZER: &'static str = "Utopia\\Query\\Tokenizer\\SQLite";
    const SERIALIZER: &'static str = "Utopia\\Query\\AST\\Serializer\\SQLite";
}
impl Text for ClickHouseDialect {
    const TOKENIZER: &'static str = "Utopia\\Query\\Tokenizer\\ClickHouse";
    const SERIALIZER: &'static str = "Utopia\\Query\\AST\\Serializer\\ClickHouse";

    fn formatted(builder: &mut Builder<Self>) -> Result<Option<FormattedInsertStatement>, Error> {
        builder.formatted_insert()
    }
}
impl Text for MongoDbDialect {
    const JSON: bool = true;
}

/// The crate's text as the bytes PHP gave.
fn bytes(json: bool, text: &str) -> Cow<'_, [u8]> {
    if json { Cow::Borrowed(text.as_bytes()) } else { narrow(text) }
}

/// The crate's value as PHP's.
fn zval(json: bool, value: &Value) -> PhpResult<Zval> {
    let mut out = Zval::new();
    match value {
        Value::Null => {}
        Value::Bool(found) => out.set_bool(*found),
        Value::Int(found) => out.set_long(*found),
        Value::Float(found) => out.set_double(*found),
        Value::Str(found) => return Ok(string(found.as_bytes())),
        Value::Array(found) | Value::Object(found) => return list(json, found),
        Value::Ext(Object::Query(found)) => return query_object(json, found),
        Value::Ext(_) => {}
    }
    Ok(out)
}

fn list(json: bool, values: &Array) -> PhpResult<Zval> {
    if values.is_empty() {
        return Ok(empty());
    }
    let mut table = ZendHashTable::new();
    for (key, item) in values.iter() {
        let item = zval(json, item)?;
        match key {
            KeyRef::Int(index) => table.insert_at_index(index, item),
            KeyRef::Str(name) => table.insert(String::from_utf8_lossy(name).as_ref(), item),
        }
        .map_err(PhpException::from)?;
    }
    let mut out = Zval::new();
    out.set_hashtable(table);
    Ok(out)
}

/// The crate's query as a PHP `Query`, for an override that is handed one.
fn query_object(json: bool, found: &Query) -> PhpResult<Zval> {
    let mut arguments = [
        string(found.method().as_str().as_bytes()),
        string(&bytes(json, found.attribute())),
        list(json, found.values())?,
        string(&bytes(json, found.alias())),
    ];
    let query = construct(class(QUERY)?, &mut arguments)?;
    // What the constructor does not take.
    if found.on_array() {
        query.try_call_method("setOnArray", vec![&true]).map_err(PhpException::from)?;
    }
    if !found.attribute_type().is_empty() {
        let attribute_type = string(&bytes(json, found.attribute_type()));
        query.try_call_method("setAttributeType", vec![&attribute_type]).map_err(PhpException::from)?;
    }
    query.into_zval(false).map_err(PhpException::from)
}

/// The error of a build: the exception an override left pending, or the crate's own.
fn raised(error: &Error) -> PhpException {
    match thrown() {
        Err(pending) => pending,
        Ok(()) => failed(error),
    }
}

/// The builder a PHP object owns, and the pointer lent to it while an override runs.
pub struct State<D: Dialect + Text> {
    own: NonNull<Builder<D>>,
    lent: Cell<*mut Builder<D>>,
    /// Whether a JSON dialect was given a string that is not UTF-8: its statements then fail
    /// as `json_encode()` does.
    malformed: Cell<bool>,
    /// Whether the crate was told the object's class.
    named: Cell<bool>,
    /// The closure given to `setExecutor()`, which every statement carries.
    executor: RefCell<Option<Zval>>,
    /// The statement the last `afterBuild()` callback returned, and its query: `build()`
    /// hands back that very object.
    returned: RefCell<Option<(Zval, String)>>,
    /// The protected properties PHP was given a pointer into, to append to or change in place.
    /// The next call reads them back.
    shared: RefCell<[Option<Box<Zval>>; 3]>,
    touched: Cell<bool>,
}

/// The protected properties a subclass can change in place.
#[derive(Clone, Copy)]
enum Property {
    Bindings,
    PendingQueries,
    ResolvedAttributeCache,
}

impl Property {
    fn named(name: &[u8]) -> Option<Self> {
        match name {
            b"bindings" => Some(Self::Bindings),
            b"pendingQueries" => Some(Self::PendingQueries),
            b"resolvedAttributeCache" => Some(Self::ResolvedAttributeCache),
            _ => None,
        }
    }
}

impl<D: Dialect + Text> State<D> {
    /// A new builder for an object of class `C`, which asks that class for its overrides.
    fn new<C: Native<Dialect = D>>() -> Self {
        let mut builder = Builder::<D>::new();
        builder.set_overrides(Arc::new(Subclass::<C>(PhantomData)));
        Self::of(builder, false)
    }

    fn of(builder: Builder<D>, malformed: bool) -> Self {
        State {
            own: NonNull::from(Box::leak(Box::new(builder))),
            lent: Cell::new(std::ptr::null_mut()),
            malformed: Cell::new(malformed),
            named: Cell::new(false),
            executor: RefCell::new(None),
            returned: RefCell::new(None),
            shared: RefCell::new([None, None, None]),
            touched: Cell::new(false),
        }
    }

    /// A pointer to `property` as PHP holds it, for `$this->pendingQueries[] = $query` and the like.
    fn share(&self, property: Property) -> *mut Zval {
        let value = match property {
            Property::Bindings => self.bound().unwrap_or_else(|_| empty()),
            Property::PendingQueries => self.pending().unwrap_or_else(|_| empty()),
            Property::ResolvedAttributeCache => self.resolved(),
        };
        self.touched.set(true);
        std::ptr::from_mut(&mut **self.shared.borrow_mut()[property as usize].insert(Box::new(value)))
    }

    /// Reads back what PHP did to the properties it was given a pointer into.
    fn absorb(&self) {
        self.touched.set(false);
        let [bindings, pending, resolved] = self.shared.take();
        if let Some(bindings) = bindings {
            self.bind(&bindings);
        }
        // Left alone when PHP only read it: a build that is running is reading the same queries.
        if let Some(pending) = pending
            && let Ok(queries) = self.queries(&Arr::Given(&pending))
        {
            self.with(|builder| {
                if builder.pending() != queries.as_slice() {
                    builder.set_pending(queries);
                }
            });
        }
        if let Some(resolved) = resolved {
            self.resolve(&resolved);
        }
    }

    /// Runs `work` on the builder: the one the crate lent when an override is running.
    fn with<T>(&self, work: impl FnOnce(&mut Builder<D>) -> T) -> T {
        if self.touched.get() {
            self.absorb();
        }
        let lent = self.lent.get();
        let builder = if lent.is_null() { self.own.as_ptr() } else { lent };
        // SAFETY: the lent pointer is the `&mut` of the build that is waiting on the override
        // which made this call; without one, nothing else is using the builder.
        work(unsafe { &mut *builder })
    }

    /// PHP's bytes as the crate's text: a character for each byte in SQL, UTF-8 in JSON.
    fn text(&self, bytes: &[u8]) -> String {
        if !D::JSON {
            return wide(bytes).into_owned();
        }
        // One that is not UTF-8 fails the statement; it is kept a character a byte, for a
        // step called on its own to hand the bytes back.
        String::from_utf8(bytes.to_vec()).unwrap_or_else(|error| {
            self.malformed.set(true);
            wide(error.as_bytes()).into_owned()
        })
    }

    /// A `?string` argument as the crate's text.
    fn maybe(&self, text: Option<&PhpString<'_>>) -> Option<String> {
        text.map(|text| self.text(text.bytes()))
    }

    /// A PHP value as the crate's.
    fn value(&self, zval: &Zval) -> PhpResult<Value> {
        Ok(if zval.is_null() {
            Value::Null
        } else if let Some(found) = zval.bool() {
            Value::Bool(found)
        } else if let Some(found) = zval.long() {
            Value::Int(found)
        } else if let Some(found) = zval.double() {
            Value::Float(found)
        } else if let Some(found) = zval.zend_str() {
            Value::Str(Str::from(found.as_bytes()))
        } else if let Some(found) = zval.array() {
            Value::Array(self.array(found)?)
        } else if parts(zval).is_some() {
            Value::from(self.query(zval)?)
        } else if let Some(Nulls(nulls)) = Nulls::from_zval(zval) {
            Value::Ext(Object::Nulls(nulls))
        } else {
            let message = "A builder value must be a scalar, an array or a query".to_owned();
            return Err(PhpException::new(message, 0, ce::type_error()));
        })
    }

    fn array(&self, table: &ZendHashTable) -> PhpResult<Array> {
        let mut out = Array::default();
        for (key, item) in table {
            let key = match key {
                ArrayKey::Long(index) => Key::Int(index),
                ArrayKey::String(name) => Key::Str(Str::from(name.as_bytes())),
                ArrayKey::Str(name) => Key::Str(Str::from(name.as_bytes())),
                ArrayKey::ZendString(name) => Key::Str(Str::from(name.as_bytes())),
            };
            out.set(key, self.value(item)?);
        }
        Ok(out)
    }

    /// A row: its keys are column names, which are text as the statement's is.
    fn row(&self, given: &Arr<'_>) -> PhpResult<Array> {
        let mut out = Array::default();
        for (key, item) in given.table().into_iter().flatten() {
            let key = match key {
                ArrayKey::Long(index) => Key::Int(index),
                ArrayKey::String(name) => Key::Str(Str::from(self.text(name.as_bytes()).as_bytes())),
                ArrayKey::Str(name) => Key::Str(Str::from(self.text(name.as_bytes()).as_bytes())),
                ArrayKey::ZendString(name) => Key::Str(Str::from(self.text(name.as_bytes()).as_bytes())),
            };
            out.set(key, self.value(item)?);
        }
        Ok(out)
    }

    /// Makes `object` the owner of its builder, and names its class to the crate when it is a
    /// subclass, as PHP's messages name `static::class`. The builder carries its owner because
    /// a coroutine may yield inside an override while another builds on the same thread.
    fn running(&self, object: *mut ZendObject) {
        // SAFETY: `object` is the live PHP object of the method that is running.
        let class = unsafe { &*object }.get_class_entry();
        if !self.named.replace(true)
            && let Some(name) = class.name()
        {
            self.with(|builder| {
                if name != builder.class() {
                    builder.set_class(name);
                }
            });
        }
        let functions = declared(class);
        let steps = functions
            .iter()
            .enumerate()
            .fold(0, |steps, (index, function)| steps | u128::from(!function.is_null()) << index);
        self.with(|builder| {
            builder.set_overridden(steps).set_owner(object as usize);
        });
    }

    fn values(&self, given: &Arr<'_>) -> PhpResult<Array> {
        Ok(given.table().map(|table| self.array(table)).transpose()?.unwrap_or_default())
    }

    /// The strings of an array argument.
    fn strings(&self, given: &Arr<'_>) -> Vec<String> {
        let items = given.table().into_iter().flat_map(ZendHashTable::values);
        items.filter_map(|item| item.zend_str().map(|name| self.text(name.as_bytes()))).collect()
    }

    /// PHP's `Case\Expression` as the crate's, read through its getters and its clauses' properties.
    fn case(&self, given: &CaseArgument<'_>) -> PhpResult<CaseExpression> {
        let Some(object) = given.0.object() else { return Ok(CaseExpression::new()) };
        let ask = |method: &str| object.try_call_method(method, vec![]).map_err(PhpException::from);
        let case_of = |field: Option<&Zval>| {
            let case = field.and_then(Zval::object)?;
            slots(case).get(1)?.str().map(str::to_owned)
        };
        let mut out = CaseExpression::new();
        let whens = ask("getWhens")?;
        for when in whens.array().into_iter().flat_map(ZendHashTable::values) {
            let Some(fields) = properties(when) else { continue };
            let value = |name: &str| property(fields, name).map_or(Ok(Value::Null), |value| self.value(value));
            let text = |name: &str| {
                property(fields, name)
                    .and_then(Zval::zend_str)
                    .map(|text| self.text(text.as_bytes()))
                    .unwrap_or_default()
            };
            let list = |name: &str| -> PhpResult<Vec<Value>> {
                let items = property(fields, name).and_then(Zval::array).into_iter().flat_map(ZendHashTable::values);
                items.map(|item| self.value(item)).collect()
            };
            match case_of(property(fields, "kind")).as_deref().and_then(Kind::try_from_value) {
                Some(Kind::Comparison) => {
                    let operator = case_of(property(fields, "operator")).as_deref().and_then(Operator::try_from_value);
                    out.when(text("column"), operator.unwrap_or(Operator::Equal), value("value")?, value("then")?);
                }
                Some(Kind::Null) => {
                    out.when_null(text("column"), value("then")?);
                }
                Some(Kind::NotNull) => {
                    out.when_not_null(text("column"), value("then")?);
                }
                Some(Kind::In) => {
                    out.when_in(text("column"), list("values")?, value("then")?).map_err(|error| raised(&error))?;
                }
                Some(Kind::Raw) => {
                    out.when_raw(text("rawCondition"), value("then")?, list("rawBindings")?);
                }
                None => {}
            }
        }
        if ask("hasElse")?.bool().unwrap_or(false) {
            out.else_(self.value(&ask("getElse")?)?);
        }
        if let Some(alias) = ask("getAlias")?.zend_str() {
            out.alias(self.text(alias.as_bytes()));
        }
        Ok(out)
    }

    /// A cursor: a value, or the row it is read from. A row that is an object is read here, where
    /// PHP reads it when it builds: `_cursor` of an `ArrayAccess`, the public properties of any other.
    fn cursor(&self, given: &Zval) -> PhpResult<Value> {
        let Some(object) = given.object() else { return self.value(given) };
        if parts(given).is_some() {
            return self.value(given);
        }
        let row = if class("ArrayAccess").is_ok_and(|class| object.instance_of(class)) {
            let mut row = ZendHashTable::new();
            if object.try_call_method("offsetExists", vec![&"_cursor"]).map_err(PhpException::from)?.bool()
                == Some(true)
            {
                let value = object.try_call_method("offsetGet", vec![&"_cursor"]).map_err(PhpException::from)?;
                row.insert("_cursor", value).map_err(PhpException::from)?;
            }
            let mut zval = Zval::new();
            zval.set_hashtable(row);
            zval
        } else {
            let read = ZendCallable::try_from_name("get_object_vars").map_err(PhpException::from)?;
            read.try_call(vec![given]).map_err(PhpException::from)?
        };
        self.value(&row)
    }

    /// The pairs of an array of strings by name.
    fn pairs(&self, given: &Arr<'_>) -> Vec<(String, String)> {
        let items = given.table().into_iter().flatten();
        items
            .map(|(name, value)| {
                let value = value
                    .zend_str()
                    .map_or_else(|| value.string().unwrap_or_default(), |text| self.text(text.as_bytes()));
                (self.text(name.to_string().as_bytes()), value)
            })
            .collect()
    }

    /// `$this->bindings`: a list of `Binding`.
    fn bound(&self) -> PhpResult<Zval> {
        let bindings = self.with(|builder| builder.binding_list().to_vec());
        if bindings.is_empty() {
            return Ok(empty());
        }
        let mut out = Vec::with_capacity(bindings.len());
        for binding in &bindings {
            let column = binding.column.as_deref().map_or_else(null, |column| string(&bytes(D::JSON, column)));
            let mut arguments = [zval(D::JSON, &binding.value)?, column];
            out.push(construct(class(BINDING)?, &mut arguments)?.into_zval(false).map_err(PhpException::from)?);
        }
        out.into_zval(false).map_err(PhpException::from)
    }

    /// `$this->bindings = $bindings`: each a `Binding`, or a value on its own.
    fn bind(&self, bindings: &Zval) {
        let mut list = Vec::new();
        for item in bindings.array().into_iter().flat_map(ZendHashTable::values) {
            let binding = item.object().filter(|object| class(BINDING).is_ok_and(|class| object.instance_of(class)));
            let fields = binding.and_then(|_| properties(item));
            let value = fields.map_or(Some(item), |fields| property(fields, "value"));
            let Ok(value) = value.map_or(Ok(Value::Null), |value| self.value(value)) else { continue };
            let column = fields.and_then(|fields| property(fields, "column")).and_then(Zval::zend_str);
            list.push(Binding::new(value, column.map(|column| self.text(column.as_bytes()))));
        }
        self.with(|builder| {
            builder.set_binding_list(list);
        });
    }

    /// `$this->pendingQueries`: a list of `Query`.
    fn pending(&self) -> PhpResult<Zval> {
        let pending = self.with(|builder| builder.pending().to_vec());
        if pending.is_empty() {
            return Ok(empty());
        }
        let queries = pending.iter().map(|query| query_object(D::JSON, query)).collect::<PhpResult<Vec<_>>>()?;
        queries.into_zval(false).map_err(PhpException::from)
    }

    /// `$this->resolvedAttributeCache`: what each attribute resolved to.
    fn resolved(&self) -> Zval {
        let mut table = ZendHashTable::new();
        self.with(|builder| {
            for (attribute, resolved) in builder.resolved_attributes() {
                let key = bytes(D::JSON, attribute);
                let _ = table.insert(String::from_utf8_lossy(&key).as_ref(), string(&bytes(D::JSON, resolved)));
            }
        });
        if table.is_empty() {
            return empty();
        }
        let mut out = Zval::new();
        out.set_hashtable(table);
        out
    }

    /// `$this->resolvedAttributeCache = $resolved`.
    fn resolve(&self, resolved: &Zval) {
        let mut pairs = Vec::new();
        for (attribute, found) in resolved.array().into_iter().flatten() {
            if let Some(found) = found.zend_str() {
                pairs.push((self.text(attribute.to_string().as_bytes()), self.text(found.as_bytes())));
            }
        }
        self.with(|builder| {
            builder.set_resolved_attributes(pairs);
        });
    }

    /// The queries of an array argument.
    fn queries(&self, given: &Arr<'_>) -> PhpResult<Vec<Query>> {
        given.table().into_iter().flat_map(ZendHashTable::values).map(|query| self.query(query)).collect()
    }

    /// A fluent call that can refuse: the crate's error is the exception.
    fn change(&self, work: impl FnOnce(&mut Builder<D>) -> Result<(), Error>) -> PhpResult<()> {
        self.with(work).map_err(|error| raised(&error))
    }

    /// One of the `compile*()` methods that take a query, run as the builder itself does.
    fn compile(&self, step: Step, query: &Zval) -> PhpResult<Bytes> {
        let query = self.query(query)?;
        self.step(step, &[Arg::Query(&query)])
    }

    /// The bindings so far, as PHP holds them.
    fn bindings(&self, overridable: bool) -> PhpResult<Zval> {
        let bindings =
            self.with(|builder| if overridable { builder.bindings() } else { builder.base_binding_values() });
        if bindings.is_empty() {
            return Ok(empty());
        }
        let bindings = bindings.iter().map(|binding| zval(D::JSON, binding)).collect::<PhpResult<Vec<_>>>()?;
        bindings.into_zval(false).map_err(PhpException::from)
    }

    /// A PHP `Query` as the crate's.
    fn query(&self, zval: &Zval) -> PhpResult<Query> {
        let (method, attribute, values, alias, attribute_type, on_array) = parts(zval)
            .ok_or_else(|| PhpException::new(format!("A query must be of type {QUERY}"), 0, ce::type_error()))?;
        let values = values.array().map(|values| self.array(values)).transpose()?.unwrap_or_default();
        let mut query = Query::new(method, self.text(attribute), values, self.text(alias));
        query.set_attribute_type(self.text(attribute_type));
        query.set_on_array(on_array);
        Ok(query)
    }

    /// The statement the builder compiled, as the package's `Statement`.
    fn statement(&self, compiled: Result<Statement, Error>) -> PhpResult<Zval> {
        let found = compiled.map_err(|error| raised(&error))?;
        if let Some((object, query)) = self.returned.take()
            && query == found.query
        {
            return Ok(object);
        }
        self.statement_object(&found)
    }

    /// `found` as a new `Statement` of the package.
    fn statement_object(&self, found: &Statement) -> PhpResult<Zval> {
        let query = self.written(&found.query)?;
        let mut bindings = Vec::with_capacity(found.bindings.len());
        for binding in &found.bindings {
            bindings.push(zval(D::JSON, binding)?);
        }
        let mut read_only = Zval::new();
        read_only.set_bool(found.read_only);
        let bindings =
            if bindings.is_empty() { empty() } else { bindings.into_zval(false).map_err(PhpException::from)? };
        let executor = self.executor.borrow().as_ref().map_or_else(null, Zval::shallow_clone);
        let mut arguments = vec![string(&query), bindings, read_only, executor];
        if let Some(named) = &found.named_bindings {
            arguments.push(zval(D::JSON, &Value::Array(named.clone()))?);
        }
        construct(class(STATEMENT)?, &mut arguments)?.into_zval(false).map_err(PhpException::from)
    }

    /// ClickHouse's `FormattedInsertStatement`: the envelope, its columns and format, and the rows
    /// when `bulkInsert()` serialized them.
    fn formatted(&self, found: &FormattedInsertStatement) -> PhpResult<Zval> {
        let columns: Vec<Zval> = found.columns.iter().map(|column| string(&bytes(D::JSON, column))).collect();
        let columns =
            if columns.is_empty() { empty() } else { columns.into_zval(false).map_err(PhpException::from)? };
        let mut read_only = Zval::new();
        read_only.set_bool(found.read_only);
        let mut arguments = [
            string(&self.written(&found.query)?),
            empty(),
            columns,
            string(&bytes(D::JSON, &found.format)),
            // The rows are text as PHP gave it, not a character a byte.
            found.body.as_deref().map_or_else(null, |body| string(body.as_bytes())),
            read_only,
            self.executor.borrow().as_ref().map_or_else(null, Zval::shallow_clone),
        ];
        construct(class(FORMATTED)?, &mut arguments)?.into_zval(false).map_err(PhpException::from)
    }

    /// What the builder wrote, as the bytes PHP gets. A JSON dialect that was given a string
    /// which is not UTF-8 fails where it encodes JSON, as `json_encode()` does; what it
    /// writes without encoding (a single step, `INSERT ... DEFAULT VALUES`) keeps the bytes.
    fn written<'t>(&self, text: &'t str) -> PhpResult<Cow<'t, [u8]>> {
        if !self.malformed.get() {
            return Ok(bytes(D::JSON, text));
        }
        if text.contains('{') {
            let class = ClassEntry::try_find("JsonException").unwrap_or_else(ce::exception);
            let message = "Malformed UTF-8 characters, possibly incorrectly encoded".to_owned();
            return Err(PhpException::new(message, 5, class));
        }
        Ok(narrow(text))
    }

    /// The statement with its bindings written into it.
    fn raw(&self) -> PhpResult<Bytes> {
        let sql = self.with(|builder| if D::JSON { builder.to_raw_sql() } else { builder.to_raw_sql_widened() });
        Ok(Bytes(self.written(&sql.map_err(|error| raised(&error))?)?.into_owned()))
    }

    /// A step that returns a string, run as the builder itself does.
    fn step(&self, step: Step, arguments: &[Arg<'_>]) -> PhpResult<Bytes> {
        match self.with(|builder| builder.base(step, arguments)).map_err(|error| raised(&error))? {
            Ret::Str(text) => Ok(Bytes(self.written(&text)?.into_owned())),
            _ => Ok(Bytes(Vec::new())),
        }
    }

    /// A step that returns a bool, run as the builder itself runs it.
    fn step_bool(&self, step: Step, arguments: &[Arg<'_>]) -> PhpResult<bool> {
        let returned = self.with(|builder| builder.base(step, arguments)).map_err(|error| raised(&error))?;
        Ok(matches!(returned, Ret::Bool(true)))
    }

    /// Calls `function`, a PHP method of `object`'s class, as `$this->function(...)` does.
    fn overridden(
        &self,
        object: *mut ZendObject,
        function: *mut zend_function,
        step: Step,
        arguments: &[Arg<'_>],
    ) -> Option<Ret> {
        let mut zvals = Vec::with_capacity(arguments.len());
        for argument in arguments {
            zvals.push(match argument {
                Arg::Str(text) => string(&bytes(D::JSON, text)),
                Arg::MaybeStr(text) => text.map_or_else(null, |text| string(&bytes(D::JSON, text))),
                Arg::Bool(flag) => {
                    let mut out = Zval::new();
                    out.set_bool(*flag);
                    out
                }
                Arg::Values(values) => list(D::JSON, values).ok()?,
                Arg::Value(found) => zval(D::JSON, found).ok()?,
                Arg::Query(found) => query_object(D::JSON, found).ok()?,
                Arg::Method(method) => method_case(*method).ok()?,
                Arg::Join(join) => join_object(D::JSON, join).ok()?,
                Arg::Int(number) => number.map_or_else(null, |number| {
                    let mut out = Zval::new();
                    out.set_long(number);
                    out
                }),
                Arg::Case(case) => self.case_object(case).ok()?,
                Arg::Statement(statement) => self.statement_object(statement).ok()?,
                Arg::Placement(placement) => placed(*placement).ok()?,
                // Taken by reference: what the override leaves in it is read back below.
                Arg::Strings(strings) => referenced(self.listed(strings).ok()?),
                Arg::Parsed(None) => null(),
                // PHP's own grouping of the builder's queries.
                Arg::Parsed(Some(_)) => {
                    let group = ZendCallable::try_from_name("Utopia\\Query\\Query::groupByType").ok()?;
                    group.try_call(vec![&self.pending().ok()?]).ok()?
                }
            });
        }
        // `createTokenizer()` takes nothing: the SQL is for the tokenizer it returns.
        if step == Step::CreateTokenizer {
            let [Arg::Str(sql)] = arguments else { return None };
            let tokenizer = invoke(object, function, &mut [])?;
            return self.tokenized(&tokenizer, sql).ok().map(|read| Ret::Select(Box::new(read)));
        }
        let returned = invoke(object, function, &mut zvals)?;
        let strings = |list: &Zval| -> Vec<String> {
            let items = list.dereference().array().into_iter().flat_map(ZendHashTable::values);
            items.filter_map(Zval::zend_str).map(|item| self.text(item.as_bytes())).collect()
        };
        match step {
            Step::CompileWhereClauses | Step::CompileOrderAndLimit => {
                return Some(Ret::Strings(strings(zvals.first()?)));
            }
            Step::CompileAssignments => return Some(Ret::Strings(strings(&returned))),
            Step::ValidateAndGetColumns => {
                let columns = returned.array().into_iter().flat_map(ZendHashTable::values);
                let columns = columns.filter_map(Zval::zend_str).map(|column| Key::Str(Str::from(column.as_bytes())));
                return Some(Ret::Columns(columns.collect()));
            }
            Step::CompileInsertBody => {
                let body = returned.array()?;
                let sql = body.get_index(0).and_then(Zval::zend_str).map(|sql| self.text(sql.as_bytes()))?;
                let bindings = body.get_index(1).and_then(Zval::array).into_iter().flat_map(ZendHashTable::values);
                return Some(Ret::InsertBody(sql, bindings.map(bound).collect()));
            }
            Step::GetBindingValues => {
                let values = returned.array().into_iter().flat_map(ZendHashTable::values);
                return Some(Ret::Values(values.map(bound).collect()));
            }
            Step::CompileVectorOrderExpression => return Some(Ret::Condition(condition(D::JSON, &returned))),
            Step::ResolveJoinFilterPlacement => {
                return PlacementArgument::from_zval(&returned).map(|placement| Ret::Placement(placement.0));
            }
            _ => {}
        }
        if let Some(text) = returned.zend_str() {
            return Some(Ret::Str(self.text(text.as_bytes())));
        }
        if let Some(flag) = returned.bool() {
            return Some(Ret::Bool(flag));
        }
        // `build()` returns a statement.
        Some(self.read(&returned).map_or(Ret::None, Ret::Statement))
    }

    /// The package's `Statement` as the crate's.
    fn read(&self, statement: &Zval) -> Option<Statement> {
        let fields = properties(statement)?;
        let query = property(fields, "query").and_then(Zval::zend_str).map(|query| self.text(query.as_bytes()));
        let bindings = property(fields, "bindings").and_then(Zval::array).into_iter().flat_map(ZendHashTable::values);
        let mut read = Statement::new(query.unwrap_or_default(), bindings.map(bound).collect());
        read.read_only = property(fields, "readOnly").and_then(Zval::bool).unwrap_or(false);
        Some(read)
    }

    /// Appends `clauses` to `$parts`, an array PHP passed by reference.
    fn append(&self, parts: &mut Zval, clauses: &[String]) -> PhpResult<()> {
        let inner = parts.reference_mut().map_or(std::ptr::null_mut(), std::ptr::from_mut);
        // SAFETY: the reference PHP passed holds the array for the length of this call.
        let parts = if inner.is_null() { parts } else { unsafe { &mut *inner } };
        let mut held = parts.array().map(ZendHashTable::to_owned).unwrap_or_else(ZendHashTable::new);
        for clause in clauses {
            held.push(string(&bytes(D::JSON, clause))).map_err(PhpException::from)?;
        }
        parts.set_hashtable(held);
        Ok(())
    }

    /// `$sql` read as PHP reads a CTE: the tokenizer's tokens, filtered, through the package's parser
    /// with the dialect's string rules.
    fn tokenized(&self, tokenizer: &Zval, sql: &str) -> PhpResult<utopia_query::ast::Select> {
        let tokenizer = tokenizer.object().ok_or_else(pending)?;
        let tokens =
            tokenizer.try_call_method("tokenize", vec![&string(&bytes(D::JSON, sql))]).map_err(|_| pending())?;
        let filter =
            ZendCallable::try_from_name("Utopia\\Query\\Tokenizer\\Tokenizer::filter").map_err(PhpException::from)?;
        let tokens = filter.try_call(vec![&tokens]).map_err(|_| pending())?;
        let rules = utopia_query::StringLiteral::CASES.iter().find(|(rules, _)| *rules == D::LITERAL);
        let rules = enum_case("Utopia\\Query\\StringLiteral", rules.map_or("Backslash", |found| found.1))?;
        let parser = construct(class("Utopia\\Query\\AST\\Parser")?, &mut [rules])?;
        let select = parser.try_call_method("parse", vec![&tokens]).map_err(|_| pending())?;
        super::ast::read_select(&select)
    }

    /// The crate's `CASE` as the package's `Case\Expression`, built through its own methods.
    fn case_object(&self, case: &CaseExpression) -> PhpResult<Zval> {
        let made = construct(class(CASE)?, &mut [])?;
        let text = |text: &str| string(&bytes(D::JSON, text));
        let call = |method: &str, arguments: Vec<&dyn IntoZvalDyn>| {
            made.try_call_method(method, arguments).map(drop).map_err(PhpException::from)
        };
        for when in case.whens() {
            let column = text(when.column.as_deref().unwrap_or_default());
            let then = zval(D::JSON, &when.then)?;
            match when.kind {
                Kind::Comparison => {
                    let operator = enum_case(Operator::PHP_CLASS, when.operator.unwrap_or(Operator::Equal).name())?;
                    call("when", vec![&column, &operator, &zval(D::JSON, &when.value)?, &then])?;
                }
                Kind::Null => call("whenNull", vec![&column, &then])?,
                Kind::NotNull => call("whenNotNull", vec![&column, &then])?,
                Kind::In => {
                    let values = when.values.iter().map(|value| zval(D::JSON, value)).collect::<PhpResult<Vec<_>>>()?;
                    call("whenIn", vec![&column, &values.into_zval(false).map_err(PhpException::from)?, &then])?;
                }
                Kind::Raw => {
                    let bindings =
                        when.raw_bindings.iter().map(|value| zval(D::JSON, value)).collect::<PhpResult<Vec<_>>>()?;
                    let bindings = if bindings.is_empty() {
                        empty()
                    } else {
                        bindings.into_zval(false).map_err(PhpException::from)?
                    };
                    call("whenRaw", vec![&text(when.raw_condition.as_deref().unwrap_or_default()), &then, &bindings])?;
                }
            }
        }
        if case.has_else() {
            call("else", vec![&zval(D::JSON, case.else_value())?])?;
        }
        if !case.get_alias().is_empty() {
            call("alias", vec![&text(case.get_alias())])?;
        }
        made.into_zval(false).map_err(PhpException::from)
    }

    /// A step run as the builder itself runs it, which is what `parent::step()` is.
    fn base(&self, step: Step, arguments: &[Arg<'_>]) -> PhpResult<Ret> {
        self.with(|builder| builder.base(step, arguments)).map_err(|error| raised(&error))
    }

    /// A list of strings as PHP's.
    fn listed(&self, items: &[String]) -> PhpResult<Zval> {
        if items.is_empty() {
            return Ok(empty());
        }
        let items: Vec<Zval> = items.iter().map(|item| string(&bytes(D::JSON, item))).collect();
        items.into_zval(false).map_err(PhpException::from)
    }
}

impl<D: Dialect + Text> Clone for State<D> {
    /// `clone $builder`: a copy of the builder as it is now.
    fn clone(&self) -> Self {
        let copy = Self::of(self.with(|builder| builder.clone()), self.malformed.get());
        *copy.executor.borrow_mut() = self.executor.borrow().as_ref().map(Zval::shallow_clone);
        copy
    }
}

impl<D: Dialect + Text> Drop for State<D> {
    fn drop(&mut self) {
        // SAFETY: `own` came from `Box::leak` and is freed here only.
        drop(unsafe { Box::from_raw(self.own.as_ptr()) });
    }
}

/// A PHP class that holds a builder.
pub trait Native: RegisteredClass {
    /// The dialect of its builder.
    type Dialect: Dialect + Text;

    /// The builder of an object of the class.
    fn state(&self) -> &State<Self::Dialect>;

    /// An object of the class with a new builder.
    fn fresh() -> Self;

    /// Where the class keeps ext-php-rs's handler for a pointer to a property.
    fn pointers() -> &'static std::sync::atomic::AtomicUsize;
}

/// A pointer to a property of a builder, for PHP to write through. ext-php-rs has none to give for a
/// property a getter backs, so `$this->pendingQueries[] = $query` would change nothing. Its handler
/// still runs first, for the visibility check.
unsafe extern "C" fn pointer<C: Native>(
    object: *mut ZendObject,
    member: *mut ext_php_rs::types::ZendStr,
    kind: std::ffi::c_int,
    cache: *mut *mut std::ffi::c_void,
) -> *mut Zval {
    // SAFETY: stored from the handler itself in `prepare`.
    let original: unsafe extern "C" fn(
        *mut ZendObject,
        *mut ext_php_rs::types::ZendStr,
        std::ffi::c_int,
        *mut *mut std::ffi::c_void,
    ) -> *mut Zval = unsafe { std::mem::transmute(C::pointers().load(std::sync::atomic::Ordering::Acquire)) };
    // SAFETY: PHP passes a live object and the name of the property.
    let found = unsafe { original(object, member, kind, cache) };
    if !found.is_null() || ExecutorGlobals::has_exception() {
        return found;
    }
    // SAFETY: as above.
    let (object, name) = unsafe { (&*object, (*member).as_bytes()) };
    match (ZendClassObject::<C>::from_zend_obj(object), Property::named(name)) {
        (Some(builder), Some(property)) => builder.state().share(property),
        _ => found,
    }
}

/// Has PHP create objects of `C` with [`create`] and write to their properties through [`pointer`].
/// Runs once every class is registered.
pub(super) fn prepare<C: Native>() {
    let metadata = C::get_metadata();
    let handlers = std::ptr::from_ref(metadata.handlers()).cast_mut();
    // SAFETY: an internal class and its handlers live as long as the process, and no script has run.
    unsafe {
        if let Some(original) = (*handlers).get_property_ptr_ptr {
            C::pointers().store(original as usize, std::sync::atomic::Ordering::Release);
            (*handlers).get_property_ptr_ptr = Some(pointer::<C>);
        }
        (*std::ptr::from_ref(metadata.ce()).cast_mut()).__bindgen_anon_2.create_object = Some(create::<C>);
    }
}

/// Creates an object of `C`, or of a PHP subclass, with its builder already in it. A subclass may
/// declare a constructor that never calls the parent's, which PHP's own classes have none of.
extern "C" fn create<C: Native>(class: *mut ClassEntry) -> *mut ZendObject {
    // SAFETY: PHP passes the class it is creating an object of, and the object is initialised below.
    let mut held = unsafe { ZendClassObject::<C>::new_uninit(class.as_ref()) };
    held.initialize(C::fresh());
    let object = std::ptr::from_mut(held.get_mut_zend_obj());
    // The builder knows its owner from the start, whichever method PHP calls first.
    held.state().running(object);
    // PHP owns the object from here.
    std::mem::forget(held);
    object
}

/// The steps a PHP subclass of `C` overrides, called on the object whose method is running.
struct Subclass<C>(PhantomData<fn() -> C>);

impl<C: Native> Overrides for Subclass<C> {
    fn call(&self, builder: *mut (), step: Step, arguments: &[Arg<'_>]) -> Option<Result<Ret, Error>> {
        // SAFETY: the crate lends its `Builder<C::Dialect>` for this call, and its owner is the
        // live PHP object `running()` set, which holds the builder.
        let object = unsafe { (*builder.cast::<Builder<C::Dialect>>()).owner() as *mut ZendObject };
        let function = unsafe { object.as_ref() }.map(|object| declared(object.get_class_entry())[step as usize])?;
        if function.is_null() {
            return None;
        }
        // SAFETY: as above.
        let state = ZendClassObject::<C>::from_zend_obj(unsafe { &*object })?.state();
        // The override reaches the builder through the pointer the crate lent for this call.
        let before = state.lent.replace(builder.cast());
        let returned = state.overridden(object, function, step, arguments);
        state.lent.set(before);
        // An exception stays pending for the native method that started the build to rethrow.
        Some(returned.ok_or_else(|| Error::Validation(format!("{}() failed", step.method()))))
    }
}

/// A `Query` argument: an override must be able to declare the same type.
pub struct QueryArgument<'a>(&'a Zval);

impl<'a> FromZval<'a> for QueryArgument<'a> {
    const TYPE: DataType = DataType::object(QUERY);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        parts(zval).map(|_| QueryArgument(zval))
    }
}

/// A `Builder` argument.
pub struct BuilderArgument<'a>(&'a Zval);

impl<'a> FromZval<'a> for BuilderArgument<'a> {
    const TYPE: DataType = DataType::object(BUILDER);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let base = Base::get_metadata().ce();
        zval.object().filter(|object| object.instance_of(base)).map(|_| BuilderArgument(zval))
    }
}

/// A PHP builder among another's clauses. It is built by its own `build()`, which a subclass
/// may override, when the builder that holds it is; the object stays the one PHP passed.
struct Foreign {
    object: Zval,
    json: bool,
}

// SAFETY: a PHP request runs on one thread, and the builder that holds this never leaves it.
unsafe impl Send for Foreign {}
// SAFETY: as above.
unsafe impl Sync for Foreign {}

impl Foreign {
    fn of<D: Dialect + Text>(builder: &BuilderArgument<'_>) -> Self {
        Foreign { object: builder.0.shallow_clone(), json: D::JSON }
    }

    fn text(&self, bytes: &[u8]) -> String {
        if self.json { String::from_utf8_lossy(bytes).into_owned() } else { wide(bytes).into_owned() }
    }
}

/// A binding of a statement PHP built: a scalar or an array of them.
fn bound(zval: &Zval) -> Value {
    if let Some(found) = zval.bool() {
        Value::Bool(found)
    } else if let Some(found) = zval.long() {
        Value::Int(found)
    } else if let Some(found) = zval.double() {
        Value::Float(found)
    } else if let Some(found) = zval.zend_str() {
        Value::Str(Str::from(found.as_bytes()))
    } else if let Some(found) = zval.array() {
        let mut out = Array::default();
        for (key, item) in found {
            let key = match key {
                ArrayKey::Long(index) => Key::Int(index),
                ArrayKey::String(name) => Key::Str(Str::from(name.as_bytes())),
                ArrayKey::Str(name) => Key::Str(Str::from(name.as_bytes())),
                ArrayKey::ZendString(name) => Key::Str(Str::from(name.as_bytes())),
            };
            out.set(key, bound(item));
        }
        Value::Array(out)
    } else {
        Value::Null
    }
}

impl AnyBuilder for Foreign {
    fn build(&mut self) -> Result<Statement, Error> {
        // An exception stays pending for the native method that started the build to rethrow.
        let failed = || Error::Validation("build() failed".to_owned());
        let object = self.object.object().ok_or_else(failed)?;
        let built = object.try_call_method("build", vec![]).map_err(|_| failed())?;
        if !ExecutorGlobals::get().exception.is_null() {
            return Err(failed());
        }
        let fields = built.object().and_then(|object| object.get_properties().ok()).ok_or_else(failed)?;
        let property = |name: &str| fields.get(name).map(|found| found.indirect().unwrap_or(found));
        let query = property("query").and_then(Zval::zend_str).map(|query| self.text(query.as_bytes()));
        let bindings = property("bindings").and_then(Zval::array).into_iter().flat_map(ZendHashTable::values);
        let mut statement = Statement::new(query.unwrap_or_default(), bindings.map(bound).collect());
        statement.read_only = property("readOnly").and_then(Zval::bool).unwrap_or(false);
        Ok(statement)
    }

    fn clone_box(&self) -> Box<dyn AnyBuilder> {
        // `clone $builder` clones the builders it holds.
        Box::new(Foreign { object: clone_object(&self.object), json: self.json })
    }

    fn php_class(&self) -> &'static str {
        BUILDER
    }

    fn as_any(&self) -> &dyn std::any::Any {
        self
    }
}

/// The properties of a PHP object, by name.
fn properties(zval: &Zval) -> Option<&ZendHashTable> {
    zval.object()?.get_properties().ok()
}

fn property<'a>(fields: &'a ZendHashTable, name: &str) -> Option<&'a Zval> {
    fields.get(name).map(|found| found.indirect().unwrap_or(found))
}

/// A `Builder\Condition` PHP made: its expression and bindings.
fn condition(json: bool, zval: &Zval) -> Option<Condition> {
    let fields = properties(zval)?;
    let expression = property(fields, "expression")?.zend_str()?;
    let expression = if json {
        String::from_utf8_lossy(expression.as_bytes()).into_owned()
    } else {
        wide(expression.as_bytes()).into_owned()
    };
    let bindings = property(fields, "bindings").and_then(Zval::array).into_iter().flat_map(ZendHashTable::values);
    Some(Condition::new(expression, bindings.map(bound).collect()))
}

/// The conditions of a PHP `JoinBuilder`, as written on it.
fn join_of(json: bool, zval: &Zval) -> JoinBuilder {
    let text = |bytes: &[u8]| if json { String::from_utf8_lossy(bytes).into_owned() } else { wide(bytes).into_owned() };
    let fields = properties(zval);
    let list = |name: &str| {
        fields
            .and_then(|fields| property(fields, name))
            .and_then(Zval::array)
            .into_iter()
            .flat_map(ZendHashTable::values)
    };
    let ons = list("ons").filter_map(|on| {
        let fields = properties(on)?;
        let part = |name: &str| property(fields, name)?.zend_str().map(|found| text(found.as_bytes()));
        Some(JoinOn::new(part("left")?, part("operator")?, part("right")?))
    });
    let mut join = JoinBuilder::new();
    join.push(ons, list("wheres").filter_map(|found| condition(json, found)));
    join
}

/// The crate's join conditions as a PHP `JoinBuilder`, for an override that is handed one.
fn join_object(json: bool, join: &JoinBuilder) -> PhpResult<Zval> {
    let text = |text: &str| string(&bytes(json, text));
    let mut ons = Vec::with_capacity(join.ons().len());
    for on in join.ons() {
        let mut arguments = [text(&on.left), text(&on.operator), text(&on.right)];
        ons.push(construct(class(JOIN_ON)?, &mut arguments)?.into_zval(false).map_err(PhpException::from)?);
    }
    let mut wheres = Vec::with_capacity(join.wheres().len());
    for found in join.wheres() {
        let bindings = found.bindings.iter().map(|binding| zval(json, binding)).collect::<PhpResult<Vec<_>>>()?;
        let bindings =
            if bindings.is_empty() { empty() } else { bindings.into_zval(false).map_err(PhpException::from)? };
        let mut arguments = [text(&found.expression), bindings];
        wheres.push(construct(class(CONDITION)?, &mut arguments)?.into_zval(false).map_err(PhpException::from)?);
    }
    let builder = class(JOIN_BUILDER)?;
    let mut object = construct(builder, &mut [])?;
    for (name, items) in [("ons", ons), ("wheres", wheres)] {
        if items.is_empty() {
            continue;
        }
        let Some(slot) = slot_of(builder, name) else { continue };
        let items = items.into_zval(false).map_err(PhpException::from)?;
        // SAFETY: `slot_of` only answers a slot the class declares, and a new `JoinBuilder`
        // holds the shared empty array there, which is not counted.
        unsafe { object.properties_table.as_mut_ptr().add(slot).write(items) };
    }
    object.into_zval(false).map_err(PhpException::from)
}

/// `returning()`'s columns: the ones given, or every column.
pub enum Returned<'a> {
    Given(&'a Zval),
    Star,
}

impl<'a> FromZval<'a> for Returned<'a> {
    const TYPE: DataType = DataType::Array;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        zval.is_array().then_some(Returned::Given(zval))
    }
}

impl StubLiteral for Returned<'_> {
    fn stub_literal(&self) -> String {
        "['*']".to_owned()
    }
}

/// A `JoinBuilder` argument.
pub struct Joined<'a>(&'a Zval);

impl<'a> FromZval<'a> for Joined<'a> {
    const TYPE: DataType = DataType::object(JOIN_BUILDER);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let builder = ClassEntry::try_find(JOIN_BUILDER)?;
        zval.object().filter(|object| object.instance_of(builder)).map(|_| Joined(zval))
    }
}

/// A `Method` argument.
pub struct MethodArgument(Method);

impl<'a> FromZval<'a> for MethodArgument {
    const TYPE: DataType = DataType::object(Method::PHP_CLASS);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        zval.object().and_then(case_method).map(MethodArgument)
    }
}

/// A `JoinType` argument.
pub struct Join(JoinType);

impl<'a> FromZval<'a> for Join {
    const TYPE: DataType = DataType::object(JoinType::PHP_CLASS);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let object =
            zval.object().filter(|object| class(JoinType::PHP_CLASS).is_ok_and(|class| object.instance_of(class)))?;
        slots(object).get(1)?.str().and_then(JoinType::try_from_value).map(Join)
    }
}

impl StubLiteral for Join {
    fn stub_literal(&self) -> String {
        format!("\\{}::{}", JoinType::PHP_CLASS, self.0.name())
    }
}

/// An argument that is a case of one of the package's enums.
macro_rules! case_argument {
    ($(#[$meta:meta])* $name:ident, $of:ty) => {
        $(#[$meta])*
        pub struct $name($of);

        impl<'a> FromZval<'a> for $name {
            const TYPE: DataType = DataType::object(<$of>::PHP_CLASS);

            fn from_zval(zval: &'a Zval) -> Option<Self> {
                let object =
                    zval.object().filter(|object| class(<$of>::PHP_CLASS).is_ok_and(|class| object.instance_of(class)))?;
                slots(object).get(1)?.str().and_then(<$of>::try_from_value).map($name)
            }
        }

        impl StubLiteral for $name {
            fn stub_literal(&self) -> String {
                format!("\\{}::{}", <$of>::PHP_CLASS, self.0.name())
            }
        }
    };
}

case_argument!(
    /// A `VectorMetric` argument.
    Metric,
    VectorMetric
);
case_argument!(
    /// A ClickHouse `Format` argument.
    Formats,
    Format
);
case_argument!(
    /// An `AsofOperator` argument.
    Asof,
    AsofOperator
);

/// A `Case\Expression` argument.
pub struct CaseArgument<'a>(&'a Zval);

impl<'a> FromZval<'a> for CaseArgument<'a> {
    const TYPE: DataType = DataType::object(CASE);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let object = zval.object()?;
        class(CASE).is_ok_and(|class| object.instance_of(class)).then_some(CaseArgument(zval))
    }
}

/// A `Statement` argument.
pub struct StatementArgument<'a>(&'a Zval);

impl<'a> FromZval<'a> for StatementArgument<'a> {
    const TYPE: DataType = DataType::object(STATEMENT);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let object = zval.object()?;
        class(STATEMENT).is_ok_and(|class| object.instance_of(class)).then_some(StatementArgument(zval))
    }
}

/// A `Hook\Join\Placement` argument: `On` or `Where`.
pub struct PlacementArgument(hook::Placement);

impl<'a> FromZval<'a> for PlacementArgument {
    const TYPE: DataType = DataType::object(PLACEMENT);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let object = zval.object().filter(|object| class(PLACEMENT).is_ok_and(|class| object.instance_of(class)))?;
        match slots(object).first()?.str()? {
            "On" => Some(PlacementArgument(hook::Placement::On)),
            "Where" => Some(PlacementArgument(hook::Placement::Where)),
            _ => None,
        }
    }
}

/// A `Builder\ParsedQuery` argument. The builder groups its own queries, as PHP's callers do.
pub struct Grouped;

impl<'a> FromZval<'a> for Grouped {
    const TYPE: DataType = DataType::object(PARSED);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let object = zval.object()?;
        class(PARSED).is_ok_and(|class| object.instance_of(class)).then_some(Grouped)
    }
}

/// Calls `function`, a method of `object`'s class, whatever its visibility. `None` when it threw:
/// the exception stays pending.
fn invoke(object: *mut ZendObject, function: *mut zend_function, arguments: &mut [Zval]) -> Option<Zval> {
    let mut returned = Zval::new();
    // SAFETY: `object` is a live builder and `function` a method of its class, found in its
    // function table this request; the arguments are initialized and outlive the call.
    unsafe {
        ext_php_rs::ffi::zend_call_known_function(
            function,
            object,
            (*object).ce,
            &raw mut returned,
            u32::try_from(arguments.len()).unwrap_or(u32::MAX),
            arguments.as_mut_ptr(),
            std::ptr::null_mut(),
        );
    }
    ExecutorGlobals::get().exception.is_null().then_some(returned)
}

/// The exception PHP has pending, or a plain one when it has none.
fn pending() -> PhpException {
    match thrown() {
        Err(pending) => pending,
        Ok(()) => PhpException::from_message("A method of the package failed".to_owned()),
    }
}

/// The serializer a subclass's `createAstSerializer()` returned, which `fromAst()` writes what stays
/// raw SQL with.
struct Written {
    serializer: Zval,
    json: bool,
}

impl Written {
    fn call(&self, method: &str, node: PhpResult<Zval>) -> Result<String, Error> {
        let failed = || Error::Validation(format!("{method}() failed"));
        let node = node.map_err(|_| failed())?;
        let serializer = self.serializer.object().ok_or_else(failed)?;
        let written = serializer.try_call_method(method, vec![&node]).map_err(|_| failed())?;
        let written = written.zend_str().ok_or_else(failed)?;
        Ok(if self.json {
            String::from_utf8_lossy(written.as_bytes()).into_owned()
        } else {
            wide(written.as_bytes()).into_owned()
        })
    }
}

impl AstWriter for Written {
    fn serialize(&self, statement: &utopia_query::ast::Select) -> Result<String, Error> {
        self.call("serialize", super::ast::nodes(statement))
    }

    fn serialize_expression(&self, expression: &utopia_query::ast::Expression) -> Result<String, Error> {
        self.call("serializeExpression", super::ast::expression_node(expression))
    }
}

/// A `Hook\Join\Placement` case.
fn placed(placement: hook::Placement) -> PhpResult<Zval> {
    enum_case(PLACEMENT, if placement == hook::Placement::On { "On" } else { "Where" })
}

/// `value` as a PHP reference to it, for an argument a method takes by reference.
fn referenced(value: Zval) -> Zval {
    // `GC_REFERENCE`: a reference, which the cycle collector does not walk.
    const REFERENCE: u32 = 10 | (1 << 4);
    // `IS_REFERENCE_EX`: a reference, counted.
    const REFERENCE_ZVAL: u32 = 10 | (1 << 8);
    let mut out = Zval::new();
    // SAFETY: the reference is PHP's own layout, allocated by its allocator and freed with the zval.
    unsafe {
        let reference = ext_php_rs::ffi::_emalloc(std::mem::size_of::<ext_php_rs::ffi::zend_reference>())
            .cast::<ext_php_rs::ffi::zend_reference>();
        (*reference).gc.refcount = 1;
        (*reference).gc.u.type_info = REFERENCE;
        std::ptr::write(&raw mut (*reference).val, value);
        (*reference).sources.ptr = std::ptr::null_mut();
        out.value.ref_ = reference;
        out.u1.type_info = REFERENCE_ZVAL;
    }
    out
}

/// A number of a list of floats.
fn number(value: &Value) -> f64 {
    match value {
        Value::Float(number) => *number,
        #[allow(clippy::cast_precision_loss)]
        Value::Int(number) => *number as f64,
        _ => 0.0,
    }
}

/// MongoDB's `hint()`: a key pattern, or the name of an index.
fn hinted(hint: Value) -> IndexHint {
    match hint {
        Value::Array(keys) => IndexHint::Keys(keys),
        Value::Str(name) => IndexHint::Name(String::from_utf8_lossy(name.as_bytes()).into_owned()),
        _ => IndexHint::Name(String::new()),
    }
}

/// A `Hook` argument.
pub struct HookArgument<'a>(&'a Zval);

impl<'a> FromZval<'a> for HookArgument<'a> {
    const TYPE: DataType = DataType::object(HOOK);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let hook = ClassEntry::try_find(HOOK)?;
        zval.object().filter(|object| object.instance_of(hook)).map(|_| HookArgument(zval))
    }
}

/// A PHP hook a builder runs while it compiles.
struct Hooked {
    object: Zval,
    json: bool,
}

// SAFETY: a PHP request runs on one thread, and the builder that holds this never leaves it.
unsafe impl Send for Hooked {}
// SAFETY: as above.
unsafe impl Sync for Hooked {}

impl Hooked {
    fn text(&self, bytes: &[u8]) -> String {
        if self.json { String::from_utf8_lossy(bytes).into_owned() } else { wide(bytes).into_owned() }
    }

    /// Calls a method of the hook. An exception stays pending for the native method that
    /// started the build to rethrow.
    fn call(&self, method: &str, arguments: Vec<&dyn IntoZvalDyn>) -> Result<Zval, Error> {
        let failed = || Error::Validation(format!("{method}() failed"));
        let called = self.object.object().ok_or_else(failed)?.try_call_method(method, arguments);
        if !ExecutorGlobals::get().exception.is_null() {
            return Err(failed());
        }
        called.map_err(|_| failed())
    }
}

impl hook::Filter for Hooked {
    fn filter(&self, table: &str) -> Result<Condition, Error> {
        let table = string(&bytes(self.json, table));
        let returned = self.call("filter", vec![&table])?;
        condition(self.json, &returned).ok_or_else(|| Error::Validation("filter() must return a Condition".to_owned()))
    }
}

impl hook::Attribute for Hooked {
    fn resolve(&self, attribute: &str) -> Result<String, Error> {
        let attribute = string(&bytes(self.json, attribute));
        let returned = self.call("resolve", vec![&attribute])?;
        Ok(returned.zend_str().map(|resolved| self.text(resolved.as_bytes())).unwrap_or_default())
    }
}

impl hook::JoinFilter for Hooked {
    fn filter_join(&self, table: &str, join_type: JoinType) -> Result<Option<hook::JoinCondition>, Error> {
        let failed = || Error::Validation("filterJoin() failed".to_owned());
        let table = string(&bytes(self.json, table));
        let kind = enum_case(JoinType::PHP_CLASS, join_type.name()).map_err(|_| failed())?;
        let returned = self.call("filterJoin", vec![&table, &kind])?;
        if returned.is_null() {
            return Ok(None);
        }
        let fields = properties(&returned).ok_or_else(failed)?;
        let found = property(fields, "condition").and_then(|found| condition(self.json, found)).ok_or_else(failed)?;
        // `Placement` has no backing value: a case object keeps its name in its first slot.
        let placement =
            property(fields, "placement").and_then(Zval::object).and_then(|case| slots(case).first()?.str());
        let placement = if placement == Some("On") { hook::Placement::On } else { hook::Placement::Where };
        Ok(Some(hook::JoinCondition::new(found, placement)))
    }
}

/// A `WindowFrame` argument.
pub struct Frame(WindowFrame);

impl<'a> FromZval<'a> for Frame {
    const TYPE: DataType = DataType::object(WINDOW_FRAME);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let frame = ClassEntry::try_find(WINDOW_FRAME)?;
        let fields = zval.object().filter(|object| object.instance_of(frame))?.get_properties().ok()?;
        let text = |name: &str| property(fields, name)?.zend_str().map(|found| wide(found.as_bytes()).into_owned());
        Some(Frame(WindowFrame::new(text("type")?, text("start")?, text("end"))))
    }
}

/// A `Closure` argument.
pub struct Callback<'a>(&'a Zval);

impl<'a> FromZval<'a> for Callback<'a> {
    const TYPE: DataType = DataType::object("Closure");

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let closure = ClassEntry::try_find("Closure")?;
        zval.object().filter(|object| object.instance_of(closure)).map(|_| Callback(zval))
    }
}

/// A PHP closure a builder keeps.
struct Held(Zval);

// SAFETY: a PHP request runs on one thread, and the builder that holds this never leaves it.
unsafe impl Send for Held {}
// SAFETY: as above.
unsafe impl Sync for Held {}

impl Held {
    /// Calls the closure. An exception stays pending for the native method that started
    /// the build to rethrow.
    fn call(&self, argument: &Zval, what: &str) -> Result<Zval, Error> {
        let failed = || Error::Validation(format!("{what}() failed"));
        let called = ZendCallable::new(&self.0).map_err(|_| failed())?.try_call(vec![argument]);
        if !ExecutorGlobals::get().exception.is_null() {
            return Err(failed());
        }
        called.map_err(|_| failed())
    }
}

/// The PHP object that owns a builder, as `$this`.
fn this(owner: usize) -> Option<Zval> {
    // SAFETY: `running()` set the owner to the live PHP object that holds the builder.
    let object = unsafe { (owner as *mut ZendObject).as_mut() }?;
    let mut this = Zval::new();
    this.set_object(object);
    Some(this)
}

/// A `beforeBuild()` callback: `$callback($this)`, with the builder lent to it.
fn before<C: Native>(builder: &mut Builder<C::Dialect>, callback: &Held) -> Result<(), Error> {
    let Some(this) = this(builder.owner()) else { return Ok(()) };
    let Some(state) = this.object().and_then(ZendClassObject::<C>::from_zend_obj).map(|object| object.state()) else {
        return Ok(());
    };
    let before = state.lent.replace(std::ptr::from_mut(builder));
    let called = callback.call(&this, "beforeBuild");
    state.lent.set(before);
    called.map(drop)
}

/// An `afterBuild()` callback: `$callback($statement)`, whose statement is the one built.
fn after<C: Native>(owner: usize, statement: Statement, callback: &Held) -> Result<Statement, Error> {
    let failed = || Error::Validation("afterBuild() failed".to_owned());
    let Some(this) = this(owner) else { return Ok(statement) };
    let Some(state) = this.object().and_then(ZendClassObject::<C>::from_zend_obj).map(|object| object.state()) else {
        return Ok(statement);
    };
    // A callback before this one may have returned a statement of its own.
    let given = match state.returned.take() {
        Some((object, query)) if query == statement.query => object,
        _ => state.statement_object(&statement).map_err(|_| failed())?,
    };
    let returned = callback.call(&given, "afterBuild")?;
    let fields = returned.object().and_then(|object| object.get_properties().ok()).ok_or_else(failed)?;
    let property = |name: &str| fields.get(name).map(|found| found.indirect().unwrap_or(found));
    let query = property("query").and_then(Zval::zend_str).map(|query| state.text(query.as_bytes()));
    let bindings = property("bindings").and_then(Zval::array).into_iter().flat_map(ZendHashTable::values);
    let mut built = Statement::new(query.unwrap_or_default(), bindings.map(bound).collect());
    built.read_only = property("readOnly").and_then(Zval::bool).unwrap_or(false);
    *state.returned.borrow_mut() = Some((returned, built.query.clone()));
    Ok(built)
}

/// A `NullsPosition` argument.
pub struct Nulls(NullsPosition);

impl<'a> FromZval<'a> for Nulls {
    const TYPE: DataType = DataType::object(NullsPosition::PHP_CLASS);

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        let object = zval
            .object()
            .filter(|object| class(NullsPosition::PHP_CLASS).is_ok_and(|class| object.instance_of(class)))?;
        slots(object).get(1)?.str().and_then(NullsPosition::try_from_value).map(Nulls)
    }
}

/// `select()`'s `array|string $columns`: a list of columns, or one raw expression.
pub struct Columns<'a>(&'a Zval);

impl<'a> FromZval<'a> for Columns<'a> {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        (zval.is_array() || zval.is_string()).then_some(Columns(zval))
    }
}

/// A builder class: its state, and the methods every dialect has. Every class declares its
/// own, since a parent's native method would read its own Rust type.
macro_rules! builder_class {
    ($(#[$meta:meta])* $class:ident, $dialect:ty, [$($feature:ident),*]) => {
        builder_class!(@add [$(#[$meta])*] $class, $dialect, {}, $($feature,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, sql, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "compileSearchExpression", vis = "protected")]
            pub fn compile_search_expression(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
                not: bool,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (attribute, values) = (self_.0.text(attribute.bytes()), self_.0.values(&values)?);
                self_.0.step(Step::CompileSearchExpression, &[Arg::Str(&attribute), Arg::Values(&values), Arg::Bool(not)])
            }

            #[php(name = "compileJsonContainsExpression", vis = "protected")]
            pub fn compile_json_contains_expression(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
                not: bool,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (attribute, values) = (self_.0.text(attribute.bytes()), self_.0.values(&values)?);
                self_.0.step(Step::CompileJsonContainsExpression, &[Arg::Str(&attribute), Arg::Values(&values), Arg::Bool(not)])
            }

            #[php(name = "compileJsonOverlapsExpression", vis = "protected")]
            pub fn compile_json_overlaps_expression(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (attribute, values) = (self_.0.text(attribute.bytes()), self_.0.values(&values)?);
                self_.0.step(Step::CompileJsonOverlapsExpression, &[Arg::Str(&attribute), Arg::Values(&values)])
            }

            #[php(name = "compileSpatialFilter", vis = "protected")]
            pub fn compile_spatial_filter(
                self_: &mut ZendClassObject<$class>,
                method: MethodArgument,
                attribute: PhpString<'_>,
                query: QueryArgument<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (attribute, query) = (self_.0.text(attribute.bytes()), self_.0.query(query.0)?);
                self_.0.step(Step::CompileSpatialFilter, &[Arg::Method(method.0), Arg::Str(&attribute), Arg::Query(&query)])
            }

            #[php(name = "compileArrayFilter", vis = "protected")]
            pub fn compile_array_filter(
                self_: &mut ZendClassObject<$class>,
                method: MethodArgument,
                attribute: PhpString<'_>,
                query: QueryArgument<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (attribute, query) = (self_.0.text(attribute.bytes()), self_.0.query(query.0)?);
                self_.0.step(Step::CompileArrayFilter, &[Arg::Method(method.0), Arg::Str(&attribute), Arg::Query(&query)])
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, spatial, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "geometryToWkt", vis = "protected")]
            pub fn geometry_to_wkt(&self, geometry: Arr<'_>) -> PhpResult<Bytes> {
                let geometry = self.0.values(&geometry)?;
                let wkt = utopia_query::builder::geometry_to_wkt(&geometry).map_err(|error| raised(&error))?;
                Ok(Bytes(wkt.into_bytes()))
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, literal, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "quoteLiteral", vis = "protected")]
            pub fn quote_literal(&self, identifier: PhpString<'_>) -> PhpResult<Bytes> {
                let identifier = self.0.text(identifier.bytes());
                let quoted = utopia_query::quote_literal(&identifier, <$dialect as Dialect>::WRAP);
                Ok(Bytes(self.0.written(&quoted.map_err(|error| raised(&error))?)?.into_owned()))
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, raw_sql, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "selectRaw", defaults(bindings = "Arr::Empty"))]
            pub fn select_raw<'a>(
                self_: &'a mut ZendClassObject<$class>,
                expression: PhpString<'_>,
                bindings: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let expression = self_.0.text(expression.bytes());
                let bound: Vec<Value> = self_.0.values(&bindings)?.values().cloned().collect();
                self_.0.with(|builder| {
                    builder.select_raw(expression, bound);
                });
                Ok(self_)
            }

            #[php(name = "whereRaw", defaults(bindings = "Arr::Empty"))]
            pub fn where_raw<'a>(
                self_: &'a mut ZendClassObject<$class>,
                expression: PhpString<'_>,
                bindings: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let expression = self_.0.text(expression.bytes());
                let bound: Vec<Value> = self_.0.values(&bindings)?.values().cloned().collect();
                self_.0.with(|builder| {
                    builder.where_raw(expression, bound);
                });
                Ok(self_)
            }

            #[php(name = "orderByRaw", defaults(bindings = "Arr::Empty"))]
            pub fn order_by_raw<'a>(
                self_: &'a mut ZendClassObject<$class>,
                expression: PhpString<'_>,
                bindings: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let expression = self_.0.text(expression.bytes());
                let bound: Vec<Value> = self_.0.values(&bindings)?.values().cloned().collect();
                self_.0.with(|builder| {
                    builder.order_by_raw(expression, bound);
                });
                Ok(self_)
            }

            #[php(name = "groupByRaw", defaults(bindings = "Arr::Empty"))]
            pub fn group_by_raw<'a>(
                self_: &'a mut ZendClassObject<$class>,
                expression: PhpString<'_>,
                bindings: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let expression = self_.0.text(expression.bytes());
                let bound: Vec<Value> = self_.0.values(&bindings)?.values().cloned().collect();
                self_.0.with(|builder| {
                    builder.group_by_raw(expression, bound);
                });
                Ok(self_)
            }

            #[php(name = "havingRaw", defaults(bindings = "Arr::Empty"))]
            pub fn having_raw<'a>(
                self_: &'a mut ZendClassObject<$class>,
                expression: PhpString<'_>,
                bindings: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let expression = self_.0.text(expression.bytes());
                let bound: Vec<Value> = self_.0.values(&bindings)?.values().cloned().collect();
                self_.0.with(|builder| {
                    builder.having_raw(expression, bound);
                });
                Ok(self_)
            }

            #[php(name = "setRaw", defaults(bindings = "Arr::Empty"))]
            pub fn set_raw<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                expression: PhpString<'_>,
                bindings: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let (column, expression) = (self_.0.text(column.bytes()), self_.0.text(expression.bytes()));
                let bound: Vec<Value> = self_.0.values(&bindings)?.values().cloned().collect();
                self_.0.with(|builder| {
                    builder.set_raw(column, expression, bound);
                });
                Ok(self_)
            }

            #[php(name = "conflictSetRaw", defaults(bindings = "Arr::Empty"))]
            pub fn conflict_set_raw<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                expression: PhpString<'_>,
                bindings: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let (column, expression) = (self_.0.text(column.bytes()), self_.0.text(expression.bytes()));
                let bound: Vec<Value> = self_.0.values(&bindings)?.values().cloned().collect();
                self_.0.with(|builder| {
                    builder.conflict_set_raw(column, expression, bound);
                });
                Ok(self_)
            }

            #[php(name = "insertColumnExpression", defaults(extraBindings = "Arr::Empty"))]
            pub fn insert_column_expression<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                expression: PhpString<'_>,
                extraBindings: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let (column, expression) = (self_.0.text(column.bytes()), self_.0.text(expression.bytes()));
                let bound: Vec<Value> = self_.0.values(&extraBindings)?.values().cloned().collect();
                self_.0.with(|builder| {
                    builder.insert_column_expression(column, expression, bound);
                });
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, locking, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "forUpdate")]
            pub fn for_update(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.for_update();
                });
                self_
            }

            #[php(name = "forShare")]
            pub fn for_share(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.for_share();
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, upsert, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "upsert")]
            pub fn upsert(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let built = self_.0.with(Builder::upsert);
                self_.0.statement(built)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, upsert_select, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "upsertSelect")]
            pub fn upsert_select(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let built = self_.0.with(Builder::upsert_select);
                self_.0.statement(built)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, insert_or_ignore, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "insertOrIgnore")]
            pub fn insert_or_ignore(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let built = self_.0.with(Builder::insert_or_ignore);
                self_.0.statement(built)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, returning, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(defaults(columns = "Returned::Star"))]
            pub fn returning<'a>(
                self_: &'a mut ZendClassObject<$class>,
                columns: Returned<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let columns = match columns {
                    Returned::Given(columns) => self_.0.strings(&Arr::Given(columns)),
                    Returned::Star => vec!["*".to_owned()],
                };
                self_.0.with(|builder| {
                    builder.returning(columns);
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, mysql_family, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "updateJoin", defaults(alias = "\"\""))]
            pub fn update_join<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
                left: PhpString<'_>,
                right: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let state = &self_.0;
                let (table, alias) = (state.text(table.bytes()), state.text(alias.bytes()));
                let (left, right) = (state.text(left.bytes()), state.text(right.bytes()));
                state.with(|builder| {
                    builder.update_join(table, left, right, alias);
                });
                self_
            }

            #[php(name = "deleteJoin")]
            pub fn delete_join<'a>(
                self_: &'a mut ZendClassObject<$class>,
                alias: PhpString<'_>,
                table: PhpString<'_>,
                left: PhpString<'_>,
                right: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let state = &self_.0;
                let (table, alias) = (state.text(table.bytes()), state.text(alias.bytes()));
                let (left, right) = (state.text(left.bytes()), state.text(right.bytes()));
                state.with(|builder| {
                    builder.delete_join(alias, table, left, right);
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, postgres_from, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "updateFrom", defaults(alias = "\"\""))]
            pub fn update_from<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (table, alias) = (self_.0.text(table.bytes()), self_.0.text(alias.bytes()));
                self_.0.with(|builder| {
                    builder.update_from(table, alias);
                });
                self_
            }

            #[php(name = "deleteUsing")]
            pub fn delete_using<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
                condition: PhpString<'_>,
                bindings: &[&Zval],
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let (table, condition) = (self_.0.text(table.bytes()), self_.0.text(condition.bytes()));
                let bound = bindings.iter().map(|binding| self_.0.value(binding)).collect::<PhpResult<Vec<Value>>>()?;
                self_.0.with(|builder| {
                    builder.delete_using(table, condition, bound);
                });
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, explain_plain, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(defaults(analyze = "Bool(false)"))]
            pub fn explain(self_: &mut ZendClassObject<$class>, analyze: Bool) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let built = self_.0.with(|builder| builder.explain(analyze.0));
                self_.0.statement(built)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, explain_format, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(defaults(analyze = "Bool(false)", format = "\"\""))]
            pub fn explain(self_: &mut ZendClassObject<$class>, analyze: Bool, format: PhpString<'_>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let options = ExplainOptions { format: self_.0.text(format.bytes()), ..ExplainOptions::default() };
                let built = self_.0.with(|builder| builder.explain_with(analyze.0, &options));
                self_.0.statement(built)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, explain_postgres, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(defaults(analyze = "Bool(false)", verbose = "Bool(false)", buffers = "Bool(false)", format = "\"\""))]
            pub fn explain(self_: &mut ZendClassObject<$class>, analyze: Bool, verbose: Bool, buffers: Bool, format: PhpString<'_>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let options = ExplainOptions { verbose: verbose.0, buffers: buffers.0, format: self_.0.text(format.bytes()) };
                let built = self_.0.with(|builder| builder.explain_with(analyze.0, &options));
                self_.0.statement(built)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, cross_joins, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "crossJoin", defaults(alias = "\"\""))]
            pub fn cross_join<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let table = self_.0.text(table.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.cross_join(table, alias);
                });
                self_
            }

            #[php(name = "naturalJoin", defaults(alias = "\"\""))]
            pub fn natural_join<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let table = self_.0.text(table.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.natural_join(table, alias);
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, full_outer_joins, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "fullOuterJoin", defaults(operator = "\"=\"", alias = "\"\""))]
            pub fn full_outer_join<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
                left: PhpString<'_>,
                right: PhpString<'_>,
                operator: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let table = self_.0.text(table.bytes());
                let left = self_.0.text(left.bytes());
                let right = self_.0.text(right.bytes());
                let operator = self_.0.text(operator.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.full_outer_join(table, &left, &right, &operator, alias);
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, locking_modes, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "forUpdateSkipLocked")]
            pub fn for_update_skip_locked(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.for_update_skip_locked();
                });
                self_
            }

            #[php(name = "forUpdateNoWait")]
            pub fn for_update_no_wait(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.for_update_no_wait();
                });
                self_
            }

            #[php(name = "forShareSkipLocked")]
            pub fn for_share_skip_locked(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.for_share_skip_locked();
                });
                self_
            }

            #[php(name = "forShareNoWait")]
            pub fn for_share_no_wait(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.for_share_no_wait();
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, statistical, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(defaults(alias = "\"\""))]
            pub fn stddev<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.stddev(attribute, alias);
                });
                self_
            }

            #[php(name = "stddevPop", defaults(alias = "\"\""))]
            pub fn stddev_pop<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.stddev_pop(attribute, alias);
                });
                self_
            }

            #[php(name = "stddevSamp", defaults(alias = "\"\""))]
            pub fn stddev_samp<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.stddev_samp(attribute, alias);
                });
                self_
            }

            #[php(defaults(alias = "\"\""))]
            pub fn variance<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.variance(attribute, alias);
                });
                self_
            }

            #[php(name = "varPop", defaults(alias = "\"\""))]
            pub fn var_pop<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.var_pop(attribute, alias);
                });
                self_
            }

            #[php(name = "varSamp", defaults(alias = "\"\""))]
            pub fn var_samp<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.var_samp(attribute, alias);
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, statistical_clickhouse, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(defaults(alias = "\"\""))]
            pub fn stddev<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.stddev(&attribute, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "stddevPop", defaults(alias = "\"\""))]
            pub fn stddev_pop<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.stddev_pop(attribute, alias);
                });
                self_
            }

            #[php(name = "stddevSamp", defaults(alias = "\"\""))]
            pub fn stddev_samp<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.stddev_samp(attribute, alias);
                });
                self_
            }

            #[php(defaults(alias = "\"\""))]
            pub fn variance<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.variance(&attribute, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "varPop", defaults(alias = "\"\""))]
            pub fn var_pop<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.var_pop(attribute, alias);
                });
                self_
            }

            #[php(name = "varSamp", defaults(alias = "\"\""))]
            pub fn var_samp<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.var_samp(attribute, alias);
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, bitwise, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "bitAnd", defaults(alias = "\"\""))]
            pub fn bit_and<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.bit_and(attribute, alias);
                });
                self_
            }

            #[php(name = "bitOr", defaults(alias = "\"\""))]
            pub fn bit_or<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.bit_or(attribute, alias);
                });
                self_
            }

            #[php(name = "bitXor", defaults(alias = "\"\""))]
            pub fn bit_xor<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.bit_xor(attribute, alias);
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, raw_sql_more, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "selectCast", defaults(alias = "\"\""))]
            pub fn select_cast<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                r#type: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let r#type = self_.0.text(r#type.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.select_cast(&column, &r#type, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "whereColumn")]
            pub fn where_column<'a>(
                self_: &'a mut ZendClassObject<$class>,
                left: PhpString<'_>,
                operator: PhpString<'_>,
                right: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let left = self_.0.text(left.bytes());
                let operator = self_.0.text(operator.bytes());
                let right = self_.0.text(right.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.where_column(&left, &operator, &right).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, rollup, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "withRollup")]
            pub fn with_rollup(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.with_rollup();
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, cube, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "withCube")]
            pub fn with_cube(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.with_cube();
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, totals, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "withTotals")]
            pub fn with_totals(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.with_totals();
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, hints, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            pub fn hint<'a>(
                self_: &'a mut ZendClassObject<$class>,
                hint: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let hint = self_.0.text(hint.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.hint(&hint).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, conditional_aggregates, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "countWhen", defaults(alias = "\"\""), optional = bindings)]
            pub fn count_when<'a>(
                self_: &'a mut ZendClassObject<$class>,
                condition: PhpString<'_>,
                alias: PhpString<'_>,
                bindings: &[&Zval],
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let condition = self_.0.text(condition.bytes());
                let alias = self_.0.text(alias.bytes());
                let bindings = bindings.iter().map(|value| self_.0.value(value)).collect::<PhpResult<Vec<Value>>>()?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.count_when(&condition, &alias, bindings).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "sumWhen", defaults(alias = "\"\""), optional = bindings)]
            pub fn sum_when<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                condition: PhpString<'_>,
                alias: PhpString<'_>,
                bindings: &[&Zval],
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let condition = self_.0.text(condition.bytes());
                let alias = self_.0.text(alias.bytes());
                let bindings = bindings.iter().map(|value| self_.0.value(value)).collect::<PhpResult<Vec<Value>>>()?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.sum_when(&column, &condition, &alias, bindings).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "avgWhen", defaults(alias = "\"\""), optional = bindings)]
            pub fn avg_when<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                condition: PhpString<'_>,
                alias: PhpString<'_>,
                bindings: &[&Zval],
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let condition = self_.0.text(condition.bytes());
                let alias = self_.0.text(alias.bytes());
                let bindings = bindings.iter().map(|value| self_.0.value(value)).collect::<PhpResult<Vec<Value>>>()?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.avg_when(&column, &condition, &alias, bindings).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "minWhen", defaults(alias = "\"\""), optional = bindings)]
            pub fn min_when<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                condition: PhpString<'_>,
                alias: PhpString<'_>,
                bindings: &[&Zval],
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let condition = self_.0.text(condition.bytes());
                let alias = self_.0.text(alias.bytes());
                let bindings = bindings.iter().map(|value| self_.0.value(value)).collect::<PhpResult<Vec<Value>>>()?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.min_when(&column, &condition, &alias, bindings).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "maxWhen", defaults(alias = "\"\""), optional = bindings)]
            pub fn max_when<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                condition: PhpString<'_>,
                alias: PhpString<'_>,
                bindings: &[&Zval],
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let condition = self_.0.text(condition.bytes());
                let alias = self_.0.text(alias.bytes());
                let bindings = bindings.iter().map(|value| self_.0.value(value)).collect::<PhpResult<Vec<Value>>>()?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.max_when(&column, &condition, &alias, bindings).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, json_aggregates, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "jsonArrayAgg", defaults(alias = "\"\""))]
            pub fn json_array_agg<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.json_array_agg(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "jsonObjectAgg", defaults(alias = "\"\""))]
            pub fn json_object_agg<'a>(
                self_: &'a mut ZendClassObject<$class>,
                keyColumn: PhpString<'_>,
                valueColumn: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let keyColumn = self_.0.text(keyColumn.bytes());
                let valueColumn = self_.0.text(valueColumn.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.json_object_agg(&keyColumn, &valueColumn, &alias).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, json, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "filterJsonContains")]
            pub fn filter_json_contains<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let value = self_.0.value(value)?;
                self_.0.with(|builder| {
                    builder.filter_json_contains(attribute, value);
                });
                Ok(self_)
            }

            #[php(name = "filterJsonNotContains")]
            pub fn filter_json_not_contains<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let value = self_.0.value(value)?;
                self_.0.with(|builder| {
                    builder.filter_json_not_contains(attribute, value);
                });
                Ok(self_)
            }

            #[php(name = "filterJsonOverlaps")]
            pub fn filter_json_overlaps<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let values = self_.0.values(&values)?;
                self_.0.with(|builder| {
                    builder.filter_json_overlaps(attribute, values);
                });
                Ok(self_)
            }

            #[php(name = "filterJsonPath")]
            pub fn filter_json_path<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                path: PhpString<'_>,
                operator: PhpString<'_>,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let path = self_.0.text(path.bytes());
                let operator = self_.0.text(operator.bytes());
                let value = self_.0.value(value)?;
                self_.0.with(|builder| {
                    builder.filter_json_path(attribute, &path, &operator, value);
                });
                Ok(self_)
            }

            #[php(name = "setJsonAppend")]
            pub fn set_json_append<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                values: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let values = self_.0.values(&values)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.set_json_append(&column, values).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "setJsonPrepend")]
            pub fn set_json_prepend<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                values: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let values = self_.0.values(&values)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.set_json_prepend(&column, values).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "setJsonInsert")]
            pub fn set_json_insert<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                index: Int,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let index = index.0;
                let value = self_.0.value(value)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.set_json_insert(&column, index, value).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "setJsonRemove")]
            pub fn set_json_remove<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let value = self_.0.value(value)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.set_json_remove(&column, value).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "setJsonIntersect")]
            pub fn set_json_intersect<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                values: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let values = self_.0.values(&values)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.set_json_intersect(&column, values).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "setJsonDiff")]
            pub fn set_json_diff<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                values: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let values = self_.0.values(&values)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.set_json_diff(&column, values).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "setJsonUnique")]
            pub fn set_json_unique<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.set_json_unique(&column).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "setJsonPath")]
            pub fn set_json_path<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                path: PhpString<'_>,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let path = self_.0.text(path.bytes());
                let value = self_.0.value(value)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.set_json_path(&column, &path, value).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, search, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "filterSearch")]
            pub fn filter_search<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                value: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let value = self_.0.text(value.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.filter_search(&attribute, &value).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, not_search, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "filterNotSearch")]
            pub fn filter_not_search<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                value: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                let value = self_.0.text(value.bytes());
                self_.0.with(|builder| {
                    builder.filter_not_search(&attribute, &value);
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, tablesample, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(defaults(method = "\"BERNOULLI\""))]
            pub fn tablesample<'a>(
                self_: &'a mut ZendClassObject<$class>,
                percent: Float,
                method: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let percent = percent.0;
                let method = self_.0.text(method.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.tablesample(percent, &method).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, sequences, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "nextVal", defaults(alias = "\"\""))]
            pub fn next_val<'a>(
                self_: &'a mut ZendClassObject<$class>,
                sequence: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let sequence = self_.0.text(sequence.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.next_val(&sequence, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "currVal", defaults(alias = "\"\""))]
            pub fn curr_val<'a>(
                self_: &'a mut ZendClassObject<$class>,
                sequence: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let sequence = self_.0.text(sequence.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.curr_val(&sequence, &alias).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, spatial_filters, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "filterDistance", defaults(meters = "Bool(false)"))]
            pub fn filter_distance<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                point: Arr<'_>,
                operator: PhpString<'_>,
                distance: Float,
                meters: Bool,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let point = self_.0.values(&point)?;
                let operator = self_.0.text(operator.bytes());
                let distance = distance.0;
                let meters = meters.0;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.filter_distance(attribute, &point, &operator, distance, meters).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "filterIntersects")]
            pub fn filter_intersects<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                geometry: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let geometry = self_.0.values(&geometry)?;
                self_.0.with(|builder| {
                    builder.filter_intersects(attribute, geometry);
                });
                Ok(self_)
            }

            #[php(name = "filterNotIntersects")]
            pub fn filter_not_intersects<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                geometry: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let geometry = self_.0.values(&geometry)?;
                self_.0.with(|builder| {
                    builder.filter_not_intersects(attribute, geometry);
                });
                Ok(self_)
            }

            #[php(name = "filterCrosses")]
            pub fn filter_crosses<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                geometry: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let geometry = self_.0.values(&geometry)?;
                self_.0.with(|builder| {
                    builder.filter_crosses(attribute, geometry);
                });
                Ok(self_)
            }

            #[php(name = "filterNotCrosses")]
            pub fn filter_not_crosses<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                geometry: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let geometry = self_.0.values(&geometry)?;
                self_.0.with(|builder| {
                    builder.filter_not_crosses(attribute, geometry);
                });
                Ok(self_)
            }

            #[php(name = "filterOverlaps")]
            pub fn filter_overlaps<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                geometry: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let geometry = self_.0.values(&geometry)?;
                self_.0.with(|builder| {
                    builder.filter_overlaps(attribute, geometry);
                });
                Ok(self_)
            }

            #[php(name = "filterNotOverlaps")]
            pub fn filter_not_overlaps<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                geometry: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let geometry = self_.0.values(&geometry)?;
                self_.0.with(|builder| {
                    builder.filter_not_overlaps(attribute, geometry);
                });
                Ok(self_)
            }

            #[php(name = "filterTouches")]
            pub fn filter_touches<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                geometry: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let geometry = self_.0.values(&geometry)?;
                self_.0.with(|builder| {
                    builder.filter_touches(attribute, geometry);
                });
                Ok(self_)
            }

            #[php(name = "filterNotTouches")]
            pub fn filter_not_touches<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                geometry: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let geometry = self_.0.values(&geometry)?;
                self_.0.with(|builder| {
                    builder.filter_not_touches(attribute, geometry);
                });
                Ok(self_)
            }

            #[php(name = "filterCovers")]
            pub fn filter_covers<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                geometry: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let geometry = self_.0.values(&geometry)?;
                self_.0.with(|builder| {
                    builder.filter_covers(attribute, geometry);
                });
                Ok(self_)
            }

            #[php(name = "filterNotCovers")]
            pub fn filter_not_covers<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                geometry: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let geometry = self_.0.values(&geometry)?;
                self_.0.with(|builder| {
                    builder.filter_not_covers(attribute, geometry);
                });
                Ok(self_)
            }

            #[php(name = "filterSpatialEquals")]
            pub fn filter_spatial_equals<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                geometry: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let geometry = self_.0.values(&geometry)?;
                self_.0.with(|builder| {
                    builder.filter_spatial_equals(attribute, geometry);
                });
                Ok(self_)
            }

            #[php(name = "filterNotSpatialEquals")]
            pub fn filter_not_spatial_equals<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                geometry: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let geometry = self_.0.values(&geometry)?;
                self_.0.with(|builder| {
                    builder.filter_not_spatial_equals(attribute, geometry);
                });
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, transactions, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            pub fn begin(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Zval> {
                let built = self_.0.with(|builder| Ok(builder.begin()));
                self_.0.statement(built)
            }

            pub fn commit(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Zval> {
                let built = self_.0.with(|builder| Ok(builder.commit()));
                self_.0.statement(built)
            }

            pub fn rollback(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Zval> {
                let built = self_.0.with(|builder| Ok(builder.rollback()));
                self_.0.statement(built)
            }

            pub fn savepoint(
                self_: &mut ZendClassObject<$class>,
                name: PhpString<'_>,
            ) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let name = self_.0.text(name.bytes());
                let built = self_.0.with(|builder| builder.savepoint(&name));
                self_.0.statement(built)
            }

            #[php(name = "releaseSavepoint")]
            pub fn release_savepoint(
                self_: &mut ZendClassObject<$class>,
                name: PhpString<'_>,
            ) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let name = self_.0.text(name.bytes());
                let built = self_.0.with(|builder| builder.release_savepoint(&name));
                self_.0.statement(built)
            }

            #[php(name = "rollbackToSavepoint")]
            pub fn rollback_to_savepoint(
                self_: &mut ZendClassObject<$class>,
                name: PhpString<'_>,
            ) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let name = self_.0.text(name.bytes());
                let built = self_.0.with(|builder| builder.rollback_to_savepoint(&name));
                self_.0.statement(built)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, postgres_more, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "updateFromWhere")]
            pub fn update_from_where<'a>(
                self_: &'a mut ZendClassObject<$class>,
                condition: PhpString<'_>,
                bindings: &[&Zval],
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let condition = self_.0.text(condition.bytes());
                let bindings = bindings.iter().map(|value| self_.0.value(value)).collect::<PhpResult<Vec<Value>>>()?;
                self_.0.with(|builder| {
                    builder.update_from_where(condition, bindings);
                });
                Ok(self_)
            }

            #[php(name = "distinctOn")]
            pub fn distinct_on<'a>(
                self_: &'a mut ZendClassObject<$class>,
                columns: Arr<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let columns = self_.0.strings(&columns);
                self_.0.with(|builder| {
                    builder.distinct_on(columns);
                });
                self_
            }

            #[php(name = "forUpdateOf")]
            pub fn for_update_of<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let table = self_.0.text(table.bytes());
                self_.0.with(|builder| {
                    builder.for_update_of(table);
                });
                self_
            }

            #[php(name = "forShareOf")]
            pub fn for_share_of<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let table = self_.0.text(table.bytes());
                self_.0.with(|builder| {
                    builder.for_share_of(table);
                });
                self_
            }

            #[php(name = "selectAggregateFilter", defaults(alias = "\"\"", bindings = "Arr::Empty"))]
            pub fn select_aggregate_filter<'a>(
                self_: &'a mut ZendClassObject<$class>,
                aggregateExpr: PhpString<'_>,
                filterCondition: PhpString<'_>,
                alias: PhpString<'_>,
                bindings: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let aggregateExpr = self_.0.text(aggregateExpr.bytes());
                let filterCondition = self_.0.text(filterCondition.bytes());
                let alias = self_.0.text(alias.bytes());
                let bindings: Vec<Value> = self_.0.values(&bindings)?.values().cloned().collect();
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.select_aggregate_filter(&aggregateExpr, &filterCondition, &alias, bindings).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "arrayAgg", defaults(alias = "\"\""))]
            pub fn array_agg<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.array_agg(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "boolAnd", defaults(alias = "\"\""))]
            pub fn bool_and<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.bool_and(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "boolOr", defaults(alias = "\"\""))]
            pub fn bool_or<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.bool_or(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(defaults(alias = "\"\""))]
            pub fn every_<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.every(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(defaults(alias = "\"\""))]
            pub fn mode_<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.mode(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "percentileCont", defaults(alias = "\"\""))]
            pub fn percentile_cont<'a>(
                self_: &'a mut ZendClassObject<$class>,
                fraction: Float,
                orderColumn: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let fraction = fraction.0;
                let orderColumn = self_.0.text(orderColumn.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.percentile_cont(fraction, &orderColumn, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "percentileDisc", defaults(alias = "\"\""))]
            pub fn percentile_disc<'a>(
                self_: &'a mut ZendClassObject<$class>,
                fraction: Float,
                orderColumn: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let fraction = fraction.0;
                let orderColumn = self_.0.text(orderColumn.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.percentile_disc(fraction, &orderColumn, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "mergeInto")]
            pub fn merge_into<'a>(
                self_: &'a mut ZendClassObject<$class>,
                target: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let target = self_.0.text(target.bytes());
                self_.0.with(|builder| {
                    builder.merge_into(target);
                });
                self_
            }

            pub fn on_<'a>(
                self_: &'a mut ZendClassObject<$class>,
                condition: PhpString<'_>,
                bindings: &[&Zval],
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let condition = self_.0.text(condition.bytes());
                let bindings = bindings.iter().map(|value| self_.0.value(value)).collect::<PhpResult<Vec<Value>>>()?;
                self_.0.with(|builder| {
                    builder.on(condition, bindings);
                });
                Ok(self_)
            }

            #[php(name = "whenMatched")]
            pub fn when_matched<'a>(
                self_: &'a mut ZendClassObject<$class>,
                action: PhpString<'_>,
                bindings: &[&Zval],
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let action = self_.0.text(action.bytes());
                let bindings = bindings.iter().map(|value| self_.0.value(value)).collect::<PhpResult<Vec<Value>>>()?;
                self_.0.with(|builder| {
                    builder.when_matched(action, bindings);
                });
                Ok(self_)
            }

            #[php(name = "whenNotMatched")]
            pub fn when_not_matched<'a>(
                self_: &'a mut ZendClassObject<$class>,
                action: PhpString<'_>,
                bindings: &[&Zval],
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let action = self_.0.text(action.bytes());
                let bindings = bindings.iter().map(|value| self_.0.value(value)).collect::<PhpResult<Vec<Value>>>()?;
                self_.0.with(|builder| {
                    builder.when_not_matched(action, bindings);
                });
                Ok(self_)
            }

            #[php(name = "executeMerge")]
            pub fn execute_merge(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let built = self_.0.with(|builder| builder.execute_merge());
                self_.0.statement(built)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, max_execution_time, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "maxExecutionTime")]
            pub fn max_execution_time<'a>(
                self_: &'a mut ZendClassObject<$class>,
                ms: Int,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let ms = ms.0;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.max_execution_time(ms).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, clickhouse_more, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            pub fn final_(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.final_();
                });
                self_
            }

            pub fn sample<'a>(
                self_: &'a mut ZendClassObject<$class>,
                fraction: Float,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let fraction = fraction.0;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.sample(fraction).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "useNamedBindings", defaults(enabled = "Bool(true)"))]
            pub fn use_named_bindings<'a>(
                self_: &'a mut ZendClassObject<$class>,
                enabled: Bool,
            ) -> &'a mut ZendClassObject<$class> {
                let enabled = enabled.0;
                self_.0.with(|builder| {
                    builder.use_named_bindings(enabled);
                });
                self_
            }

            #[php(name = "withParamType")]
            pub fn with_param_type<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                r#type: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let r#type = self_.0.text(r#type.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.with_param_type(column, &r#type).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "withParamTypes")]
            pub fn with_param_types<'a>(
                self_: &'a mut ZendClassObject<$class>,
                types: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let types = self_.0.pairs(&types);
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.with_param_types(types).map(|_| ()))?;
                Ok(self_)
            }

            pub fn settings<'a>(
                self_: &'a mut ZendClassObject<$class>,
                settings: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let settings = self_.0.pairs(&settings);
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.settings(settings).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "insertFormat", defaults(columns = "Arr::Empty"))]
            pub fn insert_format<'a>(
                self_: &'a mut ZendClassObject<$class>,
                format: PhpString<'_>,
                columns: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let format = self_.0.text(format.bytes());
                let columns = self_.0.strings(&columns);
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.insert_format(&format, columns).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "deleteMode")]
            pub fn delete_mode<'a>(
                self_: &'a mut ZendClassObject<$class>,
                mode: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let mode = self_.0.text(mode.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| (match utopia_query::builder::DeleteMode::parse(&mode) { Ok(mode) => { builder.delete_mode(mode); Ok(()) } Err(error) => Err(error) }).map(|_| ()))?;
                Ok(self_)
            }

            #[php(defaults(alias = "\"\""))]
            pub fn quantile<'a>(
                self_: &'a mut ZendClassObject<$class>,
                level: Float,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let level = level.0;
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.quantile(level, &column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "quantileExact", defaults(alias = "\"\""))]
            pub fn quantile_exact<'a>(
                self_: &'a mut ZendClassObject<$class>,
                level: Float,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let level = level.0;
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.quantile_exact(level, &column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(defaults(alias = "\"\""))]
            pub fn median<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.median(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(defaults(alias = "\"\""))]
            pub fn uniq<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.uniq(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "uniqExact", defaults(alias = "\"\""))]
            pub fn uniq_exact<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.uniq_exact(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "uniqCombined", defaults(alias = "\"\""))]
            pub fn uniq_combined<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.uniq_combined(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "argMin", defaults(alias = "\"\""))]
            pub fn arg_min<'a>(
                self_: &'a mut ZendClassObject<$class>,
                valueColumn: PhpString<'_>,
                argColumn: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let valueColumn = self_.0.text(valueColumn.bytes());
                let argColumn = self_.0.text(argColumn.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.arg_min(&valueColumn, &argColumn, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "argMax", defaults(alias = "\"\""))]
            pub fn arg_max<'a>(
                self_: &'a mut ZendClassObject<$class>,
                valueColumn: PhpString<'_>,
                argColumn: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let valueColumn = self_.0.text(valueColumn.bytes());
                let argColumn = self_.0.text(argColumn.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.arg_max(&valueColumn, &argColumn, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "topK", defaults(alias = "\"\""))]
            pub fn top_k<'a>(
                self_: &'a mut ZendClassObject<$class>,
                k: Int,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let k = k.0;
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.top_k(k, &column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "topKWeighted", defaults(alias = "\"\""))]
            pub fn top_k_weighted<'a>(
                self_: &'a mut ZendClassObject<$class>,
                k: Int,
                column: PhpString<'_>,
                weightColumn: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let k = k.0;
                let column = self_.0.text(column.bytes());
                let weightColumn = self_.0.text(weightColumn.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.top_k_weighted(k, &column, &weightColumn, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "anyValue", defaults(alias = "\"\""))]
            pub fn any_value<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.any_value(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "anyLastValue", defaults(alias = "\"\""))]
            pub fn any_last_value<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.any_last_value(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "groupUniqArray", defaults(alias = "\"\""))]
            pub fn group_uniq_array<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.group_uniq_array(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "groupArrayMovingAvg", defaults(alias = "\"\""))]
            pub fn group_array_moving_avg<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.group_array_moving_avg(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "groupArrayMovingSum", defaults(alias = "\"\""))]
            pub fn group_array_moving_sum<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.group_array_moving_sum(&column, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "arrayJoin", defaults(alias = "\"\""))]
            pub fn array_join<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.array_join(column, alias);
                });
                self_
            }

            #[php(name = "leftArrayJoin", defaults(alias = "\"\""))]
            pub fn left_array_join<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.left_array_join(column, alias);
                });
                self_
            }

            #[php(name = "limitBy")]
            pub fn limit_by<'a>(
                self_: &'a mut ZendClassObject<$class>,
                count: Int,
                columns: Arr<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let count = count.0;
                let columns = self_.0.strings(&columns);
                self_.0.with(|builder| {
                    builder.limit_by(count, columns);
                });
                self_
            }

            pub fn prewhere<'a>(
                self_: &'a mut ZendClassObject<$class>,
                queries: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let queries = self_.0.queries(&queries)?;
                self_.0.with(|builder| {
                    builder.prewhere(queries);
                });
                Ok(self_)
            }

            #[php(name = "orderWithFill", defaults(direction = "\"ASC\""), optional = direction)]
            pub fn order_with_fill<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                direction: PhpString<'_>,
                from: Option<&Zval>,
                to: Option<&Zval>,
                step: Option<&Zval>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let direction = self_.0.text(direction.bytes());
                let from = from.filter(|from| !from.is_null()).map(|from| self_.0.value(from)).transpose()?;
                let to = to.filter(|to| !to.is_null()).map(|to| self_.0.value(to)).transpose()?;
                let step = step.filter(|step| !step.is_null()).map(|step| self_.0.value(step)).transpose()?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.order_with_fill(&column, &direction, from, to, step).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, mongo_updates, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            pub fn push<'a>(
                self_: &'a mut ZendClassObject<$class>,
                field: PhpString<'_>,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let field = self_.0.text(field.bytes());
                let value = self_.0.value(value)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.push(&field, value).map(|_| ()))?;
                Ok(self_)
            }

            pub fn pull<'a>(
                self_: &'a mut ZendClassObject<$class>,
                field: PhpString<'_>,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let field = self_.0.text(field.bytes());
                let value = self_.0.value(value)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.pull(&field, value).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "addToSet")]
            pub fn add_to_set<'a>(
                self_: &'a mut ZendClassObject<$class>,
                field: PhpString<'_>,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let field = self_.0.text(field.bytes());
                let value = self_.0.value(value)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.add_to_set(&field, value).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "unsetFields")]
            pub fn unset_fields<'a>(
                self_: &'a mut ZendClassObject<$class>,
                fields: &[&Zval],
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let fields: Vec<String> = fields.iter().filter_map(|value| value.zend_str()).map(|value| self_.0.text(value.as_bytes())).collect();
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.unset_fields(fields).map(|_| ()))?;
                Ok(self_)
            }

            pub fn rename_<'a>(
                self_: &'a mut ZendClassObject<$class>,
                oldField: PhpString<'_>,
                newField: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let oldField = self_.0.text(oldField.bytes());
                let newField = self_.0.text(newField.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.rename(&oldField, &newField).map(|_| ()))?;
                Ok(self_)
            }

            pub fn multiply<'a>(
                self_: &'a mut ZendClassObject<$class>,
                field: PhpString<'_>,
                factor: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let field = self_.0.text(field.bytes());
                let factor = self_.0.value(factor)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.multiply(&field, factor).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "popFirst")]
            pub fn pop_first<'a>(
                self_: &'a mut ZendClassObject<$class>,
                field: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let field = self_.0.text(field.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.pop_first(&field).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "popLast")]
            pub fn pop_last<'a>(
                self_: &'a mut ZendClassObject<$class>,
                field: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let field = self_.0.text(field.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.pop_last(&field).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "pullAll")]
            pub fn pull_all<'a>(
                self_: &'a mut ZendClassObject<$class>,
                field: PhpString<'_>,
                values: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let field = self_.0.text(field.bytes());
                let values = self_.0.values(&values)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.pull_all(&field, values).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "updateMin")]
            pub fn update_min<'a>(
                self_: &'a mut ZendClassObject<$class>,
                field: PhpString<'_>,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let field = self_.0.text(field.bytes());
                let value = self_.0.value(value)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.update_min(&field, value).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "updateMax")]
            pub fn update_max<'a>(
                self_: &'a mut ZendClassObject<$class>,
                field: PhpString<'_>,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let field = self_.0.text(field.bytes());
                let value = self_.0.value(value)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.update_max(&field, value).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "currentDate", defaults(r#type = "\"date\""))]
            pub fn current_date<'a>(
                self_: &'a mut ZendClassObject<$class>,
                field: PhpString<'_>,
                r#type: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let field = self_.0.text(field.bytes());
                let r#type = self_.0.text(r#type.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.current_date(&field, &r#type).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "pushEach", optional = position)]
            pub fn push_each<'a>(
                self_: &'a mut ZendClassObject<$class>,
                field: PhpString<'_>,
                values: Arr<'_>,
                position: Option<Int>,
                slice: Option<Int>,
                sort: Option<Arr<'_>>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let field = self_.0.text(field.bytes());
                let values = self_.0.values(&values)?;
                let position = position.map(|position| position.0);
                let slice = slice.map(|slice| slice.0);
                let sort = sort.map(|sort| self_.0.values(&sort)).transpose()?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.push_each(&field, values, position, slice, sort).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "arrayFilter")]
            pub fn array_filter<'a>(
                self_: &'a mut ZendClassObject<$class>,
                identifier: PhpString<'_>,
                condition: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let identifier = self_.0.text(identifier.bytes());
                let condition = self_.0.values(&condition)?;
                self_.0.with(|builder| {
                    builder.array_filter(&identifier, condition);
                });
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, mongo_stages, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(defaults(output = "Arr::Empty"), optional = defaultBucket)]
            pub fn bucket<'a>(
                self_: &'a mut ZendClassObject<$class>,
                groupBy: PhpString<'_>,
                boundaries: Arr<'_>,
                defaultBucket: Option<PhpString<'_>>,
                output: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let groupBy = self_.0.text(groupBy.bytes());
                let boundaries = self_.0.values(&boundaries)?;
                let defaultBucket = defaultBucket.map(|defaultBucket| self_.0.text(defaultBucket.bytes()));
                let output = self_.0.values(&output)?;
                self_.0.with(|builder| {
                    builder.bucket(&groupBy, boundaries, defaultBucket.as_deref(), output);
                });
                Ok(self_)
            }

            #[php(name = "bucketAuto", defaults(output = "Arr::Empty"))]
            pub fn bucket_auto<'a>(
                self_: &'a mut ZendClassObject<$class>,
                groupBy: PhpString<'_>,
                buckets: Int,
                output: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let groupBy = self_.0.text(groupBy.bytes());
                let buckets = buckets.0;
                let output = self_.0.values(&output)?;
                self_.0.with(|builder| {
                    builder.bucket_auto(&groupBy, buckets, output);
                });
                Ok(self_)
            }

            #[php(name = "graphLookup", optional = maxDepth)]
            pub fn graph_lookup<'a>(
                self_: &'a mut ZendClassObject<$class>,
                from: PhpString<'_>,
                startWith: PhpString<'_>,
                connectFromField: PhpString<'_>,
                connectToField: PhpString<'_>,
                r#as: PhpString<'_>,
                maxDepth: Option<Int>,
                depthField: Option<PhpString<'_>>,
            ) -> &'a mut ZendClassObject<$class> {
                let from = self_.0.text(from.bytes());
                let startWith = self_.0.text(startWith.bytes());
                let connectFromField = self_.0.text(connectFromField.bytes());
                let connectToField = self_.0.text(connectToField.bytes());
                let r#as = self_.0.text(r#as.bytes());
                let maxDepth = maxDepth.map(|maxDepth| maxDepth.0);
                let depthField = depthField.map(|depthField| self_.0.text(depthField.bytes()));
                self_.0.with(|builder| {
                    builder.graph_lookup(&from, &startWith, &connectFromField, &connectToField, &r#as, maxDepth, depthField.as_deref());
                });
                self_
            }

            #[php(name = "mergeIntoCollection", optional = on)]
            pub fn merge_into_collection<'a>(
                self_: &'a mut ZendClassObject<$class>,
                collection: PhpString<'_>,
                on: Option<Arr<'_>>,
                whenMatched: Option<Arr<'_>>,
                whenNotMatched: Option<Arr<'_>>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let collection = self_.0.text(collection.bytes());
                let on = on.map(|on| self_.0.values(&on)).transpose()?;
                let whenMatched = whenMatched.map(|whenMatched| self_.0.values(&whenMatched)).transpose()?;
                let whenNotMatched = whenNotMatched.map(|whenNotMatched| self_.0.values(&whenNotMatched)).transpose()?;
                self_.0.with(|builder| {
                    builder.merge_into_collection(&collection, on, whenMatched, whenNotMatched);
                });
                Ok(self_)
            }

            #[php(name = "outputToCollection", optional = database)]
            pub fn output_to_collection<'a>(
                self_: &'a mut ZendClassObject<$class>,
                collection: PhpString<'_>,
                database: Option<PhpString<'_>>,
            ) -> &'a mut ZendClassObject<$class> {
                let collection = self_.0.text(collection.bytes());
                let database = database.map(|database| self_.0.text(database.bytes()));
                self_.0.with(|builder| {
                    builder.output_to_collection(&collection, database.as_deref());
                });
                self_
            }

            #[php(name = "replaceRoot")]
            pub fn replace_root<'a>(
                self_: &'a mut ZendClassObject<$class>,
                newRootExpression: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let newRootExpression = self_.0.text(newRootExpression.bytes());
                self_.0.with(|builder| {
                    builder.replace_root(&newRootExpression);
                });
                self_
            }

            #[php(optional = index)]
            pub fn search_<'a>(
                self_: &'a mut ZendClassObject<$class>,
                searchDefinition: Arr<'_>,
                index: Option<PhpString<'_>>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let searchDefinition = self_.0.values(&searchDefinition)?;
                let index = index.map(|index| self_.0.text(index.bytes()));
                self_.0.with(|builder| {
                    builder.search(searchDefinition, index.as_deref());
                });
                Ok(self_)
            }

            #[php(name = "searchMeta", optional = index)]
            pub fn search_meta<'a>(
                self_: &'a mut ZendClassObject<$class>,
                searchDefinition: Arr<'_>,
                index: Option<PhpString<'_>>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let searchDefinition = self_.0.values(&searchDefinition)?;
                let index = index.map(|index| self_.0.text(index.bytes()));
                self_.0.with(|builder| {
                    builder.search_meta(searchDefinition, index.as_deref());
                });
                Ok(self_)
            }

            #[php(name = "vectorSearch", optional = index)]
            pub fn vector_search<'a>(
                self_: &'a mut ZendClassObject<$class>,
                path: PhpString<'_>,
                queryVector: Arr<'_>,
                numCandidates: Int,
                limit: Int,
                index: Option<PhpString<'_>>,
                filter: Option<Arr<'_>>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let path = self_.0.text(path.bytes());
                let queryVector = self_.0.values(&queryVector)?;
                let numCandidates = numCandidates.0;
                let limit = limit.0;
                let index = index.map(|index| self_.0.text(index.bytes()));
                let filter = filter.map(|filter| self_.0.values(&filter)).transpose()?;
                self_.0.with(|builder| {
                    builder.vector_search(&path, queryVector, numCandidates, limit, index.as_deref(), filter);
                });
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, clickhouse_constants, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            const DELETE_MODE_LIGHTWEIGHT: &'static str = "lightweight";
            const DELETE_MODE_MUTATION: &'static str = "mutation";
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, mongo_facet, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            pub fn facet<'a>(
                self_: &'a mut ZendClassObject<$class>,
                facets: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let mut named = Vec::new();
                for (name, builder) in facets.table().into_iter().flatten() {
                    let builder = BuilderArgument::from_zval(builder)
                        .ok_or_else(|| PhpException::new("A facet must be a builder".to_owned(), 0, ce::type_error()))?;
                    named.push((self_.0.text(name.to_string().as_bytes()), Foreign::of::<$dialect>(&builder)));
                }
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let facets = named.iter_mut().map(|(name, builder)| (name.as_str(), builder as &mut dyn AnyBuilder));
                self_.0.change(|builder| builder.facet(facets).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, lateral_joins, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "joinLateral", defaults(r#type = "Join(JoinType::Inner)"))]
            pub fn join_lateral<'a>(
                self_: &'a mut ZendClassObject<$class>,
                subquery: BuilderArgument<'_>,
                alias: PhpString<'_>,
                r#type: Join,
            ) -> &'a mut ZendClassObject<$class> {
                let subquery: Box<dyn AnyBuilder> = Box::new(Foreign::of::<$dialect>(&subquery));
                let alias = self_.0.text(alias.bytes());
                let r#type = r#type.0;
                self_.0.with(|builder| {
                    builder.join_lateral(subquery, alias, r#type);
                });
                self_
            }

            #[php(name = "leftJoinLateral")]
            pub fn left_join_lateral<'a>(
                self_: &'a mut ZendClassObject<$class>,
                subquery: BuilderArgument<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let subquery: Box<dyn AnyBuilder> = Box::new(Foreign::of::<$dialect>(&subquery));
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.left_join_lateral(subquery, alias);
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, merge_using, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            pub fn using_<'a>(
                self_: &'a mut ZendClassObject<$class>,
                source: BuilderArgument<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let source: Box<dyn AnyBuilder> = Box::new(Foreign::of::<$dialect>(&source));
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.using(source, alias);
                });
                self_
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, vector_search, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "orderByVectorDistance", defaults(metric = "Metric(VectorMetric::Cosine)"))]
            pub fn order_by_vector_distance<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                vector: Arr<'_>,
                metric: Metric,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let attribute = self_.0.text(attribute.bytes());
                let vector = self_.0.values(&vector)?;
                let metric = metric.0;
                self_.0.with(|builder| {
                    builder.order_by_vector_distance(attribute, vector, metric);
                });
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, asof_joins, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "asofJoin", defaults(alias = "\"\""))]
            pub fn asof_join<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
                equiPairs: Arr<'_>,
                leftInequality: PhpString<'_>,
                operator: Asof,
                rightInequality: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let table = self_.0.text(table.bytes());
                let equiPairs = self_.0.pairs(&equiPairs);
                let leftInequality = self_.0.text(leftInequality.bytes());
                let operator = operator.0;
                let rightInequality = self_.0.text(rightInequality.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.asof_join(&table, equiPairs, &leftInequality, operator, &rightInequality, &alias).map(|_| ()))?;
                Ok(self_)
            }

            #[php(name = "asofLeftJoin", defaults(alias = "\"\""))]
            pub fn asof_left_join<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
                equiPairs: Arr<'_>,
                leftInequality: PhpString<'_>,
                operator: Asof,
                rightInequality: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let table = self_.0.text(table.bytes());
                let equiPairs = self_.0.pairs(&equiPairs);
                let leftInequality = self_.0.text(leftInequality.bytes());
                let operator = operator.0;
                let rightInequality = self_.0.text(rightInequality.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.asof_left_join(&table, equiPairs, &leftInequality, operator, &rightInequality, &alias).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, quantiles, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(defaults(alias = "\"\""))]
            pub fn quantiles<'a>(
                self_: &'a mut ZendClassObject<$class>,
                levels: Arr<'_>,
                column: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let levels: Vec<f64> = self_.0.values(&levels)?.values().map(number).collect();
                let column = self_.0.text(column.bytes());
                let alias = self_.0.text(alias.bytes());
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.quantiles(&levels, &column, &alias).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, group_concat, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "groupConcat", defaults(separator = "\",\"", alias = "\"\""), optional = separator)]
            pub fn group_concat<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                separator: PhpString<'_>,
                alias: PhpString<'_>,
                orderBy: Option<Arr<'_>>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let column = self_.0.text(column.bytes());
                let separator = self_.0.text(separator.bytes());
                let alias = self_.0.text(alias.bytes());
                let orderBy = orderBy.map(|orderBy| self_.0.strings(&orderBy));
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.group_concat(&column, &separator, &alias, orderBy.as_deref()).map(|_| ()))?;
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, mongo_more, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(optional = amount)]
            pub fn increment<'a>(
                self_: &'a mut ZendClassObject<$class>,
                field: PhpString<'_>,
                amount: Option<&Zval>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let field = self_.0.text(field.bytes());
                let amount = amount.filter(|amount| !amount.is_null()).map(|amount| self_.0.value(amount)).transpose()?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.increment(&field, amount.unwrap_or(Value::Int(1))).map(|_| ()))?;
                Ok(self_)
            }

            pub fn hint<'a>(
                self_: &'a mut ZendClassObject<$class>,
                hint: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let hint = self_.0.value(hint)?;
                self_.0.with(|builder| {
                    builder.hint(hinted(hint));
                });
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, case_expressions, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "selectCase")]
            pub fn select_case<'a>(
                self_: &'a mut ZendClassObject<$class>,
                case: CaseArgument<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let case = self_.0.case(&case)?;
                self_.0.with(|builder| {
                    builder.select_case(case);
                });
                Ok(self_)
            }

            #[php(name = "setCase")]
            pub fn set_case<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                case: CaseArgument<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let (column, case) = (self_.0.text(column.bytes()), self_.0.case(&case)?);
                self_.0.with(|builder| {
                    builder.set_case(column, case);
                });
                Ok(self_)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, bulk_insert, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "bulkInsert", defaults(columns = "Arr::Empty"))]
            pub fn bulk_insert(
                self_: &mut ZendClassObject<$class>,
                format: Formats,
                rows: &Zval,
                columns: Arr<'_>,
            ) -> PhpResult<Zval> {
                // `iterable`: an array, or what a `Traversable` yields.
                let listed;
                let rows = if rows.is_array() {
                    rows
                } else {
                    let list = ZendCallable::try_from_name("iterator_to_array").map_err(PhpException::from)?;
                    listed = list.try_call(vec![rows, &false]).map_err(PhpException::from)?;
                    &listed
                };
                let rows = rows.array().into_iter().flat_map(ZendHashTable::values);
                let rows = rows.map(|row| self_.0.value(row)).collect::<PhpResult<Vec<Value>>>()?;
                let columns = self_.0.strings(&columns);
                let built = self_.0.with(|builder| builder.bulk_insert(format.0, rows, columns));
                self_.0.formatted(&built.map_err(|error| raised(&error))?)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, ast, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "toAst")]
            pub fn to_ast(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                // A CTE is read with a subclass's own tokenizer.
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let select = self_.0.with(Builder::to_ast).map_err(|error| raised(&error))?;
                super::ast::nodes(&select)
            }

            /// `static::fromAst()`: a new builder of the class it was called on.
            #[php(name = "fromAst")]
            pub fn from_ast(ast: super::ast::Statement<'_>) -> PhpResult<Zval> {
                let select = super::ast::read(&ast)?;
                let class = called_scope();
                let mut object = construct(class, &mut [])?.into_zval(false).map_err(PhpException::from)?;
                // A subclass's own serializer writes what stays raw SQL.
                let factory = declared(class)[Step::CreateAstSerializer as usize];
                let built = match object.object_mut().filter(|_| !factory.is_null()) {
                    Some(made) => {
                        let serializer = invoke(std::ptr::from_mut(made), factory, &mut []).ok_or_else(pending)?;
                        let writer = Written { serializer, json: <$dialect as Text>::JSON };
                        Builder::<$dialect>::from_ast_with(&select, &writer)
                    }
                    None => Builder::<$dialect>::from_ast(&select),
                };
                let mut built = built.map_err(|error| raised(&error))?;
                built.set_overrides(Arc::new(Subclass::<$class>(PhantomData)));
                if let Some(state) = object.object().and_then(ZendClassObject::<$class>::from_zend_obj) {
                    state.0.with(|builder| *builder = built);
                }
                Ok(object)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, steps_all, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "compileRandom", vis = "protected")]
            pub fn compile_random(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.step(Step::CompileRandom, &[])
            }

            #[php(name = "buildTableClause", vis = "protected")]
            pub fn build_table_clause(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.step(Step::BuildTableClause, &[])
            }

            #[php(name = "buildAfterGroupByClause", vis = "protected")]
            pub fn build_after_group_by_clause(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.step(Step::BuildAfterGroupByClause, &[])
            }

            #[php(name = "buildAfterOrderByClause", vis = "protected")]
            pub fn build_after_order_by_clause(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.step(Step::BuildAfterOrderByClause, &[])
            }

            #[php(name = "buildSettingsClause", vis = "protected")]
            pub fn build_settings_clause(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.step(Step::BuildSettingsClause, &[])
            }

            #[php(name = "wrapUnionMember", vis = "protected")]
            pub fn wrap_union_member(
                self_: &mut ZendClassObject<$class>,
                sql: PhpString<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let sql = self_.0.text(sql.bytes());
                self_.0.step(Step::WrapUnionMember, &[Arg::Str(&sql)])
            }

            // `?int` parameters that are required: ext-php-rs makes an `Option` optional, so they are
            // taken as they come and typed where the classes are finished.
            #[php(name = "shouldEmitOffset", vis = "protected")]
            pub fn should_emit_offset(
                self_: &mut ZendClassObject<$class>,
                offset: &Zval,
                limit: &Zval,
            ) -> PhpResult<bool> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.step_bool(Step::ShouldEmitOffset, &[Arg::Int(offset.long()), Arg::Int(limit.long())])
            }

            #[php(name = "compileHavingCondition", vis = "protected")]
            pub fn compile_having_condition(
                self_: &mut ZendClassObject<$class>,
                query: QueryArgument<'_>,
                expression: PhpString<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let query = self_.0.query(query.0)?;
                let expression = self_.0.text(expression.bytes());
                self_.0.step(Step::CompileHavingCondition, &[Arg::Query(&query), Arg::Str(&expression)])
            }

            #[php(name = "compileGroupByTimeBucket", vis = "protected")]
            pub fn compile_group_by_time_bucket(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                interval: PhpString<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let attribute = self_.0.text(attribute.bytes());
                let interval = self_.0.text(interval.bytes());
                self_.0.step(Step::CompileGroupByTimeBucket, &[Arg::Str(&attribute), Arg::Str(&interval)])
            }

            #[php(name = "compileLikePredicate", vis = "protected", defaults(not = "Bool(false)"))]
            pub fn compile_like_predicate(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                not: Bool,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let attribute = self_.0.text(attribute.bytes());
                let not = not.0;
                self_.0.step(Step::CompileLikePredicate, &[Arg::Str(&attribute), Arg::Bool(not)])
            }

            #[php(name = "likeEscape", vis = "protected")]
            pub fn like_escape(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.step(Step::LikeEscape, &[])
            }

            #[php(name = "compileComparison", vis = "protected", optional = column)]
            pub fn compile_comparison(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                operator: PhpString<'_>,
                values: Arr<'_>,
                column: Option<PhpString<'_>>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let attribute = self_.0.text(attribute.bytes());
                let operator = self_.0.text(operator.bytes());
                let values = self_.0.values(&values)?;
                let column = column.map(|column| self_.0.text(column.bytes()));
                self_.0.step(Step::CompileComparison, &[Arg::Str(&attribute), Arg::Str(&operator), Arg::Values(&values), Arg::MaybeStr(column.as_deref())])
            }

            #[php(vis = "protected")]
            pub fn enclose(
                self_: &mut ZendClassObject<$class>,
                condition: PhpString<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let condition = self_.0.text(condition.bytes());
                self_.0.step(Step::Enclose, &[Arg::Str(&condition)])
            }

            #[php(name = "escapeLikeValue", vis = "protected")]
            pub fn escape_like_value(
                self_: &mut ZendClassObject<$class>,
                value: &Zval,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let value = self_.0.value(value)?;
                self_.0.step(Step::EscapeLikeValue, &[Arg::Value(&value)])
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, steps_sql, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "compileSpatialDistance", vis = "protected")]
            pub fn compile_spatial_distance(
                self_: &mut ZendClassObject<$class>,
                method: MethodArgument,
                attribute: PhpString<'_>,
                values: Arr<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let method = method.0;
                let attribute = self_.0.text(attribute.bytes());
                let values = self_.0.values(&values)?;
                self_.0.step(Step::CompileSpatialDistance, &[Arg::Method(method), Arg::Str(&attribute), Arg::Values(&values)])
            }

            #[php(name = "compileSpatialPredicate", vis = "protected")]
            pub fn compile_spatial_predicate(
                self_: &mut ZendClassObject<$class>,
                function: PhpString<'_>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
                not: Bool,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let function = self_.0.text(function.bytes());
                let attribute = self_.0.text(attribute.bytes());
                let values = self_.0.values(&values)?;
                let not = not.0;
                self_.0.step(Step::CompileSpatialPredicate, &[Arg::Str(&function), Arg::Str(&attribute), Arg::Values(&values), Arg::Bool(not)])
            }

            #[php(name = "compileSpatialCoversPredicate", vis = "protected")]
            pub fn compile_spatial_covers_predicate(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
                not: Bool,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let attribute = self_.0.text(attribute.bytes());
                let values = self_.0.values(&values)?;
                let not = not.0;
                self_.0.step(Step::CompileSpatialCoversPredicate, &[Arg::Str(&attribute), Arg::Values(&values), Arg::Bool(not)])
            }

            #[php(name = "compileJsonPathExpression", vis = "protected")]
            pub fn compile_json_path_expression(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let attribute = self_.0.text(attribute.bytes());
                let values = self_.0.values(&values)?;
                self_.0.step(Step::CompileJsonPathExpression, &[Arg::Str(&attribute), Arg::Values(&values)])
            }

            #[php(name = "compileJsonFilter", vis = "protected")]
            pub fn compile_json_filter(
                self_: &mut ZendClassObject<$class>,
                method: MethodArgument,
                attribute: PhpString<'_>,
                query: QueryArgument<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let method = method.0;
                let attribute = self_.0.text(attribute.bytes());
                let query = self_.0.query(query.0)?;
                self_.0.step(Step::CompileJsonFilter, &[Arg::Method(method), Arg::Str(&attribute), Arg::Query(&query)])
            }

            #[php(name = "compileConflictHeader", vis = "protected")]
            pub fn compile_conflict_header(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.step(Step::CompileConflictHeader, &[])
            }

            #[php(name = "compileConflictAssignment", vis = "protected")]
            pub fn compile_conflict_assignment(
                self_: &mut ZendClassObject<$class>,
                wrapped: PhpString<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let wrapped = self_.0.text(wrapped.bytes());
                self_.0.step(Step::CompileConflictAssignment, &[Arg::Str(&wrapped)])
            }

            #[php(name = "compileConflictClause", vis = "protected")]
            pub fn compile_conflict_clause(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.step(Step::CompileConflictClause, &[])
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, steps_aggregates, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "groupConcatExpr", vis = "protected")]
            pub fn group_concat_expr(
                self_: &mut ZendClassObject<$class>,
                column: PhpString<'_>,
                orderBy: PhpString<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let column = self_.0.text(column.bytes());
                let orderBy = self_.0.text(orderBy.bytes());
                self_.0.step(Step::GroupConcatExpr, &[Arg::Str(&column), Arg::Str(&orderBy)])
            }

            #[php(name = "jsonArrayAggExpr", vis = "protected")]
            pub fn json_array_agg_expr(
                self_: &mut ZendClassObject<$class>,
                column: PhpString<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let column = self_.0.text(column.bytes());
                self_.0.step(Step::JsonArrayAggExpr, &[Arg::Str(&column)])
            }

            #[php(name = "jsonObjectAggExpr", vis = "protected")]
            pub fn json_object_agg_expr(
                self_: &mut ZendClassObject<$class>,
                keyColumn: PhpString<'_>,
                valueColumn: PhpString<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let keyColumn = self_.0.text(keyColumn.bytes());
                let valueColumn = self_.0.text(valueColumn.bytes());
                self_.0.step(Step::JsonObjectAggExpr, &[Arg::Str(&keyColumn), Arg::Str(&valueColumn)])
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, steps_mysql, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "geomFromText", vis = "protected")]
            pub fn geom_from_text(
                self_: &mut ZendClassObject<$class>,
                srid: Int,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let srid = srid.0;
                self_.0.step(Step::GeomFromText, &[Arg::Int(Some(srid))])
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, steps_postgres, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "compileObjectFilter", vis = "protected")]
            pub fn compile_object_filter(
                self_: &mut ZendClassObject<$class>,
                query: QueryArgument<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let query = self_.0.query(query.0)?;
                self_.0.step(Step::CompileObjectFilter, &[Arg::Query(&query)])
            }

            #[php(name = "compileJsonbContainment", vis = "protected")]
            pub fn compile_jsonb_containment(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
                not: Bool,
                wrapScalars: Bool,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let attribute = self_.0.text(attribute.bytes());
                let values = self_.0.values(&values)?;
                let not = not.0;
                let wrapScalars = wrapScalars.0;
                self_.0.step(Step::CompileJsonbContainment, &[Arg::Str(&attribute), Arg::Values(&values), Arg::Bool(not), Arg::Bool(wrapScalars)])
            }

            #[php(name = "buildJsonbPath", vis = "protected")]
            pub fn build_jsonb_path(
                self_: &mut ZendClassObject<$class>,
                path: PhpString<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let path = self_.0.text(path.bytes());
                self_.0.step(Step::BuildJsonbPath, &[Arg::Str(&path)])
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, helpers, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "validateTable", vis = "protected")]
            pub fn validate_table(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<()> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.base(Step::ValidateTable, &[]).map(drop)
            }

            #[php(name = "validateRows", vis = "protected")]
            pub fn validate_rows(
                self_: &mut ZendClassObject<$class>,
                operation: PhpString<'_>,
            ) -> PhpResult<()> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let operation = self_.0.text(operation.bytes());
                self_.0.base(Step::ValidateRows, &[Arg::Str(&operation)]).map(drop)
            }

            #[php(name = "validateAndGetColumns", vis = "protected")]
            pub fn validate_and_get_columns(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let Ret::Columns(columns) = self_.0.base(Step::ValidateAndGetColumns, &[])? else { return Ok(empty()) };
                let columns: Vec<Zval> = columns
                    .iter()
                    .map(|column| match column {
                        Key::Int(index) => {
                            let mut out = Zval::new();
                            out.set_long(*index);
                            out
                        }
                        Key::Str(name) => string(name.as_bytes()),
                    })
                    .collect();
                if columns.is_empty() { Ok(empty()) } else { columns.into_zval(false).map_err(PhpException::from) }
            }

            #[php(name = "addBindings", vis = "protected")]
            pub fn add_bindings(
                self_: &mut ZendClassObject<$class>,
                bindings: Arr<'_>,
            ) -> PhpResult<()> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let bindings = self_.0.values(&bindings)?;
                self_.0.base(Step::AddBindings, &[Arg::Values(&bindings)]).map(drop)
            }

            #[php(name = "compileCase", vis = "protected")]
            pub fn compile_case(
                self_: &mut ZendClassObject<$class>,
                case: CaseArgument<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let case = self_.0.case(&case)?;
                self_.0.step(Step::CompileCase, &[Arg::Case(&case)])
            }

            #[php(name = "compileInsertBody", vis = "protected")]
            pub fn compile_insert_body(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let Ret::InsertBody(sql, bindings) = self_.0.base(Step::CompileInsertBody, &[])? else { return Ok(empty()) };
                let json = <$dialect as Text>::JSON;
                let bindings = bindings.iter().map(|binding| zval(json, binding)).collect::<PhpResult<Vec<_>>>()?;
                let bindings = if bindings.is_empty() { empty() } else { bindings.into_zval(false).map_err(PhpException::from)? };
                vec![string(&self_.0.written(&sql)?), bindings].into_zval(false).map_err(PhpException::from)
            }

            #[php(name = "compileAssignments", vis = "protected")]
            pub fn compile_assignments(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let Ret::Strings(assignments) = self_.0.base(Step::CompileAssignments, &[])? else { return Ok(empty()) };
                self_.0.listed(&assignments)
            }

            #[php(name = "compileWhereClauses", vis = "protected", optional = grouped)]
            pub fn compile_where_clauses(
                self_: &mut ZendClassObject<$class>,
                parts: &mut Zval,
                grouped: Option<Grouped>,
            ) -> PhpResult<()> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                // `$parts` is taken by reference, and the clauses are appended to it. The builder
                // groups its own queries, whatever it is handed.
                let _ = grouped;
                let Ret::Strings(clauses) = self_.0.base(Step::CompileWhereClauses, &[Arg::Strings(&[]), Arg::Parsed(None)])? else {
                    return Ok(());
                };
                self_.0.append(parts, &clauses)
            }

            #[php(name = "compileOrderAndLimit", vis = "protected", optional = grouped)]
            pub fn compile_order_and_limit(
                self_: &mut ZendClassObject<$class>,
                parts: &mut Zval,
                grouped: Option<Grouped>,
            ) -> PhpResult<()> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let _ = grouped;
                let Ret::Strings(clauses) = self_.0.base(Step::CompileOrderAndLimit, &[Arg::Strings(&[]), Arg::Parsed(None)])? else {
                    return Ok(());
                };
                self_.0.append(parts, &clauses)
            }

            #[php(name = "buildAfterJoinsClause", vis = "protected")]
            pub fn build_after_joins_clause(
                self_: &mut ZendClassObject<$class>,
                grouped: Grouped,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let _ = grouped;
                let grouped = utopia_query::ParsedQuery::default();
                self_.0.step(Step::BuildAfterJoinsClause, &[Arg::Parsed(Some(&grouped))])
            }

            #[php(name = "compileVectorOrderExpression", vis = "protected")]
            pub fn compile_vector_order_expression(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let Ret::Condition(Some(found)) = self_.0.base(Step::CompileVectorOrderExpression, &[])? else { return Ok(null()) };
                let json = <$dialect as Text>::JSON;
                let bindings = found.bindings.iter().map(|binding| zval(json, binding)).collect::<PhpResult<Vec<_>>>()?;
                let bindings = if bindings.is_empty() { empty() } else { bindings.into_zval(false).map_err(PhpException::from)? };
                let mut arguments = [string(&bytes(json, &found.expression)), bindings];
                construct(class(CONDITION)?, &mut arguments)?.into_zval(false).map_err(PhpException::from)
            }

            #[php(name = "resolveJoinFilterPlacement", vis = "protected")]
            pub fn resolve_join_filter_placement(
                self_: &mut ZendClassObject<$class>,
                requested: PlacementArgument,
                isCrossJoin: Bool,
            ) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let arguments = [Arg::Placement(requested.0), Arg::Bool(isCrossJoin.0)];
                match self_.0.base(Step::ResolveJoinFilterPlacement, &arguments)? {
                    Ret::Placement(placement) => placed(placement),
                    _ => placed(requested.0),
                }
            }

            #[php(name = "createTokenizer", vis = "protected")]
            pub fn create_tokenizer(&self) -> PhpResult<Zval> {
                construct(class(<$dialect as Text>::TOKENIZER)?, &mut [])?.into_zval(false).map_err(PhpException::from)
            }

            #[php(name = "createAstSerializer", vis = "protected")]
            pub fn create_ast_serializer(&self) -> PhpResult<Zval> {
                construct(class(<$dialect as Text>::SERIALIZER)?, &mut [])?.into_zval(false).map_err(PhpException::from)
            }

            /// `clone` copies the builder in its handler, so there is nothing left to do here.
            pub fn __clone(&self) {}
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, helpers_returning, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "appendReturning", vis = "protected")]
            pub fn append_returning(
                self_: &mut ZendClassObject<$class>,
                result: StatementArgument<'_>,
            ) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let statement = self_.0.read(result.0).unwrap_or_else(|| Statement::new(String::new(), Vec::new()));
                match self_.0.base(Step::AppendReturning, &[Arg::Statement(&statement)])? {
                    Ret::Statement(statement) => self_.0.statement_object(&statement),
                    _ => Ok(null()),
                }
            }

            #[php(name = "resetReturning", vis = "protected")]
            pub fn reset_returning(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<()> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.base(Step::ResetReturning, &[]).map(drop)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, helpers_group_by, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "resetGroupByModifier", vis = "protected")]
            pub fn reset_group_by_modifier(
                self_: &mut ZendClassObject<$class>,
            ) -> PhpResult<()> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.base(Step::ResetGroupByModifier, &[]).map(drop)
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, helpers_wkt, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            /// The column type a WKT string is: `point`, `linestring` or `polygon`.
            #[php(name = "getSpatialTypeFromWkt", vis = "protected")]
            pub fn get_spatial_type_from_wkt(&self, wkt: PhpString<'_>) -> PhpResult<Bytes> {
                let upper = String::from_utf8_lossy(wkt.bytes()).trim().to_ascii_uppercase();
                for (prefix, kind) in [("POINT", "point"), ("LINESTRING", "linestring"), ("POLYGON", "polygon")] {
                    if upper.starts_with(prefix) {
                        return Ok(Bytes(kind.as_bytes().to_vec()));
                    }
                }
                Ok(Bytes(b"unknown".to_vec()))
            }
        }, $($rest,)*);
    };
    (@add $metas:tt $class:ident, $dialect:ty, {$($methods:tt)*}, helpers_named, $($rest:ident,)*) => {
        builder_class!(@add $metas $class, $dialect, {
            $($methods)*
            #[php(name = "applyNamedTypedBindings", vis = "protected")]
            pub fn apply_named_typed_bindings(
                self_: &mut ZendClassObject<$class>,
                stmt: StatementArgument<'_>,
            ) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let statement = self_.0.read(stmt.0).unwrap_or_else(|| Statement::new(String::new(), Vec::new()));
                match self_.0.base(Step::ApplyNamedTypedBindings, &[Arg::Statement(&statement)])? {
                    Ret::Statement(statement) => self_.0.statement_object(&statement),
                    _ => Ok(null()),
                }
            }
        }, $($rest,)*);
    };
    // FEATURES
    (@add [$(#[$meta:meta])*] $class:ident, $dialect:ty, {$($methods:tt)*},) => {
        $(#[$meta])*
        #[derive(Clone)]
        pub struct $class(State<$dialect>);

        impl Native for $class {
            type Dialect = $dialect;

            fn state(&self) -> &State<$dialect> {
                &self.0
            }

            fn fresh() -> Self {
                Self(State::new::<$class>())
            }

            fn pointers() -> &'static std::sync::atomic::AtomicUsize {
                static ORIGINAL: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
                &ORIGINAL
            }
        }

        #[php_impl]
        impl $class {
            /// The protected properties a subclass reads and writes.
            #[php(getter, name = "bindings", vis = "protected")]
            pub fn bindings_property(&self) -> Slot {
                Slot(self.0.bound().unwrap_or_else(|_| empty()))
            }

            #[php(setter, name = "bindings", vis = "protected")]
            pub fn set_bindings_property(&self, bindings: Slot) {
                self.0.bind(&bindings.0);
            }

            #[php(getter, name = "pendingQueries", vis = "protected")]
            pub fn pending_queries_property(&self) -> Slot {
                Slot(self.0.pending().unwrap_or_else(|_| empty()))
            }

            #[php(setter, name = "pendingQueries", vis = "protected")]
            pub fn set_pending_queries_property(&self, queries: Slot) {
                if let Ok(queries) = self.0.queries(&Arr::Given(&queries.0)) {
                    self.0.with(|builder| {
                        builder.set_pending(queries);
                    });
                }
            }

            #[php(getter, name = "resolvedAttributeCache", vis = "protected")]
            pub fn resolved_attribute_cache_property(&self) -> Slot {
                Slot(self.0.resolved())
            }

            #[php(setter, name = "resolvedAttributeCache", vis = "protected")]
            pub fn set_resolved_attribute_cache_property(&self, resolved: Slot) {
                self.0.resolve(&resolved.0);
            }

            pub fn __construct() -> Self {
                Self(State::new::<$class>())
            }

            #[php(defaults(table = "\"\"", alias = "\"\""))]
            pub fn from<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (table, alias) = (self_.0.text(table.bytes()), self_.0.text(alias.bytes()));
                self_.0.with(|builder| {
                    builder.from(table, alias);
                });
                self_
            }

            #[php(name = "fromNone")]
            pub fn from_none(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.from_none();
                });
                self_
            }

            #[php(defaults(bindings = "Arr::Empty"))]
            pub fn select<'a>(
                self_: &'a mut ZendClassObject<$class>,
                columns: Columns<'_>,
                bindings: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let state = &self_.0;
                match columns.0.zend_str() {
                    // One string is a raw expression, with its bindings.
                    Some(expression) => {
                        let expression = state.text(expression.as_bytes());
                        let bindings: Vec<Value> = state.values(&bindings)?.values().cloned().collect();
                        state.with(|builder| {
                            builder.select_raw(expression, bindings);
                        });
                    }
                    None => {
                        let columns = state.strings(&Arr::Given(columns.0));
                        state.with(|builder| {
                            builder.select(columns);
                        });
                    }
                }
                Ok(self_)
            }

            pub fn distinct(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.distinct();
                });
                self_
            }

            pub fn filter<'a>(
                self_: &'a mut ZendClassObject<$class>,
                queries: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let queries = self_.0.queries(&queries)?;
                self_.0.with(|builder| {
                    builder.filter(queries);
                });
                Ok(self_)
            }

            pub fn queries<'a>(
                self_: &'a mut ZendClassObject<$class>,
                queries: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let queries = self_.0.queries(&queries)?;
                self_.0.with(|builder| {
                    builder.queries(queries);
                });
                Ok(self_)
            }

            pub fn having<'a>(
                self_: &'a mut ZendClassObject<$class>,
                queries: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let queries = self_.0.queries(&queries)?;
                self_.0.with(|builder| {
                    builder.having(queries);
                });
                Ok(self_)
            }

            #[php(name = "sortAsc", optional = nulls)]
            pub fn sort_asc<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                nulls: Option<Nulls>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                self_.0.with(|builder| {
                    builder.sort_asc(attribute, nulls.map(|nulls| nulls.0));
                });
                self_
            }

            #[php(name = "sortDesc", optional = nulls)]
            pub fn sort_desc<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                nulls: Option<Nulls>,
            ) -> &'a mut ZendClassObject<$class> {
                let attribute = self_.0.text(attribute.bytes());
                self_.0.with(|builder| {
                    builder.sort_desc(attribute, nulls.map(|nulls| nulls.0));
                });
                self_
            }

            #[php(name = "sortRandom")]
            pub fn sort_random(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.sort_random();
                });
                self_
            }

            pub fn limit(self_: &mut ZendClassObject<$class>, value: Int) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.limit(value.0);
                });
                self_
            }

            pub fn offset(self_: &mut ZendClassObject<$class>, value: Int) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.offset(value.0);
                });
                self_
            }

            #[php(defaults(withTies = "Bool(false)"))]
            pub fn fetch(self_: &mut ZendClassObject<$class>, count: Int, withTies: Bool) -> &mut ZendClassObject<$class> {
                self_.0.with(|builder| {
                    builder.fetch(count.0, withTies.0);
                });
                self_
            }

            #[php(defaults(perPage = "Int(25)"))]
            pub fn page(
                self_: &mut ZendClassObject<$class>,
                page: Int,
                perPage: Int,
            ) -> PhpResult<&mut ZendClassObject<$class>> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.page(page.0, perPage.0).map(drop))?;
                Ok(self_)
            }

            #[php(name = "cursorAfter")]
            pub fn cursor_after<'a>(
                self_: &'a mut ZendClassObject<$class>,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let value = self_.0.cursor(value)?;
                self_.0.with(|builder| {
                    builder.cursor_after(value);
                });
                Ok(self_)
            }

            #[php(name = "cursorBefore")]
            pub fn cursor_before<'a>(
                self_: &'a mut ZendClassObject<$class>,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let value = self_.0.cursor(value)?;
                self_.0.with(|builder| {
                    builder.cursor_before(value);
                });
                Ok(self_)
            }

            #[php(defaults(attribute = "\"*\"", alias = "\"\""))]
            pub fn count<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (attribute, alias) = (self_.0.text(attribute.bytes()), self_.0.text(alias.bytes()));
                self_.0.with(|builder| {
                    builder.count(attribute, alias);
                });
                self_
            }

            #[php(name = "countDistinct", defaults(alias = "\"\""))]
            pub fn count_distinct<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (attribute, alias) = (self_.0.text(attribute.bytes()), self_.0.text(alias.bytes()));
                self_.0.with(|builder| {
                    builder.count_distinct(attribute, alias);
                });
                self_
            }

            #[php(defaults(alias = "\"\""))]
            pub fn sum<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (attribute, alias) = (self_.0.text(attribute.bytes()), self_.0.text(alias.bytes()));
                self_.0.with(|builder| {
                    builder.sum(attribute, alias);
                });
                self_
            }

            #[php(defaults(alias = "\"\""))]
            pub fn avg<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (attribute, alias) = (self_.0.text(attribute.bytes()), self_.0.text(alias.bytes()));
                self_.0.with(|builder| {
                    builder.avg(attribute, alias);
                });
                self_
            }

            #[php(defaults(alias = "\"\""))]
            pub fn min<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (attribute, alias) = (self_.0.text(attribute.bytes()), self_.0.text(alias.bytes()));
                self_.0.with(|builder| {
                    builder.min(attribute, alias);
                });
                self_
            }

            #[php(defaults(alias = "\"\""))]
            pub fn max<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (attribute, alias) = (self_.0.text(attribute.bytes()), self_.0.text(alias.bytes()));
                self_.0.with(|builder| {
                    builder.max(attribute, alias);
                });
                self_
            }

            #[php(name = "groupBy")]
            pub fn group_by<'a>(
                self_: &'a mut ZendClassObject<$class>,
                columns: Arr<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let columns = self_.0.strings(&columns);
                self_.0.with(|builder| {
                    builder.group_by(columns);
                });
                self_
            }

            #[php(name = "groupByTimeBucket")]
            pub fn group_by_time_bucket<'a>(
                self_: &'a mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                interval: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (attribute, interval) = (self_.0.text(attribute.bytes()), self_.0.text(interval.bytes()));
                self_.0.change(|builder| builder.group_by_time_bucket(attribute, &interval).map(drop))?;
                Ok(self_)
            }

            #[php(defaults(operator = "\"=\"", alias = "\"\""))]
            pub fn join<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
                left: PhpString<'_>,
                right: PhpString<'_>,
                operator: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let state = &self_.0;
                let (table, alias) = (state.text(table.bytes()), state.text(alias.bytes()));
                let (left, right) = (state.text(left.bytes()), state.text(right.bytes()));
                let operator = state.text(operator.bytes());
                state.with(|builder| {
                    builder.join(table, &left, &right, &operator, alias);
                });
                self_
            }

            #[php(name = "leftJoin", defaults(operator = "\"=\"", alias = "\"\""))]
            pub fn left_join<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
                left: PhpString<'_>,
                right: PhpString<'_>,
                operator: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let state = &self_.0;
                let (table, alias) = (state.text(table.bytes()), state.text(alias.bytes()));
                let (left, right) = (state.text(left.bytes()), state.text(right.bytes()));
                let operator = state.text(operator.bytes());
                state.with(|builder| {
                    builder.left_join(table, &left, &right, &operator, alias);
                });
                self_
            }

            #[php(name = "rightJoin", defaults(operator = "\"=\"", alias = "\"\""))]
            pub fn right_join<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
                left: PhpString<'_>,
                right: PhpString<'_>,
                operator: PhpString<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let state = &self_.0;
                let (table, alias) = (state.text(table.bytes()), state.text(alias.bytes()));
                let (left, right) = (state.text(left.bytes()), state.text(right.bytes()));
                let operator = state.text(operator.bytes());
                state.with(|builder| {
                    builder.right_join(table, &left, &right, &operator, alias);
                });
                self_
            }

            pub fn into<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let table = self_.0.text(table.bytes());
                self_.0.with(|builder| {
                    builder.into(table);
                });
                self_
            }

            #[php(name = "insertAs")]
            pub fn insert_as<'a>(
                self_: &'a mut ZendClassObject<$class>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let alias = self_.0.text(alias.bytes());
                self_.0.with(|builder| {
                    builder.insert_as(alias);
                });
                self_
            }

            pub fn set<'a>(
                self_: &'a mut ZendClassObject<$class>,
                row: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let row = self_.0.row(&row)?;
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.set(row).map(drop))?;
                Ok(self_)
            }

            #[php(name = "onConflict")]
            pub fn on_conflict<'a>(
                self_: &'a mut ZendClassObject<$class>,
                keys: Arr<'_>,
                updateColumns: Arr<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (keys, columns) = (self_.0.strings(&keys), self_.0.strings(&updateColumns));
                self_.0.with(|builder| {
                    builder.on_conflict(keys, columns);
                });
                self_
            }

            pub fn reset(self_: &mut ZendClassObject<$class>) -> &mut ZendClassObject<$class> {
                // A dialect's reset runs steps a subclass may override.
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.with(|builder| {
                    builder.reset();
                });
                self_
            }

            #[php(name = "getBindings")]
            pub fn get_bindings(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.bindings(true)
            }

            #[php(name = "toRawSql")]
            pub fn to_raw_sql(self_: &mut ZendClassObject<$class>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.raw()
            }

            pub fn insert(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                if let Some(formatted) = self_.0.with(<$dialect as Text>::formatted).map_err(|error| raised(&error))? {
                    return self_.0.formatted(&formatted);
                }
                let built = self_.0.with(Builder::insert);
                self_.0.statement(built)
            }

            #[php(name = "insertDefaultValues")]
            pub fn insert_default_values(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let built = self_.0.with(Builder::insert_default_values);
                self_.0.statement(built)
            }

            pub fn update(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let built = self_.0.with(Builder::update);
                self_.0.statement(built)
            }

            pub fn delete(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let built = self_.0.with(Builder::delete);
                self_.0.statement(built)
            }

            #[php(name = "fromSub")]
            pub fn from_sub<'a>(
                self_: &'a mut ZendClassObject<$class>,
                subquery: BuilderArgument<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (subquery, alias) = (Box::new(Foreign::of::<$dialect>(&subquery)), self_.0.text(alias.bytes()));
                self_.0.with(|builder| {
                    builder.from_sub_boxed(subquery, alias);
                });
                self_
            }

            #[php(name = "selectSub")]
            pub fn select_sub<'a>(
                self_: &'a mut ZendClassObject<$class>,
                subquery: BuilderArgument<'_>,
                alias: PhpString<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (subquery, alias) = (Box::new(Foreign::of::<$dialect>(&subquery)), self_.0.text(alias.bytes()));
                self_.0.with(|builder| {
                    builder.select_sub(subquery, alias);
                });
                self_
            }

            #[php(name = "filterWhereIn")]
            pub fn filter_where_in<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                subquery: BuilderArgument<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (column, subquery) = (self_.0.text(column.bytes()), Box::new(Foreign::of::<$dialect>(&subquery)));
                self_.0.with(|builder| {
                    builder.filter_where_in(column, subquery);
                });
                self_
            }

            #[php(name = "filterWhereNotIn")]
            pub fn filter_where_not_in<'a>(
                self_: &'a mut ZendClassObject<$class>,
                column: PhpString<'_>,
                subquery: BuilderArgument<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (column, subquery) = (self_.0.text(column.bytes()), Box::new(Foreign::of::<$dialect>(&subquery)));
                self_.0.with(|builder| {
                    builder.filter_where_not_in(column, subquery);
                });
                self_
            }

            #[php(name = "filterExists")]
            pub fn filter_exists<'a>(
                self_: &'a mut ZendClassObject<$class>,
                subquery: BuilderArgument<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let subquery = Box::new(Foreign::of::<$dialect>(&subquery));
                self_.0.with(|builder| {
                    builder.filter_exists(subquery);
                });
                self_
            }

            #[php(name = "filterNotExists")]
            pub fn filter_not_exists<'a>(
                self_: &'a mut ZendClassObject<$class>,
                subquery: BuilderArgument<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let subquery = Box::new(Foreign::of::<$dialect>(&subquery));
                self_.0.with(|builder| {
                    builder.filter_not_exists(subquery);
                });
                self_
            }

            pub fn union<'a>(
                self_: &'a mut ZendClassObject<$class>,
                other: BuilderArgument<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let mut other = Foreign::of::<$dialect>(&other);
                self_.0.change(|builder| builder.union(&mut other).map(drop))?;
                Ok(self_)
            }

            #[php(name = "unionAll")]
            pub fn union_all<'a>(
                self_: &'a mut ZendClassObject<$class>,
                other: BuilderArgument<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let mut other = Foreign::of::<$dialect>(&other);
                self_.0.change(|builder| builder.union_all(&mut other).map(drop))?;
                Ok(self_)
            }

            pub fn intersect<'a>(
                self_: &'a mut ZendClassObject<$class>,
                other: BuilderArgument<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let mut other = Foreign::of::<$dialect>(&other);
                self_.0.change(|builder| builder.intersect(&mut other).map(drop))?;
                Ok(self_)
            }

            #[php(name = "intersectAll")]
            pub fn intersect_all<'a>(
                self_: &'a mut ZendClassObject<$class>,
                other: BuilderArgument<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let mut other = Foreign::of::<$dialect>(&other);
                self_.0.change(|builder| builder.intersect_all(&mut other).map(drop))?;
                Ok(self_)
            }

            pub fn except<'a>(
                self_: &'a mut ZendClassObject<$class>,
                other: BuilderArgument<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let mut other = Foreign::of::<$dialect>(&other);
                self_.0.change(|builder| builder.except(&mut other).map(drop))?;
                Ok(self_)
            }

            #[php(name = "exceptAll")]
            pub fn except_all<'a>(
                self_: &'a mut ZendClassObject<$class>,
                other: BuilderArgument<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let mut other = Foreign::of::<$dialect>(&other);
                self_.0.change(|builder| builder.except_all(&mut other).map(drop))?;
                Ok(self_)
            }

            #[php(defaults(columns = "Arr::Empty"))]
            pub fn with<'a>(
                self_: &'a mut ZendClassObject<$class>,
                name: PhpString<'_>,
                query: BuilderArgument<'_>,
                columns: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (name, columns) = (self_.0.text(name.bytes()), self_.0.strings(&columns));
                let mut query = Foreign::of::<$dialect>(&query);
                self_.0.change(|builder| builder.with(name, &mut query, columns).map(drop))?;
                Ok(self_)
            }

            #[php(name = "withRecursive", defaults(columns = "Arr::Empty"))]
            pub fn with_recursive<'a>(
                self_: &'a mut ZendClassObject<$class>,
                name: PhpString<'_>,
                query: BuilderArgument<'_>,
                columns: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (name, columns) = (self_.0.text(name.bytes()), self_.0.strings(&columns));
                let mut query = Foreign::of::<$dialect>(&query);
                self_.0.change(|builder| builder.with_recursive(name, &mut query, columns).map(drop))?;
                Ok(self_)
            }

            #[php(name = "withRecursiveSeedStep", defaults(columns = "Arr::Empty"))]
            pub fn with_recursive_seed_step<'a>(
                self_: &'a mut ZendClassObject<$class>,
                name: PhpString<'_>,
                seed: BuilderArgument<'_>,
                step: BuilderArgument<'_>,
                columns: Arr<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (name, columns) = (self_.0.text(name.bytes()), self_.0.strings(&columns));
                let (mut seed, mut step) = (Foreign::of::<$dialect>(&seed), Foreign::of::<$dialect>(&step));
                self_.0.change(|builder| builder.with_recursive_seed_step(name, &mut seed, &mut step, columns).map(drop))?;
                Ok(self_)
            }

            #[php(name = "fromSelect")]
            pub fn from_select<'a>(
                self_: &'a mut ZendClassObject<$class>,
                columns: Arr<'_>,
                source: BuilderArgument<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let (columns, source) = (self_.0.strings(&columns), Box::new(Foreign::of::<$dialect>(&source)));
                self_.0.with(|builder| {
                    builder.from_select(columns, source);
                });
                self_
            }

            #[php(name = "insertSelect")]
            pub fn insert_select(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let built = self_.0.with(Builder::insert_select);
                self_.0.statement(built)
            }

            #[php(name = "joinWhere", defaults(r#type = "Join(JoinType::Inner)", alias = "\"\""))]
            pub fn join_where<'a>(
                self_: &'a mut ZendClassObject<$class>,
                table: PhpString<'_>,
                callback: Callback<'_>,
                r#type: Join,
                alias: PhpString<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                // The conditions are written on the package's own `JoinBuilder`, which checks them.
                let join = construct(class(JOIN_BUILDER)?, &mut [])?.into_zval(false).map_err(PhpException::from)?;
                ZendCallable::new(callback.0).map_err(PhpException::from)?.try_call(vec![&join]).map(drop).or_else(|_| thrown())?;
                thrown()?;
                let written = join_of(<$dialect as Text>::JSON, &join);
                let (table, alias) = (self_.0.text(table.bytes()), self_.0.text(alias.bytes()));
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| {
                    builder
                        .join_where(
                            table,
                            |join| {
                                *join = written;
                                Ok(())
                            },
                            r#type.0,
                            alias,
                        )
                        .map(drop)
                })?;
                Ok(self_)
            }

            #[php(name = "addHook")]
            pub fn add_hook<'a>(
                self_: &'a mut ZendClassObject<$class>,
                hook: HookArgument<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let object = hook.0.object();
                let is = |name: &str| ClassEntry::try_find(name).is_some_and(|class| object.is_some_and(|object| object.instance_of(class)));
                let held = Arc::new(Hooked { object: hook.0.shallow_clone(), json: <$dialect as Text>::JSON });
                let hook = Hook {
                    filter: is("Utopia\\Query\\Hook\\Filter").then(|| held.clone() as Arc<dyn hook::Filter>),
                    attribute: is("Utopia\\Query\\Hook\\Attribute").then(|| held.clone() as Arc<dyn hook::Attribute>),
                    join_filter: is("Utopia\\Query\\Hook\\Join\\Filter").then(|| held.clone() as Arc<dyn hook::JoinFilter>),
                };
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.change(|builder| builder.add_hook(hook).map(drop))?;
                Ok(self_)
            }

            #[php(name = "selectWindow", optional = partitionBy)]
            pub fn select_window<'a>(
                self_: &'a mut ZendClassObject<$class>,
                function: PhpString<'_>,
                alias: PhpString<'_>,
                partitionBy: Option<Arr<'_>>,
                orderBy: Option<Arr<'_>>,
                windowName: Option<PhpString<'_>>,
                frame: Option<Frame>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let state = &self_.0;
                let (function, alias) = (state.text(function.bytes()), state.text(alias.bytes()));
                let (partition, order) = (partitionBy.map(|by| state.strings(&by)), orderBy.map(|by| state.strings(&by)));
                let name = state.maybe(windowName.as_ref());
                state.change(|builder| builder.select_window(function, alias, partition, order, name, frame.map(|frame| frame.0)).map(drop))?;
                Ok(self_)
            }

            #[php(optional = partitionBy)]
            pub fn window<'a>(
                self_: &'a mut ZendClassObject<$class>,
                name: PhpString<'_>,
                partitionBy: Option<Arr<'_>>,
                orderBy: Option<Arr<'_>>,
                frame: Option<Frame>,
            ) -> &'a mut ZendClassObject<$class> {
                let state = &self_.0;
                let name = state.text(name.bytes());
                let (partition, order) = (partitionBy.map(|by| state.strings(&by)), orderBy.map(|by| state.strings(&by)));
                state.with(|builder| {
                    builder.window(name, partition, order, frame.map(|frame| frame.0));
                });
                self_
            }

            pub fn when<'a>(
                self_: &'a mut ZendClassObject<$class>,
                condition: bool,
                callback: Callback<'_>,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                if condition {
                    let mut this = Zval::new();
                    this.set_object(&mut self_.std);
                    ZendCallable::new(callback.0).map_err(PhpException::from)?.try_call(vec![&this]).map(drop).or_else(|_| thrown())?;
                }
                Ok(self_)
            }

            #[php(name = "beforeBuild")]
            pub fn before_build<'a>(
                self_: &'a mut ZendClassObject<$class>,
                callback: Callback<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let callback = Held(callback.0.shallow_clone());
                self_.0.with(|builder| {
                    builder.before_build(move |builder| before::<$class>(builder, &callback));
                });
                self_
            }

            #[php(name = "afterBuild")]
            pub fn after_build<'a>(
                self_: &'a mut ZendClassObject<$class>,
                callback: Callback<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                let callback = Held(callback.0.shallow_clone());
                self_.0.with(|builder| {
                    builder.after_build_owned(move |owner, statement| after::<$class>(owner, statement, &callback));
                });
                self_
            }

            #[php(name = "setExecutor")]
            pub fn set_executor<'a>(
                self_: &'a mut ZendClassObject<$class>,
                executor: Callback<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                *self_.0.executor.borrow_mut() = Some(executor.0.shallow_clone());
                self_
            }

            /// `$this->build()->execute()`: `build()` is the object's own, which a subclass may override.
            pub fn execute(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let built = self_.std.try_call_method("build", vec![]);
                thrown()?;
                let executed = built.map_err(PhpException::from)?.object().map(|built| built.try_call_method("execute", vec![]));
                thrown()?;
                executed.transpose().map_err(PhpException::from).map(|result| result.unwrap_or_else(Zval::new))
            }

            pub fn clone(self_: &mut ZendClassObject<$class>) -> Zval {
                let mut this = Zval::new();
                this.set_object(&mut self_.std);
                clone_object(&this)
            }

            pub fn build(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let built = self_.0.with(Builder::build);
                self_.0.statement(built)
            }

            #[php(name = "compileFilter")]
            pub fn compile_filter(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let query = self_.0.query(query.0)?;
                self_.0.step(Step::CompileFilter, &[Arg::Query(&query)])
            }

            #[php(name = "compileOrder")]
            pub fn compile_order(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.compile(Step::CompileOrder, query.0)
            }

            #[php(name = "compileLimit")]
            pub fn compile_limit(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.compile(Step::CompileLimit, query.0)
            }

            #[php(name = "compileOffset")]
            pub fn compile_offset(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.compile(Step::CompileOffset, query.0)
            }

            #[php(name = "compileSelect")]
            pub fn compile_select(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.compile(Step::CompileSelect, query.0)
            }

            #[php(name = "compileCursor")]
            pub fn compile_cursor(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.compile(Step::CompileCursor, query.0)
            }

            #[php(name = "compileAggregate")]
            pub fn compile_aggregate(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.compile(Step::CompileAggregate, query.0)
            }

            #[php(name = "compileGroupBy")]
            pub fn compile_group_by(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.compile(Step::CompileGroupBy, query.0)
            }

            #[php(name = "compileJoin")]
            pub fn compile_join(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                self_.0.compile(Step::CompileJoin, query.0)
            }

            #[php(name = "compileIn", vis = "protected", optional = column)]
            pub fn compile_in(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
                column: Option<PhpString<'_>>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (values, column) = (self_.0.values(&values)?, self_.0.maybe(column.as_ref()));
                let attribute = self_.0.text(attribute.bytes());
                self_.0.step(Step::CompileIn, &[Arg::Str(&attribute), Arg::Values(&values), Arg::MaybeStr(column.as_deref())])
            }

            #[php(name = "compileNotIn", vis = "protected", optional = column)]
            pub fn compile_not_in(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
                column: Option<PhpString<'_>>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (values, column) = (self_.0.values(&values)?, self_.0.maybe(column.as_ref()));
                let attribute = self_.0.text(attribute.bytes());
                self_.0.step(Step::CompileNotIn, &[Arg::Str(&attribute), Arg::Values(&values), Arg::MaybeStr(column.as_deref())])
            }

            #[php(name = "compileRegex", vis = "protected", optional = column)]
            pub fn compile_regex(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
                column: Option<PhpString<'_>>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (values, column) = (self_.0.values(&values)?, self_.0.maybe(column.as_ref()));
                let attribute = self_.0.text(attribute.bytes());
                self_.0.step(Step::CompileRegex, &[Arg::Str(&attribute), Arg::Values(&values), Arg::MaybeStr(column.as_deref())])
            }

            #[php(name = "compileContains", vis = "protected", optional = column)]
            pub fn compile_contains(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
                column: Option<PhpString<'_>>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (values, column) = (self_.0.values(&values)?, self_.0.maybe(column.as_ref()));
                let attribute = self_.0.text(attribute.bytes());
                self_.0.step(Step::CompileContains, &[Arg::Str(&attribute), Arg::Values(&values), Arg::MaybeStr(column.as_deref())])
            }

            #[php(name = "compileContainsAll", vis = "protected", optional = column)]
            pub fn compile_contains_all(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
                column: Option<PhpString<'_>>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (values, column) = (self_.0.values(&values)?, self_.0.maybe(column.as_ref()));
                let attribute = self_.0.text(attribute.bytes());
                self_.0.step(Step::CompileContainsAll, &[Arg::Str(&attribute), Arg::Values(&values), Arg::MaybeStr(column.as_deref())])
            }

            #[php(name = "compileNotContains", vis = "protected", optional = column)]
            pub fn compile_not_contains(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
                column: Option<PhpString<'_>>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (values, column) = (self_.0.values(&values)?, self_.0.maybe(column.as_ref()));
                let attribute = self_.0.text(attribute.bytes());
                self_.0.step(Step::CompileNotContains, &[Arg::Str(&attribute), Arg::Values(&values), Arg::MaybeStr(column.as_deref())])
            }

            #[php(name = "compileJoinWithBuilder", vis = "protected")]
            pub fn compile_join_with_builder(
                self_: &mut ZendClassObject<$class>,
                query: QueryArgument<'_>,
                joinBuilder: Joined<'_>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (query, join) = (self_.0.query(query.0)?, join_of(<$dialect as Text>::JSON, joinBuilder.0));
                self_.0.step(Step::CompileJoinWithBuilder, &[Arg::Query(&query), Arg::Join(&join)])
            }

            #[php(name = "resolveAttribute", vis = "protected")]
            pub fn resolve_attribute(self_: &mut ZendClassObject<$class>, attribute: PhpString<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let attribute = self_.0.text(attribute.bytes());
                self_.0.step(Step::ResolveAttribute, &[Arg::Str(&attribute)])
            }

            #[php(name = "resolveAndWrap", vis = "protected")]
            pub fn resolve_and_wrap(self_: &mut ZendClassObject<$class>, attribute: PhpString<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let attribute = self_.0.text(attribute.bytes());
                self_.0.step(Step::ResolveAndWrap, &[Arg::Str(&attribute)])
            }

            #[php(vis = "protected")]
            pub fn quote(&self, identifier: PhpString<'_>) -> PhpResult<Bytes> {
                let identifier = self.0.text(identifier.bytes());
                let quoted = self.0.with(|builder| <$dialect as Dialect>::quote(builder, &identifier));
                Ok(Bytes(self.0.written(&quoted.map_err(|error| raised(&error))?)?.into_owned()))
            }


            #[php(name = "getLikeKeyword", vis = "protected")]
            pub fn get_like_keyword(&self) -> &'static str {
                self.0.with(|builder| <$dialect as Dialect>::like_keyword(builder))
            }

            #[php(name = "getBindingValues", vis = "protected")]
            pub fn get_binding_values(&self) -> PhpResult<Zval> {
                self.0.bindings(false)
            }



            #[php(name = "compileLike", vis = "protected", optional = column)]
            pub fn compile_like(
                self_: &mut ZendClassObject<$class>,
                attribute: PhpString<'_>,
                values: Arr<'_>,
                prefix: PhpString<'_>,
                suffix: PhpString<'_>,
                not: bool,
                column: Option<PhpString<'_>>,
            ) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (values, column) = (self_.0.values(&values)?, self_.0.maybe(column.as_ref()));
                let attribute = self_.0.text(attribute.bytes());
                let (prefix, suffix) = (self_.0.text(prefix.bytes()), self_.0.text(suffix.bytes()));
                self_.0.step(
                    Step::CompileLike,
                    &[
                        Arg::Str(&attribute),
                        Arg::Values(&values),
                        Arg::Str(&prefix),
                        Arg::Str(&suffix),
                        Arg::Bool(not),
                        Arg::MaybeStr(column.as_deref()),
                    ],
                )
            }

            #[php(name = "addBinding", vis = "protected", optional = column)]
            pub fn add_binding(
                self_: &mut ZendClassObject<$class>,
                value: &Zval,
                column: Option<PhpString<'_>>,
            ) -> PhpResult<()> {
                let object = std::ptr::from_mut(&mut self_.std);
                self_.0.running(object);
                let (value, column) = (self_.0.value(value)?, self_.0.maybe(column.as_ref()));
                self_.0.step(Step::AddBinding, &[Arg::Value(&value), Arg::MaybeStr(column.as_deref())]).map(drop)
            }

            $($methods)*
        }
    };
}

/// `Utopia\Query\Compiler`: what `Query::compile()` takes. It declares no methods here; the
/// builders that implement it have every one.
#[php_interface]
#[php(name = "Utopia\\Query\\Compiler")]
#[allow(dead_code)]
pub trait Compiler {}

/// `Utopia\Query\Builder`: the parent of every builder. Its methods are the dialects'.
#[php_class]
#[php(name = "Utopia\\Query\\Builder")]
#[php(implements(PhpInterfaceCompiler))]
#[php(flags = ClassFlags::Abstract)]
pub struct Base;

#[php_impl]
impl Base {}

/// `Utopia\Query\Builder\SQL`: the parent of the SQL builders.
#[php_class]
#[php(name = "Utopia\\Query\\Builder\\SQL")]
#[php(extends(Base))]
#[php(flags = ClassFlags::Abstract)]
pub struct Sql;

#[php_impl]
impl Sql {}

builder_class!(
    /// `Utopia\Query\Builder\MySQL`.
    #[php_class]
    #[php(name = "Utopia\\Query\\Builder\\MySQL")]
    #[php(extends(Sql))]
    MySqlBuilder,
    MySqlDialect,
    [sql, spatial, literal, raw_sql, locking, upsert, upsert_select, insert_or_ignore, mysql_family, explain_format, cross_joins, locking_modes, statistical, bitwise, raw_sql_more, rollup, hints, conditional_aggregates, json_aggregates, json, search, not_search, spatial_filters, transactions, max_execution_time, lateral_joins, group_concat, case_expressions, ast, steps_all, steps_sql, steps_aggregates, steps_mysql, helpers, helpers_group_by, helpers_wkt]
);
builder_class!(
    /// `Utopia\Query\Builder\MariaDB`.
    #[php_class]
    #[php(name = "Utopia\\Query\\Builder\\MariaDB")]
    #[php(extends(MySqlBuilder))]
    MariaDbBuilder,
    MariaDbDialect,
    [sql, spatial, literal, raw_sql, locking, upsert, upsert_select, insert_or_ignore, returning, mysql_family, explain_format, cross_joins, locking_modes, statistical, bitwise, raw_sql_more, rollup, hints, conditional_aggregates, json_aggregates, json, search, not_search, sequences, spatial_filters, transactions, max_execution_time, lateral_joins, group_concat, case_expressions, ast, steps_all, steps_sql, steps_aggregates, steps_mysql, helpers, helpers_returning, helpers_group_by, helpers_wkt]
);
builder_class!(
    /// `Utopia\Query\Builder\PostgreSQL`.
    #[php_class]
    #[php(name = "Utopia\\Query\\Builder\\PostgreSQL")]
    #[php(extends(Sql))]
    PostgreSqlBuilder,
    PostgreSqlDialect,
    [sql, spatial, literal, raw_sql, locking, upsert, upsert_select, insert_or_ignore, returning, postgres_from, explain_postgres, cross_joins, full_outer_joins, locking_modes, statistical, bitwise, raw_sql_more, rollup, cube, conditional_aggregates, json_aggregates, json, search, not_search, tablesample, sequences, spatial_filters, transactions, postgres_more, lateral_joins, merge_using, vector_search, group_concat, case_expressions, ast, steps_all, steps_sql, steps_aggregates, steps_postgres, helpers, helpers_returning, helpers_group_by, helpers_wkt]
);
builder_class!(
    /// `Utopia\Query\Builder\SQLite`.
    #[php_class]
    #[php(name = "Utopia\\Query\\Builder\\SQLite")]
    #[php(extends(Sql))]
    SqliteBuilder,
    SqliteDialect,
    [sql, literal, raw_sql, locking, upsert, upsert_select, insert_or_ignore, explain_plain, cross_joins, locking_modes, statistical, bitwise, raw_sql_more, conditional_aggregates, json_aggregates, json, transactions, group_concat, case_expressions, ast, steps_all, steps_sql, steps_aggregates, helpers]
);
builder_class!(
    /// `Utopia\Query\Builder\ClickHouse`.
    #[php_class]
    #[php(name = "Utopia\\Query\\Builder\\ClickHouse")]
    #[php(extends(Base))]
    ClickHouseBuilder,
    ClickHouseDialect,
    [literal, raw_sql, explain_plain, cross_joins, full_outer_joins, statistical_clickhouse, bitwise, raw_sql_more, rollup, cube, totals, hints, conditional_aggregates, json_aggregates, tablesample, clickhouse_more, clickhouse_constants, asof_joins, quantiles, group_concat, case_expressions, bulk_insert, ast, steps_all, steps_aggregates, helpers, helpers_group_by, helpers_named]
);
builder_class!(
    /// `Utopia\Query\Builder\MongoDB`.
    #[php_class]
    #[php(name = "Utopia\\Query\\Builder\\MongoDB")]
    #[php(extends(Base))]
    MongoDbBuilder,
    MongoDbDialect,
    [upsert, insert_or_ignore, explain_plain, search, tablesample, mongo_updates, mongo_stages, mongo_facet, mongo_more, ast, steps_all, helpers]
);
