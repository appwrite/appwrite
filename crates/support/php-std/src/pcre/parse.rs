//! The first pass of `pcre2_compile()`: a port of `parse_regex()` and its
//! helpers (`check_escape()`, `get_ucp()`, `read_name()`, ...) from PCRE2
//! 10.44's `pcre2_compile.c`.
//!
//! The pattern is turned into the same "parsed pattern" PCRE2 builds: a
//! vector of `u32` holding literal code points and `META_*` items. Keeping
//! PCRE2's representation, its parse order and its error offsets is what
//! makes compile errors (and the PHP warnings built from them) identical.

use std::ops::Range;

use super::ucd;
use super::unicode::{self, CTYPE_LCLETTER, CTYPE_LETTER, CTYPE_SPACE, CTYPE_WORD};

pub const META_END: u32 = 0x8000_0000;
pub const META_ALT: u32 = 0x8001_0000;
pub const META_ATOMIC: u32 = 0x8002_0000;
pub const META_BACKREF: u32 = 0x8003_0000;
pub const META_BACKREF_BYNAME: u32 = 0x8004_0000;
pub const META_BIGVALUE: u32 = 0x8005_0000;
pub const META_CALLOUT_NUMBER: u32 = 0x8006_0000;
pub const META_CALLOUT_STRING: u32 = 0x8007_0000;
pub const META_CAPTURE: u32 = 0x8008_0000;
pub const META_CIRCUMFLEX: u32 = 0x8009_0000;
pub const META_CLASS: u32 = 0x800a_0000;
pub const META_CLASS_EMPTY: u32 = 0x800b_0000;
pub const META_CLASS_EMPTY_NOT: u32 = 0x800c_0000;
pub const META_CLASS_END: u32 = 0x800d_0000;
pub const META_CLASS_NOT: u32 = 0x800e_0000;
pub const META_COND_ASSERT: u32 = 0x800f_0000;
pub const META_COND_DEFINE: u32 = 0x8010_0000;
pub const META_COND_NAME: u32 = 0x8011_0000;
pub const META_COND_NUMBER: u32 = 0x8012_0000;
pub const META_COND_RNAME: u32 = 0x8013_0000;
pub const META_COND_RNUMBER: u32 = 0x8014_0000;
pub const META_COND_VERSION: u32 = 0x8015_0000;
pub const META_DOLLAR: u32 = 0x8016_0000;
pub const META_DOT: u32 = 0x8017_0000;
pub const META_ESCAPE: u32 = 0x8018_0000;
pub const META_KET: u32 = 0x8019_0000;
pub const META_NOCAPTURE: u32 = 0x801a_0000;
pub const META_OPTIONS: u32 = 0x801b_0000;
pub const META_POSIX: u32 = 0x801c_0000;
pub const META_POSIX_NEG: u32 = 0x801d_0000;
pub const META_RANGE_ESCAPED: u32 = 0x801e_0000;
pub const META_RANGE_LITERAL: u32 = 0x801f_0000;
pub const META_RECURSE: u32 = 0x8020_0000;
pub const META_RECURSE_BYNAME: u32 = 0x8021_0000;
pub const META_SCRIPT_RUN: u32 = 0x8022_0000;
pub const META_LOOKAHEAD: u32 = 0x8023_0000;
pub const META_LOOKAHEADNOT: u32 = 0x8024_0000;
pub const META_LOOKBEHIND: u32 = 0x8025_0000;
pub const META_LOOKBEHINDNOT: u32 = 0x8026_0000;
pub const META_LOOKAHEAD_NA: u32 = 0x8027_0000;
pub const META_LOOKBEHIND_NA: u32 = 0x8028_0000;
pub const META_MARK: u32 = 0x8029_0000;
pub const META_ACCEPT: u32 = 0x802a_0000;
pub const META_FAIL: u32 = 0x802b_0000;
pub const META_COMMIT: u32 = 0x802c_0000;
pub const META_COMMIT_ARG: u32 = 0x802d_0000;
pub const META_PRUNE: u32 = 0x802e_0000;
pub const META_PRUNE_ARG: u32 = 0x802f_0000;
pub const META_SKIP: u32 = 0x8030_0000;
pub const META_SKIP_ARG: u32 = 0x8031_0000;
pub const META_THEN: u32 = 0x8032_0000;
pub const META_THEN_ARG: u32 = 0x8033_0000;
pub const META_ASTERISK: u32 = 0x8034_0000;
pub const META_ASTERISK_PLUS: u32 = 0x8035_0000;
pub const META_ASTERISK_QUERY: u32 = 0x8036_0000;
pub const META_PLUS: u32 = 0x8037_0000;
pub const META_PLUS_PLUS: u32 = 0x8038_0000;
pub const META_PLUS_QUERY: u32 = 0x8039_0000;
pub const META_QUERY: u32 = 0x803a_0000;
pub const META_QUERY_PLUS: u32 = 0x803b_0000;
pub const META_QUERY_QUERY: u32 = 0x803c_0000;
pub const META_MINMAX: u32 = 0x803d_0000;
pub const META_MINMAX_PLUS: u32 = 0x803e_0000;
pub const META_MINMAX_QUERY: u32 = 0x803f_0000;
const META_ATOMIC_SCRIPT_RUN: u32 = 0x8fff_0000;

pub fn meta_code(x: u32) -> u32 {
    x & 0xffff_0000
}

pub fn meta_data(x: u32) -> u32 {
    x & 0x0000_ffff
}

/// Extra parsed-pattern items after each META code (`meta_extra_lengths`);
/// offsets take one item here.
pub const META_EXTRA_LENGTHS: [u32; 64] = [
    0, 0, 0, 0, 2, 1, 3, 4, 0, 0, 0, 0, 0, 0, 0, 0, 1, 2, 2, 2, 2, 3, 0, 0, 0, 0, 0, 1, 1, 1, 0, 0, 1, 2, 0, 0, 0, 1,
    1, 0, 1, 1, 0, 0, 0, 1, 0, 1, 0, 1, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 2, 2,
];

pub const ESC_A: u32 = 1;
pub const ESC_G: u32 = 2;
pub const ESC_K: u32 = 3;
pub const ESC_B: u32 = 4;
pub const ESC_B_LOWER: u32 = 5;
pub const ESC_D: u32 = 6;
pub const ESC_D_LOWER: u32 = 7;
pub const ESC_S: u32 = 8;
pub const ESC_S_LOWER: u32 = 9;
pub const ESC_W: u32 = 10;
pub const ESC_W_LOWER: u32 = 11;
pub const ESC_N: u32 = 12;
#[allow(dead_code)] // PCRE2's escape numbering, kept whole.
pub const ESC_DUM: u32 = 13;
pub const ESC_C: u32 = 14;
pub const ESC_P: u32 = 15;
pub const ESC_P_LOWER: u32 = 16;
pub const ESC_R: u32 = 17;
pub const ESC_H: u32 = 18;
pub const ESC_H_LOWER: u32 = 19;
pub const ESC_V: u32 = 20;
pub const ESC_V_LOWER: u32 = 21;
pub const ESC_X: u32 = 22;
pub const ESC_Z: u32 = 23;
pub const ESC_Z_LOWER: u32 = 24;
pub const ESC_E: u32 = 25;
pub const ESC_Q: u32 = 26;
pub const ESC_G_LOWER: u32 = 27;
pub const ESC_K_LOWER: u32 = 28;
pub const ESC_UB: u32 = 29;

/// Compile option bits (PCRE2 values).
pub mod opt {
    // PHP never sets these two; they complete PCRE2's option bits.
    #[allow(dead_code)]
    pub const ALLOW_EMPTY_CLASS: u32 = 0x0000_0001;
    #[allow(dead_code)]
    pub const ALT_BSUX: u32 = 0x0000_0002;
    pub const CASELESS: u32 = 0x0000_0008;
    pub const DOLLAR_ENDONLY: u32 = 0x0000_0010;
    pub const DOTALL: u32 = 0x0000_0020;
    pub const DUPNAMES: u32 = 0x0000_0040;
    pub const EXTENDED: u32 = 0x0000_0080;
    pub const MATCH_UNSET_BACKREF: u32 = 0x0000_0200;
    pub const MULTILINE: u32 = 0x0000_0400;
    pub const NO_AUTO_CAPTURE: u32 = 0x0000_2000;
    pub const NO_AUTO_POSSESS: u32 = 0x0000_4000;
    pub const NO_DOTSTAR_ANCHOR: u32 = 0x0000_8000;
    pub const NO_START_OPTIMIZE: u32 = 0x0001_0000;
    pub const UCP: u32 = 0x0002_0000;
    pub const UNGREEDY: u32 = 0x0004_0000;
    pub const UTF: u32 = 0x0008_0000;
    pub const NEVER_BACKSLASH_C: u32 = 0x0010_0000;
    pub const ALT_VERBNAMES: u32 = 0x0040_0000;
    pub const EXTENDED_MORE: u32 = 0x0100_0000;
    pub const ANCHORED: u32 = 0x8000_0000;

    pub const X_CASELESS_RESTRICT: u32 = 0x0000_0080;
    pub const X_ASCII_BSD: u32 = 0x0000_0100;
    pub const X_ASCII_BSS: u32 = 0x0000_0200;
    pub const X_ASCII_BSW: u32 = 0x0000_0400;
    pub const X_ASCII_POSIX: u32 = 0x0000_0800;
    pub const X_ASCII_DIGIT: u32 = 0x0000_1000;
}

use opt::*;

const PARSE_TRACKED_OPTIONS: u32 =
    CASELESS | DOTALL | DUPNAMES | EXTENDED | EXTENDED_MORE | MULTILINE | NO_AUTO_CAPTURE | UNGREEDY;
const PARSE_TRACKED_EXTRA_OPTIONS: u32 =
    X_CASELESS_RESTRICT | X_ASCII_BSD | X_ASCII_BSS | X_ASCII_BSW | X_ASCII_DIGIT | X_ASCII_POSIX;

pub const PT_ANY: u32 = 0;
pub const PT_LAMP: u32 = 1;
pub const PT_GC: u32 = 2;
pub const PT_PC: u32 = 3;
pub const PT_SC: u32 = 4;
pub const PT_SCX: u32 = 5;
pub const PT_ALNUM: u32 = 6;
pub const PT_SPACE: u32 = 7;
pub const PT_PXSPACE: u32 = 8;
pub const PT_WORD: u32 = 9;
pub const PT_CLIST: u32 = 10;
pub const PT_UCNC: u32 = 11;
pub const PT_BIDICL: u32 = 12;
pub const PT_BOOL: u32 = 13;
pub const PT_PXGRAPH: u32 = 14;
pub const PT_PXPRINT: u32 = 15;
pub const PT_PXPUNCT: u32 = 16;
pub const PT_PXXDIGIT: u32 = 17;
const PT_NOTSCRIPT: u32 = 255;

pub const PC_DIGIT: u32 = 7;
pub const PC_XDIGIT: u32 = 13;

const MAX_GROUP_NUMBER: u32 = 65535;
const MAX_REPEAT_COUNT: u32 = 65535;
pub const REPEAT_UNLIMITED: u32 = MAX_REPEAT_COUNT + 1;
const MAX_NAME_SIZE: usize = 128;
const MAX_NAME_COUNT: usize = 10000;
const MAX_MARK: usize = 255;
const PARENS_NEST_LIMIT: u32 = 250;
const NEST_SAVE_LIMIT: usize = 6000 / 16;
pub const UNSET: usize = usize::MAX;

/// Newline conventions (`PCRE2_NEWLINE_*`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Newline {
    Cr,
    Lf,
    CrLf,
    Any,
    AnyCrLf,
    Nul,
}

/// `\R` conventions.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Bsr {
    Unicode,
    AnyCrLf,
}

