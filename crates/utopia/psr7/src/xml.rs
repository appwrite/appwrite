//! A strict XML 1.0 reader for response bodies: well-formed documents
//! only, read into the element tree SimpleXML exposes.

/// An XML element as SimpleXML exposes it.
///
/// PHP: the `SimpleXMLElement` `Response::xml()` returns: `getName()`,
/// `attributes()`, `(string) $element` and `children()`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Element {
    /// The local name (without a namespace prefix).
    pub name: String,
    /// Attributes without a namespace, in document order (namespace
    /// declarations are not attributes).
    pub attributes: Vec<(String, String)>,
    /// The element's own text: its text and CDATA children, not its descendants'.
    pub text: String,
    /// Child elements.
    pub children: Vec<Element>,
}

impl Element {
    /// The first child named `name`.
    ///
    /// PHP: `$element->name`.
    pub fn child(&self, name: &str) -> Option<&Element> {
        self.children.iter().find(|c| c.name == name)
    }

    /// An attribute's value.
    ///
    /// PHP: `$element['name']`.
    pub fn attribute(&self, name: &str) -> Option<&str> {
        self.attributes.iter().find(|(n, _)| n == name).map(|(_, v)| v.as_str())
    }
}

/// The root element of a well-formed document; `None` when libxml would
/// refuse it.
pub(crate) fn parse(input: &[u8]) -> Option<Element> {
    let text = std::str::from_utf8(input).ok()?;
    let text = text.strip_prefix('\u{feff}').unwrap_or(text);
    let mut parser = Parser { t: text, s: text.as_bytes(), at: 0 };
    parser.prolog()?;
    let root = parser.element()?;
    parser.misc();
    if parser.at != parser.s.len() {
        return None;
    }
    Some(root)
}

struct Parser<'a> {
    t: &'a str,
    s: &'a [u8],
    at: usize,
}

fn is_name_start(c: char) -> bool {
    c == ':' || c == '_' || c.is_ascii_alphabetic() || (c as u32) >= 0xC0 && c != '\u{D7}' && c != '\u{F7}'
}

fn is_name_char(c: char) -> bool {
    is_name_start(c) || c == '-' || c == '.' || c.is_ascii_digit() || c == '\u{B7}'
}

/// XML 1.0 `Char`.
fn is_char(c: char) -> bool {
    matches!(c, '\t' | '\n' | '\r') || (c >= ' ' && !matches!(c as u32, 0xFFFE | 0xFFFF))
}

