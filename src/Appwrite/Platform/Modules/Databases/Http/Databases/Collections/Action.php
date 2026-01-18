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
    protected function getSDKGroup(): string
    {
        return $this->isCollectionsAPI() ? 'collections' : 'tables';
    }

    /**
     * Get the SDK namespace for the current action.
     */
    protected function getSDKNamespace(): string
    {
        return $this->isCollectionsAPI() ? 'databases' : 'tablesDB';
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

    /**
     * Get the appropriate format unsupported exception.
     */
    protected function getFormatUnsupportedException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_FORMAT_UNSUPPORTED
            : Exception::COLUMN_FORMAT_UNSUPPORTED;
    }

    /**
     * Get the correct default unsupported message.
     */
    protected function getDefaultUnsupportedException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED
            : Exception::COLUMN_DEFAULT_UNSUPPORTED;
    }

    /**
     * Get the appropriate parent level not found exception.
     */
    protected function getParentNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_NOT_FOUND
            : Exception::TABLE_NOT_FOUND;
    }

    /**
     * Get the correct invalid structure message.
     */
    protected function getStructureException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_INVALID_STRUCTURE
            : Exception::ROW_INVALID_STRUCTURE;
    }

    /**
     * Get the exception for unknown attribute/column in index.
     */
    protected function getParentUnknownException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_UNKNOWN
            : Exception::COLUMN_UNKNOWN;
    }

    /**
     * Get the exception for invalid attribute/column type in index.
     */
    protected function getParentInvalidTypeException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_TYPE_INVALID
            : Exception::COLUMN_TYPE_INVALID;
    }
}
