//! `serialize`, `unserialize` and `var_export`, in PHP 8.5's formats.
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `serialize.*`).
//!
//! | PHP | Rust |
//! |---|---|
//! | `serialize($value)` | [`serialize`] ([`Value`]) |
//! | `unserialize($data, ['max_depth' => $n])` | [`unserialize`], [`Options`] |
//! | `var_export($value, true)` | [`var_export`] |
//! | warnings `unserialize()` emits | [`Unserialized::warnings`], [`Error::Failed`] |
//!
//! Values are scalars, arrays and `stdClass` objects. Floats use
//! `serialize_precision = -1` (`d:0.1;`, `d:1.0E+25;`, `d:INF;`). An
//! [`Value::Ext`] object is written as its [`Extension::to_array`] (PHP code
//! serializes `getArrayCopy()`, not the object).
//!
//! `unserialize` reads every format PHP writes for those values (`N`, `b`,
//! `i`, `d`, `s`, `S`, `a`, `O`/`C` of `stdClass`, and the `r`/`R`
//! back-references), with PHP's error offsets, warnings and depth limit.
//! Back-references resolve to the value at that slot when they are read,
//! which is what PHP produces for data `serialize` writes; [`Value`] has no
//! references or object identity, so they become copies. Objects of other
//! classes, enums (`E:`), and back-references to a container that is still
//! being read (recursive structures) cannot be represented and fail with
//! [`Error::Unsupported`].

use std::fmt;

use indexmap::IndexMap;

use crate::number;
use crate::types::{Array, ArrayKey, Extension, KeyRef, Str, Value};

// ---------------------------------------------------------------------------
// serialize (ext/standard/var.c)
// ---------------------------------------------------------------------------

/// `serialize($value)`: PHP's byte string.
pub fn serialize<X: Extension>(value: &Value<X>) -> Vec<u8> {
    let mut out = Vec::new();
    serialize_into(&mut out, value);
    out
}

/// `serialize($value)`, appended to `out`.
pub fn serialize_into<X: Extension>(out: &mut Vec<u8>, value: &Value<X>) {
    match value {
        Value::Null => out.extend_from_slice(b"N;"),
        Value::Bool(b) => out.extend_from_slice(if *b { b"b:1;" } else { b"b:0;" }),
        Value::Int(i) => {
            out.extend_from_slice(b"i:");
            out.extend_from_slice(i.to_string().as_bytes());
            out.push(b';');
        }
        Value::Float(f) => {
            out.extend_from_slice(b"d:");
            out.extend_from_slice(number::gcvt(*f, -1, 'E').as_bytes());
            out.push(b';');
        }
        Value::Str(s) => serialize_string(out, s),
        Value::Array(a) => {
            out.extend_from_slice(b"a:");
            nested(out, a);
        }
        Value::Object(a) => {
            out.extend_from_slice(b"O:8:\"stdClass\":");
            nested(out, a);
        }
        Value::Ext(x) => {
            out.extend_from_slice(b"a:");
            nested(out, &x.to_array());
        }
    }
}

fn serialize_string(out: &mut Vec<u8>, s: &[u8]) {
    out.extend_from_slice(b"s:");
    out.extend_from_slice(s.len().to_string().as_bytes());
    out.extend_from_slice(b":\"");
    out.extend_from_slice(s);
    out.extend_from_slice(b"\";");
}

/// `php_var_serialize_nested_data`.
fn nested<X: Extension>(out: &mut Vec<u8>, entries: &Array<X>) {
    out.extend_from_slice(entries.len().to_string().as_bytes());
    out.extend_from_slice(b":{");
    for (key, value) in entries.iter() {
        match key {
            KeyRef::Int(i) => {
                out.extend_from_slice(b"i:");
                out.extend_from_slice(i.to_string().as_bytes());
                out.push(b';');
            }
            KeyRef::Str(s) => serialize_string(out, s),
        }
        serialize_into(out, value);
    }
    out.push(b'}');
}

// ---------------------------------------------------------------------------
// var_export (ext/standard/var.c)
// ---------------------------------------------------------------------------

/// `var_export($value, true)`: PHP source code for the value, as bytes
/// (string contents are copied verbatim).
pub fn var_export<X: Extension>(value: &Value<X>) -> Vec<u8> {
    let mut out = Vec::new();
    export(&mut out, value, 1);
    out
}

/// `buffer_append_spaces`: `%*c` with a space, so never fewer than one.
fn spaces(out: &mut Vec<u8>, n: usize) {
    out.resize(out.len() + n.max(1), b' ');
}

