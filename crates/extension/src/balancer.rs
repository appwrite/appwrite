//! `Utopia\Balancer` over [`utopia_balancer`], built by hand like the detector (see the README).

use std::ffi::{CStr, c_char, c_void};
use std::panic::AssertUnwindSafe;
use std::sync::atomic::{AtomicPtr, AtomicUsize, Ordering};
use std::sync::{Mutex, OnceLock};

use ext_php_rs::args::{Arg, ArgInfoTables};
use ext_php_rs::builders::{ClassBuilder, FunctionBuilder};
use ext_php_rs::exception::throw;
use ext_php_rs::ffi::{
    _zend_new_array, HashTable, instanceof_function_slow, zend_class_entry, zend_execute_data, zend_function,
    zend_hash_add, zend_hash_del, zend_hash_find, zend_hash_index_del, zend_hash_index_find,
    zend_hash_next_index_insert, zend_is_true, zend_lookup_class_ex, zend_object, zend_string, zval,
};
use ext_php_rs::flags::{ClassFlags, DataType, MethodFlags};
use ext_php_rs::types::{ZendHashTable, ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData, ce};
use utopia_balancer::{Algorithm, First, Last, RoundRobin};

use crate::classes::engine::{self, Callee, Declared, Literal, MAY_BE_ARRAY, MAY_BE_LONG, MAY_BE_NULL, MAY_BE_STRING};
use crate::classes::engine::{Property, PropertyType};
use crate::classes::leaf::{FunctionHandler, stored, this};

// The classes, in the order they are registered (a parent before its children).
const OPTION: usize = 0;
const ALGORITHM: usize = 1;
const FIRST: usize = 2;
const LAST: usize = 3;
const RANDOM: usize = 4;
const ROUND_ROBIN: usize = 5;
const BALANCER: usize = 6;
const GROUP: usize = 7;
const COUNT: usize = 8;

const OPTION_NAME: &str = "Utopia\\Balancer\\Option";
const ALGORITHM_NAME: &str = "Utopia\\Balancer\\Algorithm";
const BALANCER_NAME: &str = "Utopia\\Balancer\\Balancer";
const TELEMETRY_NAME: &str = "Utopia\\Telemetry\\Adapter";
const NO_TELEMETRY_NAME: &str = "Utopia\\Telemetry\\Adapter\\None";
const HISTOGRAM_NAME: &str = "Utopia\\Telemetry\\Histogram";

/// The registered classes, by the indexes above.
static CLASSES: [AtomicPtr<ClassEntry>; COUNT] = [const { AtomicPtr::new(std::ptr::null_mut()) }; COUNT];

#[inline(always)]
fn class(index: usize) -> &'static ClassEntry {
    stored(&CLASSES[index])
}

/// Methods as registered, to spot overrides and to name return type errors.
static RUN: [AtomicPtr<zend_function>; COUNT] = [const { AtomicPtr::new(std::ptr::null_mut()) }; COUNT];
static GET_FILTERED_OPTIONS: AtomicPtr<zend_function> = AtomicPtr::new(std::ptr::null_mut());
static GET_OPTIONS: AtomicPtr<zend_function> = AtomicPtr::new(std::ptr::null_mut());
static SET_TELEMETRY_FUNCTION: AtomicPtr<zend_function> = AtomicPtr::new(std::ptr::null_mut());

// The properties, in the order the PHP classes declare them.
static STATE: Property = Property::new("state");
static ALGO: Property = Property::new("algo");
static FILTERS: Property = Property::new("filters");
static OPTIONS: Property = Property::new("options");
static INDEX: Property = Property::new("index");
static BALANCERS: Property = Property::new("balancers");
static RUN_HISTOGRAM: Property = Property::new("runHistogram");
static NAME: Property = Property::new("name");

// The names `getName()` returns.
static FIRST_NAME: Literal = Literal::new("First");
static LAST_NAME: Literal = Literal::new("Last");
static RANDOM_NAME: Literal = Literal::new("Random");
static ROUND_ROBIN_NAME: Literal = Literal::new("RoundRobin");

// The methods called on PHP objects.
static RUN_METHOD: Callee = Callee::new("run");
static GET_FILTERED_OPTIONS_METHOD: Callee = Callee::new("getFilteredOptions");
static GET_OPTIONS_METHOD: Callee = Callee::new("getOptions");
static SET_TELEMETRY: Callee = Callee::new("setTelemetry");
static CREATE_HISTOGRAM: Callee = Callee::new("createHistogram");
static RECORD: Callee = Callee::new("record");

/// What `Group` passes to the telemetry, built once in MINIT.
struct Frozen {
    /// `createHistogram()`'s arguments, the integer boundaries kept integers as PHP wrote them.
    histogram: [Zval; 4],
    /// `'name'`, and `['name' => null]` for a group without a name.
    name_key: Zval,
    unnamed: Zval,
    /// The classes `Group` names, and their lowercase keys, interned for lookups.
    no_telemetry: Zval,
    no_telemetry_key: Zval,
    telemetry: Zval,
    telemetry_key: Zval,
    histogram_class: Zval,
    histogram_class_key: Zval,
}

// SAFETY: interned strings and immutable persistent arrays made in MINIT, never reference counted.
unsafe impl Send for Frozen {}
// SAFETY: as above.
unsafe impl Sync for Frozen {}

static FROZEN: OnceLock<Frozen> = OnceLock::new();

#[inline(always)]
fn frozen() -> &'static Frozen {
    FROZEN.get().expect("built in MINIT")
}

// The engine ---------------------------------------------------------------------------------

/// `zend_fcall_info`.
#[repr(C)]
struct FcallInfo {
    size: usize,
    // Borrowed: the engine's `zval` releases what it holds when dropped.
    function_name: std::mem::ManuallyDrop<zval>,
    retval: *mut zval,
    params: *mut zval,
    object: *mut zend_object,
    param_count: u32,
    named_params: *mut HashTable,
}

/// `zend_fcall_info_cache`.
#[repr(C)]
struct FcallCache {
    function_handler: *mut zend_function,
    calling_scope: *mut zend_class_entry,
    called_scope: *mut zend_class_entry,
    object: *mut zend_object,
    closure: *mut zend_object,
}

/// `php_random_algo_with_state`.
#[repr(C)]
#[derive(Clone, Copy)]
struct RandomEngine {
    algo: *const c_void,
    state: *mut c_void,
}

unsafe extern "C" {
    fn zend_is_callable_at_frame(
        callable: *mut zval,
        object: *mut zend_object,
        frame: *mut zend_execute_data,
        check_flags: u32,
        fcc: *mut FcallCache,
        error: *mut *mut c_char,
    ) -> bool;
    fn zend_release_fcall_info_cache(fcc: *mut c_void);
    fn zend_call_function(fci: *mut FcallInfo, fcc: *mut FcallCache) -> i32;
    fn _zend_handle_numeric_str_ex(key: *const c_char, length: usize, idx: *mut u64) -> bool;
    fn zend_hash_lookup(ht: *mut HashTable, key: *mut zend_string) -> *mut zval;
    fn zend_hash_index_lookup(ht: *mut HashTable, h: u64) -> *mut zval;
    fn _zend_hash_init(ht: *mut HashTable, size: u32, destructor: *mut c_void, persistent: bool);
    fn zend_hash_real_init_packed(ht: *mut HashTable);
    fn zend_array_dup(source: *mut HashTable) -> *mut HashTable;
    fn zend_assign_to_typed_ref(variable: *mut zval, value: *mut zval, value_type: u8, strict: bool) -> *mut zval;
    fn zend_verify_return_error(function: *const zend_function, value: *const zval);
    fn object_init_ex(arg: *mut zval, ce: *mut zend_class_entry) -> i32;
    fn php_mt_rand_range(min: i64, max: i64) -> i64;
    fn php_random_default_algo() -> *const c_void;
    fn php_random_default_status() -> *mut c_void;
    fn php_array_pick_keys(
        engine: RandomEngine,
        input: *mut zval,
        num_req: i64,
        retval: *mut zval,
        silent: bool,
    ) -> bool;
}

const IS_UNDEF: u8 = 0;
const IS_NULL: u8 = 1;
const IS_LONG: u8 = 4;
const IS_STRING: u8 = 6;
const IS_ARRAY: u8 = 7;
const IS_OBJECT: u8 = 8;
const IS_REFERENCE: u8 = 10;
/// `IS_CV`: a value the assignment copies (adding a reference) rather than takes.
const IS_CV: u8 = 8;
/// `ZEND_FETCH_CLASS_NO_AUTOLOAD`.
const NO_AUTOLOAD: u32 = 0x80;
/// `ZEND_INTERNAL_FUNCTION`.
const INTERNAL_FUNCTION: u8 = 1;
/// `HASH_FLAG_PACKED`, `HASH_FLAG_UNINITIALIZED`.
const HASH_PACKED: u32 = 1 << 2;
/// `GC_IMMUTABLE`, `GC_NOT_COLLECTABLE | GC_IMMUTABLE | GC_PERSISTENT`.
const GC_IMMUTABLE: u32 = 1 << 6;
const IMMUTABLE_ARRAY: u32 = (1 << 4) | (1 << 6) | (1 << 7);

#[inline(always)]
fn type_of(value: &Zval) -> u8 {
    // SAFETY: reading a live zval's type byte.
    (unsafe { value.u1.type_info } & 0xff) as u8
}

