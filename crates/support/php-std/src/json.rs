//! `json_encode`, `json_decode`, `json_validate`, `json_last_error` and
//! `json_last_error_msg`, as PHP 8.5's `ext/json` implements them.
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `json.*`).
//!
//! | PHP | Rust |
//! |---|---|
//! | `json_encode($v, $flags, $depth)` | [`encode`] (any [`PhpValue`]: [`Zval`] or `serde_json::Value`) |
//! | `json_decode($s, $assoc, $depth, $flags)` | [`decode`] ([`Zval`]), [`decode_value`] (`serde_json::Value`, `$assoc = true`) |
//! | `json_validate($s, $depth, $flags)` | [`validate`] |
//! | `json_last_error()`, `json_last_error_msg()` | [`ErrorCode::code`], [`ErrorCode::message`] of the returned error |
//! | `JSON_*` constants | [`Flags`] |
//! | `JsonException` / `ValueError` | [`Error`] ([`Error::php_class`]) |
//!
//! There is no global "last error": each call returns its error. PHP's
//! `json_last_error()` after a call without `JSON_THROW_ON_ERROR` is the
//! code of the returned error (`0` on success).
//!
//! Faithful details: floats print with `serialize_precision = -1`
//! ([`crate::number::serialize`]); strings are validated as UTF-8 exactly
//! like PHP (`php_next_utf8_char` when encoding, the scanner's byte ranges
//! when decoding, so `JSON_INVALID_UTF8_SUBSTITUTE` replaces the same byte
//! runs); errors follow PHP's precedence (the first error in input order when
//! decoding, the last one recorded when encoding); the decoder reports the
//! same error as PHP's bison parser when nesting exhausts its 10000-entry
//! stack, and never recurses.
//!
//! Not modelled: objects other than `stdClass` (`JsonSerializable`, enums,
//! public properties of classes), recursion (a [`Zval`] cannot contain
//! itself) and the engine's stack-size limit when encoding values nested
//! thousands of levels deep.

use std::fmt;
use std::ops::{BitAnd, BitOr, BitOrAssign};

use serde_json::{Map, Number, Value};

use crate::number;
use crate::value;
use crate::zval::{Array, Key, KeyRef, Object, PhpValue, View, Zval, numeric_key};

/// `JSON_*` option bits for `json_encode`, `json_decode` and `json_validate`.
///
/// PHP passes options as `int`; [`Flags::from_php`] keeps the low 32 bits
/// like the engine's `(int)` cast. Unknown bits are ignored.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default, Hash)]
pub struct Flags(u32);

impl Flags {
    pub const NONE: Flags = Flags(0);
    /// `JSON_OBJECT_AS_ARRAY` (decode): objects become arrays.
    pub const OBJECT_AS_ARRAY: Flags = Flags(1 << 0);
    /// `JSON_BIGINT_AS_STRING` (decode): integers beyond `i64` stay strings.
    pub const BIGINT_AS_STRING: Flags = Flags(1 << 1);
    /// `JSON_HEX_TAG`: `<` and `>` as `<`, `>`.
    pub const HEX_TAG: Flags = Flags(1 << 0);
    /// `JSON_HEX_AMP`: `&` as `&`.
    pub const HEX_AMP: Flags = Flags(1 << 1);
    /// `JSON_HEX_APOS`: `'` as `'`.
    pub const HEX_APOS: Flags = Flags(1 << 2);
    /// `JSON_HEX_QUOT`: `"` as `"`.
    pub const HEX_QUOT: Flags = Flags(1 << 3);
    /// `JSON_FORCE_OBJECT`: lists as objects.
    pub const FORCE_OBJECT: Flags = Flags(1 << 4);
    /// `JSON_NUMERIC_CHECK`: numeric strings as numbers.
    pub const NUMERIC_CHECK: Flags = Flags(1 << 5);
    /// `JSON_UNESCAPED_SLASHES`: `/` stays `/`.
    pub const UNESCAPED_SLASHES: Flags = Flags(1 << 6);
    /// `JSON_PRETTY_PRINT`: newlines and four-space indentation.
    pub const PRETTY_PRINT: Flags = Flags(1 << 7);
    /// `JSON_UNESCAPED_UNICODE`: non-ASCII characters stay raw (except U+2028/U+2029).
    pub const UNESCAPED_UNICODE: Flags = Flags(1 << 8);
    /// `JSON_PARTIAL_OUTPUT_ON_ERROR`: replace what cannot be encoded and keep going.
    pub const PARTIAL_OUTPUT_ON_ERROR: Flags = Flags(1 << 9);
    /// `JSON_PRESERVE_ZERO_FRACTION`: `1.0` stays `1.0`.
    pub const PRESERVE_ZERO_FRACTION: Flags = Flags(1 << 10);
    /// `JSON_UNESCAPED_LINE_TERMINATORS`: with `UNESCAPED_UNICODE`, U+2028/U+2029 stay raw.
    pub const UNESCAPED_LINE_TERMINATORS: Flags = Flags(1 << 11);
    /// `JSON_INVALID_UTF8_IGNORE`: drop invalid UTF-8.
    pub const INVALID_UTF8_IGNORE: Flags = Flags(1 << 20);
    /// `JSON_INVALID_UTF8_SUBSTITUTE`: replace invalid UTF-8 with U+FFFD.
    pub const INVALID_UTF8_SUBSTITUTE: Flags = Flags(1 << 21);
    /// `JSON_THROW_ON_ERROR`: PHP throws `JsonException` instead of setting the last error.
    pub const THROW_ON_ERROR: Flags = Flags(1 << 22);

    /// Options as PHP code passes them (an `int`, cast like the engine's `(int)`).
    pub const fn from_php(options: i64) -> Flags {
        Flags(options as u32)
    }

    pub const fn bits(self) -> u32 {
        self.0
    }

    /// Whether every bit of `other` is set.
    pub const fn contains(self, other: Flags) -> bool {
        self.0 & other.0 == other.0
    }

    /// Whether any bit of `other` is set.
    pub const fn intersects(self, other: Flags) -> bool {
        self.0 & other.0 != 0
    }

    const fn without(self, other: Flags) -> Flags {
        Flags(self.0 & !other.0)
    }
}

impl BitOr for Flags {
    type Output = Flags;

    fn bitor(self, rhs: Flags) -> Flags {
        Flags(self.0 | rhs.0)
    }
}

