//! PHP `tests/Client/ExceptionTest.php`.

use utopia_client::{
    AdapterInitializationException, AdapterPreconditionException, ConnectionException,
    DnsException, Error, ErrorKind, InvalidResponseException, InvalidUriException,
    NetworkException, ProtocolException, ProxyException, RequestException, TimeoutException,
    TlsException,
};

fn dummy_request() -> http::Request<bytes::Bytes> {
    http::Request::builder()
        .uri("https://example.com")
        .body(bytes::Bytes::new())
        .unwrap()
}

#[test]
fn request_exceptions_remain_psr_request_exceptions() {
    let request = dummy_request();
    for error in [
        Error::request(request.clone(), "x", 0),
        Error::adapter_initialization(request.clone(), "x", 0),
        Error::adapter_precondition(request.clone(), "x"),
        Error::invalid_response(request.clone(), "x"),
        Error::invalid_uri(request, "x"),
    ] {
        assert!(error.is_request_exception(), "{error:?}");
        assert!(!error.is_network());
    }
    assert_eq!(
        Error::adapter_initialization(dummy_request(), "x", 0).kind(),
        ErrorKind::AdapterInitialization
    );
    let _: RequestException = Error::request(dummy_request(), "x", 0);
    let _: AdapterInitializationException = Error::adapter_initialization(dummy_request(), "x", 0);
    let _: AdapterPreconditionException = Error::adapter_precondition(dummy_request(), "x");
    let _: InvalidResponseException = Error::invalid_response(dummy_request(), "x");
    let _: InvalidUriException = Error::invalid_uri(dummy_request(), "x");
}

#[test]
fn network_exceptions_remain_psr_network_exceptions() {
    let request = dummy_request();
    for error in [
        Error::connection(request.clone(), "x", 0),
        Error::dns(request.clone(), "x", 0),
        Error::network(request.clone(), "x", 0),
        Error::protocol(request.clone(), "x", 0),
        Error::proxy(request.clone(), "x", 0),
        Error::tls(request.clone(), "x", 0),
        Error::timeout(request, "x", 0),
    ] {
        assert!(error.is_network(), "{error:?}");
        assert!(!error.is_request_exception());
    }
    let _: ConnectionException = Error::connection(dummy_request(), "x", 0);
    let _: DnsException = Error::dns(dummy_request(), "x", 0);
    let _: NetworkException = Error::network(dummy_request(), "x", 0);
    let _: ProtocolException = Error::protocol(dummy_request(), "x", 0);
    let _: ProxyException = Error::proxy(dummy_request(), "x", 0);
    let _: TlsException = Error::tls(dummy_request(), "x", 0);
    let _: TimeoutException = Error::timeout(dummy_request(), "x", 0);
}
