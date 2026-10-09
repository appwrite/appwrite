//! Lowering of the compiled tree into the code that the matcher runs.
//!
//! The layout follows the code `pcre2_compile()` generates, because the
//! matcher (a port of `match()` in `pcre2_match.c`) depends on it: every
//! group is a `Bra` whose link points to its first `Alt` or its `Ket`, each
//! `Alt` links to the next, and the `Ket` points back to the `Bra`. Group
//! repetition is expanded as `compile_branch()` does it: the minimum number
//! of copies, then nested optional copies (`BraZero`) for a bounded maximum
//! or a looping last copy (`KetRMax`/`KetRMin`) for an unbounded one, with
//! possessive groups turned into `BraPos`/`KetRPos` or wrapped in an atomic
//! group. Code positions are in pattern order, which `(*THEN)` relies on.

use super::compile::{Assert, Cond, GroupKind, Lit, Node, RepKind, Reverse, Tree, Units};
use super::parse::REPEAT_UNLIMITED;

pub const UNLIMITED: u32 = u32::MAX;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum BraKind {
    /// The whole pattern (no group frame is recorded).
    Root,
    /// Non-capturing group (`OP_SBRA`: always records its start).
    Bra,
    Capture(u32),
    Once,
    ScriptRun,
    BraPos,
    CapturePos(u32),
    Assert,
    AssertNot,
    AssertBack,
    AssertBackNot,
    AssertNa,
    AssertBackNa,
    Cond,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum KetKind {
    Ket,
    RMax,
    RMin,
    RPos,
}

#[derive(Debug, Clone)]
pub enum Op {
    End,
    Item(Lit),
    Rep {
        lit: Lit,
        min: u32,
        max: u32,
        kind: RepKind,
    },
    /// Back reference; `groups` has several entries for a duplicated name
    /// (the first set one is used).
    Ref {
        groups: Box<[u32]>,
        caseless: bool,
        min: u32,
        max: u32,
        lazy: bool,
        repeated: bool,
    },
    Assert(Assert),
    SetSom,
    /// `empty`: the `OP_S...` form of a repeated group that could match an
    /// empty string.
    Bra {
        kind: BraKind,
        link: usize,
        empty: bool,
    },
    Alt {
        link: usize,
    },
    Ket {
        bra: usize,
        kind: KetKind,
    },
    BraZero,
    BraMinZero,
    SkipZero,
    BraPosZero,
    /// Conditions, immediately after a `Cond` bracket.
    CondRef(Box<[u32]>),
    CondRecurse(Option<Box<[u32]>>),
    CondTrue,
    CondFalse,
    Reverse(u32),
    VReverse {
        min: u32,
        max: u32,
    },
    Recurse(u32),
    Close(u32),
    Accept,
    AssertAccept,
    Fail,
    Mark(u32),
    Commit(Option<u32>),
    Prune(Option<u32>),
    Skip,
    SkipArg(u32),
    Then(Option<u32>),
}

#[derive(Debug, Clone)]
pub struct Program {
    pub code: Vec<Op>,
    pub top_bracket: u32,
    /// Position of the first `Bra` of each group (`[0]` is the root).
    pub group_start: Vec<usize>,
    pub marks: Vec<Vec<u8>>,
    pub names: Vec<(Vec<u8>, u32)>,
    pub max_lookbehind: u32,
    pub has_then: bool,
    pub units: Units,
    pub match_empty: bool,
    pub had_accept: bool,
    pub had_pruneorskip: bool,
    pub backref_map: u32,
    pub top_backref: u32,
    pub has_cr_or_lf: bool,
    pub dupcap_used: bool,
    /// How the PCRE2 JIT lays out its stack for this pattern (see
    /// [`JitLayout`]).
    pub jit: JitLayout,
}

/// The JIT's stack usage that does not depend on the subject: the words an
/// iterator inside a repeated group keeps per entry (`rep`, by position;
/// iterators elsewhere use a fixed slot), whether a capturing group saves
/// its previous offsets in a fixed slot (`optimized`, by group: not when a
/// back reference or condition refers to it) and what a recursion into a
/// group costs (`recurse`, `recurse_end`, `recurse_inline`, by group).
#[derive(Debug, Clone, Default)]
pub struct JitLayout {
    pub rep: Vec<u8>,
    pub optimized: Vec<bool>,
    /// The words a recursion allocates when it enters the group
    /// (`compile_recurse()`): the data it saves
    /// (`get_recurse_data_length()`), its return address and, for a group
    /// with alternatives, the start position.
    pub recurse: Vec<u32>,
    /// The words a recursion leaves when it matches: 1, or 2 for a group
    /// with alternatives or `(*ACCEPT)` inside.
    pub recurse_end: Vec<u32>,
    /// Whether a recursion into the group is compiled inline (a group of
    /// simple items, `get_framesize() == no_stack`): it allocates nothing
    /// and counts no match step.
    pub recurse_inline: Vec<bool>,
    /// By position of an atomic group: the words of the frame it saves
    /// (`get_framesize()`), `None` when it needs none.
    pub once_frame: Vec<Option<u32>>,
    /// The pattern has `(*SKIP:NAME)`: a mark saves five words.
    pub skip_arg: bool,
    /// By position: how the JIT counts match steps at a bracket that the
    /// compiler copied for a quantifier and the JIT runs as a counted loop.
    pub count: Vec<LoopCount>,
    /// By position of an iterator: its early-fail slot
    /// (`detect_early_fail()`).
    pub early: Vec<EarlyFail>,
    /// The number of early-fail slots (a range takes two).
    pub early_slots: usize,
    /// The slot of the iterator that starts the pattern, whose end is where
    /// the next match attempt starts (`fast_forward_bc_ptr`).
    pub fast_forward: Option<usize>,
    /// By position of a greedy iterator followed by a character: that
    /// character (with its other-case bit set) and the other-case bit. The
    /// JIT only stops where the character follows and consumes it
    /// (`charpos_enabled`).
    pub charpos: Vec<Option<(u8, u8)>>,
    /// By position of a possessive group: the words entering it takes
    /// after `BRAPOSZERO` and without (`compile_bracketpos_matchingpath()`).
    pub brapos: Vec<[u32; 2]>,
    /// By position of an assertion: the words it holds while it runs. An
    /// atomic one (`compile_assert_matchingpath()`) saves its frame, the
    /// start position unless only `^ $ \b` are inside, and the subject end
    /// for a variable-length lookbehind; a non-atomic one is a bracket that
    /// saves the start (and the end, variable-length).
    pub assert_words: Vec<u32>,
    /// By `Bra` position: a copy of a bounded repeat that the JIT runs as
    /// one counted loop (`OP_UPTO`/`OP_MINUPTO`), and whether it is the
    /// copy the loop starts with.
    pub loop_copy: Vec<Option<(LoopKind, bool)>>,
    /// By position: a `BRAZERO`/`BRAMINZERO` between the copies of such a
    /// loop (it saves nothing).
    pub loop_zero: Vec<bool>,
    /// By `Bra` position: a group wrapping copies of such a loop (it saves
    /// nothing).
    pub loop_wrapper: Vec<bool>,
    /// By position of an atomic assertion: the words it leaves when it
    /// holds (the frame a positive one saved, the mark of an optional one).
    pub assert_kept: Vec<u32>,
    /// By position of an atomic assertion: the words it leaves as the
    /// condition of a group when its branch matched (the frame it saved).
    pub cond_kept: Vec<u32>,
    /// By position of the first op of a branch: the words of the trap that
    /// a `(*THEN)` in the branch returns to
    /// (`compile_then_trap_matchingpath()`), 0 for none.
    pub then_trap: Vec<u32>,
}

/// A bounded repeat of a group, as the JIT runs it.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum LoopKind {
    /// Greedy: `KETRMAX` with a counter.
    Upto,
    /// Lazy: `KETRMIN` with a counter saved at each iteration.
    MinUpto,
}

