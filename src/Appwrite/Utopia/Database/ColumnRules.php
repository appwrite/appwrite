<?php

namespace Appwrite\Utopia\Database;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Structure;

/**
 * Runs one column value through the same structure rules a normal update uses.
 *
 * Operator payloads skip those rules inside utopia-php/database: the structure
 * validator returns as soon as it sees an operator. Checking the value that
 * would be stored catches min/max, element size, and type constraints first.
 */
class ColumnRules extends Structure
{
    public function __construct()
    {
        parent::__construct(
            new Document([
                '$id' => 'columns',
                '$collection' => Database::METADATA,
            ]),
            Database::VAR_STRING,
        );
    }

    /**
     * @param Document|array<string, mixed> $attribute
     */
    public static function describe(Document|array $attribute, mixed $value): ?string
    {
        $spec = self::spec($attribute);
        $name = $spec['$id'];
        if ($name === '') {
            return null;
        }

        $rules = new self();
        $document = new Document([$name => $value]);
        if (!$rules->checkForInvalidAttributeValues($document, [$name => $value], [$name => $spec])) {
            return $rules->getDescription();
        }

        return null;
    }

    /**
     * @param Document|array<string, mixed> $attribute
     * @return array<string, mixed>
     */
    private static function spec(Document|array $attribute): array
    {
        if ($attribute instanceof Document) {
            $attribute = $attribute->getArrayCopy();
        }

        $name = $attribute['key'] ?? $attribute['$id'] ?? '';
        $attribute['$id'] = \is_string($name) ? $name : '';
        $attribute['size'] = (int) ($attribute['size'] ?? 0);
        $attribute['signed'] = $attribute['signed'] ?? true;
        $attribute['array'] = (bool) ($attribute['array'] ?? false);
        $attribute['required'] = (bool) ($attribute['required'] ?? false);
        $attribute['format'] = $attribute['format'] ?? '';

        $options = $attribute['formatOptions'] ?? [];
        if ($options instanceof Document) {
            $options = $options->getArrayCopy();
        }
        $attribute['formatOptions'] = \is_array($options) ? $options : [];

        return $attribute;
    }
}
