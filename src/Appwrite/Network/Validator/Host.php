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

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Host
 *
 * Validate that a host is allowed from given whitelisted hosts list
 *
 * @package Utopia\Validator
 */
class Host extends Validator
{
    protected $whitelist = [];

    /**
     * @param array $whitelist
     */
    public function __construct(array $whitelist)
    {
        $this->whitelist = $whitelist;
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
        return 'URL host must be one of: ' . \implode(', ', $this->whitelist);
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
     * Validation will pass when $value starts with one of the given hosts
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value)
    {
        $urlValidator = new URL();

        if (!$urlValidator->isValid($value)) {
            return false;
        }

        if (\in_array(\parse_url($value, PHP_URL_HOST), $this->whitelist)) {
            return true;
        }

        return false;
    }
}