/// The JIT's early fail of an iterator near the start of the pattern
/// (`detect_early_fail()`), with its slot: where the iterator ended in an
/// earlier attempt of the same match call.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum EarlyFail {
    #[default]
    None,
    /// The first item of the pattern: the next attempt starts after where
    /// it ended (`type_skip`).
    Skip(usize),
    /// Fails at once when entered at or before where it ended
    /// (`type_fail`).
    Fail(usize),
    /// Fails at once when entered inside the range it covered last; the
    /// slot and the next hold the range end and start (`type_fail_range`).
    Range(usize),
}

/// `has_skip_in_assert_back`: a `(*SKIP)` inside a positive lookbehind
/// (which turns early fail off).
pub fn skip_in_lookbehind(code: &[Op]) -> bool {
    let mut end = 0;
    for (pc, op) in code.iter().enumerate() {
        match op {
            Op::Bra { kind: BraKind::AssertBack, .. } => end = end.max(ket_of(code, pc)),
            Op::Skip | Op::SkipArg(_) if pc < end => return true,
            _ => {}
        }
    }
    false
}

/// What the JIT layout depends on besides the code.
pub struct JitOptions {
    /// `detect_early_fail()` runs: the pattern is not anchored, start
    /// optimizations are on and no `(*SKIP)` is inside a lookbehind.
    pub early_fail: bool,
    pub utf: bool,
    pub ucp: bool,
}

/// How the match steps of a copied group are counted (`detect_repeat()`
/// loops): the JIT runs the copies as iterations of one bracket.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum LoopCount {
    /// Not part of a copied group.
    #[default]
    Normal,
    /// A copy of an exact repeat: one step when it ends.
    Exact,
    /// Inside the optional copies of a bounded repeat: the loop counts one
    /// step when it is left (at the outermost copy's `Ket` or zero path),
    /// none here.
    Silent,
}

impl JitLayout {
    /// `set_private_data_ptrs()` and the parts of `check_opcode_types()`
    /// that decide stack usage.
    pub fn new(code: &[Op], top_bracket: u32, group_start: &[usize], options: &JitOptions) -> JitLayout {
        let mut optimized = vec![true; top_bracket as usize + 1];
        for op in code {
            match op {
                Op::Ref { groups, .. } | Op::CondRef(groups) => {
                    for &g in groups.iter() {
                        optimized[g as usize] = false;
                    }
                }
                Op::Bra { kind: BraKind::CapturePos(n), .. } => optimized[*n as usize] = false,
                _ => {}
            }
        }
        let mut early = EarlyFailScan {
            code,
            optimized: &optimized,
            early: vec![EarlyFail::None; code.len()],
            marked: vec![false; code.len()],
            slots: 0,
            fast_forward: None,
        };
        if options.early_fail {
            early.detect(0, 0, 0);
        }
        let EarlyFailScan { early, marked, slots: early_slots, fast_forward, .. } = early;
        let walk = Walk::new(code, &marked);
        // Iterators inside a repeated group keep their state on the stack,
        // and so does a greedy `\R` or `\X` repeat anywhere.
        let mut rep = vec![0u8; code.len()];
        for (pc, op) in code.iter().enumerate() {
            if let Op::Rep { lit, min, max, kind } = op
                && (walk.in_region[pc] || stacked_iterator(lit, *min, *max, *kind))
            {
                rep[pc] = iterator_words(lit, *min, *max, *kind);
            }
        }
        let flags = PatternFlags::new(code);
        let groups = group_start.len();
        let mut recurse = vec![0u32; groups];
        let mut recurse_end = vec![0u32; groups];
        let mut recurse_inline = vec![false; groups];
        for (g, &start) in group_start.iter().enumerate() {
            if start == usize::MAX {
                continue;
            }
            let ket = ket_of(code, start);
            if code[start + 1..ket].iter().all(simple_item) {
                recurse_inline[g] = true;
                continue;
            }
            let alternatives = {
                let mut n = 1;
                let mut k = start;
                while let Op::Bra { link, .. } | Op::Alt { link } = code[k] {
                    if matches!(code[link], Op::Alt { .. }) {
                        n += 1;
                    }
                    k = link;
                }
                n
            };
            let (length, accept) = recurse_data_length(code, start + 1, ket, &walk, &optimized, &flags);
            let local = if alternatives > 1 { 2 } else { 1 };
            recurse[g] = length + local;
            recurse_end[g] = if alternatives > 1 || accept { 2 } else { 1 };
        }
        let mut once_frame = vec![None; code.len()];
        for (pc, op) in code.iter().enumerate() {
            if let Op::Bra { kind: BraKind::Once, .. } = op {
                once_frame[pc] = framesize(code, pc, &flags).0;
            }
        }
        let skip_arg = code.iter().any(|op| matches!(op, Op::SkipArg(_)));
        let mut count = vec![LoopCount::Normal; code.len()];
        let mut loop_copy = vec![None; code.len()];
        let mut loop_zero = vec![false; code.len()];
        let mut loop_wrapper = vec![false; code.len()];
        for (&ket, &(resume, exact)) in &walk.repeat {
            if exact {
                let len = ket + 1 - bra_of(code, ket);
                let mut k = ket;
                while k < resume {
                    count[k] = LoopCount::Exact;
                    k += len;
                }
                continue;
            }
            // The copy that loops, then `Z Bra copy` levels and the
            // innermost `Z copy`, closed by the levels' `Ket`s; only the
            // outermost zero and `Ket` count.
            count[ket] = LoopCount::Silent;
            let len = ket + 1 - bra_of(code, ket);
            let levels = (resume - ket - 2 - len) / (3 + len);
            let kind = if matches!(code[ket + 1], Op::BraMinZero) { LoopKind::MinUpto } else { LoopKind::Upto };
            loop_copy[bra_of(code, ket)] = Some((kind, true));
            let mut pc = ket + 1;
            for level in 0..levels {
                if level > 0 {
                    count[pc] = LoopCount::Silent;
                }
                count[pc + 1 + len] = LoopCount::Silent;
                loop_zero[pc] = true;
                loop_wrapper[pc + 1] = true;
                loop_copy[pc + 2] = Some((kind, false));
                pc += 2 + len;
            }
            count[pc] = LoopCount::Silent;
            count[pc + len] = LoopCount::Silent;
            loop_zero[pc] = true;
            loop_copy[pc + 1] = Some((kind, false));
            count[pc + 1 + len..resume - 1].fill(LoopCount::Silent);
        }
        // The copies run the code of the first one: what is marked inside it
        // (nested loops) holds for them too, innermost loops first.
        let mut marks: Vec<(usize, usize, bool)> = walk.repeat.iter().map(|(&k, &(r, e))| (k, r, e)).collect();
        marks.sort_by_key(|&(ket, resume, _)| (resume - bra_of(code, ket), ket));
        for (ket, resume, exact) in marks {
            let first = bra_of(code, ket);
            let len = ket + 1 - first;
            let mut copies = Vec::new();
            if exact {
                let mut at = ket + 1;
                while at < resume {
                    copies.push(at);
                    at += len;
                }
            } else {
                let levels = (resume - ket - 2 - len) / (3 + len);
                let mut pc = ket + 1;
                for _ in 0..levels {
                    copies.push(pc + 2);
                    pc += 2 + len;
                }
                copies.push(pc + 1);
            }
            for at in copies {
                for i in 1..len - 1 {
                    count[at + i] = count[first + i];
                    loop_copy[at + i] = loop_copy[first + i];
                    loop_zero[at + i] = loop_zero[first + i];
                    loop_wrapper[at + i] = loop_wrapper[first + i];
                }
            }
        }
        let charpos = (0..code.len()).map(|pc| charpos(code, pc, options)).collect();
        let mut assert_words = vec![0; code.len()];
        let mut assert_kept = vec![0; code.len()];
        let mut cond_kept = vec![0; code.len()];
        for (pc, op) in code.iter().enumerate() {
            let Op::Bra { kind, .. } = op else { continue };
            let vreverse = || {
                let mut branch = pc;
                loop {
                    if matches!(code[branch + 1], Op::VReverse { .. }) {
                        return true;
                    }
                    let (Op::Bra { link, .. } | Op::Alt { link }) = code[branch] else { return false };
                    if !matches!(code[link], Op::Alt { .. }) {
                        return false;
                    }
                    branch = link;
                }
            };
            assert_words[pc] = match kind {
                BraKind::AssertNa => 1,
                BraKind::AssertBackNa => {
                    if vreverse() {
                        4
                    } else {
                        1
                    }
                }
                BraKind::Assert | BraKind::AssertNot | BraKind::AssertBack | BraKind::AssertBackNot => {
                    let (frame, control_head) = framesize(code, pc, &flags);
                    let prefixed = pc > 0 && matches!(code[pc - 1], Op::BraZero | Op::BraMinZero);
                    let back = matches!(kind, BraKind::AssertBack | BraKind::AssertBackNot) && vreverse();
                    // A frame comes with the start position and the
                    // previous frame pointer.
                    let saved = match frame {
                        Some(f) => f + 2,
                        None => u32::from(prefixed || saves_start(code, pc)),
                    };
                    let end_block = if back { 3 } else { 0 };
                    // `compile_assert_matchingpath()` when the assertion
                    // holds: a positive one keeps its frame (and, when
                    // optional, its start); an optional one keeps a mark.
                    let positive = matches!(kind, BraKind::Assert | BraKind::AssertBack);
                    cond_kept[pc] = frame.map_or(0, |f| f + 1);
                    assert_kept[pc] = match (positive, frame, prefixed) {
                        (true, Some(f), false) => f + 1,
                        (true, Some(f), true) => f + end_block + 2,
                        (_, _, true) => 1,
                        _ => 0,
                    };
                    saved + end_block + u32::from(control_head)
                }
                _ => 0,
            };
        }
        let mut brapos = vec![[0; 2]; code.len()];
        for (pc, op) in code.iter().enumerate() {
            if let Op::Bra { kind: kind @ (BraKind::BraPos | BraKind::CapturePos(_)), .. } = op {
                let capture = matches!(kind, BraKind::CapturePos(_));
                let (frame, control_head) = framesize(code, pc, &flags);
                let control_head = u32::from(control_head);
                brapos[pc] = [false, true].map(|nonzero| {
                    let nonzero = u32::from(nonzero);
                    match frame {
                        None => u32::from(capture) + 1 + control_head + nonzero,
                        Some(f) => f + 1 + nonzero + control_head + u32::from(!capture),
                    }
                });
            }
        }
        let then_trap = then_traps(code, &flags);
        JitLayout {
            rep,
            optimized,
            recurse,
            recurse_end,
            recurse_inline,
            once_frame,
            skip_arg,
            count,
            early,
            early_slots,
            fast_forward,
            charpos,
            brapos,
            assert_words,
            assert_kept,
            cond_kept,
            loop_copy,
            loop_zero,
            loop_wrapper,
            then_trap,
        }
    }
}

