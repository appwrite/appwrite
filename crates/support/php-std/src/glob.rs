//! Shell glob matching: PHP's `fnmatch()`.
//!
//! PHP calls the C library's `fnmatch(3)`. The runtimes this port is held
//! to run on Alpine Linux, so this is musl's implementation
//! (`src/regex/fnmatch.c`) in the `C.UTF-8` locale PHP runs in there:
//! characters are UTF-8 sequences, character classes are musl's Unicode
//! tables ([`classes`]), an invalid sequence in the pattern matches nothing
//! and one in the name only matches `?` or a negated bracket, as musl reads
//! them.
//!
//! | PHP | Rust |
//! |---|---|
//! | `fnmatch($pattern, $filename)` (no flags) | [`fnmatch`] |
//!
//! Checked against the real function by `bin/compat fuzz php-std`
//! (operation `glob.fnmatch`).

mod classes;

use crate::types::EngineError;

/// `MAXPATHLEN` on Linux: longer arguments are refused.
const MAXPATHLEN: usize = 4096;

/// A pattern token (musl's `pat_next`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Token {
    End,
    Unmatchable,
    Bracket,
    Question,
    Star,
    Char(i64),
}

/// musl's `mbtowc` on at most `n` bytes of `s`: the character and its
/// length, or `None` for an invalid (or truncated) sequence.
fn mbtowc(s: &[u8], n: usize) -> Option<(i64, usize)> {
    let first = *s.first()?;
    if first < 0x80 {
        return Some((i64::from(first), 1));
    }
    let len = match first {
        0xc2..=0xdf => 2,
        0xe0..=0xef => 3,
        0xf0..=0xf4 => 4,
        _ => return None,
    };
    if n < len || s.len() < len {
        return None;
    }
    let c = std::str::from_utf8(&s[..len]).ok()?.chars().next()?;
    Some((i64::from(u32::from(c)), len))
}

/// musl's `str_next`: the next character of the name (0 at its end, -1 for
/// an invalid byte) and how many bytes it spans.
fn str_next(s: &[u8]) -> (i64, usize) {
    match s.first() {
        None => (0, 0),
        Some(&b) if b < 0x80 => (i64::from(b), 1),
        Some(_) => match mbtowc(s, s.len()) {
            Some(c) => c,
            None => (-1, 1),
        },
    }
}

/// musl's `pat_next`: the next token of the pattern and how many bytes it
/// spans.
fn pat_next(pat: &[u8]) -> (Token, usize) {
    let Some(&first) = pat.first() else { return (Token::End, 0) };
    let m = pat.len();
    let (at, esc) = if first == b'\\' && m > 1 {
        (1, 1)
    } else {
        if first == b'[' {
            let mut k = 1;
            if k < m && (pat[k] == b'^' || pat[k] == b'!') {
                k += 1;
            }
            if k < m && pat[k] == b']' {
                k += 1;
            }
            while k < m && pat[k] != b']' {
                if k + 1 < m && pat[k] == b'[' && matches!(pat[k + 1], b':' | b'.' | b'=') {
                    let z = pat[k + 1];
                    k += 2;
                    if k < m {
                        k += 1;
                    }
                    while k < m && (pat[k - 1] != z || pat[k] != b']') {
                        k += 1;
                    }
                    if k == m {
                        break;
                    }
                }
                k += 1;
            }
            if k >= m {
                return (Token::Char(i64::from(b'[')), 1);
            }
            return (Token::Bracket, k + 1);
        }
        match first {
            b'*' => return (Token::Star, 1),
            b'?' => return (Token::Question, 1),
            _ => (0, 0),
        }
    };
    let c = pat[at];
    if c < 0x80 {
        return (Token::Char(i64::from(c)), at + 1);
    }
    match mbtowc(&pat[at..], m - at) {
        Some((wc, k)) => (Token::Char(wc), k + esc),
        None => (Token::Unmatchable, 0),
    }
}

