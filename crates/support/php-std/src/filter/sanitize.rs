//! The sanitizing filters of `ext/filter/sanitizing_filters.c` (PHP 8.5),
//! with the parts of `ext/standard` they call: `php_strip_tags_ex()`
//! (`string.c`), `php_escape_html_entities_ex()` (`html.c`, as
//! `FILTER_SANITIZE_FULL_SPECIAL_CHARS` calls it: every HTML 4.01 entity,
//! UTF-8, no double encoding) and `php_addslashes()`.
//!
//! Every function takes the value already converted to a string and
//! returns the filtered value; none of them can fail.

use super::{
    FILTER_FLAG_ALLOW_FRACTION, FILTER_FLAG_ALLOW_SCIENTIFIC, FILTER_FLAG_ALLOW_THOUSAND,
    FILTER_FLAG_EMPTY_STRING_NULL, FILTER_FLAG_ENCODE_AMP, FILTER_FLAG_ENCODE_HIGH, FILTER_FLAG_ENCODE_LOW,
    FILTER_FLAG_NO_ENCODE_QUOTES, FILTER_FLAG_STRIP_BACKTICK, FILTER_FLAG_STRIP_HIGH, FILTER_FLAG_STRIP_LOW, Value,
};

/// Bytes to encode, indexed by byte (`unsigned char enc[256]`).
type Table = [bool; 256];

/// `php_filter_strip()`: drops bytes >= 127, < 32 and backticks as the
/// strip flags ask.
fn strip(s: &[u8], flags: i64) -> Vec<u8> {
    if flags & (FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_STRIP_BACKTICK) == 0 {
        return s.to_vec();
    }
    s.iter()
        .copied()
        .filter(|&c| {
            !((c >= 127 && flags & FILTER_FLAG_STRIP_HIGH != 0)
                || (c < 32 && flags & FILTER_FLAG_STRIP_LOW != 0)
                || (c == b'`' && flags & FILTER_FLAG_STRIP_BACKTICK != 0))
        })
        .collect()
}

/// `php_filter_encode_html()`: each byte the table selects becomes `&#N;`.
fn encode_html(s: &[u8], enc: &Table) -> Vec<u8> {
    let mut out = Vec::with_capacity(s.len());
    for &c in s {
        if enc[usize::from(c)] {
            out.extend_from_slice(format!("&#{c};").as_bytes());
        } else {
            out.push(c);
        }
    }
    out
}

/// Marks `ENCODE_AMP`, `ENCODE_LOW` and `ENCODE_HIGH` in `enc`.
fn encode_flags(enc: &mut Table, flags: i64) {
    if flags & FILTER_FLAG_ENCODE_AMP != 0 {
        enc[usize::from(b'&')] = true;
    }
    if flags & FILTER_FLAG_ENCODE_LOW != 0 {
        enc[..32].fill(true);
    }
    if flags & FILTER_FLAG_ENCODE_HIGH != 0 {
        enc[127..].fill(true);
    }
}

/// `php_filter_unsafe_raw()` (`FILTER_UNSAFE_RAW`, `FILTER_DEFAULT`).
pub(super) fn unsafe_raw(s: &[u8], flags: i64) -> Value {
    if flags != 0 && !s.is_empty() {
        let mut enc = [false; 256];
        encode_flags(&mut enc, flags);
        Value::Str(encode_html(&strip(s, flags), &enc))
    } else if flags & FILTER_FLAG_EMPTY_STRING_NULL != 0 && s.is_empty() {
        Value::Null
    } else {
        Value::Str(s.to_vec())
    }
}

/// `php_filter_string()` (`FILTER_SANITIZE_STRING`): strips, encodes the
/// quotes and what the flags ask for, then strips tags (and NUL bytes).
pub(super) fn string(s: &[u8], flags: i64) -> Value {
    let mut enc = [false; 256];
    if flags & FILTER_FLAG_NO_ENCODE_QUOTES == 0 {
        enc[usize::from(b'\'')] = true;
        enc[usize::from(b'"')] = true;
    }
    encode_flags(&mut enc, flags);
    let out = strip_tags(&encode_html(&strip(s, flags), &enc));
    if out.is_empty() && flags & FILTER_FLAG_EMPTY_STRING_NULL != 0 { Value::Null } else { Value::Str(out) }
}

