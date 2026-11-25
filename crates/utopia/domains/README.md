# Utopia Domains

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/domains`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/domains) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there: please open issues and pull requests in that repository.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Domain names split against the Public Suffix List.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-domains = { git = "https://github.com/utopia-rs/domains" }
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

The utopia PHP extension serves this crate as `Utopia\Domains\Domain`, which no longer has a PHP implementation. `crates/extension/tests/run.sh` runs `packages/domains/tests` on it in the development image.

### Public Suffix List

`data/public-suffix-list.txt` is `packages/domains/data/data.php` in the crate's format. After refreshing the package's list, regenerate it from the repository root:

```bash
php crates/utopia/domains/data/export.php > crates/utopia/domains/data/public-suffix-list.txt
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
