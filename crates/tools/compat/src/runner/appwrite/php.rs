//! A small, tolerant reader of PHP source: enough to read the routes and
//! hooks Appwrite declares with well-known calls (`setHttpMethod`,
//! `setHttpPath`, `httpAlias`, `label`, `groups`, `inject`, `Http::get(...)`,
//! `Http::init()`), without running PHP.
//!
//! It lexes a file into tokens (strings, names, variables, punctuation and
//! docblocks; comments are dropped), finds namespaces, `use` imports,
//! classes with their constants, methods and parents, and evaluates the
//! simple expressions those calls take: string literals and their
//! concatenation, arrays, class constants, `new Method(name: ...)`,
//! constructor parameters passed up with `parent::__construct(...)`, and
//! `$this->method()` returning one of those. Anything else becomes
//! [`Value::Unknown`] with its source text, so a new idiom degrades the data
//! instead of failing the generation.

use std::collections::BTreeMap;

#[derive(Debug, Clone, PartialEq)]
pub enum Tok {
    Str(String),
    Num(String),
    /// A name: keyword, function, class or constant (`\in_array`, `Http`).
    Name(String),
    /// A variable, without its `$`.
    Var(String),
    Punct(String),
    /// A `/** ... */` comment, kept for descriptions.
    Doc(String),
}

#[derive(Debug, Clone)]
pub struct Token {
    pub tok: Tok,
    pub line: usize,
    /// Byte offsets in the source.
    pub start: usize,
    pub end: usize,
}

impl Token {
    fn punct(&self, p: &str) -> bool {
        matches!(&self.tok, Tok::Punct(x) if x == p)
    }

    fn name(&self) -> Option<&str> {
        match &self.tok {
            Tok::Name(n) => Some(n),
            _ => None,
        }
    }

    fn is_name(&self, n: &str) -> bool {
        self.name().is_some_and(|x| x.eq_ignore_ascii_case(n))
    }
}

const PUNCT: [&str; 21] = [
    "?->", "===", "!==", "...", "<=>", "**=", "->", "::", "=>", "==", "!=", "<=", ">=", "&&", "||", "??", ".=", "++",
    "--", "+=", "-=",
];

