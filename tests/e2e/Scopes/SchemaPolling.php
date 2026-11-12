<?php

namespace Tests\E2E\Scopes;

use Appwrite\Tests\Async\Exceptions\Critical;
use Tests\E2E\Client;

/**
 * Trait for polling schema changes (attributes, indexes) instead of using sleep().
 * Uses assertEventually to wait for async operations to complete.
 */
trait SchemaPolling
{
    private const int SCHEMA_POLL_SERVER_ERROR_LIMIT = 10;

    /**
     * Wait for an attribute to become available.
     *
     * @param string $databaseId The database ID
     * @param string $containerId The collection/table ID
     * @param string $attributeKey The attribute key to wait for
     * @param int $timeoutMs Maximum time to wait in milliseconds
     * @param int $waitMs Time between polling attempts in milliseconds
     */
    protected function waitForAttribute(string $databaseId, string $containerId, string $attributeKey, int $timeoutMs = 360000, int $waitMs = 500): void
    {
        if (!$this->getSupportForAttributes()) {
            return;
        }
        $serverErrors = 0;
        $this->assertEventually(function () use ($databaseId, $containerId, $attributeKey, &$serverErrors) {
            $attribute = $this->pollSchema($this->getSchemaUrl($databaseId, $containerId) . '/' . $attributeKey, $serverErrors);

            $this->assertEquals(200, $attribute['headers']['status-code']);

            $status = $attribute['body']['status'] ?? '';
            if ($status === 'failed') {
                throw new Critical("Attribute '{$attributeKey}' failed: " . ($attribute['body']['error'] ?? 'unknown error'));
            }

            $this->assertEquals('available', $status);
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
    protected function waitForIndex(string $databaseId, string $containerId, string $indexKey, int $timeoutMs = 360000, int $waitMs = 500): void
    {
        $serverErrors = 0;
        $this->assertEventually(function () use ($databaseId, $containerId, $indexKey, &$serverErrors) {
            $index = $this->pollSchema($this->getIndexUrl($databaseId, $containerId, $indexKey), $serverErrors);

            $this->assertEquals(200, $index['headers']['status-code']);
            $this->assertArrayHasKey('body', $index);
            $this->assertArrayHasKey('status', $index['body']);

            $status = $index['body']['status'] ?? '';
            if ($status === 'failed') {
                throw new Critical("Index '{$indexKey}' failed: " . ($index['body']['error'] ?? 'unknown error'));
            }

            $this->assertEquals('available', $status);
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
    protected function waitForAllIndexes(string $databaseId, string $containerId, int $timeoutMs = 360000, int $waitMs = 500): void
    {
        $serverErrors = 0;
        $this->assertEventually(function () use ($databaseId, $containerId, &$serverErrors) {
            $container = $this->pollSchema($this->getContainerUrl($databaseId, $containerId), $serverErrors);

            $this->assertEquals(200, $container['headers']['status-code']);
            $this->assertArrayHasKey('body', $container);
            $this->assertArrayHasKey('indexes', $container['body']);

            foreach ($container['body']['indexes'] as $index) {
                if ($index['status'] === 'failed') {
                    throw new Critical("Index '{$index['key']}' failed: " . ($index['error'] ?? 'unknown error'));
                }
                $this->assertEquals('available', $index['status'], "Index '{$index['key']}' is not available yet");
            }
        }, $timeoutMs, $waitMs);
    }

    /**
     * Wait for all attributes in a collection/table to become available.
     *
     * @param string $databaseId The database ID
     * @param string $containerId The collection/table ID
     * @param int $timeoutMs Maximum time to wait in milliseconds
     * @param int $waitMs Time between polling attempts in milliseconds
     */
    protected function waitForAllAttributes(string $databaseId, string $containerId, int $timeoutMs = 360000, int $waitMs = 500): void
    {
        $serverErrors = 0;
        $this->assertEventually(function () use ($databaseId, $containerId, &$serverErrors) {
            $container = $this->pollSchema($this->getContainerUrl($databaseId, $containerId), $serverErrors);

            $this->assertSame(200, $container['headers']['status-code'], "Expected 200 but got {$container['headers']['status-code']} polling container {$containerId}");

            $schemaResource = $this->getSchemaResource();
            $this->assertNotEmpty($container['body'][$schemaResource], "No attributes found in container {$containerId}");

            foreach ($container['body'][$schemaResource] as $attribute) {
                if ($attribute['status'] === 'failed') {
                    throw new Critical("Attribute '{$attribute['key']}' failed: " . ($attribute['error'] ?? 'unknown error'));
                }
                $this->assertEquals('available', $attribute['status'], "Attribute '{$attribute['key']}' is not available yet");
            }
        }, $timeoutMs, $waitMs);
    }

    /**
     * A poll retries until the schema settles, which tolerates a transient server error but would spend the whole
     * timeout retrying one the server returns every time. A run of server errors fails the wait with the error.
     *
     * @return array{headers: array<string, mixed>, body: mixed}
     * @throws Critical
     */
    private function pollSchema(string $url, int &$serverErrors): array
    {
        $response = $this->client->call(
            Client::METHOD_GET,
            $url,
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]
        );

        $status = (int) $response['headers']['status-code'];
        if ($status < 500) {
            $serverErrors = 0;

            return $response;
        }

        if (++$serverErrors >= self::SCHEMA_POLL_SERVER_ERROR_LIMIT) {
            $body = \is_array($response['body']) ? $response['body'] : [];
            $origin = isset($body['file']) ? " at {$body['file']}:" . ($body['line'] ?? '?') : '';
            throw new Critical("GET {$url} returned {$serverErrors} server errors in a row, the last {$status}: " . ($body['message'] ?? 'no message') . $origin);
        }

        return $response;
    }
}