/// The THEN traps (`set_then_offsets()`): every branch of a group with
/// alternatives (not a condition) that holds a `(*THEN)`, directly or in a
/// nested group without alternatives (not in an assertion), starts with a
/// trap of three words and the frame of what the branch changes.
fn then_traps(code: &[Op], flags: &PatternFlags) -> Vec<u32> {
    let mut traps = vec![0; code.len()];
    if !code.iter().any(|op| matches!(op, Op::Then(_))) {
        return traps;
    }
    let mut marked = vec![false; code.len()];
    then_offsets(code, 0, None, &mut marked);
    for start in (0..code.len()).filter(|&pc| marked[pc]) {
        // The branch ends at the next `Alt` or `Ket` of its group.
        let mut end = start;
        let mut depth = 0usize;
        loop {
            match code[end] {
                Op::Bra { .. } => depth += 1,
                Op::Alt { .. } | Op::Ket { .. } if depth == 0 => break,
                Op::Ket { .. } => depth -= 1,
                _ => {}
            }
            end += 1;
        }
        traps[start] = 3 + framesize_of(code, start, end, flags).0.unwrap_or(0);
    }
    traps
}

/// `set_then_offsets()` for the bracket at `bra`: marks the first op of
/// each branch that holds a `(*THEN)`; `current` is the branch of an outer
/// group that a `(*THEN)` here belongs to. Returns the position after the
/// bracket.
fn then_offsets(code: &[Op], bra: usize, current: Option<usize>, marked: &mut [bool]) -> usize {
    let Op::Bra { kind, link, .. } = code[bra] else { return bra + 1 };
    let end = ket_of(code, bra);
    let assert = matches!(
        kind,
        BraKind::Assert
            | BraKind::AssertNot
            | BraKind::AssertBack
            | BraKind::AssertBackNot
            | BraKind::AssertNa
            | BraKind::AssertBackNa
    );
    let mut current = if assert { None } else { current };
    let alternatives = matches!(code[link], Op::Alt { .. }) && kind != BraKind::Cond;
    let skip_reverse = |pc: usize| if matches!(code[pc], Op::Reverse(_) | Op::VReverse { .. }) { pc + 1 } else { pc };
    let mut pc = bra + 1;
    if alternatives {
        pc = skip_reverse(pc);
        current = Some(pc);
    }
    while pc < end {
        match code[pc] {
            Op::Bra { .. } => pc = then_offsets(code, pc, current, marked),
            Op::Alt { .. } if alternatives => {
                pc = skip_reverse(pc + 1);
                current = Some(pc);
            }
            Op::Then(_) => {
                if let Some(c) = current {
                    marked[c] = true;
                }
                pc += 1;
            }
            _ => pc += 1,
        }
    }
    end + 1
}

