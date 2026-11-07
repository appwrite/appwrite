//! An HTML document parsed the way PHP's `Dom\HTMLDocument::createFromString()`
//! parses it: the HTML5 tree construction algorithm with scripting disabled
//! (so `<noscript>` holds markup), errors ignored, and the encoding the
//! document declares in its first bytes applied to them.
//!
//! The tree keeps what [`super::mime`] reads off it: elements with their
//! attributes, text, and everything else as childless nodes.

use std::borrow::Cow;
use std::cell::RefCell;

use encoding_rs::{Encoding, REPLACEMENT, UTF_8};
use html5ever::interface::tree_builder::{ElemName, ElementFlags, NodeOrText, QuirksMode, TreeSink};
use html5ever::tendril::{StrTendril, TendrilSink};
use html5ever::tree_builder::TreeBuilderOpts;
use html5ever::{Attribute, LocalName, Namespace, ParseOpts, QualName, local_name, ns, parse_document};

/// A node of the document.
pub enum Node {
    Document,
    Element {
        name: QualName,
        attributes: Vec<Attribute>,
        /// A `<template>`'s contents, which are not among its children.
        template: Option<usize>,
    },
    Text(String),
    /// A comment, doctype or processing instruction.
    Other,
}

struct Slot {
    parent: Option<usize>,
    children: Vec<usize>,
    node: Node,
}

/// A parsed document. Node 0 is the document itself.
pub struct Document {
    slots: Vec<Slot>,
}

impl Document {
    /// `source` parsed as an HTML document.
    pub fn parse(source: &str) -> Self {
        let opts = ParseOpts {
            tree_builder: TreeBuilderOpts { scripting_enabled: false, ..Default::default() },
            ..Default::default()
        };
        let sink =
            Sink { slots: RefCell::new(vec![Slot { parent: None, children: Vec::new(), node: Node::Document }]) };
        parse_document(sink, opts).one(StrTendril::from(&*decode(source)))
    }

    pub fn node(&self, id: usize) -> &Node {
        &self.slots[id].node
    }

    pub fn children(&self, id: usize) -> &[usize] {
        &self.slots[id].children
    }

    /// The first of the `<html>` element's children that is a `<body>` or a
    /// `<frameset>` (PHP: `$document->body`).
    pub fn body(&self) -> Option<usize> {
        let html = self.children(0).iter().copied().find(|&id| self.is_html(id, &local_name!("html")))?;
        self.children(html)
            .iter()
            .copied()
            .find(|&id| self.is_html(id, &local_name!("body")) || self.is_html(id, &local_name!("frameset")))
    }

    fn is_html(&self, id: usize, local: &LocalName) -> bool {
        matches!(self.node(id), Node::Element { name, .. } if name.ns == ns!(html) && name.local == *local)
    }
}

/// An element's value of the attribute whose qualified name is `name`
/// (PHP: `Element::getAttribute()`).
pub fn attribute<'a>(attributes: &'a [Attribute], name: &str) -> Option<&'a str> {
    attributes.iter().find(|a| a.name.prefix.is_none() && &*a.name.local == name).map(|a| &*a.value)
}

/// `source` read in the encoding its first 1024 bytes declare, as lexbor
/// reads it for PHP: a byte order mark first, then the first `<meta>` that
/// names a character set (the HTML prescan, which takes that name even when
/// it is no encoding and so falls back to UTF-8, and keeps UTF-16 and
/// x-user-defined as named). A name of the replacement encoding leaves
/// nothing to read.
fn decode(source: &str) -> Cow<'_, str> {
    if source.starts_with('\u{FEFF}') {
        return Cow::Borrowed(source);
    }
    let bytes = source.as_bytes();
    let Some(label) = prescan(&bytes[..bytes.len().min(1024)]) else {
        return Cow::Borrowed(source);
    };
    match Encoding::for_label(&label) {
        None => Cow::Borrowed(source),
        Some(encoding) if encoding == UTF_8 => Cow::Borrowed(source),
        Some(encoding) if encoding == REPLACEMENT => Cow::Borrowed(""),
        Some(encoding) => Cow::Owned(encoding.decode_without_bom_handling(bytes).0.into_owned()),
    }
}

fn is_space(byte: u8) -> bool {
    matches!(byte, b'\t' | b'\n' | b'\x0C' | b'\r' | b' ')
}

