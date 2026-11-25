//! `Utopia\Compression` over [`utopia_compression`], registered when `_APP_EXTENSION_LIBRARIES`
//! names `compression`: the abstract `Compression` and its seven algorithms (`Brotli`,
//! `Deflate`, `GZIP`, `LZ4`, `Snappy`, `XZ`, `Zstd`), so none of the package's PHP classes loads.
//!
//! Built by hand like the validators ([`crate::classes`]): no Rust object behind them, their
//! state in the PHP classes' own typed properties (`Brotli`'s `$level` and `$mode`, `Deflate`'s
//! private `$level`, the promoted `$level` of `LZ4` and `Zstd`), and the same inheritance, so
//! `instanceof`, `var_dump()`, `clone`, `==` and PHP subclasses behave as with the PHP classes.
//!
//! The codecs are the crate's, on the C libraries PHP's extensions link (the system zlib,
//! libzstd 1.5.7, C brotli 1.2.1, LZ4 1.10.0) and a port of snappy 1.2.1, called with the same
//! parameters and buffer sizes, so the bytes are PHP's. The input is read in place from the PHP
//! string and the output written straight into the PHP string returned ([`Output`]): PHP's
//! functions copy theirs at least once. Where PHP's function warns and returns `false`, which
//! the method's `string` return type turns into `""`, the method raises the same warning and
//! returns `""`. `XZ` calls `xzencode()` and `xzdecode()` as the PHP class does, so it works
//! exactly when ext-xz is loaded.

use std::panic::AssertUnwindSafe;
use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::args::{Arg, ArgInfoTables};
use ext_php_rs::builders::{ClassBuilder, FunctionBuilder};
use ext_php_rs::exception::throw;
use ext_php_rs::ffi::zend_string;
use ext_php_rs::flags::{ClassFlags, DataType, MethodFlags};
use ext_php_rs::types::{ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData, ce};
use utopia_compression::{Allocator, Brotli, Compression, Deflate, Error, Gzip, Lz4, Sink, Snappy, Zstd};

use crate::classes::engine::{self, Key, Literal, MAY_BE_LONG, MAY_BE_STRING, Property};
use crate::classes::leaf::{FunctionHandler, stored, this};

// The classes, in the order they are registered (the base first).
const BASE: usize = 0;
const BROTLI: usize = 1;
const DEFLATE: usize = 2;
const GZIP: usize = 3;
const LZ4: usize = 4;
const SNAPPY: usize = 5;
const XZ: usize = 6;
const ZSTD: usize = 7;
const COUNT: usize = 8;

const BASE_NAME: &str = "Utopia\\Compression\\Compression";

/// The registered classes, by the indexes above.
static CLASSES: [AtomicPtr<ClassEntry>; COUNT] = [const { AtomicPtr::new(std::ptr::null_mut()) }; COUNT];

fn class(index: usize) -> &'static ClassEntry {
    stored(&CLASSES[index])
}

/// The index of `ce` among the registered classes: which algorithm an object of a native
/// class is.
fn index_of(ce: *const ClassEntry) -> Option<usize> {
    CLASSES.iter().position(|slot| std::ptr::eq(slot.load(Ordering::Acquire), ce))
}

// The properties, each declared by one class.
static BROTLI_LEVEL: Property = Property::new("level");
static BROTLI_MODE: Property = Property::new("mode");
static DEFLATE_LEVEL: Property = Property::new("level");
static LZ4_LEVEL: Property = Property::new("level");
static ZSTD_LEVEL: Property = Property::new("level");

// What `getName()` and `getContentEncoding()` answer.
static NAME_BROTLI: Literal = Literal::new("brotli");
static NAME_DEFLATE: Literal = Literal::new("deflate");
static NAME_GZIP: Literal = Literal::new("gzip");
static NAME_LZ4: Literal = Literal::new("lz4");
static NAME_SNAPPY: Literal = Literal::new("snappy");
static NAME_XZ: Literal = Literal::new("xz");
static NAME_ZSTD: Literal = Literal::new("zstd");
static ENCODING_BROTLI: Literal = Literal::new("br");

/// The name a native class's `getName()` answers.
fn name_literal(index: usize) -> Option<&'static Literal> {
    Some(match index {
        BROTLI => &NAME_BROTLI,
        DEFLATE => &NAME_DEFLATE,
        GZIP => &NAME_GZIP,
        LZ4 => &NAME_LZ4,
        SNAPPY => &NAME_SNAPPY,
        XZ => &NAME_XZ,
        ZSTD => &NAME_ZSTD,
        _ => return None,
    })
}

/// `BROTLI_COMPRESS_LEVEL_DEFAULT` and `BROTLI_GENERIC`, the defaults of `Brotli`'s properties.
const BROTLI_DEFAULT_LEVEL: i64 = 11;
const BROTLI_GENERIC: i64 = 0;
/// The defaults of `new LZ4()` and `new Zstd()`, and `Deflate`'s level.
const LZ4_DEFAULT_LEVEL: i64 = 0;
const ZSTD_DEFAULT_LEVEL: i64 = 3;
const DEFLATE_LEVEL_VALUE: i64 = 6;

