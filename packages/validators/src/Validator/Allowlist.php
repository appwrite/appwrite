<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Validates that a value is a listed hostname, or an IP address inside one of
 * the listed subnets. Hostnames match exactly, ignoring case and a trailing
 * dot, with no wildcard or suffix matching. A listed hostname never admits an
 * address, and a subnet never admits a hostname.
 */
class Allowlist extends Validator
{
    /**
     * @var array<string>
     */
    private readonly array $hostnames;

    /**
     * @param array<string> $hostnames
     * @param array<Subnet> $subnets
     */
    public function __construct(
        array $hostnames = [],
        private readonly array $subnets = [],
    ) {
        $this->hostnames = \array_values(\array_unique(\array_map($this->normalize(...), $hostnames)));
    }

    public function getDescription(): string
    {
        return 'Value must be an allowed hostname or an IP address in an allowed subnet.';
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
        if (!\is_string($value)) {
            return false;
        }

        return $this->hasHostname($value) || $this->hasAddress(\trim($value, '[]'));
    }

    public function hasSubnets(): bool
    {
        return $this->subnets !== [];
    }

    public function hasHostname(string $hostname): bool
    {
        return \in_array($this->normalize($hostname), $this->hostnames, true);
    }

    public function hasAddress(string $address): bool
    {
        return \array_any($this->subnets, fn (Subnet $subnet): bool => $subnet->isValid($address));
    }

    private function normalize(string $hostname): string
    {
        $hostname = \strtolower($hostname);

        return \str_ends_with($hostname, '.') ? \substr($hostname, 0, -1) : $hostname;
    }
}
