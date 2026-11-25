//! PCRE2 as `ext/pcre` holds it: compiled patterns shared by every thread,
//! and per thread the match context (`pcre.backtrack_limit`,
//! `pcre.recursion_limit` and the 32 KiB–192 KiB JIT stack) and the
//! preallocated match data `php_pcre_init_pcre2()` creates.
//!
//! PHP keeps that state per thread too (`ZEND_TLS`), so a Rust thread
//! running several patterns behaves like one PHP process.

use std::cell::Cell;
use std::ffi::{CStr, c_int, c_void};
use std::ptr::{self, NonNull};
use std::sync::atomic::{AtomicBool, Ordering};

use super::ffi;

/// `pcre.backtrack_limit` and `pcre.recursion_limit` defaults.
const BACKTRACK_LIMIT: u32 = 1_000_000;
const RECURSION_LIMIT: u32 = 100_000;
/// `PCRE_JIT_STACK_MIN_SIZE` and `PCRE_JIT_STACK_MAX_SIZE`.
const JIT_STACK_MIN: usize = 32 * 1024;
const JIT_STACK_MAX: usize = 192 * 1024;
/// `PHP_PCRE_PREALLOC_MDATA_SIZE`.
const PREALLOC_PAIRS: u32 = 32;

/// A compiled pattern (`pcre2_code`), with its JIT code if compiled.
pub(super) struct Code(NonNull<ffi::Code>);

// SAFETY: a `pcre2_code` is not modified after `pcre2_jit_compile()`, which
// takes `&mut self`; PCRE2 documents compiled patterns (JIT code included) as
// safe to use from several threads at once, each with its own match data and
// JIT stack, which `Session` keeps per thread.
unsafe impl Send for Code {}
// SAFETY: see `Send`.
unsafe impl Sync for Code {}

impl Drop for Code {
    fn drop(&mut self) {
        // SAFETY: the pointer came from pcre2_compile() and is freed once.
        unsafe { ffi::pcre2_code_free_8(self.0.as_ptr()) }
    }
}

/// A `pcre2_compile()` failure: the error number and offset.
pub(super) struct CompileError {
    pub code: c_int,
    pub offset: usize,
}

impl Code {
    /// `pcre2_compile()` with `options` and, through a compile context,
    /// `extra` options.
    pub fn compile(pattern: &[u8], options: u32, extra: u32) -> Result<Code, CompileError> {
        let mut code = 0;
        let mut offset = 0;
        // SAFETY: the pattern pointer and length describe a live slice; the
        // context is created, used and freed here.
        let re = unsafe {
            let ctx = if extra != 0 {
                let ctx = ffi::pcre2_compile_context_create_8(ptr::null_mut());
                if !ctx.is_null() {
                    ffi::pcre2_set_compile_extra_options_8(ctx, extra);
                }
                ctx
            } else {
                ptr::null_mut()
            };
            let re = ffi::pcre2_compile_8(pattern.as_ptr(), pattern.len(), options, &mut code, &mut offset, ctx);
            if !ctx.is_null() {
                ffi::pcre2_compile_context_free_8(ctx);
            }
            re
        };
        NonNull::new(re).map(Code).ok_or(CompileError { code, offset })
    }

    /// `pcre2_jit_compile(PCRE2_JIT_COMPLETE)`: whether there is JIT code
    /// (`PCRE2_INFO_JITSIZE` > 0; `(*NO_JIT)` leaves none), or the error.
    pub fn jit_compile(&mut self) -> Result<bool, c_int> {
        // SAFETY: a valid pattern, owned exclusively.
        let rc = unsafe { ffi::pcre2_jit_compile_8(self.0.as_ptr(), ffi::JIT_COMPLETE) };
        if rc < 0 {
            return Err(rc);
        }
        let mut size: usize = 0;
        Ok(self.info(ffi::INFO_JITSIZE, &mut size as *mut usize as *mut c_void) == 0 && size > 0)
    }

    fn info(&self, what: u32, out: *mut c_void) -> c_int {
        // SAFETY: `out` points to storage of the type PCRE2 writes for `what`.
        unsafe { ffi::pcre2_pattern_info_8(self.0.as_ptr(), what, out) }
    }

