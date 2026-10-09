//! Byte-string functions of `ext/standard/string.c` and `ext/ctype`.
//!
//! PHP strings are byte strings: every function here takes and returns
//! `&[u8]` / `Vec<u8>`, never assumes UTF-8 and handles invalid UTF-8 like
//! any other byte. Case mapping (`strtolower`, `ucfirst`, `stripos`, ...) is
//! ASCII-only, as in PHP 8.2 and later.
//!
//! Callers holding a `&str` pass `s.as_bytes()`. For valid UTF-8 arguments
//! these keep the result valid UTF-8, so `String::from_utf8` (or
//! `std::str::from_utf8`) on it cannot fail: the case functions, `trim`
//! family with an ASCII character list (such as [`TRIM_CHARACTERS`]),
//! `str_replace`/`str_ireplace`/`strtr_array` with UTF-8 strings, `explode`,
//! `implode`, `nl2br`, `str_repeat`, `quotemeta`, `strstr`/`stristr`/`strrchr`.
//! Functions that work on single bytes or byte positions (`substr`,
//! `str_pad`, `str_split`, `wordwrap` with `cut`, `strrev`, `chunk_split`,
//! `strtr` with non-ASCII bytes, `strpbrk`) may split a character, as in PHP.
//!
//! Arguments PHP takes as `int` (offsets, lengths) are `i64`, with PHP's
//! negative-offset rules. Results that PHP returns as `false` are `None`.
//! Errors PHP throws are [`Error`], one variant per exception class, with
//! PHP's message.
//!
//! | PHP | Rust |
//! |---|---|
//! | `strtolower`, `strtoupper`, `ucfirst`, `lcfirst`, `ucwords` | [`strtolower`], [`strtoupper`], [`ucfirst`], [`lcfirst`], [`ucwords`] |
//! | `trim`, `ltrim`, `rtrim` | [`trim`], [`ltrim`], [`rtrim`] ([`TRIM_CHARACTERS`] is the default) |
//! | `str_pad` | [`str_pad`] with [`Pad`] |
//! | `substr`, `substr_replace`, `substr_count`, `substr_compare` | [`substr`], [`substr_replace`], [`substr_replace_array`], [`substr_count`], [`substr_compare`] |
//! | `strpos`, `stripos`, `strrpos`, `strripos` | [`strpos`], [`stripos`], [`strrpos`], [`strripos`] |
//! | `str_contains`, `str_starts_with`, `str_ends_with` | [`str_contains`], [`str_starts_with`], [`str_ends_with`] |
//! | `strstr`, `stristr`, `strrchr`, `strpbrk` | [`strstr`], [`stristr`], [`strrchr`], [`strpbrk`] |
//! | `strspn`, `strcspn` | [`strspn`], [`strcspn`] |
//! | `strcmp`, `strcasecmp`, `strncmp`, `strncasecmp` | [`strcmp`], [`strcasecmp`], [`strncmp`], [`strncasecmp`] |
//! | `str_replace`, `str_ireplace` | [`str_replace`], [`str_ireplace`], [`str_replace_array`] |
//! | `strtr` | [`strtr`], [`strtr_array`] |
//! | `explode`, `implode` | [`explode`], [`implode`], [`implode_values`] |
//! | `str_split`, `chunk_split`, `wordwrap`, `nl2br` | [`str_split`], [`chunk_split`], [`wordwrap`], [`nl2br`] |
//! | `str_repeat`, `strrev`, `ord`, `chr`, `quotemeta` | [`str_repeat`], [`strrev`], [`ord`], [`chr`], [`quotemeta`] |
//! | `addcslashes`, `stripcslashes` | [`addcslashes`], [`stripcslashes`] |
//! | `ctype_*` | [`ctype_alnum`], [`ctype_alpha`], [`ctype_cntrl`], [`ctype_digit`], [`ctype_graph`], [`ctype_lower`], [`ctype_print`], [`ctype_punct`], [`ctype_space`], [`ctype_upper`], [`ctype_xdigit`] |
//! | `(string) $value` (`zval_get_string`) | [`to_php_string`] |
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `string.*`).

use std::borrow::Cow;
use std::fmt;

use serde_json::Value;

use crate::number;

/// An exception a PHP string, multibyte, encoding or formatting function
/// throws: one variant per PHP exception class, carrying PHP's message.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Error {
    /// `ValueError`: an argument has a value the function rejects.
    Value(String),
    /// `TypeError`: an argument combination PHP rejects by type.
    Type(String),
    /// `ArgumentCountError`: too few arguments (`sprintf`).
    ArgumentCount(String),
    /// `DivisionByZeroError` (`intdiv`).
    DivisionByZero(String),
    /// `ArithmeticError` (`intdiv(PHP_INT_MIN, -1)`).
    Arithmetic(String),
    /// `Error`, e.g. converting a `stdClass` to a string.
    Error(String),
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Value(_) => "ValueError",
            Error::Type(_) => "TypeError",
            Error::ArgumentCount(_) => "ArgumentCountError",
            Error::DivisionByZero(_) => "DivisionByZeroError",
            Error::Arithmetic(_) => "ArithmeticError",
            Error::Error(_) => "Error",
        }
    }

    /// PHP's exception message.
    pub fn message(&self) -> &str {
        match self {
            Error::Value(m)
            | Error::Type(m)
            | Error::ArgumentCount(m)
            | Error::DivisionByZero(m)
            | Error::Arithmetic(m)
            | Error::Error(m) => m,
        }
    }

    /// `zend_argument_value_error()`: `"<function>(): Argument #<n> ($<name>) <what>"`.
    pub(crate) fn argument(function: &str, n: u32, name: &str, what: &str) -> Self {
        Error::Value(format!("{function}(): Argument #{n} (${name}) {what}"))
    }
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self.message())
    }
}

impl std::error::Error for Error {}

// ---------------------------------------------------------------------------
// Case (ASCII only since PHP 8.2)
// ---------------------------------------------------------------------------

/// PHP `strtolower`: ASCII `A-Z` to `a-z`; every other byte unchanged.
/// Borrows when nothing changes. Valid UTF-8 stays valid UTF-8.
pub fn strtolower(s: &[u8]) -> Cow<'_, [u8]> {
    match s.iter().position(u8::is_ascii_uppercase) {
        None => Cow::Borrowed(s),
        Some(_) => Cow::Owned(s.to_ascii_lowercase()),
    }
}

/// PHP `strtoupper`: ASCII `a-z` to `A-Z`; every other byte unchanged.
/// Borrows when nothing changes. Valid UTF-8 stays valid UTF-8.
pub fn strtoupper(s: &[u8]) -> Cow<'_, [u8]> {
    match s.iter().position(u8::is_ascii_lowercase) {
        None => Cow::Borrowed(s),
        Some(_) => Cow::Owned(s.to_ascii_uppercase()),
    }
}

/// PHP `ucfirst`: uppercases the first byte if it is an ASCII letter.
pub fn ucfirst(s: &[u8]) -> Cow<'_, [u8]> {
    match s.first() {
        Some(c) if c.is_ascii_lowercase() => {
            let mut out = s.to_vec();
            out[0] = c.to_ascii_uppercase();
            Cow::Owned(out)
        }
        _ => Cow::Borrowed(s),
    }
}

