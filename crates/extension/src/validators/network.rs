//! The network leaf validators of `packages/validators`: `Hostname`, `Domain`, `Host`, `IP`,
//! `URL` and `Phone`, over [`utopia_validators`], extending the base.
//!
//! Built by hand like the scalar and text batches: each PHP constructor's state lives in real
//! typed protected properties, declared as `packages/validators` declares them, and every
//! method reads them when PHP reads them. `filter_var()`, `parse_url()` and
//! `Uri\Rfc3986\Uri::parse()` run as [`php_std`]'s ports; what PHP does with the PHP values a
//! validator holds (`Hostname`'s allow-list entries that are not strings, `Domain`'s
//! restrictions, `(string)` of a `Stringable` value) goes through the engine, so its
//! coercions, warnings and errors are PHP's.

use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::args::ArgInfoTables;
use ext_php_rs::exception::throw;
use ext_php_rs::flags::MethodFlags;
use ext_php_rs::types::{ZendHashTable, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData, ce};
use utopia_validators::{FilterValue, IpType, UrlComponent};

use super::engine::{
    self, Literal, MAY_BE_ARRAY, MAY_BE_BOOL, MAY_BE_LONG, MAY_BE_STRING, Property, array_arg, bool_arg, get, set,
    str_arg, str_zval_arg, string, too_few,
};
use super::leaf::{
    self, Method, PRIVATE, PUBLIC, VALUE, VALUE_MIXED, build, is_array, set_bool, set_built, set_literal, set_str,
    stored, this, type_string, value_arg,
};

/// The descriptions the classes return as they are.
static HOSTNAME_DESCRIPTION: Literal = Literal::new(utopia_validators::HOSTNAME_DESCRIPTION);
static DOMAIN_DESCRIPTION: Literal = Literal::new(utopia_validators::DOMAIN_DESCRIPTION);
static IP_DESCRIPTION: Literal = Literal::new(utopia_validators::IP_DESCRIPTION);
static PHONE_DESCRIPTION: Literal = Literal::new(utopia_validators::PHONE_DESCRIPTION);
/// `IP::ALL`, the constructor's default.
static IP_ALL: Literal = Literal::new("all");

/// `MAY_BE_NULL`: a nullable type's `null`.
const MAY_BE_NULL: u32 = 1 << 1;

/// A public static method.
const PUBLIC_STATIC: MethodFlags = MethodFlags::Public.union(MethodFlags::Static);

/// The base's constants, which a class inherits as it is registered, and which PHP lists
/// after a class's own.
const INHERITED: [&str; 7] =
    ["TYPE_BOOLEAN", "TYPE_INTEGER", "TYPE_FLOAT", "TYPE_STRING", "TYPE_ARRAY", "TYPE_OBJECT", "TYPE_MIXED"];

/// A value's bytes, when it is a string.
fn bytes(value: &Zval) -> Option<&[u8]> {
    value.dereference().zend_str().map(|s| s.as_bytes())
}

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

fn false_zval() -> Zval {
    bool_zval(false)
}

/// An optional `bool` constructor argument `i` (from 0): `default` when it was not passed,
/// `None` with the argument's error pending.
fn bool_or(ex: &mut ExecuteData, i: usize, default: bool) -> Option<bool> {
    match engine::arg(ex, i) {
        Some(arg) => bool_arg(ex, u32::try_from(i + 1).unwrap_or(u32::MAX), arg),
        None => Some(default),
    }
}

/// An optional `array` constructor argument `i` (from 0): `[]` when it was not passed.
fn array_or_empty(ex: &mut ExecuteData, i: usize) -> Option<Zval> {
    match engine::arg(ex, i) {
        Some(arg) => array_arg(ex, u32::try_from(i + 1).unwrap_or(u32::MAX), arg),
        None => Some(empty_array()),
    }
}