impl BitOrAssign for Flags {
    fn bitor_assign(&mut self, rhs: Flags) {
        self.0 |= rhs.0;
    }
}

impl BitAnd for Flags {
    type Output = Flags;

    fn bitand(self, rhs: Flags) -> Flags {
        Flags(self.0 & rhs.0)
    }
}

/// `JSON_ERROR_*`: what `json_last_error()` returns.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum ErrorCode {
    None = 0,
    Depth = 1,
    StateMismatch = 2,
    CtrlChar = 3,
    Syntax = 4,
    Utf8 = 5,
    Recursion = 6,
    InfOrNan = 7,
    UnsupportedType = 8,
    InvalidPropertyName = 9,
    Utf16 = 10,
    NonBackedEnum = 11,
}

impl ErrorCode {
    /// `json_last_error()`.
    pub fn code(self) -> i64 {
        self as i64
    }

    /// `json_last_error_msg()`.
    pub fn message(self) -> &'static str {
        match self {
            ErrorCode::None => "No error",
            ErrorCode::Depth => "Maximum stack depth exceeded",
            ErrorCode::StateMismatch => "State mismatch (invalid or malformed JSON)",
            ErrorCode::CtrlChar => "Control character error, possibly incorrectly encoded",
            ErrorCode::Syntax => "Syntax error",
            ErrorCode::Utf8 => "Malformed UTF-8 characters, possibly incorrectly encoded",
            ErrorCode::Recursion => "Recursion detected",
            ErrorCode::InfOrNan => "Inf and NaN cannot be JSON encoded",
            ErrorCode::UnsupportedType => "Type is not supported",
            ErrorCode::InvalidPropertyName => "The decoded property name is invalid",
            ErrorCode::Utf16 => "Single unpaired UTF-16 surrogate in unicode escape",
            ErrorCode::NonBackedEnum => "Non-backed enums have no default serialization",
        }
    }
}

/// Why a JSON function failed.
#[derive(Debug, Clone, PartialEq)]
pub enum Error {
    /// The value could not be encoded or the input decoded. Without
    /// `JSON_THROW_ON_ERROR` PHP returns `false`/`null` and
    /// `json_last_error()` is this code; with it PHP throws `JsonException`
    /// (message [`ErrorCode::message`], code [`ErrorCode::code`]).
    Json(ErrorCode),
    /// `json_encode` with `JSON_PARTIAL_OUTPUT_ON_ERROR` after an error: PHP
    /// returns `json` (with `null`, `0` or `""` where values failed) and sets
    /// `json_last_error()` to `code`. It never throws, even with
    /// `JSON_THROW_ON_ERROR`.
    Partial { json: String, code: ErrorCode },
    /// An invalid argument: PHP throws `ValueError` with this message.
    Value(String),
    /// [`decode_value`] only: PHP decoded a number `serde_json::Value`
    /// cannot hold (`1e999` is `INF` in PHP). Use [`decode`] for those.
    Unrepresentable,
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Json(_) | Error::Partial { .. } => "JsonException",
            Error::Value(_) => "ValueError",
            Error::Unrepresentable => "JsonException",
        }
    }

    /// `json_last_error()` after the call that failed (`0` for a `ValueError`,
    /// which PHP throws after resetting the last error).
    pub fn code(&self) -> ErrorCode {
        match self {
            Error::Json(code) | Error::Partial { code, .. } => *code,
            Error::Value(_) | Error::Unrepresentable => ErrorCode::None,
        }
    }

    /// The exception message.
    pub fn message(&self) -> &str {
        match self {
            Error::Json(code) | Error::Partial { code, .. } => code.message(),
            Error::Value(message) => message,
            Error::Unrepresentable => "The decoded value cannot be represented as serde_json::Value",
        }
    }
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self.message())
    }
}

impl std::error::Error for Error {}

/// `PHP_JSON_PARSER_DEFAULT_DEPTH`: the default `$depth` of every function.
pub const DEFAULT_DEPTH: i64 = 512;

// ---------------------------------------------------------------------------
// Encoding (ext/json/json_encoder.c)
// ---------------------------------------------------------------------------

/// `json_encode($value, $flags, $depth)`.
///
/// Returns the JSON text, or the error PHP reports: `Err(Error::Json)` where
/// PHP returns `false` (or throws with `JSON_THROW_ON_ERROR`), and
/// `Err(Error::Partial)` carrying the output PHP returns when
/// `JSON_PARTIAL_OUTPUT_ON_ERROR` is set. `depth` is cast to a C `int` like
/// PHP does; it is not validated (a depth below 1 fails on any array).
pub fn encode<V: PhpValue>(value: &V, flags: Flags, depth: i64) -> Result<String, Error> {
    let mut encoder = Encoder { out: Vec::new(), depth: 0, max_depth: depth as i32, error: ErrorCode::None };
    // The engine ignores the encoder's return value here: the error code decides.
    let _ = encoder.value(value, flags);
    let json = String::from_utf8(encoder.out).unwrap_or_else(|e| String::from_utf8_lossy(e.as_bytes()).into_owned());
    match encoder.error {
        ErrorCode::None => Ok(json),
        code if flags.contains(Flags::PARTIAL_OUTPUT_ON_ERROR) => Err(Error::Partial { json, code }),
        code => Err(Error::Json(code)),
    }
}

struct Encoder {
    out: Vec<u8>,
    depth: i32,
    max_depth: i32,
    error: ErrorCode,
}

/// The engine's `FAILURE` while encoding: the error code is in the encoder.
struct Failed;

const HEX: &[u8; 16] = b"0123456789abcdef";

/// Bytes `php_json_escape_string` cannot copy verbatim: controls, `"`, `&`,
/// `'`, `/`, `<`, `>`, `\` and everything from 0x80.
const SPECIAL: [u32; 8] =
    [0xffffffff, 0x500080c4, 0x10000000, 0x00000000, 0xffffffff, 0xffffffff, 0xffffffff, 0xffffffff];

fn special(b: u8) -> bool {
    SPECIAL[(b >> 5) as usize] & (1 << (b & 31)) != 0
}

impl Encoder {
    fn pretty_char(&mut self, flags: Flags, c: u8) {
        if flags.contains(Flags::PRETTY_PRINT) {
            self.out.push(c);
        }
    }

    fn pretty_indent(&mut self, flags: Flags) {
        if flags.contains(Flags::PRETTY_PRINT) {
            for _ in 0..self.depth {
                self.out.extend_from_slice(b"    ");
            }
        }
    }

