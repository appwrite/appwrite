//! PHP's PCRE functions: `preg_match`, `preg_match_all`, `preg_replace`,
//! `preg_replace_callback`, `preg_split`, `preg_quote` and `preg_grep`, on
//! PCRE2 10.44 with its JIT, the library and version PHP 8.5 bundles.
//!
//! | PHP | Rust |
//! |---|---|
//! | `preg_match($p, $s, $m, $flags, $offset)` | [`preg_match`] |
//! | `preg_match($p, $s)` | [`preg_match_bare`] |
//! | `preg_match_all($p, $s, $m, $flags, $offset)` | [`preg_match_all`] |
//! | `preg_replace($p, $r, $s, $limit, $count)` | [`preg_replace`] |
//! | `preg_replace_callback($p, $fn, $s, $limit, $count, $flags)` | [`preg_replace_callback`] |
//! | `preg_split($p, $s, $limit, $flags)` | [`preg_split`] |
//! | `preg_quote($s, $delimiter)` | [`preg_quote`] |
//! | `preg_grep($p, $array, $flags)` | [`preg_grep`] |
//! | `preg_match($p, $s, $m) === 1` on a pattern compiled once | [`Regex::compiled`], [`Regex::captures`], [`Regex::is_match`] |
//! | `preg_last_error()` / `preg_last_error_msg()` | [`PregError`] in every result |
//!
//! User patterns must behave exactly as in PHP, down to where
//! `pcre.backtrack_limit` and the JIT stack run out, and only PCRE2 itself
//! does that: `build.rs` compiles the sources PHP 8.5 bundles
//! (`ext/pcre/pcre2lib`), and this module calls them as `ext/pcre/php_pcre.c`
//! does. Patterns are delimited and take modifiers exactly as
//! `pcre_get_compiled_regex_cache()` parses them (`/i m n s x A D r S X U u
//! J`), then go through PHP's per-process cache of compiled patterns, which
//! JIT-compiles them while `pcre.jit` is on (a JIT failure warns and turns it
//! off for the process). Matches run with `pcre.backtrack_limit` and
//! `pcre.recursion_limit` (their defaults) as the match and depth limits and
//! the 32 KiB–192 KiB JIT stack, through `pcre2_jit_match()` when no UTF-8
//! check is needed and `pcre2_match()` otherwise, and an empty match is
//! retried with `PCRE2_NOTEMPTY_ATSTART | PCRE2_ANCHORED`, as PHP does.
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `pcre.*`).

use std::collections::{HashMap, VecDeque};
use std::fmt;
use std::sync::atomic::{AtomicBool, AtomicU8, AtomicU64, Ordering};
use std::sync::{Arc, Mutex, OnceLock};

mod compiled;
mod engine;
mod ffi;

use engine::{Code, Session};
use ffi::UNSET;

pub use compiled::Captures;
pub(crate) use engine::is_letter_or_number;

/// The Unicode version of PCRE2's tables (PHP's "PCRE Unicode Version").
pub const UNICODE_VERSION: &str = "15.0.0";

/// Orders `preg_match_all()` results by group, as PHP's `PREG_PATTERN_ORDER`.
pub const PREG_PATTERN_ORDER: i64 = 1;
/// Orders `preg_match_all()` results by match, as PHP's `PREG_SET_ORDER`.
pub const PREG_SET_ORDER: i64 = 2;
/// Returns each match with its byte offset, as PHP's `PREG_OFFSET_CAPTURE`.
pub const PREG_OFFSET_CAPTURE: i64 = 1 << 8;
/// Reports unmatched groups as `null`, as PHP's `PREG_UNMATCHED_AS_NULL`.
pub const PREG_UNMATCHED_AS_NULL: i64 = 1 << 9;
/// Leaves empty pieces out of `preg_split()`, as PHP's `PREG_SPLIT_NO_EMPTY`.
pub const PREG_SPLIT_NO_EMPTY: i64 = 1;
/// Returns the delimiter's captured groups from `preg_split()` too, as PHP's `PREG_SPLIT_DELIM_CAPTURE`.
pub const PREG_SPLIT_DELIM_CAPTURE: i64 = 2;
/// Returns each `preg_split()` piece with its byte offset, as PHP's `PREG_SPLIT_OFFSET_CAPTURE`.
pub const PREG_SPLIT_OFFSET_CAPTURE: i64 = 4;
/// Returns the entries that do not match from `preg_grep()`, as PHP's `PREG_GREP_INVERT`.
pub const PREG_GREP_INVERT: i64 = 1;

/// A PHP array key.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub enum Key {
    /// An integer key.
    Int(i64),
    /// A string key, as bytes.
    Str(Vec<u8>),
}

/// The PHP values the `preg_*` functions build.
#[derive(Debug, Clone, PartialEq)]
pub enum Value {
    /// PHP `null`.
    Null,
    /// A PHP `bool`.
    Bool(bool),
    /// A PHP `int`.
    Int(i64),
    /// A PHP `string`, as bytes.
    Str(Vec<u8>),
    /// A PHP array.
    Array(Array),
}

/// An ordered PHP array.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Array {
    entries: Vec<(Key, Value)>,
    next: i64,
}

impl Array {
    /// An empty array.
    pub fn new() -> Self {
        Self::default()
    }

    /// The key-value pairs, in order.
    pub fn entries(&self) -> &[(Key, Value)] {
        &self.entries
    }

    /// The number of elements.
    pub fn len(&self) -> usize {
        self.entries.len()
    }

    /// Whether the array has no elements.
    pub fn is_empty(&self) -> bool {
        self.entries.is_empty()
    }

    /// Whether the keys are `0, 1, 2...` in order (`array_is_list`).
    pub fn is_list(&self) -> bool {
        self.entries.iter().enumerate().all(|(i, (k, _))| *k == Key::Int(i as i64))
    }

    /// The value at `key`, if any.
    pub fn get(&self, key: &Key) -> Option<&Value> {
        self.entries.iter().find(|(k, _)| k == key).map(|(_, v)| v)
    }

    /// `$array[] = $value`.
    pub fn push(&mut self, value: Value) {
        let k = self.next;
        self.entries.push((Key::Int(k), value));
        self.next = k + 1;
    }

    /// `$array[$key] = $value` (`zend_hash_update`): an existing key keeps
    /// its position.
    pub fn update(&mut self, key: Key, value: Value) {
        if let Some(slot) = self.entries.iter_mut().find(|(k, _)| *k == key) {
            slot.1 = value;
            return;
        }
        self.insert_new(key, value);
    }

    /// `zend_hash_add`: only when the key is absent.
    pub fn add(&mut self, key: Key, value: Value) -> bool {
        if self.entries.iter().any(|(k, _)| *k == key) {
            return false;
        }
        self.insert_new(key, value);
        true
    }

    fn insert_new(&mut self, key: Key, value: Value) {
        if let Key::Int(i) = key
            && i >= self.next
        {
            self.next = i.saturating_add(1);
        }
        self.entries.push((key, value));
    }
}

/// `preg_last_error()`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PregError {
    /// `PREG_NO_ERROR`.
    None,
    /// `PREG_INTERNAL_ERROR`.
    Internal,
    /// `PREG_BACKTRACK_LIMIT_ERROR`: `pcre.backtrack_limit` was reached.
    BacktrackLimit,
    /// `PREG_RECURSION_LIMIT_ERROR`: `pcre.recursion_limit` was reached.
    RecursionLimit,
    /// `PREG_BAD_UTF8_ERROR`: the subject is not valid UTF-8.
    BadUtf8,
    /// `PREG_BAD_UTF8_OFFSET_ERROR`: the offset is inside a UTF-8 character.
    BadUtf8Offset,
    /// `PREG_JIT_STACKLIMIT_ERROR`: the JIT stack ran out.
    JitStackLimit,
}

