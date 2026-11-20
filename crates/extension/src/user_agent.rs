//! `Utopia\UserAgent\UserAgent` over [`utopia_user_agent`]. What it returns are the
//! package's own PHP value objects (`OperatingSystem`, `Client`, `Device`, `Bot`),
//! built once per agent and returned as the same object on every call, as PHP does.
//! Their class entries are looked up once per request.
//!
//! The class is built by hand ([`crate::classes`]) rather than with `#[php_class]`, like
//! `Locale`: its state is `UserAgent.php`'s own private typed properties (the promoted
//! `readonly string $value` last), and its methods are plain handlers reading and writing
//! their slots. Through `#[php_class]`, every call paid for ext-php-rs's glue (a `zend_try`
//! and `catch_unwind`, and `ArgParser` for `parse()`'s string), which cost more than
//! `parse()` and `raw()` themselves.

use std::cell::Cell;
use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::args::ArgInfoTables;
use ext_php_rs::convert::IntoZval;
use ext_php_rs::exception::PhpException;
use ext_php_rs::flags::{ClassFlags, MethodFlags};
use ext_php_rs::types::{ZendHashTable, ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData, ce};
use utopia_user_agent::{Text, detection};

use crate::classes::engine::{
    self, Declared, MAY_BE_ARRAY, MAY_BE_BOOL, MAY_BE_NULL, MAY_BE_STRING, Property, PropertyType, get, has_exception,
    initialize, set, str_zval_arg, too_few,
};
use crate::classes::leaf::{self, Method, PRIVATE, PUBLIC, stored, this};

/// A public static method.
const PUBLIC_STATIC: MethodFlags = MethodFlags::Public.union(MethodFlags::Static);

static USER_AGENT: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());

// The private properties of `UserAgent.php`, in its declaration order.
static OPERATING_SYSTEM_PROPERTY: Property = Property::new("operatingSystem");
static CLIENT_PROPERTY: Property = Property::new("client");
static DEVICE_PROPERTY: Property = Property::new("device");
static BOT_PROPERTY: Property = Property::new("bot");
static BOT_RESOLVED: Property = Property::new("botResolved");
/// `private readonly string $value`, promoted by the constructor.
static VALUE: Property = Property::new("value");

/// The package's value classes, in the order of [`CLASSES`].
const NAMES: [&str; 4] = [
    "Utopia\\UserAgent\\OperatingSystem",
    "Utopia\\UserAgent\\Client",
    "Utopia\\UserAgent\\Device",
    "Utopia\\UserAgent\\Bot",
];
const OPERATING_SYSTEM: usize = 0;
const CLIENT: usize = 1;
const DEVICE: usize = 2;
const BOT: usize = 3;

thread_local! {
    /// The class entry of each of [`NAMES`] once found this request.
    static CLASSES: [Cell<*const ClassEntry>; 4] = const { [const { Cell::new(std::ptr::null()) }; 4] };
}

/// Forgets the class entries at the end of a request, when PHP frees user classes.
pub fn request_shutdown() {
    CLASSES.with(|classes| classes.iter().for_each(|class| class.set(std::ptr::null())));
}

/// The class entry of `NAMES[index]`, autoloading it as PHP does on first use; `None` with
/// the `Error` pending.
fn class(index: usize) -> Option<&'static ClassEntry> {
    let cached = CLASSES.with(|classes| classes[index].get());
    // SAFETY: a user class entry lives until the request ends, when the pointer is forgotten.
    if let Some(entry) = unsafe { cached.as_ref() } {
        return Some(entry);
    }
    let name = NAMES[index];
    let Some(entry) = ClassEntry::try_find(name) else {
        if !has_exception() {
            PhpException::new(format!("Class \"{name}\" not found"), 0, ce::error()).throw();
        }
        return None;
    };
    CLASSES.with(|classes| classes[index].set(entry));
    Some(entry)
}

/// A PHP string of `bytes`, or `null`.
fn text(value: Option<&[u8]>) -> Zval {
    let mut zval = Zval::new();
    match value {
        Some(bytes) => zval.set_zend_string(ZendStr::new(bytes, false)),
        None => zval.set_null(),
    }
    zval
}

