//! `Utopia\Query\Query` (`packages/query/src/Query.php`), behind `_APP_EXTENSION_LIBRARIES=query`.
//!
//! Built by hand like the validators ([`crate::classes`]): no Rust object behind it, its state in
//! the seven typed protected properties PHP declares (`Method $method`, `string $attribute`,
//! `string $attributeType`, `bool $onArray`, `array $values`, `string $alias`,
//! `bool $aliasDefaulted`), so `Utopia\Database\Query`, `Utopia\Audit\Query` and `UsageQuery`
//! extend it, read and write those properties, and override its methods as they do the PHP class.
//! The enums (`Method`, `NullsPosition`...), the exceptions and `Builder\ParsedQuery` stay PHP.
//!
//! `$values` stays a PHP array: a cursor's `Document`, nested queries of a subclass and the
//! int/float distinction keep their identity. The query methods come from [`utopia_query::Method`]
//! (its cases and predicates), the JSON a query is parsed from is decoded by `php-std`.
//!
//! PHP's methods reach each other through late static binding (`new static`, `static::parseQuery()`,
//! `static::isMethod()`, `static::on()`...) and through `$this->`/`$query->` (`toArray()`,
//! `shape()`, `isNestedJoin()`, `getMethod()`...). Here every such call goes to the called class's
//! (or the object's) own method when a subclass overrides it, and stays native otherwise.

mod factories;
mod lists;
mod parse;
mod zend;

use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::args::{Arg, ArgInfoTables};
use ext_php_rs::builders::{ClassBuilder, FunctionBuilder};
use ext_php_rs::flags::{ConstantFlags, DataType, MethodFlags};
use ext_php_rs::types::{ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData};
use utopia_query::Method;

use crate::classes::engine::{
    self, Declared, MAY_BE_ANY, MAY_BE_ARRAY, MAY_BE_BOOL, MAY_BE_DOUBLE, MAY_BE_LONG, MAY_BE_STRING, Property,
    PropertyType,
};
use zend::{Class, Dispatch, Info};

/// The class's name.
pub const NAME: &str = "Utopia\\Query\\Query";

static CLASS: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());

/// The registered class.
#[inline]
pub fn base() -> &'static ClassEntry {
    // SAFETY: set once, in MINIT, to a class entry the engine keeps for the process.
    unsafe { CLASS.load(Ordering::Acquire).as_ref().expect("Utopia\\Query\\Query is registered") }
}

// The properties, in `Query.php`'s order.
static METHOD: Property = Property::new("method");
static ATTRIBUTE: Property = Property::new("attribute");
static ATTRIBUTE_TYPE: Property = Property::new("attributeType");
static ON_ARRAY: Property = Property::new("onArray");
static VALUES: Property = Property::new("values");
static ALIAS: Property = Property::new("alias");
static ALIAS_DEFAULTED: Property = Property::new("aliasDefaulted");

fn offsets() -> [u32; 7] {
    [&METHOD, &ATTRIBUTE, &ATTRIBUTE_TYPE, &ON_ARRAY, &VALUES, &ALIAS, &ALIAS_DEFAULTED].map(Property::offset)
}

/// What `ce` (the class or a subclass) overrides.
#[inline]
fn info(ce: &ClassEntry) -> Info {
    zend::info(ce, base(), &offsets())
}

/// The override of `dispatch` by `ce`, if any.
#[inline]
fn overridden(ce: &ClassEntry, dispatch: Dispatch) -> Option<*mut ext_php_rs::ffi::zend_function> {
    if std::ptr::eq(ce, base()) {
        return None;
    }
    let function = info(ce).overrides[dispatch as usize];
    (!function.is_null()).then_some(function)
}

// Properties -----------------------------------------------------------------------------------

/// The slot of `property` in `object` when it may be used directly: an object of the class, or of
/// a subclass that keeps the property as declared, and not lazy.
#[inline]
fn slot<'a>(object: &'a mut ZendObject, property: &Property) -> Option<&'a mut Zval> {
    let offset = property.offset();
    if offset == 0 || object.is_lazy() {
        return None;
    }
    // SAFETY: the object's class lives as long as the object.
    let ce = unsafe { &*object.ce.cast::<ClassEntry>() };
    if !std::ptr::eq(ce, base()) && !info(ce).plain {
        return None;
    }
    // SAFETY: a declared property's slot lies `offset` bytes into the object.
    Some(unsafe { &mut *std::ptr::from_mut(object).cast::<u8>().add(offset as usize).cast::<Zval>() })
}

/// `$object->property` read from the class's scope: `None` with the engine's `Error` pending for
/// an uninitialized property.
#[inline]
fn get(object: &mut ZendObject, property: &Property) -> Option<Zval> {
    let ptr = std::ptr::from_mut(object);
    if let Some(slot) = slot(object, property) {
        let value = slot.dereference();
        // SAFETY: reading a live slot's type.
        if unsafe { value.u1.type_info } != 0 {
            return Some(value.shallow_clone());
        }
    }
    // SAFETY: the object is alive; the slot borrow ended.
    engine::read(base(), unsafe { &mut *ptr }, property.name())
}

