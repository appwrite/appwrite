<?php

namespace Appwrite\Network\Validator;

use Swoole\Coroutine\WaitGroup;
use Utopia\DNS\Client;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Validator\DNS as BaseDNS;
use Utopia\Domains\Domain;

class DNS extends BaseDNS
{
    /**
     * How many of the zone's nameservers are asked. One answer verifies; asking a few
     * guards against a single secondary that has not pulled the latest zone yet.
     */
    protected const int MAX_AUTHORITATIVE_SERVERS = 3;

    /**
     * @var array<string>
     */
    protected array $dnsServers = [];

    /**
     * @param string $target Expected value for the DNS record
     * @param int $type Type of DNS record to validate
     *  For value, use const from Record, such as Record::TYPE_A
     *  When using CAA type, you can provide exact match, or just issuer domain as $target
     * @param array<string> $dnsServers Recursive resolver IP(s) to use for validation; 8.8.8.8 when empty
     */
    public function __construct(string $target, int $type = Record::TYPE_CNAME, array $dnsServers = [])
    {
        if ($dnsServers === []) {
            $dnsServers = [self::DEFAULT_DNS_SERVER];
        }

        parent::__construct($target, $type, $dnsServers[0]);

        $this->dnsServers = $dnsServers;
    }

    /**
     * Validate a DNS record against the zone's own nameservers and the configured resolvers
     *
     * A recursive resolver caches "does not exist" for as long as the zone's negative TTL,
     * so a lookup that ran before the record was added keeps failing there for up to that
     * long, and which cache node answers is a matter of luck. The zone's authoritative
     * nameservers have no cache. Both are asked, and a match on any one of them verifies.
     * When nothing matches, the failure is described from a nameserver that answered,
     * because that is what the zone really holds; the resolvers only speak when no
     * nameserver could be found or reached.
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid(mixed $value): bool
    {
        // Label => server IP, authoritative nameservers first so they win the description below.
        $servers = [];
        if (\is_string($value)) {
            foreach ($this->findAuthoritativeServers($value) as $name => $ip) {
                $servers["{$name} (authoritative)"] = $ip;
            }
        }
        foreach ($this->dnsServers as $server) {
            $servers[$server] = $server;
        }

        $wg = new WaitGroup();
        $valid = false;
        /** @var array<string, BaseDNS> $failed */
        $failed = [];

        foreach ($servers as $label => $server) {
            $wg->add();

            \go(function () use ($value, $label, $server, $wg, &$valid, &$failed) {
                try {
                    $validator = $this->createValidator($server);
                    if ($validator->isValid($value)) {
                        $valid = true;
                    } else {
                        $failed[$label] = $validator;
                    }
                } finally {
                    $wg->done();
                }
            });
        }

        $wg->wait();

        if ($valid) {
            return true;
        }

        if ($failed === []) {
            $this->reason = self::FAILURE_REASON_QUERY;
            return false;
        }

        // A server that answered beats one whose query failed; among those, the first in $servers.
        $label = (string) \array_key_first($failed);
        foreach ($servers as $candidate => $server) {
            if (isset($failed[$candidate]) && $failed[$candidate]->reason === '') {
                $label = $candidate;
                break;
            }
        }

        $validator = $failed[$label];
        $this->dnsServer = $label;
        $this->count = $validator->count;
        $this->value = $validator->value;
        $this->reason = $validator->reason;
        $this->records = $validator->records;

        return false;
    }

    /**
     * One lookup of the record on one server. A test scripts answers by overriding this.
     */
    protected function createValidator(string $server): BaseDNS
    {
        return new BaseDNS($this->target, $this->type, $server);
    }

    /**
     * Nameservers of the zone that holds $value, keyed by hostname
     *
     * The zone cut is found by asking a resolver for NS records at every name from the
     * registrable apex down to $value itself; the deepest answer wins, so a delegated
     * subdomain is asked rather than its parent. An empty array means no resolver knows
     * nameservers for it, none of them has an IPv4 address, or a lookup failed, and
     * verification then rests on the resolvers alone.
     *
     * @return array<string, string>
     */
    protected function findAuthoritativeServers(string $value): array
    {
        try {
            $apex = (new Domain($value))->getApex();
        } catch (\Throwable) {
            return [];
        }

        $labels = \explode('.', \rtrim(\strtolower($value), '.'));
        $depth = \count(\explode('.', $apex));
        if ($apex === '' || $depth > \count($labels) || \implode('.', \array_slice($labels, -$depth)) !== $apex) {
            return [];
        }

        $names = [];
        for ($i = \count($labels) - $depth; $i >= 0; $i--) {
            $names[] = \implode('.', \array_slice($labels, $i));
        }

        foreach ($this->dnsServers as $resolver) {
            $nameservers = $this->findNameservers($resolver, $names);
            if ($nameservers !== []) {
                return $this->resolveNameservers($resolver, $nameservers);
            }
        }

        return [];
    }

    /**
     * NS records at the deepest of $names (ordered apex first) the resolver has any for
     *
     * @param array<string> $names
     * @return array<string>
     */
    protected function findNameservers(string $resolver, array $names): array
    {
        /** @var array<int, array<string>> $found */
        $found = [];
        $wg = new WaitGroup();

        foreach ($names as $index => $name) {
            $wg->add();

            \go(function () use ($resolver, $index, $name, $wg, &$found) {
                try {
                    $found[$index] = \array_map(
                        fn (Record $record) => $record->rdata,
                        $this->query($resolver, $name, Record::TYPE_NS)
                    );
                } finally {
                    $wg->done();
                }
            });
        }

        $wg->wait();

        \krsort($found);
        foreach ($found as $nameservers) {
            if ($nameservers !== []) {
                return \array_slice($nameservers, 0, self::MAX_AUTHORITATIVE_SERVERS);
            }
        }

        return [];
    }

    /**
     * IPv4 address of each nameserver, keyed by hostname; names without one are dropped
     *
     * @param array<string> $nameservers
     * @return array<string, string>
     */
    protected function resolveNameservers(string $resolver, array $nameservers): array
    {
        /** @var array<string, string> $addresses */
        $addresses = [];
        $wg = new WaitGroup();

        foreach ($nameservers as $nameserver) {
            $wg->add();

            \go(function () use ($resolver, $nameserver, $wg, &$addresses) {
                try {
                    foreach ($this->query($resolver, $nameserver, Record::TYPE_A) as $record) {
                        if (\filter_var($record->rdata, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                            $addresses[$nameserver] = $record->rdata;
                            break;
                        }
                    }
                } finally {
                    $wg->done();
                }
            });
        }

        $wg->wait();

        return $addresses;
    }

    /**
     * Answers of $type for $name from $resolver; empty on any failure
     *
     * @return array<Record>
     */
    protected function query(string $resolver, string $name, int $type): array
    {
        try {
            $response = (new Client($resolver))->query(Message::query(new Question($name, $type)));
        } catch (\Throwable) {
            return [];
        }

        return \array_values(\array_filter(
            $response->answers,
            fn (Record $record) => $record->type === $type
        ));
    }
}
