//! The PHP data files this crate reads: `<?php return <array literal>;`
//! with single-quoted string keys and values (`\'` and `\\` escapes),
//! nested short arrays and comments.

/// A value of the literal.
#[derive(Debug)]
pub enum Literal {
    Str(String),
    /// Entries in order; list items have no key.
    Array(Vec<(Option<String>, Literal)>),
}

impl Literal {
    pub fn as_str(&self) -> Option<&str> {
        match self {
            Literal::Str(s) => Some(s),
            Literal::Array(_) => None,
        }
    }
}

struct Parser<'s> {
    s: &'s [u8],
    pos: usize,
}

/// Parses the file.
pub fn parse(source: &str) -> Result<Literal, String> {
    let body = source.trim_start().strip_prefix("<?php").ok_or("missing `<?php`")?;
    let mut p = Parser { s: body.as_bytes(), pos: 0 };
    p.skip();
    if !p.eat_word("return") {
        return Err("missing `return`".into());
    }
    let value = p.value()?;
    p.skip();
    match p.peek() {
        Some(b';') | None => Ok(value),
        Some(_) => Err(p.error("expected `;`")),
    }
}

impl Parser<'_> {
    fn peek(&self) -> Option<u8> {
        self.s.get(self.pos).copied()
    }

    fn error(&self, what: &str) -> String {
        format!("{what} at byte {}", self.pos)
    }

    /// Skips whitespace and comments.
    fn skip(&mut self) {
        loop {
            while self.peek().is_some_and(|c| c.is_ascii_whitespace()) {
                self.pos += 1;
            }
            let rest = &self.s[self.pos..];
            if rest.starts_with(b"//") || rest.starts_with(b"#") {
                while self.peek().is_some_and(|c| c != b'\n') {
                    self.pos += 1;
                }
            } else if rest.starts_with(b"/*") {
                match rest[2..].windows(2).position(|w| w == b"*/") {
                    Some(end) => self.pos += end + 4,
                    None => self.pos = self.s.len(),
                }
            } else {
                return;
            }
        }
    }

    fn eat_word(&mut self, word: &str) -> bool {
        if self.s[self.pos..].starts_with(word.as_bytes()) {
            self.pos += word.len();
            true
        } else {
            false
        }
    }

    fn value(&mut self) -> Result<Literal, String> {
        self.skip();
        match self.peek() {
            Some(b'\'') => self.string().map(Literal::Str),
            Some(b'[') => self.array(),
            _ => Err(self.error("expected a string or an array")),
        }
    }

    fn string(&mut self) -> Result<String, String> {
        self.pos += 1;
        let mut out = Vec::new();
        loop {
            match self.peek() {
                None => return Err(self.error("unterminated string")),
                Some(b'\'') => {
                    self.pos += 1;
                    break;
                }
                Some(b'\\') if matches!(self.s.get(self.pos + 1), Some(b'\'' | b'\\')) => {
                    out.push(self.s[self.pos + 1]);
                    self.pos += 2;
                }
                Some(c) => {
                    out.push(c);
                    self.pos += 1;
                }
            }
        }
        String::from_utf8(out).map_err(|_| self.error("string is not UTF-8"))
    }

    fn array(&mut self) -> Result<Literal, String> {
        self.pos += 1;
        let mut entries = Vec::new();
        loop {
            self.skip();
            if self.peek() == Some(b']') {
                self.pos += 1;
                return Ok(Literal::Array(entries));
            }
            let first = self.value()?;
            self.skip();
            let entry = if self.eat_word("=>") {
                let Literal::Str(key) = first else {
                    return Err(self.error("array key is not a string"));
                };
                (Some(key), self.value()?)
            } else {
                (None, first)
            };
            entries.push(entry);
            self.skip();
            match self.peek() {
                Some(b',') => self.pos += 1,
                Some(b']') => {}
                _ => return Err(self.error("expected `,` or `]`")),
            }
        }
    }
}