/// PHP `lcfirst`: lowercases the first byte if it is an ASCII letter.
pub fn lcfirst(s: &[u8]) -> Cow<'_, [u8]> {
    match s.first() {
        Some(c) if c.is_ascii_uppercase() => {
            let mut out = s.to_vec();
            out[0] = c.to_ascii_lowercase();
            Cow::Owned(out)
        }
        _ => Cow::Borrowed(s),
    }
}

/// The default `$separators` of `ucwords`: `" \t\r\n\f\v"`.
pub const UCWORDS_SEPARATORS: &[u8] = b" \t\r\n\x0c\x0b";

/// PHP `ucwords($string, $separators)`: uppercases (ASCII) the first byte
/// and every byte that follows one of `separators`. `separators` accepts
/// `a..z` ranges like [`trim`]. Pass [`UCWORDS_SEPARATORS`] for PHP's default.
///
/// As in PHP, the separator test looks at the previous byte *after* it was
/// uppercased, so `ucwords("aab", "A")` is `"AAB"`.
pub fn ucwords<'a>(s: &'a [u8], separators: &[u8]) -> Cow<'a, [u8]> {
    if s.is_empty() {
        return Cow::Borrowed(s);
    }
    let mask = charmask(separators);
    let mut out = s.to_vec();
    out[0] = out[0].to_ascii_uppercase();
    for i in 1..out.len() {
        if mask[out[i - 1] as usize] {
            out[i] = out[i].to_ascii_uppercase();
        }
    }
    if out == s { Cow::Borrowed(s) } else { Cow::Owned(out) }
}

// ---------------------------------------------------------------------------
// Character masks and trimming
// ---------------------------------------------------------------------------

/// `php_charmask`: the set of bytes a `trim`/`ucwords`/`addcslashes`
/// character list names. `a..z` adds an inclusive range; a malformed range
/// (PHP warns) adds what PHP adds: for `"a.."` that is `a` and the last `.`.
pub fn charmask(chars: &[u8]) -> [bool; 256] {
    let mut mask = [false; 256];
    let len = chars.len();
    let mut i = 0;
    while i < len {
        let c = chars[i];
        if i + 3 < len && chars[i + 1] == b'.' && chars[i + 2] == b'.' && chars[i + 3] >= c {
            for b in c..=chars[i + 3] {
                mask[b as usize] = true;
            }
            i += 4;
        } else if i + 1 < len && chars[i] == b'.' && chars[i + 1] == b'.' {
            // An invalid range: PHP warns and skips this '.' only.
            i += 1;
        } else {
            mask[c as usize] = true;
            i += 1;
        }
    }
    mask
}

/// The default character list of `trim`, `ltrim` and `rtrim`: `" \n\r\t\v\0"`.
pub const TRIM_CHARACTERS: &[u8] = b" \n\r\t\x0b\0";

fn trim_mode<'a>(s: &'a [u8], characters: &[u8], left: bool, right: bool) -> &'a [u8] {
    let mask = charmask(characters);
    let mut start = 0;
    let mut end = s.len();
    if left {
        while start < end && mask[s[start] as usize] {
            start += 1;
        }
    }
    if right {
        while end > start && mask[s[end - 1] as usize] {
            end -= 1;
        }
    }
    &s[start..end]
}

/// PHP `trim($string, $characters)`: strips the bytes of `characters` (with
/// `a..z` ranges, see [`charmask`]) from both ends. Pass [`TRIM_CHARACTERS`]
/// for PHP's default.
pub fn trim<'a>(s: &'a [u8], characters: &[u8]) -> &'a [u8] {
    trim_mode(s, characters, true, true)
}

/// PHP `ltrim($string, $characters)`: like [`trim`], start only.
pub fn ltrim<'a>(s: &'a [u8], characters: &[u8]) -> &'a [u8] {
    trim_mode(s, characters, true, false)
}

/// PHP `rtrim($string, $characters)` (alias `chop`): like [`trim`], end only.
pub fn rtrim<'a>(s: &'a [u8], characters: &[u8]) -> &'a [u8] {
    trim_mode(s, characters, false, true)
}

// ---------------------------------------------------------------------------
// Padding
// ---------------------------------------------------------------------------

/// Where `str_pad` / `mb_str_pad` add padding (`STR_PAD_*`).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum Pad {
    /// `STR_PAD_LEFT` (0).
    Left,
    /// `STR_PAD_RIGHT` (1), the default.
    #[default]
    Right,
    /// `STR_PAD_BOTH` (2): half on the left (rounded down), the rest on the right.
    Both,
}

impl Pad {
    /// The `STR_PAD_*` constant's value; `None` for any other integer (PHP
    /// throws a `ValueError` once padding is needed).
    pub fn from_php(value: i64) -> Option<Self> {
        match value {
            0 => Some(Pad::Left),
            1 => Some(Pad::Right),
            2 => Some(Pad::Both),
            _ => None,
        }
    }

    /// Left and right padding counts for `total` padding units.
    pub(crate) fn split(self, total: usize) -> (usize, usize) {
        match self {
            Pad::Left => (total, 0),
            Pad::Right => (0, total),
            Pad::Both => (total / 2, total - total / 2),
        }
    }
}

/// PHP `str_pad($string, $length, $pad_string, $pad_type)`: pads to `length`
/// bytes by repeating `pad`, cutting the last repetition. A `length` not
/// above the input length returns the input unchanged (even with an empty
/// `pad`).
pub fn str_pad<'a>(s: &'a [u8], length: i64, pad: &[u8], pad_type: Pad) -> Result<Cow<'a, [u8]>, Error> {
    if length < 0 || (length as u64) <= s.len() as u64 {
        return Ok(Cow::Borrowed(s));
    }
    if pad.is_empty() {
        return Err(Error::argument("str_pad", 3, "pad_string", "must not be empty"));
    }
    let total = length as usize - s.len();
    let (left, right) = pad_type.split(total);
    let mut out = Vec::with_capacity(length as usize);
    out.extend((0..left).map(|i| pad[i % pad.len()]));
    out.extend_from_slice(s);
    out.extend((0..right).map(|i| pad[i % pad.len()]));
    Ok(Cow::Owned(out))
}

// ---------------------------------------------------------------------------
// Substrings and searching
// ---------------------------------------------------------------------------

/// PHP `substr($string, $offset, $length)`: a negative `offset` counts from
/// the end; a negative `length` stops that many bytes before the end;
/// `None` takes the rest. Out-of-range values clamp; never fails.
pub fn substr(s: &[u8], offset: i64, length: Option<i64>) -> &[u8] {
    let len = s.len() as u64;
    let from = if offset < 0 {
        len.saturating_sub(offset.unsigned_abs())
    } else if offset as u64 > len {
        return &[];
    } else {
        offset as u64
    };
    let rest = len - from;
    let take = match length {
        None => rest,
        Some(l) if l < 0 => rest.saturating_sub(l.unsigned_abs()),
        Some(l) => (l as u64).min(rest),
    };
    &s[from as usize..(from + take) as usize]
}

/// `php_memnstr`: first occurrence of `needle` in `haystack` (an empty needle
/// matches at 0).
pub(crate) fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    if needle.is_empty() {
        return Some(0);
    }
    if needle.len() > haystack.len() {
        return None;
    }
    if needle.len() == 1 {
        return haystack.iter().position(|&b| b == needle[0]);
    }
    let first = needle[0];
    let last_start = haystack.len() - needle.len();
    let mut i = 0;
    while i <= last_start {
        i += haystack[i..=last_start].iter().position(|&b| b == first)?;
        if &haystack[i..i + needle.len()] == needle {
            return Some(i);
        }
        i += 1;
    }
    None
}