/// `ZVAL_DEREF`.
#[inline(always)]
fn deref(value: &Zval) -> &Zval {
    if type_of(value) == IS_REFERENCE { value.dereference() } else { value }
}

/// A copy of `value` dereferenced, holding its own reference (`ZVAL_COPY_DEREF`).
#[inline(always)]
fn copy(value: &Zval) -> Zval {
    deref(value).shallow_clone()
}

/// The table of an array zval.
#[inline(always)]
fn table(value: &Zval) -> *mut HashTable {
    // SAFETY: the caller checked the zval holds an array.
    unsafe { value.value.arr }
}

/// Whether `object`'s class is `ce` or extends it.
#[inline(always)]
fn instance_of(object: &ZendObject, ce: &ClassEntry) -> bool {
    std::ptr::eq(object.ce, ce)
        // SAFETY: two live classes.
        || unsafe { instanceof_function_slow(object.ce, std::ptr::from_ref(ce).cast()) }
}

/// Whether `name` on `ce` is still `ours`: a subclass's copy of an internal method keeps its handler.
#[inline]
fn inherits(ce: &ClassEntry, name: &str, ours: &AtomicPtr<zend_function>) -> bool {
    let ours = ours.load(Ordering::Acquire);
    // SAFETY: function tables live as long as their class; `ours` was stored in MINIT.
    unsafe {
        let found =
            ext_php_rs::ffi::zend_hash_str_find_ptr_lc(&raw const ce.function_table, name.as_ptr().cast(), name.len())
                .cast::<zend_function>();
        if found == ours {
            return true;
        }
        let (Some(found), Some(ours)) = (found.as_ref(), ours.as_ref()) else { return false };
        // A copy keeps the handler's address, which is all that is compared.
        found.type_ == INTERNAL_FUNCTION
            && found.internal_function.handler.map(|f| f as usize) == ours.internal_function.handler.map(|f| f as usize)
    }
}

/// Whether `ce` runs the algorithm at `index` natively (itself, or a subclass not overriding `run()`).
#[inline(always)]
fn runs(ce: &ClassEntry, index: usize) -> bool {
    std::ptr::eq(ce, class(index))
        || (!is_internal(ce) && instance_of_ce(ce, class(index)) && inherits(ce, "run", &RUN[index]))
}

#[inline(always)]
fn instance_of_ce(ce: &ClassEntry, parent: &ClassEntry) -> bool {
    // SAFETY: two live classes.
    std::ptr::eq(ce, parent) || unsafe { instanceof_function_slow(std::ptr::from_ref(ce), std::ptr::from_ref(parent)) }
}

/// `ZEND_INTERNAL_CLASS`; `zend_class_entry.type` is a C `char`, signed on x86-64.
#[inline(always)]
fn is_internal(ce: &ClassEntry) -> bool {
    ce.type_ == 1
}

// Registration ------------------------------------------------------------------------------

/// A type in a method's signature.
#[derive(Clone, Copy)]
enum Ty {
    /// None: a constructor.
    None,
    Str,
    NullableStr,
    Int,
    Array,
    Mixed,
    Callable,
    Void,
    /// `self`, which PHP declares as the class's name.
    This,
    Class(&'static str),
    NullableClass(&'static str),
}

impl Ty {
    fn data_type(self, own: &'static str) -> (DataType, bool) {
        match self {
            Ty::None | Ty::Void => (DataType::Void, false),
            Ty::Str => (DataType::String, false),
            Ty::NullableStr => (DataType::String, true),
            Ty::Int => (DataType::Long, false),
            Ty::Array => (DataType::Array, false),
            Ty::Mixed => (DataType::Mixed, false),
            Ty::Callable => (DataType::Callable, false),
            Ty::This => (DataType::object(own), false),
            Ty::Class(name) => (DataType::object(name), false),
            Ty::NullableClass(name) => (DataType::object(name), true),
        }
    }
}

type Param = (&'static str, Ty, Option<&'static str>);

/// A method as the PHP class declares it; abstract without a handler.
struct Method {
    name: &'static str,
    handler: Option<FunctionHandler>,
    params: &'static [Param],
    returns: Ty,
}

const fn method(name: &'static str, handler: FunctionHandler, params: &'static [Param], returns: Ty) -> Method {
    Method { name, handler: Some(handler), params, returns }
}

const fn abstract_method(name: &'static str, params: &'static [Param], returns: Ty) -> Method {
    Method { name, handler: None, params, returns }
}

/// A class: its name, parent, abstractness, methods in the PHP class's order, and what it declares.
struct Class {
    name: &'static str,
    parent: Option<usize>,
    is_abstract: bool,
    methods: Vec<Method>,
    declare: Declare,
}

/// What a class declares once registered: its properties.
type Declare = fn(&mut ClassEntry);

fn nothing(_: &mut ClassEntry) {}

/// The class being registered, which the engine's `registration` callback reads (MINIT, one at a time).
static REGISTERING: Mutex<(usize, Declare)> = Mutex::new((0, nothing));
static PARENT: AtomicUsize = AtomicUsize::new(0);

fn parent_class() -> &'static ClassEntry {
    class(PARENT.load(Ordering::Acquire))
}

fn registered(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    let (index, declare) = *REGISTERING.lock().unwrap_or_else(std::sync::PoisonError::into_inner);
    declare(class);
    if index == GROUP
        && let Some(info) = engine::arg_info(class, "__construct")
    {
        // `public function __construct(private ?string $name = null)`.
        info[1].type_.type_mask |= engine::IS_PROMOTED;
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
        let flags =
            if method.handler.is_none() { MethodFlags::Public | MethodFlags::Abstract } else { MethodFlags::Public };
        builder = builder.method(function, flags);
    }
    if definition.is_abstract {
        builder = builder.flags(ClassFlags::Abstract);
    }
    if let Some(parent) = definition.parent {
        PARENT.store(parent, Ordering::Release);
        builder = builder.extends((parent_class, class(parent).name().unwrap_or_default()));
    }
    *REGISTERING.lock().unwrap_or_else(std::sync::PoisonError::into_inner) = (index, definition.declare);
    builder.registration(registered).register()
}

const PRIVATE: Declared = Declared { private: true, readonly: false, promoted: false };
const PROTECTED: Declared = Declared { private: false, readonly: false, promoted: false };

fn declare_option(class: &mut ClassEntry) {
    engine::declare_property(class, &STATE, PropertyType::Mask(MAY_BE_ARRAY), PRIVATE, None);
}

fn declare_round_robin(class: &mut ClassEntry) {
    engine::declare_property(class, &INDEX, PropertyType::Mask(MAY_BE_LONG), PRIVATE, None);
}

fn declare_balancer(class: &mut ClassEntry) {
    let algorithm = PropertyType::Class { name: ALGORITHM_NAME, nullable: false };
    engine::declare_property(class, &ALGO, algorithm, PRIVATE, None);
    engine::declare_property(class, &FILTERS, PropertyType::Mask(MAY_BE_ARRAY), PRIVATE, Some(engine::empty_array()));
    engine::declare_property(class, &OPTIONS, PropertyType::Mask(MAY_BE_ARRAY), PRIVATE, Some(engine::empty_array()));
}

fn declare_group(class: &mut ClassEntry) {
    let histogram = PropertyType::Class { name: HISTOGRAM_NAME, nullable: false };
    engine::declare_property(
        class,
        &BALANCERS,
        PropertyType::Mask(MAY_BE_ARRAY),
        PROTECTED,
        Some(engine::empty_array()),
    );
    engine::declare_property(class, &RUN_HISTOGRAM, histogram, PRIVATE, None);
    let promoted = Declared { promoted: true, ..PRIVATE };
    engine::declare_property(class, &NAME, PropertyType::Mask(MAY_BE_STRING | MAY_BE_NULL), promoted, None);
}

const OPTIONS_PARAM: &[Param] = &[("options", Ty::Array, None)];
const RUN_RETURNS: Ty = Ty::NullableClass(OPTION_NAME);

fn algorithm(name: &'static str, index: usize, get_name: FunctionHandler, run: FunctionHandler) -> Class {
    let mut methods = vec![method("getName", get_name, &[], Ty::Str)];
    if index == ROUND_ROBIN {
        methods.extend([
            method("__construct", round_robin_construct, &[("lastIndex", Ty::Int, None)], Ty::None),
            method("getIndex", round_robin_get_index, &[], Ty::Int),
            method("setIndex", round_robin_set_index, &[("lastIndex", Ty::Int, None)], Ty::This),
        ]);
    }
    methods.push(method("run", run, OPTIONS_PARAM, RUN_RETURNS));
    let declare = if index == ROUND_ROBIN { declare_round_robin } else { nothing };
    Class { name, parent: Some(ALGORITHM), is_abstract: false, methods, declare }
}

fn classes() -> Vec<Class> {
    vec![
        Class {
            name: OPTION_NAME,
            parent: None,
            is_abstract: false,
            methods: vec![
                method("__construct", option_construct, &[("state", Ty::Array, None)], Ty::None),
                method("setState", option_set_state, &[("key", Ty::Str, None), ("value", Ty::Mixed, None)], Ty::This),
                method(
                    "getState",
                    option_get_state,
                    &[("key", Ty::Str, None), ("default", Ty::Mixed, Some("null"))],
                    Ty::Mixed,
                ),
                method("deleteState", option_delete_state, &[("key", Ty::Str, None)], Ty::This),
                method("getStates", option_get_states, &[], Ty::Array),
            ],
            declare: declare_option,
        },
        Class {
            name: ALGORITHM_NAME,
            parent: None,
            is_abstract: true,
            methods: vec![abstract_method("run", OPTIONS_PARAM, RUN_RETURNS), abstract_method("getName", &[], Ty::Str)],
            declare: nothing,
        },
        algorithm("Utopia\\Balancer\\Algorithm\\First", FIRST, first_get_name, first_run),
        algorithm("Utopia\\Balancer\\Algorithm\\Last", LAST, last_get_name, last_run),
        algorithm("Utopia\\Balancer\\Algorithm\\Random", RANDOM, random_get_name, random_run),
        algorithm("Utopia\\Balancer\\Algorithm\\RoundRobin", ROUND_ROBIN, round_robin_get_name, round_robin_run),
        Class {
            name: BALANCER_NAME,
            parent: None,
            is_abstract: false,
            methods: vec![
                method("__construct", balancer_construct, &[("algo", Ty::Class(ALGORITHM_NAME), None)], Ty::None),
                method("getAlgo", balancer_get_algo, &[], Ty::Class(ALGORITHM_NAME)),
                method("addOption", balancer_add_option, &[("option", Ty::Class(OPTION_NAME), None)], Ty::This),
                method("getOptions", balancer_get_options, &[], Ty::Array),
                method("addFilter", balancer_add_filter, &[("filter", Ty::Callable, None)], Ty::This),
                method("getFilteredOptions", balancer_get_filtered_options, &[], Ty::Array),
                method("run", balancer_run, &[], RUN_RETURNS),
            ],
            declare: declare_balancer,
        },
        Class {
            name: "Utopia\\Balancer\\Group",
            parent: None,
            is_abstract: false,
            methods: vec![
                method("__construct", group_construct, &[("name", Ty::NullableStr, Some("null"))], Ty::None),
                method(
                    "setTelemetry",
                    group_set_telemetry,
                    &[("telemetry", Ty::Class(TELEMETRY_NAME), None)],
                    Ty::Void,
                ),
                method("add", group_add, &[("balancer", Ty::Class(BALANCER_NAME), None)], Ty::This),
                method("getOptions", group_get_options, &[], Ty::Array),
                method("run", group_run, &[], RUN_RETURNS),
            ],
            declare: declare_group,
        },
    ]
}

/// The method `name` of the registered class at `index`.
fn function(index: usize, name: &str) -> *mut zend_function {
    let ce = class(index);
    // SAFETY: a registered class's function table, alive for the process.
    unsafe {
        ext_php_rs::ffi::zend_hash_str_find_ptr_lc(&raw const ce.function_table, name.as_ptr().cast(), name.len())
            .cast::<zend_function>()
    }
}

/// Registers every class of `utopia-php/balancer` (MINIT), when `balancer` is switched on.
pub fn register() -> ext_php_rs::error::Result<()> {
    engine::intern(&[&FIRST_NAME, &LAST_NAME, &RANDOM_NAME, &ROUND_ROBIN_NAME]);
    for callee in
        [&RUN_METHOD, &GET_FILTERED_OPTIONS_METHOD, &GET_OPTIONS_METHOD, &SET_TELEMETRY, &CREATE_HISTOGRAM, &RECORD]
    {
        callee.intern();
    }
    FROZEN.get_or_init(|| {
        let boundaries: Vec<Zval> = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75]
            .into_iter()
            .map(double)
            .chain([long(1), double(2.5), long(5), double(7.5), long(10)])
            .collect();
        let name_key = engine::interned(b"name");
        Frozen {
            histogram: [
                engine::interned(b"balancer.run.duration"),
                engine::interned(b"s"),
                Zval::null(),
                persistent_map(&[(engine::interned(b"ExplicitBucketBoundaries"), persistent_list(boundaries))]),
            ],
            unnamed: persistent_map(&[(name_key.shallow_clone(), Zval::null())]),
            name_key,
            no_telemetry: engine::interned(NO_TELEMETRY_NAME.as_bytes()),
            no_telemetry_key: engine::interned(NO_TELEMETRY_NAME.to_ascii_lowercase().as_bytes()),
            telemetry: engine::interned(TELEMETRY_NAME.as_bytes()),
            telemetry_key: engine::interned(TELEMETRY_NAME.to_ascii_lowercase().as_bytes()),
            histogram_class: engine::interned(HISTOGRAM_NAME.as_bytes()),
            histogram_class_key: engine::interned(HISTOGRAM_NAME.to_ascii_lowercase().as_bytes()),
        }
    });
    for (index, definition) in classes().into_iter().enumerate() {
        build(index, definition)?;
    }
    for index in [FIRST, LAST, RANDOM, ROUND_ROBIN, BALANCER, GROUP] {
        RUN[index].store(function(index, "run"), Ordering::Release);
    }
    GET_FILTERED_OPTIONS.store(function(BALANCER, "getfilteredoptions"), Ordering::Release);
    GET_OPTIONS.store(function(BALANCER, "getoptions"), Ordering::Release);
    SET_TELEMETRY_FUNCTION.store(function(GROUP, "settelemetry"), Ordering::Release);
    Ok(())
}

