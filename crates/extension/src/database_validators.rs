//! `Utopia\Database\Validator\Key`, `Label` and `UID` of utopia-php/database over
//! [`utopia_database::validator`], switched on by `database-validators` (off by default, apart
//! from `database`: see the README).
//!
//! Built by hand like `packages/validators`' leaves ([`crate::validators::text`]): they extend
//! `Utopia\Validator\Validator` and keep their state in the properties `Key.php` declares, in its
//! order (`protected string $message`, then the promoted `protected readonly bool $allowInternal`
//! and `protected readonly int $maxLength`). Every method reads them when PHP reads them, so
//! Appwrite's `CustomId` (a `Key`) and `KeywordId` (a `UID`), which call `parent::isValid()` and
//! `parent::getDescription()`, behave as on the PHP classes, as do an object made without its
//! constructor and a second `__construct()`.

use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::args::ArgInfoTables;
use ext_php_rs::types::{ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData};
use utopia_database::validator::{self, Shape};

use crate::classes::engine::{
    self, Declared, MAY_BE_BOOL, MAY_BE_LONG, MAY_BE_STRING, Property, PropertyType, bool_arg, get, long_arg,
};
use crate::classes::leaf::{
    self, Method, PUBLIC, VALUE_MIXED, build, is_array, set_bool, set_built, stored, this, type_string, value_arg,
};

const KEY_NAME: &str = "Utopia\\Database\\Validator\\Key";
const LABEL_NAME: &str = "Utopia\\Database\\Validator\\Label";
const UID_NAME: &str = "Utopia\\Database\\Validator\\UID";

/// The constructors' `$maxLength` default, as `Key.php` writes it.
const MAX_LENGTH_DEFAULT: &str = "\\Utopia\\Database\\Database::MAX_UID_DEFAULT_LENGTH";

static KEY: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static LABEL: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static UID: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());

static MESSAGE: Property = Property::new("message");
static ALLOW_INTERNAL: Property = Property::new("allowInternal");
static MAX_LENGTH: Property = Property::new("maxLength");

fn key_class() -> &'static ClassEntry {
    stored(&KEY)
}

/// The scope a method reads and writes the properties from: the object's own class when it is
/// one of the three (its slots are then reached directly), `Key`, which declares them, for a PHP
/// subclass (through the engine, which honours what the subclass redeclares).
#[inline]
fn scope(this: &ZendObject) -> &'static ClassEntry {
    for slot in [&KEY, &LABEL, &UID] {
        let class = stored(slot);
        if std::ptr::eq(this.ce, class) {
            return class;
        }
    }
    key_class()
}

/// A string argument's bytes, when it is a string.
#[inline]
fn bytes(value: &Zval) -> Option<&[u8]> {
    value.dereference().zend_str().map(|s| s.as_bytes())
}

// Key -----------------------------------------------------------------------------------

const KEY_METHODS: &[Method] = &[
    Method { name: "getDescription", handler: key_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method {
        name: "__construct",
        handler: key_construct,
        flags: PUBLIC,
        params: &[("allowInternal", MAY_BE_BOOL, Some("false")), ("maxLength", MAY_BE_LONG, Some(MAX_LENGTH_DEFAULT))],
        returns: 0,
    },
    Method { name: "isValid", handler: key_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
];

fn key_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    let body = Declared { private: false, readonly: false, promoted: false };
    let promoted = Declared { private: false, readonly: true, promoted: true };
    engine::declare_property(class, &MESSAGE, PropertyType::Mask(MAY_BE_STRING), body, None);
    engine::declare_property(class, &ALLOW_INTERNAL, PropertyType::Mask(MAY_BE_BOOL), promoted, None);
    engine::declare_property(class, &MAX_LENGTH, PropertyType::Mask(MAY_BE_LONG), promoted, None);
    KEY.store(class, Ordering::Release);
    leaf::patch(class, KEY_METHODS, tables, &[]);
}

/// The constructor's arguments from `offset` on (`$allowInternal` when `with_internal`, then
/// `$maxLength`), with their defaults; `None` with the `TypeError` pending.
fn arguments(ex: &mut ExecuteData, with_internal: bool) -> Option<(bool, i64)> {
    let mut at = 0;
    let mut allow_internal = false;
    if with_internal {
        if let Some(arg) = engine::arg(ex, 0) {
            allow_internal = bool_arg(ex, 1, arg)?;
        }
        at = 1;
    }
    let max_length = match engine::arg(ex, at) {
        Some(arg) => long_arg(ex, u32::try_from(at + 1).unwrap_or(u32::MAX), arg)?,
        None => validator::MAX_UID_DEFAULT_LENGTH,
    };
    Some((allow_internal, max_length))
}

/// `Key::__construct($allowInternal, $maxLength)` on `this`: the promoted properties, then
/// `$message`. `None` with the exception pending (a second construction's readonly `Error`).
fn key_init(this: &mut ZendObject, allow_internal: bool, max_length: i64) -> Option<()> {
    let scope = scope(this);
    let mut value = Zval::new();
    value.set_bool(allow_internal);
    engine::initialize(scope, this, &ALLOW_INTERNAL, value);
    if engine::has_exception() {
        return None;
    }
    let mut value = Zval::new();
    value.set_long(max_length);
    engine::initialize(scope, this, &MAX_LENGTH, value);
    if engine::has_exception() {
        return None;
    }
    set_message(scope, this, validator::key_description, max_length)
}

/// `$this->message = ` the message `describe` writes for `max_length`.
fn set_message(
    scope: &ClassEntry,
    this: &mut ZendObject,
    describe: fn(i64, &mut Vec<u8>),
    max_length: i64,
) -> Option<()> {
    let mut message = Vec::with_capacity(160);
    describe(max_length, &mut message);
    engine::set(scope, this, &MESSAGE, engine::string(&message));
    (!engine::has_exception()).then_some(())
}

extern "C" fn key_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let Some((allow_internal, max_length)) = arguments(ex, true) else { return };
    if let Some(this) = this(ex) {
        key_init(this, allow_internal, max_length);
    }
}

