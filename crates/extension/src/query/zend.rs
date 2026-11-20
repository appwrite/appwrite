//! The engine calls `Utopia\Query\Query` needs beyond [`crate::classes::engine`]: the called
//! scope of a static method (`static::`), `new static(...)`, calling a method of a class the way
//! `static::method()` and `$object->method()` do, a method's arguments checked against its
//! declared types, PHP's own exception constructors, enum cases, and the classes and methods the
//! binding looks up once per request.

use std::cell::RefCell;
use std::ffi::c_void;

use ext_php_rs::ffi::{
    zend_arg_info, zend_class_entry, zend_execute_data, zend_function, zend_hash_str_find_ptr_lc, zend_object,
    zend_string, zend_type, zval,
};
use ext_php_rs::types::{ZendHashTable, ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData};
use utopia_query::Method;

use crate::classes::engine::{self, has_exception};

unsafe extern "C" {
    fn zend_get_called_scope(ex: *mut zend_execute_data) -> *mut zend_class_entry;
    fn object_init_ex(arg: *mut zval, ce: *mut zend_class_entry) -> i32;
    fn zend_check_user_type_slow(ty: *const zend_type, arg: *mut zval, reference: *const c_void, ret: bool) -> bool;
    fn zend_verify_arg_error(zf: *const zend_function, info: *const zend_arg_info, num: u32, value: *const zval);
    fn zend_lookup_class(name: *mut zend_string) -> *mut zend_class_entry;
    fn zend_throw_exception_object(exception: *mut zval);
    fn zend_clear_exception();
    fn instanceof_function_slow(instance: *const zend_class_entry, ce: *const zend_class_entry) -> bool;
    fn zend_enum_get_case_by_value(
        result: *mut *mut zend_object,
        ce: *mut zend_class_entry,
        long_key: i64,
        string_key: *mut zend_string,
        try_from: bool,
    ) -> i32;
    fn zend_enum_get_case_cstr(ce: *mut zend_class_entry, name: *const std::ffi::c_char) -> *mut zend_object;
}

/// `GC_ADD_FLAGS(obj, IS_OBJ_DESTRUCTOR_CALLED)`: what `zend_object_store_ctor_failed()` sets.
const IS_OBJ_DESTRUCTOR_CALLED: u32 = 1 << 8;
/// `ZEND_INTERNAL_FUNCTION`.
const INTERNAL_FUNCTION: u8 = 1;

/// The class a static method was called on (`static::`), or `$this`'s class.
#[inline]
pub fn called_scope(ex: &mut ExecuteData) -> &'static ClassEntry {
    // SAFETY: the executing frame; the engine returns a class alive for the request.
    unsafe { &*zend_get_called_scope(std::ptr::from_mut(ex).cast()).cast::<ClassEntry>() }
}

/// The object `value` holds (not dereferenced), mutable as the engine's objects are.
#[inline]
pub fn object(value: &Zval) -> Option<&'static mut ZendObject> {
    if !value.is_object() {
        return None;
    }
    // SAFETY: an object zval points at a live object, kept alive by whoever holds the value.
    unsafe { value.value.obj.cast::<ZendObject>().as_mut() }
}

/// Whether `ce` is `parent` or extends it (`instanceof`).
#[inline]
pub fn extends(ce: *const zend_class_entry, parent: &ClassEntry) -> bool {
    std::ptr::eq(ce, parent) || unsafe { instanceof_function_slow(ce, parent) }
}

/// Whether `value` is an object of `class` or a subclass (`$value instanceof self`).
#[inline]
pub fn instance_of(value: &Zval, class: &ClassEntry) -> bool {
    value.object().is_some_and(|object| extends(object.ce, class))
}

/// The function `name` (lowercase) of `ce`.
#[inline]
pub fn function(ce: &ClassEntry, name: &str) -> *mut zend_function {
    // SAFETY: the class's function table lives as long as the class.
    unsafe {
        zend_hash_str_find_ptr_lc(&raw const ce.function_table, name.as_ptr().cast(), name.len())
            .cast::<zend_function>()
    }
}

