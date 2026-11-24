//! The engine calls the hand-built validator classes need and ext-php-rs does not wrap:
//! typed property declarations, property reads and writes in a class's scope, PHP's own
//! weak-mode argument coercions, and the argument errors of a userland function, so a
//! native method takes and rejects arguments exactly as the PHP method it replaces.

use std::ffi::{CStr, CString, c_char, c_void};
use std::sync::OnceLock;
use std::sync::atomic::{AtomicU32, Ordering};

use ext_php_rs::args::ArgInfo;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::exception::throw;
use ext_php_rs::ffi::{
    HashTable, zend_class_constant, zend_class_entry, zend_function, zend_hash_next_index_insert, zend_hash_str_del,
    zend_hash_str_find, zend_hash_str_find_ptr_lc, zend_is_identical, zend_object, zend_property_info,
    zend_read_property, zend_string, zend_type, zval,
};
use ext_php_rs::flags::PropertyFlags;
use ext_php_rs::types::{ZendHashTable, ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData, ce};
use utopia_validators::{Input, Number};

/// `MAY_BE_BOOL`: `false` and `true`.
pub const MAY_BE_BOOL: u32 = (1 << 2) | (1 << 3);
/// `MAY_BE_LONG`.
pub const MAY_BE_LONG: u32 = 1 << 4;
/// `MAY_BE_DOUBLE`.
pub const MAY_BE_DOUBLE: u32 = 1 << 5;
/// `MAY_BE_STRING`.
pub const MAY_BE_STRING: u32 = 1 << 6;
/// `MAY_BE_ARRAY`.
pub const MAY_BE_ARRAY: u32 = 1 << 7;
/// `MAY_BE_ANY`: every type bit; clearing them leaves an argument untyped.
pub const MAY_BE_ANY: u32 = 0x3fe;
/// `MAY_BE_NULL`.
pub const MAY_BE_NULL: u32 = 1 << 1;
/// `MAY_BE_FALSE`.
pub const MAY_BE_FALSE: u32 = 1 << 2;
/// `MAY_BE_STATIC`: a `static` return type.
pub const MAY_BE_STATIC: u32 = 1 << 15;
/// `_ZEND_TYPE_NAME_BIT`: a type naming a class (its name in the type's pointer).
const TYPE_NAME_BIT: u32 = 1 << 24;

unsafe extern "C" {
    fn zend_declare_typed_property(
        ce: *mut zend_class_entry,
        name: *mut zend_string,
        property: *mut zval,
        access_type: i32,
        doc_comment: *mut zend_string,
        ty: zend_type,
    ) -> *mut zend_property_info;
    fn zend_update_property(
        scope: *const zend_class_entry,
        object: *mut zend_object,
        name: *const c_char,
        name_length: usize,
        value: *mut zval,
    );
    fn zend_parse_arg_bool_slow(arg: *const zval, dest: *mut bool, arg_num: u32) -> bool;
    fn zend_parse_arg_long_slow(arg: *const zval, dest: *mut i64, arg_num: u32) -> bool;
    fn zend_parse_arg_number_slow(arg: *mut zval, dest: *mut *mut zval, arg_num: u32) -> bool;
    fn zend_parse_arg_str_slow(arg: *mut zval, dest: *mut *mut zend_string, arg_num: u32) -> bool;
    fn zend_zval_value_name(arg: *const zval) -> *const c_char;
    fn zend_argument_type_error(arg_num: u32, format: *const c_char, ...);
    fn zend_compare(op1: *mut zval, op2: *mut zval) -> i32;
    fn zval_get_string_func(op: *mut zval) -> *mut zend_string;
    fn zend_declare_typed_class_constant(
        ce: *mut zend_class_entry,
        name: *mut zend_string,
        value: *mut zval,
        flags: i32,
        doc_comment: *mut zend_string,
        ty: zend_type,
    ) -> *mut zend_class_constant;
    fn _zend_hash_init(ht: *mut HashTable, size: u32, destructor: *mut c_void, persistent: bool);
    fn zend_hash_real_init_packed(ht: *mut HashTable);
    fn zend_hash_str_add(ht: *mut HashTable, key: *const c_char, len: usize, value: *mut zval) -> *mut zval;
    fn zend_array_dup(source: *mut HashTable) -> *mut HashTable;
    fn zend_fetch_dimension_const(result: *mut zval, container: *mut zval, dim: *mut zval, ty: i32);
    fn add_function(result: *mut zval, op1: *mut zval, op2: *mut zval) -> i32;
    fn zend_error(ty: i32, format: *const c_char, ...);
    fn zend_undefined_method(ce: *const zend_class_entry, method: *const zend_string);
    fn zend_verify_scalar_type_hint(type_mask: u32, arg: *mut zval, strict: bool, is_internal_arg: bool) -> bool;
    fn zend_verify_return_error(function: *const zend_function, value: *const zval);
    fn increment_function(op: *mut zval) -> i32;
    fn zend_release_fcall_info_cache(fcc: *mut c_void);
}

/// The engine's executor globals (`EG`).
#[inline]
fn executor_globals() -> *mut ext_php_rs::ffi::zend_executor_globals {
    // SAFETY: the engine's own globals, alive for the process.
    unsafe { ext_php_rs::ffi::ext_php_rs_executor_globals() }
}

/// Whether an exception is pending (`EG(exception)`). ext-php-rs's
/// `ExecutorGlobals::has_exception()` takes a read lock on the globals for it, two atomic
/// operations and an `Arc` count on every call, which costs more than most methods here.
#[inline]
pub fn has_exception() -> bool {
    // SAFETY: reading one pointer of the engine's globals, on the thread that runs PHP.
    unsafe { !(*executor_globals()).exception.is_null() }
}

/// `ZEND_ACC_PROMOTED`: a property declared by a constructor parameter.
const ACC_PROMOTED: i32 = 1 << 8;
/// `ZEND_ACC_READONLY`.
const ACC_READONLY: i32 = 1 << 7;
/// `_ZEND_IS_PROMOTED_BIT`: the constructor parameter that declares a property.
pub const IS_PROMOTED: u32 = 1 << 28;

/// The argument info the engine keeps for `class`'s method `name`: the return type first,
/// then each parameter. The engine copies a typed method's info when it registers it, so
/// types are patched here, after registration (and after the inheritance checks, which
/// see the types the method was built with).
pub fn arg_info(class: &ClassEntry, name: &str) -> Option<&'static mut [ArgInfo]> {
    // SAFETY: the function table and the engine's argument info live for the process; an
    // internal function's `arg_info` points one past its return type, and holds
    // `num_args` parameters.
    unsafe {
        let function = zend_hash_str_find_ptr_lc(&raw const class.function_table, name.as_ptr().cast(), name.len())
            .cast::<zend_function>();
        let common = &function.as_ref()?.common;
        let first = common.arg_info.cast::<ArgInfo>().as_mut()?;
        let count = usize::try_from(common.num_args).ok()? + 1;
        Some(std::slice::from_raw_parts_mut(std::ptr::from_mut(first).sub(1), count))
    }
}

/// Sets the type of `info` to `mask` (0: untyped), keeping its other bits.
pub fn set_type(info: &mut ArgInfo, mask: u32) {
    info.type_.type_mask = (info.type_.type_mask & !MAY_BE_ANY) | mask;
}

/// A declared property of a hand-built class: its name and the offset of its slot in an
/// object of the class, known once [`declare`] has run.
pub struct Property {
    name: &'static str,
    offset: AtomicU32,
}

impl Property {
    /// The property `name`, not declared yet.
    pub const fn new(name: &'static str) -> Self {
        Self { name, offset: AtomicU32::new(0) }
    }

    /// The property's name.
    pub fn name(&self) -> &'static str {
        self.name
    }

    /// Records the offset of the property's slot, once declared.
    pub fn set_offset(&self, offset: u32) {
        self.offset.store(offset, Ordering::Release);
    }
}

/// Declares `property` as a protected typed property of `class`, with `default`; `None`
/// declares it as a promoted constructor parameter does (uninitialized until the constructor
/// runs, and `isPromoted()` in reflection).
pub fn declare(class: &mut ClassEntry, property: &Property, mask: u32, default: Option<Zval>) {
    let promoted = default.is_none();
    declare_as(class, property, mask, default, promoted);
}

/// Declares `property` as a protected typed property of `class` without a default, as a
/// property declared in the class body is (`protected array $patterns;`): uninitialized until
/// it is assigned, and not promoted.
pub fn declare_uninitialized(class: &mut ClassEntry, property: &Property, mask: u32) {
    declare_as(class, property, mask, None, false);
}

fn declare_as(class: &mut ClassEntry, property: &Property, mask: u32, default: Option<Zval>, promoted: bool) {
    let flags = PropertyFlags::Protected.bits().try_into().expect("property flags fit an int");
    let flags = if promoted { flags | ACC_PROMOTED } else { flags };
    declare_typed(class, property, zend_type { ptr: std::ptr::null_mut(), type_mask: mask }, flags, default);
}

/// Declares `property` as a public untyped property of `class` with the default `null`
/// (`public $name;` and `public $name = null;` alike).
pub fn declare_public(class: &mut ClassEntry, property: &Property) {
    let flags = PropertyFlags::Public.bits().try_into().expect("property flags fit an int");
    declare_typed(class, property, zend_type { ptr: std::ptr::null_mut(), type_mask: 0 }, flags, None);
}

/// Declares the public untyped static property `name` of `class` with `default` (a value that
/// is not reference counted).
pub fn declare_public_static(class: &mut ClassEntry, name: &'static str, default: Zval) {
    let flags: i32 =
        (PropertyFlags::Public | PropertyFlags::Static).bits().try_into().expect("property flags fit an int");
    let property = Property::new(name);
    declare_typed(class, &property, zend_type { ptr: std::ptr::null_mut(), type_mask: 0 }, flags, Some(default));
}

