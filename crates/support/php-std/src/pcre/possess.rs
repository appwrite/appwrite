//! `auto_possessify()` (`pcre2_auto_possess.c`): turns a repeated single
//! item into its possessive form when what can follow it never matches a
//! character it matches (`a+b` becomes `a++b`), unless the pattern sets
//! `(*NO_AUTO_POSSESS)`.
//!
//! Matching results do not change, but backtracking does: the interpreter's
//! depth and match counts, the JIT's match counts and when the limits run
//! out all depend on it, so it is ported as is, on the lowered program.

use super::compile::{Assert, Class, Lit, RepKind};
use super::exec::xclass;
use super::parse::{
    PT_ALNUM, PT_BIDICL, PT_BOOL, PT_CLIST, PT_GC, PT_LAMP, PT_PC, PT_PXSPACE, PT_SC, PT_SCX, PT_SPACE, PT_WORD,
};
use super::program::{BraKind, KetKind, Op};
use super::unicode::{self, CBIT_DIGIT, CBIT_SPACE, CBIT_WORD, CBITS, CTYPE_DIGIT, CTYPE_SPACE, CTYPE_WORD};

/// Rows and columns of `autoposstab`, in PCRE2's opcode order:
/// `\D \d \S \s \W \w . .+ \C \P \p \R \H \h \V \v \X \Z \z $ $M`.
mod t {
    pub const NOT_DIGIT: usize = 0;
    pub const DIGIT: usize = 1;
    pub const NOT_WHITESPACE: usize = 2;
    pub const WHITESPACE: usize = 3;
    pub const NOT_WORDCHAR: usize = 4;
    pub const WORDCHAR: usize = 5;
    pub const ANY: usize = 6;
    pub const ALLANY: usize = 7;
    pub const ANYNL: usize = 11;
    pub const NOT_HSPACE: usize = 12;
    pub const HSPACE: usize = 13;
    pub const NOT_VSPACE: usize = 14;
    pub const VSPACE: usize = 15;
    pub const EXTUNI: usize = 16;
    pub const EODN: usize = 17;
    pub const EOD: usize = 18;
    pub const DOLL: usize = 19;
    pub const DOLLM: usize = 20;
    /// Rows exist up to `\X`.
    pub const LAST_LEFT: usize = 16;
}

#[rustfmt::skip]
const AUTOPOSSTAB: [[u8; 21]; 17] = [
    [0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0], // \D
    [1, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 1, 0, 1, 0, 1, 0, 1, 1, 1, 1], // \d
    [0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 1, 0, 1, 0, 1, 0, 1, 1, 1, 1], // \S
    [0, 1, 1, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0], // \s
    [0, 1, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0], // \W
    [0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 1, 0, 1, 0, 1, 0, 1, 1, 1, 1], // \w
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 1, 0, 0], // .
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0], // .+
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0], // \C
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], // \P
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], // \p
    [0, 1, 0, 1, 0, 1, 1, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0], // \R
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0], // \H
    [0, 1, 1, 0, 0, 1, 0, 0, 0, 0, 0, 1, 1, 0, 0, 1, 0, 0, 1, 0, 0], // \h
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 1, 0, 0], // \V
    [0, 1, 1, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 1, 1, 0, 0, 0, 1, 0, 0], // \v
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0], // \X
];

