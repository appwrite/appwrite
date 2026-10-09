//! The matcher: a port of `match()` and the bumpalong loop of
//! `pcre2_match()` (PCRE2 10.44).
//!
//! Backtracking uses an explicit vector of frames, exactly as PCRE2 does:
//! `rmatch` pushes a copy of the current frame (position, start of match,
//! mark, captures...) and continues with the new one; `rreturn` pops back to
//! the frame that made the call, which resumes where it left off (its
//! `Ret`). Group start frames are chained (`last_group`) so that the end of
//! a group finds where it started, and atomic groups and assertions discard
//! their internal frames by returning past them (`back`).

use super::compile::{Assert, Class, Lit, RepKind, XItem};
use super::parse::{Bsr, Newline, opt};
use super::parse::{
    PT_ALNUM, PT_ANY, PT_BIDICL, PT_BOOL, PT_CLIST, PT_GC, PT_LAMP, PT_PC, PT_PXGRAPH, PT_PXPRINT, PT_PXPUNCT,
    PT_PXSPACE, PT_PXXDIGIT, PT_SC, PT_SCX, PT_SPACE, PT_UCNC, PT_WORD,
};
use super::program::{BraKind, KetKind, LoopCount, Op, Program};
use super::study::StartInfo;
use super::unicode::{self, CTYPE_DIGIT, CTYPE_SPACE, CTYPE_WORD};

pub const UNSET: usize = usize::MAX;
const RECURSE_UNSET: u32 = u32::MAX;

const GF_CAPTURE: u32 = 0x0001_0000;
const GF_NOCAPTURE: u32 = 0x0002_0000;
const GF_CONDASSERT: u32 = 0x0003_0000;
const GF_RECURSE: u32 = 0x0004_0000;
const GF_IDMASK: u32 = 0xffff_0000;
const GF_DATAMASK: u32 = 0x0000_ffff;

pub const MATCH_MATCH: i32 = 1;
pub const MATCH_NOMATCH: i32 = 0;
const MATCH_ACCEPT: i32 = -999;
const MATCH_KETRPOS: i32 = -998;
const MATCH_COMMIT: i32 = -997;
const MATCH_PRUNE: i32 = -996;
const MATCH_SKIP: i32 = -995;
const MATCH_SKIP_ARG: i32 = -994;
const MATCH_THEN: i32 = -993;
const MATCH_BACKTRACK_MAX: i32 = MATCH_THEN;
const MATCH_BACKTRACK_MIN: i32 = MATCH_COMMIT;

pub const ERROR_BADUTFOFFSET: i32 = -36;
pub const ERROR_INTERNAL: i32 = -44;
pub const ERROR_JIT_STACKLIMIT: i32 = -46;
pub const ERROR_MATCHLIMIT: i32 = -47;
pub const ERROR_RECURSELOOP: i32 = -52;
pub const ERROR_DEPTHLIMIT: i32 = -53;

/// PHP's JIT stack (`PCRE_JIT_STACK_MAX_SIZE`, 192 KiB) in 8-byte words.
/// The matcher keeps the words the JIT's code would hold for the groups,
/// iterators, assertions and recursions on the current path
/// (`allocate_stack()` in `pcre2_jit_compile.c`): exact for groups and
/// iterators, approximate for recursion (see the `pcre` deviations in the
/// compat spec).
const JIT_STACK_WORDS: u32 = 192 * 1024 / 8;

/// Match-time options (`pcre2_match()` options).
pub const NOTEMPTY: u32 = 0x0000_0004;
pub const NOTEMPTY_ATSTART: u32 = 0x0000_0008;
pub const ANCHORED: u32 = 0x8000_0000;

/// Where a frame resumes when the frame it pushed returns.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Ret {
    None,
    RepMin,
    RepMax,
    RepMaxExtuni,
    /// A greedy class repeat backtracks down to its start position too.
    RepMaxClass,
    RefMin,
    RefMaxSame,
    RefMaxRescan,
    BraZero,
    BraMinZero,
    BraPos,
    GroupLoop,
    /// A branch other than the last of a group matched without a group
    /// frame (`OP_BRA` below the top level).
    BraBranch,
    Recurse,
    Assert,
    AssertNot,
    CondAssert,
    SCond,
    VReverse,
    KetRMin,
    KetRMax,
    JitEmptyLoop,
    /// What follows a lazily optional group with alternatives that matched:
    /// backtracking into the group goes through the JIT's `BRAMINZERO`
    /// entry, which counts a match step.
    JitBraMinZeroAgain,
    Mark,
    Commit,
    Prune,
    Skip,
    SkipArg,
    Then,
}

#[derive(Debug, Clone, Copy)]
struct Frame {
    // Not copied into a new frame.
    pc: usize,
    ret: Ret,
    back: usize,
    rdepth: u32,
    gft: u32,
    t0: usize,
    t1: usize,
    len: usize,
    n: [u32; 4],
    // Copied into a new frame.
    eptr: usize,
    start_match: usize,
    mark: Option<u32>,
    recurse_last_used: usize,
    current_recurse: u32,
    capture_last: u32,
    last_group: usize,
    offset_top: usize,
    /// The subject end in effect when this frame entered an assertion,
    /// restored when the assertion is left (see `Matcher::end`).
    saved_end: usize,
    /// The JIT stack words of the assertion this frame entered.
    jit_assert: u32,
}

/// A successful match: `ovector` holds `(start, end)` pairs for the whole
/// match and every group (`UNSET` for unset ones), `count` is the number of
/// pairs PCRE2 reports (one more than the highest group set).
#[derive(Debug, Clone)]
pub struct MatchData {
    pub ovector: Vec<usize>,
    pub count: usize,
    pub mark: Option<u32>,
}

pub struct Matcher<'a> {
    prog: &'a Program,
    s: &'a [u8],
    /// The end of the subject. The JIT matches a lookbehind that has a
    /// variable-length branch with the subject ended at the assertion's
    /// position (its `STR_END` register): `\z`, `$`, `\b` and lookaheads
    /// inside see that position as the end, and a variable-length branch
    /// must reach it. Like a register, it is not restored when matching
    /// backtracks, only when the assertion is left, so backtracking into a
    /// non-atomic lookbehind that already succeeded runs with the outer end.
    /// The interpreter keeps the real end.
    end: usize,
    true_end: usize,
    utf: bool,
    ucp: bool,
    poptions: u32,
    moptions: u32,
    start_offset: usize,
    newline: Newline,
    nllen: usize,
    bsr: Bsr,
    /// The JIT turns a recursion loop into a JIT stack overflow; the
    /// interpreter reports it.
    pub jit: bool,
    /// Where lookbehinds and `\b` stop looking back (the interpreter's
    /// `check_subject`).
    pub check_subject: usize,
    frames: Vec<Frame>,
    ov: Vec<usize>,
    ovs: usize,
    match_call_count: u64,
    pub match_limit: u64,
    pub depth_limit: u32,
    last_used_ptr: usize,
    start_used_ptr: usize,
    verb_ecode: usize,
    verb_skip_ptr: usize,
    verb_skip_name: u32,
    verb_current_recurse: u32,
    skip_arg_count: u32,
    ignore_skip_arg: u32,
    assert_accept: Option<(Vec<usize>, usize, Option<u32>)>,
    branch_end: Option<usize>,
    result: Option<MatchData>,
    /// The JIT stack words held, by the frame they belong to (freed when
    /// that frame is backtracked out of).
    jit_alloc: Vec<(usize, u32)>,
    jit_words: u32,
}

impl<'a> Matcher<'a> {
    pub fn new(prog: &'a Program, subject: &'a [u8], poptions: u32, newline: Newline, bsr: Bsr) -> Self {
        let ovs = 2 * prog.top_bracket as usize;
        Matcher {
            prog,
            s: subject,
            end: subject.len(),
            true_end: subject.len(),
            utf: poptions & opt::UTF != 0,
            ucp: poptions & opt::UCP != 0,
            poptions,
            moptions: 0,
            start_offset: 0,
            newline,
            nllen: match newline {
                Newline::CrLf => 2,
                _ => 1,
            },
            bsr,
            jit: true,
            check_subject: 0,
            frames: Vec::new(),
            ov: Vec::new(),
            ovs,
            match_call_count: 0,
            match_limit: 10_000_000,
            depth_limit: u32::MAX,
            last_used_ptr: 0,
            start_used_ptr: 0,
            verb_ecode: 0,
            verb_skip_ptr: 0,
            verb_skip_name: 0,
            verb_current_recurse: RECURSE_UNSET,
            skip_arg_count: 0,
            ignore_skip_arg: 0,
            assert_accept: None,
            branch_end: None,
            result: None,
            jit_alloc: Vec::new(),
            jit_words: 0,
        }
    }

    // ------------------------------------------------------------------
    // Character helpers

    fn getchar(&self, p: usize) -> (u32, usize) {
        if self.utf { unicode::utf8_at(self.s, p) } else { (u32::from(self.s[p]), 1) }
    }

    /// `BACKCHAR`: move back to the start of a UTF-8 character.
    fn backchar(&self, mut p: usize) -> usize {
        while p > 0 && self.s[p] & 0xc0 == 0x80 {
            p -= 1;
        }
        p
    }

    /// `FORWARDCHARTEST` / `ACROSSCHAR`.
    fn forwardchar(&self, mut p: usize) -> usize {
        while p < self.end && self.s[p] & 0xc0 == 0x80 {
            p += 1;
        }
        p
    }

    /// `IS_NEWLINE(p)`; for the variable conventions it also sets `nllen`.
    fn is_newline(&mut self, p: usize) -> bool {
        let s = self.s;
        match self.newline {
            Newline::Lf => p < self.end && s[p] == b'\n',
            Newline::Cr => p < self.end && s[p] == b'\r',
            Newline::Nul => p < self.end && s[p] == 0,
            Newline::CrLf => p + 2 <= self.end && s[p] == b'\r' && s[p + 1] == b'\n',
            Newline::Any | Newline::AnyCrLf => {
                if p >= self.end {
                    return false;
                }
                let (c, _) = self.getchar(p);
                let any = self.newline == Newline::Any;
                let len = match c {
                    0x0a => 1,
                    0x0d => {
                        if p < self.end - 1 && s[p + 1] == b'\n' {
                            2
                        } else {
                            1
                        }
                    }
                    0x0b | 0x0c if any => 1,
                    0x85 if any => {
                        if self.utf {
                            2
                        } else {
                            1
                        }
                    }
                    0x2028 | 0x2029 if any => 3,
                    _ => return false,
                };
                self.nllen = len;
                true
            }
        }
    }

    /// `WAS_NEWLINE(p)`.
    fn was_newline(&mut self, p: usize) -> bool {
        let s = self.s;
        match self.newline {
            Newline::Lf => p >= 1 && s[p - 1] == b'\n',
            Newline::Cr => p >= 1 && s[p - 1] == b'\r',
            Newline::Nul => p >= 1 && s[p - 1] == 0,
            Newline::CrLf => p >= 2 && s[p - 2] == b'\r' && s[p - 1] == b'\n',
            Newline::Any | Newline::AnyCrLf => {
                if p == 0 {
                    return false;
                }
                let mut q = p - 1;
                let c = if self.utf {
                    q = self.backchar(q);
                    unicode::utf8_at(s, q).0
                } else {
                    u32::from(s[q])
                };
                let any = self.newline == Newline::Any;
                let len = match c {
                    0x0a => {
                        if q > 0 && s[q - 1] == b'\r' {
                            2
                        } else {
                            1
                        }
                    }
                    0x0d => 1,
                    0x0b | 0x0c if any => 1,
                    0x85 if any => {
                        if self.utf {
                            2
                        } else {
                            1
                        }
                    }
                    0x2028 | 0x2029 if any => 3,
                    _ => return false,
                };
                self.nllen = len;
                true
            }
        }
    }

