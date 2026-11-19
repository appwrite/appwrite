//! The hashes of `packages/auth/src/Hashes`: `Argon2`, `Bcrypt`, `MD5`, `PHPass`,
//! `Plaintext`, `Scrypt`, `ScryptModified` and `Sha`, each a native subclass of the native
//! `Utopia\Auth\Hash`.
//!
//! What PHP's classes hand to PHP functions (`password_hash()`, `password_verify()`,
//! `crypt()`, `hash()`, `md5()`, the PECL `scrypt()`, `openssl_encrypt()`) is computed by
//! their ports in `php-std` and [`utopia_auth`], validated first as PHP validates it, in its
//! order and with its messages.

use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::args::ArgInfoTables;
use ext_php_rs::types::{ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData, ExecutorGlobals};
use utopia_auth::scrypt::Failure;

use super::{BASE, STATIC, args, base_class, exact, fail, name_of, option_of, options_of, return_this, set_option_on};
use crate::classes::engine::{
    self, Key, MAY_BE_ARRAY, MAY_BE_BOOL, MAY_BE_LONG, MAY_BE_STRING, Property, bool_arg, long_arg, str_arg, string,
};
use crate::classes::leaf::{self, Method, PRIVATE, PROTECTED, PUBLIC, set_bool, set_str, stored, this};

/// Registers the hashes, after the base they extend.
pub fn register() -> ext_php_rs::error::Result<()> {
    let parent = (base_class as fn() -> &'static ClassEntry, BASE);
    leaf::build("Utopia\\Auth\\Hashes\\Argon2", parent, ARGON2_METHODS, argon2_register)?;
    leaf::build("Utopia\\Auth\\Hashes\\Bcrypt", parent, BCRYPT_METHODS, bcrypt_register)?;
    leaf::build("Utopia\\Auth\\Hashes\\MD5", parent, MD5_METHODS, md5_register)?;
    leaf::build("Utopia\\Auth\\Hashes\\PHPass", parent, PHPASS_METHODS, phpass_register)?;
    leaf::build("Utopia\\Auth\\Hashes\\Plaintext", parent, PLAINTEXT_METHODS, plaintext_register)?;
    leaf::build("Utopia\\Auth\\Hashes\\Scrypt", parent, SCRYPT_METHODS, scrypt_register)?;
    leaf::build("Utopia\\Auth\\Hashes\\ScryptModified", parent, SCRYPT_MODIFIED_METHODS, scrypt_modified_register)?;
    leaf::build("Utopia\\Auth\\Hashes\\Sha", parent, SHA_METHODS, sha_register)
}

// What the hashes share ---------------------------------------------------------------

/// `string $value`.
const VALUE: &[leaf::Param] = &[("value", MAY_BE_STRING, None)];
/// `string $value, string $hash`.
const VALUE_HASH: &[leaf::Param] = &[("value", MAY_BE_STRING, None), ("hash", MAY_BE_STRING, None)];

/// The `hash()`, `verify()` and `getName()` every hash implements, as [`Method`]s.
const fn hash_method(handler: leaf::FunctionHandler) -> Method {
    Method { name: "hash", handler, flags: PUBLIC, params: VALUE, returns: MAY_BE_STRING }
}
const fn verify_method(handler: leaf::FunctionHandler) -> Method {
    Method { name: "verify", handler, flags: PUBLIC, params: VALUE_HASH, returns: MAY_BE_BOOL }
}
const fn name_method(handler: leaf::FunctionHandler) -> Method {
    Method { name: "getName", handler, flags: PUBLIC, params: &[], returns: MAY_BE_STRING }
}
const fn constructor(handler: leaf::FunctionHandler) -> Method {
    Method { name: "__construct", handler, flags: PUBLIC, params: &[], returns: 0 }
}

/// [`leaf::patch`], then the class-typed returns PHP declares (`self`, a class) on `classed`.
fn patch(class: &ClassEntry, methods: &[Method], tables: ArgInfoTables, classed: &[(&str, &str)]) {
    leaf::patch(class, methods, tables, &[]);
    for (method, type_name) in classed {
        if let Some(info) = engine::arg_info(class, method) {
            engine::set_class_type(&mut info[0], type_name);
        }
    }
}

/// The method's string arguments `1..=count` (after `required` of them are checked), `None`
/// with the exception pending.
fn strings(ex: &mut ExecuteData, function: &str, required: usize, count: usize) -> Option<Vec<Vec<u8>>> {
    let args = args(ex, function, required, count)?;
    let mut out = Vec::with_capacity(args.len());
    for (i, arg) in args.into_iter().enumerate() {
        out.push(str_arg(ex, u32::try_from(i + 1).unwrap_or(u32::MAX), arg)?);
    }
    Some(out)
}

/// The method's one `int` argument.
fn int(ex: &mut ExecuteData, function: &str) -> Option<i64> {
    let mut args = args(ex, function, 1, 1)?;
    long_arg(ex, 1, args.remove(0))
}

/// `$this->setOption($key, $value)` for a native constructor or setter of `class`.
fn set(this: &mut ZendObject, class: &ClassEntry, key: &str, value: Zval) -> Option<()> {
    set_option_on(this, class, key.as_bytes(), value)
}

fn long(i: i64) -> Zval {
    let mut zval = Zval::new();
    zval.set_long(i);
    zval
}

fn boolean(b: bool) -> Zval {
    let mut zval = Zval::new();
    zval.set_bool(b);
    zval
}

/// `$this->setOption('type', $this->getName())`, how the constructors start.
fn set_type(this: &mut ZendObject, class: &ClassEntry, name: &str) -> Option<()> {
    let name = name_of(this, class, name)?;
    set(this, class, "type", name)
}

/// `$this->hash($value)` on an object of `class` or a subclass: `native` for the former.
fn hash_of(
    this: &mut ZendObject,
    class: &ClassEntry,
    value: &[u8],
    native: fn(&mut ZendObject, &[u8]) -> Option<Vec<u8>>,
) -> Option<Vec<u8>> {
    if exact(this, class) {
        return native(this, value);
    }
    let hashed = engine::call_own_method(this, "hash", &[&string(value)])?;
    Some(hashed.zend_str().map(|s| s.as_bytes().to_vec()).unwrap_or_default())
}

/// `hash_equals($hash, $this->hash($value))`, the `verify()` of the hashes that hash again.
fn verify_by_hashing(
    ex: &mut ExecuteData,
    retval: &mut Zval,
    function: &str,
    class: &ClassEntry,
    native: fn(&mut ZendObject, &[u8]) -> Option<Vec<u8>>,
) {
    let Some(args) = strings(ex, function, 2, 2) else { return };
    let Some(this) = this(ex) else { return };
    let Some(computed) = hash_of(this, class, &args[0], native) else { return };
    set_bool(retval, utopia_auth::hash_equals(&args[1], &computed));
}

/// `$options[$key]` as `password_hash()` reads it (`zend_hash_str_find()`, then
/// `zval_get_long()`), `default` when missing.
fn password_option(options: &Zval, key: &str, default: i64) -> i64 {
    engine::array_get(options, &Key::Name(key.as_bytes().to_vec())).map_or(default, engine::long_value)
}

/// `password_hash()`'s warning for a `salt` option, which it ignores.
fn warn_salt(options: &Zval) {
    if engine::array_get(options, &Key::Name(b"salt".to_vec())).is_some() {
        engine::warning(
            "password_hash(): The \"salt\" option has been ignored, since providing a custom salt is no longer supported",
        );
    }
}

/// `is_string($value)`.
fn is_string(value: &Zval) -> bool {
    value.dereference().zend_str().is_some()
}

/// `is_int($value)`, and the integer.
fn as_int(value: &Zval) -> Option<i64> {
    value.dereference().long()
}

// Argon2 ------------------------------------------------------------------------------

static ARGON2: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());

