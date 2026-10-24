//! Evaluates PHP config files: a script that returns a constant expression.
//!
//! Rust cannot run PHP, so this reads the part of PHP a config file uses: an
//! opening `<?php` tag, optional `declare(...)`, `namespace` and `use`
//! statements, and `return <expression>;`, where the expression is built
//! from literals (integers, floats, single- and double-quoted strings,
//! `true`, `false`, `null`), arrays (`[...]`, `array(...)`, with keys),
//! a few engine constants (`PHP_EOL`, `PHP_INT_MAX`, ...), parentheses,
//! unary `+`, `-` and `!`, and the operators `**`, `*`, `/`, `%`, `+`, `-`
//! and `.`. Values, array keys and arithmetic follow the engine (integer
//! overflow to float, numeric strings, division by zero), and the errors PHP
//! raises for the same script (unclosed brackets, unexpected tokens,
//! undefined constants) have the same messages.
//!
//! Anything else (variables, function calls, string interpolation, heredoc)
//! is refused with an [`Unsupported`](Failure::Unsupported) error.

use php_std::{Array, ArrayKey, Str, Value};

/// Why a script could not be evaluated.
#[derive(Debug, Clone, PartialEq)]
pub(crate) enum Failure {
    /// PHP would throw: the message of the `ParseError`, `Error`,
    /// `TypeError` or `DivisionByZeroError`.
    Thrown(String),
    /// The script uses PHP beyond what this evaluator reads.
    Unsupported(String),
}

#[derive(Debug, Clone, PartialEq)]
enum Token {
    Int(i64),
    Float(f64),
    /// A string literal and how PHP names its kind in errors.
    Str(Vec<u8>, &'static str),
    Ident(String),
    Punct(&'static str),
    Eof,
}

impl Token {
    /// How PHP names the token in "unexpected ..." messages.
    fn describe(&self, source: &[u8], span: (usize, usize)) -> String {
        let text = String::from_utf8_lossy(&source[span.0..span.1]).into_owned();
        match self {
            Token::Int(_) => format!("integer \"{text}\""),
            Token::Float(_) => format!("floating-point number \"{text}\""),
            Token::Str(value, kind) => format!("{kind} \"{}\"", String::from_utf8_lossy(value)),
            Token::Ident(name) => match name.to_ascii_lowercase().as_str() {
                "return" | "array" | "declare" | "namespace" | "use" => format!("token \"{name}\""),
                _ => format!("identifier \"{name}\""),
            },
            Token::Punct(p) => format!("token \"{p}\""),
            Token::Eof => "end of file".to_owned(),
        }
    }
}

const PUNCTS: &[&str] =
    &["**", "=>", "[", "]", "(", ")", "{", "}", ",", ";", "+", "-", "*", "/", "%", ".", "!", "=", "\\"];

struct Lexer<'a> {
    src: &'a [u8],
    pos: usize,
    line: usize,
    /// Open brackets and the line each opened on.
    brackets: Vec<(u8, usize)>,
}

impl<'a> Lexer<'a> {
    fn at(&self, i: usize) -> u8 {
        self.src.get(i).copied().unwrap_or(0)
    }