// Building the classes -------------------------------------------------------------------

/// A type in a method's signature.
#[derive(Clone, Copy)]
enum Ty {
    /// None: a constructor, or a method declared without one.
    None,
    Str,
    Int,
    Bool,
    Void,
    Array,
    /// `?Compression`.
    NullableBase,
}

impl Ty {
    fn data_type(self) -> Option<(DataType, bool)> {
        Some(match self {
            Ty::None => return None,
            Ty::Str => (DataType::String, false),
            Ty::Int => (DataType::Long, false),
            Ty::Bool => (DataType::Bool, false),
            Ty::Void => (DataType::Void, false),
            Ty::Array => (DataType::Array, false),
            Ty::NullableBase => (DataType::object(BASE_NAME), true),
        })
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

const DATA: &[Param] = &[("data", Ty::Str, None)];
const LEVEL: &[Param] = &[("level", Ty::Int, None)];

const fn public(name: &'static str, handler: FunctionHandler, params: &'static [Param], returns: Ty) -> Method {
    Method { name, handler: Some(handler), flags: MethodFlags::Public, params, returns }
}

const fn public_static(name: &'static str, handler: FunctionHandler, params: &'static [Param], returns: Ty) -> Method {
    Method { name, handler: Some(handler), flags: MethodFlags::Public.union(MethodFlags::Static), params, returns }
}

const fn public_abstract(name: &'static str, params: &'static [Param], returns: Ty) -> Method {
    Method { name, handler: None, flags: MethodFlags::Public.union(MethodFlags::Abstract), params, returns }
}

/// What a class declares once registered (properties, constants), and the constructor
/// parameters that declare a property.
struct Class {
    name: &'static str,
    methods: Vec<Method>,
    declare: fn(&mut ClassEntry),
    promoted: bool,
}

/// The class being registered: the engine calls [`registered`] inside `register()`, which runs
/// once per class in MINIT, one at a time.
/// The class's index, what it declares once registered, and whether its constructor promotes.
type Registering = (usize, fn(&mut ClassEntry), bool);

static REGISTERING: std::sync::Mutex<Registering> = std::sync::Mutex::new((0, |_| {}, false));

fn registered(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    let (index, declare, promoted) = *REGISTERING.lock().unwrap_or_else(std::sync::PoisonError::into_inner);
    declare(class);
    if promoted && let Some(info) = engine::arg_info(class, "__construct") {
        // `public function __construct(protected int $level = ...)`.
        info[1].type_.type_mask |= engine::IS_PROMOTED;
    }
    if index == BASE {
        make_abstract(class, "issupported");
    }
    CLASSES[index].store(class, Ordering::Release);
    // The engine points at the built tables for the methods it did not copy.
    Box::leak(tables);
}

/// Marks the method `name` (lowercase) of `class` abstract. The engine refuses an abstract
/// static method in an internal class that is not an interface (a startup warning, the method
/// left concrete), so `isSupported()` is registered static and made abstract here, before the
/// algorithms inherit it: as in PHP, they must declare it, and calling it on the base is
/// "Cannot call abstract method".
fn make_abstract(class: &ClassEntry, name: &str) {
    /// `ZEND_ACC_ABSTRACT`.
    const ACC_ABSTRACT: u32 = 1 << 6;
    // SAFETY: the class's function table and its functions live for the process; MINIT runs
    // before any code could call the method.
    unsafe {
        let function = ext_php_rs::ffi::zend_hash_str_find_ptr_lc(
            &raw const class.function_table,
            name.as_ptr().cast(),
            name.len(),
        )
        .cast::<ext_php_rs::ffi::zend_function>();
        if let Some(function) = function.as_mut() {
            function.common.fn_flags |= ACC_ABSTRACT;
        }
    }
}

fn build(index: usize, definition: Class) -> ext_php_rs::error::Result<()> {
    let mut builder = ClassBuilder::new(definition.name);
    for method in &definition.methods {
        let mut function = match method.handler {
            Some(handler) => FunctionBuilder::new(method.name, handler),
            None => FunctionBuilder::new_abstract(method.name),
        };
        for (name, ty, default) in method.params {
            let (data_type, nullable) = ty.data_type().unwrap_or((DataType::Mixed, false));
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
        if let Some((data_type, nullable)) = method.returns.data_type() {
            function = function.returns(data_type, false, nullable);
        }
        builder = builder.method(function, method.flags);
    }
    if index == BASE {
        builder = builder.flags(ClassFlags::Abstract);
    } else {
        builder = builder.extends((|| class(BASE), BASE_NAME));
    }
    *REGISTERING.lock().unwrap_or_else(std::sync::PoisonError::into_inner) =
        (index, definition.declare, definition.promoted);
    builder.registration(registered).register()
}

/// Declares `property` of `class`: an `int`, protected (private when `private`), with
/// `default` (`None`: promoted, uninitialized until the constructor runs).
fn declare_int(class: &mut ClassEntry, property: &Property, private: bool, default: Option<i64>) {
    let declared = engine::Declared { private, readonly: false, promoted: default.is_none() };
    let default = default.map(|value| {
        let mut zval = Zval::new();
        zval.set_long(value);
        zval
    });
    engine::declare_property(class, property, engine::PropertyType::Mask(MAY_BE_LONG), declared, default);
}

fn declare_base(class: &mut ClassEntry) {
    for (name, value) in [
        ("NONE", "none"),
        ("IDENTITY", "identity"),
        ("BROTLI", "brotli"),
        ("DEFLATE", "deflate"),
        ("GZIP", "gzip"),
        ("LZ4", "lz4"),
        ("SNAPPY", "snappy"),
        ("XZ", "xz"),
        ("ZSTD", "zstd"),
    ] {
        engine::declare_constant(class, name, engine::interned(value.as_bytes()), 0);
    }
}

fn declare_nothing(_: &mut ClassEntry) {}

/// Registers every class of `utopia-php/compression`. Called from the module's startup (MINIT)
/// when `_APP_EXTENSION_LIBRARIES` names `compression`.
pub fn register() -> ext_php_rs::error::Result<()> {
    engine::intern(&[
        &NAME_BROTLI,
        &NAME_DEFLATE,
        &NAME_GZIP,
        &NAME_LZ4,
        &NAME_SNAPPY,
        &NAME_XZ,
        &NAME_ZSTD,
        &ENCODING_BROTLI,
    ]);
    let classes = [
        Class {
            name: BASE_NAME,
            methods: vec![
                public_abstract("getName", &[], Ty::Str),
                public("getContentEncoding", get_content_encoding, &[], Ty::Str),
                public_abstract("compress", DATA, Ty::None),
                public_abstract("decompress", DATA, Ty::None),
                // Made abstract once registered (see `make_abstract`).
                public_static("isSupported", supported, &[], Ty::Bool),
                public_static("fromName", from_name, &[("name", Ty::Str, None)], Ty::NullableBase),
                public_static(
                    "fromAcceptEncoding",
                    from_accept_encoding,
                    &[("acceptEncoding", Ty::Str, None), ("supported", Ty::Array, Some("[]"))],
                    Ty::NullableBase,
                ),
            ],
            declare: declare_base,
            promoted: false,
        },
        Class {
            name: "Utopia\\Compression\\Algorithms\\Brotli",
            methods: vec![
                public("getName", brotli_name, &[], Ty::Str),
                public("getContentEncoding", brotli_encoding, &[], Ty::Str),
                public("getLevel", brotli_get_level, &[], Ty::Int),
                public("useGenericMode", brotli_generic_mode, &[], Ty::Void),
                public("useTextMode", brotli_generic_mode, &[], Ty::Void),
                public("useFontMode", brotli_generic_mode, &[], Ty::Void),
                public("setLevel", brotli_set_level, LEVEL, Ty::Void),
                public("compress", brotli_compress, DATA, Ty::Str),
                public("decompress", brotli_decompress, DATA, Ty::Str),
                public_static("isSupported", supported, &[], Ty::Bool),
            ],
            declare: |class| {
                declare_int(class, &BROTLI_LEVEL, false, Some(BROTLI_DEFAULT_LEVEL));
                declare_int(class, &BROTLI_MODE, false, Some(BROTLI_GENERIC));
            },
            promoted: false,
        },
        Class {
            name: "Utopia\\Compression\\Algorithms\\Deflate",
            methods: vec![
                public("getName", deflate_name, &[], Ty::Str),
                public("compress", deflate_compress, DATA, Ty::Str),
                public("decompress", deflate_decompress, DATA, Ty::Str),
                public_static("isSupported", supported, &[], Ty::Bool),
            ],
            declare: |class| declare_int(class, &DEFLATE_LEVEL, true, Some(DEFLATE_LEVEL_VALUE)),
            promoted: false,
        },
        Class {
            name: "Utopia\\Compression\\Algorithms\\GZIP",
            methods: vec![
                public("getName", gzip_name, &[], Ty::Str),
                public("compress", gzip_compress, DATA, Ty::Str),
                public("decompress", gzip_decompress, DATA, Ty::Str),
                public_static("isSupported", supported, &[], Ty::Bool),
            ],
            declare: declare_nothing,
            promoted: false,
        },
        Class {
            name: "Utopia\\Compression\\Algorithms\\LZ4",
            methods: vec![
                public("__construct", lz4_construct, &[("level", Ty::Int, Some("0"))], Ty::None),
                public("getLevel", lz4_get_level, &[], Ty::Int),
                public("setLevel", lz4_set_level, LEVEL, Ty::Void),
                public("getName", lz4_name, &[], Ty::Str),
                public("compress", lz4_compress, DATA, Ty::Str),
                public("decompress", lz4_decompress, DATA, Ty::Str),
                public_static("isSupported", supported, &[], Ty::Bool),
            ],
            declare: |class| declare_int(class, &LZ4_LEVEL, false, None),
            promoted: true,
        },
        Class {
            name: "Utopia\\Compression\\Algorithms\\Snappy",
            methods: vec![
                public("getName", snappy_name, &[], Ty::Str),
                public("compress", snappy_compress, DATA, Ty::Str),
                public("decompress", snappy_decompress, DATA, Ty::Str),
                public_static("isSupported", supported, &[], Ty::Bool),
            ],
            declare: declare_nothing,
            promoted: false,
        },
        Class {
            name: "Utopia\\Compression\\Algorithms\\XZ",
            methods: vec![
                public("getName", xz_name, &[], Ty::Str),
                public("compress", xz_compress, DATA, Ty::Str),
                public("decompress", xz_decompress, DATA, Ty::Str),
                public_static("isSupported", xz_supported, &[], Ty::Bool),
            ],
            declare: declare_nothing,
            promoted: false,
        },
        Class {
            name: "Utopia\\Compression\\Algorithms\\Zstd",
            methods: vec![
                public("__construct", zstd_construct, &[("level", Ty::Int, Some("3"))], Ty::None),
                public("getLevel", zstd_get_level, &[], Ty::Int),
                public("setLevel", zstd_set_level, LEVEL, Ty::Void),
                public("getName", zstd_name, &[], Ty::Str),
                public("compress", zstd_compress, DATA, Ty::Str),
                public("decompress", zstd_decompress, DATA, Ty::Str),
                public_static("isSupported", supported, &[], Ty::Bool),
            ],
            declare: |class| declare_int(class, &ZSTD_LEVEL, false, None),
            promoted: true,
        },
    ];
    for (index, definition) in classes.into_iter().enumerate() {
        build(index, definition)?;
    }
    Ok(())
}

// The output ------------------------------------------------------------------------------

unsafe extern "C" {
    // Release builds of PHP (`ZEND_DEBUG` off), as Appwrite's and every published image are:
    // the allocator's functions take no file and line.
    fn _emalloc(size: usize) -> *mut std::ffi::c_void;
    fn _erealloc(ptr: *mut std::ffi::c_void, size: usize) -> *mut std::ffi::c_void;
    fn _efree(ptr: *mut std::ffi::c_void);
    fn zend_memory_usage(real_usage: bool) -> usize;
}

/// `IS_STRING`, and a string zval that counts references (`IS_STRING_EX`).
const IS_STRING: u32 = 6;
const IS_STRING_EX: u32 = IS_STRING | (1 << 8);
/// `GC_STRING`: a string's type info, which never holds cycles.
const GC_STRING: u32 = IS_STRING | (1 << 4);
/// `_ZSTR_HEADER_SIZE`: a string's header, before its bytes.
const HEADER: usize = std::mem::offset_of!(zend_string, val);
/// What a guess at the final size (a [`Sink::reserve`]) leaves free under `memory_limit`.
const RESERVE_MARGIN: usize = 4 << 20;

/// A PHP string the codec writes into in place, from PHP's allocator, as PHP's functions build
/// theirs: it counts against `memory_limit`, and running out is PHP's fatal error. It becomes
/// the method's return value as it is, cut down to its length as `zend_string_truncate()` does.
struct Output {
    string: *mut zend_string,
    capacity: usize,
    len: usize,
}

impl Output {
    fn new() -> Self {
        Output { string: std::ptr::null_mut(), capacity: 0, len: 0 }
    }

    fn bytes(&mut self) -> *mut u8 {
        // SAFETY: the bytes start after the header of the allocation.
        unsafe { std::ptr::addr_of_mut!((*self.string).val).cast::<u8>() }
    }

    /// The string, its length set and terminated, as the method's return value.
    fn finish(mut self, retval: &mut Zval) {
        if self.string.is_null() {
            retval.set_zend_string(ZendStr::new(b"", false));
            return;
        }
        if self.capacity != self.len {
            // SAFETY: the allocation is PHP's, of `HEADER + capacity + 1` bytes; shrinking keeps
            // the header and the `len` bytes written.
            self.string = unsafe { _erealloc(self.string.cast(), HEADER + self.len + 1) }.cast();
            self.capacity = self.len;
        }
        let string = std::mem::replace(&mut self.string, std::ptr::null_mut());
        // SAFETY: a live allocation with room for the header, `len` bytes and the NUL,
        // initialised as `zend_string_alloc()` initialises one.
        unsafe {
            (*string).gc.refcount = 1;
            (*string).gc.u.type_info = GC_STRING;
            (*string).h = 0;
            (*string).len = self.len;
            std::ptr::addr_of_mut!((*string).val).cast::<u8>().add(self.len).write(0);
            retval.value.str_ = string;
            retval.u1.type_info = IS_STRING_EX;
        }
    }
}

/// The room PHP's allocator must keep under `memory_limit` for a block of codec state to come
/// from it: a new chunk's worth.
const CHUNK: usize = 2 << 20;

/// What precedes a block of codec state: its size, and whether `malloc()` holds it.
const TAG: usize = 16;

/// Whether `extra` more bytes from PHP's allocator leave `margin` under `memory_limit`.
fn fits(extra: usize, margin: usize) -> bool {
    // SAFETY: the engine's globals, read on the thread that runs PHP.
    let limit = unsafe { (*ext_php_rs::ffi::ext_php_rs_process_globals()).memory_limit };
    if limit < 0 {
        return true;
    }
    // SAFETY: reads the allocator's counters.
    let used = unsafe { zend_memory_usage(true) };
    used.saturating_add(extra).saturating_add(margin) <= limit as usize
}

/// A block of a codec's working state (zstd's contexts, brotli's encoder and decoder). Small
/// ones come from PHP's allocator, whose free lists serve them without the `mmap()` and page
/// faults `malloc()` costs for each context PHP's extensions create; large ones, and any that
/// would bring the process near `memory_limit`, from `malloc()`, as PHP's extensions allocate
/// all of theirs, so the state never counts against the limit more than a few megabytes.
extern "C" fn codec_alloc(_: *mut std::ffi::c_void, size: usize) -> *mut std::ffi::c_void {
    let Some(total) = size.checked_add(TAG).map(|total| total.next_multiple_of(16)) else {
        return std::ptr::null_mut();
    };
    let (block, malloced) = if total < CHUNK && fits(total, CHUNK) {
        // SAFETY: PHP's allocator; a size that is a multiple of 16 lands in a bin of that size,
        // so the block is 16-byte aligned, as `malloc()` aligns one.
        (unsafe { _emalloc(total) }.cast::<u8>(), 0)
    } else {
        let Ok(layout) = std::alloc::Layout::from_size_align(total, 16) else { return std::ptr::null_mut() };
        // SAFETY: a layout of non-zero size.
        (unsafe { std::alloc::GlobalAlloc::alloc(&std::alloc::System, layout) }, 1)
    };
    if block.is_null() {
        return std::ptr::null_mut();
    }
    // SAFETY: the block holds the tag and `size` bytes after it.
    unsafe {
        block.cast::<[usize; 2]>().write([total, malloced]);
        block.add(TAG).cast()
    }
}

extern "C" fn codec_free(_: *mut std::ffi::c_void, address: *mut std::ffi::c_void) {
    if address.is_null() {
        return;
    }
    // SAFETY: a block of `codec_alloc`, its tag `TAG` bytes before it.
    unsafe {
        let block = address.cast::<u8>().sub(TAG);
        let [total, malloced] = block.cast::<[usize; 2]>().read();
        if malloced == 0 {
            _efree(block.cast());
        } else {
            let layout = std::alloc::Layout::from_size_align_unchecked(total, 16);
            std::alloc::GlobalAlloc::dealloc(&std::alloc::System, block, layout);
        }
    }
}

impl Drop for Output {
    fn drop(&mut self) {
        if !self.string.is_null() {
            // SAFETY: an allocation of PHP's allocator, not handed to the engine.
            unsafe { _efree(self.string.cast()) };
        }
    }
}

// SAFETY: the pointer covers `capacity` bytes after the header, the first `len` written;
// growing reallocates, which keeps them.
unsafe impl Sink for Output {
    fn len(&self) -> usize {
        self.len
    }

    fn capacity(&self) -> usize {
        self.capacity
    }

    fn grow(&mut self, capacity: usize) -> bool {
        if capacity <= self.capacity {
            return true;
        }
        let Some(size) = capacity.checked_add(HEADER + 1) else { return false };
        // A first allocation past `memory_limit` asks for `capacity` alone, the buffer PHP's
        // function allocates (a zstd frame's content size, a snappy length), so the fatal error
        // names the size PHP's does; were it granted after all, it is given back.
        if self.string.is_null() && !fits(size, 0) {
            // SAFETY: PHP's allocator, which stops the request when the limit is reached.
            unsafe { _efree(_emalloc(capacity)) };
        }
        // SAFETY: PHP's allocator: a new block, or the string's grown (a request past
        // `memory_limit` is PHP's fatal error, as for PHP's own functions).
        self.string =
            unsafe { if self.string.is_null() { _emalloc(size) } else { _erealloc(self.string.cast(), size) } }.cast();
        self.capacity = capacity;
        true
    }

    fn reserve(&mut self, capacity: usize) -> bool {
        if capacity <= self.capacity {
            return true;
        }
        // A guess is only taken while it leaves room under `memory_limit`, so it never fails
        // where PHP's step-by-step growth would not have.
        fits(capacity - self.capacity, RESERVE_MARGIN) && self.grow(capacity)
    }

    fn as_mut_ptr(&mut self) -> *mut u8 {
        if self.string.is_null() { std::ptr::NonNull::dangling().as_ptr() } else { self.bytes() }
    }

    unsafe fn set_len(&mut self, len: usize) {
        self.len = len;
    }

    fn allocator(&self) -> Option<Allocator> {
        Some(Allocator { alloc: codec_alloc, free: codec_free, opaque: std::ptr::null_mut() })
    }
}

// What the handlers share --------------------------------------------------------------

/// Runs a handler's body, a panic thrown as PHP's `Error` (`Rust panic: ...`) and a bailout
/// passed on to the engine.
#[inline]
fn guard(body: impl FnOnce()) {
    ext_php_rs::zend::run_handler(AssertUnwindSafe(body));
}

/// `Class::method`, the executing method's name as PHP's errors give it.
fn function_name(ex: &ExecuteData) -> String {
    // SAFETY: the executing function, its scope and its name live as long as the call.
    unsafe {
        let Some(func) = ex.func.as_ref() else { return String::new() };
        let class = func.common.scope.as_ref().and_then(|scope| scope.name()).unwrap_or_default();
        let name = func.common.function_name.cast::<ZendStr>().as_ref().and_then(|name| name.as_str().ok());
        format!("{class}::{}", name.unwrap_or_default())
    }
}

/// The method's argument `num` (from 1), or the `ArgumentCountError` of a call with fewer than
/// `required`.
fn arg(ex: &mut ExecuteData, num: usize, required: usize, total: usize) -> Option<&'static mut Zval> {
    let passed = engine::args_count(ex);
    if passed < required {
        engine::too_few(ex, &function_name(ex), passed, required, total);
        return None;
    }
    engine::arg(ex, num - 1)
}

/// The method's `string $data`, the caller's string itself when it is one (no copy), coerced as
/// a userland `string` parameter is otherwise.
fn data(ex: &mut ExecuteData) -> Option<Zval> {
    let arg = arg(ex, 1, 1, 1)?;
    engine::str_zval_arg(ex, 1, arg)
}

/// The method's `int $level`.
fn level_arg(ex: &mut ExecuteData) -> Option<i64> {
    let arg = arg(ex, 1, 1, 1)?;
    engine::long_arg(ex, 1, arg)
}

fn bytes(value: &Zval) -> &[u8] {
    value.zend_str().map(ZendStr::as_bytes).unwrap_or_default()
}

/// Whether `object` is of exactly the native class at `index` (not a PHP subclass, whose
/// overrides must be called).
fn exact(object: &ZendObject, index: usize) -> bool {
    std::ptr::eq(object.ce, class(index))
}

fn long(value: i64) -> Zval {
    let mut zval = Zval::new();
    zval.set_long(value);
    zval
}

/// `$this->property`, `property` declared by the class at `index`, as an int.
fn read_int(this: &mut ZendObject, index: usize, property: &Property) -> Option<i64> {
    let value = engine::get(class(index), this, property)?;
    Some(value.long().unwrap_or_default())
}

/// Runs `codec` into a new PHP string returned by the method. A codec's failure is PHP's: the
/// warning its function raises, and `""` (the `false` it returns, as the method's `string`
/// return type converts it).
fn run(retval: &mut Zval, codec: impl FnOnce(&mut Output) -> Result<(), Error>) {
    let mut output = Output::new();
    match codec(&mut output) {
        Ok(()) => output.finish(retval),
        Err(Error::Codec(message)) => {
            drop(output);
            engine::warning(&message);
            retval.set_zend_string(ZendStr::new(b"", false));
        }
        Err(Error::InvalidArgument(message)) => invalid_argument(&message),
        Err(error) => engine::error(&error.to_string()),
    }
}

/// Throws `\InvalidArgumentException` with `message`.
fn invalid_argument(message: &str) {
    let class = ClassEntry::try_find("InvalidArgumentException").unwrap_or_else(ce::exception);
    let _ = throw(class, message);
}

/// `$this->level = $level` after `setLevel()`'s range check (`min..=max`).
fn set_level(ex: &mut ExecuteData, index: usize, property: &Property, min: i64, max: i64) {
    let Some(level) = level_arg(ex) else { return };
    if !(min..=max).contains(&level) {
        return invalid_argument(&format!("Level must be between {min} and {max}"));
    }
    let Some(this) = this(ex) else { return };
    engine::set(class(index), this, property, long(level));
}

/// `return $this->level;`.
fn get_level(ex: &mut ExecuteData, retval: &mut Zval, index: usize, property: &Property) {
    let Some(this) = this(ex) else { return };
    if let Some(level) = engine::get(class(index), this, property) {
        *retval = level;
    }
}

/// `__construct(protected int $level = $default)`.
fn construct(ex: &mut ExecuteData, index: usize, property: &Property, default: i64) {
    let level = match engine::arg(ex, 0) {
        Some(arg) => match engine::long_arg(ex, 1, arg) {
            Some(level) => level,
            None => return,
        },
        None => default,
    };
    let Some(this) = this(ex) else { return };
    engine::initialize(class(index), this, property, long(level));
}

// The base ----------------------------------------------------------------------------------

/// `strtolower($this->getName())`: the name of a native algorithm as it is, a PHP subclass's
/// `getName()` called.
extern "C" fn get_content_encoding(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(this) = this(ex) else { return };
        if let Some(literal) = index_of(this.ce).and_then(name_literal) {
            return literal.set(retval);
        }
        let Some(name) = engine::call_own_method(this, "getName", &[]) else { return };
        let Some(name) = engine::to_string(&name) else { return };
        let lower = php_std::string::strtolower(&name);
        retval.set_zend_string(ZendStr::new(&lower[..], false));
    });
}

