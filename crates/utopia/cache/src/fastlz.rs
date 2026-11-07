//! FastLZ (0.1), the compression the Memcached extension applies to large
//! values: level 1 below 64 KiB, level 2 above, byte for byte.

const MAX_COPY: usize = 32;
const MAX_LEN: usize = 264;
const MAX_DISTANCE: usize = 8192;
const MAX_FARDISTANCE: usize = 65535 + MAX_DISTANCE - 1;
const HASH_LOG: u32 = 13;
const HASH_MASK: usize = (1 << HASH_LOG) - 1;

fn read16(input: &[u8], at: usize) -> usize {
    input[at] as usize | (input[at + 1] as usize) << 8
}

fn hash(input: &[u8], at: usize) -> usize {
    let v = read16(input, at);
    (v ^ read16(input, at + 1) ^ (v >> (16 - HASH_LOG))) & HASH_MASK
}

/// `fastlz_compress`: level 1 for inputs under 65536 bytes, level 2 above.
pub fn compress(input: &[u8]) -> Vec<u8> {
    compress_level(input, if input.len() < 65536 { 1 } else { 2 })
}

fn compress_level(input: &[u8], level: u8) -> Vec<u8> {
    let length = input.len();
    let mut out = Vec::with_capacity(length + length / 16 + 8);
    if length < 4 {
        if length > 0 {
            out.push((length - 1) as u8);
            out.extend_from_slice(input);
        }
        return out;
    }
    let ip_bound = length - 2;
    let ip_limit = length as isize - 12;
    let mut htab = vec![0usize; 1 << HASH_LOG];
    let mut copy: usize = 2;
    out.push((MAX_COPY - 1) as u8);
    out.push(input[0]);
    out.push(input[1]);
    let mut ip = 2usize;
    while (ip as isize) < ip_limit {
        let anchor = ip;
        let mut len = 3usize;
        let mut reference;
        let mut distance;
        let run = level == 2 && input[ip] == input[ip - 1] && read16(input, ip - 1) == read16(input, ip + 1);
        if run {
            distance = 1;
            reference = anchor - 1 + 3;
        } else {
            let hv = hash(input, ip);
            let candidate = htab[hv];
            distance = anchor - candidate;
            htab[hv] = anchor;
            let far = if level == 1 { MAX_DISTANCE } else { MAX_FARDISTANCE };
            let mut matched = distance != 0
                && distance < far
                && input[candidate] == input[ip]
                && input[candidate + 1] == input[ip + 1]
                && input[candidate + 2] == input[ip + 2];
            reference = candidate + 3;
            if matched && level == 2 && distance >= MAX_DISTANCE {
                matched = input[ip + 3] == input[reference] && input[ip + 4] == input[reference + 1];
                if matched {
                    reference += 2;
                    len += 2;
                }
            }
            if !matched {
                out.push(input[anchor]);
                ip = anchor + 1;
                copy += 1;
                if copy == MAX_COPY {
                    copy = 0;
                    out.push((MAX_COPY - 1) as u8);
                }
                continue;
            }
        }
        ip = anchor + len;
        distance -= 1;
        if distance == 0 {
            let x = input[ip - 1];
            while ip < ip_bound {
                let same = input[reference] == x;
                reference += 1;
                if !same {
                    break;
                }
                ip += 1;
            }
        } else {
            let mut mismatch = false;
            for _ in 0..8 {
                let differs = input[reference] != input[ip];
                reference += 1;
                ip += 1;
                if differs {
                    mismatch = true;
                    break;
                }
            }
            if !mismatch {
                while ip < ip_bound {
                    let differs = input[reference] != input[ip];
                    reference += 1;
                    ip += 1;
                    if differs {
                        break;
                    }
                }
            }
        }
        if copy > 0 {
            let at = out.len() - copy - 1;
            out[at] = (copy - 1) as u8;
        } else {
            out.pop();
        }
        copy = 0;
        ip -= 3;
        let mut len = ip - anchor;
        if level == 2 {
            if distance < MAX_DISTANCE {
                if len < 7 {
                    out.push(((len << 5) + (distance >> 8)) as u8);
                    out.push((distance & 255) as u8);
                } else {
                    out.push(((7 << 5) + (distance >> 8)) as u8);
                    len -= 7;
                    while len >= 255 {
                        out.push(255);
                        len -= 255;
                    }
                    out.push(len as u8);
                    out.push((distance & 255) as u8);
                }
            } else {
                let far = distance - MAX_DISTANCE;
                if len < 7 {
                    out.push(((len << 5) + 31) as u8);
                    out.push(255);
                } else {
                    out.push(((7 << 5) + 31) as u8);
                    len -= 7;
                    while len >= 255 {
                        out.push(255);
                        len -= 255;
                    }
                    out.push(len as u8);
                    out.push(255);
                }
                out.push((far >> 8) as u8);
                out.push((far & 255) as u8);
            }
        } else {
            while len > MAX_LEN - 2 {
                out.push(((7 << 5) + (distance >> 8)) as u8);
                out.push((MAX_LEN - 2 - 7 - 2) as u8);
                out.push((distance & 255) as u8);
                len -= MAX_LEN - 2;
            }
            if len < 7 {
                out.push(((len << 5) + (distance >> 8)) as u8);
                out.push((distance & 255) as u8);
            } else {
                out.push(((7 << 5) + (distance >> 8)) as u8);
                out.push((len - 7) as u8);
                out.push((distance & 255) as u8);
            }
        }
        htab[hash(input, ip)] = ip;
        ip += 1;
        htab[hash(input, ip)] = ip;
        ip += 1;
        out.push((MAX_COPY - 1) as u8);
    }
    while ip <= ip_bound + 1 {
        out.push(input[ip]);
        ip += 1;
        copy += 1;
        if copy == MAX_COPY {
            copy = 0;
            out.push((MAX_COPY - 1) as u8);
        }
    }
    if copy > 0 {
        let at = out.len() - copy - 1;
        out[at] = (copy - 1) as u8;
    } else {
        out.pop();
    }
    if level == 2 {
        out[0] |= 1 << 5;
    }
    out
}

