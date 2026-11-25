# Utopia Validators

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/validators`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/validators) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

The checks of Utopia's request validators: booleans, integers, floats, numbers, ranges, ports, hex colors, text, identifiers, white lists, substrings, DNS labels, hostnames, domains, hosts, IP addresses, URLs, phone numbers, JSON, FCM service accounts, globstar patterns and the validators that compose others.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-validators = { git = "https://github.com/utopia-rs/validators" }
```

## Check a value

Each function is the body of a PHP validator's method, over a value as PHP sees it (`Input`), with the same coercions, comparisons and messages.

```rust
use utopia_validators::{Input, Integer, Number, range};

let int8 = Integer::new(8, false).unwrap();
assert!(int8.accepts(true, Input::Str(b" 127 ")));
assert!(!int8.accepts(true, Input::Float(1.0)));
assert!(range(Number::Int(1), Number::Int(10), b"integer", Input::Str(b"5")));
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/validators`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/validators). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-validators
cargo clippy -p utopia-validators --all-targets -- -D warnings
cargo fmt --all --check
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
