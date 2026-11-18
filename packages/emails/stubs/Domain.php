<?php

// Utopia\Domains\Domain is registered by the utopia PHP extension (crates/extension in
// appwrite/appwrite). This file only declares it for static analysis and editors; it is never loaded.
// A copy of packages/domains/stubs/Domain.php: PHPStan for packages/emails may only read files of
// this package, and runs where utopia-php/domains has no PHP Domain.

namespace Utopia\Domains;

/**
 * A domain or hostname, split by the Public Suffix List into its suffix, name and subdomain.
 *
 * The protected properties are filled as their getters run, as in the PHP class this replaced.
 */
class Domain
{
    protected string $domain;

    protected string $TLD = '';

    protected string $suffix = '';

    protected string $name = '';

    protected string $sub = '';

    protected string $rule = '';

    /**
     * @var string[]
     */
    protected array $parts;

    /**
     * @throws \Exception when the value is a URL (`http://`, `https://`)
     */
    public function __construct(string $domain)
    {
    }

    public function get(): string
    {
    }

    public function getApex(): string
    {
    }

    public function getTLD(): string
    {
    }

    public function getSuffix(): string
    {
    }

    public function getRule(): string
    {
    }

    public function getRegisterable(): string
    {
    }

    public function getName(): string
    {
    }

    public function getSub(): string
    {
    }

    public function isKnown(): bool
    {
    }

    public function isICANN(): bool
    {
    }

    public function isPrivate(): bool
    {
    }

    public function isTest(): bool
    {
    }
}
