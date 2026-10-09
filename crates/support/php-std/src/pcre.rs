//! PHP's PCRE functions: `preg_match`, `preg_match_all`, `preg_replace`,
//! `preg_replace_callback`, `preg_split`, `preg_quote` and `preg_grep`, on a
//! Rust port of PCRE2 10.44 (the version bundled with PHP 8.5).
//!
//! | PHP | Rust |
//! |---|---|
//! | `preg_match($p, $s, $m, $flags, $offset)` | [`preg_match`] |
//! | `preg_match_all($p, $s, $m, $flags, $offset)` | [`preg_match_all`] |
//! | `preg_replace($p, $r, $s, $limit, $count)` | [`preg_replace`] |
//! | `preg_replace_callback($p, $fn, $s, $limit, $count, $flags)` | [`preg_replace_callback`] |
//! | `preg_split($p, $s, $limit, $flags)` | [`preg_split`] |
//! | `preg_quote($s, $delimiter)` | [`preg_quote`] |
//! | `preg_grep($p, $array, $flags)` | [`preg_grep`] |
//! | `preg_last_error()` / `preg_last_error_msg()` | [`PregError`] in every result |
//!
//! The engine is a port, not a translation to another regex dialect: the
//! pattern parser (`parse_regex()`), the lookbehind checks, the compiler
//! (`compile_branch()`, including class construction and caseless sets),
//! the matcher (`match()`, with PCRE2's backtracking frames, so verbs,
//! recursion, conditions, atomic groups and `\K` behave identically) and the
//! Unicode 15.0.0 tables. Patterns are delimited and take modifiers exactly
//! as `pcre_get_compiled_regex_cache()` parses them (`/i m n s x A D r S X U
//! u J`), and compile errors produce PHP's warning text, with PCRE2's error
//! messages and offsets.
//!
//! PHP runs patterns with the PCRE2 JIT, and retries an empty match with
//! the interpreter; both produce the same results, which this module
//! reproduces, including PHP's UTF-8 checks (only from the start offset
//! moved back by the longest lookbehind) and the JIT's report of a
//! recursion loop as a JIT stack overflow. Not reproduced: where exactly
//! the backtracking limit (`pcre.backtrack_limit`) and the JIT stack run
//! out, since they depend on the machine code the JIT generates; this module
//! counts backtracking frames like the interpreter does.
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `pcre.*`).

use std::collections::{HashMap, VecDeque};
use std::fmt;
use std::sync::{Arc, Mutex, OnceLock};

mod compile;
mod exec;
mod lookbehind;
mod parse;
mod program;
mod study;
mod ucd;
mod unicode;

use exec::{Exec, MatchData, Matcher, UNSET};
use study::StartInfo;
use parse::{opt, Bsr, Newline};
use program::{BraKind, Op, Program};

pub const PREG_PATTERN_ORDER: i64 = 1;
pub const PREG_SET_ORDER: i64 = 2;
pub const PREG_OFFSET_CAPTURE: i64 = 1 << 8;
pub const PREG_UNMATCHED_AS_NULL: i64 = 1 << 9;
pub const PREG_SPLIT_NO_EMPTY: i64 = 1;
pub const PREG_SPLIT_DELIM_CAPTURE: i64 = 2;
pub const PREG_SPLIT_OFFSET_CAPTURE: i64 = 4;
pub const PREG_GREP_INVERT: i64 = 1;

/// `pcre.backtrack_limit` and `pcre.recursion_limit` defaults.
const BACKTRACK_LIMIT: u64 = 1_000_000;
const RECURSION_LIMIT: u32 = 100_000;

/// A PHP array key.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub enum Key {
    Int(i64),
    Str(Vec<u8>),
}

/// The PHP values the `preg_*` functions build.
#[derive(Debug, Clone, PartialEq)]
pub enum Value {
    Null,
    Bool(bool),
    Int(i64),
    Str(Vec<u8>),
    Array(Array),
}

/// An ordered PHP array.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Array {
    entries: Vec<(Key, Value)>,
    next: i64,
}

impl Array {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn entries(&self) -> &[(Key, Value)] {
        &self.entries
    }

    pub fn len(&self) -> usize {
        self.entries.len()
    }

    pub fn is_empty(&self) -> bool {
        self.entries.is_empty()
    }

