//! A streaming JSON pull parser: the items of a top-level array (or the
//! key/value pairs of a top-level object), decoded one at a time from a file
//! read in 16 KiB chunks, so a large export never sits in memory whole.
//!
//! PHP: `halaxa/json-machine` 1.2 (`JsonMachine\Items::fromFile($file,
//! ['decoder' => ...])` with the default JSON pointer), which
//! `Sources\JSON` streams its input with. Its tokenizer and parser are
//! ported as they are, quirks included: tokens are split at quotes,
//! structural characters, ASCII whitespace and the three UTF-8 BOM bytes;
//! anything after the top-level value closes is ignored; scalars are only
//! validated when their item is decoded; and error positions are always 0
//! (the parser only tracks positions in debug mode).

use std::collections::VecDeque;

use php_std::json::Flags;
use php_std::{ArrayKey, Value};
use tokio::io::AsyncReadExt;

use crate::error::{Error, JsonMachineKind, Result};

/// The chunk size `FileChunks` reads.
pub const CHUNK_SIZE: usize = 1024 * 16;

/// How items are decoded.
///
/// PHP: `JsonMachine\JsonDecoder\ItemDecoder` implementations.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Decoder {
    /// `ExtJsonDecoder($assoc)`: `json_decode` of each item.
    Ext {
        /// Objects as arrays.
        assoc: bool,
    },
    /// `PassThruDecoder`: each item's JSON text, as a string.
    PassThru,
}

impl Decoder {
    fn decode(self, json: &[u8]) -> std::result::Result<Value, String> {
        match self {
            Decoder::PassThru => Ok(Value::Str(php_std::Str::from(json))),
            Decoder::Ext { assoc } => {
                php_std::json::decode(json, Some(assoc), 512, Flags::NONE).map_err(|e| e.message().to_owned())
            }
        }
    }
}

/// `Tokens`: splits chunks into JSON tokens.
#[derive(Debug, Default)]
struct Tokenizer {
    in_string: bool,
    carry: Option<Vec<u8>>,
    out: VecDeque<Vec<u8>>,
}

fn is_ws(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')
}

fn is_structural(b: u8) -> bool {
    matches!(b, b'{' | b'}' | b'[' | b']' | b',' | b':')
}

/// `preg_match_all('/ [{}\[\],:] | [^\xEF\xBB\xBF\s{}\[\],:]+ /x', $s)`.
fn regex_tokens(s: &[u8]) -> Vec<Vec<u8>> {
    let mut out = Vec::new();
    let mut i = 0;
    while i < s.len() {
        let b = s[i];
        if is_structural(b) {
            out.push(vec![b]);
            i += 1;
        } else if is_ws(b) || matches!(b, 0xEF | 0xBB | 0xBF) {
            i += 1;
        } else {
            let start = i;
            while i < s.len() && !is_structural(s[i]) && !is_ws(s[i]) && !matches!(s[i], 0xEF | 0xBB | 0xBF) {
                i += 1;
            }
            out.push(s[start..i].to_vec());
        }
    }
    out
}

/// PHP `trim()` with its default characters.
fn trim(s: &[u8]) -> &[u8] {
    let strip = |b: &u8| matches!(*b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B);
    let start = s.iter().position(|b| !strip(b)).unwrap_or(s.len());
    let end = s.iter().rposition(|b| !strip(b)).map_or(start, |i| i + 1);
    &s[start..end.max(start)]
}

fn string_is_escaping(token: &[u8]) -> bool {
    token.iter().rev().take_while(|b| **b == b'\\').count() % 2 != 0
}

impl Tokenizer {
    fn feed(&mut self, chunk: &[u8]) {
        let mut joined = self.carry.take().unwrap_or_default();
        joined.extend_from_slice(chunk);
        let blocks: Vec<&[u8]> = joined.split(|b| *b == b'"').collect();
        let mut carry: Vec<u8> = Vec::new();
        let last = blocks.len() - 1;
        for block in &blocks[..last] {
            if self.in_string {
                if string_is_escaping(block) {
                    carry.extend_from_slice(block);
                    carry.push(b'"');
                } else {
                    let mut t = Vec::with_capacity(carry.len() + block.len() + 2);
                    t.push(b'"');
                    t.extend_from_slice(&carry);
                    t.extend_from_slice(block);
                    t.push(b'"');
                    self.out.push_back(t);
                    carry.clear();
                    self.in_string = false;
                }
            } else {
                let b = trim(block);
                if b.len() == 1 {
                    self.out.push_back(b.to_vec());
                } else {
                    self.out.extend(regex_tokens(b));
                }
                self.in_string = true;
            }
        }
        if self.in_string {
            carry.extend_from_slice(blocks[last]);
            self.carry = Some(carry);
        } else {
            let mut matches = regex_tokens(blocks[last]);
            self.carry = matches.pop();
            self.out.extend(matches);
        }
    }

