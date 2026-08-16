//! HTTP request handler - mirrors `src/Server/HTTP/Swoole/Handler.php`.
//!
//! Flow:
//! 1. If `config.request_handler` is set, delegate to it.
//! 2. Else if `config.direct_response` is set, short-circuit with the configured body.
//! 3. Else if `config.fixed_backend` is set, forward there without touching the adapter.
//! 4. Else extract the `Host` header (reject 400 on missing/invalid), route via the
//!    adapter, then forward either as pooled hyper requests or as raw TCP bytes.

use std::sync::Arc;
use std::time::Duration;

use bytes::{Bytes, BytesMut};
use http_body_util::{BodyExt, Full};
use hyper::body::Incoming;
use hyper::header::{HeaderName, HeaderValue, CONNECTION, CONTENT_LENGTH, HOST, TRANSFER_ENCODING};
use hyper::{Method, Request, Response, StatusCode};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpStream;
use tokio::time::timeout;

use crate::adapter::Adapter;
use crate::error::ProxyError;
use crate::resolver::ResolverError;
use serde_json::json;
use utopia_console::Console;
use utopia_validators::{Hostname, Validator};

use super::config::HttpConfig;
use super::pool::Pools;

/// Shared per-worker state: typed config, the adapter, and the pool registry.
pub struct Handler {
    config: Arc<HttpConfig>,
    adapter: Arc<Adapter>,
    pools: Arc<Pools>,
}

impl std::fmt::Debug for Handler {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Handler").finish_non_exhaustive()
    }
}

impl Handler {
    pub fn new(config: Arc<HttpConfig>, adapter: Arc<Adapter>) -> Self {
        Self {
            config,
            adapter,
            pools: Arc::new(Pools::new()),
        }
    }

    pub fn adapter(&self) -> Arc<Adapter> {
        Arc::clone(&self.adapter)
    }

    pub fn config(&self) -> Arc<HttpConfig> {
        Arc::clone(&self.config)
    }

    /// Top-level request entry point. Never panics - any error path produces an
    /// HTTP response with an appropriate status.
    pub async fn handle(
        &self,
        request: Request<Incoming>,
    ) -> Result<Response<Full<Bytes>>, hyper::Error> {
        if let Some(handler) = self.config.request_handler.clone() {
            match handler(request, Arc::clone(&self.adapter)).await {
                Ok(response) => return Ok(response),
                Err(error) => {
                    let _ = Console::error(&format!("Request handler error: {error}"));
                    return Ok(text_response(
                        StatusCode::INTERNAL_SERVER_ERROR,
                        "Internal Server Error",
                    ));
                }
            }
        }

        if let Some(body) = self.config.direct_response.clone() {
            let status =
                StatusCode::from_u16(self.config.direct_response_status).unwrap_or(StatusCode::OK);
            return Ok(text_response(status, body));
        }

        let endpoint = match self.config.fixed_backend.clone() {
            Some(endpoint) => endpoint,
            None => match extract_host(&request) {
                Ok(host) => match self.adapter.route(host.as_bytes()).await {
                    Ok(result) => result.endpoint().to_string(),
                    Err(error) => return Ok(resolver_error_response(error)),
                },
                Err(response) => return Ok(*response),
            },
        };

        let forward = if self.config.raw_backend {
            self.forward_raw_request(request, &endpoint).await
        } else {
            self.forward_request(request, &endpoint).await
        };

        match forward {
            Ok(response) => Ok(response),
            Err(error) => {
                tracing::error!(%error, "proxy forwarding failed");
                Ok(service_unavailable())
            }
        }
    }