/// A single-quoted PHP string literal: `'` and `\` escaped, NUL bytes as
/// `' . "\0" . '` when `nul` is set.
fn quoted(out: &mut Vec<u8>, s: &[u8], nul: bool) {
    out.push(b'\'');
    for &c in s {
        match c {
            b'\'' | b'\\' => out.extend_from_slice(&[b'\\', c]),
            0 if nul => out.extend_from_slice(b"' . \"\\0\" . '"),
            _ => out.push(c),
        }
    }
    out.push(b'\'');
}

/// `php_var_export_ex`.
fn export<X: Extension>(out: &mut Vec<u8>, value: &Value<X>, level: usize) {
    match value {
        Value::Null => out.extend_from_slice(b"NULL"),
        Value::Bool(b) => out.extend_from_slice(if *b { b"true" } else { b"false" }),
        Value::Int(i64::MIN) => out.extend_from_slice(b"-9223372036854775807-1"),
        Value::Int(i) => out.extend_from_slice(i.to_string().as_bytes()),
        Value::Float(f) => {
            let f = *f;
            let s = number::gcvt(f, -1, 'E');
            out.extend_from_slice(s.as_bytes());
            if f.is_finite() && !s.contains('.') {
                out.extend_from_slice(b".0");
            }
        }
        Value::Str(s) => quoted(out, s, true),
        Value::Ext(x) => export(out, &Value::Array(x.to_array()), level),
        Value::Array(entries) => {
            if level > 1 {
                out.push(b'\n');
                spaces(out, level - 1);
            }
            out.extend_from_slice(b"array (\n");
            for (key, v) in entries.iter() {
                spaces(out, level + 1);
                match key {
                    KeyRef::Int(i) => out.extend_from_slice(i.to_string().as_bytes()),
                    KeyRef::Str(k) => quoted(out, k, true),
                }
                out.extend_from_slice(b" => ");
                export(out, v, level + 2);
                out.extend_from_slice(b",\n");
            }
            if level > 1 {
                spaces(out, level - 1);
            }
            out.push(b')');
        }
        Value::Object(entries) => {
            if level > 1 {
                out.push(b'\n');
                spaces(out, level - 1);
            }
            out.extend_from_slice(b"(object) array(\n");
            for (key, v) in entries.iter() {
                spaces(out, level + 2);
                match key {
                    KeyRef::Int(i) => out.extend_from_slice(i.to_string().as_bytes()),
                    KeyRef::Str(k) => quoted(out, unmangle(k), false),
                }
                out.extend_from_slice(b" => ");
                export(out, v, level + 2);
                out.extend_from_slice(b",\n");
            }
            if level > 1 {
                spaces(out, level - 1);
            }
            out.push(b')');
        }
    }
}

/// `zend_unmangle_property_name_ex`: the name after `"\0Class\0"` (or
/// `"\0*\0"`); the whole name when it is not mangled.
fn unmangle(name: &[u8]) -> &[u8] {
    if name.len() < 2 || name[0] != 0 {
        return name;
    }
    match name[1..].iter().position(|&c| c == 0) {
        Some(i) => &name[i + 2..],
        // A malformed mangled name: PHP keeps it whole.
        None => name,
    }
}

// ---------------------------------------------------------------------------
// unserialize (ext/standard/var_unserializer.re)
// ---------------------------------------------------------------------------

/// `unserialize()` options.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Options {
    /// `max_depth`: arrays and objects nested deeper fail. `0` is unlimited;
    /// the default is the `unserialize_max_depth` ini default, 4096.
    pub max_depth: i64,
}

impl Default for Options {
    fn default() -> Self {
        Options { max_depth: 4096 }
    }
}

/// A successful `unserialize()`: the value and the warnings PHP emitted
/// (for example `unserialize(): Extra data starting at offset 4 of 5 bytes`).
#[derive(Debug, Clone, PartialEq)]
pub struct Unserialized {
    pub value: Value,
    pub warnings: Vec<String>,
}

/// Why `unserialize()` failed.
#[derive(Debug, Clone, PartialEq)]
pub enum Error {
    /// PHP returns `false` after emitting `warnings` (the last one is
    /// `unserialize(): Error at offset X of Y bytes`, except for empty input,
    /// which fails silently). `offset` is X.
    Failed { offset: Option<usize>, warnings: Vec<String> },
    /// An invalid option: PHP throws `ValueError` with this message.
    Value(String),
    /// Data PHP reads into something [`Value`] cannot hold: objects of other
    /// classes (or `__PHP_Incomplete_Class`), enums, or a back-reference to
    /// a container still being read (a recursive structure).
    Unsupported(&'static str),
}

impl Error {
    /// The PHP class of a thrown error (`ValueError`); `unserialize` reports
    /// the other failures as warnings and `false`.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Value(_) => "ValueError",
            Error::Failed { .. } | Error::Unsupported(_) => "Error",
        }
    }
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Error::Failed { warnings, .. } => {
                f.write_str(warnings.last().map(String::as_str).unwrap_or("unserialize() failed"))
            }
            Error::Value(message) => f.write_str(message),
            Error::Unsupported(what) => write!(f, "unserialize(): unsupported data: {what}"),
        }
    }
}

