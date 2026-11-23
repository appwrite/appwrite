//! `Utopia\Detector` over [`utopia_detector`], registered when `_APP_EXTENSION_LIBRARIES` names
//! `detector`: every class of `packages/detector` (the `Detector` and `Detection` bases, the four
//! detectors, `Strategy`, and the frameworks, packagers, renderings and runtimes), so none of the
//! package's PHP classes loads.
//!
//! Built by hand like the validators ([`crate::classes`]): no Rust object behind them, their state
//! in the PHP classes' own typed properties (`$options`, `$inputs`, `$packager`, `$framework`,
//! `$strategy`, `$fallbackFile`, `Strategy`'s private `$value`), and the same inheritance chain,
//! so `get_parent_class()`, `instanceof`, `==`, `var_dump()`, `clone` and PHP subclasses behave
//! as with the PHP classes.
//!
//! The data of each framework, packager, rendering and runtime is the crate's. `detect()` asks an
//! option of a native class the crate directly, and calls the methods of any other option (a PHP
//! subclass, or a PHP class extending a base) as PHP does, so their overrides count; the ranking
//! of frameworks, manifest parsing and the static fallback are the crate's either way.

mod detections;
mod detectors;

use std::panic::AssertUnwindSafe;
use std::sync::Mutex;
use std::sync::atomic::{AtomicPtr, AtomicUsize, Ordering};

use ext_php_rs::args::{Arg, ArgInfoTables};
use ext_php_rs::builders::{ClassBuilder, FunctionBuilder};
use ext_php_rs::exception::throw_object;
use ext_php_rs::flags::{ClassFlags, DataType, MethodFlags};
use ext_php_rs::types::{ZendHashTable, ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData};

use crate::classes::engine::{self, Callee, Property};
use crate::classes::leaf::FunctionHandler;

// The classes, in the order they are registered (a parent before its children). The detectors'
// signatures name the detections, so those come first.
const DETECTION: usize = 0;
const DETECTOR: usize = 1;
const FRAMEWORK_DETECTION: usize = 2;
const JS: usize = 3;
const PACKAGER_DETECTION: usize = 4;
const RENDERING_DETECTION: usize = 5;
const RUNTIME_DETECTION: usize = 6;
const STRATEGY: usize = 7;
const FRAMEWORK: usize = 8;
// 9: `Detector\Packager`, which nothing refers to by index.
const RENDERING: usize = 10;
const RUNTIME: usize = 11;
/// The first of the frameworks ([`detections::FRAMEWORKS_TABLE`]).
const FRAMEWORKS: usize = 12;
/// The first of the packagers ([`detections::PACKAGERS_TABLE`]).
const PACKAGERS: usize = FRAMEWORKS + detections::FRAMEWORKS_TABLE.len();
const SSR: usize = PACKAGERS + detections::PACKAGERS_TABLE.len();
const XSTATIC: usize = SSR + 1;
/// The first of the runtimes ([`detections::RUNTIMES_TABLE`]).
const RUNTIMES: usize = XSTATIC + 1;
const COUNT: usize = RUNTIMES + detections::RUNTIMES_TABLE.len();

const DETECTION_NAME: &str = "Utopia\\Detector\\Detection";
const FRAMEWORK_DETECTION_NAME: &str = "Utopia\\Detector\\Detection\\Framework";
const PACKAGER_DETECTION_NAME: &str = "Utopia\\Detector\\Detection\\Packager";
const RENDERING_DETECTION_NAME: &str = "Utopia\\Detector\\Detection\\Rendering";
const RUNTIME_DETECTION_NAME: &str = "Utopia\\Detector\\Detection\\Runtime";
const STRATEGY_NAME: &str = "Utopia\\Detector\\Detector\\Strategy";

/// The registered classes, by the indexes above.
static CLASSES: [AtomicPtr<ClassEntry>; COUNT] = [const { AtomicPtr::new(std::ptr::null_mut()) }; COUNT];

/// The registered class at `index`.
fn class(index: usize) -> &'static ClassEntry {
    crate::classes::leaf::stored(&CLASSES[index])
}

/// The index of the registered class `ce`, if it is one of these.
fn index_of(ce: *const ClassEntry) -> Option<usize> {
    CLASSES.iter().position(|slot| std::ptr::eq(slot.load(Ordering::Acquire), ce))
}

