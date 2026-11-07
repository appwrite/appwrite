//! Random numbers: PHP's Mersenne Twister, the engine behind `mt_rand()`,
//! `rand()`, `array_rand()` and `shuffle()`.
//!
//! [`Mt19937`] is the generator `ext/random` keeps as the default engine. Seeded
//! with [`Mt19937::new`] it produces exactly the sequence PHP produces after
//! `mt_srand($seed)`, so code that picks at random can be replayed against
//! PHP: [`Mt19937::next_u32`] is one engine output, [`Mt19937::range`] is
//! `mt_rand($min, $max)` (and every other bounded draw of the default
//! engine), and [`Mt19937::array_rand`] is `array_rand()` of one key from a
//! list.
//!
//! | PHP | Rust |
//! |---|---|
//! | `mt_srand($seed)` (`MT_RAND_MT19937`) | [`Mt19937::new`] |
//! | the engine seeded from the OS on first use | [`Mt19937::from_entropy`] |
//! | `mt_rand($min, $max)`, `php_mt_rand_range()` | [`Mt19937::range`] |
//! | `array_rand($list)` | [`Mt19937::array_rand`] |
//!
//! ```
//! use php_std::random::Mt19937;
//!
//! let mut mt = Mt19937::new(42);
//! assert_eq!(mt.range(1, 6), 1);
//! ```
//!
//! ```php
//! mt_srand(42);
//! echo mt_rand(1, 6), "\n"; // 1
//! ```

use crate::EngineError;

const N: usize = 624;
const M: usize = 397;

/// PHP's Mersenne Twister (`Random\Engine\Mt19937` in `MT_RAND_MT19937`
/// mode, the default engine).
#[derive(Clone)]
pub struct Mt19937 {
    state: [u32; N],
    count: usize,
}

impl std::fmt::Debug for Mt19937 {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Mt19937").field("count", &self.count).finish_non_exhaustive()
    }
}

#[inline]
fn twist(m: u32, u: u32, v: u32) -> u32 {
    let mixed = (u & 0x8000_0000) | (v & 0x7FFF_FFFF);
    m ^ (mixed >> 1) ^ ((v & 1).wrapping_neg() & 0x9908_B0DF)
}

impl Mt19937 {
    /// A generator seeded like `mt_srand($seed)`: the seed is the low 32
    /// bits of the integer, as PHP casts it.
    pub fn new(seed: i64) -> Self {
        let mut state = [0u32; N];
        state[0] = seed as u32;
        for i in 1..N {
            let prev = state[i - 1];
            state[i] = 1_812_433_253u32.wrapping_mul(prev ^ (prev >> 30)).wrapping_add(i as u32);
        }
        let mut mt = Mt19937 { state, count: N };
        mt.reload();
        mt
    }

    /// A generator seeded from the operating system, as PHP seeds its default
    /// engine on first use.
    pub fn from_entropy() -> Self {
        let mut seed = [0u8; 4];
        if getrandom::fill(&mut seed).is_err() {
            let nanos = std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .map(|d| d.subsec_nanos() ^ d.as_secs() as u32)
                .unwrap_or(0);
            seed = nanos.to_le_bytes();
        }
        Mt19937::new(i64::from(u32::from_le_bytes(seed)))
    }

    fn reload(&mut self) {
        let s = &mut self.state;
        for i in 0..N - M {
            s[i] = twist(s[i + M], s[i], s[i + 1]);
        }
        for i in N - M..N - 1 {
            s[i] = twist(s[i + M - N], s[i], s[i + 1]);
        }
        s[N - 1] = twist(s[M - 1], s[N - 1], s[0]);
        self.count = 0;
    }

    /// One 32-bit output of the engine (`Mt19937::generate()`).
    pub fn next_u32(&mut self) -> u32 {
        if self.count >= N {
            self.reload();
        }
        let mut s1 = self.state[self.count];
        self.count += 1;
        s1 ^= s1 >> 11;
        s1 ^= (s1 << 7) & 0x9D2C_5680;
        s1 ^= (s1 << 15) & 0xEFC6_0000;
        s1 ^ (s1 >> 18)
    }

    /// `mt_rand($min, $max)`: uniform in `min..=max` without modulo bias
    /// (`php_random_range`). `max` below `min` wraps the way PHP's unsigned
    /// arithmetic does; `mt_rand()` itself refuses that before drawing.
    pub fn range(&mut self, min: i64, max: i64) -> i64 {
        let umax = (max as u64).wrapping_sub(min as u64);
        let result = if umax > u64::from(u32::MAX) { self.range64(umax) } else { u64::from(self.range32(umax as u32)) };
        result.wrapping_add(min as u64) as i64
    }

    fn range32(&mut self, umax: u32) -> u32 {
        let mut result = self.next_u32();
        if umax == u32::MAX {
            return result;
        }
        let umax = umax + 1;
        if umax & (umax - 1) == 0 {
            return result & (umax - 1);
        }
        let limit = u32::MAX - (u32::MAX % umax) - 1;
        while result > limit {
            result = self.next_u32();
        }
        result % umax
    }

    /// Two outputs as one 64-bit value, the first in the low half (as
    /// `php_random_range64` concatenates a 32-bit engine's results).
    fn next_u64(&mut self) -> u64 {
        let low = u64::from(self.next_u32());
        low | (u64::from(self.next_u32()) << 32)
    }

    fn range64(&mut self, umax: u64) -> u64 {
        let mut result = self.next_u64();
        if umax == u64::MAX {
            return result;
        }
        let umax = umax + 1;
        if umax & (umax - 1) == 0 {
            return result & (umax - 1);
        }
        let limit = u64::MAX - (u64::MAX % umax) - 1;
        while result > limit {
            result = self.next_u64();
        }
        result % umax
    }

    /// `array_rand($list)` for a list (a packed array without holes) of `len`
    /// elements: the index of one. An empty list is the `ValueError` PHP
    /// throws, and draws nothing.
    pub fn array_rand(&mut self, len: usize) -> Result<usize, EngineError> {
        if len == 0 {
            return Err(EngineError::new("ValueError", "array_rand(): Argument #1 ($array) must not be empty"));
        }
        Ok(self.range(0, len as i64 - 1) as usize)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn matches_the_reference_sequence() {
        // The reference MT19937 output for seed 5489 is 3499211612, 581869302, ...
        let mut mt = Mt19937::new(5489);
        assert_eq!(mt.next_u32(), 3_499_211_612);
        assert_eq!(mt.next_u32(), 581_869_302);
    }

    #[test]
    fn ranges_stay_in_bounds() {
        let mut mt = Mt19937::new(1);
        for _ in 0..1000 {
            let v = mt.range(-3, 7);
            assert!((-3..=7).contains(&v));
        }
        assert!(mt.array_rand(0).is_err());
        assert_eq!(mt.range(4, 4), 4);
    }
}
