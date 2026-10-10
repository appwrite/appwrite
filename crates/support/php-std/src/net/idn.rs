//! `idn_to_ascii()` / `idn_to_utf8()` (ext/intl over ICU 78 UTS #46).
//!
//! A port of ICU4C's `UTS46` class (`common/uts46.cpp`) and its Punycode
//! codec (`common/punycode.cpp`), driven the way PHP drives them
//! (`ext/intl/idn/idn.cpp`): `uidna_openUTS46($flags)`, then
//! `uidna_nameToASCII_UTF8()` into a 255-byte buffer or
//! `uidna_nameToUnicodeUTF8()` into a 1008-byte buffer. Any IDNA error, an
//! ICU failure or an output that does not fit the buffer makes PHP return
//! `false`.
//!
//! The work happens on UTF-16 code units, as in ICU, because several checks
//! index code units (`label[2] == '-'`, the BiDi and CONTEXTO scans). The
//! Unicode data comes from ICU4X (`icu_normalizer`'s UTS #46 mapping and
//! `icu_properties`), which is exported from the same ICU release and covers
//! the same Unicode version (17) as PHP's ICU 78.1.
//!
//! Every function here is checked against PHP by `bin/compat fuzz php-std`
//! (operations `net.idn_to_ascii`, `net.idn_to_utf8`).

use std::fmt;

use icu_normalizer::uts46::Uts46MapperBorrowed;
use icu_properties::CodePointMapData;
use icu_properties::props::{BidiClass, GeneralCategory, GeneralCategoryGroup, JoiningType, Script};

/// `IDNA_DEFAULT`, the default `$flags`: nontransitional processing both
/// ways (`IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_NONTRANSITIONAL_TO_UNICODE`)
/// since PHP 8.4. ICU's own default (0) is transitional.
pub const IDNA_DEFAULT: i64 = 0x30;
/// `IDNA_ALLOW_UNASSIGNED` (ignored by UTS #46).
pub const IDNA_ALLOW_UNASSIGNED: i64 = 1;
/// `IDNA_USE_STD3_RULES`: ASCII other than letters, digits, `-` and `.` is disallowed.
pub const IDNA_USE_STD3_RULES: i64 = 2;
/// `IDNA_CHECK_BIDI`: the RFC 5893 BiDi rule.
pub const IDNA_CHECK_BIDI: i64 = 4;
/// `IDNA_CHECK_CONTEXTJ`: the RFC 5892 CONTEXTJ rules (ZWJ/ZWNJ).
pub const IDNA_CHECK_CONTEXTJ: i64 = 8;
/// `IDNA_NONTRANSITIONAL_TO_ASCII`: keep deviation characters in `idn_to_ascii()`.
pub const IDNA_NONTRANSITIONAL_TO_ASCII: i64 = 0x10;
/// `IDNA_NONTRANSITIONAL_TO_UNICODE`: keep deviation characters in `idn_to_utf8()`.
pub const IDNA_NONTRANSITIONAL_TO_UNICODE: i64 = 0x20;
/// `IDNA_CHECK_CONTEXTO`: the RFC 5892 CONTEXTO rules.
pub const IDNA_CHECK_CONTEXTO: i64 = 0x40;

/// `IDNA_ERROR_EMPTY_LABEL`.
pub const IDNA_ERROR_EMPTY_LABEL: u32 = 1;
/// `IDNA_ERROR_LABEL_TOO_LONG`.
pub const IDNA_ERROR_LABEL_TOO_LONG: u32 = 2;
/// `IDNA_ERROR_DOMAIN_NAME_TOO_LONG`.
pub const IDNA_ERROR_DOMAIN_NAME_TOO_LONG: u32 = 4;
/// `IDNA_ERROR_LEADING_HYPHEN`.
pub const IDNA_ERROR_LEADING_HYPHEN: u32 = 8;
/// `IDNA_ERROR_TRAILING_HYPHEN`.
pub const IDNA_ERROR_TRAILING_HYPHEN: u32 = 0x10;
/// `IDNA_ERROR_HYPHEN_3_4`.
pub const IDNA_ERROR_HYPHEN_3_4: u32 = 0x20;
/// `IDNA_ERROR_LEADING_COMBINING_MARK`.
pub const IDNA_ERROR_LEADING_COMBINING_MARK: u32 = 0x40;
/// `IDNA_ERROR_DISALLOWED`.
pub const IDNA_ERROR_DISALLOWED: u32 = 0x80;
/// `IDNA_ERROR_PUNYCODE`.
pub const IDNA_ERROR_PUNYCODE: u32 = 0x100;
/// `IDNA_ERROR_LABEL_HAS_DOT`.
pub const IDNA_ERROR_LABEL_HAS_DOT: u32 = 0x200;
/// `IDNA_ERROR_INVALID_ACE_LABEL`.
pub const IDNA_ERROR_INVALID_ACE_LABEL: u32 = 0x400;
/// `IDNA_ERROR_BIDI`.
pub const IDNA_ERROR_BIDI: u32 = 0x800;
/// `IDNA_ERROR_CONTEXTJ`.
pub const IDNA_ERROR_CONTEXTJ: u32 = 0x1000;
/// `IDNA_ERROR_CONTEXTO_PUNCTUATION`.
pub const IDNA_ERROR_CONTEXTO_PUNCTUATION: u32 = 0x2000;
/// `IDNA_ERROR_CONTEXTO_DIGITS`.
pub const IDNA_ERROR_CONTEXTO_DIGITS: u32 = 0x4000;