/// A new object of the native algorithm `algorithm`, as `new Algorithms\X()` makes it.
fn create(retval: &mut Zval, algorithm: &Compression) {
    let (index, level) = match algorithm {
        Compression::Brotli(_) => (BROTLI, None),
        Compression::Deflate(_) => (DEFLATE, None),
        Compression::Gzip(_) => (GZIP, None),
        Compression::Lz4(_) => (LZ4, Some((&LZ4_LEVEL, LZ4_DEFAULT_LEVEL))),
        Compression::Snappy(_) => (SNAPPY, None),
        Compression::Xz(_) => (XZ, None),
        Compression::Zstd(_) => (ZSTD, Some((&ZSTD_LEVEL, ZSTD_DEFAULT_LEVEL))),
    };
    let mut object = ZendObject::new(class(index));
    if let Some((property, level)) = level {
        engine::initialize(class(index), &mut object, property, long(level));
    }
    retval.set_object(&mut object);
}

/// `Compression::fromName($name)`.
extern "C" fn from_name(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(arg) = arg(ex, 1, 1, 1) else { return };
        let Some(name) = engine::str_zval_arg(ex, 1, arg) else { return };
        match Compression::from_name_bytes(bytes(&name)) {
            Some(algorithm) => create(retval, &algorithm),
            None => retval.set_null(),
        }
    });
}

