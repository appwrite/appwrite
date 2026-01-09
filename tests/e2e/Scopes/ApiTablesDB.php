<?php

namespace Tests\E2E\Scopes;

/**
 * API configuration trait for TablesDB database API.
 * Uses: /tablesdb, tables, columns, rows
 */
trait ApiTablesDB
{
    protected function getApiBasePath(): string
    {
        return '/tablesdb';
    }

    protected function getDatabaseType(): string
    {
        return 'tablesdb';
    }

    protected function getContainerResource(): string
    {
        return 'tables';
    }

    protected function getContainerIdParam(): string
    {
        return 'tableId';
    }

    protected function getSchemaResource(): string
    {
        return 'columns';
    }

    protected function getSchemaParam(): string
    {
        return 'column';
    }

    protected function getRecordResource(): string
    {
        return 'rows';
    }

    protected function getRecordIdParam(): string
    {
        return 'rowId';
    }

    protected function getSecurityParam(): string
    {
        return 'rowSecurity';
    }

    protected function getRelatedIdParam(): string
    {
        return 'relatedTableId';
    }

    protected function getRelatedResourceKey(): string
    {
        return 'relatedTable';
    }

    protected function getContainerIdResponseKey(): string
    {
        return '$tableId';
    }

    protected function getIndexAttributesParam(): string
    {
        return 'columns';
    }

    protected function getSecurityResponseKey(): string
    {
        return 'rowSecurity';
    }
}
