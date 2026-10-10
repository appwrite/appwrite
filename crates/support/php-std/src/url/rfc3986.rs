//! `Uri\Rfc3986\Uri` from the PHP 8.5 URI extension.
//!
//! PHP parses with uriparser 1.0 (`uriParseSingleUriExMmA`, the RFC 3986
//! `URI-reference` grammar), keeps the parsed URI for the `getRaw*()`
//! getters, and reads the normalized getters (`getScheme()`, `getHost()`,
//! ...) from a copy passed through `uriNormalizeSyntaxExMmA` with every
//! normalization enabled. This module ports the parser state machine
//! (`UriParse.c`), the normalization (`UriNormalize.c`, `UriCommon.c`) and
//! the recomposition used by `toString()`/`toRawString()` (`UriRecompose.c`)
//! byte for byte, including their quirks:
//!
//! - an IPv6 host is recomposed in full, uncompressed form
//!   (`[::1]` becomes `[0000:0000:0000:0000:0000:0000:0000:0001]`);
//! - host lowercasing stops at a `%` in the last three bytes of the host;
//! - a port longer than 20 digits or above `PHP_INT_MAX` makes `parse()`
//!   return `null` (PHP's own check).
//!
//! Base URIs (`parse($uri, $baseUrl)`), `resolve()` and the `with*()`
//! modifiers are not ported: no Utopia library uses them.

use std::borrow::Cow;
use std::ops::Range;

/// A URI parsed by `Uri\Rfc3986\Uri::parse()`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Uri<'a> {
    input: &'a [u8],
    scheme: Option<Range<usize>>,
    user_info: Option<Range<usize>>,
    host: Option<Host>,
    port: Option<Range<usize>>,
    segments: Vec<Range<usize>>,
    absolute_path: bool,
    query: Option<Range<usize>>,
    fragment: Option<Range<usize>>,
}

/// The host of an authority. `text` excludes the brackets of IP literals.
#[derive(Debug, Clone, PartialEq, Eq)]
struct Host {
    text: Range<usize>,
    kind: HostKind,
}

#[derive(Debug, Clone, PartialEq, Eq)]
enum HostKind {
    /// A reg-name (possibly empty) or an IPv4 address: both recompose to
    /// their text (uriparser's IPv4 grammar is strict `dec-octet`).
    Name,
    Ip6([u8; 16]),
    IpFuture,
}

impl<'a> Uri<'a> {
    /// `Uri\Rfc3986\Uri::parse($uri)`: `None` where PHP returns `null`.
    pub fn parse(input: &'a [u8]) -> Option<Self> {
        let mut p = Parser::new(input);
        let end = p.uri_reference(0)?;
        if end != input.len() {
            return None;
        }
        let uri = p.finish();
        // ext/uri: a port that does not fit in a PHP integer is rejected.
        if let Some(port) = &uri.port
            && !port.is_empty()
            && port_value(&input[port.clone()]).is_none()
        {
            return None;
        }
        Some(uri)
    }