fn long(value: i64) -> Zval {
    let mut zval = Zval::new();
    zval.set_long(value);
    zval
}

fn double(value: f64) -> Zval {
    let mut zval = Zval::new();
    zval.set_double(value);
    zval
}

/// A persistent immutable list of `values`, as a constant of an internal class holds one (MINIT).
fn persistent_list(values: Vec<Zval>) -> Zval {
    // SAFETY: allocated persistently in MINIT, never freed; flagged immutable so nothing counts references.
    unsafe {
        let ht = ext_php_rs::ffi::__zend_malloc(std::mem::size_of::<HashTable>()).cast::<HashTable>();
        _zend_hash_init(ht, u32::try_from(values.len()).unwrap_or(8), std::ptr::null_mut(), true);
        zend_hash_real_init_packed(ht);
        for mut value in values {
            zend_hash_next_index_insert(ht, &raw mut value);
            std::mem::forget(value);
        }
        frozen_array(ht)
    }
}

/// A persistent immutable array of `entries`, keyed by interned strings (MINIT).
fn persistent_map(entries: &[(Zval, Zval)]) -> Zval {
    // SAFETY: as [`persistent_list`]; the keys are interned strings.
    unsafe {
        let ht = ext_php_rs::ffi::__zend_malloc(std::mem::size_of::<HashTable>()).cast::<HashTable>();
        _zend_hash_init(ht, u32::try_from(entries.len()).unwrap_or(8), std::ptr::null_mut(), true);
        for (key, value) in entries {
            let mut value = value.shallow_clone();
            zend_hash_add(ht, key.value.str_, &raw mut value);
            std::mem::forget(value);
        }
        frozen_array(ht)
    }
}

/// # Safety: `ht` is a filled persistent table.
unsafe fn frozen_array(ht: *mut HashTable) -> Zval {
    // SAFETY: the caller's table, marked immutable from now on.
    unsafe {
        (*ht).gc.refcount = 2;
        (*ht).gc.u.type_info = u32::from(IS_ARRAY) | IMMUTABLE_ARRAY;
        let mut zval = Zval::new();
        zval.value.arr = ht;
        zval.u1.type_info = u32::from(IS_ARRAY);
        zval
    }
}

// What the handlers share ---------------------------------------------------------------

/// Runs a body that calls no PHP code, a panic thrown as PHP's `Error`.
#[inline(always)]
pub(crate) fn guard(body: impl FnOnce()) {
    if let Err(payload) = std::panic::catch_unwind(AssertUnwindSafe(body)) {
        panicked(payload.as_ref());
    }
}

#[cold]
#[inline(never)]
fn panicked(payload: &(dyn std::any::Any + Send)) {
    let message = payload
        .downcast_ref::<&str>()
        .map(|s| (*s).to_owned())
        .or_else(|| payload.downcast_ref::<String>().cloned())
        .unwrap_or_else(|| "non-string panic payload".to_owned());
    if !engine::has_exception() {
        engine::error(&format!("Rust panic: {message}"));
    }
}

/// Runs a body that calls PHP code under `zend_try` (`run_handler` without its thread-local bookkeeping).
pub(crate) fn guard_engine<F: FnOnce()>(body: F) {
    struct Frame<F> {
        body: std::mem::ManuallyDrop<F>,
        panic: Option<Box<dyn std::any::Any + Send>>,
    }
    unsafe extern "C" fn trampoline<F: FnOnce()>(frame: *const c_void) -> *const c_void {
        // SAFETY: the frame below, which the engine passes back once.
        let state = unsafe { &mut *frame.cast_mut().cast::<Frame<F>>() };
        let body = unsafe { std::mem::ManuallyDrop::take(&mut state.body) };
        if let Err(payload) = std::panic::catch_unwind(AssertUnwindSafe(body)) {
            state.panic = Some(payload);
        }
        frame
    }
    let mut frame = Frame { body: std::mem::ManuallyDrop::new(body), panic: None };
    let mut done = std::ptr::null_mut();
    // SAFETY: a bailout is passed on once this frame holds nothing to drop; what the body held leaks.
    unsafe {
        if ext_php_rs::ffi::ext_php_rs_zend_try_catch(trampoline::<F>, (&raw mut frame).cast(), &raw mut done) {
            std::mem::forget(frame);
            ext_php_rs::ffi::ext_php_rs_zend_bailout();
        }
    }
    if let Some(payload) = frame.panic {
        panicked(payload.as_ref());
    }
}

