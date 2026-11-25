# Utopia Emails

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/emails`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/emails) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Email addresses split, checked and made canonical per provider, and their domains classified as free or disposable.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-emails = { git = "https://github.com/utopia-rs/emails" }
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
```

## Canonical forms

A provider's aliases are removed: Gmail drops dots and `+tags`, Outlook and iCloud drop `+tags`, and each uses its canonical domain.

```rust
use utopia_emails::Email;

let email = Email::new("John.Doe+news@googlemail.com")?;

assert_eq!(email.canonical()?.address(), b"johndoe@gmail.com");
assert_eq!(email.canonical_domain(), Some("gmail.com"));
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

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/emails`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/emails). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-emails
cargo clippy -p utopia-emails --all-targets -- -D warnings
cargo fmt --all --check
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
