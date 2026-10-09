//! The start-of-match optimizations: what the end of `pcre2_compile()`
//! (`is_anchored()`, `is_startline()`, `find_firstassertedcu()`) and
//! `pcre2_study()` (`set_start_bits()`, `find_minlength()`) work out about
//! where a match can start, ported to the program representation.
//!
//! They are not just optimizations: `pcre2_match()` skips start positions
//! where a match cannot begin, so a pattern whose matching would run into
//! `(*COMMIT)`, a recursion loop or the backtracking limit at such a
//! position behaves differently without them.

use super::compile::{Assert, Class, Lit, REQ_CASELESS, REQ_NONE, REQ_VARY, XItem};
use super::parse::{PT_CLIST, opt};
use super::program::{BraKind, Op, Program, UNLIMITED};
use super::unicode::{self, CBIT_DIGIT, CBIT_SPACE, CBIT_WORD, CBITS};

/// What `pcre2_match()` uses to find start positions.
#[derive(Debug, Clone, Default)]
pub struct StartInfo {
    /// The pattern can only match at the start offset.
    pub anchored: bool,
    /// The first code unit and its other case (the same when caseful).
    pub first_cu: Option<(u8, u8)>,
    /// A match starts at the start of the subject or after a newline.
    pub startline: bool,
    /// The possible first code units.
    pub start_bits: Option<[u8; 32]>,
    /// A code unit that must appear (and its other case).
    pub req_cu: Option<(u8, u8)>,
    pub minlength: usize,
    /// `PCRE2_NO_START_OPTIMIZE`: none of the above is used.
    pub disabled: bool,
}

const SSB_FAIL: i32 = 0;
const SSB_DONE: i32 = 1;
const SSB_CONTINUE: i32 = 2;
const SSB_TOODEEP: i32 = 4;

const MAX_CACHE_BACKREF: u32 = 128;

struct Study<'a> {
    code: &'a [Op],
    prog: &'a Program,
    utf: bool,
    ucp: bool,
    options: u32,
    bits: [u8; 32],
}

/// Computes the start information of a compiled program.
pub fn study(prog: &Program, options: u32) -> StartInfo {
    let mut s = Study {
        code: &prog.code,
        prog,
        utf: options & opt::UTF != 0,
        ucp: options & opt::UCP != 0,
        options,
        bits: [0; 32],
    };
    let mut info = StartInfo::default();
    let anchored = options & opt::ANCHORED != 0 || s.is_anchored(0, 0, 0, false);
    info.anchored = anchored;
    if options & opt::NO_START_OPTIMIZE != 0 {
        info.disabled = true;
        return info;
    }
    let utf = s.utf;
    let ucp = s.ucp;
    let mut minminlength = 0usize;
    let mut units = prog.units;
    if units.firstcuflags >= REQ_NONE {
        let (c, f) = s.find_firstassertedcu(0, 0);
        units.firstcu = c;
        units.firstcuflags = f;
    }
    let caseless_other = |c: u32| -> Option<u32> {
        if c < 128 || (!utf && !ucp && c < 255) {
            if unicode::fcc(c) != c {
                return Some(unicode::fcc(c));
            }
        } else if ucp && !utf && unicode::other_case(c) != c {
            return Some(unicode::other_case(c));
        }
        None
    };
    let mut first_set: Option<(u32, bool)> = None;
    let mut startline = false;
    if units.firstcuflags < REQ_NONE {
        let caseless = units.firstcuflags & REQ_CASELESS != 0 && caseless_other(units.firstcu).is_some();
        first_set = Some((units.firstcu, caseless));
        minminlength += 1;
    } else if !anchored && s.is_startline(0, 0, 0, false) {
        startline = true;
    }
    let mut last_set: Option<(u32, bool)> = None;
    if units.reqcuflags < REQ_NONE {
        if !utf || units.firstcuflags >= REQ_NONE || units.firstcu & 0x80 == 0 || units.reqcu & 0x80 == 0 {
            minminlength += 1;
        }
        if !anchored || units.reqcuflags & REQ_VARY != 0 {
            let caseless = units.reqcuflags & REQ_CASELESS != 0 && caseless_other(units.reqcu).is_some();
            last_set = Some((units.reqcu, caseless));
        }
    }
    // pcre2_study()
    let mut first_map = false;
    if first_set.is_none() && !startline {
        let mut depth = 0;
        let rc = s.set_start_bits(0, &mut depth);
        if rc == SSB_DONE {
            first_map = true;
            if let Some((a, b)) = s.single_start_unit() {
                let clash = last_set.is_some_and(|(r, _)| r == a || b.is_some_and(|b| r == b));
                if !clash {
                    first_set = Some((a, b.is_some()));
                    first_map = false;
                }
            }
        }
    }
    let mut minlength = 0usize;
    if !prog.match_empty && !prog.had_accept && prog.top_backref <= MAX_CACHE_BACKREF {
        let mut count = 0;
        let mut cache = vec![0i32; MAX_CACHE_BACKREF as usize + 1];
        let min = s.find_minlength(0, &mut Vec::new(), &mut count, &mut cache);
        if min >= 0 {
            minlength = (min as usize).min(65535);
        }
    }
    if first_map && minminlength == 0 {
        minminlength = 1;
    }
    if minlength < minminlength {
        minlength = minminlength;
    }
    let other = |c: u32, caseless: bool| -> u8 {
        if !caseless {
            return c as u8;
        }
        let mut d = unicode::fcc(c);
        if c > 127 && ucp && !utf {
            d = unicode::other_case(c);
        }
        d as u8
    };
    info.first_cu = first_set.map(|(c, ci)| (c as u8, other(c, ci)));
    info.startline = startline;
    info.start_bits = if first_map && !startline { Some(s.bits) } else { None };
    info.req_cu = last_set.map(|(c, ci)| (c as u8, other(c, ci)));
    info.minlength = minlength;
    info
}

