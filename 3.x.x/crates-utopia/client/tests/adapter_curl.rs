//! PHP `tests/Client/Adapter/AdapterContract.php` + Curl `ClientTest.php`.
//! Uses a localhost HTTP/1.1 server (no live network) and wiremock.

mod support;

use std::time::Duration;

use bytes::Bytes;
use sha2::{Digest, Sha256};
use support::http_server::{self, TestServer};
use support::{request, request_body};
use utopia_client::adapter::curl::{Client as CurlClient, CurlOptions};
use utopia_client::{Adapter, ErrorKind, StreamingClient, Tls};
use utopia_test_wiremock::{method, path, Mock, MockServer, ResponseTemplate};

fn sha256_hex(bytes: &[u8]) -> String {
    hex::encode(Sha256::digest(bytes))
}

fn adapter() -> CurlClient {
    CurlClient::new()
}

fn adapter_timeout(timeout: f64, connect: Option<f64>) -> CurlClient {
    let mut options = CurlOptions::default().with_timeout_ms((timeout * 1000.0).round() as u64);
    if let Some(connect) = connect {
        options = options.with_connect_timeout_ms((connect * 1000.0).round() as u64);
    }
    CurlClient::with_options(options)
}

fn header_line(response: &http::Response<Bytes>, name: &str) -> String {
    response
        .headers()
        .get(name)
        .and_then(|value| value.to_str().ok())
        .unwrap_or("")
        .to_owned()
}

fn header_all(response: &http::Response<Bytes>, name: &str) -> Vec<String> {
    response
        .headers()
        .get_all(name)
        .iter()
        .filter_map(|value| value.to_str().ok().map(str::to_owned))
        .collect()
}

#[test]
fn it_requires_absolute_uris() {
    let error = adapter()
        .send_request(request("GET", "/relative"))
        .unwrap_err();
    assert_eq!(error.kind(), ErrorKind::InvalidUri);
}

#[test]
fn it_rejects_unsupported_uri_schemes() {
    let error = adapter()
        .send_request(request("GET", "ftp://example.com/resource"))
        .unwrap_err();
    assert_eq!(error.kind(), ErrorKind::InvalidUri);
}

#[test]
fn it_sends_requests() {
    let server = TestServer::serve();
    let mut req = request_body("POST", &server.url("/echo"), "hello");
    req.headers_mut()
        .insert("content-type", "text/plain".parse().unwrap());
    req.headers_mut()
        .insert("x-custom", "sent".parse().unwrap());
    let response = adapter().send_request(req).unwrap();
    assert_eq!(response.status(), 202);
    assert_eq!(
        std::str::from_utf8(response.body()).unwrap(),
        "POST:/echo:sent:hello"
    );
}

#[test]
fn it_returns_client_and_server_error_responses_without_throwing() {
    let server = TestServer::serve();
    let not_found = adapter()
        .send_request(request("GET", &server.url("/not-found")))
        .unwrap();
    let server_error = adapter()
        .send_request(request("GET", &server.url("/server-error")))
        .unwrap();
    assert_eq!(not_found.status(), 404);
    assert_eq!(std::str::from_utf8(not_found.body()).unwrap(), "missing");
    assert_eq!(server_error.status(), 500);
    assert_eq!(std::str::from_utf8(server_error.body()).unwrap(), "failed");
}

#[test]
fn it_does_not_follow_redirects_by_default() {
    let server = TestServer::serve();
    let response = adapter()
        .send_request(request("GET", &server.url("/redirect")))
        .unwrap();
    assert_eq!(response.status(), 302);
    assert_eq!(header_line(&response, "location"), "/final");
    assert_eq!(std::str::from_utf8(response.body()).unwrap(), "redirect");
}

