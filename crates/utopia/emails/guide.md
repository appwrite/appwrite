Utopia Emails parses email addresses and answers the questions an application asks about them: is the address well formed, what is its canonical form at its mail provider, and is its domain free, disposable or corporate. It also ships validators for request parameters. This guide covers the common tasks.

## Validate an address from a sign-up form

`EmailValidator` accepts a string that parses as a valid address. Upper case is fine, because the address is lower-cased first. Surrounding spaces and control characters are not: the parser would trim them, so the value stored would be a lookalike of the address checked.

```
use utopia_emails::EmailValidator;

let validator = EmailValidator::default();

assert!(validator.is_valid_address("Jane.Doe@Example.com"));
assert!(!validator.is_valid_address("  Jane.Doe@Example.com "));
assert!(!validator.is_valid_address("jane.doe"));
assert!(!validator.is_valid_address("jane@doe@example.com"));
assert!(!validator.is_valid_address(""));

// An optional field: allow it to stay empty.
assert!(EmailValidator::new(true).is_valid_address(""));
```

```php
use Utopia\Emails\Validator\Email;

$validator = new Email();

var_dump($validator->isValid('Jane.Doe@Example.com'));    // bool(true)
var_dump($validator->isValid('  Jane.Doe@Example.com ')); // bool(false)
var_dump($validator->isValid('jane.doe'));                // bool(false)
var_dump($validator->isValid('jane@doe@example.com'));    // bool(false)
var_dump($validator->isValid(''));                        // bool(false)

var_dump((new Email(true))->isValid('')); // bool(true)
```

## Read the parts of an address

Parse the address once, then read its local part, its domain, and the provider and subdomain inside the domain. `formatted` returns any one of them by name. An `Address` is an `Email` with its domain parsed; the parts are bytes, as PHP strings are.

```
use utopia_emails::{Address, Format};

let email = Address::new("  Jane@Mail.Example.co.uk ")?;

assert_eq!(email.get(), b"jane@mail.example.co.uk");
assert_eq!(email.local(), b"jane");
assert_eq!(email.domain(), b"mail.example.co.uk");
assert_eq!(&*email.provider(), b"example.co.uk");
assert_eq!(email.subdomain(), b"mail");
assert!(email.has_subdomain());
assert_eq!(&*email.formatted(Format::Provider), b"example.co.uk");
assert_eq!(&*email.formatted(Format::from_name(b"local")), b"jane");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Email;

$email = new Email('  Jane@Mail.Example.co.uk ');

echo $email->get(), "\n";                                // jane@mail.example.co.uk
echo $email->getLocal(), "\n";                           // jane
echo $email->getDomain(), "\n";                          // mail.example.co.uk
echo $email->getProvider(), "\n";                        // example.co.uk
echo $email->getSubdomain(), "\n";                       // mail
var_dump($email->hasSubdomain());                        // bool(true)
echo $email->getFormatted(Email::FORMAT_PROVIDER), "\n"; // example.co.uk
echo $email->getFormatted('local'), "\n";                // jane
```

## Check the local part and the domain

`FILTER_VALIDATE_EMAIL` allows more than most applications want. `has_valid_local` allows only letters, digits and `._+-` with no stray dots, and `has_valid_domain` requires a known public suffix. `EmailLocal` and `EmailDomain` apply the same rules as validators.

```
use utopia_emails::{Address, EmailDomain, EmailLocal};

let email = Address::new("o'brien@example.com")?;
assert!(email.is_valid());
assert!(!email.has_valid_local());
assert!(email.has_valid_domain());

assert!(EmailLocal.is_valid_address("jane.doe+news@example.com"));
assert!(!EmailLocal.is_valid_address("o'brien@example.com"));
assert!(EmailDomain.is_valid_address("jane@mail.example.com"));
assert!(!EmailDomain.is_valid_address("jane@example.notatld"));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Email;
use Utopia\Emails\Validator\EmailDomain;
use Utopia\Emails\Validator\EmailLocal;

$email = new Email("o'brien@example.com");
var_dump($email->isValid());        // bool(true)
var_dump($email->hasValidLocal());  // bool(false)
var_dump($email->hasValidDomain()); // bool(true)

var_dump((new EmailLocal())->isValid('jane.doe+news@example.com')); // bool(true)
var_dump((new EmailLocal())->isValid("o'brien@example.com"));       // bool(false)
var_dump((new EmailDomain())->isValid('jane@mail.example.com'));    // bool(true)
var_dump((new EmailDomain())->isValid('jane@example.notatld'));     // bool(false)
```

## Normalize an address before storing it

Many providers deliver several spellings to the same inbox. Gmail ignores dots and `+tags`, and Outlook ignores `+tags`. Store `canonical` to stop one person from signing up many times. Unknown domains keep their address as is.

```
use utopia_emails::Email;

let canonical = |address: &str| Email::new(address)?.canonical().map(|canonical| canonical.address());

assert_eq!(canonical("Jane.Doe+promo@googlemail.com")?, b"janedoe@gmail.com");
assert_eq!(canonical("jane.doe+promo@hotmail.co.uk")?, b"jane.doe@outlook.com");
assert_eq!(canonical("jane.doe+promo@me.com")?, b"jane.doe@icloud.com");
assert_eq!(canonical("jane.doe+promo@example.com")?, b"jane.doe+promo@example.com");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Email;

echo (new Email('Jane.Doe+promo@googlemail.com'))->getCanonical(), "\n"; // janedoe@gmail.com
echo (new Email('jane.doe+promo@hotmail.co.uk'))->getCanonical(), "\n";  // jane.doe@outlook.com
echo (new Email('jane.doe+promo@me.com'))->getCanonical(), "\n";         // jane.doe@icloud.com
echo (new Email('jane.doe+promo@example.com'))->getCanonical(), "\n";    // jane.doe+promo@example.com
```

