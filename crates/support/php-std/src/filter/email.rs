//! `FILTER_VALIDATE_EMAIL` without its regular expression.
//!
//! `php_filter_validate_email()` matches the address (at most 320 bytes)
//! against one of two fixed regular expressions ([`EMAIL`], or
//! [`EMAIL_UNICODE`] with `FILTER_FLAG_EMAIL_UNICODE`). Every part of them
//! is deterministic except two lookaheads, so this module checks the same
//! language directly, one character at a time (a byte, or a code point for
//! the `/u` expression):
//!
//! - `(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})`
//!   and the same with `{65,}@`: the units of these lookaheads are a
//!   character other than `\` and `"`, or `\` and a character up to `\x7E`,
//!   each with an optional `"` on both sides. Where each unit starts is
//!   forced, so the address is refused when 255 units follow each other
//!   from the start, or 65 or more are followed by `@` ([`units_refuse`]).
//! - the local part: dot-separated words, each an atom or a quoted string.
//! - the domain: dot-separated labels of letters, digits and inner hyphens,
//!   none of 64 characters or more, ending in one starting with a letter;
//!   or an address literal (`[IPv4]`, `[IPv6:...]` with at most 6 groups
//!   around `::`, `[IPv6:...:IPv4]` with at most 4).
//!
//! The expressions are case-insensitive: with `/u`, PCRE2 also matches `K`
//! with U+212A KELVIN SIGN and `S` with U+017F LATIN SMALL LETTER LONG S, and
//! `\pL`/`\pN` follow PCRE2's Unicode 15.0.0 tables.
//!
//! The one thing a direct check cannot see is PCRE2 running out of
//! `pcre.backtrack_limit`, which fails the validation. Only the lookaheads
//! backtrack enough to get there, by trying both sides for each `"` between
//! two units: an address with more than [`MAX_QUOTES`] double quotes runs
//! the regular expression itself (see [`validate`]).

use crate::pcre;

/// `regexp0`: the expression with `FILTER_FLAG_EMAIL_UNICODE`.
pub(super) const EMAIL_UNICODE: &[u8] = br#"/^(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E\pL\pN]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F\pL\pN]|(?:\x5C[\x00-\x7F]))*\x22))(?:\.(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E\pL\pN]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F\pL\pN]|(?:\x5C[\x00-\x7F]))*\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\]))$/iDu"#;

/// `regexp1`: the expression without `FILTER_FLAG_EMAIL_UNICODE`.
pub(super) const EMAIL: &[u8] = br#"/^(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22))(?:\.(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\]))$/iD"#;

/// The most double quotes an address may hold for [`validate`] to decide
/// it. The lookaheads try both sides of each `"` between two units, so
/// PCRE2's work doubles with each one: at 320 bytes and 6 quotes it stays
/// under a fifth of `pcre.backtrack_limit` (about 50,000 steps for a
/// 200-byte address), while 10 quotes reach the limit.
pub(super) const MAX_QUOTES: usize = 6;

/// The longest address PHP checks (`Z_STRLEN_P(value) > 320` fails).
pub(super) const MAX_LENGTH: usize = 320;

/// Whether `s` (at most [`MAX_LENGTH`] bytes) matches the expression, or
/// `None` when only the expression itself can tell (more than
/// [`MAX_QUOTES`] double quotes).
pub(super) fn validate(s: &[u8], unicode: bool) -> Option<bool> {
    debug_assert!(s.len() <= MAX_LENGTH);
    if s.iter().filter(|&&b| b == b'"').count() > MAX_QUOTES {
        return None;
    }
    let mut buf = [0u32; MAX_LENGTH];
    let n = if unicode {
        // pcre2_match() checks the subject first: invalid UTF-8 fails.
        let Ok(text) = std::str::from_utf8(s) else { return Some(false) };
        let mut n = 0;
        for c in text.chars() {
            buf[n] = c as u32;
            n += 1;
        }
        n
    } else {
        for (slot, &b) in buf.iter_mut().zip(s) {
            *slot = u32::from(b);
        }
        s.len()
    };
    Some(Address { s: &buf[..n], unicode }.valid())
}

