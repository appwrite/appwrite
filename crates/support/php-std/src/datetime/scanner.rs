//! The matching engine for timelib's re2c scanner: a Thompson NFA built
//! from the rule definitions of `parse_date.re`, simulated to find what the
//! re2c DFA finds: the longest match over all rules, the earliest rule on a
//! tie.

use std::sync::OnceLock;

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

pub fn digit() -> P {
    range(b'0', b'9')
}

pub fn seq(ps: Vec<P>) -> P {
    P::Seq(ps)
}

pub fn alt(ps: Vec<P>) -> P {
    P::Alt(ps)
}

pub fn opt(p: P) -> P {
    P::Rep(Box::new(p), 0, Some(1))
}

pub fn star(p: P) -> P {
    P::Rep(Box::new(p), 0, None)
}

pub fn plus(p: P) -> P {
    P::Rep(Box::new(p), 1, None)
}

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
        let mut nfa = Nfa { states, start_closure: Vec::new() };
        let mut marks = vec![0u32; nfa.states.len()];
        let mut out = Vec::new();
        nfa.closure(&[start], &mut marks, 1, &mut out);
        nfa.start_closure = out;
        nfa
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
    pub const fn new(rules: fn() -> Vec<P>) -> Lazy {
        Lazy(OnceLock::new(), rules)
    }

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
}
