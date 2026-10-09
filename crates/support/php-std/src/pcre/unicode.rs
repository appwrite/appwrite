//! Lookups over the PCRE2 Unicode tables (`ucd.rs`), plus the C-locale
//! character tables PHP compiles patterns with (`pcre2_chartables.c.dist`).

use super::ucd::{self, Record};

/// General categories (`ucp_C` ...).
pub const GC_C: u32 = 0;
pub const GC_L: u32 = 1;
pub const GC_M: u32 = 2;
pub const GC_N: u32 = 3;
pub const GC_P: u32 = 4;
pub const GC_S: u32 = 5;
pub const GC_Z: u32 = 6;

/// Particular categories (`ucp_Cc` ...).
pub const CC: u32 = 0;
pub const CF: u32 = 1;
pub const CN: u32 = 2;
pub const LL: u32 = 5;
pub const LT: u32 = 8;
pub const LU: u32 = 9;
pub const MN: u32 = 12;
pub const ND: u32 = 13;
pub const PC: u32 = 16;
pub const ZL: u32 = 27;
pub const ZP: u32 = 28;

/// `PRIV(ucp_gentype)`: particular category to general category.
const GENTYPE: [u8; 30] = [0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 2, 2, 2, 3, 3, 3, 4, 4, 4, 4, 4, 4, 4, 5, 5, 5, 5, 6, 6, 6];

/// Grapheme break properties used by `\X`.
pub const GB_CR: u32 = 0;
pub const GB_LF: u32 = 1;
pub const GB_CONTROL: u32 = 2;
pub const GB_EXTEND: u32 = 3;
pub const GB_PREPEND: u32 = 4;
pub const GB_SPACING_MARK: u32 = 5;
pub const GB_L: u32 = 6;
pub const GB_V: u32 = 7;
pub const GB_T: u32 = 8;
pub const GB_LV: u32 = 9;
pub const GB_LVT: u32 = 10;
pub const GB_REGIONAL_INDICATOR: u32 = 11;
pub const GB_OTHER: u32 = 12;
pub const GB_ZWJ: u32 = 13;
pub const GB_EXTENDED_PICTOGRAPHIC: u32 = 14;

const ESZ: u32 = (1 << GB_EXTEND) | (1 << GB_SPACING_MARK) | (1 << GB_ZWJ);

/// `PRIV(ucp_gbtable)`: bit `right` of `GBTABLE[left]` set means no grapheme
/// break between the two.
pub const GBTABLE: [u32; 15] = [
    1 << GB_LF,
    0,
    0,
    ESZ,
    ESZ | (1 << GB_PREPEND)
        | (1 << GB_L)
        | (1 << GB_V)
        | (1 << GB_T)
        | (1 << GB_LV)
        | (1 << GB_LVT)
        | (1 << GB_OTHER)
        | (1 << GB_REGIONAL_INDICATOR),
    ESZ,
    ESZ | (1 << GB_L) | (1 << GB_V) | (1 << GB_LV) | (1 << GB_LVT),
    ESZ | (1 << GB_V) | (1 << GB_T),
    ESZ | (1 << GB_T),
    ESZ | (1 << GB_V) | (1 << GB_T),
    ESZ | (1 << GB_T),
    1 << GB_REGIONAL_INDICATOR,
    ESZ,
    ESZ | (1 << GB_EXTENDED_PICTOGRAPHIC),
    ESZ,
];

/// The UCD record of a code point (`GET_UCD`).
pub fn record(c: u32) -> &'static Record {
    if c > 0x10ffff {
        // Not reachable from 8-bit patterns; behave like an unassigned code point.
        return &UNASSIGNED;
    }
    let block = ucd::STAGE1[(c / 128) as usize] as usize;
    &ucd::RECORDS[ucd::STAGE2[block * 128 + (c % 128) as usize] as usize]
}

static UNASSIGNED: Record =
    Record { script: 68, chartype: CN as u8, gbprop: GB_OTHER as u8, caseset: 0, other_case: 0, scriptx_bidiclass: 9 << 11, bprops: 0 };

pub fn chartype(c: u32) -> u32 {
    u32::from(record(c).chartype)
}

pub fn gentype_of(chartype: u32) -> u32 {
    u32::from(GENTYPE[chartype as usize])
}

pub fn category(c: u32) -> u32 {
    gentype_of(chartype(c))
}

/// `UCD_OTHERCASE`
pub fn other_case(c: u32) -> u32 {
    (c as i64 + i64::from(record(c).other_case)) as u32
}

/// `UCD_CASESET`: offset into [`ucd::CASELESS_SETS`], 0 for none.
pub fn caseset(c: u32) -> u32 {
    u32::from(record(c).caseset)
}