/// Whether at least `required` of `total` arguments were passed, else the `ArgumentCountError`.
#[inline(always)]
fn arity(ex: &ExecuteData, required: usize, total: usize) -> bool {
    let passed = engine::args_count(ex);
    if passed < required {
        too_few(ex, passed, required, total);
        return false;
    }
    true
}

#[cold]
#[inline(never)]
fn too_few(ex: &ExecuteData, passed: usize, required: usize, total: usize) {
    engine::too_few(ex, &function_name(ex), passed, required, total);
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
            .cast::<ZendStr>()
            .as_ref()
            .and_then(|name| name.as_str().ok())
            .unwrap_or_default();
        format!("{class}::{name}")
    }
}

/// Argument `i` (from 0) of the call; present once [`arity`] passed.
#[inline(always)]
fn arg(ex: &mut ExecuteData, i: usize) -> &'static mut Zval {
    engine::arg(ex, i).expect("arity checked")
}

/// A parameter typed with the class at `index`, or the `TypeError` a userland function throws.
#[inline(always)]
fn object_arg<'a>(ex: &ExecuteData, num: u32, value: &'a Zval, index: usize, expected: &str) -> Option<&'a Zval> {
    let value = deref(value);
    if let Some(object) = value.object()
        && instance_of(object, class(index))
    {
        return Some(value);
    }
    engine::type_error(ex, num, expected, value);
    None
}

/// A `string` argument, borrowed or coerced in place as a userland parameter is.
#[inline(always)]
fn key_arg(ex: &ExecuteData, num: u32, value: &mut Zval) -> Option<*mut zend_string> {
    if type_of(value) != IS_STRING {
        let coerced = engine::scalar_arg(ex, num, value, MAY_BE_STRING, "string")?;
        assign(value, coerced);
    }
    // SAFETY: a string argument, alive for the call.
    Some(unsafe { value.value.str_ })
}

/// The index a numeric string key such as `"5"` or `"-1"` stands for, as an array stores it.
#[inline(always)]
fn numeric(key: &ZendStr) -> Option<u64> {
    let bytes = key.as_bytes();
    let first = *bytes.first()?;
    if first > b'9' || (first < b'0' && (first != b'-' || !bytes.get(1).is_some_and(u8::is_ascii_digit))) {
        return None;
    }
    let mut index = 0;
    // SAFETY: the string's bytes; the engine reads `length` of them.
    unsafe { _zend_handle_numeric_str_ex(bytes.as_ptr().cast(), bytes.len(), &raw mut index) }.then_some(index)
}

/// `$array[$key]` for reading, `key` a string as a PHP array key takes it.
#[inline(always)]
fn find(ht: *const HashTable, key: *mut zend_string) -> *mut zval {
    // SAFETY: a live table and a live string.
    unsafe {
        match numeric(&*key.cast::<ZendStr>()) {
            Some(index) => zend_hash_index_find(ht, index),
            None => zend_hash_find(ht, key),
        }
    }
}

/// `$array[$key] = ` ...: the element's slot, added as `null` when missing.
#[inline(always)]
fn lookup(ht: *mut HashTable, key: *mut zend_string) -> &'static mut Zval {
    // SAFETY: a separated table and a live string; the slot lives until the table changes.
    unsafe {
        let element = match numeric(&*key.cast::<ZendStr>()) {
            Some(index) => zend_hash_index_lookup(ht, index),
            None => zend_hash_lookup(ht, key),
        };
        &mut *element.cast::<Zval>()
    }
}

/// The array in `slot`, separated for writing as PHP separates it (`SEPARATE_ARRAY`).
#[inline(always)]
fn separate(slot: &mut Zval) -> *mut HashTable {
    // SAFETY: an array slot; a shared or immutable table is replaced by a copy the slot owns.
    unsafe {
        let mut ht = slot.value.arr;
        let flags = (*ht).gc.u.type_info;
        if flags & GC_IMMUTABLE != 0 {
            ht = zend_array_dup(ht);
        } else if (*ht).gc.refcount > 1 {
            (*ht).gc.refcount -= 1;
            ht = zend_array_dup(ht);
        } else {
            return ht;
        }
        slot.value.arr = ht;
        slot.u1.type_info = engine::IS_ARRAY_EX;
        ht
    }
}

/// `$slot = $value`: the old value released after the write, the slot's property flags kept.
#[inline(always)]
fn assign(slot: &mut Zval, value: Zval) {
    // SAFETY: the old value is moved out before the slot is overwritten, and released after.
    unsafe {
        let old = std::ptr::read(slot);
        slot.value = value.value;
        slot.u1 = value.u1;
        std::mem::forget(value);
        drop(old);
    }
}

/// The slot of the private `property` when it holds a value; `None` sends the caller to the engine.
#[inline(always)]
fn slot<'a>(this: &'a mut ZendObject, property: &Property) -> Option<&'a mut Zval> {
    let slot = engine::private_slot(this, property)?;
    (type_of(slot) != IS_UNDEF).then_some(slot)
}

/// A copy of `$this->property`; `None` with the exception pending.
#[inline(always)]
fn get(this: &mut ZendObject, declared_in: usize, property: &Property, name: &str) -> Option<Zval> {
    if let Some(slot) = slot(this, property) {
        return Some(copy(slot));
    }
    read_slow(this, declared_in, name)
}

#[cold]
#[inline(never)]
fn read_slow(this: &mut ZendObject, declared_in: usize, name: &str) -> Option<Zval> {
    engine::read(class(declared_in), this, name).map(|value| copy(&value))
}

/// `$this->property = $value`, for a value already of the property's type.
#[inline(always)]
fn set(this: &mut ZendObject, declared_in: usize, property: &Property, name: &str, value: Zval) {
    if let Some(slot) = engine::private_slot(this, property)
        && type_of(slot) != IS_REFERENCE
    {
        assign(slot, value);
        return;
    }
    engine::write(class(declared_in), this, name, value);
}

/// `$this->property[] = $value` for the array `property` (private to `declared_in`).
fn append(this: &mut ZendObject, declared_in: usize, property: &Property, name: &str, value: Zval) -> Option<()> {
    if let Some(slot) = engine::private_slot(this, property)
        && type_of(slot) == IS_ARRAY
    {
        let ht = separate(slot);
        // SAFETY: a separated table the slot owns; the insert takes the value's reference.
        unsafe {
            if (*ht).nNextFreeElement != i64::MAX {
                let mut value = value;
                zend_hash_next_index_insert(ht, &raw mut value);
                std::mem::forget(value);
                return Some(());
            }
        }
    }
    append_slow(this, class(declared_in), name, value)
}

/// `$this->name[] = $value` through the engine (read, copy, append, write back).
#[cold]
#[inline(never)]
fn append_slow(this: &mut ZendObject, scope: &ClassEntry, name: &str, value: Zval) -> Option<()> {
    let current = engine::read(scope, this, name)?;
    let mut array = Zval::new();
    // SAFETY: a fresh copy of the array (or a new one), which the zval owns.
    unsafe {
        let ht = match current.array() {
            Some(ht) => zend_array_dup(std::ptr::from_ref(ht).cast_mut()),
            None => _zend_new_array(0),
        };
        let mut value = value;
        if zend_hash_next_index_insert(ht, &raw mut value).is_null() {
            drop(value);
        } else {
            std::mem::forget(value);
        }
        array.set_hashtable(ext_php_rs::boxed::ZBox::from_raw(ht.cast::<ZendHashTable>()));
    }
    engine::write(scope, this, name, array);
    (!engine::has_exception()).then_some(())
}

fn return_this(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(this) = this(ex) {
        retval.set_object(this);
    }
}

/// Throws a new `ce` with `message`.
fn throw_new(ce: &'static ClassEntry, message: &str) {
    let _ = throw(ce, message);
}

/// A PHP list holding a copy of each of `values`, or the immutable `[]`.
fn list(values: &[*mut zval]) -> Zval {
    if values.is_empty() {
        return engine::empty_array();
    }
    let mut zval = Zval::new();
    let count = u32::try_from(values.len()).unwrap_or(u32::MAX);
    // SAFETY: a new packed table sized for the values, filled as `ZEND_HASH_FILL_PACKED` fills one.
    unsafe {
        let ht = _zend_new_array(count);
        zend_hash_real_init_packed(ht);
        let slots = (*ht).__bindgen_anon_1.arPacked.cast::<Zval>();
        for (i, value) in values.iter().enumerate() {
            std::ptr::write(slots.add(i), copy(&*value.cast::<Zval>()));
        }
        (*ht).nNumUsed = count;
        (*ht).nNumOfElements = count;
        (*ht).nNextFreeElement = i64::from(count);
        zval.value.arr = ht;
        zval.u1.type_info = engine::IS_ARRAY_EX;
    }
    zval
}

// Option ---------------------------------------------------------------------------------

extern "C" fn option_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| {
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(this) = this(ex) else { return };
        let state = deref(arg(ex, 0));
        if type_of(state) != IS_ARRAY {
            engine::type_error(ex, 1, "array", state);
            return;
        }
        set(this, OPTION, &STATE, "state", state.shallow_clone());
    });
}

extern "C" fn option_get_state(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        if !arity(ex, 1, 2) {
            return;
        }
        let Some(this) = this(ex) else { return };
        let first = arg(ex, 0);
        let Some(key) = key_arg(ex, 1, first) else { return };
        // `$this->state[$key] ?? $default`: an uninitialized `$state` reads as missing.
        let found = match engine::private_slot(this, &STATE) {
            Some(state) => find_in(deref(state), key),
            None => std::ptr::null_mut(),
        };
        if !found.is_null() {
            // SAFETY: an element of the property's array, alive for the call.
            let value = deref(unsafe { &*found.cast::<Zval>() });
            if type_of(value) != IS_NULL {
                *retval = value.shallow_clone();
                return;
            }
        }
        if let Some(default) = engine::arg(ex, 1) {
            *retval = copy(default);
        }
    });
}