/// What a property declared with [`declare_property`] is: its visibility and modifiers.
#[derive(Clone, Copy)]
pub struct Declared {
    /// `private`, rather than `protected`.
    pub private: bool,
    /// `readonly`.
    pub readonly: bool,
    /// Declared by a constructor parameter.
    pub promoted: bool,
}

/// A property's type: a mask of scalar types (0: untyped), or the class `name` (`null` too
/// when `nullable`).
#[derive(Clone, Copy)]
pub enum PropertyType {
    Mask(u32),
    Class { name: &'static str, nullable: bool },
}

/// Declares `property` of `class` as `declared` says, of type `ty`, with `default` (`None`:
/// uninitialized, as a typed property without a default, or a promoted one, starts).
pub fn declare_property(
    class: &mut ClassEntry,
    property: &Property,
    ty: PropertyType,
    declared: Declared,
    default: Option<Zval>,
) {
    let visibility = if declared.private { PropertyFlags::Private } else { PropertyFlags::Protected };
    let mut flags: i32 = visibility.bits().try_into().expect("property flags fit an int");
    if declared.readonly {
        flags |= ACC_READONLY;
    }
    if declared.promoted {
        flags |= ACC_PROMOTED;
    }
    let ty = match ty {
        PropertyType::Mask(mask) => zend_type { ptr: std::ptr::null_mut(), type_mask: mask },
        // SAFETY: called in MINIT: the name is interned and persistent, as a type must hold it.
        PropertyType::Class { name, nullable } => unsafe {
            let init = ext_php_rs::ffi::zend_string_init_interned.expect("zend_string_init_interned");
            let name = init(name.as_ptr().cast(), name.len(), true);
            zend_type { ptr: name.cast(), type_mask: TYPE_NAME_BIT | if nullable { MAY_BE_NULL } else { 0 } }
        },
    };
    declare_typed(class, property, ty, flags, default);
}

fn declare_typed(class: &mut ClassEntry, property: &Property, ty: zend_type, flags: i32, default: Option<Zval>) {
    let mut value = default.unwrap_or_else(|| {
        let mut undef = Zval::new();
        undef.u1.type_info = 0;
        undef
    });
    // An untyped property without a default holds `null`, as PHP declares it.
    if ty.ptr.is_null() && ty.type_mask == 0 && unsafe { value.u1.type_info } == 0 {
        value.set_null();
    }
    // SAFETY: called while the class is registered (MINIT): the name is interned and
    // persistent, the engine takes the default with `ZVAL_COPY_VALUE` (scalars, `null` or the
    // immutable empty array here), and the type's name, if any, is interned.
    let info = unsafe {
        let init = ext_php_rs::ffi::zend_string_init_interned.expect("zend_string_init_interned");
        let name = init(property.name.as_ptr().cast(), property.name.len(), true);
        zend_declare_typed_property(class, name, &raw mut value, flags, std::ptr::null_mut(), ty)
    };
    std::mem::forget(value);
    // SAFETY: the engine returns the property's info, alive for the process.
    if let Some(info) = unsafe { info.as_ref() } {
        property.offset.store(info.offset, Ordering::Release);
    }
}

/// The immutable empty array (`[]`), as a property default or constant holds it.
pub fn empty_array() -> Zval {
    let mut zval = Zval::new();
    // SAFETY: the engine's empty array is immutable and lives for the process; a zval of it
    // is not reference counted.
    zval.value.arr = (&raw const ext_php_rs::ffi::zend_empty_array).cast_mut();
    zval.u1.type_info = IS_ARRAY;
    zval
}

/// `$this->property` read from `scope`, as a method of `scope` reads it: an uninitialized
/// typed property throws its `Error`, and `None` comes back with the exception pending.
///
/// On an object of exactly `scope` (not a subclass, which may redeclare the property with
/// hooks) that is not lazy, an initialized property is read straight from its slot; the
/// rest goes through the engine.
///
/// Always inlined, with the engine's way out of line: returned through memory, the
/// `Option<Zval>` cost a store-forwarding stall on every read.
#[inline(always)]
pub fn get(scope: &ClassEntry, this: &mut ZendObject, property: &Property) -> Option<Zval> {
    if let Some(slot) = own_slot(scope, this, property) {
        let value = slot.dereference();
        // `IS_UNDEF`: uninitialized, or unset, which the engine reports (or `__get` answers).
        if unsafe { value.u1.type_info } != 0 {
            return Some(value.shallow_clone());
        }
    }
    get_through_engine(scope, this, property)
}

#[cold]
#[inline(never)]
fn get_through_engine(scope: &ClassEntry, this: &mut ZendObject, property: &Property) -> Option<Zval> {
    read(scope, this, property.name)
}

/// The slot of `property` in `this` when it may be read and written directly: an object of
/// exactly `scope` (not a subclass, which may redeclare the property with hooks) that is not
/// lazy, once the property is declared.
#[inline(always)]
fn own_slot<'a>(scope: &ClassEntry, this: &'a mut ZendObject, property: &Property) -> Option<&'a mut Zval> {
    let offset = property.offset.load(Ordering::Acquire);
    if offset != 0 && std::ptr::eq(this.ce, scope) && !this.is_lazy() {
        // SAFETY: a declared property's slot lies `offset` bytes into an object of the class
        // that declared it, and lives as long as the object.
        return Some(unsafe { &mut *std::ptr::from_mut(this).cast::<u8>().add(offset as usize).cast::<Zval>() });
    }
    None
}

/// The slot of the private `property` in `this` (of its class or a subclass, which cannot hook it), if not lazy.
#[inline(always)]
pub fn private_slot<'a>(this: &'a mut ZendObject, property: &Property) -> Option<&'a mut Zval> {
    let offset = property.offset.load(Ordering::Acquire);
    if offset != 0 && !this.is_lazy() {
        // SAFETY: the slot lies `offset` bytes into any object of the declaring class or a subclass.
        return Some(unsafe { &mut *std::ptr::from_mut(this).cast::<u8>().add(offset as usize).cast::<Zval>() });
    }
    None
}

/// The slot of `property` in `this`, to read or write in place, when [`own_slot`] allows it (an
/// object of exactly `scope`, not lazy): no copy of the value goes through memory, which a
/// getter that only looks at it would then reload.
#[inline(always)]
pub fn property_slot<'a>(scope: &ClassEntry, this: &'a mut ZendObject, property: &Property) -> Option<&'a mut Zval> {
    own_slot(scope, this, property)
}

/// `IS_PROP_UNINIT`: a typed property's slot that was never initialized (not one `unset()`).
const PROP_UNINIT: u32 = 1;

/// `$this->property = $value` for a property never initialized, straight into its slot when
/// [`own_slot`] allows it (what the engine's write does for such a slot, readonly included,
/// for a value already of the property's type); through the engine otherwise.
#[inline]
pub fn initialize(scope: &ClassEntry, this: &mut ZendObject, property: &Property, value: Zval) {
    if let Some(slot) = own_slot(scope, this, property)
        // SAFETY: reading the type and the property flag of a live slot.
        && unsafe { slot.u1.type_info } == 0
        && unsafe { slot.u2.extra } & PROP_UNINIT != 0
    {
        // An undefined slot owns nothing.
        std::mem::forget(std::mem::replace(slot, value));
        return;
    }
    write(scope, this, property.name, value);
}

/// `$this->property[] = ` ...: the array `property` holds, separated for writing as PHP
/// separates it, when [`own_slot`] allows reaching it and it is an array (not a reference)
/// with room for one more element; `None` when the engine's way must be taken.
pub fn array_slot<'a>(
    scope: &ClassEntry,
    this: &'a mut ZendObject,
    property: &Property,
) -> Option<&'a mut ZendHashTable> {
    let slot = own_slot(scope, this, property)?;
    if !slot.is_array() {
        return None;
    }
    // SAFETY: an array slot: its table is live; a shared or immutable one is replaced by a
    // copy the slot owns (`SEPARATE_ARRAY`), then appended to in place.
    unsafe {
        let mut table = slot.value.arr;
        if (*table).nNextFreeElement == i64::MAX {
            return None;
        }
        if (*table.cast::<ZendHashTable>()).is_immutable() {
            table = zend_array_dup(table);
        } else if (*table).gc.refcount > 1 {
            (*table).gc.refcount -= 1;
            table = zend_array_dup(table);
        }
        slot.value.arr = table;
        slot.u1.type_info = IS_ARRAY_EX;
        table.cast::<ZendHashTable>().as_mut()
    }
}

/// `$this->property = $value` from `scope`, for a value already of the property's type.
///
/// On an object of exactly `scope` that is not lazy, the value goes straight into the slot
/// (the old one released); a subclass's object (which may redeclare the property with hooks)
/// and a slot holding a reference go through the engine, with its type checks.
#[inline]
pub fn set(scope: &ClassEntry, this: &mut ZendObject, property: &Property, value: Zval) {
    if let Some(slot) = own_slot(scope, this, property)
        && !slot.is_reference()
    {
        // The slot owns its value, which the replaced one releases.
        drop(std::mem::replace(slot, value));
        return;
    }
    write(scope, this, property.name, value);
}

