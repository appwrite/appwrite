//! The engine calls the hand-built validator classes need and ext-php-rs does not wrap:
//! typed property declarations, property reads and writes in a class's scope, PHP's own
//! weak-mode argument coercions, and the argument errors of a userland function, so a
//! native method takes and rejects arguments exactly as the PHP method it replaces.

use std::ffi::{CStr, CString, c_char};
use std::sync::atomic::{AtomicU32, Ordering};

use ext_php_rs::args::ArgInfo;
use ext_php_rs::exception::throw;
use ext_php_rs::ffi::{
    zend_class_entry, zend_function, zend_hash_str_find_ptr_lc, zend_object, zend_property_info, zend_read_property,
    zend_string, zend_type, zval,
};
use ext_php_rs::flags::PropertyFlags;
use ext_php_rs::types::{ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData, ExecutorGlobals, ce};
use utopia_validators::{Input, Number};

/// `MAY_BE_BOOL`: `false` and `true`.
pub const MAY_BE_BOOL: u32 = (1 << 2) | (1 << 3);
/// `MAY_BE_LONG`.
pub const MAY_BE_LONG: u32 = 1 << 4;
/// `MAY_BE_DOUBLE`.
pub const MAY_BE_DOUBLE: u32 = 1 << 5;
/// `MAY_BE_STRING`.
pub const MAY_BE_STRING: u32 = 1 << 6;
/// `MAY_BE_ANY`: every type bit; clearing them leaves an argument untyped.
pub const MAY_BE_ANY: u32 = 0x3fe;

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
}

/// `ZEND_ACC_PROMOTED`: a property declared by a constructor parameter.
const ACC_PROMOTED: i32 = 1 << 8;
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
}

/// Declares `property` as a protected typed property of `class`, with `default`; `None`
/// declares it as a promoted constructor parameter does (uninitialized until the constructor
/// runs, and `isPromoted()` in reflection).
pub fn declare(class: &mut ClassEntry, property: &Property, mask: u32, default: Option<Zval>) {
    let promoted = default.is_none();
    let mut value = default.unwrap_or_else(|| {
        let mut undef = Zval::new();
        undef.u1.type_info = 0;
        undef
    });
    // SAFETY: called while the class is registered (MINIT): the name is interned and
    // persistent, the engine takes the default with `ZVAL_COPY_VALUE` (scalars here), and
    // a mask-only type needs no pointer.
    let info = unsafe {
        let init = ext_php_rs::ffi::zend_string_init_interned.expect("zend_string_init_interned");
        let name = init(property.name.as_ptr().cast(), property.name.len(), true);
        let flags: i32 = PropertyFlags::Protected.bits().try_into().expect("property flags fit an int");
        let flags = if promoted { flags | ACC_PROMOTED } else { flags };
        zend_declare_typed_property(
            class,
            name,
            &raw mut value,
            flags,
            std::ptr::null_mut(),
            zend_type { ptr: std::ptr::null_mut(), type_mask: mask },
        )
    };
    std::mem::forget(value);
    // SAFETY: the engine returns the property's info, alive for the process.
    if let Some(info) = unsafe { info.as_ref() } {
        property.offset.store(info.offset, Ordering::Release);
    }
}

/// `$this->property` read from `scope`, as a method of `scope` reads it: an uninitialized
/// typed property throws its `Error`, and `None` comes back with the exception pending.
///
/// On an object of exactly `scope` (not a subclass, which may redeclare the property with
/// hooks) that is not lazy, an initialized property is read straight from its slot; the
/// rest goes through the engine.
pub fn get(scope: &ClassEntry, this: &mut ZendObject, property: &Property) -> Option<Zval> {
    let offset = property.offset.load(Ordering::Acquire);
    if offset != 0 && std::ptr::eq(this.ce, scope) && !this.is_lazy() {
        // SAFETY: a declared property's slot lies `offset` bytes into an object of the class
        // that declared it, and lives as long as the object.
        let slot = unsafe { &*std::ptr::from_mut(this).cast::<u8>().add(offset as usize).cast::<Zval>() };
        let value = slot.dereference();
        // `IS_UNDEF`: uninitialized, or unset, which the engine reports (or `__get` answers).
        if unsafe { value.u1.type_info } != 0 {
            return Some(value.shallow_clone());
        }
    }
    read(scope, this, property.name)
}

