//! `yaml_parse()` (ext-yaml 2.3 over libyaml 0.2.5) with the default ini
//! settings: timestamps, `!!binary` and `!php/object` stay strings.
//!
//! Documents are split into events by libyaml itself (`unsafe-libyaml`, the
//! C library translated to Rust), so exactly the same inputs are accepted and
//! refused; the events are turned into PHP values as ext-yaml's `parse.c`
//! and `detect.c` do: YAML 1.1 booleans (`yes`, `off`, `y`), integers in
//! binary, octal (`0755`), hexadecimal, sexagesimal (`1:20`) and with `_` or
//! `,` separators, floats whose exponent has a sign, merge keys (`<<`), and
//! keys converted like array offsets.
//!
//! PHP: anchors are PHP references. An alias of a node that is still being
//! built (a recursive structure) has no value here: it is refused as an
//! unknown alias.

use std::collections::HashMap;
use std::mem::MaybeUninit;

use crate::format::{dval_to_lval, str_to_float, str_to_int};
use crate::{Array, ArrayKey, Str, Value};

const NULL_TAG: &[u8] = b"tag:yaml.org,2002:null";
const BOOL_TAG: &[u8] = b"tag:yaml.org,2002:bool";
const INT_TAG: &[u8] = b"tag:yaml.org,2002:int";
const FLOAT_TAG: &[u8] = b"tag:yaml.org,2002:float";
const TIMESTAMP_TAG: &[u8] = b"tag:yaml.org,2002:timestamp";
const MERGE_TAG: &[u8] = b"tag:yaml.org,2002:merge";
const NONSPECIFIC_TAG: &[u8] = b"!";

/// A parser event, copied out of libyaml.
#[derive(Debug, Clone, PartialEq)]
enum Event {
    StreamStart,
    StreamEnd,
    DocumentStart,
    DocumentEnd,
    Alias(Vec<u8>),
    Scalar(Scalar),
    SequenceStart(Option<Vec<u8>>),
    SequenceEnd,
    MappingStart(Option<Vec<u8>>),
    MappingEnd,
    /// No event: what libyaml returns after an error or the end of the stream.
    Nothing,
}

#[derive(Debug, Clone, PartialEq)]
struct Scalar {
    anchor: Option<Vec<u8>>,
    tag: Option<Vec<u8>>,
    value: Vec<u8>,
    plain_implicit: bool,
    quoted_implicit: bool,
    /// Plain (or any) style: not quoted, literal or folded.
    plain: bool,
}

impl Scalar {
    fn tag_is(&self, tag: &[u8]) -> bool {
        self.tag.as_deref() == Some(tag)
    }

    fn not_implicit_and_tag_is(&self, tag: &[u8]) -> bool {
        !self.quoted_implicit && !self.plain_implicit && self.tag_is(tag)
    }

    fn not_quoted_or_tag_is(&self, tag: &[u8]) -> bool {
        self.plain && (self.plain_implicit || self.tag_is(tag))
    }
}

/// libyaml's parser over one input.
struct Parser<'a> {
    parser: Box<MaybeUninit<unsafe_libyaml::yaml_parser_t>>,
    _input: &'a [u8],
}

/// A C string of libyaml, copied.
///
/// # Safety
///
/// `p` is null or points at a NUL-terminated string.
unsafe fn c_bytes(p: *const u8) -> Option<Vec<u8>> {
    if p.is_null() {
        return None;
    }
    // SAFETY: the caller guarantees a NUL-terminated string.
    let s = unsafe { std::ffi::CStr::from_ptr(p.cast()) };
    Some(s.to_bytes().to_vec())
}

impl<'a> Parser<'a> {
    fn new(input: &'a [u8]) -> Option<Self> {
        let mut parser = Box::new(MaybeUninit::<unsafe_libyaml::yaml_parser_t>::uninit());
        // SAFETY: `yaml_parser_initialize` initialises the parser it is given;
        // the input outlives the parser (`_input` borrows it).
        unsafe {
            if unsafe_libyaml::yaml_parser_initialize(parser.as_mut_ptr()).fail {
                return None;
            }
            unsafe_libyaml::yaml_parser_set_input_string(parser.as_mut_ptr(), input.as_ptr(), input.len() as _);
        }
        Some(Self { parser, _input: input })
    }