    fn skip_trivia(&mut self) -> Result<(), Failure> {
        loop {
            let c = self.at(self.pos);
            if self.pos >= self.src.len() {
                return Ok(());
            }
            if c == b'\n' {
                self.line += 1;
                self.pos += 1;
            } else if c.is_ascii_whitespace() {
                self.pos += 1;
            } else if c == b'#' && self.at(self.pos + 1) == b'[' {
                return Err(Failure::Unsupported("attributes".into()));
            } else if c == b'#' || (c == b'/' && self.at(self.pos + 1) == b'/') {
                while self.pos < self.src.len() && self.at(self.pos) != b'\n' {
                    if self.at(self.pos) == b'?' && self.at(self.pos + 1) == b'>' {
                        break;
                    }
                    self.pos += 1;
                }
                if self.at(self.pos) == b'?' {
                    continue;
                }
            } else if c == b'/' && self.at(self.pos + 1) == b'*' {
                let end = self.src[self.pos + 2..].windows(2).position(|w| w == b"*/");
                let stop = end.map_or(self.src.len(), |e| self.pos + 2 + e + 2);
                self.line += self.src[self.pos..stop].iter().filter(|&&b| b == b'\n').count();
                self.pos = stop;
            } else if c == b'?' && self.at(self.pos + 1) == b'>' {
                // A closing tag: text up to the next opening tag is output,
                // which a config file never meant; it is skipped.
                let rest = &self.src[self.pos + 2..];
                match rest.windows(5).position(|w| w == b"<?php") {
                    Some(p) => {
                        self.line += rest[..p].iter().filter(|&&b| b == b'\n').count();
                        self.pos += 2 + p + 5;
                    }
                    None => self.pos = self.src.len(),
                }
            } else {
                return Ok(());
            }
        }
    }

    fn next(&mut self) -> Result<(Token, (usize, usize)), Failure> {
        self.skip_trivia()?;
        let start = self.pos;
        if start >= self.src.len() {
            if let Some((open, line)) = self.brackets.last() {
                let on = if *line != self.line { format!(" on line {line}") } else { String::new() };
                return Err(Failure::Thrown(format!("Unclosed '{}'{on}", *open as char)));
            }
            return Ok((Token::Eof, (start, start)));
        }
        let c = self.at(start);
        let token = if c.is_ascii_digit() || (c == b'.' && self.at(start + 1).is_ascii_digit()) {
            self.number()?
        } else if c == b'\'' {
            self.single()?
        } else if c == b'"' {
            self.double()?
        } else if c.is_ascii_alphabetic() || c == b'_' || c >= 0x80 {
            while self.pos < self.src.len() {
                let c = self.at(self.pos);
                if c.is_ascii_alphanumeric() || c == b'_' || c >= 0x80 {
                    self.pos += 1;
                } else {
                    break;
                }
            }
            Token::Ident(String::from_utf8_lossy(&self.src[start..self.pos]).into_owned())
        } else if c == b'$' {
            return Err(Failure::Unsupported("variables".into()));
        } else {
            let Some(p) = PUNCTS.iter().find(|p| self.src[start..].starts_with(p.as_bytes())) else {
                return Err(Failure::Unsupported(format!("the character '{}'", c as char)));
            };
            self.pos += p.len();
            match *p {
                "[" | "(" | "{" => self.brackets.push((p.as_bytes()[0], self.line)),
                "]" | ")" | "}" => {
                    let want = match *p {
                        "]" => b'[',
                        ")" => b'(',
                        _ => b'{',
                    };
                    match self.brackets.pop() {
                        None => return Err(Failure::Thrown(format!("Unmatched '{p}'"))),
                        Some((open, line)) if open != want => {
                            let on = if line != self.line { format!(" on line {line}") } else { String::new() };
                            return Err(Failure::Thrown(format!(
                                "Unclosed '{}'{on} does not match '{p}'",
                                open as char
                            )));
                        }
                        Some(_) => {}
                    }
                }
                _ => {}
            }
            Token::Punct(p)
        };
        Ok((token, (start, self.pos)))
    }