/// A compile error: the PCRE2 error number (`ERRn`, or a negative UTF-8
/// error code) and the offset `pcre2_compile()` reports.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct CompileError {
    pub code: i32,
    pub offset: usize,
}

#[derive(Debug, Clone)]
pub struct NamedGroup {
    pub name: Range<usize>,
    pub number: u32,
    pub isdup: bool,
}

/// The output of the parsing pass.
#[derive(Debug, Clone)]
pub struct Parsed {
    pub meta: Vec<u32>,
    pub bracount: u32,
    pub names: Vec<NamedGroup>,
    pub has_lookbehind: bool,
    pub small_ref_offset: [usize; 10],
    pub dupcap_used: bool,
    pub options: u32,
    pub newline: Newline,
    pub bsr: Bsr,
    pub notempty: bool,
    pub notempty_atstart: bool,
    pub limit_match: Option<u32>,
    pub limit_depth: Option<u32>,
}

struct NestSave {
    nest_depth: u32,
    reset_group: u32,
    max_group: u32,
    flags: u32,
    options: u32,
    xoptions: u32,
}

const NSF_RESET: u32 = 1;
const NSF_CONDASSERT: u32 = 2;
const NSF_ATOMICSR: u32 = 4;

/// `pcre2_compile()` up to and including `parse_regex()`.
pub fn parse(pattern: &[u8], options: u32, xoptions: u32) -> Result<Parsed, CompileError> {
    let mut options = options;
    let mut skip = 0usize;
    let mut newline = Newline::Lf;
    let mut bsr = Bsr::Unicode;
    let mut notempty = false;
    let mut notempty_atstart = false;
    let mut limit_match = None;
    let mut limit_depth = None;

    // Start-of-pattern items such as (*UTF) and (*CRLF).
    const PSO: &[(&[u8], u8)] = &[
        (b"UTF8)", 0),
        (b"UTF)", 0),
        (b"UCP)", 1),
        (b"NOTEMPTY)", 2),
        (b"NOTEMPTY_ATSTART)", 3),
        (b"NO_AUTO_POSSESS)", 4),
        (b"NO_DOTSTAR_ANCHOR)", 5),
        (b"NO_JIT)", 6),
        (b"NO_START_OPT)", 7),
        (b"LIMIT_HEAP=", 8),
        (b"LIMIT_MATCH=", 9),
        (b"LIMIT_DEPTH=", 10),
        (b"LIMIT_RECURSION=", 10),
        (b"CR)", 11),
        (b"LF)", 12),
        (b"CRLF)", 13),
        (b"ANY)", 14),
        (b"NUL)", 15),
        (b"ANYCRLF)", 16),
        (b"BSR_ANYCRLF)", 17),
        (b"BSR_UNICODE)", 18),
    ];
    let at = |i: usize| pattern.get(i).copied().unwrap_or(0);
    'pso: while pattern.len() - skip >= 2 && pattern[skip] == b'(' && pattern[skip + 1] == b'*' {
        for &(name, kind) in PSO {
            if pattern.len() - skip - 2 >= name.len() && &pattern[skip + 2..skip + 2 + name.len()] == name {
                skip += name.len() + 2;
                match kind {
                    0 => options |= UTF,
                    1 => options |= UCP,
                    2 => notempty = true,
                    3 => notempty_atstart = true,
                    4 => options |= NO_AUTO_POSSESS,
                    5 => options |= NO_DOTSTAR_ANCHOR,
                    6 => {}
                    7 => options |= NO_START_OPTIMIZE,
                    8..=10 => {
                        let mut c: u32 = 0;
                        let mut pp = skip;
                        if !at(pp).is_ascii_digit() {
                            return Err(CompileError { code: 60, offset: pp });
                        }
                        while at(pp).is_ascii_digit() {
                            if c > u32::MAX / 10 - 1 {
                                break;
                            }
                            c = c * 10 + u32::from(at(pp) - b'0');
                            pp += 1;
                        }
                        let close = at(pp);
                        pp += 1;
                        if close != b')' {
                            return Err(CompileError { code: 60, offset: pp });
                        }
                        match kind {
                            9 => limit_match = Some(c),
                            10 => limit_depth = Some(c),
                            _ => {}
                        }
                        skip = pp;
                    }
                    11 => newline = Newline::Cr,
                    12 => newline = Newline::Lf,
                    13 => newline = Newline::CrLf,
                    14 => newline = Newline::Any,
                    15 => newline = Newline::Nul,
                    16 => newline = Newline::AnyCrLf,
                    17 => bsr = Bsr::AnyCrLf,
                    _ => bsr = Bsr::Unicode,
                }
                continue 'pso;
            }
        }
        break;
    }

    let utf = options & UTF != 0;
    if utf && let Err((code, offset)) = unicode::valid_utf8(pattern) {
        return Err(CompileError { code, offset });
    }

    let mut p = Parser {
        pat: pattern,
        ptr: skip,
        out: Vec::with_capacity(pattern.len() + 8),
        options,
        xoptions,
        utf,
        bracount: 0,
        names: Vec::new(),
        has_lookbehind: false,
        small_ref_offset: [UNSET; 10],
        dupcap_used: false,
        newline,
    };
    p.parse_regex().map_err(|code| CompileError { code, offset: p.ptr })?;
    Ok(Parsed {
        meta: p.out,
        bracount: p.bracount,
        names: p.names,
        has_lookbehind: p.has_lookbehind,
        small_ref_offset: p.small_ref_offset,
        dupcap_used: p.dupcap_used,
        options,
        newline,
        bsr,
        notempty,
        notempty_atstart,
        limit_match,
        limit_depth,
    })
}

struct Parser<'a> {
    pat: &'a [u8],
    ptr: usize,
    out: Vec<u32>,
    options: u32,
    xoptions: u32,
    utf: bool,
    bracount: u32,
    names: Vec<NamedGroup>,
    has_lookbehind: bool,
    small_ref_offset: [usize; 10],
    dupcap_used: bool,
    newline: Newline,
}

type PResult<T> = Result<T, i32>;

fn is_digit(c: u32) -> bool {
    (u32::from(b'0')..=u32::from(b'9')).contains(&c)
}

fn xdigit(c: u32) -> Option<u32> {
    match c {
        0x30..=0x39 => Some(c - 0x30),
        0x41..=0x46 => Some(c - 0x41 + 10),
        0x61..=0x66 => Some(c - 0x61 + 10),
        _ => None,
    }
}

/// `escapes[]` for `'0'..='z'`: positive = literal, negative = `-ESC_x`.
fn escape_table(c: u32) -> i32 {
    const T: [i32; 75] = [
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        b':' as i32,
        b';' as i32,
        b'<' as i32,
        b'=' as i32,
        b'>' as i32,
        b'?' as i32,
        b'@' as i32,
        -(ESC_A as i32),
        -(ESC_B as i32),
        -(ESC_C as i32),
        -(ESC_D as i32),
        -(ESC_E as i32),
        0,
        -(ESC_G as i32),
        -(ESC_H as i32),
        0,
        0,
        -(ESC_K as i32),
        0,
        0,
        -(ESC_N as i32),
        0,
        -(ESC_P as i32),
        -(ESC_Q as i32),
        -(ESC_R as i32),
        -(ESC_S as i32),
        0,
        0,
        -(ESC_V as i32),
        -(ESC_W as i32),
        -(ESC_X as i32),
        0,
        -(ESC_Z as i32),
        b'[' as i32,
        b'\\' as i32,
        b']' as i32,
        b'^' as i32,
        b'_' as i32,
        b'`' as i32,
        7,
        -(ESC_B_LOWER as i32),
        0,
        -(ESC_D_LOWER as i32),
        0x1b,
        0x0c,
        0,
        -(ESC_H_LOWER as i32),
        0,
        0,
        -(ESC_K_LOWER as i32),
        0,
        0,
        0x0a,
        0,
        -(ESC_P_LOWER as i32),
        0,
        0x0d,
        -(ESC_S_LOWER as i32),
        0x09,
        0,
        -(ESC_V_LOWER as i32),
        -(ESC_W_LOWER as i32),
        0,
        0,
        -(ESC_Z_LOWER as i32),
    ];
    T[(c - u32::from(b'0')) as usize]
}

impl<'a> Parser<'a> {
    fn end(&self) -> usize {
        self.pat.len()
    }

    fn byte(&self, i: usize) -> u32 {
        u32::from(self.pat[i])
    }

    /// Byte at `i`, or NUL past the end (as reading a NUL-terminated buffer).
    fn byte0(&self, i: usize) -> u32 {
        self.pat.get(i).map_or(0, |&b| u32::from(b))
    }

    /// `GETCHARINCTEST`: the character at `ptr`, advancing past it.
    fn getchar_at(&self, i: &mut usize) -> u32 {
        if self.utf && self.pat[*i] >= 0xc0 {
            let (c, len) = unicode::utf8_at(self.pat, *i);
            *i += len;
            c
        } else {
            let c = u32::from(self.pat[*i]);
            *i += 1;
            c
        }
    }

    fn push(&mut self, v: u32) {
        self.out.push(v);
    }

    /// `read_number()`: `Ok(Some(n))` a number was read, `Ok(None)` none
    /// found, `Err` with `*ptr` updated.
    fn read_number(&self, ptr: &mut usize, allow_sign: i32, max_value: u32, max_error: i32) -> PResult<Option<i32>> {
        let mut sign = 0;
        let mut n: u32 = 0;
        let mut p = *ptr;
        let mut max_value = max_value;
        if allow_sign >= 0 && p < self.end() {
            if self.byte(p) == u32::from(b'+') {
                sign = 1;
                max_value -= allow_sign as u32;
                p += 1;
            } else if self.byte(p) == u32::from(b'-') {
                sign = -1;
                p += 1;
            }
        }
        if p >= self.end() || !is_digit(self.byte(p)) {
            return Ok(None);
        }
        while p < self.end() && is_digit(self.byte(p)) {
            n = n * 10 + self.byte(p) - u32::from(b'0');
            p += 1;
            if n > max_value {
                *ptr = p;
                return Err(max_error);
            }
        }
        if allow_sign >= 0 && sign != 0 {
            if n == 0 {
                *ptr = p;
                return Err(26);
            }
            if sign > 0 {
                n += allow_sign as u32;
            } else if n as i32 > allow_sign {
                *ptr = p;
                return Err(15);
            } else {
                n = (allow_sign + 1 - n as i32) as u32;
            }
        }
        *ptr = p;
        Ok(Some(n as i32))
    }