impl<'a> Study<'a> {
    fn link(&self, pc: usize) -> usize {
        match self.code[pc] {
            Op::Bra { link, .. } | Op::Alt { link } => link,
            _ => pc,
        }
    }

    fn is_alt(&self, pc: usize) -> bool {
        matches!(self.code[pc], Op::Alt { .. })
    }

    /// The `Ket` of the bracket at `pc`.
    fn ket(&self, mut pc: usize) -> usize {
        loop {
            pc = self.link(pc);
            if !self.is_alt(pc) {
                return pc;
            }
        }
    }

    /// `first_significant_code()`.
    fn first_significant_code(&self, mut pc: usize, skipassert: bool) -> usize {
        loop {
            match &self.code[pc] {
                Op::Bra {
                    kind: BraKind::AssertNot | BraKind::AssertBack | BraKind::AssertBackNot | BraKind::AssertBackNa,
                    ..
                } => {
                    if !skipassert {
                        return pc;
                    }
                    pc = self.ket(pc) + 1;
                }
                Op::Assert(Assert::WordBoundary { .. }) => {
                    if !skipassert {
                        return pc;
                    }
                    pc += 1;
                }
                Op::CondRef(_) | Op::CondRecurse(_) | Op::CondFalse | Op::CondTrue => pc += 1,
                // Past the first alternative (or the end) of the skipped group.
                Op::SkipZero => pc = self.link(pc + 1) + 1,
                Op::Bra { kind: BraKind::Cond, link, .. } => {
                    if !matches!(self.code[pc + 1], Op::CondFalse) || !matches!(self.code[*link], Op::Ket { .. }) {
                        return pc;
                    }
                    pc = link + 1;
                }
                Op::Mark(_) | Op::Commit(Some(_)) | Op::Prune(Some(_)) | Op::SkipArg(_) | Op::Then(Some(_)) => pc += 1,
                _ => return pc,
            }
        }
    }

    /// `.*` (`OP_TYPESTAR` and friends) of the given type.
    fn is_dotstar(op: &Op, allany: bool) -> bool {
        match op {
            Op::Rep { lit, min: 0, max: UNLIMITED, .. } => {
                if allany {
                    matches!(lit, Lit::AllAny)
                } else {
                    matches!(lit, Lit::Any)
                }
            }
            _ => false,
        }
    }

    /// `is_anchored()`.
    fn is_anchored(&self, mut pc: usize, bracket_map: u32, atomcount: u32, inassert: bool) -> bool {
        loop {
            let scode = self.first_significant_code(pc + 1, false);
            let ok = match &self.code[scode] {
                Op::Bra { kind: BraKind::Bra | BraKind::BraPos, .. } => {
                    self.is_anchored(scode, bracket_map, atomcount, inassert)
                }
                Op::Bra { kind: BraKind::Capture(n) | BraKind::CapturePos(n), .. } => {
                    let map = bracket_map | if *n < 32 { 1 << n } else { 1 };
                    self.is_anchored(scode, map, atomcount, inassert)
                }
                Op::Bra { kind: BraKind::Assert | BraKind::AssertNa, .. } => {
                    self.is_anchored(scode, bracket_map, atomcount, true)
                }
                Op::Bra { kind: BraKind::Cond, link, .. } => {
                    self.is_alt(*link) && self.is_anchored(scode, bracket_map, atomcount, inassert)
                }
                Op::Bra { kind: BraKind::Once, .. } => self.is_anchored(scode, bracket_map, atomcount + 1, inassert),
                op if Self::is_dotstar(op, true) => {
                    !(bracket_map & self.prog.backref_map != 0
                        || atomcount > 0
                        || self.prog.had_pruneorskip
                        || inassert
                        || self.options & opt::NO_DOTSTAR_ANCHOR != 0)
                }
                Op::Assert(Assert::Sod | Assert::Som | Assert::Circ) => true,
                _ => false,
            };
            if !ok {
                return false;
            }
            pc = self.link(pc);
            if !self.is_alt(pc) {
                return true;
            }
        }
    }

