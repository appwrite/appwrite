<?php

namespace Appwrite\Network\Validator;

use Appwrite\Network\HostnameNotResolved;
use InvalidArgumentException;
use Utopia\Client\Destinations;
use Utopia\DNS\Lookup;
use Utopia\Validator\Validator;

/**
 * A hostname or IP literal Appwrite may connect to on a user's behalf: it resolves, and
 * only to addresses the destination allows. Checked where a request accepts a host it
 * later connects to; a fetch through the client is checked again on the address it
 * connects to.
 *
 * Distinct from Utopia\Validator\Hostname, which only checks string format and an optional
 * allow-list and does not touch DNS.
 */
class PublicHostname extends Validator
{
    private string $reason = '';

    public function __construct(
        private readonly Destinations $destinations,
        private readonly Lookup $lookup,
    ) {
    }

    public function getDescription(): string
    {
        return $this->reason !== ''
            ? $this->reason
            : 'Value must be a publicly routable hostname or address.';
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
        $this->reason = '';

        if (!\is_string($value)) {
            $this->reason = 'Hostname is empty.';
            return false;
        }

        try {
            $this->address($value);
        } catch (InvalidArgumentException $exception) {
            $this->reason = $exception->getMessage();
            return false;
        }

        return true;
    }

    /**
     * An address to connect to the host on, for a connection no client can check (a
     * migration's Postgres connection). The host must resolve, and to allowed addresses
     * only, since the connection could land on any of them. Connect to the returned
     * address, not the host, so DNS cannot answer differently at connect time.
     *
     * @throws HostnameNotResolved When the host gave no address
     * @throws InvalidArgumentException When the host resolves to an address the destination does not allow
     */
    public function address(string $host): string
    {
        $host = \strtolower(\trim($host, " \t\n\r\0\x0B[]"));

        if ($host === '') {
            throw new InvalidArgumentException('Hostname is empty.');
        }

        $addresses = \filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->lookup->addresses($host);

        if ($addresses === []) {
            throw new HostnameNotResolved("Hostname {$host} does not resolve.");
        }

        foreach ($addresses as $address) {
            if (!$this->destinations->allows($address)) {
                throw new InvalidArgumentException($address === $host
                    ? "Address {$host} is in a private or reserved range."
                    : "Hostname {$host} resolves to private or reserved address {$address}.");
            }
        }

        return $addresses[0];
    }

    /**
     * address() as the rule a mail adapter dials by. The adapter reads an
     * InvalidArgumentException as a refusal that no retry changes, and anything else as
     * an answer it could not get now. A host that gave no address is the second kind: a
     * DNS server that failed a moment ago would otherwise make mail permanently
     * undeliverable.
     *
     * @return \Closure(string): string
     */
    public function resolver(): \Closure
    {
        return function (string $host): string {
            try {
                return $this->address($host);
            } catch (HostnameNotResolved $exception) {
                throw new \RuntimeException($exception->getMessage(), previous: $exception);
            }
        };
    }
}
