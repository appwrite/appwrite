//! Byte-string functions of `ext/standard/string.c` and `ext/ctype`, plus
//! `crypt()`, `password_hash()` / `password_verify()` and `hash()`.
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

// ---------------------------------------------------------------------------
// crypt(), password_hash(), password_verify()
// (ext/standard/crypt.c, crypt_blowfish.c, crypt_sha256.c, crypt_sha512.c,
// php_crypt_r.c, password.c)
// ---------------------------------------------------------------------------

/// `PHP_MAX_SALT_LEN`: `crypt()` reads at most this much of the salt.
const PHP_MAX_SALT_LEN: usize = 123;
/// The `./0-9A-Za-z` alphabet of MD5 and SHA crypt.
const CRYPT_ITOA64: &[u8; 64] = b"./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
/// crypt_blowfish's `./A-Za-z0-9` alphabet.
const BF_ITOA64: &[u8; 64] = b"./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";

/// A C string: the bytes before the first NUL.
fn c_str(s: &[u8]) -> &[u8] {
    s.iter().position(|b| *b == 0).map_or(s, |n| &s[..n])
}

/// PHP `crypt($string, $salt)`: the hash, or `*0` (`*1` when the salt starts
/// with `*0`) where the salt names no supported algorithm or is invalid.
///
/// The algorithms are PHP's own: blowfish (`$2a$`, `$2b$`, `$2x$`, `$2y$`),
/// MD5 (`$1$`), SHA-256 (`$5$`), SHA-512 (`$6$`), and FreeSec's DES
/// (two salt characters) and extended DES (`_`).
pub fn crypt(string: &[u8], salt: &[u8]) -> Vec<u8> {
    let salt = &salt[..salt.len().min(PHP_MAX_SALT_LEN)];
    match php_crypt(string, salt) {
        Some(hash) => hash,
        None if c_str(salt).starts_with(b"*0") => b"*1".to_vec(),
        None => b"*0".to_vec(),
    }
}

/// `php_crypt()`: `crypt()` without the failure strings. Both arguments are
/// C strings (they end at the first NUL).
pub fn php_crypt(password: &[u8], salt: &[u8]) -> Option<Vec<u8>> {
    let (password, salt) = (c_str(password), c_str(salt));
    let at = |i: usize| salt.get(i).copied().unwrap_or(0);
    if at(0) == b'*' && (at(1) == b'0' || at(1) == b'1') {
        return None;
    }
    if salt.starts_with(b"$1$") {
        return Some(md5_crypt(password, salt));
    }
    if salt.starts_with(b"$6$") {
        return sha_crypt::<sha2::Sha512>(password, salt, b"$6$", &SHA512_ORDER, [0, 0, 63], 2, true);
    }
    if salt.starts_with(b"$5$") {
        return sha_crypt::<sha2::Sha256>(password, salt, b"$5$", &SHA256_ORDER, [0, 31, 30], 3, false);
    }
    if at(0) == b'$' && at(1) == b'2' && at(2) != 0 && at(3) == b'$' {
        return blowfish_crypt(password, salt);
    }
    let valid = |c: u8| matches!(c, b'.'..=b'9' | b'A'..=b'Z' | b'a'..=b'z');
    if at(0) == b'_' || (valid(at(0)) && valid(at(1))) {
        return des_crypt(password, salt);
    }
    None
}

/// `php_md5_crypt_r()`: `$1$` hashes (salt up to 8 characters).
fn md5_crypt(password: &[u8], salt: &[u8]) -> Vec<u8> {
    use md5::{Digest, Md5};
    let sp = salt.strip_prefix(b"$1$").unwrap_or(salt);
    let sl = sp.iter().take(8).take_while(|b| **b != b'$').count();
    let sp = &sp[..sl];
    let mut ctx = Md5::new();
    ctx.update(password);
    ctx.update(b"$1$");
    ctx.update(sp);
    let mut final_ = Md5::new().chain_update(password).chain_update(sp).chain_update(password).finalize();
    let mut pl = password.len() as isize;
    while pl > 0 {
        ctx.update(&final_[..pl.min(16) as usize]);
        pl -= 16;
    }
    final_.iter_mut().for_each(|b| *b = 0);
    let mut i = password.len();
    while i != 0 {
        if i & 1 != 0 {
            ctx.update(&final_[..1]);
        } else {
            ctx.update(&password[..1]);
        }
        i >>= 1;
    }
    let mut final_ = ctx.finalize();
    for i in 0..1000 {
        let mut ctx1 = Md5::new();
        if i & 1 != 0 {
            ctx1.update(password);
        } else {
            ctx1.update(final_);
        }
        if i % 3 != 0 {
            ctx1.update(sp);
        }
        if i % 7 != 0 {
            ctx1.update(password);
        }
        if i & 1 != 0 {
            ctx1.update(final_);
        } else {
            ctx1.update(password);
        }
        final_ = ctx1.finalize();
    }
    let mut out = b"$1$".to_vec();
    out.extend_from_slice(sp);
    out.push(b'$');
    let to64 = |out: &mut Vec<u8>, mut v: u32, n: usize| {
        for _ in 0..n {
            out.push(CRYPT_ITOA64[(v & 0x3f) as usize]);
            v >>= 6;
        }
    };
    let f = |i: usize| u32::from(final_[i]);
    for [a, b, c] in [[0, 6, 12], [1, 7, 13], [2, 8, 14], [3, 9, 15], [4, 10, 5]] {
        to64(&mut out, (f(a) << 16) | (f(b) << 8) | f(c), 4);
    }
    to64(&mut out, f(11), 2);
    out
}

/// The byte triples SHA-512 crypt encodes, in order.
const SHA512_ORDER: [[usize; 3]; 21] = [
    [0, 21, 42],
    [22, 43, 1],
    [44, 2, 23],
    [3, 24, 45],
    [25, 46, 4],
    [47, 5, 26],
    [6, 27, 48],
    [28, 49, 7],
    [50, 8, 29],
    [9, 30, 51],
    [31, 52, 10],
    [53, 11, 32],
    [12, 33, 54],
    [34, 55, 13],
    [56, 14, 35],
    [15, 36, 57],
    [37, 58, 16],
    [59, 17, 38],
    [18, 39, 60],
    [40, 61, 19],
    [62, 20, 41],
];

/// The byte triples SHA-256 crypt encodes, in order.
const SHA256_ORDER: [[usize; 3]; 10] = [
    [0, 10, 20],
    [21, 1, 11],
    [12, 22, 2],
    [3, 13, 23],
    [24, 4, 14],
    [15, 25, 5],
    [6, 16, 26],
    [27, 7, 17],
    [18, 28, 8],
    [9, 19, 29],
];

/// `strtoul(s, &end, 10)`: the value (saturated) and the bytes consumed,
/// 0 when there are no digits.
fn strtoul(s: &[u8]) -> (u64, usize) {
    let mut i = s.iter().take_while(|b| matches!(b, b' ' | b'\t' | b'\n' | b'\x0b' | b'\x0c' | b'\r')).count();
    let negative = match s.get(i) {
        Some(b'-') => {
            i += 1;
            true
        }
        Some(b'+') => {
            i += 1;
            false
        }
        _ => false,
    };
    let digits = s[i..].iter().take_while(|b| b.is_ascii_digit()).count();
    if digits == 0 {
        return (0, 0);
    }
    let mut value: u64 = 0;
    let mut overflow = false;
    for d in &s[i..i + digits] {
        match value.checked_mul(10).and_then(|v| v.checked_add(u64::from(d - b'0'))) {
            Some(v) => value = v,
            None => overflow = true,
        }
    }
    let value = if overflow {
        u64::MAX
    } else if negative {
        value.wrapping_neg()
    } else {
        value
    };
    (value, i + digits)
}

