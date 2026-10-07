<?php

namespace Utopia\Database\Validator;

use Utopia\Database\Document;
use Utopia\Validator;

class IndexDependency extends Validator
{
    protected string $message = "Attribute can't be deleted or renamed because it is used in an index";

    protected bool $castIndexSupport;

    /**
     * @var array<Document>
     */
    protected array $indexes;

    /**
     * @param array<Document> $indexes
     * @param bool $castIndexSupport
     */
    public function __construct(array $indexes, bool $castIndexSupport)
    {
        $this->castIndexSupport = $castIndexSupport;
        $this->indexes = $indexes;
    }

    /**
     * Returns validator description
     */
    public function getDescription(): string
    {
        return $this->message;
    }

    /**
     * Is valid.
     *
     * @param  Document  $value
     */
    public function isValid($value): bool
    {
        if (! $this->castIndexSupport) {
            return true;
        }

        if (! $value->getAttribute('array', false)) {
            return true;
        }

        $key = \strtolower($value->getAttribute('key', $value->getAttribute('$id')));

        foreach ($this->indexes as $index) {
            $attributes = $index->getAttribute('attributes', []);
            foreach ($attributes as $attribute) {
                if ($key === \strtolower($attribute)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }
}