/// `$array[$key]` when `array` (dereferenced) is one.
fn find_in(array: &Zval, key: *mut zend_string) -> *mut zval {
    if type_of(array) == IS_ARRAY { find(table(array), key) } else { std::ptr::null_mut() }
}

extern "C" fn option_set_state(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        if !arity(ex, 2, 2) {
            return;
        }
        let Some(this) = this(ex) else { return };
        let first = arg(ex, 0);
        let Some(key) = key_arg(ex, 1, first) else { return };
        let value = deref(arg(ex, 1));
        if let Some(state) = engine::private_slot(this, &STATE)
            && matches!(type_of(state), IS_ARRAY | IS_UNDEF)
        {
            if type_of(state) == IS_UNDEF {
                // `$this->state[$key] = ` on an uninitialized `$state` starts it as an array.
                initialize_array(state);
            }
            store(lookup(separate(state), key), value);
        } else {
            set_state_slow(this, key, value);
        }
        if !engine::has_exception() {
            return_this(ex, retval);
        }
    });
}

/// Writes a new empty array into the uninitialized property `slot`.
#[cold]
#[inline(never)]
fn initialize_array(slot: &mut Zval) {
    /// `IS_PROP_UNINIT`.
    const PROP_UNINIT: u32 = 1;
    // SAFETY: an undefined slot owns nothing; the new table is the slot's.
    unsafe {
        slot.value.arr = _zend_new_array(0);
        slot.u1.type_info = engine::IS_ARRAY_EX;
        slot.u2.extra &= !PROP_UNINIT;
    }
}

/// `$element = $value`, through a reference the element holds as PHP assigns through one.
#[inline(always)]
fn store(element: &mut Zval, value: &Zval) {
    if type_of(element) == IS_REFERENCE {
        store_ref(element, value);
        return;
    }
    assign(element, value.shallow_clone());
}

#[cold]
#[inline(never)]
fn store_ref(element: &mut Zval, value: &Zval) {
    // SAFETY: a typed reference checks the value as a non-strict assignment does, and copies it in.
    unsafe {
        let reference = element.value.ref_;
        if !(*reference).sources.ptr.is_null() {
            let mut value = value.shallow_clone();
            zend_assign_to_typed_ref(element, &raw mut value, IS_CV, false);
            return;
        }
        assign(&mut *(&raw mut (*reference).val).cast::<Zval>(), value.shallow_clone());
    }
}

/// `$this->state[$key] = $value` through the engine.
#[cold]
#[inline(never)]
fn set_state_slow(this: &mut ZendObject, key: *mut zend_string, value: &Zval) {
    let Some(mut state) = engine::read(class(OPTION), this, "state") else { return };
    store(lookup(separate(&mut state), key), value);
    engine::write(class(OPTION), this, "state", state);
}

extern "C" fn option_delete_state(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(this) = this(ex) else { return };
        let first = arg(ex, 0);
        let Some(key) = key_arg(ex, 1, first) else { return };
        // `unset($this->state[$key])`: nothing to do for a missing key or an uninitialized `$state`.
        if let Some(state) = engine::private_slot(this, &STATE)
            && type_of(state) == IS_ARRAY
        {
            // SAFETY: an array slot's live table.
            let shared =
                unsafe { (*table(state)).gc.refcount > 1 || (*table(state)).gc.u.type_info & GC_IMMUTABLE != 0 };
            if !shared || !find(table(state), key).is_null() {
                remove(separate(state), key);
            }
        }
        if !engine::has_exception() {
            return_this(ex, retval);
        }
    });
}

/// `unset($array[$key])`.
#[inline(always)]
fn remove(ht: *mut HashTable, key: *mut zend_string) {
    // SAFETY: a separated table and a live string.
    unsafe {
        match numeric(&*key.cast::<ZendStr>()) {
            Some(index) => zend_hash_index_del(ht, index),
            None => zend_hash_del(ht, key),
        };
    }
}

extern "C" fn option_get_states(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(this) = this(ex) else { return };
        if let Some(state) = get(this, OPTION, &STATE, "state") {
            *retval = state;
        }
    });
}

// Algorithms -----------------------------------------------------------------------------

extern "C" fn first_get_name(_: &mut ExecuteData, retval: &mut Zval) {
    FIRST_NAME.set(retval);
}

extern "C" fn last_get_name(_: &mut ExecuteData, retval: &mut Zval) {
    LAST_NAME.set(retval);
}

extern "C" fn random_get_name(_: &mut ExecuteData, retval: &mut Zval) {
    RANDOM_NAME.set(retval);
}

extern "C" fn round_robin_get_name(_: &mut ExecuteData, retval: &mut Zval) {
    ROUND_ROBIN_NAME.set(retval);
}

/// The `$options` argument of an algorithm's `run()`; `None` with the `TypeError` thrown.
fn options_arg(ex: &mut ExecuteData) -> Option<&'static Zval> {
    if !arity(ex, 1, 1) {
        return None;
    }
    let options = deref(arg(ex, 0));
    if type_of(options) != IS_ARRAY {
        engine::type_error(ex, 1, "array", options);
        return None;
    }
    Some(options)
}

/// Returns `value` as the `?Option` return type of the algorithm at `index` allows, else its `TypeError`.
#[inline(always)]
fn returns(index: usize, value: Option<&Zval>, retval: &mut Zval) {
    let Some(value) = value.map(deref) else { return };
    match type_of(value) {
        IS_NULL => {}
        IS_OBJECT if value.object().is_some_and(|object| instance_of(object, class(OPTION))) => {
            *retval = value.shallow_clone();
        }
        // SAFETY: the run method registered for the class, and a live value.
        _ => unsafe { zend_verify_return_error(RUN[index].load(Ordering::Acquire), std::ptr::from_ref(value).cast()) },
    }
}

/// Fills `kept` with the values of the array `options` in order, each a slot of the table.
fn fill(kept: &mut Kept, options: &Zval) {
    if type_of(options) != IS_ARRAY {
        return;
    }
    // SAFETY: a live table.
    let ht = unsafe { &*table(options).cast::<ZendHashTable>() };
    kept.reserve(ht.len());
    for value in engine::values(ht) {
        kept.push(std::ptr::from_ref(value).cast_mut().cast());
    }
}

extern "C" fn first_run(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(options) = options_arg(ex) else { return };
        // `$options[array_key_first($options)]`: the first slot in use.
        let ht = table(options);
        // SAFETY: the used slots of a live table.
        let first =
            (0..unsafe { (*ht).nNumUsed } as usize).map(|i| element(ht, i)).find(|value| type_of(value) != IS_UNDEF);
        returns(FIRST, first, retval);
    });
}

extern "C" fn last_run(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(options) = options_arg(ex) else { return };
        // `$options[array_key_last($options)]`: the last slot in use.
        let ht = table(options);
        // SAFETY: the used slots of a live table.
        let last = (0..unsafe { (*ht).nNumUsed } as usize)
            .rev()
            .map(|i| element(ht, i))
            .find(|value| type_of(value) != IS_UNDEF);
        returns(LAST, last, retval);
    });
}

/// The value in slot `i` (below `nNumUsed`) of `ht`, packed or not.
#[inline(always)]
fn element(ht: *mut HashTable, i: usize) -> &'static Zval {
    // SAFETY: a slot within the used ones, alive while the table is.
    unsafe {
        if (*ht).u.flags & HASH_PACKED != 0 {
            &*(*ht).__bindgen_anon_1.arPacked.add(i).cast::<Zval>()
        } else {
            &*(&raw const (*(*ht).__bindgen_anon_1.arData.add(i)).val).cast::<Zval>()
        }
    }
}

extern "C" fn random_run(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(options) = options_arg(ex) else { return };
        let picked = random_pick(options);
        if !engine::has_exception() {
            returns(RANDOM, picked.as_ref(), retval);
        }
    });
}

/// `$options[\array_rand($options)] ?? null` on PHP's default engine.
fn random_pick(options: &Zval) -> Option<Zval> {
    let ht = table(options);
    // SAFETY: a live table.
    let (count, used, packed) = unsafe { ((*ht).nNumOfElements, (*ht).nNumUsed, (*ht).u.flags & HASH_PACKED != 0) };
    if count == 0 {
        throw_new(ce::value_error(), "array_rand(): Argument #1 ($array) must not be empty");
        return None;
    }
    if packed && used == count {
        // SAFETY: a list without holes, where `array_rand()` draws one index from the same engine.
        let index = unsafe { php_mt_rand_range(0, i64::from(count) - 1) };
        let value = unsafe { &*(*ht).__bindgen_anon_1.arPacked.add(index as usize).cast::<Zval>() };
        return Some(copy(value));
    }
    let mut key = Zval::new();
    // SAFETY: PHP's own `array_rand()` over the array, on its default engine.
    let picked = unsafe {
        let engine = RandomEngine { algo: php_random_default_algo(), state: php_random_default_status() };
        php_array_pick_keys(engine, std::ptr::from_ref(options).cast_mut().cast(), 1, &raw mut key, true)
    };
    if !picked || engine::has_exception() {
        return None;
    }
    // SAFETY: a key the table holds.
    let found = unsafe {
        match type_of(&key) {
            IS_LONG => zend_hash_index_find(ht, key.value.lval as u64),
            _ => zend_hash_find(ht, key.value.str_),
        }
    };
    // SAFETY: an element of the table.
    unsafe { found.cast::<Zval>().as_ref() }.map(copy)
}

