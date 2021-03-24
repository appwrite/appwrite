<?php
/**
 * Utopia PHP Framework
 *
 * @package Framework
 * @subpackage Validator
 *
 * @link https://github.com/utopia-php/framework
 * @author Appwrite Team <team@appwrite.io>
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Appwrite\Network\Validator;

use Utopia\Validator;

/**
 * Email
 *
 * Validate that an variable is a valid email address
 *
 * @package Utopia\Validator
 */
class Email extends Validator
{
    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Value must be a valid email address';
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
        return self::TYPE_STRING;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is valid email address.
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value)
    {
        if (!\filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return true;
    }
}