/// `$object->property` as `empty()` reads it: `null` for an uninitialized property, no error.
#[inline]
fn get_quietly(object: &mut ZendObject, property: &Property) -> Zval {
    let ptr = std::ptr::from_mut(object);
    if let Some(slot) = slot(object, property) {
        let value = slot.dereference();
        // SAFETY: reading a live slot's type.
        if unsafe { value.u1.type_info } != 0 {
            return value.shallow_clone();
        }
    }
    // SAFETY: as in [`get`].
    zend::read_quietly(base(), unsafe { &mut *ptr }, property.name())
}

/// `$object->property = $value` from the class's scope, for a value of the property's type.
#[inline]
fn put(object: &mut ZendObject, property: &Property, value: Zval) {
    let ptr = std::ptr::from_mut(object);
    if let Some(slot) = slot(object, property)
        && !slot.is_reference()
    {
        drop(std::mem::replace(slot, value));
        return;
    }
    // SAFETY: as in [`get`].
    engine::write(base(), unsafe { &mut *ptr }, property.name(), value);
}

/// The object a value holds, if it is one of the class (or a subclass).
#[inline]
fn query_object(value: &Zval) -> Option<&'static mut ZendObject> {
    let value = value.dereference();
    if !zend::instance_of(value, base()) {
        return None;
    }
    zend::object(value)
}

/// The method `$object->method` holds (`None` with the `Error` of an uninitialized property).
fn method(object: &mut ZendObject) -> Option<(Zval, Method)> {
    let case = get(object, &METHOD)?;
    let method = zend::method_of(&case)?;
    Some((case, method))
}

// Construction -------------------------------------------------------------------------------

/// `new static($method, $attribute, $values, $alias)` with the first `count` arguments, for the
/// called class `scope`: its own constructor when it overrides the native one.
fn new_static(
    scope: &ClassEntry,
    method: Method,
    attribute: Zval,
    values: Zval,
    alias: Zval,
    count: usize,
) -> Option<Zval> {
    let ctor = info(scope).ctor;
    let case = zend::case(method)?;
    if ctor.is_null() {
        let mut object = zend::instantiate(scope)?;
        let target = object.object_mut()?;
        initialize(target, case, method, attribute, values, alias);
        return Some(object);
    }
    let mut args = [case, attribute, values, alias];
    zend::construct_with(scope, ctor, &mut args[..count])
}

/// What `__construct()` assigns, the method already a case.
fn initialize(this: &mut ZendObject, case: Zval, method: Method, attribute: Zval, values: Zval, alias: Zval) {
    let defaulted = method.is_join()
        && alias.zend_str().is_some_and(|a| !a.as_bytes().is_empty())
        && alias.zend_str().map(ZendStr::as_bytes) == attribute.zend_str().map(ZendStr::as_bytes);
    let mut flag = Zval::new();
    flag.set_bool(defaulted);
    // One check of the object's class for the five slots, rather than one per property.
    if slot(this, &METHOD).is_some() {
        let base = std::ptr::from_mut(this).cast::<u8>();
        let assignments =
            [(&METHOD, case), (&ATTRIBUTE, attribute), (&VALUES, values), (&ALIAS, alias), (&ALIAS_DEFAULTED, flag)];
        for (property, value) in assignments {
            // SAFETY: a plain object's declared slot, alive as long as the object.
            let slot = unsafe { &mut *base.add(property.offset() as usize).cast::<Zval>() };
            if slot.is_reference() {
                put(this, property, value);
            } else {
                drop(std::mem::replace(slot, value));
            }
        }
        return;
    }
    put(this, &METHOD, case);
    put(this, &ATTRIBUTE, attribute);
    put(this, &VALUES, values);
    put(this, &ALIAS, alias);
    put(this, &ALIAS_DEFAULTED, flag);
}

// Exceptions -----------------------------------------------------------------------------------

/// `throw new Utopia\Query\Exception($message)`.
fn fail(message: impl AsRef<[u8]>) {
    zend::throw_new(Class::Exception, message.as_ref());
}

/// `throw new Utopia\Query\Exception\ValidationException($message)`.
fn invalid(message: impl AsRef<[u8]>) {
    zend::throw_new(Class::Validation, message.as_ref());
}

/// `$a . $b` of byte strings.
fn concat(parts: &[&[u8]]) -> Vec<u8> {
    parts.concat()
}

// Calls through `$this`/`$query` -----------------------------------------------------------------