/// `$this->name` read from `scope`, as a method of `scope` reads it: an uninitialized typed
/// property throws its `Error`, and `None` comes back with the exception pending.
pub fn read(scope: &ClassEntry, this: &mut ZendObject, name: &str) -> Option<Zval> {
    let mut rv = Zval::new();
    // SAFETY: `this` is the method's object and `rv` outlives the call; the result points at
    // the property slot or at `rv`, and is copied before either goes away.
    let value = unsafe {
        let ptr = zend_read_property(
            std::ptr::from_ref(scope).cast_mut(),
            std::ptr::from_mut(this),
            name.as_ptr().cast(),
            name.len(),
            false,
            &raw mut rv,
        );
        ptr.as_ref().map(Zval::shallow_clone)
    };
    if has_exception() { None } else { value }
}

/// `$this->name = $value` from `scope`, with the property's type checks.
pub fn write(scope: &ClassEntry, this: &mut ZendObject, name: &str, mut value: Zval) {
    // SAFETY: the engine copies `value` (adding a reference) into the property.
    unsafe { zend_update_property(scope, std::ptr::from_mut(this), name.as_ptr().cast(), name.len(), &raw mut value) };
}

/// The value a validator checks, as the `utopia_validators` functions take it.
#[inline(always)]
pub fn input(value: &Zval) -> Input<'_> {
    let value = value.dereference();
    if let Some(b) = value.bool() {
        Input::Bool(b)
    } else if let Some(i) = value.long() {
        Input::Int(i)
    } else if let Some(f) = value.double() {
        Input::Float(f)
    } else if let Some(s) = value.zend_str() {
        Input::Str(s.as_bytes())
    } else if value.is_null() {
        Input::Null
    } else {
        Input::Other
    }
}

/// An int or a float property as a number (0 for anything else, which a typed property
/// cannot hold).
#[inline]
pub fn number(value: &Zval) -> Number {
    value.long().map(Number::Int).or_else(|| value.double().map(Number::Float)).unwrap_or(Number::Int(0))
}

/// PHP truthiness, for a method's result used as a condition.
#[inline(always)]
pub fn truthy(value: &Zval) -> bool {
    match input(value) {
        Input::Null => false,
        Input::Bool(b) => b,
        Input::Int(i) => i != 0,
        Input::Float(f) => f != 0.0,
        Input::Str(s) => !(s.is_empty() || s == b"0"),
        Input::Other => value.array().is_none_or(|array| !array.is_empty()),
    }
}

/// A PHP string of `bytes`.
#[inline]
pub fn string(bytes: &[u8]) -> Zval {
    let mut zval = Zval::new();
    zval.set_zend_string(ZendStr::new(bytes, false));
    zval
}

/// The method's argument `i` (from 0), if it was passed.
#[inline]
pub fn arg(ex: &mut ExecuteData, i: usize) -> Option<&'static mut Zval> {
    // SAFETY: the engine sets `num_args` for the call, and the argument slot lives as long
    // as the call frame, which outlives the method's use of it.
    let count = unsafe { ex.This.u2.num_args } as usize;
    if i < count { unsafe { ex.zend_call_arg(i) } } else { None }
}

/// How many arguments the method was called with.
#[inline]
pub fn args_count(ex: &ExecuteData) -> usize {
    // SAFETY: the engine sets `num_args` for the call.
    unsafe { ex.This.u2.num_args as usize }
}

/// The file and line of the userland code that made the call, which a userland function's
/// argument errors name; `None` when an internal function called (reflection,
/// `call_user_func()`), as the engine does.
fn caller(ex: &ExecuteData) -> Option<(String, u32)> {
    // SAFETY: the frames, functions and oplines are the engine's, alive during the call; a
    // user function's (and eval'd code's) `op_array` is the active member of the union.
    unsafe {
        let prev = ex.prev_execute_data.as_ref()?;
        let func = prev.func.as_ref()?;
        if func.type_ & 1 != 0 {
            return None;
        }
        let filename = (*func.op_array.filename.cast::<ZendStr>()).as_bytes();
        let line = prev.opline.as_ref()?.lineno;
        Some((String::from_utf8_lossy(filename).into_owned(), line))
    }
}

/// Throws the `TypeError` a userland function throws for argument `num` not of type
/// `expected`: "X::f(): Argument #1 ($a) must be of type bool, array given, called in F on
/// line N".
pub fn type_error(ex: &ExecuteData, num: u32, expected: &str, arg: &Zval) {
    // SAFETY: `arg` is a live zval; the engine returns a static or interned name.
    let given = unsafe { CStr::from_ptr(zend_zval_value_name(arg)) }.to_string_lossy().into_owned();
    let mut message = format!("must be of type {expected}, {given} given");
    if let Some((file, line)) = caller(ex) {
        message.push_str(&format!(", called in {file} on line {line}"));
    }
    let message = CString::new(message.replace('\0', "")).unwrap_or_default();
    // SAFETY: the engine names the executing function and its argument, and formats `%s`.
    unsafe { zend_argument_type_error(num, c"%s".as_ptr(), message.as_ptr()) };
}

/// Throws the `ArgumentCountError` a userland function throws when called with `passed`
/// arguments of `required` (out of `total`).
pub fn too_few(ex: &ExecuteData, function: &str, passed: usize, required: usize, total: usize) {
    let bound = if required == total { "exactly" } else { "at least" };
    let message = match caller(ex) {
        Some((file, line)) => format!(
            "Too few arguments to function {function}(), {passed} passed in {file} on line {line} and {bound} {required} expected"
        ),
        None => format!("Too few arguments to function {function}(), {passed} passed and {bound} {required} expected"),
    };
    let _ = throw(ce::argument_count_error(), &message);
}

/// A `bool` argument, coerced as a userland `bool` parameter is: `null` is a `TypeError`,
/// anything else goes through the engine's coercion (strict or weak, as the caller declares).
#[inline]
pub fn bool_arg(ex: &ExecuteData, num: u32, arg: &mut Zval) -> Option<bool> {
    if let Some(b) = arg.bool() {
        return Some(b);
    }
    let mut dest = false;
    // SAFETY: a live zval and a valid destination.
    if !arg.is_null() && unsafe { zend_parse_arg_bool_slow(arg, &raw mut dest, num) } {
        return (!has_exception()).then_some(dest);
    }
    if !has_exception() {
        type_error(ex, num, "bool", arg);
    }
    None
}

/// An `int` argument, coerced as a userland `int` parameter is.
#[inline]
pub fn long_arg(ex: &ExecuteData, num: u32, arg: &mut Zval) -> Option<i64> {
    long_arg_named(ex, num, arg, "int")
}

/// An `int` argument of a parameter whose type PHP names `expected` in its error (`?int` for
/// a nullable one, which the caller lets `null` through), coerced as a userland one is.
pub fn long_arg_named(ex: &ExecuteData, num: u32, arg: &mut Zval, expected: &str) -> Option<i64> {
    if let Some(i) = arg.long() {
        return Some(i);
    }
    let mut dest = 0;
    // SAFETY: a live zval and a valid destination.
    if !arg.is_null() && unsafe { zend_parse_arg_long_slow(arg, &raw mut dest, num) } {
        return (!has_exception()).then_some(dest);
    }
    if !has_exception() {
        type_error(ex, num, expected, arg);
    }
    None
}

/// An `int|float` argument, coerced as a userland `int|float` parameter is.
#[inline]
pub fn number_arg(ex: &ExecuteData, num: u32, arg: &mut Zval) -> Option<Zval> {
    if arg.is_long() || arg.is_double() {
        return Some(arg.shallow_clone());
    }
    let mut dest: *mut zval = std::ptr::null_mut();
    // SAFETY: a live zval; on success the engine points `dest` at the coerced number.
    if !arg.is_null() && unsafe { zend_parse_arg_number_slow(arg, &raw mut dest, num) } {
        if has_exception() {
            return None;
        }
        return unsafe { dest.cast::<Zval>().as_ref() }.map(Zval::shallow_clone);
    }
    if !has_exception() {
        type_error(ex, num, "int|float", arg);
    }
    None
}

/// A `string` argument, coerced as a userland `string` parameter is (`Stringable` objects
/// included, in weak mode).
#[inline]
pub fn str_arg(ex: &ExecuteData, num: u32, arg: &mut Zval) -> Option<Vec<u8>> {
    if let Some(s) = arg.zend_str() {
        return Some(s.as_bytes().to_vec());
    }
    let mut dest: *mut zend_string = std::ptr::null_mut();
    // SAFETY: a live zval; on success the engine points `dest` at the string.
    if !arg.is_null() && unsafe { zend_parse_arg_str_slow(arg, &raw mut dest, num) } {
        if has_exception() {
            return None;
        }
        return unsafe { dest.cast::<ZendStr>().as_ref() }.map(|s| s.as_bytes().to_vec());
    }
    if !has_exception() {
        type_error(ex, num, "string", arg);
    }
    None
}

/// A `string` argument as a PHP string, shared by reference rather than copied: [`str_arg`]
/// for a value a method keeps (a property).
#[inline]
pub fn str_zval_arg(ex: &ExecuteData, num: u32, arg: &mut Zval) -> Option<Zval> {
    if arg.is_string() {
        return Some(arg.shallow_clone());
    }
    let mut dest: *mut zend_string = std::ptr::null_mut();
    // SAFETY: a live zval; on success the engine has converted it to the string in place.
    if !arg.is_null() && unsafe { zend_parse_arg_str_slow(arg, &raw mut dest, num) } {
        if has_exception() {
            return None;
        }
        return Some(arg.dereference().shallow_clone());
    }
    if !has_exception() {
        type_error(ex, num, "string", arg);
    }
    None
}

/// An `array` argument, as a userland `array` parameter takes it: arrays only, in either mode.
pub fn array_arg(ex: &ExecuteData, num: u32, arg: &Zval) -> Option<Zval> {
    if arg.is_array() {
        return Some(arg.shallow_clone());
    }
    type_error(ex, num, "array", arg);
    None
}