/// Whether `function` is one of the native class's own methods (not a PHP override).
#[inline]
pub fn is_native(function: *const zend_function, base: &ClassEntry) -> bool {
    // SAFETY: a live function of a live class.
    unsafe { function.as_ref().is_some_and(|f| f.type_ == INTERNAL_FUNCTION && std::ptr::eq(f.common.scope, base)) }
}

/// Calls `function` (a method of `scope`) on `object` (`None`: statically, with `scope` as the
/// called scope, as `static::f()` calls it) with `args`. `None` with an exception pending.
pub fn call(
    function: *mut zend_function,
    object: Option<&mut ZendObject>,
    scope: *const zend_class_entry,
    args: &mut [Zval],
) -> Option<Zval> {
    let mut retval = Zval::new();
    let object = object.map_or(std::ptr::null_mut(), std::ptr::from_mut);
    let scope = if object.is_null() { scope.cast_mut() } else { unsafe { (*object).ce } };
    // SAFETY: a live function, object and class; the engine writes the result into `retval`.
    unsafe {
        ext_php_rs::ffi::zend_call_known_function(
            function,
            object,
            scope,
            std::ptr::from_mut(&mut retval).cast(),
            u32::try_from(args.len()).unwrap_or(u32::MAX),
            args.as_mut_ptr().cast(),
            std::ptr::null_mut(),
        );
    }
    if has_exception() { None } else { Some(retval) }
}

/// A new, unconstructed object of `ce` (`Error` for an abstract class, as `new` throws).
pub fn instantiate(ce: &ClassEntry) -> Option<Zval> {
    let mut object = Zval::new();
    // SAFETY: a live class; on failure the engine has thrown.
    let ok = unsafe { object_init_ex(std::ptr::from_mut(&mut object).cast(), std::ptr::from_ref(ce).cast_mut()) };
    (ok == 0 && !has_exception()).then_some(object)
}

/// `new $ce(...$args)` through its PHP constructor `ctor`: the object, or `None` with the
/// exception pending (the object released without its destructor, as `new` leaves it).
pub fn construct_with(ce: &ClassEntry, ctor: *mut zend_function, args: &mut [Zval]) -> Option<Zval> {
    let mut object = instantiate(ce)?;
    let obj = object.object_mut()?;
    let ptr = std::ptr::from_mut(obj);
    if call(ctor, Some(obj), ce, args).is_none() {
        // SAFETY: the object is alive until `object` drops.
        unsafe { (*ptr).gc.u.type_info |= IS_OBJ_DESTRUCTOR_CALLED };
        return None;
    }
    Some(object)
}

/// The class `name`, autoloaded as PHP looks a class up.
pub fn lookup(name: &str) -> Option<&'static ClassEntry> {
    let name = ZendStr::new(name, false);
    // SAFETY: a live string; the engine returns a class alive for the request.
    unsafe { zend_lookup_class(std::ptr::from_ref(&*name).cast_mut().cast()).cast::<ClassEntry>().as_ref() }
}

/// `throw new $class($message)`: through the class's own constructor, as PHP builds it.
pub fn throw_new(class: Class, message: &[u8]) {
    let Some(ce) = class.get() else { return };
    let mut args = [engine::string(message)];
    let Some(mut exception) = (match ce.constructor {
        ctor if !ctor.is_null() => construct_with(ce, ctor, &mut args),
        _ => instantiate(ce),
    }) else {
        return;
    };
    // SAFETY: a live exception object, which the engine takes a reference to.
    unsafe { zend_throw_exception_object(std::ptr::from_mut(&mut exception).cast()) };
    std::mem::forget(exception);
}

