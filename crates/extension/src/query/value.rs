//! `Utopia\Query\NativeQuery`: what `Utopia\Query\Query` is over when the library is on. The
//! package declares `Query` as a subclass of it (`packages/query/src/extension.php`) to hold the
//! two constants that are arrays, which a class registered when the module starts cannot.
//!
//! A query's values are PHP values (any value, nested queries among them), so the binding
//! keeps them as PHP holds them: a nested query stays the same object, and what a method
//! returns is what was stored.

// Parameters carry the PHP names, which callers pass as named arguments.
#![allow(non_snake_case)]

use std::cell::Cell;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::class::RegisteredClass;
use ext_php_rs::convert::{FromZval, IntoZval, StubLiteral};
use ext_php_rs::exception::PhpException;
use ext_php_rs::flags::DataType;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendClassObject, ZendHashTable, ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ExecutorGlobals, ce};
use php_std::json;
use utopia_query::Method;

use crate::emails::{construct, string, thrown};
use crate::string::Bytes;
use crate::string::PhpString;

/// A class of the PHP package, loaded through the autoloader.
pub(super) fn class(name: &str) -> PhpResult<&'static ClassEntry> {
    ClassEntry::try_find(name).ok_or_else(|| PhpException::new(format!("Class \"{name}\" not found"), 0, ce::error()))
}

/// The extension's class, which `Utopia\Query\Query` and every PHP query class extend.
fn base() -> &'static ClassEntry {
    Query::get_metadata().ce()
}

/// The class a static method was called on (`static`), or `Query` itself.
fn called_scope() -> &'static ClassEntry {
    // SAFETY: inside a method call the engine's current frame is this method's, and for a
    // static call its `This` holds the called class (`zend_get_called_scope` reads the same).
    unsafe {
        let Some(frame) = ExecutorGlobals::get().current_execute_data.as_ref() else { return base() };
        if let Some(object) = frame.This.object() {
            return object.get_class_entry();
        }
        frame.This.value.ce.as_ref().unwrap_or_else(|| base())
    }
}

thread_local! {
    /// The `Method` enum's class entry and case objects once found this request: every
    /// query is made with one and asked for one.
    static METHOD_CLASS: Cell<*const ClassEntry> = const { Cell::new(std::ptr::null()) };
    static METHOD_CASES: [Cell<*mut ZendObject>; Method::CASES.len()] =
        const { [const { Cell::new(std::ptr::null_mut()) }; Method::CASES.len()] };
}

/// Forgets the enum at the end of a request, when PHP frees user classes and their cases.
pub fn request_shutdown() {
    METHOD_CLASS.with(|class| class.set(std::ptr::null()));
    METHOD_CASES.with(|cases| cases.iter().for_each(|case| case.set(std::ptr::null_mut())));
}

/// The `Method` enum's class.
fn method_class() -> PhpResult<&'static ClassEntry> {
    let cached = METHOD_CLASS.with(Cell::get);
    // SAFETY: a cached entry was found this request and lives until its shutdown clears it.
    if let Some(class) = unsafe { cached.as_ref() } {
        return Ok(class);
    }
    let class = class(Method::PHP_CLASS)?;
    METHOD_CLASS.with(|cell| cell.set(class));
    Ok(class)
}

/// The `Method` case object of `method`.
fn method_case(method: Method) -> PhpResult<Zval> {
    let mut case = METHOD_CASES.with(|cases| cases[method as usize].get());
    if case.is_null() {
        let class = method_class()?;
        let mut name = ZendStr::new(method.name(), false);
        // SAFETY: `class` is the loaded enum and `method.name()` one of its cases; the case
        // object lives as long as the class does this request.
        case = unsafe { ext_php_rs::ffi::zend_enum_get_case(std::ptr::from_ref(class).cast_mut(), &raw mut *name) };
        METHOD_CASES.with(|cases| cases[method as usize].set(case));
    }
    let mut zval = Zval::new();
    // SAFETY: the pointer is a case object of the loaded enum, found this request.
    zval.set_object(unsafe { &mut *case });
    Ok(zval)
}

/// A `Method|string` argument: the case itself, or the case with that backing value.
/// Anything else is what `Method::from()` throws for it.
fn method_of(zval: &Zval) -> PhpResult<Method> {
    if let Some(method) = zval.object().and_then(case_method) {
        return Ok(method);
    }
    let Some(value) = PhpString::from_zval(zval) else {
        return Err(type_error("Argument #1 ($method) must be of type Utopia\\Query\\Method|string"));
    };
    if let Some(method) = std::str::from_utf8(value.bytes()).ok().and_then(Method::try_from_value) {
        return Ok(method);
    }
    match call("Utopia\\Query\\Method::from", vec![&string(value.bytes())]) {
        Ok(case) => case.object().and_then(case_method).ok_or_else(|| type_error("Invalid query method")),
        Err(Some(exception)) => {
            let thrown = exception.into_zval(false).map_err(PhpException::from)?;
            Err(PhpException::from_message(String::new()).with_object(thrown))
        }
        Err(None) => Err(type_error("Invalid query method")),
    }
}

/// `[]`: PHP's shared empty array, which is never counted or freed. A zval of type
/// `IS_ARRAY` without the refcounted flag is how `ZVAL_EMPTY_ARRAY` points at it.
pub(super) fn empty() -> Zval {
    let mut zval = Zval::new();
    zval.value.arr = (&raw const ext_php_rs::ffi::zend_empty_array).cast_mut();
    zval.u1.type_info = 7;
    zval
}

/// A PHP list of `items`.
fn list(items: impl IntoIterator<Item = Zval>) -> PhpResult<Zval> {
    let mut items = items.into_iter().peekable();
    if items.peek().is_none() {
        return Ok(empty());
    }
    let mut table = ZendHashTable::new();
    for item in items {
        table.push(item).map_err(PhpException::from)?;
    }
    let mut zval = Zval::new();
    zval.set_hashtable(table);
    Ok(zval)
}

/// An `array` parameter, kept as PHP holds it: storing it shares the array, as PHP does.
/// `Empty` is the `[]` an optional one defaults to.
pub enum Arr<'a> {
    Given(&'a Zval),
    Empty,
}

impl<'a> FromZval<'a> for Arr<'a> {
    const TYPE: DataType = DataType::Array;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        zval.is_array().then_some(Self::Given(zval))
    }
}

impl StubLiteral for Arr<'_> {
    fn stub_literal(&self) -> String {
        "[]".to_owned()
    }
}

impl<'a> Arr<'a> {
    fn held(&self) -> Zval {
        match self {
            Self::Given(zval) => zval.shallow_clone(),
            Self::Empty => list([]).unwrap_or_else(|_| Zval::new()),
        }
    }

    pub(super) fn table(&self) -> Option<&'a ZendHashTable> {
        match self {
            Self::Given(zval) => zval.array(),
            Self::Empty => None,
        }
    }

    fn values(&self) -> impl Iterator<Item = &'a Zval> {
        self.table().into_iter().flat_map(ZendHashTable::values)
    }
}

/// An `int` parameter, converted the way PHP converts one outside `strict_types`: a bool, a
/// float or a numeric string becomes the int it holds; anything else is a `TypeError`.
pub struct Int(pub(super) i64);

impl StubLiteral for Int {
    fn stub_literal(&self) -> String {
        self.0.to_string()
    }
}

impl<'a> FromZval<'a> for Int {
    const TYPE: DataType = DataType::Long;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        if let Some(long) = zval.long() {
            return Some(Self(long));
        }
        if let Some(bool) = zval.bool() {
            return Some(Self(i64::from(bool)));
        }
        let number = match zval.double() {
            Some(number) => number,
            None => {
                zval.zend_str()?;
                if !call("is_numeric", vec![zval]).ok()?.bool()? {
                    return None;
                }
                if let Some(long) = call("filter_var", vec![&call("trim", vec![zval]).ok()?, &257i64]).ok()?.long() {
                    return Some(Self(long));
                }
                call("floatval", vec![zval]).ok()?.double()?
            }
        };
        #[allow(clippy::cast_precision_loss, clippy::cast_possible_truncation)]
        if number.is_finite() && number >= i64::MIN as f64 && number < i64::MAX as f64 {
            Some(Self(number as i64))
        } else {
            None
        }
    }
}

/// A `bool` parameter, converted the way PHP converts one outside `strict_types`.
#[derive(Clone, Copy)]
pub struct Bool(pub(super) bool);

impl StubLiteral for Bool {
    fn stub_literal(&self) -> String {
        self.0.to_string()
    }
}

impl<'a> FromZval<'a> for Bool {
    const TYPE: DataType = DataType::Bool;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        if let Some(bool) = zval.bool() {
            return Some(Self(bool));
        }
        if let Some(long) = zval.long() {
            return Some(Self(long != 0));
        }
        if let Some(double) = zval.double() {
            return Some(Self(double != 0.0));
        }
        zval.zend_str().map(|text| Self(!text.as_bytes().is_empty() && text.as_bytes() != b"0"))
    }
}

fn type_error(message: &str) -> PhpException {
    PhpException::new(message.to_owned(), 0, ce::type_error())
}