/// Splits PHP source into tokens. Text before `<?php` is skipped.
pub fn lex(src: &str) -> Vec<Token> {
    let bytes = src.as_bytes();
    let mut out = Vec::new();
    let mut i = src.find("<?php").map(|p| p + 5).unwrap_or(0);
    let mut line = 1 + src[..i].matches('\n').count();
    let n = bytes.len();
    while i < n {
        let c = bytes[i];
        let start = i;
        let start_line = line;
        match c {
            b'\n' => {
                line += 1;
                i += 1;
            }
            b' ' | b'\t' | b'\r' => i += 1,
            b'/' if bytes.get(i + 1) == Some(&b'/') => {
                while i < n && bytes[i] != b'\n' {
                    i += 1;
                }
            }
            b'#' if bytes.get(i + 1) != Some(&b'[') => {
                while i < n && bytes[i] != b'\n' {
                    i += 1;
                }
            }
            b'/' if bytes.get(i + 1) == Some(&b'*') => {
                let end = src[i + 2..].find("*/").map(|p| i + 2 + p + 2).unwrap_or(n);
                let text = &src[i..end];
                line += text.matches('\n').count();
                if text.starts_with("/**") && text.len() > 4 {
                    out.push(Token { tok: Tok::Doc(text.to_owned()), line: start_line, start, end });
                }
                i = end;
            }
            b'\'' => {
                let mut s = String::new();
                i += 1;
                while i < n && bytes[i] != b'\'' {
                    if bytes[i] == b'\\' && matches!(bytes.get(i + 1), Some(b'\'') | Some(b'\\')) {
                        s.push(bytes[i + 1] as char);
                        i += 2;
                        continue;
                    }
                    if bytes[i] == b'\n' {
                        line += 1;
                    }
                    let ch = src[i..].chars().next().unwrap_or(' ');
                    s.push(ch);
                    i += ch.len_utf8();
                }
                i += 1;
                out.push(Token { tok: Tok::Str(s), line: start_line, start, end: i.min(n) });
            }
            b'"' => {
                let mut s = String::new();
                i += 1;
                while i < n && bytes[i] != b'"' {
                    if bytes[i] == b'\\' && i + 1 < n {
                        let e = bytes[i + 1];
                        match e {
                            b'n' => s.push('\n'),
                            b't' => s.push('\t'),
                            b'"' | b'\\' | b'$' => s.push(e as char),
                            _ => {
                                s.push('\\');
                                s.push(e as char);
                            }
                        }
                        i += 2;
                        continue;
                    }
                    if bytes[i] == b'\n' {
                        line += 1;
                    }
                    let ch = src[i..].chars().next().unwrap_or(' ');
                    s.push(ch);
                    i += ch.len_utf8();
                }
                i += 1;
                out.push(Token { tok: Tok::Str(s), line: start_line, start, end: i.min(n) });
            }
            b'<' if src[i..].starts_with("<<<") => {
                // Heredoc / nowdoc: `<<<EOT` or `<<<'EOT'`, ending at a line whose trimmed start is the label.
                let rest = &src[i + 3..];
                let header_end = rest.find('\n').unwrap_or(rest.len());
                let label = rest[..header_end].trim().trim_matches(['\'', '"']).to_owned();
                let mut j = i + 3 + header_end + 1;
                let mut body = String::new();
                while j < n {
                    let end = src[j..].find('\n').map(|p| j + p).unwrap_or(n);
                    let l = &src[j..end];
                    line += 1;
                    if !label.is_empty() && l.trim_start().starts_with(&label) {
                        j += l.find(&label).unwrap_or(0) + label.len();
                        break;
                    }
                    body.push_str(l);
                    body.push('\n');
                    j = end + 1;
                }
                i = j.min(n);
                out.push(Token { tok: Tok::Str(body), line: start_line, start, end: i });
            }
            b'$' if bytes.get(i + 1).is_some_and(|b| b.is_ascii_alphabetic() || *b == b'_') => {
                i += 1;
                let s = i;
                while i < n && (bytes[i].is_ascii_alphanumeric() || bytes[i] == b'_') {
                    i += 1;
                }
                out.push(Token { tok: Tok::Var(src[s..i].to_owned()), line: start_line, start, end: i });
            }
            b'0'..=b'9' => {
                while i < n && (bytes[i].is_ascii_alphanumeric() || bytes[i] == b'_' || bytes[i] == b'.') {
                    // `1..2` never appears in PHP; stop a trailing `.` before a name (concatenation).
                    if bytes[i] == b'.' && !bytes.get(i + 1).is_some_and(u8::is_ascii_digit) {
                        break;
                    }
                    i += 1;
                }
                out.push(Token { tok: Tok::Num(src[start..i].to_owned()), line: start_line, start, end: i });
            }
            c if c.is_ascii_alphabetic() || c == b'_' || c == b'\\' || c >= 0x80 => {
                while i < n
                    && (bytes[i].is_ascii_alphanumeric() || bytes[i] == b'_' || bytes[i] == b'\\' || bytes[i] >= 0x80)
                {
                    i += 1;
                }
                out.push(Token { tok: Tok::Name(src[start..i].to_owned()), line: start_line, start, end: i });
            }
            _ => {
                let p = PUNCT.iter().find(|p| src[i..].starts_with(*p)).map(|p| p.to_string());
                let p = p.unwrap_or_else(|| (c as char).to_string());
                i += p.len();
                out.push(Token { tok: Tok::Punct(p), line: start_line, start, end: i });
            }
        }
    }
    out
}

/// The index of the bracket closing the one at `open` (`(`, `[` or `{`),
/// or the last token when it is unbalanced.
pub fn closing(tokens: &[Token], open: usize) -> usize {
    let mut depth = 0i32;
    for (k, t) in tokens.iter().enumerate().skip(open) {
        if let Tok::Punct(p) = &t.tok {
            match p.as_str() {
                "(" | "[" | "{" => depth += 1,
                ")" | "]" | "}" => {
                    depth -= 1;
                    if depth == 0 {
                        return k;
                    }
                }
                _ => {}
            }
        }
    }
    tokens.len().saturating_sub(1)
}