const ARGON2_METHODS: &[Method] = &[
    constructor(argon2_construct),
    hash_method(argon2_hash),
    verify_method(password_verify),
    Method {
        name: "setMemoryCost",
        handler: argon2_memory,
        flags: PUBLIC,
        params: &[("cost", MAY_BE_LONG, None)],
        returns: STATIC,
    },
    Method {
        name: "setTimeCost",
        handler: argon2_time,
        flags: PUBLIC,
        params: &[("cost", MAY_BE_LONG, None)],
        returns: STATIC,
    },
    Method {
        name: "setThreads",
        handler: argon2_threads,
        flags: PUBLIC,
        params: &[("threads", MAY_BE_LONG, None)],
        returns: STATIC,
    },
    name_method(argon2_name),
];

fn argon2_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    ARGON2.store(class, Ordering::Release);
    patch(class, ARGON2_METHODS, tables, &[]);
}

extern "C" fn argon2_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let class = stored(&ARGON2);
    let Some(this) = this(ex) else { return };
    let _ = set_type(this, class, "argon2")
        .and_then(|()| set(this, class, "memory_cost", long(65536)))
        .and_then(|()| set(this, class, "time_cost", long(4)))
        .and_then(|()| set(this, class, "threads", long(3)));
}

/// `password_hash($value, PASSWORD_ARGON2ID, $this->getOptions())`.
extern "C" fn argon2_hash(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\Argon2::hash", 1, 1) else { return };
    let Some(this) = this(ex) else { return };
    let Some(options) = options_of(this, stored(&ARGON2)) else { return };
    // php_password_argon2_hash(): each option read, then checked, in turn; a negative one
    // wraps to a huge `size_t`.
    let memory = password_option(&options, "memory_cost", 65536);
    if !(8..=0xFFFF_FFFF).contains(&memory) {
        return fail("ValueError", "Memory cost is outside of allowed memory range");
    }
    let time = password_option(&options, "time_cost", 4);
    if !(1..=0xFFFF_FFFF).contains(&time) {
        return fail("ValueError", "Time cost is outside of allowed time range");
    }
    let threads = password_option(&options, "threads", 1);
    if !(1..=0xFF_FFFF).contains(&threads) {
        return fail("ValueError", "Invalid number of threads");
    }
    warn_salt(&options);
    let mut salt = [0u8; 16];
    salt.copy_from_slice(&utopia_auth::password_salt(16));
    match php_std::string::password_hash_argon2id(&args[0], memory, time, threads, &salt) {
        Ok(hashed) => set_str(retval, &hashed),
        Err(error) => fail("ValueError", error.message()),
    }
}

/// `password_verify($value, $hash)`, `Argon2`'s and `Bcrypt`'s.
extern "C" fn password_verify(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hash::verify", 2, 2) else { return };
    set_bool(retval, php_std::string::password_verify(&args[0], &args[1]));
}

/// A setter that stores its `int` argument as the option `key` and returns `$this`.
fn int_setter(ex: &mut ExecuteData, retval: &mut Zval, function: &str, class: &ClassEntry, key: &str) {
    let Some(value) = int(ex, function) else { return };
    let Some(this) = this(ex) else { return };
    if set(this, class, key, long(value)).is_some() {
        return_this(ex, retval);
    }
}

extern "C" fn argon2_memory(ex: &mut ExecuteData, retval: &mut Zval) {
    int_setter(ex, retval, "Utopia\\Auth\\Hashes\\Argon2::setMemoryCost", stored(&ARGON2), "memory_cost");
}
extern "C" fn argon2_time(ex: &mut ExecuteData, retval: &mut Zval) {
    int_setter(ex, retval, "Utopia\\Auth\\Hashes\\Argon2::setTimeCost", stored(&ARGON2), "time_cost");
}
extern "C" fn argon2_threads(ex: &mut ExecuteData, retval: &mut Zval) {
    int_setter(ex, retval, "Utopia\\Auth\\Hashes\\Argon2::setThreads", stored(&ARGON2), "threads");
}
extern "C" fn argon2_name(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"argon2");
}

// Bcrypt ------------------------------------------------------------------------------

static BCRYPT: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());

const BCRYPT_METHODS: &[Method] = &[
    constructor(bcrypt_construct),
    hash_method(bcrypt_hash),
    verify_method(password_verify),
    Method {
        name: "setCost",
        handler: bcrypt_cost,
        flags: PUBLIC,
        params: &[("cost", MAY_BE_LONG, None)],
        returns: STATIC,
    },
    name_method(bcrypt_name),
];

fn bcrypt_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    BCRYPT.store(class, Ordering::Release);
    patch(class, BCRYPT_METHODS, tables, &[]);
}

