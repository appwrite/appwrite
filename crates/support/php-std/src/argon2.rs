//! Argon2 (RFC 9106) as libargon2 computes it for `password_hash()` and `password_verify()`:
//! its lanes filled in parallel, as libargon2 does with as many threads as lanes.
//!
//! The `argon2` crate fills lanes one after the other unless its `parallel` feature brings in
//! rayon's process-wide thread pool, which a process that forks (Swoole workers do) must not
//! have. Here each slice's lanes run on scoped threads, joined before the next slice starts,
//! as libargon2 joins its pthreads at every sync point: no thread outlives the call.

use blake2::digest::{Digest, Update, VariableOutput};
use blake2::{Blake2b512, Blake2bVar};
use std::mem::MaybeUninit;
use std::thread;

/// libargon2's `argon2_type` values, which the initial hash and the address blocks hash.
#[derive(Clone, Copy, PartialEq, Eq)]
pub(crate) enum Algorithm {
    Argon2i = 1,
    Argon2id = 2,
}

const SYNC_POINTS: usize = 4;
const BLOCK_WORDS: usize = 128;
const BLOCK_BYTES: usize = BLOCK_WORDS * 8;
/// Lanes beyond this share threads: a hash naming many lanes does not start a thread for each.
const MAX_THREADS: usize = 16;

#[derive(Clone, Copy)]
#[repr(C, align(64))]
struct Block([u64; BLOCK_WORDS]);

impl Block {
    const ZERO: Self = Self([0; BLOCK_WORDS]);

    fn from_bytes(bytes: &[u8; BLOCK_BYTES]) -> Self {
        let mut block = Self::ZERO;
        for (word, chunk) in block.0.iter_mut().zip(bytes.chunks_exact(8)) {
            *word = u64::from_le_bytes(chunk.try_into().expect("8 bytes"));
        }
        block
    }

    fn xor(&mut self, other: &Self) {
        for (a, b) in self.0.iter_mut().zip(other.0.iter()) {
            *a ^= b;
        }
    }
}

/// The checked costs of one hash: what libargon2's `validate_inputs()` accepts.
pub(crate) struct Params {
    algorithm: Algorithm,
    version: u32,
    memory: u32,
    passes: u32,
    lanes: u32,
}

impl Params {
    /// `None` where libargon2 refuses the costs: fewer than 8 KiB per lane, no pass, no lane or
    /// more than 2^24 - 1 of them, or a version other than 0x10 and 0x13.
    pub(crate) fn new(algorithm: Algorithm, version: u32, memory: u32, passes: u32, lanes: u32) -> Option<Self> {
        let valid = matches!(version, 0x10 | 0x13)
            && passes >= 1
            && (1..=0xFF_FFFF).contains(&lanes)
            && u64::from(memory) >= 8 * u64::from(lanes);
        valid.then_some(Self { algorithm, version, memory, passes, lanes })
    }
}

