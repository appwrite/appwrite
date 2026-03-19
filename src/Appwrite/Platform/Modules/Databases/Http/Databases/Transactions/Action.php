<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Transactions;

use Appwrite\Platform\Modules\Databases\Http\Databases\Action as DatabasesAction;

abstract class Action extends DatabasesAction
{
    /**
     * The current API context (either 'table' or 'collection').
     */
    private ?string $context = COLLECTIONS;

    public function setHttpPath(string $path): DatabasesAction
    {
        if (\str_contains($path, '/tablesdb')) {
            $this->context = TABLES;
        }
        return parent::setHttpPath($path);
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