/// `propposstab`: how two Unicode property items are compared.
#[rustfmt::skip]
const PROPPOSSTAB: [[u8; 14]; 14] = [
    // ANY LAMP GC PC SC SCX ALNUM SPACE PXSPACE WORD CLIST UCNC BIDICL BOOL
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],    // PT_ANY
    [0, 3, 0, 0, 0, 0, 3, 1, 1, 0, 0, 0, 0, 0],    // PT_LAMP
    [0, 0, 2, 4, 0, 0, 9, 10, 10, 11, 0, 0, 0, 0], // PT_GC
    [0, 0, 5, 2, 0, 0, 15, 16, 16, 17, 0, 0, 0, 0],// PT_PC
    [0, 0, 0, 0, 2, 2, 0, 0, 0, 0, 0, 0, 0, 0],    // PT_SC
    [0, 0, 0, 0, 2, 2, 0, 0, 0, 0, 0, 0, 0, 0],    // PT_SCX
    [0, 3, 6, 12, 0, 0, 3, 1, 1, 0, 0, 0, 0, 0],   // PT_ALNUM
    [0, 1, 7, 13, 0, 0, 1, 3, 3, 1, 0, 0, 0, 0],   // PT_SPACE
    [0, 1, 7, 13, 0, 0, 1, 3, 3, 1, 0, 0, 0, 0],   // PT_PXSPACE
    [0, 0, 8, 14, 0, 0, 0, 1, 1, 3, 0, 0, 0, 0],   // PT_WORD
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],    // PT_CLIST
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 3, 0, 0],    // PT_UCNC
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],    // PT_BIDICL
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],    // PT_BOOL
];

/// `catposstab`: 1 where the particular category (column) is not part of
/// the general category (row).
#[rustfmt::skip]
const CATPOSSTAB: [[u8; 30]; 7] = [
    // Cc Cf Cn Co Cs Ll Lm Lo Lt Lu Mc Me Mn Nd Nl No Pc Pd Pe Pf Pi Po Ps Sc Sk Sm So Zl Zp Zs
    [0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1], // C
    [1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1], // L
    [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1], // M
    [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1], // N
    [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1], // P
    [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 1, 1, 1], // S
    [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0], // Z
];

// The categories `posspropstab` uses (`ucp_C` ... and `ucp_Nl`, `ucp_Po`).
use unicode::{CC as UCP_CC, GC_C as UCP_C, GC_L as UCP_L, GC_N as UCP_N, GC_P as UCP_P, GC_Z as UCP_Z};
const UCP_NL: u32 = 14;
const UCP_PO: u32 = 21;

/// `posspropstab`: the categories ALNUM, SPACE and WORD cover.
const POSSPROPSTAB: [[u32; 4]; 3] = [
    [UCP_L, UCP_N, UCP_N, UCP_NL], // ALNUM
    [UCP_Z, UCP_Z, UCP_C, UCP_CC], // SPACE and PXSPACE
    [UCP_L, UCP_N, UCP_P, UCP_PO], // WORD
];

/// What `get_chr_property_list()` makes of an item.
#[derive(Clone, Copy, PartialEq, Eq)]
enum Kind {
    Char,
    Not,
    Class,
    NClass,
    XClass,
    Prop,
    NotProp,
    /// An `autoposstab` index.
    Type(usize),
}

struct Item<'a> {
    kind: Kind,
    /// For the base: a greedy repeat; for the next item: it can match the
    /// empty string.
    flag: bool,
    /// `Char`/`Not`: the characters.
    chars: Vec<u32>,
    ptype: u32,
    pdata: u32,
    class: Option<&'a Class>,
}

struct Possess<'a> {
    code: &'a [Op],
    utf: bool,
    ucp: bool,
    had_recurse: bool,
    rec_limit: i32,
}

/// Makes the repeats that can be possessive possessive.
pub fn auto_possessify(code: &mut [Op], utf: bool, ucp: bool) {
    let had_recurse = code.iter().any(|op| matches!(op, Op::Recurse(_)));
    let mut changes = Vec::new();
    {
        let mut p = Possess { code, utf, ucp, had_recurse, rec_limit: 1000 };
        for (pc, op) in code.iter().enumerate() {
            let Op::Rep { lit, min, max, kind } = op else { continue };
            if *kind == RepKind::Possessive {
                continue;
            }
            // A fixed repeat has nothing to give back, but a class repeat
            // (OP_CRRANGE) is still compared, which counts against the limit.
            if min == max && !matches!(lit, Lit::Class(_)) {
                continue;
            }
            let Some(mut base) = p.item(lit, false) else { continue };
            base.flag = *kind == RepKind::Greedy;
            if p.compare(pc + 1, &base) && min != max {
                changes.push(pc);
            }
        }
    }
    for pc in changes {
        if let Op::Rep { kind, .. } = &mut code[pc] {
            *kind = RepKind::Possessive;
        }
    }
}