const SEVERE_ERRORS: u32 = IDNA_ERROR_LEADING_COMBINING_MARK
    | IDNA_ERROR_DISALLOWED
    | IDNA_ERROR_PUNYCODE
    | IDNA_ERROR_LABEL_HAS_DOT
    | IDNA_ERROR_INVALID_ACE_LABEL;

/// The `$flags` argument: `IDNA_*` option bits. PHP passes the integer to
/// ICU as an unsigned 32-bit value and ignores unknown bits. The default is
/// [`IDNA_DEFAULT`].
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Flags(u32);

impl Default for Flags {
    fn default() -> Self {
        Flags(IDNA_DEFAULT as u32)
    }
}

impl Flags {
    /// The flags from PHP's integer (truncated to 32 bits like PHP's
    /// `(uint32_t)` cast).
    pub fn from_bits(bits: i64) -> Self {
        Flags(bits as u32)
    }

    pub fn bits(self) -> u32 {
        self.0
    }

    fn has(self, flag: i64) -> bool {
        self.0 & flag as u32 != 0
    }
}

/// An argument error: PHP's `ValueError` for an empty domain.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Error {
    message: String,
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        "ValueError"
    }
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.message)
    }
}

impl std::error::Error for Error {}

/// What PHP stores in the `$idna_info` argument.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Info {
    /// The converted name, also when it has errors.
    pub result: Vec<u8>,
    /// Whether transitional and nontransitional processing differ (the name
    /// has a deviation character: `ß`, `ς`, ZWJ or ZWNJ).
    pub is_transitional_different: bool,
    /// The `IDNA_ERROR_*` bits.
    pub errors: u32,
}

/// The outcome of a conversion.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Conversion {
    /// The return value; `None` where PHP returns `false`.
    pub value: Option<Vec<u8>>,
    /// What `$idna_info` receives; `None` where PHP leaves it an empty
    /// array (an ICU failure or an output too long for PHP's buffer).
    pub info: Option<Info>,
}

/// PHP `idn_to_ascii($domain, $flags, INTL_IDNA_VARIANT_UTS46)`: the ASCII
/// (Punycode) form of a domain name, `None` where PHP returns `false`.
///
/// `domain` is UTF-8; ill-formed sequences become U+FFFD, which is
/// disallowed. Only the UTS #46 variant exists in PHP 8.
pub fn to_ascii(domain: &[u8], flags: Flags) -> Result<Option<Vec<u8>>, Error> {
    Ok(to_ascii_info(domain, flags)?.value)
}

/// PHP `idn_to_utf8($domain, $flags, INTL_IDNA_VARIANT_UTS46)`: the Unicode
/// (UTF-8) form of a domain name, `None` where PHP returns `false`.
pub fn to_utf8(domain: &[u8], flags: Flags) -> Result<Option<Vec<u8>>, Error> {
    Ok(to_utf8_info(domain, flags)?.value)
}

/// [`to_ascii`] with what PHP's `$idna_info` argument receives.
pub fn to_ascii_info(domain: &[u8], flags: Flags) -> Result<Conversion, Error> {
    convert("idn_to_ascii", domain, flags, true)
}

/// [`to_utf8`] with what PHP's `$idna_info` argument receives.
pub fn to_utf8_info(domain: &[u8], flags: Flags) -> Result<Conversion, Error> {
    convert("idn_to_utf8", domain, flags, false)
}

/// `php_intl_idn_handoff()` + `php_intl_idn_to_46()`.
fn convert(function: &str, domain: &[u8], flags: Flags, to_ascii: bool) -> Result<Conversion, Error> {
    if domain.is_empty() {
        return Err(Error { message: format!("{function}(): Argument #1 ($domain) must not be empty") });
    }
    let failed = Conversion { value: None, info: None };
    let mut info = State::default();
    let Ok(out) = Uts46::new(flags).process_utf8(domain, to_ascii, &mut info) else {
        return Ok(failed);
    };
    let capacity = if to_ascii { 255 } else { 252 * 4 };
    if out.len() >= capacity {
        return Ok(failed);
    }
    Ok(Conversion {
        value: (info.errors == 0).then(|| out.clone()),
        info: Some(Info { result: out, is_transitional_different: info.is_trans_diff, errors: info.errors }),
    })
}

/// An ICU `U_FAILURE` status (a label too long for the Punycode encoder).
#[derive(Debug)]
struct Failure;

/// ICU's `IDNAInfo`.
#[derive(Debug)]
struct State {
    errors: u32,
    label_errors: u32,
    is_trans_diff: bool,
    is_bidi: bool,
    is_ok_bidi: bool,
}

impl Default for State {
    fn default() -> Self {
        State { errors: 0, label_errors: 0, is_trans_diff: false, is_bidi: false, is_ok_bidi: true }
    }
}

/// ICU's `asciiData[]`: -1 for non-LDH ASCII, 0 for LDH and dot, 1 for A-Z.
fn ascii_data(c: u16) -> i8 {
    match c as u8 {
        b'-' | b'.' | b'0'..=b'9' | b'a'..=b'z' => 0,
        b'A'..=b'Z' => 1,
        _ => -1,
    }
}

fn is_surrogate(c: u16) -> bool {
    (0xd800..0xe000).contains(&c)
}

fn is_lead(c: u16) -> bool {
    (0xd800..0xdc00).contains(&c)
}

fn is_trail(c: u16) -> bool {
    (0xdc00..0xe000).contains(&c)
}

fn supplementary(lead: u16, trail: u16) -> u32 {
    0x10000 + ((u32::from(lead) - 0xd800) << 10) + (u32::from(trail) - 0xdc00)
}

