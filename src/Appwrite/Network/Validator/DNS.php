<?php

namespace Appwrite\Network\Validator;

use Swoole\Coroutine\Channel;
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
        $serverCount = \count($this->dnsServers);
        $channel = new Channel($serverCount);

        foreach ($this->dnsServers as $dnsServer) {
            \go(function () use ($value, $dnsServer, $channel) {
                $validator = new BaseDNS($this->target, $this->type, $dnsServer);
                $valid = $validator->isValid($value);
                $channel->push(['valid' => $valid, 'validator' => $validator]);
            });
        }

        for ($i = 0; $i < $serverCount; $i++) {
            $result = $channel->pop();
            if (!$result['valid']) {
                $failed = $result['validator'];
                $this->count = $failed->count;
                $this->value = $failed->value;
                $this->reason = $failed->reason;
                $this->records = $failed->records;
                return false;
            }
        }

        return true;
    }
}