    fn number(&mut self) -> Result<Token, Failure> {
        let start = self.pos;
        let radix = |s: &Self, i: usize| -> Option<u32> {
            match (s.at(i), s.at(i + 1)) {
                (b'0', b'x' | b'X') => Some(16),
                (b'0', b'b' | b'B') => Some(2),
                (b'0', b'o' | b'O') => Some(8),
                _ => None,
            }
        };
        let digits = |s: &mut Self, ok: &dyn Fn(u8) -> bool| -> Vec<u8> {
            let mut out = Vec::new();
            while s.pos < s.src.len() {
                let c = s.at(s.pos);
                if ok(c) {
                    out.push(c);
                    s.pos += 1;
                } else if c == b'_' && ok(s.at(s.pos + 1)) && !out.is_empty() {
                    s.pos += 1;
                } else {
                    break;
                }
            }
            out
        };
        if let Some(base) = radix(self, start) {
            self.pos += 2;
            let d = digits(self, &|c: u8| (c as char).is_digit(base));
            return Ok(int_or_float(&d, base));
        }
        let mut text = digits(self, &|c: u8| c.is_ascii_digit());
        let mut float = false;
        if self.at(self.pos) == b'.'
            && (self.at(self.pos + 1).is_ascii_digit() || !self.at(self.pos + 1).is_ascii_alphabetic())
            && self.at(self.pos + 1) != b'.'
        {
            float = true;
            self.pos += 1;
            text.push(b'.');
            text.extend(digits(self, &|c: u8| c.is_ascii_digit()));
        }
        if matches!(self.at(self.pos), b'e' | b'E') {
            let sign = matches!(self.at(self.pos + 1), b'+' | b'-');
            let first = self.at(self.pos + 1 + usize::from(sign));
            if first.is_ascii_digit() {
                float = true;
                text.push(b'e');
                if sign {
                    text.push(self.at(self.pos + 1));
                }
                self.pos += 1 + usize::from(sign);
                text.extend(digits(self, &|c: u8| c.is_ascii_digit()));
            }
        }
        if float {
            let s = String::from_utf8_lossy(&text);
            let s = if s.starts_with('.') { format!("0{s}") } else { s.into_owned() };
            return Ok(Token::Float(s.parse().unwrap_or(0.0)));
        }
        // A leading zero makes an octal literal.
        if text.len() > 1 && text[0] == b'0' {
            if text.iter().any(|&c| c > b'7') {
                return Err(Failure::Thrown("Invalid numeric literal".into()));
            }
            return Ok(int_or_float(&text[1..], 8));
        }
        Ok(int_or_float(&text, 10))
    }

    fn single(&mut self) -> Result<Token, Failure> {
        let mut out = Vec::new();
        self.pos += 1;
        loop {
            if self.pos >= self.src.len() {
                return Err(Failure::Unsupported("an unterminated string".into()));
            }
            let c = self.at(self.pos);
            if c == b'\'' {
                self.pos += 1;
                break;
            }
            if c == b'\\' && matches!(self.at(self.pos + 1), b'\\' | b'\'') {
                out.push(self.at(self.pos + 1));
                self.pos += 2;
                continue;
            }
            if c == b'\n' {
                self.line += 1;
            }
            out.push(c);
            self.pos += 1;
        }
        Ok(Token::Str(out, "single-quoted string"))
    }