    /// `php_json_encode_zval`.
    fn value<V: PhpValue>(&mut self, value: &V, flags: Flags) -> Result<(), Failed> {
        match value.view() {
            View::Null => self.out.extend_from_slice(b"null"),
            View::Bool(true) => self.out.extend_from_slice(b"true"),
            View::Bool(false) => self.out.extend_from_slice(b"false"),
            View::Int(i) => self.out.extend_from_slice(i.to_string().as_bytes()),
            View::Float(f) => {
                if f.is_finite() {
                    self.double(f, flags);
                } else {
                    self.error = ErrorCode::InfOrNan;
                    self.out.push(b'0');
                }
            }
            View::Str(s) => return self.string(s, flags),
            View::Array(entries) => {
                let as_object = flags.contains(Flags::FORCE_OBJECT) || !value.is_list();
                return self.container::<V>(entries, as_object, false, flags);
            }
            View::Object(entries) => return self.container::<V>(entries, true, true, flags),
        }
        Ok(())
    }

    /// `php_json_encode_double`.
    fn double(&mut self, d: f64, flags: Flags) {
        let num = number::serialize(d);
        self.out.extend_from_slice(num.as_bytes());
        if flags.contains(Flags::PRESERVE_ZERO_FRACTION) && !num.contains('.') {
            self.out.extend_from_slice(b".0");
        }
    }

    /// `php_json_encode_array` for arrays and `stdClass` objects.
    fn container<'a, V: PhpValue + 'a>(
        &mut self,
        entries: V::Entries<'a>,
        as_object: bool,
        is_object: bool,
        flags: Flags,
    ) -> Result<(), Failed> {
        let mut need_comma = false;
        self.out.push(if as_object { b'{' } else { b'[' });
        self.depth += 1;
        for (key, data) in entries {
            if !as_object {
                if need_comma {
                    self.out.push(b',');
                } else {
                    need_comma = true;
                }
                self.pretty_char(flags, b'\n');
                self.pretty_indent(flags);
            } else {
                match key {
                    KeyRef::Str(k) => {
                        if is_object && k.first() == Some(&0) {
                            // Skip protected and private members.
                            continue;
                        }
                        if need_comma {
                            self.out.push(b',');
                        } else {
                            need_comma = true;
                        }
                        self.pretty_char(flags, b'\n');
                        self.pretty_indent(flags);
                        if self.string(k, flags.without(Flags::NUMERIC_CHECK)).is_err()
                            && flags.contains(Flags::PARTIAL_OUTPUT_ON_ERROR)
                        {
                            self.out.truncate(self.out.len() - 4);
                            self.out.extend_from_slice(b"\"\"");
                        }
                    }
                    KeyRef::Int(index) => {
                        if need_comma {
                            self.out.push(b',');
                        } else {
                            need_comma = true;
                        }
                        self.pretty_char(flags, b'\n');
                        self.pretty_indent(flags);
                        self.out.push(b'"');
                        self.out.extend_from_slice(index.to_string().as_bytes());
                        self.out.push(b'"');
                    }
                }
                self.out.push(b':');
                self.pretty_char(flags, b' ');
            }
            if self.value(data, flags).is_err() && !flags.contains(Flags::PARTIAL_OUTPUT_ON_ERROR) {
                return Err(Failed);
            }
        }
        if self.depth > self.max_depth {
            self.error = ErrorCode::Depth;
            if !flags.contains(Flags::PARTIAL_OUTPUT_ON_ERROR) {
                return Err(Failed);
            }
        }
        self.depth -= 1;
        // Only keep the closing bracket on the same line for empty arrays/objects.
        if need_comma {
            self.pretty_char(flags, b'\n');
            self.pretty_indent(flags);
        }
        self.out.push(if as_object { b'}' } else { b']' });
        Ok(())
    }

    /// `php_json_escape_string`.
    fn string(&mut self, s: &[u8], flags: Flags) -> Result<(), Failed> {
        if s.is_empty() {
            self.out.extend_from_slice(b"\"\"");
            return Ok(());
        }
        if flags.contains(Flags::NUMERIC_CHECK)
            && let Some(n) = std::str::from_utf8(s).ok().and_then(value::numeric_str)
        {
            match n {
                value::Number::Int(i) => {
                    self.out.extend_from_slice(i.to_string().as_bytes());
                    return Ok(());
                }
                value::Number::Float(d) if d.is_finite() => {
                    self.double(d, flags);
                    return Ok(());
                }
                value::Number::Float(_) => {}
            }
        }
        let checkpoint = self.out.len();
        self.out.reserve(s.len() + 2);
        self.out.push(b'"');
        let mut pos = 0;
        while pos < s.len() {
            let start = pos;
            while pos < s.len() && !special(s[pos]) {
                pos += 1;
            }
            self.out.extend_from_slice(&s[start..pos]);
            if pos == s.len() {
                break;
            }
            let us = s[pos];
            if us >= 0x80 {
                match next_utf8_char(&s[pos..]) {
                    Err(advance) => {
                        if flags.contains(Flags::INVALID_UTF8_IGNORE) {
                            // Ignore the invalid sequence.
                        } else if flags.contains(Flags::INVALID_UTF8_SUBSTITUTE) {
                            if flags.contains(Flags::UNESCAPED_UNICODE) {
                                self.out.extend_from_slice("\u{fffd}".as_bytes());
                            } else {
                                self.out.extend_from_slice(b"\\ufffd");
                            }
                        } else {
                            self.out.truncate(checkpoint);
                            self.error = ErrorCode::Utf8;
                            if flags.contains(Flags::PARTIAL_OUTPUT_ON_ERROR) {
                                self.out.extend_from_slice(b"null");
                            }
                            return Err(Failed);
                        }
                        pos += advance;
                    }
                    Ok((c, len)) => {
                        if flags.contains(Flags::UNESCAPED_UNICODE)
                            && (flags.contains(Flags::UNESCAPED_LINE_TERMINATORS) || !(0x2028..=0x2029).contains(&c))
                        {
                            self.out.extend_from_slice(&s[pos..pos + len]);
                        } else if c >= 0x10000 {
                            let c = c - 0x10000;
                            self.unicode_escape(0xd800 | (c >> 10));
                            self.unicode_escape(0xdc00 | (c & 0x3ff));
                        } else {
                            self.unicode_escape(c);
                        }
                        pos += len;
                    }
                }
                continue;
            }
            pos += 1;
            match us {
                b'"' => self.out.extend_from_slice(if flags.contains(Flags::HEX_QUOT) { b"\\u0022" } else { b"\\\"" }),
                b'\\' => self.out.extend_from_slice(b"\\\\"),
                b'/' => {
                    self.out.extend_from_slice(if flags.contains(Flags::UNESCAPED_SLASHES) { b"/" } else { b"\\/" })
                }
                0x08 => self.out.extend_from_slice(b"\\b"),
                0x0c => self.out.extend_from_slice(b"\\f"),
                b'\n' => self.out.extend_from_slice(b"\\n"),
                b'\r' => self.out.extend_from_slice(b"\\r"),
                b'\t' => self.out.extend_from_slice(b"\\t"),
                b'<' => self.out.extend_from_slice(if flags.contains(Flags::HEX_TAG) { b"\\u003C" } else { b"<" }),
                b'>' => self.out.extend_from_slice(if flags.contains(Flags::HEX_TAG) { b"\\u003E" } else { b">" }),
                b'&' => self.out.extend_from_slice(if flags.contains(Flags::HEX_AMP) { b"\\u0026" } else { b"&" }),
                b'\'' => self.out.extend_from_slice(if flags.contains(Flags::HEX_APOS) { b"\\u0027" } else { b"'" }),
                c => self.unicode_escape(u32::from(c)),
            }
        }
        self.out.push(b'"');
        Ok(())
    }