    /// Whether the keys are `0, 1, 2...` in order (`array_is_list`).
    pub fn is_list(&self) -> bool {
        self.entries.iter().enumerate().all(|(i, (k, _))| *k == Key::Int(i as i64))
    }

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
        if let Key::Int(i) = key {
            if i >= self.next {
                self.next = i.saturating_add(1);
            }
        }
        self.entries.push((key, value));
    }
}

/// `preg_last_error()`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PregError {
    None,
    Internal,
    BacktrackLimit,
    RecursionLimit,
    BadUtf8,
    BadUtf8Offset,
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
            exec::ERROR_MATCHLIMIT => PregError::BacktrackLimit,
            exec::ERROR_DEPTHLIMIT => PregError::RecursionLimit,
            exec::ERROR_BADUTFOFFSET => PregError::BadUtf8Offset,
            exec::ERROR_JIT_STACKLIMIT => PregError::JitStackLimit,
            -23..=-3 => PregError::BadUtf8,
            _ => PregError::Internal,
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
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.message)
    }
}

impl std::error::Error for ArgumentError {}

/// What a `preg_*` call returned, with `preg_last_error()` and the last
/// warning it emitted (`"preg_match(): Compilation failed: ..."`).
#[derive(Debug, Clone, PartialEq)]
pub struct Preg<T> {
    pub value: T,
    pub error: PregError,
    pub warning: Option<Vec<u8>>,
}

/// A pattern that `pcre_get_compiled_regex_cache()` rejected: the warning
/// text, without the function name.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CompileFailure {
    pub message: Vec<u8>,
}

/// A compiled PHP regular expression (delimiters and modifiers included).
#[derive(Debug, Clone)]
pub struct Regex {
    prog: Program,
    options: u32,
    /// The `u` modifier: PHP decides on UTF-8 checks and how far to step
    /// after an empty match from it, not from a `(*UTF)` in the pattern.
    php_utf: bool,
    newline: Newline,
    bsr: Bsr,
    jit: bool,
    notempty: bool,
    notempty_atstart: bool,
    match_limit: u64,
    depth_limit: u32,
    /// Group number to name (`subpats_table`).
    names: Vec<Option<Vec<u8>>>,
    name_count: usize,
    start: StartInfo,
}

/// The ways PHP runs a pattern.
#[derive(Clone, Copy, PartialEq, Eq)]
enum Call {
    /// `pcre2_jit_match(..., PCRE2_NO_UTF_CHECK)`: no UTF-8 check, and the
    /// pattern's `(*NOTEMPTY)`/`(*NOTEMPTY_ATSTART)` are not applied (only
    /// `pcre2_match()` copies them into the options). A pattern without JIT
    /// code goes to `pcre2_match(..., PCRE2_NO_UTF_CHECK)` instead.
    Fast,
    /// `pcre2_match(..., 0)` for a `/u` pattern and a subject not known to
    /// be valid UTF-8: the UTF-8 check, then the JIT.
    Checked,
    /// `pcre2_match(..., PCRE2_NO_UTF_CHECK | PCRE2_NOTEMPTY_ATSTART |
    /// PCRE2_ANCHORED)` after an empty match: the interpreter.
    Retry,
}