/// The pending exception, if it is an instance of `class`: taken off (cleared) and returned.
pub fn catch(class: &ClassEntry) -> Option<Zval> {
    // SAFETY: the engine's globals, on the thread running PHP.
    unsafe {
        let globals = ext_php_rs::ffi::ext_php_rs_executor_globals();
        let exception = (*globals).exception;
        if exception.is_null() || !extends((*exception).ce, class) {
            return None;
        }
        let mut caught = Zval::new();
        caught.set_object(&mut *exception.cast::<ZendObject>());
        zend_clear_exception();
        Some(caught)
    }
}

/// The classes the binding refers to by name, looked up (autoloaded) once per request.
#[derive(Clone, Copy)]
pub enum Class {
    Method,
    Exception,
    Validation,
    ParsedQuery,
    Json,
}

impl Class {
    pub const fn name(self) -> &'static str {
        match self {
            Class::Method => "Utopia\\Query\\Method",
            Class::Exception => "Utopia\\Query\\Exception",
            Class::Validation => "Utopia\\Query\\Exception\\ValidationException",
            Class::ParsedQuery => "Utopia\\Query\\Builder\\ParsedQuery",
            Class::Json => "JsonException",
        }
    }

    /// The class, `None` with PHP's `Error` pending when it does not exist.
    pub fn get(self) -> Option<&'static ClassEntry> {
        let index = self as usize;
        let cached = CACHE.with(|cache| cache.borrow().classes[index]);
        if !cached.is_null() {
            // SAFETY: looked up this request; classes live until the request ends.
            return Some(unsafe { &*cached });
        }
        let found = lookup(self.name());
        match found {
            Some(ce) => CACHE.with(|cache| cache.borrow_mut().classes[index] = std::ptr::from_ref(ce)),
            None if !has_exception() => engine::error(&format!("Class \"{}\" not found", self.name())),
            None => {}
        }
        found
    }
}

/// What a class extending the native one overrides, and whether its objects' property slots
/// may be reached directly.
#[derive(Clone, Copy)]
pub struct Info {
    /// Every property the native class declares is the native one's (same slot, no hooks).
    pub plain: bool,
    /// The class's own method for each [`Dispatch`], null when it is the native one.
    pub overrides: [*mut zend_function; DISPATCH],
    /// The class's constructor, null when it is the native one.
    pub ctor: *mut zend_function,
}

/// The methods the native class calls through `static::` or `$this->`, which a subclass may
/// override.
#[derive(Clone, Copy)]
pub enum Dispatch {
    Parse,
    ParseQuery,
    IsMethod,
    On,
    ToArray,
    Shape,
    IsNestedJoin,
    GetMethod,
    GetValues,
    GetAttribute,
    LessThan,
    GreaterThan,
    Between,
    Limit,
    Offset,
    Validate,
}

const DISPATCH: usize = 16;
const NAMES: [&str; DISPATCH] = [
    "parse",
    "parsequery",
    "ismethod",
    "on",
    "toarray",
    "shape",
    "isnestedjoin",
    "getmethod",
    "getvalues",
    "getattribute",
    "lessthan",
    "greaterthan",
    "between",
    "limit",
    "offset",
    "validate",
];

const CLASSES: usize = 5;

struct Cache {
    classes: [*const ClassEntry; CLASSES],
    infos: Vec<(*const ClassEntry, Info)>,
    cases: Vec<*mut zend_object>,
}

thread_local! {
    static CACHE: RefCell<Cache> = const {
        RefCell::new(Cache { classes: [std::ptr::null(); CLASSES], infos: Vec::new(), cases: Vec::new() })
    };
}

/// Forgets the classes and objects looked up this request.
pub fn request_shutdown() {
    CACHE.with(|cache| {
        let mut cache = cache.borrow_mut();
        cache.classes = [std::ptr::null(); CLASSES];
        cache.infos.clear();
        cache.cases.clear();
    });
}

