<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator;

class DNS extends Validator
{
    public const RECORD_A = 'a';
    public const RECORD_AAAA = 'aaaa';
    public const RECORD_CNAME = 'cname';
    public const RECORD_CAA = 'caa';

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
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        $typeNative = match ($this->type) {
            self::RECORD_A => DNS_A,
            self::RECORD_AAAA => DNS_AAAA,
            self::RECORD_CNAME => DNS_CNAME,
            self::RECORD_CAA => DNS_CAA,
            default => throw new \Exception('Record type not supported.')
        };

        $dnsKey = match ($this->type) {
            self::RECORD_A => 'ip',
            self::RECORD_AAAA => 'ipv6',
            self::RECORD_CNAME => 'target',
            self::RECORD_CAA => 'value',
            default => throw new \Exception('Record type not supported.')
        };

        if (!is_string($value)) {
            return false;
        }

        try {
            $records = \dns_get_record($value, $typeNative);
            $this->logs = $records;
        } catch (\Throwable $th) {
            return false;
        }

        if (!$records) {
            return false;
        }

        foreach ($records as $record) {
            if ($this->type === self::RECORD_CAA) {
                // For CAA records, reconstruct the format from parsed components
                if (isset($record['value'])) {
                    if ($record['value'] === $this->target) {
                        return true;
                    }
                }
            } else {
                // For other record types, use the standard approach
                if (isset($record[$dnsKey]) && $record[$dnsKey] === $this->target) {
                    return true;
                }
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