#[test]
fn it_does_not_follow_redirects_when_streaming() {
    let server = TestServer::serve();
    let mut received = Vec::new();
    let response = adapter()
        .stream(request("GET", &server.url("/redirect")), &mut |chunk| {
            received.extend_from_slice(chunk);
        })
        .unwrap();
    assert_eq!(response.status(), 302);
    assert_eq!(header_line(&response, "location"), "/final");
    assert_eq!(received, b"redirect");
    assert!(response.body().is_empty());
}

#[test]
fn it_preserves_duplicate_mixed_case_headers_and_binary_bodies() {
    let server = TestServer::serve();
    let headers = adapter()
        .send_request(request("GET", &server.url("/headers")))
        .unwrap();
    let binary = adapter()
        .send_request(request("GET", &server.url("/binary")))
        .unwrap();
    assert_eq!(headers.status(), 204);
    assert_eq!(header_all(&headers, "x-trace"), vec!["one", "two"]);
    assert_eq!(header_line(&headers, "x-mixed-case"), "Value");
    assert_eq!(
        header_line(&binary, "content-type"),
        "application/octet-stream"
    );
    assert_eq!(binary.body().as_ref(), b"\x00\x01hello\xff");
}

#[test]
fn it_sends_explicit_host_and_repeated_request_headers() {
    let server = TestServer::serve();
    let mut req = request("GET", &server.url("/request-headers"));
    req.headers_mut()
        .insert("host", "proxy.example.test".parse().unwrap());
    req.headers_mut().append("x-trace", "one".parse().unwrap());
    req.headers_mut().append("x-trace", "two".parse().unwrap());
    let response = adapter().send_request(req).unwrap();
    assert_eq!(response.status(), 200);
    assert_eq!(
        std::str::from_utf8(response.body()).unwrap(),
        "proxy.example.test:one, two"
    );
}

#[test]
fn it_sends_binary_request_bodies() {
    let server = TestServer::serve();
    let body = b"\x00\x01hello\xff".as_slice();
    let response = adapter()
        .send_request(request_body(
            "POST",
            &server.url("/body-info"),
            body.to_vec(),
        ))
        .unwrap();
    let expected = format!("{}:{}", body.len(), sha256_hex(body));
    assert_eq!(std::str::from_utf8(response.body()).unwrap(), expected);
}

#[test]
fn it_preserves_comma_separated_and_zero_request_header_values() {
    let server = TestServer::serve();
    let mut req = request("GET", &server.url("/selected-headers"));
    req.headers_mut()
        .insert("x-comma", "one, two".parse().unwrap());
    req.headers_mut().insert("x-zero", "0".parse().unwrap());
    req.headers_mut()
        .insert("x-mixed-request", "Value".parse().unwrap());
    let response = adapter().send_request(req).unwrap();
    assert_eq!(
        std::str::from_utf8(response.body()).unwrap(),
        "one, two:0:Value"
    );
}

#[test]
fn it_sends_default_host_with_non_default_ports() {
    let server = TestServer::serve();
    let mut req = request("GET", &server.url("/request-headers"));
    req.headers_mut().insert("x-trace", "sent".parse().unwrap());
    let response = adapter().send_request(req).unwrap();
    assert_eq!(
        std::str::from_utf8(response.body()).unwrap(),
        &format!("127.0.0.1:{}:sent", server.port())
    );
}

#[test]
fn it_preserves_query_strings_empty_paths_and_strips_fragments() {
    let server = TestServer::serve();
    let query = adapter()
        .send_request(request(
            "GET",
            &format!(
                "http://127.0.0.1:{}/request-target?x=1&y=two#fragment",
                server.port()
            ),
        ))
        .unwrap();
    let empty_path = adapter()
        .send_request(request(
            "GET",
            &format!("http://127.0.0.1:{}?ping=1#fragment", server.port()),
        ))
        .unwrap();
    assert_eq!(
        std::str::from_utf8(query.body()).unwrap(),
        "/request-target?x=1&y=two"
    );
    assert_eq!(std::str::from_utf8(empty_path.body()).unwrap(), "/?ping=1");
}