/// `php_sha256_crypt_r()` / `php_sha512_crypt_r()`.
fn sha_crypt<D: sha2::Digest + Clone>(
    key: &[u8],
    salt: &[u8],
    prefix: &[u8],
    order: &[[usize; 3]],
    last: [usize; 3],
    last_chars: usize,
    bounded: bool,
) -> Option<Vec<u8>> {
    let size = <D as sha2::Digest>::output_size();
    let mut salt = salt.strip_prefix(prefix).unwrap_or(salt);
    let mut rounds = 5000u64;
    let mut custom = false;
    if let Some(num) = salt.strip_prefix(b"rounds=") {
        let (value, used) = strtoul(num);
        if num.get(used) == Some(&b'$') {
            salt = &num[used + 1..];
            if !(1000..=999_999_999).contains(&value) {
                return None;
            }
            rounds = value;
            custom = true;
        }
    }
    let salt_len = salt.iter().take_while(|b| **b != b'$').count().min(16);
    let salt = &salt[..salt_len];
    let key_len = key.len();
    // The output must fit PHP_MAX_SALT_LEN with its NUL (only SHA-512 with
    // 9-digit rounds and a 16-byte salt does not): PHP computes, then fails.
    let rounds_len = if custom { format!("rounds={rounds}$").len() } else { 0 };
    let chars = order.len() * 4 + last_chars;
    if prefix.len() + rounds_len + salt_len + 1 + chars >= PHP_MAX_SALT_LEN {
        return None;
    }

    let mut ctx = D::new();
    ctx.update(key);
    ctx.update(salt);
    let alt = D::new().chain_update(key).chain_update(salt).chain_update(key).finalize();
    let mut cnt = key_len;
    while cnt > size {
        ctx.update(&alt);
        cnt -= size;
    }
    ctx.update(&alt[..cnt]);
    let mut cnt = key_len;
    while cnt > 0 {
        if cnt & 1 != 0 {
            ctx.update(&alt);
        } else {
            ctx.update(key);
        }
        cnt >>= 1;
    }
    let mut alt_result = ctx.finalize();

    let mut dp = D::new();
    for _ in 0..key_len {
        dp.update(key);
    }
    let temp = dp.finalize();
    let p_bytes: Vec<u8> = (0..key_len).map(|i| temp[i % size]).collect();
    let mut ds = D::new();
    for _ in 0..16 + usize::from(alt_result[0]) {
        ds.update(salt);
    }
    let temp = ds.finalize();
    let s_bytes: Vec<u8> = (0..salt_len).map(|i| temp[i % size]).collect();

    for cnt in 0..rounds {
        let mut ctx = D::new();
        if cnt & 1 != 0 {
            ctx.update(&p_bytes);
        } else {
            ctx.update(&alt_result);
        }
        if cnt % 3 != 0 {
            ctx.update(&s_bytes);
        }
        if cnt % 7 != 0 {
            ctx.update(&p_bytes);
        }
        if cnt & 1 != 0 {
            ctx.update(&alt_result);
        } else {
            ctx.update(&p_bytes);
        }
        alt_result = ctx.finalize();
    }

    // The result goes into a PHP_MAX_SALT_LEN buffer, which must keep room for the NUL.
    let mut buflen = PHP_MAX_SALT_LEN as isize - prefix.len() as isize;
    let mut out = prefix.to_vec();
    if custom {
        let r = format!("rounds={rounds}$");
        buflen -= r.len() as isize;
        out.extend_from_slice(r.as_bytes());
    }
    let n = salt_len.min(buflen.max(0) as usize);
    out.extend_from_slice(&salt[..n]);
    buflen -= n as isize;
    if buflen > 0 {
        out.push(b'$');
        buflen -= 1;
    }
    let mut b64 = |out: &mut Vec<u8>, [b2, b1, b0]: [u32; 3], n: usize| {
        let mut w = (b2 << 16) | (b1 << 8) | b0;
        for _ in 0..n {
            if buflen <= 0 {
                break;
            }
            out.push(CRYPT_ITOA64[(w & 0x3f) as usize]);
            buflen -= 1;
            w >>= 6;
        }
    };
    let r = |i: usize| u32::from(alt_result[i]);
    for [a, b, c] in order {
        b64(&mut out, [r(*a), r(*b), r(*c)], 4);
    }
    let [_, b, c] = last;
    let triple = if bounded { [0, 0, r(c)] } else { [0, r(b), r(c)] };
    b64(&mut out, triple, last_chars);
    if buflen <= 0 {
        return None;
    }
    Some(out)
}

/// crypt_blowfish's `BF_decode` of the 22-character salt (the last
/// character's low bits are ignored).
fn bf_decode_salt(src: &[u8]) -> Option<[u8; 16]> {
    let get = |i: usize| src.get(i).and_then(|c| BF_ITOA64.iter().position(|x| x == c)).map(|p| p as u32);
    let mut out = [0u8; 16];
    let (mut n, mut i) = (0, 0);
    while n < 16 {
        let (c1, c2) = (get(i)?, get(i + 1)?);
        out[n] = ((c1 << 2) | ((c2 & 0x30) >> 4)) as u8;
        n += 1;
        if n >= 16 {
            break;
        }
        let c3 = get(i + 2)?;
        out[n] = (((c2 & 0x0f) << 4) | ((c3 & 0x3c) >> 2)) as u8;
        n += 1;
        let c4 = get(i + 3)?;
        out[n] = (((c3 & 0x03) << 6) | c4) as u8;
        n += 1;
        i += 4;
    }
    Some(out)
}

/// crypt_blowfish's `BF_encode`.
fn bf_encode(src: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(src.len() * 4 / 3 + 2);
    let mut i = 0;
    while i < src.len() {
        let c1 = u32::from(src[i]);
        i += 1;
        out.push(BF_ITOA64[(c1 >> 2) as usize]);
        let mut c1 = (c1 & 0x03) << 4;
        if i >= src.len() {
            out.push(BF_ITOA64[c1 as usize]);
            break;
        }
        let c2 = u32::from(src[i]);
        i += 1;
        c1 |= c2 >> 4;
        out.push(BF_ITOA64[c1 as usize]);
        c1 = (c2 & 0x0f) << 2;
        if i >= src.len() {
            out.push(BF_ITOA64[c1 as usize]);
            break;
        }
        let c2 = u32::from(src[i]);
        i += 1;
        c1 |= c2 >> 6;
        out.push(BF_ITOA64[c1 as usize]);
        out.push(BF_ITOA64[(c2 & 0x3f) as usize]);
    }
    out
}

/// `BF_set_key()`: the 18 key words for the expensive loop and for the
/// initial salted expansion, serialised (the key with its NUL, cycled over
/// 72 bytes). `bug` (`$2x$`) sign-extends 8-bit characters as old
/// crypt_blowfish did; `safety` (`$2a$`) flips bit 16 of the initial first
/// word when the buggy and the correct schedules coincide despite a sign
/// extension, so such keys cannot collide with buggy hashes.
fn bf_set_key(key: &[u8], bug: bool, safety: bool) -> ([u8; 72], [u8; 72]) {
    let mut expanded = [0u8; 72];
    let (mut sign, mut diff) = (0u32, 0u32);
    let mut ptr = 0usize;
    for word in 0..18 {
        let (mut correct, mut buggy) = (0u32, 0u32);
        for j in 0..4 {
            let c = key.get(ptr).copied().unwrap_or(0);
            correct = (correct << 8) | u32::from(c);
            buggy = (buggy << 8) | (c as i8 as i32 as u32);
            if j != 0 {
                sign |= buggy & 0x80;
            }
            ptr = if c == 0 { 0 } else { ptr + 1 };
        }
        diff |= correct ^ buggy;
        let w = if bug { buggy } else { correct };
        expanded[word * 4..word * 4 + 4].copy_from_slice(&w.to_be_bytes());
    }
    diff |= diff >> 16;
    diff &= 0xffff;
    diff = diff.wrapping_add(0xffff);
    sign <<= 9;
    sign &= !diff & if safety { 0x10000 } else { 0 };
    let mut initial = expanded;
    let first = u32::from_be_bytes([initial[0], initial[1], initial[2], initial[3]]) ^ sign;
    initial[..4].copy_from_slice(&first.to_be_bytes());
    (expanded, initial)
}

/// EksBlowfish and the 64-fold encryption of "OrpheanBeholderScryDoubt".
fn bf_crypt_raw(cost: u32, salt: &[u8; 16], expanded: &[u8; 72], initial: &[u8; 72]) -> [u8; 24] {
    let mut state = blowfish::Blowfish::bc_init_state();
    state.salted_expand_key(salt, initial);
    for _ in 0..1u64 << cost {
        state.bc_expand_key(expanded);
        state.bc_expand_key(salt);
    }
    let mut ctext = [0x4f72_7068, 0x6561_6e42, 0x6568_6f6c, 0x6465_7253, 0x6372_7944, 0x6f75_6274];
    let mut output = [0u8; 24];
    for i in (0..6).step_by(2) {
        for _ in 0..64 {
            let [l, r] = state.bc_encrypt([ctext[i], ctext[i + 1]]);
            ctext[i] = l;
            ctext[i + 1] = r;
        }
        output[i * 4..i * 4 + 4].copy_from_slice(&ctext[i].to_be_bytes());
        output[(i + 1) * 4..(i + 1) * 4 + 4].copy_from_slice(&ctext[i + 1].to_be_bytes());
    }
    output
}

/// `php_crypt_blowfish_rn()`: `$2a$`, `$2b$`, `$2x$` and `$2y$` hashes.
fn blowfish_crypt(key: &[u8], setting: &[u8]) -> Option<Vec<u8>> {
    let at = |i: usize| setting.get(i).copied().unwrap_or(0);
    let (bug, safety) = match at(2) {
        b'a' => (false, true),
        b'b' | b'y' => (false, false),
        b'x' => (true, false),
        _ => return None,
    };
    if at(3) != b'$'
        || !(b'0'..=b'3').contains(&at(4))
        || !at(5).is_ascii_digit()
        || (at(4) == b'3' && at(5) > b'1')
        || at(6) != b'$'
    {
        return None;
    }
    let cost = u32::from(at(4) - b'0') * 10 + u32::from(at(5) - b'0');
    if cost < 4 || setting.len() < 29 {
        return None;
    }
    let salt = bf_decode_salt(&setting[7..29])?;
    let (expanded, initial) = bf_set_key(key, bug, safety);
    let raw = bf_crypt_raw(cost, &salt, &expanded, &initial);
    let mut out = setting[..28].to_vec();
    let last = BF_ITOA64.iter().position(|c| *c == setting[28])?;
    out.push(BF_ITOA64[last & 0x30]);
    out.extend_from_slice(&bf_encode(&raw[..23]));
    Some(out)
}

/// `password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost])` with
/// `salt` (16 random bytes in PHP).
pub fn password_hash_bcrypt(password: &[u8], cost: i64, salt: &[u8; 16]) -> Result<Vec<u8>, Error> {
    if !(4..=31).contains(&cost) {
        return Err(Error::Value(format!("Invalid bcrypt cost parameter specified: {cost}")));
    }
    if password.contains(&0) {
        return Err(Error::Value("Bcrypt password must not contain null character".into()));
    }
    let mut setting = format!("$2y${cost:02}$").into_bytes();
    setting.extend_from_slice(&bf_encode(salt)[..22]);
    blowfish_crypt(password, &setting).ok_or_else(|| Error::Value("Invalid bcrypt cost parameter specified".into()))
}

/// The cost of allocating Argon2 memory PHP would fail to allocate: beyond
/// this (KiB) the port reports an allocation error instead of trying.
const ARGON2_MEMORY_LIMIT: u32 = 1 << 22;

