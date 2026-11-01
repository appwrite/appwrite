//! The HTTP/2 transport APNs requires: TLS with ALPN `h2` (cleartext HTTP/2
//! with prior knowledge for `http://` URLs), one connection kept and shared
//! by the requests sent through it.

use std::net::SocketAddr;
use std::sync::Arc;
use std::time::{Duration, Instant};

use bytes::Bytes;
use http_body_util::{BodyExt, Full};
use hyper::client::conn::http2::SendRequest;
use utopia_async::hyper::{Executor, Io};
use utopia_async::net::TcpStream;
use utopia_async::sync::Mutex;
use utopia_client::{Body, Destinations, Error, Request, Response};

use crate::http::{BoxFuture, Transport};

/// Sends requests over HTTP/2.
///
/// PHP: the default cURL client of an adapter with
/// `CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0`, which APNs needs (it
/// refuses HTTP/1.1). Connections are checked against the destinations
/// once connected, before anything is sent, as `utopia_client` does.
pub struct Http2<D> {
    destinations: D,
    timeout: Option<Duration>,
    connect_timeout: Option<Duration>,
    connection: Mutex<Option<(String, SendRequest<Full<Bytes>>)>>,
}

impl<D: Destinations> Http2<D> {
    /// A transport to `destinations`, with timeouts in seconds (0 is none).
    pub fn new(destinations: D, timeout: i64, connect_timeout: i64) -> Http2<D> {
        let seconds = |s: i64| (s > 0).then(|| Duration::from_secs(s.unsigned_abs()));
        Http2 {
            destinations,
            timeout: seconds(timeout),
            connect_timeout: seconds(connect_timeout),
            connection: Mutex::new(None),
        }
    }

    async fn connect(&self, request: &Request, deadline: Option<Instant>) -> Result<SendRequest<Full<Bytes>>, Error> {
        let uri = request.uri();
        let host = uri.host().trim_start_matches('[').trim_end_matches(']').to_owned();
        let port = effective_port(uri);
        let started = Instant::now();
        let connect_deadline = self.connect_timeout.map(|t| started + t);
        let deadline = match (connect_deadline, deadline) {
            (Some(a), Some(b)) => Some(a.min(b)),
            (a, b) => a.or(b),
        };
        let timed_out =
            || Error::Timeout(format!("Connection timed out after {} milliseconds", started.elapsed().as_millis()));

        let addresses: Vec<SocketAddr> = match within(deadline, utopia_async::net::lookup_host((host.as_str(), port)))
            .await
        {
            None => return Err(timed_out()),
            Some(Err(_)) => return Err(Error::Dns(format!("Could not resolve host: {host} (Domain name not found)"))),
            Some(Ok(found)) => found.collect(),
        };
        let mut tcp = None;
        for address in &addresses {
            match within(deadline, TcpStream::connect(address)).await {
                None => return Err(timed_out()),
                Some(Ok(stream)) => {
                    tcp = Some(stream);
                    break;
                }
                Some(Err(_)) => continue,
            }
        }
        let Some(tcp) = tcp else {
            return Err(Error::Connection(format!(
                "Failed to connect to {host}:{port} after {} ms: Could not connect to server",
                started.elapsed().as_millis()
            )));
        };
        let _ = tcp.set_nodelay(true);
        let peer = tcp.peer_addr().map(|a| a.ip().to_canonical().to_string()).unwrap_or_default();
        if !self.destinations.allows(&peer) {
            return Err(Error::Destination(format!("Connection to {peer} refused: not an allowed destination.")));
        }

        let handshake = |e: hyper::Error| Error::Protocol(format!("HTTP/2 handshake failed: {e}"));
        if uri.scheme() == "http" {
            let (send, connection) =
                hyper::client::conn::http2::handshake(Executor, Io::new(tcp)).await.map_err(handshake)?;
            utopia_async::spawn(connection);
            return Ok(send);
        }

        let mut roots = rustls::RootCertStore::empty();
        roots.extend(webpki_roots::TLS_SERVER_ROOTS.iter().cloned());
        let provider = Arc::new(rustls::crypto::ring::default_provider());
        let mut config = rustls::ClientConfig::builder_with_provider(provider)
            .with_safe_default_protocol_versions()
            .map_err(|e| Error::Tls(e.to_string()))?
            .with_root_certificates(roots)
            .with_no_client_auth();
        config.alpn_protocols = vec![b"h2".to_vec()];
        let name = rustls::pki_types::ServerName::try_from(host.clone())
            .map_err(|_| Error::Tls(format!("Invalid TLS server name: {host}")))?;
        let tls = match within(deadline, tokio_rustls::TlsConnector::from(Arc::new(config)).connect(name, tcp)).await {
            None => return Err(timed_out()),
            Some(Err(e)) => return Err(Error::Tls(format!("TLS connect error: {e}"))),
            Some(Ok(tls)) => tls,
        };
        let (send, connection) =
            hyper::client::conn::http2::handshake(Executor, Io::new(tls)).await.map_err(handshake)?;
        utopia_async::spawn(connection);
        Ok(send)
    }