impl Regex {
    /// Parses the delimiters and modifiers of a PHP regex and compiles it
    /// (`pcre_get_compiled_regex_cache()`).
    pub fn new(regex: &[u8]) -> Result<Regex, CompileFailure> {
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
                b'i' => coptions |= opt::CASELESS,
                b'm' => coptions |= opt::MULTILINE,
                b'n' => coptions |= opt::NO_AUTO_CAPTURE,
                b's' => coptions |= opt::DOTALL,
                b'x' => coptions |= opt::EXTENDED,
                b'A' => coptions |= opt::ANCHORED,
                b'D' => coptions |= opt::DOLLAR_ENDONLY,
                b'r' => eoptions |= opt::X_CASELESS_RESTRICT,
                b'S' | b'X' | b' ' | b'\n' | b'\r' => {}
                b'U' => coptions |= opt::UNGREEDY,
                b'u' => coptions |= opt::UTF | opt::UCP | opt::NEVER_BACKSLASH_C,
                b'J' => coptions |= opt::DUPNAMES,
                0 => return fail("NUL byte is not a valid modifier".into()),
                c => {
                    let mut msg = b"Unknown modifier '".to_vec();
                    msg.push(c);
                    msg.push(b'\'');
                    return Err(CompileFailure { message: msg });
                }
            }
        }
        let mut re = Self::compile(pattern, coptions, eoptions).map_err(|e| {
            let text: &[u8] = if e.code == 83 {
                b"using \\C is incompatible with the 'u' modifier"
            } else {
                parse::error_message(e.code).as_bytes()
            };
            let mut m = b"Compilation failed: ".to_vec();
            m.extend_from_slice(text);
            m.extend_from_slice(format!(" at offset {}", e.offset).as_bytes());
            CompileFailure { message: m }
        })?;
        re.php_utf = coptions & opt::UTF != 0;
        Ok(re)
    }

    /// `pcre2_compile()` on a bare pattern.
    fn compile(pattern: &[u8], options: u32, xoptions: u32) -> Result<Regex, parse::CompileError> {
        let mut parsed = parse::parse(pattern, options, xoptions)?;
        let mut meta = std::mem::take(&mut parsed.meta);
        if parsed.has_lookbehind {
            let mut checker = lookbehind::Checker::new(&mut meta, pattern, &parsed);
            let mut recurses = Vec::new();
            let mut loopcount = 0;
            let rc = checker.check_lookbehinds(0, None, &mut recurses, &mut loopcount);
            if rc != 0 {
                return Err(parse::CompileError { code: rc, offset: checker.erroroffset });
            }
        }
        let tree = compile::build(&meta, pattern, &parsed, xoptions)?;
        let prog = program::lower(&tree);
        let mut names = vec![None; prog.top_bracket as usize + 1];
        for (name, number) in &prog.names {
            names[*number as usize] = Some(name.clone());
        }
        let name_count = prog.names.len();
        let jit = !contains_no_jit(pattern);
        let start = study::study(&prog, parsed.options);
        Ok(Regex {
            options: parsed.options,
            php_utf: false,
            newline: parsed.newline,
            bsr: parsed.bsr,
            jit,
            notempty: parsed.notempty,
            notempty_atstart: parsed.notempty_atstart,
            match_limit: parsed.limit_match.map_or(BACKTRACK_LIMIT, |l| BACKTRACK_LIMIT.min(u64::from(l))),
            depth_limit: parsed.limit_depth.map_or(RECURSION_LIMIT, |l| RECURSION_LIMIT.min(l)),
            names,
            name_count,
            start,
            prog,
        })
    }

    /// Whether `pcre2_jit_compile()` accepts the pattern: it refuses
    /// backtracking verbs (other than `(*MARK)` and a bare `(*THEN)`) and
    /// `(*ACCEPT)` inside a non-atomic assertion (`check_opcode_types()`).
    fn jit_compiles(&self) -> bool {
        let code = &self.prog.code;
        let mut assert_na_end = 0;
        for (pc, op) in code.iter().enumerate() {
            match op {
                // `\C` in UTF mode (`next_opcode()` gives up on OP_ANYBYTE).
                Op::Item(compile::Lit::AnyByte) | Op::Rep { lit: compile::Lit::AnyByte, .. } if self.utf() => {
                    return false;
                }
                Op::Bra { kind: BraKind::AssertNa | BraKind::AssertBackNa, .. } => {
                    let mut k = pc;
                    loop {
                        k = match code[k] {
                            Op::Bra { link, .. } | Op::Alt { link } => link,
                            _ => break,
                        };
                    }
                    assert_na_end = assert_na_end.max(k + 1);
                }
                Op::Commit(_) | Op::Prune(_) | Op::Skip | Op::SkipArg(_) | Op::Then(Some(_)) | Op::AssertAccept
                    if pc < assert_na_end =>
                {
                    return false;
                }
                _ => {}
            }
        }
        true
    }

    /// The number of capturing groups.
    pub fn capture_count(&self) -> u32 {
        self.prog.top_bracket
    }

    fn utf(&self) -> bool {
        self.options & opt::UTF != 0
    }

    /// One `pcre2_match()`/`pcre2_jit_match()` call as PHP makes it.
    fn call(&self, subject: &[u8], start_offset: usize, call: Call) -> Result<Option<MatchData>, i32> {
        let utf = self.utf();
        let (jit, check_utf, mut options) = match call {
            Call::Fast => (self.jit, false, 0),
            Call::Checked => (self.jit, true, 0),
            Call::Retry => (false, false, exec::NOTEMPTY_ATSTART | exec::ANCHORED),
        };
        if call != Call::Fast || !self.jit {
            if self.notempty {
                options |= exec::NOTEMPTY;
            }
            if self.notempty_atstart {
                options |= exec::NOTEMPTY_ATSTART;
            }
        }
        if start_offset > subject.len() {
            return Err(-33);
        }
        let mut check_subject = 0;
        if utf && check_utf {
            if start_offset < subject.len() && subject[start_offset] & 0xc0 == 0x80 {
                return Err(if start_offset > 0 { exec::ERROR_BADUTFOFFSET } else { -22 });
            }
            let mut sm = start_offset;
            for _ in 0..self.prog.max_lookbehind {
                if sm == 0 {
                    break;
                }
                sm -= 1;
                while sm > 0 && subject[sm] & 0xc0 == 0x80 {
                    sm -= 1;
                }
            }
            if let Err((code, _)) = unicode::valid_utf8(&subject[sm..]) {
                return Err(code);
            }
            if !jit {
                check_subject = sm;
            }
        }
        let mut m = Matcher::new(&self.prog, subject, self.options, self.newline, self.bsr);
        m.jit = jit;
        m.check_subject = check_subject;
        m.match_limit = self.match_limit;
        m.depth_limit = if jit { u32::MAX } else { self.depth_limit };
        match exec::exec(&mut m, start_offset, options, &self.start, self.prog.has_cr_or_lf) {
            Exec::Match(md) => Ok(Some(md)),
            Exec::NoMatch => Ok(None),
            Exec::Error(e) => Err(e),
        }
    }

    /// The first call of a `preg_*` function: `/u` patterns check the
    /// subject first. `known_valid`: PHP already knows the subject string is
    /// valid UTF-8 (`is_known_valid_utf8()`).
    fn first_call(&self, known_valid: bool) -> Call {
        if self.php_utf && !known_valid {
            Call::Checked
        } else {
            Call::Fast
        }
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

    fn mark_value(&self, mark: Option<u32>) -> Option<Vec<u8>> {
        mark.map(|id| {
            let name = &self.prog.marks[id as usize];
            let end = name.iter().position(|&b| b == 0).unwrap_or(name.len());
            name[..end].to_vec()
        })
    }

    /// `populate_subpat_array()`.
    fn populate_subpat_array(&self, out: &mut Array, subject: &[u8], md: &MatchData, flags: i64) {
        let offset_capture = flags & PREG_OFFSET_CAPTURE != 0;
        let unmatched_as_null = flags & PREG_UNMATCHED_AS_NULL != 0;
        let num_subpats = self.prog.top_bracket as usize + 1;
        let count = md.count;
        let names = if self.name_count > 0 { Some(&self.names) } else { None };
        let ov = &md.ovector;
        if let Some(names) = names {
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
        if let Some(mark) = self.mark_value(md.mark) {
            out.add(Key::Str(b"MARK".to_vec()), Value::Str(mark));
        }
    }
}

/// `PCRE_CACHE_SIZE`.
const CACHE_SIZE: usize = 4096;

/// PHP's per-process PCRE state: whether the JIT is still enabled
/// (`pcre.jit`, switched off for good when a pattern fails to JIT-compile)
/// and the compiled-pattern cache, which keeps how each pattern was
/// compiled.
struct Globals {
    jit: bool,
    /// `PCRE_G(error_code)`: what `preg_last_error()` returns. Calls that
    /// never reach a pattern (an empty array of patterns or subjects) leave
    /// it as the previous call set it.
    error: PregError,
    order: VecDeque<Vec<u8>>,
    cache: HashMap<Vec<u8>, Arc<Regex>>,
}

fn globals() -> &'static Mutex<Globals> {
    static GLOBALS: OnceLock<Mutex<Globals>> = OnceLock::new();
    GLOBALS.get_or_init(|| Mutex::new(Globals { jit: true, error: PregError::None, order: VecDeque::new(), cache: HashMap::new() }))
}

