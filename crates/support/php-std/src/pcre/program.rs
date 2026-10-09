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
/// group saves (`recurse`, by group).
#[derive(Debug, Clone, Default)]
pub struct JitLayout {
    pub rep: Vec<u8>,
    pub optimized: Vec<bool>,
    pub recurse: Vec<u32>,
    /// By position of an atomic group: the words of the frame it saves
    /// (two per capturing group inside, `get_framesize()`).
    pub once_frame: Vec<u32>,
}

impl JitLayout {
    /// `set_private_data_ptrs()` and the parts of `check_opcode_types()`
    /// that decide stack usage.
    pub fn new(code: &[Op], top_bracket: u32, group_start: &[usize]) -> JitLayout {
        let ket_of = |pc: usize| {
            let mut k = pc;
            while let Op::Bra { link, .. } | Op::Alt { link } = code[k] {
                k = link;
            }
            k
        };
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
        // Iterators inside a repeated group keep their state on the stack.
        let mut rep = vec![0u8; code.len()];
        let mut end: Option<usize> = None;
        for (pc, op) in code.iter().enumerate() {
            match op {
                Op::Bra { .. } => {
                    if end.is_none_or(|e| pc >= e) {
                        let ket = ket_of(pc);
                        end = match code[ket] {
                            Op::Ket { kind: KetKind::Ket, .. } if !replicated(code, pc, ket) => None,
                            _ => Some(ket + 1),
                        };
                    }
                }
                Op::Rep { lit, min, max, kind } if end.is_some_and(|e| pc < e) => {
                    rep[pc] = iterator_words(lit, *min, *max, *kind);
                }
                _ => {}
            }
        }
        // A recursion saves the group's captures (beyond its own) and two
        // more words.
        let mut recurse = vec![0u32; top_bracket as usize + 1];
        for (g, &start) in group_start.iter().enumerate() {
            if start == usize::MAX {
                continue;
            }
            let ket = ket_of(start);
            let inner = code[start + 1..ket]
                .iter()
                .filter(|op| matches!(op, Op::Bra { kind: BraKind::Capture(_) | BraKind::CapturePos(_), .. }))
                .count() as u32;
            recurse[g] = 2 + 2 * inner;
        }
        let mut once_frame = vec![0u32; code.len()];
        for (pc, op) in code.iter().enumerate() {
            if let Op::Bra { kind: BraKind::Once, .. } = op {
                let ket = ket_of(pc);
                once_frame[pc] = 2 * code[pc + 1..ket]
                    .iter()
                    .filter(|op| matches!(op, Op::Bra { kind: BraKind::Capture(_) | BraKind::CapturePos(_), .. }))
                    .count() as u32;
            }
        }
        JitLayout { rep, optimized, recurse, once_frame }
    }
}

/// Whether the group at `bra` is the first of copies the compiler made for
/// a quantifier (`(?:ab){3}`, `(?:ab){1,3}`), which the JIT runs as a loop
/// (`detect_repeat()`).
fn replicated(code: &[Op], bra: usize, ket: usize) -> bool {
    if !matches!(
        code[bra],
        Op::Bra { kind: BraKind::Bra | BraKind::Capture(_) | BraKind::Cond | BraKind::Once, empty: false, .. }
    ) {
        return false;
    }
    let len = ket + 1 - bra;
    let same = |at: usize| at + len <= code.len() && (0..len).all(|i| same_op(&code[bra + i], &code[at + i], bra, at));
    let mut next = ket + 1;
    let mut copies = 1;
    while same(next) {
        next += len;
        copies += 1;
    }
    match copies {
        2 => false,
        1 => {
            // Optional copies, the outer ones wrapped in a group.
            matches!(code.get(next), Some(Op::BraZero | Op::BraMinZero))
                && matches!(code.get(next + 1), Some(Op::Bra { kind: BraKind::Bra, .. }))
                && same(next + 2)
        }
        _ => true,
    }
}

/// Two ops equal up to where their links point (relative to `a` and `b`).
fn same_op(x: &Op, y: &Op, a: usize, b: usize) -> bool {
    match (x, y) {
        (Op::Bra { kind: k1, link: l1, empty: e1 }, Op::Bra { kind: k2, link: l2, empty: e2 }) => {
            k1 == k2 && e1 == e2 && l1 - a == l2 - b
        }
        (Op::Alt { link: l1 }, Op::Alt { link: l2 }) => l1 - a == l2 - b,
        (Op::Ket { bra: b1, kind: k1 }, Op::Ket { bra: b2, kind: k2 }) => k1 == k2 && b1 - a == b2 - b,
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
