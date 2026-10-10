//! The XML OpenSRS answers in, read as SimpleXML reads it: a document of
//! elements with attributes and text, queried with the location paths the
//! adapter uses (`//body/data_block/dt_assoc/item[@key="response_code"]`,
//! `dt_assoc/item`, unions with `|`).

/// An element.
#[derive(Debug)]
struct Element {
    name: String,
    attributes: Vec<(String, String)>,
    /// The text directly inside the element (what `(string) $element` is).
    text: String,
    children: Vec<usize>,
}

/// A parsed document; elements are numbered in document order.
#[derive(Debug)]
pub(crate) struct Document {
    elements: Vec<Element>,
}

/// An element of a [`Document`].
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub(crate) struct Node(usize);

struct Parser<'s> {
    s: &'s [u8],
    pos: usize,
}

impl Parser<'_> {
    fn starts_with(&self, prefix: &[u8]) -> bool {
        self.s[self.pos..].starts_with(prefix)
    }

    fn skip_past(&mut self, end: &[u8]) -> Option<()> {
        let found = self.s[self.pos..].windows(end.len()).position(|w| w == end)?;
        self.pos += found + end.len();
        Some(())
    }

    fn skip_space(&mut self) {
        while self.pos < self.s.len() && self.s[self.pos].is_ascii_whitespace() {
            self.pos += 1;
        }
    }

    fn name(&mut self) -> Option<String> {
        let start = self.pos;
        while self.pos < self.s.len() {
            let c = self.s[self.pos];
            if c.is_ascii_whitespace() || matches!(c, b'/' | b'>' | b'=') {
                break;
            }
            self.pos += 1;
        }
        (self.pos > start).then(|| String::from_utf8_lossy(&self.s[start..self.pos]).into_owned())
    }

    /// Skips a `<!DOCTYPE ...>` with an optional internal subset.
    fn doctype(&mut self) -> Option<()> {
        let mut depth = 0;
        while self.pos < self.s.len() {
            match self.s[self.pos] {
                b'[' => depth += 1,
                b']' => depth -= 1,
                b'>' if depth == 0 => {
                    self.pos += 1;
                    return Some(());
                }
                _ => {}
            }
            self.pos += 1;
        }
        None
    }
}

/// Decodes the predefined and numeric entities; `None` for any other.
fn unescape(raw: &[u8]) -> Option<String> {
    let mut out = Vec::with_capacity(raw.len());
    let mut i = 0;
    while i < raw.len() {
        if raw[i] != b'&' {
            out.push(raw[i]);
            i += 1;
            continue;
        }
        let end = raw[i..].iter().position(|&c| c == b';')? + i;
        let entity = std::str::from_utf8(&raw[i + 1..end]).ok()?;
        let decoded = match entity {
            "lt" => '<',
            "gt" => '>',
            "amp" => '&',
            "quot" => '"',
            "apos" => '\'',
            _ => {
                let code = if let Some(hex) = entity.strip_prefix("#x") {
                    u32::from_str_radix(hex, 16).ok()?
                } else {
                    entity.strip_prefix('#')?.parse().ok()?
                };
                char::from_u32(code).filter(|&c| c != '\0')?
            }
        };
        let mut buf = [0; 4];
        out.extend_from_slice(decoded.encode_utf8(&mut buf).as_bytes());
        i = end + 1;
    }
    String::from_utf8(out).ok()
}

impl Document {
    /// `simplexml_load_string()`: `None` where PHP returns `false` (empty or
    /// malformed input).
    pub(crate) fn parse(source: &[u8]) -> Option<Document> {
        let mut p = Parser { s: source, pos: 0 };
        let mut elements: Vec<Element> = Vec::new();
        let mut stack: Vec<usize> = Vec::new();
        let mut root = None;
        while p.pos < p.s.len() {
            if p.starts_with(b"<?") {
                // A processing instruction: a target name, then space or `?>`;
                // the XML declaration only at the very start.
                let at = p.pos;
                p.pos += 2;
                let start = p.pos;
                while p
                    .s
                    .get(p.pos)
                    .is_some_and(|&c| c.is_ascii_alphanumeric() || matches!(c, b'_' | b':' | b'.' | b'-'))
                {
                    p.pos += 1;
                }
                let target = String::from_utf8_lossy(&p.s[start..p.pos]).into_owned();
                if !target.starts_with(|c: char| c.is_ascii_alphabetic() || c == '_' || c == ':')
                    || (target.eq_ignore_ascii_case("xml") && at != 0)
                    || !(p.starts_with(b"?>") || p.s.get(p.pos).is_some_and(u8::is_ascii_whitespace))
                {
                    return None;
                }
                p.skip_past(b"?>")?;
            } else if p.starts_with(b"<!--") {
                p.skip_past(b"-->")?;
            } else if p.starts_with(b"<![CDATA[") {
                let parent = *stack.last()?;
                p.pos += 9;
                let start = p.pos;
                p.skip_past(b"]]>")?;
                let text = std::str::from_utf8(&p.s[start..p.pos - 3]).ok()?;
                elements[parent].text.push_str(text);
            } else if p.starts_with(b"<!DOCTYPE") {
                p.doctype()?;
            } else if p.starts_with(b"</") {
                p.pos += 2;
                let name = p.name()?;
                p.skip_space();
                if !p.starts_with(b">") {
                    return None;
                }
                p.pos += 1;
                let open = stack.pop()?;
                if elements[open].name != name {
                    return None;
                }
            } else if p.starts_with(b"<") {
                p.pos += 1;
                let name = p.name()?;
                let mut attributes = Vec::new();
                loop {
                    p.skip_space();
                    if p.starts_with(b"/>") || p.starts_with(b">") {
                        break;
                    }
                    let key = p.name()?;
                    p.skip_space();
                    if !p.starts_with(b"=") {
                        return None;
                    }
                    p.pos += 1;
                    p.skip_space();
                    let quote = *p.s.get(p.pos).filter(|&&q| q == b'"' || q == b'\'')?;
                    p.pos += 1;
                    let start = p.pos;
                    while p.pos < p.s.len() && p.s[p.pos] != quote {
                        if p.s[p.pos] == b'<' {
                            return None;
                        }
                        p.pos += 1;
                    }
                    if p.pos >= p.s.len() {
                        return None;
                    }
                    let raw: Vec<u8> = p.s[start..p.pos]
                        .iter()
                        .map(|&c| if matches!(c, b'\t' | b'\n' | b'\r') { b' ' } else { c })
                        .collect();
                    p.pos += 1;
                    if attributes.iter().any(|(k, _)| *k == key) {
                        return None;
                    }
                    attributes.push((key, unescape(&raw)?));
                }
                let id = elements.len();
                elements.push(Element { name, attributes, text: String::new(), children: Vec::new() });
                match stack.last() {
                    Some(&parent) => elements[parent].children.push(id),
                    None if root.is_none() => root = Some(id),
                    None => return None,
                }
                if p.starts_with(b"/>") {
                    p.pos += 2;
                } else {
                    p.pos += 1;
                    stack.push(id);
                }
            } else {
                let start = p.pos;
                while p.pos < p.s.len() && p.s[p.pos] != b'<' {
                    p.pos += 1;
                }
                let raw = &p.s[start..p.pos];
                match stack.last() {
                    Some(&parent) => elements[parent].text.push_str(&unescape(raw)?),
                    None if raw.iter().all(u8::is_ascii_whitespace) => {}
                    None => return None,
                }
            }
        }
        (stack.is_empty() && root.is_some()).then_some(Document { elements })
    }

