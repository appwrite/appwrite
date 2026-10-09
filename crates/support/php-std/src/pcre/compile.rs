//! The second pass of `pcre2_compile()`: the parsed pattern becomes a tree
//! of matching items, following `compile_branch()`/`compile_regex()` in
//! PCRE2 10.44 (option scoping, class construction, the single-character
//! class optimizations, caseless character sets, errors raised at this
//! stage).

use super::lookbehind::LOOKBEHIND_MAX;
use super::parse::{opt::*, *};
use super::unicode::{
    self, CBIT_CNTRL, CBIT_DIGIT, CBIT_GRAPH, CBIT_LOWER, CBIT_PRINT, CBIT_PUNCT, CBIT_SPACE, CBIT_UPPER, CBIT_WORD,
    CBIT_XDIGIT, CBITS,
};

/// A single-character item: matches exactly one character (or `\R`/`\X`
/// sequences) and can be repeated without groups.
#[derive(Debug, Clone)]
pub enum Lit {
    Char(u32),
    /// Caseless character without a multi-case set.
    CharI(u32),
    Not(u32),
    NotI(u32),
    Prop {
        neg: bool,
        ptype: u32,
        pdata: u32,
    },
    Any,
    AllAny,
    AnyByte,
    Class(Box<Class>),
    Digit(bool),
    Space(bool),
    Word(bool),
    HSpace(bool),
    VSpace(bool),
    AnyNl,
    ExtUni,
}

/// A compiled character class (`OP_CLASS`, `OP_NCLASS` or `OP_XCLASS`).
#[derive(Debug, Clone)]
pub enum Class {
    /// Characters > 255 never match.
    Map([u8; 32]),
    /// Characters > 255 always match.
    NMap([u8; 32]),
    X {
        negated: bool,
        has_prop: bool,
        map: Option<[u8; 32]>,
        items: Vec<XItem>,
    },
}

#[derive(Debug, Clone)]
pub enum XItem {
    Range(u32, u32),
    Prop { positive: bool, ptype: u32, pdata: u32 },
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Assert {
    Circ,
    CircM,
    Dollar,
    DollarM,
    Sod,
    Eod,
    Eodn,
    Som,
    WordBoundary { ucp: bool, negated: bool },
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum RepKind {
    Greedy,
    Lazy,
    Possessive,
}

/// The length of one lookbehind branch.
#[derive(Debug, Clone, Copy)]
pub enum Reverse {
    Fixed(u32),
    Variable { min: u32, max: u32 },
}

#[derive(Debug, Clone)]
pub enum Cond {
    /// Any of these groups is set.
    Groups(Vec<u32>),
    /// In a recursion of any of these groups (`None`: any recursion).
    Recursion(Option<Vec<u32>>),
    Value(bool),
    Assert(Box<Node>),
}

#[derive(Debug, Clone)]
pub enum GroupKind {
    NonCapture,
    Capture(u32),
    Atomic,
    ScriptRun,
    Look {
        behind: bool,
        negated: bool,
        atomic: bool,
        reverse: Vec<Reverse>,
    },
    Cond(Cond),
    /// A `(?(DEFINE)...)` group: never run in line.
    Define,
}

#[derive(Debug, Clone)]
pub enum Node {
    Lit(Lit),
    Assert(Assert),
    SetSom,
    Ref {
        groups: Vec<u32>,
        caseless: bool,
    },
    Recurse(u32),
    /// `empty`: the group could match an empty string (`compile_regex()`
    /// returned a negative value), which selects the `OP_S...` forms.
    Group {
        kind: GroupKind,
        branches: Vec<Vec<Node>>,
        empty: bool,
    },
    Repeat {
        node: Box<Node>,
        min: u32,
        max: u32,
        kind: RepKind,
    },
    /// `(*ACCEPT)`: close these captures (innermost first), then accept.
    Accept {
        close: Vec<u32>,
        in_assert: bool,
    },
    Fail,
    Mark(u32),
    Commit(Option<u32>),
    Prune(Option<u32>),
    Skip(Option<u32>),
    Then(Option<u32>),
}

/// "No code unit found yet" / "no fixed code unit" (`REQ_UNSET`,
/// `REQ_NONE`); smaller values are a code unit's flags.
pub const REQ_UNSET: u32 = 0xffff_ffff;
pub const REQ_NONE: u32 = 0xffff_fffe;
pub const REQ_CASELESS: u32 = 0x0000_0001;
pub const REQ_VARY: u32 = 0x0000_0002;

/// The first and last required code units of a branch or group, as
/// `compile_branch()` and `compile_regex()` track them.
#[derive(Debug, Clone, Copy)]
pub struct Units {
    pub firstcu: u32,
    pub firstcuflags: u32,
    pub reqcu: u32,
    pub reqcuflags: u32,
}

impl Units {
    const UNSET: Units = Units { firstcu: 0, firstcuflags: REQ_UNSET, reqcu: 0, reqcuflags: REQ_UNSET };
}

/// The compiled pattern tree and what `pcre2_pattern_info()` would report.
#[derive(Debug, Clone)]
pub struct Tree {
    pub branches: Vec<Vec<Node>>,
    pub top_bracket: u32,
    /// `(name, number)` pairs of named groups.
    pub names: Vec<(Vec<u8>, u32)>,
    /// Verb arguments (`(*MARK:name)` ...), indexed by the ids in nodes.
    pub marks: Vec<Vec<u8>>,
    /// First and required code units of the whole pattern.
    pub units: Units,
    /// The pattern can match an empty string (`PCRE2_MATCH_EMPTY`).
    pub match_empty: bool,
    pub had_accept: bool,
    pub had_pruneorskip: bool,
    /// Groups referenced by back references (bit n for group n < 32, bit 0
    /// for the others).
    pub backref_map: u32,
    pub top_backref: u32,
    /// A literal `\r` or `\n` (`PCRE2_HASCRORLF`).
    pub has_cr_or_lf: bool,
    /// `\b`, `\B` or `\A` appear, which look back one character.
    pub looks_back_one: bool,
    /// `(?|` was used (`PCRE2_DUPCAPUSED`).
    pub dupcap_used: bool,
}

struct Builder<'a> {
    meta: &'a [u32],
    pat: &'a [u8],
    parsed: &'a Parsed,
    p: usize,
    utf: bool,
    ucp: bool,
    assert_depth: u32,
    open_caps: Vec<(u32, u32)>,
    marks: Vec<Vec<u8>>,
    error_offset: usize,
    /// The first error that only the real compile pass detects (too many
    /// branches in a condition or DEFINE group): reported only when the
    /// pre-compile pass finds no other error.
    deferred: Option<(i32, usize)>,
    // compile_block fields used by the start-of-match optimizations.
    req_varyopt: u32,
    had_accept: bool,
    had_pruneorskip: bool,
    backref_map: u32,
    top_backref: u32,
    has_cr_or_lf: bool,
    looks_back_one: bool,
}

/// The result of `compile_regex()`.
struct Compiled {
    branches: Vec<Vec<Node>>,
    units: Units,
    /// Some branch could match an empty string.
    empty: bool,
}

/// The result of `compile_branch()`.
struct BranchResult {
    units: Units,
    okreturn: i32,
}

/// What `compile_class()` found: a class that is compiled as a character.
enum ClassShape {
    /// `[c]`: compiled as the literal character.
    Char(u32),
    /// `[cC]`: the caseless character.
    CaselessPair(u32),
    /// `[^c]`.
    NegSingle(Node),
    General(Node),
}

pub fn build(meta: &[u32], pat: &[u8], parsed: &Parsed, xoptions: u32) -> Result<Tree, CompileError> {
    let mut b = Builder {
        meta,
        pat,
        parsed,
        p: 0,
        utf: parsed.options & UTF != 0,
        ucp: parsed.options & UCP != 0,
        assert_depth: 0,
        open_caps: Vec::new(),
        marks: Vec::new(),
        error_offset: pat.len(),
        deferred: None,
        req_varyopt: 0,
        had_accept: false,
        had_pruneorskip: false,
        backref_map: 0,
        top_backref: 0,
        has_cr_or_lf: false,
        looks_back_one: false,
    };
    let compiled = match b.compile_regex(parsed.options, xoptions, false) {
        Ok(c) => c,
        Err(code) => return Err(CompileError { code, offset: b.error_offset }),
    };
    if let Some((code, offset)) = b.deferred {
        return Err(CompileError { code, offset });
    }
    let mut units = compiled.units;
    if b.had_accept {
        units.reqcu = 0;
        units.reqcuflags = REQ_NONE;
    }
    let names = parsed.names.iter().map(|ng| (pat[ng.name.clone()].to_vec(), ng.number)).collect();
    Ok(Tree {
        branches: compiled.branches,
        top_bracket: parsed.bracount,
        names,
        marks: b.marks,
        units,
        match_empty: compiled.empty,
        had_accept: b.had_accept,
        had_pruneorskip: b.had_pruneorskip,
        backref_map: b.backref_map,
        top_backref: b.top_backref,
        has_cr_or_lf: b.has_cr_or_lf,
        looks_back_one: b.looks_back_one,
        dupcap_used: parsed.dupcap_used,
    })
}

type CResult<T> = Result<T, i32>;

/// The tracking state of `compile_branch()`.
struct BranchState {
    u: Units,
    zero: Units,
    req_caseopt: u32,
    groupsetfirstcu: bool,
}

impl<'a> Builder<'a> {
    /// `compile_regex()`: `p` is at the first item of the first branch; on
    /// return it is at the closing `META_KET` (or `META_END`). With
    /// `cond_assert`, the first item of the first branch is the assertion
    /// of a conditional group.
    fn compile_regex(&mut self, options: u32, xoptions: u32, cond_assert: bool) -> CResult<Compiled> {
        let mut lengths = Vec::new();
        self.compile_regex_collecting(options, xoptions, &mut lengths, cond_assert)
    }

