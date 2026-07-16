<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCS;

use Appwrite\Tests\Async;
use Appwrite\Tests\Async\Exceptions\Critical;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\System\System;

final class VCSGiteaConsoleClientTest extends Scope
{
    use Async;
    use VCSBase;
    use ProjectCustom;
    use SideConsole;

    protected function getVcsProvider(): string
    {
        return 'gitea';
    }

    public function testFunctionRedeploysFromGiteaWebhook(): void
    {
        $token = $this->createGiteaToken();
        $user = $this->gitea(Client::METHOD_GET, '/api/v1/user', token: $token)['body'];
        $this->assertNotEmpty($user['id'] ?? '');
        $repository = $this->createRepository($token);

        $this->writeFunction($token, $repository['name'], 'gitea-v1', 'Initial function');

        $installation = $this->client->call(Client::METHOD_GET, '/mock/gitea/callback', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => $this->getProject()['$id'],
            'giteaUserId' => (string) $user['id'],
            'organization' => $this->adminUser(),
            'accessToken' => $token,
        ]);
        $this->assertEquals(200, $installation['headers']['status-code']);
        $installationId = $installation['body']['installationId'];

        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Gitea VCS',
            'execute' => [Role::any()->toString()],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'commands' => '',
            'timeout' => 15,
            'installationId' => $installationId,
            'providerRepositoryId' => (string) $repository['id'],
            'providerBranch' => 'main',
        ]);
        $this->assertEquals(201, $function['headers']['status-code'], \json_encode($function['body'], JSON_PRETTY_PRINT));
        $functionId = $function['body']['$id'];

        $this->assertRepositoryWebhookCreated($token, $repository['name']);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments/vcs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'branch',
            'reference' => 'main',
            'activate' => true,
        ]);
        $this->assertEquals(202, $deployment['headers']['status-code'], \json_encode($deployment['body'], JSON_PRETTY_PRINT));
        $initialDeployment = $this->waitForDeployment($functionId, $deployment['body']['$id']);

        $this->assertExecutionOutput($functionId, 'gitea-v1');

        $this->writeFunction($token, $repository['name'], 'gitea-v2', 'Update function');

        $webhookDeployment = $this->waitForNextDeployment($functionId, $initialDeployment['$id']);
        $this->assertNotSame($initialDeployment['$id'], $webhookDeployment['$id']);

        $this->assertExecutionOutput($functionId, 'gitea-v2');
    }

    private function createGiteaToken(): string
    {
        $response = $this->gitea(Client::METHOD_POST, '/api/v1/users/' . $this->adminUser() . '/tokens', [
            'name' => 'appwrite-e2e-' . ID::unique(),
            'scopes' => [
                'read:user',
                'write:user',
                'read:repository',
                'write:repository',
                'read:organization',
            ],
        ], basic: true);

        $this->assertEquals(201, $response['status'], \json_encode($response['body'], JSON_PRETTY_PRINT));
        $this->assertNotEmpty($response['body']['sha1'] ?? $response['body']['token'] ?? '');

        return $response['body']['sha1'] ?? $response['body']['token'] ?? '';
    }

    private function createRepository(string $token): array
    {
        $response = $this->gitea(Client::METHOD_POST, '/api/v1/user/repos', [
            'name' => 'function-' . ID::unique(),
            'private' => false,
            'auto_init' => true,
            'default_branch' => 'main',
        ], token: $token);

        $this->assertEquals(201, $response['status'], \json_encode($response['body'], JSON_PRETTY_PRINT));
        $this->assertNotEmpty($response['body']['id']);

        return $response['body'];
    }

    private function writeFunction(string $token, string $repository, string $output, string $message): void
    {
        $path = '/api/v1/repos/' . $this->adminUser() . '/' . $repository . '/contents/index.js';
        $existing = $this->gitea(Client::METHOD_GET, $path . '?ref=main', token: $token);

        $source = "module.exports = async (context) => context.res.send('{$output}');\n";
        $body = [
            'content' => \base64_encode($source),
            'message' => $message,
            'branch' => 'main',
        ];

        $method = Client::METHOD_POST;
        if ($existing['status'] === 200 && !empty($existing['body']['sha'] ?? '')) {
            $method = Client::METHOD_PUT;
            $body['sha'] = $existing['body']['sha'] ?? '';
        }

        $response = $this->gitea($method, $path, $body, token: $token);
        $this->assertContains($response['status'], [200, 201], \json_encode($response['body'], JSON_PRETTY_PRINT));

        $written = $this->gitea(Client::METHOD_GET, $path . '?ref=main', token: $token);
        $this->assertEquals(200, $written['status'], \json_encode($written['body'], JSON_PRETTY_PRINT));
        $content = \preg_replace('/\s+/', '', $written['body']['content'] ?? '') ?? '';
        $this->assertSame($source, \base64_decode($content, true), \json_encode($written['body'], JSON_PRETTY_PRINT));
    }

    private function assertRepositoryWebhookCreated(string $token, string $repository): void
    {
        $response = $this->gitea(Client::METHOD_GET, '/api/v1/repos/' . $this->adminUser() . '/' . $repository . '/hooks', token: $token);

        $this->assertEquals(200, $response['status'], \json_encode($response['body'], JSON_PRETTY_PRINT));
        $this->assertNotEmpty($response['body']);

        $matching = \array_filter($response['body'], function ($hook) {
            $url = $hook['config']['url'] ?? '';
            $events = $hook['events'] ?? [];

            return \str_contains($url, '/vcs/gitea/events')
                && \in_array('push', $events, true)
                && \in_array('pull_request', $events, true);
        });

        $this->assertNotEmpty($matching, 'No hook registered with the expected Appwrite webhook URL and events: ' . \json_encode($response['body'], JSON_PRETTY_PRINT));
    }

    private function waitForDeployment(string $functionId, string $deploymentId): array
    {
        $deployment = [];

        $this->assertEventually(function () use ($functionId, $deploymentId, &$deployment) {
            $response = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $response['headers']['status-code'], \json_encode($response['body'], JSON_PRETTY_PRINT));

            if (($response['body']['status'] ?? '') === 'failed') {
                throw new Critical('Deployment build failed: ' . ($response['body']['buildLogs'] ?? 'no logs'));
            }

            $this->assertEquals('ready', $response['body']['status'] ?? '', \json_encode($response['body'], JSON_PRETTY_PRINT));
            $deployment = $response['body'];
        }, 180000, 1000);

        return $deployment;
    }

    private function waitForNextDeployment(string $functionId, string $previousDeploymentId): array
    {
        $deployment = [];

        $this->assertEventually(function () use ($functionId, $previousDeploymentId, &$deployment) {
            $response = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $response['headers']['status-code'], \json_encode($response['body'], JSON_PRETTY_PRINT));

            foreach ($response['body']['deployments'] ?? [] as $candidate) {
                if ($candidate['$id'] === $previousDeploymentId) {
                    continue;
                }

                if (($candidate['status'] ?? '') === 'failed') {
                    throw new Critical('Webhook deployment build failed: ' . ($candidate['buildLogs'] ?? 'no logs'));
                }

                if (($candidate['status'] ?? '') === 'ready') {
                    $deployment = $candidate;
                    return;
                }
            }

            $this->fail('Webhook deployment is not ready yet');
        }, 180000, 1000);

        return $deployment;
    }

    private function assertExecutionOutput(string $functionId, string $output): void
    {
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => false,
            'method' => 'GET',
            'path' => '/',
        ]);

        $this->assertEquals(201, $execution['headers']['status-code'], \json_encode($execution['body'], JSON_PRETTY_PRINT));
        $this->assertEquals('completed', $execution['body']['status'] ?? '', \json_encode($execution['body'], JSON_PRETTY_PRINT));
        $this->assertEquals($output, $execution['body']['responseBody'] ?? '');
    }

    private function gitea(string $method, string $path, array $body = [], ?string $token = null, bool $basic = false): array
    {
        $endpoint = \rtrim(System::getEnv('_APP_VCS_GITEA_ENDPOINT', 'http://gitea:3000'), '/');
        $ch = \curl_init($endpoint . $path);
        $headers = ['Content-Type: application/json'];

        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($token !== null) {
            $headers[] = 'Authorization: token ' . $token;
        }

        if ($basic) {
            $adminPassword = System::getEnv('_TESTS_GITEA_ADMIN_PASSWORD', 'password');
            \curl_setopt($ch, CURLOPT_USERPWD, $this->adminUser() . ':' . $adminPassword);
        }

        if (!empty($body)) {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($body));
        }

        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = \curl_exec($ch);
        $status = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = \curl_error($ch);

        $decoded = \json_decode($response ?: '', true);

        return [
            'status' => $status,
            'body' => \is_array($decoded) ? $decoded : ['error' => $error, 'raw' => $response],
        ];
    }

    private function adminUser(): string
    {
        return System::getEnv('_TESTS_GITEA_ADMIN_USER', 'appwrite');
    }
}
