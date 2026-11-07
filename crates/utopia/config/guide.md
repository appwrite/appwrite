Configuration comes from many places: environment variables, `.env` files, JSON, YAML or PHP files. This library reads any of them into a typed config: you describe the properties you expect, which key each one reads and how to validate it, and loading fails with a clear message when something is missing or wrong. It also keeps a process-wide registry of parameters for values shared across the application.

## Share parameters across the application

Set parameters once at startup and read them anywhere. Dotted keys reach into nested arrays.

```
use utopia_config::{Array, Value, get_param, set_param};

let mut platform = Array::default();
platform.set("hostname".into(), Value::from("cloud.appwrite.io"));
set_param("platform", Value::Array(platform));

assert_eq!(get_param("platform.hostname"), Some(Value::from("cloud.appwrite.io")));
assert_eq!(get_param("platform.region").unwrap_or(Value::from("default")), Value::from("default"));
```

```php
use Utopia\Config\Config;

Config::setParam('platform', ['hostname' => 'cloud.appwrite.io']);

echo Config::getParam('platform.hostname'), "\n";          // cloud.appwrite.io
echo Config::getParam('platform.region', 'default'), "\n"; // default
```

## Load a typed config from the environment

Describe the config as a schema: each property has a type, the key it reads and a validator. Optional keys fall back to a default.

```
use utopia_config::{Environment, Kind, NoParser, Property, Schema, Type, Value, load};
use utopia_validators::Text;

let schema = Schema::new("App\\Config")
    .property(Property::key("home", Type::of(Kind::String), "HOME", Text::with_min(4096, 0)))
    .property(Property::key("region", Type::of(Kind::String), "_APP_REGION", Text::new(32)).optional().default("default"));

let config = load(&Environment::new(), &NoParser, &schema)?;
assert!(config.get("home").is_some());
assert_eq!(config.get("region"), Some(&Value::from("default")));
# Ok::<(), utopia_config::Error>(())
```

```php
use Utopia\Config\Attribute\Key;
use Utopia\Config\Config;
use Utopia\Config\Parser\None;
use Utopia\Config\Source\Environment;
use Utopia\Validator\Text;

class AppConfig
{
    #[Key('HOME', new Text(4096, 0))]
    public string $home;

    #[Key('_APP_REGION', new Text(32), required: false)]
    public string $region = 'default';
}

$config = Config::load(new Environment(), new None(), AppConfig::class);
echo $config->region, "\n"; // default
```

## Read a dotenv file

Dotenv values are strings; a property typed `bool` turns `true`, `yes`, `on`, `enabled` and `1` (or their opposites) into booleans.

```
use utopia_config::{Dotenv, Kind, Property, Schema, Type, Value, Variable, load};
use utopia_validators::{Text, Wildcard};

let schema = Schema::new("App\\Mail")
    .property(Property::key("host", Type::of(Kind::String), "SMTP_HOST", Text::new(255)))
    .property(Property::key("secure", Type::of(Kind::Bool), "SMTP_SECURE", Wildcard));

let env = "SMTP_HOST=smtp.example.com # outgoing\nSMTP_SECURE=enabled";
let config = load(&Variable::new(env), &Dotenv, &schema)?;
assert_eq!(config.get("host"), Some(&Value::from("smtp.example.com")));
assert_eq!(config.get("secure"), Some(&Value::Bool(true)));
# Ok::<(), utopia_config::Error>(())
```

```php
use Utopia\Config\Attribute\Key;
use Utopia\Config\Config;
use Utopia\Config\Parser\Dotenv;
use Utopia\Config\Source\Variable;
use Utopia\Validator\Text;
use Utopia\Validator\Wildcard;

class MailConfig
{
    #[Key('SMTP_HOST', new Text(255))]
    public string $host;

    #[Key('SMTP_SECURE', new Wildcard())]
    public bool $secure;
}

$env = "SMTP_HOST=smtp.example.com # outgoing\nSMTP_SECURE=enabled";
$config = Config::load(new Variable($env), new Dotenv(), MailConfig::class);
var_dump($config->secure); // bool(true)
```

## Read JSON and YAML files

`File` reads a file; the JSON and YAML parsers turn it into nested maps, which dotted keys can read.

```
use utopia_config::{Kind, Property, Schema, Type, Value, Variable, Yaml, load};
use utopia_validators::{Text, Wildcard};

let schema = Schema::new("App\\Database")
    .property(Property::key("host", Type::of(Kind::String), "database.host", Text::new(255)))
    .property(Property::key("port", Type::of(Kind::Int), "database.port", Wildcard));

let yaml = "database:\n  host: db.internal\n  port: 5432\n";
let config = load(&Variable::new(yaml), &Yaml, &schema)?;
assert_eq!(config.get("port"), Some(&Value::Int(5432)));
# Ok::<(), utopia_config::Error>(())
```

```php
use Utopia\Config\Attribute\Key;
use Utopia\Config\Config;
use Utopia\Config\Parser\YAML;
use Utopia\Config\Source\Variable;
use Utopia\Validator\Text;
use Utopia\Validator\Wildcard;

class DatabaseConfig
{
    #[Key('database.host', new Text(255))]
    public string $host;

    #[Key('database.port', new Wildcard())]
    public int $port;
}

$yaml = "database:\n  host: db.internal\n  port: 5432\n";
$config = Config::load(new Variable($yaml), new YAML(), DatabaseConfig::class);
echo $config->port, "\n"; // 5432
```

## Report a bad config

Loading fails with `Error::Load` and a message naming the key, so a misconfigured deployment says what to fix.

```
use utopia_config::{Error, Json, Kind, Property, Schema, Type, Variable, load};
use utopia_validators::Text;

let schema = Schema::new("App\\Config").property(Property::key("key", Type::of(Kind::String), "key", Text::with_min(8, 0)));

let error = load(&Variable::new(r#"{"key": "far too long"}"#), &Json, &schema).unwrap_err();
assert!(matches!(error, Error::Load(_)));
assert_eq!(error.to_string(), "Invalid value for key: Value must be a valid string and no longer than 8 chars");
```

```php
use Utopia\Config\Attribute\Key;
use Utopia\Config\Config;
use Utopia\Config\Exception\Load;
use Utopia\Config\Parser\JSON;
use Utopia\Config\Source\Variable;
use Utopia\Validator\Text;

class ShortConfig
{
    #[Key('key', new Text(8, 0))]
    public string $key;
}

try {
    Config::load(new Variable('{"key": "far too long"}'), new JSON(), ShortConfig::class);
} catch (Load $e) {
    echo $e->getMessage(), "\n"; // Invalid value for key: Value must be a valid string and no longer than 8 chars
}
```