extern "C" fn bcrypt_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let class = stored(&BCRYPT);
    let Some(this) = this(ex) else { return };
    let _ = set_type(this, class, "bcrypt").and_then(|()| set(this, class, "cost", long(8)));
}

/// `password_hash($value, PASSWORD_BCRYPT, $this->getOptions())`.
extern "C" fn bcrypt_hash(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\Bcrypt::hash", 1, 1) else { return };
    let Some(this) = this(ex) else { return };
    let Some(options) = options_of(this, stored(&BCRYPT)) else { return };
    // php_password_bcrypt_hash(): the null byte first, then the cost (12 when absent).
    if args[0].contains(&0) {
        return fail("ValueError", "Bcrypt password must not contain null character");
    }
    let cost = password_option(&options, "cost", 12);
    if !(4..=31).contains(&cost) {
        return fail("ValueError", &format!("Invalid bcrypt cost parameter specified: {cost}"));
    }
    warn_salt(&options);
    let mut setting = format!("$2y${cost:02}$").into_bytes();
    setting.extend(utopia_auth::password_salt(22));
    set_str(retval, &php_std::string::crypt(&args[0], &setting));
}

extern "C" fn bcrypt_cost(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(cost) = int(ex, "Utopia\\Auth\\Hashes\\Bcrypt::setCost") else { return };
    if !(4..=31).contains(&cost) {
        return fail("InvalidArgumentException", "Cost must be between 4 and 31");
    }
    let Some(this) = this(ex) else { return };
    if set(this, stored(&BCRYPT), "cost", long(cost)).is_some() {
        return_this(ex, retval);
    }
}
extern "C" fn bcrypt_name(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"bcrypt");
}

// MD5 and Plaintext ---------------------------------------------------------------------

static MD5: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static PLAINTEXT: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());

const MD5_METHODS: &[Method] =
    &[constructor(md5_construct), hash_method(md5_hash), verify_method(md5_verify), name_method(md5_name)];
const PLAINTEXT_METHODS: &[Method] = &[
    constructor(plaintext_construct),
    hash_method(plaintext_hash),
    verify_method(plaintext_verify),
    name_method(plaintext_name),
];

fn md5_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    MD5.store(class, Ordering::Release);
    patch(class, MD5_METHODS, tables, &[]);
}
fn plaintext_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    PLAINTEXT.store(class, Ordering::Release);
    patch(class, PLAINTEXT_METHODS, tables, &[]);
}

fn md5_of(_: &mut ZendObject, value: &[u8]) -> Option<Vec<u8>> {
    php_std::string::hash(b"md5", value).map(String::into_bytes)
}
fn plaintext_of(_: &mut ZendObject, value: &[u8]) -> Option<Vec<u8>> {
    Some(value.to_vec())
}

extern "C" fn md5_construct(ex: &mut ExecuteData, _: &mut Zval) {
    if let Some(this) = this(ex) {
        let _ = set_type(this, stored(&MD5), "md5");
    }
}
extern "C" fn md5_hash(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\MD5::hash", 1, 1) else { return };
    let Some(this) = this(ex) else { return };
    if let Some(hashed) = md5_of(this, &args[0]) {
        set_str(retval, &hashed);
    }
}
extern "C" fn md5_verify(ex: &mut ExecuteData, retval: &mut Zval) {
    verify_by_hashing(ex, retval, "Utopia\\Auth\\Hashes\\MD5::verify", stored(&MD5), md5_of);
}
extern "C" fn md5_name(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"md5");
}

extern "C" fn plaintext_construct(ex: &mut ExecuteData, _: &mut Zval) {
    if let Some(this) = this(ex) {
        let _ = set_type(this, stored(&PLAINTEXT), "plaintext");
    }
}
extern "C" fn plaintext_hash(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\Plaintext::hash", 1, 1) else { return };
    set_str(retval, &args[0]);
}
extern "C" fn plaintext_verify(ex: &mut ExecuteData, retval: &mut Zval) {
    verify_by_hashing(ex, retval, "Utopia\\Auth\\Hashes\\Plaintext::verify", stored(&PLAINTEXT), plaintext_of);
}
extern "C" fn plaintext_name(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"plaintext");
}

// Sha ---------------------------------------------------------------------------------

static SHA: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());

/// `Sha`'s public constants and the versions `setVersion()` accepts (`VALID_VERSIONS`).
const SHA_VERSIONS: [(&str, &str); 9] = [
    ("SHA1", "sha1"),
    ("SHA224", "sha224"),
    ("SHA256", "sha256"),
    ("SHA384", "sha384"),
    ("SHA512", "sha512"),
    ("SHA3_224", "sha3-224"),
    ("SHA3_256", "sha3-256"),
    ("SHA3_384", "sha3-384"),
    ("SHA3_512", "sha3-512"),
];

const SHA_METHODS: &[Method] = &[
    constructor(sha_construct),
    Method {
        name: "setVersion",
        handler: sha_version,
        flags: PUBLIC,
        params: &[("version", MAY_BE_STRING, None)],
        returns: STATIC,
    },
    hash_method(sha_hash),
    verify_method(sha_verify),
    name_method(sha_name),
];

fn sha_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    for (name, value) in SHA_VERSIONS {
        engine::declare_constant(class, name, engine::interned(value.as_bytes()), 0);
    }
    let versions: Vec<&str> = SHA_VERSIONS.iter().map(|(_, value)| *value).collect();
    engine::declare_private_constant(class, "VALID_VERSIONS", engine::string_list(&versions), MAY_BE_ARRAY);
    SHA.store(class, Ordering::Release);
    patch(class, SHA_METHODS, tables, &[]);
}

extern "C" fn sha_construct(ex: &mut ExecuteData, _: &mut Zval) {
    if let Some(this) = this(ex) {
        let _ = set(this, stored(&SHA), "version", string(b"sha256"));
    }
}

extern "C" fn sha_version(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\Sha::setVersion", 1, 1) else { return };
    if !SHA_VERSIONS.iter().any(|(_, value)| value.as_bytes() == args[0].as_slice()) {
        let versions: Vec<&str> = SHA_VERSIONS.iter().map(|(_, value)| *value).collect();
        return fail(
            "InvalidArgumentException",
            &format!("Invalid SHA version. Valid versions are: {}", versions.join(", ")),
        );
    }
    let Some(this) = this(ex) else { return };
    if set(this, stored(&SHA), "version", string(&args[0])).is_some() {
        return_this(ex, retval);
    }
}

