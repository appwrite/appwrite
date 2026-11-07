//! The matching engine for timelib's re2c scanner: a Thompson NFA built
//! from the rule definitions of `parse_date.re`, simulated to find what the
//! re2c DFA finds: the longest match over all rules, the earliest rule on a
//! tie.
//!
//! The simulation is cached as a lazily built DFA (each set of NFA states
//! seen becomes a DFA state, each transition is computed once), so a
//! format seen before is scanned at one table lookup per byte.

use std::collections::HashMap;
use std::sync::{OnceLock, RwLock};

/// A set of bytes.
#[derive(Clone, Copy, PartialEq, Eq)]
pub struct ByteSet([u64; 4]);

impl ByteSet {
    const EMPTY: ByteSet = ByteSet([0; 4]);

    fn insert(&mut self, b: u8) {
        self.0[(b >> 6) as usize] |= 1 << (b & 63);
    }

    fn contains(&self, b: u8) -> bool {
        self.0[(b >> 6) as usize] & (1 << (b & 63)) != 0
    }
}

/// A pattern (a re2c regular expression).
#[derive(Clone)]
pub enum P {
    Set(ByteSet),
    Seq(Vec<P>),
    Alt(Vec<P>),
    /// `p{min,max}`, `max` `None` for unbounded.
    Rep(Box<P>, u32, Option<u32>),
}

/// `"..."`: a case-sensitive literal.
pub fn lit(s: &str) -> P {
    P::Seq(s.bytes().map(|b| set_of(&[b])).collect())
}

/// `'...'`: an ASCII case-insensitive literal.
pub fn ci(s: &str) -> P {
    P::Seq(
        s.bytes()
            .map(|b| {
                if b.is_ascii_alphabetic() {
                    set_of(&[b.to_ascii_lowercase(), b.to_ascii_uppercase()])
                } else {
                    set_of(&[b])
                }
            })
            .collect(),
    )
}

fn set_of(bytes: &[u8]) -> P {
    let mut s = ByteSet::EMPTY;
    for &b in bytes {
        s.insert(b);
    }
    P::Set(s)
}

/// `[...]` of the listed bytes.
pub fn set(chars: &str) -> P {
    set_of(chars.as_bytes())
}

/// `[a-b]`.
pub fn range(a: u8, b: u8) -> P {
    let mut s = ByteSet::EMPTY;
    for c in a..=b {
        s.insert(c);
    }
    P::Set(s)
}

/// A union of byte ranges.
pub fn ranges(rs: &[(u8, u8)]) -> P {
    let mut s = ByteSet::EMPTY;
    for &(a, b) in rs {
        for c in a..=b {
            s.insert(c);
        }
    }
    P::Set(s)
}

/// A pattern matching one ASCII digit.
pub fn digit() -> P {
    range(b'0', b'9')
}

/// A pattern matching each of `ps` in turn.
pub fn seq(ps: Vec<P>) -> P {
    P::Seq(ps)
}

/// A pattern matching any one of `ps`.
pub fn alt(ps: Vec<P>) -> P {
    P::Alt(ps)
}

/// A pattern matching `p` or nothing.
pub fn opt(p: P) -> P {
    P::Rep(Box::new(p), 0, Some(1))
}

/// A pattern matching `p` zero or more times.
pub fn star(p: P) -> P {
    P::Rep(Box::new(p), 0, None)
}

/// A pattern matching `p` one or more times.
pub fn plus(p: P) -> P {
    P::Rep(Box::new(p), 1, None)
}

/// A pattern matching `p` from `min` to `max` times.
pub fn rep(p: P, min: u32, max: u32) -> P {
    P::Rep(Box::new(p), min, Some(max))
}

enum State {
    Byte(ByteSet, usize),
    Split(Vec<usize>),
    Match(u16),
}

/// All rules compiled into one NFA.
pub struct Nfa {
    states: Vec<State>,
    /// The epsilon closure of `start` (computed once).
    start_closure: Vec<usize>,
    /// The DFA states and transitions found so far.
    dfa: RwLock<Dfa>,
}

/// A transition not computed yet.
const UNKNOWN: u32 = u32::MAX;
/// A transition to no state (the scan ends).
const DEAD: u32 = u32::MAX - 1;
/// DFA states kept at most; past it, scans simulate the NFA.
const MAX_DFA_STATES: usize = 4096;

/// A set of NFA states (sorted) as a DFA state: the rule it matches (the
/// earliest of its match states) and its transitions by byte.
struct DfaState {
    rule: Option<u16>,
    next: Box<[u32; 256]>,
}

#[derive(Default)]
struct Dfa {
    states: Vec<DfaState>,
    /// Each DFA state's NFA states, and the reverse index.
    sets: Vec<Vec<usize>>,
    index: HashMap<Vec<usize>, u32>,
}