/// `U16_NEXT_UNSAFE`.
fn next_unsafe(s: &[u16], i: &mut usize) -> u32 {
    let c = s[*i];
    *i += 1;
    if is_lead(c) {
        let t = s[*i];
        *i += 1;
        supplementary(c, t)
    } else {
        u32::from(c)
    }
}

/// `U16_PREV_UNSAFE`.
fn prev_unsafe(s: &[u16], i: &mut usize) -> u32 {
    *i -= 1;
    let c = s[*i];
    if is_trail(c) {
        *i -= 1;
        supplementary(s[*i], c)
    } else {
        u32::from(c)
    }
}

/// `U16_NEXT` (bounds-checked; an unpaired surrogate is returned as is).
fn next(s: &[u16], i: &mut usize) -> u32 {
    let c = s[*i];
    *i += 1;
    if is_lead(c) && *i < s.len() && is_trail(s[*i]) {
        let t = s[*i];
        *i += 1;
        return supplementary(c, t);
    }
    u32::from(c)
}

/// `U16_PREV` with a start of 0.
fn prev(s: &[u16], i: &mut usize) -> u32 {
    *i -= 1;
    let c = s[*i];
    if is_trail(c) && *i > 0 && is_lead(s[*i - 1]) {
        *i -= 1;
        return supplementary(s[*i], c);
    }
    u32::from(c)
}

/// `UnicodeString::fromUTF8()`: ill-formed sequences become U+FFFD (one per
/// maximal subpart, as ICU does).
fn from_utf8(s: &[u8]) -> Vec<u16> {
    String::from_utf8_lossy(s).encode_utf16().collect()
}

fn is_ascii(s: &[u16]) -> bool {
    s.iter().all(|&c| c <= 0x7f)
}

/// The UTS #46 processing state: ICU's `UTS46` instance.
struct Uts46 {
    flags: Flags,
    mapper: Uts46MapperBorrowed<'static>,
}

impl Uts46 {
    fn new(flags: Flags) -> Self {
        Uts46 { flags, mapper: Uts46MapperBorrowed::new() }
    }

    /// The `uts46.nrm` normalizer: UTS #46 mapping (disallowed characters
    /// to U+FFFD, ignored ones removed, deviation characters kept) then NFC.
    fn normalize(&self, s: &[u16]) -> Vec<u16> {
        let chars = char::decode_utf16(s.iter().copied()).map(|r| r.unwrap_or('\u{fffd}'));
        let mut out = Vec::with_capacity(s.len());
        let mut buf = [0u16; 2];
        for c in self.mapper.map_normalize(chars) {
            out.extend_from_slice(c.encode_utf16(&mut buf));
        }
        out
    }

    /// `UTS46::processUTF8()` for a name (never a single label).
    fn process_utf8(&self, src: &[u8], to_ascii: bool, info: &mut State) -> Result<Vec<u8>, Failure> {
        *info = State::default();
        if src.is_empty() {
            info.errors |= IDNA_ERROR_EMPTY_LABEL;
            return Ok(Vec::new());
        }
        let mut out = Vec::with_capacity(src.len());
        let mut label_start = 0;
        let mut dest;
        if src.len() <= 256 {
            // ASCII fast path.
            let std3 = self.flags.has(IDNA_USE_STD3_RULES);
            let mut i = 0;
            loop {
                if i == src.len() {
                    if to_ascii {
                        if i - label_start > 63 {
                            info.label_errors |= IDNA_ERROR_LABEL_TOO_LONG;
                        }
                        // A trailing dot is allowed after 253 characters.
                        if i >= 254 && (i > 254 || label_start < i) {
                            info.errors |= IDNA_ERROR_DOMAIN_NAME_TOO_LONG;
                        }
                    }
                    info.errors |= info.label_errors;
                    return Ok(out);
                }
                let c = src[i];
                if c > 0x7f {
                    break;
                }
                let data = ascii_data(u16::from(c));
                if data > 0 {
                    out.push(c + 0x20);
                } else if data < 0 && std3 {
                    break;
                } else {
                    out.push(c);
                    if c == b'-' {
                        if i == label_start + 3 && src[i - 1] == b'-' {
                            // "??--" is Punycode or forbidden.
                            out.pop();
                            break;
                        }
                        if i == label_start {
                            info.label_errors |= IDNA_ERROR_LEADING_HYPHEN;
                        }
                        if i + 1 == src.len() || src[i + 1] == b'.' {
                            info.label_errors |= IDNA_ERROR_TRAILING_HYPHEN;
                        }
                    } else if c == b'.' {
                        if i == label_start {
                            info.label_errors |= IDNA_ERROR_EMPTY_LABEL;
                        }
                        if to_ascii && i - label_start > 63 {
                            info.label_errors |= IDNA_ERROR_LABEL_TOO_LONG;
                        }
                        info.errors |= info.label_errors;
                        info.label_errors = 0;
                        label_start = i + 1;
                    }
                }
                i += 1;
            }
            info.errors |= info.label_errors;
            // The lowercased ASCII prefix of the current label, then the rest
            // of the source, normalized together
            // (`normalizeSecondAndAppend()`).
            let mut current: Vec<u16> = out[label_start..i].iter().map(|&b| u16::from(b)).collect();
            current.extend(from_utf8(&src[i..]));
            out.truncate(label_start);
            dest = self.normalize(&current);
        } else {
            dest = self.normalize(&from_utf8(src));
        }
        self.process_unicode(&mut dest, to_ascii, info)?;
        out.extend_from_slice(String::from_utf16_lossy(&dest).as_bytes());
        if to_ascii {
            let length = label_start + dest.len();
            if length >= 254
                && is_ascii(&dest)
                && (length > 254 || (label_start < 254 && dest[253 - label_start] != u16::from(b'.')))
            {
                info.errors |= IDNA_ERROR_DOMAIN_NAME_TOO_LONG;
            }
        }
        if info.is_bidi
            && info.errors & SEVERE_ERRORS == 0
            && (!info.is_ok_bidi || (label_start > 0 && !is_ascii_ok_bidi(&src[..label_start])))
        {
            info.errors |= IDNA_ERROR_BIDI;
        }
        Ok(out)
    }