    /// `read_repeat_counts()`: `Ok(Some((min, max)))` with `*ptr` after `}`,
    /// `Ok(None)` when this is not a quantifier (`*ptr` unchanged).
    fn read_repeat_counts(&self, ptr: &mut usize) -> PResult<Option<(u32, u32)>> {
        let end = self.end();
        let sp = |q: usize| q < end && (self.pat[q] == b' ' || self.pat[q] == b'\t');
        let mut p = *ptr;
        while sp(p) {
            p += 1;
        }
        let mut pp = p;
        let mut had_minimum = false;
        if pp < end && self.pat[pp].is_ascii_digit() {
            had_minimum = true;
            pp += 1;
            while pp < end && self.pat[pp].is_ascii_digit() {
                pp += 1;
            }
        }
        while sp(pp) {
            pp += 1;
        }
        if pp >= end {
            return Ok(None);
        }
        if self.pat[pp] == b'}' {
            if !had_minimum {
                return Ok(None);
            }
        } else {
            let c = self.pat[pp];
            pp += 1;
            if c != b',' {
                return Ok(None);
            }
            while sp(pp) {
                pp += 1;
            }
            if pp >= end {
                return Ok(None);
            }
            if self.pat[pp].is_ascii_digit() {
                pp += 1;
                while pp < end && self.pat[pp].is_ascii_digit() {
                    pp += 1;
                }
            } else if !had_minimum {
                return Ok(None);
            }
            while sp(pp) {
                pp += 1;
            }
            if pp >= end || self.pat[pp] != b'}' {
                return Ok(None);
            }
        }

        let mut min: i32 = 0;
        let mut max: i32 = REPEAT_UNLIMITED as i32;
        match self.read_number(&mut p, -1, MAX_REPEAT_COUNT, 5) {
            Err(e) => {
                *ptr = p;
                return Err(e);
            }
            Ok(None) => {
                p += 1; // comma
                while sp(p) {
                    p += 1;
                }
                match self.read_number(&mut p, -1, MAX_REPEAT_COUNT, 5) {
                    Err(e) => {
                        *ptr = p;
                        return Err(e);
                    }
                    Ok(Some(m)) => max = m,
                    Ok(None) => {}
                }
            }
            Ok(Some(n)) => {
                min = n;
                while sp(p) {
                    p += 1;
                }
                if p < end && self.pat[p] == b'}' {
                    max = min;
                } else {
                    p += 1;
                    while sp(p) {
                        p += 1;
                    }
                    match self.read_number(&mut p, -1, MAX_REPEAT_COUNT, 5) {
                        Err(e) => {
                            *ptr = p;
                            return Err(e);
                        }
                        Ok(Some(m)) => max = m,
                        Ok(None) => {}
                    }
                    if max < min {
                        *ptr = p;
                        return Err(4);
                    }
                }
            }
        }
        while sp(p) {
            p += 1;
        }
        p += 1;
        *ptr = p;
        Ok(Some((min as u32, max as u32)))
    }

    /// `PRIV(check_escape)()`. `ptr` is after the backslash. Returns
    /// `(escape, c)`: `escape == 0` is a data character `c`, positive an
    /// `ESC_*`, negative a back reference. On error, `*ptr` is the error
    /// position.
    fn check_escape(&self, ptr: &mut usize, isclass: bool) -> PResult<(i32, u32)> {
        let utf = self.utf;
        let end = self.end();
        let mut p = *ptr;
        if p >= end {
            return Err(1);
        }
        let mut c = self.getchar_at(&mut p);
        let mut escape: i32 = 0;

        if !(u32::from(b'0')..=u32::from(b'z')).contains(&c) {
            // literal
        } else {
            let i = escape_table(c);
            if i != 0 {
                if i > 0 {
                    c = i as u32;
                } else {
                    escape = -i;
                    if escape == ESC_N as i32 && p < end && self.pat[p] == b'{' {
                        let mut q = p + 1;
                        while q < end && (self.pat[q] == b' ' || self.pat[q] == b'\t') {
                            q += 1;
                        }
                        if end - q > 1 && self.pat[q] == b'U' && self.pat[q + 1] == b'+' {
                            if utf {
                                p = q + 2;
                                // Continue with \x{ handling (COME_FROM_NU).
                                let r = self.hex_brace(&mut p);
                                *ptr = p;
                                return r.map(|c| (0, c));
                            } else {
                                *ptr = p;
                                return Err(93);
                            }
                        } else {
                            match self.read_repeat_counts(&mut q) {
                                Err(e) => {
                                    *ptr = p;
                                    return Err(e);
                                }
                                Ok(None) => {
                                    *ptr = p;
                                    return Err(37);
                                }
                                Ok(Some(_)) => {}
                            }
                        }
                    }
                }
            } else {
                match c as u8 {
                    b'F' | b'l' | b'L' | b'u' | b'U' => {
                        *ptr = p;
                        return Err(37);
                    }
                    b'g' => {
                        if !isclass {
                            if p >= end {
                                *ptr = p;
                                return Err(57);
                            }
                            if self.pat[p] == b'<' || self.pat[p] == b'\'' {
                                *ptr = p;
                                return Ok((ESC_G_LOWER as i32, c));
                            }
                            let s;
                            if self.pat[p] == b'{' {
                                let mut q = p + 1;
                                while q < end && (self.pat[q] == b' ' || self.pat[q] == b'\t') {
                                    q += 1;
                                }
                                match self.read_number(&mut q, self.bracount as i32, MAX_GROUP_NUMBER, 61) {
                                    Err(e) => {
                                        *ptr = p;
                                        return Err(e);
                                    }
                                    Ok(None) => {
                                        *ptr = p;
                                        return Ok((ESC_K_LOWER as i32, c));
                                    }
                                    Ok(Some(n)) => s = n,
                                }
                                while q < end && (self.pat[q] == b' ' || self.pat[q] == b'\t') {
                                    q += 1;
                                }
                                if q >= end || self.pat[q] != b'}' {
                                    *ptr = p;
                                    return Err(57);
                                }
                                p = q + 1;
                            } else {
                                match self.read_number(&mut p, self.bracount as i32, MAX_GROUP_NUMBER, 61) {
                                    Err(e) => {
                                        *ptr = p;
                                        return Err(e);
                                    }
                                    Ok(None) => {
                                        *ptr = p;
                                        return Err(57);
                                    }
                                    Ok(Some(n)) => s = n,
                                }
                            }
                            if s <= 0 {
                                *ptr = p;
                                return Err(15);
                            }
                            escape = -s;
                        }
                    }
                    b'1'..=b'9' | b'0' => {
                        let mut handled = false;
                        if c != u32::from(b'0') && !isclass {
                            let oldptr = p;
                            let mut q = p - 1;
                            match self.read_number(&mut q, -1, (i32::MAX / 10 - 1) as u32, 0) {
                                Ok(Some(s)) if s < 10 || self.pat[oldptr - 1] >= b'8' || s <= self.bracount as i32 => {
                                    p = q;
                                    if s > MAX_GROUP_NUMBER as i32 {
                                        *ptr = p;
                                        return Err(61);
                                    }
                                    escape = -s;
                                    handled = true;
                                }
                                _ => {}
                            }
                            if !handled {
                                p = oldptr;
                            }
                        }
                        if !handled && c >= u32::from(b'8') {
                            handled = true; // \8 and \9 are literals
                        }
                        if !handled {
                            // Octal: up to three digits in all.
                            c -= u32::from(b'0');
                            let mut i = 0;
                            while i < 2 && p < end && (b'0'..=b'7').contains(&self.pat[p]) {
                                c = c * 8 + u32::from(self.pat[p] - b'0');
                                p += 1;
                                i += 1;
                            }
                            if !utf && c > 0xff {
                                *ptr = p;
                                return Err(51);
                            }
                        }
                    }
                    b'o' => {
                        if p >= end || self.pat[p] != b'{' {
                            *ptr = p;
                            return Err(55);
                        }
                        p += 1;
                        while p < end && (self.pat[p] == b' ' || self.pat[p] == b'\t') {
                            p += 1;
                        }
                        if p >= end || self.pat[p] == b'}' {
                            *ptr = p;
                            return Err(78);
                        }
                        c = 0;
                        let mut overflow = false;
                        while p < end && (b'0'..=b'7').contains(&self.pat[p]) {
                            let cc = u32::from(self.pat[p]);
                            p += 1;
                            if c == 0 && cc == u32::from(b'0') {
                                continue;
                            }
                            c = (c << 3) + (cc - u32::from(b'0'));
                            if c > (if utf { 0x10ffff } else { 0xff }) {
                                overflow = true;
                                break;
                            }
                        }
                        while p < end && (self.pat[p] == b' ' || self.pat[p] == b'\t') {
                            p += 1;
                        }
                        if overflow {
                            while p < end && (b'0'..=b'7').contains(&self.pat[p]) {
                                p += 1;
                            }
                            *ptr = p;
                            return Err(34);
                        } else if p < end && {
                            let b = self.pat[p];
                            p += 1;
                            b == b'}'
                        } {
                            if utf && (0xd800..=0xdfff).contains(&c) {
                                *ptr = p - 1;
                                return Err(73);
                            }
                        } else {
                            *ptr = p - 1;
                            return Err(64);
                        }
                    }
                    b'x' => {
                        if p < end && self.pat[p] == b'{' {
                            p += 1;
                            while p < end && (self.pat[p] == b' ' || self.pat[p] == b'\t') {
                                p += 1;
                            }
                            let r = self.hex_brace(&mut p);
                            *ptr = p;
                            return r.map(|c| (0, c));
                        } else {
                            c = 0;
                            if p < end
                                && let Some(cc) = xdigit(u32::from(self.pat[p]))
                            {
                                p += 1;
                                c = cc;
                                if p < end
                                    && let Some(cc) = xdigit(u32::from(self.pat[p]))
                                {
                                    p += 1;
                                    c = (c << 4) | cc;
                                }
                            }
                        }
                    }
                    b'c' => {
                        if p >= end {
                            *ptr = p;
                            return Err(2);
                        }
                        c = u32::from(self.pat[p]);
                        if (u32::from(b'a')..=u32::from(b'z')).contains(&c) {
                            c -= 32;
                        }
                        if !(32..=126).contains(&c) {
                            *ptr = p;
                            return Err(68);
                        }
                        c ^= 0x40;
                        p += 1;
                    }
                    _ => {
                        *ptr = p - 1;
                        return Err(3);
                    }
                }
            }
        }
        *ptr = p;
        Ok((escape, c))
    }

    /// The `\x{...}` body (also `\N{U+...}`): `ptr` is after `{` and spaces.
    fn hex_brace(&self, ptr: &mut usize) -> PResult<u32> {
        let end = self.end();
        let utf = self.utf;
        let mut p = *ptr;
        if p >= end || self.pat[p] == b'}' {
            *ptr = p;
            return Err(78);
        }
        let mut c: u32 = 0;
        let mut overflow = false;
        while p < end {
            let Some(cc) = xdigit(u32::from(self.pat[p])) else { break };
            p += 1;
            if c == 0 && cc == 0 {
                continue;
            }
            c = (c << 4) | cc;
            if (utf && c > 0x10ffff) || (!utf && c > 0xff) {
                overflow = true;
                break;
            }
        }
        while p < end && (self.pat[p] == b' ' || self.pat[p] == b'\t') {
            p += 1;
        }
        if overflow {
            while p < end && xdigit(u32::from(self.pat[p])).is_some() {
                p += 1;
            }
            *ptr = p;
            return Err(34);
        }
        if p < end && {
            let b = self.pat[p];
            p += 1;
            b == b'}'
        } {
            if utf && (0xd800..=0xdfff).contains(&c) {
                *ptr = p - 1;
                return Err(73);
            }
            *ptr = p;
            Ok(c)
        } else {
            *ptr = p - 1;
            Err(67)
        }
    }