/// The caseless set starting at `offset`, without its terminator.
pub fn caseless_set(offset: u32) -> &'static [u32] {
    let set = &ucd::CASELESS_SETS[offset as usize..];
    let end = set.iter().position(|&c| c == u32::MAX).unwrap_or(set.len());
    &set[..end]
}

pub fn script(c: u32) -> u32 {
    u32::from(record(c).script)
}

/// Whether `script` is in the code point's Script_Extensions (`PT_SCX`).
pub fn has_script_extension(c: u32, script: u32) -> bool {
    let r = record(c);
    if u32::from(r.script) == script {
        return true;
    }
    let base = usize::from(r.scriptx_bidiclass & 0x3ff);
    map_bit(&ucd::SCRIPT_SETS[base..], script)
}

pub fn bidi_class(c: u32) -> u32 {
    u32::from(record(c).scriptx_bidiclass >> 11)
}

/// Whether the boolean property `prop` (`ucp_ASCII` ...) holds.
pub fn has_bool_property(c: u32, prop: u32) -> bool {
    let base = usize::from(record(c).bprops & 0xfff);
    map_bit(&ucd::BOOLPROP_SETS[base..], prop)
}

pub fn grapheme_break(c: u32) -> u32 {
    u32::from(record(c).gbprop)
}

fn map_bit(words: &[u32], n: u32) -> bool {
    words.get((n / 32) as usize).is_some_and(|w| w & (1 << (n % 32)) != 0)
}

/// `\h` code points.
pub fn is_hspace(c: u32) -> bool {
    ucd::HSPACE.contains(&c)
}

/// `\v` code points.
pub fn is_vspace(c: u32) -> bool {
    ucd::VSPACE.contains(&c)
}

/// C-locale `ctypes` bits.
pub const CTYPE_SPACE: u8 = 0x01;
pub const CTYPE_LETTER: u8 = 0x02;
pub const CTYPE_LCLETTER: u8 = 0x04;
pub const CTYPE_DIGIT: u8 = 0x08;
pub const CTYPE_WORD: u8 = 0x10;

/// `cb->ctypes[c]` of the default (C locale) tables.
pub fn ctypes(c: u32) -> u8 {
    match c {
        0x09..=0x0d | 0x20 => CTYPE_SPACE,
        0x30..=0x39 => CTYPE_DIGIT | CTYPE_WORD,
        0x41..=0x5a => CTYPE_LETTER | CTYPE_WORD,
        0x61..=0x7a => CTYPE_LETTER | CTYPE_LCLETTER | CTYPE_WORD,
        0x5f => CTYPE_WORD,
        _ => 0,
    }
}

/// `cbits` offsets of the default tables.
pub const CBIT_SPACE: usize = 0;
pub const CBIT_XDIGIT: usize = 32;
pub const CBIT_DIGIT: usize = 64;
pub const CBIT_UPPER: usize = 96;
pub const CBIT_LOWER: usize = 128;
pub const CBIT_WORD: usize = 160;
pub const CBIT_GRAPH: usize = 192;
pub const CBIT_PRINT: usize = 224;
pub const CBIT_PUNCT: usize = 256;
pub const CBIT_CNTRL: usize = 288;

/// The default `cbits` table (C locale), 10 maps of 32 bytes.
pub static CBITS: [u8; 320] = [
    0x00, 0x3e, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, // space
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0xff, 0x03, 0x7e, 0x00, 0x00, 0x00, 0x7e, 0x00, 0x00, 0x00, // xdigit
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0xff, 0x03, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, // digit
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0xfe, 0xff, 0xff, 0x07, 0x00, 0x00, 0x00, 0x00, // upper
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0xfe, 0xff, 0xff, 0x07, // lower
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0xff, 0x03, 0xfe, 0xff, 0xff, 0x87, 0xfe, 0xff, 0xff, 0x07, // word
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
    0x00, 0x00, 0x00, 0x00, 0xfe, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0x7f, // graph
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
    0x00, 0x00, 0x00, 0x00, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0x7f, // print
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
    0x00, 0x00, 0x00, 0x00, 0xfe, 0xff, 0x00, 0xfc, 0x01, 0x00, 0x00, 0xf8, 0x01, 0x00, 0x00, 0x78, // punct
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
    0xff, 0xff, 0xff, 0xff, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x80, // cntrl
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
];

/// `cb->fcc[c]`: ASCII case flip (C locale).
pub fn fcc(c: u32) -> u32 {
    match c {
        0x41..=0x5a => c + 32,
        0x61..=0x7a => c - 32,
        _ => c,
    }
}

/// `mb->lcc[c]`: ASCII lowercase (C locale).
pub fn lcc(c: u32) -> u32 {
    match c {
        0x41..=0x5a => c + 32,
        _ => c,
    }
}

