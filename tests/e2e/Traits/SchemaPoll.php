<?php

namespace Tests\E2E\Traits;

use Tests\E2E\Client;

trait SchemaPoll
{
    protected function getSchemaApiConfig(): array
    {
        return [
            'basePath' => '/databases',
            'collectionPath' => 'collections',
            'attributePath' => 'attributes',
            'indexPath' => 'indexes',
        ];
    }

    protected function waitForAttribute(
        string $databaseId,
        string $collectionId,
        string $attributeKey,
        int $timeoutMs = 30000,
        int $intervalMs = 100
    ): void {
        $config = $this->getSchemaApiConfig();

        $this->assertEventually(function () use ($config, $databaseId, $collectionId, $attributeKey) {
            $response = $this->client->call(
                Client::METHOD_GET,
                $config['basePath'] . '/' . $databaseId . '/' . $config['collectionPath'] . '/' . $collectionId . '/' . $config['attributePath'] . '/' . $attributeKey,
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders())
            );

            $status = $response['body']['status'] ?? 'unknown';

            if ($status === 'failed') {
                throw new \Exception("Attribute creation failed: " . ($response['body']['error'] ?? 'unknown error'));
            }

            $this->assertEquals('available', $status);
            return true;
        }, $timeoutMs, $intervalMs);
    }

    protected function waitForIndex(
        string $databaseId,
        string $collectionId,
        string $indexKey,
        int $timeoutMs = 60000,
        int $intervalMs = 100
    ): void {
        $config = $this->getSchemaApiConfig();

        $this->assertEventually(function () use ($config, $databaseId, $collectionId, $indexKey) {
            $response = $this->client->call(
                Client::METHOD_GET,
                $config['basePath'] . '/' . $databaseId . '/' . $config['collectionPath'] . '/' . $collectionId . '/' . $config['indexPath'] . '/' . $indexKey,
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders())
            );

            $status = $response['body']['status'] ?? 'unknown';

            if ($status === 'failed') {
                throw new \Exception("Index creation failed: " . ($response['body']['error'] ?? 'unknown error'));
            }

            $this->assertEquals('available', $status);
            return true;
        }, $timeoutMs, $intervalMs);
    }

    protected function waitForAllAttributes(
        string $databaseId,
        string $collectionId,
        int $timeoutMs = 30000,
        int $intervalMs = 100
    ): void {
        $config = $this->getSchemaApiConfig();

        $this->assertEventually(function () use ($config, $databaseId, $collectionId) {
            $response = $this->client->call(
                Client::METHOD_GET,
                $config['basePath'] . '/' . $databaseId . '/' . $config['collectionPath'] . '/' . $collectionId . '/' . $config['attributePath'],
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders())
            );

            $attributes = $response['body']['attributes'] ?? $response['body']['columns'] ?? [];

            if (empty($attributes)) {
                return true;
            }

            foreach ($attributes as $attr) {
                if ($attr['status'] === 'failed') {
                    throw new \Exception("Attribute '{$attr['key']}' creation failed: " . ($attr['error'] ?? 'unknown error'));
                }
                $this->assertEquals('available', $attr['status']);
            }

            return true;
        }, $timeoutMs, $intervalMs);
    }

    protected function waitForAllIndexes(
        string $databaseId,
        string $collectionId,
        int $timeoutMs = 60000,
        int $intervalMs = 100
    ): void {
        $config = $this->getSchemaApiConfig();

        $this->assertEventually(function () use ($config, $databaseId, $collectionId) {
            $response = $this->client->call(
                Client::METHOD_GET,
                $config['basePath'] . '/' . $databaseId . '/' . $config['collectionPath'] . '/' . $collectionId . '/' . $config['indexPath'],
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders())
            );

            $indexes = $response['body']['indexes'] ?? [];

            if (empty($indexes)) {
                return true;
            }

            foreach ($indexes as $index) {
                if ($index['status'] === 'failed') {
                    throw new \Exception("Index '{$index['key']}' creation failed: " . ($index['error'] ?? 'unknown error'));
                }
                $this->assertEquals('available', $index['status']);
            }

            return true;
        }, $timeoutMs, $intervalMs);
    }

    protected function waitForAttributeDeletion(
        string $databaseId,
        string $collectionId,
        string $attributeKey,
        int $timeoutMs = 30000,
        int $intervalMs = 100
    ): void {
        $config = $this->getSchemaApiConfig();

        $this->assertEventually(function () use ($config, $databaseId, $collectionId, $attributeKey) {
            $response = $this->client->call(
                Client::METHOD_GET,
                $config['basePath'] . '/' . $databaseId . '/' . $config['collectionPath'] . '/' . $collectionId . '/' . $config['attributePath'] . '/' . $attributeKey,
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders())
            );

            if ($response['headers']['status-code'] === 404) {
                return true;
            }

            $status = $response['body']['status'] ?? 'unknown';

            if ($status === 'available') {
                throw new \Exception("Attribute '{$attributeKey}' deletion failed - still available");
            }

            $this->assertNotEquals('deleting', $status);
            return true;
        }, $timeoutMs, $intervalMs);
    }

    protected function waitForIndexDeletion(
        string $databaseId,
        string $collectionId,
        string $indexKey,
        int $timeoutMs = 60000,
        int $intervalMs = 100
    ): void {
        $config = $this->getSchemaApiConfig();

        $this->assertEventually(function () use ($config, $databaseId, $collectionId, $indexKey) {
            $response = $this->client->call(
                Client::METHOD_GET,
                $config['basePath'] . '/' . $databaseId . '/' . $config['collectionPath'] . '/' . $collectionId . '/' . $config['indexPath'] . '/' . $indexKey,
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders())
            );

            if ($response['headers']['status-code'] === 404) {
                return true;
            }

            $status = $response['body']['status'] ?? 'unknown';

            if ($status === 'available') {
                throw new \Exception("Index '{$indexKey}' deletion failed - still available");
            }

            $this->assertNotEquals('deleting', $status);
            return true;
        }, $timeoutMs, $intervalMs);
    }
}
