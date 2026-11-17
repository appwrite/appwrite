# Utopia Emails

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Email addresses split, checked and made canonical per provider, and their domains classified as free or disposable.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-emails = { git = "https://github.com/appwrite/appwrite" }
```

## Split and check an address

`Email::new` trims and lower-cases the address and splits it at its single `@`. It holds bytes, as a PHP string does.

```rust
use utopia_emails::Email;

let email = Email::new("  User.Name+Tag@Mail.Example.COM ")?;

assert_eq!(email.get(), b"user.name+tag@mail.example.com");
assert_eq!(email.local(), b"user.name+tag");
assert_eq!(email.domain(), b"mail.example.com");
assert!(email.is_valid() && email.has_valid_local() && email.has_valid_domain_syntax());
# Ok::<(), utopia_emails::Error>(())
```

## Canonical forms

A provider's aliases are removed: Gmail drops dots and `+tags`, Outlook and iCloud drop `+tags`, and each uses its canonical domain.

```rust
use utopia_emails::Email;

let email = Email::new("John.Doe+news@googlemail.com")?;

assert_eq!(email.canonical()?.address(), b"johndoe@gmail.com");
assert_eq!(email.canonical_domain(), Some("gmail.com"));
# Ok::<(), utopia_emails::Error>(())
```

## Free and disposable domains

The lists are embedded. A disposable provider listed by its registrable domain owns its subdomains, so pass the registrable domain (from `utopia-domains`) too.

```rust
use utopia_emails::Lists;

let lists = Lists::global();

assert!(lists.is_on_free_list(b"gmail.com"));
assert!(lists.is_disposable(b"x.mailinator.com", b"mailinator.com"));
assert!(!lists.is_disposable(b"appwrite.io", b"appwrite.io"));
```

## Contributing

### Test

```bash
cargo test -p utopia-emails
cargo clippy -p utopia-emails --all-targets -- -D warnings
cargo fmt --all --check
```

### Compare with PHP

The utopia PHP extension serves this crate as `Utopia\Emails\Email`, which no longer has a PHP implementation. Its parity corpus, `crates/extension/tests/emails.php`, checks every domain of both lists, every provider domain and 20,000 random addresses, and must print `crates/extension/tests/emails.expected.jsonl`, the output of the PHP class it replaced; `crates/extension/tests/run.sh` checks it, and runs `packages/emails/tests`, in the development image.

### Domain lists

`data/free-domains.txt` and `data/disposable-domains.txt` are `packages/emails/data/{free,disposable}-domains.php` in the crate's format. After refreshing the package's lists (`composer import:all` in `packages/emails`), regenerate them from the repository root:

```bash
php crates/utopia/emails/data/export.php
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