    /// `UTS46::processUnicode()` on the already normalized `dest`.
    fn process_unicode(&self, dest: &mut Vec<u16>, to_ascii: bool, info: &mut State) -> Result<(), Failure> {
        let mut map_dev_chars = if to_ascii {
            !self.flags.has(IDNA_NONTRANSITIONAL_TO_ASCII)
        } else {
            !self.flags.has(IDNA_NONTRANSITIONAL_TO_UNICODE)
        };
        let mut label_start = 0;
        let mut label_limit = 0;
        while label_limit < dest.len() {
            let c = dest[label_limit];
            if c == u16::from(b'.') {
                let length = self.process_label(dest, label_start, label_limit - label_start, to_ascii, info)?;
                info.errors |= info.label_errors;
                info.label_errors = 0;
                label_start += length + 1;
                label_limit = label_start;
                continue;
            } else if c < 0xdf {
            } else if c <= 0x200d && (c == 0xdf || c == 0x3c2 || c >= 0x200c) {
                info.is_trans_diff = true;
                if map_dev_chars {
                    self.map_dev_chars(dest, label_start, label_limit);
                    // All deviation characters are mapped now; look at the
                    // (possibly removed) character again.
                    map_dev_chars = false;
                    continue;
                }
            } else if is_surrogate(c) {
                let unpaired = if is_lead(c) {
                    label_limit + 1 == dest.len() || !is_trail(dest[label_limit + 1])
                } else {
                    label_limit == label_start || !is_lead(dest[label_limit - 1])
                };
                if unpaired {
                    info.label_errors |= IDNA_ERROR_DISALLOWED;
                    dest[label_limit] = 0xfffd;
                }
            }
            label_limit += 1;
        }
        // An empty label is allowed at the end only (a trailing dot).
        if label_start == 0 || label_start < label_limit {
            self.process_label(dest, label_start, label_limit - label_start, to_ascii, info)?;
            info.errors |= info.label_errors;
        }
        Ok(())
    }

    /// `UTS46::mapDevChars()`: transitional mapping of the deviation
    /// characters from `mapping_start` on (ß → ss, ς → σ, ZWJ/ZWNJ removed),
    /// then normalization of the text from the label start again.
    fn map_dev_chars(&self, dest: &mut Vec<u16>, label_start: usize, mapping_start: usize) {
        let mut mapped = Vec::with_capacity(dest.len() + 1);
        mapped.extend_from_slice(&dest[label_start..mapping_start]);
        for &c in &dest[mapping_start..] {
            match c {
                0xdf => mapped.extend_from_slice(&[0x73, 0x73]),
                0x3c2 => mapped.push(0x3c3),
                0x200c | 0x200d => {}
                _ => mapped.push(c),
            }
        }
        let normalized = self.normalize(&mapped);
        dest.truncate(label_start);
        dest.extend_from_slice(&normalized);
    }