/// The `;` ending the statement that starts at `from`, skipping nested
/// brackets (closures carry their own semicolons), or `to`.
pub fn statement_end(tokens: &[Token], from: usize, to: usize) -> usize {
    let mut k = from;
    while k < to {
        let t = &tokens[k];
        if t.punct("(") || t.punct("[") || t.punct("{") {
            k = closing(tokens, k) + 1;
            continue;
        }
        if t.punct(";") {
            return k;
        }
        k += 1;
    }
    to
}

/// Top-level comma-separated parts of `tokens[from..to]`.
pub fn split_args(tokens: &[Token], from: usize, to: usize) -> Vec<(usize, usize)> {
    let mut out = Vec::new();
    let mut start = from;
    let mut k = from;
    while k < to {
        let t = &tokens[k];
        if t.punct("(") || t.punct("[") || t.punct("{") {
            k = closing(tokens, k) + 1;
            continue;
        }
        if t.punct(",") {
            out.push((start, k));
            start = k + 1;
        }
        k += 1;
    }
    if start < to {
        out.push((start, to));
    }
    out
}

/// A value read from PHP source.
#[derive(Debug, Clone, PartialEq)]
pub enum Value {
    Str(String),
    Num(String),
    Bool(bool),
    Null,
    Array(Vec<(Option<Value>, Value)>),
    /// `new Class(args)`, with named arguments' names.
    New {
        class: String,
        args: Vec<(Option<String>, Value)>,
    },
    /// A constant that is not resolved (`APP_LIMIT_COUNT`, `Action::HTTP_REQUEST_METHOD_GET`).
    Const(String),
    /// Anything else, as its source text.
    Unknown(String),
}

impl Value {
    pub fn as_str(&self) -> Option<&str> {
        match self {
            Value::Str(s) => Some(s),
            _ => None,
        }
    }

    /// Strings of a string or an array of strings.
    pub fn strings(&self) -> Vec<String> {
        match self {
            Value::Str(s) => vec![s.clone()],
            Value::Array(items) => items.iter().filter_map(|(_, v)| v.as_str().map(str::to_owned)).collect(),
            _ => Vec::new(),
        }
    }

    /// A named (or positional) argument of `new Class(...)`.
    pub fn arg(&self, name: &str, position: usize) -> Option<&Value> {
        let Value::New { args, .. } = self else { return None };
        args.iter()
            .find(|(n, _)| n.as_deref() == Some(name))
            .or_else(|| args.get(position).filter(|(n, _)| n.is_none()))
            .map(|(_, v)| v)
    }

    /// Short text for display.
    pub fn text(&self) -> String {
        match self {
            Value::Str(s) => s.clone(),
            Value::Num(n) => n.clone(),
            Value::Bool(b) => b.to_string(),
            Value::Null => "null".into(),
            Value::Const(c) | Value::Unknown(c) => c.clone(),
            Value::Array(items) => {
                format!("[{}]", items.iter().map(|(_, v)| v.text()).collect::<Vec<_>>().join(", "))
            }
            Value::New { class, .. } => format!("new {class}(...)"),
        }
    }
}

/// A class, trait or interface.
#[derive(Debug, Clone)]
pub struct Class {
    pub name: String,
    pub fqcn: String,
    pub kind: &'static str,
    pub is_abstract: bool,
    pub extends: Option<String>,
    pub line: usize,
    pub doc: Option<String>,
    /// Constant name → expression range.
    pub consts: BTreeMap<String, (usize, usize)>,
    pub methods: BTreeMap<String, Method>,
    /// Traits it uses, fully qualified.
    pub traits: Vec<String>,
}

#[derive(Debug, Clone)]
pub struct Method {
    pub params: Vec<String>,
    /// Body range, inside the braces (empty for abstract methods).
    pub body: (usize, usize),
    pub line: usize,
}

