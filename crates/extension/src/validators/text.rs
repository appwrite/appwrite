//! The text leaf validators of `packages/validators`: `Text`, `Identifier` (a `Text`),
//! `WhiteList`, `Contains` and `Label`, over [`utopia_validators`], behind `validators-text`
//! (and `validators`, whose base they extend).
//!
//! Built by hand like the scalar batch ([`super::scalar`]): the state each PHP constructor
//! keeps lives in real typed protected properties, declared as `packages/validators` declares
//! them, and every method reads them when PHP reads them, so a subclass that leaves them
//! uninitialized gets the same `Error` at the same point. Comparisons with the PHP values a
//! validator holds (the loose `in_array()` over an allow list or a white list, `(string)` of
//! a pattern or a list item, `implode()`) go through the engine's own functions, so type
//! juggling, `__toString()`, warnings and errors are PHP's.

use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::args::ArgInfoTables;
use ext_php_rs::exception::throw;
use ext_php_rs::types::{ZendHashTable, ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData};

use super::engine::{
    self, MAY_BE_ARRAY, MAY_BE_BOOL, MAY_BE_LONG, MAY_BE_STRING, Property, array_arg, bool_arg, get, long_arg, set,
    str_arg, string, too_few,
};
use super::leaf::{
    self, Method, PUBLIC, VALUE, VALUE_MIXED, build, is_array, set_bool, set_str, stored, this, type_string, value_arg,
};

/// The base's constants, which the classes inherit as they are registered, and which PHP
/// lists after a class's own.
const INHERITED: [&str; 7] =
    ["TYPE_BOOLEAN", "TYPE_INTEGER", "TYPE_FLOAT", "TYPE_STRING", "TYPE_ARRAY", "TYPE_OBJECT", "TYPE_MIXED"];

/// A string argument's bytes, when it is a string.
fn bytes(value: &Zval) -> Option<&[u8]> {
    value.dereference().zend_str().map(|s| s.as_bytes())
}

/// An empty array.
fn empty_array() -> Zval {
    let mut zval = Zval::new();
    zval.set_hashtable(ZendHashTable::new());
    zval
}

fn bool_zval(value: bool) -> Zval {
    let mut zval = Zval::new();
    zval.set_bool(value);
    zval
}

fn long_zval(value: i64) -> Zval {
    let mut zval = Zval::new();
    zval.set_long(value);
    zval
}

// Text ----------------------------------------------------------------------------------

static TEXT: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static TEXT_LENGTH: Property = Property::new("length");
static TEXT_MIN: Property = Property::new("min");
static TEXT_ALLOW_LIST: Property = Property::new("allowList");
static TEXT_NON_BLANK: Property = Property::new("requireNonBlank");

const NUMBERS: [&str; 10] = ["0", "1", "2", "3", "4", "5", "6", "7", "8", "9"];
const ALPHABET_UPPER: [&str; 26] = [
    "A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R", "S", "T", "U", "V", "W",
    "X", "Y", "Z",
];
const ALPHABET_LOWER: [&str; 26] = [
    "a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v", "w",
    "x", "y", "z",
];

const TEXT_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: text_construct,
        flags: PUBLIC,
        params: &[
            ("length", MAY_BE_LONG, None),
            ("min", MAY_BE_LONG, Some("1")),
            ("allowList", MAY_BE_ARRAY, Some("[]")),
            ("requireNonBlank", MAY_BE_BOOL, Some("false")),
        ],
        returns: 0,
    },
    Method { name: "getDescription", handler: text_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: text_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn text_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare_constant(class, "NUMBERS", engine::string_list(&NUMBERS), 0);
    engine::declare_constant(class, "ALPHABET_UPPER", engine::string_list(&ALPHABET_UPPER), 0);
    engine::declare_constant(class, "ALPHABET_LOWER", engine::string_list(&ALPHABET_LOWER), 0);
    engine::inherited_constants_last(class, &INHERITED);
    engine::declare(class, &TEXT_LENGTH, MAY_BE_LONG, None);
    engine::declare(class, &TEXT_MIN, MAY_BE_LONG, None);
    engine::declare(class, &TEXT_ALLOW_LIST, MAY_BE_ARRAY, None);
    engine::declare(class, &TEXT_NON_BLANK, MAY_BE_BOOL, None);
    TEXT.store(class, Ordering::Release);
    leaf::patch(class, TEXT_METHODS, tables, &[]);
}

