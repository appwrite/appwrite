# Utopia Domains

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Domain names split against the Public Suffix List.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-domains = { git = "https://github.com/appwrite/appwrite" }
```

## Split a domain into its parts

`Domain::new` lower-cases the name and finds its public suffix. `registerable` is what someone actually owns: empty when the suffix is unknown.

```rust
use utopia_domains::Domain;

let domain = Domain::new("Shop.Example.CO.UK")?;

assert_eq!(domain.get(), "shop.example.co.uk");
assert_eq!(domain.tld(), "uk");
assert_eq!(domain.suffix(), "co.uk");
assert_eq!(domain.name(), "example");
assert_eq!(domain.sub(), "shop");
assert_eq!(domain.registerable(), "example.co.uk");
assert!(domain.is_known() && domain.is_icann());
```

## Tell private platforms and test domains apart

The list also has a private section for platforms that hand out subdomains (`github.io`, cloud providers): each user's site is then its own registrable domain. Test and local names have no suffix at all.

```rust
use utopia_domains::Domain;

let site = Domain::new("my-site.github.io")?;
assert!(site.is_private());
assert_eq!(site.registerable(), "my-site.github.io");

let local = Domain::new("api.localhost")?;
assert!(local.is_test() && !local.is_known());
assert_eq!(local.registerable(), "");
```

## Contributing

### Test

```bash
cargo test -p utopia-domains
cargo clippy -p utopia-domains --all-targets -- -D warnings
cargo fmt --all --check
```

### Compare with PHP

The utopia PHP extension serves this crate as `Utopia\Domains\Domain`. Its parity corpus, `crates/extension/tests/domains.php`, checks every rule of `packages/domains/data/data.php` on PHP's class and on the extension; `crates/extension/tests/run.sh` runs it in the development image. To time both implementations:

```bash
python3 crates/extension/bench/run.py crates/extension/bench/domains.php . target/release/libutopia.so 5
```

### Public Suffix List

`data/public-suffix-list.txt` is `packages/domains/data/data.php` in the crate's format. After refreshing the PHP list, regenerate it from the repository root:

```bash
php crates/utopia/domains/data/export.php > crates/utopia/domains/data/public-suffix-list.txt
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