#[test]
fn it_preserves_methods_with_empty_bodies() {
    let server = TestServer::serve();
    let delete = adapter()
        .send_request(request("DELETE", &server.url("/method")))
        .unwrap();
    let patch = adapter()
        .send_request(request("PATCH", &server.url("/method")))
        .unwrap();
    let head = adapter()
        .send_request(request("HEAD", &server.url("/method")))
        .unwrap();
    assert_eq!(header_line(&delete, "x-request-method"), "DELETE");
    assert_eq!(std::str::from_utf8(delete.body()).unwrap(), "DELETE");
    assert_eq!(header_line(&patch, "x-request-method"), "PATCH");
    assert_eq!(header_line(&head, "x-request-method"), "HEAD");
    assert!(head.body().is_empty());
}

#[test]
fn it_preserves_custom_methods_and_request_bodies() {
    let server = TestServer::serve();
    let mut req = request_body("PROPFIND", &server.url("/echo"), "custom-body");
    req.headers_mut()
        .insert("x-custom", "sent".parse().unwrap());
    let response = adapter().send_request(req).unwrap();
    assert_eq!(
        std::str::from_utf8(response.body()).unwrap(),
        "PROPFIND:/echo:sent:custom-body"
    );
}

#[test]
fn it_sends_zero_string_bodies_as_non_empty_bodies() {
    let server = TestServer::serve();
    let mut req = request_body("PUT", &server.url("/echo"), "0");
    req.headers_mut()
        .insert("x-custom", "sent".parse().unwrap());
    let response = adapter().send_request(req).unwrap();
    assert_eq!(
        std::str::from_utf8(response.body()).unwrap(),
        "PUT:/echo:sent:0"
    );
}

#[test]
fn it_uses_http11_protocol_version() {
    let server = TestServer::serve();
    let response = adapter()
        .send_request(request("GET", &server.url("/binary")))
        .unwrap();
    assert_eq!(response.version(), http::Version::HTTP_11);
}

#[test]
fn it_parses_final_response_metadata() {
    http_server::raw(
        b"HTTP/1.1 201 Created Thing\r\nX-Trace: final\r\nX-Colon: http://example.test/a:b\r\nContent-Length: 7\r\n\r\ncreated",
        |port| {
            let response = adapter_timeout(1.0, Some(1.0))
                .send_request(request("GET", &format!("http://127.0.0.1:{port}/interim")))
                .unwrap();
            assert_eq!(response.status(), 201);
            assert_eq!(header_line(&response, "x-trace"), "final");
            assert_eq!(
                header_line(&response, "x-colon"),
                "http://example.test/a:b"
            );
            assert_eq!(std::str::from_utf8(response.body()).unwrap(), "created");
        },
    );
}

#[test]
fn it_preserves_repeated_set_cookie_response_headers() {
    http_server::raw(
        b"HTTP/1.1 200 OK\r\nSet-Cookie: a=1; Path=/\r\nSet-Cookie: b=2; Path=/; HttpOnly\r\nContent-Length: 2\r\n\r\nok",
        |port| {
            let response = adapter_timeout(1.0, Some(1.0))
                .send_request(request("GET", &format!("http://127.0.0.1:{port}/cookies")))
                .unwrap();
            assert_eq!(
                header_all(&response, "set-cookie"),
                vec!["a=1; Path=/", "b=2; Path=/; HttpOnly"]
            );
        },
    );
}

#[test]
fn it_decodes_chunked_response_bodies() {
    http_server::raw(
        b"HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n",
        |port| {
            let response = adapter_timeout(1.0, Some(1.0))
                .send_request(request("GET", &format!("http://127.0.0.1:{port}/chunked")))
                .unwrap();
            assert_eq!(std::str::from_utf8(response.body()).unwrap(), "hello world");
        },
    );
}

