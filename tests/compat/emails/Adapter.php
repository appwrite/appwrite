<?php

namespace Tests\Compat\Emails;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Emails\Canonicals\Provider;
use Utopia\Emails\Canonicals\Providers\Fastmail;
use Utopia\Emails\Canonicals\Providers\Generic;
use Utopia\Emails\Canonicals\Providers\Gmail;
use Utopia\Emails\Canonicals\Providers\Icloud;
use Utopia\Emails\Canonicals\Providers\Outlook;
use Utopia\Emails\Canonicals\Providers\Protonmail;
use Utopia\Emails\Canonicals\Providers\Walla;
use Utopia\Emails\Canonicals\Providers\Yahoo;
use Utopia\Emails\Canonicals\Providers\Yandex;
use Utopia\Emails\Email;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Emails\Validator\EmailCorporate;
use Utopia\Emails\Validator\EmailDomain;
use Utopia\Emails\Validator\EmailLocal;
use Utopia\Emails\Validator\EmailNotDisposable;
use Utopia\Validator\Validator;

/**
 * Maps tests/compat/emails/spec.json operations onto utopia-php/emails. Glue only: no logic.
 */
final class Adapter implements Base
{
    public function operations(): array
    {
        return [
            'email.get' => fn (array $a, Session $s) => self::email($a)->get(),
            'email.local' => fn (array $a, Session $s) => self::email($a)->getLocal(),
            'email.domain' => fn (array $a, Session $s) => self::email($a)->getDomain(),
            'email.is_valid' => fn (array $a, Session $s) => self::email($a)->isValid(),
            'email.has_valid_local' => fn (array $a, Session $s) => self::email($a)->hasValidLocal(),
            'email.has_valid_domain' => fn (array $a, Session $s) => self::email($a)->hasValidDomain(),
            'email.is_disposable' => fn (array $a, Session $s) => self::email($a)->isDisposable(),
            'email.is_free' => fn (array $a, Session $s) => self::email($a)->isFree(),
            'email.is_corporate' => fn (array $a, Session $s) => self::email($a)->isCorporate(),
            'email.provider' => fn (array $a, Session $s) => self::email($a)->getProvider(),
            'email.subdomain' => fn (array $a, Session $s) => self::email($a)->getSubdomain(),
            'email.has_subdomain' => fn (array $a, Session $s) => self::email($a)->hasSubdomain(),
            'email.canonical' => fn (array $a, Session $s) => self::email($a)->getCanonical(),
            'email.is_canonical_supported' => fn (array $a, Session $s) => self::email($a)->isCanonicalSupported(),
            'email.canonical_domain' => fn (array $a, Session $s) => self::email($a)->getCanonicalDomain(),
            'email.formatted' => fn (array $a, Session $s) => isset($a['format'])
                ? self::email($a)->getFormatted($a['format'])
                : self::email($a)->getFormatted(),
            'email.inspect' => fn (array $a, Session $s) => self::inspect(self::email($a)),

            'validator.is_valid' => fn (array $a, Session $s) => self::validator($a)->isValid($a['value'] ?? null),
            'validator.describe' => fn (array $a, Session $s) => [
                'description' => self::validator($a)->getDescription(),
                'type' => self::validator($a)->getType(),
                'array' => self::validator($a)->isArray(),
            ],

            'provider.supports' => fn (array $a, Session $s) => self::provider($a)->supports($a['domain']),
            'provider.canonical' => fn (array $a, Session $s) => self::provider($a)->getCanonical($a['local'], $a['domain']),
            'provider.canonical_domain' => fn (array $a, Session $s) => self::provider($a)->getCanonicalDomain(),
            'provider.supported_domains' => fn (array $a, Session $s) => self::provider($a)->getSupportedDomains(),
        ];
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function email(array $a): Email
    {
        return new Email($a['email']);
    }

    /**
     * Every accessor of one Email; an accessor that throws reports the exception as the protocol does.
     *
     * @return array<string, mixed>
     */
    private static function inspect(Email $email): array
    {
        $call = static function (callable $accessor): mixed {
            try {
                return $accessor();
            } catch (\Throwable $error) {
                return ['$error' => ['class' => $error::class, 'message' => $error->getMessage()]];
            }
        };

        return [
            'get' => $email->get(),
            'local' => $email->getLocal(),
            'domain' => $email->getDomain(),
            'isValid' => $email->isValid(),
            'hasValidLocal' => $email->hasValidLocal(),
            'hasValidDomain' => $email->hasValidDomain(),
            'isDisposable' => $email->isDisposable(),
            'isFree' => $email->isFree(),
            'isCorporate' => $email->isCorporate(),
            'provider' => $email->getProvider(),
            'subdomain' => $email->getSubdomain(),
            'hasSubdomain' => $email->hasSubdomain(),
            'canonical' => $call(fn () => $email->getCanonical()),
            'isCanonicalSupported' => $email->isCanonicalSupported(),
            'canonicalDomain' => $email->getCanonicalDomain(),
        ];
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function validator(array $a): Validator
    {
        return match ($a['validator']) {
            'email' => isset($a['allow_empty']) ? new EmailValidator($a['allow_empty']) : new EmailValidator(),
            'domain' => new EmailDomain(),
            'local' => new EmailLocal(),
            'corporate' => new EmailCorporate(),
            'not_disposable' => new EmailNotDisposable(),
            default => throw new Fault("unknown validator `{$a['validator']}`"),
        };
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function provider(array $a): Provider
    {
        return match ($a['provider']) {
            'gmail' => new Gmail(),
            'outlook' => new Outlook(),
            'yahoo' => new Yahoo(),
            'icloud' => new Icloud(),
            'protonmail' => new Protonmail(),
            'fastmail' => new Fastmail(),
            'yandex' => new Yandex(),
            'walla' => new Walla(),
            'generic' => new Generic(),
            default => throw new Fault("unknown provider `{$a['provider']}`"),
        };
    }
}