/// `hash($this->getOption('version'), $value)`, after the version is checked a string.
fn sha_of(this: &mut ZendObject, value: &[u8]) -> Option<Vec<u8>> {
    let version = option_of(this, stored(&SHA), b"version")?;
    let Some(version) = version.dereference().zend_str().map(|s| s.as_bytes().to_vec()) else {
        fail("RuntimeException", "SHA version must be a string");
        return None;
    };
    if let Some(hashed) = php_std::string::hash(&version, value) {
        return Some(hashed.into_bytes());
    }
    fail("ValueError", "hash(): Argument #1 ($algo) must be a valid hashing algorithm");
    None
}

extern "C" fn sha_hash(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\Sha::hash", 1, 1) else { return };
    let Some(this) = this(ex) else { return };
    if let Some(hashed) = sha_of(this, &args[0]) {
        set_str(retval, &hashed);
    }
}
extern "C" fn sha_verify(ex: &mut ExecuteData, retval: &mut Zval) {
    verify_by_hashing(ex, retval, "Utopia\\Auth\\Hashes\\Sha::verify", stored(&SHA), sha_of);
}
extern "C" fn sha_name(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"sha");
}

// Scrypt ------------------------------------------------------------------------------

static SCRYPT: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());

const SCRYPT_METHODS: &[Method] = &[
    constructor(scrypt_construct),
    hash_method(scrypt_hash),
    verify_method(scrypt_verify),
    Method {
        name: "setCpuCost",
        handler: scrypt_cpu,
        flags: PUBLIC,
        params: &[("cost", MAY_BE_LONG, None)],
        returns: 0,
    },
    Method {
        name: "setMemoryCost",
        handler: scrypt_memory,
        flags: PUBLIC,
        params: &[("cost", MAY_BE_LONG, None)],
        returns: STATIC,
    },
    Method {
        name: "setParallelCost",
        handler: scrypt_parallel,
        flags: PUBLIC,
        params: &[("cost", MAY_BE_LONG, None)],
        returns: STATIC,
    },
    Method {
        name: "setLength",
        handler: scrypt_length,
        flags: PUBLIC,
        params: &[("length", MAY_BE_LONG, None)],
        returns: STATIC,
    },
    Method {
        name: "setSalt",
        handler: scrypt_salt,
        flags: PUBLIC,
        params: &[("salt", MAY_BE_STRING, None)],
        returns: STATIC,
    },
    name_method(scrypt_name),
];

fn scrypt_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    SCRYPT.store(class, Ordering::Release);
    // PHP's `self`, which reflection shows resolved.
    patch(class, SCRYPT_METHODS, tables, &[("setCpuCost", "Utopia\\Auth\\Hashes\\Scrypt")]);
}

extern "C" fn scrypt_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let class = stored(&SCRYPT);
    let Some(this) = this(ex) else { return };
    let salt = php_std::encoding::bin2hex(&utopia_auth::random_bytes(16));
    let _ = set_type(this, class, "scrypt")
        .and_then(|()| set(this, class, "costCpu", long(8)))
        .and_then(|()| set(this, class, "costMemory", long(14)))
        .and_then(|()| set(this, class, "costParallel", long(1)))
        .and_then(|()| set(this, class, "length", long(64)))
        .and_then(|()| set(this, class, "salt", string(salt.as_bytes())));
}

/// `scrypt()`'s failure as PHP raises it: its `Error`, or its warning (then `false`).
fn scrypt_failed(failure: &Failure) {
    match failure {
        Failure::Error(message) => fail("Error", message),
        Failure::Warning(message) => engine::warning(&format!("scrypt(): {message}")),
        Failure::False => {}
    }
}

fn scrypt_of(this: &mut ZendObject, value: &[u8]) -> Option<Vec<u8>> {
    let class = stored(&SCRYPT);
    let salt = option_of(this, class, b"salt")?;
    let cpu = option_of(this, class, b"costCpu")?;
    let memory = option_of(this, class, b"costMemory")?;
    let parallel = option_of(this, class, b"costParallel")?;
    let length = option_of(this, class, b"length")?;
    let Some(salt) = salt.dereference().zend_str().map(|s| s.as_bytes().to_vec()) else {
        fail("InvalidArgumentException", "Salt must be a string");
        return None;
    };
    let (Some(cpu), Some(memory), Some(parallel), Some(length)) =
        (as_int(&cpu), as_int(&memory), as_int(&parallel), as_int(&length))
    else {
        fail("InvalidArgumentException", "Scrypt cost and length options must be integers");
        return None;
    };
    match utopia_auth::scrypt::scrypt(value, &salt, cpu, memory, parallel, length) {
        Ok(key) => Some(php_std::encoding::bin2hex(&key).into_bytes()),
        Err(failure) => {
            // `scrypt()`'s `Error`, or an error handler that threw on its warning, ends
            // `hash()` there; otherwise its `false` is the class's `RuntimeException`.
            scrypt_failed(&failure);
            if !ExecutorGlobals::has_exception() {
                fail("RuntimeException", "Failed to hash using scrypt");
            }
            None
        }
    }
}

extern "C" fn scrypt_hash(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\Scrypt::hash", 1, 1) else { return };
    let Some(this) = this(ex) else { return };
    if let Some(hashed) = scrypt_of(this, &args[0]) {
        set_str(retval, &hashed);
    }
}
extern "C" fn scrypt_verify(ex: &mut ExecuteData, retval: &mut Zval) {
    verify_by_hashing(ex, retval, "Utopia\\Auth\\Hashes\\Scrypt::verify", stored(&SCRYPT), scrypt_of);
}

/// A setter of `Scrypt` that checks its `int` with `valid` (throwing `message`), stores it as
/// `key` and returns `$this`.
fn scrypt_setter(
    ex: &mut ExecuteData,
    retval: &mut Zval,
    function: &str,
    key: &str,
    valid: fn(i64) -> bool,
    message: &str,
) {
    let Some(value) = int(ex, function) else { return };
    if !valid(value) {
        return fail("InvalidArgumentException", message);
    }
    let Some(this) = this(ex) else { return };
    if set(this, stored(&SCRYPT), key, long(value)).is_some() {
        return_this(ex, retval);
    }
}