/// `charpos_enabled` in `compile_iterator_matchingpath()`: a greedy
/// unlimited or `{n,m}` (m - n >= 2) repeat of something other than a
/// literal character (and not `\R`, `\X` or a dot-all `.*`), directly
/// followed by a one-unit character whose other case, if any, differs in
/// one bit.
fn charpos(code: &[Op], pc: usize, options: &JitOptions) -> Option<(u8, u8)> {
    let Op::Rep { lit, min, max, kind: RepKind::Greedy } = &code[pc] else { return None };
    if min == max || (*max != UNLIMITED && max - min < 2) {
        return None;
    }
    if matches!(lit, Lit::Char(_) | Lit::CharI(_) | Lit::AnyNl | Lit::ExtUni)
        || (matches!(lit, Lit::AllAny) && (*max == UNLIMITED || !options.utf))
    {
        return None;
    }
    // `x{1,n}` is `OP_CHAR` and then `OP_UPTO` too.
    let (c, caseless) = match code.get(pc + 1) {
        Some(Op::Item(Lit::Char(c))) => (*c, false),
        Some(Op::Item(Lit::CharI(c))) => (*c, true),
        Some(Op::Rep { lit: Lit::Char(c), min: 1, max, .. }) if *max != UNLIMITED => (*c, false),
        Some(Op::Rep { lit: Lit::CharI(c), min: 1, max, .. }) if *max != UNLIMITED => (*c, true),
        _ => return None,
    };
    if c > 255 || (options.utf && c > 127) {
        return None;
    }
    let other = if !caseless {
        c
    } else if (options.utf || options.ucp) && c > 127 {
        super::unicode::other_case(c)
    } else {
        super::unicode::fcc(c)
    };
    if other == c {
        return Some((c as u8, 0));
    }
    let bit = c ^ other;
    if !bit.is_power_of_two() || bit > 255 {
        return None;
    }
    Some(((c | bit) as u8, bit as u8))
}

/// `EARLY_FAIL_ENHANCE_MAX`.
const EARLY_FAIL_MAX: u32 = 6;

/// `detect_early_fail()`: assigns early-fail slots to the iterators near
/// the start of each branch (descending into plain groups), and marks the
/// brackets that contain one (`PRIVATE_DATA(begin) = 1`: they cannot be
/// counted loops).
struct EarlyFailScan<'a> {
    code: &'a [Op],
    optimized: &'a [bool],
    early: Vec<EarlyFail>,
    marked: Vec<bool>,
    slots: usize,
    fast_forward: Option<usize>,
}

/// How the scan treats a repeat (by the opcodes PCRE2 compiles it to).
enum RepScan {
    Continue,
    Accelerated,
}

impl EarlyFailScan<'_> {
    fn detect(&mut self, begin: usize, depth: u32, start: u32) -> u32 {
        let code = self.code;
        let link = |pc: usize| match code[pc] {
            Op::Bra { link, .. } | Op::Alt { link } => link,
            _ => pc,
        };
        let mut start = start;
        let mut next_alt = link(begin);
        if matches!(code[next_alt], Op::Alt { .. }) && start < 1 {
            start = 1;
        }
        let mut result = 0;
        let mut cc = begin;
        loop {
            let mut count = start;
            cc += 1;
            loop {
                let at = match &code[cc] {
                    Op::Assert(_) | Op::SetSom => {
                        cc += 1;
                        continue;
                    }
                    Op::Item(lit) => {
                        count = count.max(item_weight(lit));
                        cc += 1;
                        continue;
                    }
                    Op::Rep { lit, min, max, kind } => match rep_scan(lit, *min, *max, *kind, &mut count) {
                        RepScan::Continue => {
                            cc += 1;
                            continue;
                        }
                        RepScan::Accelerated => {
                            cc += 1;
                            cc - 1
                        }
                    },
                    Op::Bra { kind: kind @ (BraKind::Bra | BraKind::Capture(_)), empty: false, .. } => {
                        let prev = count;
                        count = count.max(1);
                        if depth >= 4 {
                            break;
                        }
                        if count < 3 && matches!(code[link(cc)], Op::Alt { .. }) {
                            count = 3;
                        }
                        let ket = ket_of(code, cc);
                        let unoptimized = matches!(kind, BraKind::Capture(n) if !self.optimized[*n as usize]);
                        if !matches!(code[ket], Op::Ket { kind: KetKind::Ket, .. }) || unoptimized {
                            break;
                        }
                        let inner = self.detect(cc, depth + 1, prev);
                        count = count.max(inner);
                        if self.marked[cc] {
                            self.marked[begin] = true;
                        }
                        if count < EARLY_FAIL_MAX {
                            cc = ket + 1;
                            continue;
                        }
                        break;
                    }
                    _ => break,
                };
                if count == 0 {
                    self.early[at] = EarlyFail::Skip(self.slots);
                    self.fast_forward = Some(self.slots);
                    self.slots += 1;
                    count = 4;
                } else if count < 3 {
                    self.early[at] = EarlyFail::Fail(self.slots);
                    self.slots += 1;
                    count = 4;
                } else {
                    self.early[at] = EarlyFail::Range(self.slots);
                    self.slots += 2;
                    count += 1;
                }
                // Cannot be part of a repeat.
                self.marked[begin] = true;
                if count >= EARLY_FAIL_MAX {
                    break;
                }
            }
            if matches!(code[cc], Op::Alt { .. } | Op::Ket { .. }) {
                result = result.max(count);
            } else {
                result = EARLY_FAIL_MAX;
            }
            cc = next_alt;
            if !matches!(code[cc], Op::Alt { .. }) {
                break;
            }
            next_alt = link(cc);
        }
        result
    }
}

/// The weight `detect_early_fail()` gives a single item: 3 for `\R` and
/// `\X` (variable length), 1 for the others.
fn item_weight(lit: &Lit) -> u32 {
    if matches!(lit, Lit::AnyNl | Lit::ExtUni) { 3 } else { 1 }
}

/// `detect_early_fail()` on the opcodes of a repeat: a fixed part
/// (`OP_EXACT`, or the item once before `OP_UPTO`), then `OP_STAR`,
/// `OP_PLUS` and their lazy and possessive forms, which can fail early,
/// or `OP_QUERY`/`OP_UPTO`, which only add weight.
fn rep_scan(lit: &Lit, min: u32, max: u32, kind: RepKind, count: &mut u32) -> RepScan {
    let lazy = kind == RepKind::Lazy;
    if let Lit::Class(_) = lit {
        return if max == UNLIMITED && min <= 1 {
            if lazy && *count == 2 {
                *count = 3;
            }
            RepScan::Accelerated
        } else {
            *count = (*count).max(if min == max { 1 } else { 3 });
            RepScan::Continue
        };
    }
    let char_op = matches!(lit, Lit::Char(_) | Lit::CharI(_) | Lit::Not(_) | Lit::NotI(_));
    // The fixed part.
    if min == max || min >= 2 {
        let exact = if char_op { if matches!(lit, Lit::Char(_)) { 1 } else { 3 } } else { item_weight(lit) };
        *count = (*count).max(exact);
        if min == max {
            return RepScan::Continue;
        }
    } else if min == 1 && max != UNLIMITED {
        *count = (*count).max(item_weight(lit));
    }
    // The variable part.
    if max != UNLIMITED {
        *count = (*count).max(3);
        return RepScan::Continue;
    }
    if lazy && *count == 2 {
        *count = 3;
    }
    if matches!(lit, Lit::AnyNl | Lit::ExtUni) {
        *count = (*count).max(3);
        return RepScan::Continue;
    }
    RepScan::Accelerated
}

/// The `Bra` of the `Ket` at `ket`.
fn bra_of(code: &[Op], ket: usize) -> usize {
    match code[ket] {
        Op::Ket { bra, .. } => bra,
        _ => ket,
    }
}

/// Follows the links from a bracket to its `Ket`.
fn ket_of(code: &[Op], pc: usize) -> usize {
    let mut k = pc;
    while let Op::Bra { link, .. } | Op::Alt { link } = code[k] {
        k = link;
    }
    k
}

