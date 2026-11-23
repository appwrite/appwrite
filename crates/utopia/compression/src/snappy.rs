//! Snappy, a port of Google's C++ snappy 1.2.1.

use crate::sink::spare;
use crate::{Algorithm, Compression, Error, Sink};

/// Snappy raw format: the uncompressed length as a varint, then literals
/// and copies.
///
/// The compressor is a port of C++ snappy 1.2.1's level 1 (the library
/// PHP's ext-snappy links), with its portable hash: output is byte for byte
/// `snappy_compress()`. The decompressor is as strict as snappy's: any
/// malformed tag, out-of-range copy or length mismatch fails.
///
/// ```
/// use utopia_compression::{Algorithm, Snappy};
///
/// let snappy = Snappy::new();
/// let body = snappy.compress(b"This is a demo string")?;
/// assert_eq!(body.len(), 23);
/// assert_eq!(snappy.decompress(&body)?, b"This is a demo string");
/// # Ok::<(), utopia_compression::Error>(())
/// ```
///
/// ```php
/// use Utopia\Compression\Algorithms\Snappy;
///
/// $snappy = new Snappy();
/// $body = $snappy->compress('This is a demo string');
/// echo strlen($body), "\n"; // 23
/// echo $snappy->decompress($body), "\n"; // This is a demo string
/// ```
///
/// Pure Rust, always available. PHP: `Utopia\Compression\Algorithms\Snappy`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct Snappy;

impl Snappy {
    /// Snappy.
    ///
    /// PHP: `new Snappy()`.
    pub const fn new() -> Self {
        Snappy
    }

    /// Always `true`: Snappy is pure Rust.
    ///
    /// PHP: `Snappy::isSupported()`, whether `snappy_compress()` exists.
    pub const fn is_supported() -> bool {
        true
    }

    /// [`Algorithm::compress`], appended to `out`: into room for snappy's
    /// `MaxCompressedLength()`, as `snappy_compress()` allocates it.
    pub fn compress_into<S: Sink + ?Sized>(&self, data: &[u8], out: &mut S) -> Result<(), Error> {
        let max = 32 + data.len() + data.len() / 6;
        let start = out.len();
        if !out.grow(start + max) {
            return Err(Error::Codec("snappy_compress : memory error".into()));
        }
        let (at, _) = spare(out);
        let mut writer = Writer { at, len: 0, capacity: max };
        compress(data, &mut writer);
        // SAFETY: the writer wrote `len` bytes, within the room made.
        unsafe { out.set_len(start + writer.len) };
        Ok(())
    }

    /// [`Algorithm::decompress`], appended to `out`: the length the input starts with is
    /// allocated, as `snappy_uncompress()` allocates it, and the input must decode to exactly
    /// that.
    pub fn decompress_into<S: Sink + ?Sized>(&self, data: &[u8], out: &mut S) -> Result<(), Error> {
        let Some((length, header)) = varint(data) else {
            return Err(Error::Codec("snappy_uncompress : output length error".into()));
        };
        let length = length as usize;
        let start = out.len();
        if !out.grow(start + length) {
            return Err(Error::Codec("snappy_uncompress : memory error".into()));
        }
        let (at, _) = spare(out);
        // SAFETY: `at` has room for `length` bytes.
        if !unsafe { decompress(&data[header..], at, length) } {
            return Err(Error::Codec("snappy_uncompress : data error".into()));
        }
        // SAFETY: decompress wrote exactly `length` bytes.
        unsafe { out.set_len(start + length) };
        Ok(())
    }
}

impl Algorithm for Snappy {
    fn name(&self) -> &str {
        Compression::SNAPPY
    }

    fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        let mut out = Vec::new();
        self.compress_into(data, &mut out)?;
        Ok(out)
    }

    fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        let mut out = Vec::new();
        self.decompress_into(data, &mut out)?;
        Ok(out)
    }

    fn supported(&self) -> bool {
        true
    }
}

/// `kBlockSize`: input is compressed in independent 64 KiB fragments.
const BLOCK_SIZE: usize = 1 << 16;
/// `kMinHashTableBits` and `kMaxHashTableBits`.
const MIN_TABLE_BITS: u32 = 8;
const MAX_TABLE_BITS: u32 = 15;
/// `kInputMarginBytes`: the main loop stops this close to the end.
const INPUT_MARGIN: usize = 15;

