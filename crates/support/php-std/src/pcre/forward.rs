//! How the PCRE2 JIT finds the next position where a match can start
//! (`fast_forward_first_n_chars()` in `pcre2_jit_compile.c`).
//!
//! Before it falls back to the first code unit, the start of a line or the
//! start bits (which `pcre2_match()` uses too), the JIT works out which code
//! units each of the first twelve positions of a match can hold
//! (`scan_prefix()`) and searches for a pair of positions that rule out the
//! most start positions (`fast_forward_char_pair_simd()`), a long run of
//! known positions with a skip table, or a single position
//! (`fast_forward_first_char2()`). The positions it skips can never match,
//! so only the match steps spent on them differ: a small
//! `(*LIMIT_MATCH=n)` runs out at a position the JIT never tries.

use super::compile::{Class, Lit};
use super::program::{BraKind, KetKind, Op, UNLIMITED};
use super::unicode::{self, CBIT_DIGIT, CBIT_SPACE, CBIT_WORD, CBITS};

/// `MAX_N_CHARS`: the positions `scan_prefix()` looks at.
const MAX_N_CHARS: usize = 12;
/// `MAX_DIFF_CHARS`: the code units a position may list before it counts
/// as any code unit.
const MAX_DIFF_CHARS: usize = 5;
/// `max_fast_forward_char_pair_offset()` for 8-bit code units (ARM64 NEON
/// and x86 SSE2).
const MAX_PAIR_OFFSET: usize = 15;
/// A position that can hold any code unit.
const ANY: u8 = 255;

/// Where the JIT looks for the next start position.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Forward {
    /// The first position `p` where the code unit at `p + offs1` is one of
    /// `chars1` and the one at `p + offs2` one of `chars2`
    /// (`fast_forward_char_pair_simd()`, `offs1 > offs2`).
    Pair { offs1: usize, chars1: [u8; 2], offs2: usize, chars2: [u8; 2] },
    /// The first position `p` where the code unit at `p + offset` is one of
    /// `chars` (`fast_forward_first_char2()`).
    Char { offset: usize, chars: [u8; 2] },
    /// A run of known positions ending at `right`, searched with a skip
    /// table indexed by the code unit at `p + right`, then checked at
    /// `offset`; a match needs `len` code units.
    Range { len: usize, right: usize, table: Box<[u8; 256]>, offset: Option<(usize, [u8; 2])> },
}

impl Forward {
    /// The plan `fast_forward_first_n_chars()` compiles for `code`, or
    /// `None` when it falls back to the other searches.
    pub fn plan(code: &[Op], utf: bool, ucp: bool) -> Option<Forward> {
        let mut scan = Scan { code, utf, ucp, rec_count: 10_000, chars: [Position::default(); MAX_N_CHARS] };
        let max = scan.scan(Cursor { pc: 0, step: 0 }, 0, MAX_N_CHARS);
        if max < 1 {
            return None;
        }
        let mut chars = scan.chars;
        // Convert `last_count` to a priority.
        for p in chars.iter_mut().take(max) {
            match p.count {
                0 => {
                    p.count = ANY;
                    p.last_count = 0;
                }
                1 => {
                    p.last_count = if p.last_count == 1 { 7 } else { 5 };
                    p.chars[1] = p.chars[0];
                }
                2 => {
                    let pow2 = (p.chars[0] ^ p.chars[1]).is_power_of_two();
                    p.last_count = match (pow2, p.last_count == 2) {
                        (true, true) => 6,
                        (true, false) => 4,
                        (false, true) => 3,
                        (false, false) => 2,
                    };
                }
                _ => p.last_count = u8::from(p.count != ANY),
            }
        }
        if let Some(pair) = pair(&chars, max) {
            return Some(pair);
        }
        // The longest run of at least four positions that are not "any".
        let mut in_range = false;
        let mut from = 0;
        let mut range_len = 3;
        let mut range_right: Option<usize> = None;
        for i in 0..=max {
            if in_range && i - from > range_len && chars[i - 1].count < ANY {
                range_len = i - from;
                range_right = Some(i - 1);
            }
            if i < max && chars[i].count < ANY {
                if !in_range {
                    in_range = true;
                    from = i;
                }
            } else {
                in_range = false;
            }
        }
        let mut offset: Option<usize> = None;
        for i in 0..max {
            if range_right == Some(i) {
                continue;
            }
            match offset {
                None if chars[i].last_count >= 2 => offset = Some(i),
                Some(o) if chars[o].last_count < chars[i].last_count => offset = Some(i),
                _ => {}
            }
        }
        let offset = offset.map(|o| (o, [chars[o].chars[0], chars[o].chars[1]]));
        let Some(right) = range_right else {
            return offset.map(|(offset, chars)| Forward::Char { offset, chars });
        };
        let mut table = Box::new([range_len as u8; 256]);
        for i in 0..range_len {
            let p = &chars[right - i];
            for &c in &p.chars[..usize::from(p.count)] {
                let t = &mut table[usize::from(c)];
                if *t > i as u8 {
                    *t = i as u8;
                }
            }
        }
        Some(Forward::Range { len: max, right, table, offset })
    }

