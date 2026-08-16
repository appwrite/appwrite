# utopia-websocket

WebSocket client and server for Utopia. Rust port of [utopia-php/websocket](https://github.com/utopia-php/websocket).

## Runtime deviation

PHP adapters wrap **Swoole** and **Workerman**. This crate exposes the same types (`Swoole`, `Workerman`) and method names, backed by a **Tokio** TCP/WebSocket implementation (`TokioAdapter`). There are no OS worker processes: `on_worker_start` / `on_worker_stop` fire once per configured `worker_num` on the Tokio runtime. `set_compression_enabled` is stored (Swoole config flag) but permessage-deflate is not applied. `wss://` URLs select port 443 by default; TLS is not implemented - use `ws://`.

## Install

```toml
utopia-websocket = { path = "../utopia-websocket" }
```

## Usage

```rust
use utopia_websocket::prelude::*;

let mut adapter = Swoole::new("127.0.0.1", 0);
adapter.set_package_max_length(64_000);
adapter.set_worker_number(1);

let send = adapter.clone();
let mut server = Server::new(adapter);
server.on_start(Box::new(|| println!("Server started!")));
server.on_message(Box::new(move |connection, message| {
    let _ = send.send(&[connection], &message);
}));
```

## API Reference

### `Adapter` trait - PHP `Utopia\WebSocket\Adapter`

| Method | Signature | Description |
|--------|-----------|-------------|
| `start` / `shutdown` | `fn start(&mut self) -> Result<(), WebsocketError>` | Bind and serve; request stop. |
| `send` | `fn send(&self, connections: &[i64], message: &str)` | Text frame to connection ids. |
| `close` | `fn close(&self, connection: i64, code: i32)` | Close a connection. |
| `on_start` / `on_worker_start` / `on_worker_stop` | callbacks | Lifecycle hooks. |
| `on_open` / `on_message` / `on_close` / `on_request` | callbacks | Connection and HTTP hooks. |
| `set_package_max_length` | `fn set_package_max_length(&mut self, bytes: i32)` | Max frame payload. |
| `set_compression_enabled` | `fn set_compression_enabled(&mut self, enabled: bool)` | Stored; compression not applied. |
| `set_worker_number` | `fn set_worker_number(&mut self, num: i32)` | Worker-callback count (not OS processes). |
| `get_native` | `fn get_native(&self) -> NativeHandle` | Host/port (PHP returns the Swoole/Workerman server object). |
| `get_connections` | `fn get_connections(&self) -> Vec<i64>` | Active connection ids. |

`Swoole` and `Workerman` are type aliases of `TokioAdapter`.

### `Server<A: Adapter>` - PHP `Utopia\WebSocket\Server`

Forwards adapter methods and swallows errors into `error()` callbacks (`$error, $operation`).

### `Client` - PHP `Utopia\WebSocket\Client`

| Method | Signature | Description |
|--------|-----------|-------------|
| `from_url` / `new` | `fn new(url, headers, timeout_secs) -> Result<Self, WebsocketError>` | Parse `ws://` / `wss://`. |
| `connect` | `fn connect(&mut self) -> Result<(), WebsocketError>` | HTTP upgrade handshake. |
| `listen` | `fn listen(&mut self)` | Read loop until disconnect. |
| `send` / `receive` | send text / read one frame | Fail with `Not connected to WebSocket server` when disconnected. |
| `close` / `is_connected` | | |
| `on_message` / `on_close` / `on_error` / `on_open` / `on_ping` / `on_pong` | fluent | Event handlers. |

### Errors

| Variant | PHP message |
|---------|-------------|
| `InvalidUrl` | `Invalid WebSocket URL` |
| `MissingHost` | `WebSocket URL must contain a host` |
| `NotConnected` | `Not connected to WebSocket server` |
| `ConnectFailed` | `WebSocket connection failed: {code} - {message}` |
| `SendFailed` | `Failed to send data: {code} - {message}` |
| `ReceiveFailed` | `Failed to receive data: {code} - {message}` |

## Tests

```bash
cargo test --manifest-path crates/utopia-websocket/Cargo.toml
```

Ports `tests/unit/ClientTest.php`. Adapter echo/broadcast runs in-process against Tokio (no Docker). PHP Swoole/Workerman live e2e is `#[ignore]`.

## Benchmarks

```bash
cargo bench --manifest-path crates/utopia-websocket/Cargo.toml
```

Prints `ws_accept_key`, `ws_encode_text_frame`, `ws_decode_text_frame` ops/s. PHP twin: [`benchmarks/websocket/`](../../benchmarks/websocket/).

## Code quality

- **rustfmt** - `cargo fmt --manifest-path crates/utopia-websocket/Cargo.toml`
- **Clippy** - `cargo clippy --manifest-path crates/utopia-websocket/Cargo.toml --all-targets -- -D warnings`

## License

MIT - see [LICENSE](LICENSE).