extern "C" fn scrypt_cpu(ex: &mut ExecuteData, retval: &mut Zval) {
    scrypt_setter(
        ex,
        retval,
        "Utopia\\Auth\\Hashes\\Scrypt::setCpuCost",
        "costCpu",
        |cost| cost > 1 && cost & (cost - 1) == 0,
        "CPU cost must be > 1 and a power of 2",
    );
}
extern "C" fn scrypt_memory(ex: &mut ExecuteData, retval: &mut Zval) {
    scrypt_setter(
        ex,
        retval,
        "Utopia\\Auth\\Hashes\\Scrypt::setMemoryCost",
        "costMemory",
        |cost| cost >= 1,
        "Memory cost must be >= 1",
    );
}
extern "C" fn scrypt_parallel(ex: &mut ExecuteData, retval: &mut Zval) {
    scrypt_setter(
        ex,
        retval,
        "Utopia\\Auth\\Hashes\\Scrypt::setParallelCost",
        "costParallel",
        |cost| cost >= 1,
        "Parallel cost must be >= 1",
    );
}
extern "C" fn scrypt_length(ex: &mut ExecuteData, retval: &mut Zval) {
    scrypt_setter(
        ex,
        retval,
        "Utopia\\Auth\\Hashes\\Scrypt::setLength",
        "length",
        |length| length >= 16,
        "Length must be >= 16 bytes",
    );
}
extern "C" fn scrypt_salt(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\Scrypt::setSalt", 1, 1) else { return };
    if args[0].is_empty() || args[0] == b"0" {
        return fail("InvalidArgumentException", "Salt cannot be empty");
    }
    let Some(this) = this(ex) else { return };
    if set(this, stored(&SCRYPT), "salt", string(&args[0])).is_some() {
        return_this(ex, retval);
    }
}
extern "C" fn scrypt_name(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"scrypt");
}

// ScryptModified ----------------------------------------------------------------------

static SCRYPT_MODIFIED: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());

const SCRYPT_MODIFIED_METHODS: &[Method] = &[
    constructor(scrypt_modified_construct),
    hash_method(scrypt_modified_hash),
    verify_method(scrypt_modified_verify),
    Method {
        name: "generateDerivedKey",
        handler: scrypt_modified_derive,
        flags: PRIVATE,
        params: VALUE,
        returns: MAY_BE_STRING,
    },
    Method {
        name: "hashKeys",
        handler: scrypt_modified_keys,
        flags: PRIVATE,
        params: &[("signerKeyBytes", MAY_BE_STRING, None), ("derivedKeyBytes", MAY_BE_STRING, None)],
        returns: MAY_BE_STRING,
    },
    Method {
        name: "setSalt",
        handler: scrypt_modified_salt,
        flags: PUBLIC,
        params: &[("salt", MAY_BE_STRING, None)],
        returns: STATIC,
    },
    Method {
        name: "setSaltSeparator",
        handler: scrypt_modified_separator,
        flags: PUBLIC,
        params: &[("separator", MAY_BE_STRING, None)],
        returns: STATIC,
    },
    Method {
        name: "setSignerKey",
        handler: scrypt_modified_signer,
        flags: PUBLIC,
        params: &[("key", MAY_BE_STRING, None)],
        returns: STATIC,
    },
    name_method(scrypt_modified_name),
];

fn scrypt_modified_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    SCRYPT_MODIFIED.store(class, Ordering::Release);
    patch(class, SCRYPT_MODIFIED_METHODS, tables, &[]);
}

extern "C" fn scrypt_modified_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let class = stored(&SCRYPT_MODIFIED);
    let Some(this) = this(ex) else { return };
    let encode = |bytes: Vec<u8>| string(php_std::encoding::base64_encode(&bytes).as_bytes());
    let (salt, separator, signer) =
        (utopia_auth::random_bytes(16), utopia_auth::random_bytes(16), utopia_auth::random_bytes(32));
    let _ = set_type(this, class, "scryptMod")
        .and_then(|()| set(this, class, "salt", encode(salt)))
        .and_then(|()| set(this, class, "saltSeparator", encode(separator)))
        .and_then(|()| set(this, class, "signerKey", encode(signer)));
}

/// `$options[$key]` read as PHP reads it: `Undefined array key` warns, `null` comes back.
fn option_read(options: &Zval, key: &str) -> Option<Zval> {
    engine::dimension(options, key)
}

/// `generateDerivedKey($value)`: the 64-byte scrypt of the value under the salt and its
/// separator.
fn scrypt_modified_derive_of(this: &mut ZendObject, value: &[u8]) -> Option<Vec<u8>> {
    let options = options_of(this, stored(&SCRYPT_MODIFIED))?;
    let salt = option_read(&options, "salt")?;
    let separator = if is_string(&salt) { Some(option_read(&options, "saltSeparator")?) } else { None };
    let (Some(salt), Some(separator)) =
        (salt.dereference().zend_str(), separator.as_ref().and_then(|s| s.dereference().zend_str()))
    else {
        fail("InvalidArgumentException", "Salt and salt separator must be strings");
        return None;
    };
    let salt = php_std::encoding::base64_decode(salt.as_bytes(), false).unwrap_or_default();
    let separator = php_std::encoding::base64_decode(separator.as_bytes(), false).unwrap_or_default();
    let value = php_std::mb::mb_scrub(value);
    match utopia_auth::firebase::derived_key(&value, &salt, &separator) {
        Ok(key) => Some(key),
        Err(failure) => {
            // As in `Scrypt`: an `Error`, or a warning's handler that threw, ends it there.
            scrypt_failed(&failure);
            if !ExecutorGlobals::has_exception() {
                fail("RuntimeException", "Failed to generate derived key using scrypt");
            }
            None
        }
    }
}

