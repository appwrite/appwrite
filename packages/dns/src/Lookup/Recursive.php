<?php

declare(strict_types=1);

namespace Utopia\DNS\Lookup;

use InvalidArgumentException;
use Utopia\DNS\Client;
use Utopia\DNS\Lookup;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;

/**
 * Asks the given recursive resolvers, in order, rather than the system resolver, so names
 * only the local network knows (/etc/hosts, search domains, cluster services) never resolve.
 * A server that fails or answers only one address family is skipped; when none answers for
 * both, the hostname resolves to nothing rather than to half its addresses.
 */
final readonly class Recursive implements Lookup
{
    private const int NOERROR = 0;

    private const int NXDOMAIN = 3;

    /** @var list<array{string, int}> host and port of each server, in the order to ask them */
    private array $servers;

    /**
     * @param list<string> $servers "ip", "ip:port", "[ipv6]" or "[ipv6]:port"; without a port, 53
     *
     * @throws InvalidArgumentException When no server is given, or one is not an IP address with an optional valid port
     */
    public function __construct(array $servers)
    {
        if ($servers === []) {
            throw new InvalidArgumentException('At least one DNS server is required.');
        }

        $this->servers = \array_map(function (string $server): array {
            $host = $server;
            $digits = '53';
            if (\str_starts_with($server, '[')) {
                // [ipv6] or [ipv6]:port
                $close = \strpos($server, ']');
                $host = $close === false ? '' : \substr($server, 1, $close - 1);
                $rest = $close === false ? '' : \substr($server, $close + 1);
                if ($rest !== '') {
                    $digits = \str_starts_with($rest, ':') ? \substr($rest, 1) : '';
                }
            } elseif (\substr_count($server, ':') === 1) {
                // ipv4:port; more than one colon is a bare IPv6 address
                [$host, $digits] = \explode(':', $server, 2);
            }

            if (\filter_var($host, FILTER_VALIDATE_IP) === false || !\ctype_digit($digits) || (int) $digits > 65535) {
                throw new InvalidArgumentException("DNS server must be an IP address with an optional port: '{$server}'.");
            }

            return [$host, (int) $digits];
        }, $servers);
    }

    public function addresses(string $hostname): array
    {
        foreach ($this->servers as [$host, $port]) {
            try {
                // TCP: no 512-byte truncation of large answers, and IPv6 servers work
                $client = new Client($host, $port, useTcp: true);
                $addresses = [];
                foreach ([Record::TYPE_A, Record::TYPE_AAAA] as $type) {
                    $response = $client->query(Message::query(new Question($hostname, $type), recursionDesired: true));

                    // Anything but an answer (SERVFAIL, REFUSED) is this server failing: ask the next
                    if (!\in_array($response->header->responseCode, [self::NOERROR, self::NXDOMAIN], true)) {
                        continue 2;
                    }

                    foreach ($response->answers as $record) {
                        if ($record->type === $type) {
                            $addresses[] = $record->rdata;
                        }
                    }
                }

                return \array_values(\array_unique($addresses));
            } catch (\Throwable) {
                continue; // Unreachable server: ask the next
            }
        }

        // No server answered for both families: fail closed rather than trust half a list
        return [];
    }
}