/// The character set the first `<meta>` declaring one names, by the HTML
/// "prescan a byte stream to determine its encoding" algorithm.
fn prescan(bytes: &[u8]) -> Option<Vec<u8>> {
    let starts = |at: usize, prefix: &[u8]| {
        bytes.len() >= at + prefix.len() && bytes[at..at + prefix.len()].eq_ignore_ascii_case(prefix)
    };
    let mut position = 0;
    while position < bytes.len() {
        if starts(position, b"<!--") {
            // The first `-->`, whose dashes may be those of the `<!--`.
            let mut end = position + 4;
            while end < bytes.len() && !(bytes[end] == b'>' && bytes[end - 2] == b'-' && bytes[end - 1] == b'-') {
                end += 1;
            }
            position = end;
        } else if starts(position, b"<meta") && bytes.get(position + 5).is_some_and(|&b| is_space(b) || b == b'/') {
            position += 6;
            let mut names: Vec<Vec<u8>> = Vec::new();
            let mut pragma = false;
            let mut need_pragma = None;
            let mut charset = None;
            while let Some((name, value)) = meta_attribute(bytes, &mut position)? {
                if names.contains(&name) {
                    continue;
                }
                match name.as_slice() {
                    b"http-equiv" if value == b"content-type" => pragma = true,
                    b"content" if charset.is_none() => {
                        if let Some(found) = content_charset(&value) {
                            charset = Some(found);
                            need_pragma = Some(true);
                        }
                    }
                    b"charset" => {
                        charset = Some(value.clone());
                        need_pragma = Some(false);
                    }
                    _ => {}
                }
                names.push(name);
            }
            match (need_pragma, charset) {
                (Some(true), Some(_)) if !pragma => {}
                (Some(_), Some(charset)) => return Some(charset),
                _ => {}
            }
        } else if bytes[position] == b'<'
            && (bytes.get(position + 1).is_some_and(u8::is_ascii_alphabetic)
                || (bytes.get(position + 1) == Some(&b'/')
                    && bytes.get(position + 2).is_some_and(u8::is_ascii_alphabetic)))
        {
            while position < bytes.len() && !is_space(bytes[position]) && bytes[position] != b'>' {
                position += 1;
            }
            while meta_attribute(bytes, &mut position)?.is_some() {}
        } else if starts(position, b"<!") || starts(position, b"</") || starts(position, b"<?") {
            while position < bytes.len() && bytes[position] != b'>' {
                position += 1;
            }
        }
        position += 1;
    }
    None
}

/// The prescan's "get an attribute": `Some(None)` when the tag ends,
/// `None` when the bytes run out first.
#[allow(clippy::type_complexity)]
fn meta_attribute(bytes: &[u8], position: &mut usize) -> Option<Option<(Vec<u8>, Vec<u8>)>> {
    let at = |p: usize| bytes.get(p).copied();
    while is_space(at(*position)?) || at(*position)? == b'/' {
        *position += 1;
    }
    if at(*position)? == b'>' {
        return Some(None);
    }
    let mut name = Vec::new();
    let mut value = Vec::new();
    loop {
        let byte = at(*position)?;
        if byte == b'=' && !name.is_empty() {
            *position += 1;
            break;
        }
        if is_space(byte) {
            while is_space(at(*position)?) {
                *position += 1;
            }
            if at(*position)? != b'=' {
                return Some(Some((name, value)));
            }
            *position += 1;
            break;
        }
        if byte == b'/' || byte == b'>' {
            return Some(Some((name, value)));
        }
        name.push(byte.to_ascii_lowercase());
        *position += 1;
    }
    while is_space(at(*position)?) {
        *position += 1;
    }
    let byte = at(*position)?;
    if byte == b'"' || byte == b'\'' {
        loop {
            *position += 1;
            let next = at(*position)?;
            if next == byte {
                *position += 1;
                return Some(Some((name, value)));
            }
            value.push(next.to_ascii_lowercase());
        }
    }
    if byte == b'>' {
        return Some(Some((name, value)));
    }
    loop {
        let byte = at(*position)?;
        if is_space(byte) || byte == b'>' {
            return Some(Some((name, value)));
        }
        value.push(byte.to_ascii_lowercase());
        *position += 1;
    }
}

/// The character set a `<meta>` element's `content` names
/// (`text/html; charset=utf-8`).
fn content_charset(content: &[u8]) -> Option<Vec<u8>> {
    let mut position = 0;
    loop {
        let found = content[position..].windows(7).position(|w| w.eq_ignore_ascii_case(b"charset"))?;
        position += found + 7;
        while content.get(position).is_some_and(|&b| is_space(b)) {
            position += 1;
        }
        if content.get(position) == Some(&b'=') {
            break;
        }
    }
    position += 1;
    while content.get(position).is_some_and(|&b| is_space(b)) {
        position += 1;
    }
    let rest = &content[position..];
    match rest.first()? {
        quote @ (b'"' | b'\'') => {
            let end = rest[1..].iter().position(|b| b == quote)?;
            Some(rest[1..=end].to_vec())
        }
        _ => {
            let end = rest.iter().position(|&b| is_space(b) || b == b';').unwrap_or(rest.len());
            Some(rest[..end].to_vec())
        }
    }
}

struct Sink {
    slots: RefCell<Vec<Slot>>,
}

#[derive(Debug)]
struct Name(QualName);

impl ElemName for Name {
    fn ns(&self) -> &Namespace {
        &self.0.ns
    }

    fn local_name(&self) -> &LocalName {
        &self.0.local
    }
}