    /// Hyper-backed forwarder. Body is collected into memory before being sent,
    /// matching the PHP code's `$request->getContent()`.
    async fn forward_request(
        &self,
        request: Request<Incoming>,
        endpoint: &str,
    ) -> Result<Response<Full<Bytes>>, ProxyError> {
        let (host, port) = Adapter::parse_endpoint(endpoint, 80);
        let authority = format!("{host}:{port}");
        let pool = self.pools.get_or_create(&authority, self.config.pool_size);

        let mut pooled = pool
            .acquire(self.config.pool_timeout, self.config.connect_timeout)
            .await?;

        let (parts, body) = request.into_parts();
        let method = parts.method.clone();
        let path_and_query = parts
            .uri
            .path_and_query()
            .map_or_else(|| "/".to_string(), |pq| pq.as_str().to_string());

        // Build the forwarded request.
        let mut builder = Request::builder().method(&method).uri(&path_and_query);

        let host_value = if port == 80 {
            host.clone()
        } else {
            format!("{host}:{port}")
        };

        // Forward all headers except Host (rewritten) and Connection (stripped).
        // Cookies are ordinary headers in hyper so they ride along for free.
        for (name, value) in &parts.headers {
            if name == HOST || name == CONNECTION {
                continue;
            }
            builder = builder.header(name, value);
        }
        builder = builder.header(HOST, host_value);

        // Collect body for non-GET/HEAD. GET/HEAD always forward empty bytes.
        let body_bytes: Bytes = if method == Method::GET || method == Method::HEAD {
            Bytes::new()
        } else {
            match timeout(self.config.timeout, body.collect()).await {
                Ok(Ok(collected)) => collected.to_bytes(),
                Ok(Err(error)) => {
                    return Err(ProxyError::Other(format!(
                        "failed to read request body: {error}"
                    )))
                }
                Err(_) => {
                    return Err(ProxyError::Other(format!(
                        "reading request body timed out after {:?}",
                        self.config.timeout
                    )))
                }
            }
        };

        let outbound = builder
            .body(Full::new(body_bytes))
            .map_err(|error| ProxyError::Other(format!("build outbound request: {error}")))?;

        let backend_response =
            match timeout(self.config.timeout, pooled.client_mut().send(outbound)).await {
                Ok(Ok(response)) => response,
                Ok(Err(error)) => {
                    pooled.invalidate();
                    return Err(ProxyError::BackendConnect(error.to_string()));
                }
                Err(_) => {
                    pooled.invalidate();
                    return Err(ProxyError::Other(format!(
                        "backend request timed out after {:?}",
                        self.config.timeout
                    )));
                }
            };

        let (response_parts, response_body) = backend_response.into_parts();
        let body_bytes = match timeout(self.config.timeout, response_body.collect()).await {
            Ok(Ok(collected)) => collected.to_bytes(),
            Ok(Err(error)) => {
                pooled.invalidate();
                return Err(ProxyError::Other(format!(
                    "reading backend body failed: {error}"
                )));
            }
            Err(_) => {
                pooled.invalidate();
                return Err(ProxyError::Other(format!(
                    "reading backend body timed out after {:?}",
                    self.config.timeout
                )));
            }
        };

        let mut client_response = Response::builder()
            .status(if self.config.fast_path_assume_ok {
                StatusCode::OK
            } else {
                response_parts.status
            })
            .body(Full::new(body_bytes))
            .map_err(|error| ProxyError::Other(format!("build client response: {error}")))?;

        // Relay headers (skip hop-by-hop). The pool handles keep-alive itself.
        let headers = client_response.headers_mut();
        for (name, value) in &response_parts.headers {
            if is_hop_by_hop(name) {
                continue;
            }
            headers.append(name, value.clone());
        }

        Ok(client_response)
    }

