Validators check request parameters before an action runs: a name is a short string, a limit is a number in range, a URL uses https. Each validator answers two questions: is this value valid, and what rule does it follow. The rule is a human-readable description that becomes the error message a client sees, so it is part of the API. Values are `serde_json::Value`, the form a decoded request body takes. This guide covers the common tasks.

## Validate a text field

`Text` accepts a string between a minimum and a maximum number of characters. A maximum of 0 means no limit, and the minimum defaults to 1.

```
use serde_json::json;
use utopia_validators::{Text, Validator};

let username = Text::with_min(20, 3);

assert!(username.is_valid(&json!("eldad")));
assert!(!username.is_valid(&json!("ab")));
assert!(!username.is_valid(&json!(42)));
assert_eq!(username.description(), "Value must be a valid string and at least 3 chars and no longer than 20 chars");

let name = Text::new(128).non_blank();
assert!(!name.is_valid(&json!("   ")));
assert_eq!(name.description(), "Value must be a valid string and at least 1 chars and no longer than 128 chars and not be blank");
```

```php
use Utopia\Validator\Text;

$username = new Text(20, min: 3);

var_dump($username->isValid('eldad')); // bool(true)
var_dump($username->isValid('ab'));    // bool(false)
var_dump($username->isValid(42));      // bool(false)
echo $username->getDescription(), "\n"; // Value must be a valid string and at least 3 chars and no longer than 20 chars

$name = new Text(128, requireNonBlank: true);
var_dump($name->isValid('   '));    // bool(false)
echo $name->getDescription(), "\n"; // Value must be a valid string and at least 1 chars and no longer than 128 chars and not be blank
```

## Validate numbers in a range

`Range` accepts an integer between two bounds, inclusive. `Integer` accepts any integer that fits a number of bits. `Range` also accepts numeric strings, as query strings carry them. `Integer` accepts them only when loose.

```
use serde_json::json;
use utopia_validators::{Integer, Range, Validator};

let limit = Range::new(1, 5000);

assert!(limit.is_valid(&json!(25)));
assert!(!limit.is_valid(&json!(0)));
assert!(limit.is_valid(&json!("25")));
assert!(!limit.is_valid(&json!(2.5)));
assert_eq!(limit.description(), "Value must be a valid range between 1 and 5,000");

let offset = Integer::new(true, 32, true)?;
assert!(offset.is_valid(&json!("25")));
assert!(!offset.is_valid(&json!(-1)));
assert_eq!(offset.description(), "Value must be a valid unsigned 32-bit integer between 0 and 4,294,967,295");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Validator\Integer;
use Utopia\Validator\Range;

$limit = new Range(1, 5000);

var_dump($limit->isValid(25));   // bool(true)
var_dump($limit->isValid(0));    // bool(false)
var_dump($limit->isValid('25')); // bool(true)
var_dump($limit->isValid(2.5));  // bool(false)
echo $limit->getDescription(), "\n"; // Value must be a valid range between 1 and 5,000

$offset = new Integer(loose: true, bits: 32, unsigned: true);
var_dump($offset->isValid('25')); // bool(true)
var_dump($offset->isValid(-1));   // bool(false)
echo $offset->getDescription(), "\n"; // Value must be a valid unsigned 32-bit integer between 0 and 4,294,967,295
```

## Accept one of a fixed set of values

`WhiteList` accepts a value from a list. By default it ignores case. A strict list needs an exact match.

```
use serde_json::json;
use utopia_validators::{Validator, WhiteList};

let order = WhiteList::new(&["ASC", "DESC"]);

assert!(order.is_valid(&json!("asc")));
assert!(!order.is_valid(&json!("random")));
assert_eq!(order.description(), "Value must be one of (asc, desc)");

let region = WhiteList::strict(&["fra", "nyc"]);
assert!(region.is_valid(&json!("fra")));
assert!(!region.is_valid(&json!("FRA")));
assert_eq!(region.description(), "Value must be one of (fra, nyc)");
```

```php
use Utopia\Validator\WhiteList;

$order = new WhiteList(['ASC', 'DESC']);

var_dump($order->isValid('asc'));    // bool(true)
var_dump($order->isValid('random')); // bool(false)
echo $order->getDescription(), "\n"; // Value must be one of (asc, desc)

$region = new WhiteList(['fra', 'nyc'], strict: true);
var_dump($region->isValid('fra')); // bool(true)
var_dump($region->isValid('FRA')); // bool(false)
echo $region->getDescription(), "\n"; // Value must be one of (fra, nyc)
```

## Validate a list of values

`ArrayList` accepts an array whose items all pass another validator. Its second argument caps the number of items; 0 means no cap. The description includes the item rule.