/// `password_hash($password, PASSWORD_ARGON2ID, $options)` with the
/// options' `memory_cost`, `time_cost` and `threads` (already cast to int)
/// and `salt` (16 random bytes in PHP).
pub fn password_hash_argon2id(
    password: &[u8],
    memory_cost: i64,
    time_cost: i64,
    threads: i64,
    salt: &[u8; 16],
) -> Result<Vec<u8>, Error> {
    use argon2::{Algorithm, Argon2, Params, Version};
    if !(8..=0xFFFF_FFFF).contains(&memory_cost) {
        return Err(Error::Value("Memory cost is outside of allowed memory range".into()));
    }
    if !(1..=0xFFFF_FFFF).contains(&time_cost) {
        return Err(Error::Value("Time cost is outside of allowed time range".into()));
    }
    if !(1..=0xFF_FFFF).contains(&threads) {
        return Err(Error::Value("Invalid number of threads".into()));
    }
    let (m, t, p) = (memory_cost as u32, time_cost as u32, threads as u32);
    if m < 8 * p {
        return Err(Error::Value("Memory cost is too small".into()));
    }
    let failed = || Error::Value("Memory allocation error".into());
    if m > ARGON2_MEMORY_LIMIT {
        return Err(failed());
    }
    let params = Params::new(m, t, p, Some(32)).map_err(|_| failed())?;
    let mut out = [0u8; 32];
    Argon2::new(Algorithm::Argon2id, Version::V0x13, params)
        .hash_password_into(password, salt, &mut out)
        .map_err(|_| failed())?;
    let b64 = |b: &[u8]| crate::encoding::base64_encode(b).trim_end_matches('=').to_owned();
    Ok(format!("$argon2id$v=19$m={m},t={t},p={p}${}${}", b64(salt), b64(&out)).into_bytes())
}

/// PHP `password_verify($password, $hash)`.
pub fn password_verify(password: &[u8], hash: &[u8]) -> bool {
    match password_ident(hash) {
        Some(b"argon2i") => argon2_verify(password, hash, argon2::Algorithm::Argon2i, b"argon2i"),
        Some(b"argon2id") => argon2_verify(password, hash, argon2::Algorithm::Argon2id, b"argon2id"),
        _ => {
            // php_password_bcrypt_verify: php_crypt() and a constant-time compare.
            let Some(computed) = php_crypt(password, hash) else {
                return false;
            };
            if hash.len() < 13 {
                return false;
            }
            computed.len() == hash.len() && computed.iter().zip(hash).fold(0u8, |acc, (a, b)| acc | (a ^ b)) == 0
        }
    }
}

/// `php_password_algo_extract_ident`: between the first byte and the next `$`.
fn password_ident(hash: &[u8]) -> Option<&[u8]> {
    if hash.len() < 3 {
        return None;
    }
    let rest = &hash[1..];
    rest.iter().position(|b| *b == b'$').map(|end| &rest[..end])
}

/// libargon2's `decode_decimal`: digits without leading zeros.
fn argon2_decimal(s: &[u8]) -> Option<(u64, &[u8])> {
    let len = s.iter().take_while(|b| b.is_ascii_digit()).count();
    if len == 0 || (s[0] == b'0' && len > 1) {
        return None;
    }
    let mut acc: u64 = 0;
    for d in &s[..len] {
        acc = acc.checked_mul(10)?.checked_add(u64::from(d - b'0'))?;
    }
    Some((acc, &s[len..]))
}

fn argon2_u32<'a>(s: &'a [u8], prefix: &[u8]) -> Option<(u32, &'a [u8])> {
    let (v, rest) = argon2_decimal(s.strip_prefix(prefix)?)?;
    Some((u32::try_from(v).ok()?, rest))
}

/// libargon2's `from_base64`: standard alphabet, no padding, zero trailing bits.
fn argon2_base64(s: &[u8]) -> Option<(Vec<u8>, &[u8])> {
    let val = |c: u8| -> Option<u32> {
        Some(match c {
            b'A'..=b'Z' => u32::from(c - b'A'),
            b'a'..=b'z' => u32::from(c - b'a') + 26,
            b'0'..=b'9' => u32::from(c - b'0') + 52,
            b'+' => 62,
            b'/' => 63,
            _ => return None,
        })
    };
    let (mut acc, mut bits, mut out, mut i) = (0u32, 0u32, Vec::new(), 0);
    while let Some(d) = s.get(i).copied().and_then(val) {
        i += 1;
        acc = (acc << 6) | d;
        bits += 6;
        if bits >= 8 {
            bits -= 8;
            out.push((acc >> bits) as u8);
        }
        acc &= (1 << bits) - 1;
    }
    if bits > 4 || acc != 0 {
        return None;
    }
    Some((out, &s[i..]))
}

/// `argon2_verify()` as PHP's argon2 password algorithms call it.
fn argon2_verify(password: &[u8], hash: &[u8], algorithm: argon2::Algorithm, name: &[u8]) -> bool {
    use argon2::{Argon2, Params, Version};
    let parse = || {
        let s = hash.strip_prefix(b"$")?.strip_prefix(name)?;
        let (version, s) = match argon2_u32(s, b"$v=") {
            Some((v, rest)) => (v, rest),
            None if s.starts_with(b"$v=") => return None,
            None => (0x10, s),
        };
        let (m, s) = argon2_u32(s, b"$m=")?;
        let (t, s) = argon2_u32(s, b",t=")?;
        let (p, s) = argon2_u32(s, b",p=")?;
        let (salt, s) = argon2_base64(s.strip_prefix(b"$")?)?;
        let (out, s) = argon2_base64(s.strip_prefix(b"$")?)?;
        s.is_empty().then_some((version, [m, t, p], salt, out))
    };
    let Some((version, [m, t, p], salt, expected)) = parse() else {
        return false;
    };
    let version = match version {
        0x10 => Version::V0x10,
        0x13 => Version::V0x13,
        _ => return false,
    };
    if expected.len() < 4 || salt.len() < 8 || m > ARGON2_MEMORY_LIMIT {
        return false;
    }
    let Ok(params) = Params::new(m, t, p, Some(expected.len())) else {
        return false;
    };
    let mut out = vec![0u8; expected.len()];
    if Argon2::new(algorithm, version, params).hash_password_into(password, &salt, &mut out).is_err() {
        return false;
    }
    out.iter().zip(&expected).fold(0u8, |acc, (a, b)| acc | (a ^ b)) == 0
}

/// FreeSec's DES tables (ext/standard/crypt_freesec.c), built once.
struct DesTables {
    m_sbox: [[u8; 4096]; 4],
    psbox: [[u32; 256]; 4],
    ip_maskl: [[u32; 256]; 8],
    ip_maskr: [[u32; 256]; 8],
    fp_maskl: [[u32; 256]; 8],
    fp_maskr: [[u32; 256]; 8],
    key_perm_maskl: [[u32; 128]; 8],
    key_perm_maskr: [[u32; 128]; 8],
    comp_maskl: [[u32; 128]; 8],
    comp_maskr: [[u32; 128]; 8],
}

const DES_IP: [u8; 64] = [
    58, 50, 42, 34, 26, 18, 10, 2, 60, 52, 44, 36, 28, 20, 12, 4, 62, 54, 46, 38, 30, 22, 14, 6, 64, 56, 48, 40, 32,
    24, 16, 8, 57, 49, 41, 33, 25, 17, 9, 1, 59, 51, 43, 35, 27, 19, 11, 3, 61, 53, 45, 37, 29, 21, 13, 5, 63, 55, 47,
    39, 31, 23, 15, 7,
];
const DES_KEY_PERM: [u8; 56] = [
    57, 49, 41, 33, 25, 17, 9, 1, 58, 50, 42, 34, 26, 18, 10, 2, 59, 51, 43, 35, 27, 19, 11, 3, 60, 52, 44, 36, 63, 55,
    47, 39, 31, 23, 15, 7, 62, 54, 46, 38, 30, 22, 14, 6, 61, 53, 45, 37, 29, 21, 13, 5, 28, 20, 12, 4,
];
const DES_KEY_SHIFTS: [u32; 16] = [1, 1, 2, 2, 2, 2, 2, 2, 1, 2, 2, 2, 2, 2, 2, 1];
const DES_COMP_PERM: [u8; 48] = [
    14, 17, 11, 24, 1, 5, 3, 28, 15, 6, 21, 10, 23, 19, 12, 4, 26, 8, 16, 7, 27, 20, 13, 2, 41, 52, 31, 37, 47, 55, 30,
    40, 51, 45, 33, 48, 44, 49, 39, 56, 34, 53, 46, 42, 50, 36, 29, 32,
];
const DES_SBOX: [[u8; 64]; 8] = [
    [
        14, 4, 13, 1, 2, 15, 11, 8, 3, 10, 6, 12, 5, 9, 0, 7, 0, 15, 7, 4, 14, 2, 13, 1, 10, 6, 12, 11, 9, 5, 3, 8, 4,
        1, 14, 8, 13, 6, 2, 11, 15, 12, 9, 7, 3, 10, 5, 0, 15, 12, 8, 2, 4, 9, 1, 7, 5, 11, 3, 14, 10, 0, 6, 13,
    ],
    [
        15, 1, 8, 14, 6, 11, 3, 4, 9, 7, 2, 13, 12, 0, 5, 10, 3, 13, 4, 7, 15, 2, 8, 14, 12, 0, 1, 10, 6, 9, 11, 5, 0,
        14, 7, 11, 10, 4, 13, 1, 5, 8, 12, 6, 9, 3, 2, 15, 13, 8, 10, 1, 3, 15, 4, 2, 11, 6, 7, 12, 0, 5, 14, 9,
    ],
    [
        10, 0, 9, 14, 6, 3, 15, 5, 1, 13, 12, 7, 11, 4, 2, 8, 13, 7, 0, 9, 3, 4, 6, 10, 2, 8, 5, 14, 12, 11, 15, 1, 13,
        6, 4, 9, 8, 15, 3, 0, 11, 1, 2, 12, 5, 10, 14, 7, 1, 10, 13, 0, 6, 9, 8, 7, 4, 15, 14, 3, 11, 5, 2, 12,
    ],
    [
        7, 13, 14, 3, 0, 6, 9, 10, 1, 2, 8, 5, 11, 12, 4, 15, 13, 8, 11, 5, 6, 15, 0, 3, 4, 7, 2, 12, 1, 10, 14, 9, 10,
        6, 9, 0, 12, 11, 7, 13, 15, 1, 3, 14, 5, 2, 8, 4, 3, 15, 0, 6, 10, 1, 13, 8, 9, 4, 5, 11, 12, 7, 2, 14,
    ],
    [
        2, 12, 4, 1, 7, 10, 11, 6, 8, 5, 3, 15, 13, 0, 14, 9, 14, 11, 2, 12, 4, 7, 13, 1, 5, 0, 15, 10, 3, 9, 8, 6, 4,
        2, 1, 11, 10, 13, 7, 8, 15, 9, 12, 5, 6, 3, 0, 14, 11, 8, 12, 7, 1, 14, 2, 13, 6, 15, 0, 9, 10, 4, 5, 3,
    ],
    [
        12, 1, 10, 15, 9, 2, 6, 8, 0, 13, 3, 4, 14, 7, 5, 11, 10, 15, 4, 2, 7, 12, 9, 5, 6, 1, 13, 14, 0, 11, 3, 8, 9,
        14, 15, 5, 2, 8, 12, 3, 7, 0, 4, 10, 1, 13, 11, 6, 4, 3, 2, 12, 9, 5, 15, 10, 11, 14, 1, 7, 6, 0, 8, 13,
    ],
    [
        4, 11, 2, 14, 15, 0, 8, 13, 3, 12, 9, 7, 5, 10, 6, 1, 13, 0, 11, 7, 4, 9, 1, 10, 14, 3, 5, 12, 2, 15, 8, 6, 1,
        4, 11, 13, 12, 3, 7, 14, 10, 15, 6, 8, 0, 5, 9, 2, 6, 11, 13, 8, 1, 4, 10, 7, 9, 5, 0, 15, 14, 2, 3, 12,
    ],
    [
        13, 2, 8, 4, 6, 15, 11, 1, 10, 9, 3, 14, 5, 0, 12, 7, 1, 15, 13, 8, 10, 3, 7, 4, 12, 5, 6, 11, 0, 14, 9, 2, 7,
        11, 4, 1, 9, 12, 14, 2, 0, 6, 10, 13, 15, 3, 5, 8, 2, 1, 14, 7, 4, 10, 8, 13, 15, 12, 9, 0, 3, 5, 6, 11,
    ],
];
const DES_PBOX: [u8; 32] = [
    16, 7, 20, 21, 29, 12, 28, 17, 1, 15, 23, 26, 5, 18, 31, 10, 2, 8, 24, 14, 32, 27, 3, 9, 19, 13, 30, 6, 22, 11, 4,
    25,
];

