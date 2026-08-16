//! PHP `parse_url` / `urldecode` / `parse_str` compatible helpers.
//!
//! Intentionally does **not** use the `url` crate: PHP accepts custom schemes
//! (`mariadb://`, `s3://`, `sms://`) and authority forms that `url::Url` rejects.

use std::collections::HashMap;

/// Components produced by a PHP-compatible `parse_url`.
#[derive(Debug, Default, Clone, PartialEq, Eq)]
pub(crate) struct UrlParts {
    pub scheme: Option<String>,
    pub user: Option<String>,
    pub pass: Option<String>,
    pub host: Option<String>,
    pub port: Option<u16>,
    pub path: Option<String>,
    pub query: Option<String>,
}

/// PHP `empty()` for optional strings (`null`, `""`, `"0"`).
pub(crate) fn php_empty(value: Option<&str>) -> bool {
    matches!(value, None | Some("" | "0"))
}

/// PHP `urldecode`: `+` → space and `%XX` hex pairs.
pub(crate) fn php_urldecode(input: &str) -> String {
    let bytes = input.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'+' {
            out.push(b' ');
            i += 1;
        } else if bytes[i] == b'%'
            && i + 2 < bytes.len()
            && is_hex(bytes[i + 1])
            && is_hex(bytes[i + 2])
        {
            out.push((hex_val(bytes[i + 1]) << 4) | hex_val(bytes[i + 2]));
            i += 3;
        } else {
            out.push(bytes[i]);
            i += 1;
        }
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// PHP `parse_str` for flat `application/x-www-form-urlencoded` query strings.
///
/// Values are URL-decoded. Dots and spaces in keys become `_` (PHP). Nested
/// `foo[bar]` arrays are stored under the decoded key as a flat string value
/// (DSN only uses scalar params).
pub(crate) fn php_parse_str(query: &str) -> HashMap<String, String> {
    let mut params = HashMap::new();
    for part in query.split('&') {
        if part.is_empty() {
            continue;
        }
        let (raw_key, raw_val) = match part.split_once('=') {
            Some((k, v)) => (k, v),
            None => (part, ""),
        };
        let key = php_urldecode(raw_key).replace(['.', ' '], "_");
        let val = php_urldecode(raw_val);
        params.insert(key, val);
    }
    params
}

/// PHP `parse_url`. Returns `None` when PHP would return `false`.
pub(crate) fn parse_url(input: &str) -> Option<UrlParts> {
    Parser::new(input).parse()
}

struct Parser<'a> {
    bytes: &'a [u8],
    s: usize,
    ue: usize,
    ret: UrlParts,
}

impl<'a> Parser<'a> {
    fn new(input: &'a str) -> Self {
        let bytes = input.as_bytes();
        let ue = bytes.len();
        Self {
            bytes,
            s: 0,
            ue,
            ret: UrlParts::default(),
        }
    }

    fn parse(mut self) -> Option<UrlParts> {
        let colon = self.find(self.s, self.ue, b':');
        if let Some(e) = colon.filter(|&e| e != self.s) {
            if !self.scheme_chars_valid(self.s, e) {
                return self.after_invalid_scheme(e);
            }
            if e + 1 == self.ue {
                self.ret.scheme = Some(self.slice_ctrl(self.s, e));
                return Some(self.ret);
            }
            if self.bytes[e + 1] != b'/' {
                return self.scheme_without_slash(e);
            }
            self.ret.scheme = Some(self.slice_ctrl(self.s, e));
            if e + 2 < self.ue && self.bytes[e + 2] == b'/' {
                self.s = e + 3;
                if self.file_windows_path(e) {
                    return Some(self.parse_just_path());
                }
                self.parse_host()
            } else {
                self.s = e + 1;
                Some(self.parse_just_path())
            }
        } else if let Some(e) = colon {
            self.parse_port_then_host(e)
        } else if self.starts_with_slash_slash(self.s) {
            self.s += 2;
            self.parse_host()
        } else {
            Some(self.parse_just_path())
        }
    }

    fn after_invalid_scheme(mut self, e: usize) -> Option<UrlParts> {
        let before_query = self.find_any(self.s, self.ue, b"/?#");
        if e + 1 < self.ue && e < before_query {
            self.parse_port_then_host(e)
        } else if self.starts_with_slash_slash(self.s) {
            self.s += 2;
            self.parse_host()
        } else {
            Some(self.parse_just_path())
        }
    }

    fn scheme_without_slash(mut self, e: usize) -> Option<UrlParts> {
        let mut p = e + 1;
        while p < self.ue && self.bytes[p].is_ascii_digit() {
            p += 1;
        }
        if (p == self.ue || self.bytes[p] == b'/') && (p - e) < 7 {
            return self.parse_port_then_host(e);
        }
        self.ret.scheme = Some(self.slice_ctrl(self.s, e));
        self.s = e + 1;
        Some(self.parse_just_path())
    }

    fn file_windows_path(&mut self, e: usize) -> bool {
        if !scheme_eq_ci(self.ret.scheme.as_deref(), "file") {
            return false;
        }
        if e + 3 < self.ue && self.bytes[e + 3] == b'/' {
            if e + 5 < self.ue && self.bytes[e + 5] == b':' {
                self.s = e + 4;
            }
            return true;
        }
        false
    }