    fn double(&mut self) -> Result<Token, Failure> {
        let mut out = Vec::new();
        self.pos += 1;
        loop {
            if self.pos >= self.src.len() {
                return Err(Failure::Unsupported("an unterminated string".into()));
            }
            let c = self.at(self.pos);
            match c {
                b'"' => {
                    self.pos += 1;
                    break;
                }
                b'$' if self.at(self.pos + 1).is_ascii_alphabetic()
                    || self.at(self.pos + 1) == b'_'
                    || self.at(self.pos + 1) == b'{'
                    || self.at(self.pos + 1) >= 0x80 =>
                {
                    return Err(Failure::Unsupported("string interpolation".into()));
                }
                b'{' if self.at(self.pos + 1) == b'$' => {
                    return Err(Failure::Unsupported("string interpolation".into()));
                }
                b'\\' => {
                    let e = self.at(self.pos + 1);
                    self.pos += 2;
                    match e {
                        b'n' => out.push(b'\n'),
                        b't' => out.push(b'\t'),
                        b'r' => out.push(b'\r'),
                        b'v' => out.push(0x0b),
                        b'e' => out.push(0x1b),
                        b'f' => out.push(0x0c),
                        b'\\' => out.push(b'\\'),
                        b'$' => out.push(b'$'),
                        b'"' => out.push(b'"'),
                        b'0'..=b'7' => {
                            let mut n = u32::from(e - b'0');
                            for _ in 0..2 {
                                if (b'0'..=b'7').contains(&self.at(self.pos)) {
                                    n = n * 8 + u32::from(self.at(self.pos) - b'0');
                                    self.pos += 1;
                                }
                            }
                            out.push(n as u8);
                        }
                        b'x' if self.at(self.pos).is_ascii_hexdigit() => {
                            let mut n = 0u32;
                            for _ in 0..2 {
                                if let Some(d) = (self.at(self.pos) as char).to_digit(16) {
                                    n = n * 16 + d;
                                    self.pos += 1;
                                }
                            }
                            out.push(n as u8);
                        }
                        b'u' if self.at(self.pos) == b'{' => {
                            let close = self.src[self.pos..].iter().position(|&b| b == b'}');
                            let hex = close.map(|e| &self.src[self.pos + 1..self.pos + e]);
                            let ch = hex
                                .and_then(|h| u32::from_str_radix(std::str::from_utf8(h).ok()?, 16).ok())
                                .and_then(char::from_u32)
                                .ok_or_else(|| Failure::Unsupported("this \\u escape".into()))?;
                            let mut buf = [0u8; 4];
                            out.extend_from_slice(ch.encode_utf8(&mut buf).as_bytes());
                            self.pos += close.unwrap_or(0) + 1;
                        }
                        other => {
                            out.push(b'\\');
                            self.pos -= 1;
                            let _ = other;
                        }
                    }
                }
                _ => {
                    if c == b'\n' {
                        self.line += 1;
                    }
                    out.push(c);
                    self.pos += 1;
                }
            }
        }
        Ok(Token::Str(out, "double-quoted string"))
    }
}

/// An integer literal in `base`, or a float when it overflows.
fn int_or_float(digits: &[u8], base: u32) -> Token {
    let mut n: i64 = 0;
    let mut f: f64 = 0.0;
    let mut overflow = false;
    for &c in digits {
        let d = (c as char).to_digit(base).unwrap_or(0);
        f = f * f64::from(base) + f64::from(d);
        match n.checked_mul(i64::from(base)).and_then(|n| n.checked_add(i64::from(d))) {
            Some(v) => n = v,
            None => overflow = true,
        }
    }
    if overflow {
        let text = String::from_utf8_lossy(digits);
        if base == 10 {
            return Token::Float(text.parse().unwrap_or(f));
        }
        return Token::Float(f);
    }
    Token::Int(n)
}

struct Parser<'a> {
    lexer: Lexer<'a>,
    token: Token,
    span: (usize, usize),
    /// The first runtime error, raised once the whole script parsed.
    runtime: Option<String>,
    /// Whether a `return` ran: later runtime errors do not happen.
    returned: bool,
}

/// A number operand of an arithmetic operator.
#[derive(Clone, Copy)]
enum Num {
    Int(i64),
    Float(f64),
}

impl Num {
    fn f(self) -> f64 {
        match self {
            Num::Int(i) => i as f64,
            Num::Float(f) => f,
        }
    }
}

impl<'a> Parser<'a> {
    fn bump(&mut self) -> Result<(), Failure> {
        let (token, span) = self.lexer.next()?;
        self.token = token;
        self.span = span;
        Ok(())
    }

    fn unexpected(&self, expecting: Option<&str>) -> Failure {
        let what = self.token.describe(self.lexer.src, self.span);
        match expecting {
            Some(e) => Failure::Thrown(format!("syntax error, unexpected {what}, expecting \"{e}\"")),
            None => Failure::Thrown(format!("syntax error, unexpected {what}")),
        }
    }

    fn is(&self, p: &str) -> bool {
        matches!(&self.token, Token::Punct(q) if *q == p)
    }

    fn keyword(&self, k: &str) -> bool {
        matches!(&self.token, Token::Ident(name) if name.eq_ignore_ascii_case(k))
    }