impl<'a> Possess<'a> {
    fn link(&self, pc: usize) -> usize {
        match self.code[pc] {
            Op::Bra { link, .. } | Op::Alt { link } => link,
            _ => pc,
        }
    }

    /// `get_chr_property_list()` for a literal.
    fn item(&self, lit: &'a Lit, flag: bool) -> Option<Item<'a>> {
        let mut it = Item { kind: Kind::Char, flag, chars: Vec::new(), ptype: 0, pdata: 0, class: None };
        let ty = |i| Some(Kind::Type(i));
        let kind = match lit {
            Lit::Char(c) => {
                it.chars.push(*c);
                Some(Kind::Char)
            }
            Lit::Not(c) => {
                it.chars.push(*c);
                Some(Kind::Not)
            }
            Lit::CharI(c) | Lit::NotI(c) => {
                let c = *c;
                let other = if c < 128 || (c < 256 && !self.utf && !self.ucp) {
                    unicode::fcc(c)
                } else {
                    unicode::other_case(c)
                };
                it.chars.push(c);
                if other != c {
                    it.chars.push(other);
                }
                Some(if matches!(lit, Lit::CharI(_)) { Kind::Char } else { Kind::Not })
            }
            Lit::Prop { neg, ptype, pdata } => {
                if *ptype == PT_CLIST {
                    it.chars.extend_from_slice(unicode::caseless_set(*pdata));
                    Some(if *neg { Kind::Not } else { Kind::Char })
                } else {
                    it.ptype = *ptype;
                    it.pdata = *pdata;
                    Some(if *neg { Kind::NotProp } else { Kind::Prop })
                }
            }
            Lit::Class(c) => {
                let c: &Class = c;
                it.class = Some(c);
                Some(match c {
                    Class::Map(_) => Kind::Class,
                    Class::NMap(_) => Kind::NClass,
                    Class::X { .. } => Kind::XClass,
                })
            }
            Lit::Digit(neg) => ty(if *neg { t::NOT_DIGIT } else { t::DIGIT }),
            Lit::Space(neg) => ty(if *neg { t::NOT_WHITESPACE } else { t::WHITESPACE }),
            Lit::Word(neg) => ty(if *neg { t::NOT_WORDCHAR } else { t::WORDCHAR }),
            Lit::Any => ty(t::ANY),
            Lit::AllAny => ty(t::ALLANY),
            Lit::AnyNl => ty(t::ANYNL),
            Lit::HSpace(neg) => ty(if *neg { t::NOT_HSPACE } else { t::HSPACE }),
            Lit::VSpace(neg) => ty(if *neg { t::NOT_VSPACE } else { t::VSPACE }),
            Lit::ExtUni => ty(t::EXTUNI),
            Lit::AnyByte => None,
        }?;
        it.kind = kind;
        Some(it)
    }

    /// The item at `pc` as the one that follows: an item, a repeat (empty
    /// when its minimum is 0) or an end-of-line assertion.
    fn next_item(&self, pc: usize) -> Option<Item<'a>> {
        match &self.code[pc] {
            Op::Item(lit) => self.item(lit, false),
            Op::Rep { lit, min, .. } => self.item(lit, *min == 0),
            Op::Assert(a) => {
                let i = match a {
                    Assert::Dollar => t::DOLL,
                    Assert::DollarM => t::DOLLM,
                    Assert::Eod => t::EOD,
                    Assert::Eodn => t::EODN,
                    _ => return None,
                };
                Some(Item { kind: Kind::Type(i), flag: false, chars: Vec::new(), ptype: 0, pdata: 0, class: None })
            }
            _ => None,
        }
    }