fn lock() -> std::sync::MutexGuard<'static, Globals> {
    match globals().lock() {
        Ok(g) => g,
        Err(poisoned) => poisoned.into_inner(),
    }
}

/// Sets `preg_last_error()`, returning the value.
fn set_last_error(e: PregError) -> PregError {
    lock().error = e;
    e
}

/// `preg_last_error()`: the error of the last `preg_*` call that set one.
pub fn preg_last_error() -> PregError {
    lock().error
}

/// `ini_set('pcre.jit', ...)`: switches the JIT on or off for patterns
/// compiled from now on (cached patterns keep how they were compiled).
pub fn set_jit(enabled: bool) {
    lock().jit = enabled;
}

/// `pcre_get_compiled_regex_cache()`: the cached pattern, compiling it on
/// a miss. `Err` carries the warning of a pattern that does not compile;
/// `Ok` may carry the warning of a pattern the JIT could not compile, which
/// switches the JIT off for the rest of the process, as PHP does.
fn cached(func: &str, regex: &[u8]) -> Result<(Arc<Regex>, Option<Vec<u8>>), Vec<u8>> {
    let mut g = lock();
    if let Some(re) = g.cache.get(regex) {
        return Ok((re.clone(), None));
    }
    let mut re = match Regex::new(regex) {
        Ok(re) => re,
        Err(e) => {
            g.error = PregError::Internal;
            return Err(warn(func, e));
        }
    };
    let mut warning = None;
    if !g.jit {
        re.jit = false;
    } else if re.jit && !re.jit_compiles() {
        warning = Some(
            format!(
                "{func}(): Allocation of JIT memory failed, PCRE JIT will be disabled. This is likely caused by security \
                 restrictions. Either grant PHP permission to allocate executable memory, or set pcre.jit=0"
            )
            .into_bytes(),
        );
        g.jit = false;
        re.jit = false;
    }
    if g.cache.len() == CACHE_SIZE {
        for _ in 0..CACHE_SIZE / 8 {
            if let Some(k) = g.order.pop_front() {
                g.cache.remove(&k);
            }
        }
    }
    let re = Arc::new(re);
    g.order.push_back(regex.to_vec());
    g.cache.insert(regex.to_vec(), re.clone());
    Ok((re, warning))
}