/// `php_filter_encoded()` (`FILTER_SANITIZE_ENCODED`): strips, then
/// percent-encodes every byte but `[A-Za-z0-9._-]` (upper-case hex).
pub(super) fn encoded(s: &[u8], flags: i64) -> Vec<u8> {
    const HEX: &[u8; 16] = b"0123456789ABCDEF";
    let mut out = Vec::with_capacity(s.len());
    for c in strip(s, flags) {
        if c.is_ascii_alphanumeric() || matches!(c, b'-' | b'.' | b'_') {
            out.push(c);
        } else {
            out.extend_from_slice(&[b'%', HEX[usize::from(c >> 4)], HEX[usize::from(c & 15)]]);
        }
    }
    out
}

/// `php_filter_special_chars()` (`FILTER_SANITIZE_SPECIAL_CHARS`): strips,
/// then encodes `'"<>&`, bytes below 32 and, with `ENCODE_HIGH`, bytes from
/// 127.
pub(super) fn special_chars(s: &[u8], flags: i64) -> Vec<u8> {
    let mut enc = [false; 256];
    for &c in b"'\"<>&" {
        enc[usize::from(c)] = true;
    }
    enc[..32].fill(true);
    if flags & FILTER_FLAG_ENCODE_HIGH != 0 {
        enc[127..].fill(true);
    }
    encode_html(&strip(s, flags), &enc)
}

/// `php_filter_full_special_chars()` (`FILTER_SANITIZE_FULL_SPECIAL_CHARS`):
/// `htmlentities($s, ENT_QUOTES | ENT_HTML401, 'UTF-8', false)`, or
/// `ENT_NOQUOTES` with `FILTER_FLAG_NO_ENCODE_QUOTES`.
pub(super) fn full_special_chars(s: &[u8], flags: i64) -> Vec<u8> {
    escape_html_entities(s, flags & FILTER_FLAG_NO_ENCODE_QUOTES == 0)
}

/// `filter_map_apply()`: keeps the bytes `keep` accepts.
fn keep(s: &[u8], keep: impl Fn(u8) -> bool) -> Vec<u8> {
    s.iter().copied().filter(|&c| keep(c)).collect()
}

/// `php_filter_email()` (`FILTER_SANITIZE_EMAIL`).
pub(super) fn email(s: &[u8]) -> Vec<u8> {
    keep(s, |c| c.is_ascii_alphanumeric() || b"!#$%&'*+-=?^_`{|}~@.[]".contains(&c))
}

/// `php_filter_url()` (`FILTER_SANITIZE_URL`): the characters of RFC 1738
/// section 5.
pub(super) fn url(s: &[u8]) -> Vec<u8> {
    keep(s, |c| c.is_ascii_alphanumeric() || b"$-_.+!*'(),{}|\\^~[]`<>#%\";/?:@&=".contains(&c))
}

/// `php_filter_number_int()` (`FILTER_SANITIZE_NUMBER_INT`).
pub(super) fn number_int(s: &[u8]) -> Vec<u8> {
    keep(s, |c| c.is_ascii_digit() || c == b'+' || c == b'-')
}

/// `php_filter_number_float()` (`FILTER_SANITIZE_NUMBER_FLOAT`): digits and
/// signs, plus `.`, `,` and `eE` as `ALLOW_FRACTION`, `ALLOW_THOUSAND` and
/// `ALLOW_SCIENTIFIC` ask.
pub(super) fn number_float(s: &[u8], flags: i64) -> Vec<u8> {
    keep(s, |c| {
        c.is_ascii_digit()
            || c == b'+'
            || c == b'-'
            || (c == b'.' && flags & FILTER_FLAG_ALLOW_FRACTION != 0)
            || (c == b',' && flags & FILTER_FLAG_ALLOW_THOUSAND != 0)
            || ((c == b'e' || c == b'E') && flags & FILTER_FLAG_ALLOW_SCIENTIFIC != 0)
    })
}

/// `php_addslashes()` (`FILTER_SANITIZE_ADD_SLASHES`).
pub(super) fn add_slashes(s: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(s.len());
    for &c in s {
        match c {
            0 => out.extend_from_slice(b"\\0"),
            b'\'' | b'"' | b'\\' => out.extend_from_slice(&[b'\\', c]),
            _ => out.push(c),
        }
    }
    out
}