/// `zend_memnrstr`: last occurrence of `needle` that ends at or before the
/// end of `haystack` (an empty needle matches at the end).
pub(crate) fn rfind(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    if needle.is_empty() {
        return Some(haystack.len());
    }
    if needle.len() > haystack.len() {
        return None;
    }
    (0..=haystack.len() - needle.len()).rev().find(|&i| &haystack[i..i + needle.len()] == needle)
}

/// Case-insensitive (ASCII) [`find`].
fn find_ci(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    if needle.is_empty() {
        return Some(0);
    }
    if needle.len() > haystack.len() {
        return None;
    }
    (0..=haystack.len() - needle.len()).find(|&i| haystack[i..i + needle.len()].eq_ignore_ascii_case(needle))
}

fn offset_error(function: &str) -> Error {
    Error::argument(function, 3, "offset", "must be contained in argument #1 ($haystack)")
}

/// The start position for `strpos`/`stripos`: a negative offset counts from
/// the end; anything outside `0..=len` is PHP's `ValueError`.
fn forward_start(function: &str, haystack: &[u8], offset: i64) -> Result<usize, Error> {
    let len = haystack.len() as i64;
    let start = if offset < 0 { offset.saturating_add(len) } else { offset };
    if start < 0 || start > len {
        return Err(offset_error(function));
    }
    Ok(start as usize)
}

/// PHP `strpos($haystack, $needle, $offset)`: byte position of the first
/// occurrence at or after `offset` (negative: from the end), `None` for
/// `false`. An empty needle matches at the offset.
pub fn strpos(haystack: &[u8], needle: &[u8], offset: i64) -> Result<Option<usize>, Error> {
    let start = forward_start("strpos", haystack, offset)?;
    Ok(find(&haystack[start..], needle).map(|p| p + start))
}

/// PHP `stripos`: [`strpos`] with ASCII case-insensitive matching.
pub fn stripos(haystack: &[u8], needle: &[u8], offset: i64) -> Result<Option<usize>, Error> {
    let start = forward_start("stripos", haystack, offset)?;
    Ok(find_ci(&haystack[start..], needle).map(|p| p + start))
}

/// The search window `[from, to)` for `strrpos`/`strripos`: the match must
/// start at or after `from` and end at or before `to`.
fn reverse_window(function: &str, haystack: &[u8], needle_len: usize, offset: i64) -> Result<(usize, usize), Error> {
    let len = haystack.len();
    if offset >= 0 {
        if offset as u64 > len as u64 {
            return Err(offset_error(function));
        }
        Ok((offset as usize, len))
    } else {
        if offset < -i64::MAX || offset.unsigned_abs() > len as u64 {
            return Err(offset_error(function));
        }
        let back = offset.unsigned_abs() as usize;
        let end = if back < needle_len { len } else { len - back + needle_len };
        Ok((0, end))
    }
}

/// PHP `strrpos($haystack, $needle, $offset)`: byte position of the last
/// occurrence. A non-negative `offset` skips that many bytes from the start;
/// a negative one makes the search start that many bytes before the end
/// (the match may begin at most there).
pub fn strrpos(haystack: &[u8], needle: &[u8], offset: i64) -> Result<Option<usize>, Error> {
    let (from, to) = reverse_window("strrpos", haystack, needle.len(), offset)?;
    Ok(rfind(&haystack[from..to.max(from)], needle).map(|p| p + from))
}

/// PHP `strripos`: [`strrpos`] with ASCII case-insensitive matching.
pub fn strripos(haystack: &[u8], needle: &[u8], offset: i64) -> Result<Option<usize>, Error> {
    if needle.len() == 1 {
        // PHP's single-byte path: scans back from the end (or from
        // `len + offset` for a negative offset), inclusive.
        let len = haystack.len();
        let (from, last) = if offset >= 0 {
            if offset as u64 > len as u64 {
                return Err(offset_error("strripos"));
            }
            (offset as usize, len as i64 - 1)
        } else {
            if offset < -i64::MAX || offset.unsigned_abs() > len as u64 {
                return Err(offset_error("strripos"));
            }
            (0, len as i64 + offset)
        };
        let lowered = needle[0].to_ascii_lowercase();
        let mut e = last;
        while e >= from as i64 {
            if haystack[e as usize].to_ascii_lowercase() == lowered {
                return Ok(Some(e as usize));
            }
            e -= 1;
        }
        return Ok(None);
    }
    let lower = haystack.to_ascii_lowercase();
    let lower_needle = needle.to_ascii_lowercase();
    let (from, to) = reverse_window("strripos", haystack, needle.len(), offset)?;
    Ok(rfind(&lower[from..to.max(from)], &lower_needle).map(|p| p + from))
}

/// PHP `str_contains`.
pub fn str_contains(haystack: &[u8], needle: &[u8]) -> bool {
    find(haystack, needle).is_some()
}

/// PHP `str_starts_with`.
pub fn str_starts_with(haystack: &[u8], needle: &[u8]) -> bool {
    haystack.starts_with(needle)
}

/// PHP `str_ends_with`.
pub fn str_ends_with(haystack: &[u8], needle: &[u8]) -> bool {
    haystack.ends_with(needle)
}

/// PHP `strstr($haystack, $needle, $before_needle)`: the part from the first
/// occurrence of `needle` on (or before it), `None` for `false`.
pub fn strstr<'a>(haystack: &'a [u8], needle: &[u8], before_needle: bool) -> Option<&'a [u8]> {
    let p = find(haystack, needle)?;
    Some(if before_needle { &haystack[..p] } else { &haystack[p..] })
}

/// PHP `stristr`: [`strstr`] with ASCII case-insensitive matching.
pub fn stristr<'a>(haystack: &'a [u8], needle: &[u8], before_needle: bool) -> Option<&'a [u8]> {
    let p = find_ci(haystack, needle)?;
    Some(if before_needle { &haystack[..p] } else { &haystack[p..] })
}

/// PHP `strrchr($haystack, $needle, $before_needle)`: from the last
/// occurrence of the *first byte* of `needle` (NUL for an empty needle).
pub fn strrchr<'a>(haystack: &'a [u8], needle: &[u8], before_needle: bool) -> Option<&'a [u8]> {
    let c = needle.first().copied().unwrap_or(0);
    let p = haystack.iter().rposition(|&b| b == c)?;
    Some(if before_needle { &haystack[..p] } else { &haystack[p..] })
}

/// PHP `strpbrk($string, $characters)`: the part from the first byte that is
/// in `characters` (a plain byte list, no ranges).
pub fn strpbrk<'a>(s: &'a [u8], characters: &[u8]) -> Result<Option<&'a [u8]>, Error> {
    if characters.is_empty() {
        return Err(Error::argument("strpbrk", 2, "characters", "must be a non-empty string"));
    }
    Ok(s.iter().position(|b| characters.contains(b)).map(|p| &s[p..]))
}