const QUOTE: u32 = b'"' as u32;
const BACKSLASH: u32 = b'\\' as u32;
const DOT: u32 = b'.' as u32;
const AT: u32 = b'@' as u32;
const COLON: u32 = b':' as u32;
const HYPHEN: u32 = b'-' as u32;
const KELVIN: u32 = 0x212a;
const LONG_S: u32 = 0x17f;

/// An address as characters: bytes, or code points with `/u`.
struct Address<'a> {
    s: &'a [u32],
    unicode: bool,
}

impl Address<'_> {
    fn at(&self, i: usize) -> Option<u32> {
        self.s.get(i).copied()
    }

    /// A character that only matches an ASCII letter caselessly in UTF
    /// mode: `K` U+212A and `ſ` U+017F.
    fn folds_to_ascii(&self, c: u32) -> bool {
        self.unicode && (c == KELVIN || c == LONG_S)
    }

    /// `\pL` and `\pN` beyond ASCII (ASCII letters and digits are in every
    /// class that has them).
    fn letter_or_number(&self, c: u32) -> bool {
        self.unicode && c >= 0x80 && pcre::is_letter_or_number(c)
    }

    /// `[\x00-\x7E]` (the lookaheads' escaped character).
    fn escaped_unit(&self, c: u32) -> bool {
        c <= 0x7e || self.folds_to_ascii(c)
    }

    /// `[\x00-\x7F]` (a quoted string's escaped character).
    fn escaped(&self, c: u32) -> bool {
        c <= 0x7f || self.folds_to_ascii(c)
    }

    /// `[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E\pL\pN]`,
    /// caseless (so with `A-Z`).
    fn atom(&self, c: u32) -> bool {
        matches!(c, 0x21 | 0x23..=0x27 | 0x2a | 0x2b | 0x2d | 0x2f..=0x39 | 0x3d | 0x3f | 0x41..=0x5a | 0x5e..=0x7e)
            || self.letter_or_number(c)
    }

    /// `[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F\pL\pN]`.
    fn quoted(&self, c: u32) -> bool {
        matches!(c, 0x01..=0x08 | 0x0b | 0x0c | 0x0e..=0x1f | 0x21 | 0x23..=0x5b | 0x5d..=0x7f)
            || self.letter_or_number(c)
    }

    /// `[a-z0-9]`, caseless.
    fn alnum(&self, c: u32) -> bool {
        u8::try_from(c).is_ok_and(|b| b.is_ascii_alphanumeric()) || self.folds_to_ascii(c)
    }

    /// `[a-z]`, caseless.
    fn alpha(&self, c: u32) -> bool {
        u8::try_from(c).is_ok_and(|b| b.is_ascii_alphabetic()) || self.folds_to_ascii(c)
    }

    fn valid(&self) -> bool {
        if self.units_refuse() {
            return false;
        }
        let Some(at) = self.local_part() else { return false };
        let domain = &self.s[at + 1..];
        if domain.first() == Some(&(b'[' as u32)) { self.literal(domain) } else { self.hostname(domain) }
    }

    /// The two lookaheads at the start: whether 255 units follow each
    /// other, or 65 or more are followed by `@`. A unit's core (a character
    /// other than `\` and `"`, or `\` and its escaped character) is where
    /// it must be; a run of quotes between two cores is the first unit's
    /// trailing quote and the next one's leading quote, so more than two
    /// end the units (more than one before the first).
    fn units_refuse(&self) -> bool {
        let quotes = |from: usize| self.s[from.min(self.s.len())..].iter().take_while(|&&c| c == QUOTE).count();
        let mut pos = quotes(0);
        if pos > 1 {
            return false;
        }
        let mut units = 0;
        loop {
            let Some(c) = self.at(pos) else { return false };
            let end = if c == BACKSLASH {
                match self.at(pos + 1) {
                    Some(e) if self.escaped_unit(e) => pos + 2,
                    _ => return false,
                }
            } else {
                pos + 1
            };
            units += 1;
            if units >= 255 {
                return true;
            }
            if units >= 65
                && (self.at(end) == Some(AT) || (self.at(end) == Some(QUOTE) && self.at(end + 1) == Some(AT)))
            {
                return true;
            }
            let run = quotes(end);
            if run > 2 {
                return false;
            }
            pos = end + run;
        }
    }

    /// The local part: words (atoms or quoted strings) separated by dots,
    /// up to the `@`, whose index it returns.
    fn local_part(&self) -> Option<usize> {
        let mut pos = 0;
        loop {
            if self.at(pos) == Some(QUOTE) {
                pos += 1;
                loop {
                    let c = self.at(pos)?;
                    if c == QUOTE {
                        pos += 1;
                        break;
                    } else if c == BACKSLASH {
                        if !self.escaped(self.at(pos + 1)?) {
                            return None;
                        }
                        pos += 2;
                    } else if self.quoted(c) {
                        pos += 1;
                    } else {
                        return None;
                    }
                }
            } else {
                let start = pos;
                while self.at(pos).is_some_and(|c| self.atom(c)) {
                    pos += 1;
                }
                if pos == start {
                    return None;
                }
            }
            match self.at(pos)? {
                DOT => pos += 1,
                AT => return Some(pos),
                _ => return None,
            }
        }
    }

    /// The host name: two or more labels, each of letters and digits with
    /// hyphens only inside, shorter than 64 characters (the lookahead
    /// `(?!.*[^.]{64,})`), the last starting with a letter.
    fn hostname(&self, d: &[u32]) -> bool {
        let mut labels = 0;
        for label in d.split(|&c| c == DOT) {
            let (Some(&first), Some(&last)) = (label.first(), label.last()) else { return false };
            if label.len() >= 64
                || !self.alnum(first)
                || !self.alnum(last)
                || !label.iter().all(|&c| c == HYPHEN || self.alnum(c))
            {
                return false;
            }
            labels += 1;
        }
        labels >= 2 && d.iter().rposition(|&c| c == DOT).is_some_and(|i| self.alpha(d[i + 1]))
    }

    /// The address literal `[...]`.
    fn literal(&self, d: &[u32]) -> bool {
        if d.len() < 2 || d[0] != u32::from(b'[') || d[d.len() - 1] != u32::from(b']') {
            return false;
        }
        let inner = &d[1..d.len() - 1];
        let Some(rest) = strip_ipv6_tag(inner) else { return ipv4(inner) };
        // `IPv6:` and an IPv6 address: eight groups, or at most six around
        // `::` (the lookahead also sees the final `]`).
        if groups(rest, 8, 8) {
            return true;
        }
        if let Some((left, right)) = split_compressed(rest)
            && groups(left, 0, 6)
            && groups(right, 0, 6)
            && ends_of_groups(rest, true) < 7
        {
            return true;
        }
        // `IPv6:`, then six groups each followed by `:`, or at most four
        // around `::` (those after it followed by `:`), then an IPv4
        // address: everything after the last colon.
        let Some(colon) = rest.iter().rposition(|&c| c == COLON) else { return false };
        let (v6, v4) = (&rest[..=colon], &rest[colon + 1..]);
        if !ipv4(v4) {
            return false;
        }
        if groups(&v6[..colon], 6, 6) {
            return true;
        }
        let Some((left, after)) = split_compressed(v6) else { return false };
        let right_ok = match after {
            [] => true,
            [right @ .., _colon] => groups(right, 1, 4),
        };
        right_ok && groups(left, 0, 4) && ends_of_groups(rest, false) < 5
    }
}

