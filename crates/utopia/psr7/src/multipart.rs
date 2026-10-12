//! `multipart/form-data`: the parts of a request body and the [`Body`] that
//! serialises them lazily, and the parts read back from a response
//! ([`Received`]).

use std::io::SeekFrom;
use std::path::{Path, PathBuf};

use crate::Error;
use crate::names::header;
use crate::stream::{Detached, Meta, Stream, TempStream};

/// One part of a multipart request: a form field, an in-memory file, or a
/// file on disk read while the body is sent.
///
/// PHP: `Utopia\Psr7\Request\Multipart\Part`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Part {
    name: String,
    content: Vec<u8>,
    path: Option<PathBuf>,
    filename: Option<String>,
    content_type: Option<String>,
    headers: Vec<(String, String)>,
}

impl Part {
    /// A form field.
    ///
    /// PHP: `Part::field()`.
    pub fn field(name: &str, value: &[u8], headers: Vec<(String, String)>) -> Part {
        Part { name: name.to_owned(), content: value.to_vec(), path: None, filename: None, content_type: None, headers }
    }

    /// A file on disk, read lazily while the request is sent; the filename
    /// defaults to the path's last component.
    ///
    /// Fails with [`Error::Runtime`] (`Unable to read multipart file.`) when
    /// `path` is not a file.
    ///
    /// PHP: `Part::file()`.
    pub fn file(
        name: &str,
        path: impl AsRef<Path>,
        filename: Option<&str>,
        content_type: Option<&str>,
        headers: Vec<(String, String)>,
    ) -> Result<Part, Error> {
        let path = path.as_ref();
        if !path.is_file() {
            return Err(Error::runtime("Unable to read multipart file."));
        }
        let filename = match filename {
            Some(f) => f.to_owned(),
            None => {
                let raw = path.to_string_lossy();
                String::from_utf8_lossy(php_std::path::basename(raw.as_bytes(), b"")).into_owned()
            }
        };
        Ok(Part {
            name: name.to_owned(),
            content: Vec::new(),
            path: Some(path.to_path_buf()),
            filename: Some(filename),
            content_type: content_type.map(str::to_owned),
            headers,
        })
    }

    /// An in-memory file.
    ///
    /// PHP: `Part::body()`.
    pub fn body(
        name: &str,
        body: &[u8],
        filename: Option<&str>,
        content_type: Option<&str>,
        headers: Vec<(String, String)>,
    ) -> Part {
        Part {
            name: name.to_owned(),
            content: body.to_vec(),
            path: None,
            filename: filename.map(str::to_owned),
            content_type: content_type.map(str::to_owned),
            headers,
        }
    }

    /// The field name.
    ///
    /// PHP: `name()`.
    pub fn name(&self) -> &str {
        &self.name
    }

    /// The in-memory content; empty for a file part, whose bytes are at [`Part::path`].
    ///
    /// PHP: `content()`.
    pub fn content(&self) -> &[u8] {
        &self.content
    }

    /// The file a file part reads.
    ///
    /// PHP: `path()`.
    pub fn path(&self) -> Option<&Path> {
        self.path.as_deref()
    }

    /// The content's size: the file's for a file part (0 when it is gone).
    ///
    /// PHP: `size()`.
    pub fn size(&self) -> u64 {
        match &self.path {
            None => self.content.len() as u64,
            Some(path) => std::fs::metadata(path).map(|m| m.len()).unwrap_or(0),
        }
    }

    /// The filename sent in `Content-Disposition`.
    ///
    /// PHP: `filename()`.
    pub fn filename(&self) -> Option<&str> {
        self.filename.as_deref()
    }

    /// The part's `Content-Type`.
    ///
    /// PHP: `contentType()`.
    pub fn content_type(&self) -> Option<&str> {
        self.content_type.as_deref()
    }

    /// Extra headers of the part.
    ///
    /// PHP: `headers()`.
    pub fn headers(&self) -> &[(String, String)] {
        &self.headers
    }

    /// The lines before the content: `Content-Disposition`, `Content-Type`,
    /// then the extra headers (one named exactly like an earlier one
    /// replaces it in place).
    fn head(&self) -> Vec<u8> {
        let mut disposition = format!("form-data; name=\"{}\"", escape(&self.name));
        if let Some(filename) = &self.filename {
            disposition.push_str(&format!("; filename=\"{}\"", escape(filename)));
        }
        let mut lines: Vec<(&str, &str)> = vec![(header::CONTENT_DISPOSITION, &disposition)];
        if let Some(content_type) = &self.content_type {
            lines.push((header::CONTENT_TYPE, content_type));
        }
        for (name, value) in &self.headers {
            match lines.iter_mut().find(|(n, _)| *n == name.as_str()) {
                Some(line) => line.1 = value,
                None => lines.push((name, value)),
            }
        }
        let mut out = Vec::new();
        for (i, (name, value)) in lines.iter().enumerate() {
            if i > 0 {
                out.extend_from_slice(b"\r\n");
            }
            out.extend_from_slice(name.as_bytes());
            out.extend_from_slice(b": ");
            out.extend_from_slice(value.as_bytes());
        }
        out.extend_from_slice(b"\r\n");
        out
    }
}

