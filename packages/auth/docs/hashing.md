# Password Hashing

The `Password` proof hashes and verifies passwords using a pluggable hashing
algorithm. Argon2 is used by default; any of the bundled hashes can be swapped
in.

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Utopia\Auth\Proofs\Password;
use Utopia\Auth\Hashes\Bcrypt;

// Initialize password authentication with default algorithms
$password = new Password();

// Hash a password (uses Argon2 by default)
$hash = $password->hash('user-password');

// Verify the password
$isValid = $password->verify('user-password', $hash);

// Use a specific algorithm with custom parameters
$bcrypt = new Bcrypt();
$bcrypt->setCost(12); // Increase cost factor for better security

$password->setHash($bcrypt);
$hash = $password->hash('user-password');
```

## Supported algorithms

- **Argon2** — modern, secure, and the recommended password hashing algorithm
- **Bcrypt** — well-established and secure password hashing
- **Scrypt** — memory-hard password hashing algorithm
- **ScryptModified** — modified version of Scrypt with additional features
- **SHA** — various SHA hash implementations
- **PHPass** — portable password hashing framework
- **MD5** — not recommended for passwords, legacy support only

## Advanced hash configuration

Each hash exposes a fluent API for tuning its cost parameters.

```php
<?php

use Utopia\Auth\Hashes\Scrypt;
use Utopia\Auth\Hashes\Argon2;

// Configure Scrypt parameters
$scrypt = new Scrypt();
$scrypt
    ->setCpuCost(16)      // CPU/Memory cost parameter
    ->setMemoryCost(14)   // Memory cost parameter
    ->setParallelCost(2)  // Parallelization parameter
    ->setLength(64)       // Output length in bytes
    ->setSalt('randomsalt123'); // Custom salt

// Configure Argon2 parameters
$argon2 = new Argon2();
$argon2
    ->setMemoryCost(65536)  // Memory cost in KiB
    ->setTimeCost(4)        // Number of iterations
    ->setThreads(3);        // Number of threads
```