fn text_class() -> &'static ClassEntry {
    stored(&TEXT)
}

/// Sets `Text`'s four properties on `this`, from `scope`.
fn text_init(scope: &ClassEntry, this: &mut ZendObject, length: i64, min: i64, allow_list: Zval, non_blank: bool) {
    set(scope, this, &TEXT_LENGTH, long_zval(length));
    set(scope, this, &TEXT_MIN, long_zval(min));
    set(scope, this, &TEXT_ALLOW_LIST, allow_list);
    set(scope, this, &TEXT_NON_BLANK, bool_zval(non_blank));
}

extern "C" fn text_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let passed = engine::args_count(ex);
    let Some(length) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\Validator\\Text::__construct", passed, 1, 4);
        return;
    };
    let Some(length) = long_arg(ex, 1, length) else { return };
    let min = match engine::arg(ex, 1) {
        Some(arg) => match long_arg(ex, 2, arg) {
            Some(min) => min,
            None => return,
        },
        None => 1,
    };
    let allow_list = match engine::arg(ex, 2) {
        Some(arg) => match array_arg(ex, 3, arg) {
            Some(list) => list,
            None => return,
        },
        None => empty_array(),
    };
    let non_blank = match engine::arg(ex, 3) {
        Some(arg) => match bool_arg(ex, 4, arg) {
            Some(b) => b,
            None => return,
        },
        None => false,
    };
    if let Some(this) = this(ex) {
        text_init(text_class(), this, length, min, allow_list, non_blank);
    }
}

extern "C" fn text_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let scope = text_class();
    let Some(this) = this(ex) else { return };
    let Some(min) = get(scope, this, &TEXT_MIN) else { return };
    let Some(length) = get(scope, this, &TEXT_LENGTH) else { return };
    let Some(allow_list) = get(scope, this, &TEXT_ALLOW_LIST) else { return };
    let joined = if allow_list.array().is_some_and(|list| !list.is_empty()) {
        match engine::implode(&allow_list) {
            Some(joined) => Some(joined),
            None => return,
        }
    } else {
        None
    };
    let Some(non_blank) = get(scope, this, &TEXT_NON_BLANK) else { return };
    let (min, length) = (min.long().unwrap_or(0), length.long().unwrap_or(0));
    set_str(retval, &utopia_validators::text_description(length, min, joined.as_deref(), engine::truthy(&non_blank)));
}

/// `Text::isValid()` on `this`, from `scope` (`Text`, or `Identifier` for its own objects:
/// the properties are `Text`'s either way); `None` with an exception pending.
fn text_valid(scope: &ClassEntry, this: &mut ZendObject, value: &Zval) -> Option<bool> {
    let Some(subject) = bytes(value) else { return Some(false) };
    let non_blank = get(scope, this, &TEXT_NON_BLANK)?;
    if engine::truthy(&non_blank) && utopia_validators::is_blank(subject) {
        return Some(false);
    }
    let chars = utopia_validators::chars(subject);
    let min = get(scope, this, &TEXT_MIN)?.long().unwrap_or(0);
    if chars < min {
        return Some(false);
    }
    let length = get(scope, this, &TEXT_LENGTH)?.long().unwrap_or(0);
    if chars > length && length != 0 {
        return Some(false);
    }
    let allow_list = get(scope, this, &TEXT_ALLOW_LIST)?;
    let Some(list) = allow_list.array().filter(|list| !list.is_empty()) else { return Some(true) };
    if let Some(allowed) = allowed_bytes(list) {
        return Some(subject.iter().all(|byte| allowed[usize::from(*byte)]));
    }
    // `in_array($char, $this->allowList)` for each byte, compared loosely, in the list's
    // order (an item that is not a string may throw as it is compared).
    for byte in subject {
        let char = string(&[*byte]);
        let mut found = false;
        for item in engine::values(list) {
            if engine::loose_equal(&char, item)? {
                found = true;
                break;
            }
        }
        if !found {
            return Some(false);
        }
    }
    Some(true)
}