    /// `UTS46::processLabel()`: validates the label at
    /// `dest[label_start..label_start + label_length]`, encodes or decodes
    /// Punycode, and returns the new label length.
    fn process_label(
        &self,
        dest: &mut Vec<u16>,
        label_start: usize,
        label_length: usize,
        to_ascii: bool,
        info: &mut State,
    ) -> Result<usize, Failure> {
        let dest_label_start = label_start;
        let dest_label_length = label_length;
        let original = &dest[label_start..label_start + label_length];
        let was_punycode = label_length >= 4 && original[..4] == [0x78, 0x6e, 0x2d, 0x2d];
        let mut label: Vec<u16> = if was_punycode {
            // "xn--" (empty) and "xn--ASCII-" (just "ASCII") do not round-trip.
            if label_length == 4 || (label_length > 5 && original[label_length - 1] == u16::from(b'-')) {
                info.label_errors |= IDNA_ERROR_INVALID_ACE_LABEL;
                return Ok(self.mark_bad_ace_label(dest, label_start, label_length, to_ascii, info));
            }
            let Ok(decoded) = punycode_decode(&original[4..]) else {
                info.label_errors |= IDNA_ERROR_PUNYCODE;
                return Ok(self.mark_bad_ace_label(dest, label_start, label_length, to_ascii, info));
            };
            // Something wrong (not NFC, not valid, mapped) changes the
            // string. A decoded label must not start with "xn--" either.
            if self.normalize(&decoded) != decoded || decoded.starts_with(&[0x78, 0x6e, 0x2d, 0x2d]) {
                info.label_errors |= IDNA_ERROR_INVALID_ACE_LABEL;
                return Ok(self.mark_bad_ace_label(dest, label_start, label_length, to_ascii, info));
            }
            decoded
        } else {
            original.to_vec()
        };
        let replace = |dest: &mut Vec<u16>, label: &[u16]| {
            dest.splice(dest_label_start..dest_label_start + dest_label_length, label.iter().copied());
            label.len()
        };
        if label.is_empty() {
            info.label_errors |= IDNA_ERROR_EMPTY_LABEL;
            return Ok(replace(dest, &label));
        }
        let length = label.len();
        if length >= 4 && label[2] == 0x2d && label[3] == 0x2d {
            info.label_errors |= IDNA_ERROR_HYPHEN_3_4;
        }
        if label[0] == 0x2d {
            info.label_errors |= IDNA_ERROR_LEADING_HYPHEN;
        }
        if label[length - 1] == 0x2d {
            info.label_errors |= IDNA_ERROR_TRAILING_HYPHEN;
        }
        // STD3 ASCII, U+FFFD (disallowed) and dots (only from Punycode).
        let std3 = self.flags.has(IDNA_USE_STD3_RULES);
        let mut ored: u16 = 0;
        for c in label.iter_mut() {
            if *c <= 0x7f {
                if *c == 0x2e {
                    info.label_errors |= IDNA_ERROR_LABEL_HAS_DOT;
                    *c = 0xfffd;
                } else if std3 && ascii_data(*c) < 0 {
                    info.label_errors |= IDNA_ERROR_DISALLOWED;
                    *c = 0xfffd;
                }
            } else {
                ored |= *c;
                if *c == 0xfffd {
                    info.label_errors |= IDNA_ERROR_DISALLOWED;
                }
            }
        }
        // A leading combining mark, checked last so that its U+FFFD does
        // not count as disallowed.
        let mut cp_length = 0;
        let first = next_unsafe(&label, &mut cp_length);
        if GeneralCategoryGroup::Mark.contains(CodePointMapData::<GeneralCategory>::new().get32(first)) {
            info.label_errors |= IDNA_ERROR_LEADING_COMBINING_MARK;
            label.splice(0..cp_length, [0xfffd]);
        }
        if info.label_errors & SEVERE_ERRORS == 0 {
            // Contextual checks only without U+FFFD from a severe error.
            if self.flags.has(IDNA_CHECK_BIDI) && (!info.is_bidi || info.is_ok_bidi) {
                check_label_bidi(&label, info);
            }
            if self.flags.has(IDNA_CHECK_CONTEXTJ) && ored & 0x200c == 0x200c && !self.is_label_ok_context_j(&label) {
                info.label_errors |= IDNA_ERROR_CONTEXTJ;
            }
            if self.flags.has(IDNA_CHECK_CONTEXTO) && ored >= 0xb7 {
                check_label_context_o(&label, info);
            }
            if to_ascii {
                if was_punycode {
                    // A valid Punycode label stays as it was.
                    if dest_label_length > 63 {
                        info.label_errors |= IDNA_ERROR_LABEL_TOO_LONG;
                    }
                    return Ok(dest_label_length);
                } else if ored >= 0x80 {
                    let mut punycode = vec![0x78, 0x6e, 0x2d, 0x2d];
                    punycode.extend(punycode_encode(&label)?);
                    if punycode.len() > 63 {
                        info.label_errors |= IDNA_ERROR_LABEL_TOO_LONG;
                    }
                    return Ok(replace(dest, &punycode));
                } else if label.len() > 63 {
                    info.label_errors |= IDNA_ERROR_LABEL_TOO_LONG;
                }
            }
        } else if was_punycode {
            // A Punycode label with severe errors stays, made to look invalid.
            info.label_errors |= IDNA_ERROR_INVALID_ACE_LABEL;
            return Ok(self.mark_bad_ace_label(dest, dest_label_start, dest_label_length, to_ascii, info));
        }
        Ok(replace(dest, &label))
    }

    /// `UTS46::markBadACELabel()`: makes sure an ACE label does not look
    /// valid (appends U+FFFD to an all-LDH label; with STD3 rules replaces
    /// disallowed ASCII with U+FFFD).
    fn mark_bad_ace_label(
        &self,
        dest: &mut Vec<u16>,
        label_start: usize,
        label_length: usize,
        to_ascii: bool,
        info: &mut State,
    ) -> usize {
        let std3 = self.flags.has(IDNA_USE_STD3_RULES);
        let mut is_ascii = true;
        let mut only_ldh = true;
        for c in &mut dest[label_start + 4..label_start + label_length] {
            if *c <= 0x7f {
                if *c == 0x2e {
                    info.label_errors |= IDNA_ERROR_LABEL_HAS_DOT;
                    *c = 0xfffd;
                    is_ascii = false;
                    only_ldh = false;
                } else if ascii_data(*c) < 0 {
                    only_ldh = false;
                    if std3 {
                        *c = 0xfffd;
                        is_ascii = false;
                    }
                }
            } else {
                is_ascii = false;
                only_ldh = false;
            }
        }
        if only_ldh {
            dest.insert(label_start + label_length, 0xfffd);
            label_length + 1
        } else {
            if to_ascii && is_ascii && label_length > 63 {
                info.label_errors |= IDNA_ERROR_LABEL_TOO_LONG;
            }
            label_length
        }
    }

