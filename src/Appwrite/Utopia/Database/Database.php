<?php

namespace Appwrite\Utopia\Database;

use Utopia\Database\Database as UtopiaDatabase;

class Database extends UtopiaDatabase
{
    public function find(string $collection, array $queries = []): array
    {
        try {
            $collectionCache = null;
            foreach ($queries as $query) {
                if ($query->getMethod() === Query::TYPE_SEARCH || $query->getMethod() === Query::TYPE_EQUAL) {
                    $attribute = $query->getAttribute();

                    if ($collectionCache === null) {
                        $collectionCache = $this->silent(fn () => $this->getCollection($collection));
                    }

                    $attributeMetadata = null;
                    foreach ($collectionCache->getAttribute('attributes', []) as $attributeDocument) {
                        if ($attributeDocument->getId() === $attribute) {
                            $attributeMetadata = $attributeDocument;
                            break;
                        }
                    }

                    if ($attributeMetadata !== null) {
                        if ($attributeMetadata->getAttribute('array', false) === true) {
                            $query->setMethod(Query::TYPE_CONTAINS);
                        }
                    }
                }
            }
        } catch (\Throwable $err) {
            // Ignore error, to not do any harm
        }

        return parent::find($collection, $queries);
    }
}