/// An exception of the PHP package's class `name`.
fn thrown_as(name: &str, message: &[u8]) -> PhpException {
    let class = ClassEntry::try_find(name).unwrap_or_else(ce::exception);
    match construct(class, &mut [string(message)])
        .and_then(|object| object.into_zval(false).map_err(PhpException::from))
    {
        Ok(object) => PhpException::new(String::from_utf8_lossy(message).into_owned(), 0, class).with_object(object),
        Err(failed) => failed,
    }
}

/// `Utopia\Query\Exception`.
fn invalid(message: impl AsRef<[u8]>) -> PhpException {
    thrown_as("Utopia\\Query\\Exception", message.as_ref())
}

/// `Utopia\Query\Exception\ValidationException`.
fn refused(message: impl AsRef<[u8]>) -> PhpException {
    thrown_as("Utopia\\Query\\Exception\\ValidationException", message.as_ref())
}

fn long(value: i64) -> Zval {
    let mut zval = Zval::new();
    zval.set_long(value);
    zval
}

fn boolean(value: bool) -> Zval {
    let mut zval = Zval::new();
    zval.set_bool(value);
    zval
}

/// A `string|int|float|bool` argument.
fn scalar(zval: &Zval) -> PhpResult<Zval> {
    if zval.is_string() || zval.is_long() || zval.is_double() || zval.is_bool() {
        return Ok(zval.shallow_clone());
    }
    match PhpString::from_zval(zval) {
        Some(converted) => Ok(string(converted.bytes())),
        None => Err(type_error("Argument must be of type string|int|float|bool")),
    }
}

/// An `int|float` argument, converted the way PHP converts one outside `strict_types`.
fn number(zval: &Zval) -> PhpResult<Zval> {
    if zval.is_long() || zval.is_double() {
        return Ok(zval.shallow_clone());
    }
    if let Some(bool) = zval.bool() {
        return Ok(long(i64::from(bool)));
    }
    let numeric =
        zval.is_string() && call("is_numeric", vec![zval]).ok().and_then(|numeric| numeric.bool()) == Some(true);
    if numeric {
        let trimmed = call("trim", vec![zval]).map_err(|_| type_error("Argument must be of type int|float"))?;
        if let Some(whole) = call("filter_var", vec![&trimmed, &257i64]).ok().and_then(|whole| whole.long()) {
            return Ok(long(whole));
        }
        if let Ok(float) = call("floatval", vec![zval]) {
            return Ok(float);
        }
    }
    Err(type_error("Argument must be of type int|float"))
}

/// An `array|object` parameter. It is declared `array`, the nearest type an extension method
/// can declare that an override taking `array|object` (as `Utopia\Database\Query` has) is
/// compatible with; an object is accepted all the same.
pub struct Row<'a>(&'a Zval);

impl<'a> FromZval<'a> for Row<'a> {
    const TYPE: DataType = DataType::Array;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        (zval.is_array() || zval.is_object()).then_some(Self(zval))
    }
}

/// The values of an order query: its `?NullsPosition`, when given.
fn nulls_position(nulls: Option<&Zval>) -> PhpResult<Zval> {
    match nulls {
        None => list([]),
        Some(nulls) if nulls.is_null() => list([]),
        Some(nulls) => {
            let class = class("Utopia\\Query\\NullsPosition")?;
            if nulls.object().is_some_and(|object| object.instance_of(class)) {
                list([nulls.shallow_clone()])
            } else {
                Err(type_error("Argument #2 ($nulls) must be of type ?Utopia\\Query\\NullsPosition"))
            }
        }
    }
}

/// PHP `gettype()`.
fn gettype(zval: &Zval) -> &'static str {
    if zval.is_null() {
        "NULL"
    } else if zval.is_bool() {
        "boolean"
    } else if zval.is_long() {
        "integer"
    } else if zval.is_double() {
        "double"
    } else if zval.is_string() {
        "string"
    } else if zval.is_array() {
        "array"
    } else if zval.is_object() {
        "object"
    } else {
        "unknown type"
    }
}

/// The bytes of a string `zval`.
fn bytes(zval: &Zval) -> Option<&[u8]> {
    zval.zend_str().map(ZendStr::as_bytes)
}

/// `$table[$key] ?? null`: the element, unless it is missing or null.
fn element<'a>(table: &'a ZendHashTable, key: &str) -> Option<&'a Zval> {
    table.get(key).filter(|value| !value.is_null())
}

/// `clone $object`.
fn clone_object(zval: &Zval) -> Zval {
    let Some(object) = zval.object() else { return zval.shallow_clone() };
    // SAFETY: `clone_obj` is the handler `clone` calls, on a live object; it returns a new
    // object, which the box then owns and hands to the zval.
    unsafe {
        match (*object.handlers).clone_obj {
            Some(clone) => ZBox::from_raw(clone(std::ptr::from_ref(object).cast_mut()))
                .into_zval(false)
                .unwrap_or_else(|_| zval.shallow_clone()),
            None => zval.shallow_clone(),
        }
    }
}

/// A PHP function called with `arguments`: its result, or the exception it threw, taken.
pub(super) fn call(
    function: &str,
    arguments: Vec<&dyn ext_php_rs::convert::IntoZvalDyn>,
) -> Result<Zval, Option<ZBox<ZendObject>>> {
    let Ok(callable) = ZendCallable::try_from_name(function) else { return Err(None) };
    let result = callable.try_call(arguments);
    match ExecutorGlobals::take_exception() {
        Some(exception) => Err(Some(exception)),
        None => result.map_err(|_| None),
    }
}

/// The message of an exception object.
fn message_of(exception: &ZendObject) -> Vec<u8> {
    exception
        .try_call_method("getMessage", vec![])
        .ok()
        .as_ref()
        .and_then(bytes)
        .map(<[u8]>::to_vec)
        .unwrap_or_default()
}

/// The query behind `zval`, or the `Error` PHP raises calling a method on something else.
fn required(zval: &Zval) -> PhpResult<&ZendClassObject<Query>> {
    query_of(zval).ok_or_else(|| {
        PhpException::new(format!("Call to a member function getMethod() on {}", gettype(zval)), 0, ce::error())
    })
}

/// The query behind `zval`, or the `TypeError` a `Query`-typed parameter raises.
fn typed(zval: &Zval) -> PhpResult<&ZendClassObject<Query>> {
    query_of(zval).ok_or_else(|| type_error("Argument #1 ($q) must be of type Utopia\\Query\\Query"))
}

/// `$query->toArray()`: the class's own when it declares one in PHP, as a subclass such as
/// `Utopia\Database\Query` does.
fn array_of(zval: &Zval) -> PhpResult<Zval> {
    let object = zval.object().ok_or_else(|| type_error("toArray() on a value that is not a query"))?;
    if overridden(object.get_class_entry(), "toarray") {
        let array = object.try_call_method("toArray", vec![]);
        thrown()?;
        return array.map_err(PhpException::from);
    }
    typed(zval)?.array()
}

/// `$query->shape()`, likewise.
fn shape_of(zval: &Zval) -> PhpResult<Vec<u8>> {
    let object = zval.object().ok_or_else(|| type_error("shape() on a value that is not a query"))?;
    if overridden(object.get_class_entry(), "shape") {
        let shape = object.try_call_method("shape", vec![]);
        thrown()?;
        return Ok(shape.ok().as_ref().and_then(bytes).map(<[u8]>::to_vec).unwrap_or_default());
    }
    Ok(typed(zval)?.shaped())
}

/// The `Method`s among `types`.
fn methods(types: &Arr<'_>) -> Vec<Method> {
    types.values().filter_map(|kind| kind.object().and_then(case_method)).collect()
}

/// The `Method` an enum case object is, if it is one.
fn case_method(object: &ZendObject) -> Option<Method> {
    if !std::ptr::eq(object.get_class_entry(), method_class().ok()?) {
        return None;
    }
    let pointer = std::ptr::from_ref(object).cast_mut();
    let known = METHOD_CASES.with(|cases| cases.iter().position(|case| case.get() == pointer));
    if let Some(index) = known {
        return Some(Method::CASES[index]);
    }
    let properties = object.get_properties().ok()?;
    let name = properties.get("name")?;
    let method = Method::from_name(name.indirect().unwrap_or(name).str()?)?;
    METHOD_CASES.with(|cases| cases[method as usize].set(pointer));
    Some(method)
}

/// The case `name` of the PHP enum `class`.
fn enum_case(class: &str, name: &str) -> PhpResult<Zval> {
    let class = self::class(class)?;
    let mut name = ZendStr::new(name, false);
    let mut zval = Zval::new();
    // SAFETY: `class` is the loaded enum and `name` one of its cases; the case object lives
    // as long as the class.
    unsafe {
        let case = ext_php_rs::ffi::zend_enum_get_case(std::ptr::from_ref(class).cast_mut(), &raw mut *name);
        zval.set_object(&mut *case);
    }
    Ok(zval)
}

/// `Query::toInteger()`: an int as it is, a numeric value as the int it holds, else none.
fn to_integer(value: Option<&Zval>) -> Option<i64> {
    let value = value?;
    if let Some(long) = value.long() {
        return Some(long);
    }
    if !call("is_numeric", vec![value]).ok()?.bool()? {
        return None;
    }
    if let Some(long) = call("filter_var", vec![value, &257i64]).ok()?.long() {
        return Some(long);
    }
    let number = call("floatval", vec![value]).ok()?.double()?;
    #[allow(clippy::cast_precision_loss, clippy::cast_possible_truncation)]
    if !number.is_finite() || number < i64::MIN as f64 || number >= i64::MAX as f64 {
        None
    } else {
        Some(number as i64)
    }
}

