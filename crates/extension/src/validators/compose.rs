//! The composing validators of `packages/validators`: `Nullable`, `ArrayList`, `AllOf`,
//! `AnyOf`, `NoneOf`, `Multiple` and `Assoc`, the JSON validators (`JSON`,
//! `JSON\ArrayValidator`, `JSON\ObjectValidator`, `JSON\FCM`) and `Globstar`.
//!
//! What these classes mostly do is ask other validators, which may be PHP classes (Appwrite's,
//! utopia-php/database's, a subclass) or the extension's. They ask
//! through the engine ([`engine::call_method`]), as the PHP classes' `$rule->isValid($value)`
//! does: the same method resolution (a subclass's override, `__call()`), the same calls in the
//! same order and number, the same short-circuits, the same exceptions passing through. The
//! PHP functions whose state is observable (`json_decode()` and `json_last_error()`,
//! `json_encode()`, `preg_match()` and its warnings, `filter_var()`) are called through the
//! engine too, so they leave that state as PHP does.

use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::args::ArgInfoTables;
use ext_php_rs::exception::throw;
use ext_php_rs::flags::ConstantFlags;
use ext_php_rs::types::{ArrayKey, ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData, ce};

use super::engine::{
    self, Callee, Declared, KeyRef, Literal, MAY_BE_ARRAY, MAY_BE_BOOL, MAY_BE_FALSE, MAY_BE_LONG, MAY_BE_NULL,
    MAY_BE_STATIC, MAY_BE_STRING, Property, PropertyType, array_arg, call, call_method, get, long_arg, set,
    str_zval_arg, string, too_few, type_error,
};
use super::leaf::{
    self, Method, PRIVATE, PUBLIC, TYPE_ARRAY, TYPE_MIXED, TYPE_OBJECT, VALIDATOR, VALUE, VALUE_MIXED, build, is_array,
    set_bool, set_built, set_literal, stored, this, value_arg,
};

/// `FILTER_VALIDATE_URL`.
const FILTER_VALIDATE_URL: i64 = 273;
/// `FILTER_VALIDATE_EMAIL`.
const FILTER_VALIDATE_EMAIL: i64 = 274;
/// `PHP_URL_SCHEME`.
const PHP_URL_SCHEME: i64 = 0;

/// The descriptions the classes return as they are.
static ASSOC_DESCRIPTION: Literal = Literal::new(utopia_validators::ASSOC_DESCRIPTION);
static JSON_DESCRIPTION: Literal = Literal::new(utopia_validators::JSON_DESCRIPTION);
static GLOBSTAR_DESCRIPTION: Literal = Literal::new(utopia_validators::GLOBSTAR_DESCRIPTION);
static FCM_DESCRIPTION: Literal = Literal::new(utopia_validators::FCM_DESCRIPTION);

/// The base's constants, which a class lists after its own.
const INHERITED: [&str; 7] =
    ["TYPE_BOOLEAN", "TYPE_INTEGER", "TYPE_FLOAT", "TYPE_STRING", "TYPE_ARRAY", "TYPE_OBJECT", "TYPE_MIXED"];

/// A promoted `protected` property.
const PROMOTED: Declared = Declared { private: false, readonly: false, promoted: true };
/// A `protected` property declared in the class body.
const BODY: Declared = Declared { private: false, readonly: false, promoted: false };

const VALIDATOR_TYPE: PropertyType = PropertyType::Class { name: super::BASE, nullable: false };

fn long_zval(value: i64) -> Zval {
    let mut zval = Zval::new();
    zval.set_long(value);
    zval
}

fn null_zval() -> Zval {
    let mut zval = Zval::new();
    zval.set_null();
    zval
}

fn object_zval(object: &mut ZendObject) -> Zval {
    let mut zval = Zval::new();
    zval.set_object(object);
    zval
}

/// A string's bytes (after dereferencing), or `None` for any other value.
fn bytes(value: &Zval) -> Option<&[u8]> {
    value.dereference().zend_str().map(|s| s.as_bytes())
}

/// PHP `array_is_list($array)`.
fn is_list(value: &Zval) -> bool {
    value.dereference().array().is_some_and(|array| {
        array.iter().enumerate().all(|(i, (key, _))| matches!(key, ArrayKey::Long(k) if usize::try_from(k) == Ok(i)))
    })
}

/// Whether `value` is an object of `class` or a subclass (`instanceof`).
fn instance_of(value: &Zval, class: &ClassEntry) -> bool {
    value.dereference().object().is_some_and(|object| object.instance_of(class))
}

/// A `Validator` argument `num` of a userland parameter typed `Validator`, or its `TypeError`.
fn validator_arg(ex: &ExecuteData, num: u32, arg: &Zval) -> Option<Zval> {
    if instance_of(arg, super::base_class()) {
        return Some(arg.dereference().shallow_clone());
    }
    type_error(ex, num, super::BASE, arg.dereference());
    None
}

/// `$this->name` read from `scope` for `getX()` returning it: the value, or the exception.
fn return_property(ex: &mut ExecuteData, retval: &mut Zval, scope: &AtomicPtr<ClassEntry>, property: &Property) {
    let Some(this) = this(ex) else { return };
    if let Some(value) = get(stored(scope), this, property) {
        *retval = value;
    }
}

/// `$value` as `json_encode($value)` encodes it: its bytes, `None` for `false`.
fn json_encode(value: &Zval) -> Option<Option<Vec<u8>>> {
    let encoded = call("json_encode", &[value])?;
    Some(bytes(&encoded).map(<[u8]>::to_vec))
}

/// `json_decode($value)` (its value), and whether `json_last_error()` then says it decoded.
fn json_decode(value: &Zval) -> Option<(Zval, bool)> {
    let decoded = call("json_decode", &[value])?;
    let error = call("json_last_error", &[])?;
    Some((decoded, error.long() == Some(0)))
}

// Nullable ------------------------------------------------------------------------------

static NULLABLE: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static NULLABLE_VALIDATOR: Property = Property::new("validator");

const NULLABLE_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: nullable_construct,
        flags: PUBLIC,
        params: &[("validator", VALIDATOR, None)],
        returns: 0,
    },
    Method {
        name: "getDescription",
        handler: nullable_description,
        flags: PUBLIC,
        params: &[],
        returns: MAY_BE_STRING,
    },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: nullable_type, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "getValidator", handler: nullable_validator, flags: PUBLIC, params: &[], returns: VALIDATOR },
    Method { name: "isValid", handler: nullable_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn nullable_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare_property(class, &NULLABLE_VALIDATOR, VALIDATOR_TYPE, PROMOTED, None);
    NULLABLE.store(class, Ordering::Release);
    leaf::patch(class, NULLABLE_METHODS, tables, &[]);
}