/// A parsed file.
#[derive(Debug)]
pub struct File {
    pub path: String,
    pub src: String,
    pub tokens: Vec<Token>,
    pub namespace: String,
    /// Alias (last segment or `as` name) → fully qualified name.
    pub uses: BTreeMap<String, String>,
    pub classes: Vec<Class>,
}

impl File {
    pub fn parse(path: &str, src: String) -> File {
        let tokens = lex(&src);
        let mut file = File {
            path: path.to_owned(),
            src,
            tokens,
            namespace: String::new(),
            uses: BTreeMap::new(),
            classes: Vec::new(),
        };
        file.read_top();
        file
    }

    /// Resolves a class name as PHP does in this file.
    pub fn resolve(&self, name: &str) -> String {
        if let Some(n) = name.strip_prefix('\\') {
            return n.to_owned();
        }
        if matches!(name.to_ascii_lowercase().as_str(), "self" | "static" | "parent") {
            return name.to_owned();
        }
        let (first, rest) = match name.split_once('\\') {
            Some((f, r)) => (f, Some(r)),
            None => (name, None),
        };
        if let Some(full) = self.uses.get(first) {
            return match rest {
                Some(r) => format!("{full}\\{r}"),
                None => full.clone(),
            };
        }
        if self.namespace.is_empty() { name.to_owned() } else { format!("{}\\{name}", self.namespace) }
    }

    pub fn text(&self, from: usize, to: usize) -> String {
        if from >= to || from >= self.tokens.len() {
            return String::new();
        }
        let end = self.tokens[(to - 1).min(self.tokens.len() - 1)].end;
        self.src[self.tokens[from].start..end].to_owned()
    }

    fn read_top(&mut self) {
        let tokens = std::mem::take(&mut self.tokens);
        let t = &tokens;
        let mut k = 0;
        let mut doc: Option<String> = None;
        let mut modifiers = Vec::new();
        while k < t.len() {
            match &t[k].tok {
                Tok::Doc(d) => {
                    doc = Some(d.clone());
                    k += 1;
                    continue;
                }
                Tok::Name(n) if n == "namespace" => {
                    if let Some(name) = t.get(k + 1).and_then(Token::name) {
                        self.namespace = name.trim_start_matches('\\').to_owned();
                    }
                    k += 2;
                }
                // Function bodies and closures hold no declarations this reader needs.
                Tok::Punct(p) if p == "{" => {
                    k = closing(t, k) + 1;
                }
                Tok::Name(n) if n == "use" && t.get(k + 1).is_some_and(|x| x.name().is_some()) => {
                    let end = (k..t.len()).find(|&j| t[j].punct(";")).unwrap_or(t.len());
                    self.read_use(t, k + 1, end);
                    k = end + 1;
                }
                Tok::Name(n) if matches!(n.as_str(), "abstract" | "final" | "readonly") => {
                    modifiers.push(n.clone());
                    k += 1;
                    continue;
                }
                Tok::Name(n) if matches!(n.as_str(), "class" | "trait" | "interface" | "enum") => {
                    // `Foo::class` is not a declaration.
                    if k > 0 && t[k - 1].punct("::") {
                        k += 1;
                        continue;
                    }
                    let kind = match n.as_str() {
                        "class" => "class",
                        "trait" => "trait",
                        "enum" => "enum",
                        _ => "interface",
                    };
                    let Some(name) = t.get(k + 1).and_then(Token::name).map(str::to_owned) else {
                        k += 1;
                        continue;
                    };
                    let Some(open) = (k..t.len()).find(|&j| t[j].punct("{")) else { break };
                    let extends = (k..open)
                        .find(|&j| t[j].is_name("extends"))
                        .and_then(|j| t.get(j + 1))
                        .and_then(Token::name)
                        .map(|p| self.resolve(p));
                    let close = closing(t, open);
                    let fqcn =
                        if self.namespace.is_empty() { name.clone() } else { format!("{}\\{name}", self.namespace) };
                    let mut class = Class {
                        name,
                        fqcn,
                        kind,
                        is_abstract: modifiers.iter().any(|m| m == "abstract"),
                        extends,
                        line: t[k].line,
                        doc: doc.take(),
                        consts: BTreeMap::new(),
                        methods: BTreeMap::new(),
                        traits: Vec::new(),
                    };
                    self.read_class_body(t, &mut class, open + 1, close);
                    self.classes.push(class);
                    k = close + 1;
                }
                _ => k += 1,
            }
            modifiers.clear();
            doc = None;
        }
        self.tokens = tokens;
    }