impl Nfa {
    /// Compiles `rules` (rule `i` reports id `i`).
    pub fn new(rules: Vec<P>) -> Nfa {
        let mut states = Vec::new();
        let mut entries = Vec::new();
        for (i, r) in rules.iter().enumerate() {
            states.push(State::Match(i as u16));
            let m = states.len() - 1;
            entries.push(compile(r, m, &mut states));
        }
        states.push(State::Split(entries));
        let start = states.len() - 1;
        let mut nfa = Nfa { states, start_closure: Vec::new(), dfa: RwLock::new(Dfa::default()) };
        let mut marks = vec![0u32; nfa.states.len()];
        let mut out = Vec::new();
        nfa.closure(&[start], &mut marks, 1, &mut out);
        nfa.start_closure = out;
        let start = nfa.start_closure.clone();
        let mut dfa = Dfa::default();
        nfa.add_state(&mut dfa, start);
        nfa.dfa = RwLock::new(dfa);
        nfa
    }

    /// The earliest rule a set of NFA states matches.
    fn rule_of(&self, set: &[usize]) -> Option<u16> {
        set.iter()
            .filter_map(|&st| match self.states[st] {
                State::Match(r) => Some(r),
                _ => None,
            })
            .min()
    }

    /// The DFA state of a set of NFA states (sorted), added when new.
    fn add_state(&self, dfa: &mut Dfa, set: Vec<usize>) -> u32 {
        if let Some(&id) = dfa.index.get(&set) {
            return id;
        }
        let id = dfa.states.len() as u32;
        dfa.states.push(DfaState { rule: self.rule_of(&set), next: Box::new([UNKNOWN; 256]) });
        dfa.sets.push(set.clone());
        dfa.index.insert(set, id);
        id
    }

    /// The NFA states reached from `current` on byte `b` (with their
    /// epsilon closure), sorted; empty when none.
    fn step(&self, current: &[usize], b: u8) -> Vec<usize> {
        let next_from: Vec<usize> = current
            .iter()
            .filter_map(|&st| match &self.states[st] {
                State::Byte(set, next) if set.contains(b) => Some(*next),
                _ => None,
            })
            .collect();
        if next_from.is_empty() {
            return Vec::new();
        }
        let mut marks = vec![0u32; self.states.len()];
        let mut next = Vec::with_capacity(next_from.len() * 2);
        self.closure(&next_from, &mut marks, 1, &mut next);
        next.sort_unstable();
        next
    }

    /// The transition of DFA state `from` on `b`, computed (and cached)
    /// when new; `None` once the DFA is full.
    fn transition(&self, from: u32, b: u8) -> Option<u32> {
        let mut dfa = self.dfa.write().unwrap_or_else(|e| e.into_inner());
        let known = dfa.states[from as usize].next[b as usize];
        if known != UNKNOWN {
            return Some(known);
        }
        if dfa.states.len() >= MAX_DFA_STATES {
            return None;
        }
        let next = self.step(&dfa.sets[from as usize], b);
        let to = if next.is_empty() { DEAD } else { self.add_state(&mut dfa, next) };
        dfa.states[from as usize].next[b as usize] = to;
        Some(to)
    }

    fn closure(&self, from: &[usize], marks: &mut [u32], generation: u32, out: &mut Vec<usize>) {
        let mut stack: Vec<usize> = from.to_vec();
        while let Some(s) = stack.pop() {
            if marks[s] == generation {
                continue;
            }
            marks[s] = generation;
            match &self.states[s] {
                State::Split(next) => stack.extend(next.iter().rev().copied()),
                _ => out.push(s),
            }
        }
    }

    /// The longest match at `start` (bytes past the end of `s` read as NUL,
    /// like the scanner's padding) and the rule that matched, earliest first.
    pub fn longest(&self, s: &[u8], start: usize) -> Option<(usize, u16)> {
        let byte = |p: usize| s.get(p).copied().unwrap_or(0);
        let mut best: Option<(usize, u16)> = None;
        let mut state = 0u32;
        let mut pos = start;
        let mut dfa = self.dfa.read().unwrap_or_else(|e| e.into_inner());
        loop {
            let current = &dfa.states[state as usize];
            if let Some(r) = current.rule {
                best = Some((pos, r));
            }
            if pos > s.len() + 2 {
                return best;
            }
            let b = byte(pos);
            let mut next = current.next[b as usize];
            if next == UNKNOWN {
                drop(dfa);
                match self.transition(state, b) {
                    Some(to) => next = to,
                    // The DFA is full: simulate the NFA for this scan.
                    None => return self.simulate(s, start),
                }
                dfa = self.dfa.read().unwrap_or_else(|e| e.into_inner());
            }
            if next == DEAD {
                return best;
            }
            state = next;
            pos += 1;
        }
    }