/// Whether the class named `name` holds `k` (`iswctype(k, wctype(name))`).
fn in_class(name: &[u8], k: i64) -> bool {
    let Ok(k) = u32::try_from(k) else { return false };
    let table = match name {
        b"alnum" => classes::ALNUM,
        b"alpha" => classes::ALPHA,
        b"blank" => classes::BLANK,
        b"cntrl" => classes::CNTRL,
        b"digit" => classes::DIGIT,
        b"graph" => classes::GRAPH,
        b"lower" => classes::LOWER,
        b"print" => classes::PRINT,
        b"punct" => classes::PUNCT,
        b"space" => classes::SPACE,
        b"upper" => classes::UPPER,
        b"xdigit" => classes::XDIGIT,
        _ => return false,
    };
    table.binary_search_by(|&(lo, hi)| if hi < k { std::cmp::Ordering::Less } else if lo > k { std::cmp::Ordering::Greater } else { std::cmp::Ordering::Equal }).is_ok()
}

/// musl's `match_bracket`: whether the bracket expression at the start of
/// `p` (known to be closed) matches `k`.
fn match_bracket(p: &[u8], k: i64) -> bool {
    let at = |i: usize| p.get(i).copied().unwrap_or(0);
    let mut i = 1;
    let mut inv = false;
    if at(i) == b'^' || at(i) == b'!' {
        inv = true;
        i += 1;
    }
    if at(i) == b']' {
        if k == i64::from(b']') {
            return !inv;
        }
        i += 1;
    } else if at(i) == b'-' {
        if k == i64::from(b'-') {
            return !inv;
        }
        i += 1;
    }
    let mut wc = i64::from(at(i - 1));
    while at(i) != b']' {
        if at(i) == b'-' && at(i + 1) != b']' {
            let Some((wc2, l)) = mbtowc(&p[(i + 1).min(p.len())..], 4) else { return false };
            if wc <= wc2 {
                let span = (wc2 - wc) as u32;
                if (k.wrapping_sub(wc) as u32) <= span {
                    return !inv;
                }
            }
            i += l;
            continue;
        }
        if at(i) == b'[' && matches!(at(i + 1), b':' | b'.' | b'=') {
            let start = i + 2;
            let z = at(i + 1);
            i += 3;
            while at(i - 1) != z || at(i) != b']' {
                i += 1;
            }
            let name = &p[start..i - 1];
            if z == b':' && name.len() < 16 && in_class(name, k) {
                return !inv;
            }
            i += 1;
            continue;
        }
        if at(i) < 0x80 {
            wc = i64::from(at(i));
        } else {
            let Some((c, l)) = mbtowc(&p[i..], 4) else { return false };
            wc = c;
            i += l - 1;
        }
        if wc == k {
            return !inv;
        }
        i += 1;
    }
    inv
}

/// Whether a name character `k` matches the token at the start of `p`.
fn token_matches(token: Token, p: &[u8], k: i64) -> bool {
    match token {
        Token::Bracket => match_bracket(p, k),
        Token::Question => true,
        Token::Char(c) => c == k,
        _ => false,
    }
}