    fn unicode_escape(&mut self, us: u32) {
        self.out.extend_from_slice(&[
            b'\\',
            b'u',
            HEX[((us >> 12) & 0xf) as usize],
            HEX[((us >> 8) & 0xf) as usize],
            HEX[((us >> 4) & 0xf) as usize],
            HEX[(us & 0xf) as usize],
        ]);
    }
}

/// `php_next_utf8_char`: the code point at the start of `s` and its length,
/// or the number of bytes PHP skips as one invalid sequence (UTR #36
/// strategy 2: an invalid sequence never swallows a byte that could start a
/// valid one).
pub fn next_utf8_char(s: &[u8]) -> Result<(u32, usize), usize> {
    let lead = |c: u8| c < 0x80 || (0xc2..=0xf4).contains(&c);
    let trail = |c: u8| (0x80..=0xbf).contains(&c);
    let avail = s.len();
    let c = *s.first().ok_or(1usize)?;
    if c < 0x80 {
        Ok((u32::from(c), 1))
    } else if c < 0xc2 {
        Err(1)
    } else if c < 0xe0 {
        if avail < 2 {
            return Err(1);
        }
        if !trail(s[1]) {
            return Err(if lead(s[1]) { 1 } else { 2 });
        }
        Ok(((u32::from(c) & 0x1f) << 6 | (u32::from(s[1]) & 0x3f), 2))
    } else if c < 0xf0 {
        if avail < 3 || !trail(s[1]) || !trail(s[2]) {
            return Err(if avail < 2 || lead(s[1]) {
                1
            } else if avail < 3 || lead(s[2]) {
                2
            } else {
                3
            });
        }
        let ch = (u32::from(c) & 0x0f) << 12 | (u32::from(s[1]) & 0x3f) << 6 | (u32::from(s[2]) & 0x3f);
        if ch < 0x800 || (0xd800..=0xdfff).contains(&ch) {
            return Err(3);
        }
        Ok((ch, 3))
    } else if c < 0xf5 {
        if avail < 4 || !trail(s[1]) || !trail(s[2]) || !trail(s[3]) {
            return Err(if avail < 2 || lead(s[1]) {
                1
            } else if avail < 3 || lead(s[2]) {
                2
            } else if avail < 4 || lead(s[3]) {
                3
            } else {
                4
            });
        }
        let ch = (u32::from(c) & 0x07) << 18
            | (u32::from(s[1]) & 0x3f) << 12
            | (u32::from(s[2]) & 0x3f) << 6
            | (u32::from(s[3]) & 0x3f);
        if !(0x10000..=0x10ffff).contains(&ch) {
            return Err(4);
        }
        Ok((ch, 4))
    } else {
        Err(1)
    }
}

// ---------------------------------------------------------------------------
// Decoding (ext/json/json_scanner.re, ext/json/json_parser.y)
// ---------------------------------------------------------------------------

/// `json_decode($json, $assoc, $depth, $flags)`.
///
/// `assoc` overrides `JSON_OBJECT_AS_ARRAY` in `flags` when it is not
/// `None`, as in PHP. Objects decode to [`Zval::Object`] (`stdClass`) or, in
/// array mode, to [`Zval::Array`] with PHP's key rules (`"7"` is key `7`).
/// Errors: [`Error::Json`] with PHP's code (empty input is a syntax error),
/// [`Error::Value`] when `depth` is not in `1..=i32::MAX`.
pub fn decode(json: &[u8], assoc: Option<bool>, depth: i64, flags: Flags) -> Result<Zval, Error> {
    let mut flags = flags;
    match assoc {
        Some(true) => flags |= Flags::OBJECT_AS_ARRAY,
        Some(false) => flags = flags.without(Flags::OBJECT_AS_ARRAY),
        None => {}
    }
    let depth = decode_depth(json, depth, "json_decode(): Argument #3 ($depth)")?;
    let builder = ZvalBuilder { assoc: flags.contains(Flags::OBJECT_AS_ARRAY) };
    parse(json, flags, depth, &builder).map_err(ParseError::into_error)
}

/// `json_decode($json, true, $depth, $flags)` into the request model
/// (`serde_json::Value`): PHP arrays that are lists become JSON arrays (so
/// `{"0":"a"}` decodes to `["a"]`, as PHP's `[0 => 'a']` is a list), other
/// arrays objects; empty objects become `[]`.
///
/// Fails with [`Error::Unrepresentable`] where PHP decodes a number to an
/// infinite float (`1e999`), which `serde_json::Value` cannot hold.
pub fn decode_value(json: &[u8], depth: i64, flags: Flags) -> Result<Value, Error> {
    let depth = decode_depth(json, depth, "json_decode(): Argument #3 ($depth)")?;
    parse(json, flags | Flags::OBJECT_AS_ARRAY, depth, &ValueBuilder).map_err(ParseError::into_error)
}