    fn expect(&mut self, p: &str) -> Result<(), Failure> {
        if !self.is(p) {
            return Err(self.unexpected(Some(p)));
        }
        self.bump()
    }

    /// A runtime error (an `Error` PHP throws while running the script): PHP
    /// parses the whole file before running it, so syntax errors anywhere
    /// win. The first one before the script returns is kept, and parsing goes
    /// on with a null value.
    fn run(&mut self, result: Result<Value, Failure>) -> Result<Value, Failure> {
        match result {
            Err(Failure::Thrown(message)) => {
                if !self.returned && self.runtime.is_none() {
                    self.runtime = Some(message);
                }
                Ok(Value::Null)
            }
            other => other,
        }
    }

    /// Ends a statement: `;`, or a syntax error.
    fn end_statement(&mut self, expecting: Option<&str>) -> Result<(), Failure> {
        if !self.is(";") {
            return Err(self.unexpected(expecting));
        }
        self.bump()
    }

    /// The statements of the script: the value it returns, or `None` when it
    /// does not return.
    fn script(&mut self) -> Result<Option<Value>, Failure> {
        let mut result = None;
        while self.token != Token::Eof {
            if self.is(";") {
                self.bump()?;
            } else if self.keyword("return") {
                self.bump()?;
                let value = if self.is(";") || self.token == Token::Eof { Value::Null } else { self.expr(0)? };
                self.end_statement(Some(";"))?;
                if !self.returned {
                    self.returned = true;
                    result = Some(value);
                }
            } else if self.keyword("declare") {
                self.bump()?;
                self.expect("(")?;
                while !self.is(")") {
                    if self.token == Token::Eof {
                        return Err(self.unexpected(Some(")")));
                    }
                    self.bump()?;
                }
                self.bump()?;
                if !self.is(";") {
                    return Err(Failure::Unsupported("a declare block".into()));
                }
            } else if self.keyword("namespace") || self.keyword("use") {
                while !self.is(";") {
                    if self.token == Token::Eof || self.is("{") {
                        return Err(Failure::Unsupported("this namespace or use statement".into()));
                    }
                    self.bump()?;
                }
            } else {
                // An expression statement: evaluated, its value dropped.
                self.expr(0)?;
                self.end_statement(None)?;
            }
        }
        if let Some(message) = self.runtime.take() {
            return Err(Failure::Thrown(message));
        }
        Ok(result)
    }

    fn expr(&mut self, min: u8) -> Result<Value, Failure> {
        let mut left = self.unary()?;
        loop {
            let (op, prec, right_assoc) = match &self.token {
                Token::Punct("**") => ("**", 6, true),
                Token::Punct(p @ ("*" | "/" | "%")) => (*p, 5, false),
                Token::Punct(p @ ("+" | "-")) => (*p, 4, false),
                Token::Punct(".") => (".", 3, false),
                _ => return Ok(left),
            };
            if prec < min {
                return Ok(left);
            }
            self.bump()?;
            let right = self.expr(if right_assoc { prec } else { prec + 1 })?;
            left = self.run(binary(op, &left, &right))?;
        }
    }

    fn unary(&mut self) -> Result<Value, Failure> {
        if self.is("-") || self.is("+") {
            let minus = self.is("-");
            self.bump()?;
            // Unary minus binds looser than `**`: -2 ** 2 is -4.
            let operand = self.expr(6)?;
            let factor = Value::Int(if minus { -1 } else { 1 });
            return self.run(binary("*", &operand, &factor));
        }
        if self.is("!") {
            self.bump()?;
            let operand = self.expr(6)?;
            return Ok(Value::Bool(!operand.truthy()));
        }
        self.primary()
    }

