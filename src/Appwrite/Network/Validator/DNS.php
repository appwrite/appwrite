<?php

namespace Appwrite\Network\Validator;

use Utopia\DNS\Client;
use Utopia\System\System;
use Utopia\Validator;

class DNS extends Validator
{
    public const RECORD_A = 'A';
    public const RECORD_AAAA = 'AAAA';
    public const RECORD_CNAME = 'CNAME';
    public const RECORD_CAA = 'CAA'; // You can provide domain only (as $target) for CAA validation

    /**
     * @var mixed
     */
    protected mixed $logs;

    /**
     * @param string $target
     */
    public function __construct(protected string $target, protected string $type = self::RECORD_CNAME)
    {
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Invalid DNS record';
    }

    /**
     * @return mixed
     */
    public function getLogs(): mixed
    {
        return $this->logs;
    }

    /**
     * Check if DNS record value matches specific value
     *
     * @param mixed $domain
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $dnsServer = System::getEnv('_APP_DNS', '8.8.8.8');
        $dns = new Client($dnsServer);

        try {
            $query = $dns->query($value, $this->type);
            $this->logs = $query;
        } catch (\Exception $e) {
            $this->logs = ['error' => $e->getMessage()];
            return false;
        }

        if (empty($query)) {
            // CAA records inherit from parent (custom CAA behaviour)
            if ($this->type === self::RECORD_CAA) {
                if (\substr_count($value, ".") === 1) {
                    return true; // No CAA on apex domain means anyone can issue certificate
                }

                // Recursive validation by parent domain
                $parts = \explode('.', $value);
                \array_shift($parts);
                $parentDomain = \implode('.', $parts);
                $validator = new DNS(System::getEnv('_APP_DOMAIN_TARGET_CAA', ''), DNS::RECORD_CAA);
                return $validator->isValid($parentDomain);
            }

            return false;
        }

        foreach ($query as $record) {
            // CAA validation only needs to ensure domain
            if ($this->type === self::RECORD_CAA) {
                // Extract domain; comments showcase extraction steps in most complex scenario
                $rdata = $record->getRdata(); // 255 issuewild "certainly.com;validationmethods=tls-alpn-01;retrytimeout=3600"
                $rdata = \explode(' ', $rdata, 3)[2] ?? ''; // "certainly.com;validationmethods=tls-alpn-01;retrytimeout=3600"
                $rdata = \trim($rdata, '"'); // certainly.com;validationmethods=tls-alpn-01;retrytimeout=3600
                $rdata = \explode(';', $rdata, 2)[0] ?? ''; // certainly.com

                if ($rdata === $this->target) {
                    return true;
                }
            }

            if ($record->getRdata() === $this->target) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