    /// The next event, or `None` on a reader, scanner or parser error.
    fn next(&mut self) -> Option<Event> {
        let mut raw = MaybeUninit::<unsafe_libyaml::yaml_event_t>::uninit();
        // SAFETY: the parser was initialised in `new`; on success the event is
        // initialised, read according to its type, then deleted once.
        unsafe {
            if unsafe_libyaml::yaml_parser_parse(self.parser.as_mut_ptr(), raw.as_mut_ptr()).fail {
                return None;
            }
            let event = raw.as_mut_ptr();
            let converted = match (*event).type_ {
                unsafe_libyaml::YAML_STREAM_START_EVENT => Event::StreamStart,
                unsafe_libyaml::YAML_STREAM_END_EVENT => Event::StreamEnd,
                unsafe_libyaml::YAML_DOCUMENT_START_EVENT => Event::DocumentStart,
                unsafe_libyaml::YAML_DOCUMENT_END_EVENT => Event::DocumentEnd,
                unsafe_libyaml::YAML_ALIAS_EVENT => {
                    Event::Alias(c_bytes((*event).data.alias.anchor).unwrap_or_default())
                }
                unsafe_libyaml::YAML_SCALAR_EVENT => {
                    let s = (*event).data.scalar;
                    let value = if s.value.is_null() {
                        Vec::new()
                    } else {
                        std::slice::from_raw_parts(s.value, s.length as usize).to_vec()
                    };
                    Event::Scalar(Scalar {
                        anchor: c_bytes(s.anchor),
                        tag: c_bytes(s.tag),
                        value,
                        plain_implicit: s.plain_implicit,
                        quoted_implicit: s.quoted_implicit,
                        plain: matches!(
                            s.style,
                            unsafe_libyaml::YAML_PLAIN_SCALAR_STYLE | unsafe_libyaml::YAML_ANY_SCALAR_STYLE
                        ),
                    })
                }
                unsafe_libyaml::YAML_SEQUENCE_START_EVENT => {
                    Event::SequenceStart(c_bytes((*event).data.sequence_start.anchor))
                }
                unsafe_libyaml::YAML_SEQUENCE_END_EVENT => Event::SequenceEnd,
                unsafe_libyaml::YAML_MAPPING_START_EVENT => {
                    Event::MappingStart(c_bytes((*event).data.mapping_start.anchor))
                }
                unsafe_libyaml::YAML_MAPPING_END_EVENT => Event::MappingEnd,
                _ => Event::Nothing,
            };
            unsafe_libyaml::yaml_event_delete(event);
            Some(converted)
        }
    }
}

impl Drop for Parser<'_> {
    fn drop(&mut self) {
        // SAFETY: the parser was initialised in `new` and is deleted once.
        unsafe { unsafe_libyaml::yaml_parser_delete(self.parser.as_mut_ptr()) }
    }
}

/// A node as ext-yaml builds it: its value, whether PHP holds it as a
/// reference (it has an anchor or is an alias), and which of its direct
/// children are references (a merge key merges only those).
struct Node {
    value: Value,
    is_ref: bool,
    child_refs: Vec<ArrayKey>,
}

impl Node {
    fn plain(value: Value) -> Self {
        Node { value, is_ref: false, child_refs: Vec::new() }
    }
}

struct State<'a> {
    parser: Parser<'a>,
    /// The last event read; `None` after an error.
    event: Option<Event>,
    aliases: HashMap<Vec<u8>, Value>,
}