/// `argon2_hash()` into `out` (4 bytes or more), with no secret and no associated data;
/// `None` for a salt shorter than 8 bytes or a password or salt longer than 2^32 - 1.
pub(crate) fn hash(params: &Params, password: &[u8], salt: &[u8], out: &mut [u8]) -> Option<()> {
    let length = |b: &[u8]| u32::try_from(b.len()).ok();
    let (password_len, salt_len, out_len) = (length(password)?, length(salt)?, length(out)?);
    if salt.len() < 8 || out.len() < 4 {
        return None;
    }
    let lanes = params.lanes as usize;
    // libargon2 rounds memory down to a multiple of 4 blocks per lane.
    let segment = params.memory as usize / (SYNC_POINTS * lanes);
    let lane_length = segment * SYNC_POINTS;

    let mut h0 = Blake2b512::new();
    let algorithm = params.algorithm as u32;
    for word in [params.lanes, out_len, params.memory, params.passes, params.version, algorithm, password_len] {
        Digest::update(&mut h0, word.to_le_bytes());
    }
    Digest::update(&mut h0, password);
    Digest::update(&mut h0, salt_len.to_le_bytes());
    Digest::update(&mut h0, salt);
    // No secret and no associated data: their lengths, 0.
    Digest::update(&mut h0, [0u8; 8]);
    let h0 = h0.finalize();

    // Left uninitialized: the first pass writes every block before any is read, and the lanes'
    // threads fault their own pages in, where zeroing it first would on this thread alone. Memory
    // the system will not give is `None`, as libargon2's `ARGON2_MEMORY_ALLOCATION_ERROR`.
    let total = lanes * lane_length;
    let mut memory: Vec<MaybeUninit<Block>> = Vec::new();
    memory.try_reserve_exact(total).ok()?;
    memory.resize_with(total, MaybeUninit::uninit);
    for (lane, blocks) in memory.chunks_exact_mut(lane_length).enumerate() {
        for (index, block) in blocks[..2].iter_mut().enumerate() {
            let mut bytes = [0u8; BLOCK_BYTES];
            long(&[&h0, &(index as u32).to_le_bytes(), &(lane as u32).to_le_bytes()], &mut bytes);
            block.write(Block::from_bytes(&bytes));
        }
    }

    let fill =
        Fill { memory: Memory(memory.as_mut_ptr().cast()), params, lanes, segment, lane_length, total, avx2: avx2() };
    let threads = lanes.min(MAX_THREADS);
    for pass in 0..params.passes as usize {
        for slice in 0..SYNC_POINTS {
            let worker = |first: usize| {
                for lane in (first..lanes).step_by(threads) {
                    // SAFETY: lanes write only their own segment of this slice, and read only
                    // blocks of other slices or blocks of their own segment (`Fill::segment`).
                    unsafe { fill.segment(pass, slice, lane) };
                }
            };
            thread::scope(|scope| {
                let mut inline = Vec::new();
                for first in 1..threads {
                    if thread::Builder::new().spawn_scoped(scope, move || worker(first)).is_err() {
                        inline.push(first);
                    }
                }
                worker(0);
                // A thread the system would not start: its lanes on this one, where libargon2
                // fails the hash.
                inline.into_iter().for_each(worker);
            });
        }
    }

    // SAFETY: the first pass wrote every block.
    let block = |index: usize| unsafe { memory[index].assume_init_ref() };
    let mut last = *block(lane_length - 1);
    for lane in 1..lanes {
        last.xor(block(lane * lane_length + lane_length - 1));
    }
    let mut bytes = [0u8; BLOCK_BYTES];
    for (chunk, word) in bytes.chunks_exact_mut(8).zip(last.0) {
        chunk.copy_from_slice(&word.to_le_bytes());
    }
    long(&[&bytes], out);
    Some(())
}

/// The memory being filled, shared by the threads of a slice.
#[derive(Clone, Copy)]
struct Memory(*mut Block);

// SAFETY: threads touch disjoint blocks while they run (see `Fill::segment`), and a slice's
// threads are joined before the next slice's start.
unsafe impl Send for Memory {}
unsafe impl Sync for Memory {}

struct Fill<'a> {
    memory: Memory,
    params: &'a Params,
    lanes: usize,
    segment: usize,
    lane_length: usize,
    total: usize,
    avx2: bool,
}

