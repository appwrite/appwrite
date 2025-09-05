<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Validator\Spatial as SpatialValidator;
use Utopia\Validator;

class Spatial extends Validator
{
    private string $spatialAttributeType;

    public function getDescription(): string
    {
        return 'Value must be a valid spatial type JSON string';
    }

    /**
     * @param string $spatialAttributeType
     */
    public function __construct(string $spatialAttributeType)
    {
        $this->spatialAttributeType = $spatialAttributeType;
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
     * Returns validator type
     *
     * @return string
    */
    public function getType(): string
    {
        return self::TYPE_ARRAY;
    }

    /**
     * Is valid.
     *
     * Returns true if valid or false if not.
     *
     * @param $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        $validator = new SpatialValidator($this->spatialAttributeType);
        return $validator->isValid($value);
    }
}
