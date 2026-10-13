//! The client: an adapter plus request defaults.

use std::path::PathBuf;
use std::sync::Arc;

use crate::adapter::{Adapter, Certificate, Options, Sink, Tls};
use crate::destinations::Destinations;
use crate::{Error, Request, Response, Uri, redirect};

/// An HTTP client: an [`Adapter`] (the transport, possibly decorated) plus
/// defaults applied to every request: a base URI for relative requests,
/// default headers (never overriding a header the request sets), and W3C
/// trace propagation.
///
/// Configuration is immutable: each `with_*` returns a configured copy. A
/// copy of a transport keeps no connection of the original.
///
/// PHP: `Utopia\Client\Client`.
///
/// ```no_run
/// use utopia_client::{Client, Http, Request};
///
/// # async fn run() -> Result<(), utopia_client::Error> {
/// let client = Client::new(Http::new())
///     .with_base_uri("https://api.example.com/v1")?
///     .with_headers([("Accept", vec!["application/json"])])
///     .with_bearer_auth("token");
/// let response = client.send(Request::new("GET", "users?page=2")?).await?;
/// println!("{}", response.status());
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Client\Adapter\Curl\Client as Curl;
/// use Utopia\Client\Client;
/// use Utopia\Psr7\Request;
/// use Utopia\Psr7\Uri;
///
/// $client = (new Client(new Curl()))
///     ->withBaseUri('https://api.example.com/v1')
///     ->withHeaders(['Accept' => 'application/json'])
///     ->withBearerAuth('token');
/// $request = new Request('GET', Uri::parse('users?page=2'));
/// // $client->sendRequest($request) sends GET https://api.example.com/v1/users?page=2
/// ```
#[derive(Debug)]
pub struct Client<A> {
    adapter: A,
    headers: Vec<(String, String, Vec<String>)>,
    base: Option<Uri>,
    trace: bool,
}

impl<A: Adapter> Client<A> {
    /// A client sending through `adapter`, which keeps its own destinations
    /// ([`Anywhere`](crate::Anywhere) unless changed).
    ///
    /// PHP: `new Client($adapter)`.
    pub fn new(adapter: A) -> Client<A> {
        Client { adapter, headers: Vec::new(), base: None, trace: false }
    }

    /// The adapter.
    pub fn adapter(&self) -> &A {
        &self.adapter
    }

    fn configure(&self, change: impl FnOnce(&mut Options)) -> Client<A> {
        let mut options = self.adapter.options().clone();
        change(&mut options);
        self.with_adapter(self.adapter.with_options(options))
    }

    /// The same defaults over another adapter.
    pub(crate) fn with_adapter(&self, adapter: A) -> Client<A> {
        Client { adapter, headers: self.headers.clone(), base: self.base.clone(), trace: self.trace }
    }

    /// Where requests may connect.
    ///
    /// PHP: `withDestinations($destinations)`, or the constructor's second
    /// argument.
    pub fn with_destinations(&self, destinations: impl Destinations + 'static) -> Client<A> {
        let destinations: Arc<dyn Destinations> = Arc::new(destinations);
        self.configure(|o| o.destinations = destinations)
    }

    /// The whole-request timeout in seconds (rounded to milliseconds; 0 is
    /// no limit).
    ///
    /// Fails with [`Error::Value`] for a negative or non-finite value.
    ///
    /// PHP: `withTimeout($seconds)`.
    pub fn with_timeout(&self, seconds: f64) -> Result<Client<A>, Error> {
        let timeout = Options::duration(seconds)?;
        Ok(self.configure(|o| o.timeout = timeout))
    }

    /// The connect timeout in seconds (rounded to milliseconds; 0 is no
    /// limit).
    ///
    /// Fails like [`Client::with_timeout`].
    ///
    /// PHP: `withConnectTimeout($seconds)`.
    pub fn with_connect_timeout(&self, seconds: f64) -> Result<Client<A>, Error> {
        let timeout = Options::duration(seconds)?;
        Ok(self.configure(|o| o.connect_timeout = timeout))
    }

    /// Whether TLS certificates and host names are verified (on by default).
    ///
    /// PHP: `withSslVerification($enabled)`.
    pub fn with_ssl_verification(&self, enabled: bool) -> Client<A> {
        self.configure(|o| o.verify = enabled)
    }