impl PregError {
    /// The value of `preg_last_error()` (`PREG_*_ERROR`).
    pub fn code(self) -> i64 {
        self as i64
    }

    /// `preg_last_error_msg()`.
    pub fn message(self) -> &'static str {
        match self {
            PregError::None => "No error",
            PregError::Internal => "Internal error",
            PregError::BacktrackLimit => "Backtrack limit exhausted",
            PregError::RecursionLimit => "Recursion limit exhausted",
            PregError::BadUtf8 => "Malformed UTF-8 characters, possibly incorrectly encoded",
            PregError::BadUtf8Offset => "The offset did not correspond to the beginning of a valid UTF-8 code point",
            PregError::JitStackLimit => "JIT stack limit exhausted",
        }
    }

    /// `pcre_handle_exec_error()`.
    fn from_pcre(code: i32) -> Self {
        match code {
            ffi::ERROR_MATCHLIMIT => PregError::BacktrackLimit,
            ffi::ERROR_DEPTHLIMIT => PregError::RecursionLimit,
            ffi::ERROR_BADUTFOFFSET => PregError::BadUtf8Offset,
            ffi::ERROR_JIT_STACKLIMIT => PregError::JitStackLimit,
            ffi::ERROR_UTF8_ERR21..=ffi::ERROR_UTF8_ERR1 => PregError::BadUtf8,
            _ => PregError::Internal,
        }
    }

    fn from_u8(v: u8) -> Self {
        match v {
            1 => PregError::Internal,
            2 => PregError::BacktrackLimit,
            3 => PregError::RecursionLimit,
            4 => PregError::BadUtf8,
            5 => PregError::BadUtf8Offset,
            6 => PregError::JitStackLimit,
            _ => PregError::None,
        }
    }
}

/// A `ValueError`/`TypeError` thrown for an invalid argument.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ArgumentError {
    class: &'static str,
    message: String,
}

impl ArgumentError {
    fn value(message: String) -> Self {
        ArgumentError { class: "ValueError", message }
    }

    /// The PHP exception class.
    pub fn php_class(&self) -> &'static str {
        self.class
    }
}

impl fmt::Display for ArgumentError {
    /// Writes the message.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.message)
    }
}

impl std::error::Error for ArgumentError {}

/// What a `preg_*` call returned, with `preg_last_error()` and the last
/// warning it emitted (`"preg_match(): Compilation failed: ..."`).
#[derive(Debug, Clone, PartialEq)]
pub struct Preg<T> {
    /// The value the function returned.
    pub value: T,
    /// What `preg_last_error()` reports after the call.
    pub error: PregError,
    /// The last warning emitted, if any.
    pub warning: Option<Vec<u8>>,
}

/// A pattern that `pcre_get_compiled_regex_cache()` rejected: the warning
/// text, without the function name.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CompileFailure {
    /// The warning text.
    pub message: Vec<u8>,
}

/// A compiled PHP regular expression (delimiters and modifiers included):
/// a `pcre_cache_entry`.
pub struct Regex {
    code: Code,
    /// `PREG_JIT`: the pattern has JIT code.
    jit: bool,
    /// The `u` modifier (`compile_options & PCRE2_UTF`): PHP decides on
    /// UTF-8 checks and how far to step after an empty match from it, not
    /// from a `(*UTF)` in the pattern.
    php_utf: bool,
    capture_count: u32,
    /// Group number to name (`subpats_table`), when the pattern has names.
    names: Option<Vec<Option<Vec<u8>>>>,
}

impl fmt::Debug for Regex {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Regex")
            .field("jit", &self.jit)
            .field("utf", &self.php_utf)
            .field("capture_count", &self.capture_count)
            .finish_non_exhaustive()
    }
}

/// The ways PHP runs a pattern.
#[derive(Clone, Copy, PartialEq, Eq)]
enum Call {
    /// `pcre2_jit_match(..., PCRE2_NO_UTF_CHECK)`, or for a pattern without
    /// JIT code `pcre2_match(..., PCRE2_NO_UTF_CHECK)`.
    Fast,
    /// `pcre2_match(..., 0)` for a `/u` pattern and a subject not known to
    /// be valid UTF-8: the UTF-8 check, then the JIT.
    Checked,
    /// `pcre2_match(..., PCRE2_NO_UTF_CHECK | PCRE2_NOTEMPTY_ATSTART |
    /// PCRE2_ANCHORED)` after an empty match.
    Retry,
}

impl Regex {
    /// Parses the delimiters and modifiers of a PHP regex and compiles it
    /// (`pcre_get_compiled_regex_cache()`), with JIT code while `pcre.jit`
    /// is on. Unlike [`Regex::compiled`], it bypasses PHP's cache, and a
    /// JIT failure leaves the pattern interpreted without a warning.
    pub fn new(regex: &[u8]) -> Result<Regex, CompileFailure> {
        let mut re = Self::compile(regex)?;
        if globals().jit.load(Ordering::Relaxed) {
            re.jit = re.code.jit_compile().unwrap_or(false);
        }
        Ok(re)
    }

    /// The delimiters, the modifiers and `pcre2_compile()`, without the JIT.
    fn compile(regex: &[u8]) -> Result<Regex, CompileFailure> {
        let fail = |m: String| Err(CompileFailure { message: m.into_bytes() });
        let mut p = 0;
        while p < regex.len() && is_c_space(regex[p]) {
            p += 1;
        }
        if p >= regex.len() {
            return fail("Empty regular expression".into());
        }
        let delimiter = regex[p];
        p += 1;
        if delimiter.is_ascii_alphanumeric() || delimiter == b'\\' || delimiter == 0 {
            return fail("Delimiter must not be alphanumeric, backslash, or NUL byte".into());
        }
        let start_delimiter = delimiter;
        let end_delimiter = match delimiter {
            b'(' => b')',
            b'[' => b']',
            b'{' => b'}',
            b'<' => b'>',
            d => d,
        };
        let mut pp = p;
        if start_delimiter == end_delimiter {
            while pp < regex.len() {
                if regex[pp] == b'\\' && pp + 1 < regex.len() {
                    pp += 1;
                } else if regex[pp] == end_delimiter {
                    break;
                }
                pp += 1;
            }
        } else {
            let mut brackets = 1;
            while pp < regex.len() {
                if regex[pp] == b'\\' && pp + 1 < regex.len() {
                    pp += 1;
                } else if regex[pp] == end_delimiter {
                    brackets -= 1;
                    if brackets <= 0 {
                        break;
                    }
                } else if regex[pp] == start_delimiter {
                    brackets += 1;
                }
                pp += 1;
            }
        }
        if pp >= regex.len() {
            let mut m = if start_delimiter == end_delimiter {
                b"No ending delimiter '".to_vec()
            } else {
                b"No ending matching delimiter '".to_vec()
            };
            m.push(end_delimiter);
            m.extend_from_slice(b"' found");
            return Err(CompileFailure { message: m });
        }
        let pattern = &regex[p..pp];
        let mut coptions = 0u32;
        let mut eoptions = 0u32;
        for &m in &regex[pp + 1..] {
            match m {
                b'i' => coptions |= ffi::CASELESS,
                b'm' => coptions |= ffi::MULTILINE,
                b'n' => coptions |= ffi::NO_AUTO_CAPTURE,
                b's' => coptions |= ffi::DOTALL,
                b'x' => coptions |= ffi::EXTENDED,
                b'A' => coptions |= ffi::ANCHORED,
                b'D' => coptions |= ffi::DOLLAR_ENDONLY,
                b'r' => eoptions |= ffi::EXTRA_CASELESS_RESTRICT,
                b'S' | b'X' | b' ' | b'\n' | b'\r' => {}
                b'U' => coptions |= ffi::UNGREEDY,
                b'u' => coptions |= ffi::UTF | ffi::UCP | ffi::NEVER_BACKSLASH_C,
                b'J' => coptions |= ffi::DUPNAMES,
                0 => return fail("NUL byte is not a valid modifier".into()),
                c => {
                    let mut msg = b"Unknown modifier '".to_vec();
                    msg.push(c);
                    msg.push(b'\'');
                    return Err(CompileFailure { message: msg });
                }
            }
        }
        let code = Code::compile(pattern, coptions, eoptions).map_err(|e| {
            let mut m = b"Compilation failed: ".to_vec();
            if e.code == ffi::ERROR_BACKSLASH_C_CALLER_DISABLED {
                m.extend_from_slice(b"using \\C is incompatible with the 'u' modifier");
            } else {
                m.extend_from_slice(&engine::error_message(e.code));
            }
            m.extend_from_slice(format!(" at offset {}", e.offset).as_bytes());
            CompileFailure { message: m }
        })?;
        let capture_count = code.capture_count();
        let named = code.names();
        let names = (!named.is_empty()).then(|| {
            let mut names = vec![None; capture_count as usize + 1];
            for (group, name) in named {
                if let Some(slot) = names.get_mut(group) {
                    *slot = Some(name);
                }
            }
            names
        });
        Ok(Regex { code, jit: false, php_utf: coptions & ffi::UTF != 0, capture_count, names })
    }