const LITERAL: u8 = 0;
const COPY_1_BYTE_OFFSET: u32 = 1;
const COPY_2_BYTE_OFFSET: u32 = 2;

/// Where the compressor writes: room for snappy's `MaxCompressedLength()`, which its output
/// never exceeds (a write past it panics rather than corrupting memory).
struct Writer {
    at: *mut u8,
    len: usize,
    capacity: usize,
}

impl Writer {
    #[inline(always)]
    fn push(&mut self, byte: u8) {
        assert!(self.len < self.capacity, "snappy output past MaxCompressedLength");
        // SAFETY: within the room checked above.
        unsafe { self.at.add(self.len).write(byte) };
        self.len += 1;
    }

    #[inline(always)]
    fn extend(&mut self, bytes: &[u8]) {
        assert!(bytes.len() <= self.capacity - self.len, "snappy output past MaxCompressedLength");
        // SAFETY: within the room checked above; the input never overlaps the output.
        unsafe { std::ptr::copy_nonoverlapping(bytes.as_ptr(), self.at.add(self.len), bytes.len()) };
        self.len += bytes.len();
    }
}

#[inline(always)]
fn load32(s: &[u8], i: usize) -> u32 {
    u32::from_le_bytes(s[i..i + 4].try_into().unwrap_or_default())
}

#[inline(always)]
fn load64(s: &[u8], i: usize) -> u64 {
    u64::from_le_bytes(s[i..i + 8].try_into().unwrap_or_default())
}

/// `CalculateTableSize()`: the next power of two, within 2^8..2^15.
fn table_size(input: usize) -> usize {
    input.clamp(1 << MIN_TABLE_BITS, 1 << MAX_TABLE_BITS).next_power_of_two()
}

/// `TableEntry()` with the portable multiplicative hash: the index of the
/// 16-bit slot for the 4 bytes `bytes`.
#[inline(always)]
fn slot(bytes: u32, mask: u32) -> usize {
    const MAGIC: u32 = 0x1e35_a7bd;
    // The C++ table is addressed in bytes: `mask` is 2 * (size - 1).
    (((MAGIC.wrapping_mul(bytes) >> (31 - MAX_TABLE_BITS)) & mask) >> 1) as usize
}

/// `RawCompress()`: the varint length, then each fragment.
fn compress(input: &[u8], out: &mut Writer) {
    let mut n = input.len() as u32;
    while n >= 0x80 {
        out.push(n as u8 | 0x80);
        n >>= 7;
    }
    out.push(n as u8);
    // The hash table, kept for the thread: only the part a fragment uses is cleared, so a small
    // input does not pay for the 64 KiB a full one needs.
    thread_local! {
        static TABLE: std::cell::Cell<Option<Box<[u16; 1 << MAX_TABLE_BITS]>>> = const { std::cell::Cell::new(None) };
    }
    let mut table = TABLE.with(std::cell::Cell::take).unwrap_or_else(|| Box::new([0; 1 << MAX_TABLE_BITS]));
    for fragment in input.chunks(BLOCK_SIZE) {
        let size = table_size(fragment.len());
        table[..size].fill(0);
        compress_fragment(fragment, out, &mut table[..size]);
    }
    TABLE.with(|slot| slot.set(Some(table)));
}

/// `EmitLiteral()`.
#[inline(always)]
fn emit_literal(out: &mut Writer, literal: &[u8]) {
    let n = literal.len() - 1;
    if n < 60 {
        out.push(LITERAL | ((n as u8) << 2));
    } else {
        let count = (n.ilog2() as usize >> 3) + 1;
        out.push(LITERAL | (((59 + count) as u8) << 2));
        out.extend(&(n as u32).to_le_bytes()[..count]);
    }
    out.extend(literal);
}

/// `EmitCopyAtMost64()`.
#[inline(always)]
fn emit_copy_at_most_64(out: &mut Writer, offset: usize, len: usize) {
    let (offset, len) = (offset as u32, len as u32);
    if len < 12 && offset < 2048 {
        out.push((COPY_1_BYTE_OFFSET + ((len - 4) << 2) + ((offset >> 8) << 5)) as u8);
        out.push(offset as u8);
    } else {
        out.push((COPY_2_BYTE_OFFSET + ((len - 1) << 2)) as u8);
        out.extend(&(offset as u16).to_le_bytes());
    }
}