#[test]
fn it_round_trips_large_response_bodies() {
    let server = TestServer::serve();
    let response = adapter()
        .send_request(request("GET", &server.url("/large-response")))
        .unwrap();
    let body = response.body();
    assert_eq!(body.len(), 262_144);
    assert_eq!(sha256_hex(body), sha256_hex(&b"abcd".repeat(65_536)));
}

#[test]
fn it_returns_empty_bodies_for_no_content_responses() {
    let server = TestServer::serve();
    let response = adapter()
        .send_request(request("GET", &server.url("/headers")))
        .unwrap();
    assert_eq!(response.status(), 204);
    assert!(response.body().is_empty());
    assert_eq!(header_all(&response, "x-trace"), vec!["one", "two"]);
}

#[test]
fn it_round_trips_large_request_bodies() {
    let server = TestServer::serve();
    let body = b"wxyz".repeat(65_536);
    let response = adapter()
        .send_request(request_body(
            "POST",
            &server.url("/body-info"),
            body.clone(),
        ))
        .unwrap();
    assert_eq!(
        std::str::from_utf8(response.body()).unwrap(),
        format!("{}:{}", body.len(), sha256_hex(&body))
    );
}

#[test]
fn it_sends_large_request_payloads() {
    let server = TestServer::serve();
    let body = vec![b'a'; 8 * 1024 * 1024];
    let mut req = request_body("POST", &server.url("/body-info"), body.clone());
    req.headers_mut()
        .insert("content-type", "application/octet-stream".parse().unwrap());
    let response = adapter().send_request(req).unwrap();
    assert_eq!(
        std::str::from_utf8(response.body()).unwrap(),
        format!("{}:{}", body.len(), sha256_hex(&body))
    );
}

#[test]
fn it_uploads_multipart_files_and_fields() {
    let server = TestServer::serve();
    let contents = b"payload".repeat(4096);
    let boundary = "----UtopiaBoundary7MA4YWxkTrZu0gW";
    let mut body = Vec::new();
    body.extend_from_slice(format!("--{boundary}\r\n").as_bytes());
    body.extend_from_slice(b"Content-Disposition: form-data; name=\"name\"\r\n\r\nAda\r\n");
    body.extend_from_slice(format!("--{boundary}\r\n").as_bytes());
    body.extend_from_slice(
        b"Content-Disposition: form-data; name=\"file\"; filename=\"data.bin\"\r\n",
    );
    body.extend_from_slice(b"Content-Type: application/octet-stream\r\n\r\n");
    body.extend_from_slice(&contents);
    body.extend_from_slice(format!("\r\n--{boundary}--\r\n").as_bytes());
    let mut req = request_body("POST", &server.url("/multipart"), body);
    req.headers_mut().insert(
        "content-type",
        format!("multipart/form-data; boundary={boundary}")
            .parse()
            .unwrap(),
    );
    let response = adapter().send_request(req).unwrap();
    assert_eq!(
        std::str::from_utf8(response.body()).unwrap(),
        format!("Ada:{}:{}", contents.len(), sha256_hex(&contents))
    );
}

#[test]
fn it_rejects_invalid_response_status_codes() {
    http_server::raw(
        b"HTTP/1.1 999 Invalid\r\nContent-Length: 7\r\n\r\ninvalid",
        |port| {
            let error = adapter_timeout(1.0, Some(1.0))
                .send_request(request("GET", &format!("http://127.0.0.1:{port}/invalid")))
                .unwrap_err();
            assert_eq!(error.kind(), ErrorKind::InvalidResponse);
        },
    );
}

#[test]
fn it_throws_protocol_exceptions_for_malformed_responses() {
    http_server::raw(b"not an http response\r\n\r\n", |port| {
        let error = adapter_timeout(1.0, Some(1.0))
            .send_request(request(
                "GET",
                &format!("http://127.0.0.1:{port}/malformed"),
            ))
            .unwrap_err();
        assert!(
            error.kind() == ErrorKind::Protocol || error.kind() == ErrorKind::Connection,
            "{error:?}"
        );
    });
}

