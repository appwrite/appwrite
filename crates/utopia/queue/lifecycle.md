A worker server takes messages off queues and runs a job for each one. It has two lifecycles. Each worker's: it starts, consumes until it is told to stop, and stops. And each message's: hooks run around the job, and the message is then settled with the broker, committed when the job succeeds and rejected when it fails.

## The worker

```flow
Register | a job per queue, the hooks, and the resources every message shares
Start | `start` launches the adapter's workers and returns when all of them are done
> For each worker
Worker start | the `worker_start` hooks, with the worker's number as `workerId`
Consume | runs each message through the lifecycle below. `Runtime::Workers` keeps going until stopped. `Runtime::Drain` stops once the queues are empty
Worker stop | the `worker_stop` hooks, however the worker ended. Their failures are ignored
```

`Runtime::Workers` is for long-running servers. Each queue gets its own receive loop, which handles up to `coroutines` messages at once until `stop` is called. `Runtime::Drain` is for jobs that run once, such as a Kubernetes job: it takes messages one at a time and returns when a receive finds nothing.

## A message

```flow
> For each message
Receive | the consumer claims a message, which gets a fresh context: a child of the shared resources
Init hooks | the global (`*`) hooks, unless the job turned them off with `hook(false)`, then the hooks of the job's groups
Job | its params are read from the payload and validated, its resources are injected, then its callback runs
Commit | the broker is told the message is done
Shutdown hooks | the global (`*`) hooks, then the hooks of the job's groups
! Reject and error hooks | the init hooks or the job failed. The message is rejected and the error hooks run, with the failure as `error` and the message as `message`
```

Hooks and jobs read their params from the message's payload and inject resources by name: `message`, `workerId`, `error` in error hooks, and anything set on `Server::resources`. A resource a hook sets on its context is visible to the later hooks of the same message only.

The example runs a worker over one message. An in-memory consumer stands in for the broker so the example runs anywhere, and it records how the message was settled:

```
# use std::sync::{Arc, Mutex};
# use utopia_queue::connection::BoxFuture;
# use utopia_queue::{Consumer, Message, Queue};
# type Log = Mutex<Vec<String>>;
# /// A consumer without a broker: it hands out its messages once and logs how each is settled.
# struct Inbox { messages: Mutex<Vec<Message>>, log: Arc<Log> }
# impl Inbox {
#     fn new(log: &Arc<Log>, payloads: impl IntoIterator<Item = serde_json::Value>) -> Self {
#         let mut messages: Vec<Message> = payloads.into_iter().enumerate()
#             .map(|(i, p)| Message::new(i.to_string(), "mails", 0, utopia_queue::payload(&p).unwrap()))
#             .collect();
#         messages.reverse();
#         Self { messages: Mutex::new(messages), log: log.clone() }
#     }
# }
# impl Consumer for Inbox {
#     fn receive<'a>(&'a self, _: &'a Queue, _: i64, _: i64) -> BoxFuture<'a, utopia_queue::Result<Vec<Message>>> {
#         Box::pin(async move { Ok(self.messages.lock().unwrap().pop().into_iter().collect()) })
#     }
#     fn commit<'a>(&'a self, _: &'a Queue, _: &'a Message) -> BoxFuture<'a, utopia_queue::Result<()>> {
#         Box::pin(async move { self.log.lock().unwrap().push("commit".into()); Ok(()) })
#     }
#     fn reject<'a>(&'a self, _: &'a Queue, m: &'a Message) -> BoxFuture<'a, utopia_queue::Result<()>> {
#         let step = if m.is_terminal() { "reject (terminal)" } else { "reject" };
#         Box::pin(async move { self.log.lock().unwrap().push(step.into()); Ok(()) })
#     }
#     fn close(&self) -> BoxFuture<'_, ()> { Box::pin(async {}) }
# }
use serde_json::json;
use utopia_queue::adapter::{Adapter, Runtime};
use utopia_queue::hook::Scope;
use utopia_queue::Server;
use utopia_validators::Text;

utopia_async::Runtime::new()?.block_on(async {
    let log: Arc<Log> = Arc::default();
    let inbox = Arc::new(Inbox::new(&log, [json!({ "to": "ada@example.com" })]));
    let mut server = Server::new(Adapter::new(inbox, 1, Runtime::Drain));
    server.resources().set("log", log.clone());
    let step = |scope: &Scope, name: String| scope.get::<Log>("log").unwrap().lock().unwrap().push(name);

    server.worker_start().inject("log").inject("workerId").action(move |scope| async move {
        let id = scope.get::<String>("workerId").unwrap();
        step(&scope, format!("worker {id} start"));
    });
    server.init().inject("log").action(move |scope| async move { step(&scope, "init".into()) });
    server
        .job("mails", 1, None)?
        .param("to", php_std::Value::Null, Text::new(256), "Recipient", false)
        .inject("log")
        .action(move |scope| async move {
            let to = scope.param("to").to_php_string().unwrap_or_default();
            step(&scope, format!("mail to {}", to.as_str().unwrap_or_default()));
        });
    server.shutdown().inject("log").action(move |scope| async move { step(&scope, "shutdown".into()) });
    server.worker_stop().inject("log").action(move |scope| async move { step(&scope, "worker stop".into()) });

    Arc::new(server).start().await?;

    assert_eq!(
        *log.lock().unwrap(),
        ["worker 0 start", "init", "mail to ada@example.com", "commit", "shutdown", "worker stop"]
    );
    Ok::<_, utopia_queue::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

## When a job fails

```flow
Init hooks | run
Job | returns an error
! Reject | the broker is told the job failed. It may deliver the message again, unless the failure is terminal
! Error hooks | run in place of the shutdown hooks, with the failure as `error`
```

A failed message is rejected, never committed, and its shutdown hooks do not run. What the broker does with it depends on the failure. `Error::PermanentFailure`, a type error and a value error are terminal: the message is not retried. Any other failure lets the broker try it again.

```
# use std::sync::{Arc, Mutex};
# use utopia_queue::connection::BoxFuture;
# use utopia_queue::{Consumer, Message, Queue};
# type Log = Mutex<Vec<String>>;
# struct Inbox { messages: Mutex<Vec<Message>>, log: Arc<Log> }
# impl Inbox {
#     fn new(log: &Arc<Log>, payloads: impl IntoIterator<Item = serde_json::Value>) -> Self {
#         let mut messages: Vec<Message> = payloads.into_iter().enumerate()
#             .map(|(i, p)| Message::new(i.to_string(), "mails", 0, utopia_queue::payload(&p).unwrap()))
#             .collect();
#         messages.reverse();
#         Self { messages: Mutex::new(messages), log: log.clone() }
#     }
# }
# impl Consumer for Inbox {
#     fn receive<'a>(&'a self, _: &'a Queue, _: i64, _: i64) -> BoxFuture<'a, utopia_queue::Result<Vec<Message>>> {
#         Box::pin(async move { Ok(self.messages.lock().unwrap().pop().into_iter().collect()) })
#     }
#     fn commit<'a>(&'a self, _: &'a Queue, _: &'a Message) -> BoxFuture<'a, utopia_queue::Result<()>> {
#         Box::pin(async move { self.log.lock().unwrap().push("commit".into()); Ok(()) })
#     }
#     fn reject<'a>(&'a self, _: &'a Queue, m: &'a Message) -> BoxFuture<'a, utopia_queue::Result<()>> {
#         let step = if m.is_terminal() { "reject (terminal)" } else { "reject" };
#         Box::pin(async move { self.log.lock().unwrap().push(step.into()); Ok(()) })
#     }
#     fn close(&self) -> BoxFuture<'_, ()> { Box::pin(async {}) }
# }
use serde_json::json;
use utopia_queue::adapter::{Adapter, Runtime};
use utopia_queue::hook::Scope;
use utopia_queue::{Error, Server};

utopia_async::Runtime::new()?.block_on(async {
    let log: Arc<Log> = Arc::default();
    let inbox = Arc::new(Inbox::new(&log, [json!({ "to": "" }), json!({ "to": "ada@example.com" })]));
    let mut server = Server::new(Adapter::new(inbox, 1, Runtime::Drain));
    server.resources().set("log", log.clone());
    let step = |scope: &Scope, name: String| scope.get::<Log>("log").unwrap().lock().unwrap().push(name);

    server.job("mails", 1, None)?.inject("message").action(|scope| async move {
        let message = scope.message().unwrap();
        match message.payload().get_str("to").and_then(|to| to.to_php_string().ok()) {
            Some(to) if !to.is_empty() => Ok(()),
            _ => Err(Error::PermanentFailure("no recipient".into())),
        }
    });
    server.shutdown().inject("log").action(move |scope| async move { step(&scope, "shutdown".into()) });
    server.error().inject("log").inject("error").action(move |scope| async move {
        let error = scope.error().unwrap();
        step(&scope, format!("error: {error}"));
    });

    Arc::new(server).start().await?;

    assert_eq!(
        *log.lock().unwrap(),
        ["reject (terminal)", "error: no recipient", "commit", "shutdown"]
    );
    Ok::<_, utopia_queue::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

## What each failure does

| Failure | The message | Then |
|---|---|---|
| An init hook or the job | Rejected: terminal for `PermanentFailure`, type and value errors, otherwise retried by the broker | The error hooks |
| The commit | Not rejected, because the job's work is done | The error hooks |
| A shutdown hook | Already committed | The error hooks |
| The worker cannot start, such as when no job is registered | Nothing was received | `start` runs the error hooks and returns the error |