impl State<'_> {
    fn next(&mut self) -> bool {
        self.event = self.parser.next();
        self.event.is_some()
    }

    /// `get_next_element()`: the next node, `None` at a halting event or
    /// on failure.
    fn element(&mut self) -> Option<Node> {
        if !self.next() {
            return None;
        }
        match self.event.clone()? {
            Event::DocumentEnd | Event::MappingEnd | Event::SequenceEnd | Event::StreamEnd => None,
            Event::DocumentStart => self.document(),
            Event::MappingStart(anchor) => self.mapping(anchor),
            Event::SequenceStart(anchor) => self.sequence(anchor),
            Event::Scalar(scalar) => {
                let value = eval_scalar(&scalar);
                if let Some(anchor) = scalar.anchor {
                    self.aliases.insert(anchor, value.clone());
                    return Some(Node { value, is_ref: true, child_refs: Vec::new() });
                }
                Some(Node::plain(value))
            }
            Event::Alias(anchor) => {
                let value = self.aliases.get(&anchor)?.clone();
                Some(Node { value, is_ref: true, child_refs: Vec::new() })
            }
            Event::StreamStart | Event::Nothing => None,
        }
    }

    fn document(&mut self) -> Option<Node> {
        self.aliases.clear();
        let node = self.element();
        // The end of the document must follow; an error reading it is
        // ignored, as ext-yaml does.
        if self.next() && self.event != Some(Event::DocumentEnd) {
            return None;
        }
        node
    }

    fn mapping(&mut self, anchor: Option<Vec<u8>>) -> Option<Node> {
        let mut array = Array::default();
        let mut refs: Vec<ArrayKey> = Vec::new();
        while let Some(key) = self.element() {
            let key_event = self.event.clone();
            // ext-yaml 2.3 returns what it has so far when a value fails
            // (an unknown alias, an error), without reading the mapping's end.
            let Some(value) = self.element() else {
                return Some(self.anchored(anchor, Value::Array(array), refs));
            };
            let merge_key = matches!(&key_event, Some(Event::Scalar(s)) if s.not_quoted_or_tag_is(MERGE_TAG))
                && matches!(&key.value, Value::Str(s) if s.as_bytes() == b"<<");
            if merge_key && matches!(value.value, Value::Array(_)) {
                let Value::Array(source) = &value.value else { unreachable!() };
                if matches!(self.event, Some(Event::Alias(_))) {
                    merge(&mut array, source);
                } else {
                    for (k, v) in source.iter() {
                        if let Value::Array(inner) = v
                            && value.child_refs.iter().any(|r| r.as_ref() == k)
                        {
                            merge(&mut array, inner);
                        }
                    }
                }
                continue;
            }
            let offset = match &key.value {
                Value::Float(f) => ArrayKey::Int(dval_to_lval(*f)),
                Value::Bool(b) => ArrayKey::Int(i64::from(*b)),
                Value::Int(i) => ArrayKey::Int(*i),
                Value::Null => ArrayKey::Str(Str::default()),
                Value::Str(s) => ArrayKey::normalize(s.clone()),
                // Illegal offset type: the pair is dropped (PHP warns).
                _ => continue,
            };
            refs.retain(|r| *r != offset);
            if value.is_ref {
                refs.push(offset.clone());
            }
            array.set(offset, value.value);
        }
        if self.event != Some(Event::MappingEnd) {
            return None;
        }
        Some(self.anchored(anchor, Value::Array(array), refs))
    }

    fn sequence(&mut self, anchor: Option<Vec<u8>>) -> Option<Node> {
        let mut array = Array::default();
        let mut refs = Vec::new();
        while let Some(node) = self.element() {
            if node.is_ref {
                refs.push(ArrayKey::Int(array.len() as i64));
            }
            let _ = array.push(node.value);
        }
        if self.event != Some(Event::SequenceEnd) {
            return None;
        }
        Some(self.anchored(anchor, Value::Array(array), refs))
    }

    fn anchored(&mut self, anchor: Option<Vec<u8>>, value: Value, child_refs: Vec<ArrayKey>) -> Node {
        match anchor {
            Some(anchor) => {
                self.aliases.insert(anchor, value.clone());
                Node { value, is_ref: true, child_refs }
            }
            None => Node { value, is_ref: false, child_refs },
        }
    }
}