impl std::error::Error for Error {}

/// `unserialize($data, $options)`.
pub fn unserialize(data: &[u8], options: &Options) -> Result<Unserialized, Error> {
    if data.is_empty() {
        return Err(Error::Failed { offset: None, warnings: Vec::new() });
    }
    if options.max_depth < 0 {
        return Err(Error::Value("unserialize(): Option \"max_depth\" must be greater than or equal to 0".into()));
    }
    let mut u = Unserializer {
        buf: data,
        max_depth: options.max_depth,
        cur_depth: 0,
        slots: Vec::new(),
        cells: vec![Cell::Null],
        warnings: Vec::new(),
        opened: None,
        unsupported: None,
    };
    let mut p = 0;
    match u.run(&mut p) {
        Ok(true) => {}
        Ok(false) => {
            u.warn(format!("unserialize(): Error at offset {p} of {} bytes", data.len()));
            return Err(Error::Failed { offset: Some(p), warnings: u.warnings });
        }
        Err(e) => return Err(e),
    }
    if p < data.len() {
        u.warn(format!("unserialize(): Extra data starting at offset {p} of {} bytes", data.len()));
    }
    if let Some(what) = u.unsupported {
        return Err(Error::Unsupported(what));
    }
    let value = u.materialize(0)?;
    Ok(Unserialized { value, warnings: u.warnings })
}

/// A location values are read into (PHP's `zval *` slots): back-references
/// point at locations, and a duplicate key reuses its location.
type CellId = usize;

enum Cell {
    Null,
    Value(Value),
    Array {
        entries: IndexMap<ArrayKey, CellId>,
        done: bool,
    },
    Object {
        props: IndexMap<Str, CellId>,
        done: bool,
    },
    /// A back-reference that makes the value recursive (PHP shares the
    /// referenced container): reading goes on, the result is unsupported.
    Recursive(CellId),
}

struct Unserializer<'a> {
    buf: &'a [u8],
    max_depth: i64,
    cur_depth: i64,
    /// `var_hash`: the location of every value read so far, in order.
    slots: Vec<CellId>,
    cells: Vec<Cell>,
    warnings: Vec<String>,
    /// Set by [`Unserializer::value`] when it opened an array or object:
    /// its element count and whether it is an object.
    opened: Option<(i64, bool)>,
    /// Why the value, once read, cannot be returned (an object of another class).
    unsupported: Option<&'static str>,
}

/// `HT_MAX_SIZE` on 64-bit platforms.
const HT_MAX_SIZE: i64 = 0x4000_0000;

/// What a key read produced (`php_var_unserialize_internal` without `var_hash`).
enum KeyValue {
    Int(i64),
    Str(Str),
    /// A value that cannot be a key (`N;`, `b:..;`, `d:..;`).
    Other,
}

type Step = Result<bool, Error>;