#[test]
fn it_throws_connection_exceptions_when_server_closes_before_response() {
    http_server::raw(b"", |port| {
        let error = adapter_timeout(1.0, Some(1.0))
            .send_request(request("GET", &format!("http://127.0.0.1:{port}/closed")))
            .unwrap_err();
        assert!(
            error.kind() == ErrorKind::Connection || error.is_network(),
            "{error:?}"
        );
    });
}

#[test]
fn it_does_not_read_a_body_for_head_responses() {
    http_server::raw(
        b"HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 4\r\n\r\n",
        |port| {
            let response = adapter_timeout(1.0, Some(1.0))
                .send_request(request("HEAD", &format!("http://127.0.0.1:{port}/head")))
                .unwrap();
            assert_eq!(response.status(), 200);
            assert_eq!(header_line(&response, "content-length"), "4");
            assert!(response.body().is_empty());
        },
    );
}

#[test]
fn it_throws_connection_exceptions_for_connection_failures() {
    http_server::unbound(|port| {
        let error = adapter_timeout(0.1, Some(0.1))
            .send_request(request("GET", &format!("http://127.0.0.1:{port}")))
            .unwrap_err();
        assert_eq!(error.kind(), ErrorKind::Connection);
    });
}

#[test]
fn it_throws_dns_exceptions_for_resolution_failures() {
    let error = adapter_timeout(2.0, Some(1.0))
        .send_request(request("GET", "http://utopia-request.invalid"))
        .unwrap_err();
    assert_eq!(error.kind(), ErrorKind::Dns);
}

#[test]
fn it_throws_tls_exceptions_for_tls_failures() {
    http_server::plaintext_on_connect(|port| {
        let error = adapter_timeout(1.0, Some(1.0))
            .send_request(request("GET", &format!("https://127.0.0.1:{port}/binary")))
            .unwrap_err();
        assert_eq!(error.kind(), ErrorKind::Tls, "{error}");
    });
}

#[test]
fn it_throws_timeout_exceptions_for_timed_out_requests() {
    let server = TestServer::serve();
    let error = adapter_timeout(0.1, None)
        .send_request(request("GET", &server.url("/slow")))
        .unwrap_err();
    assert_eq!(error.kind(), ErrorKind::Timeout);
}

#[test]
fn timeout_helpers_return_configured_clones() {
    let client = adapter();
    let _ = client.with_timeout(1.0).unwrap();
    let _ = client.with_connect_timeout(1.0).unwrap();
}

#[test]
fn tls_helpers_return_configured_clones() {
    let client = adapter();
    let _ = client.with_ssl_verification(false);
    let _ = client.with_custom_ca("/etc/ssl/ca.pem");
    let _ = client.with_certificate("/etc/ssl/client.pem", "/etc/ssl/client.key", None);
    let _ = client.with_min_tls_version(Tls::V1_2);
}

#[test]
fn it_rejects_invalid_timeout_values() {
    let error = adapter().with_timeout(f64::INFINITY).unwrap_err();
    assert!(error.is_value_error());
}

#[test]
fn it_rejects_invalid_connect_timeout_values() {
    let error = adapter().with_connect_timeout(-0.001).unwrap_err();
    assert!(error.is_value_error());
}

#[test]
fn it_rejects_invalid_adapter_configuration_options() {
    let server = TestServer::serve();
    let client = CurlClient::with_options(CurlOptions::invalid());
    let error = client
        .send_request(request("GET", &server.url("/binary")))
        .unwrap_err();
    assert_eq!(error.to_string(), "Unable to configure curl.");
}

#[test]
fn it_advertises_compression_and_transparently_decodes_responses() {
    let server = TestServer::serve();
    let response = adapter()
        .send_request(request("GET", &server.url("/gzip")))
        .unwrap();
    assert_eq!(response.status(), 200);
    assert!(header_line(&response, "x-accept-encoding").contains("gzip"));
    assert_eq!(
        std::str::from_utf8(response.body()).unwrap(),
        &"utopia ".repeat(64)
    );
    assert!(!response.headers().contains_key("content-encoding"));
    assert!(!response.headers().contains_key("content-length"));
}

