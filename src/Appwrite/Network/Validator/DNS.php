<?php

namespace Appwrite\Network\Validator;

use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Validator\DNS as BaseDNS;

class DNS extends BaseDNS
{
    protected array $dnsServers = [];

    /**
     * @param string $target Expected value for the DNS record
     * @param int $type Type of DNS record to validate
     *  For value, use const from Record, such as Record::TYPE_A
     *  When using CAA type, you can provide exact match, or just issuer domain as $target
     * @param array<string> $dnsServers DNS server IP(s) or domain(s) to use for validation
     */
    public function __construct(string $target, int $type = Record::TYPE_CNAME, array $dnsServers = [])
    {
        parent::__construct($target, $type, $dnsServers[0] ?? self::DEFAULT_DNS_SERVER);

        $this->dnsServers = $dnsServers;
    }

    /**
     * Validate DNS record value against multiple DNS servers
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid(mixed $value): bool
    {
        // Every record below is checked by agreement between the resolvers, so with
        // none of them nothing is checked. Reporting success on that would accept
        // every domain this validator is ever asked about.
        if ($this->dnsServers === []) {
            $this->reason = 'DNS verification failed as no DNS resolver is configured.';

            return false;
        }

        $wg = new WaitGroup();
        $failedValidator = null;
        // Outside a coroutine (CLI, tests) go() would leave a reactor running, so query in turn
        $parallel = Coroutine::getCid() > 0;

        foreach ($this->dnsServers as $dnsServer) {
            $check = function () use ($value, $dnsServer, &$failedValidator): void {
                $validator = new BaseDNS($this->target, $this->type, $dnsServer);

                if (!$validator->isValid($value)) {
                    self::retainFailure($validator, $failedValidator);
                }
            };

            if (!$parallel) {
                $check();
                continue;
            }

            $wg->add();
            \go(function () use ($check, $wg) {
                try {
                    $check();
                } finally {
                    $wg->done();
                }
            });
        }

        if ($parallel) {
            $wg->wait();
        }

        if (!\is_null($failedValidator)) {
            $this->count = $failedValidator->count;
            $this->value = $failedValidator->value;
            $this->reason = $failedValidator->reason;
            $this->records = $failedValidator->records;
            return false;
        }

        return true;
    }

    /**
     * Keep the failure that best describes why the multi-resolver check lost.
     *
     * Prefer a resolver that could not answer over one that answered with no or
     * wrong records. resolveOwnership() reads an empty reason as "no proof", so a
     * NODATA response must not overwrite a lookup error from another resolver and
     * let a rule verify during a partial outage. Among answered failures, prefer
     * the one that saw records, so a mismatch is not lost to a NODATA peer.
     */
    public static function retainFailure(BaseDNS $validator, ?BaseDNS &$failedValidator): void
    {
        if (
            $failedValidator === null
            || ($validator->reason !== '' && $failedValidator->reason === '')
            || (
                $failedValidator->reason === ''
                && $validator->reason === ''
                && $validator->count > $failedValidator->count
            )
        ) {
            $failedValidator = $validator;
        }
    }
}