/// `fastlz_decompress` into at most `max` bytes; `None` on corrupt input.
pub fn decompress(input: &[u8], max: usize) -> Option<Vec<u8>> {
    if input.is_empty() {
        return Some(Vec::new());
    }
    let level = (input[0] >> 5) + 1;
    if level > 2 {
        return None;
    }
    let mut out: Vec<u8> = Vec::with_capacity(max);
    let mut ip = 0usize;
    let mut ctrl = (input[ip] & 31) as usize;
    ip += 1;
    loop {
        if ctrl >= 32 {
            let mut len = (ctrl >> 5) - 1;
            let mut ofs = (ctrl & 31) << 8;
            if len == 7 - 1 {
                if level == 1 {
                    len += *input.get(ip)? as usize;
                    ip += 1;
                } else {
                    loop {
                        let code = *input.get(ip)? as usize;
                        ip += 1;
                        len += code;
                        if code != 255 {
                            break;
                        }
                    }
                }
            }
            let code = *input.get(ip)? as usize;
            ip += 1;
            let mut back = ofs + code;
            if level == 2 && code == 255 && ofs == 31 << 8 {
                ofs = (*input.get(ip)? as usize) << 8;
                ofs += *input.get(ip + 1)? as usize;
                ip += 2;
                back = ofs + MAX_DISTANCE;
            }
            let start = out.len().checked_sub(back + 1)?;
            if out.len() + len + 3 > max {
                return None;
            }
            for i in 0..len + 3 {
                let b = out[start + i];
                out.push(b);
            }
        } else {
            let count = ctrl + 1;
            let literal = input.get(ip..ip + count)?;
            if out.len() + count > max {
                return None;
            }
            out.extend_from_slice(literal);
            ip += count;
        }
        if ip >= input.len() {
            break;
        }
        ctrl = input[ip] as usize;
        ip += 1;
    }
    Some(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn matches_the_extension() {
        // php-memcached 3.4 storing str_repeat('abcdefgh', 400).
        let input = b"abcdefgh".repeat(400);
        let compressed = compress(&input);
        let mut expected = vec![0x07];
        expected.extend_from_slice(b"abcdefgh");
        for _ in 0..12 {
            expected.extend_from_slice(&[0xe0, 0xfd, 0x07]);
        }
        expected.extend_from_slice(&[0xe0, 0x24, 0x07, 0x02, b'f', b'g', b'h']);
        assert_eq!(compressed, expected);
        assert_eq!(decompress(&compressed, input.len()).as_deref(), Some(&input[..]));
    }

    #[test]
    fn round_trips() {
        let mut seed = 7u64;
        for size in [0, 1, 3, 4, 13, 100, 5000, 70000, 140000] {
            let input: Vec<u8> = (0..size)
                .map(|i| {
                    seed = seed.wrapping_mul(6364136223846793005).wrapping_add(1442695040888963407);
                    if i % 7 < 4 { b'a' + (seed >> 60) as u8 } else { (seed >> 56) as u8 }
                })
                .collect();
            let compressed = compress(&input);
            assert_eq!(decompress(&compressed, input.len()).as_deref(), Some(&input[..]), "size {size}");
        }
    }
}