    /// The next start position at or after `from`, or `None` when the match
    /// fails.
    pub fn next(&self, s: &[u8], from: usize, utf: bool) -> Option<usize> {
        let end = s.len();
        let continuation = |c: u8| c & 0xc0 == 0x80;
        match self {
            Forward::Pair { offs1, chars1, offs2, chars2 } => {
                let diff = offs1 - offs2;
                let mut q = from + offs1;
                while q < end {
                    if chars1.contains(&s[q]) && chars2.contains(&s[q - diff]) && !(utf && continuation(s[q - offs1])) {
                        return Some(q - offs1);
                    }
                    q += 1;
                }
                None
            }
            Forward::Char { offset, chars } => {
                let mut q = from + offset;
                while q < end {
                    if chars.contains(&s[q]) && !(utf && *offset > 0 && continuation(s[q - offset])) {
                        return Some(q - offset);
                    }
                    q += 1;
                }
                None
            }
            Forward::Range { len, right, table, offset } => {
                let last = end.checked_sub(*len)?;
                let mut p = from;
                loop {
                    if p > last {
                        return None;
                    }
                    let skip = usize::from(table[usize::from(s[p + right])]);
                    if skip != 0 {
                        p += skip;
                        continue;
                    }
                    if let Some((o, chars)) = offset
                        && !chars.contains(&s[p + o])
                    {
                        p += 1;
                        continue;
                    }
                    if utf && offset.is_none_or(|(o, _)| o != 0) && continuation(s[p]) {
                        p += 1;
                        continue;
                    }
                    return Some(p);
                }
            }
        }
    }
}

/// `check_fast_forward_char_pair_simd()`: the two positions with the
/// highest priorities and no code unit in common.
fn pair(chars: &[Position; MAX_N_CHARS], max: usize) -> Option<Forward> {
    let (mut max_i, mut max_j, mut max_pri) = (0, 0, 0u32);
    for i in (1..max).rev() {
        let a = &chars[i];
        if a.last_count <= 2 {
            continue;
        }
        for (j, b) in chars.iter().enumerate().take(i).skip(i.saturating_sub(MAX_PAIR_OFFSET)) {
            if b.last_count > 2 && u32::from(a.last_count) + u32::from(b.last_count) >= max_pri {
                let (a1, a2, b1, b2) = (a.chars[0], a.chars[1], b.chars[0], b.chars[1]);
                if a1 != b1 && a1 != b2 && a2 != b1 && a2 != b2 {
                    max_pri = u32::from(a.last_count) + u32::from(b.last_count);
                    max_i = i;
                    max_j = j;
                }
            }
        }
    }
    (max_pri != 0).then(|| Forward::Pair {
        offs1: max_i,
        chars1: [chars[max_i].chars[0], chars[max_i].chars[1]],
        offs2: max_j,
        chars2: [chars[max_j].chars[0], chars[max_j].chars[1]],
    })
}