    fn slice(&self, r: &Range<usize>) -> &'a [u8] {
        &self.input[r.clone()]
    }

    fn opt(&self, r: &Option<Range<usize>>) -> Option<&'a [u8]> {
        r.as_ref().map(|r| self.slice(r))
    }

    /// `getRawScheme()`
    pub fn raw_scheme(&self) -> Option<Cow<'a, [u8]>> {
        self.opt(&self.scheme).map(Cow::Borrowed)
    }

    /// `getScheme()`: lowercased.
    pub fn scheme(&self) -> Option<Cow<'a, [u8]>> {
        self.opt(&self.scheme).map(lowercase)
    }

    /// `getRawUserInfo()`
    pub fn raw_user_info(&self) -> Option<Cow<'a, [u8]>> {
        self.opt(&self.user_info).map(Cow::Borrowed)
    }

    /// `getUserInfo()`: percent-encoding normalized.
    pub fn user_info(&self) -> Option<Cow<'a, [u8]>> {
        self.opt(&self.user_info).map(fix_percent_encoding)
    }

    /// `getRawUsername()`
    pub fn raw_username(&self) -> Option<Cow<'a, [u8]>> {
        self.raw_user_info().map(username)
    }

    /// `getUsername()`
    pub fn username(&self) -> Option<Cow<'a, [u8]>> {
        self.user_info().map(username)
    }

    /// `getRawPassword()`
    pub fn raw_password(&self) -> Option<Cow<'a, [u8]>> {
        self.raw_user_info().map(password)
    }

    /// `getPassword()`
    pub fn password(&self) -> Option<Cow<'a, [u8]>> {
        self.user_info().map(password)
    }

    /// `getRawHost()`: IP literals keep their brackets.
    pub fn raw_host(&self) -> Option<Cow<'a, [u8]>> {
        let host = self.host.as_ref()?;
        let text = self.slice(&host.text);
        Some(match host.kind {
            HostKind::Name => Cow::Borrowed(text),
            _ => Cow::Owned(bracketed(text)),
        })
    }

    /// `getHost()`: lowercased and percent-encoding normalized.
    pub fn host(&self) -> Option<Cow<'a, [u8]>> {
        let host = self.host.as_ref()?;
        let text = self.normalized_host_text(host);
        Some(match host.kind {
            HostKind::Name => text,
            _ => Cow::Owned(bracketed(&text)),
        })
    }

    fn normalized_host_text(&self, host: &Host) -> Cow<'a, [u8]> {
        let text = self.slice(&host.text);
        match host.kind {
            HostKind::IpFuture => lowercase(text),
            _ => {
                let mut fixed = fix_percent_encoding(text);
                if fixed.iter().any(u8::is_ascii_uppercase) {
                    lowercase_except_percent_encoding(fixed.to_mut());
                }
                fixed
            }
        }
    }

    /// `getPort()` (and `getRawPort()`): `None` without a port or with an
    /// empty one (`http://h:`).
    pub fn port(&self) -> Option<i64> {
        self.port.as_ref().filter(|r| !r.is_empty()).and_then(|r| port_value(self.slice(r)))
    }

    /// `getRawPath()`
    pub fn raw_path(&self) -> Cow<'a, [u8]> {
        let segments: Vec<Cow<'a, [u8]>> = self.segments.iter().map(|r| Cow::Borrowed(self.slice(r))).collect();
        Cow::Owned(self.join_path(&segments, self.absolute_path))
    }

    /// `getPath()`: percent-encoding normalized, dot segments removed.
    pub fn path(&self) -> Cow<'a, [u8]> {
        Cow::Owned(self.join_path(&self.normalized_segments(), self.absolute_path))
    }

    /// `getRawQuery()`
    pub fn raw_query(&self) -> Option<Cow<'a, [u8]>> {
        self.opt(&self.query).map(Cow::Borrowed)
    }

    /// `getQuery()`
    pub fn query(&self) -> Option<Cow<'a, [u8]>> {
        self.opt(&self.query).map(fix_percent_encoding)
    }

    /// `getRawFragment()`
    pub fn raw_fragment(&self) -> Option<Cow<'a, [u8]>> {
        self.opt(&self.fragment).map(Cow::Borrowed)
    }

    /// `getFragment()`
    pub fn fragment(&self) -> Option<Cow<'a, [u8]>> {
        self.opt(&self.fragment).map(fix_percent_encoding)
    }

    /// `toRawString()`
    pub fn to_raw_string(&self) -> Vec<u8> {
        let segments: Vec<Cow<'a, [u8]>> = self.segments.iter().map(|r| Cow::Borrowed(self.slice(r))).collect();
        self.recompose(
            self.raw_scheme(),
            self.raw_user_info(),
            self.host.as_ref().map(|h| Cow::Borrowed(self.slice(&h.text))),
            self.port.as_ref().map(|r| Cow::Borrowed(self.slice(r))),
            &segments,
            self.raw_query(),
            self.raw_fragment(),
        )
    }

    /// `toString()`: the normalized URI.
    pub fn to_string_normalized(&self) -> Vec<u8> {
        self.recompose(
            self.scheme(),
            self.user_info(),
            self.host.as_ref().map(|h| self.normalized_host_text(h)),
            self.port.as_ref().map(|r| Cow::Borrowed(drop_leading_zeros(self.slice(r)))),
            &self.normalized_segments(),
            self.query(),
            self.fragment(),
        )
    }

    fn has_host(&self) -> bool {
        self.host.is_some()
    }

    /// `php_uri_parser_rfc3986_path_read`.
    fn join_path(&self, segments: &[Cow<'a, [u8]>], absolute: bool) -> Vec<u8> {
        let mut out = Vec::new();
        if !segments.is_empty() {
            if absolute || self.has_host() {
                out.push(b'/');
            }
            join_segments(&mut out, segments);
        } else if absolute {
            out.push(b'/');
        }
        out
    }

    /// The path segments after `URI_NORMALIZE_PATH`.
    fn normalized_segments(&self) -> Vec<Cow<'a, [u8]>> {
        let mut segments: Vec<Cow<'a, [u8]>> =
            self.segments.iter().map(|r| fix_percent_encoding(self.slice(r))).collect();
        let relative = self.scheme.is_none() && !self.absolute_path;
        remove_dot_segments(&mut segments, relative, self.has_host(), self.absolute_path);
        // FixEmptyTrailSegment
        if !self.absolute_path && !self.has_host() && segments.len() == 1 && segments[0].is_empty() {
            segments.clear();
        }
        segments
    }

    /// `uriToStringA` (`UriRecompose.c`).
    #[allow(clippy::too_many_arguments)]
    fn recompose(
        &self,
        scheme: Option<Cow<'_, [u8]>>,
        user_info: Option<Cow<'_, [u8]>>,
        host_text: Option<Cow<'_, [u8]>>,
        port: Option<Cow<'_, [u8]>>,
        segments: &[Cow<'_, [u8]>],
        query: Option<Cow<'_, [u8]>>,
        fragment: Option<Cow<'_, [u8]>>,
    ) -> Vec<u8> {
        let mut out = Vec::with_capacity(self.input.len() + 16);
        if let Some(scheme) = scheme {
            out.extend_from_slice(&scheme);
            out.push(b':');
        }
        if let (Some(host), Some(text)) = (&self.host, host_text) {
            out.extend_from_slice(b"//");
            if let Some(user_info) = user_info {
                out.extend_from_slice(&user_info);
                out.push(b'@');
            }
            match &host.kind {
                HostKind::Name => out.extend_from_slice(&text),
                HostKind::Ip6(bytes) => {
                    out.push(b'[');
                    for (i, b) in bytes.iter().enumerate() {
                        out.push(HEX_LOWER[usize::from(b >> 4)]);
                        out.push(HEX_LOWER[usize::from(b & 15)]);
                        if i & 1 == 1 && i < 15 {
                            out.push(b':');
                        }
                    }
                    out.push(b']');
                }
                HostKind::IpFuture => {
                    out.push(b'[');
                    out.extend_from_slice(&text);
                    out.push(b']');
                }
            }
            if let Some(port) = port {
                out.push(b':');
                out.extend_from_slice(&port);
            }
        }
        if self.absolute_path || (!segments.is_empty() && self.has_host()) {
            out.push(b'/');
        }
        join_segments(&mut out, segments);
        if let Some(query) = query {
            out.push(b'?');
            out.extend_from_slice(&query);
        }
        if let Some(fragment) = fragment {
            out.push(b'#');
            out.extend_from_slice(&fragment);
        }
        out
    }
}