    /// The number of capturing groups.
    pub fn capture_count(&self) -> u32 {
        self.capture_count
    }

    /// The match data for the calls of one `preg_*` function.
    fn session(&self) -> Session {
        Session::new(&self.code, self.capture_count)
    }

    /// One `pcre2_match()`/`pcre2_jit_match()` call as PHP makes it: the
    /// match count, `None` for no match, or the PCRE2 error.
    fn call(&self, session: &mut Session, subject: &[u8], start: usize, call: Call) -> Result<Option<usize>, i32> {
        let (jit, options) = match call {
            Call::Fast => (self.jit, ffi::NO_UTF_CHECK),
            Call::Checked => (false, 0),
            Call::Retry => (false, ffi::NO_UTF_CHECK | ffi::NOTEMPTY_ATSTART | ffi::ANCHORED),
        };
        match session.run(&self.code, subject, start, options, jit) {
            // "Matched, but too many substrings" cannot happen: the match
            // data always has room for every group.
            0 => Ok(Some(self.capture_count as usize + 1)),
            n if n > 0 => Ok(Some(n as usize)),
            ffi::ERROR_NOMATCH => Ok(None),
            e => Err(e),
        }
    }

    /// The first call of a `preg_*` function: `/u` patterns check the
    /// subject first. `known_valid`: PHP already knows the subject string is
    /// valid UTF-8 (`is_known_valid_utf8()`).
    fn first_call(&self, known_valid: bool) -> Call {
        if self.php_utf && !known_valid { Call::Checked } else { Call::Fast }
    }

    /// `calculate_unit_length()`.
    fn unit_length(&self, subject: &[u8], at: usize) -> usize {
        if self.php_utf {
            let mut end = at + 1;
            while end < subject.len() && subject[end] & 0xc0 == 0x80 {
                end += 1;
            }
            end - at
        } else {
            1
        }
    }

    /// `populate_subpat_array()` for the last match of `session`, which
    /// set `count` groups.
    fn populate_subpat_array(&self, out: &mut Array, subject: &[u8], session: &Session, count: usize, flags: i64) {
        let offset_capture = flags & PREG_OFFSET_CAPTURE != 0;
        let unmatched_as_null = flags & PREG_UNMATCHED_AS_NULL != 0;
        let num_subpats = self.capture_count as usize + 1;
        let ov = session.ovector(count);
        let count = ov.len() / 2;
        // Room for every group (named ones twice), as PHP sizes the array.
        out.entries.reserve(if self.names.is_some() { 2 * num_subpats } else { num_subpats });
        if let Some(names) = &self.names {
            if offset_capture {
                for i in 0..count {
                    add_offset_pair(out, subject, ov[2 * i], ov[2 * i + 1], names[i].as_deref(), unmatched_as_null);
                }
                if unmatched_as_null {
                    for name in &names[count..num_subpats] {
                        add_offset_pair(out, subject, UNSET, UNSET, name.as_deref(), true);
                    }
                }
            } else {
                for i in 0..count {
                    let val = match_value(subject, ov[2 * i], ov[2 * i + 1], unmatched_as_null);
                    if let Some(name) = &names[i] {
                        add_named(out, name, val.clone(), ov[2 * i] == UNSET);
                    }
                    out.push(val);
                }
                if unmatched_as_null {
                    for name in &names[count..num_subpats] {
                        if let Some(name) = name {
                            out.add(Key::Str(name.clone()), Value::Null);
                        }
                        out.push(Value::Null);
                    }
                }
            }
        } else if offset_capture {
            for i in 0..count {
                add_offset_pair(out, subject, ov[2 * i], ov[2 * i + 1], None, unmatched_as_null);
            }
            if unmatched_as_null {
                for _ in count..num_subpats {
                    add_offset_pair(out, subject, UNSET, UNSET, None, true);
                }
            }
        } else {
            for i in 0..count {
                out.push(match_value(subject, ov[2 * i], ov[2 * i + 1], unmatched_as_null));
            }
            if unmatched_as_null {
                for _ in count..num_subpats {
                    out.push(Value::Null);
                }
            }
        }
        if let Some(mark) = session.mark(&self.code) {
            out.add(Key::Str(b"MARK".to_vec()), Value::Str(mark.to_vec()));
        }
    }
}

/// `PCRE_CACHE_SIZE`.
const CACHE_SIZE: usize = 4096;

/// PHP's per-process PCRE state: `pcre.jit` (switched off for good when a
/// pattern fails to JIT-compile), `PCRE_G(error_code)` and the
/// compiled-pattern cache, which keeps how each pattern was compiled.
struct Globals {
    jit: AtomicBool,
    /// What `preg_last_error()` returns. Calls that never reach a pattern
    /// (an empty array of patterns or subjects) leave it as the previous
    /// call set it.
    error: AtomicU8,
    cache: Mutex<Cache>,
    /// How many times the cache has evicted entries.
    evictions: AtomicU64,
}

/// A cached regex, delimiters and modifiers included: the cache's key.
type Source = Arc<[u8]>;

#[derive(Default)]
struct Cache {
    order: VecDeque<Source>,
    entries: HashMap<Source, Arc<Regex>>,
}

fn globals() -> &'static Globals {
    static GLOBALS: OnceLock<Globals> = OnceLock::new();
    GLOBALS.get_or_init(|| Globals {
        jit: AtomicBool::new(true),
        error: AtomicU8::new(0),
        cache: Mutex::new(Cache::default()),
        evictions: AtomicU64::new(0),
    })
}

/// Sets `preg_last_error()`, returning the value.
fn set_last_error(e: PregError) -> PregError {
    globals().error.store(e as u8, Ordering::Relaxed);
    e
}

/// `preg_last_error()`: the error of the last `preg_*` call that set one.
pub fn preg_last_error() -> PregError {
    PregError::from_u8(globals().error.load(Ordering::Relaxed))
}

