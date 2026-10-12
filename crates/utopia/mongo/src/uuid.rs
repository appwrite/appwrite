//! UUID version 7 (RFC 9562) ids for `_id`: the time in milliseconds, then
//! random bits that grow monotonically within a millisecond, so ids made
//! one after the other sort in the order they were made, as
//! `ramsey/uuid`'s `Uuid::uuid7()` makes them.

use std::sync::Mutex;
use std::time::{SystemTime, UNIX_EPOCH};

/// The last id's millisecond and random part (74 bits: 10 then 64).
static LAST: Mutex<(u64, u128)> = Mutex::new((0, 0));

/// The random part's room: 10 bits of `rand_a`, 64 of `rand_b`.
const ROOM: u128 = 1 << 74;

fn random<const N: usize>() -> [u8; N] {
    let mut bytes = [0u8; N];
    // An exhausted entropy source leaves zeros: ids stay unique through the
    // monotonic increment, only less random.
    let _ = getrandom::fill(&mut bytes);
    bytes
}

/// A fresh random part: 10 random bits of `rand_a` (two more stay free
/// for increments, as `ramsey/uuid` masks them) and 64 of `rand_b`.
fn randomize() -> u128 {
    let b = random::<10>();
    let a = u128::from(u16::from_be_bytes([b[0], b[1]]) & 0x03ff);
    let rest = u128::from(u64::from_be_bytes([b[2], b[3], b[4], b[5], b[6], b[7], b[8], b[9]]));
    (a << 64) | rest
}

/// A new UUIDv7 in its 36-character form.
pub(crate) fn uuid7() -> String {
    let now = SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |d| d.as_millis() as u64);
    let (ms, rand) = {
        let mut last = LAST.lock().unwrap_or_else(|e| e.into_inner());
        if now > last.0 {
            *last = (now, randomize());
        } else {
            // Same (or an earlier) millisecond: step forward by 1 plus up
            // to 24 random bits; past the room, the next millisecond.
            let step = random::<3>();
            let step = 1 + u128::from(u32::from_be_bytes([0, step[0], step[1], step[2]]));
            let next = last.1 + step;
            *last = if next >= ROOM { (last.0 + 1, randomize()) } else { (last.0, next) };
        }
        *last
    };
    let mut bytes = [0u8; 16];
    bytes[..6].copy_from_slice(&ms.to_be_bytes()[2..]);
    bytes[6..8].copy_from_slice(&((rand >> 64) as u16).to_be_bytes());
    bytes[8..].copy_from_slice(&(rand as u64).to_be_bytes());
    bytes[6] = (bytes[6] & 0x0f) | 0x70;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    let hex = hex::encode(bytes);
    format!("{}-{}-{}-{}-{}", &hex[..8], &hex[8..12], &hex[12..16], &hex[16..20], &hex[20..])
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn version_variant_and_order() {
        let ids: Vec<String> = (0..2000).map(|_| uuid7()).collect();
        for id in &ids {
            assert_eq!(id.len(), 36);
            assert_eq!(&id[14..15], "7");
            assert!(matches!(&id[19..20], "8" | "9" | "a" | "b"), "{id}");
        }
        for pair in ids.windows(2) {
            assert!(pair[0] < pair[1], "{} !< {}", pair[0], pair[1]);
        }
    }
}