/// `EmitCopy()`: 64-byte copies, keeping at least 4 bytes for the last one.
#[inline(always)]
fn emit_copy(out: &mut Writer, offset: usize, mut len: usize) {
    if len < 12 {
        return emit_copy_at_most_64(out, offset, len);
    }
    while len >= 68 {
        emit_copy_at_most_64(out, offset, 64);
        len -= 64;
    }
    if len > 64 {
        emit_copy_at_most_64(out, offset, 60);
        len -= 60;
    }
    emit_copy_at_most_64(out, offset, len);
}

/// `FindMatchLength()`: how many bytes from `a` and `b` agree, `b` bounded
/// by `end` (`a < b`), eight at a time.
#[inline(always)]
fn match_length(s: &[u8], a: usize, b: usize, end: usize) -> usize {
    let mut matched = 0;
    while b + matched + 8 <= end {
        let diff = load64(s, a + matched) ^ load64(s, b + matched);
        if diff != 0 {
            return matched + (diff.trailing_zeros() / 8) as usize;
        }
        matched += 8;
    }
    while b + matched < end && s[a + matched] == s[b + matched] {
        matched += 1;
    }
    matched
}

/// `CompressFragment()`: greedy matching through a hash table of the last
/// position of each 4-byte string, skipping faster through data without
/// matches.
fn compress_fragment(input: &[u8], out: &mut Writer, table: &mut [u16]) {
    let mask = 2 * (table.len() as u32 - 1);
    let end = input.len();
    let mut ip = 0;
    if end >= INPUT_MARGIN {
        let limit = end - INPUT_MARGIN;
        'outer: loop {
            // Bytes in [next_emit, ip) are emitted as a literal before the
            // next copy.
            let next_emit = ip;
            ip += 1;
            let mut skip = 32u32;
            let mut candidate;
            // The unrolled first 16 probes: a match there takes a short
            // literal straight away.
            'scan: {
                if limit >= ip + 16 {
                    for i in 0..16 {
                        let bytes = load32(input, ip + i);
                        let entry = &mut table[slot(bytes, mask)];
                        candidate = *entry as usize;
                        *entry = (ip + i) as u16;
                        if load32(input, candidate) == bytes {
                            ip += i;
                            break 'scan;
                        }
                    }
                    ip += 16;
                    skip += 16;
                }
                loop {
                    let bytes = load32(input, ip);
                    let index = slot(bytes, mask);
                    let step = (skip >> 5) as usize;
                    skip += step as u32;
                    let next = ip + step;
                    if next > limit {
                        ip = next_emit;
                        break 'outer;
                    }
                    candidate = table[index] as usize;
                    table[index] = ip as u16;
                    if load32(input, candidate) == bytes {
                        break 'scan;
                    }
                    ip = next;
                }
            }
            emit_literal(out, &input[next_emit..ip]);
            // Copies, as long as the bytes after the last one match again.
            loop {
                let base = ip;
                let matched = 4 + match_length(input, candidate + 4, ip + 4, end);
                ip += matched;
                emit_copy(out, base - candidate, matched);
                if ip >= limit {
                    break 'outer;
                }
                table[slot(load32(input, ip - 1), mask)] = (ip - 1) as u16;
                let bytes = load32(input, ip);
                let entry = &mut table[slot(bytes, mask)];
                candidate = *entry as usize;
                *entry = ip as u16;
                if bytes != load32(input, candidate) {
                    break;
                }
            }
        }
    }
    if ip < end {
        emit_literal(out, &input[ip..]);
    }
}

/// `Varint::Parse32WithLimit()`: the uncompressed length and its size in
/// bytes; at most 5 bytes, the last below 16.
fn varint(data: &[u8]) -> Option<(u32, usize)> {
    let mut result = 0u32;
    for (i, &b) in data.iter().enumerate().take(5) {
        if i == 4 {
            return (b < 16).then(|| (result | (u32::from(b) << 28), 5));
        }
        result |= u32::from(b & 0x7f) << (7 * i);
        if b < 0x80 {
            return Some((result, i + 1));
        }
    }
    None
}