impl<'a> Parser<'a> {
    fn rest(&self) -> &'a [u8] {
        &self.s[self.at..]
    }

    fn starts(&self, prefix: &[u8]) -> bool {
        self.rest().starts_with(prefix)
    }

    fn peek_char(&self) -> Option<char> {
        self.t.get(self.at..)?.chars().next()
    }

    fn spaces(&mut self) -> bool {
        let start = self.at;
        while self.at < self.s.len() && matches!(self.s[self.at], b' ' | b'\t' | b'\n' | b'\r') {
            self.at += 1;
        }
        self.at > start
    }

    fn name(&mut self) -> Option<String> {
        let start = self.at;
        let first = self.peek_char()?;
        if !is_name_start(first) {
            return None;
        }
        self.at += first.len_utf8();
        while let Some(c) = self.peek_char() {
            if !is_name_char(c) {
                break;
            }
            self.at += c.len_utf8();
        }
        Some(String::from_utf8_lossy(&self.s[start..self.at]).into_owned())
    }

    fn prolog(&mut self) -> Option<()> {
        if self.starts(b"<?xml") && self.s.get(self.at + 5).is_some_and(|b| matches!(b, b' ' | b'\t' | b'\n' | b'\r')) {
            let end = find(self.rest(), b"?>")?;
            let decl = &self.rest()[5..end];
            find(decl, b"version")?;
            self.at += end + 2;
        }
        self.misc();
        if self.starts(b"<!DOCTYPE") {
            self.doctype()?;
            self.misc();
        }
        Some(())
    }

    fn doctype(&mut self) -> Option<()> {
        let mut depth = 0usize;
        let mut quote: Option<u8> = None;
        self.at += 9;
        while self.at < self.s.len() {
            let b = self.s[self.at];
            self.at += 1;
            match (quote, b) {
                (Some(q), _) if b == q => quote = None,
                (Some(_), _) => {}
                (None, b'"' | b'\'') => quote = Some(b),
                (None, b'[') => depth += 1,
                (None, b']') => depth = depth.checked_sub(1)?,
                (None, b'>') if depth == 0 => return Some(()),
                _ => {}
            }
        }
        None
    }

    /// Comments, processing instructions and white space.
    fn misc(&mut self) {
        loop {
            if self.spaces() {
                continue;
            }
            if self.starts(b"<!--") {
                if self.comment().is_none() {
                    return;
                }
                continue;
            }
            if self.starts(b"<?") {
                if self.pi().is_none() {
                    return;
                }
                continue;
            }
            return;
        }
    }

    fn comment(&mut self) -> Option<()> {
        let body = &self.rest()[4..];
        let end = find(body, b"--")?;
        if body.get(end + 2) != Some(&b'>') {
            return None;
        }
        std::str::from_utf8(&body[..end]).ok()?.chars().all(is_char).then_some(())?;
        self.at += 4 + end + 3;
        Some(())
    }

    fn pi(&mut self) -> Option<()> {
        self.at += 2;
        let target = self.name()?;
        if target.eq_ignore_ascii_case("xml") {
            return None;
        }
        let end = find(self.rest(), b"?>")?;
        if end > 0 && !matches!(self.s[self.at], b' ' | b'\t' | b'\n' | b'\r') {
            return None;
        }
        self.at += end + 2;
        Some(())
    }

    fn element(&mut self) -> Option<Element> {
        if !self.starts(b"<") {
            return None;
        }
        self.at += 1;
        let qname = self.name()?;
        let mut attributes: Vec<(String, String)> = Vec::new();
        let mut seen: Vec<String> = Vec::new();
        loop {
            let spaced = self.spaces();
            if self.starts(b"/>") {
                self.at += 2;
                return Some(Element { name: local(&qname), attributes, ..Element::default() });
            }
            if self.starts(b">") {
                self.at += 1;
                break;
            }
            if !spaced {
                return None;
            }
            let name = self.name()?;
            self.spaces();
            if !self.starts(b"=") {
                return None;
            }
            self.at += 1;
            self.spaces();
            let value = self.attribute_value()?;
            if seen.contains(&name) {
                return None;
            }
            seen.push(name.clone());
            if name != "xmlns" && !name.contains(':') {
                attributes.push((name, value));
            }
        }
        let mut element = Element { name: local(&qname), attributes, ..Element::default() };
        loop {
            if self.at >= self.s.len() {
                return None;
            }
            if self.starts(b"</") {
                self.at += 2;
                let end = self.name()?;
                if end != qname {
                    return None;
                }
                self.spaces();
                if !self.starts(b">") {
                    return None;
                }
                self.at += 1;
                return Some(element);
            }
            if self.starts(b"<!--") {
                self.comment()?;
            } else if self.starts(b"<![CDATA[") {
                let body = &self.rest()[9..];
                let end = find(body, b"]]>")?;
                let text = std::str::from_utf8(&body[..end]).ok()?;
                if !text.chars().all(is_char) {
                    return None;
                }
                element.text.push_str(&text.replace("\r\n", "\n").replace('\r', "\n"));
                self.at += 9 + end + 3;
            } else if self.starts(b"<?") {
                self.pi()?;
            } else if self.starts(b"<") {
                let child = self.element()?;
                element.children.push(child);
            } else {
                let text = self.char_data()?;
                element.text.push_str(&text);
            }
        }
    }

    /// Text up to the next `<`, with references resolved and line ends normalised.
    fn char_data(&mut self) -> Option<String> {
        let start = self.at;
        while self.at < self.s.len() && self.s[self.at] != b'<' {
            self.at += 1;
        }
        let raw = std::str::from_utf8(&self.s[start..self.at]).ok()?;
        if raw.contains("]]>") {
            return None;
        }
        unescape(&raw.replace("\r\n", "\n").replace('\r', "\n"))
    }

    fn attribute_value(&mut self) -> Option<String> {
        let quote = *self.s.get(self.at)?;
        if quote != b'"' && quote != b'\'' {
            return None;
        }
        let body = &self.rest()[1..];
        let end = body.iter().position(|&b| b == quote)?;
        let raw = std::str::from_utf8(&body[..end]).ok()?;
        if raw.contains('<') {
            return None;
        }
        self.at += end + 2;
        let normalised = raw.replace("\r\n", " ").replace(['\r', '\n', '\t'], " ");
        unescape(&normalised)
    }
}

/// Resolves the predefined entities and character references; `None` for
/// any other reference or a bare `&`.
fn unescape(raw: &str) -> Option<String> {
    if !raw.chars().all(is_char) {
        return None;
    }
    if !raw.contains('&') {
        return Some(raw.to_owned());
    }
    let mut out = String::with_capacity(raw.len());
    let mut rest = raw;
    while let Some(amp) = rest.find('&') {
        out.push_str(&rest[..amp]);
        let after = &rest[amp + 1..];
        let semi = after.find(';')?;
        let entity = &after[..semi];
        let c = match entity {
            "amp" => '&',
            "lt" => '<',
            "gt" => '>',
            "quot" => '"',
            "apos" => '\'',
            _ => {
                let code = if let Some(hex) = entity.strip_prefix("#x") {
                    if hex.is_empty() || !hex.bytes().all(|b| b.is_ascii_hexdigit()) {
                        return None;
                    }
                    u32::from_str_radix(hex, 16).ok()?
                } else {
                    let dec = entity.strip_prefix('#')?;
                    if dec.is_empty() || !dec.bytes().all(|b| b.is_ascii_digit()) {
                        return None;
                    }
                    dec.parse().ok()?
                };
                let c = char::from_u32(code)?;
                if !is_char(c) {
                    return None;
                }
                c
            }
        };
        out.push(c);
        rest = &after[semi + 1..];
    }
    out.push_str(rest);
    Some(out)
}

fn local(qname: &str) -> String {
    qname.rsplit(':').next().unwrap_or(qname).to_owned()
}

fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack.windows(needle.len()).position(|w| w == needle)
}