/// `json_validate($json, $depth, $flags)`: `Ok(())` when PHP returns `true`.
///
/// Only `JSON_INVALID_UTF8_IGNORE` is accepted in `flags` (PHP throws
/// `ValueError` for anything else). Property names starting with NUL are
/// valid here (they only fail when decoding to `stdClass`).
pub fn validate(json: &[u8], depth: i64, flags: Flags) -> Result<(), Error> {
    if flags != Flags::NONE && flags != Flags::INVALID_UTF8_IGNORE {
        return Err(Error::Value(
            "json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE)"
                .into(),
        ));
    }
    let depth = decode_depth(json, depth, "json_validate(): Argument #2 ($depth)")?;
    parse(json, flags, depth, &Validator).map_err(ParseError::into_error)
}

/// The argument checks `json_decode` and `json_validate` make before parsing.
fn decode_depth(json: &[u8], depth: i64, argument: &str) -> Result<i32, Error> {
    if json.is_empty() {
        return Err(Error::Json(ErrorCode::Syntax));
    }
    if depth <= 0 {
        return Err(Error::Value(format!("{argument} must be greater than 0")));
    }
    if depth > i64::from(i32::MAX) {
        return Err(Error::Value(format!("{argument} must be less than {}", i32::MAX)));
    }
    Ok(depth as i32)
}

/// A token of PHP's JSON scanner.
enum Token {
    LBrace,
    RBrace,
    LBracket,
    RBracket,
    Colon,
    Comma,
    Null,
    True,
    False,
    Int(i64),
    Double(f64),
    /// A string (also a big integer with `JSON_BIGINT_AS_STRING`).
    Str(String),
    /// The end of the input.
    End,
}

struct Scanner<'a> {
    s: &'a [u8],
    pos: usize,
    flags: Flags,
}

/// Length of the valid UTF-8 sequence (scanner byte ranges) at the start of
/// `s`, if one starts there.
fn utf8_len(s: &[u8]) -> Option<usize> {
    let t = |i: usize| s.get(i).is_some_and(|b| (0x80..=0xbf).contains(b));
    let c = *s.first()?;
    match c {
        0x00..=0x7f => Some(1),
        0xc2..=0xdf if t(1) => Some(2),
        0xe0 if s.get(1).is_some_and(|b| (0xa0..=0xbf).contains(b)) && t(2) => Some(3),
        0xe1..=0xec | 0xee..=0xef if t(1) && t(2) => Some(3),
        0xed if s.get(1).is_some_and(|b| (0x80..=0x9f).contains(b)) && t(2) => Some(3),
        0xf0 if s.get(1).is_some_and(|b| (0x90..=0xbf).contains(b)) && t(2) && t(3) => Some(4),
        0xf1..=0xf3 if t(1) && t(2) && t(3) => Some(4),
        0xf4 if s.get(1).is_some_and(|b| (0x80..=0x8f).contains(b)) && t(2) && t(3) => Some(4),
        _ => None,
    }
}

fn hex4(s: &[u8]) -> Option<u32> {
    if s.len() < 4 {
        return None;
    }
    let mut v = 0;
    for &b in &s[..4] {
        v = v << 4 | char::from(b).to_digit(16)?;
    }
    Some(v)
}

impl Scanner<'_> {
    /// `php_json_scan`: the next token, or the scanner's error.
    fn next(&mut self) -> Result<Token, ErrorCode> {
        let s = self.s;
        loop {
            let Some(&c) = s.get(self.pos) else {
                // The NUL terminator after the input.
                return Ok(Token::End);
            };
            let token = match c {
                b' ' | b'\t' | b'\r' | b'\n' => {
                    self.pos += 1;
                    continue;
                }
                b'{' => Token::LBrace,
                b'}' => Token::RBrace,
                b'[' => Token::LBracket,
                b']' => Token::RBracket,
                b':' => Token::Colon,
                b',' => Token::Comma,
                b'"' => {
                    self.pos += 1;
                    return self.string();
                }
                b'-' | b'0'..=b'9' => {
                    if let Some(t) = self.number() {
                        return Ok(t);
                    }
                    return Err(ErrorCode::Syntax);
                }
                _ if s[self.pos..].starts_with(b"null") => {
                    self.pos += 4;
                    return Ok(Token::Null);
                }
                _ if s[self.pos..].starts_with(b"true") => {
                    self.pos += 4;
                    return Ok(Token::True);
                }
                _ if s[self.pos..].starts_with(b"false") => {
                    self.pos += 5;
                    return Ok(Token::False);
                }
                0x00..=0x1f => return Err(ErrorCode::CtrlChar),
                _ => {
                    return Err(if utf8_len(&s[self.pos..]).is_some() { ErrorCode::Syntax } else { ErrorCode::Utf8 });
                }
            };
            self.pos += 1;
            return Ok(token);
        }
    }

    /// `INT`, `FLOAT` or `EXP` at the cursor (longest match), or `None` when
    /// none matches (a lone `-`).
    fn number(&mut self) -> Option<Token> {
        let s = self.s;
        let start = self.pos;
        let mut i = start;
        let negative = s[i] == b'-';
        if negative {
            i += 1;
        }
        match s.get(i) {
            Some(b'0') => i += 1,
            Some(b'1'..=b'9') => {
                while s.get(i).is_some_and(u8::is_ascii_digit) {
                    i += 1;
                }
            }
            _ => return None,
        }
        let int_end = i;
        let mut is_double = false;
        if s.get(i) == Some(&b'.') && s.get(i + 1).is_some_and(u8::is_ascii_digit) {
            i += 2;
            while s.get(i).is_some_and(u8::is_ascii_digit) {
                i += 1;
            }
            is_double = true;
        }
        if matches!(s.get(i), Some(b'e' | b'E')) {
            let mut j = i + 1;
            if matches!(s.get(j), Some(b'+' | b'-')) {
                j += 1;
            }
            if s.get(j).is_some_and(u8::is_ascii_digit) {
                while s.get(j).is_some_and(u8::is_ascii_digit) {
                    j += 1;
                }
                i = j;
                is_double = true;
            }
        }
        self.pos = i;
        // The token is ASCII digits, signs, '.', 'e': valid UTF-8.
        let text = std::str::from_utf8(&s[start..i]).unwrap_or("0");
        if is_double {
            return Some(Token::Double(text.parse().unwrap_or(0.0)));
        }
        debug_assert_eq!(int_end, i);
        let digits = &s[start + usize::from(negative)..int_end];
        let bigint = digits.len() > 19
            || (digits.len() == 19
                && !(digits < &b"9223372036854775808"[..] || (digits == b"9223372036854775808" && negative)));
        if !bigint {
            return Some(Token::Int(text.parse().unwrap_or(0)));
        }
        if self.flags.contains(Flags::BIGINT_AS_STRING) {
            return Some(Token::Str(text.to_owned()));
        }
        Some(Token::Double(text.parse().unwrap_or(0.0)))
    }

    /// A string after its opening quote (`STR_P1`, then `STR_P2`).
    fn string(&mut self) -> Result<Token, ErrorCode> {
        let s = self.s;
        let substitute = self.flags.contains(Flags::INVALID_UTF8_SUBSTITUTE);
        let tolerant = self.flags.intersects(Flags::INVALID_UTF8_IGNORE | Flags::INVALID_UTF8_SUBSTITUTE);
        let mut out = String::new();
        let mut run = self.pos;
        loop {
            let Some(&c) = s.get(self.pos) else {
                // The NUL terminator is a control character inside a string.
                return Err(ErrorCode::CtrlChar);
            };
            match c {
                0x00..=0x1f => return Err(ErrorCode::CtrlChar),
                b'"' => {
                    out.push_str(std::str::from_utf8(&s[run..self.pos]).unwrap_or_default());
                    self.pos += 1;
                    return Ok(Token::Str(out));
                }
                b'\\' => {
                    out.push_str(std::str::from_utf8(&s[run..self.pos]).unwrap_or_default());
                    let esc = s.get(self.pos + 1).copied();
                    let decoded = match esc {
                        Some(b'"') => '"',
                        Some(b'\\') => '\\',
                        Some(b'/') => '/',
                        Some(b'b') => '\u{8}',
                        Some(b'f') => '\u{c}',
                        Some(b'n') => '\n',
                        Some(b'r') => '\r',
                        Some(b't') => '\t',
                        Some(b'u') => {
                            let Some(code) = hex4(&s[self.pos + 2..]) else {
                                return Err(ErrorCode::Syntax);
                            };
                            if (0xd800..=0xdbff).contains(&code)
                                && s.get(self.pos + 6) == Some(&b'\\')
                                && s.get(self.pos + 7) == Some(&b'u')
                                && let Some(low) = hex4(&s[self.pos + 8..]).filter(|l| (0xdc00..=0xdfff).contains(l))
                            {
                                let c = 0x10000 + ((code & 0x3ff) << 10) + (low & 0x3ff);
                                out.push(char::from_u32(c).unwrap_or('\u{fffd}'));
                                self.pos += 12;
                                run = self.pos;
                                continue;
                            }
                            match char::from_u32(code) {
                                Some(ch) => {
                                    out.push(ch);
                                    self.pos += 6;
                                    run = self.pos;
                                    continue;
                                }
                                None => return Err(ErrorCode::Utf16),
                            }
                        }
                        _ => return Err(ErrorCode::Syntax),
                    };
                    out.push(decoded);
                    self.pos += 2;
                    run = self.pos;
                }
                0x20..=0x7f => self.pos += 1,
                _ => match utf8_len(&s[self.pos..]) {
                    Some(n) => self.pos += n,
                    None if tolerant => {
                        out.push_str(std::str::from_utf8(&s[run..self.pos]).unwrap_or_default());
                        if substitute {
                            out.push('\u{fffd}');
                        }
                        self.pos += 1;
                        run = self.pos;
                    }
                    None => return Err(ErrorCode::Utf8),
                },
            }
        }
    }
}

