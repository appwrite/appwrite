//! The scalar leaf validators of `packages/validators`: `Boolean`, `Integer`,
//! `FloatValidator`, `Numeric`, `Range` (a `Numeric`), `Port`, `HexColor` and `Wildcard`,
//! over [`utopia_validators`], behind `validators-scalar` (and `validators`, whose base
//! they extend).
//!
//! Each is built by hand, like the base: no Rust object, and the state its PHP
//! constructor keeps in real typed protected properties, declared as `packages/validators`
//! declares them. So a subclass reads and writes them, and `var_dump()`, `(array)`, `==`,
//! `clone` and `serialize()` see what they see in PHP. Methods read the properties on
//! every call, as PHP does, and a class without a constructor in PHP has none here.

use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::args::{Arg, ArgInfoTables};
use ext_php_rs::builders::{ClassBuilder, FunctionBuilder};
use ext_php_rs::exception::throw;
use ext_php_rs::flags::{DataType, MethodFlags};
use ext_php_rs::types::{ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData};
use utopia_validators::scalar::Integer as IntegerCheck;

use super::engine::{
    self, MAY_BE_ANY, MAY_BE_BOOL, MAY_BE_DOUBLE, MAY_BE_LONG, MAY_BE_STRING, Property, bool_arg, get, input, long_arg,
    number_arg, set, str_arg, string, too_few,
};

/// A method's handler (ext-php-rs's `FunctionHandler`, which it does not export).
type FunctionHandler = extern "C" fn(&mut ExecuteData, &mut Zval);

/// A method of a hand-built class: its handler, flags, parameters (name, type mask, default)
/// and return type mask. A parameter mask of 0 is untyped, as `isValid($value)` is in PHP.
struct Method {
    name: &'static str,
    handler: FunctionHandler,
    flags: MethodFlags,
    params: &'static [(&'static str, u32, Option<&'static str>)],
    returns: u32,
}

const PUBLIC: MethodFlags = MethodFlags::Public;
const PRIVATE: MethodFlags = MethodFlags::Private;

/// `$value`, typed `mixed` (`Integer`, `FloatValidator`, `Numeric`, `Range`, `HexColor`).
const VALUE_MIXED: &[(&str, u32, Option<&str>)] = &[("value", MAY_BE_ANY, None)];
/// `$value`, untyped (`Boolean`, `Port`, `Wildcard`).
const VALUE: &[(&str, u32, Option<&str>)] = &[("value", 0, None)];

/// The type ext-php-rs builds a parameter or return with, before [`patch`] sets the exact
/// one: what the inheritance checks at registration see, so it must be compatible with the
/// parent's (`int` stands in for `int|float`, `mixed` for untyped).
fn data_type(mask: u32) -> DataType {
    match mask {
        MAY_BE_BOOL => DataType::Bool,
        MAY_BE_LONG | NUMBER => DataType::Long,
        MAY_BE_STRING => DataType::String,
        _ => DataType::Mixed,
    }
}

/// Builds `name` (extending `parent`) with `methods`; `register` runs once the engine has
/// the class, to declare its properties and [`patch`] the methods' types.
fn build(
    name: &str,
    parent: (fn() -> &'static ClassEntry, &'static str),
    methods: &[Method],
    register: fn(&'static mut ClassEntry, ArgInfoTables),
) -> ext_php_rs::error::Result<()> {
    let mut class = ClassBuilder::new(name).extends(parent);
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
    class.registration(register).register()
}

/// Sets the exact types of `methods`' parameters and returns on `class` (the `int|float`
/// unions, the untyped `$value`s), and keeps the built tables alive: the engine points at
/// them for the methods it did not copy.
fn patch(class: &ClassEntry, methods: &[Method], tables: ArgInfoTables) {
    for method in methods {
        let Some(info) = engine::arg_info(class, method.name) else { continue };
        let (returns, params) = info.split_at_mut(1);
        if method.name != "__construct" {
            engine::set_type(&mut returns[0], method.returns);
        }
        for ((name, mask, _), param) in method.params.iter().zip(params.iter_mut()) {
            engine::set_type(param, *mask);
            // The constructor parameters that declare a property (every one here bar
            // `Integer`'s `$bits` and `$unsigned`, which are declared on their own).
            if method.name == "__construct" && !matches!(*name, "bits" | "unsigned") {
                param.type_.type_mask |= engine::IS_PROMOTED;
            }
        }
    }
    Box::leak(tables);
}

/// The class entry stored in `slot` at registration.
fn stored(slot: &AtomicPtr<ClassEntry>) -> &'static ClassEntry {
    // SAFETY: set once, in MINIT, to a class entry the engine keeps for the process.
    unsafe { slot.load(Ordering::Acquire).as_ref().expect("validator class registered") }
}

