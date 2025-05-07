<?php

namespace Appwrite\Platform\Modules\Databases\Http\Collections;

use Appwrite\Extend\Exception;
use Utopia\Platform\Action as UtopiaAction;

/**
 * Abstract base action for Collection and Table database routes.
 *
 * Provides shared utilities to determine API type, response model,
 * SDK group, and context-specific exceptions.
 */
abstract class Action extends UtopiaAction
{

    /**
     * The current API context (either 'table' or 'collection').
     */
    private ?string $context = DATABASE_COLLECTIONS_CONTEXT;

    /**
     * Set the current API context.
     *
     * @param string $context Must be either `DATABASE_TABLES_CONTEXT` or `DATABASE_COLLECTIONS_CONTEXT`.
     */
    final protected function setContext(string $context): void
    {
        if (!\in_array($context, [DATABASE_TABLES_CONTEXT, DATABASE_COLLECTIONS_CONTEXT], true)) {
            throw new \InvalidArgumentException("Invalid context '$context'. Must be either `DATABASE_TABLES_CONTEXT` or `DATABASE_COLLECTIONS_CONTEXT`.");
        }

        $this->context = $context;
    }

    /**
     * Get the current API context.
     *
     * @throws \Exception if context has not been set.
     */
    final protected function getContext(): string
    {
        return $this->context;
    }

    /**
     * Get the key used in event parameters (e.g., 'collectionId' or 'tableId').
     */
    final protected function getEventsParamKey(): string
    {
        return $this->getContext() . 'Id';
    }

    /**
     * Get the response model used in the SDK and HTTP responses.
     */
    abstract protected function getResponseModel(): string;

    /**
     * Determine if the current action is for the Collections API.
     */
    final protected function isCollectionsAPI(): bool
    {
        return $this->getContext() === DATABASE_COLLECTIONS_CONTEXT;
    }

    /**
     * Get the SDK group name for the current action.
     */
    final protected function getSdkGroup(): string
    {
        return $this->isCollectionsAPI() ? 'collections' : 'tables';
    }

    /**
     * Get the exception to throw when the resource already exists.
     */
    final protected function getDuplicateException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_ALREADY_EXISTS
            : Exception::TABLE_ALREADY_EXISTS;
    }

    /**
     * Get the exception to throw when the resource is not found.
     */
    final protected function getNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_NOT_FOUND
            : Exception::TABLE_NOT_FOUND;
    }

    /**
     * Get the exception to throw when the resource limit is exceeded.
     */
    final protected function getLimitException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_LIMIT_EXCEEDED
            : Exception::TABLE_LIMIT_EXCEEDED;
    }
}