/// `new $class(...$arguments)` for the class `NAMES[index]`, calling its constructor
/// as `new` does: by the class's constructor pointer, not by name. `None` with the
/// exception pending when the class is missing or its constructor threw.
fn object<const N: usize>(index: usize, arguments: [Option<&Text<'_>>; N]) -> Option<Zval> {
    let entry = class(index)?;
    let mut object = entry.new();
    let mut arguments = arguments.map(|argument| text(argument.map(AsRef::as_ref)));
    if !entry.constructor.is_null() {
        let mut result = Zval::new();
        // SAFETY: the constructor is the class's own, called on a new object of that class
        // with initialized arguments that outlive the call, as the `NEW` opcode does.
        unsafe {
            ext_php_rs::ffi::zend_call_known_function(
                entry.constructor,
                std::ptr::from_mut(&mut *object),
                std::ptr::from_ref(entry).cast_mut(),
                &raw mut result,
                N as u32,
                arguments.as_mut_ptr(),
                std::ptr::null_mut(),
            );
        }
        if has_exception() {
            return None;
        }
    }
    object.into_zval(false).ok()
}

/// The bytes of a string [`Zval`].
fn bytes(value: &Zval) -> &[u8] {
    value.zend_str().map_or(&[], ZendStr::as_bytes)
}

/// `$this->property ??= detect($this->value)`: the object detected once, then the same one.
/// `None` with the exception pending.
fn detected(this: &mut ZendObject, property: &Property, detect: impl FnOnce(&[u8]) -> Option<Zval>) -> Option<Zval> {
    let scope = stored(&USER_AGENT);
    let current = get(scope, this, property)?;
    if !current.is_null() {
        return Some(current);
    }
    let value = get(scope, this, &VALUE)?;
    let found = detect(bytes(&value))?;
    set(scope, this, property, found.shallow_clone());
    Some(found)
}

fn operating_system_of(this: &mut ZendObject) -> Option<Zval> {
    detected(this, &OPERATING_SYSTEM_PROPERTY, |value| {
        let found = detection::operating_system(value);
        object(OPERATING_SYSTEM, [found.code.as_ref(), found.name.as_ref(), found.version.as_ref()])
    })
}

fn client_of(this: &mut ZendObject) -> Option<Zval> {
    detected(this, &CLIENT_PROPERTY, |value| {
        let found = detection::client(value);
        object(
            CLIENT,
            [
                found.kind.as_ref(),
                found.code.as_ref(),
                found.name.as_ref(),
                found.version.as_ref(),
                found.engine.as_ref(),
                found.engine_version.as_ref(),
            ],
        )
    })
}

fn device_of(this: &mut ZendObject) -> Option<Zval> {
    detected(this, &DEVICE_PROPERTY, |value| {
        let found = detection::device(value);
        object(DEVICE, [found.kind.as_ref(), found.brand.as_ref(), found.model.as_ref()])
    })
}

/// `bot()`: detected once (`$botResolved`), then the same object or `null`.
fn bot_of(this: &mut ZendObject) -> Option<Zval> {
    let scope = stored(&USER_AGENT);
    if !engine::truthy(&get(scope, this, &BOT_RESOLVED)?) {
        let value = get(scope, this, &VALUE)?;
        let bot = match detection::bot(bytes(&value)) {
            Some(found) => object(BOT, [Some(&found.name), Some(&found.category)])?,
            None => Zval::null(),
        };
        set(scope, this, &BOT_PROPERTY, bot);
        let mut resolved = Zval::new();
        resolved.set_bool(true);
        set(scope, this, &BOT_RESOLVED, resolved);
    }
    get(scope, this, &BOT_PROPERTY)
}

/// `$value->toArray()`, or `null` for `null` (`?->`). `None` with the exception pending.
fn fields(value: &Zval) -> Option<Zval> {
    match value.object() {
        Some(object) => object.try_call_method("toArray", vec![]).ok(),
        None => Some(Zval::null()),
    }
}

// Handlers ----------------------------------------------------------------------------------

/// `private function __construct(private readonly string $value)`.
extern "C" fn construct(ex: &mut ExecuteData, _: &mut Zval) {
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\UserAgent\\UserAgent::__construct", engine::args_count(ex), 1, 1);
        return;
    };
    let Some(value) = str_zval_arg(ex, 1, arg) else { return };
    if let Some(this) = this(ex) {
        // A second initialization of the readonly property goes through the engine, which
        // refuses it.
        initialize(stored(&USER_AGENT), this, &VALUE, value);
    }
}

/// `parse(string $value): self`: `new self($value)`, with `$value` initialized as the
/// constructor initializes it.
extern "C" fn parse(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, "Utopia\\UserAgent\\UserAgent::parse", engine::args_count(ex), 1, 1);
        return;
    };
    let Some(value) = str_zval_arg(ex, 1, arg) else { return };
    let scope = stored(&USER_AGENT);
    let mut object = ZendObject::new(scope);
    initialize(scope, &mut object, &VALUE, value);
    // The box's reference moves to the return value.
    if let Err(error) = object.set_zval(retval, false) {
        PhpException::from(error).throw();
    }
}

/// `raw(): string`.
extern "C" fn raw(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    if let Some(value) = get(stored(&USER_AGENT), this, &VALUE) {
        *retval = value;
    }
}

/// Returns what `of` gives for `$this`; nothing, with the exception pending, when it threw.
fn answer(ex: &mut ExecuteData, retval: &mut Zval, of: Category) {
    let Some(this) = this(ex) else { return };
    if let Some(value) = of(this) {
        *retval = value;
    }
}