    fn read_use(&mut self, t: &[Token], from: usize, to: usize) {
        if t.get(from).is_some_and(|x| x.is_name("function") || x.is_name("const")) {
            return;
        }
        // `use A\{B, C as D}`: a name ending in `\` followed by a brace group.
        if let Some(open) = (from..to).find(|&j| t[j].punct("{")) {
            let prefix = t.get(from).and_then(Token::name).unwrap_or("").trim_matches('\\').to_owned();
            let close = closing(t, open).min(to);
            for (a, b) in split_args(t, open + 1, close) {
                self.add_use(t, &prefix, a, b);
            }
            return;
        }
        for (a, b) in split_args(t, from, to) {
            self.add_use(t, "", a, b);
        }
    }

    fn add_use(&mut self, t: &[Token], prefix: &str, a: usize, b: usize) {
        let Some(name) = t.get(a).and_then(Token::name) else { return };
        let full = if prefix.is_empty() {
            name.trim_start_matches('\\').to_owned()
        } else {
            format!("{prefix}\\{}", name.trim_start_matches('\\'))
        };
        let alias = if b > a + 2 && t[a + 1].is_name("as") {
            t[a + 2].name().unwrap_or("").to_owned()
        } else {
            full.rsplit('\\').next().unwrap_or(&full).to_owned()
        };
        self.uses.insert(alias, full);
    }

    fn read_class_body(&self, t: &[Token], class: &mut Class, from: usize, to: usize) {
        let mut k = from;
        while k < to {
            if t[k].punct("{") || t[k].punct("(") || t[k].punct("[") {
                k = closing(t, k) + 1;
                continue;
            }
            if t[k].is_name("use") {
                // Traits: `use A, B;` (or `use A { ... }`).
                let mut end = k + 1;
                while end < to && !t[end].punct(";") && !t[end].punct("{") {
                    end += 1;
                }
                for (a, b) in split_args(t, k + 1, end) {
                    if let Some(name) = (a..b).find_map(|j| t[j].name()) {
                        class.traits.push(self.resolve(name));
                    }
                }
                k = if end < to && t[end].punct("{") { closing(t, end) + 1 } else { end + 1 };
                continue;
            }
            if t[k].is_name("const") {
                // `const NAME = expr;` (also `const TYPE NAME = expr;`).
                let end = (k..to).find(|&j| t[j].punct(";")).unwrap_or(to);
                if let Some(eq) = (k..end).find(|&j| t[j].punct("="))
                    && let Some(name) = t[eq - 1].name()
                {
                    class.consts.insert(name.to_owned(), (eq + 1, end));
                }
                k = end + 1;
                continue;
            }
            if t[k].is_name("function") {
                let Some(name) = t.get(k + 1).and_then(Token::name).map(str::to_owned) else {
                    k += 1;
                    continue;
                };
                let Some(open_params) = (k..to).find(|&j| t[j].punct("(")) else { break };
                let close_params = closing(t, open_params);
                let params = split_args(t, open_params + 1, close_params)
                    .into_iter()
                    .filter_map(|(a, b)| {
                        (a..b).find_map(|j| match &t[j].tok {
                            Tok::Var(v) => Some(v.clone()),
                            _ => None,
                        })
                    })
                    .collect();
                // The body, or `;` for an abstract method.
                let mut j = close_params + 1;
                while j < to && !t[j].punct("{") && !t[j].punct(";") {
                    j += 1;
                }
                let body = if j < to && t[j].punct("{") {
                    let close = closing(t, j);
                    let range = (j + 1, close);
                    k = close + 1;
                    range
                } else {
                    k = j + 1;
                    (j, j)
                };
                class.methods.insert(name, Method { params, body, line: t[open_params].line });
                continue;
            }
            k += 1;
        }
    }