    /// `UTS46::isLabelOkContextJ()`: RFC 5892 Appendix A.1 and A.2.
    fn is_label_ok_context_j(&self, label: &[u16]) -> bool {
        let joining = CodePointMapData::<JoiningType>::new();
        let virama = |c: u32| char::from_u32(c).is_some_and(|c| self.mapper.is_virama(c));
        for i in 0..label.len() {
            if label[i] == 0x200c {
                // ZERO WIDTH NON-JOINER: after a virama, or between joining
                // characters (L|D) T* ZWNJ T* (R|D).
                if i == 0 {
                    return false;
                }
                let mut j = i;
                let mut c = prev_unsafe(label, &mut j);
                if virama(c) {
                    continue;
                }
                loop {
                    let t = joining.get32(c);
                    if t == JoiningType::Transparent {
                        if j == 0 {
                            return false;
                        }
                        c = prev_unsafe(label, &mut j);
                    } else if t == JoiningType::LeftJoining || t == JoiningType::DualJoining {
                        break;
                    } else {
                        return false;
                    }
                }
                let mut j = i + 1;
                loop {
                    if j == label.len() {
                        return false;
                    }
                    let t = joining.get32(next_unsafe(label, &mut j));
                    if t == JoiningType::Transparent {
                    } else if t == JoiningType::RightJoining || t == JoiningType::DualJoining {
                        break;
                    } else {
                        return false;
                    }
                }
            } else if label[i] == 0x200d {
                // ZERO WIDTH JOINER: after a virama only.
                if i == 0 {
                    return false;
                }
                let mut j = i;
                if !virama(prev_unsafe(label, &mut j)) {
                    return false;
                }
            }
        }
        true
    }
}

/// The Bidi_Class bits `checkLabelBiDi()` tests (`U_MASK(u_charDirection(c))`);
/// every other class shares one bit.
mod dir {
    pub const L: u32 = 1 << 0;
    pub const R: u32 = 1 << 1;
    pub const EN: u32 = 1 << 2;
    pub const ES: u32 = 1 << 3;
    pub const ET: u32 = 1 << 4;
    pub const AN: u32 = 1 << 5;
    pub const CS: u32 = 1 << 6;
    pub const ON: u32 = 1 << 10;
    pub const AL: u32 = 1 << 13;
    pub const NSM: u32 = 1 << 17;
    pub const BN: u32 = 1 << 18;
    pub const OTHER: u32 = 1 << 31;

    pub const R_AL: u32 = R | AL;
    pub const L_R_AL: u32 = L | R_AL;
    pub const R_AL_AN: u32 = R_AL | AN;
    pub const EN_AN: u32 = EN | AN;
    pub const R_AL_EN_AN: u32 = R_AL | EN_AN;
    pub const L_EN: u32 = L | EN;
    pub const ES_CS_ET_ON_BN_NSM: u32 = ES | CS | ET | ON | BN | NSM;
    pub const L_EN_ES_CS_ET_ON_BN_NSM: u32 = L_EN | ES_CS_ET_ON_BN_NSM;
    pub const R_AL_AN_EN_ES_CS_ET_ON_BN_NSM: u32 = R_AL | EN_AN | ES_CS_ET_ON_BN_NSM;
}

fn dir_mask(c: u32) -> u32 {
    let class = CodePointMapData::<BidiClass>::new().get32(c);
    match class {
        BidiClass::LeftToRight => dir::L,
        BidiClass::RightToLeft => dir::R,
        BidiClass::EuropeanNumber => dir::EN,
        BidiClass::EuropeanSeparator => dir::ES,
        BidiClass::EuropeanTerminator => dir::ET,
        BidiClass::ArabicNumber => dir::AN,
        BidiClass::CommonSeparator => dir::CS,
        BidiClass::OtherNeutral => dir::ON,
        BidiClass::ArabicLetter => dir::AL,
        BidiClass::NonspacingMark => dir::NSM,
        BidiClass::BoundaryNeutral => dir::BN,
        _ => dir::OTHER,
    }
}

/// `UTS46::checkLabelBiDi()`: the RFC 5893 BiDi rule for one label, and
/// whether the label makes the name a BiDi domain name.
fn check_label_bidi(label: &[u16], info: &mut State) {
    let mut i = 0;
    let first = dir_mask(next_unsafe(label, &mut i));
    // 1. The first character must be L, R or AL.
    if first & !dir::L_R_AL != 0 {
        info.is_ok_bidi = false;
    }
    // The last character that is not NSM.
    let mut length = label.len();
    let last = loop {
        if i >= length {
            break first;
        }
        let m = dir_mask(prev_unsafe(label, &mut length));
        if m != dir::NSM {
            break m;
        }
    };
    // 3. An RTL label ends with R, AL, EN or AN; 6. an LTR label with L or EN.
    let bad_end = if first & dir::L != 0 { last & !dir::L_EN != 0 } else { last & !dir::R_AL_EN_AN != 0 };
    if bad_end {
        info.is_ok_bidi = false;
    }
    let mut mask = first | last;
    while i < length {
        mask |= dir_mask(next_unsafe(label, &mut i));
    }
    if first & dir::L != 0 {
        // 5. LTR labels allow L, EN, ES, CS, ET, ON, BN and NSM.
        if mask & !dir::L_EN_ES_CS_ET_ON_BN_NSM != 0 {
            info.is_ok_bidi = false;
        }
    } else {
        // 2. RTL labels allow R, AL, AN, EN, ES, CS, ET, ON, BN and NSM;
        // 4. not both EN and AN.
        if mask & !dir::R_AL_AN_EN_ES_CS_ET_ON_BN_NSM != 0 {
            info.is_ok_bidi = false;
        }
        if mask & dir::EN_AN == dir::EN_AN {
            info.is_ok_bidi = false;
        }
    }
    if mask & dir::R_AL_AN != 0 {
        info.is_bidi = true;
    }
}

