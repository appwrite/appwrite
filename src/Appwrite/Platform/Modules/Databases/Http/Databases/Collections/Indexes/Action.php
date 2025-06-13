<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Context;
use Utopia\Platform\Action as UtopiaAction;

abstract class Action extends UtopiaAction
{
    /**
     * The current API context (either 'columnIndex' or 'index').
     */
    private ?string $context = Context::DATABASE_INDEX;

    /**
     * Get the response model used in the SDK and HTTP responses.
     */
    abstract protected function getResponseModel(): string;

    /**
     * Set the current API context.
     *
     * @param string $context Must be either `DATABASE_INDEX` or `DATABASE_COLUMN_INDEX`.
     */
    final protected function setContext(string $context): void
    {
        if (!\in_array($context, [Context::DATABASE_INDEX, Context::DATABASE_COLUMN_INDEX], true)) {
            throw new \InvalidArgumentException("Invalid context '$context'. Must be either `Context::DATABASE_COLUMN_INDEX` or `Context::DATABASE_INDEX`.");
        }

        $this->context = $context;
    }

    /**
     * Get the current API's parent context.
     */
    final protected function getParentContext(): string
    {
        return $this->getContext() === Context::DATABASE_INDEX
            ? Context::DATABASE_ATTRIBUTES
            : Context::DATABASE_COLUMNS;
    }

    /**
     * Get the current API context.
     */
    final protected function getContext(): string
    {
        return $this->context;
    }

    /**
     * Determine if the current action is for the Collections API.
     */
    final protected function isCollectionsAPI(): bool
    {
        return $this->getParentContext() === Context::DATABASE_ATTRIBUTES;
    }

    /**
     * Get the SDK group name for the current action.
     */
    final protected function getSdkGroup(): string
    {
        return 'indexes';
    }

    /**
     * Get the SDK namespace for the current action.
     */
    final protected function getSdkNamespace(): string
    {
        return $this->isCollectionsAPI() ? 'collections' : 'tables';
    }

    /**
     * Get the exception to throw when the parent is unknown.
     */
    final protected function getParentUnknownException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_UNKNOWN
            : Exception::COLUMN_UNKNOWN;
    }

    /**
     * Get the appropriate grandparent level not found exception.
     */
    final protected function getGrandParentNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_NOT_FOUND
            : Exception::TABLE_NOT_FOUND;
    }

    /**
     * Get the appropriate not found exception.
     */
    final protected function getNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_NOT_FOUND
            : Exception::COLUMN_INDEX_NOT_FOUND;
    }

    /**
     * Get the exception to throw when the parent type is invalid.
     */
    final protected function getParentInvalidTypeException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_TYPE_INVALID
            : Exception::COLUMN_TYPE_INVALID;
    }

    /**
     * Get the exception to throw when the index type is invalid.
     */
    final protected function getInvalidTypeException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_INVALID
            : Exception::COLUMN_INDEX_INVALID;
    }

    /**
     * Get the exception to throw when the resource already exists.
     */
    final protected function getDuplicateException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_ALREADY_EXISTS
            : Exception::COLUMN_INDEX_ALREADY_EXISTS;
    }

    /**
     * Get the exception to throw when the resource limit is exceeded.
     */
    final protected function getLimitException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_LIMIT_EXCEEDED
            : Exception::COLUMN_INDEX_LIMIT_EXCEEDED;
    }

    /**
     * Get the exception to throw when the parent attribute/column is not in `available` state.
     */
    final protected function getParentNotAvailableException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_NOT_AVAILABLE
            : Exception::COLUMN_NOT_AVAILABLE;
    }

    /**
     * Get the correct collections context for Events queue.
     */
    final protected function getCollectionsEventsContext(): string
    {
        return $this->isCollectionsAPI() ? 'collection' : 'table';
    }
}