const HEX_LOWER: &[u8; 16] = b"0123456789abcdef";
const HEX_UPPER: &[u8; 16] = b"0123456789ABCDEF";

fn join_segments(out: &mut Vec<u8>, segments: &[Cow<'_, [u8]>]) {
    for (i, s) in segments.iter().enumerate() {
        if i > 0 {
            out.push(b'/');
        }
        out.extend_from_slice(s);
    }
}

fn bracketed(text: &[u8]) -> Vec<u8> {
    let mut v = Vec::with_capacity(text.len() + 2);
    v.push(b'[');
    v.extend_from_slice(text);
    v.push(b']');
    v
}

/// `php_uri_parser_rfc3986_username_read`.
fn username(user_info: Cow<'_, [u8]>) -> Cow<'_, [u8]> {
    match user_info.iter().position(|&b| b == b':') {
        None => user_info,
        Some(c) => match user_info {
            Cow::Borrowed(b) => Cow::Borrowed(&b[..c]),
            Cow::Owned(mut v) => {
                v.truncate(c);
                Cow::Owned(v)
            }
        },
    }
}

/// `php_uri_parser_rfc3986_password_read`: empty when there is no `:`.
fn password(user_info: Cow<'_, [u8]>) -> Cow<'_, [u8]> {
    match user_info.iter().position(|&b| b == b':') {
        None => Cow::Borrowed(&[]),
        Some(c) => match user_info {
            Cow::Borrowed(b) => Cow::Borrowed(&b[c + 1..]),
            Cow::Owned(v) => Cow::Owned(v[c + 1..].to_vec()),
        },
    }
}

/// `port_str_to_zend_long_checked`: `strtoul` of the digits, rejected above
/// 20 digits or `PHP_INT_MAX`.
fn port_value(digits: &[u8]) -> Option<i64> {
    if digits.len() > 20 {
        return None;
    }
    let mut v: u64 = 0;
    for &b in digits {
        v = v.saturating_mul(10).saturating_add(u64::from(b - b'0'));
    }
    i64::try_from(v).ok()
}

/// `DropLeadingZerosInplace`: `"0080"` becomes `"80"`, `"000"` becomes `"0"`.
fn drop_leading_zeros(port: &[u8]) -> &[u8] {
    if port.is_empty() {
        return port;
    }
    let zeros = port.iter().take_while(|&&b| b == b'0').count();
    if zeros == port.len() { &port[port.len() - 1..] } else { &port[zeros..] }
}

fn lowercase(s: &[u8]) -> Cow<'_, [u8]> {
    if s.iter().any(u8::is_ascii_uppercase) { Cow::Owned(s.to_ascii_lowercase()) } else { Cow::Borrowed(s) }
}

/// `LowercaseInplaceExceptPercentEncoding`, including its early return when a
/// `%` sits in the last three bytes.
fn lowercase_except_percent_encoding(s: &mut [u8]) {
    let mut i = 0;
    while i < s.len() {
        if s[i].is_ascii_uppercase() {
            s[i] = s[i].to_ascii_lowercase();
        } else if s[i] == b'%' {
            if i + 3 >= s.len() {
                return;
            }
            i += 2;
        }
        i += 1;
    }
}

fn is_unreserved(b: u8) -> bool {
    b.is_ascii_alphanumeric() || matches!(b, b'-' | b'.' | b'_' | b'~')
}

fn is_sub_delim(b: u8) -> bool {
    matches!(b, b'!' | b'$' | b'&' | b'\'' | b'(' | b')' | b'*' | b'+' | b',' | b';' | b'=')
}

/// `URI_SET_PCHAR`: unreserved, sub-delims, `:`, `@` and `%`.
fn is_pchar(b: u8) -> bool {
    is_unreserved(b) || is_sub_delim(b) || matches!(b, b':' | b'@' | b'%')
}

fn hex_value(b: u8) -> u8 {
    match b {
        b'0'..=b'9' => b - b'0',
        b'a'..=b'f' => b - b'a' + 10,
        b'A'..=b'F' => b - b'A' + 10,
        _ => 0,
    }
}

/// `FixPercentEncodingEngine`: decodes `%XX` of unreserved characters and
/// uppercases the hex digits of the others.
fn fix_percent_encoding(s: &[u8]) -> Cow<'_, [u8]> {
    let needs = s.windows(3).any(|w| w[0] == b'%');
    if !needs {
        return Cow::Borrowed(s);
    }
    let mut out = Vec::with_capacity(s.len());
    let mut i = 0;
    while i + 2 < s.len() {
        if s[i] != b'%' {
            out.push(s[i]);
        } else {
            let (left, right) = (hex_value(s[i + 1]), hex_value(s[i + 2]));
            let code = 16 * left + right;
            if is_unreserved(code) {
                out.push(code);
            } else {
                out.extend_from_slice(&[b'%', HEX_UPPER[usize::from(left)], HEX_UPPER[usize::from(right)]]);
            }
            i += 2;
        }
        i += 1;
    }
    out.extend_from_slice(&s[i..]);
    Cow::Owned(out)
}

/// `RemoveDotSegmentsEx` over a segment list.
fn remove_dot_segments(segments: &mut Vec<Cow<'_, [u8]>>, relative: bool, has_host: bool, absolute: bool) {
    let mut i = 0;
    while i < segments.len() {
        let has_next = i + 1 < segments.len();
        let prev = i.checked_sub(1);
        let seg: &[u8] = &segments[i];
        let mut removed = false;
        if seg == b"." {
            let mut remove = true;
            // A leading "." stays before an empty segment (no host) or, in a
            // relative reference, before a segment with a colon.
            if i == 0
                && has_next
                && ((segments[1].is_empty() && !has_host) || (relative && segments[1].contains(&b':')))
            {
                remove = false;
            }
            if remove {
                removed = true;
                if has_next {
                    segments.remove(i);
                } else {
                    if prev.is_none() && !has_host {
                        segments.remove(i);
                    } else {
                        segments[i] = Cow::Borrowed(&[]);
                    }
                    i = segments.len();
                }
            }
        } else if seg == b".." {
            let mut remove = true;
            if relative {
                match prev {
                    None => remove = false,
                    Some(p) if &*segments[p] == b".." => remove = false,
                    _ => {}
                }
            }
            if remove {
                removed = true;
                match prev {
                    Some(p) => {
                        if p > 0 {
                            // prevPrev exists: drop prev and walker.
                            segments.drain(p..=i);
                            if has_next {
                                i = p;
                            } else {
                                segments.push(Cow::Borrowed(&[]));
                                i = segments.len();
                            }
                        } else if has_next {
                            segments.drain(0..=1);
                            i = 0;
                        } else {
                            segments.clear();
                            segments.push(Cow::Borrowed(&[]));
                            i = segments.len();
                        }
                    }
                    None => {
                        if has_next {
                            segments.remove(0);
                            i = 0;
                        } else {
                            if absolute {
                                segments.clear();
                            } else {
                                segments[0] = Cow::Borrowed(&[]);
                            }
                            i = segments.len();
                        }
                    }
                }
            }
        }
        if !removed {
            i += 1;
        }
    }
}

/// The uriparser state machine (`UriParse.c`), positions instead of pointers.
/// Every `fn` returns the position after what it consumed, or `None` for a
/// syntax error.
struct Parser<'a> {
    s: &'a [u8],
    seg_start: usize,
    scheme: Option<Range<usize>>,
    user_info_first: usize,
    user_info: Option<Range<usize>>,
    host_first: usize,
    host_end: Option<usize>,
    host: Option<Host>,
    port_first: Option<usize>,
    port: Option<Range<usize>>,
    ip6: Option<[u8; 16]>,
    ip_future: bool,
    segments: Vec<Range<usize>>,
    absolute_path: bool,
    query: Option<Range<usize>>,
    fragment: Option<Range<usize>>,
}