    fn mark(&mut self, start: usize, len: usize) -> u32 {
        let name: Vec<u8> = if self.utf {
            let mut v = Vec::new();
            for &c in &self.meta[start..start + len] {
                unicode::push_utf8(&mut v, c);
            }
            v
        } else {
            self.meta[start..start + len].iter().map(|&c| c as u8).collect()
        };
        let id = self.marks.len() as u32;
        self.marks.push(name);
        id
    }

    fn name_lookup(&self, offset: usize, length: usize) -> Option<(u32, bool)> {
        let name = &self.pat[offset..offset + length];
        self.parsed.names.iter().find(|ng| &self.pat[ng.name.clone()] == name).map(|ng| (ng.number, ng.isdup))
    }

    /// Every group number carrying a (duplicated) name, in name-table order.
    fn dup_numbers(&self, offset: usize, length: usize) -> Vec<u32> {
        let name = &self.pat[offset..offset + length];
        let mut v: Vec<u32> =
            self.parsed.names.iter().filter(|ng| &self.pat[ng.name.clone()] == name).map(|ng| ng.number).collect();
        v.sort_unstable();
        v
    }

    fn note_backref(&mut self, number: u32) {
        self.backref_map |= if number < 32 { 1 << number } else { 1 };
        if number > self.top_backref {
            self.top_backref = number;
        }
    }

    /// A literal character, as `NORMAL_CHAR` compiles it.
    fn char_node(&self, c: u32, options: u32, xoptions: u32) -> Node {
        if options & CASELESS != 0 {
            if self.utf || self.ucp {
                let set = unicode::caseset(c);
                if set != 0 && (xoptions & X_CASELESS_RESTRICT == 0 || super::ucd::CASELESS_SETS[set as usize] > 127) {
                    return Node::Lit(Lit::Prop { neg: false, ptype: PT_CLIST, pdata: set });
                }
            }
            Node::Lit(Lit::CharI(c))
        } else {
            Node::Lit(Lit::Char(c))
        }
    }

    /// `NORMAL_CHAR`/`CLASS_CASELESS_CHAR`: the node for a literal character
    /// and its effect on the first and required code units.
    fn literal(&mut self, c: u32, options: u32, xoptions: u32, st: &mut BranchState) -> Node {
        let node = self.char_node(c, options, xoptions);
        if let Node::Lit(Lit::Prop { .. }) = node {
            if st.u.firstcuflags == REQ_UNSET {
                st.u.firstcuflags = REQ_NONE;
                st.zero.firstcuflags = REQ_NONE;
            }
            return node;
        }
        self.char_units(c, st);
        node
    }

    /// The code-unit bookkeeping of `CLASS_CASELESS_CHAR` for character `c`
    /// (with `st.req_caseopt` already set for its caselessness).
    fn char_units(&mut self, c: u32, st: &mut BranchState) {
        let mut buf = Vec::with_capacity(4);
        if self.utf {
            unicode::push_utf8(&mut buf, c);
        } else {
            buf.push(c as u8);
        }
        let mclength = buf.len();
        let first = u32::from(buf[0]);
        let last = u32::from(buf[mclength - 1]);
        if first == 0x0d || first == 0x0a {
            self.has_cr_or_lf = true;
        }
        if st.u.firstcuflags == REQ_UNSET {
            st.zero.firstcuflags = REQ_NONE;
            st.zero.reqcu = st.u.reqcu;
            st.zero.reqcuflags = st.u.reqcuflags;
            if mclength == 1 || st.req_caseopt == 0 {
                st.u.firstcu = first;
                st.u.firstcuflags = st.req_caseopt;
                if mclength != 1 {
                    st.u.reqcu = last;
                    st.u.reqcuflags = self.req_varyopt;
                }
            } else {
                st.u.firstcuflags = REQ_NONE;
                st.u.reqcuflags = REQ_NONE;
            }
        } else {
            st.zero.firstcu = st.u.firstcu;
            st.zero.firstcuflags = st.u.firstcuflags;
            st.zero.reqcu = st.u.reqcu;
            st.zero.reqcuflags = st.u.reqcuflags;
            if mclength == 1 || st.req_caseopt == 0 {
                st.u.reqcu = last;
                st.u.reqcuflags = st.req_caseopt | self.req_varyopt;
            }
        }
    }

    /// `if (firstcuflags == REQ_UNSET) firstcuflags = REQ_NONE;` followed by
    /// saving all four values for a zero repeat.
    fn no_first_save_all(st: &mut BranchState) {
        if st.u.firstcuflags == REQ_UNSET {
            st.u.firstcuflags = REQ_NONE;
        }
        st.zero = st.u;
    }