extern "C" fn operating_system(ex: &mut ExecuteData, retval: &mut Zval) {
    answer(ex, retval, operating_system_of);
}

extern "C" fn client(ex: &mut ExecuteData, retval: &mut Zval) {
    answer(ex, retval, client_of);
}

extern "C" fn device(ex: &mut ExecuteData, retval: &mut Zval) {
    answer(ex, retval, device_of);
}

extern "C" fn bot(ex: &mut ExecuteData, retval: &mut Zval) {
    answer(ex, retval, bot_of);
}

/// `isBot(): bool`: `$this->bot() instanceof Bot`.
extern "C" fn is_bot(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    if let Some(bot) = bot_of(this) {
        retval.set_bool(bot.is_object());
    }
}

/// A category's object (or `null`) for `$this`; `None` with the exception pending.
type Category = fn(&mut ZendObject) -> Option<Zval>;

/// What `toArray()` holds, in `UserAgent.php`'s order: each key and its category.
const CATEGORIES: [(&str, Category); 4] =
    [("os", operating_system_of), ("client", client_of), ("device", device_of), ("bot", bot_of)];

/// `toArray(): array`: each category's `toArray()`.
extern "C" fn to_array(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let mut result = ZendHashTable::with_capacity(4);
    for (key, of) in CATEGORIES {
        let Some(value) = of(this) else { return };
        let Some(value) = fields(&value) else { return };
        if let Err(error) = result.insert(key, value) {
            PhpException::from(error).throw();
            return;
        }
    }
    retval.set_hashtable(result);
}

/// `string $value`.
const VALUE_PARAM: &[leaf::Param] = &[("value", MAY_BE_STRING, None)];

/// The methods, in `UserAgent.php`'s order. A return of 0 is a class, set by [`register_class`].
const METHODS: &[Method] = &[
    Method { name: "__construct", handler: construct, flags: PRIVATE, params: VALUE_PARAM, returns: 0 },
    Method { name: "parse", handler: parse, flags: PUBLIC_STATIC, params: VALUE_PARAM, returns: 0 },
    Method { name: "raw", handler: raw, flags: PUBLIC, params: &[], returns: MAY_BE_STRING },
    Method { name: "operatingSystem", handler: operating_system, flags: PUBLIC, params: &[], returns: 0 },
    Method { name: "client", handler: client, flags: PUBLIC, params: &[], returns: 0 },
    Method { name: "device", handler: device, flags: PUBLIC, params: &[], returns: 0 },
    Method { name: "bot", handler: bot, flags: PUBLIC, params: &[], returns: 0 },
    Method { name: "isBot", handler: is_bot, flags: PUBLIC, params: &[], returns: MAY_BE_BOOL },
    Method { name: "toArray", handler: to_array, flags: PUBLIC, params: &[], returns: MAY_BE_ARRAY },
];

/// Declares `UserAgent.php`'s properties in its order, and the methods' class return types.
fn register_class(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    let body = Declared { private: true, readonly: false, promoted: false };
    for (property, index) in [
        (&OPERATING_SYSTEM_PROPERTY, OPERATING_SYSTEM),
        (&CLIENT_PROPERTY, CLIENT),
        (&DEVICE_PROPERTY, DEVICE),
        (&BOT_PROPERTY, BOT),
    ] {
        let ty = PropertyType::Class { name: NAMES[index], nullable: true };
        engine::declare_property(class, property, ty, body, Some(Zval::null()));
    }
    let mut resolved = Zval::new();
    resolved.set_bool(false);
    engine::declare_property(class, &BOT_RESOLVED, PropertyType::Mask(MAY_BE_BOOL), body, Some(resolved));
    let promoted = Declared { private: true, readonly: true, promoted: true };
    engine::declare_property(class, &VALUE, PropertyType::Mask(MAY_BE_STRING), promoted, None);
    USER_AGENT.store(class, Ordering::Release);
    leaf::patch(class, METHODS, tables, &[]);
    for (method, name, nullable) in [
        ("parse", "Utopia\\UserAgent\\UserAgent", false),
        ("operatingSystem", NAMES[OPERATING_SYSTEM], false),
        ("client", NAMES[CLIENT], false),
        ("device", NAMES[DEVICE], false),
        ("bot", NAMES[BOT], true),
    ] {
        if let Some(info) = engine::arg_info(class, method) {
            engine::set_class_type(&mut info[0], name);
            if nullable {
                info[0].type_.type_mask |= MAY_BE_NULL;
            }
        }
    }
}

/// Registers `Utopia\UserAgent\UserAgent`. Called from the module's startup (MINIT).
pub fn register() -> ext_php_rs::error::Result<()> {
    leaf::class("Utopia\\UserAgent\\UserAgent", METHODS)
        .flags(ClassFlags::Final)
        .registration(register_class)
        .register()
}