/// `hashKeys($signerKeyBytes, $derivedKeyBytes)`: the signer key encrypted with AES-256-CTR
/// under the derived key's first 32 bytes, from a zero IV.
fn scrypt_modified_keys_of(signer: &[u8], derived: &[u8]) -> Option<Vec<u8>> {
    // `openssl_encrypt()` pads a short key with zeros and uses the first 32 bytes of a long one.
    let mut key = [0u8; 32];
    let take = derived.len().min(32);
    key[..take].copy_from_slice(&derived[..take]);
    Some(utopia_auth::firebase::aes_256_ctr(&key, signer))
}

fn scrypt_modified_of(this: &mut ZendObject, value: &[u8]) -> Option<Vec<u8>> {
    let options = options_of(this, stored(&SCRYPT_MODIFIED))?;
    let signer = option_read(&options, "signerKey")?;
    let Some(signer) = signer.dereference().zend_str().map(|s| s.as_bytes().to_vec()) else {
        fail("InvalidArgumentException", "Signer key must be a string");
        return None;
    };
    let derived = scrypt_modified_derive_of(this, value)?;
    let signer = php_std::encoding::base64_decode(&signer, false).unwrap_or_default();
    let encrypted = scrypt_modified_keys_of(&signer, &derived)?;
    Some(php_std::encoding::base64_encode(&encrypted).into_bytes())
}

extern "C" fn scrypt_modified_hash(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\ScryptModified::hash", 1, 1) else { return };
    let Some(this) = this(ex) else { return };
    if let Some(hashed) = scrypt_modified_of(this, &args[0]) {
        set_str(retval, &hashed);
    }
}
extern "C" fn scrypt_modified_verify(ex: &mut ExecuteData, retval: &mut Zval) {
    verify_by_hashing(
        ex,
        retval,
        "Utopia\\Auth\\Hashes\\ScryptModified::verify",
        stored(&SCRYPT_MODIFIED),
        scrypt_modified_of,
    );
}
extern "C" fn scrypt_modified_derive(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\ScryptModified::generateDerivedKey", 1, 1) else { return };
    let Some(this) = this(ex) else { return };
    if let Some(key) = scrypt_modified_derive_of(this, &args[0]) {
        set_str(retval, &key);
    }
}
extern "C" fn scrypt_modified_keys(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\ScryptModified::hashKeys", 2, 2) else { return };
    if let Some(encrypted) = scrypt_modified_keys_of(&args[0], &args[1]) {
        set_str(retval, &encrypted);
    }
}

/// PCRE `/^[A-Za-z0-9+\/]+={0,2}$/`: base64 characters, up to two `=`, and an optional final
/// newline (`$` matches before one).
fn base64_shaped(s: &[u8]) -> bool {
    let s = s.strip_suffix(b"\n").unwrap_or(s);
    let body = s.iter().take_while(|b| b.is_ascii_alphanumeric() || **b == b'+' || **b == b'/').count();
    let padding = &s[body..];
    body > 0 && padding.len() <= 2 && padding.iter().all(|b| *b == b'=')
}

/// A base64 setter of `ScryptModified`: `empty` (when given) for `''` or `'0'`, `shaped` for
/// anything not base64, then stores the option `key` and returns `$this`.
fn scrypt_modified_setter(
    ex: &mut ExecuteData,
    retval: &mut Zval,
    function: &str,
    key: &str,
    empty: Option<&str>,
    shaped: &str,
) {
    let Some(args) = strings(ex, function, 1, 1) else { return };
    if let Some(message) = empty
        && (args[0].is_empty() || args[0] == b"0")
    {
        return fail("InvalidArgumentException", message);
    }
    if !base64_shaped(&args[0]) {
        return fail("InvalidArgumentException", shaped);
    }
    let Some(this) = this(ex) else { return };
    if set(this, stored(&SCRYPT_MODIFIED), key, string(&args[0])).is_some() {
        return_this(ex, retval);
    }
}

extern "C" fn scrypt_modified_salt(ex: &mut ExecuteData, retval: &mut Zval) {
    scrypt_modified_setter(
        ex,
        retval,
        "Utopia\\Auth\\Hashes\\ScryptModified::setSalt",
        "salt",
        Some("Salt cannot be empty"),
        "Salt must be base64 encoded",
    );
}
extern "C" fn scrypt_modified_separator(ex: &mut ExecuteData, retval: &mut Zval) {
    scrypt_modified_setter(
        ex,
        retval,
        "Utopia\\Auth\\Hashes\\ScryptModified::setSaltSeparator",
        "saltSeparator",
        None,
        "Salt separator must be base64 encoded",
    );
}
extern "C" fn scrypt_modified_signer(ex: &mut ExecuteData, retval: &mut Zval) {
    scrypt_modified_setter(
        ex,
        retval,
        "Utopia\\Auth\\Hashes\\ScryptModified::setSignerKey",
        "signerKey",
        Some("Signer key cannot be empty"),
        "Signer key must be base64 encoded",
    );
}
extern "C" fn scrypt_modified_name(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"scryptMod");
}

// PHPass ------------------------------------------------------------------------------

static PHPASS: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
/// `PHPass::$itoa64`.
static ITOA64: Property = Property::new("itoa64");

const PHPASS_METHODS: &[Method] = &[
    constructor(phpass_construct),
    hash_method(phpass_hash),
    verify_method(phpass_verify),
    Method {
        name: "getRandomBytes",
        handler: phpass_random,
        flags: PROTECTED,
        params: &[("count", MAY_BE_LONG, None)],
        returns: MAY_BE_STRING,
    },
    Method {
        name: "encode64",
        handler: phpass_encode64,
        flags: PROTECTED,
        params: &[("input", MAY_BE_STRING, None), ("count", MAY_BE_LONG, None)],
        returns: MAY_BE_STRING,
    },
    Method {
        name: "gensaltPrivate",
        handler: phpass_gensalt_private,
        flags: PRIVATE,
        params: &[("input", MAY_BE_STRING, None)],
        returns: MAY_BE_STRING,
    },
    Method {
        name: "gensaltBlowfish",
        handler: phpass_gensalt_blowfish,
        flags: PRIVATE,
        params: &[("input", MAY_BE_STRING, None)],
        returns: MAY_BE_STRING,
    },
    Method {
        name: "cryptPrivate",
        handler: phpass_crypt_private,
        flags: PRIVATE,
        params: &[("password", MAY_BE_STRING, None), ("setting", MAY_BE_STRING, None)],
        returns: MAY_BE_STRING,
    },
    Method {
        name: "setIterationCount",
        handler: phpass_iterations,
        flags: PUBLIC,
        params: &[("count", MAY_BE_LONG, None)],
        returns: 0,
    },
    Method {
        name: "setPortableHashes",
        handler: phpass_portable,
        flags: PUBLIC,
        params: &[("portable", MAY_BE_BOOL, None)],
        returns: 0,
    },
    name_method(phpass_name),
];