fn bits32(i: usize) -> u32 {
    0x8000_0000 >> i
}

/// `_crypt_extended_init()`.
fn des_tables() -> Box<DesTables> {
    let bits28 = |i: usize| bits32(i + 4);
    let bits24 = |i: usize| bits32(i + 8);
    let bits8 = |i: usize| 0x80u32 >> i;
    let mut t = Box::new(DesTables {
        m_sbox: [[0; 4096]; 4],
        psbox: [[0; 256]; 4],
        ip_maskl: [[0; 256]; 8],
        ip_maskr: [[0; 256]; 8],
        fp_maskl: [[0; 256]; 8],
        fp_maskr: [[0; 256]; 8],
        key_perm_maskl: [[0; 128]; 8],
        key_perm_maskr: [[0; 128]; 8],
        comp_maskl: [[0; 128]; 8],
        comp_maskr: [[0; 128]; 8],
    });
    let mut u_sbox = [[0u8; 64]; 8];
    for (i, row) in u_sbox.iter_mut().enumerate() {
        for (j, v) in row.iter_mut().enumerate() {
            let b = (j & 0x20) | ((j & 1) << 4) | ((j >> 1) & 0xf);
            *v = DES_SBOX[i][b];
        }
    }
    for b in 0..4 {
        for i in 0..64 {
            for j in 0..64 {
                t.m_sbox[b][(i << 6) | j] = (u_sbox[b << 1][i] << 4) | u_sbox[(b << 1) + 1][j];
            }
        }
    }
    let mut init_perm = [0u8; 64];
    let mut final_perm = [0u8; 64];
    let mut inv_key_perm = [255u8; 64];
    let mut inv_comp_perm = [255u8; 56];
    for i in 0..64 {
        final_perm[i] = DES_IP[i] - 1;
        init_perm[final_perm[i] as usize] = i as u8;
    }
    for (i, p) in DES_KEY_PERM.iter().enumerate() {
        inv_key_perm[*p as usize - 1] = i as u8;
    }
    for (i, p) in DES_COMP_PERM.iter().enumerate() {
        inv_comp_perm[*p as usize - 1] = i as u8;
    }
    for k in 0..8 {
        for i in 0..256u32 {
            let (mut il, mut ir, mut fl, mut fr) = (0, 0, 0, 0);
            for j in 0..8 {
                let inbit = 8 * k + j;
                if i & bits8(j) != 0 {
                    let obit = init_perm[inbit] as usize;
                    if obit < 32 {
                        il |= bits32(obit);
                    } else {
                        ir |= bits32(obit - 32);
                    }
                    let obit = final_perm[inbit] as usize;
                    if obit < 32 {
                        fl |= bits32(obit);
                    } else {
                        fr |= bits32(obit - 32);
                    }
                }
            }
            t.ip_maskl[k][i as usize] = il;
            t.ip_maskr[k][i as usize] = ir;
            t.fp_maskl[k][i as usize] = fl;
            t.fp_maskr[k][i as usize] = fr;
        }
        for i in 0..128u32 {
            let (mut il, mut ir) = (0, 0);
            for j in 0..7 {
                let inbit = 8 * k + j;
                if i & bits8(j + 1) != 0 {
                    let obit = inv_key_perm[inbit];
                    if obit == 255 {
                        continue;
                    }
                    let obit = obit as usize;
                    if obit < 28 {
                        il |= bits28(obit);
                    } else {
                        ir |= bits28(obit - 28);
                    }
                }
            }
            t.key_perm_maskl[k][i as usize] = il;
            t.key_perm_maskr[k][i as usize] = ir;
            let (mut il, mut ir) = (0, 0);
            for j in 0..7 {
                let inbit = 7 * k + j;
                if i & bits8(j + 1) != 0 {
                    let obit = inv_comp_perm[inbit];
                    if obit == 255 {
                        continue;
                    }
                    let obit = obit as usize;
                    if obit < 24 {
                        il |= bits24(obit);
                    } else {
                        ir |= bits24(obit - 24);
                    }
                }
            }
            t.comp_maskl[k][i as usize] = il;
            t.comp_maskr[k][i as usize] = ir;
        }
    }
    let mut un_pbox = [0u8; 32];
    for (i, p) in DES_PBOX.iter().enumerate() {
        un_pbox[*p as usize - 1] = i as u8;
    }
    for b in 0..4 {
        for i in 0..256u32 {
            let mut p = 0;
            for j in 0..8 {
                if i & bits8(j) != 0 {
                    p |= bits32(un_pbox[8 * b + j] as usize);
                }
            }
            t.psbox[b][i as usize] = p;
        }
    }
    t
}

static DES: std::sync::LazyLock<Box<DesTables>> = std::sync::LazyLock::new(des_tables);

/// A DES key schedule and the salt bits (`struct php_crypt_extended_data`).
struct Des {
    keys_l: [u32; 16],
    keys_r: [u32; 16],
    saltbits: u32,
}

impl Des {
    fn new() -> Self {
        Des { keys_l: [0; 16], keys_r: [0; 16], saltbits: 0 }
    }

    /// `setup_salt()`.
    fn set_salt(&mut self, salt: u32) {
        let mut saltbits = 0;
        let (mut saltbit, mut obit) = (1u32, 0x80_0000u32);
        for _ in 0..24 {
            if salt & saltbit != 0 {
                saltbits |= obit;
            }
            saltbit <<= 1;
            obit >>= 1;
        }
        self.saltbits = saltbits;
    }

    /// `des_setkey()`.
    fn set_key(&mut self, key: &[u8; 8]) {
        let t = &**DES;
        let raw0 = u32::from_be_bytes([key[0], key[1], key[2], key[3]]);
        let raw1 = u32::from_be_bytes([key[4], key[5], key[6], key[7]]);
        let perm = |m: &[[u32; 128]; 8]| {
            m[0][(raw0 >> 25) as usize]
                | m[1][((raw0 >> 17) & 0x7f) as usize]
                | m[2][((raw0 >> 9) & 0x7f) as usize]
                | m[3][((raw0 >> 1) & 0x7f) as usize]
                | m[4][(raw1 >> 25) as usize]
                | m[5][((raw1 >> 17) & 0x7f) as usize]
                | m[6][((raw1 >> 9) & 0x7f) as usize]
                | m[7][((raw1 >> 1) & 0x7f) as usize]
        };
        let (k0, k1) = (perm(&t.key_perm_maskl), perm(&t.key_perm_maskr));
        let mut shifts = 0;
        for (round, shift) in DES_KEY_SHIFTS.iter().enumerate() {
            shifts += shift;
            let t0 = (k0 << shifts) | (k0 >> (28 - shifts));
            let t1 = (k1 << shifts) | (k1 >> (28 - shifts));
            let comp = |m: &[[u32; 128]; 8]| {
                m[0][((t0 >> 21) & 0x7f) as usize]
                    | m[1][((t0 >> 14) & 0x7f) as usize]
                    | m[2][((t0 >> 7) & 0x7f) as usize]
                    | m[3][(t0 & 0x7f) as usize]
                    | m[4][((t1 >> 21) & 0x7f) as usize]
                    | m[5][((t1 >> 14) & 0x7f) as usize]
                    | m[6][((t1 >> 7) & 0x7f) as usize]
                    | m[7][(t1 & 0x7f) as usize]
            };
            self.keys_l[round] = comp(&t.comp_maskl);
            self.keys_r[round] = comp(&t.comp_maskr);
        }
    }