/// `zend_hash_merge($target, $source, overwrite: false)`: adds the entries
/// whose key `target` lacks.
fn merge(target: &mut Array, source: &Array) {
    for (k, v) in source.iter() {
        let key = k.to_owned();
        if !target.contains_key(&key) {
            target.set(key, v.clone());
        }
    }
}

/// `yaml_parse($input)`: the first document of `input` as a PHP value, or
/// `None` where PHP returns `false` (and warns): a malformed document or an
/// unknown alias. An input without a document is `null`.
///
/// PHP: `yaml_parse($input)` with `yaml.decode_timestamp`, `decode_binary`
/// and `decode_php` off (their defaults). Only the first document is read:
/// errors after it are not reported.
///
/// ```
/// use php_std::{Value, yaml};
///
/// let Some(Value::Array(config)) = yaml::parse(b"enabled: yes\nmode: 0755\nratio: 1.5\n") else { panic!() };
/// assert_eq!(config.get_str("enabled"), Some(&Value::Bool(true)));
/// assert_eq!(config.get_str("mode"), Some(&Value::Int(493)));
/// assert_eq!(config.get_str("ratio"), Some(&Value::Float(1.5)));
/// assert_eq!(yaml::parse(b"a: *missing"), None);
/// ```
///
/// ```php
/// $config = yaml_parse("enabled: yes\nmode: 0755\nratio: 1.5\n");
/// var_dump($config['enabled'], $config['mode'], $config['ratio']); // bool(true) int(493) float(1.5)
/// var_dump(@yaml_parse('a: *missing')); // bool(false)
/// ```
pub fn parse(input: &[u8]) -> Option<Value> {
    let parser = Parser::new(input)?;
    let mut state = State { parser, event: None, aliases: HashMap::new() };
    loop {
        if !state.next() {
            return None;
        }
        match state.event {
            Some(Event::StreamEnd) => return Some(Value::Null),
            Some(Event::DocumentStart) => return state.document().map(|n| n.value),
            Some(Event::Nothing) | None => return None,
            _ => {}
        }
    }
}

/// `eval_scalar()`: a scalar's PHP value.
fn eval_scalar(s: &Scalar) -> Value {
    let value = &s.value;
    let string = || Value::Str(Str::copy_from(value));
    if s.tag_is(NONSPECIFIC_TAG) || s.quoted_implicit {
        return string();
    }
    if is_null(s) {
        return Value::Null;
    }
    if let Some(b) = is_bool(s) {
        return Value::Bool(b);
    }
    if s.plain_implicit || s.tag_is(INT_TAG) || s.tag_is(FLOAT_TAG) {
        match numeric(value) {
            Some(Number::Int(i)) if !s.plain_implicit && s.tag_is(FLOAT_TAG) => return Value::Float(i as f64),
            Some(Number::Float(f)) if !s.plain_implicit && s.tag_is(INT_TAG) => {
                return Value::Int(dval_to_lval(f));
            }
            Some(Number::Int(i)) => return Value::Int(i),
            Some(Number::Float(f)) => return Value::Float(f),
            None if s.not_implicit_and_tag_is(FLOAT_TAG) => return Value::Float(str_to_float(value)),
            None if s.not_implicit_and_tag_is(INT_TAG) => return Value::Int(str_to_int(value)),
            None => {}
        }
    }
    // Timestamps, binary and PHP objects stay strings with the default ini.
    let _ = s.not_implicit_and_tag_is(TIMESTAMP_TAG);
    string()
}

/// `scalar_is_null()`.
fn is_null(s: &Scalar) -> bool {
    if s.quoted_implicit {
        return false;
    }
    if s.plain_implicit {
        let v = s.value.as_slice();
        return v.is_empty() || v == b"~" || v == b"NULL" || v == b"Null" || v == b"null";
    }
    s.tag_is(NULL_TAG)
}

