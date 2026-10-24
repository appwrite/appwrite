<?php

namespace Appwrite\SMTP\Validator;

use Appwrite\Network\Allowlist;
use Appwrite\Network\Validator\PublicHostname;
use InvalidArgumentException;
use Utopia\System\System;
use Utopia\Validator;

/**
 * An SMTP host a project configures and Appwrite dials: one server, or several separated
 * by semicolons, each optionally led by ssl:// or tls:// and followed by :port, read the
 * way the SMTP adapter reads them. Every server must resolve to public addresses only.
 */
class Host extends Validator
{
    private string $reason = '';

    private readonly PublicHostname $hostname;

    /**
     * @param bool $resolved Whether every server must resolve now. A host stored for later may not resolve yet; it is checked again when dialled.
     * @param PublicHostname|null $hostname The address policy; by default public addresses plus those listed in _APP_ALLOWED_INTERNAL_ADDRESSES.
     */
    public function __construct(
        private readonly bool $resolved = true,
        ?PublicHostname $hostname = null,
    ) {
        $this->hostname = $hostname ?? new PublicHostname(Allowlist::parse(System::getEnv('_APP_ALLOWED_INTERNAL_ADDRESSES', '')));
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
     * Each server entry rewritten to dial the address it was checked on, with the name its
     * certificate is verified against, for a client that dials one entry at a time.
     *
     * @return list<array{host: string, peer: string}>
     * @throws InvalidArgumentException When a server does not resolve or resolves to an address the destination does not allow
     */
    public function pinned(string $value): array
    {
        $pinned = [];

        foreach ($this->entries($value) as [$prefix, $server, $suffix]) {
            $address = $this->hostname->address($server);
            $pinned[] = [
                'host' => $prefix . (\str_contains($address, ':') ? "[{$address}]" : $address) . $suffix,
                'peer' => \trim($server, '[]'),
            ];
        }

        return $pinned;
    }

    /**
     * @return list<string>
     * @throws InvalidArgumentException When no server is named
     */
    private function servers(string $value): array
    {
        return \array_map(fn (array $entry): string => $entry[1], $this->entries($value));
    }

    /**
     * Each server entry split into its encryption prefix, server name and port suffix.
     *
     * @return list<array{string, string, string}>
     * @throws InvalidArgumentException When no server is named
     */
    private function entries(string $value): array
    {
        $entries = [];

        foreach (\explode(';', $value) as $entry) {
            $entry = \trim($entry);

            if ($entry === '') {
                continue;
            }

            $prefix = '';
            if (\in_array(\strtolower(\substr($entry, 0, 6)), ['ssl://', 'tls://'], true)) {
                $prefix = \substr($entry, 0, 6);
                $entry = \substr($entry, 6);
            }

            // A port follows a bracketed address literal or a name without colons; a bare
            // IPv6 literal keeps its colons.
            $suffix = '';
            $separator = \strrpos($entry, ':');
            if ($separator !== false && \ctype_digit(\substr($entry, $separator + 1))) {
                $name = \substr($entry, 0, $separator);
                if ((\str_starts_with($name, '[') && \str_ends_with($name, ']')) || !\str_contains($name, ':')) {
                    $suffix = \substr($entry, $separator);
                    $entry = $name;
                }
            }

            $entries[] = [$prefix, $entry, $suffix];
        }

        if ($entries === []) {
            throw new InvalidArgumentException('Hostname is empty.');
        }

        return $entries;
    }
}