/// `ini_set('pcre.jit', ...)`: switches the JIT on or off for patterns
/// compiled from now on (cached patterns keep how they were compiled), and
/// gives JIT code the 192 KiB JIT stack only while it is on.
pub fn set_jit(enabled: bool) {
    globals().jit.store(enabled, Ordering::Relaxed);
    engine::assign_jit_stack(enabled);
}

/// A compiled pattern and the warning its JIT compilation emitted.
type Cached = (Arc<Regex>, Option<Vec<u8>>);

/// `pcre_get_compiled_regex_cache()`: the cached pattern, compiling it on
/// a miss. `Err` carries the warning of a pattern that does not compile;
/// `Ok` may carry the warning of a pattern the JIT could not compile, which
/// switches the JIT off for the rest of the process, as PHP does.
fn cached(func: &str, regex: &[u8]) -> Result<Cached, Vec<u8>> {
    let g = globals();
    if let Some(re) = recent(g, regex) {
        return Ok((re, None));
    }
    let mut cache = match g.cache.lock() {
        Ok(c) => c,
        Err(poisoned) => poisoned.into_inner(),
    };
    if let Some((key, re)) = cache.entries.get_key_value(regex) {
        remember(g, key, re);
        return Ok((re.clone(), None));
    }
    let mut re = match Regex::compile(regex) {
        Ok(re) => re,
        Err(e) => {
            set_last_error(PregError::Internal);
            return Err(warn(func, e));
        }
    };
    let mut warning = None;
    if g.jit.load(Ordering::Relaxed) {
        match re.code.jit_compile() {
            Ok(jit) => re.jit = jit,
            Err(ffi::ERROR_NOMEMORY) => {
                warning = Some(
                    format!(
                        "{func}(): Allocation of JIT memory failed, PCRE JIT will be disabled. This is likely caused \
                         by security restrictions. Either grant PHP permission to allocate executable memory, or set \
                         pcre.jit=0"
                    )
                    .into_bytes(),
                );
                g.jit.store(false, Ordering::Relaxed);
            }
            Err(e) => {
                let mut w = format!("{func}(): JIT compilation failed: ").into_bytes();
                w.extend_from_slice(&engine::error_message(e));
                warning = Some(w);
                set_last_error(PregError::Internal);
            }
        }
    }
    if cache.entries.len() == CACHE_SIZE {
        g.evictions.fetch_add(1, Ordering::Release);
        for _ in 0..CACHE_SIZE / 8 {
            if let Some(k) = cache.order.pop_front() {
                cache.entries.remove(&k);
            }
        }
    }
    let re = Arc::new(re);
    let key: Source = regex.into();
    cache.order.push_back(key.clone());
    cache.entries.insert(key.clone(), re.clone());
    remember(g, &key, &re);
    Ok((re, warning))
}

/// How many cache entries each thread remembers.
const RECENT: usize = 16;

/// The cache entries a thread used last, so that a hit takes neither the
/// cache's lock nor a hash of the pattern. They stand for the entries of
/// the shared cache, which never change while they are in it: the thread
/// forgets them all when the shared cache evicts.
struct Recent {
    evictions: u64,
    next: usize,
    entries: [Option<(Source, Arc<Regex>)>; RECENT],
}

thread_local! {
    static RECENT_ENTRIES: std::cell::RefCell<Recent> =
        const { std::cell::RefCell::new(Recent { evictions: 0, next: 0, entries: [const { None }; RECENT] }) };
}

/// The thread's remembered entry for `regex`, if the shared cache still
/// holds it.
fn recent(g: &Globals, regex: &[u8]) -> Option<Arc<Regex>> {
    RECENT_ENTRIES.with_borrow_mut(|r| {
        let evictions = g.evictions.load(Ordering::Acquire);
        if r.evictions != evictions {
            *r = Recent { evictions, next: 0, entries: [const { None }; RECENT] };
            return None;
        }
        r.entries.iter().flatten().find(|(k, _)| **k == *regex).map(|(_, re)| re.clone())
    })
}

/// Remembers an entry of the shared cache (called with its lock held).
fn remember(g: &Globals, key: &Source, re: &Arc<Regex>) {
    RECENT_ENTRIES.with_borrow_mut(|r| {
        let evictions = g.evictions.load(Ordering::Acquire);
        if r.evictions != evictions {
            *r = Recent { evictions, next: 0, entries: [const { None }; RECENT] };
        }
        let slot = r.next;
        r.entries[slot] = Some((key.clone(), re.clone()));
        r.next = (slot + 1) % RECENT;
    })
}

/// What `ext/filter` does with a regular expression:
/// `pcre_get_compiled_regex()` (PHP's cache), then `pcre2_match(re,
/// subject, len, 0, 0, ...)`. The result is `None` when the regex does not
/// compile, and comes with the warning of a compile or JIT failure.
pub(crate) fn engine_match(func: &str, regex: &[u8], subject: &[u8]) -> (Option<bool>, Option<Vec<u8>>) {
    match cached(func, regex) {
        Err(w) => (None, Some(w)),
        Ok((re, w)) => {
            let mut session = re.session();
            (Some(matches!(re.call(&mut session, subject, 0, Call::Checked), Ok(Some(_)))), w)
        }
    }
}

/// `isspace()` in the C locale.
fn is_c_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0b | 0x0c | b'\r')
}

fn match_value(subject: &[u8], start: usize, end: usize, unmatched_as_null: bool) -> Value {
    if start == UNSET {
        if unmatched_as_null { Value::Null } else { Value::Str(Vec::new()) }
    } else {
        Value::Str(subject[start..end].to_vec())
    }
}

fn pair(value: Value, offset: i64) -> Value {
    let mut a = Array::new();
    a.push(value);
    a.push(Value::Int(offset));
    Value::Array(a)
}

/// `add_named()`: a matched group overwrites, an unmatched one only fills
/// a missing name.
fn add_named(out: &mut Array, name: &[u8], val: Value, unmatched: bool) {
    if !unmatched {
        out.update(Key::Str(name.to_vec()), val);
    } else {
        out.add(Key::Str(name.to_vec()), val);
    }
}

/// `add_offset_pair()`.
fn add_offset_pair(
    out: &mut Array,
    subject: &[u8],
    start: usize,
    end: usize,
    name: Option<&[u8]>,
    unmatched_as_null: bool,
) {
    let p = if start == UNSET {
        pair(if unmatched_as_null { Value::Null } else { Value::Str(Vec::new()) }, -1)
    } else {
        pair(Value::Str(subject[start..end].to_vec()), start as i64)
    };
    if let Some(name) = name {
        add_named(out, name, p.clone(), start == UNSET);
    }
    out.push(p);
}

fn warn(func: &str, failure: CompileFailure) -> Vec<u8> {
    let mut w = format!("{func}(): ").into_bytes();
    w.extend_from_slice(&failure.message);
    w
}

/// The result of [`preg_match`] and [`preg_match_all`]: the return value
/// (`Int` or `Bool(false)`) and `$matches` (`None` when the pattern did not
/// compile, which leaves `$matches` untouched).
#[derive(Debug, Clone, PartialEq)]
pub struct MatchResult {
    /// The return value: the match count, or `false`.
    pub result: Value,
    /// `$matches`, or `None` when the pattern did not compile.
    pub matches: Option<Array>,
}

/// `preg_match($pattern, $subject, $matches, $flags, $offset)`.
pub fn preg_match(pattern: &[u8], subject: &[u8], flags: i64, offset: i64) -> Result<Preg<MatchResult>, ArgumentError> {
    match_impl("preg_match", pattern, subject, false, true, flags, offset)
}