    /// The `->name(args)` calls at the top level of `tokens[from..to]`, in order.
    pub fn calls(&self, from: usize, to: usize) -> Vec<Call> {
        let t = &self.tokens;
        let mut out = Vec::new();
        let mut k = from;
        while k < to {
            if (t[k].punct("->") || t[k].punct("?->"))
                && let Some(name) = t.get(k + 1).and_then(Token::name)
                && t.get(k + 2).is_some_and(|x| x.punct("("))
            {
                let close = closing(t, k + 2);
                out.push(Call { name: name.to_owned(), args: split_args(t, k + 3, close), line: t[k + 1].line });
                k = close + 1;
                continue;
            }
            if t[k].punct("(") || t[k].punct("[") || t[k].punct("{") {
                k = closing(t, k) + 1;
                continue;
            }
            k += 1;
        }
        out
    }

    /// Statements starting with `Http::<name>(...)` at the top level of the file.
    pub fn statics(&self, class: &str) -> Vec<Static> {
        let t = &self.tokens;
        let mut out = Vec::new();
        let mut k = 0;
        while k + 3 < t.len() {
            if t[k].punct("{") {
                k = closing(t, k) + 1;
                continue;
            }
            if t[k].name() == Some(class)
                && t[k + 1].punct("::")
                && let Some(name) = t[k + 2].name()
                && t[k + 3].punct("(")
            {
                let close = closing(t, k + 3);
                let end = statement_end(t, close + 1, t.len());
                // The docblock right before the statement describes it.
                let doc = k.checked_sub(1).and_then(|p| match &t[p].tok {
                    Tok::Doc(d) => Some(d.clone()),
                    _ => None,
                });
                out.push(Static {
                    name: name.to_owned(),
                    args: split_args(t, k + 4, close),
                    chain: self.calls(close + 1, end),
                    line: t[k].line,
                    range: (k, end),
                    doc,
                });
                k = end + 1;
                continue;
            }
            k += 1;
        }
        out
    }
}

/// A `->name(args)` call; args are token ranges in the file.
#[derive(Debug, Clone)]
pub struct Call {
    pub name: String,
    pub args: Vec<(usize, usize)>,
    pub line: usize,
}

/// A top-level `Class::name(args)->chain...;` statement.
#[derive(Debug, Clone)]
pub struct Static {
    pub name: String,
    pub args: Vec<(usize, usize)>,
    pub chain: Vec<Call>,
    pub line: usize,
    pub range: (usize, usize),
    pub doc: Option<String>,
}

/// What an expression can refer to while it is evaluated.
pub trait Scope {
    /// `Class::NAME` (`self`/`static` resolved by the scope).
    fn constant(&self, class: &str, name: &str) -> Option<Value>;
    /// `$name`.
    fn var(&self, name: &str) -> Option<Value>;
    /// `$this->name()`.
    fn method(&self, name: &str) -> Option<Value>;
    /// `Class::name()` (`static::`/`self::` resolved by the scope).
    fn call(&self, _class: &str, _name: &str) -> Option<Value> {
        None
    }
}

/// A scope that knows nothing: literals only.
pub struct Plain;

impl Scope for Plain {
    fn constant(&self, _: &str, _: &str) -> Option<Value> {
        None
    }
    fn var(&self, _: &str) -> Option<Value> {
        None
    }
    fn method(&self, _: &str) -> Option<Value> {
        None
    }
}