/// `Compression::fromAcceptEncoding($acceptEncoding, $supported)`.
extern "C" fn from_accept_encoding(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(first) = arg(ex, 1, 1, 2) else { return };
        let Some(accept) = engine::str_zval_arg(ex, 1, first) else { return };
        let supported = match engine::arg(ex, 1) {
            Some(arg) => match engine::array_arg(ex, 2, arg) {
                Some(array) => array,
                None => return,
            },
            None => engine::empty_array(),
        };
        let accept = bytes(&accept);
        if accept.is_empty() || accept == b"0" {
            return retval.set_null();
        }
        let chosen = match supported.array().filter(|table| !table.is_empty()) {
            // `zstd`, `brotli`, `gzip` and `deflate` (each supported here), `none` and
            // `identity`.
            None => Compression::from_accept_encoding_bytes(accept, |name| {
                matches!(name, b"zstd" | b"brotli" | b"gzip" | b"deflate" | b"none" | b"identity")
            }),
            // A list is `array_fill_keys($supported, true)`: each value becomes a key (an int as
            // it is, anything else as PHP converts it to a string, warnings included).
            Some(table) if table.has_sequential_keys() => {
                let mut keys = Vec::with_capacity(table.len());
                for value in engine::values(table) {
                    let value = value.dereference();
                    if let Some(int) = value.long().filter(|_| value.is_long()) {
                        keys.push(Key::Index(int));
                    } else {
                        let Some(string) = engine::to_string(value) else { return };
                        keys.push(Key::of(&string));
                    }
                }
                Compression::from_accept_encoding_bytes(accept, |name| keys.contains(&Key::of(name)))
            }
            // A map: `isset($supported[$name]) && $supported[$name]`.
            Some(_) => Compression::from_accept_encoding_bytes(accept, |name| {
                engine::array_get(&supported, &Key::of(name))
                    .is_some_and(|value| !value.is_null() && engine::truthy(value))
            }),
        };
        match chosen {
            Some(algorithm) => create(retval, &algorithm),
            None => retval.set_null(),
        }
    });
}