    fn ctype(c: u32, bit: u8) -> bool {
        c <= 255 && unicode::ctypes(c) & bit != 0
    }

    /// One single-character item at `p` (the single-item opcodes of
    /// `match()`); the position after it, or `None`.
    fn match_one(&mut self, lit: &Lit, p: usize) -> Option<usize> {
        let s = self.s;
        let end = self.end;
        match lit {
            Lit::Any => {
                if self.is_newline(p) || p >= end {
                    return None;
                }
                Some(if self.utf { self.forwardchar(p + 1) } else { p + 1 })
            }
            Lit::AllAny => {
                if p >= end {
                    return None;
                }
                Some(if self.utf { self.forwardchar(p + 1) } else { p + 1 })
            }
            Lit::AnyByte => {
                if p >= end {
                    None
                } else {
                    Some(p + 1)
                }
            }
            Lit::Char(c) => {
                if self.utf {
                    let mut buf = Vec::with_capacity(4);
                    unicode::push_utf8(&mut buf, *c);
                    if buf.len() > end - p || s[p..p + buf.len()] != buf[..] {
                        return None;
                    }
                    Some(p + buf.len())
                } else {
                    if p >= end || u32::from(s[p]) != *c {
                        return None;
                    }
                    Some(p + 1)
                }
            }
            Lit::CharI(c) => {
                if p >= end {
                    return None;
                }
                let fc = *c;
                if self.utf {
                    if fc < 128 {
                        if unicode::lcc(fc) != unicode::lcc(u32::from(s[p])) {
                            return None;
                        }
                        Some(p + 1)
                    } else {
                        let (dc, len) = unicode::utf8_at(s, p);
                        if dc != fc && dc != unicode::other_case(fc) {
                            return None;
                        }
                        Some(p + len)
                    }
                } else if self.ucp {
                    let cc = u32::from(s[p]);
                    if fc < 128 {
                        if unicode::lcc(fc) != unicode::lcc(cc) {
                            return None;
                        }
                    } else if cc != fc && cc != unicode::other_case(fc) {
                        return None;
                    }
                    Some(p + 1)
                } else {
                    if unicode::lcc(fc) != unicode::lcc(u32::from(s[p])) {
                        return None;
                    }
                    Some(p + 1)
                }
            }
            Lit::Not(c) | Lit::NotI(c) => {
                if p >= end {
                    return None;
                }
                let caseless = matches!(lit, Lit::NotI(_));
                let (fc, len) = self.getchar(p);
                let mut ch = *c;
                if ch == fc {
                    return None;
                }
                if caseless {
                    if self.utf || self.ucp {
                        ch = if ch > 127 { unicode::other_case(ch) } else { unicode::fcc(ch) };
                    } else {
                        ch = unicode::fcc(ch);
                    }
                    if ch == fc {
                        return None;
                    }
                }
                Some(p + len)
            }
            Lit::Digit(neg) | Lit::Space(neg) | Lit::Word(neg) => {
                if p >= end {
                    return None;
                }
                let (fc, len) = self.getchar(p);
                let bit = match lit {
                    Lit::Digit(_) => CTYPE_DIGIT,
                    Lit::Space(_) => CTYPE_SPACE,
                    _ => CTYPE_WORD,
                };
                if Self::ctype(fc, bit) == *neg {
                    return None;
                }
                Some(p + len)
            }
            Lit::AnyNl => {
                if p >= end {
                    return None;
                }
                let (fc, len) = self.getchar(p);
                let mut q = p + len;
                match fc {
                    0x0d => {
                        if q < end && s[q] == b'\n' {
                            q += 1;
                        }
                    }
                    0x0a => {}
                    0x0b | 0x0c | 0x85 | 0x2028 | 0x2029 => {
                        if self.bsr == Bsr::AnyCrLf {
                            return None;
                        }
                    }
                    _ => return None,
                }
                Some(q)
            }
            Lit::HSpace(neg) | Lit::VSpace(neg) => {
                if p >= end {
                    return None;
                }
                let (fc, len) = self.getchar(p);
                let is = if matches!(lit, Lit::HSpace(_)) { unicode::is_hspace(fc) } else { unicode::is_vspace(fc) };
                if is == *neg {
                    return None;
                }
                Some(p + len)
            }
            Lit::Prop { neg, ptype, pdata } => {
                if p >= end {
                    return None;
                }
                let (fc, len) = self.getchar(p);
                if prop_matches(fc, *ptype, *pdata) == *neg {
                    return None;
                }
                Some(p + len)
            }
            Lit::ExtUni => {
                if p >= end {
                    return None;
                }
                let (fc, len) = self.getchar(p);
                Some(self.extuni(fc, p + len))
            }
            Lit::Class(class) => {
                if p >= end {
                    return None;
                }
                let (fc, len) = self.getchar(p);
                let ok = match class.as_ref() {
                    Class::Map(map) => fc <= 255 && map[(fc / 8) as usize] & (1 << (fc & 7)) != 0,
                    Class::NMap(map) => fc > 255 || map[(fc / 8) as usize] & (1 << (fc & 7)) != 0,
                    Class::X { negated, has_prop, map, items } => xclass(fc, *negated, *has_prop, map.as_ref(), items),
                };
                if !ok {
                    return None;
                }
                Some(p + len)
            }
        }
    }

    /// `PRIV(extuni)`: the end of the extended grapheme cluster whose first
    /// character is `c`, which ends at `eptr`.
    fn extuni(&self, c: u32, mut eptr: usize) -> usize {
        let s = self.s;
        let mut was_ep_zwj = false;
        let mut lgb = unicode::grapheme_break(c);
        while eptr < self.end {
            let (c, len) = self.getchar(eptr);
            let rgb = unicode::grapheme_break(c);
            if unicode::GBTABLE[lgb as usize] & (1 << rgb) == 0 {
                break;
            }
            if lgb == unicode::GB_ZWJ && rgb == unicode::GB_EXTENDED_PICTOGRAPHIC && !was_ep_zwj {
                break;
            }
            if lgb == unicode::GB_REGIONAL_INDICATOR && rgb == unicode::GB_REGIONAL_INDICATOR {
                let mut ricount = 0;
                let mut bptr = eptr - 1;
                if self.utf {
                    bptr = self.backchar(bptr);
                }
                while bptr > 0 {
                    bptr -= 1;
                    let c = if self.utf {
                        bptr = self.backchar(bptr);
                        unicode::utf8_at(s, bptr).0
                    } else {
                        u32::from(s[bptr])
                    };
                    if unicode::grapheme_break(c) != unicode::GB_REGIONAL_INDICATOR {
                        break;
                    }
                    ricount += 1;
                }
                if ricount & 1 != 0 {
                    break;
                }
            }
            was_ep_zwj = lgb == unicode::GB_EXTENDED_PICTOGRAPHIC && rgb == unicode::GB_ZWJ;
            if rgb != unicode::GB_EXTEND || lgb != unicode::GB_EXTENDED_PICTOGRAPHIC {
                lgb = rgb;
            }
            eptr += len;
        }
        eptr
    }

    /// `match_ref()`: `Ok(length)` on a match.
    fn match_ref(&self, fi: usize, offset: usize, caseless: bool) -> Result<usize, ()> {
        let f = &self.frames[fi];
        let ov = &self.ov[fi * self.ovs..];
        if offset >= f.offset_top || ov[offset] == UNSET {
            if self.poptions & opt::MATCH_UNSET_BACKREF != 0 {
                return Ok(0);
            }
            return Err(());
        }
        let s = self.s;
        let mut eptr = f.eptr;
        let start = eptr;
        let mut p = ov[offset];
        let length = ov[offset + 1] - ov[offset];
        if caseless {
            if self.utf || self.ucp {
                let endptr = p + length;
                while p < endptr {
                    if eptr >= self.end {
                        return Err(());
                    }
                    let (c, d) = if self.utf {
                        let (c, cl) = unicode::utf8_at(s, eptr);
                        let (d, dl) = unicode::utf8_at(s, p);
                        eptr += cl;
                        p += dl;
                        (c, d)
                    } else {
                        let r = (u32::from(s[eptr]), u32::from(s[p]));
                        eptr += 1;
                        p += 1;
                        r
                    };
                    if c != d && c != unicode::other_case(d) {
                        let set = unicode::caseset(d);
                        let mut k = set as usize;
                        loop {
                            let x = super::ucd::CASELESS_SETS[k];
                            if c < x {
                                return Err(());
                            }
                            k += 1;
                            if c == x {
                                break;
                            }
                        }
                    }
                }
            } else {
                for _ in 0..length {
                    if eptr >= self.end {
                        return Err(());
                    }
                    if unicode::lcc(u32::from(s[p])) != unicode::lcc(u32::from(s[eptr])) {
                        return Err(());
                    }
                    p += 1;
                    eptr += 1;
                }
            }
        } else {
            if self.end - eptr < length || s[p..p + length] != s[eptr..eptr + length] {
                return Err(());
            }
            eptr += length;
        }
        Ok(eptr - start)
    }

    // ------------------------------------------------------------------
    // Frames

    fn top(&self) -> usize {
        self.frames.len() - 1
    }

    fn ov_get(&self, fi: usize, i: usize) -> usize {
        self.ov[fi * self.ovs + i]
    }

    fn ov_set(&mut self, fi: usize, i: usize, v: usize) {
        self.ov[fi * self.ovs + i] = v;
    }

    /// `RMATCH`: remember the resume point of the current frame and start a
    /// new frame at `target`.
    fn rmatch(&mut self, target: usize, ret: Ret, gft: u32) -> Result<(), i32> {
        let fi = self.top();
        self.frames[fi].ret = ret;
        let mut n = self.frames[fi];
        n.pc = target;
        n.ret = Ret::None;
        n.back = 1;
        n.rdepth += 1;
        n.gft = gft;
        if gft != 0 {
            n.last_group = fi + 1;
            if gft & GF_IDMASK == GF_RECURSE {
                n.current_recurse = gft & GF_DATAMASK;
            }
        }
        self.frames.push(n);
        let ovs = self.ovs;
        self.ov.extend_from_within(fi * ovs..(fi + 1) * ovs);
        if !self.jit
            || matches!(
                ret,
                Ret::RepMin
                    | Ret::RepMax
                    | Ret::RepMaxExtuni
                    | Ret::RepMaxClass
                    | Ret::RefMin
                    | Ret::RefMaxSame
                    | Ret::RefMaxRescan
            )
            || (ret == Ret::BraMinZero && self.prog.jit.count[self.frames[fi].pc] != LoopCount::Silent)
        {
            self.count()?;
        }
        if n.rdepth >= self.depth_limit {
            return Err(ERROR_DEPTHLIMIT);
        }
        Ok(())
    }

    /// Saves the subject end before the assertion at `bra` (entered from
    /// frame `fi`) and, for the JIT, ends the subject at the assertion's
    /// position when it is a lookbehind with a variable-length branch.
    fn enter_assertion(&mut self, bra: usize, fi: usize) {
        self.frames[fi].saved_end = self.end;
        if !self.jit {
            return;
        }
        let Op::Bra { kind: BraKind::AssertBack | BraKind::AssertBackNot | BraKind::AssertBackNa, .. } =
            self.prog.code[bra]
        else {
            return;
        };
        if self.lookbehind_has_vreverse(bra) {
            self.end = self.frames[fi].eptr;
        }
    }

    /// Whether a branch of the lookbehind at `bra` has a variable length.
    fn lookbehind_has_vreverse(&self, bra: usize) -> bool {
        let mut branch = bra;
        loop {
            if matches!(self.prog.code[branch + 1], Op::VReverse { .. }) {
                return true;
            }
            branch = self.link(branch);
            if !self.is_alt(branch) {
                return false;
            }
        }
    }