    /// The first/required code unit handling at the end of a group.
    fn group_units(st: &mut BranchState, assertion: Option<bool>, sub: Units, tempreqvary: u32) {
        st.zero = st.u;
        st.groupsetfirstcu = false;
        let mut sub = sub;
        match assertion {
            None => {
                if st.u.firstcuflags == REQ_UNSET && sub.firstcuflags != REQ_UNSET {
                    if sub.firstcuflags < REQ_NONE {
                        st.u.firstcu = sub.firstcu;
                        st.u.firstcuflags = sub.firstcuflags;
                        st.groupsetfirstcu = true;
                    } else {
                        st.u.firstcuflags = REQ_NONE;
                    }
                    st.zero.firstcuflags = REQ_NONE;
                } else if sub.firstcuflags < REQ_NONE && sub.reqcuflags >= REQ_NONE {
                    sub.reqcu = sub.firstcu;
                    sub.reqcuflags = sub.firstcuflags | tempreqvary;
                }
                if sub.reqcuflags < REQ_NONE {
                    st.u.reqcu = sub.reqcu;
                    st.u.reqcuflags = sub.reqcuflags;
                }
            }
            // A positive forward assertion (atomic or not).
            Some(true) => {
                if sub.reqcuflags < REQ_NONE && sub.firstcuflags < REQ_NONE {
                    st.u.reqcu = sub.reqcu;
                    st.u.reqcuflags = sub.reqcuflags;
                }
            }
            Some(false) => {}
        }
    }