const JSON_THROW_ON_ERROR: i64 = 4_194_304;
const E_USER_DEPRECATED: i64 = 16384;
const DEPRECATED_CONTAINS: &str = "Method Utopia\\Query\\Query::contains() is deprecated, Use containsString() for string substring matching or containsAny() for array attributes.";

/// Whether `class` declares `method` (lower case) itself, in PHP, where the extension would
/// otherwise run its own.
fn overridden(class: &ClassEntry, method: &str) -> bool {
    // SAFETY: a class entry's function table holds its methods by lower-case name, each a
    // pointer to a `zend_function` whose first byte is its type (1 is internal).
    unsafe {
        let found =
            ext_php_rs::ffi::zend_hash_str_find(&raw const class.function_table, method.as_ptr().cast(), method.len());
        found.as_ref().is_some_and(|slot| (*slot.value.func).type_ != 1)
    }
}

/// `static::$method(...$arguments)`, when `scope` declares `$method` (lower case) in PHP:
/// where `Query.php` calls a method through `static`, a subclass's own runs. `None` when the
/// class inherits the extension's, which the caller then runs itself.
fn late(scope: &'static ClassEntry, method: &str, arguments: &mut [Zval]) -> Option<PhpResult<Zval>> {
    // SAFETY: the function table holds each method by lower-case name as a pointer to its
    // `zend_function`; a static method is called with no object and `scope` as the called
    // class, with initialized arguments that outlive the call, as the engine calls one.
    unsafe {
        let found =
            ext_php_rs::ffi::zend_hash_str_find(&raw const scope.function_table, method.as_ptr().cast(), method.len());
        let function = found.as_ref()?.value.func;
        if (*function).type_ == 1 {
            return None;
        }
        let mut result = Zval::new();
        ext_php_rs::ffi::zend_call_known_function(
            function,
            std::ptr::null_mut(),
            std::ptr::from_ref(scope).cast_mut(),
            &raw mut result,
            u32::try_from(arguments.len()).unwrap_or(u32::MAX),
            arguments.as_mut_ptr(),
            std::ptr::null_mut(),
        );
        Some(thrown().map(|()| result))
    }
}

/// `new static($method, $attribute, $values, $alias)`: an object of `scope` made directly
/// when its constructor is the extension's, or through its PHP constructor.
fn make(scope: &'static ClassEntry, method: Method, attribute: &[u8], values: Zval, alias: &[u8]) -> PhpResult<Zval> {
    // SAFETY: a class entry's constructor, when set, points at a `zend_function`.
    let native = unsafe { scope.constructor.as_ref() }.is_none_or(|constructor| unsafe { constructor.type_ } == 1);
    if native && scope.instance_of(base()) {
        // SAFETY: the object is initialized before anything can read it.
        let mut object = unsafe { ZendClassObject::<Query>::new_uninit(Some(scope)) };
        object.initialize(Query::new(method, attribute, values, alias));
        return object.into_zval(false).map_err(PhpException::from);
    }
    let object = construct(scope, &mut [method_case(method)?, string(attribute), values, string(alias)])?;
    object.into_zval(false).map_err(PhpException::from)
}

/// The query behind `zval`, when it is one (a `Query` or a PHP subclass of it).
fn query_of(zval: &Zval) -> Option<&ZendClassObject<Query>> {
    let object: &ZendObject = zval.object()?;
    if !object.instance_of(base()) {
        return None;
    }
    ZendClassObject::<Query>::from_zend_obj(object)
}

/// `Utopia\Query\NativeQuery`: the state and methods of `Utopia\Query\Query`.
///
/// Not `Default`: with it, ext-php-rs 0.16.5 makes `new` of a PHP subclass an object of this
/// class.
#[php_class]
#[php(name = "Utopia\\Query\\NativeQuery")]
pub struct Query {
    method: Method,
    attribute: Vec<u8>,
    attribute_type: Vec<u8>,
    on_array: bool,
    values: Zval,
    alias: Vec<u8>,
    alias_defaulted: bool,
}

impl Clone for Query {
    /// `__clone()`: the nested queries are cloned too, so a copy shares none with its source.
    fn clone(&self) -> Self {
        let mut values = ZendHashTable::new();
        if let Some(source) = self.values.array() {
            for (key, value) in source {
                let copied = if query_of(value).is_some() { clone_object(value) } else { value.shallow_clone() };
                let _ = values.insert(key, copied);
            }
        }
        let mut held = Zval::new();
        held.set_hashtable(values);
        Self {
            method: self.method,
            attribute: self.attribute.clone(),
            attribute_type: self.attribute_type.clone(),
            on_array: self.on_array,
            values: held,
            alias: self.alias.clone(),
            alias_defaulted: self.alias_defaulted,
        }
    }
}

impl Query {
    fn new(method: Method, attribute: &[u8], values: Zval, alias: &[u8]) -> Self {
        Self {
            method,
            attribute: attribute.to_vec(),
            attribute_type: Vec::new(),
            on_array: false,
            values,
            alias_defaulted: method.is_join() && !alias.is_empty() && alias == attribute,
            alias: alias.to_vec(),
        }
    }

    fn values(&self) -> impl Iterator<Item = &Zval> {
        self.values.array().into_iter().flat_map(ZendHashTable::values)
    }

    fn nested_join(&self) -> bool {
        self.method.is_join() && self.values().any(|value| query_of(value).is_some())
    }

    /// Whether the query holds queries that count towards its shape.
    fn logical(&self) -> bool {
        matches!(self.method, Method::And | Method::Or | Method::ElemMatch | Method::Relationship) || self.nested_join()
    }

    /// `shape()`: the method and attribute, and for a logical query its children's shapes, sorted.
    fn shaped(&self) -> Vec<u8> {
        let mut shape = self.method.as_str().as_bytes().to_vec();
        shape.push(b':');
        shape.extend_from_slice(&self.attribute);
        if self.logical() {
            let mut children: Vec<Vec<u8>> = self.values().filter_map(query_of).map(|child| child.shaped()).collect();
            children.sort();
            shape.push(b'(');
            shape.extend_from_slice(&children.join(&b'|'));
            shape.push(b')');
        }
        shape
    }

    /// `json_encode($this->toArray())` appended to `out`, for a query holding only what is
    /// plain to write: nulls, booleans, integers, UTF-8 strings and queries like itself.
    /// `false` leaves the query to PHP's encoder, which has an answer for everything else.
    fn json(&self, out: &mut Vec<u8>, depth: usize) -> bool {
        if depth > 64 {
            return false;
        }
        out.extend_from_slice(b"{\"method\":\"");
        out.extend_from_slice(self.method.as_str().as_bytes());
        out.push(b'"');
        if !self.attribute.is_empty() && self.attribute != b"0" {
            out.extend_from_slice(b",\"attribute\":");
            if !json::push_string(out, &self.attribute) {
                return false;
            }
        }
        if !self.alias.is_empty() {
            out.extend_from_slice(b",\"alias\":");
            if !json::push_string(out, &self.alias) {
                return false;
            }
        }
        out.extend_from_slice(b",\"values\":[");
        for (index, value) in self.values().enumerate() {
            if index > 0 {
                out.push(b',');
            }
            if value.is_null() {
                out.extend_from_slice(b"null");
            } else if let Some(flag) = value.bool() {
                out.extend_from_slice(if flag { b"true" } else { b"false" });
            } else if let Some(number) = value.long() {
                json::push_int(out, number);
            } else if let Some(text) = value.zend_str() {
                if !json::push_string(out, text.as_bytes()) {
                    return false;
                }
            } else {
                // A subclass with its own `toArray()` is PHP's to encode.
                let plain = query_of(value).is_some_and(|query| {
                    !overridden(query.std.get_class_entry(), "toarray") && query.json(out, depth + 1)
                });
                if !plain {
                    return false;
                }
            }
        }
        out.extend_from_slice(b"]}");
        true
    }

    /// `toArray()`.
    fn array(&self) -> PhpResult<Zval> {
        let mut array = ZendHashTable::new();
        array.insert("method", self.method.as_str()).map_err(PhpException::from)?;
        if !self.attribute.is_empty() && self.attribute != b"0" {
            array.insert("attribute", string(&self.attribute)).map_err(PhpException::from)?;
        }
        if !self.alias.is_empty() {
            array.insert("alias", string(&self.alias)).map_err(PhpException::from)?;
        }
        let mut values = Vec::new();
        for value in self.values() {
            values.push(if query_of(value).is_some() { array_of(value)? } else { value.shallow_clone() });
        }
        array.insert("values", list(values)?).map_err(PhpException::from)?;
        let mut zval = Zval::new();
        zval.set_hashtable(array);
        Ok(zval)
    }
}

/// `static::parseQuery($query, $allowRaw)`.
fn parse_query_late(
    scope: &'static ClassEntry,
    held: &Zval,
    query: &ZendHashTable,
    allow_raw: bool,
) -> PhpResult<Zval> {
    match late(scope, "parsequery", &mut [held.shallow_clone(), boolean(allow_raw)]) {
        Some(parsed) => parsed,
        None => parse_query(scope, query, allow_raw),
    }
}

