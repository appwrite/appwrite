A container builds the objects an application is made of, such as connections, clients and services, from factories that declare what they depend on. Ask the container for an id and it resolves the dependencies, builds the object once, and shares it with every later caller.

## Register and resolve a dependency

A factory receives its dependencies, resolved, in the order it declared them.

```
use utopia_di::Container;

let container = Container::new();
container.set("host", &[], |_| Ok(String::from("db.internal")));
container.set("dsn", &["host"], |deps| Ok(format!("postgresql://{}:5432", deps.get::<String>(0)?)));

assert_eq!(*container.get::<String>("dsn")?, "postgresql://db.internal:5432");
# Ok::<(), utopia_di::Error>(())
```

```php
use Utopia\DI\Container;

$container = new Container();
$container->set('host', fn () => 'db.internal');
$container->set('dsn', fn (string $host) => "postgresql://$host:5432", ['host']);

echo $container->get('dsn'), "\n"; // postgresql://db.internal:5432
```

## Share one instance

The factory runs on the first `get`; every later `get` returns the same instance.

```
use std::sync::Arc;
use utopia_di::Container;

let container = Container::new();
container.set("pool", &[], |_| Ok(vec![String::from("connection")]));

let first = container.get::<Vec<String>>("pool")?;
let second = container.get::<Vec<String>>("pool")?;
assert!(Arc::ptr_eq(&first, &second));
# Ok::<(), utopia_di::Error>(())
```

```php
use Utopia\DI\Container;

$container = new Container();
$container->set('pool', fn () => new \ArrayObject(['connection']));

var_dump($container->get('pool') === $container->get('pool')); // bool(true)
```

## Scope dependencies with a child container

A child container adds or overrides dependencies for one request or job, and resolves everything else through its parent, which keeps sharing what it built.

```
use std::sync::Arc;
use utopia_di::Container;

let app = Arc::new(Container::new());
app.set("config", &[], |_| Ok("production"));

let request = Container::with_parent(app.clone());
request.set("user", &["config"], |deps| Ok(format!("user@{}", deps.get::<&str>(0)?)));

assert_eq!(*request.get::<String>("user")?, "user@production");
assert!(request.has("config"));
assert!(!app.has("user"));
# Ok::<(), utopia_di::Error>(())
```

```php
use Utopia\DI\Container;

$app = new Container();
$app->set('config', fn () => 'production');

$request = new Container($app);
$request->set('user', fn (string $config) => "user@$config", ['config']);

echo $request->get('user'), "\n";  // user@production
var_dump($request->has('config')); // bool(true)
var_dump($app->has('user'));       // bool(false)
```

## Handle a missing dependency

Resolving an id that no container defines fails with `Error::NotFound`.

```
use utopia_di::{Container, Error};

let container = Container::new();
let error = container.get_any("cache").unwrap_err();
assert_eq!(error, Error::NotFound("cache".into()));
assert_eq!(error.to_string(), "Dependency cache not found");
```

```php
use Psr\Container\NotFoundExceptionInterface;
use Utopia\DI\Container;

try {
    (new Container())->get('cache');
} catch (NotFoundExceptionInterface $e) {
    echo $e->getMessage(), "\n"; // Dependency cache not found
}
```
