//! Number formatting and casts: `sprintf`/`vsprintf`, `number_format`,
//! `round`/`floor`/`ceil`, `intval`/`floatval`/`boolval` and the `(int)` /
//! `(float)` casts, `intdiv`, `fmod`, `abs`, `max`/`min` of numbers.
//!
//! Ported from PHP 8.5.10 (`ext/standard/formatted_print.c`, `math.c`,
//! `type.c`, `array.c`, `Zend/zend_operators.c`, `main/snprintf.c`).
//! Floating-point digits come from Rust's correctly rounded formatting,
//! which agrees with PHP's `zend_dtoa` (also correctly rounded, ties to
//! even) in every mode PHP uses here.
//!
//! | PHP | Rust |
//! |---|---|
//! | `sprintf`, `vsprintf` | [`sprintf`], [`vsprintf`] with [`Arg`] |
//! | `number_format` | [`number_format`] |
//! | `round`, `floor`, `ceil`, `abs`, `fmod`, `fdiv`, `intdiv` | [`round`] with [`RoundingMode`], [`floor`], [`ceil`], [`abs`], [`fmod`], [`fdiv`], [`intdiv`] |
//! | `intval`, `floatval`, `boolval`, `(int)`, `(float)` | [`intval`], [`floatval`], [`boolval`], [`Arg::to_int`], [`Arg::to_float`], [`str_to_int`], [`str_to_float`] |
//! | `max`, `min` (numbers) | [`max`], [`min`], [`max_array`], [`min_array`] |
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `format.*`).

use std::borrow::Cow;

use crate::number;
use crate::string::Error;
use crate::value::Number;

/// `INT_MAX`, the bound of `sprintf` widths, precisions and argument numbers.
const INT_MAX: i64 = i32::MAX as i64;

// ---------------------------------------------------------------------------
// Values and conversions
// ---------------------------------------------------------------------------

/// A PHP value as the formatting and conversion functions see it.
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum Arg<'a> {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    /// A (byte) string.
    Str(&'a [u8]),
    /// An array with this many elements.
    Array(usize),
    /// A `stdClass` object.
    Object,
}

impl<'a> From<&'a serde_json::Value> for Arg<'a> {
    /// A JSON value as PHP decodes it: objects are arrays, except empty ones
    /// (`stdClass`); integers beyond `i64` are floats.
    fn from(value: &'a serde_json::Value) -> Self {
        use serde_json::Value;
        match value {
            Value::Null => Arg::Null,
            Value::Bool(b) => Arg::Bool(*b),
            Value::Number(n) => match crate::value::json_number(n) {
                Number::Int(i) => Arg::Int(i),
                Number::Float(f) => Arg::Float(f),
            },
            Value::String(s) => Arg::Str(s.as_bytes()),
            Value::Array(a) => Arg::Array(a.len()),
            Value::Object(o) if o.is_empty() => Arg::Object,
            Value::Object(o) => Arg::Array(o.len()),
        }
    }
}

impl Arg<'_> {
    /// `zval_get_long`, the `(int)` cast: floats truncate (out of range:
    /// modulo 2^64, non-finite: 0), strings use their leading number
    /// ([`str_to_int`]), arrays are 0 or 1, a `stdClass` is 1.
    pub fn to_int(&self) -> i64 {
        match *self {
            Arg::Null | Arg::Bool(false) => 0,
            Arg::Bool(true) | Arg::Object => 1,
            Arg::Int(i) => i,
            Arg::Float(f) => dval_to_lval(f),
            Arg::Str(s) => str_to_int(s),
            Arg::Array(n) => (n > 0) as i64,
        }
    }

    /// `zval_get_double`, the `(float)` cast: strings use their leading
    /// number ([`str_to_float`]), arrays are 0.0 or 1.0, a `stdClass` is 1.0.
    pub fn to_float(&self) -> f64 {
        match *self {
            Arg::Null | Arg::Bool(false) => 0.0,
            Arg::Bool(true) | Arg::Object => 1.0,
            Arg::Int(i) => i as f64,
            Arg::Float(f) => f,
            Arg::Str(s) => str_to_float(s),
            Arg::Array(n) => {
                if n > 0 {
                    1.0
                } else {
                    0.0
                }
            }
        }
    }

    /// `zend_is_true`, the `(bool)` cast.
    pub fn to_bool(&self) -> bool {
        match *self {
            Arg::Null => false,
            Arg::Bool(b) => b,
            Arg::Int(i) => i != 0,
            Arg::Float(f) => f != 0.0,
            Arg::Str(s) => !(s.is_empty() || s == b"0"),
            Arg::Array(n) => n > 0,
            Arg::Object => true,
        }
    }

    /// `zval_get_string`, the `(string)` cast: floats with `precision` 14,
    /// arrays `"Array"`; a `stdClass` throws `Error`.
    pub fn to_bytes(&self) -> Result<Cow<'_, [u8]>, Error> {
        Ok(match *self {
            Arg::Null | Arg::Bool(false) => Cow::Borrowed(b""),
            Arg::Bool(true) => Cow::Borrowed(b"1"),
            Arg::Int(i) => Cow::Owned(i.to_string().into_bytes()),
            Arg::Float(f) => Cow::Owned(number::to_string(f).into_bytes()),
            Arg::Str(s) => Cow::Borrowed(s),
            Arg::Array(_) => Cow::Borrowed(b"Array"),
            Arg::Object => {
                return Err(Error::Error("Object of class stdClass could not be converted to string".into()));
            }
        })
    }
}

/// `zend_dval_to_lval`: a float to an integer as `(int)` converts it: NaN
/// and infinities are 0, values outside `i64` wrap modulo 2^64 (PHP warns).
pub fn dval_to_lval(d: f64) -> i64 {
    if !d.is_finite() {
        return 0;
    }
    if !fits_long(d) {
        let two64 = 18446744073709551616.0f64;
        let mut m = d % two64;
        if m < 0.0 {
            m += two64;
        }
        return (m as u64) as i64;
    }
    d as i64
}

