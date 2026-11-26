//! `Utopia\Auth\Hash` and its hashes over [`utopia_auth`] and `php-std`, always registered: the PHP
//! classes they replaced (`Hash.php` and `Hashes/*` of `packages/auth`) were removed, and
//! `packages/auth/stubs/Hash.php` keeps their signatures.
//!
//! Built by hand like the validators ([`crate::classes`]): no Rust object behind them and
//! no constructor beyond PHP's, their state in real typed protected properties (`$options`,
//! PHPass's `$itoa64`), so a PHP subclass extends them as it would the PHP classes, and
//! `var_dump()`, `clone`, `serialize()` and `==` see what they see in PHP.
//!
//! PHP's methods call each other through `$this` (`$this->setOption()`, `$this->getName()`,
//! `$this->hash()`, PHPass's `$this->encode64()`), so a subclass's override runs. Here an
//! object of exactly the native class takes the direct path; any other (a subclass) has the
//! call dispatched by the engine to its own method.

pub mod hashes;
mod native;

use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::args::{Arg, ArgInfoTables};
use ext_php_rs::builders::FunctionBuilder;
use ext_php_rs::exception::throw;
use ext_php_rs::flags::{ClassFlags, DataType, MethodFlags};
use ext_php_rs::types::{ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData, ExecutorGlobals};

use crate::classes::engine::{
    self, Key, KeyRef, Literal, MAY_BE_ANY, MAY_BE_ARRAY, MAY_BE_STRING, Property, string, too_few,
};
use crate::classes::leaf::{self, Method, PUBLIC, this};

/// The base class's name, which the hashes extend.
pub const BASE: &str = "Utopia\\Auth\\Hash";

/// `static`, as a return type (`MAY_BE_STATIC`).
pub const STATIC: u32 = 1 << 15;

static BASE_CLASS: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());

/// `Hash::$options`.
static OPTIONS: Property = Property::new("options");

/// The registered base class.
pub fn base_class() -> &'static ClassEntry {
    leaf::stored(&BASE_CLASS)
}

const BASE_METHODS: &[Method] = &[
    Method {
        name: "setOption",
        handler: set_option,
        flags: PUBLIC,
        params: &[("key", MAY_BE_STRING, None), ("value", MAY_BE_ANY, None)],
        returns: STATIC,
    },
    Method {
        name: "setOptions",
        handler: set_options,
        flags: PUBLIC,
        params: &[("options", MAY_BE_ARRAY, None)],
        returns: STATIC,
    },
    Method {
        name: "getOption",
        handler: get_option,
        flags: PUBLIC,
        params: &[("key", MAY_BE_STRING, None), ("default", MAY_BE_ANY, Some("null"))],
        returns: MAY_BE_ANY,
    },
    Method { name: "getOptions", handler: get_options, flags: PUBLIC, params: &[], returns: MAY_BE_ARRAY },
];