/// `fast_forward_char_data`: the code units one position can hold.
#[derive(Debug, Clone, Copy, Default)]
struct Position {
    /// Code units listed, [`ANY`] for any.
    count: u8,
    /// Code units that end a character (later the position's priority).
    last_count: u8,
    chars: [u8; MAX_DIFF_CHARS],
}

impl Position {
    /// `add_prefix_char()`.
    fn add(&mut self, c: u8, last: bool) {
        let count = usize::from(self.count);
        if self.count == ANY {
            return;
        }
        if count == 0 {
            self.count = 1;
            self.chars[0] = c;
            if last {
                self.last_count = 1;
            }
            return;
        }
        if self.chars[..count].contains(&c) {
            return;
        }
        if count >= MAX_DIFF_CHARS {
            self.count = ANY;
            return;
        }
        self.chars[count] = c;
        self.count += 1;
        if last {
            self.last_count += 1;
        }
    }
}

/// A point in the code: the op at `pc` and, for a repeat that PCRE2 compiles
/// as several opcodes, which of them.
#[derive(Debug, Clone, Copy)]
struct Cursor {
    pc: usize,
    step: usize,
}

/// One PCRE2 opcode as `scan_prefix()` sees it.
enum Unit<'a> {
    /// `OP_CHAR`/`OP_CHARI`.
    Char { c: u32, caseless: bool },
    /// `OP_PLUS` and its lazy, possessive and caseless forms.
    Plus { c: u32, caseless: bool },
    /// `OP_EXACT`/`OP_EXACTI`.
    Exact { c: u32, caseless: bool, n: u32 },
    /// `OP_QUERY` and its forms.
    Query { c: u32, caseless: bool },
    /// `OP_NOT`/`OP_NOTI`.
    Not,
    /// `OP_NOTEXACT`/`OP_NOTEXACTI`.
    NotExact { n: u32 },
    /// A character type.
    Type(&'a Lit),
    /// `OP_TYPEEXACT`, before its type.
    TypeExact { n: u32 },
    /// `OP_CLASS`/`OP_NCLASS`/`OP_XCLASS` and its repeat.
    Class { class: &'a Class, repeat: ClassRepeat },
    /// An opcode `scan_prefix()` stops at.
    Stop,
}

#[derive(Clone, Copy)]
enum ClassRepeat {
    None,
    /// `OP_CRSTAR`.
    Star,
    /// `OP_CRPLUS`.
    Plus,
    /// `OP_CRQUERY`.
    Query,
    /// `OP_CRRANGE`.
    Range(u32, u32),
}

/// The opcodes `compile_branch()` writes for `lit{min,max}`.
fn units(lit: &Lit, min: u32, max: u32) -> Vec<Unit<'_>> {
    match lit {
        Lit::Class(class) => {
            let repeat = match (min, max) {
                (1, 1) => ClassRepeat::None,
                (0, UNLIMITED) => ClassRepeat::Star,
                (1, UNLIMITED) => ClassRepeat::Plus,
                (0, 1) => ClassRepeat::Query,
                _ => ClassRepeat::Range(min, if max == UNLIMITED { 0 } else { max }),
            };
            let mut v = vec![Unit::Class { class, repeat }];
            // The repeat after an extended class, or `OP_CRPLUS`, is an
            // opcode of its own.
            let x = matches!(**class, Class::X { .. });
            if (x && !matches!(repeat, ClassRepeat::None)) || (!x && matches!(repeat, ClassRepeat::Plus)) {
                v.push(Unit::Stop);
            }
            v
        }
        Lit::Char(c) | Lit::CharI(c) => {
            let (c, caseless) = (*c, matches!(lit, Lit::CharI(_)));
            match (min, max) {
                (0, 0) => vec![],
                (0, 1) => vec![Unit::Query { c, caseless }],
                (0, _) => vec![Unit::Stop],
                (1, UNLIMITED) => vec![Unit::Plus { c, caseless }],
                (1, 1) => vec![Unit::Char { c, caseless }],
                (1, _) => vec![Unit::Char { c, caseless }, Unit::Stop],
                (n, m) if m == n => vec![Unit::Exact { c, caseless, n }],
                (n, m) if m != UNLIMITED && m - n == 1 => {
                    vec![Unit::Exact { c, caseless, n }, Unit::Query { c, caseless }]
                }
                (n, _) => vec![Unit::Exact { c, caseless, n }, Unit::Stop],
            }
        }
        Lit::Not(_) | Lit::NotI(_) => match (min, max) {
            (0, 0) => vec![],
            (0, _) | (1, UNLIMITED) => vec![Unit::Stop],
            (1, 1) => vec![Unit::Not],
            (1, _) => vec![Unit::Not, Unit::Stop],
            (n, m) if m == n => vec![Unit::NotExact { n }],
            (n, _) => vec![Unit::NotExact { n }, Unit::Stop],
        },
        _ => match (min, max) {
            (0, 0) => vec![],
            (0, _) | (1, UNLIMITED) => vec![Unit::Stop],
            (1, 1) => vec![Unit::Type(lit)],
            (1, _) => vec![Unit::Type(lit), Unit::Stop],
            (n, m) if m == n => vec![Unit::TypeExact { n }, Unit::Type(lit)],
            (n, _) => vec![Unit::TypeExact { n }, Unit::Type(lit), Unit::Stop],
        },
    }
}