    /// Whether a variable-length lookbehind branch ending in frame `fi` ends
    /// where it must: at the assertion's position (frame `pi`) for the
    /// interpreter, at or after the subject end in effect for the JIT.
    fn reaches_lookbehind_end(&self, fi: usize, pi: usize) -> bool {
        if self.jit { self.frames[fi].eptr >= self.end } else { self.frames[fi].eptr == self.frames[pi].eptr }
    }

    /// One step against the match limit: every frame for the interpreter
    /// (`match()` calls), every `count_match()` for the JIT.
    fn count(&mut self) -> Result<(), i32> {
        if self.match_call_count >= self.match_limit {
            return Err(ERROR_MATCHLIMIT);
        }
        self.match_call_count += 1;
        Ok(())
    }

    /// Whether the JIT counts a match step when what follows the bracket at
    /// `bra` is entered (`compile_bracket_matchingpath()`, which also compiles
    /// the whole pattern): repeated groups, groups after `BRAZERO` and groups
    /// with alternatives; a group after `BRAMINZERO` goes back to its count.
    /// Assertions and possessive groups are compiled elsewhere.
    fn jit_counts_bracket(&self, bra: usize, ket: KetKind) -> bool {
        let code = &self.prog.code;
        let Op::Bra { kind, .. } = code[bra] else { return false };
        if matches!(
            kind,
            BraKind::Assert
                | BraKind::AssertNot
                | BraKind::AssertBack
                | BraKind::AssertBackNot
                | BraKind::BraPos
                | BraKind::CapturePos(_)
        ) {
            return false;
        }
        let prefix = if bra > 0 { Some(&code[bra - 1]) } else { None };
        let brazero = matches!(prefix, Some(Op::BraZero));
        let braminzero = matches!(prefix, Some(Op::BraMinZero));
        let alternatives = if kind == BraKind::Cond {
            !matches!(code[bra + 1], Op::CondRecurse(_) | Op::CondTrue | Op::CondFalse | Op::Fail)
        } else {
            self.is_alt(self.link(bra))
        };
        braminzero || ket != KetKind::Ket || brazero || alternatives
    }

    fn truncate(&mut self, len: usize) {
        self.frames.truncate(len);
        self.ov.truncate(len * self.ovs);
        while let Some(&(f, w)) = self.jit_alloc.last() {
            if f < len {
                break;
            }
            self.jit_alloc.pop();
            self.jit_words -= w;
        }
    }

    /// `allocate_stack()`: `words` more on the JIT stack, held by frame `fi`.
    fn jit_push(&mut self, words: u32, fi: usize) -> Result<(), i32> {
        if !self.jit || words == 0 {
            return Ok(());
        }
        self.jit_alloc.push((fi, words));
        self.jit_words += words;
        if self.jit_words > JIT_STACK_WORDS {
            return Err(ERROR_JIT_STACKLIMIT);
        }
        Ok(())
    }

    /// An atomic group or assertion that matched drops what its frames held.
    fn jit_discard(&mut self, above: usize) {
        while let Some(&(f, w)) = self.jit_alloc.last() {
            if f <= above {
                break;
            }
            self.jit_alloc.pop();
            self.jit_words -= w;
        }
    }

    /// Whether the JIT treats the group at `bra` as having alternatives.
    fn jit_has_alternatives(&self, bra: usize) -> bool {
        match self.prog.code[bra] {
            Op::Bra { kind: BraKind::Cond, .. } => {
                !matches!(self.prog.code[bra + 1], Op::CondRecurse(_) | Op::CondTrue | Op::CondFalse | Op::Fail)
            }
            _ => self.is_alt(self.link(bra)),
        }
    }

    /// The JIT stack words of entering the group at `bra` from frame `fi`
    /// (`compile_bracket_matchingpath()`), and of starting its loop.
    fn jit_entry_words(&self, bra: usize, fi: usize) -> u32 {
        let code = &self.prog.code;
        let Op::Bra { kind, .. } = code[bra] else { return 0 };
        let Op::Ket { kind: ket, .. } = code[self.to_ket(bra)] else { return 0 };
        let mut words = self.jit_bracket_words(bra);
        let iteration = (fi > 0 && self.frames[fi - 1].ret == Ret::KetRMax && self.frames[fi - 1].t0 == bra)
            || (self.frames[fi].ret == Ret::KetRMin && self.frames[fi].t0 == bra);
        if !iteration && !matches!(kind, BraKind::BraPos | BraKind::CapturePos(_)) {
            let prefix = if bra > 0 { &code[bra - 1] } else { &Op::End };
            if ket == KetKind::RMax || (ket == KetKind::RMin && !matches!(prefix, Op::BraMinZero)) {
                words += 1;
            }
            if matches!(prefix, Op::BraZero) {
                words += 1;
            }
        }
        words
    }

    /// The words entering the group at `bra` takes for itself (saved
    /// offsets, the start position for alternatives).
    fn jit_bracket_words(&self, bra: usize) -> u32 {
        let code = &self.prog.code;
        let Op::Bra { kind, empty, .. } = code[bra] else { return 0 };
        let Op::Ket { kind: ket, .. } = code[self.to_ket(bra)] else { return 0 };
        let has_alt = self.jit_has_alternatives(bra);
        match kind {
            BraKind::Capture(n) => {
                if self.prog.jit.optimized[n as usize] {
                    2
                } else {
                    1
                }
            }
            BraKind::AssertNa | BraKind::AssertBackNa | BraKind::ScriptRun => 1,
            BraKind::Bra | BraKind::Cond if empty => 1,
            BraKind::Once => {
                if ket == KetKind::RMin {
                    2
                } else {
                    u32::from(ket != KetKind::Ket || has_alt)
                }
            }
            BraKind::Bra | BraKind::Cond | BraKind::Root => u32::from(has_alt),
            BraKind::BraPos => 1,
            BraKind::CapturePos(_) => 3,
            _ => 0,
        }
    }

    /// The JIT stack words a branch of the group at `bra` leaves when it
    /// matches.
    fn jit_end_words(&self, bra: usize) -> u32 {
        let code = &self.prog.code;
        let Op::Bra { kind, .. } = code[bra] else { return 0 };
        if matches!(
            kind,
            BraKind::Assert
                | BraKind::AssertNot
                | BraKind::AssertBack
                | BraKind::AssertBackNot
                | BraKind::BraPos
                | BraKind::CapturePos(_)
        ) {
            return 0;
        }
        let Op::Ket { kind: ket, .. } = code[self.to_ket(bra)] else { return 0 };
        let prefix = if bra > 0 { &code[bra - 1] } else { &Op::End };
        let mut words = u32::from(ket != KetKind::Ket || matches!(prefix, Op::BraZero | Op::BraMinZero));
        if let BraKind::Capture(n) = kind
            && !self.prog.jit.optimized[n as usize]
        {
            words += 2;
        }
        if kind != BraKind::Once && self.jit_has_alternatives(bra) {
            words += 1;
        }
        words
    }

    fn link(&self, pc: usize) -> usize {
        match &self.prog.code[pc] {
            Op::Bra { link, .. } | Op::Alt { link } => *link,
            _ => unreachable!("not a linked op at {pc}"),
        }
    }

    fn is_alt(&self, pc: usize) -> bool {
        matches!(self.prog.code[pc], Op::Alt { .. })
    }

    /// Follows the links from a bracket or alternative to the `Ket`.
    fn to_ket(&self, mut pc: usize) -> usize {
        loop {
            pc = self.link(pc);
            if !self.is_alt(pc) {
                return pc;
            }
        }
    }

