<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Sandbox;

use PHPUnit\Framework\Attributes\Depends;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class SandboxCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private function call(string $method, string $path, array $body = []): array
    {
        return $this->client->call($method, $path, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $body);
    }

    public function testCreate(): array
    {
        $sandbox = $this->call(Client::METHOD_POST, '/sandbox', [
            'sandboxId' => 'test',
            'image' => 'python:3.12-slim',
        ]);

        $this->assertEquals(201, $sandbox['headers']['status-code']);
        $this->assertEquals('test', $sandbox['body']['$id']);
        $this->assertEquals('ready', $sandbox['body']['status']);
        $this->assertNotEmpty($sandbox['body']['url']);
        $this->assertArrayHasKey('3000', $sandbox['body']['urls']);

        return $sandbox['body'];
    }

    #[Depends('testCreate')]
    public function testCreateDuplicate(array $sandbox): void
    {
        $duplicate = $this->call(Client::METHOD_POST, '/sandbox', [
            'sandboxId' => $sandbox['$id'],
            'image' => 'python:3.12-slim',
        ]);

        $this->assertEquals(409, $duplicate['headers']['status-code']);
        $this->assertEquals('sandbox_already_exists', $duplicate['body']['type']);
    }

    public function testCreateWithoutImage(): void
    {
        $sandbox = $this->call(Client::METHOD_POST, '/sandbox');

        $this->assertEquals(400, $sandbox['headers']['status-code']);
    }

    public function testCreateInvalidId(): void
    {
        $sandbox = $this->call(Client::METHOD_POST, '/sandbox', [
            'sandboxId' => 'Not_Valid',
            'image' => 'python:3.12-slim',
        ]);

        $this->assertEquals(400, $sandbox['headers']['status-code']);
    }

    #[Depends('testCreate')]
    public function testGet(array $sandbox): void
    {
        $found = $this->call(Client::METHOD_GET, '/sandbox/' . $sandbox['$id']);

        $this->assertEquals(200, $found['headers']['status-code']);
        $this->assertEquals($sandbox['$id'], $found['body']['$id']);
        $this->assertEquals('ready', $found['body']['status']);
    }

    #[Depends('testCreate')]
    public function testList(array $sandbox): void
    {
        $list = $this->call(Client::METHOD_GET, '/sandbox');

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertEquals(1, $list['body']['total']);
        $this->assertEquals($sandbox['$id'], $list['body']['sandboxes'][0]['$id']);
    }

    #[Depends('testCreate')]
    public function testDelete(array $sandbox): void
    {
        $deleted = $this->call(Client::METHOD_DELETE, '/sandbox/' . $sandbox['$id']);
        $this->assertEquals(204, $deleted['headers']['status-code']);

        $found = $this->call(Client::METHOD_GET, '/sandbox/' . $sandbox['$id']);
        $this->assertEquals(404, $found['headers']['status-code']);
        $this->assertEquals('sandbox_not_found', $found['body']['type']);
    }

    public function testGetMissing(): void
    {
        $found = $this->call(Client::METHOD_GET, '/sandbox/missing');

        $this->assertEquals(404, $found['headers']['status-code']);
        $this->assertEquals('sandbox_not_found', $found['body']['type']);
    }
}