/// `$this->property = $value` from `scope`, for a value already of the property's type.
///
/// On an object of exactly `scope` that is not lazy, the value goes straight into the slot
/// (the old one released); a subclass's object (which may redeclare the property with hooks)
/// and a slot holding a reference go through the engine, with its type checks.
pub fn set(scope: &ClassEntry, this: &mut ZendObject, property: &Property, value: Zval) {
    let offset = property.offset.load(Ordering::Acquire);
    if offset != 0 && std::ptr::eq(this.ce, scope) && !this.is_lazy() {
        // SAFETY: as in [`get`]; the slot owns its value, which the replaced one releases.
        let slot = unsafe { &mut *std::ptr::from_mut(this).cast::<u8>().add(offset as usize).cast::<Zval>() };
        if !slot.is_reference() {
            drop(std::mem::replace(slot, value));
            return;
        }
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
    if ExecutorGlobals::has_exception() { None } else { value }
}

/// `$this->name = $value` from `scope`, with the property's type checks.
pub fn write(scope: &ClassEntry, this: &mut ZendObject, name: &str, mut value: Zval) {
    // SAFETY: the engine copies `value` (adding a reference) into the property.
    unsafe { zend_update_property(scope, std::ptr::from_mut(this), name.as_ptr().cast(), name.len(), &raw mut value) };
}

/// The value a validator checks, as the `utopia_validators` functions take it.
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
pub fn number(value: &Zval) -> Number {
    value.long().map(Number::Int).or_else(|| value.double().map(Number::Float)).unwrap_or(Number::Int(0))
}

/// PHP truthiness, for a method's result used as a condition.
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
pub fn string(bytes: &[u8]) -> Zval {
    let mut zval = Zval::new();
    zval.set_zend_string(ZendStr::new(bytes, false));
    zval
}

/// The method's argument `i` (from 0), if it was passed.
pub fn arg(ex: &mut ExecuteData, i: usize) -> Option<&'static mut Zval> {
    // SAFETY: the engine sets `num_args` for the call, and the argument slot lives as long
    // as the call frame, which outlives the method's use of it.
    let count = unsafe { ex.This.u2.num_args } as usize;
    if i < count { unsafe { ex.zend_call_arg(i) } } else { None }
}

/// How many arguments the method was called with.
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
pub fn bool_arg(ex: &ExecuteData, num: u32, arg: &mut Zval) -> Option<bool> {
    if let Some(b) = arg.bool() {
        return Some(b);
    }
    let mut dest = false;
    // SAFETY: a live zval and a valid destination.
    if !arg.is_null() && unsafe { zend_parse_arg_bool_slow(arg, &raw mut dest, num) } {
        return (!ExecutorGlobals::has_exception()).then_some(dest);
    }
    if !ExecutorGlobals::has_exception() {
        type_error(ex, num, "bool", arg);
    }
    None
}

/// An `int` argument, coerced as a userland `int` parameter is.
pub fn long_arg(ex: &ExecuteData, num: u32, arg: &mut Zval) -> Option<i64> {
    if let Some(i) = arg.long() {
        return Some(i);
    }
    let mut dest = 0;
    // SAFETY: a live zval and a valid destination.
    if !arg.is_null() && unsafe { zend_parse_arg_long_slow(arg, &raw mut dest, num) } {
        return (!ExecutorGlobals::has_exception()).then_some(dest);
    }
    if !ExecutorGlobals::has_exception() {
        type_error(ex, num, "int", arg);
    }
    None
}

/// An `int|float` argument, coerced as a userland `int|float` parameter is.
pub fn number_arg(ex: &ExecuteData, num: u32, arg: &mut Zval) -> Option<Zval> {
    if arg.is_long() || arg.is_double() {
        return Some(arg.shallow_clone());
    }
    let mut dest: *mut zval = std::ptr::null_mut();
    // SAFETY: a live zval; on success the engine points `dest` at the coerced number.
    if !arg.is_null() && unsafe { zend_parse_arg_number_slow(arg, &raw mut dest, num) } {
        if ExecutorGlobals::has_exception() {
            return None;
        }
        return unsafe { dest.cast::<Zval>().as_ref() }.map(Zval::shallow_clone);
    }
    if !ExecutorGlobals::has_exception() {
        type_error(ex, num, "int|float", arg);
    }
    None
}

/// A `string` argument, coerced as a userland `string` parameter is (`Stringable` objects
/// included, in weak mode).
pub fn str_arg(ex: &ExecuteData, num: u32, arg: &mut Zval) -> Option<Vec<u8>> {
    if let Some(s) = arg.zend_str() {
        return Some(s.as_bytes().to_vec());
    }
    let mut dest: *mut zend_string = std::ptr::null_mut();
    // SAFETY: a live zval; on success the engine points `dest` at the string.
    if !arg.is_null() && unsafe { zend_parse_arg_str_slow(arg, &raw mut dest, num) } {
        if ExecutorGlobals::has_exception() {
            return None;
        }
        return unsafe { dest.cast::<ZendStr>().as_ref() }.map(|s| s.as_bytes().to_vec());
    }
    if !ExecutorGlobals::has_exception() {
        type_error(ex, num, "string", arg);
    }
    None
}