    fn compile_branch(
        &mut self,
        optionsptr: &mut u32,
        xoptionsptr: &mut u32,
        out: &mut Vec<Node>,
        cond_assert: bool,
    ) -> CResult<BranchResult> {
        let mut options = *optionsptr;
        let mut xoptions = *xoptionsptr;
        let greedy_default = |options: u32| if options & UNGREEDY != 0 { RepKind::Lazy } else { RepKind::Greedy };
        let mut st = BranchState {
            u: Units::UNSET,
            zero: Units::UNSET,
            req_caseopt: if options & CASELESS != 0 { REQ_CASELESS } else { 0 },
            groupsetfirstcu: false,
        };
        st.u.firstcu = 0;
        st.u.reqcu = 0;
        let mut okreturn = -1;
        let mut matched_char = false;
        let mut had_accept = false;
        let mut first_item = true;
        // `offset` in compile_branch(): the pattern offset most recently read
        // in this branch, used for the too-many-branches errors.
        let mut offset_var: usize = 0;
        loop {
            let item = self.meta[self.p];
            let meta = meta_code(item);
            let arg = meta_data(item);
            let is_quantifier = item >= META_END && (META_ASTERISK..=META_MINMAX_QUERY).contains(&meta);
            if !is_quantifier && matched_char && !had_accept {
                okreturn = 1;
            }
            let previous_matched_char = matched_char;
            matched_char = false;
            if cond_assert
                && first_item
                && !(item >= META_END && matches!(meta, META_CALLOUT_NUMBER | META_CALLOUT_STRING))
            {
                first_item = false;
                if item >= META_END
                    && matches!(
                        meta,
                        META_LOOKAHEAD
                            | META_LOOKAHEADNOT
                            | META_LOOKAHEAD_NA
                            | META_LOOKBEHIND
                            | META_LOOKBEHINDNOT
                            | META_LOOKBEHIND_NA
                    )
                {
                    let node = self.compile_lookaround(item, options, xoptions, &mut st)?;
                    out.push(node);
                    continue;
                }
                return Err(28);
            }
            if !(item >= META_END && matches!(meta, META_CALLOUT_NUMBER | META_CALLOUT_STRING)) {
                first_item = false;
            }
            if item < META_END {
                matched_char = true;
                let node = self.literal(item, options, xoptions, &mut st);
                out.push(node);
                self.p += 1;
                continue;
            }
            match meta {
                META_END | META_ALT | META_KET => {
                    *optionsptr = options;
                    *xoptionsptr = xoptions;
                    return Ok(BranchResult { units: st.u, okreturn });
                }
                META_CIRCUMFLEX => {
                    if options & MULTILINE != 0 {
                        if st.u.firstcuflags == REQ_UNSET {
                            st.u.firstcuflags = REQ_NONE;
                            st.zero.firstcuflags = REQ_NONE;
                        }
                        out.push(Node::Assert(Assert::CircM));
                    } else {
                        out.push(Node::Assert(Assert::Circ));
                    }
                }
                META_DOLLAR => {
                    out.push(Node::Assert(if options & MULTILINE != 0 { Assert::DollarM } else { Assert::Dollar }));
                }
                META_DOT => {
                    matched_char = true;
                    Self::no_first_save_all(&mut st);
                    out.push(Node::Lit(if options & DOTALL != 0 { Lit::AllAny } else { Lit::Any }));
                }
                META_CLASS_EMPTY | META_CLASS_EMPTY_NOT => {
                    matched_char = true;
                    if st.u.firstcuflags == REQ_UNSET {
                        st.u.firstcuflags = REQ_NONE;
                    }
                    st.zero.firstcu = st.u.firstcu;
                    st.zero.firstcuflags = st.u.firstcuflags;
                    out.push(if meta == META_CLASS_EMPTY { Node::Fail } else { Node::Lit(Lit::AllAny) });
                }
                META_CLASS | META_CLASS_NOT => {
                    matched_char = true;
                    // compile_class leaves p at META_CLASS_END
                    match self.compile_class(meta == META_CLASS_NOT, options, xoptions) {
                        ClassShape::Char(c) => {
                            let node = self.literal(c, options, xoptions, &mut st);
                            out.push(node);
                        }
                        ClassShape::CaselessPair(c) => {
                            let saved = st.req_caseopt;
                            st.req_caseopt = REQ_CASELESS;
                            self.char_units(c, &mut st);
                            if options & CASELESS == 0 {
                                st.req_caseopt = 0;
                            } else {
                                st.req_caseopt = saved;
                            }
                            out.push(Node::Lit(Lit::CharI(c)));
                        }
                        ClassShape::NegSingle(node) => {
                            st.zero.reqcu = st.u.reqcu;
                            st.zero.reqcuflags = st.u.reqcuflags;
                            if st.u.firstcuflags == REQ_UNSET {
                                st.u.firstcuflags = REQ_NONE;
                            }
                            st.zero.firstcu = st.u.firstcu;
                            st.zero.firstcuflags = st.u.firstcuflags;
                            out.push(node);
                        }
                        ClassShape::General(node) => {
                            Self::no_first_save_all(&mut st);
                            out.push(node);
                        }
                    }
                }
                META_ACCEPT => {
                    self.had_accept = true;
                    had_accept = true;
                    let close = self
                        .open_caps
                        .iter()
                        .rev()
                        .take_while(|&&(_, depth)| depth >= self.assert_depth)
                        .map(|&(n, _)| n)
                        .collect();
                    if st.u.firstcuflags == REQ_UNSET {
                        st.u.firstcuflags = REQ_NONE;
                    }
                    out.push(Node::Accept { close, in_assert: self.assert_depth > 0 });
                }
                META_FAIL => out.push(Node::Fail),
                META_COMMIT => out.push(Node::Commit(None)),
                META_PRUNE => {
                    self.had_pruneorskip = true;
                    out.push(Node::Prune(None));
                }
                META_SKIP => {
                    self.had_pruneorskip = true;
                    out.push(Node::Skip(None));
                }
                META_THEN => out.push(Node::Then(None)),
                META_MARK | META_COMMIT_ARG | META_PRUNE_ARG | META_SKIP_ARG | META_THEN_ARG => {
                    if meta == META_PRUNE_ARG || meta == META_SKIP_ARG {
                        self.had_pruneorskip = true;
                    }
                    let len = self.meta[self.p + 1] as usize;
                    let id = self.mark(self.p + 2, len);
                    self.p += 1 + len;
                    out.push(match meta {
                        META_MARK => Node::Mark(id),
                        META_COMMIT_ARG => Node::Commit(Some(id)),
                        META_PRUNE_ARG => Node::Prune(Some(id)),
                        META_SKIP_ARG => Node::Skip(Some(id)),
                        _ => Node::Then(Some(id)),
                    });
                }
                META_OPTIONS => {
                    options = self.meta[self.p + 1];
                    xoptions = self.meta[self.p + 2];
                    *optionsptr = options;
                    *xoptionsptr = xoptions;
                    st.req_caseopt = if options & CASELESS != 0 { REQ_CASELESS } else { 0 };
                    self.p += 2;
                }
                META_COND_RNUMBER | META_COND_NAME | META_COND_RNAME => {
                    let length = self.meta[self.p + 1] as usize;
                    let offset = self.meta[self.p + 2] as usize;
                    offset_var = offset;
                    self.p += 3;
                    let cond = match self.name_lookup(offset, length) {
                        Some((number, false)) => {
                            if meta == META_COND_RNAME {
                                Cond::Recursion(Some(vec![number]))
                            } else {
                                if number > self.top_backref {
                                    self.top_backref = number;
                                }
                                Cond::Groups(vec![number])
                            }
                        }
                        Some((_, true)) => {
                            let numbers = self.dup_numbers(offset, length);
                            for &n in &numbers {
                                self.note_backref(n);
                            }
                            if meta == META_COND_RNAME { Cond::Recursion(Some(numbers)) } else { Cond::Groups(numbers) }
                        }
                        None => {
                            let mut groupnumber: u32 = 0;
                            if meta == META_COND_RNUMBER {
                                for i in 1..length {
                                    groupnumber = groupnumber * 10 + u32::from(self.pat[offset + i] - b'0');
                                    if groupnumber > 65535 {
                                        self.error_offset = offset + i;
                                        return Err(61);
                                    }
                                }
                            }
                            if meta != META_COND_RNUMBER || groupnumber > self.parsed.bracount {
                                self.error_offset = offset;
                                return Err(15);
                            }
                            Cond::Recursion(if groupnumber == 0 { None } else { Some(vec![groupnumber]) })
                        }
                    };
                    let node = self.compile_cond(cond, options, xoptions, offset, &mut st, &mut matched_char, false)?;
                    out.push(node);
                }
                META_COND_DEFINE => {
                    let offset = self.meta[self.p + 1] as usize;
                    offset_var = offset;
                    self.p += 2;
                    let compiled = self.compile_regex(options, xoptions, false)?;
                    if compiled.branches.len() > 1 && self.deferred.is_none() {
                        self.deferred = Some((54, offset));
                    }
                    out.push(Node::Group {
                        kind: GroupKind::Define,
                        branches: compiled.branches,
                        empty: compiled.empty,
                    });
                }
                META_COND_NUMBER => {
                    let offset = self.meta[self.p + 1] as usize;
                    let groupnumber = self.meta[self.p + 2];
                    self.p += 3;
                    if groupnumber > self.parsed.bracount {
                        self.error_offset = offset;
                        return Err(15);
                    }
                    if groupnumber > self.top_backref {
                        self.top_backref = groupnumber;
                    }
                    offset_var = offset - 2;
                    let node = self.compile_cond(
                        Cond::Groups(vec![groupnumber]),
                        options,
                        xoptions,
                        offset_var,
                        &mut st,
                        &mut matched_char,
                        false,
                    )?;
                    out.push(node);
                }
                META_COND_VERSION => {
                    let ge = self.meta[self.p + 1] > 0;
                    let (major, minor) = (self.meta[self.p + 2], self.meta[self.p + 3]);
                    self.p += 4;
                    let value =
                        if ge { 10 > major || (10 == major && 44 >= minor) } else { major == 10 && minor == 44 };
                    let node = self.compile_cond(
                        Cond::Value(value),
                        options,
                        xoptions,
                        offset_var,
                        &mut st,
                        &mut matched_char,
                        false,
                    )?;
                    out.push(node);
                }
                META_COND_ASSERT => {
                    self.p += 1;
                    // The assertion is the first item of the first branch.
                    let node = self.compile_cond(
                        Cond::Value(false),
                        options,
                        xoptions,
                        offset_var,
                        &mut st,
                        &mut matched_char,
                        true,
                    )?;
                    out.push(node);
                }
                META_LOOKAHEAD | META_LOOKAHEADNOT | META_LOOKAHEAD_NA | META_LOOKBEHIND | META_LOOKBEHINDNOT
                | META_LOOKBEHIND_NA => {
                    let node = self.compile_lookaround(item, options, xoptions, &mut st)?;
                    out.push(node);
                    continue;
                }
                META_ATOMIC | META_SCRIPT_RUN | META_NOCAPTURE | META_CAPTURE => {
                    self.p += 1;
                    if meta == META_CAPTURE {
                        self.open_caps.push((arg, self.assert_depth));
                    }
                    let tempreqvary = self.req_varyopt;
                    let r = self.compile_regex(options, xoptions, false);
                    if meta == META_CAPTURE {
                        self.open_caps.pop();
                    }
                    let compiled = r?;
                    if !compiled.empty {
                        matched_char = true;
                    }
                    Self::group_units(&mut st, None, compiled.units, tempreqvary);
                    let kind = match meta {
                        META_ATOMIC => GroupKind::Atomic,
                        META_SCRIPT_RUN => GroupKind::ScriptRun,
                        META_CAPTURE => GroupKind::Capture(arg),
                        _ => GroupKind::NonCapture,
                    };
                    out.push(Node::Group { kind, branches: compiled.branches, empty: compiled.empty });
                }
                META_BACKREF_BYNAME | META_RECURSE_BYNAME => {
                    let length = self.meta[self.p + 1] as usize;
                    let offset = self.meta[self.p + 2] as usize;
                    offset_var = offset;
                    self.p += 2;
                    let Some((number, isdup)) = self.name_lookup(offset, length) else {
                        self.error_offset = offset;
                        return Err(15);
                    };
                    if meta == META_RECURSE_BYNAME {
                        Self::recursion_units(&mut st);
                        out.push(Node::Recurse(number));
                    } else if !isdup {
                        self.note_backref(number);
                        if st.u.firstcuflags == REQ_UNSET {
                            st.u.firstcuflags = REQ_NONE;
                            st.zero.firstcuflags = REQ_NONE;
                        }
                        out.push(Node::Ref { groups: vec![number], caseless: options & CASELESS != 0 });
                    } else {
                        let numbers = self.dup_numbers(offset, length);
                        for &n in &numbers {
                            self.note_backref(n);
                        }
                        if st.u.firstcuflags == REQ_UNSET {
                            st.u.firstcuflags = REQ_NONE;
                        }
                        out.push(Node::Ref { groups: numbers, caseless: options & CASELESS != 0 });
                    }
                }
                META_CALLOUT_NUMBER => self.p += 3,
                META_CALLOUT_STRING => {
                    offset_var = self.meta[self.p + 4] as usize;
                    self.p += 4;
                }
                META_MINMAX | META_MINMAX_PLUS | META_MINMAX_QUERY | META_ASTERISK | META_ASTERISK_PLUS
                | META_ASTERISK_QUERY | META_PLUS | META_PLUS_PLUS | META_PLUS_QUERY | META_QUERY | META_QUERY_PLUS
                | META_QUERY_QUERY => {
                    let (min, max) = match meta {
                        META_MINMAX | META_MINMAX_PLUS | META_MINMAX_QUERY => {
                            let mm = (self.meta[self.p + 1], self.meta[self.p + 2]);
                            self.p += 2;
                            mm
                        }
                        META_ASTERISK | META_ASTERISK_PLUS | META_ASTERISK_QUERY => (0, REPEAT_UNLIMITED),
                        META_PLUS | META_PLUS_PLUS | META_PLUS_QUERY => (1, REPEAT_UNLIMITED),
                        _ => (0, 1),
                    };
                    let kind = match meta {
                        META_MINMAX_PLUS | META_ASTERISK_PLUS | META_PLUS_PLUS | META_QUERY_PLUS => RepKind::Possessive,
                        META_MINMAX_QUERY | META_ASTERISK_QUERY | META_PLUS_QUERY | META_QUERY_QUERY => {
                            if greedy_default(options) == RepKind::Greedy {
                                RepKind::Lazy
                            } else {
                                RepKind::Greedy
                            }
                        }
                        _ => greedy_default(options),
                    };
                    if previous_matched_char && min > 0 {
                        matched_char = true;
                    }
                    let reqvary = if min == max { 0 } else { REQ_VARY };
                    if min == 0 {
                        st.u = st.zero;
                    }
                    if let Some(prev) = out.pop() {
                        match &prev {
                            // A single-code-unit character repeated at least
                            // twice is also a required code unit.
                            Node::Lit(Lit::Char(c) | Lit::CharI(c)) if !(min == 1 && max == 1) => {
                                let single = !self.utf || *c < 0x80;
                                if single && min > 1 {
                                    st.u.reqcu = *c;
                                    st.u.reqcuflags = self.req_varyopt;
                                    if matches!(prev, Node::Lit(Lit::CharI(_))) {
                                        st.u.reqcuflags |= REQ_CASELESS;
                                    }
                                }
                            }
                            Node::Group { kind: gk, branches, .. }
                                if min > 1
                                    && !(matches!(gk, GroupKind::Define)
                                        || matches!(gk, GroupKind::Cond(Cond::Value(false)))
                                            && branches.len() == 1)
                                    && st.groupsetfirstcu
                                    && st.u.reqcuflags >= REQ_NONE =>
                            {
                                st.u.reqcu = st.u.firstcu;
                                st.u.reqcuflags = st.u.firstcuflags;
                            }
                            _ => {}
                        }
                        if let Some(n) = repeat(prev, min, max, kind) {
                            out.push(n);
                        }
                    }
                    self.req_varyopt |= reqvary;
                }
                META_BIGVALUE => {
                    self.p += 1;
                    let c = self.meta[self.p];
                    matched_char = true;
                    let node = self.literal(c, options, xoptions, &mut st);
                    out.push(node);
                }
                META_BACKREF => {
                    let offset = if arg < 10 {
                        self.parsed.small_ref_offset[arg as usize]
                    } else {
                        self.p += 1;
                        self.meta[self.p] as usize
                    };
                    offset_var = offset;
                    if arg > self.parsed.bracount {
                        self.error_offset = offset;
                        return Err(15);
                    }
                    if st.u.firstcuflags == REQ_UNSET {
                        st.u.firstcuflags = REQ_NONE;
                        st.zero.firstcuflags = REQ_NONE;
                    }
                    self.note_backref(arg);
                    out.push(Node::Ref { groups: vec![arg], caseless: options & CASELESS != 0 });
                }
                META_RECURSE => {
                    let offset = self.meta[self.p + 1] as usize;
                    offset_var = offset;
                    self.p += 1;
                    if arg > self.parsed.bracount {
                        self.error_offset = offset;
                        return Err(15);
                    }
                    Self::recursion_units(&mut st);
                    out.push(Node::Recurse(arg));
                }
                META_ESCAPE => {
                    if arg > ESC_B_LOWER && arg < ESC_Z {
                        matched_char = true;
                        if st.u.firstcuflags == REQ_UNSET {
                            st.u.firstcuflags = REQ_NONE;
                        }
                    }
                    st.zero = st.u;
                    if arg == ESC_P || arg == ESC_P_LOWER {
                        self.p += 1;
                        let v = self.meta[self.p];
                        let (ptype, pdata) = (v >> 16, v & 0xffff);
                        if arg == ESC_P_LOWER && ptype == PT_ANY {
                            out.push(Node::Lit(Lit::AllAny));
                        } else {
                            out.push(Node::Lit(Lit::Prop { neg: arg == ESC_P, ptype, pdata }));
                        }
                    } else {
                        if self.assert_depth > 0 && arg == ESC_K {
                            return Err(99);
                        }
                        if matches!(arg, ESC_A | ESC_B | ESC_B_LOWER) {
                            self.looks_back_one = true;
                        }
                        out.push(match arg {
                            ESC_A => Node::Assert(Assert::Sod),
                            ESC_G => Node::Assert(Assert::Som),
                            ESC_K => Node::SetSom,
                            ESC_B | ESC_B_LOWER => Node::Assert(Assert::WordBoundary {
                                ucp: options & UCP != 0 && xoptions & X_ASCII_BSW == 0,
                                negated: arg == ESC_B,
                            }),
                            ESC_D => Node::Lit(Lit::Digit(true)),
                            ESC_D_LOWER => Node::Lit(Lit::Digit(false)),
                            ESC_S => Node::Lit(Lit::Space(true)),
                            ESC_S_LOWER => Node::Lit(Lit::Space(false)),
                            ESC_W => Node::Lit(Lit::Word(true)),
                            ESC_W_LOWER => Node::Lit(Lit::Word(false)),
                            ESC_N => Node::Lit(Lit::Any),
                            ESC_C => Node::Lit(if self.utf { Lit::AnyByte } else { Lit::AllAny }),
                            ESC_R => Node::Lit(Lit::AnyNl),
                            ESC_H => Node::Lit(Lit::HSpace(true)),
                            ESC_H_LOWER => Node::Lit(Lit::HSpace(false)),
                            ESC_V => Node::Lit(Lit::VSpace(true)),
                            ESC_V_LOWER => Node::Lit(Lit::VSpace(false)),
                            ESC_X => Node::Lit(Lit::ExtUni),
                            ESC_Z => Node::Assert(Assert::Eodn),
                            ESC_Z_LOWER => Node::Assert(Assert::Eod),
                            _ => return Err(89),
                        });
                    }
                }
                _ => return Err(89),
            }
            self.p += 1;
        }
    }

