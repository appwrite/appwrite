//! The query builders, registered only when `_APP_EXTENSION_LIBRARIES` also names
//! `query-builders`: they are still being moved, and have only part of their methods.
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

#![allow(non_snake_case)]

use std::borrow::Cow;
use std::cell::{Cell, RefCell};
use std::marker::PhantomData;
use std::ptr::NonNull;
use std::sync::Arc;

use ext_php_rs::class::RegisteredClass;
use ext_php_rs::convert::{FromZval, IntoZval};
use ext_php_rs::exception::PhpException;
use ext_php_rs::ffi::{zend_function, zend_hash_str_find};
use ext_php_rs::flags::{ClassFlags, DataType};
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendClassObject, ZendHashTable, ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecutorGlobals, ce};
use php_std::types::KeyRef;
use php_std::{ArrayKey as Key, Str};
use utopia_query::builder::{
    Arg, Builder, ClickHouseDialect, Dialect, MariaDbDialect, MongoDbDialect, MySqlDialect, Overrides,
    PostgreSqlDialect, Ret, SqliteDialect, Statement, Step,
};
use utopia_query::{Array, Error, NullsPosition, Object, Query, Value};

use super::ast::{failed, narrow, null, slots, wide};
use super::tokenizer::class;
use super::value::{Arr, Bool, Int, empty, parts};
use crate::emails::{Text as Bytes, construct, string, thrown};
use crate::string::PhpString;

const QUERY: &str = "Utopia\\Query\\Query";
const STATEMENT: &str = "Utopia\\Query\\Builder\\Statement";

/// The PHP functions a class declares for the steps it overrides, by [`Step`].
type Declared = [*mut zend_function; Step::ALL.len()];

const NONE: Declared = [std::ptr::null_mut(); Step::ALL.len()];

thread_local! {
    /// The builder object whose method is running, and the steps its class overrides.
    static CURRENT: Cell<(*mut ZendObject, Declared)> = const { Cell::new((std::ptr::null_mut(), NONE)) };
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

/// Makes `object` the builder whose method is running, until it is dropped.
struct Entered((*mut ZendObject, Declared));

impl Drop for Entered {
    fn drop(&mut self) {
        CURRENT.set(self.0);
    }
}

/// Whether a dialect's statements are JSON, which is UTF-8, or SQL, which is bytes.
pub trait Text {
    /// MongoDB's are JSON.
    const JSON: bool = false;
}

impl Text for MySqlDialect {}
impl Text for MariaDbDialect {}
impl Text for PostgreSqlDialect {}
impl Text for SqliteDialect {}
impl Text for ClickHouseDialect {}
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
    construct(class(QUERY)?, &mut arguments)?.into_zval(false).map_err(PhpException::from)
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
        }
    }

    /// Runs `work` on the builder: the one the crate lent when an override is running.
    fn with<T>(&self, work: impl FnOnce(&mut Builder<D>) -> T) -> T {
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

    /// Makes `object` the builder whose method is running, and names its class to the crate
    /// when it is a subclass, as PHP's messages name `static::class`.
    fn running(&self, object: *mut ZendObject) -> Entered {
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
        Entered(CURRENT.replace((object, declared(class))))
    }

    fn values(&self, given: &Arr<'_>) -> PhpResult<Array> {
        Ok(given.table().map(|table| self.array(table)).transpose()?.unwrap_or_default())
    }

    /// The strings of an array argument.
    fn strings(&self, given: &Arr<'_>) -> Vec<String> {
        let items = given.table().into_iter().flat_map(ZendHashTable::values);
        items.filter_map(|item| item.zend_str().map(|name| self.text(name.as_bytes()))).collect()
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
    fn bindings(&self) -> PhpResult<Zval> {
        let bindings = self.with(|builder| builder.bindings());
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
        let query = self.written(&found.query)?;
        let mut bindings = Vec::with_capacity(found.bindings.len());
        for binding in &found.bindings {
            bindings.push(zval(D::JSON, binding)?);
        }
        let mut read_only = Zval::new();
        read_only.set_bool(found.read_only);
        let bindings =
            if bindings.is_empty() { empty() } else { bindings.into_zval(false).map_err(PhpException::from)? };
        construct(class(STATEMENT)?, &mut [string(&query), bindings, read_only])?
            .into_zval(false)
            .map_err(PhpException::from)
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
            Ret::None => Ok(Bytes(Vec::new())),
        }
    }

    /// Calls `function`, a PHP method of `object`'s class, as `$this->function(...)` does.
    fn overridden(&self, object: *mut ZendObject, function: *mut zend_function, arguments: &[Arg<'_>]) -> Option<Ret> {
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
            });
        }
        let mut returned = Zval::new();
        // SAFETY: `object` is the live builder whose method is running; `function` is a
        // method of its class, found in its function table this request, and the arguments
        // are initialized and outlive the call.
        unsafe {
            ext_php_rs::ffi::zend_call_known_function(
                function,
                object,
                (*object).ce,
                &raw mut returned,
                u32::try_from(zvals.len()).unwrap_or(u32::MAX),
                zvals.as_mut_ptr(),
                std::ptr::null_mut(),
            );
        }
        if !ExecutorGlobals::get().exception.is_null() {
            return None;
        }
        Some(match returned.zend_str() {
            Some(text) => Ret::Str(self.text(text.as_bytes())),
            None => Ret::None,
        })
    }
}