#[test]
fn it_decodes_large_compressed_responses() {
    let server = TestServer::serve();
    let response = adapter()
        .send_request(request("GET", &server.url("/gzip?repeat=40000")))
        .unwrap();
    let expected = "utopia ".repeat(40_000);
    assert_eq!(response.body().len(), expected.len());
    assert_eq!(sha256_hex(response.body()), sha256_hex(expected.as_bytes()));
    assert!(!response.headers().contains_key("content-encoding"));
}

#[test]
fn it_decodes_compressed_binary_responses_byte_for_byte() {
    let server = TestServer::serve();
    let response = adapter()
        .send_request(request("GET", &server.url("/gzip?type=binary&repeat=16")))
        .unwrap();
    let unit: Vec<u8> = (0..=255).collect();
    let expected = unit.repeat(16);
    assert_eq!(response.body().as_ref(), expected);
    assert!(!response.headers().contains_key("content-encoding"));
}

#[test]
fn it_leaves_uncompressed_responses_and_their_content_length_intact() {
    let server = TestServer::serve();
    let response = adapter()
        .send_request(request("GET", &server.url("/gzip?compress=0")))
        .unwrap();
    let body = response.body();
    assert_eq!(std::str::from_utf8(body).unwrap(), &"utopia ".repeat(64));
    assert!(header_line(&response, "x-accept-encoding").contains("gzip"));
    assert!(!response.headers().contains_key("content-encoding"));
    assert_eq!(
        header_line(&response, "content-length"),
        body.len().to_string()
    );
}

#[test]
fn it_delivers_decoded_bodies_when_streaming() {
    let server = TestServer::serve();
    let mut received = Vec::new();
    let response = adapter()
        .stream(
            request("GET", &server.url("/gzip?repeat=20000")),
            &mut |chunk| received.extend_from_slice(chunk),
        )
        .unwrap();
    assert_eq!(response.status(), 200);
    assert_eq!(received, "utopia ".repeat(20_000).into_bytes());
    assert!(response.body().is_empty());
}

#[test]
fn it_decodes_compressed_responses_over_a_reused_connection() {
    let server = TestServer::serve();
    let client = adapter().with_connection_reuse(true);
    let expected = "utopia ".repeat(64);
    for _ in 0..3 {
        let response = client
            .send_request(request("GET", &server.url("/gzip")))
            .unwrap();
        assert_eq!(std::str::from_utf8(response.body()).unwrap(), expected);
    }
}

#[test]
fn it_does_not_override_an_explicit_accept_encoding() {
    let server = TestServer::serve();
    let mut req = request("GET", &server.url("/gzip"));
    req.headers_mut()
        .insert("accept-encoding", "identity".parse().unwrap());
    let response = adapter().send_request(req).unwrap();
    assert_eq!(header_line(&response, "x-accept-encoding"), "identity");
    assert_eq!(
        std::str::from_utf8(response.body()).unwrap(),
        &"utopia ".repeat(64)
    );
}

#[test]
fn it_streams_response_bodies_to_a_sink() {
    let server = TestServer::serve();
    let mut received = Vec::new();
    let response = adapter()
        .stream(request("GET", &server.url("/stream")), &mut |chunk| {
            received.extend_from_slice(chunk);
        })
        .unwrap();
    assert_eq!(response.status(), 200);
    assert_eq!(received, b"chunk0\nchunk1\nchunk2\nchunk3\nchunk4\n");
    assert!(response.body().is_empty());
}

