//! What a server advertised in its EHLO reply.

use indexmap::IndexMap;
use php_std::string::{TRIM_CHARACTERS, trim};

use crate::Reply;

/// What the server advertised in its EHLO reply: each keyword, upper-cased,
/// with its parameters.
///
/// Discarded and rebuilt after STARTTLS: RFC 3207 section 4.2 requires that
/// anything learned before the handshake is forgotten.
///
/// PHP: `Utopia\SMTP\Capabilities`.
///
/// ```
/// use utopia_smtp::{Capabilities, Reply};
///
/// let reply = Reply::new(250, ["mail.example.test", "SIZE 35882577", "AUTH=PLAIN LOGIN"])?;
/// let capabilities = Capabilities::from_reply(&reply);
///
/// assert_eq!(capabilities.max_size(), Some(35_882_577));
/// assert_eq!(capabilities.mechanisms(), ["PLAIN", "LOGIN"]);
/// assert!(!capabilities.has("STARTTLS"));
/// # Ok::<(), utopia_smtp::Error>(())
/// ```
///
/// ```php
/// use Utopia\SMTP\Capabilities;
/// use Utopia\SMTP\Reply;
///
/// $capabilities = Capabilities::fromReply(new Reply(250, ['mail.example.test', 'SIZE 35882577', 'AUTH=PLAIN LOGIN']));
/// var_dump($capabilities->maxSize()); // int(35882577)
/// echo implode(' ', $capabilities->mechanisms()), "\n"; // PLAIN LOGIN
/// ```
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Capabilities {
    keywords: IndexMap<String, Vec<String>>,
}

impl Capabilities {
    /// Nothing advertised: the state before EHLO, and after HELO.
    ///
    /// PHP: `Capabilities::none()`.
    pub fn none() -> Capabilities {
        Capabilities::default()
    }

    /// Reads an EHLO reply. The first line is the greeting, not a keyword;
    /// both `AUTH PLAIN` and the `AUTH=PLAIN` some servers write are read.
    /// Lines that are not a keyword are skipped.
    ///
    /// PHP: `Capabilities::fromReply()`.
    pub fn from_reply(reply: &Reply) -> Capabilities {
        let mut keywords = IndexMap::new();
        for line in reply.lines().iter().skip(1) {
            let line = trim(line.as_bytes(), TRIM_CHARACTERS);
            let Some((keyword, rest)) = keyword(line) else {
                continue;
            };
            let parameters = trim(rest, TRIM_CHARACTERS);
            let parameters =
                if parameters.is_empty() { Vec::new() } else { split_whitespace(parameters).map(upper).collect() };
            keywords.insert(upper(keyword), parameters);
        }
        Capabilities { keywords }
    }

    /// Whether `keyword` was advertised, in any case.
    ///
    /// PHP: `has()`.
    pub fn has(&self, keyword: &str) -> bool {
        self.keywords.contains_key(&keyword.to_ascii_uppercase())
    }

    /// The parameters of `keyword`, upper-cased; empty when it has none or
    /// was not advertised.
    ///
    /// PHP: `params()`.
    pub fn params(&self, keyword: &str) -> &[String] {
        self.keywords.get(&keyword.to_ascii_uppercase()).map_or(&[], Vec::as_slice)
    }

    /// Every keyword advertised, in the order the server sent them.
    pub fn keywords(&self) -> impl Iterator<Item = (&str, &[String])> {
        self.keywords.iter().map(|(k, v)| (k.as_str(), v.as_slice()))
    }

    /// The SIZE extension of RFC 1870. A declared zero means the server sets
    /// no fixed maximum, which is reported the same way as no declaration.
    ///
    /// PHP: `maxSize()`.
    pub fn max_size(&self) -> Option<u64> {
        let size = self.params("SIZE").first()?;
        if size.is_empty() || !size.bytes().all(|b| b.is_ascii_digit()) {
            return None;
        }
        // PHP's (int) saturates at PHP_INT_MAX.
        let value = size.parse::<u64>().map_or(i64::MAX as u64, |v| v.min(i64::MAX as u64));
        (value != 0).then_some(value)
    }

    /// The AUTH mechanisms offered.
    ///
    /// PHP: `mechanisms()`.
    pub fn mechanisms(&self) -> &[String] {
        self.params("AUTH")
    }
}

/// `^([A-Za-z0-9][A-Za-z0-9-]*)(?:[ =](.*))?$` on a trimmed line: the keyword
/// and the text after its separator (empty when there is none).
fn keyword(line: &[u8]) -> Option<(&[u8], &[u8])> {
    if !line.first()?.is_ascii_alphanumeric() {
        return None;
    }
    let end = line.iter().position(|b| !(b.is_ascii_alphanumeric() || *b == b'-')).unwrap_or(line.len());
    match line.get(end) {
        None => Some((line, &[])),
        // `.` stops at a line feed, and `$` only matches before a final one.
        Some(b' ' | b'=') => {
            let rest = &line[end + 1..];
            let body = rest.strip_suffix(b"\n").unwrap_or(rest);
            (!body.contains(&b'\n')).then_some((&line[..end], rest))
        }
        Some(_) => None,
    }
}

/// `preg_split('/\s+/', ...)`: PCRE's ASCII white space, keeping the empty
/// pieces a leading or trailing run leaves.
fn split_whitespace(s: &[u8]) -> impl Iterator<Item = &[u8]> {
    let space = |b: &u8| matches!(b, b' ' | b'\t' | b'\n' | 0x0b | 0x0c | b'\r');
    let mut rest = Some(s);
    std::iter::from_fn(move || {
        let s = rest?;
        match s.iter().position(space) {
            Some(i) => {
                let skip = s[i..].iter().take_while(|b| space(b)).count();
                rest = Some(&s[i + skip..]);
                Some(&s[..i])
            }
            None => {
                rest = None;
                Some(s)
            }
        }
    })
}

fn upper(s: &[u8]) -> String {
    String::from_utf8_lossy(&s.to_ascii_uppercase()).into_owned()
}