    async fn exchange(&self, request: Request) -> Result<Response, Error> {
        if !matches!(request.uri().scheme(), "http" | "https") || request.uri().host().is_empty() {
            return Err(Error::InvalidUri("Requests must use an absolute URI.".into()));
        }
        let deadline = self.timeout.map(|t| Instant::now() + t);
        let key = format!("{}://{}:{}", request.uri().scheme(), request.uri().host(), effective_port(request.uri()));

        let mut sender = {
            let mut kept = self.connection.lock().await;
            match kept.take() {
                Some((k, send)) if k == key && !send.is_closed() => send,
                _ => self.connect(&request, deadline).await?,
            }
        };
        within(deadline, sender.ready())
            .await
            .ok_or_else(|| Error::Timeout("Operation timed out".into()))?
            .map_err(|e| Error::Connection(e.to_string()))?;

        let body = match request.get_body() {
            Body::Empty => Bytes::new(),
            Body::Bytes(b) => b.clone(),
            Body::File(path) => {
                Bytes::from(utopia_async::fs::read(path).await.map_err(|e| Error::InvalidArgument(e.to_string()))?)
            }
        };
        let mut builder = http::Request::builder().method(request.method()).uri(request.uri().to_string());
        for (name, values) in request.headers().iter() {
            if name.eq_ignore_ascii_case("host") || name.eq_ignore_ascii_case("connection") {
                continue;
            }
            for value in values {
                builder = builder.header(name, value);
            }
        }
        let outgoing = builder.body(Full::new(body)).map_err(|e| Error::Request(e.to_string()))?;
        let exchange = async {
            let response = sender.send_request(outgoing).await.map_err(|e| Error::Connection(e.to_string()))?;
            let status = response.status().as_u16();
            let headers = response.headers().clone();
            let bytes = response.into_body().collect().await.map_err(|e| Error::Connection(e.to_string()))?.to_bytes();
            Ok::<_, Error>((status, headers, bytes))
        };
        let (status, headers, bytes) =
            within(deadline, exchange).await.ok_or_else(|| Error::Timeout("Operation timed out".into()))??;

        *self.connection.lock().await = Some((key, sender));

        let mut response = Response::new(status)?;
        for (name, value) in &headers {
            response = response.with_header(name.as_str(), &String::from_utf8_lossy(value.as_bytes()))?;
        }
        Ok(response.with_body(bytes))
    }
}

impl<D: Destinations + 'static> Transport for Http2<D> {
    fn send<'a>(&'a self, request: Request) -> BoxFuture<'a, Result<Response, Error>> {
        Box::pin(self.exchange(request))
    }
}

fn effective_port(uri: &utopia_client::Uri) -> u16 {
    uri.port().unwrap_or(if uri.scheme() == "https" { 443 } else { 80 })
}

async fn within<F: Future>(deadline: Option<Instant>, future: F) -> Option<F::Output> {
    match deadline {
        None => Some(future.await),
        Some(deadline) => utopia_async::time::timeout_at(deadline, future).await.ok(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use http_body_util::Full;
    use hyper::service::service_fn;
    use utopia_client::Anywhere;

    #[utopia_async::test]
    async fn speaks_http2_with_prior_knowledge() {
        let listener = utopia_async::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        utopia_async::spawn(async move {
            let (stream, _) = listener.accept().await.unwrap();
            let service = service_fn(|request: hyper::Request<hyper::body::Incoming>| async move {
                let version = format!("{:?}", request.version());
                let topic =
                    request.headers().get("apns-topic").map(|v| v.to_str().unwrap().to_owned()).unwrap_or_default();
                let body = request.into_body().collect().await.unwrap().to_bytes();
                let text = format!("{version} {topic} {}", String::from_utf8_lossy(&body));
                Ok::<_, std::convert::Infallible>(
                    hyper::Response::builder()
                        .status(200)
                        .header("apns-id", "abc")
                        .body(Full::new(Bytes::from(text)))
                        .unwrap(),
                )
            });
            hyper::server::conn::http2::Builder::new(Executor)
                .serve_connection(Io::new(stream), service)
                .await
                .unwrap();
        });

        let transport = Http2::new(Anywhere, 5, 5);
        for _ in 0..2 {
            let request = Request::new("POST", &format!("http://127.0.0.1:{port}/3/device/token"))
                .unwrap()
                .header("apns-topic", "com.example")
                .unwrap()
                .body("{}");
            let response = transport.send(request).await.unwrap();
            assert_eq!(response.status(), 200);
            assert_eq!(response.headers().line("apns-id"), "abc");
            assert_eq!(response.text(), "HTTP/2.0 com.example {}");
        }
    }

    #[utopia_async::test]
    async fn refuses_destinations_not_allowed() {
        let listener = utopia_async::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let transport = Http2::new(utopia_client::PublicInternet::default(), 5, 5);
        let request = Request::new("POST", &format!("http://127.0.0.1:{port}/")).unwrap();
        let error = transport.send(request).await.unwrap_err();
        assert_eq!(error.message(), "Connection to 127.0.0.1 refused: not an allowed destination.");
    }
}
