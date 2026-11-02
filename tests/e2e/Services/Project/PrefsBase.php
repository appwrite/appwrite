<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;

trait PrefsBase
{
    // Update prefs tests

    public function testUpdatePrefs(): void
    {
        $response = $this->updatePrefs([
            'funcKey1' => 'funcValue1',
            'funcKey2' => 'funcValue2',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('funcValue1', $response['body']['funcKey1']);
        $this->assertSame('funcValue2', $response['body']['funcKey2']);

        // Cleanup
        $this->updatePrefs([]);
    }

    public function testUpdatePrefsReplace(): void
    {
        $response = $this->updatePrefs(['alpha' => 'a', 'beta' => 'b']);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(['alpha' => 'a', 'beta' => 'b'], $response['body']);

        // Replace with new prefs; previous keys are dropped
        $response = $this->updatePrefs(['gamma' => 'g']);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(['gamma' => 'g'], $response['body']);

        $response = $this->getPrefs();

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(['gamma' => 'g'], $response['body']);

        // Cleanup
        $this->updatePrefs([]);
    }

    public function testUpdatePrefsNested(): void
    {
        $prefs = [
            'theme' => 'dark',
            'notifications' => ['email' => true, 'push' => false],
            'columns' => ['name', 'status'],
            'limit' => 25,
        ];

        $response = $this->updatePrefs($prefs);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($prefs, $response['body']);

        $response = $this->getPrefs();

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($prefs, $response['body']);

        // Cleanup
        $this->updatePrefs([]);
    }

    public function testUpdatePrefsEmpty(): void
    {
        $response = $this->updatePrefs(['toRemove' => 'value']);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('value', $response['body']['toRemove']);

        // Clear all prefs
        $response = $this->updatePrefs([]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame([], $response['body']);

        $response = $this->getPrefs();

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame([], $response['body']);
    }

    public function testUpdatePrefsIdempotent(): void
    {
        $prefs = ['stable' => true, 'env' => 'production'];

        $first = $this->updatePrefs($prefs);
        $this->assertSame(200, $first['headers']['status-code']);

        $second = $this->updatePrefs($prefs);
        $this->assertSame(200, $second['headers']['status-code']);

        $this->assertSame($first['body'], $second['body']);

        // Cleanup
        $this->updatePrefs([]);
    }

    public function testUpdatePrefsWithoutAuthentication(): void
    {
        $response = $this->updatePrefs(['unauthorized' => true], false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdatePrefsInvalidType(): void
    {
        $response = $this->updatePrefs('bad-string');

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdatePrefsMissing(): void
    {
        $response = $this->client->call(Client::METHOD_PUT, '/project/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdatePrefsTooLarge(): void
    {
        $response = $this->updatePrefs(['data' => \str_repeat('a', 65536)]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    // Get prefs tests

    public function testGetPrefs(): void
    {
        $response = $this->updatePrefs(['theme' => 'light']);
        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->getPrefs();

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(['theme' => 'light'], $response['body']);

        // Cleanup
        $this->updatePrefs([]);
    }

    public function testGetPrefsEmptyObject(): void
    {
        $response = $this->updatePrefs([]);
        $this->assertSame(200, $response['headers']['status-code']);

        // Empty prefs serialize as a JSON object, not an array
        $response = $this->getPrefs(decode: false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('{}', $response['body']);
    }

    public function testGetPrefsWithoutAuthentication(): void
    {
        $response = $this->getPrefs(false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // Project model

    public function testUpdatePrefsProjectModel(): void
    {
        $response = $this->updatePrefs(['theme' => 'dark']);
        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/project', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(['theme' => 'dark'], $response['body']['prefs']);

        // Cleanup
        $this->updatePrefs([]);

        $response = $this->client->call(Client::METHOD_GET, '/project', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [], false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertStringContainsString('"prefs":{}', $response['body']);
    }

    // Helpers

    protected function updatePrefs(mixed $prefs, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PUT, '/project/prefs', $headers, [
            'prefs' => $prefs,
        ]);
    }

    protected function getPrefs(bool $authenticated = true, bool $decode = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_GET, '/project/prefs', $headers, [], $decode);
    }
}