    /// Runs one match attempt starting at `start_eptr` (`match()`).
    fn run(&mut self, start_eptr: usize) -> i32 {
        let prog = self.prog;
        let code = &prog.code;
        let utf = self.utf;
        self.frames.clear();
        self.ov.clear();
        self.ov.resize(self.ovs, UNSET);
        self.branch_end = None;
        self.end = self.true_end;
        self.jit_alloc.clear();
        self.jit_words = 0;
        self.frames.push(Frame {
            pc: 0,
            ret: Ret::None,
            back: 1,
            rdepth: 0,
            gft: 0,
            t0: 0,
            t1: 0,
            len: 0,
            n: [0; 4],
            eptr: start_eptr,
            start_match: start_eptr,
            mark: None,
            recurse_last_used: 0,
            current_recurse: RECURSE_UNSET,
            capture_last: 0,
            last_group: UNSET,
            offset_top: 0,
            saved_end: self.true_end,
            jit_assert: 0,
        });
        if !self.jit
            && let Err(e) = self.count()
        {
            return e;
        }

        // A JIT `count_match()` where the interpreter makes no frame.
        macro_rules! jit_count {
            () => {
                if self.jit {
                    if let Err(e) = self.count() {
                        return e;
                    }
                }
            };
        }

        // The words an assertion at `bra` holds while it runs
        // (`compile_assert_matchingpath()`), in its first frame.
        macro_rules! jit_assertion {
            ($bra:expr) => {
                if self.jit {
                    let bra = $bra;
                    let back = matches!(
                        self.prog.code[bra],
                        Op::Bra { kind: BraKind::AssertBack | BraKind::AssertBackNot | BraKind::AssertBackNa, .. }
                    ) && self.lookbehind_has_vreverse(bra);
                    let top = self.top();
                    let words = if back { 4 } else { 1 };
                    self.frames[top - 1].jit_assert = words;
                    if let Err(e) = self.jit_push(words, top) {
                        return e;
                    }
                }
            };
        }

        macro_rules! rmatch {
            ($target:expr, $ret:expr, $gft:expr) => {
                if let Err(e) = self.rmatch($target, $ret, $gft) {
                    return e;
                }
            };
        }

        let mut rrc: i32;
        'exec: loop {
            // Process ops in the current frame until something returns.
            rrc = 'ops: loop {
                let fi = self.top();
                let pc = self.frames[fi].pc;
                match &code[pc] {
                    Op::Close(number) => {
                        let number = *number;
                        if self.frames[fi].current_recurse == RECURSE_UNSET {
                            let mut off = self.frames[fi].last_group;
                            let p_idx = loop {
                                if off == UNSET || off == 0 {
                                    return ERROR_INTERNAL;
                                }
                                if self.frames[off].gft == GF_CAPTURE | number {
                                    break off - 1;
                                }
                                off = self.frames[off - 1].last_group;
                            };
                            let offset = (number as usize) * 2 - 2;
                            let start = self.frames[p_idx].eptr;
                            let f = &mut self.frames[fi];
                            f.capture_last = number;
                            let e = f.eptr;
                            if offset >= f.offset_top {
                                f.offset_top = offset + 2;
                            }
                            self.ov_set(fi, offset, start);
                            self.ov_set(fi, offset + 1, e);
                        }
                        self.frames[fi].pc += 1;
                    }
                    Op::AssertAccept => {
                        let f = self.frames[fi];
                        if f.eptr > self.last_used_ptr {
                            self.last_used_ptr = f.eptr;
                        }
                        let ovs = self.ovs;
                        self.assert_accept = Some((self.ov[fi * ovs..(fi + 1) * ovs].to_vec(), f.offset_top, f.mark));
                        break 'ops MATCH_ACCEPT;
                    }
                    Op::Accept | Op::End => {
                        if matches!(code[pc], Op::Accept) && self.frames[fi].current_recurse != RECURSE_UNSET {
                            let mut off = self.frames[fi].last_group;
                            let p_idx = loop {
                                if off == UNSET || off == 0 {
                                    return ERROR_INTERNAL;
                                }
                                if self.frames[off].gft & GF_IDMASK == GF_RECURSE {
                                    break off - 1;
                                }
                                off = self.frames[off - 1].last_group;
                            };
                            let f = self.frames[fi];
                            let p = &mut self.frames[p_idx];
                            p.eptr = f.eptr;
                            p.mark = f.mark;
                            p.start_match = f.start_match;
                            p.pc += 1;
                            self.truncate(p_idx + 1);
                            if let Err(e) = self.jit_push(2, p_idx) {
                                return e;
                            }
                            continue 'ops;
                        }
                        let f = self.frames[fi];
                        if f.eptr == f.start_match
                            && (self.moptions & NOTEMPTY != 0
                                || (self.moptions & NOTEMPTY_ATSTART != 0 && f.start_match == self.start_offset))
                        {
                            break 'ops MATCH_NOMATCH;
                        }
                        if f.eptr > self.last_used_ptr {
                            self.last_used_ptr = f.eptr;
                        }
                        let top = prog.top_bracket as usize;
                        let mut ovector = vec![UNSET; 2 * (top + 1)];
                        ovector[0] = f.start_match;
                        ovector[1] = f.eptr;
                        for i in 0..f.offset_top.min(2 * top) {
                            ovector[2 + i] = self.ov_get(fi, i);
                        }
                        self.result = Some(MatchData { ovector, count: f.offset_top / 2 + 1, mark: f.mark });
                        return MATCH_MATCH;
                    }
                    Op::Item(lit) => {
                        let e = self.frames[fi].eptr;
                        match self.match_one(lit, e) {
                            Some(e) => {
                                let f = &mut self.frames[fi];
                                f.eptr = e;
                                f.pc += 1;
                            }
                            None => break 'ops MATCH_NOMATCH,
                        }
                    }
                    Op::Rep { lit, min, max, kind } => {
                        let (min, max, kind) = (*min, *max, *kind);
                        if let Err(e) = self.jit_push(u32::from(prog.jit.rep[pc]), fi) {
                            return e;
                        }
                        let mut e = self.frames[fi].eptr;
                        for _ in 0..min {
                            match self.match_one(lit, e) {
                                Some(x) => e = x,
                                None => break 'ops MATCH_NOMATCH,
                            }
                        }
                        {
                            let f = &mut self.frames[fi];
                            f.eptr = e;
                            f.pc = pc + 1;
                            f.t1 = pc;
                        }
                        if min == max {
                            jit_count!();
                            continue 'ops;
                        }
                        // {n,m} with n >= 2 is OP_EXACT and then the variable
                        // part, two iterators for the JIT (a class repeat is
                        // one).
                        if min >= 2 && !matches!(lit, Lit::Class(_)) {
                            jit_count!();
                        }
                        if kind == RepKind::Lazy {
                            let f = &mut self.frames[fi];
                            f.n[0] = min;
                            f.n[1] = max;
                            rmatch!(pc + 1, Ret::RepMin, 0);
                            continue 'ops;
                        }
                        let start = e;
                        let mut i = min;
                        while i < max {
                            match self.match_one(lit, e) {
                                Some(x) => e = x,
                                None => break,
                            }
                            i += 1;
                        }
                        {
                            let f = &mut self.frames[fi];
                            f.eptr = e;
                            f.t0 = start;
                        }
                        if kind == RepKind::Possessive {
                            jit_count!();
                            continue 'ops;
                        }
                        // A class repeat tries what follows at every position
                        // down to and including its start, in a new frame.
                        if matches!(lit, Lit::Class(_)) {
                            rmatch!(pc + 1, Ret::RepMaxClass, 0);
                            continue 'ops;
                        }
                        if e <= start {
                            jit_count!();
                            continue 'ops;
                        }
                        let ret = if matches!(lit, Lit::ExtUni) { Ret::RepMaxExtuni } else { Ret::RepMax };
                        rmatch!(pc + 1, ret, 0);
                    }
                    Op::Ref { groups, caseless, min, max, lazy, repeated } => {
                        let f = self.frames[fi];
                        let mut offset = 0;
                        for &g in groups.iter() {
                            offset = (g as usize) * 2 - 2;
                            if offset < f.offset_top && self.ov_get(fi, offset) != UNSET {
                                break;
                            }
                        }
                        let caseless = *caseless;
                        if !*repeated {
                            match self.match_ref(fi, offset, caseless) {
                                Ok(len) => {
                                    let f = &mut self.frames[fi];
                                    f.eptr += len;
                                    f.pc += 1;
                                }
                                Err(()) => break 'ops MATCH_NOMATCH,
                            }
                            continue 'ops;
                        }
                        let (min, max, lazy) = (*min, *max, *lazy);
                        self.frames[fi].pc = pc + 1;
                        if offset < f.offset_top && self.ov_get(fi, offset) != UNSET {
                            if self.ov_get(fi, offset) == self.ov_get(fi, offset + 1) {
                                jit_count!();
                                continue 'ops;
                            }
                        } else if min == 0 || self.poptions & opt::MATCH_UNSET_BACKREF != 0 {
                            jit_count!();
                            continue 'ops;
                        }
                        for _ in 0..min {
                            match self.match_ref(fi, offset, caseless) {
                                Ok(len) => self.frames[fi].eptr += len,
                                Err(()) => break 'ops MATCH_NOMATCH,
                            }
                        }
                        if min == max {
                            jit_count!();
                            continue 'ops;
                        }
                        {
                            let f = &mut self.frames[fi];
                            f.n[0] = min;
                            f.n[1] = max;
                            f.n[2] = u32::from(caseless);
                            f.len = offset;
                        }
                        if lazy {
                            rmatch!(pc + 1, Ret::RefMin, 0);
                            continue 'ops;
                        }
                        let start = self.frames[fi].eptr;
                        let flength = self.ov_get(fi, offset + 1) - self.ov_get(fi, offset);
                        let mut samelengths = true;
                        let mut i = min;
                        while i < max {
                            match self.match_ref(fi, offset, caseless) {
                                Ok(len) => {
                                    if len != flength {
                                        samelengths = false;
                                    }
                                    self.frames[fi].eptr += len;
                                }
                                Err(()) => break,
                            }
                            i += 1;
                        }
                        let f = &mut self.frames[fi];
                        f.t0 = start;
                        f.t1 = flength;
                        if samelengths {
                            rmatch!(pc + 1, Ret::RefMaxSame, 0);
                        } else {
                            f.n[1] = i;
                            rmatch!(pc + 1, Ret::RefMaxRescan, 0);
                        }
                    }
                    Op::Assert(a) => {
                        let e = self.frames[fi].eptr;
                        let ok = self.check_assert(*a, e, fi);
                        if !ok {
                            break 'ops MATCH_NOMATCH;
                        }
                        self.frames[fi].pc += 1;
                    }
                    Op::SetSom => {
                        let f = &mut self.frames[fi];
                        f.start_match = f.eptr;
                        f.pc += 1;
                    }
                    Op::BraZero => {
                        self.frames[fi].t0 = pc + 1;
                        rmatch!(pc + 1, Ret::BraZero, 0);
                    }
                    Op::BraMinZero => {
                        let ket = self.to_ket(pc + 1);
                        let words = if matches!(code[ket], Op::Ket { kind: KetKind::RMin, .. }) { 2 } else { 1 };
                        if let Err(e) = self.jit_push(words, fi) {
                            return e;
                        }
                        rmatch!(ket + 1, Ret::BraMinZero, 0);
                    }
                    Op::SkipZero => {
                        let ket = self.to_ket(pc + 1);
                        self.frames[fi].pc = ket + 1;
                    }
                    Op::BraPosZero => {
                        let f = &mut self.frames[fi];
                        f.n[2] = 1;
                        f.pc = pc + 1;
                        let gft = match &code[pc + 1] {
                            Op::Bra { kind: BraKind::CapturePos(n), .. } => GF_CAPTURE | n,
                            _ => GF_NOCAPTURE,
                        };
                        f.n[0] = gft;
                        f.n[1] = 0;
                        f.t1 = pc + 1;
                        f.t0 = f.eptr;
                        rmatch!(pc + 2, Ret::BraPos, gft);
                    }
                    Op::Bra { kind, .. } => {
                        if self.jit {
                            let words = self.jit_entry_words(pc, fi);
                            if *kind == BraKind::Once {
                                // The words saved before the first branch
                                // (repeat and zero marks) outlive the group;
                                // its own go when it matches.
                                let own = self.jit_bracket_words(pc);
                                if let Err(e) = self.jit_push(words - own, fi) {
                                    return e;
                                }
                                self.frames[fi].jit_assert = own;
                                if let Err(e) = self.jit_push(own, fi) {
                                    return e;
                                }
                            } else if let Err(e) = self.jit_push(words, fi) {
                                return e;
                            }
                        }
                        match kind {
                            BraKind::BraPos | BraKind::CapturePos(_) => {
                                let gft = match kind {
                                    BraKind::CapturePos(n) => GF_CAPTURE | n,
                                    _ => GF_NOCAPTURE,
                                };
                                let f = &mut self.frames[fi];
                                f.n = [gft, 0, 0, 0];
                                f.t1 = pc;
                                f.t0 = f.eptr;
                                rmatch!(pc + 1, Ret::BraPos, gft);
                            }
                            BraKind::Assert | BraKind::AssertBack | BraKind::AssertNa | BraKind::AssertBackNa => {
                                self.frames[fi].n[0] = GF_NOCAPTURE;
                                self.enter_assertion(pc, fi);
                                rmatch!(pc + 1, Ret::Assert, GF_NOCAPTURE);
                                jit_assertion!(pc);
                            }
                            BraKind::AssertNot | BraKind::AssertBackNot => {
                                self.frames[fi].n[0] = GF_NOCAPTURE;
                                self.enter_assertion(pc, fi);
                                rmatch!(pc + 1, Ret::AssertNot, GF_NOCAPTURE);
                                jit_assertion!(pc);
                            }
                            BraKind::Cond => {
                                let link = self.link(pc);
                                let second = if self.is_alt(link) { link + 1 } else { link };
                                self.frames[fi].len = second;
                                let f = self.frames[fi];
                                let condition = match &code[pc + 1] {
                                    Op::CondRecurse(None) => Some(f.current_recurse != RECURSE_UNSET),
                                    Op::CondRecurse(Some(gs)) => {
                                        Some(f.current_recurse != RECURSE_UNSET && gs.contains(&f.current_recurse))
                                    }
                                    Op::CondRef(gs) => Some(gs.iter().any(|&g| {
                                        let off = (g as usize) * 2 - 2;
                                        off < f.offset_top && self.ov_get(fi, off) != UNSET
                                    })),
                                    Op::CondTrue => Some(true),
                                    Op::CondFalse => Some(false),
                                    _ => None,
                                };
                                match condition {
                                    Some(c) => {
                                        let next = if c { pc + 2 } else { second };
                                        self.frames[fi].pc = next;
                                        if matches!(code[pc], Op::Bra { empty: true, .. }) {
                                            rmatch!(next, Ret::SCond, GF_NOCAPTURE);
                                        }
                                    }
                                    None => {
                                        // An assertion condition.
                                        let positive = matches!(
                                            code[pc + 1],
                                            Op::Bra { kind: BraKind::Assert | BraKind::AssertBack, .. }
                                        );
                                        let f = &mut self.frames[fi];
                                        f.n[0] = u32::from(positive);
                                        f.t0 = pc + 1;
                                        self.enter_assertion(pc + 1, fi);
                                        rmatch!(pc + 2, Ret::CondAssert, GF_CONDASSERT);
                                        jit_assertion!(pc + 1);
                                    }
                                }
                            }
                            BraKind::Root => {
                                self.frames[fi].n[0] = 0;
                                rmatch!(pc + 1, Ret::GroupLoop, 0);
                            }
                            BraKind::Capture(n) => {
                                self.frames[fi].n[0] = GF_CAPTURE | n;
                                rmatch!(pc + 1, Ret::GroupLoop, GF_CAPTURE | n);
                            }
                            BraKind::Bra if !matches!(code[pc], Op::Bra { empty: true, .. }) => {
                                // OP_BRA records no group frame. Below the top
                                // level and without (*THEN), only the branches
                                // before the last get a backtracking frame; the
                                // last runs at this level.
                                if prog.has_then || self.frames[fi].rdepth == 0 {
                                    self.frames[fi].n[0] = 0;
                                    rmatch!(pc + 1, Ret::GroupLoop, 0);
                                } else if self.is_alt(self.link(pc)) {
                                    rmatch!(pc + 1, Ret::BraBranch, 0);
                                } else {
                                    self.frames[fi].pc = pc + 1;
                                }
                            }
                            BraKind::Bra | BraKind::Once | BraKind::ScriptRun => {
                                self.frames[fi].n[0] = GF_NOCAPTURE;
                                rmatch!(pc + 1, Ret::GroupLoop, GF_NOCAPTURE);
                            }
                        }
                    }
                    Op::Alt { .. } => {
                        self.branch_end = Some(pc);
                        let ket = self.to_ket(pc);
                        self.frames[fi].pc = ket;
                    }
                    Op::Ket { bra, kind: ket_kind } => {
                        let bracode = *bra;
                        let ket_kind = *ket_kind;
                        let branch_end = self.branch_end.take().unwrap_or(pc);
                        let mut branch_start = bracode;
                        while self.link(branch_start) != branch_end {
                            branch_start = self.link(branch_start);
                        }
                        let Op::Bra { kind, empty, .. } = code[bracode] else { return ERROR_INTERNAL };
                        if self.jit && kind != BraKind::Once {
                            // The end of a recursion is not the end of its
                            // group: `compile_recurse()` runs the branches
                            // without the bracket.
                            let recursion = match kind {
                                BraKind::Root => {
                                    self.frames[fi].current_recurse == 0 && matches!(code[pc + 1], Op::End)
                                }
                                BraKind::Capture(n) | BraKind::CapturePos(n) => self.frames[fi].current_recurse == n,
                                _ => false,
                            };
                            let words = if recursion {
                                let group = match kind {
                                    BraKind::Capture(n) | BraKind::CapturePos(n) => n as usize,
                                    _ => 0,
                                };
                                self.prog.jit.recurse_end[group]
                            } else {
                                self.jit_end_words(bracode)
                            };
                            if let Err(e) = self.jit_push(words, fi) {
                                return e;
                            }
                        }
                        let mut p_idx: Option<usize> = None;
                        let group_frame =
                            !matches!(kind, BraKind::Root) && !(matches!(kind, BraKind::Bra | BraKind::Cond) && !empty);
                        if group_frame {
                            let n_idx = self.frames[fi].last_group;
                            if n_idx == UNSET || n_idx == 0 {
                                return ERROR_INTERNAL;
                            }
                            let pi = n_idx - 1;
                            self.frames[fi].last_group = self.frames[pi].last_group;
                            if self.frames[n_idx].gft & GF_IDMASK == GF_CONDASSERT {
                                let f = self.frames[fi];
                                let ovs = self.ovs;
                                let (lo, hi) = self.ov.split_at_mut(fi * ovs);
                                lo[pi * ovs..pi * ovs + f.offset_top].copy_from_slice(&hi[..f.offset_top]);
                                let p = &mut self.frames[pi];
                                p.offset_top = f.offset_top;
                                p.mark = f.mark;
                                self.frames[fi].back = fi - pi;
                                break 'ops MATCH_MATCH;
                            }
                            p_idx = Some(pi);
                        }
                        let vreverse = matches!(code[branch_start + 1], Op::VReverse { .. });
                        match kind {
                            BraKind::Root => {
                                if self.frames[fi].current_recurse == 0 && matches!(code[pc + 1], Op::End) {
                                    let off = self.frames[fi].last_group;
                                    if off == UNSET || off == 0 {
                                        return ERROR_INTERNAL;
                                    }
                                    let pi = off - 1;
                                    self.return_from_recursion(fi, pi);
                                    continue 'ops;
                                }
                            }
                            BraKind::Cond | BraKind::Bra | BraKind::BraPos => {}
                            BraKind::AssertNa | BraKind::AssertBackNa => {
                                let pi = p_idx.unwrap_or(0);
                                if kind == BraKind::AssertBackNa && vreverse && !self.reaches_lookbehind_end(fi, pi) {
                                    break 'ops MATCH_NOMATCH;
                                }
                                if self.frames[fi].eptr > self.last_used_ptr {
                                    self.last_used_ptr = self.frames[fi].eptr;
                                }
                                // The JIT leaves a non-atomic lookbehind where its
                                // branch ended: the assertion's position, or the
                                // outer end when the branch was retried after
                                // backtracking into it.
                                if !(self.jit && kind == BraKind::AssertBackNa) {
                                    self.frames[fi].eptr = self.frames[pi].eptr;
                                }
                                self.end = self.frames[pi].saved_end;
                            }
                            BraKind::Assert | BraKind::AssertBack | BraKind::Once => {
                                let pi = p_idx.unwrap_or(0);
                                if kind != BraKind::Once {
                                    if kind == BraKind::AssertBack && vreverse && !self.reaches_lookbehind_end(fi, pi) {
                                        break 'ops MATCH_NOMATCH;
                                    }
                                    if self.frames[fi].eptr > self.last_used_ptr {
                                        self.last_used_ptr = self.frames[fi].eptr;
                                    }
                                    self.frames[fi].eptr = self.frames[pi].eptr;
                                    self.end = self.frames[pi].saved_end;
                                }
                                if self.jit {
                                    // `match_once_common()`: the group's own
                                    // words go too unless it saved a frame
                                    // (captures inside), which stays.
                                    self.jit_discard(pi);
                                    if kind == BraKind::Once {
                                        let entry = self.frames[pi].jit_assert;
                                        if let Some(&(f, w)) = self.jit_alloc.last()
                                            && f == pi
                                            && w == entry
                                        {
                                            self.jit_alloc.pop();
                                            self.jit_words -= w;
                                        }
                                        let captures = self.prog.jit.once_frame[bracode];
                                        let mut words = self.jit_end_words(bracode);
                                        if captures > 0 {
                                            let alt = ket_kind != KetKind::Ket || self.jit_has_alternatives(bracode);
                                            words += captures + if alt { 2 } else { 1 };
                                        }
                                        if let Err(e) = self.jit_push(words, fi) {
                                            return e;
                                        }
                                    }
                                }
                                self.frames[fi].back = fi - pi;
                                loop {
                                    let y = self.link(self.frames[pi].pc);
                                    if !self.is_alt(y) {
                                        break;
                                    }
                                    self.frames[pi].pc = y;
                                }
                            }
                            BraKind::AssertNot | BraKind::AssertBackNot => {
                                let pi = p_idx.unwrap_or(0);
                                if kind == BraKind::AssertBackNot && vreverse && !self.reaches_lookbehind_end(fi, pi) {
                                    break 'ops MATCH_NOMATCH;
                                }
                                break 'ops MATCH_MATCH;
                            }
                            BraKind::ScriptRun => {
                                let pi = p_idx.unwrap_or(0);
                                if !self.script_run(self.frames[pi].eptr, self.frames[fi].eptr) {
                                    break 'ops MATCH_NOMATCH;
                                }
                            }
                            BraKind::Capture(number) | BraKind::CapturePos(number) => {
                                let pi = p_idx.unwrap_or(0);
                                if self.frames[fi].current_recurse == number {
                                    self.return_from_recursion(fi, pi);
                                    continue 'ops;
                                }
                                let offset = (number as usize) * 2 - 2;
                                let start = self.frames[pi].eptr;
                                let f = &mut self.frames[fi];
                                f.capture_last = number;
                                let e = f.eptr;
                                if offset >= f.offset_top {
                                    f.offset_top = offset + 2;
                                }
                                self.ov_set(fi, offset, start);
                                self.ov_set(fi, offset + 1, e);
                            }
                        }
                        if ket_kind == KetKind::RPos {
                            let pi = p_idx.unwrap_or(0);
                            let f = self.frames[fi];
                            let p = &mut self.frames[pi];
                            p.eptr = f.eptr;
                            p.start_match = f.start_match;
                            p.mark = f.mark;
                            p.recurse_last_used = f.recurse_last_used;
                            p.current_recurse = f.current_recurse;
                            p.capture_last = f.capture_last;
                            p.last_group = f.last_group;
                            p.offset_top = f.offset_top;
                            let ovs = self.ovs;
                            let (lo, hi) = self.ov.split_at_mut(fi * ovs);
                            lo[pi * ovs..(pi + 1) * ovs].copy_from_slice(&hi[..ovs]);
                            break 'ops MATCH_KETRPOS;
                        }
                        let empty = p_idx.is_some_and(|pi| self.frames[fi].eptr == self.frames[pi].eptr);
                        // The JIT does not stop a lazily repeated script run
                        // after an empty iteration: when what follows fails,
                        // it iterates again forever, until its stack runs out.
                        if self.jit && empty && ket_kind == KetKind::RMin && kind == BraKind::ScriptRun {
                            rmatch!(pc + 1, Ret::JitEmptyLoop, 0);
                            continue 'ops;
                        }
                        if ket_kind != KetKind::Ket && !empty {
                            if ket_kind == KetKind::RMin {
                                if self.jit_counts_bracket(bracode, ket_kind) {
                                    jit_count!();
                                }
                                self.frames[fi].t0 = bracode;
                                rmatch!(pc + 1, Ret::KetRMin, 0);
                                continue 'ops;
                            }
                            self.frames[fi].t0 = bracode;
                            rmatch!(bracode, Ret::KetRMax, 0);
                            continue 'ops;
                        }
                        match prog.jit.count[pc] {
                            LoopCount::Normal => {
                                if self.jit_counts_bracket(bracode, ket_kind) {
                                    jit_count!();
                                }
                                if self.jit
                                    && ket_kind == KetKind::Ket
                                    && bracode > 0
                                    && matches!(code[bracode - 1], Op::BraMinZero)
                                    && self.jit_has_alternatives(bracode)
                                {
                                    rmatch!(pc + 1, Ret::JitBraMinZeroAgain, 0);
                                    continue 'ops;
                                }
                            }
                            LoopCount::Exact => {
                                jit_count!();
                            }
                            LoopCount::Silent => {}
                        }
                        self.frames[fi].pc = pc + 1;
                    }
                    Op::CondRef(_) | Op::CondRecurse(_) | Op::CondTrue | Op::CondFalse => {
                        return ERROR_INTERNAL;
                    }
                    Op::Reverse(number) => {
                        let mut e = self.frames[fi].eptr;
                        if utf {
                            for _ in 0..*number {
                                if e <= self.check_subject {
                                    break 'ops MATCH_NOMATCH;
                                }
                                e = self.backchar(e - 1);
                            }
                        } else {
                            if *number as usize > e {
                                break 'ops MATCH_NOMATCH;
                            }
                            e -= *number as usize;
                        }
                        if e < self.start_used_ptr {
                            self.start_used_ptr = e;
                        }
                        let f = &mut self.frames[fi];
                        f.eptr = e;
                        f.pc += 1;
                    }
                    Op::VReverse { min, max } => {
                        let (min, mut max) = (*min, *max);
                        let mut e = self.frames[fi].eptr;
                        if utf {
                            for i in 0..max {
                                if e == 0 {
                                    if i < min {
                                        break 'ops MATCH_NOMATCH;
                                    }
                                    max = i;
                                    break;
                                }
                                e = self.backchar(e - 1);
                            }
                        } else {
                            let available = e.min(65535) as u32;
                            if min > available {
                                break 'ops MATCH_NOMATCH;
                            }
                            if max > available {
                                max = available;
                            }
                            e -= max as usize;
                        }
                        let f = &mut self.frames[fi];
                        f.eptr = e;
                        f.n[0] = min;
                        f.n[1] = max;
                        rmatch!(pc + 1, Ret::VReverse, 0);
                    }
                    Op::Recurse(group) => {
                        let number = *group;
                        let bracode = prog.group_start[number as usize];
                        let f = self.frames[fi];
                        if f.current_recurse != RECURSE_UNSET {
                            let mut off = f.last_group;
                            while off != UNSET && off != 0 {
                                let p = &self.frames[off - 1];
                                if self.frames[off].gft == GF_RECURSE | number {
                                    // The interpreter reports a recursion that would
                                    // repeat forever; the JIT recurses until its
                                    // stack or the match limit runs out.
                                    if !self.jit && f.eptr == p.eptr && self.last_used_ptr == p.recurse_last_used {
                                        return ERROR_RECURSELOOP;
                                    }
                                    break;
                                }
                                off = p.last_group;
                            }
                        }
                        let f = &mut self.frames[fi];
                        f.recurse_last_used = self.last_used_ptr;
                        f.t0 = bracode;
                        f.n[0] = GF_RECURSE | number;
                        if self.jit && !self.prog.jit.recurse_inline[number as usize] {
                            // compile_recurse(): count_match(), then the
                            // saved data.
                            jit_count!();
                            if let Err(e) = self.jit_push(self.prog.jit.recurse[number as usize], fi) {
                                return e;
                            }
                        }
                        rmatch!(bracode + 1, Ret::Recurse, GF_RECURSE | number);
                    }
                    Op::Fail => break 'ops MATCH_NOMATCH,
                    Op::Mark(id) => {
                        let f = &mut self.frames[fi];
                        f.mark = Some(*id);
                        rmatch!(pc + 1, Ret::Mark, 0);
                    }
                    Op::Commit(arg) => {
                        if let Some(id) = arg {
                            self.frames[fi].mark = Some(*id);
                        }
                        rmatch!(pc + 1, Ret::Commit, 0);
                    }
                    Op::Prune(arg) => {
                        if let Some(id) = arg {
                            self.frames[fi].mark = Some(*id);
                        }
                        rmatch!(pc + 1, Ret::Prune, 0);
                    }
                    Op::Skip => {
                        rmatch!(pc + 1, Ret::Skip, 0);
                    }
                    Op::SkipArg(_) => {
                        self.skip_arg_count += 1;
                        if self.skip_arg_count <= self.ignore_skip_arg {
                            self.frames[fi].pc += 1;
                            continue 'ops;
                        }
                        rmatch!(pc + 1, Ret::SkipArg, 0);
                    }
                    Op::Then(arg) => {
                        if let Some(id) = arg {
                            self.frames[fi].mark = Some(*id);
                        }
                        rmatch!(pc + 1, Ret::Then, 0);
                    }
                }
            };