extern "C" fn nullable_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let passed = engine::args_count(ex);
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\Validator\\Nullable::__construct", passed, 1, 1);
        return;
    };
    let Some(validator) = validator_arg(ex, 1, arg) else { return };
    if let Some(this) = this(ex) {
        set(stored(&NULLABLE), this, &NULLABLE_VALIDATOR, validator);
    }
}

extern "C" fn nullable_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(validator) = get(stored(&NULLABLE), this, &NULLABLE_VALIDATOR) else { return };
    let Some(description) = call_method(&validator, &GET_DESCRIPTION, &[]) else { return };
    set_built(retval, |out, _| {
        engine::append_string(&description, out)?;
        out.extend_from_slice(b" or null");
        Some(())
    });
}

extern "C" fn nullable_type(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(validator) = get(stored(&NULLABLE), this, &NULLABLE_VALIDATOR) else { return };
    let Some(kind) = call_method(&validator, &GET_TYPE, &[]) else { return };
    if let Some(kind) = engine::verify_return(ex, kind, MAY_BE_STRING, true) {
        *retval = kind;
    }
}

extern "C" fn nullable_validator(ex: &mut ExecuteData, retval: &mut Zval) {
    return_property(ex, retval, &NULLABLE, &NULLABLE_VALIDATOR);
}

extern "C" fn nullable_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Nullable::isValid") else { return };
    if value.dereference().is_null() {
        set_bool(retval, true);
        return;
    }
    let Some(this) = this(ex) else { return };
    let Some(validator) = get(stored(&NULLABLE), this, &NULLABLE_VALIDATOR) else { return };
    let Some(valid) = call_method(&validator, &IS_VALID, &[&*value]) else { return };
    if let Some(valid) = engine::verify_return(ex, valid, MAY_BE_BOOL, true) {
        *retval = valid;
    }
}

// ArrayList -----------------------------------------------------------------------------

static ARRAY_LIST: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static ARRAY_LIST_VALIDATOR: Property = Property::new("validator");
static ARRAY_LIST_LENGTH: Property = Property::new("length");

const ARRAY_LIST_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: array_list_construct,
        flags: PUBLIC,
        params: &[("validator", VALIDATOR, None), ("length", MAY_BE_LONG, Some("0"))],
        returns: 0,
    },
    Method {
        name: "getDescription",
        handler: array_list_description,
        flags: PUBLIC,
        params: &[],
        returns: MAY_BE_STRING,
    },
    Method { name: "isArray", handler: is_array_true, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: array_list_type, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "getValidator", handler: array_list_validator, flags: PUBLIC, params: &[], returns: VALIDATOR },
    Method { name: "isValid", handler: array_list_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn array_list_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare_property(class, &ARRAY_LIST_VALIDATOR, VALIDATOR_TYPE, PROMOTED, None);
    engine::declare_property(class, &ARRAY_LIST_LENGTH, PropertyType::Mask(MAY_BE_LONG), PROMOTED, None);
    ARRAY_LIST.store(class, Ordering::Release);
    leaf::patch(class, ARRAY_LIST_METHODS, tables, &[]);
}

extern "C" fn is_array_true(_: &mut ExecuteData, retval: &mut Zval) {
    set_bool(retval, true);
}

extern "C" fn array_list_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let passed = engine::args_count(ex);
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\Validator\\ArrayList::__construct", passed, 1, 2);
        return;
    };
    let Some(validator) = validator_arg(ex, 1, arg) else { return };
    let length = match engine::arg(ex, 1) {
        Some(arg) => match long_arg(ex, 2, arg) {
            Some(length) => length,
            None => return,
        },
        None => 0,
    };
    if let Some(this) = this(ex) {
        let scope = stored(&ARRAY_LIST);
        set(scope, this, &ARRAY_LIST_VALIDATOR, validator);
        set(scope, this, &ARRAY_LIST_LENGTH, long_zval(length));
    }
}

extern "C" fn array_list_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let scope = stored(&ARRAY_LIST);
    let Some(length) = get(scope, this, &ARRAY_LIST_LENGTH) else { return };
    let length = length.long().unwrap_or(0);
    let Some(validator) = get(scope, this, &ARRAY_LIST_VALIDATOR) else { return };
    // `in_array($this->validator->getDescription(), ['', '0'], true)`, then the description
    // again for the message.
    let Some(first) = call_method(&validator, &GET_DESCRIPTION, &[]) else { return };
    let item = if matches!(bytes(&first), Some(b"" | b"0")) {
        None
    } else {
        let Some(validator) = get(scope, this, &ARRAY_LIST_VALIDATOR) else { return };
        let Some(second) = call_method(&validator, &GET_DESCRIPTION, &[]) else { return };
        Some(second)
    };
    set_built(retval, |out, text| {
        if let Some(item) = &item {
            engine::append_string(item, text)?;
        }
        utopia_validators::array_list_description(length, item.is_some().then_some(&text[..]), out);
        Some(())
    });
}

extern "C" fn array_list_type(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(validator) = get(stored(&ARRAY_LIST), this, &ARRAY_LIST_VALIDATOR) else { return };
    let Some(kind) = call_method(&validator, &GET_TYPE, &[]) else { return };
    if let Some(kind) = engine::verify_return(ex, kind, MAY_BE_STRING, true) {
        *retval = kind;
    }
}

extern "C" fn array_list_validator(ex: &mut ExecuteData, retval: &mut Zval) {
    return_property(ex, retval, &ARRAY_LIST, &ARRAY_LIST_VALIDATOR);
}

extern "C" fn array_list_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\ArrayList::isValid") else { return };
    // `foreach ($value as $element)` walks the array as it was passed, whatever a validator
    // does meanwhile.
    let value = value.dereference().shallow_clone();
    let Some(elements) = value.array() else {
        set_bool(retval, false);
        return;
    };
    let Some(this) = this(ex) else { return };
    let scope = stored(&ARRAY_LIST);
    for element in engine::values(elements) {
        let Some(validator) = get(scope, this, &ARRAY_LIST_VALIDATOR) else { return };
        let Some(valid) = call_method(&validator, &IS_VALID, &[element]) else { return };
        if !engine::condition(&valid) {
            set_bool(retval, false);
            return;
        }
    }
    let Some(length) = get(scope, this, &ARRAY_LIST_LENGTH) else { return };
    let length = length.long().unwrap_or(0);
    let count = i64::try_from(elements.len()).unwrap_or(i64::MAX);
    set_bool(retval, length == 0 || count <= length);
}

// AllOf, AnyOf, NoneOf ------------------------------------------------------------------

/// Which of the three a method serves: what each asks of its validators.
#[derive(Clone, Copy)]
enum Of {
    All,
    Any,
    None,
}

