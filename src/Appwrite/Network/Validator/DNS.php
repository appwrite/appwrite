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
     * How many of the zone's nameservers are asked. One answer decides; asking a few
     * guards against a single secondary that has not pulled the latest zone yet.
     */
    protected const int MAX_AUTHORITATIVE_SERVERS = 3;

    protected const int RCODE_NOERROR = 0;
    protected const int RCODE_NXDOMAIN = 3;

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
     * Validate a DNS record against the zone's own nameservers, falling back to the configured resolvers
     *
     * A recursive resolver caches what it saw first for the record's TTL, "does not exist"
     * included: a record added after a failed check stays missing there, and a record the
     * owner removed stays present, for up to that long, and which cache node answers is a
     * matter of luck. The zone's authoritative nameservers hold the current state and have
     * no cache, so when one of them answers, that answer decides. The resolvers decide only
     * when no nameserver could be found or gave a usable answer, which is the check as it
     * was before, except that any one resolver finding the record is enough.
     *
     * CAA stays on the resolvers: a missing CAA record is a pass that inherits from the
     * parent, so a cached absence cannot fail it, and the parent walk lives in the base validator.
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid(mixed $value): bool
    {
        if (\is_string($value) && $this->type !== Record::TYPE_CAA) {
            $verdict = $this->askAuthoritative($value);
            if ($verdict !== null) {
                return $verdict;
            }
        }

        return $this->askResolvers($value);
    }

    /**
     * What the zone's nameservers say: true on a match, false when one answered that the
     * record is missing or different, null when none could be found or answered usably.
     */
    protected function askAuthoritative(string $value): ?bool
    {
        $nameservers = $this->findAuthoritativeServers($value);
        if ($nameservers === []) {
            return null;
        }

        /** @var array<string, array<string>|null> $answers nameserver => records of the wanted type, null when its answer settles nothing */
        $answers = [];
        $wg = new WaitGroup();

        foreach ($nameservers as $name => $ip) {
            $wg->add();

            \go(function () use ($name, $ip, $value, $wg, &$answers) {
                try {
                    $answers[$name] = $this->lookupAuthoritative($ip, $value);
                } finally {
                    $wg->done();
                }
            });
        }

        $wg->wait();

        foreach ($answers as $records) {
            if ($records !== null && \in_array($this->target, $records, true)) {
                return true;
            }
        }

        foreach ($nameservers as $name => $ip) {
            $records = $answers[$name] ?? null;
            if ($records === null) {
                continue;
            }

            $this->dnsServer = "{$name} (authoritative)";
            $this->value = $value;
            $this->reason = '';
            $this->records = $records;
            $this->count = \count($records);

            return false;
        }

        return null;
    }

    /**
     * Records of the wanted type the nameserver at $ip holds for $name
     *
     * Null when its answer settles nothing: the query failed; the server did not answer
     * authoritatively (a referral from a parent whose delegation the resolver had not seen
     * yet); it refused or errored; or the name is an alias whose target lies outside the
     * zone, so only a resolver can follow the chain to the wanted type.
     *
     * @return array<string>|null
     */
    protected function lookupAuthoritative(string $ip, string $name): ?array
    {
        try {
            $response = (new Client($ip))->query(
                Message::query(new Question($name, $this->type), recursionDesired: false)
            );
        } catch (\Throwable) {
            return null;
        }

        $header = $response->header;
        if (!$header->authoritative || !\in_array($header->responseCode, [self::RCODE_NOERROR, self::RCODE_NXDOMAIN], true)) {
            return null;
        }

        $records = [];
        $aliased = false;
        foreach ($response->answers as $record) {
            if ($record->type === $this->type) {
                $records[] = $record->rdata;
            } elseif ($record->type === Record::TYPE_CNAME) {
                $aliased = true;
            }
        }

        if ($records === [] && $aliased) {
            return null;
        }

        return $records;
    }

    /**
     * Whether any configured resolver returns the record; the first that answered describes a failure
     */
    protected function askResolvers(mixed $value): bool
    {
        $wg = new WaitGroup();
        $valid = false;
        /** @var array<string, BaseDNS> $failed */
        $failed = [];

        foreach ($this->dnsServers as $server) {
            $wg->add();

            \go(function () use ($value, $server, $wg, &$valid, &$failed) {
                try {
                    $validator = new BaseDNS($this->target, $this->type, $server);
                    if ($validator->isValid($value)) {
                        $valid = true;
                    } else {
                        $failed[$server] = $validator;
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

        // A resolver that answered beats one whose query failed; among those, the first configured.
        $server = (string) \array_key_first($failed);
        foreach ($this->dnsServers as $candidate) {
            if (isset($failed[$candidate]) && $failed[$candidate]->reason === '') {
                $server = $candidate;
                break;
            }
        }

        $validator = $failed[$server];
        $this->dnsServer = $server;
        $this->count = $validator->count;
        $this->value = $validator->value;
        $this->reason = $validator->reason;
        $this->records = $validator->records;

        return false;
    }

    /**
     * Nameservers of the zone that holds $value, keyed by hostname
     *
     * The zone cut is found by asking a resolver for NS records at every name from the
     * registrable apex down to $value itself; the deepest answer wins, so a delegated
     * subdomain is asked rather than its parent. Each configured resolver is tried until
     * one yields nameservers with usable addresses. An empty array means none did, and
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
            if ($nameservers === []) {
                continue;
            }

            $addresses = $this->resolveNameservers($resolver, $nameservers);
            if ($addresses !== []) {
                return $addresses;
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
     * IPv4 address of each nameserver, keyed by hostname; names without a usable one are dropped
     *
     * The zone owner controls where their NS names point. An address in private or reserved
     * space is used only when $resolver lives there too, so a zone cannot make the verifier
     * send queries into a network the operator has not already pointed it at.
     *
     * @param array<string> $nameservers
     * @return array<string, string>
     */
    protected function resolveNameservers(string $resolver, array $nameservers): array
    {
        $flags = FILTER_FLAG_IPV4;
        if (\filter_var($resolver, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            $flags |= FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        }

        /** @var array<string, string> $addresses */
        $addresses = [];
        $wg = new WaitGroup();

        foreach ($nameservers as $nameserver) {
            $wg->add();

            \go(function () use ($resolver, $nameserver, $flags, $wg, &$addresses) {
                try {
                    foreach ($this->query($resolver, $nameserver, Record::TYPE_A) as $record) {
                        if (\filter_var($record->rdata, FILTER_VALIDATE_IP, $flags) !== false) {
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
