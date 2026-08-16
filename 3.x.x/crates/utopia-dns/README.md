# utopia-dns

DNS message codec, zone files, resolvers, server, and client for Utopia. Rust port of [utopia-php/dns](https://github.com/utopia-php/dns) (PHP SHA [`c3ae00025014`](https://github.com/utopia-php/monorepo/commit/c3ae00025014)).

Wire encode/decode is a 1:1 port of the PHP codec (no Hickory/trust-dns). Unit tests assert exact bytes where PHP does.

Public paths follow PHP `Utopia\DNS\`: `Client`, `Message`, `Server`, `Zone` at the crate root; transports under `adapter::native` / `adapter::swoole` (`Udp`, `Tcp`, `Transport`); validators under `validator` (`CAA`, `DNS`, `Name`); exceptions under `exception::message` / `exception::zone`.

## Install

```toml
utopia-dns = { path = "../utopia-dns" }
```

## Usage

```rust
use utopia_dns::prelude::*;

let question = Question::new("www.example.com", Record::TYPE_A);
let query = Message::query(question, Some(0x1234), true).unwrap();
let packet = query.encode(None).unwrap();
let decoded = Message::decode(&packet).unwrap();
assert_eq!(decoded.questions[0].name, "www.example.com");
```

Authoritative server (Tokio UDP + TCP):

```rust
use utopia_dns::adapter::native::{Native, Tcp, Transport, Udp};
use utopia_dns::prelude::*;

let soa = Record::new("example.com", Record::TYPE_SOA)
    .ttl(3600)
    .rdata("ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300");
let zone = Zone::new(
    "example.com",
    vec![Record::new("www.example.com", Record::TYPE_A).ttl(300).rdata("192.0.2.10")],
    soa,
)
.unwrap();
let adapter = Native::new(vec![
    Transport::Udp(Udp::new("127.0.0.1", 5353)),
    Transport::Tcp(Tcp::new("127.0.0.1", 5353)),
])
.unwrap();
let server = Server::new(adapter, Memory::new(zone));
server.start().unwrap(); // blocking
```

## API Reference

### Constants (`Message`)

| Const | Value | PHP |
|-------|-------|-----|
| `MAX_SIZE` | `65535` | `Message::MAX_SIZE` |
| `MAX_UDP_SIZE` | `512` | `Message::MAX_UDP_SIZE` |
| `RCODE_NOERROR` … `RCODE_NOTZONE` | `0`–`10` | same names |

### `Record` types / classes

| Const | Value |
|-------|-------|
| `TYPE_A` | `1` |
| `TYPE_NS` | `2` |
| `TYPE_CNAME` | `5` |
| `TYPE_SOA` | `6` |
| `TYPE_PTR` | `12` |
| `TYPE_MX` | `15` |
| `TYPE_TXT` | `16` |
| `TYPE_AAAA` | `28` |
| `TYPE_SRV` | `33` |
| `TYPE_CAA` | `257` |
| `CLASS_IN` | `1` |
| `CLASS_CS` | `2` |
| `CLASS_CH` | `3` |
| `CLASS_HS` | `4` |

PHP public field `$type` is `type_code` in Rust (`type` is a keyword).

### `Header`

PHP `Utopia\DNS\Message\Header`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | 12 args matching PHP `__construct` | Opcode and RCODE must be 0–15. |
| `decode` | `fn decode(data: &[u8], offset: usize) -> Result<Header>` | 12-byte header. Z bits ignored. |
| `encode` | `fn encode(&self) -> Vec<u8>` | Network byte order. |
| `LENGTH` | `12` | Header size. |

### `Domain`

| Method / const | Description |
|----------------|-------------|
| `encode(name)` | RFC 1035 labels. Root is `""` or `"."`. |
| `decode(data, &mut offset)` | Labels + `0xC0` pointers (backward only, loop detection). |
| `MAX_LABEL_LEN` | `63` |
| `MAX_LABELS` | `127` |
| `MAX_DOMAIN_NAME_LEN` | `255` |

Encode does **not** emit compression pointers (PHP `Domain::encode` does not).

### `Question` / `Record` / `Message`

| Type | Notes |
|------|-------|
| `Question::new` / `with_class` | Name trimmed + lowercased. |
| `Record::new(name, type_code)` | Fluent `.class().ttl().rdata().priority().weight().port()`. |
| `Record::with_name` | Clone with a new owner name. |
| `Record::validate_rdata` | A/AAAA must be IPs; NS/CNAME/PTR are names. |
| `Message::query(question, id, recursion_desired)` | PHP default `id = null` (random), `recursionDesired = true`. |
| `Message::response(...)` | Builds a response header; NXDOMAIN/NODATA require SOA when AA and not TC. |
| `Message::decode` / `encode(max_size)` | Truncation follows RFC 1035/2181 (PHP). |
| `Message::validate` | Validates rdata of answer/authority/additional. |

### Exceptions (`Error`)

| Variant | PHP |
|---------|-----|
| `Decoding(String)` | `DecodingException` |
| `PartialDecoding { header, message }` | `PartialDecodingException` (`header()`) |
| `InvalidArgument(String)` | `\InvalidArgumentException` |
| `Import { content, message }` | `ImportException` (`content()`) |
| `Other(String)` | `\Exception` (client, PROXY, adapters) |

Type aliases: `DecodingException`, `PartialDecodingException`, `ImportException`.

### `Protocol`

PHP enum cases `Udp` / `Tcp` / `Https`.

| Method | UDP | TCP / HTTPS |
|--------|-----|-------------|
| `max_response_size()` | `512` | `65535` |
| `as_str()` | `"udp"` | `"tcp"` / `"https"` |

### `ProxyProtocol`

`parse(buffer) -> Result<Option<ProxyProtocol>>`. `Ok(None)` means incomplete. v1 text and v2 binary. `SIGNATURE_V2` matches PHP.

### `Zone` / `File` / `zone::Resolver`

| API | Description |
|-----|-------------|
| `Zone::new(name, records, soa)` | SOA is separate from `records`. |
| `Zone::is_authoritative(name)` | False when NS exists at that name (except apex). |
| `File::import(content, default_origin, default_ttl)` | PHP default TTL `3600`. |
| `File::export(zone, include_comments)` | RFC 1035 master file. |
| `zone::Resolver::lookup(query, zone)` | Exact / CNAME / NODATA / NXDOMAIN / wildcard / referral. |

### Resolvers

| Type | PHP | Notes |
|------|-----|-------|
| `Memory` | `Resolver\Memory` | In-memory zone lookup. |
| `Proxy` | `Resolver\Proxy` | UDP `Client` to another server. |
| `Cloudflare` | `Resolver\Cloudflare` | UDP proxy to `1.1.1.1` / `1.0.0.1` (not DoH). `with_nameserver` is Rust-only for tests. |
| `Google` | `Resolver\Google` | UDP proxy to `8.8.8.8` / `8.8.4.4` (not DoH). `with_nameserver` is Rust-only for tests. |

### Adapters / `Server` / `Client`

| Type | Description |
|------|-------------|
| `Native` | Tokio UDP + TCP. PHP sockets adapter. `start()` blocking; `start_async()` for tests. |
| `native::Udp` / `native::Tcp` | Public names match PHP. |
| `Swoole` | Tokio equivalent of PHP Swoole (UDP/TCP + Hyper DoH HTTP). |
| `swoole::Udp` / `Tcp` / `Http` | HTTP is RFC 8484 GET `?dns=` / POST `application/dns-message`. TLS cert/key stored but not used. |
| `Server::set_telemetry` | Histogram `dns.query.duration` (s, buckets 0.001…1), counters `dns.queries.total`, `dns.responses.total`. |
| `Server::error` / `on_worker_start` / `set_debug` | Match PHP. |
| `Client::new(server, port, timeout_secs, use_tcp)` | Server must be an IP (`"Server must be an IP address."`). |

Port `0` on UDP binds an ephemeral port; a following TCP with port `0` reuses that port (PHP same-port UDP+TCP).

### Validators (`utopia_validators::Validator`)

| Type | PHP | Value |
|------|-----|-------|
| `CAA` | `Validator\CAA` | `"<flags> <tag> \"<value>\""` |
| `Name` | `Validator\Name` | Owner names; underscores allowed except A/AAAA. |
| `DNS` | `Validator\DNS` | Live lookup via `Client` (needs a reachable resolver). |

## Intentional deviations

- **Swoole runtime → Tokio.** Public type names (`Swoole`, `swoole::Udp`/`Tcp`/`Http`) are kept. HTTP DoH uses Hyper; TLS paths are accepted but not applied (no rustls terminator).
- **`Record.type` → `type_code`.** Rust keyword.
- **Cloudflare / Google are UDP proxies**, matching PHP - not DNS-over-HTTPS. `with_nameserver` points tests at an in-process `Native`+`Memory` server instead of public UDP 53.
- **`File::import` always takes `default_ttl`** (pass `3600` for the PHP default).
- **`Message::query` / `response` have no default arguments**; pass `id: None` and the PHP flag defaults explicitly.
- **In-process tests** spin up `Native`+`Memory` on an ephemeral port instead of Docker `127.0.0.1:5300`.

## Tests

```bash
cargo test -p utopia-dns
```

Ports PHP `tests/unit` (Domain, Header, Question, Record, Message, ProxyProtocol, Zone, File, Resolver) plus in-process client/HTTP/PROXY e2e. Cloudflare/Google resolver suites hit a local UDP zone (not 8.8.8.8 / 1.1.1.1).

## Benchmarks

```bash
cargo bench --bench dns --manifest-path crates/utopia-dns/Cargo.toml
```

PHP twin: `benchmarks/dns/bench.php` (Message encode/decode + Memory resolve via Utopia PHP).
