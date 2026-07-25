<?php

namespace Tests\E2E\Traits;

/**
 * URL helper methods for database tests.
 * This trait provides methods to build API endpoints dynamically based on the API variant.
 * Requires ApiLegacy or ApiTablesDB trait to provide getApiBasePath(), getContainerResource(), etc.
 */
trait DatabasesUrlHelpers
{
    protected function getDatabaseUrl(string $databaseId = ''): string
    {
        $base = $this->getApiBasePath();
        return $databaseId ? "{$base}/{$databaseId}" : $base;
    }

    protected function getContainerUrl(string $databaseId, string $containerId = ''): string
    {
        $resource = $this->getContainerResource();
        $base = "{$this->getApiBasePath()}/{$databaseId}/{$resource}";
        return $containerId ? "{$base}/{$containerId}" : $base;
    }

    protected function getSchemaUrl(string $databaseId, string $containerId, ?string $type = '', ?string $key = ''): string
    {
        $resource = $this->getSchemaResource();
        $base = "{$this->getContainerUrl($databaseId, $containerId)}/{$resource}";
        // For relationship updates, the URL pattern is /attributes/{key}/relationship
        // For other attribute updates, the URL pattern is /attributes/{type}/{key}
        if ($type === 'relationship' && $key) {
            $base .= "/{$key}/{$type}";
        } else {
            if ($type) {
                $base .= "/{$type}";
            }
            if ($key) {
                $base .= "/{$key}";
            }
        }
        return $base;
    }

    protected function getRecordUrl(string $databaseId, string $containerId, ?string $recordId = ''): string
    {
        $resource = $this->getRecordResource();
        $base = "{$this->getContainerUrl($databaseId, $containerId)}/{$resource}";
        return $recordId ? "{$base}/{$recordId}" : $base;
    }

    protected function getTransactionUrl(string $transactionId = ''): string
    {
        $base = "{$this->getApiBasePath()}/transactions";
        return $transactionId ? "{$base}/{$transactionId}" : $base;
    }

    protected function getIndexUrl(string $databaseId, string $containerId, ?string $indexKey = ''): string
    {
        $base = "{$this->getContainerUrl($databaseId, $containerId)}/indexes";
        return $indexKey ? "{$base}/{$indexKey}" : $base;
    }
}
