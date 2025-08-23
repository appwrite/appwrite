<?php
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
    protected bool $allowEmpty;
    /**
     * @var array<string, bool>
     */
    protected array $disposableDomains = [];
    protected bool $blockDisposable    = false;

    public function __construct(bool $allowEmpty = false, array $disposableDomains = [], bool $blockDisposable = false)
    {
        $this->allowEmpty        = $allowEmpty;
        $this->disposableDomains = $disposableDomains;
        $this->blockDisposable   = $blockDisposable;
    }

    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Value must be a valid email address';
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is valid email address.
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        if ($this->allowEmpty && \strlen($value) === 0) {
            return true;
        }

        if (! \filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if ($this->blockDisposable && ! empty($this->disposableDomains)) {
            $atPos = \strrpos($value, '@');
            if ($atPos !== false) {
                $domain = \strtolower(\substr($value, $atPos + 1));
                // Skip IP literal domains like [123.123.123.123]
                if ($domain !== '' && $domain[0] !== '[') {
                    // Check domain and its parent suffixes
                    $parts = \explode('.', $domain);
                    for ($i = 0; $i < \count($parts); $i++) {
                        $suffix = \implode('.', \array_slice($parts, $i));
                        if (isset($this->disposableDomains[$suffix])) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
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