/// What `ext/filter` does with a regular expression:
/// `pcre_get_compiled_regex()` (PHP's cache), then `pcre2_match(re,
/// subject, len, 0, 0, ...)`. The result is `None` when the regex does not
/// compile, and comes with the warning of a compile or JIT failure.
pub(crate) fn engine_match(func: &str, regex: &[u8], subject: &[u8]) -> (Option<bool>, Option<Vec<u8>>) {
    match cached(func, regex) {
        Err(w) => (None, Some(w)),
        Ok((re, w)) => (Some(matches!(re.call(subject, 0, Call::Checked), Ok(Some(_)))), w),
    }
}

/// `isspace()` in the C locale.
fn is_c_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0b | 0x0c | b'\r')
}

/// Whether the pattern starts with a `(*NO_JIT)` option.
fn contains_no_jit(pattern: &[u8]) -> bool {
    let mut p = 0;
    while pattern.len() > p + 2 && pattern[p] == b'(' && pattern[p + 1] == b'*' {
        let Some(close) = pattern[p..].iter().position(|&b| b == b')') else { return false };
        if &pattern[p + 2..p + close] == b"NO_JIT" {
            return true;
        }
        p += close + 1;
    }
    false
}

fn match_value(subject: &[u8], start: usize, end: usize, unmatched_as_null: bool) -> Value {
    if start == UNSET {
        if unmatched_as_null {
            Value::Null
        } else {
            Value::Str(Vec::new())
        }
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
fn add_offset_pair(out: &mut Array, subject: &[u8], start: usize, end: usize, name: Option<&[u8]>, unmatched_as_null: bool) {
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
    pub result: Value,
    pub matches: Option<Array>,
}

/// `preg_match($pattern, $subject, $matches, $flags, $offset)`.
pub fn preg_match(pattern: &[u8], subject: &[u8], flags: i64, offset: i64) -> Result<Preg<MatchResult>, ArgumentError> {
    match_impl("preg_match", pattern, subject, false, flags, offset)
}

/// `preg_match_all($pattern, $subject, $matches, $flags, $offset)`.
pub fn preg_match_all(pattern: &[u8], subject: &[u8], flags: i64, offset: i64) -> Result<Preg<MatchResult>, ArgumentError> {
    match_impl("preg_match_all", pattern, subject, true, flags, offset)
}

/// `php_do_pcre_match()` and `php_pcre_match_impl()`.
fn match_impl(
    func: &str,
    pattern: &[u8],
    subject: &[u8],
    global: bool,
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
            if (global && !(PREG_PATTERN_ORDER..=PREG_SET_ORDER).contains(&subpats_order)) || (!global && subpats_order != 0) {
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
        Ok(Preg { value: MatchResult { result, matches: Some(matches) }, error, warning: warning.or(jit_warning.clone()) })
    };
    if start_offset2 > len {
        return done(Value::Bool(false), matches, set_last_error(PregError::Internal), None);
    }
    let num_subpats = re.prog.top_bracket as usize + 1;
    let mut match_sets: Option<Vec<Array>> =
        if global && subpats_order == PREG_PATTERN_ORDER { Some(vec![Array::new(); num_subpats]) } else { None };
    let mut marks: Option<Array> = None;
    let mut error = set_last_error(PregError::None);
    let mut matched: i64 = 0;
    // PHP's empty string is flagged as valid UTF-8; other subjects reach
    // these functions without the flag.
    let first = re.first_call(subject.is_empty());
    let mut pending = Some(re.call(subject, start_offset2, first));
    loop {
        let count = pending.take().unwrap_or_else(|| re.call(subject, start_offset2, Call::Fast));
        match count {
            Ok(Some(md)) => {
                matched += 1;
                let ov = &md.ovector;
                if ov[1] < ov[0] {
                    let warning = Some(format!("{func}(): Get subpatterns list failed").into_bytes());
                    return done(Value::Bool(false), matches, error, warning);
                }
                if global {
                    if let Some(sets) = match_sets.as_mut() {
                        for (i, set) in sets.iter_mut().enumerate().take(md.count) {
                            if offset_capture {
                                add_offset_pair(set, subject, ov[2 * i], ov[2 * i + 1], None, unmatched_as_null);
                            } else {
                                set.push(match_value(subject, ov[2 * i], ov[2 * i + 1], unmatched_as_null));
                            }
                        }
                        if let Some(mark) = re.mark_value(md.mark) {
                            marks.get_or_insert_with(Array::new).add(Key::Int(matched - 1), Value::Str(mark));
                        }
                        for set in sets.iter_mut().take(num_subpats).skip(md.count) {
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
                        re.populate_subpat_array(&mut set, subject, &md, flags);
                        matches.push(Value::Array(set));
                    }
                } else {
                    re.populate_subpat_array(&mut matches, subject, &md, flags);
                    break;
                }
                start_offset2 = ov[1];
                if start_offset2 == ov[0] {
                    match re.call(subject, start_offset2, Call::Retry) {
                        Ok(Some(md)) => {
                            pending = Some(Ok(Some(md)));
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
            if re.name_count > 0 {
                if let Some(name) = &re.names[i] {
                    matches.update(Key::Str(name.clone()), Value::Array(set.clone()));
                }
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

/// Expands a replacement template for one match (`php_pcre_replace_impl()`).
fn expand_replacement(out: &mut Vec<u8>, replace: &[u8], subject: &[u8], md: &MatchData) {
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
                if backref < md.count {
                    let (s, e) = (md.ovector[2 * backref], md.ovector[2 * backref + 1]);
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
    let mut start_offset = 0usize;
    let mut count = re.call(subject, 0, re.first_call(false));
    loop {
        let mut piece = last_end_offset;
        match count {
            Ok(Some(first)) if limit > 0 => {
                let mut md = first;
                loop {
                    if md.ovector[1] < md.ovector[0] {
                        *error = set_last_error(PregError::Internal);
                        return None;
                    }
                    *replace_count += 1;
                    let mut out = result.take().unwrap_or_default();
                    out.extend_from_slice(&subject[piece..md.ovector[0]]);
                    match replacement {
                        Replacement::Template(r) => expand_replacement(&mut out, r, subject, &md),
                        Replacement::Callback { func, flags } => {
                            let mut arg = Array::new();
                            re.populate_subpat_array(&mut arg, subject, &md, *flags);
                            out.extend_from_slice(&func(&arg));
                        }
                    }
                    result = Some(out);
                    limit -= 1;
                    start_offset = md.ovector[1];
                    last_end_offset = start_offset;
                    if start_offset == md.ovector[0] {
                        let retry = re.call(subject, start_offset, Call::Retry);
                        piece = start_offset;
                        match retry {
                            Ok(Some(m)) if limit > 0 => {
                                md = m;
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
        count = re.call(subject, start_offset, Call::Fast);
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
    Str(&'a [u8]),
    Array(Vec<(Key, Vec<u8>)>),
}

/// What [`preg_replace`] and [`preg_replace_callback`] return: the result
/// (`Str`, `Array` or `Null`) and `$count`.
#[derive(Debug, Clone, PartialEq)]
pub struct ReplaceResult {
    pub result: Value,
    pub count: i64,
}

/// The `$limit` argument as PHP uses it (a `size_t`).
fn limit_of(limit: i64) -> usize {
    limit as u64 as usize
}

/// Runs one compiled-or-failed regex over a subject (`php_pcre_replace()`).
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
    let mut in_subject = |subject: &[u8], count: &mut i64, error: &mut PregError, warning: &mut Option<Vec<u8>>| -> Option<Vec<u8>> {
        match pattern {
            StrOrArray::Str(p) => {
                let StrOrArray::Str(r) = replacement else { unreachable!() };
                replace_one("preg_replace", p, subject, &mut Replacement::Template(r), limit, count, error, warning)
            }
            StrOrArray::Array(patterns) => {
                let mut current = subject.to_vec();
                let mut replace_idx = 0;
                for (_, p) in patterns {
                    let r: Vec<u8> = match replacement {
                        StrOrArray::Str(r) => r.to_vec(),
                        StrOrArray::Array(rs) => {
                            let r = rs.get(replace_idx).map(|(_, r)| r.clone()).unwrap_or_default();
                            replace_idx += 1;
                            r
                        }
                    };
                    current = replace_one("preg_replace", p, &current, &mut Replacement::Template(&r), limit, count, error, warning)?;
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
    let mut in_subject = |subject: &[u8], count: &mut i64, error: &mut PregError, warning: &mut Option<Vec<u8>>| -> Option<Vec<u8>> {
        match pattern {
            StrOrArray::Str(p) => {
                replace_one(func, p, subject, &mut Replacement::Callback { func: callback, flags }, limit, count, error, warning)
            }
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
        let mut pending = Some(re.call(subject, start_offset, re.first_call(false)));
        loop {
            let count = pending.take().unwrap_or_else(|| re.call(subject, start_offset, Call::Fast));
            match count {
                Ok(Some(md)) => {
                    let ov = &md.ovector;
                    if ov[1] < ov[0] {
                        error = set_last_error(PregError::Internal);
                        break;
                    }
                    if !no_empty || ov[0] != last_match_offset {
                        piece(&mut out, last_match_offset, ov[0]);
                        if limit_val != -1 {
                            limit_val -= 1;
                        }
                    }
                    if delim_capture {
                        for i in 1..md.count {
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
                    start_offset = ov[1];
                    last_match_offset = ov[1];
                    if start_offset == ov[0] {
                        if limit_val != -1 && limit_val <= 1 {
                            break;
                        }
                        match re.call(subject, start_offset, Call::Retry) {
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
    for (k, s) in input {
        match re.call(s, 0, re.first_call(false)) {
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
        let r = preg_replace(&StrOrArray::Str(b"/(\\w+) (\\w+)/"), &StrOrArray::Str(b"$2 ${1}\\\\"), &StrOrArray::Str(b"hello world"), -1)
            .unwrap();
        assert_eq!(r.value.result, Value::Str(b"world hello\\".to_vec()));
        let r = preg_split(b"/\\s*/", b"a b", -1, PREG_SPLIT_NO_EMPTY);
        assert_eq!(strs(&r.value), ["a", "b"]);
        assert_eq!(preg_quote(b"a.b*c/\0", Some(b"/")), b"a\\.b\\*c\\/\\000".to_vec());
    }

    #[test]
    fn compile_failures() {
        let r = preg_match(b"/(abc/", b"x", 0, 0).unwrap();
        assert_eq!(r.value.result, Value::Bool(false));
        assert_eq!(r.warning.unwrap(), b"preg_match(): Compilation failed: missing closing parenthesis at offset 4".to_vec());
        let r = preg_match(b"abc", b"x", 0, 0).unwrap();
        assert_eq!(r.warning.unwrap(), b"preg_match(): Delimiter must not be alphanumeric, backslash, or NUL byte".to_vec());
        let r = preg_match(b"/abc/k", b"x", 0, 0).unwrap();
        assert_eq!(r.warning.unwrap(), b"preg_match(): Unknown modifier 'k'".to_vec());
    }
}