/// PHP `(string) $value`: the engine's conversion, with its warning for an array and its
/// `Error` for an object without `__toString()`; `None` with the exception pending.
pub fn to_string(value: &Zval) -> Option<Vec<u8>> {
    let value = value.dereference();
    // A string or an int converts without the engine (and without its warnings, which
    // neither raises).
    if let Some(s) = value.zend_str() {
        return Some(s.as_bytes().to_vec());
    }
    if let Some(i) = value.long() {
        return Some(i.to_string().into_bytes());
    }
    let mut copy = value.shallow_clone();
    // SAFETY: a live zval; the engine returns a string it adds a reference to, which the box
    // releases.
    let converted = unsafe { zval_get_string_func(&raw mut copy) };
    let bytes = unsafe { ZBox::<ZendStr>::from_raw(converted.cast()) }.as_bytes().to_vec();
    if has_exception() { None } else { Some(bytes) }
}

/// PHP `(string) $value` appended to `out`, as [`to_string`] converts it; a string is copied
/// straight from its bytes.
pub fn append_string(value: &Zval, out: &mut Vec<u8>) -> Option<()> {
    let value = value.dereference();
    if let Some(s) = value.zend_str() {
        out.extend_from_slice(s.as_bytes());
    } else if let Some(i) = value.long() {
        append_decimal(i, out);
    } else {
        out.extend_from_slice(&to_string(value)?);
    }
    Some(())
}

/// PHP `implode(', ', $list)`: each element converted as [`to_string`] converts it.
/// Appended to `joined`.
pub fn implode(list: &Zval, joined: &mut Vec<u8>) -> Option<()> {
    if let Some(array) = list.array() {
        for (i, value) in values(array).enumerate() {
            if i > 0 {
                joined.extend_from_slice(b", ");
            }
            append_string(value, joined)?;
        }
    }
    Some(())
}

/// `HASH_FLAG_PACKED`: the values sit in a plain zval array, without keys.
const HASH_FLAG_PACKED: u32 = 1 << 2;

/// The address of `ht`'s slot `i` (of `nNumUsed`): a packed array's zval, or a bucket's value.
///
/// # Safety
///
/// `i` is below `ht.nNumUsed`.
unsafe fn slot(ht: &ZendHashTable, i: usize) -> *mut Zval {
    // SAFETY: the caller keeps `i` within the used slots; the union member read is the one
    // the packed flag names.
    unsafe {
        if ht.u.flags & HASH_FLAG_PACKED != 0 {
            ht.__bindgen_anon_1.arPacked.add(i).cast::<Zval>()
        } else {
            (&raw mut (*ht.__bindgen_anon_1.arData.add(i)).val).cast::<Zval>()
        }
    }
}

/// `ht`'s values in order, read straight from its slots (packed or not), skipping the holes
/// deletions leave: what `foreach` visits.
pub fn values(ht: &ZendHashTable) -> impl Iterator<Item = &Zval> {
    (0..ht.nNumUsed as usize).filter_map(move |i| {
        // SAFETY: within the used slots, which live as long as the table.
        let value = unsafe { &*slot(ht, i) };
        // `IS_UNDEF`: a hole.
        (unsafe { value.u1.type_info } != 0).then_some(value)
    })
}

/// A copy of the array `list` (the engine's `zend_array_dup`, keys and order kept) with each
/// value passed through `map`, in order; `map` returning `None` (an exception pending) leaves
/// that value and the ones after it as they were. The flag says whether every value was mapped.
pub fn map_values(list: &Zval, mut map: impl FnMut(&Zval) -> Option<Option<Zval>>) -> (Zval, bool) {
    let Some(array) = list.array() else { return (list.shallow_clone(), true) };
    // SAFETY: the engine copies the table (a new one, owned by the box); its slots are then
    // replaced in place, each old value released as it is overwritten.
    let copy = unsafe { zend_array_dup(std::ptr::from_ref(array).cast_mut()) };
    let mut done = true;
    let used = unsafe { (*copy).nNumUsed } as usize;
    for i in 0..used {
        let value = unsafe { &mut *slot(&*copy, i) };
        if unsafe { value.u1.type_info } == 0 {
            continue;
        }
        match map(value) {
            Some(Some(new)) => drop(std::mem::replace(value, new)),
            Some(None) => {}
            None => {
                done = false;
                break;
            }
        }
    }
    let mut zval = Zval::new();
    zval.set_hashtable(unsafe { ZBox::from_raw(copy) });
    (zval, done)
}

/// PHP `$a == $b`, as `in_array()` compares loosely: `None` with an exception pending (a
/// `__toString()` that throws).
pub fn loose_equal(a: &Zval, b: &Zval) -> Option<bool> {
    // Two strings are equal by their bytes unless both are numeric, which needs a digit in
    // each: only then does PHP compare them as numbers, which the engine does below.
    if let (Some(x), Some(y)) = (a.dereference().zend_str(), b.dereference().zend_str()) {
        let (x, y) = (x.as_bytes(), y.as_bytes());
        if x == y {
            return Some(true);
        }
        if !(x.iter().any(u8::is_ascii_digit) && y.iter().any(u8::is_ascii_digit)) {
            return Some(false);
        }
    }
    let (mut a, mut b) = (a.shallow_clone(), b.shallow_clone());
    // SAFETY: two live zvals.
    let equal = unsafe { zend_compare(&raw mut a, &raw mut b) } == 0;
    if has_exception() { None } else { Some(equal) }
}

/// PHP `$needle == $item` for a string `$needle`: by bytes against a string item, unless
/// both hold a digit (only then may PHP compare them as numbers, which the engine does), and
/// through the engine against anything else.
pub fn loose_equal_bytes(needle: &[u8], item: &Zval) -> Option<bool> {
    if let Some(s) = item.dereference().zend_str() {
        let s = s.as_bytes();
        if s == needle {
            return Some(true);
        }
        if !(s.iter().any(u8::is_ascii_digit) && needle.iter().any(u8::is_ascii_digit)) {
            return Some(false);
        }
    }
    loose_equal(&string(needle), item)
}

/// PHP `$a === $b`.
pub fn identical(a: &Zval, b: &Zval) -> bool {
    if let (Some(x), Some(y)) = (a.dereference().zend_str(), b.dereference().zend_str()) {
        return x.as_bytes() == y.as_bytes();
    }
    // SAFETY: two live zvals.
    unsafe { zend_is_identical(a, b) }
}

/// `IS_ARRAY`, `IS_STRING` and the `IS_PTR` type of a hash table entry holding a pointer.
const IS_ARRAY: u32 = 7;
/// `IS_ARRAY_EX`: an array that counts references and may be collected.
pub const IS_ARRAY_EX: u32 = IS_ARRAY | (1 << 8) | (1 << 9);

const IS_STRING: u32 = 6;
/// `IS_STRING_EX`: a string that counts references.
const IS_STRING_EX: u32 = IS_STRING | (1 << 8);
const IS_PTR: u32 = 13;
/// `GC_NOT_COLLECTABLE | GC_IMMUTABLE | GC_PERSISTENT`, an immutable array's flags.
const IMMUTABLE_ARRAY: u32 = (1 << 4) | (1 << 6) | (1 << 7);

/// A persistent interned string zval, as a constant of an internal class holds it.
pub fn interned(bytes: &[u8]) -> Zval {
    let mut zval = Zval::new();
    // SAFETY: called in MINIT; the engine keeps interned persistent strings for the process.
    unsafe {
        let init = ext_php_rs::ffi::zend_string_init_interned.expect("zend_string_init_interned");
        zval.value.str_ = init(bytes.as_ptr().cast(), bytes.len(), true);
    }
    zval.u1.type_info = IS_STRING;
    zval
}

/// A string a method returns as PHP returns a string literal: interned in MINIT
/// ([`Literal::intern`]), so returning it neither allocates nor counts references. Building
/// a new PHP string for each call costs an allocation and a copy, and its release, which is
/// more than the rest of a constant getter.
pub struct Literal {
    text: &'static str,
    string: std::sync::atomic::AtomicPtr<zend_string>,
}

impl Literal {
    pub const fn new(text: &'static str) -> Self {
        Self { text, string: std::sync::atomic::AtomicPtr::new(std::ptr::null_mut()) }
    }

    /// Interns the string; called in MINIT, before any call.
    pub fn intern(&self) {
        if self.string.load(Ordering::Acquire).is_null() {
            self.string.store(interned(self.text.as_bytes()).value_str(), Ordering::Release);
        }
    }

    /// The string as a PHP value: the interned one, or a new one before [`Literal::intern`].
    #[inline]
    pub fn zval(&self) -> Zval {
        let mut zval = Zval::new();
        self.set(&mut zval);
        zval
    }

    /// Writes the string into `zval` (which holds nothing that needs releasing, as a
    /// method's return value before the method sets it).
    #[inline(always)]
    pub fn set(&self, zval: &mut Zval) {
        let string = self.string.load(Ordering::Acquire);
        if string.is_null() {
            zval.set_zend_string(ZendStr::new(self.text.as_bytes(), false));
            return;
        }
        zval.value.str_ = string;
        // An interned string: `IS_STRING` without the refcounted flag.
        zval.u1.type_info = IS_STRING;
    }
}

/// Interns each of `literals` (MINIT).
pub fn intern(literals: &[&Literal]) {
    literals.iter().for_each(|literal| literal.intern());
}