/// `$query->toArray()`: the object's own `toArray()` when its class overrides it.
fn to_array_of(object: &mut ZendObject) -> Option<Zval> {
    // SAFETY: the object's class lives as long as the object.
    let ce = unsafe { &*object.ce.cast::<ClassEntry>() };
    match overridden(ce, Dispatch::ToArray) {
        Some(f) => zend::call(f, Some(object), ce, &mut []),
        None => to_array(object),
    }
}

/// `$node->isNestedJoin()`.
fn is_nested_join_of(object: &mut ZendObject) -> Option<bool> {
    // SAFETY: as in [`to_array_of`].
    let ce = unsafe { &*object.ce.cast::<ClassEntry>() };
    match overridden(ce, Dispatch::IsNestedJoin) {
        Some(f) => zend::call(f, Some(object), ce, &mut []).map(|v| engine::truthy(&v)),
        None => is_nested_join(object),
    }
}

/// `$query->{name}()` (`getMethod`, `getValues`, `getAttribute`) on any value, as PHP calls it:
/// a native object's property, a subclass's override, or PHP's `Error` for a value that is not an
/// object.
fn getter(value: &Zval, dispatch: Dispatch, callee: &engine::Callee, property: &Property) -> Option<Zval> {
    if let Some(object) = query_object(value) {
        // SAFETY: as in [`to_array_of`].
        let ce = unsafe { &*object.ce.cast::<ClassEntry>() };
        return match overridden(ce, dispatch) {
            Some(f) => zend::call(f, Some(object), ce, &mut []),
            None => get(object, property),
        };
    }
    engine::call_method(value.dereference(), callee, &[])
}

static GET_METHOD: engine::Callee = engine::Callee::new("getMethod");
static GET_VALUES: engine::Callee = engine::Callee::new("getValues");
static GET_ATTRIBUTE: engine::Callee = engine::Callee::new("getAttribute");
static TO_ARRAY: engine::Callee = engine::Callee::new("toArray");

/// `$query->getMethod()`.
fn get_method_of(value: &Zval) -> Option<Zval> {
    getter(value, Dispatch::GetMethod, &GET_METHOD, &METHOD)
}

/// `$query->toArray()` on any value.
fn to_array_any(value: &Zval) -> Option<Zval> {
    match query_object(value) {
        Some(object) => to_array_of(object),
        None => engine::call_method(value.dereference(), &TO_ARRAY, &[]),
    }
}

// Methods --------------------------------------------------------------------------------------