    fn finish(&mut self) {
        if let Some(carry) = self.carry.take()
            && !carry.is_empty()
        {
            let mut t = Vec::new();
            if self.in_string {
                t.push(b'"');
            }
            t.extend_from_slice(&carry);
            self.out.push_back(t);
        }
    }
}

const SCALAR_CONST: u8 = 1;
const SCALAR_STRING: u8 = 2;
const OBJECT_START: u8 = 4;
const OBJECT_END: u8 = 8;
const ARRAY_START: u8 = 16;
const ARRAY_END: u8 = 32;
const COMMA: u8 = 64;
const COLON: u8 = 128;

fn token_type(b: u8) -> u8 {
    match b {
        b'n' | b't' | b'f' | b'-' | b'0'..=b'9' => SCALAR_CONST,
        b'"' => SCALAR_STRING,
        b'{' => OBJECT_START,
        b'}' => OBJECT_END,
        b'[' => ARRAY_START,
        b']' => ARRAY_END,
        b',' => COMMA,
        b':' => COLON,
        _ => 0,
    }
}

/// The source of a stream's bytes.
#[derive(Debug)]
enum Input {
    File(tokio::fs::File),
    Bytes(Option<Vec<u8>>),
}

/// The items of a JSON document, pulled one at a time.
///
/// PHP: `JsonMachine\Items` (with its `Parser` and `Tokens`).
#[derive(Debug)]
pub struct Items {
    input: Input,
    eof: bool,
    tokens: Tokenizer,
    decoder: Decoder,
    // Parser state.
    level: i64,
    stack: Vec<u8>,
    json_value: Vec<u8>,
    key: Vec<u8>,
    object_key_expected: bool,
    in_object: Option<bool>,
    expected: u8,
    iterator_struct: Option<u8>,
    last_token: Option<Vec<u8>>,
    next_index: i64,
    done: bool,
}

fn syntax(kind: JsonMachineKind, message: String, token: &[u8]) -> Error {
    Error::JsonMachine { kind, message: format!("{message} '{}' At position 0.", String::from_utf8_lossy(token)) }
}

impl Items {
    fn with_input(input: Input, decoder: Decoder) -> Self {
        Self {
            input,
            eof: false,
            tokens: Tokenizer::default(),
            decoder,
            level: -1,
            stack: Vec::new(),
            json_value: Vec::new(),
            key: Vec::new(),
            object_key_expected: false,
            in_object: None,
            expected: OBJECT_START | ARRAY_START,
            iterator_struct: None,
            last_token: None,
            next_index: 0,
            done: false,
        }
    }

    /// The items of the file at `path`.
    ///
    /// PHP: `Items::fromFile($path, ['decoder' => ...])`. The file is opened
    /// on the first pull, as PHP's generator opens it on the first iteration.
    pub async fn from_file(path: &str, decoder: Decoder) -> Result<Self> {
        let file = tokio::fs::File::open(path)
            .await
            .map_err(|e| Error::exception(format!("fopen({path}): Failed to open stream: {}", io_reason(&e)), 0))?;
        Ok(Self::with_input(Input::File(file), decoder))
    }

    /// The items of `json`, fed as one chunk.
    ///
    /// PHP: `Items::fromString($json, ...)`.
    pub fn from_bytes(json: impl Into<Vec<u8>>, decoder: Decoder) -> Self {
        Self::with_input(Input::Bytes(Some(json.into())), decoder)
    }

    async fn next_token(&mut self) -> Result<Option<Vec<u8>>> {
        loop {
            if let Some(t) = self.tokens.out.pop_front() {
                return Ok(Some(t));
            }
            if self.eof {
                return Ok(None);
            }
            let chunk = match &mut self.input {
                Input::Bytes(b) => b.take().unwrap_or_default(),
                Input::File(f) => {
                    let mut buf = vec![0u8; CHUNK_SIZE];
                    let mut filled = 0;
                    while filled < CHUNK_SIZE {
                        let n = f.read(&mut buf[filled..]).await.map_err(|e| Error::exception(e.to_string(), 0))?;
                        if n == 0 {
                            break;
                        }
                        filled += n;
                    }
                    buf.truncate(filled);
                    buf
                }
            };
            if chunk.is_empty() {
                self.eof = true;
                self.tokens.finish();
            } else {
                self.tokens.feed(&chunk);
            }
        }
    }