extern "C" fn round_robin_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| {
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(this) = this(ex) else { return };
        let first = arg(ex, 0);
        let Some(index) = engine::scalar_arg(ex, 1, first, MAY_BE_LONG, "int") else { return };
        set(this, ROUND_ROBIN, &INDEX, "index", index);
    });
}

extern "C" fn round_robin_get_index(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(this) = this(ex) else { return };
        if let Some(index) = get(this, ROUND_ROBIN, &INDEX, "index") {
            *retval = index;
        }
    });
}

extern "C" fn round_robin_set_index(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(this) = this(ex) else { return };
        let first = arg(ex, 0);
        let Some(index) = engine::scalar_arg(ex, 1, first, MAY_BE_LONG, "int") else { return };
        set(this, ROUND_ROBIN, &INDEX, "index", index);
        return_this(ex, retval);
    });
}

/// `$this->index++` then the option there, else the first and index 0; a kept list goes through the crate.
fn round_robin(
    this: &mut ZendObject,
    list: Option<&[&Zval]>,
    at: impl Fn(i64) -> Option<*mut zval>,
) -> Option<Option<*mut zval>> {
    let index = get(this, ROUND_ROBIN, &INDEX, "index")?.long().unwrap_or_default();
    let mut rotation = RoundRobin::new(index);
    if let Some(list) = list {
        let picked = match rotation.pick(list) {
            Ok(picked) => picked,
            Err(error) => {
                throw_new(ce::type_error(), &error.to_string());
                return None;
            }
        };
        set(this, ROUND_ROBIN, &INDEX, "index", long(rotation.index()));
        return Some(picked.and_then(|i| at(i as i64)));
    }
    let Some(next) = index.checked_add(1) else {
        let error = Algorithm::<Zval>::pick(&mut rotation, &[]).expect_err("an index at its maximum cannot move");
        throw_new(ce::type_error(), &error.to_string());
        return None;
    };
    set(this, ROUND_ROBIN, &INDEX, "index", long(next));
    if let Some(option) = at(next) {
        return Some(Some(option));
    }
    let first = at(0);
    set(this, ROUND_ROBIN, &INDEX, "index", long(0));
    Some(first)
}

extern "C" fn round_robin_run(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(options) = options_arg(ex) else { return };
        let Some(this) = this(ex) else { return };
        let ht = table(options);
        // SAFETY: a live table, read for the indexes `run()` looks up.
        let at = |i: i64| unsafe { zend_hash_index_find(ht, i as u64) }.cast::<zval>();
        let at = |i: i64| Some(at(i)).filter(|found| !found.is_null());
        let Some(picked) = round_robin(this, None, at) else { return };
        // SAFETY: an element of the argument's table.
        returns(ROUND_ROBIN, picked.map(|found| unsafe { &*found.cast::<Zval>() }), retval);
    });
}

// Balancer -------------------------------------------------------------------------------

/// The options a run works on: slots of an options array it holds, on the stack for a handful.
struct Kept {
    inline: [*mut zval; 16],
    len: usize,
    heap: Vec<*mut zval>,
}

impl Kept {
    #[inline(always)]
    fn new() -> Self {
        Kept { inline: [std::ptr::null_mut(); 16], len: 0, heap: Vec::new() }
    }

    /// Room for `capacity` options, on the heap past what the stack holds.
    #[inline(always)]
    fn reserve(&mut self, capacity: usize) {
        if capacity > 16 && self.heap.capacity() == 0 {
            self.heap.reserve(capacity);
            self.heap.extend_from_slice(&self.inline[..self.len]);
        }
    }

    #[inline(always)]
    fn push(&mut self, value: *mut zval) {
        if self.heap.capacity() > 0 {
            self.heap.push(value);
        } else if self.len < 16 {
            self.inline[self.len] = value;
            self.len += 1;
        } else {
            self.heap.extend_from_slice(&self.inline);
            self.heap.push(value);
        }
    }

    #[inline(always)]
    fn as_slice(&self) -> &[*mut zval] {
        if self.heap.capacity() > 0 { &self.heap } else { &self.inline[..self.len] }
    }

    /// The options as references, for the crate's algorithms.
    #[inline(always)]
    fn refs(&self) -> &[&Zval] {
        let slice = self.as_slice();
        // SAFETY: live, non-null slots of an array held for the call; pointers and references share a layout.
        unsafe { std::slice::from_raw_parts(slice.as_ptr().cast::<&Zval>(), slice.len()) }
    }

    /// Keeps the options `keep` accepts, in order; stops at the first `None` (an exception).
    fn retain(&mut self, mut keep: impl FnMut(*mut zval) -> Option<bool>) -> Option<()> {
        let mut kept = 0;
        let mut result = Some(());
        let slice: &mut [*mut zval] =
            if self.heap.capacity() > 0 { &mut self.heap } else { &mut self.inline[..self.len] };
        for i in 0..slice.len() {
            match keep(slice[i]) {
                Some(true) => {
                    slice[kept] = slice[i];
                    kept += 1;
                }
                Some(false) => {}
                None => {
                    result = None;
                    break;
                }
            }
        }
        if self.heap.capacity() > 0 {
            self.heap.truncate(kept);
        } else {
            self.len = kept;
        }
        result
    }
}

extern "C" fn balancer_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| {
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(this) = this(ex) else { return };
        let first = arg(ex, 0);
        let Some(algo) = object_arg(ex, 1, first, ALGORITHM, ALGORITHM_NAME) else { return };
        set(this, BALANCER, &ALGO, "algo", algo.shallow_clone());
    });
}

extern "C" fn balancer_get_algo(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(this) = this(ex) else { return };
        if let Some(algo) = get(this, BALANCER, &ALGO, "algo") {
            *retval = algo;
        }
    });
}

extern "C" fn balancer_add_option(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(this) = this(ex) else { return };
        let first = arg(ex, 0);
        let Some(option) = object_arg(ex, 1, first, OPTION, OPTION_NAME) else { return };
        if append(this, BALANCER, &OPTIONS, "options", option.shallow_clone()).is_some() {
            return_this(ex, retval);
        }
    });
}

extern "C" fn balancer_get_options(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(this) = this(ex) else { return };
        if let Some(options) = get(this, BALANCER, &OPTIONS, "options") {
            *retval = options;
        }
    });
}

extern "C" fn balancer_add_filter(ex: &mut ExecuteData, retval: &mut Zval) {
    guard_engine(|| {
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(this) = this(ex) else { return };
        let filter = deref(arg(ex, 0));
        // SAFETY: a userland `callable` parameter is checked from the method's own frame (`Balancer`'s scope).
        let callable = unsafe {
            zend_is_callable_at_frame(
                std::ptr::from_ref(filter).cast_mut().cast(),
                std::ptr::null_mut(),
                std::ptr::from_mut(ex).cast(),
                0,
                std::ptr::null_mut(),
                std::ptr::null_mut(),
            )
        };
        if engine::has_exception() {
            return;
        }
        if !callable {
            engine::type_error(ex, 1, "callable", filter);
            return;
        }
        if append(this, BALANCER, &FILTERS, "filters", filter.shallow_clone()).is_some() {
            return_this(ex, retval);
        }
    });
}

/// The options every filter keeps, filter by filter as `array_filter()` runs, on the run's arrays.
fn filtered(frame: &mut ExecuteData, this: &mut ZendObject, kept: &mut Kept) -> Option<Zval> {
    let options = get(this, BALANCER, &OPTIONS, "options")?;
    let filters = get(this, BALANCER, &FILTERS, "filters")?;
    fill(kept, &options);
    if let Some(filters) = filters.array() {
        for filter in engine::values(filters) {
            filter_with(frame, filter, kept)?;
        }
    }
    Some(options)
}

/// `array_filter($kept, $filter)`: the filter resolved once, then called on each option in order.
fn filter_with(frame: &mut ExecuteData, filter: &Zval, kept: &mut Kept) -> Option<()> {
    let mut fcc = FcallCache {
        function_handler: std::ptr::null_mut(),
        calling_scope: std::ptr::null_mut(),
        called_scope: std::ptr::null_mut(),
        object: std::ptr::null_mut(),
        closure: std::ptr::null_mut(),
    };
    let mut error: *mut c_char = std::ptr::null_mut();
    // SAFETY: a live callable and the executing frame; the error the engine allocates is freed below.
    let callable = unsafe {
        zend_is_callable_at_frame(
            std::ptr::from_ref(deref(filter)).cast_mut().cast(),
            std::ptr::null_mut(),
            std::ptr::from_mut(frame).cast(),
            0,
            &raw mut fcc,
            &raw mut error,
        )
    };
    let message = (!error.is_null()).then(|| {
        // SAFETY: the engine's NUL-terminated message, freed once read.
        let message = unsafe { CStr::from_ptr(error) }.to_string_lossy().into_owned();
        unsafe { ext_php_rs::alloc::efree(error.cast()) };
        message
    });
    if !callable || engine::has_exception() {
        if !engine::has_exception() {
            let reason = message.unwrap_or_default();
            throw_new(
                ce::type_error(),
                &format!("array_filter(): Argument #2 ($callback) must be a valid callback or null, {reason}"),
            );
        }
        return None;
    }
    // SAFETY: as `Z_PARAM_FUNC` does, a `__call()` trampoline is freed and resolved again per call.
    unsafe { zend_release_fcall_info_cache((&raw mut fcc).cast()) };
    let function = deref(filter);
    kept.retain(|option| {
        let mut retval = Zval::new();
        let mut fci = FcallInfo {
            size: std::mem::size_of::<FcallInfo>(),
            // SAFETY: the callable, copied without a reference of its own as the engine reads it.
            function_name: std::mem::ManuallyDrop::new(unsafe {
                std::ptr::read(std::ptr::from_ref(function).cast::<zval>())
            }),
            retval: (&raw mut retval).cast(),
            params: option,
            object: fcc.object,
            param_count: 1,
            named_params: std::ptr::null_mut(),
        };
        // SAFETY: the call `array_filter()` makes, the option passed as is and the result into `retval`.
        unsafe { zend_call_function(&raw mut fci, &raw mut fcc) };
        if engine::has_exception() {
            return None;
        }
        // SAFETY: the call's result.
        Some(unsafe { zend_is_true((&raw const retval).cast()) })
    })
}