impl Fill<'_> {
    /// Fills `lane`'s segment of `slice` in `pass` (RFC 9106 3.2 and 3.4).
    ///
    /// # Safety
    ///
    /// No other thread may write the blocks this segment reads or writes: the other lanes' in
    /// the same slice may run, which write only their own segments of it, never referenced here.
    unsafe fn segment(&self, pass: usize, slice: usize, lane: usize) {
        let independent = self.params.algorithm == Algorithm::Argon2i || (pass == 0 && slice < SYNC_POINTS / 2);
        let mut addresses = Block::ZERO;
        let mut input = Block::ZERO;
        if independent {
            input.0[..6].copy_from_slice(&[
                pass as u64,
                lane as u64,
                slice as u64,
                self.total as u64,
                u64::from(self.params.passes),
                self.params.algorithm as u64,
            ]);
        }
        let first = if pass == 0 && slice == 0 {
            if independent {
                self.next_addresses(&mut addresses, &mut input);
            }
            2
        } else {
            0
        };
        let start = lane * self.lane_length + slice * self.segment;
        // The block before the first: the lane's last, for a segment at the start of the lane.
        let mut previous = if slice == 0 && first == 0 { start + self.lane_length - 1 } else { start + first - 1 };
        let base = self.memory.0;
        for index in first..self.segment {
            let current = start + index;
            let random = if independent {
                if index % BLOCK_WORDS == 0 {
                    self.next_addresses(&mut addresses, &mut input);
                }
                addresses.0[index % BLOCK_WORDS]
            } else {
                // SAFETY: `previous` is this lane's block before `current`: written by this thread,
                // or before this slice's threads started.
                unsafe { (*base.add(previous)).0[0] }
            };
            let reference_lane = if pass == 0 && slice == 0 { lane } else { (random >> 32) as usize % self.lanes };
            let reference = reference_lane * self.lane_length
                + self.reference_index(pass, slice, index, reference_lane == lane, random);
            // SAFETY: `previous` and `reference` are written blocks no thread writes during this
            // slice; `current` is this segment's, which only this thread touches, and is written
            // (by an earlier pass) where it is XORed.
            unsafe {
                let next = self.compress(&*base.add(previous), &*base.add(reference));
                if self.params.version == 0x10 || pass == 0 {
                    base.add(current).write(next);
                } else {
                    (*base.add(current)).xor(&next);
                }
            }
            previous = current;
        }
    }

    /// The position in the reference lane of block `index` of the segment (RFC 9106 3.4.2).
    fn reference_index(&self, pass: usize, slice: usize, index: usize, same_lane: bool, random: u64) -> usize {
        let area = if pass == 0 {
            if slice == 0 {
                index - 1
            } else if same_lane {
                slice * self.segment + index - 1
            } else {
                slice * self.segment - usize::from(index == 0)
            }
        } else if same_lane {
            self.lane_length - self.segment + index - 1
        } else {
            self.lane_length - self.segment - usize::from(index == 0)
        };
        let x = ((random & 0xFFFF_FFFF) * (random & 0xFFFF_FFFF)) >> 32;
        let relative = area - 1 - ((area as u64 * x) >> 32) as usize;
        let start = if pass != 0 && slice != SYNC_POINTS - 1 { (slice + 1) * self.segment } else { 0 };
        (start + relative) % self.lane_length
    }

    fn next_addresses(&self, addresses: &mut Block, input: &mut Block) {
        input.0[6] += 1;
        *addresses = self.compress(&Block::ZERO, input);
        *addresses = self.compress(&Block::ZERO, addresses);
    }

    fn compress(&self, x: &Block, y: &Block) -> Block {
        #[cfg(target_arch = "x86_64")]
        if self.avx2 {
            // SAFETY: the CPU has AVX2 (`avx2()`).
            return unsafe { compress_avx2(x, y) };
        }
        compress(x, y)
    }
}

fn avx2() -> bool {
    #[cfg(target_arch = "x86_64")]
    return std::arch::is_x86_feature_detected!("avx2");
    #[cfg(not(target_arch = "x86_64"))]
    false
}

/// `compress` compiled for AVX2, which vectorises its rounds.
#[cfg(target_arch = "x86_64")]
#[target_feature(enable = "avx2")]
fn compress_avx2(x: &Block, y: &Block) -> Block {
    compress(x, y)
}

