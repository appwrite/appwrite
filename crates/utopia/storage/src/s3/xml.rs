//! S3's XML responses, read as PHP's SimpleXML reads them.

use indexmap::IndexMap;
use quick_xml::events::Event;

/// An element: its local name, its own text (CDATA included) and its child
/// elements.
#[derive(Debug, Clone, Default, PartialEq)]
pub(crate) struct Element {
    pub name: String,
    pub text: String,
    pub children: Vec<Element>,
}

impl Element {
    /// The text of the first child named `name`.
    ///
    /// PHP: `(string) ($xml->Name ?? '')`.
    pub fn child_text(&self, name: &str) -> Option<String> {
        self.children.iter().find(|c| c.name == name).map(|c| c.text.clone())
    }

    /// The element as `xmlToArray()` turns it into PHP arrays.
    pub fn decode(&self) -> Decoded {
        if self.children.is_empty() {
            return if self.text.is_empty() {
                Decoded::Array(IndexMap::new())
            } else {
                Decoded::Text(self.text.clone())
            };
        }
        let mut grouped: IndexMap<String, Vec<Decoded>> = IndexMap::new();
        for child in &self.children {
            grouped.entry(child.name.clone()).or_default().push(child.decode());
        }
        Decoded::Array(
            grouped
                .into_iter()
                .map(|(name, mut values)| {
                    let entry = if values.len() == 1 { Entry::One(values.remove(0)) } else { Entry::Many(values) };
                    (name, entry)
                })
                .collect(),
        )
    }
}

/// A decoded element: text, or an array of its children by name.
#[derive(Debug, Clone, PartialEq)]
pub(crate) enum Decoded {
    Text(String),
    Array(IndexMap<String, Entry>),
}

/// The children of one name: one value, or a list when the name repeats.
#[derive(Debug, Clone, PartialEq)]
pub(crate) enum Entry {
    One(Decoded),
    Many(Vec<Decoded>),
}

impl Decoded {
    /// `$array[$name]` as a string, when it is one.
    pub fn text(&self, name: &str) -> Option<&str> {
        match self.get(name)? {
            Entry::One(Decoded::Text(t)) => Some(t),
            _ => None,
        }
    }

    pub fn get(&self, name: &str) -> Option<&Entry> {
        match self {
            Decoded::Array(map) => map.get(name),
            Decoded::Text(_) => None,
        }
    }

    /// The array, when it is one.
    pub fn as_array(&self) -> Option<&IndexMap<String, Entry>> {
        match self {
            Decoded::Array(map) => Some(map),
            Decoded::Text(_) => None,
        }
    }

    /// The entries listed under `name`, as the device reads them: one
    /// element with a `Key` stands for itself, several are a list.
    ///
    /// PHP: `is_array($contents) ? (isset($contents['Key']) ? [$contents] : $contents) : []`,
    /// keeping the entries that are arrays.
    pub fn entries(&self, name: &str) -> Vec<&IndexMap<String, Entry>> {
        match self.get(name) {
            None => Vec::new(),
            Some(Entry::Many(list)) => list.iter().filter_map(Decoded::as_array).collect(),
            Some(Entry::One(Decoded::Text(_))) => Vec::new(),
            Some(Entry::One(Decoded::Array(map))) => {
                if map.contains_key("Key") {
                    vec![map]
                } else {
                    map.values()
                        .filter_map(|e| match e {
                            Entry::One(Decoded::Array(m)) => Some(m),
                            _ => None,
                        })
                        .collect()
                }
            }
        }
    }
}

/// `$array[$name]` of an entry map, as a string when it is one.
pub(crate) fn text<'a>(map: &'a IndexMap<String, Entry>, name: &str) -> Option<&'a str> {
    match map.get(name)? {
        Entry::One(Decoded::Text(t)) => Some(t),
        _ => None,
    }
}

/// Parses a document; `None` where `simplexml_load_string()` returns false.
pub(crate) fn parse(body: &[u8]) -> Option<Element> {
    let mut reader = quick_xml::Reader::from_reader(body);
    let mut stack: Vec<Element> = Vec::new();
    let mut root = None;
    loop {
        match reader.read_event().ok()? {
            Event::Start(start) => {
                if root.is_some() {
                    return None;
                }
                stack.push(Element { name: local(start.local_name().as_ref()), ..Element::default() });
            }
            Event::Empty(start) => {
                if root.is_some() {
                    return None;
                }
                let element = Element { name: local(start.local_name().as_ref()), ..Element::default() };
                match stack.last_mut() {
                    Some(parent) => parent.children.push(element),
                    None => root = Some(element),
                }
            }
            Event::End(_) => {
                let element = stack.pop()?;
                match stack.last_mut() {
                    Some(parent) => parent.children.push(element),
                    None => root = Some(element),
                }
            }
            Event::Text(text) => {
                let raw = text.into_inner();
                if let Some(current) = stack.last_mut() {
                    current.text.push_str(&unescape(&raw)?);
                } else if !raw.iter().all(u8::is_ascii_whitespace) {
                    return None;
                }
            }
            Event::GeneralRef(reference) => {
                let current = stack.last_mut()?;
                current.text.push_str(&resolve(&reference)?);
            }
            Event::CData(data) => {
                let current = stack.last_mut()?;
                current.text.push_str(std::str::from_utf8(&data).ok()?);
            }
            Event::Eof => break,
            _ => {}
        }
    }
    if !stack.is_empty() {
        return None;
    }
    root
}

fn local(name: &[u8]) -> String {
    String::from_utf8_lossy(name).into_owned()
}

fn unescape(raw: &[u8]) -> Option<String> {
    let s = std::str::from_utf8(raw).ok()?;
    if !s.contains('&') {
        return Some(s.to_owned());
    }
    quick_xml::escape::unescape(s).ok().map(|c| c.into_owned())
}

/// A character or predefined entity reference (`amp`, `#34`, `#x22`).
fn resolve(name: &[u8]) -> Option<String> {
    let name = std::str::from_utf8(name).ok()?;
    let c = match name {
        "amp" => '&',
        "lt" => '<',
        "gt" => '>',
        "quot" => '"',
        "apos" => '\'',
        _ => {
            let code = if let Some(hex) = name.strip_prefix("#x") {
                u32::from_str_radix(hex, 16).ok()?
            } else {
                name.strip_prefix('#')?.parse().ok()?
            };
            char::from_u32(code)?
        }
    };
    Some(c.to_string())
}