    /// `get_ucp()`: `ptr` is after `p`/`P`. Returns `(negated, ptype, pdata)`.
    fn get_ucp(&self, ptr: &mut usize) -> PResult<(bool, u32, u32)> {
        let end = self.end();
        let mut p = *ptr;
        let mut negated = false;
        let mut name: Vec<u8> = Vec::with_capacity(16);
        let mut vpos: Option<usize> = None;
        let malformed = |p: usize, ptr: &mut usize| {
            *ptr = p;
            Err(46)
        };
        if p >= end {
            return malformed(p, ptr);
        }
        let mut c = u32::from(self.pat[p]);
        p += 1;
        if c == u32::from(b'{') {
            if p >= end {
                return malformed(p, ptr);
            }
            if self.pat[p] == b'^' {
                negated = true;
                p += 1;
            }
            let mut closed = false;
            for _ in 0..49 {
                if p >= end {
                    return malformed(p, ptr);
                }
                c = u32::from(self.pat[p]);
                p += 1;
                while c == u32::from(b'_') || c == u32::from(b'-') || is_c_space(c) {
                    if p >= end {
                        return malformed(p, ptr);
                    }
                    c = u32::from(self.pat[p]);
                    p += 1;
                }
                if c == 0 {
                    return malformed(p, ptr);
                }
                if c == u32::from(b'}') {
                    closed = true;
                    break;
                }
                if (c == u32::from(b':') || c == u32::from(b'=')) && vpos.is_none() {
                    vpos = Some(name.len());
                }
                name.push((c as u8).to_ascii_lowercase());
            }
            if !closed {
                return malformed(p, ptr);
            }
        } else if c < 256 && unicode::ctypes(c) & CTYPE_LETTER != 0 {
            name.push((c as u8).to_ascii_lowercase());
        } else {
            return malformed(p, ptr);
        }
        *ptr = p;

        let mut ptscript = PT_NOTSCRIPT;
        if let Some(v) = vpos {
            let (prop, value) = name.split_at(v);
            let value = &value[1..];
            let mut lookup = Vec::with_capacity(name.len() + 4);
            if prop == b"bidiclass" || prop == b"bc" {
                lookup.extend_from_slice(b"bidi");
            } else if prop == b"script" || prop == b"sc" {
                ptscript = PT_SC;
            } else if prop == b"scriptextensions" || prop == b"scx" {
                ptscript = PT_SCX;
            } else {
                return Err(47);
            }
            lookup.extend_from_slice(value);
            name = lookup;
        }

        let found = ucd::PROPERTY_NAMES.binary_search_by(|(n, _, _)| n.as_bytes().cmp(&name[..]));
        if let Ok(i) = found {
            let (_, ptype, pdata) = ucd::PROPERTY_NAMES[i];
            let (ptype, pdata) = (u32::from(ptype), u32::from(pdata));
            if vpos.is_none() || ptscript == PT_NOTSCRIPT {
                return Ok((negated, ptype, pdata));
            }
            match ptype {
                PT_SC => return Ok((negated, PT_SC, pdata)),
                PT_SCX => return Ok((negated, ptscript, pdata)),
                _ => {}
            }
        }
        Err(47)
    }

    /// `check_posix_syntax()`: `ptr` is at the character after `[`.
    fn check_posix_syntax(&self, ptr: usize) -> Option<usize> {
        let end = self.end();
        let terminator = self.pat[ptr];
        let mut p = ptr + 1;
        while end - p >= 2 {
            if self.pat[p] == b'\\' && (self.pat[p + 1] == b']' || self.pat[p + 1] == b'\\') {
                p += 1;
            } else if (self.pat[p] == b'[' && self.pat[p + 1] == terminator) || self.pat[p] == b']' {
                return None;
            } else if self.pat[p] == terminator && self.pat[p + 1] == b']' {
                return Some(p);
            }
            p += 1;
        }
        None
    }

    /// `read_name()`. `ptr` is at the character before the name (`<`, `'`,
    /// `{`, `(`, `&`, `>`, `=` or `*`). Returns the name's range.
    fn read_name(&self, ptr: &mut usize, terminator: u8) -> PResult<Range<usize>> {
        let end = self.end();
        let mut p = *ptr;
        let is_group = self.pat[p] != b'*';
        p += 1;
        let is_braced = terminator == b'}';
        if is_braced {
            while p < end && (self.pat[p] == b' ' || self.pat[p] == b'\t') {
                p += 1;
            }
        }
        if p >= end {
            *ptr = p;
            return Err(if is_group { 62 } else { 60 });
        }
        let start = p;
        if self.utf && is_group {
            let mut q = p;
            let mut c = self.getchar_at(&mut q);
            let mut t = unicode::chartype(c);
            if t == unicode::ND {
                *ptr = p;
                return Err(44);
            }
            loop {
                if t != unicode::ND && unicode::gentype_of(t) != unicode::GC_L && c != u32::from(b'_') {
                    break;
                }
                p = q;
                if p >= end {
                    break;
                }
                q = p;
                c = self.getchar_at(&mut q);
                t = unicode::chartype(c);
            }
        } else {
            if is_group && self.pat[p].is_ascii_digit() {
                *ptr = p;
                return Err(44);
            }
            while p < end && unicode::ctypes(u32::from(self.pat[p])) & CTYPE_WORD != 0 {
                p += 1;
            }
        }
        if p > start + MAX_NAME_SIZE {
            *ptr = p;
            return Err(48);
        }
        let name = start..p;
        if is_group {
            if p == start {
                *ptr = p;
                return Err(62);
            }
            if is_braced {
                while p < end && (self.pat[p] == b' ' || self.pat[p] == b'\t') {
                    p += 1;
                }
            }
            if p >= end || self.pat[p] != terminator {
                *ptr = p;
                return Err(42);
            }
            p += 1;
        }
        *ptr = p;
        Ok(name)
    }

    /// `handle_escdsw()`
    fn handle_escdsw(&mut self, escape: u32) {
        let (ascii_option, prop) = match escape {
            ESC_D => (X_ASCII_BSD, ESC_P),
            ESC_D_LOWER => (X_ASCII_BSD, ESC_P_LOWER),
            ESC_S => (X_ASCII_BSS, ESC_P),
            ESC_S_LOWER => (X_ASCII_BSS, ESC_P_LOWER),
            ESC_W => (X_ASCII_BSW, ESC_P),
            _ => (X_ASCII_BSW, ESC_P_LOWER),
        };
        if self.options & UCP == 0 || self.xoptions & ascii_option != 0 {
            self.push(META_ESCAPE + escape);
        } else {
            self.push(META_ESCAPE + prop);
            match escape {
                ESC_D | ESC_D_LOWER => self.push((PT_PC << 16) | unicode::ND),
                ESC_S | ESC_S_LOWER => self.push(PT_SPACE << 16),
                _ => self.push(PT_WORD << 16),
            }
        }
    }

    /// `IS_NEWLINE` for `#` comments in extended mode; returns the length.
    fn newline_len(&self, p: usize) -> Option<usize> {
        let b = self.pat[p];
        match self.newline {
            Newline::Lf => (b == b'\n').then_some(1),
            Newline::Cr => (b == b'\r').then_some(1),
            Newline::Nul => (b == 0).then_some(1),
            Newline::CrLf => (b == b'\r' && self.pat.get(p + 1) == Some(&b'\n')).then_some(2),
            Newline::AnyCrLf => match b {
                b'\n' => Some(1),
                b'\r' => Some(if self.pat.get(p + 1) == Some(&b'\n') { 2 } else { 1 }),
                _ => None,
            },
            Newline::Any => {
                let (c, len) = if self.utf { unicode::utf8_at(self.pat, p) } else { (u32::from(b), 1) };
                match c {
                    0x0a..=0x0c => Some(len),
                    0x0d => Some(if self.pat.get(p + 1) == Some(&b'\n') { 2 } else { 1 }),
                    0x85 | 0x2028 | 0x2029 => Some(len),
                    _ => None,
                }
            }
        }
    }

    fn add_name(&mut self, name: Range<usize>) -> PResult<()> {
        if self.bracount >= MAX_GROUP_NUMBER {
            return Err(97);
        }
        self.bracount += 1;
        self.push(META_CAPTURE | self.bracount);
        if self.names.len() >= MAX_NAME_COUNT {
            return Err(49);
        }
        let mut isdupname = false;
        let mut i = 0;
        while i < self.names.len() {
            let ng = &self.names[i];
            if self.pat[ng.name.clone()] == self.pat[name.clone()] {
                if ng.number == self.bracount {
                    break;
                }
                if self.options & DUPNAMES == 0 {
                    return Err(43);
                }
                isdupname = true;
                self.names[i].isdup = true;
            } else if ng.number == self.bracount {
                return Err(65);
            }
            i += 1;
        }
        if i < self.names.len() {
            return Ok(());
        }
        self.names.push(NamedGroup { name, number: self.bracount, isdup: isdupname });
        Ok(())
    }