    /// `is_startline()`.
    fn is_startline(&self, mut pc: usize, bracket_map: u32, atomcount: u32, inassert: bool) -> bool {
        loop {
            let mut scode = self.first_significant_code(pc + 1, false);
            if let Op::Bra { kind: BraKind::Cond, .. } = self.code[scode] {
                let mut c = scode + 1;
                match self.code[c] {
                    Op::CondRef(_) | Op::CondRecurse(_) | Op::CondFalse | Op::CondTrue => return false,
                    _ => {
                        if !self.is_startline(c, bracket_map, atomcount, true) {
                            return false;
                        }
                        c = self.ket(c) + 1;
                    }
                }
                scode = self.first_significant_code(c, false);
            }
            let ok = match &self.code[scode] {
                Op::Bra { kind: BraKind::Bra | BraKind::BraPos, .. } => {
                    self.is_startline(scode, bracket_map, atomcount, inassert)
                }
                Op::Bra { kind: BraKind::Capture(n) | BraKind::CapturePos(n), .. } => {
                    let map = bracket_map | if *n < 32 { 1 << n } else { 1 };
                    self.is_startline(scode, map, atomcount, inassert)
                }
                Op::Bra { kind: BraKind::Assert | BraKind::AssertNa, .. } => {
                    self.is_startline(scode, bracket_map, atomcount, true)
                }
                Op::Bra { kind: BraKind::Once, .. } => self.is_startline(scode, bracket_map, atomcount + 1, inassert),
                op if Self::is_dotstar(op, false) => {
                    !(bracket_map & self.prog.backref_map != 0
                        || atomcount > 0
                        || self.prog.had_pruneorskip
                        || inassert
                        || self.options & opt::NO_DOTSTAR_ANCHOR != 0)
                }
                Op::Assert(Assert::Circ | Assert::CircM) => true,
                _ => false,
            };
            if !ok {
                return false;
            }
            pc = self.link(pc);
            if !self.is_alt(pc) {
                return true;
            }
        }
    }

    /// The first code unit of a character in the compiled code.
    fn first_unit(&self, c: u32) -> u32 {
        if self.utf && c >= 0x80 {
            let mut b = Vec::with_capacity(4);
            unicode::push_utf8(&mut b, c);
            u32::from(b[0])
        } else {
            c
        }
    }

    /// `find_firstassertedcu()`.
    fn find_firstassertedcu(&self, mut pc: usize, inassert: u32) -> (u32, u32) {
        let mut c = 0;
        let mut cflags = REQ_NONE;
        loop {
            let scode = self.first_significant_code(pc + 1, true);
            match &self.code[scode] {
                Op::Bra { kind, empty, .. }
                    if matches!(
                        kind,
                        BraKind::Bra
                            | BraKind::BraPos
                            | BraKind::Capture(_)
                            | BraKind::CapturePos(_)
                            | BraKind::Assert
                            | BraKind::AssertNa
                            | BraKind::Once
                            | BraKind::ScriptRun
                    ) && !(*empty && matches!(kind, BraKind::Bra | BraKind::BraPos)) =>
                {
                    let assert = matches!(kind, BraKind::Assert | BraKind::AssertNa);
                    let (d, dflags) = self.find_firstassertedcu(scode, inassert + u32::from(assert));
                    if dflags >= REQ_NONE {
                        return (0, REQ_NONE);
                    }
                    if cflags >= REQ_NONE {
                        c = d;
                        cflags = dflags;
                    } else if c != d || cflags != dflags {
                        return (0, REQ_NONE);
                    }
                }
                Op::Item(Lit::Char(ch)) | Op::Rep { lit: Lit::Char(ch), min: 1.., .. } => {
                    if inassert == 0 {
                        return (0, REQ_NONE);
                    }
                    let u = self.first_unit(*ch);
                    if cflags >= REQ_NONE {
                        c = u;
                        cflags = 0;
                    } else if c != u {
                        return (0, REQ_NONE);
                    }
                }
                Op::Item(Lit::CharI(ch)) | Op::Rep { lit: Lit::CharI(ch), min: 1.., .. } => {
                    if inassert == 0 {
                        return (0, REQ_NONE);
                    }
                    let u = self.first_unit(*ch);
                    if u >= 0x80 {
                        return (0, REQ_NONE);
                    }
                    if cflags >= REQ_NONE {
                        c = u;
                        cflags = REQ_CASELESS;
                    } else if c != u {
                        return (0, REQ_NONE);
                    }
                }
                _ => return (0, REQ_NONE),
            }
            pc = self.link(pc);
            if !self.is_alt(pc) {
                return (c, cflags);
            }
        }
    }

