<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator\Hostname;
use Utopia\Validator\Validator;

/**
 * A hostname this deployment serves on: its domain, any subdomain of it, or its internal
 * migration host. Each is configured by the operator as a bare host, a host and port, or a URL.
 * These may resolve to internal addresses, so they are trusted without a public address check.
 */
class DeploymentHostname extends Validator
{
    /**
     * @var list<string>
     */
    private readonly array $hosts;

    public function __construct(string $domain, string $migrationHost)
    {
        $domain = self::host($domain);

        $this->hosts = \array_values(\array_filter([
            $domain,
            $domain !== '' ? '*.' . $domain : '',
            self::host($migrationHost),
        ]));
    }

    public function getDescription(): string
    {
        return 'Value must be a hostname this deployment serves on.';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    public function isValid(mixed $value): bool
    {
        return \is_string($value)
            && $this->hosts !== []
            && (new Hostname($this->hosts))->isValid(\strtolower($value));
    }

    private static function host(string $value): string
    {
        $value = \trim($value);

        if ($value === '') {
            return '';
        }

        $url = \str_contains($value, '://') ? $value : 'http://' . $value;

        return \strtolower(\parse_url($url, PHP_URL_HOST) ?: '');
    }
}
