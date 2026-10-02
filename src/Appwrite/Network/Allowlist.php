<?php

declare(strict_types=1);

namespace Appwrite\Network;

use Appwrite\Network\Validator\PublicHostname;

final readonly class Allowlist
{
    /**
     * @var array<string>
     */
    private array $hostnames;

    /**
     * @param array<string> $hostnames
     * @param array<Subnet> $subnets
     */
    public function __construct(
        array $hostnames = [],
        private array $subnets = [],
    ) {
        $this->hostnames = \array_values(\array_unique(\array_map(self::normalize(...), $hostnames)));
    }

    public static function parse(string $value): self
    {
        $hostnames = [];
        $subnets = [];

        foreach (\explode(',', $value) as $entry) {
            $entry = \trim($entry);

            if ($entry === '') {
                continue;
            }

            if (\str_contains($entry, '/') || \filter_var(\trim($entry, '[]'), FILTER_VALIDATE_IP) !== false) {
                $subnet = Subnet::parse(\str_contains($entry, '/') ? $entry : \trim($entry, '[]'));

                if ($subnet !== null) {
                    $subnets[] = $subnet;
                }

                continue;
            }

            $hostname = self::normalize($entry);

            if (
                \filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
                && !PublicHostname::isNumericAddress($hostname)
            ) {
                $hostnames[] = $hostname;
            }
        }

        return new self($hostnames, $subnets);
    }

    public function isEmpty(): bool
    {
        return $this->hostnames === [] && $this->subnets === [];
    }

    public function hasSubnets(): bool
    {
        return $this->subnets !== [];
    }

    public function hasHostname(string $hostname): bool
    {
        return \in_array(self::normalize($hostname), $this->hostnames, true);
    }

    public function hasAddress(string $address): bool
    {
        foreach ($this->subnets as $subnet) {
            if ($subnet->contains($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $addresses
     */
    public function admits(array $addresses): bool
    {
        $listed = false;

        foreach ($addresses as $address) {
            if ($this->hasAddress($address)) {
                $listed = true;
            } elseif (!PublicHostname::isPublicIp($address)) {
                return false;
            }
        }

        return $listed;
    }

    private static function normalize(string $hostname): string
    {
        $hostname = \strtolower($hostname);

        return \str_ends_with($hostname, '.') ? \substr($hostname, 0, -1) : $hostname;
    }
}