/// `$value` as `filter_var()` takes it: a scalar as it is, an array (which a filter without
/// `FILTER_REQUIRE_ARRAY` rejects), and an object converted by its `__toString()` (called
/// once, as `filter_var()` calls it) or rejected when it has none; `None` with an exception
/// pending (a `__toString()` that throws).
fn filter_value(value: &Zval) -> Option<FilterValue> {
    let value = value.dereference();
    Some(if value.is_null() {
        FilterValue::Null
    } else if let Some(b) = value.bool() {
        FilterValue::Bool(b)
    } else if let Some(i) = value.long() {
        FilterValue::Int(i)
    } else if let Some(f) = value.double() {
        // `filter_var()` converts the value to a string, and PHP warns as it converts NAN.
        if f.is_nan() {
            engine::warning("unexpected NAN value was coerced to string");
        }
        FilterValue::Float(f)
    } else if let Some(s) = value.zend_str() {
        FilterValue::Str(s.as_bytes().to_vec())
    } else if value.is_array() {
        FilterValue::Array(Vec::new())
    } else if let Some(object) = value.object() {
        if object.get_class_entry().__tostring.is_null() {
            FilterValue::Object
        } else {
            FilterValue::Str(engine::to_string(value)?)
        }
    } else {
        FilterValue::Str(engine::to_string(value)?)
    })
}

/// `filter_var($value, FILTER_VALIDATE_URL)` after `URL::isValid()`'s IRI step: a string's
/// bytes above 127 percent-encoded, any other value as it is; `None` with an exception pending.
fn url_passes(value: &Zval) -> Option<bool> {
    match bytes(value) {
        Some(s) => Some(utopia_validators::url_str(&utopia_validators::url_ascii(s))),
        None => Some(utopia_validators::url(&filter_value(value)?)),
    }
}

/// `parse_url($url, $component)` as PHP returns it: `false`, `null` or the string.
fn url_component(url: &[u8], component: UrlComponent) -> Zval {
    match utopia_validators::url_component(url, component) {
        None => false_zval(),
        Some(None) => Zval::new(),
        Some(Some(part)) => string(&part),
    }
}

/// PHP `strtolower((string) parse_url($url, $component))`: `false` and `null` cast to `''`.
fn url_component_lower(url: &[u8], component: UrlComponent) -> Vec<u8> {
    utopia_validators::url_component(url, component).flatten().map(|part| part.to_ascii_lowercase()).unwrap_or_default()
}

// Hostname ------------------------------------------------------------------------------

static HOSTNAME: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static HOSTNAME_ALLOW_LIST: Property = Property::new("allowList");

const HOSTNAME_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: hostname_construct,
        flags: PUBLIC,
        params: &[("allowList", MAY_BE_ARRAY, Some("[]"))],
        returns: 0,
    },
    Method {
        name: "getDescription",
        handler: hostname_description,
        flags: PUBLIC,
        params: &[],
        returns: MAY_BE_STRING,
    },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: hostname_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn hostname_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare(class, &HOSTNAME_ALLOW_LIST, MAY_BE_ARRAY, None);
    HOSTNAME.store(class, Ordering::Release);
    leaf::patch(class, HOSTNAME_METHODS, tables, &[]);
}

extern "C" fn hostname_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let Some(allow_list) = array_or_empty(ex, 0) else { return };
    if let Some(this) = this(ex) {
        set(stored(&HOSTNAME), this, &HOSTNAME_ALLOW_LIST, allow_list);
    }
}

extern "C" fn hostname_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_literal(retval, &HOSTNAME_DESCRIPTION);
}

/// `Hostname::isValid()`'s allow-list loop over `list` for the hostname `value`. An entry that
/// is a string is compared here; any other goes through `str_starts_with()` and `substr()` as
/// `Hostname.php` (a file without strict types) calls them, so a `Stringable` converts, `null`
/// raises its deprecation and an array its `TypeError`.
fn hostname_listed(list: &Zval, value: &[u8]) -> Option<bool> {
    let Some(list) = list.array() else { return Some(true) };
    if list.is_empty() {
        return Some(true);
    }
    for item in engine::values(list) {
        let item = item.dereference();
        if let Some(allowed) = item.zend_str() {
            if utopia_validators::hostname_allowed(value, allowed.as_bytes()) {
                return Some(true);
            }
            continue;
        }
        // `$value === $item` and `$item === '*'` are false for an entry that is not a string.
        if engine::truthy(&engine::call("str_starts_with", &[item, &string(b"*")])?) {
            let rest = engine::call("substr", &[item, &long_zval(1)])?;
            if bytes(&rest).is_some_and(|suffix| value.ends_with(suffix)) {
                return Some(true);
            }
        }
    }
    Some(false)
}

