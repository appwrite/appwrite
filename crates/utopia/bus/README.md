# Utopia Bus

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/bus`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/bus) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

A synchronous in-process event bus: typed events, listeners with injected dependencies, failures isolated and recorded on the current span.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-bus = { git = "https://github.com/utopia-rs/bus" }
```

An event bus decouples what happens from what reacts to it: a deployment publishes `Deployed`, and the mailer, the audit log and the usage counter each subscribe to it without the deployment knowing about them. Listeners run synchronously, in subscription order, each with the dependencies it injects.

## Publish an event to its listeners

Listeners subscribe to event types; dispatching an event calls those of its type only.

```rust
use std::sync::{Arc, Mutex};
use utopia_bus::{Bus, Event, Listener, Resource};

struct Deployed { id: String }
impl Event for Deployed {}
struct Cancelled;
impl Event for Cancelled {}

let log = Arc::new(Mutex::new(Vec::new()));
let mut bus = Bus::new();
bus.set_resolver(|name| Ok(Arc::new(name.to_owned()) as Resource));

let seen = log.clone();
bus.subscribe(Listener::new("audit").on::<Deployed>().callback(move |event, _| {
    if let Some(deployed) = event.downcast_ref::<Deployed>() {
        seen.lock().unwrap().push(format!("deployed {}", deployed.id));
    }
    Ok(())
}));
let seen = log.clone();
bus.subscribe(Listener::new("refunds").on::<Cancelled>().callback(move |_, _| {
    seen.lock().unwrap().push("cancelled".to_owned());
    Ok(())
}));

bus.dispatch(&Deployed { id: "d1".into() })?;
assert_eq!(*log.lock().unwrap(), ["deployed d1"]);
```

## Inject dependencies

A listener names its dependencies; the bus's resolver builds each one on every dispatch, and the callback receives them in that order.

```rust
use std::sync::{Arc, Mutex};
use utopia_bus::{Bus, Event, Listener, Resource};

struct Deployed;
impl Event for Deployed {}

let sent = Arc::new(Mutex::new(Vec::new()));
let out = sent.clone();
let mut bus = Bus::new();
bus.set_resolver(|name| match name {
    "mailer" => Ok(Arc::new(String::from("smtp")) as Resource),
    other => Err(utopia_bus::Failure::new(format!("unknown dependency {other}"))),
});
bus.subscribe(Listener::new("notify").on::<Deployed>().inject("mailer").callback(move |_, deps| {
    out.lock().unwrap().push(deps[0].downcast_ref::<String>().cloned().unwrap_or_default());
    Ok(())
}));

bus.dispatch(&Deployed)?;
assert_eq!(*sent.lock().unwrap(), ["smtp"]);
```

## Keep failures isolated

A failing listener does not stop the others; its failure is recorded on the current span.

```rust
use std::sync::Arc;
use utopia_bus::{Bus, Event, Failure, Listener, Resource};
use utopia_span::{Memory, Span, Value};

struct Deployed;
impl Event for Deployed {}

Span::set_storage(Some(Arc::new(Memory::new())));
let span = Span::init("deploy", None);

let mut bus = Bus::new();
bus.set_resolver(|name| Ok(Arc::new(name.to_owned()) as Resource));
bus.subscribe(Listener::new("mailer").on::<Deployed>().callback(|_, _| Err(Failure::new("SMTP is down").with_code(503))));
bus.subscribe(Listener::new("audit").on::<Deployed>().callback(|_, _| Ok(())));

bus.dispatch(&Deployed)?;
assert_eq!(span.get("listener.mailer.success"), Value::from(false));
assert_eq!(span.get("listener.mailer.error.message"), Value::from("SMTP is down"));
assert_eq!(span.get("listener.audit.success"), Value::from(true));
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