/// `scalar_is_bool()`: `Some(bool)`, or `None` when the scalar is not one.
fn is_bool(s: &Scalar) -> Option<bool> {
    let v = s.value.as_slice();
    if s.not_quoted_or_tag_is(BOOL_TAG) {
        const TRUE: &[&[u8]] = &[b"Y", b"y", b"YES", b"Yes", b"yes", b"TRUE", b"True", b"true", b"ON", b"On", b"on"];
        const FALSE: &[&[u8]] =
            &[b"N", b"n", b"NO", b"No", b"no", b"FALSE", b"False", b"false", b"OFF", b"Off", b"off"];
        if TRUE.contains(&v) {
            return Some(true);
        }
        if FALSE.contains(&v) {
            return Some(false);
        }
        return None;
    }
    if s.not_implicit_and_tag_is(BOOL_TAG) {
        return Some(!(v.is_empty() || v == b"0"));
    }
    None
}

enum Number {
    Int(i64),
    Float(f64),
}

/// C's `strtol(s, NULL, base)` as musl implements it: optional whitespace,
/// sign and (base 16) `0x` prefix; saturates on overflow.
fn strtol(s: &[u8], base: u32) -> i64 {
    let mut i = 0;
    while i < s.len() && (s[i] == b' ' || (b'\t'..=b'\r').contains(&s[i])) {
        i += 1;
    }
    let negative = s.get(i) == Some(&b'-');
    if matches!(s.get(i), Some(b'-' | b'+')) {
        i += 1;
    }
    if base == 16 && s.get(i) == Some(&b'0') && matches!(s.get(i + 1), Some(b'x' | b'X')) {
        i += 2;
    }
    let mut n: i128 = 0;
    while let Some(d) = s.get(i).and_then(|&c| (c as char).to_digit(base)) {
        n = (n * i128::from(base) + i128::from(d)).min(i128::from(u64::MAX) + 1);
        i += 1;
    }
    let n = if negative { -n } else { n };
    n.clamp(i128::from(i64::MIN), i128::from(i64::MAX)) as i64
}

/// C's `atol` as musl implements it: optional whitespace and sign, then
/// digits accumulated with wrapping.
fn atol(s: &[u8]) -> i64 {
    let mut i = 0;
    while i < s.len() && (s[i] == b' ' || (b'\t'..=b'\r').contains(&s[i])) {
        i += 1;
    }
    let negative = s.get(i) == Some(&b'-');
    if matches!(s.get(i), Some(b'-' | b'+')) {
        i += 1;
    }
    let mut n: i64 = 0;
    while let Some(&c) = s.get(i).filter(|c| c.is_ascii_digit()) {
        n = n.wrapping_mul(10).wrapping_sub(i64::from(c - b'0'));
        i += 1;
    }
    if negative { n } else { n.wrapping_neg() }
}

/// `zend_strtod` of the leading decimal number.
fn strtod(s: &[u8]) -> f64 {
    str_to_float(s)
}

fn sexagesimal_l(buf: &[u8]) -> i64 {
    let mut n: i64 = 0;
    for part in buf.split(|c| !c.is_ascii_digit()).filter(|p| !p.is_empty()) {
        n = n.wrapping_mul(60).wrapping_add(strtol(part, 10));
    }
    n
}

fn sexagesimal_d(buf: &[u8]) -> f64 {
    let mut d = 0.0;
    let mut i = 0;
    loop {
        while i < buf.len() && buf[i] != b'.' && !buf[i].is_ascii_digit() {
            i += 1;
        }
        if i >= buf.len() || buf[i] == b'.' {
            return d;
        }
        d = d * 60.0 + strtod(&buf[i..]);
        while i < buf.len() && buf[i].is_ascii_digit() {
            i += 1;
        }
    }
}