    fn set_bit(&mut self, c: u32) {
        self.bits[(c / 8) as usize] |= 1 << (c % 8);
    }

    /// `set_table_bit()` for a character.
    fn set_table_bit(&mut self, c: u32, caseless: bool) {
        let first = self.first_unit(c);
        self.set_bit(first);
        if caseless {
            if self.utf || self.ucp {
                let o = unicode::other_case(c);
                if self.utf {
                    let f = self.first_unit(o);
                    self.set_bit(f);
                } else if o < 256 {
                    self.set_bit(o);
                }
            } else if c < 256 {
                self.set_bit(unicode::fcc(c));
            }
        }
    }

    fn table_limit(&self) -> usize {
        if self.utf { 16 } else { 32 }
    }

    /// `set_type_bits()`.
    fn set_type_bits(&mut self, cbit: usize) {
        let limit = self.table_limit();
        for c in 0..limit {
            self.bits[c] |= CBITS[cbit + c];
        }
        if limit == 32 {
            return;
        }
        for c in 128u32..256 {
            if CBITS[(c / 8) as usize] & (1 << (c & 7)) != 0 {
                let f = self.first_unit(c);
                self.set_bit(f);
            }
        }
    }

    /// `set_nottype_bits()`.
    fn set_nottype_bits(&mut self, cbit: usize) {
        let limit = self.table_limit();
        for c in 0..limit {
            self.bits[c] |= !CBITS[cbit + c];
        }
        if limit != 32 {
            for c in 24..32 {
                self.bits[c] = 0xff;
            }
        }
    }

    fn set_hspace_bits(&mut self) {
        self.set_bit(0x09);
        self.set_bit(0x20);
        if self.utf {
            for b in [0xc2, 0xe1, 0xe2, 0xe3] {
                self.set_bit(b);
            }
        } else {
            self.set_bit(0xa0);
        }
    }

    fn set_vspace_bits(&mut self) {
        for b in [0x0a, 0x0b, 0x0c, 0x0d] {
            self.set_bit(b);
        }
        if self.utf {
            self.set_bit(0xc2);
            self.set_bit(0xe2);
        } else {
            self.set_bit(0x85);
        }
    }

    /// The bits of a single-character type item (`OP_DIGIT` ...); `None` for
    /// types that give up (`SSB_FAIL`).
    fn type_bits(&mut self, lit: &Lit) -> bool {
        match lit {
            Lit::Digit(neg) => {
                if *neg {
                    self.set_nottype_bits(CBIT_DIGIT)
                } else {
                    self.set_type_bits(CBIT_DIGIT)
                }
            }
            Lit::Space(neg) => {
                if *neg {
                    self.set_nottype_bits(CBIT_SPACE)
                } else {
                    self.set_type_bits(CBIT_SPACE)
                }
            }
            Lit::Word(neg) => {
                if *neg {
                    self.set_nottype_bits(CBIT_WORD)
                } else {
                    self.set_type_bits(CBIT_WORD)
                }
            }
            Lit::HSpace(false) => self.set_hspace_bits(),
            Lit::VSpace(false) | Lit::AnyNl => self.set_vspace_bits(),
            _ => return false,
        }
        true
    }

    /// The class bit map handling of `set_start_bits()` (`HANDLE_CLASSMAP`).
    fn class_map_bits(&mut self, map: &[u8; 32]) {
        if self.utf {
            for (bit, m) in self.bits.iter_mut().zip(&map[..16]) {
                *bit |= m;
            }
            let mut c = 128u32;
            while c < 256 {
                if map[(c / 8) as usize] & (1 << (c & 7)) != 0 {
                    let d = (c >> 6) | 0xc0;
                    self.set_bit(d);
                    c = (c & 0xc0) + 0x40 - 1;
                }
                c += 1;
            }
        } else {
            for (bit, m) in self.bits.iter_mut().zip(map) {
                *bit |= m;
            }
        }
    }