/// The method's `$this` (a validator's methods are never static).
fn this(ex: &mut ExecuteData) -> Option<&'static mut ZendObject> {
    // SAFETY: the object outlives the call.
    ex.get_self().map(|object| unsafe { &mut *std::ptr::from_mut(object) })
}

/// `isValid()`'s one argument, or the `ArgumentCountError` of a call without it.
fn value_arg(ex: &mut ExecuteData, function: &str) -> Option<&'static mut Zval> {
    let arg = engine::arg(ex, 0);
    if arg.is_none() {
        too_few(ex, function, 0, 1, 1);
    }
    arg
}

fn set_bool(retval: &mut Zval, value: bool) {
    retval.set_bool(value);
}

fn set_str(retval: &mut Zval, value: &[u8]) {
    *retval = string(value);
}

// The constant answers every class here gives.
extern "C" fn is_array(_: &mut ExecuteData, retval: &mut Zval) {
    set_bool(retval, false);
}
extern "C" fn type_string(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"string");
}

// Boolean -------------------------------------------------------------------------------

static BOOLEAN: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static BOOLEAN_LOOSE: Property = Property::new("loose");

const BOOLEAN_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: boolean_construct,
        flags: PUBLIC,
        params: &[("loose", MAY_BE_BOOL, Some("false"))],
        returns: 0,
    },
    Method { name: "getDescription", handler: boolean_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: boolean_type, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: boolean_is_valid, flags: PUBLIC, params: VALUE, returns: MAY_BE_BOOL },
];

fn boolean_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare(class, &BOOLEAN_LOOSE, MAY_BE_BOOL, None);
    BOOLEAN.store(class, Ordering::Release);
    patch(class, BOOLEAN_METHODS, tables);
}

/// `__construct(protected bool $loose = false)`, shared by `Boolean` and `FloatValidator`.
fn loose_construct(ex: &mut ExecuteData, scope: &ClassEntry, property: &Property) {
    let loose = match engine::arg(ex, 0) {
        Some(arg) => match bool_arg(ex, 1, arg) {
            Some(loose) => loose,
            None => return,
        },
        None => false,
    };
    if let Some(this) = this(ex) {
        let mut value = Zval::new();
        value.set_bool(loose);
        set(scope, this, property, value);
    }
}

/// `$this->loose` of `scope`, `None` with the exception pending when it cannot be read.
fn loose(ex: &mut ExecuteData, scope: &ClassEntry, property: &Property) -> Option<bool> {
    get(scope, this(ex)?, property).map(|value| engine::truthy(&value))
}

extern "C" fn boolean_construct(ex: &mut ExecuteData, _: &mut Zval) {
    loose_construct(ex, stored(&BOOLEAN), &BOOLEAN_LOOSE);
}
extern "C" fn boolean_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"Value must be a valid boolean");
}
extern "C" fn boolean_type(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"boolean");
}
extern "C" fn boolean_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Boolean::isValid") else { return };
    // `$this->loose && ...`: PHP reads the property first, for every value.
    let Some(loose) = loose(ex, stored(&BOOLEAN), &BOOLEAN_LOOSE) else { return };
    set_bool(retval, utopia_validators::boolean(loose, input(value)));
}

