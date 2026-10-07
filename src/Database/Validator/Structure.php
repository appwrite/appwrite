<?php

declare(strict_types=1);

namespace Utopia\Database\Validator;

use Utopia\Database\Document;

/**
 * Partial updates of rows that predate a required column.
 *
 * updateDocument() validates the merged row. A column made required later is
 * null on older rows, and a null fails the required check, so every update of
 * those rows fails (#14175). A required column that is already null, and stays
 * null, is validated as optional. Creating a row without the column, or
 * clearing a value that is already set, still fails.
 */
class Structure extends StructureUpstream
{
    private ?Document $existing;
    private string $attributeType;
    private \DateTime $minimumDate;
    private \DateTime $maximumDate;
    private bool $attributesSupported;
    private bool $unsignedBigIntSupported;

    public function __construct(
        Document $collection,
        string $idAttributeType,
        \DateTime $minAllowedDate = new \DateTime('0000-01-01'),
        \DateTime $maxAllowedDate = new \DateTime('9999-12-31'),
        bool $supportForAttributes = true,
        bool $supportUnsignedBigInt = true,
        ?Document $currentDocument = null,
    ) {
        $this->existing = $currentDocument;
        $this->attributeType = $idAttributeType;
        $this->minimumDate = $minAllowedDate;
        $this->maximumDate = $maxAllowedDate;
        $this->attributesSupported = $supportForAttributes;
        $this->unsignedBigIntSupported = $supportUnsignedBigInt;

        parent::__construct(
            $collection,
            $idAttributeType,
            $minAllowedDate,
            $maxAllowedDate,
            $supportForAttributes,
            $supportUnsignedBigInt,
            $currentDocument,
        );
    }

    /**
     * @param mixed $document
     */
    public function isValid($document): bool
    {
        if (!$document instanceof Document || !$this->existing instanceof Document) {
            return parent::isValid($document);
        }

        $relaxed = $this->relax($document, $this->existing);
        if ($relaxed === null) {
            return parent::isValid($document);
        }

        $validator = new StructureUpstream(
            $relaxed,
            $this->attributeType,
            $this->minimumDate,
            $this->maximumDate,
            $this->attributesSupported,
            $this->unsignedBigIntSupported,
            $this->existing,
        );

        if ($validator->isValid($document)) {
            return true;
        }

        $this->message = $validator->message;

        return false;
    }

    private function relax(Document $document, Document $existing): ?Document
    {
        $changed = false;
        $attributes = [];

        foreach ($this->collection->getAttribute('attributes', []) as $attribute) {
            $name = $this->attributeName($attribute);
            $required = $this->attributeRequired($attribute);

            if ($required && $name !== '' && $this->isNull($document, $name) && $this->isNull($existing, $name)) {
                $attributes[] = $this->optional($attribute);
                $changed = true;
                continue;
            }

            $attributes[] = $attribute;
        }

        if (!$changed) {
            return null;
        }

        $collection = new Document($this->collection->getArrayCopy());
        $collection->setAttribute('attributes', $attributes);

        return $collection;
    }

    private function attributeName(mixed $attribute): string
    {
        if ($attribute instanceof Document) {
            return (string) $attribute->getAttribute('$id', '');
        }

        if (\is_array($attribute)) {
            return (string) ($attribute['$id'] ?? '');
        }

        return '';
    }

    private function attributeRequired(mixed $attribute): bool
    {
        if ($attribute instanceof Document) {
            return (bool) $attribute->getAttribute('required', false);
        }

        if (\is_array($attribute)) {
            return (bool) ($attribute['required'] ?? false);
        }

        return false;
    }

    /**
     * Document::getAttribute() uses isset, so a stored null is returned as null.
     */
    private function isNull(Document $document, string $name): bool
    {
        return $document->getAttribute($name, null) === null;
    }

    private function optional(mixed $attribute): mixed
    {
        if ($attribute instanceof Document) {
            $copy = new Document($attribute->getArrayCopy());
            $copy->setAttribute('required', false);

            return $copy;
        }

        if (\is_array($attribute)) {
            $attribute['required'] = false;
        }

        return $attribute;
    }
}