    /// The bits for a class item; `false` gives up.
    fn class_bits(&mut self, class: &Class) -> bool {
        match class {
            Class::X { negated, has_prop, map, items } => {
                if *has_prop || (map.is_none() && *negated) {
                    return false;
                }
                if self.utf && !negated {
                    for item in items {
                        if let XItem::Range(a, b) = *item {
                            let (fa, fb) = (self.first_unit(a), self.first_unit(b));
                            for x in fa..=fb {
                                self.set_bit(x);
                            }
                        }
                    }
                } else if self.utf {
                    // A negated XCLASS with a map: as OP_NCLASS.
                    self.bits[24] |= 0xf0;
                    for b in &mut self.bits[25..32] {
                        *b = 0xff;
                    }
                }
                if let Some(m) = map {
                    self.class_map_bits(m);
                }
                true
            }
            Class::NMap(map) => {
                if self.utf {
                    self.bits[24] |= 0xf0;
                    for b in &mut self.bits[25..32] {
                        *b = 0xff;
                    }
                }
                self.class_map_bits(map);
                true
            }
            Class::Map(map) => {
                self.class_map_bits(map);
                true
            }
        }
    }

    /// `set_start_bits()`.
    fn set_start_bits(&mut self, mut pc: usize, depth: &mut u32) -> i32 {
        let mut yield_ = SSB_DONE;
        *depth += 1;
        if *depth > 1000 {
            return SSB_TOODEEP;
        }
        let code = self.code;
        loop {
            let mut tcode = pc + 1;
            let mut try_next = true;
            while try_next {
                match &code[tcode] {
                    Op::Accept
                    | Op::AssertAccept
                    | Op::Close(_)
                    | Op::Commit(_)
                    | Op::End
                    | Op::Fail
                    | Op::Mark(_)
                    | Op::Prune(_)
                    | Op::Recurse(_)
                    | Op::Ref { .. }
                    | Op::Reverse(_)
                    | Op::VReverse { .. }
                    | Op::SetSom
                    | Op::Skip
                    | Op::SkipArg(_)
                    | Op::Then(_)
                    | Op::CondRef(_)
                    | Op::CondRecurse(_)
                    | Op::CondFalse
                    | Op::CondTrue => return SSB_FAIL,
                    Op::Bra { kind: BraKind::Cond, .. } => return SSB_FAIL,
                    Op::Assert(a) => match a {
                        Assert::Circ => tcode += 1,
                        Assert::WordBoundary { .. } => tcode += 1,
                        _ => return SSB_FAIL,
                    },
                    Op::Item(lit) => {
                        match lit {
                            Lit::Prop { neg: false, ptype, pdata } if *ptype == PT_CLIST => {
                                for &c in unicode::caseless_set(*pdata) {
                                    let f = self.first_unit(c);
                                    if f > 0xff {
                                        self.set_bit(0xff);
                                    } else {
                                        self.set_bit(f);
                                    }
                                }
                            }
                            Lit::Char(c) => self.set_table_bit(*c, false),
                            Lit::CharI(c) => self.set_table_bit(*c, true),
                            Lit::Class(class) => {
                                let class = class.clone();
                                if !self.class_bits(&class) {
                                    return SSB_FAIL;
                                }
                            }
                            other => {
                                if !self.type_bits(other) {
                                    return SSB_FAIL;
                                }
                            }
                        }
                        try_next = false;
                    }
                    Op::Rep { lit, min, .. } => {
                        if *min >= 1 {
                            // CHAR/PLUS/EXACT and TYPEPLUS/TYPEEXACT: as one item.
                            match lit {
                                Lit::Prop { neg: false, ptype, pdata } if *ptype == PT_CLIST => {
                                    for &c in unicode::caseless_set(*pdata) {
                                        let f = self.first_unit(c);
                                        self.set_bit(f.min(0xff));
                                    }
                                }
                                Lit::Char(c) => self.set_table_bit(*c, false),
                                Lit::CharI(c) => self.set_table_bit(*c, true),
                                Lit::Class(class) => {
                                    let class = class.clone();
                                    if !self.class_bits(&class) {
                                        return SSB_FAIL;
                                    }
                                }
                                other => {
                                    if !self.type_bits(other) {
                                        return SSB_FAIL;
                                    }
                                }
                            }
                            try_next = false;
                        } else {
                            match lit {
                                Lit::Char(c) => self.set_table_bit(*c, false),
                                Lit::CharI(c) => self.set_table_bit(*c, true),
                                Lit::Class(class) => {
                                    let class = class.clone();
                                    if !self.class_bits(&class) {
                                        return SSB_FAIL;
                                    }
                                }
                                Lit::Not(_) | Lit::NotI(_) => return SSB_FAIL,
                                other => {
                                    if !self.type_bits(other) {
                                        return SSB_FAIL;
                                    }
                                }
                            }
                            tcode += 1;
                        }
                    }
                    Op::Bra { kind: BraKind::Assert | BraKind::AssertNa, .. } => {
                        let mut ncode = self.ket(tcode) + 1;
                        loop {
                            match &code[ncode] {
                                Op::Bra {
                                    kind:
                                        BraKind::Assert
                                        | BraKind::AssertNot
                                        | BraKind::AssertBack
                                        | BraKind::AssertBackNot
                                        | BraKind::AssertNa
                                        | BraKind::AssertBackNa,
                                    ..
                                } => ncode = self.ket(ncode) + 1,
                                Op::Assert(Assert::WordBoundary { .. }) => ncode += 1,
                                _ => break,
                            }
                        }
                        let mandatory = match &code[ncode] {
                            Op::Item(Lit::Prop { neg: false, ptype, .. }) => *ptype == PT_CLIST,
                            Op::Item(
                                Lit::AnyNl
                                | Lit::Char(_)
                                | Lit::CharI(_)
                                | Lit::HSpace(false)
                                | Lit::VSpace(false)
                                | Lit::Digit(_)
                                | Lit::Word(_)
                                | Lit::Space(_),
                            ) => true,
                            // EXACT/PLUS forms of a character.
                            Op::Rep { lit: Lit::Char(_) | Lit::CharI(_), min: 1.., .. } => true,
                            _ => false,
                        };
                        if mandatory {
                            tcode = ncode;
                            continue;
                        }
                        if let Some(rc) = self.group_bits(&mut tcode, &mut try_next, depth) {
                            return rc;
                        }
                    }
                    Op::Bra { kind, .. } => match kind {
                        BraKind::Bra
                        | BraKind::Capture(_)
                        | BraKind::BraPos
                        | BraKind::CapturePos(_)
                        | BraKind::Once
                        | BraKind::ScriptRun
                        | BraKind::Root => {
                            if let Some(rc) = self.group_bits(&mut tcode, &mut try_next, depth) {
                                return rc;
                            }
                        }
                        _ => tcode = self.ket(tcode) + 1,
                    },
                    Op::Alt { .. } => {
                        yield_ = SSB_CONTINUE;
                        try_next = false;
                    }
                    Op::Ket { .. } => return SSB_CONTINUE,
                    Op::BraZero | Op::BraMinZero | Op::BraPosZero => {
                        tcode += 1;
                        let rc = self.set_start_bits(tcode, depth);
                        if rc == SSB_FAIL || rc == SSB_TOODEEP {
                            return rc;
                        }
                        tcode = self.ket(tcode) + 1;
                    }
                    Op::SkipZero => {
                        tcode += 1;
                        tcode = self.ket(tcode) + 1;
                    }
                }
            }
            pc = self.link(pc);
            if !self.is_alt(pc) {
                return yield_;
            }
        }
    }