    /// `HANDLE_NUMERICAL_RECURSION`.
    fn recursion_units(st: &mut BranchState) {
        st.groupsetfirstcu = false;
        if st.u.firstcuflags == REQ_UNSET {
            st.u.firstcuflags = REQ_NONE;
        }
        st.zero.firstcu = st.u.firstcu;
        st.zero.firstcuflags = st.u.firstcuflags;
    }

    /// A lookaround group; `p` is at its META item. Leaves `p` after its KET.
    fn compile_lookaround(&mut self, item: u32, options: u32, xoptions: u32, st: &mut BranchState) -> CResult<Node> {
        let meta = meta_code(item);
        let behind = matches!(meta, META_LOOKBEHIND | META_LOOKBEHINDNOT | META_LOOKBEHIND_NA);
        let negated = matches!(meta, META_LOOKAHEADNOT | META_LOOKBEHINDNOT);
        let atomic = !matches!(meta, META_LOOKAHEAD_NA | META_LOOKBEHIND_NA);
        // (?!) alone is OP_FAIL, unless quantified.
        if meta == META_LOOKAHEADNOT
            && self.meta[self.p + 1] == META_KET
            && !(META_ASTERISK..=META_MINMAX_QUERY).contains(&meta_code(self.meta[self.p + 2]))
        {
            self.p += 2;
            return Ok(Node::Fail);
        }
        self.assert_depth += 1;
        let tempreqvary = self.req_varyopt;
        let mut reverse = Vec::new();
        let r = if behind {
            let first_max = meta_data(item);
            let min_all = self.meta[self.p + 1];
            self.p += 2;
            let mut lengths = vec![first_max];
            let r = self.compile_regex_collecting(options, xoptions, &mut lengths, false);
            for &max in &lengths {
                reverse.push(if min_all == LOOKBEHIND_MAX || min_all == max {
                    Reverse::Fixed(max)
                } else {
                    Reverse::Variable { min: min_all, max }
                });
            }
            r
        } else {
            self.p += 1;
            self.compile_regex(options, xoptions, false)
        };
        self.assert_depth -= 1;
        let compiled = r?;
        self.p += 1; // past KET
        let positive_ahead = !behind && !negated;
        Self::group_units(st, Some(positive_ahead), compiled.units, tempreqvary);
        Ok(Node::Group {
            kind: GroupKind::Look { behind, negated, atomic, reverse },
            branches: compiled.branches,
            empty: compiled.empty,
        })
    }