    /// `do_des()`, encrypting `count` times.
    fn encrypt(&self, l_in: u32, r_in: u32, count: u32) -> (u32, u32) {
        let t = &**DES;
        let byte = |v: u32, s: u32| ((v >> s) & 0xff) as usize;
        let ip = |m: &[[u32; 256]; 8]| {
            m[0][byte(l_in, 24)]
                | m[1][byte(l_in, 16)]
                | m[2][byte(l_in, 8)]
                | m[3][byte(l_in, 0)]
                | m[4][byte(r_in, 24)]
                | m[5][byte(r_in, 16)]
                | m[6][byte(r_in, 8)]
                | m[7][byte(r_in, 0)]
        };
        let (mut l, mut r) = (ip(&t.ip_maskl), ip(&t.ip_maskr));
        let mut f = 0u32;
        for _ in 0..count {
            for round in 0..16 {
                let mut r48l = ((r & 0x0000_0001) << 23)
                    | ((r & 0xf800_0000) >> 9)
                    | ((r & 0x1f80_0000) >> 11)
                    | ((r & 0x01f8_0000) >> 13)
                    | ((r & 0x001f_8000) >> 15);
                let mut r48r = ((r & 0x0001_f800) << 7)
                    | ((r & 0x0000_1f80) << 5)
                    | ((r & 0x0000_01f8) << 3)
                    | ((r & 0x0000_001f) << 1)
                    | ((r & 0x8000_0000) >> 31);
                f = (r48l ^ r48r) & self.saltbits;
                r48l ^= f ^ self.keys_l[round];
                r48r ^= f ^ self.keys_r[round];
                f = t.psbox[0][t.m_sbox[0][(r48l >> 12) as usize] as usize]
                    | t.psbox[1][t.m_sbox[1][(r48l & 0xfff) as usize] as usize]
                    | t.psbox[2][t.m_sbox[2][(r48r >> 12) as usize] as usize]
                    | t.psbox[3][t.m_sbox[3][(r48r & 0xfff) as usize] as usize];
                f ^= l;
                l = r;
                r = f;
            }
            r = l;
            l = f;
        }
        let fp = |m: &[[u32; 256]; 8]| {
            m[0][byte(l, 24)]
                | m[1][byte(l, 16)]
                | m[2][byte(l, 8)]
                | m[3][byte(l, 0)]
                | m[4][byte(r, 24)]
                | m[5][byte(r, 16)]
                | m[6][byte(r, 8)]
                | m[7][byte(r, 0)]
        };
        (fp(&t.fp_maskl), fp(&t.fp_maskr))
    }
}

/// FreeSec's `ascii_to_bin()` (on a signed char).
fn des_ascii_to_bin(ch: u8) -> u32 {
    let sch = i32::from(ch as i8);
    let mut v = sch - i32::from(b'.');
    if sch >= i32::from(b'A') {
        v = sch - (i32::from(b'A') - 12);
        if sch >= i32::from(b'a') {
            v = sch - (i32::from(b'a') - 38);
        }
    }
    (v & 0x3f) as u32
}

/// `_crypt_extended_r()`: traditional DES (two salt characters, the first
/// 8 key bytes) and BSDi extended DES (`_`, 4 count and 4 salt characters).
fn des_crypt(key: &[u8], setting: &[u8]) -> Option<Vec<u8>> {
    let at = |i: usize| setting.get(i).copied().unwrap_or(0);
    let mut keybuf = [0u8; 8];
    let mut k = 0usize;
    for b in keybuf.iter_mut() {
        let c = key.get(k).copied().unwrap_or(0);
        *b = c << 1;
        if c != 0 {
            k += 1;
        }
    }
    let mut des = Des::new();
    des.set_key(&keybuf);
    let (count, salt, mut out) = if at(0) == b'_' {
        let field = |range: std::ops::Range<usize>| -> Option<u32> {
            let mut v = 0u32;
            for (n, i) in range.enumerate() {
                let value = des_ascii_to_bin(at(i));
                if CRYPT_ITOA64[value as usize] != at(i) {
                    return None;
                }
                v |= value << (n * 6);
            }
            Some(v)
        };
        let count = field(1..5)?;
        if count == 0 {
            return None;
        }
        let salt = field(5..9)?;
        while key.get(k).is_some_and(|c| *c != 0) {
            des.set_salt(0);
            let (l, r) = des.encrypt(
                u32::from_be_bytes([keybuf[0], keybuf[1], keybuf[2], keybuf[3]]),
                u32::from_be_bytes([keybuf[4], keybuf[5], keybuf[6], keybuf[7]]),
                1,
            );
            keybuf[..4].copy_from_slice(&l.to_be_bytes());
            keybuf[4..].copy_from_slice(&r.to_be_bytes());
            for b in keybuf.iter_mut() {
                match key.get(k) {
                    Some(c) if *c != 0 => {
                        *b ^= c << 1;
                        k += 1;
                    }
                    _ => break,
                }
            }
            des.set_key(&keybuf);
        }
        (count, salt, setting[..9].to_vec())
    } else {
        let unsafe_char = |c: u8| c == 0 || c == b'\n' || c == b':';
        if unsafe_char(at(0)) || unsafe_char(at(1)) {
            return None;
        }
        let salt = (des_ascii_to_bin(at(1)) << 6) | des_ascii_to_bin(at(0));
        (25, salt, setting[..2].to_vec())
    };
    des.set_salt(salt);
    let (r0, r1) = des.encrypt(0, 0, count);
    let mut push = |v: u32, shifts: &[u32]| {
        for s in shifts {
            out.push(CRYPT_ITOA64[((v >> s) & 0x3f) as usize]);
        }
    };
    push(r0 >> 8, &[18, 12, 6, 0]);
    push((r0 << 16) | ((r1 >> 16) & 0xffff), &[18, 12, 6, 0]);
    push(r1 << 2, &[12, 6, 0]);
    Some(out)
}

// ---------------------------------------------------------------------------
// hash()
// ---------------------------------------------------------------------------

mod tables;

/// `hash($algo, $data)` as lowercase hex, for every algorithm of
/// `hash_algos()`; `None` for any other name (PHP's "must be a valid hashing
/// algorithm"). The name is case-insensitive.
pub fn hash(algo: &[u8], data: &[u8]) -> Option<String> {
    use sha2::Digest;
    let hex = |b: &[u8]| b.iter().map(|x| format!("{x:02x}")).collect::<String>();
    Some(match algo.to_ascii_lowercase().as_slice() {
        b"md2" => hex(&md2::Md2::digest(data)),
        b"md4" => hex(&md4(data)),
        b"md5" => hex(&md5::Md5::digest(data)),
        b"sha1" => hex(&sha1::Sha1::digest(data)),
        b"sha224" => hex(&sha2::Sha224::digest(data)),
        b"sha256" => hex(&sha2::Sha256::digest(data)),
        b"sha384" => hex(&sha2::Sha384::digest(data)),
        b"sha512/224" => hex(&sha2::Sha512_224::digest(data)),
        b"sha512/256" => hex(&sha2::Sha512_256::digest(data)),
        b"sha512" => hex(&sha2::Sha512::digest(data)),
        b"sha3-224" => hex(&sha3::Sha3_224::digest(data)),
        b"sha3-256" => hex(&sha3::Sha3_256::digest(data)),
        b"sha3-384" => hex(&sha3::Sha3_384::digest(data)),
        b"sha3-512" => hex(&sha3::Sha3_512::digest(data)),
        b"ripemd128" => hex(&ripemd::Ripemd128::digest(data)),
        b"ripemd160" => hex(&ripemd::Ripemd160::digest(data)),
        b"ripemd256" => hex(&ripemd::Ripemd256::digest(data)),
        b"ripemd320" => hex(&ripemd::Ripemd320::digest(data)),
        b"whirlpool" => hex(&whirlpool::Whirlpool::digest(data)),
        b"tiger128,3" => hex(&tiger(data, 0)[..16]),
        b"tiger160,3" => hex(&tiger(data, 0)[..20]),
        b"tiger192,3" => hex(&tiger(data, 0)),
        b"tiger128,4" => hex(&tiger(data, 1)[..16]),
        b"tiger160,4" => hex(&tiger(data, 1)[..20]),
        b"tiger192,4" => hex(&tiger(data, 1)),
        b"snefru" | b"snefru256" => hex(&snefru(data)),
        name if name.starts_with(b"haval") => {
            let spec = &name[5..];
            let (bits, passes) = match spec {
                b"128,3" => (128, 3),
                b"160,3" => (160, 3),
                b"192,3" => (192, 3),
                b"224,3" => (224, 3),
                b"256,3" => (256, 3),
                b"128,4" => (128, 4),
                b"160,4" => (160, 4),
                b"192,4" => (192, 4),
                b"224,4" => (224, 4),
                b"256,4" => (256, 4),
                b"128,5" => (128, 5),
                b"160,5" => (160, 5),
                b"192,5" => (192, 5),
                b"224,5" => (224, 5),
                b"256,5" => (256, 5),
                _ => return None,
            };
            hex(&haval(data, passes, bits))
        }
        b"gost" => hex(&gost94::Gost94Test::digest(data)),
        b"gost-crypto" => hex(&gost94::Gost94CryptoPro::digest(data)),
        b"adler32" => {
            let (mut a, mut b) = (1u32, 0u32);
            for x in data {
                a = (a + u32::from(*x)) % 65521;
                b = (b + a) % 65521;
            }
            format!("{:08x}", (b << 16) | a)
        }
        // bzip2's CRC (most significant bit first), written least significant byte first.
        b"crc32" => {
            let mut state = !0u32;
            for x in data {
                let mut c = (state >> 24) ^ u32::from(*x);
                c <<= 24;
                for _ in 0..8 {
                    c = if c & 0x8000_0000 != 0 { (c << 1) ^ 0x04c1_1db7 } else { c << 1 };
                }
                state = (state << 8) ^ c;
            }
            hex(&(!state).to_le_bytes())
        }
        b"crc32b" => format!("{:08x}", crc32_reflected(data, 0xedb8_8320)),
        b"crc32c" => format!("{:08x}", crc32_reflected(data, 0x82f6_3b78)),
        b"fnv132" | b"fnv1a32" => {
            let mut h = 0x811c_9dc5u32;
            for x in data {
                if algo.eq_ignore_ascii_case(b"fnv132") {
                    h = h.wrapping_mul(0x0100_0193) ^ u32::from(*x);
                } else {
                    h = (h ^ u32::from(*x)).wrapping_mul(0x0100_0193);
                }
            }
            format!("{h:08x}")
        }
        b"fnv164" | b"fnv1a64" => {
            let mut h = 0xcbf2_9ce4_8422_2325u64;
            for x in data {
                if algo.eq_ignore_ascii_case(b"fnv164") {
                    h = h.wrapping_mul(0x0000_0100_0000_01b3) ^ u64::from(*x);
                } else {
                    h = (h ^ u64::from(*x)).wrapping_mul(0x0000_0100_0000_01b3);
                }
            }
            format!("{h:016x}")
        }
        b"joaat" => {
            let mut h = 0u32;
            for x in data {
                h = h.wrapping_add(u32::from(*x));
                h = h.wrapping_add(h << 10);
                h ^= h >> 6;
            }
            h = h.wrapping_add(h << 3);
            h ^= h >> 11;
            h = h.wrapping_add(h << 15);
            format!("{h:08x}")
        }
        b"murmur3a" => format!("{:08x}", murmur3_x86_32(data)),
        b"murmur3c" => murmur3_x86_128(data).iter().map(|h| format!("{h:08x}")).collect(),
        b"murmur3f" => murmur3_x64_128(data).iter().map(|h| format!("{h:016x}")).collect(),
        b"xxh32" => format!("{:08x}", xxh32(data)),
        b"xxh64" => format!("{:016x}", xxh64(data)),
        b"xxh3" => format!("{:016x}", xxhash_rust::xxh3::xxh3_64(data)),
        b"xxh128" => format!("{:032x}", xxhash_rust::xxh3::xxh3_128(data)),
        _ => return None,
    })
}