/// `RawUncompress()`: decodes every tag into `out`; `false` unless the input ends on a tag
/// boundary with exactly `length` bytes written.
///
/// # Safety
///
/// `out` has room for `length` bytes.
unsafe fn decompress(mut input: &[u8], out: *mut u8, length: usize) -> bool {
    let mut op = 0;
    while let Some((&tag, rest)) = input.split_first() {
        input = rest;
        match tag & 3 {
            0 => {
                let mut len = (tag >> 2) as usize + 1;
                if len > 60 {
                    let count = len - 60;
                    let Some((bytes, rest)) = input.split_at_checked(count) else { return false };
                    input = rest;
                    len = bytes.iter().rev().fold(0usize, |n, &b| (n << 8) | b as usize) + 1;
                }
                if len > length - op {
                    return false;
                }
                let Some((literal, rest)) = input.split_at_checked(len) else { return false };
                input = rest;
                // SAFETY: `len` more bytes fit in `length`; the input never overlaps the output.
                unsafe { std::ptr::copy_nonoverlapping(literal.as_ptr(), out.add(op), len) };
                op += len;
            }
            kind => {
                let (len, offset, extra) = match kind {
                    1 => {
                        let Some(&b) = input.first() else { return false };
                        (((tag >> 2) & 7) as usize + 4, ((tag as usize >> 5) << 8) | b as usize, 1)
                    }
                    2 => {
                        let Some(b) = input.first_chunk::<2>() else { return false };
                        ((tag >> 2) as usize + 1, u16::from_le_bytes(*b) as usize, 2)
                    }
                    _ => {
                        let Some(b) = input.first_chunk::<4>() else { return false };
                        ((tag >> 2) as usize + 1, u32::from_le_bytes(*b) as usize, 4)
                    }
                };
                input = &input[extra..];
                if offset == 0 || offset > op || len > length - op {
                    return false;
                }
                // SAFETY: the copy reads `offset` back from `op`, within what was written, and
                // writes `len` bytes that fit in `length`. A copy longer than its offset repeats
                // the bytes it writes, so it goes one offset's worth (or byte) at a time.
                unsafe {
                    let from = out.add(op - offset);
                    let to = out.add(op);
                    if offset >= len {
                        std::ptr::copy_nonoverlapping(from, to, len);
                    } else if offset >= 8 {
                        let mut done = 0;
                        while done < len {
                            let step = offset.min(len - done);
                            std::ptr::copy_nonoverlapping(from.add(done), to.add(done), step);
                            done += step;
                        }
                    } else {
                        for i in 0..len {
                            *to.add(i) = *from.add(i);
                        }
                    }
                }
                op += len;
            }
        }
    }
    op == length
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn known_outputs() {
        // snappy_compress() of PHP's ext-snappy.
        assert_eq!(Snappy.compress(b"").unwrap(), b"\x00");
        assert_eq!(Snappy.compress(b"a").unwrap(), b"\x01\x00a");
        assert_eq!(Snappy.compress(b"This is a demo string").unwrap(), b"\x15\x50This is a demo string");
    }

    #[test]
    fn round_trips() {
        let mut data = Vec::new();
        for i in 0..200_000u32 {
            data.push((i.wrapping_mul(2_654_435_761) >> 29) as u8 + if i % 7 == 0 { b'a' } else { 0 });
        }
        for input in [&b"abcabcabcabcabcabcabcabc"[..], &data, &vec![0; 100_000], &b"0123456789".repeat(9000)] {
            let compressed = Snappy.compress(input).unwrap();
            assert_eq!(Snappy.decompress(&compressed).unwrap(), input);
        }
    }

    #[test]
    fn invalid_data() {
        assert_eq!(Snappy.decompress(b""), Err(Error::Codec("snappy_uncompress : output length error".into())));
        assert_eq!(Snappy.decompress(b"garbage"), Err(Error::Codec("snappy_uncompress : data error".into())));
        assert_eq!(Snappy.decompress(b"\x01\x05\x01"), Err(Error::Codec("snappy_uncompress : data error".into())));
    }
}