/// `isASCIIOkBiDi()` (UTF-8 version) for the source's all-ASCII prefix of
/// labels, which ends with a dot.
fn is_ascii_ok_bidi(s: &[u8]) -> bool {
    let mut label_start = 0;
    for (i, &c) in s.iter().enumerate() {
        if c == b'.' {
            if i > label_start && !s[i - 1].is_ascii_alphanumeric() {
                // The last character is not L or EN.
                return false;
            }
            label_start = i + 1;
        } else if i == label_start {
            if !c.is_ascii_alphabetic() {
                // The first character is not L.
                return false;
            }
        } else if c <= 0x20 && (c >= 0x1c || (9..=0xd).contains(&c)) {
            // An intermediate B, S or WS character.
            return false;
        }
    }
    true
}

/// `UTS46::checkLabelContextO()`: RFC 5892 Appendix A.3 to A.9.
fn check_label_context_o(label: &[u16], info: &mut State) {
    let scripts = CodePointMapData::<Script>::new();
    let end = label.len() - 1;
    let mut arabic_digits = 0i8; // -1 for 066x, +1 for 06Fx
    for i in 0..=end {
        let c = label[i];
        if c < 0xb7 {
        } else if c <= 0x6f9 {
            if c == 0xb7 {
                // MIDDLE DOT between two l.
                if !(i > 0 && label[i - 1] == 0x6c && i < end && label[i + 1] == 0x6c) {
                    info.label_errors |= IDNA_ERROR_CONTEXTO_PUNCTUATION;
                }
            } else if c == 0x375 {
                // GREEK LOWER NUMERAL SIGN before a Greek character.
                let mut greek = false;
                if i < end {
                    let mut j = i + 1;
                    greek = scripts.get32(next(label, &mut j)) == Script::Greek;
                }
                if !greek {
                    info.label_errors |= IDNA_ERROR_CONTEXTO_PUNCTUATION;
                }
            } else if c == 0x5f3 || c == 0x5f4 {
                // HEBREW GERESH / GERSHAYIM after a Hebrew character.
                let mut hebrew = false;
                if i > 0 {
                    let mut j = i;
                    hebrew = scripts.get32(prev(label, &mut j)) == Script::Hebrew;
                }
                if !hebrew {
                    info.label_errors |= IDNA_ERROR_CONTEXTO_PUNCTUATION;
                }
            } else if c >= 0x660 {
                // ARABIC-INDIC and EXTENDED ARABIC-INDIC digits do not mix.
                if c <= 0x669 {
                    if arabic_digits > 0 {
                        info.label_errors |= IDNA_ERROR_CONTEXTO_DIGITS;
                    }
                    arabic_digits = -1;
                } else if c >= 0x6f0 {
                    if arabic_digits < 0 {
                        info.label_errors |= IDNA_ERROR_CONTEXTO_DIGITS;
                    }
                    arabic_digits = 1;
                }
            }
        } else if c == 0x30fb {
            // KATAKANA MIDDLE DOT with a Hiragana, Katakana or Han character.
            let mut j = 0;
            loop {
                if j > end {
                    info.label_errors |= IDNA_ERROR_CONTEXTO_PUNCTUATION;
                    break;
                }
                let script = scripts.get32(next(label, &mut j));
                if script == Script::Hiragana || script == Script::Katakana || script == Script::Han {
                    break;
                }
            }
        }
    }
}

// Punycode (RFC 3492) as ICU implements it on UTF-16.

const BASE: i64 = 36;
const TMIN: i64 = 1;
const TMAX: i64 = 26;
const SKEW: i64 = 38;
const DAMP: i64 = 700;
const INITIAL_BIAS: i64 = 72;
const INITIAL_N: i64 = 0x80;
const MAX: i64 = 0x7fff_ffff;

fn adapt_bias(mut delta: i64, length: i64, first_time: bool) -> i64 {
    delta /= if first_time { DAMP } else { 2 };
    delta += delta / length;
    let mut count = 0;
    while delta > ((BASE - TMIN) * TMAX) / 2 {
        delta /= BASE - TMIN;
        count += BASE;
    }
    count + ((BASE - TMIN + 1) * delta) / (delta + SKEW)
}

fn threshold(k: i64, bias: i64) -> i64 {
    let t = k - bias;
    if t < TMIN {
        TMIN
    } else if k >= bias + TMAX {
        TMAX
    } else {
        t
    }
}

fn digit_to_basic(digit: i64) -> u16 {
    if digit < 26 { (i64::from(b'a') + digit) as u16 } else { (i64::from(b'0') - 26 + digit) as u16 }
}

fn decode_digit(c: u16) -> i64 {
    let c = i64::from(c);
    if c <= i64::from(b'Z') {
        if c <= i64::from(b'9') {
            if c < i64::from(b'0') { -1 } else { c - i64::from(b'0') + 26 }
        } else {
            c - i64::from(b'A')
        }
    } else if c <= i64::from(b'z') {
        c - i64::from(b'a')
    } else {
        -1
    }
}