// FloatValidator ------------------------------------------------------------------------

static FLOAT: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static FLOAT_LOOSE: Property = Property::new("loose");

const FLOAT_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: float_construct,
        flags: PUBLIC,
        params: &[("loose", MAY_BE_BOOL, Some("false"))],
        returns: 0,
    },
    Method { name: "getDescription", handler: float_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: float_type, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: float_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn float_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare(class, &FLOAT_LOOSE, MAY_BE_BOOL, None);
    FLOAT.store(class, Ordering::Release);
    patch(class, FLOAT_METHODS, tables);
}

extern "C" fn float_construct(ex: &mut ExecuteData, _: &mut Zval) {
    loose_construct(ex, stored(&FLOAT), &FLOAT_LOOSE);
}
extern "C" fn float_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"Value must be a valid float");
}
extern "C" fn float_type(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"double");
}
extern "C" fn float_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\FloatValidator::isValid") else { return };
    let Some(loose) = loose(ex, stored(&FLOAT), &FLOAT_LOOSE) else { return };
    set_bool(retval, utopia_validators::float(loose, input(value)));
}

// Integer -------------------------------------------------------------------------------

static INTEGER: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static INTEGER_BITS: Property = Property::new("bits");
static INTEGER_UNSIGNED: Property = Property::new("unsigned");
static INTEGER_LOOSE: Property = Property::new("loose");

const INTEGER_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: integer_construct,
        flags: PUBLIC,
        params: &[
            ("loose", MAY_BE_BOOL, Some("false")),
            ("bits", MAY_BE_LONG, Some("32")),
            ("unsigned", MAY_BE_BOOL, Some("false")),
        ],
        returns: 0,
    },
    Method { name: "getDescription", handler: integer_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: integer_type, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "getBits", handler: integer_bits, flags: PUBLIC, params: &[], returns: MAY_BE_LONG },
    Method { name: "isUnsigned", handler: integer_unsigned, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getFormat", handler: integer_format, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "getMin", handler: integer_min, flags: PRIVATE, params: &[], returns: MAY_BE_LONG },
    Method { name: "getMax", handler: integer_max, flags: PRIVATE, params: &[], returns: MAY_BE_LONG },
    Method { name: "isValid", handler: integer_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn integer_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    // `Integer.php`'s order: the declared `$bits` and `$unsigned`, then the promoted `$loose`.
    let mut bits = Zval::new();
    bits.set_long(32);
    let mut unsigned = Zval::new();
    unsigned.set_bool(false);
    engine::declare(class, &INTEGER_BITS, MAY_BE_LONG, Some(bits));
    engine::declare(class, &INTEGER_UNSIGNED, MAY_BE_BOOL, Some(unsigned));
    engine::declare(class, &INTEGER_LOOSE, MAY_BE_BOOL, None);
    INTEGER.store(class, Ordering::Release);
    patch(class, INTEGER_METHODS, tables);
}

extern "C" fn integer_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let scope = stored(&INTEGER);
    let mut parsed = [None, None, None];
    for (i, slot) in parsed.iter_mut().enumerate() {
        let Some(arg) = engine::arg(ex, i) else { break };
        let num = u32::try_from(i + 1).unwrap_or(u32::MAX);
        let value = if i == 1 {
            long_arg(ex, num, arg).map(|bits| (0, bits))
        } else {
            bool_arg(ex, num, arg).map(|b| (u8::from(b), 0))
        };
        match value {
            Some(value) => *slot = Some(value),
            None => return,
        }
    }
    let loose = parsed[0].is_some_and(|(b, _)| b == 1);
    let bits = parsed[1].map_or(32, |(_, bits)| bits);
    let unsigned = parsed[2].is_some_and(|(b, _)| b == 1);
    let Some(this) = this(ex) else { return };
    let mut value = Zval::new();
    value.set_bool(loose);
    set(scope, this, &INTEGER_LOOSE, value);
    if let Err(error) = IntegerCheck::new(bits, unsigned) {
        let class = ClassEntry::try_find("InvalidArgumentException").unwrap_or_else(ext_php_rs::zend::ce::exception);
        let _ = throw(class, error.message());
        return;
    }
    let mut value = Zval::new();
    value.set_long(bits);
    set(scope, this, &INTEGER_BITS, value);
    let mut value = Zval::new();
    value.set_bool(unsigned);
    set(scope, this, &INTEGER_UNSIGNED, value);
}

