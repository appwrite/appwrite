//! `parse_url` and the PHP 8.5 URI extension (`Uri\Rfc3986\Uri`).
//!
//! | PHP | Rust |
//! |---|---|
//! | `parse_url($url)` | [`parse_url`] → [`Url`] |
//! | `parse_url($url, PHP_URL_*)` | [`parse_url`] + [`Url::component`] ([`Component`]) |
//! | `Uri\Rfc3986\Uri::parse($uri)` | [`rfc3986::Uri::parse`] |
//!
//! `Uri\WhatWg\Url` is not reimplemented: no Utopia library uses it.
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `url.*`).

use std::borrow::Cow;
use std::ops::Range;

pub mod rfc3986;

/// A URL split by PHP's `parse_url` (`php_url_parse_ex2` in
/// `ext/standard/url.c`).
///
/// Components borrow from the input. PHP replaces ASCII control characters
/// (`iscntrl`: `0x00`–`0x1F` and `0x7F`) in every component with `_`; the
/// accessors do the same, allocating only when a component contains one.
/// Components are byte strings because PHP strings are: when the input is
/// valid UTF-8, so is every component (the parser only splits at ASCII
/// bytes).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Url<'a> {
    input: &'a [u8],
    scheme: Option<Range<usize>>,
    host: Option<Range<usize>>,
    port: Option<u16>,
    user: Option<Range<usize>>,
    pass: Option<Range<usize>>,
    path: Option<Range<usize>>,
    query: Option<Range<usize>>,
    fragment: Option<Range<usize>>,
}

/// A `PHP_URL_*` selector for `parse_url($url, $component)`.
///
/// PHP throws a `ValueError` for any other integer; the enum makes that
/// unrepresentable (a recorded deviation).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Component {
    /// `PHP_URL_SCHEME` (0)
    Scheme,
    /// `PHP_URL_HOST` (1)
    Host,
    /// `PHP_URL_PORT` (2)
    Port,
    /// `PHP_URL_USER` (3)
    User,
    /// `PHP_URL_PASS` (4)
    Pass,
    /// `PHP_URL_PATH` (5)
    Path,
    /// `PHP_URL_QUERY` (6)
    Query,
    /// `PHP_URL_FRAGMENT` (7)
    Fragment,
}

impl Component {
    /// The component for a `PHP_URL_*` constant value.
    pub fn from_php(value: i64) -> Option<Self> {
        Some(match value {
            0 => Component::Scheme,
            1 => Component::Host,
            2 => Component::Port,
            3 => Component::User,
            4 => Component::Pass,
            5 => Component::Path,
            6 => Component::Query,
            7 => Component::Fragment,
            _ => return None,
        })
    }
}

/// The value `parse_url($url, $component)` returns for a component that is
/// present: a string, or an integer for the port.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum ComponentValue<'a> {
    Str(Cow<'a, [u8]>),
    Port(u16),
}

/// PHP `parse_url($url)`: `None` where PHP returns `false` (a malformed URL,
/// such as an invalid port or an empty host after `//`).
pub fn parse_url(url: &[u8]) -> Option<Url<'_>> {
    Parser { s: url, ret: Url::empty(url) }.run()
}

impl<'a> Url<'a> {
    fn empty(input: &'a [u8]) -> Self {
        Url {
            input,
            scheme: None,
            host: None,
            port: None,
            user: None,
            pass: None,
            path: None,
            query: None,
            fragment: None,
        }
    }

    fn part(&self, r: &Option<Range<usize>>) -> Option<Cow<'a, [u8]>> {
        r.clone().map(|r| replace_controls(&self.input[r]))
    }

    /// `scheme`
    pub fn scheme(&self) -> Option<Cow<'a, [u8]>> {
        self.part(&self.scheme)
    }

    /// `host`
    pub fn host(&self) -> Option<Cow<'a, [u8]>> {
        self.part(&self.host)
    }

    /// `port`. PHP reports a port only when one was written (`:0` included).
    pub fn port(&self) -> Option<u16> {
        self.port
    }

    /// `user`
    pub fn user(&self) -> Option<Cow<'a, [u8]>> {
        self.part(&self.user)
    }

    /// `pass`
    pub fn pass(&self) -> Option<Cow<'a, [u8]>> {
        self.part(&self.pass)
    }

    /// `path`
    pub fn path(&self) -> Option<Cow<'a, [u8]>> {
        self.part(&self.path)
    }

    /// `query` (without `?`)
    pub fn query(&self) -> Option<Cow<'a, [u8]>> {
        self.part(&self.query)
    }

    /// `fragment` (without `#`)
    pub fn fragment(&self) -> Option<Cow<'a, [u8]>> {
        self.part(&self.fragment)
    }

    /// `parse_url($url, $component)` for a URL that parsed: `None` is PHP's
    /// `null` (component absent).
    pub fn component(&self, component: Component) -> Option<ComponentValue<'a>> {
        let s = |v: Option<Cow<'a, [u8]>>| v.map(ComponentValue::Str);
        match component {
            Component::Scheme => s(self.scheme()),
            Component::Host => s(self.host()),
            Component::Port => self.port.map(ComponentValue::Port),
            Component::User => s(self.user()),
            Component::Pass => s(self.pass()),
            Component::Path => s(self.path()),
            Component::Query => s(self.query()),
            Component::Fragment => s(self.fragment()),
        }
    }
}

