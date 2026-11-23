//! The `Feature\*` interfaces the builders implement, and what makes a builder class behave as PHP's
//! does where `#[php_class]` cannot say so.
//!
//! An interface takes its methods' signatures from the native class that implements it, so the two
//! cannot disagree. It is registered only once every one of its methods is native, which is how the
//! list grows while the builders are being moved.
//!
//! Four things wait for [`finish`], which PHP runs after every extension has registered its classes
//! and before it preloads or compiles any script:
//!
//! - a `Query` can be serialized and unserialized (see [`super::value::prepare`]);
//! - a fluent method returns `static`, where ext-php-rs declares the class it is written on;
//! - the builders implement the interfaces, which needs those return types to agree;
//! - PHP creates a builder with its state already in it and lets it change a protected property in place (see [`prepare`]).

use std::ffi::CStr;
use std::sync::atomic::{AtomicUsize, Ordering};

use ext_php_rs::builders::{ClassBuilder, FunctionBuilder};
use ext_php_rs::ffi::{compiler_globals, zend_do_implement_interface, zend_hash_str_find_ptr_lc};
use ext_php_rs::flags::{ClassFlags, MethodFlags};
use ext_php_rs::types::ZendStr;
use ext_php_rs::zend::ClassEntry;

use super::builder::{
    ClickHouseBuilder, MariaDbBuilder, MongoDbBuilder, MySqlBuilder, Native, PostgreSqlBuilder, SqliteBuilder, prepare,
};
use crate::classes::engine;