    /// `compile_regex` with each branch's maximum lookbehind length collected
    /// (stored in the data bits of its `META_ALT`), and the branches' first
    /// and required code units combined.
    fn compile_regex_collecting(
        &mut self,
        options: u32,
        xoptions: u32,
        lengths: &mut Vec<u32>,
        cond_assert: bool,
    ) -> CResult<Compiled> {
        let mut options = options;
        let mut xoptions = xoptions;
        let mut branches = Vec::new();
        let mut units = Units::UNSET;
        units.firstcu = 0;
        units.reqcu = 0;
        let mut empty = false;
        loop {
            let mut branch = Vec::new();
            let r =
                self.compile_branch(&mut options, &mut xoptions, &mut branch, cond_assert && branches.is_empty())?;
            if r.okreturn < 0 {
                empty = true;
            }
            let b = r.units;
            if branches.is_empty() {
                units = b;
            } else {
                let mut b = b;
                if units.firstcuflags != b.firstcuflags || units.firstcu != b.firstcu {
                    if units.firstcuflags < REQ_NONE && units.reqcuflags >= REQ_NONE {
                        units.reqcu = units.firstcu;
                        units.reqcuflags = units.firstcuflags;
                    }
                    units.firstcuflags = REQ_NONE;
                }
                if units.firstcuflags >= REQ_NONE && b.firstcuflags < REQ_NONE && b.reqcuflags >= REQ_NONE {
                    b.reqcu = b.firstcu;
                    b.reqcuflags = b.firstcuflags;
                }
                if (units.reqcuflags & !REQ_VARY) != (b.reqcuflags & !REQ_VARY) || units.reqcu != b.reqcu {
                    units.reqcuflags = REQ_NONE;
                } else {
                    units.reqcu = b.reqcu;
                    units.reqcuflags |= b.reqcuflags;
                }
            }
            branches.push(branch);
            let item = self.meta[self.p];
            if meta_code(item) != META_ALT {
                return Ok(Compiled { branches, units, empty });
            }
            lengths.push(meta_data(item));
            self.p += 1;
        }
    }

    /// A conditional group; `p` is at its first item. With `cond_assert`,
    /// the condition is an assertion, compiled as the first item of the
    /// first branch (`cond` is ignored).
    #[allow(clippy::too_many_arguments)]
    fn compile_cond(
        &mut self,
        cond: Cond,
        options: u32,
        xoptions: u32,
        offset: usize,
        st: &mut BranchState,
        matched_char: &mut bool,
        cond_assert: bool,
    ) -> CResult<Node> {
        let tempreqvary = self.req_varyopt;
        let mut compiled = self.compile_regex(options, xoptions, cond_assert)?;
        let cond = if cond_assert {
            let first = compiled.branches.first_mut().filter(|b| !b.is_empty()).map(|b| b.remove(0));
            Cond::Assert(Box::new(first.unwrap_or(Node::Fail)))
        } else {
            cond
        };
        if compiled.branches.len() > 2 && self.deferred.is_none() {
            self.deferred = Some((27, offset));
        }
        let mut sub = compiled.units;
        if compiled.branches.len() == 1 {
            sub.firstcuflags = REQ_NONE;
            sub.reqcuflags = REQ_NONE;
        } else if !compiled.empty {
            *matched_char = true;
        }
        Self::group_units(st, None, sub, tempreqvary);
        // `self.p` is at the closing KET (advanced by the caller's loop).
        Ok(Node::Group { kind: GroupKind::Cond(cond), branches: compiled.branches, empty: compiled.empty })
    }