/// `php_replace_controlchars`: `iscntrl()` bytes become `_`.
fn replace_controls(bytes: &[u8]) -> Cow<'_, [u8]> {
    if !bytes.iter().any(|&b| is_cntrl(b)) {
        return Cow::Borrowed(bytes);
    }
    Cow::Owned(bytes.iter().map(|&b| if is_cntrl(b) { b'_' } else { b }).collect())
}

fn is_cntrl(b: u8) -> bool {
    b < 0x20 || b == 0x7f
}

/// `isspace()` in the C locale.
fn is_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0b | 0x0c | b'\r')
}

/// `strtol(buf, &end, 10)` as used for ports: `None` when no digits were
/// read (`end == buf`). `buf` is at most 5 bytes, so it cannot overflow.
fn strtol(buf: &[u8]) -> Option<i64> {
    let mut i = 0;
    while i < buf.len() && is_space(buf[i]) {
        i += 1;
    }
    let mut negative = false;
    if i < buf.len() && (buf[i] == b'+' || buf[i] == b'-') {
        negative = buf[i] == b'-';
        i += 1;
    }
    let start = i;
    let mut v: i64 = 0;
    while i < buf.len() && buf[i].is_ascii_digit() {
        v = v * 10 + i64::from(buf[i] - b'0');
        i += 1;
    }
    if i == start {
        return None;
    }
    Some(if negative { -v } else { v })
}

/// Where `php_url_parse_ex2` jumps to.
enum Step {
    ParsePort(usize),
    ParseHost,
    JustPath,
}

struct Parser<'a> {
    s: &'a [u8],
    ret: Url<'a>,
}

impl<'a> Parser<'a> {
    /// `binary_strcspn(s, ue, chars)`: the first position in `from..` holding
    /// any of `chars`, or the end.
    fn cspn(&self, from: usize, chars: &[u8]) -> usize {
        self.s[from..].iter().position(|b| chars.contains(b)).map_or(self.s.len(), |p| from + p)
    }

    fn run(mut self) -> Option<Url<'a>> {
        let ue = self.s.len();
        let mut s = 0;
        let colon = self.s.iter().position(|&b| b == b':');

        let step = match colon {
            Some(e) if e != s => {
                let invalid = self.s[s..e]
                    .iter()
                    .any(|&b| !(b.is_ascii_alphabetic() || b.is_ascii_digit() || b == b'+' || b == b'.' || b == b'-'));
                if invalid {
                    if e + 1 < ue && e < self.cspn(s, b"?#") {
                        Step::ParsePort(e)
                    } else if s + 1 < ue && self.s[s] == b'/' && self.s[s + 1] == b'/' {
                        s += 2;
                        Step::ParseHost
                    } else {
                        Step::JustPath
                    }
                } else if e + 1 == ue {
                    // Only a scheme.
                    self.ret.scheme = Some(s..e);
                    return Some(self.ret);
                } else if self.s[e + 1] != b'/' {
                    // `mailto:` style, or `host:port`.
                    let mut p = e + 1;
                    while p < ue && self.s[p].is_ascii_digit() {
                        p += 1;
                    }
                    if (p == ue || self.s[p] == b'/') && (p - e) < 7 {
                        Step::ParsePort(e)
                    } else {
                        self.ret.scheme = Some(s..e);
                        s = e + 1;
                        Step::JustPath
                    }
                } else {
                    self.ret.scheme = Some(s..e);
                    if e + 2 < ue && self.s[e + 2] == b'/' {
                        s = e + 3;
                        if self.s[..e].eq_ignore_ascii_case(b"file") && e + 3 < ue && self.s[e + 3] == b'/' {
                            // Windows drive letters: file:///c:/dir
                            if e + 5 < ue && self.s[e + 5] == b':' {
                                s = e + 4;
                            }
                            Step::JustPath
                        } else {
                            Step::ParseHost
                        }
                    } else {
                        s = e + 1;
                        Step::JustPath
                    }
                }
            }
            Some(e) => Step::ParsePort(e),
            None if s + 1 < ue && self.s[s] == b'/' && self.s[s + 1] == b'/' => {
                s += 2;
                Step::ParseHost
            }
            None => Step::JustPath,
        };