/// The bytes a one-character string loosely equals some item of `list` for, when every item
/// is a string: comparing strings never throws, so the list's order does not matter. A
/// one-byte item allows its byte; a longer one can only equal a digit, as numbers (`'01'`,
/// `'1.0'`), which the engine compares. `None` when an item is not a string.
fn allowed_bytes(list: &ZendHashTable) -> Option<[bool; 256]> {
    let mut allowed = [false; 256];
    for item in engine::values(list) {
        let item = item.dereference();
        let s = item.zend_str()?.as_bytes();
        if let [byte] = s {
            allowed[usize::from(*byte)] = true;
        } else if s.iter().any(u8::is_ascii_digit) {
            for digit in b'0'..=b'9' {
                if !allowed[usize::from(digit)] && engine::loose_equal(&string(&[digit]), item)? {
                    allowed[usize::from(digit)] = true;
                }
            }
        }
    }
    Some(allowed)
}

extern "C" fn text_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Text::isValid") else { return };
    let Some(this) = this(ex) else { return };
    if let Some(valid) = text_valid(text_class(), this, value) {
        set_bool(retval, valid);
    }
}

// Identifier ----------------------------------------------------------------------------

static IDENTIFIER: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());

const IDENTIFIER_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: identifier_construct,
        flags: PUBLIC,
        params: &[("length", MAY_BE_LONG, Some("0"))],
        returns: 0,
    },
    Method {
        name: "getDescription",
        handler: identifier_description,
        flags: PUBLIC,
        params: &[],
        returns: MAY_BE_STRING,
    },
    Method { name: "isValid", handler: identifier_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn identifier_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    IDENTIFIER.store(class, Ordering::Release);
    // `__construct(int $length = 0)` declares no property: it passes it to `Text`'s.
    leaf::patch(class, IDENTIFIER_METHODS, tables, &["length"]);
}

extern "C" fn identifier_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let length = match engine::arg(ex, 0) {
        Some(arg) => match long_arg(ex, 1, arg) {
            Some(length) => length,
            None => return,
        },
        None => 0,
    };
    // `parent::__construct($length, 1)`.
    if let Some(this) = this(ex) {
        text_init(stored(&IDENTIFIER), this, length, 1, empty_array(), false);
    }
}

extern "C" fn identifier_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(length) = get(stored(&IDENTIFIER), this, &TEXT_LENGTH) else { return };
    set_str(retval, &utopia_validators::identifier_description(length.long().unwrap_or(0)));
}

extern "C" fn identifier_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Identifier::isValid") else { return };
    let Some(this) = this(ex) else { return };
    // `parent::isValid($value) && preg_match('/^[A-Za-z_]\w*$/D', (string) $value) === 1`.
    let Some(valid) = text_valid(stored(&IDENTIFIER), this, value) else { return };
    set_bool(retval, valid && bytes(value).is_some_and(utopia_validators::identifier));
}

// WhiteList -----------------------------------------------------------------------------

static WHITE_LIST: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static WHITE_LIST_LIST: Property = Property::new("list");
static WHITE_LIST_STRICT: Property = Property::new("strict");
static WHITE_LIST_TYPE: Property = Property::new("type");

const WHITE_LIST_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: white_list_construct,
        flags: PUBLIC,
        params: &[
            ("list", MAY_BE_ARRAY, None),
            ("strict", MAY_BE_BOOL, Some("false")),
            ("type", MAY_BE_STRING, Some("self::TYPE_STRING")),
        ],
        returns: 0,
    },
    Method { name: "getList", handler: white_list_get_list, flags: PUBLIC, params: &[], returns: MAY_BE_ARRAY },
    Method {
        name: "getDescription",
        handler: white_list_description,
        flags: PUBLIC,
        params: &[],
        returns: MAY_BE_STRING,
    },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: white_list_type, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: white_list_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn white_list_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare(class, &WHITE_LIST_LIST, MAY_BE_ARRAY, None);
    engine::declare(class, &WHITE_LIST_STRICT, MAY_BE_BOOL, None);
    engine::declare(class, &WHITE_LIST_TYPE, MAY_BE_STRING, None);
    WHITE_LIST.store(class, Ordering::Release);
    leaf::patch(class, WHITE_LIST_METHODS, tables, &[]);
}

