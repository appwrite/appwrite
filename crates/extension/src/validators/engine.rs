//! The engine calls the hand-built validator classes need and ext-php-rs does not wrap:
//! typed property declarations, property reads and writes in a class's scope, PHP's own
//! weak-mode argument coercions, and the argument errors of a userland function, so a
//! native method takes and rejects arguments exactly as the PHP method it replaces.

use std::ffi::{CStr, CString, c_char, c_void};
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
/// `MAY_BE_ARRAY`.
pub const MAY_BE_ARRAY: u32 = 1 << 7;
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
    declare_as(class, property, mask, default, promoted);
}

/// Declares `property` as a protected typed property of `class` without a default, as a
/// property declared in the class body is (`protected array $patterns;`): uninitialized until
/// it is assigned, and not promoted.
pub fn declare_uninitialized(class: &mut ClassEntry, property: &Property, mask: u32) {
    declare_as(class, property, mask, None, false);
}

fn declare_as(class: &mut ClassEntry, property: &Property, mask: u32, default: Option<Zval>, promoted: bool) {
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
        return (!ExecutorGlobals::has_exception()).then_some(dest);
    }
    if !ExecutorGlobals::has_exception() {
        type_error(ex, num, expected, arg);
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
    if ExecutorGlobals::has_exception() { None } else { Some(bytes) }
}

/// PHP `implode(', ', $list)`: each element converted as [`to_string`] converts it.
pub fn implode(list: &Zval) -> Option<Vec<u8>> {
    let mut joined = Vec::new();
    if let Some(array) = list.array() {
        for (i, value) in values(array).enumerate() {
            if i > 0 {
                joined.extend_from_slice(b", ");
            }
            match value.dereference().zend_str() {
                Some(s) => joined.extend_from_slice(s.as_bytes()),
                None => joined.extend_from_slice(&to_string(value)?),
            }
        }
    }
    Some(joined)
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
    if ExecutorGlobals::has_exception() { None } else { Some(equal) }
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
const IS_STRING: u32 = 6;
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

/// A persistent immutable list of strings (`['0', '1', ...]`), as a constant of an internal
/// class holds an array: nothing counts references to it, and it lives for the process.
pub fn string_list(items: &[&str]) -> Zval {
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

/// Declares the public constant `name` of `class` with `value` (a scalar, an interned
/// string or a [`string_list`]) and type `mask` (0: untyped).
pub fn declare_constant(class: &mut ClassEntry, name: &str, mut value: Zval, mask: u32) {
    // SAFETY: called in MINIT, while the class is registered; the name is interned and the
    // value is not reference counted, as an internal class's constants must be.
    unsafe {
        let init = ext_php_rs::ffi::zend_string_init_interned.expect("zend_string_init_interned");
        let name = init(name.as_ptr().cast(), name.len(), true);
        let flags: i32 = ext_php_rs::flags::ConstantFlags::Public.bits().try_into().expect("constant flags fit an int");
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
    // SAFETY: called in MINIT; the table maps names to the engine's constant pointers and
    // frees nothing when an entry goes (an internal class's constants table has no
    // destructor), so each pointer is taken out and put back at the end.
    unsafe {
        let table = &raw mut class.constants_table;
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
    if ExecutorGlobals::has_exception() { None } else { Some(result) }
}

/// PHP `$a + $b`: the engine's addition, with its coercions, warnings and `TypeError`s;
/// `None` with an exception pending.
pub fn add(a: &Zval, b: &Zval) -> Option<Zval> {
    let mut result = Zval::new();
    let (mut a, mut b) = (a.dereference().shallow_clone(), b.dereference().shallow_clone());
    // SAFETY: live zvals; the engine writes the sum into `result`, which the returned `Zval`
    // owns.
    unsafe { add_function(&raw mut result, &raw mut a, &raw mut b) };
    if ExecutorGlobals::has_exception() { None } else { Some(result) }
}

/// Calls the PHP function `name` with `args`, as a function in a file without
/// `declare(strict_types=1)` calls it (an internal caller passes arguments in weak mode), so
/// its coercions, deprecations and errors are PHP's; `None` with an exception pending.
pub fn call(name: &str, args: &[&Zval]) -> Option<Zval> {
    let mut function = string(name.as_bytes());
    let mut params: Vec<Zval> = args.iter().map(|arg| arg.dereference().shallow_clone()).collect();
    let mut retval = Zval::new();
    let count = u32::try_from(params.len()).unwrap_or(u32::MAX);
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
    if result < 0 || ExecutorGlobals::has_exception() { None } else { Some(retval) }
}