/// A type in the methods' signatures: a mask of `MAY_BE_*` bits, or a class (with more bits).
#[derive(Clone, Copy)]
pub enum Ty {
    M(u32),
    C(&'static str, u32),
}

const MAY_BE_OBJECT: u32 = 1 << 8;
const MAY_BE_VOID: u32 = 1 << 14;
const STR: Ty = Ty::M(MAY_BE_STRING);
const INT: Ty = Ty::M(MAY_BE_LONG);
const BOOL: Ty = Ty::M(MAY_BE_BOOL);
const ARRAY: Ty = Ty::M(MAY_BE_ARRAY);
const MIXED: Ty = Ty::M(MAY_BE_ANY);
const STATIC: Ty = Ty::M(engine::MAY_BE_STATIC);
const VOID: Ty = Ty::M(MAY_BE_VOID);
const SCALAR: Ty = Ty::M(MAY_BE_STRING | MAY_BE_LONG | MAY_BE_DOUBLE | MAY_BE_BOOL);
const NUMBER: Ty = Ty::M(MAY_BE_LONG | MAY_BE_DOUBLE);
const METHOD_TYPE: Ty = Ty::C("Utopia\\Query\\Method", 0);

/// A method: name, handler, flags, parameters (name, type, default as PHP source) and return type
/// (`None`: none, the constructor).
pub struct Def {
    pub name: &'static str,
    pub handler: crate::classes::leaf::FunctionHandler,
    pub flags: MethodFlags,
    pub params: &'static [(&'static str, Ty, Option<&'static str>)],
    pub returns: Option<Ty>,
}

const PUBLIC: MethodFlags = MethodFlags::Public;
const STATIC_PUBLIC: MethodFlags = MethodFlags::Public.union(MethodFlags::Static);

const METHODS: &[Def] = &[
    Def {
        name: "__construct",
        handler: construct,
        flags: PUBLIC,
        params: &[
            ("method", Ty::C("Utopia\\Query\\Method", MAY_BE_STRING), None),
            ("attribute", STR, Some("''")),
            ("values", ARRAY, Some("[]")),
            ("alias", STR, Some("''")),
        ],
        returns: None,
    },
    Def { name: "__clone", handler: clone, flags: PUBLIC, params: &[], returns: Some(VOID) },
    Def { name: "getMethod", handler: get_method, flags: PUBLIC, params: &[], returns: Some(METHOD_TYPE) },
    Def { name: "getAttribute", handler: get_attribute, flags: PUBLIC, params: &[], returns: Some(STR) },
    Def { name: "getValues", handler: get_values, flags: PUBLIC, params: &[], returns: Some(ARRAY) },
    Def {
        name: "getValue",
        handler: get_value,
        flags: PUBLIC,
        params: &[("default", MIXED, Some("null"))],
        returns: Some(MIXED),
    },
    Def { name: "isNestedJoin", handler: is_nested_join_method, flags: PUBLIC, params: &[], returns: Some(BOOL) },
    Def { name: "getAlias", handler: get_alias, flags: PUBLIC, params: &[], returns: Some(STR) },
    Def { name: "isAliasDefaulted", handler: is_alias_defaulted, flags: PUBLIC, params: &[], returns: Some(BOOL) },
    Def { name: "getJoinOnQueries", handler: get_join_on_queries, flags: PUBLIC, params: &[], returns: Some(ARRAY) },
    Def {
        name: "setMethod",
        handler: set_method,
        flags: PUBLIC,
        params: &[("method", Ty::C("Utopia\\Query\\Method", MAY_BE_STRING), None)],
        returns: Some(STATIC),
    },
    Def {
        name: "setAttribute",
        handler: set_attribute,
        flags: PUBLIC,
        params: &[("attribute", STR, None)],
        returns: Some(STATIC),
    },
    Def {
        name: "setValues",
        handler: set_values,
        flags: PUBLIC,
        params: &[("values", ARRAY, None)],
        returns: Some(STATIC),
    },
    Def {
        name: "setValue",
        handler: set_value,
        flags: PUBLIC,
        params: &[("value", MIXED, None)],
        returns: Some(STATIC),
    },
    Def {
        name: "isMethod",
        handler: parse::is_method,
        flags: STATIC_PUBLIC,
        params: &[("value", STR, None)],
        returns: Some(BOOL),
    },
    Def { name: "isSpatialQuery", handler: is_spatial_query, flags: PUBLIC, params: &[], returns: Some(BOOL) },
    Def {
        name: "parse",
        handler: parse::parse,
        flags: STATIC_PUBLIC,
        params: &[("query", STR, None), ("allowRaw", BOOL, Some("false"))],
        returns: Some(STATIC),
    },
    Def {
        name: "parseQuery",
        handler: parse::parse_query,
        flags: STATIC_PUBLIC,
        params: &[("query", ARRAY, None), ("allowRaw", BOOL, Some("false"))],
        returns: Some(STATIC),
    },
    Def {
        name: "parseQueries",
        handler: parse::parse_queries,
        flags: STATIC_PUBLIC,
        params: &[("queries", ARRAY, None), ("allowRaw", BOOL, Some("false"))],
        returns: Some(ARRAY),
    },
    Def {
        name: "fingerprint",
        handler: lists::fingerprint,
        flags: STATIC_PUBLIC,
        params: &[("queries", ARRAY, None)],
        returns: Some(STR),
    },
    Def { name: "shape", handler: lists::shape, flags: PUBLIC, params: &[], returns: Some(STR) },
    Def { name: "toArray", handler: to_array_method, flags: PUBLIC, params: &[], returns: Some(ARRAY) },
    Def {
        name: "compile",
        handler: lists::compile,
        flags: PUBLIC,
        params: &[("compiler", Ty::C("Utopia\\Query\\Compiler", 0), None)],
        returns: Some(STR),
    },
    Def { name: "toString", handler: to_string, flags: PUBLIC, params: &[], returns: Some(STR) },
];

const METHODS_AFTER_FACTORIES: &[Def] = &[
    Def {
        name: "getByType",
        handler: lists::get_by_type,
        flags: STATIC_PUBLIC,
        params: &[("queries", ARRAY, None), ("types", ARRAY, None), ("clone", BOOL, Some("true"))],
        returns: Some(ARRAY),
    },
    Def {
        name: "getCursorQueries",
        handler: lists::get_cursor_queries,
        flags: STATIC_PUBLIC,
        params: &[("queries", ARRAY, None), ("clone", BOOL, Some("true"))],
        returns: Some(ARRAY),
    },
    Def {
        name: "groupByType",
        handler: lists::group_by_type,
        flags: STATIC_PUBLIC,
        params: &[("queries", ARRAY, None)],
        returns: Some(Ty::C("Utopia\\Query\\Builder\\ParsedQuery", 0)),
    },
    Def { name: "isNested", handler: is_nested, flags: PUBLIC, params: &[], returns: Some(BOOL) },
    Def { name: "onArray", handler: on_array, flags: PUBLIC, params: &[], returns: Some(BOOL) },
    Def {
        name: "setOnArray",
        handler: set_on_array,
        flags: PUBLIC,
        params: &[("bool", BOOL, None)],
        returns: Some(VOID),
    },
    Def {
        name: "setAttributeType",
        handler: set_attribute_type,
        flags: PUBLIC,
        params: &[("type", STR, None)],
        returns: Some(VOID),
    },
    Def { name: "getAttributeType", handler: get_attribute_type, flags: PUBLIC, params: &[], returns: Some(STR) },
];

/// Every method, in `Query.php`'s order.
fn methods() -> impl Iterator<Item = &'static Def> {
    METHODS.iter().chain(factories::FIRST).chain(METHODS_AFTER_FACTORIES).chain(factories::SECOND).chain(lists::LAST)
}

