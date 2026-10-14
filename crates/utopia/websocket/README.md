# Utopia Websocket

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/websocket`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/websocket) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

WebSocket servers behind one adapter interface (Tokio, in-memory), with hooks, fan-out sends and error stages, and a WebSocket client.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-websocket = { git = "https://github.com/utopia-rs/websocket" }
```

A WebSocket server keeps many long-lived connections open and pushes messages to them. This library puts the server behind one `Server` type: you register hooks for its events (start, a connection opening, a message, a connection closing, a plain HTTP request), and from any hook you send to one or many connections or close one. The `Tokio` adapter serves on the network; the `Memory` adapter runs in process, for tests. A `Client` connects to any WebSocket server. This guide covers the common tasks.

## Serve WebSockets and talk to them

Create a `Tokio` adapter for a host and port, wrap it in a `Server`, register hooks and start it. A hook that sends keeps a clone of the server. Here the server runs on a free port in the background, and a `Client` connects to it, sends `ping` and reads the reply.

```rust
use utopia_websocket::client::{Client, Options};
use utopia_websocket::{Server, Tokio};

tokio::runtime::Runtime::new()?.block_on(async {
    let adapter = Tokio::new("127.0.0.1", 0, 5.0)?;
    let server = Server::new(adapter.clone());
    let replies = server.clone();
    server.on_message(move |connection, message| {
        if message == b"ping" {
            replies.send(&[connection], "pong");
        }
    });

    let running = server.clone();
    let task = tokio::spawn(async move { running.start().await });
    let addr = adapter.listening().await;

    let mut client = Client::new(&format!("ws://{addr}"), Options::default())?;
    client.connect().await?;
    client.send("ping").await?;
    assert_eq!(client.receive().await?.as_deref(), Some(&b"pong"[..]));
    client.close().await;

    server.shutdown();
    task.await.ok();
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
```

## Send to many connections

`send` takes a list of connections and returns at once: each connection writes its own queue. Connections that are not open are skipped. Keep track of who is connected with the open and close hooks.

```rust
use std::sync::{Arc, Mutex};

use utopia_websocket::{Memory, Server};

let adapter = Memory::new();
let server = Server::new(adapter.clone());
let connected = Arc::new(Mutex::new(Vec::new()));
let (opened, closed) = (connected.clone(), connected.clone());
server
    .on_open(move |connection, _| opened.lock().unwrap().push(connection))
    .on_close(move |connection| closed.lock().unwrap().retain(|&c| c != connection));

for connection in 1..=3 {
    adapter.open(connection, &Default::default());
}
adapter.disconnect(2);

let everyone = connected.lock().unwrap().clone();
server.send(&everyone, "maintenance in 5 minutes");
assert_eq!(adapter.frames().iter().map(|f| f.connection).collect::<Vec<_>>(), [1, 3]);
```

## Handle failures by stage

The server never returns an error. When a step fails, every callback registered with `error` gets the error and the `Stage` that failed, whose name is the method it guards (`send`, `onMessage`, ...).

```rust
use std::sync::{Arc, Mutex};

use utopia_websocket::{Memory, Server, Stage};

let adapter = Memory::new();
let server = Server::new(adapter.clone());
let failures = Arc::new(Mutex::new(Vec::new()));
let seen = failures.clone();
server.error(move |error, stage| seen.lock().unwrap().push(format!("{stage}: {error}")));

adapter.fail(Stage::Send, "buffer full");
server.send(&[1], "hello");

assert_eq!(*failures.lock().unwrap(), ["send: buffer full"]);
```

## Bound how long a slow client can hold a send

A client that stops reading fills its connection's buffer. Each send waits at most the send timeout (5 seconds by default) for the buffer to drain; after that the connection is reset, its queued messages are dropped and the close hook runs, so one stalled client cannot hold memory or delay the others.

```rust
use utopia_websocket::{Adapter, Tokio};

let adapter = Tokio::new("0.0.0.0", 8080, 1.0)?;
adapter.set_package_max_length(1024 * 1024);
adapter.set_worker_number(1);
assert_eq!(adapter.config().send_timeout, 1.0);

let invalid = Tokio::new("0.0.0.0", 8080, 0.0).err().unwrap();
assert_eq!(invalid.to_string(), "Send timeout must be a finite positive number of seconds");
```

## Listen with the client

`listen` reads until the connection closes and calls the hooks as frames arrive: text messages, pings (answered for you) and pongs. A failure calls the error hook and closes the client.

```rust
use utopia_websocket::client::{Client, Options};

let mut client = Client::new("ws://127.0.0.1:1", Options::default())?;
client
    .on_message(|message| println!("{}", String::from_utf8_lossy(message)))
    .on_error(|error| eprintln!("{error}"))
    .on_close(|| println!("closed"));

let refused = tokio::runtime::Runtime::new()?.block_on(client.connect()).unwrap_err();
assert_eq!(refused.to_string(), "WebSocket connection failed: 111 - Connection refused");
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