    /// A character class; `p` is at `META_CLASS` or `META_CLASS_NOT`, and is
    /// left at `META_CLASS_END`.
    fn compile_class(&mut self, negate_class: bool, options: u32, xoptions: u32) -> ClassShape {
        let utf = self.utf;
        let ucp = self.ucp;
        let m = self.meta;
        let p = self.p;
        // One literal character.
        if m[p + 1] < META_END && m[p + 2] == META_CLASS_END {
            let c = m[p + 1];
            self.p += 2;
            if !negate_class {
                return ClassShape::Char(c);
            }
            if (utf || ucp) && options & CASELESS != 0 {
                let d = unicode::caseset(c);
                if d != 0 && (xoptions & X_CASELESS_RESTRICT == 0 || super::ucd::CASELESS_SETS[d as usize] > 127) {
                    return ClassShape::NegSingle(Node::Lit(Lit::Prop { neg: true, ptype: PT_CLIST, pdata: d }));
                }
            }
            return ClassShape::NegSingle(Node::Lit(if options & CASELESS != 0 { Lit::NotI(c) } else { Lit::Not(c) }));
        }
        // Two case partners: a caseless character.
        if !negate_class && m[p + 1] < META_END && m[p + 2] < META_END && m[p + 3] == META_CLASS_END {
            let c = m[p + 1];
            let skip = unicode::caseset(c) == 0 || (xoptions & X_CASELESS_RESTRICT != 0 && c < 128 && m[p + 2] < 128);
            if skip {
                let d = if (utf || ucp) && c > 127 { unicode::other_case(c) } else { unicode::fcc(c) };
                if c != d && m[p + 2] == d {
                    self.p += 3;
                    return ClassShape::CaselessPair(c);
                }
            }
        }

        let mut classbits = [0u8; 32];
        let mut xitems: Vec<XItem> = Vec::new();
        let mut should_flip_negation = false;
        let mut match_all_or_no_wide_chars = false;
        let mut class_has_8bitchar: i32 = 0;
        let mut xclass_has_prop = false;
        let mut i = p + 1;
        loop {
            let item = m[i];
            if item == META_CLASS_END {
                break;
            }
            if item == META_POSIX || item == META_POSIX_NEG {
                let local_negate = item == META_POSIX_NEG;
                i += 1;
                let mut posix_class = m[i] as usize;
                should_flip_negation = local_negate;
                if options & CASELESS != 0 && posix_class <= 2 {
                    posix_class = 0;
                }
                let mut skip_bitmap = false;
                if options & UCP != 0 && xoptions & X_ASCII_POSIX == 0 {
                    match posix_class as u32 {
                        8..=10 => {
                            xitems.push(XItem::Prop {
                                positive: !local_negate,
                                ptype: match posix_class {
                                    8 => PT_PXGRAPH,
                                    9 => PT_PXPRINT,
                                    _ => PT_PXPUNCT,
                                },
                                pdata: 0,
                            });
                            xclass_has_prop = true;
                            skip_bitmap = true;
                        }
                        _ => {
                            if utf {
                                match_all_or_no_wide_chars |= local_negate;
                            }
                        }
                    }
                }
                if !skip_bitmap {
                    // posix_class_maps
                    const MAPS: [(usize, i32, i32); 14] = [
                        (CBIT_WORD, CBIT_DIGIT as i32, -2),
                        (CBIT_LOWER, -1, 0),
                        (CBIT_UPPER, -1, 0),
                        (CBIT_WORD, -1, 2),
                        (CBIT_PRINT, CBIT_CNTRL as i32, 0),
                        (CBIT_SPACE, -1, 1),
                        (CBIT_CNTRL, -1, 0),
                        (CBIT_DIGIT, -1, 0),
                        (CBIT_GRAPH, -1, 0),
                        (CBIT_PRINT, -1, 0),
                        (CBIT_PUNCT, -1, 0),
                        (CBIT_SPACE, -1, 0),
                        (CBIT_WORD, -1, 0),
                        (CBIT_XDIGIT, -1, 0),
                    ];
                    let (base, second, opt) = MAPS[posix_class];
                    let mut pbits = [0u8; 32];
                    pbits.copy_from_slice(&CBITS[base..base + 32]);
                    if second >= 0 {
                        let s = second as usize;
                        for k in 0..32 {
                            if opt >= 0 {
                                pbits[k] |= CBITS[s + k];
                            } else {
                                pbits[k] &= !CBITS[s + k];
                            }
                        }
                    }
                    let opt = opt.abs();
                    if opt == 1 {
                        pbits[1] &= !0x3c;
                    } else if opt == 2 {
                        pbits[11] &= 0x7f;
                    }
                    for k in 0..32 {
                        classbits[k] |= if local_negate { !pbits[k] } else { pbits[k] };
                    }
                    class_has_8bitchar = 1;
                }
                i += 1;
                continue;
            }
            if item == META_BIGVALUE {
                i += 1;
            } else if item >= META_END {
                // An escape
                let escape = meta_data(item);
                class_has_8bitchar += 1;
                let cbit = |classbits: &mut [u8; 32], base: usize, neg: bool| {
                    for k in 0..32 {
                        classbits[k] |= if neg { !CBITS[base + k] } else { CBITS[base + k] };
                    }
                };
                let no_caseless = options & !CASELESS;
                match escape {
                    ESC_D_LOWER => cbit(&mut classbits, CBIT_DIGIT, false),
                    ESC_D => {
                        should_flip_negation = true;
                        cbit(&mut classbits, CBIT_DIGIT, true);
                    }
                    ESC_W_LOWER => cbit(&mut classbits, CBIT_WORD, false),
                    ESC_W => {
                        should_flip_negation = true;
                        cbit(&mut classbits, CBIT_WORD, true);
                    }
                    ESC_S_LOWER => cbit(&mut classbits, CBIT_SPACE, false),
                    ESC_S => {
                        should_flip_negation = true;
                        cbit(&mut classbits, CBIT_SPACE, true);
                    }
                    ESC_H_LOWER => {
                        add_list(&mut classbits, &mut xitems, no_caseless, xoptions, &super::ucd::HSPACE);
                    }
                    ESC_H => {
                        add_not_list(&mut classbits, &mut xitems, no_caseless, xoptions, &super::ucd::HSPACE);
                    }
                    ESC_V_LOWER => {
                        add_list(&mut classbits, &mut xitems, no_caseless, xoptions, &super::ucd::VSPACE);
                    }
                    ESC_V => {
                        add_not_list(&mut classbits, &mut xitems, no_caseless, xoptions, &super::ucd::VSPACE);
                    }
                    ESC_P | ESC_P_LOWER => {
                        i += 1;
                        let v = m[i];
                        xitems.push(XItem::Prop { positive: escape == ESC_P_LOWER, ptype: v >> 16, pdata: v & 0xffff });
                        xclass_has_prop = true;
                        class_has_8bitchar -= 1;
                    }
                    _ => {}
                }
                i += 1;
                continue;
            }
            // A literal, possibly starting a range.
            let c = m[i];
            let mut d = c;
            if m[i + 1] == META_RANGE_LITERAL || m[i + 1] == META_RANGE_ESCAPED {
                i += 2;
                d = m[i];
                if d == META_BIGVALUE {
                    i += 1;
                    d = m[i];
                }
            }
            class_has_8bitchar += add_to_class(&mut classbits, &mut xitems, options, xoptions, c, d) as i32;
            i += 1;
        }
        self.p = i;

        let xclass = !xitems.is_empty();
        if xclass && (options & UCP != 0 || xclass_has_prop || !should_flip_negation) {
            if match_all_or_no_wide_chars || (utf && should_flip_negation && !negate_class && options & UCP == 0) {
                xitems.push(XItem::Range(0x100, 0x10ffff));
            }
            let map = if class_has_8bitchar > 0 {
                if negate_class && !xclass_has_prop {
                    for b in classbits.iter_mut() {
                        *b = !*b;
                    }
                }
                Some(classbits)
            } else {
                None
            };
            return ClassShape::General(Node::Lit(Lit::Class(Box::new(Class::X {
                negated: negate_class,
                has_prop: xclass_has_prop,
                map,
                items: xitems,
            }))));
        }
        if negate_class {
            for b in classbits.iter_mut() {
                *b = !*b;
            }
        }
        ClassShape::General(Node::Lit(Lit::Class(Box::new(if negate_class == should_flip_negation {
            Class::Map(classbits)
        } else {
            Class::NMap(classbits)
        }))))
    }
}

/// Applies a quantifier to the previous item, as the REPEAT section of
/// `compile_branch()` does. `None` removes the item (a `{0}` repeat of a
/// single-character item or back reference).
fn repeat(prev: Node, min: u32, max: u32, kind: RepKind) -> Option<Node> {
    match &prev {
        Node::Lit(_) | Node::Ref { .. } => {
            if max == 0 {
                return None;
            }
            if min == 1 && max == 1 {
                return Some(prev);
            }
            Some(Node::Repeat { node: Box::new(prev), min, max, kind })
        }
        Node::Recurse(_) | Node::Group { .. } => {
            if min == 1 && max == 1 && kind != RepKind::Possessive {
                return Some(prev);
            }
            let mut max = max;
            if let Node::Group { kind: gk, branches, .. } = &prev {
                match gk {
                    GroupKind::Define => return Some(prev),
                    GroupKind::Cond(Cond::Value(false)) if branches.len() == 1 => return Some(prev),
                    GroupKind::Look { .. } if max == REPEAT_UNLIMITED => max = min + 1,
                    _ => {}
                }
            }
            Some(Node::Repeat { node: Box::new(prev), min, max, kind })
        }
        // OP_FAIL from an empty class ignores its quantifier.
        _ => Some(prev),
    }
}

/// `get_othercase_range()`: the next run of characters in `*c..=d` with a
/// single other case, or a character with a caseless set.
enum OtherCase {
    Range(u32, u32),
    Set(u32, u32),
}

fn get_othercase_range(c: &mut u32, d: u32, restricted: bool) -> Option<OtherCase> {
    let mut ch = *c;
    let mut othercase = 0;
    while ch <= d {
        if ch > 0x10ffff {
            return None;
        }
        let co = unicode::caseset(ch);
        if co != 0 && (!restricted || super::ucd::CASELESS_SETS[co as usize] > 127) {
            *c = ch + 1;
            return Some(OtherCase::Set(co, ch));
        }
        othercase = unicode::other_case(ch);
        if othercase != ch {
            break;
        }
        ch += 1;
    }
    if ch > d {
        return None;
    }
    let oc = othercase;
    let mut next = othercase + 1;
    ch += 1;
    while ch <= d {
        if unicode::caseset(ch) != 0 || unicode::other_case(ch) != next {
            break;
        }
        next += 1;
        ch += 1;
    }
    *c = ch;
    Some(OtherCase::Range(oc, next - 1))
}

struct RangeCtx {
    start: u32,
    end: u32,
}

/// `add_to_class()`: returns the number of characters < 256 added.
fn add_to_class(
    classbits: &mut [u8; 32],
    xitems: &mut Vec<XItem>,
    options: u32,
    xoptions: u32,
    start: u32,
    end: u32,
) -> u32 {
    let ctx = RangeCtx { start, end };
    add_to_class_internal(classbits, xitems, options, xoptions, &ctx, start, end)
}

fn setbit(classbits: &mut [u8; 32], c: u32) {
    classbits[(c / 8) as usize] |= 1 << (c & 7);
}

fn add_to_class_internal(
    classbits: &mut [u8; 32],
    xitems: &mut Vec<XItem>,
    options: u32,
    xoptions: u32,
    ctx: &RangeCtx,
    start: u32,
    end: u32,
) -> u32 {
    let mut start = start;
    let mut end = end;
    let mut classbits_end = end.min(0xff);
    let mut n8 = 0;
    let mut options = options;
    if options & CASELESS != 0 {
        if options & (UTF | UCP) != 0 {
            options &= !CASELESS;
            let mut c = start;
            let restricted = xoptions & X_CASELESS_RESTRICT != 0;
            while let Some(r) = get_othercase_range(&mut c, end, restricted) {
                match r {
                    OtherCase::Set(set, except) => {
                        n8 += add_list_internal(
                            classbits,
                            xitems,
                            options,
                            xoptions,
                            ctx,
                            unicode::caseless_set(set),
                            Some(except),
                        );
                    }
                    OtherCase::Range(oc, od) => {
                        if oc >= ctx.start && od <= ctx.end {
                            continue;
                        } else if oc < start && od + 1 >= start {
                            start = oc;
                        } else if od > end && oc <= end + 1 {
                            end = od;
                            if end > classbits_end {
                                classbits_end = end.min(0xff);
                            }
                        } else {
                            n8 += add_to_class_internal(classbits, xitems, options, xoptions, ctx, oc, od);
                        }
                    }
                }
            }
        } else {
            let mut c = start;
            while c <= classbits_end {
                setbit(classbits, unicode::fcc(c));
                n8 += 1;
                c += 1;
            }
        }
    }
    if options & UTF == 0 && end > 0xff {
        end = 0xff;
    }
    if start > ctx.start && end < ctx.end {
        return n8;
    }
    let mut c = start;
    while c <= classbits_end {
        setbit(classbits, c);
        n8 += 1;
        c += 1;
    }
    let start = start.max(0x100);
    if end >= start && options & UTF != 0 {
        xitems.push(XItem::Range(start, end));
    }
    n8
}

fn add_list_internal(
    classbits: &mut [u8; 32],
    xitems: &mut Vec<XItem>,
    options: u32,
    xoptions: u32,
    ctx: &RangeCtx,
    list: &[u32],
    except: Option<u32>,
) -> u32 {
    let mut n8 = 0;
    let mut i = 0;
    while i < list.len() {
        let mut n = 0;
        if Some(list[i]) != except {
            while i + n + 1 < list.len() && list[i + n + 1] == list[i] + n as u32 + 1 {
                n += 1;
            }
            n8 += add_to_class_internal(classbits, xitems, options, xoptions, ctx, list[i], list[i + n]);
        }
        i += n + 1;
    }
    n8
}

/// `add_list_to_class()`
fn add_list(classbits: &mut [u8; 32], xitems: &mut Vec<XItem>, options: u32, xoptions: u32, list: &[u32]) -> u32 {
    let mut n8 = 0;
    let mut i = 0;
    while i < list.len() {
        let mut n = 0;
        while i + n + 1 < list.len() && list[i + n + 1] == list[i] + n as u32 + 1 {
            n += 1;
        }
        let ctx = RangeCtx { start: list[i], end: list[i + n] };
        n8 += add_to_class_internal(classbits, xitems, options, xoptions, &ctx, list[i], list[i + n]);
        i += n + 1;
    }
    n8
}

/// `add_not_list_to_class()`
fn add_not_list(classbits: &mut [u8; 32], xitems: &mut Vec<XItem>, options: u32, xoptions: u32, list: &[u32]) -> u32 {
    let utf = options & UTF != 0;
    let mut n8 = 0;
    if list[0] > 0 {
        n8 += add_to_class(classbits, xitems, options, xoptions, 0, list[0] - 1);
    }
    let mut i = 0;
    while i < list.len() {
        while i + 1 < list.len() && list[i + 1] == list[i] + 1 {
            i += 1;
        }
        let hi = if i + 1 == list.len() { if utf { 0x10ffff } else { 0xffff_ffff } } else { list[i + 1] - 1 };
        n8 += add_to_class(classbits, xitems, options, xoptions, list[i] + 1, hi);
        i += 1;
    }
    n8
}