    /// Raw TCP HTTP forwarder. Assumes `Content-Length`-framed GET/HEAD replies;
    /// falls back to the pooled hyper path for any other method.
    async fn forward_raw_request(
        &self,
        request: Request<Incoming>,
        endpoint: &str,
    ) -> Result<Response<Full<Bytes>>, ProxyError> {
        if request.method() != Method::GET && request.method() != Method::HEAD {
            return self.forward_request(request, endpoint).await;
        }

        let (host, port) = Adapter::parse_endpoint(endpoint, 80);
        let authority = format!("{host}:{port}");

        let mut stream =
            match timeout(self.config.connect_timeout, TcpStream::connect(&authority)).await {
                Ok(Ok(stream)) => stream,
                Ok(Err(error)) => return Err(ProxyError::BackendConnect(error.to_string())),
                Err(_) => {
                    return Err(ProxyError::BackendConnect(format!(
                        "connect to {authority} timed out"
                    )))
                }
            };
        stream
            .set_nodelay(true)
            .map_err(|error| ProxyError::BackendConnect(error.to_string()))?;

        let method = request.method().as_str();
        let path = request
            .uri()
            .path_and_query()
            .map_or_else(|| "/".to_string(), |pq| pq.as_str().to_string());
        let host_header = if port == 80 {
            host.clone()
        } else {
            format!("{host}:{port}")
        };

        let request_line = format!(
            "{method} {path} HTTP/1.1\r\nHost: {host_header}\r\nConnection: keep-alive\r\n\r\n",
        );

        if let Err(error) = timeout(
            self.config.timeout,
            stream.write_all(request_line.as_bytes()),
        )
        .await
        {
            return Err(ProxyError::Other(format!(
                "write request timed out: {error}"
            )));
        }

        let mut buffer = BytesMut::with_capacity(8192);
        let header_end = loop {
            if let Some(position) = find_subslice(&buffer, b"\r\n\r\n") {
                break position + 4;
            }
            let mut chunk = [0u8; 8192];
            let read = match timeout(self.config.timeout, stream.read(&mut chunk)).await {
                Ok(Ok(n)) => n,
                Ok(Err(error)) => return Err(ProxyError::Other(format!("read failed: {error}"))),
                Err(_) => return Err(ProxyError::Other("read timed out".to_string())),
            };
            if read == 0 {
                return Err(ProxyError::Other(
                    "backend closed before headers".to_string(),
                ));
            }
            buffer.extend_from_slice(&chunk[..read]);
        };

        let header_bytes = buffer.split_to(header_end);
        let header_str = std::str::from_utf8(&header_bytes[..header_end - 4])
            .map_err(|_| ProxyError::Other("non-utf8 header".to_string()))?;

        let mut lines = header_str.split("\r\n");
        let status_line = lines
            .next()
            .ok_or_else(|| ProxyError::Other("missing status line".to_string()))?;
        let status_code = parse_status_line(status_line).unwrap_or(200);

        let mut response_builder =
            Response::builder().status(if self.config.raw_backend_assume_ok {
                StatusCode::OK
            } else {
                StatusCode::from_u16(status_code).unwrap_or(StatusCode::OK)
            });

        let mut content_length: Option<usize> = None;
        let mut chunked = false;

        for line in lines {
            let Some((key, value)) = line.split_once(':') else {
                continue;
            };
            let key_trimmed = key.trim();
            let value_trimmed = value.trim();
            let lower = key_trimmed.to_ascii_lowercase();
            if lower == "content-length" {
                content_length = value_trimmed.parse().ok();
            } else if lower == "transfer-encoding" && value_trimmed.eq_ignore_ascii_case("chunked")
            {
                chunked = true;
            }
            if matches!(
                lower.as_str(),
                "connection" | "keep-alive" | "transfer-encoding" | "content-length"
            ) {
                continue;
            }
            response_builder = response_builder.header(key_trimmed, value_trimmed);
        }

        if chunked || content_length.is_none() {
            let body = Bytes::copy_from_slice(&buffer);
            return response_builder
                .body(Full::new(body))
                .map_err(|error| ProxyError::Other(format!("build response: {error}")));
        }

        let total = content_length.unwrap();
        let mut body = BytesMut::with_capacity(total);
        body.extend_from_slice(&buffer);
        while body.len() < total {
            let remaining = total - body.len();
            let mut chunk = vec![0u8; remaining.min(8192)];
            let read = match timeout(self.config.timeout, stream.read(&mut chunk)).await {
                Ok(Ok(n)) => n,
                Ok(Err(error)) => return Err(ProxyError::Other(format!("read failed: {error}"))),
                Err(_) => return Err(ProxyError::Other("read timed out".to_string())),
            };
            if read == 0 {
                return Err(ProxyError::Other("backend closed mid-body".to_string()));
            }
            body.extend_from_slice(&chunk[..read]);
        }

        response_builder
            .body(Full::new(body.freeze()))
            .map_err(|error| ProxyError::Other(format!("build response: {error}")))
    }
}

