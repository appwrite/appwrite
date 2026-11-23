<?php

namespace Appwrite\SMTP\Validator;

use Appwrite\Network\Validator\PublicHostname;
use InvalidArgumentException;
use Utopia\Validator\Validator;

/**
 * An SMTP host a project configures and Appwrite dials: one server, or several separated
 * by semicolons, each optionally led by ssl:// or tls:// and followed by :port, read the
 * way the SMTP adapter reads them. Every server must resolve to public addresses only.
 */
class Host extends Validator
{
    private string $reason = '';

    /**
     * @param bool $resolved Whether every server must resolve now. A host stored for later may not resolve yet; it is checked again when dialled.
     */
    public function __construct(
        private readonly PublicHostname $hostname,
        private readonly bool $resolved = true,
    ) {
    }

    public function getDescription(): string
    {
        return $this->reason !== ''
            ? $this->reason
            : 'Value must name SMTP servers that resolve to publicly routable addresses.';
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
            foreach ($this->servers($value) as $server) {
                $this->resolved ? $this->hostname->address($server) : $this->hostname->addresses($server);
            }
        } catch (InvalidArgumentException $exception) {
            $this->reason = $exception->getMessage();
            return false;
        }

        return true;
    }

    /**
     * Each server as the adapter names it, with the address to dial it on, so the connection
     * lands where the check looked.
     *
     * @return array<string, string>
     * @throws InvalidArgumentException When a server does not resolve or resolves to an address the destination does not allow
     */
    public function addresses(string $value): array
    {
        $addresses = [];

        foreach ($this->servers($value) as $server) {
            $address = $this->hostname->address($server);
            $addresses[$server] = \str_contains($address, ':') ? "[{$address}]" : $address;
        }

        return $addresses;
    }

    /**
     * @return list<string>
     * @throws InvalidArgumentException When no server is named
     */
    private function servers(string $value): array
    {
        $servers = [];

        foreach (\explode(';', $value) as $entry) {
            $entry = \trim($entry);

            if ($entry === '') {
                continue;
            }

            if (\in_array(\strtolower(\substr($entry, 0, 6)), ['ssl://', 'tls://'], true)) {
                $entry = \substr($entry, 6);
            }

            // A port follows a bracketed address literal or a name without colons; a bare
            // IPv6 literal keeps its colons.
            $separator = \strrpos($entry, ':');
            if ($separator !== false && \ctype_digit(\substr($entry, $separator + 1))) {
                $name = \substr($entry, 0, $separator);
                if ((\str_starts_with($name, '[') && \str_ends_with($name, ']')) || !\str_contains($name, ':')) {
                    $entry = $name;
                }
            }

            $servers[] = $entry;
        }

        if ($servers === []) {
            throw new InvalidArgumentException('Hostname is empty.');
        }

        return $servers;
    }
}