/// BLAKE2b's G with `a + b + 2 * lo32(a) * lo32(b)` for each addition (RFC 9106 3.6).
macro_rules! mix {
    ($a:expr, $b:expr, $c:expr, $d:expr) => {
        $a = $a.wrapping_add($b).wrapping_add(2u64.wrapping_mul(($a & 0xFFFF_FFFF) * ($b & 0xFFFF_FFFF)));
        $d = ($d ^ $a).rotate_right(32);
        $c = $c.wrapping_add($d).wrapping_add(2u64.wrapping_mul(($c & 0xFFFF_FFFF) * ($d & 0xFFFF_FFFF)));
        $b = ($b ^ $c).rotate_right(24);
        $a = $a.wrapping_add($b).wrapping_add(2u64.wrapping_mul(($a & 0xFFFF_FFFF) * ($b & 0xFFFF_FFFF)));
        $d = ($d ^ $a).rotate_right(16);
        $c = $c.wrapping_add($d).wrapping_add(2u64.wrapping_mul(($c & 0xFFFF_FFFF) * ($d & 0xFFFF_FFFF)));
        $b = ($b ^ $c).rotate_right(63);
    };
}

/// The permutation P over 16 words: `mix` down the columns, then the diagonals.
macro_rules! round {
    ($v0:expr, $v1:expr, $v2:expr, $v3:expr, $v4:expr, $v5:expr, $v6:expr, $v7:expr,
     $v8:expr, $v9:expr, $v10:expr, $v11:expr, $v12:expr, $v13:expr, $v14:expr, $v15:expr,) => {
        mix!($v0, $v4, $v8, $v12);
        mix!($v1, $v5, $v9, $v13);
        mix!($v2, $v6, $v10, $v14);
        mix!($v3, $v7, $v11, $v15);
        mix!($v0, $v5, $v10, $v15);
        mix!($v1, $v6, $v11, $v12);
        mix!($v2, $v7, $v8, $v13);
        mix!($v3, $v4, $v9, $v14);
    };
}

/// The compression function G (RFC 9106 3.5): BLAKE2b's round with multiplications, over the
/// rows and then the columns of `x ^ y`, XORed with `x ^ y`.
#[inline(always)]
fn compress(x: &Block, y: &Block) -> Block {
    let mut r = *x;
    r.xor(y);
    let mut q = r;
    for row in q.0.chunks_exact_mut(16) {
        #[rustfmt::skip]
        round!(
            row[0], row[1], row[2], row[3], row[4], row[5], row[6], row[7],
            row[8], row[9], row[10], row[11], row[12], row[13], row[14], row[15],
        );
    }
    for b in (0..16).step_by(2) {
        let q = &mut q.0;
        #[rustfmt::skip]
        round!(
            q[b], q[b + 1], q[b + 16], q[b + 17], q[b + 32], q[b + 33], q[b + 48], q[b + 49],
            q[b + 64], q[b + 65], q[b + 80], q[b + 81], q[b + 96], q[b + 97], q[b + 112], q[b + 113],
        );
    }
    q.xor(&r);
    q
}

/// The variable-length hash H' (RFC 9106 3.3) of the concatenated `inputs` into `out`.
fn long(inputs: &[&[u8]], out: &mut [u8]) {
    let length = (out.len() as u32).to_le_bytes();
    if out.len() <= 64 {
        let mut digest = Blake2bVar::new(out.len()).expect("1 to 64 bytes");
        Update::update(&mut digest, &length);
        inputs.iter().for_each(|input| Update::update(&mut digest, input));
        digest.finalize_variable(out).expect("the requested length");
        return;
    }
    let mut digest = Blake2b512::new();
    Digest::update(&mut digest, length);
    inputs.iter().for_each(|input| Digest::update(&mut digest, input));
    let mut v = digest.finalize();
    // 32 bytes of each 64-byte hash, then all of the last, which is as long as what is left.
    let mut at = 0;
    while out.len() - at > 64 {
        out[at..at + 32].copy_from_slice(&v[..32]);
        at += 32;
        if out.len() - at > 64 {
            v = Blake2b512::digest(v);
        }
    }
    let mut last = Blake2bVar::new(out.len() - at).expect("33 to 64 bytes");
    Update::update(&mut last, &v);
    last.finalize_variable(&mut out[at..]).expect("the requested length");
}

