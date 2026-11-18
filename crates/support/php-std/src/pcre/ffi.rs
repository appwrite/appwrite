//! The PCRE2 10.44 API (`pcre2.h`, 8-bit library) that `ext/pcre` uses,
//! linked from the sources `build.rs` compiles.

use std::ffi::{c_int, c_void};

/// `pcre2_code`.
#[repr(C)]
pub struct Code {
    _private: [u8; 0],
}

/// `pcre2_match_data`.
#[repr(C)]
pub struct MatchData {
    _private: [u8; 0],
}

/// `pcre2_match_context`.
#[repr(C)]
pub struct MatchContext {
    _private: [u8; 0],
}

/// `pcre2_compile_context`.
#[repr(C)]
pub struct CompileContext {
    _private: [u8; 0],
}

/// `pcre2_jit_stack`.
#[repr(C)]
pub struct JitStack {
    _private: [u8; 0],
}

/// `pcre2_general_context` (always NULL here: PCRE2's default allocator).
#[repr(C)]
pub struct GeneralContext {
    _private: [u8; 0],
}

/// `pcre2_jit_callback`.
pub type JitCallback = Option<unsafe extern "C" fn(*mut c_void) -> *mut JitStack>;

pub const CASELESS: u32 = 0x0000_0008;
pub const DOLLAR_ENDONLY: u32 = 0x0000_0010;
pub const DOTALL: u32 = 0x0000_0020;
pub const DUPNAMES: u32 = 0x0000_0040;
pub const EXTENDED: u32 = 0x0000_0080;
pub const MULTILINE: u32 = 0x0000_0400;
pub const NO_AUTO_CAPTURE: u32 = 0x0000_2000;
pub const UCP: u32 = 0x0002_0000;
pub const UNGREEDY: u32 = 0x0004_0000;
pub const UTF: u32 = 0x0008_0000;
pub const NEVER_BACKSLASH_C: u32 = 0x0010_0000;
pub const NO_UTF_CHECK: u32 = 0x4000_0000;
pub const ANCHORED: u32 = 0x8000_0000;
pub const EXTRA_CASELESS_RESTRICT: u32 = 0x0000_0080;
pub const NOTEMPTY_ATSTART: u32 = 0x0000_0008;
pub const JIT_COMPLETE: u32 = 0x0000_0001;

pub const INFO_CAPTURECOUNT: u32 = 4;
pub const INFO_JITSIZE: u32 = 10;
pub const INFO_NAMECOUNT: u32 = 17;
pub const INFO_NAMEENTRYSIZE: u32 = 18;
pub const INFO_NAMETABLE: u32 = 19;

pub const ERROR_BACKSLASH_C_CALLER_DISABLED: c_int = 183;
pub const ERROR_NOMATCH: c_int = -1;
pub const ERROR_UTF8_ERR21: c_int = -23;
pub const ERROR_UTF8_ERR1: c_int = -3;
pub const ERROR_BADOFFSET: c_int = -33;
pub const ERROR_BADUTFOFFSET: c_int = -36;
pub const ERROR_JIT_STACKLIMIT: c_int = -46;
pub const ERROR_MATCHLIMIT: c_int = -47;
pub const ERROR_NOMEMORY: c_int = -48;
pub const ERROR_DEPTHLIMIT: c_int = -53;

/// `PCRE2_UNSET`: an unset capture offset.
pub const UNSET: usize = usize::MAX;

unsafe extern "C" {
    pub fn pcre2_compile_8(
        pattern: *const u8,
        length: usize,
        options: u32,
        errorcode: *mut c_int,
        erroroffset: *mut usize,
        ccontext: *mut CompileContext,
    ) -> *mut Code;
    pub fn pcre2_code_free_8(code: *mut Code);
    pub fn pcre2_jit_compile_8(code: *mut Code, options: u32) -> c_int;
    pub fn pcre2_pattern_info_8(code: *const Code, what: u32, out: *mut c_void) -> c_int;
    pub fn pcre2_get_error_message_8(errorcode: c_int, buffer: *mut u8, length: usize) -> c_int;

    pub fn pcre2_compile_context_create_8(gcontext: *mut GeneralContext) -> *mut CompileContext;
    pub fn pcre2_compile_context_free_8(ccontext: *mut CompileContext);
    pub fn pcre2_set_compile_extra_options_8(ccontext: *mut CompileContext, options: u32) -> c_int;

    pub fn pcre2_match_context_create_8(gcontext: *mut GeneralContext) -> *mut MatchContext;
    pub fn pcre2_set_match_limit_8(mcontext: *mut MatchContext, limit: u32) -> c_int;
    pub fn pcre2_set_depth_limit_8(mcontext: *mut MatchContext, limit: u32) -> c_int;
    pub fn pcre2_match_context_free_8(mcontext: *mut MatchContext);

    pub fn pcre2_jit_stack_create_8(start: usize, max: usize, gcontext: *mut GeneralContext) -> *mut JitStack;
    pub fn pcre2_jit_stack_assign_8(mcontext: *mut MatchContext, callback: JitCallback, data: *mut c_void);
    pub fn pcre2_jit_stack_free_8(stack: *mut JitStack);

    pub fn pcre2_match_data_create_8(pairs: u32, gcontext: *mut GeneralContext) -> *mut MatchData;
    pub fn pcre2_match_data_create_from_pattern_8(code: *const Code, gcontext: *mut GeneralContext) -> *mut MatchData;
    pub fn pcre2_match_data_free_8(match_data: *mut MatchData);
    pub fn pcre2_get_ovector_pointer_8(match_data: *mut MatchData) -> *mut usize;
    pub fn pcre2_get_ovector_count_8(match_data: *mut MatchData) -> u32;
    pub fn pcre2_get_mark_8(match_data: *mut MatchData) -> *const u8;

    pub fn pcre2_match_8(
        code: *const Code,
        subject: *const u8,
        length: usize,
        start: usize,
        options: u32,
        match_data: *mut MatchData,
        mcontext: *mut MatchContext,
    ) -> c_int;
    pub fn pcre2_jit_match_8(
        code: *const Code,
        subject: *const u8,
        length: usize,
        start: usize,
        options: u32,
        match_data: *mut MatchData,
        mcontext: *mut MatchContext,
    ) -> c_int;

    /// `pcre2/php_std_glue.c`: whether `c` is in `\pL` or `\pN`.
    pub fn php_std_pcre2_is_letter_or_number(c: u32) -> c_int;
}