/// `php_strip_tags_ex($s, allow: NULL, allow_tag_spaces: true)`: the state
/// machine of `ext/standard/string.c`, which drops tags, PHP blocks,
/// comments and NUL bytes. Reads past the end see the NUL terminator.
fn strip_tags(buf: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(buf.len());
    let mut state = 0u8;
    let mut depth = 0u32;
    let mut in_q = 0u8;
    let mut br = 0i64;
    let mut lc = 0u8;
    let mut is_xml = false;
    let before = |p: usize, n: usize| if p >= n { buf[p - n] } else { 0 };
    let mut p = 0;
    while p < buf.len() {
        let c = buf[p];
        match state {
            0 => match c {
                0 => {}
                b'<' => {
                    if in_q == 0 {
                        lc = b'<';
                        state = 1;
                    }
                }
                b'>' => {
                    if depth > 0 {
                        depth -= 1;
                    } else if in_q == 0 {
                        out.push(c);
                    }
                }
                _ => out.push(c),
            },
            1 => match c {
                b'<' => {
                    if in_q == 0 {
                        depth += 1;
                    }
                }
                b'>' => {
                    if depth > 0 {
                        depth -= 1;
                    } else if in_q == 0 {
                        lc = b'>';
                        if !(is_xml && p >= 1 && before(p, 1) == b'-') {
                            in_q = 0;
                            state = 0;
                            is_xml = false;
                        }
                    }
                }
                b'"' | b'\'' => {
                    if p != 0 && (in_q == 0 || c == in_q) {
                        in_q = if in_q != 0 { 0 } else { c };
                    }
                }
                b'!' if p >= 1 && before(p, 1) == b'<' => {
                    state = 3;
                    lc = c;
                }
                b'?' if p >= 1 && before(p, 1) == b'<' => {
                    br = 0;
                    state = 2;
                }
                _ => {}
            },
            2 => match c {
                b'(' => {
                    if lc != b'"' && lc != b'\'' {
                        lc = b'(';
                        br += 1;
                    }
                }
                b')' => {
                    if lc != b'"' && lc != b'\'' {
                        lc = b')';
                        br -= 1;
                    }
                }
                b'>' => {
                    if depth > 0 {
                        depth -= 1;
                    } else if in_q == 0 && br == 0 && p >= 1 && lc != b'"' && before(p, 1) == b'?' {
                        in_q = 0;
                        state = 0;
                    }
                }
                b'"' | b'\'' => {
                    if p >= 1 && before(p, 1) != b'\\' {
                        if lc == c {
                            lc = 0;
                        } else if lc != b'\\' {
                            lc = c;
                        }
                        if p != 0 && (in_q == 0 || c == in_q) {
                            in_q = if in_q != 0 { 0 } else { c };
                        }
                    }
                }
                // `<?xml` is not PHP: back to a tag.
                b'l' | b'L'
                    if p > 4
                        && before(p, 1).eq_ignore_ascii_case(&b'm')
                        && before(p, 2).eq_ignore_ascii_case(&b'x')
                        && before(p, 3) == b'?'
                        && before(p, 4) == b'<' =>
                {
                    state = 1;
                    is_xml = true;
                }
                _ => {}
            },
            3 => match c {
                b'>' => {
                    if depth > 0 {
                        depth -= 1;
                    } else if in_q == 0 {
                        state = 0;
                    }
                }
                b'"' | b'\'' => {
                    if p != 0 && before(p, 1) != b'\\' && (in_q == 0 || c == in_q) {
                        in_q = if in_q != 0 { 0 } else { c };
                    }
                }
                b'-' if p >= 2 && before(p, 1) == b'-' && before(p, 2) == b'!' => state = 4,
                // `<!DOCTYPE` is a tag.
                b'E' | b'e' if p > 6 && buf[p - 6..p].eq_ignore_ascii_case(b"doctyp") => state = 1,
                _ => {}
            },
            _ => {
                if c == b'>' && in_q == 0 && p >= 2 && before(p, 1) == b'-' && before(p, 2) == b'-' {
                    in_q = 0;
                    state = 0;
                }
            }
        }
        p += 1;
    }
    out
}

