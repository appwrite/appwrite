A WebSocket server keeps connections open and reacts to events. There are two lifecycles. The server's: it starts, runs its workers and shuts down. And each connection's: it opens, receives messages and closes. You write one hook per event, and from any hook you can send to connections or close them.

## The server

```flow
Register | the hooks for each event, and the error callbacks. Register them before `start`: the network adapter reads them when it starts
Start | `start` listens, then calls the `on_start` hook once
Worker start | the `on_worker_start` hook, once for each worker, with the worker's number
> Until `shutdown` is called
Serve | each connection runs the lifecycle below
<
Shutdown | open connections are closed, then the `on_worker_stop` hook runs for each worker, and `start` returns
```

Each event has one hook. Registering a second hook for the same event replaces the first.

## A connection

```flow
> For each connection
Request | a request that is not a WebSocket upgrade goes to `on_request`, which fills the response, and ends there
Open | the handshake is done. `on_open` gets the connection's id and the handshake request
> For each message
Message | `on_message` gets the connection and the message
<
Close | the client left, or the server closed it. `on_close` gets the connection
```

Use the open and close hooks to keep track of who is connected, and the message hook to answer. A hook that sends or closes holds a clone of the server: clones share the adapter and the hooks.

The example runs on the in-memory adapter, which plays the clients' side, and records each event. Its connection ids are ones the test picks, where the network adapter gives each connection its own:

```
use std::sync::{Arc, Mutex};
use utopia_websocket::{Memory, Server};

let adapter = Memory::new();
let server = Server::new(adapter.clone());
let log = Arc::new(Mutex::new(Vec::new()));

let (l, replies) = (log.clone(), server.clone());
server
    .on_start({ let l = log.clone(); move || l.lock().unwrap().push("start".to_owned()) })
    .on_worker_start({ let l = log.clone(); move |worker| l.lock().unwrap().push(format!("worker {worker} start")) })
    .on_open({ let l = log.clone(); move |connection, _| l.lock().unwrap().push(format!("{connection} open")) })
    .on_message(move |connection, message| {
        l.lock().unwrap().push(format!("{connection} says {}", String::from_utf8_lossy(message)));
        replies.send(&[connection], "pong");
    })
    .on_close({ let l = log.clone(); move |connection| l.lock().unwrap().push(format!("{connection} close")) })
    .on_worker_stop({ let l = log.clone(); move |worker| l.lock().unwrap().push(format!("worker {worker} stop")) });

utopia_async::Runtime::new().unwrap().block_on(server.start());
adapter.open(1, &Default::default());
adapter.message(1, b"ping");
adapter.disconnect(1);
server.shutdown();

assert_eq!(
    *log.lock().unwrap(),
    ["start", "worker 0 start", "1 open", "1 says ping", "1 close", "worker 0 stop"]
);
assert_eq!(adapter.frames()[0].data, "pong");
```

## Close a connection from the server

The server can end a connection from any hook with `close` and a close code. The close hook runs for it, just as when the client leaves, so the cleanup lives in one place.

```
use std::sync::{Arc, Mutex};
use utopia_websocket::{Memory, Server};

let adapter = Memory::new();
let server = Server::new(adapter.clone());
let log = Arc::new(Mutex::new(Vec::new()));

let closer = server.clone();
server.on_message(move |connection, message| {
    if message == b"bye" {
        closer.close(connection, 1000);
    }
});
let l = log.clone();
server.on_close(move |connection| l.lock().unwrap().push(format!("{connection} close")));

adapter.open(7, &Default::default());
adapter.message(7, b"bye");

assert_eq!(*log.lock().unwrap(), ["7 close"]);
assert_eq!(adapter.closed()[0].code, 1000);
assert!(adapter.connections().is_empty());
```

## When something fails

```flow
Any step | `start`, `send`, `close`, `shutdown`, or registering a hook
! Error callbacks | each one gets the error and the `Stage` that failed. Nothing is returned to the caller
```

The server never returns an error. When a step fails, every callback registered with `error` gets the error and the stage, in the order they were added. The stage's name is the method that failed, such as `send` or `onMessage`. Without any error callback, the failure is dropped.

```
use std::sync::{Arc, Mutex};
use utopia_websocket::{Memory, Server, Stage};

let adapter = Memory::new();
let server = Server::new(adapter.clone());
let failures = Arc::new(Mutex::new(Vec::new()));
let seen = failures.clone();
server.error(move |error, stage| seen.lock().unwrap().push(format!("{stage}: {error}")));

adapter.fail(Stage::Start, "port in use");
utopia_async::Runtime::new().unwrap().block_on(server.start());

assert_eq!(*failures.lock().unwrap(), ["start: port in use"]);
assert!(!adapter.is_started());
```