/// Validates a hostname. Accepts optional `:port` suffix (PHP `isValidHostname`).
pub fn is_valid_hostname(hostname: &str) -> bool {
    if hostname.is_empty() {
        return false;
    }
    let host_part = match hostname.rsplit_once(':') {
        Some((host, port)) if !port.is_empty() && port.chars().all(|c| c.is_ascii_digit()) => host,
        _ => hostname,
    };
    Hostname::new().is_valid(&json!(host_part))
}

fn extract_host(request: &Request<Incoming>) -> Result<String, Box<Response<Full<Bytes>>>> {
    let host_header = request.headers().get(HOST).and_then(|v| v.to_str().ok());
    let host = match host_header {
        Some(value) if !value.is_empty() => value.to_string(),
        _ => {
            return Err(Box::new(text_response(
                StatusCode::BAD_REQUEST,
                "Missing Host header",
            )));
        }
    };
    if !is_valid_hostname(&host) {
        return Err(Box::new(text_response(
            StatusCode::BAD_REQUEST,
            "Invalid Host header",
        )));
    }
    Ok(host)
}

fn resolver_error_response(error: ResolverError) -> Response<Full<Bytes>> {
    let status = StatusCode::from_u16(error.code()).unwrap_or(StatusCode::SERVICE_UNAVAILABLE);
    text_response(status, error.to_string())
}

fn service_unavailable() -> Response<Full<Bytes>> {
    let body = r#"{"error":"Service Unavailable","message":"The requested service is temporarily unavailable"}"#;
    let mut response = Response::builder()
        .status(StatusCode::SERVICE_UNAVAILABLE)
        .body(Full::new(Bytes::from_static(body.as_bytes())))
        .expect("static service-unavailable response must build");
    response.headers_mut().insert(
        hyper::header::CONTENT_TYPE,
        HeaderValue::from_static("application/json"),
    );
    response
}

fn text_response(status: StatusCode, body: impl Into<Bytes>) -> Response<Full<Bytes>> {
    Response::builder()
        .status(status)
        .body(Full::new(body.into()))
        .expect("text response must build")
}

fn parse_status_line(line: &str) -> Option<u16> {
    let mut parts = line.split_whitespace();
    let _http_version = parts.next()?;
    parts.next()?.parse().ok()
}

fn find_subslice(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack
        .windows(needle.len())
        .position(|window| window == needle)
}

fn is_hop_by_hop(name: &HeaderName) -> bool {
    matches!(
        name.as_str(),
        "connection"
            | "keep-alive"
            | "transfer-encoding"
            | "te"
            | "trailer"
            | "upgrade"
            | "proxy-authorization"
            | "proxy-authenticate"
    ) || name == CONTENT_LENGTH
        || name == TRANSFER_ENCODING
}

#[allow(dead_code)]
fn _timeout_marker(_: Duration) {}

#[cfg(test)]
mod tests {
    use super::is_valid_hostname;

    #[test]
    fn accepts_valid_hostnames() {
        assert!(is_valid_hostname("example.com"));
        assert!(is_valid_hostname("sub.example.com"));
        assert!(is_valid_hostname("example.com:8080"));
        assert!(is_valid_hostname("localhost"));
        assert!(is_valid_hostname("1.2.3.4"));
        assert!(is_valid_hostname("1.2.3.4:8080"));
    }

    #[test]
    fn rejects_invalid_hostnames() {
        assert!(!is_valid_hostname(""));
        assert!(!is_valid_hostname("-bad.com"));
        assert!(!is_valid_hostname("bad-.com"));
        assert!(!is_valid_hostname("bad..com"));
    }
}