/// `addcslashes($value, '\\"')`.
fn escape(value: &str) -> String {
    String::from_utf8_lossy(&php_std::string::addcslashes(value.as_bytes(), b"\\\"")).into_owned()
}

/// A read-only stream serialising a `multipart/form-data` body lazily:
/// in-memory parts are buffered, file parts are read from disk in chunks
/// as the body is consumed. It keeps its parts and boundary so a transport
/// can stream them through its own upload API instead.
///
/// It can only be rewound (`seek(SeekFrom::Start(0))`), and is not writable.
///
/// ```
/// use utopia_psr7::multipart::{Body, Part};
/// use utopia_psr7::stream::Stream;
///
/// let mut body = Body::new("abc123", vec![Part::field("name", b"Ada", vec![])])?;
/// let expected = b"--abc123\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nAda\r\n--abc123--\r\n";
/// assert_eq!(body.size()?, Some(expected.len() as u64));
/// assert_eq!(body.to_bytes(), expected);
/// # Ok::<(), utopia_psr7::Error>(())
/// ```
///
/// ```php
/// use Utopia\Psr7\Request\Multipart\Body;
/// use Utopia\Psr7\Request\Multipart\Part;
///
/// $body = new Body('abc123', [Part::field('name', 'Ada')]);
/// var_dump($body->getSize()); // int(77)
/// echo json_encode((string) $body), "\n"; // "--abc123\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nAda\r\n--abc123--\r\n"
/// ```
///
/// PHP: `Utopia\Psr7\Request\Multipart\Body`.
#[derive(Debug)]
pub struct Body {
    boundary: String,
    parts: Vec<Part>,
    segments: Vec<TempStream>,
    index: usize,
    position: u64,
    size: Option<u64>,
}

/// Read increment used only when buffering the whole body.
const BUFFER_SIZE: usize = 65_536;

impl Body {
    /// The body of `parts` between `boundary` delimiters.
    ///
    /// Fails with [`Error::Runtime`] (`Unable to open multipart file.`) when
    /// a file part cannot be opened.
    ///
    /// PHP: `new Body($boundary, $parts)`.
    pub fn new(boundary: &str, parts: Vec<Part>) -> Result<Body, Error> {
        let mut segments = Vec::with_capacity(parts.len() * 3 + 1);
        for part in &parts {
            let mut head = format!("--{boundary}\r\n").into_bytes();
            head.extend_from_slice(&part.head());
            head.extend_from_slice(b"\r\n");
            segments.push(TempStream::new(&head));
            segments.push(match &part.path {
                None => TempStream::new(&part.content),
                Some(path) => {
                    let file =
                        std::fs::File::open(path).map_err(|_| Error::runtime("Unable to open multipart file."))?;
                    TempStream::from_file(file, path)
                }
            });
            segments.push(TempStream::new(b"\r\n"));
        }
        segments.push(TempStream::new(format!("--{boundary}--\r\n").as_bytes()));
        let mut size = Some(0u64);
        for segment in &segments {
            size = match (size, segment.size().ok().flatten()) {
                (Some(total), Some(s)) => Some(total + s),
                _ => None,
            };
        }
        Ok(Body { boundary: boundary.to_owned(), parts, segments, index: 0, position: 0, size })
    }

    /// The boundary.
    ///
    /// PHP: `boundary()`.
    pub fn boundary(&self) -> &str {
        &self.boundary
    }

    /// The parts.
    ///
    /// PHP: `parts()`.
    pub fn parts(&self) -> &[Part] {
        &self.parts
    }
}

impl Stream for Body {
    fn read(&mut self, length: usize) -> Result<Vec<u8>, Error> {
        if length < 1 {
            return Err(Error::runtime("Read length must be greater than zero."));
        }
        while self.index < self.segments.len() {
            let segment = &mut self.segments[self.index];
            if !segment.eof() {
                let chunk = segment.read(length)?;
                if !chunk.is_empty() {
                    self.position += chunk.len() as u64;
                    return Ok(chunk);
                }
            }
            self.index += 1;
        }
        Ok(Vec::new())
    }

    fn write(&mut self, _data: &[u8]) -> Result<usize, Error> {
        Err(Error::runtime("A multipart body is not writable."))
    }

    fn seek(&mut self, position: SeekFrom) -> Result<(), Error> {
        if position != SeekFrom::Start(0) {
            return Err(Error::runtime("A multipart body can only be rewound."));
        }
        self.rewind()
    }

    fn rewind(&mut self) -> Result<(), Error> {
        for segment in &mut self.segments {
            segment.rewind()?;
        }
        self.index = 0;
        self.position = 0;
        Ok(())
    }