impl Of {
    fn name(self) -> &'static str {
        match self {
            Of::All => "Utopia\\Validator\\AllOf",
            Of::Any => "Utopia\\Validator\\AnyOf",
            Of::None => "Utopia\\Validator\\NoneOf",
        }
    }

    fn class(self) -> &'static ClassEntry {
        stored(match self {
            Of::All => &ALL_OF,
            Of::Any => &ANY_OF,
            Of::None => &NONE_OF,
        })
    }
}

static ALL_OF: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static ANY_OF: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static NONE_OF: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
// The three declare the same properties, at the same offsets.
static OF_FAILED_RULE: Property = Property::new("failedRule");
static OF_VALIDATORS: Property = Property::new("validators");
static OF_TYPE: Property = Property::new("type");

const OF_PARAMS: &[leaf::Param] =
    &[("validators", MAY_BE_ARRAY, None), ("type", MAY_BE_STRING, Some("self::TYPE_MIXED"))];

const ALL_OF_METHODS: &[Method] = &[
    Method { name: "__construct", handler: all_of_construct, flags: PUBLIC, params: OF_PARAMS, returns: 0 },
    Method { name: "getDescription", handler: all_of_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: all_of_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
    Method { name: "getType", handler: all_of_type, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array_true, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
];

const ANY_OF_METHODS: &[Method] = &[
    Method { name: "__construct", handler: any_of_construct, flags: PUBLIC, params: OF_PARAMS, returns: 0 },
    Method { name: "getValidators", handler: any_of_validators, flags: PUBLIC, params: &[], returns: MAY_BE_ARRAY },
    Method { name: "getDescription", handler: any_of_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: any_of_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
    Method { name: "getType", handler: any_of_type, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array_true, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
];

const NONE_OF_METHODS: &[Method] = &[
    Method { name: "__construct", handler: none_of_construct, flags: PUBLIC, params: OF_PARAMS, returns: 0 },
    Method { name: "getDescription", handler: none_of_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: none_of_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
    Method { name: "getType", handler: none_of_type, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array_true, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
];

/// `protected ?Validator $failedRule = null;`, then the promoted `$validators` and `$type`.
fn of_declare(class: &mut ClassEntry) {
    let failed = PropertyType::Class { name: super::BASE, nullable: true };
    engine::declare_property(class, &OF_FAILED_RULE, failed, BODY, Some(null_zval()));
    engine::declare_property(class, &OF_VALIDATORS, PropertyType::Mask(MAY_BE_ARRAY), PROMOTED, None);
    engine::declare_property(class, &OF_TYPE, PropertyType::Mask(MAY_BE_STRING), PROMOTED, None);
}

fn all_of_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    of_declare(class);
    ALL_OF.store(class, Ordering::Release);
    leaf::patch(class, ALL_OF_METHODS, tables, &[]);
}

fn any_of_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    of_declare(class);
    ANY_OF.store(class, Ordering::Release);
    leaf::patch(class, ANY_OF_METHODS, tables, &[]);
}

fn none_of_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    of_declare(class);
    NONE_OF.store(class, Ordering::Release);
    leaf::patch(class, NONE_OF_METHODS, tables, &[]);
}

fn of_construct(of: Of, ex: &mut ExecuteData) {
    let passed = engine::args_count(ex);
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, &format!("{}::__construct", of.name()), passed, 1, 2);
        return;
    };
    let Some(validators) = array_arg(ex, 1, arg) else { return };
    let kind = match engine::arg(ex, 1) {
        Some(arg) => match str_zval_arg(ex, 2, arg) {
            Some(kind) => kind,
            None => return,
        },
        None => TYPE_MIXED.zval(),
    };
    if let Some(this) = this(ex) {
        let scope = of.class();
        set(scope, this, &OF_VALIDATORS, validators);
        set(scope, this, &OF_TYPE, kind);
    }
}

/// `getDescription()`: the failed rule's description, else the first validator's.
fn of_description(of: Of, ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let scope = of.class();
    let Some(failed) = get(scope, this, &OF_FAILED_RULE) else { return };
    let description = if failed.is_null() {
        let Some(validators) = get(scope, this, &OF_VALIDATORS) else { return };
        let Some(first) = engine::dimension_index(&validators, 0) else { return };
        call_method(&first, &GET_DESCRIPTION, &[])
    } else {
        call_method(&failed, &GET_DESCRIPTION, &[])
    };
    let Some(description) = description else { return };
    if let Some(description) = engine::verify_return(ex, description, MAY_BE_STRING, false) {
        *retval = description;
    }
}

/// `isValid()`: each validator asked in order, `$failedRule` recorded and the loop ended as
/// the class's own rule says.
fn of_is_valid(of: Of, ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = engine::arg(ex, 0) else {
        too_few(ex, &format!("{}::isValid", of.name()), 0, 1, 1);
        return;
    };
    let Some(this) = this(ex) else { return };
    let scope = of.class();
    // `foreach ($this->validators as $rule)` walks the array as it was when the loop began.
    let Some(validators) = get(scope, this, &OF_VALIDATORS) else { return };
    let Some(list) = validators.array() else { return };
    for rule in engine::values(list) {
        let Some(valid) = call_method(rule, &IS_VALID, &[&*value]) else { return };
        // `$this->failedRule = $rule`: a Validator straight into the slot, anything else
        // through the engine's type check.
        let record = |this: &mut ZendObject| {
            let rule = rule.dereference().shallow_clone();
            if rule.object().is_some_and(|object| object.instance_of(super::base_class())) {
                set(scope, this, &OF_FAILED_RULE, rule);
                return true;
            }
            engine::write(scope, this, "failedRule", rule);
            !engine::has_exception()
        };
        match of {
            Of::All => {
                if !engine::condition(&valid) {
                    if record(this) {
                        set_bool(retval, false);
                    }
                    return;
                }
            }
            Of::Any => {
                if !record(this) {
                    return;
                }
                if engine::condition(&valid) {
                    set_bool(retval, true);
                    return;
                }
            }
            Of::None => {
                if engine::condition(&valid) {
                    if record(this) {
                        set_bool(retval, false);
                    }
                    return;
                }
            }
        }
    }
    set_bool(retval, matches!(of, Of::All | Of::None));
}

fn of_type(of: Of, ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    if let Some(kind) = get(of.class(), this, &OF_TYPE) {
        *retval = kind;
    }
}

extern "C" fn all_of_construct(ex: &mut ExecuteData, _: &mut Zval) {
    of_construct(Of::All, ex);
}
extern "C" fn any_of_construct(ex: &mut ExecuteData, _: &mut Zval) {
    of_construct(Of::Any, ex);
}
extern "C" fn none_of_construct(ex: &mut ExecuteData, _: &mut Zval) {
    of_construct(Of::None, ex);
}
extern "C" fn all_of_description(ex: &mut ExecuteData, retval: &mut Zval) {
    of_description(Of::All, ex, retval);
}
extern "C" fn any_of_description(ex: &mut ExecuteData, retval: &mut Zval) {
    of_description(Of::Any, ex, retval);
}
extern "C" fn none_of_description(ex: &mut ExecuteData, retval: &mut Zval) {
    of_description(Of::None, ex, retval);
}
extern "C" fn all_of_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    of_is_valid(Of::All, ex, retval);
}
extern "C" fn any_of_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    of_is_valid(Of::Any, ex, retval);
}
extern "C" fn none_of_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    of_is_valid(Of::None, ex, retval);
}
extern "C" fn all_of_type(ex: &mut ExecuteData, retval: &mut Zval) {
    of_type(Of::All, ex, retval);
}
extern "C" fn any_of_type(ex: &mut ExecuteData, retval: &mut Zval) {
    of_type(Of::Any, ex, retval);
}
extern "C" fn none_of_type(ex: &mut ExecuteData, retval: &mut Zval) {
    of_type(Of::None, ex, retval);
}
extern "C" fn any_of_validators(ex: &mut ExecuteData, retval: &mut Zval) {
    return_property(ex, retval, &ANY_OF, &OF_VALIDATORS);
}