/// `isSupported()` of an algorithm the crate provides: `true`.
extern "C" fn supported(_: &mut ExecuteData, retval: &mut Zval) {
    retval.set_bool(true);
}

// The algorithms ------------------------------------------------------------------------------

extern "C" fn brotli_name(_: &mut ExecuteData, retval: &mut Zval) {
    NAME_BROTLI.set(retval);
}
extern "C" fn deflate_name(_: &mut ExecuteData, retval: &mut Zval) {
    NAME_DEFLATE.set(retval);
}
extern "C" fn gzip_name(_: &mut ExecuteData, retval: &mut Zval) {
    NAME_GZIP.set(retval);
}
extern "C" fn lz4_name(_: &mut ExecuteData, retval: &mut Zval) {
    NAME_LZ4.set(retval);
}
extern "C" fn snappy_name(_: &mut ExecuteData, retval: &mut Zval) {
    NAME_SNAPPY.set(retval);
}
extern "C" fn xz_name(_: &mut ExecuteData, retval: &mut Zval) {
    NAME_XZ.set(retval);
}
extern "C" fn zstd_name(_: &mut ExecuteData, retval: &mut Zval) {
    NAME_ZSTD.set(retval);
}
extern "C" fn brotli_encoding(_: &mut ExecuteData, retval: &mut Zval) {
    ENCODING_BROTLI.set(retval);
}

