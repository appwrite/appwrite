<?php

namespace Appwrite\Network\Validator;

use Utopia\DNS\Client;
use Utopia\Domains\Domain;
use Utopia\System\System;
use Utopia\Validator;

class DNS extends Validator
{
    public const RECORD_A = 'A';
    public const RECORD_AAAA = 'AAAA';
    public const RECORD_CNAME = 'CNAME';
    public const RECORD_CAA = 'CAA'; // You can provide domain only (as $target) for CAA validation

    protected const FAILURE_REASON_QUERY = 'DNS query failed.';
    protected const FAILURE_REASON_INTERNAL = 'Internal error occurred.';
    protected const FAILURE_REASON_UNKNOWN = '';

    /**
     * @var mixed
     */
    protected mixed $logs;

    /**
     * @var string
     */
    protected string $dnsServer;

    protected string $domain = '';
    protected array $recordValues = [];
    protected int $count = 0;
    protected string $reason = '';

    /**
     * @param string $target
     */
    public function __construct(protected string $target, protected string $type = self::RECORD_CNAME, string $dnsServer = '')
    {
        if (empty($dnsServer)) {
            $dnsServer = System::getEnv('_APP_DNS', '8.8.8.8');
        }

        $this->dnsServer = $dnsServer;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        if (!empty($this->reason)) {
            return $this->reason;
        }

        if ($this->count === 0) {
            return 'Domain ' . $this->domain . ' does not have ' . $this->type . ' record.';
        }

        $record = $this->count === 1 ? 'record' : 'records';
        $value = $this->count === 1 ? 'value' : 'values';

        $recordValuesVerbose = implode(', ', $this->recordValues);

        return "Domain {$this->domain} has {$this->count} {$this->type} {$record} with wrong {$value}: {$recordValuesVerbose}";
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
    public function isValid(mixed $value): bool
    {
        $this->count = 0;
        $this->domain = \is_string($value) ? $value : \strval($value);
        $this->reason = self::FAILURE_REASON_UNKNOWN;
        $this->recordValues = [];

        if (!is_string($value)) {
            $this->reason = self::FAILURE_REASON_INTERNAL;
            return false;
        }

        $dns = new Client($this->dnsServer);

        try {
            $rawQuery = $dns->query($value, $this->type);

            // Some DNS servers return all records, not only type that's asked for
            // Likely occurs when no records of specific type are found
            $query = array_filter($rawQuery, function ($record) {
                return $record->getTypeName() === $this->type;
            });

            $this->logs = $query;
        } catch (\Exception $e) {
            $this->reason = self::FAILURE_REASON_QUERY;
            $this->logs = ['error' => $e->getMessage()];
            return false;
        }

        $this->count = \count($query);

        if (empty($query)) {
            // CAA records inherit from parent (custom CAA behaviour)
            if ($this->type === self::RECORD_CAA) {
                $domain = new Domain($value);
                if ($domain->get() === $domain->getApex()) {
                    return true; // No CAA on apex domain means anyone can issue certificate
                }

                // Recursive validation by parent domain
                $parts = \explode('.', $value);
                \array_shift($parts);
                $parentDomain = \implode('.', $parts);
                $validator = new DNS($this->target, DNS::RECORD_CAA, $this->dnsServer);
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

                $this->recordValues[] = $rdata;
                if ($rdata === $this->target) {
                    return true;
                }
            } else {
                $this->recordValues[] = $record->getRdata();
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