// Multiple ------------------------------------------------------------------------------

static MULTIPLE: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static MULTIPLE_RULES: Property = Property::new("rules");
static MULTIPLE_TYPE: Property = Property::new("type");

const MULTIPLE_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: multiple_construct,
        flags: PUBLIC,
        params: &[("rules", MAY_BE_ARRAY, None), ("type", MAY_BE_STRING | MAY_BE_NULL, Some("self::TYPE_MIXED"))],
        returns: 0,
    },
    Method {
        name: "addRule",
        handler: multiple_add_rule,
        flags: PUBLIC,
        params: &[("rule", VALIDATOR, None)],
        returns: MAY_BE_STATIC,
    },
    Method {
        name: "getDescription",
        handler: multiple_description,
        flags: PUBLIC,
        params: &[],
        returns: MAY_BE_STRING,
    },
    Method { name: "isValid", handler: multiple_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
    Method { name: "getType", handler: multiple_type, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array_true, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
];

fn multiple_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    // `protected $rules = [];`, then the promoted `?string $type`.
    engine::declare_property(class, &MULTIPLE_RULES, PropertyType::Mask(0), BODY, Some(engine::empty_array()));
    engine::declare_property(class, &MULTIPLE_TYPE, PropertyType::Mask(MAY_BE_STRING | MAY_BE_NULL), PROMOTED, None);
    MULTIPLE.store(class, Ordering::Release);
    // `$rules` is a constructor parameter that declares no property.
    leaf::patch(class, MULTIPLE_METHODS, tables, &["rules"]);
}

extern "C" fn multiple_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let passed = engine::args_count(ex);
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\Validator\\Multiple::__construct", passed, 1, 2);
        return;
    };
    let Some(rules) = array_arg(ex, 1, arg) else { return };
    let kind = match engine::arg(ex, 1) {
        Some(arg) => match engine::scalar_arg(ex, 2, arg, MAY_BE_STRING | MAY_BE_NULL, "?string") {
            Some(kind) => kind,
            None => return,
        },
        None => TYPE_MIXED.zval(),
    };
    let Some(this) = this(ex) else { return };
    set(stored(&MULTIPLE), this, &MULTIPLE_TYPE, kind);
    // `$this->addRule($rule)`: a subclass's own `addRule()`, if it has one. On a `Multiple`
    // itself, that is this class's, and a rule it takes without an error is appended here
    // rather than through a call into the engine, which is most of the constructor's cost.
    let object = object_zval(this);
    if let Some(rules) = rules.array() {
        for rule in engine::values(rules) {
            if instance_of(rule, super::base_class()) && multiple_append(this, rule) {
                continue;
            }
            if call_method(&object, &ADD_RULE, &[rule]).is_none() {
                return;
            }
        }
    }
}

/// `$this->rules[] = $rule` straight into the array a `Multiple` itself (not a subclass) holds,
/// as PHP appends it; `false` when the engine's way must be taken.
fn multiple_append(this: &mut ZendObject, rule: &Zval) -> bool {
    engine::array_slot(stored(&MULTIPLE), this, &MULTIPLE_RULES)
        .is_some_and(|rules| rules.push(rule.dereference().shallow_clone()).is_ok())
}

extern "C" fn multiple_add_rule(ex: &mut ExecuteData, retval: &mut Zval) {
    let passed = engine::args_count(ex);
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\Validator\\Multiple::addRule", passed, 1, 1);
        return;
    };
    let Some(rule) = validator_arg(ex, 1, arg) else { return };
    let Some(this) = this(ex) else { return };
    if multiple_append(this, &rule) {
        retval.set_object(this);
        return;
    }
    let scope = stored(&MULTIPLE);
    // `$this->rules[] = $rule`, on whatever `$rules` holds.
    let Some(rules) = get(scope, this, &MULTIPLE_RULES) else { return };
    let rules = rules.dereference().shallow_clone();
    let appended = if rules.is_array() {
        let (mut copy, _) = engine::map_values(&rules, |_| Some(None));
        copy.array_mut().map(|array| array.push(rule)).is_some_and(|pushed| pushed.is_ok()).then_some(copy)
    } else if rules.is_null() || rules.bool() == Some(false) {
        if rules.bool() == Some(false) {
            engine::deprecated("Automatic conversion of false to array is deprecated");
        }
        let mut array = ext_php_rs::types::ZendHashTable::new();
        let _ = array.push(rule);
        let mut zval = Zval::new();
        zval.set_hashtable(array);
        Some(zval)
    } else if rules.zend_str().is_some() {
        engine::error("[] operator not supported for strings");
        None
    } else if let Some(object) = rules.object() {
        if object.instance_of(ce::arrayaccess()) {
            if call_method(&rules, &OFFSET_SET, &[&null_zval(), &rule]).is_none() {
                return;
            }
        } else {
            engine::error(&format!("Cannot use object of type {} as array", engine::value_name(&rules)));
            return;
        }
        None
    } else {
        engine::error("Cannot use a scalar value as an array");
        None
    };
    if let Some(appended) = appended {
        engine::write(scope, this, "rules", appended);
        if engine::has_exception() {
            return;
        }
    } else if engine::has_exception() {
        return;
    }
    retval.set_object(this);
}