/// `static::parse($query, $allowRaw)`.
fn parse_late(scope: &'static ClassEntry, query: &[u8], allow_raw: bool) -> PhpResult<Zval> {
    match late(scope, "parse", &mut [string(query), boolean(allow_raw)]) {
        Some(parsed) => parsed,
        None => parse(scope, query, allow_raw),
    }
}

/// `static::isMethod($method)`.
fn is_method_late(scope: &'static ClassEntry, method: &[u8]) -> PhpResult<bool> {
    match late(scope, "ismethod", &mut [string(method)]) {
        Some(known) => Ok(known?.bool().unwrap_or(false)),
        None => Ok(std::str::from_utf8(method).ok().and_then(Method::try_from_value).is_some()),
    }
}

/// `static::$factory(...)` for the factories `Query.php` reaches through `static`.
fn factory_late(
    scope: &'static ClassEntry,
    factory: &str,
    arguments: &mut [Zval],
    method: Method,
    attribute: &[u8],
    values: Zval,
) -> PhpResult<Zval> {
    match late(scope, factory, arguments) {
        Some(made) => made,
        None => make(scope, method, attribute, values, b""),
    }
}

/// `Query::parseQuery()`.
fn parse_query(scope: &'static ClassEntry, query: &ZendHashTable, allow_raw: bool) -> PhpResult<Zval> {
    let empty = string(b"");
    let method = element(query, "method").unwrap_or(&empty);
    let attribute = element(query, "attribute").unwrap_or(&empty);
    let values = element(query, "values");
    let alias = element(query, "alias").unwrap_or(&empty);

    let Some(method_name) = bytes(method) else {
        return Err(invalid(format!("Invalid query method. Must be a string, got {}", gettype(method))));
    };
    if !is_method_late(scope, method_name)? {
        return Err(invalid([b"Invalid query method: ", method_name].concat()));
    }
    // `Method::from($method)`, which throws for a name only an overridden isMethod() accepts.
    let method = method_of(method)?;
    let Some(attribute) = bytes(attribute) else {
        return Err(invalid(format!("Invalid query attribute. Must be a string, got {}", gettype(attribute))));
    };
    let none = ZendHashTable::new();
    let values = match values {
        None => &*none,
        Some(values) => match values.array() {
            Some(values) => values,
            None => return Err(invalid(format!("Invalid query values. Must be an array, got {}", gettype(values)))),
        },
    };
    let Some(alias) = bytes(alias) else {
        return Err(invalid(format!("Invalid query alias. Must be a string, got {}", gettype(alias))));
    };

    if method == Method::Raw && !allow_raw {
        return Err(refused("Raw queries cannot be parsed from untrusted input; construct via Query::raw() in code"));
    }
    if method == Method::Distinct && (!attribute.is_empty() || !values.is_empty()) {
        return Err(invalid(
            "Invalid distinct query: distinct takes no attribute or values, it deduplicates the attributes a select names",
        ));
    }
    if method.is_join() {
        return parse_join(scope, method, attribute, values, alias, allow_raw);
    }
    if method.is_aggregate() {
        let legacy = values.get_index(0).filter(|value| !value.is_null()).and_then(bytes);
        let alias = if alias.is_empty() { legacy.unwrap_or(alias) } else { alias };
        return make(scope, method, attribute, list([])?, alias);
    }
    let mut held = ZendHashTable::new();
    for (key, value) in values {
        let value = if method.is_nested() {
            match (late(scope, "parsequery", &mut [value.shallow_clone(), boolean(allow_raw)]), value.array()) {
                (Some(parsed), _) => parsed?,
                (None, Some(nested)) => parse_query(scope, nested, allow_raw)?,
                (None, None) => {
                    return Err(type_error(
                        "Utopia\\Query\\Query::parseQuery(): Argument #1 ($query) must be of type array",
                    ));
                }
            }
        } else {
            value.shallow_clone()
        };
        held.insert(key, value).map_err(PhpException::from)?;
    }
    let mut zval = Zval::new();
    zval.set_hashtable(held);
    make(scope, method, attribute, zval, alias)
}

/// `Query::parseJoin()`: a join, in today's layout or one of the 0.6 ones.
fn parse_join(
    scope: &'static ClassEntry,
    method: Method,
    collection: &[u8],
    values: &ZendHashTable,
    alias: &[u8],
    allow_raw: bool,
) -> PhpResult<Zval> {
    let mut on = Vec::new();
    let mut names: Vec<&[u8]> = Vec::new();
    for value in values.values() {
        if let Some(nested) = value.array() {
            on.push(parse_query_late(scope, value, nested, allow_raw)?);
        } else if let Some(name) = bytes(value) {
            names.push(name);
        } else {
            return Err(invalid(format!("Invalid join value. Must be a query or a string, got {}", gettype(value))));
        }
    }
    let column_form = on.is_empty() && names.len() >= 3;
    if column_form {
        on.push(factory_late(
            scope,
            "on",
            &mut [string(names[0]), string(names[2]), string(names[1])],
            Method::On,
            b"",
            list([string(names[0]), string(names[1]), string(names[2])])?,
        )?);
    }
    let legacy = if column_form { names.get(3) } else { names.first() }.copied().unwrap_or(b"");
    if names.len() > if column_form { 4 } else { 1 } {
        return Err(invalid([b"Invalid join values for ", method.as_str().as_bytes(), b" ", collection].concat()));
    }
    create_join(scope, method, collection, if alias.is_empty() { legacy } else { alias }, &on)
}

/// `Query::createJoin()`.
fn create_join(
    scope: &'static ClassEntry,
    method: Method,
    collection: &[u8],
    alias: &[u8],
    on: &[Zval],
) -> PhpResult<Zval> {
    let alias = if alias.is_empty() { collection } else { alias };
    if alias.is_empty() {
        return Err(refused([b"Join alias is required: ", method.as_str().as_bytes(), b" ", collection].concat()));
    }
    let unconditioned = matches!(method, Method::CrossJoin | Method::NaturalJoin);
    if unconditioned && !on.is_empty() {
        return Err(refused(format!("Join ON conditions are not allowed in {}", method.as_str())));
    }
    if !unconditioned && on.is_empty() {
        return Err(refused("Join ON requires at least one condition"));
    }
    for condition in on {
        match query_of(condition) {
            Some(condition) => assert_join_condition(condition)?,
            None => return Err(refused("Join ON conditions must be Query objects")),
        }
    }
    make(scope, method, collection, list(on.iter().map(Zval::shallow_clone))?, alias)
}

fn assert_join_condition(condition: &Query) -> PhpResult<()> {
    if !condition.method.is_join_condition() {
        return Err(refused(format!("Unsupported join ON condition: {}", condition.method.as_str())));
    }
    if !matches!(condition.method, Method::And | Method::Or) {
        return Ok(());
    }
    for child in condition.values() {
        match query_of(child) {
            Some(child) => assert_join_condition(child)?,
            None => return Err(refused("Join ON conditions must be Query objects")),
        }
    }
    Ok(())
}

/// `Query::parse()`.
fn parse(scope: &'static ClassEntry, query: &[u8], allow_raw: bool) -> PhpResult<Zval> {
    let decoded = match call("json_decode", vec![&string(query), &true, &512i64, &JSON_THROW_ON_ERROR]) {
        Ok(decoded) => decoded,
        Err(Some(exception)) => return Err(invalid([&b"Invalid query: "[..], &message_of(&exception)].concat())),
        Err(None) => return Err(invalid("Invalid query: ")),
    };
    match decoded.array() {
        Some(query) => parse_query_late(scope, &decoded, query, allow_raw),
        None => Err(invalid(format!("Invalid query. Must be an array, got {}", gettype(&decoded)))),
    }
}

/// `Invalid attribute "<name>" used in <method>`.
fn invalid_attribute(name: &[u8], method: Method) -> Zval {
    string(&[b"Invalid attribute \"", name, b"\" used in ", method.as_str().as_bytes()].concat())
}

/// `Query::validate()`.
fn validate(
    scope: &'static ClassEntry,
    queries: &ZendHashTable,
    allowed: &Zval,
    errors: &mut Vec<Zval>,
) -> PhpResult<()> {
    let Some(allowed_table) = allowed.array() else { return Ok(()) };
    let permitted = |name: &Zval| allowed_table.values().any(|allowed| allowed.is_identical(name));
    for query in queries.values() {
        let query = required(query)?;
        let method = query.method;
        if matches!(
            method,
            Method::Limit
                | Method::Offset
                | Method::CursorAfter
                | Method::CursorBefore
                | Method::OrderRandom
                | Method::Distinct
                | Method::Select
                | Method::Exists
                | Method::NotExists
                | Method::Relationship
        ) {
            continue;
        }
        if method.is_nested() {
            match late(scope, "validate", &mut [query.values.shallow_clone(), allowed.shallow_clone()]) {
                Some(found) => {
                    errors.extend(found?.array().into_iter().flat_map(ZendHashTable::values).map(Zval::shallow_clone));
                }
                None => {
                    if let Some(nested) = query.values.array() {
                        validate(scope, nested, allowed, errors)?;
                    }
                }
            }
            continue;
        }
        if method == Method::GroupBy {
            for column in query.values() {
                if !permitted(column) {
                    let shown = match PhpString::from_zval(column) {
                        Some(text) => text.bytes().to_vec(),
                        None if column.is_null() => Vec::new(),
                        None => b"Array".to_vec(),
                    };
                    errors.push(invalid_attribute(&shown, method));
                }
            }
            continue;
        }
        if query.attribute.is_empty() || query.attribute == b"*" {
            continue;
        }
        if !permitted(&string(&query.attribute)) {
            errors.push(invalid_attribute(&query.attribute, method));
        }
    }
    Ok(())
}