impl<D: Dialect + Text> Clone for State<D> {
    /// `clone $builder`: a copy of the builder as it is now.
    fn clone(&self) -> Self {
        Self::of(self.with(|builder| builder.clone()), self.malformed.get())
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
}

/// The steps a PHP subclass of `C` overrides, called on the object whose method is running.
struct Subclass<C>(PhantomData<fn() -> C>);

impl<C: Native> Overrides for Subclass<C> {
    fn call(&self, builder: *mut (), step: Step, arguments: &[Arg<'_>]) -> Option<Result<Ret, Error>> {
        let (object, functions) = CURRENT.get();
        let function = functions[step as usize];
        if function.is_null() || object.is_null() {
            return None;
        }
        // SAFETY: `enter()` set the object for the length of the native method that is running.
        let state = ZendClassObject::<C>::from_zend_obj(unsafe { &*object })?.state();
        // The override reaches the builder through the pointer the crate lent for this call.
        let before = state.lent.replace(builder.cast());
        let returned = state.overridden(object, function, arguments);
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
    ($(#[$meta:meta])* $class:ident, $dialect:ty) => {
        $(#[$meta])*
        #[derive(Clone)]
        pub struct $class(State<$dialect>);

        impl Native for $class {
            type Dialect = $dialect;

            fn state(&self) -> &State<$dialect> {
                &self.0
            }
        }

        #[php_impl]
        impl $class {
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
                self_.0.change(|builder| builder.page(page.0, perPage.0).map(drop))?;
                Ok(self_)
            }

            #[php(name = "cursorAfter")]
            pub fn cursor_after<'a>(
                self_: &'a mut ZendClassObject<$class>,
                value: &Zval,
            ) -> PhpResult<&'a mut ZendClassObject<$class>> {
                let value = self_.0.value(value)?;
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
                let value = self_.0.value(value)?;
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
                let _running = self_.0.running(object);
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
                self_.0.with(|builder| {
                    builder.reset();
                });
                self_
            }

            #[php(name = "getBindings")]
            pub fn get_bindings(&self) -> PhpResult<Zval> {
                self.0.bindings()
            }

            #[php(name = "toRawSql")]
            pub fn to_raw_sql(self_: &mut ZendClassObject<$class>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                self_.0.raw()
            }

            #[php(defaults(analyze = "Bool(false)"))]
            pub fn explain(self_: &mut ZendClassObject<$class>, analyze: Bool) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                let built = self_.0.with(|builder| builder.explain(analyze.0));
                self_.0.statement(built)
            }

            pub fn insert(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                let built = self_.0.with(Builder::insert);
                self_.0.statement(built)
            }

            #[php(name = "insertDefaultValues")]
            pub fn insert_default_values(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                let built = self_.0.with(Builder::insert_default_values);
                self_.0.statement(built)
            }

            pub fn update(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                let built = self_.0.with(Builder::update);
                self_.0.statement(built)
            }

            pub fn delete(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                let built = self_.0.with(Builder::delete);
                self_.0.statement(built)
            }

            pub fn build(self_: &mut ZendClassObject<$class>) -> PhpResult<Zval> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                let built = self_.0.with(Builder::build);
                self_.0.statement(built)
            }

            #[php(name = "compileFilter")]
            pub fn compile_filter(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                let query = self_.0.query(query.0)?;
                self_.0.step(Step::CompileFilter, &[Arg::Query(&query)])
            }

            #[php(name = "compileOrder")]
            pub fn compile_order(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                self_.0.compile(Step::CompileOrder, query.0)
            }

            #[php(name = "compileLimit")]
            pub fn compile_limit(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                self_.0.compile(Step::CompileLimit, query.0)
            }

            #[php(name = "compileOffset")]
            pub fn compile_offset(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                self_.0.compile(Step::CompileOffset, query.0)
            }

            #[php(name = "compileSelect")]
            pub fn compile_select(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                self_.0.compile(Step::CompileSelect, query.0)
            }

            #[php(name = "compileCursor")]
            pub fn compile_cursor(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                self_.0.compile(Step::CompileCursor, query.0)
            }

            #[php(name = "compileAggregate")]
            pub fn compile_aggregate(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                self_.0.compile(Step::CompileAggregate, query.0)
            }

            #[php(name = "compileGroupBy")]
            pub fn compile_group_by(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
                self_.0.compile(Step::CompileGroupBy, query.0)
            }

            #[php(name = "compileJoin")]
            pub fn compile_join(self_: &mut ZendClassObject<$class>, query: QueryArgument<'_>) -> PhpResult<Bytes> {
                let object = std::ptr::from_mut(&mut self_.std);
                let _running = self_.0.running(object);
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
                let _running = self_.0.running(object);
                let (values, column) = (self_.0.values(&values)?, self_.0.maybe(column.as_ref()));
                let attribute = self_.0.text(attribute.bytes());
                self_.0.step(Step::CompileIn, &[Arg::Str(&attribute), Arg::Values(&values), Arg::MaybeStr(column.as_deref())])
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
                let _running = self_.0.running(object);
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
                let _running = self_.0.running(object);
                let (value, column) = (self_.0.value(value)?, self_.0.maybe(column.as_ref()));
                self_.0.step(Step::AddBinding, &[Arg::Value(&value), Arg::MaybeStr(column.as_deref())]).map(drop)
            }
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
    MySqlDialect
);
builder_class!(
    /// `Utopia\Query\Builder\MariaDB`.
    #[php_class]
    #[php(name = "Utopia\\Query\\Builder\\MariaDB")]
    #[php(extends(MySqlBuilder))]
    MariaDbBuilder,
    MariaDbDialect
);
builder_class!(
    /// `Utopia\Query\Builder\PostgreSQL`.
    #[php_class]
    #[php(name = "Utopia\\Query\\Builder\\PostgreSQL")]
    #[php(extends(Sql))]
    PostgreSqlBuilder,
    PostgreSqlDialect
);
builder_class!(
    /// `Utopia\Query\Builder\SQLite`.
    #[php_class]
    #[php(name = "Utopia\\Query\\Builder\\SQLite")]
    #[php(extends(Sql))]
    SqliteBuilder,
    SqliteDialect
);
builder_class!(
    /// `Utopia\Query\Builder\ClickHouse`.
    #[php_class]
    #[php(name = "Utopia\\Query\\Builder\\ClickHouse")]
    #[php(extends(Base))]
    ClickHouseBuilder,
    ClickHouseDialect
);
builder_class!(
    /// `Utopia\Query\Builder\MongoDB`.
    #[php_class]
    #[php(name = "Utopia\\Query\\Builder\\MongoDB")]
    #[php(extends(Base))]
    MongoDbBuilder,
    MongoDbDialect
);