/// `preg_match($pattern, $subject)`: `preg_match()` without `$matches`.
/// PHP then treats an empty match like the next match of
/// `preg_match_all()`: it retries at the same position with
/// `PCRE2_NOTEMPTY_ATSTART | PCRE2_ANCHORED`, and an error there (a limit)
/// makes the result `false`. Passing `flags:` or `offset:` by name gives
/// `preg_match()` a `$matches` to fill, so that is [`preg_match`].
pub fn preg_match_bare(pattern: &[u8], subject: &[u8]) -> Result<Preg<Value>, ArgumentError> {
    match_impl("preg_match", pattern, subject, false, false, 0, 0).map(|p| Preg {
        value: p.value.result,
        error: p.error,
        warning: p.warning,
    })
}

/// `preg_match_all($pattern, $subject, $matches, $flags, $offset)`.
pub fn preg_match_all(
    pattern: &[u8],
    subject: &[u8],
    flags: i64,
    offset: i64,
) -> Result<Preg<MatchResult>, ArgumentError> {
    match_impl("preg_match_all", pattern, subject, true, true, flags, offset)
}

/// `php_do_pcre_match()` and `php_pcre_match_impl()`.
fn match_impl(
    func: &str,
    pattern: &[u8],
    subject: &[u8],
    global: bool,
    subpats: bool,
    flags: i64,
    start_offset: i64,
) -> Result<Preg<MatchResult>, ArgumentError> {
    let (re, jit_warning) = match cached(func, pattern) {
        Ok(r) => r,
        Err(w) => {
            return Ok(Preg {
                value: MatchResult { result: Value::Bool(false), matches: None },
                error: PregError::Internal,
                warning: Some(w),
            });
        }
    };
    if start_offset == i64::MIN {
        return Err(ArgumentError::value(format!("{func}(): Argument #5 ($offset) must be greater than {}", i64::MIN)));
    }
    let mut matches = Array::new();
    let mut subpats_order = if global { PREG_PATTERN_ORDER } else { 0 };
    let mut offset_capture = false;
    let mut unmatched_as_null = false;
    if flags != 0 {
        offset_capture = flags & PREG_OFFSET_CAPTURE != 0;
        unmatched_as_null = flags & PREG_UNMATCHED_AS_NULL != 0;
        if flags & 0xff != 0 {
            subpats_order = flags & 0xff;
            if (global && !(PREG_PATTERN_ORDER..=PREG_SET_ORDER).contains(&subpats_order))
                || (!global && subpats_order != 0)
            {
                return Err(ArgumentError::value(format!("{func}(): Argument #4 ($flags) must be a PREG_* constant")));
            }
        }
    }
    let len = subject.len();
    let mut start_offset2 = if start_offset < 0 {
        if start_offset.unsigned_abs() <= len as u64 { len - start_offset.unsigned_abs() as usize } else { 0 }
    } else {
        start_offset as usize
    };
    let done = |result: Value, matches: Array, error: PregError, warning: Option<Vec<u8>>| {
        Ok(Preg {
            value: MatchResult { result, matches: Some(matches) },
            error,
            warning: warning.or(jit_warning.clone()),
        })
    };
    if start_offset2 > len {
        return done(Value::Bool(false), matches, set_last_error(PregError::Internal), None);
    }
    let num_subpats = re.capture_count as usize + 1;
    let mut match_sets: Option<Vec<Array>> =
        if global && subpats_order == PREG_PATTERN_ORDER { Some(vec![Array::new(); num_subpats]) } else { None };
    let mut marks: Option<Array> = None;
    let mut error = set_last_error(PregError::None);
    let mut matched: i64 = 0;
    let mut session = re.session();
    // PHP's empty string is flagged as valid UTF-8; other subjects reach
    // these functions without the flag.
    let first = re.first_call(subject.is_empty());
    let mut pending = Some(re.call(&mut session, subject, start_offset2, first));
    loop {
        let count = pending.take().unwrap_or_else(|| re.call(&mut session, subject, start_offset2, Call::Fast));
        match count {
            Ok(Some(count)) => {
                matched += 1;
                let ov = session.ovector(count);
                let (ov0, ov1) = (ov[0], ov[1]);
                // Without `$matches` nothing is recorded, and a single
                // match goes on to the empty-match retry below.
                if subpats {
                    if ov1 < ov0 {
                        let warning = Some(format!("{func}(): Get subpatterns list failed").into_bytes());
                        return done(Value::Bool(false), matches, error, warning);
                    }
                    if global {
                        if let Some(sets) = match_sets.as_mut() {
                            let set_count = ov.len() / 2;
                            for (i, set) in sets.iter_mut().enumerate().take(set_count) {
                                if offset_capture {
                                    add_offset_pair(set, subject, ov[2 * i], ov[2 * i + 1], None, unmatched_as_null);
                                } else {
                                    set.push(match_value(subject, ov[2 * i], ov[2 * i + 1], unmatched_as_null));
                                }
                            }
                            if let Some(mark) = session.mark(&re.code) {
                                marks
                                    .get_or_insert_with(Array::new)
                                    .add(Key::Int(matched - 1), Value::Str(mark.to_vec()));
                            }
                            for set in sets.iter_mut().take(num_subpats).skip(set_count) {
                                if offset_capture {
                                    add_offset_pair(set, subject, UNSET, UNSET, None, unmatched_as_null);
                                } else if unmatched_as_null {
                                    set.push(Value::Null);
                                } else {
                                    set.push(Value::Str(Vec::new()));
                                }
                            }
                        } else {
                            let mut set = Array::new();
                            re.populate_subpat_array(&mut set, subject, &session, count, flags);
                            matches.push(Value::Array(set));
                        }
                    } else {
                        re.populate_subpat_array(&mut matches, subject, &session, count, flags);
                        break;
                    }
                }
                start_offset2 = ov1;
                if start_offset2 == ov0 {
                    match re.call(&mut session, subject, start_offset2, Call::Retry) {
                        Ok(Some(count)) => {
                            if !global {
                                break;
                            }
                            pending = Some(Ok(Some(count)));
                            continue;
                        }
                        Ok(None) => {
                            if start_offset2 < len {
                                start_offset2 += re.unit_length(subject, start_offset2);
                            } else {
                                break;
                            }
                        }
                        Err(e) => {
                            error = set_last_error(PregError::from_pcre(e));
                            break;
                        }
                    }
                }
            }
            Ok(None) => break,
            Err(e) => {
                error = set_last_error(PregError::from_pcre(e));
                break;
            }
        }
        if !global {
            break;
        }
        if start_offset2 > len {
            error = set_last_error(PregError::Internal);
            break;
        }
    }
    if let Some(sets) = match_sets {
        for (i, set) in sets.into_iter().enumerate() {
            if let Some(names) = &re.names
                && let Some(name) = &names[i]
            {
                matches.update(Key::Str(name.clone()), Value::Array(set.clone()));
            }
            matches.push(Value::Array(set));
        }
        if let Some(marks) = marks {
            matches.update(Key::Str(b"MARK".to_vec()), Value::Array(marks));
        }
    }
    if error == PregError::None {
        done(Value::Int(matched), matches, error, None)
    } else {
        done(Value::Bool(false), matches, error, None)
    }
}