/// `php_escape_html_entities_ex($s, all: 1, quotes, 'UTF-8', double_encode:
/// false)` with the HTML 4.01 table. Invalid UTF-8 gives `""`; `'` and `"`
/// are encoded only with `quotes` (`ENT_QUOTES`, else `ENT_NOQUOTES`); an
/// `&` that starts a valid entity is kept.
fn escape_html_entities(s: &[u8], quotes: bool) -> Vec<u8> {
    let Ok(text) = std::str::from_utf8(s) else {
        return Vec::new();
    };
    let mut out = Vec::with_capacity(s.len() + s.len() / 2);
    let mut cursor = 0;
    while let Some(ch) = text[cursor..].chars().next() {
        let next = cursor + ch.len_utf8();
        if ch == '&' {
            match entity_len(s, next) {
                Some(n) => {
                    out.extend_from_slice(&s[cursor..next + n + 1]);
                    cursor = next + n + 1;
                }
                None => {
                    out.extend_from_slice(b"&amp;");
                    cursor = next;
                }
            }
            continue;
        }
        let entity = if (ch == '\'' || ch == '"') && !quotes {
            None
        } else {
            HTML4_ENTITIES.binary_search_by_key(&u32::from(ch), |&(cp, _)| cp).ok().map(|i| HTML4_ENTITIES[i].1)
        };
        match entity {
            Some(name) => {
                out.push(b'&');
                out.extend_from_slice(name.as_bytes());
                out.push(b';');
            }
            None => out.extend_from_slice(&s[cursor..next]),
        }
        cursor = next;
    }
    out
}

/// The length between `&` and `;` of a valid entity starting at `at` (just
/// past the `&`), as the `!double_encode` branch of
/// `php_escape_html_entities_ex()` checks it: `#` and a decimal or `x` and a
/// hexadecimal code point up to U+10FFFF (`process_numeric_entity()`), or
/// an HTML 4.01 entity name (`process_named_entity_html()`).
fn entity_len(s: &[u8], at: usize) -> Option<usize> {
    let byte = |i: usize| s.get(i).copied().unwrap_or(0);
    if byte(at) == b'#' {
        let mut p = at + 1;
        let hex = matches!(byte(p), b'x' | b'X');
        if hex {
            p += 1;
        }
        let digit = |c: u8| if hex { c.is_ascii_hexdigit() } else { c.is_ascii_digit() };
        if !digit(byte(p)) {
            return None;
        }
        // strtol(): base 16 skips a `0x` prefix followed by a digit.
        if hex && byte(p) == b'0' && matches!(byte(p + 1), b'x' | b'X') && byte(p + 2).is_ascii_hexdigit() {
            p += 2;
        }
        let mut code: u64 = 0;
        while digit(byte(p)) {
            let d = u64::from(char::from(byte(p)).to_digit(16).unwrap_or(0));
            code = code.saturating_mul(if hex { 16 } else { 10 }).saturating_add(d);
            p += 1;
        }
        if byte(p) != b';' || code > 0x10FFFF {
            return None;
        }
        return Some(p - at);
    }
    let mut p = at;
    while byte(p).is_ascii_alphanumeric() {
        p += 1;
    }
    if byte(p) != b';' || p == at {
        return None;
    }
    let name = std::str::from_utf8(&s[at..p]).ok()?;
    HTML4_NAMES.binary_search(&name).ok().map(|_| p - at)
}

