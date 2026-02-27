<?php

namespace Tests\E2E\Scopes;

/**
 * API configuration trait for VectorDB database API.
 * Uses: /vectordb, collections, documents, indexes
 */
trait ApiVectorDB
{
    protected function getApiBasePath(): string
    {
        return '/vectordb';
    }

    protected function getDatabaseType(): string
    {
        return 'vectordb';
    }

    protected function getContainerResource(): string
    {
        return 'collections';
    }

    protected function getContainerIdParam(): string
    {
        return 'collectionId';
    }

    protected function getSchemaResource(): string
    {
        return 'attributes';
    }

    protected function getSchemaParam(): string
    {
        return 'attribute';
    }

    protected function getRecordResource(): string
    {
        return 'documents';
    }

    protected function getRecordIdParam(): string
    {
        return 'documentId';
    }

    protected function getSecurityParam(): string
    {
        return 'documentSecurity';
    }

    protected function getRelatedIdParam(): string
    {
        return 'relatedCollectionId';
    }

    protected function getRelatedResourceKey(): string
    {
        return 'relatedCollection';
    }

    protected function getContainerIdResponseKey(): string
    {
        return '$collectionId';
    }

    protected function getOppositeContainerIdResponseKey(): string
    {
        return '$tableId';
    }

    protected function getIndexAttributesParam(): string
    {
        return 'attributes';
    }

    protected function getSecurityResponseKey(): string
    {
        return 'documentSecurity';
    }

    protected function getSupportForAttributes(): bool
    {
        return false;
    }

    protected function getSupportForRelationships(): bool
    {
        return false;
    }

    protected function getSupportForIntegerIds(): bool
    {
        return false;
    }

    protected function getSupportForOperators(): bool
    {
        return false;
    }

    protected function getSupportForSpatials(): bool
    {
        return false;
    }
}