/// `preg_get_backref()`: a `\n`, `$n` or `${n}` reference at `walk`; the
/// group number and the position after it.
fn get_backref(r: &[u8], walk: usize) -> Option<(usize, usize)> {
    let at = |i: usize| r.get(i).copied().unwrap_or(0);
    if at(walk + 1) == 0 {
        return None;
    }
    let mut w = walk;
    let mut in_brace = false;
    if at(w) == b'$' && at(w + 1) == b'{' {
        in_brace = true;
        w += 1;
    }
    w += 1;
    let mut backref;
    if at(w).is_ascii_digit() {
        backref = usize::from(at(w) - b'0');
        w += 1;
    } else {
        return None;
    }
    if at(w) != 0 && at(w).is_ascii_digit() {
        backref = backref * 10 + usize::from(at(w) - b'0');
        w += 1;
    }
    if in_brace {
        if at(w) != b'}' {
            return None;
        }
        w += 1;
    }
    Some((backref, w))
}

/// Expands a replacement template for one match (`php_pcre_replace_impl()`):
/// `ov` holds the pairs of the groups the match set.
fn expand_replacement(out: &mut Vec<u8>, replace: &[u8], subject: &[u8], ov: &[usize]) {
    let mut walk = 0;
    let mut walk_last = 0u8;
    while walk < replace.len() {
        let c = replace[walk];
        if c == b'\\' || c == b'$' {
            if walk_last == b'\\' {
                if let Some(last) = out.last_mut() {
                    *last = c;
                }
                walk += 1;
                walk_last = 0;
                continue;
            }
            if let Some((backref, next)) = get_backref(replace, walk) {
                if backref < ov.len() / 2 {
                    let (s, e) = (ov[2 * backref], ov[2 * backref + 1]);
                    if s != UNSET {
                        out.extend_from_slice(&subject[s..e]);
                    }
                }
                walk = next;
                continue;
            }
        }
        out.push(c);
        walk += 1;
        walk_last = c;
    }
}