/// Strips `IPv6:`, matched caselessly.
fn strip_ipv6_tag(s: &[u32]) -> Option<&[u32]> {
    const TAG: &[u8] = b"ipv6:";
    if s.len() < TAG.len() {
        return None;
    }
    let (head, rest) = s.split_at(TAG.len());
    head.iter().zip(TAG).all(|(&c, &t)| u8::try_from(c).is_ok_and(|b| b.to_ascii_lowercase() == t)).then_some(rest)
}

/// Splits at the first `::`.
fn split_compressed(s: &[u32]) -> Option<(&[u32], &[u32])> {
    let i = s.windows(2).position(|w| w[0] == COLON && w[1] == COLON)?;
    Some((&s[..i], &s[i + 2..]))
}

fn is_hex(c: u32) -> bool {
    u8::try_from(c).is_ok_and(|b| b.is_ascii_hexdigit())
}

/// Whether `s` is `min` to `max` groups of 1 to 4 hex digits separated by
/// single colons (empty for none).
fn groups(s: &[u32], min: usize, max: usize) -> bool {
    if s.is_empty() {
        return min == 0;
    }
    let mut n = 0;
    for group in s.split(|&c| c == COLON) {
        if group.is_empty() || group.len() > 4 || !group.iter().all(|&c| is_hex(c)) {
            return false;
        }
        n += 1;
    }
    (min..=max).contains(&n)
}

