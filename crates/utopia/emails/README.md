# Utopia Emails

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/emails`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/emails) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Email address parsing, canonical forms per provider, free, disposable and corporate classification, and validators.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-emails = { git = "https://github.com/utopia-rs/emails" }
```

Utopia Emails parses email addresses and answers the questions an application asks about them: is the address well formed, what is its canonical form at its mail provider, and is its domain free, disposable or corporate. It also ships validators for request parameters. This guide covers the common tasks.

## Validate an address from a sign-up form

`EmailValidator` accepts a string that parses as a valid address. Surrounding spaces and upper case are fine, because the address is trimmed and lower-cased first.

```rust
use utopia_emails::EmailValidator;

let validator = EmailValidator::default();

assert!(validator.is_valid_address("  Jane.Doe@Example.com "));
assert!(!validator.is_valid_address("jane.doe"));
assert!(!validator.is_valid_address("jane@doe@example.com"));
assert!(!validator.is_valid_address(""));

// An optional field: allow it to stay empty.
assert!(EmailValidator::new(true).is_valid_address(""));
```

## Read the parts of an address

Parse the address once, then read its local part, its domain, and the provider and subdomain inside the domain. `formatted` returns any one of them by name.

```rust
use utopia_emails::{Email, Format};

let email = Email::new("  Jane@Mail.Example.co.uk ")?;

assert_eq!(email.get(), "jane@mail.example.co.uk");
assert_eq!(email.local(), "jane");
assert_eq!(email.domain(), "mail.example.co.uk");
assert_eq!(email.provider(), "example.co.uk");
assert_eq!(email.subdomain(), "mail");
assert!(email.has_subdomain());
assert_eq!(email.formatted(Format::Provider), "example.co.uk");
assert_eq!(email.formatted(Format::from_name("local")), "jane");
```

## Check the local part and the domain

`FILTER_VALIDATE_EMAIL` allows more than most applications want. `has_valid_local` allows only letters, digits and `._+-` with no stray dots, and `has_valid_domain` requires a known public suffix. `EmailLocal` and `EmailDomain` apply the same rules as validators.

```rust
use utopia_emails::{Email, EmailDomain, EmailLocal};

let email = Email::new("o'brien@example.com")?;
assert!(email.is_valid());
assert!(!email.has_valid_local());
assert!(email.has_valid_domain());

assert!(EmailLocal.is_valid_address("jane.doe+news@example.com"));
assert!(!EmailLocal.is_valid_address("o'brien@example.com"));
assert!(EmailDomain.is_valid_address("jane@mail.example.com"));
assert!(!EmailDomain.is_valid_address("jane@example.notatld"));
```

## Normalize an address before storing it

Many providers deliver several spellings to the same inbox. Gmail ignores dots and `+tags`, and Outlook ignores `+tags`. Store `canonical` to stop one person from signing up many times. Unknown domains keep their address as is.

```rust
use utopia_emails::Email;

let canonical = |address: &str| Email::new(address)?.canonical();

assert_eq!(canonical("Jane.Doe+promo@googlemail.com")?, "janedoe@gmail.com");
assert_eq!(canonical("jane.doe+promo@hotmail.co.uk")?, "jane.doe@outlook.com");
assert_eq!(canonical("jane.doe+promo@me.com")?, "jane.doe@icloud.com");
assert_eq!(canonical("jane.doe+promo@example.com")?, "jane.doe+promo@example.com");
```

## Find the mail provider behind a domain

`is_canonical_supported` says whether a provider with aliasing rules owns the domain, and `canonical_domain` names that provider's main domain. Use it to group users by provider.

```rust
use utopia_emails::Email;

let email = Email::new("jane@live.com")?;
assert!(email.is_canonical_supported());
assert_eq!(email.canonical_domain(), Some("outlook.com"));

let email = Email::new("jane@example.com")?;
assert!(!email.is_canonical_supported());
assert_eq!(email.canonical_domain(), None);
```

## Reject disposable domains