    /// [`Nfa::longest`] by simulating the NFA, state set by state set.
    fn simulate(&self, s: &[u8], start: usize) -> Option<(usize, u16)> {
        let byte = |p: usize| s.get(p).copied().unwrap_or(0);
        let mut marks = vec![0u32; self.states.len()];
        let mut generation = 1u32;
        let mut current = self.start_closure.clone();
        let mut best: Option<(usize, u16)> = None;
        let mut pos = start;
        let mut next_from = Vec::new();
        loop {
            let mut rule = None;
            for &st in &current {
                if let State::Match(r) = self.states[st] {
                    rule = Some(rule.map_or(r, |x: u16| x.min(r)));
                }
            }
            if let Some(r) = rule {
                best = Some((pos, r));
            }
            if pos > s.len() + 2 {
                break;
            }
            let b = byte(pos);
            next_from.clear();
            for &st in &current {
                if let State::Byte(set, next) = &self.states[st]
                    && set.contains(b)
                {
                    next_from.push(*next);
                }
            }
            if next_from.is_empty() {
                break;
            }
            generation += 1;
            let mut next = Vec::with_capacity(next_from.len() * 2);
            self.closure(&next_from, &mut marks, generation, &mut next);
            current = next;
            pos += 1;
        }
        best
    }
}

/// Thompson construction, backwards: the entry state of `p` continuing to `next`.
fn compile(p: &P, next: usize, states: &mut Vec<State>) -> usize {
    match p {
        P::Set(s) => {
            states.push(State::Byte(*s, next));
            states.len() - 1
        }
        P::Seq(ps) => {
            let mut n = next;
            for p in ps.iter().rev() {
                n = compile(p, n, states);
            }
            n
        }
        P::Alt(ps) => {
            let entries = ps.iter().map(|p| compile(p, next, states)).collect();
            states.push(State::Split(entries));
            states.len() - 1
        }
        P::Rep(p, min, max) => {
            let mut n = next;
            match max {
                Some(max) => {
                    // Optional copies, nested: (p(p(...)?)?)?
                    for _ in *min..*max {
                        let e = compile(p, n, states);
                        states.push(State::Split(vec![e, next]));
                        n = states.len() - 1;
                    }
                }
                None => {
                    states.push(State::Split(Vec::new()));
                    let l = states.len() - 1;
                    let body = compile(p, l, states);
                    states[l] = State::Split(vec![body, next]);
                    n = l;
                }
            }
            for _ in 0..*min {
                n = compile(p, n, states);
            }
            n
        }
    }
}

/// A lazily built NFA.
pub struct Lazy(OnceLock<Nfa>, fn() -> Vec<P>);

impl Lazy {
    /// An NFA built from `rules` on first use.
    pub const fn new(rules: fn() -> Vec<P>) -> Lazy {
        Lazy(OnceLock::new(), rules)
    }

    /// The NFA, built on first call.
    pub fn get(&self) -> &Nfa {
        self.0.get_or_init(|| Nfa::new((self.1)()))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn longest_match_then_earliest_rule() {
        let nfa = Nfa::new(vec![ci("ab"), plus(range(b'a', b'z')), lit("a")]);
        assert_eq!(nfa.longest(b"abc", 0), Some((3, 1)));
        assert_eq!(nfa.longest(b"AB1", 0), Some((2, 0)));
        assert_eq!(nfa.longest(b"a1", 0), Some((1, 1)));
        assert_eq!(nfa.longest(b"1", 0), None);
        let nfa = Nfa::new(vec![seq(vec![rep(digit(), 1, 4), opt(lit("x"))])]);
        assert_eq!(nfa.longest(b"12345", 0), Some((4, 0)));
        assert_eq!(nfa.longest(b"12x", 0), Some((3, 0)));
    }

    #[test]
    fn the_dfa_finds_what_the_simulation_finds() {
        let nfa = Nfa::new(vec![
            ci("ab"),
            plus(range(b'a', b'z')),
            lit("a"),
            seq(vec![rep(digit(), 1, 4), opt(lit("x"))]),
            seq(vec![star(set(" \t")), lit("+"), rep(digit(), 2, 2)]),
        ]);
        for input in [&b"abc"[..], b"AB1", b"a1", b"1", b"12345", b"12x", b"  +05", b"", b"zz9", b"abcabc"] {
            for start in 0..=input.len() {
                assert_eq!(nfa.longest(input, start), nfa.simulate(input, start), "{input:?} at {start}");
                // Twice: the second scan runs on cached transitions.
                assert_eq!(nfa.longest(input, start), nfa.simulate(input, start), "{input:?} at {start}");
            }
        }
    }
}