    /// `compare_opcodes()`: whether nothing from `pc` on can match a
    /// character the base matches.
    fn compare(&mut self, mut pc: usize, base: &Item<'_>) -> bool {
        self.rec_limit -= 1;
        if self.rec_limit <= 0 {
            return false;
        }
        let mut entered_a_group = false;
        loop {
            // At the end of a branch, skip to the end of the group.
            if let Op::Alt { .. } = self.code[pc] {
                while let Op::Alt { link } = self.code[pc] {
                    pc = link;
                }
            }
            match &self.code[pc] {
                Op::End => return base.flag,
                Op::Ket { bra, kind: KetKind::Ket | KetKind::RPos } => {
                    if !base.flag {
                        return false;
                    }
                    let Op::Bra { kind, .. } = self.code[*bra] else { return false };
                    match kind {
                        BraKind::Capture(_) | BraKind::CapturePos(_) => {
                            if self.had_recurse {
                                return false;
                            }
                        }
                        BraKind::ScriptRun => {
                            if base.kind != Kind::Char {
                                return false;
                            }
                        }
                        BraKind::Assert | BraKind::AssertNot | BraKind::Once => return !entered_a_group,
                        BraKind::AssertBack | BraKind::AssertBackNot => {
                            return !matches!(self.code[*bra + 1], Op::VReverse { .. }) && !entered_a_group;
                        }
                        BraKind::AssertNa | BraKind::AssertBackNa => return false,
                        _ => {}
                    }
                    pc += 1;
                    continue;
                }
                Op::Bra { kind: BraKind::Once, .. }
                | Op::Bra { kind: BraKind::Bra | BraKind::Capture(_), empty: false, .. } => {
                    // Every branch but the last is checked by recursion.
                    let mut next = self.link(pc);
                    pc += 1;
                    while let Op::Alt { link } = self.code[next] {
                        if !self.compare(pc, base) {
                            return false;
                        }
                        pc = next + 1;
                        next = link;
                    }
                    entered_a_group = true;
                    continue;
                }
                Op::BraZero | Op::BraMinZero => {
                    let bra = pc + 1;
                    if !matches!(
                        self.code[bra],
                        Op::Bra { kind: BraKind::Once, .. }
                            | Op::Bra { kind: BraKind::Bra | BraKind::Capture(_), empty: false, .. }
                    ) {
                        return false;
                    }
                    let mut ket = self.link(bra);
                    while let Op::Alt { link } = self.code[ket] {
                        ket = link;
                    }
                    if !self.compare(ket + 1, base) {
                        return false;
                    }
                    pc = bra;
                    continue;
                }
                _ => {}
            }
            let Some(list) = self.next_item(pc) else { return false };
            pc += 1;
            let (chars_side, other) = if base.kind == Kind::Char {
                (base, &list)
            } else if list.kind == Kind::Char {
                (&list, base)
            } else {
                // Bit maps against classes and \d \s \w.
                let is_class = |k: Kind| k == Kind::Class || (!self.utf && k == Kind::NClass);
                if is_class(base.kind) || is_class(list.kind) {
                    let (set1, other) = if is_class(base.kind) { (base, &list) } else { (&list, base) };
                    let Some(set1) = class_map(set1.class) else { return false };
                    let (set2, invert): (&[u8], bool) = match other.kind {
                        Kind::Class | Kind::NClass => match class_map(other.class) {
                            Some(m) => (m, false),
                            None => return false,
                        },
                        Kind::XClass => match other.class {
                            Some(Class::X { negated, has_prop, map, .. }) => {
                                if *has_prop {
                                    return false;
                                }
                                match map {
                                    Some(m) => (&m[..], false),
                                    None => {
                                        // No bits for characters < 256.
                                        if !list.flag {
                                            return !negated;
                                        }
                                        continue;
                                    }
                                }
                            }
                            _ => return false,
                        },
                        Kind::Type(t::NOT_DIGIT) => (&CBITS[CBIT_DIGIT..CBIT_DIGIT + 32], true),
                        Kind::Type(t::DIGIT) => (&CBITS[CBIT_DIGIT..CBIT_DIGIT + 32], false),
                        Kind::Type(t::NOT_WHITESPACE) => (&CBITS[CBIT_SPACE..CBIT_SPACE + 32], true),
                        Kind::Type(t::WHITESPACE) => (&CBITS[CBIT_SPACE..CBIT_SPACE + 32], false),
                        Kind::Type(t::NOT_WORDCHAR) => (&CBITS[CBIT_WORD..CBIT_WORD + 32], true),
                        Kind::Type(t::WORDCHAR) => (&CBITS[CBIT_WORD..CBIT_WORD + 32], false),
                        _ => return false,
                    };
                    for k in 0..32 {
                        let b = if invert { !set2[k] } else { set2[k] };
                        if set1[k] & b != 0 {
                            return false;
                        }
                    }
                    if !list.flag {
                        return true;
                    }
                    continue;
                }
                // Properties and the character types.
                let accepted = match (base.kind, list.kind) {
                    (Kind::Prop | Kind::NotProp, Kind::Type(t::EOD)) => true,
                    (Kind::Prop | Kind::NotProp, Kind::Prop | Kind::NotProp) => props_disjoint(base, &list),
                    (Kind::Prop | Kind::NotProp, _) => false,
                    (Kind::Type(l), Kind::Type(r)) => l <= t::LAST_LEFT && AUTOPOSSTAB[l][r] != 0,
                    _ => false,
                };
                if !accepted {
                    return false;
                }
                if !list.flag {
                    return true;
                }
                continue;
            };
            // A small character list against the other item.
            for &chr in &chars_side.chars {
                if !self.char_disjoint(chr, other) {
                    return false;
                }
            }
            // At least one character must be matched by this item.
            if !list.flag {
                return true;
            }
        }
    }