/// What `ce` (the native class or a subclass) overrides; `offsets` are the native properties'.
pub fn info(ce: &ClassEntry, base: &ClassEntry, offsets: &[u32]) -> Info {
    let key = std::ptr::from_ref(ce);
    if let Some(info) = CACHE.with(|cache| cache.borrow().infos.iter().find(|(c, _)| *c == key).map(|(_, i)| *i)) {
        return info;
    }
    let mut overrides = [std::ptr::null_mut(); DISPATCH];
    if !std::ptr::eq(ce, base) {
        for (slot, name) in overrides.iter_mut().zip(NAMES) {
            let f = function(ce, name);
            if !is_native(f, base) {
                *slot = f;
            }
        }
    }
    let ctor = if is_native(ce.constructor, base) { std::ptr::null_mut() } else { ce.constructor };
    let plain = std::ptr::eq(ce, base) || offsets.iter().all(|&offset| plain_slot(ce, offset));
    let info = Info { plain, overrides, ctor };
    CACHE.with(|cache| cache.borrow_mut().infos.push((key, info)));
    info
}

/// The name of `object`'s class when the property at `offset` has hooks there.
pub fn hooked(object: &ZendObject, offset: u32) -> Option<String> {
    // SAFETY: the object's class lives as long as the object.
    let ce = unsafe { &*object.ce.cast::<ClassEntry>() };
    let first = std::mem::offset_of!(zend_object, properties_table) as u32;
    let index = (offset.checked_sub(first)? / std::mem::size_of::<zval>() as u32) as usize;
    if ce.properties_info_table.is_null() || index >= usize::try_from(ce.default_properties_count).unwrap_or(0) {
        return None;
    }
    // SAFETY: one entry per declared slot.
    let info = unsafe { (*ce.properties_info_table.add(index)).as_ref()? };
    if info.hooks.is_null() {
        return None;
    }
    object.get_class_name().ok()
}

/// Whether the property at `offset` of `ce`'s objects is declared without hooks.
fn plain_slot(ce: &ClassEntry, offset: u32) -> bool {
    let first = std::mem::offset_of!(zend_object, properties_table) as u32;
    let size = std::mem::size_of::<zval>() as u32;
    let Some(index) = offset.checked_sub(first).map(|o| (o / size) as usize) else { return false };
    if index >= usize::try_from(ce.default_properties_count).unwrap_or(0) || ce.properties_info_table.is_null() {
        return false;
    }
    // SAFETY: the table has one entry per declared slot.
    unsafe {
        let info = *ce.properties_info_table.add(index);
        info.as_ref().is_some_and(|info| info.offset == offset && info.hooks.is_null())
    }
}

/// The enum case `Method::$method`, `None` with an exception pending.
pub fn case(method: Method) -> Option<Zval> {
    let index = method as usize;
    let cached = CACHE.with(|cache| cache.borrow().cases.get(index).copied().unwrap_or(std::ptr::null_mut()));
    let object = if cached.is_null() {
        let ce = Class::Method.get()?;
        let name = std::ffi::CString::new(method.name()).ok()?;
        // SAFETY: a live enum class; the case object lives as long as its constants table.
        let object = unsafe { zend_enum_get_case_cstr(std::ptr::from_ref(ce).cast_mut(), name.as_ptr()) };
        if object.is_null() || has_exception() {
            return None;
        }
        CACHE.with(|cache| {
            let mut cache = cache.borrow_mut();
            if cache.cases.len() <= index {
                cache.cases.resize(Method::CASES.len().max(index + 1), std::ptr::null_mut());
            }
            cache.cases[index] = object;
        });
        object
    } else {
        cached
    };
    let mut zval = Zval::new();
    // SAFETY: a live enum case.
    zval.set_object(unsafe { &mut *object.cast::<ZendObject>() });
    Some(zval)
}