/// HTML 4.01 entities by code point (`entity_ms_table_html4`, PHP 8.5
/// `ext/standard/html_tables.h`).
const HTML4_ENTITIES: &[(u32, &str)] = &[
    (0x0022, "quot"),
    (0x0026, "amp"),
    (0x0027, "#039"),
    (0x003C, "lt"),
    (0x003E, "gt"),
    (0x00A0, "nbsp"),
    (0x00A1, "iexcl"),
    (0x00A2, "cent"),
    (0x00A3, "pound"),
    (0x00A4, "curren"),
    (0x00A5, "yen"),
    (0x00A6, "brvbar"),
    (0x00A7, "sect"),
    (0x00A8, "uml"),
    (0x00A9, "copy"),
    (0x00AA, "ordf"),
    (0x00AB, "laquo"),
    (0x00AC, "not"),
    (0x00AD, "shy"),
    (0x00AE, "reg"),
    (0x00AF, "macr"),
    (0x00B0, "deg"),
    (0x00B1, "plusmn"),
    (0x00B2, "sup2"),
    (0x00B3, "sup3"),
    (0x00B4, "acute"),
    (0x00B5, "micro"),
    (0x00B6, "para"),
    (0x00B7, "middot"),
    (0x00B8, "cedil"),
    (0x00B9, "sup1"),
    (0x00BA, "ordm"),
    (0x00BB, "raquo"),
    (0x00BC, "frac14"),
    (0x00BD, "frac12"),
    (0x00BE, "frac34"),
    (0x00BF, "iquest"),
    (0x00C0, "Agrave"),
    (0x00C1, "Aacute"),
    (0x00C2, "Acirc"),
    (0x00C3, "Atilde"),
    (0x00C4, "Auml"),
    (0x00C5, "Aring"),
    (0x00C6, "AElig"),
    (0x00C7, "Ccedil"),
    (0x00C8, "Egrave"),
    (0x00C9, "Eacute"),
    (0x00CA, "Ecirc"),
    (0x00CB, "Euml"),
    (0x00CC, "Igrave"),
    (0x00CD, "Iacute"),
    (0x00CE, "Icirc"),
    (0x00CF, "Iuml"),
    (0x00D0, "ETH"),
    (0x00D1, "Ntilde"),
    (0x00D2, "Ograve"),
    (0x00D3, "Oacute"),
    (0x00D4, "Ocirc"),
    (0x00D5, "Otilde"),
    (0x00D6, "Ouml"),
    (0x00D7, "times"),
    (0x00D8, "Oslash"),
    (0x00D9, "Ugrave"),
    (0x00DA, "Uacute"),
    (0x00DB, "Ucirc"),
    (0x00DC, "Uuml"),
    (0x00DD, "Yacute"),
    (0x00DE, "THORN"),
    (0x00DF, "szlig"),
    (0x00E0, "agrave"),
    (0x00E1, "aacute"),
    (0x00E2, "acirc"),
    (0x00E3, "atilde"),
    (0x00E4, "auml"),
    (0x00E5, "aring"),
    (0x00E6, "aelig"),
    (0x00E7, "ccedil"),
    (0x00E8, "egrave"),
    (0x00E9, "eacute"),
    (0x00EA, "ecirc"),
    (0x00EB, "euml"),
    (0x00EC, "igrave"),
    (0x00ED, "iacute"),
    (0x00EE, "icirc"),
    (0x00EF, "iuml"),
    (0x00F0, "eth"),
    (0x00F1, "ntilde"),
    (0x00F2, "ograve"),
    (0x00F3, "oacute"),
    (0x00F4, "ocirc"),
    (0x00F5, "otilde"),
    (0x00F6, "ouml"),
    (0x00F7, "divide"),
    (0x00F8, "oslash"),
    (0x00F9, "ugrave"),
    (0x00FA, "uacute"),
    (0x00FB, "ucirc"),
    (0x00FC, "uuml"),
    (0x00FD, "yacute"),
    (0x00FE, "thorn"),
    (0x00FF, "yuml"),
    (0x0152, "OElig"),
    (0x0153, "oelig"),
    (0x0160, "Scaron"),
    (0x0161, "scaron"),
    (0x0178, "Yuml"),
    (0x0192, "fnof"),
    (0x02C6, "circ"),
    (0x02DC, "tilde"),
    (0x0391, "Alpha"),
    (0x0392, "Beta"),
    (0x0393, "Gamma"),
    (0x0394, "Delta"),
    (0x0395, "Epsilon"),
    (0x0396, "Zeta"),
    (0x0397, "Eta"),
    (0x0398, "Theta"),
    (0x0399, "Iota"),
    (0x039A, "Kappa"),
    (0x039B, "Lambda"),
    (0x039C, "Mu"),
    (0x039D, "Nu"),
    (0x039E, "Xi"),
    (0x039F, "Omicron"),
    (0x03A0, "Pi"),
    (0x03A1, "Rho"),
    (0x03A3, "Sigma"),
    (0x03A4, "Tau"),
    (0x03A5, "Upsilon"),
    (0x03A6, "Phi"),
    (0x03A7, "Chi"),
    (0x03A8, "Psi"),
    (0x03A9, "Omega"),
    (0x03B1, "alpha"),
    (0x03B2, "beta"),
    (0x03B3, "gamma"),
    (0x03B4, "delta"),
    (0x03B5, "epsilon"),
    (0x03B6, "zeta"),
    (0x03B7, "eta"),
    (0x03B8, "theta"),
    (0x03B9, "iota"),
    (0x03BA, "kappa"),
    (0x03BB, "lambda"),
    (0x03BC, "mu"),
    (0x03BD, "nu"),
    (0x03BE, "xi"),
    (0x03BF, "omicron"),
    (0x03C0, "pi"),
    (0x03C1, "rho"),
    (0x03C2, "sigmaf"),
    (0x03C3, "sigma"),
    (0x03C4, "tau"),
    (0x03C5, "upsilon"),
    (0x03C6, "phi"),
    (0x03C7, "chi"),
    (0x03C8, "psi"),
    (0x03C9, "omega"),
    (0x03D1, "thetasym"),
    (0x03D2, "upsih"),
    (0x03D6, "piv"),
    (0x2002, "ensp"),
    (0x2003, "emsp"),
    (0x2009, "thinsp"),
    (0x200C, "zwnj"),
    (0x200D, "zwj"),
    (0x200E, "lrm"),
    (0x200F, "rlm"),
    (0x2013, "ndash"),
    (0x2014, "mdash"),
    (0x2018, "lsquo"),
    (0x2019, "rsquo"),
    (0x201A, "sbquo"),
    (0x201C, "ldquo"),
    (0x201D, "rdquo"),
    (0x201E, "bdquo"),
    (0x2020, "dagger"),
    (0x2021, "Dagger"),
    (0x2022, "bull"),
    (0x2026, "hellip"),
    (0x2030, "permil"),
    (0x2032, "prime"),
    (0x2033, "Prime"),
    (0x2039, "lsaquo"),
    (0x203A, "rsaquo"),
    (0x203E, "oline"),
    (0x2044, "frasl"),
    (0x20AC, "euro"),
    (0x2111, "image"),
    (0x2118, "weierp"),
    (0x211C, "real"),
    (0x2122, "trade"),
    (0x2135, "alefsym"),
    (0x2190, "larr"),
    (0x2191, "uarr"),
    (0x2192, "rarr"),
    (0x2193, "darr"),
    (0x2194, "harr"),
    (0x21B5, "crarr"),
    (0x21D0, "lArr"),
    (0x21D1, "uArr"),
    (0x21D2, "rArr"),
    (0x21D3, "dArr"),
    (0x21D4, "hArr"),
    (0x2200, "forall"),
    (0x2202, "part"),
    (0x2203, "exist"),
    (0x2205, "empty"),
    (0x2207, "nabla"),
    (0x2208, "isin"),
    (0x2209, "notin"),
    (0x220B, "ni"),
    (0x220F, "prod"),
    (0x2211, "sum"),
    (0x2212, "minus"),
    (0x2217, "lowast"),
    (0x221A, "radic"),
    (0x221D, "prop"),
    (0x221E, "infin"),
    (0x2220, "ang"),
    (0x2227, "and"),
    (0x2228, "or"),
    (0x2229, "cap"),
    (0x222A, "cup"),
    (0x222B, "int"),
    (0x2234, "there4"),
    (0x223C, "sim"),
    (0x2245, "cong"),
    (0x2248, "asymp"),
    (0x2260, "ne"),
    (0x2261, "equiv"),
    (0x2264, "le"),
    (0x2265, "ge"),
    (0x2282, "sub"),
    (0x2283, "sup"),
    (0x2284, "nsub"),
    (0x2286, "sube"),
    (0x2287, "supe"),
    (0x2295, "oplus"),
    (0x2297, "otimes"),
    (0x22A5, "perp"),
    (0x22C5, "sdot"),
    (0x2308, "lceil"),
    (0x2309, "rceil"),
    (0x230A, "lfloor"),
    (0x230B, "rfloor"),
    (0x2329, "lang"),
    (0x232A, "rang"),
    (0x25CA, "loz"),
    (0x2660, "spades"),
    (0x2663, "clubs"),
    (0x2665, "hearts"),
    (0x2666, "diams"),
];