/// How the parser builds values: `stdClass`/arrays as [`Zval`], the request
/// model as `serde_json::Value`, or nothing (`json_validate`).
trait Build {
    type Value;
    type Array;
    type Object;

    fn scalar(&self, token: Token) -> Result<Self::Value, ParseError>;
    fn array(&self) -> Self::Array;
    fn push(&self, array: &mut Self::Array, value: Self::Value);
    fn end_array(&self, array: Self::Array) -> Self::Value;
    fn object(&self) -> Self::Object;
    /// `php_json_parser_object_update`.
    fn set(&self, object: &mut Self::Object, key: String, value: Self::Value) -> Result<(), ParseError>;
    fn end_object(&self, object: Self::Object) -> Self::Value;
}

enum ParseError {
    Code(ErrorCode),
    Unrepresentable,
}

impl From<ErrorCode> for ParseError {
    fn from(code: ErrorCode) -> Self {
        ParseError::Code(code)
    }
}

impl ParseError {
    fn into_error(self) -> Error {
        match self {
            ParseError::Code(code) => Error::Json(code),
            ParseError::Unrepresentable => Error::Unrepresentable,
        }
    }
}

struct ZvalBuilder {
    assoc: bool,
}

enum ZvalObject {
    Array(Array),
    Object(Object),
}

impl Build for ZvalBuilder {
    type Value = Zval;
    type Array = Array;
    type Object = ZvalObject;

    fn scalar(&self, token: Token) -> Result<Zval, ParseError> {
        Ok(match token {
            Token::Null => Zval::Null,
            Token::True => Zval::Bool(true),
            Token::False => Zval::Bool(false),
            Token::Int(i) => Zval::Int(i),
            Token::Double(d) => Zval::Float(d),
            Token::Str(s) => Zval::String(s.into_bytes()),
            _ => Zval::Null,
        })
    }

    fn array(&self) -> Array {
        Array::new()
    }

    fn push(&self, array: &mut Array, value: Zval) {
        array.push(value);
    }

    fn end_array(&self, array: Array) -> Zval {
        Zval::Array(array)
    }

    fn object(&self) -> ZvalObject {
        if self.assoc { ZvalObject::Array(Array::new()) } else { ZvalObject::Object(Object::new()) }
    }

    fn set(&self, object: &mut ZvalObject, key: String, value: Zval) -> Result<(), ParseError> {
        match object {
            ZvalObject::Array(a) => a.insert(Key::from_bytes(key.as_bytes()), value),
            ZvalObject::Object(o) => {
                if key.as_bytes().first() == Some(&0) {
                    return Err(ParseError::Code(ErrorCode::InvalidPropertyName));
                }
                o.set(key.into_bytes(), value);
            }
        }
        Ok(())
    }

    fn end_object(&self, object: ZvalObject) -> Zval {
        match object {
            ZvalObject::Array(a) => Zval::Array(a),
            ZvalObject::Object(o) => Zval::Object(o),
        }
    }
}

struct ValueBuilder;

impl Build for ValueBuilder {
    type Value = Value;
    type Array = Vec<Value>;
    type Object = Map<String, Value>;

