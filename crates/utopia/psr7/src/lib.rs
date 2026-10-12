//! HTTP messages: requests, responses and server requests with their
//! headers and bodies, URIs, streams, uploaded files and multipart bodies,
//! and factories that build requests with JSON, form, text, XML,
//! query-string or multipart payloads.
//!
//! Messages are values: every `with_*` method consumes the message and
//! returns the changed one (clone it to keep the original). Bodies are
//! [`StreamRef`]s, shared handles, so clones of a message read one stream
//! as PHP's copies of a message do.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Psr7\Message` (`MessageInterface`) | the [`Message`] trait, over a [`Head`] with [`Headers`] |
//! | `getProtocolVersion()`, `withProtocolVersion()` | [`Message::protocol_version`], [`Message::with_protocol_version`] |
//! | `getHeaders()`, `hasHeader()`, `getHeader()`, `getHeaderLine()` | [`Message::headers`], [`Message::has_header`], [`Message::header`], [`Message::header_line`] |
//! | `withHeader()`, `withAddedHeader()`, `withoutHeader()` | [`Message::with_header`], [`Message::with_added_header`], [`Message::without_header`] |
//! | `getBody()`, `withBody()` | [`Message::body`], [`Message::with_body`] |
//! | `Utopia\Psr7\Request` | [`Request`] |
//! | `getRequestTarget()`, `withRequestTarget()`, `getMethod()`, `withMethod()`, `getUri()`, `withUri()` | [`Request::request_target`], [`Request::with_request_target`], [`Request::method`], [`Request::with_method`], [`Request::uri`], [`Request::with_uri`] |
//! | `Utopia\Psr7\Request\Factory` | [`request::Factory`] |
//! | `createRequest()`, `json()`, `form()`, `text()`, `xml()`, `body()`, `query()`, `multipart()` | [`request::Factory::create_request`], [`request::Factory::json`], [`request::Factory::form`], [`request::Factory::text`], [`request::Factory::xml`], [`request::Factory::body`], [`request::Factory::query`], [`request::Factory::multipart`] |
//! | `Utopia\Psr7\Response` | [`Response`] |
//! | `getStatusCode()`, `withStatus()`, `getReasonPhrase()` | [`Response::status`], [`Response::with_status`], [`Response::reason`] |
//! | `contentType()`, `json()`, `text()`, `xml()`, `form()`, `multipart()` | [`Response::content_type`], [`Response::json`], [`Response::text`], [`Response::xml`] (an [`xml::Element`]), [`Response::form`], [`Response::multipart`] |
//! | `Utopia\Psr7\Response\Factory` | [`response::Factory`] ([`response::ResponseFactory`]) |
//! | `Utopia\Psr7\ServerRequest` | [`ServerRequest`]; the constructor's arguments are [`server_request::Parts`] |
//! | `getServerParams()`, `getCookieParams()`, `withCookieParams()`, `getQueryParams()`, `withQueryParams()` | [`ServerRequest::server_params`], [`ServerRequest::cookie_params`], [`ServerRequest::with_cookie_params`], [`ServerRequest::query_params`], [`ServerRequest::with_query_params`] |
//! | `getUploadedFiles()`, `withUploadedFiles()`, `getParsedBody()`, `withParsedBody()` | [`ServerRequest::uploaded_files`], [`ServerRequest::with_uploaded_files`], [`ServerRequest::parsed_body`], [`ServerRequest::with_parsed_body`] |
//! | `getAttributes()`, `getAttribute()`, `withAttribute()`, `withoutAttribute()` | [`ServerRequest::attributes`], [`ServerRequest::attribute`], [`ServerRequest::with_attribute`], [`ServerRequest::without_attribute`] |
//! | `Utopia\Psr7\ServerRequest\Factory` | [`server_request::Factory`] ([`server_request::ServerRequestFactory`]) |
//! | `Utopia\Psr7\Uri` | [`Uri`]; `new Uri(...)` is [`Uri::from_parts`] |
//! | `Uri::parse()`, `getScheme()`, `getAuthority()`, `getUserInfo()`, `getHost()`, `getPort()`, `getPath()`, `getQuery()`, `getFragment()` | [`Uri::parse`], [`Uri::scheme`], [`Uri::authority`], [`Uri::user_info`], [`Uri::host`], [`Uri::port`], [`Uri::path`], [`Uri::query`], [`Uri::fragment`] |
//! | `withScheme()`, `withUserInfo()`, `withHost()`, `withPort()`, `withPath()`, `withQuery()`, `withFragment()`, `__toString()` | [`Uri::with_scheme`], [`Uri::with_user_info`], [`Uri::with_host`], [`Uri::with_port`], [`Uri::with_path`], [`Uri::with_query`], [`Uri::with_fragment`], `Display` |
//! | `Utopia\Psr7\Uri\Factory` | [`uri::Factory`] ([`uri::UriFactory`]) |
//! | `StreamInterface` | the [`Stream`] trait; a message's body is a [`StreamRef`] |
//! | `Utopia\Psr7\Stream`, `Stream::fromResource()` | [`TempStream`], [`TempStream::from_file`], [`TempStream::from_detached`] |
//! | `read()`, `write()`, `seek()`, `rewind()`, `tell()`, `eof()`, `getSize()`, `getContents()`, `__toString()` | [`Stream::read`], [`Stream::write`], [`Stream::seek`], [`Stream::rewind`], [`Stream::tell`], [`Stream::eof`], [`Stream::size`], [`Stream::contents`], [`Stream::to_bytes`] |
//! | `close()`, `detach()`, `isReadable()`, `isWritable()`, `isSeekable()`, `getMetadata()` | [`Stream::close`], [`Stream::detach`], [`Stream::is_readable`], [`Stream::is_writable`], [`Stream::is_seekable`], [`Stream::metadata`] |
//! | `Utopia\Psr7\Stream\Factory` | [`stream::Factory`] ([`stream::StreamFactory`]) |
//! | `Utopia\Psr7\UploadedFile` | [`UploadedFile`]; a tree of them is [`UploadedFiles`] |
//! | `UploadedFile::normalizeFiles()` | [`uploaded_file::normalize_files`] |
//! | `getStream()`, `moveTo()`, `getSize()`, `getError()`, `getClientFilename()`, `getClientMediaType()` | [`UploadedFile::stream`], [`UploadedFile::move_to`], [`UploadedFile::size`], [`UploadedFile::error`], [`UploadedFile::client_filename`], [`UploadedFile::client_media_type`] |
//! | `Utopia\Psr7\Request\Multipart\Part` | [`multipart::Part`] |
//! | `Utopia\Psr7\Request\Multipart\Body` | [`multipart::Body`] |
//! | `Utopia\Psr7\Response\Multipart\Part` | [`multipart::Received`] |
//! | `Utopia\Psr7\Method`, `Header`, `ContentType` | [`method`], [`header`], [`content_type`] |
//! | `InvalidArgumentException`, `RuntimeException`, `JsonException`, `ValueError` | [`Error`], one variant each |
//!
//! PHP: the PSR-7 and PSR-17 interfaces (`Psr\Http\Message\*`) are the
//! traits above ([`Message`], [`Stream`], [`uri::UriFactory`],
//! [`stream::StreamFactory`], [`response::ResponseFactory`],
//! [`server_request::ServerRequestFactory`]). PHP messages are immutable
//! objects whose `with*()` methods return a modified clone.

#![warn(missing_docs)]

mod error;
mod message;
pub mod multipart;
mod names;
pub mod request;
pub mod response;
pub mod server_request;
pub mod stream;
pub mod uploaded_file;
pub mod uri;
pub mod xml;

pub use error::Error;
pub use message::{Head, HeaderValue, HeaderValues, Headers, Message};
pub use names::{content_type, header, method};
pub use request::Request;
pub use response::Response;
pub use server_request::ServerRequest;
pub use stream::{Stream, StreamRef, TempStream};
pub use uploaded_file::{UploadedFile, UploadedFiles};
pub use uri::Uri;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