/// HTML 4.01 entity names, sorted (`ent_ht_html4`; its `#039` is never
/// looked up, a name has no `#`).
const HTML4_NAMES: &[&str] = &[
    "#039", "AElig", "Aacute", "Acirc", "Agrave", "Alpha", "Aring", "Atilde", "Auml", "Beta", "Ccedil", "Chi",
    "Dagger", "Delta", "ETH", "Eacute", "Ecirc", "Egrave", "Epsilon", "Eta", "Euml", "Gamma", "Iacute", "Icirc",
    "Igrave", "Iota", "Iuml", "Kappa", "Lambda", "Mu", "Ntilde", "Nu", "OElig", "Oacute", "Ocirc", "Ograve", "Omega",
    "Omicron", "Oslash", "Otilde", "Ouml", "Phi", "Pi", "Prime", "Psi", "Rho", "Scaron", "Sigma", "THORN", "Tau",
    "Theta", "Uacute", "Ucirc", "Ugrave", "Upsilon", "Uuml", "Xi", "Yacute", "Yuml", "Zeta", "aacute", "acirc",
    "acute", "aelig", "agrave", "alefsym", "alpha", "amp", "and", "ang", "aring", "asymp", "atilde", "auml", "bdquo",
    "beta", "brvbar", "bull", "cap", "ccedil", "cedil", "cent", "chi", "circ", "clubs", "cong", "copy", "crarr", "cup",
    "curren", "dArr", "dagger", "darr", "deg", "delta", "diams", "divide", "eacute", "ecirc", "egrave", "empty",
    "emsp", "ensp", "epsilon", "equiv", "eta", "eth", "euml", "euro", "exist", "fnof", "forall", "frac12", "frac14",
    "frac34", "frasl", "gamma", "ge", "gt", "hArr", "harr", "hearts", "hellip", "iacute", "icirc", "iexcl", "igrave",
    "image", "infin", "int", "iota", "iquest", "isin", "iuml", "kappa", "lArr", "lambda", "lang", "laquo", "larr",
    "lceil", "ldquo", "le", "lfloor", "lowast", "loz", "lrm", "lsaquo", "lsquo", "lt", "macr", "mdash", "micro",
    "middot", "minus", "mu", "nabla", "nbsp", "ndash", "ne", "ni", "not", "notin", "nsub", "ntilde", "nu", "oacute",
    "ocirc", "oelig", "ograve", "oline", "omega", "omicron", "oplus", "or", "ordf", "ordm", "oslash", "otilde",
    "otimes", "ouml", "para", "part", "permil", "perp", "phi", "pi", "piv", "plusmn", "pound", "prime", "prod", "prop",
    "psi", "quot", "rArr", "radic", "rang", "raquo", "rarr", "rceil", "rdquo", "real", "reg", "rfloor", "rho", "rlm",
    "rsaquo", "rsquo", "sbquo", "scaron", "sdot", "sect", "shy", "sigma", "sigmaf", "sim", "spades", "sub", "sube",
    "sum", "sup", "sup1", "sup2", "sup3", "supe", "szlig", "tau", "there4", "theta", "thetasym", "thinsp", "thorn",
    "tilde", "times", "trade", "uArr", "uacute", "uarr", "ucirc", "ugrave", "uml", "upsih", "upsilon", "uuml",
    "weierp", "xi", "yacute", "yen", "yuml", "zeta", "zwj", "zwnj",
];

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn strips_tags_comments_and_php() {
        assert_eq!(strip_tags(b"a<b>c</b>d"), b"acd");
        assert_eq!(strip_tags(b"x<!-- <b> -->y"), b"xy");
        assert_eq!(strip_tags(b"1<?php echo '>'; ?>2"), b"12");
        assert_eq!(strip_tags(b"a<!DOCTYPE html>b"), b"ab");
        assert_eq!(strip_tags(b"a\0b>c"), b"ab>c");
    }

    #[test]
    fn escapes_entities_without_double_encoding() {
        assert_eq!(
            escape_html_entities("é&eacute;&#123;&bogus;<'".as_bytes(), true),
            b"&eacute;&eacute;&#123;&amp;bogus;&lt;&#039;"
        );
        assert_eq!(escape_html_entities(b"&#x0x1F;&#1114112;", true), b"&#x0x1F;&amp;#1114112;");
        assert_eq!(escape_html_entities(b"\"'", false), b"\"'");
        assert_eq!(escape_html_entities(b"a\xff", true), b"");
    }

    #[test]
    fn sanitizers() {
        assert_eq!(string(b"<a href=\"x\">t'x</a>&amp;", 0), Value::Str(b"t&#39;x&amp;".to_vec()));
        assert_eq!(string(b"<>", FILTER_FLAG_EMPTY_STRING_NULL), Value::Null);
        assert_eq!(encoded(b"a b/~\xff", 0), b"a%20b%2F%7E%FF");
        assert_eq!(special_chars(b"a\x01\x7f`", FILTER_FLAG_STRIP_BACKTICK), b"a&#1;\x7f");
        assert_eq!(number_float(b"1,2.3e4+x", FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_THOUSAND), b"1,2.34+");
        assert_eq!(add_slashes(b"a'b\"c\\d\0"), b"a\\'b\\\"c\\\\d\\0");
    }
}
