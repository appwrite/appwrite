# utopia-emails

Email parsing, classification, and canonicalization for Utopia. Rust port of [utopia-php/emails](https://github.com/utopia-php/emails).

Parses addresses the same way PHP does (`trim` + `mb_strtolower`, split on `@`), classifies free / disposable / corporate domains from the shipped domain lists, and produces provider-specific canonical forms (Gmail dots, plus-addressing, Yahoo hyphen tags, …).

## Install

```toml
utopia-emails = { path = "../utopia-emails" }
```

## Usage

```rust
use utopia_emails::Email;

let email = Email::new("  USER.NAME+tag@gmail.com  ").unwrap();
assert_eq!(email.get(), "user.name+tag@gmail.com");
assert_eq!(email.get_local(), "user.name+tag");
assert_eq!(email.get_domain(), "gmail.com");
assert!(email.is_valid());
assert!(email.is_free());
assert_eq!(email.get_canonical().unwrap(), "username@gmail.com");
```

Validators implement [`utopia_validators::Validator`](../utopia-validators):

```rust
use serde_json::json;
use utopia_emails::EmailValidator;
use utopia_validators::Validator;

let v = EmailValidator::new(false);
assert!(v.is_valid(&json!("user@example.com")));
```

## API Reference

### `Email`

| Item | Signature / value | Description |
|------|-------------------|-------------|
| `LOCAL_MAX_LENGTH` | `64` | Max local-part characters (`mb_strlen`). |
| `DOMAIN_MAX_LENGTH` | `253` | Max domain-part characters. |
| `FORMAT_FULL` | `"full"` | Full address. |
| `FORMAT_LOCAL` | `"local"` | Local part. |
| `FORMAT_DOMAIN` | `"domain"` | Domain part. |
| `FORMAT_PROVIDER` | `"provider"` | Registrable domain. |
| `FORMAT_SUBDOMAIN` | `"subdomain"` | Subdomain labels. |
| `new` | `fn new(email: impl AsRef<str>) -> Result<Email, EmailError>` | Trim + lowercase; reject empty / malformed splits. |
| `get` | `fn get(&self) -> &str` | Normalized full address. |
| `get_local` / `get_domain` | `fn get_local(&self) -> &str` | Split parts. |
| `is_valid` | `fn is_valid(&self) -> bool` | PHP `filter_var(..., FILTER_VALIDATE_EMAIL)`. |
| `has_valid_local` | `fn has_valid_local(&self) -> bool` | Length, `[a-zA-Z0-9._+-]`, no leading/trailing/consecutive dots. |
| `has_valid_domain` | `fn has_valid_domain(&self) -> bool` | Length, `filter_var("test@".$domain)`, and PSL known/test. |
| `is_disposable` / `is_free` / `is_corporate` | `fn is_disposable(&self) -> bool` | List membership; disposable wins over free. |
| `get_provider` | `fn get_provider(&self) -> String` | `Domain::get_registerable()`, else full domain. |
| `get_subdomain` / `has_subdomain` | `fn get_subdomain(&self) -> String` | PSL subdomain labels. |
| `get_canonical` | `fn get_canonical(&self) -> Result<String, EmailError>` | Provider-normalized `local@domain`. |
| `is_canonical_supported` | `fn is_canonical_supported(&self) -> bool` | Domain handled by a non-generic provider. |
| `get_canonical_domain` | `fn get_canonical_domain(&self) -> Option<&'static str>` | Canonical host, or `None` for generic. |
| `get_formatted` | `fn get_formatted(&self, format: &str) -> String` | `full` / `local` / `domain` / `provider` / `subdomain`. |

`Email::new` uses [`utopia-domains`](../utopia-domains) `Domain` for PSL parsing. `Yandex` exists as a public provider type (matching PHP) but is **not** registered on `Email`, so `user@yandex.com` still uses `Generic`.

### Providers

| Type | Canonical domain | Local-part rules |
|------|------------------|------------------|
| `Gmail` | `gmail.com` | Strip `+tag`, strip dots. |
| `Outlook` | `outlook.com` | Strip `+tag`, keep dots. |
| `Yahoo` | `yahoo.com` | Drop last `-segment`, keep dots. |
| `Icloud` | `icloud.com` | Strip `+tag`, keep dots. |
| `Protonmail` | `protonmail.com` | Strip `+tag`; keep the original supported domain. |
| `Fastmail` | `fastmail.com` | Keep local as-is. |
| `Walla` | `walla.co.il` | Keep local as-is. |
| `Yandex` | `yandex.ru` | Keep local as-is (not wired into `Email`). |
| `Generic` | `""` | Lowercase only. |

`Provider::get_canonical` returns `Canonical { local, domain }` (PHP array keys `local` / `domain`).

### Validators

| Type | PHP class | Passes when |
|------|-----------|-------------|
| `EmailValidator` (`validator::Email`) | `Utopia\Emails\Validator\Email` | `Email::is_valid()` (`allow_empty` optional). |
| `EmailDomain` | `EmailDomain` | `is_valid() && has_valid_domain()`. |
| `EmailLocal` | `EmailLocal` | `is_valid() && has_valid_local()`. |
| `EmailNotDisposable` | `EmailNotDisposable` | `is_valid() && !is_disposable()`. |
| `EmailCorporate` | `EmailCorporate` | `is_valid() && is_corporate()`. |

Non-string JSON values fail (PHP `is_string` guard).

### Data files

Shipped under `crates-utopia/emails/data/` as JSON (PHP `data/*.php` arrays):

- `disposable-domains.json` / `disposable-domains-manual.json`
- `free-domains.json` / `free-domains-manual.json`

`Email::is_disposable` / `is_free` load those combined lists lazily (`OnceLock`), matching PHP `include` of `disposable-domains.php` and `free-domains.php`. Manual overlay files are shipped separately; PHP already inlines those domains in the main lists.

### Data sync CLI

PHP `import.php` (utopia-php/cli). Rust:

```bash
cargo run -p utopia-emails --bin utopia-emails-sync -- disposable --commit=true
cargo run -p utopia-emails --bin utopia-emails-sync -- free --commit=true
cargo run -p utopia-emails --bin utopia-emails-sync -- all --commit=true
cargo run -p utopia-emails --bin utopia-emails-sync -- stats
```

Tasks match PHP (`disposable`, `free`, `all`, `stats`) with `--commit`, `--force`, and `--source`. Combined JSON is written; `*-manual.json` is read and never overwritten. Remote list downloads use [`utopia-client`](../utopia-client) (PHP `Utopia\Fetch\Client`). GitHub Action: **`sync/data/emails`** (`.github/workflows/sync.data.emails.yml`), weekly + `workflow_dispatch`, opens PR `sync/data/emails-lists`.

### Errors

| Variant | PHP |
|---------|-----|
| `EmailError::Empty` | `Email address cannot be empty` |
| `EmailError::Invalid { email }` | `'{email}' must be a valid email address` |
| `EmailError::Domain` | `utopia-domains` constructor error |
| `EmailError::EmptyLocalAfterNormalization` | `InvalidArgumentException` from Gmail/Outlook/Yahoo/iCloud |

## Tests

```bash
cargo test -p utopia-emails
```

Ports `tests/EmailTest.php`, `tests/Canonicals/Providers/*Test.php`, and `tests/Validator/*Test.php`, plus extra error-path coverage.

## Benchmarks

```bash
cargo bench -p utopia-emails
```

Reports `email_new`, `email_is_valid`, `email_is_disposable`, and `email_get_canonical` ops/s. PHP twin: `benchmarks/emails/`.

## Code quality

```bash
cargo fmt --all
cargo clippy -p utopia-emails --all-targets -- -D warnings
cargo test -p utopia-emails
cargo doc -p utopia-emails --no-deps
```

## License

MIT