    /// The bracket case of `set_start_bits()`: `Some` to return.
    fn group_bits(&mut self, tcode: &mut usize, try_next: &mut bool, depth: &mut u32) -> Option<i32> {
        let rc = self.set_start_bits(*tcode, depth);
        if rc == SSB_DONE {
            *try_next = false;
            None
        } else if rc == SSB_CONTINUE {
            *tcode = self.ket(*tcode) + 1;
            None
        } else {
            Some(rc)
        }
    }

    /// One code unit, or two that are the cases of one character: the bit
    /// map as a first code unit (the end of `pcre2_study()`).
    fn single_start_unit(&self) -> Option<(u32, Option<u32>)> {
        let mut a: Option<u32> = None;
        let mut b: Option<u32> = None;
        for (i, &x) in self.bits.iter().enumerate() {
            if x == 0 {
                continue;
            }
            if x & x.wrapping_neg() != x {
                return None;
            }
            let c = (i as u32) * 8 + x.trailing_zeros();
            if self.utf && c > 127 {
                return None;
            }
            match (a, b) {
                (None, _) => a = Some(c),
                (Some(av), None) => {
                    let mut d = unicode::fcc(c);
                    if self.utf || self.ucp {
                        if unicode::caseset(c) != 0 {
                            return None;
                        }
                        if c > 127 {
                            d = unicode::other_case(c);
                        }
                    }
                    if d != av {
                        return None;
                    }
                    b = Some(c);
                }
                _ => return None,
            }
        }
        a.map(|a| (a, b))
    }

