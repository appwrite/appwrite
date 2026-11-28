//! The MIME tree.

use std::borrow::Cow;
use std::fs::File;
use std::io::Read;
use std::path::Path;

use crate::attachment::{Attachment, Source};
use crate::mime::{encoding, header};
use crate::{Error, Message};

/// Enough input for a hundred base64 lines per read.
pub(crate) const BLOCK: usize = encoding::CHUNK * 100;

/// One node of the MIME tree: content, an attachment, or a boundary with
/// children under it.
///
/// PHP: `Utopia\SMTP\Mime\Part`.
///
/// ```
/// use utopia_smtp::mime::Part;
///
/// let part = Part::text("text/plain", "héllo".as_bytes());
/// assert_eq!(
///     part.head()?,
///     "Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n",
/// );
/// assert_eq!(part.to_bytes()?, b"h=C3=A9llo");
/// # Ok::<(), utopia_smtp::Error>(())
/// ```
///
/// ```php
/// use Utopia\SMTP\Mime\Part;
///
/// $part = Part::text('text/plain', 'héllo');
/// echo $part->head(), implode('', iterator_to_array($part->body(), false)), "\n"; // ... h=C3=A9llo
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Part {
    headers: Vec<(&'static str, String)>,
    body: Body,
}

#[derive(Debug, Clone, PartialEq, Eq)]
enum Body {
    Text(Vec<u8>),
    Attachment(Attachment),
    Multipart { boundary: String, parts: Vec<Part> },
}

impl Part {
    /// Text of media type `mime_type` in UTF-8, quoted-printable.
    ///
    /// PHP: `Part::text()`.
    pub fn text(mime_type: &str, content: &[u8]) -> Part {
        Part {
            headers: vec![
                ("Content-Type", format!("{mime_type}; charset=utf-8")),
                ("Content-Transfer-Encoding", "quoted-printable".into()),
            ],
            body: Body::Text(encoding::quoted_printable(content)),
        }
    }

    /// An attachment, base64, with its disposition (`attachment`, or
    /// `inline` with a `Content-ID` when it has one) and file name (RFC 2183
    /// quoted, or RFC 2231 encoded once it leaves ASCII).
    ///
    /// PHP: `Part::attachment()`.
    pub fn attachment(attachment: Attachment) -> Part {
        let disposition = if attachment.cid().is_none() { "attachment" } else { "inline" };
        let mut headers = vec![
            ("Content-Type", attachment.mime_type().to_owned()),
            ("Content-Transfer-Encoding", "base64".to_owned()),
            ("Content-Disposition", format!("{disposition}; {}", filename(attachment.name()))),
        ];
        if let Some(cid) = attachment.cid() {
            headers.push(("Content-ID", format!("<{cid}>")));
        }
        Part { headers, body: Body::Attachment(attachment) }
    }

    /// `multipart/<subtype>` around `parts`, with a fresh boundary.
    ///
    /// PHP: `Part::multipart()`.
    pub fn multipart(subtype: &str, parts: Vec<Part>) -> Part {
        let boundary = encoding::boundary();
        Part {
            headers: vec![("Content-Type", format!("multipart/{subtype}; boundary=\"{boundary}\""))],
            body: Body::Multipart { boundary, parts },
        }
    }

    /// This part's own headers, followed by the blank line that ends them.
    ///
    /// PHP: `head()`.
    pub fn head(&self) -> Result<String, Error> {
        let mut out = String::with_capacity(128);
        self.head_into(&mut out)?;
        Ok(out)
    }

    pub(crate) fn head_into(&self, out: &mut String) -> Result<(), Error> {
        for (name, value) in &self.headers {
            header::line_into(name, value, out)?;
        }
        out.push_str("\r\n");
        Ok(())
    }

    /// The body, in the pieces it is written in: boundaries, child heads and
    /// bodies, and the base64 of a file one block at a time.
    ///
    /// PHP: `body()`.
    pub fn body(&self) -> Chunks<'_> {
        let mut segments = Vec::new();
        self.segments(&mut segments);
        Chunks::new(segments)
    }

    /// [`Part::body`] concatenated.
    pub fn to_bytes(&self) -> Result<Vec<u8>, Error> {
        let mut out = Vec::new();
        for chunk in self.body() {
            out.extend_from_slice(&chunk?);
        }
        Ok(out)
    }

    /// The boundary of a multipart part.
    pub fn boundary(&self) -> Option<&str> {
        match &self.body {
            Body::Multipart { boundary, .. } => Some(boundary),
            _ => None,
        }
    }

    pub(crate) fn segments<'a>(&'a self, out: &mut Vec<Segment<'a>>) {
        match &self.body {
            // PHP quirk: a multipart without parts has no delimiters at all,
            // just an empty body.
            Body::Multipart { parts, .. } if parts.is_empty() => out.push(Segment::Static(b"")),
            Body::Multipart { boundary, parts } => {
                for part in parts {
                    out.push(Segment::Open(boundary));
                    out.push(Segment::Head(part));
                    part.segments(out);
                    out.push(Segment::Static(b"\r\n"));
                }
                out.push(Segment::Close(boundary));
            }
            Body::Attachment(attachment) => match attachment.source() {
                Source::Bytes(content) => out.push(Segment::Base64(content)),
                Source::Path(path) => out.push(Segment::File(path)),
            },
            Body::Text(content) => out.push(Segment::Bytes(content)),
        }
    }
}

