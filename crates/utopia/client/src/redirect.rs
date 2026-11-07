//! The redirect policy: when a response is a redirect, where it leads
//! (RFC 3986 reference resolution) and which credentials it may carry there.
//!
//! PHP: `Utopia\Client\Redirect`, whose static methods are the functions
//! here.

use crate::{Error, Headers, Response, Uri};

/// The most redirects a request follows by default.
///
/// PHP: `Redirect::MAX_HOPS`.
pub const MAX_HOPS: u32 = 50;

/// Headers dropped when a redirect changes origin.
const SENSITIVE_HEADERS: [&str; 4] = ["Authorization", "Cookie", "Cookie2", "Proxy-Authorization"];

/// Whether a response is a redirect to follow: a 3xx status with a
/// non-empty `Location`.
///
/// PHP: `Redirect::isRedirect($response)`.
pub fn is_redirect(response: &Response) -> bool {
    (300..400).contains(&response.status()) && !response.headers().line("Location").is_empty()
}

/// Resolves a `Location` against the URI it was received for (RFC 3986
/// §5.2), removing dot segments.
///
/// Fails with [`Error::InvalidArgument`] (`Invalid URI.`) when the location
/// does not parse.
///
/// PHP: `Redirect::resolve($base, $location)`.
///
/// ```
/// use utopia_client::{redirect, Uri};
///
/// let base = Uri::parse("https://example.com/a/b/c?q=1")?;
/// assert_eq!(redirect::resolve(&base, "../d")?.to_string(), "https://example.com/a/d");
/// assert_eq!(redirect::resolve(&base, "//cdn.example.com/x")?.to_string(), "https://cdn.example.com/x");
/// assert_eq!(redirect::resolve(&base, "#top")?.to_string(), "https://example.com/a/b/c?q=1#top");
/// # Ok::<(), utopia_client::Error>(())
/// ```
///
/// ```php
/// use Utopia\Client\Redirect;
/// use Utopia\Psr7\Uri;
///
/// $base = Uri::parse('https://example.com/a/b/c?q=1');
/// echo Redirect::resolve($base, '../d'), "\n"; // https://example.com/a/d
/// echo Redirect::resolve($base, '//cdn.example.com/x'), "\n"; // https://cdn.example.com/x
/// echo Redirect::resolve($base, '#top'), "\n"; // https://example.com/a/b/c?q=1#top
/// ```
pub fn resolve(base: &Uri, location: &str) -> Result<Uri, Error> {
    let target = Uri::parse(location)?;
    if !target.scheme().is_empty() {
        let path = remove_dot_segments(target.path());
        return Ok(target.with_path(&path));
    }
    if !target.host().is_empty() {
        let path = remove_dot_segments(target.path());
        return Ok(target.with_scheme(base.scheme()).with_path(&path));
    }
    let path = target.path();
    if path.is_empty() {
        let query = if location_has_query(location) { target.query() } else { base.query() };
        return Ok(base.clone().with_query(query).with_fragment(target.fragment()));
    }
    let merged =
        if path.starts_with('/') { remove_dot_segments(path) } else { remove_dot_segments(&merge(base, path)) };
    Ok(base.clone().with_path(&merged).with_query(target.query()).with_fragment(target.fragment()))
}

/// RFC 3986 §5.2.4 `remove_dot_segments`.
///
/// PHP: `Redirect::removeDotSegments($path)`.
pub fn remove_dot_segments(path: &str) -> String {
    let mut input = path;
    let mut output = String::with_capacity(path.len());
    while !input.is_empty() {
        if let Some(rest) = input.strip_prefix("../") {
            input = rest;
        } else if let Some(rest) = input.strip_prefix("./") {
            input = rest;
        } else if input.starts_with("/./") {
            input = &input[2..];
        } else if input == "/." {
            input = "/";
        } else if input.starts_with("/../") {
            input = &input[3..];
            truncate_last_segment(&mut output);
        } else if input == "/.." {
            input = "/";
            truncate_last_segment(&mut output);
        } else if input == "." || input == ".." {
            input = "";
        } else {
            let from = usize::from(input.starts_with('/'));
            match input[from..].find('/') {
                None => {
                    output.push_str(input);
                    input = "";
                }
                Some(i) => {
                    output.push_str(&input[..from + i]);
                    input = &input[from + i..];
                }
            }
        }
    }
    output
}

/// Whether two URIs share an origin: scheme, host and port (the scheme's
/// default when absent), compared case-insensitively.
///
/// PHP: `Redirect::isSameOrigin($from, $to)`.
pub fn is_same_origin(from: &Uri, to: &Uri) -> bool {
    origin(from) == origin(to)
}

/// Whether a hop from `from` to `to` must drop credentials: whenever the
/// origin changes, so an HTTPS to HTTP hop on the same host too.
///
/// PHP: `Redirect::shouldStripSensitiveHeaders($from, $to)`.
pub fn should_strip_sensitive_headers(from: &Uri, to: &Uri) -> bool {
    !is_same_origin(from, to)
}

/// Removes `Authorization`, `Cookie`, `Cookie2` and `Proxy-Authorization`.
///
/// PHP: `Redirect::withoutSensitiveHeaders($request)` (on the request's
/// headers).
pub fn without_sensitive_headers(headers: &mut Headers) {
    for name in SENSITIVE_HEADERS {
        headers.remove(name);
    }
}

fn location_has_query(location: &str) -> bool {
    location.split('#').next().unwrap_or_default().contains('?')
}

fn truncate_last_segment(path: &mut String) {
    match path.rfind('/') {
        Some(i) => path.truncate(i),
        None => path.clear(),
    }
}

/// RFC 3986 §5.2.3: merges a relative path with the base URI's.
fn merge(base: &Uri, relative: &str) -> String {
    let base_path = base.path();
    if !base.authority().is_empty() && base_path.is_empty() {
        return format!("/{relative}");
    }
    match base_path.rfind('/') {
        None => relative.to_owned(),
        Some(i) => format!("{}{relative}", &base_path[..=i]),
    }
}

fn origin(uri: &Uri) -> String {
    let scheme = uri.scheme().to_ascii_lowercase();
    let port = uri.port().unwrap_or(if scheme == "https" { 443 } else { 80 });
    format!("{scheme}://{}:{port}", uri.host().to_ascii_lowercase())
}