    /// `PCRE2_INFO_CAPTURECOUNT`.
    pub fn capture_count(&self) -> u32 {
        let mut n: u32 = 0;
        self.info(ffi::INFO_CAPTURECOUNT, &mut n as *mut u32 as *mut c_void);
        n
    }

    /// `make_subpats_table()`: the named groups, as (group, name).
    pub fn names(&self) -> Vec<(usize, Vec<u8>)> {
        let mut count: u32 = 0;
        let mut size: u32 = 0;
        let mut table: *const u8 = ptr::null();
        self.info(ffi::INFO_NAMECOUNT, &mut count as *mut u32 as *mut c_void);
        if count == 0 {
            return Vec::new();
        }
        self.info(ffi::INFO_NAMEENTRYSIZE, &mut size as *mut u32 as *mut c_void);
        self.info(ffi::INFO_NAMETABLE, &mut table as *mut *const u8 as *mut c_void);
        (0..count as usize)
            .map(|i| {
                // SAFETY: the name table holds `count` entries of `size`
                // bytes: a big-endian group number and a NUL-terminated name.
                unsafe {
                    let entry = table.add(i * size as usize);
                    let group = usize::from(*entry) << 8 | usize::from(*entry.add(1));
                    (group, CStr::from_ptr(entry.add(2).cast()).to_bytes().to_vec())
                }
            })
            .collect()
    }
}

/// `pcre2_get_error_message()`.
pub(super) fn error_message(code: c_int) -> Vec<u8> {
    let mut buf = [0u8; 256];
    // SAFETY: the buffer and its length.
    let n = unsafe { ffi::pcre2_get_error_message_8(code, buf.as_mut_ptr(), buf.len()) };
    buf[..usize::try_from(n).unwrap_or(0)].to_vec()
}

/// Whether `c` is in `\pL` or `\pN` by PCRE2's Unicode tables.
pub(crate) fn is_letter_or_number(c: u32) -> bool {
    // SAFETY: a pure table lookup, bounds-checked in C.
    unsafe { ffi::php_std_pcre2_is_letter_or_number(c) != 0 }
}

/// `ini_set('pcre.jit', ...)` as `OnUpdateJit` applies it to the match
/// context: the JIT stack is assigned only while the JIT is on (otherwise
/// JIT code runs on PCRE2's 32 KiB machine-stack block).
static JIT_STACK: AtomicBool = AtomicBool::new(true);

/// One thread's `mctx`, `jit_stack` and `mdata`.
struct Thread {
    mctx: NonNull<ffi::MatchContext>,
    stack: NonNull<ffi::JitStack>,
    stack_assigned: Cell<bool>,
    /// The preallocated match data, null while a call uses it (`mdata_used`).
    mdata: Cell<*mut ffi::MatchData>,
}

impl Thread {
    fn new() -> Thread {
        // SAFETY: plain allocations with PCRE2's default allocator; a
        // failure aborts, as PHP fails to start without them.
        unsafe {
            let mctx = NonNull::new(ffi::pcre2_match_context_create_8(ptr::null_mut())).expect("PCRE2 match context");
            ffi::pcre2_set_match_limit_8(mctx.as_ptr(), BACKTRACK_LIMIT);
            ffi::pcre2_set_depth_limit_8(mctx.as_ptr(), RECURSION_LIMIT);
            let stack = NonNull::new(ffi::pcre2_jit_stack_create_8(JIT_STACK_MIN, JIT_STACK_MAX, ptr::null_mut()))
                .expect("PCRE2 JIT stack");
            ffi::pcre2_jit_stack_assign_8(mctx.as_ptr(), None, stack.as_ptr().cast());
            let mdata = ffi::pcre2_match_data_create_8(PREALLOC_PAIRS, ptr::null_mut());
            Thread { mctx, stack, stack_assigned: Cell::new(true), mdata: Cell::new(mdata) }
        }
    }
}

impl Drop for Thread {
    fn drop(&mut self) {
        // SAFETY: each was created in `new` and is freed once; no session
        // outlives the thread.
        unsafe {
            let mdata = self.mdata.get();
            if !mdata.is_null() {
                ffi::pcre2_match_data_free_8(mdata);
            }
            ffi::pcre2_match_context_free_8(self.mctx.as_ptr());
            ffi::pcre2_jit_stack_free_8(self.stack.as_ptr());
        }
    }
}