            // RETURN_SWITCH: back to the frame that made the call.
            loop {
                let fi = self.top();
                let f = self.frames[fi];
                if f.eptr > self.last_used_ptr {
                    self.last_used_ptr = f.eptr;
                }
                if f.rdepth == 0 {
                    return rrc;
                }
                let len = self.frames.len() - f.back;
                self.truncate(len);
                let fi = self.top();
                let f = self.frames[fi];
                let pc = f.pc;
                match f.ret {
                    Ret::None => return ERROR_INTERNAL,
                    Ret::RepMin => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        let fr = &mut self.frames[fi];
                        let lmin = fr.n[0];
                        fr.n[0] = lmin.wrapping_add(1);
                        if lmin >= fr.n[1] {
                            rrc = MATCH_NOMATCH;
                            continue;
                        }
                        let Op::Rep { lit, .. } = &code[f.t1] else { return ERROR_INTERNAL };
                        match self.match_one(lit, f.eptr) {
                            Some(e) => self.frames[fi].eptr = e,
                            None => {
                                rrc = MATCH_NOMATCH;
                                continue;
                            }
                        }
                        rmatch!(pc, Ret::RepMin, 0);
                        continue 'exec;
                    }
                    Ret::RepMax => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        let Op::Rep { lit, .. } = &code[f.t1] else { return ERROR_INTERNAL };
                        let mut e = f.eptr - 1;
                        if utf {
                            e = self.backchar(e);
                        }
                        if matches!(lit, Lit::AnyNl) && e > f.t0 && self.s[e] == b'\n' && self.s[e - 1] == b'\r' {
                            e -= 1;
                        }
                        self.frames[fi].eptr = e;
                        if e <= f.t0 {
                            jit_count!();
                            continue 'exec;
                        }
                        rmatch!(pc, Ret::RepMax, 0);
                        continue 'exec;
                    }
                    Ret::RepMaxClass => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        if f.eptr <= f.t0 {
                            continue;
                        }
                        let mut e = f.eptr - 1;
                        if utf {
                            e = self.backchar(e);
                        }
                        self.frames[fi].eptr = e;
                        rmatch!(pc, Ret::RepMaxClass, 0);
                        continue 'exec;
                    }
                    Ret::RepMaxExtuni => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        let start = f.t0;
                        let mut e = f.eptr - 1;
                        let mut c;
                        if utf {
                            e = self.backchar(e);
                            c = unicode::utf8_at(self.s, e).0;
                        } else {
                            c = u32::from(self.s[e]);
                        }
                        let mut rgb = unicode::grapheme_break(c);
                        loop {
                            if e <= start {
                                break;
                            }
                            let mut fptr = e - 1;
                            if utf {
                                fptr = self.backchar(fptr);
                                c = unicode::utf8_at(self.s, fptr).0;
                            } else {
                                c = u32::from(self.s[fptr]);
                            }
                            let lgb = unicode::grapheme_break(c);
                            if unicode::GBTABLE[lgb as usize] & (1 << rgb) == 0 {
                                break;
                            }
                            e = fptr;
                            rgb = lgb;
                        }
                        self.frames[fi].eptr = e;
                        if e <= start {
                            jit_count!();
                            continue 'exec;
                        }
                        rmatch!(pc, Ret::RepMaxExtuni, 0);
                        continue 'exec;
                    }
                    Ret::RefMin => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        let fr = &mut self.frames[fi];
                        let lmin = fr.n[0];
                        fr.n[0] = lmin.wrapping_add(1);
                        if lmin >= fr.n[1] {
                            rrc = MATCH_NOMATCH;
                            continue;
                        }
                        match self.match_ref(fi, f.len, f.n[2] != 0) {
                            Ok(len) => self.frames[fi].eptr += len,
                            Err(()) => {
                                rrc = MATCH_NOMATCH;
                                continue;
                            }
                        }
                        rmatch!(pc, Ret::RefMin, 0);
                        continue 'exec;
                    }
                    Ret::RefMaxSame => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        // Feptr >= Lstart after moving back by the length.
                        if f.eptr < f.t0 + f.t1 {
                            rrc = MATCH_NOMATCH;
                            continue;
                        }
                        let e = f.eptr - f.t1;
                        self.frames[fi].eptr = e;
                        rmatch!(pc, Ret::RefMaxSame, 0);
                        continue 'exec;
                    }
                    Ret::RefMaxRescan => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        if f.eptr == f.t0 {
                            rrc = MATCH_NOMATCH;
                            continue;
                        }
                        let fr = &mut self.frames[fi];
                        fr.eptr = fr.t0;
                        fr.n[1] -= 1;
                        let (lmin, lmax) = (fr.n[0], fr.n[1]);
                        for _ in lmin..lmax {
                            if let Ok(len) = self.match_ref(fi, f.len, f.n[2] != 0) {
                                self.frames[fi].eptr += len;
                            }
                        }
                        rmatch!(pc, Ret::RefMaxRescan, 0);
                        continue 'exec;
                    }
                    Ret::BraZero => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        let ket = self.to_ket(f.t0);
                        // The JIT takes the zero path with the words the
                        // group saved before its first branch still on the
                        // stack (the start position, and the iteration mark
                        // of a repeated group).
                        let words = if matches!(code[ket], Op::Ket { kind: KetKind::RMax, .. }) { 2 } else { 1 };
                        if let Err(e) = self.jit_push(words, fi) {
                            return e;
                        }
                        if prog.jit.count[pc] != LoopCount::Silent {
                            jit_count!();
                        }
                        self.frames[fi].pc = ket + 1;
                        continue 'exec;
                    }
                    Ret::BraMinZero => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        // Trying the group releases the start position
                        // (kept for a lazily repeated group).
                        if self.jit
                            && !matches!(code[self.to_ket(pc + 1)], Op::Ket { kind: KetKind::RMin, .. })
                            && let Some(&(f, w)) = self.jit_alloc.last()
                            && f == fi
                        {
                            self.jit_alloc.pop();
                            self.jit_words -= w;
                        }
                        self.frames[fi].pc = pc + 1;
                        continue 'exec;
                    }
                    Ret::BraPos => {
                        // pc: the bracket or alternative being tried; t1: the
                        // start of the group; t0: position at iteration start.
                        let gft = f.n[0];
                        if rrc == MATCH_KETRPOS {
                            self.frames[fi].n[1] = 1;
                            if self.frames[fi].eptr == f.t0 {
                                let ket = self.to_ket(pc);
                                self.frames[fi].pc = ket;
                            } else {
                                let fr = &mut self.frames[fi];
                                fr.pc = f.t1;
                                fr.t0 = fr.eptr;
                                rmatch!(f.t1 + 1, Ret::BraPos, gft);
                                continue 'exec;
                            }
                        } else {
                            if rrc == MATCH_THEN {
                                let next = self.link(pc);
                                if self.verb_ecode < next && (self.is_alt(pc) || self.is_alt(next)) {
                                    rrc = MATCH_NOMATCH;
                                }
                            }
                            if rrc != MATCH_NOMATCH {
                                continue;
                            }
                            let next = self.link(pc);
                            self.frames[fi].pc = next;
                            if self.is_alt(next) {
                                let fr = &mut self.frames[fi];
                                fr.t0 = fr.eptr;
                                rmatch!(next + 1, Ret::BraPos, gft);
                                continue 'exec;
                            }
                        }
                        // Out of the loop: at the Ket.
                        let fr = &mut self.frames[fi];
                        if fr.n[1] != 0 || fr.n[2] != 0 {
                            fr.pc += 1;
                            fr.n[2] = 0;
                            jit_count!();
                            continue 'exec;
                        }
                        rrc = MATCH_NOMATCH;
                        continue;
                    }
                    Ret::BraBranch => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        let next = self.link(pc);
                        self.frames[fi].pc = next;
                        if self.is_alt(self.link(next)) {
                            rmatch!(next + 1, Ret::BraBranch, 0);
                        } else {
                            self.frames[fi].pc = next + 1;
                        }
                        continue 'exec;
                    }
                    Ret::GroupLoop => {
                        if rrc == MATCH_THEN {
                            let next = self.link(pc);
                            if self.verb_ecode < next && (self.is_alt(pc) || self.is_alt(next)) {
                                rrc = MATCH_NOMATCH;
                            }
                        }
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        let next = self.link(pc);
                        self.frames[fi].pc = next;
                        if !self.is_alt(next) {
                            rrc = MATCH_NOMATCH;
                            continue;
                        }
                        let gft = f.n[0];
                        rmatch!(next + 1, Ret::GroupLoop, gft);
                        continue 'exec;
                    }
                    Ret::Recurse => {
                        let number = f.n[0] & GF_DATAMASK;
                        let next = self.link(f.t0);
                        if (MATCH_BACKTRACK_MIN..=MATCH_BACKTRACK_MAX).contains(&rrc)
                            && self.verb_current_recurse == number
                        {
                            if rrc == MATCH_THEN && self.verb_ecode < next && (self.is_alt(f.t0) || self.is_alt(next)) {
                                rrc = MATCH_NOMATCH;
                            } else {
                                rrc = MATCH_NOMATCH;
                                continue;
                            }
                        }
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        self.frames[fi].t0 = next;
                        if !self.is_alt(next) {
                            rrc = MATCH_NOMATCH;
                            continue;
                        }
                        rmatch!(next + 1, Ret::Recurse, f.n[0]);
                        continue 'exec;
                    }
                    Ret::Assert => {
                        if rrc == MATCH_ACCEPT {
                            self.take_assert_accept(fi, true);
                            self.end = f.saved_end;
                        } else {
                            if rrc != MATCH_NOMATCH && rrc != MATCH_THEN {
                                self.end = f.saved_end;
                                continue;
                            }
                            let next = self.link(pc);
                            self.frames[fi].pc = next;
                            if !self.is_alt(next) {
                                self.end = f.saved_end;
                                rrc = MATCH_NOMATCH;
                                continue;
                            }
                            rmatch!(next + 1, Ret::Assert, GF_NOCAPTURE);
                            {
                                let top = self.top();
                                if let Err(e) = self.jit_push(f.jit_assert, top) {
                                    return e;
                                }
                            }
                            continue 'exec;
                        }
                        let ket = self.to_ket(self.frames[fi].pc);
                        self.frames[fi].pc = ket + 1;
                        continue 'exec;
                    }
                    Ret::AssertNot => match if self.jit && rrc == MATCH_SKIP_ARG { MATCH_SKIP } else { rrc } {
                        MATCH_ACCEPT | MATCH_MATCH => {
                            self.end = f.saved_end;
                            rrc = MATCH_NOMATCH;
                            continue;
                        }
                        MATCH_NOMATCH | MATCH_THEN => {
                            let next = self.link(pc);
                            if !self.is_alt(next) {
                                self.end = f.saved_end;
                                self.frames[fi].pc = next + 1;
                                continue 'exec;
                            }
                            self.frames[fi].pc = next;
                            rmatch!(next + 1, Ret::AssertNot, GF_NOCAPTURE);
                            {
                                let top = self.top();
                                if let Err(e) = self.jit_push(f.jit_assert, top) {
                                    return e;
                                }
                            }
                            continue 'exec;
                        }
                        // The JIT does not let a (*SKIP:NAME) without its mark
                        // out of a negative assertion either: it acts as
                        // (*SKIP) there.
                        MATCH_COMMIT | MATCH_SKIP | MATCH_PRUNE => {
                            self.end = f.saved_end;
                            let ket = self.to_ket(pc);
                            self.frames[fi].pc = ket + 1;
                            continue 'exec;
                        }
                        _ => {
                            self.end = f.saved_end;
                            continue;
                        }
                    },
                    Ret::CondAssert => {
                        let positive = f.n[0] != 0;
                        let condition = match rrc {
                            MATCH_ACCEPT => {
                                self.take_assert_accept(fi, false);
                                positive
                            }
                            MATCH_MATCH => positive,
                            MATCH_NOMATCH | MATCH_THEN => {
                                let next = self.link(f.t0);
                                if self.is_alt(next) {
                                    self.frames[fi].t0 = next;
                                    rmatch!(next + 1, Ret::CondAssert, GF_CONDASSERT);
                                    {
                                        let top = self.top();
                                        if let Err(e) = self.jit_push(f.jit_assert, top) {
                                            return e;
                                        }
                                    }
                                    continue 'exec;
                                }
                                !positive
                            }
                            MATCH_COMMIT | MATCH_SKIP | MATCH_PRUNE => !positive,
                            // As in a negative assertion, for the JIT.
                            MATCH_SKIP_ARG if self.jit => !positive,
                            _ => {
                                self.end = f.saved_end;
                                continue;
                            }
                        };
                        self.end = f.saved_end;
                        // pc is the Cond bracket; the assertion starts at pc + 1.
                        let next = if condition { self.to_ket(pc + 1) + 1 } else { self.frames[fi].len };
                        self.frames[fi].pc = next;
                        if matches!(code[pc], Op::Bra { empty: true, .. }) {
                            rmatch!(next, Ret::SCond, GF_NOCAPTURE);
                        }
                        continue 'exec;
                    }
                    Ret::SCond => {
                        continue;
                    }
                    Ret::VReverse => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        let fr = &mut self.frames[fi];
                        let lmax = fr.n[1];
                        fr.n[1] = lmax.wrapping_sub(1);
                        if lmax <= fr.n[0] {
                            rrc = MATCH_NOMATCH;
                            continue;
                        }
                        let mut e = fr.eptr + 1;
                        if utf {
                            e = self.forwardchar(e);
                        }
                        self.frames[fi].eptr = e;
                        rmatch!(pc + 1, Ret::VReverse, 0);
                        continue 'exec;
                    }
                    Ret::KetRMin => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        self.frames[fi].pc = f.t0;
                        continue 'exec;
                    }
                    Ret::JitEmptyLoop => {
                        if rrc == MATCH_NOMATCH {
                            return ERROR_JIT_STACKLIMIT;
                        }
                        continue;
                    }
                    Ret::JitBraMinZeroAgain => {
                        if rrc == MATCH_NOMATCH {
                            jit_count!();
                        }
                        continue;
                    }
                    Ret::KetRMax => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        if self.jit_counts_bracket(f.t0, KetKind::RMax) {
                            jit_count!();
                        }
                        self.frames[fi].pc = pc + 1;
                        continue 'exec;
                    }
                    Ret::Mark => {
                        if let Op::Mark(id) = code[pc]
                            && rrc == MATCH_SKIP_ARG
                            && prog.marks[id as usize] == prog.marks[self.verb_skip_name as usize]
                        {
                            self.verb_skip_ptr = f.eptr;
                            rrc = MATCH_SKIP;
                        }
                        continue;
                    }
                    Ret::Commit | Ret::Prune | Ret::Skip | Ret::SkipArg | Ret::Then => {
                        if rrc != MATCH_NOMATCH {
                            continue;
                        }
                        self.verb_current_recurse = f.current_recurse;
                        rrc = match f.ret {
                            Ret::Commit => MATCH_COMMIT,
                            Ret::Prune => MATCH_PRUNE,
                            Ret::Skip => {
                                self.verb_skip_ptr = f.eptr;
                                MATCH_SKIP
                            }
                            Ret::SkipArg => {
                                let Op::SkipArg(id) = code[pc] else { return ERROR_INTERNAL };
                                self.verb_skip_name = id;
                                MATCH_SKIP_ARG
                            }
                            _ => {
                                self.verb_ecode = pc;
                                MATCH_THEN
                            }
                        };
                        continue;
                    }
                }
            }
        }
    }

    /// Copies the captures and mark saved by `(*ACCEPT)` in an assertion.
    fn take_assert_accept(&mut self, fi: usize, with_mark: bool) {
        if let Some((ov, top, mark)) = self.assert_accept.take() {
            let ovs = self.ovs;
            self.ov[fi * ovs..fi * ovs + top].copy_from_slice(&ov[..top]);
            let f = &mut self.frames[fi];
            f.offset_top = top;
            if with_mark {
                f.mark = mark;
            }
        }
    }

    /// End of a recursion: reinstate the captures of the frame before it
    /// and continue after the recursion item.
    fn return_from_recursion(&mut self, fi: usize, pi: usize) {
        let p = self.frames[pi];
        let ovs = self.ovs;
        let top = self.frames[fi].offset_top;
        let (lo, hi) = self.ov.split_at_mut(fi * ovs);
        hi[..top].copy_from_slice(&lo[pi * ovs..pi * ovs + top]);
        let f = &mut self.frames[fi];
        f.last_group = p.last_group;
        f.offset_top = p.offset_top;
        f.capture_last = p.capture_last;
        f.current_recurse = p.current_recurse;
        f.pc = p.pc + 1;
    }

    fn check_assert(&mut self, a: Assert, e: usize, _fi: usize) -> bool {
        match a {
            Assert::Circ => e == 0,
            Assert::Sod => e == 0,
            Assert::Dollar => {
                if self.poptions & opt::DOLLAR_ENDONLY == 0 {
                    self.nl_or_eos(e)
                } else {
                    e >= self.end
                }
            }
            Assert::Eod => e >= self.end,
            Assert::Eodn => self.nl_or_eos(e),
            Assert::CircM => e == 0 || (e != self.end && self.was_newline(e)),
            Assert::DollarM => e >= self.end || self.is_newline(e),
            Assert::Som => e == self.start_offset,
            Assert::WordBoundary { ucp, negated } => {
                let prev_is_word = if e == self.check_subject {
                    false
                } else {
                    let mut last = e - 1;
                    let fc = if self.utf {
                        last = self.backchar(last);
                        unicode::utf8_at(self.s, last).0
                    } else {
                        u32::from(self.s[last])
                    };
                    if last < self.start_used_ptr {
                        self.start_used_ptr = last;
                    }
                    is_word(fc, ucp)
                };
                let cur_is_word = if e >= self.end {
                    false
                } else {
                    let (fc, len) = self.getchar(e);
                    let next = if self.utf { self.forwardchar(e + 1) } else { e + len };
                    if next > self.last_used_ptr {
                        self.last_used_ptr = next;
                    }
                    is_word(fc, ucp)
                };
                if negated { cur_is_word == prev_is_word } else { cur_is_word != prev_is_word }
            }
        }
    }

    /// `ASSERT_NL_OR_EOS`: at the end, or before a final newline.
    fn nl_or_eos(&mut self, e: usize) -> bool {
        if e < self.end {
            let nl = self.is_newline(e);
            !(!nl || e != self.end - self.nllen)
        } else {
            true
        }
    }

    /// `PRIV(script_run)`.
    fn script_run(&self, mut p: usize, end: usize) -> bool {
        const UCP_COMMON: u32 = SCRIPT_COMMON;
        const UCP_INHERITED: u32 = SCRIPT_INHERITED;
        const UCP_UNKNOWN: u32 = SCRIPT_UNKNOWN;
        if p >= end {
            return true;
        }
        let (mut c, len) = self.getchar(p);
        p += len;
        if p >= end {
            return true;
        }
        let words = (SCRIPT_COUNT / 32 + 1) as usize;
        let ucd_words = (UCP_UNKNOWN / 32 + 1) as usize;
        let mut require_state = 0u32; // UNSET
        let mut require_map = vec![0u32; words];
        let mut map = vec![0u32; words];
        let mut require_digitset = 0u32;
        loop {
            let rec = unicode::record(c);
            let script = u32::from(rec.script);
            if script == UCP_UNKNOWN {
                return false;
            }
            let scriptx = u32::from(rec.scriptx_bidiclass & 0x3ff);
            if scriptx != 0 || (script != UCP_INHERITED && script != UCP_COMMON) {
                for (i, m) in map.iter_mut().enumerate() {
                    *m = if i < ucd_words { super::ucd::SCRIPT_SETS[scriptx as usize + i] } else { 0 };
                }
                if script != UCP_COMMON && script != UCP_INHERITED {
                    map[(script / 32) as usize] |= 1 << (script % 32);
                }
                let bit = |m: &[u32], s: u32| m[(s / 32) as usize] & (1 << (s % 32)) != 0;
                match require_state {
                    0 => {
                        require_state = match script {
                            SCRIPT_HAN => 2,
                            SCRIPT_HIRAGANA | SCRIPT_KATAKANA => 3,
                            SCRIPT_BOPOMOFO => 4,
                            SCRIPT_HANGUL => 5,
                            _ => {
                                require_map.copy_from_slice(&map);
                                1
                            }
                        };
                    }
                    2 => {
                        if script != SCRIPT_HAN {
                            let mut chspecial = 0;
                            if bit(&map, SCRIPT_BOPOMOFO) {
                                chspecial |= 1;
                            }
                            if bit(&map, SCRIPT_HIRAGANA) {
                                chspecial |= 2;
                            }
                            if bit(&map, SCRIPT_KATAKANA) {
                                chspecial |= 4;
                            }
                            if bit(&map, SCRIPT_HANGUL) {
                                chspecial |= 8;
                            }
                            if chspecial == 0 {
                                return false;
                            }
                            if chspecial == 1 {
                                require_state = 4;
                            } else if chspecial == 2 | 4 {
                                require_state = 3;
                            }
                        }
                    }
                    3 => {
                        if !bit(&map, SCRIPT_HAN) && !bit(&map, SCRIPT_HIRAGANA) && !bit(&map, SCRIPT_KATAKANA) {
                            return false;
                        }
                    }
                    4 => {
                        if !bit(&map, SCRIPT_HAN) && !bit(&map, SCRIPT_BOPOMOFO) {
                            return false;
                        }
                    }
                    5 => {
                        if !bit(&map, SCRIPT_HAN) && !bit(&map, SCRIPT_HANGUL) {
                            return false;
                        }
                    }
                    _ => {
                        if !require_map.iter().zip(map.iter()).any(|(a, b)| a & b != 0) {
                            return false;
                        }
                        match script {
                            SCRIPT_HAN => require_state = 2,
                            SCRIPT_HIRAGANA | SCRIPT_KATAKANA => require_state = 3,
                            SCRIPT_BOPOMOFO => require_state = 4,
                            SCRIPT_HANGUL => require_state = 5,
                            _ => {
                                for (r, m) in require_map.iter_mut().zip(map.iter()) {
                                    *r &= m;
                                }
                            }
                        }
                    }
                }
            }
            if u32::from(rec.chartype) == unicode::ND {
                let sets = &super::ucd::DIGIT_SETS;
                let digitset = if c <= sets[1] {
                    1
                } else {
                    let mut bot = 1usize;
                    let mut top = sets[0] as usize;
                    loop {
                        if top <= bot + 1 {
                            break top as u32;
                        }
                        let mid = (top + bot) / 2;
                        if c <= sets[mid] {
                            top = mid;
                        } else {
                            bot = mid;
                        }
                    }
                };
                if require_digitset == 0 {
                    require_digitset = digitset;
                } else if digitset != require_digitset {
                    return false;
                }
            }
            if p >= end {
                return true;
            }
            let (nc, len) = self.getchar(p);
            c = nc;
            p += len;
        }
    }
}