/// `$this->bits` and `$this->unsigned`.
fn integer_state(ex: &mut ExecuteData) -> Option<IntegerCheck> {
    let scope = stored(&INTEGER);
    let this = this(ex)?;
    let bits = get(scope, this, &INTEGER_BITS)?.long().unwrap_or(0);
    let unsigned = engine::truthy(&get(scope, this, &INTEGER_UNSIGNED)?);
    Some(IntegerCheck { bits, unsigned })
}

extern "C" fn integer_description(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(state) = integer_state(ex) {
        set_str(retval, &state.description());
    }
}
extern "C" fn integer_type(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"integer");
}
extern "C" fn integer_bits(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(state) = integer_state(ex) {
        retval.set_long(state.bits);
    }
}
extern "C" fn integer_unsigned(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(state) = integer_state(ex) {
        set_bool(retval, state.unsigned);
    }
}
extern "C" fn integer_format(ex: &mut ExecuteData, retval: &mut Zval) {
    // `$this->isUnsigned()`: a subclass may override it.
    let Some(this) = this(ex) else { return };
    let Ok(unsigned) = this.try_call_method("isUnsigned", vec![]) else { return };
    if ext_php_rs::zend::ExecutorGlobals::has_exception() {
        return;
    }
    let Some(bits) = get(stored(&INTEGER), this, &INTEGER_BITS) else { return };
    set_str(retval, IntegerCheck::format(bits.long().unwrap_or(0), engine::truthy(&unsigned)).as_bytes());
}
extern "C" fn integer_min(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(state) = integer_state(ex) {
        retval.set_long(state.min());
    }
}
extern "C" fn integer_max(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(state) = integer_state(ex) {
        retval.set_long(state.max());
    }
}
extern "C" fn integer_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Integer::isValid") else { return };
    let Some(loose) = loose(ex, stored(&INTEGER), &INTEGER_LOOSE) else { return };
    let Some(state) = integer_state(ex) else { return };
    set_bool(retval, state.accepts(loose, input(value)));
}

// Numeric and Range ---------------------------------------------------------------------

static NUMERIC: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static RANGE: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static RANGE_MIN: Property = Property::new("min");
static RANGE_MAX: Property = Property::new("max");
static RANGE_FORMAT: Property = Property::new("format");

const NUMERIC_METHODS: &[Method] = &[
    Method { name: "getDescription", handler: numeric_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: numeric_type, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: numeric_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn numeric_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    NUMERIC.store(class, Ordering::Release);
    patch(class, NUMERIC_METHODS, tables);
}

fn numeric_class() -> &'static ClassEntry {
    stored(&NUMERIC)
}

extern "C" fn numeric_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"Value must be a valid number");
}
extern "C" fn numeric_type(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"mixed");
}
extern "C" fn numeric_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Numeric::isValid") else { return };
    set_bool(retval, utopia_validators::numeric(input(value)));
}

const NUMBER: u32 = MAY_BE_LONG | MAY_BE_DOUBLE;

