<?php

namespace Appwrite\Migration\Destination;

use Utopia\Migration\Destinations\Appwrite as BaseAppwrite;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Row;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\UID;
use Utopia\Database\Exception;

class Appwrite extends BaseAppwrite
{
    /**
     * @var array<UtopiaDocument>
     */
    private array $rowBuffer = [];

    /**
     * @throws AuthorizationException
     * @throws DatabaseException
     * @throws StructureException
     * @throws Exception
     */
    protected function createRow(Row $resource, bool $isLast): bool
    {
        if ($resource->getId() == 'unique()') {
            $resource->setId(ID::unique());
        }

        $validator = new UID();

        if (!$validator->isValid($resource->getId())) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: $validator->getDescription(),
            );
        }

        // to check if document has already been created
        $exists = \array_key_exists(
            $resource->getId(),
            $this->cache->get(Resource::TYPE_ROW)
        );

        if ($exists) {
            $resource->setStatus(
                Resource::STATUS_SKIPPED,
                'Row has already been created'
            );
            return false;
        }

        $data = $resource->getData();

        // fix for #10711: to filter out internal context attributes that are not part of the document schema
        unset($data['$databaseId']);
        unset($data['$collectionId']);

        $this->rowBuffer[] = new UtopiaDocument(\array_merge([
            '$id' => $resource->getId(),
            '$permissions' => $resource->getPermissions(),
        ], $data));

        if ($isLast) {
            try {
                $database = $this->database->getDocument(
                    'databases',
                    $resource->getTable()->getDatabase()->getId(),
                );

                $table = $this->database->getDocument(
                    'database_' . $database->getSequence(),
                    $resource->getTable()->getId(),
                );

                $databaseInternalId = $database->getSequence();
                $tableInternalId = $table->getSequence();

                /**
                 * This is in case an attribute was deleted from Appwrite attributes collection but was not deleted from the table
                 * When creating an archive we select * which will include orphan attribute from the schema
                 */
                foreach ($this->rowBuffer as $row) {
                    foreach ($row as $key => $value) {
                        if (\str_starts_with($key, '$')) {
                            continue;
                        }

                        /** @var \Utopia\Database\Document $attribute */
                        $found = false;
                        foreach ($table->getAttribute('attributes', []) as $attribute) {
                            if ($attribute->getAttribute('key') == $key) {
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                            $row->removeAttribute($key);
                        }
                    }
                }

                $this->database->skipRelationshipsExistCheck(fn() => $this->database->createDocuments(
                    'database_' . $databaseInternalId . '_collection_' . $tableInternalId,
                    $this->rowBuffer
                ));

            } finally {
                $this->rowBuffer = [];
            }
        }


        return true;
    }
}
