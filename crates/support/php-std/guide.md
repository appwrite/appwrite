php-std reproduces PHP built-in functions and value semantics in Rust, byte for byte. When you port PHP code, call the php-std twin of each built-in instead of writing your own approximation, and the Rust code gives the same answer as PHP, edge cases included. Strings are bytes (`&[u8]`), because PHP strings are bytes. This guide covers the common tasks.

## Parse a URL like parse_url

`parse_url` splits a URL into its parts. A missing part is `None`, and a URL PHP rejects is `None` as a whole, where PHP returns `false`.

```
use php_std::url::parse_url;

let url = parse_url(b"https://user:secret@cloud.appwrite.io:8443/v1/users?limit=25#top").ok_or("invalid URL")?;

assert_eq!(url.scheme().as_deref(), Some(&b"https"[..]));
assert_eq!(url.host().as_deref(), Some(&b"cloud.appwrite.io"[..]));
assert_eq!(url.port(), Some(8443));
assert_eq!(url.path().as_deref(), Some(&b"/v1/users"[..]));
assert_eq!(url.query().as_deref(), Some(&b"limit=25"[..]));
assert!(parse_url(b"http://:80").is_none());
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
$url = parse_url('https://user:secret@cloud.appwrite.io:8443/v1/users?limit=25#top');

echo $url['scheme'], "\n"; // https
echo $url['host'], "\n";   // cloud.appwrite.io
echo $url['port'], "\n";   // 8443
echo $url['path'], "\n";   // /v1/users
echo $url['query'], "\n";  // limit=25
var_dump(parse_url('http://:80')); // bool(false)
```

## Match a regular expression like preg_match

Patterns take PHP's delimiters and modifiers, and run on a port of the PCRE2 engine PHP bundles. Compile a pattern once with `Regex::compiled` and read the groups of a match.

```
use php_std::pcre::Regex;

let semver = Regex::compiled(b"/^(\\d+)\\.(\\d+)\\.(\\d+)$/").map_err(|_| "invalid pattern")?;

let version = semver.captures(b"1.6.2").ok_or("no match")?;
assert_eq!(version.get(0), Some(&b"1.6.2"[..]));
assert_eq!(version.get(2), Some(&b"6"[..]));
assert!(!semver.is_match(b"1.6"));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
$semver = '/^(\d+)\.(\d+)\.(\d+)$/';

preg_match($semver, '1.6.2', $version);
echo $version[0], "\n"; // 1.6.2
echo $version[2], "\n"; // 6
var_dump(preg_match($semver, '1.6')); // int(0)
```

## Replace text like preg_replace

`preg_replace` takes the pattern, replacement and subject as a string or an array, like PHP. The result holds the new value and the number of replacements.

```
use php_std::pcre::{StrOrArray, Value, preg_replace};

let replaced = preg_replace(
    &StrOrArray::Str(b"/[^a-z0-9]+/i"),
    &StrOrArray::Str(b"-"),
    &StrOrArray::Str(b"Hello, World 2026!"),
    -1,
)?;

assert_eq!(replaced.value.result, Value::Str(b"Hello-World-2026-".to_vec()));
assert_eq!(replaced.value.count, 3);
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
$result = preg_replace('/[^a-z0-9]+/i', '-', 'Hello, World 2026!', -1, $count);

echo $result, "\n"; // Hello-World-2026-
echo $count, "\n";  // 3
```

## Encode and decode JSON like json_encode

`json::encode` and `json::decode` work on `Value`, PHP's value model. Floats, escaping and flags follow PHP, and a failure returns the error PHP reports.

```
use php_std::json::{self, DEFAULT_DEPTH, Flags};
use php_std::{Array, Value};

let mut user: Array = Array::new();
user.set("name".into(), Value::from("Ana"));
user.set("score".into(), Value::from(4.5));
user.set("site".into(), Value::from("https://appwrite.io"));

let text = json::encode(&Value::Array(user.clone()), Flags::NONE, DEFAULT_DEPTH)?;
assert_eq!(text, r#"{"name":"Ana","score":4.5,"site":"https:\/\/appwrite.io"}"#);

let text = json::encode(&Value::Array(user), Flags::UNESCAPED_SLASHES, DEFAULT_DEPTH)?;
assert_eq!(text, r#"{"name":"Ana","score":4.5,"site":"https://appwrite.io"}"#);

let decoded = json::decode(br#"{"tags":["a","b"]}"#, Some(true), DEFAULT_DEPTH, Flags::NONE)?;
let Value::Array(decoded) = decoded else { panic!("expected an array") };
assert!(matches!(decoded.get_str("tags"), Some(Value::Array(tags)) if tags.len() == 2));

let error = json::decode(b"{bad", None, DEFAULT_DEPTH, Flags::NONE).unwrap_err();
assert_eq!(error.message(), "Syntax error");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
$user = ['name' => 'Ana', 'score' => 4.5, 'site' => 'https://appwrite.io'];

echo json_encode($user), "\n";                         // {"name":"Ana","score":4.5,"site":"https:\/\/appwrite.io"}
echo json_encode($user, JSON_UNESCAPED_SLASHES), "\n"; // {"name":"Ana","score":4.5,"site":"https://appwrite.io"}

$decoded = json_decode('{"tags":["a","b"]}', true);
echo count($decoded['tags']), "\n"; // 2

json_decode('{bad');
echo json_last_error_msg(), "\n"; // Syntax error
```

## Compare values like ==

PHP 8's `==` compares numeric strings as numbers and other strings as text. `Value::loose_eq` is `==`, `Value::strict_eq` is `===`, and `Value::cmp_php` is `<=>`.

