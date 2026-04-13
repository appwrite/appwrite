<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Transactions;

use Appwrite\Platform\Modules\Databases\Http\Databases\Action as DatabasesAction;

abstract class Action extends DatabasesAction
{
    /**
     * The current API context (either 'table' or 'collection').
     */
    private ?string $context = COLLECTIONS;
    private ?string $databaseType = LEGACY;

    public function getDatabaseType(): string
    {
        return $this->databaseType;
    }

    protected function getDatabasesOperationWriteMetric(): string
    {
        if ($this->databaseType === LEGACY || $this->databaseType === TABLESDB) {
            return METRIC_DATABASES_OPERATIONS_WRITES;
        }
        return $this->databaseType.'.'.METRIC_DATABASES_OPERATIONS_WRITES;

    }
    protected function getDatabasesIdOperationWriteMetric(): string
    {
        if ($this->databaseType === LEGACY || $this->databaseType === TABLESDB) {
            return METRIC_DATABASE_ID_OPERATIONS_WRITES;
        }
        return $this->databaseType.'.'.METRIC_DATABASE_ID_OPERATIONS_WRITES;
    }

    public function setHttpPath(string $path): self
    {
        switch (true) {
            case str_contains($path, '/tablesdb'):
                $this->context = TABLES;
                $this->databaseType = TABLESDB;
                break;

            case str_contains($path, '/documentsdb'):
                $this->context = COLLECTIONS;
                $this->databaseType = DOCUMENTSDB;
                break;
            case str_contains($path, '/vectorsdb'):
                $this->context = COLLECTIONS;
                $this->databaseType = VECTORSDB;
                break;
        }
        parent::setHttpPath($path);
        return $this;
    }

    /**
     * Get the current API context.
     */
    protected function getContext(): string
    {
        return $this->context;
    }

    /**
     * Determine if the current action is for the Collections API.
     */
    protected function isCollectionsAPI(): bool
    {
        return $this->getContext() === COLLECTIONS;
    }

    /**
     * Get the key used in event parameters (e.g., 'collectionId' or 'tableId').
     */
    protected function getGroupId(): string
    {
        return $this->getContext() . 'Id';
    }

    /**
     * Get the resource type for the current action (either 'document' or 'row').
     */
    protected function getResource(): string
    {
        return $this->isCollectionsAPI() ? 'document' : 'row';
    }

    /**
     * Get the resource ID key for the current action.
     */
    protected function getResourceId(): string
    {
        return $this->getResource() . 'Id';
    }

    protected function getAttributeKey(): string
    {
        return $this->isCollectionsAPI() ? 'attribute' : 'column';
    }
}