    /// The group bracket that a back reference or recursion to group `n`
    /// refers to (`PRIV(find_bracket)`), searching from `from`.
    fn find_bracket(&self, from: usize, n: u32) -> Option<usize> {
        (from..self.code.len()).find(
            |&pc| matches!(self.code[pc], Op::Bra { kind: BraKind::Capture(k) | BraKind::CapturePos(k), .. } if k == n),
        )
    }

    /// `find_minlength()`: the minimum length of the group at `pc`, or a
    /// negative value when it cannot be found.
    fn find_minlength(&self, start: usize, recurses: &mut Vec<usize>, count: &mut u32, cache: &mut [i32]) -> i32 {
        let code = self.code;
        let mut length: i32 = -1;
        let mut branchlength: i32 = 0;
        let mut prev_cap_recno: i64 = -1;
        let mut prev_cap_d: i32 = 0;
        let mut prev_recurse_recno: i64 = -1;
        let mut prev_recurse_d: i32 = 0;
        let mut once_fudge = 0usize;
        let mut had_recurse = false;
        let dupcapused = self.prog.dupcap_used;
        let mut nextbranch = self.link(start);
        if let Op::Bra { empty: true, .. } = code[start] {
            return 0;
        }
        let mut cc = start + 1;
        *count += 1;
        if *count > 1000 {
            return -1;
        }
        loop {
            if branchlength >= 65535 {
                branchlength = 65535;
                cc = nextbranch;
            }
            match &code[cc] {
                Op::Bra { kind: BraKind::Cond, link, .. } => {
                    if !self.is_alt(*link) {
                        cc = link + 1;
                    } else {
                        let d = self.find_minlength(cc, recurses, count, cache);
                        if d < 0 {
                            return d;
                        }
                        branchlength += d;
                        cc = self.ket(cc) + 1;
                    }
                }
                Op::Bra { kind: BraKind::Bra, empty: false, .. }
                    if matches!(code[cc + 1], Op::Recurse(_)) && matches!(code[cc + 2], Op::Ket { .. }) =>
                {
                    once_fudge = 1;
                    cc += 1;
                }
                Op::Bra { kind: BraKind::Bra | BraKind::Once | BraKind::ScriptRun | BraKind::BraPos, .. } => {
                    let d = self.find_minlength(cc, recurses, count, cache);
                    if d < 0 {
                        return d;
                    }
                    branchlength += d;
                    cc = self.ket(cc) + 1;
                }
                Op::Bra { kind: BraKind::Capture(n) | BraKind::CapturePos(n), .. } => {
                    let recno = i64::from(*n);
                    if dupcapused || recno != prev_cap_recno {
                        prev_cap_recno = recno;
                        prev_cap_d = self.find_minlength(cc, recurses, count, cache);
                        if prev_cap_d < 0 {
                            return prev_cap_d;
                        }
                    }
                    branchlength += prev_cap_d;
                    cc = self.ket(cc) + 1;
                }
                Op::Accept | Op::AssertAccept => return -1,
                Op::Alt { .. } | Op::Ket { .. } | Op::End => {
                    if length < 0 || (!had_recurse && branchlength < length) {
                        length = branchlength;
                    }
                    if !matches!(code[cc], Op::Alt { .. }) || length == 0 {
                        return length;
                    }
                    nextbranch = self.link(cc);
                    cc += 1;
                    branchlength = 0;
                    had_recurse = false;
                }
                Op::Bra { .. } => {
                    // Assertions.
                    cc = self.ket(cc) + 1;
                }
                Op::Reverse(_)
                | Op::VReverse { .. }
                | Op::CondRef(_)
                | Op::CondRecurse(_)
                | Op::CondFalse
                | Op::CondTrue
                | Op::Assert(_) => cc += 1,
                Op::BraZero | Op::BraMinZero | Op::BraPosZero | Op::SkipZero => {
                    cc += 1;
                    cc = self.ket(cc) + 1;
                }
                Op::Item(lit) => {
                    if self.utf && matches!(lit, Lit::AnyByte) {
                        return -1;
                    }
                    branchlength += 1;
                    cc += 1;
                }
                Op::Rep { lit, min, max, .. } => {
                    // {1,n} keeps the item itself (then UPTO): a \C in UTF
                    // mode gives up there.
                    if self.utf && matches!(lit, Lit::AnyByte) && *min == 1 && *max != UNLIMITED && *max > 1 {
                        return -1;
                    }
                    branchlength = branchlength.saturating_add((*min).min(65535) as i32);
                    cc += 1;
                }
                Op::Ref { groups, min, .. } => {
                    let d = self.ref_minlength(cc, groups, recurses, count, cache, &mut had_recurse);
                    let d = match d {
                        Ok(d) => d,
                        Err(e) => return e,
                    };
                    let min = *min as i32;
                    if (d > 0 && i32::MAX / d < min) || 65535 - branchlength < min.saturating_mul(d) {
                        branchlength = 65535;
                    } else {
                        branchlength += min * d;
                    }
                    cc += 1;
                }
                Op::Recurse(g) => {
                    let cs = self.prog.group_start[*g as usize];
                    let recno = if *g == 0 { -2 } else { i64::from(*g) };
                    if recno == prev_recurse_recno {
                        branchlength += prev_recurse_d;
                    } else {
                        let ce = self.ket(cs);
                        if cc > cs && cc < ce || recurses.contains(&cs) {
                            had_recurse = true;
                        } else {
                            recurses.push(cs);
                            let d = self.find_minlength(cs, recurses, count, cache);
                            recurses.pop();
                            if d < 0 {
                                return d;
                            }
                            prev_recurse_d = d;
                            prev_recurse_recno = recno;
                            branchlength += d;
                        }
                    }
                    cc += 1 + once_fudge;
                    once_fudge = 0;
                }
                Op::Mark(_)
                | Op::Commit(_)
                | Op::Prune(_)
                | Op::Skip
                | Op::SkipArg(_)
                | Op::Then(_)
                | Op::Close(_)
                | Op::Fail
                | Op::SetSom => cc += 1,
            }
        }
    }