    fn parse_port_then_host(mut self, e: usize) -> Option<UrlParts> {
        let p = e + 1;
        let mut pp = p;
        while pp < self.ue && pp - p < 6 && self.bytes[pp].is_ascii_digit() {
            pp += 1;
        }

        if pp > p && pp - p < 6 && (pp == self.ue || self.bytes[pp] == b'/') {
            let port = php_strtol_port(&self.bytes[p..pp])?;
            self.ret.port = Some(port);
            if self.starts_with_slash_slash(self.s) {
                self.s += 2;
            }
        } else if p == pp && pp == self.ue {
            return None;
        } else if self.starts_with_slash_slash(self.s) {
            self.s += 2;
        } else {
            return Some(self.parse_just_path());
        }
        self.parse_host()
    }

    fn parse_host(mut self) -> Option<UrlParts> {
        let e = self.find_any(self.s, self.ue, b"/?#");

        if let Some(at) = self.rfind(self.s, e, b'@') {
            if let Some(colon) = self.find(self.s, at, b':') {
                self.ret.user = Some(self.slice_ctrl(self.s, colon));
                self.ret.pass = Some(self.slice_ctrl(colon + 1, at));
            } else {
                self.ret.user = Some(self.slice_ctrl(self.s, at));
            }
            self.s = at + 1;
        }

        let colon = self.host_port_colon(e);
        let host_end = if let Some(mut p) = colon {
            if self.ret.port.is_none() {
                p += 1;
                if e - p > 5 {
                    return None;
                } else if e > p {
                    self.ret.port = Some(php_strtol_port(&self.bytes[p..e])?);
                }
                p - 1
            } else {
                p
            }
        } else {
            e
        };

        if host_end <= self.s {
            return None;
        }

        self.ret.host = Some(self.slice_ctrl(self.s, host_end));

        if e == self.ue {
            return Some(self.ret);
        }
        self.s = e;
        Some(self.parse_just_path())
    }

    fn host_port_colon(&self, e: usize) -> Option<usize> {
        if self.s < self.ue && self.bytes[self.s] == b'[' && e > 0 && self.bytes[e - 1] == b']' {
            None
        } else {
            self.rfind(self.s, e, b':')
        }
    }

    fn parse_just_path(mut self) -> UrlParts {
        let mut e = self.ue;
        if let Some(hash) = self.find(self.s, e, b'#') {
            // Fragment is parsed by PHP but unused by DSN.
            e = hash;
        }
        if let Some(q) = self.find(self.s, e, b'?') {
            self.ret.query = Some(if q + 1 < e {
                self.slice_ctrl(q + 1, e)
            } else {
                String::new()
            });
            e = q;
        }
        if self.s < e || self.s == self.ue {
            self.ret.path = Some(self.slice_ctrl(self.s, e));
        }
        self.ret
    }

    fn starts_with_slash_slash(&self, at: usize) -> bool {
        at + 1 < self.ue && self.bytes[at] == b'/' && self.bytes[at + 1] == b'/'
    }

    fn scheme_chars_valid(&self, start: usize, end: usize) -> bool {
        self.bytes[start..end]
            .iter()
            .all(|&b| b.is_ascii_alphanumeric() || b == b'+' || b == b'.' || b == b'-')
    }

    fn find(&self, start: usize, end: usize, needle: u8) -> Option<usize> {
        self.bytes[start..end]
            .iter()
            .position(|&b| b == needle)
            .map(|i| start + i)
    }

    fn rfind(&self, start: usize, end: usize, needle: u8) -> Option<usize> {
        self.bytes[start..end]
            .iter()
            .rposition(|&b| b == needle)
            .map(|i| start + i)
    }

    fn find_any(&self, start: usize, end: usize, needles: &[u8]) -> usize {
        let mut e = end;
        for &c in needles {
            if let Some(p) = self.find(start, e, c) {
                e = p;
            }
        }
        e
    }

    fn slice_ctrl(&self, start: usize, end: usize) -> String {
        replace_controlchars(&self.bytes[start..end])
    }
}

fn scheme_eq_ci(scheme: Option<&str>, want: &str) -> bool {
    scheme.is_some_and(|s| s.eq_ignore_ascii_case(want))
}

fn replace_controlchars(bytes: &[u8]) -> String {
    let mut out = bytes.to_vec();
    for b in &mut out {
        if *b < 0x20 || *b == 0x7F {
            *b = b'_';
        }
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// C `strtol(..., 10)` then accept `0..=65535` (PHP `parse_url` port).
fn php_strtol_port(s: &[u8]) -> Option<u16> {
    let mut i = 0;
    while i < s.len() && s[i].is_ascii_whitespace() {
        i += 1;
    }
    if i >= s.len() {
        return None;
    }
    let mut sign: i64 = 1;
    if s[i] == b'+' {
        i += 1;
    } else if s[i] == b'-' {
        sign = -1;
        i += 1;
    }
    if i >= s.len() || !s[i].is_ascii_digit() {
        return None;
    }
    let mut n: i64 = 0;
    while i < s.len() && s[i].is_ascii_digit() {
        n = n.saturating_mul(10).saturating_add(i64::from(s[i] - b'0'));
        i += 1;
    }
    n *= sign;
    u16::try_from(n).ok()
}

fn is_hex(b: u8) -> bool {
    b.is_ascii_hexdigit()
}

fn hex_val(b: u8) -> u8 {
    match b {
        b'0'..=b'9' => b - b'0',
        b'a'..=b'f' => b - b'a' + 10,
        b'A'..=b'F' => b - b'A' + 10,
        _ => 0,
    }
}
