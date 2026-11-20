<?php

// What opcache does with the extension's classes when it caches a script that extends them:
// it copies each PHP subclass, with the defaults and constants it inherits, into shared memory,
// and frees what it copied from as if PHP's allocator held it. A default or constant the
// extension built outside that allocator corrupts the heap there. run.sh runs this with
// opcache on (`opcache.enable_cli=1`), every library switched on, and PHP's allocator; it
// prints `ok` and exits 0, or crashes.

require __DIR__ . '/../../../vendor/autoload.php';

if (!(opcache_get_status(false)['opcache_enabled'] ?? false)) {
    fwrite(STDERR, 'opcache is not enabled for the CLI' . PHP_EOL);
    exit(1);
}

// A subclass of every class the extension registers that PHP code may extend (the ones whose
// library is switched off are PHP's, which costs nothing to check too; the validators are
// always the extension's). opcache links each one
// through its inheritance cache, which copies the class into shared memory.
if (class_exists(\Utopia\Validator\Validator::class)) {
    abstract class ExtendsUtopiaValidatorValidator extends \Utopia\Validator\Validator
    {
    }
}
if (class_exists(\Utopia\Validator\Boolean::class)) {
    class ExtendsUtopiaValidatorBoolean extends \Utopia\Validator\Boolean
    {
    }
}
if (class_exists(\Utopia\Validator\Integer::class)) {
    class ExtendsUtopiaValidatorInteger extends \Utopia\Validator\Integer
    {
    }
}
if (class_exists(\Utopia\Validator\FloatValidator::class)) {
    class ExtendsUtopiaValidatorFloatValidator extends \Utopia\Validator\FloatValidator
    {
    }
}
if (class_exists(\Utopia\Validator\Numeric::class)) {
    class ExtendsUtopiaValidatorNumeric extends \Utopia\Validator\Numeric
    {
    }
}
if (class_exists(\Utopia\Validator\Range::class)) {
    class ExtendsUtopiaValidatorRange extends \Utopia\Validator\Range
    {
    }
}
if (class_exists(\Utopia\Validator\Port::class)) {
    class ExtendsUtopiaValidatorPort extends \Utopia\Validator\Port
    {
    }
}
if (class_exists(\Utopia\Validator\HexColor::class)) {
    class ExtendsUtopiaValidatorHexColor extends \Utopia\Validator\HexColor
    {
    }
}
if (class_exists(\Utopia\Validator\Wildcard::class)) {
    class ExtendsUtopiaValidatorWildcard extends \Utopia\Validator\Wildcard
    {
    }
}
if (class_exists(\Utopia\Validator\Text::class)) {
    class ExtendsUtopiaValidatorText extends \Utopia\Validator\Text
    {
    }
}
if (class_exists(\Utopia\Validator\Identifier::class)) {
    class ExtendsUtopiaValidatorIdentifier extends \Utopia\Validator\Identifier
    {
    }
}
if (class_exists(\Utopia\Validator\WhiteList::class)) {
    class ExtendsUtopiaValidatorWhiteList extends \Utopia\Validator\WhiteList
    {
    }
}
if (class_exists(\Utopia\Validator\Contains::class)) {
    class ExtendsUtopiaValidatorContains extends \Utopia\Validator\Contains
    {
    }
}
if (class_exists(\Utopia\Validator\Label::class)) {
    class ExtendsUtopiaValidatorLabel extends \Utopia\Validator\Label
    {
    }
}
if (class_exists(\Utopia\Validator\Hostname::class)) {
    class ExtendsUtopiaValidatorHostname extends \Utopia\Validator\Hostname
    {
    }
}
if (class_exists(\Utopia\Validator\Domain::class)) {
    class ExtendsUtopiaValidatorDomain extends \Utopia\Validator\Domain
    {
    }
}
if (class_exists(\Utopia\Validator\Host::class)) {
    class ExtendsUtopiaValidatorHost extends \Utopia\Validator\Host
    {
    }
}
if (class_exists(\Utopia\Validator\IP::class)) {
    class ExtendsUtopiaValidatorIP extends \Utopia\Validator\IP
    {
    }
}
if (class_exists(\Utopia\Validator\URL::class)) {
    class ExtendsUtopiaValidatorURL extends \Utopia\Validator\URL
    {
    }
}
if (class_exists(\Utopia\Validator\Phone::class)) {
    class ExtendsUtopiaValidatorPhone extends \Utopia\Validator\Phone
    {
    }
}
if (class_exists(\Utopia\Validator\Nullable::class)) {
    class ExtendsUtopiaValidatorNullable extends \Utopia\Validator\Nullable
    {
    }
}
if (class_exists(\Utopia\Validator\ArrayList::class)) {
    class ExtendsUtopiaValidatorArrayList extends \Utopia\Validator\ArrayList
    {
    }
}
if (class_exists(\Utopia\Validator\AllOf::class)) {
    class ExtendsUtopiaValidatorAllOf extends \Utopia\Validator\AllOf
    {
    }
}
if (class_exists(\Utopia\Validator\AnyOf::class)) {
    class ExtendsUtopiaValidatorAnyOf extends \Utopia\Validator\AnyOf
    {
    }
}
if (class_exists(\Utopia\Validator\NoneOf::class)) {
    class ExtendsUtopiaValidatorNoneOf extends \Utopia\Validator\NoneOf
    {
    }
}
if (class_exists(\Utopia\Validator\Multiple::class)) {
    class ExtendsUtopiaValidatorMultiple extends \Utopia\Validator\Multiple
    {
    }
}
if (class_exists(\Utopia\Validator\Assoc::class)) {
    class ExtendsUtopiaValidatorAssoc extends \Utopia\Validator\Assoc
    {
    }
}
if (class_exists(\Utopia\Validator\JSON::class)) {
    class ExtendsUtopiaValidatorJSON extends \Utopia\Validator\JSON
    {
    }
}
if (class_exists(\Utopia\Validator\JSON\ArrayValidator::class)) {
    class ExtendsUtopiaValidatorJSONArrayValidator extends \Utopia\Validator\JSON\ArrayValidator
    {
    }
}
if (class_exists(\Utopia\Validator\JSON\ObjectValidator::class)) {
    class ExtendsUtopiaValidatorJSONObjectValidator extends \Utopia\Validator\JSON\ObjectValidator
    {
    }
}
if (class_exists(\Utopia\Validator\JSON\FCM::class)) {
    class ExtendsUtopiaValidatorJSONFCM extends \Utopia\Validator\JSON\FCM
    {
    }
}
if (class_exists(\Utopia\Validator\Globstar::class)) {
    class ExtendsUtopiaValidatorGlobstar extends \Utopia\Validator\Globstar
    {
    }
}
if (class_exists(\Utopia\Auth\Hash::class)) {
    abstract class ExtendsUtopiaAuthHash extends \Utopia\Auth\Hash
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\Argon2::class)) {
    class ExtendsUtopiaAuthHashesArgon2 extends \Utopia\Auth\Hashes\Argon2
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\Bcrypt::class)) {
    class ExtendsUtopiaAuthHashesBcrypt extends \Utopia\Auth\Hashes\Bcrypt
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\MD5::class)) {
    class ExtendsUtopiaAuthHashesMD5 extends \Utopia\Auth\Hashes\MD5
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\PHPass::class)) {
    class ExtendsUtopiaAuthHashesPHPass extends \Utopia\Auth\Hashes\PHPass
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\Plaintext::class)) {
    class ExtendsUtopiaAuthHashesPlaintext extends \Utopia\Auth\Hashes\Plaintext
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\Scrypt::class)) {
    class ExtendsUtopiaAuthHashesScrypt extends \Utopia\Auth\Hashes\Scrypt
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\ScryptModified::class)) {
    class ExtendsUtopiaAuthHashesScryptModified extends \Utopia\Auth\Hashes\ScryptModified
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\Sha::class)) {
    class ExtendsUtopiaAuthHashesSha extends \Utopia\Auth\Hashes\Sha
    {
    }
}
if (class_exists(\Utopia\DSN\DSN::class)) {
    class ExtendsUtopiaDSNDSN extends \Utopia\DSN\DSN
    {
    }
}
if (class_exists(\Utopia\Locale\Locale::class)) {
    class ExtendsUtopiaLocaleLocale extends \Utopia\Locale\Locale
    {
    }
}
if (class_exists(\Utopia\Domains\Domain::class)) {
    class ExtendsUtopiaDomainsDomain extends \Utopia\Domains\Domain
    {
    }
}
if (class_exists(\Utopia\Emails\Email::class)) {
    class ExtendsUtopiaEmailsEmail extends \Utopia\Emails\Email
    {
    }
}

