//! The XML the AWS providers send and read: documents parsed as
//! `simplexml_load_string()` reads them, built as `SimpleXMLElement`
//! writes them (`asXML()`), and the signed request and reply around them.

use quick_xml::events::Event;
use utopia_client::{Adapter, Request};

use crate::Error;
use crate::aws::Signature;

/// An element: its local name, its own text (CDATA included, not that of
/// its children) and its child elements.
#[derive(Debug, Clone, Default, PartialEq)]
pub(crate) struct Element {
    pub name: String,
    pub text: String,
    pub children: Vec<Element>,
}

impl Element {
    /// An element without text or children.
    pub fn new(name: &str) -> Element {
        Element { name: name.to_owned(), ..Element::default() }
    }

    /// A leaf element holding `text`.
    pub fn leaf(name: &str, text: &str) -> Element {
        Element { name: name.to_owned(), text: text.to_owned(), children: Vec::new() }
    }

    /// Appends a child and returns it.
    ///
    /// PHP: `addChild()`.
    pub fn add(&mut self, child: Element) -> &mut Element {
        self.children.push(child);
        self.children.last_mut().expect("just pushed")
    }

    /// The first child named `name`.
    pub fn child(&self, name: &str) -> Option<&Element> {
        self.children.iter().find(|c| c.name == name)
    }

    /// The trimmed text of the first child named `name`; `None` when there
    /// is no such child or its text is empty.
    pub fn text_of(&self, name: &str) -> Option<String> {
        let text = php_trim(&self.child(name)?.text);
        (!text.is_empty()).then(|| text.to_owned())
    }

    /// The children named `item` of the first child named `name`.
    pub fn list<'a>(&'a self, name: &str, item: &'a str) -> impl Iterator<Item = &'a Element> + 'a {
        self.child(name).into_iter().flat_map(move |c| c.children.iter().filter(move |e| e.name == item))
    }

    /// The document `asXML()` writes for this root, declaring `namespace`
    /// as its default namespace: `<?xml version="1.0"?>`, a line feed, the
    /// element, a line feed.
    pub fn document(&self, namespace: &str) -> String {
        let mut out = String::from("<?xml version=\"1.0\"?>\n");
        self.write(&mut out, Some(namespace));
        out.push('\n');
        out
    }

    fn write(&self, out: &mut String, namespace: Option<&str>) {
        out.push('<');
        out.push_str(&self.name);
        if let Some(namespace) = namespace {
            out.push_str(" xmlns=\"");
            escape_into(out, namespace, true);
            out.push('"');
        }
        if self.text.is_empty() && self.children.is_empty() {
            out.push_str("/>");
            return;
        }
        out.push('>');
        escape_into(out, &self.text, false);
        for child in &self.children {
            child.write(out, None);
        }
        out.push_str("</");
        out.push_str(&self.name);
        out.push('>');
    }
}

/// PHP's `trim()` with its default characters.
pub(crate) fn php_trim(s: &str) -> &str {
    s.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B'])
}

/// Text as libxml writes it into a document without an encoding: `&`, `<`
/// and `>` (and `"` in an attribute) as entities, a carriage return and
/// every character beyond ASCII as a hexadecimal character reference, and
/// a control character XML cannot hold as U+FFFD.
fn escape_into(out: &mut String, text: &str, attribute: bool) {
    for c in text.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' if attribute => out.push_str("&quot;"),
            '\r' => out.push_str("&#xD;"),
            '\t' | '\n' => out.push(c),
            c if (c as u32) < 0x20 => out.push_str("&#xFFFD;"),
            c if c.is_ascii() => out.push(c),
            c => out.push_str(&format!("&#x{:X};", c as u32)),
        }
    }
}

/// Parses a document; `None` where `simplexml_load_string()` returns
/// false.
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
                stack.push(Element::new(&String::from_utf8_lossy(start.local_name().as_ref())));
            }
            Event::Empty(start) => {
                if root.is_some() {
                    return None;
                }
                let element = Element::new(&String::from_utf8_lossy(start.local_name().as_ref()));
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
                    current.text.push_str(std::str::from_utf8(&raw).ok()?);
                } else if !raw.iter().all(u8::is_ascii_whitespace) {
                    return None;
                }
            }
            Event::GeneralRef(reference) => {
                let current = stack.last_mut()?;
                current.text.push(resolve(&reference)?);
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

/// A character or predefined entity reference (`amp`, `#34`, `#x22`).
fn resolve(name: &[u8]) -> Option<char> {
    let name = std::str::from_utf8(name).ok()?;
    Some(match name {
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
    })
}

/// A response body: a parsed document, a body that is not XML (kept as a
/// string in PHP, where nothing reads it), or nothing.
#[derive(Debug, Clone)]
pub(crate) enum Body {
    Xml(Element),
    Raw,
    None,
}

impl Body {
    pub fn xml(&self) -> Option<&Element> {
        match self {
            Body::Xml(e) => Some(e),
            _ => None,
        }
    }
}

/// What an AWS provider gets back from one request.
///
/// PHP: the `array{statusCode, response, etag, error}` the CloudFront
/// providers' `request()` returns.
pub(crate) struct Reply {
    /// The status code; 0 when no response came back.
    pub status: i64,
    pub response: Body,
    /// The `ETag` header; `None` when absent or empty.
    pub etag: Option<String>,
    /// The transport's message when no response came back.
    pub error: Option<String>,
}

impl Reply {
    pub fn is_2xx(&self) -> bool {
        (200..300).contains(&self.status)
    }

    /// `Error/Code` of an XML error body; `None` when absent or empty.
    pub fn error_code(&self) -> Option<String> {
        self.response.xml()?.child("Error")?.text_of("Code")
    }
}

/// Signs the request for CloudFront and sends it. A failure of the
/// transport to get a response becomes a [`Reply`] with status 0 and its
/// message. An empty body is [`Body::Raw`] (PHP's `""`) when `empty_is_raw`,
/// [`Body::None`] otherwise.
pub(crate) async fn send<C: Adapter>(
    client: &C,
    signature: &Signature,
    request: Request,
    empty_is_raw: bool,
) -> Result<Reply, Error> {
    let request = signature.sign(request, "cloudfront", "us-east-1", None)?;
    let response = match client.send(request).await {
        Ok(response) => response,
        Err(e) if e.is_client() => {
            return Ok(Reply { status: 0, response: Body::None, etag: None, error: Some(e.message().to_owned()) });
        }
        Err(e) => return Err(Error::Http(e)),
    };
    let contents = response.bytes();
    let body = if contents.is_empty() {
        if empty_is_raw { Body::Raw } else { Body::None }
    } else {
        match parse(contents) {
            Some(element) => Body::Xml(element),
            None => Body::Raw,
        }
    };
    let etag = response.headers().line("ETag");
    Ok(Reply {
        status: i64::from(response.status()),
        response: body,
        etag: (!etag.is_empty()).then_some(etag),
        error: None,
    })
}