/// Tiger with `extra` passes beyond three (`PHP_3TIGER` / `PHP_4TIGER`):
/// the state's words little-endian.
fn tiger(data: &[u8], extra: usize) -> [u8; 24] {
    let mut msg = data.to_vec();
    msg.push(0x01);
    while msg.len() % 64 != 56 {
        msg.push(0);
    }
    msg.extend_from_slice(&(data.len() as u64).wrapping_mul(8).to_le_bytes());
    let mut state = [0x0123_4567_89ab_cdefu64, 0xfedc_ba98_7654_3210, 0xf096_a5b4_c3b2_e187];
    for block in msg.chunks(64) {
        let mut x = [0u64; 8];
        for (i, w) in x.iter_mut().enumerate() {
            *w = u64::from_le_bytes(block[i * 8..i * 8 + 8].try_into().unwrap_or_default());
        }
        tiger_compress(&mut state, &mut x, extra);
    }
    let mut out = [0u8; 24];
    for (i, w) in state.iter().enumerate() {
        out[i * 8..i * 8 + 8].copy_from_slice(&w.to_le_bytes());
    }
    out
}

fn tiger_round(a: &mut u64, b: &mut u64, c: &mut u64, x: u64, mul: u64) {
    let t = &tables::TIGER;
    let s = |box_: usize, byte: u64| t[box_ * 256 + (byte & 0xff) as usize];
    *c ^= x;
    let (lo, hi) = (*c & 0xffff_ffff, *c >> 32);
    *a = a.wrapping_sub(s(0, lo) ^ s(1, lo >> 16) ^ s(2, hi) ^ s(3, hi >> 16));
    *b = b.wrapping_add(s(3, lo >> 8) ^ s(2, lo >> 24) ^ s(1, hi >> 8) ^ s(0, hi >> 24));
    *b = b.wrapping_mul(mul);
}

fn tiger_pass(a: &mut u64, b: &mut u64, c: &mut u64, x: &[u64; 8], mul: u64) {
    tiger_round(a, b, c, x[0], mul);
    tiger_round(b, c, a, x[1], mul);
    tiger_round(c, a, b, x[2], mul);
    tiger_round(a, b, c, x[3], mul);
    tiger_round(b, c, a, x[4], mul);
    tiger_round(c, a, b, x[5], mul);
    tiger_round(a, b, c, x[6], mul);
    tiger_round(b, c, a, x[7], mul);
}

fn tiger_key_schedule(x: &mut [u64; 8]) {
    x[0] = x[0].wrapping_sub(x[7] ^ 0xa5a5_a5a5_a5a5_a5a5);
    x[1] ^= x[0];
    x[2] = x[2].wrapping_add(x[1]);
    x[3] = x[3].wrapping_sub(x[2] ^ ((!x[1]) << 19));
    x[4] ^= x[3];
    x[5] = x[5].wrapping_add(x[4]);
    x[6] = x[6].wrapping_sub(x[5] ^ ((!x[4]) >> 23));
    x[7] ^= x[6];
    x[0] = x[0].wrapping_add(x[7]);
    x[1] = x[1].wrapping_sub(x[0] ^ ((!x[7]) << 19));
    x[2] ^= x[1];
    x[3] = x[3].wrapping_add(x[2]);
    x[4] = x[4].wrapping_sub(x[3] ^ ((!x[2]) >> 23));
    x[5] ^= x[4];
    x[6] = x[6].wrapping_add(x[5]);
    x[7] = x[7].wrapping_sub(x[6] ^ 0x0123_4567_89ab_cdef);
}

fn tiger_compress(state: &mut [u64; 3], x: &mut [u64; 8], extra: usize) {
    let [mut a, mut b, mut c] = *state;
    let (aa, bb, cc) = (a, b, c);
    tiger_pass(&mut a, &mut b, &mut c, x, 5);
    tiger_key_schedule(x);
    tiger_pass(&mut c, &mut a, &mut b, x, 7);
    tiger_key_schedule(x);
    tiger_pass(&mut b, &mut c, &mut a, x, 9);
    for _ in 0..extra {
        tiger_key_schedule(x);
        tiger_pass(&mut a, &mut b, &mut c, x, 9);
        (a, b, c) = (c, a, b);
    }
    *state = [a ^ aa, b.wrapping_sub(bb), c.wrapping_add(cc)];
}

/// Snefru (`PHP_SNEFRU`): 32-byte blocks into the upper half of a sixteen
/// word state, the bit count in its last two words, words big-endian.
fn snefru(data: &[u8]) -> [u8; 32] {
    let mut state = [0u32; 16];
    let bits = (data.len() as u64).wrapping_mul(8);
    // PHP_SNEFRUUpdate's carry: one when the bit count passes 32 bits.
    let count = if bits > 0xffff_ffff { [1, (bits as u32).wrapping_sub(0xffff_ffff)] } else { [0, bits as u32] };
    for block in data.chunks(32) {
        let mut padded = [0u8; 32];
        padded[..block.len()].copy_from_slice(block);
        for (j, w) in padded.chunks(4).enumerate() {
            state[8 + j] = u32::from_be_bytes([w[0], w[1], w[2], w[3]]);
        }
        snefru_block(&mut state);
        state[8..].fill(0);
    }
    state[14] = count[0];
    state[15] = count[1];
    snefru_block(&mut state);
    let mut out = [0u8; 32];
    for (i, w) in state[..8].iter().enumerate() {
        out[i * 4..i * 4 + 4].copy_from_slice(&w.to_be_bytes());
    }
    out
}

fn snefru_block(state: &mut [u32; 16]) {
    let mut b = *state;
    for index in 0..8 {
        let boxes = [&tables::SNEFRU[2 * index], &tables::SNEFRU[2 * index + 1]];
        for shift in [16, 8, 16, 24] {
            for k in 0..16 {
                let sbe = boxes[(k / 2) % 2][(b[k] & 0xff) as usize];
                b[(k + 15) % 16] ^= sbe;
                b[(k + 1) % 16] ^= sbe;
            }
            for v in b.iter_mut() {
                *v = v.rotate_right(shift);
            }
        }
    }
    for i in 0..8 {
        state[i] ^= b[15 - i];
    }
}