extern "C" fn balancer_get_filtered_options(ex: &mut ExecuteData, retval: &mut Zval) {
    let mut done = false;
    guard(|| {
        let Some(this) = this(ex) else { return };
        // Without filters no PHP code runs: `array_values($this->options)`.
        if let Some(filters) = engine::private_slot(this, &FILTERS)
            && type_of(filters) == IS_ARRAY
            && unsafe { (*table(filters)).nNumOfElements } == 0
            && let Some(options) = engine::private_slot(this, &OPTIONS)
            && type_of(options) == IS_ARRAY
        {
            let mut kept = Kept::new();
            fill(&mut kept, options);
            *retval = filtered_list(options, &kept);
            done = true;
        }
    });
    if done || engine::has_exception() {
        return;
    }
    guard_engine(|| {
        let Some(this) = this(ex) else { return };
        let mut kept = Kept::new();
        let Some(options) = filtered(ex, this, &mut kept) else { return };
        *retval = filtered_list(&options, &kept);
    });
}

/// `array_values()` of what the filters kept: the options array itself when all of a list passed.
fn filtered_list(options: &Zval, kept: &Kept) -> Zval {
    let ht = table(options);
    // SAFETY: a live table.
    let whole = unsafe {
        (*ht).u.flags & HASH_PACKED != 0
            && (*ht).nNumUsed == (*ht).nNumOfElements
            && i64::from((*ht).nNumOfElements) == (*ht).nNextFreeElement
            && kept.as_slice().len() == (*ht).nNumOfElements as usize
    };
    if whole && !kept.as_slice().is_empty() { options.shallow_clone() } else { list(kept.as_slice()) }
}

extern "C" fn balancer_run(ex: &mut ExecuteData, retval: &mut Zval) {
    let mut done = false;
    guard(|| {
        if let Some(this) = this(ex) {
            done = run_fast(this, retval);
        }
    });
    if done || engine::has_exception() {
        return;
    }
    guard_engine(|| {
        let Some(this) = this(ex) else { return };
        if let Some(option) = run(ex, this) {
            *retval = option;
        }
    });
}

/// As many `&()` as a list has options, for the crate's algorithms when only the count matters.
static UNITS: [&(); 64] = [&(); 64];

/// `run()` without filters on a native algorithm, which calls no PHP code; `false` takes the full path.
#[inline(always)]
fn run_fast(this: &mut ZendObject, retval: &mut Zval) -> bool {
    if !std::ptr::eq(this.ce, class(BALANCER)) {
        return false;
    }
    let Some(filters) = engine::private_slot(this, &FILTERS) else { return false };
    // SAFETY: an array slot's live table.
    if type_of(filters) != IS_ARRAY || unsafe { (*table(filters)).nNumOfElements } != 0 {
        return false;
    }
    let Some(options) = engine::private_slot(this, &OPTIONS) else { return false };
    if type_of(options) != IS_ARRAY {
        return false;
    }
    let ht = table(options);
    // SAFETY: a live table.
    let (count, used, packed) =
        unsafe { ((*ht).nNumOfElements as usize, (*ht).nNumUsed as usize, (*ht).u.flags & HASH_PACKED != 0) };
    if count == 0 {
        return true;
    }
    if !packed || used != count || count > UNITS.len() {
        return false;
    }
    let units = &UNITS[..count];
    let Some(algo) = engine::private_slot(this, &ALGO) else { return false };
    if type_of(algo) != IS_OBJECT {
        return false;
    }
    // SAFETY: the algorithm object the property holds, and its class.
    let object = unsafe { &mut *algo.value.obj.cast::<ZendObject>() };
    let ce = object.ce;
    let (index, position) = if std::ptr::eq(ce, class(FIRST)) {
        (FIRST, First.pick(units).ok().flatten())
    } else if std::ptr::eq(ce, class(LAST)) {
        (LAST, Last.pick(units).ok().flatten())
    } else if std::ptr::eq(ce, class(RANDOM)) {
        // SAFETY: one index of the list, drawn as `array_rand()` draws it on PHP's default engine.
        (RANDOM, Some(unsafe { php_mt_rand_range(0, count as i64 - 1) } as usize))
    } else if std::ptr::eq(ce, class(ROUND_ROBIN)) {
        let Some(index) = engine::private_slot(object, &INDEX) else { return false };
        if type_of(index) != IS_LONG {
            return false;
        }
        // SAFETY: an integer slot.
        let mut rotation = RoundRobin::new(unsafe { index.value.lval });
        let Ok(position) = rotation.pick(units) else { return false };
        index.value.lval = rotation.index();
        (ROUND_ROBIN, position)
    } else {
        return false;
    };
    // SAFETY: a position within the list.
    let value = position.map(|i| unsafe { &*(*ht).__bindgen_anon_1.arPacked.add(i).cast::<Zval>() });
    returns(index, value, retval);
    true
}

/// `Balancer::run()` on `this`; `None` for `null` or with an exception pending.
fn run(ex: &mut ExecuteData, this: &mut ZendObject) -> Option<Zval> {
    // SAFETY: an object's class lives as long as the object.
    let ce = unsafe { &*this.ce };
    if !std::ptr::eq(ce, class(BALANCER)) && !inherits(ce, "getfilteredoptions", &GET_FILTERED_OPTIONS) {
        return run_overridden(this);
    }
    let mut kept = Kept::new();
    let _options = filtered(ex, this, &mut kept)?;
    if kept.as_slice().is_empty() {
        return None;
    }
    let algo = get(this, BALANCER, &ALGO, "algo")?;
    let object = algo.object()?;
    // SAFETY: as above.
    let algo_ce = unsafe { &*object.ce };
    let refs = kept.refs();
    let picked = if runs(algo_ce, FIRST) {
        (FIRST, First.pick(refs).ok().flatten())
    } else if runs(algo_ce, LAST) {
        (LAST, Last.pick(refs).ok().flatten())
    } else if runs(algo_ce, RANDOM) {
        // SAFETY: one index of the list `array_rand()` would draw from, on PHP's default engine.
        (RANDOM, Some(unsafe { php_mt_rand_range(0, refs.len() as i64 - 1) } as usize))
    } else if runs(algo_ce, ROUND_ROBIN) {
        // SAFETY: the algorithm object, alive while `algo` holds it.
        let rotation = unsafe { &mut *algo.value.obj.cast::<ZendObject>() };
        let picked = round_robin(rotation, Some(refs), |i| Some(kept.as_slice()[i as usize]))?;
        let mut result = Zval::new();
        // SAFETY: a slot of the held options array.
        let value = picked.map(|slot| unsafe { &*slot.cast::<Zval>() });
        returns(ROUND_ROBIN, value, &mut result);
        return (type_of(&result) != IS_NULL && !engine::has_exception()).then_some(result);
    } else {
        let list = list(kept.as_slice());
        return engine::call_method(&algo, &RUN_METHOD, &[&list]).filter(|value| type_of(value) != IS_NULL);
    };
    let mut result = Zval::new();
    returns(picked.0, picked.1.map(|i| refs[i]), &mut result);
    (type_of(&result) != IS_NULL && !engine::has_exception()).then_some(result)
}

/// `run()` on a subclass that overrides `getFilteredOptions()`: its list, then the algorithm.
#[cold]
#[inline(never)]
fn run_overridden(this: &mut ZendObject) -> Option<Zval> {
    let mut object = Zval::new();
    object.set_object(this);
    let options = engine::call_method(&object, &GET_FILTERED_OPTIONS_METHOD, &[])?;
    // SAFETY: a live table, which the method's `array` return type guarantees.
    if options.array().is_none_or(|ht| ht.is_empty()) {
        return None;
    }
    let algo = get(this, BALANCER, &ALGO, "algo")?;
    engine::call_method(&algo, &RUN_METHOD, &[&options]).filter(|value| type_of(value) != IS_NULL)
}

// Group ----------------------------------------------------------------------------------

/// `$this->balancers` as `Group` reads it (a subclass may redeclare the protected property).
fn balancers(this: &mut ZendObject) -> Option<Zval> {
    // SAFETY: an object's class lives as long as the object.
    if std::ptr::eq(this.ce, class(GROUP))
        && let Some(slot) = slot(this, &BALANCERS)
    {
        return Some(copy(slot));
    }
    read_slow(this, GROUP, "balancers")
}