/// A PHP string built by `build` in one allocation, written into `retval`: `build` appends to
/// the first of two buffers (the second holds a part to build first, such as an `implode()`),
/// which is then copied once into the string. What PHP's concatenation does with a rope;
/// building a `Vec` (growing as it goes) and copying it costs two or more, each through the C
/// allocator. The buffers are kept for the thread and reused, a build within a build (a
/// wrapped validator's `getDescription()`) taking the next pair. Nothing is written when
/// `build` gives up with an exception pending.
#[inline]
pub fn built(retval: &mut Zval, build: impl FnOnce(&mut Vec<u8>, &mut Vec<u8>) -> Option<()>) {
    thread_local! {
        static BUFFERS: std::cell::RefCell<Vec<(Vec<u8>, Vec<u8>)>> = const { std::cell::RefCell::new(Vec::new()) };
    }
    BUFFERS.with(|buffers| {
        let (mut out, mut spare) = buffers.borrow_mut().pop().unwrap_or_default();
        out.clear();
        spare.clear();
        if build(&mut out, &mut spare).is_some() {
            retval.set_zend_string(ZendStr::new(&out[..], false));
        }
        // Buffers grown by a long string are not kept.
        if out.capacity() <= 4096 && spare.capacity() <= 4096 {
            buffers.borrow_mut().push((out, spare));
        }
    });
}

/// `GC_STRING`: a string's type info, which never holds cycles.
const GC_STRING: u32 = IS_STRING | (1 << 4);

/// `$prefix . implode(', ', $list) . $suffix` into `retval` in one allocation of the exact
/// size, as PHP's concatenation makes it, when every item of the array `list` is a string
/// (nothing to convert, so nothing PHP could observe); `false`, with nothing written,
/// otherwise. Inlined, so the prefix and the suffix copy as constants.
#[inline(always)]
pub fn implode_between(retval: &mut Zval, prefix: &[u8], list: &Zval, suffix: &[u8]) -> bool {
    let Some(array) = list.array() else { return false };
    let mut length = prefix.len() + suffix.len();
    for (i, value) in values(array).enumerate() {
        let Some(item) = value.dereference().zend_str() else { return false };
        length += item.len() + if i > 0 { 2 } else { 0 };
    }
    // `_ZSTR_STRUCT_SIZE`: the header, the bytes and the terminating NUL.
    let header = std::mem::offset_of!(ext_php_rs::ffi::zend_string, val);
    let Ok(layout) = std::alloc::Layout::from_size_align(header + length + 1, 8) else { return false };
    let string = ext_php_rs::alloc::emalloc(layout).cast::<ext_php_rs::ffi::zend_string>();
    if string.is_null() {
        return false;
    }
    // SAFETY: a new allocation of the string's size, initialized as `zend_string_alloc()`
    // initializes one, then filled with exactly `length` bytes and the NUL.
    unsafe {
        (*string).gc.refcount = 1;
        (*string).gc.u.type_info = GC_STRING;
        (*string).h = 0;
        (*string).len = length;
        let mut at = std::ptr::addr_of_mut!((*string).val).cast::<u8>();
        let mut put = |bytes: &[u8]| {
            if !bytes.is_empty() {
                std::ptr::copy_nonoverlapping(bytes.as_ptr(), at, bytes.len());
                at = at.add(bytes.len());
            }
        };
        put(prefix);
        for (i, value) in values(array).enumerate() {
            if i > 0 {
                put(b", ");
            }
            put(value.dereference().zend_str().map(ZendStr::as_bytes).unwrap_or_default());
        }
        put(suffix);
        *at = 0;
        retval.value.str_ = string;
        retval.u1.type_info = IS_STRING_EX;
    }
    true
}

/// A persistent immutable list of strings (`['0', '1', ...]`), as a constant of an internal
/// class holds an array: nothing counts references to it, and it lives for the process.
///
/// Not for an empty list: opcache frees the data of an initialized empty table it persists
/// (a PHP subclass's copy of an inherited default) as if the engine's allocator held it, so
/// `[]` is [`empty_array`].
pub fn string_list(items: &[&str]) -> Zval {
    debug_assert!(!items.is_empty(), "an empty list is `empty_array()`");
    // SAFETY: called in MINIT; the table is allocated persistently and never freed, its
    // values are interned strings, and the flags mark it immutable so the engine copies the
    // zval without touching a reference count.
    unsafe {
        let ht = ext_php_rs::ffi::__zend_malloc(std::mem::size_of::<HashTable>()).cast::<HashTable>();
        _zend_hash_init(ht, u32::try_from(items.len()).unwrap_or(8), std::ptr::null_mut(), true);
        zend_hash_real_init_packed(ht);
        for item in items {
            let mut value = interned(item.as_bytes());
            zend_hash_next_index_insert(ht, &raw mut value);
            std::mem::forget(value);
        }
        (*ht).gc.refcount = 2;
        (*ht).gc.u.type_info = IS_ARRAY | IMMUTABLE_ARRAY;
        let mut zval = Zval::new();
        zval.value.arr = ht;
        zval.u1.type_info = IS_ARRAY;
        zval
    }
}

/// A persistent immutable array of strings keyed by strings (`['a' => 'x', ...]`), as a
/// constant of an internal class holds one.
pub fn string_map(items: &[(&str, &str)]) -> Zval {
    // SAFETY: as [`string_list`]: allocated persistently in MINIT, never freed, its keys and
    // values interned, and flagged immutable.
    unsafe {
        let ht = ext_php_rs::ffi::__zend_malloc(std::mem::size_of::<HashTable>()).cast::<HashTable>();
        _zend_hash_init(ht, u32::try_from(items.len()).unwrap_or(8), std::ptr::null_mut(), true);
        for (key, item) in items {
            let mut value = interned(item.as_bytes());
            zend_hash_str_add(ht, key.as_ptr().cast(), key.len(), &raw mut value);
            std::mem::forget(value);
        }
        (*ht).gc.refcount = 2;
        (*ht).gc.u.type_info = IS_ARRAY | IMMUTABLE_ARRAY;
        let mut zval = Zval::new();
        zval.value.arr = ht;
        zval.u1.type_info = IS_ARRAY;
        zval
    }
}

/// A persistent immutable array of lists of strings keyed by strings (`['a' => ['x', 'y'],
/// 'b' => []]`), as a constant of an internal class holds one: each list a [`string_list`],
/// an empty one the engine's immutable [`empty_array`].
pub fn string_list_map(items: &[(&str, &[&str])]) -> Zval {
    // SAFETY: as [`string_map`]; the lists are persistent and immutable themselves.
    unsafe {
        let ht = ext_php_rs::ffi::__zend_malloc(std::mem::size_of::<HashTable>()).cast::<HashTable>();
        _zend_hash_init(ht, u32::try_from(items.len()).unwrap_or(8), std::ptr::null_mut(), true);
        for (key, list) in items {
            let mut value = if list.is_empty() { empty_array() } else { string_list(list) };
            zend_hash_str_add(ht, key.as_ptr().cast(), key.len(), &raw mut value);
            std::mem::forget(value);
        }
        (*ht).gc.refcount = 2;
        (*ht).gc.u.type_info = IS_ARRAY | IMMUTABLE_ARRAY;
        let mut zval = Zval::new();
        zval.value.arr = ht;
        zval.u1.type_info = IS_ARRAY;
        zval
    }
}

/// Declares the public constant `name` of `class` with `value` (a scalar, an interned
/// string or a [`string_list`]) and type `mask` (0: untyped).
pub fn declare_constant(class: &mut ClassEntry, name: &str, value: Zval, mask: u32) {
    declare_constant_as(class, name, value, mask, ext_php_rs::flags::ConstantFlags::Public);
}

/// Declares the constant `name` of `class` with `value`, type `mask` and visibility `flags`.
pub fn declare_constant_as(
    class: &mut ClassEntry,
    name: &str,
    mut value: Zval,
    mask: u32,
    flags: ext_php_rs::flags::ConstantFlags,
) {
    // SAFETY: called in MINIT, while the class is registered; the name is interned and the
    // value is not reference counted, as an internal class's constants must be.
    unsafe {
        let init = ext_php_rs::ffi::zend_string_init_interned.expect("zend_string_init_interned");
        let name = init(name.as_ptr().cast(), name.len(), true);
        let flags: i32 = flags.bits().try_into().expect("constant flags fit an int");
        zend_declare_typed_class_constant(
            class,
            name,
            &raw mut value,
            flags,
            std::ptr::null_mut(),
            zend_type { ptr: std::ptr::null_mut(), type_mask: mask },
        );
    }
    std::mem::forget(value);
}

/// Moves the constants `names` (the parent's, which the engine inherits as it registers the
/// class) after the ones the class declares itself, the order PHP gives a class's constants.
pub fn inherited_constants_last(class: &mut ClassEntry, names: &[&str]) {
    // SAFETY: called in MINIT, on the class's own table.
    unsafe { entries_last(&raw mut class.constants_table, names) }
}

/// Moves the inherited properties `names` after `class`'s own in its property table, the
/// order reflection and `var_dump()` list them in for a PHP subclass (the engine copies the
/// parent's into an internal class when it registers it, before the class declares its own).
pub fn inherited_properties_last(class: &mut ClassEntry, names: &[&str]) {
    // SAFETY: as in [`inherited_constants_last`]; the property table of an internal class
    // has no destructor either.
    unsafe { entries_last(&raw mut class.properties_info, names) }
}

