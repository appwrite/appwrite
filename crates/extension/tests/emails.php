<?php

// Parity corpus for Utopia\Emails\Email, which only the utopia extension provides. Its stdout
// must match emails.expected.jsonl, the output of the PHP class it replaced (utopia-php/emails
// 0.8). The validators and the canonical providers stay PHP.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Domains\Domain;
use Utopia\Emails\Email;

if (!(new ReflectionClass(Email::class))->isInternal()) {
    fwrite(STDERR, 'Utopia\\Emails\\Email is not the utopia extension\'s' . PHP_EOL);
    exit(1);
}

/** Warnings raised by the current case. */
$warnings = [];
set_error_handler(function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

/** $value with every string that is not UTF-8 as its hex, and every object as its class and properties. */
function exported(mixed $value): mixed
{
    if (is_string($value)) {
        return mb_check_encoding($value, 'UTF-8') ? $value : ['hex' => bin2hex($value)];
    }
    if (is_array($value)) {
        return array_map(exported(...), $value);
    }
    if ($value instanceof Email || $value instanceof Domain) {
        return ['class' => $value::class, 'properties' => exported(properties($value))];
    }
    if (is_object($value)) {
        return ['class' => $value::class];
    }

    return $value;
}

/** The observable outcome of $run: its value or exception, and the warnings it raised. */
function outcome(callable $run): array
{
    global $warnings;
    $warnings = [];
    try {
        $result = ['value' => exported($run())];
    } catch (Throwable $e) {
        $result = ['throws' => $e::class, 'message' => exported($e->getMessage())];
    }
    if ($warnings !== []) {
        $result['warnings'] = $warnings;
    }

    return $result;
}

function emit(string $case, array $result): void
{
    echo json_encode([exported($case), $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
}

/** The protected properties as (array) shows them, keyed by name. */
function properties(object $object): array
{
    $properties = [];
    foreach ((array) $object as $key => $value) {
        $properties[substr($key, strrpos($key, "\0") + 1)] = $value;
    }

    return $properties;
}

/** $run's value, or the class and message it threw, so one getter cannot hide the rest. */
function attempt(callable $run): mixed
{
    try {
        return $run();
    } catch (Throwable $e) {
        return ['throws' => $e::class, 'message' => $e->getMessage()];
    }
}

/** Every getter on a fresh object, then the properties they filled. */
function inspect(mixed $value): array
{
    $email = new Email($value);

    return [
        $email->get(), $email->getLocal(), $email->getDomain(), $email->isValid(), $email->hasValidLocal(), $email->hasValidDomain(),
        $email->isDisposable(), $email->isFree(), $email->isCorporate(), $email->getProvider(), $email->getSubdomain(), $email->hasSubdomain(),
        attempt(fn () => $email->getCanonical()), $email->isCanonicalSupported(), $email->getCanonicalDomain(),
        $email->getFormatted(), $email->getFormatted(Email::FORMAT_LOCAL), $email->getFormatted(Email::FORMAT_DOMAIN),
        $email->getFormatted(Email::FORMAT_PROVIDER), $email->getFormatted(Email::FORMAT_SUBDOMAIN), $email->getFormatted('nonsense'),
        properties($email),
    ];
}

/** What the free and disposable lists decide, which is what the lists are for. */
function classify(string $value): array
{
    $email = new Email($value);

    return [$email->isFree(), $email->isDisposable(), $email->isCorporate(), $email->getProvider(), $email->hasValidDomain()];
}

// Every domain of both lists, and a subdomain of every 25th: a domain on one list and not the
// other's copy classifies differently.
$entries = 0;
foreach (['free', 'disposable'] as $name) {
    $list = require __DIR__ . "/../../../packages/emails/data/{$name}-domains.php";
    foreach (array_values($list) as $index => $domain) {
        emit("{$name} {$domain}", outcome(fn () => classify("user@{$domain}")));
        if ($index % 25 === 0) {
            emit("{$name} sub {$domain}", outcome(fn () => classify("user@mx.{$domain}")));
        }
        $entries++;
    }
}

// Every domain of every provider, under local parts their rules change.
$providers = ['Gmail', 'Outlook', 'Yahoo', 'Icloud', 'Protonmail', 'Fastmail', 'Walla', 'Yandex', 'Generic'];
$locals = ['John.Doe', 'john.doe+tag', 'J.O.H.N+a+b', '+tag', '0+x', '0', '..', '.+x', 'a-b-c', 'a_b', "x\n", 'ÄB.c+d', 'a+', 'a.'];
foreach ($providers as $provider) {
    $domains = (new ReflectionClass("Utopia\\Emails\\Canonicals\\Providers\\{$provider}"))->getConstant('SUPPORTED_DOMAINS') ?: [];
    foreach ([...$domains, ...array_map(strtoupper(...), $domains), ...array_map(fn ($d) => 'mail.' . $d, $domains)] as $domain) {
        foreach ($locals as $local) {
            emit("provider {$local}@{$domain}", outcome(fn () => inspect("{$local}@{$domain}")));
        }
    }
}

// Addresses Appwrite and Cloud see, and edge cases.
$addresses = [
    'user@example.com', 'User.Name+Tag@Mail.Company.CO.UK', ' padded@example.com ', "\tTAB@EXAMPLE.COM\n", "nul\0@example.com",
    'first.last@sub.domain.example.org', 'a@b.co', 'a@localhost', 'a@appwrite.test', 'a@sub.appwrite.test', 'a@example', 'a@example.local',
    'a@example.invalid', 'a@example.localhost', 'a@example.co.uk', 'a@co.uk', 'a@uk', 'a@www.ck', 'a@x.www.ck', 'a@github.io', 'a@me.github.io',
    'a@a.b.c.d.e.example.com', 'a@0.example.com', 'a@0.0', 'a@x.0', 'a@0.com', '0.0@x.com', '00@x.com', '0@0.0', 'a@1.2.3.4', 'a@[127.0.0.1]',
    'a@[IPv6:::1]', '"quoted"@example.com', '"a@b"@example.com', 'a b@example.com', 'a\\b@example.com', "a'b@example.com", 'a!#$%&*/=?^`{|}~@example.com',
    'a..b@example.com', '.a@example.com', 'a.@example.com', 'a@example..com', 'a@.example.com', 'a@example.com.', 'a@-example.com', 'a@example-.com',
    'a@exa_mple.com', 'a@ex ample.com', 'a@münchen.de', 'A@MÜNCHEN.DE', 'a@xn--mnchen-3ya.de', 'ä@example.com', 'ÄÖÜ@EXAMPLE.COM', 'İ@example.com',
    'user@例子.中国', 'пользователь@пример.рф', "a@exa\xffmple.com", "\xff@example.com", "a\xc3@example.com", "\xff", "a@\xff", 'a@b@c', '@', 'a@', '@a', '@@',
    '', ' ', "\n", '0', ' 0 ', 'a', 'a@0', '0@a', 'a@ 0', 'a@HTTP://example.com', 'a@https://example.com', 'a@http://', 'a@http:/x.com',
    'a@ftp://x.com', 'a@example.com:25', 'a@example.com/path', 'mailto:a@example.com', '<a@example.com>', 'Name <a@example.com>',
    str_repeat('a', 64) . '@example.com', str_repeat('a', 65) . '@example.com', str_repeat('ä', 64) . '@example.com', str_repeat('ä', 33) . '@example.com',
    'a@' . str_repeat('b', 63) . '.com', 'a@' . str_repeat('b', 64) . '.com', 'a@' . str_repeat('abcdefghi.', 24) . 'example.com',
    'a@' . str_repeat('abcdefghi.', 25) . 'com', 'a@' . str_repeat('a.', 126) . 'b', 'a@' . str_repeat('a.', 127) . 'b',
    'user@gmail.com', 'User.Name+x@GoogleMail.com', 'user@hotmail.com', 'user@yahoo.com', 'user@icloud.com', 'user@proton.me', 'user@mailinator.com',
    'user@x.mailinator.com', 'user@6s.lucktaro.com', 'user@lucktaro.com', 'user@it.com', 'user@x.it.com', 'user@10minutemail.com', 'user@appwrite.io',
    'user@cloud.appwrite.io', 'user@company.org', 'user@gmail.co', 'user@gmail.com.', 'user@mail.gmail.com',
];
foreach ($addresses as $address) {
    emit('edge ' . $address, outcome(fn () => inspect($address)));
}

// Random addresses over characters FILTER_VALIDATE_EMAIL and hasValidLocal() decide on.
mt_srand(5322);
$alphabet = ['a', 'b', 'Z', '0', '9', '.', '.', '-', '_', '+', '@', '"', '\\', ' ', '!', '#', '$', '%', '&', "'", '*', '/', '=', '?', '^', '`', '{', '|', '}', '~',
    '(', ')', ',', ':', ';', '<', '>', '[', ']', "\t", "\n", 'ä', "\xff", 'xn--', 'com', 'co.uk', 'test', 'local'];
$random = 0;
for ($i = 0; $i < 20000; $i++) {
    $part = function (int $max) use ($alphabet): string {
        $length = mt_rand(0, $max);
        $text = '';
        for ($j = 0; $j < $length; $j++) {
            $text .= $alphabet[mt_rand(0, count($alphabet) - 1)];
        }

        return $text;
    };
    $address = $part(8) . '@' . $part(3) . 'x' . (mt_rand(0, 1) ? '.com' : $part(4));
    $result = outcome(function () use ($address) {
        $email = new Email($address);

        return [$email->get(), $email->isValid(), $email->hasValidLocal(), $email->hasValidDomain(), attempt(fn () => $email->getCanonical())];
    });
    emit('random ' . $address, $result);
    $random++;
}
fwrite(STDERR, $entries . ' list domains, ' . count($addresses) . ' edge cases, ' . $random . ' random addresses' . PHP_EOL);

// The properties PHP fills, after each single getter and after orders of getters.
$getters = ['get', 'getLocal', 'getDomain', 'isValid', 'hasValidLocal', 'hasValidDomain', 'isDisposable', 'isFree', 'isCorporate', 'getProvider',
    'getSubdomain', 'hasSubdomain', 'getCanonical', 'isCanonicalSupported', 'getCanonicalDomain', 'getFormatted'];
foreach (['user@mail.example.co.uk', 'user@localhost', 'user@a.unknowntld', 'user@gmail.com', 'user@x.mailinator.com', 'user@0.example.com'] as $value) {
    emit('fresh ' . $value, outcome(fn () => properties(new Email($value))));
    foreach ($getters as $getter) {
        emit($getter . ' ' . $value, outcome(function () use ($value, $getter) {
            $email = new Email($value);
            $email->$getter();

            return properties($email);
        }));
    }
    emit('all reversed ' . $value, outcome(function () use ($value, $getters) {
        $email = new Email($value);
        $results = array_map(fn ($getter) => $email->$getter(), array_reverse($getters));

        return [$results, properties($email)];
    }));
}

// What PHP converts a string parameter from outside strict_types.
$stringable = new class () {
    public function __toString(): string
    {
        return 'Stringable@Example.COM';
    }
};
foreach (['int' => 5, 'float' => 1.5, 'true' => true, 'false' => false, 'stringable' => $stringable] as $name => $argument) {
    emit('argument ' . $name, outcome(fn () => inspect($argument)));
}
foreach (['null' => null, 'array' => [], 'object' => new stdClass()] as $name => $argument) {
    emit('argument ' . $name, ['throws' => outcome(fn () => new Email($argument))['throws'] ?? null]);
}
foreach (['int' => 5, 'float' => 1.5, 'true' => true, 'stringable' => $stringable, 'local' => 'local', 'LOCAL' => 'LOCAL'] as $name => $argument) {
    emit('format ' . $name, outcome(fn () => (new Email('user@mail.example.com'))->getFormatted($argument)));
}
emit('format null', ['throws' => outcome(fn () => (new Email('user@example.com'))->getFormatted(null))['throws'] ?? null]);

// The object itself.
emit('equal fresh', outcome(fn () => new Email('a@example.com') == new Email('a@example.com')));
emit('equal different', outcome(fn () => new Email('a@example.com') == new Email('b@example.com')));
emit('equal case', outcome(fn () => new Email('A@Example.com') == new Email('a@example.com')));
emit('equal after getter', outcome(function () {
    $one = new Email('a@mail.example.com');
    $one->getProvider();

    return [$one == new Email('a@mail.example.com'), $one == $one];
}));
emit('clone', outcome(function () {
    $email = new Email('a@mail.example.co.uk');
    $email->getSubdomain();
    $copy = clone $email;
    $copy->getProvider();

    return [properties($copy), properties($copy)['domainInstance'] === properties($email)['domainInstance'], $copy == $email];
}));
/** $dump with each object handle ("#12 ") dropped: handles differ between runs. */
function withoutHandles(string $dump): string
{
    $parts = explode('#', $dump);
    $result = array_shift($parts);
    foreach ($parts as $part) {
        $digits = strspn($part, '0123456789');
        $result .= '#' . ($digits > 0 && ($part[$digits] ?? '') === ' ' ? substr($part, $digits) : $part);
    }

    return $result;
}

emit('var_dump', outcome(function () {
    $email = new Email(' User@Mail.Example.CO.UK ');
    $email->getProvider();
    ob_start();
    var_dump($email);

    return withoutHandles(ob_get_clean());
}));
emit('read protected', outcome(fn () => (new Email('a@example.com'))->email));
emit('instanceof', outcome(fn () => new Email('a@example.com') instanceof Email));
emit('final', outcome(fn () => (new ReflectionClass(Email::class))->isFinal()));
emit('constants', outcome(fn () => (new ReflectionClass(Email::class))->getConstants()));
emit('subclass', outcome(function () {
    $sub = new class ('User@Mail.Example.CO.UK') extends Email {
        public function shout(): string
        {
            return strtoupper($this->getProvider());
        }
    };

    return [$sub->shout(), $sub->getSubdomain(), $sub instanceof Email, $sub->getFormatted(Email::FORMAT_LOCAL)];
}));
emit('subclass properties', outcome(function () {
    $sub = new class ('User@Mail.Example.CO.UK') extends Email {
        public function inside(): array
        {
            return [$this->email, $this->local, $this->domain, $this->parts, $this->domainInstance->getApex(), self::LOCAL_MAX_LENGTH];
        }
    };

    return [$sub->inside(), properties($sub)];
}));
$methods = (new ReflectionClass(Email::class))->getMethods(ReflectionMethod::IS_PUBLIC);
usort($methods, fn ($a, $b) => strcmp($a->getName(), $b->getName()));
emit('methods', outcome(fn () => array_map(fn (ReflectionMethod $method) => [$method->getName(), $method->isStatic(), $method->getNumberOfParameters(), $method->getNumberOfRequiredParameters()], $methods)));
emit('validators stay PHP', outcome(fn () => array_map(fn (string $class) => (new ReflectionClass($class))->isInternal(), [
    Utopia\Emails\Validator\Email::class,
    Utopia\Emails\Validator\EmailCorporate::class,
    Utopia\Emails\Validator\EmailDomain::class,
    Utopia\Emails\Validator\EmailLocal::class,
    Utopia\Emails\Validator\EmailNotDisposable::class,
    Utopia\Emails\Canonicals\Provider::class,
    Utopia\Emails\Canonicals\Providers\Gmail::class,
])));
$samples = ['user@example.com', 'user@gmail.com', 'user@mailinator.com', 'a..b@example.com', 'a@localhost', 'not an email', '', 'a@b@c', "\xff@x.com", 'a@http://x.com'];
foreach (['Email', 'EmailCorporate', 'EmailDomain', 'EmailLocal', 'EmailNotDisposable'] as $validator) {
    $class = "Utopia\\Emails\\Validator\\{$validator}";
    emit('validator ' . $validator, outcome(fn () => array_map(fn ($value) => attempt(fn () => (new $class())->isValid($value)), $samples)));
}

// Cloning a PHP subclass, with and without properties of its own: the copy keeps its class
// and its properties, and shares $domainInstance as PHP's shallow copy does.
emit('subclass clone', outcome(function () {
    $sub = new class ('User@Mail.Example.CO.UK') extends Email {
    };
    $sub->getProvider();
    $copy = clone $sub;

    return [$copy::class === $sub::class, $copy instanceof Email, $copy->getProvider(), $copy == $sub];
}));
emit('subclass with properties clone', outcome(function () {
    $sub = new class ('User@Mail.Example.CO.UK') extends Email {
        public string $label = 'first';

        public array $seen = [];
    };
    $sub->seen[] = $sub->getSubdomain();
    $copy = clone $sub;
    $copy->label = 'copy';
    $copy->seen[] = 'more';

    return [$copy::class === $sub::class, $sub->label, $copy->label, $sub->seen, $copy->seen, $copy->getProvider(), properties($copy)];
}));

// Known differences of the extension, each a waiver in crates/extension/README.md.
$waivers = [
    'protected helpers' => outcome(fn () => array_map(fn (string $method) => method_exists(Email::class, $method), ['initializeProviders', 'getProviderForDomain', 'isDomainSupported'])),
    'protected static properties' => outcome(fn () => array_keys((new ReflectionClass(Email::class))->getStaticProperties())),
];
$expected = [
    'protected helpers' => ['value' => [false, false, false]],
    'protected static properties' => ['value' => []],
];
foreach ($expected as $name => $outcome) {
    if ($waivers[$name] !== $outcome) {
        fwrite(STDERR, "Waiver changed: {$name}: " . json_encode(exported($waivers[$name])) . PHP_EOL);
        exit(1);
    }
}
fwrite(STDERR, count($expected) . ' waivers hold' . PHP_EOL);