impl Unserializer<'_> {
    fn warn(&mut self, message: String) {
        self.warnings.push(message);
    }

    /// The byte at `i`, or the NUL terminator after the data.
    fn at(&self, i: usize) -> u8 {
        self.buf.get(i).copied().unwrap_or(0)
    }

    fn digits_end(&self, mut i: usize) -> usize {
        while self.at(i).is_ascii_digit() {
            i += 1;
        }
        i
    }

    /// `parse_uiv`: `size_t` arithmetic, wrapping like C.
    fn parse_uiv(&self, mut i: usize) -> u64 {
        let mut result: u64 = 0;
        while self.at(i).is_ascii_digit() {
            result = result.wrapping_mul(10).wrapping_add(u64::from(self.at(i) - b'0'));
            i += 1;
        }
        result
    }

    /// `parse_iv2`: an optional sign, leading zeros, digits; out of range
    /// clamps with a warning. Returns the value and where the digits end.
    fn parse_iv2(&mut self, mut i: usize) -> (i64, usize) {
        let mut neg = false;
        if self.at(i) == b'-' {
            neg = true;
            i += 1;
        } else if self.at(i) == b'+' {
            i += 1;
        }
        while self.at(i) == b'0' {
            i += 1;
        }
        let start = i;
        let mut result: u64 = 0;
        while self.at(i).is_ascii_digit() {
            result = result.wrapping_mul(10).wrapping_add(u64::from(self.at(i) - b'0'));
            i += 1;
        }
        if i - start > 19 || result > i64::MAX as u64 + u64::from(neg) {
            self.warn("unserialize(): Numerical result out of range".into());
            return (if neg { i64::MIN } else { i64::MAX }, i);
        }
        (if neg { (result as i64).wrapping_neg() } else { result as i64 }, i)
    }

    /// Matches `uiv ";"` (or another terminator) at `i`: the end, after the terminator.
    fn uiv_then(&self, i: usize, term: u8) -> Option<usize> {
        let end = self.digits_end(i);
        (end > i && self.at(end) == term).then_some(end + 1)
    }

    /// `iv = [+-]? [0-9]+` at `i`: where it ends.
    fn iv(&self, mut i: usize) -> Option<usize> {
        if matches!(self.at(i), b'+' | b'-') {
            i += 1;
        }
        let end = self.digits_end(i);
        (end > i).then_some(end)
    }

    /// `(iv | nv | nvexp)` at `i`: where the longest match ends.
    fn number(&self, i: usize) -> Option<usize> {
        let mut j = i;
        if matches!(self.at(j), b'+' | b'-') {
            j += 1;
        }
        let int_end = self.digits_end(j);
        let mut end = None;
        if int_end > j {
            end = Some(int_end);
        }
        if self.at(int_end) == b'.' {
            let frac_end = self.digits_end(int_end + 1);
            // nv: digits* "." digits+ | digits+ "." digits*
            if frac_end > int_end + 1 || int_end > j {
                end = Some(frac_end);
            }
        }
        let mantissa_end = end?;
        if matches!(self.at(mantissa_end), b'e' | b'E')
            && let Some(exp_end) = self.iv(mantissa_end + 1)
        {
            return Some(exp_end);
        }
        Some(mantissa_end)
    }

    fn set(&mut self, cell: CellId, value: Value) {
        self.cells[cell] = Cell::Value(value);
    }

    /// `php_var_unserialize_internal` with `var_hash`: reads a value into `cell`.
    fn value(&mut self, p: &mut usize, cell: CellId) -> Step {
        let start = *p;
        if start >= self.buf.len() {
            return Ok(false);
        }
        let c0 = self.at(start);
        if c0 != b'R' {
            self.slots.push(cell);
        }
        let c1 = self.at(start + 1);
        match (c0, c1) {
            (b'R' | b'r', b':') => {
                let Some(end) = self.uiv_then(start + 2, b';') else { return Ok(false) };
                *p = end;
                let id = self.parse_uiv(start + 2).wrapping_sub(1);
                let Some(&target) = usize::try_from(id).ok().and_then(|id| self.slots.get(id)) else {
                    return Ok(false);
                };
                if target == cell {
                    return Ok(false);
                }
                if c0 == b'r' && !self.is_object(target) {
                    return Ok(false);
                }
                match self.materialize(target) {
                    Ok(snapshot) => self.set(cell, snapshot),
                    Err(Error::Unsupported(what)) => {
                        self.unsupported.get_or_insert(what);
                        self.cells[cell] = Cell::Recursive(target);
                    }
                    Err(e) => return Err(e),
                }
                Ok(true)
            }
            (b'N', b';') => {
                *p = start + 2;
                self.set(cell, Value::Null);
                Ok(true)
            }
            (b'b', b':') if matches!(self.at(start + 2), b'0' | b'1') && self.at(start + 3) == b';' => {
                *p = start + 4;
                let b = self.at(start + 2) == b'1';
                self.set(cell, Value::Bool(b));
                Ok(true)
            }
            (b'i', b':') => {
                let Some(end) = self.iv(start + 2).filter(|&e| self.at(e) == b';') else { return Ok(false) };
                *p = end + 1;
                let (i, _) = self.parse_iv2(start + 2);
                self.set(cell, Value::Int(i));
                Ok(true)
            }
            (b'd', b':') => {
                let rest = &self.buf[(start + 2).min(self.buf.len())..];
                for (text, value) in [(&b"NAN;"[..], f64::NAN), (b"INF;", f64::INFINITY), (b"-INF;", f64::NEG_INFINITY)]
                {
                    if rest.starts_with(text) {
                        *p = start + 2 + text.len();
                        self.set(cell, Value::Float(value));
                        return Ok(true);
                    }
                }
                let Some(end) = self.number(start + 2).filter(|&e| self.at(e) == b';') else { return Ok(false) };
                *p = end + 1;
                let text = std::str::from_utf8(&self.buf[start + 2..end]).unwrap_or("0");
                self.set(cell, Value::Float(strtod(text)));
                Ok(true)
            }
            (b's' | b'S', b':') => {
                let Some(s) = self.string(p, start)? else { return Ok(false) };
                self.set(cell, Value::Str(s));
                Ok(true)
            }
            (b'a', b':') => {
                let Some(cursor) = self.uiv_then(start + 2, b':').filter(|&e| self.at(e) == b'{').map(|e| e + 1) else {
                    return Ok(false);
                };
                let (elements, _) = self.parse_iv2(start + 2);
                *p = cursor;
                if !(0..HT_MAX_SIZE).contains(&elements) || fake_count(elements, self.buf.len() - cursor) {
                    return Ok(false);
                }
                if elements == 0 {
                    // No nested data, so no depth check.
                    self.cells[cell] = Cell::Array { entries: IndexMap::new(), done: true };
                    return Ok(self.finish(p));
                }
                self.cells[cell] = Cell::Array { entries: IndexMap::new(), done: false };
                self.opened = Some((elements, false));
                Ok(true)
            }
            (b'O' | b'C', b':') => self.object(p, cell, start),
            (b'E', b':') => self.enumeration(p, start),
            (b'}', _) => {
                self.warn("unserialize(): Unexpected end of serialized data".into());
                Ok(false)
            }
            _ => Ok(false),
        }
    }

    /// `"s:" uiv ":" ["]` and `"S:" uiv ":" ["]` at `start`: the string, or
    /// `None` after setting `p` where PHP reports the error.
    fn string(&mut self, p: &mut usize, start: usize) -> Result<Option<Str>, Error> {
        let escaped = self.at(start) == b'S';
        let Some(cursor) = self.uiv_then(start + 2, b':').filter(|&e| self.at(e) == b'"').map(|e| e + 1) else {
            return Ok(None);
        };
        let len = self.parse_uiv(start + 2);
        let maxlen = (self.buf.len() - cursor) as u64;
        if maxlen < len {
            *p = start + 2;
            return Ok(None);
        }
        let len = len as usize;
        let (s, mut cursor) = if escaped {
            // `unserialize_str`: `\xx` hex escapes; the cursor is local, so a
            // failure here reports the value's start.
            let mut out = Vec::with_capacity(len);
            let mut i = cursor;
            for _ in 0..len {
                if i >= self.buf.len() {
                    return Ok(None);
                }
                if self.at(i) != b'\\' {
                    out.push(self.at(i));
                } else {
                    let mut ch: u8 = 0;
                    for _ in 0..2 {
                        i += 1;
                        let Some(d) = char::from(self.at(i)).to_digit(16) else { return Ok(None) };
                        ch = (ch << 4).wrapping_add(d as u8);
                    }
                    out.push(ch);
                }
                i += 1;
            }
            (Str::from(out), i)
        } else {
            (Str::copy_from(&self.buf[cursor..cursor + len]), cursor + len)
        };
        if self.at(cursor) != b'"' {
            *p = cursor;
            return Ok(None);
        }
        if self.at(cursor + 1) != b';' {
            *p = cursor + 1;
            return Ok(None);
        }
        cursor += 2;
        *p = cursor;
        if escaped {
            self.warn("unserialize(): Unserializing the 'S' format is deprecated".into());
        }
        Ok(Some(s))
    }

    /// `"E:" uiv ":" ["]`: an enum case. No PHP class exists on this side,
    /// so this is PHP's behaviour for an enum class that is not defined.
    fn enumeration(&mut self, p: &mut usize, start: usize) -> Step {
        let Some(cursor) = self.uiv_then(start + 2, b':').filter(|&e| self.at(e) == b'"').map(|e| e + 1) else {
            return Ok(false);
        };
        let len = self.parse_uiv(start + 2);
        let maxlen = (self.buf.len() - cursor) as u64;
        if maxlen < len || len == 0 {
            *p = start + 2;
            return Ok(false);
        }
        let end = cursor + len as usize;
        if self.at(end) != b'"' {
            *p = end;
            return Ok(false);
        }
        if self.at(end + 1) != b';' {
            *p = end + 1;
            return Ok(false);
        }
        let name = &self.buf[cursor..end];
        let Some(colon) = name.iter().position(|&c| c == b':') else {
            let message =
                format!("unserialize(): Invalid enum name '{}' (missing colon)", String::from_utf8_lossy(name));
            self.warn(message);
            return Ok(false);
        };
        let class = &name[..colon];
        if !valid_class_name(class) {
            return Ok(false);
        }
        let message = format!("unserialize(): Class '{}' not found", String::from_utf8_lossy(class));
        self.warn(message);
        Ok(false)
    }

    /// `object ":" uiv ":" ["]`: `O:` and `C:` of `stdClass`.
    fn object(&mut self, p: &mut usize, cell: CellId, start: usize) -> Step {
        let custom = self.at(start) == b'C';
        let Some(mut cursor) = self.uiv_then(start + 2, b':').filter(|&e| self.at(e) == b'"').map(|e| e + 1) else {
            return Ok(false);
        };
        let len = self.parse_uiv(start + 2);
        let maxlen = (self.buf.len() - cursor) as u64;
        if maxlen < len || len == 0 {
            *p = start + 2;
            return Ok(false);
        }
        let name = &self.buf[cursor..cursor + len as usize];
        cursor += len as usize;
        if self.at(cursor) != b'"' {
            *p = cursor;
            return Ok(false);
        }
        if self.at(cursor + 1) != b':' {
            *p = cursor + 1;
            return Ok(false);
        }
        if name[0] == 0 || name[0] == b'\\' {
            return Ok(false);
        }
        let incomplete = !name.eq_ignore_ascii_case(b"stdClass");
        if incomplete {
            if !valid_class_name(name) {
                return Ok(false);
            }
            // No PHP class exists here: PHP reads such an object as
            // __PHP_Incomplete_Class, parsing on exactly like stdClass. Read
            // on, so failures match, and refuse the value if it is complete.
            self.unsupported.get_or_insert("object of a class other than stdClass");
        }
        *p = cursor;
        if custom {
            // `object_custom`: stdClass has no unserializer.
            let (datalen, q) = self.parse_iv2(*p + 2);
            *p = q;
            if self.buf.len().saturating_sub(*p) < 2 || self.at(*p) != b':' {
                return Ok(false);
            }
            if self.at(*p + 1) != b'{' {
                *p += 1;
                return Ok(false);
            }
            *p += 2;
            let present = (self.buf.len() - *p) as i64;
            if datalen < 0 || present <= datalen {
                self.warn(format!("Insufficient data for unserializing - {datalen} required, {present} present"));
                return Ok(false);
            }
            if self.at(*p + datalen as usize) != b'}' {
                *p += datalen as usize;
                return Ok(false);
            }
            let class = if incomplete { "__PHP_Incomplete_Class" } else { "stdClass" };
            self.warn(format!("Class {class} has no unserializer"));
            self.cells[cell] = Cell::Value(Value::Object(Array::new()));
            *p += datalen as usize + 1;
            return Ok(true);
        }
        if *p + 2 >= self.buf.len() {
            self.warn("Bad unserialize data".into());
            return Ok(false);
        }
        let (elements, q) = self.parse_iv2(*p + 2);
        *p = q;
        if elements < 0 || fake_count(elements, self.buf.len() - cursor) {
            return Ok(false);
        }
        if self.at(*p) != b':' {
            return Ok(false);
        }
        if self.at(*p + 1) != b'{' {
            *p += 1;
            return Ok(false);
        }
        *p += 2;
        if elements >= HT_MAX_SIZE {
            return Ok(false);
        }
        self.cells[cell] = Cell::Object { props: IndexMap::new(), done: false };
        self.opened = Some((elements, true));
        Ok(true)
    }

    /// `finish_nested_data`.
    fn finish(&self, p: &mut usize) -> bool {
        if *p >= self.buf.len() || self.at(*p) != b'}' {
            return false;
        }
        *p += 1;
        true
    }

    /// `php_var_unserialize_internal` without `var_hash`: an array key or property name.
    fn key(&mut self, p: &mut usize) -> Result<Option<KeyValue>, Error> {
        let start = *p;
        if start >= self.buf.len() {
            return Ok(None);
        }
        let (c0, c1) = (self.at(start), self.at(start + 1));
        Ok(match (c0, c1) {
            (b'R' | b'r', b':') => {
                // The rule matches (and moves the cursor) before refusing a key.
                if let Some(end) = self.uiv_then(start + 2, b';') {
                    *p = end;
                }
                None
            }
            (b'N', b';') => {
                *p = start + 2;
                Some(KeyValue::Other)
            }
            (b'b', b':') if matches!(self.at(start + 2), b'0' | b'1') && self.at(start + 3) == b';' => {
                *p = start + 4;
                Some(KeyValue::Other)
            }
            (b'i', b':') => match self.iv(start + 2).filter(|&e| self.at(e) == b';') {
                Some(end) => {
                    *p = end + 1;
                    Some(KeyValue::Int(self.parse_iv2(start + 2).0))
                }
                None => None,
            },
            (b'd', b':') => {
                let rest = &self.buf[(start + 2).min(self.buf.len())..];
                if let Some(text) = [&b"NAN;"[..], b"INF;", b"-INF;"].into_iter().find(|t| rest.starts_with(t)) {
                    *p = start + 2 + text.len();
                    Some(KeyValue::Other)
                } else if let Some(end) = self.number(start + 2).filter(|&e| self.at(e) == b';') {
                    *p = end + 1;
                    Some(KeyValue::Other)
                } else {
                    None
                }
            }
            (b's' | b'S', b':') => self.string(p, start)?.map(KeyValue::Str),
            (b'a', b':') => {
                if let Some(cursor) = self.uiv_then(start + 2, b':').filter(|&e| self.at(e) == b'{') {
                    self.parse_iv2(start + 2);
                    *p = cursor + 1;
                }
                None
            }
            (b'}', _) => {
                self.warn("unserialize(): Unexpected end of serialized data".into());
                None
            }
            _ => None,
        })
    }

    /// Reads the whole value into the root location: `php_var_unserialize_internal`
    /// with `process_nested_array_data` / `process_nested_object_data` and
    /// `finish_nested_data`, without recursion.
    fn run(&mut self, p: &mut usize) -> Step {
        struct Frame {
            cell: CellId,
            remaining: i64,
            object: bool,
            started: bool,
        }
        let mut frames: Vec<Frame> = Vec::new();
        let mut target = 0;
        loop {
            if !self.value(p, target)? {
                return Ok(false);
            }
            if let Some((elements, object)) = self.opened.take() {
                if self.max_depth > 0 && self.cur_depth >= self.max_depth {
                    self.warn(format!(
                        "unserialize(): Maximum depth of {} exceeded. The depth limit can be changed using the max_depth unserialize() option or the unserialize_max_depth ini setting",
                        self.max_depth
                    ));
                    return Ok(false);
                }
                self.cur_depth += 1;
                frames.push(Frame { cell: target, remaining: elements, object, started: false });
            }
            // The value is complete (or a container opened): find the next one.
            loop {
                let Some(frame) = frames.last_mut() else { return Ok(true) };
                if frame.started && frame.remaining > 0 && !matches!(self.at(p.wrapping_sub(1)), b';' | b'}') {
                    *p -= 1;
                    return Ok(false);
                }
                frame.started = true;
                if frame.remaining > 0 {
                    frame.remaining -= 1;
                    let (cell, object) = (frame.cell, frame.object);
                    let Some(key) = self.key(p)? else { return Ok(false) };
                    target = match (key, object) {
                        (KeyValue::Other, _) => return Ok(false),
                        (KeyValue::Int(i), false) => self.child(cell, ArrayKey::Int(i)),
                        (KeyValue::Str(s), false) => self.child(cell, ArrayKey::normalize(s)),
                        (KeyValue::Int(i), true) => self.prop(cell, Str::from(i.to_string())),
                        (KeyValue::Str(s), true) => self.prop(cell, s),
                    };
                    break;
                }
                let Some(frame) = frames.pop() else { return Ok(true) };
                self.cur_depth -= 1;
                match &mut self.cells[frame.cell] {
                    Cell::Array { done, .. } | Cell::Object { done, .. } => *done = true,
                    _ => {}
                }
                if !self.finish(p) {
                    return Ok(false);
                }
            }
        }
    }

    /// The location of `key` in the array at `cell` (reused when the key repeats).
    fn child(&mut self, cell: CellId, key: ArrayKey) -> CellId {
        let next = self.cells.len();
        let Cell::Array { entries, .. } = &mut self.cells[cell] else { unreachable!() };
        let id = *entries.entry(key).or_insert(next);
        if id == next {
            self.cells.push(Cell::Null);
        } else {
            self.cells[id] = Cell::Null;
        }
        id
    }

    fn prop(&mut self, cell: CellId, name: Str) -> CellId {
        let next = self.cells.len();
        let Cell::Object { props, .. } = &mut self.cells[cell] else { unreachable!() };
        let id = *props.entry(name).or_insert(next);
        if id == next {
            self.cells.push(Cell::Null);
        } else {
            self.cells[id] = Cell::Null;
        }
        id
    }

    /// Whether the value at a location is an object (`ZVAL_DEREF` + `IS_OBJECT`).
    fn is_object(&self, mut cell: CellId) -> bool {
        loop {
            match &self.cells[cell] {
                Cell::Recursive(target) => cell = *target,
                Cell::Object { .. } | Cell::Value(Value::Object(_)) => return true,
                _ => return false,
            }
        }
    }

    /// The value at a location now (without recursion).
    fn materialize(&self, root: CellId) -> Result<Value, Error> {
        enum Slot<'c> {
            Key(&'c ArrayKey),
            Prop(&'c Str),
        }
        enum Partial {
            Array(Array),
            Object(Array),
        }
        struct Frame<'c> {
            partial: Partial,
            children: Vec<(Slot<'c>, CellId)>,
            next: usize,
        }
        let mut stack: Vec<Frame<'_>> = Vec::new();
        let mut visit = Some(root);
        let mut done: Option<Value> = None;
        loop {
            if let Some(cell) = visit.take() {
                match &self.cells[cell] {
                    Cell::Null => done = Some(Value::Null),
                    Cell::Value(v) => done = Some(v.clone()),
                    Cell::Array { done: false, .. } | Cell::Object { done: false, .. } | Cell::Recursive(_) => {
                        return Err(Error::Unsupported("reference to a container that is still being read"));
                    }
                    Cell::Array { entries, .. } => stack.push(Frame {
                        partial: Partial::Array(Array::with_capacity(entries.len())),
                        children: entries.iter().map(|(k, &c)| (Slot::Key(k), c)).collect(),
                        next: 0,
                    }),
                    Cell::Object { props, .. } => stack.push(Frame {
                        partial: Partial::Object(Array::new()),
                        children: props.iter().map(|(k, &c)| (Slot::Prop(k), c)).collect(),
                        next: 0,
                    }),
                }
            }
            let Some(frame) = stack.last_mut() else { return Ok(done.unwrap_or_default()) };
            if let Some(value) = done.take() {
                match (&mut frame.partial, &frame.children[frame.next - 1].0) {
                    (Partial::Array(a), Slot::Key(k)) => {
                        a.set((*k).clone(), value);
                    }
                    (Partial::Object(o), Slot::Prop(name)) => {
                        o.set(ArrayKey::Str((*name).clone()), value);
                    }
                    _ => unreachable!(),
                }
            }
            if frame.next < frame.children.len() {
                visit = Some(frame.children[frame.next].1);
                frame.next += 1;
            } else if let Some(frame) = stack.pop() {
                done = Some(match frame.partial {
                    Partial::Array(a) => Value::Array(a),
                    Partial::Object(o) => Value::Object(o),
                });
            }
        }
    }
}