/// HAVAL with `passes` passes and an `output`-bit digest (`PHP_HAVAL`):
/// 128-byte blocks of little-endian words, padded to 118 mod 128, then the
/// version, passes, length and bit count; shorter digests fold the state.
fn haval(data: &[u8], passes: u32, output: u32) -> Vec<u8> {
    let count = [((data.len() as u64) << 3) as u32, (data.len() as u64 >> 29) as u32];
    let mut msg = data.to_vec();
    let index = data.len() % 128;
    let pad = if index < 118 { 118 - index } else { 246 - index };
    msg.push(1);
    msg.resize(msg.len() + pad - 1, 0);
    msg.push((1 | ((passes & 7) << 3) | ((output & 3) << 6)) as u8);
    msg.push((output >> 2) as u8);
    for c in count {
        msg.extend_from_slice(&c.to_le_bytes());
    }
    let mut s = tables::HAVAL_D0;
    for block in msg.chunks(128) {
        haval_transform(&mut s, block, passes);
    }
    let rotr = |x: u32, n: u32| x.rotate_right(n);
    match output {
        128 => {
            s[3] = s[3].wrapping_add(
                (s[7] & 0xff00_0000) | (s[6] & 0x00ff_0000) | (s[5] & 0x0000_ff00) | (s[4] & 0x0000_00ff),
            );
            s[2] = s[2].wrapping_add(
                (((s[7] & 0x00ff_0000) | (s[6] & 0x0000_ff00) | (s[5] & 0x0000_00ff)) << 8)
                    | ((s[4] & 0xff00_0000) >> 24),
            );
            s[1] = s[1].wrapping_add(
                (((s[7] & 0x0000_ff00) | (s[6] & 0x0000_00ff)) << 16)
                    | (((s[5] & 0xff00_0000) | (s[4] & 0x00ff_0000)) >> 16),
            );
            s[0] = s[0].wrapping_add(
                ((s[7] & 0x0000_00ff) << 24)
                    | (((s[6] & 0xff00_0000) | (s[5] & 0x00ff_0000) | (s[4] & 0x0000_ff00)) >> 8),
            );
        }
        160 => {
            s[4] = s[4].wrapping_add(((s[7] & 0xfe00_0000) | (s[6] & 0x01f8_0000) | (s[5] & 0x0007_f000)) >> 12);
            s[3] = s[3].wrapping_add(((s[7] & 0x01f8_0000) | (s[6] & 0x0007_f000) | (s[5] & 0x0000_0fc0)) >> 6);
            s[2] = s[2].wrapping_add((s[7] & 0x0007_f000) | (s[6] & 0x0000_0fc0) | (s[5] & 0x0000_003f));
            s[1] = s[1].wrapping_add(rotr((s[7] & 0x0000_0fc0) | (s[6] & 0x0000_003f) | (s[5] & 0xfe00_0000), 25));
            s[0] = s[0].wrapping_add(rotr((s[7] & 0x0000_003f) | (s[6] & 0xfe00_0000) | (s[5] & 0x01f8_0000), 19));
        }
        192 => {
            s[5] = s[5].wrapping_add(((s[7] & 0xfc00_0000) | (s[6] & 0x03e0_0000)) >> 21);
            s[4] = s[4].wrapping_add(((s[7] & 0x03e0_0000) | (s[6] & 0x001f_0000)) >> 16);
            s[3] = s[3].wrapping_add(((s[7] & 0x001f_0000) | (s[6] & 0x0000_fc00)) >> 10);
            s[2] = s[2].wrapping_add(((s[7] & 0x0000_fc00) | (s[6] & 0x0000_03e0)) >> 5);
            s[1] = s[1].wrapping_add((s[7] & 0x0000_03e0) | (s[6] & 0x0000_001f));
            s[0] = s[0].wrapping_add(rotr((s[7] & 0x0000_001f) | (s[6] & 0xfc00_0000), 26));
        }
        224 => {
            s[6] = s[6].wrapping_add(s[7] & 0x0000_000f);
            s[5] = s[5].wrapping_add((s[7] >> 4) & 0x0000_001f);
            s[4] = s[4].wrapping_add((s[7] >> 9) & 0x0000_000f);
            s[3] = s[3].wrapping_add((s[7] >> 13) & 0x0000_001f);
            s[2] = s[2].wrapping_add((s[7] >> 18) & 0x0000_000f);
            s[1] = s[1].wrapping_add((s[7] >> 22) & 0x0000_001f);
            s[0] = s[0].wrapping_add((s[7] >> 27) & 0x0000_001f);
        }
        _ => {}
    }
    s.iter().flat_map(|w| w.to_le_bytes()).take(output as usize / 8).collect()
}

/// `PHP_{3,4,5}HAVALTransform`: each pass applies its boolean function to a
/// permutation of the eight words (`M`), the argument orders below.
fn haval_transform(state: &mut [u32; 8], block: &[u8], passes: u32) {
    type F = fn(u32, u32, u32, u32, u32, u32, u32) -> u32;
    let f1: F = |x6, x5, x4, x3, x2, x1, x0| (x1 & x4) ^ (x2 & x5) ^ (x3 & x6) ^ (x0 & x1) ^ x0;
    let f2: F = |x6, x5, x4, x3, x2, x1, x0| {
        (x1 & x2 & x3) ^ (x2 & x4 & x5) ^ (x1 & x2) ^ (x1 & x4) ^ (x2 & x6) ^ (x3 & x5) ^ (x4 & x5) ^ (x0 & x2) ^ x0
    };
    let f3: F = |x6, x5, x4, x3, x2, x1, x0| (x1 & x2 & x3) ^ (x1 & x4) ^ (x2 & x5) ^ (x3 & x6) ^ (x0 & x3) ^ x0;
    let f4: F = |x6, x5, x4, x3, x2, x1, x0| {
        (x1 & x2 & x3)
            ^ (x2 & x4 & x5)
            ^ (x3 & x4 & x6)
            ^ (x1 & x4)
            ^ (x2 & x6)
            ^ (x3 & x4)
            ^ (x3 & x5)
            ^ (x3 & x6)
            ^ (x4 & x5)
            ^ (x4 & x6)
            ^ (x0 & x4)
            ^ x0
    };
    let f5: F = |x6, x5, x4, x3, x2, x1, x0| (x1 & x4) ^ (x2 & x5) ^ (x3 & x6) ^ (x0 & x1 & x2 & x3) ^ (x0 & x5) ^ x0;
    let orders: &[[usize; 7]] = match passes {
        3 => &[[1, 0, 3, 5, 6, 2, 4], [4, 2, 1, 0, 5, 3, 6], [6, 1, 2, 3, 4, 5, 0]],
        4 => &[[2, 6, 1, 4, 5, 3, 0], [3, 5, 2, 0, 1, 6, 4], [1, 4, 3, 6, 0, 2, 5], [6, 4, 0, 5, 2, 1, 3]],
        _ => &[
            [3, 4, 1, 0, 5, 2, 6],
            [6, 2, 1, 0, 3, 4, 5],
            [2, 6, 0, 4, 3, 1, 5],
            [1, 5, 3, 2, 0, 4, 6],
            [2, 5, 0, 6, 4, 3, 1],
        ],
    };
    let functions = [f1, f2, f3, f4, f5];
    let words: [Option<(&[usize; 32], &[u32; 32])>; 5] = [
        None,
        Some((&tables::HAVAL_I2, &tables::HAVAL_K2)),
        Some((&tables::HAVAL_I3, &tables::HAVAL_K3)),
        Some((&tables::HAVAL_I4, &tables::HAVAL_K4)),
        Some((&tables::HAVAL_I5, &tables::HAVAL_K5)),
    ];
    let x: Vec<u32> = block.chunks(4).map(|w| u32::from_le_bytes([w[0], w[1], w[2], w[3]])).collect();
    let mut e = *state;
    for (p, order) in orders.iter().enumerate() {
        for i in 0..32 {
            let m = |k: usize| e[(k + 8 - i % 8) % 8];
            let f =
                functions[p](m(order[0]), m(order[1]), m(order[2]), m(order[3]), m(order[4]), m(order[5]), m(order[6]));
            let word = match words[p] {
                None => x[i],
                Some((index, k)) => x[index[i]].wrapping_add(k[i]),
            };
            e[7 - i % 8] = f.rotate_right(7).wrapping_add(m(7).rotate_right(11)).wrapping_add(word);
        }
    }
    for (s, v) in state.iter_mut().zip(e) {
        *s = s.wrapping_add(v);
    }
}

fn crc32_reflected(data: &[u8], poly: u32) -> u32 {
    let mut state = !0u32;
    for x in data {
        state ^= u32::from(*x);
        for _ in 0..8 {
            state = if state & 1 != 0 { (state >> 1) ^ poly } else { state >> 1 };
        }
    }
    !state
}

/// RFC 1320.
fn md4(data: &[u8]) -> [u8; 16] {
    let mut msg = data.to_vec();
    let bits = (data.len() as u64).wrapping_mul(8);
    msg.push(0x80);
    while msg.len() % 64 != 56 {
        msg.push(0);
    }
    msg.extend_from_slice(&bits.to_le_bytes());
    let mut s = [0x6745_2301u32, 0xefcd_ab89, 0x98ba_dcfe, 0x1032_5476];
    for block in msg.chunks(64) {
        let x: Vec<u32> = block.chunks(4).map(|w| u32::from_le_bytes([w[0], w[1], w[2], w[3]])).collect();
        let [mut a, mut b, mut c, mut d] = s;
        let f = |x: u32, y: u32, z: u32| (x & y) | (!x & z);
        let g = |x: u32, y: u32, z: u32| (x & y) | (x & z) | (y & z);
        let h = |x: u32, y: u32, z: u32| x ^ y ^ z;
        for &i in &[0usize, 4, 8, 12] {
            a = a.wrapping_add(f(b, c, d)).wrapping_add(x[i]).rotate_left(3);
            d = d.wrapping_add(f(a, b, c)).wrapping_add(x[i + 1]).rotate_left(7);
            c = c.wrapping_add(f(d, a, b)).wrapping_add(x[i + 2]).rotate_left(11);
            b = b.wrapping_add(f(c, d, a)).wrapping_add(x[i + 3]).rotate_left(19);
        }
        for &i in &[0usize, 1, 2, 3] {
            a = a.wrapping_add(g(b, c, d)).wrapping_add(x[i]).wrapping_add(0x5a82_7999).rotate_left(3);
            d = d.wrapping_add(g(a, b, c)).wrapping_add(x[i + 4]).wrapping_add(0x5a82_7999).rotate_left(5);
            c = c.wrapping_add(g(d, a, b)).wrapping_add(x[i + 8]).wrapping_add(0x5a82_7999).rotate_left(9);
            b = b.wrapping_add(g(c, d, a)).wrapping_add(x[i + 12]).wrapping_add(0x5a82_7999).rotate_left(13);
        }
        for &i in &[0usize, 2, 1, 3] {
            a = a.wrapping_add(h(b, c, d)).wrapping_add(x[i]).wrapping_add(0x6ed9_eba1).rotate_left(3);
            d = d.wrapping_add(h(a, b, c)).wrapping_add(x[i + 8]).wrapping_add(0x6ed9_eba1).rotate_left(9);
            c = c.wrapping_add(h(d, a, b)).wrapping_add(x[i + 4]).wrapping_add(0x6ed9_eba1).rotate_left(11);
            b = b.wrapping_add(h(c, d, a)).wrapping_add(x[i + 12]).wrapping_add(0x6ed9_eba1).rotate_left(15);
        }
        s = [s[0].wrapping_add(a), s[1].wrapping_add(b), s[2].wrapping_add(c), s[3].wrapping_add(d)];
    }
    let mut out = [0u8; 16];
    for (i, w) in s.iter().enumerate() {
        out[i * 4..i * 4 + 4].copy_from_slice(&w.to_le_bytes());
    }
    out
}