const RANGE_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: range_construct,
        flags: PUBLIC,
        params: &[("min", NUMBER, None), ("max", NUMBER, None), ("format", MAY_BE_STRING, Some("self::TYPE_INTEGER"))],
        returns: 0,
    },
    Method { name: "getMin", handler: range_min, flags: PUBLIC, params: &[], returns: NUMBER },
    Method { name: "getMax", handler: range_max, flags: PUBLIC, params: &[], returns: NUMBER },
    Method { name: "getFormat", handler: range_format, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "getDescription", handler: range_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: range_format, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: range_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn range_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare(class, &RANGE_MIN, NUMBER, None);
    engine::declare(class, &RANGE_MAX, NUMBER, None);
    engine::declare(class, &RANGE_FORMAT, MAY_BE_STRING, None);
    RANGE.store(class, Ordering::Release);
    patch(class, RANGE_METHODS, tables);
}

extern "C" fn range_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let scope = stored(&RANGE);
    let passed = engine::args_count(ex);
    let (Some(min), Some(max)) = (engine::arg(ex, 0), engine::arg(ex, 1)) else {
        too_few(ex, "Utopia\\Validator\\Range::__construct", passed, 2, 3);
        return;
    };
    let Some(min) = number_arg(ex, 1, min) else { return };
    let Some(max) = number_arg(ex, 2, max) else { return };
    let format = match engine::arg(ex, 2) {
        Some(arg) => match str_arg(ex, 3, arg) {
            Some(format) => format,
            None => return,
        },
        None => b"integer".to_vec(),
    };
    let Some(this) = this(ex) else { return };
    set(scope, this, &RANGE_MIN, min);
    set(scope, this, &RANGE_MAX, max);
    set(scope, this, &RANGE_FORMAT, string(&format));
}

fn range_property(ex: &mut ExecuteData, retval: &mut Zval, property: &Property) {
    if let Some(this) = this(ex)
        && let Some(value) = get(stored(&RANGE), this, property)
    {
        *retval = value;
    }
}

extern "C" fn range_min(ex: &mut ExecuteData, retval: &mut Zval) {
    range_property(ex, retval, &RANGE_MIN);
}
extern "C" fn range_max(ex: &mut ExecuteData, retval: &mut Zval) {
    range_property(ex, retval, &RANGE_MAX);
}
extern "C" fn range_format(ex: &mut ExecuteData, retval: &mut Zval) {
    range_property(ex, retval, &RANGE_FORMAT);
}

extern "C" fn range_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let scope = stored(&RANGE);
    let Some(this) = this(ex) else { return };
    let Some(min) = get(scope, this, &RANGE_MIN) else { return };
    let Some(max) = get(scope, this, &RANGE_MAX) else { return };
    set_str(retval, &utopia_validators::range_description(engine::number(&min), engine::number(&max)));
}
extern "C" fn range_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Range::isValid") else { return };
    // `parent::isValid()` first: the properties are read only for a number, `$this->format`
    // first, then `$this->min`, and `$this->max` only when the value is not below it.
    if !utopia_validators::numeric(input(value)) {
        set_bool(retval, false);
        return;
    }
    let scope = stored(&RANGE);
    let Some(this) = this(ex) else { return };
    let Some(format) = get(scope, this, &RANGE_FORMAT) else { return };
    let format = format.zend_str().map(|s| s.as_bytes().to_vec()).unwrap_or_default();
    let Some(number) = utopia_validators::range_number(&format, input(value)) else {
        set_bool(retval, false);
        return;
    };
    let Some(min) = get(scope, this, &RANGE_MIN) else { return };
    if !utopia_validators::number_le(engine::number(&min), number) {
        set_bool(retval, false);
        return;
    }
    let Some(max) = get(scope, this, &RANGE_MAX) else { return };
    set_bool(retval, utopia_validators::number_le(number, engine::number(&max)));
}

// Port, HexColor and Wildcard -----------------------------------------------------------

