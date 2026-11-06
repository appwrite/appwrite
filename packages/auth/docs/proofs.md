# Authentication Proofs

Proofs are secrets you generate, hand to a user, and later verify against a
stored hash. Each proof generates a value and hashes/verifies it through the
underlying `Hash` (Argon2 by default).

## Authentication tokens

Cryptographically secure random tokens, suitable for session or API tokens.

```php
<?php

use Utopia\Auth\Proofs\Token;

// Generate secure authentication tokens
$token = new Token(32); // 32 characters length
$authToken = $token->generate(); // Random token
$hashedToken = $token->hash($authToken); // Store this in database

// Later, verify the token
$isValid = $token->verify($authToken, $hashedToken);
```

## One-time codes

Numeric codes, e.g. for two-factor authentication or email/phone verification.

```php
<?php

use Utopia\Auth\Proofs\Code;

// Generate verification codes (e.g., for 2FA)
$code = new Code(6); // 6-digit code
$verificationCode = $code->generate();
$hashedCode = $code->hash($verificationCode);

// Verify the code
$isValid = $code->verify($verificationCode, $hashedCode);
```

## Human-readable phrases

Memorable phrases, useful as a recognizable confirmation value.

```php
<?php

use Utopia\Auth\Proofs\Phrase;

// Generate memorable authentication phrases
$phrase = new Phrase();
$authPhrase = $phrase->generate(); // e.g., "Brave cat"
$hashedPhrase = $phrase->hash($authPhrase);

// Verify the phrase
$isValid = $phrase->verify($authPhrase, $hashedPhrase);
```
