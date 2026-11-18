//! The query builders. A first slice: `Utopia\Query\Builder\MySQL` with the select path,
//! registered only when `_APP_EXTENSION_LIBRARIES` also names `query-builders`.
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

use std::cell::Cell;
use std::ptr::NonNull;
use std::sync::Arc;

use ext_php_rs::convert::{FromZval, IntoZval};
use ext_php_rs::exception::PhpException;
use ext_php_rs::ffi::{zend_function, zend_hash_str_find};
use ext_php_rs::flags::DataType;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendClassObject, ZendHashTable, ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ce};
use php_std::{ArrayKey as Key, Str};
use utopia_query::builder::{Arg, MySql, Overrides, Ret, Statement, Step};
use utopia_query::{Error, Query, Value};

use super::ast::{failed, narrow, null, wide};
use super::tokenizer::class;
use super::value::{Arr, Int, empty, parts};
use crate::emails::{Text, construct, string, thrown};
use crate::string::PhpString;

const QUERY: &str = "Utopia\\Query\\Query";
const STATEMENT: &str = "Utopia\\Query\\Builder\\Statement";

/// The PHP functions a class declares for the steps it overrides, by [`Step`].
type Declared = [*mut zend_function; Step::ALL.len()];

thread_local! {
    /// The builder object whose method is running, and the steps its class overrides.
    static CURRENT: Cell<(*mut ZendObject, Declared)> = const { Cell::new((std::ptr::null_mut(), [std::ptr::null_mut(); Step::ALL.len()])) };
    /// The steps each class seen this request overrides.
    static CLASSES: std::cell::RefCell<Vec<(*const ClassEntry, Declared)>> = const { std::cell::RefCell::new(Vec::new()) };
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
    let mut functions: Declared = [std::ptr::null_mut(); Step::ALL.len()];
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

fn enter(object: &mut ZendObject) -> Entered {
    let functions = declared(object.get_class_entry());
    Entered(CURRENT.replace((std::ptr::from_mut(object), functions)))
}

impl Drop for Entered {
    fn drop(&mut self) {
        CURRENT.set(self.0);
    }
}

/// A PHP value as the crate's.
fn value(zval: &Zval) -> PhpResult<Value> {
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
        Value::Array(array(found)?)
    } else if parts(zval).is_some() {
        Value::from(query(zval)?)
    } else {
        return Err(PhpException::new(
            "A builder value must be a scalar, an array or a query".to_owned(),
            0,
            ce::type_error(),
        ));
    })
}

fn array(table: &ZendHashTable) -> PhpResult<utopia_query::Array> {
    let mut out = utopia_query::Array::default();
    for (key, item) in table {
        let key = match key {
            ArrayKey::Long(index) => Key::Int(index),
            ArrayKey::String(name) => Key::Str(Str::from(name.as_bytes())),
            ArrayKey::Str(name) => Key::Str(Str::from(name.as_bytes())),
            ArrayKey::ZendString(name) => Key::Str(Str::from(name.as_bytes())),
        };
        out.set(key, value(item)?);
    }
    Ok(out)
}

/// A PHP `Query` as the crate's.
fn query(zval: &Zval) -> PhpResult<Query> {
    let (method, attribute, values, alias, attribute_type, on_array) = parts(zval)
        .ok_or_else(|| PhpException::new(format!("A query must be of type {QUERY}"), 0, ce::type_error()))?;
    let values = values.array().map(array).transpose()?.unwrap_or_default();
    let mut query = Query::new(method, wide(attribute), values, wide(alias));
    query.set_attribute_type(wide(attribute_type));
    query.set_on_array(on_array);
    Ok(query)
}

/// The crate's value as PHP's.
fn zval(value: &Value) -> PhpResult<Zval> {
    let mut out = Zval::new();
    match value {
        Value::Null => {}
        Value::Bool(found) => out.set_bool(*found),
        Value::Int(found) => out.set_long(*found),
        Value::Float(found) => out.set_double(*found),
        Value::Str(found) => return Ok(string(found.as_bytes())),
        Value::Array(found) | Value::Object(found) => return list(found),
        Value::Ext(utopia_query::Object::Query(found)) => return query_object(found),
        Value::Ext(_) => {}
    }
    Ok(out)
}

fn list(values: &utopia_query::Array) -> PhpResult<Zval> {
    if values.is_empty() {
        return Ok(empty());
    }
    let mut table = ZendHashTable::new();
    for (key, item) in values.iter() {
        let item = zval(item)?;
        match key {
            php_std::types::KeyRef::Int(index) => table.insert_at_index(index, item),
            php_std::types::KeyRef::Str(name) => table.insert(String::from_utf8_lossy(name).as_ref(), item),
        }
        .map_err(PhpException::from)?;
    }
    let mut out = Zval::new();
    out.set_hashtable(table);
    Ok(out)
}

