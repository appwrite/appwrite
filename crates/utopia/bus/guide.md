An event bus decouples what happens from what reacts to it: a deployment publishes `Deployed`, and the mailer, the audit log and the usage counter each subscribe to it without the deployment knowing about them. Listeners run synchronously, in subscription order, each with the dependencies it injects.

## Publish an event to its listeners

Listeners subscribe to event types; dispatching an event calls those of its type only.

```
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
# Ok::<(), utopia_bus::Error>(())
```

```php
use Utopia\Bus\Bus;
use Utopia\Bus\Event;
use Utopia\Bus\Listener;

final class Deployed implements Event { public function __construct(public string $id) {} }
final class Cancelled implements Event {}

final class Audit extends Listener
{
    public static function getName(): string { return 'audit'; }
    public static function getEvents(): array { return [Deployed::class]; }
    public function __construct() { $this->callback(fn (Deployed $event) => print("deployed {$event->id}\n")); }
}

final class Refunds extends Listener
{
    public static function getName(): string { return 'refunds'; }
    public static function getEvents(): array { return [Cancelled::class]; }
    public function __construct() { $this->callback(fn () => print("cancelled\n")); }
}

(new Bus())
    ->setResolver(fn (string $name) => $name)
    ->subscribe(new Audit())
    ->subscribe(new Refunds())
    ->dispatch(new Deployed('d1')); // deployed d1
```

## Inject dependencies

A listener names its dependencies; the bus's resolver builds each one on every dispatch, and the callback receives them in that order.

```
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
# Ok::<(), utopia_bus::Error>(())
```

```php
use Utopia\Bus\Bus;
use Utopia\Bus\Event;
use Utopia\Bus\Listener;

final class Deployed implements Event {}

final class Notify extends Listener
{
    public static function getName(): string { return 'notify'; }
    public static function getEvents(): array { return [Deployed::class]; }
    public function __construct()
    {
        $this->inject('mailer')->callback(fn (Event $event, string $mailer) => print($mailer . "\n"));
    }
}

(new Bus())
    ->setResolver(fn (string $name) => $name === 'mailer' ? 'smtp' : throw new \Exception("unknown dependency $name"))
    ->subscribe(new Notify())
    ->dispatch(new Deployed()); // smtp
```

## Keep failures isolated

A failing listener does not stop the others; its failure is recorded on the current span.

```
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
# Ok::<(), utopia_bus::Error>(())
```

```php
use Utopia\Bus\Bus;
use Utopia\Bus\Event;
use Utopia\Bus\Listener;
use Utopia\Span\Span;
use Utopia\Span\Storage\Memory;

final class Deployed implements Event {}

final class Mailer extends Listener
{
    public static function getName(): string { return 'mailer'; }
    public static function getEvents(): array { return [Deployed::class]; }
    public function __construct() { $this->callback(fn () => throw new \RuntimeException('SMTP is down', 503)); }
}

final class Audit extends Listener
{
    public static function getName(): string { return 'audit'; }
    public static function getEvents(): array { return [Deployed::class]; }
    public function __construct() { $this->callback(fn () => null); }
}

Span::setStorage(new Memory());
$span = Span::init('deploy');

(new Bus())->setResolver(fn (string $name) => $name)->subscribe(new Mailer())->subscribe(new Audit())->dispatch(new Deployed());

var_dump($span->get('listener.mailer.success'));       // bool(false)
echo $span->get('listener.mailer.error.message'), "\n"; // SMTP is down
var_dump($span->get('listener.audit.success'));        // bool(true)
```
