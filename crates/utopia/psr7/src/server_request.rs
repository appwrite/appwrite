//! Incoming requests as a server sees them.

use indexmap::IndexMap;
use serde_json::Value;

use crate::message::{Head, HeaderValues, Headers, Message};
use crate::request::{host, target};
use crate::stream::StreamRef;
use crate::uploaded_file::UploadedFiles;
use crate::uri::{self, Uri, UriFactory};
use crate::{Error, names::header};

/// An incoming request with what the server knows about it: server
/// parameters, cookies, the query, uploaded files, the parsed body and
/// attributes set while handling it.
///
/// Values PHP keeps as mixed arrays (server parameters, query parameters,
/// the parsed body, attributes) are JSON values here.
///
/// PHP: `Utopia\Psr7\ServerRequest` (`Psr\Http\Message\ServerRequestInterface`).
#[derive(Debug, Clone)]
pub struct ServerRequest {
    head: Head,
    method: String,
    uri: Uri,
    server_params: IndexMap<String, Value>,
    cookie_params: IndexMap<String, String>,
    query_params: IndexMap<String, Value>,
    uploaded_files: Vec<(php_std::ArrayKey, UploadedFiles)>,
    parsed_body: Option<Value>,
    attributes: IndexMap<String, Value>,
    request_target: Option<String>,
}

/// Everything a [`ServerRequest`] is built from, for [`ServerRequest::from_parts`].
#[derive(Debug, Clone, Default)]
pub struct Parts {
    /// The method, as given.
    pub method: String,
    /// The URI.
    pub uri: Uri,
    /// `$_SERVER`-like parameters.
    pub server_params: IndexMap<String, Value>,
    /// Cookies.
    pub cookie_params: IndexMap<String, String>,
    /// The query string's parameters.
    pub query_params: IndexMap<String, Value>,
    /// Uploaded files.
    pub uploaded_files: Vec<(php_std::ArrayKey, UploadedFiles)>,
    /// The parsed body: an array or object, or none.
    pub parsed_body: Option<Value>,
    /// Attributes.
    pub attributes: IndexMap<String, Value>,
    /// The request target, when it is not the URI's path and query.
    pub request_target: Option<String>,
    /// The body (empty when `None`).
    pub body: Option<StreamRef>,
    /// Headers, as received: names are not checked, and of two names
    /// differing only in case, lookups find the later.
    pub headers: Vec<(String, Vec<String>)>,
}

impl Message for ServerRequest {
    fn head(&self) -> &Head {
        &self.head
    }

    fn head_mut(&mut self) -> &mut Head {
        &mut self.head
    }
}

impl ServerRequest {
    /// A request with the method as given and nothing else.
    ///
    /// PHP: `new ServerRequest($method, $uri)`.
    pub fn new(method: &str, uri: Uri) -> ServerRequest {
        ServerRequest::from_parts(Parts { method: method.to_owned(), uri, ..Parts::default() })
    }

    /// A request of these parts.
    ///
    /// PHP: `new ServerRequest(...)` with every argument.
    pub fn from_parts(parts: Parts) -> ServerRequest {
        let mut head = Head::new(parts.body.unwrap_or_default());
        head.headers = Headers::from_pairs(parts.headers);
        ServerRequest {
            head,
            method: parts.method,
            uri: parts.uri,
            server_params: parts.server_params,
            cookie_params: parts.cookie_params,
            query_params: parts.query_params,
            uploaded_files: parts.uploaded_files,
            parsed_body: parts.parsed_body,
            attributes: parts.attributes,
            request_target: parts.request_target,
        }
    }

    /// The request target set with [`ServerRequest::with_request_target`],
    /// else the URI's path (`/` when empty) and query.
    ///
    /// PHP: `getRequestTarget()`.
    pub fn request_target(&self) -> String {
        match &self.request_target {
            Some(target) => target.clone(),
            None => target(&self.uri),
        }
    }

    /// The request with an explicit request target.
    ///
    /// PHP: `withRequestTarget()`.
    pub fn with_request_target(mut self, target: &str) -> ServerRequest {
        self.request_target = Some(target.to_owned());
        self
    }

    /// The method.
    ///
    /// PHP: `getMethod()`.
    pub fn method(&self) -> &str {
        &self.method
    }

    /// The request with another method, upper-cased.
    ///
    /// PHP: `withMethod()`.
    pub fn with_method(mut self, method: &str) -> ServerRequest {
        self.method = method.to_ascii_uppercase();
        self
    }

    /// The URI.
    ///
    /// PHP: `getUri()`.
    pub fn uri(&self) -> &Uri {
        &self.uri
    }

    /// The request with another URI; `Host` follows as for [`crate::Request::with_uri`].
    ///
    /// PHP: `withUri()`.
    pub fn with_uri(mut self, uri: Uri, preserve_host: bool) -> ServerRequest {
        let host = host(&uri);
        self.uri = uri;
        if let Some(host) = host
            && !(preserve_host && !self.header_line(header::HOST).is_empty())
        {
            let _ = self.head.headers.set(header::HOST, host.into_values());
        }
        self
    }

