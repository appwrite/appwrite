# utopia-cdn

CDN cache purge and TLS certificate providers for Utopia. Rust port of [utopia-php/cdn](https://github.com/utopia-php/cdn) (`f933936a2bf4`, 2026-08-14).

[`Cache`](#cache) fronts interchangeable adapters (Cloudflare, Fastly, Balancer). [`Certificates`](#certificates) fronts Cloudflare custom hostnames, Fastly TLS subscriptions, and a routing [`Proxy`](#proxy).

## Install

```toml
utopia-cdn = { path = "../utopia-cdn" } # workspace
```

## Usage

```rust
use utopia_cdn::{Cache, CloudflareCache};

let cache = Cache::new(CloudflareCache::new("zone-id", "api-token"));
cache.purge_domain("example.com")?;
cache.purge_paths("example.com", &["/index.html".into()])?;
cache.purge_keys(&["host-deadbeef".into()])?;
```

```rust
use utopia_cdn::{Cache, FastlyCache};

let cache = Cache::new(
    FastlyCache::new("api-token", "domain-")
        .with_service_id("service-id")
        .with_soft_purge(false),
);
cache.purge_domain("example.com")?; // surrogate key `domain-example.com`
```

HTTP uses [`utopia-client`](../utopia-client) (cURL/reqwest adapter), matching PHP `Utopia\Client`. Tests inject [`HttpClient`](#httpclient) or point `with_api_base` at wiremock.

## API Reference

### `Cache`

PHP `Utopia\Cdn\Cache`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(adapter: impl Adapter + 'static) -> Self` | PHP `__construct(Adapter $adapter)`. |
| `purge_paths` | `fn purge_paths(&self, domain: &str, paths: &[String]) -> Result<(), CdnError>` | Purge `https://{domain}{path}` URLs. |
| `purge_domain` | `fn purge_domain(&self, domain: &str) -> Result<(), CdnError>` | Purge one hostname. |
| `purge_keys` | `fn purge_keys(&self, keys: &[String]) -> Result<(), CdnError>` | Cloudflare cache tags / Fastly surrogate keys. |
| `purge_zone` | `fn purge_zone(&self) -> Result<(), CdnError>` | Purge everything in the zone/service. |

### `Adapter`

PHP `Utopia\Cdn\Cache\Adapter`. Same four methods as `Cache`. Every adapter implements all four, or raises `UnsupportedOperation`.

### `CloudflareCache`

PHP `Utopia\Cdn\Cache\Adapter\Cloudflare`.

| Item | Description |
|------|-------------|
| `PATHS_PER_PURGE` | `30` |
| `KEYS_PER_PURGE` | `30` |
| `new(zone_id, api_token)` | Default API base `https://api.cloudflare.com/client/v4`. |
| `with_client(Arc<dyn HttpClient>)` | Inject HTTP (PHP `ClientInterface`). |
| `with_api_base(url)` | Override API origin (wiremock / enterprise). |
| `purge_paths` | POST `{files: [https://{domain}{path}, ...]}` in batches of 30. |
| `purge_domain` | POST `{hosts: [domain]}`. |
| `purge_keys` | POST `{tags: [...]}` in batches of 30. |
| `purge_zone` | POST `{purge_everything: true}`. |

A 2xx is not enough: `success: true` must be in the JSON body. Failures: `Cloudflare purge failed with status {code}: {message}`.

### `FastlyCache`

PHP `Utopia\Cdn\Cache\Adapter\Fastly`.

| Item | Description |
|------|-------------|
| `KEYS_PER_PURGE` | `256` |
| `new(api_token, domain_key_prefix)` | Prefix of the per-domain surrogate key; `""` uses the bare hostname. |
| `with_service_id(id)` | Required for key / domain / zone purges. |
| `with_soft_purge(bool)` | Sends `Fastly-Soft-Purge: 1`. |
| `with_client` / `with_api_base` | Same as Cloudflare. |
| `purge_paths` | One `POST /purge/{domain}{encoded_path}` per path (no batch). |
| `purge_domain` | Surrogate-key purge of `{prefix}{domain}`. |
| `purge_keys` | POST `{surrogate_keys: [...]}` in batches of 256. Keys are sent unencoded. |
| `purge_zone` | `POST /service/{id}/purge_all`. |

Path encoding keeps `A-Za-z0-9-._~/%?=&:+` and percent-encodes everything else (PHP `rawurlencode` on the complement). Missing service ID → `UnsupportedOperation` (`Fastly service ID is required for {operation}.`). Path purge works without a service ID.

### `Balancer` / `CdnOption` / `OptionBalancer`

PHP `Cache\Adapter\Balancer` plus `Extend\CdnOption`. `utopia-php/balancer` is **not** a workspace crate; [`OptionBalancer`](#optionbalancer) is an in-crate subset (`add_option`, `add_filter`, `get_filtered_options`, `run` with the `First` algorithm).

Failures fan out: every matching option is attempted, then collected errors are raised as [`Purge`](#errors) (`get_errors()`). `UnsupportedOperation` on one option is skipped. All-unsupported → `UnsupportedOperation`. Empty filter match → `Configuration`. Untyped options → `Configuration` (`must be instances of Utopia\Cdn\Extend\CdnOption.`).

| `CdnOption` | Description |
|-------------|-------------|
| `ADAPTER` / `PROVIDER` / `EDGE` | State keys. |
| `PROVIDER_FASTLY` / `PROVIDER_CLOUDFLARE` | `"fastly"` / `"cloudflare"`. |
| `new(adapter, provider, edge)` | Typed option. |
| `get_adapter` / `get_provider` / `is_edge` | Typed getters. |
| `set_state` | PHP `Option::setState` (tests overwrite typed state). |

### `Certificates` / `Provider`

PHP `Utopia\Cdn\Certificates` / `Certificates\Provider`.

| Method | Description |
|--------|-------------|
| `issue_certificate(cert_name, domain, domain_type)` | Returns a renew date (`Some`) or `None`. |
| `is_instant_generation` | Cloudflare: `true`. Fastly TLS: `false`. |
| `get_certificate_status` | Maps onto [`Status`](#status). Cloudflare: `UnsupportedOperation`. |
| `is_renew_required` | Cloudflare: hostname missing. Fastly: missing or `failed`. |
| `delete_certificate` | No-op when nothing is registered. |

### `CloudflareCertificates`

PHP `Certificates\Provider\Cloudflare`. Cloudflare for SaaS custom hostnames (`POST /zones/{id}/custom_hostnames`). Duplicate hostname (error code `1406`) is idempotent (`Ok(None)`). Create expects HTTP 201.

### `FastlyTls`

PHP `Certificates\Provider\FastlyTls`. JSON:API TLS subscriptions. `issue_certificate` creates or retries a `failed` subscription. Renew date is the latest included certificate `not_after` minus 30 days, formatted `Y-m-d H:i:s.v` (`2027-01-02 00:00:00.000`).

### `Proxy`

PHP `Certificates\Provider\Proxy`. `site` / `network` / `redirect` → network provider; application hostname → app provider; everything else → every custom-domain provider (error if that list is empty). `issue_certificate` keeps the last non-`None` renew date. `get_certificate_status` skips instant providers and returns the first non-`issued` status, else `issued`.

### `Status`

| Constant | Value |
|----------|-------|
| `PENDING` | `"pending"` |
| `PROCESSING` | `"processing"` |
| `ISSUED` | `"issued"` |
| `RENEWING` | `"renewing"` |
| `FAILED` | `"failed"` |
| `UNKNOWN` | `"unknown"` |

### `Domain`

| Method | Description |
|--------|-------------|
| `validate` | Lowercase hostname, `FILTER_VALIDATE_DOMAIN` + `FILTER_FLAG_HOSTNAME`. |
| `validate_paths` | Every path must start with `/`. |

### `HttpClient`

Thin trait over [`utopia_client::Client::send_request`](../utopia-client). Default: `default_client()` = `Client::new(curl::Client::new())`. Adapters accept `Arc<dyn HttpClient>` for tests.

### Errors

| Type | PHP | Notes |
|------|-----|-------|
| `CdnError::InvalidArgument` | `InvalidArgumentException` | Domain / path validation. |
| `Configuration` | `Exception\Configuration` | Balancer / proxy config. |
| `UnsupportedOperation` | `Exception\UnsupportedOperation` | Missing Fastly service ID; Cloudflare cert status. |
| `Purge` | `Exception\Purge` | Aggregated balancer failures; `get_errors()`. |
| `CdnError::Runtime` | `RuntimeException` | Provider HTTP / JSON errors. |

## Deviations

- HTTP is [`utopia-client`](../utopia-client) behind [`HttpClient`](#httpclient), not PSR-18 types. Default transport is the cURL adapter (reqwest blocking).
- `utopia-php/balancer` is on the exclusion list. `OptionBalancer` is a local `First`-only subset; there is no `Algorithm` trait.
- Thrown PHP exceptions are `Result<_, CdnError>`.
- `CdnOption` stores typed state rather than an untyped PHP array; `set_state` exists for the PHP overwrite test.
- Cloudflare/Fastly e2e (`tests/e2e.rs`) uses [utopia-test-wiremock](../utopia-test-wiremock) (compose/CI WireMock). PHP talks to live APIs.

## Tests

```bash
cargo test -p utopia-cdn
```

Ports every PHPUnit case in `tests/Cdn/` (facade, adapter contract, Cloudflare/Fastly cache, balancer, CdnOption, certificates, Fastly TLS, Cloudflare certs, proxy) plus extra error paths. Provider e2e hits WireMock via utopia-test-wiremock, not live Cloudflare/Fastly.

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/cdn/Cargo.toml
```

PHP twin: `benchmarks/cdn/`. Metrics: `cdn_validate`, `cdn_purge_domain`, `cdn_balancer_purge_keys`.
