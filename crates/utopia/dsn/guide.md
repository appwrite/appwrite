A DSN (data source name) puts everything needed to reach a service in one string: `scheme://user:password@host:port/path?query`. Applications read them from configuration so a database, cache or provider can change without code changes. This guide covers the common tasks.

## Read a connection string

Parse the string once, then read each part. A missing part is `None` (or empty for the path).

```
use utopia_dsn::Dsn;

let dsn = Dsn::parse("postgresql://appwrite:secret@db.internal:5432/appwrite")?;

assert_eq!(dsn.scheme(), "postgresql");
assert_eq!(dsn.host(), "db.internal");
assert_eq!(dsn.port(), Some(5432));
assert_eq!(dsn.path(), "appwrite");
assert_eq!(dsn.user(), Some(&b"appwrite"[..]));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\DSN\DSN;

$dsn = new DSN('postgresql://appwrite:secret@db.internal:5432/appwrite');

echo $dsn->getScheme(), "\n"; // postgresql
echo $dsn->getHost(), "\n";   // db.internal
echo $dsn->getPort(), "\n";   // 5432
echo $dsn->getPath(), "\n";   // appwrite
echo $dsn->getUser(), "\n";   // appwrite
```

## Use credentials with special characters

Passwords often contain `@`, `/` or `:`, which have a meaning in a DSN. Percent-encode them in the string; the parser decodes them. Credentials are bytes in Rust, because a decoded password can be any bytes.

```
use utopia_dsn::Dsn;

let dsn = Dsn::parse("redis://default:p%40ss%2Fw%3Ard@cache:6379")?;

assert_eq!(dsn.password(), Some(&b"p@ss/w:rd"[..]));
let password = String::from_utf8_lossy(dsn.password().unwrap_or_default());
assert_eq!(password, "p@ss/w:rd");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\DSN\DSN;

$dsn = new DSN('redis://default:p%40ss%2Fw%3Ard@cache:6379');

echo $dsn->getPassword(), "\n"; // p@ss/w:rd
```

## Keep secrets out of logs

Printing a DSN for debugging never shows its password, and errors never quote the string they refused.

```
use utopia_dsn::Dsn;

let dsn = Dsn::parse("mariadb://root:hunter2@db:3306/appwrite")?;
let logged = format!("{dsn:?}");

assert!(logged.contains("db"));
assert!(!logged.contains("hunter2"));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\DSN\DSN;

// PHP marks the constructor argument #[\SensitiveParameter], so stack traces hide it.
$dsn = new DSN('mariadb://root:hunter2@db:3306/appwrite');
echo $dsn->getHost(), "\n"; // db
```

## Read options from the query string

Settings that are not part of the address go in the query. Read one with `param`, or with `param_or` to fall back to a default.

```
use utopia_dsn::Dsn;

let dsn = Dsn::parse("s3://key:secret@s3.amazonaws.com/uploads?region=eu-central-1&acl=private")?;

assert_eq!(dsn.param("region")?, Some(&b"eu-central-1"[..]));
assert_eq!(dsn.param_or("acl", b"public-read")?, b"private");
assert_eq!(dsn.param_or("endpoint", b"https://s3.amazonaws.com")?, b"https://s3.amazonaws.com");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\DSN\DSN;

$dsn = new DSN('s3://key:secret@s3.amazonaws.com/uploads?region=eu-central-1&acl=private');

echo $dsn->getParam('region'), "\n";                                // eu-central-1
echo $dsn->getParam('acl', 'public-read'), "\n";                    // private
echo $dsn->getParam('endpoint', 'https://s3.amazonaws.com'), "\n";  // https://s3.amazonaws.com
```

## Configure a service from the environment

Read the DSN from an environment variable, with a default for local development, and pick an adapter by its scheme.

```
use utopia_dsn::Dsn;

let value = std::env::var("APP_CACHE").unwrap_or_else(|_| "redis://localhost:6379".to_owned());
let dsn = Dsn::parse(&value)?;

let adapter = match dsn.scheme() {
    "redis" => format!("Redis at {}:{}", dsn.host(), dsn.port().unwrap_or(6379)),
    "memcached" => format!("Memcached at {}", dsn.host()),
    other => format!("unsupported cache: {other}"),
};
assert_eq!(adapter, "Redis at localhost:6379");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\DSN\DSN;

$dsn = new DSN(getenv('APP_CACHE') ?: 'redis://localhost:6379');

$adapter = match ($dsn->getScheme()) {
    'redis' => 'Redis at ' . $dsn->getHost() . ':' . ($dsn->getPort() ?? 6379),
    'memcached' => 'Memcached at ' . $dsn->getHost(),
    default => 'unsupported cache: ' . $dsn->getScheme(),
};
echo $adapter, "\n"; // Redis at localhost:6379
```

## Reject invalid configuration

A DSN needs a scheme and a host. Anything else fails with an error you can report without exposing the value.

```
use utopia_dsn::{Dsn, Error};

for value in ["localhost:6379", "redis://", "not a dsn"] {
    match Dsn::parse(value) {
        Err(Error::InvalidArgument(reason)) => println!("invalid cache configuration: {reason}"),
        other => panic!("expected an error, got {other:?}"),
    }
}
```

```php
use Utopia\DSN\DSN;

foreach (['localhost:6379', 'redis://', 'not a dsn'] as $value) {
    try {
        new DSN($value);
    } catch (\InvalidArgumentException $e) {
        echo 'invalid cache configuration: ', $e->getMessage(), "\n";
    }
}
```
