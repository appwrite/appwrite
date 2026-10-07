<?php

namespace Utopia\Database\Validator;

use Utopia\Database\Database;
use Utopia\Database\Document;

class PartialStructure extends Structure
{
    /**
     * Is valid.
     *
     * Returns true if valid or false if not.
     *
     * @param mixed $document
     *
     * @return bool
     */
    public function isValid($document): bool
    {
        if (!$document instanceof Document) {
            $this->message = 'Value must be an instance of Document';
            return false;
        }

        if (empty($this->collection->getId()) || Database::METADATA !== $this->collection->getCollection()) {
            $this->message = 'Collection not found';
            return false;
        }

        $keys = [];
        $structure = $document->getArrayCopy();
        $attributes = \array_merge($this->attributes, $this->collection->getAttribute('attributes', []));

        foreach ($attributes as $attribute) {
            $name = $attribute['$id'] ?? '';
            $keys[$name] = $attribute;
        }
        /**
         * @var array<string, mixed> $requiredAttributes
         */
        $requiredAttributes = [];
        foreach ($this->attributes as $attribute) {
            if ($attribute['required'] === true && $document->offsetExists($attribute['$id'])) {
                $requiredAttributes[] = $attribute;
            }
        }

        if (!$this->checkForAllRequiredValues($structure, $requiredAttributes, $keys)) {
            return false;
        }
        if (!$this->checkForUnknownAttributes($structure, $keys)) {
            return false;
        }

        if (!$this->checkForInvalidAttributeValues($document, $structure, $keys)) {
            return false;
        }

        return true;
    }
}