/// What `set_private_data_ptrs()` decides walking the code: the brackets
/// the JIT runs as a counted loop (`detect_repeat()`: the `Ket` that
/// carries the counter, and where the walk resumes after the copies it
/// replaces) and the repeated regions, where iterators keep their state on
/// the stack instead of a fixed slot.
struct Walk {
    /// By the `Ket` that counts: where the copies end, and whether the
    /// repeat is exact (`OP_EXACT`) rather than bounded (`OP_UPTO`,
    /// `OP_MINUPTO`).
    repeat: std::collections::HashMap<usize, (usize, bool)>,
    in_region: Vec<bool>,
}

impl Walk {
    fn new(code: &[Op], marked: &[bool]) -> Walk {
        let mut repeat = std::collections::HashMap::new();
        let mut in_region = vec![false; code.len()];
        let mut end: Option<usize> = None;
        let mut repeat_check = true;
        let mut pc = 0;
        while pc < code.len() {
            let op = &code[pc];
            if repeat_check
                && matches!(
                    op,
                    Op::Bra {
                        kind: BraKind::Once | BraKind::Bra | BraKind::Capture(_) | BraKind::Cond | BraKind::Root,
                        empty: false,
                        ..
                    }
                )
                && !marked[pc]
                && detect_repeat(code, pc, &mut repeat)
                && end.is_none_or(|e| pc >= e)
            {
                end = Some(ket_of(code, pc) + 1);
            }
            repeat_check = true;
            match op {
                Op::Ket { .. } => {
                    if let Some(&(resume, _)) = repeat.get(&pc) {
                        // The copies run as iterations of this bracket.
                        for r in &mut in_region[pc + 1..resume] {
                            *r = true;
                        }
                        pc = resume;
                        continue;
                    }
                }
                Op::BraZero | Op::BraMinZero | Op::BraPosZero => repeat_check = false,
                Op::Bra { .. } if end.is_none_or(|e| pc >= e) => {
                    let ket = ket_of(code, pc);
                    end = match code[ket] {
                        Op::Ket { kind: KetKind::Ket, .. } => None,
                        _ => Some(ket + 1),
                    };
                }
                _ => {}
            }
            if end.is_some_and(|e| pc < e) {
                in_region[pc] = true;
            }
            pc += 1;
        }
        Walk { repeat, in_region }
    }
}

/// `detect_repeat()`: whether the bracket at `begin` is the first of copies
/// the compiler made for a quantifier (`(?:ab){3}`, `(?:ab){1,3}`), which
/// the JIT runs as a loop; records the `Ket` that counts and where the copies
/// end.
fn detect_repeat(code: &[Op], begin: usize, repeat: &mut std::collections::HashMap<usize, (usize, bool)>) -> bool {
    let ket = ket_of(code, begin);
    if !matches!(code[ket], Op::Ket { kind: KetKind::Ket, .. }) {
        return false;
    }
    if repeat.contains_key(&ket) {
        return true;
    }
    let len = ket + 1 - begin;
    let same =
        |at: usize| at + len <= code.len() && (0..len).all(|i| same_op(&code[begin + i], &code[at + i], begin, at));
    let mut next = ket + 1;
    let mut min = 1;
    while same(next) {
        next += len;
        min += 1;
    }
    if min == 2 {
        return false;
    }
    let mut max_end = next;
    if let Some(ty @ (Op::BraZero | Op::BraMinZero)) = code.get(next) {
        let zero = |op: &Op| std::mem::discriminant(op) == std::mem::discriminant(ty);
        let mut max = 0;
        while next + 2 < code.len()
            && zero(&code[next])
            && matches!(code[next + 1], Op::Bra { kind: BraKind::Bra, .. })
            && same(next + 2)
        {
            next += 2 + len;
            max += 1;
        }
        if next < code.len() && zero(&code[next]) && same(next + 1) && max >= 1 {
            let next_end = next + 1 + len;
            let closed = (0..max).all(|i| matches!(code.get(next_end + i), Some(Op::Ket { kind: KetKind::Ket, .. })));
            if closed {
                repeat.insert(max_end - 1, (next_end + max, false));
                if min == 1 {
                    return true;
                }
                min -= 1;
                max_end -= len;
            }
        }
    }
    if min >= 3 {
        repeat.insert(ket, (max_end, true));
        return true;
    }
    false
}

/// Whether the JIT needs no frame for an item inside a recursed group
/// (`get_framesize()` with `recursive`: characters, classes without a
/// repeat, exact and possessive single-character repeats and the simple
/// assertions; `\A` and `\G` are not among them).
fn simple_item(op: &Op) -> bool {
    match op {
        Op::Item(_) => true,
        Op::Rep { lit, min, max, kind } => {
            !matches!(lit, Lit::Class(_)) && (*kind == RepKind::Possessive || min == max)
        }
        Op::Assert(a) => !matches!(a, Assert::Sod | Assert::Som),
        _ => false,
    }
}

/// `assert_needs_str_ptr_saving()`: whether an assertion has more than
/// `^`, `$` and `\b` in its branches.
fn saves_start(code: &[Op], bra: usize) -> bool {
    for op in &code[bra + 1..] {
        match op {
            Op::Assert(
                Assert::Circ | Assert::CircM | Assert::Dollar | Assert::DollarM | Assert::WordBoundary { .. },
            )
            | Op::Alt { .. } => {}
            Op::Ket { .. } => return false,
            _ => return true,
        }
    }
    true
}

/// `get_framesize()` (not for a recursion) of the bracket at `bra`: the
/// words of the frame that saves what its items change (three per
/// capturing group, two for `\K`, a mark, ...), `None` when it needs none;
/// and whether it needs the control head.
fn framesize(code: &[Op], bra: usize, flags: &PatternFlags) -> (Option<u32>, bool) {
    let (frame, control_head) = framesize_of(code, bra + 1, ket_of(code, bra), flags);
    match code[bra] {
        // A possessive capturing group saves its own offsets anyway: it
        // needs a frame only for what is inside.
        Op::Bra { kind: BraKind::CapturePos(_), .. } => (frame.map(|f| f + 3), control_head),
        _ => (frame, control_head),
    }
}

/// `get_framesize()` for the items from `from` to `to`.
fn framesize_of(code: &[Op], from: usize, to: usize, flags: &PatternFlags) -> (Option<u32>, bool) {
    let mut length = 0;
    let (mut setsom, mut setmark, mut control_head) = (false, false, false);
    for op in &code[from..to] {
        match op {
            Op::SetSom => {
                if !setsom {
                    length += 2;
                    setsom = true;
                }
            }
            Op::Mark(_) | Op::Commit(Some(_)) | Op::Prune(Some(_)) | Op::Then(Some(_)) => {
                if !setmark {
                    length += 2;
                    setmark = true;
                }
                control_head |= flags.control_head;
            }
            Op::Recurse(_) => {
                if flags.has_set_som && !setsom {
                    length += 2;
                    setsom = true;
                }
                if flags.mark && !setmark {
                    length += 2;
                    setmark = true;
                }
            }
            Op::Bra { kind: BraKind::Capture(_) | BraKind::CapturePos(_), .. } => length += 3,
            Op::Then(None) => control_head |= flags.control_head,
            _ => {}
        }
    }
    if length == 0 {
        return (None, control_head);
    }
    (Some(length + 1), control_head)
}

/// What `check_opcode_types()` records about the whole pattern that a
/// recursion has to save.
struct PatternFlags {
    has_set_som: bool,
    mark: bool,
    control_head: bool,
}