/// The type ext-php-rs builds a parameter or return with, before [`register_class`] sets the
/// exact one (no class extends this one before then, so nothing checks it).
fn builder(def: &Def) -> FunctionBuilder<'static> {
    let mut function = FunctionBuilder::new(def.name, def.handler);
    let required = def.params.iter().take_while(|(_, _, default)| default.is_none()).count();
    for (name, _, default) in def.params {
        let arg = Arg::new(*name, DataType::Mixed);
        function = function.arg(match default {
            Some(default) => arg.default(*default),
            None => arg,
        });
    }
    let function = function.required_args(required);
    match def.returns {
        // The engine checks a magic method's return type as it registers it (`__clone(): void`).
        Some(Ty::M(MAY_BE_VOID)) => function.returns(DataType::Void, false, false),
        Some(_) => function.returns(DataType::Mixed, false, false),
        None => function,
    }
}

/// Sets `info`'s type to `ty`, keeping its flags beyond the type mask.
fn set(info: &mut ext_php_rs::args::ArgInfo, ty: Ty) {
    /// `_ZEND_TYPE_MASK`.
    const TYPE_MASK: u32 = (1 << 25) - 1;
    let flags = info.type_.type_mask & !TYPE_MASK;
    match ty {
        Ty::M(mask) => {
            info.type_.ptr = std::ptr::null_mut();
            info.type_.type_mask = flags | mask;
        }
        Ty::C(name, mask) => {
            engine::set_class_type(info, name);
            info.type_.type_mask = (info.type_.type_mask & !engine::MAY_BE_ANY) | mask;
        }
    }
}

fn register_class(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    let method_type = PropertyType::Class { name: "Utopia\\Query\\Method", nullable: false };
    let declared = Declared { private: false, readonly: false, promoted: false };
    engine::declare_property(class, &METHOD, method_type, declared, None);
    engine::declare_property(class, &ATTRIBUTE, PropertyType::Mask(MAY_BE_STRING), declared, Some(engine::string(b"")));
    engine::declare_property(
        class,
        &ATTRIBUTE_TYPE,
        PropertyType::Mask(MAY_BE_STRING),
        declared,
        Some(engine::string(b"")),
    );
    let mut no = Zval::new();
    no.set_bool(false);
    engine::declare_property(class, &ON_ARRAY, PropertyType::Mask(MAY_BE_BOOL), declared, Some(no));
    engine::declare_property(class, &VALUES, PropertyType::Mask(MAY_BE_ARRAY), declared, Some(engine::empty_array()));
    engine::declare_property(class, &ALIAS, PropertyType::Mask(MAY_BE_STRING), declared, Some(engine::string(b"")));
    let mut no = Zval::new();
    no.set_bool(false);
    engine::declare_property(class, &ALIAS_DEFAULTED, PropertyType::Mask(MAY_BE_BOOL), declared, Some(no));
    engine::declare_constant(
        class,
        "GROUP_BY_TIME_BUCKET_INTERVALS",
        engine::string_list(factories::INTERVALS),
        MAY_BE_ARRAY,
    );
    for def in methods() {
        let Some(info) = engine::arg_info(class, def.name) else { continue };
        let (returns, params) = info.split_at_mut(1);
        if let Some(ty) = def.returns {
            set(&mut returns[0], ty);
        }
        for ((_, ty, _), param) in def.params.iter().zip(params.iter_mut()) {
            set(param, *ty);
        }
    }
    for callee in [&GET_METHOD, &GET_VALUES, &GET_ATTRIBUTE, &TO_ARRAY] {
        callee.intern();
    }
    lists::intern();
    engine::intern(&[&KEY_METHOD, &KEY_ATTRIBUTE, &KEY_ALIAS, &KEY_VALUES]);
    deprecate(class, "contains", factories::CONTAINS_DEPRECATED);
    CLASS.store(class, Ordering::Release);
    Box::leak(tables);
}

/// `zend_attribute_arg`.
#[repr(C)]
struct AttributeArg {
    name: *mut ext_php_rs::ffi::zend_string,
    value: Zval,
}

/// `zend_attribute` (PHP 8.5), with one argument.
#[repr(C)]
struct Attribute {
    name: *mut ext_php_rs::ffi::zend_string,
    lcname: *mut ext_php_rs::ffi::zend_string,
    validation_error: *mut ext_php_rs::ffi::zend_string,
    flags: u32,
    lineno: u32,
    offset: u32,
    argc: u32,
    args: [AttributeArg; 1],
}