/// `ZEND_DOUBLE_FITS_LONG`: `-2^63 <= d < 2^63`.
fn fits_long(d: f64) -> bool {
    (-9223372036854775808.0..9223372036854775808.0).contains(&d)
}

/// `zend_dval_to_lval_cap`: like [`dval_to_lval`] but saturating, as numeric
/// strings convert.
fn dval_to_lval_cap(d: f64) -> i64 {
    if !d.is_finite() {
        0
    } else if !fits_long(d) {
        if d > 0.0 { i64::MAX } else { i64::MIN }
    } else {
        d as i64
    }
}

/// C `isspace` in the C locale (and PHP's numeric-string whitespace).
fn is_space(c: u8) -> bool {
    matches!(c, b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c)
}

/// The length of the longest prefix of `s` (after optional whitespace) that
/// `zend_strtod` converts: `[+-]? digits [. digits] [e [+-] digits]`, at least
/// one mantissa digit. Returns `(start, end)` of the number, or `None`.
fn strtod_span(s: &[u8], skip_space: bool) -> Option<(usize, usize)> {
    let mut i = 0;
    if skip_space {
        while i < s.len() && is_space(s[i]) {
            i += 1;
        }
    }
    let start = i;
    if i < s.len() && (s[i] == b'+' || s[i] == b'-') {
        i += 1;
    }
    let int_start = i;
    while i < s.len() && s[i].is_ascii_digit() {
        i += 1;
    }
    let mut digits = i - int_start;
    if i < s.len() && s[i] == b'.' {
        let frac_start = i + 1;
        let mut j = frac_start;
        while j < s.len() && s[j].is_ascii_digit() {
            j += 1;
        }
        digits += j - frac_start;
        if digits > 0 {
            i = j;
        }
    }
    if digits == 0 {
        return None;
    }
    if i < s.len() && (s[i] == b'e' || s[i] == b'E') {
        let mut j = i + 1;
        if j < s.len() && (s[j] == b'+' || s[j] == b'-') {
            j += 1;
        }
        let exp_start = j;
        while j < s.len() && s[j].is_ascii_digit() {
            j += 1;
        }
        if j > exp_start {
            i = j;
        }
    }
    Some((start, i))
}

/// Parses an ASCII decimal float literal, correctly rounded (as `zend_strtod`).
fn parse_decimal(text: &[u8]) -> f64 {
    std::str::from_utf8(text).ok().and_then(|t| t.parse::<f64>().ok()).unwrap_or(0.0)
}

/// `(float) $string` / `floatval($string)` (`zend_strtod`): the leading
/// decimal number after optional whitespace, `0.0` when there is none.
/// Hexadecimal, `INF` and `NAN` are not numbers; `"-0"` is `-0.0`; huge
/// exponents give infinity.
pub fn str_to_float(s: &[u8]) -> f64 {
    match strtod_span(s, true) {
        Some((start, end)) => parse_decimal(&s[start..end]),
        None => 0.0,
    }
}

/// C `strcmp` of `a` (up to its first NUL) against `b`.
fn c_strcmp(a: &[u8], b: &[u8]) -> std::cmp::Ordering {
    let a = a.iter().position(|&c| c == 0).map_or(a, |n| &a[..n]);
    a.cmp(b)
}

/// `(int) $string` / `intval($string)` (`_is_numeric_string_ex` with errors
/// allowed): the leading integer or float after optional whitespace; floats
/// and integers beyond `i64` saturate, non-finite floats are 0; no number is 0.
///
/// Quirk kept from PHP 8.5: for a 19-digit integer followed by `e+`/`e-`
/// and no exponent digit, PHP compares the wrong digits against
/// `PHP_INT_MIN`'s, so `"9223372036854775808e+"` is `PHP_INT_MIN`.
pub fn str_to_int(s: &[u8]) -> i64 {
    let at = |i: usize| s.get(i).copied().unwrap_or(0);
    if s.is_empty() || at(0) > b'9' {
        return 0;
    }
    let mut str_start = 0;
    while is_space(at(str_start)) {
        str_start += 1;
    }
    let mut ptr = str_start;
    let neg = at(ptr) == b'-';
    if neg || at(ptr) == b'+' {
        ptr += 1;
    }
    let float = |from: usize| dval_to_lval_cap(str_to_float(&s[from..]));
    if at(ptr).is_ascii_digit() {
        while at(ptr) == b'0' {
            ptr += 1;
        }
        let mut digits = 0usize;
        let mut value: u64 = 0;
        loop {
            if digits >= 20 {
                return float(str_start);
            }
            let c = at(ptr);
            if c.is_ascii_digit() {
                // zend_ulong arithmetic: wraps on the 20th digit, whose value
                // is then discarded for the float path.
                value = value.wrapping_mul(10).wrapping_add((c - b'0') as u64);
                digits += 1;
                ptr += 1;
                continue;
            } else if c == b'.' {
                return float(str_start);
            } else if c == b'e' || c == b'E' {
                let mut e = ptr + 1;
                if at(e) == b'-' || at(e) == b'+' {
                    ptr = e;
                    e += 1;
                }
                if at(e).is_ascii_digit() {
                    return float(str_start);
                }
            }
            break;
        }
        if digits == 19 {
            let from = ptr - digits;
            let cmp = c_strcmp(&s[from.min(s.len())..], b"9223372036854775808");
            if !(cmp.is_lt() || (cmp.is_eq() && at(str_start) == b'-')) {
                return float(str_start);
            }
        }
        if neg { value.wrapping_neg() as i64 } else { value as i64 }
    } else if at(ptr) == b'.' && at(ptr + 1).is_ascii_digit() {
        float(str_start)
    } else {
        0
    }
}