/// How many hex digits are followed by `:` (or, with `bracket`, by the `]`
/// after `s`): the count the lookaheads `(?!(?:.*[a-f0-9][:\]]){7,})` and
/// `(?!(?:.*[a-f0-9]:){5,})` bound.
fn ends_of_groups(s: &[u32], bracket: bool) -> usize {
    let n = s.windows(2).filter(|w| is_hex(w[0]) && w[1] == COLON).count();
    n + usize::from(bracket && s.last().is_some_and(|&c| is_hex(c)))
}

/// Four decimal octets, `0` to `255` without leading zeros.
fn ipv4(s: &[u32]) -> bool {
    let mut n = 0;
    for part in s.split(|&c| c == DOT) {
        let digit = |i: usize| part.get(i).and_then(|&c| u8::try_from(c).ok()).filter(u8::is_ascii_digit);
        let ok = match part.len() {
            1 => digit(0).is_some(),
            2 => matches!((digit(0), digit(1)), (Some(a), Some(_)) if a != b'0'),
            3 => match (digit(0), digit(1), digit(2)) {
                (Some(b'1'), Some(_), Some(_)) => true,
                (Some(b'2'), Some(b), Some(c)) => b < b'5' || (b == b'5' && c <= b'5'),
                _ => false,
            },
            _ => false,
        };
        if !ok {
            return false;
        }
        n += 1;
    }
    n == 4
}

/// The check against the expressions themselves, run by PCRE2 as
/// `php_filter_validate_email()` runs them.
#[cfg(test)]
mod tests {
    use super::*;

    fn regex(s: &[u8], unicode: bool) -> bool {
        let re = if unicode { EMAIL_UNICODE } else { EMAIL };
        pcre::engine_match("filter_var", re, s).0 == Some(true)
    }

    /// Both modes agree with the expression (or leave it the address).
    fn check(s: &[u8]) {
        if s.len() > MAX_LENGTH {
            return;
        }
        for unicode in [false, true] {
            if let Some(valid) = validate(s, unicode) {
                assert_eq!(valid, regex(s, unicode), "{:?} (unicode: {unicode})", String::from_utf8_lossy(s));
            }
        }
    }