/// Each interface, the dialects that implement it (bit 0 is MySQL, then MariaDB, PostgreSQL, SQLite,
/// ClickHouse and MongoDB) and its methods. Generated from the PHP classes.
const FEATURES: &[(&str, u8, &[&str])] = &[
    (
        "Utopia\\Query\\Builder\\Feature\\Aggregates",
        0b111111,
        &["count", "countDistinct", "sum", "avg", "min", "max", "groupBy", "groupByTimeBucket", "having"],
    ),
    ("Utopia\\Query\\Builder\\Feature\\BitwiseAggregates", 0b011111, &["bitAnd", "bitOr", "bitXor"]),
    ("Utopia\\Query\\Builder\\Feature\\CTEs", 0b111111, &["with", "withRecursive", "withRecursiveSeedStep"]),
    (
        "Utopia\\Query\\Builder\\Feature\\ClickHouse\\ApproximateAggregates",
        0b010000,
        &[
            "quantile",
            "quantiles",
            "quantileExact",
            "median",
            "uniq",
            "uniqExact",
            "uniqCombined",
            "argMin",
            "argMax",
            "topK",
            "topKWeighted",
            "anyValue",
            "anyLastValue",
            "groupUniqArray",
            "groupArrayMovingAvg",
            "groupArrayMovingSum",
        ],
    ),
    ("Utopia\\Query\\Builder\\Feature\\ClickHouse\\ArrayJoins", 0b010000, &["arrayJoin", "leftArrayJoin"]),
    ("Utopia\\Query\\Builder\\Feature\\ClickHouse\\AsofJoins", 0b010000, &["asofJoin", "asofLeftJoin"]),
    ("Utopia\\Query\\Builder\\Feature\\ClickHouse\\LimitBy", 0b010000, &["limitBy"]),
    ("Utopia\\Query\\Builder\\Feature\\ClickHouse\\WithFill", 0b010000, &["orderWithFill"]),
    (
        "Utopia\\Query\\Builder\\Feature\\ConditionalAggregates",
        0b011111,
        &["countWhen", "sumWhen", "avgWhen", "minWhen", "maxWhen"],
    ),
    ("Utopia\\Query\\Builder\\Feature\\CrossJoins", 0b011111, &["crossJoin", "naturalJoin"]),
    ("Utopia\\Query\\Builder\\Feature\\Cube", 0b010100, &["withCube"]),
    ("Utopia\\Query\\Builder\\Feature\\Deletes", 0b111111, &["from", "delete"]),
    ("Utopia\\Query\\Builder\\Feature\\FullOuterJoins", 0b010100, &["fullOuterJoin"]),
    ("Utopia\\Query\\Builder\\Feature\\FullTextSearch", 0b100111, &["filterSearch"]),
    ("Utopia\\Query\\Builder\\Feature\\Hints", 0b010011, &["hint"]),
    ("Utopia\\Query\\Builder\\Feature\\Hooks", 0b111111, &["addHook"]),
    ("Utopia\\Query\\Builder\\Feature\\InsertOrIgnore", 0b101111, &["insertOrIgnore"]),
    (
        "Utopia\\Query\\Builder\\Feature\\Inserts",
        0b111111,
        &["into", "set", "onConflict", "insert", "insertDefaultValues", "fromSelect", "insertSelect"],
    ),
    ("Utopia\\Query\\Builder\\Feature\\Joins", 0b111111, &["join", "leftJoin", "rightJoin", "joinWhere"]),
    (
        "Utopia\\Query\\Builder\\Feature\\Json",
        0b001111,
        &[
            "filterJsonContains",
            "filterJsonNotContains",
            "filterJsonOverlaps",
            "filterJsonPath",
            "setJsonAppend",
            "setJsonPrepend",
            "setJsonInsert",
            "setJsonRemove",
            "setJsonIntersect",
            "setJsonDiff",
            "setJsonUnique",
            "setJsonPath",
        ],
    ),
    ("Utopia\\Query\\Builder\\Feature\\LateralJoins", 0b000111, &["joinLateral", "leftJoinLateral"]),
    (
        "Utopia\\Query\\Builder\\Feature\\Locking",
        0b001111,
        &["forUpdate", "forShare", "forUpdateSkipLocked", "forUpdateNoWait", "forShareSkipLocked", "forShareNoWait"],
    ),
    ("Utopia\\Query\\Builder\\Feature\\MariaDB\\Returning", 0b000010, &["returning"]),
    ("Utopia\\Query\\Builder\\Feature\\MongoDB\\ArrayPushModifiers", 0b100000, &["pushEach"]),
    ("Utopia\\Query\\Builder\\Feature\\MongoDB\\AtlasSearch", 0b100000, &["search", "searchMeta", "vectorSearch"]),
    ("Utopia\\Query\\Builder\\Feature\\MongoDB\\ConditionalArrayUpdates", 0b100000, &["arrayFilter"]),
    (
        "Utopia\\Query\\Builder\\Feature\\MongoDB\\FieldUpdates",
        0b100000,
        &["rename", "multiply", "popFirst", "popLast", "pullAll", "updateMin", "updateMax", "currentDate"],
    ),
    (
        "Utopia\\Query\\Builder\\Feature\\MongoDB\\PipelineStages",
        0b100000,
        &["bucket", "bucketAuto", "facet", "graphLookup", "mergeIntoCollection", "outputToCollection", "replaceRoot"],
    ),
    ("Utopia\\Query\\Builder\\Feature\\NegatedFullTextSearch", 0b000111, &["filterNotSearch"]),
    ("Utopia\\Query\\Builder\\Feature\\PostgreSQL\\AggregateFilter", 0b000100, &["selectAggregateFilter"]),
    ("Utopia\\Query\\Builder\\Feature\\PostgreSQL\\DistinctOn", 0b000100, &["distinctOn"]),
    ("Utopia\\Query\\Builder\\Feature\\PostgreSQL\\LockingOf", 0b000100, &["forUpdateOf", "forShareOf"]),
    (
        "Utopia\\Query\\Builder\\Feature\\PostgreSQL\\Merge",
        0b000100,
        &["mergeInto", "using", "on", "whenMatched", "whenNotMatched", "executeMerge"],
    ),
    (
        "Utopia\\Query\\Builder\\Feature\\PostgreSQL\\OrderedSetAggregates",
        0b000100,
        &["arrayAgg", "boolAnd", "boolOr", "every", "mode", "percentileCont", "percentileDisc"],
    ),
    ("Utopia\\Query\\Builder\\Feature\\PostgreSQL\\Returning", 0b000100, &["returning"]),
    ("Utopia\\Query\\Builder\\Feature\\PostgreSQL\\VectorSearch", 0b000100, &["orderByVectorDistance"]),
    (
        "Utopia\\Query\\Builder\\Feature\\RawSql",
        0b011111,
        &[
            "selectRaw",
            "selectCast",
            "orderByRaw",
            "groupByRaw",
            "havingRaw",
            "whereRaw",
            "whereColumn",
            "selectCase",
            "setCase",
            "setRaw",
            "conflictSetRaw",
            "insertColumnExpression",
        ],
    ),
    ("Utopia\\Query\\Builder\\Feature\\Rollup", 0b010111, &["withRollup"]),
    (
        "Utopia\\Query\\Builder\\Feature\\Selects",
        0b111111,
        &[
            "from",
            "select",
            "distinct",
            "filter",
            "queries",
            "sortAsc",
            "sortDesc",
            "sortRandom",
            "limit",
            "offset",
            "fetch",
            "page",
            "cursorAfter",
            "cursorBefore",
            "when",
            "build",
            "toRawSql",
            "getBindings",
            "reset",
        ],
    ),
    ("Utopia\\Query\\Builder\\Feature\\Sequences", 0b000110, &["nextVal", "currVal"]),
    (
        "Utopia\\Query\\Builder\\Feature\\Spatial",
        0b000111,
        &[
            "filterDistance",
            "filterIntersects",
            "filterNotIntersects",
            "filterCrosses",
            "filterNotCrosses",
            "filterOverlaps",
            "filterNotOverlaps",
            "filterTouches",
            "filterNotTouches",
            "filterCovers",
            "filterNotCovers",
            "filterSpatialEquals",
            "filterNotSpatialEquals",
        ],
    ),
    (
        "Utopia\\Query\\Builder\\Feature\\StatisticalAggregates",
        0b011111,
        &["stddev", "stddevPop", "stddevSamp", "variance", "varPop", "varSamp"],
    ),
    ("Utopia\\Query\\Builder\\Feature\\StringAggregates", 0b011111, &["groupConcat", "jsonArrayAgg", "jsonObjectAgg"]),
    ("Utopia\\Query\\Builder\\Feature\\TableSampling", 0b110100, &["tablesample"]),
    ("Utopia\\Query\\Builder\\Feature\\Totals", 0b010000, &["withTotals"]),
    (
        "Utopia\\Query\\Builder\\Feature\\Transactions",
        0b001111,
        &["begin", "commit", "rollback", "savepoint", "releaseSavepoint", "rollbackToSavepoint"],
    ),
    (
        "Utopia\\Query\\Builder\\Feature\\Unions",
        0b111111,
        &["union", "unionAll", "intersect", "intersectAll", "except", "exceptAll"],
    ),
    ("Utopia\\Query\\Builder\\Feature\\Updates", 0b111111, &["from", "set", "update"]),
    ("Utopia\\Query\\Builder\\Feature\\Upsert", 0b101111, &["upsert"]),
    ("Utopia\\Query\\Builder\\Feature\\UpsertSelect", 0b001111, &["upsertSelect"]),
    ("Utopia\\Query\\Builder\\Feature\\Windows", 0b111111, &["selectWindow", "window"]),
];