    fn parse_regex(&mut self) -> PResult<()> {
        let end = self.end();
        let mut nest: Vec<NestSave> = Vec::new();
        let mut nest_depth: u32 = 0;
        let mut inescq = false;
        let mut inverbname = false;
        let mut verblengthptr = 0usize;
        let mut verbnamestart = 0usize;
        let mut add_after_mark: u32 = 0;
        let mut meta_quantifier: u32 = 0;
        let mut okquantifier = false;
        let mut expect_cond_assert: i32 = 0;
        let mut after_manual_callout: i32 = 0;
        let mut this_parsed_item = 0usize;
        let mut prev_parsed_item = 0usize;
        let mut verbstartptr = 0usize;

        if self.options & EXTENDED_MORE != 0 {
            self.options |= EXTENDED;
        }

        while self.ptr < end {
            if nest_depth > PARENS_NEST_LIMIT {
                return Err(19);
            }
            if this_parsed_item != self.out.len() {
                prev_parsed_item = this_parsed_item;
                this_parsed_item = self.out.len();
            }

            let thisptr = self.ptr;
            let _ = thisptr;
            let mut c = {
                let mut p = self.ptr;
                let c = self.getchar_at(&mut p);
                self.ptr = p;
                c
            };

            if inescq {
                if c == u32::from(b'\\') && self.ptr < end && self.pat[self.ptr] == b'E' {
                    inescq = false;
                    self.ptr += 1;
                } else {
                    if expect_cond_assert > 0 {
                        self.ptr -= 1;
                        return Err(28);
                    }
                    if inverbname {
                        self.push(c);
                    } else {
                        after_manual_callout -= 1;
                        self.push(c);
                        okquantifier = true;
                    }
                    meta_quantifier = 0;
                }
                continue;
            }

            if inverbname
                && ((self.options & (EXTENDED | ALT_VERBNAMES)) != (EXTENDED | ALT_VERBNAMES)
                    || (c > 255 && (c | 1) != 0x200f && (c | 1) != 0x2029)
                    || (c < 256 && c != u32::from(b'#') && unicode::ctypes(c) & CTYPE_SPACE == 0 && c != 0x85))
            {
                match c {
                    0x29 => {
                        inverbname = false;
                        let verbnamelength = self.out.len() - verblengthptr - 1;
                        if self.ptr - verbnamestart - 1 > MAX_MARK {
                            self.ptr -= 1;
                            return Err(76);
                        }
                        self.out[verblengthptr] = verbnamelength as u32;
                        if add_after_mark != 0 {
                            self.push(add_after_mark);
                            add_after_mark = 0;
                        }
                    }
                    0x5c if self.options & ALT_VERBNAMES != 0 => {
                        let mut p = self.ptr;
                        let r = self.check_escape(&mut p, false);
                        self.ptr = p;
                        let (escape, ch) = r?;
                        match escape as u32 {
                            0 => self.push(ch),
                            ESC_UB => {
                                self.push(u32::from(b'u'));
                                self.push(u32::from(b'{'));
                                okquantifier = true;
                            }
                            ESC_Q => inescq = true,
                            ESC_E => {}
                            _ => return Err(40),
                        }
                    }
                    _ => self.push(c),
                }
                continue;
            }

            if c == u32::from(b'\\') && self.ptr < end && (self.pat[self.ptr] == b'Q' || self.pat[self.ptr] == b'E') {
                inescq = self.pat[self.ptr] == b'Q';
                self.ptr += 1;
                continue;
            }

            if self.options & EXTENDED != 0 {
                if c < 256 && unicode::ctypes(c) & CTYPE_SPACE != 0 {
                    continue;
                }
                if c == 0x85 || (c | 1) == 0x200f || (c | 1) == 0x2029 {
                    continue;
                }
                if c == u32::from(b'#') {
                    while self.ptr < end {
                        if let Some(len) = self.newline_len(self.ptr) {
                            self.ptr += len;
                            break;
                        }
                        self.ptr += 1;
                        if self.utf {
                            while self.ptr < end && (self.pat[self.ptr] & 0xc0) == 0x80 {
                                self.ptr += 1;
                            }
                        }
                    }
                    continue;
                }
            }

            if c == u32::from(b'(')
                && end - self.ptr >= 2
                && self.pat[self.ptr] == b'?'
                && self.pat[self.ptr + 1] == b'#'
            {
                loop {
                    self.ptr += 1;
                    if self.ptr >= end || self.pat[self.ptr] == b')' {
                        break;
                    }
                }
                if self.ptr >= end {
                    return Err(18);
                }
                self.ptr += 1;
                continue;
            }

            // Not a quantifier: start a new item (callout bookkeeping).
            let is_quant_start = c == u32::from(b'*')
                || c == u32::from(b'+')
                || c == u32::from(b'?')
                || (c == u32::from(b'{') && {
                    let mut t = self.ptr;
                    matches!(self.read_repeat_counts(&mut t), Ok(Some(_)))
                });
            if !is_quant_start {
                let prev = after_manual_callout;
                after_manual_callout -= 1;
                if prev <= 0 {
                    this_parsed_item = self.out.len();
                }
            }

            if expect_cond_assert > 0 {
                let p = self.ptr;
                let mut ok = c == u32::from(b'(') && end - p >= 3 && (self.pat[p] == b'?' || self.pat[p] == b'*');
                if ok {
                    if self.pat[p] == b'*' {
                        ok = unicode::ctypes(u32::from(self.pat[p + 1])) & CTYPE_LCLETTER != 0;
                    } else {
                        ok = match self.pat[p + 1] {
                            b'C' => expect_cond_assert == 2,
                            b'=' | b'!' => true,
                            b'<' => self.pat[p + 2] == b'=' || self.pat[p + 2] == b'!',
                            _ => false,
                        };
                    }
                }
                if !ok {
                    self.ptr -= 1;
                    return Err(28);
                }
            }

            let prev_expect_cond_assert = expect_cond_assert;
            expect_cond_assert = 0;
            let prev_okquantifier = okquantifier;
            let prev_meta_quantifier = meta_quantifier;
            okquantifier = false;
            meta_quantifier = 0;

            if prev_meta_quantifier != 0 && (c == u32::from(b'?') || c == u32::from(b'+')) {
                let idx = self.out.len() - if prev_meta_quantifier == META_MINMAX { 3 } else { 1 };
                self.out[idx] = prev_meta_quantifier + if c == u32::from(b'?') { 0x0002_0000 } else { 0x0001_0000 };
                continue;
            }

            match c {
                0x5c => {
                    // Escape sequence
                    let mut p = self.ptr;
                    let r = self.check_escape(&mut p, false);
                    self.ptr = p;
                    let (escape, ch) = r?;
                    if escape == 0 {
                        self.push(ch);
                        okquantifier = true;
                    } else if escape < 0 {
                        let offset = self.ptr - 1;
                        let n = (-escape) as u32;
                        self.push(META_BACKREF | n);
                        if n < 10 {
                            if self.small_ref_offset[n as usize] == UNSET {
                                self.small_ref_offset[n as usize] = offset;
                            }
                        } else {
                            self.push(offset as u32);
                        }
                        okquantifier = true;
                    } else {
                        let escape = escape as u32;
                        match escape {
                            ESC_C => {
                                if self.options & NEVER_BACKSLASH_C != 0 {
                                    return Err(83);
                                }
                                okquantifier = true;
                                self.push(META_ESCAPE + escape);
                            }
                            ESC_UB => {
                                self.push(u32::from(b'u'));
                                self.push(u32::from(b'{'));
                                okquantifier = true;
                            }
                            ESC_X | ESC_H | ESC_H_LOWER | ESC_N | ESC_R | ESC_V | ESC_V_LOWER => {
                                okquantifier = true;
                                self.push(META_ESCAPE + escape);
                            }
                            ESC_D | ESC_D_LOWER | ESC_S | ESC_S_LOWER | ESC_W | ESC_W_LOWER => {
                                okquantifier = true;
                                self.handle_escdsw(escape);
                            }
                            ESC_P | ESC_P_LOWER => {
                                let mut p = self.ptr;
                                let r = self.get_ucp(&mut p);
                                self.ptr = p;
                                let (negated, ptype, pdata) = r?;
                                let escape =
                                    if negated { if escape == ESC_P { ESC_P_LOWER } else { ESC_P } } else { escape };
                                self.push(META_ESCAPE + escape);
                                self.push((ptype << 16) | pdata);
                                okquantifier = true;
                            }
                            ESC_G_LOWER | ESC_K_LOWER => {
                                if self.ptr >= end
                                    || (self.pat[self.ptr] != b'{'
                                        && self.pat[self.ptr] != b'<'
                                        && self.pat[self.ptr] != b'\'')
                                {
                                    return Err(if escape == ESC_G_LOWER { 57 } else { 69 });
                                }
                                let terminator = match self.pat[self.ptr] {
                                    b'<' => b'>',
                                    b'\'' => b'\'',
                                    _ => b'}',
                                };
                                if escape == ESC_G_LOWER && terminator != b'}' {
                                    let mut q = self.ptr + 1;
                                    match self.read_number(&mut q, self.bracount as i32, MAX_GROUP_NUMBER, 61) {
                                        Ok(Some(i)) => {
                                            if q >= end || self.pat[q] != terminator {
                                                return Err(57);
                                            }
                                            self.ptr = q;
                                            // SET_RECURSION
                                            self.push(META_RECURSE | i as u32);
                                            let offset = self.ptr;
                                            self.ptr += 1;
                                            self.push(offset as u32);
                                            okquantifier = true;
                                            meta_quantifier = 0;
                                            let _ = prev_okquantifier;
                                            continue;
                                        }
                                        Err(e) => return Err(e),
                                        Ok(None) => {}
                                    }
                                }
                                let mut p = self.ptr;
                                let r = self.read_name(&mut p, terminator);
                                self.ptr = p;
                                let name = r?;
                                self.push(if escape == ESC_K_LOWER || terminator == b'}' {
                                    META_BACKREF_BYNAME
                                } else {
                                    META_RECURSE_BYNAME
                                });
                                self.push(name.len() as u32);
                                self.push(name.start as u32);
                                okquantifier = true;
                            }
                            _ => self.push(META_ESCAPE + escape),
                        }
                    }
                }
                0x5e => self.push(META_CIRCUMFLEX),
                0x24 => self.push(META_DOLLAR),
                0x2e => {
                    self.push(META_DOT);
                    okquantifier = true;
                }
                0x2a | 0x2b | 0x3f | 0x7b => {
                    let mut minmax = None;
                    if c == 0x7b {
                        let mut p = self.ptr;
                        match self.read_repeat_counts(&mut p) {
                            Err(e) => {
                                self.ptr = p;
                                return Err(e);
                            }
                            Ok(None) => {
                                self.push(c);
                                okquantifier = true;
                                continue;
                            }
                            Ok(Some(mm)) => {
                                self.ptr = p;
                                minmax = Some(mm);
                            }
                        }
                    }
                    meta_quantifier = match c {
                        0x2a => META_ASTERISK,
                        0x2b => META_PLUS,
                        0x3f => META_QUERY,
                        _ => META_MINMAX,
                    };
                    if !prev_okquantifier {
                        self.ptr -= 1;
                        return Err(9);
                    }
                    if self.out.get(prev_parsed_item) == Some(&META_ACCEPT) {
                        // Wrap a quantified (*ACCEPT) in non-capturing brackets.
                        self.out.insert(verbstartptr, META_NOCAPTURE);
                        self.push(META_KET);
                    }
                    self.push(meta_quantifier);
                    if let Some((min, max)) = minmax {
                        self.push(min);
                        self.push(max);
                    }
                }
                0x5b => {
                    okquantifier = true;
                    self.parse_class(&mut inescq)?;
                }
                0x28 => {
                    if self.ptr >= end {
                        return Err(14);
                    }
                    if self.pat[self.ptr] != b'?' {
                        if self.pat[self.ptr] != b'*' {
                            nest_depth += 1;
                            if self.options & NO_AUTO_CAPTURE == 0 {
                                if self.bracount >= MAX_GROUP_NUMBER {
                                    return Err(97);
                                }
                                self.bracount += 1;
                                self.push(META_CAPTURE | self.bracount);
                            } else {
                                self.push(META_NOCAPTURE);
                            }
                        } else if end - self.ptr <= 1 || {
                            c = u32::from(self.pat[self.ptr + 1]);
                            c == u32::from(b')')
                        } {
                            // (* followed by end or ): gives a quantifier error later.
                        } else if c < 256 && unicode::ctypes(c) & CTYPE_LCLETTER != 0 {
                            // Alpha assertion
                            let mut p = self.ptr;
                            let r = self.read_name(&mut p, 0);
                            self.ptr = p;
                            let name = r?;
                            if self.ptr >= end || self.pat[self.ptr] != b':' {
                                return Err(95);
                            }
                            const ALAS: &[(&[u8], u32)] = &[
                                (b"pla", META_LOOKAHEAD),
                                (b"plb", META_LOOKBEHIND),
                                (b"napla", META_LOOKAHEAD_NA),
                                (b"naplb", META_LOOKBEHIND_NA),
                                (b"nla", META_LOOKAHEADNOT),
                                (b"nlb", META_LOOKBEHINDNOT),
                                (b"positive_lookahead", META_LOOKAHEAD),
                                (b"positive_lookbehind", META_LOOKBEHIND),
                                (b"non_atomic_positive_lookahead", META_LOOKAHEAD_NA),
                                (b"non_atomic_positive_lookbehind", META_LOOKBEHIND_NA),
                                (b"negative_lookahead", META_LOOKAHEADNOT),
                                (b"negative_lookbehind", META_LOOKBEHINDNOT),
                                (b"atomic", META_ATOMIC),
                                (b"sr", META_SCRIPT_RUN),
                                (b"asr", META_ATOMIC_SCRIPT_RUN),
                                (b"script_run", META_SCRIPT_RUN),
                                (b"atomic_script_run", META_ATOMIC_SCRIPT_RUN),
                            ];
                            let Some(&(_, meta)) = ALAS.iter().find(|(n, _)| *n == &self.pat[name.clone()]) else {
                                return Err(95);
                            };
                            if prev_expect_cond_assert > 0 && !(META_LOOKAHEAD..=META_LOOKBEHINDNOT).contains(&meta) {
                                return Err(if meta == META_LOOKAHEAD_NA || meta == META_LOOKBEHIND_NA {
                                    98
                                } else {
                                    28
                                });
                            }
                            match meta {
                                META_ATOMIC => {
                                    self.push(META_ATOMIC);
                                    nest_depth += 1;
                                    self.ptr += 1;
                                }
                                META_LOOKAHEAD | META_LOOKAHEAD_NA | META_LOOKAHEADNOT => {
                                    self.push(meta);
                                    self.ptr += 1;
                                    self.post_assertion(&mut nest, &mut nest_depth, prev_expect_cond_assert)?;
                                }
                                META_LOOKBEHIND | META_LOOKBEHINDNOT | META_LOOKBEHIND_NA => {
                                    self.push(meta);
                                    self.ptr -= 1;
                                    self.post_lookbehind();
                                    self.post_assertion(&mut nest, &mut nest_depth, prev_expect_cond_assert)?;
                                }
                                _ => {
                                    // Script runs
                                    self.push(META_SCRIPT_RUN);
                                    nest_depth += 1;
                                    self.ptr += 1;
                                    if meta == META_ATOMIC_SCRIPT_RUN {
                                        self.push(META_ATOMIC);
                                        if nest.len() >= NEST_SAVE_LIMIT {
                                            return Err(84);
                                        }
                                        nest.push(NestSave {
                                            nest_depth,
                                            reset_group: 0,
                                            max_group: 0,
                                            flags: NSF_ATOMICSR,
                                            options: self.options & PARSE_TRACKED_OPTIONS,
                                            xoptions: self.xoptions & PARSE_TRACKED_EXTRA_OPTIONS,
                                        });
                                    }
                                }
                            }
                        } else {
                            // (*VERB) and (*VERB:NAME)
                            let mut p = self.ptr;
                            let r = self.read_name(&mut p, 0);
                            self.ptr = p;
                            let name = r?;
                            if self.ptr >= end || (self.pat[self.ptr] != b':' && self.pat[self.ptr] != b')') {
                                return Err(60);
                            }
                            // name, META, has_arg
                            const VERBS: &[(&[u8], u32, i32)] = &[
                                (b"", META_MARK, 1),
                                (b"MARK", META_MARK, 1),
                                (b"ACCEPT", META_ACCEPT, -1),
                                (b"F", META_FAIL, -1),
                                (b"FAIL", META_FAIL, -1),
                                (b"COMMIT", META_COMMIT, 0),
                                (b"PRUNE", META_PRUNE, 0),
                                (b"SKIP", META_SKIP, 0),
                                (b"THEN", META_THEN, 0),
                            ];
                            let Some(&(_, vmeta, has_arg)) =
                                VERBS.iter().find(|(n, _, _)| *n == &self.pat[name.clone()])
                            else {
                                return Err(60);
                            };
                            if self.pat[self.ptr] == b':' && self.ptr + 1 < end && self.pat[self.ptr + 1] == b')' {
                                self.ptr += 1;
                            }
                            if has_arg > 0 && self.pat[self.ptr] != b':' {
                                return Err(66);
                            }
                            verbstartptr = self.out.len();
                            okquantifier = vmeta == META_ACCEPT;
                            let ch = self.pat[self.ptr];
                            self.ptr += 1;
                            if ch == b':' {
                                if has_arg < 0 {
                                    add_after_mark = vmeta;
                                    self.push(META_MARK);
                                } else {
                                    self.push(vmeta + if vmeta != META_MARK { 0x0001_0000 } else { 0 });
                                }
                                verblengthptr = self.out.len();
                                self.push(0);
                                verbnamestart = self.ptr;
                                inverbname = true;
                            } else {
                                self.push(vmeta);
                            }
                        }
                        continue;
                    }

                    // Items starting (?
                    self.ptr += 1;
                    if self.ptr >= end {
                        return Err(14);
                    }
                    let ch = self.pat[self.ptr];
                    match ch {
                        b'P' => {
                            self.ptr += 1;
                            if self.ptr >= end {
                                return Err(14);
                            }
                            match self.pat[self.ptr] {
                                b'<' => {
                                    self.define_name(b'>', &mut nest_depth)?;
                                }
                                b'>' => {
                                    self.recurse_by_name()?;
                                    okquantifier = true;
                                }
                                b'=' => {
                                    let mut p = self.ptr;
                                    let r = self.read_name(&mut p, b')');
                                    self.ptr = p;
                                    let name = r?;
                                    self.push(META_BACKREF_BYNAME);
                                    self.push(name.len() as u32);
                                    self.push(name.start as u32);
                                    okquantifier = true;
                                }
                                _ => return Err(41),
                            }
                        }
                        b'R' => {
                            self.ptr += 1;
                            if self.ptr >= end || self.pat[self.ptr] != b')' {
                                return Err(58);
                            }
                            self.set_recursion(0);
                            okquantifier = true;
                        }
                        b'+' | b'0'..=b'9' => {
                            if ch == b'+' && (end - self.ptr < 2 || !self.pat[self.ptr + 1].is_ascii_digit()) {
                                return Err(29);
                            }
                            self.recursion_by_number()?;
                            okquantifier = true;
                        }
                        b'-' if end - self.ptr > 1 && self.pat[self.ptr + 1].is_ascii_digit() => {
                            self.recursion_by_number()?;
                            okquantifier = true;
                        }
                        b'&' => {
                            self.recurse_by_name()?;
                            okquantifier = true;
                        }
                        b'C' => {
                            self.ptr += 1;
                            if self.ptr >= end {
                                return Err(14);
                            }
                            expect_cond_assert = prev_expect_cond_assert - 1;
                            after_manual_callout = 1;
                            let cstart = self.ptr;
                            if self.pat[self.ptr] != b')' && !self.pat[self.ptr].is_ascii_digit() {
                                const START: &[u8] = b"`'\"^%#${";
                                const END: &[u8] = b"`'\"^%#$}";
                                let Some(k) = START.iter().position(|&d| d == self.pat[self.ptr]) else {
                                    return Err(82);
                                };
                                let delimiter = END[k];
                                self.push(META_CALLOUT_STRING);
                                self.push(0);
                                self.push(0);
                                loop {
                                    self.ptr += 1;
                                    if self.ptr >= end {
                                        self.ptr = cstart;
                                        return Err(81);
                                    }
                                    if self.pat[self.ptr] == delimiter {
                                        self.ptr += 1;
                                        if self.ptr >= end || self.pat[self.ptr] != delimiter {
                                            break;
                                        }
                                    }
                                }
                                self.push((self.ptr - cstart) as u32);
                                self.push(cstart as u32);
                            } else {
                                let mut n = 0u32;
                                self.push(META_CALLOUT_NUMBER);
                                self.push(0);
                                self.push(0);
                                while self.ptr < end && self.pat[self.ptr].is_ascii_digit() {
                                    n = n * 10 + u32::from(self.pat[self.ptr] - b'0');
                                    self.ptr += 1;
                                    if n > 255 {
                                        return Err(38);
                                    }
                                }
                                self.push(n);
                            }
                            if self.ptr >= end || self.pat[self.ptr] != b')' {
                                return Err(39);
                            }
                            self.ptr += 1;
                        }
                        b'(' => {
                            self.ptr += 1;
                            if self.ptr >= end {
                                return Err(14);
                            }
                            nest_depth += 1;
                            if self.pat[self.ptr] == b'?' || self.pat[self.ptr] == b'*' {
                                self.push(META_COND_ASSERT);
                                self.ptr -= 1;
                                expect_cond_assert = 2;
                                continue;
                            }
                            let mut q = self.ptr;
                            match self.read_number(&mut q, self.bracount as i32, MAX_GROUP_NUMBER, 61) {
                                Err(e) => {
                                    self.ptr = q;
                                    return Err(e);
                                }
                                Ok(Some(i)) => {
                                    self.ptr = q;
                                    if i <= 0 {
                                        return Err(15);
                                    }
                                    self.push(META_COND_NUMBER);
                                    self.push((self.ptr - 2) as u32);
                                    self.push(i as u32);
                                }
                                Ok(None) => {
                                    let p = self.ptr;
                                    if end - p >= 10 && &self.pat[p..p + 7] == b"VERSION" && self.pat[p + 7] != b')' {
                                        self.parse_version_condition()?;
                                    } else {
                                        let mut was_r_ampersand = false;
                                        let terminator;
                                        if self.pat[p] == b'R' && end - p > 1 && self.pat[p + 1] == b'&' {
                                            terminator = b')';
                                            was_r_ampersand = true;
                                            self.ptr += 1;
                                        } else if self.pat[p] == b'<' {
                                            terminator = b'>';
                                        } else if self.pat[p] == b'\'' {
                                            terminator = b'\'';
                                        } else {
                                            terminator = b')';
                                            self.ptr -= 1;
                                        }
                                        let mut pp = self.ptr;
                                        let r = self.read_name(&mut pp, terminator);
                                        self.ptr = pp;
                                        let name = r?;
                                        let code;
                                        if was_r_ampersand {
                                            code = META_COND_RNAME;
                                            self.ptr -= 1;
                                        } else if terminator == b')' {
                                            let n = &self.pat[name.clone()];
                                            if n == b"DEFINE" {
                                                code = META_COND_DEFINE;
                                            } else {
                                                let all_digits = n.len() > 1 && n[1..].iter().all(u8::is_ascii_digit);
                                                code = if n[0] == b'R' && (n.len() == 1 || all_digits) {
                                                    META_COND_RNUMBER
                                                } else {
                                                    META_COND_NAME
                                                };
                                            }
                                            self.ptr -= 1;
                                        } else {
                                            code = META_COND_NAME;
                                        }
                                        self.push(code);
                                        if code != META_COND_DEFINE {
                                            self.push(name.len() as u32);
                                        }
                                        self.push(name.start as u32);
                                    }
                                }
                            }
                            if self.ptr >= end || self.pat[self.ptr] != b')' {
                                return Err(24);
                            }
                            self.ptr += 1;
                        }
                        b'>' => {
                            self.push(META_ATOMIC);
                            nest_depth += 1;
                            self.ptr += 1;
                        }
                        b'=' | b'*' | b'!' => {
                            self.push(match ch {
                                b'=' => META_LOOKAHEAD,
                                b'*' => META_LOOKAHEAD_NA,
                                _ => META_LOOKAHEADNOT,
                            });
                            self.ptr += 1;
                            self.post_assertion(&mut nest, &mut nest_depth, prev_expect_cond_assert)?;
                        }
                        b'<' => {
                            if end - self.ptr <= 1
                                || (self.pat[self.ptr + 1] != b'='
                                    && self.pat[self.ptr + 1] != b'!'
                                    && self.pat[self.ptr + 1] != b'*')
                            {
                                self.define_name(b'>', &mut nest_depth)?;
                            } else {
                                self.push(match self.pat[self.ptr + 1] {
                                    b'=' => META_LOOKBEHIND,
                                    b'!' => META_LOOKBEHINDNOT,
                                    _ => META_LOOKBEHIND_NA,
                                });
                                self.post_lookbehind();
                                self.post_assertion(&mut nest, &mut nest_depth, prev_expect_cond_assert)?;
                            }
                        }
                        b'\'' => {
                            self.define_name(b'\'', &mut nest_depth)?;
                        }
                        _ => {
                            // (?| or option settings
                            nest_depth += 1;
                            if nest.len() >= NEST_SAVE_LIMIT {
                                return Err(84);
                            }
                            let mut ns = NestSave {
                                nest_depth,
                                reset_group: 0,
                                max_group: 0,
                                flags: 0,
                                options: self.options & PARSE_TRACKED_OPTIONS,
                                xoptions: self.xoptions & PARSE_TRACKED_EXTRA_OPTIONS,
                            };
                            if ch == b'|' {
                                ns.reset_group = self.bracount;
                                ns.max_group = self.bracount;
                                ns.flags |= NSF_RESET;
                                self.dupcap_used = true;
                                nest.push(ns);
                                self.push(META_NOCAPTURE);
                                self.ptr += 1;
                            } else {
                                nest.push(ns);
                                let mut hyphenok = true;
                                let oldoptions = self.options;
                                let oldxoptions = self.xoptions;
                                let (mut set, mut unset, mut xset, mut xunset) = (0u32, 0u32, 0u32, 0u32);
                                let mut unsetting = false;
                                if self.ptr < end && self.pat[self.ptr] == b'^' {
                                    self.options &=
                                        !(CASELESS | MULTILINE | NO_AUTO_CAPTURE | DOTALL | EXTENDED | EXTENDED_MORE);
                                    self.xoptions &= !X_CASELESS_RESTRICT;
                                    hyphenok = false;
                                    self.ptr += 1;
                                }
                                while self.ptr < end && self.pat[self.ptr] != b')' && self.pat[self.ptr] != b':' {
                                    let o = self.pat[self.ptr];
                                    self.ptr += 1;
                                    let (optset, xoptset) =
                                        if unsetting { (&mut unset, &mut xunset) } else { (&mut set, &mut xset) };
                                    match o {
                                        b'-' => {
                                            if !hyphenok {
                                                self.ptr -= 1;
                                                return Err(94);
                                            }
                                            unsetting = true;
                                            hyphenok = false;
                                        }
                                        b'a' => {
                                            let next = self.pat.get(self.ptr).copied();
                                            match next {
                                                Some(b'D') => {
                                                    *xoptset |= X_ASCII_BSD;
                                                    self.ptr += 1;
                                                }
                                                Some(b'P') => {
                                                    *xoptset |= X_ASCII_POSIX | X_ASCII_DIGIT;
                                                    self.ptr += 1;
                                                }
                                                Some(b'S') => {
                                                    *xoptset |= X_ASCII_BSS;
                                                    self.ptr += 1;
                                                }
                                                Some(b'T') => {
                                                    *xoptset |= X_ASCII_DIGIT;
                                                    self.ptr += 1;
                                                }
                                                Some(b'W') => {
                                                    *xoptset |= X_ASCII_BSW;
                                                    self.ptr += 1;
                                                }
                                                _ => {
                                                    *xoptset |= X_ASCII_BSD
                                                        | X_ASCII_BSS
                                                        | X_ASCII_BSW
                                                        | X_ASCII_DIGIT
                                                        | X_ASCII_POSIX
                                                }
                                            }
                                        }
                                        b'J' => *optset |= DUPNAMES,
                                        b'i' => *optset |= CASELESS,
                                        b'm' => *optset |= MULTILINE,
                                        b'n' => *optset |= NO_AUTO_CAPTURE,
                                        b'r' => *xoptset |= X_CASELESS_RESTRICT,
                                        b's' => *optset |= DOTALL,
                                        b'U' => *optset |= UNGREEDY,
                                        b'x' => {
                                            *optset |= EXTENDED;
                                            if self.ptr < end && self.pat[self.ptr] == b'x' {
                                                *optset |= EXTENDED_MORE;
                                                self.ptr += 1;
                                            }
                                        }
                                        _ => {
                                            self.ptr -= 1;
                                            return Err(11);
                                        }
                                    }
                                }
                                if (set & (EXTENDED | EXTENDED_MORE)) == EXTENDED || (unset & EXTENDED) != 0 {
                                    unset |= EXTENDED_MORE;
                                }
                                self.options = (self.options | set) & !unset;
                                self.xoptions = (self.xoptions | xset) & !xunset;
                                if self.ptr >= end {
                                    return Err(14);
                                }
                                let close = self.pat[self.ptr];
                                self.ptr += 1;
                                if close == b')' {
                                    nest_depth -= 1;
                                    let n = nest.len();
                                    if n >= 2 && nest[n - 2].nest_depth == nest_depth {
                                        nest.pop();
                                    } else {
                                        nest[n - 1].nest_depth = nest_depth;
                                    }
                                } else {
                                    self.push(META_NOCAPTURE);
                                }
                                if self.options != oldoptions || self.xoptions != oldxoptions {
                                    self.push(META_OPTIONS);
                                    self.push(self.options);
                                    self.push(self.xoptions);
                                }
                            }
                        }
                    }
                }
                0x7c => {
                    if let Some(top) = nest.last_mut()
                        && top.nest_depth == nest_depth
                        && top.flags & NSF_RESET != 0
                    {
                        if self.bracount > top.max_group {
                            top.max_group = self.bracount;
                        }
                        self.bracount = top.reset_group;
                    }
                    self.push(META_ALT);
                }
                0x29 => {
                    okquantifier = true;
                    if let Some(top) = nest.last()
                        && top.nest_depth == nest_depth
                    {
                        self.options = (self.options & !PARSE_TRACKED_OPTIONS) | top.options;
                        self.xoptions = (self.xoptions & !PARSE_TRACKED_EXTRA_OPTIONS) | top.xoptions;
                        if top.flags & NSF_RESET != 0 && top.max_group > self.bracount {
                            self.bracount = top.max_group;
                        }
                        if top.flags & NSF_CONDASSERT != 0 {
                            okquantifier = false;
                        }
                        if top.flags & NSF_ATOMICSR != 0 {
                            self.push(META_KET);
                        }
                        nest.pop();
                    }
                    if nest_depth == 0 {
                        self.ptr -= 1;
                        return Err(22);
                    }
                    nest_depth -= 1;
                    self.push(META_KET);
                }
                _ => {
                    self.push(c);
                    okquantifier = true;
                }
            }
        }

        if inverbname && self.ptr >= end {
            return Err(60);
        }
        self.push(META_END);
        if nest_depth == 0 { Ok(()) } else { Err(14) }
    }

