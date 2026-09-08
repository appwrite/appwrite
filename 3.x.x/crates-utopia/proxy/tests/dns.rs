//! Ports `tests/Unit/DnsTest.php`.

use std::net::IpAddr;
use std::str::FromStr;
use std::time::Duration;

use utopia_proxy::dns;

#[tokio::test]
async fn literal_ipv4_passes_through() {
    let out = dns::resolve("8.8.8.8", Duration::from_secs(1)).await;
    assert_eq!(out, "8.8.8.8");
}

#[tokio::test]
async fn literal_ipv6_passes_through() {
    let out = dns::resolve("2001:4860:4860::8888", Duration::from_secs(1)).await;
    assert_eq!(out, "2001:4860:4860::8888");
}

#[tokio::test]
async fn empty_string_passes_through() {
    let out = dns::resolve("", Duration::from_secs(1)).await;
    assert_eq!(out, "");
}

#[tokio::test]
async fn unresolvable_hostname_returns_input() {
    let host = "this-hostname-definitely-does-not-exist-12345.invalid";
    let out = dns::resolve(host, Duration::from_millis(500)).await;
    // Mirrors PHP: returns the input unchanged on resolver failure.
    assert_eq!(out, host);
}

#[test]
fn set_ttl_is_observable() {
    let original = dns::ttl();
    dns::set_ttl(120);
    assert_eq!(dns::ttl(), 120);
    dns::set_ttl(original);
}

#[tokio::test]
async fn clear_does_not_panic() {
    dns::clear().await;
}

#[tokio::test]
async fn ipv6_literal_passes_through() {
    assert_eq!(dns::resolve("::1", Duration::from_secs(1)).await, "::1");
    assert_eq!(
        dns::resolve("fe80::1", Duration::from_secs(1)).await,
        "fe80::1"
    );
}

#[tokio::test]
async fn set_ttl_to_zero_is_observable() {
    let original = dns::ttl();
    dns::set_ttl(0);
    assert_eq!(dns::ttl(), 0);
    dns::set_ttl(original);
}

#[tokio::test]
async fn resolvable_hostname_returns_ip_or_input() {
    // Network-dependent. We accept either a valid IP (success) or the
    // original hostname back (no network / DNS down) - mirrors PHP's
    // "returns input on failure" contract without requiring the CI
    // network to be present.
    let out = dns::resolve("google.com", Duration::from_secs(2)).await;
    if out != "google.com" {
        assert!(IpAddr::from_str(&out).is_ok(), "expected IP, got {out}");
    }
}