#[php_impl]
impl Query {
    const DEFAULT_ALIAS: &'static str = "main";

    /// The protected properties of `Query.php`, in its declaration order.
    #[php(getter, name = "method", vis = "protected")]
    pub fn method_property(&self) -> PhpResult<Zval> {
        method_case(self.method)
    }

    #[php(getter, name = "attribute", vis = "protected")]
    pub fn attribute_property(&self) -> Bytes<'_> {
        Bytes(&self.attribute)
    }

    #[php(getter, name = "attributeType", vis = "protected")]
    pub fn attribute_type_property(&self) -> Bytes<'_> {
        Bytes(&self.attribute_type)
    }

    #[php(getter, name = "onArray", vis = "protected")]
    pub fn on_array_property(&self) -> bool {
        self.on_array
    }

    #[php(getter, name = "values", vis = "protected")]
    pub fn values_property(&self) -> Zval {
        self.values.shallow_clone()
    }

    #[php(getter, name = "alias", vis = "protected")]
    pub fn alias_property(&self) -> Bytes<'_> {
        Bytes(&self.alias)
    }

    #[php(getter, name = "aliasDefaulted", vis = "protected")]
    pub fn alias_defaulted_property(&self) -> bool {
        self.alias_defaulted
    }

    #[php(defaults(attribute = "\"\"", values = "Arr::Empty", alias = "\"\""))]
    pub fn __construct(
        method: &Zval,
        attribute: PhpString<'_>,
        values: Arr<'_>,
        alias: PhpString<'_>,
    ) -> PhpResult<Self> {
        let values = values.held();
        let attribute = attribute.bytes();
        let alias = alias.bytes();
        Ok(Self::new(method_of(method)?, attribute, values, alias))
    }

    #[php(name = "getMethod")]
    pub fn get_method(&self) -> PhpResult<Zval> {
        method_case(self.method)
    }

    #[php(name = "getAttribute")]
    pub fn get_attribute(&self) -> Bytes<'_> {
        Bytes(&self.attribute)
    }

    #[php(name = "getValues")]
    pub fn get_values(&self) -> Zval {
        self.values.shallow_clone()
    }

    #[php(name = "getValue", optional = default)]
    pub fn get_value(&self, default: Option<&Zval>) -> Zval {
        let first = self.values.array().and_then(|values| values.get_index(0)).filter(|value| !value.is_null());
        first.or(default).map_or_else(Zval::new, Zval::shallow_clone)
    }

    #[php(name = "isNestedJoin")]
    pub fn is_nested_join(&self) -> bool {
        self.nested_join()
    }

    #[php(name = "getAlias")]
    pub fn get_alias(&self) -> Bytes<'_> {
        Bytes(&self.alias)
    }

    #[php(name = "isAliasDefaulted")]
    pub fn is_alias_defaulted(&self) -> bool {
        self.alias_defaulted
    }

    #[php(name = "getJoinOnQueries")]
    pub fn get_join_on_queries(&self) -> PhpResult<Zval> {
        if !self.nested_join() {
            return list([]);
        }
        list(self.values().filter(|value| query_of(value).is_some()).map(Zval::shallow_clone))
    }

    #[php(name = "setMethod")]
    pub fn set_method<'a>(
        self_: &'a mut ZendClassObject<Query>,
        method: &Zval,
    ) -> PhpResult<&'a mut ZendClassObject<Query>> {
        self_.method = method_of(method)?;
        Ok(self_)
    }

    #[php(name = "setAttribute")]
    pub fn set_attribute<'a>(
        self_: &'a mut ZendClassObject<Query>,
        attribute: PhpString<'_>,
    ) -> &'a mut ZendClassObject<Query> {
        self_.attribute = attribute.bytes().to_vec();
        self_
    }

    #[php(name = "setValues")]
    pub fn set_values<'a>(self_: &'a mut ZendClassObject<Query>, values: Arr<'_>) -> &'a mut ZendClassObject<Query> {
        self_.values = values.held();
        self_
    }

    #[php(name = "setValue")]
    pub fn set_value<'a>(
        self_: &'a mut ZendClassObject<Query>,
        value: &Zval,
    ) -> PhpResult<&'a mut ZendClassObject<Query>> {
        self_.values = list([value.shallow_clone()])?;
        Ok(self_)
    }

    #[php(name = "isMethod")]
    pub fn is_method(value: PhpString<'_>) -> bool {
        std::str::from_utf8(value.bytes()).ok().and_then(Method::try_from_value).is_some()
    }

    #[php(name = "isSpatialQuery")]
    pub fn is_spatial_query(&self) -> bool {
        self.method.is_spatial()
    }

    #[php(defaults(allowRaw = "Bool(false)"))]
    pub fn parse(query: PhpString<'_>, allowRaw: Bool) -> PhpResult<Zval> {
        parse(called_scope(), query.bytes(), allowRaw.0)
    }

    #[php(name = "parseQuery")]
    #[php(defaults(allowRaw = "Bool(false)"))]
    pub fn parse_query(query: Arr<'_>, allowRaw: Bool) -> PhpResult<Zval> {
        match query.table() {
            Some(query) => parse_query(called_scope(), query, allowRaw.0),
            None => parse_query(called_scope(), &ZendHashTable::new(), allowRaw.0),
        }
    }

    #[php(name = "parseQueries")]
    #[php(defaults(allowRaw = "Bool(false)"))]
    pub fn parse_queries(queries: Arr<'_>, allowRaw: Bool) -> PhpResult<Zval> {
        let scope = called_scope();
        let mut parsed = Vec::new();
        for query in queries.values() {
            let Some(text) = PhpString::from_zval(query) else {
                return Err(type_error("Utopia\\Query\\Query::parse(): Argument #1 ($query) must be of type string"));
            };
            parsed.push(parse_late(scope, text.bytes(), allowRaw.0)?);
        }
        list(parsed)
    }

    pub fn fingerprint(queries: Arr<'_>) -> PhpResult<Zval> {
        let scope = called_scope();
        let mut shapes = Vec::new();
        for query in queries.values() {
            let parsed;
            let query = match bytes(query) {
                Some(text) => {
                    parsed = parse_late(scope, text, false)?;
                    &parsed
                }
                None => query,
            };
            if query_of(query).is_none() {
                return Err(invalid("Invalid query element for fingerprint: expected string or Query instance"));
            }
            shapes.push(shape_of(query)?);
        }
        shapes.sort();
        call("md5", vec![&string(&shapes.join(&b'|'))]).map_err(|_| PhpException::from_message("md5() failed".into()))
    }

    pub fn shape(&self) -> crate::emails::Text {
        crate::emails::Text(self.shaped())
    }

    #[php(name = "toArray")]
    pub fn to_array(&self) -> PhpResult<Zval> {
        self.array()
    }

    pub fn compile(self_: &mut ZendClassObject<Query>, compiler: &Zval) -> PhpResult<Zval> {
        let method = match self_.method {
            Method::OrderAsc | Method::OrderDesc | Method::OrderRandom => "compileOrder",
            Method::Limit => "compileLimit",
            Method::Offset => "compileOffset",
            Method::CursorAfter | Method::CursorBefore => "compileCursor",
            Method::Select => "compileSelect",
            Method::Count
            | Method::CountDistinct
            | Method::Sum
            | Method::Avg
            | Method::Min
            | Method::Max
            | Method::Stddev
            | Method::StddevPop
            | Method::StddevSamp
            | Method::Variance
            | Method::VarPop
            | Method::VarSamp
            | Method::BitAnd
            | Method::BitOr
            | Method::BitXor => "compileAggregate",
            Method::GroupBy | Method::GroupByTimeBucket => "compileGroupBy",
            Method::Join
            | Method::LeftJoin
            | Method::RightJoin
            | Method::CrossJoin
            | Method::FullOuterJoin
            | Method::NaturalJoin => "compileJoin",
            _ => "compileFilter",
        };
        let compiler_class = class("Utopia\\Query\\Compiler")?;
        let Some(compiler) = compiler.object().filter(|object| object.instance_of(compiler_class)) else {
            return Err(type_error(
                "Utopia\\Query\\Query::compile(): Argument #1 ($compiler) must be of type Utopia\\Query\\Compiler",
            ));
        };
        let mut this = Zval::new();
        this.set_object(self_.get_mut_zend_obj());
        let compiled = compiler.try_call_method(method, vec![&this]);
        thrown()?;
        compiled.map_err(PhpException::from)
    }

    #[php(name = "toString")]
    pub fn to_string(self_: &mut ZendClassObject<Query>) -> PhpResult<Zval> {
        let mut plain = Vec::with_capacity(128);
        if !overridden(self_.std.get_class_entry(), "toarray") && self_.json(&mut plain, 0) {
            return Ok(string(&plain));
        }
        let mut this = Zval::new();
        this.set_object(self_.get_mut_zend_obj());
        match call("json_encode", vec![&array_of(&this)?, &JSON_THROW_ON_ERROR]) {
            Ok(encoded) => Ok(encoded),
            Err(Some(exception)) => Err(invalid([&b"Invalid Json: "[..], &message_of(&exception)].concat())),
            Err(None) => Err(invalid("Invalid Json: ")),
        }
    }

    #[php(name = "notEqual")]
    pub fn not_equal(attribute: PhpString<'_>, value: &Zval) -> PhpResult<Zval> {
        let values = match value.array() {
            Some(list_or_map) if list_or_map.has_sequential_keys() || list_or_map.is_empty() => value.shallow_clone(),
            Some(_) => list([value.shallow_clone()])?,
            None if value.is_null() => list([Zval::new()])?,
            None => list([scalar(value)?])?,
        };
        make(called_scope(), Method::NotEqual, attribute.bytes(), values, b"")
    }

    #[php(name = "notExists")]
    pub fn not_exists(attribute: &Zval) -> PhpResult<Zval> {
        let values = if attribute.is_array() { attribute.shallow_clone() } else { list([scalar(attribute)?])? };
        make(called_scope(), Method::NotExists, b"", values, b"")
    }

    #[php(name = "groupByTimeBucket")]
    pub fn group_by_time_bucket(attribute: PhpString<'_>, interval: PhpString<'_>) -> PhpResult<Zval> {
        if !utopia_query::GROUP_BY_TIME_BUCKET_INTERVALS.iter().any(|allowed| allowed.as_bytes() == interval.bytes()) {
            return Err(refused(
                [
                    b"Invalid groupByTimeBucket interval: ",
                    interval.bytes(),
                    b". Allowed: ",
                    utopia_query::GROUP_BY_TIME_BUCKET_INTERVALS.join(", ").as_bytes(),
                ]
                .concat(),
            ));
        }
        make(called_scope(), Method::GroupByTimeBucket, attribute.bytes(), list([string(interval.bytes())])?, b"")
    }

    #[php(defaults(operator = "\"=\""))]
    pub fn on(left: PhpString<'_>, right: PhpString<'_>, operator: PhpString<'_>) -> PhpResult<Zval> {
        let operator = operator.bytes();
        make(
            called_scope(),
            Method::On,
            b"",
            list([string(left.bytes()), string(operator), string(right.bytes())])?,
            b"",
        )
    }

    pub fn join(collection: PhpString<'_>, alias: PhpString<'_>, on: Arr<'_>) -> PhpResult<Zval> {
        let on: Vec<Zval> = on.values().map(Zval::shallow_clone).collect();
        create_join(called_scope(), Method::Join, collection.bytes(), alias.bytes(), &on)
    }

    #[php(name = "leftJoin")]
    pub fn left_join(collection: PhpString<'_>, alias: PhpString<'_>, on: Arr<'_>) -> PhpResult<Zval> {
        let on: Vec<Zval> = on.values().map(Zval::shallow_clone).collect();
        create_join(called_scope(), Method::LeftJoin, collection.bytes(), alias.bytes(), &on)
    }

    #[php(name = "rightJoin")]
    pub fn right_join(collection: PhpString<'_>, alias: PhpString<'_>, on: Arr<'_>) -> PhpResult<Zval> {
        let on: Vec<Zval> = on.values().map(Zval::shallow_clone).collect();
        create_join(called_scope(), Method::RightJoin, collection.bytes(), alias.bytes(), &on)
    }

    #[php(name = "fullOuterJoin")]
    pub fn full_outer_join(collection: PhpString<'_>, alias: PhpString<'_>, on: Arr<'_>) -> PhpResult<Zval> {
        let on: Vec<Zval> = on.values().map(Zval::shallow_clone).collect();
        create_join(called_scope(), Method::FullOuterJoin, collection.bytes(), alias.bytes(), &on)
    }

    #[php(name = "crossJoin")]
    pub fn cross_join(collection: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        create_join(called_scope(), Method::CrossJoin, collection.bytes(), alias.bytes(), &[])
    }

    #[php(name = "naturalJoin")]
    pub fn natural_join(collection: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        create_join(called_scope(), Method::NaturalJoin, collection.bytes(), alias.bytes(), &[])
    }

    #[php(defaults(perPage = "Int(25)"))]
    pub fn page(page: Int, perPage: Int) -> PhpResult<Zval> {
        let (page, perPage) = (page.0, perPage.0);
        if page < 1 {
            return Err(refused(format!("Page must be >= 1, got {page}")));
        }
        if perPage < 1 {
            return Err(refused(format!("Per page must be >= 1, got {perPage}")));
        }
        let Some(offset) = (page - 1).checked_mul(perPage) else {
            return Err(type_error(
                "Utopia\\Query\\Query::offset(): Argument #1 ($value) must be of type int, float given",
            ));
        };
        let scope = called_scope();
        list([
            factory_late(scope, "limit", &mut [long(perPage)], Method::Limit, b"", list([long(perPage)])?)?,
            factory_late(scope, "offset", &mut [long(offset)], Method::Offset, b"", list([long(offset)])?)?,
        ])
    }

    #[php(name = "getByType")]
    #[php(defaults(clone = "Bool(true)"))]
    pub fn get_by_type(queries: Arr<'_>, types: Arr<'_>, clone: Bool) -> PhpResult<Zval> {
        let wanted = methods(&types);
        let mut filtered = Vec::new();
        for query in queries.values() {
            if wanted.contains(&required(query)?.method) {
                filtered.push(if clone.0 { clone_object(query) } else { query.shallow_clone() });
            }
        }
        list(filtered)
    }

    #[php(name = "getCursorQueries")]
    #[php(defaults(clone = "Bool(true)"))]
    pub fn get_cursor_queries(queries: Arr<'_>, clone: Bool) -> PhpResult<Zval> {
        let mut filtered = Vec::new();
        for query in queries.values() {
            if matches!(required(query)?.method, Method::CursorAfter | Method::CursorBefore) {
                filtered.push(if clone.0 { clone_object(query) } else { query.shallow_clone() });
            }
        }
        list(filtered)
    }

    #[php(name = "groupByType")]
    pub fn group_by_type(queries: Arr<'_>) -> PhpResult<Zval> {
        let (mut filters, mut selections, mut aggregations, mut having, mut joins, mut unions) =
            (Vec::new(), Vec::new(), Vec::new(), Vec::new(), Vec::new(), Vec::new());
        let (mut group_by, mut time_buckets) = (Vec::new(), Vec::new());
        let mut distinct = false;
        let (mut limit, mut offset) = (None, None);
        let mut cursor: Option<(Zval, &str)> = None;

        for zval in queries.values() {
            let Some(query) = query_of(zval) else { continue };
            let first = || query.values.array().and_then(|values| values.get_index(0)).filter(|value| !value.is_null());
            match query.method {
                Method::OrderAsc | Method::OrderDesc | Method::OrderRandom => {}
                Method::Limit => limit = limit.or_else(|| to_integer(first())),
                Method::Offset => offset = offset.or_else(|| to_integer(first())),
                Method::CursorAfter | Method::CursorBefore => {
                    if cursor.is_none() {
                        let direction = if query.method == Method::CursorAfter { "After" } else { "Before" };
                        cursor = Some((first().map_or_else(Zval::new, Zval::shallow_clone), direction));
                    }
                }
                Method::Select => selections.push(clone_object(zval)),
                Method::Count
                | Method::CountDistinct
                | Method::Sum
                | Method::Avg
                | Method::Min
                | Method::Max
                | Method::Stddev
                | Method::StddevPop
                | Method::StddevSamp
                | Method::Variance
                | Method::VarPop
                | Method::VarSamp
                | Method::BitAnd
                | Method::BitOr
                | Method::BitXor => aggregations.push(clone_object(zval)),
                Method::GroupBy => group_by.extend(query.values().map(Zval::shallow_clone)),
                Method::GroupByTimeBucket => {
                    let mut bucket = ZendHashTable::new();
                    bucket.insert("attribute", string(&query.attribute)).map_err(PhpException::from)?;
                    bucket
                        .insert("interval", first().map_or_else(|| string(b""), Zval::shallow_clone))
                        .map_err(PhpException::from)?;
                    let mut held = Zval::new();
                    held.set_hashtable(bucket);
                    time_buckets.push(held);
                }
                Method::Having => having.push(clone_object(zval)),
                Method::Distinct => distinct = true,
                Method::Join
                | Method::LeftJoin
                | Method::RightJoin
                | Method::CrossJoin
                | Method::FullOuterJoin
                | Method::NaturalJoin => joins.push(clone_object(zval)),
                Method::Union | Method::UnionAll => unions.push(clone_object(zval)),
                _ => filters.push(clone_object(zval)),
            }
        }

        let optional = |value: Option<i64>| value.map_or_else(Zval::new, long);
        // A cursor that was never set is null; one set from a query without values is too.
        let (cursor, direction) = match cursor {
            Some((value, direction)) => (value, enum_case("Utopia\\Query\\CursorDirection", direction)?),
            None => (Zval::new(), Zval::new()),
        };
        let parsed = construct(
            class("Utopia\\Query\\Builder\\ParsedQuery")?,
            &mut [
                list(filters)?,
                list(selections)?,
                list(aggregations)?,
                list(group_by)?,
                list(having)?,
                boolean(distinct),
                list(joins)?,
                list(unions)?,
                optional(limit),
                optional(offset),
                cursor,
                direction,
                list(time_buckets)?,
            ],
        )?;
        parsed.into_zval(false).map_err(PhpException::from)
    }

    #[php(name = "isNested")]
    pub fn is_nested(&self) -> bool {
        self.method.is_nested()
    }

    #[php(name = "onArray")]
    pub fn on_array(&self) -> bool {
        self.on_array
    }

    #[php(name = "setOnArray")]
    pub fn set_on_array(&mut self, bool: Bool) {
        self.on_array = bool.0;
    }

    #[php(name = "setAttributeType")]
    pub fn set_attribute_type(&mut self, r#type: PhpString<'_>) {
        self.attribute_type = r#type.bytes().to_vec();
    }

    #[php(name = "getAttributeType")]
    pub fn get_attribute_type(&self) -> Bytes<'_> {
        Bytes(&self.attribute_type)
    }

    pub fn merge(queriesA: Arr<'_>, queriesB: Arr<'_>) -> PhpResult<Zval> {
        let mut result = ZendHashTable::new();
        for (key, query) in queriesA.table().into_iter().flatten() {
            result.insert(key, query.shallow_clone()).map_err(PhpException::from)?;
        }
        for query in queriesB.values() {
            let method = required(query)?.method;
            if matches!(method, Method::Limit | Method::Offset | Method::CursorAfter | Method::CursorBefore) {
                let mut kept = ZendHashTable::new();
                for existing in result.values() {
                    if typed(existing)?.method != method {
                        kept.push(existing.shallow_clone()).map_err(PhpException::from)?;
                    }
                }
                result = kept;
            }
            result.push(query.shallow_clone()).map_err(PhpException::from)?;
        }
        let mut zval = Zval::new();
        zval.set_hashtable(result);
        Ok(zval)
    }

    pub fn diff(queriesA: Arr<'_>, queriesB: Arr<'_>) -> PhpResult<Zval> {
        let mut others = Vec::new();
        for query in queriesB.values() {
            typed(query)?;
            others.push(array_of(query)?);
        }
        let mut result = Vec::new();
        for query in queriesA.values() {
            required(query)?;
            let array = array_of(query)?;
            if !others.iter().any(|other| other.is_identical(&array)) {
                result.push(query.shallow_clone());
            }
        }
        list(result)
    }

    pub fn validate(queries: Arr<'_>, allowedAttributes: Arr<'_>) -> PhpResult<Zval> {
        let mut errors = Vec::new();
        if let Some(queries) = queries.table() {
            validate(called_scope(), queries, &allowedAttributes.held(), &mut errors)?;
        }
        list(errors)
    }

    pub fn equal(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Equal, attribute.bytes(), values.held(), b"")
    }

    pub fn contains(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        // What `#[\Deprecated]` on the PHP method raises when it is called.
        let _ = call("trigger_error", vec![&DEPRECATED_CONTAINS, &E_USER_DEPRECATED]);
        thrown()?;
        make(called_scope(), Method::Contains, attribute.bytes(), values.held(), b"")
    }

    #[php(name = "containsString")]
    pub fn contains_string(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Contains, attribute.bytes(), values.held(), b"")
    }

    #[php(name = "containsAny")]
    pub fn contains_any(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::ContainsAny, attribute.bytes(), values.held(), b"")
    }

    #[php(name = "notContains")]
    pub fn not_contains(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::NotContains, attribute.bytes(), values.held(), b"")
    }

    #[php(name = "containsAll")]
    pub fn contains_all(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::ContainsAll, attribute.bytes(), values.held(), b"")
    }

    #[php(name = "lessThan")]
    pub fn less_than(attribute: PhpString<'_>, value: &Zval) -> PhpResult<Zval> {
        make(called_scope(), Method::LessThan, attribute.bytes(), list([scalar(value)?])?, b"")
    }

    #[php(name = "lessThanEqual")]
    pub fn less_than_equal(attribute: PhpString<'_>, value: &Zval) -> PhpResult<Zval> {
        make(called_scope(), Method::LessThanEqual, attribute.bytes(), list([scalar(value)?])?, b"")
    }

    #[php(name = "greaterThan")]
    pub fn greater_than(attribute: PhpString<'_>, value: &Zval) -> PhpResult<Zval> {
        make(called_scope(), Method::GreaterThan, attribute.bytes(), list([scalar(value)?])?, b"")
    }

    #[php(name = "greaterThanEqual")]
    pub fn greater_than_equal(attribute: PhpString<'_>, value: &Zval) -> PhpResult<Zval> {
        make(called_scope(), Method::GreaterThanEqual, attribute.bytes(), list([scalar(value)?])?, b"")
    }

    pub fn between(attribute: PhpString<'_>, start: &Zval, end: &Zval) -> PhpResult<Zval> {
        make(called_scope(), Method::Between, attribute.bytes(), list([scalar(start)?, scalar(end)?])?, b"")
    }

    #[php(name = "notBetween")]
    pub fn not_between(attribute: PhpString<'_>, start: &Zval, end: &Zval) -> PhpResult<Zval> {
        make(called_scope(), Method::NotBetween, attribute.bytes(), list([scalar(start)?, scalar(end)?])?, b"")
    }

    pub fn search(attribute: PhpString<'_>, value: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Search, attribute.bytes(), list([string(value.bytes())])?, b"")
    }

    #[php(name = "notSearch")]
    pub fn not_search(attribute: PhpString<'_>, value: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::NotSearch, attribute.bytes(), list([string(value.bytes())])?, b"")
    }

    #[php(name = "startsWith")]
    pub fn starts_with(attribute: PhpString<'_>, value: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::StartsWith, attribute.bytes(), list([string(value.bytes())])?, b"")
    }

    #[php(name = "notStartsWith")]
    pub fn not_starts_with(attribute: PhpString<'_>, value: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::NotStartsWith, attribute.bytes(), list([string(value.bytes())])?, b"")
    }

    #[php(name = "endsWith")]
    pub fn ends_with(attribute: PhpString<'_>, value: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::EndsWith, attribute.bytes(), list([string(value.bytes())])?, b"")
    }

    #[php(name = "notEndsWith")]
    pub fn not_ends_with(attribute: PhpString<'_>, value: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::NotEndsWith, attribute.bytes(), list([string(value.bytes())])?, b"")
    }

    pub fn regex(attribute: PhpString<'_>, pattern: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Regex, attribute.bytes(), list([string(pattern.bytes())])?, b"")
    }

    #[php(name = "isNull")]
    pub fn is_null(attribute: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::IsNull, attribute.bytes(), list([])?, b"")
    }

    #[php(name = "isNotNull")]
    pub fn is_not_null(attribute: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::IsNotNull, attribute.bytes(), list([])?, b"")
    }

    #[php(name = "createdBefore")]
    pub fn created_before(value: PhpString<'_>) -> PhpResult<Zval> {
        let value = string(value.bytes());
        factory_late(
            called_scope(),
            "lessthan",
            &mut [string(b"$createdAt"), value.shallow_clone()],
            Method::LessThan,
            b"$createdAt",
            list([value])?,
        )
    }

    #[php(name = "createdAfter")]
    pub fn created_after(value: PhpString<'_>) -> PhpResult<Zval> {
        let value = string(value.bytes());
        factory_late(
            called_scope(),
            "greaterthan",
            &mut [string(b"$createdAt"), value.shallow_clone()],
            Method::GreaterThan,
            b"$createdAt",
            list([value])?,
        )
    }

    #[php(name = "updatedBefore")]
    pub fn updated_before(value: PhpString<'_>) -> PhpResult<Zval> {
        let value = string(value.bytes());
        factory_late(
            called_scope(),
            "lessthan",
            &mut [string(b"$updatedAt"), value.shallow_clone()],
            Method::LessThan,
            b"$updatedAt",
            list([value])?,
        )
    }

    #[php(name = "updatedAfter")]
    pub fn updated_after(value: PhpString<'_>) -> PhpResult<Zval> {
        let value = string(value.bytes());
        factory_late(
            called_scope(),
            "greaterthan",
            &mut [string(b"$updatedAt"), value.shallow_clone()],
            Method::GreaterThan,
            b"$updatedAt",
            list([value])?,
        )
    }

    #[php(name = "createdBetween")]
    pub fn created_between(start: PhpString<'_>, end: PhpString<'_>) -> PhpResult<Zval> {
        let (start, end) = (string(start.bytes()), string(end.bytes()));
        factory_late(
            called_scope(),
            "between",
            &mut [string(b"$createdAt"), start.shallow_clone(), end.shallow_clone()],
            Method::Between,
            b"$createdAt",
            list([start, end])?,
        )
    }

    #[php(name = "updatedBetween")]
    pub fn updated_between(start: PhpString<'_>, end: PhpString<'_>) -> PhpResult<Zval> {
        let (start, end) = (string(start.bytes()), string(end.bytes()));
        factory_late(
            called_scope(),
            "between",
            &mut [string(b"$updatedAt"), start.shallow_clone(), end.shallow_clone()],
            Method::Between,
            b"$updatedAt",
            list([start, end])?,
        )
    }

    pub fn select(attributes: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Select, b"", attributes.held(), b"")
    }

    pub fn or(queries: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Or, b"", queries.held(), b"")
    }

    pub fn and(queries: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::And, b"", queries.held(), b"")
    }

    pub fn having(queries: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Having, b"", queries.held(), b"")
    }

    pub fn union(queries: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Union, b"", queries.held(), b"")
    }

    #[php(name = "unionAll")]
    pub fn union_all(queries: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::UnionAll, b"", queries.held(), b"")
    }

    pub fn exists(attributes: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Exists, b"", attributes.held(), b"")
    }

    #[php(name = "groupBy")]
    pub fn group_by(attributes: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::GroupBy, b"", attributes.held(), b"")
    }

    #[php(name = "elemMatch")]
    pub fn elem_match(attribute: PhpString<'_>, queries: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::ElemMatch, attribute.bytes(), queries.held(), b"")
    }

    pub fn relationship(attribute: PhpString<'_>, queries: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Relationship, attribute.bytes(), queries.held(), b"")
    }

    #[php(name = "orderRandom")]
    pub fn order_random() -> PhpResult<Zval> {
        make(called_scope(), Method::OrderRandom, b"", list([])?, b"")
    }

    pub fn distinct() -> PhpResult<Zval> {
        make(called_scope(), Method::Distinct, b"", list([])?, b"")
    }

    #[php(name = "orderDesc")]
    #[php(defaults(attribute = "\"\""))]
    pub fn order_desc(attribute: PhpString<'_>, nulls: Option<&Zval>) -> PhpResult<Zval> {
        make(called_scope(), Method::OrderDesc, attribute.bytes(), nulls_position(nulls)?, b"")
    }

    #[php(name = "orderAsc")]
    #[php(defaults(attribute = "\"\""))]
    pub fn order_asc(attribute: PhpString<'_>, nulls: Option<&Zval>) -> PhpResult<Zval> {
        make(called_scope(), Method::OrderAsc, attribute.bytes(), nulls_position(nulls)?, b"")
    }

    pub fn limit(value: Int) -> PhpResult<Zval> {
        make(called_scope(), Method::Limit, b"", list([long(value.0)])?, b"")
    }

    pub fn offset(value: Int) -> PhpResult<Zval> {
        make(called_scope(), Method::Offset, b"", list([long(value.0)])?, b"")
    }

    #[php(name = "cursorAfter")]
    pub fn cursor_after(value: Row<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::CursorAfter, b"", list([value.0.shallow_clone()])?, b"")
    }

    #[php(name = "cursorBefore")]
    pub fn cursor_before(value: Row<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::CursorBefore, b"", list([value.0.shallow_clone()])?, b"")
    }

    #[php(name = "distanceEqual")]
    #[php(defaults(meters = "Bool(false)"))]
    pub fn distance_equal(attribute: PhpString<'_>, values: Arr<'_>, distance: &Zval, meters: Bool) -> PhpResult<Zval> {
        make(
            called_scope(),
            Method::DistanceEqual,
            attribute.bytes(),
            list([list([values.held(), number(distance)?, boolean(meters.0)])?])?,
            b"",
        )
    }

    #[php(name = "distanceNotEqual")]
    #[php(defaults(meters = "Bool(false)"))]
    pub fn distance_not_equal(
        attribute: PhpString<'_>,
        values: Arr<'_>,
        distance: &Zval,
        meters: Bool,
    ) -> PhpResult<Zval> {
        make(
            called_scope(),
            Method::DistanceNotEqual,
            attribute.bytes(),
            list([list([values.held(), number(distance)?, boolean(meters.0)])?])?,
            b"",
        )
    }

    #[php(name = "distanceGreaterThan")]
    #[php(defaults(meters = "Bool(false)"))]
    pub fn distance_greater_than(
        attribute: PhpString<'_>,
        values: Arr<'_>,
        distance: &Zval,
        meters: Bool,
    ) -> PhpResult<Zval> {
        make(
            called_scope(),
            Method::DistanceGreaterThan,
            attribute.bytes(),
            list([list([values.held(), number(distance)?, boolean(meters.0)])?])?,
            b"",
        )
    }

    #[php(name = "distanceLessThan")]
    #[php(defaults(meters = "Bool(false)"))]
    pub fn distance_less_than(
        attribute: PhpString<'_>,
        values: Arr<'_>,
        distance: &Zval,
        meters: Bool,
    ) -> PhpResult<Zval> {
        make(
            called_scope(),
            Method::DistanceLessThan,
            attribute.bytes(),
            list([list([values.held(), number(distance)?, boolean(meters.0)])?])?,
            b"",
        )
    }

    pub fn intersects(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Intersects, attribute.bytes(), list([values.held()])?, b"")
    }

    #[php(name = "notIntersects")]
    pub fn not_intersects(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::NotIntersects, attribute.bytes(), list([values.held()])?, b"")
    }

    pub fn crosses(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Crosses, attribute.bytes(), list([values.held()])?, b"")
    }

    #[php(name = "notCrosses")]
    pub fn not_crosses(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::NotCrosses, attribute.bytes(), list([values.held()])?, b"")
    }

    pub fn overlaps(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Overlaps, attribute.bytes(), list([values.held()])?, b"")
    }

    #[php(name = "notOverlaps")]
    pub fn not_overlaps(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::NotOverlaps, attribute.bytes(), list([values.held()])?, b"")
    }

    pub fn touches(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Touches, attribute.bytes(), list([values.held()])?, b"")
    }

    #[php(name = "notTouches")]
    pub fn not_touches(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::NotTouches, attribute.bytes(), list([values.held()])?, b"")
    }

    #[php(name = "vectorDot")]
    pub fn vector_dot(attribute: PhpString<'_>, vector: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::VectorDot, attribute.bytes(), list([vector.held()])?, b"")
    }

    #[php(name = "vectorCosine")]
    pub fn vector_cosine(attribute: PhpString<'_>, vector: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::VectorCosine, attribute.bytes(), list([vector.held()])?, b"")
    }

    #[php(name = "vectorEuclidean")]
    pub fn vector_euclidean(attribute: PhpString<'_>, vector: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::VectorEuclidean, attribute.bytes(), list([vector.held()])?, b"")
    }

    pub fn covers(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Covers, attribute.bytes(), list([values.held()])?, b"")
    }

    #[php(name = "notCovers")]
    pub fn not_covers(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::NotCovers, attribute.bytes(), list([values.held()])?, b"")
    }

    #[php(name = "spatialEquals")]
    pub fn spatial_equals(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::SpatialEquals, attribute.bytes(), list([values.held()])?, b"")
    }

    #[php(name = "notSpatialEquals")]
    pub fn not_spatial_equals(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::NotSpatialEquals, attribute.bytes(), list([values.held()])?, b"")
    }

    #[php(name = "jsonOverlaps")]
    pub fn json_overlaps(attribute: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::JsonOverlaps, attribute.bytes(), list([values.held()])?, b"")
    }

    #[php(defaults(attribute = "\"*\"", alias = "\"\""))]
    pub fn count(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Count, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(name = "countDistinct")]
    #[php(defaults(alias = "\"\""))]
    pub fn count_distinct(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::CountDistinct, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(defaults(alias = "\"\""))]
    pub fn sum(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Sum, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(defaults(alias = "\"\""))]
    pub fn avg(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Avg, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(defaults(alias = "\"\""))]
    pub fn min(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Min, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(defaults(alias = "\"\""))]
    pub fn max(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Max, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(defaults(alias = "\"\""))]
    pub fn stddev(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Stddev, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(name = "stddevPop")]
    #[php(defaults(alias = "\"\""))]
    pub fn stddev_pop(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::StddevPop, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(name = "stddevSamp")]
    #[php(defaults(alias = "\"\""))]
    pub fn stddev_samp(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::StddevSamp, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(defaults(alias = "\"\""))]
    pub fn variance(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Variance, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(name = "varPop")]
    #[php(defaults(alias = "\"\""))]
    pub fn var_pop(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::VarPop, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(name = "varSamp")]
    #[php(defaults(alias = "\"\""))]
    pub fn var_samp(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::VarSamp, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(name = "bitAnd")]
    #[php(defaults(alias = "\"\""))]
    pub fn bit_and(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::BitAnd, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(name = "bitOr")]
    #[php(defaults(alias = "\"\""))]
    pub fn bit_or(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::BitOr, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(name = "bitXor")]
    #[php(defaults(alias = "\"\""))]
    pub fn bit_xor(attribute: PhpString<'_>, alias: PhpString<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::BitXor, attribute.bytes(), list([])?, alias.bytes())
    }

    #[php(name = "jsonContains")]
    pub fn json_contains(attribute: PhpString<'_>, value: &Zval) -> PhpResult<Zval> {
        make(called_scope(), Method::JsonContains, attribute.bytes(), list([value.shallow_clone()])?, b"")
    }

    #[php(name = "jsonNotContains")]
    pub fn json_not_contains(attribute: PhpString<'_>, value: &Zval) -> PhpResult<Zval> {
        make(called_scope(), Method::JsonNotContains, attribute.bytes(), list([value.shallow_clone()])?, b"")
    }

    #[php(name = "jsonPath")]
    pub fn json_path(
        attribute: PhpString<'_>,
        path: PhpString<'_>,
        operator: PhpString<'_>,
        value: &Zval,
    ) -> PhpResult<Zval> {
        make(
            called_scope(),
            Method::JsonPath,
            attribute.bytes(),
            list([string(path.bytes()), string(operator.bytes()), value.shallow_clone()])?,
            b"",
        )
    }

    #[php(defaults(bindings = "Arr::Empty"))]
    pub fn raw(sql: PhpString<'_>, bindings: Arr<'_>) -> PhpResult<Zval> {
        make(called_scope(), Method::Raw, sql.bytes(), bindings.held(), b"")
    }
}
