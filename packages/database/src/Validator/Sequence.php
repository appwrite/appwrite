<?php

namespace Utopia\Database\Validator;

use Utopia\Database\Database;
use Utopia\Validator;
use Utopia\Validator\Range;

class Sequence extends Validator
{
    private string $idAttributeType;
    private bool $primary;

    public function getDescription(): string
    {
        return 'Invalid sequence value';
    }

    /**
     * Expression constructor
     */
    public function __construct(string $idAttributeType, bool $primary)
    {
        $this->primary = $primary;
        $this->idAttributeType = $idAttributeType;
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    public function isValid($value): bool
    {
        if ($this->primary && empty($value)) {
            return false;
        }

        if ($value === null) {
            return true;
        }

        if (!\is_string($value) && !\is_int($value)) {
            return false;
        }

        if (!$this->primary) {
            return true;
        }

        switch ($this->idAttributeType) {
            case Database::VAR_UUID7:
                return \is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-7[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value) === 1;
            case Database::VAR_INTEGER:
                $validator = new Range(1, Database::MAX_BIG_INT, Database::VAR_INTEGER);
                return $validator->isValid($value);

            default:
                return false;
        }
    }
}