extern "C" fn group_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard_engine(|| {
        let Some(this) = this(ex) else { return };
        let name = match engine::arg(ex, 0) {
            Some(name) if type_of(name) == IS_STRING => name.shallow_clone(),
            Some(name) if type_of(deref(name)) != IS_NULL => {
                let Some(name) = engine::scalar_arg(ex, 1, name, MAY_BE_STRING | MAY_BE_NULL, "?string") else {
                    return;
                };
                name
            }
            _ => Zval::null(),
        };
        set(this, GROUP, &NAME, "name", name);
        // `$this->setTelemetry(new NoTelemetry())`.
        let Some(telemetry) = no_telemetry() else { return };
        // SAFETY: an object's class lives as long as the object.
        let ce = unsafe { &*this.ce };
        if std::ptr::eq(ce, class(GROUP)) || inherits(ce, "settelemetry", &SET_TELEMETRY_FUNCTION) {
            set_telemetry(this, &telemetry);
        } else {
            let mut group = Zval::new();
            group.set_object(this);
            let _ = engine::call_method(&group, &SET_TELEMETRY, &[&telemetry]);
        }
    });
}

/// The classes `Group` looks up once per request.
const NO_TELEMETRY_CLASS: usize = 0;
const TELEMETRY_CLASS: usize = 1;
const HISTOGRAM_CLASS: usize = 2;

/// PHP runs one request at a time per process (NTS), so plain statics hold them.
static USER_CLASSES: [AtomicPtr<ClassEntry>; 3] = [const { AtomicPtr::new(std::ptr::null_mut()) }; 3];

/// `createHistogram()` as last resolved on a telemetry adapter's class.
static CREATE_HISTOGRAM_CACHE: engine::MethodCache = engine::MethodCache::new();

/// Forgets the classes and methods looked up during the request, which end with it.
pub fn request_shutdown() {
    USER_CLASSES.iter().for_each(|class| class.store(std::ptr::null_mut(), Ordering::Relaxed));
    CREATE_HISTOGRAM_CACHE.reset();
}

/// The class at `index` of [`USER_CLASSES`], looked up (and autoloaded when `autoload`) once per request.
#[inline]
fn user_class(index: usize, name: &Zval, key: &Zval, autoload: bool) -> Option<&'static ClassEntry> {
    let cached = USER_CLASSES[index].load(Ordering::Relaxed);
    // SAFETY: a user class entry lives until the request ends, when the pointer is forgotten.
    if let Some(class) = unsafe { cached.as_ref() } {
        return Some(class);
    }
    let flags = if autoload { 0 } else { NO_AUTOLOAD };
    // SAFETY: interned names.
    let found = unsafe { zend_lookup_class_ex(name.value.str_, key.value.str_, flags).cast::<ClassEntry>().as_ref() }?;
    USER_CLASSES[index].store(std::ptr::from_ref(found).cast_mut(), Ordering::Relaxed);
    Some(found)
}

/// `new NoTelemetry()`, its class autoloaded as `new` loads it; `None` with the exception pending.
fn no_telemetry() -> Option<Zval> {
    let frozen = frozen();
    let mut telemetry = Zval::new();
    // SAFETY: interned names; the object is created as `new` creates it.
    unsafe {
        let ce = user_class(NO_TELEMETRY_CLASS, &frozen.no_telemetry, &frozen.no_telemetry_key, true)
            .map_or(std::ptr::null_mut(), |ce| std::ptr::from_ref(ce).cast_mut().cast::<zend_class_entry>());
        if ce.is_null() {
            if !engine::has_exception() {
                engine::error(&format!("Class \"{NO_TELEMETRY_NAME}\" not found"));
            }
            return None;
        }
        if object_init_ex((&raw mut telemetry).cast(), ce) != 0 || engine::has_exception() {
            return None;
        }
        if !(*ce).constructor.is_null()
            && let Some(object) = telemetry.object_mut()
            && engine::call_own_method(object, "__construct", &[]).is_none()
        {
            return None;
        }
    }
    Some(telemetry)
}

/// `$this->runHistogram = $telemetry->createHistogram(...)`.
fn set_telemetry(this: &mut ZendObject, telemetry: &Zval) {
    let frozen = frozen();
    let Some(histogram) =
        engine::call_method_cached(telemetry, &CREATE_HISTOGRAM, &CREATE_HISTOGRAM_CACHE, &frozen.histogram)
    else {
        return;
    };
    // A property's class type does not autoload: an object of the class has it loaded.
    let typed = user_class(HISTOGRAM_CLASS, &frozen.histogram_class, &frozen.histogram_class_key, false).is_some_and(
        |expected| {
            histogram.object().is_some_and(|object| {
                // SAFETY: an object's class lives as long as the object.
                unsafe { (*object.ce).parent() }.is_some_and(|parent| std::ptr::eq(parent, expected))
                    || instance_of(object, expected)
            })
        },
    );
    if typed
        && let Some(slot) = engine::private_slot(this, &RUN_HISTOGRAM)
        && type_of(slot) != IS_REFERENCE
    {
        /// `IS_PROP_UNINIT`.
        const PROP_UNINIT: u32 = 1;
        assign(slot, histogram);
        // SAFETY: the property's slot, now initialized.
        unsafe { slot.u2.extra &= !PROP_UNINIT };
        return;
    }
    engine::write(class(GROUP), this, "runHistogram", histogram);
}

extern "C" fn group_set_telemetry(ex: &mut ExecuteData, retval: &mut Zval) {
    guard_engine(|| {
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(this) = this(ex) else { return };
        let telemetry = deref(arg(ex, 0));
        let frozen = frozen();
        // A parameter's class type does not autoload: an object of the class has it loaded.
        let accepted = user_class(TELEMETRY_CLASS, &frozen.telemetry, &frozen.telemetry_key, false)
            .is_some_and(|adapter| telemetry.object().is_some_and(|object| instance_of(object, adapter)));
        if !accepted {
            engine::type_error(ex, 1, TELEMETRY_NAME, telemetry);
            return;
        }
        set_telemetry(this, telemetry);
        retval.set_null();
    });
}

extern "C" fn group_add(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(this) = this(ex) else { return };
        let first = arg(ex, 0);
        let Some(balancer) = object_arg(ex, 1, first, BALANCER, BALANCER_NAME) else { return };
        let balancer = balancer.shallow_clone();
        let done = if std::ptr::eq(this.ce, class(GROUP)) {
            append(this, GROUP, &BALANCERS, "balancers", balancer)
        } else {
            append_slow(this, class(GROUP), "balancers", balancer)
        };
        if done.is_some() {
            return_this(ex, retval);
        }
    });
}

extern "C" fn group_get_options(ex: &mut ExecuteData, retval: &mut Zval) {
    guard_engine(|| {
        let Some(this) = this(ex) else { return };
        let Some(balancers) = balancers(this) else { return };
        let mut options = Kept::new();
        let mut held = Vec::new();
        if let Some(ht) = balancers.array() {
            for balancer in engine::values(ht) {
                let balancer = deref(balancer);
                // SAFETY: the balancer, alive while the held array holds it, and its class.
                let native = type_of(balancer) == IS_OBJECT && {
                    let ce = unsafe { &*(*balancer.value.obj).ce };
                    std::ptr::eq(ce, class(BALANCER)) || inherits(ce, "getoptions", &GET_OPTIONS)
                };
                let own = if native {
                    // SAFETY: as above.
                    let object = unsafe { &mut *balancer.value.obj.cast::<ZendObject>() };
                    get(object, BALANCER, &OPTIONS, "options")
                } else {
                    engine::call_method(balancer, &GET_OPTIONS_METHOD, &[])
                };
                let Some(own) = own else { return };
                if let Some(ht) = own.array() {
                    for option in engine::values(ht) {
                        options.push(std::ptr::from_ref(option).cast_mut().cast());
                    }
                }
                held.push(own);
            }
        }
        *retval = list(options.as_slice());
    });
}

extern "C" fn group_run(ex: &mut ExecuteData, retval: &mut Zval) {
    guard_engine(|| {
        let Some(this) = this(ex) else { return };
        let Some(balancers) = balancers(this) else { return };
        let start = now();
        let mut option = Zval::null();
        if let Some(ht) = balancers.array() {
            for balancer in engine::values(ht) {
                let Some(picked) = engine::call_method(balancer, &RUN_METHOD, &[]) else { return };
                option = picked;
                if type_of(&option) != IS_NULL {
                    break;
                }
            }
        }
        let end = now();
        let Some(histogram) = get(this, GROUP, &RUN_HISTOGRAM, "runHistogram") else { return };
        let Some(name) = get(this, GROUP, &NAME, "name") else { return };
        let attributes = if type_of(&name) == IS_NULL { frozen().unnamed.shallow_clone() } else { named(name) };
        if engine::call_method(&histogram, &RECORD, &[&double(end - start), &attributes]).is_some() {
            *retval = option;
        }
    });
}

/// `['name' => $name]`.
fn named(name: Zval) -> Zval {
    let mut zval = Zval::new();
    // SAFETY: a new table owning the value; the key is interned.
    unsafe {
        let ht = _zend_new_array(1);
        let mut name = name;
        zend_hash_add(ht, frozen().name_key.value.str_, &raw mut name);
        std::mem::forget(name);
        zval.value.arr = ht;
        zval.u1.type_info = engine::IS_ARRAY_EX;
    }
    zval
}

/// `microtime(true)`.
fn now() -> f64 {
    std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map_or(0.0, |since| since.as_secs_f64())
}