/// The crate's query as a PHP `Query`, for an override that is handed one.
fn query_object(found: &Query) -> PhpResult<Zval> {
    let mut arguments = [
        string(found.method().as_str().as_bytes()),
        string(&narrow(found.attribute())),
        list(found.values())?,
        string(&narrow(found.alias())),
    ];
    construct(class(QUERY)?, &mut arguments)?.into_zval(false).map_err(PhpException::from)
}

/// The steps a PHP subclass overrides, called on the object whose method is running.
struct Subclass;

impl Overrides for Subclass {
    fn call(&self, builder: *mut (), step: Step, arguments: &[Arg<'_>]) -> Option<Result<Ret, Error>> {
        let (object, functions) = CURRENT.get();
        let function = functions[step as usize];
        if function.is_null() || object.is_null() {
            return None;
        }
        // SAFETY: `enter()` set the object for the length of the native method that is running.
        let state = ZendClassObject::<MySqlBuilder>::from_zend_obj(unsafe { &*object })?;
        // The override reaches the builder through the pointer the crate lent for this call.
        let before = state.lent.replace(builder.cast());
        let returned = overridden(object, function, arguments);
        state.lent.set(before);
        // An exception stays pending for the native method that started the build to rethrow.
        Some(returned.ok_or_else(|| Error::Validation(format!("{}() failed", step.method()))))
    }
}

/// Calls `function`, a PHP method of `object`'s class, as `$this->function(...)` does.
fn overridden(object: *mut ZendObject, function: *mut zend_function, arguments: &[Arg<'_>]) -> Option<Ret> {
    let mut zvals = Vec::with_capacity(arguments.len());
    for argument in arguments {
        zvals.push(match argument {
            Arg::Str(text) => string(&narrow(text)),
            Arg::MaybeStr(text) => text.map_or_else(null, |text| string(&narrow(text))),
            Arg::Bool(flag) => {
                let mut out = Zval::new();
                out.set_bool(*flag);
                out
            }
            Arg::Values(values) => list(values).ok()?,
            Arg::Value(found) => zval(found).ok()?,
            Arg::Query(found) => query_object(found).ok()?,
        });
    }
    let mut returned = Zval::new();
    // SAFETY: `object` is the live builder whose method is running; `function` is a method of
    // its class, found in its function table this request, and the arguments are initialized
    // and outlive the call.
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
    if !ext_php_rs::zend::ExecutorGlobals::get().exception.is_null() {
        return None;
    }
    Some(match returned.zend_str() {
        Some(text) => Ret::Str(wide(text.as_bytes()).into_owned()),
        None => Ret::None,
    })
}

/// `Utopia\Query\Builder\MySQL`.
#[php_class]
#[php(name = "Utopia\\Query\\Builder\\MySQL")]
pub struct MySqlBuilder {
    own: NonNull<MySql>,
    lent: Cell<*mut MySql>,
}

impl Drop for MySqlBuilder {
    fn drop(&mut self) {
        // SAFETY: `own` came from `Box::into_raw` and is freed here only.
        drop(unsafe { Box::from_raw(self.own.as_ptr()) });
    }
}

impl MySqlBuilder {
    /// Runs `work` on the builder: the one the crate lent when an override is running.
    fn with<T>(&self, work: impl FnOnce(&mut MySql) -> T) -> T {
        let lent = self.lent.get();
        let builder = if lent.is_null() { self.own.as_ptr() } else { lent };
        // SAFETY: the lent pointer is the `&mut` of the build that is waiting on the override
        // which made this call; without one, nothing else is using the builder.
        work(unsafe { &mut *builder })
    }
}

/// The error of a build: the exception an override left pending, or the crate's own.
fn raised(error: &Error) -> PhpException {
    match thrown() {
        Err(pending) => pending,
        Ok(()) => failed(error),
    }
}

/// A step that returns a string, run as the builder itself does.
fn step(object: &MySqlBuilder, step: Step, arguments: &[Arg<'_>]) -> PhpResult<Text> {
    match object.with(|builder| builder.base(step, arguments)).map_err(|error| raised(&error))? {
        Ret::Str(text) => Ok(Text(narrow(&text).into_owned())),
        Ret::None => Ok(Text(Vec::new())),
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

fn statement(found: &Statement) -> PhpResult<Zval> {
    let mut bindings = Vec::with_capacity(found.bindings.len());
    for binding in &found.bindings {
        bindings.push(zval(binding)?);
    }
    let mut read_only = Zval::new();
    read_only.set_bool(found.read_only);
    let bindings = if bindings.is_empty() { empty() } else { bindings.into_zval(false).map_err(PhpException::from)? };
    construct(class(STATEMENT)?, &mut [string(&narrow(&found.query)), bindings, read_only])?
        .into_zval(false)
        .map_err(PhpException::from)
}

#[php_impl]
impl MySqlBuilder {
    pub fn __construct() -> Self {
        let mut builder = MySql::new();
        builder.set_overrides(Arc::new(Subclass));
        Self { own: NonNull::from(Box::leak(Box::new(builder))), lent: Cell::new(std::ptr::null_mut()) }
    }

    #[php(defaults(alias = "\"\""))]
    pub fn from<'a>(
        self_: &'a mut ZendClassObject<MySqlBuilder>,
        table: PhpString<'_>,
        alias: PhpString<'_>,
    ) -> &'a mut ZendClassObject<MySqlBuilder> {
        self_.with(|builder| {
            builder.from(wide(table.bytes()), wide(alias.bytes()));
        });
        self_
    }

    pub fn select<'a>(
        self_: &'a mut ZendClassObject<MySqlBuilder>,
        columns: Arr<'_>,
    ) -> PhpResult<&'a mut ZendClassObject<MySqlBuilder>> {
        let columns: Vec<String> = columns
            .table()
            .into_iter()
            .flat_map(ZendHashTable::values)
            .filter_map(|column| column.zend_str().map(|name| wide(name.as_bytes()).into_owned()))
            .collect();
        self_.with(|builder| {
            builder.select(columns);
        });
        Ok(self_)
    }

    pub fn filter<'a>(
        self_: &'a mut ZendClassObject<MySqlBuilder>,
        queries: Arr<'_>,
    ) -> PhpResult<&'a mut ZendClassObject<MySqlBuilder>> {
        let queries =
            queries.table().into_iter().flat_map(ZendHashTable::values).map(query).collect::<PhpResult<Vec<_>>>()?;
        self_.with(|builder| {
            builder.filter(queries);
        });
        Ok(self_)
    }

    #[php(name = "sortDesc")]
    pub fn sort_desc<'a>(
        self_: &'a mut ZendClassObject<MySqlBuilder>,
        attribute: PhpString<'_>,
    ) -> &'a mut ZendClassObject<MySqlBuilder> {
        self_.with(|builder| {
            builder.sort_desc(wide(attribute.bytes()), None);
        });
        self_
    }

    pub fn limit(self_: &mut ZendClassObject<MySqlBuilder>, value: Int) -> &mut ZendClassObject<MySqlBuilder> {
        self_.with(|builder| {
            builder.limit(value.0);
        });
        self_
    }

    pub fn offset(self_: &mut ZendClassObject<MySqlBuilder>, value: Int) -> &mut ZendClassObject<MySqlBuilder> {
        self_.with(|builder| {
            builder.offset(value.0);
        });
        self_
    }

    pub fn build(self_: &mut ZendClassObject<MySqlBuilder>) -> PhpResult<Zval> {
        let _running = enter(&mut self_.std);
        let built = self_.with(utopia_query::builder::Builder::build).map_err(|error| raised(&error))?;
        statement(&built)
    }

    #[php(name = "compileFilter")]
    pub fn compile_filter(self_: &mut ZendClassObject<MySqlBuilder>, query: QueryArgument<'_>) -> PhpResult<Text> {
        let _running = enter(&mut self_.std);
        let query = self::query(query.0)?;
        step(self_, Step::CompileFilter, &[Arg::Query(&query)])
    }

    #[php(name = "compileIn", vis = "protected", optional = column)]
    pub fn compile_in(
        self_: &mut ZendClassObject<MySqlBuilder>,
        attribute: PhpString<'_>,
        values: Arr<'_>,
        column: Option<PhpString<'_>>,
    ) -> PhpResult<Text> {
        let _running = enter(&mut self_.std);
        let values = values.table().map(array).transpose()?.unwrap_or_default();
        let column = column.as_ref().map(|column| wide(column.bytes()));
        step(
            self_,
            Step::CompileIn,
            &[Arg::Str(&wide(attribute.bytes())), Arg::Values(&values), Arg::MaybeStr(column.as_deref())],
        )
    }

    #[php(name = "compileLike", vis = "protected", optional = column)]
    pub fn compile_like(
        self_: &mut ZendClassObject<MySqlBuilder>,
        attribute: PhpString<'_>,
        values: Arr<'_>,
        prefix: PhpString<'_>,
        suffix: PhpString<'_>,
        not: bool,
        column: Option<PhpString<'_>>,
    ) -> PhpResult<Text> {
        let _running = enter(&mut self_.std);
        let values = values.table().map(array).transpose()?.unwrap_or_default();
        let column = column.as_ref().map(|column| wide(column.bytes()));
        step(
            self_,
            Step::CompileLike,
            &[
                Arg::Str(&wide(attribute.bytes())),
                Arg::Values(&values),
                Arg::Str(&wide(prefix.bytes())),
                Arg::Str(&wide(suffix.bytes())),
                Arg::Bool(not),
                Arg::MaybeStr(column.as_deref()),
            ],
        )
    }

    #[php(name = "addBinding", vis = "protected", optional = column)]
    pub fn add_binding(
        self_: &mut ZendClassObject<MySqlBuilder>,
        value: &Zval,
        column: Option<PhpString<'_>>,
    ) -> PhpResult<()> {
        let _running = enter(&mut self_.std);
        let value = self::value(value)?;
        let column = column.as_ref().map(|column| wide(column.bytes()));
        step(self_, Step::AddBinding, &[Arg::Value(&value), Arg::MaybeStr(column.as_deref())]).map(drop)
    }
}