    /// Trusts the CA certificates in this PEM file instead of the built-in
    /// roots.
    ///
    /// PHP: `withCustomCA($path)`.
    pub fn with_custom_ca(&self, path: impl Into<PathBuf>) -> Client<A> {
        let path = path.into();
        self.configure(|o| o.ca = Some(path))
    }

    /// Presents this client certificate (PEM files).
    ///
    /// PHP: `withCertificate($certPath, $keyPath, $passphrase)`.
    pub fn with_certificate(
        &self,
        cert: impl Into<PathBuf>,
        key: impl Into<PathBuf>,
        passphrase: Option<&str>,
    ) -> Client<A> {
        let certificate = Certificate { cert: cert.into(), key: key.into(), passphrase: passphrase.map(str::to_owned) };
        self.configure(|o| o.certificate = Some(certificate))
    }

    /// The lowest TLS version to negotiate.
    ///
    /// PHP: `withMinTlsVersion($version)`.
    pub fn with_min_tls_version(&self, version: Tls) -> Client<A> {
        self.configure(|o| o.min_tls = Some(version))
    }

    /// Keeps the connection open and reuses it for the next request to the
    /// same origin, so the TCP and TLS handshakes are paid once. Off by
    /// default.
    ///
    /// PHP: `withConnectionReuse($enabled)`.
    pub fn with_connection_reuse(&self, enabled: bool) -> Client<A> {
        self.configure(|o| o.reuse = enabled)
    }

    /// Follows `Location` redirects, at most `max_hops` of them. Off by
    /// default, so a 3xx response is returned with its `Location`; a longer
    /// chain fails with [`Error::Protocol`].
    ///
    /// Fails with [`Error::Value`] for a negative hop limit.
    ///
    /// PHP: `withFollowRedirects($enabled, $maxHops)`.
    pub fn with_follow_redirects(&self, enabled: bool, max_hops: i64) -> Result<Client<A>, Error> {
        if max_hops < 0 {
            return Err(Error::Value("Redirect hop limit must be greater than or equal to zero.".into()));
        }
        let max_hops = u32::try_from(max_hops).unwrap_or(u32::MAX);
        Ok(self.configure(|o| {
            o.follow = enabled;
            o.max_hops = max_hops;
        }))
    }

    /// Sends this `User-Agent` with every request, right after `Host`, as
    /// cURL sends its `CURLOPT_USERAGENT`.
    ///
    /// PHP: the Curl adapter's native option `CURLOPT_USERAGENT`.
    pub fn with_user_agent(&self, user_agent: &str) -> Client<A> {
        let user_agent = user_agent.to_owned();
        self.configure(|o| o.user_agent = Some(user_agent))
    }

    /// Sends request paths as given, without removing `.` and `..`
    /// segments.
    ///
    /// PHP: the Curl adapter's native option `CURLOPT_PATH_AS_IS`.
    pub fn with_path_as_is(&self, enabled: bool) -> Client<A> {
        self.configure(|o| o.path_as_is = enabled)
    }

    /// Whether to advertise `Accept-Encoding` and decode the response (on by
    /// default). Off, nothing is advertised and the body arrives as sent,
    /// while `Content-Encoding` and `Content-Length` are still dropped from
    /// an encoded response, as PHP's adapter drops them.
    ///
    /// PHP: the Curl adapter's native option `CURLOPT_ENCODING => null`.
    pub fn with_encoding_negotiation(&self, enabled: bool) -> Client<A> {
        self.configure(|o| o.negotiate_encoding = enabled)
    }

    /// Default headers, added to each request that does not set them. A
    /// name given again (any case) replaces the earlier default.
    ///
    /// PHP: `withHeaders($headers)`.
    pub fn with_headers<N: AsRef<str>, V: Into<String>>(
        &self,
        headers: impl IntoIterator<Item = (N, Vec<V>)>,
    ) -> Client<A> {
        let mut client = self.with_adapter(self.adapter.with_options(self.adapter.options().clone()));
        for (name, values) in headers {
            let name = name.as_ref();
            let lower = name.to_ascii_lowercase();
            let values: Vec<String> = values.into_iter().map(Into::into).collect();
            match client.headers.iter_mut().find(|(key, _, _)| *key == lower) {
                Some(entry) => {
                    entry.1 = name.to_owned();
                    entry.2 = values;
                }
                None => client.headers.push((lower, name.to_owned(), values)),
            }
        }
        client
    }

