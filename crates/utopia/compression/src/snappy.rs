//! Snappy, a port of Google's C++ snappy 1.2.1.

use crate::{Algorithm, Compression, Error};

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
}

impl Algorithm for Snappy {
    fn name(&self) -> &str {
        Compression::SNAPPY
    }

    fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        Ok(compress(data))
    }

    fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        let Some((length, header)) = varint(data) else {
            return Err(Error::Codec("snappy_uncompress : output length error".into()));
        };
        decompress(&data[header..], length as usize)
            .ok_or_else(|| Error::Codec("snappy_uncompress : data error".into()))
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

fn load32(s: &[u8], i: usize) -> u32 {
    u32::from_le_bytes([s[i], s[i + 1], s[i + 2], s[i + 3]])
}

/// `CalculateTableSize()`: the next power of two, within 2^8..2^15.
fn table_size(input: usize) -> usize {
    input.clamp(1 << MIN_TABLE_BITS, 1 << MAX_TABLE_BITS).next_power_of_two()
}

/// `TableEntry()` with the portable multiplicative hash: the index of the
/// 16-bit slot for the 4 bytes `bytes`.
fn slot(bytes: u32, mask: u32) -> usize {
    const MAGIC: u32 = 0x1e35_a7bd;
    // The C++ table is addressed in bytes: `mask` is 2 * (size - 1).
    (((MAGIC.wrapping_mul(bytes) >> (31 - MAX_TABLE_BITS)) & mask) >> 1) as usize
}

/// `RawCompress()`: the varint length, then each fragment.
fn compress(input: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(32 + input.len() + input.len() / 6);
    let mut n = input.len() as u32;
    while n >= 0x80 {
        out.push(n as u8 | 0x80);
        n >>= 7;
    }
    out.push(n as u8);
    let mut table = vec![0u16; table_size(input.len().min(BLOCK_SIZE))];
    for fragment in input.chunks(BLOCK_SIZE) {
        let size = table_size(fragment.len());
        table[..size].fill(0);
        compress_fragment(fragment, &mut out, &mut table[..size]);
    }
    out
}

/// `EmitLiteral()`.
fn emit_literal(out: &mut Vec<u8>, literal: &[u8]) {
    let n = literal.len() - 1;
    if n < 60 {
        out.push(LITERAL | ((n as u8) << 2));
    } else {
        let count = (n.ilog2() as usize >> 3) + 1;
        out.push(LITERAL | (((59 + count) as u8) << 2));
        out.extend_from_slice(&(n as u32).to_le_bytes()[..count]);
    }
    out.extend_from_slice(literal);
}

/// `EmitCopyAtMost64()`.
fn emit_copy_at_most_64(out: &mut Vec<u8>, offset: usize, len: usize) {
    let (offset, len) = (offset as u32, len as u32);
    if len < 12 && offset < 2048 {
        out.push((COPY_1_BYTE_OFFSET + ((len - 4) << 2) + ((offset >> 8) << 5)) as u8);
        out.push(offset as u8);
    } else {
        out.push((COPY_2_BYTE_OFFSET + ((len - 1) << 2)) as u8);
        out.extend_from_slice(&(offset as u16).to_le_bytes());
    }
}

/// `EmitCopy()`: 64-byte copies, keeping at least 4 bytes for the last one.
fn emit_copy(out: &mut Vec<u8>, offset: usize, mut len: usize) {
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
/// by `end`.
fn match_length(s: &[u8], a: usize, b: usize, end: usize) -> usize {
    s[b..end].iter().zip(&s[a..]).take_while(|(x, y)| x == y).count()
}

/// `CompressFragment()`: greedy matching through a hash table of the last
/// position of each 4-byte string, skipping faster through data without
/// matches.
fn compress_fragment(input: &[u8], out: &mut Vec<u8>, table: &mut [u16]) {
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

/// `RawUncompress()`: decodes every tag; `None` unless the input ends on a
/// tag boundary with exactly `length` bytes written.
fn decompress(mut input: &[u8], length: usize) -> Option<Vec<u8>> {
    let mut out: Vec<u8> = Vec::with_capacity(length.min(input.len().saturating_mul(255)));
    while let Some((&tag, rest)) = input.split_first() {
        input = rest;
        match tag & 3 {
            0 => {
                let mut len = (tag >> 2) as usize + 1;
                if len > 60 {
                    let count = len - 60;
                    let (bytes, rest) = input.split_at_checked(count)?;
                    input = rest;
                    len = bytes.iter().rev().fold(0usize, |n, &b| (n << 8) | b as usize) + 1;
                }
                if len > length - out.len() {
                    return None;
                }
                let (literal, rest) = input.split_at_checked(len)?;
                input = rest;
                out.extend_from_slice(literal);
            }
            kind => {
                let (len, offset, extra) = match kind {
                    1 => {
                        let &b = input.first()?;
                        (((tag >> 2) & 7) as usize + 4, ((tag as usize >> 5) << 8) | b as usize, 1)
                    }
                    2 => {
                        let b = input.first_chunk::<2>()?;
                        ((tag >> 2) as usize + 1, u16::from_le_bytes(*b) as usize, 2)
                    }
                    _ => {
                        let b = input.first_chunk::<4>()?;
                        ((tag >> 2) as usize + 1, u32::from_le_bytes(*b) as usize, 4)
                    }
                };
                input = &input[extra..];
                if offset == 0 || offset > out.len() || len > length - out.len() {
                    return None;
                }
                let start = out.len() - offset;
                if offset >= len {
                    out.extend_from_within(start..start + len);
                } else {
                    for i in 0..len {
                        out.push(out[start + i]);
                    }
                }
            }
        }
    }
    (out.len() == length).then_some(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn known_outputs() {
        // snappy_compress() of PHP's ext-snappy.
        assert_eq!(compress(b""), b"\x00");
        assert_eq!(compress(b"a"), b"\x01\x00a");
        assert_eq!(compress(b"This is a demo string"), b"\x15\x50This is a demo string");
    }

    #[test]
    fn round_trips() {
        let mut data = Vec::new();
        for i in 0..200_000u32 {
            data.push((i.wrapping_mul(2_654_435_761) >> 29) as u8 + if i % 7 == 0 { b'a' } else { 0 });
        }
        for input in [&b"abcabcabcabcabcabcabcabc"[..], &data, &vec![0; 100_000]] {
            let compressed = compress(input);
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
