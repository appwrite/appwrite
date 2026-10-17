# Utopia Validators

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/validators`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/validators) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Request parameter validators with their user-facing messages.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-validators = { git = "https://github.com/utopia-rs/validators" }
```

Validators check request parameters before an action runs: a name is a short string, a limit is a number in range, a URL uses https. Each validator answers two questions: is this value valid, and what rule does it follow. The rule is a human-readable description that becomes the error message a client sees, so it is part of the API. Values are `serde_json::Value`, the form a decoded request body takes. This guide covers the common tasks.

## Validate a text field

`Text` accepts a string between a minimum and a maximum number of characters. A maximum of 0 means no limit, and the minimum defaults to 1.

```rust
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

## Validate numbers in a range

`Range` accepts an integer between two bounds, inclusive. `Integer` accepts any integer that fits a number of bits. `Range` also accepts numeric strings, as query strings carry them. `Integer` accepts them only when loose.

```rust
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
```

## Accept one of a fixed set of values

`WhiteList` accepts a value from a list. By default it ignores case. A strict list needs an exact match.

```rust
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

## Validate a list of values

`ArrayList` accepts an array whose items all pass another validator. Its second argument caps the number of items; 0 means no cap. The description includes the item rule.

```rust
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

## Validate URLs, hosts and IPs

`Url` accepts a URL, optionally only with some schemes. `Host` accepts a URL whose host is on an allow list. `Ip` accepts an IPv4 or IPv6 address, or only one of them.

```rust
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

## Accept null for an optional value

`Nullable` wraps a validator so `null` passes too. Use it when a client may clear a field by sending `null`.

```rust
use serde_json::json;
use utopia_validators::{Nullable, Text, Validator};

let bio = Nullable(Text::new(256));

assert!(bio.is_valid(&json!(null)));
assert!(bio.is_valid(&json!("Hello")));
assert!(!bio.is_valid(&json!("")));
assert_eq!(bio.description(), "Value must be a valid string and at least 1 chars and no longer than 256 chars or null");
```

## Combine validators

`AnyOf` passes when one rule passes, and `AllOf` when every rule does. After a failure, the description names the rule that decided. In Rust, `check` returns that description, because validators keep no state between calls.

```rust
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

## Show the error message a user sees

A request handler validates each parameter and, on failure, reports the description. `check` returns `Err` with the message to show.

```rust
use serde_json::json;
use utopia_validators::{Range, Validator};

let limit = Range::new(1, 100);
let message = match limit.check(&json!(500)) {
    Ok(()) => String::new(),
    Err(description) => format!("Invalid `limit` param: {description}"),
};
assert_eq!(message, "Invalid `limit` param: Value must be a valid range between 1 and 100");
```

## Validate JSON objects

`json::Object` accepts a JSON object, either decoded or still encoded as a string. `Json` accepts any valid JSON, including scalars, so prefer the shape-specific validator when the value must be an object.

```rust
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

## Write your own validator

Implement `description` and `is_valid`, and the validator works everywhere the built-in ones do, including inside `AnyOf` and `ArrayList`.

```rust
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

## License

BSD-3-Clause. See [LICENSE](LICENSE).