fn fmix32(mut h: u32) -> u32 {
    h ^= h >> 16;
    h = h.wrapping_mul(0x85eb_ca6b);
    h ^= h >> 13;
    h = h.wrapping_mul(0xc2b2_ae35);
    h ^ (h >> 16)
}

fn fmix64(mut k: u64) -> u64 {
    k ^= k >> 33;
    k = k.wrapping_mul(0xff51_afd7_ed55_8ccd);
    k ^= k >> 33;
    k = k.wrapping_mul(0xc4ce_b9fe_1a85_ec53);
    k ^ (k >> 33)
}

/// The tail bytes of a block, little-endian, as one integer.
fn tail(bytes: &[u8]) -> u64 {
    bytes.iter().rev().fold(0u64, |acc, b| (acc << 8) | u64::from(*b))
}

/// MurmurHash3_x86_32, seed 0.
fn murmur3_x86_32(data: &[u8]) -> u32 {
    let (c1, c2) = (0xcc9e_2d51u32, 0x1b87_3593u32);
    let mut h = 0u32;
    let blocks = data.chunks_exact(4);
    let rest = blocks.remainder();
    for b in blocks {
        let k = u32::from_le_bytes([b[0], b[1], b[2], b[3]]).wrapping_mul(c1).rotate_left(15).wrapping_mul(c2);
        h = (h ^ k).rotate_left(13).wrapping_mul(5).wrapping_add(0xe654_6b64);
    }
    if !rest.is_empty() {
        h ^= (tail(rest) as u32).wrapping_mul(c1).rotate_left(15).wrapping_mul(c2);
    }
    fmix32(h ^ data.len() as u32)
}

/// MurmurHash3_x86_128, seed 0.
fn murmur3_x86_128(data: &[u8]) -> [u32; 4] {
    let c = [0x239b_961bu32, 0xab0e_9789, 0x38b3_4ae5, 0xa1e3_8b93];
    let rot = [15u32, 16, 17, 18];
    let hrot = [19u32, 17, 15, 13];
    let add = [0x561c_cd1bu32, 0x0bca_a747, 0x96cd_1c35, 0x32ac_3b17];
    let mut h = [0u32; 4];
    let blocks = data.chunks_exact(16);
    let rest = blocks.remainder();
    for b in blocks {
        let k: Vec<u32> = b.chunks(4).map(|w| u32::from_le_bytes([w[0], w[1], w[2], w[3]])).collect();
        for i in 0..4 {
            let n = (i + 1) % 4;
            h[i] ^= k[i].wrapping_mul(c[i]).rotate_left(rot[i]).wrapping_mul(c[n]);
            h[i] = h[i].rotate_left(hrot[i]).wrapping_add(h[n]).wrapping_mul(5).wrapping_add(add[i]);
        }
    }
    for i in (0..4).rev() {
        let part = rest.get(i * 4..rest.len().min(i * 4 + 4)).unwrap_or_default();
        if !part.is_empty() {
            let n = (i + 1) % 4;
            h[i] ^= (tail(part) as u32).wrapping_mul(c[i]).rotate_left(rot[i]).wrapping_mul(c[n]);
        }
    }
    let len = data.len() as u32;
    for x in h.iter_mut() {
        *x ^= len;
    }
    let mix = |h: &mut [u32; 4]| {
        h[0] = h[0].wrapping_add(h[1]).wrapping_add(h[2]).wrapping_add(h[3]);
        h[1] = h[1].wrapping_add(h[0]);
        h[2] = h[2].wrapping_add(h[0]);
        h[3] = h[3].wrapping_add(h[0]);
    };
    mix(&mut h);
    for x in h.iter_mut() {
        *x = fmix32(*x);
    }
    mix(&mut h);
    h
}

/// MurmurHash3_x64_128, seed 0.
fn murmur3_x64_128(data: &[u8]) -> [u64; 2] {
    let (c1, c2) = (0x87c3_7b91_1142_53d5u64, 0x4cf5_ad43_2745_937fu64);
    let (mut h1, mut h2) = (0u64, 0u64);
    let blocks = data.chunks_exact(16);
    let rest = blocks.remainder();
    for b in blocks {
        let k1 = u64::from_le_bytes(b[0..8].try_into().unwrap_or_default());
        let k2 = u64::from_le_bytes(b[8..16].try_into().unwrap_or_default());
        h1 ^= k1.wrapping_mul(c1).rotate_left(31).wrapping_mul(c2);
        h1 = h1.rotate_left(27).wrapping_add(h2).wrapping_mul(5).wrapping_add(0x52dc_e729);
        h2 ^= k2.wrapping_mul(c2).rotate_left(33).wrapping_mul(c1);
        h2 = h2.rotate_left(31).wrapping_add(h1).wrapping_mul(5).wrapping_add(0x3849_5ab5);
    }
    if rest.len() > 8 {
        h2 ^= tail(&rest[8..]).wrapping_mul(c2).rotate_left(33).wrapping_mul(c1);
    }
    if !rest.is_empty() {
        h1 ^= tail(&rest[..rest.len().min(8)]).wrapping_mul(c1).rotate_left(31).wrapping_mul(c2);
    }
    let len = data.len() as u64;
    h1 ^= len;
    h2 ^= len;
    h1 = h1.wrapping_add(h2);
    h2 = h2.wrapping_add(h1);
    h1 = fmix64(h1);
    h2 = fmix64(h2);
    h1 = h1.wrapping_add(h2);
    h2 = h2.wrapping_add(h1);
    [h1, h2]
}

/// XXH32, seed 0.
fn xxh32(data: &[u8]) -> u32 {
    const P1: u32 = 0x9e37_79b1;
    const P2: u32 = 0x85eb_ca77;
    const P3: u32 = 0xc2b2_ae3d;
    const P4: u32 = 0x27d4_eb2f;
    const P5: u32 = 0x1656_67b1;
    let round = |acc: u32, lane: u32| acc.wrapping_add(lane.wrapping_mul(P2)).rotate_left(13).wrapping_mul(P1);
    let word = |b: &[u8]| u32::from_le_bytes([b[0], b[1], b[2], b[3]]);
    let mut rest = data;
    let mut h = if data.len() >= 16 {
        let mut v = [P1.wrapping_add(P2), P2, 0, 0u32.wrapping_sub(P1)];
        while rest.len() >= 16 {
            for (i, lane) in v.iter_mut().enumerate() {
                *lane = round(*lane, word(&rest[i * 4..]));
            }
            rest = &rest[16..];
        }
        v[0].rotate_left(1)
            .wrapping_add(v[1].rotate_left(7))
            .wrapping_add(v[2].rotate_left(12))
            .wrapping_add(v[3].rotate_left(18))
    } else {
        P5
    };
    h = h.wrapping_add(data.len() as u32);
    while rest.len() >= 4 {
        h = h.wrapping_add(word(rest).wrapping_mul(P3)).rotate_left(17).wrapping_mul(P4);
        rest = &rest[4..];
    }
    for b in rest {
        h = h.wrapping_add(u32::from(*b).wrapping_mul(P5)).rotate_left(11).wrapping_mul(P1);
    }
    h ^= h >> 15;
    h = h.wrapping_mul(P2);
    h ^= h >> 13;
    h = h.wrapping_mul(P3);
    h ^ (h >> 16)
}

/// XXH64, seed 0.
fn xxh64(data: &[u8]) -> u64 {
    const P1: u64 = 0x9e37_79b1_85eb_ca87;
    const P2: u64 = 0xc2b2_ae3d_27d4_eb4f;
    const P3: u64 = 0x1656_67b1_9e37_79f9;
    const P4: u64 = 0x85eb_ca77_c2b2_ae63;
    const P5: u64 = 0x27d4_eb2f_1656_67c5;
    let round = |acc: u64, lane: u64| acc.wrapping_add(lane.wrapping_mul(P2)).rotate_left(31).wrapping_mul(P1);
    let merge = |acc: u64, v: u64| (acc ^ round(0, v)).wrapping_mul(P1).wrapping_add(P4);
    let word = |b: &[u8]| u64::from_le_bytes(b[..8].try_into().unwrap_or_default());
    let mut rest = data;
    let mut h = if data.len() >= 32 {
        let mut v = [P1.wrapping_add(P2), P2, 0, 0u64.wrapping_sub(P1)];
        while rest.len() >= 32 {
            for (i, lane) in v.iter_mut().enumerate() {
                *lane = round(*lane, word(&rest[i * 8..]));
            }
            rest = &rest[32..];
        }
        let mut h = v[0]
            .rotate_left(1)
            .wrapping_add(v[1].rotate_left(7))
            .wrapping_add(v[2].rotate_left(12))
            .wrapping_add(v[3].rotate_left(18));
        for lane in v {
            h = merge(h, lane);
        }
        h
    } else {
        P5
    };
    h = h.wrapping_add(data.len() as u64);
    while rest.len() >= 8 {
        h = (h ^ round(0, word(rest))).rotate_left(27).wrapping_mul(P1).wrapping_add(P4);
        rest = &rest[8..];
    }
    if rest.len() >= 4 {
        h = (h ^ u64::from(u32::from_le_bytes([rest[0], rest[1], rest[2], rest[3]])).wrapping_mul(P1))
            .rotate_left(23)
            .wrapping_mul(P2)
            .wrapping_add(P3);
        rest = &rest[4..];
    }
    for b in rest {
        h = (h ^ u64::from(*b).wrapping_mul(P5)).rotate_left(11).wrapping_mul(P1);
    }
    h ^= h >> 33;
    h = h.wrapping_mul(P2);
    h ^= h >> 29;
    h = h.wrapping_mul(P3);
    h ^ (h >> 32)
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