Each address is free (a public mail service), disposable (a throwaway inbox) or corporate (anything else). Use `EmailNotDisposable` to block throwaway inboxes, and `EmailCorporate` when only work addresses are welcome.

```rust
use utopia_emails::{Email, EmailCorporate, EmailNotDisposable};

let email = Email::new("jane@mailinator.com")?;
assert!(email.is_disposable());
assert!(!email.is_free());

let email = Email::new("jane@gmail.com")?;
assert!(email.is_free());
assert!(!email.is_corporate());

assert!(!EmailNotDisposable.is_valid_address("jane@10minutemail.com"));
assert!(EmailNotDisposable.is_valid_address("jane@gmail.com"));
assert!(!EmailCorporate.is_valid_address("jane@gmail.com"));
assert!(EmailCorporate.is_valid_address("jane@company.org"));
```

## Report addresses that cannot be parsed

Parsing fails on an empty value or one without exactly one `@`. Normalizing fails when a provider's rules leave nothing of the local part. Each error carries a message you can show.

```rust
use utopia_emails::{Email, Error};

for value in ["", "jane.doe", "jane@doe@example.com"] {
    match Email::new(value) {
        Err(Error::Parse(_)) => {}
        other => panic!("expected a parse error, got {other:?}"),
    }
}
assert_eq!(Email::new("jane.doe").unwrap_err().to_string(), "'jane.doe' must be a valid email address");

let error = Email::new("...@gmail.com")?.canonical().unwrap_err();
assert!(matches!(error, Error::InvalidArgument(_)));
assert_eq!(error.to_string(), "Email local part cannot be empty after normalization");
```

## Add rules for your own mail provider

Implement `Provider` for a domain the built-in providers do not know, then pass it to `canonical_with` ahead of the defaults. The first provider that supports the domain wins.

```rust
use utopia_emails::{Canonical, Email, Error, Provider, PROVIDERS};

struct Acme;

impl Provider for Acme {
    fn canonical(&self, local: &str, _domain: &str) -> Result<Canonical, Error> {
        let local = local.split('-').next().unwrap_or(local);
        Ok(Canonical { local: local.to_owned(), domain: "acme.com".to_owned() })
    }

    fn canonical_domain(&self) -> &str {
        "acme.com"
    }

    fn supported_domains(&self) -> &[&str] {
        &["acme.com", "acme.net"]
    }
}

let providers: Vec<&dyn Provider> = std::iter::once(&Acme as &dyn Provider).chain(PROVIDERS.iter().copied()).collect();

assert_eq!(Email::new("jane-news@acme.net")?.canonical_with(&providers)?, "jane@acme.com");
assert_eq!(Email::new("jane+news@gmail.com")?.canonical_with(&providers)?, "jane@gmail.com");
```

## Tasks

The free and disposable domain lists are embedded in the crate (`data/free-domains.txt`, `data/disposable-domains.txt`), so a binary needs no data files at runtime. The `emails-import` task refreshes them from the public lists they are built from, merged with the manual lists next to them (`data/*-domains-manual.txt`); rebuild afterwards to embed them.

```sh
# Preview what would change in the disposable domains, then write it.
cargo run -p utopia-emails --bin emails-import -- disposable
cargo run -p utopia-emails --bin emails-import -- disposable --commit=true

# Both lists from every source, even when nothing changed.
cargo run -p utopia-emails --bin emails-import -- all --commit=true --force=true

# One source only, and statistics about the current lists.
cargo run -p utopia-emails --bin emails-import -- free --source=kikobeats
cargo run -p utopia-emails --bin emails-import -- stats
```

A source that cannot be downloaded is reported and skipped; the task fails (exit code 1) only when no source gives a domain. To add a domain by hand, add a line to the manual list and run the task: an edit to the generated lists is lost on the next import. `UTOPIA_EMAILS_DATA_DIR` reads and writes the lists in another directory, and `UTOPIA_EMAILS_<TYPE>_<SOURCE>_URL` (such as `UTOPIA_EMAILS_DISPOSABLE_MARTENSON_URL`) downloads a source from another address.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
