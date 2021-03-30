<?php

namespace Appwrite\Network\Validator;

use Exception;
use Utopia\Validator;

/**
 * IP
 *
 * Validate that an variable is a valid IP address
 *
 * @package Utopia\Validator
 */
class IP extends Validator
{
    const ALL = 'all';
    const V4 = 'ipv4';
    const V6 = 'ipv6';

    /**
     * @var string
     */
    protected $type = self::ALL;

    /**
     * Constructor
     *
     * Set a the type of IP check.
     *
     * @param string $type
     */
    public function __construct(string $type = self::ALL)
    {
        if (!in_array($type, [self::ALL, self::V4, self::V6])) {
            throw new Exception('Unsupported IP type');
        }

        $this->type = $type;
    }

    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Value must be a valid IP address';
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType()
    {
        return 'string';
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is valid IP address.
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value)
    {
        switch ($this->type) {
            case self::ALL:
                if (\filter_var($value, FILTER_VALIDATE_IP)) {
                    return true;
                }
            break;
            
            case self::V4:
                if (\filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return true;
                }
                break;
            
            case self::V6:
                if (\filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    return true;
                }
                break;
            
            default:
                return false;
            break;
        }
        
        return false;
    }
}