/// `scalar_is_numeric()`: the number a plain scalar spells, if any.
fn numeric(input: &[u8]) -> Option<Number> {
    let mut v = input;
    while let Some((&last, rest)) = v.split_last() {
        if last == b' ' || last == b'\t' {
            v = rest;
        } else {
            break;
        }
    }
    while let Some((&first, rest)) = v.split_first() {
        if first == b' ' || first == b'\t' {
            v = rest;
        } else {
            break;
        }
    }
    if v.is_empty() || v == b"." {
        return None;
    }
    if v == b".NAN" || v == b".NaN" || v == b".nan" {
        return Some(Number::Float(f64::NAN));
    }
    let negative = v[0] == b'-';
    if v[0] == b'+' || v[0] == b'-' {
        v = &v[1..];
    }
    if v.is_empty() {
        return None;
    }
    if v == b".INF" || v == b".Inf" || v == b".inf" {
        return Some(Number::Float(if negative { f64::NEG_INFINITY } else { f64::INFINITY }));
    }
    // Bytes past the end read as the NUL terminator, as in C.
    let at = |i: usize| v.get(i).copied().unwrap_or(0);
    let end = v.len();
    let mut buf: Vec<u8> = Vec::with_capacity(end + 3);
    if negative {
        buf.push(b'-');
    }
    let mut i = 0;

    #[derive(PartialEq)]
    enum Kind {
        Binary,
        Octal,
        Hex,
        Decimal,
        SexaInt,
        SexaFloat,
        Float,
    }

    let kind;
    // Where to continue: sexagesimal or float checks.
    let mut goto_sexa = false;
    let mut goto_float = false;
    if at(0) == b'0' {
        buf.push(b'0');
        i = 1;
        if i == end {
            return Some(Number::Int(0));
        }
        match at(i) {
            b'b' => {
                buf.push(b'b');
                i += 1;
                if i == end {
                    return None;
                }
                while i < end && (at(i) == b'_' || at(i) == b'0') {
                    i += 1;
                }
                if i == end {
                    return Some(Number::Int(0));
                }
                while i < end {
                    match at(i) {
                        b'_' => i += 1,
                        c @ (b'0' | b'1') => {
                            buf.push(c);
                            i += 1;
                        }
                        _ => return None,
                    }
                }
                kind = Kind::Binary;
            }
            b'x' => {
                buf.push(b'x');
                i += 1;
                if i == end {
                    return None;
                }
                while i < end && (at(i) == b'_' || at(i) == b'0') {
                    i += 1;
                }
                if i == end {
                    return Some(Number::Int(0));
                }
                while i < end {
                    match at(i) {
                        b'_' => i += 1,
                        c if c.is_ascii_hexdigit() => {
                            buf.push(c);
                            i += 1;
                        }
                        _ => return None,
                    }
                }
                kind = Kind::Hex;
            }
            c if c == b'_' || (b'0'..=b'7').contains(&c) => {
                while i < end {
                    match at(i) {
                        b'_' => i += 1,
                        c @ b'0'..=b'7' => {
                            buf.push(c);
                            i += 1;
                        }
                        _ => return None,
                    }
                }
                kind = Kind::Octal;
            }
            b'.' => {
                goto_float = true;
                kind = Kind::Float;
            }
            b':' => {
                goto_sexa = true;
                kind = Kind::SexaInt;
            }
            _ => return None,
        }
    } else if (b'1'..=b'9').contains(&at(0)) {
        buf.push(at(0));
        i = 1;
        let mut k = Kind::Decimal;
        while i < end {
            match at(i) {
                b'_' | b',' => i += 1,
                c if c.is_ascii_digit() => {
                    buf.push(c);
                    i += 1;
                }
                b':' => {
                    goto_sexa = true;
                    k = Kind::SexaInt;
                    break;
                }
                b'.' => {
                    goto_float = true;
                    k = Kind::Float;
                    break;
                }
                _ => return None,
            }
        }
        kind = k;
    } else if at(0) == b':' {
        goto_sexa = true;
        kind = Kind::SexaInt;
    } else if at(0) == b'.' {
        buf.push(b'0');
        goto_float = true;
        kind = Kind::Float;
    } else {
        return None;
    }

    let mut kind = kind;
    if goto_sexa {
        let mut float = false;
        while i < end {
            if at(i) == b'.' {
                float = true;
                break;
            }
            if at(i) != b':' {
                return None;
            }
            buf.push(at(i));
            i += 1;
            if at(i + 1) == b':' || at(i + 1) == b'.' || i + 1 == end {
                if at(i).is_ascii_digit() {
                    buf.push(at(i));
                    i += 1;
                } else {
                    return None;
                }
            } else if (b'0'..=b'5').contains(&at(i)) && at(i + 1).is_ascii_digit() {
                buf.push(at(i));
                buf.push(at(i + 1));
                i += 2;
            } else {
                return None;
            }
        }
        if float || at(i) == b'.' {
            kind = Kind::SexaFloat;
            goto_float = true;
        } else if i == end {
            kind = Kind::SexaInt;
        } else {
            return None;
        }
    }
    if goto_float {
        buf.push(at(i));
        i += 1;
        if kind == Kind::SexaFloat {
            let mut e = end;
            while i < e && (v[e - 1] == b'_' || v[e - 1] == b'0') {
                e -= 1;
            }
            if i == e {
                buf.push(b'0');
            }
            while i < e {
                match at(i) {
                    b'_' => i += 1,
                    c if c.is_ascii_digit() => {
                        buf.push(c);
                        i += 1;
                    }
                    _ => return None,
                }
            }
        } else {
            while i < end {
                match at(i) {
                    b'_' => i += 1,
                    c if c.is_ascii_digit() => {
                        buf.push(c);
                        i += 1;
                    }
                    b'E' | b'e' => {
                        buf.push(at(i));
                        i += 1;
                        if i == end || (at(i) != b'+' && at(i) != b'-') {
                            return None;
                        }
                        buf.push(at(i));
                        i += 1;
                        if i == end || !at(i).is_ascii_digit() || (at(i) == b'0' && i + 1 == end) {
                            return None;
                        }
                        buf.push(at(i));
                        i += 1;
                        while i < end {
                            if at(i).is_ascii_digit() {
                                buf.push(at(i));
                                i += 1;
                            } else {
                                return None;
                            }
                        }
                    }
                    _ => return None,
                }
            }
            kind = Kind::Float;
        }
    }

    Some(match kind {
        Kind::Binary => {
            let digits = &buf[if negative { 3 } else { 2 }..];
            let n = strtol(digits, 2);
            Number::Int(if negative { n.wrapping_neg() } else { n })
        }
        Kind::Octal => Number::Int(strtol(&buf, 8)),
        Kind::Hex => Number::Int(strtol(&buf, 16)),
        Kind::SexaInt => {
            let n = sexagesimal_l(&buf);
            Number::Int(if negative { n.wrapping_neg() } else { n })
        }
        Kind::SexaFloat => {
            let d = sexagesimal_d(&buf);
            Number::Float(if negative { -d } else { d })
        }
        Kind::Decimal => Number::Int(atol(&buf)),
        Kind::Float => Number::Float(strtod(&buf)),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    fn int(s: &str) -> Option<i64> {
        match numeric(s.as_bytes()) {
            Some(Number::Int(i)) => Some(i),
            _ => None,
        }
    }

    #[test]
    fn numbers() {
        assert_eq!(int("99999999999999999999"), Some(7766279631452241919));
        assert_eq!(int("0x7FFFFFFFFFFFFFFFFF"), Some(i64::MAX));
        assert_eq!(int("1,000"), Some(1000));
        assert_eq!(int("1:20:30"), Some(4830));
        assert_eq!(int("-0b11"), Some(-3));
        assert_eq!(int("0o17"), None);
        assert_eq!(int("08"), None);
        assert!(matches!(numeric(b"190:20:30.15"), Some(Number::Float(f)) if (f - 685230.15).abs() < 1e-9));
        assert!(numeric(b"1e3").is_none());
    }
}