```
use serde_json::json;
use utopia_validators::{ArrayList, Text, Validator};

let tags = ArrayList::new(Text::new(32), 3);

assert!(tags.is_valid(&json!(["php", "rust"])));
assert!(!tags.is_valid(&json!(["a", "b", "c", "d"])));
assert!(!tags.is_valid(&json!(["php", 7])));
assert!(!tags.is_valid(&json!("php")));
assert_eq!(
    tags.description(),
    "Value must a valid array no longer than 3 items and Value must be a valid string and at least 1 chars and no longer than 32 chars"
);
```

```php
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

$tags = new ArrayList(new Text(32), 3);

var_dump($tags->isValid(['php', 'rust']));         // bool(true)
var_dump($tags->isValid(['a', 'b', 'c', 'd']));    // bool(false)
var_dump($tags->isValid(['php', 7]));              // bool(false)
var_dump($tags->isValid('php'));                   // bool(false)
echo $tags->getDescription(), "\n"; // Value must a valid array no longer than 3 items and Value must be a valid string and at least 1 chars and no longer than 32 chars
```

## Validate URLs, hosts and IPs

`Url` accepts a URL, optionally only with some schemes. `Host` accepts a URL whose host is on an allow list. `Ip` accepts an IPv4 or IPv6 address, or only one of them.

```
use serde_json::json;
use utopia_validators::{Host, Ip, IpVersion, Url, Validator};

let webhook = Url { allowed_schemes: vec!["https".into()], ..Url::default() };
assert!(webhook.is_valid(&json!("https://example.com/hook")));
assert!(!webhook.is_valid(&json!("http://example.com/hook")));
assert_eq!(webhook.description(), "Value must be a valid URL with following schemes (https)");

let redirect = Host::new(vec!["appwrite.io".into(), "*.appwrite.io".into()]);
assert!(redirect.is_valid(&json!("https://cloud.appwrite.io/console")));
assert!(!redirect.is_valid(&json!("https://example.com")));
assert_eq!(redirect.description(), "URL host must be one of: appwrite.io, *.appwrite.io");

let ip = Ip::new(IpVersion::V4);
assert!(ip.is_valid(&json!("192.168.1.10")));
assert!(!ip.is_valid(&json!("::1")));
assert_eq!(ip.description(), "Value must be a valid IP address");
```

```php
use Utopia\Validator\Host;
use Utopia\Validator\IP;
use Utopia\Validator\URL;

$webhook = new URL(['https']);
var_dump($webhook->isValid('https://example.com/hook')); // bool(true)
var_dump($webhook->isValid('http://example.com/hook'));  // bool(false)
echo $webhook->getDescription(), "\n"; // Value must be a valid URL with following schemes (https)

$redirect = new Host(['appwrite.io', '*.appwrite.io']);
var_dump($redirect->isValid('https://cloud.appwrite.io/console')); // bool(true)
var_dump($redirect->isValid('https://example.com'));               // bool(false)
echo $redirect->getDescription(), "\n"; // URL host must be one of: appwrite.io, *.appwrite.io

$ip = new IP(IP::V4);
var_dump($ip->isValid('192.168.1.10')); // bool(true)
var_dump($ip->isValid('::1'));          // bool(false)
echo $ip->getDescription(), "\n"; // Value must be a valid IP address
```

## Accept null for an optional value

`Nullable` wraps a validator so `null` passes too. Use it when a client may clear a field by sending `null`.

```
use serde_json::json;
use utopia_validators::{Nullable, Text, Validator};

let bio = Nullable(Text::new(256));

assert!(bio.is_valid(&json!(null)));
assert!(bio.is_valid(&json!("Hello")));
assert!(!bio.is_valid(&json!("")));
assert_eq!(bio.description(), "Value must be a valid string and at least 1 chars and no longer than 256 chars or null");
```

```php
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

$bio = new Nullable(new Text(256));

var_dump($bio->isValid(null));    // bool(true)
var_dump($bio->isValid('Hello')); // bool(true)
var_dump($bio->isValid(''));      // bool(false)
echo $bio->getDescription(), "\n"; // Value must be a valid string and at least 1 chars and no longer than 256 chars or null
```

## Combine validators

`AnyOf` passes when one rule passes, and `AllOf` when every rule does. After a failure, the description names the rule that decided. In Rust, `check` returns that description, because validators keep no state between calls.