    const ADDRESSES: &[&str] = &[
        "a@b.co",
        "zoe.mueller+appwrite@mail.example.co.uk",
        "A@B.CO",
        "a@b",
        "a@b.c1",
        "a@b.1c",
        "a@1.com",
        "a@b-.com",
        "a@-b.com",
        "a@b--c.com",
        "a@xn--bcher-kva.ch",
        "a@XN--bcher-kva.ch",
        "a@b.xn--p1ai",
        "a@b.xn--",
        "a@b..com",
        "a@.b.com",
        "a@b.com.",
        "a.@b.com",
        ".a@b.com",
        "a..b@c.com",
        "a@b@c.com",
        "\"a\"@b.com",
        "\"a b\"@b.com",
        "\"a\\\"b\"@b.com",
        "\"a\\\\\"@b.com",
        "\"\"@b.com",
        "\"\".\"\"@b.com",
        "\"a\".b@c.com",
        "a.\"b\"@c.com",
        "\"a\"b@c.com",
        "\"a@b\"@c.com",
        "\"a\n\"@b.com",
        "\"a\\\n\"@b.com",
        "\"\\\u{7f}\"@b.com",
        "\"\u{7f}\"@b.com",
        "\"a\"\"@b.com",
        "a\"@b.com",
        "\"a@b.com",
        "a\\b@c.com",
        "a@b.com\n",
        "a\n@b.com",
        "a@[127.0.0.1]",
        "a@[255.255.255.255]",
        "a@[256.0.0.1]",
        "a@[01.0.0.1]",
        "a@[1.2.3]",
        "a@[1.2.3.4.5]",
        "a@[IPv6:::1]",
        "a@[ipv6:::1]",
        "a@[IPv6:1:2:3:4:5:6:7:8]",
        "a@[IPv6:1:2:3:4:5:6:7]",
        "a@[IPv6:1:2:3:4:5:6:7:8:9]",
        "a@[IPv6:1:2:3::4:5:6]",
        "a@[IPv6:1:2:3::4:5:6:7]",
        "a@[IPv6:1::]",
        "a@[IPv6:::]",
        "a@[IPv6:1:::2]",
        "a@[IPv6:12345::1]",
        "a@[IPv6:ABCD::ef01]",
        "a@[IPv6:::ffff:1.2.3.4]",
        "a@[IPv6:1:2:3:4:5:6:1.2.3.4]",
        "a@[IPv6:1:2:3:4:5:1.2.3.4]",
        "a@[IPv6:1:2::3:4:1.2.3.4]",
        "a@[IPv6:1:2::3:4:5:1.2.3.4]",
        "a@[IPv6:1:2:3:4::1.2.3.4]",
        "a@[IPv6:1:2:3:4:5::1.2.3.4]",
        "a@[IPv6:::1.2.3.4]",
        "a@[IPv6:1.2.3.4]",
        "a@[IPv6:1::2:]",
        "a@[1.2.3.4",
        "a@1.2.3.4]",
        "a@[]",
        "üser@b.com",
        "a@bü.com",
        "a@b.\u{212a}om",
        "\u{212a}@b.com",
        "a@\u{17f}.com",
        "\"\\\u{212a}\"@b.com",
        "\"\\\u{17f}\"@b.com",
        "\"\\é\"@b.com",
        "a\u{0130}@b.com",
        "a@\u{0130}.com",
        "a@[\u{0130}Pv6:::1]",
        "a@b.\u{0131}",
        "²@b.com",
        "\u{30000}@b.com",
        "\u{1e030}@b.com",
        "\u{10ffff}@b.com",
        "a\0@b.com",
        "\"\\\0\"@b.com",
        "\"\0\"@b.com",
    ];

    #[test]
    fn known_addresses() {
        for a in ADDRESSES {
            check(a.as_bytes());
        }
        for bytes in [&b"\xff@b.com"[..], b"a@b.c\xc3", b"\"\x80\"@b.com", b"\x80@b.com"] {
            check(bytes);
        }
    }

    #[test]
    fn lengths() {
        for n in 60..=68 {
            check(format!("a@{}.com", "b".repeat(n)).as_bytes());
            check(format!("{}@b.com", "a".repeat(n)).as_bytes());
            check(format!("\"{}\"@b.com", "a".repeat(n)).as_bytes());
            check(format!("{}@b.com", "\\a".repeat(n / 2)).as_bytes());
            check(format!("\"{}\"@b.com", "\\a".repeat(n / 2)).as_bytes());
            check(format!("{}@b.com", "é".repeat(n)).as_bytes());
            check(format!("a@{}.com", "\u{212a}".repeat(n)).as_bytes());
        }
        for n in 240..=270 {
            let local = "a".repeat(60);
            let labels = "b.".repeat((n - 61) / 2);
            check(format!("{local}@{labels}com").as_bytes());
            check(format!("{local}@{}x.com", "b".repeat(n - 66)).as_bytes());
        }
        for n in 300..=330 {
            check(format!("a@{}com", "b.".repeat((n - 5) / 2)).as_bytes());
        }
    }