    /// Server parameters.
    ///
    /// PHP: `getServerParams()`.
    pub fn server_params(&self) -> &IndexMap<String, Value> {
        &self.server_params
    }

    /// Cookies.
    ///
    /// PHP: `getCookieParams()`.
    pub fn cookie_params(&self) -> &IndexMap<String, String> {
        &self.cookie_params
    }

    /// The request with other cookies.
    ///
    /// PHP: `withCookieParams()`.
    pub fn with_cookie_params(mut self, cookies: IndexMap<String, String>) -> ServerRequest {
        self.cookie_params = cookies;
        self
    }

    /// Query parameters.
    ///
    /// PHP: `getQueryParams()`.
    pub fn query_params(&self) -> &IndexMap<String, Value> {
        &self.query_params
    }

    /// The request with other query parameters.
    ///
    /// PHP: `withQueryParams()`.
    pub fn with_query_params(mut self, query: IndexMap<String, Value>) -> ServerRequest {
        self.query_params = query;
        self
    }

    /// Uploaded files by key.
    ///
    /// PHP: `getUploadedFiles()`.
    pub fn uploaded_files(&self) -> &[(php_std::ArrayKey, UploadedFiles)] {
        &self.uploaded_files
    }

    /// The request with other uploaded files.
    ///
    /// PHP: `withUploadedFiles()`.
    pub fn with_uploaded_files(mut self, files: Vec<(php_std::ArrayKey, UploadedFiles)>) -> ServerRequest {
        self.uploaded_files = files;
        self
    }

    /// The parsed body.
    ///
    /// PHP: `getParsedBody()`.
    pub fn parsed_body(&self) -> Option<&Value> {
        self.parsed_body.as_ref()
    }

    /// The request with another parsed body: `null` (none), an array or an object.
    ///
    /// Fails with [`Error::InvalidArgument`] for a scalar.
    ///
    /// PHP: `withParsedBody()`.
    pub fn with_parsed_body(mut self, data: Value) -> Result<ServerRequest, Error> {
        match data {
            Value::Null => self.parsed_body = None,
            Value::Array(_) | Value::Object(_) => self.parsed_body = Some(data),
            _ => return Err(Error::invalid("Parsed body must be null, an array, or an object.")),
        }
        Ok(self)
    }

    /// Every attribute.
    ///
    /// PHP: `getAttributes()`.
    pub fn attributes(&self) -> &IndexMap<String, Value> {
        &self.attributes
    }

    /// An attribute; an attribute set to `null` reads as missing.
    ///
    /// PHP: `getAttribute($name, $default)` (`$default` is `.unwrap_or(default)`).
    pub fn attribute(&self, name: &str) -> Option<&Value> {
        self.attributes.get(name).filter(|v| !v.is_null())
    }

    /// The request with an attribute set.
    ///
    /// PHP: `withAttribute()`.
    pub fn with_attribute(mut self, name: &str, value: Value) -> ServerRequest {
        self.attributes.insert(name.to_owned(), value);
        self
    }

    /// The request without an attribute.
    ///
    /// PHP: `withoutAttribute()`.
    pub fn without_attribute(mut self, name: &str) -> ServerRequest {
        self.attributes.shift_remove(name);
        self
    }
}

/// Creates server requests.
///
/// PHP: `Psr\Http\Message\ServerRequestFactoryInterface`.
pub trait ServerRequestFactory {
    /// A request with the method upper-cased and `Host` from the URI.
    ///
    /// PHP: `createServerRequest()`.
    fn create_server_request(
        &self,
        method: &str,
        uri: crate::request::Target<'_>,
        server_params: IndexMap<String, Value>,
    ) -> Result<ServerRequest, Error>;
}

/// The default [`ServerRequestFactory`], parsing URIs with a [`UriFactory`].
///
/// PHP: `Utopia\Psr7\ServerRequest\Factory`.
#[derive(Debug, Clone)]
pub struct Factory<U = uri::Factory> {
    uris: U,
}

impl Default for Factory {
    /// URIs parsed with [`Uri::parse`].
    fn default() -> Self {
        Factory { uris: uri::Factory }
    }
}

impl<U: UriFactory> Factory<U> {
    /// A factory parsing URIs with `uris`.
    ///
    /// PHP: `new ServerRequest\Factory($uriFactory)`.
    pub fn new(uris: U) -> Self {
        Factory { uris }
    }
}

impl<U: UriFactory> ServerRequestFactory for Factory<U> {
    fn create_server_request(
        &self,
        method: &str,
        uri: crate::request::Target<'_>,
        server_params: IndexMap<String, Value>,
    ) -> Result<ServerRequest, Error> {
        let uri = match uri {
            crate::request::Target::Text(text) => self.uris.create_uri(text)?,
            crate::request::Target::Uri(uri) => uri,
        };
        let request = ServerRequest::from_parts(Parts {
            method: method.to_ascii_uppercase(),
            uri: uri.clone(),
            server_params,
            ..Parts::default()
        });
        Ok(request.with_uri(uri, false))
    }
}