    fn tell(&self) -> Result<u64, Error> {
        Ok(self.position)
    }

    fn eof(&self) -> bool {
        self.index >= self.segments.len()
    }

    fn size(&self) -> Result<Option<u64>, Error> {
        Ok(self.size)
    }

    fn contents(&mut self) -> Result<Vec<u8>, Error> {
        let mut contents = Vec::new();
        while !self.eof() {
            contents.extend_from_slice(&self.read(BUFFER_SIZE)?);
        }
        Ok(contents)
    }

    fn to_bytes(&mut self) -> Vec<u8> {
        if self.rewind().is_err() {
            return Vec::new();
        }
        self.contents().unwrap_or_default()
    }

    fn close(&mut self) {
        for segment in &mut self.segments {
            segment.close();
        }
    }

    fn detach(&mut self) -> Result<Option<Detached>, Error> {
        self.close();
        Ok(None)
    }

    fn is_readable(&self) -> bool {
        true
    }

    fn is_writable(&self) -> bool {
        false
    }

    fn is_seekable(&self) -> bool {
        true
    }

    fn metadata(&self) -> Result<Vec<(&'static str, Meta)>, Error> {
        Ok(Vec::new())
    }
}

/// A part read from a multipart response.
///
/// Header names and values, and the body, are bytes as received.
///
/// PHP: `Utopia\Psr7\Response\Multipart\Part`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Received {
    headers: Vec<(Vec<u8>, Vec<Vec<u8>>)>,
    body: Vec<u8>,
}

impl Received {
    /// A part of these headers (name and values, in order) and body.
    ///
    /// PHP: `new Part($headers, $body)`.
    pub fn new(headers: Vec<(Vec<u8>, Vec<Vec<u8>>)>, body: Vec<u8>) -> Received {
        Received { headers, body }
    }

    /// Every header.
    ///
    /// PHP: `headers()`.
    pub fn headers(&self) -> &[(Vec<u8>, Vec<Vec<u8>>)] {
        &self.headers
    }

    /// The values of the first header named `name` in any case.
    ///
    /// PHP: `header()`.
    pub fn header(&self, name: &[u8]) -> &[Vec<u8>] {
        self.headers.iter().find(|(n, _)| n.eq_ignore_ascii_case(name)).map_or(&[], |(_, v)| v.as_slice())
    }

    /// The values of a header joined with `", "`.
    ///
    /// PHP: `headerLine()`.
    pub fn header_line(&self, name: &[u8]) -> Vec<u8> {
        self.header(name).join(&b", "[..])
    }

    /// The body.
    ///
    /// PHP: `body()`.
    pub fn body(&self) -> &[u8] {
        &self.body
    }

    /// The `name` parameter of `Content-Disposition`.
    ///
    /// PHP: `name()`.
    pub fn name(&self) -> Option<Vec<u8>> {
        self.disposition(b"name")
    }

    /// The `filename` parameter of `Content-Disposition`.
    ///
    /// PHP: `filename()`.
    pub fn filename(&self) -> Option<Vec<u8>> {
        self.disposition(b"filename")
    }

    /// `Content-Type`, when set and not empty.
    ///
    /// PHP: `contentType()`.
    pub fn content_type(&self) -> Option<Vec<u8>> {
        let line = self.header_line(b"Content-Type");
        if line.is_empty() { None } else { Some(line) }
    }

    /// A `Content-Disposition` parameter: quoted (backslash escapes decoded
    /// like `stripcslashes()`) or a bare token.
    fn disposition(&self, parameter: &[u8]) -> Option<Vec<u8>> {
        let line = self.header_line(b"Content-Disposition");
        if line.is_empty() {
            return None;
        }
        for start in 0..line.len() {
            let after =
                if start == 0 && line[start..].starts_with(parameter) && line.get(parameter.len()) == Some(&b'=') {
                    parameter.len() + 1
                } else if line[start] == b';' {
                    let mut at = start + 1;
                    while at < line.len() && is_space(line[at]) {
                        at += 1;
                    }
                    if !(line[at..].starts_with(parameter) && line.get(at + parameter.len()) == Some(&b'=')) {
                        continue;
                    }
                    at + parameter.len() + 1
                } else {
                    continue;
                };
            let rest = &line[after..];
            if let Some(inner) = rest.strip_prefix(b"\"") {
                let mut i = 0;
                loop {
                    match inner.get(i) {
                        Some(b'"') => return Some(php_std::string::stripcslashes(&inner[..i])),
                        Some(b'\\') if inner.get(i + 1).is_some_and(|&b| b != b'\n') => i += 2,
                        Some(b'\\') | None => break,
                        Some(_) => i += 1,
                    }
                }
            }
            let len = rest.iter().take_while(|&&b| b != b';' && !is_space(b)).count();
            if len > 0 {
                return Some(rest[..len].to_vec());
            }
        }
        None
    }
}

/// PCRE's `\s` without `/u`.
fn is_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')
}