#[cfg(test)]
mod tests {
    use super::{Algorithm, Params, hash};

    fn reference(
        algorithm: Algorithm,
        version: u32,
        [m, t, p]: [u32; 3],
        password: &[u8],
        salt: &[u8],
        len: usize,
    ) -> Vec<u8> {
        let algorithm = match algorithm {
            Algorithm::Argon2i => ::argon2::Algorithm::Argon2i,
            Algorithm::Argon2id => ::argon2::Algorithm::Argon2id,
        };
        let version = if version == 0x10 { ::argon2::Version::V0x10 } else { ::argon2::Version::V0x13 };
        let params = ::argon2::Params::new(m, t, p, Some(len)).expect("valid params");
        let mut out = vec![0u8; len];
        ::argon2::Argon2::new(algorithm, version, params).hash_password_into(password, salt, &mut out).expect("hash");
        out
    }

    #[test]
    fn hashes_are_the_argon2_crates() {
        let costs = [
            [8, 1, 1],
            [32, 1, 4],
            [64, 3, 3],
            [1024, 1, 1],
            [1024, 2, 2],
            [2048, 2, 4],
            [4099, 3, 3],
            [512, 2, 7],
            [544, 1, 17],
            [256, 1, 32],
        ];
        for algorithm in [Algorithm::Argon2i, Algorithm::Argon2id] {
            for version in [0x10, 0x13] {
                for costs in costs {
                    for (password, salt, len) in [
                        (&b""[..], &b"saltsalt"[..], 4),
                        (b"password", b"somesaltsomesalt", 32),
                        (&[0xFF; 200][..], &[7; 33][..], 65),
                        (b"p", b"saltsalt", 1027),
                    ] {
                        let params = Params::new(algorithm, version, costs[0], costs[1], costs[2]).expect("valid");
                        let mut out = vec![0u8; len];
                        hash(&params, password, salt, &mut out).expect("hash");
                        assert_eq!(
                            out,
                            reference(algorithm, version, costs, password, salt, len),
                            "{costs:?} {version:#x}"
                        );
                    }
                }
            }
        }
    }

    #[test]
    fn hashes_the_reference_implementations_example() {
        // phc-winner-argon2's README: `echo -n "password" | ./argon2 somesalt -t 2 -m 16 -p 4 -l 24`.
        let params = Params::new(Algorithm::Argon2i, 0x13, 1 << 16, 2, 4).expect("valid");
        let mut out = [0u8; 24];
        hash(&params, b"password", b"somesalt", &mut out).expect("hash");
        let hex: String = out.iter().map(|b| format!("{b:02x}")).collect();
        assert_eq!(hex, "45d7ac72e76f242b20b77b9bf9bf9d5915894e669a24e6c6");
    }

    #[test]
    fn refuses_what_libargon2_refuses() {
        assert!(Params::new(Algorithm::Argon2id, 0x13, 7, 1, 1).is_none());
        assert!(Params::new(Algorithm::Argon2id, 0x13, 31, 1, 4).is_none());
        assert!(Params::new(Algorithm::Argon2id, 0x13, 1024, 0, 1).is_none());
        assert!(Params::new(Algorithm::Argon2id, 0x13, 1024, 1, 0).is_none());
        assert!(Params::new(Algorithm::Argon2id, 0x13, u32::MAX, 1, 1 << 24).is_none());
        assert!(Params::new(Algorithm::Argon2id, 0x12, 1024, 1, 1).is_none());
        let params = Params::new(Algorithm::Argon2id, 0x13, 1024, 1, 1).expect("valid");
        assert!(hash(&params, b"", b"short", &mut [0; 32]).is_none());
        assert!(hash(&params, b"", b"saltsalt", &mut [0; 3]).is_none());
    }
}