/// Moves the entries `names` of `table`, a table of pointers with no destructor, to its end.
///
/// # Safety
///
/// `table` is a live table of `IS_PTR` entries that frees nothing when an entry goes.
unsafe fn entries_last(table: *mut HashTable, names: &[&str]) {
    // SAFETY: each pointer is taken out and put back at the end.
    unsafe {
        for name in names {
            let found = zend_hash_str_find(table, name.as_ptr().cast(), name.len());
            let Some(entry) = found.as_ref() else { continue };
            if entry.u1.type_info != IS_PTR {
                continue;
            }
            let mut pointer = Zval::new();
            pointer.value.ptr = entry.value.ptr;
            pointer.u1.type_info = IS_PTR;
            zend_hash_str_del(table, name.as_ptr().cast(), name.len());
            zend_hash_str_add(table, name.as_ptr().cast(), name.len(), &raw mut pointer);
            std::mem::forget(pointer);
        }
    }
}

/// PHP's name for `value`'s type in its messages: `int`, `true`, `stdClass`.
pub fn value_name(value: &Zval) -> String {
    // SAFETY: `value` is a live zval; the engine returns a static or interned name.
    unsafe { CStr::from_ptr(zend_zval_value_name(value)) }.to_string_lossy().into_owned()
}

/// Raises the `E_DEPRECATED` `message`, as the engine raises one.
pub fn deprecated(message: &str) {
    /// `E_DEPRECATED`.
    const E_DEPRECATED: i32 = 1 << 13;
    let message = CString::new(message.replace('\0', "")).unwrap_or_default();
    // SAFETY: the engine formats `%s` with a NUL-terminated string.
    unsafe { zend_error(E_DEPRECATED, c"%s".as_ptr(), message.as_ptr()) };
}

/// Raises the `E_WARNING` `message`, as the engine raises one.
pub fn warning(message: &str) {
    /// `E_WARNING`.
    const E_WARNING: i32 = 1 << 1;
    let message = CString::new(message.replace('\0', "")).unwrap_or_default();
    // SAFETY: the engine formats `%s` with a NUL-terminated string.
    unsafe { zend_error(E_WARNING, c"%s".as_ptr(), message.as_ptr()) };
}

/// PHP `$container['key']` read: the engine's own fetch, with its warnings (`Undefined array
/// key`, `Trying to access array offset on null`), its errors and `ArrayAccess::offsetGet()`;
/// `None` with an exception pending.
pub fn dimension(container: &Zval, key: &str) -> Option<Zval> {
    let mut result = Zval::new();
    let mut container = container.dereference().shallow_clone();
    let mut dim = string(key.as_bytes());
    // SAFETY: live zvals; `BP_VAR_R` (0) copies the element (or `null`) into `result`, which
    // the returned `Zval` owns.
    unsafe { zend_fetch_dimension_const(&raw mut result, &raw mut container, &raw mut dim, 0) };
    if has_exception() { None } else { Some(result) }
}

/// PHP `$container[$index]` read for an integer `index`, as [`dimension`] reads a key.
pub fn dimension_index(container: &Zval, index: i64) -> Option<Zval> {
    let mut result = Zval::new();
    let mut container = container.dereference().shallow_clone();
    let mut dim = Zval::new();
    dim.set_long(index);
    // SAFETY: as in [`dimension`].
    unsafe { zend_fetch_dimension_const(&raw mut result, &raw mut container, &raw mut dim, 0) };
    if has_exception() { None } else { Some(result) }
}

/// PHP `$a + $b`: the engine's addition, with its coercions, warnings and `TypeError`s;
/// `None` with an exception pending.
pub fn add(a: &Zval, b: &Zval) -> Option<Zval> {
    let mut result = Zval::new();
    let (mut a, mut b) = (a.dereference().shallow_clone(), b.dereference().shallow_clone());
    // SAFETY: live zvals; the engine writes the sum into `result`, which the returned `Zval`
    // owns.
    unsafe { add_function(&raw mut result, &raw mut a, &raw mut b) };
    if has_exception() { None } else { Some(result) }
}

/// Calls the PHP function `name` with `args` (at most three), as a function in a file without
/// `declare(strict_types=1)` calls it (an internal caller passes arguments in weak mode), so
/// its coercions, deprecations and errors are PHP's; `None` with an exception pending.
pub fn call(name: &str, args: &[&Zval]) -> Option<Zval> {
    // SAFETY: the engine's function table, whose functions live for the process (the
    // internal ones this calls) or the request.
    let function = unsafe {
        zend_hash_str_find_ptr_lc((*executor_globals()).function_table, name.as_ptr().cast(), name.len())
            .cast::<zend_function>()
    };
    let mut params = [Zval::new(), Zval::new(), Zval::new()];
    for (param, arg) in params.iter_mut().zip(args) {
        *param = arg.dereference().shallow_clone();
    }
    let count = u32::try_from(args.len().min(3)).unwrap_or(3);
    let mut retval = Zval::new();
    if function.is_null() {
        // Not defined (a disabled function): the engine raises PHP's error for the name.
        let mut function = string(name.as_bytes());
        // SAFETY: the name and the arguments are live zvals laid out contiguously; the engine
        // writes the result into `retval`, which the returned `Zval` owns.
        let result = unsafe {
            ext_php_rs::ffi::_call_user_function_impl(
                std::ptr::null_mut(),
                &raw mut function,
                &raw mut retval,
                count,
                params.as_mut_ptr(),
                std::ptr::null_mut(),
            )
        };
        return if result < 0 || has_exception() { None } else { Some(retval) };
    }
    // SAFETY: a function of the table, called as `call_user_function()` would call it once
    // resolved; the arguments are live zvals laid out contiguously.
    unsafe {
        ext_php_rs::ffi::zend_call_known_function(
            function,
            std::ptr::null_mut(),
            std::ptr::null_mut(),
            &raw mut retval,
            count,
            params.as_mut_ptr().cast(),
            std::ptr::null_mut(),
        );
    }
    if has_exception() { None } else { Some(retval) }
}

/// Throws an `Error` with `message`, as the engine throws one.
pub fn error(message: &str) {
    let _ = throw(ce::error(), message);
}

/// A method the extension calls on PHP objects, its name and lowercase key interned in MINIT
/// ([`Callee::intern`]) so a call neither allocates nor lowercases them.
pub struct Callee {
    name: &'static str,
    strings: OnceLock<CalleeStrings>,
}

/// The interned name and lowercase key of a [`Callee`].
struct CalleeStrings {
    name: *mut zend_string,
    key: *mut zend_string,
}

// SAFETY: persistent interned strings, created once in MINIT and never freed or changed.
unsafe impl Send for CalleeStrings {}
// SAFETY: as above.
unsafe impl Sync for CalleeStrings {}

impl Callee {
    pub const fn new(name: &'static str) -> Self {
        Self { name, strings: OnceLock::new() }
    }

    /// Interns the name and its key; called in MINIT, before any call.
    pub fn intern(&self) {
        self.strings.get_or_init(|| CalleeStrings {
            name: interned(self.name.as_bytes()).value_str(),
            key: interned(self.name.to_ascii_lowercase().as_bytes()).value_str(),
        });
    }
}

/// The string an [`interned`] zval holds.
trait InternedValue {
    fn value_str(self) -> *mut zend_string;
}

impl InternedValue for Zval {
    fn value_str(self) -> *mut zend_string {
        // SAFETY: an interned string zval: nothing counts references to the string.
        let string = unsafe { self.value.str_ };
        std::mem::forget(self);
        string
    }
}

/// PHP `$object->name(...$args)` from the executing method's class: the object's own method
/// (a subclass's override, `__call()`), its visibility checked against that class, and
/// PHP's `Error` for a value that is not an object or a method that does not exist; `None`
/// with an exception pending. At most four arguments.
pub fn call_method(object: &Zval, method: &Callee, args: &[&Zval]) -> Option<Zval> {
    let object = object.dereference();
    let Some(target) = object.object() else {
        error(&format!("Call to a member function {}() on {}", method.name, value_name(object)));
        return None;
    };
    let strings = method.strings.get().expect("method names are interned in MINIT");
    let mut key = Zval::new();
    key.value.str_ = strings.key;
    key.u1.type_info = IS_STRING;
    let mut target = std::ptr::from_ref(target).cast_mut().cast::<zend_object>();
    // SAFETY: a live object and its own handlers; `get_method` resolves the method as the
    // `INIT_METHOD_CALL` opcode does (from the executing scope, which is this method's class,
    // with the lowercase key the opcode carries), and the call adds what references it needs
    // to the arguments.
    unsafe {
        let get_method = (*(*target).handlers).get_method.expect("get_method");
        let function = get_method(&raw mut target, strings.name, &raw const key);
        if function.is_null() {
            if !has_exception() {
                zend_undefined_method((*target).ce, strings.name);
            }
            return None;
        }
        let mut params = [Zval::new(), Zval::new(), Zval::new(), Zval::new()];
        for (param, arg) in params.iter_mut().zip(args) {
            *param = arg.dereference().shallow_clone();
        }
        let mut retval = Zval::new();
        ext_php_rs::ffi::zend_call_known_function(
            function,
            target,
            (*target).ce,
            &raw mut retval,
            u32::try_from(args.len().min(4)).unwrap_or(4),
            params.as_mut_ptr().cast(),
            std::ptr::null_mut(),
        );
        if has_exception() { None } else { Some(retval) }
    }
}

/// The method a [`Callee`] last resolved to on one class; reset at request shutdown.
pub struct MethodCache {
    class: std::sync::atomic::AtomicPtr<zend_class_entry>,
    function: std::sync::atomic::AtomicPtr<zend_function>,
}

impl MethodCache {
    pub const fn new() -> Self {
        Self {
            class: std::sync::atomic::AtomicPtr::new(std::ptr::null_mut()),
            function: std::sync::atomic::AtomicPtr::new(std::ptr::null_mut()),
        }
    }

    /// Forgets the method (request shutdown).
    pub fn reset(&self) {
        self.class.store(std::ptr::null_mut(), Ordering::Relaxed);
        self.function.store(std::ptr::null_mut(), Ordering::Relaxed);
    }
}