impl PatternFlags {
    fn new(code: &[Op]) -> PatternFlags {
        PatternFlags {
            has_set_som: code.iter().any(|op| matches!(op, Op::SetSom)),
            mark: code
                .iter()
                .any(|op| matches!(op, Op::Mark(_) | Op::Commit(Some(_)) | Op::Prune(Some(_)) | Op::Then(Some(_)))),
            control_head: code.iter().any(|op| matches!(op, Op::Then(_) | Op::SkipArg(_))),
        }
    }
}

/// `get_recurse_data_length()` for the items between `from` and `to`: one
/// word (the previous recursion head), the fixed slots of the brackets and
/// iterators inside (each once), the offsets of the capturing groups
/// inside, and the control head, start and mark when verbs can leave the
/// recursion. Also whether `(*ACCEPT)` is inside.
fn recurse_data_length(
    code: &[Op],
    from: usize,
    to: usize,
    walk: &Walk,
    optimized: &[bool],
    flags: &PatternFlags,
) -> (u32, bool) {
    let mut seen = std::collections::HashSet::new();
    let mut length = 1;
    let mut slot = |key: (u8, usize), words: u32, length: &mut u32| {
        if seen.insert(key) {
            *length += words;
        }
    };
    let (mut setsom, mut setmark, mut control_head, mut quit, mut accept) = (false, false, false, false, false);
    let mut pc = from;
    while pc < to {
        match &code[pc] {
            Op::SetSom => setsom = true,
            Op::Recurse(_) => {
                setsom |= flags.has_set_som;
                setmark |= flags.mark;
            }
            Op::Ket { .. } => {
                if let Some(&(resume, _)) = walk.repeat.get(&pc) {
                    slot((0, pc), 1, &mut length);
                    pc = resume;
                    continue;
                }
            }
            Op::Bra { kind, empty, link } => match kind {
                BraKind::Assert
                | BraKind::AssertNot
                | BraKind::AssertBack
                | BraKind::AssertBackNot
                | BraKind::AssertNa
                | BraKind::AssertBackNa
                | BraKind::Once
                | BraKind::ScriptRun
                | BraKind::BraPos => slot((0, pc), 1, &mut length),
                BraKind::Bra if *empty => slot((0, pc), 1, &mut length),
                BraKind::Capture(n) => {
                    let n = *n as usize;
                    slot((1, n), 2, &mut length);
                    if !optimized[n] {
                        slot((2, n), 1, &mut length);
                    }
                }
                BraKind::CapturePos(n) => {
                    let n = *n as usize;
                    slot((1, n), 2, &mut length);
                    slot((2, n), 1, &mut length);
                    slot((0, pc), 1, &mut length);
                }
                BraKind::Cond => {
                    let hidden = matches!(code[*link], Op::Ket { kind: KetKind::RMax | KetKind::RMin, .. });
                    if *empty || hidden {
                        slot((0, pc), 1, &mut length);
                    }
                }
                _ => {}
            },
            Op::Rep { lit, min, max, kind } => {
                if !walk.in_region[pc] {
                    length += private_words(lit, *min, *max, *kind);
                }
            }
            Op::Mark(_) => {
                setmark = true;
                control_head |= flags.control_head;
            }
            Op::Commit(Some(_)) | Op::Prune(Some(_)) | Op::Then(Some(_)) => {
                setmark = true;
                control_head |= flags.control_head;
                quit = true;
            }
            Op::Prune(None) | Op::Skip | Op::Commit(None) | Op::SkipArg(_) => quit = true,
            Op::Then(None) => {
                quit = true;
                control_head = true;
            }
            Op::Accept | Op::AssertAccept => accept = true,
            _ => {}
        }
        pc += 1;
    }
    if control_head {
        length += 1;
    }
    if quit {
        length += u32::from(setsom) + u32::from(setmark);
    }
    (length, accept)
}

/// A greedy `\R` or `\X` repeat other than `?`: the JIT keeps where each
/// iteration ended on its stack, after a start and an end mark.
pub fn stacked_iterator(lit: &Lit, min: u32, max: u32, kind: RepKind) -> bool {
    matches!(lit, Lit::AnyNl | Lit::ExtUni)
        && kind == RepKind::Greedy
        && min != max
        && (max == UNLIMITED || max - min >= 2)
}

/// The fixed slot of an iterator outside repeated groups
/// (`set_private_data_ptrs()`): like its stack words, except that a greedy
/// `\R` or `\X` repeat other than `?` keeps none.
fn private_words(lit: &Lit, min: u32, max: u32, kind: RepKind) -> u32 {
    if matches!(lit, Lit::AnyNl | Lit::ExtUni) && kind == RepKind::Greedy && min != max {
        return u32::from(max != UNLIMITED && max - min == 1);
    }
    u32::from(iterator_words(lit, min, max, kind))
}

/// Two ops equal up to where their links point (relative to `a` and `b`).
fn same_op(x: &Op, y: &Op, a: usize, b: usize) -> bool {
    match (x, y) {
        (Op::Bra { kind: k1, link: l1, empty: e1 }, Op::Bra { kind: k2, link: l2, empty: e2 }) => {
            k1 == k2 && e1 == e2 && l1.wrapping_sub(a) == l2.wrapping_sub(b)
        }
        (Op::Alt { link: l1 }, Op::Alt { link: l2 }) => l1.wrapping_sub(a) == l2.wrapping_sub(b),
        (Op::Ket { bra: b1, kind: k1 }, Op::Ket { bra: b2, kind: k2 }) => {
            k1 == k2 && b1.wrapping_sub(a) == b2.wrapping_sub(b)
        }
        _ => format!("{x:?}") == format!("{y:?}"),
    }
}

/// The stack words a non-possessive iterator keeps when it has no fixed
/// slot (`CASE_ITERATOR_PRIVATE_DATA_*`, `get_class_iterator_size()`), by
/// the opcode PCRE2 compiles the repeat to.
fn iterator_words(lit: &Lit, min: u32, max: u32, kind: RepKind) -> u8 {
    if kind == RepKind::Possessive || min == max {
        return 0;
    }
    let lazy = kind == RepKind::Lazy;
    if matches!(lit, Lit::Class(_)) {
        return match (min, max) {
            (0 | 1, UNLIMITED) => {
                if lazy {
                    1
                } else {
                    2
                }
            }
            (0, 1) => 1,
            (_, UNLIMITED) => {
                if lazy {
                    1
                } else {
                    2
                }
            }
            _ => (max - min).min(2) as u8,
        };
    }
    if max == UNLIMITED {
        if lazy { 1 } else { 2 }
    } else if (min == 0 && max == 1) || (min >= 2 && max - min == 1) {
        1
    } else {
        2
    }
}

struct Gen {
    code: Vec<Op>,
    group_start: Vec<usize>,
    max_lookbehind: u32,
    has_then: bool,
}

pub fn lower(tree: &Tree) -> Program {
    let mut g = Gen {
        code: Vec::new(),
        group_start: vec![usize::MAX; tree.top_bracket as usize + 1],
        max_lookbehind: 0,
        has_then: false,
    };
    g.group_start[0] = 0;
    g.group(BraKind::Root, &tree.branches, KetKind::Ket, None);
    g.code.push(Op::End);
    Program {
        code: g.code,
        top_bracket: tree.top_bracket,
        group_start: g.group_start,
        marks: tree.marks.clone(),
        names: tree.names.clone(),
        // \b, \B and \A look back one character.
        max_lookbehind: if g.max_lookbehind == 0 && tree.looks_back_one { 1 } else { g.max_lookbehind },
        has_then: g.has_then,
        units: tree.units,
        match_empty: tree.match_empty,
        had_accept: tree.had_accept,
        had_pruneorskip: tree.had_pruneorskip,
        backref_map: tree.backref_map,
        top_backref: tree.top_backref,
        has_cr_or_lf: tree.has_cr_or_lf,
        dupcap_used: tree.dupcap_used,
        jit: JitLayout::default(),
    }
}

