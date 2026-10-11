A registry holds the resources an application shares, such as database connections and API clients, and creates each one only when it is first needed. Register a factory per resource at startup; code that needs a resource asks the registry for it by name.

## Create a resource on first use

The factory runs on the first read; later reads return the same instance.

```
use utopia_registry::Registry;

let mut registry = Registry::new();
registry.set("db", || String::from("postgresql://db.internal"));

assert!(registry.has("db"));
assert_eq!(registry.get::<String>("db")?, "postgresql://db.internal");
# Ok::<(), utopia_registry::Error>(())
```

```php
use Utopia\Registry\Registry;

$registry = new Registry();
$registry->set('db', fn () => 'postgresql://db.internal');

var_dump($registry->has('db'));  // bool(true)
echo $registry->get('db'), "\n"; // postgresql://db.internal
```

## Get a new instance every time

A fresh factory runs on every read, for resources that must not be shared. A single read can also ask for a new instance.

```
use std::sync::atomic::{AtomicU32, Ordering};
use utopia_registry::Registry;

let mut registry = Registry::new();
let requests = AtomicU32::new(0);
registry.set_fresh("request", move || requests.fetch_add(1, Ordering::Relaxed));

assert_eq!(*registry.get::<u32>("request")?, 0);
assert_eq!(*registry.get::<u32>("request")?, 1);
# Ok::<(), utopia_registry::Error>(())
```

```php
use Utopia\Registry\Registry;

$registry = new Registry();
$requests = 0;
$registry->set('request', function () use (&$requests) { return $requests++; }, true);

echo $registry->get('request'), "\n"; // 0
echo $registry->get('request'), "\n"; // 1
```

## Keep one set of resources per context

Each context has its own instances. Switch context per worker or tenant to give each its own connections.

```
use std::sync::atomic::{AtomicU32, Ordering};
use utopia_registry::Registry;

let mut registry = Registry::new();
let connections = AtomicU32::new(0);
registry.set("redis", move || connections.fetch_add(1, Ordering::Relaxed));

let first = *registry.get::<u32>("redis")?;
registry.context("tenant-b");
let second = *registry.get::<u32>("redis")?;
assert_ne!(first, second);
# Ok::<(), utopia_registry::Error>(())
```

```php
use Utopia\Registry\Registry;

$registry = new Registry();
$connections = 0;
$registry->set('redis', function () use (&$connections) { return $connections++; });

$first = $registry->get('redis');
$registry->context('tenant-b');
$second = $registry->get('redis');
var_dump($first !== $second); // bool(true)
```