extern "C" fn multiple_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(rules) = get(stored(&MULTIPLE), this, &MULTIPLE_RULES) else { return };
    let Some(items) = engine::foreach_items(&rules) else { return };
    set_built(retval, |description, _| {
        if let Some(items) = items.array() {
            for (key, rule) in engine::keyed_values(items) {
                // `++$key . '. ' . $rule->getDescription() . " \n"`; an int key short of
                // `PHP_INT_MAX` (past which `++` makes a float) needs no engine.
                let key = match key {
                    KeyRef::Index(i) if i < i64::MAX => long_zval(i + 1),
                    KeyRef::Index(i) => engine::increment(&long_zval(i))?,
                    KeyRef::Name(name) => engine::increment(&string(name.as_bytes()))?,
                };
                let text = call_method(rule, &GET_DESCRIPTION, &[])?;
                engine::append_string(&key, description)?;
                description.extend_from_slice(b". ");
                engine::append_string(&text, description)?;
                description.extend_from_slice(b" \n");
            }
        }
        Some(())
    });
}

extern "C" fn multiple_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Multiple::isValid") else { return };
    let Some(this) = this(ex) else { return };
    let Some(rules) = get(stored(&MULTIPLE), this, &MULTIPLE_RULES) else { return };
    let Some(items) = engine::foreach_items(&rules) else { return };
    if let Some(items) = items.array() {
        for rule in engine::values(items) {
            let Some(valid) = call_method(rule, &IS_VALID, &[&*value]) else { return };
            // `false === $rule->isValid($value)`.
            if valid.dereference().bool() == Some(false) {
                set_bool(retval, false);
                return;
            }
        }
    }
    set_bool(retval, true);
}

extern "C" fn multiple_type(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(kind) = get(stored(&MULTIPLE), this, &MULTIPLE_TYPE) else { return };
    // `?string $type` returned as `string`: `null` is the engine's return `TypeError`.
    if let Some(kind) = engine::verify_return(ex, kind, MAY_BE_STRING, false) {
        *retval = kind;
    }
}

// Assoc ---------------------------------------------------------------------------------

static ASSOC: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static ASSOC_LENGTH: Property = Property::new("length");

const ASSOC_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: assoc_construct,
        flags: PUBLIC,
        params: &[("length", MAY_BE_LONG, Some("65535"))],
        returns: 0,
    },
    Method { name: "getDescription", handler: assoc_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_object, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: assoc_is_valid, flags: PUBLIC, params: VALUE, returns: MAY_BE_BOOL },
];

fn assoc_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare_property(class, &ASSOC_LENGTH, PropertyType::Mask(MAY_BE_LONG), PROMOTED, None);
    ASSOC.store(class, Ordering::Release);
    leaf::patch(class, ASSOC_METHODS, tables, &[]);
}

extern "C" fn type_object(_: &mut ExecuteData, retval: &mut Zval) {
    set_literal(retval, &TYPE_OBJECT);
}

/// A constructor taking only `int $length = $default`, into `property`.
fn length_construct(ex: &mut ExecuteData, scope: &AtomicPtr<ClassEntry>, property: &Property, default: i64) {
    let length = match engine::arg(ex, 0) {
        Some(arg) => match long_arg(ex, 1, arg) {
            Some(length) => length,
            None => return,
        },
        None => default,
    };
    if let Some(this) = this(ex) {
        set(stored(scope), this, property, long_zval(length));
    }
}

extern "C" fn assoc_construct(ex: &mut ExecuteData, _: &mut Zval) {
    length_construct(ex, &ASSOC, &ASSOC_LENGTH, 65535);
}

extern "C" fn assoc_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_literal(retval, &ASSOC_DESCRIPTION);
}

extern "C" fn assoc_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Assoc::isValid") else { return };
    let value = value.dereference().shallow_clone();
    let Some(array) = value.array() else {
        set_bool(retval, false);
        return;
    };
    // `strlen(json_encode($value))`: `false` counts as `''`.
    let Some(encoded) = json_encode(&value) else { return };
    let size = encoded.map_or(0, |encoded| encoded.len());
    let Some(this) = this(ex) else { return };
    let Some(length) = get(stored(&ASSOC), this, &ASSOC_LENGTH) else { return };
    if i64::try_from(size).unwrap_or(i64::MAX) > length.long().unwrap_or(0) {
        set_bool(retval, false);
        return;
    }
    // `array_keys($value) !== range(0, count($value) - 1)`: an empty array's `range(0, -1)`
    // is `[0, -1]`, so it counts as associative.
    set_bool(retval, array.is_empty() || !is_list(&value));
}

// JSON ----------------------------------------------------------------------------------

const JSON_METHODS: &[Method] = &[
    Method { name: "getDescription", handler: json_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_object, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: json_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn json_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    leaf::patch(class, JSON_METHODS, tables, &[]);
}

extern "C" fn json_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_literal(retval, &JSON_DESCRIPTION);
}

extern "C" fn json_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\JSON::isValid") else { return };
    let value = value.dereference();
    if value.is_array() {
        set_bool(retval, true);
        return;
    }
    if value.zend_str().is_none() {
        set_bool(retval, false);
        return;
    }
    if let Some((_, decoded)) = json_decode(value) {
        set_bool(retval, decoded);
    }
}

// JSON\ArrayValidator, JSON\ObjectValidator ----------------------------------------------

static JSON_ARRAY: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static JSON_OBJECT: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
// Both declare `$length` alone, at the same offset.
static JSON_LENGTH: Property = Property::new("length");

const LENGTH_PARAMS: &[leaf::Param] = &[("length", MAY_BE_LONG, Some("0"))];
const ENCODED_PARAMS: &[leaf::Param] = &[("encoded", MAY_BE_STRING | MAY_BE_FALSE, None)];

const JSON_ARRAY_METHODS: &[Method] = &[
    Method { name: "__construct", handler: json_array_construct, flags: PUBLIC, params: LENGTH_PARAMS, returns: 0 },
    Method {
        name: "getDescription",
        handler: json_array_description,
        flags: PUBLIC,
        params: &[],
        returns: MAY_BE_STRING,
    },
    Method { name: "isArray", handler: is_array_true, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_array, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: json_array_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
    Method {
        name: "hasValidLength",
        handler: json_array_length,
        flags: PRIVATE,
        params: ENCODED_PARAMS,
        returns: MAY_BE_BOOL,
    },
];

const JSON_OBJECT_METHODS: &[Method] = &[
    Method { name: "__construct", handler: json_object_construct, flags: PUBLIC, params: LENGTH_PARAMS, returns: 0 },
    Method {
        name: "getDescription",
        handler: json_object_description,
        flags: PUBLIC,
        params: &[],
        returns: MAY_BE_STRING,
    },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_object, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: json_object_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
    Method {
        name: "hasValidLength",
        handler: json_object_length,
        flags: PRIVATE,
        params: ENCODED_PARAMS,
        returns: MAY_BE_BOOL,
    },
];

fn json_array_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare_property(class, &JSON_LENGTH, PropertyType::Mask(MAY_BE_LONG), PROMOTED, None);
    JSON_ARRAY.store(class, Ordering::Release);
    leaf::patch(class, JSON_ARRAY_METHODS, tables, &[]);
}

