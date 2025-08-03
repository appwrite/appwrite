<?php

namespace Appwrite\Network\Validator;

use Utopia\DNS\Client;
use Utopia\System\System;
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
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $dnsServer = System::getEnv('_APP_DOMAINS_DNS', '');
        $dns = new Client($dnsServer);

        try {
            $query = $dns->query($value, strtoupper($this->type));
            $this->logs = $query;
        } catch (\Exception $e) {
            $this->logs = ['error' => $e->getMessage()];
            return false;
        }

        if (empty($query)) {
            return false;
        }

        foreach ($query as $record) {
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
