<?php

namespace Appwrite\Network\Validator;

use Swoole\Coroutine\WaitGroup;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Validator\DNS as BaseDNS;

class DNS extends BaseDNS
{
    /**
     * @var array<string>
     */
    protected array $dnsServers = [];

    /**
     * @param string $target Expected value for the DNS record
     * @param int $type Type of DNS record to validate
     *  For value, use const from Record, such as Record::TYPE_A
     *  When using CAA type, you can provide exact match, or just issuer domain as $target
     * @param string|array<string> $dnsServers DNS server IP(s) or domain(s) to use for validation
     */
    public function __construct(string $target, int $type = Record::TYPE_CNAME, string|array $dnsServers = self::DEFAULT_DNS_SERVER)
    {
        parent::__construct($target, $type, is_array($dnsServers) ? $dnsServers[0] : $dnsServers);

        $this->dnsServers = is_array($dnsServers) ? $dnsServers : [$dnsServers];
    }

    /**
     * Validate DNS record value against multiple DNS servers
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid(mixed $value): bool
    {
        if (\count($this->dnsServers) === 1) {
            return $this->isValidWithDNSServer($value, $this->dnsServers[0]);
        }

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

    /**
     * Validate with a specific DNS server
     *
     * @param mixed $value
     * @param string $dnsServer
     * @return bool
     */
    protected function isValidWithDNSServer(mixed $value, string $dnsServer): bool
    {
        $validator = new BaseDNS($this->target, $this->type, $dnsServer);
        $result = $validator->isValid($value);

        $this->count = $validator->count;
        $this->value = $validator->value;
        $this->reason = $validator->reason;
        $this->records = $validator->records;

        return $result;
    }
}