    /// Whether `chr` cannot be matched by `other`.
    fn char_disjoint(&self, chr: u32, other: &Item<'_>) -> bool {
        let ctype = |bit: u8| chr <= 255 && unicode::ctypes(chr) & bit != 0;
        match other.kind {
            Kind::Char => !other.chars.contains(&chr),
            Kind::Not => other.chars.contains(&chr),
            Kind::Type(t::DIGIT) => !ctype(CTYPE_DIGIT),
            Kind::Type(t::NOT_DIGIT) => ctype(CTYPE_DIGIT),
            Kind::Type(t::WHITESPACE) => !ctype(CTYPE_SPACE),
            Kind::Type(t::NOT_WHITESPACE) => ctype(CTYPE_SPACE),
            // PCRE2 tests `chr < 255` here.
            Kind::Type(t::WORDCHAR) => !(chr < 255 && unicode::ctypes(chr) & CTYPE_WORD != 0),
            Kind::Type(t::NOT_WORDCHAR) => ctype(CTYPE_WORD),
            Kind::Type(t::HSPACE) => !unicode::is_hspace(chr),
            Kind::Type(t::NOT_HSPACE) => unicode::is_hspace(chr),
            Kind::Type(t::ANYNL | t::VSPACE) => !unicode::is_vspace(chr),
            Kind::Type(t::NOT_VSPACE) => unicode::is_vspace(chr),
            Kind::Type(t::DOLL | t::EODN) => !matches!(chr, 0x0d | 0x0a | 0x0b | 0x0c | 0x85 | 0x2028 | 0x2029),
            Kind::Type(t::EOD) => true,
            Kind::Prop | Kind::NotProp => check_char_prop(chr, other.ptype, other.pdata, other.kind == Kind::NotProp),
            Kind::NClass | Kind::Class => {
                if chr > 255 {
                    return other.kind == Kind::Class;
                }
                class_map(other.class).is_some_and(|m| m[(chr >> 3) as usize] & (1 << (chr & 7)) == 0)
            }
            Kind::XClass => match other.class {
                Some(Class::X { negated, has_prop, map, items }) => {
                    !xclass(chr, *negated, *has_prop, map.as_ref(), items.as_slice())
                }
                _ => false,
            },
            Kind::Type(_) => false,
        }
    }
}