/// `$list` with every value as `strtolower((string) $value)`, the keys kept, as the PHP
/// constructor rewrites it when it is not strict. A conversion that throws (an object
/// without `__toString()`) leaves it and the rest as they were, with the exception pending.
fn lowered(list: &Zval) -> Zval {
    engine::map_values(list, |value| {
        let value = value.dereference();
        // A string already in lower case stays as it is.
        if let Some(s) = value.zend_str()
            && !s.as_bytes().iter().any(u8::is_ascii_uppercase)
        {
            return Some(None);
        }
        let converted = engine::to_string(value)?;
        Some(Some(string(&utopia_validators::ascii_lower(&converted))))
    })
    .0
}

extern "C" fn white_list_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let scope = stored(&WHITE_LIST);
    let passed = engine::args_count(ex);
    let Some(list) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\Validator\\WhiteList::__construct", passed, 1, 3);
        return;
    };
    let Some(list) = array_arg(ex, 1, list) else { return };
    let strict = match engine::arg(ex, 1) {
        Some(arg) => match bool_arg(ex, 2, arg) {
            Some(b) => b,
            None => return,
        },
        None => false,
    };
    let kind = match engine::arg(ex, 2) {
        Some(arg) => match str_arg(ex, 3, arg) {
            Some(kind) => kind,
            None => return,
        },
        None => b"string".to_vec(),
    };
    let Some(this) = this(ex) else { return };
    // The promoted properties first, then the body.
    set(scope, this, &WHITE_LIST_LIST, list.shallow_clone());
    set(scope, this, &WHITE_LIST_STRICT, bool_zval(strict));
    set(scope, this, &WHITE_LIST_TYPE, string(&kind));
    if !strict {
        set(scope, this, &WHITE_LIST_LIST, lowered(&list));
    }
}

fn white_list_property(ex: &mut ExecuteData, retval: &mut Zval, property: &Property) {
    if let Some(this) = this(ex)
        && let Some(value) = get(stored(&WHITE_LIST), this, property)
    {
        *retval = value;
    }
}

extern "C" fn white_list_get_list(ex: &mut ExecuteData, retval: &mut Zval) {
    white_list_property(ex, retval, &WHITE_LIST_LIST);
}
extern "C" fn white_list_type(ex: &mut ExecuteData, retval: &mut Zval) {
    white_list_property(ex, retval, &WHITE_LIST_TYPE);
}
extern "C" fn white_list_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(list) = get(stored(&WHITE_LIST), this, &WHITE_LIST_LIST) else { return };
    if let Some(joined) = engine::implode(&list) {
        set_str(retval, &utopia_validators::white_list_description(&joined));
    }
}

extern "C" fn white_list_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\WhiteList::isValid") else { return };
    if value.dereference().is_array() {
        set_bool(retval, false);
        return;
    }
    let scope = stored(&WHITE_LIST);
    let Some(this) = this(ex) else { return };
    let Some(strict) = get(scope, this, &WHITE_LIST_STRICT) else { return };
    let strict = engine::truthy(&strict);
    // `strtolower((string) $value)` when not strict, compared by bytes where it can be.
    let lowered = if strict {
        None
    } else {
        match engine::to_string(value) {
            Some(mut converted) => {
                converted.make_ascii_lowercase();
                Some(converted)
            }
            None => return,
        }
    };
    let Some(list) = get(scope, this, &WHITE_LIST_LIST) else { return };
    let mut found = false;
    if let Some(list) = list.array() {
        for item in engine::values(list) {
            let equal = match &lowered {
                Some(needle) => match engine::loose_equal_bytes(needle, item) {
                    Some(equal) => equal,
                    None => return,
                },
                None => engine::identical(value.dereference(), item),
            };
            if equal {
                found = true;
                break;
            }
        }
    }
    set_bool(retval, found);
}

// Contains ------------------------------------------------------------------------------

static CONTAINS: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static CONTAINS_PATTERNS: Property = Property::new("patterns");
static CONTAINS_STRICT: Property = Property::new("strict");

const CONTAINS_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: contains_construct,
        flags: PUBLIC,
        params: &[("patterns", MAY_BE_ARRAY, None), ("strict", MAY_BE_BOOL, Some("false"))],
        returns: 0,
    },
    Method {
        name: "getDescription",
        handler: contains_description,
        flags: PUBLIC,
        params: &[],
        returns: MAY_BE_STRING,
    },
    Method { name: "isValid", handler: contains_is_valid, flags: PUBLIC, params: VALUE, returns: MAY_BE_BOOL },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
];