fn phpass_register(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    engine::declare(class, &ITOA64, MAY_BE_STRING, Some(engine::interned(utopia_auth::phpass::ITOA64)));
    engine::inherited_properties_last(class, &["options"]);
    PHPASS.store(class, Ordering::Release);
    let name = "Utopia\\Auth\\Hashes\\PHPass";
    patch(class, PHPASS_METHODS, tables, &[("setIterationCount", name), ("setPortableHashes", name)]);
}

extern "C" fn phpass_construct(ex: &mut ExecuteData, _: &mut Zval) {
    let class = stored(&PHPASS);
    let Some(this) = this(ex) else { return };
    // `microtime() . getmypid()`.
    let state = format!("{}{}", php_std::datetime::microtime(), std::process::id());
    let _ = set_type(this, class, "phpass")
        .and_then(|()| set(this, class, "iteration_count_log2", long(8)))
        .and_then(|()| set(this, class, "portable_hashes", boolean(false)))
        .and_then(|()| set(this, class, "random_state", string(state.as_bytes())));
}

/// `$this->itoa64`.
fn itoa64(this: &mut ZendObject) -> Option<Zval> {
    engine::get(stored(&PHPASS), this, &ITOA64)
}

/// Raises `Uninitialized string offset N`, as PHP does for a read past the end of a string.
fn warn_offset(offset: usize) {
    engine::warning(&format!("Uninitialized string offset {offset}"));
}

/// [`warn_offset`] for `ord($input[N])`, which then deprecates the empty string it got.
fn warn_ord(offset: usize) {
    warn_offset(offset);
    engine::deprecated("ord(): Providing an empty string is deprecated");
}

/// `getRandomBytes($count)`: `$count` bytes of `/dev/urandom`, or phpass's MD5 fallback when
/// it cannot be read.
fn random_of(this: &mut ZendObject, count: i64) -> Option<Vec<u8>> {
    if count < 1 {
        fail("Exception", "Argument count must be a positive integer");
        return None;
    }
    let count = usize::try_from(count).unwrap_or(usize::MAX);
    let mut output = Vec::new();
    if let Ok(mut file) = std::fs::File::open("/dev/urandom") {
        use std::io::Read;
        let _ = file.by_ref().take(count as u64).read_to_end(&mut output);
    }
    if output.len() < count {
        // `$options['random_state'] = md5(microtime() . $options['random_state'])`, on a copy
        // of the options that the method then drops.
        let options = options_of(this, stored(&PHPASS))?;
        let mut state = option_read(&options, "random_state")?;
        output.clear();
        let mut i = 0;
        while i < count {
            let mut seed = php_std::datetime::microtime().into_bytes();
            seed.extend(engine::to_string(&state)?);
            let hex = php_std::string::hash(b"md5", &seed).unwrap_or_default();
            output.extend(utopia_auth::phpass::md5_raw(hex.as_bytes()));
            state = string(hex.as_bytes());
            i += 16;
        }
        output.truncate(count);
    }
    Some(output)
}

/// `$this->getRandomBytes($count)` on an object of `PHPass` or a subclass.
fn random_on(this: &mut ZendObject, count: i64) -> Option<Vec<u8>> {
    if exact(this, stored(&PHPASS)) {
        return random_of(this, count);
    }
    let bytes = engine::call_own_method(this, "getRandomBytes", &[&long(count)])?;
    Some(bytes.zend_str().map(|s| s.as_bytes().to_vec()).unwrap_or_default())
}

/// `encode64($input, $count)` over `$this->itoa64`.
fn encode64_of(this: &mut ZendObject, input: &[u8], count: i64) -> Option<Vec<u8>> {
    if count < 1 {
        fail("Exception", "Argument count must be a positive integer");
        return None;
    }
    let alphabet = itoa64(this)?;
    let alphabet = engine::to_string(&alphabet)?;
    Some(utopia_auth::phpass::encode64(
        &alphabet,
        input,
        usize::try_from(count).unwrap_or(usize::MAX),
        &mut warn_ord,
        &mut warn_offset,
    ))
}

/// `$this->encode64($input, $count)` on an object of `PHPass` or a subclass.
fn encode64_on(this: &mut ZendObject, input: &[u8], count: i64) -> Option<Vec<u8>> {
    if exact(this, stored(&PHPASS)) {
        return encode64_of(this, input, count);
    }
    let encoded = engine::call_own_method(this, "encode64", &[&string(input), &long(count)])?;
    Some(encoded.zend_str().map(|s| s.as_bytes().to_vec()).unwrap_or_default())
}

/// `gensaltPrivate($input)`: `'$P$'`, the count character, and six bytes of `input`.
fn gensalt_private_of(this: &mut ZendObject, input: &[u8]) -> Option<Vec<u8>> {
    let options = options_of(this, stored(&PHPASS))?;
    // `$this->itoa64[min($options['iteration_count_log2'] + 5, 30)]`, PHP's operators on
    // whatever the option holds.
    let count = option_read(&options, "iteration_count_log2")?;
    let sum = engine::add(&count, &long(5))?;
    let index = engine::call("min", &[&sum, &long(30)])?;
    let alphabet = itoa64(this)?;
    let character = engine::dimension_of(&alphabet, &index)?;
    let mut output = b"$P$".to_vec();
    output.extend(engine::to_string(&character)?);
    output.extend(encode64_on(this, input, 6)?);
    Some(output)
}