    fn primary(&mut self) -> Result<Value, Failure> {
        let value = match self.token.clone() {
            Token::Int(i) => Value::Int(i),
            Token::Float(f) => Value::Float(f),
            Token::Str(s, _) => Value::Str(Str::from(s)),
            Token::Punct("(") => {
                self.bump()?;
                let v = self.expr(0)?;
                self.expect(")")?;
                return Ok(v);
            }
            Token::Punct("[") => {
                self.bump()?;
                return self.array("]");
            }
            Token::Ident(name) if name.eq_ignore_ascii_case("array") => {
                self.bump()?;
                if !self.is("(") {
                    return Err(self.unexpected(Some("(")));
                }
                self.bump()?;
                return self.array(")");
            }
            Token::Ident(name) => {
                self.bump()?;
                if self.is("(") || self.is("\\") {
                    return Err(Failure::Unsupported(format!("calling {name}()")));
                }
                return self.run(constant(&name));
            }
            _ => return Err(self.unexpected(None)),
        };
        self.bump()?;
        Ok(value)
    }

    fn array(&mut self, close: &str) -> Result<Value, Failure> {
        let mut array = Array::default();
        loop {
            if self.is(close) {
                self.bump()?;
                return Ok(Value::Array(array));
            }
            let first = self.expr(0)?;
            if self.is("=>") {
                self.bump()?;
                let value = self.expr(0)?;
                match key(&first) {
                    Ok(k) => {
                        array.set(k, value);
                    }
                    Err(e) => {
                        self.run(Err(e))?;
                    }
                }
            } else if array.push(first).is_err() {
                self.run(Err(Failure::Thrown(
                    "Cannot add element to the array as the next element is already occupied".into(),
                )))?;
            }
            if self.is(",") {
                self.bump()?;
            } else if !self.is(close) {
                return Err(self.unexpected(Some(close)));
            }
        }
    }
}

/// An array key as `[$key => ...]` converts it.
fn key(value: &Value) -> Result<ArrayKey, Failure> {
    Ok(match value {
        Value::Int(i) => ArrayKey::Int(*i),
        Value::Str(s) => ArrayKey::normalize(s.clone()),
        Value::Bool(b) => ArrayKey::Int(i64::from(*b)),
        Value::Null => ArrayKey::Str(Str::default()),
        Value::Float(f) => ArrayKey::Int(php_std::format::dval_to_lval(*f)),
        _ => return Err(Failure::Thrown("Illegal offset type".into())),
    })
}

fn constant(name: &str) -> Result<Value, Failure> {
    Ok(match name.to_ascii_lowercase().as_str() {
        "true" => Value::Bool(true),
        "false" => Value::Bool(false),
        "null" => Value::Null,
        _ => match name {
            "PHP_EOL" => Value::from("\n"),
            "PHP_INT_MAX" => Value::Int(i64::MAX),
            "PHP_INT_MIN" => Value::Int(i64::MIN),
            "PHP_INT_SIZE" => Value::Int(8),
            "PHP_FLOAT_EPSILON" => Value::Float(f64::EPSILON),
            "PHP_FLOAT_MAX" => Value::Float(f64::MAX),
            "PHP_FLOAT_MIN" => Value::Float(f64::MIN_POSITIVE),
            "INF" => Value::Float(f64::INFINITY),
            "NAN" => Value::Float(f64::NAN),
            "M_PI" => Value::Float(std::f64::consts::PI),
            "DIRECTORY_SEPARATOR" => Value::from("/"),
            _ => return Err(Failure::Thrown(format!("Undefined constant \"{name}\""))),
        },
    })
}

/// A number from an operand of `op`, as the engine converts it.
fn number(v: &Value, op: &str, left: &Value, right: &Value) -> Result<Num, Failure> {
    let unsupported =
        || Failure::Thrown(format!("Unsupported operand types: {} {op} {}", left.debug_type(), right.debug_type()));
    Ok(match v {
        Value::Int(i) => Num::Int(*i),
        Value::Float(f) => Num::Float(*f),
        Value::Bool(b) => Num::Int(i64::from(*b)),
        Value::Null => Num::Int(0),
        Value::Str(s) => match php_std::value::numeric_bytes_ex(s.as_bytes()) {
            Some((php_std::value::Number::Int(i), _)) => Num::Int(i),
            Some((php_std::value::Number::Float(f), _)) => Num::Float(f),
            // A leading-numeric string ("5x") is its number (PHP warns).
            None => leading_number(s.as_bytes()).ok_or_else(unsupported)?,
        },
        _ => return Err(unsupported()),
    })
}