extern "C" fn key_description(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(this) = this(ex)
        && let Some(message) = get(scope(this), this, &MESSAGE)
    {
        *retval = message;
    }
}

/// `Key::isValid($value)` on `this`, reading `$allowInternal` and `$maxLength` only where PHP
/// does; `None` with an exception pending (an uninitialized property).
fn key_valid(this: &mut ZendObject, value: &Zval) -> Option<bool> {
    let Some(subject) = bytes(value) else { return Some(false) };
    match validator::shape(subject) {
        Shape::Refused | Shape::Invalid => Some(false),
        Shape::Internal => {
            let allow = get(scope(this), this, &ALLOW_INTERNAL)?;
            Some(engine::truthy(&allow) && validator::internal(subject))
        }
        Shape::Plain => {
            let max_length = get(scope(this), this, &MAX_LENGTH)?;
            Some(validator::fits(subject, engine::long_value(&max_length)))
        }
    }
}

extern "C" fn key_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Database\\Validator\\Key::isValid") else { return };
    let Some(this) = this(ex) else { return };
    if let Some(valid) = key_valid(this, value) {
        set_bool(retval, valid);
    }
}

// Label ---------------------------------------------------------------------------------

const LABEL_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: label_construct,
        flags: PUBLIC,
        params: &[("allowInternal", MAY_BE_BOOL, Some("false")), ("maxLength", MAY_BE_LONG, Some(MAX_LENGTH_DEFAULT))],
        returns: 0,
    },
    Method { name: "isValid", handler: label_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn label_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    LABEL.store(class, Ordering::Release);
    // `__construct(bool $allowInternal = false, int $maxLength = …)` passes both to `Key`'s.
    leaf::patch(class, LABEL_METHODS, tables, &["allowInternal", "maxLength"]);
}

extern "C" fn label_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let Some((allow_internal, max_length)) = arguments(ex, true) else { return };
    let Some(this) = this(ex) else { return };
    // `parent::__construct($allowInternal, $maxLength)`, then its own message.
    if key_init(this, allow_internal, max_length).is_some() {
        set_message(scope(this), this, validator::label_description, max_length);
    }
}

extern "C" fn label_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Database\\Validator\\Label::isValid") else { return };
    let Some(this) = this(ex) else { return };
    // `parent::isValid($value)`, then only `A-Z`, `a-z` and `0-9`.
    if let Some(valid) = key_valid(this, value) {
        set_bool(retval, valid && bytes(value).is_some_and(validator::alphanumeric));
    }
}

// UID -----------------------------------------------------------------------------------

const UID_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: uid_construct,
        flags: PUBLIC,
        params: &[("maxLength", MAY_BE_LONG, Some(MAX_LENGTH_DEFAULT))],
        returns: 0,
    },
    Method { name: "getDescription", handler: uid_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
];

fn uid_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    UID.store(class, Ordering::Release);
    leaf::patch(class, UID_METHODS, tables, &["maxLength"]);
}

extern "C" fn uid_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let Some((_, max_length)) = arguments(ex, false) else { return };
    // `parent::__construct(false, $maxLength)`.
    if let Some(this) = this(ex) {
        key_init(this, false, max_length);
    }
}

extern "C" fn uid_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(max_length) = get(scope(this), this, &MAX_LENGTH) else { return };
    set_built(retval, |out, _| {
        validator::uid_description(engine::long_value(&max_length), out);
        Some(())
    });
}

/// Registers `Key`, then `Label` and `UID`, which extend it. Called from the module's startup
/// (MINIT), after `Utopia\Validator\Validator`.
pub fn register() -> ext_php_rs::error::Result<()> {
    let base = (crate::validators::base_class as fn() -> &'static ClassEntry, crate::validators::BASE);
    build(KEY_NAME, base, KEY_METHODS, key_register)?;
    build(LABEL_NAME, (key_class, KEY_NAME), LABEL_METHODS, label_register)?;
    build(UID_NAME, (key_class, KEY_NAME), UID_METHODS, uid_register)
}