/// `Method::from($value)`: the case, or `ValueError` pending.
pub fn method_from(value: &ZendStr) -> Option<Zval> {
    let ce = Class::Method.get()?;
    let mut object: *mut zend_object = std::ptr::null_mut();
    // SAFETY: a live enum class and string; on failure the engine has thrown.
    let ok = unsafe {
        zend_enum_get_case_by_value(
            &raw mut object,
            std::ptr::from_ref(ce).cast_mut(),
            0,
            std::ptr::from_ref(value).cast_mut().cast(),
            false,
        )
    };
    if ok != 0 || object.is_null() {
        return None;
    }
    let mut zval = Zval::new();
    zval.set_object(unsafe { &mut *object.cast::<ZendObject>() });
    Some(zval)
}

/// The backing value of an enum case object (`$case->value`).
#[inline]
pub fn case_value(case: &ZendObject) -> Option<&ZendStr> {
    // SAFETY: an enum case holds its name and its value in its first two property slots.
    unsafe {
        let table = std::ptr::from_ref(case).cast::<u8>().add(std::mem::offset_of!(zend_object, properties_table));
        (*table.cast::<Zval>().add(1)).zend_str()
    }
}

/// The method of a `Method` case object.
#[inline]
pub fn method_of(case: &Zval) -> Option<Method> {
    let object = case.object()?;
    case_value(object).and_then(|s| s.as_str().ok()).and_then(Method::try_from_value)
}

/// The executing function.
#[inline]
pub fn executing(ex: &ExecuteData) -> &'static zend_function {
    // SAFETY: the frame's function outlives the call.
    unsafe { &*ex.func }
}

/// `Class::method` of the executing function, as PHP names it in errors.
pub fn function_name(ex: &ExecuteData) -> String {
    let f = executing(ex);
    // SAFETY: a live function, its class and their names.
    unsafe {
        let text = |s: *mut zend_string| {
            s.cast::<ZendStr>().as_ref().map(|s| String::from_utf8_lossy(s.as_bytes()).into_owned()).unwrap_or_default()
        };
        let scope = f.common.scope.as_ref().map(|c| text(c.name)).unwrap_or_default();
        format!("{scope}::{}", text(f.common.function_name))
    }
}

/// The method's arguments, checked against the types it declares as PHP checks a userland
/// function's (coerced in place in weak mode, in order, then their count): `None` with the
/// `TypeError` or `ArgumentCountError` pending.
pub fn args(ex: &mut ExecuteData) -> Option<Args> {
    let f = executing(ex);
    // SAFETY: the engine sets the frame's argument count.
    let passed = unsafe { ex.This.u2.num_args } as usize;
    // SAFETY: every function starts with the common header.
    let (declared, required) = unsafe { (f.common.num_args as usize, f.common.required_num_args as usize) };
    let count = passed.min(declared);
    let mut args = Args { values: [std::ptr::null_mut(); 4], count };
    for i in 0..count.min(4) {
        // SAFETY: within the arguments passed.
        let arg = unsafe { ex.zend_call_arg(i) }?;
        // SAFETY: the function's argument info holds `num_args` entries.
        let info = unsafe { &*f.common.arg_info.add(i) };
        if !accepts(&info.type_, arg) {
            // SAFETY: as above; the engine throws the userland function's `TypeError`.
            unsafe { zend_verify_arg_error(f, info, (i + 1) as u32, std::ptr::from_ref(arg).cast()) };
            return None;
        }
        args.values[i] = std::ptr::from_mut(arg);
    }
    // A userland function checks each argument passed, in order, before it finds one missing.
    if passed < required {
        engine::too_few(ex, &function_name(ex), passed, required, declared);
        return None;
    }
    Some(args)
}