/// The number a string starts with (after whitespace), if any: an integer,
/// or a float when it has a fraction or exponent or overflows.
fn leading_number(s: &[u8]) -> Option<Num> {
    let at = |i: usize| s.get(i).copied().unwrap_or(0);
    let mut i = 0;
    while matches!(at(i), b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C) {
        i += 1;
    }
    let start = i;
    if matches!(at(i), b'+' | b'-') {
        i += 1;
    }
    let digits = |i: &mut usize| {
        let from = *i;
        while at(*i).is_ascii_digit() {
            *i += 1;
        }
        *i > from
    };
    let whole = digits(&mut i);
    let mut float = false;
    if at(i) == b'.' {
        let mut j = i + 1;
        if digits(&mut j) || whole {
            float = true;
            i = j;
        }
    }
    if !whole && !float {
        return None;
    }
    if matches!(at(i), b'e' | b'E') {
        let mut j = i + 1;
        if matches!(at(j), b'+' | b'-') {
            j += 1;
        }
        if digits(&mut j) {
            float = true;
            i = j;
        }
    }
    let text = &s[start..i];
    if !float && let Some(n) = std::str::from_utf8(text).ok().and_then(|t| t.parse::<i64>().ok()) {
        return Some(Num::Int(n));
    }
    Some(Num::Float(php_std::format::str_to_float(text)))
}

fn binary(op: &str, left: &Value, right: &Value) -> Result<Value, Failure> {
    if op == "." {
        let text = |v: &Value| match v {
            Value::Array(_) => Ok(b"Array".to_vec()),
            other => other
                .to_php_string()
                .map(|s| s.as_bytes().to_vec())
                .map_err(|_| Failure::Unsupported("concatenating this value".into())),
        };
        let mut out = text(left)?;
        out.extend(text(right)?);
        return Ok(Value::Str(Str::from(out)));
    }
    if let (Value::Array(a), Value::Array(b), "+") = (left, right, op) {
        let mut union = a.clone();
        for (k, v) in b.iter() {
            let k = k.to_owned();
            if !union.contains_key(&k) {
                union.set(k, v.clone());
            }
        }
        return Ok(Value::Array(union));
    }
    if matches!(left, Value::Array(_)) || matches!(right, Value::Array(_)) {
        return Err(Failure::Thrown(format!(
            "Unsupported operand types: {} {op} {}",
            left.debug_type(),
            right.debug_type()
        )));
    }
    let a = number(left, op, left, right)?;
    let b = number(right, op, left, right)?;
    Ok(match op {
        "+" | "-" | "*" => match (a, b) {
            (Num::Int(x), Num::Int(y)) => {
                let r = match op {
                    "+" => x.checked_add(y),
                    "-" => x.checked_sub(y),
                    _ => x.checked_mul(y),
                };
                match r {
                    Some(r) => Value::Int(r),
                    None => Value::Float(apply(op, x as f64, y as f64)),
                }
            }
            _ => Value::Float(apply(op, a.f(), b.f())),
        },
        "/" => {
            if b.f() == 0.0 {
                return Err(Failure::Thrown("Division by zero".into()));
            }
            match (a, b) {
                (Num::Int(x), Num::Int(y)) if y != -1 && x % y == 0 => Value::Int(x / y),
                (Num::Int(x), Num::Int(-1)) if x != i64::MIN => Value::Int(-x),
                _ => Value::Float(a.f() / b.f()),
            }
        }
        "%" => {
            let to_int = |n: Num| match n {
                Num::Int(i) => i,
                Num::Float(f) => php_std::format::dval_to_lval(f),
            };
            let (x, y) = (to_int(a), to_int(b));
            if y == 0 {
                return Err(Failure::Thrown("Modulo by zero".into()));
            }
            Value::Int(if y == -1 { 0 } else { x % y })
        }
        _ => match (a, b) {
            (Num::Int(x), Num::Int(y)) if y >= 0 => int_pow(x, y),
            _ => Value::Float(a.f().powf(b.f())),
        },
    })
}