// The script numbers used by the script run check (`ucp_Common` ...), from
// pcre2_ucp.h of PCRE2 10.44.
const SCRIPT_UNKNOWN: u32 = 68;
const SCRIPT_COMMON: u32 = 69;
const SCRIPT_INHERITED: u32 = 84;
const SCRIPT_HAN: u32 = 23;
const SCRIPT_HIRAGANA: u32 = 20;
const SCRIPT_KATAKANA: u32 = 21;
const SCRIPT_BOPOMOFO: u32 = 22;
const SCRIPT_HANGUL: u32 = 18;
const SCRIPT_COUNT: u32 = 164;

fn is_word(c: u32, ucp: bool) -> bool {
    if ucp {
        let chartype = unicode::chartype(c);
        let category = unicode::gentype_of(chartype);
        category == unicode::GC_L || category == unicode::GC_N || chartype == unicode::MN || chartype == unicode::PC
    } else {
        c <= 255 && unicode::ctypes(c) & CTYPE_WORD != 0
    }
}

/// `OP_PROP` property test (without the negation).
pub fn prop_matches(c: u32, ptype: u32, pdata: u32) -> bool {
    let chartype = unicode::chartype(c);
    let gentype = unicode::gentype_of(chartype);
    match ptype {
        PT_ANY => true,
        PT_LAMP => chartype == unicode::LU || chartype == unicode::LL || chartype == unicode::LT,
        PT_GC => pdata == gentype,
        PT_PC => pdata == chartype,
        PT_SC => pdata == unicode::script(c),
        PT_SCX => unicode::has_script_extension(c, pdata),
        PT_ALNUM => gentype == unicode::GC_L || gentype == unicode::GC_N,
        PT_SPACE | PT_PXSPACE => {
            if unicode::is_hspace(c) || unicode::is_vspace(c) {
                true
            } else {
                gentype == unicode::GC_Z
            }
        }
        PT_WORD => {
            gentype == unicode::GC_L || gentype == unicode::GC_N || chartype == unicode::MN || chartype == unicode::PC
        }
        PT_CLIST => {
            let mut k = pdata as usize;
            loop {
                let x = super::ucd::CASELESS_SETS[k];
                if c < x {
                    return false;
                }
                if c == x {
                    return true;
                }
                k += 1;
            }
        }
        PT_UCNC => {
            c == u32::from(b'$')
                || c == u32::from(b'@')
                || c == u32::from(b'`')
                || (0xa0..=0xd7ff).contains(&c)
                || c >= 0xe000
        }
        PT_BIDICL => unicode::bidi_class(c) == pdata,
        PT_BOOL => unicode::has_bool_property(c, pdata),
        _ => false,
    }
}

