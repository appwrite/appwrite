# utopia-nats

NATS client for Utopia. Rust port of [utopia-php/nats](https://github.com/utopia-php/monorepo/tree/main/packages/nats) (`packages/nats` SHA `bde69f72e43f`).

Speaks the NATS core protocol (CONNECT/PUB/HPUB/SUB/UNSUB/PING/PONG, MSG/HMSG/INFO), JetStream, Key-Value, Object Store, and microservices. Unit tests drive an in-memory [`FakeTransport`](src/transport.rs) so they do not need a broker. Live E2E always connects to the compose NATS container (`NATS_URL`, default `nats://127.0.0.1:4222`).

Swoole TCP is Tokio/std TCP; WebSocket uses `tokio-tungstenite` when talking to a live `ws://` / `wss://` server.

## Install

```toml
utopia-nats = { path = "../utopia-nats" }
```

## Usage

```rust
use utopia_nats::prelude::*;
use serde_json::json;

let fake = FakeTransport::new(json!({"headers": true}));
let factory_fake = fake.clone();
let mut opts = ConnectionOptions::default();
opts.transport_factory = Some(std::sync::Arc::new(move |_| {
    factory_fake.clone() as std::sync::Arc<dyn Transport>
}));
let conn = Connection::connect(opts).unwrap();
conn.publish("greet", b"hello", None, None).unwrap();
conn.close();
```

Against a live server, omit `transport_factory` and set `opts.servers`.

## API Reference

### `Connection` / `ConnectionOptions`

| Method | Description |
|--------|-------------|
| `connect(options)` | PHP `Connection::connect`. Handshake: INFO, CONNECT, PING/PONG. |
| `publish(subject, data, reply_to, headers)` | PUB or HPUB. Rejects headers when the server lacks support; max-payload includes header bytes. |
| `subscribe` / `queue_subscribe` / `unsubscribe` | Core subscriptions. |
| `request` / `request_many` | Inbox request/reply. 503 no-responders → `NatsException`. |
| `process_message` | Pull one server op (used by sync consumers and tests). |
| `flush` / `drain` / `close` | Barriers and shutdown. |
| `jetstream(domain, api_prefix)` | JetStream client. |
| `get_server_info` / `get_status` / `is_connected` / `new_inbox` | Connection state. |
| `tls_options` | PHP stream SSL option map (`cafile`, `local_cert`, `verify_peer`, `peer_name`, …). |
| `map_server_error` | ADR-7 mapping: permissions → `PermissionException`, auth → `AuthenticationException`, payload → `MaxPayloadException`, else `ProtocolException`. |
| `reconnect_backoff` / `reconnect_buffer_accepts` | Exponential reconnect wait and buffer cap. |

Status strings: `disconnected`, `connecting`, `connected`, `reconnecting`, `draining`, `closed`.

### Protocol

| Type | Description |
|------|-------------|
| `Parser` | Incremental INFO/MSG/HMSG/PING/PONG/+OK/-ERR reader. |
| `Writer` | CONNECT/PUB/HPUB/SUB/UNSUB/PING/PONG encoder. CONNECT JSON preserves key order. |
| `ServerOp` / `ServerEvent` / `MsgData` | Parsed server operations. |

### Auth

| Type | CONNECT fields |
|------|----------------|
| `NoAuth` | (none) |
| `TokenAuth` | `auth_token` |
| `UserPassAuth` | `user`, `pass` |
| `NKeyAuth` | `nkey`, `sig` (ed25519 over server nonce; public key derived from seed) |
| `CredentialsAuth` | JWT + NKey from a `.creds` file |
| `token_provider` / `jwt_provider` | Resolved at connect time |

### Transports

| Type | PHP |
|------|-----|
| `TcpTransport` | `TcpTransport` - loops until every byte is written |
| `TlsTransport` | `TlsTransport` - `build_ssl_options()` |
| `WebSocketTransport` | `WebSocketTransport` |
| `FakeTransport` | unit-test double |

### Headers, Inbox, Message, Subscription

Fluent `Headers` (`set`/`add`/`get`/`to_wire`/`from_wire`, status 503). `Inbox::create()` → `_INBOX.` + 22-char id. Subscriptions bound pending msgs/bytes and fire `on_slow_consumer`; callback subs never queue.

### JetStream / KV / ObjectStore / Services

`JetStream` API requests over `Connection::request`. `ConsumerConfig`/`ConsumerInfo`/`StreamMessage` match PHP `toArray`/`fromArray` (nanos for durations, base64 payloads). `KeyValue` and `ObjectStore` wrap JetStream buckets. `Service` registers endpoints and groups.

## Intentional deviations

- Client `lang` in CONNECT is `"rust"` (PHP sends `"php"`).
- Swoole coroutines → blocking std/`TcpStream` for the PHP-shaped sync API; Tokio is used where a runtime is already present (TLS/WebSocket).
- BPF-free; no PHP stream wrappers. Partial writes are covered by `write_fully`.
- Live broker tests always hit the compose NATS container (`NATS_URL`, default `nats://127.0.0.1:4222`).

## Tests

```bash
cargo test -p utopia-nats
```

Ports `packages/nats/tests/Unit` (protocol, headers, inbox, reconnect, NKey, JetStream config, connection protocol/TLS/auth, slow consumer, TCP write). E2E in `tests/e2e.rs`.

## Benchmarks

```bash
cargo bench -p utopia-nats
```
