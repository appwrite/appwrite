use crate::response::Cookie;

/// The response on the wire: the status, headers, cookies and body bytes
/// the response has sent so far.
///
/// PHP: what the server adapter was asked to send (`sendStatus`,
/// `sendHeader`, `sendCookie`, `write`, `end`).
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Wire {
    /// The status sent, if any.
    pub status: Option<u16>,
    /// Header lines by lowercased name, in the order they were first sent.
    pub headers: Vec<(String, Vec<String>)>,
    /// Cookies sent.
    pub cookies: Vec<Cookie>,
    /// Body bytes written.
    pub body: Vec<u8>,
    /// Number of `write()` calls (a chunked response has at least one).
    pub writes: usize,
    /// Whether `end()` was called.
    pub ended: bool,
    /// Whether the response was aborted: the connection closed without the
    /// terminal chunk, so the client sees the body as incomplete.
    pub aborted: bool,
}

impl Wire {
    pub(super) fn header(&mut self, name: &str, values: &[String]) {
        match self.headers.iter_mut().find(|(n, _)| n == name) {
            Some((_, v)) => *v = values.to_vec(),
            None => self.headers.push((name.to_owned(), values.to_vec())),
        }
    }

    pub(super) fn write(&mut self, content: &[u8]) -> bool {
        if self.ended {
            return false;
        }
        self.writes += 1;
        self.body.extend_from_slice(content);
        true
    }

    pub(super) fn end(&mut self, content: Option<&[u8]>) {
        if self.ended {
            return;
        }
        if let Some(c) = content {
            self.body.extend_from_slice(c);
        }
        self.ended = true;
    }
}