const PORT_METHODS: &[Method] = &[
    Method { name: "getDescription", handler: port_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: port_is_valid, flags: PUBLIC, params: VALUE, returns: MAY_BE_BOOL },
    Method {
        name: "isValidHost",
        handler: port_is_valid_host,
        flags: PRIVATE,
        params: &[("host", MAY_BE_STRING, None)],
        returns: MAY_BE_BOOL,
    },
    Method {
        name: "isValidPort",
        handler: port_is_valid_port,
        flags: PRIVATE,
        params: &[("port", MAY_BE_STRING, None)],
        returns: MAY_BE_BOOL,
    },
];

fn port_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    patch(class, PORT_METHODS, tables);
}

extern "C" fn port_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(
        retval,
        b"Value must be a port between 1 and 65535, optionally prefixed by a host IP address (e.g. 127.0.0.1:80)",
    );
}
extern "C" fn port_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Port::isValid") else { return };
    set_bool(retval, utopia_validators::port(input(value)));
}

/// A private method's one `string` argument.
fn private_str(ex: &mut ExecuteData, function: &str) -> Option<Vec<u8>> {
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, function, 0, 1, 1);
        return None;
    };
    str_arg(ex, 1, arg)
}

extern "C" fn port_is_valid_host(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(host) = private_str(ex, "Utopia\\Validator\\Port::isValidHost") {
        set_bool(retval, utopia_validators::port_host(&host));
    }
}
extern "C" fn port_is_valid_port(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(port) = private_str(ex, "Utopia\\Validator\\Port::isValidPort") {
        set_bool(retval, utopia_validators::port_number(&port));
    }
}

const HEX_COLOR_METHODS: &[Method] = &[
    Method {
        name: "getDescription",
        handler: hex_color_description,
        flags: PUBLIC,
        params: &[],
        returns: MAY_BE_STRING,
    },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: hex_color_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn hex_color_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    patch(class, HEX_COLOR_METHODS, tables);
}

extern "C" fn hex_color_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"Value must be a valid Hex color code");
}
extern "C" fn hex_color_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\HexColor::isValid") else { return };
    set_bool(retval, utopia_validators::hex_color(input(value)));
}

const WILDCARD_METHODS: &[Method] = &[
    Method {
        name: "getDescription",
        handler: wildcard_description,
        flags: PUBLIC,
        params: &[],
        returns: MAY_BE_STRING,
    },
    Method { name: "isValid", handler: wildcard_is_valid, flags: PUBLIC, params: VALUE, returns: MAY_BE_BOOL },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
];

fn wildcard_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    patch(class, WILDCARD_METHODS, tables);
}

extern "C" fn wildcard_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"Every input is valid");
}
extern "C" fn wildcard_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    if value_arg(ex, "Utopia\\Validator\\Wildcard::isValid").is_some() {
        set_bool(retval, true);
    }
}

/// Registers the batch's classes. Called from the module's startup (MINIT), after the base.
pub fn register() -> ext_php_rs::error::Result<()> {
    let base = (super::base_class as fn() -> &'static ClassEntry, super::BASE);
    build("Utopia\\Validator\\Boolean", base, BOOLEAN_METHODS, boolean_register)?;
    build("Utopia\\Validator\\Integer", base, INTEGER_METHODS, integer_register)?;
    build("Utopia\\Validator\\FloatValidator", base, FLOAT_METHODS, float_register)?;
    build("Utopia\\Validator\\Numeric", base, NUMERIC_METHODS, numeric_register)?;
    build("Utopia\\Validator\\Range", (numeric_class, "Utopia\\Validator\\Numeric"), RANGE_METHODS, range_register)?;
    build("Utopia\\Validator\\Port", base, PORT_METHODS, port_register)?;
    build("Utopia\\Validator\\HexColor", base, HEX_COLOR_METHODS, hex_color_register)?;
    build("Utopia\\Validator\\Wildcard", base, WILDCARD_METHODS, wildcard_register)
}