unsafe extern "C" {
    fn zend_add_attribute(
        attributes: *mut *mut ext_php_rs::ffi::HashTable,
        name: *mut ext_php_rs::ffi::zend_string,
        argc: u32,
        flags: u32,
        offset: u32,
        lineno: u32,
    ) -> *mut Attribute;
}

/// `#[\Deprecated($message)]` on the method `name` of `class`, as reflection reads it.
fn deprecate(class: &ClassEntry, name: &str, message: &str) {
    /// `ZEND_ATTRIBUTE_PERSISTENT`.
    const PERSISTENT: u32 = 1;
    let function = zend::function(class, &name.to_ascii_lowercase());
    // SAFETY: MINIT: the method is the class's, its attributes table persistent like the
    // attribute's name and argument (interned strings).
    unsafe {
        let Some(function) = function.as_mut() else { return };
        let attribute_name = engine::interned(b"Deprecated");
        let attribute =
            zend_add_attribute(&raw mut function.common.attributes, attribute_name.value.str_, 1, PERSISTENT, 0, 0);
        std::mem::forget(attribute_name);
        if let Some(attribute) = attribute.as_mut() {
            attribute.args[0].name = std::ptr::null_mut();
            std::ptr::write(&raw mut attribute.args[0].value, engine::interned(message.as_bytes()));
        }
    }
}

/// Registers `Utopia\Query\Query`. Called from the module's startup (MINIT).
pub fn register() -> ext_php_rs::error::Result<()> {
    let mut class = ClassBuilder::new(NAME).constant("DEFAULT_ALIAS", "main", &[], ConstantFlags::Public)?;
    for def in methods() {
        class = class.method(builder(def), def.flags);
    }
    class.registration(register_class).register()
}

/// Forgets the classes looked up this request.
pub fn request_shutdown() {
    zend::request_shutdown();
}

// Handlers shared by the submodules -----------------------------------------------------------

/// The method's `$this`.
#[inline]
fn this(ex: &mut ExecuteData) -> Option<&'static mut ZendObject> {
    crate::classes::leaf::this(ex)
}

/// Returns `$this` (the setters' `static`).
fn return_this(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(this) = this(ex) {
        retval.set_object(this);
    }
}

extern "C" fn construct(ex: &mut ExecuteData, _: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let Some(this) = this(ex) else { return };
    let Some(method_arg) = args.get(0) else { return };
    let (case, method) = match zend::method_of(method_arg.dereference()) {
        Some(method) => (method_arg.dereference().shallow_clone(), method),
        None => {
            let Some(name) = method_arg.dereference().zend_str() else { return };
            let Some(case) = zend::method_from(name) else { return };
            let Some(method) = zend::method_of(&case) else { return };
            (case, method)
        }
    };
    initialize(this, case, method, args.str_or(1, b""), args.array_or_empty(2), args.str_or(3, b""));
}

extern "C" fn clone(ex: &mut ExecuteData, _: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(values) = get(this, &VALUES) else { return };
    // `$this->values[$index] = clone $value` on a subclass's hooked `$values`: PHP's `Error`.
    if values.array().is_some_and(|a| engine::values(a).any(|v| query_object(v).is_some()))
        && let Some(class) = zend::hooked(this, VALUES.offset())
    {
        engine::error(&format!("Indirect modification of {class}::$values is not allowed"));
        return;
    }
    let mut cloned = false;
    let (copy, done) = engine::map_values(&values, |value| match query_object(value) {
        Some(object) => {
            cloned = true;
            lists::clone_object(object).map(Some)
        }
        None => Some(None),
    });
    if done && cloned {
        put(this, &VALUES, copy);
    }
}

extern "C" fn get_method(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(this) = this(ex)
        && let Some(value) = get(this, &METHOD)
    {
        *retval = value;
    }
}

fn getter_handler(ex: &mut ExecuteData, retval: &mut Zval, property: &Property) {
    if let Some(this) = this(ex)
        && let Some(value) = get(this, property)
    {
        *retval = value;
    }
}

extern "C" fn get_attribute(ex: &mut ExecuteData, retval: &mut Zval) {
    getter_handler(ex, retval, &ATTRIBUTE);
}
extern "C" fn get_values(ex: &mut ExecuteData, retval: &mut Zval) {
    getter_handler(ex, retval, &VALUES);
}
extern "C" fn get_alias(ex: &mut ExecuteData, retval: &mut Zval) {
    getter_handler(ex, retval, &ALIAS);
}
extern "C" fn is_alias_defaulted(ex: &mut ExecuteData, retval: &mut Zval) {
    getter_handler(ex, retval, &ALIAS_DEFAULTED);
}
extern "C" fn on_array(ex: &mut ExecuteData, retval: &mut Zval) {
    getter_handler(ex, retval, &ON_ARRAY);
}
extern "C" fn get_attribute_type(ex: &mut ExecuteData, retval: &mut Zval) {
    getter_handler(ex, retval, &ATTRIBUTE_TYPE);
}