/// PHP `substr_count($haystack, $needle, $offset, $length)`: non-overlapping
/// occurrences of `needle` in the given part of `haystack`.
pub fn substr_count(haystack: &[u8], needle: &[u8], offset: i64, length: Option<i64>) -> Result<usize, Error> {
    if needle.is_empty() {
        return Err(Error::argument("substr_count", 2, "needle", "must not be empty"));
    }
    let mut hay = haystack;
    if offset != 0 {
        let len = hay.len() as i64;
        let start = if offset < 0 { offset.saturating_add(len) } else { offset };
        if start < 0 || start > len {
            return Err(Error::argument("substr_count", 3, "offset", "must be contained in argument #1 ($haystack)"));
        }
        hay = &hay[start as usize..];
    }
    if let Some(l) = length {
        let len = hay.len() as i64;
        let l = if l < 0 { l.saturating_add(len) } else { l };
        if l < 0 || l > len {
            return Err(Error::argument("substr_count", 4, "length", "must be contained in argument #1 ($haystack)"));
        }
        hay = &hay[..l as usize];
    }
    let mut count = 0;
    let mut p = 0;
    while let Some(i) = find(&hay[p..], needle) {
        count += 1;
        p += i + needle.len();
    }
    Ok(count)
}

/// Resolves `strspn`/`strcspn`'s `$offset` and `$length` (clamping, never failing).
fn span_window(s: &[u8], offset: i64, length: Option<i64>) -> &[u8] {
    let len = s.len() as i64;
    let start = if offset < 0 { offset.saturating_add(len).max(0) } else { offset.min(len) };
    let remain = len - start;
    let take = match length {
        None => remain,
        Some(l) if l < 0 => l.saturating_add(remain).max(0),
        Some(l) => l.min(remain),
    };
    &s[start as usize..(start + take) as usize]
}

/// PHP `strspn($string, $characters, $offset, $length)`: length of the
/// initial run of bytes that are in `characters` (a plain byte list).
pub fn strspn(s: &[u8], characters: &[u8], offset: i64, length: Option<i64>) -> usize {
    let w = span_window(s, offset, length);
    w.iter().position(|b| !characters.contains(b)).unwrap_or(w.len())
}

/// PHP `strcspn($string, $characters, $offset, $length)`: length of the
/// initial run of bytes that are *not* in `characters`.
pub fn strcspn(s: &[u8], characters: &[u8], offset: i64, length: Option<i64>) -> usize {
    let w = span_window(s, offset, length);
    w.iter().position(|b| characters.contains(b)).unwrap_or(w.len())
}

/// PHP `substr_replace($string, $replace, $offset, $length)` for a single
/// string: replaces the part [`substr`] would select (with clamping) by
/// `replace`. `length` `None` replaces to the end. (An array `$replace` with
/// a string `$string` uses its first element: [`Replace::first`].)
pub fn substr_replace(s: &[u8], replace: &[u8], offset: i64, length: Option<i64>) -> Vec<u8> {
    let len = s.len() as i64;
    let f = clamp_offset(offset, len);
    let mut l = length.unwrap_or(len);
    if l < 0 {
        l = (len - f).saturating_add(l).max(0);
    }
    splice(s, replace, f, l.min(len))
}

/// `substr_replace`'s start: negative from the end, clamped to `0..=len`.
fn clamp_offset(offset: i64, len: i64) -> i64 {
    if offset < 0 { offset.saturating_add(len).max(0) } else { offset.min(len) }
}

/// `s` with `l` bytes at `f` (clamped to the end) replaced by `replace`.
fn splice(s: &[u8], replace: &[u8], f: i64, l: i64) -> Vec<u8> {
    let len = s.len() as i64;
    let l = if f.saturating_add(l) > len { len - f } else { l };
    let (f, l) = (f as usize, l as usize);
    let mut out = Vec::with_capacity(s.len() - l + replace.len());
    out.extend_from_slice(&s[..f]);
    out.extend_from_slice(replace);
    out.extend_from_slice(&s[f + l..]);
    out
}

/// One value for every string, or one per string, in the array forms of
/// `substr_replace`.
#[derive(Debug, Clone, Copy)]
pub enum PerString<'a> {
    /// An integer `$offset`/`$length`: the same for every string.
    All(i64),
    /// An array: the n-th value for the n-th string (PHP converts each with
    /// `(int)`); strings beyond its end get the default (offset 0, length to
    /// the end).
    Each(&'a [i64]),
}

/// PHP `substr_replace($array, $replace, $offset, $length)` for an array of
/// strings: [`substr_replace`] on each string, with `replace`, `offset` and
/// `length` either shared or taken in turn from arrays (a missing
/// replacement removes the selected part). The caller keeps the keys of
/// `$array`. A string `$string` with an array `$offset` or `$length` is a
/// `TypeError` in PHP and has no form here.
pub fn substr_replace_array(
    strings: &[&[u8]],
    replace: Replace<'_>,
    offset: PerString<'_>,
    length: Option<PerString<'_>>,
) -> Vec<Vec<u8>> {
    strings
        .iter()
        .enumerate()
        .map(|(i, s)| {
            let len = s.len() as i64;
            let f = match offset {
                PerString::All(o) => clamp_offset(o, len),
                PerString::Each(list) => list.get(i).map_or(0, |&o| clamp_offset(o, len)),
            };
            let mut l = match length {
                None => len,
                Some(PerString::All(l)) => l,
                Some(PerString::Each(list)) => list.get(i).copied().unwrap_or(len),
            };
            if l < 0 {
                l = (len - f).saturating_add(l).max(0);
            }
            let r: &[u8] = match replace {
                Replace::All(r) => r,
                Replace::Each(list) => list.get(i).copied().unwrap_or(b""),
            };
            splice(s, r, f, l)
        })
        .collect()
}

// ---------------------------------------------------------------------------
// Comparison
// ---------------------------------------------------------------------------

/// `zend_binary_strcmp`: the difference of the first differing bytes, else
/// -1/0/1 by length.
fn compare_bytes(a: &[u8], b: &[u8], fold: bool) -> i64 {
    for (&x, &y) in a.iter().zip(b) {
        let (x, y) = if fold { (x.to_ascii_lowercase(), y.to_ascii_lowercase()) } else { (x, y) };
        if x != y {
            return x as i64 - y as i64;
        }
    }
    (a.len() as i64 - b.len() as i64).signum()
}

/// PHP `strcmp`: the byte difference at the first mismatch (`"a"` vs `"c"`
/// is `-2`), else `-1`, `0` or `1` by length.
pub fn strcmp(a: &[u8], b: &[u8]) -> i64 {
    compare_bytes(a, b, false)
}

/// PHP `strcasecmp`: [`strcmp`] on ASCII-lowercased bytes.
pub fn strcasecmp(a: &[u8], b: &[u8]) -> i64 {
    compare_bytes(a, b, true)
}

fn ncompare(function: &str, a: &[u8], b: &[u8], length: i64, fold: bool) -> Result<i64, Error> {
    if length < 0 {
        return Err(Error::argument(function, 3, "length", "must be greater than or equal to 0"));
    }
    let n = usize::try_from(length).unwrap_or(usize::MAX);
    Ok(compare_bytes(&a[..a.len().min(n)], &b[..b.len().min(n)], fold))
}

/// PHP `strncmp`: [`strcmp`] of at most `length` bytes.
pub fn strncmp(a: &[u8], b: &[u8], length: i64) -> Result<i64, Error> {
    ncompare("strncmp", a, b, length, false)
}

