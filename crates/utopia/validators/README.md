# utopia-validators

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/validators`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/validators) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there: please open issues and pull requests in that repository.

The checks of [`utopia-php/validators`](../../../packages/validators) as PHP runs them: booleans,
integers, floats, numbers, ranges, ports, hex colors, text, identifiers, white lists, substrings,
DNS labels, hostnames, domains, hosts, IP addresses, URLs and phone numbers (over `php-std`'s ports of
`filter_var()`, `parse_url()` and `Uri\Rfc3986\Uri`). Each function is the body of a PHP
validator's method, over a value as PHP sees it (`Input`), with the same coercions
(`is_numeric()`, `$value + 0`), comparisons and messages. The validator classes, and the state
their constructors keep, live in the PHP extension (`crates/extension`), which passes it in.

```rust
use utopia_validators::{Input, Integer, Number, range};

let int8 = Integer::new(8, false).unwrap();
assert!(int8.accepts(true, Input::Str(b" 127 ")));
assert!(!int8.accepts(true, Input::Float(1.0)));
assert!(range(Number::Int(1), Number::Int(10), b"integer", Input::Str(b"5")));
```