thread_local! {
    static THREAD: Thread = Thread::new();
}

/// The match data and context of one `preg_*` call: the thread's
/// preallocated match data when it is free and large enough, a block sized
/// for the pattern otherwise (`php_pcre_match_impl()`).
pub(super) struct Session {
    md: NonNull<ffi::MatchData>,
    shared: bool,
    mctx: *mut ffi::MatchContext,
}

impl Session {
    pub fn new(code: &Code, capture_count: u32) -> Session {
        THREAD.with(|t| {
            let jit = JIT_STACK.load(Ordering::Relaxed);
            if t.stack_assigned.get() != jit {
                let stack: *mut c_void = if jit { t.stack.as_ptr().cast() } else { ptr::null_mut() };
                // SAFETY: the thread's own context and stack.
                unsafe { ffi::pcre2_jit_stack_assign_8(t.mctx.as_ptr(), None, stack) };
                t.stack_assigned.set(jit);
            }
            let mctx = t.mctx.as_ptr();
            let shared = t.mdata.replace(ptr::null_mut());
            if let Some(md) = NonNull::new(shared) {
                if capture_count < PREALLOC_PAIRS {
                    return Session { md, shared: true, mctx };
                }
                t.mdata.set(shared);
            }
            // SAFETY: a valid pattern; the default allocator.
            let md = unsafe { ffi::pcre2_match_data_create_from_pattern_8(code.0.as_ptr(), ptr::null_mut()) };
            Session { md: NonNull::new(md).expect("PCRE2 match data"), shared: false, mctx }
        })
    }

    /// `pcre2_jit_match()` (`jit`) or `pcre2_match()`: the match count,
    /// `PCRE2_ERROR_NOMATCH` or an error.
    pub fn run(&mut self, code: &Code, subject: &[u8], start: usize, options: u32, jit: bool) -> c_int {
        if start > subject.len() {
            return ffi::ERROR_BADOFFSET;
        }
        let f = if jit { ffi::pcre2_jit_match_8 } else { ffi::pcre2_match_8 };
        // An empty slice's pointer is dangling, and PCRE2's (JIT) start-up
        // code may read around the subject; PHP's empty string is a real
        // buffer, and so is this one.
        let subject = if subject.is_empty() { &b"\0"[..0] } else { subject };
        // SAFETY: the subject pointer and length describe a live slice and
        // `start` is within it; the match data holds at least one pair; the
        // context belongs to this thread. pcre2_jit_match() is only used on
        // patterns with JIT code, as PHP's PREG_JIT flag guarantees.
        unsafe { f(code.0.as_ptr(), subject.as_ptr(), subject.len(), start, options, self.md.as_ptr(), self.mctx) }
    }

    /// The offset vector of the last match: `count` pairs.
    pub fn ovector(&self, count: usize) -> &[usize] {
        // SAFETY: the vector has `pcre2_get_ovector_count()` pairs and lives
        // as long as the match data.
        unsafe {
            let n = (ffi::pcre2_get_ovector_count_8(self.md.as_ptr()) as usize).min(count);
            std::slice::from_raw_parts(ffi::pcre2_get_ovector_pointer_8(self.md.as_ptr()), 2 * n)
        }
    }

    /// `pcre2_get_mark()` after a match of `code`, up to its NUL as PHP
    /// reads it.
    pub fn mark<'c>(&self, _code: &'c Code) -> Option<&'c [u8]> {
        // SAFETY: null, or a NUL-terminated name inside `code`, the pattern
        // that set it.
        unsafe {
            let p = ffi::pcre2_get_mark_8(self.md.as_ptr());
            if p.is_null() { None } else { Some(CStr::from_ptr(p.cast()).to_bytes()) }
        }
    }
}

impl Drop for Session {
    fn drop(&mut self) {
        let md = self.md.as_ptr();
        if self.shared && THREAD.try_with(|t| t.mdata.set(md)).is_ok() {
            return;
        }
        // SAFETY: a match data block this session owns.
        unsafe { ffi::pcre2_match_data_free_8(md) }
    }
}
