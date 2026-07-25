<?php

namespace Appwrite\Network\Validator;

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
        $wg = new WaitGroup();
        $failedValidator = null;

        foreach ($this->dnsServers as $dnsServer) {
            $wg->add();

            \go(function () use ($value, $dnsServer, $wg, &$failedValidator) {
                try {
                    $validator = new BaseDNS($this->target, $this->type, $dnsServer);
                    $isValid = $validator->isValid($value);

                    if (!$isValid) {
                        $failedValidator = $validator;
                    }
                } finally {
                    $wg->done();
                }
            });
        }

        $wg->wait();

        if (!\is_null($failedValidator)) {
            $this->count = $failedValidator->count;
            $this->value = $failedValidator->value;
            $this->reason = $failedValidator->reason;
            $this->records = $failedValidator->records;
            return false;
        }

        return true;
    }
}
