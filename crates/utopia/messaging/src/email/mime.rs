//! An email as a MIME message on the wire, which the SMTP adapter hands
//! over whole and SES sends as a raw message per recipient.
//!
//! PHP: `Utopia\Messaging\Adapter\Email\Mime`.

use utopia_smtp::{Address, Attachment, Message};

use super::filesize;
use super::html::{self, Document, Node};
use crate::error::Error;
use crate::message::{Email, Mailbox};

/// The message for `email`, addressed to `to` and `cc`, with `bcc` in the
/// envelope only, and `headers` of the adapter's own (`X-Mailer`), which
/// win over the email's headers of the same name in any case.
///
/// The text part is the content, or for HTML the text it reads as.
///
/// PHP: `Mime::message()`.
pub fn message(
    email: &Email,
    to: &[Mailbox],
    cc: &[Mailbox],
    bcc: &[Mailbox],
    headers: &[(String, String)],
) -> Result<Message, Error> {
    let mut builder = Message::builder(Address::new(email.from_email(), email.from_name())?, email.subject());
    for address in addresses(to)? {
        builder = builder.to(address);
    }
    for address in addresses(cc)? {
        builder = builder.cc(address);
    }
    for address in addresses(bcc)? {
        builder = builder.bcc(address);
    }
    if email.has_reply_to() {
        builder = builder.reply_to(Address::new(email.reply_to_email(), email.reply_to_name())?);
    }
    builder = builder.text(text(email));
    if email.is_html() {
        builder = builder.html(email.content());
    }
    for attachment in attachments(email)? {
        builder = builder.attachment(attachment);
    }
    for (name, value) in email.headers() {
        if !headers.iter().any(|(own, _)| own.eq_ignore_ascii_case(name)) {
            builder = builder.header(name.as_str(), value.as_str());
        }
    }
    for (name, value) in headers {
        builder = builder.header(name.as_str(), value.as_str());
    }
    Ok(builder.build()?)
}

/// The recipients as addresses (a missing name is empty).
///
/// PHP: `Mime::addresses()`.
pub fn addresses(recipients: &[Mailbox]) -> Result<Vec<Address>, Error> {
    recipients
        .iter()
        .map(|r| Address::new(r.email.as_str(), r.name.as_deref().unwrap_or("")).map_err(Error::from))
        .collect()
}

/// The email's attachments: read from their path when written, or from the
/// content given.
///
/// PHP: `Mime::attachments()`.
pub fn attachments(email: &Email) -> Result<Vec<Attachment>, Error> {
    email
        .attachments()
        .unwrap_or_default()
        .iter()
        .map(|a| {
            match a.content() {
                None => Attachment::from_path(a.path(), Some(a.name()), Some(a.mime_type())),
                Some(content) => Attachment::from_bytes(content, a.name(), Some(a.mime_type())),
            }
            .map_err(Error::from)
        })
        .collect()
}

/// What the attachments weigh before they are encoded.
///
/// Fails with [`Error::Exception`] for a file that cannot be read.
///
/// PHP: `Mime::size()`.
pub fn size(email: &Email) -> Result<u64, Error> {
    let mut size = 0u64;
    for attachment in email.attachments().unwrap_or_default() {
        size += match attachment.content() {
            Some(content) => content.len() as u64,
            None => filesize(attachment.path())
                .ok_or_else(|| Error::Exception(format!("Failed to read attachment file: {}", attachment.path())))?,
        };
    }
    Ok(size)
}

/// The reading a client without markup gets: the visible text with its
/// entities decoded, a line per block, and each link's address beside it so
/// a button still leads somewhere.
///
/// PHP: `Mime::text()`.
fn text(email: &Email) -> Vec<u8> {
    if !email.is_html() {
        return email.content().as_bytes().to_vec();
    }
    let document = Document::parse(email.content());
    let rendered = document.body().map(|body| render(&document, body, false)).unwrap_or_default();

    let mut text = String::new();
    for line in rendered.split('\n') {
        let line = line.trim_matches(' ');
        // Blocks end a line; a blank one between them keeps paragraphs apart.
        if !line.is_empty() || !text.ends_with("\n\n") {
            text.push_str(line);
            text.push('\n');
        }
    }

    // Preformatted lines are joined with a carriage return so the trim above leaves them alone.
    php_std::string::trim(text.as_bytes(), b" \t\n\r\0\x0B")
        .iter()
        .map(|&b| if b == b'\r' { b'\n' } else { b })
        .collect()
}

/// Characters that only pad a preview line when a text holds nothing else.
const PADDING: [char; 12] = [
    '\u{00A0}', '\u{200B}', '\u{200C}', '\u{200D}', '\u{200E}', '\u{200F}', '\u{FEFF}', ' ', '\t', '\n', '\r',
    '\u{000C}',
];

/// Elements that sit on lines of their own.
const BLOCKS: [&str; 19] = [
    "p",
    "div",
    "tr",
    "li",
    "h1",
    "h2",
    "h3",
    "h4",
    "h5",
    "h6",
    "table",
    "ul",
    "ol",
    "blockquote",
    "section",
    "header",
    "footer",
    "hr",
    "pre",
];

/// The text `id` reads as, with blocks wrapped in blank lines and
/// preformatted line breaks as carriage returns.
///
/// PHP: `Mime::render()`.
fn render(document: &Document, id: usize, preformatted: bool) -> String {
    let (name, attributes) = match document.node(id) {
        Node::Text(data) => {
            if preformatted {
                return data.replace('\n', "\r");
            }

            // Preview padding is a run of invisible characters and no-break spaces
            // with nothing else in it. Elsewhere those characters shape the text.
            if data.chars().all(|c| PADDING.contains(&c)) {
                return " ".to_owned();
            }

            // Source whitespace means nothing in HTML; collapse it the way a browser would.
            let data = data.replace(['\t', '\n', '\r', '\u{000C}'], " ");
            let words = data.split(' ').filter(|word| !word.is_empty()).collect::<Vec<_>>().join(" ");
            let mut text = String::new();
            if data.starts_with(' ') {
                text.push(' ');
            }
            text.push_str(&words);
            if data.ends_with(' ') {
                text.push(' ');
            }
            return text;
        }
        Node::Element { name, attributes, .. } => (&*name.local, Some(attributes)),
        Node::Document | Node::Other => ("", None),
    };

    if ["style", "script", "title", "template"].contains(&name) {
        return String::new();
    }

    if name == "br" {
        return "\n".to_owned();
    }

    let preformatted = preformatted || name == "pre" || name == "textarea";

    let mut text = String::new();
    for &child in document.children(id) {
        text.push_str(&render(document, child, preformatted));
    }

    if name == "a" {
        let href = trim(attributes.and_then(|a| html::attribute(a, "href")).unwrap_or(""));
        let label = trim(&text);

        if !href.is_empty()
            && !href.starts_with('#')
            && !href.to_ascii_lowercase().starts_with("javascript:")
            && href != label
        {
            text = if label.is_empty() { href.to_owned() } else { format!("{label}: {href}") };
        }
    }

    if BLOCKS.contains(&name) {
        return format!("\n\n{text}\n\n");
    }

    text
}

/// PHP's `trim()` with its default characters.
fn trim(s: &str) -> &str {
    s.trim_matches([' ', '\t', '\n', '\r', '\0', '\u{000B}'])
}
