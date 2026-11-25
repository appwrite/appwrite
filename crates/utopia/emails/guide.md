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
