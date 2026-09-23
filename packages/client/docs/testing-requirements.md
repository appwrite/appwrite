# Testing Requirements

These requirements translate the vendored FIG specification and production-readiness concerns into local test coverage.

Source specs:

- `docs/psr/PSR-18-http-client.md`

## PSR-18 client coverage

- Clients return 4xx/5xx responses without throwing.
- Invalid requests throw `RequestExceptionInterface`.
- Network failures, including timeouts, throw `NetworkExceptionInterface`.
- Clients collapse interim 1xx response headers and expose only the final response.

## Timeout coverage

- `Utopia\Client` timeout helpers are immutable.
- cURL adapter maps timeout seconds to `CURLOPT_TIMEOUT_MS`.
- cURL adapter maps connect timeout seconds to `CURLOPT_CONNECTTIMEOUT_MS`.
- Swoole adapter maps timeout seconds to `timeout`.
- Swoole adapter maps connect timeout seconds to `connect_timeout`.
- Invalid timeout values throw `ValueError`.

## Redirect coverage

- Redirects are not followed by default; a 3xx response is returned with its `Location` header.
- `withFollowRedirects()` follows `Location` to the final non-redirect response on both adapters.
- Relative `Location` values are resolved with RFC 3986, including `.` / `..`.
- Same-origin redirects keep `Authorization`; cross-origin and HTTPS to HTTP hops strip sensitive headers.
- Streaming after a redirect delivers the final body to the sink without buffering it first.
- Fifty hops succeed; the fifty-first throws `ProtocolException`.
- `Utopia\Client::withFollowRedirects()` forwards onto the adapter.