/// An abstract public method of the base, taking `args` and returning `returns`.
fn abstract_method(name: &str, args: Vec<Arg<'static>>, returns: DataType) -> FunctionBuilder<'static> {
    args.into_iter().fold(FunctionBuilder::new_abstract(name), FunctionBuilder::arg).returns(returns, false, false)
}

fn register_base(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare(class, &OPTIONS, MAY_BE_ARRAY, Some(engine::empty_array()));
    BASE_CLASS.store(class, Ordering::Release);
    leaf::patch(class, BASE_METHODS, tables, &[]);
}

/// Registers `Utopia\Auth\Hash` and the hashes. Called from the module's startup (MINIT).
pub fn register() -> ext_php_rs::error::Result<()> {
    let abstract_public = MethodFlags::Public | MethodFlags::Abstract;
    leaf::class(BASE, BASE_METHODS)
        .flags(ClassFlags::Abstract)
        .method(abstract_method("hash", vec![Arg::new("value", DataType::String)], DataType::String), abstract_public)
        .method(
            abstract_method(
                "verify",
                vec![Arg::new("value", DataType::String), Arg::new("hash", DataType::String)],
                DataType::Bool,
            ),
            abstract_public,
        )
        .method(abstract_method("getName", vec![], DataType::String), abstract_public)
        .registration(register_base)
        .register()?;
    hashes::register()
}

// What the methods share --------------------------------------------------------------

/// Throws a new `class` (looked up by name; `Exception` if missing) with `message`.
pub fn fail(class: &str, message: &str) {
    let class = ClassEntry::try_find(class).unwrap_or_else(ext_php_rs::zend::ce::exception);
    let _ = throw(class, message);
}

/// Whether `this` is an object of exactly `class` (no subclass between them), whose methods
/// are then this module's and need no dispatch.
pub fn exact(this: &ZendObject, class: &ClassEntry) -> bool {
    std::ptr::eq(this.ce, class)
}

/// `ZEND_INTERNAL_CLASS`: a class an extension registered.
const INTERNAL_CLASS: std::ffi::c_char = 1;

/// The `$options` slot of an object of this module's classes, holding an array: none of them
/// redeclares the property, so it sits where `Hash` declared it. A PHP subclass may redeclare
/// it (with hooks) and goes through the engine, as does a slot unset or holding a reference.
fn options_slot(this: &mut ZendObject) -> Option<&mut Zval> {
    // SAFETY: an object's class lives as long as the object.
    if unsafe { (*this.ce).type_ } != INTERNAL_CLASS {
        return None;
    }
    engine::private_slot(this, &OPTIONS).filter(|slot| slot.is_array())
}

/// `$this->options`, `None` with the exception pending (a subclass that unset it).
fn options(this: &mut ZendObject) -> Option<Zval> {
    if let Some(slot) = options_slot(this) {
        return Some(slot.shallow_clone());
    }
    engine::get(base_class(), this, &OPTIONS)
}

/// `$this->options[$key] = $value`: in place, as PHP writes it, on this module's classes.
fn store(this: &mut ZendObject, key: &[u8], value: Zval) -> Option<()> {
    if let Some(table) = options_slot(this).and_then(engine::separate) {
        engine::update(table, &Key::of(key), value);
        return Some(());
    }
    let current = options(this)?;
    let updated = engine::array_with(&current, &Key::of(key), value);
    engine::set(base_class(), this, &OPTIONS, updated);
    (!ExecutorGlobals::has_exception()).then_some(())
}

/// `$this->options[$name] = $value` for a `name` that is not a canonical integer (a literal, or
/// a string key of an array): the table shares the string rather than copying it.
fn store_name(this: &mut ZendObject, name: &ZendStr, value: Zval) -> Option<()> {
    if let Some(table) = options_slot(this).and_then(engine::separate) {
        engine::update_name(table, name, value);
        return Some(());
    }
    store(this, name.as_bytes(), value)
}

/// `$this->options[$key] ?? $default`.
fn lookup(this: &mut ZendObject, key: &[u8], default: Zval) -> Option<Zval> {
    let found = |options: &Zval| match engine::array_get(options, &Key::of(key)) {
        Some(value) if !value.is_null() => Some(value.shallow_clone()),
        _ => None,
    };
    if let Some(slot) = options_slot(this) {
        return Some(found(slot).unwrap_or(default));
    }
    let options = options(this)?;
    Some(found(&options).unwrap_or(default))
}

/// `$this->setOption($key, $value)` with a literal `key`, on an object of `class` or a subclass:
/// the key interned, as PHP's literals are.
pub fn set_literal_option(this: &mut ZendObject, class: &ClassEntry, key: &Literal, value: Zval) -> Option<()> {
    if exact(this, class) {
        return match key.zend_str() {
            Some(name) => store_name(this, name, value),
            None => store(this, key.text().as_bytes(), value),
        };
    }
    engine::call_own_method(this, "setOption", &[&key.zval(), &value]).map(drop)
}

/// `$this->getOption($key)` on an object of `class` or a subclass.
pub fn option_of(this: &mut ZendObject, class: &ClassEntry, key: &[u8]) -> Option<Zval> {
    if exact(this, class) {
        return lookup(this, key, Zval::new());
    }
    engine::call_own_method(this, "getOption", &[&string(key)])
}

/// `$this->getOptions()` on an object of `class` or a subclass.
pub fn options_of(this: &mut ZendObject, class: &ClassEntry) -> Option<Zval> {
    if exact(this, class) {
        return options(this);
    }
    engine::call_own_method(this, "getOptions", &[])
}

/// `$this->getName()` on an object of `class` (named `name`) or a subclass.
pub fn name_of(this: &mut ZendObject, class: &ClassEntry, name: &Literal) -> Option<Zval> {
    if exact(this, class) {
        return Some(name.zval());
    }
    engine::call_own_method(this, "getName", &[])
}

/// `$this` as the method's return value (the setters' `static`).
pub fn return_this(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(this) = this(ex) {
        retval.set_object(this);
    }
}

/// The method's arguments, `None` with the `ArgumentCountError` of a call with fewer than
/// `required` thrown (`function` names the method in it).
pub fn args<'a>(ex: &mut ExecuteData, function: &str, required: usize, total: usize) -> Option<Vec<&'a mut Zval>> {
    let passed = engine::args_count(ex);
    if passed < required {
        too_few(ex, function, passed, required, total);
        return None;
    }
    Some((0..passed.min(total)).filter_map(|i| engine::arg(ex, i)).collect())
}

// The base's methods ----------------------------------------------------------------

extern "C" fn set_option(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(mut args) = args(ex, "Utopia\\Auth\\Hash::setOption", 2, 2) else { return };
    let (first, rest) = args.split_at_mut(1);
    let Some(key) = engine::str_arg(ex, 1, first[0]) else { return };
    let value = rest[0].dereference().shallow_clone();
    let Some(this) = this(ex) else { return };
    if store(this, &key, value).is_some() {
        return_this(ex, retval);
    }
}

extern "C" fn set_options(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = args(ex, "Utopia\\Auth\\Hash::setOptions", 1, 1) else { return };
    let Some(list) = engine::array_arg(ex, 1, args[0]) else { return };
    let Some(this) = this(ex) else { return };
    // `foreach ($options as $key => $value) { $this->setOption($key, $value); }`.
    if exact(this, base_class()) || !overrides_set_option(this) {
        // No PHP code runs in the loop, so it reads the passed array in place.
        for (key, value) in list.array().into_iter().flat_map(engine::keyed_values) {
            let KeyRef::Name(name) = key else {
                // `Hash.php` declares `strict_types`: an integer key is a `TypeError` for the
                // `string $key` of `setOption()` (PHP adds where `Hash.php` made the call).
                fail(
                    "TypeError",
                    "Utopia\\Auth\\Hash::setOption(): Argument #1 ($key) must be of type string, int given",
                );
                return;
            };
            if store_name(this, name, value.dereference().shallow_clone()).is_none() {
                return;
            }
        }
        return return_this(ex, retval);
    }
    // A subclass's own `setOption()` runs PHP code, so the loop goes over the array as it was
    // passed, copied first.
    let entries = list.array().map(engine::entries).unwrap_or_default();
    for (key, value) in entries {
        match key {
            Key::Name(name) => {
                if engine::call_own_method(this, "setOption", &[&string(&name), &value]).is_none() {
                    return;
                }
            }
            Key::Index(i) => {
                if let Some(error) = strict_key_error(this) {
                    fail("TypeError", &error);
                    return;
                }
                let mut index = Zval::new();
                index.set_long(i);
                if engine::call_own_method(this, "setOption", &[&index, &value]).is_none() {
                    return;
                }
            }
        }
    }
    return_this(ex, retval);
}

/// Whether `this`'s class declares its own `setOption()` (a subclass's override).
fn overrides_set_option(this: &ZendObject) -> bool {
    // SAFETY: the class and its function table live as long as the object.
    unsafe {
        let name = "setoption";
        let function = ext_php_rs::ffi::zend_hash_str_find_ptr_lc(
            &raw const (*this.ce).function_table,
            name.as_ptr().cast(),
            name.len(),
        )
        .cast::<ext_php_rs::ffi::zend_function>();
        function.as_ref().is_some_and(|f| !std::ptr::eq(f.common.scope, base_class()))
    }
}

/// `_ZEND_TYPE_LIST_BIT`: a union or intersection of class types.
const TYPE_LIST_BIT: u32 = 1 << 22;

unsafe extern "C" {
    fn zend_type_to_string(type_: ext_php_rs::ffi::zend_type) -> *mut ext_php_rs::ffi::zend_string;
}

/// The `TypeError` of `Hash.php`'s call (under `strict_types`) of `this`'s own `setOption()`
/// with an integer key, unless its first parameter takes one (untyped, or typed with `int` or
/// `float`). PHP adds where the call was made.
fn strict_key_error(this: &ZendObject) -> Option<String> {
    // SAFETY: the class's function table, the user function found in it and its argument
    // info live as long as the object; a user function's argument names are strings.
    unsafe {
        let name = "setoption";
        let function = ext_php_rs::ffi::zend_hash_str_find_ptr_lc(
            &raw const (*this.ce).function_table,
            name.as_ptr().cast(),
            name.len(),
        )
        .cast::<ext_php_rs::ffi::zend_function>()
        .as_ref()?;
        let common = &function.common;
        let arg = common.arg_info.as_ref().filter(|_| common.num_args > 0)?;
        let mask = arg.type_.type_mask;
        let named = mask & (ext_php_rs::ffi::_ZEND_TYPE_NAME_BIT | TYPE_LIST_BIT) != 0;
        let accepts = engine::MAY_BE_LONG | engine::MAY_BE_DOUBLE;
        if (mask & MAY_BE_ANY == 0 && !named) || mask & accepts != 0 {
            return None;
        }
        let text = |s: *mut ext_php_rs::ffi::zend_string| {
            s.cast::<ext_php_rs::types::ZendStr>()
                .as_ref()
                .map(|s| String::from_utf8_lossy(s.as_bytes()).into_owned())
                .unwrap_or_default()
        };
        let class = common.scope.as_ref().map(|scope| text(scope.name)).unwrap_or_default();
        let method = text(common.function_name);
        let parameter = text(arg.name.cast());
        let expected = zend_type_to_string(arg.type_);
        let message =
            format!("{class}::{method}(): Argument #1 (${parameter}) must be of type {}, int given", text(expected));
        ext_php_rs::ffi::ext_php_rs_zend_string_release(expected);
        Some(message)
    }
}

extern "C" fn get_option(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = args(ex, "Utopia\\Auth\\Hash::getOption", 1, 2) else { return };
    let mut args = args.into_iter();
    let Some(key) = args.next().and_then(|arg| engine::str_arg(ex, 1, arg)) else { return };
    let default = args.next().map_or_else(Zval::new, |arg| arg.dereference().shallow_clone());
    let Some(this) = this(ex) else { return };
    if let Some(value) = lookup(this, &key, default) {
        *retval = value;
    }
}

extern "C" fn get_options(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    if let Some(value) = options(this) {
        *retval = value;
    }
}