extern "C" fn brotli_get_level(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| get_level(ex, retval, BROTLI, &BROTLI_LEVEL));
}

extern "C" fn brotli_set_level(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| set_level(ex, BROTLI, &BROTLI_LEVEL, 0, 11));
}

/// `useGenericMode()`, `useTextMode()` and `useFontMode()`: each sets `BROTLI_GENERIC`, as in PHP.
extern "C" fn brotli_generic_mode(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| {
        let Some(this) = this(ex) else { return };
        engine::set(class(BROTLI), this, &BROTLI_MODE, long(BROTLI_GENERIC));
    });
}

/// `brotli_compress($data, $this->getLevel(), $this->mode)`.
extern "C" fn brotli_compress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        let Some(this) = this(ex) else { return };
        let level = if exact(this, BROTLI) {
            read_int(this, BROTLI, &BROTLI_LEVEL)
        } else {
            engine::call_own_method(this, "getLevel", &[]).map(|level| level.long().unwrap_or_default())
        };
        let Some(level) = level else { return };
        let Some(mode) = read_int(this, BROTLI, &BROTLI_MODE) else { return };
        run(retval, |out| Brotli::compress_with(bytes(&data), level, mode, out));
    });
}

extern "C" fn brotli_decompress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        run(retval, |out| Brotli::new().decompress_into(bytes(&data), out));
    });
}