/// `u_strToPunycode()` without case flags.
fn punycode_encode(src: &[u16]) -> Result<Vec<u16>, Failure> {
    if src.len() > 1000 {
        return Err(Failure); // U_INPUT_TOO_LONG_ERROR
    }
    let mut cps: Vec<i64> = Vec::with_capacity(src.len());
    let mut dest = Vec::new();
    let mut j = 0;
    while j < src.len() {
        let c = src[j];
        if c < 0x80 {
            cps.push(0);
            dest.push(c);
        } else if !is_surrogate(c) {
            cps.push(i64::from(c));
        } else if is_lead(c) && j + 1 < src.len() && is_trail(src[j + 1]) {
            j += 1;
            cps.push(i64::from(supplementary(c, src[j])));
        } else {
            return Err(Failure); // U_INVALID_CHAR_FOUND
        }
        j += 1;
    }
    let basic = dest.len() as i64;
    if basic > 0 {
        dest.push(u16::from(b'-'));
    }
    let (mut n, mut delta, mut bias) = (INITIAL_N, 0i64, INITIAL_BIAS);
    let mut handled = basic;
    while handled < cps.len() as i64 {
        let m = cps.iter().copied().filter(|&q| n <= q).min().unwrap_or(MAX);
        if m - n > (MAX - handled - delta) / (handled + 1) {
            return Err(Failure); // U_INTERNAL_PROGRAM_ERROR
        }
        delta += (m - n) * (handled + 1);
        n = m;
        for &q in &cps {
            if q < n {
                delta += 1;
            } else if q == n {
                let mut q = delta;
                let mut k = BASE;
                loop {
                    let t = threshold(k, bias);
                    if q < t {
                        break;
                    }
                    dest.push(digit_to_basic(t + (q - t) % (BASE - t)));
                    q = (q - t) / (BASE - t);
                    k += BASE;
                }
                dest.push(digit_to_basic(q));
                bias = adapt_bias(delta, handled + 1, handled == basic);
                delta = 0;
                handled += 1;
            }
        }
        delta += 1;
        n += 1;
    }
    Ok(dest)
}

/// `u_strFromPunycode()` without case flags.
fn punycode_decode(src: &[u16]) -> Result<Vec<u16>, Failure> {
    if src.len() > 2000 {
        return Err(Failure); // U_INPUT_TOO_LONG_ERROR
    }
    // The basic code points are those before the last delimiter.
    let basic = src.iter().rposition(|&c| c == u16::from(b'-')).unwrap_or(0);
    if src[..basic].iter().any(|&c| c >= 0x80) {
        return Err(Failure);
    }
    let mut cps: Vec<u32> = src[..basic].iter().map(|&c| u32::from(c)).collect();
    let (mut n, mut i, mut bias) = (INITIAL_N, 0i64, INITIAL_BIAS);
    let mut input = if basic > 0 { basic + 1 } else { 0 };
    while input < src.len() {
        let old_i = i;
        let mut w = 1i64;
        let mut k = BASE;
        loop {
            if input >= src.len() {
                return Err(Failure);
            }
            let digit = decode_digit(src[input]);
            input += 1;
            if digit < 0 || digit > (MAX - i) / w {
                return Err(Failure);
            }
            i += digit * w;
            let t = threshold(k, bias);
            if digit < t {
                break;
            }
            if w > MAX / (BASE - t) {
                return Err(Failure);
            }
            w *= BASE - t;
            k += BASE;
        }
        let count = cps.len() as i64 + 1;
        bias = adapt_bias(i - old_i, count, old_i == 0);
        if i / count > MAX - n {
            return Err(Failure);
        }
        n += i / count;
        i %= count;
        if n > 0x10ffff || (0xd800..0xe000).contains(&n) {
            return Err(Failure);
        }
        cps.insert(i as usize, n as u32);
        i += 1;
    }
    let mut out = Vec::with_capacity(cps.len());
    let mut buf = [0u16; 2];
    for cp in cps {
        out.extend_from_slice(char::from_u32(cp).unwrap_or('\u{fffd}').encode_utf16(&mut buf));
    }
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ascii(s: &str, flags: i64) -> Option<String> {
        to_ascii(s.as_bytes(), Flags::from_bits(flags)).unwrap().map(|v| String::from_utf8(v).unwrap())
    }

    fn utf8(s: &str, flags: i64) -> Option<String> {
        to_utf8(s.as_bytes(), Flags::from_bits(flags)).unwrap().map(|v| String::from_utf8(v).unwrap())
    }

    #[test]
    fn nontransitional_by_default() {
        let default = |s: &str| to_ascii(s.as_bytes(), Flags::default()).unwrap();
        assert_eq!(default("faß.de").as_deref(), Some(&b"xn--fa-hia.de"[..]));
        let default = |s: &str| to_utf8(s.as_bytes(), Flags::default()).unwrap();
        assert_eq!(default("faß.de").as_deref(), Some("faß.de".as_bytes()));
    }

    #[test]
    fn transitional_with_flags_0() {
        assert_eq!(ascii("faß.de", 0).as_deref(), Some("fass.de"));
        assert_eq!(ascii("faß.de", IDNA_NONTRANSITIONAL_TO_ASCII).as_deref(), Some("xn--fa-hia.de"));
        assert_eq!(utf8("xn--fa-hia.de", 0).as_deref(), Some("faß.de"));
        assert_eq!(utf8("faß.de", 0).as_deref(), Some("fass.de"));
    }

    #[test]
    fn errors_return_false() {
        assert_eq!(ascii("a..b", 0), None);
        assert_eq!(ascii("-a", 0), None);
        assert_eq!(ascii("ab--c", 0), None);
        assert_eq!(ascii("a b", IDNA_USE_STD3_RULES), None);
        assert_eq!(ascii("a b", 0).as_deref(), Some("a b"));
        assert_eq!(ascii("\u{378}", 0), None);
    }

    #[test]
    fn empty_is_a_value_error() {
        let e = to_ascii(b"", Flags::default()).unwrap_err();
        assert_eq!(e.to_string(), "idn_to_ascii(): Argument #1 ($domain) must not be empty");
    }

    #[test]
    fn punycode_round_trip() {
        let label: Vec<u16> = "bücher".encode_utf16().collect();
        let encoded = punycode_encode(&label).unwrap();
        assert_eq!(String::from_utf16(&encoded).unwrap(), "bcher-kva");
        assert_eq!(punycode_decode(&encoded).unwrap(), label);
    }
}