    fn scalar(&self, token: Token) -> Result<Value, ParseError> {
        Ok(match token {
            Token::Null => Value::Null,
            Token::True => Value::Bool(true),
            Token::False => Value::Bool(false),
            Token::Int(i) => Value::from(i),
            Token::Double(d) => Value::Number(Number::from_f64(d).ok_or(ParseError::Unrepresentable)?),
            Token::Str(s) => Value::String(s),
            _ => Value::Null,
        })
    }

    fn array(&self) -> Vec<Value> {
        Vec::new()
    }

    fn push(&self, array: &mut Vec<Value>, value: Value) {
        array.push(value);
    }

    fn end_array(&self, array: Vec<Value>) -> Value {
        Value::Array(array)
    }

    fn object(&self) -> Map<String, Value> {
        Map::new()
    }

    fn set(&self, object: &mut Map<String, Value>, key: String, value: Value) -> Result<(), ParseError> {
        // `zend_symtable_update`: the same key replaces the value in place.
        object.insert(key, value);
        Ok(())
    }

    fn end_object(&self, object: Map<String, Value>) -> Value {
        let is_list = object.keys().enumerate().all(|(i, k)| numeric_key(k.as_bytes()) == Some(i as i64));
        if is_list { Value::Array(object.into_iter().map(|(_, v)| v).collect()) } else { Value::Object(object) }
    }
}

struct Validator;

impl Build for Validator {
    type Value = ();
    type Array = ();
    type Object = ();

    fn scalar(&self, _: Token) -> Result<(), ParseError> {
        Ok(())
    }

    fn array(&self) {}

    fn push(&self, _: &mut (), _: ()) {}

    fn end_array(&self, _: ()) {}

    fn object(&self) {}

    fn set(&self, _: &mut (), _: String, _: ()) -> Result<(), ParseError> {
        Ok(())
    }

    fn end_object(&self, _: ()) {}
}

/// `YYMAXDEPTH`: the bison parser stack never holds this many states; the
/// push that would reach it fails with "memory exhausted", a syntax error.
const PARSER_STACK_LIMIT: usize = 10000;

/// An open array or object, and how many parser-stack entries it holds.
enum Frame<B: Build> {
    /// `'[' $@ element ','?`
    Array { array: B::Array, entries: usize },
    /// `'{' $@ (member ',')? key ':'`
    Object { object: B::Object, key: Option<String>, entries: usize },
}

struct Parser<'a> {
    scanner: Scanner<'a>,
    /// `parser->depth` (starts at 1) and `max_depth`.
    depth: i32,
    max_depth: i32,
    /// Entries on bison's state stack.
    stack: usize,
}

impl Parser<'_> {
    /// A push onto bison's state stack (a shift, or the reduction of an
    /// empty rule).
    fn push(&mut self) -> Result<(), ErrorCode> {
        self.stack += 1;
        if self.stack >= PARSER_STACK_LIMIT { Err(ErrorCode::Syntax) } else { Ok(()) }
    }

    /// `PHP_JSON_DEPTH_INC` (the mid-rule action after `[` or `{`).
    fn enter(&mut self) -> Result<(), ErrorCode> {
        if self.max_depth != 0 && self.depth >= self.max_depth {
            return Err(ErrorCode::Depth);
        }
        self.depth += 1;
        self.push()
    }
}