fn json_object_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare_property(class, &JSON_LENGTH, PropertyType::Mask(MAY_BE_LONG), PROMOTED, None);
    JSON_OBJECT.store(class, Ordering::Release);
    leaf::patch(class, JSON_OBJECT_METHODS, tables, &[]);
}

extern "C" fn type_array(_: &mut ExecuteData, retval: &mut Zval) {
    set_literal(retval, &TYPE_ARRAY);
}

extern "C" fn json_array_construct(ex: &mut ExecuteData, _: &mut Zval) {
    length_construct(ex, &JSON_ARRAY, &JSON_LENGTH, 0);
}

extern "C" fn json_object_construct(ex: &mut ExecuteData, _: &mut Zval) {
    length_construct(ex, &JSON_OBJECT, &JSON_LENGTH, 0);
}

/// `$this->length` of a JSON validator.
fn json_length_of(scope: &AtomicPtr<ClassEntry>, this: &mut ZendObject) -> Option<i64> {
    get(stored(scope), this, &JSON_LENGTH).map(|length| length.long().unwrap_or(0))
}

extern "C" fn json_array_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    if let Some(length) = json_length_of(&JSON_ARRAY, this) {
        set_built(retval, |out, _| {
            utopia_validators::json_array_description(length, out);
            Some(())
        });
    }
}

extern "C" fn json_object_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    if let Some(length) = json_length_of(&JSON_OBJECT, this) {
        set_built(retval, |out, _| {
            utopia_validators::json_object_description(length, out);
            Some(())
        });
    }
}

/// `isValid()` of `JSON\ArrayValidator` (`array`) or `JSON\ObjectValidator`.
fn json_shape_is_valid(ex: &mut ExecuteData, retval: &mut Zval, array: bool) {
    let (name, scope) = if array {
        ("Utopia\\Validator\\JSON\\ArrayValidator::isValid", &JSON_ARRAY)
    } else {
        ("Utopia\\Validator\\JSON\\ObjectValidator::isValid", &JSON_OBJECT)
    };
    let Some(value) = value_arg(ex, name) else { return };
    let value = value.dereference().shallow_clone();
    let Some(this) = this(ex) else { return };
    if let Some(text) = bytes(&value) {
        let Some(length) = json_length_of(scope, this) else { return };
        if !utopia_validators::json_length(length, Some(text)) {
            set_bool(retval, false);
            return;
        }
        // Decoded to objects, so `[]` and `{}` stay apart.
        let Some((decoded, ok)) = json_decode(&value) else { return };
        let shape = if array { decoded.is_array() } else { instance_of(&decoded, ce::stdclass()) };
        set_bool(retval, ok && shape);
        return;
    }
    let encode = if !array && instance_of(&value, ce::stdclass()) {
        true
    } else if let Some(elements) = value.array() {
        // A decoded empty array is an ambiguous `[]` or `{}`, so it stays valid.
        elements.is_empty() || is_list(&value) == array
    } else {
        false
    };
    if !encode {
        set_bool(retval, false);
        return;
    }
    let Some(encoded) = json_encode(&value) else { return };
    let Some(length) = json_length_of(scope, this) else { return };
    set_bool(retval, utopia_validators::json_length(length, encoded.as_deref()));
}

extern "C" fn json_array_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    json_shape_is_valid(ex, retval, true);
}

extern "C" fn json_object_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    json_shape_is_valid(ex, retval, false);
}

/// The private `hasValidLength(string|false $encoded)`, which only reflection can call.
fn json_has_valid_length(ex: &mut ExecuteData, retval: &mut Zval, scope: &AtomicPtr<ClassEntry>, name: &str) {
    let passed = engine::args_count(ex);
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, name, passed, 1, 1);
        return;
    };
    let Some(encoded) = engine::scalar_arg(ex, 1, arg, MAY_BE_STRING | MAY_BE_FALSE, "string|false") else { return };
    let Some(this) = this(ex) else { return };
    let Some(length) = json_length_of(scope, this) else { return };
    set_bool(retval, utopia_validators::json_length(length, bytes(&encoded)));
}

extern "C" fn json_array_length(ex: &mut ExecuteData, retval: &mut Zval) {
    json_has_valid_length(ex, retval, &JSON_ARRAY, "Utopia\\Validator\\JSON\\ArrayValidator::hasValidLength");
}

extern "C" fn json_object_length(ex: &mut ExecuteData, retval: &mut Zval) {
    json_has_valid_length(ex, retval, &JSON_OBJECT, "Utopia\\Validator\\JSON\\ObjectValidator::hasValidLength");
}

// JSON\FCM ------------------------------------------------------------------------------

static FCM: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static FCM_ERROR: Property = Property::new("error");

const FCM_METHODS: &[Method] = &[
    Method { name: "getDescription", handler: fcm_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_object, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: fcm_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
    Method {
        name: "isNonEmptyString",
        handler: fcm_non_empty,
        flags: PRIVATE,
        params: VALUE_MIXED,
        returns: MAY_BE_BOOL,
    },
    Method {
        name: "isHttpsUrl",
        handler: fcm_https_url,
        flags: PRIVATE,
        params: &[("value", MAY_BE_STRING, None)],
        returns: MAY_BE_BOOL,
    },
];

fn fcm_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    let private = ConstantFlags::Private;
    engine::declare_constant_as(
        class,
        "REQUIRED_FIELDS",
        engine::string_map(&utopia_validators::FCM_REQUIRED),
        MAY_BE_ARRAY,
        private,
    );
    engine::declare_constant_as(
        class,
        "OPTIONAL_FIELDS",
        engine::string_list(&utopia_validators::FCM_OPTIONAL),
        MAY_BE_ARRAY,
        private,
    );
    engine::declare_constant_as(
        class,
        "URL_FIELDS",
        engine::string_list(&utopia_validators::FCM_URL),
        MAY_BE_ARRAY,
        private,
    );
    engine::inherited_constants_last(class, &INHERITED);
    // `private ?string $error = null;`.
    let declared = Declared { private: true, readonly: false, promoted: false };
    engine::declare_property(
        class,
        &FCM_ERROR,
        PropertyType::Mask(MAY_BE_STRING | MAY_BE_NULL),
        declared,
        Some(null_zval()),
    );
    FCM.store(class, Ordering::Release);
    leaf::patch(class, FCM_METHODS, tables, &[]);
}

extern "C" fn fcm_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(error) = get(stored(&FCM), this, &FCM_ERROR) else { return };
    if error.is_null() {
        set_literal(retval, &FCM_DESCRIPTION);
    } else {
        *retval = error;
    }
}

/// `FCM::isNonEmptyString($value)`.
fn non_empty_string(value: Option<&Zval>) -> bool {
    value.and_then(bytes).is_some_and(utopia_validators::non_empty)
}