/// PHP `strncasecmp`: [`strcasecmp`] of at most `length` bytes.
pub fn strncasecmp(a: &[u8], b: &[u8], length: i64) -> Result<i64, Error> {
    ncompare("strncasecmp", a, b, length, true)
}

/// PHP `substr_compare($haystack, $needle, $offset, $length, $case_insensitive)`.
pub fn substr_compare(
    haystack: &[u8],
    needle: &[u8],
    offset: i64,
    length: Option<i64>,
    case_insensitive: bool,
) -> Result<i64, Error> {
    if let Some(l) = length {
        if l == 0 {
            return Ok(0);
        }
        if l < 0 {
            return Err(Error::argument("substr_compare", 4, "length", "must be greater than or equal to 0"));
        }
    }
    let len = haystack.len() as i64;
    let mut offset = offset;
    if offset < 0 {
        offset = offset.saturating_add(len).max(0);
    }
    if offset > len {
        return Err(Error::argument("substr_compare", 3, "offset", "must be contained in argument #1 ($haystack)"));
    }
    let rest = &haystack[offset as usize..];
    let n = match length {
        Some(l) => usize::try_from(l).unwrap_or(usize::MAX),
        None => needle.len().max(rest.len()),
    };
    Ok(compare_bytes(&rest[..rest.len().min(n)], &needle[..needle.len().min(n)], case_insensitive))
}

// ---------------------------------------------------------------------------
// Replacement
// ---------------------------------------------------------------------------

/// Replaces every non-overlapping occurrence (left to right) and counts them.
fn replace_all<'a>(subject: Cow<'a, [u8]>, search: &[u8], replace: &[u8], fold: bool) -> (Cow<'a, [u8]>, usize) {
    if search.is_empty() || search.len() > subject.len() {
        return (subject, 0);
    }
    let lowered;
    let (hay, needle): (&[u8], Cow<'_, [u8]>) = if fold {
        lowered = subject.to_ascii_lowercase();
        (&lowered, Cow::Owned(search.to_ascii_lowercase()))
    } else {
        (&subject, Cow::Borrowed(search))
    };
    let mut out: Option<Vec<u8>> = None;
    let mut count = 0;
    let mut p = 0;
    while let Some(i) = find(&hay[p..], &needle) {
        let o = out.get_or_insert_with(|| Vec::with_capacity(subject.len()));
        o.extend_from_slice(&subject[p..p + i]);
        o.extend_from_slice(replace);
        count += 1;
        p += i + needle.len();
    }
    match out {
        None => (subject, 0),
        Some(mut o) => {
            o.extend_from_slice(&subject[p..]);
            (Cow::Owned(o), count)
        }
    }
}

/// PHP `str_replace($search, $replace, $subject, $count)` with string
/// arguments: replaces every occurrence of `search` (left to right, not
/// overlapping) and returns the result with `$count`. An empty `search`
/// changes nothing. Valid UTF-8 arguments give valid UTF-8.
pub fn str_replace<'a>(search: &[u8], replace: &[u8], subject: &'a [u8]) -> (Cow<'a, [u8]>, usize) {
    replace_all(Cow::Borrowed(subject), search, replace, false)
}

/// PHP `str_ireplace` with string arguments: [`str_replace`] matching ASCII
/// case-insensitively (the replacement is inserted as given).
pub fn str_ireplace<'a>(search: &[u8], replace: &[u8], subject: &'a [u8]) -> (Cow<'a, [u8]>, usize) {
    replace_all(Cow::Borrowed(subject), search, replace, true)
}

/// The `$replace` of an array-`$search` `str_replace`.
#[derive(Debug, Clone, Copy)]
pub enum Replace<'a> {
    /// A string: every search string is replaced by it.
    All(&'a [u8]),
    /// An array: the n-th search string is replaced by the n-th element (in
    /// iteration order), or by `""` once the array runs out.
    Each(&'a [&'a [u8]]),
}

impl<'a> Replace<'a> {
    /// The replacement PHP uses where only one fits (`substr_replace` on a
    /// single string): the string, or the array's first element, or `""`.
    pub fn first(&self) -> &'a [u8] {
        match *self {
            Replace::All(r) => r,
            Replace::Each(list) => list.first().copied().unwrap_or(b""),
        }
    }
}

/// PHP `str_replace` / `str_ireplace` with an array `$search`: applies each
/// search/replacement pair in turn to the result of the previous one, skipping
/// empty search strings, and returns the total count. The array forms of
/// `$subject` apply this to each element (keys kept, counts summed); a string
/// `$search` with an array `$replace` is a `TypeError` in PHP and cannot be
/// expressed here.
pub fn str_replace_array<'a>(
    search: &[&[u8]],
    replace: Replace<'_>,
    subject: &'a [u8],
    case_insensitive: bool,
) -> (Cow<'a, [u8]>, usize) {
    let mut out = Cow::Borrowed(subject);
    let mut total = 0;
    for (i, s) in search.iter().enumerate() {
        let r: &[u8] = match replace {
            Replace::All(r) => r,
            Replace::Each(list) => list.get(i).copied().unwrap_or(b""),
        };
        if s.is_empty() {
            continue;
        }
        let (next, n) = replace_all(out, s, r, case_insensitive);
        out = next;
        total += n;
    }
    (out, total)
}

/// PHP `strtr($string, $from, $to)`: translates each byte of `from` to the
/// byte at the same position of `to`, ignoring the extra bytes of the longer
/// one. When a byte appears twice in `from`, the last one wins.
pub fn strtr<'a>(s: &'a [u8], from: &[u8], to: &[u8]) -> Cow<'a, [u8]> {
    let n = from.len().min(to.len());
    if s.is_empty() || n == 0 {
        return Cow::Borrowed(s);
    }
    let mut table: [u8; 256] = std::array::from_fn(|i| i as u8);
    for i in 0..n {
        table[from[i] as usize] = to[i];
    }
    if s.iter().all(|&b| table[b as usize] == b) {
        return Cow::Borrowed(s);
    }
    Cow::Owned(s.iter().map(|&b| table[b as usize]).collect())
}

/// PHP `strtr($string, $replace_pairs)`: at each position replaces the
/// longest key that matches, then continues after it; replaced text is never
/// rescanned. Empty keys are ignored (PHP warns). `pairs` is the array in
/// order, keys as PHP strings (integer keys as their decimal form); when a
/// key repeats, the last pair wins, as in a PHP array.
pub fn strtr_array<'a>(s: &'a [u8], pairs: &[(&[u8], &[u8])]) -> Cow<'a, [u8]> {
    if s.is_empty() || pairs.is_empty() {
        return Cow::Borrowed(s);
    }
    let mut map: std::collections::HashMap<&[u8], &[u8]> = std::collections::HashMap::with_capacity(pairs.len());
    let (mut minlen, mut maxlen) = (usize::MAX, 0);
    for (k, v) in pairs {
        map.insert(k, v);
    }
    for k in map.keys() {
        if k.is_empty() || k.len() > s.len() {
            continue;
        }
        minlen = minlen.min(k.len());
        maxlen = maxlen.max(k.len());
    }
    if minlen > maxlen {
        return Cow::Borrowed(s);
    }
    let mut out: Option<Vec<u8>> = None;
    let (mut pos, mut old) = (0, 0);
    while pos + minlen <= s.len() {
        let mut len = maxlen.min(s.len() - pos);
        let mut matched = false;
        while len >= minlen {
            if let Some(v) = map.get(&s[pos..pos + len]) {
                let o = out.get_or_insert_with(|| Vec::with_capacity(s.len()));
                o.extend_from_slice(&s[old..pos]);
                o.extend_from_slice(v);
                old = pos + len;
                pos = old;
                matched = true;
                break;
            }
            len -= 1;
        }
        if !matched {
            pos += 1;
        }
    }
    match out {
        None => Cow::Borrowed(s),
        Some(mut o) => {
            o.extend_from_slice(&s[old..]);
            Cow::Owned(o)
        }
    }
}

