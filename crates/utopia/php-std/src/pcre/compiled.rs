//! A PHP regex compiled once and matched many times: `preg_match()` without
//! the per-call cache lookup, for libraries that run fixed patterns on a hot
//! path (`utopia-user-agent` runs its tables on every request).
//!
//! | PHP | Rust |
//! |---|---|
//! | `preg_match($re, $s, $m) === 1`, then `$m[$n]` | [`Regex::compiled`], [`Regex::captures`], [`Captures::get`] |
//! | `preg_match($re, $s) === 1` | [`Regex::is_match`] |
//!
//! Both run exactly the calls `preg_match()` makes, so a match that fails
//! (`pcre.backtrack_limit`, the JIT stack) is no match, as `preg_match()`
//! returning `false` is not `1`. They do not set `preg_last_error()`.

use std::sync::Arc;

use super::ffi::UNSET;
use super::{Call, CompileFailure, Regex, cached};

/// The groups of one match of [`Regex::captures`], as `preg_match()` puts
/// them in `$matches`.
#[derive(Debug, Clone)]
pub struct Captures<'s> {
    subject: &'s [u8],
    ovector: Offsets,
}

/// The offset pairs of the groups a match set: inline for up to
/// [`INLINE`] groups, which is most patterns.
#[derive(Debug, Clone)]
enum Offsets {
    Inline { len: usize, pairs: [usize; 2 * INLINE] },
    Heap(Vec<usize>),
}

const INLINE: usize = 6;

impl Offsets {
    fn new(ov: &[usize]) -> Self {
        if ov.len() <= 2 * INLINE {
            let mut pairs = [0; 2 * INLINE];
            pairs[..ov.len()].copy_from_slice(ov);
            Offsets::Inline { len: ov.len(), pairs }
        } else {
            Offsets::Heap(ov.to_vec())
        }
    }

    fn as_slice(&self) -> &[usize] {
        match self {
            Offsets::Inline { len, pairs } => &pairs[..*len],
            Offsets::Heap(v) => v,
        }
    }
}

impl<'s> Captures<'s> {
    /// `$matches[$group]`: `None` when `$matches` has no such key (groups
    /// after the last one that took part in the match); an unset group
    /// before it is `""`.
    pub fn get(&self, group: usize) -> Option<&'s [u8]> {
        let ov = self.ovector.as_slice();
        let (start, end) = (*ov.get(2 * group)?, ov[2 * group + 1]);
        Some(if start == UNSET { &[] } else { &self.subject[start..end] })
    }

    /// `count($matches)` (without named groups): the whole match and every
    /// group up to the last one set.
    pub fn len(&self) -> usize {
        self.ovector.as_slice().len() / 2
    }

    /// Always `false`: a match has at least the whole match.
    pub fn is_empty(&self) -> bool {
        self.ovector.as_slice().is_empty()
    }
}

impl Regex {
    /// `pcre_get_compiled_regex_cache()` as `preg_match()` calls it: the
    /// pattern from the per-process cache, compiled (JIT included, as the
    /// process has it) on a miss. A pattern that does not compile is the
    /// warning `preg_match()` emits for it.
    pub fn compiled(regex: &[u8]) -> Result<Arc<Regex>, CompileFailure> {
        cached("preg_match", regex).map(|(re, _)| re).map_err(|message| CompileFailure { message })
    }

    /// `preg_match($regex, $subject, $matches) === 1`: the groups of the
    /// first match, or `None` when there is none or the match fails.
    pub fn captures<'s>(&self, subject: &'s [u8]) -> Option<Captures<'s>> {
        let mut session = self.session();
        match self.call(&mut session, subject, 0, self.first_call(subject.is_empty())) {
            Ok(Some(count)) => {
                let ov = session.ovector(count);
                (ov[1] >= ov[0]).then(|| Captures { subject, ovector: Offsets::new(ov) })
            }
            _ => None,
        }
    }

    /// `preg_match($regex, $subject) === 1`. Without `$matches`, PHP
    /// retries an empty match at the same position (anchored, not empty at
    /// start), and a failure there makes the result `false`.
    pub fn is_match(&self, subject: &[u8]) -> bool {
        let mut session = self.session();
        match self.call(&mut session, subject, 0, self.first_call(subject.is_empty())) {
            Ok(Some(count)) => {
                let ov = session.ovector(count);
                let (start, end) = (ov[0], ov[1]);
                end != start || self.call(&mut session, subject, end, Call::Retry).is_ok()
            }
            _ => false,
        }
    }
}
