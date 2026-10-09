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
    /// (two per capturing group inside, `get_framesize()`).
    pub once_frame: Vec<u32>,
    /// By position: how the JIT counts match steps at a bracket that the
    /// compiler copied for a quantifier and the JIT runs as a counted loop.
    pub count: Vec<LoopCount>,
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
    pub fn new(code: &[Op], top_bracket: u32, group_start: &[usize]) -> JitLayout {
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
        let walk = Walk::new(code);
        // Iterators inside a repeated group keep their state on the stack.
        let mut rep = vec![0u8; code.len()];
        for (pc, op) in code.iter().enumerate() {
            if let Op::Rep { lit, min, max, kind } = op
                && walk.in_region[pc]
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
        let mut once_frame = vec![0u32; code.len()];
        for (pc, op) in code.iter().enumerate() {
            if let Op::Bra { kind: BraKind::Once, .. } = op {
                let ket = ket_of(code, pc);
                once_frame[pc] = 2 * code[pc + 1..ket]
                    .iter()
                    .filter(|op| matches!(op, Op::Bra { kind: BraKind::Capture(_) | BraKind::CapturePos(_), .. }))
                    .count() as u32;
            }
        }
        let mut count = vec![LoopCount::Normal; code.len()];
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
            let mut pc = ket + 1;
            for level in 0..levels {
                if level > 0 {
                    count[pc] = LoopCount::Silent;
                }
                count[pc + 1 + len] = LoopCount::Silent;
                pc += 2 + len;
            }
            count[pc] = LoopCount::Silent;
            count[pc + len] = LoopCount::Silent;
            count[pc + 1 + len..resume - 1].fill(LoopCount::Silent);
        }
        JitLayout { rep, optimized, recurse, recurse_end, recurse_inline, once_frame, count }
    }
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
    fn new(code: &[Op]) -> Walk {
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