/// `int ** int` with a non-negative exponent, as the engine computes it: by
/// squaring, switching to a float at the first overflow.
fn int_pow(base: i64, exponent: i64) -> Value {
    let (mut l1, mut l2, mut i) = (1i64, base, exponent);
    if i == 0 {
        return Value::Int(1);
    }
    if l2 == 0 {
        return Value::Int(0);
    }
    while i >= 1 {
        if i % 2 != 0 {
            i -= 1;
            match l1.checked_mul(l2) {
                Some(v) => l1 = v,
                None => return Value::Float(l1 as f64 * l2 as f64 * (l2 as f64).powf(i as f64)),
            }
        } else {
            i /= 2;
            match l2.checked_mul(l2) {
                Some(v) => l2 = v,
                None => return Value::Float(l1 as f64 * (l2 as f64 * l2 as f64).powf(i as f64)),
            }
        }
    }
    Value::Int(l1)
}

fn apply(op: &str, x: f64, y: f64) -> f64 {
    match op {
        "+" => x + y,
        "-" => x - y,
        _ => x * y,
    }
}

/// Evaluates a script that starts with `<?php`: the value it returns, or
/// `1` (what `include` returns) when it returns nothing.
pub(crate) fn evaluate(source: &[u8]) -> Result<Value, Failure> {
    let mut lexer = Lexer { src: source, pos: 5, line: 1, brackets: Vec::new() };
    if source.len() > 5 && source[5] == b'\n' {
        lexer.line = 2;
        lexer.pos = 6;
    }
    let (token, span) = lexer.next()?;
    let mut parser = Parser { lexer, token, span, runtime: None, returned: false };
    match parser.script() {
        Ok(value) => Ok(value.unwrap_or(Value::Int(1))),
        Err(Failure::Unsupported(what)) => {
            // PHP would still report the errors its lexer finds further on
            // (unclosed brackets, invalid literals) before running anything.
            loop {
                match parser.lexer.next() {
                    Ok((Token::Eof, _)) => return Err(Failure::Unsupported(what)),
                    Ok(_) => {}
                    // Skip what the lexer does not read (`$`, attributes).
                    Err(Failure::Unsupported(_)) => parser.lexer.pos += 1,
                    Err(thrown) => return Err(thrown),
                }
            }
        }
        Err(e) => Err(e),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn evaluates() {
        let v = evaluate(b"<?php return ['key' => 5 + 3, 2 ** 3, -2 ** 2, 7 / 2, \"a\\x41\"];").unwrap();
        let Value::Array(a) = v else { panic!() };
        assert_eq!(a.get_str("key"), Some(&Value::Int(8)));
        assert_eq!(a.get(&ArrayKey::Int(0)), Some(&Value::Int(8)));
        assert_eq!(a.get(&ArrayKey::Int(1)), Some(&Value::Int(-4)));
        assert_eq!(a.get(&ArrayKey::Int(2)), Some(&Value::Float(3.5)));
        assert_eq!(a.get(&ArrayKey::Int(3)), Some(&Value::from("aA")));
        assert_eq!(evaluate(b"<?php").unwrap(), Value::Int(1));
        assert_eq!(evaluate(b"<?php return [};"), Err(Failure::Thrown("Unclosed '[' does not match '}'".into())));
        assert_eq!(
            evaluate(b"<?php return [1 2];"),
            Err(Failure::Thrown("syntax error, unexpected integer \"2\", expecting \"]\"".into()))
        );
    }
}