/// The replacement of [`replace_impl`]: a template or a callback.
enum Replacement<'a, 'f> {
    Template(&'a [u8]),
    Callback { func: &'f mut dyn FnMut(&Array) -> Vec<u8>, flags: i64 },
}

/// `php_pcre_replace_impl()` / `php_pcre_replace_func_impl()`: `None` on
/// error (`preg_last_error()` set in `error`).
fn replace_impl(
    re: &Regex,
    subject: &[u8],
    replacement: &mut Replacement<'_, '_>,
    mut limit: usize,
    replace_count: &mut i64,
    error: &mut PregError,
) -> Option<Vec<u8>> {
    *error = set_last_error(PregError::None);
    let len = subject.len();
    let mut result: Option<Vec<u8>> = None;
    let mut last_end_offset = 0usize;
    let mut start_offset;
    let mut session = re.session();
    let mut count = re.call(&mut session, subject, 0, re.first_call(false));
    loop {
        let mut piece = last_end_offset;
        match count {
            Ok(Some(first)) if limit > 0 => {
                let mut n = first;
                loop {
                    let (ov0, ov1) = {
                        let ov = session.ovector(n);
                        (ov[0], ov[1])
                    };
                    if ov1 < ov0 {
                        *error = set_last_error(PregError::Internal);
                        return None;
                    }
                    *replace_count += 1;
                    let mut out = result.take().unwrap_or_default();
                    out.extend_from_slice(&subject[piece..ov0]);
                    match replacement {
                        Replacement::Template(r) => expand_replacement(&mut out, r, subject, session.ovector(n)),
                        Replacement::Callback { func, flags } => {
                            let mut arg = Array::new();
                            re.populate_subpat_array(&mut arg, subject, &session, n, *flags);
                            out.extend_from_slice(&func(&arg));
                        }
                    }
                    result = Some(out);
                    limit -= 1;
                    start_offset = ov1;
                    last_end_offset = start_offset;
                    if start_offset == ov0 {
                        let retry = re.call(&mut session, subject, start_offset, Call::Retry);
                        piece = start_offset;
                        match retry {
                            Ok(Some(m)) if limit > 0 => {
                                n = m;
                                continue;
                            }
                            Ok(_) => {}
                            Err(_) if limit == 0 => {}
                            Err(e) => {
                                *error = set_last_error(PregError::from_pcre(e));
                                return None;
                            }
                        }
                        if start_offset < len {
                            start_offset += re.unit_length(subject, piece);
                        } else {
                            return Some(finish(result, subject, last_end_offset));
                        }
                    }
                    break;
                }
            }
            Ok(_) => return Some(finish(result, subject, last_end_offset)),
            Err(_) if limit == 0 => return Some(finish(result, subject, last_end_offset)),
            Err(e) => {
                *error = set_last_error(PregError::from_pcre(e));
                return None;
            }
        }
        count = re.call(&mut session, subject, start_offset, Call::Fast);
    }
}

/// `not_matched:` the rest of the subject after the last match.
fn finish(result: Option<Vec<u8>>, subject: &[u8], last_end_offset: usize) -> Vec<u8> {
    match result {
        None => subject.to_vec(),
        Some(mut r) => {
            r.extend_from_slice(&subject[last_end_offset..]);
            r
        }
    }
}

/// A `string|array` argument of `preg_replace()`.
#[derive(Debug, Clone, PartialEq)]
pub enum StrOrArray<'a> {
    /// A string.
    Str(&'a [u8]),
    /// An array of strings, with their keys.
    Array(Vec<(Key, Vec<u8>)>),
}

/// What [`preg_replace`] and [`preg_replace_callback`] return: the result
/// (`Str`, `Array` or `Null`) and `$count`.
#[derive(Debug, Clone, PartialEq)]
pub struct ReplaceResult {
    /// The return value: a string, an array, or `null` on failure.
    pub result: Value,
    /// `$count`, the number of replacements.
    pub count: i64,
}

/// The `$limit` argument as PHP uses it (a `size_t`).
fn limit_of(limit: i64) -> usize {
    limit as u64 as usize
}

/// Runs one compiled-or-failed regex over a subject (`php_pcre_replace()`).
#[allow(clippy::too_many_arguments)]
fn replace_one(
    func: &str,
    regex: &[u8],
    subject: &[u8],
    replacement: &mut Replacement<'_, '_>,
    limit: i64,
    count: &mut i64,
    error: &mut PregError,
    warning: &mut Option<Vec<u8>>,
) -> Option<Vec<u8>> {
    match cached(func, regex) {
        Ok((re, jit_warning)) => {
            if jit_warning.is_some() {
                *warning = jit_warning;
            }
            replace_impl(&re, subject, replacement, limit_of(limit), count, error)
        }
        Err(w) => {
            *error = PregError::Internal;
            *warning = Some(w);
            None
        }
    }
}

/// `preg_replace($pattern, $replacement, $subject, $limit, $count)`.
pub fn preg_replace(
    pattern: &StrOrArray<'_>,
    replacement: &StrOrArray<'_>,
    subject: &StrOrArray<'_>,
    limit: i64,
) -> Result<Preg<ReplaceResult>, ArgumentError> {
    if let (StrOrArray::Str(_), StrOrArray::Array(_)) = (pattern, replacement) {
        return Err(ArgumentError {
            class: "TypeError",
            message: "preg_replace(): Argument #1 ($pattern) must be of type array when argument #2 ($replacement) is an array, string given".into(),
        });
    }
    let mut error = preg_last_error();
    let mut warning = None;
    let mut count = 0i64;
    let in_subject =
        |subject: &[u8], count: &mut i64, error: &mut PregError, warning: &mut Option<Vec<u8>>| -> Option<Vec<u8>> {
            match pattern {
                StrOrArray::Str(p) => {
                    let StrOrArray::Str(r) = replacement else { unreachable!() };
                    replace_one("preg_replace", p, subject, &mut Replacement::Template(r), limit, count, error, warning)
                }
                StrOrArray::Array(patterns) => {
                    let mut current = subject.to_vec();
                    let mut replace_idx = 0;
                    for (_, p) in patterns {
                        let r: &[u8] = match replacement {
                            StrOrArray::Str(r) => r,
                            StrOrArray::Array(rs) => {
                                let r = rs.get(replace_idx).map_or(&[][..], |(_, r)| r.as_slice());
                                replace_idx += 1;
                                r
                            }
                        };
                        current = replace_one(
                            "preg_replace",
                            p,
                            &current,
                            &mut Replacement::Template(r),
                            limit,
                            count,
                            error,
                            warning,
                        )?;
                    }
                    Some(current)
                }
            }
        };
    let result = match subject {
        StrOrArray::Str(s) => match in_subject(s, &mut count, &mut error, &mut warning) {
            Some(r) => Value::Str(r),
            None => Value::Null,
        },
        StrOrArray::Array(entries) => {
            let mut out = Array::new();
            for (k, s) in entries {
                if let Some(r) = in_subject(s, &mut count, &mut error, &mut warning) {
                    out.update(k.clone(), Value::Str(r));
                }
            }
            Value::Array(out)
        }
    };
    Ok(Preg { value: ReplaceResult { result, count }, error, warning })
}

/// `preg_replace_callback($pattern, $callback, $subject, $limit, $count,
/// $flags)`: `callback` receives the match array (shaped by `flags`) and
/// returns the replacement.
pub fn preg_replace_callback(
    pattern: &StrOrArray<'_>,
    callback: &mut dyn FnMut(&Array) -> Vec<u8>,
    subject: &StrOrArray<'_>,
    limit: i64,
    flags: i64,
) -> Preg<ReplaceResult> {
    let mut error = preg_last_error();
    let mut warning = None;
    let mut count = 0i64;
    let func = "preg_replace_callback";
    let mut in_subject =
        |subject: &[u8], count: &mut i64, error: &mut PregError, warning: &mut Option<Vec<u8>>| -> Option<Vec<u8>> {
            match pattern {
                StrOrArray::Str(p) => replace_one(
                    func,
                    p,
                    subject,
                    &mut Replacement::Callback { func: callback, flags },
                    limit,
                    count,
                    error,
                    warning,
                ),
                StrOrArray::Array(patterns) => {
                    let mut current = subject.to_vec();
                    for (_, p) in patterns {
                        current = replace_one(
                            func,
                            p,
                            &current,
                            &mut Replacement::Callback { func: callback, flags },
                            limit,
                            count,
                            error,
                            warning,
                        )?;
                    }
                    Some(current)
                }
            }
        };
    let result = match subject {
        StrOrArray::Str(s) => match in_subject(s, &mut count, &mut error, &mut warning) {
            Some(r) => Value::Str(r),
            None => Value::Null,
        },
        StrOrArray::Array(entries) => {
            let mut out = Array::new();
            for (k, s) in entries {
                if let Some(r) = in_subject(s, &mut count, &mut error, &mut warning) {
                    out.update(k.clone(), Value::Str(r));
                }
            }
            Value::Array(out)
        }
    };
    Preg { value: ReplaceResult { result, count }, error, warning }
}

/// `preg_split($pattern, $subject, $limit, $flags)`: an array, or
/// `Bool(false)`.
pub fn preg_split(pattern: &[u8], subject: &[u8], limit: i64, flags: i64) -> Preg<Value> {
    let (re, warning) = match cached("preg_split", pattern) {
        Ok(r) => r,
        Err(w) => return Preg { value: Value::Bool(false), error: PregError::Internal, warning: Some(w) },
    };
    let no_empty = flags & PREG_SPLIT_NO_EMPTY != 0;
    let delim_capture = flags & PREG_SPLIT_DELIM_CAPTURE != 0;
    let offset_capture = flags & PREG_SPLIT_OFFSET_CAPTURE != 0;
    let mut out = Array::new();
    let len = subject.len();
    let mut start_offset = 0usize;
    let mut last_match_offset = 0usize;
    let mut error = set_last_error(PregError::None);
    let mut limit_val = limit;
    let piece = |out: &mut Array, s: usize, e: usize| {
        if offset_capture {
            add_offset_pair(out, subject, s, e, None, false);
        } else {
            out.push(match_value(subject, s, e, false));
        }
    };
    let mut run = true;
    if limit_val == -1 {
    } else if limit_val == 0 {
        limit_val = -1;
    } else if limit_val <= 1 {
        run = false;
    }
    if run {
        let mut session = re.session();
        let mut pending = Some(re.call(&mut session, subject, start_offset, re.first_call(false)));
        loop {
            let count = pending.take().unwrap_or_else(|| re.call(&mut session, subject, start_offset, Call::Fast));
            match count {
                Ok(Some(count)) => {
                    let ov = session.ovector(count);
                    let (ov0, ov1) = (ov[0], ov[1]);
                    if ov1 < ov0 {
                        error = set_last_error(PregError::Internal);
                        break;
                    }
                    if !no_empty || ov0 != last_match_offset {
                        piece(&mut out, last_match_offset, ov0);
                        if limit_val != -1 {
                            limit_val -= 1;
                        }
                    }
                    if delim_capture {
                        for i in 1..ov.len() / 2 {
                            if !no_empty || ov[2 * i] != ov[2 * i + 1] {
                                if ov[2 * i] == UNSET {
                                    if offset_capture {
                                        add_offset_pair(&mut out, subject, UNSET, UNSET, None, false);
                                    } else {
                                        out.push(Value::Str(Vec::new()));
                                    }
                                } else {
                                    piece(&mut out, ov[2 * i], ov[2 * i + 1]);
                                }
                            }
                        }
                    }
                    start_offset = ov1;
                    last_match_offset = ov1;
                    if start_offset == ov0 {
                        if limit_val != -1 && limit_val <= 1 {
                            break;
                        }
                        match re.call(&mut session, subject, start_offset, Call::Retry) {
                            Ok(Some(m)) => {
                                pending = Some(Ok(Some(m)));
                                continue;
                            }
                            Ok(None) => {
                                if start_offset < len {
                                    start_offset += re.unit_length(subject, start_offset);
                                } else {
                                    break;
                                }
                            }
                            Err(e) => {
                                error = set_last_error(PregError::from_pcre(e));
                                break;
                            }
                        }
                    }
                }
                Ok(None) => break,
                Err(e) => {
                    error = set_last_error(PregError::from_pcre(e));
                    break;
                }
            }
            if limit_val != -1 && limit_val <= 1 {
                break;
            }
        }
        if error != PregError::None {
            return Preg { value: Value::Bool(false), error, warning };
        }
    }
    let start = last_match_offset;
    if !no_empty || start < len {
        piece(&mut out, start, len);
    }
    Preg { value: Value::Array(out), error, warning }
}

/// `preg_quote($str, $delimiter)`: only the first byte of the delimiter is
/// used.
pub fn preg_quote(s: &[u8], delimiter: Option<&[u8]>) -> Vec<u8> {
    let delim_char = delimiter.and_then(|d| d.first().copied()).unwrap_or(0);
    let mut out = Vec::with_capacity(s.len());
    for &c in s {
        match c {
            b'.' | b'\\' | b'+' | b'*' | b'?' | b'[' | b'^' | b']' | b'$' | b'(' | b')' | b'{' | b'}' | b'=' | b'!'
            | b'>' | b'<' | b'|' | b':' | b'-' | b'#' => {
                out.push(b'\\');
                out.push(c);
            }
            0 => out.extend_from_slice(b"\\000"),
            _ => {
                if c == delim_char {
                    out.push(b'\\');
                }
                out.push(c);
            }
        }
    }
    out
}

/// `preg_grep($pattern, $array, $flags)`: the entries (keys kept) that match
/// (or, with `PREG_GREP_INVERT`, that do not), or `Bool(false)`.
pub fn preg_grep(pattern: &[u8], input: &[(Key, Vec<u8>)], flags: i64) -> Preg<Value> {
    let (re, warning) = match cached("preg_grep", pattern) {
        Ok(r) => r,
        Err(w) => return Preg { value: Value::Bool(false), error: PregError::Internal, warning: Some(w) },
    };
    let invert = flags & PREG_GREP_INVERT != 0;
    let mut out = Array::new();
    let mut error = set_last_error(PregError::None);
    let mut session = re.session();
    for (k, s) in input {
        match re.call(&mut session, s, 0, re.first_call(false)) {
            Ok(Some(_)) => {
                if !invert {
                    out.update(k.clone(), Value::Str(s.clone()));
                }
            }
            Ok(None) => {
                if invert {
                    out.update(k.clone(), Value::Str(s.clone()));
                }
            }
            Err(e) => {
                error = set_last_error(PregError::from_pcre(e));
                break;
            }
        }
    }
    Preg { value: Value::Array(out), error, warning }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn m(p: &str, s: &str) -> Value {
        let r = preg_match(p.as_bytes(), s.as_bytes(), 0, 0).unwrap();
        Value::Array(r.value.matches.unwrap())
    }

    fn strs(v: &Value) -> Vec<String> {
        let Value::Array(a) = v else { panic!("not an array") };
        a.entries()
            .iter()
            .map(|(_, v)| match v {
                Value::Str(s) => String::from_utf8_lossy(s).into_owned(),
                other => format!("{other:?}"),
            })
            .collect()
    }

    #[test]
    fn basic_matches() {
        assert_eq!(strs(&m("/a(b)c/", "xabcx")), ["abc", "b"]);
        assert_eq!(strs(&m("/(a|ab)(c|bcd)(d*)/", "abcd")), ["abcd", "a", "bcd", ""]);
        assert_eq!(strs(&m("/^(\\w+)\\((.+)\\)$/i", "count(x)")), ["count(x)", "count", "x"]);
        assert_eq!(strs(&m("/(a+)+b/", "aaab")), ["aaab", "aaa"]);
        assert_eq!(strs(&m("/a*?/", "aaa")), [""]);
        assert_eq!(strs(&m("/(?<y>\\d{4})-(?<m>\\d\\d)/", "on 2024-05")), ["2024-05", "2024", "2024", "05", "05"]);
        assert_eq!(strs(&m("/(a)|(b)/", "b")), ["b", "", "b"]);
        assert_eq!(strs(&m("/(?:(a)|b)+/", "ab")), ["ab", "a"]);
        assert_eq!(strs(&m("/(?=(\\w+))\\w/", "abc")), ["a", "abc"]);
        assert_eq!(strs(&m("/(?<=b)c/", "abc")), ["c"]);
        assert_eq!(strs(&m("/(a)(?1)/", "aa")), ["aa", "a"]);
        assert_eq!(strs(&m("/\\((?:[^()]|(?R))*\\)/", "x(a(b)c)y")), ["(a(b)c)"]);
        assert_eq!(strs(&m("/(?:x|(?:a\\Ka)*)+$/", "aaaa")), ["a"]);
        assert_eq!(strs(&m("/é+/u", "aéé")), ["éé"]);
        assert_eq!(strs(&m("/\\w+/u", "naïve!")), ["naïve"]);
        assert_eq!(strs(&m("/[[:alpha:]]+/", "ab1")), ["ab"]);
        assert_eq!(strs(&m("/(*MARK:A)x|(*MARK:B)y/", "y")), ["y", "B"]);
    }

    #[test]
    fn match_all_and_replace() {
        let r = preg_match_all(b"/a*?/", b"aaa", 0, 0).unwrap();
        assert_eq!(r.value.result, Value::Int(7));
        let r = preg_replace(
            &StrOrArray::Str(b"/(\\w+) (\\w+)/"),
            &StrOrArray::Str(b"$2 ${1}\\\\"),
            &StrOrArray::Str(b"hello world"),
            -1,
        )
        .unwrap();
        assert_eq!(r.value.result, Value::Str(b"world hello\\".to_vec()));
        let r = preg_split(b"/\\s*/", b"a b", -1, PREG_SPLIT_NO_EMPTY);
        assert_eq!(strs(&r.value), ["a", "b"]);
        assert_eq!(preg_quote(b"a.b*c/\0", Some(b"/")), b"a\\.b\\*c\\/\\000".to_vec());
    }

    #[test]
    fn limits() {
        let r = preg_match(b"/(?:\\D+|<\\d+>)*[!?]/", b"foobar foobar foobar", 0, 0).unwrap();
        assert_eq!((r.value.result, r.error), (Value::Bool(false), PregError::BacktrackLimit));
        let r = preg_match(b"/^(?:a|b)*$/", &b"ab".repeat(4096), 0, 0).unwrap();
        assert_eq!(r.error, PregError::JitStackLimit);
        let r = preg_match(b"/./u", b"\xff", 0, 0).unwrap();
        assert_eq!(r.error, PregError::BadUtf8);
    }

    #[test]
    fn nested_calls() {
        let r = preg_replace_callback(
            &StrOrArray::Str(b"/\\w+/"),
            &mut |m| {
                let Some(Value::Str(word)) = m.get(&Key::Int(0)) else { return Vec::new() };
                let inner = preg_replace(&StrOrArray::Str(b"/o/"), &StrOrArray::Str(b"0"), &StrOrArray::Str(word), -1);
                match inner.unwrap().value.result {
                    Value::Str(s) => s,
                    _ => Vec::new(),
                }
            },
            &StrOrArray::Str(b"foo boo"),
            -1,
            0,
        );
        assert_eq!(r.value.result, Value::Str(b"f00 b00".to_vec()));
    }

    #[test]
    fn compile_failures() {
        let r = preg_match(b"/(abc/", b"x", 0, 0).unwrap();
        assert_eq!(r.value.result, Value::Bool(false));
        assert_eq!(
            r.warning.unwrap(),
            b"preg_match(): Compilation failed: missing closing parenthesis at offset 4".to_vec()
        );
        let r = preg_match(b"abc", b"x", 0, 0).unwrap();
        assert_eq!(
            r.warning.unwrap(),
            b"preg_match(): Delimiter must not be alphanumeric, backslash, or NUL byte".to_vec()
        );
        let r = preg_match(b"/abc/k", b"x", 0, 0).unwrap();
        assert_eq!(r.warning.unwrap(), b"preg_match(): Unknown modifier 'k'".to_vec());
        let r = preg_match(b"/\\C/u", b"x", 0, 0).unwrap();
        assert_eq!(
            r.warning.unwrap(),
            b"preg_match(): Compilation failed: using \\C is incompatible with the 'u' modifier at offset 2".to_vec()
        );
    }

    /// A full cache evicts its oldest entries, and a thread that remembers
    /// one of them compiles it again, as PHP would.
    #[test]
    fn cache_evicts() {
        let first = Regex::compiled(b"/cache-evicts-first/").unwrap();
        assert!(Arc::ptr_eq(&first, &Regex::compiled(b"/cache-evicts-first/").unwrap()));
        for i in 0..CACHE_SIZE {
            Regex::compiled(format!("/cache-evicts-{i}/").as_bytes()).unwrap();
        }
        assert!(!Arc::ptr_eq(&first, &Regex::compiled(b"/cache-evicts-first/").unwrap()));
    }
}