impl Gen {
    fn emit(&mut self, op: Op) -> usize {
        self.code.push(op);
        self.code.len() - 1
    }

    fn set_link(&mut self, at: usize, target: usize) {
        match &mut self.code[at] {
            Op::Bra { link, .. } | Op::Alt { link } => *link = target,
            _ => unreachable!("not a linked op"),
        }
    }

    /// One group: `Bra`, the branches separated by `Alt`, and the `Ket`. A
    /// lookbehind's branches start with their `Reverse`/`VReverse`; a
    /// condition (for `Cond`) is emitted right after the `Bra`.
    fn group(&mut self, kind: BraKind, branches: &[Vec<Node>], ket: KetKind, reverse: Option<&[Reverse]>) -> usize {
        self.group_with(kind, branches, ket, reverse, false, |_| {})
    }

    fn group_with(
        &mut self,
        kind: BraKind,
        branches: &[Vec<Node>],
        ket: KetKind,
        reverse: Option<&[Reverse]>,
        empty: bool,
        condition: impl FnOnce(&mut Self),
    ) -> usize {
        let bra = self.emit(Op::Bra { kind, link: 0, empty });
        if let BraKind::Capture(n) | BraKind::CapturePos(n) = kind
            && self.group_start[n as usize] == usize::MAX
        {
            self.group_start[n as usize] = bra;
        }
        condition(self);
        let mut last = bra;
        for (i, branch) in branches.iter().enumerate() {
            if i > 0 {
                let alt = self.emit(Op::Alt { link: 0 });
                self.set_link(last, alt);
                last = alt;
            }
            if let Some(reverse) = reverse {
                match reverse.get(i).copied().unwrap_or(Reverse::Fixed(0)) {
                    Reverse::Fixed(0) | Reverse::Variable { max: 0, .. } => {}
                    Reverse::Fixed(n) => {
                        self.max_lookbehind = self.max_lookbehind.max(n);
                        self.emit(Op::Reverse(n));
                    }
                    Reverse::Variable { min, max } => {
                        self.max_lookbehind = self.max_lookbehind.max(max);
                        self.emit(Op::VReverse { min, max });
                    }
                }
            }
            self.seq(branch);
        }
        let k = self.emit(Op::Ket { bra, kind: ket });
        self.set_link(last, k);
        bra
    }

    fn seq(&mut self, nodes: &[Node]) {
        for n in nodes {
            self.node(n);
        }
    }

    fn node(&mut self, n: &Node) {
        match n {
            Node::Lit(l) => {
                self.emit(Op::Item(l.clone()));
            }
            Node::Assert(a) => {
                self.emit(Op::Assert(*a));
            }
            Node::SetSom => {
                self.emit(Op::SetSom);
            }
            Node::Ref { groups, caseless } => {
                self.emit(Op::Ref {
                    groups: groups.clone().into(),
                    caseless: *caseless,
                    min: 1,
                    max: 1,
                    lazy: false,
                    repeated: false,
                });
            }
            Node::Recurse(g) => {
                self.emit(Op::Recurse(*g));
            }
            Node::Group { kind, branches, .. } => self.group_node(kind, branches, KetKind::Ket, false, false),
            Node::Repeat { node, min, max, kind } => self.repeat(node, *min, *max, *kind),
            Node::Accept { close, in_assert } => {
                for &c in close {
                    self.emit(Op::Close(c));
                }
                self.emit(if *in_assert { Op::AssertAccept } else { Op::Accept });
            }
            Node::Fail => {
                self.emit(Op::Fail);
            }
            Node::Mark(id) => {
                self.emit(Op::Mark(*id));
            }
            Node::Commit(m) => {
                self.emit(Op::Commit(*m));
            }
            Node::Prune(m) => {
                self.emit(Op::Prune(*m));
            }
            Node::Skip(None) => {
                self.emit(Op::Skip);
            }
            Node::Skip(Some(m)) => {
                self.emit(Op::SkipArg(*m));
            }
            Node::Then(m) => {
                self.has_then = true;
                self.emit(Op::Then(*m));
            }
        }
    }

    /// One copy of a group with the given closing kind; `pos` selects the
    /// possessive (`BraPos`) form of the opening bracket and `s` the `S`
    /// form.
    fn group_node(&mut self, kind: &GroupKind, branches: &[Vec<Node>], ket: KetKind, pos: bool, s: bool) {
        match kind {
            GroupKind::NonCapture => {
                let k = if pos { BraKind::BraPos } else { BraKind::Bra };
                self.group_with(k, branches, ket, None, s, |_| {});
            }
            GroupKind::Capture(n) => {
                let k = if pos { BraKind::CapturePos(*n) } else { BraKind::Capture(*n) };
                self.group_with(k, branches, ket, None, s, |_| {});
            }
            GroupKind::Atomic => {
                self.group(if pos { BraKind::BraPos } else { BraKind::Once }, branches, ket, None);
            }
            GroupKind::ScriptRun => {
                self.group(BraKind::ScriptRun, branches, ket, None);
            }
            GroupKind::Look { behind, negated, atomic, reverse } => {
                let kind = match (*behind, *negated, *atomic) {
                    (false, false, true) => BraKind::Assert,
                    (false, true, _) => BraKind::AssertNot,
                    (false, false, false) => BraKind::AssertNa,
                    (true, false, true) => BraKind::AssertBack,
                    (true, true, _) => BraKind::AssertBackNot,
                    (true, false, false) => BraKind::AssertBackNa,
                };
                self.group(kind, branches, ket, if *behind { Some(reverse) } else { None });
            }
            GroupKind::Cond(cond) => {
                self.group_with(BraKind::Cond, branches, ket, None, s, |g| g.condition(cond));
            }
            GroupKind::Define => {
                self.group_with(BraKind::Cond, branches, ket, None, false, |g| {
                    g.emit(Op::CondFalse);
                });
            }
        }
    }

    fn condition(&mut self, cond: &Cond) {
        match cond {
            Cond::Groups(gs) => {
                self.emit(Op::CondRef(gs.clone().into()));
            }
            Cond::Recursion(gs) => {
                self.emit(Op::CondRecurse(gs.as_ref().map(|v| v.clone().into())));
            }
            Cond::Value(v) => {
                self.emit(if *v { Op::CondTrue } else { Op::CondFalse });
            }
            Cond::Assert(node) => match node.as_ref() {
                Node::Group { kind: kind @ GroupKind::Look { .. }, branches, .. } => {
                    self.group_node(kind, branches, KetKind::Ket, false, false);
                }
                // `(?!)` as a condition is OP_FAIL: always false.
                _ => {
                    self.emit(Op::CondFalse);
                }
            },
        }
    }