## Find the mail provider behind a domain

`is_canonical_supported` says whether a provider with aliasing rules owns the domain, and `canonical_domain` names that provider's main domain. Use it to group users by provider.

```
use utopia_emails::Email;

let email = Email::new("jane@live.com")?;
assert!(email.is_canonical_supported());
assert_eq!(email.canonical_domain(), Some("outlook.com"));

let email = Email::new("jane@example.com")?;
assert!(!email.is_canonical_supported());
assert_eq!(email.canonical_domain(), None);
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Email;

$email = new Email('jane@live.com');
var_dump($email->isCanonicalSupported()); // bool(true)
echo $email->getCanonicalDomain(), "\n";  // outlook.com

$email = new Email('jane@example.com');
var_dump($email->isCanonicalSupported()); // bool(false)
var_dump($email->getCanonicalDomain());   // NULL
```

## Reject disposable domains

Each address is free (a public mail service), disposable (a throwaway inbox) or corporate (anything else). Use `EmailNotDisposable` to block throwaway inboxes, and `EmailCorporate` when only work addresses are welcome.

```
use utopia_emails::{Address, EmailCorporate, EmailNotDisposable};

let email = Address::new("jane@mailinator.com")?;
assert!(email.is_disposable());
assert!(!email.is_free());

// A subdomain of a disposable provider is disposable too.
assert!(Address::new("jane@inbox.mailinator.com")?.is_disposable());

let email = Address::new("jane@gmail.com")?;
assert!(email.is_free());
assert!(!email.is_corporate());

assert!(!EmailNotDisposable.is_valid_address("jane@10minutemail.com"));
assert!(EmailNotDisposable.is_valid_address("jane@gmail.com"));
assert!(!EmailCorporate.is_valid_address("jane@gmail.com"));
assert!(EmailCorporate.is_valid_address("jane@company.org"));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Email;
use Utopia\Emails\Validator\EmailCorporate;
use Utopia\Emails\Validator\EmailNotDisposable;

$email = new Email('jane@mailinator.com');
var_dump($email->isDisposable()); // bool(true)
var_dump($email->isFree());       // bool(false)

var_dump((new Email('jane@inbox.mailinator.com'))->isDisposable()); // bool(true)

$email = new Email('jane@gmail.com');
var_dump($email->isFree());       // bool(true)
var_dump($email->isCorporate());  // bool(false)

var_dump((new EmailNotDisposable())->isValid('jane@10minutemail.com')); // bool(false)
var_dump((new EmailNotDisposable())->isValid('jane@gmail.com'));        // bool(true)
var_dump((new EmailCorporate())->isValid('jane@gmail.com'));            // bool(false)
var_dump((new EmailCorporate())->isValid('jane@company.org'));          // bool(true)
```

## Report addresses that cannot be parsed

Parsing fails on an empty value or one without exactly one `@`, and `Address::new` on a URL as the domain. Normalizing fails when a provider's rules leave nothing of the local part. Each error carries a message you can show.

```
use utopia_emails::{Email, Error};

for value in ["", "jane.doe", "jane@doe@example.com"] {
    match Email::new(value) {
        Err(Error::Parse(_)) => {}
        other => panic!("expected a parse error, got {other:?}"),
    }
}
assert_eq!(Email::new("jane.doe").unwrap_err().to_string(), "'jane.doe' must be a valid email address");

let error = Email::new("...@gmail.com")?.canonical().unwrap_err();
assert_eq!(error, Error::EmptyLocal);
assert_eq!(error.to_string(), "Email local part cannot be empty after normalization");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Email;

foreach (['', 'jane.doe', 'jane@doe@example.com'] as $value) {
    try {
        new Email($value);
    } catch (\Exception $e) {
        echo $e->getMessage(), "\n";
    }
}
// Email address cannot be empty
// 'jane.doe' must be a valid email address
// 'jane@doe@example.com' must be a valid email address

try {
    (new Email('...@gmail.com'))->getCanonical();
} catch (\InvalidArgumentException $e) {
    echo $e->getMessage(), "\n"; // Email local part cannot be empty after normalization
}
```

## Apply one provider's rules

Each provider is a `Provider`: its canonical domain, the domains it owns and its aliasing rules. `Provider::of` finds the one `Email` would use for a domain. Yandex is one `Email` does not consult; apply it yourself.

```
use utopia_emails::Provider;

assert_eq!(Provider::of(b"googlemail.com").canonical_domain, "gmail.com");
assert_eq!(Provider::of(b"example.com"), &Provider::GENERIC);

let canonical = Provider::YANDEX.canonical(b"Jane.Doe+news", b"ya.ru")?;
assert_eq!(canonical.address(), b"jane.doe+news@yandex.ru");
assert!(Provider::YANDEX.supports(b"yandex.com"));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Canonicals\Providers\Yandex;

$yandex = new Yandex();
$canonical = $yandex->getCanonical('Jane.Doe+news', 'ya.ru');
echo $canonical['local'], '@', $canonical['domain'], "\n"; // jane.doe+news@yandex.ru
var_dump($yandex->supports('yandex.com'));                // bool(true)
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