/// `PRIV(xclass)`.
pub(crate) fn xclass(c: u32, negated: bool, has_prop: bool, map: Option<&[u8; 32]>, items: &[XItem]) -> bool {
    if c < 256 {
        if !has_prop {
            return match map {
                None => negated,
                Some(m) => m[(c / 8) as usize] & (1 << (c & 7)) != 0,
            };
        }
        if let Some(m) = map
            && m[(c / 8) as usize] & (1 << (c & 7)) != 0
        {
            return !negated;
        }
    }
    for item in items {
        match *item {
            XItem::Range(x, y) => {
                if c >= x && c <= y {
                    return !negated;
                }
            }
            XItem::Prop { positive: isprop, ptype, pdata } => {
                let chartype = unicode::chartype(c);
                let gentype = unicode::gentype_of(chartype);
                let ok = match ptype {
                    PT_ANY => {
                        if isprop {
                            return !negated;
                        }
                        continue;
                    }
                    PT_UCNC => {
                        if c < 0xa0 {
                            c == u32::from(b'$') || c == u32::from(b'@') || c == u32::from(b'`')
                        } else {
                            !(0xd800..=0xdfff).contains(&c)
                        }
                    }
                    PT_PXGRAPH => {
                        gentype != unicode::GC_Z
                            && (gentype != unicode::GC_C
                                || (chartype == unicode::CF
                                    && c != 0x061c
                                    && c != 0x180e
                                    && !(0x2066..=0x2069).contains(&c)))
                    }
                    PT_PXPRINT => {
                        chartype != unicode::ZL
                            && chartype != unicode::ZP
                            && (gentype != unicode::GC_C
                                || (chartype == unicode::CF && c != 0x061c && !(0x2066..=0x2069).contains(&c)))
                    }
                    PT_PXPUNCT => gentype == unicode::GC_P || (c < 128 && gentype == unicode::GC_S),
                    PT_PXXDIGIT => {
                        (0x30..=0x39).contains(&c)
                            || (0x41..=0x46).contains(&c)
                            || (0x61..=0x66).contains(&c)
                            || (0xff10..=0xff19).contains(&c)
                            || (0xff21..=0xff26).contains(&c)
                            || (0xff41..=0xff46).contains(&c)
                    }
                    PT_CLIST => continue,
                    _ => prop_matches(c, ptype, pdata),
                };
                if ok == isprop {
                    return !negated;
                }
            }
        }
    }
    negated
}