/// Evaluates `tokens[from..to]` of `file`.
pub fn eval(file: &File, from: usize, to: usize, scope: &dyn Scope) -> Value {
    let t = &file.tokens;
    if from >= to {
        return Value::Unknown(String::new());
    }
    // Named argument prefix (`name: value`) is handled by the caller; strip a leading spread.
    // Concatenation: split on top-level `.`.
    let mut parts = Vec::new();
    let mut start = from;
    let mut k = from;
    while k < to {
        if t[k].punct("(") || t[k].punct("[") || t[k].punct("{") {
            k = closing(t, k) + 1;
            continue;
        }
        if t[k].punct(".") {
            parts.push((start, k));
            start = k + 1;
        }
        k += 1;
    }
    parts.push((start, to));
    if parts.len() > 1 {
        let mut s = String::new();
        for (a, b) in parts {
            match eval(file, a, b, scope) {
                Value::Str(x) | Value::Num(x) => s.push_str(&x),
                Value::Const(c) => {
                    s.push('{');
                    s.push_str(&c);
                    s.push('}');
                }
                _ => return Value::Unknown(file.text(from, to)),
            }
        }
        return Value::Str(s);
    }
    let len = to - from;
    let first = &t[from];
    match &first.tok {
        Tok::Str(s) if len == 1 => return Value::Str(s.clone()),
        Tok::Num(n) if len == 1 => return Value::Num(n.clone()),
        Tok::Var(v) if len == 1 => return scope.var(v).unwrap_or_else(|| Value::Unknown(format!("${v}"))),
        Tok::Name(n) if len == 1 => {
            return match n.to_ascii_lowercase().as_str() {
                "true" => Value::Bool(true),
                "false" => Value::Bool(false),
                "null" => Value::Null,
                _ => Value::Const(n.clone()),
            };
        }
        _ => {}
    }
    // `[a, b]` or `array(a, b)`.
    let array_open = if first.punct("[") {
        Some(from)
    } else if first.is_name("array") && t.get(from + 1).is_some_and(|x| x.punct("(")) {
        Some(from + 1)
    } else {
        None
    };
    if let Some(open) = array_open
        && closing(t, open) == to - 1
    {
        let items = split_args(t, open + 1, to - 1)
            .into_iter()
            .map(|(a, b)| match (a..b).find(|&j| t[j].punct("=>")) {
                Some(arrow) => (Some(eval(file, a, arrow, scope)), eval(file, arrow + 1, b, scope)),
                None => (None, eval(file, a, b, scope)),
            })
            .collect();
        return Value::Array(items);
    }
    // `new Class(args)`.
    if first.is_name("new")
        && let Some(class) = t.get(from + 1).and_then(Token::name)
    {
        let class = file.resolve(class);
        let args = if t.get(from + 2).is_some_and(|x| x.punct("(")) && closing(t, from + 2) == to - 1 {
            split_args(t, from + 3, to - 1)
                .into_iter()
                .map(|(a, b)| {
                    // `name: value`
                    if b > a + 1
                        && t[a + 1].punct(":")
                        && let Some(name) = t[a].name()
                    {
                        (Some(name.to_owned()), eval(file, a + 2, b, scope))
                    } else {
                        (None, eval(file, a, b, scope))
                    }
                })
                .collect()
        } else {
            Vec::new()
        };
        return Value::New { class, args };
    }
    // `Class::NAME`.
    if len == 3
        && let Some(class) = first.name()
        && t[from + 1].punct("::")
        && let Some(name) = t[from + 2].name()
    {
        return scope.constant(class, name).unwrap_or_else(|| {
            let class = if matches!(class, "self" | "static") { class.to_owned() } else { short(&file.resolve(class)) };
            Value::Const(format!("{class}::{name}"))
        });
    }
    // `Class::name()`.
    if len == 5
        && let Some(class) = first.name()
        && t[from + 1].punct("::")
        && let Some(name) = t[from + 2].name()
        && t[from + 3].punct("(")
        && t[from + 4].punct(")")
        && let Some(v) = scope.call(class, name)
    {
        return v;
    }
    // `$this->name()`.
    if len == 5
        && matches!(&first.tok, Tok::Var(v) if v == "this")
        && t[from + 1].punct("->")
        && let Some(name) = t[from + 2].name()
        && t[from + 3].punct("(")
        && t[from + 4].punct(")")
        && let Some(v) = scope.method(name)
    {
        return v;
    }
    Value::Unknown(file.text(from, to))
}

/// `Appwrite\Platform\Action` → `Action`.
pub fn short(name: &str) -> String {
    name.rsplit('\\').next().unwrap_or(name).to_owned()
}