    /// The URI relative requests resolve against.
    ///
    /// Fails with [`Error::InvalidArgument`] (`Base URI must be absolute.`)
    /// without a scheme and host.
    ///
    /// PHP: `withBaseUri($uri)`.
    pub fn with_base_uri(&self, uri: &str) -> Result<Client<A>, Error> {
        let uri = Uri::parse(uri)?;
        if uri.scheme().is_empty() || uri.host().is_empty() {
            return Err(Error::InvalidArgument("Base URI must be absolute.".into()));
        }
        let mut client = self.with_adapter(self.adapter.with_options(self.adapter.options().clone()));
        client.base = Some(uri);
        Ok(client)
    }

    /// A default `Authorization: Basic` header.
    ///
    /// PHP: `withBasicAuth($username, $password)`.
    pub fn with_basic_auth(&self, username: &str, password: &str) -> Client<A> {
        let credentials = php_std::encoding::base64_encode(format!("{username}:{password}").as_bytes());
        self.with_headers([("Authorization", vec![format!("Basic {credentials}")])])
    }

    /// A default `Authorization: Bearer` header.
    ///
    /// PHP: `withBearerAuth($token)`.
    pub fn with_bearer_auth(&self, token: &str) -> Client<A> {
        self.with_headers([("Authorization", vec![format!("Bearer {token}")])])
    }

    /// Propagates the active [`utopia_span::Span`] downstream as a W3C
    /// `traceparent` header, unless the request carries one. Off by default.
    ///
    /// PHP: `withTracePropagation($enabled)`.
    pub fn with_trace_propagation(&self, enabled: bool) -> Client<A> {
        let mut client = self.with_adapter(self.adapter.with_options(self.adapter.options().clone()));
        client.trace = enabled;
        client
    }

    /// Sends a request after applying the defaults, and returns the response
    /// with its body. A 4xx or 5xx response is returned, not an error.
    ///
    /// PHP: `sendRequest($request)`.
    pub async fn send(&self, request: Request) -> Result<Response, Error> {
        self.adapter.send(self.prepare(request)?).await
    }

    /// Sends a request after applying the defaults, passing each body chunk
    /// to `sink` as it arrives; the response carries an empty body.
    ///
    /// PHP: `stream($request, $sink)`.
    pub async fn stream<S: Sink>(&self, request: Request, sink: &mut S) -> Result<Response, Error> {
        self.adapter.stream(self.prepare(request)?, sink).await
    }

    /// The request as it is sent: base URI, default headers and trace
    /// applied.
    ///
    /// PHP: `prepare($request)` (private).
    pub fn prepare(&self, request: Request) -> Result<Request, Error> {
        let mut request = self.apply_base_uri(request);
        for (_, name, values) in &self.headers {
            if !request.headers().has(name) {
                request.headers_mut().set(name, values.iter().cloned())?;
            }
        }
        if self.trace
            && !request.headers().has("traceparent")
            && let Some(traceparent) = utopia_span::Span::current_traceparent()
        {
            request.headers_mut().set("traceparent", [traceparent])?;
        }
        Ok(request)
    }

    fn apply_base_uri(&self, request: Request) -> Request {
        let Some(base) = &self.base else { return request };
        let uri = request.uri();
        if !uri.scheme().is_empty() || !uri.host().is_empty() {
            return request;
        }
        let path = resolve_path(base.path(), uri.path());
        let resolved = base.clone().with_path(&path).with_query(uri.query()).with_fragment(uri.fragment());
        request.with_uri(resolved)
    }
}

fn resolve_path(base: &str, path: &str) -> String {
    if path.is_empty() {
        return if base.is_empty() { "/".to_owned() } else { base.to_owned() };
    }
    if path.starts_with('/') {
        return redirect::remove_dot_segments(path);
    }
    let mut joined = base.to_owned();
    if joined.is_empty() || !joined.ends_with('/') {
        joined.push('/');
    }
    joined.push_str(path);
    redirect::remove_dot_segments(&joined)
}