/// `gzdeflate($data, $this->level)`: always 6, unless something reached the private property.
extern "C" fn deflate_compress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        let Some(this) = this(ex) else { return };
        let Some(level) = engine::get(class(DEFLATE), this, &DEFLATE_LEVEL) else { return };
        if level.long() != Some(DEFLATE_LEVEL_VALUE) {
            return call_php(ex, retval, "gzdeflate", &[&data, &level]);
        }
        run(retval, |out| Deflate::new().compress_into(bytes(&data), out));
    });
}

extern "C" fn deflate_decompress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        run(retval, |out| Deflate::new().decompress_into(bytes(&data), out));
    });
}

extern "C" fn gzip_compress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        run(retval, |out| Gzip::new().compress_into(bytes(&data), out));
    });
}

extern "C" fn gzip_decompress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        run(retval, |out| Gzip::new().decompress_into(bytes(&data), out));
    });
}

extern "C" fn lz4_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| construct(ex, LZ4, &LZ4_LEVEL, LZ4_DEFAULT_LEVEL));
}

extern "C" fn lz4_get_level(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| get_level(ex, retval, LZ4, &LZ4_LEVEL));
}

extern "C" fn lz4_set_level(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| set_level(ex, LZ4, &LZ4_LEVEL, 0, 12));
}