/// `Hostname::isValid()` of `value` with `$this->allowList` read (from `scope`) only when PHP
/// reads it, after the format checks.
fn hostname_valid(value: &Zval, list: impl FnOnce() -> Option<Zval>) -> Option<bool> {
    let Some(value) = bytes(value) else { return Some(false) };
    if !utopia_validators::hostname(value) {
        return Some(false);
    }
    hostname_listed(&list()?, value)
}

extern "C" fn hostname_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Hostname::isValid") else { return };
    let Some(this) = this(ex) else { return };
    if let Some(valid) = hostname_valid(value, || get(stored(&HOSTNAME), this, &HOSTNAME_ALLOW_LIST)) {
        set_bool(retval, valid);
    }
}

// Domain --------------------------------------------------------------------------------

static DOMAIN: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static DOMAIN_RESTRICTIONS: Property = Property::new("restrictions");
static DOMAIN_HOSTNAMES: Property = Property::new("hostnames");
static DOMAIN_ALLOW_EMPTY: Property = Property::new("allowEmpty");
static RESTRICTION_HOSTNAME: Literal = Literal::new("hostname");
static RESTRICTION_LEVELS: Literal = Literal::new("levels");
static RESTRICTION_PREFIX_DENY_LIST: Literal = Literal::new("prefixDenyList");

const DOMAIN_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: domain_construct,
        flags: PUBLIC,
        params: &[
            ("restrictions", MAY_BE_ARRAY, Some("[]")),
            ("hostnames", MAY_BE_BOOL, Some("true")),
            ("allowEmpty", MAY_BE_BOOL, Some("false")),
        ],
        returns: 0,
    },
    Method {
        name: "createRestriction",
        handler: domain_create_restriction,
        flags: PUBLIC_STATIC,
        params: &[
            ("hostname", MAY_BE_STRING, None),
            ("levels", MAY_BE_LONG | MAY_BE_NULL, Some("null")),
            ("prefixDenyList", MAY_BE_ARRAY, Some("[]")),
        ],
        returns: MAY_BE_ARRAY,
    },
    Method { name: "getDescription", handler: domain_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: domain_is_valid, flags: PUBLIC, params: VALUE, returns: MAY_BE_BOOL },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
];

fn domain_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare(class, &DOMAIN_RESTRICTIONS, MAY_BE_ARRAY, None);
    engine::declare(class, &DOMAIN_HOSTNAMES, MAY_BE_BOOL, None);
    engine::declare(class, &DOMAIN_ALLOW_EMPTY, MAY_BE_BOOL, None);
    DOMAIN.store(class, Ordering::Release);
    leaf::patch(class, DOMAIN_METHODS, tables, &[]);
}

extern "C" fn domain_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let Some(restrictions) = array_or_empty(ex, 0) else { return };
    let Some(hostnames) = bool_or(ex, 1, true) else { return };
    let Some(allow_empty) = bool_or(ex, 2, false) else { return };
    if let Some(this) = this(ex) {
        let scope = stored(&DOMAIN);
        set(scope, this, &DOMAIN_RESTRICTIONS, restrictions);
        set(scope, this, &DOMAIN_HOSTNAMES, bool_zval(hostnames));
        set(scope, this, &DOMAIN_ALLOW_EMPTY, bool_zval(allow_empty));
    }
}

extern "C" fn domain_create_restriction(ex: &mut ExecuteData, retval: &mut Zval) {
    let passed = engine::args_count(ex);
    let Some(hostname) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\Validator\\Domain::createRestriction", passed, 1, 3);
        return;
    };
    let Some(hostname) = engine::str_zval_arg(ex, 1, hostname) else { return };
    // `?int $levels = null`: `null` passes as it is.
    let levels = match engine::arg(ex, 1) {
        Some(arg) if !arg.is_null() => match engine::long_arg_named(ex, 2, arg, "?int") {
            Some(levels) => long_zval(levels),
            None => return,
        },
        _ => Zval::new(),
    };
    let Some(prefix_deny_list) = array_or_empty(ex, 2) else { return };
    // `['hostname' => …, 'levels' => …, 'prefixDenyList' => …]`: the keys are interned, as a
    // literal's are, and the hostname is the string passed, shared.
    let (Some(hostname_key), Some(levels_key), Some(prefix_key)) =
        (RESTRICTION_HOSTNAME.zend_str(), RESTRICTION_LEVELS.zend_str(), RESTRICTION_PREFIX_DENY_LIST.zend_str())
    else {
        return;
    };
    let mut restriction = ZendHashTable::with_capacity(3);
    engine::update_name(&mut restriction, hostname_key, hostname);
    engine::update_name(&mut restriction, levels_key, levels);
    engine::update_name(&mut restriction, prefix_key, prefix_deny_list);
    retval.set_hashtable(restriction);
}

