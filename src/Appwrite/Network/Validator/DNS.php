<?php

namespace Appwrite\Network\Validator;

use Swoole\Coroutine\WaitGroup;
use Utopia\DNS\Validator\DNS as BaseDNS;

class DNS extends BaseDNS
{
    /**
     * @param array<string> $dnsServers DNS server IP(s) or domain(s) to use for validation
     */
    public function __construct(protected array $dnsServers = [])
    {
    }

    /**
     * Clone the validator config and set the target & type for the record
     *
     * @param string $target Target domain to validate
     * @param int $type Type of DNS record to validate
     *  For value, use const from Record, such as Record::TYPE_A
     * @return DNS
     */
    public function forRecord(string $target, int $type): self
    {
        $new = clone $this;
        $new->target = $target;
        $new->type = $type;
        return $new;
    }

    /**
     * Validate DNS record value against multiple DNS servers
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid(mixed $value): bool
    {
        if (empty($this->target) || empty($this->type)) {
            return false;
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
}
