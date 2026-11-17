<?php

// Times Utopia\Emails\Email on whichever implementation loaded. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/emails.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Emails\Email;

// A run meant for the extension that gets the PHP class would time PHP against PHP.
$internal = (new ReflectionClass(Email::class))->isInternal();
$requested = in_array('emails', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' emails, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

// The first classification reads the lists (data/*.php for PHP, the embedded lists for Rust).
$boot = hrtime(true);
(new Email('user@example.com'))->isCorporate();
$boot = hrtime(true) - $boot;

// What requests do: account creation and email updates check the address and, with the
// project's policies, whether it is free, disposable or a canonical duplicate.
$cases = [
    'new' => fn () => new Email('User.Name@Example.com'),
    'validator' => fn () => (new Utopia\Emails\Validator\Email())->isValid('user.name+tag@example.co.uk'),
    'isValid' => fn () => (new Email('user@example.com'))->isValid(),
    'hasValidDomain' => fn () => (new Email('user@mail.example.co.uk'))->hasValidDomain(),
    'isDisposable' => fn () => (new Email('user@mailinator.com'))->isDisposable(),
    'isFree' => fn () => (new Email('user@gmail.com'))->isFree(),
    'isCorporate' => fn () => (new Email('user@appwrite.io'))->isCorporate(),
    'getCanonical' => fn () => (new Email('John.Doe+tag@googlemail.com'))->getCanonical(),
    'account policies' => function () {
        $email = new Email('John.Doe+tag@gmail.com');

        return [$email->isFree(), $email->isDisposable(), $email->isCanonicalSupported() ? $email->getCanonical() : null];
    },
];
$n = 2000;
$out = ['first use (ns, once)' => $boot];
foreach ($cases as $name => $fn) {
    for ($i = 0; $i < 100; $i++) {
        $fn();
    }
    $runs = [];
    for ($r = 0; $r < 5; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $fn();
        }
        $runs[] = (hrtime(true) - $t) / $n;
    }
    sort($runs);
    $out[$name] = $runs[2];
}
$out['peak memory MB'] = round(memory_get_peak_usage(true) / 1048576, 1);
echo json_encode($out), "\n";