extern "C" fn domain_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_literal(retval, &DOMAIN_DESCRIPTION);
}

/// `foreach ($prefixDenyList as $deniedPrefix)` from `Domain`'s scope, calling `each` until it
/// answers `Some(true)`: an array's values, a `Traversable`'s, an object's properties visible
/// from `Domain` (`get_object_vars()` called from its method), and the engine's warning for
/// anything else. `None` with an exception pending.
fn for_each_prefix(list: &Zval, mut each: impl FnMut(&Zval) -> Option<bool>) -> Option<bool> {
    let list = list.dereference();
    let items = if list.is_array() {
        list.shallow_clone()
    } else if list.object().is_some() {
        if engine::truthy(&engine::call("is_iterable", &[list])?) {
            engine::call("iterator_to_array", &[list, &false_zval()])?
        } else {
            engine::call("get_object_vars", &[list])?
        }
    } else {
        engine::warning(&format!(
            "foreach() argument must be of type array|object, {} given",
            engine::value_name(list)
        ));
        return Some(false);
    };
    let Some(items) = items.array() else { return Some(false) };
    for item in engine::values(items) {
        if each(item)? {
            return Some(true);
        }
    }
    Some(false)
}

/// One of `Domain::isValid()`'s restrictions on the domain `value`: `Some(false)` when it
/// rejects the domain. Each key is read as PHP reads `$restriction['key']`, before any of them
/// is used.
fn domain_restriction(value: &[u8], restriction: &Zval) -> Option<bool> {
    // An array holding the three keys (as `createRestriction()` makes it) is read directly;
    // anything else through the engine, for the warnings and errors of a missing key, a value
    // that is not an array, or `ArrayAccess`.
    let element = |key: &str| restriction.dereference().array().and_then(|r| r.get(key)).map(Zval::shallow_clone);
    let (hostname, levels, prefix_deny_list) = match (element("hostname"), element("levels"), element("prefixDenyList"))
    {
        (Some(hostname), Some(levels), Some(prefix_deny_list)) => (hostname, levels, prefix_deny_list),
        _ => (
            engine::dimension(restriction, "hostname")?,
            engine::dimension(restriction, "levels")?,
            engine::dimension(restriction, "prefixDenyList")?,
        ),
    };
    let suffix = match bytes(&hostname) {
        Some(suffix) => value.ends_with(suffix),
        None => value.ends_with(&engine::to_string(&hostname)?),
    };
    if !suffix {
        return Some(true);
    }
    let levels = levels.dereference();
    if let Some(expected) = levels.long()
        && let Some(limit) = expected.checked_add(1)
    {
        if utopia_validators::explode_count(value, limit) != expected {
            return Some(false);
        }
    } else if !levels.is_null() {
        // `count(explode('.', $value, $levels + 1)) !== $levels`, in a file with strict types:
        // the limit must be an int.
        let limit = engine::add(levels, &long_zval(1))?;
        let Some(limit) = limit.long() else {
            let message =
                format!("explode(): Argument #3 ($limit) must be of type int, {} given", engine::value_name(&limit));
            let _ = throw(ce::type_error(), &message);
            return None;
        };
        let count = long_zval(utopia_validators::explode_count(value, limit));
        if !engine::identical(&count, levels) {
            return Some(false);
        }
    }
    if engine::truthy(&prefix_deny_list) {
        let denied = for_each_prefix(&prefix_deny_list, |prefix| match bytes(prefix) {
            Some(prefix) => Some(value.starts_with(prefix)),
            None => Some(value.starts_with(&engine::to_string(prefix)?)),
        })?;
        if denied {
            return Some(false);
        }
    }
    Some(true)
}