    fn post_lookbehind(&mut self) {
        self.has_lookbehind = true;
        let offset = self.ptr - 2;
        self.push(offset as u32);
        self.ptr += 2;
    }

    fn post_assertion(&mut self, nest: &mut Vec<NestSave>, nest_depth: &mut u32, prev_expect: i32) -> PResult<()> {
        *nest_depth += 1;
        if prev_expect > 0 {
            if nest.len() >= NEST_SAVE_LIMIT {
                return Err(84);
            }
            nest.push(NestSave {
                nest_depth: *nest_depth,
                reset_group: 0,
                max_group: 0,
                flags: NSF_CONDASSERT,
                options: self.options & PARSE_TRACKED_OPTIONS,
                xoptions: self.xoptions & PARSE_TRACKED_EXTRA_OPTIONS,
            });
        }
        Ok(())
    }

    fn define_name(&mut self, terminator: u8, nest_depth: &mut u32) -> PResult<()> {
        let mut p = self.ptr;
        let r = self.read_name(&mut p, terminator);
        self.ptr = p;
        let name = r?;
        let r = self.add_name(name);
        *nest_depth += 1;
        r
    }

    fn recurse_by_name(&mut self) -> PResult<()> {
        let mut p = self.ptr;
        let r = self.read_name(&mut p, b')');
        self.ptr = p;
        let name = r?;
        self.push(META_RECURSE_BYNAME);
        self.push(name.len() as u32);
        self.push(name.start as u32);
        Ok(())
    }