    /// The next item: its key (the array index, or the object key) and
    /// value; `None` after the last one.
    pub async fn next(&mut self) -> Result<Option<(Value, Value)>> {
        if self.done {
            return Ok(None);
        }
        loop {
            let Some(token) = self.next_token().await? else {
                self.done = true;
                return match &self.last_token {
                    None => Err(syntax(JsonMachineKind::Syntax, "Cannot iterate empty JSON".into(), b"")),
                    Some(t) if self.level > -1 => {
                        Err(syntax(JsonMachineKind::UnexpectedEnd, "JSON string ended unexpectedly".into(), t))
                    }
                    Some(_) => Ok(None),
                };
            };
            self.last_token = Some(token.clone());
            let ty = token_type(token[0]);
            if ty & self.expected == 0 {
                self.done = true;
                return Err(syntax(JsonMachineKind::Syntax, "Unexpected symbol".into(), &token));
            }
            let is_value = (ty | 23) == 23;
            if self.level > 0 || (!self.object_key_expected && self.level == 0 && is_value) {
                self.json_value.extend_from_slice(&token);
            }
            let in_object = self.in_object == Some(true);
            match token[0] {
                b'"' => {
                    if self.object_key_expected {
                        self.object_key_expected = false;
                        self.expected = COLON;
                        if self.level == 0 {
                            self.key = token.clone();
                        }
                        continue;
                    }
                    self.expected = if in_object { 72 } else { 96 };
                }
                b',' => {
                    if in_object {
                        self.object_key_expected = true;
                        self.expected = SCALAR_STRING;
                    } else {
                        self.expected = 23;
                    }
                    continue;
                }
                b':' => {
                    self.expected = 23;
                    continue;
                }
                b'{' | b'[' => {
                    self.level += 1;
                    if self.level <= 0 {
                        self.iterator_struct = Some(token[0]);
                    }
                    self.stack.truncate(self.level as usize);
                    self.stack.push(token[0]);
                    if token[0] == b'{' {
                        self.in_object = Some(true);
                        self.expected = 10;
                        self.object_key_expected = true;
                    } else {
                        self.in_object = Some(false);
                        self.expected = 55;
                    }
                    continue;
                }
                b'}' | b']' => {
                    if token[0] == b'}' {
                        self.object_key_expected = false;
                    }
                    self.level -= 1;
                    if self.level < 0 {
                        self.iterator_struct = None;
                    }
                    let in_obj = self.level >= 0 && self.stack.get(self.level as usize) == Some(&b'{');
                    self.in_object = Some(in_obj);
                    self.expected = if in_obj { 72 } else { 96 };
                }
                _ => {
                    self.expected = if in_object { 72 } else { 96 };
                }
            }
            if self.level > 0 {
                continue;
            }
            let mut item = None;
            if !self.json_value.is_empty() {
                let json = std::mem::take(&mut self.json_value);
                let value = match self.decoder.decode(&json) {
                    Ok(v) => v,
                    Err(m) => {
                        self.done = true;
                        return Err(syntax(JsonMachineKind::Syntax, m, &token));
                    }
                };
                if self.iterator_struct == Some(b'[') {
                    let k = Value::Int(self.next_index);
                    self.next_index += 1;
                    item = Some((k, value));
                } else {
                    let key = match self.decoder.decode(&self.key) {
                        Ok(k) => k,
                        Err(m) => {
                            self.done = true;
                            let key = self.key.clone();
                            return Err(syntax(JsonMachineKind::Syntax, m, &key));
                        }
                    };
                    // `yield $key => $value`: a numeric string key stays a string
                    // for the consumer, but integer-like keys behave as PHP array keys.
                    let key = match key {
                        Value::Str(s) => match ArrayKey::normalize(s.clone()) {
                            ArrayKey::Int(_) | ArrayKey::Str(_) => Value::Str(s),
                        },
                        other => other,
                    };
                    item = Some((key, value));
                }
            }
            if self.level < 0 {
                self.done = true;
            }
            if let Some(item) = item {
                return Ok(Some(item));
            }
            if self.done {
                return Ok(None);
            }
        }
    }

    /// Counts the remaining items (PHP: `iterator_count($items)`).
    pub async fn count(mut self) -> Result<i64> {
        let mut n = 0;
        while self.next().await?.is_some() {
            n += 1;
        }
        Ok(n)
    }
}

fn io_reason(e: &std::io::Error) -> &'static str {
    match e.kind() {
        std::io::ErrorKind::NotFound => "No such file or directory",
        std::io::ErrorKind::PermissionDenied => "Permission denied",
        _ => "Is a directory",
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    async fn all(json: &str) -> Result<Vec<(Value, Value)>> {
        let mut items = Items::from_bytes(json, Decoder::Ext { assoc: true });
        let mut out = Vec::new();
        while let Some(i) = items.next().await? {
            out.push(i);
        }
        Ok(out)
    }

    #[tokio::test]
    async fn arrays_and_errors() {
        assert_eq!(all("[1, {\"a\": [2]}, \"x\"]").await.unwrap().len(), 3);
        assert_eq!(all("[]").await.unwrap().len(), 0);
        assert_eq!(all("").await.unwrap_err().message(), "Cannot iterate empty JSON '' At position 0.");
        assert_eq!(all("1").await.unwrap_err().message(), "Unexpected symbol '1' At position 0.");
        assert_eq!(all("[1").await.unwrap_err().message(), "JSON string ended unexpectedly '1' At position 0.");
        assert_eq!(all("[tru]").await.unwrap_err().message(), "Syntax error 'tru' At position 0.");
        assert_eq!(all("[1] trailing").await.unwrap().len(), 1);
    }
}