/// Whether the code units below 128 decide a class (`is_char7_bitset()`).
fn char7(bits: &[u8]) -> bool {
    bits[16..32].iter().all(|&b| b == 0)
}

struct Scan<'a> {
    code: &'a [Op],
    utf: bool,
    ucp: bool,
    rec_count: u32,
    chars: [Position; MAX_N_CHARS],
}

impl Scan<'_> {
    fn link(&self, pc: usize) -> usize {
        match self.code[pc] {
            Op::Bra { link, .. } | Op::Alt { link } => link,
            _ => pc,
        }
    }

    /// The opcode at `cur` and the cursor after it.
    fn unit(&self, cur: Cursor) -> (Option<Unit<'_>>, Cursor) {
        let next_op = Cursor { pc: cur.pc + 1, step: 0 };
        let (lit, min, max) = match &self.code[cur.pc] {
            Op::Item(lit) => (lit, 1, 1),
            Op::Rep { lit, min, max, .. } => (lit, *min, *max),
            _ => return (None, next_op),
        };
        let mut all = units(lit, min, max);
        if cur.step >= all.len() {
            return (None, next_op);
        }
        let next = if cur.step + 1 < all.len() { Cursor { pc: cur.pc, step: cur.step + 1 } } else { next_op };
        (Some(all.swap_remove(cur.step)), next)
    }

    /// Skips a repeat PCRE2 leaves out (`{0}`).
    fn normalize(&self, mut cur: Cursor) -> Cursor {
        while let Op::Item(_) | Op::Rep { .. } = &self.code[cur.pc] {
            let (lit, min, max) = match &self.code[cur.pc] {
                Op::Item(lit) => (lit, 1, 1),
                Op::Rep { lit, min, max, .. } => (lit, *min, *max),
                _ => unreachable!(),
            };
            if cur.step < units(lit, min, max).len() {
                break;
            }
            cur = Cursor { pc: cur.pc + 1, step: 0 };
        }
        cur
    }

    /// `scan_prefix()`: fills the positions from `at` along the code from
    /// `cur`, at most `max_chars` of them, and returns how many it filled.
    fn scan(&mut self, cur: Cursor, mut at: usize, mut max_chars: usize) -> usize {
        let mut cur = self.normalize(cur);
        let mut consumed = 0;
        let mut repeat: u32 = 1;
        loop {
            if self.rec_count == 0 {
                return 0;
            }
            self.rec_count -= 1;
            let (c, caseless, last) = match &self.code[cur.pc] {
                Op::Item(_) | Op::Rep { .. } => {
                    let (unit, next) = self.unit(cur);
                    match unit {
                        Some(Unit::Char { c, caseless }) => {
                            cur = self.normalize(next);
                            (c, caseless, false)
                        }
                        Some(Unit::Plus { c, caseless }) => {
                            cur = self.normalize(next);
                            (c, caseless, true)
                        }
                        Some(Unit::Exact { c, caseless, n }) => {
                            repeat = n;
                            cur = self.normalize(next);
                            (c, caseless, false)
                        }
                        Some(Unit::Query { c, caseless }) => {
                            let after = self.normalize(next);
                            max_chars = self.scan(after, at, max_chars);
                            if max_chars == 0 {
                                return consumed;
                            }
                            cur = after;
                            (c, caseless, false)
                        }
                        Some(Unit::Not) => {
                            if self.utf {
                                return consumed;
                            }
                            cur = self.normalize(next);
                            if self.any(&mut at, &mut max_chars, &mut consumed, &mut repeat) {
                                return consumed;
                            }
                            continue;
                        }
                        Some(Unit::NotExact { n }) => {
                            if self.utf {
                                return consumed;
                            }
                            repeat = n;
                            cur = self.normalize(next);
                            if self.any(&mut at, &mut max_chars, &mut consumed, &mut repeat) {
                                return consumed;
                            }
                            continue;
                        }
                        Some(Unit::TypeExact { n }) => {
                            repeat = n;
                            cur = next;
                            continue;
                        }
                        Some(Unit::Type(lit)) => {
                            if !self.type_any(lit) {
                                return consumed;
                            }
                            cur = self.normalize(next);
                            if self.any(&mut at, &mut max_chars, &mut consumed, &mut repeat) {
                                return consumed;
                            }
                            continue;
                        }
                        Some(Unit::Class { class, repeat: rep }) => {
                            let bits = match class {
                                Class::Map(bits) => {
                                    if self.utf && !char7(bits) {
                                        return consumed;
                                    }
                                    *bits
                                }
                                Class::NMap(bits) => {
                                    if self.utf {
                                        return consumed;
                                    }
                                    *bits
                                }
                                Class::X { .. } => {
                                    if self.utf {
                                        return consumed;
                                    }
                                    cur = self.normalize(next);
                                    if self.any(&mut at, &mut max_chars, &mut consumed, &mut repeat) {
                                        return consumed;
                                    }
                                    continue;
                                }
                            };
                            let after = self.normalize(next);
                            match rep {
                                ClassRepeat::Star | ClassRepeat::Query => {
                                    max_chars = self.scan(after, at, max_chars);
                                    if max_chars == 0 {
                                        return consumed;
                                    }
                                }
                                ClassRepeat::Range(min, _) => {
                                    if min == 0 {
                                        return consumed;
                                    }
                                    repeat = min;
                                }
                                ClassRepeat::None | ClassRepeat::Plus => {}
                            }
                            loop {
                                let p = &mut self.chars[at];
                                if bits[31] & 0x80 != 0 {
                                    p.count = ANY;
                                } else if p.count != ANY {
                                    for c in 0..256usize {
                                        if p.count == ANY {
                                            break;
                                        }
                                        if bits[c / 8] & (1 << (c % 8)) != 0 {
                                            p.add(c as u8, true);
                                        }
                                    }
                                }
                                consumed += 1;
                                max_chars -= 1;
                                if max_chars == 0 {
                                    return consumed;
                                }
                                at += 1;
                                repeat -= 1;
                                if repeat == 0 {
                                    break;
                                }
                            }
                            match rep {
                                ClassRepeat::Star => return consumed,
                                ClassRepeat::Range(min, max) if min != max => return consumed,
                                _ => {}
                            }
                            cur = after;
                            repeat = 1;
                            continue;
                        }
                        Some(Unit::Stop) | None => return consumed,
                    }
                }
                Op::Assert(_) | Op::SetSom => {
                    cur = self.normalize(Cursor { pc: cur.pc + 1, step: 0 });
                    continue;
                }
                Op::Bra {
                    kind:
                        BraKind::Assert
                        | BraKind::AssertNot
                        | BraKind::AssertBack
                        | BraKind::AssertBackNot
                        | BraKind::AssertNa
                        | BraKind::AssertBackNa,
                    ..
                } => {
                    let mut pc = cur.pc;
                    loop {
                        pc = self.link(pc);
                        if !matches!(self.code[pc], Op::Alt { .. }) {
                            break;
                        }
                    }
                    cur = self.normalize(Cursor { pc: pc + 1, step: 0 });
                    continue;
                }
                Op::Ket { kind: KetKind::Ket, .. } => {
                    cur = self.normalize(Cursor { pc: cur.pc + 1, step: 0 });
                    continue;
                }
                Op::Alt { link } => {
                    cur = self.normalize(Cursor { pc: *link, step: 0 });
                    continue;
                }
                Op::Bra {
                    kind:
                        BraKind::Root
                        | BraKind::Bra
                        | BraKind::Capture(_)
                        | BraKind::Once
                        | BraKind::BraPos
                        | BraKind::CapturePos(_),
                    empty: false,
                    link,
                } => {
                    let mut alternative = *link;
                    while let Op::Alt { link } = self.code[alternative] {
                        let first = self.normalize(Cursor { pc: alternative + 1, step: 0 });
                        max_chars = self.scan(first, at, max_chars);
                        if max_chars == 0 {
                            return consumed;
                        }
                        alternative = link;
                    }
                    cur = self.normalize(Cursor { pc: cur.pc + 1, step: 0 });
                    continue;
                }
                _ => return consumed,
            };
            // A character: its code units, and those of its other case.
            let mut units = Vec::with_capacity(4);
            let mut other = Vec::with_capacity(4);
            if self.utf {
                unicode::push_utf8(&mut units, c);
            } else {
                units.push(c as u8);
            }
            let caseless = caseless && self.has_other_case(c);
            if caseless {
                if self.utf {
                    let o = if c > 127 { unicode::other_case(c) } else { unicode::fcc(c) };
                    unicode::push_utf8(&mut other, o);
                    if other.len() != units.len() {
                        return consumed;
                    }
                } else if self.ucp && c > 127 {
                    let o = unicode::other_case(c);
                    other.push(if o <= 0xff { o as u8 } else { c as u8 });
                } else {
                    other.push(unicode::fcc(c) as u8);
                }
            }
            loop {
                for (k, &unit) in units.iter().enumerate() {
                    let end = k + 1 == units.len();
                    consumed += 1;
                    let p = &mut self.chars[at];
                    p.add(unit, end);
                    if caseless {
                        p.add(other[k], end);
                    }
                    max_chars -= 1;
                    if max_chars == 0 {
                        return consumed;
                    }
                    at += 1;
                }
                repeat -= 1;
                if repeat == 0 {
                    break;
                }
            }
            repeat = 1;
            if last {
                return consumed;
            }
        }
    }

    /// A position that can hold anything, `repeat` times; true when the
    /// positions ran out.
    fn any(&mut self, at: &mut usize, max_chars: &mut usize, consumed: &mut usize, repeat: &mut u32) -> bool {
        loop {
            self.chars[*at].count = ANY;
            *consumed += 1;
            *max_chars -= 1;
            if *max_chars == 0 {
                return true;
            }
            *at += 1;
            *repeat -= 1;
            if *repeat == 0 {
                break;
            }
        }
        *repeat = 1;
        false
    }

    /// Whether `scan_prefix()` goes past a character type (as any code
    /// unit) rather than stopping there.
    fn type_any(&self, lit: &Lit) -> bool {
        match lit {
            Lit::Digit(false) => !self.utf || char7(&CBITS[CBIT_DIGIT..CBIT_DIGIT + 32]),
            Lit::Space(false) => !self.utf || char7(&CBITS[CBIT_SPACE..CBIT_SPACE + 32]),
            Lit::Word(false) => !self.utf || char7(&CBITS[CBIT_WORD..CBIT_WORD + 32]),
            Lit::Digit(true) | Lit::Space(true) | Lit::Word(true) | Lit::Any | Lit::AllAny | Lit::Prop { .. } => {
                !self.utf
            }
            _ => false,
        }
    }

    /// `char_has_othercase()`.
    fn has_other_case(&self, c: u32) -> bool {
        if (self.utf || self.ucp) && c > 127 {
            return c != unicode::other_case(c);
        }
        c <= 0xff && unicode::fcc(c) != c
    }
}
