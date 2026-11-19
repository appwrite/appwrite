// The hash table of a domain list, shared by `build.rs` (which builds the embedded
// lists' tables at compile time) and `lists.rs` (which builds a parsed list's at
// run time and looks domains up in both). Included with `include!`, so it uses
// nothing outside `core`/`std`.

/// The slot of an empty table entry.
pub(crate) const EMPTY: u32 = u32::MAX;

/// FNV-1a over the domain's bytes: deterministic, so a table built by `build.rs`
/// is read with the same hash at run time.
pub(crate) fn hash(bytes: &[u8]) -> u64 {
    let mut hash: u64 = 0xcbf2_9ce4_8422_2325;
    for &byte in bytes {
        hash ^= u64::from(byte);
        hash = hash.wrapping_mul(0x0000_0100_0000_01b3);
    }
    hash
}

/// The line of `source` that starts at `offset`, without its newline.
pub(crate) fn line(source: &[u8], offset: u32) -> &[u8] {
    let rest = &source[offset as usize..];
    &rest[..rest.iter().position(|&b| b == b'\n').unwrap_or(rest.len())]
}

/// An open-addressing table of `source`'s distinct lines (one domain per line):
/// each slot holds a line's byte offset, or [`EMPTY`]. At most half full, so a
/// lookup probes one or two slots. Returns the table and its number of entries.
pub(crate) fn build(source: &[u8]) -> (Vec<u32>, usize) {
    let lines = source.split(|&b| b == b'\n').filter(|l| !l.is_empty()).count();
    let size = (lines.max(1) * 2).next_power_of_two();
    let mask = size - 1;
    let mut table = vec![EMPTY; size];
    let mut entries = 0;
    let mut offset = 0;
    for chunk in source.split_inclusive(|&b| b == b'\n') {
        let start = u32::try_from(offset).expect("a list under 4 GiB");
        offset += chunk.len();
        let domain = line(source, start);
        if domain.is_empty() {
            continue;
        }
        let mut slot = hash(domain) as usize & mask;
        loop {
            match table[slot] {
                EMPTY => {
                    table[slot] = start;
                    entries += 1;
                    break;
                }
                taken if line(source, taken) == domain => break,
                _ => slot = (slot + 1) & mask,
            }
        }
    }
    (table, entries)
}

/// Whether `domain` is in `table`, built by [`build`] over `source`.
pub(crate) fn contains(source: &[u8], table: &[u32], domain: &[u8]) -> bool {
    if table.is_empty() {
        return false;
    }
    let mask = table.len() - 1;
    let mut slot = hash(domain) as usize & mask;
    loop {
        match table[slot] {
            EMPTY => return false,
            offset if line(source, offset) == domain => return true,
            _ => slot = (slot + 1) & mask,
        }
    }
}