/// RFC 2183 for the plain case, RFC 2231 once the name leaves ASCII.
fn filename(name: &str) -> String {
    if name.is_ascii() {
        let mut out = String::with_capacity(name.len() + 12);
        out.push_str("filename=\"");
        for c in name.chars() {
            if c == '"' || c == '\\' {
                out.push('\\');
            }
            out.push(c);
        }
        out.push('"');
        return out;
    }
    format!("filename*=UTF-8''{}", php_std::encoding::rawurlencode(name.as_bytes()))
}

/// One step of writing a message out.
#[derive(Debug, Clone, Copy)]
pub(crate) enum Segment<'a> {
    /// The message's headers and the root part's.
    MessageHead(&'a Message),
    /// A part's headers.
    Head(&'a Part),
    /// `--boundary\r\n`.
    Open(&'a str),
    /// `--boundary--\r\n`.
    Close(&'a str),
    Static(&'static [u8]),
    Bytes(&'a [u8]),
    /// Base64 of bytes in memory.
    Base64(&'a [u8]),
    /// Base64 of a file, one chunk per block read.
    File(&'a Path),
}

impl<'a> Segment<'a> {
    /// The chunk of every segment but [`Segment::File`].
    pub(crate) fn render(self) -> Result<Cow<'a, [u8]>, Error> {
        Ok(match self {
            Segment::MessageHead(message) => Cow::Owned(message.head()?.into_bytes()),
            Segment::Head(part) => Cow::Owned(part.head()?.into_bytes()),
            Segment::Open(boundary) => Cow::Owned(format!("--{boundary}\r\n").into_bytes()),
            Segment::Close(boundary) => Cow::Owned(format!("--{boundary}--\r\n").into_bytes()),
            Segment::Static(bytes) => Cow::Borrowed(bytes),
            Segment::Bytes(bytes) => Cow::Borrowed(bytes),
            Segment::Base64(bytes) => Cow::Owned(encoding::base64(bytes)),
            Segment::File(_) => Cow::Borrowed(b""),
        })
    }
}

pub(crate) fn unreadable(path: &Path) -> Error {
    Error::Message(format!("Cannot read attachment: {}", path.display()))
}

pub(crate) fn failed(path: &Path) -> Error {
    Error::Message(format!("Failed reading attachment: {}", path.display()))
}

/// The pieces a message or part is written in, read lazily: a file is opened
/// and read block by block as the pieces are taken.
///
/// PHP: the generators of `Message::toIterable()` and `Part::body()`.
pub struct Chunks<'a> {
    segments: std::vec::IntoIter<Segment<'a>>,
    file: Option<(File, &'a Path)>,
    block: Vec<u8>,
}

impl<'a> Chunks<'a> {
    pub(crate) fn new(segments: Vec<Segment<'a>>) -> Chunks<'a> {
        Chunks { segments: segments.into_iter(), file: None, block: Vec::new() }
    }
}

impl<'a> Iterator for Chunks<'a> {
    type Item = Result<Cow<'a, [u8]>, Error>;

    fn next(&mut self) -> Option<Self::Item> {
        loop {
            if let Some((file, path)) = &mut self.file {
                self.block.resize(BLOCK, 0);
                let mut filled = 0;
                while filled < BLOCK {
                    match file.read(&mut self.block[filled..]) {
                        Ok(0) => break,
                        Ok(n) => filled += n,
                        Err(e) if e.kind() == std::io::ErrorKind::Interrupted => {}
                        Err(_) => {
                            let error = failed(path);
                            self.file = None;
                            return Some(Err(error));
                        }
                    }
                }
                if filled == 0 {
                    self.file = None;
                    continue;
                }
                return Some(Ok(Cow::Owned(encoding::base64(&self.block[..filled]))));
            }
            match self.segments.next()? {
                Segment::File(path) => match File::open(path) {
                    Ok(file) => self.file = Some((file, path)),
                    Err(_) => return Some(Err(unreadable(path))),
                },
                segment => return Some(segment.render()),
            }
        }
    }
}
