<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;

/**
 * Trait for polling schema changes (attributes, indexes) instead of using sleep().
 * Uses assertEventually to wait for async operations to complete.
 */
trait SchemaPolling
{
    /**
     * Wait for an attribute to become available.
     *
     * @param string $databaseId The database ID
     * @param string $containerId The collection/table ID
     * @param string $attributeKey The attribute key to wait for
     * @param int $timeoutMs Maximum time to wait in milliseconds
     * @param int $waitMs Time between polling attempts in milliseconds
     */
    protected function waitForAttribute(string $databaseId, string $containerId, string $attributeKey, int $timeoutMs = 480000, int $waitMs = 500): void
    {
        $this->assertEventually(function () use ($databaseId, $containerId, $attributeKey) {
            $attribute = $this->client->call(
                Client::METHOD_GET,
                $this->getSchemaUrl($databaseId, $containerId) . '/' . $attributeKey,
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey']
                ])
            );

            $this->assertEquals(200, $attribute['headers']['status-code']);
            $this->assertEquals('available', $attribute['body']['status']);
        }, $timeoutMs, $waitMs);
    }

    /**
     * Wait for multiple attributes to become available.
     *
     * @param string $databaseId The database ID
     * @param string $containerId The collection/table ID
     * @param array $attributeKeys Array of attribute keys to wait for
     * @param int $timeoutMs Maximum time to wait in milliseconds
     * @param int $waitMs Time between polling attempts in milliseconds
     */
    protected function waitForAttributes(string $databaseId, string $containerId, array $attributeKeys, int $timeoutMs = 480000, int $waitMs = 500): void
    {
        $this->assertEventually(function () use ($databaseId, $containerId, $attributeKeys) {
            $container = $this->client->call(
                Client::METHOD_GET,
                $this->getContainerUrl($databaseId, $containerId),
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey']
                ])
            );

            $this->assertEquals(200, $container['headers']['status-code']);
            $this->assertArrayHasKey('body', $container);
            $this->assertArrayHasKey($this->getSchemaResource(), $container['body']);

            $attributes = $container['body'][$this->getSchemaResource()];
            $availableKeys = [];

            foreach ($attributes as $attr) {
                if ($attr['status'] === 'available') {
                    $availableKeys[] = $attr['key'];
                }
            }

            foreach ($attributeKeys as $key) {
                $this->assertContains($key, $availableKeys, "Attribute '$key' is not available yet");
            }
        }, $timeoutMs, $waitMs);
    }

    /**
     * Wait for the collection/table to have at least a certain number of available attributes.
     *
     * @param string $databaseId The database ID
     * @param string $containerId The collection/table ID
     * @param int $count Minimum number of available attributes required
     * @param int $timeoutMs Maximum time to wait in milliseconds
     * @param int $waitMs Time between polling attempts in milliseconds
     */
    protected function waitForAttributeCount(string $databaseId, string $containerId, int $count, int $timeoutMs = 480000, int $waitMs = 500): void
    {
        $this->assertEventually(function () use ($databaseId, $containerId, $count) {
            $container = $this->client->call(
                Client::METHOD_GET,
                $this->getContainerUrl($databaseId, $containerId),
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey']
                ])
            );

            $this->assertEquals(200, $container['headers']['status-code']);
            $this->assertArrayHasKey('body', $container);
            $this->assertArrayHasKey($this->getSchemaResource(), $container['body']);

            $attributes = $container['body'][$this->getSchemaResource()];
            $availableCount = 0;

            foreach ($attributes as $attr) {
                if ($attr['status'] === 'available') {
                    $availableCount++;
                }
            }

            $this->assertGreaterThanOrEqual($count, $availableCount, "Expected at least $count available attributes, got $availableCount");
        }, $timeoutMs, $waitMs);
    }

    /**
     * Wait for an index to become available.
     *
     * @param string $databaseId The database ID
     * @param string $containerId The collection/table ID
     * @param string $indexKey The index key to wait for
     * @param int $timeoutMs Maximum time to wait in milliseconds
     * @param int $waitMs Time between polling attempts in milliseconds
     */
    protected function waitForIndex(string $databaseId, string $containerId, string $indexKey, int $timeoutMs = 480000, int $waitMs = 500): void
    {
        $this->assertEventually(function () use ($databaseId, $containerId, $indexKey) {
            $index = $this->client->call(
                Client::METHOD_GET,
                $this->getIndexUrl($databaseId, $containerId, $indexKey),
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey']
                ])
            );

            $this->assertEquals(200, $index['headers']['status-code']);
            $this->assertArrayHasKey('body', $index);
            $this->assertArrayHasKey('status', $index['body']);
            $this->assertEquals('available', $index['body']['status']);
        }, $timeoutMs, $waitMs);
    }

    /**
     * Wait for all indexes in a collection/table to become available.
     *
     * @param string $databaseId The database ID
     * @param string $containerId The collection/table ID
     * @param int $timeoutMs Maximum time to wait in milliseconds
     * @param int $waitMs Time between polling attempts in milliseconds
     */
    protected function waitForAllIndexes(string $databaseId, string $containerId, int $timeoutMs = 480000, int $waitMs = 500): void
    {
        $this->assertEventually(function () use ($databaseId, $containerId) {
            $container = $this->client->call(
                Client::METHOD_GET,
                $this->getContainerUrl($databaseId, $containerId),
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey']
                ])
            );

            $this->assertEquals(200, $container['headers']['status-code']);
            $this->assertArrayHasKey('body', $container);
            $this->assertArrayHasKey('indexes', $container['body']);

            foreach ($container['body']['indexes'] as $index) {
                $this->assertEquals('available', $index['status'], "Index '{$index['key']}' is not available yet");
            }
        }, $timeoutMs, $waitMs);
    }

    /**
     * Wait for all attributes in a collection/table to become available.
     *
     * @param string $databaseId The database ID
     * @param string $containerId The collection/table ID
     * @param int $timeoutMs Maximum time to wait in milliseconds (default 8 minutes for CI stability under parallel load)
     * @param int $waitMs Time between polling attempts in milliseconds
     */
    protected function waitForAllAttributes(string $databaseId, string $containerId, int $timeoutMs = 480000, int $waitMs = 500): void
    {
        $this->assertEventually(function () use ($databaseId, $containerId) {
            $container = $this->client->call(
                Client::METHOD_GET,
                $this->getContainerUrl($databaseId, $containerId),
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey']
                ])
            );

            // Tolerate transient 500s during heavy attribute processing
            $this->assertContains($container['headers']['status-code'], [200], "Expected 200 but got {$container['headers']['status-code']} polling container {$containerId}");

            $schemaResource = $this->getSchemaResource();
            $this->assertNotEmpty($container['body'][$schemaResource], "No attributes found in container {$containerId}");

            foreach ($container['body'][$schemaResource] as $attribute) {
                $this->assertEquals('available', $attribute['status'], "Attribute '{$attribute['key']}' is not available yet");
            }
        }, $timeoutMs, $waitMs);
    }
}