    /// The minimum length of the group(s) a back reference refers to.
    #[allow(clippy::too_many_arguments)]
    fn ref_minlength(
        &self,
        cc: usize,
        groups: &[u32],
        recurses: &mut Vec<usize>,
        count: &mut u32,
        cache: &mut [i32],
        had_recurse: &mut bool,
    ) -> Result<i32, i32> {
        let unset_ok = self.options & opt::MATCH_UNSET_BACKREF != 0;
        let dupcapused = self.prog.dupcap_used;
        if groups.len() > 1 {
            if dupcapused || unset_ok {
                return Ok(0);
            }
            let mut d = i32::MAX;
            for &recno in groups {
                let dd = self.group_minlength(cc, recno, recurses, count, cache, had_recurse)?;
                if dd < d {
                    d = dd;
                }
                if d <= 0 {
                    break;
                }
            }
            return Ok(d);
        }
        let recno = groups[0];
        if recno <= cache[0] as u32 && cache[recno as usize] >= 0 {
            return Ok(cache[recno as usize]);
        }
        if unset_ok {
            Self::cache_set(cache, recno, 0);
            return Ok(0);
        }
        self.group_minlength(cc, recno, recurses, count, cache, had_recurse)
    }

    fn cache_set(cache: &mut [i32], recno: u32, d: i32) {
        cache[recno as usize] = d;
        for i in (cache[0] + 1) as u32..recno {
            cache[i as usize] = -1;
        }
        cache[0] = recno as i32;
    }

    /// The (cached) minimum length of group `recno` for a back reference at
    /// `cc`.
    #[allow(clippy::too_many_arguments)]
    fn group_minlength(
        &self,
        cc: usize,
        recno: u32,
        recurses: &mut Vec<usize>,
        count: &mut u32,
        cache: &mut [i32],
        had_recurse: &mut bool,
    ) -> Result<i32, i32> {
        if recno <= cache[0] as u32 && cache[recno as usize] >= 0 {
            return Ok(cache[recno as usize]);
        }
        let mut d = 0;
        let Some(cs) = self.find_bracket(0, recno) else { return Err(-2) };
        let ce = self.ket(cs);
        if !self.prog.dupcap_used || self.find_bracket(ce, recno).is_none() {
            if cc > cs && cc < ce || recurses.contains(&cs) {
                *had_recurse = true;
            } else {
                recurses.push(cs);
                d = self.find_minlength(cs, recurses, count, cache);
                recurses.pop();
                if d < 0 {
                    return Err(d);
                }
            }
        }
        Self::cache_set(cache, recno, d);
        Ok(d)
    }
}