extern "C" fn domain_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Domain::isValid") else { return };
    let Some(this) = this(ex) else { return };
    let scope = stored(&DOMAIN);
    let Some(allow_empty) = get(scope, this, &DOMAIN_ALLOW_EMPTY) else { return };
    if engine::truthy(&allow_empty) && bytes(value) == Some(b"") {
        set_bool(retval, true);
        return;
    }
    // `empty($value)`, then `!is_string($value)`. PHP warns as it coerces NAN to a bool.
    if value.dereference().double().is_some_and(f64::is_nan) {
        engine::warning("unexpected NAN value was coerced to bool");
    }
    let Some(domain) = bytes(value).filter(|_| engine::truthy(value)) else {
        set_bool(retval, false);
        return;
    };
    let Some(hostnames) = get(scope, this, &DOMAIN_HOSTNAMES) else { return };
    if !utopia_validators::domain(domain, engine::truthy(&hostnames)) {
        set_bool(retval, false);
        return;
    }
    let Some(restrictions) = get(scope, this, &DOMAIN_RESTRICTIONS) else { return };
    if let Some(list) = restrictions.array() {
        for restriction in engine::values(list) {
            match domain_restriction(domain, restriction) {
                Some(true) => {}
                Some(false) => {
                    set_bool(retval, false);
                    return;
                }
                None => return,
            }
        }
    }
    set_bool(retval, true);
}

// Host ----------------------------------------------------------------------------------

static HOST: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static HOST_WHITELIST: Property = Property::new("whitelist");

const HOST_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: host_construct,
        flags: PUBLIC,
        params: &[("whitelist", MAY_BE_ARRAY, None)],
        returns: 0,
    },
    Method { name: "getDescription", handler: host_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: host_is_valid, flags: PUBLIC, params: VALUE, returns: MAY_BE_BOOL },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
];

fn host_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare(class, &HOST_WHITELIST, MAY_BE_ARRAY, None);
    HOST.store(class, Ordering::Release);
    leaf::patch(class, HOST_METHODS, tables, &[]);
}

extern "C" fn host_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let passed = engine::args_count(ex);
    let Some(whitelist) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\Validator\\Host::__construct", passed, 1, 1);
        return;
    };
    let Some(whitelist) = array_arg(ex, 1, whitelist) else { return };
    if let Some(this) = this(ex) {
        set(stored(&HOST), this, &HOST_WHITELIST, whitelist);
    }
}

extern "C" fn host_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(whitelist) = get(stored(&HOST), this, &HOST_WHITELIST) else { return };
    // Built at its exact size when every host is a string, as most lists are.
    if engine::implode_between(retval, utopia_validators::HOST_DESCRIPTION_PREFIX.as_bytes(), &whitelist, b"") {
        return;
    }
    set_built(retval, |out, joined| {
        engine::implode(&whitelist, joined)?;
        utopia_validators::host_description(joined, out);
        Some(())
    });
}

extern "C" fn host_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Host::isValid") else { return };
    let Some(this) = this(ex) else { return };
    // `(new URL())->isValid($value)`: the defaults leave only `filter_var()`.
    let Some(url) = url_passes(value) else { return };
    if !url {
        set_bool(retval, false);
        return;
    }
    // `(new Hostname($this->whitelist))->isValid(parse_url((string) $value, PHP_URL_HOST))`.
    let Some(whitelist) = get(stored(&HOST), this, &HOST_WHITELIST) else { return };
    let Some(url) = engine::to_string(value) else { return };
    let host = url_component(&url, UrlComponent::Host);
    if let Some(valid) = hostname_valid(&host, || Some(whitelist)) {
        set_bool(retval, valid);
    }
}

// IP ------------------------------------------------------------------------------------

static IP: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static IP_TYPE: Property = Property::new("type");

const IP_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: ip_construct,
        flags: PUBLIC,
        params: &[("type", MAY_BE_STRING, Some("self::ALL"))],
        returns: 0,
    },
    Method { name: "getDescription", handler: ip_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: ip_is_valid, flags: PUBLIC, params: VALUE, returns: MAY_BE_BOOL },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
];

