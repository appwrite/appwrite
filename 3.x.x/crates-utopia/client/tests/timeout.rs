//! PHP `tests/Client/Adapter/TimeoutTest.php`.

use utopia_client::adapter::curl::Client as CurlClient;
use utopia_client::adapter::swoole_coroutine::Client as SwooleClient;
use utopia_client::Adapter;

#[test]
fn curl_timeouts_reject_invalid_values() {
    let adapter = CurlClient::new();
    let error = adapter.with_timeout(f64::INFINITY).unwrap_err();
    assert!(error.is_value_error());
}

#[test]
fn swoole_timeouts_reject_invalid_values() {
    let adapter = SwooleClient::new();
    let error = adapter.with_connect_timeout(-0.001).unwrap_err();
    assert!(error.is_value_error());
}