impl Sink {
    fn add(&self, node: Node) -> usize {
        let mut slots = self.slots.borrow_mut();
        slots.push(Slot { parent: None, children: Vec::new(), node });
        slots.len() - 1
    }

    fn detach(&self, id: usize) {
        let mut slots = self.slots.borrow_mut();
        if let Some(parent) = slots[id].parent.take() {
            slots[parent].children.retain(|&child| child != id);
        }
    }

    /// Puts `child` into `parent` at `index`, or appends text to the text
    /// node before that position.
    fn insert(&self, parent: usize, index: usize, child: NodeOrText<usize>) {
        let id = match child {
            NodeOrText::AppendText(text) => {
                let mut slots = self.slots.borrow_mut();
                if index > 0 {
                    let previous = slots[parent].children[index - 1];
                    if let Node::Text(existing) = &mut slots[previous].node {
                        existing.push_str(&text);
                        return;
                    }
                }
                drop(slots);
                self.add(Node::Text(text.to_string()))
            }
            NodeOrText::AppendNode(id) => id,
        };
        self.detach(id);
        let mut slots = self.slots.borrow_mut();
        // Detaching may have moved the position when `id` was an earlier sibling.
        let index = index.min(slots[parent].children.len());
        slots[parent].children.insert(index, id);
        slots[id].parent = Some(parent);
    }
}

impl TreeSink for Sink {
    type Handle = usize;
    type Output = Document;
    type ElemName<'a> = Name;

    fn finish(self) -> Document {
        Document { slots: self.slots.into_inner() }
    }

    fn parse_error(&self, _: Cow<'static, str>) {}

    fn get_document(&self) -> usize {
        0
    }

    fn elem_name<'a>(&'a self, target: &'a usize) -> Name {
        match &self.slots.borrow()[*target].node {
            Node::Element { name, .. } => Name(name.clone()),
            _ => unreachable!("only elements have names"),
        }
    }

    fn create_element(&self, name: QualName, attributes: Vec<Attribute>, flags: ElementFlags) -> usize {
        let template = flags.template.then(|| self.add(Node::Document));
        self.add(Node::Element { name, attributes, template })
    }

    fn create_comment(&self, _: StrTendril) -> usize {
        self.add(Node::Other)
    }

    fn create_pi(&self, _: StrTendril, _: StrTendril) -> usize {
        self.add(Node::Other)
    }

    fn append(&self, parent: &usize, child: NodeOrText<usize>) {
        let index = self.slots.borrow()[*parent].children.len();
        self.insert(*parent, index, child);
    }

    fn append_based_on_parent_node(&self, element: &usize, previous: &usize, child: NodeOrText<usize>) {
        if self.slots.borrow()[*element].parent.is_some() {
            self.append_before_sibling(element, child);
        } else {
            self.append(previous, child);
        }
    }

    fn append_doctype_to_document(&self, _: StrTendril, _: StrTendril, _: StrTendril) {
        let id = self.add(Node::Other);
        self.append(&0, NodeOrText::AppendNode(id));
    }

    fn get_template_contents(&self, target: &usize) -> usize {
        match &self.slots.borrow()[*target].node {
            Node::Element { template: Some(contents), .. } => *contents,
            _ => unreachable!("only templates have contents"),
        }
    }

    fn same_node(&self, x: &usize, y: &usize) -> bool {
        x == y
    }

    fn set_quirks_mode(&self, _: QuirksMode) {}

    fn append_before_sibling(&self, sibling: &usize, child: NodeOrText<usize>) {
        let (parent, index) = {
            let slots = self.slots.borrow();
            let Some(parent) = slots[*sibling].parent else {
                return;
            };
            let index = slots[parent].children.iter().position(|&c| c == *sibling).unwrap_or(0);
            (parent, index)
        };
        if let NodeOrText::AppendNode(id) = &child {
            // Moving a node that sits before the sibling shifts the sibling left.
            let slots = self.slots.borrow();
            if slots[*id].parent == Some(parent) && slots[parent].children.iter().position(|&c| c == *id) < Some(index)
            {
                drop(slots);
                self.insert(parent, index - 1, child);
                return;
            }
        }
        self.insert(parent, index, child);
    }

    fn add_attrs_if_missing(&self, target: &usize, attrs: Vec<Attribute>) {
        if let Node::Element { attributes, .. } = &mut self.slots.borrow_mut()[*target].node {
            for attr in attrs {
                if !attributes.iter().any(|a| a.name == attr.name) {
                    attributes.push(attr);
                }
            }
        }
    }

    fn remove_from_parent(&self, target: &usize) {
        self.detach(*target);
    }

    fn reparent_children(&self, node: &usize, new_parent: &usize) {
        let children = std::mem::take(&mut self.slots.borrow_mut()[*node].children);
        for child in children {
            self.slots.borrow_mut()[child].parent = None;
            self.append(new_parent, NodeOrText::AppendNode(child));
        }
    }
}