// ---------------------------------------------------------------------------
// Splitting and joining
// ---------------------------------------------------------------------------

/// `explode`'s default `$limit` (`PHP_INT_MAX`): no limit.
pub const NO_LIMIT: i64 = i64::MAX;

/// PHP `explode($separator, $string, $limit)`: the pieces between occurrences
/// of `separator`. A positive `limit` returns at most that many pieces (the
/// last holds the rest); a negative one drops that many pieces from the end;
/// `0` is `1`. An empty string gives `[""]` (or `[]` for a negative limit).
pub fn explode<'a>(separator: &[u8], s: &'a [u8], limit: i64) -> Result<Vec<&'a [u8]>, Error> {
    if separator.is_empty() {
        return Err(Error::argument("explode", 1, "separator", "must not be empty"));
    }
    if s.is_empty() {
        return Ok(if limit >= 0 { vec![s] } else { Vec::new() });
    }
    if limit > 1 {
        let mut out = Vec::new();
        let mut p = 0;
        let mut remaining = limit;
        while let Some(i) = find(&s[p..], separator) {
            out.push(&s[p..p + i]);
            p += i + separator.len();
            remaining -= 1;
            if remaining <= 1 {
                break;
            }
        }
        out.push(&s[p..]);
        Ok(out)
    } else if limit < 0 {
        let mut pieces = Vec::new();
        let mut p = 0;
        while let Some(i) = find(&s[p..], separator) {
            pieces.push(&s[p..p + i]);
            p += i + separator.len();
        }
        if pieces.is_empty() {
            return Ok(Vec::new());
        }
        pieces.push(&s[p..]);
        let keep = (pieces.len() as i64).saturating_add(limit).max(0) as usize;
        pieces.truncate(keep);
        Ok(pieces)
    } else {
        Ok(vec![s])
    }
}

/// PHP `implode($separator, $array)` for strings: joins `pieces` with
/// `separator`. See [`implode_values`] for mixed PHP values.
pub fn implode<I, T>(separator: &[u8], pieces: I) -> Vec<u8>
where
    I: IntoIterator<Item = T>,
    T: AsRef<[u8]>,
{
    let mut out = Vec::new();
    for (i, p) in pieces.into_iter().enumerate() {
        if i > 0 {
            out.extend_from_slice(separator);
        }
        out.extend_from_slice(p.as_ref());
    }
    out
}

/// PHP `implode($separator, $array)` for any PHP values: each element is
/// converted like [`to_php_string`] (`true` is `"1"`, `null`/`false` are
/// `""`, floats use `precision` 14, arrays are `"Array"`), and a `stdClass`
/// throws PHP's `Error`.
pub fn implode_values(separator: &[u8], pieces: &[Value]) -> Result<Vec<u8>, Error> {
    let mut out = Vec::new();
    for (i, p) in pieces.iter().enumerate() {
        if i > 0 {
            out.extend_from_slice(separator);
        }
        out.extend_from_slice(to_php_string(p)?.as_bytes());
    }
    Ok(out)
}

/// PHP's string conversion of a value as string functions perform it
/// (`zval_get_string`): `null` and `false` are `""`, `true` is `"1"`,
/// integers in decimal, floats with `precision` 14 ([`number::to_string`]),
/// arrays `"Array"` (PHP also warns). A `stdClass` (an empty JSON object)
/// has no string form: PHP's `Error`.
pub fn to_php_string(value: &Value) -> Result<Cow<'_, str>, Error> {
    Ok(match value {
        Value::Null | Value::Bool(false) => Cow::Borrowed(""),
        Value::Bool(true) => Cow::Borrowed("1"),
        Value::Number(n) => Cow::Owned(match crate::value::json_number(n) {
            crate::value::Number::Int(i) => i.to_string(),
            crate::value::Number::Float(f) => number::to_string(f),
        }),
        Value::String(s) => Cow::Borrowed(s.as_str()),
        Value::Array(_) => Cow::Borrowed("Array"),
        Value::Object(o) if o.is_empty() => {
            return Err(Error::Error("Object of class stdClass could not be converted to string".into()));
        }
        Value::Object(_) => Cow::Borrowed("Array"),
    })
}

/// PHP `str_split($string, $length)`: chunks of `length` bytes (the last may
/// be shorter); `[]` for an empty string.
pub fn str_split(s: &[u8], length: i64) -> Result<Vec<&[u8]>, Error> {
    if length <= 0 {
        return Err(Error::argument("str_split", 2, "length", "must be greater than 0"));
    }
    let n = usize::try_from(length).unwrap_or(usize::MAX);
    Ok(s.chunks(n).collect())
}

/// PHP `chunk_split($string, $length, $separator)`: inserts `separator`
/// after every `length` bytes and at the end.
pub fn chunk_split(s: &[u8], length: i64, separator: &[u8]) -> Result<Vec<u8>, Error> {
    if length <= 0 {
        return Err(Error::argument("chunk_split", 2, "length", "must be greater than 0"));
    }
    let n = usize::try_from(length).unwrap_or(usize::MAX);
    if n > s.len() {
        let mut out = s.to_vec();
        out.extend_from_slice(separator);
        return Ok(out);
    }
    let mut out = Vec::with_capacity(s.len() + s.len().div_ceil(n) * separator.len());
    for chunk in s.chunks(n) {
        out.extend_from_slice(chunk);
        out.extend_from_slice(separator);
    }
    Ok(out)
}