/// `php_json_yyparse`, without recursion: the same tokens are read in the
/// same order and the same error is reported first.
fn parse<B: Build>(json: &[u8], flags: Flags, max_depth: i32, builder: &B) -> Result<B::Value, ParseError> {
    let mut p = Parser {
        scanner: Scanner { s: json, pos: 0, flags },
        depth: 1,
        max_depth,
        // State 0.
        stack: 1,
    };
    let mut frames: Vec<Frame<B>> = Vec::new();
    // The next token, when it was read ahead.
    let mut ahead: Option<Token> = None;
    loop {
        // Parse a value.
        let token = match ahead.take() {
            Some(t) => t,
            None => p.scanner.next()?,
        };
        let mut value = match token {
            Token::LBracket => {
                p.push()?;
                p.enter()?;
                let token = p.scanner.next()?;
                match token {
                    Token::RBracket => {
                        // `elements: %empty`, then `]`.
                        p.push()?;
                        p.push()?;
                        p.depth -= 1;
                        p.stack -= 3;
                        builder.end_array(builder.array())
                    }
                    Token::RBrace => {
                        p.push()?;
                        p.push()?;
                        return Err(ErrorCode::StateMismatch.into());
                    }
                    t => {
                        frames.push(Frame::Array { array: builder.array(), entries: 2 });
                        ahead = Some(t);
                        continue;
                    }
                }
            }
            Token::LBrace => {
                p.push()?;
                p.enter()?;
                match p.scanner.next()? {
                    Token::RBrace => {
                        // `members: %empty`, then `}`.
                        p.push()?;
                        p.push()?;
                        p.depth -= 1;
                        p.stack -= 3;
                        builder.end_object(builder.object())
                    }
                    Token::RBracket => {
                        p.push()?;
                        p.push()?;
                        return Err(ErrorCode::StateMismatch.into());
                    }
                    Token::Str(key) => {
                        // The key, then `:`.
                        p.push()?;
                        if !matches!(p.scanner.next()?, Token::Colon) {
                            return Err(ErrorCode::Syntax.into());
                        }
                        p.push()?;
                        frames.push(Frame::Object { object: builder.object(), key: Some(key), entries: 4 });
                        continue;
                    }
                    _ => return Err(ErrorCode::Syntax.into()),
                }
            }
            Token::RBrace | Token::RBracket | Token::Colon | Token::Comma | Token::End => {
                return Err(ErrorCode::Syntax.into());
            }
            scalar => {
                p.push()?;
                builder.scalar(scalar)?
            }
        };
        // `value` is complete and occupies one stack entry: close every
        // container it completes.
        loop {
            let Some(frame) = frames.last_mut() else {
                // `start: value EOI`.
                return match p.scanner.next()? {
                    Token::End => Ok(value),
                    _ => Err(ErrorCode::Syntax.into()),
                };
            };
            match frame {
                Frame::Array { array, entries } => {
                    // `element: value` or `element: element ',' value`.
                    p.stack -= *entries - 2;
                    *entries = 3;
                    builder.push(array, value);
                    match p.scanner.next()? {
                        Token::Comma => {
                            p.push()?;
                            *entries = 4;
                            break;
                        }
                        Token::RBracket => {
                            p.push()?;
                            p.depth -= 1;
                            p.stack -= 3;
                            let Some(Frame::Array { array, .. }) = frames.pop() else { unreachable!() };
                            value = builder.end_array(array);
                        }
                        Token::RBrace => {
                            p.push()?;
                            return Err(ErrorCode::StateMismatch.into());
                        }
                        _ => return Err(ErrorCode::Syntax.into()),
                    }
                }
                Frame::Object { object, key, entries } => {
                    // `member: key ':' value` or `member: member ',' key ':' value`.
                    p.stack -= *entries - 2;
                    *entries = 3;
                    let k = key.take().unwrap_or_default();
                    builder.set(object, k, value)?;
                    match p.scanner.next()? {
                        Token::Comma => {
                            p.push()?;
                            match p.scanner.next()? {
                                Token::Str(k) => {
                                    p.push()?;
                                    *key = Some(k);
                                }
                                _ => return Err(ErrorCode::Syntax.into()),
                            }
                            if !matches!(p.scanner.next()?, Token::Colon) {
                                return Err(ErrorCode::Syntax.into());
                            }
                            p.push()?;
                            *entries = 6;
                            break;
                        }
                        Token::RBrace => {
                            p.push()?;
                            p.depth -= 1;
                            p.stack -= 3;
                            let Some(Frame::Object { object, .. }) = frames.pop() else { unreachable!() };
                            value = builder.end_object(object);
                        }
                        Token::RBracket => {
                            p.push()?;
                            return Err(ErrorCode::StateMismatch.into());
                        }
                        _ => return Err(ErrorCode::Syntax.into()),
                    }
                }
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn enc(v: &Value, flags: Flags) -> String {
        encode(v, flags, DEFAULT_DEPTH).unwrap()
    }

    #[test]
    fn encodes_like_php() {
        assert_eq!(enc(&json!({"a": [], "b": {}, "c": [1, 2]}), Flags::NONE), r#"{"a":[],"b":{},"c":[1,2]}"#);
        assert_eq!(enc(&json!({"0": "x", "1": "y"}), Flags::NONE), r#"["x","y"]"#);
        assert_eq!(enc(&json!("a/\u{e9}\u{1f600}"), Flags::NONE), "\"a\\/\\u00e9\\ud83d\\ude00\"");
        assert_eq!(enc(&json!("a/é"), Flags::UNESCAPED_SLASHES | Flags::UNESCAPED_UNICODE), r#""a/é""#);
        assert_eq!(enc(&json!(1.0), Flags::PRESERVE_ZERO_FRACTION), "1.0");
        assert_eq!(enc(&json!(0.1), Flags::NONE), "0.1");
        assert_eq!(enc(&json!([1, {}]), Flags::PRETTY_PRINT), "[\n    1,\n    {}\n]");
        assert_eq!(enc(&json!([1]), Flags::FORCE_OBJECT), r#"{"0":1}"#);
    }

    #[test]
    fn encode_errors_like_php() {
        let bad = Zval::Array([Zval::Float(f64::INFINITY), Zval::String(vec![0xff])].into_iter().collect());
        assert_eq!(encode(&bad, Flags::NONE, 512), Err(Error::Json(ErrorCode::Utf8)));
        assert_eq!(
            encode(&bad, Flags::PARTIAL_OUTPUT_ON_ERROR, 512),
            Err(Error::Partial { json: "[0,null]".into(), code: ErrorCode::Utf8 })
        );
        assert_eq!(encode(&json!([[1]]), Flags::NONE, 1), Err(Error::Json(ErrorCode::Depth)));
        assert_eq!(encode(&json!([[1]]), Flags::NONE, 4294967298), Ok("[[1]]".into()));
    }

    #[test]
    fn decodes_like_php() {
        assert_eq!(decode_value(br#"{"0":1,"1":2}"#, 512, Flags::NONE).unwrap(), json!([1, 2]));
        assert_eq!(decode_value(br#"{"1":1,"0":2}"#, 512, Flags::NONE).unwrap(), json!({"1": 1, "0": 2}));
        assert_eq!(decode_value(b"{}", 512, Flags::NONE).unwrap(), json!([]));
        assert_eq!(decode(b"-0", None, 512, Flags::NONE).unwrap(), Zval::Int(0));
        assert_eq!(decode(b"9223372036854775808", None, 512, Flags::NONE).unwrap(), Zval::Float(9223372036854775808.0));
        assert_eq!(decode(b"-9223372036854775808", None, 512, Flags::NONE).unwrap(), Zval::Int(i64::MIN));
        let object = decode(br#"{"a":1,"a":2,"b":3}"#, None, 512, Flags::NONE).unwrap();
        assert_eq!(object.to_json().unwrap(), json!({"a": 2, "b": 3}));
    }

    #[test]
    fn decode_errors_like_php() {
        let code = |s: &[u8], depth: i64| decode(s, Some(true), depth, Flags::NONE).unwrap_err().code();
        assert_eq!(code(b"[\x01", 1), ErrorCode::Depth);
        assert_eq!(code(b"[]", 1), ErrorCode::Depth);
        assert_eq!(code(b"\"abc", 512), ErrorCode::CtrlChar);
        assert_eq!(code(b"1\0", 512), ErrorCode::CtrlChar);
        assert_eq!(code(b"[1}", 512), ErrorCode::StateMismatch);
        assert_eq!(code(b"[1,}", 512), ErrorCode::Syntax);
        assert_eq!(code(br#""\ud800""#, 512), ErrorCode::Utf16);
        assert_eq!(code(b"\xff", 512), ErrorCode::Utf8);
        assert_eq!(code("é".as_bytes(), 512), ErrorCode::Syntax);
        assert_eq!(
            decode(br#"{"\u0000a":1 x"#, Some(false), 512, Flags::NONE),
            Err(Error::Json(ErrorCode::InvalidPropertyName))
        );
        let nest = |n: usize| format!("{}{}", "[".repeat(n), "]".repeat(n));
        assert!(decode(nest(4998).as_bytes(), Some(true), 100000, Flags::NONE).is_ok());
        assert_eq!(code(nest(4999).as_bytes(), 100000), ErrorCode::Syntax);
        assert!(matches!(decode(b"1", None, 0, Flags::NONE), Err(Error::Value(_))));
        assert_eq!(code(b"", 0), ErrorCode::Syntax);
    }
}