// The properties, each declared once (a redeclaration shares its parent's slot).
static OPTIONS: Property = Property::new("options");
static INPUTS: Property = Property::new("inputs");
static FRAMEWORK_OPTIONS: Property = Property::new("options");
static FRAMEWORK_PACKAGER: Property = Property::new("packager");
static PACKAGER_OPTIONS: Property = Property::new("options");
static RENDERING_FRAMEWORK: Property = Property::new("framework");
static RENDERING_OPTIONS: Property = Property::new("options");
static RUNTIME_OPTIONS: Property = Property::new("options");
static RUNTIME_STRATEGY: Property = Property::new("strategy");
static RUNTIME_PACKAGER: Property = Property::new("packager");
static STRATEGY_VALUE: Property = Property::new("value");
static FRAMEWORK_DETECTION_PACKAGER: Property = Property::new("packager");
static RUNTIME_DETECTION_PACKAGER: Property = Property::new("packager");
static FALLBACK_FILE: Property = Property::new("fallbackFile");

// The methods `detect()` calls on options and strategies that are not native.
static GET_NAME: Callee = Callee::new("getName");
static GET_FILES: Callee = Callee::new("getFiles");
static GET_PACKAGES: Callee = Callee::new("getPackages");
static GET_LANGUAGES: Callee = Callee::new("getLanguages");
static GET_FILE_EXTENSIONS: Callee = Callee::new("getFileExtensions");
static SET_PACKAGER: Callee = Callee::new("setPackager");
static GET_VALUE: Callee = Callee::new("getValue");

/// A type in a method's signature.
#[derive(Clone, Copy)]
enum Ty {
    /// None: a constructor.
    None,
    Str,
    NullableStr,
    Array,
    /// `self`, which PHP declares as the class's name.
    This,
    Class(&'static str),
    NullableClass(&'static str),
}

impl Ty {
    /// The type, in a method of the class `own` (which PHP names for `self`).
    fn data_type(self, own: &'static str) -> (DataType, bool) {
        match self {
            Ty::None => (DataType::Void, false),
            Ty::Str => (DataType::String, false),
            Ty::NullableStr => (DataType::String, true),
            Ty::Array => (DataType::Array, false),
            Ty::This => (DataType::object(own), false),
            Ty::Class(name) => (DataType::object(name), false),
            Ty::NullableClass(name) => (DataType::object(name), true),
        }
    }
}

/// A parameter: its name, type and default (PHP source).
type Param = (&'static str, Ty, Option<&'static str>);

/// A method as the PHP class declares it; abstract without a handler.
struct Method {
    name: &'static str,
    handler: Option<FunctionHandler>,
    flags: MethodFlags,
    params: &'static [Param],
    returns: Ty,
}

/// A public method.
const fn method(name: &'static str, handler: FunctionHandler, params: &'static [Param], returns: Ty) -> Method {
    Method { name, handler: Some(handler), flags: MethodFlags::Public, params, returns }
}

/// A protected method.
const fn protected_method(
    name: &'static str,
    handler: FunctionHandler,
    params: &'static [Param],
    returns: Ty,
) -> Method {
    Method { name, handler: Some(handler), flags: MethodFlags::Protected, params, returns }
}

/// A public abstract method.
const fn abstract_method(name: &'static str, params: &'static [Param], returns: Ty) -> Method {
    Method { name, handler: None, flags: MethodFlags::Public.union(MethodFlags::Abstract), params, returns }
}

/// A class: its name, parent, whether it is abstract, its own methods in the PHP class's order,
/// and what `declare` adds once the engine has it (properties, constants).
struct Class {
    name: &'static str,
    parent: Option<usize>,
    is_abstract: bool,
    methods: Vec<Method>,
    declare: Declare,
}

/// What a class declares once registered: its properties and constants.
type Declare = fn(&mut ClassEntry);

fn nothing(_: &mut ClassEntry) {}

/// The class being registered (its index, and what it declares once registered) and its parent:
/// the engine asks for them inside `register()`, which runs once per class in MINIT, one at a time.
static REGISTERING: Mutex<(usize, Declare)> = Mutex::new((0, nothing));
static PARENT: AtomicUsize = AtomicUsize::new(0);

fn parent_class() -> &'static ClassEntry {
    class(PARENT.load(Ordering::Acquire))
}

fn registered(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    let (index, declare) = *REGISTERING.lock().unwrap_or_else(std::sync::PoisonError::into_inner);
    declare(class);
    if index == RENDERING_DETECTION {
        // `public function __construct(protected ?string $fallbackFile = null)`.
        if let Some(info) = engine::arg_info(class, "__construct") {
            info[1].type_.type_mask |= engine::IS_PROMOTED;
        }
    }
    CLASSES[index].store(class, Ordering::Release);
    // The engine points at the built tables for the methods it did not copy.
    Box::leak(tables);
}

fn build(index: usize, definition: Class) -> ext_php_rs::error::Result<()> {
    let mut builder = ClassBuilder::new(definition.name);
    for method in &definition.methods {
        let mut function = match method.handler {
            Some(handler) => FunctionBuilder::new(method.name, handler),
            None => FunctionBuilder::new_abstract(method.name),
        };
        for (name, ty, default) in method.params {
            let (data_type, nullable) = ty.data_type(definition.name);
            let mut arg = Arg::new(*name, data_type);
            if nullable {
                arg = arg.allow_null();
            }
            if let Some(default) = default {
                arg = arg.default(*default);
            }
            function = function.arg(arg);
        }
        function = function.required_args(method.params.iter().take_while(|(_, _, default)| default.is_none()).count());
        if !matches!(method.returns, Ty::None) {
            let (data_type, nullable) = method.returns.data_type(definition.name);
            function = function.returns(data_type, false, nullable);
        }
        builder = builder.method(function, method.flags);
    }
    if definition.is_abstract {
        builder = builder.flags(ClassFlags::Abstract);
    }
    if let Some(parent) = definition.parent {
        PARENT.store(parent, Ordering::Release);
        builder = builder.extends((parent_class, parent_name(parent)));
    }
    *REGISTERING.lock().unwrap_or_else(std::sync::PoisonError::into_inner) = (index, definition.declare);
    builder.registration(registered).register()
}

/// The name of the registered class at `index`, as `extends` takes it.
fn parent_name(index: usize) -> &'static str {
    class(index).name().unwrap_or_default()
}