/// `gensaltBlowfish($input)`: `'$2a$'`, the two-digit cost, `'$'` and 16 bytes of `input`.
fn gensalt_blowfish_of(this: &mut ZendObject, input: &[u8]) -> Option<Vec<u8>> {
    let options = options_of(this, stored(&PHPASS))?;
    let count = option_read(&options, "iteration_count_log2")?;
    // `\chr(\ord('0') + \intval($count / 10))` and `\chr(\ord('0') + $count % 10)`.
    let tens = engine::call("intval", &[&engine::divide(&count, &long(10))?])?;
    let tens = engine::long_value(&tens);
    let count = option_read(&options, "iteration_count_log2")?;
    let units = engine::long_value(&engine::modulo(&count, &long(10))?);
    let digit = |n: i64| (48i64.wrapping_add(n) & 0xFF) as u8;
    let mut output = b"$2a$".to_vec();
    output.push(digit(tens));
    output.push(digit(units));
    output.push(b'$');
    output.extend(utopia_auth::phpass::blowfish_salt(input, &mut warn_ord));
    Some(output)
}

/// `cryptPrivate($password, $setting)`: the portable hash, or `'*0'` (`'*1'` when the setting
/// starts `*0`) for a setting that is not one.
fn crypt_private_of(this: &mut ZendObject, password: &[u8], setting: &[u8]) -> Option<Vec<u8>> {
    let failed = if setting.starts_with(b"*0") { b"*1".to_vec() } else { b"*0".to_vec() };
    let id = &setting[..setting.len().min(3)];
    if id != b"$P$" && id != b"$H$" {
        return Some(failed);
    }
    // `strpos($this->itoa64, $setting[3])`: past the end, `''`, which PHP finds at 0.
    let alphabet = engine::to_string(&itoa64(this)?)?;
    let position = match setting.get(3) {
        Some(c) => alphabet.iter().position(|a| a == c),
        None => {
            warn_offset(3);
            Some(0)
        }
    };
    let Some(count_log2) = position.filter(|p| (7..=30).contains(p)) else { return Some(failed) };
    let salt = setting.get(4..setting.len().min(12)).unwrap_or_default();
    if salt.len() != 8 {
        return Some(failed);
    }
    let hash = utopia_auth::phpass::md5_rounds(salt, password, 1u64 << count_log2);
    let mut output = setting[..setting.len().min(12)].to_vec();
    output.extend(encode64_on(this, &hash, 16)?);
    Some(output)
}

fn phpass_of(this: &mut ZendObject, value: &[u8]) -> Option<Vec<u8>> {
    let options = options_of(this, stored(&PHPASS))?;
    let mut random = Vec::new();
    if !engine::truthy(&option_read(&options, "portable_hashes")?) {
        random = random_on(this, 16)?;
        let hashed = php_std::string::crypt(value, &gensalt_blowfish_of(this, &random)?);
        if hashed.len() == 60 {
            return Some(hashed);
        }
    }
    if random.len() < 6 {
        random = random_on(this, 6)?;
    }
    let setting = gensalt_private_of(this, &random)?;
    let hashed = crypt_private_of(this, value, &setting)?;
    Some(if hashed.len() == 34 { hashed } else { b"*".to_vec() })
}

extern "C" fn phpass_hash(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\PHPass::hash", 1, 1) else { return };
    let Some(this) = this(ex) else { return };
    if let Some(hashed) = phpass_of(this, &args[0]) {
        set_str(retval, &hashed);
    }
}

extern "C" fn phpass_verify(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\PHPass::verify", 2, 2) else { return };
    let Some(this) = this(ex) else { return };
    let Some(mut computed) = crypt_private_of(this, &args[0], &args[1]) else { return };
    if computed.first() == Some(&b'*') {
        computed = php_std::string::crypt(&args[0], &args[1]);
    }
    set_bool(retval, utopia_auth::hash_equals(&args[1], &computed));
}

extern "C" fn phpass_random(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(count) = int(ex, "Utopia\\Auth\\Hashes\\PHPass::getRandomBytes") else { return };
    let Some(this) = this(ex) else { return };
    if let Some(bytes) = random_of(this, count) {
        set_str(retval, &bytes);
    }
}

extern "C" fn phpass_encode64(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(mut args) = args(ex, "Utopia\\Auth\\Hashes\\PHPass::encode64", 2, 2) else { return };
    let count_arg = args.remove(1);
    let Some(input) = str_arg(ex, 1, args.remove(0)) else { return };
    let Some(count) = long_arg(ex, 2, count_arg) else { return };
    let Some(this) = this(ex) else { return };
    if let Some(encoded) = encode64_of(this, &input, count) {
        set_str(retval, &encoded);
    }
}

extern "C" fn phpass_gensalt_private(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\PHPass::gensaltPrivate", 1, 1) else { return };
    let Some(this) = this(ex) else { return };
    if let Some(salt) = gensalt_private_of(this, &args[0]) {
        set_str(retval, &salt);
    }
}
extern "C" fn phpass_gensalt_blowfish(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\PHPass::gensaltBlowfish", 1, 1) else { return };
    let Some(this) = this(ex) else { return };
    if let Some(salt) = gensalt_blowfish_of(this, &args[0]) {
        set_str(retval, &salt);
    }
}
extern "C" fn phpass_crypt_private(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = strings(ex, "Utopia\\Auth\\Hashes\\PHPass::cryptPrivate", 2, 2) else { return };
    let Some(this) = this(ex) else { return };
    if let Some(hashed) = crypt_private_of(this, &args[0], &args[1]) {
        set_str(retval, &hashed);
    }
}

extern "C" fn phpass_iterations(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(count) = int(ex, "Utopia\\Auth\\Hashes\\PHPass::setIterationCount") else { return };
    if !(4..=31).contains(&count) {
        return fail("InvalidArgumentException", "Iteration count must be between 4 and 31");
    }
    let Some(this) = this(ex) else { return };
    if set(this, stored(&PHPASS), "iteration_count_log2", long(count)).is_some() {
        return_this(ex, retval);
    }
}
extern "C" fn phpass_portable(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(mut args) = args(ex, "Utopia\\Auth\\Hashes\\PHPass::setPortableHashes", 1, 1) else { return };
    let Some(portable) = bool_arg(ex, 1, args.remove(0)) else { return };
    let Some(this) = this(ex) else { return };
    if set(this, stored(&PHPASS), "portable_hashes", boolean(portable)).is_some() {
        return_this(ex, retval);
    }
}
extern "C" fn phpass_name(_: &mut ExecuteData, retval: &mut Zval) {
    set_str(retval, b"phpass");
}
