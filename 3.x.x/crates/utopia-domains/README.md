# utopia-domains

Domain parsing, Public Suffix List matching, and registrar adapters for Utopia. Rust port of [utopia-php/domains](https://github.com/utopia-php/domains).

## Install

```toml
utopia-domains = { path = "../utopia-domains" } # workspace
```

## Usage

```rust
use utopia_domains::Domain;

let domain = Domain::new("demo.example.co.uk")?;
assert_eq!(domain.get(), "demo.example.co.uk");
assert_eq!(domain.get_tld(), "uk");
assert_eq!(domain.get_suffix(), "co.uk");
assert_eq!(domain.get_registerable(), "example.co.uk");
assert_eq!(domain.get_name(), "example");
assert_eq!(domain.get_sub(), "demo");
assert!(domain.is_known());
assert!(domain.is_icann());
assert!(!domain.is_private());
assert!(!domain.is_test());
```

```rust
use utopia_domains::registrar::{Contact, Mock};
use utopia_domains::Registrar;

let registrar = Registrar::new(Mock::default_mock());
assert!(registrar.available("brand-new-name.com")?);

let contact = Contact::new(
    "Ada", "Lovelace", "+18035551212", "ada@example.com",
    "1 Computation Way", "", "", "London", "LN", "GB", "SW1A 1AA",
    "Analytical Engines", None,
);
let order_id = registrar.purchase(
    "brand-new-name.com",
    contact,
    1,
    Vec::new(),
    false,
    None,
)?;
```

The parser embeds the Public Suffix List (`data/psl.json`, converted from PHP `data/data.php`) and loads it lazily into a `OnceLock<HashMap>`. Matching order is identical to PHP: exception `!joined`, exact `joined`, then wildcard `*.next`.

### Data sync CLI

PHP refreshes the list with `php ./data/import.php`. Rust:

```bash
cargo run -p utopia-domains --bin utopia-domains-sync -- psl --commit=true
```

Writes `data/psl.json`. `--commit=false` (default) downloads and diffs without writing. `--force=true` rewrites even when unchanged. GitHub Action: **`sync/data/domains`** (`.github/workflows/sync.data.domains.yml`), weekly + `workflow_dispatch`, opens PR `sync/data/domains-psl`.

## API Reference

### `Domain`

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(domain: impl AsRef<str>) -> Result<Domain, DomainsError>` | Parse a hostname. Rejects `http://` / `https://` prefixes. Stores Unicode-lowercased input. |
| `get` | `fn get(&self) -> &str` | Full domain string. |
| `get_apex` | `fn get_apex(&self) -> String` | `{name}.{suffix}`. |
| `get_tld` | `fn get_tld(&self) -> &str` | Right-most label. |
| `get_suffix` | `fn get_suffix(&self) -> &str` | Public suffix (`co.uk`, `com`, `*.ck` match, …). |
| `get_rule` | `fn get_rule(&self) -> &str` | Matching PSL rule, including `!` / `*.` prefixes. |
| `get_registerable` | `fn get_registerable(&self) -> String` | Registrable domain, or empty when unknown. |
| `get_name` | `fn get_name(&self) -> &str` | Registrable label. |
| `get_sub` | `fn get_sub(&self) -> String` | Subdomain path. |
| `is_known` | `fn is_known(&self) -> bool` | PSL rule matched. |
| `is_icann` | `fn is_icann(&self) -> bool` | Rule is in the ICANN section. |
| `is_private` | `fn is_private(&self) -> bool` | Rule is in the PRIVATE section. |
| `is_test` | `fn is_test(&self) -> bool` | TLD is `test` or `localhost`. |

### Validators (`utopia_validators::Validator`)

| Type | PHP | Description |
|------|-----|-------------|
| `PublicDomain` | `Utopia\Domains\Validator\PublicDomain` | Known PSL domain, or a host on the static allow-list. |
| `ApexDomain` | `Utopia\Domains\Validator\ApexDomain` | Public domain whose value equals `get_apex()`. |

`PublicDomain::allow(domains)` appends to a process-wide allow-list (PHP static `$allowedDomains`). `reset_allowed()` clears it for tests. HTTP(S) URLs are reduced to their host, matching PHP `filter_var(FILTER_VALIDATE_URL)` + `parse_url(PHP_URL_HOST)`.

### `Registrar`

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` / `new_with` | adapter, optional nameservers / cache / timeouts | PHP constructor. |
| `get_name` | `fn get_name(&self) -> String` | Adapter name (`mock`, `namecom`, `opensrs`). |
| `available` | `fn available(&self, domain: &str) -> Result<bool, DomainsError>` | Availability check. |
| `purchase` | `fn purchase(..., period_years, nameservers, autorenew, purchase_price) -> Result<String, DomainsError>` | Purchase; returns order id. |
| `suggest` | `fn suggest(query, tlds, limit, filter_type, price_max, price_min)` | Suggestions / premium search. |
| `tlds` | `fn tlds(&self) -> Result<Vec<String>, DomainsError>` | Supported TLDs (empty for Name.com / OpenSRS). |
| `get_domain` | `fn get_domain(&self, domain: &str) -> Result<RegistrarDomain, DomainsError>` | Domain details. |
| `update_domain` | `fn update_domain(&self, domain: &str, details: &UpdateDetails) -> Result<bool, DomainsError>` | Auto-renew and similar. |
| `update_nameservers` | `fn update_nameservers(&self, domain: &str, nameservers: Vec<String>) -> Result<NameserverUpdate, DomainsError>` | Nameserver update. |
| `get_price` | `fn get_price(&self, domain, period_years, reg_type, ttl) -> Result<Price, DomainsError>` | Cached when a `Cache` is attached. |
| `renew` | `fn renew(&self, domain: &str, period_years: i64) -> Result<Renewal, DomainsError>` | Renewal. |
| `transfer` | `fn transfer(&self, domain, auth_code, purchase_price) -> Result<String, DomainsError>` | Transfer; returns order id. |
| `get_auth_code` | `fn get_auth_code(&self, domain: &str) -> Result<String, DomainsError>` | EPP auth code. |
| `cancel_purchase` | `fn cancel_purchase(&self) -> Result<bool, DomainsError>` | Cancel pending orders. |
| `check_transfer_status` | `fn check_transfer_status(&self, domain: &str) -> Result<TransferStatus, DomainsError>` | Transfer status. |

Constants: `REG_TYPE_NEW`, `REG_TYPE_TRANSFER`, `REG_TYPE_RENEWAL`, `REG_TYPE_TRADE` (`"new"` / `"transfer"` / `"renewal"` / `"trade"`).

### Adapters

| Adapter | PHP | Notes |
|---------|-----|-------|
| `Mock` | `Adapter\Mock` | In-memory registrar used by PHP `MockTest`. |
| `NameCom` | `Adapter\NameCom` | HTTP JSON + basic auth via [`utopia-client`](../utopia-client). Request paths match PHP (`/core/v1/domains:checkAvailability`, …). |
| `OpenSrs` | `Adapter\OpenSRS` | HTTP XML (OPS envelope) via [`utopia-client`](../utopia-client) + `X-Signature` = `md5(md5(xml+key)+key)`. |

`Adapter` is a trait. Default `update_nameservers` returns `"Method not implemented"`.

### Cache

PHP wraps [`utopia-cache`](../utopia-cache). This crate implements [`CacheStore`] for [`utopia_cache::Cache`] as well as in-crate [`MemoryCache`] / [`NoneCache`]. `Cache` prefixes keys with `domain:` and exposes PHP `load` / `save` / `purge`.

### Errors

`DomainsError` variants map to PHP exception classes: `InvalidDomain`, `Generic` (`Utopia\Domains\Exception`), `Auth`, `DomainNotFound`, `DomainNotTransferable`, `DomainTaken`, `InvalidAuthCode`, `InvalidContact`, `InvalidPeriod`, `PriceNotFound`, `RateLimit`, `UnsupportedTld`. Use `.code()` for PHP `getCode()`.

## Tests

```bash
cargo test --manifest-path crates/utopia-domains/Cargo.toml
```

Ports PHP `tests/DomainTest.php`, `tests/Validator/*`, `tests/Registrar/Base.php` + `MockTest.php`. Name.com / OpenSRS e2e uses [utopia-test-wiremock](../utopia-test-wiremock) (WireMock 3.12.1) with the same HTTP request shapes (no live registrar credentials).

## Benchmarks

```bash
cargo bench --manifest-path crates/utopia-domains/Cargo.toml
```

Prints `domain_new`, `domain_suffix`, and `domain_registerable` ops/s for several hostnames (PHP twin: `benchmarks/domains/`).

## Code quality

- **rustfmt** - `cargo fmt --manifest-path crates/utopia-domains/Cargo.toml`
- **Clippy** - `cargo clippy --manifest-path crates/utopia-domains/Cargo.toml --all-targets -- -D warnings`
- Inherits workspace lint policy (`[lints] workspace = true`).

## License

MIT - see [LICENSE](LICENSE).