/// `zend_is_valid_class_name`: `0-9 a-z A-Z _ \` and bytes from 0x80.
fn valid_class_name(name: &[u8]) -> bool {
    name.iter().all(|&c| c.is_ascii_alphanumeric() || c == b'_' || c == b'\\' || c >= 0x80)
}

/// `IS_FAKE_ELEM_COUNT`: each element takes at least two bytes.
fn fake_count(elements: i64, remaining: usize) -> bool {
    elements > (remaining / 2) as i64
}

/// `zend_strtod` of a matched `iv | nv | nvexp`: Rust's parser accepts the
/// same forms (`+1`, `5.`, `.5e1`) and rounds the same way.
fn strtod(text: &str) -> f64 {
    text.parse().unwrap_or(0.0)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    type V = Value<crate::types::Never>;

    fn u(s: &str) -> Result<Unserialized, Error> {
        unserialize(s.as_bytes(), &Options::default())
    }

    #[test]
    fn serializes_like_php() {
        let v = json!([1.0, 0.1, 1e25, -0.0, "a", {"x": true, "5": null}, {}]);
        assert_eq!(
            String::from_utf8(serialize(&V::from_json(&v))).unwrap(),
            r#"a:7:{i:0;d:1;i:1;d:0.1;i:2;d:1.0E+25;i:3;d:-0;i:4;s:1:"a";i:5;a:2:{s:1:"x";b:1;i:5;N;}i:6;O:8:"stdClass":0:{}}"#
        );
    }

    #[test]
    fn exports_like_php() {
        let v = json!({"a": [1, {}], "k'": "x\u{0}y"});
        assert_eq!(
            String::from_utf8(var_export(&V::from_json(&v))).unwrap(),
            "array (\n  'a' => \n  array (\n    0 => 1,\n    1 => \n    (object) array(\n    ),\n  ),\n  'k\\'' => 'x' . \"\\0\" . 'y',\n)"
        );
        assert_eq!(String::from_utf8(var_export(&V::from_json(&json!(1.0)))).unwrap(), "1.0");
        assert_eq!(String::from_utf8(var_export(&V::from_json(&json!(i64::MIN)))).unwrap(), "-9223372036854775807-1");
    }

    #[test]
    fn unserializes_like_php() {
        assert_eq!(u("i:+0005;").unwrap().value, Value::Int(5));
        assert_eq!(u("d:5.;").unwrap().value, Value::Float(5.0));
        assert_eq!(u("d:.5e1;").unwrap().value, Value::Float(5.0));
        let r = u("a:3:{i:0;i:1;i:0;i:2;i:1;R:2;}").unwrap().value;
        assert_eq!(r.to_json(), json!([2, 2]));
        assert_eq!(
            u("i:5;x").unwrap().warnings,
            vec!["unserialize(): Extra data starting at offset 4 of 5 bytes".to_string()]
        );
        for (s, offset) in [
            ("i:5", 0),
            ("s:3:\"ab\";", 8),
            ("s:2:\"abc\";", 7),
            ("a:1:{i:0;i:1;", 13),
            ("a:1:{d:1;i:1;}", 9),
            ("a:2:{i:0;i:5;i:1;r:2;}", 21),
            ("R:1;", 4),
        ] {
            match u(s) {
                Err(Error::Failed { offset: o, .. }) => assert_eq!(o, Some(offset), "{s}"),
                other => panic!("{s}: {other:?}"),
            }
        }
        assert!(matches!(u("a:1:{i:0;R:1;}"), Err(Error::Unsupported(_))));
    }
}