/// `FCM::isHttpsUrl($value)`: `filter_var($value, FILTER_VALIDATE_URL) !== false`, then the
/// scheme `parse_url()` reads, lowercased, is `https`.
fn https_url(value: &Zval) -> Option<bool> {
    let url = call("filter_var", &[value, &long_zval(FILTER_VALIDATE_URL)])?;
    if url.bool() == Some(false) {
        return Some(false);
    }
    let scheme = call("parse_url", &[value, &long_zval(PHP_URL_SCHEME)])?;
    Some(utopia_validators::https_scheme(bytes(&scheme).unwrap_or_default()))
}

extern "C" fn fcm_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\JSON\\FCM::isValid") else { return };
    let mut value = value.dereference().shallow_clone();
    let Some(this) = this(ex) else { return };
    let scope = stored(&FCM);
    set(scope, this, &FCM_ERROR, null_zval());
    let fail = |this: &mut ZendObject, retval: &mut Zval, message: &str| {
        set(scope, this, &FCM_ERROR, string(message.as_bytes()));
        set_bool(retval, false);
    };
    if value.zend_str().is_some() {
        let Some((decoded, ok)) = json_decode(&value) else { return };
        if !ok {
            set_bool(retval, false);
            return;
        }
        value = decoded;
    }
    if instance_of(&value, ce::stdclass()) {
        let Some(vars) = call("get_object_vars", &[&value]) else { return };
        value = vars;
    } else if value.array().is_none_or(|array| !array.is_empty() && is_list(&value)) {
        set_bool(retval, false);
        return;
    }
    let Some(fields) = value.array() else {
        set_bool(retval, false);
        return;
    };
    let field = |name: &str| fields.get(name).map(Zval::dereference);
    for (name, purpose) in utopia_validators::FCM_REQUIRED {
        if !non_empty_string(field(name)) {
            fail(this, retval, &utopia_validators::fcm_required_error(name, purpose));
            return;
        }
    }
    for name in utopia_validators::FCM_OPTIONAL {
        if let Some(value) = field(name)
            && !non_empty_string(Some(value))
        {
            fail(this, retval, &utopia_validators::fcm_optional_error(name));
            return;
        }
    }
    if field("type").and_then(bytes) != Some(b"service_account") {
        fail(this, retval, utopia_validators::FCM_TYPE_ERROR);
        return;
    }
    let email = field("client_email").map_or_else(null_zval, Zval::shallow_clone);
    let Some(email) = call("filter_var", &[&email, &long_zval(FILTER_VALIDATE_EMAIL)]) else { return };
    if email.bool() == Some(false) {
        fail(this, retval, utopia_validators::FCM_EMAIL_ERROR);
        return;
    }
    if !field("private_key").and_then(bytes).is_some_and(utopia_validators::pem_private_key) {
        fail(this, retval, utopia_validators::FCM_KEY_ERROR);
        return;
    }
    for name in utopia_validators::FCM_URL {
        // `isset($value[$field])`: present and not `null`.
        let Some(url) = field(name).filter(|value| !value.is_null()) else { continue };
        let Some(https) = https_url(url) else { return };
        if !https {
            fail(this, retval, &utopia_validators::fcm_url_error(name));
            return;
        }
    }
    set_bool(retval, true);
}

extern "C" fn fcm_non_empty(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\JSON\\FCM::isNonEmptyString") else { return };
    set_bool(retval, non_empty_string(Some(value.dereference())));
}

extern "C" fn fcm_https_url(ex: &mut ExecuteData, retval: &mut Zval) {
    let passed = engine::args_count(ex);
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\Validator\\JSON\\FCM::isHttpsUrl", passed, 1, 1);
        return;
    };
    let Some(value) = engine::scalar_arg(ex, 1, arg, MAY_BE_STRING, "string") else { return };
    if let Some(https) = https_url(&value) {
        set_bool(retval, https);
    }
}

// Globstar ------------------------------------------------------------------------------

static GLOBSTAR: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static GLOBSTAR_PATTERNS: Property = Property::new("patterns");

const MATCH_PARAMS: &[leaf::Param] = &[("subject", MAY_BE_STRING, None), ("pattern", MAY_BE_STRING, None)];

const GLOBSTAR_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: globstar_construct,
        flags: PUBLIC,
        params: &[("patterns", MAY_BE_ARRAY, None)],
        returns: 0,
    },
    Method {
        name: "getDescription",
        handler: globstar_description,
        flags: PUBLIC,
        params: &[],
        returns: MAY_BE_STRING,
    },
    Method { name: "isValid", handler: globstar_is_valid, flags: PUBLIC, params: VALUE, returns: MAY_BE_BOOL },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: leaf::type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "match", handler: globstar_match, flags: PRIVATE, params: MATCH_PARAMS, returns: MAY_BE_BOOL },
    Method {
        name: "matchGlobstar",
        handler: globstar_match_globstar,
        flags: PRIVATE,
        params: MATCH_PARAMS,
        returns: MAY_BE_BOOL,
    },
];

fn globstar_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    // `private readonly array $patterns`, promoted.
    let declared = Declared { private: true, readonly: true, promoted: true };
    engine::declare_property(class, &GLOBSTAR_PATTERNS, PropertyType::Mask(MAY_BE_ARRAY), declared, None);
    GLOBSTAR.store(class, Ordering::Release);
    leaf::patch(class, GLOBSTAR_METHODS, tables, &[]);
}

extern "C" fn globstar_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let passed = engine::args_count(ex);
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\Validator\\Globstar::__construct", passed, 1, 1);
        return;
    };
    let Some(patterns) = array_arg(ex, 1, arg) else { return };
    if let Some(this) = this(ex) {
        // A second initialization of the readonly property goes through the engine, which
        // refuses it.
        engine::initialize(stored(&GLOBSTAR), this, &GLOBSTAR_PATTERNS, patterns);
    }
}

extern "C" fn globstar_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_literal(retval, &GLOBSTAR_DESCRIPTION);
}

/// `preg_match('~^' . $regex . '$~', $subject)` for the glob `pattern`, as a bool: the
/// engine's `preg_match()`, with its warnings and `preg_last_error()`.
fn glob_match(subject: &[u8], pattern: &[u8]) -> Option<bool> {
    let regex = string(&utopia_validators::globstar_regex(pattern));
    let matched = call("preg_match", &[&regex, &string(subject)])?;
    Some(engine::truthy(&matched))
}

/// `(string) $pattern` contains a wildcard, cast once per `str_contains()` as PHP's closure
/// does (`*`, then `?`, then `[`).
fn cast_wildcard(pattern: &Zval) -> Option<bool> {
    for wildcard in b"*?[" {
        if engine::to_string(pattern)?.contains(wildcard) {
            return Some(true);
        }
    }
    Some(false)
}