/// The value a method returns: its first top-level `return expr;`.
pub fn returned(file: &File, method: &Method, scope: &dyn Scope) -> Option<Value> {
    let t = &file.tokens;
    let (from, to) = method.body;
    let mut k = from;
    while k < to {
        if t[k].punct("{") || t[k].punct("(") || t[k].punct("[") {
            k = closing(t, k) + 1;
            continue;
        }
        if t[k].is_name("return") {
            let end = statement_end(t, k + 1, to);
            return Some(eval(file, k + 1, end, scope));
        }
        k += 1;
    }
    None
}

/// Strips a docblock to its text: no markers, no `@tags`.
pub fn doc_text(doc: &str) -> String {
    let inner = doc.trim().trim_start_matches("/**").trim_end_matches("*/");
    let mut out = Vec::new();
    for l in inner.lines() {
        let l = l.trim_start();
        let l = l.strip_prefix("* ").or_else(|| l.strip_prefix('*')).unwrap_or(l).trim_end();
        if l.trim_start().starts_with('@') {
            break;
        }
        out.push(l);
    }
    out.join("\n").trim().to_owned()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn lexes_strings_comments_and_names() {
        let t = lex("<?php\n// x\n$a = 'it\\'s' . \"b\\n\"; /** Doc */ \\in_array(1.5);");
        let toks: Vec<Tok> = t.into_iter().map(|t| t.tok).collect();
        assert_eq!(toks[0], Tok::Var("a".into()));
        assert_eq!(toks[2], Tok::Str("it's".into()));
        assert_eq!(toks[4], Tok::Str("b\n".into()));
        assert_eq!(toks[6], Tok::Doc("/** Doc */".into()));
        assert_eq!(toks[7], Tok::Name("\\in_array".into()));
        assert_eq!(toks[9], Tok::Num("1.5".into()));
    }

    #[test]
    fn evaluates_simple_expressions() {
        let f = File::parse(
            "x.php",
            "<?php namespace A; use B\\Method; class C { const P = '/v1/s3'; }\n\
             $x = [self::P . '/*', 'k' => true, new Method(namespace: 'users', name: 'get'), APP_X, Action::HTTP_REQUEST_METHOD_GET];"
                .into(),
        );
        struct S<'a>(&'a File);
        impl Scope for S<'_> {
            fn constant(&self, class: &str, name: &str) -> Option<Value> {
                let c = &self.0.classes[0];
                (class == "self").then(|| c.consts.get(name).map(|(a, b)| eval(self.0, *a, *b, &Plain))).flatten()
            }
            fn var(&self, _: &str) -> Option<Value> {
                None
            }
            fn method(&self, _: &str) -> Option<Value> {
                None
            }
        }
        let t = &f.tokens;
        let open = t.iter().position(|x| x.punct("[")).unwrap();
        let v = eval(&f, open, closing(t, open) + 1, &S(&f));
        let Value::Array(items) = v else { panic!("{v:?}") };
        assert_eq!(items[0].1, Value::Str("/v1/s3/*".into()));
        assert_eq!(items[1], (Some(Value::Str("k".into())), Value::Bool(true)));
        assert_eq!(items[2].1.arg("namespace", 0), Some(&Value::Str("users".into())));
        assert!(matches!(&items[2].1, Value::New { class, .. } if class == "B\\Method"));
        assert_eq!(items[3].1, Value::Const("APP_X".into()));
        assert_eq!(items[4].1, Value::Const("Action::HTTP_REQUEST_METHOD_GET".into()));
    }

    #[test]
    fn reads_group_uses_and_classes() {
        let f = File::parse(
            "x.php",
            "<?php\nnamespace N;\nuse A\\{B, C as D};\nuse E\\F;\nabstract class G extends F { public function __construct(string $m) { $this->a($m); } abstract protected function p(): string; }".into(),
        );
        assert_eq!(f.uses.get("B").map(String::as_str), Some("A\\B"));
        assert_eq!(f.uses.get("D").map(String::as_str), Some("A\\C"));
        let g = &f.classes[0];
        assert!(g.is_abstract);
        assert_eq!(g.extends.as_deref(), Some("E\\F"));
        assert_eq!(g.methods["__construct"].params, vec!["m".to_owned()]);
        assert!(g.methods.contains_key("p"));
    }
}