/// The result of [`exec`].
pub enum Exec {
    Match(MatchData),
    NoMatch,
    Error(i32),
}

/// `REQ_CU_MAX`: how far an anchored pattern looks for its required code
/// unit.
const REQ_CU_MAX: usize = 5000;

/// The bumpalong loop of `pcre2_match()`, with its start-of-match
/// optimizations.
pub fn exec(m: &mut Matcher<'_>, start_offset: usize, options: u32, info: &StartInfo, has_cr_or_lf: bool) -> Exec {
    let s = m.s;
    let end = m.end;
    m.start_offset = start_offset;
    m.moptions = options & (NOTEMPTY | NOTEMPTY_ATSTART);
    m.ignore_skip_arg = 0;
    let anchored = info.anchored || options & ANCHORED != 0;
    let first = info.first_cu;
    let start_bits = if info.startline { None } else { info.start_bits };
    let mut req_cu_ptr: isize = start_offset as isize - 1;
    let mut start_match = start_offset;
    loop {
        if !info.disabled {
            if anchored {
                if first.is_some() || start_bits.is_some() {
                    let mut ok = start_match < end;
                    if ok {
                        let c = s[start_match];
                        ok = first.is_some_and(|(a, b)| c == a || c == b);
                        if !ok && let Some(bits) = &start_bits {
                            ok = bits[usize::from(c / 8)] & (1 << (c & 7)) != 0;
                        }
                    }
                    if !ok {
                        return Exec::NoMatch;
                    }
                }
            } else if let Some((a, b)) = first {
                start_match = s[start_match..].iter().position(|&c| c == a || c == b).map_or(end, |i| start_match + i);
                if start_match >= end {
                    return Exec::NoMatch;
                }
            } else if info.startline {
                if start_match > start_offset {
                    while start_match < end && !m.was_newline(start_match) {
                        start_match += 1;
                        if m.utf {
                            start_match = m.forwardchar(start_match);
                        }
                    }
                    if s[start_match - 1] == b'\r'
                        && matches!(m.newline, Newline::Any | Newline::AnyCrLf)
                        && start_match < end
                        && s[start_match] == b'\n'
                    {
                        start_match += 1;
                    }
                }
            } else if let Some(bits) = &start_bits {
                while start_match < end {
                    let c = s[start_match];
                    if bits[usize::from(c / 8)] & (1 << (c & 7)) != 0 {
                        break;
                    }
                    start_match += 1;
                }
                if start_match >= end {
                    return Exec::NoMatch;
                }
            }
            if end - start_match < info.minlength {
                return Exec::NoMatch;
            }
            let p = start_match + usize::from(first.is_some());
            if let Some((rc, rc2)) = info.req_cu
                && p as isize > req_cu_ptr
            {
                let check_length = end - start_match;
                if check_length < REQ_CU_MAX || (!anchored && check_length < REQ_CU_MAX * 1000) {
                    let find = |c: u8| s.get(p..).and_then(|t| t.iter().position(|&x| x == c)).map(|i| p + i);
                    let found = if rc != rc2 { find(rc).or_else(|| find(rc2)) } else { find(rc) };
                    match found {
                        Some(q) => req_cu_ptr = q as isize,
                        None => return Exec::NoMatch,
                    }
                }
            }
        }
        m.start_used_ptr = start_match;
        m.last_used_ptr = start_match;
        m.match_call_count = 0;
        m.skip_arg_count = 0;
        m.result = None;
        let rc = m.run(start_match);
        m.end = m.true_end;
        let new_start_match;
        match rc {
            MATCH_SKIP_ARG => {
                new_start_match = start_match;
                m.ignore_skip_arg = m.skip_arg_count;
            }
            MATCH_SKIP if m.verb_skip_ptr > start_match => {
                new_start_match = m.verb_skip_ptr;
            }
            MATCH_SKIP | MATCH_NOMATCH | MATCH_PRUNE | MATCH_THEN => {
                m.ignore_skip_arg = 0;
                let mut n = start_match + 1;
                if m.utf {
                    n = m.forwardchar(n);
                }
                new_start_match = n;
            }
            MATCH_COMMIT => return Exec::NoMatch,
            MATCH_MATCH => {
                return match m.result.take() {
                    Some(r) => Exec::Match(r),
                    None => Exec::Error(ERROR_INTERNAL),
                };
            }
            e => return Exec::Error(e),
        }
        start_match = new_start_match;
        if anchored || start_match > end {
            return Exec::NoMatch;
        }
        if start_match > start_offset
            && s[start_match - 1] == b'\r'
            && start_match < end
            && s[start_match] == b'\n'
            && !has_cr_or_lf
            && (matches!(m.newline, Newline::Any | Newline::AnyCrLf) || m.nllen == 2)
        {
            start_match += 1;
        }
    }
}