    fn repeat(&mut self, node: &Node, min: u32, max: u32, kind: RepKind) {
        let max = if max == REPEAT_UNLIMITED { UNLIMITED } else { max };
        match node {
            // `\d{1,n}+` is the item and then a repeat of it; PCRE2 cannot
            // make that pair possessive (only a character or an exact count
            // before the repeat is skipped) and wraps it in ONCE instead.
            Node::Lit(l)
                if kind == RepKind::Possessive
                    && min == 1
                    && max != UNLIMITED
                    && max > 1
                    && !matches!(l, Lit::Char(_) | Lit::CharI(_) | Lit::Not(_) | Lit::NotI(_) | Lit::Class(_)) =>
            {
                let bra = self.emit(Op::Bra { kind: BraKind::Once, link: 0, empty: false });
                self.emit(Op::Rep { lit: l.clone(), min, max, kind: RepKind::Greedy });
                let k = self.emit(Op::Ket { bra, kind: KetKind::Ket });
                self.set_link(bra, k);
            }
            Node::Lit(l) => {
                self.emit(Op::Rep { lit: l.clone(), min, max, kind });
            }
            Node::Ref { groups, caseless } => {
                let op = |lazy| Op::Ref {
                    groups: groups.clone().into(),
                    caseless: *caseless,
                    min,
                    max,
                    lazy,
                    repeated: true,
                };
                if kind == RepKind::Possessive {
                    // No possessive back reference opcode: wrapped in ONCE.
                    let bra = self.emit(Op::Bra { kind: BraKind::Once, link: 0, empty: false });
                    self.emit(op(false));
                    let k = self.emit(Op::Ket { bra, kind: KetKind::Ket });
                    self.set_link(bra, k);
                } else {
                    self.emit(op(kind == RepKind::Lazy));
                }
            }
            Node::Recurse(g) => self.repeat_recurse(*g, min, max, kind),
            Node::Group { kind: gk, branches, empty } => self.repeat_group(gk, branches, *empty, min, max, kind),
            _ => self.node(node),
        }
    }

    /// A repeated recursion: replicated for the minimum, then wrapped in a
    /// non-capturing bracket that is repeated like a group.
    fn repeat_recurse(&mut self, group: u32, min: u32, max: u32, kind: RepKind) {
        let possessive = kind == RepKind::Possessive;
        if min == 1 && max == 1 && !possessive {
            self.emit(Op::Recurse(group));
            return;
        }
        let wrap = possessive && if min == max { min > 0 } else { max != UNLIMITED };
        let once = if wrap { Some(self.emit(Op::Bra { kind: BraKind::Once, link: 0, empty: false })) } else { None };
        let (mut min, mut max) = (min, max);
        if min > 0 && (min != 1 || max != UNLIMITED) {
            if min == max {
                for _ in 0..min {
                    self.emit(Op::Recurse(group));
                }
                self.close_once(once);
                return;
            }
            // The original call and `min` copies: the last copy is the one
            // that becomes the optional part below.
            for _ in 0..min {
                self.emit(Op::Recurse(group));
            }
            if max != UNLIMITED {
                max -= min;
            }
            min = 0;
        }
        let body = vec![Node::Recurse(group)];
        let branches = std::slice::from_ref(&body);
        self.repeat_copies(&GroupKind::NonCapture, branches, true, min, max, kind, possessive && max == UNLIMITED);
        self.close_once(once);
    }

    fn close_once(&mut self, once: Option<usize>) {
        if let Some(bra) = once {
            let k = self.emit(Op::Ket { bra, kind: KetKind::Ket });
            self.set_link(bra, k);
        }
    }

    #[allow(clippy::too_many_arguments)]
    fn repeat_group(&mut self, gk: &GroupKind, branches: &[Vec<Node>], empty: bool, min: u32, max: u32, kind: RepKind) {
        let possessive = kind == RepKind::Possessive;
        if min == 1 && max == 1 && !possessive {
            self.group_node(gk, branches, KetKind::Ket, false, false);
            return;
        }
        // An unlimited possessive repeat uses the POS opcodes for its last
        // copy (except a script run, which keeps KETRMAX); the whole item is
        // also wrapped in ONCE unless that was enough (minimum 0 or 1). A
        // `{0}` repeat is just skipped.
        let script_run = matches!(gk, GroupKind::ScriptRun);
        let pos_last = possessive && max == UNLIMITED && !script_run;
        let wrap = possessive && max != 0 && !(pos_last && min < 2);
        let once = if wrap { Some(self.emit(Op::Bra { kind: BraKind::Once, link: 0, empty: false })) } else { None };
        self.repeat_copies(gk, branches, empty, min, max, kind, pos_last);
        self.close_once(once);
    }

    /// The copies of a repeated group (`compile_branch()` REPEAT for
    /// brackets). `pos` makes the looping copy possessive.
    #[allow(clippy::too_many_arguments)]
    fn repeat_copies(
        &mut self,
        gk: &GroupKind,
        branches: &[Vec<Node>],
        empty: bool,
        min: u32,
        max: u32,
        kind: RepKind,
        pos: bool,
    ) {
        let lazy = kind == RepKind::Lazy;
        let zero_op = || if lazy { Op::BraMinZero } else { Op::BraZero };
        let mut max = max;
        let mut nests: Vec<usize> = Vec::new();
        let mut brazero: Option<usize> = None;
        if min == 0 {
            if max == 0 {
                self.emit(Op::SkipZero);
                self.group_node(gk, branches, KetKind::Ket, false, false);
                return;
            }
            if max == 1 || max == UNLIMITED {
                brazero = Some(self.emit(zero_op()));
                if max == 1 {
                    self.group_node(gk, branches, KetKind::Ket, false, false);
                    return;
                }
            } else {
                self.emit(zero_op());
                nests.push(self.emit(Op::Bra { kind: BraKind::Bra, link: 0, empty: false }));
                self.group_node(gk, branches, KetKind::Ket, false, false);
                max -= 1;
            }
        } else {
            let plain = if max == UNLIMITED { min - 1 } else { min };
            for _ in 0..plain {
                self.group_node(gk, branches, KetKind::Ket, false, false);
            }
            if max != UNLIMITED {
                max -= min;
            }
        }
        if max != UNLIMITED {
            for i in (1..=max).rev() {
                self.emit(zero_op());
                if i != 1 {
                    nests.push(self.emit(Op::Bra { kind: BraKind::Bra, link: 0, empty: false }));
                }
                self.group_node(gk, branches, KetKind::Ket, false, false);
            }
            while let Some(bra) = nests.pop() {
                let k = self.emit(Op::Ket { bra, kind: KetKind::Ket });
                self.set_link(bra, k);
            }
            return;
        }
        // The looping copy.
        let ket = if pos {
            KetKind::RPos
        } else if lazy {
            KetKind::RMin
        } else {
            KetKind::RMax
        };
        // `OP_S...` forms for a group that could match an empty string, and
        // for a condition with one branch.
        let s = match gk {
            GroupKind::NonCapture | GroupKind::Capture(_) => empty,
            GroupKind::Atomic => pos && empty,
            GroupKind::Cond(_) => empty || branches.len() == 1,
            _ => false,
        };
        match gk {
            GroupKind::Atomic if pos => self.group_node(&GroupKind::NonCapture, branches, ket, true, s),
            GroupKind::Cond(_) if pos => {
                let bra = self.emit(Op::Bra { kind: BraKind::BraPos, link: 0, empty: s });
                self.group_node(gk, branches, KetKind::Ket, false, s);
                let k = self.emit(Op::Ket { bra, kind: KetKind::RPos });
                self.set_link(bra, k);
            }
            _ => self.group_node(gk, branches, ket, pos, s),
        }
        if pos && let Some(z) = brazero {
            self.code[z] = Op::BraPosZero;
        }
    }
}