    /// Addresses at the quote limit stay far from `pcre.backtrack_limit`:
    /// they match with a fifth of it.
    #[test]
    fn quotes_stay_under_the_limit() {
        let limited = |re: &[u8]| {
            let mut p = b"/(*LIMIT_MATCH=200000)".to_vec();
            p.extend_from_slice(&re[1..]);
            p
        };
        let domains = ["b.com".to_string(), format!("{}com", "abcdefghi.".repeat(29))];
        for words in 1..=MAX_QUOTES / 2 {
            for domain in &domains {
                let local = vec!["\"a\""; words].join(".");
                let address = format!("{local}.{}@{domain}", "x".repeat(60 - 4 * words));
                if address.len() > MAX_LENGTH {
                    continue;
                }
                for unicode in [false, true] {
                    assert_eq!(validate(address.as_bytes(), unicode), Some(true), "{address}");
                    let re = limited(if unicode { EMAIL_UNICODE } else { EMAIL });
                    assert_eq!(pcre::engine_match("filter_var", &re, address.as_bytes()).0, Some(true), "{address}");
                }
            }
        }
        assert_eq!(validate(br#""a"."a"."a"."a"@b.com"#, false), None);
    }

    /// Every character in each position where a class decides.
    #[test]
    fn characters() {
        let slots = [
            ("", "@b.com"),
            ("a", "@b.com"),
            ("\"", "\"@b.com"),
            ("\"\\", "\"@b.com"),
            ("\\", "@b.com"),
            ("a@", ".com"),
            ("a@b", ".com"),
            ("a@b.", ""),
            ("a@b.c", ""),
            ("a@[", "Pv6:::1]"),
            ("a@[IPv6:", "::1]"),
            ("a@[IPv6:1:2:3:4:5:6:7:", "]"),
            ("a@[1.2.3.", "]"),
        ];
        let mut chars: Vec<u32> = (0..0x800).collect();
        chars.extend((0x800..0x30000).step_by(7));
        chars.extend([0x212a, 0x17f, 0x130, 0x131, 0x1e9e, 0xdf, 0x3a3, 0x3c2, 0xfb05, 0xfb06, 0x10ffff, 0xe0001]);
        for (before, after) in slots {
            for &c in &chars {
                let Some(c) = char::from_u32(c) else { continue };
                check(format!("{before}{c}{after}").as_bytes());
            }
            for b in 0x80..=0xffu8 {
                let mut s = before.as_bytes().to_vec();
                s.push(b);
                s.extend_from_slice(after.as_bytes());
                check(&s);
            }
        }
    }

    /// Addresses assembled from the pieces the expression cares about.
    #[test]
    fn generated() {
        const PIECES: &[&str] = &[
            "a",
            "Z",
            "9",
            "-",
            "--",
            ".",
            "..",
            "@",
            "\"",
            "\"\"",
            "\"\"\"",
            "\\",
            "\\\\",
            "\\\"",
            " ",
            "\n",
            "\t",
            "\0",
            "!",
            "#",
            "(",
            ")",
            ",",
            ":",
            ";",
            "<",
            "[",
            "]",
            "{",
            "|",
            "~",
            "\u{7f}",
            "é",
            "\u{212a}",
            "\u{17f}",
            "ß",
            "²",
            "😀",
            "xn--",
            "XN--",
            "IPv6:",
            "ipv6:",
            "::",
            "1.2.3.4",
            "255",
            "256",
            "0",
            "01",
            "ffff",
            "abcde",
            "com",
            "b.com",
            "@b.com",
            "@[1.2.3.4]",
            "@[IPv6:::1]",
            "@[IPv6:1:2:3:4:5:6:7:8]",
            "@[IPv6:::ffff:1.2.3.4]",
        ];
        let mut state: u64 = 0x9e37_79b9_7f4a_7c15;
        let mut next = move |n: usize| {
            state ^= state << 13;
            state ^= state >> 7;
            state ^= state << 17;
            (state % n as u64) as usize
        };
        for _ in 0..60_000 {
            let mut s = String::new();
            let parts = 1 + next(12);
            for _ in 0..parts {
                let piece = PIECES[next(PIECES.len())];
                let times = if next(10) == 0 { 1 + next(70) } else { 1 };
                for _ in 0..times {
                    s.push_str(piece);
                }
            }
            if next(2) == 0 {
                s.push_str(["@b.com", "@b.c", ".com", "]", "@[1.2.3.4]", "@x.yz"][next(6)]);
            }
            check(s.as_bytes());
        }
    }
}
