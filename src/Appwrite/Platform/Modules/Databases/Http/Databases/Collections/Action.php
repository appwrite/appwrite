<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections;

use Appwrite\Extend\Exception;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;

abstract class Action extends UtopiaAction
{
    /**
     * The current API context (either 'table' or 'collection').
     */
    private ?string $context = COLLECTIONS;

    /**
     * Get the response model used in the SDK and HTTP responses.
     */
    abstract protected function getResponseModel(): string;

    public function setHttpPath(string $path): UtopiaAction
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
     * Get the key used in event parameters (e.g., 'collectionId' or 'tableId').
     */
    protected function getEventsParamKey(): string
    {
        return $this->getContext() . 'Id';
    }

    /**
     * Determine if the current action is for the Collections API.
     */
    protected function isCollectionsAPI(): bool
    {
        return $this->getContext() === COLLECTIONS;
    }

    /**
     * Get the SDK group name for the current action.
     */
    protected function getSdkGroup(): string
    {
        return $this->isCollectionsAPI() ? 'collections' : 'tables';
    }

    /**
     * Get the SDK namespace for the current action.
     */
    protected function getSdkNamespace(): string
    {
        return $this->isCollectionsAPI() ? 'databases' : 'tablesdb';
    }

    /**
     * Get the exception to throw when the resource already exists.
     */
    protected function getDuplicateException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_ALREADY_EXISTS
            : Exception::TABLE_ALREADY_EXISTS;
    }

    /**
     * Get the appropriate index invalid exception.
     */
    protected function getInvalidIndexException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_INVALID
            : Exception::COLUMN_INDEX_INVALID;
    }

    /**
     * Get the exception to throw when the resource is not found.
     */
    protected function getNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_NOT_FOUND
            : Exception::TABLE_NOT_FOUND;
    }

    /**
     * Get the exception to throw when the resource limit is exceeded.
     */
    protected function getLimitException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_LIMIT_EXCEEDED
            : Exception::TABLE_LIMIT_EXCEEDED;
    }
}