/// Whether `arg` is of `ty`, coercing it in weak mode as the engine does for a parameter.
#[inline]
fn accepts(ty: &zend_type, arg: &mut Zval) -> bool {
    let mask = ty.type_mask;
    // SAFETY: a live zval.
    let code = unsafe { arg.u1.v.type_ };
    if (mask & engine::MAY_BE_ANY) == engine::MAY_BE_ANY && ty.ptr.is_null() {
        return true;
    }
    // The engine's own first check (`ZEND_TYPE_CONTAINS_CODE`): `object` takes any object; a
    // class type is the slow path's.
    if mask & (1 << code) != 0 {
        return true;
    }
    // SAFETY: a live type and zval; the engine coerces scalars in place.
    unsafe { zend_check_user_type_slow(ty, std::ptr::from_mut(arg).cast(), std::ptr::null(), false) }
}

/// A method's checked arguments (at most four are read).
pub struct Args {
    values: [*mut Zval; 4],
    count: usize,
}

impl Args {
    /// Argument `i` (from 0), if passed.
    #[inline]
    pub fn get(&self, i: usize) -> Option<&'static mut Zval> {
        // SAFETY: arguments live as long as the call.
        let value = if i < self.count { self.values[i] } else { std::ptr::null_mut() };
        unsafe { value.as_mut() }
    }

    /// Argument `i` as a string, or `default` when it was not passed.
    pub fn str_or(&self, i: usize, default: &[u8]) -> Zval {
        self.get(i).map_or_else(|| engine::string(default), |v| v.shallow_clone())
    }

    /// Argument `i`, or `null` when it was not passed.
    pub fn value(&self, i: usize) -> Zval {
        self.get(i).map_or_else(Zval::null, |v| v.shallow_clone())
    }

    pub fn bool_or(&self, i: usize, default: bool) -> bool {
        self.get(i).and_then(|v| v.bool()).unwrap_or(default)
    }

    pub fn array_or_empty(&self, i: usize) -> Zval {
        self.get(i).map_or_else(engine::empty_array, |v| v.shallow_clone())
    }
}

/// A PHP list of `values` (`[$a, $b]`).
pub fn list(values: Vec<Zval>) -> Zval {
    let mut array = ZendHashTable::with_capacity(u32::try_from(values.len()).unwrap_or(u32::MAX));
    for value in values {
        let _ = array.push(value);
    }
    let mut zval = Zval::new();
    zval.set_hashtable(array);
    zval
}

/// PHP's `gettype()` name of `value`.
pub fn gettype(value: &Zval) -> &'static str {
    let value = value.dereference();
    // SAFETY: a live zval.
    match unsafe { value.u1.v.type_ } {
        1 => "NULL",
        2 | 3 => "boolean",
        4 => "integer",
        5 => "double",
        6 => "string",
        7 => "array",
        8 => "object",
        9 => "resource",
        _ => "unknown type",
    }
}

/// `$object->name` as `isset()`/`empty()` read it from `scope`: `null` (no error) for an
/// uninitialized or unset property.
pub fn read_quietly(scope: &ClassEntry, object: &mut ZendObject, name: &str) -> Zval {
    let mut rv = Zval::new();
    // SAFETY: a live object; the result points at the slot or at `rv`, copied before either goes.
    let value = unsafe {
        let ptr = ext_php_rs::ffi::zend_read_property(
            std::ptr::from_ref(scope).cast_mut(),
            std::ptr::from_mut(object),
            name.as_ptr().cast(),
            name.len(),
            true,
            std::ptr::from_mut(&mut rv).cast(),
        );
        ptr.cast::<Zval>().as_ref().map(|v| v.dereference().shallow_clone())
    };
    value.unwrap_or_else(Zval::null)
}

/// The case `name` of the enum `ce`.
pub fn enum_case(ce: &ClassEntry, name: &std::ffi::CStr) -> Option<Zval> {
    // SAFETY: a live enum class; the case object lives as long as its constants table.
    let object = unsafe { zend_enum_get_case_cstr(std::ptr::from_ref(ce).cast_mut(), name.as_ptr()) };
    if object.is_null() || has_exception() {
        return None;
    }
    let mut zval = Zval::new();
    zval.set_object(unsafe { &mut *object.cast::<ZendObject>() });
    Some(zval)
}