fn ip_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    IP_ALL.intern();
    engine::declare_constant(class, "ALL", IP_ALL.zval(), 0);
    engine::declare_constant(class, "V4", engine::interned(b"ipv4"), 0);
    engine::declare_constant(class, "V6", engine::interned(b"ipv6"), 0);
    engine::inherited_constants_last(class, &INHERITED);
    // `protected string $type;`, assigned by the constructor.
    engine::declare_uninitialized(class, &IP_TYPE, MAY_BE_STRING);
    IP.store(class, Ordering::Release);
    leaf::patch(class, IP_METHODS, tables, &["type"]);
}

extern "C" fn ip_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let kind = match engine::arg(ex, 0) {
        Some(arg) => match str_zval_arg(ex, 1, arg) {
            Some(kind) => kind,
            None => return,
        },
        None => IP_ALL.zval(),
    };
    // `in_array($type, [self::ALL, self::V4, self::V6])`: no name is numeric, so loosely
    // equal strings are equal bytes.
    if IpType::from_name(kind.zend_str().map_or(&[], |kind| kind.as_bytes())).is_none() {
        let _ = throw(ce::exception(), "Unsupported IP type");
        return;
    }
    if let Some(this) = this(ex) {
        set(stored(&IP), this, &IP_TYPE, kind);
    }
}

extern "C" fn ip_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_literal(retval, &IP_DESCRIPTION);
}

extern "C" fn ip_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\IP::isValid") else { return };
    let Some(this) = this(ex) else { return };
    let Some(kind) = get(stored(&IP), this, &IP_TYPE) else { return };
    // `switch ($this->type)`: a type other than the three falls to `default`, before any
    // `filter_var()`.
    let Some(kind) = bytes(&kind).and_then(IpType::from_name) else {
        set_bool(retval, false);
        return;
    };
    let valid = match bytes(value) {
        Some(s) => utopia_validators::ip_str(s, kind),
        None => match filter_value(value) {
            Some(input) => utopia_validators::ip(&input, kind),
            None => return,
        },
    };
    set_bool(retval, valid);
}

// URL -----------------------------------------------------------------------------------

static URL: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static URL_ALLOWED_SCHEMES: Property = Property::new("allowedSchemes");
static URL_ALLOW_EMPTY: Property = Property::new("allowEmpty");
static URL_ALLOW_FRAGMENTS: Property = Property::new("allowFragments");
static URL_ALLOW_PRIVATE: Property = Property::new("allowPrivateUseSchemes");
static URL_HTTPS_OR_LOOPBACK: Property = Property::new("httpsOrLoopback");

const URL_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: url_construct,
        flags: PUBLIC,
        params: &[
            ("allowedSchemes", MAY_BE_ARRAY, Some("[]")),
            ("allowEmpty", MAY_BE_BOOL, Some("false")),
            ("allowFragments", MAY_BE_BOOL, Some("true")),
            ("allowPrivateUseSchemes", MAY_BE_BOOL, Some("false")),
            ("httpsOrLoopback", MAY_BE_BOOL, Some("false")),
        ],
        returns: 0,
    },
    Method { name: "getDescription", handler: url_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: url_is_valid, flags: PUBLIC, params: VALUE, returns: MAY_BE_BOOL },
    Method {
        name: "isPrivateUseSchemeURI",
        handler: url_is_private_use_scheme,
        flags: PRIVATE,
        params: VALUE,
        returns: MAY_BE_BOOL,
    },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
];

fn url_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare(class, &URL_ALLOWED_SCHEMES, MAY_BE_ARRAY, None);
    engine::declare(class, &URL_ALLOW_EMPTY, MAY_BE_BOOL, None);
    engine::declare(class, &URL_ALLOW_FRAGMENTS, MAY_BE_BOOL, None);
    engine::declare(class, &URL_ALLOW_PRIVATE, MAY_BE_BOOL, None);
    engine::declare(class, &URL_HTTPS_OR_LOOPBACK, MAY_BE_BOOL, None);
    URL.store(class, Ordering::Release);
    leaf::patch(class, URL_METHODS, tables, &[]);
}