/// Registers every class of `utopia-php/detector`. Called from the module's startup (MINIT)
/// when `_APP_EXTENSION_LIBRARIES` names `detector`.
pub fn register() -> ext_php_rs::error::Result<()> {
    for callee in
        [&GET_NAME, &GET_FILES, &GET_PACKAGES, &GET_LANGUAGES, &GET_FILE_EXTENSIONS, &SET_PACKAGER, &GET_VALUE]
    {
        callee.intern();
    }
    let mut classes = vec![detections::detection(), detectors::detector()];
    classes.extend(detections::bases());
    classes.extend(detectors::classes());
    classes.extend(detections::leaves());
    debug_assert_eq!(classes.len(), COUNT);
    for (index, definition) in classes.into_iter().enumerate() {
        build(index, definition)?;
    }
    Ok(())
}

// What the handlers share ---------------------------------------------------------------

/// Runs a handler's body, a panic thrown as PHP's `Error` (`Rust panic: ...`) and a bailout
/// passed on to the engine.
fn guard(body: impl FnOnce()) {
    ext_php_rs::zend::run_handler(AssertUnwindSafe(body));
}

/// The class that declares the executing method (a subclass inherits the method as it is).
fn scope(ex: &ExecuteData) -> *const ClassEntry {
    // SAFETY: the executing function and its scope live as long as the call.
    unsafe { ex.func.as_ref().map_or(std::ptr::null(), |func| func.common.scope.cast_const()) }
}

/// `Class::method`, the executing method's name as PHP's errors give it.
fn function_name(ex: &ExecuteData) -> String {
    // SAFETY: the executing function, its scope and its name live as long as the call.
    unsafe {
        let Some(func) = ex.func.as_ref() else { return String::new() };
        let class = func.common.scope.as_ref().and_then(|scope| scope.name()).unwrap_or_default();
        let name = func
            .common
            .function_name
            .cast::<ext_php_rs::types::ZendStr>()
            .as_ref()
            .and_then(|name| name.as_str().ok())
            .unwrap_or_default();
        format!("{class}::{name}")
    }
}

