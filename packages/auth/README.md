# Utopia Auth

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/auth`](https://github.com/utopia-php/monorepo/tree/main/packages/auth) — please open issues and pull requests there.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/auth.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Auth is a simple, dependency-free PHP library for building authentication and authorization: secure password hashing, authentication proofs (tokens, codes, phrases), and signing/verifying OAuth2 and OpenID Connect JWTs. It is maintained by the [Appwrite team](https://appwrite.io).

Although it is part of the [Utopia Framework](https://github.com/utopia-php/framework) project, it is dependency free and can be used standalone with any PHP project or framework.

## Getting started

Install using Composer:

```bash
composer require utopia-php/auth
```

```php
<?php

use Utopia\Auth\Proofs\Password;

$password = new Password();
$hash = $password->hash('user-password');
$isValid = $password->verify('user-password', $hash);
```

## System requirements

Utopia Auth requires PHP 8.3 or later. We recommend using the latest PHP version whenever possible.

## Features

- **Password hashing** — Argon2, Bcrypt, Scrypt (and a modified Scrypt), SHA, PHPass, and MD5 (legacy only)
- **Authentication proofs** — cryptographically random tokens, one-time codes (e.g. 2FA), and human-readable phrases
- **Data store** — a base64-encodable key/value envelope for serializing authentication state
- **Token issuers** — mint signed [JWS](https://datatracker.ietf.org/doc/html/rfc7515): application-defined HS256 JWTs, OAuth2 access tokens (RFC 9068), refresh tokens, and OpenID Connect ID tokens
- **Token verifiers** — verify RS256/HS256 JWS with an `alg`-confusion guard and standard claim checks
- **OAuth2 helpers** — RFC 8707 resource indicators, OpenID Connect prompts, RFC 9126 pushed authorization request URIs, and OAuth Client ID Metadata Documents

## Documentation

- [Password Hashing](docs/hashing.md) — algorithms and tuning
- [Authentication Proofs](docs/proofs.md) — tokens, one-time codes, and phrases
- [Data Store](docs/store.md) — encode/decode authentication state
- [JSON Web Tokens](docs/jwt.md) — application token issuance, JWS mechanics, verification, and claim/header names
- [OAuth2 and OpenID Connect](docs/oauth2.md) — token examples and protocol helpers

## Tests

To run all unit tests, use the following Docker command:

```bash
docker compose exec tests vendor/bin/phpunit --configuration phpunit.xml tests
```

## Security

We take security seriously. If you discover any security-related issues, please email security@appwrite.io instead of using the issue tracker.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](https://github.com/utopia-php/monorepo/blob/main/CONTRIBUTING.md).

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