/// musl's `fnmatch_internal` with no flags.
fn matches(pat: &[u8], s: &[u8]) -> bool {
    let (mut pat, mut s) = (pat, s);
    // Characters up to the first star.
    loop {
        let (c, pinc) = pat_next(pat);
        match c {
            Token::Unmatchable => return false,
            Token::Star => {
                pat = &pat[1..];
                break;
            }
            _ => {
                let (k, sinc) = str_next(s);
                if k <= 0 {
                    return c == Token::End;
                }
                if !token_matches(c, pat, k) {
                    return false;
                }
                s = &s[sinc..];
                pat = &pat[pinc..];
            }
        }
    }

    // The last star, and how many characters the pattern needs after it.
    let mut ptail = 0;
    let mut tailcnt = 0usize;
    let mut i = 0;
    while i < pat.len() {
        let (c, pinc) = pat_next(&pat[i..]);
        match c {
            Token::Unmatchable => return false,
            Token::Star => {
                tailcnt = 0;
                ptail = i + 1;
            }
            _ => tailcnt += 1,
        }
        i += pinc;
    }
    if s.len() < tailcnt {
        return false;
    }

    // Step back over the last `tailcnt` characters of the name.
    let mut stail = s.len();
    while stail > 0 && tailcnt > 0 {
        if s[stail - 1] < 0x80 {
            stail -= 1;
        } else {
            loop {
                stail -= 1;
                if !(s[stail].wrapping_sub(0x80) < 0x40 && stail > 0) {
                    break;
                }
            }
        }
        tailcnt -= 1;
    }
    if tailcnt > 0 {
        return false;
    }

    // The tails must match.
    let mut p = ptail;
    let mut j = stail;
    loop {
        let (c, pinc) = pat_next(&pat[p..]);
        let token_at = p;
        p += pinc;
        let (k, sinc) = str_next(&s[j..]);
        if k <= 0 {
            if c != Token::End {
                return false;
            }
            break;
        }
        j += sinc;
        if !token_matches(c, &pat[token_at..], k) {
            return false;
        }
    }

    // The components between stars, leftmost first.
    let endpat = ptail;
    let endstr = stail;
    let (mut pi, mut si) = (0usize, 0usize);
    while pi < endpat {
        let mut p = pi;
        let mut sj = si;
        let mut star = false;
        loop {
            let (c, pinc) = pat_next(&pat[p..endpat]);
            let token_at = p;
            p += pinc;
            if c == Token::Star {
                pi = p;
                si = sj;
                star = true;
                break;
            }
            let (k, sinc) = str_next(&s[sj..endstr]);
            if k == 0 {
                return false;
            }
            if !token_matches(c, &pat[token_at..endpat], k) {
                break;
            }
            sj += sinc;
        }
        if star {
            continue;
        }
        let (k, sinc) = str_next(&s[si..endstr]);
        if k > 0 {
            si += sinc;
        } else {
            si += 1;
            while si <= endstr && str_next(&s[si..endstr]).0 < 0 {
                si += 1;
            }
        }
    }
    true
}

/// PHP `fnmatch($pattern, $filename)`: whether `filename` matches the shell
/// glob `pattern` (`*`, `?`, `[...]` with `!`/`^`, ranges and `[:class:]`,
/// `\` escapes; `*` crosses `/` and a leading `.` is not special).
///
/// A null byte in either argument is the `ValueError` PHP throws; an
/// argument of 4096 bytes or more does not match (PHP warns).
///
/// ```
/// use php_std::glob::fnmatch;
///
/// assert_eq!(fnmatch(b"v1.*", b"v1.2.0"), Ok(true));
/// assert_eq!(fnmatch(b"v?.0.0", b"v10.0.0"), Ok(false));
/// assert_eq!(fnmatch(b"[!a]*", b"beta"), Ok(true));
/// ```
pub fn fnmatch(pattern: &[u8], filename: &[u8]) -> Result<bool, EngineError> {
    if pattern.contains(&0) {
        return Err(EngineError::new("ValueError", "fnmatch(): Argument #1 ($pattern) must not contain any null bytes"));
    }
    if filename.contains(&0) {
        return Err(EngineError::new("ValueError", "fnmatch(): Argument #2 ($filename) must not contain any null bytes"));
    }
    if filename.len() >= MAXPATHLEN || pattern.len() >= MAXPATHLEN {
        return Ok(false);
    }
    Ok(matches(pattern, filename))
}

#[cfg(test)]
mod tests {
    use super::fnmatch;

    #[test]
    fn globs() {
        let cases: &[(&[u8], &[u8], bool)] = &[
            (b"*", b"", true),
            (b"", b"", true),
            (b"", b"a", false),
            (b"a*b", b"ab", true),
            (b"a*b*c", b"axxbyyc", true),
            (b"a*b*c", b"axxbyy", false),
            (b"v1.*", b"v1.0.0", true),
            (b"v1.*", b"v2.0.0", false),
            (b"[a-c]x", b"bx", true),
            (b"[!a-c]x", b"bx", false),
            (b"[]]", b"]", true),
            (b"[", b"[", true),
            (b"\\*", b"*", true),
            (b"\\*", b"a", false),
            (b"[[:digit:]]*", b"1abc", true),
            (b"*.txt", b"dir/a.txt", true),
            ("[[:alpha:]]".as_bytes(), "é".as_bytes(), true),
            (b"[[:alpha:]]", b"\xe9", false),
            (b"a", b"a\xff", true),
            (b"?", b"\xff", true),
        ];
        for (p, s, want) in cases {
            assert_eq!(fnmatch(p, s), Ok(*want), "{:?} {:?}", String::from_utf8_lossy(p), String::from_utf8_lossy(s));
        }
    }
}
