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
 * URL
 *
 * Validate that an variable is a valid URL
 *
 * @package Utopia\Validator
 */
class URL extends Validator
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
        return 'Value must be a valid URL';
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
     * Validation will pass when $value is valid URL.
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value)
    {
        if (\filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return true;
    }
}
