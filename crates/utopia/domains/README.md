# Utopia Domains

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/domains`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/domains) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Domain names split against the Public Suffix List, domain validators, and registrar clients for availability, prices, purchases, renewals and transfers.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-domains = { git = "https://github.com/utopia-rs/domains" }
```

A domain name such as `blog.example.co.uk` has a structure DNS alone does not tell you: `co.uk` is a public suffix (anyone can register under it), `example` is the registered name, and `blog` is a subdomain. This library reads that structure from the Public Suffix List, validates user input with it, and talks to domain registrars to check availability, quote prices and buy, renew or transfer domains. Registrar calls are async, so those examples run with `utopia_async::run`. This guide covers the common tasks.

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

## Validate domains people type

Three validators cover the usual checks: `PublicDomain` (under a known suffix; a URL is judged by its host), `ApexDomain` (the registrable domain itself) and `RegistrableDomain` (a strict lower-case name that is not a suffix, an IP or a URL).

```rust
use utopia_domains::validator::{ApexDomain, PublicDomain, RegistrableDomain};
use utopia_validators::Validator;

assert!(PublicDomain.is_valid(&"https://www.example.com/login".into()));
assert!(!PublicDomain.is_valid(&"printer.local".into()));

assert!(ApexDomain.is_valid(&"example.com".into()));
assert!(!ApexDomain.is_valid(&"www.example.com".into()));

assert!(RegistrableDomain.is_valid(&"app.example.com".into()));
assert!(!RegistrableDomain.is_valid(&"co.uk".into()));
assert_eq!(RegistrableDomain.description(), "Value must be a lowercase domain name such as \"example.com\". IP addresses and public suffixes are not allowed.");
```

## Check availability and prices

A `Registrar` drives an adapter. The `Mock` adapter keeps its registry in memory, which makes it the one to use in tests; `NameCom` and `OpenSrs` talk to those registrars. Prices are quoted per registration type and period.

```rust
use utopia_domains::registrar::{Mock, RegType, Registrar};

let registrar = Registrar::new(Mock::new());

utopia_async::Runtime::new()?.block_on(async {
    let availability = registrar.available(&["my-shop.com".to_owned(), "google.com".to_owned()]).await?;
    assert!(availability["my-shop.com"]);
    assert!(!availability["google.com"]);

    let price = registrar.price("my-shop.com", 2, RegType::New, 3600).await?;
    assert_eq!(price.price, 25.98);
    assert!(!price.premium);

    let premium = registrar.price("premium.com", 1, RegType::New, 3600).await?;
    assert!(premium.premium);
    Ok::<_, utopia_domains::Error>(())
})?;
```

## Buy a domain

A purchase needs contacts: one `Contact` for every role, or contacts by role (`owner`, `admin`, `tech`, `billing`). The order id comes back; the registrar's errors say why a purchase failed.

```rust
use utopia_domains::Error;
use utopia_domains::registrar::{Contact, Mock, Purchase, Registrar};

let contact = Contact {
    firstname: "Ada".into(), lastname: "Lovelace".into(), phone: "+442071234567".into(),
    email: "ada@example.com".into(), address1: "12 St James's Square".into(), city: "London".into(),
    state: "LDN".into(), country: "GB".into(), postalcode: "SW1Y 4JH".into(), ..Contact::default()
};
let registrar = Registrar::new(Mock::new());

utopia_async::Runtime::new()?.block_on(async {
    let order = registrar.purchase(&Purchase::new("ada-lovelace.com", contact.clone())).await?;
    assert!(order.starts_with("mock_"));

    let taken = registrar.purchase(&Purchase::new("google.com", contact)).await.unwrap_err();
    assert!(matches!(taken, Error::DomainTaken { .. }));
    assert_eq!(taken.to_string(), "Domain google.com is not available for registration");
    Ok::<_, Error>(())
})?;
```

## Cache prices

Give the registrar a `Cache` and price lookups are answered from it for as long as the `ttl` allows. `MemoryStore` keeps entries in the process; implement `Store` to keep them anywhere else.

```rust
use utopia_domains::registrar::{Config, Mock, RegType, Registrar};
use utopia_domains::{Cache, MemoryStore};

let cache = Cache::new(MemoryStore::new());
let registrar = Registrar::with_config(Mock::new(), Config { cache: Some(cache.clone()), ..Config::default() });

utopia_async::Runtime::new()?.block_on(async {
    registrar.price("my-shop.com", 1, RegType::New, 3600).await?;
    let cached = cache.load("my-shop.com", 3600).await.expect("the price is cached");
    assert_eq!(cached["price"], 12.99);
    Ok::<_, utopia_domains::Error>(())
})?;
```

## Route registrar traffic through your own transport

The API adapters send requests through a `Transport`. Pass one to record, replay or proxy their traffic; without one they use `HttpTransport` with the registrar's timeouts.

```rust
use std::sync::{Arc, Mutex};
use utopia_domains::BoxFuture;
use utopia_domains::registrar::{NameCom, Registrar};
use utopia_domains::transport::{Request, Response, Transport, TransportError};

/// Answers every request with `{"authCode": "secret"}` and remembers the URLs.
#[derive(Default)]
struct Replay(Mutex<Vec<String>>);

impl Transport for Replay {
    fn send<'a>(&'a self, request: Request) -> BoxFuture<'a, Result<Response, TransportError>> {
        self.0.lock().unwrap().push(request.url);
        Box::pin(async { Ok(Response { status: 200, headers: vec![], body: br#"{"authCode":"secret"}"#.to_vec() }) })
    }
}

let replay = Arc::new(Replay::default());
let registrar = Registrar::new(NameCom::new("user", "token", "https://api.dev.name.com", Some(replay.clone())));

let code = utopia_async::Runtime::new()?.block_on(registrar.auth_code("example.com"))?;
assert_eq!(code, "secret");
assert_eq!(*replay.0.lock().unwrap(), ["https://api.dev.name.com/core/v1/domains/example.com:getAuthCode"]);
```

## Tasks

The Public Suffix List changes every week as registries and platforms add suffixes. The crate embeds its own copy, `data/public-suffix-list.txt`, so a binary needs no data files at runtime. The `domains-import` task downloads the current list and rewrites that file; rebuild afterwards to embed it.

```sh
cargo run -p utopia-domains --bin domains-import
```

It keeps every rule with its section (`ICANN`, `PRIVATE`) and the comment lines above it, and fails without writing anything (exit code 1, the reason on standard error) when the list cannot be downloaded or has no `com` rule. `UTOPIA_DOMAINS_PSL_URL` downloads from another address, such as a mirror, and `UTOPIA_DOMAINS_DATA_DIR` writes the list to another directory.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
