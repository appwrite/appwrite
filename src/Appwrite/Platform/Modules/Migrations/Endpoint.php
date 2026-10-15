<?php

namespace Appwrite\Platform\Modules\Migrations;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\PublicHostname;
use InvalidArgumentException;
use Utopia\Client\Destinations;
use Utopia\Client\Destinations\Anywhere;
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

    private PublicInternet $policy;

    private PublicHostname $hostname;

    /**
     * @var list<string>
     */
    private array $hostnames = [];

    /**
     * @param string $addresses Comma-separated addresses and CIDR ranges (_APP_ALLOWED_INTERNAL_ADDRESSES)
     * @param string $hosts Comma-separated addresses, CIDR ranges and hostnames allowed by name (_APP_MIGRATIONS_ALLOWED_HOSTS)
     *
     * @throws InvalidArgumentException When an entry of $addresses is not an address or range, or one of $hosts is a malformed range
     */
    public function __construct(Lookup $lookup, string $addresses = '', string $hosts = '')
    {
        $ranges = \array_map(fn (string $range): IPRange => new IPRange($range), $this->entries($addresses));

        foreach ($this->entries($hosts) as $entry) {
            if (\filter_var(\explode('/', $entry, 2)[0], FILTER_VALIDATE_IP) !== false) {
                $ranges[] = new IPRange($entry);
            } else {
                $this->hostnames[] = $this->normalize($entry);
            }
        }

        $this->url = new URL(['http', 'https']);
        $this->policy = new PublicInternet(...$ranges);
        $this->hostname = new PublicHostname($this->policy, $lookup);
    }

    /**
     * @throws Exception When the URL is not an allowed endpoint
     */
    public function validate(string $url): void
    {
        $this->destinations($url);
    }

    /**
     * Where requests to the endpoint may connect. The source's client checks every
     * connection and redirect hop against them, on the address it actually reached. A host
     * allowed by name may connect anywhere, since the operator trusts it.
     *
     * @throws Exception When the URL is not an allowed endpoint
     */
    public function destinations(string $url): Destinations
    {
        try {
            return $this->check($url);
        } catch (InvalidArgumentException $exception) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid `endpoint`: ' . $exception->getMessage());
        }
    }

    /**
     * @throws InvalidArgumentException When the URL is not an allowed endpoint
     */
    private function check(string $url): Destinations
    {
        if (!$this->url->isValid($url)) {
            throw new InvalidArgumentException($this->url->getDescription());
        }

        if (\parse_url($url, PHP_URL_USER) !== null || \parse_url($url, PHP_URL_PASS) !== null) {
            throw new InvalidArgumentException('Endpoint must not contain credentials.');
        }

        $host = (string) \parse_url($url, PHP_URL_HOST);

        if (\in_array($this->normalize($host), $this->hostnames, true)) {
            return new Anywhere();
        }

        $this->hostname->address($host);

        return $this->policy;
    }

    /**
     * @return list<string>
     */
    private function entries(string $list): array
    {
        return \array_values(\array_filter(\array_map('trim', \explode(',', $list))));
    }

    private function normalize(string $hostname): string
    {
        return \rtrim(\strtolower(\trim($hostname)), '.');
    }
}