/// A class type is a name (`_ZEND_TYPE_NAME_BIT`).
const NAMED: u32 = 1 << 24;

type Methods = fn() -> Vec<(FunctionBuilder<'static>, MethodFlags)>;

/// A builder class.
struct Dialect {
    name: &'static str,
    methods: Methods,
    class: fn() -> &'static ClassEntry,
    /// See [`prepare`].
    prepare: fn(),
}

impl Dialect {
    const fn of<C: Native>() -> Self {
        Self {
            name: C::CLASS_NAME,
            methods: C::method_builders,
            class: || C::get_metadata().ce(),
            prepare: prepare::<C>,
        }
    }
}

/// The builder classes, in the order of the bits in [`FEATURES`].
const DIALECTS: [Dialect; 6] = [
    Dialect::of::<MySqlBuilder>(),
    Dialect::of::<MariaDbBuilder>(),
    Dialect::of::<PostgreSqlBuilder>(),
    Dialect::of::<SqliteBuilder>(),
    Dialect::of::<ClickHouseBuilder>(),
    Dialect::of::<MongoDbBuilder>(),
];

/// The names of `methods`, in order. A builder does not give its name away, so each is built for it.
fn names(methods: Methods) -> Vec<String> {
    let name = |(method, _): (FunctionBuilder<'static>, MethodFlags)| {
        let (entry, _) = method.build().ok()?;
        // SAFETY: a built function entry names itself with a C string.
        Some(unsafe { CStr::from_ptr(entry.fname) }.to_string_lossy().into_owned())
    };
    methods().into_iter().map(|method| name(method).unwrap_or_default()).collect()
}

/// Whether the builders are on.
static BUILDERS: std::sync::atomic::AtomicBool = std::sync::atomic::AtomicBool::new(false);

/// Registers the interfaces whose methods are all native, when the builders are on, and has [`finish`]
/// run. Runs in MINIT, before the classes.
pub fn register(builders: bool) -> ext_php_rs::error::Result<()> {
    BUILDERS.store(builders, Ordering::Release);
    // SAFETY: MINIT runs on one thread, and the hook is PHP's to be chained like this.
    unsafe {
        PREVIOUS.store(zend_post_startup_cb.map_or(0, |previous| previous as usize), Ordering::Release);
        zend_post_startup_cb = Some(finish);
    }
    if !builders {
        return Ok(());
    }
    let native: Vec<Vec<String>> = DIALECTS.iter().map(|dialect| names(dialect.methods)).collect();
    for (name, dialects, methods) in FEATURES {
        // The first dialect that implements it lends its signatures.
        let from = dialects.trailing_zeros() as usize;
        if !methods.iter().all(|method| native[from].iter().any(|have| have == method)) {
            continue;
        }
        let mut interface = ClassBuilder::new(*name).flags(ClassFlags::Interface);
        for (index, (method, _)) in (DIALECTS[from].methods)().into_iter().enumerate() {
            if methods.contains(&native[from][index].as_str()) {
                interface = interface.method(method, MethodFlags::Public | MethodFlags::Abstract);
            }
        }
        interface
            .registration(|_, tables| {
                Box::leak(tables);
            })
            .register()?;
    }
    Ok(())
}

unsafe extern "C" {
    /// PHP's hook for after every module has started.
    static mut zend_post_startup_cb: Option<unsafe extern "C" fn() -> i32>;
}

/// The hook that was installed before ours.
static PREVIOUS: AtomicUsize = AtomicUsize::new(0);

/// Whether `name` names one of the builder classes.
fn builder(name: &[u8]) -> bool {
    DIALECTS.iter().any(|dialect| dialect.name.as_bytes().eq_ignore_ascii_case(name))
}

/// Has the methods of `class` that return a builder return `static`, as PHP's do.
fn fluent(class: &ClassEntry, methods: &[String]) {
    for method in methods {
        let Some(info) = engine::arg_info(class, method) else { continue };
        let returns = &mut info[0].type_;
        if returns.type_mask & NAMED == 0 || returns.ptr.is_null() {
            continue;
        }
        // SAFETY: PHP keeps the class name of a registered method's type as a string.
        if builder(unsafe { &*returns.ptr.cast::<ZendStr>() }.as_bytes()) {
            returns.ptr = std::ptr::null_mut();
            returns.type_mask = engine::MAY_BE_STATIC;
        }
    }
}

/// A type ext-php-rs cannot declare: a union, a class a method returns, or `static`.
enum Declared {
    /// Built-in types, as their `MAY_BE_*` bits.
    Types(u32),
    /// A class, and the built-in types it is in a union with.
    Class(&'static str, u32),
}

const STATEMENT: Declared = Declared::Class("Utopia\\Query\\Builder\\Statement", 0);
const INT_OR_FLOAT: Declared = Declared::Types(engine::MAY_BE_LONG | engine::MAY_BE_DOUBLE);
const ARRAY_OR_STRING: Declared = Declared::Types(engine::MAY_BE_ARRAY | engine::MAY_BE_STRING);
const OBJECT_OR_ARRAY: Declared = Declared::Types(MAY_BE_OBJECT | engine::MAY_BE_ARRAY);

/// `iterable`, which is `Traversable|array` with this bit (`_ZEND_TYPE_ITERABLE_BIT`).
const ITERABLE: u32 = 1 << 21;

/// `MAY_BE_OBJECT`.
const MAY_BE_OBJECT: u32 = 1 << 8;

/// The types PHP declares where ext-php-rs declares `mixed`: a method, the parameter (0 is what it
/// returns) and the type. A subclass that overrides one with PHP's type would not load otherwise.
const TYPES: &[(&str, usize, Declared)] = &[
    ("build", 0, STATEMENT),
    ("insert", 0, STATEMENT),
    ("insertDefaultValues", 0, STATEMENT),
    ("insertOrIgnore", 0, STATEMENT),
    ("insertSelect", 0, STATEMENT),
    ("update", 0, STATEMENT),
    ("delete", 0, STATEMENT),
    ("upsert", 0, STATEMENT),
    ("upsertSelect", 0, STATEMENT),
    ("explain", 0, STATEMENT),
    ("executeMerge", 0, STATEMENT),
    ("begin", 0, STATEMENT),
    ("commit", 0, STATEMENT),
    ("rollback", 0, STATEMENT),
    ("savepoint", 0, STATEMENT),
    ("releaseSavepoint", 0, STATEMENT),
    ("rollbackToSavepoint", 0, STATEMENT),
    ("bulkInsert", 0, Declared::Class("Utopia\\Query\\Builder\\ClickHouse\\FormattedInsertStatement", 0)),
    ("bulkInsert", 2, Declared::Class("Traversable", engine::MAY_BE_ARRAY | ITERABLE)),
    ("toAst", 0, Declared::Class("Utopia\\Query\\AST\\Statement\\Select", 0)),
    ("fromAst", 0, Declared::Types(engine::MAY_BE_STATIC)),
    ("clone", 0, Declared::Types(engine::MAY_BE_STATIC)),
    ("getBindings", 0, Declared::Types(engine::MAY_BE_ARRAY)),
    ("getBindingValues", 0, Declared::Types(engine::MAY_BE_ARRAY)),
    ("execute", 0, Declared::Types(engine::MAY_BE_ARRAY | engine::MAY_BE_LONG)),
    ("select", 1, ARRAY_OR_STRING),
    ("hint", 1, ARRAY_OR_STRING),
    ("cursorAfter", 1, OBJECT_OR_ARRAY),
    ("cursorBefore", 1, OBJECT_OR_ARRAY),
    ("increment", 2, INT_OR_FLOAT),
    ("multiply", 2, INT_OR_FLOAT),
    ("unsetFields", 1, Declared::Types(engine::MAY_BE_STRING)),
    ("createAstSerializer", 0, Declared::Class("Utopia\\Query\\AST\\Serializer", 0)),
    ("createTokenizer", 0, Declared::Class("Utopia\\Query\\Tokenizer\\Tokenizer", 0)),
    ("compileInsertBody", 0, Declared::Types(engine::MAY_BE_ARRAY)),
    ("compileAssignments", 0, Declared::Types(engine::MAY_BE_ARRAY)),
    ("validateAndGetColumns", 0, Declared::Types(engine::MAY_BE_ARRAY)),
    ("compileWhereClauses", 1, Declared::Types(engine::MAY_BE_ARRAY)),
    ("compileOrderAndLimit", 1, Declared::Types(engine::MAY_BE_ARRAY)),
    ("compileVectorOrderExpression", 0, Declared::Class("Utopia\\Query\\Builder\\Condition", engine::MAY_BE_NULL)),
    ("resolveJoinFilterPlacement", 0, Declared::Class("Utopia\\Query\\Hook\\Join\\Placement", 0)),
    ("appendReturning", 0, STATEMENT),
    ("applyNamedTypedBindings", 0, STATEMENT),
    ("shouldEmitOffset", 1, Declared::Types(engine::MAY_BE_LONG | engine::MAY_BE_NULL)),
    ("shouldEmitOffset", 2, Declared::Types(engine::MAY_BE_LONG | engine::MAY_BE_NULL)),
];

/// Declares [`TYPES`] on the methods of `class` that are among them.
fn typed(class: &ClassEntry) {
    // The bits of a type that say what it is, below the flags of the parameter it is on.
    const TYPE: u32 = (1 << 25) - 1;
    for (method, parameter, declared) in TYPES {
        let Some(info) = engine::arg_info(class, method) else { continue };
        // A variadic parameter is one past the parameters the function counts.
        let info = if *parameter == info.len() && *method == "unsetFields" {
            // SAFETY: a variadic function's argument info has that entry.
            unsafe { &mut *info.as_mut_ptr().add(*parameter) }
        } else if let Some(info) = info.get_mut(*parameter) {
            info
        } else {
            continue;
        };
        // MongoDB's `hint()` is the one that takes a key pattern; the others take a string.
        if *method == "hint" && info.type_.type_mask & engine::MAY_BE_ANY != engine::MAY_BE_ANY {
            continue;
        }
        let kept = info.type_.type_mask & !TYPE;
        match declared {
            Declared::Types(types) => {
                info.type_.ptr = std::ptr::null_mut();
                info.type_.type_mask = kept | types;
            }
            Declared::Class(name, types) => {
                info.type_.ptr = ZendStr::new_interned(name, true).into_raw().cast();
                info.type_.type_mask = kept | NAMED | types;
            }
        }
    }
    // `increment(string $field, int|float $amount = 1)`.
    if let Some(amount) = engine::arg_info(class, "increment").and_then(|info| info.get_mut(2)) {
        amount.default_value = c"1".as_ptr();
    }
}

/// The interface `name`, if [`register`] registered it. Nothing can be looked up by autoloading yet.
fn registered(name: &str) -> Option<&'static ClassEntry> {
    // SAFETY: the class table holds the classes registered so far, for as long as the process runs.
    unsafe {
        let found = zend_hash_str_find_ptr_lc(compiler_globals.class_table, name.as_ptr().cast(), name.len());
        found.cast::<ClassEntry>().as_ref()
    }
}

/// See the module's documentation.
unsafe extern "C" fn finish() -> i32 {
    let previous = PREVIOUS.load(Ordering::Acquire);
    if previous != 0 {
        // SAFETY: stored from the hook itself in `register`.
        let previous: unsafe extern "C" fn() -> i32 = unsafe { std::mem::transmute(previous) };
        if unsafe { previous() } != 0 {
            return -1;
        }
    }
    super::value::prepare();
    if !BUILDERS.load(Ordering::Acquire) {
        return 0;
    }
    let native: Vec<Vec<String>> = DIALECTS.iter().map(|dialect| names(dialect.methods)).collect();
    for (dialect, methods) in DIALECTS.iter().zip(&native) {
        fluent((dialect.class)(), methods);
        typed((dialect.class)());
        (dialect.prepare)();
    }
    for (name, dialects, _) in FEATURES {
        let Some(interface) = registered(name) else { continue };
        fluent(interface, &native[dialects.trailing_zeros() as usize]);
        typed(interface);
        for (bit, dialect) in DIALECTS.iter().enumerate() {
            if dialects & (1 << bit) != 0 {
                let (class, interface) =
                    (std::ptr::from_ref((dialect.class)()).cast_mut(), std::ptr::from_ref(interface).cast_mut());
                // SAFETY: both are internal classes, and the signatures agree by construction.
                unsafe { zend_do_implement_interface(class, interface) };
            }
        }
    }
    0
}
