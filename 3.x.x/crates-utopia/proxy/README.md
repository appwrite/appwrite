# utopia-proxy

TCP / HTTP / SMTP proxy for Utopia. Rust port of [utopia-php/proxy](https://github.com/utopia-php/proxy) (`c0a2f34e0832`).

Swoole servers become Tokio listeners. Routing still goes through a `Resolver` plus SSRF validation (`utopia-validators` IP/Range, `utopia-console` startup logs). BPF sockmap load is a documented no-op because this workspace forbids `unsafe`.

## Install

```toml
utopia-proxy = { path = "../utopia-proxy" }
```

## Usage

```rust
use std::sync::Arc;
use utopia_proxy::prelude::*;

let resolver = Arc::new(Fixed::new("127.0.0.1:9000"));
let config = TcpConfig::new(vec![0]); // 0 = ephemeral port in tests
let server = TcpServer::new(resolver, config);
// server.start().await
```

HTTP and SMTP follow the same pattern with `HttpServer` / `SmtpServer`.

## API Reference

### `Protocol`

28 variants matching PHP (`Http`, `Smtp`, `Tcp`, `PostgreSQL`, `MySQL`, …). `from_port` / `as_str` / `FromStr`.

### `Adapter` / `TcpAdapter`

| Method | Description |
|--------|-------------|
| `route(data)` | Resolve → SSRF-validate → `ConnectionResult`. |
| `set_skip_validation` / `set_cache_ttl` / `set_on_resolve` | PHP `setSkipValidation`, `setCacheTTL`, `onResolve`. |
| `parse_endpoint(endpoint, default_port)` | `"host:port"` split. |
| `TcpAdapter::get_connection` | Dial backend, optional sockmap pair. |

SSRF: `Range(1, 65535)` on ports, `IP` for literals, blocked private/reserved ranges as in PHP.

### `Resolver` / `Fixed` / `Dns`

`Resolver::resolve` returns `ResolverResult { endpoint, metadata, timeout }`. `Fixed` always returns one endpoint. `dns::resolve` matches PHP `gethostbyname` (returns the input host on failure).

### Servers

| Type | PHP | Notes |
|------|-----|--------|
| `TcpServer` + `TcpConfig` | `Server\TCP\Swoole` | Multi-port, TLS, optional sockmap hook |
| `HttpServer` + `HttpConfig` | `Server\HTTP\Swoole` | hyper 1, Host validation via `Hostname` |
| `SmtpServer` + `SmtpConfig` | `Server\SMTP\Swoole` | EHLO routing |

Workers default to `available_parallelism()` (PHP `swoole_cpu_num()`).

### TLS

`Tls` + `TlsContext` - certificate paths validated with `Text(4096)` then filesystem checks. PostgreSQL SSLRequest / MySQL CLIENT_SSL detection.

## Intentional deviations

- Swoole coroutines → Tokio tasks.
- BPF sockmap `load()` always returns false (no `unsafe` syscalls). Tuple packing remains for tests.
- Linux-only `setsockopt` knobs (TCP_FASTOPEN, TCP_DEFER_ACCEPT, TCP_USER_TIMEOUT, TCP_QUICKACK, TCP_NOTSENT_LOWAT) are skipped; keepalive / nodelay / buffer sizes use `socket2`.

## Tests

```bash
cargo test -p utopia-proxy
```

Ports PHP unit tests plus localhost integration on ephemeral ports.

## Benchmarks

```bash
cargo bench -p utopia-proxy
```