/// The method's arguments, at most `total`; `None` with the `ArgumentCountError` of a call with
/// fewer than `required` thrown.
fn args(ex: &mut ExecuteData, required: usize, total: usize) -> Option<Vec<&'static mut Zval>> {
    let passed = engine::args_count(ex);
    if passed < required {
        engine::too_few(ex, &function_name(ex), passed, required, total);
        return None;
    }
    Some((0..passed.min(total)).filter_map(|i| engine::arg(ex, i)).collect())
}

/// Whether `ce` is a class of this extension (not a PHP subclass): its properties have no hooks
/// and its methods are these.
fn native(ce: &ClassEntry) -> bool {
    /// `ZEND_INTERNAL_CLASS`. `zend_class_entry.type` is a C `char`: signed on x86-64, unsigned on aarch64.
    const INTERNAL_CLASS: std::ffi::c_char = 1;
    ce.type_ == INTERNAL_CLASS
}

/// `$this->property`, `property` declared by the class at `declared_in`; `None` with the
/// exception pending (an uninitialized property).
fn read(this: &mut ZendObject, declared_in: usize, property: &Property) -> Option<Zval> {
    // SAFETY: an object's class lives as long as the object.
    let ce = unsafe { &*this.ce };
    let scope = if native(ce) { ce } else { class(declared_in) };
    engine::get(scope, this, property)
}

/// `$this->property = $value`, `property` declared by the class at `declared_in`.
fn write(this: &mut ZendObject, declared_in: usize, property: &Property, value: Zval) {
    // SAFETY: an object's class lives as long as the object.
    let ce = unsafe { &*this.ce };
    let scope = if native(ce) { ce } else { class(declared_in) };
    engine::set(scope, this, property, value);
}

/// `$this->property[] = $value`, `property` (named `name`) declared by the class at
/// `declared_in`.
fn append(this: &mut ZendObject, declared_in: usize, property: &Property, name: &str, value: Zval) -> Option<()> {
    // SAFETY: an object's class lives as long as the object.
    let ce = unsafe { &*this.ce };
    if native(ce)
        && let Some(table) = engine::array_slot(ce, this, property)
    {
        return table.push(value).ok();
    }
    // A subclass's object: the array, copied with the value appended, written back.
    let current = engine::read(class(declared_in), this, name)?;
    let mut copy = current.array().map_or_else(ZendHashTable::new, ToOwned::to_owned);
    copy.push(value).ok()?;
    let mut array = Zval::new();
    array.set_hashtable(copy);
    engine::write(class(declared_in), this, name, array);
    (!engine::has_exception()).then_some(())
}

/// Returns `$this` (the methods returning `self`).
fn return_this(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(this) = crate::classes::leaf::this(ex) {
        retval.set_object(this);
    }
}

/// `items` as a PHP list of strings.
fn list<'a>(items: impl IntoIterator<Item = &'a [u8]>) -> Zval {
    let mut table = ZendHashTable::new();
    for item in items {
        let _ = table.push(engine::string(item));
    }
    if table.is_empty() {
        return engine::empty_array();
    }
    let mut array = Zval::new();
    array.set_hashtable(table);
    array
}

/// The string values of `value` (a method's array), each as PHP's `(string)` makes it, in order;
/// `None` with an exception pending. What `array_intersect()` compares.
fn strings(value: &Zval) -> Option<Vec<Vec<u8>>> {
    let Some(array) = value.array() else { return Some(Vec::new()) };
    let mut out = Vec::with_capacity(array.len());
    for item in engine::values(array) {
        out.push(engine::to_string(item.dereference())?);
    }
    Some(out)
}

/// Throws a new `class` (found by name) constructed with `message`, kept byte for byte.
fn throw(class: &str, message: &[u8]) {
    let Some(ce) = ClassEntry::try_find(class) else {
        engine::error(&String::from_utf8_lossy(message));
        return;
    };
    let mut object = ZendObject::new(ce);
    if engine::call_own_method(&mut object, "__construct", &[&engine::string(message)]).is_none() {
        return;
    }
    let mut zval = Zval::new();
    zval.set_object(&mut object);
    drop(object);
    let _ = throw_object(zval);
}
