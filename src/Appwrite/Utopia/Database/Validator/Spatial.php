<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Validator\Spatial as SpatialValidator;
use Utopia\Validator\JSON;

class Spatial extends JSON
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
        if (!parent::isValid($value)) {
            return false;
        }
        $value = \json_decode($value, true);
        $validator = new SpatialValidator($this->spatialAttributeType);
        return $validator->isValid($value);
    }
}
