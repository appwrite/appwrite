//! Names used across HTTP messages: methods, header names and content types.

/// Request methods.
///
/// PHP: the constants of `Utopia\Psr7\Method`.
pub mod method {
    /// `CONNECT`
    pub const CONNECT: &str = "CONNECT";
    /// `DELETE`
    pub const DELETE: &str = "DELETE";
    /// `GET`
    pub const GET: &str = "GET";
    /// `HEAD`
    pub const HEAD: &str = "HEAD";
    /// `OPTIONS`
    pub const OPTIONS: &str = "OPTIONS";
    /// `PATCH`
    pub const PATCH: &str = "PATCH";
    /// `POST`
    pub const POST: &str = "POST";
    /// `PUT`
    pub const PUT: &str = "PUT";
    /// `TRACE`
    pub const TRACE: &str = "TRACE";
}

/// Common header names, in their usual spelling.
///
/// PHP: the constants of `Utopia\Psr7\Header`.
pub mod header {
    /// `Accept`
    pub const ACCEPT: &str = "Accept";
    /// `Accept-Encoding`
    pub const ACCEPT_ENCODING: &str = "Accept-Encoding";
    /// `Authorization`
    pub const AUTHORIZATION: &str = "Authorization";
    /// `Cache-Control`
    pub const CACHE_CONTROL: &str = "Cache-Control";
    /// `Content-Disposition`
    pub const CONTENT_DISPOSITION: &str = "Content-Disposition";
    /// `Content-Encoding`
    pub const CONTENT_ENCODING: &str = "Content-Encoding";
    /// `Content-Length`
    pub const CONTENT_LENGTH: &str = "Content-Length";
    /// `Content-Type`
    pub const CONTENT_TYPE: &str = "Content-Type";
    /// `Cookie`
    pub const COOKIE: &str = "Cookie";
    /// `Date`
    pub const DATE: &str = "Date";
    /// `ETag`
    pub const ETAG: &str = "ETag";
    /// `Host`
    pub const HOST: &str = "Host";
    /// `If-Match`
    pub const IF_MATCH: &str = "If-Match";
    /// `If-None-Match`
    pub const IF_NONE_MATCH: &str = "If-None-Match";
    /// `Location`
    pub const LOCATION: &str = "Location";
    /// `Referer`
    pub const REFERER: &str = "Referer";
    /// `Retry-After`
    pub const RETRY_AFTER: &str = "Retry-After";
    /// `Set-Cookie`
    pub const SET_COOKIE: &str = "Set-Cookie";
    /// `traceparent` (W3C Trace Context)
    pub const TRACEPARENT: &str = "traceparent";
    /// `User-Agent`
    pub const USER_AGENT: &str = "User-Agent";
}

/// Common media types.
///
/// PHP: the constants of `Utopia\Psr7\ContentType`.
pub mod content_type {
    /// `application/x-www-form-urlencoded`
    pub const FORM_URLENCODED: &str = "application/x-www-form-urlencoded";
    /// `text/html`
    pub const HTML: &str = "text/html";
    /// `application/json`
    pub const JSON: &str = "application/json";
    /// `application/merge-patch+json`
    pub const MERGE_PATCH_JSON: &str = "application/merge-patch+json";
    /// `multipart/form-data`
    pub const MULTIPART_FORM_DATA: &str = "multipart/form-data";
    /// `application/octet-stream`
    pub const OCTET_STREAM: &str = "application/octet-stream";
    /// `text/plain`
    pub const PLAIN_TEXT: &str = "text/plain";
    /// `application/problem+json`
    pub const PROBLEM_JSON: &str = "application/problem+json";
    /// `application/xml`
    pub const XML: &str = "application/xml";
}
