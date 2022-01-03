<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator;

class CNAME extends Validator
{
    /**
     * @var string
     */
    protected $target;

    /**
     * @param string $target
     */
    public function __construct($target)
    {
        $this->target = $target;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Invalid CNAME record';
    }

    /**
     * Check if CNAME record target value matches selected target
     *
     * @param mixed $domain
     *
     * @return bool
     */
    public function isValid($domain): bool
    {
        if (!is_string($domain)) {
            return false;
        }

        try {
            $records = \dns_get_record($domain, DNS_CNAME);
        } catch (\Throwable $th) {
            return false;
        }

        foreach ($records as $record) {
            if (isset($record['target']) && $record['target'] === $this->target) {
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