/// `$pattern` passed to `match(string $subject, string $pattern)` from `Globstar.php`, which
/// does not declare strict types: coerced as a weak-mode `string` parameter, or its
/// `TypeError`.
fn match_pattern(pattern: &Zval) -> Option<Vec<u8>> {
    if let Some(s) = bytes(pattern) {
        return Some(s.to_vec());
    }
    let coerced = engine::coerce_weak(pattern, MAY_BE_STRING);
    match coerced {
        Some(Some(value)) => bytes(&value).map(<[u8]>::to_vec),
        Some(None) => {
            let message = format!(
                "Utopia\\Validator\\Globstar::match(): Argument #2 ($pattern) must be of type string, {} given",
                engine::value_name(pattern.dereference())
            );
            let _ = throw(ce::type_error(), &message);
            None
        }
        None => None,
    }
}

extern "C" fn globstar_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Globstar::isValid") else { return };
    let Some(subject) = bytes(value).map(<[u8]>::to_vec) else {
        set_bool(retval, false);
        return;
    };
    let Some(this) = this(ex) else { return };
    let Some(patterns) = get(stored(&GLOBSTAR), this, &GLOBSTAR_PATTERNS) else { return };
    let Some(list) = patterns.array() else { return };
    if list.is_empty() {
        set_bool(retval, true);
        return;
    }
    let mut inclusions = false;
    for pattern in engine::values(list) {
        let Some(cast) = engine::to_string(pattern) else { return };
        if !utopia_validators::exclusion(&cast) {
            inclusions = true;
            break;
        }
    }
    // Only exclusions: valid unless one matches (`array_all()`, which stops at the first).
    if !inclusions {
        for pattern in engine::values(list) {
            let Some(cast) = engine::to_string(pattern) else { return };
            let Some(matched) = glob_match(&subject, cast.get(1..).unwrap_or_default()) else { return };
            if matched {
                set_bool(retval, false);
                return;
            }
        }
        set_bool(retval, true);
        return;
    }
    // A literal inclusion that matches wins over every exclusion.
    for pattern in engine::values(list) {
        let Some(cast) = engine::to_string(pattern) else { return };
        if utopia_validators::exclusion(&cast) {
            continue;
        }
        let Some(wildcard) = cast_wildcard(pattern) else { return };
        if wildcard {
            continue;
        }
        let Some(literal) = match_pattern(pattern) else { return };
        let Some(matched) = glob_match(&subject, &literal) else { return };
        if matched {
            set_bool(retval, true);
            return;
        }
    }
    // Then the last wildcard inclusion or exclusion that matches decides.
    let mut state = false;
    for pattern in engine::values(list) {
        let Some(cast) = engine::to_string(pattern) else { return };
        if utopia_validators::exclusion(&cast) {
            let Some(cast) = engine::to_string(pattern) else { return };
            let Some(matched) = glob_match(&subject, cast.get(1..).unwrap_or_default()) else { return };
            if matched {
                state = false;
            }
        } else {
            let Some(wildcard) = cast_wildcard(pattern) else { return };
            if wildcard {
                let Some(glob) = match_pattern(pattern) else { return };
                let Some(matched) = glob_match(&subject, &glob) else { return };
                if matched {
                    state = true;
                }
            }
        }
    }
    set_bool(retval, state);
}

/// The private `match()` and `matchGlobstar()`, which only reflection can call.
fn globstar_private_match(ex: &mut ExecuteData, retval: &mut Zval, name: &str) {
    let passed = engine::args_count(ex);
    let (Some(subject), Some(pattern)) = (engine::arg(ex, 0), engine::arg(ex, 1)) else {
        too_few(ex, name, passed, 2, 2);
        return;
    };
    let Some(subject) = engine::scalar_arg(ex, 1, subject, MAY_BE_STRING, "string") else { return };
    let Some(pattern) = engine::scalar_arg(ex, 2, pattern, MAY_BE_STRING, "string") else { return };
    if let Some(matched) = glob_match(bytes(&subject).unwrap_or_default(), bytes(&pattern).unwrap_or_default()) {
        set_bool(retval, matched);
    }
}

extern "C" fn globstar_match(ex: &mut ExecuteData, retval: &mut Zval) {
    globstar_private_match(ex, retval, "Utopia\\Validator\\Globstar::match");
}

extern "C" fn globstar_match_globstar(ex: &mut ExecuteData, retval: &mut Zval) {
    globstar_private_match(ex, retval, "Utopia\\Validator\\Globstar::matchGlobstar");
}

/// Registers the batch's classes, each extending `Utopia\Validator\Validator`. Called from
/// the module's startup (MINIT), after the base.
/// The methods these classes call on the validators they wrap (and on `$rules`).
static IS_VALID: Callee = Callee::new("isValid");
static GET_DESCRIPTION: Callee = Callee::new("getDescription");
static GET_TYPE: Callee = Callee::new("getType");
static ADD_RULE: Callee = Callee::new("addRule");
static OFFSET_SET: Callee = Callee::new("offsetSet");

pub fn register() -> ext_php_rs::error::Result<()> {
    for method in [&IS_VALID, &GET_DESCRIPTION, &GET_TYPE, &ADD_RULE, &OFFSET_SET] {
        method.intern();
    }
    engine::intern(&[&ASSOC_DESCRIPTION, &JSON_DESCRIPTION, &GLOBSTAR_DESCRIPTION, &FCM_DESCRIPTION]);
    let base = (super::base_class as fn() -> &'static ClassEntry, super::BASE);
    build("Utopia\\Validator\\Nullable", base, NULLABLE_METHODS, nullable_register)?;
    build("Utopia\\Validator\\ArrayList", base, ARRAY_LIST_METHODS, array_list_register)?;
    build("Utopia\\Validator\\AllOf", base, ALL_OF_METHODS, all_of_register)?;
    build("Utopia\\Validator\\AnyOf", base, ANY_OF_METHODS, any_of_register)?;
    build("Utopia\\Validator\\NoneOf", base, NONE_OF_METHODS, none_of_register)?;
    build("Utopia\\Validator\\Multiple", base, MULTIPLE_METHODS, multiple_register)?;
    build("Utopia\\Validator\\Assoc", base, ASSOC_METHODS, assoc_register)?;
    build("Utopia\\Validator\\JSON", base, JSON_METHODS, json_register)?;
    build("Utopia\\Validator\\JSON\\ArrayValidator", base, JSON_ARRAY_METHODS, json_array_register)?;
    build("Utopia\\Validator\\JSON\\ObjectValidator", base, JSON_OBJECT_METHODS, json_object_register)?;
    build("Utopia\\Validator\\JSON\\FCM", base, FCM_METHODS, fcm_register)?;
    build("Utopia\\Validator\\Globstar", base, GLOBSTAR_METHODS, globstar_register)
}