/// PHP `wordwrap($string, $width, $break, $cut_long_words)`: breaks lines at
/// spaces so they are at most `width` bytes, cutting longer words when `cut`.
/// Ported from PHP's algorithm, including its handling of existing breaks,
/// spaces at line ends and non-positive widths.
///
/// Quirk (PHP 8.5.10): with a multi-byte `brk` or `cut`, an occurrence of
/// `brk` that ends the text is not treated as an existing break, and `brk`
/// is compared like C `strncmp` (only up to a NUL byte).
pub fn wordwrap<'a>(s: &'a [u8], width: i64, brk: &[u8], cut: bool) -> Result<Cow<'a, [u8]>, Error> {
    if s.is_empty() {
        return Ok(Cow::Borrowed(s));
    }
    if brk.is_empty() {
        return Err(Error::argument("wordwrap", 3, "break", "must not be empty"));
    }
    if width == 0 && cut {
        return Err(Error::Value(
            "wordwrap(): Argument #4 ($cut_long_words) cannot be true when argument #2 ($width) is 0".into(),
        ));
    }
    let len = s.len() as i64;
    if brk.len() == 1 && !cut {
        let b = brk[0];
        let mut out = s.to_vec();
        let (mut laststart, mut lastspace) = (0i64, 0i64);
        for current in 0..len {
            let c = s[current as usize];
            if c == b {
                laststart = current + 1;
                lastspace = current + 1;
            } else if c == b' ' {
                if current - laststart >= width {
                    out[current as usize] = b;
                    laststart = current + 1;
                }
                lastspace = current;
            } else if current - laststart >= width && laststart != lastspace {
                out[lastspace as usize] = b;
                laststart = lastspace + 1;
            }
        }
        return Ok(Cow::Owned(out));
    }
    let blen = brk.len() as i64;
    let mut out = Vec::with_capacity(s.len() + s.len() / 8);
    let (mut laststart, mut lastspace) = (0i64, 0i64);
    let mut current = 0i64;
    while current < len {
        let c = s[current as usize];
        // PHP 8.5.10 only recognises an existing break that is not at the
        // very end of the text (`<`, changed to `<=` in later releases).
        if c == brk[0] && current + blen < len && c_strncmp_eq(&s[current as usize..], brk) {
            out.extend_from_slice(&s[laststart as usize..(current + blen) as usize]);
            current += blen - 1;
            laststart = current + 1;
            lastspace = current + 1;
        } else if c == b' ' {
            if current - laststart >= width {
                out.extend_from_slice(&s[laststart as usize..current as usize]);
                out.extend_from_slice(brk);
                laststart = current + 1;
            }
            lastspace = current;
        } else if current - laststart >= width && cut && laststart >= lastspace {
            out.extend_from_slice(&s[laststart as usize..current as usize]);
            out.extend_from_slice(brk);
            laststart = current;
            lastspace = current;
        } else if current - laststart >= width && laststart < lastspace {
            out.extend_from_slice(&s[laststart as usize..lastspace as usize]);
            out.extend_from_slice(brk);
            lastspace += 1;
            laststart = lastspace;
        }
        current += 1;
    }
    if laststart != current {
        out.extend_from_slice(&s[laststart as usize..current as usize]);
    }
    Ok(Cow::Owned(out))
}

/// C `strncmp(a, b, b.len()) == 0`: equal up to `b.len()` bytes or up to
/// the first NUL both share (PHP's `wordwrap` compares breaks this way).
fn c_strncmp_eq(a: &[u8], b: &[u8]) -> bool {
    for (i, &y) in b.iter().enumerate() {
        let x = a.get(i).copied().unwrap_or(0);
        if x != y {
            return false;
        }
        if x == 0 {
            return true;
        }
    }
    true
}

/// PHP `nl2br($string, $use_xhtml)`: inserts `<br />` (or `<br>`) before each
/// line break (`\r\n`, `\n\r`, `\n` or `\r`).
pub fn nl2br(s: &[u8], use_xhtml: bool) -> Cow<'_, [u8]> {
    if !s.iter().any(|&c| c == b'\r' || c == b'\n') {
        return Cow::Borrowed(s);
    }
    let tag: &[u8] = if use_xhtml { b"<br />" } else { b"<br>" };
    let mut out = Vec::with_capacity(s.len() + 8);
    let mut i = 0;
    while i < s.len() {
        let c = s[i];
        if c == b'\r' || c == b'\n' {
            out.extend_from_slice(tag);
            let next = s.get(i + 1).copied();
            if (c == b'\r' && next == Some(b'\n')) || (c == b'\n' && next == Some(b'\r')) {
                out.push(c);
                i += 1;
            }
            out.push(s[i]);
        } else {
            out.push(c);
        }
        i += 1;
    }
    Cow::Owned(out)
}

/// PHP `str_repeat($string, $times)`.
pub fn str_repeat(s: &[u8], times: i64) -> Result<Vec<u8>, Error> {
    if times < 0 {
        return Err(Error::argument("str_repeat", 2, "times", "must be greater than or equal to 0"));
    }
    Ok(s.repeat(times as usize))
}

/// PHP `strrev`: the bytes in reverse order (multi-byte characters are
/// reversed byte by byte, as in PHP).
pub fn strrev(s: &[u8]) -> Vec<u8> {
    s.iter().rev().copied().collect()
}

/// PHP `ord($character)`: the first byte, `0` for an empty string.
pub fn ord(s: &[u8]) -> u8 {
    s.first().copied().unwrap_or(0)
}

/// PHP `chr($codepoint)`: the byte `codepoint mod 256`.
pub fn chr(codepoint: i64) -> u8 {
    (codepoint & 0xff) as u8
}

/// PHP `quotemeta`: backslash before each of `. \ + * ? [ ^ ] $ ( )`.
pub fn quotemeta(s: &[u8]) -> Cow<'_, [u8]> {
    const META: &[u8] = b".\\+*?[^]$()";
    if !s.iter().any(|b| META.contains(b)) {
        return Cow::Borrowed(s);
    }
    let mut out = Vec::with_capacity(s.len() * 2);
    for &c in s {
        if META.contains(&c) {
            out.push(b'\\');
        }
        out.push(c);
    }
    Cow::Owned(out)
}

/// PHP `addcslashes($string, $characters)`: backslash-escapes the bytes of
/// `characters` (with `a..z` ranges); those outside printable ASCII become
/// `\n`, `\t`, `\r`, `\a`, `\v`, `\b`, `\f` or a 3-digit octal escape.
pub fn addcslashes<'a>(s: &'a [u8], characters: &[u8]) -> Cow<'a, [u8]> {
    if s.is_empty() || characters.is_empty() {
        return Cow::Borrowed(s);
    }
    let mask = charmask(characters);
    let mut out = Vec::with_capacity(s.len() * 2);
    for &c in s {
        if mask[c as usize] {
            out.push(b'\\');
            if !(32..=126).contains(&c) {
                match c {
                    b'\n' => out.push(b'n'),
                    b'\t' => out.push(b't'),
                    b'\r' => out.push(b'r'),
                    0x07 => out.push(b'a'),
                    0x0b => out.push(b'v'),
                    0x08 => out.push(b'b'),
                    0x0c => out.push(b'f'),
                    _ => out.extend_from_slice(format!("{c:03o}").as_bytes()),
                }
                continue;
            }
        }
        out.push(c);
    }
    Cow::Owned(out)
}

/// PHP `stripcslashes`: decodes C-style escapes (`\n`, `\t`, `\xhh`, `\ooo`,
/// ...); an unknown escape yields the escaped byte.
pub fn stripcslashes(s: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(s.len());
    let end = s.len();
    let mut i = 0;
    while i < end {
        if s[i] == b'\\' && i + 1 < end {
            i += 1;
            match s[i] {
                b'n' => out.push(b'\n'),
                b'r' => out.push(b'\r'),
                b'a' => out.push(0x07),
                b't' => out.push(b'\t'),
                b'v' => out.push(0x0b),
                b'b' => out.push(0x08),
                b'f' => out.push(0x0c),
                b'\\' => out.push(b'\\'),
                b'x' if i + 1 < end && s[i + 1].is_ascii_hexdigit() => {
                    let mut v = hex_value(s[i + 1]);
                    i += 1;
                    if i + 1 < end && s[i + 1].is_ascii_hexdigit() {
                        v = v * 16 + hex_value(s[i + 1]);
                        i += 1;
                    }
                    out.push(v);
                }
                _ => {
                    let start = i;
                    let mut v: u32 = 0;
                    while i < end && (b'0'..=b'7').contains(&s[i]) && i - start < 3 {
                        v = v * 8 + (s[i] - b'0') as u32;
                        i += 1;
                    }
                    if i > start {
                        out.push(v as u8);
                        i -= 1;
                    } else {
                        out.push(s[i]);
                    }
                }
            }
        } else {
            out.push(s[i]);
        }
        i += 1;
    }
    out
}