impl Default for MethodCache {
    fn default() -> Self {
        Self::new()
    }
}

/// `ZEND_ACC_CALL_VIA_TRAMPOLINE`: a `__call()` stand-in, freed after its call.
const ACC_CALL_VIA_TRAMPOLINE: u32 = 1 << 18;

/// [`call_method`] for a public by-value method, its lookup kept in `cache` per class.
pub fn call_method_cached(object: &Zval, method: &Callee, cache: &MethodCache, args: &[Zval]) -> Option<Zval> {
    let object = object.dereference();
    let Some(target) = object.object() else {
        let args: Vec<&Zval> = args.iter().collect();
        return call_method(object, method, &args);
    };
    let target = std::ptr::from_ref(target).cast_mut().cast::<zend_object>();
    // SAFETY: a live object and its class.
    let class = unsafe { (*target).ce };
    let mut function = std::ptr::null_mut();
    if cache.class.load(Ordering::Relaxed) == class {
        function = cache.function.load(Ordering::Relaxed);
    }
    if function.is_null() {
        let strings = method.strings.get().expect("method names are interned in MINIT");
        let mut key = Zval::new();
        key.value.str_ = strings.key;
        key.u1.type_info = IS_STRING;
        let mut resolved = target;
        // SAFETY: as in [`call_method`]; only a plain method of the class itself is cached.
        unsafe {
            let get_method = (*(*target).handlers).get_method.expect("get_method");
            function = get_method(&raw mut resolved, strings.name, &raw const key);
            if function.is_null() || resolved != target || (*function).common.fn_flags & ACC_CALL_VIA_TRAMPOLINE != 0 {
                if !function.is_null() && (*function).common.fn_flags & ACC_CALL_VIA_TRAMPOLINE != 0 {
                    zend_release_trampoline(function);
                }
                let args: Vec<&Zval> = args.iter().collect();
                return call_method(object, method, &args);
            }
        }
        cache.class.store(class, Ordering::Relaxed);
        cache.function.store(function, Ordering::Relaxed);
    }
    let mut retval = Zval::new();
    // SAFETY: as in [`call_method`]; by-value parameters leave `args` as they are.
    unsafe {
        ext_php_rs::ffi::zend_call_known_function(
            function,
            target,
            class,
            &raw mut retval,
            u32::try_from(args.len()).unwrap_or(u32::MAX),
            args.as_ptr().cast_mut().cast(),
            std::ptr::null_mut(),
        );
    }
    if has_exception() { None } else { Some(retval) }
}

/// Frees a `__call()` trampoline `get_method` made (`zend_free_trampoline`).
unsafe fn zend_release_trampoline(function: *mut zend_function) {
    let mut fcc = [function, std::ptr::null_mut(), std::ptr::null_mut(), std::ptr::null_mut(), std::ptr::null_mut()];
    // SAFETY: a `zend_fcall_info_cache` holding only the trampoline, which the engine frees.
    unsafe { zend_release_fcall_info_cache(fcc.as_mut_ptr().cast()) };
}

/// `ZEND_ACC_STRICT_TYPES`: a function from a file declaring `strict_types=1`.
const ACC_STRICT_TYPES: u32 = 1 << 31;

/// Whether the code that called the method declares `strict_types=1` (an internal caller,
/// such as reflection, does not): what decides how PHP coerces its arguments.
pub fn caller_strict(ex: &ExecuteData) -> bool {
    // SAFETY: the frames and functions are the engine's, alive during the call.
    unsafe {
        let Some(prev) = ex.prev_execute_data.as_ref() else { return false };
        let Some(func) = prev.func.as_ref() else { return false };
        func.type_ & 1 == 0 && func.common.fn_flags & ACC_STRICT_TYPES != 0
    }
}

/// Argument `num` of the method coerced to the scalar types `mask` as a userland parameter
/// of that type takes it (`null` refused unless `mask` allows it), or the `TypeError` naming
/// it `expected`; `None` with the exception pending.
pub fn scalar_arg(ex: &ExecuteData, num: u32, arg: &Zval, mask: u32, expected: &str) -> Option<Zval> {
    let mut value = arg.dereference().shallow_clone();
    if type_matches(&value, mask) {
        return Some(value);
    }
    // SAFETY: a live zval, which the engine coerces in place.
    if unsafe { zend_verify_scalar_type_hint(mask, &raw mut value, caller_strict(ex), false) } {
        return (!has_exception()).then_some(value);
    }
    if !has_exception() {
        type_error(ex, num, expected, arg.dereference());
    }
    None
}

/// Whether `value`'s own type is one of `mask`'s (what the engine checks before coercing).
fn type_matches(value: &Zval, mask: u32) -> bool {
    // SAFETY: reading a live zval's type byte.
    let ty = unsafe { value.u1.type_info } & 0xff;
    ty < 32 && mask & (1 << ty) != 0
}

/// `value` coerced to the scalar types `mask` as a weak-mode userland parameter takes it:
/// `Some(Some(value))` coerced, `Some(None)` refused (the caller throws its `TypeError`),
/// `None` with an exception pending (a `__toString()` that threw).
pub fn coerce_weak(value: &Zval, mask: u32) -> Option<Option<Zval>> {
    let mut value = value.dereference().shallow_clone();
    if type_matches(&value, mask) {
        return Some(Some(value));
    }
    // SAFETY: a live zval, which the engine coerces in place.
    let coerced = unsafe { zend_verify_scalar_type_hint(mask, &raw mut value, false, false) };
    if has_exception() {
        return None;
    }
    Some(coerced.then_some(value))
}

/// The method's return `value` checked against its scalar return types `mask` as a userland
/// function declared in a file with (`strict`) or without `strict_types=1` checks it: coerced
/// in weak mode, or the engine's `TypeError` ("X::f(): Return value must be of type string,
/// null returned"); `None` with the exception pending.
pub fn verify_return(ex: &ExecuteData, value: Zval, mask: u32, strict: bool) -> Option<Zval> {
    let mut value = value.dereference().shallow_clone();
    if type_matches(&value, mask) {
        return Some(value);
    }
    // SAFETY: a live zval, which the engine coerces in place; the function is the method's own.
    unsafe {
        if zend_verify_scalar_type_hint(mask, &raw mut value, strict, false) {
            return (!has_exception()).then_some(value);
        }
        if !has_exception() {
            zend_verify_return_error(ex.func, &raw const value);
        }
    }
    None
}

/// PHP truthiness of a condition (`if ($valid)`), with the warning PHP 8.5 raises for NAN.
pub fn condition(value: &Zval) -> bool {
    if value.dereference().double().is_some_and(f64::is_nan) {
        warning("unexpected NAN value was coerced to bool");
    }
    truthy(value)
}

/// PHP `++$value`: the engine's increment, with its deprecations; `None` with an exception
/// pending.
pub fn increment(value: &Zval) -> Option<Zval> {
    let mut value = value.dereference().shallow_clone();
    // SAFETY: a live zval, which the engine increments in place.
    unsafe { increment_function(&raw mut value) };
    if has_exception() { None } else { Some(value) }
}

/// The values `foreach ($list as $key => $value)` visits from the executing method's class:
/// an array's, a `Traversable`'s, an object's properties visible from that class
/// (`get_object_vars()`), and nothing (with PHP's warning) for anything else; `None` with an
/// exception pending. The array holds its keys as `foreach` sees them.
pub fn foreach_items(list: &Zval) -> Option<Zval> {
    let list = list.dereference();
    if list.is_array() {
        return Some(list.shallow_clone());
    }
    if list.object().is_some() {
        if truthy(&call("is_iterable", &[list])?) {
            let mut preserve = Zval::new();
            preserve.set_bool(true);
            return call("iterator_to_array", &[list, &preserve]);
        }
        return call("get_object_vars", &[list]);
    }
    warning(&format!("foreach() argument must be of type array|object, {} given", value_name(list)));
    Some(empty_array())
}

// ---------------------------------------------------------------------------------------
// What the auth hashes need beyond the validators: arrays read and written as PHP keys them,
// methods called on `$this` as PHP dispatches them, and a few of the engine's operators.

unsafe extern "C" {
    fn zval_get_long_func(op: *const zval, is_strict: bool) -> i64;
    fn div_function(result: *mut zval, op1: *mut zval, op2: *mut zval) -> i32;
    fn mod_function(result: *mut zval, op1: *mut zval, op2: *mut zval) -> i32;
    fn zend_hash_str_update(ht: *mut HashTable, key: *const c_char, len: usize, value: *mut zval) -> *mut zval;
}

/// PHP's `zval_get_long()`: an option read as an integer the way `password_hash()` reads
/// its options (`"12"` is 12, `"abc"` 0, `1.9` 1, `true` 1, an array 1 when not empty).
pub fn long_value(value: &Zval) -> i64 {
    let value = value.dereference();
    if let Some(i) = value.long() {
        return i;
    }
    // SAFETY: a live zval; the engine only reads it (an object's cast may warn).
    unsafe { zval_get_long_func(std::ptr::from_ref(value).cast(), false) }
}

/// The engine's `$a / $b` and `$a % $b`, with their coercions, warnings and errors; `None`
/// with an exception pending.
pub fn divide(a: &Zval, b: &Zval) -> Option<Zval> {
    binary(div_function, a, b)
}

/// See [`divide`].
pub fn modulo(a: &Zval, b: &Zval) -> Option<Zval> {
    binary(mod_function, a, b)
}