```
use std::cmp::Ordering;

use php_std::Value;

let hundred: Value = Value::from(100);
let exponent: Value = Value::from("1e2");
let zero: Value = Value::from(0);
let lower: Value = Value::from("abc");
let ten: Value = Value::from("10");
let null: Value = Value::Null;

assert!(hundred.loose_eq(&exponent));
assert!(!hundred.strict_eq(&exponent));
assert!(!zero.loose_eq(&Value::from("a")));
assert!(!lower.loose_eq(&Value::from("ABC")));
assert!(null.loose_eq(&Value::from(false)));
assert_eq!(ten.cmp_php(&Value::from("9")), Ordering::Greater);
```

```php
var_dump(100 == '1e2');    // bool(true)
var_dump(100 === '1e2');   // bool(false)
var_dump(0 == 'a');        // bool(false)
var_dump('abc' == 'ABC');  // bool(false)
var_dump(null == false);   // bool(true)
var_dump('10' <=> '9');    // int(1)
```

## Format numbers like number_format

`number_format` rounds half away from zero and groups the digits by three. `sprintf` takes PHP's format string and arguments.

```
use php_std::format::{Arg, number_format, sprintf};
use php_std::value::Number;

assert_eq!(number_format(Number::Float(1234567.891), 2, b".", b","), b"1,234,567.89");
assert_eq!(number_format(Number::Float(1234.5), 0, b".", b","), b"1,235");
assert_eq!(number_format(Number::Int(1234567), 2, b",", b" "), b"1 234 567,00");

let line = sprintf(b"%-8s|%05.1f|%x", &[Arg::Str(b"cpu"), Arg::Float(3.14159), Arg::Int(255)])?;
assert_eq!(line, b"cpu     |003.1|ff");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
echo number_format(1234567.891, 2, '.', ','), "\n"; // 1,234,567.89
echo number_format(1234.5, 0, '.', ','), "\n";      // 1,235
echo number_format(1234567, 2, ',', ' '), "\n";     // 1 234 567,00

echo sprintf('%-8s|%05.1f|%x', 'cpu', 3.14159, 255), "\n"; // cpu     |003.1|ff
```

## Work with multibyte strings

The `mb` functions count and cut UTF-8 characters, not bytes. `strlen` is the byte length of the slice.

```
use php_std::mb::{mb_strlen, mb_strtoupper, mb_substr};

let name = "Zoë Ångström".as_bytes();

assert_eq!(name.len(), 15);
assert_eq!(mb_strlen(name), 12);
assert_eq!(mb_substr(name, 0, Some(3))?, "Zoë".as_bytes());
assert_eq!(mb_strtoupper(name), "ZOË ÅNGSTRÖM".as_bytes());
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
$name = 'Zoë Ångström';

echo strlen($name), "\n";          // 15
echo mb_strlen($name), "\n";       // 12
echo mb_substr($name, 0, 3), "\n"; // Zoë
echo mb_strtoupper($name), "\n";   // ZOË ÅNGSTRÖM
```

## Parse a query string like parse_str

`parse_str` decodes a query string into an array the way PHP fills `$_GET`. Dots and spaces in names become `_`, and `name[]` builds a nested array.

```
use php_std::encoding::parse_str;
use php_std::zval::{Key, Zval};

let query = parse_str(b"user.name=Ana+Lee&tags[]=a&tags[]=b");

assert_eq!(query.get(&Key::from_bytes(b"user_name")), Some(&Zval::String(b"Ana Lee".to_vec())));
let Some(Zval::Array(tags)) = query.get(&Key::from_bytes(b"tags")) else { panic!("expected an array") };
assert_eq!(tags.get(&Key::Int(1)), Some(&Zval::String(b"b".to_vec())));
```

```php
parse_str('user.name=Ana+Lee&tags[]=a&tags[]=b', $query);

echo $query['user_name'], "\n"; // Ana Lee
echo $query['tags'][1], "\n";   // b
```

## Validate input like filter_var

`filter_var` takes the value, the filter and its flags or options, with PHP's constants. A failed validation returns `false`, like PHP.

```
use php_std::filter::{
    FILTER_FLAG_IPV4, FILTER_VALIDATE_EMAIL, FILTER_VALIDATE_INT, FILTER_VALIDATE_IP, Key, Options, Value, filter_var,
};

let email = filter_var(&Value::Str(b"ana@appwrite.io".to_vec()), FILTER_VALIDATE_EMAIL, &Options::Flags(0))?;
assert_eq!(email.value, Value::Str(b"ana@appwrite.io".to_vec()));

let ip = filter_var(&Value::Str(b"::1".to_vec()), FILTER_VALIDATE_IP, &Options::Flags(FILTER_FLAG_IPV4))?;
assert_eq!(ip.value, Value::Bool(false));

let range = Options::Array(vec![(
    Key::Str(b"options".to_vec()),
    Value::Array(vec![(Key::Str(b"min_range".to_vec()), Value::Int(1)), (Key::Str(b"max_range".to_vec()), Value::Int(100))]),
)]);
let limit = filter_var(&Value::Str(b" 25 ".to_vec()), FILTER_VALIDATE_INT, &range)?;
assert_eq!(limit.value, Value::Int(25));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
var_dump(filter_var('ana@appwrite.io', FILTER_VALIDATE_EMAIL)); // string(15) "ana@appwrite.io"
var_dump(filter_var('::1', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)); // bool(false)

$range = ['options' => ['min_range' => 1, 'max_range' => 100]];
var_dump(filter_var(' 25 ', FILTER_VALIDATE_INT, $range)); // int(25)
```
