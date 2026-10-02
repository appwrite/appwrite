<?php

namespace Appwrite\Platform\Modules\Migrations;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\PublicHostname;
use InvalidArgumentException;
use Utopia\Client\Destinations\IPRange;
use Utopia\Client\Destinations\PublicInternet;
use Utopia\DNS\Lookup;
use Utopia\Validator\URL;

/**
 * Where an Appwrite migration source may be: an http or https URL whose host is allowed by
 * name, or passes the destination policy: public addresses plus the allowed addresses and
 * CIDR ranges. Holds no per-call state, so one instance can be shared by concurrent requests.
 */
class Endpoint
{
    private URL $url;

    private PublicHostname $hostname;

    /**
     * @var list<string>
     */
    private array $hostnames = [];

    /**
     * @param string ...$allowlists Comma-separated addresses, CIDR ranges and hostnames, combined
     *
     * @throws InvalidArgumentException When an entry is a malformed range
     */
    public function __construct(Lookup $lookup, string ...$allowlists)
    {
        $ranges = [];

        foreach ($allowlists as $allowlist) {
            foreach (\array_filter(\array_map('trim', \explode(',', $allowlist))) as $entry) {
                if (\filter_var(\explode('/', $entry, 2)[0], FILTER_VALIDATE_IP) !== false) {
                    $ranges[] = new IPRange($entry);
                } else {
                    $this->hostnames[] = $this->normalize($entry);
                }
            }
        }

        $this->url = new URL(['http', 'https']);
        $this->hostname = new PublicHostname(new PublicInternet(...$ranges), $lookup);
    }

    /**
     * @throws Exception When the URL is not an allowed endpoint
     */
    public function validate(string $url): void
    {
        $this->resolve($url);
    }

    /**
     * The CURLOPT_RESOLVE entries that pin a request to the address its host was checked
     * against. None for an IP literal, which curl never resolves, or a host allowed by name.
     *
     * @return list<string>
     *
     * @throws Exception When the URL is not an allowed endpoint
     */
    public function resolve(string $url): array
    {
        try {
            $address = $this->address($url);
        } catch (InvalidArgumentException $exception) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid `endpoint`: ' . $exception->getMessage());
        }

        if ($address === null) {
            return [];
        }

        $host = (string) \parse_url($url, PHP_URL_HOST);
        $port = \parse_url($url, PHP_URL_PORT) ?? (\strtolower((string) \parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80);
        $address = \str_contains($address, ':') ? "[{$address}]" : $address;

        // curl drops a trailing dot before it looks the host up, so pin both spellings
        return \array_values(\array_map(
            fn (string $name): string => "{$name}:{$port}:{$address}",
            \array_unique([$host, \rtrim($host, '.')]),
        ));
    }

    /**
     * @throws InvalidArgumentException When the URL is not an allowed endpoint
     */
    private function address(string $url): ?string
    {
        if (!$this->url->isValid($url)) {
            throw new InvalidArgumentException($this->url->getDescription());
        }

        if (\parse_url($url, PHP_URL_USER) !== null || \parse_url($url, PHP_URL_PASS) !== null) {
            throw new InvalidArgumentException('Endpoint must not contain credentials.');
        }

        $host = (string) \parse_url($url, PHP_URL_HOST);

        if (\in_array($this->normalize($host), $this->hostnames, true)) {
            return null;
        }

        $address = $this->hostname->address($host);

        return \filter_var(\trim($host, '[]'), FILTER_VALIDATE_IP) === false ? $address : null;
    }

    private function normalize(string $hostname): string
    {
        return \rtrim(\strtolower(\trim($hostname)), '.');
    }
}