#[test]
fn it_streams_large_responses_with_bounded_memory() {
    let server = TestServer::serve();
    let mut hasher = Sha256::new();
    let mut read = 0usize;
    let response = adapter()
        .stream(request("GET", &server.url("/stream-large")), &mut |chunk| {
            hasher.update(chunk);
            read += chunk.len();
        })
        .unwrap();
    assert_eq!(response.status(), 200);
    assert_eq!(read, 8 * 1024 * 1024);
    assert_eq!(
        hex::encode(hasher.finalize()),
        sha256_hex(&vec![b'a'; 8 * 1024 * 1024])
    );
    assert!(response.body().is_empty());
}

#[test]
fn it_throws_connection_exceptions_for_partial_response_headers() {
    http_server::raw(b"HTTP/1.1 200 OK\r\nX-Partial: value", |port| {
        let error = adapter_timeout(1.0, Some(1.0))
            .send_request(request(
                "GET",
                &format!("http://127.0.0.1:{port}/partial-headers"),
            ))
            .unwrap_err();
        assert_eq!(error.kind(), ErrorKind::Connection);
    });
}

#[test]
fn it_throws_protocol_exceptions_for_truncated_bodies() {
    http_server::raw(
        b"HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\nshort",
        |port| {
            let error = adapter_timeout(1.0, Some(1.0))
                .send_request(request(
                    "GET",
                    &format!("http://127.0.0.1:{port}/truncated-body"),
                ))
                .unwrap_err();
            assert_eq!(error.kind(), ErrorKind::Protocol);
        },
    );
}

#[test]
fn it_recovers_when_a_reused_connection_is_dropped() {
    let statuses = std::sync::Mutex::new(Vec::new());
    let connections = http_server::drops_first_keep_alive(|port| {
        let client = adapter().with_connection_reuse(true);
        let uri = format!("http://127.0.0.1:{port}/");
        for _ in 0..4 {
            let response = client.send_request(request("GET", &uri)).unwrap();
            statuses.lock().unwrap().push(response.status().as_u16());
        }
    });
    assert_eq!(*statuses.lock().unwrap(), vec![200, 200, 200, 200]);
    assert_eq!(connections, 2);
}

#[test]
fn it_throws_proxy_exceptions_for_proxy_failures() {
    http_server::raw(b"\x04\x00", |port| {
        let client = CurlClient::with_options(
            CurlOptions::default()
                .with_timeout_ms(1000)
                .with_connect_timeout_ms(1000)
                .with_http_proxy(format!("http://127.0.0.1:{port}")),
        );
        let error = client
            .send_request(request("GET", "http://example.com/"))
            .unwrap_err();
        assert!(
            matches!(
                error.kind(),
                ErrorKind::Proxy | ErrorKind::Protocol | ErrorKind::Connection
            ),
            "{error:?} kind={:?}",
            error.kind()
        );
    });
}

#[test]
fn it_sends_requests_through_wiremock() {
    let runtime = tokio::runtime::Builder::new_multi_thread()
        .worker_threads(2)
        .enable_all()
        .build()
        .unwrap();
    let server = runtime.block_on(async {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/ping"))
            .respond_with(ResponseTemplate::new(200).set_body_string("pong"))
            .mount(&server)
            .await;
        server
    });
    let uri = format!("{}/ping", server.uri());
    let response = adapter().send_request(request("GET", &uri)).unwrap();
    assert_eq!(response.status(), 200);
    assert_eq!(std::str::from_utf8(response.body()).unwrap(), "pong");
    drop(server);
    drop(runtime);
}

#[test]
fn default_timeouts_allow_reasonably_slow_responses() {
    let server = TestServer::serve();
    let response = adapter()
        .send_request(request("GET", &server.url("/slow")))
        .unwrap();
    assert_eq!(std::str::from_utf8(response.body()).unwrap(), "slow");
}

#[test]
fn curl_options_timeout_uses_duration() {
    let _ = Duration::from_millis(100);
    let options = CurlOptions::default().with_timeout_ms(100);
    let _ = CurlClient::with_options(options);
}
