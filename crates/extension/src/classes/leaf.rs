//! What every hand-built class shares: its methods' description ([`Method`]),
//! building and registering the class ([`build`], [`patch`]), and the small pieces each
//! method's handler needs (its `$this`, its `$value`, the return value).

use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::args::{Arg, ArgInfoTables};
use ext_php_rs::builders::{ClassBuilder, FunctionBuilder};
use ext_php_rs::flags::{DataType, MethodFlags};
use ext_php_rs::types::{ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData};

use super::engine::{
    self, MAY_BE_ANY, MAY_BE_ARRAY, MAY_BE_BOOL, MAY_BE_DOUBLE, MAY_BE_LONG, MAY_BE_STRING, string, too_few,
};

/// A method's handler (ext-php-rs's `FunctionHandler`, which it does not export).
pub type FunctionHandler = extern "C" fn(&mut ExecuteData, &mut Zval);

/// A method's parameter: its name, type mask (0: untyped) and default, as PHP source.
pub type Param = (&'static str, u32, Option<&'static str>);

/// A method of a hand-built class: its handler, flags, parameters and return type mask.
pub struct Method {
    pub name: &'static str,
    pub handler: FunctionHandler,
    pub flags: MethodFlags,
    pub params: &'static [Param],
    pub returns: u32,
}

pub const PUBLIC: MethodFlags = MethodFlags::Public;
pub const PROTECTED: MethodFlags = MethodFlags::Protected;
pub const PRIVATE: MethodFlags = MethodFlags::Private;

/// `$value`, typed `mixed`.
pub const VALUE_MIXED: &[Param] = &[("value", MAY_BE_ANY, None)];
/// `$value`, untyped.
pub const VALUE: &[Param] = &[("value", 0, None)];

/// `int|float`.
pub const NUMBER: u32 = MAY_BE_LONG | MAY_BE_DOUBLE;

/// The class type `Utopia\Validator\Validator`, in place of a mask: not a type bit, so
/// [`patch`] leaves the class type the builder declared as it is.
pub const VALIDATOR: u32 = 1 << 30;

/// The type ext-php-rs builds a parameter or return with, before [`patch`] sets the exact
/// one: what the inheritance checks at registration see, so it must be compatible with the
/// parent's (`int` stands in for `int|float`, `mixed` for untyped).
fn data_type(mask: u32) -> DataType {
    match mask {
        VALIDATOR => DataType::object(crate::validators::BASE),
        MAY_BE_BOOL => DataType::Bool,
        MAY_BE_LONG | NUMBER => DataType::Long,
        MAY_BE_STRING => DataType::String,
        MAY_BE_ARRAY => DataType::Array,
        _ => DataType::Mixed,
    }
}

/// Builds `name` (extending `parent`) with `methods`; `register` runs once the engine has
/// the class, to declare its properties and [`patch`] the methods' types.
pub fn build(
    name: &str,
    parent: (fn() -> &'static ClassEntry, &'static str),
    methods: &[Method],
    register: fn(&'static mut ClassEntry, ArgInfoTables),
) -> ext_php_rs::error::Result<()> {
    class(name, methods).extends(parent).registration(register).register()
}

/// The class `name` with `methods`, to register as [`build`] does (or with a parent, flags
/// or abstract methods of its own).
pub fn class(name: &str, methods: &[Method]) -> ClassBuilder {
    let mut class = ClassBuilder::new(name);
    for method in methods {
        let mut function = FunctionBuilder::new(method.name, method.handler);
        let required = method.params.iter().take_while(|(_, _, default)| default.is_none()).count();
        for (param, mask, default) in method.params {
            let arg = Arg::new(*param, data_type(*mask));
            function = function.arg(match default {
                Some(default) => arg.default(*default),
                None => arg,
            });
        }
        let function = function.required_args(required);
        // A constructor declares no return type.
        let function = if method.name == "__construct" {
            function
        } else {
            function.returns(data_type(method.returns), false, false)
        };
        class = class.method(function, method.flags);
    }
    class
}

/// Sets the exact types of `methods`' parameters and returns on `class` (the `int|float`
/// unions, the untyped `$value`s), marks the constructor's parameters other than `unpromoted`
/// as the ones that declare a property, and keeps the built tables alive: the engine points
/// at them for the methods it did not copy.
pub fn patch(class: &ClassEntry, methods: &[Method], tables: ArgInfoTables, unpromoted: &[&str]) {
    for method in methods {
        let Some(info) = engine::arg_info(class, method.name) else { continue };
        let (returns, params) = info.split_at_mut(1);
        if method.name != "__construct" && method.returns != VALIDATOR {
            engine::set_type(&mut returns[0], method.returns);
        }
        for ((name, mask, _), param) in method.params.iter().zip(params.iter_mut()) {
            if *mask != VALIDATOR {
                engine::set_type(param, *mask);
            }
            if method.name == "__construct" && !unpromoted.contains(name) {
                param.type_.type_mask |= engine::IS_PROMOTED;
            }
        }
    }
    Box::leak(tables);
}

/// The class entry stored in `slot` at registration.
pub fn stored(slot: &AtomicPtr<ClassEntry>) -> &'static ClassEntry {
    // SAFETY: set once, in MINIT, to a class entry the engine keeps for the process.
    unsafe { slot.load(Ordering::Acquire).as_ref().expect("class registered in MINIT") }
}

/// The method's `$this` (the hand-built classes' methods are never static).
pub fn this(ex: &mut ExecuteData) -> Option<&'static mut ZendObject> {
    // SAFETY: the object outlives the call.
    ex.get_self().map(|object| unsafe { &mut *std::ptr::from_mut(object) })
}

/// `isValid()`'s one argument, or the `ArgumentCountError` of a call without it.
pub fn value_arg(ex: &mut ExecuteData, function: &str) -> Option<&'static mut Zval> {
    let arg = engine::arg(ex, 0);
    if arg.is_none() {
        too_few(ex, function, 0, 1, 1);
    }
    arg
}

pub fn set_bool(retval: &mut Zval, value: bool) {
    retval.set_bool(value);
}

pub fn set_str(retval: &mut Zval, value: &[u8]) {
    *retval = string(value);
}

// The constant answers most classes give.
pub extern "C" fn is_array(_: &mut ExecuteData, retval: &mut Zval) {
    set_bool(retval, false);
}
pub extern "C" fn type_string(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"string");
}