fn contains_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    // `protected array $patterns;` in the class body, then the promoted `$strict`.
    engine::declare_uninitialized(class, &CONTAINS_PATTERNS, MAY_BE_ARRAY);
    engine::declare(class, &CONTAINS_STRICT, MAY_BE_BOOL, None);
    CONTAINS.store(class, Ordering::Release);
    leaf::patch(class, CONTAINS_METHODS, tables, &["patterns"]);
}

extern "C" fn contains_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let scope = stored(&CONTAINS);
    let passed = engine::args_count(ex);
    let Some(patterns) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\Validator\\Contains::__construct", passed, 1, 2);
        return;
    };
    let Some(patterns) = array_arg(ex, 1, patterns) else { return };
    let strict = match engine::arg(ex, 1) {
        Some(arg) => match bool_arg(ex, 2, arg) {
            Some(b) => b,
            None => return,
        },
        None => false,
    };
    let Some(this) = this(ex) else { return };
    set(scope, this, &CONTAINS_STRICT, bool_zval(strict));
    if patterns.array().is_none_or(ZendHashTable::is_empty) {
        let class = ClassEntry::try_find("InvalidArgumentException").unwrap_or_else(ext_php_rs::zend::ce::exception);
        let _ = throw(class, "Patterns array cannot be empty");
        return;
    }
    set(scope, this, &CONTAINS_PATTERNS, patterns);
}

extern "C" fn contains_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let scope = stored(&CONTAINS);
    let Some(this) = this(ex) else { return };
    let Some(patterns) = get(scope, this, &CONTAINS_PATTERNS) else { return };
    let Some(joined) = engine::implode(&patterns) else { return };
    let Some(strict) = get(scope, this, &CONTAINS_STRICT) else { return };
    set_str(retval, &utopia_validators::contains_description(&joined, engine::truthy(&strict)));
}

extern "C" fn contains_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Contains::isValid") else { return };
    let Some(subject) = bytes(value) else {
        set_bool(retval, false);
        return;
    };
    let scope = stored(&CONTAINS);
    let Some(this) = this(ex) else { return };
    let Some(strict) = get(scope, this, &CONTAINS_STRICT) else { return };
    let strict = engine::truthy(&strict);
    let haystack = if strict { subject.to_vec() } else { utopia_validators::lower(subject) };
    let Some(patterns) = get(scope, this, &CONTAINS_PATTERNS) else { return };
    if let Some(patterns) = patterns.array() {
        for pattern in engine::values(patterns) {
            let Some(needle) = engine::to_string(pattern) else { return };
            let needle = if strict { needle } else { utopia_validators::lower(&needle) };
            if utopia_validators::contains(&haystack, &needle) {
                set_bool(retval, true);
                return;
            }
        }
    }
    set_bool(retval, false);
}

// Label ---------------------------------------------------------------------------------

const LABEL_METHODS: &[Method] = &[
    Method { name: "getDescription", handler: label_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: label_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn label_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare_constant(class, "MAX_LENGTH", long_zval(utopia_validators::LABEL_MAX_LENGTH), MAY_BE_LONG);
    engine::inherited_constants_last(class, &INHERITED);
    leaf::patch(class, LABEL_METHODS, tables, &[]);
}

extern "C" fn label_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, &utopia_validators::label_description());
}
extern "C" fn label_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Label::isValid") else { return };
    set_bool(retval, bytes(value).is_some_and(utopia_validators::label));
}

/// Registers the batch's classes. Called from the module's startup (MINIT), after the base.
pub fn register() -> ext_php_rs::error::Result<()> {
    let base = (super::base_class as fn() -> &'static ClassEntry, super::BASE);
    build("Utopia\\Validator\\Text", base, TEXT_METHODS, text_register)?;
    build(
        "Utopia\\Validator\\Identifier",
        (text_class, "Utopia\\Validator\\Text"),
        IDENTIFIER_METHODS,
        identifier_register,
    )?;
    build("Utopia\\Validator\\WhiteList", base, WHITE_LIST_METHODS, white_list_register)?;
    build("Utopia\\Validator\\Contains", base, CONTAINS_METHODS, contains_register)?;
    build("Utopia\\Validator\\Label", base, LABEL_METHODS, label_register)
}