    fn recursion_by_number(&mut self) -> PResult<()> {
        let allow = if self.pat[self.ptr].is_ascii_digit() { -1 } else { self.bracount as i32 };
        let mut q = self.ptr;
        let r = self.read_number(&mut q, allow, MAX_GROUP_NUMBER, 61);
        self.ptr = q;
        let i = match r? {
            Some(i) => i,
            None => return Err(14),
        };
        if i < 0 {
            self.ptr -= 1;
            return Err(15);
        }
        if self.ptr >= self.end() || self.pat[self.ptr] != b')' {
            return Err(14);
        }
        self.set_recursion(i as u32);
        Ok(())
    }

    /// `SET_RECURSION`: `ptr` is at the closing parenthesis.
    fn set_recursion(&mut self, group: u32) {
        self.push(META_RECURSE | group);
        let offset = self.ptr;
        self.ptr += 1;
        self.push(offset as u32);
    }

    fn parse_version_condition(&mut self) -> PResult<()> {
        let end = self.end();
        self.ptr += 7;
        let mut ge = 0;
        if self.pat[self.ptr] == b'>' {
            ge = 1;
            self.ptr += 1;
        }
        if self.byte0(self.ptr) != u32::from(b'=') {
            return Err(79);
        }
        self.ptr += 1;
        if !is_digit(self.byte0(self.ptr)) {
            return Err(79);
        }
        let mut q = self.ptr;
        let r = self.read_number(&mut q, -1, 1000, 79);
        self.ptr = q;
        let major = r?.unwrap_or(0);
        let mut minor = 0;
        if self.ptr >= end {
            return Err(79);
        }
        if self.pat[self.ptr] == b'.' {
            self.ptr += 1;
            if self.ptr >= end || !self.pat[self.ptr].is_ascii_digit() {
                return Err(79);
            }
            minor = i32::from(self.pat[self.ptr] - b'0') * 10;
            self.ptr += 1;
            if self.ptr >= end {
                return Err(79);
            }
            if self.pat[self.ptr].is_ascii_digit() {
                minor += i32::from(self.pat[self.ptr] - b'0');
                self.ptr += 1;
            }
            if self.ptr >= end || self.pat[self.ptr] != b')' {
                return Err(79);
            }
        }
        self.push(META_COND_VERSION);
        self.push(ge);
        self.push(major as u32);
        self.push(minor as u32);
        Ok(())
    }