extern "C" fn url_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let Some(schemes) = array_or_empty(ex, 0) else { return };
    let Some(allow_empty) = bool_or(ex, 1, false) else { return };
    let Some(allow_fragments) = bool_or(ex, 2, true) else { return };
    let Some(allow_private) = bool_or(ex, 3, false) else { return };
    let Some(https_or_loopback) = bool_or(ex, 4, false) else { return };
    if let Some(this) = this(ex) {
        let scope = stored(&URL);
        set(scope, this, &URL_ALLOWED_SCHEMES, schemes);
        set(scope, this, &URL_ALLOW_EMPTY, bool_zval(allow_empty));
        set(scope, this, &URL_ALLOW_FRAGMENTS, bool_zval(allow_fragments));
        set(scope, this, &URL_ALLOW_PRIVATE, bool_zval(allow_private));
        set(scope, this, &URL_HTTPS_OR_LOOPBACK, bool_zval(https_or_loopback));
    }
}

extern "C" fn url_description(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let scope = stored(&URL);
    let Some(transport) = get(scope, this, &URL_HTTPS_OR_LOOPBACK) else { return };
    let Some(schemes) = get(scope, this, &URL_ALLOWED_SCHEMES) else { return };
    set_built(retval, |out, joined| {
        let listed = schemes.array().is_some_and(|list| !list.is_empty());
        if listed {
            engine::implode(&schemes, joined)?;
        }
        let fragments = get(scope, this, &URL_ALLOW_FRAGMENTS)?;
        let joined = listed.then_some(&joined[..]);
        utopia_validators::url_description(joined, engine::truthy(&fragments), engine::truthy(&transport), out);
        Some(())
    });
}

/// `URL::isPrivateUseSchemeURI($value)`.
fn private_use_scheme(value: &Zval) -> bool {
    bytes(value).is_some_and(utopia_validators::private_use_scheme)
}

extern "C" fn url_is_private_use_scheme(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\URL::isPrivateUseSchemeURI") else { return };
    set_bool(retval, private_use_scheme(value));
}

/// `URL::isValid()` on `this`, from `scope`; `None` with an exception pending.
fn url_valid(scope: &ClassEntry, this: &mut ext_php_rs::types::ZendObject, value: &Zval) -> Option<bool> {
    if engine::truthy(&get(scope, this, &URL_ALLOW_EMPTY)?) && bytes(value) == Some(b"") {
        return Some(true);
    }
    let private_use = engine::truthy(&get(scope, this, &URL_ALLOW_PRIVATE)?) && private_use_scheme(value);
    if !url_passes(value)? && !private_use {
        return Some(false);
    }
    let schemes = get(scope, this, &URL_ALLOWED_SCHEMES)?;
    if let Some(list) = schemes.array().filter(|list| !list.is_empty()) {
        // `in_array(parse_url((string) $value, PHP_URL_SCHEME), $this->allowedSchemes)`.
        let scheme = url_component(&engine::to_string(value)?, UrlComponent::Scheme);
        let mut allowed = false;
        for item in engine::values(list) {
            if engine::loose_equal(&scheme, item)? {
                allowed = true;
                break;
            }
        }
        if !allowed {
            return Some(false);
        }
    }
    if !engine::truthy(&get(scope, this, &URL_ALLOW_FRAGMENTS)?) {
        // `parse_url((string) $value, PHP_URL_FRAGMENT) !== null`: a fragment, or `false`.
        let url = engine::to_string(value)?;
        if utopia_validators::url_component(&url, UrlComponent::Fragment) != Some(None) {
            return Some(false);
        }
    }
    if engine::truthy(&get(scope, this, &URL_HTTPS_OR_LOOPBACK)?) && !private_use {
        let scheme = url_component_lower(&engine::to_string(value)?, UrlComponent::Scheme);
        if scheme == b"http" {
            let host = url_component_lower(&engine::to_string(value)?, UrlComponent::Host);
            if !utopia_validators::LOOPBACK_HOSTS.contains(&host.as_slice()) {
                return Some(false);
            }
        } else if scheme != b"https" {
            return Some(false);
        }
    }
    Some(true)
}

extern "C" fn url_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\URL::isValid") else { return };
    let Some(this) = this(ex) else { return };
    if let Some(valid) = url_valid(stored(&URL), this, value) {
        set_bool(retval, valid);
    }
}

// Phone ---------------------------------------------------------------------------------

static PHONE: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static PHONE_ALLOW_EMPTY: Property = Property::new("allowEmpty");
static PHONE_NORMALIZE: Property = Property::new("normalize");