/// `lz4_compress($data, $this->level)`.
extern "C" fn lz4_compress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        let Some(this) = this(ex) else { return };
        let Some(level) = read_int(this, LZ4, &LZ4_LEVEL) else { return };
        run(retval, |out| Lz4::with_level(level).compress_into(bytes(&data), out));
    });
}

extern "C" fn lz4_decompress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        run(retval, |out| Lz4::new().decompress_into(bytes(&data), out));
    });
}

extern "C" fn snappy_compress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        run(retval, |out| Snappy::new().compress_into(bytes(&data), out));
    });
}

extern "C" fn snappy_decompress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        run(retval, |out| Snappy::new().decompress_into(bytes(&data), out));
    });
}

extern "C" fn zstd_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| construct(ex, ZSTD, &ZSTD_LEVEL, ZSTD_DEFAULT_LEVEL));
}

extern "C" fn zstd_get_level(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| get_level(ex, retval, ZSTD, &ZSTD_LEVEL));
}

extern "C" fn zstd_set_level(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| set_level(ex, ZSTD, &ZSTD_LEVEL, 1, 22));
}

/// `zstd_compress($data, $this->level)`.
extern "C" fn zstd_compress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        let Some(this) = this(ex) else { return };
        let Some(level) = read_int(this, ZSTD, &ZSTD_LEVEL) else { return };
        run(retval, |out| Zstd::with_level(level).compress_into(bytes(&data), out));
    });
}

extern "C" fn zstd_decompress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        run(retval, |out| Zstd::new().decompress_into(bytes(&data), out));
    });
}

// XZ: ext-xz's functions, as the PHP class calls them ----------------------------------------

/// Whether the function `name` is defined (lowercase).
fn function_exists(name: &str) -> bool {
    // SAFETY: the engine's function table, read on the thread that runs PHP.
    unsafe {
        let table = (*ext_php_rs::ffi::ext_php_rs_executor_globals()).function_table;
        !ext_php_rs::ffi::zend_hash_str_find(table, name.as_ptr().cast(), name.len()).is_null()
    }
}

/// `return name(...$args);` from a method declared `: string` in a file without
/// `strict_types`: an unqualified call in `Utopia\Compression\Algorithms`, which finds the
/// namespaced function first and the global one then, and PHP's `Error` when neither exists.
fn call_php(ex: &mut ExecuteData, retval: &mut Zval, name: &str, args: &[&Zval]) {
    let namespaced = format!("utopia\\compression\\algorithms\\{name}");
    let function = if function_exists(&namespaced) {
        namespaced
    } else if function_exists(name) {
        name.to_owned()
    } else {
        return engine::error(&format!("Call to undefined function Utopia\\Compression\\Algorithms\\{name}()"));
    };
    let Some(result) = engine::call(&function, args) else { return };
    if let Some(value) = engine::verify_return(ex, result, MAY_BE_STRING, false) {
        *retval = value;
    }
}

extern "C" fn xz_compress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        call_php(ex, retval, "xzencode", &[&data]);
    });
}

extern "C" fn xz_decompress(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(data) = data(ex) else { return };
        call_php(ex, retval, "xzdecode", &[&data]);
    });
}

/// `\function_exists('xzencode')`.
extern "C" fn xz_supported(_: &mut ExecuteData, retval: &mut Zval) {
    retval.set_bool(function_exists("xzencode"));
}