    /// The root element.
    pub(crate) fn root(&self) -> Node {
        Node(0)
    }

    /// `(string) $element`.
    pub(crate) fn text(&self, node: Node) -> &str {
        &self.elements[node.0].text
    }

    /// `(string) $element[$name]`: `""` when missing.
    pub(crate) fn attribute(&self, node: Node, name: &str) -> &str {
        self.elements[node.0].attributes.iter().find(|(k, _)| k == name).map_or("", |(_, v)| v.as_str())
    }

    fn matches(&self, id: usize, step: &Step<'_>) -> bool {
        let element = &self.elements[id];
        element.name == step.name
            && step.key.is_none_or(|key| element.attributes.iter().any(|(k, v)| k == "key" && v == key))
    }

    /// Every element under `id`, in document order.
    fn descendants(&self, id: usize, out: &mut Vec<usize>) {
        for &child in &self.elements[id].children {
            out.push(child);
            self.descendants(child, out);
        }
    }

    /// `$element->xpath($expression)` for the location paths the adapter
    /// uses, in document order.
    pub(crate) fn xpath(&self, context: Node, expression: &str) -> Vec<Node> {
        let mut found = Vec::new();
        for path in expression.split('|') {
            let path = path.trim();
            let (mut current, rest) = match path.strip_prefix("//") {
                Some(rest) => {
                    let mut all = vec![0];
                    self.descendants(0, &mut all);
                    let (first, rest) = rest.split_once('/').unwrap_or((rest, ""));
                    let step = Step::parse(first);
                    (all.into_iter().filter(|&id| self.matches(id, &step)).collect::<Vec<_>>(), rest)
                }
                None => (vec![context.0], path),
            };
            for step in rest.split('/').filter(|s| !s.is_empty()) {
                let step = Step::parse(step);
                current = current
                    .iter()
                    .flat_map(|&id| self.elements[id].children.iter().copied())
                    .filter(|&id| self.matches(id, &step))
                    .collect();
            }
            found.extend(current);
        }
        found.sort_unstable();
        found.dedup();
        found.into_iter().map(Node).collect()
    }
}

/// One location step: `name` or `name[@key="value"]`.
struct Step<'e> {
    name: &'e str,
    key: Option<&'e str>,
}

impl<'e> Step<'e> {
    fn parse(step: &'e str) -> Self {
        match step.split_once("[@key=") {
            Some((name, predicate)) => {
                let value = predicate.trim_end_matches(']');
                let value = value.get(1..value.len().saturating_sub(1)).unwrap_or("");
                Step { name, key: Some(value) }
            }
            None => Step { name: step, key: None },
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_and_queries() {
        let doc = Document::parse(
            b"<?xml version='1.0'?>\n<!DOCTYPE OPS_envelope SYSTEM 'ops.dtd'>\n<OPS_envelope><body><data_block><dt_assoc>\
              <item key=\"response_code\">200</item><item key='a'>x &amp; <![CDATA[<y>]]><b>no</b>z</item>\
              </dt_assoc></data_block></body></OPS_envelope>",
        )
        .unwrap();
        let code = doc.xpath(doc.root(), "//body/data_block/dt_assoc/item[@key=\"response_code\"]");
        assert_eq!(code.len(), 1);
        assert_eq!(doc.text(code[0]), "200");
        let a = doc.xpath(doc.root(), "//data_block/dt_assoc/item[@key=\"a\"]");
        assert_eq!(doc.text(a[0]), "x & <y>z");
        let items = doc.xpath(doc.root(), "//dt_assoc/item[@key=\"a\"] | //dt_assoc/item[@key=\"response_code\"]");
        assert_eq!(items.len(), 2);
        assert_eq!(doc.attribute(items[1], "key"), "a");
        assert!(Document::parse(b"").is_none());
        assert!(Document::parse(b"<a><b></a>").is_none());
        assert!(Document::parse(b"<a>&nbsp;</a>").is_none());
    }
}
