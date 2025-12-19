<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as AppwriteAction;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Operator;

class Action extends AppwriteAction
{
    private string $context = 'legacy';

    public function getDatabaseType(): string
    {
        return $this->context;
    }

    public function setHttpPath(string $path): AppwriteAction
    {
        if (\str_contains($path, '/tablesdb')) {
            $this->context = 'tablesdb';
        }
        return parent::setHttpPath($path);
    }

    /**
     * Parse operator strings in data array and convert them to Operator objects.
     *
     * @param array $data The data array that may contain operator JSON strings or arrays
     * @param Document $collection The collection document to check for relationship attributes
     * @return array The data array with operators converted to Operator objects
     * @throws Exception If an operator string is invalid
     */
    protected function parseOperators(array $data, Document $collection): array
    {
        $relationshipKeys = [];
        foreach ($collection->getAttribute('attributes', []) as $attribute) {
            if ($attribute->getAttribute('type') === Database::VAR_RELATIONSHIP) {
                $relationshipKeys[$attribute->getAttribute('key')] = true;
            }
        }

        foreach ($data as $key => $value) {
            if (!\is_string($key)) {
                if (\is_array($value)) {
                    $data[$key] = $this->parseOperators($value, $collection);
                }
                continue;
            }

            if (\str_starts_with($key, '$')) {
                continue;
            }

            if (isset($relationshipKeys[$key])) {
                continue;
            }

            // Handle operator as JSON string (from API requests)
            if (\is_string($value)) {
                $decoded = \json_decode($value, true);

                if (
                    \is_array($decoded) &&
                    isset($decoded['method']) &&
                    \is_string($decoded['method']) &&
                    Operator::isMethod($decoded['method'])
                ) {
                    try {
                        $data[$key] = Operator::parse($value);
                    } catch (\Exception $e) {
                        throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid operator for attribute "' . $key . '": ' . $e->getMessage());
                    }
                }
            }
            // Handle operator as array (from transaction logs after serialization)
            elseif (
                \is_array($value) &&
                isset($value['method']) &&
                \is_string($value['method']) &&
                Operator::isMethod($value['method'])
            ) {
                try {
                    $data[$key] = Operator::parseOperator($value);
                } catch (\Exception $e) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid operator for attribute "' . $key . '": ' . $e->getMessage());
                }
            } elseif (\is_array($value)) {
                $data[$key] = $this->parseOperators($value, $collection);
            }
        }

        return $data;
    }
}