/// C `strtol(s, NULL, base)` (musl): optional whitespace and sign, a `0x`
/// prefix for base 16 (and 0), octal for base 0 with a leading `0`;
/// saturates on overflow; an invalid base gives 0.
fn strtol(s: &[u8], base: i64) -> i64 {
    if base < 0 || base == 1 || base > 36 {
        return 0;
    }
    let mut base = base as u64;
    let at = |i: usize| s.get(i).copied().unwrap_or(0);
    let digit = |c: u8| -> u64 {
        match c {
            b'0'..=b'9' => (c - b'0') as u64,
            b'a'..=b'z' => (c - b'a') as u64 + 10,
            b'A'..=b'Z' => (c - b'A') as u64 + 10,
            _ => 99,
        }
    };
    let mut i = 0;
    while is_space(at(i)) {
        i += 1;
    }
    let mut neg = false;
    if at(i) == b'+' || at(i) == b'-' {
        neg = at(i) == b'-';
        i += 1;
    }
    if (base == 0 || base == 16) && at(i) == b'0' {
        i += 1;
        if at(i) | 32 == b'x' {
            i += 1;
            if digit(at(i)) >= 16 {
                return 0;
            }
            base = 16;
        } else if base == 0 {
            base = 8;
        }
    } else {
        if base == 0 {
            base = 10;
        }
        if digit(at(i)) >= base {
            return 0;
        }
    }
    let limit: u64 = 1 << 63;
    let mut value: u64 = 0;
    let mut overflow = false;
    while digit(at(i)) < base {
        match value.checked_mul(base).and_then(|v| v.checked_add(digit(at(i)))) {
            Some(v) => value = v,
            None => overflow = true,
        }
        i += 1;
    }
    if overflow || value > limit || (value == limit && !neg) {
        return if neg { i64::MIN } else { i64::MAX };
    }
    if neg { value.wrapping_neg() as i64 } else { value as i64 }
}

/// PHP `intval($value, $base)`: [`Arg::to_int`] unless `value` is a string
/// and `base` is not 10; then C `strtol` with that base, where bases 0 and 2
/// also accept a `0b` prefix (after whitespace and a sign).
pub fn intval(value: Arg<'_>, base: i64) -> i64 {
    let Arg::Str(s) = value else {
        return value.to_int();
    };
    if base == 10 {
        return value.to_int();
    }
    if base == 0 || base == 2 {
        let mut t = s;
        while let Some((&c, rest)) = t.split_first() {
            if !is_space(c) {
                break;
            }
            t = rest;
        }
        if t.len() > 2 {
            let offset = (t[0] == b'-' || t[0] == b'+') as usize;
            if t[offset] == b'0' && (t[offset + 1] == b'b' || t[offset + 1] == b'B') {
                let mut digits = Vec::with_capacity(t.len() - 2);
                if offset == 1 {
                    digits.push(t[0]);
                }
                digits.extend_from_slice(&t[offset + 2..]);
                return strtol(&digits, 2);
            }
        }
    }
    strtol(s, base)
}

/// PHP `floatval($value)`, the `(float)` cast: [`Arg::to_float`].
pub fn floatval(value: Arg<'_>) -> f64 {
    value.to_float()
}

/// PHP `boolval($value)`, the `(bool)` cast: [`Arg::to_bool`].
pub fn boolval(value: Arg<'_>) -> bool {
    value.to_bool()
}

// ---------------------------------------------------------------------------
// sprintf
// ---------------------------------------------------------------------------

#[derive(Clone, Copy, PartialEq)]
enum Align {
    Left,
    Right,
}

const ADJ_WIDTH: u8 = 1;
const ADJ_PRECISION: u8 = 2;