extern "C" fn get_value(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let Some(this) = this(ex) else { return };
    let Some(values) = get(this, &VALUES) else { return };
    let first = values.array().and_then(|a| a.get_index(0)).map(Zval::dereference).filter(|v| !v.is_null());
    *retval = match first {
        Some(value) => value.shallow_clone(),
        None => args.value(0),
    };
}

/// `isNestedJoin()` of an object of the class, natively.
fn is_nested_join(this: &mut ZendObject) -> Option<bool> {
    let (_, method) = method(this)?;
    if !method.is_join() {
        return Some(false);
    }
    let values = get(this, &VALUES)?;
    Some(values.array().is_some_and(|a| engine::values(a).any(|v| zend::instance_of(v.dereference(), base()))))
}

extern "C" fn is_nested_join_method(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(this) = this(ex)
        && let Some(nested) = is_nested_join(this)
    {
        retval.set_bool(nested);
    }
}

extern "C" fn get_join_on_queries(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(nested) = is_nested_join_of(this) else { return };
    if !nested {
        *retval = zend::list(Vec::new());
        return;
    }
    let Some(values) = get(this, &VALUES) else { return };
    let queries = values
        .array()
        .map(|a| {
            engine::values(a)
                .map(Zval::dereference)
                .filter(|v| zend::instance_of(v, base()))
                .map(Zval::shallow_clone)
                .collect()
        })
        .unwrap_or_default();
    *retval = zend::list(queries);
}

extern "C" fn set_method(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let Some(this) = this(ex) else { return };
    let Some(arg) = args.get(0) else { return };
    let case = if zend::method_of(arg.dereference()).is_some() {
        arg.dereference().shallow_clone()
    } else {
        let Some(name) = arg.dereference().zend_str() else { return };
        let Some(case) = zend::method_from(name) else { return };
        case
    };
    put(this, &METHOD, case);
    return_this(ex, retval);
}

fn setter(ex: &mut ExecuteData, retval: Option<&mut Zval>, property: &Property) {
    let Some(args) = zend::args(ex) else { return };
    let Some(this) = this(ex) else { return };
    put(this, property, args.value(0));
    if let Some(retval) = retval {
        return_this(ex, retval);
    }
}

extern "C" fn set_attribute(ex: &mut ExecuteData, retval: &mut Zval) {
    setter(ex, Some(retval), &ATTRIBUTE);
}
extern "C" fn set_values(ex: &mut ExecuteData, retval: &mut Zval) {
    setter(ex, Some(retval), &VALUES);
}
extern "C" fn set_on_array(ex: &mut ExecuteData, _: &mut Zval) {
    setter(ex, None, &ON_ARRAY);
}
extern "C" fn set_attribute_type(ex: &mut ExecuteData, _: &mut Zval) {
    setter(ex, None, &ATTRIBUTE_TYPE);
}

extern "C" fn set_value(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let Some(this) = this(ex) else { return };
    put(this, &VALUES, zend::list(vec![args.value(0)]));
    return_this(ex, retval);
}

fn method_predicate(ex: &mut ExecuteData, retval: &mut Zval, predicate: fn(Method) -> bool) {
    if let Some(this) = this(ex)
        && let Some((_, method)) = method(this)
    {
        retval.set_bool(predicate(method));
    }
}

extern "C" fn is_spatial_query(ex: &mut ExecuteData, retval: &mut Zval) {
    method_predicate(ex, retval, Method::is_spatial);
}
extern "C" fn is_nested(ex: &mut ExecuteData, retval: &mut Zval) {
    method_predicate(ex, retval, Method::is_nested);
}

// The keys `toArray()` writes, interned in MINIT.
static KEY_METHOD: engine::Literal = engine::Literal::new("method");
static KEY_ATTRIBUTE: engine::Literal = engine::Literal::new("attribute");
static KEY_ALIAS: engine::Literal = engine::Literal::new("alias");
static KEY_VALUES: engine::Literal = engine::Literal::new("values");

unsafe extern "C" {
    fn zend_hash_add_new(
        ht: *mut ext_php_rs::ffi::HashTable,
        key: *mut ext_php_rs::ffi::zend_string,
        value: *mut ext_php_rs::ffi::zval,
    ) -> *mut ext_php_rs::ffi::zval;
}

/// `$array[$key] = $value` for a key not in `array` yet.
fn add(array: &mut ext_php_rs::types::ZendHashTable, key: &engine::Literal, mut value: Zval) {
    let key = key.zval();
    // SAFETY: a live table, an interned key, and a value the table takes over.
    unsafe {
        zend_hash_add_new(std::ptr::from_mut(array), key.value.str_, std::ptr::from_mut(&mut value).cast());
    }
    std::mem::forget(value);
}

