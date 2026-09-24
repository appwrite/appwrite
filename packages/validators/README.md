# Utopia Validators

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/validators`](https://github.com/utopia-php/monorepo/tree/main/packages/validators) — please open issues and pull requests there.

Reusable validation building blocks for [Utopia](https://github.com/utopia-php) projects.  
This package exposes a consistent API for common HTTP-oriented validation concerns such as input sanitization, URL checks, IP validation, hostname filtering, lists enforcement, and more.

## Installation

```bash
composer require utopia-php/validators
```

## Usage

```php
use Utopia\Validator\Text;
use Utopia\Validator\Range;

$username = new Text(20, min: 3);
$age = new Range(min: 13, max: 120);

if (! $username->isValid($input['username'])) {
    throw new InvalidArgumentException($username->getDescription());
}

if (! $age->isValid($input['age'])) {
    throw new InvalidArgumentException($age->getDescription());
}
```

Validators expose a predictable contract:

- `isValid(mixed $value): bool` – core validation rule
- `getDescription(): string` – human readable rule summary
- `getType(): string` – expected PHP type (string, integer, array, ...)
- `isArray(): bool` – hint whether the validator expects an array input

For advanced flows combine validators with `Multiple`, `AnyOf`, `AllOf`, `NoneOf`, or wrap checks with helpers such as `Nullable`.

## Available Validators

- `AllOf`, `AnyOf`, `NoneOf`, `Multiple` – composition helpers
- `ArrayList`, `Assoc`, `Nullable`, `WhiteList`, `Wildcard`
- `Boolean`, `Integer`, `FloatValidator`, `Numeric`, `Range`
- `Domain`, `Host`, `Hostname`, `IP`, `URL`
- `HexColor`, `Identifier`, `JSON`, `Phone`, `Text`
- `JSON\ObjectValidator`, `JSON\ArrayValidator` – JSON shape checks that accept encoded strings
- `JSON\FCM` – FCM service account JSON with required credential fields

### Validating JSON shape

`JSON` accepts any valid JSON, including scalars such as `"1"` and `"\"text\""`. When a parameter must be
an object or a list, reach for the shape-specific validators instead. Both accept a value that is already
decoded or still encoded as a string:

```php
use Utopia\Validator\JSON;

$data = new JSON\ObjectValidator();

$data->isValid('{"event": "login"}'); // true
$data->isValid(['event' => 'login']); // true
$data->isValid('"login"');            // false
$data->isValid('[]');                 // false
```

Strings decode to objects rather than associative arrays, so `'{}'` and `'[]'` stay distinguishable. A value
that arrives already decoded as an empty array is ambiguous — PHP represents both `{}` and `[]` that way — so
both validators accept it.

Use `JSON\FCM` for Google service account credentials used with the FCM HTTP v1 API. It accepts an encoded
JSON object, associative array, or `stdClass`. The validator checks the credential type, Firebase project,
service account email, PEM private key, and OAuth token endpoint. It also validates standard optional service
account fields when present.

## Development

Run the static analysis and test suites from the project root:

```bash
composer check
composer test
```

This project is released under the [MIT License](LICENSE.md).