impl<'a> Parser<'a> {
    fn new(s: &'a [u8]) -> Self {
        Parser {
            s,
            seg_start: 0,
            scheme: None,
            user_info_first: 0,
            user_info: None,
            host_first: 0,
            host_end: None,
            host: None,
            port_first: None,
            port: None,
            ip6: None,
            ip_future: false,
            segments: Vec::new(),
            absolute_path: false,
            query: None,
            fragment: None,
        }
    }

    fn finish(self) -> Uri<'a> {
        Uri {
            input: self.s,
            scheme: self.scheme,
            user_info: self.user_info,
            host: self.host,
            port: self.port,
            segments: self.segments,
            absolute_path: self.absolute_path,
            query: self.query,
            fragment: self.fragment,
        }
    }

    fn end(&self) -> usize {
        self.s.len()
    }

    fn at(&self, i: usize) -> u8 {
        self.s[i]
    }

    fn push(&mut self, r: Range<usize>) {
        self.segments.push(r);
    }

    fn set_name_host(&mut self, r: Range<usize>) {
        let kind = match (self.ip6.take(), std::mem::take(&mut self.ip_future)) {
            (Some(bytes), _) => HostKind::Ip6(bytes),
            (None, true) => HostKind::IpFuture,
            _ => HostKind::Name,
        };
        self.host = Some(Host { text: r, kind });
    }

    /// `[uriReference]`
    fn uri_reference(&mut self, first: usize) -> Option<usize> {
        if first >= self.end() {
            return Some(self.end());
        }
        let c = self.at(first);
        match c {
            _ if c.is_ascii_alphabetic() => {
                self.seg_start = first;
                self.segment_nz_nc_or_scheme2(first + 1)
            }
            _ if c.is_ascii_digit() || is_sub_delim(c) || matches!(c, b'.' | b'_' | b'~' | b'-' | b'@') => {
                self.seg_start = first;
                self.must_be_segment_nz_nc(first + 1)
            }
            b'%' => {
                let after = self.pct_encoded(first)?;
                self.seg_start = first;
                self.must_be_segment_nz_nc(after)
            }
            b'/' => {
                let after = self.part_helper_two(first + 1)?;
                self.uri_tail(after)
            }
            _ => self.uri_tail(first),
        }
    }

    /// `[segmentNzNcOrScheme2]`
    fn segment_nz_nc_or_scheme2(&mut self, mut first: usize) -> Option<usize> {
        loop {
            if first >= self.end() {
                self.push(self.seg_start..first);
                return Some(self.end());
            }
            let c = self.at(first);
            match c {
                b'.' | b'+' | b'-' => first += 1,
                _ if c.is_ascii_alphanumeric() => first += 1,
                b'%' => {
                    let after = self.pct_encoded(first)?;
                    return self.must_be_segment_nz_nc(after);
                }
                b'!' | b'$' | b'&' | b'(' | b')' | b'*' | b',' | b';' | b'@' | b'_' | b'~' | b'=' | b'\'' => {
                    return self.must_be_segment_nz_nc(first + 1);
                }
                b'/' => {
                    let after_segment = self.segment(first + 1)?;
                    self.push(self.seg_start..first);
                    self.push(first + 1..after_segment);
                    let after = self.zero_more_slash_segs(after_segment)?;
                    return self.uri_tail(after);
                }
                b':' => {
                    let after_hier = self.hier_part(first + 1)?;
                    self.scheme = Some(self.seg_start..first);
                    return self.uri_tail(after_hier);
                }
                _ => {
                    self.push(self.seg_start..first);
                    return self.uri_tail(first);
                }
            }
        }
    }

    /// `[mustBeSegmentNzNc]`
    fn must_be_segment_nz_nc(&mut self, mut first: usize) -> Option<usize> {
        loop {
            if first >= self.end() {
                self.push(self.seg_start..first);
                return Some(self.end());
            }
            let c = self.at(first);
            match c {
                b'%' => first = self.pct_encoded(first)?,
                _ if c == b'@' || is_sub_delim(c) || is_unreserved(c) => first += 1,
                b'/' => {
                    self.push(self.seg_start..first);
                    let after_segment = self.segment(first + 1)?;
                    self.push(first + 1..after_segment);
                    let after = self.zero_more_slash_segs(after_segment)?;
                    return self.uri_tail(after);
                }
                _ => {
                    self.push(self.seg_start..first);
                    return self.uri_tail(first);
                }
            }
        }
    }

    /// `[uriTail]`
    fn uri_tail(&mut self, first: usize) -> Option<usize> {
        if first >= self.end() {
            return Some(self.end());
        }
        match self.at(first) {
            b'#' => {
                let after = self.query_frag(first + 1)?;
                self.fragment = Some(first + 1..after);
                Some(after)
            }
            b'?' => {
                let after = self.query_frag(first + 1)?;
                self.query = Some(first + 1..after);
                if after < self.end() && self.at(after) == b'#' {
                    let after_fragment = self.query_frag(after + 1)?;
                    self.fragment = Some(after + 1..after_fragment);
                    return Some(after_fragment);
                }
                Some(after)
            }
            _ => Some(first),
        }
    }

    /// `[queryFrag]`
    fn query_frag(&mut self, mut first: usize) -> Option<usize> {
        loop {
            if first >= self.end() {
                return Some(self.end());
            }
            let c = self.at(first);
            if is_pchar(c) {
                first = self.pchar(first)?;
            } else if c == b'/' || c == b'?' {
                first += 1;
            } else {
                return Some(first);
            }
        }
    }

    /// `[hierPart]`
    fn hier_part(&mut self, first: usize) -> Option<usize> {
        if first >= self.end() {
            return Some(self.end());
        }
        let c = self.at(first);
        if is_pchar(c) {
            self.path_rootless(first)
        } else if c == b'/' {
            self.part_helper_two(first + 1)
        } else {
            Some(first)
        }
    }

    /// `[partHelperTwo]`
    fn part_helper_two(&mut self, first: usize) -> Option<usize> {
        if first >= self.end() {
            self.absolute_path = true;
            return Some(self.end());
        }
        if self.at(first) == b'/' {
            let after_authority = self.authority(first + 1)?;
            self.path_abs_empty(after_authority)
        } else {
            self.absolute_path = true;
            self.path_abs_no_lead_slash(first)
        }
    }

    /// `[pathAbsEmpty]`, also `[zeroMoreSlashSegs]`.
    fn path_abs_empty(&mut self, mut first: usize) -> Option<usize> {
        while first < self.end() && self.at(first) == b'/' {
            let after = self.segment(first + 1)?;
            self.push(first + 1..after);
            first = after;
        }
        Some(first)
    }

    fn zero_more_slash_segs(&mut self, first: usize) -> Option<usize> {
        self.path_abs_empty(first)
    }

    /// `[pathAbsNoLeadSlash]`
    fn path_abs_no_lead_slash(&mut self, first: usize) -> Option<usize> {
        if first >= self.end() {
            return Some(self.end());
        }
        if is_pchar(self.at(first)) {
            let after = self.segment_nz(first)?;
            self.push(first..after);
            self.zero_more_slash_segs(after)
        } else {
            Some(first)
        }
    }

    /// `[pathRootless]`
    fn path_rootless(&mut self, first: usize) -> Option<usize> {
        let after = self.segment_nz(first)?;
        self.push(first..after);
        self.zero_more_slash_segs(after)
    }

    /// `[segmentNz]`
    fn segment_nz(&mut self, first: usize) -> Option<usize> {
        let after = self.pchar(first)?;
        self.segment(after)
    }

    /// `[segment]`
    fn segment(&mut self, mut first: usize) -> Option<usize> {
        while first < self.end() && is_pchar(self.at(first)) {
            first = self.pchar(first)?;
        }
        Some(first)
    }

    /// `[pchar]`
    fn pchar(&mut self, first: usize) -> Option<usize> {
        if first >= self.end() {
            return None;
        }
        match self.at(first) {
            b'%' => self.pct_encoded(first),
            c if is_pchar(c) => Some(first + 1),
            _ => None,
        }
    }

    /// `[pctEncoded]`
    fn pct_encoded(&self, first: usize) -> Option<usize> {
        if self.end() - first < 3 {
            return None;
        }
        (self.at(first + 1).is_ascii_hexdigit() && self.at(first + 2).is_ascii_hexdigit()).then_some(first + 3)
    }

    /// `[pctSubUnres]`
    fn pct_sub_unres(&self, first: usize) -> Option<usize> {
        if first >= self.end() {
            return None;
        }
        match self.at(first) {
            b'%' => self.pct_encoded(first),
            c if is_sub_delim(c) || is_unreserved(c) => Some(first + 1),
            _ => None,
        }
    }

    /// `[authority]`
    fn authority(&mut self, first: usize) -> Option<usize> {
        if first >= self.end() {
            self.set_name_host(first..first);
            return Some(self.end());
        }
        let c = self.at(first);
        if c == b'[' {
            let after = self.ip_lit2(first + 1)?;
            self.authority_two(after)
        } else if is_pchar(c) {
            self.user_info_first = first;
            self.own_host_user_info_nz(first)
        } else {
            self.set_name_host(first..first);
            Some(first)
        }
    }

    /// `[authorityTwo]`
    fn authority_two(&mut self, first: usize) -> Option<usize> {
        if first < self.end() && self.at(first) == b':' {
            let mut after = first + 1;
            while after < self.end() && self.at(after).is_ascii_digit() {
                after += 1;
            }
            self.port = Some(first + 1..after);
            return Some(after);
        }
        Some(first)
    }

    /// `[ownHostUserInfoNz]`
    fn own_host_user_info_nz(&mut self, mut first: usize) -> Option<usize> {
        let original = first;
        while first < self.end() {
            let c = self.at(first);
            if c == b'%' || is_sub_delim(c) || is_unreserved(c) {
                first = self.pct_sub_unres(first)?;
            } else {
                break;
            }
        }
        if first < self.end() {
            match self.at(first) {
                b':' => {
                    self.host_end = Some(first);
                    self.port_first = Some(first + 1);
                    return self.own_port_user_info(first + 1);
                }
                b'@' => {
                    self.user_info = Some(self.user_info_first..first);
                    self.host_first = first + 1;
                    return self.own_host(first + 1);
                }
                _ => {}
            }
        }
        if first == original {
            return None;
        }
        self.set_name_host(self.user_info_first..first);
        Some(first)
    }

    fn on_exit_own_port_user_info(&mut self, first: usize) {
        let host_end = self.host_end.unwrap_or(first);
        self.set_name_host(self.user_info_first..host_end);
        let port_first = self.port_first.unwrap_or(first);
        self.port = Some(port_first..first);
    }

    /// `[ownPortUserInfo]`
    fn own_port_user_info(&mut self, mut first: usize) -> Option<usize> {
        loop {
            if first >= self.end() {
                self.on_exit_own_port_user_info(first);
                return Some(self.end());
            }
            let c = self.at(first);
            match c {
                _ if is_sub_delim(c) || matches!(c, b'-' | b'.' | b'_' | b'~' | b':') || c.is_ascii_alphabetic() => {
                    self.host_end = None;
                    self.port_first = None;
                    return self.own_user_info(first + 1);
                }
                _ if c.is_ascii_digit() => first += 1,
                b'%' => {
                    self.port_first = None;
                    let after = self.pct_encoded(first)?;
                    return self.own_user_info(after);
                }
                b'@' => {
                    self.host_end = None;
                    self.port_first = None;
                    self.user_info = Some(self.user_info_first..first);
                    self.host_first = first + 1;
                    return self.own_host(first + 1);
                }
                _ => {
                    self.on_exit_own_port_user_info(first);
                    return Some(first);
                }
            }
        }
    }

    /// `[ownUserInfo]`
    fn own_user_info(&mut self, mut first: usize) -> Option<usize> {
        loop {
            if first >= self.end() {
                return None;
            }
            let c = self.at(first);
            match c {
                b':' => first += 1,
                b'@' => {
                    self.user_info = Some(self.user_info_first..first);
                    self.host_first = first + 1;
                    return self.own_host(first + 1);
                }
                _ if c == b'%' || is_sub_delim(c) || is_unreserved(c) => first = self.pct_sub_unres(first)?,
                _ => return None,
            }
        }
    }

    /// `[ownHost]`
    fn own_host(&mut self, first: usize) -> Option<usize> {
        if first >= self.end() {
            self.set_name_host(self.host_first..self.end());
            return Some(self.end());
        }
        if self.at(first) == b'[' {
            let after = self.ip_lit2(first + 1)?;
            return self.authority_two(after);
        }
        self.own_host2(first)
    }

    /// `[ownHost2]`
    fn own_host2(&mut self, mut first: usize) -> Option<usize> {
        loop {
            if first >= self.end() {
                self.set_name_host(self.host_first..self.end());
                return Some(self.end());
            }
            let c = self.at(first);
            if c == b'%' || is_sub_delim(c) || is_unreserved(c) {
                first = self.pct_sub_unres(first)?;
            } else {
                self.set_name_host(self.host_first..first);
                return self.authority_two(first);
            }
        }
    }

    /// `[ipLit2]`: `first` is after `[`.
    fn ip_lit2(&mut self, first: usize) -> Option<usize> {
        if first >= self.end() {
            return None;
        }
        let c = self.at(first);
        match c {
            b'v' | b'V' => {
                let after = self.ip_future(first)?;
                if after >= self.end() || self.at(after) != b']' {
                    return None;
                }
                self.ip_future = true;
                self.set_name_host(first..after);
                Some(after + 1)
            }
            b':' | b']' => self.ipv6_address2(first),
            _ if c.is_ascii_hexdigit() => self.ipv6_address2(first),
            _ => None,
        }
    }

    /// `[ipFuture]`: `v` 1*HEXDIG `.` 1*(unreserved / sub-delims / `:`).
    fn ip_future(&mut self, first: usize) -> Option<usize> {
        if self.end() - first < 2 || !self.at(first + 1).is_ascii_hexdigit() {
            return None;
        }
        let mut p = first + 2;
        while p < self.end() && self.at(p).is_ascii_hexdigit() {
            p += 1;
        }
        if p >= self.end() || self.at(p) != b'.' {
            return None;
        }
        let start = p + 1;
        let mut q = start;
        while q < self.end() {
            let c = self.at(q);
            if c == b':' || is_sub_delim(c) || is_unreserved(c) {
                q += 1;
            } else {
                break;
            }
        }
        (q != start).then_some(q)
    }

    /// `[IPv6address2]`: everything up to and including `]`.
    fn ipv6_address2(&mut self, mut first: usize) -> Option<usize> {
        let start = first;
        let mut data = [0u8; 16];
        let mut zipper_ever = false;
        let mut quads_done = 0usize;
        let mut digit_count = 0usize;
        let mut digits = [0u8; 4];
        let mut ip4_octets_done = 0usize;
        let mut after_zipper = [0u8; 14];
        let mut after_zipper_count = 0usize;

        let octet_ok = |digits: &[u8; 4], count: usize| -> bool {
            !((count > 1 && digits[0] == 0)
                || (count == 3 && 100 * u32::from(digits[0]) + 10 * u32::from(digits[1]) + u32::from(digits[2]) > 255))
        };
        let octet = |digits: &[u8; 4], count: usize| -> u8 {
            digits[..count].iter().fold(0u32, |acc, &d| acc * 10 + u32::from(d)) as u8
        };
        let write_quad = |digits: &[u8; 4], count: usize, out: &mut [u8]| match count {
            1 => {
                out[0] = 0;
                out[1] = digits[0];
            }
            2 => {
                out[0] = 0;
                out[1] = 16 * digits[0] + digits[1];
            }
            3 => {
                out[0] = digits[0];
                out[1] = 16 * digits[1] + digits[2];
            }
            _ => {
                out[0] = 16 * digits[0] + digits[1];
                out[1] = 16 * digits[2] + digits[3];
            }
        };

        loop {
            if first >= self.end() {
                return None;
            }
            if ip4_octets_done > 0 {
                loop {
                    let c = self.at(first);
                    match c {
                        b'0'..=b'9' => {
                            if digit_count == 4 {
                                return None;
                            }
                            digits[digit_count] = c - b'0';
                            digit_count += 1;
                        }
                        b'.' => {
                            if ip4_octets_done == 4 || digit_count == 0 || digit_count == 4 {
                                return None;
                            }
                            if !octet_ok(&digits, digit_count) {
                                return None;
                            }
                            data[16 - 4 + ip4_octets_done] = octet(&digits, digit_count);
                            digit_count = 0;
                            ip4_octets_done += 1;
                        }
                        b']' => {
                            if ip4_octets_done != 3 || digit_count == 0 || digit_count == 4 {
                                return None;
                            }
                            if !octet_ok(&digits, digit_count) {
                                return None;
                            }
                            let n = 2 * after_zipper_count;
                            data[16 - 4 - n..16 - 4].copy_from_slice(&after_zipper[..n]);
                            data[16 - 4 + 3] = octet(&digits, digit_count);
                            self.ip6 = Some(data);
                            self.set_name_host(start..first);
                            return Some(first + 1);
                        }
                        _ => return None,
                    }
                    first += 1;
                    if first >= self.end() {
                        return None;
                    }
                }
            }
            let mut letter_among = false;
            loop {
                let c = self.at(first);
                match c {
                    b'a'..=b'f' | b'A'..=b'F' | b'0'..=b'9' => {
                        if !c.is_ascii_digit() {
                            letter_among = true;
                        }
                        if digit_count == 4 {
                            return None;
                        }
                        digits[digit_count] = hex_value(c);
                        digit_count += 1;
                    }
                    b':' => {
                        let mut set_zipper = false;
                        if digit_count > 0 {
                            if zipper_ever {
                                write_quad(
                                    &digits,
                                    digit_count,
                                    &mut after_zipper[2 * after_zipper_count..2 * after_zipper_count + 2],
                                );
                                after_zipper_count += 1;
                            } else {
                                write_quad(&digits, digit_count, &mut data[2 * quads_done..2 * quads_done + 2]);
                            }
                            quads_done += 1;
                            digit_count = 0;
                        }
                        letter_among = false;
                        if quads_done >= 8 - usize::from(zipper_ever) {
                            return None;
                        }
                        if self.end() - first < 2 {
                            return None;
                        }
                        if self.at(first + 1) == b':' {
                            let reset = 2 * quads_done;
                            first += 1;
                            if zipper_ever {
                                return None;
                            }
                            data[reset..].fill(0);
                            set_zipper = true;
                            if self.end() - first < 2 || self.at(first + 1) == b':' {
                                return None;
                            }
                        } else if quads_done == 0 || self.at(first + 1) == b']' {
                            return None;
                        }
                        if set_zipper {
                            zipper_ever = true;
                        }
                    }
                    b'.' => {
                        if quads_done + usize::from(zipper_ever) > 6
                            || (!zipper_ever && quads_done < 6)
                            || letter_among
                            || digit_count == 0
                            || digit_count == 4
                        {
                            return None;
                        }
                        if !octet_ok(&digits, digit_count) {
                            return None;
                        }
                        data[16 - 4] = octet(&digits, digit_count);
                        digit_count = 0;
                        ip4_octets_done = 1;
                        first += 1;
                        break;
                    }
                    b']' => {
                        if !(zipper_ever || (quads_done == 7 && digit_count > 0)) {
                            return None;
                        }
                        if digit_count > 0 {
                            if zipper_ever {
                                if quads_done >= 7 {
                                    return None;
                                }
                                write_quad(
                                    &digits,
                                    digit_count,
                                    &mut after_zipper[2 * after_zipper_count..2 * after_zipper_count + 2],
                                );
                                after_zipper_count += 1;
                            } else {
                                write_quad(&digits, digit_count, &mut data[2 * quads_done..2 * quads_done + 2]);
                            }
                        }
                        let n = 2 * after_zipper_count;
                        data[16 - n..].copy_from_slice(&after_zipper[..n]);
                        self.ip6 = Some(data);
                        self.set_name_host(start..first);
                        return Some(first + 1);
                    }
                    _ => return None,
                }
                first += 1;
                if first >= self.end() {
                    return None;
                }
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn s(v: Option<Cow<'_, [u8]>>) -> Option<String> {
        v.map(|v| String::from_utf8(v.into_owned()).unwrap())
    }

    #[test]
    fn parses_and_normalizes() {
        let u = Uri::parse(b"HTTP://User:P%41ss@EXAMPLE.com:0080/A/./b/../%7e%2f?Q%41#F%61").unwrap();
        assert_eq!(s(u.scheme()).unwrap(), "http");
        assert_eq!(s(u.raw_scheme()).unwrap(), "HTTP");
        assert_eq!(s(u.host()).unwrap(), "example.com");
        assert_eq!(u.port(), Some(80));
        assert_eq!(String::from_utf8(u.path().into_owned()).unwrap(), "/A/~%2F");
        assert_eq!(s(u.user_info()).unwrap(), "User:PAss");
        assert_eq!(s(u.password()).unwrap(), "PAss");
        assert_eq!(
            String::from_utf8(u.to_string_normalized()).unwrap(),
            "http://User:PAss@example.com:80/A/~%2F?QA#Fa"
        );
    }

    #[test]
    fn ipv6_recomposes_expanded() {
        let u = Uri::parse(b"http://[::1]:80/a").unwrap();
        assert_eq!(s(u.host()).unwrap(), "[::1]");
        assert_eq!(
            String::from_utf8(u.to_raw_string()).unwrap(),
            "http://[0000:0000:0000:0000:0000:0000:0000:0001]:80/a"
        );
    }

    #[test]
    fn rejects() {
        assert!(Uri::parse(b"http://a@b@c").is_none());
        assert!(Uri::parse(b"http://h:99999999999999999999").is_none());
        assert!(Uri::parse(b"a b").is_none());
        assert!(Uri::parse(b"").is_some());
    }

    #[test]
    fn dot_segments() {
        let path = |u: &str| String::from_utf8(Uri::parse(u.as_bytes()).unwrap().path().into_owned()).unwrap();
        assert_eq!(path("a/../.."), "..");
        assert_eq!(path("/./a"), "/a");
        assert_eq!(path("./a:b"), "./a:b");
        assert_eq!(path("a/./b"), "a/b");
    }
}