/// `toArray()` of an object of the class, natively.
fn to_array(this: &mut ZendObject) -> Option<Zval> {
    let case = get(this, &METHOD)?;
    // `$this->method->value`: the case's own string, shared.
    let method = match case.object() {
        // SAFETY: an enum case holds its value in its second slot.
        Some(object) => unsafe {
            let table = std::ptr::from_ref(object)
                .cast::<u8>()
                .add(std::mem::offset_of!(ext_php_rs::ffi::zend_object, properties_table));
            (*table.cast::<Zval>().add(1)).shallow_clone()
        },
        None => engine::string(b""),
    };
    // `! empty($this->attribute)`: an uninitialized attribute is empty, without an error.
    let attribute = get_quietly(this, &ATTRIBUTE);
    let alias = get(this, &ALIAS)?;
    let values = get(this, &VALUES)?;
    let mut array = ext_php_rs::types::ZendHashTable::with_capacity(4);
    add(&mut array, &KEY_METHOD, method);
    if attribute.zend_str().is_some_and(|a| !matches!(a.as_bytes(), b"" | b"0")) {
        add(&mut array, &KEY_ATTRIBUTE, attribute);
    }
    if alias.zend_str().is_some_and(|a| !a.as_bytes().is_empty()) {
        add(&mut array, &KEY_ALIAS, alias);
    }
    let mut list = Vec::new();
    if let Some(values) = values.array() {
        list.reserve(values.len());
        for value in engine::values(values) {
            let value = value.dereference();
            match query_object(value) {
                Some(child) => list.push(to_array_of(child)?),
                None => list.push(value.shallow_clone()),
            }
        }
    }
    add(&mut array, &KEY_VALUES, zend::list(list));
    let mut zval = Zval::new();
    zval.set_hashtable(array);
    Some(zval)
}

extern "C" fn to_array_method(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(this) = this(ex)
        && let Some(array) = to_array(this)
    {
        *retval = array;
    }
}

/// `smart_str`.
#[repr(C)]
struct SmartStr {
    s: *mut ext_php_rs::ffi::zend_string,
    a: usize,
}

/// ext/json's globals (`JSON_G`), in a PHP built without ZTS.
#[repr(C)]
struct JsonGlobals {
    encoder_depth: i32,
    encode_max_depth: i32,
    error_code: i32,
}

unsafe extern "C" {
    fn php_json_encode_ex(buf: *mut SmartStr, value: *mut ext_php_rs::ffi::zval, options: i32, depth: i64) -> i32;
    static mut json_globals: JsonGlobals;
}

/// `json_encode($value, flags: JSON_THROW_ON_ERROR)` as `toString()` calls it: the JSON, or the
/// `Utopia\Query\Exception` of `Invalid Json: ...` (or an exception `jsonSerialize()` threw) pending.
/// ext/json's encoder is called directly; `json_last_error()` is left as it was, as
/// `JSON_THROW_ON_ERROR` leaves it.
fn json_encode(value: &Zval) -> Option<Zval> {
    let mut buffer = SmartStr { s: std::ptr::null_mut(), a: 0 };
    let mut value = value.shallow_clone();
    // SAFETY: the engine's encoder over a live value; the globals are the process's (NTS).
    let (result, code) = unsafe {
        let saved = json_globals.error_code;
        let result = php_json_encode_ex(&raw mut buffer, std::ptr::from_mut(&mut value).cast(), 0, 512);
        let code = json_globals.error_code;
        json_globals.error_code = saved;
        (result, code)
    };
    let json = if buffer.s.is_null() {
        engine::string(b"")
    } else {
        // SAFETY: the buffer's string, terminated as `smart_str_0()` does (a smart_str always
        // has room for it; PHP's string functions read the NUL), then owned by the zval.
        let mut zval = Zval::new();
        unsafe {
            let string = &mut *buffer.s;
            *string.val.as_mut_ptr().add(string.len) = 0;
            zval.set_zend_string(ext_php_rs::boxed::ZBox::from_raw(buffer.s.cast::<ZendStr>()));
        }
        zval
    };
    if engine::has_exception() {
        return None;
    }
    if result != 0 || code != 0 {
        use php_std::json::ErrorCode as C;
        let message = [
            C::None,
            C::Depth,
            C::StateMismatch,
            C::CtrlChar,
            C::Syntax,
            C::Utf8,
            C::Recursion,
            C::InfOrNan,
            C::UnsupportedType,
            C::InvalidPropertyName,
            C::Utf16,
            C::NonBackedEnum,
        ]
        .into_iter()
        .find(|c| c.code() == i64::from(code))
        .map_or("Unknown error", C::message);
        fail(concat(&[b"Invalid Json: ", message.as_bytes()]));
        return None;
    }
    Some(json)
}

extern "C" fn to_string(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(array) = to_array_of(this) else { return };
    if let Some(json) = json_encode(&array) {
        *retval = json;
    }
}