    /// A character class; `ptr` is after `[`.
    fn parse_class(&mut self, inescq: &mut bool) -> PResult<()> {
        const RANGE_NO: u8 = 0;
        const RANGE_STARTED: u8 = 1;
        const RANGE_OK_ESCAPED: u8 = 2;
        const RANGE_OK_LITERAL: u8 = 3;
        let end = self.end();

        if end - self.ptr >= 6
            && (&self.pat[self.ptr..self.ptr + 6] == b"[:<:]]" || &self.pat[self.ptr..self.ptr + 6] == b"[:>:]]")
        {
            self.push(META_ESCAPE + ESC_B_LOWER);
            if self.pat[self.ptr + 2] == b'<' {
                self.push(META_LOOKAHEAD);
            } else {
                self.push(META_LOOKBEHIND);
                self.has_lookbehind = true;
                self.push(0);
            }
            if self.options & UCP == 0 {
                self.push(META_ESCAPE + ESC_W_LOWER);
            } else {
                self.push(META_ESCAPE + ESC_P_LOWER);
                self.push(PT_WORD << 16);
            }
            self.push(META_KET);
            self.ptr += 6;
            return Ok(());
        }

        if self.ptr < end
            && matches!(self.pat[self.ptr], b':' | b'.' | b'=')
            && self.check_posix_syntax(self.ptr).is_some()
        {
            let was_colon = self.pat[self.ptr] == b':';
            self.ptr -= 1;
            return Err(if was_colon { 12 } else { 13 });
        }

        let mut negate_class = false;
        let mut c = 0u32;
        while self.ptr < end {
            let mut p = self.ptr;
            c = self.getchar_at(&mut p);
            self.ptr = p;
            if c == u32::from(b'\\') {
                if self.ptr < end && self.pat[self.ptr] == b'E' {
                    self.ptr += 1;
                } else if end - self.ptr >= 3 && &self.pat[self.ptr..self.ptr + 3] == b"Q\\E" {
                    self.ptr += 3;
                } else {
                    break;
                }
            } else if self.options & EXTENDED_MORE != 0 && (c == u32::from(b' ') || c == u32::from(b'\t')) {
                continue;
            } else if !negate_class && c == u32::from(b'^') {
                negate_class = true;
            } else {
                break;
            }
        }

        // ALLOW_EMPTY_CLASS is never set by PHP.
        self.push(if negate_class { META_CLASS_NOT } else { META_CLASS });
        let mut class_range_state = RANGE_NO;

        loop {
            let mut char_is_literal = true;
            let mut literal = false;
            if *inescq {
                if c == u32::from(b'\\') && self.ptr < end && self.pat[self.ptr] == b'E' {
                    *inescq = false;
                    self.ptr += 1;
                } else {
                    literal = true;
                }
            } else if self.options & EXTENDED_MORE != 0 && (c == u32::from(b' ') || c == u32::from(b'\t')) {
                // skip
            } else if c == u32::from(b'[')
                && end - self.ptr >= 3
                && matches!(self.pat[self.ptr], b':' | b'.' | b'=')
                && let Some(tempptr) = self.check_posix_syntax(self.ptr)
            {
                if class_range_state == RANGE_STARTED {
                    return Err(50);
                }
                if self.pat[self.ptr] != b':' {
                    self.ptr -= 1;
                    return Err(13);
                }
                self.ptr += 1;
                let mut posix_negate = false;
                if self.pat[self.ptr] == b'^' {
                    posix_negate = true;
                    self.ptr += 1;
                }
                const POSIX: &[&[u8]] = &[
                    b"alpha", b"lower", b"upper", b"alnum", b"ascii", b"blank", b"cntrl", b"digit", b"graph", b"print",
                    b"punct", b"space", b"word", b"xdigit",
                ];
                let Some(posix_class) = POSIX.iter().position(|n| *n == &self.pat[self.ptr..tempptr]) else {
                    return Err(30);
                };
                let posix_class = posix_class as u32;
                self.ptr = tempptr + 2;
                if self.ptr + 1 < end && self.pat[self.ptr] == b'-' && self.pat[self.ptr + 1] != b']' {
                    return Err(50);
                }
                class_range_state = RANGE_NO;
                let mut done = false;
                if self.options & UCP != 0
                    && self.xoptions & X_ASCII_POSIX == 0
                    && !(self.xoptions & X_ASCII_DIGIT != 0 && (posix_class == PC_DIGIT || posix_class == PC_XDIGIT))
                {
                    // posix_substitutes
                    const SUBS: [(i32, u32); 14] = [
                        (PT_GC as i32, unicode::GC_L),
                        (PT_PC as i32, unicode::LL),
                        (PT_PC as i32, unicode::LU),
                        (PT_ALNUM as i32, 0),
                        (-1, 0),
                        (-1, 1),
                        (PT_PC as i32, unicode::CC),
                        (PT_PC as i32, unicode::ND),
                        (PT_PXGRAPH as i32, 0),
                        (PT_PXPRINT as i32, 0),
                        (PT_PXPUNCT as i32, 0),
                        (PT_PXSPACE as i32, 0),
                        (PT_WORD as i32, 0),
                        (PT_PXXDIGIT as i32, 0),
                    ];
                    let (ptype, pvalue) = SUBS[posix_class as usize];
                    if ptype >= 0 {
                        self.push(META_ESCAPE + if posix_negate { ESC_P } else { ESC_P_LOWER });
                        self.push(((ptype as u32) << 16) | pvalue);
                        done = true;
                    } else if pvalue != 0 {
                        self.push(META_ESCAPE + if posix_negate { ESC_H } else { ESC_H_LOWER });
                        done = true;
                    }
                }
                if !done {
                    self.push(if posix_negate { META_POSIX_NEG } else { META_POSIX });
                    self.push(posix_class);
                }
            } else if c == u32::from(b'-') && class_range_state >= RANGE_OK_ESCAPED {
                self.push(if class_range_state == RANGE_OK_LITERAL { META_RANGE_LITERAL } else { META_RANGE_ESCAPED });
                class_range_state = RANGE_STARTED;
            } else if c != u32::from(b'\\') {
                literal = true;
            } else {
                let tempptr = self.ptr;
                let mut p = self.ptr;
                let r = self.check_escape(&mut p, true);
                self.ptr = p;
                let (escape, ch) = r?;
                let _ = tempptr;
                let mut escape = escape as u32;
                match escape {
                    0 => {
                        c = ch;
                        char_is_literal = false;
                        literal = true;
                    }
                    ESC_B_LOWER => {
                        c = 0x08;
                        char_is_literal = false;
                        literal = true;
                    }
                    ESC_Q => {
                        *inescq = true;
                    }
                    ESC_E => {}
                    ESC_B | ESC_R | ESC_X => {
                        self.ptr -= 1;
                        return Err(7);
                    }
                    _ => {
                        if class_range_state == RANGE_STARTED {
                            return Err(50);
                        }
                        class_range_state = RANGE_NO;
                        match escape {
                            ESC_N => return Err(71),
                            ESC_H | ESC_H_LOWER | ESC_V | ESC_V_LOWER => self.push(META_ESCAPE + escape),
                            ESC_D | ESC_D_LOWER | ESC_S | ESC_S_LOWER | ESC_W | ESC_W_LOWER => {
                                self.handle_escdsw(escape)
                            }
                            ESC_P | ESC_P_LOWER => {
                                let mut p = self.ptr;
                                let r = self.get_ucp(&mut p);
                                self.ptr = p;
                                let (negated, ptype, pdata) = r?;
                                if negated {
                                    escape = if escape == ESC_P { ESC_P_LOWER } else { ESC_P };
                                }
                                self.push(META_ESCAPE + escape);
                                self.push((ptype << 16) | pdata);
                            }
                            _ => {
                                self.ptr -= 1;
                                return Err(7);
                            }
                        }
                        if self.ptr + 1 < end && self.pat[self.ptr] == b'-' && self.pat[self.ptr + 1] != b']' {
                            return Err(50);
                        }
                    }
                }
            }

            if literal {
                // CLASS_LITERAL
                if class_range_state == RANGE_STARTED {
                    let n = self.out.len();
                    if c == self.out[n - 2] {
                        self.out.pop();
                    } else if self.out[n - 2] > c {
                        self.ptr -= 1;
                        return Err(8);
                    } else {
                        if !char_is_literal && self.out[n - 1] == META_RANGE_LITERAL {
                            self.out[n - 1] = META_RANGE_ESCAPED;
                        }
                        self.push(c);
                    }
                    class_range_state = RANGE_NO;
                } else {
                    class_range_state = if char_is_literal { RANGE_OK_LITERAL } else { RANGE_OK_ESCAPED };
                    self.push(c);
                }
            }

            // CLASS_CONTINUE
            if self.ptr >= end {
                return Err(6);
            }
            let mut p = self.ptr;
            c = self.getchar_at(&mut p);
            self.ptr = p;
            if c == u32::from(b']') && !*inescq {
                break;
            }
        }

        if class_range_state == RANGE_STARTED {
            let n = self.out.len();
            self.out[n - 1] = u32::from(b'-');
        }
        self.push(META_CLASS_END);
        Ok(())
    }
}

/// `isspace()` in the C locale.
fn is_c_space(c: u32) -> bool {
    matches!(c, 0x09..=0x0d | 0x20)
}

/// `PRIV(compile_error_texts)`, by error number.
pub fn error_message(code: i32) -> &'static str {
    if code < 0 {
        return match code {
            -3 => "UTF-8 error: 1 byte missing at end",
            -4 => "UTF-8 error: 2 bytes missing at end",
            -5 => "UTF-8 error: 3 bytes missing at end",
            -6 => "UTF-8 error: 4 bytes missing at end",
            -7 => "UTF-8 error: 5 bytes missing at end",
            -8 => "UTF-8 error: byte 2 top bits not 0x80",
            -9 => "UTF-8 error: byte 3 top bits not 0x80",
            -10 => "UTF-8 error: byte 4 top bits not 0x80",
            -11 => "UTF-8 error: byte 5 top bits not 0x80",
            -12 => "UTF-8 error: byte 6 top bits not 0x80",
            -13 => "UTF-8 error: 5-byte character is not allowed (RFC 3629)",
            -14 => "UTF-8 error: 6-byte character is not allowed (RFC 3629)",
            -15 => "UTF-8 error: code points greater than 0x10ffff are not defined",
            -16 => "UTF-8 error: code points 0xd800-0xdfff are not defined",
            -17 => "UTF-8 error: overlong 2-byte sequence",
            -18 => "UTF-8 error: overlong 3-byte sequence",
            -19 => "UTF-8 error: overlong 4-byte sequence",
            -20 => "UTF-8 error: overlong 5-byte sequence",
            -21 => "UTF-8 error: overlong 6-byte sequence",
            -22 => "UTF-8 error: isolated byte with 0x80 bit set",
            _ => "UTF-8 error: illegal byte (0xfe or 0xff)",
        };
    }
    const TEXTS: [&str; 102] = [
        "no error",
        "\\ at end of pattern",
        "\\c at end of pattern",
        "unrecognized character follows \\",
        "numbers out of order in {} quantifier",
        "number too big in {} quantifier",
        "missing terminating ] for character class",
        "escape sequence is invalid in character class",
        "range out of order in character class",
        "quantifier does not follow a repeatable item",
        "internal error: unexpected repeat",
        "unrecognized character after (? or (?-",
        "POSIX named classes are supported only within a class",
        "POSIX collating elements are not supported",
        "missing closing parenthesis",
        "reference to non-existent subpattern",
        "pattern passed as NULL with non-zero length",
        "unrecognised compile-time option bit(s)",
        "missing ) after (?# comment",
        "parentheses are too deeply nested",
        "regular expression is too large",
        "failed to allocate heap memory",
        "unmatched closing parenthesis",
        "internal error: code overflow",
        "missing closing parenthesis for condition",
        "length of lookbehind assertion is not limited",
        "a relative value of zero is not allowed",
        "conditional subpattern contains more than two branches",
        "assertion expected after (?( or (?(?C)",
        "digit expected after (?+ or (?-",
        "unknown POSIX class name",
        "internal error in pcre2_study(): should not occur",
        "this version of PCRE2 does not have Unicode support",
        "parentheses are too deeply nested (stack check)",
        "character code point value in \\x{} or \\o{} is too large",
        "lookbehind is too complicated",
        "\\C is not allowed in a lookbehind assertion in UTF-8 mode",
        "PCRE2 does not support \\F, \\L, \\l, \\N{name}, \\U, or \\u",
        "number after (?C is greater than 255",
        "closing parenthesis for (?C expected",
        "invalid escape sequence in (*VERB) name",
        "unrecognized character after (?P",
        "syntax error in subpattern name (missing terminator?)",
        "two named subpatterns have the same name (PCRE2_DUPNAMES not set)",
        "subpattern name must start with a non-digit",
        "this version of PCRE2 does not have support for \\P, \\p, or \\X",
        "malformed \\P or \\p sequence",
        "unknown property after \\P or \\p",
        "subpattern name is too long (maximum 128 code units)",
        "too many named subpatterns (maximum 10000)",
        "invalid range in character class",
        "octal value is greater than \\377 in 8-bit non-UTF-8 mode",
        "internal error: overran compiling workspace",
        "internal error: previously-checked referenced subpattern not found",
        "DEFINE subpattern contains more than one branch",
        "missing opening brace after \\o",
        "internal error: unknown newline setting",
        "\\g is not followed by a braced, angle-bracketed, or quoted name/number or by a plain number",
        "(?R (recursive pattern call) must be followed by a closing parenthesis",
        "obsolete error (should not occur)",
        "(*VERB) not recognized or malformed",
        "subpattern number is too big",
        "subpattern name expected",
        "internal error: parsed pattern overflow",
        "non-octal character in \\o{} (closing brace missing?)",
        "different names for subpatterns of the same number are not allowed",
        "(*MARK) must have an argument",
        "non-hex character in \\x{} (closing brace missing?)",
        "\\c must be followed by a printable ASCII character",
        "\\k is not followed by a braced, angle-bracketed, or quoted name",
        "internal error: unknown meta code in check_lookbehinds()",
        "\\N is not supported in a class",
        "callout string is too long",
        "disallowed Unicode code point (>= 0xd800 && <= 0xdfff)",
        "using UTF is disabled by the application",
        "using UCP is disabled by the application",
        "name is too long in (*MARK), (*PRUNE), (*SKIP), or (*THEN)",
        "character code point value in \\u.... sequence is too large",
        "digits missing in \\x{} or \\o{} or \\N{U+}",
        "syntax error or number too big in (?(VERSION condition",
        "internal error: unknown opcode in auto_possessify()",
        "missing terminating delimiter for callout with string argument",
        "unrecognized string delimiter follows (?C",
        "using \\C is disabled by the application",
        "(?| and/or (?J: or (?x: parentheses are too deeply nested",
        "using \\C is disabled in this PCRE2 library",
        "regular expression is too complicated",
        "lookbehind assertion is too long",
        "pattern string is longer than the limit set by the application",
        "internal error: unknown code in parsed pattern",
        "internal error: bad code value in parsed_skip()",
        "PCRE2_EXTRA_ALLOW_SURROGATE_ESCAPES is not allowed in UTF-16 mode",
        "invalid option bits with PCRE2_LITERAL",
        "\\N{U+dddd} is supported only in Unicode (UTF) mode",
        "invalid hyphen in option setting",
        "(*alpha_assertion) not recognized",
        "script runs require Unicode support, which this version of PCRE2 does not have",
        "too many capturing groups (maximum 65535)",
        "atomic assertion expected after (?( or (?(?C)",
        "\\K is not allowed in lookarounds (but see PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK)",
        "branch too long in variable-length lookbehind assertion",
        "compiled pattern would be longer than the limit set by the application",
    ];
    TEXTS.get(code as usize).copied().unwrap_or("internal error")
}