/// Decodes one UTF-8 character at `i` of valid UTF-8 (`GETCHAR`), returning
/// it and its length. Malformed input yields the byte itself.
pub fn utf8_at(s: &[u8], i: usize) -> (u32, usize) {
    let b0 = u32::from(s[i]);
    if b0 < 0xc0 {
        return (b0, 1);
    }
    let (len, init) = if b0 < 0xe0 {
        (2, b0 & 0x1f)
    } else if b0 < 0xf0 {
        (3, b0 & 0x0f)
    } else if b0 < 0xf8 {
        (4, b0 & 0x07)
    } else if b0 < 0xfc {
        (5, b0 & 0x03)
    } else {
        (6, b0 & 0x01)
    };
    let mut c = init;
    for k in 1..len {
        match s.get(i + k) {
            Some(&b) => c = (c << 6) | u32::from(b & 0x3f),
            None => return (b0, 1),
        }
    }
    (c, len)
}

/// Encodes a code point as UTF-8 (`PRIV(ord2utf)`).
pub fn push_utf8(out: &mut Vec<u8>, c: u32) {
    if c < 0x80 {
        out.push(c as u8);
    } else if c < 0x800 {
        out.extend_from_slice(&[0xc0 | (c >> 6) as u8, 0x80 | (c & 0x3f) as u8]);
    } else if c < 0x10000 {
        out.extend_from_slice(&[0xe0 | (c >> 12) as u8, 0x80 | ((c >> 6) & 0x3f) as u8, 0x80 | (c & 0x3f) as u8]);
    } else {
        out.extend_from_slice(&[
            0xf0 | (c >> 18) as u8,
            0x80 | ((c >> 12) & 0x3f) as u8,
            0x80 | ((c >> 6) & 0x3f) as u8,
            0x80 | (c & 0x3f) as u8,
        ]);
    }
}

/// Validates UTF-8 the way `PRIV(valid_utf)` does: on error, the PCRE2 error
/// code (`PCRE2_ERROR_UTF8_ERR1` = -3 ... `ERR21` = -23) and the offset of
/// the offending character.
pub fn valid_utf8(s: &[u8]) -> Result<(), (i32, usize)> {
    const ERR1: i32 = -3;
    const ERR6: i32 = -8; // byte 2 top bits; ERR7..ERR10 follow
    const ERR11: i32 = -13; // 5-byte character
    const ERR12: i32 = -14; // 6-byte character
    const ERR13: i32 = -15; // > 0x10ffff
    const ERR14: i32 = -16; // surrogate
    const ERR15: i32 = -17; // overlong 2-byte; ERR16..ERR19 follow
    const ERR20: i32 = -22; // isolated continuation byte
    const ERR21: i32 = -23; // 0xfe or 0xff
    let mut p = 0;
    while p < s.len() {
        let c = s[p];
        if c < 128 {
            p += 1;
            continue;
        }
        if c < 0xc0 {
            return Err((ERR20, p));
        }
        if c >= 0xfe {
            return Err((ERR21, p));
        }
        let ab = UTF8_TABLE4[(c & 0x3f) as usize] as usize;
        let length = s.len() - p - 1;
        if length < ab {
            return Err((ERR1 - (ab - length) as i32 + 1, p));
        }
        let d = s[p + 1];
        if d & 0xc0 != 0x80 {
            return Err((ERR6, p));
        }
        // Bytes 3.. must be continuation bytes (ERR7 for byte 3, ...).
        for k in 2..=ab {
            if s[p + k] & 0xc0 != 0x80 {
                return Err((ERR6 - (k as i32 - 1), p));
            }
            // The overlong / range checks of each length come after its own
            // continuation checks, so they run once all bytes are checked.
        }
        match ab {
            1 if c & 0x3e == 0 => return Err((ERR15, p)),
            2 if c == 0xe0 && d & 0x20 == 0 => return Err((ERR15 - 1, p)),
            2 if c == 0xed && d >= 0xa0 => return Err((ERR14, p)),
            3 if c == 0xf0 && d & 0x30 == 0 => return Err((ERR15 - 2, p)),
            3 if c > 0xf4 || (c == 0xf4 && d > 0x8f) => return Err((ERR13, p)),
            4 if c == 0xf8 && d & 0x38 == 0 => return Err((ERR15 - 3, p)),
            5 if c == 0xfc && d & 0x3c == 0 => return Err((ERR15 - 4, p)),
            _ => {}
        }
        if ab > 3 {
            return Err((if ab == 4 { ERR11 } else { ERR12 }, p));
        }
        p += ab + 1;
    }
    Ok(())
}

/// `PRIV(utf8_table4)`: number of additional bytes for lead bytes 0xc0..0xff.
const UTF8_TABLE4: [u8; 64] = [
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 2, 2,
    2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 3, 3, 3, 3, 3, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5,
];