        let step = match step {
            Step::ParsePort(e) => {
                let p = e + 1;
                let mut pp = p;
                while pp < ue && pp - p < 6 && self.s[pp].is_ascii_digit() {
                    pp += 1;
                }
                if pp - p > 0 && pp - p < 6 && (pp == ue || self.s[pp] == b'/') {
                    match strtol(&self.s[p..pp]) {
                        Some(port @ 0..=65535) => {
                            self.ret.port = Some(port as u16);
                            if s + 1 < ue && self.s[s] == b'/' && self.s[s + 1] == b'/' {
                                s += 2;
                            }
                            Step::ParseHost
                        }
                        _ => return None,
                    }
                } else if p == pp && pp == ue {
                    return None;
                } else if s + 1 < ue && self.s[s] == b'/' && self.s[s + 1] == b'/' {
                    s += 2;
                    Step::ParseHost
                } else {
                    Step::JustPath
                }
            }
            other => other,
        };

        if let Step::ParseHost = step {
            let e = self.cspn(s, b"/?#");
            // User info.
            if let Some(at) = self.s[s..e].iter().rposition(|&b| b == b'@').map(|p| s + p) {
                match self.s[s..at].iter().position(|&b| b == b':').map(|p| s + p) {
                    Some(pp) => {
                        self.ret.user = Some(s..pp);
                        self.ret.pass = Some(pp + 1..at);
                    }
                    None => self.ret.user = Some(s..at),
                }
                s = at + 1;
            }
            // Port, unless the host is an IPv6 literal.
            let colon = if s < ue && self.s[s] == b'[' && self.s[e - 1] == b']' {
                None
            } else {
                self.s[s..e].iter().rposition(|&b| b == b':').map(|p| s + p)
            };
            let host_end = match colon {
                Some(p) => {
                    // PHP tests the port value, not whether one was seen: an
                    // earlier `:0` is parsed again here.
                    if self.ret.port.is_none_or(|port| port == 0) {
                        let digits = p + 1;
                        if e - digits > 5 {
                            return None;
                        } else if e > digits {
                            match strtol(&self.s[digits..e]) {
                                Some(port @ 0..=65535) => self.ret.port = Some(port as u16),
                                _ => return None,
                            }
                        }
                    }
                    p
                }
                None => e,
            };
            if host_end <= s {
                return None;
            }
            self.ret.host = Some(s..host_end);
            if e == ue {
                return Some(self.ret);
            }
            s = e;
        }

        // just_path
        let mut e = ue;
        if let Some(hash) = self.s[s..e].iter().position(|&b| b == b'#').map(|p| s + p) {
            self.ret.fragment = Some(hash + 1..e);
            e = hash;
        }
        if let Some(q) = self.s[s..e].iter().position(|&b| b == b'?').map(|p| s + p) {
            self.ret.query = Some(q + 1..e);
            e = q;
        }
        if s < e || s == ue {
            self.ret.path = Some(s..e);
        }
        Some(self.ret)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn parts(url: &str) -> Option<Vec<(&'static str, String)>> {
        let u = parse_url(url.as_bytes())?;
        let s = |v: Option<Cow<'_, [u8]>>| v.map(|v| String::from_utf8(v.into_owned()).unwrap());
        let mut out = Vec::new();
        for (k, v) in [
            ("scheme", s(u.scheme())),
            ("host", s(u.host())),
            ("port", u.port().map(|p| p.to_string())),
            ("user", s(u.user())),
            ("pass", s(u.pass())),
            ("path", s(u.path())),
            ("query", s(u.query())),
            ("fragment", s(u.fragment())),
        ] {
            if let Some(v) = v {
                out.push((k, v));
            }
        }
        Some(out)
    }

    #[test]
    fn full_url() {
        assert_eq!(
            parts("https://u:p@example.com:8080/a/b?x=1#frag").unwrap(),
            vec![
                ("scheme", "https".into()),
                ("host", "example.com".into()),
                ("port", "8080".into()),
                ("user", "u".into()),
                ("pass", "p".into()),
                ("path", "/a/b".into()),
                ("query", "x=1".into()),
                ("fragment", "frag".into()),
            ]
        );
    }

    #[test]
    fn quirks() {
        assert_eq!(parts("").unwrap(), vec![("path", "".into())]);
        assert_eq!(parts("a.com:80").unwrap(), vec![("host", "a.com".into()), ("port", "80".into())]);
        assert_eq!(parts("mailto:a@b.c").unwrap(), vec![("scheme", "mailto".into()), ("path", "a@b.c".into())]);
        assert_eq!(parts("//example.com/x").unwrap(), vec![("host", "example.com".into()), ("path", "/x".into())]);
        assert!(parts("http://").is_none());
        assert!(parts("http://a:65536").is_none());
        assert!(parts(":").is_none());
        assert_eq!(parts("x?").unwrap(), vec![("path", "x".into()), ("query", "".into())]);
        assert_eq!(parts("http://h/\x01").unwrap()[2], ("path", "/_".into()));
    }
}