/// The 32-byte map of a `Class`/`NClass` item, or of an `XClass` that has one.
fn class_map(class: Option<&Class>) -> Option<&[u8; 32]> {
    match class? {
        Class::Map(m) | Class::NMap(m) => Some(m),
        Class::X { map, .. } => map.as_ref(),
    }
}

/// `check_char_prop()`: whether `c` never has the property (`negated`:
/// always has it).
fn check_char_prop(c: u32, ptype: u32, pdata: u32, negated: bool) -> bool {
    let chartype = unicode::chartype(c);
    let gentype = unicode::gentype_of(chartype);
    match ptype {
        PT_LAMP => (chartype == unicode::LU || chartype == unicode::LL || chartype == unicode::LT) == negated,
        PT_GC => (pdata == gentype) == negated,
        PT_PC => (pdata == chartype) == negated,
        PT_SC => (pdata == unicode::script(c)) == negated,
        PT_SCX => unicode::has_script_extension(c, pdata) == negated,
        PT_ALNUM => (gentype == UCP_L || gentype == UCP_N) == negated,
        PT_SPACE | PT_PXSPACE => {
            if unicode::is_hspace(c) || unicode::is_vspace(c) {
                negated
            } else {
                (gentype == UCP_Z) == negated
            }
        }
        PT_WORD => (gentype == UCP_L || gentype == UCP_N || c == u32::from(b'_')) == negated,
        PT_CLIST => {
            // The set of the character itself, as PCRE2 does.
            for &x in unicode::caseless_set(unicode::caseset(c)) {
                if c < x {
                    return !negated;
                }
                if c == x {
                    return negated;
                }
            }
            !negated
        }
        PT_BIDICL | PT_BOOL => false,
        _ => false,
    }
}

/// The `propposstab` comparison of two property items.
fn props_disjoint(base: &Item<'_>, list: &Item<'_>) -> bool {
    let same = base.kind == list.kind;
    let lisprop = base.kind == Kind::Prop;
    let risprop = list.kind == Kind::Prop;
    let bothprop = lisprop && risprop;
    let (lt, rt) = (base.ptype as usize, list.ptype as usize);
    if lt >= 14 || rt >= 14 {
        return false;
    }
    let (l3, r3) = (base.pdata as usize, list.pdata as usize);
    let cat = |g: usize, p: usize| CATPOSSTAB.get(g).and_then(|row| row.get(p)).copied().unwrap_or(0);
    let n = PROPPOSSTAB[lt][rt];
    match n {
        1 => bothprop,
        2 => (l3 == r3) != same,
        3 => !same,
        4 => risprop && (cat(l3, r3) != 0) == same,
        5 => lisprop && (cat(r3, l3) != 0) == same,
        6..=8 => {
            let p = POSSPROPSTAB[usize::from(n - 6)];
            let r3 = r3 as u32;
            risprop && lisprop == (r3 != p[0] && r3 != p[1] && (r3 != p[2] || !lisprop))
        }
        9..=11 => {
            let p = POSSPROPSTAB[usize::from(n - 9)];
            let l3 = l3 as u32;
            lisprop && risprop == (l3 != p[0] && l3 != p[1] && (l3 != p[2] || !risprop))
        }
        12..=14 => {
            let p = POSSPROPSTAB[usize::from(n - 12)];
            risprop
                && lisprop
                    == (cat(p[0] as usize, r3) != 0 && cat(p[1] as usize, r3) != 0 && (r3 as u32 != p[3] || !lisprop))
        }
        15..=17 => {
            let p = POSSPROPSTAB[usize::from(n - 15)];
            lisprop
                && risprop
                    == (cat(p[0] as usize, l3) != 0 && cat(p[1] as usize, l3) != 0 && (l3 as u32 != p[3] || !risprop))
        }
        _ => false,
    }
}
