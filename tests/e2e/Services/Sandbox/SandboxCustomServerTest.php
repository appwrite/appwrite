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

    /**
     * Runs a command through the sandbox contract, which is served inside the
     * sandbox rather than by this API.
     *
     * @return array<string, mixed>
     */
    private function execute(string $url, string $command): array
    {
        $ch = curl_init($url . '/execute');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['command' => $command, 'timeoutSeconds' => 30]),
            CURLOPT_TIMEOUT => 60,
            // The sandbox host resolves through the edge, which this container
            // does not use; dial the orchestrator and let the Host header route.
            CURLOPT_CONNECT_TO => [parse_url($url, PHP_URL_HOST) . ':80:orchestrator:80'],
        ]);
        $body = curl_exec($ch);

        return json_decode((string)$body, true) ?? ['exitCode' => -1, 'stdout' => '', 'stderr' => (string)$body];
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

    public function testCreateSpecification(): void
    {
        $sandbox = $this->call(Client::METHOD_POST, '/sandbox', [
            'sandboxId' => 'sized',
            'image' => 'python:3.12-slim',
            'specification' => 's-1vcpu-1gb',
        ]);

        $this->assertEquals(201, $sandbox['headers']['status-code']);
        $this->assertEquals('ready', $sandbox['body']['status']);

        $this->assertEquals(204, $this->call(Client::METHOD_DELETE, '/sandbox/sized')['headers']['status-code']);
    }

    public function testCreateUnknownSpecification(): void
    {
        $sandbox = $this->call(Client::METHOD_POST, '/sandbox', [
            'sandboxId' => 'huge',
            'image' => 'python:3.12-slim',
            'specification' => 's-64vcpu-256gb',
        ]);

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

    public function testPersistentStorageOutlivesTheSandbox(): void
    {
        $first = $this->call(Client::METHOD_POST, '/sandbox', [
            'sandboxId' => 'persist',
            'image' => 'python:3.12-slim',
        ]);
        $this->assertEquals(201, $first['headers']['status-code']);

        $this->assertEquals(0, $this->execute($first['body']['url'], 'echo kept > /workspace/persistent/note.txt')['exitCode']);
        $this->assertEquals(204, $this->call(Client::METHOD_DELETE, '/sandbox/persist')['headers']['status-code']);

        $second = $this->call(Client::METHOD_POST, '/sandbox', [
            'sandboxId' => 'persist',
            'image' => 'python:3.12-slim',
        ]);
        $this->assertEquals(201, $second['headers']['status-code']);
        $this->assertNotEquals($first['body']['url'], $second['body']['url']);

        $read = $this->execute($second['body']['url'], 'cat /workspace/persistent/note.txt');
        $this->assertEquals(0, $read['exitCode'], $read['stderr']);
        $this->assertSame('kept', trim($read['stdout']));

        // The rest of the workspace is scratch, so it must not have survived.
        $this->assertNotEquals(0, $this->execute($second['body']['url'], 'test -f /workspace/gone.txt')['exitCode']);

        // Storage follows the ID, so another sandbox gets its own empty one.
        $other = $this->call(Client::METHOD_POST, '/sandbox', [
            'sandboxId' => 'persist-other',
            'image' => 'python:3.12-slim',
        ]);
        $this->assertEquals(201, $other['headers']['status-code']);
        $this->assertNotEquals(0, $this->execute($other['body']['url'], 'test -f /workspace/persistent/note.txt')['exitCode']);

        $this->call(Client::METHOD_DELETE, '/sandbox/persist-other');
        $this->call(Client::METHOD_DELETE, '/sandbox/persist');
    }
}
