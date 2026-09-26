# Utopia Emails

[![Tests](https://github.com/utopia-php/emails/workflows/Tests/badge.svg)](https://github.com/utopia-php/emails/actions/workflows/test.yml)
[![Linter](https://github.com/utopia-php/emails/workflows/Linter/badge.svg)](https://github.com/utopia-php/emails/actions/workflows/linter.yml)
[![CodeQL](https://github.com/utopia-php/emails/workflows/CodeQL/badge.svg)](https://github.com/utopia-php/emails/actions/workflows/codeql-analysis.yml)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/emails.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia Emails library is a simple and lite library for parsing and validating email addresses. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project, it can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:
```bash
composer require utopia-php/emails
```

```php
<?php

require_once '../vendor/autoload.php';

use Utopia\Emails\Email;

// Basic email parsing
$email = new Email('user@example.com');

$email->get(); // user@example.com
$email->getLocal(); // user
$email->getDomain(); // example.com
$email->isValid(); // true
$email->hasValidLocal(); // true
$email->hasValidDomain(); // true

// Email classification
$email->isDisposable(); // false
$email->isFree(); // false
$email->isCorporate(); // true

// Domain analysis
$email->getProvider(); // example.com
$email->getSubdomain(); // ''
$email->hasSubdomain(); // false

// Email with subdomain
$email = new Email('user@mail.example.com');

$email->get(); // user@mail.example.com
$email->getLocal(); // user
$email->getDomain(); // mail.example.com
$email->getProvider(); // example.com
$email->getSubdomain(); // mail
$email->hasSubdomain(); // true

// Email formatting
$email->getFormatted(Email::FORMAT_FULL); // user@mail.example.com
$email->getFormatted(Email::FORMAT_LOCAL); // user
$email->getFormatted(Email::FORMAT_DOMAIN); // mail.example.com
$email->getFormatted(Email::FORMAT_PROVIDER); // example.com
$email->getFormatted(Email::FORMAT_SUBDOMAIN); // mail

// Email normalization (automatic)
$email = new Email('  USER@EXAMPLE.COM  ');
$email->get(); // user@example.com

```

## Library API

### Email Class

#### Constants

The Email class provides the following constants for email formatting:

- `Email::FORMAT_FULL` - Full email address (default)
- `Email::FORMAT_LOCAL` - Local part only (before @)
- `Email::FORMAT_DOMAIN` - Domain part only (after @)
- `Email::FORMAT_PROVIDER` - Provider domain (domain without subdomain)
- `Email::FORMAT_SUBDOMAIN` - Subdomain part only

#### Methods

* **get()** - Return full email address.
* **getLocal()** - Return local part (before @).
* **getDomain()** - Return domain part (after @).
* **isValid()** - Check if email is valid format.
* **hasValidLocal()** - Check if email has valid local part.
* **hasValidDomain()** - Check if email has valid domain part.
* **isDisposable()** - Check if email is from a disposable email service.
* **isFree()** - Check if email is from a free email service.
* **isCorporate()** - Check if email is from a corporate domain.
* **getProvider()** - Get email provider (domain without subdomain).
* **getSubdomain()** - Get email subdomain (if any).
* **hasSubdomain()** - Check if email has subdomain.
* **getFormatted(string $format)** - Get email in different formats. Use constants: `Email::FORMAT_FULL`, `Email::FORMAT_LOCAL`, `Email::FORMAT_DOMAIN`, `Email::FORMAT_PROVIDER`, `Email::FORMAT_SUBDOMAIN`.

## Using the Validators

```php
<?php

use Utopia\Emails\Validator\Email;
use Utopia\Emails\Validator\EmailDomain;
use Utopia\Emails\Validator\EmailLocal;
use Utopia\Emails\Validator\EmailNotDisposable;
use Utopia\Emails\Validator\EmailCorporate;

// Basic email validation
$emailValidator = new Email();
$emailValidator->isValid('user@example.com'); // true
$emailValidator->isValid('invalid-email'); // false

// Domain validation
$domainValidator = new EmailDomain();
$domainValidator->isValid('user@example.com'); // true
$domainValidator->isValid('user@example..com'); // false

// Local part validation
$localValidator = new EmailLocal();
$localValidator->isValid('user@example.com'); // true
$localValidator->isValid('user..name@example.com'); // false

// Non-disposable email validation
$notDisposableValidator = new EmailNotDisposable();
$notDisposableValidator->isValid('user@example.com'); // true
$notDisposableValidator->isValid('user@10minutemail.com'); // false

// Corporate email validation
$corporateValidator = new EmailCorporate();
$corporateValidator->isValid('user@company.com'); // true
$corporateValidator->isValid('user@gmail.com'); // false

```

## Library Validators API

* **Email** - Basic email validation using the Email class.
* **EmailDomain** - Validates that an email address has a valid domain.
* **EmailLocal** - Validates that an email address has a valid local part.
* **EmailNotDisposable** - Validates that an email address is not from a disposable email service.
* **EmailCorporate** - Validates that an email address is from a corporate domain (not free or disposable).

## Email Classification

The library automatically classifies emails into three categories:

### Free Email Services
Common free email providers like Gmail, Yahoo, Hotmail, Outlook, etc.

### Disposable Email Services
Temporary email services like 10minutemail, GuerrillaMail, Mailinator, etc.

### Corporate Email Services
All other email addresses that are not classified as free or disposable.

## Supported Email Formats

The library supports various email formats including:

- Basic: `user@example.com`
- With dots: `user.name@example.com`
- With plus: `user+tag@example.com`
- With hyphens: `user-name@example.com`
- With underscores: `user_name@example.com`
- With numbers: `user123@example123.com`
- With subdomains: `user@mail.example.com`
- With multiple subdomains: `user@mail.sub.example.com`

## Validation Rules

### Local Part (before @)
- Maximum 64 characters
- Can contain letters, numbers, dots, underscores, hyphens, and plus signs
- Cannot start or end with a dot
- Cannot contain consecutive dots

### Domain Part (after @)
- Maximum 253 characters
- Must contain at least one dot
- Must have a valid TLD (at least 2 characters)
- Can contain letters, numbers, dots, and hyphens
- Cannot start or end with a dot or hyphen
- Cannot contain consecutive dots or hyphens

## Data Management & Import System

The library uses external data files to classify email domains as free or disposable. These files are located in the `data/` directory:

- `data/free-domains.php` - List of known free email service providers
- `data/disposable-domains.php` - List of known disposable/temporary email services
- `data/free-domains-manual.php` - Manually managed free email domains
- `data/disposable-domains-manual.php` - Manually managed disposable email domains

### Import System

The library includes a comprehensive import system that can automatically update domain lists from multiple sources.

#### Quick Start

```bash
# Install dependencies
composer install

# Show current statistics
php import.php stats

# Update all domains (preview only)
php import.php all

# Update and commit changes
php import.php all --commit=true
```

#### Available Commands

**Update All Domains**
```bash
# Preview changes without committing
php import.php all

# Force update and commit changes
php import.php all --force=true --commit=true
```

**Update Disposable Domains Only**
```bash
# Update from all sources
php import.php disposable --commit=true

# Update from specific source
php import.php disposable --source=martenson --commit=true

# Force update even if no changes detected
php import.php disposable --force=true --commit=true
```

**Update Free Domains Only**
```bash
# Update free domains
php import.php free --commit=true

# Update from specific source
php import.php free --source=manual --commit=true
```

**Show Statistics**
```bash
# Display current domain statistics
php import.php stats
```

#### Composer Scripts

For convenience, you can also use composer scripts:

```bash
# Using composer scripts
composer run import:all
composer run import:disposable
composer run import:free
composer run import:stats
```

#### Import Sources

**Disposable Email Sources:**
- Manual Disposable Email Domains (configurable)
- Martenson Disposable Email Domains
- Disposable Email Domains
- Wes Bos Burner Email Providers
- 7c FakeFilter Domains
- Adam Loving Temporary Email Domains

**Free Email Sources:**
- Manual Free Email Domains (configurable)

#### Features

- **Multiple Sources**: Support for 5+ disposable email domain sources
- **Manual Configuration**: Ability to manually manage both free and disposable email domains
- **Domain Validation**: Built-in domain validation using Utopia Domains
- **Statistics & Analysis**: Detailed domain statistics and TLD analysis
- **Deduplication**: Automatic removal of duplicate domains
- **Error Handling**: Robust error handling with graceful fallbacks

#### Manual Domain Management

You can manually edit the domain files to add or remove domains:

**Free Email Domains** - Edit `data/free-domains-manual.php`:
```php
<?php

return [
    'gmail.com',
    'yahoo.com',
    'hotmail.com',
    // Add your custom free email domains here
];
```

**Disposable Email Domains** - Edit `data/disposable-domains-manual.php`:
```php
<?php

return [
    '10minutemail.com',
    'guerrillamail.com',
    'mailinator.com',
    // Add your custom disposable email domains here
];
```

#### Configuration

Sources are defined in `import.php`. You can:
- Enable/disable sources by modifying the source arrays
- Add new sources by adding entries to the `DISPOSABLE_SOURCES` or `FREE_SOURCES` arrays
- Modify existing source URLs or descriptions

## System Requirements

Utopia Emails requires PHP 8.5 or later. We recommend using the latest PHP version whenever possible.

## Authors

**Eldad Fux**

+ [https://twitter.com/eldadfux](https://twitter.com/eldadfux)
+ [https://github.com/eldadfux](https://github.com/eldadfux)

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