fn binary(op: unsafe extern "C" fn(*mut zval, *mut zval, *mut zval) -> i32, a: &Zval, b: &Zval) -> Option<Zval> {
    let mut result = Zval::new();
    let (mut a, mut b) = (a.dereference().shallow_clone(), b.dereference().shallow_clone());
    // SAFETY: live zvals; the engine writes the result into `result`, which the returned `Zval`
    // owns.
    unsafe { op(&raw mut result, &raw mut a, &raw mut b) };
    if has_exception() { None } else { Some(result) }
}

/// An array key as PHP stores it: a string holding a canonical decimal integer (`"7"`,
/// `"-3"`, not `"07"`, `"-0"` or one past `PHP_INT_MAX`) is that integer.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Key {
    Index(i64),
    Name(Vec<u8>),
}

impl Key {
    /// The key PHP stores `$array[$bytes]` under (`ZEND_HANDLE_NUMERIC_STR`).
    pub fn of(bytes: &[u8]) -> Self {
        let digits = bytes.strip_prefix(b"-").unwrap_or(bytes);
        let canonical = !digits.is_empty()
            && digits.iter().all(u8::is_ascii_digit)
            && (digits[0] != b'0' || (digits.len() == 1 && digits.len() == bytes.len()));
        if canonical && let Some(i) = std::str::from_utf8(bytes).ok().and_then(|s| s.parse::<i64>().ok()) {
            return Key::Index(i);
        }
        Key::Name(bytes.to_vec())
    }
}

/// `$array[$key]`, without a warning when it is missing; the element's reference resolved.
pub fn array_get<'a>(array: &'a Zval, key: &Key) -> Option<&'a Zval> {
    let ht = array.dereference().array()?;
    // SAFETY: the table lives as long as `array`; the engine returns a pointer into it.
    let found = unsafe {
        match key {
            Key::Index(i) => ext_php_rs::ffi::zend_hash_index_find(std::ptr::from_ref(ht).cast(), *i as u64),
            Key::Name(name) => zend_hash_str_find(std::ptr::from_ref(ht).cast(), name.as_ptr().cast(), name.len()),
        }
    };
    unsafe { found.cast::<Zval>().as_ref() }.map(Zval::dereference)
}

/// A copy of `array` with `$copy[$key] = $value` (the copy is PHP's separation: `array`
/// itself is left as it is).
pub fn array_with(array: &Zval, key: &Key, mut value: Zval) -> Zval {
    // SAFETY: the engine copies the table (a new one, which the returned zval owns) and takes
    // `value` into it, the copy's slot owning what `value` held.
    unsafe {
        let source = array.dereference().array().map_or(std::ptr::addr_of!(ext_php_rs::ffi::zend_empty_array), |ht| {
            std::ptr::from_ref(ht).cast::<HashTable>()
        });
        let copy = zend_array_dup(source.cast_mut());
        match key {
            Key::Index(i) => {
                ext_php_rs::ffi::zend_hash_index_update(copy, *i as u64, std::ptr::from_mut(&mut value).cast());
            }
            Key::Name(name) => {
                zend_hash_str_update(copy, name.as_ptr().cast(), name.len(), std::ptr::from_mut(&mut value).cast());
            }
        }
        std::mem::forget(value);
        let mut zval = Zval::new();
        zval.set_hashtable(ZBox::from_raw(copy));
        zval
    }
}

/// An array key as a table holds it: an index, or a name.
#[derive(Clone, Copy)]
pub enum KeyRef<'a> {
    Index(i64),
    Name(&'a ZendStr),
}

/// `ht`'s keys and values in order, read straight from its slots (what `foreach ($ht as $key
/// => $value)` visits), without copying either.
pub fn keyed_values(ht: &ZendHashTable) -> impl Iterator<Item = (KeyRef<'_>, &Zval)> {
    (0..ht.nNumUsed as usize).filter_map(move |i| {
        // SAFETY: within the used slots; a packed table's keys are the slot indexes, a
        // bucket's are its `key` string or, when that is null, its `h`.
        unsafe {
            let value = &*slot(ht, i);
            if value.u1.type_info == 0 {
                return None;
            }
            let key = if ht.u.flags & HASH_FLAG_PACKED != 0 {
                KeyRef::Index(i as i64)
            } else {
                let bucket = &*ht.__bindgen_anon_1.arData.add(i);
                match bucket.key.cast::<ZendStr>().as_ref() {
                    Some(name) => KeyRef::Name(name),
                    None => KeyRef::Index(bucket.h as i64),
                }
            };
            Some((key, value))
        }
    })
}

/// Runs `run` on `$int` as PHP converts it to a string, written on the stack.
pub fn with_decimal<T>(int: i64, run: impl FnOnce(&[u8]) -> T) -> T {
    let mut digits = [0u8; 21];
    let mut n = int.unsigned_abs();
    let mut start = digits.len();
    loop {
        start -= 1;
        digits[start] = b'0' + (n % 10) as u8;
        n /= 10;
        if n == 0 {
            break;
        }
    }
    if int < 0 {
        start -= 1;
        digits[start] = b'-';
    }
    run(&digits[start..])
}

/// Appends `$int` as PHP converts it to a string.
pub fn append_decimal(int: i64, out: &mut Vec<u8>) {
    with_decimal(int, |digits| out.extend_from_slice(digits));
}

/// `$array`'s keys and values in order (what `foreach ($array as $key => $value)` visits),
/// the values' references resolved.
pub fn entries(ht: &ZendHashTable) -> Vec<(Key, Zval)> {
    (0..ht.nNumUsed as usize)
        .filter_map(|i| {
            // SAFETY: within the used slots; a packed table's keys are the slot indexes, a
            // bucket's are its `key` string or, when that is null, its `h`.
            unsafe {
                let value = &*slot(ht, i);
                if value.u1.type_info == 0 {
                    return None;
                }
                let key = if ht.u.flags & HASH_FLAG_PACKED != 0 {
                    Key::Index(i as i64)
                } else {
                    let bucket = &*ht.__bindgen_anon_1.arData.add(i);
                    match bucket.key.cast::<ZendStr>().as_ref() {
                        Some(name) => Key::Name(name.as_bytes().to_vec()),
                        None => Key::Index(bucket.h as i64),
                    }
                };
                Some((key, value.dereference().shallow_clone()))
            }
        })
        .collect()
}

/// `$this->name(...$args)` from a method of the class itself, by name (unlike [`call_method`]):
/// the method of `this`'s own class (a subclass's override), whatever its visibility. `None` with
/// an exception pending.
pub fn call_own_method(this: &mut ZendObject, name: &str, args: &[&Zval]) -> Option<Zval> {
    let class = this.ce;
    // SAFETY: the object's class and its function table live as long as the object.
    let function = unsafe {
        zend_hash_str_find_ptr_lc(&raw const (*class).function_table, name.as_ptr().cast(), name.len())
            .cast::<zend_function>()
    };
    if function.is_null() {
        return None;
    }
    let mut params: Vec<Zval> = args.iter().map(|arg| arg.dereference().shallow_clone()).collect();
    let mut retval = Zval::new();
    let count = u32::try_from(params.len()).unwrap_or(u32::MAX);
    // SAFETY: a method of the object's class, called on the object with live arguments; the
    // engine writes the result into `retval`, which the returned `Zval` owns.
    unsafe {
        ext_php_rs::ffi::zend_call_known_function(
            function,
            std::ptr::from_mut(this),
            class,
            std::ptr::from_mut(&mut retval).cast(),
            count,
            params.as_mut_ptr().cast(),
            std::ptr::null_mut(),
        );
    }
    if has_exception() { None } else { Some(retval) }
}

/// PHP `$container[$dim]` read, for any key: the engine's fetch, with its warnings
/// (`Uninitialized string offset 70`, `Undefined array key`) and errors; `None` with an
/// exception pending.
pub fn dimension_of(container: &Zval, dim: &Zval) -> Option<Zval> {
    let mut result = Zval::new();
    let mut container = container.dereference().shallow_clone();
    let mut dim = dim.dereference().shallow_clone();
    // SAFETY: as in [`dimension`].
    unsafe { zend_fetch_dimension_const(&raw mut result, &raw mut container, &raw mut dim, 0) };
    if has_exception() { None } else { Some(result) }
}

/// Declares the private constant `name` of `class`, with `value` and type `mask`, as
/// [`declare_constant`] declares a public one.
pub fn declare_private_constant(class: &mut ClassEntry, name: &str, mut value: Zval, mask: u32) {
    // SAFETY: as in [`declare_constant`].
    unsafe {
        let init = ext_php_rs::ffi::zend_string_init_interned.expect("zend_string_init_interned");
        let name = init(name.as_ptr().cast(), name.len(), true);
        let flags: i32 =
            ext_php_rs::flags::ConstantFlags::Private.bits().try_into().expect("constant flags fit an int");
        zend_declare_typed_class_constant(
            class,
            name,
            &raw mut value,
            flags,
            std::ptr::null_mut(),
            zend_type { ptr: std::ptr::null_mut(), type_mask: mask },
        );
    }
    std::mem::forget(value);
}

/// Types a parameter or return as the class `name` (`self`, a class): a named type, as the
/// engine stores an internal function's class type once registered.
pub fn set_class_type(info: &mut ArgInfo, name: &str) {
    // SAFETY: called in MINIT; the interned name lives for the process.
    unsafe {
        let init = ext_php_rs::ffi::zend_string_init_interned.expect("zend_string_init_interned");
        info.type_.ptr = init(name.as_ptr().cast(), name.len(), true).cast();
    }
    info.type_.type_mask = (info.type_.type_mask & !(MAY_BE_ANY | ext_php_rs::ffi::_ZEND_TYPE_LITERAL_NAME_BIT))
        | ext_php_rs::ffi::_ZEND_TYPE_NAME_BIT;
}