pub(crate) fn hex_value(c: u8) -> u8 {
    match c {
        b'0'..=b'9' => c - b'0',
        b'a'..=b'f' => c - b'a' + 10,
        _ => c - b'A' + 10,
    }
}

// ---------------------------------------------------------------------------
// ctype (C locale)
// ---------------------------------------------------------------------------

fn ctype(s: &[u8], f: impl Fn(u8) -> bool) -> bool {
    !s.is_empty() && s.iter().all(|&c| f(c))
}

/// A `ctype_*` function, for calls with a value that may not be a string.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Ctype {
    Alnum,
    Alpha,
    Cntrl,
    Digit,
    Graph,
    Lower,
    Print,
    Punct,
    Space,
    Upper,
    Xdigit,
}

impl Ctype {
    /// The function on a string (the supported call).
    pub fn on_bytes(self, s: &[u8]) -> bool {
        match self {
            Ctype::Alnum => ctype_alnum(s),
            Ctype::Alpha => ctype_alpha(s),
            Ctype::Cntrl => ctype_cntrl(s),
            Ctype::Digit => ctype_digit(s),
            Ctype::Graph => ctype_graph(s),
            Ctype::Lower => ctype_lower(s),
            Ctype::Print => ctype_print(s),
            Ctype::Punct => ctype_punct(s),
            Ctype::Space => ctype_space(s),
            Ctype::Upper => ctype_upper(s),
            Ctype::Xdigit => ctype_xdigit(s),
        }
    }

    /// The function on an integer (deprecated in PHP 8.1, still supported):
    /// -128..=255 is a character code (negatives + 256); a larger integer is
    /// true for the functions whose class contains digits, a smaller one for
    /// those whose class also contains `-` (`ext/ctype`'s `allow_digits` and
    /// `allow_minus`).
    pub fn on_int(self, n: i64) -> bool {
        match n {
            0..=255 => self.on_bytes(&[n as u8]),
            -128..=-1 => self.on_bytes(&[(n + 256) as u8]),
            _ if n > 0 => matches!(self, Ctype::Alnum | Ctype::Digit | Ctype::Graph | Ctype::Print | Ctype::Xdigit),
            _ => matches!(self, Ctype::Graph | Ctype::Print),
        }
    }
}

/// PHP `ctype_alnum` on a string: non-empty and all ASCII letters or digits.
pub fn ctype_alnum(s: &[u8]) -> bool {
    ctype(s, |c| c.is_ascii_alphanumeric())
}

/// PHP `ctype_alpha` on a string: non-empty and all ASCII letters.
pub fn ctype_alpha(s: &[u8]) -> bool {
    ctype(s, |c| c.is_ascii_alphabetic())
}

/// PHP `ctype_cntrl` on a string: non-empty and all control bytes (0-31, 127).
pub fn ctype_cntrl(s: &[u8]) -> bool {
    ctype(s, |c| c.is_ascii_control())
}

/// PHP `ctype_digit` on a string: non-empty and all `0-9`.
pub fn ctype_digit(s: &[u8]) -> bool {
    ctype(s, |c| c.is_ascii_digit())
}

/// PHP `ctype_graph` on a string: non-empty and all visible ASCII (33-126).
pub fn ctype_graph(s: &[u8]) -> bool {
    ctype(s, |c| c.is_ascii_graphic())
}

/// PHP `ctype_lower` on a string: non-empty and all `a-z`.
pub fn ctype_lower(s: &[u8]) -> bool {
    ctype(s, |c| c.is_ascii_lowercase())
}

/// PHP `ctype_print` on a string: non-empty and all printable ASCII (32-126).
pub fn ctype_print(s: &[u8]) -> bool {
    ctype(s, |c| (32..=126).contains(&c))
}

/// PHP `ctype_punct` on a string: non-empty and all ASCII punctuation.
pub fn ctype_punct(s: &[u8]) -> bool {
    ctype(s, |c| c.is_ascii_punctuation())
}

/// PHP `ctype_space` on a string: non-empty and all of `" \t\n\r\v\f"`.
pub fn ctype_space(s: &[u8]) -> bool {
    ctype(s, |c| matches!(c, b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c))
}

/// PHP `ctype_upper` on a string: non-empty and all `A-Z`.
pub fn ctype_upper(s: &[u8]) -> bool {
    ctype(s, |c| c.is_ascii_uppercase())
}

/// PHP `ctype_xdigit` on a string: non-empty and all hexadecimal digits.
pub fn ctype_xdigit(s: &[u8]) -> bool {
    ctype(s, |c| c.is_ascii_hexdigit())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn trims_with_ranges() {
        assert_eq!(trim(b"  abc  ", TRIM_CHARACTERS), b"abc");
        assert_eq!(trim(b"abcxyz", b"a..c"), b"xyz");
        assert_eq!(trim(b"a..", b"a.."), b"");
        assert_eq!(rtrim(b"1.500", b"0"), b"1.5");
    }

    #[test]
    fn substr_like_php() {
        assert_eq!(substr(b"abcdef", -2, None), b"ef");
        assert_eq!(substr(b"abcdef", 1, Some(-1)), b"bcde");
        assert_eq!(substr(b"abc", 5, None), b"");
        assert_eq!(substr(b"abc", i64::MIN, Some(i64::MIN)), b"");
    }

    #[test]
    fn explode_limits() {
        assert_eq!(explode(b",", b"a,b,c", 2).unwrap(), vec![&b"a"[..], b"b,c"]);
        assert_eq!(explode(b",", b"a,b,c", -1).unwrap(), vec![&b"a"[..], b"b"]);
        assert_eq!(explode(b",", b"abc", -1).unwrap(), Vec::<&[u8]>::new());
        assert!(explode(b"", b"a", 1).is_err());
    }

    #[test]
    fn replaces_and_counts() {
        assert_eq!(str_replace(b"a", b"bb", b"aXa"), (Cow::Owned(b"bbXbb".to_vec()), 2));
        assert_eq!(str_ireplace(b"AB", b"x", b"abAb"), (Cow::Owned(b"xx".to_vec()), 2));
        let (r, n) = str_replace_array(&[b"a", b"b"], Replace::All(b"b"), b"ab", false);
        assert_eq!((r.as_ref(), n), (&b"bb"[..], 3));
    }

    #[test]
    fn strtr_longest_first() {
        let r = strtr_array(b"Hi all", &[(b"Hi", b"Hello"), (b"Hi all", b"Hey"), (b"a", b"A")]);
        assert_eq!(r.as_ref(), b"Hey");
    }

    #[test]
    fn compares_like_php() {
        assert_eq!(strcmp(b"a", b"c"), -2);
        assert_eq!(strcmp(b"ab", b"abcd"), -1);
        assert_eq!(strcasecmp(b"HELLO", b"hello"), 0);
    }
}