if (class_exists(\Utopia\Query\Query::class)) {
    class ExtendsUtopiaQueryQuery extends \Utopia\Query\Query
    {
    }
}

$extended = [];
foreach ((new ReflectionExtension('utopia'))->getClassNames() as $name) {
    $class = new ReflectionClass($name);
    if (!$class->isFinal() && $class->getName() === $name) {
        $extended[$name] = 'Extends' . str_replace('\\', '', $name);
    }
}
foreach ($extended as $name => $child) {
    if (!class_exists($child, false)) {
        fwrite(STDERR, $name . ' has no subclass here; add one' . PHP_EOL);
        exit(1);
    }
}

/** The inherited defaults and constants of $child, used as PHP code uses them. */
function use_inherited(string $child): array
{
    $class = new ReflectionClass($child);
    $seen = [];
    foreach ($class->getDefaultProperties() as $name => $value) {
        if (is_array($value)) {
            $value[] = 'appended';
        }
        $seen[$name] = get_debug_type($value);
    }
    foreach ($class->getReflectionConstants() as $constant) {
        if ($constant->isPrivate()) {
            continue;
        }
        $value = constant($child . '::' . $constant->getName());
        if (is_array($value)) {
            $value[] = 'appended';
            sort($value);
        }
        $seen[$constant->getName()] = get_debug_type($value);
    }

    return $seen;
}

$seen = 0;
foreach ($extended as $child) {
    $seen += count(use_inherited($child));
}

echo 'ok ', count($extended), ' classes, ', $seen, ' inherited members', PHP_EOL;