const PHONE_METHODS: &[Method] = &[
    Method {
        name: "__construct",
        handler: phone_construct,
        flags: PUBLIC,
        params: &[("allowEmpty", MAY_BE_BOOL, Some("false")), ("normalize", MAY_BE_BOOL, Some("false"))],
        returns: 0,
    },
    Method {
        name: "normalize",
        handler: phone_normalize,
        flags: PUBLIC_STATIC,
        params: &[("value", MAY_BE_STRING, None)],
        returns: MAY_BE_STRING,
    },
    Method { name: "getDescription", handler: phone_description, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isArray", handler: is_array, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "getType", handler: type_string, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "isValid", handler: phone_is_valid, flags: PUBLIC, params: VALUE_MIXED, returns: MAY_BE_BOOL },
];

fn phone_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare(class, &PHONE_ALLOW_EMPTY, MAY_BE_BOOL, None);
    engine::declare(class, &PHONE_NORMALIZE, MAY_BE_BOOL, None);
    PHONE.store(class, Ordering::Release);
    leaf::patch(class, PHONE_METHODS, tables, &[]);
}

extern "C" fn phone_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let Some(allow_empty) = bool_or(ex, 0, false) else { return };
    let Some(normalize) = bool_or(ex, 1, false) else { return };
    if let Some(this) = this(ex) {
        let scope = stored(&PHONE);
        set(scope, this, &PHONE_ALLOW_EMPTY, bool_zval(allow_empty));
        set(scope, this, &PHONE_NORMALIZE, bool_zval(normalize));
    }
}

extern "C" fn phone_normalize(ex: &mut ExecuteData, retval: &mut Zval) {
    let passed = engine::args_count(ex);
    let Some(value) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\Validator\\Phone::normalize", passed, 1, 1);
        return;
    };
    let Some(value) = str_arg(ex, 1, value) else { return };
    set_str(retval, &utopia_validators::phone_normalize(&value));
}

extern "C" fn phone_description(_: &mut ExecuteData, retval: &mut Zval) {
    set_literal(retval, &PHONE_DESCRIPTION);
}

extern "C" fn phone_is_valid(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(value) = value_arg(ex, "Utopia\\Validator\\Phone::isValid") else { return };
    let Some(number) = bytes(value).map(<[u8]>::to_vec) else {
        set_bool(retval, false);
        return;
    };
    let Some(this) = this(ex) else { return };
    let scope = stored(&PHONE);
    let Some(allow_empty) = get(scope, this, &PHONE_ALLOW_EMPTY) else { return };
    if engine::truthy(&allow_empty) && number.is_empty() {
        set_bool(retval, true);
        return;
    }
    let Some(normalize) = get(scope, this, &PHONE_NORMALIZE) else { return };
    // `self::normalize()`: `Phone`'s own, whatever a subclass declares.
    let number = if engine::truthy(&normalize) { utopia_validators::phone_normalize(&number) } else { number };
    set_bool(retval, utopia_validators::phone(&number));
}

/// Registers the batch's classes, each extending `Utopia\Validator\Validator`. Called from
/// the module's startup (MINIT), after the base.
pub fn register() -> ext_php_rs::error::Result<()> {
    engine::intern(&[
        &HOSTNAME_DESCRIPTION,
        &DOMAIN_DESCRIPTION,
        &IP_DESCRIPTION,
        &PHONE_DESCRIPTION,
        &RESTRICTION_HOSTNAME,
        &RESTRICTION_LEVELS,
        &RESTRICTION_PREFIX_DENY_LIST,
    ]);
    let base = (super::base_class as fn() -> &'static ClassEntry, super::BASE);
    build("Utopia\\Validator\\Hostname", base, HOSTNAME_METHODS, hostname_register)?;
    build("Utopia\\Validator\\Domain", base, DOMAIN_METHODS, domain_register)?;
    build("Utopia\\Validator\\Host", base, HOST_METHODS, host_register)?;
    build("Utopia\\Validator\\IP", base, IP_METHODS, ip_register)?;
    build("Utopia\\Validator\\URL", base, URL_METHODS, url_register)?;
    build("Utopia\\Validator\\Phone", base, PHONE_METHODS, phone_register)
}