/// PHP `sprintf($format, ...$values)`: every conversion (`%s %d %u %c %e %E
/// %f %F %g %G %h %H %o %x %X %b %%`), flags (`-`, `+`, space or `0`
/// padding, `'c` custom padding), width, precision, `*` and `n$` argument
/// numbers, with PHP's errors (`ValueError`, `ArgumentCountError`).
pub fn sprintf(format: &[u8], values: &[Arg<'_>]) -> Result<Vec<u8>, Error> {
    formatted_print(format, values, 1)
}

/// PHP `vsprintf($format, $values)`: [`sprintf`] with the arguments in an
/// array; missing arguments are a `ValueError` instead of an
/// `ArgumentCountError`.
pub fn vsprintf(format: &[u8], values: &[Arg<'_>]) -> Result<Vec<u8>, Error> {
    formatted_print(format, values, -1)
}

/// `php_sprintf_getnumber`: a run of digits as `strtol` reads it; -1 when
/// it is `INT_MAX` or more.
fn get_number(fmt: &[u8], pos: &mut usize) -> i64 {
    let start = *pos;
    let mut value: u64 = 0;
    while *pos < fmt.len() && fmt[*pos].is_ascii_digit() {
        value = value.saturating_mul(10).saturating_add((fmt[*pos] - b'0') as u64);
        *pos += 1;
    }
    if *pos == start {
        return 0;
    }
    if value >= INT_MAX as u64 { -1 } else { value as i64 }
}

/// `php_sprintf_get_argnum`: `Ok(None)` for "next argument", `Ok(Some(n))`
/// for an explicit zero-based `n$`.
fn get_argnum(fmt: &[u8], pos: &mut usize) -> Result<Option<usize>, Error> {
    let mut t = *pos;
    while t < fmt.len() && fmt[t].is_ascii_digit() {
        t += 1;
    }
    if fmt.get(t) != Some(&b'$') {
        return Ok(None);
    }
    let n = get_number(fmt, pos);
    if n <= 0 {
        return Err(Error::Value(format!(
            "Argument number specifier must be greater than zero and less than {INT_MAX}"
        )));
    }
    *pos += 1;
    Ok(Some(n as usize - 1))
}

#[allow(clippy::too_many_arguments)]
fn append_string(
    out: &mut Vec<u8>,
    add: &[u8],
    min_width: usize,
    max_width: usize,
    padding: u8,
    align: Align,
    neg: bool,
    expprec: bool,
    always_sign: bool,
) {
    let mut add = add;
    let mut copy_len = if expprec { max_width.min(add.len()) } else { add.len() };
    let npad = min_width.saturating_sub(copy_len);
    if align == Align::Right {
        if (neg || always_sign) && padding == b'0' {
            out.push(if neg { b'-' } else { b'+' });
            add = &add[1..];
            copy_len -= 1;
        }
        out.extend(std::iter::repeat_n(padding, npad));
    }
    out.extend_from_slice(&add[..copy_len]);
    if align == Align::Left {
        out.extend(std::iter::repeat_n(padding, npad));
    }
}

fn append_int(out: &mut Vec<u8>, number: i64, width: usize, padding: u8, align: Align, always_sign: bool) {
    let padding = if align == Align::Left && padding == b'0' { b' ' } else { padding };
    let neg = number < 0;
    let mut buf = Vec::with_capacity(21);
    if neg {
        buf.push(b'-');
    } else if always_sign {
        buf.push(b'+');
    }
    buf.extend_from_slice(number.unsigned_abs().to_string().as_bytes());
    append_string(out, &buf, width, 0, padding, align, neg, false, always_sign);
}

fn append_uint(out: &mut Vec<u8>, number: u64, width: usize, padding: u8, align: Align) {
    let padding = if align == Align::Left && padding == b'0' { b' ' } else { padding };
    append_string(out, number.to_string().as_bytes(), width, 0, padding, align, false, false, false);
}

#[allow(clippy::too_many_arguments)]
fn append_2n(
    out: &mut Vec<u8>,
    number: i64,
    width: usize,
    padding: u8,
    align: Align,
    bits: u32,
    upper: bool,
    expprec: bool,
) {
    let mut n = number as u64;
    let mask = (1u64 << bits) - 1;
    let table: &[u8; 16] = if upper { b"0123456789ABCDEF" } else { b"0123456789abcdef" };
    let mut digits = Vec::with_capacity(64);
    loop {
        digits.push(table[(n & mask) as usize]);
        n >>= bits;
        if n == 0 {
            break;
        }
    }
    digits.reverse();
    append_string(out, &digits, width, 0, padding, align, false, expprec, false);
}

/// `php_conv_fp('F', ...)`'s digits: `value` (made non-negative) with
/// `precision` decimals, correctly rounded.
fn fixed_digits(abs: f64, precision: usize) -> String {
    format!("{abs:.precision$}")
}

/// `php_conv_fp`: `%e`/`%E` (`exp` = Some) or `%F` (`exp` = None) without
/// the sign; returns the text and whether the value is negative (a zero,
/// including -0.0, is not).
fn conv_fp(value: f64, precision: usize, exp: Option<u8>) -> (Vec<u8>, bool) {
    let precision = precision.min(318);
    let negative = value < 0.0;
    let abs = value.abs();
    match exp {
        None => (fixed_digits(abs, precision).into_bytes(), negative),
        Some(e) => {
            let text = format!("{abs:.precision$e}");
            let (mantissa, exponent) = text.split_once('e').unwrap_or((&text, "0"));
            let exponent: i64 = exponent.parse().unwrap_or(0);
            let mut out = mantissa.as_bytes().to_vec();
            out.push(e);
            if exponent == 0 {
                out.extend_from_slice(b"+0");
            } else {
                out.push(if exponent < 0 { b'-' } else { b'+' });
                out.extend_from_slice(exponent.unsigned_abs().to_string().as_bytes());
            }
            (out, negative)
        }
    }
}

#[allow(clippy::too_many_arguments)]
fn append_double(
    out: &mut Vec<u8>,
    number: f64,
    width: usize,
    padding: u8,
    align: Align,
    precision: i64,
    adjust: u8,
    fmt: u8,
    always_sign: bool,
) {
    let mut precision = if adjust & ADJ_PRECISION == 0 { 6 } else { precision.min(53) };
    if number.is_nan() {
        append_string(out, b"NaN", 3, 0, padding, align, false, false, always_sign);
        return;
    }
    if number.is_infinite() {
        let neg = number < 0.0;
        let s: &[u8] = if neg { b"-INF" } else { b"INF" };
        append_string(out, s, s.len(), 0, padding, align, neg, false, always_sign);
        return;
    }
    let (text, negative) = match fmt {
        b'e' | b'E' | b'f' | b'F' => {
            let exp = if fmt == b'e' || fmt == b'E' { Some(fmt) } else { None };
            let (digits, negative) = conv_fp(number, precision.max(0) as usize, exp);
            let mut text = Vec::with_capacity(digits.len() + 1);
            if negative {
                text.push(b'-');
            } else if always_sign {
                text.push(b'+');
            }
            text.extend_from_slice(&digits);
            (text, negative)
        }
        _ => {
            if precision == 0 {
                precision = 1;
            }
            let exp_char = if fmt == b'G' || fmt == b'H' { 'E' } else { 'e' };
            let s = number::gcvt(number, precision as i32, exp_char);
            let negative = s.starts_with('-');
            let mut text = Vec::with_capacity(s.len() + 1);
            if !negative && always_sign {
                text.push(b'+');
            }
            text.extend_from_slice(s.as_bytes());
            (text, negative)
        }
    };
    append_string(out, &text, width, 0, padding, align, negative, false, always_sign);
}

/// `php_formatted_print`. `additional` is the number of arguments before
/// the values (1 for `sprintf`), or -1 for `vsprintf`'s array.
fn formatted_print(fmt: &[u8], args: &[Arg<'_>], additional: i64) -> Result<Vec<u8>, Error> {
    let at = |i: usize| fmt.get(i).copied().unwrap_or(0);
    let argc = args.len();
    let mut out = Vec::with_capacity(fmt.len() + 16);
    let mut pos = 0usize;
    let mut currarg = 0usize;
    let mut max_missing: Option<usize> = None;
    let mut pending: Option<Error> = None;
    let missing = |n: usize, max_missing: &mut Option<usize>| {
        *max_missing = Some(max_missing.map_or(n, |m| m.max(n)));
    };
    while pos < fmt.len() {
        match fmt[pos..].iter().position(|&c| c == b'%') {
            None => {
                out.extend_from_slice(&fmt[pos..]);
                break;
            }
            Some(i) => {
                out.extend_from_slice(&fmt[pos..pos + i]);
                pos += i;
            }
        }
        pos += 1;
        if at(pos) == b'%' {
            out.push(b'%');
            pos += 1;
            continue;
        }
        let mut align = Align::Right;
        let mut adjusting = 0u8;
        let mut padding = b' ';
        let mut always_sign = false;
        let mut expprec = false;
        let mut width: i64 = 0;
        let mut precision: i64 = 0;
        let argnum;
        if at(pos).is_ascii_alphabetic() {
            argnum = None;
        } else {
            argnum = get_argnum(fmt, &mut pos)?;
            loop {
                match at(pos) {
                    b' ' | b'0' => padding = at(pos),
                    b'-' => align = Align::Left,
                    b'+' => always_sign = true,
                    b'\'' => {
                        if fmt.len().saturating_sub(pos) > 1 {
                            pos += 1;
                            padding = at(pos);
                        } else {
                            return Err(Error::Value("Missing padding character".into()));
                        }
                    }
                    _ => break,
                }
                pos += 1;
            }
            if at(pos) == b'*' {
                pos += 1;
                let n = match get_argnum(fmt, &mut pos)? {
                    Some(n) => n,
                    None => {
                        currarg += 1;
                        currarg - 1
                    }
                };
                if n >= argc {
                    missing(n, &mut max_missing);
                    continue;
                }
                match args[n] {
                    Arg::Int(v) if (0..=INT_MAX).contains(&v) => width = v,
                    Arg::Int(_) => return Err(Error::Value(format!("Width must be between 0 and {INT_MAX}"))),
                    _ => return Err(Error::Value("Width must be an integer".into())),
                }
                adjusting |= ADJ_WIDTH;
            } else if at(pos).is_ascii_digit() {
                width = get_number(fmt, &mut pos);
                if width < 0 {
                    return Err(Error::Value(format!("Width must be between 0 and {INT_MAX}")));
                }
                adjusting |= ADJ_WIDTH;
            }
            if at(pos) == b'.' {
                pos += 1;
                if at(pos) == b'*' {
                    pos += 1;
                    let n = match get_argnum(fmt, &mut pos)? {
                        Some(n) => n,
                        None => {
                            currarg += 1;
                            currarg - 1
                        }
                    };
                    if n >= argc {
                        missing(n, &mut max_missing);
                        continue;
                    }
                    match args[n] {
                        Arg::Int(v) if (-1..=INT_MAX).contains(&v) => precision = v,
                        Arg::Int(_) => {
                            return Err(Error::Value(format!("Precision must be between -1 and {INT_MAX}")));
                        }
                        _ => return Err(Error::Value("Precision must be an integer".into())),
                    }
                    adjusting |= ADJ_PRECISION;
                    expprec = true;
                } else if at(pos).is_ascii_digit() {
                    precision = get_number(fmt, &mut pos);
                    if precision < 0 {
                        return Err(Error::Value(format!("Precision must be between 0 and {INT_MAX}")));
                    }
                    adjusting |= ADJ_PRECISION;
                    expprec = true;
                } else {
                    adjusting |= ADJ_PRECISION;
                }
            }
        }
        if at(pos) == b'l' {
            pos += 1;
        }
        let argnum = match argnum {
            Some(n) => n,
            None => {
                currarg += 1;
                currarg - 1
            }
        };
        if argnum >= argc {
            missing(argnum, &mut max_missing);
            continue;
        }
        let spec = at(pos);
        if expprec && precision == -1 && !matches!(spec, b'g' | b'G' | b'h' | b'H') {
            return Err(Error::Value("Precision -1 is only supported for %g, %G, %h and %H".into()));
        }
        let arg = &args[argnum];
        let width = width as usize;
        match spec {
            b's' => {
                // A failed conversion leaves PHP's Error pending and formats
                // "" instead; a later error supersedes it.
                let s = arg.to_bytes().unwrap_or_else(|e| {
                    pending = Some(e);
                    Cow::Borrowed(b"")
                });
                append_string(&mut out, &s, width, precision.max(0) as usize, padding, align, false, expprec, false);
            }
            b'd' => append_int(&mut out, arg.to_int(), width, padding, align, always_sign),
            b'u' => append_uint(&mut out, arg.to_int() as u64, width, padding, align),
            b'e' | b'E' | b'f' | b'F' | b'g' | b'G' | b'h' | b'H' => {
                append_double(&mut out, arg.to_float(), width, padding, align, precision, adjusting, spec, always_sign)
            }
            b'c' => out.push(arg.to_int() as u8),
            b'o' => append_2n(&mut out, arg.to_int(), width, padding, align, 3, false, expprec),
            b'x' => append_2n(&mut out, arg.to_int(), width, padding, align, 4, false, expprec),
            b'X' => append_2n(&mut out, arg.to_int(), width, padding, align, 4, true, expprec),
            b'b' => append_2n(&mut out, arg.to_int(), width, padding, align, 1, false, expprec),
            b'%' => out.push(b'%'),
            0 if pos >= fmt.len() => return Err(Error::Value("Missing format specifier at end of string".into())),
            c => {
                return Err(Error::Value(format!("Unknown format specifier \"{}\"", String::from_utf8_lossy(&[c]))));
            }
        }
        pos += 1;
    }
    if let Some(m) = max_missing {
        return Err(if additional == -1 {
            Error::Value(format!("The arguments array must contain {} items, {} given", m + 1, argc))
        } else {
            Error::ArgumentCount(format!(
                "{} arguments are required, {} given",
                m as i64 + additional + 1,
                argc as i64 + additional
            ))
        });
    }
    match pending {
        Some(e) => Err(e),
        None => Ok(out),
    }
}

// ---------------------------------------------------------------------------
// Rounding
// ---------------------------------------------------------------------------

/// `round()`'s `$mode` (`RoundingMode` cases and `PHP_ROUND_*` constants).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum RoundingMode {
    /// `PHP_ROUND_HALF_UP` (1), `RoundingMode::HalfAwayFromZero`: the default.
    #[default]
    HalfAwayFromZero,
    /// `PHP_ROUND_HALF_DOWN` (2), `RoundingMode::HalfTowardsZero`.
    HalfTowardsZero,
    /// `PHP_ROUND_HALF_EVEN` (3), `RoundingMode::HalfEven`.
    HalfEven,
    /// `PHP_ROUND_HALF_ODD` (4), `RoundingMode::HalfOdd`.
    HalfOdd,
    /// `RoundingMode::PositiveInfinity` (5).
    PositiveInfinity,
    /// `RoundingMode::NegativeInfinity` (6).
    NegativeInfinity,
    /// `RoundingMode::TowardsZero` (7).
    TowardsZero,
    /// `RoundingMode::AwayFromZero` (8).
    AwayFromZero,
}

impl RoundingMode {
    /// The mode for PHP's integer value (`PHP_ROUND_*`, 1 to 8); `None`
    /// for anything else (PHP's `ValueError`).
    pub fn from_php(mode: i64) -> Option<Self> {
        Some(match mode {
            1 => Self::HalfAwayFromZero,
            2 => Self::HalfTowardsZero,
            3 => Self::HalfEven,
            4 => Self::HalfOdd,
            5 => Self::PositiveInfinity,
            6 => Self::NegativeInfinity,
            7 => Self::TowardsZero,
            8 => Self::AwayFromZero,
            _ => return None,
        })
    }
}

/// `php_intpow10`: 10^power, exact for 0..=22 and C `pow(10, power)` above.
///
/// Quirk: PHP's image links musl, whose `pow(10, n)` is one ULP above the
/// correctly rounded power for n = 23, 126 and 210 (checked for every n up
/// to 330 against the PHP image); those three are pinned here.
fn intpow10(power: i32) -> f64 {
    match power {
        23 => f64::from_bits(0x44b5_2d02_c7e1_4af7),
        126 => f64::from_bits(0x5a17_a2ec_c414_a040),
        210 => f64::from_bits(0x6b88_557f_3132_6bbc),
        _ => format!("1e{power}").parse().unwrap_or(f64::INFINITY),
    }
}

fn round_helper(integral: f64, value: f64, exponent: f64, places: i32, mode: RoundingMode) -> f64 {
    let value_abs = value.abs();
    let basic_edge = || {
        let v = integral + 0.5f64.copysign(integral);
        (if places > 0 { v / exponent } else { v * exponent }).abs()
    };
    let zero_edge = || (if places > 0 { integral / exponent } else { integral * exponent }).abs();
    let away = integral + 1.0f64.copysign(integral);
    match mode {
        RoundingMode::HalfAwayFromZero => {
            if value_abs >= basic_edge() {
                away
            } else {
                integral
            }
        }
        RoundingMode::HalfTowardsZero => {
            if value_abs > basic_edge() {
                away
            } else {
                integral
            }
        }
        RoundingMode::PositiveInfinity => {
            if value > 0.0 && value_abs > zero_edge() {
                integral + 1.0
            } else {
                integral
            }
        }
        RoundingMode::NegativeInfinity => {
            if value < 0.0 && value_abs > zero_edge() {
                integral - 1.0
            } else {
                integral
            }
        }
        RoundingMode::TowardsZero => integral,
        RoundingMode::AwayFromZero => {
            if value_abs > zero_edge() {
                away
            } else {
                integral
            }
        }
        RoundingMode::HalfEven | RoundingMode::HalfOdd => {
            let edge = basic_edge();
            if value_abs > edge {
                away
            } else if value_abs == edge {
                let even = integral % 2.0 == 0.0;
                if even == (mode == RoundingMode::HalfOdd) { away } else { integral }
            } else {
                integral
            }
        }
    }
}

/// `_php_math_round` (PHP 8.5.10): `value` rounded to `places` decimal
/// places, with PHP's pre-rounding correction for values like 0.285.
fn math_round(value: f64, places: i32, mode: RoundingMode) -> f64 {
    if !value.is_finite() || value == 0.0 {
        return value;
    }
    let places = places.max(i32::MIN + 1);
    let exponent = intpow10(places.abs());
    let scaled = if places > 0 { value * exponent } else { value / exponent };
    let (mut tmp, tmp2) = if value >= 0.0 {
        let t = scaled.floor();
        (t, t + 1.0)
    } else {
        let t = scaled.ceil();
        (t, t - 1.0)
    };
    if (if places > 0 { tmp2 / exponent } else { tmp2 * exponent }) == value {
        tmp = tmp2;
    }
    if tmp.abs() >= 1e16 {
        return value;
    }
    tmp = round_helper(tmp, value, exponent, places, mode);
    if places.abs() < 23 {
        if places > 0 { tmp / exponent } else { tmp * exponent }
    } else {
        // PHP prints "%15fe%d" and parses it back with zend_strtod.
        let sign = if tmp < 0.0 { "-" } else { "" };
        let text = format!("{sign}{:.6}e{}", tmp.abs(), -(places as i64));
        let r = parse_decimal(text.as_bytes());
        if r.is_finite() { r } else { value }
    }
}

/// Clamps a PHP `int` precision to a C `int` as `round` and `number_format` do.
fn clamp_places(precision: i64) -> i32 {
    precision.clamp(i32::MIN as i64, i32::MAX as i64) as i32
}

/// PHP `round($num, $precision, $mode)`: always a float. Integers with a
/// non-negative precision are returned as they are.
pub fn round(num: Number, precision: i64, mode: RoundingMode) -> f64 {
    let places = clamp_places(precision);
    match num {
        Number::Int(i) if places >= 0 => i as f64,
        n => math_round(n.as_f64(), places, mode),
    }
}

/// PHP `floor($num)`: always a float.
pub fn floor(num: Number) -> f64 {
    match num {
        Number::Int(i) => i as f64,
        Number::Float(f) => f.floor(),
    }
}

/// PHP `ceil($num)`: always a float.
pub fn ceil(num: Number) -> f64 {
    match num {
        Number::Int(i) => i as f64,
        Number::Float(f) => f.ceil(),
    }
}

/// PHP `abs($num)`: `abs(PHP_INT_MIN)` is a float.
pub fn abs(num: Number) -> Number {
    match num {
        Number::Int(i64::MIN) => Number::Float(9223372036854775808.0),
        Number::Int(i) => Number::Int(i.abs()),
        Number::Float(f) => Number::Float(f.abs()),
    }
}

/// PHP `fmod($num1, $num2)`: C `fmod` (the sign of the dividend).
pub fn fmod(num1: f64, num2: f64) -> f64 {
    num1 % num2
}

/// PHP `fdiv($num1, $num2)`: IEEE division (by zero gives ±INF or NAN).
pub fn fdiv(num1: f64, num2: f64) -> f64 {
    num1 / num2
}

/// PHP `intdiv($num1, $num2)`: truncating integer division.
pub fn intdiv(num1: i64, num2: i64) -> Result<i64, Error> {
    if num2 == 0 {
        return Err(Error::DivisionByZero("Division by zero".into()));
    }
    if num2 == -1 && num1 == i64::MIN {
        return Err(Error::Arithmetic("Division of PHP_INT_MIN by -1 is not an integer".into()));
    }
    Ok(num1 / num2)
}

// ---------------------------------------------------------------------------
// number_format
// ---------------------------------------------------------------------------

/// Inserts `sep` every three digits from the right of `digits`.
fn group(digits: &[u8], sep: &[u8], out: &mut Vec<u8>) {
    for (i, &d) in digits.iter().enumerate() {
        if i > 0 && (digits.len() - i).is_multiple_of(3) {
            out.extend_from_slice(sep);
        }
        out.push(d);
    }
}

/// `_php_math_number_format_long`.
fn number_format_long(num: i64, dec: i64, dec_point: &[u8], thousands_sep: &[u8]) -> Vec<u8> {
    const POWERS: [u64; 20] = [
        1,
        10,
        100,
        1_000,
        10_000,
        100_000,
        1_000_000,
        10_000_000,
        100_000_000,
        1_000_000_000,
        10_000_000_000,
        100_000_000_000,
        1_000_000_000_000,
        10_000_000_000_000,
        100_000_000_000_000,
        1_000_000_000_000_000,
        10_000_000_000_000_000,
        100_000_000_000_000_000,
        1_000_000_000_000_000_000,
        10_000_000_000_000_000_000,
    ];
    let mut negative = num < 0;
    let mut n = num.unsigned_abs();
    if dec < 0 {
        if dec < -19 {
            n = 0;
        } else {
            let power = POWERS[dec.unsigned_abs() as usize];
            let rest = n % power;
            n /= power;
            n = if rest >= power / 2 { n * power + power } else { n * power };
        }
        if n == 0 {
            negative = false;
        }
    }
    let mut out = Vec::new();
    if negative {
        out.push(b'-');
    }
    group(n.to_string().as_bytes(), thousands_sep, &mut out);
    if dec > 0 {
        out.extend_from_slice(dec_point);
        out.extend(std::iter::repeat_n(b'0', dec as usize));
    }
    out
}

/// PHP `number_format($num, $decimals, $decimal_separator, $thousands_separator)`:
/// rounds half away from zero (with [`round`]'s pre-rounding), groups the
/// integer digits by three and never prints `-0`. Infinite floats print
/// `inf` and NaN `nan`, with no sign. Pass `"."` and `","` for PHP's
/// defaults.
pub fn number_format(num: Number, decimals: i64, decimal_separator: &[u8], thousands_separator: &[u8]) -> Vec<u8> {
    let d = match num {
        Number::Int(i) => return number_format_long(i, decimals, decimal_separator, thousands_separator),
        Number::Float(d) => d,
    };
    if (d >= 4503599627370496.0 || d <= -4503599627370496.0) && fits_long(d) {
        return number_format_long(d as i64, decimals, decimal_separator, thousands_separator);
    }
    let dec = clamp_places(decimals);
    let mut negative = d < 0.0;
    let d = math_round(d.abs(), dec, RoundingMode::HalfAwayFromZero);
    let dec = dec.max(0) as usize;
    if d.is_nan() {
        return b"nan".to_vec();
    }
    if d.is_infinite() {
        return b"inf".to_vec();
    }
    // spprintf's "%.*F": precision capped at 500, then php_conv_fp's 318.
    let text = fixed_digits(d, dec.min(500).min(318));
    if negative && d == 0.0 {
        negative = false;
    }
    let (int_part, frac) = match text.split_once('.') {
        Some((i, f)) if dec > 0 => (i, f),
        _ => (text.as_str(), ""),
    };
    let mut out = Vec::with_capacity(text.len() + text.len() / 3 + 2);
    if negative {
        out.push(b'-');
    }
    group(int_part.as_bytes(), thousands_separator, &mut out);
    if dec > 0 {
        out.extend_from_slice(decimal_separator);
        out.extend_from_slice(frac.as_bytes());
        out.extend(std::iter::repeat_n(b'0', dec - frac.len()));
    }
    out
}

// ---------------------------------------------------------------------------
// max / min
// ---------------------------------------------------------------------------

/// `zend_compare` of two numbers (`ZEND_THREEWAY_COMPARE`: a NaN compares
/// as greater either way).
fn compare(a: Number, b: Number) -> i32 {
    match (a, b) {
        (Number::Int(x), Number::Int(y)) => x.cmp(&y) as i32,
        _ => {
            let (x, y) = (a.as_f64(), b.as_f64());
            if x == y {
                0
            } else if x < y {
                -1
            } else {
                1
            }
        }
    }
}

/// Whether an integer survives a round trip through a float.
fn exact_as_float(i: i64) -> bool {
    dval_to_lval(i as f64) == i
}

fn minmax(values: &[Number], max: bool) -> Option<Number> {
    let better = |x: f64, y: f64| if max { x < y } else { x > y };
    let first = *values.first()?;
    let mut best = first;
    let mut i = 1;
    match first {
        Number::Int(mut l) => {
            while i < values.len() {
                match values[i] {
                    Number::Int(v) => {
                        if if max { l < v } else { l > v } {
                            l = v;
                            best = values[i];
                        }
                    }
                    Number::Float(_) if exact_as_float(l) => {
                        return Some(float_minmax(values, i, l as f64, best, max));
                    }
                    _ => return Some(generic_minmax(values, i, best, max)),
                }
                i += 1;
            }
            Some(best)
        }
        Number::Float(d) => {
            let mut d = d;
            while i < values.len() {
                match values[i] {
                    Number::Float(v) => {
                        if better(d, v) {
                            d = v;
                            best = values[i];
                        }
                    }
                    Number::Int(v) if exact_as_float(v) => {
                        if better(d, v as f64) {
                            d = v as f64;
                            best = values[i];
                        }
                    }
                    _ => return Some(generic_minmax(values, i, best, max)),
                }
                i += 1;
            }
            Some(best)
        }
    }
}

fn float_minmax(values: &[Number], from: usize, mut d: f64, mut best: Number, max: bool) -> Number {
    let better = |x: f64, y: f64| if max { x < y } else { x > y };
    let mut i = from;
    while i < values.len() {
        match values[i] {
            Number::Float(v) => {
                if better(d, v) {
                    d = v;
                    best = values[i];
                }
            }
            Number::Int(v) if exact_as_float(v) => {
                if better(d, v as f64) {
                    d = v as f64;
                    best = values[i];
                }
            }
            _ => return generic_minmax(values, i, best, max),
        }
        i += 1;
    }
    best
}

fn generic_minmax(values: &[Number], from: usize, mut best: Number, max: bool) -> Number {
    for &v in &values[from..] {
        let c = compare(v, best);
        if (max && c > 0) || (!max && c < 0) {
            best = v;
        }
    }
    best
}

/// PHP `max($value, ...$values)` for numbers (at least two): the first
/// largest value, keeping its type (`max(1, 1.0)` is `1`). `None` when
/// `values` is empty.
pub fn max(values: &[Number]) -> Option<Number> {
    minmax(values, true)
}

/// PHP `min($value, ...$values)` for numbers (at least two): the first
/// smallest value, keeping its type.
pub fn min(values: &[Number]) -> Option<Number> {
    minmax(values, false)
}

fn empty_array(function: &str) -> Error {
    Error::argument(function, 1, "value", "must contain at least one element")
}

/// PHP `max($array)` for an array of numbers (`zend_hash_minmax`, which
/// compares differently from the variadic form when a NaN is involved).
pub fn max_array(values: &[Number]) -> Result<Number, Error> {
    let mut best = *values.first().ok_or_else(|| empty_array("max"))?;
    for &v in &values[1..] {
        if compare(best, v) < 0 {
            best = v;
        }
    }
    Ok(best)
}

/// PHP `min($array)` for an array of numbers.
pub fn min_array(values: &[Number]) -> Result<Number, Error> {
    let mut best = *values.first().ok_or_else(|| empty_array("min"))?;
    for &v in &values[1..] {
        if compare(best, v) > 0 {
            best = v;
        }
    }
    Ok(best)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn s(f: &str, a: &[Arg]) -> String {
        String::from_utf8(sprintf(f.as_bytes(), a).unwrap()).unwrap()
    }

    #[test]
    fn sprintf_like_php() {
        assert_eq!(
            s("%05.1f|%-6s|%u", &[Arg::Float(3.04159), Arg::Str(b"ab"), Arg::Int(-1)]),
            "003.0|ab    |18446744073709551615"
        );
        assert_eq!(s("%e", &[Arg::Float(1234.5)]), "1.234500e+3");
        assert_eq!(s("%+05f", &[Arg::Float(f64::NAN)]), "+aN");
        assert_eq!(s("%'*8.3e", &[Arg::Float(1234.5)]), "1.234e+3");
        assert_eq!(s("%.10g", &[Arg::Float(0.1)]), "0.1");
        assert_eq!(s("%2$s %1$s", &[Arg::Str(b"a"), Arg::Str(b"b")]), "b a");
        assert!(matches!(sprintf(b"%d", &[]), Err(Error::ArgumentCount(_))));
    }

    #[test]
    fn number_format_like_php() {
        assert_eq!(number_format(Number::Float(1234.5678), 2, b",", b"."), b"1.234,57");
        assert_eq!(number_format(Number::Float(-0.4), 0, b".", b","), b"0");
        assert_eq!(number_format(Number::Int(1234567), 0, b".", b","), b"1,234,567");
    }

    #[test]
    fn casts_like_php() {
        assert_eq!(str_to_int(b"12abc"), 12);
        assert_eq!(str_to_int(b" 1e3"), 1000);
        assert_eq!(str_to_int(b"0x1A"), 0);
        assert_eq!(str_to_int(b"9223372036854775808e+"), i64::MIN);
        assert_eq!(str_to_int(b"1e1000"), 0);
        assert_eq!(str_to_float(b"-0").to_bits(), (-0.0f64).to_bits());
        assert_eq!(intval(Arg::Str(b"0x1A"), 16), 26);
        assert_eq!(intval(Arg::Str(b"0b11"), 0), 3);
        assert_eq!(intval(Arg::Str(b"012"), 0), 10);
    }

    #[test]
    fn rounds_like_php() {
        assert_eq!(round(Number::Float(0.285), 2, RoundingMode::HalfAwayFromZero), 0.29);
        assert_eq!(round(Number::Float(2.5), 0, RoundingMode::HalfEven), 2.0);
        assert_eq!(round(Number::Int(1234), -2, RoundingMode::HalfAwayFromZero), 1200.0);
    }
}