```
use serde_json::json;
use utopia_validators::{AllOf, AnyOf, Ip, Text, Url, Validator};

let target = AnyOf::new(vec![Box::new(Url::default()), Box::new(Ip::default())]);
assert!(target.is_valid(&json!("10.0.0.1")));
assert_eq!(target.check(&json!("nope")), Err("Value must be a valid IP address".to_owned()));

let path = AllOf::new(vec![Box::new(Text::new(64)), Box::new(Url::default())]);
assert_eq!(
    path.check(&json!("https://example.com/a-very-long-path-that-goes-past-the-sixty-four-char-limit")),
    Err("Value must be a valid string and at least 1 chars and no longer than 64 chars".to_owned())
);
assert_eq!(path.check(&json!("not a url")), Err("Value must be a valid URL".to_owned()));
```

```php
use Utopia\Validator\AllOf;
use Utopia\Validator\AnyOf;
use Utopia\Validator\IP;
use Utopia\Validator\Text;
use Utopia\Validator\URL;

$target = new AnyOf([new URL(), new IP()]);
var_dump($target->isValid('10.0.0.1')); // bool(true)
$target->isValid('nope');
echo $target->getDescription(), "\n"; // Value must be a valid IP address

$path = new AllOf([new Text(64), new URL()]);
$path->isValid('https://example.com/a-very-long-path-that-goes-past-the-sixty-four-char-limit');
echo $path->getDescription(), "\n"; // Value must be a valid string and at least 1 chars and no longer than 64 chars
$path->isValid('not a url');
echo $path->getDescription(), "\n"; // Value must be a valid URL
```

## Show the error message a user sees

A request handler validates each parameter and, on failure, reports the description. `check` returns `Err` with the message to show.

```
use serde_json::json;
use utopia_validators::{Range, Validator};

let limit = Range::new(1, 100);
let message = match limit.check(&json!(500)) {
    Ok(()) => String::new(),
    Err(description) => format!("Invalid `limit` param: {description}"),
};
assert_eq!(message, "Invalid `limit` param: Value must be a valid range between 1 and 100");
```

```php
use Utopia\Validator\Range;

$limit = new Range(1, 100);
if (!$limit->isValid(500)) {
    echo 'Invalid `limit` param: ', $limit->getDescription(), "\n"; // Invalid `limit` param: Value must be a valid range between 1 and 100
}
```

## Validate JSON objects

`json::Object` accepts a JSON object, either decoded or still encoded as a string. `Json` accepts any valid JSON, including scalars, so prefer the shape-specific validator when the value must be an object.

```
use serde_json::json;
use utopia_validators::json::Object;
use utopia_validators::{Json, Validator};

let data = Object::default();

assert!(data.is_valid(&json!({"event": "login"})));
assert!(data.is_valid(&json!(r#"{"event": "login"}"#)));
assert!(!data.is_valid(&json!(r#""login""#)));
assert!(!data.is_valid(&json!("[]")));
assert_eq!(data.description(), "Value must be a valid JSON object");

assert!(Json.is_valid(&json!(r#""login""#)));
```

```php
use Utopia\Validator\JSON;
use Utopia\Validator\JSON\ObjectValidator;

$data = new ObjectValidator();

var_dump($data->isValid(['event' => 'login']));  // bool(true)
var_dump($data->isValid('{"event": "login"}'));  // bool(true)
var_dump($data->isValid('"login"'));             // bool(false)
var_dump($data->isValid('[]'));                  // bool(false)
echo $data->getDescription(), "\n"; // Value must be a valid JSON object

var_dump((new JSON())->isValid('"login"')); // bool(true)
```

## Write your own validator

Implement `description` and `is_valid`, and the validator works everywhere the built-in ones do, including inside `AnyOf` and `ArrayList`.

```
use serde_json::{Value, json};
use utopia_validators::{ArrayList, Validator};

struct Even;

impl Validator for Even {
    fn description(&self) -> String {
        "Value must be an even integer".to_owned()
    }

    fn is_valid(&self, value: &Value) -> bool {
        value.as_i64().is_some_and(|n| n % 2 == 0)
    }
}

let sizes = ArrayList::new(Even, 0);
assert!(sizes.is_valid(&json!([2, 4, 8])));
assert!(!sizes.is_valid(&json!([2, 3])));
assert_eq!(sizes.description(), "Value must a valid array and Value must be an even integer");
```

```php
use Utopia\Validator\ArrayList;
use Utopia\Validator\Validator;

class Even extends Validator
{
    public function getDescription(): string
    {
        return 'Value must be an even integer';
    }

    public function isValid(mixed $value): bool
    {
        return \is_int($value) && $value % 2 === 0;
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_INTEGER;
    }
}

$sizes = new ArrayList(new Even(), 0);
var_dump($sizes->isValid([2, 4, 8])); // bool(true)
var_dump($sizes->isValid([2, 3]));    // bool(false)
echo $sizes->getDescription(), "\n"; // Value must a valid array and Value must be an even integer
```
